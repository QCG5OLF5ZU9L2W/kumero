<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests\Hilfe;

use Kumero\Plattform\Webauthn\Base64Url;

/**
 * Software-Authentifikator für Tests: erzeugt Registrierungen und Assertionen
 * wie ein echter Passkey (ES256 oder EdDSA), mit synthetischen Schlüsseln.
 */
final class Authentifikator
{
    public string $credentialId;
    public int $zaehler = 0;
    private mixed $privat;
    private string $cose;

    public function __construct(public readonly string $rpId, public readonly string $origin,
                                public readonly int $alg = -7, public bool $zaehlerFuehren = true)
    {
        $this->credentialId = random_bytes(32);
        if ($alg === -7) {
            $this->privat = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            $d = openssl_pkey_get_details($this->privat)['ec'];
            $this->cose = self::cbor([1 => 2, 3 => -7, -1 => 1,
                -2 => self::bytes(str_pad($d['x'], 32, "\0", STR_PAD_LEFT)),
                -3 => self::bytes(str_pad($d['y'], 32, "\0", STR_PAD_LEFT))]);
        } else {
            $paar = sodium_crypto_sign_keypair();
            $this->privat = sodium_crypto_sign_secretkey($paar);
            $this->cose = self::cbor([1 => 1, 3 => -8, -1 => 6, -2 => self::bytes(sodium_crypto_sign_publickey($paar))]);
        }
    }

    /** @return array{id: string, response: array{attestationObject: string, clientDataJSON: string}} */
    public function registrieren(string $challenge, int $flags = 0x45): array
    {
        $cd = json_encode(['type' => 'webauthn.create', 'challenge' => Base64Url::kodieren($challenge),
            'origin' => $this->origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
        $auth = hash('sha256', $this->rpId, true) . chr($flags) . pack('N', 0)
            . str_repeat("\0", 16) . pack('n', strlen($this->credentialId)) . $this->credentialId . $this->cose;
        $att = self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => self::bytes($auth)]);
        return ['id' => Base64Url::kodieren($this->credentialId),
                'response' => ['attestationObject' => Base64Url::kodieren($att), 'clientDataJSON' => Base64Url::kodieren($cd)]];
    }

    /** @return array{id: string, response: array{authenticatorData: string, clientDataJSON: string, signature: string}} */
    public function anmelden(string $challenge, int $flags = 0x05, ?string $origin = null): array
    {
        if ($this->zaehlerFuehren) {
            $this->zaehler++;
        }
        $cd = json_encode(['type' => 'webauthn.get', 'challenge' => Base64Url::kodieren($challenge),
            'origin' => $origin ?? $this->origin], JSON_UNESCAPED_SLASHES);
        $auth = hash('sha256', $this->rpId, true) . chr($flags) . pack('N', $this->zaehler);
        $daten = $auth . hash('sha256', $cd, true);
        if ($this->alg === -7) {
            openssl_sign($daten, $sig, $this->privat, OPENSSL_ALGO_SHA256);
        } else {
            $sig = sodium_crypto_sign_detached($daten, $this->privat);
        }
        return ['id' => Base64Url::kodieren($this->credentialId),
                'response' => ['authenticatorData' => Base64Url::kodieren($auth),
                               'clientDataJSON' => Base64Url::kodieren($cd), 'signature' => Base64Url::kodieren($sig)]];
    }

    public function cose(): string
    {
        return $this->cose;
    }

    // --- kleiner CBOR-Encoder (nur für Tests) ---------------------------------
    private static function bytes(string $s): object
    {
        return (object) ['b' => $s];
    }

    private static function kopf(int $typ, int $n): string
    {
        if ($n < 24) {
            return chr(($typ << 5) | $n);
        }
        if ($n < 256) {
            return chr(($typ << 5) | 24) . chr($n);
        }
        if ($n < 65536) {
            return chr(($typ << 5) | 25) . pack('n', $n);
        }
        return chr(($typ << 5) | 26) . pack('N', $n);
    }

    public static function cbor(mixed $v): string
    {
        if (is_object($v)) {
            return self::kopf(2, strlen($v->b)) . $v->b;
        }
        if (is_bool($v)) {
            return $v ? "\xf5" : "\xf4";
        }
        if (is_int($v)) {
            return $v >= 0 ? self::kopf(0, $v) : self::kopf(1, -1 - $v);
        }
        if (is_string($v)) {
            return self::kopf(3, strlen($v)) . $v;
        }
        if (is_array($v)) {
            if (array_is_list($v) && $v !== []) {
                return self::kopf(4, count($v)) . implode('', array_map([self::class, 'cbor'], $v));
            }
            $s = self::kopf(5, count($v));
            foreach ($v as $k => $w) {
                $s .= self::cbor($k) . self::cbor($w);
            }
            return $s;
        }
        throw new \LogicException('Typ nicht unterstützt');
    }
}
