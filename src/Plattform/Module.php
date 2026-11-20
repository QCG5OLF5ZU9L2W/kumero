<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Plattform;

use Doctrine\DBAL\Connection;

/**
 * Modulrahmen (ANWENDUNG.md 7.1, KONTENRAHMEN.md K6): Manifeste lesen und ein Modul
 * aktivieren – Migrationen, Registrierung, Rechte, Bausteine, Rollen.
 *
 * Ein Modul gehört zu einer Ebene (F135): `buch` (Vorgabe; Schema in der Datenbank eines Buchs, Rechte und Rollen im
 * Schema `kern`) oder `mandant` (Schema in der Datenbank des Mandanten, Rechte und Rollen im Schema `mandant`).
 */
final class Module
{
    public const EBENEN = ['buch', 'mandant'];

    /** @var array<string, string> Name → Verzeichnis von Modulen außerhalb von module/ (nur Tests) */
    private static array $weitere = [];

    public static function verzeichnis(): string
    {
        return dirname(__DIR__, 2) . '/module';
    }

    /** Verzeichnis eines Moduls. */
    public static function pfad(string $name): string
    {
        return self::$weitere[$name] ?? self::verzeichnis() . "/$name";
    }

    /**
     * Meldet ein Modul aus einem anderen Verzeichnis an. Nur für Tests: Im Betrieb gibt es allein die Module des
     * installierten, signierten Releases (F73); ein solches Modul kann keine Migrationen haben. Ohne Verzeichnis
     * wird es wieder abgemeldet.
     *
     * @internal
     */
    public static function fuerTestsAnmelden(string $name, ?string $verzeichnis): void
    {
        if (getenv('KUMERO_TEST') !== '1') {
            throw new \LogicException('Module außerhalb von module/ gibt es nur in Tests');
        }
        if ($verzeichnis === null) {
            unset(self::$weitere[$name]);
        } else {
            self::$weitere[$name] = $verzeichnis;
        }
        \Kumero\Http\Routen::verwerfen();
    }

    /** Ebene eines Manifests: buch oder mandant. @param array<string, mixed> $m */
    public static function ebene(array $m): string
    {
        return (string) ($m['ebene'] ?? 'buch');
    }

    /** @return array<string, array<string, mixed>> Name → Manifest */
    public static function alle(): array
    {
        $erg = [];
        foreach (glob(self::verzeichnis() . '/*/modul.json') ?: [] as $datei) {
            $m = self::manifest(basename(dirname($datei)));
            $erg[$m['name']] = $m;
        }
        foreach (array_keys(self::$weitere) as $name) {
            $erg[$name] = self::manifest($name);
        }
        ksort($erg);
        return $erg;
    }

    /**
     * Namen der Module so geordnet, dass jedes nach denen steht, von denen es abhängt (für Einschalten und Nachziehen).
     *
     * @param list<string> $namen
     * @return list<string>
     */
    public static function reihenfolge(array $namen): array
    {
        $alle = self::alle();
        $erg = [];
        $setzen = function (string $n) use (&$setzen, &$erg, $alle, $namen): void {
            if (in_array($n, $erg, true) || !isset($alle[$n])) {
                return;
            }
            foreach (array_keys($alle[$n]['abhaengigkeiten'] ?? []) as $d) {
                if (in_array((string) $d, $namen, true)) {
                    $setzen((string) $d);
                }
            }
            $erg[] = $n;
        };
        sort($namen);
        foreach ($namen as $n) {
            $setzen($n);
        }
        return $erg;
    }

    /**
     * Schaltet ein Modul aus (F142): Menü, Seiten und Schnittstelle sind weg, Daten, Rechte und Rollen bleiben.
     * Abgelehnt, solange ein aktives Modul derselben Datenbank darauf aufbaut.
     */
    public static function ausschalten(string $name, Connection $db, string $subjekt): void
    {
        $m = self::manifest($name);
        $s = self::ebene($m) === 'mandant' ? 'mandant' : 'kern';
        $aktiv = array_map('strval', $db->fetchFirstColumn("SELECT name FROM $s.modul WHERE aktiv"));
        foreach (self::alle() as $anderes => $am) {
            if ($anderes !== $name && in_array($anderes, $aktiv, true) && array_key_exists($name, $am['abhaengigkeiten'] ?? [])) {
                throw new \RuntimeException('modul.wird_gebraucht');
            }
        }
        if (!in_array($name, $aktiv, true)) {
            throw new \RuntimeException('modul.nicht_aktiv');
        }
        $db->executeStatement("SELECT $s.modul_ausschalten(?, ?)", [$subjekt, $name]);
    }

