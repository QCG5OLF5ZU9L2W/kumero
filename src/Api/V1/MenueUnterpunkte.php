<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Api\V1;

/**
 * Erweiterungspunkt `menue_unterpunkte` (OBERFLAECHE.md 3a, F80 N3a): wechselnde Unterpunkte
 * unter einem eigenen Menüeintrag mit `"unterpunkte": true`. Nur lesend.
 *
 * Jeder Unterpunkt: id (mit Modulpräfix), titel (fertiger Text), ziel (Seite des Moduls,
 * optional mit ?parametern), recht, reihenfolge. Der Kern prüft und filtert nach Rollen.
 */
interface MenueUnterpunkte
{
    /** @return list<array{id: string, titel: string, ziel: string, recht: string, reihenfolge?: int}> */
    public function menueUnterpunkte(Kontext $kontext, string $eintragId): array;
}
