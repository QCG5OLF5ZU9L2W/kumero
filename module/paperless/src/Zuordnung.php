<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Paperless;

use Doctrine\DBAL\Connection;

/**
 * Zuordnung von Paperless-Feldern zum Buchungssatz (PAPERLESS.md Abschnitt 12, F95). Quellen sind feste
 * Felder des Dokuments, benutzerdefinierte Felder und Tags; Ziele sind die Felder des Eintrags im
 * Arbeitsvorrat. Je Ziel gilt die erste Quelle mit einem Wert. Werte werden streng gelesen, nie geraten.
 */
final class Zuordnung
{
    /** Ziel → Art des Werts */
    public const ZIELE = ['betreff' => 'text', 'art' => 'art', 'gegenpartei' => 'text', 'gegenpartei_kennung' => 'text', 'datum' => 'datum',
        'betrag' => 'betrag', 'netto' => 'betrag', 'steuer' => 'betrag', 'richtung' => 'richtung', 'belegnummer' => 'text',
        'zahlweise' => 'zahlweise', 'zahltag' => 'datum', 'anzeigen' => 'anzeigen'];
    public const FEST = ['titel', 'korrespondent', 'dokumenttyp', 'erstellt', 'archivnummer'];
    private const LAENGE = ['betreff' => 200, 'gegenpartei' => 200, 'gegenpartei_kennung' => 60, 'belegnummer' => 36];

    /** Ohne jede Zuordnung: das, was Paperless von sich aus weiß. @return array<string, list<array<string, string>>> */
    public static function standard(): array
    {
        $f = fn (string $n) => [['art' => 'fest', 'name' => $n]];
        return ['betreff' => $f('titel'), 'gegenpartei' => $f('korrespondent'), 'datum' => $f('erstellt'), 'art' => $f('dokumenttyp')];
    }

    /**
     * Wirksame Zuordnung: Standard, darüber die Vorgabe des Mandanten, darüber je Ziel die eigene (Z3).
     *
     * @return array{wirksam: array<string, mixed>, mandant: ?array<string, mixed>, eigene: ?array<string, mixed>}
     */
    public static function laden(Connection $db, string $adresse, string $subjekt): array
    {
        $z = $db->fetchAllKeyValue('SELECT subjekt, regeln::text FROM paperless.zuordnung WHERE adresse = ? AND subjekt IN (?, ?)', [$adresse, '', $subjekt]);
        $mandant = isset($z['']) ? json_decode((string) $z[''], true) : null;
        $eigene = isset($z[$subjekt]) ? json_decode((string) $z[$subjekt], true) : null;
        return ['wirksam' => array_merge(self::standard(), $mandant ?? [], $eigene ?? []), 'mandant' => $mandant, 'eigene' => $eigene];
    }

    /**
     * Prüft die Form einer Zuordnung.
     *
     * @return array{0: array<string, mixed>, 1: list<array{feld: string, meldung: string}>}
     */
    public static function pruefen(mixed $regeln): array
    {
        $f = [];
        $erg = [];
        if (!is_array($regeln) || ($regeln !== [] && array_is_list($regeln))) {
            return [[], [['feld' => 'regeln', 'meldung' => 'Objekt {ziel: [quelle, …]} erwartet']]];
        }
        foreach ($regeln as $ziel => $quellen) {
            if (!isset(self::ZIELE[$ziel]) || !is_array($quellen) || !array_is_list($quellen) || count($quellen) > 20) {
                $f[] = ['feld' => "regeln.$ziel", 'meldung' => 'Unbekanntes Ziel oder keine Liste von Quellen'];
                continue;
            }
            foreach ($quellen as $n => $q) {
                $art = is_array($q) ? ($q['art'] ?? null) : null;
                $name = is_array($q) ? ($q['name'] ?? null) : null;
                $ok = is_string($name) && $name !== '' && mb_strlen($name) <= 200 && array_diff(array_keys($q), ['art', 'name', 'wert']) === []
                    && match ($art) {
                        'fest' => in_array($name, self::FEST, true) && !isset($q['wert']),
                        'feld' => !isset($q['wert']),
                        'tag' => is_string($q['wert'] ?? null) && $q['wert'] !== '' && mb_strlen($q['wert']) <= 60 && in_array(self::ZIELE[$ziel], ['richtung', 'zahlweise', 'art'], true),
                        default => false,
                    };
                if (!$ok) {
                    $f[] = ['feld' => "regeln.$ziel[$n]", 'meldung' => 'Quelle erwartet: {art: fest|feld|tag, name}, bei tag dazu wert (nur für Richtung, Zahlweise, Art)'];
                    continue;
                }
                $erg[$ziel][] = $art === 'tag' ? ['art' => 'tag', 'name' => $name, 'wert' => $q['wert']] : ['art' => $art, 'name' => $name];
            }
            $erg[$ziel] ??= [];
        }
        return [$erg, $f];
    }

