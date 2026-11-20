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

    /** Datenbank eines Buchs: `kumero_<mandant>__<buch>` (BUECHER.md A5). */
    public static function buchDatenbankName(string $mandant, string $buch): string
    {
        return self::datenbankName($mandant) . '__' . str_replace('-', '_', $buch);
    }

    /** Spielt die Migrationen einer Art in eine Datenbank ein – wie `db/migrieren.sh` von Hand, mit der Freigabe des installierten Stands. */
    private function migrieren(string $datenbank, string $art): void
    {
        $wurzel = dirname(__DIR__, 2);
        $stand = $this->db->verwaltung()->fetchAssociative('SELECT release_sha256, freigabe_schluessel FROM meta.version ORDER BY installiert_am DESC LIMIT 1') ?: null;
        $env = ['KUMERO_FREIGABE' => trim((string) file_get_contents("$wurzel/VERSION")),
                'KUMERO_RELEASE_SHA256' => $stand['release_sha256'] ?? str_repeat('0', 64),
                'KUMERO_FREIGABE_SCHLUESSEL' => $stand['freigabe_schluessel'] ?? 'unbekannt', 'KUMERO_DB_USER' => $this->konfig->dbNutzer];
        $befehl = array_merge(['bash', "$wurzel/db/migrieren.sh"], $art === '' ? [] : [$art], [$datenbank, '-h', $this->konfig->dbHost, '-p', (string) $this->konfig->dbPort]);
        $p = proc_open($befehl, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre, null, $env + getenv());
        stream_get_contents($rohre[1]);
        $fehler = stream_get_contents($rohre[2]);
        if (proc_close($p) !== 0) {
            error_log('kumero migrieren fehlgeschlagen: ' . substr((string) $fehler, 0, 500));
            throw new \RuntimeException('datenbank.migration');
        }
    }

    private function datenbankFreigeben(Connection $m, string $datenbank): void
    {
        $m->executeStatement(sprintf('REVOKE ALL ON DATABASE %s FROM PUBLIC', $datenbank));
        $m->executeStatement(sprintf('GRANT CONNECT ON DATABASE %s TO kumero_app, kumero_lesen', $datenbank));
    }

    /**
     * Mandant anlegen und ersten Administrator einsetzen – ein Vorgang (U5): Mandanten-Datenbank mit dem Schema
     * `mandant`, Identität und Administrator in einer Transaktion des Mandanten, danach Mandant und Pseudonym in einer
     * der Verwaltung. **Ein Buch entsteht dabei nicht** (BUECHER.md A12) – das erste legt der Administrator an.
     * Den Zugang (Link) hat der Aufrufer erzeugt; hier kommt nur sein Hash an.
     *
     * @return string ID des Administrators
     */
    public function anlegen(string $subjekt, string $kennung, string $name, string $vornamen, string $familienname,
                            string $email, string $tokenHash, string $gueltig = '15 minutes'): string
    {
        if (!preg_match(self::KENNUNG, $kennung) || in_array($kennung, ['verwaltung', 'vorlage'], true)) {
            throw new \InvalidArgumentException('Kennung: Kleinbuchstaben, Ziffern, Bindestrich; nicht „verwaltung“ oder „vorlage“');
        }
        $datenbank = self::datenbankName($kennung);
        $v = $this->db->verwaltung();
        if ($v->fetchOne('SELECT 1 FROM verwaltung.mandant WHERE kennung = ? OR datenbank = ?', [$kennung, $datenbank])
            || $v->fetchOne('SELECT 1 FROM verwaltung.buch WHERE datenbank = ?', [$datenbank])) {
            throw new \RuntimeException("Mandant $kennung gibt es schon");
        }
        $v->executeStatement(sprintf('CREATE DATABASE %s OWNER kumero_owner TEMPLATE template0 ENCODING %s', $datenbank, "'UTF8'"));
        try {
            $this->migrieren($datenbank, '--mandant');
            $m = $this->db->verbindung($datenbank);
            $m->executeStatement('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
            $this->datenbankFreigeben($m, $datenbank);
            $pseudonym = Webauthn\Base64Url::kodieren(random_bytes(32));
            $id = $m->transactional(function (Connection $m) use ($subjekt, $vornamen, $familienname, $email, $tokenHash, $gueltig, $pseudonym): string {
                $id = (string) $m->fetchOne('SELECT mandant.nutzer_anlegen(?, ?, ?, ?, ?, ?)', [$subjekt, $familienname, $vornamen, $email, null, $pseudonym]);
                $m->executeStatement('SELECT mandant.administrator_einsetzen(?, ?::uuid)', [$subjekt, $id]);
                $m->executeStatement('SELECT mandant.registrierung_ausstellen(?, ?::uuid, ?, ?::interval)', [$subjekt, $id, $tokenHash, $gueltig]);
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
     * Buch anlegen (BUECHER.md A12): Datenbank aus der Vorlage, Buch einrichten, Rollenvorlagen; danach der Eintrag in
     * der Verwaltung und in der Bücherliste des Mandanten. `$subjekt` ist, wer das Buch wollte – es steht im Protokoll
     * des Mandanten und des Buchs, nicht in der Verwaltung (F114).
     */
    public function buchAnlegen(string $subjekt, string $mandant, string $kennung, string $name, string $art, string $waehrung = 'EUR', bool $testbuch = false): void
    {
        if (!preg_match('/^[a-z][a-z0-9-]{1,19}$/', $kennung) || !in_array($art, ['euer', 'bilanz'], true)) {
            throw new \InvalidArgumentException('Buch: Kennung mit 2 bis 20 Zeichen (Kleinbuchstaben, Ziffern, Bindestrich), Art euer oder bilanz');
        }
        $v = $this->db->verwaltung();
        $mdbName = $this->datenbank($mandant);
        $datenbank = self::buchDatenbankName($mandant, $kennung);
        if (strlen($datenbank) > 63 || $v->fetchOne('SELECT 1 FROM verwaltung.buch WHERE (mandant = ? AND kennung = ?) OR datenbank = ?', [$mandant, $kennung, $datenbank])
            || $v->fetchOne('SELECT 1 FROM pg_database WHERE datname = ?', [$datenbank])) {
            throw new \RuntimeException('buch.vorhanden');
        }
        $vorlage = getenv('KUMERO_VORLAGE_DB') ?: 'kumero_vorlage';
        $v->executeStatement(sprintf('CREATE DATABASE %s TEMPLATE %s OWNER kumero_owner', $datenbank, $vorlage));
        try {
            $b = $this->db->verbindung($datenbank);
            $this->datenbankFreigeben($b, $datenbank);
            $b->transactional(function (Connection $b) use ($subjekt, $kennung, $name, $art, $waehrung, $testbuch): void {
                $b->executeStatement('SELECT kern.buch_anlegen(?, ?, ?)', [$subjekt, $name, $waehrung]);
                $b->executeStatement('SELECT kern.buch_einrichten(?, ?, ?, ?::boolean)', [$subjekt, $kennung, $art, $testbuch ? 'true' : 'false']);
                $b->executeStatement('SELECT kern.rollenvorlagen_anlegen(?)', [$subjekt]);
            });
            $this->db->verbindung($mdbName)->executeStatement('SELECT mandant.buch_eintragen(?, ?, ?, ?, ?::boolean)',
                [$subjekt, $kennung, $name, $art, $testbuch ? 'true' : 'false']);
            $v->executeStatement('SELECT verwaltung.buch_eintragen(?, ?, ?, ?)', [self::SUBJEKT, $mandant, $kennung, $datenbank]);
        } catch (\Throwable $f) {
            $this->db->schliessen();
            try {
                $this->db->verbindung($mdbName)->executeStatement('DELETE FROM mandant.buch WHERE kennung = ?', [$kennung]);
            } catch (\Throwable) {
                // die Bücherliste nennt dann ein Buch ohne Datenbank; die Anwendung zeigt es nicht
            }
            $this->db->schliessen();
            $this->db->verwaltung()->executeStatement("DROP DATABASE IF EXISTS $datenbank");
            throw $f;
        }
    }

    /** @return array<string, string> Kennung → Datenbank der Bücher eines Mandanten */
    private function buecher(string $mandant): array
    {
        $b = $this->db->verwaltung()->fetchAllKeyValue('SELECT kennung, datenbank FROM verwaltung.buch WHERE mandant = ? ORDER BY kennung', [$mandant]);
        foreach ($b as $datenbank) {
            if (!preg_match('/^kumero_[a-z0-9_]{1,56}$/', (string) $datenbank) || in_array($datenbank, [$this->konfig->verwaltungDb, 'kumero_vorlage'], true)) {
                throw new \RuntimeException('buch.datenbank');
            }
        }
        return $b;
    }

    private function sichern(string $datenbank, string $datei): void
    {
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
    }

    /**
     * Vollständige Sicherung des Mandanten mit pg_dump (eigenes Format, mit pg_restore einzuspielen) in das
     * Export-Verzeichnis: eine Datei für die Mandanten-Datenbank und eine je Buch, mit demselben Zeitstempel. Die
     * Dateien enthalten alle Buchungsdaten und sind nur für den Dienst und root lesbar.
     *
     * @return array{datei: string, groesse: int, sha256: string, dateien: list<array{datei: string, groesse: int, sha256: string}>}
     */
    public function exportieren(string $kennung): array
    {
        $datenbank = $this->datenbank($kennung);
        $verz = self::exportVerzeichnis();
        if (!is_dir($verz) && !@mkdir($verz, 0700, true) && !is_dir($verz)) {
            throw new \RuntimeException('export.verzeichnis');
        }
        @chmod($verz, 0700);
        $zeit = gmdate('Ymd-His');
        $ziele = [sprintf('%s/%s-%s.dump', $verz, $kennung, $zeit) => $datenbank];
        foreach ($this->buecher($kennung) as $buch => $db) {
            $ziele[sprintf('%s/%s-%s__%s.dump', $verz, $kennung, $zeit, $buch)] = $db;
        }
        $dateien = [];
        try {
            foreach ($ziele as $datei => $db) {
                $this->sichern((string) $db, $datei);
                $dateien[] = ['datei' => $datei, 'groesse' => (int) filesize($datei), 'sha256' => (string) hash_file('sha256', $datei)];
            }
        } catch (\Throwable $f) {
            foreach ($dateien as $d) {
                @unlink($d['datei']);
            }
            throw $f;
        }
        // „datei“ nennt die Sicherung der Mandanten-Datenbank; die der Bücher liegen daneben
        return ['datei' => $dateien[0]['datei'], 'groesse' => array_sum(array_column($dateien, 'groesse')), 'sha256' => $dateien[0]['sha256'], 'dateien' => $dateien];
    }

    /**
     * Löscht die Datenbanken des Mandanten – die seiner Bücher und seine eigene – und entfernt ihn aus der Verwaltung.
     * Dass er stillgelegt und danach exportiert ist, prüft die Verwaltungsdatenbank beim Anlegen des Auftrags und noch
     * einmal beim Entfernen. Zurück bleibt in der Verwaltung nur der Protokolleintrag mit dem letzten Kettenkopf je Buch (V11).
     *
     * @return array{buecher: array<string, array{journal_nr: ?int, journal_hash: ?string, protokoll_nr: ?int, protokoll_hash: ?string}>}
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
        $koepfe = [];
        foreach ($this->buecher($kennung) as $buch => $db) {
            $kopf = ['journal_nr' => null, 'journal_hash' => null, 'protokoll_nr' => null, 'protokoll_hash' => null];
            if ($v->fetchOne('SELECT 1 FROM pg_database WHERE datname = ?', [$db])) {
                $m = $this->db->verbindung((string) $db);
                $j = $m->fetchAssociative('SELECT journal_nr, hash FROM kern.buchung WHERE journal_nr IS NOT NULL ORDER BY journal_nr DESC LIMIT 1');
                $p = $m->fetchAssociative('SELECT nr, hash FROM kern.protokoll ORDER BY nr DESC LIMIT 1');
                $kopf = ['journal_nr' => $j ? (int) $j['journal_nr'] : null, 'journal_hash' => $j ? (string) $j['hash'] : null,
                         'protokoll_nr' => $p ? (int) $p['nr'] : null, 'protokoll_hash' => $p ? (string) $p['hash'] : null];
                $m->close();
                // erst die Daten, dann der Eintrag: Scheitert das Zweite, holt ein neuer Auftrag es nach
                $v->executeStatement("DROP DATABASE IF EXISTS $db WITH (FORCE)");
            }
            $koepfe[$buch] = $kopf;
        }
        $v->executeStatement("DROP DATABASE IF EXISTS $datenbank WITH (FORCE)");
        $ergebnis = ['buecher' => $koepfe];
        $v->executeStatement('SELECT verwaltung.mandant_loeschen(?, ?, ?::jsonb)', [$subjekt, $kennung, json_encode($ergebnis)]);
        return $ergebnis;
    }

    /**
     * Überführt den Bestand von 0.7.29 (BUECHER.md Abschnitt 12): Je Mandant wird die vorhandene Datenbank – bisher Buch
     * **und** Nutzer – in `kumero_<mandant>__haupt` umbenannt und ist das erste Buch; daneben entsteht die
     * Mandanten-Datenbank, in die Nutzer, Passkeys, offene Zugänge und Einstellungen mit gleicher ID umziehen. Wer im
     * Buch die Rolle „Administrator“ trägt, wird Administrator des Mandanten. Journal, Ketten und Siegel bleiben
     * unberührt; kein Hash ändert sich. Wiederholbar: Was schon überführt ist, wird übergangen.
     *
     * @param callable(string): void $melden
     * @return int Zahl der Mandanten, die nicht überführt werden konnten
     */
    public function buecherUeberfuehren(callable $melden, int $wartenSek = 30): int
    {
        $fehler = 0;
        $v = $this->db->verwaltung();
        foreach ($v->fetchAllAssociative('SELECT kennung, datenbank, stillgelegt_am IS NOT NULL AS still FROM verwaltung.mandant ORDER BY kennung') as $md) {
            $kennung = (string) $md['kennung'];
            if ($v->fetchOne('SELECT 1 FROM verwaltung.buch WHERE mandant = ?', [$kennung])) {
                continue;   // schon überführt (oder neu angelegt)
            }
            $alt = (string) $md['datenbank'];
            $neu = self::buchDatenbankName($kennung, 'haupt');
            try {
                $v = $this->db->verwaltung();
                $hatAlt = (bool) $v->fetchOne('SELECT 1 FROM pg_database WHERE datname = ?', [$alt]);
                $hatNeu = (bool) $v->fetchOne('SELECT 1 FROM pg_database WHERE datname = ?', [$neu]);
                // Ist die alte Datenbank noch das Buch? (Ein abgebrochener Lauf kann schon umbenannt haben.)
                $altIstBuch = $hatAlt && (bool) $this->db->verbindung($alt)->fetchOne("SELECT to_regclass('kern.buch') IS NOT NULL");
                if ($altIstBuch && !$this->db->verbindung($alt)->fetchOne('SELECT 1 FROM kern.buch')) {
                    $melden("Mandant $kennung: Datenbank ohne Buch – nichts zu überführen, bitte prüfen.");
                    $fehler++;
                    continue;
                }
                $this->db->schliessen();
                $v = $this->db->verwaltung();
                if ($altIstBuch) {
                    if ($hatNeu) {
                        throw new \RuntimeException("Datenbank $neu gibt es schon");
                    }
                    // Umbenennen geht nur ohne andere Verbindungen: neue sperren, auf das Ende der laufenden warten
                    $v->executeStatement("ALTER DATABASE $alt WITH ALLOW_CONNECTIONS false");
                    try {
                        for ($i = 0; (int) $v->fetchOne('SELECT count(*) FROM pg_stat_activity WHERE datname = ?', [$alt]) > 0; $i++) {
                            if ($i >= $wartenSek) {
                                throw new \RuntimeException("Datenbank $alt ist noch in Gebrauch – Anwendung anhalten und wiederholen");
                            }
                            sleep(1);
                        }
                        $v->executeStatement("ALTER DATABASE $alt RENAME TO $neu");
                    } finally {
                        $name = $v->fetchOne('SELECT 1 FROM pg_database WHERE datname = ?', [$neu]) ? $neu : $alt;
                        $v->executeStatement("ALTER DATABASE $name WITH ALLOW_CONNECTIONS true");
                    }
                } elseif (!$hatNeu) {
                    throw new \RuntimeException("weder $alt noch $neu ist das Buch");
                }
                // Mandanten-Datenbank unter dem bisherigen Namen
                if (!$v->fetchOne('SELECT 1 FROM pg_database WHERE datname = ?', [$alt])) {
                    $v->executeStatement(sprintf('CREATE DATABASE %s OWNER kumero_owner TEMPLATE template0 ENCODING %s', $alt, "'UTF8'"));
                }
                $this->migrieren($alt, '--mandant');
                $m = $this->db->verbindung($alt);
                $m->executeStatement('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
                $this->datenbankFreigeben($m, $alt);
                $b = $this->db->verbindung($neu);
                $buch = $b->fetchAssociative('SELECT name, kennung, betriebsart::text AS betriebsart FROM kern.buch');
                $test = $kennung === 'spiel';   // B8: der Spielmandant wird Testbuch
                $zahl = 0;
                if ($b->fetchOne("SELECT to_regclass('kern.nutzer') IS NOT NULL")) {
                    $m->transactional(function (Connection $m) use ($b, &$zahl): void {
                        foreach ($b->fetchAllAssociative('SELECT id, vornamen, familienname, email, sprache, aktiv, pseudonym, angelegt_am, letzte_anmeldung FROM kern.nutzer ORDER BY angelegt_am, id') as $n) {
                            if ($m->fetchOne('SELECT 1 FROM mandant.nutzer WHERE id = ?', [$n['id']])) {
                                continue;
                            }
                            $m->executeStatement('SELECT mandant.nutzer_anlegen(?, ?, ?, ?, ?, ?, ?::uuid)',
                                [self::SUBJEKT, $n['familienname'], $n['vornamen'], $n['email'], $n['sprache'], $n['pseudonym'], $n['id']]);
                            // Zeitpunkte und Sperre wie im Bestand – nur beim Überführen, als Eigentümer
                            $m->executeStatement('UPDATE mandant.nutzer SET aktiv = ?::boolean, angelegt_am = ?, letzte_anmeldung = ? WHERE id = ?',
                                [$n['aktiv'] ? 'true' : 'false', $n['angelegt_am'], $n['letzte_anmeldung'], $n['id']]);
                            $zahl++;
                        }
                        foreach ($b->fetchAllAssociative("SELECT credential_id, nutzer, encode(public_key, 'hex') AS pk, alg, zaehler, bezeichnung, registriert_am, widerrufen_am FROM kern.anmelde_credential") as $c) {
                            if ($m->fetchOne('SELECT 1 FROM mandant.anmelde_credential WHERE credential_id = ?', [$c['credential_id']])) {
                                continue;
                            }
                            $m->executeStatement("SELECT mandant.anmelde_credential_uebernehmen(?, ?::uuid, ?, decode(?, 'hex'), ?, ?, ?, ?::timestamptz)",
                                [self::SUBJEKT, $c['nutzer'], $c['credential_id'], $c['pk'], $c['alg'], $c['zaehler'], $c['bezeichnung'], $c['registriert_am']]);
                            if ($c['widerrufen_am'] !== null) {
                                $m->executeStatement('UPDATE mandant.anmelde_credential SET widerrufen_am = ? WHERE credential_id = ?', [$c['widerrufen_am'], $c['credential_id']]);
                            }
                        }
                        $m->executeStatement('DELETE FROM mandant.registrierung');
                        foreach ($b->fetchAllAssociative('SELECT token_hash, nutzer, code_hash, ablauf, fehlversuche FROM kern.registrierung WHERE verwendet_am IS NULL AND ungueltig_am IS NULL AND ablauf > now()') as $r) {
                            $m->executeStatement('INSERT INTO mandant.registrierung (token_hash, nutzer, code_hash, ablauf, fehlversuche) VALUES (?, ?, ?, ?, ?)',
                                [$r['token_hash'], $r['nutzer'], $r['code_hash'], $r['ablauf'], $r['fehlversuche']]);
                        }
                        $m->executeStatement('DELETE FROM mandant.nutzer_einstellung');
                        foreach ($b->fetchAllAssociative('SELECT nutzer, schluessel, wert::text AS wert, geaendert_am FROM kern.nutzer_einstellung') as $e) {
                            $m->executeStatement('INSERT INTO mandant.nutzer_einstellung (nutzer, schluessel, wert, geaendert_am) VALUES (?, ?, ?::jsonb, ?)',
                                [$e['nutzer'], $e['schluessel'], $e['wert'], $e['geaendert_am']]);
                        }
                    });
                }
                // Administratoren: wer im Buch die Rolle der Art „administrator“ trägt, wird Administrator des Mandanten
                $admins = 0;
                foreach ($b->fetchAllAssociative(
                    "SELECT z.id, z.subjekt FROM kern.zuweisung z JOIN kern.rolle r ON r.id = z.rolle
                      WHERE r.art = 'administrator' AND z.subjekt LIKE 'nutzer:%' AND (z.gueltig_bis IS NULL OR z.gueltig_bis > clock_timestamp())") as $z) {
                    $nid = substr((string) $z['subjekt'], 7);
                    if ($m->fetchOne('SELECT 1 FROM mandant.nutzer WHERE id = ? AND aktiv', [$nid]) && !$m->fetchOne('SELECT 1 FROM mandant.administrator WHERE nutzer = ? AND bis IS NULL', [$nid])) {
                        $m->executeStatement('SELECT mandant.administrator_einsetzen(?, ?::uuid)', [self::SUBJEKT, $nid]);
                        $admins++;
                    }
                    $b->executeStatement('SELECT kern.zuweisung_beenden(?, ?::uuid)', [self::SUBJEKT, $z['id']]);
                }
                // Buch einrichten, Nutzertabellen aus dem Buch entfernen
                $b->transactional(function (Connection $b) use ($buch, $test): void {
                    if ($buch['kennung'] === null) {
                        $b->executeStatement('SELECT kern.buch_einrichten(?, ?, ?, ?::boolean)', [self::SUBJEKT, 'haupt', 'euer', $test && $buch['betriebsart'] === 'test' ? 'true' : 'false']);
                    }
                    $b->executeStatement('SELECT kern.nutzer_auslagern()');
                });
                if (!$m->fetchOne("SELECT 1 FROM mandant.buch WHERE kennung = 'haupt'")) {
                    $m->executeStatement('SELECT mandant.buch_eintragen(?, ?, ?, ?, ?::boolean)',
                        [self::SUBJEKT, 'haupt', (string) $buch['name'], 'euer', $test && $buch['betriebsart'] === 'test' ? 'true' : 'false']);
                }
                $this->db->verwaltung()->executeStatement('SELECT verwaltung.buch_eintragen(?, ?, ?, ?)', [self::SUBJEKT, $kennung, 'haupt', $neu]);
                $melden("Mandant $kennung: Buch „haupt“ in $neu" . ($test ? ' (Testbuch)' : '') . ", $zahl Nutzer in die Mandanten-Datenbank $alt, $admins Administrator(en)."
                    . ($admins === 0 ? " ACHTUNG: kein Administrator – einsetzen mit: bin/kumero mandant:administrator $kennung <Nutzer-ID>" : ''));
            } catch (\Throwable $f) {
                $fehler++;
                $melden("Mandant $kennung: NICHT überführt – " . $f->getMessage());
                $this->db->schliessen();
            }
        }
        return $fehler;
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
                        $this->anlegen(self::SUBJEKT, $a['mandant'], (string) $angaben['name'],
                            (string) $angaben['vornamen'], (string) $angaben['familienname'], (string) $angaben['email'],
                            (string) $angaben['token_hash'], '24 hours');
                        break;
                    case 'buch_anlegen':
                        $this->buchAnlegen((string) $angaben['subjekt'], $a['mandant'], (string) $angaben['kennung'], (string) $angaben['name'],
                            (string) $angaben['art'], (string) $angaben['buchwaehrung'], (bool) $angaben['testbuch']);
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
