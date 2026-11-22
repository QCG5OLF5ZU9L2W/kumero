<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Plattform;

use Doctrine\DBAL\Connection;
use Kumero\Api\V1\Kontext;
use Kumero\Api\V1\Problem;
use Symfony\Component\HttpFoundation\Request;

/** Eine laufende Anfrage, wie sie ein Controller erhält. */
final class Anfrage
{
    /** @var array<string, mixed>|null */
    private ?array $koerper = null;

    /** @param array<string, string> $param Pfadparameter */
    public function __construct(
        public readonly Request $http,
        public readonly Konfiguration $konfig,
        public readonly Datenbank $datenbank,
        public readonly array $param,
        public readonly ?Identitaet $identitaet,
        public readonly ?Kontext $kontext = null,
        public readonly ?Connection $db = null,
        public readonly string $anfrageId = '',
        /** Mandanten-Datenbank (Schema `mandant`) – auf den Ebenen Mandant und Buch gesetzt */
        public readonly ?Connection $mandantDb = null,
        /** Wirksame Adresse der Anfrage; ohne Angabe die der Installation */
        private ?Adresse $wirksam = null,
    ) {
    }

    /** Die wirksame Adresse dieser Anfrage – die einzige Quelle für RP-ID, Herkunft und Links (VERWALTUNG.md 18, DM13). */
    public function wirksameAdresse(): Adresse
    {
        return $this->wirksam ??= Adresse::derInstallation($this->konfig);
    }

    /** Origin, unter dem kumero für diese Anfrage erreichbar ist, z. B. https://kumero.example.org */
    public function adresse(): string
    {
        return $this->wirksameAdresse()->origin;
    }

    /** RP-ID der Passkeys für diese Anfrage (WebAuthn): der Hostname der wirksamen Adresse. */
    public function rpId(): string
    {
        return $this->wirksameAdresse()->rpId;
    }

    /** Prüfer für Passkeys: Herkunft und RP-ID der wirksamen Adresse (F68). */
    public function pruefer(): \Kumero\Plattform\Webauthn\Pruefer
    {
        return $this->wirksameAdresse()->pruefer();
    }

    /** Absoluter Link unter der wirksamen Adresse; $pfad beginnt mit einem Schrägstrich. */
    public function link(string $pfad): string
    {
        return $this->wirksameAdresse()->link($pfad);
    }

    public function verwaltung(): Connection
    {
        return $this->datenbank->verwaltung();
    }

    /** Mandanten-Kontext; nur auf Mandantenpfaden gesetzt. */
    public function kontext(): Kontext
    {
        return $this->kontext ?? throw new \LogicException('Kein Mandanten-Kontext');
    }

    /** Die Datenbank der Anfrage: auf der Ebene Buch die des Buchs (Schema `kern`), auf der Ebene Mandant die des Mandanten. */
    public function db(): Connection
    {
        return $this->db ?? throw new \LogicException('Keine Datenbank des Buchs bzw. Mandanten');
    }

    /** Mandanten-Datenbank: Nutzer, Administratoren, Bücherliste (BUECHER.md A2). */
    public function mandantDb(): Connection
    {
        return $this->mandantDb ?? throw new \LogicException('Keine Mandanten-Datenbank');
    }

    /** JSON-Körper als Objekt (leer erlaubt, wenn $pflicht = false). @return array<string, mixed> */
    public function json(bool $pflicht = true): array
    {
        if ($this->koerper !== null) {
            return $this->koerper;
        }
        $roh = $this->http->getContent();
        if ($roh === '') {
            if ($pflicht) {
                throw Problem::ungueltig('JSON-Körper fehlt');
            }
            return $this->koerper = [];
        }
        $typ = (string) $this->http->headers->get('Content-Type', '');
        if (!preg_match('#^application/(problem\+)?json\b#', $typ)) {
            throw new Problem(415, 'anfrage.medientyp', 'Erwartet wird Content-Type: application/json');
        }
        try {
            $w = json_decode($roh, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw Problem::ungueltig('Körper ist kein gültiges JSON');
        }
        if (!is_array($w) || ($w !== [] && array_is_list($w))) {
            throw Problem::ungueltig('Körper muss ein JSON-Objekt sein');
        }
        return $this->koerper = $w;
    }

    public function param(string $name): string
    {
        return $this->param[$name] ?? throw new \LogicException("Pfadparameter $name fehlt");
    }
}
