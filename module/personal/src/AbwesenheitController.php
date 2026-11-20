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
            'zaehlt_urlaub' => (bool) $z['zaehlt_urlaub'], 'genehmigung' => (bool) $z['genehmigung'], 'selbst' => (bool) $z['selbst'], 'stillgelegt' => (bool) $z['stillgelegt']],
            $a->db()->fetchAllAssociative('SELECT id, kennung, name, bezahlt, zaehlt_urlaub, genehmigung, selbst, stillgelegt FROM personal.abwesenheitsart'
                . ($nurWaehlbare ? ' WHERE selbst AND NOT stillgelegt' : '') . " ORDER BY stillgelegt, (kennung = 'urlaub') DESC NULLS LAST, (kennung = 'au') DESC NULLS LAST, lower(name)"));
    }

    /** @return list<array{datum: string, name: string}> */
    public static function freieTage(Anfrage $a): array
    {
        return $a->db()->fetchAllAssociative("SELECT datum::text AS datum, name FROM personal.freier_tag WHERE datum >= date_trunc('year', current_date) - interval '1 year' ORDER BY datum");
    }

    /** @return array<string, mixed> */
    public static function konto(Anfrage $a, string $person, int $jahr): array
    {
        $k = $a->db()->fetchAssociative('SELECT * FROM personal.urlaubskonto_stand(?::uuid, ?::integer)', [$person, $jahr]);
        $z = array_map('floatval', array_intersect_key($k, array_flip(['anspruch', 'uebertrag', 'beantragt', 'genehmigt', 'genommen'])));
        $rest = $z['anspruch'] + $z['uebertrag'] - $z['genommen'] - $z['genehmigt'];
        return ['person' => $person, 'jahr' => $jahr, 'festgesetzt' => (bool) $k['festgesetzt']] + $z + ['rest' => $rest, 'rest_nach_antraegen' => $rest - $z['beantragt']];
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
            "SELECT x.id, x.person, p.familienname, p.vornamen, p.personalnummer, x.art, art.name AS art_name, art.kennung, art.genehmigung, art.zaehlt_urlaub,
                    x.von::text AS von, x.bis::text AS bis, x.halber_tag, x.tage, x.bemerkung, x.zustand, x.grund, x.loeschen_ab::text AS loeschen_ab,
                    " . sprintf(self::ZEIT, 'x.angelegt_am') . ' AS angelegt_am, ' . sprintf(self::ZEIT, 'x.entschieden_am') . " AS entschieden_am,
                    u.festgestellt_am::text AS festgestellt_am, u.folge, u.arbeitsunfall, u.nachweis
               FROM personal.abwesenheit x JOIN personal.person p ON p.id = x.person JOIN personal.abwesenheitsart art ON art.id = x.art
               LEFT JOIN personal.au u ON u.abwesenheit = x.id
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
            $e = ['id' => $z['id'], 'person' => $person, 'name' => WebController::name($z), 'personalnummer' => $z['personalnummer'], 'ich' => $selbst,
                'art' => $mitArt ? $z['art'] : null, 'art_name' => $mitArt ? $z['art_name'] : 'abwesend', 'ist_au' => $voll ? $z['kennung'] === 'au' : null,
                'zaehlt_urlaub' => $mitArt ? (bool) $z['zaehlt_urlaub'] : null,
                'von' => $z['von'], 'bis' => $z['bis'], 'halber_tag' => (bool) $z['halber_tag'], 'tage' => $mitArt ? (float) $z['tage'] : null,
                'bemerkung' => $mitArt ? $z['bemerkung'] : null, 'zustand' => $z['zustand'], 'grund' => $mitArt ? $z['grund'] : null,
                'angelegt_am' => $mitArt ? $z['angelegt_am'] : null, 'entschieden_am' => $mitArt ? $z['entschieden_am'] : null, 'au' => null,
                'ueberschneidet_au' => $voll ? isset($ueberschnitten[$z['id']]) : null,
                'entscheidbar' => $z['zustand'] === 'beantragt' && $s['genehmigen'] && ($s['abw_pflegen'] || ($person !== $ich && in_array($person, $s['team'], true)))];
            if ($z['kennung'] === 'au' && ($selbst || ($s['au'] && $voll))) {
                $e['au'] = ['festgestellt_am' => $z['festgestellt_am'], 'folge' => (bool) $z['folge'], 'arbeitsunfall' => (bool) $z['arbeitsunfall'], 'nachweis' => (bool) $z['nachweis']];
            }
            if ($s['pflegen']) {
                $e['loeschen_ab'] = $z['loeschen_ab'];
            }
            $erg[] = $e;
        }
        return $erg;
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
            $db->fetchAllAssociative("SELECT id, familienname, vornamen, personalnummer, (austritt IS NOT NULL AND austritt < current_date) AS ausgeschieden
                                        FROM personal.person ORDER BY lower(familienname), lower(coalesce(vornamen, '')), id"),
            fn (array $z) => WebController::sichtbar($s, (string) $z['id'])));
        $grenzen = $db->fetchAssociative('SELECT extract(year FROM min(von))::integer AS von, extract(year FROM max(bis))::integer AS bis FROM personal.abwesenheit');
        return Antwort::json(['darf' => WebController::darf($a->kontext()), 'ich' => $s['ich'], 'heute' => date('Y-m-d'), 'jahr' => $jahr,
            'jahre' => range(min($grenzen['von'] ?? $jahr, $jahr, (int) date('Y')) , max($grenzen['bis'] ?? $jahr, $jahr, (int) date('Y') + 1)),
            'abwesenheiten' => self::liste($a, $s, $jahr),
            'personen' => array_map(fn (array $z) => ['id' => $z['id'], 'name' => WebController::name($z), 'personalnummer' => $z['personalnummer'], 'ausgeschieden' => (bool) $z['ausgeschieden']], $personen),
            'konten' => array_map(fn (array $z) => self::konto($a, (string) $z['id'], $jahr), array_values(array_filter($personen, fn (array $z) => !$z['ausgeschieden']))),
            'arten' => self::arten($a), 'freie_tage' => self::freieTage($a)]);
    }

    /** Zähler am Menüpunkt: Anträge, die der Aufrufer entscheiden kann. */
    public function zaehler(Anfrage $a): Response
    {
        return Antwort::json(['anzahl' => count(array_filter(self::liste($a, WebController::sicht($a), null), fn (array $x) => $x['entscheidbar']))]);
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
        WebController::nurFelder($j, array_merge($mitPerson ? ['person'] : [], ['art', 'von', 'bis', 'halber_tag', 'bemerkung', 'au']));
        $fehler = [];
        $f = WebController::felder($j, ($mitPerson ? ['person' => ['uuid']] : []) + ['art' => ['uuid'], 'von' => ['pflichtdatum'], 'bis' => ['pflichtdatum'], 'bemerkung' => ['text', 200]], $fehler);
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
        // wer nicht das Personal führt, entscheidet nur für die unmittelbar Unterstellten – und nie über sich selbst
        if ($person === false || !($s['abw_pflegen'] || ((string) $person !== WebController::ich($a) && in_array((string) $person, $s['team'], true)))) {
            throw Problem::nichtGefunden('Abwesenheit unbekannt');
        }
        $a->db()->executeStatement('SELECT personal.abwesenheit_entscheiden(?, ?::uuid, ?::boolean, ?)', [$a->kontext()->subjekt, $a->param('id'), $j['genehmigen'] ? 'true' : 'false', $f['grund']]);
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
        WebController::nurFelder($j, ['liegt_vor']);
        if (!is_bool($j['liegt_vor'] ?? null)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'liegt_vor', 'meldung' => 'Ja oder nein erwartet']]);
        }
        if (!$a->kontext()->darf('personal.au.sehen')) {
            throw new Problem(403, 'recht.fehlt', 'Recht fehlt: personal.au.sehen');
        }
        $a->db()->executeStatement('SELECT personal.au_nachweis_setzen(?, ?::uuid, ?::boolean)', [$a->kontext()->subjekt, $a->param('id'), $j['liegt_vor'] ? 'true' : 'false']);
        return Antwort::leer();
    }

    public function artSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['name', 'bezahlt', 'zaehlt_urlaub', 'genehmigung', 'selbst', 'stillgelegt']);
        $fehler = [];
        $f = WebController::felder($j, ['name' => ['pflichttext', 60]], $fehler);
        foreach (['bezahlt', 'zaehlt_urlaub', 'genehmigung', 'selbst', 'stillgelegt'] as $b) {
            if (isset($j[$b]) && !is_bool($j[$b])) {
                $fehler[] = ['feld' => $b, 'meldung' => 'Ja oder nein erwartet'];
            } elseif (isset($j[$b])) {
                $f[$b] = $j[$b];
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

    /** Urlaubskonto einer Person in einem Jahr: Anspruch festsetzen (null = aus dem Vertrag) und Übertrag. */
    public function kontoSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['anspruch', 'uebertrag']);
        $fehler = [];
        foreach (['anspruch' => '/^\d{1,2}(\.[05])?$/', 'uebertrag' => '/^-?\d{1,2}(\.[05])?$/'] as $feld => $muster) {
            $w = $j[$feld] ?? null;
            if ($w !== null && (!is_string($w) || !preg_match($muster, $w))) {
                $fehler[] = ['feld' => $feld, 'meldung' => 'Tage als Zahl, halbe Tage mit .5'];
            }
        }
        if (!preg_match('/^\d{4}$/', $a->param('jahr'))) {
            $fehler[] = ['feld' => 'jahr', 'meldung' => 'Jahr erwartet'];
        }
        WebController::pruefen($fehler);
        $a->db()->executeStatement('SELECT personal.urlaubskonto_setzen(?, ?::uuid, ?::integer, ?::numeric, ?::numeric)',
            [$a->kontext()->subjekt, $a->param('person'), (int) $a->param('jahr'), $j['anspruch'] ?? null, $j['uebertrag'] ?? '0']);
        return Antwort::leer();
    }
}
