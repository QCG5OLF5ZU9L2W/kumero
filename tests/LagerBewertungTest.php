<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

use Kumero\Modul\Lager\Bewertung;

/**
 * Bewertung des Lagerbestands (LAGER.md Stufe 3, WW13): letzter Einstandspreis und gleitender Durchschnitt, gerechnet
 * in ganzen Zahlen. Ohne Datenbank; alle Zahlen sind erfunden.
 */
final class LagerBewertungTest extends Rahmen
{
    /** @param list<array{0: string, 1: string, 2?: ?string, 3?: string}> $b @return array<string, mixed> */
    private static function wert(array $b, string $verfahren, string $artikel = 'a'): ?array
    {
        $r = Bewertung::rechnen(array_map(fn (array $x) => ['artikel' => $x[3] ?? 'a', 'art' => $x[0], 'menge' => $x[1], 'preis' => $x[2] ?? null], $b), $verfahren)[$artikel] ?? null;
        return $r === null ? null : ['menge' => Bewertung::dezimal($r['menge'], 3), 'preis' => $r['preis'] === null ? null : Bewertung::dezimal($r['preis'], 4),
            'wert' => $r['wert'] === null ? null : Bewertung::dezimal($r['wert'], 2), 'ohne_preis' => $r['ohne_preis']];
    }

    public function testVerfahren(): void
    {
        $b = [['zugang', '10', '1.00'], ['zugang', '10', '2.00'], ['entnahme', '5']];
        $this->gleich(['menge' => '15.000', 'preis' => '2.0000', 'wert' => '30.00', 'ohne_preis' => false], self::wert($b, 'letzter'), 'letzter Einstandspreis: Bestand × letzter Preis');
        $this->gleich(['menge' => '15.000', 'preis' => '1.5000', 'wert' => '22.50', 'ohne_preis' => false], self::wert($b, 'durchschnitt'), 'gleitender Durchschnitt: der Abgang geht zum Durchschnitt heraus');
        // der Durchschnitt wandert mit jedem Zugang, nicht mit dem Abgang
        $this->gleich('2.2500', self::wert([...$b, ['zugang', '5', '4.50']], 'durchschnitt')['preis'], '15 zu 1,50 und 5 zu 4,50 ergeben 2,25');
        $this->gleich(['menge' => '2.000', 'preis' => '0.3333', 'wert' => '0.67', 'ohne_preis' => false], self::wert([['zugang', '3', '0.3333'], ['entnahme', '1']], 'durchschnitt'), 'gerundet wird erst am Ende, kaufmännisch');
        $this->gleich('0.63', self::wert([['zugang', '0.333', '1.89']], 'letzter')['wert'], 'Mengen mit Nachkommastellen');
        // Umlagerungen und fremde Artikel ändern nichts
        $this->gleich('22.50', self::wert([...$b, ['umlagerung', '7'], ['zugang', '100', '9.99', 'b']], 'durchschnitt')['wert'], 'Umlagerung und anderer Artikel ohne Einfluss');
        $this->gleich(null, self::wert([['zugang', '4', '1.00'], ['ausbuchung', '4']], 'letzter'), 'ohne Bestand steht der Artikel nicht in der Liste');
    }

    public function testOhnePreis(): void
    {
        // Anfangsbestand ohne Preis, danach ein Zugang mit Preis
        $b = [['zugang', '5'], ['zugang', '10', '1.00']];
        $this->gleich(['menge' => '15.000', 'preis' => '1.0000', 'wert' => '15.00', 'ohne_preis' => false], self::wert($b, 'letzter'), 'letzter Preis gilt für den ganzen Bestand');
        $this->gleich(['menge' => '15.000', 'preis' => '1.0000', 'wert' => '10.00', 'ohne_preis' => true], self::wert($b, 'durchschnitt'), 'Durchschnitt: was ohne Preis kam, hat keinen Wert und wird gemeldet');
        $this->gleich(['menge' => '3.000', 'preis' => null, 'wert' => null, 'ohne_preis' => true], self::wert([...$b, ['entnahme', '12']], 'durchschnitt'), 'erst geht, was einen Wert hat');
        $this->gleich(['menge' => '5.000', 'preis' => null, 'wert' => null, 'ohne_preis' => true], self::wert([['zugang', '5']], 'letzter'), 'nie ein Preis: kein Wert');
        // Zugang ohne Preis bei bekanntem Durchschnitt (Inventurfund): geht zum Durchschnitt ein
        $this->gleich(['menge' => '12.000', 'preis' => '2.0000', 'wert' => '24.00', 'ohne_preis' => false], self::wert([['zugang', '10', '2.00'], ['zugang', '2']], 'durchschnitt'), 'Fund zum laufenden Durchschnitt');
        $this->gleich([18900, '1.8900', '-0.05'], [Bewertung::ganz('1.89', 4), Bewertung::dezimal(18900, 4), Bewertung::dezimal(-5, 2)], 'Umrechnung ohne Gleitkomma');
    }
}
