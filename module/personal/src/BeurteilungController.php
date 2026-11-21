<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Personal;

use Kumero\Api\V1\Problem;
use Kumero\Http\Antwort;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Beurteilungen und Zielvereinbarungen (PERSONAL.md Stufe 4, Abschnitt 10e, F141). Wer was sieht (PE15):
 *   – die Person selbst: ihre Beurteilungen und Ziele; die Einschätzung des Beurteilers erst, wenn beide abgegeben
 *     haben, spätestens mit dem Abschluss;
 *   – der Beurteiler (Recht `personal.beurteilung.team`): die Beurteilungen, bei denen er als Beurteiler eingetragen
 *     ist; die Selbsteinschätzung erst, wenn er seine eigene abgegeben hat – niemand schreibt vom anderen ab;
 *     Ziele der Personen, die ihm unmittelbar unterstehen;
 *   – wer `personal.beurteilung.sehen` hat (Personal): alles; die Rolle Lohn nichts.
 * Über sich selbst entscheidet niemand wie über ein Teammitglied.
 */
final class BeurteilungController
{
    private const ZEIT = "to_char(%s AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"')";

    /** @return array{ich: ?string, team: list<string>, sehen: bool, pflegen: bool, beurteilen: bool, eigene: bool} */
    public static function sicht(Anfrage $a): array
    {
        $k = $a->kontext();
        $ich = WebController::ich($a);
        $beurteilen = $k->darf('personal.beurteilung.team');
        $team = $ich !== null && $beurteilen ? array_map('strval', $a->db()->fetchFirstColumn('SELECT id FROM personal.person WHERE vorgesetzter = ?', [$ich])) : [];
        return ['ich' => $ich, 'team' => $team, 'sehen' => $k->darf('personal.beurteilung.sehen'), 'pflegen' => $k->darf('personal.beurteilung.pflegen'),
                'beurteilen' => $beurteilen, 'eigene' => $k->darf('personal.beurteilung.eigene')];
    }

    /** @return list<array<string, mixed>> */
    public static function vorlagen(Anfrage $a): array
    {
        return array_map(fn (array $z) => ['id' => $z['id'], 'name' => $z['name'], 'skala_von' => (int) $z['skala_von'], 'skala_bis' => (int) $z['skala_bis'],
            'skala_niedrig' => $z['skala_niedrig'], 'skala_hoch' => $z['skala_hoch'], 'kriterien' => json_decode((string) $z['kriterien'], true), 'stillgelegt' => (bool) $z['stillgelegt']],
            $a->db()->fetchAllAssociative('SELECT id, name, skala_von, skala_bis, skala_niedrig, skala_hoch, kriterien::text AS kriterien, stillgelegt FROM personal.beurteilungsvorlage ORDER BY stillgelegt, lower(name)'));
    }

