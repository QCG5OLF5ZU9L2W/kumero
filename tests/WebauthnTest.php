<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

use Kumero\Plattform\Webauthn\Base64Url;
use Kumero\Plattform\Webauthn\Cbor;
use Kumero\Plattform\Webauthn\Pruefer;
use Kumero\Plattform\Webauthn\WebauthnFehler;
use Kumero\Tests\Hilfe\Authentifikator;

final class WebauthnTest extends Rahmen
{
    private const RP = 'kumero.test';
    private const ORIGIN = 'https://kumero.test';

    private function pruefer(): Pruefer
    {
        return new Pruefer(self::RP, [self::ORIGIN]);
    }

    /** @return array{0: Authentifikator, 1: array} */
    private function registriert(int $alg): array
    {
        $a = new Authentifikator(self::RP, self::ORIGIN, $alg);
        $ch = random_bytes(32);
        $r = $a->registrieren($ch);
        $erg = $this->pruefer()->registrierung(Base64Url::dekodieren($r['response']['clientDataJSON']),
            Base64Url::dekodieren($r['response']['attestationObject']), $ch);
        return [$a, $erg];
    }

    private function anmelden(Authentifikator $a, array $reg, string $ch, int $alterZaehler = 0, ?array $as = null): int
    {
        $as ??= $a->anmelden($ch);
        return $this->pruefer()->assertion(Base64Url::dekodieren($as['response']['clientDataJSON']),
            Base64Url::dekodieren($as['response']['authenticatorData']), Base64Url::dekodieren($as['response']['signature']),
            $ch, $reg['public_key'], $alterZaehler);
    }

    public function testCbor(): void
    {
        $this->gleich([1 => 2, 'a' => [1, -1, 'x'], 3 => true], Cbor::dekodieren(Authentifikator::cbor([1 => 2, 'a' => [1, -1, 'x'], 3 => true])), 'CBOR hin und zurück');
        $this->gleich(-500, Cbor::dekodieren("\x39\x01\xf3"), 'negative Zahl mit 2 Byte');
        $this->wirft(WebauthnFehler::class, fn () => Cbor::dekodieren("\xa2\x01\x01\x01\x02"), 'doppelter Schlüssel', 'doppelt');
        $this->wirft(WebauthnFehler::class, fn () => Cbor::dekodieren("\x5f"), 'indefinite Länge', 'indefinite');
        $this->wirft(WebauthnFehler::class, fn () => Cbor::dekodieren("\xf9\x3c\x00"), 'Gleitkomma', 'Gleitkomma');
        $this->wirft(WebauthnFehler::class, fn () => Cbor::dekodieren("\x01\x02"), 'Reste', 'überzählig');
    }

    public function testRegistrierungUndAnmeldungEs256(): void
    {
        [$a, $reg] = $this->registriert(-7);
        $this->gleich(-7, $reg['alg'], 'Algorithmus ES256');
        $this->gleich($a->credentialId, $reg['credential_id'], 'Credential-ID');
        $this->gleich(1, $this->anmelden($a, $reg, random_bytes(32)), 'erste Anmeldung, Zähler 1');
        $this->gleich(2, $this->anmelden($a, $reg, random_bytes(32), 1), 'zweite Anmeldung');
    }

    public function testRegistrierungUndAnmeldungEdDsa(): void
    {
        [$a, $reg] = $this->registriert(-8);
        $this->gleich(-8, $reg['alg'], 'Algorithmus EdDSA');
        $this->gleich(1, $this->anmelden($a, $reg, random_bytes(32)), 'Anmeldung mit Ed25519');
    }

    public function testAbweisungen(): void
    {
        [$a, $reg] = $this->registriert(-7);
        $ch = random_bytes(32);
        $this->wirft(WebauthnFehler::class, fn () => $this->anmelden($a, $reg, $ch, 0, $a->anmelden(random_bytes(32))), 'fremde Challenge', 'Challenge');
        $this->wirft(WebauthnFehler::class, fn () => $this->anmelden($a, $reg, $ch, 0, $a->anmelden($ch, 0x05, 'https://boese.test')), 'fremder Origin', 'Origin');
        $this->wirft(WebauthnFehler::class, fn () => $this->anmelden($a, $reg, $ch, 0, $a->anmelden($ch, 0x01)), 'ohne Nutzerverifikation', 'UV');
        $this->wirft(WebauthnFehler::class, fn () => $this->anmelden($a, $reg, $ch, 99), 'Zähler nicht gestiegen', 'zähler');

        $as = $a->anmelden($ch);
        $as['response']['signature'] = Base64Url::kodieren(str_repeat("\x30", 70));
        $this->wirft(WebauthnFehler::class, fn () => $this->anmelden($a, $reg, $ch, 0, $as), 'falsche Signatur', 'Signatur');

        $as = $a->anmelden($ch);
        $auth = Base64Url::dekodieren($as['response']['authenticatorData']);
        $auth[0] = chr(ord($auth[0]) ^ 1);
        $as['response']['authenticatorData'] = Base64Url::kodieren($auth);
        $this->wirft(WebauthnFehler::class, fn () => $this->anmelden($a, $reg, $ch, 0, $as), 'fremde RP-ID', 'RP-ID');

        $fremd = new Authentifikator(self::RP, self::ORIGIN, -7);
        $this->wirft(WebauthnFehler::class, fn () => $this->anmelden($fremd, $reg, $ch), 'anderer Schlüssel', 'Signatur');
    }

    public function testZaehlerlosePasskeys(): void
    {
        $a = new Authentifikator(self::RP, self::ORIGIN, -7, false);
        $ch = random_bytes(32);
        $r = $a->registrieren($ch);
        $reg = $this->pruefer()->registrierung(Base64Url::dekodieren($r['response']['clientDataJSON']),
            Base64Url::dekodieren($r['response']['attestationObject']), $ch);
        $this->gleich(0, $this->anmelden($a, $reg, random_bytes(32)), 'Plattform-Passkey ohne Zähler (immer 0) wird angenommen');
    }

    public function testRegistrierungAbweisungen(): void
    {
        $a = new Authentifikator(self::RP, self::ORIGIN, -7);
        $ch = random_bytes(32);
        $r = $a->registrieren($ch, 0x41); // ohne UV
        $this->wirft(WebauthnFehler::class, fn () => $this->pruefer()->registrierung(
            Base64Url::dekodieren($r['response']['clientDataJSON']), Base64Url::dekodieren($r['response']['attestationObject']), $ch),
            'Registrierung ohne UV', 'UV');
        $r = $a->registrieren($ch);
        $this->wirft(WebauthnFehler::class, fn () => $this->pruefer()->registrierung(
            Base64Url::dekodieren($r['response']['clientDataJSON']), Base64Url::dekodieren($r['response']['attestationObject']), random_bytes(32)),
            'Registrierung mit fremder Challenge', 'Challenge');
        $rsa = Authentifikator::cbor([1 => 3, 3 => -257, -1 => (object) ['b' => 'x'], -2 => (object) ['b' => 'y']]);
        $this->wirft(WebauthnFehler::class, fn () => Pruefer::schluesselPruefen($rsa), 'RSA nicht unterstützt (E3)', 'nicht unterstützt');
        $kaputt = Authentifikator::cbor([1 => 2, 3 => -7, -1 => 1, -2 => (object) ['b' => str_repeat("\1", 32)], -3 => (object) ['b' => str_repeat("\2", 32)]]);
        $this->wirft(WebauthnFehler::class, fn () => Pruefer::schluesselPruefen($kaputt), 'Punkt nicht auf der Kurve', 'Kurve');
    }
}
