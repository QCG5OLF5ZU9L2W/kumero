<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Kontenrahmen;

/**
 * Liest einen gedruckten DATEV-Kontenrahmen aus einem PDF (KONTENRAHMEN.md 2a).
 *
 * Text über `pdftotext -layout`. Erkannt werden zwei Spalten je Seite (oder eine),
 * Kontenklassen, Untergruppen, Kontozeilen mit Funktionskennzeichen, umbrochene
 * Beschriftungen, Kontenbereiche („0810“ + „-69“), Fußnoten, Bereichs-Zusatzfunktionen
 * und Personenkonten (übergangen). Was wie ein Konto aussieht, aber nicht sicher gelesen
 * wurde, kommt als Hinweis in den Prüfbericht – nie still verworfen.
 */
final class PdfLeser
{
    private const KENNZEICHEN = 'AV|AM|S|F|R|U';

    /** @return array{text: string, sha256: string} */
    public static function textAus(string $datei): array
    {
        if (!is_file($datei) || !is_readable($datei)) {
            throw new \RuntimeException("Datei nicht lesbar: $datei");
        }
        if (file_get_contents($datei, false, null, 0, 5) !== '%PDF-') {
            throw new \RuntimeException('Keine PDF-Datei');
        }
        $p = proc_open(['pdftotext', '-layout', '-enc', 'UTF-8', $datei, '-'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre);
        if (!is_resource($p)) {
            throw new \RuntimeException('pdftotext nicht verfügbar (Debian-Paket poppler-utils)');
        }
        $text = stream_get_contents($rohre[1]);
        $fehler = stream_get_contents($rohre[2]);
        if (proc_close($p) !== 0) {
            throw new \RuntimeException('pdftotext fehlgeschlagen: ' . trim((string) $fehler));
        }
        if (trim((string) $text) === '') {
            throw new \RuntimeException('PDF ohne Textebene (Scan?) – Texterkennung wird nicht unterstützt');
        }
        return ['text' => (string) $text, 'sha256' => hash_file('sha256', $datei)];
    }

    /**
     * @return array{fassung: ?string, konten: list<array<string, mixed>>, bereiche: list<array<string, mixed>>,
     *               bereichsfunktionen: list<array<string, string>>, hinweise: list<array<string, mixed>>}
     */
    public static function lesen(string $text): array
    {
        $erg = ['fassung' => null, 'rahmen' => null, 'konten' => [], 'bereiche' => [], 'bereichsfunktionen' => [], 'hinweise' => []];
        foreach (explode("\f", $text) as $i => $seite) {
            $zeilen = [];
            foreach (preg_split('/\R/u', $seite) as $z) {
                if (preg_match('/Gültig für (\d{4})/u', $z, $m)) {
                    $erg['fassung'] ??= $m[1];
                }
                // Rahmen aus Kopfzeile oder Titel, z. B. „SKR 03“ oder „SKR 81“
                if ($erg['rahmen'] === null && preg_match('/\bSKR\s?(\d{2})\b/u', $z, $m)) {
                    $erg['rahmen'] = 'SKR' . $m[1];
                }
                // Kopf- und Fußzeilen
                if (preg_match('/Gültig für \d{4}|Art\.-Nr\.|Seite \d+\s*$|Standardkontenrahmen \(SKR\)|Kontenrahmen \(|Kontenrahmen für/u', $z)) {
                    continue;
                }
                $zeilen[] = rtrim($z);
            }
            $grenze = self::spaltengrenze($zeilen);
            $links = $rechts = [];
            foreach ($zeilen as $z) {
                if ($grenze === null) {
                    $links[] = $z;
                    continue;
                }
                $links[] = rtrim(mb_substr($z, 0, $grenze));
                $rechts[] = mb_substr($z, $grenze);
            }
            foreach ([$links, $rechts] as $spalte) {
                self::spalteLesen($spalte, $i + 1, $erg);
            }
        }
        return $erg;
    }

    /** Linker Rand der rechten Spalte: frühester Spaltenanfang nach mindestens drei Leerzeichen. */
    private static function spaltengrenze(array $zeilen): ?int
    {
        $kandidaten = [];
        foreach ($zeilen as $z) {
            if (preg_match_all('/(?<=   )(?:(?:' . self::KENNZEICHEN . ') +\d{4}\b|\d{4}\b(?!\.)|\d [A-ZÄÖÜ][a-zäöüß])/u', $z, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$treffer, $byte]) {
                    $pos = mb_strlen(substr($z, 0, $byte));
                    if ($pos >= 20) {
                        $kandidaten[] = $pos;
                    }
                }
            }
        }
        if (count($kandidaten) < 2) {
            return null;
        }
        // Grenze nicht mitten in ein Wort legen: auf das Ende der Leerstelle davor zurückgehen
        return min($kandidaten);
    }

