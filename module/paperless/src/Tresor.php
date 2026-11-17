<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Paperless;

/**
 * Verschlüsselung des Paperless-Tokens (PAPERLESS.md L3, L3a). Dauerhaft: Schlüssel aus der Passphrase
 * des Nutzers (Argon2id), XSalsa20-Poly1305. Für die Sitzung: ein Zufallsschlüssel, den nur der Browser
 * als Cookie hält. Weder Passphrase noch Sitzungsschlüssel werden je gespeichert oder protokolliert.
 */
final class Tresor
{
    public const COOKIE = '__Host-kumero-paperless';
    public const PASSPHRASE_MIN = 10;

    /** @return array{chiffre: string, nonce: string, salz: string, ops: int, speicher: int} */
    public static function verschliessen(string $token, #[\SensitiveParameter] string $passphrase): array
    {
        $salz = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
        $speicher = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $s = self::ableiten($passphrase, $salz, $ops, $speicher);
        return ['chiffre' => sodium_crypto_secretbox($token, $nonce, $s), 'nonce' => $nonce, 'salz' => $salz, 'ops' => $ops, 'speicher' => $speicher];
    }

    /** Token aus der dauerhaften Ablage oder null, wenn die Passphrase nicht passt. */
    public static function oeffnen(string $chiffre, string $nonce, string $salz, int $ops, int $speicher, #[\SensitiveParameter] string $passphrase): ?string
    {
        $t = sodium_crypto_secretbox_open($chiffre, $nonce, self::ableiten($passphrase, $salz, $ops, $speicher));
        return $t === false ? null : $t;
    }

    public static function sitzungsschluessel(): string
    {
        return random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /** @return array{chiffre: string, nonce: string} */
    public static function fuerSitzung(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $schluessel): array
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return ['chiffre' => sodium_crypto_secretbox($token, $nonce, $schluessel), 'nonce' => $nonce];
    }

    public static function ausSitzung(string $chiffre, string $nonce, #[\SensitiveParameter] string $schluessel): ?string
    {
        if (strlen($schluessel) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return null;
        }
        $t = sodium_crypto_secretbox_open($chiffre, $nonce, $schluessel);
        return $t === false ? null : $t;
    }

    private static function ableiten(#[\SensitiveParameter] string $passphrase, string $salz, int $ops, int $speicher): string
    {
        return sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passphrase, $salz, $ops, $speicher, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }
}
