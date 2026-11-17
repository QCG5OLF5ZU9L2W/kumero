<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Api\V1;

/**
 * REST-Endpunkte eines Moduls (F18, F44). Pfade beginnen mit /<modul>; der Kern hängt sie unter
 * /api/v1 (systemweit) bzw. /m/{mandant}/api/v1 (im Mandanten) ein. Auf Mandantenpfaden prüft der
 * Kern zusätzlich, dass das Modul im Mandanten aktiv ist.
 */
interface RoutenAnbieter
{
    /**
     * @return list<array{name: string, methode: string, pfad: string, handler: callable, recht: string, mandant: bool}>
     *         recht: Rechte-Code; systemweit nur `angemeldet` oder `verwaltung.verwalter`
     */
    public function routen(): array;
}
