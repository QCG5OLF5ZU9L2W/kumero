<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Personal;

use Kumero\Api\V1\Problem;
use Kumero\Http\Antwort;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Abwesenheiten (PERSONAL.md Stufe 2, Abschnitt 10b): Urlaub, Arbeitsunfähigkeit, Freistellungen und weitere Arten;
 * Antrag, Genehmigung, Ablehnung, Rücknahme; Urlaubskonto; freie Tage. Wer was sieht (PE8):
 *   – die Person selbst und wer `personal.abwesenheit.sehen` hat (Personal, Lohn): alles samt Art;
 *     die Angaben zur Bescheinigung einer AU zusätzlich nur mit `personal.au.sehen` bzw. für die Person selbst;
 *   – der Vorgesetzte für sein Team: bei Arten, die eine Genehmigung brauchen, den Antrag mit Art und Bemerkung (er
 *     entscheidet ihn); bei allen anderen – AU, Mutterschutz, Elternzeit – nur „abwesend“ von–bis, ohne Art.
 * Eine Diagnose führt kumero nie: Es gibt kein Feld dafür, und eine AU trägt keine Bemerkung.
 */
final class AbwesenheitController
{
    private const ZEIT = "to_char(%s AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"')";

    // --- Lesen ------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public static function arten(Anfrage $a, bool $nurWaehlbare = false): array
    {
        return array_map(fn (array $z) => ['id' => $z['id'], 'kennung' => $z['kennung'], 'name' => $z['name'], 'bezahlt' => (bool) $z['bezahlt'],
            'zaehlt_urlaub' => (bool) $z['zaehlt_urlaub'], 'genehmigung' => (bool) $z['genehmigung'], 'selbst' => (bool) $z['selbst'], 'stillgelegt' => (bool) $z['stillgelegt'],
            // der Ablauf vor der Genehmigung (10n): leer heißt, es gilt die Vorgabe
            'ablauf' => $z['ablauf'] === null ? null : json_decode((string) $z['ablauf'], true), 'ablauf_vorgabe' => json_decode((string) $z['vorgabe'], true)],
            $a->db()->fetchAllAssociative('SELECT id, kennung, name, bezahlt, zaehlt_urlaub, genehmigung, selbst, stillgelegt, ablauf::text AS ablauf, personal._ablauf_vorgabe()::text AS vorgabe FROM personal.abwesenheitsart'
                . ($nurWaehlbare ? ' WHERE selbst AND NOT stillgelegt' : '') . " ORDER BY stillgelegt, (kennung = 'urlaub') DESC NULLS LAST, (kennung = 'au') DESC NULLS LAST, lower(name)"));
    }

    /** @return list<array{datum: string, name: string}> */
    public static function freieTage(Anfrage $a): array
    {
        return $a->db()->fetchAllAssociative("SELECT datum::text AS datum, name FROM personal.freier_tag WHERE datum >= date_trunc('year', current_date) - interval '1 year' ORDER BY datum");
    }

    /**
     * Urlaubskonto nach Vertrag, Regel des Jahres und dem, was am Konto festgesetzt ist (PERSONAL.md 10c).
     *
     * @return array<string, mixed>
     */
    public static function konto(Anfrage $a, string $person, int $jahr): array
    {
        $k = $a->db()->fetchAssociative('SELECT *, verfall_am::text AS verfall_am FROM personal.urlaubskonto_stand(?::uuid, ?::integer)', [$person, $jahr]);
        $z = array_map('floatval', array_intersect_key($k, array_flip(['anspruch', 'voll', 'uebertrag', 'beantragt', 'genehmigt', 'genommen', 'verfallen', 'verfall_droht', 'verfall_offen'])));
        $rest = round($z['anspruch'] + $z['uebertrag'] - $z['verfallen'] - $z['genommen'] - $z['genehmigt'], 2);
        $bestaetigt = $a->db()->fetchOne('SELECT verfallen IS NOT NULL FROM personal.urlaubskonto WHERE person = ?::uuid AND jahr = ?', [$person, $jahr]);
        return ['person' => $person, 'jahr' => $jahr, 'festgesetzt' => (bool) $k['festgesetzt'], 'uebertrag_festgesetzt' => (bool) $k['uebertrag_festgesetzt'], 'verfallen_festgesetzt' => $bestaetigt === true,
            'monate' => $k['monate'] === null ? null : (int) $k['monate'], 'verfall_am' => $k['verfall_am']] + $z
            + ['rest' => $rest, 'rest_nach_antraegen' => round($rest - $z['beantragt'], 2)];
    }

    /** @return list<array<string, mixed>> */
    public static function arbeitsorte(Anfrage $a): array
    {
        $standard = $a->db()->fetchOne('SELECT arbeitsort FROM personal.einstellung');
        $liste = fn (string $t) => $t === '{}' ? [] : explode(',', trim($t, '{}'));
        return array_map(fn (array $z) => ['id' => $z['id'], 'name' => $z['name'], 'land' => $z['land'], 'zusatz' => $liste((string) $z['zusatz']), 'ohne' => $liste((string) $z['ohne']),
            'stillgelegt' => (bool) $z['stillgelegt'], 'standard' => $z['id'] === $standard],
            $a->db()->fetchAllAssociative('SELECT id, name, land, zusatz::text AS zusatz, ohne::text AS ohne, stillgelegt FROM personal.arbeitsort ORDER BY stillgelegt, lower(name)'));
    }

    /** Das Regelwerk der Feiertage, wie es der Mandant führt. @return list<array<string, mixed>> */
    public static function regelwerk(Anfrage $a): array
    {
        $liste = fn (string $t) => $t === '{}' ? [] : explode(',', trim($t, '{}'));
        return array_map(fn (array $z) => ['kennung' => $z['kennung'], 'name' => $z['name'], 'art' => $z['art'], 'monat' => $z['monat'], 'tag' => $z['tag'], 'abstand' => $z['abstand'],
            'bundesweit' => (bool) $z['bundesweit'], 'laender' => $liste((string) $z['laender']), 'teile' => $liste((string) $z['teile'])],
            $a->db()->fetchAllAssociative('SELECT kennung, name, art, monat, tag, abstand, bundesweit, laender::text AS laender, teile::text AS teile FROM personal.feiertag ORDER BY reihenfolge, kennung'));
    }

