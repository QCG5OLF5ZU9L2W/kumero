<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Import;

/**
 * CSV-Dateien lesen und in Vorschläge für den Arbeitsvorrat verwandeln (IMPORT.md Abschnitt 2–5, 8).
 * Reine Funktionen ohne Datenbank: erkennen (Aufbau vorschlagen), lesen (Tabelle), anwenden (Zeile → Vorschlag).
 * Es wird nie geraten: Was sich nicht bestimmen lässt, ist ein Fehler der Zeile.
 */
final class Csv
{
    public const HOECHSTENS_BYTES = 350 * 1024 * 1024;
    public const HOECHSTENS_ZEILEN = 20000;
    public const ZEICHENSAETZE = ['utf-8', 'windows-1252'];
    public const TRENNER = [';', ',', "\t"];
    public const DATUMSFORMEN = ['TT.MM.JJJJ' => 'd.m.Y', 'TT.MM.JJ' => 'd.m.y', 'JJJJ-MM-TT' => 'Y-m-d', 'MM/TT/JJJJ' => 'm/d/Y'];
    public const RICHTUNGEN = ['vorzeichen', 'vorzeichen_umgekehrt', 'zwei_spalten', 'kennzeichen', 'fest'];
    /** Ziele der Spaltenzuordnung; mehrere Spalten sind nur bei Textzielen erlaubt */
    public const ZIELE = ['datum', 'zahltag', 'betrag', 'einnahme', 'ausgabe', 'kennzeichen', 'gegenpartei', 'gegenpartei_kennung',
        'betreff', 'belegnummer', 'art', 'waehrung', 'saldo', 'anzeigen'];
    private const MEHRFACH = ['betreff', 'gegenpartei', 'anzeigen'];

    /** Text der Datei in UTF-8, ohne BOM, mit einheitlichen Zeilenenden. */
    public static function text(string $roh, string $zeichensatz): string
    {
        if (str_starts_with($roh, "\xEF\xBB\xBF")) {
            $roh = substr($roh, 3);
        }
        if ($zeichensatz !== 'utf-8') {
            $roh = mb_convert_encoding($roh, 'UTF-8', 'Windows-1252');
        }
        return str_replace(["\r\n", "\r"], "\n", $roh);
    }

    /**
     * Schlägt den Aufbau der Datei vor (Abschnitt 2). Der Nutzer bestätigt oder ändert.
     *
     * @return array{zeichensatz: string, trenner: string, kopfzeile: int, fusszeilen: int, datum: string, dezimal: string}
     */
    public static function erkennen(string $roh): array
    {
        $zeichensatz = mb_check_encoding($roh, 'UTF-8') ? 'utf-8' : 'windows-1252';
        $zeilen = array_slice(explode("\n", self::text($roh, $zeichensatz)), 0, 60);
        // Trennzeichen: das, mit dem die meisten Zeilen gleich viele (und mehr als eine) Spalten haben
        $bester = [';', 0, 1];
        foreach (self::TRENNER as $t) {
            $anzahl = array_count_values(array_map(fn ($z) => count(str_getcsv($z, $t, '"', '')), array_filter($zeilen, fn ($z) => trim($z) !== '')));
            unset($anzahl[1]);
            arsort($anzahl);
            $spalten = (int) (array_key_first($anzahl) ?? 1);
            $treffer = (int) (reset($anzahl) ?: 0);
            if ($treffer * min($spalten, 4) > $bester[1] * min($bester[2], 4)) {
                $bester = [$t, $treffer, $spalten];
            }
        }
        [$trenner, , $spalten] = $bester;
        // Kopfzeile: die erste Zeile mit der üblichen Spaltenzahl – davor steht bei Bankexporten Vorspann
        $kopf = 1;
        foreach ($zeilen as $n => $z) {
            if (count(str_getcsv($z, $trenner, '"', '')) === $spalten) {
                $kopf = $n + 1;
                break;
            }
        }
        $daten = implode("\n", array_slice($zeilen, $kopf, 30));
        $datum = 'TT.MM.JJJJ';
        foreach (['TT.MM.JJJJ' => '/\b\d{2}\.\d{2}\.\d{4}\b/', 'JJJJ-MM-TT' => '/\b\d{4}-\d{2}-\d{2}\b/', 'MM/TT/JJJJ' => '#\b\d{1,2}/\d{1,2}/\d{4}\b#', 'TT.MM.JJ' => '/\b\d{2}\.\d{2}\.\d{2}\b/'] as $form => $muster) {
            if (preg_match($muster, $daten)) {
                $datum = $form;
                break;
            }
        }
        // Datumsangaben zählen nicht mit: „01.03.2026“ sähe sonst wie ein Betrag mit Dezimalpunkt aus
        $ohneDatum = (string) preg_replace('#\b\d{1,2}[./]\d{1,2}[./]\d{2,4}\b|\b\d{4}-\d{2}-\d{2}\b#', '', $daten);
        $dezimal = preg_match_all('/\d,\d{1,2}(?!\d)/', $ohneDatum) >= preg_match_all('/\d\.\d{1,2}(?!\d)/', $ohneDatum) ? ',' : '.';
        return ['zeichensatz' => $zeichensatz, 'trenner' => $trenner, 'kopfzeile' => $kopf, 'fusszeilen' => 0, 'datum' => $datum, 'dezimal' => $dezimal];
    }