    private static function spalteLesen(array $zeilen, int $seite, array &$erg): void
    {
        $letztes = null;          // Index des letzten Kontos in $erg['konten'] bzw. Bereich
        $letztesArt = null;       // 'konto' | 'bereich'
        $beschriftungAb = 0;
        $klasse = null;
        $gruppe = null;
        $personen = false;
        foreach ($zeilen as $z) {
            $t = trim($z);
            if ($t === '') {
                continue;
            }
            $einzug = mb_strlen($z) - mb_strlen(ltrim($z));

            if (preg_match('/^\d{5}(\s*-\s*\d{5})?\b/u', $t)) {            // Personenkonten
                $personen = true;
                $letztes = null;
                continue;
            }
            if (preg_match('/^(\d) ([A-ZÄÖÜ].*)$/u', $t, $m)) {            // Kontenklasse
                $klasse = $m[1];
                $gruppe = null;
                $letztes = null;
                $personen = false;
                continue;
            }
            if (preg_match('/^(KU|V|M) (\d{4}) ?- ?(\d{4})$/u', $t, $m)) {  // Zusatzfunktion für einen Bereich
                $erg['bereichsfunktionen'][] = ['kennzeichen' => $m[1], 'von' => $m[2], 'bis' => $m[3]];
                $letztes = null;
                continue;
            }
            if (preg_match('/^- ?(\d{2})(?:\s+(.*))?$/u', $t, $m) && $letztes !== null) {   // Endung eines Bereichs
                self::zuBereich($erg, $letztes, $letztesArt, $m[1]);
                $letztesArt = 'bereich';
                $letztes = array_key_last($erg['bereiche']);
                if (($m[2] ?? '') !== '') {
                    self::anhaengen($erg, $letztesArt, $letztes, $m[2]);
                }
                continue;
            }
            if (preg_match('/^(?:(' . self::KENNZEICHEN . ') +)?(\d{4})(?: ?- ?(\d{2}))?(?: +(\S.*))?$/u', $t, $m)) {
                [$kz, $nr, $endung, $text] = [$m[1] ?? '', $m[2], $m[3] ?? '', $m[4] ?? ''];
                [$text, $fussnote] = self::fussnote($text);
                $eintrag = array_filter(['nr' => $nr, 'bezeichnung' => $text, 'kennzeichen' => $kz ?: null,
                    'fussnote' => $fussnote, 'klasse' => $klasse, 'gruppe' => $gruppe, 'seite' => $seite],
                    fn ($w) => $w !== null && $w !== '');
                $eintrag['bezeichnung'] = $text;
                $erg['konten'][] = $eintrag;
                $letztes = array_key_last($erg['konten']);
                $letztesArt = 'konto';
                $beschriftungAb = $einzug + ($text === '' ? mb_strlen($t) + 1 : mb_strpos($t, $text));
                $personen = false;
                if ($endung !== '') {
                    self::zuBereich($erg, $letztes, $letztesArt, $endung);
                    $letztesArt = 'bereich';
                    $letztes = array_key_last($erg['bereiche']);
                }
                continue;
            }
            if ($personen) {
                continue;                                                    // Beschriftung zu Personenkonten
            }
            if ($letztes !== null && $einzug >= $beschriftungAb - 2) {      // umbrochene Beschriftung
                self::anhaengen($erg, $letztesArt, $letztes, $t);
                continue;
            }
            if (preg_match('/\b\d{4}\b/u', $t)) {                          // sieht nach Konto aus, nicht sicher
                $erg['hinweise'][] = ['art' => 'unsicher', 'seite' => $seite, 'text' => $t];
                $letztes = null;
                continue;
            }
            $gruppe = $t;                                                    // Untergruppe
            $letztes = null;
        }
    }

    private static function anhaengen(array &$erg, ?string $art, int $i, string $text): void
    {
        $liste = $art === 'bereich' ? 'bereiche' : 'konten';
        [$text, $fussnote] = self::fussnote($text);
        $alt = $erg[$liste][$i]['bezeichnung'];
        $erg[$liste][$i]['bezeichnung'] = $alt === '' ? $text
            : (str_ends_with($alt, '-') && preg_match('/^\p{Ll}/u', $text) ? substr($alt, 0, -1) . $text : "$alt $text");
        if ($fussnote !== null) {
            $erg[$liste][$i]['fussnote'] = $fussnote;
        }
    }

    /** Macht aus dem letzten Konto einen Bereich (z. B. 0810 bis 0869). */
    private static function zuBereich(array &$erg, int $i, ?string $art, string $endung): void
    {
        if ($art === 'bereich') {
            return;
        }
        $k = $erg['konten'][$i];
        array_splice($erg['konten'], $i, 1);
        $k['von'] = $k['nr'];
        $k['bis'] = substr($k['nr'], 0, 2) . $endung;
        unset($k['nr']);
        $erg['bereiche'][] = $k;
    }

    /** @return array{0: string, 1: ?string} */
    private static function fussnote(string $text): array
    {
        if (preg_match('/^(.*?)\s*(\d{1,2})\)$/u', $text, $m)) {
            return [$m[1], $m[2]];
        }
        return [$text, null];
    }
}
