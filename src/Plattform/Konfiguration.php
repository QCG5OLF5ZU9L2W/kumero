<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Plattform;

/**
 * Konfiguration aus /etc/kumero/kumero.php (ANWENDUNG.md Abschnitt 8, E6).
 * Die Datei gibt ein Array zurück und enthält keine Geheimnisse.
 */
final class Konfiguration
{
    public const STANDARD_DATEI = '/etc/kumero/kumero.php';

    public readonly string $adresse;      // Origin, z. B. https://kumero.example.org
    public readonly string $rpId;         // Host der Adresse (WebAuthn)
    public readonly string $sprache;
    public readonly string $verwaltungDb;
    public readonly string $dbHost;       // Socket-Verzeichnis (Peer-Authentisierung)
    public readonly int $dbPort;
    public readonly string $dbNutzer;     // kumero_app (Web), kumero_owner (Kommandozeile)
    public readonly int $leerlaufMin;
    public readonly int $hoechstensStd;
    public readonly string $protokollStufe;
    public readonly string $kontenrahmenVerzeichnis;   // Vorlagen, installationsweit (KONTENRAHMEN.md K2)

    /** @param array<string, mixed> $w */
    public function __construct(private readonly array $w)
    {
        $adresse = rtrim((string) ($w['adresse'] ?? ''), '/');
        $teile = parse_url($adresse);
        if (!$teile || !isset($teile['scheme'], $teile['host']) || isset($teile['path'])) {
            throw new \InvalidArgumentException("Konfiguration: 'adresse' muss ein Origin sein, z. B. https://kumero.example.org");
        }
        $host = strtolower($teile['host']);
        if ($teile['scheme'] !== 'https' && $host !== 'localhost') {
            throw new \InvalidArgumentException("Konfiguration: 'adresse' braucht https (Ausnahme: http://localhost für den SSH-Tunnel)");
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new \InvalidArgumentException("Konfiguration: 'adresse' braucht einen Hostnamen – Passkeys funktionieren nicht mit IP-Adressen");
        }
        $this->adresse = $adresse;
        $this->rpId = $host;
        $this->sprache = (string) ($w['sprache'] ?? 'de');
        $this->verwaltungDb = (string) ($w['verwaltung_db'] ?? 'kumero_verwaltung');
        $this->dbHost = (string) ($w['db_host'] ?? '/var/run/postgresql');
        $this->dbPort = (int) ($w['db_port'] ?? 5432);
        $this->dbNutzer = (string) ($w['db_nutzer'] ?? 'kumero_app');
        $this->leerlaufMin = (int) ($w['sitzung']['leerlauf_min'] ?? 30);
        $this->hoechstensStd = (int) ($w['sitzung']['hoechstens_std'] ?? 12);
        $this->protokollStufe = (string) ($w['protokoll']['stufe'] ?? 'info');
        $this->kontenrahmenVerzeichnis = (string) ($w['kontenrahmen_verzeichnis'] ?? '/var/lib/kumero/kontenrahmen');
    }

    public static function laden(?string $datei = null): self
    {
        $datei ??= getenv('KUMERO_KONFIG') ?: self::STANDARD_DATEI;
        if (!is_file($datei)) {
            throw new \RuntimeException("Konfiguration fehlt: $datei");
        }
        $w = require $datei;
        if (!is_array($w)) {
            throw new \RuntimeException("Konfiguration muss ein Array zurückgeben: $datei");
        }
        return new self($w);
    }

    /** Dieselbe Konfiguration mit anderem Datenbanknutzer (Kommandozeile als root → kumero_owner). */
    public function mitDbNutzer(string $nutzer): self
    {
        return new self(['db_nutzer' => $nutzer] + $this->w);
    }
}