    /**
     * Liest die Tabelle: Spaltennamen der Kopfzeile und die Datenzeilen mit ihrer Zeilennummer in der Datei.
     *
     * @param array<string, mixed> $e Einstellungen (zeichensatz, trenner, kopfzeile, fusszeilen)
     * @return array{kopf: list<string>, zeilen: list<array{nr: int, felder: array<string, string>}>}
     */
    public static function lesen(string $roh, array $e): array
    {
        $zeilen = [];
        $strom = fopen('php://memory', 'w+') ?: throw new \RuntimeException('Zwischenspeicher fehlt');
        fwrite($strom, self::text($roh, (string) $e['zeichensatz']));
        rewind($strom);
        $nr = 0;
        while (($felder = fgetcsv($strom, null, (string) $e['trenner'], '"', '')) !== false) {
            $nr++;
            $zeilen[] = [$nr, $felder === [null] ? [] : array_map(fn ($x) => trim((string) $x), $felder)];
            if ($nr > self::HOECHSTENS_ZEILEN + 200) {
                break;
            }
        }
        fclose($strom);
        $kopfNr = (int) $e['kopfzeile'];
        $kopf = [];
        foreach ($zeilen as [$n, $f]) {
            if ($n === $kopfNr) {
                $kopf = $f;
            }
        }
        if ($kopf === [] || count(array_filter($kopf, fn ($x) => $x !== '')) < 2) {
            throw new \InvalidArgumentException('In der genannten Kopfzeile stehen keine Spaltennamen');
        }
        // gleichnamige Spalten unterscheidbar machen
        $gesehen = [];
        foreach ($kopf as $i => $name) {
            $name = $name === '' ? 'Spalte ' . ($i + 1) : $name;
            $gesehen[$name] = ($gesehen[$name] ?? 0) + 1;
            $kopf[$i] = $gesehen[$name] > 1 ? "$name ({$gesehen[$name]})" : $name;
        }
        $daten = array_values(array_filter($zeilen, fn ($z) => $z[0] > $kopfNr && array_filter($z[1], fn ($x) => $x !== '') !== []));
        $daten = array_slice($daten, 0, max(0, count($daten) - max(0, (int) ($e['fusszeilen'] ?? 0))));
        $erg = [];
        foreach ($daten as [$n, $f]) {
            $zeile = [];
            foreach ($kopf as $i => $name) {
                $zeile[$name] = $f[$i] ?? '';
            }
            $erg[] = ['nr' => $n, 'felder' => $zeile];
        }
        return ['kopf' => $kopf, 'zeilen' => $erg];
    }

