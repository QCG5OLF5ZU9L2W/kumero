<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Konsole;

use Doctrine\DBAL\Connection;
use Kumero\Plattform\Datenbank;
use Kumero\Plattform\Kernel;
use Kumero\Plattform\Konfiguration;
use Kumero\Plattform\Webauthn\Base64Url;
use Kumero\Plattform\Webauthn\Pruefer;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kommandozeile bin/kumero. Läuft als root; die Datenbank sieht root per Peer
 * als kumero_owner. Alle Änderungen gehen über die Funktionen von Kern und
 * Verwaltung und werden mit dem Subjekt system:cli protokolliert.
 */
final class Befehle
{
    public const SUBJEKT = 'system:cli';

    public static function anwendung(): Application
    {
        $app = new Application('kumero', trim((string) @file_get_contents(dirname(__DIR__, 2) . '/VERSION')) ?: 'unbekannt');
        foreach ([
            self::befehl('nutzer:anlegen', 'Nutzer anlegen und Registrierungslink für seinen Passkey ausgeben',
                fn (Command $c) => $c->addArgument('name', InputArgument::REQUIRED, 'Anzeigename')
                    ->addOption('verwalter', null, InputOption::VALUE_NONE, 'darf Nutzer, Mandanten und Zugänge pflegen')
                    ->addOption('sprache', null, InputOption::VALUE_REQUIRED, 'Sprache', 'de'),
                self::nutzerAnlegen(...)),
            self::befehl('nutzer:link', 'Neuen Registrierungslink für einen weiteren Passkey ausgeben',
                fn (Command $c) => $c->addArgument('nutzer', InputArgument::REQUIRED, 'Nutzer-ID'),
                self::nutzerLink(...)),
            self::befehl('nutzer:liste', 'Nutzer mit Passkeys und Zugängen', fn (Command $c) => $c, self::nutzerListe(...)),
            self::befehl('mandant:anlegen', 'Neuen Mandanten aus der Vorlage anlegen (F40)',
                fn (Command $c) => $c->addArgument('kennung', InputArgument::REQUIRED, 'Kennung für den Pfad, z. B. praxis')
                    ->addArgument('name', InputArgument::REQUIRED, 'Anzeigename')
                    ->addOption('waehrung', null, InputOption::VALUE_REQUIRED, 'Buchwährung (F11)', 'EUR'),
                self::mandantAnlegen(...)),
            self::befehl('mandant:eintragen', 'Bestehende Mandanten-Datenbank in der Verwaltung eintragen',
                fn (Command $c) => $c->addArgument('kennung', InputArgument::REQUIRED)
                    ->addArgument('datenbank', InputArgument::REQUIRED)->addArgument('name', InputArgument::REQUIRED),
                self::mandantEintragen(...)),
            self::befehl('mandant:liste', 'Mandanten', fn (Command $c) => $c, self::mandantListe(...)),
            self::befehl('zugang:gewaehren', 'Nutzer Zugang zu einem Mandanten geben und Rollen zuweisen (F41, F42)',
                fn (Command $c) => $c->addArgument('nutzer', InputArgument::REQUIRED, 'Nutzer-ID')
                    ->addArgument('mandant', InputArgument::REQUIRED, 'Kennung')
                    ->addOption('rolle', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Rolle(n)', ['Verantwortlicher']),
                self::zugangGewaehren(...)),
            self::befehl('selbsttest', 'Prüft PHP, Erweiterungen, Konfiguration, Datenbanken und WebAuthn auf diesem Server',
                fn (Command $c) => $c, self::selbsttest(...)),
        ] as $befehl) {
            $app->addCommand($befehl);
        }
        return $app;
    }

    private static function befehl(string $name, string $beschreibung, callable $konfigurieren, callable $ausfuehren): Command
    {
        $c = new Command($name);
        $c->setDescription($beschreibung);
        $konfigurieren($c);
        $c->setCode(function (InputInterface $e, OutputInterface $a) use ($ausfuehren): int {
            $konfig = Konfiguration::laden();
            $konfig = $konfig->mitDbNutzer(getenv('KUMERO_DB_NUTZER') ?: 'kumero_owner');
            return $ausfuehren($e, $a, $konfig, new Datenbank($konfig));
        });
        return $c;
    }

    private static function link(Konfiguration $k, string $token): string
    {
        // Das Token steht im Fragment: Es landet nicht in Server-Logs.
        return $k->adresse . '/start.html#registrierung=' . $token;
    }

    private static function nutzerAnlegen(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $v = $db->verwaltung();
        $token = Base64Url::kodieren(random_bytes(32));
        $id = $v->transactional(function (Connection $v) use ($e, $token): string {
            $id = (string) $v->fetchOne('SELECT verwaltung.nutzer_anlegen(?, ?, ?, ?::boolean)',
                [self::SUBJEKT, $e->getArgument('name'), $e->getOption('sprache'), $e->getOption('verwalter') ? 'true' : 'false']);
            $v->executeStatement('SELECT verwaltung.registrierung_ausstellen(?, ?::uuid, ?)', [self::SUBJEKT, $id, hash('sha256', $token)]);
            return $id;
        });
        $a->writeln("Nutzer angelegt: $id");
        $a->writeln('Registrierungslink (15 Minuten, einmal verwendbar):');
        $a->writeln('  ' . self::link($k, $token));
        return Command::SUCCESS;
    }

    private static function nutzerLink(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $token = Base64Url::kodieren(random_bytes(32));
        $db->verwaltung()->executeStatement('SELECT verwaltung.registrierung_ausstellen(?, ?::uuid, ?)',
            [self::SUBJEKT, $e->getArgument('nutzer'), hash('sha256', $token)]);
        $a->writeln('Registrierungslink (15 Minuten, einmal verwendbar):');
        $a->writeln('  ' . self::link($k, $token));
        return Command::SUCCESS;
    }

    private static function nutzerListe(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        foreach ($db->verwaltung()->fetchAllAssociative(
            "SELECT n.id, n.name, n.verwalter, n.aktiv,
                    (SELECT count(*) FROM verwaltung.anmelde_credential c WHERE c.nutzer = n.id AND c.widerrufen_am IS NULL) AS passkeys,
                    (SELECT string_agg(z.mandant, ', ' ORDER BY z.mandant) FROM verwaltung.zugang z WHERE z.subjekt = 'nutzer:' || n.id) AS mandanten
               FROM verwaltung.nutzer n ORDER BY n.name") as $n) {
            $a->writeln(sprintf('%s  %-24s %s%s  Passkeys: %d  Mandanten: %s', $n['id'], $n['name'],
                $n['aktiv'] ? 'aktiv' : 'inaktiv', $n['verwalter'] ? ', Verwalter' : '', $n['passkeys'], $n['mandanten'] ?? '–'));
        }
        return Command::SUCCESS;
    }

    private static function mandantAnlegen(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $kennung = (string) $e->getArgument('kennung');
        if (!preg_match('/^[a-z][a-z0-9-]{1,31}$/', $kennung) || in_array($kennung, ['verwaltung', 'vorlage'], true)) {
            $a->writeln('<error>Kennung: Kleinbuchstaben, Ziffern, Bindestrich; nicht „verwaltung“ oder „vorlage“</error>');
            return Command::INVALID;
        }
        $datenbank = 'kumero_' . str_replace('-', '_', $kennung);
        $v = $db->verwaltung();
        $vorlage = getenv('KUMERO_VORLAGE_DB') ?: 'kumero_vorlage';
        $v->executeStatement(sprintf('CREATE DATABASE %s TEMPLATE %s OWNER kumero_owner', $datenbank, $vorlage));
        try {
            $m = $db->verbindung($datenbank);
            $m->executeStatement(sprintf('REVOKE ALL ON DATABASE %s FROM PUBLIC', $datenbank));
            $m->executeStatement(sprintf('GRANT CONNECT ON DATABASE %s TO kumero_app, kumero_lesen', $datenbank));
            $m->transactional(function (Connection $m) use ($e): void {
                $m->executeStatement('SELECT kern.buch_anlegen(?, ?, ?)', [self::SUBJEKT, $e->getArgument('name'), $e->getOption('waehrung')]);
                $m->executeStatement('SELECT kern.rollenvorlagen_anlegen(?)', [self::SUBJEKT]);
            });
            $v->executeStatement('SELECT verwaltung.mandant_eintragen(?, ?, ?, ?)', [self::SUBJEKT, $kennung, $datenbank, $e->getArgument('name')]);
        } catch (\Throwable $f) {
            $db->schliessen();
            $db->verwaltung()->executeStatement("DROP DATABASE IF EXISTS $datenbank");
            throw $f;
        }
        $a->writeln("Mandant $kennung angelegt (Datenbank $datenbank, Testbetrieb, Rollenvorlagen F42).");
        $a->writeln("Zugang: bin/kumero zugang:gewaehren <nutzer-id> $kennung");
        return Command::SUCCESS;
    }

    private static function mandantEintragen(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $m = $db->verbindung((string) $e->getArgument('datenbank'));
        if ($m->fetchOne('SELECT count(*) FROM kern.buch') == 0) {
            $a->writeln('<error>Datenbank hat noch kein Buch – mandant:anlegen verwenden</error>');
            return Command::FAILURE;
        }
        if ($m->fetchOne('SELECT count(*) FROM kern.rolle WHERE vorlage') == 0) {
            $m->executeStatement('SELECT kern.rollenvorlagen_anlegen(?)', [self::SUBJEKT]);
            $a->writeln('Rollenvorlagen angelegt (F42).');
        }
        $db->verwaltung()->executeStatement('SELECT verwaltung.mandant_eintragen(?, ?, ?, ?)',
            [self::SUBJEKT, $e->getArgument('kennung'), $e->getArgument('datenbank'), $e->getArgument('name')]);
        $a->writeln('Mandant eingetragen.');
        return Command::SUCCESS;
    }

    private static function mandantListe(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        foreach ($db->verwaltung()->fetchAllAssociative('SELECT kennung, datenbank, name, stillgelegt_am FROM verwaltung.mandant ORDER BY kennung') as $m) {
            $a->writeln(sprintf('%-16s %-24s %s%s', $m['kennung'], $m['datenbank'], $m['name'], $m['stillgelegt_am'] ? ' (stillgelegt)' : ''));
        }
        return Command::SUCCESS;
    }

    private static function zugangGewaehren(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $nutzer = (string) $e->getArgument('nutzer');
        $kennung = (string) $e->getArgument('mandant');
        $v = $db->verwaltung();
        $datenbank = $v->fetchOne('SELECT datenbank FROM verwaltung.mandant WHERE kennung = ?', [$kennung]);
        if (!is_string($datenbank)) {
            $a->writeln("<error>Mandant $kennung unbekannt</error>");
            return Command::FAILURE;
        }
        $subjekt = 'nutzer:' . $nutzer;
        if (!$v->fetchOne('SELECT 1 FROM verwaltung.zugang WHERE subjekt = ? AND mandant = ?', [$subjekt, $kennung])) {
            $v->executeStatement('SELECT verwaltung.zugang_gewaehren(?, ?, ?)', [self::SUBJEKT, $subjekt, $kennung]);
        }
        $m = $db->verbindung($datenbank);
        $m->transactional(function (Connection $m) use ($e, $subjekt): void {
            foreach ((array) $e->getOption('rolle') as $rolle) {
                $m->executeStatement('SELECT kern.zuweisen(?, ?, ?)', [self::SUBJEKT, $subjekt, $rolle]);
            }
        });
        $a->writeln("Zugang zu $kennung mit Rolle(n): " . implode(', ', (array) $e->getOption('rolle')));
        return Command::SUCCESS;
    }

    private static function selbsttest(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $fehler = 0;
        $pruefe = function (string $was, callable $f) use ($a, &$fehler): void {
            try {
                $erg = $f();
                $a->writeln("  ok      $was" . (is_string($erg) ? " ($erg)" : ''));
            } catch (\Throwable $x) {
                $fehler++;
                $a->writeln("  FEHLER  $was: " . $x->getMessage());
            }
        };
        $a->writeln('kumero selbsttest');
        $pruefe('PHP ≥ 8.5 (F54)', fn () => PHP_VERSION_ID >= 80500 ? PHP_VERSION : throw new \RuntimeException(PHP_VERSION));
        foreach (['pdo_pgsql', 'openssl', 'sodium', 'mbstring', 'json'] as $ext) {
            $pruefe("Erweiterung $ext", fn () => extension_loaded($ext) ?: throw new \RuntimeException('fehlt'));
        }
        $pruefe('Konfiguration', fn () => "{$k->adresse}, RP-ID {$k->rpId}");
        $pruefe('Verwaltungsdatenbank', fn () => 'Version ' . $db->verwaltung()->fetchOne('SELECT version FROM meta.version ORDER BY installiert_am DESC LIMIT 1'));
        $pruefe('Protokollkette der Verwaltung', fn () => $db->verwaltung()->fetchOne('SELECT count(*) FROM verwaltung.kette_pruefen()') == 0 ? 'intakt' : throw new \RuntimeException('Abweichung'));
        foreach ($db->verwaltung()->fetchAllAssociative('SELECT kennung, datenbank FROM verwaltung.mandant WHERE stillgelegt_am IS NULL ORDER BY kennung') as $m) {
            $pruefe("Mandant {$m['kennung']}: Ketten", fn () => $db->verbindung($m['datenbank'])->fetchOne('SELECT count(*) FROM kern.kette_pruefen()') == 0
                ? 'intakt' : throw new \RuntimeException('Abweichung'));
        }
        $pruefe('WebAuthn ES256 mit OpenSSL', function (): string {
            $p = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            $d = openssl_pkey_get_details($p)['ec'];
            $cose = "\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . "\x22\x58\x20" . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
            openssl_sign('kumero', $sig, $p, OPENSSL_ALGO_SHA256);
            return Pruefer::signaturGueltig($cose, 'kumero', $sig) && !Pruefer::signaturGueltig($cose, 'anders', $sig) ? 'geprüft' : throw new \RuntimeException('Signaturprüfung falsch');
        });
        $pruefe('WebAuthn EdDSA mit libsodium', function (): string {
            $paar = sodium_crypto_sign_keypair();
            $cose = "\xa4\x01\x01\x03\x27\x20\x06\x21\x58\x20" . sodium_crypto_sign_publickey($paar);
            $sig = sodium_crypto_sign_detached('kumero', sodium_crypto_sign_secretkey($paar));
            return Pruefer::signaturGueltig($cose, 'kumero', $sig) && !Pruefer::signaturGueltig($cose, 'anders', $sig) ? 'geprüft' : throw new \RuntimeException('Signaturprüfung falsch');
        });
        $pruefe('Anwendung antwortet (GET /api/v1/ich ohne Anmeldung → 401)', function () use ($k): string {
            $antwort = (new Kernel($k, new Datenbank($k)))->bearbeiten(Request::create('/api/v1/ich'));
            $j = json_decode((string) $antwort->getContent(), true);
            return $antwort->getStatusCode() === 401 && ($j['code'] ?? '') === 'anmeldung.erforderlich' ? 'ok' : throw new \RuntimeException('Antwort ' . $antwort->getStatusCode());
        });
        $a->writeln($fehler === 0 ? 'Alles in Ordnung.' : "$fehler Prüfung(en) fehlgeschlagen.");
        return $fehler === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
