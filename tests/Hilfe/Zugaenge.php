<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests\Hilfe;

use Kumero\Plattform\Webauthn\Base64Url;

/**
 * Gemeinsame Hilfen der Tests seit F114 (Nutzer gehören zum Mandanten): Mandant anlegen → Identität mit Rolle →
 * Passkey → Anmeldung. Die Testklasse stellt `$u` (Umgebung), `$cookie`, `http()` und `status()` bzw. `ok()` bereit.
 * Wer als System-Verwalter und im Mandanten arbeitet, braucht zwei Identitäten und wechselt die Anmeldung.
 * Nur synthetische Daten.
 */
trait Zugaenge
{
    /** @return mixed JSON der Antwort; prüft den Status */
    private function zugangErwartet(int $status, string $methode, string $pfad, ?array $json, string $was): mixed
    {
        $erg = $this->http($methode, $pfad, $json);
        // http() der Testklassen liefert die Antwort oder nur ihren Status, dazu das JSON
        $erhalten = is_int($erg[0]) ? $erg[0] : $erg[0]->getStatusCode();
        $this->gleich($status, $erhalten, $was . ' – ' . json_encode($erg[1], JSON_UNESCAPED_UNICODE));
        return $erg[1];
    }

    protected static function linkToken(string $ausgabe): string
    {
        preg_match('/#registrierung=([A-Za-z0-9_.-]+)/', $ausgabe, $m) ?: throw new \RuntimeException("Kein Link: $ausgabe");
        return $m[1];
    }

    protected static function angelegt(string $ausgabe): string
    {
        preg_match('/(?:Nutzer angelegt|Verwalter angelegt|Administrator eingesetzt): ([0-9a-f-]{36})/', $ausgabe, $m) ?: throw new \RuntimeException("Keine ID: $ausgabe");
        return $m[1];
    }

    /** Richtet über den Link aus der Ausgabe der Kommandozeile einen Passkey ein (ohne Anmeldung). */
    protected function passkeyEinrichten(string $ausgabe, int $alg = -7, ?string $bezeichnung = null): Authentifikator
    {
        $token = self::linkToken($ausgabe);
        $pk = new Authentifikator('kumero.test', Umgebung::ORIGIN, $alg);
        $this->cookie = null;
        $opt = $this->zugangErwartet(200, 'POST', '/api/v1/registrierung/optionen', ['token' => $token], 'Registrierungsoptionen');
        $pk->nutzerkennung = $opt['user']['id'];      // das Pseudonym; der Passkey legt es bei der Anmeldung vor (userHandle)
        $this->zugangErwartet(201, 'POST', '/api/v1/registrierung',
            ['token' => $token] + ($bezeichnung === null ? [] : ['bezeichnung' => $bezeichnung]) + $pk->registrieren(Base64Url::dekodieren($opt['challenge'])), 'Passkey registriert');
        return $pk;
    }

    /** Legt einen Mandanten an (mit seinem ersten Administrator, U5). @return string Ausgabe mit dem Link des Administrators */
    protected function mandantAnlegen(string $kennung, string $name): string
    {
        return $this->u->cli(['command' => 'mandant:anlegen', 'kennung' => $kennung, 'name' => $name, '--administrator' => true,
                              'vorname' => 'Adam', 'familienname' => 'Admin', 'email' => "adam@$kennung.example.org"]);
    }

    /**
     * Legt im Mandanten eine Identität mit Rollen an und registriert ihren Passkey.
     *
     * @param list<string> $rollen
     * @return array{0: string, 1: Authentifikator} Nutzer-ID und Passkey
     */
    protected function nutzer(string $mandant, string $vorname, string $familienname, array $rollen = ['Verantwortlicher'], int $alg = -7): array
    {
        $aus = $this->u->cli(['command' => 'nutzer:anlegen', 'mandant' => $mandant, 'vorname' => $vorname, 'familienname' => $familienname,
                              'email' => strtolower($vorname) . '@example.org', '--rolle' => $rollen]);
        return [self::angelegt($aus), $this->passkeyEinrichten($aus, $alg)];
    }

    /** Legt einen System-Verwalter an und registriert seinen Passkey. @return array{0: string, 1: Authentifikator} */
    protected function verwalter(string $name, int $alg = -7): array
    {
        $aus = $this->u->cli(['command' => 'verwalter:anlegen', 'name' => $name]);
        return [self::angelegt($aus), $this->passkeyEinrichten($aus, $alg)];
    }

    /** Meldet mit dem Passkey an; die bisherige Sitzung endet damit (ein Browser hat eine Sitzung, U3). @return array<string, mixed> Profil */
    protected function anmelden(Authentifikator $pk): array
    {
        $this->cookie = null;
        $opt = $this->zugangErwartet(200, 'POST', '/api/v1/anmeldung/optionen', null, 'Anmeldeoptionen');
        return $this->zugangErwartet(200, 'POST', '/api/v1/anmeldung', $pk->anmelden(Base64Url::dekodieren($opt['challenge'])), 'angemeldet');
    }
}