    /**
     * Schlägt die Spaltenzuordnung und die Art der Richtung aus den Spaltennamen vor (Abschnitt 3 und 4).
     *
     * @param list<string> $kopf
     * @return array{spalten: array<string, list<string>>, richtung: array<string, mixed>}
     */
    public static function vorschlag(array $kopf): array
    {
        $muster = [
            'zahltag' => '/^(valuta|wertstellung|wert)/u',
            'datum' => '/^(buchungstag|buchungsdatum|datum|buchung$|date)/u',
            'einnahme' => '/^(einnahme|gutschrift|haben|eingang|zugang)/u',
            'ausgabe' => '/^(ausgabe|belastung|soll|ausgang|abgang)/u',
            'saldo' => '/saldo|kontostand/u',
            'betrag' => '/^(betrag|umsatz|amount)/u',
            'kennzeichen' => '/^(s\/h|soll\/haben|soll-haben|kennzeichen|umsatzart$)/u',
            'gegenpartei_kennung' => '/iban|kontonummer|konto-nr/u',
            'gegenpartei' => '/empf(ä|ae)nger|auftraggeber|beg(ü|ue)nstigter|zahlungspflichtiger|payee|^name/u',
            'betreff' => '/verwendungszweck|zahlungsgrund|zahlungszweck|buchungstext|memo|beschreibung|notiz|vorgang|^text$|^zweck/u',
            'belegnummer' => '/belegnummer|beleg-nr|referenz|schecknummer|^nummer/u',
            'art' => '/kategorie|category|buchungsart|umsatzart|^art$/u',
            'waehrung' => '/^w(ä|ae)hrung|currency/u',
        ];
        $spalten = [];
        foreach ($kopf as $name) {
            $klein = mb_strtolower($name);
            foreach ($muster as $ziel => $m) {
                if (preg_match($m, $klein) && (in_array($ziel, self::MEHRFACH, true) || !isset($spalten[$ziel]))) {
                    $spalten[$ziel][] = $name;
                    break;
                }
            }
        }
        $richtung = match (true) {
            isset($spalten['einnahme'], $spalten['ausgabe']) && !isset($spalten['betrag']) => ['art' => 'zwei_spalten'],
            isset($spalten['kennzeichen'], $spalten['betrag']) => ['art' => 'kennzeichen', 'ausgabe' => ['S', 'Soll', 'Lastschrift', '-'], 'einnahme' => ['H', 'Haben', 'Gutschrift', '+']],
            default => ['art' => 'vorzeichen'],
        };
        if ($richtung['art'] !== 'zwei_spalten') {
            unset($spalten['einnahme'], $spalten['ausgabe']);
        }
        if ($richtung['art'] !== 'kennzeichen') {
            unset($spalten['kennzeichen']);
        }
        return ['spalten' => $spalten, 'richtung' => $richtung];
    }

