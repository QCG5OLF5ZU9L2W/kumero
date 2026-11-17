<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Plattform;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Verbindungen über den Unix-Socket mit Peer-Authentisierung – ohne Passwort
 * (ANWENDUNG.md Grundsatz 4). Eine Verbindung je Datenbank und Anfrage.
 */
final class Datenbank
{
    /** @var array<string, Connection> */
    private array $verbindungen = [];

    public function __construct(private readonly Konfiguration $konfig)
    {
    }

    public function verwaltung(): Connection
    {
        return $this->verbindung($this->konfig->verwaltungDb);
    }

    public function verbindung(string $datenbank): Connection
    {
        if (!preg_match('/^kumero_[a-z0-9_]{1,50}$/', $datenbank)) {
            throw new \InvalidArgumentException("Unzulässiger Datenbankname: $datenbank");
        }
        return $this->verbindungen[$datenbank] ??= DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => $this->konfig->dbHost,
            'port' => $this->konfig->dbPort,
            'dbname' => $datenbank,
            'user' => $this->konfig->dbNutzer,
            'charset' => 'utf8',
            'application_name' => 'kumero',
        ]);
    }

    public function schliessen(): void
    {
        foreach ($this->verbindungen as $v) {
            $v->close();
        }
        $this->verbindungen = [];
    }
}
