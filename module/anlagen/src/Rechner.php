<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Anlagen;

/**
 * Rechnet die Absetzung für Abnutzung eines Anlageguts (ANLAGEN.md Abschnitt 4 und 5). Reine Rechnung in Cent, ohne
 * Datenbank: Was bis zu einem Monat insgesamt abgesetzt ist, folgt aus den Angaben des Guts – die AfA eines Zeitraums
 * ist die Differenz zweier solcher Stände. So entstehen keine Rundungsreste, und ein Lauf ist beliebig wiederholbar.
 *
 * Ein Gut: kosten (Dezimal-String), zugang (JJJJ-MM-TT), art (linear|gwg|sammelposten|keine), nutzungsdauer (Jahre,
 * bei linear), restwert (null|erinnerung), abgang (Datum oder null), uebernahme (Datum oder null) mit bisherige_afa.
 */
final class Rechner
{
    public const ERINNERUNG = 100;   // 1,00 € in Cent

    public static function cent(string $dezimal): int
    {
        return (int) round(((float) $dezimal) * 100);
    }

    public static function dezimal(int $cent): string
    {
        return number_format($cent / 100, 2, '.', '');
    }

    /** Laufende Nummer eines Monats (Jahr × 12 + Monat) – damit lässt sich mit Monaten rechnen. */
    private static function monat(string $datum): int
    {
        return (int) substr($datum, 0, 4) * 12 + (int) substr($datum, 5, 2);
    }

    /** Bis zum Ende des Monats $bis insgesamt abgesetzt, in Cent – ohne den Rest beim Abgang. */
    public static function abgesetztBis(array $g, string $bis, ?string $jahresbeginn = null): int
    {
        $kosten = self::cent((string) $g['kosten']);
        $zugang = self::monat((string) $g['zugang']);
        $ende = self::monat($bis);
        $art = (string) $g['art'];
        $bisher = self::cent((string) ($g['bisherige_afa'] ?? '0'));
        $uebernahme = empty($g['uebernahme']) ? null : self::monat((string) $g['uebernahme']);
        if ($ende < $zugang || $art === 'keine') {
            return $uebernahme !== null && $ende >= $uebernahme ? $bisher : 0;
        }
        if ($art === 'gwg') {
            return $kosten;
        }
        if ($art === 'sammelposten') {
            // fünf Wirtschaftsjahre je ein Fünftel, unabhängig vom Monat des Zugangs und von einem Abgang
            $start = $jahresbeginn === null ? 1 : (int) substr($jahresbeginn, 5, 2);
            $jahr = fn (int $m) => intdiv($m - $start, 12);
            $jahre = min(5, $jahr($ende) - $jahr($zugang) + 1);
            return $jahre >= 5 ? $kosten : intdiv($kosten * $jahre, 5);
        }
        // linear: der Monat des Zugangs zählt voll, der Monat des Abgangs zählt mit (G8)
        $abgang = empty($g['abgang']) ? null : self::monat((string) $g['abgang']);
        if ($abgang !== null) {
            $ende = min($ende, $abgang);
        }
        $basis = max(0, $kosten - (($g['restwert'] ?? 'null') === 'erinnerung' ? self::ERINNERUNG : 0));
        $dauer = max(1, (int) $g['nutzungsdauer']) * 12;
        if ($uebernahme !== null) {
            // Bestand von vor kumero: bis zum Stichtag gilt, was bisher abgesetzt wurde; der Rest verteilt sich auf die Restdauer
            if ($ende < $uebernahme) {
                return min($bisher, $basis);
            }
            $rest = max(1, $dauer - ($uebernahme - $zugang));
            $monate = min($rest, $ende - $uebernahme + 1);
            return min($basis, $bisher + (int) round(max(0, $basis - $bisher) * $monate / $rest));
        }
        $monate = min($dauer, $ende - $zugang + 1);
        return (int) round($basis * $monate / $dauer);
    }

