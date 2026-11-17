<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Einziger Einstieg für nginx (ANWENDUNG.md Abschnitt 3).
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Kumero\Api\V1\Problem;
use Kumero\Plattform\Datenbank;
use Kumero\Plattform\Kernel;
use Kumero\Plattform\Konfiguration;
use Symfony\Component\HttpFoundation\Request;

$anfrage = Request::createFromGlobals();
try {
    if (PHP_VERSION_ID < 80500 && getenv('KUMERO_ENTWICKLUNG') !== '1') {
        throw new \RuntimeException('kumero braucht PHP 8.5 (F54)');
    }
    $konfig = Konfiguration::laden();
    $antwort = (new Kernel($konfig, new Datenbank($konfig)))->bearbeiten($anfrage);
} catch (\Throwable $e) {
    error_log('kumero: Start fehlgeschlagen: ' . $e->getMessage());
    $antwort = Kernel::problemAntwort(new Problem(500, 'intern', 'kumero ist nicht richtig eingerichtet – siehe Server-Log'), '-');
}
$antwort->prepare($anfrage)->send();
