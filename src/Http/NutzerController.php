<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Http;

use Kumero\Api\V1\Problem;
use Kumero\Plattform\Anfrage;
use Kumero\Plattform\Webauthn\Base64Url;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nutzer und Rollen eines Mandanten (VERWALTUNG.md Abschnitt 6, 8, 9 und 15; F125) – die Seite des Administrators.
 *
 * Seit den Büchern (BUECHER.md, F126) auf zwei Ebenen: **Nutzer, Passkeys, Zugänge und Administratoren** gehören zum
 * Mandanten (Schema `mandant` der Mandanten-Datenbank), **Rollen** werden je Buch zugewiesen (Schema `kern` des Buchs).
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
        $db = $a->mandantDb();
        $z = fn (string $spalte) => sprintf(self::ZEIT, $spalte);
        $passkeys = [];
        foreach ($db->fetchAllAssociative(
            "SELECT nutzer, credential_id, bezeichnung, {$z('registriert_am')} AS registriert_am
               FROM mandant.anmelde_credential WHERE widerrufen_am IS NULL ORDER BY registriert_am") as $p) {
            $passkeys[$p['nutzer']][] = ['id' => $p['credential_id'], 'bezeichnung' => $p['bezeichnung'], 'registriert_am' => $p['registriert_am']];
        }
        $zugang = $db->fetchAllKeyValue(
            "SELECT nutzer, {$z('max(ablauf)')} FROM mandant.registrierung
              WHERE verwendet_am IS NULL AND ungueltig_am IS NULL AND ablauf > now() GROUP BY nutzer");
        $admins = $db->fetchFirstColumn('SELECT nutzer FROM mandant.administrator WHERE bis IS NULL');
        $nutzer = array_map(fn (array $n) => [
            'id' => $n['id'], 'vornamen' => $n['vornamen'], 'familienname' => $n['familienname'], 'email' => $n['email'],
            'aktiv' => (bool) $n['aktiv'], 'administrator' => in_array($n['id'], $admins, true),
            'angelegt_am' => $n['angelegt_am'], 'letzte_anmeldung' => $n['letzte_anmeldung'],
            'passkeys' => $passkeys[$n['id']] ?? [], 'zugang_bis' => $zugang[$n['id']] ?? null,
        ], $db->fetchAllAssociative(
            "SELECT id, vornamen, familienname, email, aktiv, {$z('angelegt_am')} AS angelegt_am, {$z('letzte_anmeldung')} AS letzte_anmeldung
               FROM mandant.nutzer ORDER BY lower(familienname), lower(coalesce(vornamen, '')), id"));
        return Antwort::json(['nutzer' => $nutzer, 'ich' => $a->identitaet?->nutzerId()]);
    }

    /** Zuweisungen in diesem Buch (Ebene Buch): laufende und künftige, dazu die Rollen des Buchs zur Auswahl. */
    public function zuweisungen(Anfrage $a): Response
    {
        $db = $a->db();
        $z = fn (string $spalte) => sprintf(self::ZEIT, $spalte);
        $liste = array_map(fn (array $w) => ['id' => $w['id'], 'nutzer' => substr($w['subjekt'], 7), 'rolle' => $w['rolle'], 'art' => $w['art'],
            'gueltig_von' => $w['gueltig_von'], 'gueltig_bis' => $w['gueltig_bis']], $db->fetchAllAssociative(
            "SELECT z.id, z.subjekt, r.name AS rolle, r.art, {$z('z.gueltig_von')} AS gueltig_von, {$z('z.gueltig_bis')} AS gueltig_bis
               FROM kern.zuweisung z JOIN kern.rolle r ON r.id = z.rolle
              WHERE z.subjekt LIKE 'nutzer:%' AND (z.gueltig_bis IS NULL OR z.gueltig_bis > clock_timestamp())
              ORDER BY r.name, z.gueltig_von"));
        // Die Rolle „Administrator“ wird im Buch nicht mehr zugewiesen: Der Administrator steht im Mandanten (A10)
        $rollen = $db->fetchAllAssociative("SELECT name, art, beschreibung FROM kern.rolle WHERE art <> 'administrator' ORDER BY name");
        return Antwort::json(['zuweisungen' => $liste, 'rollen' => $rollen]);
    }

    /** Nutzer anlegen. Einen Zugang stellt `zugang` aus; Rollen weist das Buch zu (`…/b/{buch}/api/v1/zuweisungen`). */
    public function anlegen(Anfrage $a): Response
    {
        $e = new Eingabe($j = $a->json());
        $e->nurFelder(['vornamen', 'familienname', 'email']);
        $vornamen = $e->text('vornamen', true, 200);
        $familienname = $e->text('familienname', true, 200);
        $email = self::email($e, $j);
        $e->pruefen();

        $ktx = $a->kontext();
        $pseudonym = Base64Url::kodieren(random_bytes(32));
        // erst die Zuordnung in der Verwaltung (eigene, sofort gültige Anweisung), dann die Identität im Mandanten
        $v = $a->verwaltung();
        $v->executeStatement('SELECT verwaltung.pseudonym_eintragen(?, ?)', [$pseudonym, $ktx->mandant]);
        try {
            $id = (string) $a->mandantDb()->fetchOne('SELECT mandant.nutzer_anlegen(?, ?, ?, ?, NULL, ?)',
                [$ktx->subjekt, $familienname, $vornamen, $email, $pseudonym]);
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
        if (!$aktiv && (bool) $n['aktiv']) {
            self::letzterVerantwortlicher($a, $n['id']);
        }
        $a->mandantDb()->executeStatement('SELECT mandant.nutzer_aendern(?, ?::uuid, ?, ?, ?, ?::boolean)',
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
        $db = $a->mandantDb();
        $n_widerrufen = 0;
        if ($widerrufen) {
            foreach ($db->fetchFirstColumn('SELECT credential_id FROM mandant.anmelde_credential WHERE nutzer = ? AND widerrufen_am IS NULL', [$n['id']]) as $c) {
                $db->executeStatement('SELECT mandant.anmelde_credential_widerrufen(?, ?)', [$ktx->subjekt, $c]);
                $n_widerrufen++;
            }
        }
        $token = $ktx->mandant . '.' . Base64Url::kodieren(random_bytes(32));
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= self::CODE_ZEICHEN[random_int(0, strlen(self::CODE_ZEICHEN) - 1)];
        }
        $db->executeStatement('SELECT mandant.registrierung_ausstellen(?, ?::uuid, ?, make_interval(hours => ?), ?)',
            [$ktx->subjekt, $n['id'], hash('sha256', $token), $stunden, self::codeHash($token, $code)]);
        $bis = (string) $db->fetchOne(sprintf('SELECT ' . self::ZEIT . ' FROM mandant.registrierung WHERE token_hash = ?', 'ablauf'), [hash('sha256', $token)]);
        // Das Token steht im Fragment: Es landet nicht in Server-Logs. Die Antwort wird nicht zwischengespeichert.
        return Antwort::json(['link' => $a->konfig->adresse . '/app.html#registrierung=' . $token, 'code' => $code,
            'gueltig_bis' => $bis, 'passkeys_widerrufen' => $n_widerrufen], 201, ['Cache-Control' => 'no-store']);
    }

    public function passkeyWiderrufen(Anfrage $a): Response
    {
        $n = self::nutzer($a);
        $c = $a->param('credential');
        if (!$a->mandantDb()->fetchOne('SELECT 1 FROM mandant.anmelde_credential WHERE credential_id = ? AND nutzer = ?', [$c, $n['id']])) {
            throw Problem::nichtGefunden('Passkey unbekannt');
        }
        $a->mandantDb()->executeStatement('SELECT mandant.anmelde_credential_widerrufen(?, ?)', [$a->kontext()->subjekt, $c]);
        return Antwort::leer();
    }

    public function administratorEinsetzen(Anfrage $a): Response
    {
        $e = new Eingabe($a->json());
        $e->nurFelder(['nutzer']);
        $nutzer = (string) $e->text('nutzer', true, 36);
        $e->pruefen();
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $nutzer)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'nutzer', 'meldung' => 'Nutzer unbekannt']]);
        }
        $a->mandantDb()->executeStatement('SELECT mandant.administrator_einsetzen(?, ?::uuid)', [$a->kontext()->subjekt, $nutzer]);
        return Antwort::leer(201);
    }

    /** Administrator abberufen – der letzte bleibt (Datenbank). */
    public function administratorAbberufen(Anfrage $a): Response
    {
        $a->mandantDb()->executeStatement('SELECT mandant.administrator_abberufen(?, ?::uuid)', [$a->kontext()->subjekt, $a->param('id')]);
        return Antwort::leer();
    }

    /** Rolle in diesem Buch zuweisen, auf Wunsch befristet. */
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
            || !$a->mandantDb()->fetchOne('SELECT 1 FROM mandant.nutzer WHERE id = ?', [$nutzer])) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'nutzer', 'meldung' => 'Nutzer unbekannt']]);
        }
        if (!$db->fetchOne("SELECT 1 FROM kern.rolle WHERE name = ? AND art <> 'administrator'", [$rolle])) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'rolle', 'meldung' => 'Rolle unbekannt – Administratoren setzt der Mandant ein, nicht das Buch']]);
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
        return $a->mandantDb()->fetchAssociative('SELECT id, vornamen, familienname, email, aktiv FROM mandant.nutzer WHERE id = ?', [$a->param('id')])
            ?: throw Problem::nichtGefunden('Nutzer unbekannt');
    }

    /**
     * Der letzte Verantwortliche eines Buchs wird nicht gesperrt (VERWALTUNG.md Abschnitt 4). Ob ein Träger gesperrt
     * ist, weiß nur der Mandant – deshalb prüft das die Anwendung über alle Bücher, nicht die Datenbank eines Buchs.
     */
    private static function letzterVerantwortlicher(Anfrage $a, string $nutzer): void
    {
        $aktive = $a->mandantDb()->fetchFirstColumn('SELECT id FROM mandant.nutzer WHERE aktiv');
        foreach ($a->verwaltung()->fetchAllKeyValue('SELECT kennung, datenbank FROM verwaltung.buch WHERE mandant = ? ORDER BY kennung', [$a->kontext()->mandant]) as $buch => $datenbank) {
            $traeger = array_map(fn ($s) => substr((string) $s, 7), $a->datenbank->verbindung($datenbank)->fetchFirstColumn(
                "SELECT DISTINCT z.subjekt FROM kern.zuweisung z JOIN kern.rolle r ON r.id = z.rolle
                  WHERE r.art = 'verantwortlich' AND z.subjekt LIKE 'nutzer:%' AND z.gueltig_von <= clock_timestamp()
                    AND (z.gueltig_bis IS NULL OR z.gueltig_bis > clock_timestamp())"));
            $uebrig = array_diff(array_intersect($traeger, $aktive), [$nutzer]);
            if (in_array($nutzer, $traeger, true) && $uebrig === []) {
                throw new Problem(409, 'KM003', "Der letzte Verantwortliche des Buchs „{$buch}“ wird nicht gesperrt – er geht mit dem Buch");
            }
        }
    }

    private static function email(Eingabe $e, array $j): ?string
    {
        $w = $e->text('email', true, 254);
        if ($w !== null && !preg_match('/^[^@\s]+@[^@\s]+$/', trim($w))) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'email', 'meldung' => 'E-Mail-Adresse erwartet']]);
        }
        return $w === null ? null : trim($w);
    }
}
