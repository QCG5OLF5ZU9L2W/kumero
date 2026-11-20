<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Lager;

use Kumero\Api\V1\Dienste;
use Kumero\Api\V1\Problem;
use Kumero\Http\Antwort;
use Kumero\Modul\Arbeitsvorrat\Dienst as Vorrat;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lager eines Buchs (LAGER.md Stufe 1). Lesen per SELECT im eigenen Schema, Schreiben nur über die Funktionen des
 * Moduls (F44, F64) – sie tragen die Regeln: kein negativer Bestand, abgelaufene und gesperrte Chargen sind nicht
 * entnehmbar, Betäubungsmittel werden nicht geführt. Positionen eines Belegs kommen über den Dienst des Arbeitsvorrats.
 */
final class WebController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const MENGE = '/^\d{1,12}(\.\d{1,3})?$/';
    private const ARTEN = ['medizinprodukt', 'arzneimittel', 'verbrauch', 'sonstiges'];
    private const FUEHRUNG = ['ohne', 'charge', 'serie'];
    private const GRUENDE_ZUGANG = ['anfangsbestand', 'muster', 'berichtigung'];

    /** Einstellungen, Lagerorte und alle Artikel mit ihren Chargen und Beständen. */
    public function stand(Anfrage $a): Response
    {
        $db = $a->db();
        $e = $db->fetchAssociative('SELECT warn_tage, zwecke::text AS zwecke, current_date::text AS heute, bewertung, buchfuehrung, preise, vorratskonten, bestandskonto, aufwandskonto FROM lager.einstellung');
        $warn = (int) $e['warn_tage'];
        $chargen = [];
        foreach ($db->fetchAllAssociative("SELECT c.id, c.artikel, c.bezeichnung, c.verfall::text AS verfall, c.gesperrt, c.sperrgrund,
                    (c.verfall IS NOT NULL AND c.verfall < current_date) AS abgelaufen, (c.verfall - current_date) AS tage,
                    coalesce((SELECT jsonb_agg(jsonb_build_object('ort', s.ort::text, 'menge', lager._menge(s.menge)) ORDER BY o.name)
                                FROM lager.bestand s JOIN lager.ort o ON o.id = s.ort WHERE s.charge = c.id AND s.menge > 0), '[]')::text AS orte,
                    coalesce((SELECT sum(s.menge) FROM lager.bestand s WHERE s.charge = c.id), 0)::text AS menge
               FROM lager.charge c ORDER BY c.verfall NULLS LAST, c.angelegt_am, c.id") as $c) {
            if ((float) $c['menge'] <= 0) {
                continue;   // leere Chargen stehen in der Rückverfolgung, nicht im Bestand
            }
            $zustand = $c['abgelaufen'] ? 'abgelaufen' : ($c['gesperrt'] ? 'gesperrt' : ($c['tage'] !== null && (int) $c['tage'] <= $warn ? 'laeuft_ab' : 'frei'));
            $chargen[$c['artikel']][] = ['id' => $c['id'], 'bezeichnung' => $c['bezeichnung'], 'verfall' => $c['verfall'], 'zustand' => $zustand,
                'sperrgrund' => $c['sperrgrund'], 'tage' => $c['tage'] === null ? null : (int) $c['tage'], 'menge' => self::menge($c['menge']),
                'orte' => json_decode((string) $c['orte'], true), 'vorschlag' => false];
        }
        $artikel = [];
        $zahlen = ['abgelaufen' => 0, 'laeuft_ab' => 0, 'gesperrt' => 0, 'unter_mindest' => 0];
        foreach ($db->fetchAllAssociative('SELECT id, nummer, bezeichnung, art, einheit, fuehrung, verfall_pflicht, btm, gtin, mindest::text AS mindest, soll::text AS soll, standardort, lagerbedingung, stillgelegt,
                    lager.vorschlag(id) AS vorschlag FROM lager.artikel ORDER BY stillgelegt, lower(bezeichnung), id') as $z) {
            $x = self::artikel($z);
            $x['chargen'] = $chargen[$z['id']] ?? [];
            $summe = 0.0;
            $naechster = null;
            $arten = [];
            foreach ($x['chargen'] as $n => $c) {
                $x['chargen'][$n]['vorschlag'] = $c['id'] === $z['vorschlag'];
                $summe += (float) $c['menge'];
                $arten[$c['zustand']] = ($arten[$c['zustand']] ?? 0) + 1;
                if ($c['verfall'] !== null && ($naechster === null || $c['verfall'] < $naechster)) {
                    $naechster = $c['verfall'];
                }
            }
            foreach (['abgelaufen', 'laeuft_ab', 'gesperrt'] as $k) {
                $zahlen[$k] += $arten[$k] ?? 0;
            }
            $x['bestand'] = self::menge(number_format($summe, 3, '.', ''));
            $x['naechster_verfall'] = $naechster;
            $x['unter_mindest'] = $z['mindest'] !== null && !$z['stillgelegt'] && $summe < (float) $z['mindest'];
            $zahlen['unter_mindest'] += $x['unter_mindest'] ? 1 : 0;
            $x['zustand'] = isset($arten['abgelaufen']) ? 'abgelaufen' : (isset($arten['gesperrt']) ? 'gesperrt' : (isset($arten['laeuft_ab']) ? 'laeuft_ab' : ($x['unter_mindest'] ? 'unter_mindest' : 'in_ordnung')));
            $artikel[] = $x;
        }
        $k = $a->kontext();
        return Antwort::json(['heute' => $e['heute'], 'einstellung' => self::einstellung($e),
            'orte' => array_map(fn (array $o) => ['id' => $o['id'], 'name' => $o['name'], 'gekuehlt' => (bool) $o['gekuehlt'], 'stillgelegt' => (bool) $o['stillgelegt']],
                $db->fetchAllAssociative('SELECT id, name, gekuehlt, stillgelegt FROM lager.ort ORDER BY stillgelegt, lower(name)')),
            'artikel' => $artikel, 'zahlen' => $zahlen, 'vorrat' => self::vorrat($a) !== null,
            'darf' => ['buchen' => $k->darf('lager.bewegung.buchen'), 'sperren' => $k->darf('lager.charge.sperren'),
                       'pflegen' => $k->darf('lager.artikel.pflegen'), 'einstellung' => $k->darf('lager.einstellung.pflegen')],
            'ich' => $k->subjekt]);
    }

    /** Eine Charge mit allen Bewegungen: die Rückverfolgung. */
    public function charge(Anfrage $a): Response
    {
        $id = self::uuid($a->param('id'), 'id');
        $db = $a->db();
        $c = $db->fetchAssociative("SELECT c.id, c.bezeichnung, c.verfall::text AS verfall, c.gesperrt, c.sperrgrund, c.code, (c.verfall IS NOT NULL AND c.verfall < current_date) AS abgelaufen,
                    a.id AS artikel, a.bezeichnung AS artikel_name, a.einheit, a.fuehrung, a.gtin FROM lager.charge c JOIN lager.artikel a ON a.id = c.artikel WHERE c.id = ?", [$id]);
        if ($c === false) {
            throw Problem::nichtGefunden('Charge unbekannt');
        }
        $stand = 0.0;
        $summen = ['zugang' => 0.0, 'entnahme' => 0.0, 'ausbuchung' => 0.0];
        $bewegungen = [];
        foreach ($db->fetchAllAssociative("SELECT b.id, b.art, b.menge::text AS menge, kern.zeitpunkt(b.zeit) AS zeit, b.subjekt, b.eintrag, b.position, b.zweck, b.grund, b.abweichung,
                    b.preis::text AS preis, v.name AS von, n.name AS nach
               FROM lager.bewegung b LEFT JOIN lager.ort v ON v.id = b.ort_von LEFT JOIN lager.ort n ON n.id = b.ort_nach WHERE b.charge = ? ORDER BY b.nr", [$id]) as $b) {
            $m = (float) $b['menge'];
            $stand += $b['art'] === 'zugang' ? $m : ($b['art'] === 'umlagerung' ? 0 : -$m);
            if (isset($summen[$b['art']])) {
                $summen[$b['art']] += $m;
            }
            $bewegungen[] = ['id' => $b['id'], 'art' => $b['art'], 'menge' => self::menge($b['menge']), 'zeit' => $b['zeit'], 'subjekt' => $b['subjekt'], 'eintrag' => $b['eintrag'],
                'position' => $b['position'], 'zweck' => $b['zweck'], 'grund' => $b['grund'], 'abweichung' => (bool) $b['abweichung'], 'preis' => $b['preis'],
                'von' => $b['von'], 'nach' => $b['nach'], 'danach' => self::menge(number_format($stand, 3, '.', ''))];
        }
        $sperren = $db->fetchAllAssociative('SELECT kern.zeitpunkt(zeit) AS zeit, subjekt, grund FROM lager.sperre WHERE charge = ? ORDER BY zeit', [$id]);
        return Antwort::json(['id' => $c['id'], 'bezeichnung' => $c['bezeichnung'], 'verfall' => $c['verfall'], 'gesperrt' => (bool) $c['gesperrt'], 'sperrgrund' => $c['sperrgrund'],
            'abgelaufen' => (bool) $c['abgelaufen'], 'code' => $c['code'],
            'artikel' => ['id' => $c['artikel'], 'bezeichnung' => $c['artikel_name'], 'einheit' => $c['einheit'], 'fuehrung' => $c['fuehrung'], 'gtin' => $c['gtin']],
            'summen' => array_map(fn (float $s) => self::menge(number_format($s, 3, '.', '')), $summen) + ['bestand' => self::menge(number_format($stand, 3, '.', ''))],
            'bewegungen' => $bewegungen, 'sperren' => $sperren]);
    }

    /** Einträge des Arbeitsvorrats mit Positionen und der Zahl der daraus gebuchten Zugänge. */
    public function wareneingang(Anfrage $a): Response
    {
        $v = self::vorrat($a);
        if ($v === null) {
            return Antwort::json(['vorrat' => false, 'eintraege' => []]);
        }
        $gebucht = [];
        foreach ($a->db()->fetchAllAssociative("SELECT eintrag, count(DISTINCT coalesce(position, id::text)) AS n FROM lager.bewegung WHERE art = 'zugang' AND eintrag IS NOT NULL GROUP BY eintrag") as $z) {
            $gebucht[$z['eintrag']] = (int) $z['n'];
        }
        $liste = [];
        foreach ($v->eintraegeMitPositionen() as $e) {
            $e['positionen'] = (int) $e['positionen'];
            $e['zugaenge'] = $gebucht[$e['id']] ?? 0;
            $liste[] = $e;
        }
        return Antwort::json(['vorrat' => true, 'eintraege' => $liste]);
    }

    /** Die Positionen eines Eintrags mit der gemerkten Zuordnung und dem, was schon zugegangen ist. */
    public function eintrag(Anfrage $a): Response
    {
        $id = self::uuid($a->param('eintrag'), 'eintrag');
        $v = self::vorrat($a);
        $p = $v?->positionen($id);
        if ($p === null) {
            throw Problem::nichtGefunden('Eintrag unbekannt');
        }
        $db = $a->db();
        $lieferant = self::lieferant($p['eintrag']);
        $zu = [];
        foreach ($db->fetchAllAssociative("SELECT b.position, lager._menge(b.menge) AS menge, c.bezeichnung AS charge, c.verfall::text AS verfall, c.artikel, o.name AS ort
               FROM lager.bewegung b JOIN lager.charge c ON c.id = b.charge JOIN lager.ort o ON o.id = b.ort_nach WHERE b.art = 'zugang' AND b.eintrag = ? ORDER BY b.nr", [$id]) as $z) {
            $zu[(string) $z['position']][] = $z;
        }
        $positionen = [];
        foreach ($p['positionen'] as $n => $pos) {
            $nr = (string) ($pos['nr'] ?? ($n + 1));
            $pos['nr'] = $nr;
            $schluessel = mb_strtolower($pos['artikelnummer'] ?? $pos['bezeichnung']);
            $g = $lieferant === '' ? false : $db->fetchAssociative('SELECT artikel, kein_lager, faktor::text AS faktor FROM lager.lieferantenartikel WHERE lieferant = ? AND nummer = ?', [$lieferant, $schluessel]);
            // ohne gemerkte Zuordnung: ein Artikel mit derselben GTIN ist ein Vorschlag, mehr nicht
            $ueberGtin = $g === false && isset($pos['gtin']) ? $db->fetchOne('SELECT id FROM lager.artikel WHERE gtin = ? AND NOT stillgelegt ORDER BY angelegt_am LIMIT 1', [$pos['gtin']]) : false;
            $pos['zuordnung'] = $g === false
                ? ($ueberGtin === false ? null : ['artikel' => $ueberGtin, 'kein_lager' => false, 'faktor' => '1', 'gemerkt' => false])
                : ['artikel' => $g['artikel'], 'kein_lager' => (bool) $g['kein_lager'], 'faktor' => self::menge($g['faktor']), 'gemerkt' => true];
            $pos['schluessel'] = $schluessel;
            $pos['zugaenge'] = $zu[$nr] ?? [];
            $positionen[] = $pos;
        }
        // Hinweis „eher ein Anlagegut?“ (LAGER.md Abschnitt 6 Nr. 4): die Grenze kommt aus dem Regelwerk der Anlagen, wenn das Modul im Buch aktiv ist
        $grenze = null;
        if (isset(Dienste::aktiveModule($db)['anlagen']) && class_exists(\Kumero\Modul\Anlagen\WebController::class)) {
            $grenze = \Kumero\Modul\Anlagen\WebController::grenzen((string) ($p['eintrag']['datum'] ?? date('Y-m-d')))['gwg_bis'] ?? null;
        }
        $preise = (string) $db->fetchOne('SELECT preise FROM lager.einstellung');
        return Antwort::json(['eintrag' => $p['eintrag'], 'quelle' => $p['quelle'], 'lieferant' => $lieferant, 'positionen' => $positionen, 'anlagegrenze' => $grenze, 'preise' => $preise,
            'summenhinweis' => \Kumero\Api\V1\Belegpositionen::summenhinweis($p['positionen'], $p['eintrag']['netto'] ?? null)]);
    }

    /** Mehrere Zugänge in einem Zug – ganz oder gar nicht. Je Zeile kann die Zuordnung zum Lieferantenartikel gemerkt werden. */
    public function zugaenge(Anfrage $a): Response
    {
        $j = $a->json();
        $zeilen = $j['zeilen'] ?? null;
        if (!is_array($zeilen) || !array_is_list($zeilen) || $zeilen === [] || count($zeilen) > 500) {
            throw Problem::ungueltig('zeilen: 1 bis 500 Zugänge erwartet');
        }
        $eintrag = isset($j['eintrag']) ? self::uuid($j['eintrag'], 'eintrag') : null;
        $lieferant = '';
        $name = null;
        $nummern = [];
        if ($eintrag !== null) {
            $p = self::vorrat($a)?->positionen($eintrag);
            if ($p === null) {
                throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'eintrag', 'meldung' => 'Eintrag im Arbeitsvorrat unbekannt']]);
            }
            $lieferant = self::lieferant($p['eintrag']);
            $name = ($p['eintrag']['gegenpartei'] ?? '') !== '' ? (string) $p['eintrag']['gegenpartei'] : null;
            foreach ($p['positionen'] as $pos) {
                if (isset($pos['artikelnummer'])) {
                    $nummern[mb_strtolower($pos['artikelnummer'])] = $pos['artikelnummer'];
                }
            }
        }
        $f = [];
        $sauber = [];
        foreach ($zeilen as $n => $z) {
            $z = is_array($z) ? $z : [];
            $x = ['artikel' => $z['artikel'] ?? null, 'ort' => $z['ort'] ?? null, 'menge' => $z['menge'] ?? null, 'charge' => trim((string) ($z['charge'] ?? '')),
                  'verfall' => $z['verfall'] ?? null, 'preis' => $z['preis'] ?? null, 'position' => isset($z['position']) ? mb_substr((string) $z['position'], 0, 20) : null,
                  'grund' => $z['grund'] ?? null, 'code' => isset($z['code']) ? mb_substr((string) $z['code'], 0, 200) : null, 'eintrag' => $eintrag, 'lieferant' => $name];
            foreach (['artikel', 'ort'] as $feld) {
                if (!is_string($x[$feld]) || !preg_match(self::UUID, $x[$feld])) {
                    $f[] = ['feld' => "zeilen[$n].$feld", 'meldung' => 'Pflichtfeld'];
                }
            }
            if (!is_string($x['menge']) || !preg_match(self::MENGE, $x['menge']) || (float) $x['menge'] <= 0) {
                $f[] = ['feld' => "zeilen[$n].menge", 'meldung' => 'Menge größer als null als Dezimal-String mit höchstens drei Nachkommastellen erwartet'];
            }
            if (mb_strlen($x['charge']) > 50) {
                $f[] = ['feld' => "zeilen[$n].charge", 'meldung' => 'höchstens 50 Zeichen'];
            }
            if ($x['verfall'] !== null && $x['verfall'] !== '') {
                $x['verfall'] = self::datum($x['verfall'], "zeilen[$n].verfall");
            } else {
                $x['verfall'] = null;
            }
            if ($x['preis'] !== null && (!is_string($x['preis']) || !preg_match('/^\d{1,11}(\.\d{1,4})?$/', $x['preis']))) {
                $f[] = ['feld' => "zeilen[$n].preis", 'meldung' => 'Preis als Dezimal-String mit höchstens vier Nachkommastellen erwartet'];
            }
            if ($x['grund'] !== null && !in_array($x['grund'], self::GRUENDE_ZUGANG, true)) {
                $f[] = ['feld' => "zeilen[$n].grund", 'meldung' => 'Erlaubt: ' . implode(', ', self::GRUENDE_ZUGANG)];
            }
            $merken = $z['merken'] ?? null;
            if ($merken !== null && (!is_array($merken) || !is_string($merken['nummer'] ?? null) || trim($merken['nummer']) === '' || mb_strlen($merken['nummer']) > 200
                || !is_string($merken['faktor'] ?? '1') || !preg_match(self::MENGE, $merken['faktor'] ?? '1') || (float) ($merken['faktor'] ?? '1') <= 0 || $lieferant === '')) {
                $f[] = ['feld' => "zeilen[$n].merken", 'meldung' => 'merken: nummer und faktor erwartet, und der Eintrag muss einen Lieferanten nennen'];
                $merken = null;
            }
            $sauber[] = [$x, $merken];
        }
        if ($f !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        $db = $a->db();
        $subjekt = $a->kontext()->subjekt;
        $ids = $db->transactional(function () use ($db, $subjekt, $sauber, $lieferant, $name, $nummern): array {
            $ids = [];
            foreach ($sauber as [$x, $merken]) {
                $ids[] = (string) $db->fetchOne('SELECT lager.zugang(?, ?::jsonb)', [$subjekt, self::json($x)]);
                if ($merken !== null) {
                    $db->executeStatement('SELECT lager.merken(?, ?, ?, ?::uuid, ?::numeric, ?, ?)',
                        [$subjekt, $lieferant, $merken['nummer'], $x['artikel'], $merken['faktor'] ?? '1', $name, $nummern[mb_strtolower($merken['nummer'])] ?? null]);
                }
            }
            return $ids;
        });
        return Antwort::json(['bewegungen' => $ids], 201);
    }

    /** Merkt für einen Eintrag, dass eine Position kein Lagerartikel ist (artikel null) oder zu welchem Artikel sie gehört. */
    public function merken(Anfrage $a): Response
    {
        $j = $a->json();
        $p = self::vorrat($a)?->positionen(self::uuid($j['eintrag'] ?? null, 'eintrag'));
        $lieferant = $p === null ? '' : self::lieferant($p['eintrag']);
        $nummer = $j['nummer'] ?? null;
        $artikel = $j['artikel'] ?? null;
        $faktor = $j['faktor'] ?? '1';
        if ($lieferant === '' || !is_string($nummer) || trim($nummer) === '' || mb_strlen($nummer) > 200 || ($artikel !== null && (!is_string($artikel) || !preg_match(self::UUID, $artikel)))
            || !is_string($faktor) || !preg_match(self::MENGE, $faktor) || (float) $faktor <= 0) {
            throw Problem::ungueltig('eintrag mit Lieferant, nummer, artikel (oder null für „kein Lagerartikel“) und faktor erwartet');
        }
        $a->db()->executeStatement('SELECT lager.merken(?, ?, ?, ?::uuid, ?::numeric)', [$a->kontext()->subjekt, $lieferant, $nummer, $artikel, $faktor]);
        return Antwort::leer();
    }

    public function entnahme(Anfrage $a): Response
    {
        $j = $a->json();
        $zweck = $j['zweck'] ?? null;
        if ($zweck !== null && (!is_string($zweck) || $zweck === '' || mb_strlen($zweck) > 40)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'zweck', 'meldung' => 'Ein Eintrag der Auswahlliste oder nichts']]);
        }
        $id = (string) $a->db()->fetchOne('SELECT lager.entnahme(?, ?::uuid, ?::uuid, ?::numeric, ?)',
            [$a->kontext()->subjekt, self::uuid($j['charge'] ?? null, 'charge'), self::uuid($j['ort'] ?? null, 'ort'), self::mengeLesen($j['menge'] ?? null), $zweck]);
        $abweichung = (bool) $a->db()->fetchOne('SELECT abweichung FROM lager.bewegung WHERE id = ?', [$id]);
        return Antwort::json(['bewegung' => $id, 'abweichung' => $abweichung], 201);
    }

    public function umlagerung(Anfrage $a): Response
    {
        $j = $a->json();
        $id = (string) $a->db()->fetchOne('SELECT lager.umlagerung(?, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
            [$a->kontext()->subjekt, self::uuid($j['charge'] ?? null, 'charge'), self::uuid($j['von'] ?? null, 'von'), self::uuid($j['nach'] ?? null, 'nach'), self::mengeLesen($j['menge'] ?? null)]);
        return Antwort::json(['bewegung' => $id], 201);
    }

    public function ausbuchung(Anfrage $a): Response
    {
        $j = $a->json();
        $id = (string) $a->db()->fetchOne('SELECT lager.ausbuchung(?, ?::uuid, ?::uuid, ?::numeric, ?)',
            [$a->kontext()->subjekt, self::uuid($j['charge'] ?? null, 'charge'), self::uuid($j['ort'] ?? null, 'ort'), self::mengeLesen($j['menge'] ?? null), is_string($j['grund'] ?? null) ? $j['grund'] : null]);
        return Antwort::json(['bewegung' => $id], 201);
    }

    /** Sperren mit Grund oder freigeben (grund null). */
    public function sperre(Anfrage $a): Response
    {
        $grund = $a->json()['grund'] ?? null;
        if ($grund !== null && (!is_string($grund) || trim($grund) === '' || mb_strlen(trim($grund)) > 200)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'grund', 'meldung' => 'Grund mit höchstens 200 Zeichen – oder null zum Freigeben']]);
        }
        $a->db()->executeStatement('SELECT lager.charge_sperren(?, ?::uuid, ?)', [$a->kontext()->subjekt, self::uuid($a->param('id'), 'id'), $grund]);
        return Antwort::leer();
    }

    public function artikelAnlegen(Anfrage $a): Response
    {
        $id = (string) $a->db()->fetchOne('SELECT lager.artikel_setzen(?, NULL, ?::jsonb)', [$a->kontext()->subjekt, self::json(self::artikelPruefen($a->json()))]);
        return Antwort::json(self::artikelHolen($a, $id), 201);
    }

    public function artikelAendern(Anfrage $a): Response
    {
        $id = self::uuid($a->param('id'), 'id');
        self::artikelHolen($a, $id);
        $a->db()->fetchOne('SELECT lager.artikel_setzen(?, ?::uuid, ?::jsonb)', [$a->kontext()->subjekt, $id, self::json(self::artikelPruefen($a->json()))]);
        return Antwort::json(self::artikelHolen($a, $id));
    }

    public function ortAnlegen(Anfrage $a): Response
    {
        [$name, $gekuehlt] = self::ortPruefen($a->json());
        $id = (string) $a->db()->fetchOne('SELECT lager.ort_setzen(?, NULL, ?, ?::boolean, false)', [$a->kontext()->subjekt, $name, $gekuehlt ? 'true' : 'false']);
        return Antwort::json(['id' => $id, 'name' => $name, 'gekuehlt' => $gekuehlt, 'stillgelegt' => false], 201);
    }

    public function ortAendern(Anfrage $a): Response
    {
        $id = self::uuid($a->param('id'), 'id');
        [$name, $gekuehlt, $still] = self::ortPruefen($a->json());
        $a->db()->fetchOne('SELECT lager.ort_setzen(?, ?::uuid, ?, ?::boolean, ?::boolean)', [$a->kontext()->subjekt, $id, $name, $gekuehlt ? 'true' : 'false', $still ? 'true' : 'false']);
        return Antwort::json(['id' => $id, 'name' => $name, 'gekuehlt' => $gekuehlt, 'stillgelegt' => $still]);
    }

    /** Einstellungen des Lagers. Genannte Felder werden gesetzt, die übrigen bleiben. */
    public function einstellungSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        $alt = self::einstellung($a->db()->fetchAssociative('SELECT warn_tage, zwecke::text AS zwecke, bewertung, buchfuehrung, preise, vorratskonten, bestandskonto, aufwandskonto FROM lager.einstellung'));
        $fremd = array_diff(array_keys($j), array_keys($alt));
        $x = array_intersect_key($j, $alt) + $alt;
        $f = array_map(fn ($k) => ['feld' => (string) $k, 'meldung' => 'Unbekanntes Feld'], array_values($fremd));
        if (!is_int($x['warn_tage']) || $x['warn_tage'] < 1 || $x['warn_tage'] > 730) {
            $f[] = ['feld' => 'warn_tage', 'meldung' => 'Ganze Zahl von 1 bis 730 erwartet'];
        }
        $zwecke = $x['zwecke'];
        if (!is_array($zwecke) || !array_is_list($zwecke) || $zwecke === [] || count($zwecke) > 20
            || array_filter($zwecke, fn ($z) => !is_string($z) || trim($z) === '' || mb_strlen(trim($z)) > 40) !== []) {
            $f[] = ['feld' => 'zwecke', 'meldung' => '1 bis 20 Bezeichnungen mit je höchstens 40 Zeichen erwartet'];
        } else {
            $x['zwecke'] = array_values(array_unique(array_map('trim', $zwecke)));
        }
        foreach (['bewertung' => ['letzter', 'durchschnitt'], 'buchfuehrung' => ['aufwand', 'bestand'], 'preise' => ['netto', 'brutto']] as $feld => $erlaubt) {
            if (!in_array($x[$feld], $erlaubt, true)) {
                $f[] = ['feld' => $feld, 'meldung' => 'Erlaubt: ' . implode(', ', $erlaubt)];
            }
        }
        if (!is_string($x['vorratskonten']) || mb_strlen($x['vorratskonten']) > 500 || !preg_match('/^[0-9A-Za-z*,;\s–-]*$/u', $x['vorratskonten'])) {
            $f[] = ['feld' => 'vorratskonten', 'meldung' => 'Kontonummern, Bereiche (3970-3989) oder Anfänge (39*), durch Komma getrennt'];
        }
        foreach (['bestandskonto', 'aufwandskonto'] as $feld) {
            if ($x[$feld] !== null && $x[$feld] !== '' && (!is_string($x[$feld]) || mb_strlen(trim($x[$feld])) > 20)) {
                $f[] = ['feld' => $feld, 'meldung' => 'Kontonummer mit höchstens 20 Zeichen erwartet'];
            }
        }
        if ($f === [] && $x['buchfuehrung'] === 'bestand' && (trim((string) $x['bestandskonto']) === '' || trim((string) $x['aufwandskonto']) === '')) {
            $f[] = ['feld' => 'bestandskonto', 'meldung' => 'Bestandsführung braucht ein Bestandskonto und ein Aufwandskonto'];
        }
        if ($f !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        $a->db()->executeStatement('SELECT lager.einstellung_setzen(?, ?::jsonb)', [$a->kontext()->subjekt, self::json($x)]);
        return Antwort::leer();
    }

    /** @param array<string, mixed> $e @return array<string, mixed> */
    private static function einstellung(array $e): array
    {
        return ['warn_tage' => (int) $e['warn_tage'], 'zwecke' => json_decode((string) $e['zwecke'], true), 'bewertung' => $e['bewertung'], 'buchfuehrung' => $e['buchfuehrung'],
            'preise' => $e['preise'], 'vorratskonten' => $e['vorratskonten'], 'bestandskonto' => $e['bestandskonto'], 'aufwandskonto' => $e['aufwandskonto']];
    }

    /**
     * Bestandsliste zu einem Stichtag mit Menge und Wert (LAGER.md Stufe 3): gerechnet aus den Bewegungen bis zum Ende
     * des Stichtags, nach dem Verfahren des Buchs. Dazu die Vermerke über Buchungen der Bestandsveränderung.
     */
    public function bestandsliste(Anfrage $a): Response
    {
        $db = $a->db();
        $heute = (string) $db->fetchOne('SELECT current_date::text');
        $stichtag = $a->http->query->has('stichtag') ? self::datum($a->http->query->get('stichtag'), 'stichtag') : $heute;
        $e = self::einstellung($db->fetchAssociative('SELECT warn_tage, zwecke::text AS zwecke, bewertung, buchfuehrung, preise, vorratskonten, bestandskonto, aufwandskonto FROM lager.einstellung'));
        $werte = Bewertung::rechnen($db->iterateAssociative("SELECT c.artikel, b.art, b.menge::text AS menge, b.preis::text AS preis
               FROM lager.bewegung b JOIN lager.charge c ON c.id = b.charge WHERE b.zeit::date <= ?::date AND b.art <> 'umlagerung' ORDER BY b.nr", [$stichtag]), $e['bewertung']);
        $liste = [];
        $summe = 0;
        $ohne = 0;
        foreach ($db->fetchAllAssociative('SELECT id, nummer, bezeichnung, art, einheit FROM lager.artikel ORDER BY lower(bezeichnung), id') as $z) {
            $w = $werte[$z['id']] ?? null;
            if ($w === null) {
                continue;
            }
            $summe += $w['wert'] ?? 0;
            $ohne += $w['ohne_preis'] ? 1 : 0;
            $liste[] = ['artikel' => $z['id'], 'nummer' => $z['nummer'], 'bezeichnung' => $z['bezeichnung'], 'art' => $z['art'], 'einheit' => $z['einheit'],
                'menge' => self::menge(Bewertung::dezimal($w['menge'], 3)), 'preis' => $w['preis'] === null ? null : Bewertung::dezimal($w['preis'], 4),
                'wert' => $w['wert'] === null ? null : Bewertung::dezimal($w['wert'], 2), 'ohne_preis' => $w['ohne_preis']];
        }
        return Antwort::json(['stichtag' => $stichtag, 'heute' => $heute, 'einstellung' => $e, 'artikel' => $liste, 'summe' => Bewertung::dezimal($summe, 2), 'ohne_preis' => $ohne,
            'vermerke' => $db->fetchAllAssociative('SELECT buchung, betrag::text AS betrag, richtung FROM lager.abschluss WHERE stichtag = ?::date ORDER BY vermerkt_am', [$stichtag]),
            'darf' => $a->kontext()->darf('lager.einstellung.pflegen'), 'darf_buchen' => $a->kontext()->darf('kern.entwurf.anlegen')]);
    }

    public function abschlussVermerken(Anfrage $a): Response
    {
        $j = $a->json();
        $betrag = $j['betrag'] ?? null;
        $richtung = $j['richtung'] ?? null;
        if (!is_string($betrag) || !preg_match('/^\d{1,13}\.\d{2}$/', $betrag) || (float) $betrag <= 0 || !in_array($richtung, ['erhoehung', 'minderung'], true)) {
            throw Problem::ungueltig('betrag als Dezimal-String größer null und richtung (erhoehung|minderung) erwartet');
        }
        $a->db()->executeStatement('SELECT lager.abschluss_vermerken(?, ?::date, ?::uuid, ?::numeric, ?)',
            [$a->kontext()->subjekt, self::datum($j['stichtag'] ?? null, 'stichtag'), self::uuid($j['buchung'] ?? null, 'buchung'), $betrag, $richtung]);
        return Antwort::leer();
    }

    public function abschlussEntfernen(Anfrage $a): Response
    {
        $j = $a->json();
        $a->db()->executeStatement('SELECT lager.abschluss_entfernen(?, ?::date, ?::uuid)', [$a->kontext()->subjekt, self::datum($j['stichtag'] ?? null, 'stichtag'), self::uuid($j['buchung'] ?? null, 'buchung')]);
        return Antwort::leer();
    }

    /**
     * Nachbestellliste (LAGER.md Stufe 2): Artikel, deren verfügbarer Bestand unter dem Mindestbestand liegt. Verfügbar
     * ist, was weder abgelaufen noch gesperrt ist. Vorgeschlagen wird bis zum Sollbestand, sonst bis zum Mindestbestand.
     */
    public function nachbestellung(Anfrage $a): Response
    {
        $db = $a->db();
        $liste = [];
        foreach ($db->fetchAllAssociative("SELECT a.id, a.nummer, a.bezeichnung, a.einheit, a.mindest::text AS mindest, a.soll::text AS soll,
                    coalesce((SELECT sum(s.menge) FROM lager.bestand s JOIN lager.charge c ON c.id = s.charge
                               WHERE c.artikel = a.id AND NOT c.gesperrt AND (c.verfall IS NULL OR c.verfall >= current_date)), 0)::text AS verfuegbar,
                    coalesce((SELECT sum(s.menge) FROM lager.bestand s JOIN lager.charge c ON c.id = s.charge WHERE c.artikel = a.id), 0)::text AS bestand
               FROM lager.artikel a WHERE NOT a.stillgelegt AND NOT a.btm AND a.mindest IS NOT NULL ORDER BY lower(a.bezeichnung), a.id") as $z) {
            if ((float) $z['verfuegbar'] >= (float) $z['mindest']) {
                continue;
            }
            $ziel = $z['soll'] ?? $z['mindest'];
            $fehlt = max(0.0, round((float) $ziel - (float) $z['verfuegbar'], 3));
            $letzter = $db->fetchAssociative("SELECT b.lieferant, b.preis::text AS preis, b.zeit::date::text AS am FROM lager.bewegung b JOIN lager.charge c ON c.id = b.charge
                                               WHERE c.artikel = ? AND b.art = 'zugang' AND b.eintrag IS NOT NULL ORDER BY b.nr DESC LIMIT 1", [$z['id']]);
            $g = $db->fetchAssociative('SELECT lieferant_name, nummer_beleg, faktor::text AS faktor FROM lager.lieferantenartikel WHERE artikel = ? ORDER BY gemerkt_am DESC LIMIT 1', [$z['id']]);
            $faktor = $g === false ? 1.0 : (float) $g['faktor'];
            $liste[] = ['artikel' => $z['id'], 'nummer' => $z['nummer'], 'bezeichnung' => $z['bezeichnung'], 'einheit' => $z['einheit'],
                'mindest' => self::menge($z['mindest']), 'soll' => $z['soll'] === null ? null : self::menge($z['soll']),
                'verfuegbar' => self::menge($z['verfuegbar']), 'nicht_verfuegbar' => self::menge(number_format((float) $z['bestand'] - (float) $z['verfuegbar'], 3, '.', '')),
                'vorschlag' => self::menge(number_format($fehlt, 3, '.', '')),
                'lieferant' => $letzter === false ? ($g === false ? null : $g['lieferant_name']) : ($letzter['lieferant'] ?? ($g === false ? null : $g['lieferant_name'])),
                'lieferant_nummer' => $g === false ? null : $g['nummer_beleg'],
                'faktor' => self::menge(number_format($faktor, 3, '.', '')), 'bestelleinheiten' => $faktor > 0 ? (int) ceil(round($fehlt / $faktor, 6)) : null,
                'letzter_preis' => $letzter === false ? null : $letzter['preis'], 'letzter_zugang' => $letzter === false ? null : $letzter['am']];
        }
        return Antwort::json(['heute' => (string) $db->fetchOne('SELECT current_date::text'), 'artikel' => $liste]);
    }

    public function inventuren(Anfrage $a): Response
    {
        $liste = $a->db()->fetchAllAssociative("SELECT i.id, i.nr, i.ort, o.name AS ort_name, i.stichtag::text AS stichtag, i.zustand, kern.zeitpunkt(i.angelegt_am) AS angelegt_am,
                    kern.zeitpunkt(i.beendet_am) AS beendet_am, (SELECT count(*) FROM lager.inventur_zeile z WHERE z.inventur = i.id) AS zeilen,
                    (SELECT count(*) FROM lager.inventur_zeile z WHERE z.inventur = i.id AND z.ist IS NOT NULL) AS gezaehlt
               FROM lager.inventur i LEFT JOIN lager.ort o ON o.id = i.ort ORDER BY i.nr DESC LIMIT 200");
        return Antwort::json(['inventuren' => array_map(fn (array $i) => ['zeilen' => (int) $i['zeilen'], 'gezaehlt' => (int) $i['gezaehlt'], 'nr' => (int) $i['nr']] + $i, $liste),
            'darf' => $a->kontext()->darf('lager.inventur.fuehren'), 'darf_abschliessen' => $a->kontext()->darf('lager.inventur.abschliessen')]);
    }

    public function inventurAnlegen(Anfrage $a): Response
    {
        $ort = $a->json()['ort'] ?? null;
        $id = (string) $a->db()->fetchOne('SELECT lager.inventur_anlegen(?, ?::uuid)', [$a->kontext()->subjekt, $ort === null ? null : self::uuid($ort, 'ort')]);
        return Antwort::json(self::inventurHolen($a, $id), 201);
    }

    public function inventur(Anfrage $a): Response
    {
        return Antwort::json(self::inventurHolen($a, self::uuid($a->param('id'), 'id')));
    }

    /** Zählen: ist als Dezimal-String, oder null, um die Zählung der Zeile zurückzunehmen. */
    public function inventurZaehlen(Anfrage $a): Response
    {
        $j = $a->json();
        $ist = $j['ist'] ?? null;
        if ($ist !== null && (!is_string($ist) || !preg_match(self::MENGE, $ist))) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'ist', 'meldung' => 'Menge ab null als Dezimal-String mit höchstens drei Nachkommastellen – oder null']]);
        }
        $id = self::uuid($a->param('id'), 'id');
        $a->db()->executeStatement('SELECT lager.inventur_zaehlen(?, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
            [$a->kontext()->subjekt, $id, self::uuid($j['charge'] ?? null, 'charge'), self::uuid($j['ort'] ?? null, 'ort'), $ist]);
        return Antwort::json(self::inventurHolen($a, $id));
    }

    public function inventurZeile(Anfrage $a): Response
    {
        $j = $a->json();
        $id = self::uuid($a->param('id'), 'id');
        $a->db()->executeStatement('SELECT lager.inventur_zeile_dazu(?, ?::uuid, ?::uuid, ?::uuid)', [$a->kontext()->subjekt, $id, self::uuid($j['charge'] ?? null, 'charge'), self::uuid($j['ort'] ?? null, 'ort')]);
        return Antwort::json(self::inventurHolen($a, $id), 201);
    }

    public function inventurAbschliessen(Anfrage $a): Response
    {
        $id = self::uuid($a->param('id'), 'id');
        $n = (int) $a->db()->fetchOne('SELECT lager.inventur_abschliessen(?, ?::uuid)', [$a->kontext()->subjekt, $id]);
        return Antwort::json(['abweichungen' => $n] + self::inventurHolen($a, $id));
    }

    public function inventurVerwerfen(Anfrage $a): Response
    {
        $id = self::uuid($a->param('id'), 'id');
        $a->db()->executeStatement('SELECT lager.inventur_verwerfen(?, ?::uuid)', [$a->kontext()->subjekt, $id]);
        return Antwort::json(self::inventurHolen($a, $id));
    }

    /** @return array<string, mixed> */
    private static function inventurHolen(Anfrage $a, string $id): array
    {
        $db = $a->db();
        $i = $db->fetchAssociative("SELECT i.id, i.nr, i.ort, o.name AS ort_name, i.stichtag::text AS stichtag, i.zustand, kern.zeitpunkt(i.angelegt_am) AS angelegt_am, i.angelegt_von,
                    kern.zeitpunkt(i.beendet_am) AS beendet_am, i.beendet_von FROM lager.inventur i LEFT JOIN lager.ort o ON o.id = i.ort WHERE i.id = ?", [$id]);
        if ($i === false) {
            throw Problem::nichtGefunden('Inventur unbekannt');
        }
        $zeilen = [];
        foreach ($db->fetchAllAssociative("SELECT z.charge, z.ort, o.name AS ort_name, a.id AS artikel, a.bezeichnung, a.einheit, a.fuehrung, a.gtin, c.bezeichnung AS charge_name, c.verfall::text AS verfall,
                    (c.verfall IS NOT NULL AND c.verfall < current_date) AS abgelaufen, z.bestand::text AS bestand, z.ist::text AS ist, kern.zeitpunkt(z.gezaehlt_am) AS gezaehlt_am, z.gezaehlt_von,
                    coalesce((SELECT s.menge FROM lager.bestand s WHERE s.charge = z.charge AND s.ort = z.ort), 0)::text AS jetzt
               FROM lager.inventur_zeile z JOIN lager.charge c ON c.id = z.charge JOIN lager.artikel a ON a.id = c.artikel JOIN lager.ort o ON o.id = z.ort
              WHERE z.inventur = ? ORDER BY lower(o.name), lower(a.bezeichnung), c.verfall NULLS LAST, c.bezeichnung", [$id]) as $z) {
            $gezaehlt = $z['ist'] !== null;
            $zeilen[] = ['charge' => $z['charge'], 'ort' => $z['ort'], 'ort_name' => $z['ort_name'], 'artikel' => $z['artikel'], 'bezeichnung' => $z['bezeichnung'], 'einheit' => $z['einheit'],
                'fuehrung' => $z['fuehrung'], 'gtin' => $z['gtin'], 'charge_name' => $z['charge_name'], 'verfall' => $z['verfall'], 'abgelaufen' => (bool) $z['abgelaufen'],
                'bestand' => self::menge($gezaehlt ? $z['bestand'] : $z['jetzt']), 'ist' => $gezaehlt ? self::menge($z['ist']) : null,
                'differenz' => $gezaehlt ? self::menge(number_format((float) $z['ist'] - (float) $z['bestand'], 3, '.', '')) : null,
                'gezaehlt_am' => $z['gezaehlt_am'], 'gezaehlt_von' => $z['gezaehlt_von']];
        }
        $i['nr'] = (int) $i['nr'];
        $i['zeilen'] = $zeilen;
        $i['gezaehlt'] = count(array_filter($zeilen, fn (array $z) => $z['ist'] !== null));
        $i['abweichungen'] = count(array_filter($zeilen, fn (array $z) => $z['differenz'] !== null && (float) $z['differenz'] != 0));
        $i['darf'] = $a->kontext()->darf('lager.inventur.fuehren');
        $i['darf_abschliessen'] = $a->kontext()->darf('lager.inventur.abschliessen');
        // für „Zeile hinzufügen“: bekannte Chargen, die noch nicht auf der Liste stehen
        $i['chargen'] = $i['zustand'] !== 'offen' ? [] : $db->fetchAllAssociative("SELECT c.id, a.bezeichnung, c.bezeichnung AS charge_name, c.verfall::text AS verfall
               FROM lager.charge c JOIN lager.artikel a ON a.id = c.artikel WHERE NOT a.stillgelegt ORDER BY lower(a.bezeichnung), c.verfall NULLS LAST LIMIT 2000");
        return $i;
    }

    /** Der Dienst des Arbeitsvorrats, wenn das Modul im Buch aktiv ist – sonst gibt es keinen Wareneingang aus Belegen. */
    private static function vorrat(Anfrage $a): ?Vorrat
    {
        return isset(Dienste::aktiveModule($a->db())['arbeitsvorrat']) ? new Vorrat($a->db(), $a->kontext()->subjekt) : null;
    }

    /** Schlüssel des Lieferanten für gemerkte Zuordnungen: Kennung vor Name. @param array<string, mixed> $eintrag */
    private static function lieferant(array $eintrag): string
    {
        return mb_strtolower(trim((string) (($eintrag['gegenpartei_kennung'] ?? '') !== '' ? $eintrag['gegenpartei_kennung'] : ($eintrag['gegenpartei'] ?? ''))));
    }

    /** @return array<string, mixed> */
    private static function artikelHolen(Anfrage $a, string $id): array
    {
        $z = $a->db()->fetchAssociative('SELECT id, nummer, bezeichnung, art, einheit, fuehrung, verfall_pflicht, btm, gtin, mindest::text AS mindest, soll::text AS soll, standardort, lagerbedingung, stillgelegt
                                           FROM lager.artikel WHERE id = ?', [$id]);
        return $z === false ? throw Problem::nichtGefunden('Artikel unbekannt') : self::artikel($z);
    }

    /** @param array<string, mixed> $z @return array<string, mixed> */
    private static function artikel(array $z): array
    {
        return ['id' => $z['id'], 'nummer' => $z['nummer'], 'bezeichnung' => $z['bezeichnung'], 'art' => $z['art'], 'einheit' => $z['einheit'], 'fuehrung' => $z['fuehrung'],
            'verfall_pflicht' => (bool) $z['verfall_pflicht'], 'btm' => (bool) $z['btm'], 'gtin' => $z['gtin'], 'mindest' => $z['mindest'] === null ? null : self::menge($z['mindest']), 'soll' => ($z['soll'] ?? null) === null ? null : self::menge($z['soll']),
            'standardort' => $z['standardort'], 'lagerbedingung' => $z['lagerbedingung'], 'stillgelegt' => (bool) $z['stillgelegt']];
    }

    /** @param array<string, mixed> $j @return array<string, mixed> */
    private static function artikelPruefen(array $j): array
    {
        $f = [];
        $text = function (string $feld, int $max, bool $pflicht) use ($j, &$f): ?string {
            $w = $j[$feld] ?? null;
            if ($w === null || (is_string($w) && trim($w) === '')) {
                if ($pflicht) {
                    $f[] = ['feld' => $feld, 'meldung' => 'Pflichtfeld'];
                }
                return null;
            }
            if (!is_string($w) || mb_strlen(trim($w)) > $max) {
                $f[] = ['feld' => $feld, 'meldung' => "Text mit höchstens $max Zeichen erwartet"];
                return null;
            }
            return trim($w);
        };
        $x = ['bezeichnung' => $text('bezeichnung', 200, true), 'nummer' => $text('nummer', 40, false), 'einheit' => $text('einheit', 20, true),
              'lagerbedingung' => $text('lagerbedingung', 100, false)];
        foreach (['art' => self::ARTEN, 'fuehrung' => self::FUEHRUNG] as $feld => $erlaubt) {
            $x[$feld] = $j[$feld] ?? null;
            if (!in_array($x[$feld], $erlaubt, true)) {
                $f[] = ['feld' => $feld, 'meldung' => 'Erlaubt: ' . implode(', ', $erlaubt)];
            }
        }
        foreach (['verfall_pflicht', 'btm', 'stillgelegt'] as $feld) {
            $x[$feld] = $j[$feld] ?? false;
            if (!is_bool($x[$feld])) {
                $f[] = ['feld' => $feld, 'meldung' => 'true oder false erwartet'];
            }
        }
        if ($x['fuehrung'] === 'ohne' && $x['verfall_pflicht'] === true) {
            $f[] = ['feld' => 'verfall_pflicht', 'meldung' => 'Ein Verfallsdatum gehört zu einer Charge – ohne Chargenführung gibt es keines'];
        }
        $x['gtin'] = $j['gtin'] ?? null;
        if ($x['gtin'] !== null && $x['gtin'] !== '' && (!is_string($x['gtin']) || !preg_match('/^\d{8,14}$/', $x['gtin']))) {
            $f[] = ['feld' => 'gtin', 'meldung' => '8 bis 14 Ziffern erwartet'];
        }
        $x['mindest'] = $j['mindest'] ?? null;
        if ($x['mindest'] !== null && (!is_string($x['mindest']) || !preg_match(self::MENGE, $x['mindest']))) {
            $f[] = ['feld' => 'mindest', 'meldung' => 'Menge als Dezimal-String mit höchstens drei Nachkommastellen erwartet'];
        }
        $x['soll'] = $j['soll'] ?? null;
        if ($x['soll'] !== null && (!is_string($x['soll']) || !preg_match(self::MENGE, $x['soll']))) {
            $f[] = ['feld' => 'soll', 'meldung' => 'Menge als Dezimal-String mit höchstens drei Nachkommastellen erwartet'];
        } elseif ($x['soll'] !== null && is_string($x['mindest']) && (float) $x['soll'] < (float) $x['mindest']) {
            $f[] = ['feld' => 'soll', 'meldung' => 'Der Sollbestand liegt unter dem Mindestbestand'];
        }
        $x['standardort'] = $j['standardort'] ?? null;
        if ($x['standardort'] !== null && (!is_string($x['standardort']) || !preg_match(self::UUID, $x['standardort']))) {
            $f[] = ['feld' => 'standardort', 'meldung' => 'Lagerort erwartet'];
        }
        if ($f !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        return $x;
    }

    /** @param array<string, mixed> $j @return array{0: string, 1: bool, 2: bool} */
    private static function ortPruefen(array $j): array
    {
        $name = $j['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 80 || !is_bool($j['gekuehlt'] ?? false) || !is_bool($j['stillgelegt'] ?? false)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'name', 'meldung' => 'Name mit höchstens 80 Zeichen erwartet; gekuehlt und stillgelegt als true oder false']]);
        }
        return [trim($name), (bool) ($j['gekuehlt'] ?? false), (bool) ($j['stillgelegt'] ?? false)];
    }

    private static function uuid(mixed $w, string $feld): string
    {
        if (!is_string($w) || !preg_match(self::UUID, $w)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => $feld, 'meldung' => 'Kennung erwartet']]);
        }
        return $w;
    }

    private static function mengeLesen(mixed $w): string
    {
        if (!is_string($w) || !preg_match(self::MENGE, $w) || (float) $w <= 0) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'menge', 'meldung' => 'Menge größer als null als Dezimal-String mit höchstens drei Nachkommastellen erwartet']]);
        }
        return $w;
    }

    private static function datum(mixed $w, string $feld): string
    {
        $d = is_string($w) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $w) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $w) : false;
        if ($d === false || $d->format('Y-m-d') !== $w) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => $feld, 'meldung' => 'Datum als JJJJ-MM-TT erwartet']]);
        }
        return $w;
    }

    /** „12.000“ → „12“, „0.500“ → „0.5“ */
    private static function menge(string $w): string
    {
        return str_contains($w, '.') ? rtrim(rtrim($w, '0'), '.') : $w;
    }

    /** @param array<mixed> $w */
    private static function json(array $w): string
    {
        return json_encode($w, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
