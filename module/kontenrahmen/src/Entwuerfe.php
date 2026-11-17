<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Kontenrahmen;

/**
 * Entwürfe des Imports in der Weboberfläche (F80): eingelesene Rohdaten plus Korrekturen,
 * bis daraus eine Vorlage gespeichert wird. Ablage als Datei unter <vorlagen>/entwuerfe,
 * installationsweit wie die Vorlagen (K2). Entwürfe älter als 14 Tage werden entfernt.
 */
final class Entwuerfe
{
    public const FORMAT = 'kumero-kontenrahmen-entwurf/1';
    private const HALTBAR_TAGE = 14;

    private readonly string $verzeichnis;

    public function __construct(string $vorlagenVerzeichnis)
    {
        $this->verzeichnis = rtrim($vorlagenVerzeichnis, '/') . '/entwuerfe';
    }

    /** @param array<string, mixed> $roh */
    public function anlegen(string $name, string $rahmen, string $regelwerk, array $roh, string $subjekt): array
    {
        $e = ['format' => self::FORMAT, 'id' => self::uuid(), 'name' => $name, 'rahmen' => $rahmen, 'regelwerk' => $regelwerk,
              'angelegt_am' => gmdate('Y-m-d\TH:i:s\Z'), 'angelegt_von' => $subjekt, 'geaendert_am' => null,
              'roh' => $roh, 'korrekturen' => new \stdClass()];
        $this->schreiben($e);
        return $this->laden($e['id']);
    }

    /** @return array<string, mixed> */
    public function laden(string $id): array
    {
        $datei = $this->datei($id);
        if (!is_file($datei)) {
            throw new \OutOfBoundsException('Entwurf nicht gefunden');
        }
        $e = json_decode((string) file_get_contents($datei), true, 64, JSON_THROW_ON_ERROR);
        if (($e['format'] ?? null) !== self::FORMAT) {
            throw new \RuntimeException('Entwurf in unbekanntem Format');
        }
        $e['korrekturen'] = (array) ($e['korrekturen'] ?? []);
        return $e;
    }

    /** @return list<array<string, mixed>> neueste zuerst */
    public function alle(): array
    {
        $this->aufraeumen();
        $erg = [];
        foreach (glob($this->verzeichnis . '/*.json') ?: [] as $datei) {
            try {
                $erg[] = $this->laden(basename($datei, '.json'));
            } catch (\Throwable) {
                continue;
            }
        }
        usort($erg, fn ($a, $b) => strcmp($b['angelegt_am'], $a['angelegt_am']));
        return $erg;
    }

    /** @param array<string, array<string, mixed>> $korrekturen */
    public function korrigieren(string $id, array $korrekturen): array
    {
        $e = $this->laden($id);
        ksort($korrekturen, SORT_STRING);
        $e['korrekturen'] = $korrekturen === [] ? new \stdClass() : $korrekturen;
        $e['geaendert_am'] = gmdate('Y-m-d\TH:i:s\Z');
        $this->schreiben($e);
        return $this->laden($id);
    }

    public function loeschen(string $id): void
    {
        $datei = $this->datei($id);
        if (!is_file($datei)) {
            throw new \OutOfBoundsException('Entwurf nicht gefunden');
        }
        unlink($datei);
    }

    /** @param array<string, mixed> $e */
    public static function vorlage(array $e): Vorlage
    {
        return Einlesen::vorlage($e['name'], $e['rahmen'], Regelwerk::laden($e['regelwerk']), $e['roh'], (array) $e['korrekturen']);
    }

    private function aufraeumen(): void
    {
        $grenze = time() - self::HALTBAR_TAGE * 86400;
        foreach (glob($this->verzeichnis . '/*.json') ?: [] as $datei) {
            if (@filemtime($datei) < $grenze) {
                @unlink($datei);
            }
        }
    }

    /** @param array<string, mixed> $e */
    private function schreiben(array $e): void
    {
        if (!is_dir($this->verzeichnis) && !@mkdir($this->verzeichnis, 0770, true) && !is_dir($this->verzeichnis)) {
            throw new \RuntimeException('Verzeichnis für Entwürfe nicht anlegbar – Rechte von ' . dirname($this->verzeichnis) . ' prüfen');
        }
        $datei = $this->datei($e['id']);
        $tmp = $datei . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new \RuntimeException('Entwurf nicht schreibbar – Rechte von ' . $this->verzeichnis . ' prüfen');
        }
        @chmod($tmp, 0660);
        rename($tmp, $datei);
    }

    private function datei(string $id): string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id)) {
            throw new \OutOfBoundsException('Entwurf nicht gefunden');
        }
        return "{$this->verzeichnis}/$id.json";
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
