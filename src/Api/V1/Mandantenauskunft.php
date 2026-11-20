<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Api\V1;

use Doctrine\DBAL\Connection;

/**
 * Auskunft eines Moduls am Mandanten an Module eines Buchs (F135). Ein Buchmodul liest keine Tabellen des Mandanten;
 * es fragt über `Dienste::auskunftAmMandanten()` und bekommt je Eintrag nur ID und Anzeigenamen – der Kern schneidet
 * alles Weitere ab. Beispiel: Das Modul eines Buchs braucht die Liste der Personen, um eine davon zu wählen.
 */
interface Mandantenauskunft
{
    /**
     * @param string $gegenstand was gefragt ist, z. B. „personen“ – ein Modul nennt in seiner Beschreibung, was es kennt
     * @param list<string> $ids nur diese Einträge; leer = alle, die zur Auswahl stehen
     * @return list<array{id: string, name: string}> unbekannter Gegenstand: leere Liste
     */
    public function mandantenauskunft(Connection $mandantDb, string $gegenstand, array $ids): array;
}