    /**
     * Beurteilungen, die der Aufrufer sehen darf – je Eintrag nur, was ihm zusteht.
     *
     * @param array{ich: ?string, team: list<string>, sehen: bool, pflegen: bool, beurteilen: bool, eigene: bool} $s
     * @param ?string $nurPerson nur die Beurteilungen dieser Person, aus ihrer eigenen Sicht (Selfservice)
     * @return list<array<string, mixed>>
     */
    public static function beurteilungen(Anfrage $a, array $s, ?string $nurPerson = null): array
    {
        $erg = [];
        foreach ($a->db()->fetchAllAssociative(
            'SELECT b.id, b.person, p.familienname, p.vornamen, p.akademischer_titel, p.personalnummer, b.vorlage_name, b.kriterien::text AS kriterien, b.skala_von, b.skala_bis, b.skala_niedrig, b.skala_hoch,
                    b.zeitraum_von::text AS zeitraum_von, b.zeitraum_bis::text AS zeitraum_bis, b.beurteiler, u.familienname AS b_familienname, u.vornamen AS b_vornamen, u.akademischer_titel AS b_titel, b.zustand,
                    b.selbst::text AS selbst, b.fremd::text AS fremd, b.gespraech_am::text AS gespraech_am, b.ergebnis, b.stellungnahme, b.grund, b.loeschen_ab::text AS loeschen_ab, '
                    . sprintf(self::ZEIT, 'b.selbst_am') . ' AS selbst_am, ' . sprintf(self::ZEIT, 'b.fremd_am') . ' AS fremd_am, ' . sprintf(self::ZEIT, 'b.abgeschlossen_am') . ' AS abgeschlossen_am, '
                    . sprintf(self::ZEIT, 'b.kenntnis_am') . ' AS kenntnis_am
               FROM personal.beurteilung b JOIN personal.person p ON p.id = b.person LEFT JOIN personal.person u ON u.id = b.beurteiler'
            . ($nurPerson === null ? '' : ' WHERE b.person = ?') . ' ORDER BY (b.zustand = \'offen\') DESC, b.zeitraum_bis DESC, lower(p.familienname), b.id', $nurPerson === null ? [] : [$nurPerson]) as $z) {
            $person = (string) $z['person'];
            $selbst = $person === $s['ich'];
            $alles = $nurPerson === null && $s['sehen'] && !$selbst;      // das Eigene sieht auch das Personal wie jede Person
            $zustaendig = !$selbst && $nurPerson === null && ($s['pflegen'] || ($s['beurteilen'] && $s['ich'] !== null && $z['beurteiler'] === $s['ich']));
            if (!$selbst && !$alles && !$zustaendig) {
                continue;
            }
            $fertig = $z['zustand'] === 'abgeschlossen' || $z['zustand'] === 'zur_kenntnis';
            $zeigtSelbst = $selbst || $alles || $fertig || $z['fremd_am'] !== null;
            $zeigtFremd = !$selbst || $fertig || ($z['selbst_am'] !== null && $z['fremd_am'] !== null);
            $erg[] = ['id' => $z['id'], 'person' => $person, 'name' => WebController::name($z), 'personalnummer' => $z['personalnummer'], 'ich' => $selbst,
                'vorlage' => $z['vorlage_name'], 'kriterien' => json_decode((string) $z['kriterien'], true), 'skala_von' => (int) $z['skala_von'], 'skala_bis' => (int) $z['skala_bis'],
                'skala_niedrig' => $z['skala_niedrig'], 'skala_hoch' => $z['skala_hoch'], 'zeitraum_von' => $z['zeitraum_von'], 'zeitraum_bis' => $z['zeitraum_bis'],
                'beurteiler' => $z['beurteiler'], 'beurteiler_name' => $z['beurteiler'] === null ? null : WebController::name(['familienname' => $z['b_familienname'], 'vornamen' => $z['b_vornamen'], 'akademischer_titel' => $z['b_titel']]),
                'zustand' => $z['zustand'], 'zustaendig' => $zustaendig,
                'selbst_abgegeben' => $z['selbst_am'] !== null, 'fremd_abgegeben' => $z['fremd_am'] !== null,
                'selbst' => $zeigtSelbst && $z['selbst'] !== null ? json_decode($z['selbst'], true) : null, 'fremd' => $zeigtFremd && $z['fremd'] !== null ? json_decode($z['fremd'], true) : null,
                'selbst_am' => $z['selbst_am'], 'fremd_am' => $z['fremd_am'], 'gespraech_am' => $z['gespraech_am'], 'ergebnis' => $z['ergebnis'], 'abgeschlossen_am' => $z['abgeschlossen_am'],
                'kenntnis_am' => $z['kenntnis_am'], 'stellungnahme' => $z['stellungnahme'], 'grund' => $z['grund']]
                + ($s['pflegen'] && $nurPerson === null ? ['loeschen_ab' => $z['loeschen_ab']] : []);
        }
        return $erg;
    }

