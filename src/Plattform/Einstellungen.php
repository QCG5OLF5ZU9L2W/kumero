<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Plattform;

use Doctrine\DBAL\Connection;

/**
 * Einstellungen des Nutzers (PROFIL.md, F86): Schlüssel mit Besitzer-Präfix, feste Werte,
 * der erste Wert ist der Standard. Gespeichert am Nutzer in der Verwaltungsdatenbank.
 * Einstellungen aus Modulen (Manifest) folgen in einer späteren Stufe.
 */
final class Einstellungen
{
    /** @var array<string, list<string>> */
    public const KERN = [
        'kern.betragseingabe' => ['dezimal', 'kaufmaennisch'],       // BUCHUNGSMASKE.md M10
        'kern.schriftgroesse' => ['normal', 'gross', 'sehr_gross'],  // PROFIL.md 4a
        'kern.kontrast' => ['system', 'normal', 'hoch'],
    ];
    /** Sprachen, für die es Texte gibt (T5); null am Nutzer = wie der Browser (U5). */
    public const SPRACHEN = ['de'];

    /** @return array<string, string> wirksame Werte: gespeichert oder Standard */
    public static function wirksam(Connection $verwaltung, string $nutzer): array
    {
        $erg = array_map(fn (array $werte) => $werte[0], self::KERN);
        foreach ($verwaltung->fetchAllKeyValue('SELECT schluessel, wert::text FROM verwaltung.nutzer_einstellung WHERE nutzer = ?', [$nutzer]) as $k => $w) {
            $w = json_decode((string) $w, true);
            if (isset(self::KERN[$k]) && is_string($w) && in_array($w, self::KERN[$k], true)) {
                $erg[$k] = $w;
            }
        }
        return $erg;
    }
}
