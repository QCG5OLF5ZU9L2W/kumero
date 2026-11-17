<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Plattform\Webauthn;

/**
 * Eigene, kleine WebAuthn-Prüfung (ANWENDUNG.md E3, F68).
 *
 * Unterstützt ES256 (COSE -7, P-256) und EdDSA (COSE -8, Ed25519). Registrierung
 * mit Attestation „none“: Eine mitgelieferte Attestation wird nicht ausgewertet,
 * der Passkey wird allein über die Registrierung durch den Nutzer bzw. den
 * Registrierungslink vertraut. Nutzerverifikation (UV) ist immer Pflicht.
 *
 * Alle Binärwerte sind rohe Bytes; base64url dekodiert der Aufrufer.
 */
final class Pruefer
{
    public const ES256 = -7;
    public const EDDSA = -8;

    private const FLAG_UP = 0x01;
    private const FLAG_UV = 0x04;
    private const FLAG_AT = 0x40;
    private const FLAG_ED = 0x80;

    /** @param list<string> $origins erlaubte Origins, z. B. ["https://kumero.example.org"] */
    public function __construct(private readonly string $rpId, private readonly array $origins)
    {
    }

    /**
     * Prüft eine Registrierung (navigator.credentials.create).
     *
     * @return array{credential_id: string, public_key: string, alg: int, zaehler: int}
     */
    public function registrierung(string $clientDataJson, string $attestationObject, string $challenge): array
    {
        $this->clientDataPruefen($clientDataJson, 'webauthn.create', $challenge);

        $att = Cbor::dekodieren($attestationObject);
        if (!is_array($att) || !isset($att['authData'], $att['fmt']) || !is_string($att['authData'])) {
            throw new WebauthnFehler('attestationObject unvollständig');
        }
        $auth = $this->authDataLesen($att['authData'], true);
        if ($auth['credential_id'] === null || $auth['public_key'] === null) {
            throw new WebauthnFehler('Registrierung ohne Schlüssel');
        }
        $alg = self::schluesselPruefen($auth['public_key']);
        return [
            'credential_id' => $auth['credential_id'],
            'public_key' => $auth['public_key'],
            'alg' => $alg,
            'zaehler' => $auth['zaehler'],
        ];
    }

    /**
     * Prüft eine Assertion (navigator.credentials.get) und liefert den neuen Signaturzähler.
     * Ob der Zähler gestiegen ist, prüft zusätzlich die Datenbank (F72).
     */
    public function assertion(string $clientDataJson, string $authenticatorData, string $signatur,
                              string $challenge, string $publicKeyCose, int $alterZaehler): int
    {
        $this->clientDataPruefen($clientDataJson, 'webauthn.get', $challenge);
        $auth = $this->authDataLesen($authenticatorData, false);
        $daten = $authenticatorData . hash('sha256', $clientDataJson, true);
        if (!self::signaturGueltig($publicKeyCose, $daten, $signatur)) {
            throw new WebauthnFehler('Signatur ungültig');
        }
        if (($auth['zaehler'] > 0 || $alterZaehler > 0) && $auth['zaehler'] <= $alterZaehler) {
            throw new WebauthnFehler('Signaturzähler nicht gestiegen');
        }
        return $auth['zaehler'];
    }

    private function clientDataPruefen(string $json, string $typ, string $challenge): void
    {
        try {
            $cd = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new WebauthnFehler('clientDataJSON ist kein JSON');
        }
        if (!is_array($cd) || ($cd['type'] ?? null) !== $typ) {
            throw new WebauthnFehler("clientDataJSON: Typ muss $typ sein");
        }
        $erhalten = Base64Url::dekodieren((string) ($cd['challenge'] ?? ''));
        if (strlen($challenge) < 32 || !hash_equals($challenge, $erhalten)) {
            throw new WebauthnFehler('Challenge stimmt nicht');
        }
        if (!in_array($cd['origin'] ?? null, $this->origins, true)) {
            throw new WebauthnFehler('Origin nicht erlaubt');
        }
        if (($cd['crossOrigin'] ?? false) === true) {
            throw new WebauthnFehler('crossOrigin nicht erlaubt');
        }
    }

