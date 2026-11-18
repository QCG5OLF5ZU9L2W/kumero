<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Konsole;

use Doctrine\DBAL\Connection;
use Kumero\Plattform\Datenbank;
use Kumero\Modul\Kontenrahmen\Ablage;
use Kumero\Modul\Kontenrahmen\Anwenden;
use Kumero\Modul\Kontenrahmen\CsvLeser;
use Kumero\Modul\Kontenrahmen\Einlesen;
use Kumero\Modul\Kontenrahmen\Regelwerk;
use Kumero\Plattform\Kernel;
use Kumero\Plattform\Module;
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
 *
 * Nutzer gehören zum Mandanten (F114): nutzer:* arbeitet in der Datenbank eines Mandanten, verwalter:* an den
 * System-Verwaltern der Installation. Die Zuordnung Pseudonym → Mandant trägt die Kommandozeile mit ein.
 */
final class Befehle
{
    public const SUBJEKT = 'system:cli';

    public static function anwendung(): Application
    {
        $app = new Application('kumero', trim((string) @file_get_contents(dirname(__DIR__, 2) . '/VERSION')) ?: 'unbekannt');
        foreach ([
            self::befehl('mandant:anlegen', 'Neuen Mandanten aus der Vorlage anlegen und seinen ersten Administrator einsetzen (F40, F114 U5)',
                fn (Command $c) => $c->addArgument('kennung', InputArgument::REQUIRED, 'Kennung für den Pfad, z. B. praxis')
                    ->addArgument('name', InputArgument::REQUIRED, 'Anzeigename')
                    ->addArgument('vorname', InputArgument::REQUIRED, 'Vorname(n) des ersten Administrators')
                    ->addArgument('familienname', InputArgument::REQUIRED, 'Familienname des ersten Administrators')
                    ->addArgument('email', InputArgument::REQUIRED, 'E-Mail-Adresse des ersten Administrators')
                    ->addOption('administrator', null, InputOption::VALUE_NONE, 'leitet die Angaben zum ersten Administrator ein: --administrator <Vorname> <Familienname> <E-Mail>')
                    ->addOption('waehrung', null, InputOption::VALUE_REQUIRED, 'Buchwährung (F11)', 'EUR'),
                self::mandantAnlegen(...)),
            self::befehl('mandant:eintragen', 'Bestehende Mandanten-Datenbank in der Verwaltung eintragen',
                fn (Command $c) => $c->addArgument('kennung', InputArgument::REQUIRED)
                    ->addArgument('datenbank', InputArgument::REQUIRED)->addArgument('name', InputArgument::REQUIRED),
                self::mandantEintragen(...)),
            self::befehl('mandant:liste', 'Mandanten', fn (Command $c) => $c, self::mandantListe(...)),
            self::befehl('nutzer:anlegen', 'Nutzer in einem Mandanten anlegen, Rollen zuweisen und Registrierungslink für seinen Passkey ausgeben (F114)',
                fn (Command $c) => $c->addArgument('mandant', InputArgument::REQUIRED, 'Kennung des Mandanten')
                    ->addArgument('vorname', InputArgument::REQUIRED, 'Vorname(n)')
                    ->addArgument('familienname', InputArgument::REQUIRED, 'Familienname')
                    ->addArgument('email', InputArgument::REQUIRED, 'E-Mail-Adresse')
                    ->addOption('rolle', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Rolle(n), z. B. Verantwortlicher, Buchhalter, Administrator (F42)', [])
                    ->addOption('sprache', null, InputOption::VALUE_REQUIRED, 'Sprache, z. B. de; ohne Angabe gilt die Sprache des Browsers'),
                self::nutzerAnlegen(...)),
            self::befehl('nutzer:rolle', 'Einem Nutzer eines Mandanten weitere Rollen zuweisen (F41, F42)',
                fn (Command $c) => $c->addArgument('mandant', InputArgument::REQUIRED, 'Kennung des Mandanten')
                    ->addArgument('nutzer', InputArgument::REQUIRED, 'Nutzer-ID')
                    ->addOption('rolle', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Rolle(n)', []),
                self::nutzerRolle(...)),
            self::befehl('nutzer:link', 'Neuen Registrierungslink für einen (weiteren) Passkey eines Nutzers ausgeben',
                fn (Command $c) => $c->addArgument('mandant', InputArgument::REQUIRED, 'Kennung des Mandanten')
                    ->addArgument('nutzer', InputArgument::REQUIRED, 'Nutzer-ID'),
                self::nutzerLink(...)),
            self::befehl('nutzer:liste', 'Nutzer je Mandant mit Passkeys und Rollen',
                fn (Command $c) => $c->addArgument('mandant', InputArgument::OPTIONAL, 'Kennung; ohne Angabe alle Mandanten'),
                self::nutzerListe(...)),
            self::befehl('verwalter:anlegen', 'System-Verwalter anlegen und Registrierungslink für seinen Passkey ausgeben (F114 U4)',
                fn (Command $c) => $c->addArgument('name', InputArgument::REQUIRED, 'Anzeigename')
                    ->addOption('sprache', null, InputOption::VALUE_REQUIRED, 'Sprache, z. B. de; ohne Angabe gilt die Sprache des Browsers'),
                self::verwalterAnlegen(...)),
            self::befehl('verwalter:link', 'Neuen Registrierungslink für einen System-Verwalter ausgeben – der Notausgang, falls niemand mehr hineinkommt',
                fn (Command $c) => $c->addArgument('verwalter', InputArgument::REQUIRED, 'ID des Verwalters (verwalter:liste)'),
                self::verwalterLink(...)),
            self::befehl('verwalter:liste', 'System-Verwalter mit Passkeys', fn (Command $c) => $c, self::verwalterListe(...)),
            self::befehl('system:nutzer-ueberfuehren', 'Überführt Nutzer aus der Verwaltungsdatenbank in ihre Mandanten (F114 U6); wiederholbar',
                fn (Command $c) => $c, self::nutzerUeberfuehren(...)),
            self::befehl('modul:liste', 'Mitgelieferte Module und ihr Stand je Mandant', fn (Command $c) => $c, self::modulListe(...)),
            self::befehl('modul:aktivieren', 'Modul in einem Mandanten aktivieren bzw. aktualisieren (K6)',
                fn (Command $c) => $c->addArgument('modul', InputArgument::REQUIRED)->addArgument('mandant', InputArgument::REQUIRED, 'Kennung'),
                self::modulAktivieren(...)),
            self::befehl('kontenrahmen:einlesen', 'Kontenrahmen aus PDF oder CSV einlesen → Vorlage und Prüfbericht (KONTENRAHMEN.md)',
                fn (Command $c) => $c->addArgument('datei', InputArgument::REQUIRED, 'PDF oder CSV')
                    ->addOption('art', null, InputOption::VALUE_REQUIRED, 'pdf oder csv (sonst nach Endung)')
                    ->addOption('rahmen', null, InputOption::VALUE_REQUIRED, 'Kontenrahmen', 'SKR81')
                    ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Name der Vorlage')
                    ->addOption('ergaenzung', null, InputOption::VALUE_REQUIRED, 'CSV mit Ergänzungen: Nummer;Bezeichnung;Kontoart (- = weglassen)')
                    ->addOption('probe', null, InputOption::VALUE_NONE, 'nur Prüfbericht, keine Vorlage speichern'),
                self::kontenrahmenEinlesen(...)),
            self::befehl('kontenrahmen:vorlagen', 'Gespeicherte Vorlagen', fn (Command $c) => $c, self::kontenrahmenVorlagen(...)),
            self::befehl('kontenrahmen:zeigen', 'Konten einer Vorlage anzeigen',
                fn (Command $c) => $c->addArgument('vorlage', InputArgument::REQUIRED, 'Kennung, Datei oder Anfang des SHA-256')
                    ->addOption('offen', null, InputOption::VALUE_NONE, 'nur Konten mit offenen Punkten')
                    ->addOption('pruefen', null, InputOption::VALUE_NONE, 'nur Konten, deren Kontoart zu bestätigen ist'),
                self::kontenrahmenZeigen(...)),
            self::befehl('kontenrahmen:anwenden', 'Vorlage auf einen Mandanten anwenden: Konten anlegen (F2, F74)',
                fn (Command $c) => $c->addArgument('vorlage', InputArgument::REQUIRED)->addArgument('mandant', InputArgument::REQUIRED)
                    ->addOption('bezeichnungen', null, InputOption::VALUE_NONE, 'Bezeichnungen vorhandener Konten übernehmen'),
                self::kontenrahmenAnwenden(...)),
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

    /** Token eines Links: nennt den Mandanten, damit die Seite ohne Anmeldung weiß, wohin (VERWALTUNG.md Abschnitt 11). */
    private static function token(?string $mandant): string
    {
        return ($mandant === null ? '' : $mandant . '.') . Base64Url::kodieren(random_bytes(32));
    }

    private static function link(Konfiguration $k, string $token): string
    {
        // Das Token steht im Fragment: Es landet nicht in Server-Logs.
        return $k->adresse . '/app.html#registrierung=' . $token;
    }

    private static function linkAusgeben(OutputInterface $a, Konfiguration $k, string $token): void
    {
        $a->writeln('Registrierungslink (15 Minuten, einmal verwendbar):');
        $a->writeln('  ' . self::link($k, $token));
    }

    /** Zufälliges Pseudonym (32 Byte) – die Nutzerkennung im Passkey (VERWALTUNG.md Abschnitt 3). */
    private static function pseudonym(): string
    {
        return Base64Url::kodieren(random_bytes(32));
    }

    /**
     * Legt im Mandanten eine Identität mit Rollen an und stellt ihr einen Link aus – in der laufenden
     * Transaktion des Mandanten. Das Pseudonym muss der Aufrufer in der Verwaltung eintragen.
     *
     * @param list<string> $rollen
     * @return array{0: string, 1: string} Nutzer-ID und Token
     */
    private static function identitaetAnlegen(Connection $m, string $kennung, string $pseudonym, string $vorname, string $familienname,
                                              string $email, ?string $sprache, array $rollen): array
    {
        $id = (string) $m->fetchOne('SELECT kern.nutzer_anlegen(?, ?, ?, ?, ?, ?)', [self::SUBJEKT, $familienname, $vorname, $email, $sprache, $pseudonym]);
        foreach ($rollen as $rolle) {
            $m->executeStatement('SELECT kern.zuweisen(?, ?, ?)', [self::SUBJEKT, 'nutzer:' . $id, $rolle]);
        }
        $token = self::token($kennung);
        $m->executeStatement('SELECT kern.registrierung_ausstellen(?, ?::uuid, ?)', [self::SUBJEKT, $id, hash('sha256', $token)]);
        return [$id, $token];
    }

    private static function nutzerAnlegen(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $kennung = (string) $e->getArgument('mandant');
        [, $m] = self::mandantDb($db, $kennung);
        $rollen = array_values((array) $e->getOption('rolle'));
        $pseudonym = self::pseudonym();
        $v = $db->verwaltung();
        // Erst die Zuordnung, dann die Identität: Eine Zuordnung ohne Identität führt nirgends hin.
        $v->executeStatement('SELECT verwaltung.pseudonym_eintragen(?, ?)', [$pseudonym, $kennung]);
        try {
            [$id, $token] = $m->transactional(fn (Connection $m) => self::identitaetAnlegen($m, $kennung, $pseudonym,
                (string) $e->getArgument('vorname'), (string) $e->getArgument('familienname'), (string) $e->getArgument('email'),
                $e->getOption('sprache'), $rollen));
        } catch (\Throwable $f) {
            $v->executeStatement('DELETE FROM verwaltung.pseudonym WHERE pseudonym = ?', [$pseudonym]);
            throw $f;
        }
        $a->writeln("Nutzer angelegt: $id");
        $a->writeln("Mandant $kennung, " . ($rollen === [] ? 'noch ohne Rolle (bin/kumero nutzer:rolle)' : 'Rolle(n): ' . implode(', ', $rollen)));
        self::linkAusgeben($a, $k, $token);
        return Command::SUCCESS;
    }

    private static function nutzerRolle(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        [, $m] = self::mandantDb($db, (string) $e->getArgument('mandant'));
        $nutzer = (string) $e->getArgument('nutzer');
        $rollen = array_values((array) $e->getOption('rolle'));
        if ($rollen === [] || !$m->fetchOne('SELECT 1 FROM kern.nutzer WHERE id::text = ?', [$nutzer])) {
            $a->writeln($rollen === [] ? '<error>Mindestens eine --rolle angeben</error>' : '<error>Nutzer in diesem Mandanten unbekannt</error>');
            return Command::FAILURE;
        }
        $m->transactional(function (Connection $m) use ($nutzer, $rollen): void {
            foreach ($rollen as $rolle) {
                $m->executeStatement('SELECT kern.zuweisen(?, ?, ?)', [self::SUBJEKT, 'nutzer:' . $nutzer, $rolle]);
            }
        });
        $a->writeln('Rolle(n) zugewiesen: ' . implode(', ', $rollen));
        return Command::SUCCESS;
    }

    private static function nutzerLink(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $kennung = (string) $e->getArgument('mandant');
        [, $m] = self::mandantDb($db, $kennung);
        $token = self::token($kennung);
        $m->executeStatement('SELECT kern.registrierung_ausstellen(?, ?::uuid, ?)', [self::SUBJEKT, $e->getArgument('nutzer'), hash('sha256', $token)]);
        self::linkAusgeben($a, $k, $token);
        return Command::SUCCESS;
    }

    private static function nutzerListe(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $nur = $e->getArgument('mandant');
        foreach ($db->verwaltung()->fetchAllAssociative('SELECT kennung, datenbank, stillgelegt_am FROM verwaltung.mandant ORDER BY kennung') as $md) {
            if ($nur !== null && $nur !== $md['kennung']) {
                continue;
            }
            $a->writeln($md['kennung'] . ($md['stillgelegt_am'] ? ' (stillgelegt)' : ''));
            foreach ($db->verbindung($md['datenbank'])->fetchAllAssociative(
                "SELECT n.id, trim(coalesce(n.vornamen, '') || ' ' || n.familienname) AS name, coalesce(n.email, '–') AS email, n.aktiv,
                        (SELECT count(*) FROM kern.anmelde_credential c WHERE c.nutzer = n.id AND c.widerrufen_am IS NULL) AS passkeys,
                        (SELECT string_agg(DISTINCT r.name, ', ' ORDER BY r.name) FROM kern.zuweisung z JOIN kern.rolle r ON r.id = z.rolle
                          WHERE z.subjekt = 'nutzer:' || n.id AND z.gueltig_von <= now() AND (z.gueltig_bis IS NULL OR z.gueltig_bis > now())) AS rollen
                   FROM kern.nutzer n ORDER BY n.familienname, n.vornamen") as $n) {
                $a->writeln(sprintf('  %s  %-28s %-28s %s  Passkeys: %d  Rollen: %s', $n['id'], $n['name'], $n['email'],
                    $n['aktiv'] ? 'aktiv' : 'gesperrt', $n['passkeys'], $n['rollen'] ?? '–'));
            }
        }
        return Command::SUCCESS;
    }

    private static function verwalterAnlegen(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $token = self::token(null);
        $id = $db->verwaltung()->transactional(function (Connection $v) use ($e, $token): string {
            $id = (string) $v->fetchOne('SELECT verwaltung.verwalter_anlegen(?, ?, ?, ?)',
                [self::SUBJEKT, $e->getArgument('name'), $e->getOption('sprache'), self::pseudonym()]);
            $v->executeStatement('SELECT verwaltung.registrierung_ausstellen(?, ?::uuid, ?)', [self::SUBJEKT, $id, hash('sha256', $token)]);
            return $id;
        });
        $a->writeln("Verwalter angelegt: $id");
        self::linkAusgeben($a, $k, $token);
        return Command::SUCCESS;
    }

    private static function verwalterLink(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $v = $db->verwaltung();
        $id = (string) $e->getArgument('verwalter');
        if (!$v->fetchOne('SELECT 1 FROM verwaltung.nutzer WHERE id::text = ? AND verwalter AND aktiv AND pseudonym IS NOT NULL', [$id])) {
            $a->writeln('<error>Kein aktiver System-Verwalter mit dieser ID (bin/kumero verwalter:liste; nach einem Update zuerst system:nutzer-ueberfuehren)</error>');
            return Command::FAILURE;
        }
        $token = self::token(null);
        $v->executeStatement('SELECT verwaltung.registrierung_ausstellen(?, ?::uuid, ?)', [self::SUBJEKT, $id, hash('sha256', $token)]);
        self::linkAusgeben($a, $k, $token);
        return Command::SUCCESS;
    }

    private static function verwalterListe(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        foreach ($db->verwaltung()->fetchAllAssociative(
            'SELECT n.id, n.name, n.aktiv, n.pseudonym IS NOT NULL AS bereit,
                    (SELECT count(*) FROM verwaltung.anmelde_credential c WHERE c.nutzer = n.id AND c.widerrufen_am IS NULL) AS passkeys
               FROM verwaltung.nutzer n WHERE n.verwalter ORDER BY n.name') as $n) {
            $a->writeln(sprintf('%s  %-28s %s  Passkeys: %d%s', $n['id'], $n['name'], $n['aktiv'] ? 'aktiv' : 'gesperrt', $n['passkeys'],
                $n['bereit'] ? '' : '  (noch nicht überführt: bin/kumero system:nutzer-ueberfuehren)'));
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
        if ($v->fetchOne('SELECT 1 FROM verwaltung.mandant WHERE kennung = ? OR datenbank = ?', [$kennung, $datenbank])) {
            $a->writeln("<error>Mandant $kennung gibt es schon</error>");
            return Command::FAILURE;
        }
        $vorlage = getenv('KUMERO_VORLAGE_DB') ?: 'kumero_vorlage';
        $v->executeStatement(sprintf('CREATE DATABASE %s TEMPLATE %s OWNER kumero_owner', $datenbank, $vorlage));
        try {
            $m = $db->verbindung($datenbank);
            $m->executeStatement(sprintf('REVOKE ALL ON DATABASE %s FROM PUBLIC', $datenbank));
            $m->executeStatement(sprintf('GRANT CONNECT ON DATABASE %s TO kumero_app, kumero_lesen', $datenbank));
            // Mandant anlegen und ersten Administrator einsetzen sind ein Vorgang (U5): Buch, Rollenvorlagen und
            // Identität in einer Transaktion des Mandanten, danach Mandant und Pseudonym in einer der Verwaltung.
            $pseudonym = self::pseudonym();
            [$id, $token] = $m->transactional(function (Connection $m) use ($e, $kennung, $pseudonym): array {
                $m->executeStatement('SELECT kern.buch_anlegen(?, ?, ?)', [self::SUBJEKT, $e->getArgument('name'), $e->getOption('waehrung')]);
                $m->executeStatement('SELECT kern.rollenvorlagen_anlegen(?)', [self::SUBJEKT]);
                return self::identitaetAnlegen($m, $kennung, $pseudonym, (string) $e->getArgument('vorname'),
                    (string) $e->getArgument('familienname'), (string) $e->getArgument('email'), null, ['Administrator']);
            });
            $v->transactional(function (Connection $v) use ($e, $kennung, $datenbank, $pseudonym): void {
                $v->executeStatement('SELECT verwaltung.mandant_eintragen(?, ?, ?, ?)', [self::SUBJEKT, $kennung, $datenbank, $e->getArgument('name')]);
                $v->executeStatement('SELECT verwaltung.pseudonym_eintragen(?, ?)', [$pseudonym, $kennung]);
                $v->executeStatement('SELECT verwaltung.administrator_vermerken(?, ?)', [self::SUBJEKT, $kennung]);
            });
        } catch (\Throwable $f) {
            $db->schliessen();
            $db->verwaltung()->executeStatement("DROP DATABASE IF EXISTS $datenbank");
            throw $f;
        }
        $a->writeln("Mandant $kennung angelegt (Datenbank $datenbank, Testbetrieb, Rollenvorlagen F42).");
        $a->writeln("Administrator eingesetzt: $id");
        self::linkAusgeben($a, $k, $token);
        $a->writeln("Weitere Nutzer: bin/kumero nutzer:anlegen $kennung <Vorname> <Familienname> <E-Mail> --rolle Verantwortlicher");
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
        $kennung = (string) $e->getArgument('kennung');
        $v = $db->verwaltung();
        $pseudonyme = $m->fetchFirstColumn('SELECT pseudonym FROM kern.nutzer ORDER BY angelegt_am');
        // Die Nutzer liegen in der Datenbank des Mandanten (F114): ihre Pseudonyme gehören in die Zuordnung der Verwaltung
        $v->transactional(function (Connection $v) use ($e, $kennung, $pseudonyme): void {
            $v->executeStatement('SELECT verwaltung.mandant_eintragen(?, ?, ?, ?)', [self::SUBJEKT, $kennung, $e->getArgument('datenbank'), $e->getArgument('name')]);
            foreach ($pseudonyme as $p) {
                $v->executeStatement('SELECT verwaltung.pseudonym_eintragen(?, ?)', [$p, $kennung]);
            }
        });
        $a->writeln('Mandant eingetragen, ' . count($pseudonyme) . ' Nutzer zugeordnet.');
        if ($pseudonyme === []) {
            $a->writeln("Noch ohne Nutzer: bin/kumero nutzer:anlegen $kennung <Vorname> <Familienname> <E-Mail> --rolle Administrator");
        }
        return Command::SUCCESS;
    }

    private static function mandantListe(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        foreach ($db->verwaltung()->fetchAllAssociative('SELECT kennung, datenbank, name, stillgelegt_am FROM verwaltung.mandant ORDER BY kennung') as $m) {
            $a->writeln(sprintf('%-16s %-24s %s%s', $m['kennung'], $m['datenbank'], $m['name'], $m['stillgelegt_am'] ? ' (stillgelegt)' : ''));
        }
        return Command::SUCCESS;
    }

    /**
     * Überführt den Bestand (F114 U6; VERWALTUNG.md Abschnitt 11): Jeder bisherige Nutzer wird in jedem Mandanten,
     * zu dem er Zugang hat, eine Identität mit derselben ID. Seine Passkeys tragen die bisherige Nutzer-ID als
     * Kennung; sie wird als Pseudonym für genau einen Mandanten eingetragen (Testmandant, sonst der zuerst
     * gewährte) – dort gelten die Passkeys weiter. System-Verwalter bleiben in der Verwaltung.
     * Zwei Datenbanken, deshalb keine SQL-Migration. Der Befehl ist wiederholbar: Was überführt ist, bleibt.
     */
    private static function nutzerUeberfuehren(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $v = $db->verwaltung();
        $fehler = 0;
        $anzahl = 0;
        $testbetrieb = [];
        foreach ($v->fetchAllAssociative('SELECT kennung, datenbank FROM verwaltung.mandant') as $md) {
            try {
                $testbetrieb[$md['kennung']] = $db->verbindung($md['datenbank'])->fetchOne('SELECT betriebsart::text FROM kern.buch') === 'test';
            } catch (\Throwable) {
                $testbetrieb[$md['kennung']] = false;
            }
        }
        foreach ($v->fetchAllAssociative('SELECT id, name, sprache, verwalter, aktiv, pseudonym FROM verwaltung.nutzer ORDER BY angelegt_am, id') as $n) {
            $id = (string) $n['id'];
            $subjekt = 'nutzer:' . $id;
            // So steht die bisherige Nutzer-ID als Kennung in den vorhandenen Passkeys (16 Byte, base64url)
            $alt = Base64Url::kodieren((string) hex2bin(str_replace('-', '', $id)));
            $zugaenge = $v->fetchAllAssociative(
                'SELECT z.mandant, m.datenbank FROM verwaltung.zugang z JOIN verwaltung.mandant m ON m.kennung = z.mandant
                  WHERE z.subjekt = ? ORDER BY z.gewaehrt_am, z.mandant', [$subjekt]);
            $ziel = $v->fetchOne('SELECT mandant FROM verwaltung.pseudonym WHERE pseudonym = ?', [$alt]);
            if ($ziel === false && $zugaenge !== []) {
                $test = array_values(array_filter($zugaenge, fn ($z) => $testbetrieb[$z['mandant']] ?? false));
                $ziel = ($test[0] ?? $zugaenge[0])['mandant'];
            }
            $offen = false;
            foreach ($zugaenge as $z) {
                $neu = null;
                try {
                    $m = $db->verbindung($z['datenbank']);
                    if (!$m->fetchOne('SELECT 1 FROM kern.nutzer WHERE id::text = ?', [$id])) {
                        $pseudonym = $z['mandant'] === $ziel ? $alt : ($neu = self::pseudonym());
                        if (!$v->fetchOne('SELECT 1 FROM verwaltung.pseudonym WHERE pseudonym = ?', [$pseudonym])) {
                            $v->executeStatement('SELECT verwaltung.pseudonym_eintragen(?, ?)', [$pseudonym, $z['mandant']]);
                        }
                        $m->transactional(function (Connection $m) use ($v, $n, $id, $subjekt, $pseudonym, $z, $ziel): void {
                            // Der bisherige Name wird Familienname; Vorname(n) und E-Mail fragt die Oberfläche bei der nächsten Anmeldung ab
                            $m->executeStatement('SELECT kern.nutzer_anlegen(?, ?, NULL, NULL, ?, ?, ?::uuid)', [self::SUBJEKT, $n['name'], $n['sprache'], $pseudonym, $id]);
                            if ($z['mandant'] === $ziel) {
                                foreach ($v->fetchAllAssociative(
                                    "SELECT credential_id, encode(public_key, 'hex') AS public_key, alg, zaehler, bezeichnung, registriert_am::text AS registriert_am
                                       FROM verwaltung.anmelde_credential WHERE nutzer = ? AND widerrufen_am IS NULL ORDER BY registriert_am", [$id]) as $c) {
                                    $m->executeStatement("SELECT kern.anmelde_credential_uebernehmen(?, ?::uuid, ?, decode(?, 'hex'), ?, ?, ?, ?::timestamptz)",
                                        [self::SUBJEKT, $id, $c['credential_id'], $c['public_key'], $c['alg'], $c['zaehler'], $c['bezeichnung'], $c['registriert_am']]);
                                }
                            }
                            foreach ($v->fetchAllKeyValue('SELECT schluessel, wert::text FROM verwaltung.nutzer_einstellung WHERE nutzer = ?', [$id]) as $schluessel => $wert) {
                                $m->executeStatement('SELECT kern.einstellung_setzen(?, ?::uuid, ?, ?::jsonb)', [self::SUBJEKT, $id, $schluessel, $wert]);
                            }
                            if (!$n['aktiv']) {
                                $m->executeStatement('SELECT kern.nutzer_aendern(?, ?::uuid, ?, NULL, NULL, false)', [self::SUBJEKT, $id, $n['name']]);
                            }
                        });
                    }
                    $v->executeStatement('SELECT verwaltung.zugang_entziehen(?, ?, ?)', [self::SUBJEKT, $subjekt, $z['mandant']]);
                    $anzahl++;
                    $a->writeln(sprintf('überführt: %s → Mandant %s%s', $id, $z['mandant'],
                        $z['mandant'] === $ziel ? ' (vorhandene Passkeys gelten hier weiter)' : " (neuer Passkey nötig: bin/kumero nutzer:link {$z['mandant']} $id)"));
                } catch (\Throwable $f) {
                    $offen = true;
                    $fehler++;
                    if ($neu !== null) {
                        $v->executeStatement('DELETE FROM verwaltung.pseudonym WHERE pseudonym = ?', [$neu]);
                    }
                    $a->writeln(sprintf('<error>FEHLER: %s → Mandant %s: %s</error>', $id, $z['mandant'], explode("\n", $f->getMessage())[0]));
                }
            }
            if ($offen) {
                continue;      // beim nächsten Lauf weiter; bis dahin bleibt in der Verwaltung alles, wie es ist
            }
            $imMandanten = is_string($ziel);
            if ($imMandanten) {
                // Die Passkeys gehören jetzt zur Identität im Mandanten; in der Verwaltung gelten sie nicht mehr
                foreach ($v->fetchFirstColumn('SELECT credential_id FROM verwaltung.anmelde_credential WHERE nutzer = ? AND widerrufen_am IS NULL', [$id]) as $c) {
                    $v->executeStatement('SELECT verwaltung.credential_widerrufen(?, ?)', [self::SUBJEKT, $c]);
                }
            }
            if ($n['verwalter']) {
                if ($n['pseudonym'] === null) {
                    $v->executeStatement('SELECT verwaltung.verwalter_pseudonym_setzen(?, ?::uuid, ?)', [self::SUBJEKT, $id, $imMandanten ? self::pseudonym() : $alt]);
                    $a->writeln(sprintf('System-Verwalter %s (%s): %s', $n['name'], $id, $imMandanten
                        ? "sein bisheriger Passkey gehört jetzt zum Mandanten $ziel – neuer Passkey für die Verwaltung: bin/kumero verwalter:link $id"
                        : 'bleibt in der Verwaltung, seine Passkeys gelten weiter'));
                }
            } elseif ($n['aktiv']) {
                // Kein Verwalter: In der Verwaltung bleibt von ihm nur ein gesperrter Eintrag ohne Namen
                $v->executeStatement('SELECT verwaltung.nutzer_aendern(?, ?::uuid, ?, ?, false, false)', [self::SUBJEKT, $id, '(überführt)', $n['sprache']]);
                if (!$imMandanten) {
                    $a->writeln("ohne Zugang zu einem Mandanten, in der Verwaltung gesperrt: $id");
                }
            }
        }
        $a->writeln($fehler === 0 ? "Bestand überführt: $anzahl Identität(en) in Mandanten angelegt bzw. bestätigt."
            : "$fehler Zugang/Zugänge nicht überführt – Ursache beheben und den Befehl wiederholen.");
        return $fehler === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private static function mandantDb(Datenbank $db, string $kennung): array
    {
        $d = $db->verwaltung()->fetchOne('SELECT datenbank FROM verwaltung.mandant WHERE kennung = ? AND stillgelegt_am IS NULL', [$kennung]);
        if (!is_string($d)) {
            throw new \RuntimeException("Mandant $kennung unbekannt");
        }
        return [$d, $db->verbindung($d)];
    }

    private static function modulListe(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $module = Module::alle();
        $mandanten = $db->verwaltung()->fetchAllAssociative('SELECT kennung, datenbank FROM verwaltung.mandant WHERE stillgelegt_am IS NULL ORDER BY kennung');
        foreach ($module as $name => $m) {
            $a->writeln(sprintf('%-16s %-8s %s', $name, $m['version'], $m['beschreibung'] ?? ''));
            foreach ($mandanten as $md) {
                $v = $db->verbindung($md['datenbank'])->fetchOne('SELECT version FROM kern.modul WHERE name = ? AND aktiv', [$name]);
                $a->writeln(sprintf('    %-16s %s', $md['kennung'], $v === false ? 'nicht aktiv' : "aktiv ($v)"));
            }
        }
        return Command::SUCCESS;
    }

    private static function modulAktivieren(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        [$datenbank, $m] = self::mandantDb($db, (string) $e->getArgument('mandant'));
        foreach (Module::aktivieren((string) $e->getArgument('modul'), $datenbank, $m, self::SUBJEKT,
            ['-h', $k->dbHost, '-p', (string) $k->dbPort]) as $z) {
            $a->writeln($z);
        }
        return Command::SUCCESS;
    }

    private static function kontenrahmenEinlesen(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $datei = (string) $e->getArgument('datei');
        $art = $e->getOption('art') ?? (preg_match('/\.pdf$/i', $datei) ? 'pdf' : 'csv');
        $rahmen = strtoupper(str_replace([' ', '-'], '', (string) $e->getOption('rahmen')));
        $regeln = Regelwerk::laden(strtolower($rahmen));
        $ergaenzung = $e->getOption('ergaenzung') !== null ? CsvLeser::lesen((string) $e->getOption('ergaenzung')) : null;
        if (!in_array($art, ['pdf', 'csv'], true)) {
            $a->writeln('<error>--art muss pdf oder csv sein</error>');
            return Command::INVALID;
        }
        $roh = Einlesen::datei($datei, $art);
        $v = Einlesen::vorlage((string) ($e->getOption('name') ?? "$rahmen, aus " . strtoupper($art)), $rahmen, $regeln, $roh, [], $ergaenzung);
        $d = $v->daten;
        $a->writeln("Prüfbericht – {$d['name']}" . ($d['fassung'] ? " (Fassung {$d['fassung']})" : ''));
        $a->writeln(sprintf('  Konten: %d   Bereiche: %d   ausgelassen: %d   zu bestätigen: %d   offen: %d   Hinweise: %d',
            count($d['konten']), count($d['bereiche']), count($d['ausgelassen']), count($v->pruefen()), count($v->offen()), count($d['hinweise'])));
        $arten = array_count_values(array_map(fn ($x) => $x['kontoart'] ?? '–', $d['konten']));
        ksort($arten);
        $a->writeln('  Kontoarten: ' . implode(', ', array_map(fn ($x, $n) => "$x $n", array_keys($arten), $arten)));
        foreach ($d['hinweise'] as $h) {
            $a->writeln('  Hinweis (' . $h['art'] . (isset($h['seite']) ? ", Seite {$h['seite']}" : '') . (isset($h['zeile']) ? ", Zeile {$h['zeile']}" : '') . '): ' . $h['text']);
        }
        foreach ($v->offen() as $o) {
            $a->writeln("  offen: {$o['nr']} {$o['bezeichnung']} – " . ($o['offen'] ?? 'Kontoart fehlt'));
        }
        if ($e->getOption('probe')) {
            return Command::SUCCESS;
        }
        $pfad = (new Ablage($k->kontenrahmenVerzeichnis))->speichern($v);
        $a->writeln("Vorlage: " . basename($pfad, '.json'));
        $a->writeln($v->offen() === [] ? 'Bereit zum Anwenden: bin/kumero kontenrahmen:anwenden ' . basename($pfad, '.json') . ' MANDANT'
            : 'Vor dem Anwenden offene Punkte per --ergaenzung=CSV klären und neu einlesen.');
        return Command::SUCCESS;
    }

    private static function kontenrahmenVorlagen(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        foreach ((new Ablage($k->kontenrahmenVerzeichnis))->alle() as $x) {
            $d = $x['vorlage']->daten;
            $a->writeln(sprintf('%-48s %-6s %4d Konten  %s  %s', $x['kennung'], $d['rahmen'], count($d['konten']),
                $x['vorlage']->offen() === [] ? 'bereit' : count($x['vorlage']->offen()) . ' offen', $d['quelle']['eingelesen_am'] ?? ''));
        }
        return Command::SUCCESS;
    }

    private static function kontenrahmenZeigen(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $v = (new Ablage($k->kontenrahmenVerzeichnis))->laden((string) $e->getArgument('vorlage'));
        $liste = $e->getOption('offen') ? $v->offen() : ($e->getOption('pruefen') ? $v->pruefen() : $v->daten['konten']);
        foreach ($liste as $x) {
            $a->writeln(sprintf('%-6s %-3s %-8s %s%s', $x['nr'], $x['kennzeichen'] ?? '', $x['kontoart'] ?? '–', $x['bezeichnung'],
                isset($x['offen']) ? "   [offen: {$x['offen']}]" : (isset($x['pruefen']) ? "   [prüfen: {$x['pruefen']}]" : '')));
        }
        return Command::SUCCESS;
    }

    private static function kontenrahmenAnwenden(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $v = (new Ablage($k->kontenrahmenVerzeichnis))->laden((string) $e->getArgument('vorlage'));
        [, $m] = self::mandantDb($db, (string) $e->getArgument('mandant'));
        $erg = Anwenden::ausfuehren($m, $v, self::SUBJEKT, (bool) $e->getOption('bezeichnungen'));
        $a->writeln(sprintf('Angelegt: %d   schon vorhanden: %d   Bezeichnung übernommen: %d   Konflikte: %d',
            $erg['angelegt'], $erg['vorhanden'], $erg['umbenannt'], count($erg['konflikte'])));
        foreach ($erg['konflikte'] as $z) {
            $a->writeln("  Konflikt: $z");
        }
        if ($v->pruefen() !== []) {
            $a->writeln(count($v->pruefen()) . ' Konten mit Kontoart aus unsicheren Bereichen – Liste: bin/kumero kontenrahmen:zeigen VORLAGE --pruefen');
        }
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
        $pruefe('pdftotext für den Kontenrahmen-Import', function (): string {
            exec('pdftotext -v 2>&1', $aus, $rc);
            return $rc === 0 ? trim($aus[0] ?? '') : throw new \RuntimeException('fehlt (Debian-Paket poppler-utils)');
        });
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