    /**
     * @param array{ich: ?string, team: list<string>, sehen: bool, pflegen: bool, beurteilen: bool, eigene: bool} $s
     * @return list<array<string, mixed>>
     */
    public static function ziele(Anfrage $a, array $s, ?string $nurPerson = null): array
    {
        $erg = [];
        foreach ($a->db()->fetchAllAssociative(
            'SELECT z.id, z.person, p.familienname, p.vornamen, p.akademischer_titel, p.personalnummer, z.titel, z.beschreibung, z.frist::text AS frist, (z.frist - current_date) AS tage, z.zustand, z.stand, z.stand_text,
                    z.bewertung, z.bewertung_text, z.grund, z.loeschen_ab::text AS loeschen_ab, ' . sprintf(self::ZEIT, 'z.stand_am') . ' AS stand_am, ' . sprintf(self::ZEIT, 'z.vereinbart_am') . ' AS vereinbart_am
               FROM personal.ziel z JOIN personal.person p ON p.id = z.person' . ($nurPerson === null ? '' : ' WHERE z.person = ?')
            . ' ORDER BY (z.zustand IN (\'vorgeschlagen\', \'vereinbart\')) DESC, z.frist, lower(p.familienname), z.id', $nurPerson === null ? [] : [$nurPerson]) as $z) {
            $person = (string) $z['person'];
            $selbst = $person === $s['ich'];
            $zustaendig = !$selbst && $nurPerson === null && ($s['pflegen'] || ($s['beurteilen'] && in_array($person, $s['team'], true)));
            if (!$selbst && !$zustaendig && !($nurPerson === null && $s['sehen'])) {
                continue;
            }
            $erg[] = ['id' => $z['id'], 'person' => $person, 'name' => WebController::name($z), 'personalnummer' => $z['personalnummer'], 'ich' => $selbst, 'titel' => $z['titel'],
                'beschreibung' => $z['beschreibung'], 'frist' => $z['frist'], 'tage' => (int) $z['tage'], 'zustand' => $z['zustand'], 'stand' => (int) $z['stand'], 'stand_text' => $z['stand_text'],
                'stand_am' => $z['stand_am'], 'vereinbart_am' => $z['vereinbart_am'], 'bewertung' => $z['bewertung'], 'bewertung_text' => $z['bewertung_text'], 'grund' => $z['grund'], 'zustaendig' => $zustaendig]
                + ($s['pflegen'] && $nurPerson === null ? ['loeschen_ab' => $z['loeschen_ab']] : []);
        }
        return $erg;
    }

