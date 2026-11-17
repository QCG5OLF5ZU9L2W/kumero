<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Kontenrahmen;

use Kumero\Plattform\Jcs;

/**
 * Liest eine Quelle (DATEV-PDF oder CSV) in Rohdaten und baut daraus mit Regelwerk und
 * Korrekturen eine Vorlage. Gemeinsam für Kommandozeile und Weboberfläche (F80).
 */
final class Einlesen
{
    /**
     * @return array{art: string, fassung: ?string, quelle: array<string, mixed>, konten: list<array<string, mixed>>,
     *               bereiche: list<array<string, mixed>>, bereichsfunktionen: list<array<string, string>>, hinweise: list<array<string, mixed>>}
     */
    public static function datei(string $datei, string $art): array
    {
        $zeit = gmdate('Y-m-d\TH:i:s\Z');
        if ($art === 'pdf') {
            $pdf = PdfLeser::textAus($datei);
            // Layout mit Bilanz-/GuV-Posten (SKR 03/04 ab BilRUG) oder einfaches Layout (z. B. SKR 81, 2015)
            $g = PdfPosten::erkennen($pdf['text']) ? PdfPosten::lesen($datei, $pdf['text']) : PdfLeser::lesen($pdf['text']);
            return ['art' => 'pdf', 'fassung' => $g['fassung'], 'rahmen_erkannt' => $g['rahmen'], 'quelle' => ['art' => 'datev-pdf', 'datei_sha256' => $pdf['sha256'], 'eingelesen_am' => $zeit],
                    'konten' => $g['konten'], 'bereiche' => $g['bereiche'], 'bereichsfunktionen' => $g['bereichsfunktionen'], 'hinweise' => $g['hinweise']];
        }
        if ($art === 'csv') {
            $c = CsvLeser::lesen($datei);
            $konten = array_map(fn ($z) => array_filter(['nr' => $z['nr'], 'bezeichnung' => $z['bezeichnung'] ?? '', 'kontoart' => $z['kontoart']],
                fn ($w) => $w !== null), array_filter($c['zeilen'], fn ($z) => !$z['weglassen']));
            return ['art' => 'csv', 'fassung' => null, 'quelle' => ['art' => 'csv', 'datei_sha256' => $c['sha256'], 'eingelesen_am' => $zeit],
                    'konten' => array_values($konten), 'bereiche' => [], 'bereichsfunktionen' => [], 'hinweise' => $c['hinweise']];
        }
        throw new \InvalidArgumentException('Art muss pdf oder csv sein');
    }

    /**
     * @param array<string, mixed> $roh Ergebnis von datei()
     * @param array<string, array{kontoart?: string, bezeichnung?: string, weglassen?: bool}> $korrekturen Nummer → Korrektur
     * @param ?array<string, mixed> $ergaenzung CSV-Ergänzung (Kommandozeile); schließt $korrekturen aus
     */
    public static function vorlage(string $name, string $rahmen, Regelwerk $regeln, array $roh, array $korrekturen = [], ?array $ergaenzung = null): Vorlage
    {
        if ($ergaenzung === null && $korrekturen !== []) {
            ksort($korrekturen, SORT_STRING);
            $zeilen = [];
            foreach ($korrekturen as $nr => $k) {
                $zeilen[] = ['nr' => (string) $nr, 'bezeichnung' => $k['bezeichnung'] ?? null, 'kontoart' => $k['kontoart'] ?? null,
                             'weglassen' => (bool) ($k['weglassen'] ?? false)];
            }
            $ergaenzung = ['zeilen' => $zeilen, 'hinweise' => [], 'sha256' => Jcs::sha256($korrekturen)];
        }
        $hinweise = $roh['hinweise'];
        $erkannt = $roh['rahmen_erkannt'] ?? null;
        if ($erkannt !== null && $erkannt !== $rahmen) {
            array_unshift($hinweise, ['art' => 'rahmen', 'text' => "Die Datei ist ein $erkannt, gewählt ist das Regelwerk für $rahmen – Kontoarten passen dann nicht. Bitte mit dem passenden Regelwerk neu einlesen."]);
        }
        return Vorlage::bauen($name, $rahmen, $roh['fassung'], $roh['quelle'], $regeln, $roh['konten'], $roh['bereiche'],
            $roh['bereichsfunktionen'], $hinweise, $ergaenzung);
    }
}
