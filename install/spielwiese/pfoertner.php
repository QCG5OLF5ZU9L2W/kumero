<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Pförtner der Spielwiese (SPIELWIESE.md Abschnitt 3; F183, F185, F186). Steht vor kumero und ändert nichts daran:
//   1. zeigt die vorgeschaltete Seite mit allem Rechtlichen (SW7, SW11, SW12),
//   2. meldet nach „Verstanden, weiter“ die Spiel-Identität mit ihrem Passkey an und reicht die Sitzung weiter (SW17),
//   3. reicht jede Anfrage an die Schnittstelle unverändert durch und meldet neu an, wenn die Sitzung abgelaufen ist (SW20, SW22).
// Nur install/spielwiese.sh bringt ihn auf eine Maschine. Auf einer Maschine des Betriebs gibt es ihn nicht.
declare(strict_types=1);

namespace Kumero\Spielwiese;

require __DIR__ . '/lib.php';

const MERKZEICHEN = '__Host-spielwiese';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function kopfzeilen(): void
{
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
}

/** @param array<string, mixed> $k */
function seite(array $k, ?string $fehler = null): void
{
    $t = $k['texte'] ?? [];
    $verweis = fn (string $schluessel, string $titel) => ($t[$schluessel] ?? '') === '' ? ''
        : '<a href="' . h($t[$schluessel]) . '" rel="noopener noreferrer">' . h($titel) . '</a>';
    kopfzeilen();
    header('Content-Type: text/html; charset=utf-8');
    http_response_code($fehler === null ? 200 : 503);
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">',
        '<meta name="robots" content="noindex, nofollow"><title>kumero zum Ausprobieren</title><link rel="stylesheet" href="/spielwiese/seite.css"></head><body><main>',
        '<h1>kumero zum Ausprobieren</h1>',
        '<p>Hier läuft <strong>kumero</strong>, eine offene Buchhaltung, mit einem erfundenen Betrieb. Sie arbeiten ohne Anmeldung und dürfen alles: buchen, Belege ansehen, Lager, Personal, Nutzer und Module verwalten.</p>',
        '<ul>',
        '<li><strong>Alles hier ist öffentlich.</strong> Jeder Besucher sieht und ändert denselben Bestand. Geben Sie keine echten Namen, Belege, Bankdaten oder Passwörter ein und laden Sie keine echten Dokumente hoch.</li>',
        '<li><strong>Alles wird regelmäßig gelöscht.</strong> ', h($t['ruecksetzen'] ?? 'Die Maschine wird alle vier Stunden auf den Ausgangsstand zurückgesetzt (0, 4, 8, 12, 16 und 20 Uhr).'), ' Was Sie eingeben, ist danach weg.</li>',
        '<li><strong>Der Betrieb ist erfunden.</strong> Namen, Beträge und Belege sind Muster; Ähnlichkeiten mit echten Betrieben sind Zufall.</li>',
        '<li><strong>Eine Identität mit allen Rollen.</strong> Im Betrieb trennt kumero Buchhaltung, Verwaltung und Personal nach Rollen; hier sehen Sie alles auf einmal.</li>',
        '</ul>',
        $fehler === null ? '' : '<p class="fehler">' . h($fehler) . '</p>',
        '<form method="post" action="/spielwiese/eintreten"><button type="submit">Verstanden, weiter</button></form>',
        '<h2>Quellen und Rechtliches</h2>',
        '<p>Der Kontenrahmen folgt den Zeilen des Vordrucks „Einnahmenüberschussrechnung – Anlage EÜR“ 2026, bekannt gegeben mit Schreiben des Bundesministeriums der Finanzen vom 1. September 2026 (IV C 6 - S 2142/00024/010/001), im Einvernehmen mit den obersten Finanzbehörden der Länder',
        ($t['land'] ?? '') === '' ? '' : ' – hier: ' . h($t['land']),
        '. Bezeichnungen der Zeilen stammen von dort; alle übrigen Konten sind eigene des Projekts. Keine steuerliche Beratung, ohne Gewähr.</p>',
        '<p>kumero ist freie Software unter der AGPL 3.0. ',
        ($t['quellcode'] ?? '') === '' ? '' : '<a href="' . h($t['quellcode']) . '" rel="noopener noreferrer">Quellcode</a>. ',
        '</p><p class="klein">', ($t['betreiber'] ?? '') === '' ? '' : 'Betreiber: ' . h($t['betreiber']) . '. ',
        implode(' · ', array_filter([$verweis('impressum', 'Impressum'), $verweis('datenschutz', 'Datenschutz')])), '</p>',
        '</main></body></html>';
}

function eingetreten(): bool
{
    return ($_COOKIE[MERKZEICHEN] ?? '') === 'verstanden';
}

