<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Kontenrahmen;

/**
 * Regelwerk eines Kontenrahmens: Nummernbereiche → Kontoart (KONTENRAHMEN.md Abschnitt 4).
 * Der engste passende Bereich gilt.
 */
final class Regelwerk
{
    /** @param array<string, mixed> $daten */
    private function __construct(public readonly array $daten)
    {
    }

    public static function laden(string $name): self
    {
        if (!preg_match('/^[a-z0-9_-]{1,32}$/', $name)) {
            throw new \InvalidArgumentException("Regelwerk-Name ungültig: $name");
        }
        $datei = dirname(__DIR__) . "/regeln/$name.json";
        if (!is_file($datei)) {
            throw new \InvalidArgumentException("Regelwerk unbekannt: $name");
        }
        $d = json_decode((string) file_get_contents($datei), true, 16, JSON_THROW_ON_ERROR);
        if (($d['format'] ?? '') !== 'kumero-regelwerk/1') {
            throw new \RuntimeException("Regelwerk $name hat ein unbekanntes Format");
        }
        return new self($d);
    }

    /** @return list<array{name: string, rahmen: string, version: string, beschreibung: string}> */
    public static function alle(): array
    {
        $erg = [];
        foreach (glob(dirname(__DIR__) . '/regeln/*.json') ?: [] as $datei) {
            $r = self::laden(basename($datei, '.json'));
            $erg[] = ['name' => $r->name(), 'rahmen' => (string) ($r->daten['rahmen'] ?? strtoupper($r->name())),
                      'version' => $r->version(), 'beschreibung' => (string) ($r->daten['beschreibung'] ?? '')];
        }
        return $erg;
    }

    public function name(): string
    {
        return $this->daten['name'];
    }

    public function version(): string
    {
        return $this->daten['version'];
    }

    public function nummernlaenge(): int
    {
        return (int) $this->daten['nummernlaenge'];
    }

    public function istPersonenkonto(string $nr): bool
    {
        $p = $this->daten['personenkonten'] ?? null;
        return $p !== null && strlen($nr) === strlen($p['von']) && $nr >= $p['von'] && $nr <= $p['bis'];
    }

    /** @return array{kontoart: ?string, pruefen: bool, auslassen: bool, hinweis: string}|null */
    public function einordnen(string $nr): ?array
    {
        $treffer = null;
        foreach ($this->daten['bereiche'] as $b) {
            if (strlen($nr) === strlen($b['von']) && $nr >= $b['von'] && $nr <= $b['bis']) {
                $breite = (int) $b['bis'] - (int) $b['von'];
                if ($treffer === null || $breite < $treffer[0]) {
                    $treffer = [$breite, $b];
                }
            }
        }
        if ($treffer === null) {
            return null;
        }
        $b = $treffer[1];
        return ['kontoart' => $b['kontoart'], 'pruefen' => (bool) ($b['pruefen'] ?? false),
                'auslassen' => (bool) ($b['auslassen'] ?? false), 'hinweis' => $b['hinweis']];
    }
}
