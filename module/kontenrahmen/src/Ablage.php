<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Kontenrahmen;

/**
 * Installationsweite Ablage der Vorlagen als Dateien (KONTENRAHMEN.md K2).
 * Dateiname: <kurzname>-<sha256, 12 Zeichen>.json. Vorlagen werden nie überschrieben;
 * eine geänderte Vorlage ist eine neue Datei.
 */
final class Ablage
{
    public function __construct(private readonly string $verzeichnis)
    {
    }

    public function speichern(Vorlage $v): string
    {
        if (!is_dir($this->verzeichnis) && !mkdir($this->verzeichnis, 0750, true) && !is_dir($this->verzeichnis)) {
            throw new \RuntimeException("Verzeichnis nicht anlegbar: {$this->verzeichnis}");
        }
        $kurz = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(self::ascii($v->daten['name']))), '-') ?: 'vorlage';
        $datei = sprintf('%s/%s-%s.json', $this->verzeichnis, substr($kurz, 0, 40), substr($v->sha256(), 0, 12));
        if (!is_file($datei)) {
            file_put_contents($datei, $v->json(), LOCK_EX);
            @chmod($datei, 0640);
            if (function_exists('posix_getgrnam') && ($g = posix_getgrnam('kumero')) !== false) {
                @chgrp($datei, $g['gid']);
            }
        }
        return $datei;
    }

    /** @return list<array{datei: string, kennung: string, vorlage: Vorlage}> */
    public function alle(): array
    {
        $erg = [];
        foreach (glob($this->verzeichnis . '/*.json') ?: [] as $datei) {
            try {
                $v = Vorlage::ausDaten(json_decode((string) file_get_contents($datei), true, 32, JSON_THROW_ON_ERROR));
            } catch (\Throwable) {
                continue;
            }
            $erg[] = ['datei' => $datei, 'kennung' => basename($datei, '.json'), 'vorlage' => $v];
        }
        return $erg;
    }

    /** Sucht nach Dateiname (ohne .json), Pfad oder Anfang des SHA-256. */
    public function laden(string $kennung): Vorlage
    {
        if (is_file($kennung)) {
            return self::ausDatei($kennung);
        }
        $treffer = array_values(array_filter($this->alle(), fn ($e) => $e['kennung'] === $kennung
            || (strlen($kennung) >= 8 && str_starts_with($e['vorlage']->sha256(), $kennung))));
        if (count($treffer) !== 1) {
            throw new \RuntimeException(count($treffer) === 0 ? "Vorlage $kennung nicht gefunden" : "Vorlage $kennung ist nicht eindeutig");
        }
        return $treffer[0]['vorlage'];
    }

    public static function ausDatei(string $datei): Vorlage
    {
        return Vorlage::ausDaten(json_decode((string) file_get_contents($datei), true, 32, JSON_THROW_ON_ERROR));
    }

    private static function ascii(string $s): string
    {
        return strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
    }
}
