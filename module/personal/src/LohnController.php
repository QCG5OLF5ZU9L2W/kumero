<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Personal;

use Kumero\Api\V1\Problem;
use Kumero\Http\Antwort;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zuschlagsregeln, Zuschläge von Hand und Lohnvorbereitung (PERSONAL.md Stufe 3, Abschnitt 10d, F140).
 * Datenklasse „Lohn“: sehen mit `personal.lohn.sehen` (Rollen Personal und Lohn), die Person selbst sieht ihre
 * übergebenen Lohnvorbereitungen unter „Meine Daten“; Vorgesetzte sehen nichts davon.
 * Was in einer Lohnvorbereitung steht, rechnet die Datenbank an einer Stelle (personal._lohn_inhalt); mit der Prüfung
 * wird es eingefroren. Beträge sind ganze Cent, Sätze Hundertstel Prozent.
 */
final class LohnController
{
    private const ZEIT = "to_char(%s AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"')";
    private const MONAT = '/^\d{4}-(0[1-9]|1[0-2])$/';

    /** @return array<string, bool> */
    public static function darf(Anfrage $a): array
    {
        $k = $a->kontext();
        return ['sehen' => $k->darf('personal.lohn.sehen'), 'pruefen' => $k->darf('personal.lohn.pruefen'), 'uebergeben' => $k->darf('personal.lohn.uebergeben'),
                'zuschlag' => $k->darf('personal.zuschlag.pflegen')];
    }

