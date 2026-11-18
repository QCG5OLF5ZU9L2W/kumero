<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Import;

use Kumero\Api\V1\RoutenAnbieter;

/**
 * Einstieg des Moduls import (IMPORT.md, F104): CSV-Dateien als Quelle des Arbeitsvorrats.
 * Eine liefernde Quelle – sie bringt Einträge, wenn der Nutzer eine Datei einliest, und bucht nie selbst.
 */
final class Modul implements RoutenAnbieter
{
    public const QUELLE = 'import';

    public function routen(): array
    {
        $w = new WebController();
        $m = fn (string $name, string $methode, string $pfad, callable $h, string $recht) =>
            ['name' => $name, 'methode' => $methode, 'pfad' => "/import$pfad", 'handler' => $h, 'recht' => $recht, 'mandant' => true];
        return [
            $m('erkennen', 'POST', '/erkennen', $w->erkennen(...), 'import.datei.einlesen'),
            $m('vorschau', 'POST', '/vorschau', $w->vorschau(...), 'import.datei.einlesen'),
            $m('einliefern', 'POST', '/einliefern', $w->einliefern(...), 'import.datei.einlesen'),
            $m('importe', 'GET', '/importe', $w->importe(...), 'import.datei.einlesen'),
            $m('import.zurueckziehen', 'POST', '/importe/{id}/zurueckziehen', $w->zurueckziehen(...), 'import.datei.einlesen'),
            $m('profile', 'GET', '/profile', $w->profile(...), 'import.datei.einlesen'),
            $m('profil.setzen', 'PUT', '/profile', $w->profilSetzen(...), 'import.datei.einlesen'),
            $m('profil.loeschen', 'DELETE', '/profile/{id}', $w->profilLoeschen(...), 'import.datei.einlesen'),
        ];
    }
}
