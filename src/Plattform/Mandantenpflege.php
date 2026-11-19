<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Plattform;

use Doctrine\DBAL\Connection;

/**
 * Was nur die Datenbankrolle kumero_owner darf: einen Mandanten anlegen, sichern und löschen (VERWALTUNG.md
 * Abschnitt 5 und 14, F118). Aufgerufen von der Kommandozeile – unmittelbar (`mandant:anlegen`) oder vom Dienst, der
 * die Aufträge der Seite „Mandanten“ ausführt (`system:auftraege`). Die Webanwendung ruft diese Klasse nie auf.
 *
 * Logs und Ergebnisse nennen nie Namen oder E-Mail-Adressen.
 */
final class Mandantenpflege
{
    public const SUBJEKT = 'system:auftrag';
    public const KENNUNG = '/^[a-z][a-z0-9-]{1,31}$/';

    public function __construct(private readonly Konfiguration $konfig, private readonly Datenbank $db)
    {
    }

    public static function datenbankName(string $kennung): string
    {
        return 'kumero_' . str_replace('-', '_', $kennung);
    }

    /** Verzeichnis der Sicherungen; nur der Dienst und root lesen dort. */
    public static function exportVerzeichnis(): string
    {
        return rtrim(getenv('KUMERO_EXPORT_VERZEICHNIS') ?: '/var/lib/kumero/exporte', '/');
    }

    /**
     * Mandant anlegen und ersten Administrator einsetzen – ein Vorgang (U5): Datenbank aus der Vorlage, Buch,
     * Rollenvorlagen und Identität in einer Transaktion des Mandanten, danach Mandant und Pseudonym in einer der
     * Verwaltung. Den Zugang (Link) hat der Aufrufer erzeugt; hier kommt nur sein Hash an.
     *
     * @return string ID des Administrators
     */
    public function anlegen(string $subjekt, string $kennung, string $name, string $waehrung, string $vornamen, string $familienname,
                            string $email, string $tokenHash, string $gueltig = '15 minutes'): string
    {
        if (!preg_match(self::KENNUNG, $kennung) || in_array($kennung, ['verwaltung', 'vorlage'], true)) {
            throw new \InvalidArgumentException('Kennung: Kleinbuchstaben, Ziffern, Bindestrich; nicht „verwaltung“ oder „vorlage“');
        }
        $datenbank = self::datenbankName($kennung);
        $v = $this->db->verwaltung();
        if ($v->fetchOne('SELECT 1 FROM verwaltung.mandant WHERE kennung = ? OR datenbank = ?', [$kennung, $datenbank])) {
            throw new \RuntimeException("Mandant $kennung gibt es schon");
        }
        $vorlage = getenv('KUMERO_VORLAGE_DB') ?: 'kumero_vorlage';
        $v->executeStatement(sprintf('CREATE DATABASE %s TEMPLATE %s OWNER kumero_owner', $datenbank, $vorlage));
        try {
            $m = $this->db->verbindung($datenbank);
            $m->executeStatement(sprintf('REVOKE ALL ON DATABASE %s FROM PUBLIC', $datenbank));
            $m->executeStatement(sprintf('GRANT CONNECT ON DATABASE %s TO kumero_app, kumero_lesen', $datenbank));
            $pseudonym = Webauthn\Base64Url::kodieren(random_bytes(32));
            $id = $m->transactional(function (Connection $m) use ($subjekt, $name, $waehrung, $vornamen, $familienname, $email, $tokenHash, $gueltig, $pseudonym): string {
                $m->executeStatement('SELECT kern.buch_anlegen(?, ?, ?)', [$subjekt, $name, $waehrung]);
                $m->executeStatement('SELECT kern.rollenvorlagen_anlegen(?)', [$subjekt]);
                $id = (string) $m->fetchOne('SELECT kern.nutzer_anlegen(?, ?, ?, ?, ?, ?)', [$subjekt, $familienname, $vornamen, $email, null, $pseudonym]);
                $m->executeStatement('SELECT kern.zuweisen(?, ?, ?)', [$subjekt, 'nutzer:' . $id, 'Administrator']);
                $m->executeStatement('SELECT kern.registrierung_ausstellen(?, ?::uuid, ?, ?::interval)', [$subjekt, $id, $tokenHash, $gueltig]);
                return $id;
            });
            $v->transactional(function (Connection $v) use ($subjekt, $kennung, $datenbank, $name, $pseudonym): void {
                $v->executeStatement('SELECT verwaltung.mandant_eintragen(?, ?, ?, ?)', [$subjekt, $kennung, $datenbank, $name]);
                $v->executeStatement('SELECT verwaltung.pseudonym_eintragen(?, ?)', [$pseudonym, $kennung]);
                $v->executeStatement('SELECT verwaltung.administrator_vermerken(?, ?)', [$subjekt, $kennung]);
            });
        } catch (\Throwable $f) {
            $this->db->schliessen();
            $this->db->verwaltung()->executeStatement("DROP DATABASE IF EXISTS $datenbank");
            throw $f;
        }
        return $id;
    }

