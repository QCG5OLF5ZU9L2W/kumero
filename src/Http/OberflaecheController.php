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
        // aktive Module: die des Buchs und die des Mandanten (F135); in der Installation bleibt die Liste leer
        $aktiv = $ktx?->buch === null ? [] : array_map('strval', $a->db()->fetchFirstColumn('SELECT name FROM kern.modul WHERE aktiv ORDER BY name'));
        $rechte = $ktx?->rechte() ?? [];
        if ($ktx?->mandant !== null && \Kumero\Plattform\Kernel::modulAmMandantenAktiv($a->mandantDb(), null)) {
            $aktiv = array_merge($aktiv, array_map('strval', $a->mandantDb()->fetchFirstColumn('SELECT name FROM mandant.modul WHERE aktiv ORDER BY name')));
            // Im Buch trägt der Kontext nur die Rechte des Buchs; das Menü zeigt auch dort, was am Mandanten erlaubt ist
            if ($ktx->buch !== null) {
                $rechte = array_values(array_unique(array_merge($rechte, array_keys(\Kumero\Plattform\Kernel::rechteAmMandanten($a->mandantDb(), $ident->subjekt)))));
            }
        }
        $sprache = $ktx?->sprache ?? $a->konfig->sprache;
        $kontextSystem = new Kontext($ident->subjekt, null, $sprache, null, false, $a->anfrageId, array_fill_keys($system, null));
        $menue = new Menue(
            Module::alle(), $aktiv, $ktx?->mandant, $system, $rechte, $sprache, $ktx, $kontextSystem,
            fn (string $name) => Module::einstieg($name),
            trim((string) @file_get_contents(dirname(__DIR__, 2) . '/VERSION')),
            $ktx?->buch,
            $a->wirksameAdresse()->mandant !== null,
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
                                             'rechte_mandant' => $ktx === null ? [] : array_merge(['angemeldet'], $rechte)]);
    }
}
