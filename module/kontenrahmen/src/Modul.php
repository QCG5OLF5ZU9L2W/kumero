<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Kontenrahmen;

use Kumero\Api\V1\Kontext;
use Kumero\Api\V1\MenueUnterpunkte;
use Kumero\Api\V1\RoutenAnbieter;
use Kumero\Plattform\Konfiguration;

/** Einstieg des Moduls kontenrahmen (modul.json „einstieg“): REST-Endpunkte und Menü-Unterpunkte. */
final class Modul implements RoutenAnbieter, MenueUnterpunkte
{
    public function routen(): array
    {
        $w = new WebController();
        $sys = fn (string $name, string $methode, string $pfad, callable $h) =>
            ['name' => $name, 'methode' => $methode, 'pfad' => "/kontenrahmen$pfad", 'handler' => $h, 'recht' => 'verwaltung.verwalter', 'mandant' => false];
        $m = fn (string $name, string $methode, string $pfad, callable $h, string $recht) =>
            ['name' => $name, 'methode' => $methode, 'pfad' => "/kontenrahmen$pfad", 'handler' => $h, 'recht' => $recht, 'mandant' => true];
        return [
            $sys('regelwerke', 'GET', '/regelwerke', $w->regelwerke(...)),
            $sys('entwuerfe.liste', 'GET', '/entwuerfe', $w->entwuerfe(...)),
            $sys('entwuerfe.anlegen', 'POST', '/entwuerfe', $w->entwurfAnlegen(...)),
            $sys('entwurf.lesen', 'GET', '/entwuerfe/{id}', $w->entwurf(...)),
            $sys('entwurf.loeschen', 'DELETE', '/entwuerfe/{id}', $w->entwurfLoeschen(...)),
            $sys('entwurf.korrekturen', 'PUT', '/entwuerfe/{id}/korrekturen', $w->korrekturen(...)),
            $sys('entwurf.vorlage', 'POST', '/entwuerfe/{id}/vorlage', $w->vorlageSpeichern(...)),
            $sys('vorlagen.liste', 'GET', '/vorlagen', $w->vorlagen(...)),
            $sys('vorlage.lesen', 'GET', '/vorlagen/{kennung}', $w->vorlage(...)),
            $m('m.vorlagen.liste', 'GET', '/vorlagen', $w->vorlagen(...), 'kontenrahmen.vorlage.anwenden'),
            $m('m.anwenden', 'POST', '/anwenden', $w->anwenden(...), 'kontenrahmen.vorlage.anwenden'),
            $m('m.angewendet', 'GET', '/angewendet', $w->angewendet(...), 'kontenrahmen.angewendet.sehen'),
        ];
    }

    /** Unter „Kontenrahmen-Vorlagen“ steht jede gespeicherte Vorlage als eigener Unterpunkt (N3a). */
    public function menueUnterpunkte(Kontext $kontext, string $eintragId): array
    {
        if ($eintragId !== 'kontenrahmen.vorlagen' || !$kontext->darf('verwaltung.verwalter')) {
            return [];
        }
        $erg = [];
        foreach ((new Ablage(Konfiguration::laden()->kontenrahmenVerzeichnis))->alle() as $i => $x) {
            $erg[] = ['id' => 'kontenrahmen.vorlage.' . substr($x['vorlage']->sha256(), 0, 12), 'titel' => (string) $x['vorlage']->daten['name'],
                      'ziel' => 'vorlage?kennung=' . rawurlencode($x['kennung']), 'recht' => 'verwaltung.verwalter', 'reihenfolge' => $i];
        }
        return $erg;
    }
}
