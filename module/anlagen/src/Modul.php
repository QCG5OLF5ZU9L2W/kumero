<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Anlagen;

use Kumero\Api\V1\RoutenAnbieter;

/**
 * Einstieg des Moduls anlagen (ANLAGEN.md, F61, F129): Anlagegüter eines Buchs, Rechnung der Absetzung, Vermerk der
 * Buchungen dazu. Das Modul schreibt nie ins Journal: Die Seite legt Entwürfe über die REST-Schnittstelle des Kerns
 * an, mit den Rechten dessen, der den Lauf ausführt; festgeschrieben wird wie jede Buchung von einem Menschen (F31).
 */
final class Modul implements RoutenAnbieter
{
    public function routen(): array
    {
        $w = new WebController();
        $m = fn (string $name, string $methode, string $pfad, callable $h, string $recht = 'anlagen.gut.sehen') =>
            ['name' => $name, 'methode' => $methode, 'pfad' => "/anlagen$pfad", 'handler' => $h, 'recht' => $recht, 'mandant' => true];
        return [
            $m('stand', 'GET', '', $w->stand(...)),
            $m('plan', 'POST', '/plan', $w->plan(...)),
            $m('einstellung', 'PUT', '/einstellung', $w->einstellungSetzen(...), 'anlagen.gut.pflegen'),
            $m('gut.anlegen', 'POST', '/gueter', $w->anlegen(...), 'anlagen.gut.pflegen'),
            $m('gut.aendern', 'PUT', '/gueter/{id}', $w->aendern(...), 'anlagen.gut.pflegen'),
            $m('gut.abgang', 'POST', '/gueter/{id}/abgang', $w->abgang(...), 'anlagen.gut.pflegen'),
            $m('vermerk', 'POST', '/vermerke', $w->vermerken(...), 'anlagen.lauf.ausfuehren'),
            $m('vermerk.entfernen', 'POST', '/vermerke/entfernen', $w->vermerkEntfernen(...), 'anlagen.lauf.ausfuehren'),
        ];
    }
}
