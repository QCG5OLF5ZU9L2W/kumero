<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

use Kumero\Modul\Anlagen\Rechner;

/** Rechnung der Anlagenbuchhaltung (ANLAGEN.md Abschnitt 4 und 5) – ohne Datenbank, alle Güter erfunden. */
final class AnlagenRechnerTest extends Rahmen
{
    private const G = ['kosten' => '1200.00', 'zugang' => '2026-03-15', 'art' => 'linear', 'nutzungsdauer' => 3, 'restwert' => 'null', 'abgang' => null];

    /** @return list<int> AfA, Rest beim Abgang und Buchwert am Ende je Jahr */
    private static function jahre(array $g, int $von, int $bis, string $feld = 'afa'): array
    {
        $erg = [];
        for ($j = $von; $j <= $bis; $j++) {
            $erg[] = Rechner::jahr($g, "$j-01-01", "$j-12-31")[$feld];
        }
        return $erg;
    }

    public function testLinearNachMonaten(): void
    {
        $this->gleich([0, 33333, 40000, 40000, 6667, 0], self::jahre(self::G, 2025, 2030), 'drei Jahre ab März: zehn Monate im ersten Jahr, zwei im letzten; der Monat des Zugangs zählt voll');
        $this->gleich(120000, array_sum(self::jahre(self::G, 2025, 2030)), 'die Summe ist genau der Anschaffungspreis – kein Rundungsrest');
        $this->gleich([86667, 46667, 6667, 0], self::jahre(self::G, 2026, 2029, 'buchwert_ende'), 'Buchwert am Jahresende');
        $this->gleich([0, 86667], [Rechner::jahr(self::G, '2026-01-01', '2026-12-31')['buchwert_anfang'], Rechner::jahr(self::G, '2027-01-01', '2027-12-31')['buchwert_anfang']], 'Buchwert am Jahresanfang');
        $e = ['restwert' => 'erinnerung'] + self::G;
        $this->gleich([119900, 100, 100], [array_sum(self::jahre($e, 2026, 2031)), Rechner::jahr($e, '2029-01-01', '2029-12-31')['buchwert_ende'], Rechner::jahr($e, '2035-01-01', '2035-12-31')['buchwert_ende']],
            'Erinnerungswert: 1,00 € bleibt stehen, solange das Gut vorhanden ist');
        // abweichendes Wirtschaftsjahr (Juli bis Juni)
        $this->gleich([13333, 40000], [Rechner::jahr(self::G, '2025-07-01', '2026-06-30')['afa'], Rechner::jahr(self::G, '2026-07-01', '2027-06-30')['afa']], 'Wirtschaftsjahr ab Juli: vier Monate im ersten Jahr');
    }

    public function testGwgSammelpostenKeine(): void
    {
        // gemietet oder geleast: ohne Kosten – nichts zu rechnen, kein Plan (LAGER.md IN9)
        $fremd = ['art' => 'fremd', 'kosten' => null, 'abgang' => '2026-09-30'] + self::G;
        $this->gleich([[0, 0, 0], ['buchwert_anfang' => 0, 'afa' => 0, 'abgang_rest' => 0, 'buchwert_ende' => 0, 'zugang_im_jahr' => false, 'abgang_im_jahr' => false], []],
            [self::jahre($fremd, 2025, 2027), Rechner::jahr($fremd, '2026-01-01', '2026-12-31'), Rechner::plan($fremd, '2026-01-01')], 'fremd: kein Wert, keine Absetzung, kein Rest bei der Rückgabe');
        $gwg = ['art' => 'gwg', 'kosten' => '600.00'] + self::G;
        $this->gleich([0, 60000, 0], self::jahre($gwg, 2025, 2027), 'GWG: ganz im Jahr der Anschaffung');
        $this->gleich(0, Rechner::jahr($gwg, '2026-01-01', '2026-12-31')['buchwert_ende'], 'Buchwert 0');
        $sp = ['art' => 'sammelposten', 'kosten' => '901.00', 'zugang' => '2026-11-20', 'abgang' => '2027-02-01'] + self::G;
        $this->gleich([18020, 18020, 18020, 18020, 18020, 0], self::jahre($sp, 2026, 2031), 'Sammelposten: fünf Jahre je ein Fünftel, unabhängig vom Monat – und vom Abgang');
        $this->gleich([0, 0], [Rechner::jahr($sp, '2027-01-01', '2027-12-31')['abgang_rest'], Rechner::jahr($sp, '2030-01-01', '2030-12-31')['buchwert_ende']], 'kein Rest beim Abgang, am Ende 0');
        $keine = ['art' => 'keine', 'kosten' => '50000.00'] + self::G;
        $this->gleich([[0, 0], 5000000], [self::jahre($keine, 2026, 2027), Rechner::jahr($keine, '2040-01-01', '2040-12-31')['buchwert_ende']], 'nicht abnutzbar: keine AfA, der Buchwert bleibt');
    }

