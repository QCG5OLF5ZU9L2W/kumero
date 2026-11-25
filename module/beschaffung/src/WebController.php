<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Beschaffung;

use Kumero\Api\V1\Dienste;
use Kumero\Api\V1\Problem;
use Kumero\Http\Antwort;
use Kumero\Modul\Lager\Dienst as Lager;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bestellanforderung (BESTELLANFORDERUNG.md Stufe 1). Lesen per SELECT im eigenen Schema, Schreiben nur über die
 * Funktionen des Moduls (F44, F64). Artikel, Artikelgruppen und der Vorschlag kommen über den Dienst des Lagers – ist
 * das Lager im Buch nicht aktiv, gibt es nur Positionen „nicht fürs Lager“ (BA16).
 * Sichtbar ist einem Nutzer, was er angelegt hat und was bei ihm liegt; alles sieht, wer „alle sehen“, „freigeben“ oder
 * „bestellen“ hat. Preise sieht nur, wer freigibt oder bestellt.
 */
final class WebController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const MENGE = '/^\d{1,12}(\.\d{1,3})?$/';
    private const PREIS = '/^\d{1,11}(\.\d{1,4})?$/';
    private const ANFORDERN = 'beschaffung.bedarf.anfordern';
    private const FREIGEBEN = 'beschaffung.bedarf.freigeben';
    private const BESTELLEN = 'beschaffung.bedarf.bestellen';
    private const WOZU = ['freigabe' => self::FREIGEBEN, 'bestellen' => self::BESTELLEN, 'rueckfrage' => null];

    public function stand(Anfrage $a): Response
    {
        $db = $a->db();
        $k = $a->kontext();
        $ich = $k->subjekt;
        $darf = self::darf($a);
        $meineRollen = self::meineRollen($a);
        $namen = [];
        foreach (Dienste::nutzerAmMandanten($a) as $n) {
            $namen['nutzer:' . $n['id']] = $n['name'];
        }
        $name = fn (?string $s) => $s === null ? null : ($namen[$s] ?? 'unbekannt');

        $positionen = [];
        foreach ($db->fetchAllAssociative('SELECT id, anforderung, nr, art, artikel, meta, bezeichnung, einheit, menge::text AS menge, vermerk, zustand, grund, bestellt_menge::text AS bestellt_menge,
                    lieferant, lieferant_nummer, bestellnummer, bestellt_am::text AS bestellt_am, liefertermin::text AS liefertermin, preis::text AS preis, bestell_vermerk, bestellt_von,
                    erhalten_menge::text AS erhalten_menge, (zustand = \'bestellt\' AND liefertermin IS NOT NULL AND liefertermin < current_date) AS ueberfaellig
               FROM beschaffung.position ORDER BY anforderung, nr') as $p) {
            $positionen[$p['anforderung']][] = ['id' => $p['id'], 'nr' => (int) $p['nr'], 'art' => $p['art'], 'artikel' => $p['artikel'], 'meta' => $p['meta'], 'bezeichnung' => $p['bezeichnung'],
                'einheit' => $p['einheit'], 'menge' => self::menge($p['menge']), 'vermerk' => $p['vermerk'], 'zustand' => $p['zustand'], 'grund' => $p['grund'],
                'bestellt_menge' => $p['bestellt_menge'] === null ? null : self::menge($p['bestellt_menge']), 'lieferant' => $p['lieferant'], 'lieferant_nummer' => $p['lieferant_nummer'],
                'bestellnummer' => $p['bestellnummer'], 'bestellt_am' => $p['bestellt_am'], 'liefertermin' => $p['liefertermin'],
                'preis' => $darf['preise'] && $p['preis'] !== null ? self::preis($p['preis']) : null, 'bestell_vermerk' => $p['bestell_vermerk'], 'bestellt_von' => $name($p['bestellt_von']),
                'erhalten_menge' => self::menge($p['erhalten_menge']), 'ueberfaellig' => (bool) $p['ueberfaellig']];
        }
        $verlauf = [];
        foreach ($db->fetchAllAssociative("SELECT v.anforderung, to_char(v.zeit AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS zeit, v.subjekt, v.art, v.an_subjekt, v.an_rolle, v.wozu, v.text, p.nr AS position
               FROM beschaffung.verlauf v LEFT JOIN beschaffung.position p ON p.id = v.position ORDER BY v.anforderung, v.nr") as $v) {
            $verlauf[$v['anforderung']][] = ['zeit' => $v['zeit'], 'wer' => $name($v['subjekt']), 'art' => $v['art'], 'an' => $v['an_subjekt'] !== null ? $name($v['an_subjekt']) : $v['an_rolle'],
                'an_rolle' => $v['an_rolle'] !== null, 'wozu' => $v['wozu'], 'text' => $v['text'], 'position' => $v['position'] === null ? null : (int) $v['position']];
        }
        $liste = [];
        $offen = ['artikel' => [], 'meta' => []];
        foreach ($db->fetchAllAssociative("SELECT id, nummer, wofuer, termin::text AS termin, zustand, grund, bei_subjekt, bei_rolle, bei_wozu, angelegt_von, angelegt_am::date::text AS angelegt_am,
                    angefordert_am::date::text AS angefordert_am FROM beschaffung.anforderung ORDER BY coalesce(angefordert_am, angelegt_am) DESC, id") as $z) {
            $pos = $positionen[$z['id']] ?? [];
            // was schon angefordert oder bestellt und noch nicht da ist, zieht der Vorschlag ab (BA9) – über alle Anforderungen, auch die fremden
            if (in_array($z['zustand'], ['angefordert', 'freigegeben'], true)) {
                foreach ($pos as $p) {
                    $m = $p['zustand'] === 'offen' ? (float) $p['menge'] : ($p['zustand'] === 'bestellt' ? max(0.0, (float) $p['bestellt_menge'] - (float) $p['erhalten_menge']) : 0.0);
                    foreach (['artikel', 'meta'] as $art) {
                        if ($p[$art] !== null && $m > 0) {
                            $offen[$art][$p[$art]] = ($offen[$art][$p[$art]] ?? 0.0) + $m;
                        }
                    }
                }
            }
            $beiMirGeleitet = $z['bei_subjekt'] === $ich || ($z['bei_rolle'] !== null && in_array($z['bei_rolle'], $meineRollen, true));
            if (!$darf['alle'] && $z['angelegt_von'] !== $ich && !$beiMirGeleitet) {
                continue;
            }
            $stand = self::standVon($z['zustand'], $pos);
            $hatOffen = (bool) array_filter($pos, fn (array $p) => $p['zustand'] === 'offen');
            $niemand = $z['bei_subjekt'] === null && $z['bei_rolle'] === null;
            $liste[] = ['id' => $z['id'], 'nummer' => $z['nummer'], 'wofuer' => $z['wofuer'], 'termin' => $z['termin'], 'zustand' => $z['zustand'], 'stand' => $stand, 'grund' => $z['grund'],
                'bei' => $z['bei_subjekt'] !== null ? $name($z['bei_subjekt']) : $z['bei_rolle'], 'bei_rolle' => $z['bei_rolle'] !== null, 'bei_wozu' => $z['bei_wozu'],
                'bei_mir' => $beiMirGeleitet || ($niemand && (($z['zustand'] === 'angefordert' && $darf['freigeben']) || ($z['zustand'] === 'freigegeben' && $hatOffen && $darf['bestellen'])
                    || ($z['zustand'] === 'entwurf' && $z['angelegt_von'] === $ich))),
                'uebernehmbar' => $z['bei_rolle'] !== null && in_array($z['bei_rolle'], $meineRollen, true),
                'von' => $name($z['angelegt_von']), 'eigene' => $z['angelegt_von'] === $ich, 'angelegt_am' => $z['angelegt_am'], 'angefordert_am' => $z['angefordert_am'],
                'ueberfaellig' => (bool) array_filter($pos, fn (array $p) => $p['ueberfaellig']),
                'positionen' => $pos, 'verlauf' => $verlauf[$z['id']] ?? []];
        }

        $erg = ['heute' => (string) $db->fetchOne('SELECT current_date::text'), 'anforderungen' => $liste, 'darf' => $darf, 'lager' => false, 'artikel' => [], 'gruppen' => [], 'vorschlag' => [],
            'personen' => [], 'rollen' => [], 'lieferanten' => [], 'bezug' => new \stdClass()];
        $lager = self::lager($a);
        if ($lager !== null) {
            $erg['lager'] = true;
            $erg['artikel'] = $lager->artikel();
            $erg['gruppen'] = $lager->gruppen();
            if ($darf['anfordern']) {
                $erg['vorschlag'] = self::vorschlag($lager->bedarf(), $offen);
            }
        }
        // an wen sich weiterleiten lässt: wer im Buch anfordern, freigeben oder bestellen darf – und welche Rollen das tragen
        if ($darf['anfordern'] || $darf['freigeben'] || $darf['bestellen']) {
            foreach (['freigabe' => [self::FREIGEBEN], 'bestellen' => [self::BESTELLEN], 'rueckfrage' => [self::ANFORDERN, self::FREIGEBEN, self::BESTELLEN]] as $wozu => $rechte) {
                $b = Dienste::berechtigte($db, $rechte);
                foreach ($b['subjekte'] as $s => $rollen) {
                    if (isset($namen[$s])) {
                        $erg['personen'][$s] ??= ['id' => $s, 'name' => $namen[$s], 'wozu' => []];
                        $erg['personen'][$s]['wozu'][] = $wozu;
                    }
                }
                foreach ($b['rollen'] as $r) {
                    $erg['rollen'][$r] ??= ['name' => $r, 'wozu' => []];
                    $erg['rollen'][$r]['wozu'][] = $wozu;
                }
            }
            $erg['personen'] = array_values($erg['personen']);
            usort($erg['personen'], fn (array $x, array $y) => strcasecmp($x['name'], $y['name']));
            $erg['rollen'] = array_values($erg['rollen']);
        }
        if ($darf['bestellen']) {
            $erg['lieferanten'] = array_map('strval', $db->fetchFirstColumn('SELECT lieferant FROM beschaffung.position WHERE lieferant IS NOT NULL GROUP BY lieferant ORDER BY max(bestellt_am) DESC, lieferant LIMIT 200'));
            // Hinweis, keine Vorgabe (BA22, BA29): wo der Artikel zuletzt bestellt wurde, mit Preis und Datum
            $bezug = [];
            foreach ($db->fetchAllAssociative('SELECT DISTINCT ON (artikel) artikel, lieferant, lieferant_nummer, preis::text AS preis, bestellt_am::text AS am
                   FROM beschaffung.position WHERE artikel IS NOT NULL AND bestellt_menge IS NOT NULL ORDER BY artikel, bestellt_am DESC, id') as $z) {
                $bezug[$z['artikel']] = ['lieferant' => $z['lieferant'], 'lieferant_nummer' => $z['lieferant_nummer'], 'preis' => $z['preis'] === null ? null : self::preis($z['preis']), 'am' => $z['am']];
            }
            $erg['bezug'] = $bezug === [] ? new \stdClass() : $bezug;
        }
        return Antwort::json($erg);
    }

    public function anlegen(Anfrage $a): Response
    {
        $id = (string) $a->db()->fetchOne('SELECT beschaffung.anforderung_setzen(?, NULL, ?::jsonb)', [$a->kontext()->subjekt, self::json(self::pruefen($a, $a->json()))]);
        return Antwort::json(['id' => $id], 201);
    }

    public function aendern(Anfrage $a): Response
    {
        $z = self::holen($a, $a->param('id'));
        $darf = self::darf($a);
        if (!$darf['freigeben'] && !($darf['anfordern'] && $z['angelegt_von'] === $a->kontext()->subjekt)) {
            throw self::verboten('Ändern kann die Anforderung, wer sie angelegt hat oder wer freigeben darf');
        }
        $a->db()->fetchOne('SELECT beschaffung.anforderung_setzen(?, ?::uuid, ?::jsonb, ?::boolean)', [$a->kontext()->subjekt, $z['id'], self::json(self::pruefen($a, $a->json())), $darf['freigeben'] ? 'true' : 'false']);
        return Antwort::json(['id' => $z['id']]);
    }

    public function anfordern(Anfrage $a): Response
    {
        $z = self::holen($a, $a->param('id'));
        $nummer = (string) $a->db()->fetchOne('SELECT beschaffung.anfordern(?, ?::uuid)', [$a->kontext()->subjekt, $z['id']]);
        return Antwort::json(['id' => $z['id'], 'nummer' => $nummer]);
    }

    public function zurueckziehen(Anfrage $a): Response
    {
        $z = self::holen($a, $a->param('id'));
        $darf = self::darf($a);
        if (!$darf['freigeben'] && !($darf['anfordern'] && $z['angelegt_von'] === $a->kontext()->subjekt)) {
            throw self::verboten('Zurückziehen kann die Anforderung, wer sie angelegt hat oder wer freigeben darf');
        }
        $erg = (string) $a->db()->fetchOne('SELECT beschaffung.zurueckziehen(?, ?::uuid, ?::boolean)', [$a->kontext()->subjekt, $z['id'], $darf['freigeben'] ? 'true' : 'false']);
        return Antwort::json(['id' => $z['id'], 'ergebnis' => $erg]);
    }

    public function freigeben(Anfrage $a): Response
    {
        $z = self::holen($a, $a->param('id'));
        $a->db()->executeStatement('SELECT beschaffung.entscheiden(?, ?::uuid, NULL)', [$a->kontext()->subjekt, $z['id']]);
        return Antwort::leer();
    }

    public function ablehnen(Anfrage $a): Response
    {
        $z = self::holen($a, $a->param('id'));
        $grund = self::text($a->json(), 'grund', 300, true);
        $a->db()->executeStatement('SELECT beschaffung.entscheiden(?, ?::uuid, ?)', [$a->kontext()->subjekt, $z['id'], $grund]);
        return Antwort::leer();
    }

    /** Weiterleiten an eine Person ({person}) oder an eine Rolle ({rolle}) – zur Freigabe, zur Rückfrage oder zum Bestellen (BA15, BA17). */
    public function weiterleiten(Anfrage $a): Response
    {
        $z = self::holen($a, $a->param('id'));
        $darf = self::darf($a);
        if (!$darf['anfordern'] && !$darf['freigeben'] && !$darf['bestellen']) {
            throw self::verboten('Weiterleiten kann, wer anfordern, freigeben oder bestellen darf');
        }
        $j = $a->json();
        $wozu = $j['wozu'] ?? null;
        if (!is_string($wozu) || !array_key_exists($wozu, self::WOZU)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'wozu', 'meldung' => 'Erlaubt: freigabe, rueckfrage, bestellen']]);
        }
        $person = $j['person'] ?? null;
        $rolle = $j['rolle'] ?? null;
        if (($person === null) === ($rolle === null) || ($person !== null && !is_string($person)) || ($rolle !== null && !is_string($rolle))) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'person', 'meldung' => 'Genau eine Person oder eine Rolle erwartet']]);
        }
        $b = Dienste::berechtigte($a->db(), self::WOZU[$wozu] === null ? [self::ANFORDERN, self::FREIGEBEN, self::BESTELLEN] : [self::WOZU[$wozu]]);
        $kann = ['freigabe' => 'darf nicht freigeben', 'bestellen' => 'darf nicht bestellen', 'rueckfrage' => 'arbeitet nicht mit Anforderungen'][$wozu];
        if ($person !== null && !isset($b['subjekte'][$person])) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'person', 'meldung' => "Diese Person $kann"]]);
        }
        if ($person === $a->kontext()->subjekt) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'person', 'meldung' => 'An sich selbst lässt sich nicht weiterleiten']]);
        }
        if ($rolle !== null && !in_array($rolle, $b['rollen'], true)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'rolle', 'meldung' => "Diese Rolle $kann"]]);
        }
        $a->db()->executeStatement('SELECT beschaffung.weiterleiten(?, ?::uuid, ?, ?, ?, ?)', [$a->kontext()->subjekt, $z['id'], $person, $rolle, $wozu, self::text($j, 'text', 300, false)]);
        return Antwort::leer();
    }

    public function uebernehmen(Anfrage $a): Response
    {
        $z = self::holen($a, $a->param('id'));
        if ($z['bei_rolle'] !== null && !in_array($z['bei_rolle'], self::meineRollen($a), true)) {
            throw self::verboten("Übernehmen kann die Anforderung, wer die Rolle „{$z['bei_rolle']}“ hat");
        }
        $a->db()->executeStatement('SELECT beschaffung.uebernehmen(?, ?::uuid)', [$a->kontext()->subjekt, $z['id']]);
        return Antwort::leer();
    }

    /**
     * Eine Position als bestellt abhaken (BA21, BA23): Menge (entscheidet der Einkäufer, BA27), Lieferant, dessen
     * Artikelnummer, Bestellnummer des Shops, Bestelldatum, Liefertermin, Preis je Einheit, Vermerk. Für eine Position
     * fürs Lager steht spätestens jetzt der Artikel fest (BA12): `artikel` wählt einen, `neuer_artikel` legt ihn an,
     * `gtin` ergänzt sie, wo keine steht. `rest` lässt eine Menge als neue offene Position stehen.
     */
    public function bestellen(Anfrage $a): Response
    {
        $db = $a->db();
        $subjekt = $a->kontext()->subjekt;
        $p = self::position($a, $a->param('id'));
        $j = $a->json();
        $f = [];
        $x = ['menge' => self::mengeLesen($j['menge'] ?? null, 'menge', $f), 'lieferant' => self::text($j, 'lieferant', 200, true, $f), 'lieferant_nummer' => self::text($j, 'lieferant_nummer', 200, false, $f),
              'bestellnummer' => self::text($j, 'bestellnummer', 100, false, $f), 'vermerk' => self::text($j, 'vermerk', 200, false, $f),
              'bestellt_am' => self::datum($j['bestellt_am'] ?? (string) $db->fetchOne('SELECT current_date::text'), 'bestellt_am', $f),
              'liefertermin' => ($j['liefertermin'] ?? null) === null ? null : self::datum($j['liefertermin'], 'liefertermin', $f),
              'rest' => ($j['rest'] ?? null) === null ? null : self::mengeLesen($j['rest'], 'rest', $f)];
        if ($x['bestellt_am'] !== null && $x['bestellt_am'] > (string) $db->fetchOne('SELECT current_date::text')) {
            $f[] = ['feld' => 'bestellt_am', 'meldung' => 'Das Bestelldatum liegt nicht in der Zukunft'];
        }
        $x['preis'] = $j['preis'] ?? null;
        if ($x['preis'] !== null && (!is_string($x['preis']) || !preg_match(self::PREIS, $x['preis']))) {
            $f[] = ['feld' => 'preis', 'meldung' => 'Preis je Einheit als Dezimal-String mit höchstens vier Nachkommastellen erwartet'];
        }
        $gtin = $j['gtin'] ?? null;
        if ($gtin !== null && (!is_string($gtin) || !preg_match('/^\d{8,14}$/', $gtin))) {
            $f[] = ['feld' => 'gtin', 'meldung' => 'GTIN: 8 bis 14 Ziffern erwartet – die Nummer unter dem Strichcode der Packung'];
        }
        $wahl = $j['artikel'] ?? null;
        $neu = $j['neuer_artikel'] ?? null;
        if ($wahl !== null && (!is_string($wahl) || !preg_match(self::UUID, $wahl))) {
            $f[] = ['feld' => 'artikel', 'meldung' => 'Artikel erwartet'];
        }
        if ($neu !== null && (!is_array($neu) || $wahl !== null)) {
            $f[] = ['feld' => 'neuer_artikel', 'meldung' => 'Entweder einen Artikel wählen oder einen neuen anlegen'];
        }
        if ($p['art'] === 'frei' && ($wahl !== null || $neu !== null || $gtin !== null)) {
            $f[] = ['feld' => 'artikel', 'meldung' => 'Eine Position „nicht fürs Lager“ hat keinen Artikel'];
        }
        if ($f !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        $lager = $p['art'] === 'lager' ? self::lager($a) : null;
        if ($p['art'] === 'lager' && $lager === null) {
            throw self::konflikt('Die Position ist fürs Lager, das Modul Lager ist in diesem Buch aber nicht aktiv');
        }
        $rest = $db->transactional(function () use ($db, $subjekt, $p, $x, $lager, $wahl, $neu, $gtin): ?string {
            if ($lager !== null) {
                $art = $neu !== null ? $lager->artikelAnlegen($neu) : (($wahl ?? $p['artikel']) === null ? null : $lager->einArtikel($wahl ?? $p['artikel']));
                if ($art === null) {
                    throw $wahl === null ? self::konflikt('Zu dieser Position fehlt der Artikel – wähle einen oder lege ihn an') : Problem::ungueltig('Eingabe ungültig', [['feld' => 'artikel', 'meldung' => 'Artikel unbekannt']]);
                }
                if ($p['meta'] !== null && $art['meta_artikel'] !== $p['meta']) {
                    throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'artikel', 'meldung' => 'Der Artikel gehört nicht zur angeforderten Artikelgruppe']]);
                }
                if ($art['in_stuecken'] && ((float) $x['menge'] !== floor((float) $x['menge']) || ($x['rest'] !== null && (float) $x['rest'] !== floor((float) $x['rest'])))) {
                    throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'menge', 'meldung' => 'Der Artikel wird in Stücken gezählt: ganze Zahl erwartet']]);
                }
                if ($gtin !== null && $neu === null) {
                    $lager->gtinErgaenzen($art['id'], $gtin);
                }
                if ($x['lieferant_nummer'] !== null) {
                    $lager->bezugsquelleMerken($art['id'], $x['lieferant'], $x['lieferant_nummer']);
                }
                $x += ['artikel' => $art['id'], 'bezeichnung' => $art['bezeichnung'], 'einheit' => $art['einheit']];
            }
            $r = $db->fetchOne('SELECT beschaffung.bestellen(?, ?::uuid, ?::jsonb)', [$subjekt, $p['id'], self::json($x)]);
            return $r === null || $r === false ? null : (string) $r;
        });
        return Antwort::json(['position' => $p['id'], 'rest' => $rest]);
    }

    /** Einen falsch gesetzten Haken zurücknehmen – solange nichts als erhalten gemeldet ist. */
    public function zuruecknehmen(Anfrage $a): Response
    {
        $p = self::position($a, $a->param('id'));
        $a->db()->executeStatement('SELECT beschaffung.bestellung_zuruecknehmen(?, ?::uuid)', [$a->kontext()->subjekt, $p['id']]);
        return Antwort::leer();
    }

    public function streichen(Anfrage $a): Response
    {
        $p = self::position($a, $a->param('id'));
        $a->db()->executeStatement('SELECT beschaffung.position_streichen(?, ?::uuid, ?)', [$a->kontext()->subjekt, $p['id'], self::text($a->json(), 'grund', 200, true)]);
        return Antwort::leer();
    }

    /**
     * Den Eingang von Hand melden (BA16): {menge?, am?, fertig?}. Wer bestellen darf, meldet für alle; wer anfordern
     * darf, für die eigene Anforderung. Für eine Position fürs Lager bucht das nichts – den Zugang bucht der
     * Wareneingang (Stufe 2 verbindet beides).
     */
    public function erhalten(Anfrage $a): Response
    {
        $p = self::position($a, $a->param('id'));
        $darf = self::darf($a);
        if (!$darf['bestellen'] && !($darf['anfordern'] && $p['angelegt_von'] === $a->kontext()->subjekt)) {
            throw self::verboten('Den Eingang meldet, wer bestellen darf – oder wer die Anforderung angelegt hat');
        }
        $j = $a->json();
        $f = [];
        $menge = ($j['menge'] ?? null) === null ? null : self::mengeLesen($j['menge'], 'menge', $f);
        $am = self::datum($j['am'] ?? (string) $a->db()->fetchOne('SELECT current_date::text'), 'am', $f);
        if (!is_bool($j['fertig'] ?? false)) {
            $f[] = ['feld' => 'fertig', 'meldung' => 'true oder false erwartet'];
        }
        if ($f !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        $zustand = (string) $a->db()->fetchOne('SELECT beschaffung.erhalten(?, ?::uuid, ?::numeric, ?::date, ?::boolean)', [$a->kontext()->subjekt, $p['id'], $menge, $am, ($j['fertig'] ?? false) ? 'true' : 'false']);
        return Antwort::json(['position' => $p['id'], 'zustand' => $zustand]);
    }

    // ------------------------------------------------------------------------------------------------------------

    private static function verboten(string $text): Problem
    {
        return new Problem(403, 'recht.fehlt', $text);
    }

    private static function konflikt(string $text): Problem
    {
        return new Problem(409, 'zustand', $text);
    }

    /** @return array{alle: bool, anfordern: bool, freigeben: bool, bestellen: bool, preise: bool} */
    private static function darf(Anfrage $a): array
    {
        $k = $a->kontext();
        $f = $k->darf(self::FREIGEBEN);
        $b = $k->darf(self::BESTELLEN);
        return ['alle' => $k->darf('beschaffung.vorgang.alle') || $f || $b, 'anfordern' => $k->darf(self::ANFORDERN), 'freigeben' => $f, 'bestellen' => $b, 'preise' => $f || $b];
    }

    /** Rollen des Angemeldeten, die mit Anforderungen arbeiten – an sie kann weitergeleitet sein. @return list<string> */
    private static function meineRollen(Anfrage $a): array
    {
        return Dienste::berechtigte($a->db(), [self::ANFORDERN, self::FREIGEBEN, self::BESTELLEN])['subjekte'][$a->kontext()->subjekt] ?? [];
    }

    /** Die Anforderung, wenn der Angemeldete sie sehen darf – sonst gibt es sie für ihn nicht. @return array<string, mixed> */
    private static function holen(Anfrage $a, string $id): array
    {
        $z = preg_match(self::UUID, $id) ? $a->db()->fetchAssociative('SELECT id, zustand, angelegt_von, bei_subjekt, bei_rolle FROM beschaffung.anforderung WHERE id = ?', [$id]) : false;
        $ich = $a->kontext()->subjekt;
        if ($z === false || (!self::darf($a)['alle'] && $z['angelegt_von'] !== $ich && $z['bei_subjekt'] !== $ich && !($z['bei_rolle'] !== null && in_array($z['bei_rolle'], self::meineRollen($a), true)))) {
            throw Problem::nichtGefunden('Anforderung unbekannt');
        }
        return $z;
    }

    /** Eine Position mit dem, was die Anwendung von ihr wissen muss. @return array<string, mixed> */
    private static function position(Anfrage $a, string $id): array
    {
        $p = preg_match(self::UUID, $id) ? $a->db()->fetchAssociative('SELECT p.id, p.anforderung, p.art, p.artikel, p.meta, a.angelegt_von FROM beschaffung.position p JOIN beschaffung.anforderung a ON a.id = p.anforderung WHERE p.id = ?', [$id]) : false;
        if ($p === false) {
            throw Problem::nichtGefunden('Position unbekannt');
        }
        self::holen($a, (string) $p['anforderung']);
        return $p;
    }

    /** Der Dienst des Lagers, wenn das Modul im Buch aktiv ist. */
    private static function lager(Anfrage $a): ?Lager
    {
        return isset(Dienste::aktiveModule($a->db())['lager']) ? new Lager($a->db(), $a->kontext()->subjekt) : null;
    }

    /**
     * Zustand in Worten, wie ihn die Liste zeigt: bis zur Freigabe der gespeicherte, danach aus den Positionen.
     *
     * @param list<array<string, mixed>> $pos
     */
    private static function standVon(string $zustand, array $pos): string
    {
        if ($zustand !== 'freigegeben') {
            return $zustand;
        }
        $z = array_count_values(array_column($pos, 'zustand')) + ['offen' => 0, 'bestellt' => 0, 'erhalten' => 0, 'gestrichen' => 0];
        $teil = (bool) array_filter($pos, fn (array $p) => $p['zustand'] === 'bestellt' && (float) $p['erhalten_menge'] > 0);
        return match (true) {
            $z['offen'] === 0 && $z['bestellt'] === 0 => 'erledigt',
            $z['erhalten'] > 0 || $teil => 'teilweise_erhalten',
            $z['offen'] === 0 => 'bestellt',
            $z['bestellt'] > 0 => 'teilweise_bestellt',
            default => 'freigegeben',
        };
    }

    /**
     * Vorschlag aus dem Bestand, abzüglich dessen, was schon angefordert oder bestellt und noch nicht da ist (BA9).
     *
     * @param array{artikel: list<array<string, mixed>>, meta: list<array<string, mixed>>} $bedarf
     * @param array{artikel: array<string, float>, meta: array<string, float>} $offen
     * @return list<array<string, mixed>>
     */
    private static function vorschlag(array $bedarf, array $offen): array
    {
        $erg = [];
        foreach ([['artikel', 'artikel'], ['meta', 'meta']] as [$liste, $schluessel]) {
            foreach ($bedarf[$liste] as $z) {
                $o = $offen[$schluessel][$z[$schluessel]] ?? 0.0;
                if ($liste === 'meta') {   // was für einen Artikel der Gruppe unterwegs ist, zählt für die Gruppe
                    foreach ($z['mitglieder'] as $mitglied) {
                        $o += $offen['artikel'][$mitglied['artikel']] ?? 0.0;
                    }
                }
                $rest = max(0.0, round((float) $z['vorschlag'] - $o, 3));
                $erg[] = [$schluessel => $z[$schluessel], 'gruppe' => $liste === 'meta', 'bezeichnung' => $z['bezeichnung'], 'nummer' => $z['nummer'] ?? null, 'einheit' => $z['einheit'],
                    'verfuegbar' => $z['verfuegbar'], 'mindest' => $z['mindest'], 'soll' => $z['soll'], 'fehlt' => $z['vorschlag'],
                    'unterwegs' => self::menge(number_format($o, 3, '.', '')), 'vorschlag' => self::menge(number_format($rest, 3, '.', ''))];
            }
        }
        usort($erg, fn (array $x, array $y) => strcasecmp($x['bezeichnung'], $y['bezeichnung']));
        return $erg;
    }

    /**
     * Kopf und Positionen einer Anforderung prüfen. Bezeichnung und Einheit eines Artikels oder einer Artikelgruppe
     * kommen vom Lager, nicht aus der Eingabe.
     *
     * @param array<string, mixed> $j
     * @return array<string, mixed>
     */
    private static function pruefen(Anfrage $a, array $j): array
    {
        $f = [];
        $x = ['wofuer' => self::text($j, 'wofuer', 200, true, $f), 'termin' => ($j['termin'] ?? null) === null ? null : self::datum($j['termin'], 'termin', $f), 'positionen' => []];
        $pos = $j['positionen'] ?? [];
        if (!is_array($pos) || !array_is_list($pos) || count($pos) > 200) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'positionen', 'meldung' => 'Liste mit höchstens 200 Positionen erwartet']]);
        }
        $lager = self::lager($a);
        $artikel = $lager === null ? [] : array_column($lager->artikel(), null, 'id');
        $gruppen = $lager === null ? [] : array_column($lager->gruppen(), null, 'id');
        foreach ($pos as $n => $p) {
            $feld = fn (string $name) => "positionen[$n].$name";
            if (!is_array($p) || !in_array($p['art'] ?? null, ['lager', 'frei'], true)) {
                $f[] = ['feld' => $feld('art'), 'meldung' => 'Erlaubt: lager, frei'];
                continue;
            }
            $z = ['art' => $p['art'], 'artikel' => null, 'meta' => null, 'vermerk' => self::text($p, 'vermerk', 200, false, $f, $feld('vermerk')), 'menge' => self::mengeLesen($p['menge'] ?? null, $feld('menge'), $f)];
            $art = $p['artikel'] ?? null;
            $meta = $p['meta'] ?? null;
            if ($p['art'] === 'lager' && $lager === null) {
                $f[] = ['feld' => $feld('art'), 'meldung' => 'Das Modul Lager ist in diesem Buch nicht aktiv – möglich sind nur Positionen „nicht fürs Lager“'];
            } elseif ($p['art'] === 'frei' && ($art !== null || $meta !== null)) {
                $f[] = ['feld' => $feld('artikel'), 'meldung' => 'Eine Position „nicht fürs Lager“ hat keinen Artikel'];
            } elseif ($art !== null && $meta !== null) {
                $f[] = ['feld' => $feld('artikel'), 'meldung' => 'Entweder ein Artikel oder eine Artikelgruppe'];
            } elseif ($art !== null) {
                if (!is_string($art) || !isset($artikel[$art])) {
                    $f[] = ['feld' => $feld('artikel'), 'meldung' => 'Artikel unbekannt oder stillgelegt'];
                } else {
                    $z = ['artikel' => $art, 'bezeichnung' => $artikel[$art]['bezeichnung'], 'einheit' => $artikel[$art]['einheit']] + $z;
                    if ($artikel[$art]['in_stuecken'] && $z['menge'] !== null && (float) $z['menge'] !== floor((float) $z['menge'])) {
                        $f[] = ['feld' => $feld('menge'), 'meldung' => 'Der Artikel wird in Stücken gezählt: ganze Zahl erwartet'];
                    }
                }
            } elseif ($meta !== null) {
                if (!is_string($meta) || !isset($gruppen[$meta])) {
                    $f[] = ['feld' => $feld('meta'), 'meldung' => 'Artikelgruppe unbekannt oder stillgelegt'];
                } else {
                    $z = ['meta' => $meta, 'bezeichnung' => $gruppen[$meta]['bezeichnung'], 'einheit' => $gruppen[$meta]['einheit']] + $z;
                }
            }
            if (!isset($z['bezeichnung'])) {
                $z['bezeichnung'] = self::text($p, 'bezeichnung', 200, true, $f, $feld('bezeichnung'));
                $z['einheit'] = self::text($p, 'einheit', 20, true, $f, $feld('einheit'));
            }
            $x['positionen'][] = $z;
        }
        if ($f !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        return $x;
    }

    /**
     * Ein Textfeld lesen. Mit $f sammelt es den Fehler, ohne $f wirft es ihn gleich.
     *
     * @param array<string, mixed> $j
     * @param ?list<array{feld: string, meldung: string}> $f
     */
    private static function text(array $j, string $feld, int $max, bool $pflicht, ?array &$f = null, ?string $name = null): ?string
    {
        $w = $j[$feld] ?? null;
        $fehler = null;
        if ($w === null || (is_string($w) && trim($w) === '')) {
            $fehler = $pflicht ? 'Pflichtfeld' : null;
            $w = null;
        } elseif (!is_string($w) || mb_strlen(trim($w)) > $max) {
            $fehler = "Text mit höchstens $max Zeichen erwartet";
            $w = null;
        }
        if ($fehler !== null) {
            $eintrag = ['feld' => $name ?? $feld, 'meldung' => $fehler];
            if (func_num_args() < 5) {
                throw Problem::ungueltig('Eingabe ungültig', [$eintrag]);
            }
            $f[] = $eintrag;
        }
        return $w === null ? null : trim($w);
    }

    /** @param list<array{feld: string, meldung: string}> $f */
    private static function mengeLesen(mixed $w, string $feld, array &$f): ?string
    {
        if (!is_string($w) || !preg_match(self::MENGE, $w) || (float) $w <= 0) {
            $f[] = ['feld' => $feld, 'meldung' => 'Menge größer als null als Dezimal-String mit höchstens drei Nachkommastellen erwartet'];
            return null;
        }
        return $w;
    }

    /** @param list<array{feld: string, meldung: string}> $f */
    private static function datum(mixed $w, string $feld, array &$f): ?string
    {
        $d = is_string($w) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $w) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $w) : false;
        if ($d === false || $d->format('Y-m-d') !== $w) {
            $f[] = ['feld' => $feld, 'meldung' => 'Datum als JJJJ-MM-TT erwartet'];
            return null;
        }
        return $w;
    }

    /** „12.000“ → „12“, „0.500“ → „0.5“ */
    private static function menge(string $w): string
    {
        return str_contains($w, '.') ? rtrim(rtrim($w, '0'), '.') : $w;
    }

    /** „4.5000“ → „4.50“: mindestens zwei, höchstens vier Nachkommastellen */
    private static function preis(string $w): string
    {
        [$g, $n] = explode('.', $w . (str_contains($w, '.') ? '' : '.'));
        return $g . '.' . str_pad(rtrim($n, '0'), 2, '0');
    }

    /** @param array<mixed> $w */
    private static function json(array $w): string
    {
        return json_encode($w, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