    /** @return array<string, mixed> */
    public static function manifest(string $name): array
    {
        if (!preg_match('/^[a-z][a-z0-9-]{0,31}$/', $name) || $name === 'kern') {
            throw new \InvalidArgumentException("Modulname ungültig: $name");
        }
        $datei = self::pfad($name) . '/modul.json';
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
        $ebene = $m['ebene'] ?? 'buch';
        if (!in_array($ebene, self::EBENEN, true)) {
            throw new \RuntimeException("Manifest von $name: ebene ist buch oder mandant");
        }
        if ($ebene === 'mandant' && ($name === 'mandant' || !empty($m['erweiterungspunkte']))) {
            throw new \RuntimeException("Manifest von $name: Ein Modul am Mandanten heißt nicht „mandant“ und hat keine Erweiterungspunkte");
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
        // Ein Modul am Mandanten steht über den Büchern und kann sich nicht auf ein Modul eines Buchs stützen
        foreach ($abh as $d => $am) {
            if ($ebene === 'mandant' && self::ebene($am) !== 'mandant') {
                throw new \RuntimeException("Manifest von $name: hängt als Modul am Mandanten von $d ab, das zu einem Buch gehört");
            }
        }
        Menue::pruefen($m, $abh, self::pfad($name) . '/oeffentlich');
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
     * Aktiviert ein Modul bzw. aktualisiert seine Registrierung – in der Datenbank eines Buchs oder, bei einem Modul
     * der Ebene `mandant`, in der Datenbank des Mandanten. Der Aufrufer nennt die Datenbank, die zur Ebene gehört.
     * Migrationen laufen über db/migrieren.sh (F73: nur Code des installierten, signierten Releases).
     *
     * @param list<string> $psqlOptionen z. B. ['-h', '/var/run/postgresql']
     * @return list<string> Meldungen
     */
    public static function aktivieren(string $name, string $datenbank, Connection $db, string $subjekt, array $psqlOptionen = []): array
    {
        $m = self::manifest($name);
        // Schema der Rechte: `kern` im Buch, `mandant` am Mandanten – gleiche Funktionen, gleiche Signaturen (F135)
        $s = self::ebene($m) === 'mandant' ? 'mandant' : 'kern';
        if ($db->fetchOne('SELECT to_regnamespace(?) IS NOT NULL AND to_regclass(?) IS NOT NULL', [$s, "$s.modul"]) !== true) {
            throw new \RuntimeException($s === 'mandant'
                ? "Modul $name gehört zum Mandanten und wird ohne Buch aktiviert (oder die Datenbank des Mandanten ist nicht auf dem Stand dieser Version)"
                : "Modul $name gehört zu einem Buch, nicht zum Mandanten");
        }
        $art = $s === 'kern' ? 'art' : "'normal'::text AS art";
        $meldungen = [];
        $wurzel = dirname(__DIR__, 2);
        $version = trim((string) file_get_contents("$wurzel/VERSION"));
        $stand = $db->fetchAssociative('SELECT release_sha256, freigabe_schluessel FROM meta.version ORDER BY installiert_am DESC LIMIT 1') ?: null;

        if (isset(self::$weitere[$name]) && is_dir(self::pfad($name) . '/migrationen')) {
            throw new \RuntimeException("Modul $name: ein Modul außerhalb von module/ kann keine Migrationen haben");
        }
        if (is_dir(self::pfad($name) . '/migrationen')) {
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

        $db->transactional(function (Connection $db) use ($m, $subjekt, $name, $s, $art, &$meldungen): void {
            $db->executeStatement("SELECT $s.modul_registrieren(?, ?, ?, ?::text[])",
                [$subjekt, $name, $m['version'], '{' . implode(',', $m['erweiterungspunkte'] ?? []) . '}']);
            foreach ($m['rechte'] ?? [] as $r) {
                // dritter Schalter: im Buch „berührt Buchungsdaten“, am Mandanten „berührt Personendaten“
                $db->executeStatement("SELECT $s.recht_anmelden(?, ?, ?, ?::boolean, ?::boolean, ?::boolean)",
                    [$subjekt, $r['code'], $r['beschreibung'], self::b($r[$s === 'kern' ? 'buchungsdaten' : 'personendaten'] ?? true), self::b($r['schreibend'] ?? true), self::b($r['notfall'] ?? false)]);
            }
            foreach ($m['bausteine'] ?? [] as $b) {
                $db->executeStatement("SELECT $s.baustein_setzen(?, ?, ?, ?::text[])",
                    [$subjekt, $b['name'], $b['beschreibung'] ?? null, '{' . implode(',', $b['rechte']) . '}']);
            }
            // Rollen, die das Modul mitbringt (F134): angelegt, wenn es sie im Buch noch nicht gibt – ohne Bausteine;
            // die bekommen sie wie jede Rolle über `rollen`. Eine vorhandene Rolle gleichen Namens bleibt, wie sie ist.
            foreach ($m['eigene_rollen'] ?? [] as $er) {
                if (!is_array($er) || !is_string($er['name'] ?? null) || !preg_match('/^[\p{L}][\p{L}\p{N} -]{1,39}$/u', $er['name'])) {
                    throw new \RuntimeException("Modul $name: eigene_rollen braucht je Rolle einen Namen aus Buchstaben, Ziffern, Leerzeichen und Bindestrich");
                }
                if ($db->fetchOne("SELECT 1 FROM $s.rolle WHERE name = ?", [$er['name']]) === false) {
                    $db->executeStatement("SELECT $s.rolle_setzen(?, ?, 'normal', ?, '[]'::jsonb)", [$subjekt, $er['name'], mb_substr((string) ($er['beschreibung'] ?? ''), 0, 200) ?: null]);
                    $meldungen[] = "Rolle {$er['name']} angelegt";
                }
            }
            // Bausteine, die eine Rolle aus einer früheren Fassung des Moduls nicht mehr haben soll
            foreach ($m['rollen_ohne'] ?? [] as $rolle => $weg) {
                $r = $db->fetchAssociative("SELECT id, $art, beschreibung FROM $s.rolle WHERE name = ?", [$rolle]);
                if ($r === false) {
                    continue;
                }
                $liste = $db->fetchAllAssociative(
                    "SELECT b.name AS baustein, rb.einschraenkung::text AS e FROM $s.rolle_baustein rb
                       JOIN $s.baustein b ON b.id = rb.baustein WHERE rb.rolle = ?", [$r['id']]);
                $bleibt = array_values(array_filter($liste, fn (array $z) => !in_array($z['baustein'], $weg, true)));
                if (count($bleibt) !== count($liste)) {
                    $db->executeStatement("SELECT $s.rolle_setzen(?, ?, ?, ?, ?::jsonb)", [$subjekt, $rolle, $r['art'], $r['beschreibung'],
                        json_encode(array_map(fn (array $z) => ['baustein' => $z['baustein'], 'einschraenkung' => $z['e'] === null ? null : json_decode($z['e'], true)], $bleibt), JSON_UNESCAPED_UNICODE)]);
                    $meldungen[] = "Rolle $rolle ohne: " . implode(', ', array_intersect($weg, array_column($liste, 'baustein')));
                }
            }
            foreach ($m['rollen'] ?? [] as $rolle => $bausteine) {
                $r = $db->fetchAssociative("SELECT id, $art, beschreibung FROM $s.rolle WHERE name = ?", [$rolle]);
                if ($r === false) {
                    $meldungen[] = "Rolle $rolle gibt es hier nicht – übergangen";
                    continue;
                }
                $liste = $db->fetchAllAssociative(
                    "SELECT b.name AS baustein, rb.einschraenkung::text AS e FROM $s.rolle_baustein rb
                       JOIN $s.baustein b ON b.id = rb.baustein WHERE rb.rolle = ?", [$r['id']]);
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
                    $db->executeStatement("SELECT $s.rolle_setzen(?, ?, ?, ?, ?::jsonb)",
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
