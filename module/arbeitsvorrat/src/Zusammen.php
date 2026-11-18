<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Arbeitsvorrat;

/**
 * Zusammenführen zweier Einträge (ARBEITSVORRAT.md X3, F105): Welcher bleibt, und welcher Wert gilt je Feld?
 * Beleg und Umsatz ergänzen sich: Der Beleg liefert die Belegangaben, der Umsatz den Geldfluss.
 * Hier wird nur vorgeschlagen; entschieden wird im Vergleich oder – wenn eingeschaltet – von selbst.
 */
final class Zusammen
{
    /** Felder, bei denen der Beleg vorgeht */
    public const VOM_BELEG = ['betreff', 'art', 'gegenpartei', 'gegenpartei_kennung', 'datum', 'belegnummer', 'betrag', 'netto', 'steuer'];
    /** Felder, bei denen der Umsatz vorgeht: Tag und Art des Geldflusses */
    public const VOM_UMSATZ = ['zahltag', 'zahlweise', 'richtung'];

    /**
     * @param array<string, mixed> $a Eintrag (wie aus der Liste)
     * @param array<string, mixed> $b
     * @return array{ziel: string, quelle: string, wahl: array<string, string>, felder: array<string, string>, eindeutig: bool}
     *         wahl: Feld → ID des Eintrags, dessen Wert vorgeschlagen ist; felder: die vorgeschlagenen Werte;
     *         eindeutig: genau einer von beiden hat einen Beleg (Voraussetzung für das Zusammenführen von selbst)
     */
    public static function vorschlag(array $a, array $b): array
    {
        $hatBeleg = fn (array $e) => ($e['belege'] ?? []) !== [];
        $eindeutig = $hatBeleg($a) !== $hatBeleg($b);
        // Es bleibt der Eintrag mit dem Beleg; sonst der ältere
        if ($eindeutig) {
            [$beleg, $umsatz] = $hatBeleg($a) ? [$a, $b] : [$b, $a];
        } else {
            [$beleg, $umsatz] = strcmp((string) $a['eingegangen_am'], (string) $b['eingegangen_am']) <= 0 ? [$a, $b] : [$b, $a];
        }
        $wahl = [];
        $felder = [];
        $leer = fn ($w) => $w === null || $w === '';
        foreach (array_merge(self::VOM_BELEG, self::VOM_UMSATZ) as $f) {
            // ohne eindeutigen Beleg geht der bleibende Eintrag überall vor
            [$erst, $dann] = $eindeutig && in_array($f, self::VOM_UMSATZ, true) ? [$umsatz, $beleg] : [$beleg, $umsatz];
            $von = !$leer($erst[$f] ?? null) ? $erst : (!$leer($dann[$f] ?? null) ? $dann : null);
            if ($von !== null) {
                $wahl[$f] = (string) $von['id'];
                $felder[$f] = (string) $von[$f];
            }
        }
        return ['ziel' => (string) $beleg['id'], 'quelle' => (string) $umsatz['id'], 'wahl' => $wahl, 'felder' => $felder, 'eindeutig' => $eindeutig];
    }
}
