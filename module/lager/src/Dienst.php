<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Lager;

use Doctrine\DBAL\Connection;

/**
 * Dienst des Lagers für andere Module (F44, BESTELLANFORDERUNG.md 9.1, F222): Die Beschaffung fragt hier nach Bedarf,
 * Artikeln und Artikelgruppen und lässt hierüber Artikel anlegen – sie liest und schreibt nie die Tabellen des Lagers.
 * Wer den Dienst ruft, hat das Recht dazu selbst geprüft (die Beschaffung: „bestellen“); geschrieben wird über die
 * Funktionen des Lagers, mit Protokoll und unter dem Subjekt dessen, der handelt.
 */
final class Dienst
{
    public function __construct(private readonly Connection $db, private readonly string $subjekt)
    {
    }

    /**
     * Was unter Mindestbestand liegt (LAGER.md Stufe 2): Artikel und Artikelgruppen mit Vorschlag bis zum Sollbestand,
     * sonst bis zum Mindestbestand. Verfügbar ist, was weder abgelaufen noch gesperrt ist.
     *
     * @return array{heute: string, artikel: list<array<string, mixed>>, meta: list<array<string, mixed>>}
     */
    public function bedarf(): array
    {
        $liste = [];
        foreach ($this->db->fetchAllAssociative("SELECT a.id, a.nummer, a.bezeichnung, a.einheit, a.mindest::text AS mindest, a.soll::text AS soll,
                    coalesce((SELECT sum(s.menge) FROM lager.bestand s JOIN lager.charge c ON c.id = s.charge
                               WHERE c.artikel = a.id AND NOT c.gesperrt AND (c.verfall IS NULL OR c.verfall >= current_date)), 0)::text AS verfuegbar,
                    coalesce((SELECT sum(s.menge) FROM lager.bestand s JOIN lager.charge c ON c.id = s.charge WHERE c.artikel = a.id), 0)::text AS bestand
               FROM lager.artikel a WHERE NOT a.stillgelegt AND NOT a.btm AND a.mindest IS NOT NULL ORDER BY lower(a.bezeichnung), a.id") as $z) {
            if ((float) $z['verfuegbar'] >= (float) $z['mindest']) {
                continue;
            }
            $ziel = $z['soll'] ?? $z['mindest'];
            $fehlt = max(0.0, round((float) $ziel - (float) $z['verfuegbar'], 3));
            $letzter = $this->db->fetchAssociative("SELECT b.lieferant, b.preis::text AS preis, b.zeit::date::text AS am FROM lager.bewegung b JOIN lager.charge c ON c.id = b.charge
                                               WHERE c.artikel = ? AND b.art = 'zugang' AND b.eintrag IS NOT NULL ORDER BY b.nr DESC LIMIT 1", [$z['id']]);
            $g = $this->db->fetchAssociative('SELECT lieferant_name, nummer_beleg, faktor::text AS faktor FROM lager.lieferantenartikel WHERE artikel = ? ORDER BY gemerkt_am DESC LIMIT 1', [$z['id']]);
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
        // Meta-Artikel: gerechnet wird mit der Summe der Mitglieder; welches Produkt bestellt wird, entscheidet der Mensch (MA7)
        $meta = [];
        foreach ($this->db->fetchAllAssociative('SELECT id, bezeichnung, aufgabe, einheit, mindest::text AS mindest, soll::text AS soll FROM lager.meta_artikel WHERE NOT stillgelegt AND mindest IS NOT NULL ORDER BY lower(bezeichnung), id') as $m) {
            $mitglieder = [];
            $verfuegbar = 0.0;
            $bestand = 0.0;
            foreach ($this->db->fetchAllAssociative("SELECT a.id, a.nummer, a.bezeichnung,
                        coalesce((SELECT sum(s.menge) FROM lager.bestand s JOIN lager.charge c ON c.id = s.charge
                                   WHERE c.artikel = a.id AND NOT c.gesperrt AND (c.verfall IS NULL OR c.verfall >= current_date)), 0)::text AS verfuegbar,
                        coalesce((SELECT sum(s.menge) FROM lager.bestand s JOIN lager.charge c ON c.id = s.charge WHERE c.artikel = a.id), 0)::text AS bestand
                   FROM lager.artikel a WHERE a.meta_artikel = ? AND NOT a.stillgelegt ORDER BY lower(a.bezeichnung), a.id", [$m['id']]) as $z) {
                $letzter = $this->db->fetchAssociative("SELECT b.lieferant, b.preis::text AS preis, b.zeit::date::text AS am FROM lager.bewegung b JOIN lager.charge c ON c.id = b.charge
                                                   WHERE c.artikel = ? AND b.art = 'zugang' AND b.eintrag IS NOT NULL ORDER BY b.nr DESC LIMIT 1", [$z['id']]);
                $g = $this->db->fetchAssociative('SELECT lieferant_name, nummer_beleg, faktor::text AS faktor FROM lager.lieferantenartikel WHERE artikel = ? ORDER BY gemerkt_am DESC LIMIT 1', [$z['id']]);
                $verfuegbar += (float) $z['verfuegbar'];
                $bestand += (float) $z['bestand'];
                $mitglieder[] = ['artikel' => $z['id'], 'nummer' => $z['nummer'], 'bezeichnung' => $z['bezeichnung'], 'verfuegbar' => self::menge($z['verfuegbar']),
                    'lieferant' => $letzter === false ? ($g === false ? null : $g['lieferant_name']) : ($letzter['lieferant'] ?? ($g === false ? null : $g['lieferant_name'])),
                    'lieferant_nummer' => $g === false ? null : $g['nummer_beleg'], 'faktor' => self::menge(number_format($g === false ? 1.0 : (float) $g['faktor'], 3, '.', '')),
                    'letzter_preis' => $letzter === false ? null : $letzter['preis'], 'letzter_zugang' => $letzter === false ? null : $letzter['am']];
            }
            if (round($verfuegbar, 3) >= (float) $m['mindest']) {
                continue;
            }
            $fehlt = max(0.0, round((float) ($m['soll'] ?? $m['mindest']) - $verfuegbar, 3));
            foreach ($mitglieder as $n => $x) {
                $mitglieder[$n]['bestelleinheiten'] = (float) $x['faktor'] > 0 ? (int) ceil(round($fehlt / (float) $x['faktor'], 6)) : null;
            }
            $meta[] = ['meta' => $m['id'], 'bezeichnung' => $m['bezeichnung'], 'aufgabe' => $m['aufgabe'], 'einheit' => $m['einheit'], 'mindest' => self::menge($m['mindest']),
                'soll' => $m['soll'] === null ? null : self::menge($m['soll']), 'verfuegbar' => self::menge(number_format($verfuegbar, 3, '.', '')),
                'nicht_verfuegbar' => self::menge(number_format($bestand - $verfuegbar, 3, '.', '')), 'vorschlag' => self::menge(number_format($fehlt, 3, '.', '')), 'mitglieder' => $mitglieder];
        }
        return ['heute' => (string) $this->db->fetchOne('SELECT current_date::text'), 'artikel' => $liste, 'meta' => $meta];
    }

    /**
     * Artikel zur Auswahl in einer Anforderung: ohne stillgelegte und ohne Betäubungsmittel (die führt das Lager nicht).
     *
     * @return list<array{id: string, nummer: ?string, bezeichnung: string, einheit: string, gtin: ?string, meta_artikel: ?string, in_stuecken: bool}>
     */
    public function artikel(): array
    {
        return array_map(fn (array $z) => ['id' => (string) $z['id'], 'nummer' => $z['nummer'], 'bezeichnung' => (string) $z['bezeichnung'], 'einheit' => (string) $z['einheit'],
            'gtin' => $z['gtin'], 'meta_artikel' => $z['meta_artikel'], 'in_stuecken' => (bool) $z['in_stuecken']],
            $this->db->fetchAllAssociative('SELECT id, nummer, bezeichnung, einheit, gtin, meta_artikel, in_stuecken FROM lager.artikel WHERE NOT stillgelegt AND NOT btm ORDER BY lower(bezeichnung), id'));
    }

    /** @return list<array{id: string, bezeichnung: string, aufgabe: ?string, einheit: string}> Artikelgruppen, ohne stillgelegte */
    public function gruppen(): array
    {
        return array_map(fn (array $z) => ['id' => (string) $z['id'], 'bezeichnung' => (string) $z['bezeichnung'], 'aufgabe' => $z['aufgabe'], 'einheit' => (string) $z['einheit']],
            $this->db->fetchAllAssociative('SELECT id, bezeichnung, aufgabe, einheit FROM lager.meta_artikel WHERE NOT stillgelegt ORDER BY lower(bezeichnung), id'));
    }

    /** Ein Artikel nach seiner Kennung – auch ein stillgelegter; null, wenn es ihn nicht gibt. @return ?array<string, mixed> */
    public function einArtikel(string $id): ?array
    {
        $z = $this->db->fetchAssociative('SELECT id, nummer, bezeichnung, einheit, gtin, meta_artikel, in_stuecken, stillgelegt, btm FROM lager.artikel WHERE id = ?', [$id]);
        return $z === false ? null : ['id' => (string) $z['id'], 'nummer' => $z['nummer'], 'bezeichnung' => (string) $z['bezeichnung'], 'einheit' => (string) $z['einheit'], 'gtin' => $z['gtin'],
            'meta_artikel' => $z['meta_artikel'], 'in_stuecken' => (bool) $z['in_stuecken'], 'stillgelegt' => (bool) $z['stillgelegt'], 'btm' => (bool) $z['btm']];
    }

    /**
     * Einen Artikel anlegen (BA12): dieselbe Prüfung und dieselbe Funktion wie im Bestand.
     *
     * @param array<string, mixed> $angaben wie POST /lager/artikel
     * @return array<string, mixed> der angelegte Artikel wie in `artikel()`
     */
    public function artikelAnlegen(array $angaben): array
    {
        $id = (string) $this->db->fetchOne('SELECT lager.artikel_setzen(?, NULL, ?::jsonb)',
            [$this->subjekt, json_encode(WebController::artikelPruefen($angaben), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        return (array) $this->einArtikel($id);
    }

    /** Eine GTIN eintragen, wo am Artikel keine steht – eine vorhandene bleibt (BA12). */
    public function gtinErgaenzen(string $artikel, string $gtin): void
    {
        $this->db->executeStatement('SELECT lager.artikel_gtin_ergaenzen(?, ?::uuid, ?)', [$this->subjekt, $artikel, $gtin]);
    }

    /**
     * Der Artikel lernt mit (BA24): Lieferant und dessen Artikelnummer am Artikel merken. Eine Zuordnung, die es schon
     * gibt, bleibt unverändert – ihr Faktor ist am Beleg gepflegt und wird hier nie zurückgesetzt.
     */
    public function bezugsquelleMerken(string $artikel, string $lieferant, string $nummer): void
    {
        $l = mb_strtolower(trim($lieferant));
        $n = mb_strtolower(trim($nummer));
        if ($l === '' || $n === '' || $this->db->fetchOne('SELECT 1 FROM lager.lieferantenartikel WHERE lieferant = ? AND nummer = ?', [$l, $n]) !== false) {
            return;
        }
        $this->db->executeStatement('SELECT lager.merken(?, ?, ?, ?::uuid, 1, ?, ?)', [$this->subjekt, $l, $n, $artikel, trim($lieferant), trim($nummer)]);
    }

    /** „12.000“ → „12“, „0.500“ → „0.5“ */
    private static function menge(string $w): string
    {
        return str_contains($w, '.') ? rtrim(rtrim($w, '0'), '.') : $w;
    }
}
