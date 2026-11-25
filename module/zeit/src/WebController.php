<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Zeit;

use Kumero\Api\V1\Problem;
use Kumero\Http\Antwort;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zeiterfassung (ZEIT.md Abschnitt 9, F145). Wer was sieht:
 *   – die Person selbst: ihre Zeiten, ihr Zeitkonto, ihre Anträge (Recht zeit.eigene.buchen);
 *   – der Vorgesetzte: die Zeiten der ihm unmittelbar Unterstellten – er gibt Anträge, Tage und Monate frei;
 *   – wer zeit.alle.sehen hat (Personal, Lohn): alle; wer zeit.alle.pflegen hat (Personal), gibt für alle frei, trägt
 *     für andere ein und pflegt Profile und Regeln.
 * Über die eigenen Zeiten entscheidet niemand als Vorgesetzter. Gerechnet wird in der Datenbank (zeit.tage).
 */
final class WebController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const MONAT = '/^\d{4}-(0[1-9]|1[0-2])$/';
    private const DATUM = '/^\d{4}-\d{2}-\d{2}$/';
    private const ZEIT = "to_char(%s AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"')";
    private const ORTE = ['betrieb', 'mobil', 'dienstgang'];
    private const ZAHLEN = ['brutto', 'pause', 'abzug', 'gezaehlt', 'soll', 'gutschrift', 'saldo', 'nacht', 'sonntag', 'feiertag_minuten'];

    // --- Sicht ------------------------------------------------------------------------------------------------

    /** @return array<string, array{id: string, personalnummer: string, name: string, nutzer: ?string, vorgesetzter: ?string, eintritt: ?string, austritt: ?string}> */
    private static function personen(Anfrage $a): array
    {
        return array_column($a->db()->fetchAllAssociative('SELECT id, personalnummer, name, nutzer, vorgesetzter, eintritt::text AS eintritt, austritt::text AS austritt FROM personal.api_personen()'), null, 'id');
    }

    /** @return array{ich: ?string, team: list<string>, alle: bool, pflegen: bool, freigeben: bool, personen: array<string, array<string, mixed>>} */
    private static function sicht(Anfrage $a): array
    {
        $k = $a->kontext();
        $personen = self::personen($a);
        $ich = null;
        if (str_starts_with($k->subjekt, 'nutzer:')) {
            foreach ($personen as $p) {
                if ($p['nutzer'] === substr($k->subjekt, 7)) {
                    $ich = (string) $p['id'];
                }
            }
        }
        $freigeben = $k->darf('zeit.team.freigeben');
        $team = $ich !== null && $freigeben ? array_keys(array_filter($personen, fn (array $p) => $p['vorgesetzter'] === $ich)) : [];
        return ['ich' => $ich, 'team' => array_map('strval', $team), 'alle' => $k->darf('zeit.alle.sehen'), 'pflegen' => $k->darf('zeit.alle.pflegen'),
                'freigeben' => $freigeben, 'personen' => $personen];
    }

    /** Die Person aus dem Pfad, wenn der Aufrufer sie sehen bzw. – mit $entscheiden – über sie entscheiden darf; sonst 404. */
    private static function person(Anfrage $a, array $s, bool $entscheiden): string
    {
        $id = $a->param('person');
        $sieht = isset($s['personen'][$id]) && ($s['alle'] || in_array($id, $s['team'], true));
        $darf = isset($s['personen'][$id]) && ($s['pflegen'] || ($id !== $s['ich'] && in_array($id, $s['team'], true)));
        if (!preg_match(self::UUID, $id) || !($entscheiden ? $darf : ($sieht || $darf))) {
            throw Problem::nichtGefunden('Person unbekannt');
        }
        return $id;
    }

    private static function ich(Anfrage $a): string
    {
        return self::sicht($a)['ich'] ?? throw new Problem(409, 'personal.nicht_verknuepft', 'Dein Zugang ist noch mit keiner Person verknüpft – das richtet das Personal ein');
    }

    private static function monat(?string $m): string
    {
        if ($m === null || !preg_match(self::MONAT, $m)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'monat', 'meldung' => 'Monat als JJJJ-MM erwartet']]);
        }
        return $m;
    }

    // --- Lesen ------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function einstellung(Anfrage $a): array
    {
        $e = $a->db()->fetchAssociative("SELECT zeitzone, pausen::text AS pausen, hoechst_minuten, offen_minuten, to_char(nacht_von, 'HH24:MI') AS nacht_von, to_char(nacht_bis, 'HH24:MI') AS nacht_bis, monatsabschluss, aufbewahrung_monate FROM zeit.einstellung");
        return ['zeitzone' => $e['zeitzone'], 'pausen' => json_decode((string) $e['pausen'], true), 'hoechst_minuten' => (int) $e['hoechst_minuten'], 'offen_minuten' => (int) $e['offen_minuten'], 'nacht_von' => $e['nacht_von'],
            'nacht_bis' => $e['nacht_bis'], 'monatsabschluss' => $e['monatsabschluss'], 'aufbewahrung_monate' => (int) $e['aufbewahrung_monate']];
    }

    /** @return array<string, mixed> */
    private static function profil(Anfrage $a, string $person): array
    {
        $p = $a->db()->fetchAssociative("SELECT modell, to_json(regeltage)::text AS regeltage, to_char(rahmen_von, 'HH24:MI') AS rahmen_von, to_char(rahmen_bis, 'HH24:MI') AS rahmen_bis,
            to_char(kern_von, 'HH24:MI') AS kern_von, to_char(kern_bis, 'HH24:MI') AS kern_bis, korrektur, anfangssaldo, beginn::text AS beginn FROM zeit.profil WHERE person = ?", [$person]);
        return $p === false ? ['modell' => 'fest', 'regeltage' => null, 'rahmen_von' => null, 'rahmen_bis' => null, 'kern_von' => null, 'kern_bis' => null, 'korrektur' => 'freigabe', 'anfangssaldo' => 0, 'beginn' => null, 'gesetzt' => false]
            : ['regeltage' => $p['regeltage'] === null ? null : json_decode($p['regeltage'], true), 'anfangssaldo' => (int) $p['anfangssaldo'], 'gesetzt' => true] + $p;
    }

    /**
     * Monatsblatt einer Person: die Tage mit dem, was zählt, die Summen, das Zeitkonto, Anträge und der Stand des Monats.
     *
     * @return array<string, mixed>
     */
    private static function monatsblatt(Anfrage $a, string $person, string $monat): array
    {
        $db = $a->db();
        $von = "$monat-01";
        $tage = [];
        $summe = array_fill_keys(self::ZAHLEN, 0);
        foreach ($db->fetchAllAssociative("SELECT tag::text AS tag, abschnitte::text AS abschnitte, brutto, pause, abzug, gezaehlt, soll, gutschrift, saldo, nacht, sonntag, feiertag_minuten, feiertag, frei,
                abwesenheit, arbeitstag, to_json(hinweise)::text AS hinweise, to_json(braucht)::text AS braucht, laeuft
                FROM zeit.tage(?::uuid, ?::date, (?::date + interval '1 month' - interval '1 day')::date)", [$person, $von, $von]) as $z) {
            $t = ['tag' => $z['tag'], 'abschnitte' => json_decode((string) $z['abschnitte'], true), 'feiertag' => $z['feiertag'], 'frei' => $z['frei'], 'abwesenheit' => (float) $z['abwesenheit'],
                'arbeitstag' => (bool) $z['arbeitstag'], 'hinweise' => json_decode((string) $z['hinweise'], true), 'braucht' => json_decode((string) $z['braucht'], true), 'laeuft' => (bool) $z['laeuft']];
            foreach (self::ZAHLEN as $f) {
                $t[$f] = (int) $z[$f];
            }
            // Saldo, Soll und was zählt, gelten für abgelaufene Tage; heute und künftig stehen sie offen – sonst gingen die Summen nicht auf
            $t['zaehlt'] = $z['tag'] < (string) $db->fetchOne('SELECT zeit._jetzt()::date::text');
            foreach (self::ZAHLEN as $f) {
                $summe[$f] += $t['zaehlt'] || !in_array($f, ['saldo', 'soll', 'gezaehlt', 'gutschrift'], true) ? $t[$f] : 0;
            }
            $tage[] = $t;
        }
        $stand = $db->fetchAssociative('SELECT zustand, ' . sprintf(self::ZEIT, 'bestaetigt_am') . ' AS bestaetigt_am, ' . sprintf(self::ZEIT, 'freigegeben_am') . ' AS freigegeben_am FROM zeit.monat WHERE person = ? AND monat = ?::date', [$person, $von]);
        $antraege = $db->fetchAllAssociative("SELECT id, tag::text AS tag, art, abschnitt, to_char(von, 'HH24:MI') AS von, to_char(bis, 'HH24:MI') AS bis, ort, begruendung, zustand, grund, "
            // beim nachgetragenen Gehen steht das gestempelte Kommen daneben, solange der Antrag offen ist (NB5)
            . "(SELECT to_char(ab.von, 'HH24:MI') FROM zeit.abschnitt ab WHERE ab.id = antrag.abschnitt AND antrag.art = 'gehen' AND antrag.zustand = 'offen') AS kommen, "
            . sprintf(self::ZEIT, 'angelegt_am') . " AS angelegt_am FROM zeit.antrag WHERE person = ? AND date_trunc('month', tag) = ?::date ORDER BY tag, angelegt_am", [$person, $von]);
        // alle offenen Abschnitte der Person, auch außerhalb des angezeigten Monats: Zu ihnen lässt sich das Gehen nachtragen (ZEIT.md Abschnitt 12)
        $offene = $db->fetchAllAssociative("SELECT id, tag::text AS tag, to_char(von, 'HH24:MI') AS von, ort, zeit._offen_veraltet(person, tag, von, zeit._jetzt()) AS veraltet
            FROM zeit.abschnitt WHERE person = ? AND bis IS NULL ORDER BY tag, von", [$person]);
        return ['person' => $person, 'monat' => $monat, 'tage' => $tage, 'summe' => $summe, 'offene' => $offene,
            'konto' => (int) $db->fetchOne("SELECT zeit.konto(?::uuid, (?::date + interval '1 month' - interval '1 day')::date)", [$person, $von]),
            'monatsstand' => $stand === false ? ['zustand' => 'offen', 'bestaetigt_am' => null, 'freigegeben_am' => null] : $stand,
            'antraege' => $antraege, 'profil' => self::profil($a, $person)];
    }

    /** Die eigenen Zeiten: Stand von jetzt und das Monatsblatt. */
    public function meine(Anfrage $a): Response
    {
        $s = self::sicht($a);
        if ($s['ich'] === null) {
            return Antwort::json(['person' => null, 'hinweis' => 'Dein Zugang ist noch mit keiner Person verknüpft – das richtet das Personal unter „Personen“ ein.']);
        }
        $db = $a->db();
        $jetzt = (string) $db->fetchOne("SELECT to_char(zeit._jetzt(), 'YYYY-MM-DD\"T\"HH24:MI')");
        $monat = self::monat((string) ($a->http->query->get('monat') ?? substr($jetzt, 0, 7)));
        // Laufend ist nur der jüngste offene Abschnitt, und nur solange er nicht veraltet ist; was sonst offen steht, hat das Gehen vergessen (0.4.1)
        $offene = $db->fetchAllAssociative("SELECT id, tag::text AS tag, to_char(von, 'HH24:MI') AS von, ort, zeit._offen_veraltet(person, tag, von, zeit._jetzt()) AS veraltet
            FROM zeit.abschnitt WHERE person = ? AND bis IS NULL ORDER BY tag DESC, von DESC", [$s['ich']]);
        $laufend = $offene !== [] && !$offene[0]['veraltet'] ? array_shift($offene) : null;
        unset($laufend['veraltet']);
        return Antwort::json(self::monatsblatt($a, $s['ich'], $monat) + ['name' => $s['personen'][$s['ich']]['name'], 'jetzt' => $jetzt, 'heute' => substr($jetzt, 0, 10),
            'eingestempelt' => $laufend, 'gehen_fehlt' => array_map(static fn (array $x): array => ['id' => $x['id'], 'tag' => $x['tag'], 'von' => $x['von']], $offene),
            'einstellung' => self::einstellung($a)]);
    }

    /** Übersicht des Monats für Vorgesetzte, Personal und Lohn: je Person die Summen und was auf eine Entscheidung wartet. */
    public function stand(Anfrage $a): Response
    {
        $s = self::sicht($a);
        $db = $a->db();
        $jetzt = (string) $db->fetchOne("SELECT to_char(zeit._jetzt(), 'YYYY-MM-DD')");
        $monat = self::monat((string) ($a->http->query->get('monat') ?? substr($jetzt, 0, 7)));
        $von = "$monat-01";
        $zeilen = [];
        foreach ($s['personen'] as $id => $p) {
            $sieht = $s['alle'] || in_array((string) $id, $s['team'], true);
            // Wer im Monat noch nicht oder nicht mehr beschäftigt ist, steht nicht da. Wer noch keinen Vertrag hat, steht da –
            // mit dem Vermerk „ohne Vertrag“: Sonst bliebe die Liste leer, ohne zu sagen, warum.
            if (!$sieht || ($p['eintritt'] !== null && substr((string) $p['eintritt'], 0, 7) > $monat) || ($p['austritt'] !== null && substr((string) $p['austritt'], 0, 7) < $monat)) {
                continue;
            }
            $b = self::monatsblatt($a, (string) $id, $monat);
            $zeilen[] = ['person' => (string) $id, 'name' => $p['name'], 'personalnummer' => $p['personalnummer'], 'ich' => (string) $id === $s['ich'], 'ohne_vertrag' => $p['eintritt'] === null,
                'zustaendig' => $s['pflegen'] || ((string) $id !== $s['ich'] && in_array((string) $id, $s['team'], true)),
                'summe' => $b['summe'], 'konto' => $b['konto'], 'monatsstand' => $b['monatsstand']['zustand'], 'modell' => $b['profil']['modell'],
                'antraege' => count(array_filter($b['antraege'], fn (array $x) => $x['zustand'] === 'offen')),
                'tage_offen' => count(array_filter($b['tage'], fn (array $t) => $t['braucht'] !== [])),
                'hinweise' => count(array_filter($b['tage'], fn (array $t) => $t['hinweise'] !== []))];
        }
        return Antwort::json(['monat' => $monat, 'heute' => $jetzt, 'personen' => $zeilen, 'einstellung' => self::einstellung($a),
            'personen_gesamt' => count($s['personen']),
            'darf' => ['alle' => $s['alle'], 'pflegen' => $s['pflegen'], 'freigeben' => $s['freigeben'], 'eigene' => $a->kontext()->darf('zeit.eigene.buchen'), 'verknuepft' => $s['ich'] !== null]]);
    }

    public function blatt(Anfrage $a): Response
    {
        $s = self::sicht($a);
        $id = self::person($a, $s, false);
        $monat = self::monat((string) $a->http->query->get('monat'));
        return Antwort::json(self::monatsblatt($a, $id, $monat) + ['name' => $s['personen'][$id]['name'], 'ich' => $id === $s['ich'],
            'zustaendig' => $s['pflegen'] || ($id !== $s['ich'] && in_array($id, $s['team'], true))]);
    }

    // --- Schreiben: die Person selbst -----------------------------------------------------------------------------

    public function stempeln(Anfrage $a): Response
    {
        $j = $a->json();
        if (array_diff(array_keys($j), ['art', 'ort']) !== [] || !in_array($j['art'] ?? null, ['kommen', 'gehen'], true)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'art', 'meldung' => 'kommen oder gehen']]);
        }
        // wo gearbeitet wird (F151): im Betrieb, mobil oder auf Dienstgang – gilt für den Abschnitt, der mit „Kommen“ beginnt
        if (isset($j['ort']) && !in_array($j['ort'], self::ORTE, true)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'ort', 'meldung' => 'betrieb, mobil oder dienstgang']]);
        }
        $a->db()->executeStatement('SELECT zeit.stempeln(?, ?::uuid, ?, ?)', [$a->kontext()->subjekt, self::ich($a), $j['art'], $j['ort'] ?? 'betrieb']);
        return Antwort::leer();
    }

    /** Anmerkung an einem eigenen Abschnitt (F150): freier Text der Person, leer nimmt sie weg. */
    public function anmerkungSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        if (array_keys($j) !== ['anmerkung'] || !(is_string($j['anmerkung']) || $j['anmerkung'] === null) || mb_strlen((string) $j['anmerkung']) > 200) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'anmerkung', 'meldung' => 'Text mit höchstens 200 Zeichen']]);
        }
        if (!preg_match('/^[0-9a-f-]{36}$/', $a->param('abschnitt'))) {
            throw Problem::nichtGefunden('Abschnitt unbekannt');
        }
        $a->db()->executeStatement('SELECT zeit.anmerkung_setzen(?, ?::uuid, ?::uuid, ?)', [$a->kontext()->subjekt, self::ich($a), $a->param('abschnitt'), $j['anmerkung']]);
        return Antwort::leer();
    }

    /** @return array<string, ?string> */
    private static function antrag(Anfrage $a): array
    {
        $j = $a->json();
        $fremd = array_diff(array_keys($j), ['art', 'tag', 'abschnitt', 'von', 'bis', 'ort', 'begruendung']);
        $fehler = array_map(fn ($f) => ['feld' => (string) $f, 'meldung' => 'Unbekanntes Feld'], array_values($fremd));
        foreach (['art', 'tag', 'abschnitt', 'von', 'bis', 'ort', 'begruendung'] as $f) {
            if (isset($j[$f]) && !is_string($j[$f])) {
                $fehler[] = ['feld' => $f, 'meldung' => 'Text erwartet'];
            }
        }
        if ($fehler === []) {
            if (!in_array($j['art'] ?? null, ['nachtrag', 'korrektur', 'entfernen', 'gehen'], true)) {
                $fehler[] = ['feld' => 'art', 'meldung' => 'Nachtrag, Korrektur, Entfernen oder Gehen nachtragen'];
            }
            // Gehen nachtragen (ZEIT.md Abschnitt 12): der offene Abschnitt und nur die Uhrzeit des Gehens – das Kommen ist gestempelt
            if (($j['art'] ?? null) === 'gehen') {
                foreach (['abschnitt', 'bis'] as $f) {
                    if (!isset($j[$f])) {
                        $fehler[] = ['feld' => $f, 'meldung' => 'Fehlt'];
                    }
                }
                foreach (['von', 'tag', 'ort'] as $f) {
                    if (isset($j[$f])) {
                        $fehler[] = ['feld' => $f, 'meldung' => 'Steht mit dem gestempelten Kommen fest – nachgetragen wird nur das Gehen'];
                    }
                }
            }
            if (isset($j['tag']) && (!preg_match(self::DATUM, $j['tag']) || !checkdate((int) substr($j['tag'], 5, 2), (int) substr($j['tag'], 8, 2), (int) substr($j['tag'], 0, 4)))) {
                $fehler[] = ['feld' => 'tag', 'meldung' => 'Kein gültiges Datum'];
            }
            if (isset($j['ort']) && !in_array($j['ort'], self::ORTE, true)) {
                $fehler[] = ['feld' => 'ort', 'meldung' => 'betrieb, mobil oder dienstgang'];
            }
            if (isset($j['abschnitt']) && !preg_match(self::UUID, $j['abschnitt'])) {
                $fehler[] = ['feld' => 'abschnitt', 'meldung' => 'Unbekannt'];
            }
            foreach (['von', 'bis'] as $f) {
                if (isset($j[$f]) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $j[$f])) {
                    $fehler[] = ['feld' => $f, 'meldung' => 'Uhrzeit als HH:MM'];
                }
            }
            if (trim((string) ($j['begruendung'] ?? '')) === '' || mb_strlen((string) $j['begruendung']) > 200) {
                $fehler[] = ['feld' => 'begruendung', 'meldung' => 'Fehlt oder länger als 200 Zeichen'];
            }
        }
        if ($fehler !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $fehler);
        }
        return array_filter($j, fn ($w) => $w !== null);
    }

    public function beantragen(Anfrage $a): Response
    {
        $j = self::antrag($a);
        $db = $a->db();
        $id = (string) $db->fetchOne('SELECT zeit.antrag_stellen(?, ?::uuid, ?::jsonb, false)', [$a->kontext()->subjekt, self::ich($a), json_encode($j, JSON_UNESCAPED_UNICODE)]);
        return Antwort::json(['id' => $id, 'zustand' => (string) $db->fetchOne('SELECT zustand FROM zeit.antrag WHERE id = ?', [$id])], 201);
    }

    public function bestaetigen(Anfrage $a): Response
    {
        $a->json(false);
        $a->db()->executeStatement("SELECT zeit.monat_setzen(?, ?::uuid, ?::date, 'bestaetigen')", [$a->kontext()->subjekt, self::ich($a), self::monat($a->param('monat')) . '-01']);
        return Antwort::leer();
    }

    // --- Schreiben: Vorgesetzter und Personal ---------------------------------------------------------------------

    public function entscheiden(Anfrage $a): Response
    {
        $j = $a->json();
        if (array_diff(array_keys($j), ['annehmen', 'grund']) !== [] || !is_bool($j['annehmen'] ?? null) || (isset($j['grund']) && !is_string($j['grund']))
            || (!$j['annehmen'] && trim((string) ($j['grund'] ?? '')) === '')) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'grund', 'meldung' => 'Annehmen ja oder nein; eine Ablehnung braucht einen Grund']]);
        }
        $s = self::sicht($a);
        $person = $a->db()->fetchOne('SELECT person FROM zeit.antrag WHERE id = ?::uuid', [$a->param('id')]);
        if ($person === false || !($s['pflegen'] || ((string) $person !== $s['ich'] && in_array((string) $person, $s['team'], true)))) {
            throw Problem::nichtGefunden('Antrag unbekannt');
        }
        $a->db()->executeStatement('SELECT zeit.antrag_entscheiden(?, ?::uuid, ?::boolean, ?)', [$a->kontext()->subjekt, $a->param('id'), $j['annehmen'] ? 'true' : 'false', $j['grund'] ?? null]);
        return Antwort::leer();
    }

    public function tagFreigeben(Anfrage $a): Response
    {
        $j = $a->json();
        $tag = $a->param('datum');
        if (array_diff(array_keys($j), ['art', 'genehmigt', 'grund']) !== [] || !in_array($j['art'] ?? null, ['ausserhalb', 'ueber_hoechst'], true) || !is_bool($j['genehmigt'] ?? null)
            || (isset($j['grund']) && !is_string($j['grund'])) || !preg_match(self::DATUM, $tag)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'art', 'meldung' => 'Art (ausserhalb, ueber_hoechst) und Entscheidung gehören dazu']]);
        }
        $id = self::person($a, self::sicht($a), true);
        $a->db()->executeStatement('SELECT zeit.tag_freigeben(?, ?::uuid, ?::date, ?, ?::boolean, ?)', [$a->kontext()->subjekt, $id, $tag, $j['art'], $j['genehmigt'] ? 'true' : 'false', $j['grund'] ?? null]);
        return Antwort::leer();
    }

    private function monatSetzen(Anfrage $a, string $aktion): Response
    {
        $a->json(false);
        $id = self::person($a, self::sicht($a), true);
        $a->db()->executeStatement('SELECT zeit.monat_setzen(?, ?::uuid, ?::date, ?)', [$a->kontext()->subjekt, $id, self::monat($a->param('monat')) . '-01', $aktion]);
        return Antwort::leer();
    }

    public function monatFreigeben(Anfrage $a): Response
    {
        return $this->monatSetzen($a, 'freigeben');
    }

    public function monatOeffnen(Anfrage $a): Response
    {
        return $this->monatSetzen($a, 'oeffnen');
    }

    /** Das Personal trägt für eine Person nach oder berichtigt – das gilt sofort, mit Begründung. */
    public function eintragen(Anfrage $a): Response
    {
        $j = self::antrag($a);
        $id = self::person($a, self::sicht($a), true);
        $neu = (string) $a->db()->fetchOne('SELECT zeit.antrag_stellen(?, ?::uuid, ?::jsonb, true)', [$a->kontext()->subjekt, $id, json_encode($j, JSON_UNESCAPED_UNICODE)]);
        return Antwort::json(['id' => $neu], 201);
    }

    public function profilSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        $erlaubt = ['modell', 'regeltage', 'rahmen_von', 'rahmen_bis', 'kern_von', 'kern_bis', 'korrektur', 'anfangssaldo', 'beginn'];
        $fehler = array_map(fn ($f) => ['feld' => (string) $f, 'meldung' => 'Unbekanntes Feld'], array_values(array_diff(array_keys($j), $erlaubt)));
        if (!in_array($j['modell'] ?? 'fest', ['fest', 'gleitzeit'], true)) {
            $fehler[] = ['feld' => 'modell', 'meldung' => 'fest oder gleitzeit'];
        }
        if (!in_array($j['korrektur'] ?? 'freigabe', ['sofort', 'freigabe'], true)) {
            $fehler[] = ['feld' => 'korrektur', 'meldung' => 'sofort oder freigabe'];
        }
        if (isset($j['anfangssaldo']) && !is_int($j['anfangssaldo'])) {
            $fehler[] = ['feld' => 'anfangssaldo', 'meldung' => 'Ganze Minuten'];
        }
        if (isset($j['beginn']) && (!is_string($j['beginn']) || !preg_match(self::DATUM, $j['beginn']))) {
            $fehler[] = ['feld' => 'beginn', 'meldung' => 'Kein gültiges Datum'];
        }
        foreach (['rahmen_von', 'rahmen_bis', 'kern_von', 'kern_bis'] as $f) {
            if (isset($j[$f]) && (!is_string($j[$f]) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $j[$f]))) {
                $fehler[] = ['feld' => $f, 'meldung' => 'Uhrzeit als HH:MM'];
            }
        }
        if ($fehler !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $fehler);
        }
        $id = self::person($a, self::sicht($a), true);
        $a->db()->executeStatement('SELECT zeit.profil_setzen(?, ?::uuid, ?::jsonb)', [$a->kontext()->subjekt, $id, json_encode($j)]);
        return Antwort::leer();
    }

    public function einstellungSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        $fehler = array_map(fn ($f) => ['feld' => (string) $f, 'meldung' => 'Unbekanntes Feld'],
            array_values(array_diff(array_keys($j), ['pausen', 'hoechst_minuten', 'offen_minuten', 'nacht_von', 'nacht_bis', 'monatsabschluss', 'zeitzone'])));
        if (!is_array($j['pausen'] ?? null) || !array_is_list($j['pausen'])) {
            $fehler[] = ['feld' => 'pausen', 'meldung' => 'Liste der Stufen erwartet'];
        }
        if (isset($j['hoechst_minuten']) && (!is_int($j['hoechst_minuten']) || $j['hoechst_minuten'] < 60 || $j['hoechst_minuten'] > 1440)) {
            $fehler[] = ['feld' => 'hoechst_minuten', 'meldung' => 'Ganze Minuten von 60 bis 1440'];
        }
        // wie lange ein offener Abschnitt als laufend gilt (ZEIT.md Abschnitt 11, GV2, GV8): Arbeitszeit in Minuten
        if (isset($j['offen_minuten']) && (!is_int($j['offen_minuten']) || $j['offen_minuten'] < 60 || $j['offen_minuten'] > 2880)) {
            $fehler[] = ['feld' => 'offen_minuten', 'meldung' => 'Ganze Minuten von 60 bis 2880'];
        }
        if (isset($j['monatsabschluss']) && !in_array($j['monatsabschluss'], ['aus', 'person', 'freigabe'], true)) {
            $fehler[] = ['feld' => 'monatsabschluss', 'meldung' => 'aus, person oder freigabe'];
        }
        if ($fehler !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $fehler);
        }
        $a->db()->executeStatement('SELECT zeit.einstellung_setzen(?, ?::jsonb)', [$a->kontext()->subjekt, json_encode($j)]);
        return Antwort::leer();
    }

    /** Die Vorgabe des Releases für Pausen, Höchstzeit und Nachtfenster wieder einlesen (Steuerdatei, wie F139). */
    public function einstellungVorgabe(Anfrage $a): Response
    {
        $a->json(false);
        $v = json_decode((string) file_get_contents(dirname(__DIR__) . '/regelwerk/arbeitszeit-de.json'), true);
        $a->db()->executeStatement('SELECT zeit.einstellung_setzen(?, ?::jsonb)', [$a->kontext()->subjekt,
            json_encode(array_intersect_key($v, array_flip(['pausen', 'hoechst_minuten', 'offen_minuten', 'nacht_von', 'nacht_bis'])))]);
        return Antwort::json(['stand' => $v['stand']]);
    }
}
