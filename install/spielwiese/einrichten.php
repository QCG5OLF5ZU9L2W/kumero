<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Einrichtung der Spielwiese (SPIELWIESE.md Abschnitt 3; F183–F186) – ruft install/spielwiese.sh einmal auf, als root.
// Arbeitet nur über das, was kumero jedem anbietet: seine Kommandozeile und seine Schnittstelle. Zwei Zuweisungen von
// Rollen gehen über die Funktionen der Datenbank, weil die Kommandozeile dafür keinen Befehl hat.
//   php einrichten.php alles      Mandant, Buch, Module, Spiel-Identität mit allen Rollen, Passkey, Kontenrahmen, Bestand
//   php einrichten.php anmelden   prüft, ob sich der Pförtner anmelden kann
// Umgebung: SPIELWIESE_KONFIG, SPIELWIESE_KUMERO (Befehl der Kommandozeile), SPIELWIESE_PSQL (psql als Verwalter der Datenbank),
//           SPIELWIESE_KONTENRAHMEN (Pfad der Datei euer-kontenrahmen.csv)
declare(strict_types=1);

namespace Kumero\Spielwiese;

require __DIR__ . '/lib.php';

function melde(string $text): void
{
    fwrite(STDOUT, "[spielwiese] $text\n");
}

/** @param list<string> $argumente */
function kumero(array $argumente): string
{
    $befehl = (getenv('SPIELWIESE_KUMERO') ?: 'php /var/www/kumero/bin/kumero') . ' ' . implode(' ', array_map('escapeshellarg', $argumente)) . ' 2>&1';
    exec($befehl, $zeilen, $status);
    $aus = implode("\n", $zeilen);
    // Die Ausgabe eines gescheiterten Befehls kann einen Registrierungslink tragen – er wird nie gezeigt
    $status === 0 || throw new \RuntimeException("bin/kumero {$argumente[0]} ist gescheitert:\n" . preg_replace('/#registrierung=\S+/', '#registrierung=…', $aus));
    return $aus;
}

function psql(string $datenbank, string $sql): string
{
    $befehl = (getenv('SPIELWIESE_PSQL') ?: 'runuser -u postgres -- psql') . ' -X -q -At -v ON_ERROR_STOP=1 -d ' . escapeshellarg($datenbank) . ' -c ' . escapeshellarg($sql) . ' 2>&1';
    exec($befehl, $zeilen, $status);
    $status === 0 || throw new \RuntimeException("psql ($datenbank) ist gescheitert: " . implode(' ', $zeilen));
    return implode("\n", $zeilen);
}

/** Ruft die Schnittstelle als Spiel-Identität und verlangt einen der Status. @param list<int> $erwartet */
function api(Kumero $kumero, string $cookie, string $methode, string $pfad, ?array $daten, array $erwartet, string $was): mixed
{
    [$status, $json] = $kumero->json($methode, $pfad, $daten, $cookie);
    in_array($status, $erwartet, true) || throw new \RuntimeException("$was: Status $status – " . json_encode($json, JSON_UNESCAPED_UNICODE));
    return $json;
}

/** Zeilen einer Buchung aus der Kurzform der Steuerdatei. @return list<array{konto: string, seite: string, betrag: string}> */
function zeilen(array $b): array
{
    $z = fn (string $konto, string $seite, float|string $betrag) => ['konto' => $konto, 'seite' => $seite, 'betrag' => number_format((float) $betrag, 2, '.', '')];
    [, , , , $art] = $b;
    if ($art === 'um') {
        return [$z($b[6], 'S', $b[5]), $z($b[7], 'H', $b[5])];
    }
    if ($art === 'frei') {
        return array_map(fn ($x) => $z($x[0], $x[1], $x[2]), $b[5]);
    }
    $brutto = (float) $b[5];
    $satz = $art === 'ein' ? 19 : (int) $b[8];
    $netto = round($brutto / (1 + $satz / 100), 2);
    $steuer = round($brutto - $netto, 2);
    if ($art === 'ein') {
        return [$z($b[7], 'S', $brutto), $z($b[6], 'H', $netto), $z('7500', 'H', $steuer)];
    }
    return array_values(array_filter([$z($b[6], 'S', $netto), $satz > 0 ? $z('7100', 'S', $steuer) : null, $z($b[7], 'H', $brutto)]));
}

