<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Http;

use Kumero\Api\V1\Kontext;
use Kumero\Plattform\Anfrage;
use Kumero\Plattform\Menue;
use Kumero\Plattform\Module;
use Symfony\Component\HttpFoundation\Response;

/** Menü der Weboberfläche (OBERFLAECHE.md 4, F80 N4). */
final class OberflaecheController
{
    public function menue(Anfrage $a): Response
    {
        $ident = $a->identitaet ?? throw new \LogicException('ohne Anmeldung');
        $system = $ident->systemRechte();
        $ktx = $a->kontext;
        // aktive Module gibt es nur im Buch; auf der Ebene Mandant und in der Installation bleibt die Liste leer
        $aktiv = $ktx?->buch === null ? [] : array_map('strval', $a->db()->fetchFirstColumn('SELECT name FROM kern.modul WHERE aktiv ORDER BY name'));
        $sprache = $ktx?->sprache ?? $a->konfig->sprache;
        $kontextSystem = new Kontext($ident->subjekt, null, $sprache, null, false, $a->anfrageId, array_fill_keys($system, null));
        $menue = new Menue(
            Module::alle(), $aktiv, $ktx?->mandant, $system, $ktx?->rechte() ?? [], $sprache, $ktx, $kontextSystem,
            fn (string $name) => Module::einstieg($name),
            trim((string) @file_get_contents(dirname(__DIR__, 2) . '/VERSION')),
            $ktx?->buch,
        );
        // Erweiterungen von Seiten des Kerns (OBERFLAECHE.md, F102): aktive Module nennen im Manifest unter
        // „oberflaeche_erweiterungen“ je Punkt eine Datei aus ihrem Verzeichnis; der Kern kennt die Module nicht beim Namen
        $erweiterungen = [];
        foreach (Module::alle() as $name => $m) {
            foreach (in_array($name, $aktiv, true) && is_array($m['oberflaeche_erweiterungen'] ?? null) ? $m['oberflaeche_erweiterungen'] : [] as $punkt => $datei) {
                if (in_array($punkt, ['konten'], true) && is_string($datei) && preg_match('/^[a-z0-9_-]+\.js$/', $datei)) {
                    $erweiterungen[$punkt][] = "/module/$name/{$m['version']}/$datei";
                }
            }
        }
        return Antwort::json($menue->bauen() + ['erweiterungen' => (object) $erweiterungen, 'mandant' => $ktx?->mandant, 'buch' => $ktx?->buch, 'rechte_system' => $system,
                                             'rechte_mandant' => $ktx === null ? [] : array_merge(['angemeldet'], $ktx->rechte())]);
    }
}
