<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Plattform;

use Kumero\Plattform\Webauthn\Pruefer;

/**
 * Die wirksame Adresse einer Anfrage (VERWALTUNG.md Abschnitt 18, DM13): der Origin, unter dem kumero für diese
 * Anfrage erreichbar ist, mit allem, was daran hängt – RP-ID der Passkeys, erlaubte Herkunft, Anfang von Links.
 *
 * Es gibt genau diese eine Stelle. Kein Controller und kein Modul liest dafür die Konfiguration; sie fragen die
 * Anfrage (`Anfrage::adresse()`, `Anfrage::rpId()`, `Anfrage::pruefer()`, `Anfrage::link()`).
 */
final class Adresse
{
    private function __construct(
        /** Origin ohne Schrägstrich am Ende, z. B. https://kumero.example.org */
        public readonly string $origin,
        /** Hostname des Origins – die RP-ID der Passkeys (WebAuthn) */
        public readonly string $rpId,
        /** Kennung des Mandanten, dem diese Adresse gehört; null = Adresse der Installation */
        public readonly ?string $mandant = null,
    ) {
    }

    /** Die Adresse der Installation aus `/etc/kumero/kumero.php`. */
    public static function derInstallation(Konfiguration $k): self
    {
        return new self($k->adresse, $k->rpId);
    }

    /** Die Adresse eines Mandanten mit eigener Domain (immer https, ohne Port). */
    public static function desMandanten(string $hostname, string $mandant): self
    {
        return new self('https://' . $hostname, $hostname, $mandant);
    }

    /** Prüfer für Passkeys unter dieser Adresse: Herkunft und RP-ID sind genau diese (F68). */
    public function pruefer(): Pruefer
    {
        return new Pruefer($this->rpId, [$this->origin]);
    }

    /** Absoluter Link unter dieser Adresse; $pfad beginnt mit einem Schrägstrich. */
    public function link(string $pfad): string
    {
        return $this->origin . $pfad;
    }
}
