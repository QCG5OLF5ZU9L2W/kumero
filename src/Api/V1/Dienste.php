<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Api\V1;

use Doctrine\DBAL\Connection;
use Kumero\Plattform\Module;

/**
 * Lesende Dienste des Kerns für Module (F44, F46). Teil der stabilen Modul-API: Module fragen
 * hierüber, statt Tabellen des Kerns zu lesen.
 */
final class Dienste
{
    /**
     * Stand von Buchungen, ohne Inhalt.
     *
     * @param list<string> $ids
     * @return array<string, string> ID → entwurf | festgeschrieben | storniert | fehlt
     */
    public static function buchungsstaende(Connection $db, array $ids): array
    {
        $erg = array_fill_keys($ids, 'fehlt');
        if ($ids === []) {
            return [];
        }
        $zeilen = $db->fetchAllAssociative(
            "SELECT b.id, b.status::text AS status,
                    EXISTS (SELECT 1 FROM kern.buchung s WHERE s.storno_von = b.id AND s.status = 'festgeschrieben') AS storniert
               FROM kern.buchung b WHERE b.id = ANY(?::uuid[])", ['{' . implode(',', $ids) . '}']);
        foreach ($zeilen as $z) {
            $erg[$z['id']] = $z['status'] !== 'festgeschrieben' ? 'entwurf' : ($z['storniert'] ? 'storniert' : 'festgeschrieben');
        }
        return $erg;
    }

    /**
     * Festgeschriebene, nicht stornierte Buchungen zu Belegen – erkannt am Belegverweis: gleicher Link oder
     * gleiche Prüfsumme des Originals (F88, F96 X4). Ohne Inhalt der Buchungen.
     *
     * @param list<array{link?: ?string, md5?: ?string, sha256?: ?string}> $belege
     * @return list<array{buchung: string, link: ?string, md5: ?string, sha256: ?string}>
     */
    public static function buchungenZuBelegen(Connection $db, array $belege): array
    {
        $spalte = fn (string $f) => '{' . implode(',', array_map(fn ($x) => '"' . addcslashes((string) $x, '"\\') . '"',
            array_values(array_unique(array_filter(array_column($belege, $f), 'is_string'))))) . '}';
        if ($belege === []) {
            return [];
        }
        return $db->fetchAllAssociative(
            "SELECT DISTINCT v.buchung, v.link, v.md5::text AS md5, v.sha256::text AS sha256
               FROM kern.belegverweis v JOIN kern.buchung b ON b.id = v.buchung
              WHERE b.status = 'festgeschrieben' AND b.storno_von IS NULL
                AND NOT EXISTS (SELECT 1 FROM kern.buchung s WHERE s.storno_von = b.id AND s.status = 'festgeschrieben')
                AND (v.link = ANY(?::text[]) OR v.md5 = ANY(?::text[]) OR v.sha256 = ANY(?::text[]))",
            [$spalte('link'), $spalte('md5'), $spalte('sha256')]);
    }

    /**
     * Im Mandanten aktive Module mit Manifest und Einstiegsobjekt.
     *
     * @return array<string, array{manifest: array<string, mixed>, einstieg: ?object}>
     */
    public static function aktiveModule(Connection $db): array
    {
        $erg = [];
        foreach ($db->fetchFirstColumn('SELECT name FROM kern.modul WHERE aktiv ORDER BY name') as $name) {
            try {
                $erg[$name] = ['manifest' => Module::manifest($name), 'einstieg' => Module::einstieg($name)];
            } catch (\Throwable) {
                // registriert, aber im Release nicht (mehr) vorhanden: kein Einstieg
            }
        }
        return $erg;
    }

    /**
     * Das DMS-Modul, in das der angemeldete Nutzer jetzt ablegen kann (F110), oder null.
     */
    public static function belegablage(\Kumero\Plattform\Anfrage $a): ?Belegablage
    {
        foreach (self::aktiveModule($a->db()) as $m) {
            try {
                if ($m['einstieg'] instanceof Belegablage && $m['einstieg']->belegAblageBereit($a)) {
                    return $m['einstieg'];
                }
            } catch (Problem) {
                continue;
            }
        }
        return null;
    }

    /**
     * Nimmt eines der aktiven DMS-Module Originale dieses Medientyps an (F112)? Unabhängig davon, ob der Nutzer
     * gerade verbunden ist.
     */
    public static function belegablageNimmt(\Kumero\Plattform\Anfrage $a, string $medientyp): bool
    {
        foreach (self::aktiveModule($a->db()) as $m) {
            try {
                if ($m['einstieg'] instanceof Belegablage && $m['einstieg']->belegAblageNimmt($a, $medientyp)) {
                    return true;
                }
            } catch (Problem) {
                continue;
            }
        }
        return false;
    }

    /**
     * Reicht einen Beleg zu einer festgeschriebenen Buchung nach (F27) – für Module, die ein Original erst nach der
     * Buchung an das DMS übergeben. Verlangt das Recht kern.beleg.nachreichen und lehnt vorläufige Links ab.
     * Gibt false zurück, wenn der Beleg schon an der Buchung steht.
     *
     * @param array{link: string, md5: string, sha256: string} $verweis
     */
    public static function belegNachreichen(\Kumero\Plattform\Anfrage $a, string $buchung, array $verweis): bool
    {
        if (!$a->kontext()->darf('kern.beleg.nachreichen')) {
            throw new Problem(403, 'recht.fehlt', 'Recht fehlt: kern.beleg.nachreichen');
        }
        \Kumero\Http\BelegeController::vorlaeufigAblehnen($a, [$verweis]);
        $schon = $a->db()->fetchOne("SELECT 1 FROM jsonb_array_elements(kern.buchung_belege(?::uuid)) x WHERE x->>'link' = ?", [$buchung, $verweis['link']]);
        if ($schon !== false) {
            return false;
        }
        $a->db()->executeStatement('SELECT kern.beleg_nachreichen(?, ?::uuid, ?, ?, ?)', [$a->kontext()->subjekt, $buchung, $verweis['link'], $verweis['md5'], $verweis['sha256']]);
        return true;
    }
}
