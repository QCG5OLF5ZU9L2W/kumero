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
    private const VORSCHLAG = ['strasse', 'plz', 'ort', 'telefon', 'email', 'notfall_name', 'notfall_telefon'];
    private const OBJEKTARTEN = ['person', 'privat', 'vertrag', 'frist', 'vorschlag'];

    /** @param array<string, mixed> $z Zeile mit familienname und vornamen */
    public static function name(array $z): string
    {
        return $z['familienname'] . (($z['vornamen'] ?? null) === null || $z['vornamen'] === '' ? '' : ', ' . $z['vornamen']);
    }

    // --- Sicht ------------------------------------------------------------------------------------------------

    /** ID der eigenen Person oder null: die Person, die mit dem angemeldeten Nutzer verknüpft ist. */
    private static function ich(Anfrage $a): ?string
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
     * @return array{alle: bool, team: list<string>, ich: ?string, privat: bool, vertrag: bool, pflegen: bool}
     */
    private static function sicht(Anfrage $a): array
    {
        $k = $a->kontext();
        $ich = self::ich($a);
        $team = $ich !== null && $k->darf('personal.team.sehen')
            ? array_map('strval', $a->db()->fetchFirstColumn('SELECT id FROM personal.person WHERE vorgesetzter = ?', [$ich])) : [];
        return ['alle' => $k->darf('personal.person.sehen'), 'team' => $team, 'ich' => $k->darf('personal.eigenes.sehen') ? $ich : null,
                'privat' => $k->darf('personal.privat.sehen'), 'vertrag' => $k->darf('personal.vertrag.sehen'), 'pflegen' => $k->darf('personal.person.pflegen')];
    }

    /** @param array{alle: bool, team: list<string>, ich: ?string} $s */
    private static function sichtbar(array $s, string $person): bool
    {
        return $s['alle'] || $person === $s['ich'] || in_array($person, $s['team'], true);
    }

    /** @return array<string, bool> */
    private static function darf(Kontext $k): array
    {
        return ['alle' => $k->darf('personal.person.sehen'), 'team' => $k->darf('personal.team.sehen'), 'privat' => $k->darf('personal.privat.sehen'),
                'pflegen' => $k->darf('personal.person.pflegen'), 'vertrag_sehen' => $k->darf('personal.vertrag.sehen'),
                'vertrag_pflegen' => $k->darf('personal.vertrag.pflegen'), 'frist_pflegen' => $k->darf('personal.frist.pflegen'),
                'eigenes' => $k->darf('personal.eigenes.sehen'), 'vorschlagen' => $k->darf('personal.eigenes.vorschlagen')];
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
            "SELECT p.id, p.personalnummer, p.familienname, p.vornamen, p.vorgesetzter, p.nutzer, p.austritt::text AS austritt,
                    (p.austritt IS NOT NULL AND p.austritt < current_date) AS ausgeschieden,
                    v.familienname AS v_familienname, v.vornamen AS v_vornamen,
                    (SELECT funktion FROM personal.vertrag WHERE id = personal.vertrag_am(p.id)) AS funktion,
                    (SELECT count(*) FROM personal.frist f JOIN personal.fristart fa ON fa.id = f.art
                      WHERE f.person = p.id AND f.erledigt_am IS NULL AND f.faellig <= current_date + fa.warn_tage) AS fristen,
                    (SELECT count(*) FROM personal.vorschlag o WHERE o.person = p.id AND o.zustand = 'offen') AS vorschlaege,
                    (p.loeschen_ab IS NULL OR EXISTS (SELECT 1 FROM personal.privat x WHERE x.person = p.id AND x.loeschen_ab IS NULL)
                     OR EXISTS (SELECT 1 FROM personal.vertrag x WHERE x.person = p.id AND x.loeschen_ab IS NULL)
                     OR EXISTS (SELECT 1 FROM personal.frist x WHERE x.person = p.id AND x.loeschen_ab IS NULL)
                     OR EXISTS (SELECT 1 FROM personal.vorschlag x WHERE x.person = p.id AND x.loeschen_ab IS NULL)) AS frist_fehlt
               FROM personal.person p LEFT JOIN personal.person v ON v.id = p.vorgesetzter
              ORDER BY (p.austritt IS NOT NULL AND p.austritt < current_date), lower(p.familienname), lower(coalesce(p.vornamen, '')), p.id") as $z) {
            if (!self::sichtbar($s, (string) $z['id'])) {
                continue;
            }
            $personen[] = ['id' => $z['id'], 'personalnummer' => $z['personalnummer'], 'name' => self::name($z), 'familienname' => $z['familienname'],
                'vornamen' => $z['vornamen'], 'zustand' => $z['ausgeschieden'] ? 'ausgeschieden' : 'aktiv', 'austritt' => $z['austritt'],
                'vorgesetzter' => $z['vorgesetzter'],
                'vorgesetzter_name' => $z['vorgesetzter'] === null ? null : self::name(['familienname' => $z['v_familienname'], 'vornamen' => $z['v_vornamen']]),
                'funktion' => $z['funktion'], 'fristen' => (int) $z['fristen'], 'mit_nutzer' => $z['nutzer'] !== null, 'ich' => $z['id'] === $s['ich'],
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
        return Antwort::json(self::akteDaten($a, $s['ich'], ['alle' => false, 'team' => [], 'ich' => $s['ich'], 'privat' => false, 'vertrag' => false, 'pflegen' => false]));
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
            "SELECT p.id, p.personalnummer, p.familienname, p.vornamen, p.vorgesetzter, p.nutzer, p.austritt::text AS austritt, p.loeschen_ab::text AS loeschen_ab,
                    (p.austritt IS NOT NULL AND p.austritt < current_date) AS ausgeschieden, v.familienname AS v_familienname, v.vornamen AS v_vornamen
               FROM personal.person p LEFT JOIN personal.person v ON v.id = p.vorgesetzter WHERE p.id = ?", [$id]);
        if ($p === false) {
            throw Problem::nichtGefunden('Person unbekannt');
        }
        $frist = fn (array $z) => $s['pflegen'] ? ['loeschen_ab' => $z['loeschen_ab']] : [];
        $erg = ['person' => ['id' => $p['id'], 'personalnummer' => $p['personalnummer'], 'name' => self::name($p), 'familienname' => $p['familienname'],
            'vornamen' => $p['vornamen'], 'zustand' => $p['ausgeschieden'] ? 'ausgeschieden' : 'aktiv', 'austritt' => $p['austritt'], 'vorgesetzter' => $p['vorgesetzter'],
            'vorgesetzter_name' => $p['vorgesetzter'] === null ? null : self::name(['familienname' => $p['v_familienname'], 'vornamen' => $p['v_vornamen']]),
            'mit_nutzer' => $p['nutzer'] !== null, 'nutzer' => $s['pflegen'] ? $p['nutzer'] : null, 'ich' => $selbst] + $frist($p),
            'privat' => null, 'vertraege' => null, 'fristen' => [], 'vorschlaege' => null, 'heute' => date('Y-m-d')];

        if ($s['privat'] || $selbst) {
            $x = $db->fetchAssociative('SELECT geburtsdatum::text AS geburtsdatum, strasse, plz, ort, telefon, email, notfall_name, notfall_telefon, loeschen_ab::text AS loeschen_ab
                                          FROM personal.privat WHERE person = ?', [$id]) ?: array_fill_keys(self::PRIVAT, null) + ['loeschen_ab' => null];
            $erg['privat'] = array_intersect_key($x, array_flip(self::PRIVAT)) + $frist($x);
        }
        if ($s['vertrag'] || $selbst) {
            $gilt = $db->fetchOne('SELECT personal.vertrag_am(?::uuid)', [$id]);
            $buecher = Dienste::buecherAmMandanten($a);
            $erg['vertraege'] = array_map(fn (array $v) => ['id' => $v['id'], 'gueltig_ab' => $v['gueltig_ab'], 'art' => $v['art'], 'funktion' => $v['funktion'],
                'beginn' => $v['beginn'], 'probezeit_bis' => $v['probezeit_bis'], 'befristet_bis' => $v['befristet_bis'], 'kuendigungsfrist' => $v['kuendigungsfrist'],
                'wochenminuten' => (int) $v['wochenminuten'], 'verteilung' => array_map('intval', json_decode((string) $v['verteilung'], true)),
                'urlaubstage' => $v['urlaubstage'], 'lohnbuch' => $v['lohnbuch'], 'lohnbuch_name' => $v['lohnbuch'] === null ? null : ($buecher[$v['lohnbuch']] ?? $v['lohnbuch']),
                'angelegt_am' => $v['angelegt_am'], 'gilt' => $v['id'] === $gilt] + $frist($v),
                $db->fetchAllAssociative("SELECT id, gueltig_ab::text AS gueltig_ab, art, funktion, beginn::text AS beginn, probezeit_bis::text AS probezeit_bis,
                        befristet_bis::text AS befristet_bis, kuendigungsfrist, wochenminuten, to_json(verteilung)::text AS verteilung, urlaubstage::text AS urlaubstage,
                        lohnbuch, to_char(angelegt_am AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS angelegt_am, loeschen_ab::text AS loeschen_ab
                   FROM personal.vertrag WHERE person = ? ORDER BY gueltig_ab DESC, angelegt_am DESC", [$id]));
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
            "SELECT f.id, f.person, p.familienname, p.vornamen, p.personalnummer, fa.id AS art, fa.name AS art_name, fa.warn_tage, f.faellig::text AS faellig,
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
            "SELECT p.id AS person, p.familienname, p.vornamen, p.personalnummer, v.probezeit_bis::text AS probezeit_bis, v.befristet_bis::text AS befristet_bis,
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
                $a->db()->fetchAllAssociative("SELECT id, familienname, vornamen FROM personal.person WHERE austritt IS NULL OR austritt >= current_date
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
            'buecher' => array_map(fn (string $k, string $n) => ['kennung' => $k, 'name' => $n], array_keys($b = Dienste::buecherAmMandanten($a)), array_values($b))]);
    }

    // --- Schreiben --------------------------------------------------------------------------------------------

    /** @param list<array{feld: string, meldung: string}> $fehler */
    private static function pruefen(array $fehler): void
    {
        if ($fehler !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $fehler);
        }
    }

    /** @param array<string, mixed> $j */
    private static function nurFelder(array $j, array $erlaubt): void
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
    private static function felder(array $j, array $form, array &$fehler, string $vor = ''): array
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
        self::nurFelder($j, ['personalnummer', 'familienname', 'vornamen', 'vorgesetzter', 'nutzer', 'austritt', 'privat']);
        $fehler = [];
        $stamm = self::felder($j, ['personalnummer' => ['pflichttext', 20], 'familienname' => ['pflichttext', 100], 'vornamen' => ['text', 100],
            'vorgesetzter' => ['uuid'], 'nutzer' => ['uuid'], 'austritt' => ['datum']], $fehler);
        if (isset($stamm['personalnummer']) && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,19}$/', $stamm['personalnummer'])) {
            $fehler[] = ['feld' => 'personalnummer', 'meldung' => 'Buchstaben, Ziffern und . _ / - ; höchstens 20 Zeichen'];
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
        $neu = (string) $a->db()->fetchOne('SELECT personal.person_setzen(?, ?::uuid, ?::jsonb)', [$a->kontext()->subjekt, $id, json_encode($stamm, JSON_UNESCAPED_UNICODE)]);
        if ($privat !== null) {
            $a->db()->executeStatement('SELECT personal.privat_setzen(?, ?::uuid, ?::jsonb)', [$a->kontext()->subjekt, $neu, json_encode($privat, JSON_UNESCAPED_UNICODE)]);
        }
        return $id === null ? Antwort::json(['id' => $neu], 201) : Antwort::leer();
    }

    public function vertragAnlegen(Anfrage $a): Response
    {
        $j = $a->json();
        self::nurFelder($j, ['gueltig_ab', 'art', 'funktion', 'beginn', 'probezeit_bis', 'befristet_bis', 'kuendigungsfrist', 'verteilung', 'urlaubstage', 'lohnbuch']);
        $fehler = [];
        $v = self::felder($j, ['gueltig_ab' => ['pflichtdatum'], 'art' => ['pflichttext', 20], 'funktion' => ['text', 100], 'beginn' => ['pflichtdatum'],
            'probezeit_bis' => ['datum'], 'befristet_bis' => ['datum'], 'kuendigungsfrist' => ['text', 200], 'lohnbuch' => ['text', 20]], $fehler);
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
            'notfall_name' => ['text', 100], 'notfall_telefon' => ['text', 40]], $fehler);
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
