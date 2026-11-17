<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Api\V1;

use Doctrine\DBAL\Connection;
use Kumero\Plattform\Module;

/**
 * Lesende Dienste des Kerns für Module (F44, F46). Teil der stabilen Modul-API: Module fragen
 * hierüber, statt Tabellen des Kerns zu lesen.
 */
final class Dienste
{
    /**
     * Stand von Buchungen, ohne Inhalt.
     *
     * @param list<string> $ids
     * @return array<string, string> ID → entwurf | festgeschrieben | storniert | fehlt
     */
    public static function buchungsstaende(Connection $db, array $ids): array
    {
        $erg = array_fill_keys($ids, 'fehlt');
        if ($ids === []) {
            return [];
        }
        $zeilen = $db->fetchAllAssociative(
            "SELECT b.id, b.status::text AS status,
                    EXISTS (SELECT 1 FROM kern.buchung s WHERE s.storno_von = b.id AND s.status = 'festgeschrieben') AS storniert
               FROM kern.buchung b WHERE b.id = ANY(?::uuid[])", ['{' . implode(',', $ids) . '}']);
        foreach ($zeilen as $z) {
            $erg[$z['id']] = $z['status'] !== 'festgeschrieben' ? 'entwurf' : ($z['storniert'] ? 'storniert' : 'festgeschrieben');
        }
        return $erg;
    }

    /**
     * Im Mandanten aktive Module mit Manifest und Einstiegsobjekt.
     *
     * @return array<string, array{manifest: array<string, mixed>, einstieg: ?object}>
     */
    public static function aktiveModule(Connection $db): array
    {
        $erg = [];
        foreach ($db->fetchFirstColumn('SELECT name FROM kern.modul WHERE aktiv ORDER BY name') as $name) {
            try {
                $erg[$name] = ['manifest' => Module::manifest($name), 'einstieg' => Module::einstieg($name)];
            } catch (\Throwable) {
                // registriert, aber im Release nicht (mehr) vorhanden: kein Einstieg
            }
        }
        return $erg;
    }
}
