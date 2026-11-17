<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Führt alle PHP-Tests aus: php tests/lauf.php [Filter]
// Datenbanktests brauchen KUMERO_TEST_PGHOST (z. B. /tmp/pgtest) und einen
// Superuser in KUMERO_TEST_PGUSER; sie legen eigene kumero_test_php*-Datenbanken an.
declare(strict_types=1);

$autoload = getenv('KUMERO_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php';
require $autoload;

$filter = $argv[1] ?? '';
$gesamt = 0;
$fehler = 0;
$dateien = glob(__DIR__ . '/*Test.php') ?: [];
sort($dateien);
foreach ($dateien as $datei) {
    $klasse = 'Kumero\\Tests\\' . basename($datei, '.php');
    if ($filter !== '' && !str_contains($klasse, $filter)) {
        continue;
    }
    require_once $datei;
    foreach (get_class_methods($klasse) as $methode) {
        if (!str_starts_with($methode, 'test')) {
            continue;
        }
        $t = new $klasse();
        try {
            $t->vorher();
            $t->$methode();
            echo "ok – $klasse::$methode ({$t->pruefungen})\n";
        } catch (\Throwable $e) {
            $fehler++;
            echo "FEHLER – $klasse::$methode\n  " . str_replace("\n", "\n  ", $e->getMessage())
                . "\n  bei " . $e->getFile() . ':' . $e->getLine() . "\n";
        } finally {
            $t->nachher();
        }
        $gesamt += $t->pruefungen;
    }
}
echo "\n$gesamt Prüfungen, " . ($fehler === 0 ? 'alle Tests grün' : "$fehler Tests fehlgeschlagen") . "\n";
exit($fehler === 0 ? 0 : 1);
