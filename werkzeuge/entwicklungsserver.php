<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Router für den eingebauten PHP-Server – nur Entwicklung und Browsertests, nie im Betrieb:
//   KUMERO_KONFIG=… KUMERO_ENTWICKLUNG=1 php -S localhost:8099 werkzeuge/entwicklungsserver.php
// Bildet nginx nach (install/vorlagen/nginx-kumero*.conf): statische Dateien aus public/,
// Modulseiten unter /module/<name>/<version>/…, CSP wie im Betrieb, alles andere an den Kernel.
declare(strict_types=1);

$wurzel = dirname(__DIR__);
$pfad = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$csp = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; "
     . "font-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";
header('Content-Security-Policy: ' . $csp);
header('X-Content-Type-Options: nosniff');

$typen = ['js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'json' => 'application/json; charset=utf-8',
          'svg' => 'image/svg+xml', 'html' => 'text/html; charset=utf-8'];
$datei = null;
if (preg_match('#^/module/([a-z][a-z0-9-]*)/[0-9]+\.[0-9]+\.[0-9]+/([a-z0-9_/-]+\.(js|css|json|svg))$#', $pfad, $m)) {
    $datei = "$wurzel/module/{$m[1]}/oeffentlich/{$m[2]}";
} elseif ($pfad !== '/' && !str_contains($pfad, '..') && preg_match('/\.(js|css|json|svg|html)$/', $pfad)) {
    $datei = "$wurzel/public$pfad";
}
if ($datei !== null) {
    if (!is_file($datei)) {
        http_response_code(404);
        return true;
    }
    header('Content-Type: ' . $typen[pathinfo($datei, PATHINFO_EXTENSION)]);
    readfile($datei);
    return true;
}

require getenv('KUMERO_AUTOLOAD') ?: "$wurzel/vendor/autoload.php";
$anfrage = Symfony\Component\HttpFoundation\Request::createFromGlobals();
$konfig = Kumero\Plattform\Konfiguration::laden();
(new Kumero\Plattform\Kernel($konfig, new Kumero\Plattform\Datenbank($konfig)))->bearbeiten($anfrage)->prepare($anfrage)->send();
return true;
