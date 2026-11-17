<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Kontenrahmen;

/**
 * Kontoart aus dem Bilanz- oder GuV-Posten, den der DATEV-Kontenrahmen je Konto nennt (F81 B2, B3).
 * Grundlage ist die gesetzliche Gliederung (§§ 266, 275 HGB) in posten/hgb.json.
 *
 * Ein Posten kann Alternativen nennen („Sonstige Verbindlichkeiten oder Sonstige
 * Vermögensgegenstände“ – Wechselposten je nach Saldo). Dann gilt die erste, und das Konto wird
 * zur Bestätigung angezeigt, sobald die Alternativen verschiedene Kontoarten haben.
 */
final class Posten
{
    /** @var ?array<string, array{name: string, kontoart: ?string, hinweis?: string}> */
    private static ?array $liste = null;

    /** @return array{kontoart: ?string, hinweis: ?string, unbekannt: list<string>} */
    public static function zuordnen(string $text): array
    {
        $liste = self::liste();
        $teile = preg_split('/ oder /u', $text) ?: [$text];
        $erkannt = [];
        $unbekannt = [];
        for ($i = 0; $i < count($teile);) {
            $gefunden = false;
            for ($j = count($teile) - 1; $j >= $i; $j--) {
                $kandidat = self::norm(implode(' oder ', array_slice($teile, $i, $j - $i + 1)));
                if (isset($liste[$kandidat])) {
                    $erkannt[] = $liste[$kandidat];
                    $i = $j + 1;
                    $gefunden = true;
                    break;
                }
            }
            if (!$gefunden) {
                $unbekannt[] = trim($teile[$i]);
                $i++;
            }
        }
        if ($erkannt === []) {
            return ['kontoart' => null, 'hinweis' => 'Posten unbekannt: ' . $text, 'unbekannt' => $unbekannt];
        }
        $erster = $erkannt[0];
        $arten = array_values(array_unique(array_filter(array_column($erkannt, 'kontoart'))));
        $hinweis = $erster['hinweis'] ?? null;
        if (count($arten) > 1) {
            $hinweis = 'Wechselposten je nach Saldo: ' . implode(' oder ', array_map(fn ($a) => ucfirst($a), $arten));
        }
        return ['kontoart' => $erster['kontoart'], 'hinweis' => $hinweis, 'unbekannt' => $unbekannt];
    }

    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = (string) preg_replace('/\s+[hs]-saldo$/u', '', $s);
        $s = (string) preg_replace('/\s*\(allgemein\)$/u', '', $s);
        $s = (string) preg_replace('/\s*\/\s*/u', '/', $s);
        return (string) preg_replace('/\s+/u', ' ', $s);
    }

    /** @return array<string, array{name: string, kontoart: ?string, hinweis?: string}> */
    private static function liste(): array
    {
        if (self::$liste === null) {
            $d = json_decode((string) file_get_contents(dirname(__DIR__) . '/posten/hgb.json'), true, 8, JSON_THROW_ON_ERROR);
            self::$liste = [];
            foreach ($d['posten'] as $p) {
                self::$liste[self::norm($p['name'])] = $p;
            }
        }
        return self::$liste;
    }
}
