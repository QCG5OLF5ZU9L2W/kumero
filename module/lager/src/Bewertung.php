<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Lager;

/**
 * Wert des Bestands aus den Bewegungen (LAGER.md Stufe 3, WW13). Gerechnet wird in ganzen Zahlen: Mengen in
 * Tausendsteln, Preise in Zehntausendstel Euro – ohne Gleitkomma, damit dieselben Bewegungen immer denselben Wert geben.
 *
 * - `letzter`: Bestand × Einstandspreis des letzten Zugangs, der einen Preis trägt.
 * - `durchschnitt`: gleitender Durchschnitt – jeder Zugang mit Preis hebt Menge und Wert, jeder Abgang nimmt zum
 *   Durchschnitt heraus. Ein Zugang ohne Preis (Anfangsbestand, Inventurfund) geht zum laufenden Durchschnitt ein.
 */
final class Bewertung
{
    /**
     * @param iterable<array{artikel: string, art: string, menge: string, preis: ?string}> $bewegungen in der Reihenfolge, in der sie gebucht wurden
     * @return array<string, array{menge: int, wert: ?int, preis: ?int, ohne_preis: bool}> je Artikel: Menge (Tausendstel), Wert (Cent), Preis je Einheit (Zehntausendstel Euro)
     */
    public static function rechnen(iterable $bewegungen, string $verfahren): array
    {
        $s = [];
        foreach ($bewegungen as $b) {
            $x = &$s[$b['artikel']];
            $x ??= ['menge' => 0, 'wert' => 0, 'letzter' => null, 'ohne' => 0];
            $m = self::ganz($b['menge'], 3);
            if ($b['art'] === 'zugang') {
                $p = $b['preis'] === null ? null : self::ganz($b['preis'], 4);
                if ($p !== null) {
                    $x['wert'] += $m * $p;
                    $x['letzter'] = $p;
                } elseif ($x['menge'] - $x['ohne'] > 0) {
                    $x['wert'] += self::teilen($x['wert'] * $m, $x['menge'] - $x['ohne']);   // zum laufenden Durchschnitt
                } else {
                    $x['ohne'] += $m;                                                         // noch kein Preis bekannt
                }
                $x['menge'] += $m;
            } elseif ($b['art'] === 'entnahme' || $b['art'] === 'ausbuchung') {
                $bewertet = $x['menge'] - $x['ohne'];
                // erst geht, was einen Wert hat – was ohne Preis da ist, bleibt als „ohne Preis“ stehen, bis es aufgebraucht ist
                $aus = min($m, $bewertet);
                if ($bewertet > 0) {
                    $x['wert'] -= self::teilen($x['wert'] * $aus, $bewertet);
                }
                $x['ohne'] -= $m - $aus;
                $x['menge'] -= $m;
            }
            unset($x);
        }
        $erg = [];
        foreach ($s as $artikel => $x) {
            if ($x['menge'] <= 0) {
                continue;
            }
            if ($verfahren === 'letzter') {
                $erg[$artikel] = ['menge' => $x['menge'], 'preis' => $x['letzter'], 'ohne_preis' => $x['letzter'] === null,
                    'wert' => $x['letzter'] === null ? null : self::teilen($x['menge'] * $x['letzter'], 100000)];
            } else {
                $bewertet = $x['menge'] - $x['ohne'];
                $erg[$artikel] = ['menge' => $x['menge'], 'preis' => $bewertet > 0 ? self::teilen($x['wert'], $bewertet) : null, 'ohne_preis' => $x['ohne'] > 0,
                    'wert' => $bewertet > 0 ? self::teilen($x['wert'], 100000) : null];
            }
        }
        return $erg;
    }

    /** Dezimal-String in eine ganze Zahl mit so vielen Nachkommastellen: „1.89“, 4 → 18900. */
    public static function ganz(string $w, int $stellen): int
    {
        [$g, $n] = array_pad(explode('.', $w, 2), 2, '');
        return (int) $g * 10 ** $stellen + (int) substr(str_pad($n, $stellen, '0'), 0, $stellen);
    }

    /** Ganze Zahl mit so vielen Nachkommastellen als Dezimal-String: 18900, 4 → „1.8900“. */
    public static function dezimal(int $w, int $stellen): string
    {
        return sprintf('%s%d.%0' . $stellen . 'd', $w < 0 ? '-' : '', intdiv(abs($w), 10 ** $stellen), abs($w) % 10 ** $stellen);
    }

    /** Division mit kaufmännischer Rundung, für Werte ab null. */
    private static function teilen(int $z, int $n): int
    {
        return intdiv(2 * $z + $n, 2 * $n);
    }
}
