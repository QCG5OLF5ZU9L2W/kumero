<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Leistungen;

use Kumero\Api\V1\Problem;
use Kumero\Http\Antwort;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leistungen (LEISTUNGEN.md Abschnitt 10, F154). Wer was sieht und darf:
 *   – die Person selbst (leistungen.eigene.eintragen): ihre Leistungen eintragen und sehen, eine unbestätigte löschen;
 *   – der Vorgesetzte (leistungen.team.bestaetigen): die Leistungen der unmittelbar Unterstellten sehen, bestätigen,
 *     löschen und für sie eintragen – dann gilt die Zeile sofort als bestätigt. Über Eigenes entscheidet er nicht;
 *   – das Personal (leistungen.alle.pflegen): alles, auch den Katalog; Lohn (leistungen.alle.sehen) liest.
 * Kein Empfänger, kein Freitext an der Leistung (LE2). Antworten enthalten nur, was die Rolle sehen darf.
 */
final class WebController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const MONAT = '/^\d{4}-(0[1-9]|1[0-2])$/';
    private const ZEILE = "SELECT z.id, z.person, z.tag::text AS tag, z.leistung, z.bezeichnung, z.gruppe, z.einheit, z.menge::text AS menge, z.einzel_cent, z.betrag_cent, z.vorgang,
            (z.bestaetigt_am IS NOT NULL) AS bestaetigt, personal.api_lohn_fest(z.person, date_trunc('month', z.tag)::date) AS fest FROM leistungen.zeile z";

    /** @return array<string, array<string, mixed>> */
    private static function personen(Anfrage $a): array
    {
        return array_column($a->db()->fetchAllAssociative('SELECT id, personalnummer, name, nutzer, vorgesetzter, austritt::text AS austritt FROM personal.api_personen()'), null, 'id');
    }

    /** @return array{ich: ?string, team: list<string>, alle: bool, pflegen: bool, bestaetigen: bool, personen: array<string, array<string, mixed>>} */
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
        $bestaetigen = $k->darf('leistungen.team.bestaetigen');
        $team = $ich !== null && $bestaetigen ? array_keys(array_filter($personen, fn (array $p) => $p['vorgesetzter'] === $ich)) : [];
        return ['ich' => $ich, 'team' => array_map('strval', $team), 'alle' => $k->darf('leistungen.alle.sehen'), 'pflegen' => $k->darf('leistungen.alle.pflegen'),
                'bestaetigen' => $bestaetigen, 'personen' => $personen];
    }

    /** Darf der Aufrufer über Leistungen dieser Person entscheiden (bestätigen, löschen, für sie eintragen)? */
    private static function zustaendig(array $s, string $person): bool
    {
        return isset($s['personen'][$person]) && ($s['pflegen'] || ($person !== $s['ich'] && in_array($person, $s['team'], true)));
    }

    private static function ich(Anfrage $a): string
    {
        return self::sicht($a)['ich'] ?? throw new Problem(409, 'personal.nicht_verknuepft', 'Dein Zugang ist noch mit keiner Person verknüpft – das richtet das Personal ein');
    }

    private static function monat(Anfrage $a): string
    {
        $m = (string) ($a->http->query->get('monat') ?? date('Y-m'));
        if (!preg_match(self::MONAT, $m)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'monat', 'meldung' => 'Monat als JJJJ-MM erwartet']]);
        }
        return $m;
    }

    /** @param array<string, mixed> $z @return array<string, mixed> */
    private static function zeile(array $z): array
    {
        return ['id' => $z['id'], 'person' => $z['person'], 'tag' => $z['tag'], 'leistung' => $z['leistung'], 'bezeichnung' => $z['bezeichnung'], 'gruppe' => $z['gruppe'],
            'einheit' => $z['einheit'], 'menge' => $z['menge'], 'einzel_cent' => (int) $z['einzel_cent'], 'betrag_cent' => (int) $z['betrag_cent'], 'vorgang' => $z['vorgang'],
            'bestaetigt' => (bool) $z['bestaetigt'], 'fest' => (bool) $z['fest']];
    }

    /** Der Katalog mit dem Preis von heute; $alle: auch Stillgelegtes und die Fassungen der Preise (für die Pflege). @return list<array<string, mixed>> */
    private static function katalog(Anfrage $a, bool $alle): array
    {
        $erg = [];
        // die Fassungen der Preise in einer Abfrage – der Katalog kann groß sein (F156)
        $preise = [];
        foreach ($alle ? $a->db()->fetchAllAssociative('SELECT leistung, gueltig_ab::text AS gueltig_ab, preis_cent FROM leistungen.preis ORDER BY leistung, gueltig_ab DESC') : [] as $p) {
            $preise[$p['leistung']][] = ['gueltig_ab' => $p['gueltig_ab'], 'preis_cent' => (int) $p['preis_cent']];
        }
        foreach ($a->db()->fetchAllAssociative('SELECT l.id, l.bezeichnung, l.gruppe, l.einheit, l.preis_frei, l.stillgelegt, leistungen.preis_am(l.id, current_date) AS preis_cent
                FROM leistungen.leistung l ' . ($alle ? '' : 'WHERE NOT l.stillgelegt ') . "ORDER BY lower(coalesce(l.gruppe, '')), lower(l.bezeichnung)") as $l) {
            $e = ['id' => $l['id'], 'bezeichnung' => $l['bezeichnung'], 'gruppe' => $l['gruppe'], 'einheit' => $l['einheit'], 'preis_frei' => (bool) $l['preis_frei'],
                'stillgelegt' => (bool) $l['stillgelegt'], 'preis_cent' => $l['preis_cent'] === null ? null : (int) $l['preis_cent']];
            if ($alle) {
                $e['preise'] = $preise[$l['id']] ?? [];
            }
            $erg[] = $e;
        }
        return $erg;
    }

    // --- Lesen ------------------------------------------------------------------------------------------------

    public function meine(Anfrage $a): Response
    {
        $s = self::sicht($a);
        if ($s['ich'] === null) {
            return Antwort::json(['person' => null, 'hinweis' => 'Dein Zugang ist noch mit keiner Person verknüpft – das richtet das Personal unter „Personen“ ein.']);
        }
        $monat = self::monat($a);
        $zeilen = array_map(self::zeile(...), $a->db()->fetchAllAssociative(self::ZEILE . " WHERE z.person = ? AND date_trunc('month', z.tag) = ?::date ORDER BY z.tag, z.angelegt_am", [$s['ich'], "$monat-01"]));
        return Antwort::json(['person' => $s['ich'], 'name' => $s['personen'][$s['ich']]['name'], 'monat' => $monat, 'heute' => date('Y-m-d'), 'zeilen' => $zeilen,
            'summe_cent' => array_sum(array_column($zeilen, 'betrag_cent')), 'bestaetigt_cent' => array_sum(array_column(array_filter($zeilen, fn (array $z) => $z['bestaetigt']), 'betrag_cent')),
            'katalog' => self::katalog($a, false)]);
    }

    /** Übersicht des Monats: die Leistungen, die der Aufrufer sehen darf – alle oder die seines Teams. */
    public function stand(Anfrage $a): Response
    {
        $s = self::sicht($a);
        $monat = self::monat($a);
        $zeilen = [];
        foreach ($a->db()->fetchAllAssociative(self::ZEILE . " WHERE date_trunc('month', z.tag) = ?::date ORDER BY z.tag, z.angelegt_am", ["$monat-01"]) as $z) {
            $person = (string) $z['person'];
            if (!isset($s['personen'][$person]) || !($s['alle'] || in_array($person, $s['team'], true))) {
                continue;
            }
            $zeilen[] = self::zeile($z) + ['name' => $s['personen'][$person]['name'], 'personalnummer' => $s['personen'][$person]['personalnummer'],
                'ich' => $person === $s['ich'], 'zustaendig' => self::zustaendig($s, $person)];
        }
        $personen = [];
        foreach ($s['personen'] as $id => $p) {
            if (self::zustaendig($s, (string) $id) && ($p['austritt'] === null || $p['austritt'] >= date('Y-m-d'))) {
                $personen[] = ['id' => (string) $id, 'name' => $p['name']];
            }
        }
        return Antwort::json(['monat' => $monat, 'heute' => date('Y-m-d'), 'zeilen' => $zeilen, 'personen' => $personen, 'katalog' => self::katalog($a, $s['pflegen']),
            'darf' => ['alle' => $s['alle'], 'pflegen' => $s['pflegen'], 'bestaetigen' => $s['bestaetigen'], 'eigene' => $a->kontext()->darf('leistungen.eigene.eintragen'), 'verknuepft' => $s['ich'] !== null]]);
    }

    // --- Schreiben --------------------------------------------------------------------------------------------

    /** @param list<string> $erlaubt @return array<string, mixed> */
    private static function eingabe(Anfrage $a, array $erlaubt): array
    {
        $j = $a->json();
        $fehler = array_map(fn ($f) => ['feld' => (string) $f, 'meldung' => 'Unbekanntes Feld'], array_values(array_diff(array_keys($j), $erlaubt)));
        if (in_array('tag', $erlaubt, true)) {
            if (!is_string($j['tag'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $j['tag']) || !checkdate((int) substr($j['tag'], 5, 2), (int) substr($j['tag'], 8, 2), (int) substr($j['tag'], 0, 4))) {
                $fehler[] = ['feld' => 'tag', 'meldung' => 'Kein gültiges Datum'];
            }
            if (!is_string($j['leistung'] ?? null) || !preg_match(self::UUID, $j['leistung'])) {
                $fehler[] = ['feld' => 'leistung', 'meldung' => 'Leistung aus dem Katalog wählen'];
            }
            if (!is_string($j['menge'] ?? null) || !preg_match('/^\d{1,6}(\.\d{1,2})?$/', $j['menge']) || (float) $j['menge'] <= 0) {
                $fehler[] = ['feld' => 'menge', 'meldung' => 'Menge als Zahl größer null, höchstens zwei Nachkommastellen'];
            }
            if (isset($j['einzel_cent']) && (!is_int($j['einzel_cent']) || $j['einzel_cent'] < 0 || $j['einzel_cent'] > 100000000)) {
                $fehler[] = ['feld' => 'einzel_cent', 'meldung' => 'Betrag in ganzen Cent'];
            }
            if (isset($j['vorgang']) && (!is_string($j['vorgang']) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,39}$/', $j['vorgang']))) {
                $fehler[] = ['feld' => 'vorgang', 'meldung' => 'Neutrale Kennung: Buchstaben, Ziffern und . _ / -, höchstens 40 Zeichen – kein Name'];
            }
        }
        if ($fehler !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $fehler);
        }
        return $j;
    }

    public function meineEintragen(Anfrage $a): Response
    {
        $j = self::eingabe($a, ['tag', 'leistung', 'menge', 'einzel_cent', 'vorgang']);
        $id = (string) $a->db()->fetchOne('SELECT leistungen.zeile_eintragen(?, ?::uuid, ?::jsonb, false)', [$a->kontext()->subjekt, self::ich($a), json_encode($j, JSON_UNESCAPED_UNICODE)]);
        return Antwort::json(['id' => $id], 201);
    }

    /** Für jemanden eintragen: der Vorgesetzte für sein Team, das Personal für alle – die Zeile gilt als bestätigt (LE6). */
    public function eintragen(Anfrage $a): Response
    {
        $j = self::eingabe($a, ['person', 'tag', 'leistung', 'menge', 'einzel_cent', 'vorgang']);
        $s = self::sicht($a);
        $person = (string) ($j['person'] ?? '');
        if (!preg_match(self::UUID, $person) || !self::zustaendig($s, $person)) {
            throw Problem::nichtGefunden('Person unbekannt');
        }
        unset($j['person']);
        $id = (string) $a->db()->fetchOne('SELECT leistungen.zeile_eintragen(?, ?::uuid, ?::jsonb, true)', [$a->kontext()->subjekt, $person, json_encode($j, JSON_UNESCAPED_UNICODE)]);
        return Antwort::json(['id' => $id], 201);
    }

    /** @return array{person: string, bestaetigt: bool} */
    private static function zeileHolen(Anfrage $a): array
    {
        $id = $a->param('id');
        $z = preg_match(self::UUID, $id) ? $a->db()->fetchAssociative('SELECT person, (bestaetigt_am IS NOT NULL) AS bestaetigt FROM leistungen.zeile WHERE id = ?', [$id]) : false;
        if ($z === false) {
            throw Problem::nichtGefunden('Leistung unbekannt');
        }
        return ['person' => (string) $z['person'], 'bestaetigt' => (bool) $z['bestaetigt']];
    }

    /** Die Person löscht eine eigene Zeile, solange sie nicht bestätigt ist (LE7, LF4). */
    public function meineLoeschen(Anfrage $a): Response
    {
        $z = self::zeileHolen($a);
        if ($z['person'] !== self::ich($a)) {
            throw Problem::nichtGefunden('Leistung unbekannt');
        }
        if ($z['bestaetigt']) {
            throw new Problem(409, 'leistungen.bestaetigt', 'Die Leistung ist bestätigt – löschen kann sie dein Vorgesetzter oder das Personal');
        }
        $a->db()->executeStatement('SELECT leistungen.zeile_loeschen(?, ?::uuid)', [$a->kontext()->subjekt, $a->param('id')]);
        return Antwort::leer();
    }

    private function entscheiden(Anfrage $a, string $funktion): Response
    {
        $a->json(false);
        $z = self::zeileHolen($a);
        if (!self::zustaendig(self::sicht($a), $z['person'])) {
            throw new Problem(403, 'recht.fehlt', 'Über diese Leistung entscheidest du nicht – eigene Leistungen bestätigt dein Vorgesetzter oder das Personal');
        }
        $a->db()->executeStatement("SELECT leistungen.$funktion(?, ?::uuid)", [$a->kontext()->subjekt, $a->param('id')]);
        return Antwort::leer();
    }

    public function bestaetigen(Anfrage $a): Response
    {
        return $this->entscheiden($a, 'zeile_bestaetigen');
    }

    public function loeschen(Anfrage $a): Response
    {
        return $this->entscheiden($a, 'zeile_loeschen');
    }

    // --- Katalog ----------------------------------------------------------------------------------------------

    private function leistungSetzen(Anfrage $a, ?string $id): Response
    {
        $j = $a->json();
        $fehler = array_map(fn ($f) => ['feld' => (string) $f, 'meldung' => 'Unbekanntes Feld'], array_values(array_diff(array_keys($j), ['bezeichnung', 'gruppe', 'einheit', 'preis_frei', 'stillgelegt'])));
        foreach (['bezeichnung' => 60, 'gruppe' => 60, 'einheit' => 20] as $f => $laenge) {
            if (isset($j[$f]) && (!is_string($j[$f]) || mb_strlen($j[$f]) > $laenge)) {
                $fehler[] = ['feld' => $f, 'meldung' => "Text mit höchstens $laenge Zeichen"];
            }
        }
        if (trim((string) ($j['bezeichnung'] ?? '')) === '') {
            $fehler[] = ['feld' => 'bezeichnung', 'meldung' => 'Fehlt'];
        }
        foreach (['preis_frei', 'stillgelegt'] as $f) {
            if (isset($j[$f]) && !is_bool($j[$f])) {
                $fehler[] = ['feld' => $f, 'meldung' => 'Wahr oder falsch erwartet'];
            }
        }
        if ($fehler !== [] || ($id !== null && !preg_match(self::UUID, $id))) {
            throw $fehler !== [] ? Problem::ungueltig('Eingabe ungültig', $fehler) : Problem::nichtGefunden('Leistung unbekannt');
        }
        $neu = (string) $a->db()->fetchOne('SELECT leistungen.leistung_setzen(?, ?::uuid, ?::jsonb)', [$a->kontext()->subjekt, $id, json_encode($j, JSON_UNESCAPED_UNICODE)]);
        return $id === null ? Antwort::json(['id' => $neu], 201) : Antwort::leer();
    }

    public function leistungAnlegen(Anfrage $a): Response
    {
        return $this->leistungSetzen($a, null);
    }

    public function leistungAendern(Anfrage $a): Response
    {
        return $this->leistungSetzen($a, $a->param('id'));
    }

    public function preisSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        $fehler = [];
        if (array_diff(array_keys($j), ['gueltig_ab', 'preis_cent']) !== [] || !is_string($j['gueltig_ab'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $j['gueltig_ab'])
            || !checkdate((int) substr($j['gueltig_ab'], 5, 2), (int) substr($j['gueltig_ab'], 8, 2), (int) substr($j['gueltig_ab'], 0, 4))) {
            $fehler[] = ['feld' => 'gueltig_ab', 'meldung' => 'Datum erwartet'];
        }
        if (!is_int($j['preis_cent'] ?? null) || $j['preis_cent'] < 0 || $j['preis_cent'] > 100000000) {
            $fehler[] = ['feld' => 'preis_cent', 'meldung' => 'Preis in ganzen Cent'];
        }
        if ($fehler !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $fehler);
        }
        if (!preg_match(self::UUID, $a->param('id'))) {
            throw Problem::nichtGefunden('Leistung unbekannt');
        }
        $a->db()->executeStatement('SELECT leistungen.preis_setzen(?, ?::uuid, ?::date, ?::integer)', [$a->kontext()->subjekt, $a->param('id'), $j['gueltig_ab'], $j['preis_cent']]);
        return Antwort::leer();
    }
}
