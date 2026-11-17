<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Arbeitsvorrat;

use Doctrine\DBAL\Connection;
use Kumero\Api\V1\Kontext;

/**
 * Erweiterungspunkt `arbeitsvorrat_abgleich` (ARBEITSVORRAT.md Abschnitt 3, W5): Eine befragte
 * Quelle wird beim Öffnen des Arbeitsvorrats aufgerufen und liefert über den Dienst ein bzw.
 * zieht zurück. Das Modul nennt sich im Manifest unter "arbeitsvorrat_quelle": {"titel": "…"}.
 * Der Aufruf läuft nach bestem Bemühen: Ein Fehler der Quelle hält den Arbeitsvorrat nicht auf.
 */
interface Quelle
{
    public function arbeitsvorratAbgleich(Kontext $kontext, Connection $db, Dienst $dienst): void;
}
