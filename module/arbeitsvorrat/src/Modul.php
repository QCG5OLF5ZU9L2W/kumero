<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Arbeitsvorrat;

use Kumero\Api\V1\RoutenAnbieter;

/**
 * Einstieg des Moduls arbeitsvorrat (ARBEITSVORRAT.md, F89): der Posteingang der Buchhaltung.
 * Abstrakt und unabhängig von der Quelle der Arbeit – Quellen sind andere Module, die über den
 * Dienst einliefern. Von Hand angelegte Einträge laufen über denselben Vertrag.
 */
final class Modul implements RoutenAnbieter
{
    public function routen(): array
    {
        $w = new WebController();
        $m = fn (string $name, string $methode, string $pfad, callable $h, string $recht) =>
            ['name' => $name, 'methode' => $methode, 'pfad' => "/arbeitsvorrat$pfad", 'handler' => $h, 'recht' => $recht, 'mandant' => true];
        return [
            $m('eintraege.liste', 'GET', '/eintraege', $w->liste(...), 'arbeitsvorrat.eintrag.sehen'),
            $m('eintraege.anlegen', 'POST', '/eintraege', $w->anlegen(...), 'arbeitsvorrat.eintrag.anlegen'),
            $m('eintraege.verwerfen', 'POST', '/eintraege/verwerfen', $w->vieleVerwerfen(...), 'arbeitsvorrat.eintrag.bearbeiten'),
            $m('eintraege.vorauswahl', 'PUT', '/eintraege/vorauswahl', $w->vorauswahl(...), 'arbeitsvorrat.eintrag.bearbeiten'),
            $m('eintraege.zusammenfuehren', 'POST', '/eintraege/zusammenfuehren', $w->zusammenfuehren(...), 'arbeitsvorrat.eintrag.bearbeiten'),
            $m('eintraege.zusammenfuehren.automatisch', 'POST', '/eintraege/zusammenfuehren/automatisch', $w->automatisch(...), 'arbeitsvorrat.eintrag.bearbeiten'),
            $m('eintraege.kein_duplikat', 'POST', '/eintraege/kein-duplikat', $w->keinDuplikat(...), 'arbeitsvorrat.eintrag.bearbeiten'),
            $m('einstellungen.lesen', 'GET', '/einstellungen', $w->einstellungen(...), 'arbeitsvorrat.eintrag.sehen'),
            $m('einstellungen.setzen', 'PUT', '/einstellungen', $w->einstellungenSetzen(...), 'arbeitsvorrat.regel.pflegen'),
            $m('eintrag.lesen', 'GET', '/eintraege/{id}', $w->lesen(...), 'arbeitsvorrat.eintrag.sehen'),
            $m('eintrag.vergleich', 'GET', '/eintraege/{id}/vergleich/{anderer}', $w->vergleich(...), 'arbeitsvorrat.eintrag.sehen'),
            $m('eintrag.trennen', 'POST', '/eintraege/{id}/trennen', $w->trennen(...), 'arbeitsvorrat.eintrag.bearbeiten'),
            $m('eintrag.buchung', 'POST', '/eintraege/{id}/buchung', $w->verknuepfen(...), 'arbeitsvorrat.eintrag.bearbeiten'),
            $m('eintrag.verwerfen', 'POST', '/eintraege/{id}/verwerfen', $w->verwerfen(...), 'arbeitsvorrat.eintrag.bearbeiten'),
            $m('eintrag.oeffnen', 'POST', '/eintraege/{id}/oeffnen', $w->oeffnen(...), 'arbeitsvorrat.eintrag.bearbeiten'),
            $m('zaehler', 'GET', '/zaehler', $w->zaehler(...), 'arbeitsvorrat.eintrag.sehen'),
            $m('abgleich', 'POST', '/abgleich', $w->abgleich(...), 'arbeitsvorrat.eintrag.sehen'),
            $m('regeln.lesen', 'GET', '/regeln', $w->regeln(...), 'arbeitsvorrat.eintrag.sehen'),
            $m('regel.art.setzen', 'PUT', '/regeln/arten/{art}', $w->regelSetzen(...), 'arbeitsvorrat.regel.pflegen'),
            $m('regel.art.loeschen', 'DELETE', '/regeln/arten/{art}', $w->regelLoeschen(...), 'arbeitsvorrat.regel.pflegen'),
            $m('regel.gegenpartei.loeschen', 'POST', '/regeln/gegenparteien/loeschen', $w->gemerktLoeschen(...), 'arbeitsvorrat.regel.pflegen'),
        ];
    }
}
