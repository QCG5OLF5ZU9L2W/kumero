<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

use Kumero\Modul\Personal\Kuendigung;

/**
 * Kündigungsfrist aus einer Regel (F148): Probezeit, Grundfrist, Staffel nach vollen Jahren im Betrieb.
 * Ohne Datenbank; die Regel ist die Vorgabe aus `module/personal/regelwerk/kuendigung-de.json`.
 */
final class PersonalKuendigungTest extends Rahmen
{
    public function testFristen(): void
    {
        $g = json_decode((string) file_get_contents(dirname(__DIR__) . '/module/personal/regelwerk/kuendigung-de.json'), true)['gesetz']['regel'];
        $ag = fn (string $beginn, string $tag, ?string $probe = null) => Kuendigung::auskunft($g, $beginn, $probe, $tag)['arbeitgeber'];
        $this->gleich([0, 1, 0, 2, 0], [Kuendigung::jahre('2020-03-01', '2021-02-28'), Kuendigung::jahre('2020-03-01', '2021-03-01'), Kuendigung::jahre('2020-02-29', '2021-02-28'),
            Kuendigung::jahre('2020-02-29', '2022-03-01'), Kuendigung::jahre('2020-03-01', '2019-01-01')], 'volle Jahre im Betrieb');
        $this->gleich('2 Wochen zu jedem Tag (Probezeit)', $ag('2026-01-01', '2026-06-30', '2026-06-30'), 'Probezeit bis einschließlich ihres letzten Tages');
        $this->gleich('4 Wochen zum 15. oder zum Monatsende', $ag('2026-01-01', '2026-07-01', '2026-06-30'), 'danach die Grundfrist');
        $this->gleich('4 Wochen zum 15. oder zum Monatsende', $ag('2025-01-01', '2026-12-31'), 'unter zwei Jahren: Grundfrist');
        $stufen = [];
        foreach ([2 => 1, 5 => 2, 8 => 3, 10 => 4, 12 => 5, 15 => 6, 20 => 7] as $jahre => $monate) {
            $stufen[] = $ag('2000-01-01', (2000 + $jahre) . '-01-01') === ($monate === 1 ? '1 Monat' : "$monate Monate") . " zum Monatsende (ab $jahre Jahren im Betrieb)";
        }
        $this->gleich(array_fill(0, 7, true), $stufen, 'die sieben Stufen des § 622 Abs. 2 BGB');
        $this->gleich('7 Monate zum Monatsende (ab 20 Jahren im Betrieb)', $ag('1990-01-01', '2026-11-21'), 'über der letzten Stufe bleibt es bei ihr');
        $this->gleich('4 Wochen zum 15. oder zum Monatsende', Kuendigung::auskunft($g, '1990-01-01', null, '2026-11-21')['person'], 'für die Person bleibt die Grundfrist');
        $t = ['probezeit_wochen' => 0, 'grund_wochen' => 1, 'grund_termin' => 'jeder_tag', 'staffel' => [['ab_jahren' => 1, 'monate' => 2]], 'arbeitnehmer' => 'wie_arbeitgeber'];
        $this->gleich(['ohne Frist (Probezeit)', '1 Woche zu jedem Tag', '2 Monate zum Monatsende (ab 1 Jahr im Betrieb)'],
            [Kuendigung::auskunft($t, '2026-01-01', '2026-03-31', '2026-02-01')['person'], Kuendigung::auskunft($t, '2026-01-01', null, '2026-06-01')['person'], Kuendigung::auskunft($t, '2026-01-01', null, '2027-06-01')['person']],
            'ein Tarifvertrag: ohne Frist in der Probezeit, eine Woche, dieselbe Staffel für die Person');
        $this->gleich([null, 'gesetzlich – die Regel fehlt'], [Kuendigung::zeile('individuell', null, null, null, '2026-01-01', null, '2026-06-01'), Kuendigung::zeile('gesetzlich', null, null, null, '2026-01-01', null, '2026-06-01')], 'ohne Wortlaut nichts; ohne Regel sagt die Zeile, was fehlt');
    }
}
