<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Plattform;

use Doctrine\DBAL\Exception\DriverException;
use Kumero\Api\V1\Problem;

/**
 * Übersetzt Fehler der Datenbank in Problem Details (ANWENDUNG.md Abschnitt 3).
 * Die Regeln selbst prüft PostgreSQL; hier wird nur übersetzt (Grundsatz 1).
 */
final class Fehlerabbildung
{
    private const KERN = [
        'KM001' => [409, 'Unveränderlich'],
        'KM002' => [422, 'Buchungsregel verletzt'],
        'KM003' => [409, 'Zustand erlaubt die Handlung nicht'],
        'KM004' => [422, 'Wert für die kanonische Form unzulässig'],
        'KM005' => [412, 'Inzwischen geändert'],
        'KM006' => [409, 'Idempotenzschlüssel für eine andere Anfrage verwendet'],
    ];

    public static function sqlstate(\Throwable $e): ?string
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof DriverException && $x->getSQLState() !== null) {
                return $x->getSQLState();
            }
        }
        return null;
    }

    public static function istWiederholbar(\Throwable $e): bool
    {
        return in_array(self::sqlstate($e), ['40001', '40P01'], true);
    }

    /** Liefert ein Problem oder null, wenn es kein bekannter Datenbankfehler ist. */
    public static function problem(\Throwable $e): ?Problem
    {
        $state = self::sqlstate($e);
        if ($state === null) {
            return null;
        }
        if (isset(self::KERN[$state])) {
            [$status, $titel] = self::KERN[$state];
            // Meldungen der Kern-Funktionen sind eigene, festgelegte Texte (kern.verweigern).
            return new Problem($status, $state, self::meldung($e) ?? $titel, [], $e);
        }
        return match (true) {
            $state === '23505' => new Problem(409, 'konflikt.vorhanden', 'Ein Eintrag mit diesem Schlüssel besteht bereits', [], $e),
            $state === '23503' => new Problem(422, 'anfrage.ungueltig', 'Verweis auf einen unbekannten Eintrag', [], $e),
            $state === '23514', $state === '23502', $state === '23P01'
                => new Problem(422, 'anfrage.ungueltig', 'Werte verletzen eine Regel der Datenbank', [], $e),
            str_starts_with($state, '22') => new Problem(422, 'anfrage.ungueltig', 'Wert hat ein ungültiges Format', [], $e),
            $state === '40001', $state === '40P01' => new Problem(503, 'konflikt.gleichzeitig', 'Gleichzeitige Änderung – bitte erneut versuchen', [], $e),
            default => null,
        };
    }

    /** Meldungstext aus „ERROR:  …“ der PostgreSQL-Antwort, ohne CONTEXT. */
    private static function meldung(\Throwable $e): ?string
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof \PDOException && isset($x->errorInfo[2]) && is_string($x->errorInfo[2])) {
                $t = $x->errorInfo[2];
                $t = preg_replace('/^ERROR:\s+/', '', $t) ?? $t;
                return trim(explode("\n", $t)[0]);
            }
        }
        if (preg_match('/ERROR:\s+([^\n]+)/', $e->getMessage(), $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
