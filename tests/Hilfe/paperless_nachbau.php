<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Nachgebauter Paperless-ngx-Server für die Tests (PAPERLESS.md Abschnitt 8): php -S localhost:PORT tests/Hilfe/paperless_nachbau.php
// Nur synthetische Belege. Zustand in der Datei aus PAPERLESS_NACHBAU_ZUSTAND; Token: tok_muster_0815.
declare(strict_types=1);

$datei = getenv('PAPERLESS_NACHBAU_ZUSTAND') ?: sys_get_temp_dir() . '/paperless_nachbau.json';
if (!is_file($datei)) {
    file_put_contents($datei, json_encode([
        'x_version' => '3.4.2', 'api' => [9, 10],
        'tags' => [1 => 'zu buchen', 2 => 'Praxis', 3 => 'Privat', 4 => 'Kostenstelle Empfang'],
        'correspondents' => [1 => 'Musterlieferant GmbH', 2 => 'Musterbäcker'],
        'document_types' => [1 => 'Rechnung', 2 => 'Kassenbon'],
        'custom_fields' => [
            ['id' => 11, 'name' => 'Erkannt: Belegart', 'data_type' => 'string'],
            ['id' => 12, 'name' => 'Erkannt: Rechnungsnummer', 'data_type' => 'string'],
            ['id' => 13, 'name' => 'Erkannt: Rechnungsdatum', 'data_type' => 'date'],
            ['id' => 14, 'name' => 'Erkannt: Rechnungssteller', 'data_type' => 'string'],
            ['id' => 15, 'name' => 'Erkannt: Betrag netto', 'data_type' => 'monetary'],
            ['id' => 16, 'name' => 'Erkannt: Betrag Steuer', 'data_type' => 'monetary'],
            ['id' => 17, 'name' => 'Erkannt: Betrag brutto', 'data_type' => 'monetary'],
            ['id' => 18, 'name' => 'Zahlart', 'data_type' => 'select', 'extra_data' => ['select_options' => [['id' => 'a1', 'label' => 'bar'], ['id' => 'a2', 'label' => 'unbar']]]],
        ],
        'felder' => [
            5210 => [['field' => 11, 'value' => 'rechnung'], ['field' => 12, 'value' => 'RE-0815'], ['field' => 13, 'value' => '2026-02-27'], ['field' => 14, 'value' => 'Musterlieferant GmbH & Co. KG'],
                     ['field' => 15, 'value' => 'EUR91.96'], ['field' => 16, 'value' => 'EUR17.47'], ['field' => 17, 'value' => 'EUR109.43'], ['field' => 18, 'value' => 'a2']],
            5211 => [['field' => 11, 'value' => 'Kassenbon'], ['field' => 15, 'value' => 'EUR4.21'], ['field' => 17, 'value' => 'USD4.50'], ['field' => 18, 'value' => 'a1']],
        ],
        'documents' => [
            5210 => ['title' => 'Rechnung Musterlieferant RE-0815', 'created' => '2026-03-02T00:00:00+01:00', 'correspondent' => 1, 'document_type' => 1,
                     'tags' => [1, 2], 'original_file_name' => 'rechnung-0815.pdf', 'inhalt' => "%PDF-1.4 Muster 5210\n"],
            5211 => ['title' => 'Kassenbon Musterbäcker', 'created' => '2026-03-03', 'correspondent' => 2, 'document_type' => 2,
                     'tags' => [1], 'original_file_name' => 'bon.pdf', 'inhalt' => "%PDF-1.4 Muster 5211\n"],
            5212 => ['title' => 'Abgelegt, kein Buchungsfall', 'created' => '2026-03-04', 'correspondent' => null, 'document_type' => null,
                     'tags' => [3], 'original_file_name' => 'brief.pdf', 'inhalt' => "%PDF-1.4 Muster 5212\n"],
            5213 => ['title' => 'Beleg mit falscher Prüfsumme', 'created' => '2026-03-05', 'correspondent' => null, 'document_type' => null,
                     'tags' => [], 'original_file_name' => 'kaputt.pdf', 'inhalt' => "%PDF-1.4 Muster 5213\n", 'md5' => str_repeat('0', 32)],
        ],
    ]));
}
$z = json_decode((string) file_get_contents($datei), true);
$pfad = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
// API-Versionen wie Paperless-ngx: Kopfzeilen X-Version und X-Api-Version, ohne Angabe die größte, unbekannte → 406
header('X-Version: ' . $z['x_version']);
header('X-Api-Version: ' . max($z['api']));
$api = max($z['api']);
if (preg_match('/version=(\S+)/', $_SERVER['HTTP_ACCEPT'] ?? '', $vm)) {
    if (!ctype_digit($vm[1]) || !in_array((int) $vm[1], $z['api'], true)) {
        http_response_code(406);
        header('Content-Type: application/json');
        echo '{"detail":"Invalid version in \"Accept\" header."}';
        return;
    }
    $api = (int) $vm[1];
}
$json = function (mixed $d, int $status = 200): void { http_response_code($status); header('Content-Type: application/json'); echo json_encode($d); };
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Token tok_muster_0815') {
    $json(['detail' => 'Invalid token.'], 401);
    return;
}
$dok = fn (int $id, array $d) => ['id' => $id, 'title' => $d['title'], 'created' => $d['created'], 'correspondent' => $d['correspondent'],
    'document_type' => $d['document_type'], 'tags' => $d['tags'], 'original_file_name' => $d['original_file_name'],
    'archive_serial_number' => null, 'custom_fields' => $z['felder'][$id] ?? []];
