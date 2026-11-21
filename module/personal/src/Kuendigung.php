<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Personal;

/**
 * Kündigungsfrist aus einer Regel (F148): gesetzlich, nach einem Tarifvertrag des Mandanten oder individuell.
 *
 * Eine Regel nennt die Frist in der Probezeit, die Grundfrist mit ihrem Termin, eine Staffel für die Kündigung durch
 * den Arbeitgeber nach vollen Jahren im Betrieb und ob für die Person die Grundfrist gilt oder dieselbe Staffel.
 * Gerechnet wird, was an einem Stichtag gilt – maßgeblich ist im Ernstfall der Tag, an dem die Kündigung zugeht.
 * Keine Rechtsberatung: Die Vorgabe (regelwerk/kuendigung-de.json) prüft der Verantwortliche.
 */
final class Kuendigung
{
    private const TERMINE = ['15_oder_monatsende' => 'zum 15. oder zum Monatsende', 'monatsende' => 'zum Monatsende', 'jeder_tag' => 'zu jedem Tag'];

    /** Volle Jahre zwischen zwei Tagen (JJJJ-MM-TT); nie negativ. */
    public static function jahre(string $beginn, string $stichtag): int
    {
        if ($stichtag < $beginn) {
            return 0;
        }
        return (int) (new \DateTimeImmutable($beginn))->diff(new \DateTimeImmutable($stichtag))->y;
    }

    private static function wochen(int $n): string
    {
        return $n === 1 ? '1 Woche' : "$n Wochen";
    }

    /**
     * @param array<string, mixed> $regel
     * @return array{probezeit: bool, jahre: int, arbeitgeber: string, person: string}
     */
    public static function auskunft(array $regel, string $beginn, ?string $probezeitBis, string $stichtag): array
    {
        $jahre = self::jahre($beginn, $stichtag);
        if ($probezeitBis !== null && $stichtag <= $probezeitBis && $stichtag >= $beginn) {
            $n = (int) $regel['probezeit_wochen'];
            $t = $n === 0 ? 'ohne Frist (Probezeit)' : self::wochen($n) . ' zu jedem Tag (Probezeit)';
            return ['probezeit' => true, 'jahre' => $jahre, 'arbeitgeber' => $t, 'person' => $t];
        }
        $grund = self::wochen((int) $regel['grund_wochen']) . ' ' . self::TERMINE[(string) $regel['grund_termin']];
        $stufe = null;
        foreach ($regel['staffel'] as $s) {
            if ($jahre >= (int) $s['ab_jahren']) {
                $stufe = $s;
            }
        }
        $ag = $stufe === null ? $grund
            : ((int) $stufe['monate'] === 1 ? '1 Monat' : (int) $stufe['monate'] . ' Monate') . ' zum Monatsende (ab ' . ((int) $stufe['ab_jahren'] === 1 ? '1 Jahr' : (int) $stufe['ab_jahren'] . ' Jahren') . ' im Betrieb)';
        return ['probezeit' => false, 'jahre' => $jahre, 'arbeitgeber' => $ag, 'person' => $regel['arbeitnehmer'] === 'wie_arbeitgeber' ? $ag : $grund];
    }

    /** Eine Zeile für Akte und „Meine Daten“. */
    public static function zeile(string $art, ?string $regelName, ?array $regel, ?string $wortlaut, string $beginn, ?string $probezeitBis, string $stichtag): ?string
    {
        if ($art === 'individuell') {
            return $wortlaut === null || $wortlaut === '' ? null : "individuell: $wortlaut";
        }
        if ($regel === null) {
            return ($art === 'tarif' ? 'nach Tarifvertrag' : 'gesetzlich') . ' – die Regel fehlt';
        }
        $a = self::auskunft($regel, $beginn, $probezeitBis, $stichtag);
        $kopf = $art === 'tarif' ? "nach Tarifvertrag „{$regelName}“" : 'gesetzlich';
        return $a['arbeitgeber'] === $a['person'] ? "$kopf – heute: {$a['person']}"
            : "$kopf – heute: Arbeitgeber {$a['arbeitgeber']} · Arbeitnehmer {$a['person']}";
    }
}
