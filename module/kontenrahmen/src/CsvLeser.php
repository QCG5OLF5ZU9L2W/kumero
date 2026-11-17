<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Kontenrahmen;

/**
 * CSV mit Nummer; Bezeichnung; optional Kontoart (KONTENRAHMEN.md Abschnitt 2).
 * Trennzeichen Semikolon oder Komma, UTF-8 (mit oder ohne BOM) oder Windows-1252.
 * Kontoart: aktiv, passiv, aufwand, ertrag; leer = aus dem Regelwerk; „-“ = Konto weglassen.
 * Eine erste Zeile ohne Kontonummer gilt als Überschrift.
 */
final class CsvLeser
{
    public const KONTOARTEN = ['aktiv', 'passiv', 'aufwand', 'ertrag'];

    /** @return array{zeilen: list<array{nr: string, bezeichnung: ?string, kontoart: ?string, weglassen: bool}>, hinweise: list<array<string, mixed>>, sha256: string} */
    public static function lesen(string $datei): array
    {
        if (!is_file($datei) || !is_readable($datei)) {
            throw new \RuntimeException("Datei nicht lesbar: $datei");
        }
        $roh = (string) file_get_contents($datei);
        $sha = hash('sha256', $roh);
        if (str_starts_with($roh, "\xEF\xBB\xBF")) {
            $roh = substr($roh, 3);
        } elseif (!mb_check_encoding($roh, 'UTF-8')) {
            $roh = mb_convert_encoding($roh, 'UTF-8', 'Windows-1252');
        }
        $zeilen = [];
        $hinweise = [];
        $trenner = substr_count(strtok($roh, "\n") ?: '', ';') > 0 ? ';' : ',';
        foreach (preg_split('/\R/u', $roh) as $n => $z) {
            if (trim($z) === '') {
                continue;
            }
            $f = array_map('trim', str_getcsv($z, $trenner, '"', ''));
            $nr = $f[0] ?? '';
            if (!preg_match('/^\d{1,9}$/', $nr)) {
                if ($n > 0 || $zeilen !== []) {
                    $hinweise[] = ['art' => 'csv', 'zeile' => $n + 1, 'text' => "keine Kontonummer: $z"];
                }
                continue;
            }
            $art = mb_strtolower($f[2] ?? '');
            if ($art !== '' && $art !== '-' && !in_array($art, self::KONTOARTEN, true)) {
                $hinweise[] = ['art' => 'csv', 'zeile' => $n + 1, 'text' => "unbekannte Kontoart „{$f[2]}“ bei $nr"];
                $art = '';
            }
            $zeilen[] = ['nr' => $nr, 'bezeichnung' => ($f[1] ?? '') !== '' ? $f[1] : null,
                         'kontoart' => in_array($art, self::KONTOARTEN, true) ? $art : null, 'weglassen' => $art === '-'];
        }
        return ['zeilen' => $zeilen, 'hinweise' => $hinweise, 'sha256' => $sha];
    }
}
