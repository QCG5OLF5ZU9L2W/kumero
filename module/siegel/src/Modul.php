<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Siegel;

use Kumero\Api\V1\RoutenAnbieter;

/**
 * Einstieg des Moduls siegel (SIEGEL.md, F119, F120): Siegel auf Anforderung. Siegel, Signaturen und öffentliche
 * Schlüssel liegen im Kern des Mandanten (Migration 0013); das Modul bringt die Endpunkte, die Prüfung der Signatur
 * und die Seite mit. Ein privater Schlüssel erreicht kumero nie: Signiert wird außerhalb, hochgeladen wird nur die Signatur.
 */
final class Modul implements RoutenAnbieter
{
    public function routen(): array
    {
        $w = new WebController();
        $m = fn (string $name, string $methode, string $pfad, callable $h, string $recht = 'siegel.siegel.sehen') =>
            ['name' => $name, 'methode' => $methode, 'pfad' => "/siegel$pfad", 'handler' => $h, 'recht' => $recht, 'mandant' => true];
        return [
            $m('liste', 'GET', '', $w->liste(...)),
            $m('anfordern', 'POST', '', $w->anfordern(...), 'siegel.siegel.anfordern'),
            $m('inhalt', 'GET', '/{id}/inhalt', $w->inhalt(...)),
            $m('signieren', 'POST', '/{id}/signaturen', $w->signieren(...), 'siegel.siegel.anfordern'),
            $m('signatur', 'GET', '/{id}/signaturen/{fpr}', $w->signatur(...)),
            $m('schluessel.anmelden', 'POST', '/schluessel', $w->schluesselAnmelden(...), 'siegel.schluessel.pflegen'),
            $m('schluessel', 'GET', '/schluessel/{fpr}', $w->schluessel(...)),
            $m('schluessel.zurueckziehen', 'POST', '/schluessel/{fpr}/zurueckziehen', $w->schluesselZurueckziehen(...), 'siegel.schluessel.pflegen'),
        ];
    }
}
