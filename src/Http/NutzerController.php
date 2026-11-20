<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Http;

use Doctrine\DBAL\Connection;
use Kumero\Api\V1\Problem;
use Kumero\Plattform\Anfrage;
use Kumero\Plattform\Webauthn\Base64Url;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nutzer und Rollen eines Mandanten (VERWALTUNG.md Abschnitt 6, 8, 9 und 15; F125) – die Seite des Administrators.
 *
 * Nutzer gehören zum Mandanten (F114): Die Identität liegt in seiner Datenbank; die Verwaltungsdatenbank bekommt nur
 * das Pseudonym. Es gibt keine gemeinsame Transaktion – erst die Zuordnung, dann die Identität; scheitert das Zweite,
 * wird die Zuordnung wieder entfernt.
 *
 * Link und Code eines Zugangs zeigt kumero genau einmal: in der Antwort auf das Ausstellen. Gespeichert sind nur
 * Hashes; Protokoll und Logs nennen sie nie.
 */
final class NutzerController
{
    /** Zeichen des Codes: ohne 0/O, 1/I/L (VERWALTUNG.md Abschnitt 6). */
    public const CODE_ZEICHEN = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const ZEIT = "to_char(%s AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"')";

    /** Hash des Codes, gebunden an den Link: Derselbe Code an einem anderen Link ergibt einen anderen Hash. */
    public static function codeHash(string $token, string $code): string
    {
        return hash('sha256', hash('sha256', $token) . ':' . self::codeNormal($code));
    }