    private static function monat(Anfrage $a, ?string $m = null): string
    {
        $m ??= (string) $a->param('monat');
        if (!preg_match(self::MONAT, $m)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'monat', 'meldung' => 'Monat als JJJJ-MM erwartet']]);
        }
        return $m . '-01';
    }

    private static function person(Anfrage $a): string
    {
        $p = (string) $a->param('person');
        if (!preg_match(WebController::UUID, $p)) {
            throw Problem::nichtGefunden('Person unbekannt');
        }
        return $p;
    }

    /** Regeln mit ihren Fassungen und Zuordnungen. @return array{regeln: list<array<string, mixed>>, zuordnungen: list<array<string, mixed>>} */
    public static function regeln(Anfrage $a): array
    {
        $db = $a->db();
        $fassungen = [];
        foreach ($db->fetchAllAssociative('SELECT id, regel, gueltig_ab::text AS gueltig_ab, form, satz_bp, betrag_cent, schwelle_cent, satz_ab_schwelle_bp, umfang, bezug, zeitart
                                             FROM personal.zuschlagsregel_fassung ORDER BY gueltig_ab DESC, angelegt_am DESC') as $f) {
            $fassungen[$f['regel']][] = ['id' => $f['id'], 'gueltig_ab' => $f['gueltig_ab'], 'form' => $f['form']]
                + array_map(fn ($w) => $w === null ? null : (int) $w, array_intersect_key($f, array_flip(['satz_bp', 'betrag_cent', 'schwelle_cent', 'satz_ab_schwelle_bp'])))
                + ['umfang' => $f['umfang'], 'bezug' => $f['bezug'], 'zeitart' => $f['zeitart']];
        }
        $regeln = array_map(fn (array $z) => ['id' => $z['id'], 'name' => $z['name'], 'grundlage' => $z['grundlage'], 'lohnart' => $z['lohnart'], 'stillgelegt' => (bool) $z['stillgelegt'],
            'gilt' => $z['gilt'], 'fassungen' => $fassungen[$z['id']] ?? []],
            $db->fetchAllAssociative('SELECT id, name, grundlage, lohnart, stillgelegt, personal._regel_fassung_am(id, current_date) AS gilt FROM personal.zuschlagsregel ORDER BY stillgelegt, lower(name)'));
        $zuordnungen = array_map(fn (array $z) => ['id' => $z['id'], 'regel' => $z['regel'], 'person' => $z['person'],
            'person_name' => $z['person'] === null ? null : WebController::name($z), 'funktion' => $z['funktion'], 'von' => $z['von'], 'bis' => $z['bis']],
            $db->fetchAllAssociative('SELECT z.id, z.regel, z.person, p.familienname, p.vornamen, p.akademischer_titel, z.funktion, z.von::text AS von, z.bis::text AS bis
                                        FROM personal.zuschlag_zuordnung z LEFT JOIN personal.person p ON p.id = z.person ORDER BY z.von DESC, z.id'));
        return ['regeln' => $regeln, 'zuordnungen' => $zuordnungen];
    }

    /** Lohnvorbereitungen eines Monats: je beschäftigter Person die letzte Fassung, offen gerechnet, sonst eingefroren. */
    public function stand(Anfrage $a): Response
    {
        $db = $a->db();
        $monat = self::monat($a, (string) ($a->http->query->get('monat') ?? date('Y-m', strtotime('first day of last month'))));
        $zeilen = $db->fetchAllAssociative(
            'SELECT p.id, p.personalnummer, p.familienname, p.vornamen, p.akademischer_titel, personal._im_monat_beschaeftigt(p.id, ?::date) AS beschaeftigt
               FROM personal.person p
              WHERE personal._im_monat_beschaeftigt(p.id, ?::date) OR EXISTS (SELECT 1 FROM personal.lohnvorbereitung l WHERE l.person = p.id AND l.monat = ?::date)
              ORDER BY lower(p.familienname), lower(coalesce(p.vornamen, \'\')), p.id', [$monat, $monat, $monat]);
        $personen = [];
        foreach ($zeilen as $z) {
            $personen[] = ['person' => $z['id'], 'personalnummer' => $z['personalnummer'], 'name' => WebController::name($z)] + self::eine($a, (string) $z['id'], $monat, (bool) $z['beschaeftigt']);
        }
        $funktionen = $db->fetchFirstColumn("SELECT DISTINCT funktion FROM personal.vertrag WHERE funktion IS NOT NULL ORDER BY 1");
        $auswahl = array_map(fn (array $z) => ['id' => $z['id'], 'name' => WebController::name($z)],
            $db->fetchAllAssociative('SELECT id, familienname, vornamen, akademischer_titel FROM personal.person WHERE austritt IS NULL OR austritt >= current_date ORDER BY lower(familienname), lower(coalesce(vornamen, \'\')), id'));
        return Antwort::json(['monat' => substr($monat, 0, 7), 'heute' => date('Y-m-d'), 'darf' => self::darf($a), 'personen' => $personen,
            'auswahl' => $auswahl, 'funktionen' => $funktionen] + self::regeln($a));
    }

    /**
     * Die Lohnvorbereitung einer Person in einem Monat mit früheren Fassungen.
     *
     * @return array<string, mixed>
     */
    private static function eine(Anfrage $a, string $person, string $monat, bool $beschaeftigt): array
    {
        $db = $a->db();
        $fassungen = $db->fetchAllAssociative(
            'SELECT id, fassung, zustand, inhalt::text AS inhalt, bemerkung, vermerk, loeschen_ab::text AS loeschen_ab, ' . sprintf(self::ZEIT, 'geprueft_am') . ' AS geprueft_am, '
            . sprintf(self::ZEIT, 'uebergeben_am') . ' AS uebergeben_am FROM personal.lohnvorbereitung WHERE person = ? AND monat = ?::date ORDER BY fassung DESC', [$person, $monat]);
        $l = $fassungen[0] ?? null;
        $gerechnet = $beschaeftigt ? (string) $db->fetchOne('SELECT personal._lohn_inhalt(?::uuid, ?::date)::text', [$person, $monat]) : null;
        $inhalt = $l !== null && $l['inhalt'] !== null ? $l['inhalt'] : $gerechnet;
        // eingefroren und seither anders: sichtbar machen, nicht stillschweigend ändern
        $abweichung = $l !== null && $l['inhalt'] !== null && $gerechnet !== null
            && $db->fetchOne('SELECT ?::jsonb IS DISTINCT FROM ?::jsonb', [$l['inhalt'], $gerechnet]) === true;
        return ['id' => $l['id'] ?? null, 'zustand' => $l['zustand'] ?? 'offen', 'fassung' => (int) ($l['fassung'] ?? 1), 'bemerkung' => $l['bemerkung'] ?? null, 'vermerk' => $l['vermerk'] ?? null,
            'geprueft_am' => $l['geprueft_am'] ?? null, 'uebergeben_am' => $l['uebergeben_am'] ?? null, 'loeschen_ab' => $l['loeschen_ab'] ?? null,
            'inhalt' => $inhalt === null ? null : json_decode($inhalt, true), 'abweichung' => $abweichung,
            'fruehere' => array_map(fn (array $f) => ['fassung' => (int) $f['fassung'], 'uebergeben_am' => $f['uebergeben_am'], 'vermerk' => $f['vermerk'], 'inhalt' => json_decode((string) $f['inhalt'], true)],
                array_slice($fassungen, 1))];
    }

    /** Selfservice: die übergebenen Lohnvorbereitungen der eigenen Person – was das Lohnbüro bekommen hat. @return list<array<string, mixed>> */
    public static function eigene(Anfrage $a, string $person): array
    {
        return array_map(fn (array $z) => ['monat' => $z['monat'], 'fassung' => (int) $z['fassung'], 'uebergeben_am' => $z['uebergeben_am'], 'inhalt' => json_decode((string) $z['inhalt'], true)],
            $a->db()->fetchAllAssociative("SELECT to_char(monat, 'YYYY-MM') AS monat, fassung, inhalt::text AS inhalt, " . sprintf(self::ZEIT, 'uebergeben_am') . " AS uebergeben_am
                 FROM personal.lohnvorbereitung WHERE person = ? AND zustand = 'uebergeben' ORDER BY monat DESC, fassung DESC LIMIT 24", [$person]));
    }

    // --- Ablauf -----------------------------------------------------------------------------------------------

    public function pruefen(Anfrage $a): Response
    {
        $a->json(false);
        $a->db()->executeStatement('SELECT personal.lohn_pruefen(?, ?::uuid, ?::date)', [$a->kontext()->subjekt, self::person($a), self::monat($a)]);
        return Antwort::leer();
    }

    public function oeffnen(Anfrage $a): Response
    {
        $a->json(false);
        $a->db()->executeStatement('SELECT personal.lohn_oeffnen(?, ?::uuid, ?::date)', [$a->kontext()->subjekt, self::person($a), self::monat($a)]);
        return Antwort::leer();
    }

    public function uebergeben(Anfrage $a): Response
    {
        $a->json(false);
        $a->db()->executeStatement('SELECT personal.lohn_uebergeben(?, ?::uuid, ?::date)', [$a->kontext()->subjekt, self::person($a), self::monat($a)]);
        return Antwort::leer();
    }

    public function neueFassung(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['vermerk']);
        $fehler = [];
        $f = WebController::felder($j, ['vermerk' => ['pflichttext', 200]], $fehler);
        WebController::pruefen($fehler);
        $a->db()->executeStatement('SELECT personal.lohn_neue_fassung(?, ?::uuid, ?::date, ?)', [$a->kontext()->subjekt, self::person($a), self::monat($a), $f['vermerk']]);
        return Antwort::leer();
    }

    public function bemerkung(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['bemerkung']);
        $fehler = [];
        $f = WebController::felder($j, ['bemerkung' => ['text', 500]], $fehler);
        WebController::pruefen($fehler);
        $a->db()->executeStatement('SELECT personal.lohn_bemerkung_setzen(?, ?::uuid, ?::date, ?)', [$a->kontext()->subjekt, self::person($a), self::monat($a), $f['bemerkung']]);
        return Antwort::leer();
    }

    // --- Zuschläge --------------------------------------------------------------------------------------------

    /** Ganze Zahl aus der Eingabe oder Fehler; null bleibt null. */
    private static function zahl(array $j, string $feld, array &$fehler, int $min, int $max, bool $pflicht = false): ?int
    {
        $w = $j[$feld] ?? null;
        if ($w === null) {
            if ($pflicht) {
                $fehler[] = ['feld' => $feld, 'meldung' => 'Fehlt'];
            }
            return null;
        }
        if (!is_int($w) || $w < $min || $w > $max) {
            $fehler[] = ['feld' => $feld, 'meldung' => 'Ganze Zahl im zulässigen Bereich erwartet'];
            return null;
        }
        return $w;
    }

    public function zuschlagAnlegen(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['person', 'monat', 'betrag_cent', 'begruendung', 'lohnart']);
        $fehler = [];
        $f = WebController::felder($j, ['person' => ['uuid'], 'monat' => ['pflichttext', 7], 'begruendung' => ['pflichttext', 200], 'lohnart' => ['text', 20]], $fehler);
        $betrag = self::zahl($j, 'betrag_cent', $fehler, -100000000, 100000000, true);
        if ($betrag === 0) {
            $fehler[] = ['feld' => 'betrag_cent', 'meldung' => 'Der Betrag ist null'];
        }
        if (($f['person'] ?? null) === null && !in_array('person', array_column($fehler, 'feld'), true)) {
            $fehler[] = ['feld' => 'person', 'meldung' => 'Fehlt'];
        }
        WebController::pruefen($fehler);
        $id = (string) $a->db()->fetchOne('SELECT personal.zuschlag_hand_anlegen(?, ?::uuid, ?::date, ?::integer, ?, ?)',
            [$a->kontext()->subjekt, $f['person'], self::monat($a, (string) $f['monat']), $betrag, $f['begruendung'], $f['lohnart']]);
        return Antwort::json(['id' => $id], 201);
    }

    public function zuschlagZuruecknehmen(Anfrage $a): Response
    {
        $a->json(false);
        $a->db()->executeStatement('SELECT personal.zuschlag_hand_zuruecknehmen(?, ?::uuid)', [$a->kontext()->subjekt, $a->param('id')]);
        return Antwort::leer();
    }

    /** Regel anlegen (mit erster Fassung) oder Name, Lohnart und „stillgelegt“ ändern. */
    public function regelSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        $id = $a->param['id'] ?? null;
        WebController::nurFelder($j, $id === null ? ['name', 'grundlage', 'lohnart', 'fassung'] : ['name', 'lohnart', 'stillgelegt']);
        $fehler = [];
        $f = WebController::felder($j, ['name' => ['pflichttext', 60], 'lohnart' => ['text', 20]] + ($id === null ? ['grundlage' => ['pflichttext', 10]] : []), $fehler);
        if (isset($j['stillgelegt']) && !is_bool($j['stillgelegt'])) {
            $fehler[] = ['feld' => 'stillgelegt', 'meldung' => 'Ja oder nein erwartet'];
        }
        $fassung = null;
        if ($id === null) {
            if (!is_array($j['fassung'] ?? null)) {
                $fehler[] = ['feld' => 'fassung', 'meldung' => 'Eine Regel braucht ihre erste Fassung'];
            } else {
                $fassung = self::fassungLesen($j['fassung'], $fehler);
            }
        }
        WebController::pruefen($fehler);
        $neu = $a->db()->transactional(function ($db) use ($a, $id, $f, $j, $fassung) {
            $regel = (string) $db->fetchOne('SELECT personal.zuschlagsregel_setzen(?, ?::uuid, ?::jsonb)',
                [$a->kontext()->subjekt, $id, json_encode($f + (isset($j['stillgelegt']) ? ['stillgelegt' => $j['stillgelegt']] : []), JSON_UNESCAPED_UNICODE)]);
            if ($fassung !== null) {
                $db->fetchOne('SELECT personal.zuschlagsregel_fassung(?, ?::uuid, ?::jsonb)', [$a->kontext()->subjekt, $regel, json_encode($fassung, JSON_UNESCAPED_UNICODE)]);
            }
            return $regel;
        });
        return $id === null ? Antwort::json(['id' => $neu], 201) : Antwort::leer();
    }

    public function fassungAnlegen(Anfrage $a): Response
    {
        $fehler = [];
        $fassung = self::fassungLesen($a->json(), $fehler);
        WebController::pruefen($fehler);
        $id = (string) $a->db()->fetchOne('SELECT personal.zuschlagsregel_fassung(?, ?::uuid, ?::jsonb)', [$a->kontext()->subjekt, $a->param('id'), json_encode($fassung, JSON_UNESCAPED_UNICODE)]);
        return Antwort::json(['id' => $id], 201);
    }

    /**
     * @param array<string, mixed> $j
     * @return array<string, mixed>
     */
    private static function fassungLesen(array $j, array &$fehler): array
    {
        WebController::nurFelder($j, ['gueltig_ab', 'form', 'satz_bp', 'betrag_cent', 'schwelle_cent', 'satz_ab_schwelle_bp', 'umfang', 'bezug', 'zeitart']);
        $f = WebController::felder($j, ['gueltig_ab' => ['pflichtdatum'], 'form' => ['pflichttext', 20], 'umfang' => ['text', 20], 'bezug' => ['text', 60], 'zeitart' => ['text', 20]], $fehler, 'fassung.');
        foreach (['satz_bp' => 100000, 'betrag_cent' => 100000000, 'schwelle_cent' => 2000000000, 'satz_ab_schwelle_bp' => 100000] as $feld => $max) {
            $f[$feld] = self::zahl($j, $feld, $fehler, 0, $max);
        }
        return array_filter($f, fn ($w) => $w !== null);
    }

    public function zuordnen(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['person', 'funktion', 'von', 'bis']);
        $fehler = [];
        $f = WebController::felder($j, ['person' => ['uuid'], 'funktion' => ['text', 100], 'von' => ['pflichtdatum'], 'bis' => ['datum']], $fehler);
        WebController::pruefen($fehler);
        $id = (string) $a->db()->fetchOne('SELECT personal.zuschlag_zuordnen(?, ?::uuid, ?::jsonb)', [$a->kontext()->subjekt, $a->param('id'), json_encode(array_filter($f, fn ($w) => $w !== null), JSON_UNESCAPED_UNICODE)]);
        return Antwort::json(['id' => $id], 201);
    }

    public function zuordnungBeenden(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['bis']);
        $fehler = [];
        $f = WebController::felder($j, ['bis' => ['pflichtdatum']], $fehler);
        WebController::pruefen($fehler);
        $a->db()->executeStatement('SELECT personal.zuschlag_zuordnung_beenden(?, ?::uuid, ?::date)', [$a->kontext()->subjekt, $a->param('id'), $f['bis']]);
        return Antwort::leer();
    }
}
