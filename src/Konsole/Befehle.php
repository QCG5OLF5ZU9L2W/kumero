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
use Kumero\Plattform\Mandantenpflege;
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
 * Nutzer gehören zum Mandanten (F114), Rollen zum Buch (B3): nutzer:* arbeitet in der Mandanten-Datenbank, verwalter:* an den
 * System-Verwaltern der Installation. Die Zuordnung Pseudonym → Mandant trägt die Kommandozeile mit ein.
 */
final class Befehle
{
    public const SUBJEKT = 'system:cli';

    public static function anwendung(): Application
    {
        $app = new Application('kumero', trim((string) @file_get_contents(dirname(__DIR__, 2) . '/VERSION')) ?: 'unbekannt');
        foreach ([
            self::befehl('mandant:anlegen', 'Neuen Mandanten anlegen und seinen ersten Administrator einsetzen (F114 U5) – ohne Buch; das erste Buch legt buch:anlegen an (BUECHER.md A12)',
                fn (Command $c) => $c->addArgument('kennung', InputArgument::REQUIRED, 'Kennung für den Pfad, z. B. praxis')
                    ->addArgument('name', InputArgument::REQUIRED, 'Anzeigename')
                    ->addArgument('vorname', InputArgument::REQUIRED, 'Vorname(n) des ersten Administrators')
                    ->addArgument('familienname', InputArgument::REQUIRED, 'Familienname des ersten Administrators')
                    ->addArgument('email', InputArgument::REQUIRED, 'E-Mail-Adresse des ersten Administrators')
                    ->addOption('administrator', null, InputOption::VALUE_NONE, 'leitet die Angaben zum ersten Administrator ein: --administrator <Vorname> <Familienname> <E-Mail>'),
                self::mandantAnlegen(...)),
            self::befehl('mandant:administrator', 'Einen Nutzer des Mandanten als Administrator einsetzen (BUECHER.md A10)',
                fn (Command $c) => $c->addArgument('mandant', InputArgument::REQUIRED, 'Kennung des Mandanten')
                    ->addArgument('nutzer', InputArgument::REQUIRED, 'Nutzer-ID'),
                self::mandantAdministrator(...)),
            self::befehl('mandant:liste', 'Mandanten mit ihren Büchern', fn (Command $c) => $c, self::mandantListe(...)),
            self::befehl('buch:anlegen', 'Buch in einem Mandanten anlegen: eigene Datenbank aus der Vorlage, Rollenvorlagen (F40, BUECHER.md)',
                fn (Command $c) => $c->addArgument('mandant', InputArgument::REQUIRED, 'Kennung des Mandanten')
                    ->addArgument('kennung', InputArgument::REQUIRED, 'Kennung des Buchs für den Pfad, z. B. haupt (2–20 Zeichen)')
                    ->addArgument('name', InputArgument::REQUIRED, 'Anzeigename')
                    ->addOption('art', null, InputOption::VALUE_REQUIRED, 'Art der Gewinnermittlung: euer oder bilanz', 'euer')
                    ->addOption('waehrung', null, InputOption::VALUE_REQUIRED, 'Buchwährung (F11)', 'EUR')
                    ->addOption('testbuch', null, InputOption::VALUE_NONE, 'Testbuch: dauerhaft gekennzeichnet, wird nie produktiv (B6)'),
                self::buchAnlegen(...)),
            self::befehl('system:auftraege', 'Offene Aufträge ausführen: Mandant anlegen, exportieren, löschen; Buch anlegen (F118, F126) – der Dienst kumero-auftraege ruft das auf',
                fn (Command $c) => $c, self::auftraege(...)),
            self::befehl('system:buecher-ueberfuehren', 'Überführt den Bestand von 0.7.29: je Mandant eine Mandanten-Datenbank, die bisherige Datenbank wird das Buch „haupt“ (BUECHER.md Abschnitt 12); wiederholbar',
                fn (Command $c) => $c, self::buecherUeberfuehren(...)),
            self::befehl('nutzer:anlegen', 'Nutzer in einem Mandanten anlegen, auf Wunsch Rollen in einem Buch zuweisen, und Registrierungslink für seinen Passkey ausgeben (F114)',
                fn (Command $c) => $c->addArgument('mandant', InputArgument::REQUIRED, 'Kennung des Mandanten')
                    ->addArgument('vorname', InputArgument::REQUIRED, 'Vorname(n)')
                    ->addArgument('familienname', InputArgument::REQUIRED, 'Familienname')
                    ->addArgument('email', InputArgument::REQUIRED, 'E-Mail-Adresse')
                    ->addOption('rolle', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Rolle(n) im Buch, z. B. Verantwortlicher, Buchhalter (F42)', [])
                    ->addOption('buch', null, InputOption::VALUE_REQUIRED, 'Buch für die Rollen; entbehrlich, wenn der Mandant genau ein Buch hat')
                    ->addOption('administrator', null, InputOption::VALUE_NONE, 'als Administrator des Mandanten einsetzen')
                    ->addOption('sprache', null, InputOption::VALUE_REQUIRED, 'Sprache, z. B. de; ohne Angabe gilt die Sprache des Browsers'),
                self::nutzerAnlegen(...)),
            self::befehl('nutzer:rolle', 'Einem Nutzer in einem Buch weitere Rollen zuweisen (F41, F42, B3)',
                fn (Command $c) => $c->addArgument('mandant', InputArgument::REQUIRED, 'Kennung des Mandanten')
                    ->addArgument('nutzer', InputArgument::REQUIRED, 'Nutzer-ID')
                    ->addOption('rolle', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Rolle(n)', [])
                    ->addOption('buch', null, InputOption::VALUE_REQUIRED, 'Buch; entbehrlich, wenn der Mandant genau ein Buch hat'),
                self::nutzerRolle(...)),
            self::befehl('nutzer:link', 'Neuen Registrierungslink für einen (weiteren) Passkey eines Nutzers ausgeben',
                fn (Command $c) => $c->addArgument('mandant', InputArgument::REQUIRED, 'Kennung des Mandanten')
                    ->addArgument('nutzer', InputArgument::REQUIRED, 'Nutzer-ID'),
                self::nutzerLink(...)),
            self::befehl('nutzer:liste', 'Nutzer je Mandant mit Passkeys und ihren Rollen je Buch',
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
            self::befehl('modul:liste', 'Mitgelieferte Module und ihr Stand je Buch', fn (Command $c) => $c, self::modulListe(...)),
            self::befehl('modul:aktivieren', 'Modul in einem Buch aktivieren bzw. aktualisieren (K6, B5)',
                fn (Command $c) => $c->addArgument('modul', InputArgument::REQUIRED)->addArgument('mandant', InputArgument::REQUIRED, 'Kennung des Mandanten')
                    ->addArgument('buch', InputArgument::OPTIONAL, 'Kennung des Buchs; entbehrlich, wenn der Mandant genau ein Buch hat'),
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
            self::befehl('kontenrahmen:anwenden', 'Vorlage auf ein Buch anwenden: Konten anlegen (F2, F74)',
                fn (Command $c) => $c->addArgument('vorlage', InputArgument::REQUIRED)->addArgument('mandant', InputArgument::REQUIRED)
                    ->addOption('buch', null, InputOption::VALUE_REQUIRED, 'Buch; entbehrlich, wenn der Mandant genau ein Buch hat')
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


    private static function nutzerAnlegen(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $kennung = (string) $e->getArgument('mandant');
        [, $m] = self::mandantDb($db, $kennung);
        $rollen = array_values((array) $e->getOption('rolle'));
        $buch = $rollen === [] ? null : self::buchDb($db, $kennung, $e->getOption('buch'));
        $pseudonym = self::pseudonym();
        // Zwei Datenbanken (F114): erst die Zuordnung in der Verwaltung, dann die Identität im Mandanten
        $v = $db->verwaltung();
        $v->executeStatement('SELECT verwaltung.pseudonym_eintragen(?, ?)', [$pseudonym, $kennung]);
        try {
            [$id, $token] = $m->transactional(function (Connection $m) use ($e, $kennung, $pseudonym): array {
                $id = (string) $m->fetchOne('SELECT mandant.nutzer_anlegen(?, ?, ?, ?, ?, ?)', [self::SUBJEKT, $e->getArgument('familienname'),
                    $e->getArgument('vorname'), $e->getArgument('email'), $e->getOption('sprache'), $pseudonym]);
                if ($e->getOption('administrator')) {
                    $m->executeStatement('SELECT mandant.administrator_einsetzen(?, ?::uuid)', [self::SUBJEKT, $id]);
                }
                $token = self::token($kennung);
                $m->executeStatement('SELECT mandant.registrierung_ausstellen(?, ?::uuid, ?)', [self::SUBJEKT, $id, hash('sha256', $token)]);
                return [$id, $token];
            });
        } catch (\Throwable $f) {
            $v->executeStatement('DELETE FROM verwaltung.pseudonym WHERE pseudonym = ?', [$pseudonym]);
            throw $f;
        }
        if ($buch !== null) {
            $buch[1]->transactional(function (Connection $b) use ($id, $rollen): void {
                foreach ($rollen as $rolle) {
                    $b->executeStatement('SELECT kern.zuweisen(?, ?, ?)', [self::SUBJEKT, 'nutzer:' . $id, $rolle]);
                }
            });
        }
        $a->writeln("Nutzer angelegt: $id");
        $a->writeln("Mandant $kennung, " . ($e->getOption('administrator') ? 'Administrator, ' : '')
            . ($rollen === [] ? 'noch ohne Rolle in einem Buch (bin/kumero nutzer:rolle)' : "Buch {$buch[2]}, Rolle(n): " . implode(', ', $rollen)));
        self::linkAusgeben($a, $k, $token);
        return Command::SUCCESS;
    }

    private static function nutzerRolle(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $kennung = (string) $e->getArgument('mandant');
        [, $m] = self::mandantDb($db, $kennung);
        [, $b, $buch] = self::buchDb($db, $kennung, $e->getOption('buch'));
        $nutzer = (string) $e->getArgument('nutzer');
        $rollen = array_values((array) $e->getOption('rolle'));
        if ($rollen === [] || !$m->fetchOne('SELECT 1 FROM mandant.nutzer WHERE id::text = ?', [$nutzer])) {
            $a->writeln($rollen === [] ? '<error>Mindestens eine --rolle angeben</error>' : '<error>Nutzer in diesem Mandanten unbekannt</error>');
            return Command::FAILURE;
        }
        if (in_array('Administrator', $rollen, true)) {
            $a->writeln("<error>Administratoren setzt der Mandant ein, nicht das Buch: bin/kumero mandant:administrator $kennung $nutzer</error>");
            return Command::FAILURE;
        }
        $b->transactional(function (Connection $b) use ($nutzer, $rollen): void {
            foreach ($rollen as $rolle) {
                $b->executeStatement('SELECT kern.zuweisen(?, ?, ?)', [self::SUBJEKT, 'nutzer:' . $nutzer, $rolle]);
            }
        });
        $a->writeln("Buch $buch – Rolle(n) zugewiesen: " . implode(', ', $rollen));
        return Command::SUCCESS;
    }

    private static function nutzerLink(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $kennung = (string) $e->getArgument('mandant');
        [, $m] = self::mandantDb($db, $kennung);
        $token = self::token($kennung);
        $m->executeStatement('SELECT mandant.registrierung_ausstellen(?, ?::uuid, ?)', [self::SUBJEKT, $e->getArgument('nutzer'), hash('sha256', $token)]);
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
            // Rollen je Buch: Subjekt → „buch: Rolle, Rolle“
            $rollen = [];
            foreach ($db->verwaltung()->fetchAllKeyValue('SELECT kennung, datenbank FROM verwaltung.buch WHERE mandant = ? ORDER BY kennung', [$md['kennung']]) as $buch => $datenbank) {
                foreach ($db->verbindung($datenbank)->fetchAllKeyValue(
                    "SELECT z.subjekt, string_agg(DISTINCT r.name, ', ' ORDER BY r.name) FROM kern.zuweisung z JOIN kern.rolle r ON r.id = z.rolle
                      WHERE z.gueltig_von <= now() AND (z.gueltig_bis IS NULL OR z.gueltig_bis > now()) GROUP BY z.subjekt") as $subjekt => $liste) {
                    $rollen[$subjekt][] = "$buch: $liste";
                }
            }
            foreach ($db->verbindung($md['datenbank'])->fetchAllAssociative(
                "SELECT n.id, trim(coalesce(n.vornamen, '') || ' ' || n.familienname) AS name, coalesce(n.email, '–') AS email, n.aktiv,
                        (SELECT count(*) FROM mandant.anmelde_credential c WHERE c.nutzer = n.id AND c.widerrufen_am IS NULL) AS passkeys,
                        EXISTS (SELECT 1 FROM mandant.administrator x WHERE x.nutzer = n.id AND x.bis IS NULL) AS administrator
                   FROM mandant.nutzer n ORDER BY n.familienname, n.vornamen") as $n) {
                $a->writeln(sprintf('  %s  %-28s %-28s %s  Passkeys: %d  %sRollen: %s', $n['id'], $n['name'], $n['email'],
                    $n['aktiv'] ? 'aktiv' : 'gesperrt', $n['passkeys'], $n['administrator'] ? 'Administrator  ' : '',
                    implode('; ', $rollen['nutzer:' . $n['id']] ?? []) ?: '–'));
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
                $n['bereit'] ? '' : '  (ohne Pseudonym – Bestand vor 0.7.22; erst auf 0.7.29 aktualisieren)'));
        }
        return Command::SUCCESS;
    }

    private static function mandantAnlegen(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $kennung = (string) $e->getArgument('kennung');
        $token = self::token($kennung);
        try {
            $id = (new Mandantenpflege($k, $db))->anlegen(self::SUBJEKT, $kennung, (string) $e->getArgument('name'), (string) $e->getArgument('vorname'),
                (string) $e->getArgument('familienname'), (string) $e->getArgument('email'), hash('sha256', $token));
        } catch (\InvalidArgumentException | \RuntimeException $f) {
            $a->writeln('<error>' . $f->getMessage() . '</error>');
            return $f instanceof \InvalidArgumentException ? Command::INVALID : Command::FAILURE;
        }
        $a->writeln("Mandant $kennung angelegt (Mandanten-Datenbank " . Mandantenpflege::datenbankName($kennung) . ', noch ohne Buch).');
        $a->writeln("Administrator eingesetzt: $id");
        self::linkAusgeben($a, $k, $token);
        $a->writeln("Erstes Buch: bin/kumero buch:anlegen $kennung haupt \"<Name>\" --art euer");
        $a->writeln("Weitere Nutzer: bin/kumero nutzer:anlegen $kennung <Vorname> <Familienname> <E-Mail> --rolle Verantwortlicher");
        return Command::SUCCESS;
    }

    private static function mandantAdministrator(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        [, $m] = self::mandantDb($db, (string) $e->getArgument('mandant'));
        $m->executeStatement('SELECT mandant.administrator_einsetzen(?, ?::uuid)', [self::SUBJEKT, $e->getArgument('nutzer')]);
        $a->writeln('Administrator eingesetzt: ' . $e->getArgument('nutzer'));
        return Command::SUCCESS;
    }

    private static function buchAnlegen(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $mandant = (string) $e->getArgument('mandant');
        $kennung = (string) $e->getArgument('kennung');
        try {
            (new Mandantenpflege($k, $db))->buchAnlegen(self::SUBJEKT, $mandant, $kennung, (string) $e->getArgument('name'),
                (string) $e->getOption('art'), (string) $e->getOption('waehrung'), (bool) $e->getOption('testbuch'));
        } catch (\InvalidArgumentException | \RuntimeException $f) {
            $a->writeln('<error>' . $f->getMessage() . '</error>');
            return $f instanceof \InvalidArgumentException ? Command::INVALID : Command::FAILURE;
        }
        $a->writeln("Buch $kennung im Mandanten $mandant angelegt (Datenbank " . Mandantenpflege::buchDatenbankName($mandant, $kennung)
            . ', ' . ($e->getOption('testbuch') ? 'Testbuch' : 'Testbetrieb') . ', Rollenvorlagen F42).');
        return Command::SUCCESS;
    }

    private static function buecherUeberfuehren(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $fehler = (new Mandantenpflege($k, $db))->buecherUeberfuehren(fn (string $z) => $a->writeln($z));
        $a->writeln($fehler === 0 ? 'Überführen abgeschlossen.' : "Überführen unvollständig: $fehler Mandant(en) – Ursache beheben und wiederholen.");
        return $fehler === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /** Führt die Aufträge der Mandantenverwaltung aus (F118). Die Ausgabe nennt nur Art, Mandant und Ergebnis. */
    private static function auftraege(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $erg = (new \Kumero\Plattform\Mandantenpflege($k, $db))->auftraegeAusfuehren();
        foreach ($erg as $x) {
            $a->writeln(sprintf('%s %s: %s', $x['art'], $x['mandant'], $x['erfolg'] ? 'erledigt' : 'fehlgeschlagen (' . $x['code'] . ')'));
        }
        if ($erg === []) {
            $a->writeln('Keine offenen Aufträge.');
        }
        return Command::SUCCESS;
    }


    private static function mandantListe(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        foreach ($db->verwaltung()->fetchAllAssociative('SELECT kennung, datenbank, name, stillgelegt_am FROM verwaltung.mandant ORDER BY kennung') as $m) {
            $a->writeln(sprintf('%-16s %-24s %s%s', $m['kennung'], $m['datenbank'], $m['name'], $m['stillgelegt_am'] ? ' (stillgelegt)' : ''));
            foreach ($db->verwaltung()->fetchAllKeyValue('SELECT kennung, datenbank FROM verwaltung.buch WHERE mandant = ? ORDER BY kennung', [$m['kennung']]) as $buch => $datenbank) {
                $a->writeln(sprintf('    Buch %-12s %s', $buch, $datenbank));
            }
        }
        return Command::SUCCESS;
    }


    /** @return array{0: string, 1: Connection} Name und Verbindung der Mandanten-Datenbank (Schema `mandant`) */
    private static function mandantDb(Datenbank $db, string $kennung): array
    {
        $d = $db->verwaltung()->fetchOne('SELECT datenbank FROM verwaltung.mandant WHERE kennung = ? AND stillgelegt_am IS NULL', [$kennung]);
        if (!is_string($d)) {
            throw new \RuntimeException("Mandant $kennung unbekannt");
        }
        return [$d, $db->verbindung($d)];
    }

    /**
     * Datenbank eines Buchs. Ohne Angabe gilt das eine Buch des Mandanten; hat er mehrere, muss es genannt werden.
     *
     * @return array{0: string, 1: Connection, 2: string} Name der Datenbank, Verbindung, Kennung des Buchs
     */
    private static function buchDb(Datenbank $db, string $mandant, mixed $buch): array
    {
        self::mandantDb($db, $mandant);
        $buecher = $db->verwaltung()->fetchAllKeyValue('SELECT kennung, datenbank FROM verwaltung.buch WHERE mandant = ? ORDER BY kennung', [$mandant]);
        if ($buch === null || $buch === '') {
            if (count($buecher) !== 1) {
                throw new \RuntimeException($buecher === [] ? "Mandant $mandant hat noch kein Buch (bin/kumero buch:anlegen)"
                    : "Mandant $mandant hat mehrere Bücher – eines nennen: " . implode(', ', array_keys($buecher)));
            }
            $buch = array_key_first($buecher);
        }
        if (!isset($buecher[$buch])) {
            throw new \RuntimeException("Buch $buch im Mandanten $mandant unbekannt");
        }
        return [(string) $buecher[$buch], $db->verbindung((string) $buecher[$buch]), (string) $buch];
    }

    private static function modulListe(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        $module = Module::alle();
        $buecher = $db->verwaltung()->fetchAllAssociative('SELECT b.mandant, b.kennung, b.datenbank FROM verwaltung.buch b JOIN verwaltung.mandant m ON m.kennung = b.mandant
                                                            WHERE m.stillgelegt_am IS NULL ORDER BY b.mandant, b.kennung');
        foreach ($module as $name => $m) {
            $a->writeln(sprintf('%-16s %-8s %s', $name, $m['version'], $m['beschreibung'] ?? ''));
            foreach ($buecher as $b) {
                $v = $db->verbindung($b['datenbank'])->fetchOne('SELECT version FROM kern.modul WHERE name = ? AND aktiv', [$name]);
                $a->writeln(sprintf('    %-24s %s', $b['mandant'] . '/' . $b['kennung'], $v === false ? 'nicht aktiv' : "aktiv ($v)"));
            }
        }
        return Command::SUCCESS;
    }

    private static function modulAktivieren(InputInterface $e, OutputInterface $a, Konfiguration $k, Datenbank $db): int
    {
        [$datenbank, $b, $buch] = self::buchDb($db, (string) $e->getArgument('mandant'), $e->getArgument('buch'));
        $a->writeln("Buch $buch:");
        foreach (Module::aktivieren((string) $e->getArgument('modul'), $datenbank, $b, self::SUBJEKT,
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
        [, $m] = self::buchDb($db, (string) $e->getArgument('mandant'), $e->getOption('buch'));
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
            // Seit 0.7.30 (F126): die Datenbank des Mandanten hat ihre eigene Kette, jedes Buch seine Ketten im Kern
            $pruefe("Mandant {$m['kennung']}: Protokollkette", fn () => $db->verbindung($m['datenbank'])->fetchOne('SELECT count(*) FROM mandant.kette_pruefen()') == 0
                ? 'intakt' : throw new \RuntimeException('Abweichung'));
            // Ohne Administrator kann im Mandanten niemand Nutzer, Bücher und Rollen pflegen (nach dem Überführen möglich,
            // wenn vorher niemand die Rolle hatte)
            $pruefe("Mandant {$m['kennung']}: Administrator", fn () => ($n = (int) $db->verbindung($m['datenbank'])->fetchOne('SELECT mandant._administratoren()')) > 0
                ? (string) $n : throw new \RuntimeException("keiner – einsetzen mit: bin/kumero nutzer:liste, dann bin/kumero mandant:administrator {$m['kennung']} NUTZER-ID"));
            foreach ($db->verwaltung()->fetchAllAssociative('SELECT kennung, datenbank FROM verwaltung.buch WHERE mandant = ? ORDER BY kennung', [$m['kennung']]) as $b) {
                $pruefe("Mandant {$m['kennung']}, Buch {$b['kennung']}: Ketten", fn () => $db->verbindung($b['datenbank'])->fetchOne('SELECT count(*) FROM kern.kette_pruefen()') == 0
                    ? 'intakt' : throw new \RuntimeException('Abweichung'));
            }
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
