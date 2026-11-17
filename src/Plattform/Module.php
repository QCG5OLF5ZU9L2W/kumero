<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Plattform;

use Doctrine\DBAL\Connection;

/**
 * Modulrahmen (ANWENDUNG.md 7.1, KONTENRAHMEN.md K6): Manifeste lesen und ein Modul
 * in einem Mandanten aktivieren – Migrationen, Registrierung, Rechte, Bausteine, Rollen.
 */
final class Module
{
    public static function verzeichnis(): string
    {
        return dirname(__DIR__, 2) . '/module';
    }

    /** @return array<string, array<string, mixed>> Name → Manifest */
    public static function alle(): array
    {
        $erg = [];
        foreach (glob(self::verzeichnis() . '/*/modul.json') ?: [] as $datei) {
            $m = self::manifest(basename(dirname($datei)));
            $erg[$m['name']] = $m;
        }
        ksort($erg);
        return $erg;
    }

    /** @return array<string, mixed> */
    public static function manifest(string $name): array
    {
        if (!preg_match('/^[a-z][a-z0-9-]{0,31}$/', $name) || $name === 'kern') {
            throw new \InvalidArgumentException("Modulname ungültig: $name");
        }
        $datei = self::verzeichnis() . "/$name/modul.json";
        if (!is_file($datei)) {
            throw new \InvalidArgumentException("Modul unbekannt: $name");
        }
        $m = json_decode((string) file_get_contents($datei), true, 16, JSON_THROW_ON_ERROR);
        if (($m['name'] ?? null) !== $name || !preg_match('/^\d+\.\d+\.\d+$/', (string) ($m['version'] ?? '')) || ($m['api'] ?? null) !== '1') {
            throw new \RuntimeException("Manifest von $name ungültig (name, version, api)");
        }
        foreach ($m['rechte'] ?? [] as $r) {
            if (!str_starts_with((string) ($r['code'] ?? ''), "$name.")) {
                throw new \RuntimeException("Manifest von $name: Recht {$r['code']} ohne Modulpräfix");
            }
        }
        if (isset($m['einstieg']) && !class_exists((string) $m['einstieg'])) {
            throw new \RuntimeException("Manifest von $name: Einstiegsklasse {$m['einstieg']} fehlt");
        }
        static $tiefe = 0;
        if (++$tiefe > 16) {
            $tiefe = 0;
            throw new \RuntimeException("Manifest von $name: Abhängigkeiten zu tief oder im Kreis");
        }
        try {
            $abh = [];
            foreach (array_keys($m['abhaengigkeiten'] ?? []) as $d) {
                $abh[$d] = self::manifest((string) $d);
            }
        } finally {
            $tiefe--;
        }
        Menue::pruefen($m, $abh, self::verzeichnis() . "/$name/oeffentlich");
        return $m;
    }

    /** Einstiegsobjekt eines Moduls (Erweiterungspunkte, Routen) oder null. */
    public static function einstieg(string $name): ?object
    {
        static $objekte = [];
        if (!array_key_exists($name, $objekte)) {
            $klasse = self::manifest($name)['einstieg'] ?? null;
            $objekte[$name] = $klasse === null ? null : new $klasse();
        }
        return $objekte[$name];
    }

