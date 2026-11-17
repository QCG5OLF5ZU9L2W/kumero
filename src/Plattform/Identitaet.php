<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Plattform;

use Kumero\Api\V1\Problem;
use Symfony\Component\HttpFoundation\Request;

/**
 * Wer anfragt: Nutzer mit Sitzung (Passkey, E2) oder Dienstkonto mit Token
 * (ANWENDUNG.md Abschnitt 5). Geheimnisse werden nur als SHA-256 verglichen.
 */
final class Identitaet
{
    public const COOKIE = '__Host-kumero';

    private function __construct(
        public readonly string $subjekt,
        public readonly string $art,          // 'sitzung' | 'token'
        public readonly ?string $mandant,     // bei Tokens: der einzige erlaubte Mandant
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
        if (is_string($cookie) && $cookie !== '') {
            $hash = hash('sha256', $cookie);
            $subjekt = $db->verwaltung()->fetchOne('SELECT verwaltung.sitzung_pruefen(?, make_interval(mins => ?))',
                [$hash, $k->leerlaufMin]);
            if (is_string($subjekt)) {
                return new self($subjekt, 'sitzung', null, $hash);
            }
        }
        return null;
    }

    public function nutzerId(): ?string
    {
        return str_starts_with($this->subjekt, 'nutzer:') ? substr($this->subjekt, 7) : null;
    }

    /**
     * Systemweite Rechte (F80 N3): jede Anmeldung hat `angemeldet`; `verwaltung.verwalter` nur ein
     * aktiver Nutzer mit Verwalter-Kennzeichen in einer Sitzung (Dienstkonten nie).
     *
     * @return list<string>
     */
    public function systemRechte(Datenbank $db): array
    {
        $rechte = ['angemeldet'];
        $nutzer = $this->nutzerId();
        if ($nutzer !== null && $this->art === 'sitzung'
            && $db->verwaltung()->fetchOne('SELECT 1 FROM verwaltung.nutzer WHERE id = ? AND verwalter AND aktiv', [$nutzer])) {
            $rechte[] = 'verwaltung.verwalter';
        }
        return $rechte;
    }
}