    /**
     * Prüft die Form der Einstellungen (Importprofil).
     *
     * @return array{0: array<string, mixed>, 1: list<array{feld: string, meldung: string}>}
     */
    public static function pruefen(mixed $e): array
    {
        $f = [];
        if (!is_array($e)) {
            return [[], [['feld' => 'einstellungen', 'meldung' => 'Objekt erwartet']]];
        }
        $erlaubt = ['zeichensatz', 'trenner', 'kopfzeile', 'fusszeilen', 'datum', 'dezimal', 'spalten', 'richtung', 'geldkonto', 'buchungsart', 'zahlweise'];
        foreach (array_diff(array_keys($e), $erlaubt) as $k) {
            $f[] = ['feld' => "einstellungen.$k", 'meldung' => 'Unbekanntes Feld'];
        }
        $wahl = ['zeichensatz' => self::ZEICHENSAETZE, 'trenner' => self::TRENNER, 'datum' => array_keys(self::DATUMSFORMEN), 'dezimal' => [',', '.'], 'zahlweise' => ['bar', 'unbar']];
        foreach ($wahl as $feld => $werte) {
            if (!in_array($e[$feld] ?? null, $werte, true)) {
                $f[] = ['feld' => "einstellungen.$feld", 'meldung' => 'Erlaubt: ' . implode(', ', array_map(fn ($w) => $w === "\t" ? 'Tabulator' : $w, $werte))];
            }
        }
        foreach (['kopfzeile' => [1, 200], 'fusszeilen' => [0, 50]] as $feld => [$min, $max]) {
            if (!is_int($e[$feld] ?? null) || $e[$feld] < $min || $e[$feld] > $max) {
                $f[] = ['feld' => "einstellungen.$feld", 'meldung' => "Ganze Zahl von $min bis $max erwartet"];
            }
        }
        $konto = $e['geldkonto'] ?? null;
        if (!is_string($konto) || trim($konto) === '' || strlen($konto) > 20) {
            $f[] = ['feld' => 'einstellungen.geldkonto', 'meldung' => 'Geldkonto (Bank, Kasse, Kreditkarte) ist Pflicht'];
        }
        $art = $e['buchungsart'] ?? null;
        if ($art !== null && (!is_string($art) || $art === '' || strlen($art) > 16)) {
            $f[] = ['feld' => 'einstellungen.buchungsart', 'meldung' => 'Kürzel einer Buchungsart oder null erwartet'];
        }
        $spalten = $e['spalten'] ?? null;
        if (!is_array($spalten) || ($spalten !== [] && array_is_list($spalten))) {
            $f[] = ['feld' => 'einstellungen.spalten', 'meldung' => 'Objekt {ziel: [spalte, …]} erwartet'];
            $spalten = [];
        }
        foreach ($spalten as $ziel => $namen) {
            $ok = in_array($ziel, self::ZIELE, true) && is_array($namen) && array_is_list($namen) && $namen !== [] && count($namen) <= 30
                && array_filter($namen, fn ($n) => !is_string($n) || $n === '' || mb_strlen($n) > 200) === []
                && (count($namen) === 1 || in_array($ziel, self::MEHRFACH, true));
            if (!$ok) {
                $f[] = ['feld' => "einstellungen.spalten.$ziel", 'meldung' => 'Unbekanntes Ziel oder keine Liste von Spaltennamen (mehrere nur bei Betreff, Gegenpartei, Anzeigen)'];
            }
        }
        $r = $e['richtung'] ?? null;
        $rArt = is_array($r) ? ($r['art'] ?? null) : null;
        if (!in_array($rArt, self::RICHTUNGEN, true)) {
            $f[] = ['feld' => 'einstellungen.richtung.art', 'meldung' => 'Erlaubt: ' . implode(', ', self::RICHTUNGEN)];
        } else {
            $liste = fn ($w) => is_array($w) && array_is_list($w) && $w !== [] && count($w) <= 20 && array_filter($w, fn ($x) => !is_string($x) || $x === '' || mb_strlen($x) > 60) === [];
            $fehlt = match ($rArt) {
                'zwei_spalten' => !isset($spalten['einnahme'], $spalten['ausgabe']) ? 'Spalten für Einnahme und Ausgabe zuordnen' : null,
                'kennzeichen' => !isset($spalten['betrag'], $spalten['kennzeichen']) || !$liste($r['ausgabe'] ?? null) || !$liste($r['einnahme'] ?? null)
                    ? 'Spalten für Betrag und Kennzeichen zuordnen und die Werte für Ausgabe und Einnahme nennen' : null,
                'fest' => !isset($spalten['betrag']) || !in_array($r['fest'] ?? null, ['ausgabe', 'einnahme'], true) ? 'Betragsspalte zuordnen und fest: ausgabe oder einnahme nennen' : null,
                default => !isset($spalten['betrag']) ? 'Betragsspalte zuordnen' : null,
            };
            if ($fehlt !== null) {
                $f[] = ['feld' => 'einstellungen.richtung', 'meldung' => $fehlt];
            }
        }
        if (!isset($spalten['datum'])) {
            $f[] = ['feld' => 'einstellungen.spalten.datum', 'meldung' => 'Die Spalte mit dem Datum ist Pflicht'];
        }
        return [$f === [] ? $e : [], $f];
    }

