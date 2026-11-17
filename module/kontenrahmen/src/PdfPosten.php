<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Kontenrahmen;

/**
 * Leser für DATEV-Kontenrahmen mit Posten-Spalte (SKR 03/04 ab BilRUG, F81 B1):
 * je Seitenhälfte drei Spalten „Bilanz-/GuV-Posten | Programmverbindung/Abschlusszweck | Konten“.
 *
 * Grundlage sind Wortpositionen (`pdftotext -bbox-layout`) und die Linien der Posten-Kästen
 * (`pdftocairo -svg`), beides aus poppler-utils. Ein Konto gehört zu dem Posten, in dessen Kasten
 * seine Zeile liegt. Die Kontozeilen selbst liest PdfLeser::spalteLesen wie beim einfachen Layout.
 */
final class PdfPosten
{
    private const KOPF_POSTEN = ['Bilanz-', 'GuV-', 'Bilanz-/GuV-'];
    private const FUSS_AB = 762.0;

    /** Hat das PDF das Layout mit Posten-Spalte? (Kopfzeile „Programm-“ + „Posten“) */
    public static function erkennen(string $layoutText): bool
    {
        return preg_match('/Programm-/u', $layoutText) === 1 && preg_match('/Posten\s*\d?\)?/u', $layoutText) === 1
            && preg_match('/\b(Bilanz-|GuV-)/u', $layoutText) === 1;
    }

    /**
     * @return array{fassung: ?string, rahmen: ?string, konten: list<array<string, mixed>>, bereiche: list<array<string, mixed>>,
     *               bereichsfunktionen: list<array<string, string>>, hinweise: list<array<string, mixed>>}
     */
    public static function lesen(string $datei, string $layoutText): array
    {
        $erg = ['fassung' => null, 'rahmen' => null, 'konten' => [], 'bereiche' => [], 'bereichsfunktionen' => [], 'hinweise' => []];
        if (preg_match('/Gültig für (\d{4})/u', $layoutText, $m)) {
            $erg['fassung'] = $m[1];
        }
        if (preg_match('/\bSKR\s?(\d{2})\b/u', $layoutText, $m)) {
            $erg['rahmen'] = 'SKR' . $m[1];
        }
        $woerter = self::woerter($datei);
        $flaechen = self::flaechen($datei);
        $weiter = null;   // Posten, der über das Ende einer Spalte hinausläuft
        foreach ($woerter as $i => $w) {
            $haelften = self::spalten($w, $flaechen[$i] ?? []);
            if ($haelften === null) {
                continue;
            }
            foreach ($haelften as $h) {
                $weiter = self::haelfteLesen($w, $flaechen[$i] ?? [], $h, $i + 1, $erg, $weiter);
            }
        }
        return $erg;
    }

    /** @return list<list<array{0: float, 1: float, 2: float, 3: float, 4: string}>> Wörter je Seite (xMin, yMin, xMax, yMax, Text) */
    private static function woerter(string $datei): array
    {
        $html = self::ausfuehren(['pdftotext', '-bbox-layout', $datei, '-']);
        $seiten = [];
        foreach (preg_split('/<page /', $html) ?: [] as $n => $teil) {
            if ($n === 0) {
                continue;
            }
            preg_match_all('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">([^<]*)<\/word>/u', $teil, $m, PREG_SET_ORDER);
            $seiten[] = array_map(fn ($x) => [(float) $x[1], (float) $x[2], (float) $x[3], (float) $x[4],
                html_entity_decode($x[5], ENT_QUOTES | ENT_HTML5, 'UTF-8')], $m);
        }
        return $seiten;
    }

