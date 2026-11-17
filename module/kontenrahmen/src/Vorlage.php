<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Kontenrahmen;

use Kumero\Plattform\Jcs;

/**
 * Vorlage im Format kumero-kontenrahmen/1 (KONTENRAHMEN.md Abschnitt 3):
 * entsteht beim Einlesen, liegt installationsweit als Datei (K2), wird auf Mandanten angewendet.
 */
final class Vorlage
{
    public const FORMAT = 'kumero-kontenrahmen/1';

    /** @param array<string, mixed> $daten */
    private function __construct(public readonly array $daten)
    {
    }

    /**
     * Baut eine Vorlage aus gelesenen Konten, Regelwerk und optionaler CSV-Ergänzung.
     *
     * @param list<array<string, mixed>> $konten Konten aus der Quelle (nr, bezeichnung, optional kontoart, kennzeichen, fussnote)
     * @param array<string, mixed> $quelle
     */
    public static function bauen(string $name, string $rahmen, ?string $fassung, array $quelle, Regelwerk $regeln,
                                 array $konten, array $bereiche = [], array $bereichsfunktionen = [], array $hinweise = [],
                                 ?array $ergaenzung = null): self
    {
        $liste = [];
        $ausgelassen = [];
        foreach ($konten as $k) {
            $nr = (string) $k['nr'];
            if ($regeln->istPersonenkonto($nr)) {
                $ausgelassen[] = ['nr' => $nr, 'bezeichnung' => $k['bezeichnung'], 'grund' => 'Personenkonto (Modul opos)'];
                continue;
            }
            if (strlen($nr) !== $regeln->nummernlaenge()) {
                $hinweise[] = ['art' => 'nummernlaenge', 'text' => "Konto $nr hat nicht " . $regeln->nummernlaenge() . ' Stellen'];
            }
            if (isset($liste[$nr])) {
                $hinweise[] = ['art' => 'doppelt', 'text' => "Konto $nr kommt mehrfach vor; die erste Beschriftung gilt"];
                continue;
            }
            $e = ['nr' => $nr, 'bezeichnung' => (string) $k['bezeichnung']];
            foreach (['kennzeichen', 'fussnote'] as $f) {
                if (isset($k[$f])) {
                    $e[$f] = (string) $k[$f];
                }
            }
            if (isset($k['kontoart'])) {
                $e['kontoart'] = $k['kontoart'];
                $e['kontoart_herkunft'] = 'quelle';
            } else {
                $r = $regeln->einordnen($nr);
                if ($r !== null && $r['auslassen']) {
                    $ausgelassen[] = ['nr' => $nr, 'bezeichnung' => $e['bezeichnung'], 'grund' => $r['hinweis']];
                    continue;
                }
                $e['kontoart'] = $r['kontoart'] ?? null;
                $e['kontoart_herkunft'] = 'regel';
                if ($r === null) {
                    $e['offen'] = 'außerhalb aller Bereiche des Regelwerks';
                } elseif ($r['kontoart'] === null) {
                    $e['offen'] = $r['hinweis'];
                } elseif ($r['pruefen']) {
                    $e['pruefen'] = $r['hinweis'];
                }
            }
            $liste[$nr] = $e;
        }

        // Ergänzung (CSV): hinzufügen, Kontoart oder Bezeichnung setzen, weglassen
        if ($ergaenzung !== null) {
            foreach ($ergaenzung['zeilen'] as $z) {
                $nr = $z['nr'];
                if ($z['weglassen']) {
                    if (isset($liste[$nr])) {
                        $ausgelassen[] = ['nr' => $nr, 'bezeichnung' => $liste[$nr]['bezeichnung'], 'grund' => 'per Ergänzung weggelassen'];
                        unset($liste[$nr]);
                    }
                    continue;
                }
                $e = $liste[$nr] ?? ['nr' => $nr, 'bezeichnung' => ''];
                if ($z['bezeichnung'] !== null) {
                    $e['bezeichnung'] = $z['bezeichnung'];
                }
                if ($z['kontoart'] !== null) {
                    $e['kontoart'] = $z['kontoart'];
                    $e['kontoart_herkunft'] = 'hand';
                    unset($e['offen'], $e['pruefen']);
                } elseif (!isset($e['kontoart_herkunft'])) {
                    $r = $regeln->einordnen($nr);
                    $e['kontoart'] = $r['kontoart'] ?? null;
                    $e['kontoart_herkunft'] = 'regel';
                    if ($e['kontoart'] === null) {
                        $e['offen'] = 'Kontoart angeben';
                    }
                }
                $ausgelassen = array_values(array_filter($ausgelassen, fn ($a) => $a['nr'] !== $nr));
                $liste[$nr] = $e;
            }
            $hinweise = array_merge($hinweise, $ergaenzung['hinweise']);
            $quelle['ergaenzung_sha256'] = $ergaenzung['sha256'];
        }
        foreach ($liste as $nr => $e) {
            if (trim($e['bezeichnung']) === '') {
                $liste[$nr]['offen'] = ($e['offen'] ?? '') !== '' ? $e['offen'] : 'Bezeichnung fehlt';
            }
        }
        ksort($liste, SORT_STRING);
        usort($bereiche, fn ($a, $b) => strcmp($a['von'], $b['von']));
        $bereiche = array_map(fn ($b) => array_intersect_key($b, array_flip(['von', 'bis', 'bezeichnung', 'kennzeichen', 'fussnote'])), $bereiche);

        $d = [
            'format' => self::FORMAT,
            'name' => $name,
            'rahmen' => $rahmen,
            'fassung' => $fassung,
            'quelle' => $quelle,
            'regelwerk' => ['name' => $regeln->name(), 'version' => $regeln->version()],
            'konten' => array_values($liste),
            'bereiche' => $bereiche,
            'bereichsfunktionen' => $bereichsfunktionen,
            'ausgelassen' => $ausgelassen,
            'hinweise' => array_values($hinweise),
        ];
        return new self($d);
    }

    /** @param array<string, mixed> $d */
    public static function ausDaten(array $d): self
    {
        if (($d['format'] ?? null) !== self::FORMAT || !is_array($d['konten'] ?? null)) {
            throw new \RuntimeException('Keine Vorlage im Format ' . self::FORMAT);
        }
        return new self($d);
    }

    public function sha256(): string
    {
        return Jcs::sha256($this->daten);
    }

    /** @return list<array<string, mixed>> Konten mit offenen Punkten (verhindern das Anwenden) */
    public function offen(): array
    {
        return array_values(array_filter($this->daten['konten'], fn ($k) => isset($k['offen']) || !in_array($k['kontoart'] ?? null, CsvLeser::KONTOARTEN, true)));
    }

    /** @return list<array<string, mixed>> Konten, deren Kontoart zu bestätigen ist */
    public function pruefen(): array
    {
        return array_values(array_filter($this->daten['konten'], fn ($k) => isset($k['pruefen'])));
    }

    public function json(): string
    {
        return json_encode($this->daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