    public function testAbgangUndUebernahme(): void
    {
        $ab = ['abgang' => '2027-06-10'] + self::G;
        $j = Rechner::jahr($ab, '2027-01-01', '2027-12-31');
        $this->gleich([20000, 66667, 0, true], [$j['afa'], $j['abgang_rest'], $j['buchwert_ende'], $j['abgang_im_jahr']], 'Abgang im Juni: AfA bis einschließlich Juni, der Rest des Buchwerts geht mit dem Abgang');
        $this->gleich(120000, 33333 + $j['afa'] + $j['abgang_rest'], 'AfA und Rest ergeben zusammen den Anschaffungspreis');
        $this->gleich(['buchwert_anfang' => 0, 'afa' => 0, 'abgang_rest' => 0, 'buchwert_ende' => 0, 'zugang_im_jahr' => false, 'abgang_im_jahr' => false], Rechner::jahr($ab, '2028-01-01', '2028-12-31'), 'danach ist das Gut weg');
        // Bestand von vor kumero: Büromöbel aus 2020, 13 Jahre, bis Ende 2025 sind 600,00 abgesetzt
        $alt = ['kosten' => '1300.00', 'zugang' => '2020-01-10', 'art' => 'linear', 'nutzungsdauer' => 13, 'restwert' => 'null', 'abgang' => null, 'uebernahme' => '2026-01-01', 'bisherige_afa' => '600.00'];
        $j = Rechner::jahr($alt, '2026-01-01', '2026-12-31');
        $this->gleich([70000, 10000, 60000], [$j['buchwert_anfang'], $j['afa'], $j['buchwert_ende']], 'Übernahme: Buchwert zum Stichtag, die AfA läuft über die Restdauer weiter');
        $this->gleich(70000, array_sum(self::jahre($alt, 2026, 2040)), 'bis zum Ende ist genau der Buchwert vom Stichtag abgesetzt');
    }

    public function testPlanUndVorschlag(): void
    {
        $plan = Rechner::plan(self::G, '2026-01-01');
        $this->gleich([4, '2026-01-01', '333.33', '66.67', '0.00'], [count($plan), $plan[0]['beginn'], $plan[0]['afa'], $plan[3]['afa'], $plan[3]['buchwert_ende']], 'Plan über vier Jahre');
        $this->gleich(1, count(Rechner::plan(['art' => 'gwg'] + self::G, '2026-01-01')), 'GWG: ein Jahr');
        $this->gleich(5, count(Rechner::plan(['art' => 'sammelposten'] + self::G, '2026-01-01')), 'Sammelposten: fünf Jahre');
        $gr = ['aufzeichnung_ab' => '250.00', 'gwg_bis' => '800.00', 'sammelposten_ab' => '250.00', 'sammelposten_bis' => '1000.00'];
        $this->gleich([['gwg', 'linear'], 'gwg'], [Rechner::vorschlag('200.00', $gr)['arten'], Rechner::vorschlag('200.00', $gr)['vorschlag']], 'bis 250 €: Sofortabzug, kein Sammelposten');
        $this->gleich(['gwg', 'sammelposten', 'linear'], Rechner::vorschlag('650.00', $gr)['arten'], '650 €: alle drei Wege');
        $this->gleich([['linear', 'sammelposten'], 'linear'], [Rechner::vorschlag('900.00', $gr)['arten'], Rechner::vorschlag('900.00', $gr)['vorschlag']], '900 €: kein Sofortabzug mehr');
        $this->gleich(['linear'], Rechner::vorschlag('1500.00', $gr)['arten'], 'über 1.000 €: nur die Verteilung');
        $this->ist(str_contains(Rechner::vorschlag('800.00', $gr)['begruendung'], '800,00 €'), 'die Grenze steht in der Begründung');
    }
}
