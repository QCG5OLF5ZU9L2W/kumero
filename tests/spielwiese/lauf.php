<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Prüft den Pförtner der Spielwiese gegen ein laufendes kumero (SPIELWIESE.md Abschnitt 6) – nur erfundene Daten:
//   KUMERO_TEST_PGHOST=/tmp/pgtest KUMERO_TEST_PGUSER=postgres php tests/spielwiese/lauf.php
// Baut eine Testumgebung, startet kumero (Entwicklungsserver) und davor den Pförtner, lässt install/spielwiese/einrichten.php
// laufen und geht dann den Weg eines Besuchers: vorgeschaltete Seite, eintreten, arbeiten, abgelaufene Sitzung.
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kumero\Tests\Hilfe\Umgebung;

const OEFFENTLICH = 'http://localhost:8098';
const INTERN = 'http://127.0.0.1:8099';
$wurzel = dirname(__DIR__, 2);
$ok = 0;
$fehler = [];
$pruefe = function (string $was, bool $stimmt) use (&$ok, &$fehler): void {
    $stimmt ? $ok++ : $fehler[] = $was;
};
/** @return array{0: int, 1: string, 2: list<string>} Status, Körper, Kopfzeilen */
$http = function (string $methode, string $pfad, ?array $json = null, array $cookies = [], ?string $form = null): array {
    $kopf = [];
    $c = curl_init(OEFFENTLICH . $pfad);
    $zeilen = ['Origin: ' . OEFFENTLICH];
    $cookies === [] || $zeilen[] = 'Cookie: ' . implode('; ', $cookies);
    $json === null || $zeilen[] = 'Content-Type: application/json';
    $methode === 'GET' || $zeilen[] = 'Idempotency-Key: ' . bin2hex(random_bytes(16));
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $zeilen,
        CURLOPT_HEADERFUNCTION => function ($c, string $z) use (&$kopf): int { $kopf[] = rtrim($z); return strlen($z); }]);
    $json === null || curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($json));
    $form === null || curl_setopt($c, CURLOPT_POSTFIELDS, $form);
    $koerper = (string) curl_exec($c);
    return [(int) curl_getinfo($c, CURLINFO_RESPONSE_CODE), $koerper, $kopf];
};
$gesetzt = function (array $kopf): array {
    $c = [];
    foreach ($kopf as $z) {
        if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $z, $m)) {
            $c[$m[1]] = "$m[1]=$m[2]";
        }
    }
    return $c;
};

