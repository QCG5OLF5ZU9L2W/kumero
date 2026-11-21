<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Personal;

use Kumero\Api\V1\Dienste;
use Kumero\Api\V1\Kontext;
use Kumero\Api\V1\Problem;
use Kumero\Http\Antwort;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Personal am Mandanten (PERSONAL.md Stufe 1). Lesen per SELECT im eigenen Schema, Schreiben nur über die Funktionen
 * des Moduls (F44, F64). Wer was sieht, entscheidet sich hier je Datenklasse und je Person:
 *   – die eigene Person sieht alles Eigene (Recht personal.eigenes.sehen),
 *   – ein Vorgesetzter sieht Name, Funktion und Fristen der Personen, die ihm unmittelbar unterstehen,
 *   – private Angaben nur mit personal.privat.sehen, Verträge nur mit personal.vertrag.sehen.
 * Antworten enthalten nie die ID eines Nutzers, außer für den, der Personen pflegt (die Verknüpfung ist seine Aufgabe).
 */
final class WebController
{
    public const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const DATUM = '/^\d{4}-\d{2}-\d{2}$/';
    private const ARTEN = ['unbefristet', 'befristet', 'ausbildung', 'minijob', 'werkstudent'];
    private const PRIVAT = ['geburtsdatum', 'strasse', 'plz', 'ort', 'telefon', 'email', 'notfall_name', 'notfall_telefon'];
    private const VORSCHLAG = ['strasse', 'plz', 'ort', 'telefon', 'email', 'notfall_name', 'notfall_telefon', 'akademischer_titel'];
    private const OBJEKTARTEN = ['person', 'privat', 'vertrag', 'frist', 'vorschlag', 'abwesenheit', 'lohnvorbereitung', 'zuschlag', 'beurteilung', 'ziel'];

    /** @param array<string, mixed> $z Zeile mit familienname, vornamen und akademischer_titel */
    public static function name(array $z): string
    {
        // „Muster, Dr. Erika“: der akademische Titel steht vor dem Vornamen (F147)
        $vor = implode(' ', array_filter([$z['akademischer_titel'] ?? null, $z['vornamen'] ?? null], fn ($t) => $t !== null && $t !== ''));
        return $z['familienname'] . ($vor === '' ? '' : ', ' . $vor);
    }

    // --- Sicht ------------------------------------------------------------------------------------------------

    /** ID der eigenen Person oder null: die Person, die mit dem angemeldeten Nutzer verknüpft ist. */
    public static function ich(Anfrage $a): ?string
    {
        $s = $a->kontext()->subjekt;
        if (!str_starts_with($s, 'nutzer:') || !preg_match(self::UUID, substr($s, 7))) {
            return null;
        }
        $id = $a->db()->fetchOne('SELECT id FROM personal.person WHERE nutzer = ?', [substr($s, 7)]);
        return $id === false ? null : (string) $id;
    }

    /**
     * Was der Aufrufer sehen darf.
     *
     * @return array{alle: bool, team: list<string>, ich: ?string, privat: bool, vertrag: bool, pflegen: bool, abw: bool, au: bool, abw_pflegen: bool, genehmigen: bool}
     */
    public static function sicht(Anfrage $a): array
    {
        $k = $a->kontext();
        $ich = self::ich($a);
        $team = $ich !== null && $k->darf('personal.team.sehen')
            ? array_map('strval', $a->db()->fetchFirstColumn('SELECT id FROM personal.person WHERE vorgesetzter = ?', [$ich])) : [];
        return ['alle' => $k->darf('personal.person.sehen'), 'team' => $team, 'ich' => $k->darf('personal.eigenes.sehen') ? $ich : null,
                'privat' => $k->darf('personal.privat.sehen'), 'vertrag' => $k->darf('personal.vertrag.sehen'), 'pflegen' => $k->darf('personal.person.pflegen'),
                'verguetung' => $k->darf('personal.verguetung.sehen'),
                'abw' => $k->darf('personal.abwesenheit.sehen'), 'au' => $k->darf('personal.au.sehen'), 'abw_pflegen' => $k->darf('personal.abwesenheit.pflegen'),
                'genehmigen' => $k->darf('personal.abwesenheit.genehmigen')];
    }

    /** @param array{alle: bool, team: list<string>, ich: ?string} $s */
    public static function sichtbar(array $s, string $person): bool
    {
        return $s['alle'] || $person === $s['ich'] || in_array($person, $s['team'], true);
    }

    /** @return array<string, bool> */
    public static function darf(Kontext $k): array
    {
        return ['alle' => $k->darf('personal.person.sehen'), 'team' => $k->darf('personal.team.sehen'), 'privat' => $k->darf('personal.privat.sehen'),
                'pflegen' => $k->darf('personal.person.pflegen'), 'vertrag_sehen' => $k->darf('personal.vertrag.sehen'),
                'vertrag_pflegen' => $k->darf('personal.vertrag.pflegen'),
                'verguetung_sehen' => $k->darf('personal.verguetung.sehen'), 'verguetung_pflegen' => $k->darf('personal.verguetung.pflegen'),
                // Zugänge legt der Administrator an – auch aus dem Dialog der Person heraus (F149)
                'zugang_anlegen' => $k->darf(\Kumero\Plattform\Kernel::RECHT_ADMINISTRATOR), 'frist_pflegen' => $k->darf('personal.frist.pflegen'),
                'eigenes' => $k->darf('personal.eigenes.sehen'), 'vorschlagen' => $k->darf('personal.eigenes.vorschlagen'),
                'beantragen' => $k->darf('personal.eigenes.beantragen'), 'abwesenheit_sehen' => $k->darf('personal.abwesenheit.sehen'), 'au_sehen' => $k->darf('personal.au.sehen'),
                'abwesenheit_pflegen' => $k->darf('personal.abwesenheit.pflegen'), 'genehmigen' => $k->darf('personal.abwesenheit.genehmigen'), 'beurteilung' => $k->darf('personal.beurteilung.eigene')];
    }

    // --- Lesen ------------------------------------------------------------------------------------------------

