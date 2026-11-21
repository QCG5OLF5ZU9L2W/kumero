<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Testumgebung für die Browsertests: php tests/browser/aufbau.php ADRESSE [--buchen] [--vorrat] [--paperless] [--import] [--siegel] [--personal]   bzw.   … --abbau
// Mit --buchen: Modul buchen aktiv und ein paar synthetische Konten (für tests/browser/buchen.mjs).
// Gibt JSON mit Konfigurationsdatei und drei Registrierungslinks aus: „link“ (Erika, Verantwortliche im Mandanten), „administrator“ (Adam, Administrator des Mandanten) und „verwalter“ (System-Verwalterin). Nur synthetische Daten.
declare(strict_types=1);

require getenv('KUMERO_AUTOLOAD') ?: dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kumero\Tests\Hilfe\Umgebung;

$u = new Umgebung(['kumero_browserpraxis']);
if (($argv[1] ?? '') === '--abbau') {
    $u->abbauen();
    exit(0);
}
$u->aufbauen();
$k = require $u->konfigDatei;
$k['adresse'] = $argv[1];
file_put_contents($u->konfigDatei, '<?php return ' . var_export($k, true) . ';');
// Zwei Identitäten (F114): die System-Verwalterin der Installation und Erika als Verantwortliche im Mandanten.
// Der Mandant entsteht mit seinem ersten Administrator (U5); dessen Link braucht der Browsertest nicht.
$link = function (string $aus): string {
    preg_match('#(\S+/app\.html\#registrierung=\S+)#', $aus, $l) ?: throw new RuntimeException("Kein Link: $aus");
    return $l[1];
};
$verwalter = $link($u->cli(['command' => 'verwalter:anlegen', 'name' => 'Vera Verwalterin']));
$administrator = $link($u->cli(['command' => 'mandant:anlegen', 'kennung' => 'browserpraxis', 'name' => 'Musterpraxis', '--administrator' => true,
         'vorname' => 'Adam', 'familienname' => 'Admin', 'email' => 'adam@example.org']));
// Der Mandant entsteht ohne Buch (BUECHER.md A12): das erste Buch „haupt“
$u->cli(['command' => 'buch:anlegen', 'mandant' => 'browserpraxis', 'kennung' => 'haupt', 'name' => 'Musterpraxis']);
$erika = $link($u->cli(['command' => 'nutzer:anlegen', 'mandant' => 'browserpraxis', 'vorname' => 'Erika', 'familienname' => 'Muster',
                        'email' => 'erika@example.org', '--rolle' => ['Verantwortlicher']]));
$u->cli(['command' => 'modul:aktivieren', 'modul' => 'kontenrahmen', 'mandant' => 'browserpraxis']);
if (in_array('--buchen', $argv, true)) {
    $u->cli(['command' => 'modul:aktivieren', 'modul' => 'buchen', 'mandant' => 'browserpraxis']);
    foreach ([['1000', 'Kasse', 'aktiv'], ['1200', 'Bank', 'aktiv'], ['1400', 'Abziehbare Vorsteuer', 'aktiv'], ['4930', 'Bürobedarf', 'aufwand'], ['8100', 'Honorare Privatpatienten', 'ertrag']] as [$nr, $bez, $art]) {
        $u->psql('kumero_browserpraxis__haupt', "SELECT kern.konto_anlegen('system:test', '$nr', '$bez', '$art')");
    }
}
if (in_array('--vorrat', $argv, true)) {
    $u->cli(['command' => 'modul:aktivieren', 'modul' => 'arbeitsvorrat', 'mandant' => 'browserpraxis']);
}
if (in_array('--import', $argv, true)) {
    $u->cli(['command' => 'modul:aktivieren', 'modul' => 'import', 'mandant' => 'browserpraxis']);
}
if (in_array('--paperless', $argv, true)) {
    $u->cli(['command' => 'modul:aktivieren', 'modul' => 'paperless', 'mandant' => 'browserpraxis']);
}
if (in_array('--anlagen', $argv, true)) {
    $u->cli(['command' => 'modul:aktivieren', 'modul' => 'anlagen', 'mandant' => 'browserpraxis']);
}
if (in_array('--lager', $argv, true)) {
    $u->cli(['command' => 'modul:aktivieren', 'modul' => 'lager', 'mandant' => 'browserpraxis']);
}
if (in_array('--auswertungen', $argv, true)) {
    $u->cli(['command' => 'modul:aktivieren', 'modul' => 'auswertungen', 'mandant' => 'browserpraxis']);
}
if (in_array('--personal', $argv, true)) {
    // Modul am Mandanten (F135, F136); Erika führt das Personal und ist selbst Mitarbeiterin
    $u->cli(['command' => 'modul:aktivieren', 'modul' => 'personal', 'mandant' => 'browserpraxis']);
    $id = trim($u->psql('kumero_browserpraxis', "COPY (SELECT id FROM mandant.nutzer WHERE familienname = 'Muster') TO STDOUT"));
    // Zeiterfassung (F145): eigenes Modul am Mandanten mit eigener Rolle
    $u->cli(['command' => 'modul:aktivieren', 'modul' => 'zeit', 'mandant' => 'browserpraxis']);
    foreach (['Personal', 'Mitarbeiter', 'Zeiterfassung'] as $rolle) {
        $u->psql('kumero_browserpraxis', "SELECT mandant.zuweisen('system:test', 'nutzer:$id', '$rolle')");
    }
}
if (in_array('--siegel', $argv, true)) {
    $u->cli(['command' => 'modul:aktivieren', 'modul' => 'siegel', 'mandant' => 'browserpraxis']);
}
echo json_encode(['konfig' => $u->konfigDatei, 'link' => $erika, 'verwalter' => $verwalter, 'administrator' => $administrator, 'vorlagen' => $u->vorlagenVerzeichnis]), "\n";