    public function stand(Anfrage $a): Response
    {
        $s = self::sicht($a);
        // für wen der Aufrufer anlegen darf: das Personal für alle, der Vorgesetzte für sein Team – nie für sich selbst
        $personen = [];
        foreach ($a->db()->fetchAllAssociative('SELECT id, familienname, vornamen, akademischer_titel, vorgesetzter FROM personal.person WHERE austritt IS NULL OR austritt >= current_date
                                                 ORDER BY lower(familienname), lower(coalesce(vornamen, \'\')), id') as $z) {
            if ((string) $z['id'] !== $s['ich'] && ($s['pflegen'] || in_array((string) $z['id'], $s['team'], true))) {
                $personen[] = ['id' => $z['id'], 'name' => WebController::name($z), 'vorgesetzter' => $z['vorgesetzter']];
            }
        }
        $alle = $s['pflegen'] ? array_map(fn (array $z) => ['id' => $z['id'], 'name' => WebController::name($z)],
            $a->db()->fetchAllAssociative('SELECT id, familienname, vornamen, akademischer_titel FROM personal.person WHERE austritt IS NULL OR austritt >= current_date ORDER BY lower(familienname), id')) : [];
        return Antwort::json(['darf' => ['sehen' => $s['sehen'], 'pflegen' => $s['pflegen'], 'beurteilen' => $s['beurteilen']], 'ich' => $s['ich'], 'heute' => date('Y-m-d'),
            'beurteilungen' => self::beurteilungen($a, $s), 'ziele' => self::ziele($a, $s), 'vorlagen' => self::vorlagen($a), 'personen' => $personen, 'beurteiler' => $alle]);
    }

    // --- Zuständigkeit ----------------------------------------------------------------------------------------

    /** Die Beurteilung, wenn der Aufrufer für sie zuständig ist; sonst 404 – „nicht deine“ sieht aus wie „gibt es nicht“. @return array<string, mixed> */
    private static function zustaendigFuer(Anfrage $a, bool $nurPflege = false): array
    {
        $s = self::sicht($a);
        $b = $a->db()->fetchAssociative('SELECT person, beurteiler FROM personal.beurteilung WHERE id = ?::uuid', [$a->param('id')]);
        if ($b === false || (string) $b['person'] === $s['ich'] || !($s['pflegen'] || (!$nurPflege && $s['ich'] !== null && $b['beurteiler'] === $s['ich']))) {
            throw Problem::nichtGefunden('Beurteilung unbekannt');
        }
        return $b;
    }

    private static function zielZustaendig(Anfrage $a): void
    {
        $s = self::sicht($a);
        $person = $a->db()->fetchOne('SELECT person FROM personal.ziel WHERE id = ?::uuid', [$a->param('id')]);
        if ($person === false || (string) $person === $s['ich'] || !($s['pflegen'] || in_array((string) $person, $s['team'], true))) {
            throw Problem::nichtGefunden('Ziel unbekannt');
        }
    }

    /** Die eigene Person oder 409. */
    private static function ich(Anfrage $a): string
    {
        return WebController::ich($a) ?? throw new Problem(409, 'personal.nicht_verknuepft', 'Dein Zugang ist noch mit keiner Person verknüpft');
    }

    private static function eigenes(Anfrage $a, string $tabelle): void
    {
        $ich = self::ich($a);
        if ($a->db()->fetchOne("SELECT 1 FROM personal.$tabelle WHERE id = ?::uuid AND person = ?::uuid", [$a->param('id'), $ich]) === false) {
            throw Problem::nichtGefunden($tabelle === 'ziel' ? 'Ziel unbekannt' : 'Beurteilung unbekannt');
        }
    }

    // --- Beurteilung: Beurteiler und Personal -------------------------------------------------------------------

    public function anlegen(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['person', 'vorlage', 'zeitraum_von', 'zeitraum_bis', 'beurteiler']);
        $fehler = [];
        $f = WebController::felder($j, ['person' => ['uuid'], 'vorlage' => ['uuid'], 'zeitraum_von' => ['pflichtdatum'], 'zeitraum_bis' => ['pflichtdatum'], 'beurteiler' => ['uuid']], $fehler);
        foreach (['person', 'vorlage'] as $feld) {
            if (($f[$feld] ?? null) === null && !in_array($feld, array_column($fehler, 'feld'), true)) {
                $fehler[] = ['feld' => $feld, 'meldung' => 'Fehlt'];
            }
        }
        WebController::pruefen($fehler);
        $s = self::sicht($a);
        if ($f['person'] === $s['ich'] || !($s['pflegen'] || in_array($f['person'], $s['team'], true))) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'person', 'meldung' => 'Für diese Person legst nicht du eine Beurteilung an']]);
        }
        // der Vorgesetzte beurteilt selbst; das Personal nennt den Beurteiler oder lässt ihn leer (dann schätzt das Personal ein)
        $beurteiler = $s['pflegen'] ? ($f['beurteiler'] ?? null) : $s['ich'];
        $id = (string) $a->db()->fetchOne('SELECT personal.beurteilung_anlegen(?, ?::uuid, ?::uuid, ?::date, ?::date, ?::uuid)',
            [$a->kontext()->subjekt, $f['person'], $f['vorlage'], $f['zeitraum_von'], $f['zeitraum_bis'], $beurteiler]);
        return Antwort::json(['id' => $id], 201);
    }

    /** @return array<string, mixed> */
    private static function einschaetzung(Anfrage $a): array
    {
        $j = $a->json();
        WebController::nurFelder($j, ['werte', 'texte', 'gesamt']);
        $fehler = [];
        if (!is_array($j['werte'] ?? null) || array_filter($j['werte'], fn ($w) => !is_int($w)) !== []) {
            $fehler[] = ['feld' => 'werte', 'meldung' => 'Je Kriterium eine ganze Zahl der Skala'];
        }
        if (isset($j['texte']) && (!is_array($j['texte']) || array_filter($j['texte'], fn ($w) => $w !== null && !is_string($w)) !== [])) {
            $fehler[] = ['feld' => 'texte', 'meldung' => 'Je Kriterium ein Text'];
        }
        if (isset($j['gesamt']) && !is_string($j['gesamt'])) {
            $fehler[] = ['feld' => 'gesamt', 'meldung' => 'Text erwartet'];
        }
        WebController::pruefen($fehler);
        return ['werte' => (object) $j['werte'], 'texte' => (object) array_filter($j['texte'] ?? [], fn ($w) => $w !== null), 'gesamt' => $j['gesamt'] ?? null];
    }

    public function einschaetzen(Anfrage $a): Response
    {
        $e = self::einschaetzung($a);
        self::zustaendigFuer($a);
        $a->db()->executeStatement('SELECT personal.beurteilung_einschaetzen(?, ?::uuid, false, ?::jsonb)', [$a->kontext()->subjekt, $a->param('id'), json_encode($e, JSON_UNESCAPED_UNICODE)]);
        return Antwort::leer();
    }

    public function abschliessen(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['gespraech_am', 'ergebnis']);
        $fehler = [];
        $f = WebController::felder($j, ['gespraech_am' => ['pflichtdatum'], 'ergebnis' => ['pflichttext', 2000]], $fehler);
        WebController::pruefen($fehler);
        self::zustaendigFuer($a);
        $a->db()->executeStatement('SELECT personal.beurteilung_abschliessen(?, ?::uuid, ?::date, ?)', [$a->kontext()->subjekt, $a->param('id'), $f['gespraech_am'], $f['ergebnis']]);
        return Antwort::leer();
    }

    public function aufheben(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['grund']);
        $fehler = [];
        $f = WebController::felder($j, ['grund' => ['pflichttext', 200]], $fehler);
        WebController::pruefen($fehler);
        self::zustaendigFuer($a);
        $a->db()->executeStatement('SELECT personal.beurteilung_aufheben(?, ?::uuid, ?)', [$a->kontext()->subjekt, $a->param('id'), $f['grund']]);
        return Antwort::leer();
    }

    public function beurteilerSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['beurteiler']);
        $fehler = [];
        $f = WebController::felder($j, ['beurteiler' => ['uuid']], $fehler);
        WebController::pruefen($fehler);
        self::zustaendigFuer($a, true);
        $a->db()->executeStatement('SELECT personal.beurteilung_beurteiler_setzen(?, ?::uuid, ?::uuid)', [$a->kontext()->subjekt, $a->param('id'), $f['beurteiler'] ?? null]);
        return Antwort::leer();
    }

    public function vorlageSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        $id = $a->param['id'] ?? null;
        WebController::nurFelder($j, ['name', 'skala_von', 'skala_bis', 'skala_niedrig', 'skala_hoch', 'kriterien', 'stillgelegt']);
        $fehler = [];
        $f = WebController::felder($j, ['name' => ['pflichttext', 60], 'skala_niedrig' => ['text', 60], 'skala_hoch' => ['text', 60]], $fehler);
        foreach (['skala_von' => [0, 1], 'skala_bis' => [2, 10]] as $feld => [$min, $max]) {
            if (isset($j[$feld]) && (!is_int($j[$feld]) || $j[$feld] < $min || $j[$feld] > $max)) {
                $fehler[] = ['feld' => $feld, 'meldung' => "Ganze Zahl von $min bis $max"];
            }
        }
        if (!is_array($j['kriterien'] ?? null) || !array_is_list($j['kriterien']) || $j['kriterien'] === []
            || array_filter($j['kriterien'], fn ($k) => !is_array($k) || !is_string($k['name'] ?? null) || array_diff(array_keys($k), ['kennung', 'name', 'beschreibung']) !== []) !== []) {
            $fehler[] = ['feld' => 'kriterien', 'meldung' => 'Mindestens ein Kriterium mit Namen'];
        }
        if (isset($j['stillgelegt']) && !is_bool($j['stillgelegt'])) {
            $fehler[] = ['feld' => 'stillgelegt', 'meldung' => 'Ja oder nein erwartet'];
        }
        WebController::pruefen($fehler);
        $neu = (string) $a->db()->fetchOne('SELECT personal.beurteilungsvorlage_setzen(?, ?::uuid, ?::jsonb)',
            [$a->kontext()->subjekt, $id, json_encode($f + array_intersect_key($j, array_flip(['skala_von', 'skala_bis', 'kriterien', 'stillgelegt'])), JSON_UNESCAPED_UNICODE)]);
        return $id === null ? Antwort::json(['id' => $neu], 201) : Antwort::leer();
    }

    // --- Ziele: Vorgesetzter und Personal -----------------------------------------------------------------------

    public function zielAnlegen(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['person', 'titel', 'beschreibung', 'frist']);
        $fehler = [];
        $f = WebController::felder($j, ['person' => ['uuid'], 'titel' => ['pflichttext', 120], 'beschreibung' => ['text', 1000], 'frist' => ['pflichtdatum']], $fehler);
        if (($f['person'] ?? null) === null && !in_array('person', array_column($fehler, 'feld'), true)) {
            $fehler[] = ['feld' => 'person', 'meldung' => 'Fehlt'];
        }
        WebController::pruefen($fehler);
        $s = self::sicht($a);
        if ($f['person'] === $s['ich'] || !($s['pflegen'] || in_array($f['person'], $s['team'], true))) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'person', 'meldung' => 'Mit dieser Person vereinbarst nicht du ein Ziel']]);
        }
        $person = $f['person'];
        unset($f['person']);
        $id = (string) $a->db()->fetchOne('SELECT personal.ziel_anlegen(?, ?::uuid, ?::jsonb)', [$a->kontext()->subjekt, $person, json_encode($f, JSON_UNESCAPED_UNICODE)]);
        return Antwort::json(['id' => $id], 201);
    }

    /** @return array{0: int, 1: ?string} */
    private static function standLesen(Anfrage $a): array
    {
        $j = $a->json();
        WebController::nurFelder($j, ['stand', 'text']);
        $fehler = [];
        $f = WebController::felder($j, ['text' => ['text', 500]], $fehler);
        if (!is_int($j['stand'] ?? null) || $j['stand'] < 0 || $j['stand'] > 100) {
            $fehler[] = ['feld' => 'stand', 'meldung' => 'Ganze Zahl von 0 bis 100'];
        }
        WebController::pruefen($fehler);
        return [$j['stand'], $f['text']];
    }

    public function zielStand(Anfrage $a): Response
    {
        [$stand, $text] = self::standLesen($a);
        self::zielZustaendig($a);
        $a->db()->executeStatement('SELECT personal.ziel_stand_setzen(?, ?::uuid, ?::integer, ?)', [$a->kontext()->subjekt, $a->param('id'), $stand, $text]);
        return Antwort::leer();
    }

    public function zielBewerten(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['bewertung', 'text']);
        $fehler = [];
        $f = WebController::felder($j, ['bewertung' => ['pflichttext', 20], 'text' => ['text', 1000]], $fehler);
        WebController::pruefen($fehler);
        self::zielZustaendig($a);
        $a->db()->executeStatement('SELECT personal.ziel_bewerten(?, ?::uuid, ?, ?)', [$a->kontext()->subjekt, $a->param('id'), $f['bewertung'], $f['text']]);
        return Antwort::leer();
    }

    public function zielAufheben(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['grund']);
        $fehler = [];
        $f = WebController::felder($j, ['grund' => ['pflichttext', 200]], $fehler);
        WebController::pruefen($fehler);
        self::zielZustaendig($a);
        $a->db()->executeStatement('SELECT personal.ziel_aufheben(?, ?::uuid, ?)', [$a->kontext()->subjekt, $a->param('id'), $f['grund']]);
        return Antwort::leer();
    }

    // --- Selfservice ------------------------------------------------------------------------------------------

    public function selbstEinschaetzen(Anfrage $a): Response
    {
        $e = self::einschaetzung($a);
        self::eigenes($a, 'beurteilung');
        $a->db()->executeStatement('SELECT personal.beurteilung_einschaetzen(?, ?::uuid, true, ?::jsonb)', [$a->kontext()->subjekt, $a->param('id'), json_encode($e, JSON_UNESCAPED_UNICODE)]);
        return Antwort::leer();
    }

    public function kenntnis(Anfrage $a): Response
    {
        $j = $a->json(false);
        WebController::nurFelder($j, ['stellungnahme']);
        $fehler = [];
        $f = WebController::felder($j, ['stellungnahme' => ['text', 2000]], $fehler);
        WebController::pruefen($fehler);
        self::eigenes($a, 'beurteilung');
        $a->db()->executeStatement('SELECT personal.beurteilung_kenntnis(?, ?::uuid, ?)', [$a->kontext()->subjekt, $a->param('id'), $f['stellungnahme']]);
        return Antwort::leer();
    }

    public function zielBestaetigen(Anfrage $a): Response
    {
        $j = $a->json();
        WebController::nurFelder($j, ['ja', 'grund']);
        $fehler = [];
        $f = WebController::felder($j, ['grund' => ['text', 200]], $fehler);
        if (!is_bool($j['ja'] ?? null)) {
            $fehler[] = ['feld' => 'ja', 'meldung' => 'Ja oder nein erwartet'];
        } elseif (!$j['ja'] && ($f['grund'] ?? null) === null) {
            $fehler[] = ['feld' => 'grund', 'meldung' => 'Eine Ablehnung braucht einen Grund'];
        }
        WebController::pruefen($fehler);
        self::eigenes($a, 'ziel');
        $a->db()->executeStatement('SELECT personal.ziel_bestaetigen(?, ?::uuid, ?::boolean, ?)', [$a->kontext()->subjekt, $a->param('id'), $j['ja'] ? 'true' : 'false', $f['grund']]);
        return Antwort::leer();
    }

    public function selbstStand(Anfrage $a): Response
    {
        [$stand, $text] = self::standLesen($a);
        self::eigenes($a, 'ziel');
        $a->db()->executeStatement('SELECT personal.ziel_stand_setzen(?, ?::uuid, ?::integer, ?)', [$a->kontext()->subjekt, $a->param('id'), $stand, $text]);
        return Antwort::leer();
    }
}