    /** @return list<list<array{0: float, 1: float, 2: float, 3: float}>> gefüllte Rechtecke (Linien) je Seite */
    private static function flaechen(string $datei): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kumero-svg');
        try {
            self::ausfuehren(['pdftocairo', '-svg', $datei, $tmp]);
            $svg = (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
        $seiten = [];
        foreach (explode('<page>', $svg) as $n => $teil) {
            if ($n === 0) {
                continue;
            }
            preg_match_all('/<path ([^>]*?)\/>/', $teil, $m);
            $r = [];
            foreach ($m[1] as $a) {
                if (str_contains($a, 'stroke=') || str_contains($a, 'transform=')) {
                    continue;
                }
                if (preg_match('/d="M ([\d.]+) ([\d.]+) L ([\d.]+) ([\d.]+) L ([\d.]+) ([\d.]+) L ([\d.]+) ([\d.]+) Z/', $a, $d)) {
                    $xs = [(float) $d[1], (float) $d[3], (float) $d[5], (float) $d[7]];
                    $ys = [(float) $d[2], (float) $d[4], (float) $d[6], (float) $d[8]];
                    $r[] = [min($xs), min($ys), max($xs), max($ys)];
                }
            }
            $seiten[] = $r;
        }
        return $seiten;
    }

    /**
     * Spaltengrenzen beider Seitenhälften aus der Kopfzeile.
     *
     * @return ?list<array{posten: array{0: float, 1: float}, pv: array{0: float, 1: float}, konten: array{0: float, 1: float}, oben: float, unten: float}>
     */
    private static function spalten(array $w, array $flaechen): ?array
    {
        $programm = array_values(array_filter($w, fn ($x) => $x[4] === 'Programm-'));
        $postenKopf = array_values(array_filter($w, fn ($x) => in_array($x[4], self::KOPF_POSTEN, true)));
        if (count($programm) < 1 || count($postenKopf) < 1) {
            return null;
        }
        $kopfOben = min(array_map(fn ($x) => $x[1], $programm)) - 4;
        $kopfUnten = max(array_map(fn ($x) => $x[3], array_filter($w, fn ($x) => $x[1] < $kopfOben + 40
            && preg_match('/^(zweck|Abschluss-|\d\))$/u', $x[4])))) + 2;
        usort($postenKopf, fn ($a, $b) => $a[0] <=> $b[0]);
        usort($programm, fn ($a, $b) => $a[0] <=> $b[0]);
        // senkrechte Trennlinien im Kopf
        $trenner = [];
        foreach ($flaechen as [$x0, $y0, $x1, $y1]) {
            if ($x1 - $x0 < 2.5 && $y1 - $y0 > 8 && $y0 < $kopfUnten && $y1 > $kopfOben + 4) {
                $trenner[] = round(($x0 + $x1) / 2, 1);
            }
        }
        $trenner = array_values(array_unique($trenner));
        sort($trenner);
        // Doppellinien (Kastenrand + Spaltenlinie) zusammenfassen: die rechte zählt
        $gefasst = [];
        foreach ($trenner as $t) {
            if ($gefasst !== [] && $t - end($gefasst) < 6) {
                $gefasst[array_key_last($gefasst)] = $t;
            } else {
                $gefasst[] = $t;
            }
        }
        $trenner = $gefasst;
        $breite = 612.0;
        $haelften = [];
        $kopfe = array_values(array_filter($postenKopf, fn ($x) => $x[1] < $kopfOben + 20));
        foreach ($kopfe as $j => $k) {
            $start = $k[0] - 12;
            $ende = isset($kopfe[$j + 1]) ? $kopfe[$j + 1][0] - 12 : $breite;
            $prog = array_values(array_filter($programm, fn ($x) => $x[0] > $start && $x[0] < $ende));
            if ($prog === []) {
                continue;
            }
            $innen = array_values(array_filter($trenner, fn ($t) => $t > $k[0] && $t < $ende - 5));
            $postenEnde = $innen[0] ?? $prog[0][0] - 2;
            $pvEnde = $innen[1] ?? $prog[0][0] + 36;
            $haelften[] = ['posten' => [$start, $postenEnde], 'pv' => [$postenEnde, $pvEnde], 'konten' => [$pvEnde, $ende],
                           'oben' => $kopfUnten, 'unten' => self::FUSS_AB];
        }
        return $haelften === [] ? null : $haelften;
    }

    private static function haelfteLesen(array $w, array $flaechen, array $h, int $seite, array &$erg, ?string $weiter): ?string
    {
        $in = fn (array $x, array $sp) => ($x[0] + $x[2]) / 2 >= $sp[0] && ($x[0] + $x[2]) / 2 < $sp[1] && $x[1] > $h['oben'] && $x[1] < $h['unten'];
        $postenWoerter = array_values(array_filter($w, fn ($x) => $in($x, $h['posten'])));
        $pvWoerter = array_values(array_filter($w, fn ($x) => $in($x, $h['pv'])));
        $kontenWoerter = array_values(array_filter($w, fn ($x) => $in($x, $h['konten'])));

        // Waagerechte Kanten der Posten-Kästen
        [$p0, $p1] = $h['posten'];
        $kanten = [];
        foreach ($flaechen as [$x0, $y0, $x1, $y1]) {
            if ($y1 - $y0 < 2.5 && $x1 - $x0 > ($p1 - $p0) * 0.4 && $x0 >= $p0 - 4 && $x1 <= $p1 + 4 && $y0 > $h['oben'] - 2) {
                $kanten[] = ($y0 + $y1) / 2;
            }
        }
        sort($kanten);
        $grenzen = [$h['oben']];
        foreach ($kanten as $k) {
            if ($k - end($grenzen) > 1.5) {
                $grenzen[] = $k;
            }
        }
        $grenzen[] = $h['unten'];
        // Abschnitte zwischen Kanten; Abschnitte mit Text sind Kästen. Liegen in einem Abschnitt mehrere
        // Textblöcke (Kästen ohne eigene Kante), gilt jeder Block ab seiner ersten Zeile.
        $kaesten = [];
        for ($j = 0; $j < count($grenzen) - 1; $j++) {
            [$a, $b] = [$grenzen[$j], $grenzen[$j + 1]];
            $drin = array_values(array_filter($postenWoerter, fn ($x) => ($x[1] + $x[3]) / 2 > $a && ($x[1] + $x[3]) / 2 < $b));
            $bloecke = self::bloecke($drin);
            if ($bloecke === []) {
                $kaesten[] = ['von' => $a, 'bis' => $b, 'text' => ''];
                continue;
            }
            foreach ($bloecke as $n => $bl) {
                $von = $n === 0 ? $a : $bl['oben'] - 4;
                $bis = isset($bloecke[$n + 1]) ? $bloecke[$n + 1]['oben'] - 4 : $b;
                $kaesten[] = ['von' => $von, 'bis' => $bis, 'text' => self::text($bl['w'])];
            }
        }
        // Ein Abschnitt ganz oben ohne Text setzt den Kasten der vorigen Spalte fort
        if ($kaesten !== [] && $kaesten[0]['text'] === '' && $weiter !== null && ($kaesten[0]['bis'] - $kaesten[0]['von']) > 6) {
            $kaesten[0]['text'] = $weiter;
        }
        $postenBei = function (float $y) use ($kaesten): ?string {
            foreach ($kaesten as $k) {
                if ($y > $k['von'] && $y < $k['bis']) {
                    return $k['text'] === '' ? null : $k['text'];
                }
            }
            return null;
        };

        // Zeilen der Kontenspalte
        $zeilen = self::zeilen($kontenWoerter, $h['konten'][0]);
        $texte = [];
        $meta = [];
        $vorher = null;
        foreach ($zeilen as $z) {
            $texte[] = $z['text'];
            $pv = self::text(array_values(array_filter($pvWoerter, fn ($x) => abs(($x[1] + $x[3]) / 2 - $z['y']) < 3)));
            // Abstand zur vorigen Zeile größer als eine Zeile: neuer Absatz (z. B. Zwischenüberschrift), keine Fortsetzung
            $meta[] = ['posten' => $postenBei($z['y']), 'programmverbindung' => $pv === '' ? null : $pv,
                       'absatz' => $vorher !== null && $z['y'] - $vorher > 13.5];
            $vorher = $z['y'];
        }
        PdfLeser::spalteLesen($texte, $seite, $erg, $meta);

        $letzter = end($kaesten);
        return $letzter !== false && $letzter['text'] !== '' && $letzter['bis'] >= $h['unten'] - 0.5 ? $letzter['text'] : null;
    }

    /** @return list<array{y: float, text: string}> Zeilen aus Wörtern, Einzug in Zeichen ab Spaltenanfang */
    private static function zeilen(array $woerter, float $links): array
    {
        usort($woerter, fn ($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);
        $gruppen = [];
        foreach ($woerter as $x) {
            $y = ($x[1] + $x[3]) / 2;
            $g = array_key_last($gruppen);
            if ($g !== null && abs($gruppen[$g]['y'] - $y) < 2.5) {
                $gruppen[$g]['w'][] = $x;
            } else {
                $gruppen[] = ['y' => $y, 'w' => [$x]];
            }
        }
        $zeichen = 4.4;   // mittlere Zeichenbreite bei 9 pt
        $erg = [];
        foreach ($gruppen as $g) {
            usort($g['w'], fn ($a, $b) => $a[0] <=> $b[0]);
            $s = '';
            $ende = $links;
            foreach ($g['w'] as $x) {
                $luecke = (int) round(($x[0] - $ende) / $zeichen);
                $s .= str_repeat(' ', $s === '' ? max(0, $luecke) : max(1, $luecke)) . $x[4];
                $ende = $x[2];
            }
            $erg[] = ['y' => $g['y'], 'text' => rtrim($s)];
        }
        return $erg;
    }

    /** @return list<array{oben: float, w: list<array>}> Textblöcke, getrennt durch Lücken von mehr als einer Leerzeile */
    private static function bloecke(array $woerter): array
    {
        usort($woerter, fn ($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);
        $bloecke = [];
        $unten = null;
        foreach ($woerter as $x) {
            if ($unten === null || $x[1] - $unten > 9) {
                $bloecke[] = ['oben' => $x[1], 'w' => []];
            }
            $bloecke[array_key_last($bloecke)]['w'][] = $x;
            $unten = max($unten ?? $x[3], $x[3]);
        }
        return $bloecke;
    }

    /** Text eines Kastens: Zeilen zusammenfügen, Silbentrennung auflösen. */
    private static function text(array $woerter): string
    {
        usort($woerter, fn ($a, $b) => [round($a[1]), $a[0]] <=> [round($b[1]), $b[0]]);
        $s = '';
        foreach ($woerter as $x) {
            $t = $x[4];
            if ($s !== '' && str_ends_with($s, '-') && in_array($t, ['und', 'oder', 'bzw.', 'sowie'], true)) {
                $s .= ' ' . $t;
            } elseif ($s !== '' && str_ends_with($s, '-') && preg_match('/^\p{Ll}/u', $t)) {
                $s = substr($s, 0, -1) . $t;
            } else {
                $s .= ($s === '' ? '' : ' ') . $t;
            }
        }
        $s = (string) preg_replace('/(\p{Ll})-(\p{Ll})/u', '$1$2', $s);   // Trennstrich mitten im Wort
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    private static function ausfuehren(array $befehl): string
    {
        $p = proc_open($befehl, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre);
        if (!is_resource($p)) {
            throw new \RuntimeException($befehl[0] . ' nicht verfügbar (Debian-Paket poppler-utils)');
        }
        $aus = (string) stream_get_contents($rohre[1]);
        $fehler = (string) stream_get_contents($rohre[2]);
        if (proc_close($p) !== 0) {
            throw new \RuntimeException($befehl[0] . ' fehlgeschlagen: ' . trim($fehler));
        }
        return $aus;
    }
}
