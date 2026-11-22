<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Pförtner der Spielwiese (SPIELWIESE.md, F183–F186): gemeinsame Teile für die Webseite und die Einrichtung.
// Steht mit Absicht für sich – nichts hier lädt Code von kumero, und nichts hiervon gehört in ein Release (SW17, SW18).
declare(strict_types=1);

namespace Kumero\Spielwiese;

function b64(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64zurueck(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/'), true);
}

/** @return array<string, mixed> */
function konfiguration(): array
{
    $datei = getenv('SPIELWIESE_KONFIG') ?: '/etc/kumero-spielwiese/pfoertner.php';
    $k = is_file($datei) ? require $datei : throw new \RuntimeException("Konfiguration fehlt: $datei");
    foreach (['adresse', 'intern', 'passkey', 'mandant', 'buch', 'app'] as $pflicht) {
        is_string($k[$pflicht] ?? null) && $k[$pflicht] !== '' || throw new \RuntimeException("Konfiguration: $pflicht fehlt");
    }
    return $k;
}

/**
 * Der Passkey der Spiel-Identität als Datei (SW19): ein Schlüsselpaar ES256, sonst nichts. Er tut, was ein
 * Authentifikator im Browser tut – er beantwortet die Aufgabe, die kumero stellt. kumero selbst weiß davon nichts.
 */
final class Passkey
{
    private function __construct(private array $d)
    {
    }

    public static function neu(string $adresse): self
    {
        $schluessel = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($schluessel, $pem);
        return new self(['adresse' => $adresse, 'kennung' => b64(random_bytes(32)), 'privat' => $pem, 'nutzer' => null]);
    }

    public static function laden(string $datei): self
    {
        $d = json_decode((string) @file_get_contents($datei), true);
        is_array($d) && isset($d['adresse'], $d['kennung'], $d['privat']) || throw new \RuntimeException("Passkey nicht lesbar: $datei");
        return new self($d);
    }

    public function speichern(string $datei): void
    {
        $neu = $datei . '.neu';
        file_put_contents($neu, json_encode($this->d, JSON_UNESCAPED_SLASHES));
        chmod($neu, 0600);
        rename($neu, $datei);
    }

    private function rpId(): string
    {
        return (string) parse_url($this->d['adresse'], PHP_URL_HOST);
    }

    private function daten(string $art, string $aufgabe): string
    {
        return json_encode(['type' => $art, 'challenge' => $aufgabe, 'origin' => $this->d['adresse'], 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $optionen Antwort von /registrierung/optionen @return array<string, mixed> */
    public function registrieren(array $optionen): array
    {
        $this->d['nutzer'] = $optionen['user']['id'];
        $ec = openssl_pkey_get_details(openssl_pkey_get_private($this->d['privat']))['ec'];
        $bytes = fn (string $s) => (strlen($s) < 24 ? chr(0x40 + strlen($s)) : "\x58" . chr(strlen($s))) . $s;
        // COSE-Schlüssel {1: 2, 3: -7, -1: 1, -2: x, -3: y}
        $cose = "\xa5\x01\x02\x03\x26\x20\x01\x21" . $bytes(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)) . "\x22" . $bytes(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT));
        $kennung = b64zurueck($this->d['kennung']);
        $auth = hash('sha256', $this->rpId(), true) . "\x45" . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($kennung)) . $kennung . $cose;
        // {"fmt": "none", "attStmt": {}, "authData": bytes}
        $att = "\xa3\x63fmt\x64none\x67attStmt\xa0\x68authData\x58" . chr(strlen($auth)) . $auth;
        return ['id' => $this->d['kennung'],
                'response' => ['attestationObject' => b64($att), 'clientDataJSON' => b64($this->daten('webauthn.create', $optionen['challenge']))]];
    }

    /** @param array<string, mixed> $optionen Antwort von /anmeldung/optionen @return array<string, mixed> */
    public function anmelden(array $optionen): array
    {
        $cd = $this->daten('webauthn.get', $optionen['challenge']);
        // Zähler 0: wie ein Authentifikator ohne Zähler – so können viele Anmeldungen nebeneinander laufen
        $auth = hash('sha256', $this->rpId(), true) . "\x05" . pack('N', 0);
        openssl_sign($auth . hash('sha256', $cd, true), $signatur, openssl_pkey_get_private($this->d['privat']), OPENSSL_ALGO_SHA256);
        return ['id' => $this->d['kennung'],
                'response' => ['authenticatorData' => b64($auth), 'clientDataJSON' => b64($cd), 'signature' => b64($signatur), 'userHandle' => $this->d['nutzer']]];
    }
}

/** Spricht mit kumero über dessen gewöhnliche Schnittstelle – wie ein Browser, nur von derselben Maschine aus. */
final class Kumero
{
    public const COOKIE = '__Host-kumero';

    /** @param array<string, mixed> $k */
    public function __construct(private array $k)
    {
    }

    /**
     * @param list<string> $kopf weitere Kopfzeilen
     * @return array{status: int, kopf: list<string>, koerper: string}
     */
    public function ruf(string $methode, string $pfad, ?string $koerper = null, array $kopf = [], ?string $cookie = null): array
    {
        $kopfzeilen = [];
        $c = curl_init(rtrim($this->k['intern'], '/') . $pfad);
        curl_setopt_array($c, [
            CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => array_merge(['Host: ' . parse_url($this->k['adresse'], PHP_URL_HOST) . (($p = parse_url($this->k['adresse'], PHP_URL_PORT)) ? ":$p" : ''),
                'Origin: ' . $this->k['adresse'], 'Expect:'], $kopf, $cookie === null ? [] : ["Cookie: $cookie"]),
            CURLOPT_HEADERFUNCTION => function ($c, string $zeile) use (&$kopfzeilen): int {
                if (str_contains($zeile, ':')) {
                    $kopfzeilen[] = rtrim($zeile, "\r\n");
                }
                return strlen($zeile);
            },
        ]);
        if ($koerper !== null) {
            curl_setopt($c, CURLOPT_POSTFIELDS, $koerper);
        }
        $antwort = curl_exec($c);
        if ($antwort === false) {
            throw new \RuntimeException('kumero antwortet nicht: ' . curl_error($c));
        }
        return ['status' => (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE), 'kopf' => $kopfzeilen, 'koerper' => (string) $antwort];
    }

    /** @return array{0: int, 1: mixed, 2: list<string>} Status, JSON, Kopfzeilen */
    public function json(string $methode, string $pfad, ?array $daten = null, ?string $cookie = null): array
    {
        $a = $this->ruf($methode, $pfad, $daten === null ? null : json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            array_merge(['Content-Type: application/json', 'Accept: application/json'], $methode === 'GET' ? [] : ['Idempotency-Key: ' . bin2hex(random_bytes(16))]), $cookie);
        return [$a['status'], json_decode($a['koerper'], true), $a['kopf']];
    }

    /** Die Zeile „Set-Cookie“ der Sitzung aus einer Antwort, oder null. */
    public static function sitzungszeile(array $kopf): ?string
    {
        foreach ($kopf as $zeile) {
            if (stripos($zeile, 'Set-Cookie: ' . self::COOKIE . '=') === 0) {
                return substr($zeile, 12);
            }
        }
        return null;
    }

    /**
     * Meldet die Spiel-Identität an (SW17) und entsperrt ihren Paperless-Zugang (SW9).
     *
     * @return string die Zeile „Set-Cookie“ der neuen Sitzung, unverändert zum Weiterreichen
     */
    public function anmelden(): string
    {
        $passkey = Passkey::laden($this->k['passkey']);
        [$s, $optionen] = $this->json('POST', '/api/v1/anmeldung/optionen');
        $s === 200 || throw new \RuntimeException("Anmeldeoptionen: $s");
        [$s, , $kopf] = $this->json('POST', '/api/v1/anmeldung', $passkey->anmelden($optionen));
        $zeile = self::sitzungszeile($kopf);
        $s === 200 && $zeile !== null || throw new \RuntimeException("Anmeldung der Spiel-Identität abgewiesen: $s");
        if (($this->k['paperless_passphrase'] ?? '') !== '') {
            // Scheitert das Entsperren, bleibt Paperless gesperrt – die Anmeldung gilt trotzdem
            $this->json('POST', $this->buchPfad('/paperless/entsperren'), ['passphrase' => $this->k['paperless_passphrase']], self::cookieAus($zeile));
        }
        return $zeile;
    }

    public static function cookieAus(string $zeile): string
    {
        return explode(';', $zeile, 2)[0];
    }

    public function buchPfad(string $pfad): string
    {
        return "/m/{$this->k['mandant']}/b/{$this->k['buch']}/api/v1$pfad";
    }
}