    /**
     * Aktiviert ein Modul in einem Mandanten bzw. aktualisiert seine Registrierung.
     * Migrationen laufen über db/migrieren.sh (F73: nur Code des installierten, signierten Releases).
     *
     * @param list<string> $psqlOptionen z. B. ['-h', '/var/run/postgresql']
     * @return list<string> Meldungen
     */
    public static function aktivieren(string $name, string $datenbank, Connection $db, string $subjekt, array $psqlOptionen = []): array
    {
        $m = self::manifest($name);
        $meldungen = [];
        $wurzel = dirname(__DIR__, 2);
        $version = trim((string) file_get_contents("$wurzel/VERSION"));
        $stand = $db->fetchAssociative('SELECT release_sha256, freigabe_schluessel FROM meta.version ORDER BY installiert_am DESC LIMIT 1') ?: null;

        if (is_dir(self::verzeichnis() . "/$name/migrationen")) {
            $env = ['KUMERO_FREIGABE' => $version, 'KUMERO_RELEASE_SHA256' => $stand['release_sha256'] ?? str_repeat('0', 64),
                    'KUMERO_FREIGABE_SCHLUESSEL' => $stand['freigabe_schluessel'] ?? 'unbekannt'];
            $befehl = array_merge(['bash', "$wurzel/db/migrieren.sh", '--modul', $name, $datenbank], $psqlOptionen);
            $p = proc_open($befehl, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre, null, $env + getenv());
            $aus = stream_get_contents($rohre[1]) . stream_get_contents($rohre[2]);
            if (proc_close($p) !== 0) {
                throw new \RuntimeException("Migrationen von $name fehlgeschlagen:\n$aus");
            }
            foreach (array_filter(explode("\n", trim($aus))) as $z) {
                $meldungen[] = $z;
            }
        }

        $db->transactional(function (Connection $db) use ($m, $subjekt, $name, &$meldungen): void {
            $db->executeStatement('SELECT kern.modul_registrieren(?, ?, ?, ?::text[])',
                [$subjekt, $name, $m['version'], '{' . implode(',', $m['erweiterungspunkte'] ?? []) . '}']);
            foreach ($m['rechte'] ?? [] as $r) {
                $db->executeStatement('SELECT kern.recht_anmelden(?, ?, ?, ?::boolean, ?::boolean, ?::boolean)',
                    [$subjekt, $r['code'], $r['beschreibung'], self::b($r['buchungsdaten'] ?? true), self::b($r['schreibend'] ?? true), self::b($r['notfall'] ?? false)]);
            }
            foreach ($m['bausteine'] ?? [] as $b) {
                $db->executeStatement('SELECT kern.baustein_setzen(?, ?, ?, ?::text[])',
                    [$subjekt, $b['name'], $b['beschreibung'] ?? null, '{' . implode(',', $b['rechte']) . '}']);
            }
            foreach ($m['rollen'] ?? [] as $rolle => $bausteine) {
                $r = $db->fetchAssociative('SELECT id, art, beschreibung FROM kern.rolle WHERE name = ?', [$rolle]);
                if ($r === false) {
                    $meldungen[] = "Rolle $rolle gibt es in diesem Mandanten nicht – übergangen";
                    continue;
                }
                $liste = $db->fetchAllAssociative(
                    'SELECT b.name AS baustein, rb.einschraenkung::text AS e FROM kern.rolle_baustein rb
                       JOIN kern.baustein b ON b.id = rb.baustein WHERE rb.rolle = ?', [$r['id']]);
                $vorhanden = array_column($liste, 'baustein');
                $neu = array_map(fn ($z) => ['baustein' => $z['baustein'], 'einschraenkung' => $z['e'] === null ? null : json_decode($z['e'], true)], $liste);
                $geaendert = false;
                foreach ($bausteine as $bs) {
                    if (!in_array($bs, $vorhanden, true)) {
                        $neu[] = ['baustein' => $bs, 'einschraenkung' => null];
                        $geaendert = true;
                    }
                }
                if ($geaendert) {
                    $db->executeStatement('SELECT kern.rolle_setzen(?, ?, ?, ?, ?::jsonb)',
                        [$subjekt, $rolle, $r['art'], $r['beschreibung'], json_encode($neu, JSON_UNESCAPED_UNICODE)]);
                    $meldungen[] = "Rolle $rolle: " . implode(', ', $bausteine);
                }
            }
        });
        $meldungen[] = "Modul $name {$m['version']} ist aktiv.";
        return $meldungen;
    }

    private static function b(bool $w): string
    {
        return $w ? 'true' : 'false';
    }
}
