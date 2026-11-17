<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests\Hilfe;

use Kumero\Konsole\Befehle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Testumgebung gegen eine echte PostgreSQL: Verwaltungsdatenbank, Vorlage für
 * Mandanten, Konfigurationsdatei, Vorlagenverzeichnis. Alle Datenbanken heißen
 * kumero_test*, damit migrieren.sh ohne Freigabe-Signatur arbeitet (F73).
 * Voraussetzung: KUMERO_TEST_PGHOST, Superuser KUMERO_TEST_PGUSER.
 */
final class Umgebung
{
    public const ORIGIN = 'https://kumero.test';
    public const V = 'kumero_test_php_v';
    public const VORLAGE = 'kumero_test_php_vorlage';

    public readonly string $host;
    public readonly string $su;
    public string $konfigDatei = '';
    public string $vorlagenVerzeichnis = '';

    /** @param list<string> $mandanten Datenbanknamen, die der Test anlegt (zum Aufräumen) */
    public function __construct(private readonly array $mandanten)
    {
        $this->host = getenv('KUMERO_TEST_PGHOST') ?: throw new \RuntimeException('KUMERO_TEST_PGHOST fehlt');
        $this->su = getenv('KUMERO_TEST_PGUSER') ?: 'postgres';
    }

    public function aufbauen(): void
    {
        $this->abbauen();
        foreach ([self::V, self::VORLAGE] as $db) {
            $this->psql('postgres', "CREATE DATABASE $db OWNER kumero_owner TEMPLATE template0");
            $this->psql($db, "REVOKE ALL ON DATABASE $db FROM PUBLIC; GRANT CONNECT ON DATABASE $db TO kumero_app, kumero_lesen; REVOKE CREATE ON SCHEMA public FROM PUBLIC");
        }
        $mig = dirname(__DIR__, 2) . '/db/migrieren.sh';
        $env = sprintf('KUMERO_TEST=1 KUMERO_DB_USER=%s KUMERO_FREIGABE=test KUMERO_RELEASE_SHA256=%s KUMERO_FREIGABE_SCHLUESSEL=TEST ',
            escapeshellarg($this->su), str_repeat('0', 64));
        self::sh($env . "bash $mig --verwaltung " . self::V . ' -h ' . escapeshellarg($this->host));
        self::sh($env . "bash $mig " . self::VORLAGE . ' -h ' . escapeshellarg($this->host));
        $this->psql('postgres', 'ALTER DATABASE ' . self::VORLAGE . ' IS_TEMPLATE true');

        $this->vorlagenVerzeichnis = sys_get_temp_dir() . '/kumero-vorlagen-' . bin2hex(random_bytes(4));
        $this->konfigDatei = tempnam(sys_get_temp_dir(), 'kumero') . '.php';
        file_put_contents($this->konfigDatei, '<?php return ' . var_export([
            'adresse' => self::ORIGIN, 'verwaltung_db' => self::V, 'db_host' => $this->host, 'db_nutzer' => 'kumero_app',
            'kontenrahmen_verzeichnis' => $this->vorlagenVerzeichnis,
        ], true) . ';');
        putenv('KUMERO_KONFIG=' . $this->konfigDatei);
        putenv('KUMERO_VORLAGE_DB=' . self::VORLAGE);
        putenv('KUMERO_DB_NUTZER=kumero_owner');
    }

    public function abbauen(): void
    {
        $this->psql('postgres', "UPDATE pg_database SET datistemplate = false WHERE datname = '" . self::VORLAGE . "'");
        foreach (array_merge($this->mandanten, [self::V, self::VORLAGE]) as $db) {
            $this->psql('postgres', "DROP DATABASE IF EXISTS $db");
        }
        if ($this->konfigDatei !== '') {
            @unlink($this->konfigDatei);
        }
        if ($this->vorlagenVerzeichnis !== '' && is_dir($this->vorlagenVerzeichnis)) {
            array_map('unlink', glob($this->vorlagenVerzeichnis . '/entwuerfe/*') ?: []);
            @rmdir($this->vorlagenVerzeichnis . '/entwuerfe');
            array_map('unlink', glob($this->vorlagenVerzeichnis . '/*') ?: []);
            @rmdir($this->vorlagenVerzeichnis);
        }
    }

    public function psql(string $db, string $sql): string
    {
        return self::sh(sprintf('psql -X -q -v ON_ERROR_STOP=1 -h %s -U %s -d %s -c %s',
            escapeshellarg($this->host), escapeshellarg($this->su), $db, escapeshellarg($sql)));
    }

    /** Führt einen Befehl von bin/kumero aus; wirft bei Rückgabe ≠ 0. */
    public function cli(array $eingabe, bool $fehlerErwartet = false): string
    {
        $app = Befehle::anwendung();
        $app->setAutoExit(false);
        $app->setCatchExceptions(true);
        $aus = new BufferedOutput();
        $rc = $app->run(new ArrayInput($eingabe), $aus);
        $text = $aus->fetch();
        if (($rc !== 0) !== $fehlerErwartet) {
            throw new \RuntimeException('CLI ' . ($fehlerErwartet ? 'lief unerwartet durch' : 'fehlgeschlagen') . ': ' . json_encode($eingabe, JSON_UNESCAPED_UNICODE) . "\n" . $text);
        }
        return $text;
    }

    public static function sh(string $befehl): string
    {
        exec($befehl . ' 2>&1', $aus, $rc);
        if ($rc !== 0) {
            throw new \RuntimeException("Befehl fehlgeschlagen: $befehl\n" . implode("\n", $aus));
        }
        return implode("\n", $aus);
    }
}