    /**
     * Vorschlag aus den Namen der benutzerdefinierten Felder (Z8) – gespeichert wird erst, was bestätigt ist.
     *
     * @param list<array<string, mixed>> $felder
     * @return array<string, list<array<string, string>>>
     */
    public static function vorschlag(array $felder): array
    {
        $muster = ['belegnummer' => '/rechnungsnummer|belegnummer/u', 'datum' => '/rechnungsdatum|belegdatum/u', 'gegenpartei' => '/rechnungssteller|lieferant|aussteller/u',
            'art' => '/belegart/u',
            'betrag' => '/brutto|gesamtbetrag|zahlbetrag/u', 'netto' => '/netto/u', 'gegenpartei_kennung' => '/steuernummer|ust-?id|iban/u',
            'steuer' => '/(umsatz|mehrwert|vor)steuer\b|mwst|\bust\b|steuerbetrag|\bsteuer\b/u', 'zahltag' => '/zahl(ungs)?datum|zahltag/u'];
        $erg = self::standard();
        foreach ($felder as $fd) {
            $name = (string) ($fd['name'] ?? '');
            foreach ($muster as $ziel => $m) {
                if (preg_match($m, mb_strtolower($name)) && !in_array(['art' => 'feld', 'name' => $name], $erg[$ziel] ?? [], true)) {
                    $erg[$ziel] = [['art' => 'feld', 'name' => $name], ...array_filter($erg[$ziel] ?? [], fn ($q) => $q['art'] !== 'feld')];
                    break;
                }
            }
        }
        return $erg;
    }

