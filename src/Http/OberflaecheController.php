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
        $system = $ident->systemRechte($a->datenbank);
        $ktx = $a->kontext;
        $aktiv = $ktx === null ? [] : array_map('strval', $a->db()->fetchFirstColumn('SELECT name FROM kern.modul WHERE aktiv ORDER BY name'));
        $sprache = $ktx?->sprache ?? $a->konfig->sprache;
        $kontextSystem = new Kontext($ident->subjekt, null, $sprache, null, false, $a->anfrageId, array_fill_keys($system, null));
        $menue = new Menue(
            Module::alle(), $aktiv, $ktx?->mandant, $system, $ktx?->rechte() ?? [], $sprache, $ktx, $kontextSystem,
            fn (string $name) => Module::einstieg($name),
            trim((string) @file_get_contents(dirname(__DIR__, 2) . '/VERSION')),
        );
        return Antwort::json($menue->bauen() + ['mandant' => $ktx?->mandant, 'rechte_system' => $system,
                                             'rechte_mandant' => $ktx === null ? [] : array_merge(['angemeldet'], $ktx->rechte())]);
    }
}
