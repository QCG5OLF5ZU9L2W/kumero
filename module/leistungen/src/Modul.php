<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Leistungen;

use Kumero\Api\V1\RoutenAnbieter;

/**
 * Einstieg des Moduls leistungen (LEISTUNGEN.md Abschnitt 10, F152, F154): Leistungskatalog und erbrachte Leistungen am
 * Mandanten, als Grundlage der Zuschläge. Der Mitarbeiter trägt ein, der Vorgesetzte bestätigt; das Personal pflegt den
 * Katalog. Das Modul baut auf dem Personal auf und liest dort nur über dessen Schnittstelle.
 */
final class Modul implements RoutenAnbieter
{
    public function routen(): array
    {
        $w = new WebController();
        $m = fn (string $name, string $methode, string $pfad, callable $h, string $recht) =>
            ['name' => $name, 'methode' => $methode, 'pfad' => "/leistungen$pfad", 'handler' => $h, 'recht' => $recht, 'mandant' => true];
        $ich = 'leistungen.eigene.eintragen';
        $liste = 'leistungen.liste.oeffnen';
        $team = 'leistungen.team.bestaetigen';
        $pflege = 'leistungen.alle.pflegen';
        return [
            $m('meine', 'GET', '/meine', $w->meine(...), $ich),
            $m('meine.eintragen', 'POST', '/meine/zeilen', $w->meineEintragen(...), $ich),
            $m('meine.loeschen', 'DELETE', '/meine/zeilen/{id}', $w->meineLoeschen(...), $ich),
            $m('stand', 'GET', '', $w->stand(...), $liste),
            $m('eintragen', 'POST', '/zeilen', $w->eintragen(...), $team),
            $m('bestaetigen', 'POST', '/zeilen/{id}/bestaetigung', $w->bestaetigen(...), $team),
            $m('loeschen', 'DELETE', '/zeilen/{id}', $w->loeschen(...), $team),
            $m('katalog.anlegen', 'POST', '/katalog', $w->leistungAnlegen(...), $pflege),
            $m('katalog.aendern', 'PUT', '/katalog/{id}', $w->leistungAendern(...), $pflege),
            $m('katalog.preis', 'PUT', '/katalog/{id}/preis', $w->preisSetzen(...), $pflege),
        ];
    }
}