    /**
     * Ein Geschäftsjahr eines Guts.
     *
     * @return array{buchwert_anfang: int, afa: int, abgang_rest: int, buchwert_ende: int, zugang_im_jahr: bool, abgang_im_jahr: bool}
     */
    public static function jahr(array $g, string $beginn, string $ende): array
    {
        $kosten = self::cent((string) $g['kosten']);
        $vortag = (new \DateTimeImmutable($beginn))->modify('-1 day')->format('Y-m-d');
        $zugangImJahr = $g['zugang'] >= $beginn && $g['zugang'] <= $ende;
        $vorhanden = $g['zugang'] <= $ende;
        $vorher = $g['zugang'] < $beginn ? self::abgesetztBis($g, $vortag, $beginn) : 0;
        $nachher = $vorhanden ? self::abgesetztBis($g, $ende, $beginn) : 0;
        $abgang = empty($g['abgang']) ? null : (string) $g['abgang'];
        $weg = $abgang !== null && $abgang < $beginn;
        $abgangImJahr = $abgang !== null && $abgang >= $beginn && $abgang <= $ende;
        // Sammelposten: ein Abgang ändert den Topf nicht (G14)
        $rest = $abgangImJahr && $g['art'] !== 'sammelposten' ? $kosten - $nachher : 0;
        if ($weg && $g['art'] !== 'sammelposten') {
            return ['buchwert_anfang' => 0, 'afa' => 0, 'abgang_rest' => 0, 'buchwert_ende' => 0, 'zugang_im_jahr' => false, 'abgang_im_jahr' => false];
        }
        return ['buchwert_anfang' => $g['zugang'] < $beginn ? $kosten - $vorher : 0, 'afa' => $nachher - $vorher, 'abgang_rest' => $rest,
                'buchwert_ende' => $vorhanden ? $kosten - $nachher - $rest : 0, 'zugang_im_jahr' => $zugangImJahr, 'abgang_im_jahr' => $abgangImJahr];
    }

    /**
     * Plan über alle Jahre, vom Jahr des Zugangs bis der Buchwert steht (höchstens 60 Jahre) – für die Vorschau.
     *
     * @return list<array{beginn: string, ende: string, buchwert_anfang: string, afa: string, abgang_rest: string, buchwert_ende: string}>
     */
    public static function plan(array $g, string $jahresbeginn): array
    {
        $start = new \DateTimeImmutable(substr((string) $g['zugang'], 0, 4) . substr($jahresbeginn, 4));
        if ($start->format('Y-m-d') > $g['zugang']) {
            $start = $start->modify('-1 year');
        }
        $plan = [];
        for ($i = 0; $i < 60; $i++) {
            $beginn = $start->modify("+$i year");
            $ende = $beginn->modify('+1 year -1 day');
            $j = self::jahr($g, $beginn->format('Y-m-d'), $ende->format('Y-m-d'));
            $plan[] = ['beginn' => $beginn->format('Y-m-d'), 'ende' => $ende->format('Y-m-d'), 'buchwert_anfang' => self::dezimal($j['buchwert_anfang']),
                       'afa' => self::dezimal($j['afa']), 'abgang_rest' => self::dezimal($j['abgang_rest']), 'buchwert_ende' => self::dezimal($j['buchwert_ende'])];
            $fertig = $j['afa'] === 0 && $j['abgang_rest'] === 0 && $i > 0;
            if ($fertig || $j['abgang_im_jahr'] || $g['art'] === 'keine') {
                if ($fertig) {
                    array_pop($plan);
                }
                break;
            }
        }
        return $plan;
    }

    /**
     * Welche Arten nach dem Nettobetrag in Frage kommen (G5) – ein Vorschlag, keine Entscheidung.
     *
     * @param array<string, mixed> $grenzen ein Eintrag aus dem Regelwerk
     * @return array{arten: list<string>, vorschlag: string, begruendung: string}
     */
    public static function vorschlag(string $netto, array $grenzen): array
    {
        $n = self::cent($netto);
        $gwg = self::cent((string) $grenzen['gwg_bis']);
        $sab = self::cent((string) $grenzen['sammelposten_ab']);
        $sbis = self::cent((string) $grenzen['sammelposten_bis']);
        $auf = self::cent((string) $grenzen['aufzeichnung_ab']);
        $f = fn (string $w) => number_format((float) $w, 2, ',', '.') . ' €';
        if ($n <= $gwg) {
            $arten = $n > $sab ? ['gwg', 'sammelposten', 'linear'] : ['gwg', 'linear'];
            return ['arten' => $arten, 'vorschlag' => 'gwg', 'begruendung' => 'Netto bis ' . $f((string) $grenzen['gwg_bis']) . ': Sofortabzug als geringwertiges Wirtschaftsgut möglich'
                . ($n > $sab ? ', wahlweise Sammelposten oder Verteilung über die Nutzungsdauer' : '') . ($n > $auf ? '' : ' – bis ' . $f((string) $grenzen['aufzeichnung_ab']) . ' ohne Pflicht zum Verzeichnis')];
        }
        if ($n <= $sbis) {
            return ['arten' => ['linear', 'sammelposten'], 'vorschlag' => 'linear', 'begruendung' => 'Netto über ' . $f((string) $grenzen['gwg_bis']) . ' bis ' . $f((string) $grenzen['sammelposten_bis'])
                . ': Verteilung über die Nutzungsdauer oder Sammelposten; kein Sofortabzug'];
        }
        return ['arten' => ['linear'], 'vorschlag' => 'linear', 'begruendung' => 'Netto über ' . $f((string) $grenzen['sammelposten_bis']) . ': Verteilung über die Nutzungsdauer'];
    }
}