    /** Liste der Personen, die der Aufrufer sehen darf, mit den Arten der Fristen und dem, was er darf. */
    public function stand(Anfrage $a): Response
    {
        $db = $a->db();
        $s = self::sicht($a);
        $warn = (int) $db->fetchOne('SELECT warn_tage FROM personal.einstellung');
        $personen = [];
        foreach ($db->fetchAllAssociative(
            "SELECT p.id, p.personalnummer, p.familienname, p.vornamen, p.akademischer_titel, p.vorgesetzter, p.nutzer, p.austritt::text AS austritt,
                    (p.austritt IS NOT NULL AND p.austritt < current_date) AS ausgeschieden,
                    v.familienname AS v_familienname, v.vornamen AS v_vornamen, v.akademischer_titel AS v_titel, p.arbeitsort, (SELECT o.name FROM personal.arbeitsort o WHERE o.id = personal._arbeitsort_von(p.id)) AS arbeitsort_name,
                    (SELECT funktion FROM personal.vertrag WHERE id = personal.vertrag_am(p.id)) AS funktion,
                    (SELECT count(*) FROM personal.frist f JOIN personal.fristart fa ON fa.id = f.art
                      WHERE f.person = p.id AND f.erledigt_am IS NULL AND f.faellig <= current_date + fa.warn_tage) AS fristen,
                    (SELECT count(*) FROM personal.vorschlag o WHERE o.person = p.id AND o.zustand = 'offen') AS vorschlaege,
                    (p.loeschen_ab IS NULL OR EXISTS (SELECT 1 FROM personal.privat x WHERE x.person = p.id AND x.loeschen_ab IS NULL)
                     OR EXISTS (SELECT 1 FROM personal.vertrag x WHERE x.person = p.id AND x.loeschen_ab IS NULL)
                     OR EXISTS (SELECT 1 FROM personal.frist x WHERE x.person = p.id AND x.loeschen_ab IS NULL)
                     OR EXISTS (SELECT 1 FROM personal.vorschlag x WHERE x.person = p.id AND x.loeschen_ab IS NULL)
                     OR EXISTS (SELECT 1 FROM personal.abwesenheit x WHERE x.person = p.id AND x.loeschen_ab IS NULL)) AS frist_fehlt
               FROM personal.person p LEFT JOIN personal.person v ON v.id = p.vorgesetzter
              ORDER BY (p.austritt IS NOT NULL AND p.austritt < current_date), lower(p.familienname), lower(coalesce(p.vornamen, '')), p.id") as $z) {
            if (!self::sichtbar($s, (string) $z['id'])) {
                continue;
            }
            $personen[] = ['id' => $z['id'], 'personalnummer' => $z['personalnummer'], 'name' => self::name($z), 'familienname' => $z['familienname'],
                'vornamen' => $z['vornamen'], 'akademischer_titel' => $z['akademischer_titel'], 'zustand' => $z['ausgeschieden'] ? 'ausgeschieden' : 'aktiv', 'austritt' => $z['austritt'],
                'vorgesetzter' => $z['vorgesetzter'],
                'vorgesetzter_name' => $z['vorgesetzter'] === null ? null : self::name(['familienname' => $z['v_familienname'], 'vornamen' => $z['v_vornamen'], 'akademischer_titel' => $z['v_titel']]),
                'funktion' => $z['funktion'], 'arbeitsort_name' => $z['arbeitsort_name'], 'fristen' => (int) $z['fristen'], 'mit_nutzer' => $z['nutzer'] !== null, 'ich' => $z['id'] === $s['ich'],
                // nur für den, der Personen pflegt
                'nutzer' => $s['pflegen'] ? $z['nutzer'] : null, 'vorschlaege' => $s['pflegen'] ? (int) $z['vorschlaege'] : null,
                'frist_fehlt' => $s['pflegen'] ? (bool) $z['frist_fehlt'] : null];
        }
        return Antwort::json(['darf' => self::darf($a->kontext()), 'ich' => $s['ich'], 'warn_tage' => $warn, 'heute' => date('Y-m-d'),
            'personen' => $personen, 'fristarten' => self::fristarten($a), 'vertragsarten' => self::ARTEN]);
    }

    /** @return list<array<string, mixed>> */
    private static function fristarten(Anfrage $a): array
    {
        return array_map(fn (array $z) => ['id' => $z['id'], 'name' => $z['name'], 'warn_tage' => (int) $z['warn_tage'], 'stillgelegt' => (bool) $z['stillgelegt']],
            $a->db()->fetchAllAssociative('SELECT id, name, warn_tage, stillgelegt FROM personal.fristart ORDER BY stillgelegt, lower(name)'));
    }

    /** Übersicht der Akte einer Person – je Datenklasse nur, was der Aufrufer sehen darf (sonst null). */
    public function akte(Anfrage $a): Response
    {
        $s = self::sicht($a);
        $id = $a->param('id');
        if (!self::sichtbar($s, $id)) {
            throw Problem::nichtGefunden('Person unbekannt');   // „nicht deine“ sieht aus wie „gibt es nicht“
        }
        return Antwort::json(self::akteDaten($a, $id, $s));
    }

    /** Die eigene Akte (Selfservice). */
    public function meine(Anfrage $a): Response
    {
        $s = self::sicht($a);
        if ($s['ich'] === null) {
            // wer das Personal führt, ist selbst gemeint: Er erfährt, wo er sich verknüpft
            return Antwort::json(['person' => null, 'hinweis' => $a->kontext()->darf('personal.person.pflegen')
                ? 'Dein Zugang ist noch mit keiner Person verknüpft. Du führst das Personal: Lege dich unter „Personen“ als Person an (oder bearbeite deinen Eintrag) und wähle dort bei „Zugang zu kumero“ deinen eigenen Zugang.'
                : 'Dein Zugang ist noch mit keiner Person verknüpft – das richtet das Personal ein.']);
        }
        // nur das Eigene, auch wenn die Rollen mehr erlaubten: Diese Seite ist der Blick der Person auf sich selbst
        return Antwort::json(self::akteDaten($a, $s['ich'], ['alle' => false, 'team' => [], 'ich' => $s['ich'], 'privat' => false, 'vertrag' => false, 'pflegen' => false,
            'abw' => false, 'au' => false, 'abw_pflegen' => false, 'genehmigen' => false])
            + ['lohn' => LohnController::eigene($a, $s['ich'])]
            + self::eigeneBeurteilungen($a, $s['ich'])
            + ['kalender' => [self::jahreskalender($a, $s['ich'], (int) date('Y')), self::jahreskalender($a, $s['ich'], (int) date('Y') + 1)]]
            + ['darf' => self::darf($a->kontext()), 'abwesenheitsarten' => AbwesenheitController::arten($a, true), 'freie_tage' => AbwesenheitController::freieTage($a)]);
    }

    /**
     * Jahreskalender einer Person (ZE8): Feiertage an ihrem Arbeitsort, freie Tage des Betriebs und Brückentage – ein
     * Arbeitstag zwischen einem solchen freien Tag und dem Wochenende. Für die Planung von Urlaub und Freistellung.
     *
     * @return array{jahr: int, tage: list<array{datum: string, art: string, name: string}>}
     */
    public static function jahreskalender(Anfrage $a, string $person, int $jahr): array
    {
        $frei = [];
        foreach ($a->db()->fetchAllAssociative("SELECT datum::text AS datum, name FROM personal.feiertage(personal._arbeitsort_von(?::uuid), make_date(?, 1, 1), make_date(?, 12, 31))", [$person, $jahr, $jahr]) as $z) {
            $frei[$z['datum']] = ['datum' => $z['datum'], 'art' => 'feiertag', 'name' => $z['name']];
        }
        foreach ($a->db()->fetchAllAssociative('SELECT datum::text AS datum, name FROM personal.freier_tag WHERE extract(year FROM datum) = ?', [$jahr]) as $z) {
            $frei[$z['datum']] ??= ['datum' => $z['datum'], 'art' => 'frei', 'name' => $z['name']];
        }
        $tage = $frei;
        foreach ($frei as $datum => $f) {
            $wt = (int) date('N', strtotime($datum));          // 1 = Montag
            // freier Tag am Donnerstag: der Freitag; am Dienstag: der Montag
            $bruecke = $wt === 4 ? date('Y-m-d', strtotime("$datum +1 day")) : ($wt === 2 ? date('Y-m-d', strtotime("$datum -1 day")) : null);
            if ($bruecke !== null && !isset($tage[$bruecke]) && substr($bruecke, 0, 4) === (string) $jahr) {
                $tage[$bruecke] = ['datum' => $bruecke, 'art' => 'bruecke', 'name' => 'Brückentag (' . $f['name'] . ')'];
            }
        }
        ksort($tage);
        return ['jahr' => $jahr, 'tage' => array_values($tage)];
    }

    /** Selfservice: eigene Beurteilungen und Ziele – nur mit dem Recht dazu. @return array<string, mixed> */
    private static function eigeneBeurteilungen(Anfrage $a, string $ich): array
    {
        if (!$a->kontext()->darf('personal.beurteilung.eigene')) {
            return ['beurteilungen' => null, 'ziele' => null];
        }
        $s = ['ich' => $ich, 'team' => [], 'sehen' => false, 'pflegen' => false, 'beurteilen' => false, 'eigene' => true];
        return ['beurteilungen' => BeurteilungController::beurteilungen($a, $s, $ich), 'ziele' => BeurteilungController::ziele($a, $s, $ich)];
    }

    /**
     * @param array{alle: bool, team: list<string>, ich: ?string, privat: bool, vertrag: bool, pflegen: bool} $s
     * @return array<string, mixed>
     */
    private static function akteDaten(Anfrage $a, string $id, array $s): array
    {
        $db = $a->db();
        $selbst = $id === $s['ich'];
        $p = $db->fetchAssociative(
            "SELECT p.id, p.personalnummer, p.familienname, p.vornamen, p.akademischer_titel, p.lohnbuero_nummer, p.vorgesetzter, p.nutzer, p.austritt::text AS austritt, p.loeschen_ab::text AS loeschen_ab,
                    p.arbeitsort, (SELECT o.name FROM personal.arbeitsort o WHERE o.id = personal._arbeitsort_von(p.id)) AS arbeitsort_name,
                    (p.austritt IS NOT NULL AND p.austritt < current_date) AS ausgeschieden, v.familienname AS v_familienname, v.vornamen AS v_vornamen, v.akademischer_titel AS v_titel
               FROM personal.person p LEFT JOIN personal.person v ON v.id = p.vorgesetzter WHERE p.id = ?", [$id]);
        if ($p === false) {
            throw Problem::nichtGefunden('Person unbekannt');
        }
        $frist = fn (array $z) => $s['pflegen'] ? ['loeschen_ab' => $z['loeschen_ab']] : [];
        $erg = ['person' => ['id' => $p['id'], 'personalnummer' => $p['personalnummer'], 'name' => self::name($p), 'familienname' => $p['familienname'],
            'vornamen' => $p['vornamen'], 'akademischer_titel' => $p['akademischer_titel'], 'zustand' => $p['ausgeschieden'] ? 'ausgeschieden' : 'aktiv', 'austritt' => $p['austritt'], 'vorgesetzter' => $p['vorgesetzter'],
            'vorgesetzter_name' => $p['vorgesetzter'] === null ? null : self::name(['familienname' => $p['v_familienname'], 'vornamen' => $p['v_vornamen'], 'akademischer_titel' => $p['v_titel']]),
            'arbeitsort' => $p['arbeitsort'], 'arbeitsort_name' => $p['arbeitsort_name'],
            'mit_nutzer' => $p['nutzer'] !== null, 'nutzer' => $s['pflegen'] ? $p['nutzer'] : null, 'ich' => $selbst]
                // die Nummer beim Lohnbüro kann aufsteigend sein – nur für den, der Verträge sieht, und für die Person selbst (F149)
                + ($s['vertrag'] || $selbst ? ['lohnbuero_nummer' => $p['lohnbuero_nummer']] : []) + $frist($p),
            'privat' => null, 'vertraege' => null, 'fristen' => [], 'vorschlaege' => null, 'abwesenheiten' => null, 'urlaubskonto' => null, 'heute' => date('Y-m-d')];
        // Abwesenheiten und Urlaubskonto: für die Person selbst und für den, der Abwesenheiten mit ihrer Art sehen darf
        if ($s['abw'] || $selbst) {
            $erg['abwesenheiten'] = array_values(array_filter(AbwesenheitController::liste($a, $s, null), fn (array $x) => $x['person'] === $id));
            $jahr = (int) date('Y');
            $erg['urlaubskonto'] = [AbwesenheitController::konto($a, $id, $jahr), AbwesenheitController::konto($a, $id, $jahr + 1)];
        }

        if ($s['privat'] || $selbst) {
            $x = $db->fetchAssociative('SELECT geburtsdatum::text AS geburtsdatum, strasse, plz, ort, telefon, email, notfall_name, notfall_telefon, loeschen_ab::text AS loeschen_ab
                                          FROM personal.privat WHERE person = ?', [$id]) ?: array_fill_keys(self::PRIVAT, null) + ['loeschen_ab' => null];
            $erg['privat'] = array_intersect_key($x, array_flip(self::PRIVAT)) + $frist($x);
        }
        if ($s['vertrag'] || $selbst) {
            $gilt = $db->fetchOne('SELECT personal.vertrag_am(?::uuid)', [$id]);
            $buecher = Dienste::buecherAmMandanten($a);
            $erg['vertraege'] = array_map(fn (array $v) => ['id' => $v['id'], 'gueltig_ab' => $v['gueltig_ab'], 'art' => $v['art'], 'funktion' => $v['funktion'], 'taetigkeit' => $v['taetigkeit'],
                'arbeitszeit_vereinbarung' => $v['arbeitszeit_vereinbarung'],
                'beginn' => $v['beginn'], 'probezeit_bis' => $v['probezeit_bis'], 'befristet_bis' => $v['befristet_bis'], 'kuendigungsfrist' => $v['kuendigungsfrist'],
                'kuendigung_art' => $v['kuendigung_art'], 'kuendigungsregel' => $v['kuendigungsregel'],
                // was heute gilt, rechnet kumero aus der Regel (F148) – für Akte und „Meine Daten“
                'kuendigung_anzeige' => Kuendigung::zeile((string) $v['kuendigung_art'], $v['regel_name'], $v['regel'] === null ? null : json_decode((string) $v['regel'], true),
                    $v['kuendigungsfrist'], (string) $v['beginn'], $v['probezeit_bis'], date('Y-m-d')),
                'wochenminuten' => (int) $v['wochenminuten'], 'verteilung' => array_map('intval', json_decode((string) $v['verteilung'], true)),
                'urlaubstage' => $v['urlaubstage'], 'lohnbuch' => $v['lohnbuch'], 'lohnbuch_name' => $v['lohnbuch'] === null ? null : ($buecher[$v['lohnbuch']] ?? $v['lohnbuch']),
                'angelegt_am' => $v['angelegt_am'], 'gilt' => $v['id'] === $gilt]
                + $frist($v),
                $db->fetchAllAssociative("SELECT v.id, v.gueltig_ab::text AS gueltig_ab, v.art, v.funktion, v.taetigkeit, v.arbeitszeit_vereinbarung, v.beginn::text AS beginn, v.probezeit_bis::text AS probezeit_bis,
                        v.befristet_bis::text AS befristet_bis, v.kuendigungsfrist, v.kuendigung_art, v.kuendigungsregel, r.name AS regel_name, r.regel::text AS regel,
                        v.wochenminuten, to_json(v.verteilung)::text AS verteilung, v.urlaubstage::text AS urlaubstage,
                        v.lohnbuch, to_char(v.angelegt_am AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS angelegt_am, v.loeschen_ab::text AS loeschen_ab
                   FROM personal.vertrag v
                   LEFT JOIN personal.kuendigungsregel r ON r.id = v.kuendigungsregel OR (v.kuendigung_art = 'gesetzlich' AND r.art = 'gesetz')
                  WHERE v.person = ? ORDER BY v.gueltig_ab DESC, v.angelegt_am DESC", [$id]));
        }
        // Vergütung ist eine eigene Datenklasse (F153), seit F157 ein Raster an der Person: eine Zeile je „gültig ab“. Das Feld steht nur
        // in der Antwort, wenn der Aufrufer sie sehen darf – oder es die eigene ist.
        if (($s['verguetung'] ?? false) || $selbst) {
            $gilt = $db->fetchOne('SELECT personal.entgelt_am(?::uuid)', [$id]);
            $erg['verguetung'] = array_map(fn (array $z) => [
                'id' => $z['id'], 'gueltig_ab' => $z['gueltig_ab'], 'art' => $z['art'],
                'eingruppierung' => $z['eingruppierung'], 'grund_betrag' => $z['grund_betrag'], 'leistungsstufe' => $z['leistungsstufe'], 'leistung_betrag' => $z['leistung_betrag'],
                'altersstufe' => $z['altersstufe'], 'alter_betrag' => $z['alter_betrag'], 'summe' => $z['summe'],
                'urlaubsgeld_art' => $z['urlaubsgeld_art'], 'urlaubsgeld_wert' => $z['urlaubsgeld_wert'], 'urlaubsgeld_monat' => $z['urlaubsgeld_monat'] === null ? null : (int) $z['urlaubsgeld_monat'],
                'weihnachtsgeld_art' => $z['weihnachtsgeld_art'], 'weihnachtsgeld_wert' => $z['weihnachtsgeld_wert'], 'weihnachtsgeld_monat' => $z['weihnachtsgeld_monat'] === null ? null : (int) $z['weihnachtsgeld_monat'],
                'vereinbarung' => $z['vereinbarung'], 'gilt' => $z['id'] === $gilt],
                $db->fetchAllAssociative("SELECT id, gueltig_ab::text AS gueltig_ab, art, eingruppierung, grund_betrag::text AS grund_betrag, leistungsstufe, leistung_betrag::text AS leistung_betrag,
                        altersstufe, alter_betrag::text AS alter_betrag, (coalesce(grund_betrag, 0) + coalesce(leistung_betrag, 0) + coalesce(alter_betrag, 0))::text AS summe,
                        urlaubsgeld_art, urlaubsgeld_wert::text AS urlaubsgeld_wert, urlaubsgeld_monat, weihnachtsgeld_art, weihnachtsgeld_wert::text AS weihnachtsgeld_wert, weihnachtsgeld_monat, vereinbarung
                   FROM personal.entgelt WHERE person = ? ORDER BY gueltig_ab DESC", [$id]));
        }
        $erg['fristen'] = array_values(array_filter(self::fristenListe($a, $s, true), fn (array $f) => $f['person'] === $id));
        if ($s['pflegen'] || $selbst) {
            $erg['vorschlaege'] = array_map(fn (array $v) => ['id' => $v['id'], 'felder' => json_decode((string) $v['felder'], true), 'zustand' => $v['zustand'],
                'grund' => $v['grund'], 'angelegt_am' => $v['angelegt_am'], 'entschieden_am' => $v['entschieden_am']] + $frist($v),
                $db->fetchAllAssociative("SELECT id, felder::text AS felder, zustand, grund, loeschen_ab::text AS loeschen_ab,
                        to_char(angelegt_am AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS angelegt_am,
                        to_char(entschieden_am AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS entschieden_am
                   FROM personal.vorschlag WHERE person = ? ORDER BY (zustand = 'offen') DESC, angelegt_am DESC LIMIT 50", [$id]));
        }
        return $erg;
    }

    /**
     * Fristen der sichtbaren Personen: eigene Einträge und – für den, der Verträge sehen darf, und für die eigene
     * Person – die beiden Termine aus dem geltenden Vertrag (Probezeitende, Ende der Befristung), solange sie nicht
     * vorbei sind.
     *
     * @param array{alle: bool, team: list<string>, ich: ?string, privat: bool, vertrag: bool, pflegen: bool} $s
     * @return list<array<string, mixed>>
     */
    private static function fristenListe(Anfrage $a, array $s, bool $mitErledigten = false): array
    {
        $db = $a->db();
        $erg = [];
        $zustand = fn (int $tage, int $warn) => $tage < 0 ? 'ueberfaellig' : ($tage <= $warn ? 'faellig' : 'offen');
        foreach ($db->fetchAllAssociative(
            "SELECT f.id, f.person, p.familienname, p.vornamen, p.akademischer_titel, p.personalnummer, fa.id AS art, fa.name AS art_name, fa.warn_tage, f.faellig::text AS faellig,
                    (f.faellig - current_date) AS tage, f.bemerkung, f.loeschen_ab::text AS loeschen_ab,
                    to_char(f.erledigt_am AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS erledigt_am
               FROM personal.frist f JOIN personal.person p ON p.id = f.person JOIN personal.fristart fa ON fa.id = f.art
              WHERE " . ($mitErledigten ? 'true' : 'f.erledigt_am IS NULL') . ' ORDER BY f.faellig, lower(p.familienname), f.id') as $z) {
            if (!self::sichtbar($s, (string) $z['person'])) {
                continue;
            }
            $erg[] = ['id' => $z['id'], 'quelle' => 'frist', 'person' => $z['person'], 'name' => self::name($z), 'personalnummer' => $z['personalnummer'],
                'art' => $z['art'], 'art_name' => $z['art_name'], 'faellig' => $z['faellig'], 'tage' => (int) $z['tage'], 'bemerkung' => $z['bemerkung'],
                'erledigt_am' => $z['erledigt_am'], 'zustand' => $z['erledigt_am'] !== null ? 'erledigt' : $zustand((int) $z['tage'], (int) $z['warn_tage'])]
                + ($s['pflegen'] ? ['loeschen_ab' => $z['loeschen_ab']] : []);
        }
        $warn = (int) $db->fetchOne('SELECT warn_tage FROM personal.einstellung');
        foreach ($db->fetchAllAssociative(
            "SELECT p.id AS person, p.familienname, p.vornamen, p.akademischer_titel, p.personalnummer, v.probezeit_bis::text AS probezeit_bis, v.befristet_bis::text AS befristet_bis,
                    (v.probezeit_bis - current_date) AS tage_probe, (v.befristet_bis - current_date) AS tage_befristung
               FROM personal.person p JOIN personal.vertrag v ON v.id = personal.vertrag_am(p.id)
              WHERE p.austritt IS NULL OR p.austritt >= current_date") as $z) {
            if (!($s['vertrag'] && self::sichtbar($s, (string) $z['person'])) && $z['person'] !== $s['ich']) {
                continue;
            }
            foreach ([['probezeit_bis', 'tage_probe', 'Ende der Probezeit'], ['befristet_bis', 'tage_befristung', 'Ende der Befristung']] as [$feld, $tage, $text]) {
                if ($z[$feld] !== null && (int) $z[$tage] >= 0) {
                    $erg[] = ['id' => null, 'quelle' => 'vertrag', 'person' => $z['person'], 'name' => self::name($z), 'personalnummer' => $z['personalnummer'],
                        'art' => null, 'art_name' => $text, 'faellig' => $z[$feld], 'tage' => (int) $z[$tage], 'bemerkung' => 'aus dem Vertrag',
                        'erledigt_am' => null, 'zustand' => $zustand((int) $z[$tage], $warn)];
                }
            }
        }
        usort($erg, fn (array $x, array $y) => [$x['faellig'], $x['name'], (string) $x['id']] <=> [$y['faellig'], $y['name'], (string) $y['id']]);
        return $erg;
    }

    public function fristen(Anfrage $a): Response
    {
        $s = self::sicht($a);
        return Antwort::json(['darf' => self::darf($a->kontext()), 'heute' => date('Y-m-d'), 'warn_tage' => (int) $a->db()->fetchOne('SELECT warn_tage FROM personal.einstellung'),
            'fristen' => self::fristenListe($a, $s), 'fristarten' => self::fristarten($a),
            'personen' => array_values(array_map(fn (array $z) => ['id' => $z['id'], 'name' => self::name($z)], array_filter(
                $a->db()->fetchAllAssociative("SELECT id, familienname, vornamen, akademischer_titel FROM personal.person WHERE austritt IS NULL OR austritt >= current_date
                                                ORDER BY lower(familienname), lower(coalesce(vornamen, '')), id"),
                fn (array $z) => self::sichtbar($s, (string) $z['id']))))]);
    }

    /** Zähler am Menüpunkt: überfällige und fällige Fristen der sichtbaren Personen. */
    public function zaehler(Anfrage $a): Response
    {
        $zahl = count(array_filter(self::fristenListe($a, self::sicht($a)), fn (array $f) => in_array($f['zustand'], ['ueberfaellig', 'faellig'], true)));
        return Antwort::json(['anzahl' => $zahl]);   // der Rahmen liest „anzahl“ (OBERFLAECHE.md 3)
    }

    /** Nutzer und Bücher des Mandanten zur Auswahl beim Pflegen einer Person bzw. eines Vertrags. */
    public function auswahl(Anfrage $a): Response
    {
        $vergeben = array_map('strval', $a->db()->fetchFirstColumn('SELECT nutzer FROM personal.person WHERE nutzer IS NOT NULL'));
        return Antwort::json(['nutzer' => array_map(fn (array $n) => $n + ['vergeben' => in_array($n['id'], $vergeben, true)], Dienste::nutzerAmMandanten($a)),
            'buecher' => array_map(fn (string $k, string $n) => ['kennung' => $k, 'name' => $n], array_keys($b = Dienste::buecherAmMandanten($a)), array_values($b)),
            'arbeitsorte' => AbwesenheitController::arbeitsorte($a), 'kuendigungsregeln' => self::kuendigungsregelnListe($a)]);
    }

    // --- Kündigungsregeln (F148): die gesetzliche und die Tarifverträge des Mandanten ---------------------------------

    /** @return list<array<string, mixed>> */
    private static function kuendigungsregelnListe(Anfrage $a): array
    {
        return array_map(fn (array $r) => ['id' => $r['id'], 'art' => $r['art'], 'name' => $r['name'], 'regel' => json_decode((string) $r['regel'], true), 'stillgelegt' => (bool) $r['stillgelegt']],
            $a->db()->fetchAllAssociative("SELECT id, art, name, regel::text AS regel, stillgelegt FROM personal.kuendigungsregel ORDER BY (art <> 'gesetz'), lower(name)"));
    }

    public function kuendigungsregeln(Anfrage $a): Response
    {
        return Antwort::json(['kuendigungsregeln' => self::kuendigungsregelnListe($a)]);
    }

    public function kuendigungsregelAnlegen(Anfrage $a): Response
    {
        return $this->kuendigungsregelSetzen($a, null);
    }

    public function kuendigungsregelAendern(Anfrage $a): Response
    {
        return $this->kuendigungsregelSetzen($a, $a->param('id'));
    }

    private function kuendigungsregelSetzen(Anfrage $a, ?string $id): Response
    {
        $j = $a->json();
        self::nurFelder($j, ['name', 'regel', 'stillgelegt']);
        $fehler = [];
        $f = self::felder($j, ['name' => ['pflichttext', 80]], $fehler);
        if (!is_array($j['regel'] ?? null) || array_is_list($j['regel'])) {
            $fehler[] = ['feld' => 'regel', 'meldung' => 'Objekt erwartet'];
        }
        if (array_key_exists('stillgelegt', $j) && !is_bool($j['stillgelegt'])) {
            $fehler[] = ['feld' => 'stillgelegt', 'meldung' => 'Wahr oder falsch erwartet'];
        }
        self::pruefen($fehler);
        $neu = (string) $a->db()->fetchOne('SELECT personal.kuendigungsregel_setzen(?, ?::uuid, ?, ?::jsonb, ?::boolean)',
            [$a->kontext()->subjekt, $id, $f['name'], json_encode($j['regel'], JSON_UNESCAPED_UNICODE), ($j['stillgelegt'] ?? false) ? 'true' : 'false']);
        return $id === null ? Antwort::json(['id' => $neu], 201) : Antwort::leer();
    }

    /** Die gesetzliche Regel wieder aus der Steuerdatei `regelwerk/kuendigung-de.json` einlesen; Tarifverträge bleiben. */
    public function kuendigungsregelVorgabe(Anfrage $a): Response
    {
        $a->json(false);
        $datei = dirname(__DIR__) . '/regelwerk/kuendigung-de.json';
        $d = is_file($datei) ? json_decode((string) file_get_contents($datei), true) : null;
        if (!is_array($d) || !is_array($d['gesetz']['regel'] ?? null)) {
            throw new Problem(500, 'personal.steuerdatei', 'Die Steuerdatei der Kündigungsfristen fehlt oder ist nicht lesbar');
        }
        $id = $a->db()->fetchOne("SELECT id FROM personal.kuendigungsregel WHERE art = 'gesetz'");
        $a->db()->executeStatement('SELECT personal.kuendigungsregel_setzen(?, ?::uuid, ?, ?::jsonb, false)',
            [$a->kontext()->subjekt, $id, (string) $d['gesetz']['name'], json_encode($d['gesetz']['regel'], JSON_UNESCAPED_UNICODE)]);
        return Antwort::json(['stand' => $d['stand'] ?? null]);
    }

    // --- Schreiben --------------------------------------------------------------------------------------------

    /** @param list<array{feld: string, meldung: string}> $fehler */
    public static function pruefen(array $fehler): void
    {
        if ($fehler !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $fehler);
        }
    }

    /** @param array<string, mixed> $j */
    public static function nurFelder(array $j, array $erlaubt): void
    {
        $fremd = array_diff(array_keys($j), $erlaubt);
        if ($fremd !== []) {
            throw Problem::ungueltig('Eingabe ungültig', array_map(fn ($f) => ['feld' => (string) $f, 'meldung' => 'Unbekanntes Feld'], array_values($fremd)));
        }
    }

    /**
     * Prüft Texte, Daten und IDs einer Eingabe; gibt die bereinigten Werte zurück.
     *
     * @param array<string, mixed> $j
     * @param array<string, array{0: string, 1?: int}> $form Feld → [text|datum|uuid|pflichttext, Länge]
     * @return array<string, ?string>
     */
    public static function felder(array $j, array $form, array &$fehler, string $vor = ''): array
    {
        $erg = [];
        foreach ($form as $feld => $f) {
            $w = $j[$feld] ?? null;
            if ($w !== null && !is_string($w)) {
                $fehler[] = ['feld' => $vor . $feld, 'meldung' => 'Text erwartet'];
                continue;
            }
            $w = $w === null ? null : trim($w);
            $w = $w === '' ? null : $w;
            if ($w === null) {
                if ($f[0] === 'pflichttext' || $f[0] === 'pflichtdatum') {
                    $fehler[] = ['feld' => $vor . $feld, 'meldung' => 'Fehlt'];
                }
            } elseif (($f[0] === 'datum' || $f[0] === 'pflichtdatum') && (!preg_match(self::DATUM, $w) || !checkdate((int) substr($w, 5, 2), (int) substr($w, 8, 2), (int) substr($w, 0, 4)))) {
                $fehler[] = ['feld' => $vor . $feld, 'meldung' => 'Kein gültiges Datum'];
            } elseif ($f[0] === 'uuid' && !preg_match(self::UUID, $w)) {
                $fehler[] = ['feld' => $vor . $feld, 'meldung' => 'Unbekannt'];
            } elseif (isset($f[1]) && mb_strlen($w) > $f[1]) {
                $fehler[] = ['feld' => $vor . $feld, 'meldung' => "Höchstens {$f[1]} Zeichen"];
            }
            $erg[$feld] = $w;
        }
        return $erg;
    }

    public function personAnlegen(Anfrage $a): Response
    {
        return $this->personSetzen($a, null);
    }

    public function personAendern(Anfrage $a): Response
    {
        return $this->personSetzen($a, $a->param('id'));
    }

    private function personSetzen(Anfrage $a, ?string $id): Response
    {
        $j = $a->json();
        // Die Personalnummer vergibt kumero zufällig (F147) – niemand gibt sie ein. Beim Ändern kann eine neue verlangt werden.
        self::nurFelder($j, array_merge(['familienname', 'vornamen', 'akademischer_titel', 'lohnbuero_nummer', 'vorgesetzter', 'nutzer', 'austritt', 'arbeitsort', 'privat'], $id === null ? [] : ['personalnummer_neu']));
        $fehler = [];
        if (array_key_exists('personalnummer_neu', $j) && !is_bool($j['personalnummer_neu'])) {
            $fehler[] = ['feld' => 'personalnummer_neu', 'meldung' => 'Wahr oder falsch erwartet'];
        }
        $stamm = self::felder($j, ['familienname' => ['pflichttext', 100], 'vornamen' => ['text', 100], 'akademischer_titel' => ['text', 40], 'lohnbuero_nummer' => ['text', 20],
            'vorgesetzter' => ['uuid'], 'nutzer' => ['uuid'], 'austritt' => ['datum']], $fehler);
        $ort = self::felder($j, ['arbeitsort' => ['uuid']], $fehler)['arbeitsort'] ?? null;
        if (!array_key_exists('lohnbuero_nummer', $j)) {
            unset($stamm['lohnbuero_nummer']);   // nicht genannt heißt: bleibt, wie sie ist
        } elseif (($stamm['lohnbuero_nummer'] ?? null) !== null && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,19}$/', $stamm['lohnbuero_nummer'])) {
            $fehler[] = ['feld' => 'lohnbuero_nummer', 'meldung' => 'Buchstaben, Ziffern und . _ / - ; höchstens 20 Zeichen'];
        }
        if (($stamm['nutzer'] ?? null) !== null && !in_array($stamm['nutzer'], array_column(Dienste::nutzerAmMandanten($a), 'id'), true)) {
            $fehler[] = ['feld' => 'nutzer', 'meldung' => 'Nutzer unbekannt'];
        }
        $privat = null;
        if (array_key_exists('privat', $j)) {
            // private Angaben schreibt nur, wer sie auch sehen darf
            if (!$a->kontext()->darf('personal.privat.sehen')) {
                throw new Problem(403, 'recht.fehlt', 'Recht fehlt: personal.privat.sehen');
            }
            if (!is_array($j['privat'])) {
                $fehler[] = ['feld' => 'privat', 'meldung' => 'Objekt erwartet'];
            } else {
                self::nurFelder($j['privat'], self::PRIVAT);
                $privat = self::felder($j['privat'], ['geburtsdatum' => ['datum'], 'strasse' => ['text', 100], 'plz' => ['text', 10], 'ort' => ['text', 100],
                    'telefon' => ['text', 40], 'email' => ['text', 254], 'notfall_name' => ['text', 100], 'notfall_telefon' => ['text', 40]], $fehler, 'privat.');
                if (($privat['email'] ?? null) !== null && !preg_match('/^[^@\s]+@[^@\s]+$/', $privat['email'])) {
                    $fehler[] = ['feld' => 'privat.email', 'meldung' => 'Keine E-Mail-Adresse'];
                }
            }
        }
        self::pruefen($fehler);
        $neu = (string) $a->db()->fetchOne('SELECT personal.person_setzen(?, ?::uuid, ?::jsonb)',
            [$a->kontext()->subjekt, $id, json_encode($stamm + ['personalnummer_neu' => ($j['personalnummer_neu'] ?? false) === true], JSON_UNESCAPED_UNICODE)]);
        if (array_key_exists('arbeitsort', $j)) {
            // der Arbeitsort bestimmt die Feiertage der Person; leer = der des Mandanten
            $a->db()->executeStatement('SELECT personal.person_arbeitsort_setzen(?, ?::uuid, ?::uuid)', [$a->kontext()->subjekt, $neu, $ort]);
        }
        if ($privat !== null) {
            $a->db()->executeStatement('SELECT personal.privat_setzen(?, ?::uuid, ?::jsonb)', [$a->kontext()->subjekt, $neu, json_encode($privat, JSON_UNESCAPED_UNICODE)]);
        }
        return $id === null ? Antwort::json(['id' => $neu], 201) : Antwort::leer();
    }

    public function vertragAnlegen(Anfrage $a): Response
    {
        $j = $a->json();
        self::nurFelder($j, ['gueltig_ab', 'art', 'funktion', 'taetigkeit', 'arbeitszeit_vereinbarung', 'beginn', 'probezeit_bis', 'befristet_bis', 'kuendigung_art', 'kuendigungsregel', 'kuendigungsfrist', 'verteilung', 'urlaubstage', 'lohnbuch']);
        $fehler = [];
        $v = self::felder($j, ['gueltig_ab' => ['pflichtdatum'], 'art' => ['pflichttext', 20], 'funktion' => ['text', 100], 'taetigkeit' => ['text', 2000], 'arbeitszeit_vereinbarung' => ['text', 2000], 'beginn' => ['pflichtdatum'],
            'probezeit_bis' => ['datum'], 'befristet_bis' => ['datum'], 'kuendigungsfrist' => ['text', 200], 'lohnbuch' => ['text', 20], 'kuendigung_art' => ['text', 20], 'kuendigungsregel' => ['uuid']], $fehler);
        if (isset($v['art']) && !in_array($v['art'], self::ARTEN, true)) {
            $fehler[] = ['feld' => 'art', 'meldung' => 'Art unbekannt'];
        }
        if (($v['art'] ?? null) === 'befristet' && ($v['befristet_bis'] ?? null) === null) {
            $fehler[] = ['feld' => 'befristet_bis', 'meldung' => 'Ein befristeter Vertrag braucht sein Ende'];
        }
        foreach (['probezeit_bis' => 'Die Probezeit endet vor dem Beginn', 'befristet_bis' => 'Die Befristung endet vor dem Beginn'] as $feld => $text) {
            if (($v[$feld] ?? null) !== null && ($v['beginn'] ?? null) !== null && $v[$feld] < $v['beginn']) {
                $fehler[] = ['feld' => $feld, 'meldung' => $text];
            }
        }
        $vt = $j['verteilung'] ?? null;
        if (!is_array($vt) || !array_is_list($vt) || count($vt) !== 7 || array_filter($vt, fn ($x) => !is_int($x) || $x < 0 || $x > 1440) !== []) {
            $fehler[] = ['feld' => 'verteilung', 'meldung' => 'Sieben Werte in Minuten erwartet, Montag bis Sonntag, je 0 bis 1440'];
        } elseif (array_sum($vt) > 6000) {
            $fehler[] = ['feld' => 'verteilung', 'meldung' => 'Mehr als 100 Stunden in der Woche'];
        }
        $u = $j['urlaubstage'] ?? null;
        if ($u !== null && $u !== '' && (!is_string($u) || !preg_match('/^\d{1,2}(\.[05])?$/', $u))) {
            $fehler[] = ['feld' => 'urlaubstage', 'meldung' => 'Tage als Zahl, halbe Tage mit .5'];
        }
        if (($v['lohnbuch'] ?? null) !== null && !isset(Dienste::buecherAmMandanten($a)[$v['lohnbuch']])) {
            $fehler[] = ['feld' => 'lohnbuch', 'meldung' => 'Buch unbekannt'];
        }
        self::pruefen($fehler);
        $id = (string) $a->db()->fetchOne('SELECT personal.vertrag_anlegen(?, ?::uuid, ?::jsonb)', [$a->kontext()->subjekt, $a->param('id'),
            json_encode($v + ['verteilung' => $vt, 'urlaubstage' => $u === '' ? null : $u], JSON_UNESCAPED_UNICODE)]);
        return Antwort::json(['id' => $id], 201);
    }

    public function verguetungAnlegen(Anfrage $a): Response
    {
        return $this->verguetungSetzen($a, null);
    }

    public function verguetungAendern(Anfrage $a): Response
    {
        return $this->verguetungSetzen($a, self::zeile($a));
    }

    public function verguetungLoeschen(Anfrage $a): Response
    {
        $a->db()->executeStatement('SELECT personal.entgelt_loeschen(?, ?::uuid, ?::uuid)', [$a->kontext()->subjekt, $a->param('id'), self::zeile($a)]);
        return Antwort::leer();
    }

    private static function zeile(Anfrage $a): string
    {
        $z = (string) $a->param('zeile');
        if (preg_match(self::UUID, $z) !== 1) {
            throw Problem::nichtGefunden('Zeile der Vergütung unbekannt');
        }
        return $z;
    }

    /**
     * Eine Zeile des Vergütungsrasters (F157): „gültig ab“, Eingruppierung, Leistungsstufe und Altersstufe mit je einem Brutto
     * (je Monat oder je Stunde), Urlaubs- und Weihnachtsgeld als fester Betrag oder als Prozent eines Monatsbruttos.
     * Beträge sind Dezimal-Strings (F19).
     */
    private function verguetungSetzen(Anfrage $a, ?string $zeile): Response
    {
        $g = $a->json();
        self::nurFelder($g, ['gueltig_ab', 'art', 'eingruppierung', 'grund_betrag', 'leistungsstufe', 'leistung_betrag', 'altersstufe', 'alter_betrag',
            'urlaubsgeld_art', 'urlaubsgeld_wert', 'urlaubsgeld_monat', 'weihnachtsgeld_art', 'weihnachtsgeld_wert', 'weihnachtsgeld_monat', 'vereinbarung']);
        $fehler = [];
        $zahl = fn (mixed $w): bool => is_string($w) && preg_match('/^\d{1,7}(\.\d{1,2})?$/', $w) === 1 && (float) $w > 0;
        $x = self::felder($g, ['gueltig_ab' => ['pflichtdatum'], 'eingruppierung' => ['text', 60], 'leistungsstufe' => ['text', 60], 'altersstufe' => ['text', 60], 'vereinbarung' => ['text', 1000]], $fehler);
        $x['art'] = $g['art'] ?? null;
        if (!in_array($x['art'], ['monat', 'stunde'], true)) {
            $fehler[] = ['feld' => 'art', 'meldung' => 'Beträge je Monat (monat) oder je Stunde (stunde)'];
        }
        $einer = false;
        foreach (['grund_betrag', 'leistung_betrag', 'alter_betrag'] as $n) {
            $x[$n] = $g[$n] ?? null;
            if ($x[$n] !== null && !$zahl($x[$n])) {
                $fehler[] = ['feld' => $n, 'meldung' => 'Brutto in Euro als Dezimal-String, größer als null, höchstens zwei Nachkommastellen'];
            }
            $einer = $einer || $x[$n] !== null;
        }
        if (!$einer) {
            $fehler[] = ['feld' => 'grund_betrag', 'meldung' => 'Wenigstens ein Brutto gehört dazu – an der Eingruppierung, der Leistungsstufe oder der Altersstufe'];
        }
        foreach (['urlaubsgeld', 'weihnachtsgeld'] as $was) {
            $art = $g[$was . '_art'] ?? 'keins';
            $wert = $g[$was . '_wert'] ?? null;
            $monat = $g[$was . '_monat'] ?? null;
            if (!in_array($art, ['keins', 'betrag', 'prozent'], true)) {
                $fehler[] = ['feld' => "{$was}_art", 'meldung' => 'keins, betrag oder prozent'];
            } elseif ($art === 'keins') {
                if ($wert !== null || $monat !== null) {
                    $fehler[] = ['feld' => "{$was}_wert", 'meldung' => 'Wert und Monat gehören nur dazu, wenn es vereinbart ist'];
                }
            } else {
                if (!$zahl($wert)) {
                    $fehler[] = ['feld' => "{$was}_wert", 'meldung' => 'Wert als Dezimal-String, größer als null, höchstens zwei Nachkommastellen'];
                } elseif ($art === 'prozent' && ($x['art'] !== 'monat' || (float) $wert > 300)) {
                    $fehler[] = ['feld' => "{$was}_wert", 'meldung' => 'Prozent nur bei Beträgen je Monat, höchstens 300'];
                }
                if ($monat !== null && (!is_int($monat) || $monat < 1 || $monat > 12)) {
                    $fehler[] = ['feld' => "{$was}_monat", 'meldung' => 'Monat der Auszahlung von 1 bis 12'];
                }
            }
            $x += [$was . '_art' => $art, $was . '_wert' => $wert, $was . '_monat' => $monat];
        }
        self::pruefen($fehler);
        $id = (string) $a->db()->fetchOne('SELECT personal.entgelt_setzen(?, ?::uuid, ?::uuid, ?::jsonb)', [$a->kontext()->subjekt, $a->param('id'), $zeile, json_encode($x, JSON_UNESCAPED_UNICODE)]);
        return $zeile === null ? Antwort::json(['id' => $id], 201) : Antwort::leer();
    }

    public function fristAnlegen(Anfrage $a): Response
    {
        $j = $a->json();
        self::nurFelder($j, ['person', 'art', 'faellig', 'bemerkung']);
        $fehler = [];
        $f = self::felder($j, ['person' => ['uuid'], 'art' => ['uuid'], 'faellig' => ['pflichtdatum'], 'bemerkung' => ['text', 200]], $fehler);
        foreach (['person', 'art'] as $feld) {
            if (($f[$feld] ?? null) === null && !in_array($feld, array_column($fehler, 'feld'), true)) {
                $fehler[] = ['feld' => $feld, 'meldung' => 'Fehlt'];
            }
        }
        self::pruefen($fehler);
        $id = (string) $a->db()->fetchOne('SELECT personal.frist_anlegen(?, ?::uuid, ?::uuid, ?::date, ?)', [$a->kontext()->subjekt, $f['person'], $f['art'], $f['faellig'], $f['bemerkung']]);
        return Antwort::json(['id' => $id], 201);
    }

    public function fristErledigen(Anfrage $a): Response
    {
        $a->json(false);
        $a->db()->executeStatement('SELECT personal.frist_erledigen(?, ?::uuid)', [$a->kontext()->subjekt, $a->param('id')]);
        return Antwort::leer();
    }

    public function fristartSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        self::nurFelder($j, ['name', 'warn_tage', 'stillgelegt']);
        $fehler = [];
        $f = self::felder($j, ['name' => ['pflichttext', 60]], $fehler);
        $warn = $j['warn_tage'] ?? 60;
        if (!is_int($warn) || $warn < 0 || $warn > 365) {
            $fehler[] = ['feld' => 'warn_tage', 'meldung' => '0 bis 365 Tage'];
        }
        if (isset($j['stillgelegt']) && !is_bool($j['stillgelegt'])) {
            $fehler[] = ['feld' => 'stillgelegt', 'meldung' => 'Ja oder nein erwartet'];
        }
        self::pruefen($fehler);
        $id = $a->param['id'] ?? null;
        $neu = (string) $a->db()->fetchOne('SELECT personal.fristart_setzen(?, ?::uuid, ?, ?::integer, ?::boolean)',
            [$a->kontext()->subjekt, $id, $f['name'], $warn, ($j['stillgelegt'] ?? false) ? 'true' : 'false']);
        return $id === null ? Antwort::json(['id' => $neu], 201) : Antwort::leer();
    }

    public function einstellung(Anfrage $a): Response
    {
        $j = $a->json();
        self::nurFelder($j, ['warn_tage']);
        if (!is_int($j['warn_tage'] ?? null) || $j['warn_tage'] < 0 || $j['warn_tage'] > 365) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'warn_tage', 'meldung' => '0 bis 365 Tage']]);
        }
        $a->db()->executeStatement('SELECT personal.einstellung_setzen(?, ?::integer)', [$a->kontext()->subjekt, $j['warn_tage']]);
        return Antwort::leer();
    }

    /** Selfservice: Die Person schlägt Änderungen ihrer Kontaktdaten vor; übernommen wird erst durch das Personal (PE17). */
    public function vorschlagen(Anfrage $a): Response
    {
        $ich = self::ich($a);
        if ($ich === null) {
            throw new Problem(409, 'personal.nicht_verknuepft', 'Dein Zugang ist noch mit keiner Person verknüpft');
        }
        $j = $a->json();
        self::nurFelder($j, self::VORSCHLAG);
        $fehler = [];
        $f = self::felder($j, ['strasse' => ['text', 100], 'plz' => ['text', 10], 'ort' => ['text', 100], 'telefon' => ['text', 40], 'email' => ['text', 254],
            'notfall_name' => ['text', 100], 'notfall_telefon' => ['text', 40], 'akademischer_titel' => ['text', 40]], $fehler);
        if (($f['email'] ?? null) !== null && !preg_match('/^[^@\s]+@[^@\s]+$/', $f['email'])) {
            $fehler[] = ['feld' => 'email', 'meldung' => 'Keine E-Mail-Adresse'];
        }
        // nur was genannt ist, ist vorgeschlagen; ein leeres Feld heißt „löschen“
        $f = array_intersect_key($f, $j);
        if ($f === [] && $fehler === []) {
            $fehler[] = ['feld' => 'strasse', 'meldung' => 'Der Vorschlag nennt keine Änderung'];
        }
        self::pruefen($fehler);
        $id = (string) $a->db()->fetchOne('SELECT personal.vorschlag_anlegen(?, ?::uuid, ?::jsonb)', [$a->kontext()->subjekt, $ich, json_encode($f, JSON_UNESCAPED_UNICODE)]);
        return Antwort::json(['id' => $id], 201);
    }

    public function vorschlagEntscheiden(Anfrage $a): Response
    {
        $j = $a->json();
        self::nurFelder($j, ['uebernehmen', 'grund']);
        $fehler = [];
        $f = self::felder($j, ['grund' => ['text', 200]], $fehler);
        if (!is_bool($j['uebernehmen'] ?? null)) {
            $fehler[] = ['feld' => 'uebernehmen', 'meldung' => 'Ja oder nein erwartet'];
        } elseif (!$j['uebernehmen'] && ($f['grund'] ?? null) === null) {
            $fehler[] = ['feld' => 'grund', 'meldung' => 'Eine Ablehnung braucht einen Grund'];
        }
        self::pruefen($fehler);
        // übernommen werden private Angaben – das darf nur, wer sie sehen darf
        if ($j['uebernehmen'] && !$a->kontext()->darf('personal.privat.sehen')) {
            throw new Problem(403, 'recht.fehlt', 'Recht fehlt: personal.privat.sehen');
        }
        $a->db()->executeStatement('SELECT personal.vorschlag_entscheiden(?, ?::uuid, ?::boolean, ?)', [$a->kontext()->subjekt, $a->param('id'), $j['uebernehmen'] ? 'true' : 'false', $f['grund']]);
        return Antwort::leer();
    }

    /** Löschfrist eines einzelnen Objekts setzen oder zurücknehmen (PE12): mit Begründung, im Protokoll. */
    public function loeschfrist(Anfrage $a): Response
    {
        $j = $a->json();
        self::nurFelder($j, ['objektart', 'id', 'loeschen_ab', 'grund']);
        $fehler = [];
        $f = self::felder($j, ['objektart' => ['pflichttext', 20], 'id' => ['uuid'], 'loeschen_ab' => ['datum'], 'grund' => ['pflichttext', 200]], $fehler);
        if (isset($f['objektart']) && !in_array($f['objektart'], self::OBJEKTARTEN, true)) {
            $fehler[] = ['feld' => 'objektart', 'meldung' => 'Art des Objekts unbekannt'];
        }
        if (($f['id'] ?? null) === null && !in_array('id', array_column($fehler, 'feld'), true)) {
            $fehler[] = ['feld' => 'id', 'meldung' => 'Fehlt'];
        }
        self::pruefen($fehler);
        $a->db()->executeStatement('SELECT personal.loeschfrist_setzen(?, ?, ?::uuid, ?::date, ?)', [$a->kontext()->subjekt, $f['objektart'], $f['id'], $f['loeschen_ab'], $f['grund']]);
        return Antwort::leer();
    }
}
