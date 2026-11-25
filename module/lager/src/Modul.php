<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Lager;

use Kumero\Api\V1\RoutenAnbieter;

/**
 * Einstieg des Moduls lager (LAGER.md, F131): Lager eines Buchs mit Chargen, Seriennummern und Verfallsdaten.
 * Das Lager führt Mengen, das Buch führt Werte – das Modul bucht nie. Positionen eines Belegs kommen über den Dienst
 * des Arbeitsvorrats und sind ein Vorschlag (WW9); es gibt keinen Patientenbezug (WW14).
 */
final class Modul implements RoutenAnbieter
{
    public function routen(): array
    {
        $w = new WebController();
        $m = fn (string $name, string $methode, string $pfad, callable $h, string $recht = 'lager.bestand.sehen') =>
            ['name' => $name, 'methode' => $methode, 'pfad' => "/lager$pfad", 'handler' => $h, 'recht' => $recht, 'mandant' => true];
        return [
            $m('stand', 'GET', '', $w->stand(...)),
            $m('charge', 'GET', '/chargen/{id}', $w->charge(...)),
            $m('wareneingang', 'GET', '/wareneingang', $w->wareneingang(...)),
            $m('wareneingang.eintrag', 'GET', '/wareneingang/{eintrag}', $w->eintrag(...)),
            $m('zugaenge', 'POST', '/zugaenge', $w->zugaenge(...), 'lager.bewegung.buchen'),
            $m('merken', 'POST', '/zuordnungen', $w->merken(...), 'lager.bewegung.buchen'),
            $m('entnahme', 'POST', '/entnahmen', $w->entnahme(...), 'lager.bewegung.buchen'),
            $m('umlagerung', 'POST', '/umlagerungen', $w->umlagerung(...), 'lager.bewegung.buchen'),
            $m('ausbuchung', 'POST', '/ausbuchungen', $w->ausbuchung(...), 'lager.bewegung.buchen'),
            $m('sperre', 'POST', '/chargen/{id}/sperre', $w->sperre(...), 'lager.charge.sperren'),
            $m('artikel.anlegen', 'POST', '/artikel', $w->artikelAnlegen(...), 'lager.artikel.pflegen'),
            $m('artikel.aendern', 'PUT', '/artikel/{id}', $w->artikelAendern(...), 'lager.artikel.pflegen'),
            $m('meta.anlegen', 'POST', '/meta', $w->metaAnlegen(...), 'lager.artikel.pflegen'),
            $m('meta.aendern', 'PUT', '/meta/{id}', $w->metaAendern(...), 'lager.artikel.pflegen'),
            $m('meta.zuordnen', 'POST', '/artikel/{id}/meta', $w->metaZuordnen(...), 'lager.artikel.pflegen'),
            $m('teil.einbauen', 'POST', '/chargen/{id}/teile', $w->teilEinbauen(...), 'lager.bewegung.buchen'),
            $m('teil.loesen', 'POST', '/teile/{id}/loesen', $w->teilLoesen(...), 'lager.bewegung.buchen'),
            $m('stueckliste', 'PUT', '/artikel/{id}/stueckliste', $w->stuecklisteSetzen(...), 'lager.artikel.pflegen'),
            $m('ort.anlegen', 'POST', '/orte', $w->ortAnlegen(...), 'lager.artikel.pflegen'),
            $m('ort.aendern', 'PUT', '/orte/{id}', $w->ortAendern(...), 'lager.artikel.pflegen'),
            $m('nachbestellung', 'GET', '/nachbestellung', $w->nachbestellung(...)),
            $m('inventuren', 'GET', '/inventuren', $w->inventuren(...)),
            $m('inventur', 'GET', '/inventuren/{id}', $w->inventur(...)),
            $m('inventur.anlegen', 'POST', '/inventuren', $w->inventurAnlegen(...), 'lager.inventur.fuehren'),
            $m('inventur.zaehlen', 'PUT', '/inventuren/{id}/zeilen', $w->inventurZaehlen(...), 'lager.inventur.fuehren'),
            $m('inventur.zeile', 'POST', '/inventuren/{id}/zeilen', $w->inventurZeile(...), 'lager.inventur.fuehren'),
            $m('inventur.abschluss', 'POST', '/inventuren/{id}/abschluss', $w->inventurAbschliessen(...), 'lager.inventur.abschliessen'),
            $m('inventur.verwerfen', 'POST', '/inventuren/{id}/verwerfen', $w->inventurVerwerfen(...), 'lager.inventur.abschliessen'),
            $m('bestandsliste', 'GET', '/bestandsliste', $w->bestandsliste(...)),
            $m('abschluss', 'POST', '/abschluss', $w->abschlussVermerken(...), 'lager.einstellung.pflegen'),
            $m('abschluss.entfernen', 'POST', '/abschluss/entfernen', $w->abschlussEntfernen(...), 'lager.einstellung.pflegen'),
            $m('einstellung', 'PUT', '/einstellung', $w->einstellungSetzen(...), 'lager.einstellung.pflegen'),
        ];
    }
}
