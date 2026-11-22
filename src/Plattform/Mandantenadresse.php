<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Plattform;

/**
 * Eigene Domain eines Mandanten setzen, ändern, entfernen (VERWALTUNG.md Abschnitt 18; DM1, DM6, DM7).
 *
 * Nur der System-Verwalter – Weboberfläche („Mandanten“) oder Kommandozeile. kumero trägt hier nur ein, unter welchem
 * Hostnamen der Mandant erreichbar ist; Servername und Zertifikat im Webserver setzt, wer root auf der Maschine ist.
 * kumero ändert seine Webserver-Konfiguration nie (DM14).
 */
final class Mandantenadresse
{
    private const HOSTNAME = '/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    /**
     * @param ?string $hostname null entfernt die eigene Domain – der Mandant ist dann wieder unter der Adresse der Installation erreichbar
     * @return array{vorher: ?string, adresse: ?string, geaendert: bool, passkeys_widerrufen: int}
     */
    public static function setzen(Datenbank $db, Konfiguration $k, string $subjekt, string $kennung, ?string $hostname): array
    {
        if ($hostname !== null) {
            $hostname = strtolower(trim($hostname));
            if (strlen($hostname) > 253 || preg_match(self::HOSTNAME, $hostname) !== 1) {
                throw new \InvalidArgumentException('Erwartet wird ein Hostname wie buchhaltung.example.org – ohne https://, Port und Pfad');
            }
            if ($hostname === Adresse::derInstallation($k)->rpId) {
                throw new \InvalidArgumentException('Das ist der Hostname der Installation');
            }
        }
        $v = $db->verwaltung();
        $m = $v->fetchAssociative('SELECT datenbank, adresse FROM verwaltung.mandant WHERE kennung = ? AND stillgelegt_am IS NULL', [$kennung]);
        if ($m === false) {
            throw new \InvalidArgumentException('Mandant unbekannt oder stillgelegt');
        }
        if ($m['adresse'] === $hostname) {
            return ['vorher' => $hostname, 'adresse' => $hostname, 'geaendert' => false, 'passkeys_widerrufen' => 0];
        }
        if ($hostname !== null && $v->fetchOne('SELECT 1 FROM verwaltung.mandant WHERE adresse = ?', [$hostname]) !== false) {
            throw new \InvalidArgumentException('Dieser Hostname gehört schon zu einem Mandanten');
        }
        $v->executeStatement('SELECT verwaltung.mandant_adresse_setzen(?, ?, ?)', [$subjekt, $kennung, $hostname]);
        // Passkeys gelten für den Hostnamen, unter dem sie eingerichtet wurden: Mit dem Wechsel gilt keiner mehr (DM7).
        // Der Vermerk im Mandanten nennt nicht, wer in der Installation gehandelt hat (F114) – nur, dass und warum.
        $n = (int) $db->verbindung($m['datenbank'])->fetchOne('SELECT mandant.passkeys_alle_widerrufen(?, ?)', ['system:verwaltung', 'adresse']);
        return ['vorher' => $m['adresse'], 'adresse' => $hostname, 'geaendert' => true, 'passkeys_widerrufen' => $n];
    }

    /** Die Adresse, unter der ein Mandant erreichbar ist: seine eigene Domain, sonst die der Installation. */
    public static function von(Datenbank $db, Konfiguration $k, ?string $kennung): Adresse
    {
        $h = $kennung === null ? null : $db->verwaltung()->fetchOne('SELECT adresse FROM verwaltung.mandant WHERE kennung = ?', [$kennung]);
        return is_string($h) ? Adresse::desMandanten($h, (string) $kennung) : Adresse::derInstallation($k);
    }
}