    /**
     * Vollständige Sicherung der Mandanten-Datenbank mit pg_dump (eigenes Format, mit pg_restore einzuspielen) in das
     * Export-Verzeichnis. Die Datei enthält alle Buchungsdaten und ist nur für den Dienst und root lesbar.
     *
     * @return array{datei: string, groesse: int, sha256: string}
     */
    public function exportieren(string $kennung): array
    {
        $datenbank = $this->datenbank($kennung);
        $verz = self::exportVerzeichnis();
        if (!is_dir($verz) && !@mkdir($verz, 0700, true) && !is_dir($verz)) {
            throw new \RuntimeException('export.verzeichnis');
        }
        @chmod($verz, 0700);
        $datei = sprintf('%s/%s-%s.dump', $verz, $kennung, gmdate('Ymd-His'));
        $befehl = ['pg_dump', '--format=custom', '--no-password', '-h', $this->konfig->dbHost, '-p', (string) $this->konfig->dbPort,
                   '-U', $this->konfig->dbNutzer, '-f', $datei, $datenbank];
        $alt = umask(0077);
        try {
            $p = @proc_open($befehl, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre);
            if (!is_resource($p)) {
                throw new \RuntimeException('export.pg_dump_fehlt');
            }
            stream_get_contents($rohre[1]);
            stream_get_contents($rohre[2]);   // Meldungen von pg_dump können Namen von Objekten nennen – nicht ins Log
            $ende = proc_close($p);
        } finally {
            umask($alt);
        }
        if ($ende !== 0 || !is_file($datei) || filesize($datei) === 0) {
            @unlink($datei);
            throw new \RuntimeException($ende === 127 ? 'export.pg_dump_fehlt' : 'export.pg_dump');
        }
        return ['datei' => $datei, 'groesse' => (int) filesize($datei), 'sha256' => (string) hash_file('sha256', $datei)];
    }

    /**
     * Löscht die Datenbank des Mandanten und entfernt ihn aus der Verwaltung. Dass er stillgelegt und danach
     * exportiert ist, prüft die Verwaltungsdatenbank beim Anlegen des Auftrags und noch einmal beim Entfernen.
     * Zurück bleibt in der Verwaltung nur der Protokolleintrag mit dem letzten Kettenkopf (V11).
     *
     * @return array{journal_nr: ?int, journal_hash: ?string, protokoll_nr: ?int, protokoll_hash: ?string}
     */
    public function loeschen(string $subjekt, string $kennung): array
    {
        $v = $this->db->verwaltung();
        $z = $v->fetchAssociative('SELECT datenbank, stillgelegt_am FROM verwaltung.mandant WHERE kennung = ?', [$kennung]);
        if ($z === false || $z['stillgelegt_am'] === null) {
            throw new \RuntimeException('loeschen.nicht_stillgelegt');
        }
        $datenbank = (string) $z['datenbank'];
        if (!preg_match('/^kumero_[a-z0-9_]{1,40}$/', $datenbank) || in_array($datenbank, [$this->konfig->verwaltungDb, 'kumero_vorlage'], true)) {
            throw new \RuntimeException('loeschen.datenbank');
        }
        $kopf = ['journal_nr' => null, 'journal_hash' => null, 'protokoll_nr' => null, 'protokoll_hash' => null];
        if ($v->fetchOne('SELECT 1 FROM pg_database WHERE datname = ?', [$datenbank])) {
            $m = $this->db->verbindung($datenbank);
            $j = $m->fetchAssociative('SELECT journal_nr, hash FROM kern.buchung WHERE journal_nr IS NOT NULL ORDER BY journal_nr DESC LIMIT 1');
            $p = $m->fetchAssociative('SELECT nr, hash FROM kern.protokoll ORDER BY nr DESC LIMIT 1');
            $kopf = ['journal_nr' => $j ? (int) $j['journal_nr'] : null, 'journal_hash' => $j ? (string) $j['hash'] : null,
                     'protokoll_nr' => $p ? (int) $p['nr'] : null, 'protokoll_hash' => $p ? (string) $p['hash'] : null];
            $m->close();
            // erst die Daten, dann der Eintrag: Scheitert das Zweite, holt ein neuer Auftrag es nach
            $v->executeStatement("DROP DATABASE IF EXISTS $datenbank WITH (FORCE)");
        }
        $v->executeStatement('SELECT verwaltung.mandant_loeschen(?, ?, ?::jsonb)', [$subjekt, $kennung, json_encode($kopf)]);
        return $kopf;
    }

