<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Testumgebung für die Browsertests: php tests/browser/aufbau.php ADRESSE [--buchen]   bzw.   … --abbau
// Mit --buchen: Modul buchen aktiv und ein paar synthetische Konten (für tests/browser/buchen.mjs).
// Gibt JSON mit Konfigurationsdatei und Registrierungslink aus. Nur synthetische Daten.
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
$aus = $u->cli(['command' => 'nutzer:anlegen', 'name' => 'Erika Muster', '--verwalter' => true]);
preg_match('/Nutzer angelegt: ([0-9a-f-]{36})/', $aus, $m);
preg_match('#(\S+/app\.html\#registrierung=\S+)#', $aus, $l);
$u->cli(['command' => 'mandant:anlegen', 'kennung' => 'browserpraxis', 'name' => 'Musterpraxis']);
$u->cli(['command' => 'zugang:gewaehren', 'nutzer' => $m[1], 'mandant' => 'browserpraxis']);
$u->cli(['command' => 'modul:aktivieren', 'modul' => 'kontenrahmen', 'mandant' => 'browserpraxis']);
if (in_array('--buchen', $argv, true)) {
    $u->cli(['command' => 'modul:aktivieren', 'modul' => 'buchen', 'mandant' => 'browserpraxis']);
    foreach ([['1000', 'Kasse', 'aktiv'], ['1200', 'Bank', 'aktiv'], ['4930', 'Bürobedarf', 'aufwand'], ['8100', 'Honorare Privatpatienten', 'ertrag']] as [$nr, $bez, $art]) {
        $u->psql('kumero_browserpraxis', "SELECT kern.konto_anlegen('system:test', '$nr', '$bez', '$art')");
    }
}
echo json_encode(['konfig' => $u->konfigDatei, 'link' => $l[1], 'vorlagen' => $u->vorlagenVerzeichnis]), "\n";
