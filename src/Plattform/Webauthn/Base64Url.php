<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Plattform\Webauthn;

final class Base64Url
{
    public static function kodieren(string $roh): string
    {
        return rtrim(strtr(base64_encode($roh), '+/', '-_'), '=');
    }

    public static function dekodieren(string $text): string
    {
        if ($text === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $text)) {
            throw new WebauthnFehler('Kein base64url');
        }
        $roh = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
        if ($roh === false) {
            throw new WebauthnFehler('Kein base64url');
        }
        return $roh;
    }
}
