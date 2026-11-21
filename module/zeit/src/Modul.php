<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Zeit;

use Kumero\Api\V1\RoutenAnbieter;

/**
 * Einstieg des Moduls zeit (ZEIT.md, F145): Zeiterfassung am Mandanten. Stempeln ist ein Recht des Nutzers mit eigener
 * Rolle „Zeiterfassung“; Vorgesetzte geben frei, das Personal führt Profile und Regeln. Das Modul baut auf dem Personal
 * auf und liest dort nur über dessen Schnittstelle (personal.api_personen, personal.api_tage).
 */
final class Modul implements RoutenAnbieter
{
    public function routen(): array
    {
        $w = new WebController();
        $m = fn (string $name, string $methode, string $pfad, callable $h, string $recht) =>
            ['name' => $name, 'methode' => $methode, 'pfad' => "/zeit$pfad", 'handler' => $h, 'recht' => $recht, 'mandant' => true];
        $ich = 'zeit.eigene.buchen';
        $liste = 'zeit.liste.oeffnen';
        $frei = 'zeit.team.freigeben';
        $pflege = 'zeit.alle.pflegen';
        return [
            $m('meine', 'GET', '/meine', $w->meine(...), $ich),
            $m('meine.stempel', 'POST', '/meine/stempel', $w->stempeln(...), $ich),
            $m('meine.antrag', 'POST', '/meine/antraege', $w->beantragen(...), $ich),
            $m('meine.monat', 'POST', '/meine/monate/{monat}/bestaetigung', $w->bestaetigen(...), $ich),
            $m('stand', 'GET', '', $w->stand(...), $liste),
            $m('blatt', 'GET', '/personen/{person}', $w->blatt(...), $liste),
            $m('antrag.entscheiden', 'POST', '/antraege/{id}/entscheidung', $w->entscheiden(...), $frei),
            $m('tag.freigeben', 'PUT', '/personen/{person}/tage/{datum}/freigabe', $w->tagFreigeben(...), $frei),
            $m('monat.freigeben', 'POST', '/personen/{person}/monate/{monat}/freigabe', $w->monatFreigeben(...), $frei),
            $m('monat.oeffnen', 'POST', '/personen/{person}/monate/{monat}/oeffnung', $w->monatOeffnen(...), $frei),
            $m('antrag.eintragen', 'POST', '/personen/{person}/antraege', $w->eintragen(...), $pflege),
            $m('profil', 'PUT', '/personen/{person}/profil', $w->profilSetzen(...), $pflege),
            $m('einstellung', 'PUT', '/einstellung', $w->einstellungSetzen(...), $pflege),
            $m('einstellung.vorgabe', 'POST', '/einstellung/vorgabe', $w->einstellungVorgabe(...), $pflege),
        ];
    }
}
