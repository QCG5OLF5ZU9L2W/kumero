<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Buchen;

use Kumero\Api\V1\RoutenAnbieter;

/**
 * Einstieg des Moduls buchen (modul.json „einstieg“): Buchungsliste, Buchungserfassung und
 * Geschäftsvorfälle (BUCHUNGSMASKE.md). Die Endpunkte für Buchungen selbst gehören zum Kern;
 * das Modul bringt die Seiten und die Vorlagen mit.
 */
final class Modul implements RoutenAnbieter
{
    public function routen(): array
    {
        $w = new WebController();
        $m = fn (string $name, string $methode, string $pfad, callable $h, string $recht) =>
            ['name' => $name, 'methode' => $methode, 'pfad' => "/buchen$pfad", 'handler' => $h, 'recht' => $recht, 'mandant' => true];
        return [
            $m('vorlagen.liste', 'GET', '/vorlagen', $w->liste(...), 'buchen.vorlage.sehen'),
            $m('vorlagen.anlegen', 'POST', '/vorlagen', $w->anlegen(...), 'buchen.vorlage.pflegen'),
            $m('vorlagen.vorgaben', 'POST', '/vorlagen/vorgaben', $w->vorgaben(...), 'buchen.vorlage.pflegen'),
            $m('vorlage.ersetzen', 'PUT', '/vorlagen/{id}', $w->ersetzen(...), 'buchen.vorlage.pflegen'),
            $m('vorlage.loeschen', 'DELETE', '/vorlagen/{id}', $w->loeschen(...), 'buchen.vorlage.pflegen'),
        ];
    }
}
