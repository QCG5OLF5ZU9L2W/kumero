<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Api\V1;

/**
 * Positionen eines Belegs (LAGER.md Abschnitt 7, Stufe 0): eine gemeinsame Gestalt für alle Quellen, die Positionen
 * kennen (Sidecar von nopaper, E-Rechnung). Positionen sind ein Vorschlag – aus ihnen entsteht nie von selbst ein
 * Zugang im Lager oder eine Buchung (WW9).
 *
 * Eine Position: `bezeichnung` (Pflicht), dazu nach Vorhandensein `nr`, `menge`, `einheit`, `einzelpreis`, `netto`,
 * `satz`, `artikelnummer` (des Verkäufers), `kaeufernummer`, `gtin`, `charge`, `verfall` – alles Strings.
 * Mengen und Beträge sind Dezimal-Strings mit Punkt; ein Minus ist erlaubt (Rabatt- und Gutschriftzeilen).
 */
final class Belegpositionen
{
    public const HOECHSTENS = 500;
    private const TEXT = ['nr' => 20, 'bezeichnung' => 200, 'einheit' => 12, 'artikelnummer' => 70, 'kaeufernummer' => 70, 'charge' => 50];
    private const ZAHL = ['menge' => 4, 'einzelpreis' => 4, 'netto' => 2, 'satz' => 2];

    /**
     * Bringt rohe Positionen in die gemeinsame Gestalt. Was nicht lesbar ist, entfällt je Feld; eine Position ohne
     * Bezeichnung entfällt ganz. Es wird nichts geraten und nichts gerechnet.
     *
     * @param list<mixed> $roh
     * @return array{0: list<array<string, string>>, 1: int} Positionen und die Zahl der ausgelassenen
     */
    public static function ordnen(array $roh): array
    {
        $erg = [];
        $aus = 0;
        foreach (array_slice(array_values($roh), 0, self::HOECHSTENS) as $p) {
            $z = is_array($p) ? self::eine($p) : null;
            if ($z === null) {
                $aus++;
                continue;
            }
            $erg[] = $z;
        }
        return [$erg, $aus + max(0, count($roh) - self::HOECHSTENS)];
    }

    /** @return array<string, string>|null */
    private static function eine(array $p): ?array
    {
        $z = [];
        foreach (self::TEXT as $feld => $max) {
            $w = $p[$feld] ?? null;
            if (is_string($w) && ($w = trim((string) preg_replace('/\s+/u', ' ', $w))) !== '') {
                $z[$feld] = mb_substr($w, 0, $max);
            }
        }
        if (!isset($z['bezeichnung'])) {
            return null;
        }
        foreach (self::ZAHL as $feld => $stellen) {
            $w = $p[$feld] ?? null;
            if (is_string($w) && preg_match('/^-?\d{1,13}(\.\d{1,' . $stellen . '})?$/', $w = trim($w)) && ($feld !== 'satz' || !str_starts_with($w, '-'))) {
                $z[$feld] = self::dezimal($w, $feld === 'netto');
            }
        }
        $gtin = $p['gtin'] ?? null;
        if (is_string($gtin) && preg_match('/^\d{8,14}$/', $gtin = trim($gtin))) {
            $z['gtin'] = $gtin;
        }
        $verfall = $p['verfall'] ?? null;
        if (is_string($verfall) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $verfall, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $z['verfall'] = $verfall;
        }
        return $z;
    }

    /** Dezimal-String ohne Gleitkomma: Beträge mit genau zwei Stellen, sonst ohne angehängte Nullen. */
    private static function dezimal(string $w, bool $fest): string
    {
        $minus = str_starts_with($w, '-');
        [$g, $n] = array_pad(explode('.', ltrim($w, '-'), 2), 2, '');
        $n = $fest ? str_pad($n, 2, '0') : rtrim($n, '0');
        $g = ltrim($g, '0') === '' ? '0' : ltrim($g, '0');
        $zahl = $n === '' ? $g : "$g.$n";
        return ($minus && trim($zahl, '0.') !== '' ? '-' : '') . $zahl;
    }