    /** Betrag in Cent mit Vorzeichen; null, wenn der Text kein Betrag ist. Leerer Text ergibt 0 mit leer = true. */
    public static function betrag(string $text, string $dezimal): ?int
    {
        $t = preg_replace('/[\s\x{00A0}€$£]|EUR|USD|CHF|GBP/u', '', $text) ?? '';
        if ($t === '') {
            return null;
        }
        $minus = false;
        if (preg_match('/^\((.*)\)$/', $t, $m)) {
            $minus = true;
            $t = $m[1];
        }
        if (str_ends_with($t, '-') || str_starts_with($t, '-')) {
            $minus = !$minus;
            $t = trim($t, '-');
        }
        $t = ltrim($t, '+');
        $tausend = $dezimal === ',' ? '.' : ',';
        if (!preg_match('/^\d{1,3}(' . preg_quote($tausend, '/') . '\d{3})+(' . preg_quote($dezimal, '/') . '\d{1,2})?$|^\d+(' . preg_quote($dezimal, '/') . '\d{1,2})?$/', $t)) {
            return null;
        }
        $t = str_replace([$tausend, $dezimal], ['', '.'], $t);
        [$ganz, $bruch] = array_pad(explode('.', $t), 2, '');
        if (strlen($ganz) > 13) {
            return null;
        }
        $cent = (int) $ganz * 100 + (int) str_pad($bruch, 2, '0');
        return $minus ? -$cent : $cent;
    }