$u = new Umgebung(['kumero_demo']);
$u->aufbauen();
$prozesse = [];
$verz = sys_get_temp_dir() . '/spielwiese-test-' . getmypid();
mkdir($verz, 0700);
try {
    $k = require $u->konfigDatei;
    $k['adresse'] = OEFFENTLICH;
    file_put_contents($u->konfigDatei, '<?php return ' . var_export($k, true) . ';');
    file_put_contents("$verz/pfoertner.php", '<?php return ' . var_export([
        'adresse' => OEFFENTLICH, 'intern' => INTERN, 'passkey' => "$verz/passkey.json", 'mandant' => 'demo', 'mandant_name' => 'Demo', 'buch' => 'haupt',
        'app' => "$wurzel/public/app.html", 'paperless_passphrase' => '',
        'texte' => ['betreiber' => 'Muster-Betreiber (Test)', 'impressum' => 'https://example.org/impressum', 'datenschutz' => 'https://example.org/datenschutz', 'land' => 'Finanzministerium Musterland'],
    ], true) . ';');
    putenv("SPIELWIESE_KONFIG=$verz/pfoertner.php");
    putenv('SPIELWIESE_KUMERO=php ' . escapeshellarg("$wurzel/bin/kumero"));
    putenv('SPIELWIESE_PSQL=psql -h ' . escapeshellarg((string) getenv('KUMERO_TEST_PGHOST')) . ' -U ' . escapeshellarg(getenv('KUMERO_TEST_PGUSER') ?: 'postgres'));
    putenv("SPIELWIESE_KONTENRAHMEN=$wurzel/module/kontenrahmen/referenz/euer-kontenrahmen.csv");
    putenv('KUMERO_ENTWICKLUNG=1');
    $start = fn (string $befehl) => proc_open($befehl, [['file', '/dev/null', 'r'], ['file', "$verz/server.log", 'a'], ['file', "$verz/server.log", 'a']], $rohre);
    $prozesse[] = $start('exec php -S 127.0.0.1:8099 ' . escapeshellarg("$wurzel/werkzeuge/entwicklungsserver.php"));
    $prozesse[] = $start('exec php -S localhost:8098 ' . escapeshellarg("$wurzel/install/spielwiese/pfoertner.php"));
    usleep(700000);

    // --- Einrichtung, wie sie install/spielwiese.sh aufruft ---
    exec('php ' . escapeshellarg("$wurzel/install/spielwiese/einrichten.php") . ' alles 2>&1', $aus, $status);
    $pruefe("Einrichtung läuft durch:\n  " . implode("\n  ", $aus), $status === 0);
    $pruefe('Ausgabe der Einrichtung nennt keinen Registrierungslink', !str_contains(implode("\n", $aus), 'registrierung='));
    $pruefe('Passkey liegt als Datei mit 0600', is_file("$verz/passkey.json") && (fileperms("$verz/passkey.json") & 0777) === 0600);

    // --- Besucher ohne Bestätigung ---
    [$s, $seite, $kopf] = $http('GET', '/');
    $pruefe('vorgeschaltete Seite', $s === 200 && str_contains($seite, 'Alles hier ist öffentlich') && str_contains($seite, 'Verstanden, weiter'));
    $pruefe('Rechtliches am Pförtner: Rücksetzen, Quelle, Land, Impressum, Datenschutz, Betreiber', str_contains($seite, 'alle vier Stunden')
        && str_contains($seite, 'IV C 6 - S 2142/00024/010/001') && str_contains($seite, 'Finanzministerium Musterland')
        && str_contains($seite, 'https://example.org/impressum') && str_contains($seite, 'https://example.org/datenschutz') && str_contains($seite, 'Muster-Betreiber'));
    $pruefe('Seite ohne Skript, nicht für Suchmaschinen', !str_contains($seite, '<script') && preg_grep('/^X-Robots-Tag: noindex/i', $kopf) !== []);
    [$s, , $kopf] = $http('GET', '/app.html');
    $pruefe('Oberfläche ohne Bestätigung führt zur Startseite', $s === 302 && preg_grep('#^Location: /$#i', $kopf) !== []);
    [$s, $t] = $http('GET', '/api/v1/ich');
    $pruefe('Schnittstelle ohne Bestätigung: 401, keine Sitzung', $s === 401 && str_contains($t, 'spielwiese.eintreten'));
    [$s] = $http('GET', '/m/demo/b/haupt/api/v1/buchungen');
    $pruefe('Buchungen ohne Bestätigung: 401', $s === 401);
    [$s, $t] = $http('GET', '/spielwiese/seite.css');
    $pruefe('Stil der Seite', $s === 200 && str_contains($t, 'main'));

    // --- Eintreten ---
    [$s, , $kopf] = $http('POST', '/spielwiese/eintreten', null, [], '');
    $cookies = $gesetzt($kopf);
    $pruefe('Eintreten: weiter zur Oberfläche, Merkzeichen und Sitzung gesetzt', $s === 303 && preg_grep('#^Location: /app\.html$#i', $kopf) !== []
        && isset($cookies['__Host-spielwiese'], $cookies['__Host-kumero']));
    [$s, $t] = $http('GET', '/app.html', null, array_values($cookies));
    $pruefe('Oberfläche von kumero nach dem Eintreten', $s === 200 && str_contains($t, '<html'));
    [$s, $t] = $http('GET', '/api/v1/ich', null, array_values($cookies));
    $ich = json_decode($t, true);
    $pruefe('angemeldet als Spiel-Identität im Mandanten Demo', $s === 200 && str_contains($t, 'Muster') && str_contains($t, 'demo'));
    [$s, $t] = $http('GET', '/m/demo/b/haupt/api/v1/buchungen?limit=200', null, array_values($cookies));
    $liste = json_decode($t, true)['eintraege'] ?? [];
    $pruefe('Bestand: Buchungen des erfundenen Betriebs festgeschrieben (' . count($liste) . ')', $s === 200 && count($liste) >= 50
        && count(array_filter($liste, fn ($b) => ($b['status'] ?? '') === 'entwurf')) === 0);
    [$s, $t] = $http('GET', '/m/demo/b/haupt/api/v1/konten', null, array_values($cookies));
    $pruefe('EÜR-Kontenrahmen im Buch', $s === 200 && str_contains($t, 'Bezogene Fremdleistungen') && str_contains($t, 'Abziehbare Vorsteuer 19 %'));
    [$s, $t] = $http('GET', '/m/demo/api/v1/nutzer', null, array_values($cookies));
    $pruefe('Administrator: Nutzer des Mandanten sichtbar (SW8, SW13)', $s === 200);
    [$s, $t, $kopf] = $http('POST', '/m/demo/b/haupt/api/v1/buchungen', ['buchungsart' => 'KASSE', 'text' => 'Besucher bucht (Muster)',
        'zeilen' => [['konto' => '3520', 'seite' => 'S', 'betrag' => '1.60'], ['konto' => '6100', 'seite' => 'H', 'betrag' => '1.60']]], array_values($cookies));
    $pruefe('Besucher legt eine Buchung an; Location und ETag kommen durch', $s === 201 && preg_grep('/^Location: /i', $kopf) !== [] && preg_grep('/^ETag: /i', $kopf) !== []);

    // --- Abgelaufene Sitzung: Der Pförtner meldet neu an (SW20, SW22) ---
    $alt = $cookies;
    $alt['__Host-kumero'] = '__Host-kumero=demo.' . str_repeat('A', 43);
    [$s, $t, $kopf] = $http('GET', '/api/v1/ich', null, array_values($alt));
    $neu = $gesetzt($kopf);
    $pruefe('ungültige Sitzung: Antwort trotzdem 200 und neue Sitzung im Kopf', $s === 200 && str_contains($t, 'Muster') && isset($neu['__Host-kumero'])
        && $neu['__Host-kumero'] !== $alt['__Host-kumero']);
    [$s] = $http('GET', '/api/v1/ich', null, [$cookies['__Host-spielwiese'], $neu['__Host-kumero'] ?? 'x=y']);
    $pruefe('die neue Sitzung gilt', $s === 200);
    [$s, $t, $kopf] = $http('GET', '/api/v1/ich', null, [$cookies['__Host-spielwiese']]);
    $pruefe('ganz ohne Sitzung (nach dem Rücksetzen): ebenfalls neu angemeldet', $s === 200 && isset($gesetzt($kopf)['__Host-kumero']));
    [$s] = $http('GET', '/api/v1/ich', null, array_values($cookies));
    $pruefe('die erste Sitzung besteht daneben weiter', $s === 200);
    [$s] = $http('POST', '/api/v1/abmeldung', null, array_values($cookies));
    [$s2, , $kopf] = $http('GET', '/api/v1/ich', null, array_values($cookies));
    $pruefe('nach „Abmelden“ kommt der Besucher von selbst wieder hinein', $s2 === 200 && isset($gesetzt($kopf)['__Host-kumero']));
    [$s, $t] = $http('GET', '/m/demo/b/haupt/api/v1/buchungen/00000000-0000-4000-8000-000000000000', null, [$cookies['__Host-spielwiese'], $neu['__Host-kumero'] ?? 'x=y']);
    $pruefe('Fehler von kumero kommen unverändert durch (404)', $s === 404);

    // --- im Browser (nur wenn PLAYWRIGHT gesetzt ist) ---
    if (getenv('PLAYWRIGHT')) {
        exec('node ' . escapeshellarg("$wurzel/tests/spielwiese/browser.mjs") . ' ' . OEFFENTLICH . ' ' . escapeshellarg($argv[1] ?? '') . ' 2>&1', $browser, $status);
        $pruefe("Browserlauf:\n  " . implode("\n  ", $browser), $status === 0);
        echo implode("\n", $browser), "\n";
    }

    // --- kumero selbst bleibt zu: ohne Pförtner keine Sitzung ohne Passkey ---
    $c = curl_init(INTERN . '/api/v1/ich');
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Host: localhost:8098']]);
    curl_exec($c);
    $pruefe('kumero direkt, ohne Pförtner: 401 wie immer', curl_getinfo($c, CURLINFO_RESPONSE_CODE) === 401);
} finally {
    foreach ($prozesse as $p) {
        proc_terminate($p);
    }
    is_file("$verz/server.log") && $fehler !== [] && fwrite(STDERR, implode("\n", array_slice(file("$verz/server.log", FILE_IGNORE_NEW_LINES), -15)) . "\n");
    array_map('unlink', glob("$verz/*") ?: []);
    @rmdir($verz);
    $u->abbauen();
}
foreach ($fehler as $f) {
    echo "FEHLT: $f\n";
}
echo "\n$ok Prüfungen grün, " . count($fehler) . " fehlgeschlagen\n";
exit($fehler === [] ? 0 : 1);