    /**
     * Prüft Positionen, wie sie eine Quelle beim Arbeitsvorrat einliefert: streng, mit Fehlern je Position.
     *
     * @return array{0: list<array<string, string>>, 1: list<array{feld: string, meldung: string}>}
     */
    public static function pruefen(mixed $roh): array
    {
        if (!is_array($roh) || !array_is_list($roh) || count($roh) > self::HOECHSTENS) {
            return [[], [['feld' => 'positionen', 'meldung' => 'Liste mit höchstens ' . self::HOECHSTENS . ' Positionen erwartet']]];
        }
        $erlaubt = [...array_keys(self::TEXT), ...array_keys(self::ZAHL), 'gtin', 'verfall'];
        $erg = [];
        $f = [];
        foreach ($roh as $n => $p) {
            $z = is_array($p) && array_diff(array_keys($p), $erlaubt) === [] ? self::eine($p) : null;
            // streng: was geliefert wird, muss auch lesbar sein – sonst fehlte es stillschweigend
            if ($z === null || count($z) !== count($p)) {
                $f[] = ['feld' => "positionen[$n]", 'meldung' => 'Position erwartet: bezeichnung, dazu nr, menge, einheit, einzelpreis, netto, satz, artikelnummer, kaeufernummer, gtin, charge, verfall als Strings'];
                continue;
            }
            $erg[] = $z;
        }
        return [$erg, $f];
    }

    /** Summe der Positionsbeträge in Cent; null, wenn eine Position keinen Betrag trägt. @param list<array<string, string>> $positionen */
    public static function summeCent(array $positionen): ?int
    {
        $summe = 0;
        foreach ($positionen as $p) {
            if (!isset($p['netto']) || !preg_match('/^(-?)(\d+)\.(\d{2})$/', $p['netto'], $m)) {
                return null;
            }
            $summe += ($m[1] === '-' ? -1 : 1) * ((int) $m[2] * 100 + (int) $m[3]);
        }
        return $positionen === [] ? null : $summe;
    }

    /**
     * Hinweis, wenn die Positionen nicht den Nettobetrag des Belegs ergeben (Toleranz 1 Cent je Position, WW9):
     * dann ist unvollständig gelesen, oder der Beleg hat Zu- und Abschläge außerhalb der Positionen.
     *
     * @param list<array<string, string>> $positionen
     * @param string|null $netto Nettobetrag des Belegs als Dezimal-String
     */
    public static function summenhinweis(array $positionen, ?string $netto): ?string
    {
        $summe = self::summeCent($positionen);
        if ($summe === null) {
            return $positionen === [] ? null : 'Positionen: nicht jede Position trägt einen Betrag – die Summe ist nicht geprüft';
        }
        if ($netto === null || !preg_match('/^(-?)(\d+)\.(\d{2})$/', $netto, $m)) {
            return null;
        }
        $soll = ($m[1] === '-' ? -1 : 1) * ((int) $m[2] * 100 + (int) $m[3]);
        if (abs($summe - $soll) <= count($positionen)) {
            return null;
        }
        $anzeige = fn (int $c) => ($c < 0 ? '-' : '') . number_format(abs($c) / 100, 2, ',', '.');
        return sprintf('Positionen ergeben %s, der Beleg nennt netto %s – unvollständig gelesen oder Zu-/Abschläge außerhalb der Positionen', $anzeige($summe), $anzeige($soll));
    }

    /**
     * Kehrt das Vorzeichen der Positionsbeträge um (Beleg mit negativer Summe: der Eintrag trägt Beträge ohne
     * Vorzeichen). Die Menge bleibt, wie sie gedruckt ist – eine negative Menge ist eine Rückgabe, kein Zugang.
     *
     * @param list<array<string, string>> $positionen
     * @return list<array<string, string>>
     */
    public static function umkehren(array $positionen): array
    {
        return array_map(function (array $p): array {
            if (isset($p['netto']) && trim($p['netto'], '-0.') !== '') {
                $p['netto'] = str_starts_with($p['netto'], '-') ? substr($p['netto'], 1) : '-' . $p['netto'];
            }
            return $p;
        }, $positionen);
    }
}