if (preg_match('~^/api/(tags|correspondents|document_types)/$~', $pfad, $m)) {
    $r = [];
    foreach ($z[$m[1]] as $id => $name) {
        $r[] = ['id' => (int) $id, 'name' => $name];
    }
    $json(['count' => count($r), 'next' => null, 'results' => $r] + ($api < 10 ? ['all' => array_column($r, 'id')] : []));
} elseif ($pfad === '/api/custom_fields/') {
    $json(['count' => count($z['custom_fields']), 'next' => null, 'results' => $z['custom_fields']]);
} elseif ($pfad === '/api/ui_settings/') {
    $json(['user' => ['id' => 3, 'username' => 'erika.muster']]);
} elseif ($pfad === '/api/documents/') {
    $r = [];
    foreach ($z['documents'] as $id => $d) {
        if (!isset($q['tags__id__all']) || in_array((int) $q['tags__id__all'], $d['tags'], true)) {
            $r[] = $dok((int) $id, $d);
        }
    }
    $json(['count' => count($r), 'next' => null, 'results' => $r]);
} elseif (preg_match('~^/api/documents/(\d+)/(metadata/|download/|thumb/)?$~', $pfad, $m) && isset($z['documents'][$m[1]])) {
    $d = $z['documents'][$m[1]];
    $teil = $m[2] ?? '';
    if ($teil === 'metadata/') {
        $json(['original_checksum' => $d['md5'] ?? md5($d['inhalt']), 'original_mime_type' => 'application/pdf', 'has_archive_version' => true]);
    } elseif ($teil === 'download/') {
        header('Content-Type: application/pdf');
        echo ($q['original'] ?? '') === 'true' ? $d['inhalt'] : '%PDF-1.4 Archivfassung';
    } elseif ($teil === 'thumb/') {
        header('Content-Type: image/png');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    } elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $neu = json_decode((string) file_get_contents('php://input'), true);
        $z['documents'][$m[1]]['tags'] = array_values(array_map('intval', $neu['tags'] ?? $d['tags']));
        file_put_contents($datei, json_encode($z));
        $json($dok((int) $m[1], $z['documents'][$m[1]]));
    } else {
        $json($dok((int) $m[1], $d));
    }
} else {
    $json(['detail' => 'Not found.'], 404);
}