    /** @return array{zaehler: int, credential_id: ?string, public_key: ?string} */
    private function authDataLesen(string $d, bool $mitSchluessel): array
    {
        if (strlen($d) < 37) {
            throw new WebauthnFehler('authenticatorData zu kurz');
        }
        if (!hash_equals(hash('sha256', $this->rpId, true), substr($d, 0, 32))) {
            throw new WebauthnFehler('RP-ID-Hash stimmt nicht');
        }
        $flags = ord($d[32]);
        if (!($flags & self::FLAG_UP)) {
            throw new WebauthnFehler('Nutzer nicht anwesend (UP)');
        }
        if (!($flags & self::FLAG_UV)) {
            throw new WebauthnFehler('Nutzer nicht verifiziert (UV)');
        }
        $zaehler = unpack('N', substr($d, 33, 4))[1];
        $pos = 37;
        $cid = $key = null;
        if ($mitSchluessel) {
            if (!($flags & self::FLAG_AT)) {
                throw new WebauthnFehler('Keine Schlüsseldaten (AT)');
            }
            if (strlen($d) < $pos + 18) {
                throw new WebauthnFehler('authenticatorData zu kurz');
            }
            $pos += 16; // AAGUID – bei Attestation „none“ ohne Bedeutung
            $laenge = unpack('n', substr($d, $pos, 2))[1];
            $pos += 2;
            if ($laenge < 16 || $laenge > 1023 || strlen($d) < $pos + $laenge) {
                throw new WebauthnFehler('Credential-ID ungültig');
            }
            $cid = substr($d, $pos, $laenge);
            $pos += $laenge;
            $start = $pos;
            Cbor::lesen($d, $pos);
            $key = substr($d, $start, $pos - $start);
        } elseif ($flags & self::FLAG_AT) {
            throw new WebauthnFehler('Unerwartete Schlüsseldaten in der Assertion');
        }
        if ($flags & self::FLAG_ED) {
            Cbor::lesen($d, $pos); // Erweiterungen werden nicht ausgewertet
        }
        if ($pos !== strlen($d)) {
            throw new WebauthnFehler('authenticatorData mit überzähligen Bytes');
        }
        return ['zaehler' => $zaehler, 'credential_id' => $cid, 'public_key' => $key];
    }

    /** Prüft einen COSE-Schlüssel und liefert seinen Algorithmus. */
    public static function schluesselPruefen(string $cose): int
    {
        $k = Cbor::dekodieren($cose);
        if (!is_array($k)) {
            throw new WebauthnFehler('COSE-Schlüssel ungültig');
        }
        $alg = $k[3] ?? null;
        if ($alg === self::ES256) {
            if (($k[1] ?? null) !== 2 || ($k[-1] ?? null) !== 1
                || !is_string($k[-2] ?? null) || strlen($k[-2]) !== 32
                || !is_string($k[-3] ?? null) || strlen($k[-3]) !== 32) {
                throw new WebauthnFehler('ES256-Schlüssel ungültig');
            }
            if (openssl_pkey_get_public(self::es256Pem($k[-2], $k[-3])) === false) {
                throw new WebauthnFehler('ES256-Punkt nicht auf der Kurve');
            }
            return self::ES256;
        }
        if ($alg === self::EDDSA) {
            if (($k[1] ?? null) !== 1 || ($k[-1] ?? null) !== 6
                || !is_string($k[-2] ?? null) || strlen($k[-2]) !== 32) {
                throw new WebauthnFehler('Ed25519-Schlüssel ungültig');
            }
            return self::EDDSA;
        }
        throw new WebauthnFehler('Algorithmus nicht unterstützt (nur ES256 und EdDSA)');
    }

    public static function signaturGueltig(string $cose, string $daten, string $signatur): bool
    {
        $alg = self::schluesselPruefen($cose);
        $k = Cbor::dekodieren($cose);
        if ($alg === self::ES256) {
            return openssl_verify($daten, $signatur, self::es256Pem($k[-2], $k[-3]), OPENSSL_ALGO_SHA256) === 1;
        }
        if (strlen($signatur) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($signatur, $daten, $k[-2]);
    }

    private static function es256Pem(string $x, string $y): string
    {
        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