    /** Feiertage eines Jahres je Arbeitsort; unter '' die ohne Arbeitsort (bundeseinheitliche). @return array<string, list<array{datum: string, name: string}>> */
    public static function feiertage(Anfrage $a, int $jahr): array
    {
        $erg = [];
        foreach (array_merge([null], array_column(self::arbeitsorte($a), 'id')) as $ort) {
            $erg[$ort ?? ''] = $a->db()->fetchAllAssociative('SELECT datum::text AS datum, name FROM personal.feiertage(?::uuid, make_date(?, 1, 1), make_date(?, 12, 31))', [$ort, $jahr, $jahr]);
        }
        return $erg;
    }

    /** @return list<array<string, mixed>> */
    public static function urlaubsregeln(Anfrage $a): array
    {
        return array_map(fn (array $z) => ['ab_jahr' => (int) $z['ab_jahr'], 'anteilig' => $z['anteilig'], 'rundung' => $z['rundung'], 'uebertrag' => $z['uebertrag'],
            'uebertrag_hoechstens' => $z['uebertrag_hoechstens'] === null ? null : (float) $z['uebertrag_hoechstens'], 'verfall' => $z['verfall'],
            'verfall_tag' => (int) $z['verfall_tag'], 'verfall_monat' => (int) $z['verfall_monat']],
            $a->db()->fetchAllAssociative('SELECT * FROM personal.urlaubsregel ORDER BY ab_jahr'));
    }