    /**
     * Führt die offenen Aufträge aus (höchstens $hoechstens je Lauf). Ein gescheiterter Auftrag hält die übrigen nicht auf.
     *
     * @return list<array{id: string, art: string, mandant: string, erfolg: bool, code: ?string}>
     */
    public function auftraegeAusfuehren(int $hoechstens = 20): array
    {
        $v = $this->db->verwaltung();
        $v->executeStatement('SELECT verwaltung.auftraege_aufraeumen()');
        $erg = [];
        for ($n = 0; $n < $hoechstens; $n++) {
            $a = $v->fetchAssociative('SELECT id, art, mandant, angaben::text AS angaben, angelegt_von FROM verwaltung.auftrag_uebernehmen()');
            if ($a === false) {
                break;
            }
            $angaben = $a['angaben'] === null ? [] : (array) json_decode((string) $a['angaben'], true);
            $ergebnis = null;
            $code = null;
            try {
                switch ($a['art']) {
                    case 'mandant_anlegen':
                        $this->anlegen(self::SUBJEKT, $a['mandant'], (string) $angaben['name'], (string) ($angaben['waehrung'] ?? 'EUR'),
                            (string) $angaben['vornamen'], (string) $angaben['familienname'], (string) $angaben['email'],
                            (string) $angaben['token_hash'], '24 hours');
                        break;
                    case 'mandant_exportieren':
                        $ergebnis = $this->exportieren($a['mandant']);
                        break;
                    case 'mandant_loeschen':
                        $ergebnis = ['kettenkopf' => $this->loeschen((string) $a['angelegt_von'], $a['mandant'])];
                        break;
                    default:
                        throw new \RuntimeException('auftrag.art');
                }
            } catch (\Throwable $f) {
                // Der Code ist ein festes Stichwort; die Meldung der Ausnahme kann Inhalte nennen und bleibt draußen
                $code = preg_match('/^[a-z_]+\.[a-z_]+$/', $f->getMessage()) ? $f->getMessage() : 'auftrag.fehler';
                error_log(sprintf('kumero auftrag %s (%s) fehlgeschlagen: %s %s', $a['id'], $a['art'], $code, $f::class));
            }
            // nach CREATE/DROP DATABASE frisch verbinden
            $this->db->schliessen();
            $v = $this->db->verwaltung();
            $v->executeStatement('SELECT verwaltung.auftrag_abschliessen(?::uuid, ?::boolean, ?::jsonb)',
                [$a['id'], $code === null ? 'true' : 'false', $code !== null ? json_encode(['code' => $code]) : ($ergebnis === null ? null : json_encode($ergebnis))]);
            $erg[] = ['id' => (string) $a['id'], 'art' => (string) $a['art'], 'mandant' => (string) $a['mandant'], 'erfolg' => $code === null, 'code' => $code];
        }
        return $erg;
    }

    private function datenbank(string $kennung): string
    {
        $d = $this->db->verwaltung()->fetchOne('SELECT datenbank FROM verwaltung.mandant WHERE kennung = ?', [$kennung]);
        if (!is_string($d) || !preg_match('/^kumero_[a-z0-9_]{1,40}$/', $d)) {
            throw new \RuntimeException('mandant.unbekannt');
        }
        return $d;
    }
}
