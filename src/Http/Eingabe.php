<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Http;

use Kumero\Api\V1\Problem;

/** Prüft JSON-Eingaben feldweise und sammelt alle Fehler (Problem „anfrage.ungueltig“). */
final class Eingabe
{
    /** @var list<array{feld: string, meldung: string}> */
    private array $fehler = [];

    /** @param array<string, mixed> $daten */
    public function __construct(private readonly array $daten)
    {
    }

    public function text(string $feld, bool $pflicht = true, int $max = 500): ?string
    {
        $w = $this->daten[$feld] ?? null;
        if ($w === null) {
            if ($pflicht) {
                $this->fehler[] = ['feld' => $feld, 'meldung' => 'Pflichtfeld'];
            }
            return null;
        }
        if (!is_string($w) || trim($w) === '' || mb_strlen($w) > $max) {
            $this->fehler[] = ['feld' => $feld, 'meldung' => "Text mit 1 bis $max Zeichen erwartet"];
            return null;
        }
        return $w;
    }

    public function wahr(string $feld, bool $pflicht = true): ?bool
    {
        $w = $this->daten[$feld] ?? null;
        if ($w === null) {
            if ($pflicht) {
                $this->fehler[] = ['feld' => $feld, 'meldung' => 'Pflichtfeld'];
            }
            return null;
        }
        if (!is_bool($w)) {
            $this->fehler[] = ['feld' => $feld, 'meldung' => 'true oder false erwartet'];
            return null;
        }
        return $w;
    }

    public function ganzzahl(string $feld, bool $pflicht = true): ?int
    {
        $w = $this->daten[$feld] ?? null;
        if ($w === null) {
            if ($pflicht) {
                $this->fehler[] = ['feld' => $feld, 'meldung' => 'Pflichtfeld'];
            }
            return null;
        }
        if (!is_int($w)) {
            $this->fehler[] = ['feld' => $feld, 'meldung' => 'Ganzzahl erwartet'];
            return null;
        }
        return $w;
    }

    /** @param list<string> $werte */
    public function auswahl(string $feld, array $werte, bool $pflicht = true): ?string
    {
        $w = $this->text($feld, $pflicht, 50);
        if ($w !== null && !in_array($w, $werte, true)) {
            $this->fehler[] = ['feld' => $feld, 'meldung' => 'Erlaubt: ' . implode(', ', $werte)];
            return null;
        }
        return $w;
    }

    public function datum(string $feld, bool $pflicht = true): ?string
    {
        $w = $this->text($feld, $pflicht, 10);
        if ($w !== null) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $w);
            if ($d === false || $d->format('Y-m-d') !== $w) {
                $this->fehler[] = ['feld' => $feld, 'meldung' => 'Datum YYYY-MM-DD erwartet'];
                return null;
            }
        }
        return $w;
    }

    /** @param list<string> $erlaubt */
    public function nurFelder(array $erlaubt): void
    {
        foreach (array_keys($this->daten) as $f) {
            if (!in_array($f, $erlaubt, true)) {
                $this->fehler[] = ['feld' => (string) $f, 'meldung' => 'Unbekanntes Feld'];
            }
        }
    }

    public function pruefen(): void
    {
        if ($this->fehler !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $this->fehler);
        }
    }
}