    /**
     * Bildet aus einem Dokument den Vorschlag für den Arbeitsvorrat.
     *
     * @param array<string, mixed> $regeln wirksame Zuordnung
     * @param array<string, mixed> $dok Dokument aus Paperless (id, title, created, correspondent, document_type, tags, custom_fields, archive_serial_number)
     * @param array<string, array<string, mixed>> $felder benutzerdefinierte Felder nach Namen
     * @param array{korrespondenten: array<int, string>, typen: array<int, string>, tags: array<int, string>} $namen
     * @return array{0: array<string, mixed>, 1: list<array{bezeichnung: string, wert: string}>}
     */
    public static function anwenden(array $regeln, array $dok, array $felder, array $namen): array
    {
        $tags = [];
        foreach ($dok['tags'] ?? [] as $t) {
            if (isset($namen['tags'][$t])) {
                $tags[$namen['tags'][$t]] = true;
            }
        }
        $werte = [];
        foreach ($dok['custom_fields'] ?? [] as $cf) {
            if (is_array($cf) && isset($cf['field'])) {
                $werte[(int) $cf['field']] = $cf['value'] ?? null;
            }
        }
        // Rohwert einer Quelle: [Wert, Währung]
        $lesen = function (array $q) use ($dok, $felder, $namen, $tags, $werte): array {
            if ($q['art'] === 'tag') {
                return [isset($tags[$q['name']]) ? $q['wert'] : null, null];
            }
            if ($q['art'] === 'fest') {
                return [match ($q['name']) {
                    'titel' => $dok['title'] ?? null,
                    'korrespondent' => $namen['korrespondenten'][$dok['correspondent'] ?? -1] ?? null,
                    'dokumenttyp' => $namen['typen'][$dok['document_type'] ?? -1] ?? null,
                    'erstellt' => is_string($dok['created'] ?? null) ? substr($dok['created'], 0, 10) : null,
                    'archivnummer' => isset($dok['archive_serial_number']) ? (string) $dok['archive_serial_number'] : null,
                    default => null,
                }, null];
            }
            $fd = $felder[$q['name']] ?? null;
            $w = $fd === null ? null : ($werte[(int) $fd['id']] ?? null);
            if ($w === null || $w === '') {
                return [null, null];
            }
            $typ = (string) ($fd['data_type'] ?? 'string');
            if ($typ === 'monetary' && is_string($w) && preg_match('/^([A-Z]{3})?(-?\d+(?:\.\d+)?)$/', $w, $m)) {
                return [$m[2], $m[1] !== '' ? $m[1] : null];
            }
            if ($typ === 'select') {
                foreach ($fd['extra_data']['select_options'] ?? [] as $n => $o) {
                    // seit API-Version 7 Objekte mit id und label, davor Texte nach Stelle
                    if (is_array($o) ? (string) ($o['id'] ?? '') === (string) $w : $n === $w) {
                        return [is_array($o) ? (string) ($o['label'] ?? '') : (string) $o, null];
                    }
                }
                return [null, null];
            }
            if (is_bool($w)) {
                return [$w ? 'ja' : 'nein', null];
            }
            return [is_scalar($w) ? (string) $w : null, null];
        };
        $v = [];
        $hinweise = [];
        $angaben = [];
        foreach ($regeln as $ziel => $quellen) {
            $artZiel = self::ZIELE[$ziel] ?? null;
            foreach ($quellen as $q) {
                [$w, $waehrung] = $lesen($q);
                $w = is_string($w) ? trim($w) : $w;
                if ($w === null || $w === '') {
                    continue;
                }
                if ($artZiel === 'anzeigen') {
                    $angaben[] = ['bezeichnung' => mb_substr($q['name'], 0, 60), 'wert' => mb_substr(($waehrung ? "$waehrung " : '') . $w, 0, 1000)];
                    continue;
                }
                $wert = match ($artZiel) {
                    'text' => mb_substr($w, 0, self::LAENGE[$ziel] ?? 200),
                    'datum' => preg_match('/^\d{4}-\d{2}-\d{2}/', $w, $m) ? $m[0] : null,
                    // mit dem Vorzeichen des Belegs (nopaper E60); was daraus wird, entscheidet vorzeichen() unten
                    'betrag' => ($waehrung === null || $waehrung === 'EUR') && preg_match('/^-?\d+(\.\d+)?$/', $w) ? number_format((float) $w, 2, '.', '') : null,
                    'art' => self::stichwort($w),
                    'richtung' => in_array(mb_strtolower($w), ['ausgabe', 'einnahme'], true) ? mb_strtolower($w) : null,
                    'zahlweise' => in_array(mb_strtolower($w), ['bar', 'unbar'], true) ? mb_strtolower($w) : null,
                    default => null,
                };
                if ($wert === null || (in_array($ziel, ['betrag', 'netto'], true) && (float) $wert == 0)) {
                    // nicht raten: Der Wert passt nicht zum Ziel und wird als Hinweis vermerkt
                    $hinweise[] = ['feld' => $ziel, 'text' => mb_substr('Paperless-Feld „' . $q['name'] . '“ passt nicht: ' . ($waehrung ? "$waehrung " : '') . $w, 0, 300)];
                    continue;
                }
                $v[$ziel] = $wert;
                break;
            }
        }
        // Sidecar von nopaper am Dokument (F127): Er geht den einzelnen Feldern vor; die Felder sind der Rückfall.
        // Weicht ein Feld ab – jemand hat es in Paperless geändert –, steht das als Hinweis da.
        $sidecar = Sidecar::lesen($dok);
        if ($sidecar !== null) {
            $namenZiel = ['betrag' => 'Betrag', 'netto' => 'Netto', 'steuer' => 'Steuer', 'belegnummer' => 'Belegnummer'];
            // Hinweise der Felder zu einem Ziel, das der Sidecar liefert, sind überholt
            $hinweise = array_values(array_filter($hinweise, fn (array $h) => !isset($h['feld']) || !array_key_exists($h['feld'], $sidecar['werte'])));
            foreach ($sidecar['werte'] as $ziel => $w) {
                if (isset($namenZiel[$ziel], $v[$ziel]) && $v[$ziel] !== $w) {
                    $hinweise[] = ['feld' => $ziel, 'text' => mb_substr("{$namenZiel[$ziel]}: Paperless-Feld ({$v[$ziel]}) weicht vom Sidecar ($w) ab – vorbelegt ist der Sidecar", 0, 300)];
                }
                $v[$ziel] = $w;
            }
            array_push($hinweise, ...$sidecar['hinweise']);
            array_push($angaben, ...$sidecar['angaben']);
        }
        [$v, $hinweise] = self::vorzeichen($v, $hinweise);
        // Brutto aus Netto und Steuer, wenn kein Bruttobetrag zugeordnet ist (X2) – gerechnet, nicht geraten
        if (!isset($v['betrag']) && isset($v['netto'], $v['steuer'])) {
            $v['betrag'] = number_format((float) $v['netto'] + (float) $v['steuer'], 2, '.', '');
            $hinweise[] = ['feld' => 'betrag', 'text' => 'Betrag aus Netto und Steuer berechnet'];
        } elseif (!isset($v['betrag']) && isset($v['netto'])) {
            $hinweise[] = ['feld' => 'betrag', 'text' => 'Nur der Nettobetrag ist bekannt (' . $v['netto'] . '); der Betrag der Buchung ist brutto'];
        }
        if ($hinweise !== []) {
            $v['hinweise'] = array_slice($hinweise, 0, 30);
        }
        return [$v, array_slice($angaben, 0, 40)];
    }