    /**
     * Abwesenheiten der sichtbaren Personen, je Eintrag nur, was der Aufrufer sehen darf.
     *
     * @param array{alle: bool, team: list<string>, ich: ?string, pflegen: bool, abw: bool, au: bool, abw_pflegen: bool, genehmigen: bool} $s
     * @param ?int $jahr nur Einträge, die dieses Jahr berühren
     * @return list<array<string, mixed>>
     */
    public static function liste(Anfrage $a, array $s, ?int $jahr): array
    {
        $ich = WebController::ich($a);   // auch ohne die Rolle Mitarbeiter: Niemand entscheidet über sich selbst wie über ein Teammitglied
        $wo = $jahr === null ? 'true' : 'x.von <= make_date(?, 12, 31) AND x.bis >= make_date(?, 1, 1)';
        $zeilen = $a->db()->fetchAllAssociative(
            "SELECT x.id, x.person, p.familienname, p.vornamen, p.akademischer_titel, p.personalnummer, x.art, art.name AS art_name, art.kennung, art.genehmigung, art.zaehlt_urlaub,
                    x.von::text AS von, x.bis::text AS bis, x.halber_tag, x.tage, x.tage_folgejahr, x.tage_von_hand, x.bemerkung, x.zustand, x.grund, x.loeschen_ab::text AS loeschen_ab,
                    " . sprintf(self::ZEIT, 'x.angelegt_am') . ' AS angelegt_am, ' . sprintf(self::ZEIT, 'x.entschieden_am') . " AS entschieden_am,
                    u.festgestellt_am::text AS festgestellt_am, u.folge, u.arbeitsunfall, u.nachweis, u.nachweis_form,
                    x.vertretung, v.familienname AS v_familienname, v.vornamen AS v_vornamen, v.akademischer_titel AS v_titel,
                    (x.vertretung IS NOT NULL AND EXISTS (SELECT 1 FROM personal.abwesenheit y WHERE y.person = x.vertretung AND y.zustand IN ('beantragt', 'genehmigt') AND y.von <= x.bis AND y.bis >= x.von)) AS vertretung_abwesend,
                    (SELECT coalesce(jsonb_agg(jsonb_build_object('nr', s.nr, 'was', s.was, 'wer', s.wer, 'person', s.person, 'zustand', s.zustand,
                                'name', (SELECT concat_ws(', ', q.familienname, nullif(concat_ws(' ', q.akademischer_titel, q.vornamen), '')) FROM personal.person q WHERE q.id = s.person)) ORDER BY s.nr), '[]')
                       FROM personal.abwesenheit_schritt s WHERE s.abwesenheit = x.id)::text AS schritte
               FROM personal.abwesenheit x JOIN personal.person p ON p.id = x.person JOIN personal.abwesenheitsart art ON art.id = x.art
               LEFT JOIN personal.au u ON u.abwesenheit = x.id LEFT JOIN personal.person v ON v.id = x.vertretung
              WHERE $wo ORDER BY x.von DESC, lower(p.familienname), x.id", $jahr === null ? [] : [$jahr, $jahr]);
        // Urlaub (oder anderes) und AU zugleich: Hinweis für den, der es bereinigen kann
        $aktiv = array_filter($zeilen, fn (array $z) => in_array($z['zustand'], ['beantragt', 'genehmigt'], true));
        $ueberschnitten = [];
        foreach ($aktiv as $x) {
            foreach ($aktiv as $y) {
                if ($x['person'] === $y['person'] && $x['kennung'] !== 'au' && $y['kennung'] === 'au' && $x['von'] <= $y['bis'] && $x['bis'] >= $y['von']) {
                    $ueberschnitten[$x['id']] = true;
                }
            }
        }
        $erg = [];
        foreach ($zeilen as $z) {
            $person = (string) $z['person'];
            $selbst = $person === $s['ich'];
            $sichtbar = WebController::sichtbar($s, $person);
            $voll = $selbst || ($s['abw'] && $sichtbar);
            if (!$voll && (!$sichtbar || (!$z['genehmigung'] && $z['zustand'] !== 'genehmigt'))) {
                continue;
            }
            $mitArt = $voll || $z['genehmigung'];
            // der Ablauf vor der Genehmigung (10n): die Schritte des Antrags und der, der gerade wartet
            $schritte = json_decode((string) $z['schritte'], true);
            $wartet = $z['zustand'] === 'beantragt' ? (array_values(array_filter($schritte, fn (array $x) => $x['zustand'] === 'offen'))[0] ?? null) : null;
            $e = ['id' => $z['id'], 'person' => $person, 'name' => WebController::name($z), 'personalnummer' => $z['personalnummer'], 'ich' => $selbst,
                'art' => $mitArt ? $z['art'] : null, 'art_name' => $mitArt ? $z['art_name'] : 'abwesend', 'ist_au' => $voll ? $z['kennung'] === 'au' : null,
                'zaehlt_urlaub' => $mitArt ? (bool) $z['zaehlt_urlaub'] : null,
                'von' => $z['von'], 'bis' => $z['bis'], 'halber_tag' => (bool) $z['halber_tag'], 'tage' => $mitArt ? (float) $z['tage'] : null, 'tage_folgejahr' => $mitArt ? (float) $z['tage_folgejahr'] : null, 'tage_von_hand' => $mitArt ? (bool) $z['tage_von_hand'] : null,
                'bemerkung' => $mitArt ? $z['bemerkung'] : null, 'zustand' => $z['zustand'], 'grund' => $mitArt ? $z['grund'] : null,
                'angelegt_am' => $mitArt ? $z['angelegt_am'] : null, 'entschieden_am' => $mitArt ? $z['entschieden_am'] : null, 'au' => null,
                'ueberschneidet_au' => $voll ? isset($ueberschnitten[$z['id']]) : null,
                'vertretung' => $mitArt ? $z['vertretung'] : null,
                'vertretung_name' => $mitArt && $z['vertretung'] !== null ? WebController::name(['familienname' => $z['v_familienname'], 'vornamen' => $z['v_vornamen'], 'akademischer_titel' => $z['v_titel']]) : null,
                'vertretung_abwesend' => $mitArt ? (bool) $z['vertretung_abwesend'] : null,
                'schritte' => $mitArt ? array_map(fn (array $x) => ['nr' => $x['nr'], 'was' => $x['was'], 'wer' => $x['wer'], 'name' => $x['name'], 'zustand' => $x['zustand']], $schritte) : null,
                'wartet' => $mitArt && $wartet !== null ? ['was' => $wartet['was'], 'wer' => $wartet['wer'], 'name' => $wartet['name']] : null,
                'entscheidbar' => $z['zustand'] === 'beantragt' && $s['genehmigen'] && self::darfSchritt($s, $ich, $person, $wartet)];
            if ($z['kennung'] === 'au' && ($selbst || ($s['au'] && $voll))) {
                $e['au'] = ['festgestellt_am' => $z['festgestellt_am'], 'folge' => (bool) $z['folge'], 'arbeitsunfall' => (bool) $z['arbeitsunfall'], 'nachweis' => (bool) $z['nachweis'], 'nachweis_form' => $z['nachweis_form']];
            }
            if ($s['pflegen']) {
                $e['loeschen_ab'] = $z['loeschen_ab'];
            }
            $erg[] = $e;
        }
        return $erg;
    }

    /**
     * Darf der Aufrufer den Schritt entscheiden, der gerade wartet? Es ist eine Frage der Rollen (WF7, AW6): Das Personal
     * für alle; der Vorgesetzte für die ihm unmittelbar Unterstellten und nie über sich selbst; wer im Schritt gemeint ist
     * (Vorgesetzter des Vorgesetzten, benannte Person, Vertretung), für diesen Schritt. Ohne Schritt (Altbestand) wie bisher.
     *
     * @param array<string, mixed> $s
     * @param ?array<string, mixed> $schritt
     */
    public static function darfSchritt(array $s, ?string $ich, string $person, ?array $schritt): bool
    {
        if ($s['abw_pflegen']) {
            return true;
        }
        $wer = $schritt['wer'] ?? 'vorgesetzter';
        if ($wer === 'vorgesetzter') {
            return $person !== $ich && in_array($person, $s['team'], true);
        }
        return $wer !== 'personal' && $ich !== null && ($schritt['person'] ?? null) === $ich;
    }

    /** Der Schritt eines Antrags, der gerade wartet – oder null. @return ?array<string, mixed> */
    private static function wartenderSchritt(Anfrage $a, string $id): ?array
    {
        $z = $a->db()->fetchAssociative("SELECT nr, was, wer, person FROM personal.abwesenheit_schritt WHERE abwesenheit = ?::uuid AND zustand = 'offen' ORDER BY nr LIMIT 1", [$id]);
        return $z === false ? null : $z;
    }

    /**
     * Was auf die Person zukommt, weil ein Antrag sie nennt (10n, WF5): mitzeichnen, zur Kenntnis, vertreten. Sie sieht wer,
     * wann, die Art und die Bemerkung – nicht das Urlaubskonto und keine anderen Abwesenheiten.
     *
     * @return list<array<string, mixed>>
     */
    public static function fuerMich(Anfrage $a, string $ich): array
    {
        $zeilen = $a->db()->fetchAllAssociative(
            "SELECT x.id, p.familienname, p.vornamen, p.akademischer_titel, art.name AS art_name, x.von::text AS von, x.bis::text AS bis, x.halber_tag, x.bemerkung, x.zustand,
                    s.nr, s.was, s.zustand AS schritt, (x.vertretung = ?) AS vertrete,
                    (x.zustand = 'beantragt' AND s.zustand = 'offen' AND s.was <> 'kenntnis'
                     AND NOT EXISTS (SELECT 1 FROM personal.abwesenheit_schritt f WHERE f.abwesenheit = x.id AND f.zustand = 'offen' AND f.nr < s.nr)) AS offen,
                    EXISTS (SELECT 1 FROM personal.abwesenheit y WHERE y.person = ? AND y.zustand IN ('beantragt', 'genehmigt') AND y.von <= x.bis AND y.bis >= x.von) AS selbst_abwesend
               FROM personal.abwesenheit_schritt s JOIN personal.abwesenheit x ON x.id = s.abwesenheit
               JOIN personal.person p ON p.id = x.person JOIN personal.abwesenheitsart art ON art.id = x.art
              WHERE s.person = ? AND s.wer IN ('vertretung', 'person') AND x.person <> ? AND x.zustand IN ('beantragt', 'genehmigt') AND x.bis >= current_date
                AND (s.was <> 'kenntnis' OR s.zustand = 'erledigt')
              ORDER BY x.von, x.id, s.nr", [$ich, $ich, $ich, $ich]);
        // was das Personal mit einer Vertretung eingetragen hat, hat keine Schritte – die Vertretung erfährt es trotzdem
        $ohne = $a->db()->fetchAllAssociative(
            "SELECT x.id, p.familienname, p.vornamen, p.akademischer_titel, art.name AS art_name, x.von::text AS von, x.bis::text AS bis, x.halber_tag, x.bemerkung, x.zustand,
                    0 AS nr, 'vertretung' AS was, 'erledigt' AS schritt, true AS vertrete, false AS offen,
                    EXISTS (SELECT 1 FROM personal.abwesenheit y WHERE y.person = ? AND y.zustand IN ('beantragt', 'genehmigt') AND y.von <= x.bis AND y.bis >= x.von) AS selbst_abwesend
               FROM personal.abwesenheit x JOIN personal.person p ON p.id = x.person JOIN personal.abwesenheitsart art ON art.id = x.art
              WHERE x.vertretung = ? AND x.zustand = 'genehmigt' AND x.bis >= current_date
                AND NOT EXISTS (SELECT 1 FROM personal.abwesenheit_schritt s WHERE s.abwesenheit = x.id AND s.person = ? AND s.wer = 'vertretung')", [$ich, $ich, $ich]);
        $zeilen = array_merge($zeilen, $ohne);
        usort($zeilen, fn (array $x, array $y) => [$x['von'], $x['id'], $x['nr']] <=> [$y['von'], $y['id'], $y['nr']]);
        return array_map(fn (array $z) => ['id' => $z['id'], 'name' => WebController::name($z), 'art_name' => $z['art_name'], 'von' => $z['von'], 'bis' => $z['bis'],
            'halber_tag' => (bool) $z['halber_tag'], 'bemerkung' => $z['bemerkung'], 'zustand' => $z['zustand'], 'was' => $z['was'], 'schritt' => $z['schritt'],
            'vertrete' => (bool) $z['vertrete'], 'offen' => (bool) $z['offen'], 'selbst_abwesend' => (bool) $z['selbst_abwesend']], $zeilen);
    }

    /** Wer als Vertretung wählbar ist (WF2): alle Personen, die nicht ausgeschieden sind – nur Name, sonst nichts. @return list<array{id: string, name: string}> */
    public static function vertretungen(Anfrage $a, ?string $ausser): array
    {
        return array_map(fn (array $z) => ['id' => $z['id'], 'name' => WebController::name($z)],
            $a->db()->fetchAllAssociative("SELECT id, familienname, vornamen, akademischer_titel FROM personal.person
                WHERE (austritt IS NULL OR austritt >= current_date) AND id IS DISTINCT FROM ?::uuid ORDER BY lower(familienname), lower(coalesce(vornamen, '')), id", [$ausser]));
    }

    /** Seite „Abwesenheiten“: Liste, Konten und alles zur Auswahl. */
    public function stand(Anfrage $a): Response
    {
        $s = WebController::sicht($a);
        $jahr = (int) ($a->http->query->get('jahr') ?? date('Y'));
        if ($jahr < 2000 || $jahr > 2100) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'jahr', 'meldung' => 'Jahr von 2000 bis 2100']]);
        }
        $db = $a->db();
        $personen = array_values(array_filter(
            $db->fetchAllAssociative("SELECT id, familienname, vornamen, akademischer_titel, personalnummer, (austritt IS NOT NULL AND austritt < current_date) AS ausgeschieden,
                                             personal._arbeitsort_von(id) AS arbeitsort
                                        FROM personal.person ORDER BY lower(familienname), lower(coalesce(vornamen, '')), id"),
            fn (array $z) => WebController::sichtbar($s, (string) $z['id'])));
        $grenzen = $db->fetchAssociative('SELECT extract(year FROM min(von))::integer AS von, extract(year FROM max(bis))::integer AS bis FROM personal.abwesenheit');
        return Antwort::json(['darf' => WebController::darf($a->kontext()), 'ich' => $s['ich'], 'heute' => date('Y-m-d'), 'jahr' => $jahr,
            'jahre' => range(min($grenzen['von'] ?? $jahr, $jahr, (int) date('Y')) , max($grenzen['bis'] ?? $jahr, $jahr, (int) date('Y') + 1)),
            'abwesenheiten' => self::liste($a, $s, $jahr),
            'personen' => array_map(fn (array $z) => ['id' => $z['id'], 'name' => WebController::name($z), 'personalnummer' => $z['personalnummer'], 'ausgeschieden' => (bool) $z['ausgeschieden'], 'arbeitsort' => $z['arbeitsort']], $personen),
            'konten' => array_map(fn (array $z) => self::konto($a, (string) $z['id'], $jahr), array_values(array_filter($personen, fn (array $z) => !$z['ausgeschieden']))),
            'arten' => self::arten($a), 'freie_tage' => self::freieTage($a), 'arbeitsorte' => self::arbeitsorte($a), 'feiertage' => self::feiertage($a, $jahr),
            'regelwerk' => self::regelwerk($a), 'urlaubsregeln' => self::urlaubsregeln($a)]);
    }

    /** Zähler am Menüpunkt: Anträge, die der Aufrufer entscheiden kann. */
    public function zaehler(Anfrage $a): Response
    {
        return Antwort::json(['anzahl' => count(array_filter(self::liste($a, WebController::sicht($a), null), fn (array $x) => $x['entscheidbar']))]);
    }

    /** Zähler an „Meine Daten“: Anträge, die auf die Mitzeichnung des Aufrufers warten (10n). */
    public function zaehlerMeine(Anfrage $a): Response
    {
        $ich = WebController::ich($a);
        return Antwort::json(['anzahl' => $ich === null ? 0 : count(array_filter(self::fuerMich($a, $ich), fn (array $x) => $x['offen']))]);
    }

    // --- Schreiben --------------------------------------------------------------------------------------------

    /**
     * Prüft die Angaben einer neuen Abwesenheit.
     *
     * @param array<string, mixed> $j
     * @return array<string, mixed> für personal.abwesenheit_anlegen
     */
    private static function eingabe(array $j, bool $mitPerson): array
    {
        WebController::nurFelder($j, array_merge($mitPerson ? ['person'] : [], ['art', 'von', 'bis', 'halber_tag', 'bemerkung', 'au', 'vertretung']));
        $fehler = [];
        $f = WebController::felder($j, ($mitPerson ? ['person' => ['uuid']] : []) + ['art' => ['uuid'], 'von' => ['pflichtdatum'], 'bis' => ['pflichtdatum'], 'bemerkung' => ['text', 200], 'vertretung' => ['uuid']], $fehler);
        foreach ($mitPerson ? ['person', 'art'] : ['art'] as $feld) {
            if (($f[$feld] ?? null) === null && !in_array($feld, array_column($fehler, 'feld'), true)) {
                $fehler[] = ['feld' => $feld, 'meldung' => 'Fehlt'];
            }
        }
        if (isset($j['halber_tag']) && !is_bool($j['halber_tag'])) {
            $fehler[] = ['feld' => 'halber_tag', 'meldung' => 'Ja oder nein erwartet'];
        }
        if (($f['von'] ?? null) !== null && ($f['bis'] ?? null) !== null && $f['bis'] < $f['von'] && !in_array('bis', array_column($fehler, 'feld'), true)) {
            $fehler[] = ['feld' => 'bis', 'meldung' => 'Das Ende liegt vor dem Beginn'];
        }
        $au = null;
        if (($j['au'] ?? null) !== null) {
            if (!is_array($j['au'])) {
                $fehler[] = ['feld' => 'au', 'meldung' => 'Objekt erwartet'];
            } else {
                // genau die Angaben des eAU-Verfahrens – ein Feld „diagnose“ oder Ähnliches wird abgewiesen
                WebController::nurFelder($j['au'], ['festgestellt_am', 'folge', 'arbeitsunfall']);
                $au = WebController::felder($j['au'], ['festgestellt_am' => ['datum']], $fehler, 'au.');
                foreach (['folge', 'arbeitsunfall'] as $b) {
                    if (isset($j['au'][$b]) && !is_bool($j['au'][$b])) {
                        $fehler[] = ['feld' => "au.$b", 'meldung' => 'Ja oder nein erwartet'];
                    }
                    $au[$b] = ($j['au'][$b] ?? false) === true;
                }
            }
        }
        WebController::pruefen($fehler);
        return $f + ['halber_tag' => ($j['halber_tag'] ?? false) === true] + ($au === null ? [] : ['au' => $au]);
    }

    /** Das Personal trägt für eine Person ein – die Abwesenheit gilt ohne Antrag. */
    public function eintragen(Anfrage $a): Response
    {
        $f = self::eingabe($a->json(), true);
        if (isset($f['au']) && !$a->kontext()->darf('personal.au.sehen')) {
            throw new Problem(403, 'recht.fehlt', 'Recht fehlt: personal.au.sehen');
        }
        $person = $f['person'];
        unset($f['person']);
        $id = (string) $a->db()->fetchOne('SELECT personal.abwesenheit_anlegen(?, ?::uuid, ?::jsonb, true)', [$a->kontext()->subjekt, $person, json_encode($f, JSON_UNESCAPED_UNICODE)]);
        return Antwort::json(['id' => $id], 201);
    }

    /** Selfservice: beantragen bzw. – bei einer Art ohne Genehmigung, der AU – melden. */
    public function beantragen(Anfrage $a): Response
    {
        $ich = WebController::ich($a);
        if ($ich === null) {
            throw new Problem(409, 'personal.nicht_verknuepft', 'Dein Zugang ist noch mit keiner Person verknüpft');
        }
        $f = self::eingabe($a->json(), false);
        if ($a->db()->fetchOne('SELECT 1 FROM personal.abwesenheitsart WHERE id = ?::uuid AND selbst AND NOT stillgelegt', [$f['art']]) === false) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'art', 'meldung' => 'Diese Art trägt das Personal ein']]);
        }
        $id = (string) $a->db()->fetchOne('SELECT personal.abwesenheit_anlegen(?, ?::uuid, ?::jsonb, false)', [$a->kontext()->subjekt, $ich, json_encode($f, JSON_UNESCAPED_UNICODE)]);
        return Antwort::json(['id' => $id, 'zustand' => (string) $a->db()->fetchOne('SELECT zustand FROM personal.abwesenheit WHERE id = ?', [$id])], 201);
    }

    /** Selfservice: einen eigenen Antrag zurücknehmen; Genehmigtes nur, solange es nicht begonnen hat. */
    public function selbstZuruecknehmen(Anfrage $a): Response
    {
        $a->json(false);
        $ich = WebController::ich($a);
        if ($ich === null || $a->db()->fetchOne('SELECT 1 FROM personal.abwesenheit WHERE id = ?::uuid AND person = ?::uuid', [$a->param('id'), $ich]) === false) {
            throw Problem::nichtGefunden('Abwesenheit unbekannt');   // „nicht deine“ sieht aus wie „gibt es nicht“
        }
        $a->db()->executeStatement('SELECT personal.abwesenheit_zuruecknehmen(?, ?::uuid, NULL, true)', [$a->kontext()->subjekt, $a->param('id')]);
        return Antwort::leer();
    }

    /** Antrag genehmigen oder ablehnen: der Vorgesetzte für sein Team, das Personal für alle (PE18). */
    public function entscheiden(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['genehmigen', 'grund']);
        $fehler = [];
        $f = WebController::felder($j, ['grund' => ['text', 200]], $fehler);
        if (!is_bool($j['genehmigen'] ?? null)) {
            $fehler[] = ['feld' => 'genehmigen', 'meldung' => 'Ja oder nein erwartet'];
        } elseif (!$j['genehmigen'] && ($f['grund'] ?? null) === null) {
            $fehler[] = ['feld' => 'grund', 'meldung' => 'Eine Ablehnung braucht einen Grund'];
        }
        WebController::pruefen($fehler);
        $s = WebController::sicht($a);
        $person = $a->db()->fetchOne('SELECT person FROM personal.abwesenheit WHERE id = ?::uuid', [$a->param('id')]);
        // wer nicht das Personal führt, entscheidet nur den Schritt, der ihn meint: als Vorgesetzter für die unmittelbar Unterstellten und nie über sich selbst (10n)
        if ($person === false || !self::darfSchritt($s, WebController::ich($a), (string) $person, self::wartenderSchritt($a, $a->param('id')))) {
            throw Problem::nichtGefunden('Abwesenheit unbekannt');
        }
        $a->db()->executeStatement('SELECT personal.abwesenheit_entscheiden(?, ?::uuid, ?::boolean, ?)', [$a->kontext()->subjekt, $a->param('id'), $j['genehmigen'] ? 'true' : 'false', $f['grund']]);
        return Antwort::leer();
    }

    /**
     * Selfservice: mitzeichnen oder ablehnen, wenn der Schritt, der gerade wartet, eine Mitzeichnung ist und den Aufrufer
     * meint – als Vertretung oder als benannte Person (10n). Genehmigen bleibt bei der Rolle, die genehmigt.
     */
    public function mitzeichnen(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['mitzeichnen', 'grund']);
        $fehler = [];
        $f = WebController::felder($j, ['grund' => ['text', 200]], $fehler);
        if (!is_bool($j['mitzeichnen'] ?? null)) {
            $fehler[] = ['feld' => 'mitzeichnen', 'meldung' => 'Ja oder nein erwartet'];
        } elseif (!$j['mitzeichnen'] && ($f['grund'] ?? null) === null) {
            $fehler[] = ['feld' => 'grund', 'meldung' => 'Eine Ablehnung braucht einen Grund'];
        }
        WebController::pruefen($fehler);
        $ich = WebController::ich($a);
        $schritt = self::wartenderSchritt($a, $a->param('id'));
        $offen = $a->db()->fetchOne("SELECT 1 FROM personal.abwesenheit WHERE id = ?::uuid AND zustand = 'beantragt'", [$a->param('id')]);
        if ($ich === null || $offen === false || $schritt === null || $schritt['was'] !== 'mitzeichnen' || !in_array($schritt['wer'], ['vertretung', 'person'], true) || $schritt['person'] !== $ich) {
            throw Problem::nichtGefunden('Abwesenheit unbekannt');   // „nicht deine“ sieht aus wie „gibt es nicht“
        }
        $a->db()->executeStatement('SELECT personal.abwesenheit_entscheiden(?, ?::uuid, ?::boolean, ?)', [$a->kontext()->subjekt, $a->param('id'), $j['mitzeichnen'] ? 'true' : 'false', $f['grund']]);
        return Antwort::leer();
    }

    public function zuruecknehmen(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['grund']);
        $fehler = [];
        $f = WebController::felder($j, ['grund' => ['pflichttext', 200]], $fehler);
        WebController::pruefen($fehler);
        $a->db()->executeStatement('SELECT personal.abwesenheit_zuruecknehmen(?, ?::uuid, ?, false)', [$a->kontext()->subjekt, $a->param('id'), $f['grund']]);
        return Antwort::leer();
    }

    public function endeSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['bis']);
        $fehler = [];
        $f = WebController::felder($j, ['bis' => ['pflichtdatum']], $fehler);
        WebController::pruefen($fehler);
        $a->db()->executeStatement('SELECT personal.abwesenheit_ende_setzen(?, ?::uuid, ?::date)', [$a->kontext()->subjekt, $a->param('id'), $f['bis']]);
        return Antwort::leer();
    }

    /** Vermerken, dass der Nachweis einer AU vorliegt (oder doch nicht) – kumero ruft nichts ab. */
    public function nachweis(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['liegt_vor', 'form']);
        if (!is_bool($j['liegt_vor'] ?? null)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'liegt_vor', 'meldung' => 'Ja oder nein erwartet']]);
        }
        if (!$a->kontext()->darf('personal.au.sehen')) {
            throw new Problem(403, 'recht.fehlt', 'Recht fehlt: personal.au.sehen');
        }
        // die Form gehört zum Nachweis (F155): elektronisch (eAU) oder Papier
        if ($j['liegt_vor'] && !in_array($j['form'] ?? null, ['elektronisch', 'papier'], true)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'form', 'meldung' => 'elektronisch oder papier']]);
        }
        $a->db()->executeStatement('SELECT personal.au_nachweis_setzen(?, ?::uuid, ?::boolean, ?)', [$a->kontext()->subjekt, $a->param('id'), $j['liegt_vor'] ? 'true' : 'false', $j['liegt_vor'] ? $j['form'] : null]);
        return Antwort::leer();
    }

    public function artSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['name', 'bezahlt', 'zaehlt_urlaub', 'genehmigung', 'selbst', 'stillgelegt', 'ablauf']);
        $fehler = [];
        $f = WebController::felder($j, ['name' => ['pflichttext', 60]], $fehler);
        foreach (['bezahlt', 'zaehlt_urlaub', 'genehmigung', 'selbst', 'stillgelegt'] as $b) {
            if (isset($j[$b]) && !is_bool($j[$b])) {
                $fehler[] = ['feld' => $b, 'meldung' => 'Ja oder nein erwartet'];
            } elseif (isset($j[$b])) {
                $f[$b] = $j[$b];
            }
        }
        // der Ablauf vor der Genehmigung (10n): eine Liste von Schritten, oder null für die Vorgabe; den Inhalt prüft die Datenbank
        if (array_key_exists('ablauf', $j)) {
            if ($j['ablauf'] !== null && (!is_array($j['ablauf']) || !array_is_list($j['ablauf']))) {
                $fehler[] = ['feld' => 'ablauf', 'meldung' => 'Liste der Schritte oder null erwartet'];
            } else {
                foreach ($j['ablauf'] ?? [] as $schritt) {
                    if (!is_array($schritt) || array_diff(array_keys($schritt), ['was', 'wer', 'wann', 'person', 'tage']) !== []) {
                        $fehler[] = ['feld' => 'ablauf', 'meldung' => 'Ein Schritt nennt was, wer, wann – und wo es dazugehört person oder tage'];
                        break;
                    }
                }
                $f['ablauf'] = $j['ablauf'];
            }
        }
        WebController::pruefen($fehler);
        $id = $a->param['id'] ?? null;
        $neu = (string) $a->db()->fetchOne('SELECT personal.abwesenheitsart_setzen(?, ?::uuid, ?::jsonb)', [$a->kontext()->subjekt, $id, json_encode($f, JSON_UNESCAPED_UNICODE)]);
        return $id === null ? Antwort::json(['id' => $neu], 201) : Antwort::leer();
    }

    /** Freien Tag des Betriebs setzen; `name: null` entfernt ihn. */
    public function freierTag(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['name']);
        $fehler = [];
        $f = WebController::felder($j + ['datum' => $a->param('datum')], ['datum' => ['pflichtdatum'], 'name' => ['text', 60]], $fehler);
        WebController::pruefen($fehler);
        $a->db()->executeStatement('SELECT personal.freier_tag_setzen(?, ?::date, ?)', [$a->kontext()->subjekt, $f['datum'], $f['name']]);
        return Antwort::leer();
    }

    /** Urlaubskonto einer Person in einem Jahr festsetzen: Anspruch, Übertrag, Verfallenes – null = nach Vertrag bzw. Regel. */
    public function kontoSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['anspruch', 'uebertrag', 'verfallen']);
        $fehler = [];
        foreach (['anspruch' => '/^\d{1,2}(\.[05])?$/', 'uebertrag' => '/^-?\d{1,2}(\.[05])?$/', 'verfallen' => '/^\d{1,2}(\.[05])?$/'] as $feld => $muster) {
            $w = $j[$feld] ?? null;
            if ($w !== null && (!is_string($w) || !preg_match($muster, $w))) {
                $fehler[] = ['feld' => $feld, 'meldung' => 'Tage als Zahl, halbe Tage mit .5'];
            }
        }
        if (!preg_match('/^\d{4}$/', $a->param('jahr'))) {
            $fehler[] = ['feld' => 'jahr', 'meldung' => 'Jahr erwartet'];
        }
        WebController::pruefen($fehler);
        $a->db()->executeStatement('SELECT personal.urlaubskonto_setzen(?, ?::uuid, ?::integer, ?::numeric, ?::numeric, ?::numeric)',
            [$a->kontext()->subjekt, $a->param('person'), (int) $a->param('jahr'), $j['anspruch'] ?? null, $j['uebertrag'] ?? null, $j['verfallen'] ?? null]);
        return Antwort::leer();
    }

    /** Arbeitstage einer Abwesenheit berichtigen: `tage: null` rechnet neu, sonst gilt der Wert von Hand (FT5). */
    public function tageSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['tage', 'tage_folgejahr']);
        $fehler = [];
        foreach (['tage', 'tage_folgejahr'] as $feld) {
            $w = $j[$feld] ?? null;
            if ($w !== null && (!is_string($w) || !preg_match('/^\d{1,4}(\.[05])?$/', $w))) {
                $fehler[] = ['feld' => $feld, 'meldung' => 'Tage als Zahl, halbe Tage mit .5'];
            }
        }
        WebController::pruefen($fehler);
        $a->db()->executeStatement('SELECT personal.abwesenheit_tage_setzen(?, ?::uuid, ?::numeric, ?::numeric)',
            [$a->kontext()->subjekt, $a->param('id'), $j['tage'] ?? null, $j['tage_folgejahr'] ?? null]);
        return Antwort::leer();
    }

    /** Arbeitsort anlegen oder ändern: Bundesland und die Feiertage, die dort gelten. */
    public function arbeitsortSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['name', 'land', 'feiertage', 'stillgelegt', 'standard']);
        $fehler = [];
        $f = WebController::felder($j, ['name' => ['pflichttext', 60], 'land' => ['pflichttext', 2]], $fehler);
        foreach (['stillgelegt', 'standard'] as $b) {
            if (isset($j[$b]) && !is_bool($j[$b])) {
                $fehler[] = ['feld' => $b, 'meldung' => 'Ja oder nein erwartet'];
            } elseif (isset($j[$b])) {
                $f[$b] = $j[$b];
            }
        }
        if (isset($j['feiertage'])) {
            if (!is_array($j['feiertage']) || !array_is_list($j['feiertage']) || array_filter($j['feiertage'], fn ($x) => !is_string($x)) !== []) {
                $fehler[] = ['feld' => 'feiertage', 'meldung' => 'Liste von Kennungen erwartet'];
            } else {
                $f['feiertage'] = $j['feiertage'];
            }
        }
        WebController::pruefen($fehler);
        $id = $a->param['id'] ?? null;
        $neu = (string) $a->db()->fetchOne('SELECT personal.arbeitsort_setzen(?, ?::uuid, ?::jsonb)', [$a->kontext()->subjekt, $id, json_encode($f, JSON_UNESCAPED_UNICODE)]);
        return $id === null ? Antwort::json(['id' => $neu], 201) : Antwort::leer();
    }

    /** @param array<string, mixed> $j Angaben eines Feiertags aus Oberfläche oder Steuerdatei @return array<string, mixed> */
    private static function feiertagPruefen(array $j): array
    {
        WebController::nurFelder($j, ['kennung', 'name', 'art', 'monat', 'tag', 'abstand', 'bundesweit', 'laender', 'teile', 'reihenfolge']);
        $fehler = [];
        $f = WebController::felder($j, ['name' => ['pflichttext', 60], 'art' => ['pflichttext', 10]], $fehler);
        if (isset($f['art']) && !in_array($f['art'], ['fest', 'ostern', 'busstag'], true)) {
            $fehler[] = ['feld' => 'art', 'meldung' => 'festes Datum, beweglich (ab Ostern) oder Buß- und Bettag'];
        }
        foreach (['monat' => [1, 12], 'tag' => [1, 31], 'abstand' => [-100, 100], 'reihenfolge' => [0, 100000]] as $feld => [$min, $max]) {
            $w = $j[$feld] ?? null;
            if ($w !== null && (!is_int($w) || $w < $min || $w > $max)) {
                $fehler[] = ['feld' => $feld, 'meldung' => "Ganze Zahl von $min bis $max"];
            } elseif ($w !== null) {
                $f[$feld] = $w;
            }
        }
        if (($f['art'] ?? null) === 'fest' && (!isset($f['monat']) || !isset($f['tag']))) {
            $fehler[] = ['feld' => 'tag', 'meldung' => 'Ein festes Datum braucht Tag und Monat'];
        }
        if (($f['art'] ?? null) === 'ostern' && !isset($f['abstand'])) {
            $fehler[] = ['feld' => 'abstand', 'meldung' => 'Ein beweglicher Feiertag braucht den Abstand zum Ostersonntag in Tagen'];
        }
        foreach (['laender', 'teile'] as $feld) {
            $w = $j[$feld] ?? [];
            if (!is_array($w) || !array_is_list($w) || array_filter($w, fn ($x) => !is_string($x) || !preg_match('/^[A-Z]{2}$/', $x)) !== []) {
                $fehler[] = ['feld' => $feld, 'meldung' => 'Kürzel der Bundesländer erwartet (BW, BY, …)'];
            } else {
                $f[$feld] = $w;
            }
        }
        if (isset($j['bundesweit']) && !is_bool($j['bundesweit'])) {
            $fehler[] = ['feld' => 'bundesweit', 'meldung' => 'Ja oder nein erwartet'];
        }
        $f['bundesweit'] = ($j['bundesweit'] ?? false) === true;
        WebController::pruefen($fehler);
        return $f;
    }

    /** Einen Feiertag des Regelwerks ändern, einen eigenen anlegen oder (leerer Rumpf `{"entfernen": true}`) entfernen. */
    public function feiertagSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        $kennung = $a->param['feiertag'] ?? null;   // nicht „kennung“: Der Kern lässt in Pfadteilen dieses Namens keinen Unterstrich zu
        if ($kennung !== null && !preg_match('/^[a-z][a-z0-9_]{1,39}$/', $kennung)) {
            throw Problem::nichtGefunden('Feiertag unbekannt');
        }
        if ($kennung !== null && ($j['entfernen'] ?? false) === true) {
            WebController::nurFelder($j, ['entfernen']);
            $a->db()->executeStatement('SELECT personal.feiertag_setzen(?, ?, NULL)', [$a->kontext()->subjekt, $kennung]);
            return Antwort::leer();
        }
        $f = self::feiertagPruefen($j);
        unset($f['kennung']);
        $neu = (string) $a->db()->fetchOne('SELECT personal.feiertag_setzen(?, ?, ?::jsonb)', [$a->kontext()->subjekt, $kennung, json_encode($f, JSON_UNESCAPED_UNICODE)]);
        return $kennung === null ? Antwort::json(['kennung' => $neu], 201) : Antwort::leer();
    }

    /**
     * Die Vorgabe wieder einlesen: die Steuerdatei `regelwerk/feiertage-de.json` des Moduls. Ihre Einträge ersetzen die
     * gleichnamigen des Mandanten; eigene Feiertage bleiben, entfernte Feiertage der Vorgabe kommen zurück.
     */
    public function feiertageVorgabe(Anfrage $a): Response
    {
        $a->json(false);
        $datei = dirname(__DIR__) . '/regelwerk/feiertage-de.json';
        $d = is_file($datei) ? json_decode((string) file_get_contents($datei), true) : null;
        if (!is_array($d) || !is_array($d['feiertage'] ?? null)) {
            throw new Problem(500, 'personal.steuerdatei', 'Die Steuerdatei der Feiertage fehlt oder ist nicht lesbar');
        }
        foreach ($d['feiertage'] as $e) {
            $f = self::feiertagPruefen($e);
            $a->db()->executeStatement('SELECT personal.feiertag_setzen(?, ?, ?::jsonb)', [$a->kontext()->subjekt, (string) $e['kennung'], json_encode(array_diff_key($f, ['kennung' => 1]), JSON_UNESCAPED_UNICODE)]);
        }
        return Antwort::json(['stand' => $d['stand'] ?? null, 'anzahl' => count($d['feiertage'])]);
    }

    /** Urlaubsregel des Mandanten: je „gilt ab Jahr“ eine; `{"entfernen": true}` nimmt sie zurück. */
    public function urlaubsregelSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        if (!preg_match('/^\d{4}$/', $a->param('jahr'))) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'jahr', 'meldung' => 'Jahr erwartet']]);
        }
        if (($j['entfernen'] ?? false) === true) {
            WebController::nurFelder($j, ['entfernen']);
            $a->db()->executeStatement('SELECT personal.urlaubsregel_setzen(?, ?::integer, NULL)', [$a->kontext()->subjekt, (int) $a->param('jahr')]);
            return Antwort::leer();
        }
        WebController::nurFelder($j, ['anteilig', 'rundung', 'uebertrag', 'uebertrag_hoechstens', 'verfall', 'verfall_tag', 'verfall_monat']);
        $fehler = [];
        foreach (['anteilig' => ['aus', 'gesetz', 'zwoelftel'], 'rundung' => ['gesetz', 'halbe', 'ganze', 'kaufmaennisch', 'keine'], 'uebertrag' => ['nie', 'rest'], 'verfall' => ['nie', 'bestaetigt', 'selbst']] as $feld => $werte) {
            if (!in_array($j[$feld] ?? null, $werte, true)) {
                $fehler[] = ['feld' => $feld, 'meldung' => 'Erlaubt: ' . implode(', ', $werte)];
            }
        }
        $h = $j['uebertrag_hoechstens'] ?? null;
        if ($h !== null && (!is_string($h) || !preg_match('/^\d{1,2}(\.[05])?$/', $h))) {
            $fehler[] = ['feld' => 'uebertrag_hoechstens', 'meldung' => 'Tage als Zahl, halbe Tage mit .5'];
        }
        $tag = $j['verfall_tag'] ?? 31;
        $monat = $j['verfall_monat'] ?? 3;
        if (!is_int($tag) || !is_int($monat) || !checkdate($monat, $tag, 2001)) {
            $fehler[] = ['feld' => 'verfall_tag', 'meldung' => 'Tag und Monat ergeben kein Datum'];
        }
        WebController::pruefen($fehler);
        $a->db()->executeStatement('SELECT personal.urlaubsregel_setzen(?, ?::integer, ?::jsonb)', [$a->kontext()->subjekt, (int) $a->param('jahr'),
            json_encode(['anteilig' => $j['anteilig'], 'rundung' => $j['rundung'], 'uebertrag' => $j['uebertrag'], 'uebertrag_hoechstens' => $h, 'verfall' => $j['verfall'], 'verfall_tag' => $tag, 'verfall_monat' => $monat])]);
        return Antwort::leer();
    }
}
