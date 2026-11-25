<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Beschaffung;

use Kumero\Api\V1\RoutenAnbieter;

/**
 * Einstieg des Moduls beschaffung (BESTELLANFORDERUNG.md, F217, F222): Bestellanforderung eines Buchs – Bedarf melden,
 * weiterleiten, freigeben, als bestellt abhaken, Eingang melden. Das Modul bucht nie (F31) und bestellt nicht selbst:
 * bestellt wird im Shop, hier wird erfasst, was bestellt wurde (BA20). Mit dem Lager spricht es über dessen Dienst (F44).
 */
final class Modul implements RoutenAnbieter
{
    public function routen(): array
    {
        $w = new WebController();
        $m = fn (string $name, string $methode, string $pfad, callable $h, string $recht = 'beschaffung.vorgang.sehen') =>
            ['name' => $name, 'methode' => $methode, 'pfad' => "/beschaffung$pfad", 'handler' => $h, 'recht' => $recht, 'mandant' => true];
        return [
            $m('stand', 'GET', '', $w->stand(...)),
            $m('anlegen', 'POST', '/anforderungen', $w->anlegen(...), 'beschaffung.bedarf.anfordern'),
            $m('aendern', 'PUT', '/anforderungen/{id}', $w->aendern(...)),                    // eigene oder mit „freigeben“ – prüft der Controller
            $m('anfordern', 'POST', '/anforderungen/{id}/anfordern', $w->anfordern(...), 'beschaffung.bedarf.anfordern'),
            $m('zurueckziehen', 'POST', '/anforderungen/{id}/zurueckziehen', $w->zurueckziehen(...)),
            $m('weiterleiten', 'POST', '/anforderungen/{id}/weiterleiten', $w->weiterleiten(...)),
            $m('uebernehmen', 'POST', '/anforderungen/{id}/uebernehmen', $w->uebernehmen(...)),
            $m('freigeben', 'POST', '/anforderungen/{id}/freigeben', $w->freigeben(...), 'beschaffung.bedarf.freigeben'),
            $m('ablehnen', 'POST', '/anforderungen/{id}/ablehnen', $w->ablehnen(...), 'beschaffung.bedarf.freigeben'),
            $m('bestellen', 'POST', '/positionen/{id}/bestellen', $w->bestellen(...), 'beschaffung.bedarf.bestellen'),
            $m('zuruecknehmen', 'POST', '/positionen/{id}/zuruecknehmen', $w->zuruecknehmen(...), 'beschaffung.bedarf.bestellen'),
            $m('streichen', 'POST', '/positionen/{id}/streichen', $w->streichen(...), 'beschaffung.bedarf.bestellen'),
            $m('erhalten', 'POST', '/positionen/{id}/erhalten', $w->erhalten(...)),           // „bestellen“ oder die eigene Anforderung
        ];
    }
}