    /** Datum als JJJJ-MM-TT; null, wenn der Text kein Datum in der genannten Form ist. */
    public static function datum(string $text, string $form): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!' . self::DATUMSFORMEN[$form], trim($text));
        $fehler = \DateTimeImmutable::getLastErrors();
        if ($d === false || ($fehler !== false && ($fehler['warning_count'] > 0 || $fehler['error_count'] > 0))) {
            return null;
        }
        return $d->format('Y-m-d');
    }

    /**
     * Wendet die Einstellungen auf die Tabelle an: je Zeile ein Vorschlag für den Arbeitsvorrat oder ein Fehler.
     *
     * @param array{kopf: list<string>, zeilen: list<array{nr: int, felder: array<string, string>}>} $tabelle
     * @param array<string, mixed> $e geprüfte Einstellungen
     * @return array{zeilen: list<array<string, mixed>>, salden: ?array{anfang: int, ende: int, stimmt: bool}}
     */
    public static function anwenden(array $tabelle, array $e): array
    {
        $sp = $e['spalten'];
        foreach ($sp as $ziel => $namen) {
            foreach ($namen as $n) {
                if (!in_array($n, $tabelle['kopf'], true)) {
                    throw new \InvalidArgumentException("Die Spalte „{$n}“ gibt es in dieser Datei nicht");
                }
            }
        }
        $erste = fn (array $f, string $ziel) => isset($sp[$ziel]) ? $f[$sp[$ziel][0]] : '';
        $alle = fn (array $f, string $ziel) => isset($sp[$ziel]) ? trim(implode(' ', array_filter(array_map(fn ($n) => $f[$n], $sp[$ziel]), fn ($x) => $x !== ''))) : '';
        $klein = fn (array $l) => array_map('mb_strtolower', $l);
        $r = $e['richtung'];
        $erg = [];
        $zaehler = [];
        $saldoVor = null;
        $saldoEnde = null;
        $summe = 0;
        $saldenOk = isset($sp['saldo']);
        foreach ($tabelle['zeilen'] as $z) {
            $f = $z['felder'];
            $fehler = null;
            $hinweise = [];
            $datum = self::datum($erste($f, 'datum'), $e['datum']);
            if ($datum === null) {
                $fehler = 'Datum „' . mb_substr($erste($f, 'datum'), 0, 20) . '“ passt nicht zur Form ' . $e['datum'];
            }
            $zahltag = null;
            if ($fehler === null && $erste($f, 'zahltag') !== '') {
                $zahltag = self::datum($erste($f, 'zahltag'), $e['datum']);
                if ($zahltag === null) {
                    $fehler = 'Tag des Geldflusses „' . mb_substr($erste($f, 'zahltag'), 0, 20) . '“ passt nicht zur Form ' . $e['datum'];
                }
            }
            // Betrag und Richtung (Abschnitt 4) – nie geraten
            $cent = null;
            $richtung = null;
            if ($fehler === null) {
                if ($r['art'] === 'zwei_spalten') {
                    $ein = $erste($f, 'einnahme') === '' ? 0 : self::betrag($erste($f, 'einnahme'), $e['dezimal']);
                    $aus = $erste($f, 'ausgabe') === '' ? 0 : self::betrag($erste($f, 'ausgabe'), $e['dezimal']);
                    if ($ein === null || $aus === null) {
                        $fehler = 'Betrag nicht lesbar';
                    } elseif (($ein !== 0) === ($aus !== 0)) {
                        $fehler = $ein === 0 ? 'Weder Einnahme noch Ausgabe gefüllt' : 'Einnahme und Ausgabe sind beide gefüllt';
                    } else {
                        [$cent, $richtung] = $ein !== 0 ? [abs($ein), 'einnahme'] : [abs($aus), 'ausgabe'];
                    }
                } else {
                    $b = self::betrag($erste($f, 'betrag'), $e['dezimal']);
                    if ($b === null) {
                        $fehler = 'Betrag „' . mb_substr($erste($f, 'betrag'), 0, 20) . '“ nicht lesbar';
                    } elseif ($b === 0) {
                        $fehler = 'Betrag ist 0,00';
                    } else {
                        $cent = abs($b);
                        $kz = mb_strtolower($erste($f, 'kennzeichen'));
                        $richtung = match ($r['art']) {
                            'vorzeichen' => $b < 0 ? 'ausgabe' : 'einnahme',
                            'vorzeichen_umgekehrt' => $b < 0 ? 'einnahme' : 'ausgabe',
                            'fest' => $r['fest'],
                            default => in_array($kz, $klein($r['ausgabe']), true) ? 'ausgabe' : (in_array($kz, $klein($r['einnahme']), true) ? 'einnahme' : null),
                        };
                        if ($richtung === null) {
                            $fehler = 'Kennzeichen „' . mb_substr($erste($f, 'kennzeichen'), 0, 20) . '“ ist weder Ausgabe noch Einnahme zugeordnet';
                        }
                    }
                }
            }
            // Saldenkontrolle (Abschnitt 7): Saldo nach der Zeile = Saldo davor ± Betrag
            if (isset($sp['saldo'])) {
                $saldo = self::betrag($erste($f, 'saldo'), $e['dezimal']);
                if ($saldo === null || $cent === null) {
                    $saldenOk = false;
                } else {
                    $wirkung = $richtung === 'einnahme' ? $cent : -$cent;
                    $saldoVor ??= $saldo - $wirkung;
                    $summe += $wirkung;
                    $saldoEnde = $saldo;
                }
            }
            $gegenpartei = mb_substr($alle($f, 'gegenpartei'), 0, 200);
            $betreff = mb_substr($alle($f, 'betreff'), 0, 200);
            if ($betreff === '') {
                $betreff = mb_substr(trim(($gegenpartei !== '' ? $gegenpartei : 'Umsatz') . ' ' . ($datum ?? "Zeile {$z['nr']}")), 0, 200);
            }
            $v = ['betreff' => $betreff, 'zahlweise' => $e['zahlweise']];
            if ($datum !== null) {
                $v['datum'] = $datum;
                $v['zahltag'] = $zahltag ?? $datum;
            }
            if ($richtung !== null) {
                // das Geldkonto steht je nach Richtung im Soll oder im Haben (Abschnitt 5)
                $v['richtung'] = $richtung;
                $v['geldkonto'] = trim((string) $e['geldkonto']);
            }
            $waehrung = strtoupper($erste($f, 'waehrung'));
            if ($cent !== null && ($waehrung === '' || $waehrung === 'EUR')) {
                $v['betrag'] = sprintf('%d.%02d', intdiv($cent, 100), $cent % 100);
            } elseif ($cent !== null) {
                // Fremdwährung (C12): kein Betrag, nur der Hinweis
                $hinweise[] = ['feld' => 'betrag', 'text' => sprintf('Betrag in %s: %d.%02d – nur die Buchwährung wird übernommen', mb_substr($waehrung, 0, 3), intdiv($cent, 100), $cent % 100)];
            }
            if ($gegenpartei !== '') {
                $v['gegenpartei'] = $gegenpartei;
            }
            if ($erste($f, 'gegenpartei_kennung') !== '') {
                $v['gegenpartei_kennung'] = mb_substr(preg_replace('/\s+/', '', $erste($f, 'gegenpartei_kennung')) ?? '', 0, 60);
            }
            if ($erste($f, 'belegnummer') !== '') {
                if (mb_strlen($erste($f, 'belegnummer')) <= 36) {
                    $v['belegnummer'] = $erste($f, 'belegnummer');
                } else {
                    $hinweise[] = ['feld' => 'belegnummer', 'text' => 'Belegnummer länger als 36 Zeichen: ' . mb_substr($erste($f, 'belegnummer'), 0, 80)];
                }
            }
            $art = self::stichwort($erste($f, 'art'));
            if ($art !== null) {
                $v['art'] = $art;
            }
            if ($fehler !== null) {
                // Fehlerhafte Zeilen gehen nicht verloren (C10): Sie werden mit dem eingeliefert, was lesbar ist, und markiert
                array_unshift($hinweise, ['text' => mb_substr("Fehler beim Einlesen (Zeile {$z['nr']} der Datei): $fehler – bitte prüfen und ergänzen", 0, 300)]);
            }
            if ($hinweise !== []) {
                $v['hinweise'] = $hinweise;
            }
            // Die ganze Zeile bleibt am Eintrag (C9): Was keinem Feld zugeordnet ist, steht als Angabe neben der Maske.
            // Bei einer fehlerhaften Zeile stehen alle Spalten da, damit nichts verloren geht.
            $zugeordnet = $fehler !== null ? [] : array_merge(...array_values(array_diff_key($sp, ['anzeigen' => 1])));
            $angaben = [];
            foreach ($f as $n => $w) {
                if ($w !== '' && !in_array($n, $zugeordnet, true)) {
                    $angaben[] = ['bezeichnung' => mb_substr((string) $n, 0, 60), 'wert' => mb_substr($w, 0, 1000)];
                }
            }
            // Schlüssel der Zeile (Abschnitt 8): Inhalt statt Zeilennummer, damit überlappende Auszüge nichts doppeln;
            // gleiche Zeilen in einer Datei zählt eine laufende Nummer. Fehlerhafte Zeilen: Hash der ganzen Zeile.
            $inhalt = $fehler !== null ? "fehler\x1F" . implode("\x1F", $f)
                : implode("\x1F", [$v['geldkonto'], $datum, $v['zahltag'], (string) $cent, $richtung, mb_strtolower($gegenpartei), mb_strtolower($betreff), $waehrung]);
            $zaehler[$inhalt] = ($zaehler[$inhalt] ?? 0) + 1;
            $erg[] = ['nr' => $z['nr'], 'schluessel' => 'csv:' . substr(hash('sha256', $inhalt), 0, 40) . '#' . $zaehler[$inhalt],
                      'vorschlag' => $v, 'angaben' => array_slice($angaben, 0, 40), 'cent' => $cent, 'richtung' => $richtung, 'fehler' => $fehler];
        }
        $salden = isset($sp['saldo']) && $saldenOk && $saldoVor !== null
            ? ['anfang' => $saldoVor, 'ende' => $saldoEnde, 'stimmt' => $saldoVor + $summe === $saldoEnde] : null;
        return ['zeilen' => $erg, 'salden' => $salden];
    }

    /** Stichwort für die Art (Kategorie): Kleinbuchstaben, Umlaute umschrieben; null, wenn es nicht taugt. */
    public static function stichwort(string $w): ?string
    {
        $s = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', strtr(mb_strtolower($w), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'])), '-');
        return preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $s) ? $s : null;
    }
}