    /** Groß-/Kleinschreibung, Leerzeichen und Bindestriche sind beim Eintippen egal. */
    public static function codeNormal(string $code): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/', '', $code));
    }

    public function liste(Anfrage $a): Response
    {
        $db = $a->db();
        $z = fn (string $spalte) => sprintf(self::ZEIT, $spalte);
        $passkeys = [];
        foreach ($db->fetchAllAssociative(
            "SELECT nutzer, credential_id, bezeichnung, {$z('registriert_am')} AS registriert_am
               FROM kern.anmelde_credential WHERE widerrufen_am IS NULL ORDER BY registriert_am") as $p) {
            $passkeys[$p['nutzer']][] = ['id' => $p['credential_id'], 'bezeichnung' => $p['bezeichnung'], 'registriert_am' => $p['registriert_am']];
        }
        $zuweisungen = [];
        foreach ($db->fetchAllAssociative(
            "SELECT z.id, z.subjekt, r.name AS rolle, r.art, {$z('z.gueltig_von')} AS gueltig_von, {$z('z.gueltig_bis')} AS gueltig_bis
               FROM kern.zuweisung z JOIN kern.rolle r ON r.id = z.rolle
              WHERE z.subjekt LIKE 'nutzer:%' AND (z.gueltig_bis IS NULL OR z.gueltig_bis > clock_timestamp())
              ORDER BY r.name, z.gueltig_von") as $w) {
            $zuweisungen[substr($w['subjekt'], 7)][] = ['id' => $w['id'], 'rolle' => $w['rolle'], 'art' => $w['art'],
                'gueltig_von' => $w['gueltig_von'], 'gueltig_bis' => $w['gueltig_bis']];
        }
        $zugang = $db->fetchAllKeyValue(
            "SELECT nutzer, {$z('max(ablauf)')} FROM kern.registrierung
              WHERE verwendet_am IS NULL AND ungueltig_am IS NULL AND ablauf > now() GROUP BY nutzer");
        $nutzer = array_map(fn (array $n) => [
            'id' => $n['id'], 'vornamen' => $n['vornamen'], 'familienname' => $n['familienname'], 'email' => $n['email'],
            'aktiv' => (bool) $n['aktiv'], 'angelegt_am' => $n['angelegt_am'], 'letzte_anmeldung' => $n['letzte_anmeldung'],
            'passkeys' => $passkeys[$n['id']] ?? [], 'zuweisungen' => $zuweisungen[$n['id']] ?? [],
            'zugang_bis' => $zugang[$n['id']] ?? null,
        ], $db->fetchAllAssociative(
            "SELECT id, vornamen, familienname, email, aktiv, {$z('angelegt_am')} AS angelegt_am, {$z('letzte_anmeldung')} AS letzte_anmeldung
               FROM kern.nutzer ORDER BY lower(familienname), lower(coalesce(vornamen, '')), id"));
        $rollen = $db->fetchAllAssociative('SELECT name, art, beschreibung FROM kern.rolle ORDER BY name');
        $ich = $a->identitaet?->nutzerId();
        return Antwort::json(['nutzer' => $nutzer, 'rollen' => $rollen, 'ich' => $ich]);
    }

    /** Nutzer anlegen, auf Wunsch gleich mit Rollen. Einen Zugang stellt `zugang` aus. */
    public function anlegen(Anfrage $a): Response
    {
        $e = new Eingabe($j = $a->json());
        $e->nurFelder(['vornamen', 'familienname', 'email', 'rollen']);
        $vornamen = $e->text('vornamen', true, 200);
        $familienname = $e->text('familienname', true, 200);
        $email = self::email($e, $j);
        $rollen = self::rollen($e, $j, $a->db());
        $e->pruefen();

        $ktx = $a->kontext();
        $pseudonym = Base64Url::kodieren(random_bytes(32));
        // erst die Zuordnung in der Verwaltung (eigene, sofort gültige Anweisung), dann die Identität im Mandanten
        $v = $a->verwaltung();
        $v->executeStatement('SELECT verwaltung.pseudonym_eintragen(?, ?)', [$pseudonym, $ktx->mandant]);
        try {
            $id = (string) $a->db()->fetchOne('SELECT kern.nutzer_anlegen(?, ?, ?, ?, NULL, ?)',
                [$ktx->subjekt, $familienname, $vornamen, $email, $pseudonym]);
            foreach ($rollen as $rolle) {
                $a->db()->executeStatement('SELECT kern.zuweisen(?, ?, ?)', [$ktx->subjekt, "nutzer:$id", $rolle]);
            }
        } catch (\Throwable $f) {
            try {
                $v->executeStatement('SELECT verwaltung.pseudonym_entfernen(?, ?)', [$pseudonym, $ktx->mandant]);
            } catch (\Throwable) {
                // Eine verwaiste Zuordnung führt zu keiner Identität und schadet nicht
                error_log(sprintf('kumero[%s] verwaiste Pseudonym-Zuordnung im Mandanten %s', $a->anfrageId, $ktx->mandant));
            }
            throw $f;
        }
        return Antwort::json(['id' => $id], 201, ['Location' => "/m/{$ktx->mandant}/api/v1/nutzer/$id"]);
    }

    /** Angaben ändern, sperren, entsperren. Felder, die fehlen, bleiben. */
    public function aendern(Anfrage $a): Response
    {
        $e = new Eingabe($j = $a->json());
        $e->nurFelder(['vornamen', 'familienname', 'email', 'aktiv']);
        $n = self::nutzer($a);
        $vornamen = array_key_exists('vornamen', $j) ? $e->text('vornamen', true, 200) : $n['vornamen'];
        $familienname = array_key_exists('familienname', $j) ? $e->text('familienname', true, 200) : $n['familienname'];
        $email = array_key_exists('email', $j) ? self::email($e, $j) : $n['email'];
        $aktiv = array_key_exists('aktiv', $j) ? $e->wahr('aktiv') : (bool) $n['aktiv'];
        $e->pruefen();
        $a->db()->executeStatement('SELECT kern.nutzer_aendern(?, ?::uuid, ?, ?, ?, ?::boolean)',
            [$a->kontext()->subjekt, $n['id'], $familienname, $vornamen, $email, $aktiv ? 'true' : 'false']);
        return Antwort::leer();
    }

    /**
     * Zugang ausstellen: Link **und** Code, beide einmal in der Antwort. Der vorige Zugang derselben Identität wird
     * ungültig. Auf Wunsch werden die vorhandenen Passkeys widerrufen (verlorener Passkey).
     */
    public function zugang(Anfrage $a): Response
    {
        $e = new Eingabe($j = $a->json(false));
        $e->nurFelder(['stunden', 'passkeys_widerrufen']);
        $stunden = $e->ganzzahl('stunden', false) ?? 24;
        $widerrufen = $e->wahr('passkeys_widerrufen', false) ?? false;
        if ($stunden < 1 || $stunden > 24) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'stunden', 'meldung' => 'Ein Zugang gilt 1 bis 24 Stunden']]);
        }
        $e->pruefen();
        $n = self::nutzer($a);
        $ktx = $a->kontext();
        $db = $a->db();
        $n_widerrufen = 0;
        if ($widerrufen) {
            foreach ($db->fetchFirstColumn('SELECT credential_id FROM kern.anmelde_credential WHERE nutzer = ? AND widerrufen_am IS NULL', [$n['id']]) as $c) {
                $db->executeStatement('SELECT kern.anmelde_credential_widerrufen(?, ?)', [$ktx->subjekt, $c]);
                $n_widerrufen++;
            }
        }
        $token = $ktx->mandant . '.' . Base64Url::kodieren(random_bytes(32));
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= self::CODE_ZEICHEN[random_int(0, strlen(self::CODE_ZEICHEN) - 1)];
        }
        $db->executeStatement('SELECT kern.registrierung_ausstellen(?, ?::uuid, ?, make_interval(hours => ?), ?)',
            [$ktx->subjekt, $n['id'], hash('sha256', $token), $stunden, self::codeHash($token, $code)]);
        $bis = (string) $db->fetchOne(sprintf('SELECT ' . self::ZEIT . ' FROM kern.registrierung WHERE token_hash = ?', 'ablauf'), [hash('sha256', $token)]);
        // Das Token steht im Fragment: Es landet nicht in Server-Logs. Die Antwort wird nicht zwischengespeichert.
        return Antwort::json(['link' => $a->konfig->adresse . '/app.html#registrierung=' . $token, 'code' => $code,
            'gueltig_bis' => $bis, 'passkeys_widerrufen' => $n_widerrufen], 201, ['Cache-Control' => 'no-store']);
    }

    public function passkeyWiderrufen(Anfrage $a): Response
    {
        $n = self::nutzer($a);
        $c = $a->param('credential');
        if (!$a->db()->fetchOne('SELECT 1 FROM kern.anmelde_credential WHERE credential_id = ? AND nutzer = ?', [$c, $n['id']])) {
            throw Problem::nichtGefunden('Passkey unbekannt');
        }
        $a->db()->executeStatement('SELECT kern.anmelde_credential_widerrufen(?, ?)', [$a->kontext()->subjekt, $c]);
        return Antwort::leer();
    }

    /** Rolle zuweisen, auf Wunsch befristet. */
    public function zuweisen(Anfrage $a): Response
    {
        $e = new Eingabe($j = $a->json());
        $e->nurFelder(['nutzer', 'rolle', 'bis']);
        $nutzer = $e->text('nutzer', true, 36);
        $rolle = $e->text('rolle', true, 100);
        $bis = $e->datum('bis', false);
        $e->pruefen();
        $db = $a->db();
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $nutzer)
            || !$db->fetchOne('SELECT 1 FROM kern.nutzer WHERE id = ?', [$nutzer])) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'nutzer', 'meldung' => 'Nutzer unbekannt']]);
        }
        if (!$db->fetchOne('SELECT 1 FROM kern.rolle WHERE name = ?', [$rolle])) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'rolle', 'meldung' => 'Rolle unbekannt']]);
        }
        if ($db->fetchOne("SELECT 1 FROM kern.zuweisung z JOIN kern.rolle r ON r.id = z.rolle
                            WHERE z.subjekt = ? AND r.name = ? AND (z.gueltig_bis IS NULL OR z.gueltig_bis > clock_timestamp())", ["nutzer:$nutzer", $rolle])) {
            throw new Problem(409, 'zuweisung.vorhanden', 'Der Nutzer hat diese Rolle schon');
        }
        // „bis“ ist ein Tag: Die Rolle gilt bis zu seinem Ende (Zeit des Servers)
        if ($bis !== null && $bis < date('Y-m-d')) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'bis', 'meldung' => 'Das Ende liegt in der Vergangenheit']]);
        }
        $id = (string) $db->fetchOne("SELECT kern.zuweisen(?, ?, ?, (?::date + 1)::timestamp AT TIME ZONE current_setting('TimeZone'))",
            [$a->kontext()->subjekt, "nutzer:$nutzer", $rolle, $bis]);
        return Antwort::json(['id' => $id], 201);
    }

    public function zuweisungBeenden(Anfrage $a): Response
    {
        $id = $a->param('id');
        if (!$a->db()->fetchOne("SELECT 1 FROM kern.zuweisung WHERE id = ? AND subjekt LIKE 'nutzer:%'", [$id])) {
            throw Problem::nichtGefunden('Zuweisung unbekannt');
        }
        $a->db()->executeStatement('SELECT kern.zuweisung_beenden(?, ?::uuid)', [$a->kontext()->subjekt, $id]);
        return Antwort::leer();
    }

    // --- Hilfen -----------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function nutzer(Anfrage $a): array
    {
        return $a->db()->fetchAssociative('SELECT id, vornamen, familienname, email, aktiv FROM kern.nutzer WHERE id = ?', [$a->param('id')])
            ?: throw Problem::nichtGefunden('Nutzer unbekannt');
    }

    private static function email(Eingabe $e, array $j): ?string
    {
        $w = $e->text('email', true, 254);
        if ($w !== null && !preg_match('/^[^@\s]+@[^@\s]+$/', trim($w))) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'email', 'meldung' => 'E-Mail-Adresse erwartet']]);
        }
        return $w === null ? null : trim($w);
    }

    /** @return list<string> */
    private static function rollen(Eingabe $e, array $j, Connection $db): array
    {
        $r = $j['rollen'] ?? [];
        if (!is_array($r) || !array_is_list($r) || count($r) > 20 || array_filter($r, fn ($x) => !is_string($x)) !== []) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'rollen', 'meldung' => 'Liste von Rollennamen erwartet']]);
        }
        $r = array_values(array_unique($r));
        foreach ($r as $name) {
            if (!$db->fetchOne('SELECT 1 FROM kern.rolle WHERE name = ?', [$name])) {
                throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'rollen', 'meldung' => "Rolle $name unbekannt"]]);
            }
        }
        return $r;
    }
}