/** @param array<string, mixed> $k */
function alles(array $k): void
{
    $m = $k['mandant'];
    $buch = $k['buch'];
    $bestand = require __DIR__ . '/bestand.php';
    $kumero = new Kumero($k);

    melde("Mandant „{$k['mandant_name']}“ mit der Spiel-Identität als Administrator");
    $aus = kumero(['mandant:anlegen', $m, $k['mandant_name'], '--administrator', 'Mara', 'Muster', 'mara.muster@example.org']);
    preg_match('/Administrator eingesetzt: ([0-9a-f-]{36})/', $aus, $id) ?: throw new \RuntimeException('Die Kennung der Spiel-Identität fehlt in der Ausgabe von mandant:anlegen');
    preg_match('/#registrierung=([A-Za-z0-9_.-]+)/', $aus, $link) ?: throw new \RuntimeException('Der Registrierungslink fehlt in der Ausgabe von mandant:anlegen');
    $nutzer = $id[1];

    melde("Buch „{$bestand['buch']}“ und alle Module");
    kumero(['buch:anlegen', $m, $buch, $bestand['buch'], '--art', 'euer']);
    kumero(['modul:alle']);

    melde('Jede Rolle für die Spiel-Identität (SW8)');
    $rollenBuch = psql("kumero_{$m}__{$buch}", "SELECT count(kern.zuweisen('system:spielwiese', 'nutzer:$nutzer', name)) FROM kern.rolle WHERE name <> 'Administrator'");
    $rollenMandant = psql("kumero_$m", "SELECT count(mandant.zuweisen('system:spielwiese', 'nutzer:$nutzer', name)) FROM mandant.rolle WHERE name <> 'Administrator'");
    melde("  im Buch: $rollenBuch, am Mandanten: $rollenMandant");

    melde('Passkey der Spiel-Identität (SW19)');
    $passkey = Passkey::neu($k['adresse']);
    [$s, $optionen] = $kumero->json('POST', '/api/v1/registrierung/optionen', ['token' => $link[1]]);
    $s === 200 || throw new \RuntimeException("Registrierungsoptionen: Status $s");
    [$s, $antwort] = $kumero->json('POST', '/api/v1/registrierung', ['token' => $link[1], 'bezeichnung' => 'Pförtner der Spielwiese'] + $passkey->registrieren($optionen));
    $s === 201 || throw new \RuntimeException("Passkey registrieren: Status $s – " . json_encode($antwort, JSON_UNESCAPED_UNICODE));
    $passkey->speichern($k['passkey']);

    melde('EÜR-Kontenrahmen');
    $csv = getenv('SPIELWIESE_KONTENRAHMEN') ?: '/var/www/kumero/module/kontenrahmen/referenz/euer-kontenrahmen.csv';
    $aus = kumero(['kontenrahmen:einlesen', $csv, '--rahmen', 'EUER', '--name', 'EÜR-Kontenrahmen']);
    preg_match('/^Vorlage: (\S+)$/m', $aus, $vorlage) ?: throw new \RuntimeException("Keine Vorlage aus kontenrahmen:einlesen:\n$aus");
    melde('  ' . trim(kumero(['kontenrahmen:anwenden', $vorlage[1], $m, '--buch', $buch])));

    melde('Bestand: ' . count($bestand['buchungen']) . ' Buchungen des erfundenen Betriebs');
    $cookie = Kumero::cookieAus($kumero->anmelden());
    $p = fn (string $pfad) => $kumero->buchPfad($pfad);
    $jahr = date('Y');
    api($kumero, $cookie, 'POST', $p('/geschaeftsjahre'), ['kennung' => $jahr, 'beginn' => "$jahr-01-01", 'ende' => "$jahr-12-31"], [201], 'Geschäftsjahr');
    api($kumero, $cookie, 'POST', $p('/nummernkreise'), ['kuerzel' => 'BU', 'bezeichnung' => 'Buchungen'], [201], 'Nummernkreis');
    api($kumero, $cookie, 'PUT', $p("/nummernkreise/BU/jahre/$jahr"), ['vorlage' => 'BU-{JJJJ}-{NR:5}', 'zuruecksetzen' => true], [200], 'Nummernkreis festlegen');
    foreach ($bestand['buchungsarten'] as $kuerzel => $bezeichnung) {
        api($kumero, $cookie, 'POST', $p('/buchungsarten'), ['kuerzel' => $kuerzel, 'bezeichnung' => $bezeichnung, 'nummernkreis' => 'BU'], [201], "Buchungsart $kuerzel");
    }
    api($kumero, $cookie, 'PUT', $p('/buchen/steuerkonten'), ['saetze' => $bestand['steuerkonten']], [200, 204], 'Steuerkonten');
    $nachBeleg = [];
    foreach ($bestand['buchungen'] as $b) {
        [$tag, $art, $beleg, $text, $form] = $b;
        if ($form === 'storno') {
            $storno = api($kumero, $cookie, 'POST', $p("/buchungen/{$nachBeleg[$b[5]]}/storno"), ['buchungsdatum' => "$jahr-$tag", 'text' => $text], [201], "Storno $beleg");
            $kumero->ruf('POST', $p("/buchungen/{$storno['id']}/vorbereiten"), null, ['If-Match: *', 'Accept: application/json', 'Idempotency-Key: ' . bin2hex(random_bytes(16))], $cookie);
            api($kumero, $cookie, 'POST', $p('/freigaben'), ['buchungen' => [$storno['id']]], [200], "Storno $beleg festschreiben");
            continue;
        }
        $neu = api($kumero, $cookie, 'POST', $p('/buchungen'), ['buchungsart' => $art, 'belegdatum' => "$jahr-$tag", 'buchungsdatum' => "$jahr-$tag",
            'belegnummer' => $beleg, 'text' => $text, 'zeilen' => zeilen($b), 'vorbereiten' => true], [201], "Buchung $beleg");
        api($kumero, $cookie, 'POST', $p('/freigaben'), ['buchungen' => [$neu['id']]], [200], "Buchung $beleg festschreiben");
        $nachBeleg[$beleg] = $neu['id'];
    }

    $pl = $k['paperless'] ?? null;
    if (is_array($pl) && ($pl['adresse'] ?? '') !== '' && ($pl['token'] ?? '') !== '' && ($k['paperless_passphrase'] ?? '') !== '') {
        melde('Paperless-Zugang der Spiel-Identität (SW3, SW9)');
        api($kumero, $cookie, 'POST', $p('/paperless/verbindungen'), ['bezeichnung' => 'Paperless der Spielwiese', 'adresse' => $pl['adresse'],
            'token' => $pl['token'], 'passphrase' => $k['paperless_passphrase']] + (($pl['tag_offen'] ?? '') === '' ? [] : ['tag_offen' => $pl['tag_offen']]), [200, 201], 'Paperless verbinden');
    } else {
        melde('Paperless: nicht verbunden (keine Angaben in der Konfiguration)');
    }
    melde('Fertig.');
}

try {
    $k = konfiguration();
    match ($argv[1] ?? '') {
        'alles' => alles($k),
        'anmelden' => melde('Anmeldung geht: ' . (strlen((new Kumero($k))->anmelden()) > 20 ? 'ja' : 'nein')),
        default => throw new \RuntimeException('Aufruf: php einrichten.php alles | anmelden'),
    };
} catch (\Throwable $e) {
    fwrite(STDERR, '[spielwiese] ABBRUCH: ' . $e->getMessage() . "\n");
    exit(1);
}