/** Kopfzeilen der Anfrage, die an kumero weitergehen. @return list<string> */
function anfragekopf(): array
{
    $weiter = [];
    foreach (['CONTENT_TYPE' => 'Content-Type', 'HTTP_ACCEPT' => 'Accept', 'HTTP_IF_MATCH' => 'If-Match', 'HTTP_IF_NONE_MATCH' => 'If-None-Match',
              'HTTP_ACCEPT_LANGUAGE' => 'Accept-Language', 'HTTP_RANGE' => 'Range', 'HTTP_IDEMPOTENCY_KEY' => 'Idempotency-Key'] as $server => $name) {
        if (($_SERVER[$server] ?? '') !== '') {
            $weiter[] = "$name: {$_SERVER[$server]}";
        }
    }
    return $weiter;
}

/** Cookies des Besuchers ohne das Merkzeichen; auf Wunsch mit einer anderen Sitzung. */
function cookies(?string $sitzung = null): ?string
{
    $c = $_COOKIE;
    unset($c[MERKZEICHEN]);
    $teile = [];
    foreach ($c as $name => $wert) {
        if ($sitzung === null || $name !== Kumero::COOKIE) {
            $teile[] = $name . '=' . rawurlencode((string) $wert);
        }
    }
    if ($sitzung !== null) {
        $teile[] = $sitzung;
    }
    return $teile === [] ? null : implode('; ', $teile);
}

function durchreichen(Kumero $kumero): void
{
    $methode = $_SERVER['REQUEST_METHOD'];
    $pfad = $_SERVER['REQUEST_URI'];
    $koerper = in_array($methode, ['GET', 'HEAD'], true) ? null : (string) file_get_contents('php://input');
    $a = $kumero->ruf($methode, $pfad, $koerper, anfragekopf(), cookies());
    $neu = null;
    if ($a['status'] === 401 && str_contains($a['koerper'], 'anmeldung.erforderlich')) {
        // Sitzung abgelaufen oder die Maschine wurde zurückgesetzt: neu anmelden und die Anfrage einmal wiederholen
        $neu = $kumero->anmelden();
        $a = $kumero->ruf($methode, $pfad, $koerper, anfragekopf(), cookies(Kumero::cookieAus($neu)));
    }
    http_response_code($a['status']);
    foreach ($a['kopf'] as $zeile) {
        if (preg_match('/^(Content-Type|ETag|Location|Set-Cookie|Cache-Control|Content-Disposition|Content-Security-Policy|X-Content-Type-Options|Retry-After|Allow|Content-Range|Accept-Ranges|Last-Modified):/i', $zeile)) {
            header($zeile, false);
        }
    }
    if ($neu !== null && Kumero::sitzungszeile($a['kopf']) === null) {
        header("Set-Cookie: $neu", false);
    }
    header('X-Robots-Tag: noindex, nofollow');
    echo $a['koerper'];
}

$k = konfiguration();
$pfad = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
try {
    if ($pfad === '/spielwiese/seite.css') {
        header('Content-Type: text/css; charset=utf-8');
        readfile(__DIR__ . '/seite.css');
    } elseif ($pfad === '/spielwiese/eintreten' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $sitzung = (new Kumero($k))->anmelden();
        kopfzeilen();
        header('Set-Cookie: ' . MERKZEICHEN . '=verstanden; Path=/; Secure; HttpOnly; SameSite=Lax', false);
        header("Set-Cookie: $sitzung", false);
        header('Location: /app.html', true, 303);
    } elseif (in_array($pfad, ['/', '/index.php', '/app.html', '/spielwiese', '/spielwiese/'], true)) {
        if ($pfad === '/app.html' && eingetreten()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-cache');
            header('X-Robots-Tag: noindex, nofollow');
            readfile($k['app']);
        } elseif ($pfad === '/app.html' || !eingetreten()) {
            $pfad === '/app.html' ? header('Location: /', true, 302) : seite($k);
        } else {
            header('Location: /app.html', true, 302);
        }
    } elseif (!eingetreten()) {
        // Wer die vorgeschaltete Seite nicht bestätigt hat, bekommt keine Sitzung (SW22)
        http_response_code(401);
        header('Content-Type: application/problem+json; charset=utf-8');
        echo json_encode(['status' => 401, 'code' => 'spielwiese.eintreten', 'title' => 'Bitte zuerst die Startseite bestätigen', 'startseite' => '/'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        durchreichen(new Kumero($k));
    }
} catch (\Throwable $e) {
    error_log('kumero-spielwiese: ' . $e->getMessage());
    headers_sent() || seite($k, 'Die Spielwiese ist gerade nicht erreichbar – vielleicht wird sie eben zurückgesetzt. Bitte in ein paar Minuten noch einmal versuchen.');
}