    /**
     * Beträge stehen im Eintrag ohne Vorzeichen, die Richtung sagt, wohin das Geld fließt. Ein negativer Betrag – so,
     * wie er auf dem Beleg gedruckt ist (z. B. Pfandrückgabe größer als der Einkauf) – kehrt die Richtung um (F127).
     * Passen die Vorzeichen nicht zusammen, wird nichts gedeutet: Der abweichende Wert entfällt mit Hinweis.
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, string>>}
     */
    private static function vorzeichen(array $v, array $hinweise): array
    {
        $leit = $v['betrag'] ?? $v['netto'] ?? null;
        if ($leit === null) {
            unset($v['steuerzeilen']);
            if (isset($v['steuer']) && (float) $v['steuer'] < 0) {
                unset($v['steuer']);
            }
            return [$v, $hinweise];
        }
        $negativ = (float) $leit < 0;
        $betrag = fn (string $w) => number_format(abs((float) $w), 2, '.', '');
        foreach (['betrag', 'netto', 'steuer'] as $ziel) {
            if (!isset($v[$ziel])) {
                continue;
            }
            // 0,00 Steuer passt zu jedem Vorzeichen
            if ((float) $v[$ziel] != 0 && ((float) $v[$ziel] < 0) !== $negativ) {
                $hinweise[] = ['feld' => $ziel, 'text' => "Vorzeichen passt nicht zum Betrag ({$v[$ziel]}) – nicht übernommen"];
                unset($v[$ziel]);
                continue;
            }
            $v[$ziel] = $betrag($v[$ziel]);
        }
        if (isset($v['steuerzeilen'])) {
            $zeilen = [];
            foreach ($v['steuerzeilen'] as $z) {
                if ((float) $z['netto'] == 0 || ((float) $z['netto'] < 0) !== $negativ || ((float) $z['steuer'] != 0 && ((float) $z['steuer'] < 0) !== $negativ)) {
                    $zeilen = null;
                    break;
                }
                $zeilen[] = ['satz' => $z['satz'], 'netto' => $betrag($z['netto']), 'steuer' => $betrag($z['steuer'])];
            }
            if ($zeilen === null || !isset($v['betrag'])) {
                unset($v['steuerzeilen']);
            } else {
                $v['steuerzeilen'] = $zeilen;
            }
        }
        if ($negativ) {
            $v['richtung'] = ($v['richtung'] ?? 'ausgabe') === 'einnahme' ? 'ausgabe' : 'einnahme';
            $hinweise[] = ['feld' => 'richtung', 'text' => 'Der Beleg weist einen negativen Betrag aus – vorbelegt mit umgekehrter Richtung (' . ($v['richtung'] === 'einnahme' ? 'Einnahme' : 'Ausgabe') . ')'];
        }
        return [$v, $hinweise];
    }

    /** Stichwort für die Art: Kleinbuchstaben, Umlaute umschrieben; null, wenn es nicht taugt. */
    public static function stichwort(string $w): ?string
    {
        $s = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', strtr(mb_strtolower($w), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'])), '-');
        return preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $s) ? $s : null;
    }
}
