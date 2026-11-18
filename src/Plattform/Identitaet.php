<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Plattform;

use Doctrine\DBAL\Connection;
use Kumero\Api\V1\Problem;
use Symfony\Component\HttpFoundation\Request;

/**
 * Wer anfragt (ANWENDUNG.md Abschnitt 5; F114, VERWALTUNG.md Abschnitt 3):
 *
 * - Nutzer eines Mandanten mit Sitzung (Passkey, E2) – Identität, Passkey und Sitzung liegen im Mandanten;
 * - System-Verwalter mit Sitzung – eine Identität der Installation, ohne Mandant;
 * - Dienstkonto mit Token – genau ein Mandant (bis zu einer späteren Stufe in der Verwaltung, U7).
 *
 * Das Sitzungs-Cookie nennt den Mandanten und das Geheimnis: „<kennung>.<geheimnis>“, für System-Verwalter
 * „.<geheimnis>“. Geprüft wird die Sitzung dort, wo sie liegt. Ein Browser hat eine Sitzung (U3).
 * Geheimnisse werden nur als SHA-256 verglichen.
 */
final class Identitaet
{
    public const COOKIE = '__Host-kumero';

    private function __construct(
        public readonly string $subjekt,
        public readonly string $art,          // 'sitzung' | 'token'
        public readonly ?string $mandant,     // der eine Mandant dieser Identität; null = System-Verwalter
        public readonly ?string $sitzungHash,
    ) {
    }

    public static function ermitteln(Request $r, Datenbank $db, Konfiguration $k): ?self
    {
        $kopf = (string) $r->headers->get('Authorization', '');
        $cookie = $r->cookies->get(self::COOKIE);
        if ($kopf !== '' && $cookie !== null) {
            throw Problem::ungueltig('Entweder Sitzung oder Token, nicht beides');
        }
        if ($kopf !== '') {
            if (!preg_match('/^Bearer (kmr_[A-Za-z0-9_-]{32,128})$/', $kopf, $m)) {
                throw new Problem(401, 'anmeldung.erforderlich', 'Token ungültig');
            }
            $z = $db->verwaltung()->fetchAssociative('SELECT subjekt, mandant FROM verwaltung.token_pruefen(?)', [hash('sha256', $m[1])]);
            if ($z === false) {
                throw new Problem(401, 'anmeldung.erforderlich', 'Token ungültig oder abgelaufen');
            }
            return new self($z['subjekt'], 'token', $z['mandant'], null);
        }
        if (!is_string($cookie) || !preg_match('/^([a-z][a-z0-9-]{1,31})?\.([A-Za-z0-9_-]{43,})$/', $cookie, $m)) {
            return null;
        }
        $hash = hash('sha256', $m[2]);
        if ($m[1] === '') {
            $subjekt = $db->verwaltung()->fetchOne('SELECT verwaltung.sitzung_pruefen(?, make_interval(mins => ?))', [$hash, $k->leerlaufMin]);
            return is_string($subjekt) ? new self($subjekt, 'sitzung', null, $hash) : null;
        }
        $mdb = self::mandantDb($db, $m[1]);
        if ($mdb === null) {
            return null;
        }
        $subjekt = $mdb->fetchOne('SELECT kern.sitzung_pruefen(?, make_interval(mins => ?))', [$hash, $k->leerlaufMin]);
        return is_string($subjekt) ? new self($subjekt, 'sitzung', $m[1], $hash) : null;
    }

    /** Wert des Sitzungs-Cookies: Mandant (leer für System-Verwalter) und Geheimnis. */
    public static function cookieWert(?string $mandant, string $geheim): string
    {
        return ($mandant ?? '') . '.' . $geheim;
    }

    /** Datenbank eines nicht stillgelegten Mandanten; null, wenn es ihn nicht gibt oder er nicht erreichbar ist. */
    public static function mandantDb(Datenbank $db, string $kennung): ?Connection
    {
        $d = $db->verwaltung()->fetchOne('SELECT datenbank FROM verwaltung.mandant WHERE kennung = ? AND stillgelegt_am IS NULL', [$kennung]);
        return is_string($d) ? $db->verbindung($d) : null;
    }

    public function nutzerId(): ?string
    {
        return str_starts_with($this->subjekt, 'nutzer:') ? substr($this->subjekt, 7) : null;
    }

    /** System-Verwalter: Sitzung der Installation. Die Sitzung gilt nur für aktive Verwalter (verwaltung.sitzung_pruefen). */
    public function istVerwalter(): bool
    {
        return $this->art === 'sitzung' && $this->mandant === null;
    }

    /**
     * Systemweite Rechte (F80 N3): jede Anmeldung hat `angemeldet`; `verwaltung.verwalter` nur der
     * System-Verwalter (Dienstkonten und Nutzer eines Mandanten nie).
     *
     * @return list<string>
     */
    public function systemRechte(): array
    {
        return $this->istVerwalter() ? ['angemeldet', 'verwaltung.verwalter'] : ['angemeldet'];
    }
}
