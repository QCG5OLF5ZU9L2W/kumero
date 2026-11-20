<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Auswertungen;

use Kumero\Api\V1\RoutenAnbieter;

/**
 * Einstieg des Moduls auswertungen (AUSWERTUNGEN.md, F128). Gerechnet wird im Kern (Basisabfragen `summen` und
 * `buchungen`); das Modul bringt die Seite und die gespeicherten Auswertungen mit. Es liest keine Tabelle des Kerns.
 */
final class Modul implements RoutenAnbieter
{
    public function routen(): array
    {
        $w = new WebController();
        $m = fn (string $name, string $methode, string $pfad, callable $h, string $recht) =>
            ['name' => $name, 'methode' => $methode, 'pfad' => "/auswertungen$pfad", 'handler' => $h, 'recht' => $recht, 'mandant' => true];
        return [
            $m('vorlagen.liste', 'GET', '/vorlagen', $w->liste(...), 'kern.buchung.sehen'),
            $m('vorlagen.anlegen', 'POST', '/vorlagen', $w->anlegen(...), 'auswertungen.vorlage.pflegen'),
            $m('vorlage.ersetzen', 'PUT', '/vorlagen/{id}', $w->ersetzen(...), 'auswertungen.vorlage.pflegen'),
            $m('vorlage.loeschen', 'DELETE', '/vorlagen/{id}', $w->loeschen(...), 'auswertungen.vorlage.pflegen'),
        ];
    }
}
