<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Arbeitsvorrat;

/**
 * Einträge, die mit hoher Wahrscheinlichkeit denselben Vorgang meinen (ARBEITSVORRAT.md X3 Stufe 3, IMPORT.md C8):
 * gleicher Betrag und dieselbe Gegenpartei oder dieselbe Belegnummer – etwa die Abbuchung aus einem Kontoauszug und
 * die Rechnung aus dem Belegarchiv. Hier wird nur markiert; zusammengeführt wird nie von selbst.
 */
final class Aehnlich
{
    /**
     * @param list<array{id: string, betrag: ?string, gegenpartei: ?string, belegnummer: ?string}> $eintraege
     * @return array<string, int> ID → Nummer der Gruppe (ab 1); Einträge ohne Gegenstück fehlen
     */
    public static function gruppen(array $eintraege): array
    {
        $nachBetrag = [];
        foreach ($eintraege as $e) {
            if ($e['betrag'] !== null && $e['betrag'] !== '') {
                $nachBetrag[number_format((float) $e['betrag'], 2, '.', '')][] = $e;
            }
        }
        $eltern = [];
        $finden = function (string $x) use (&$eltern, &$finden): string {
            $eltern[$x] ??= $x;
            return $eltern[$x] === $x ? $x : ($eltern[$x] = $finden($eltern[$x]));
        };
        foreach ($nachBetrag as $liste) {
            $n = count($liste);
            if ($n < 2 || $n > 200) {
                continue;   // sehr viele gleiche Beträge (z. B. eine Monatsgebühr über Jahre) sagen nichts mehr aus
            }
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if (self::gleichePartei($liste[$i]['gegenpartei'], $liste[$j]['gegenpartei']) || self::gleicheNummer($liste[$i]['belegnummer'], $liste[$j]['belegnummer'])) {
                        $eltern[$finden($liste[$i]['id'])] = $finden($liste[$j]['id']);
                    }
                }
            }
        }
        $erg = [];
        $nummern = [];
        foreach ($eintraege as $e) {
            if (!isset($eltern[$e['id']])) {
                continue;
            }
            $w = $finden($e['id']);
            $nummern[$w] ??= count($nummern) + 1;
            $erg[$e['id']] = $nummern[$w];
        }
        return $erg;
    }

    /** Name ohne Rechtsform, Satzzeichen und Groß-/Kleinschreibung. */
    public static function kern(?string $name): string
    {
        $s = strtr(mb_strtolower((string) $name), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = (string) preg_replace('/\b(gmbh|mbh|ag|kg|ohg|ug|gbr|e\.?\s?k\.?|e\.?\s?v\.?|co|inc|ltd|se|und|sagt|danke)\b/u', ' ', $s);
        return (string) preg_replace('/[^a-z0-9]+/', '', $s);
    }

    private static function gleichePartei(?string $a, ?string $b): bool
    {
        $x = self::kern($a);
        $y = self::kern($b);
        if (strlen($x) < 4 || strlen($y) < 4) {
            return false;
        }
        // der Kontoauszug nennt oft mehr als den Namen („MUSTERLIEFERANT GMBH BERLIN“) – einer enthält den anderen
        return $x === $y || str_contains($x, $y) || str_contains($y, $x);
    }

    private static function gleicheNummer(?string $a, ?string $b): bool
    {
        $x = preg_replace('/[^a-z0-9]+/', '', mb_strtolower((string) $a));
        return strlen((string) $x) >= 3 && $x === preg_replace('/[^a-z0-9]+/', '', mb_strtolower((string) $b));
    }
}
