<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Kontenrahmen;

use Doctrine\DBAL\Connection;

/**
 * Wendet eine Vorlage auf einen Mandanten an (KONTENRAHMEN.md Abschnitt 7).
 * Konten nur über kern.konto_anlegen bzw. kern.konto_aendern (F2, F64), alles in einer
 * Transaktion; der Vermerk geht über kontenrahmen.angewendet_vermerken ins Protokoll.
 */
final class Anwenden
{
    /** @return array{angelegt: int, vorhanden: int, umbenannt: int, konflikte: list<string>} */
    public static function ausfuehren(Connection $db, Vorlage $v, string $subjekt, bool $bezeichnungen = false): array
    {
        if ($v->offen() !== []) {
            throw new \RuntimeException(count($v->offen()) . ' Konten haben offene Punkte – erst klären (Ergänzung per CSV)');
        }
        if (!$db->fetchOne("SELECT 1 FROM kern.modul WHERE name = 'kontenrahmen' AND aktiv")) {
            throw new \RuntimeException('Modul kontenrahmen ist in diesem Mandanten nicht aktiv – bin/kumero modul:aktivieren kontenrahmen MANDANT');
        }
        return $db->transactional(function (Connection $db) use ($v, $subjekt, $bezeichnungen): array {
            $vorhanden = [];
            foreach ($db->fetchAllAssociative('SELECT nr, bezeichnung, kontoart::text AS kontoart FROM kern.konto') as $k) {
                $vorhanden[$k['nr']] = $k;
            }
            $erg = ['angelegt' => 0, 'vorhanden' => 0, 'umbenannt' => 0, 'konflikte' => []];
            foreach ($v->daten['konten'] as $k) {
                $alt = $vorhanden[$k['nr']] ?? null;
                if ($alt === null) {
                    $db->executeStatement('SELECT kern.konto_anlegen(?, ?, ?, ?::kern.kontoart)', [$subjekt, $k['nr'], $k['bezeichnung'], $k['kontoart']]);
                    $erg['angelegt']++;
                } elseif ($alt['kontoart'] !== $k['kontoart']) {
                    $erg['konflikte'][] = "{$k['nr']}: vorhanden als {$alt['kontoart']}, Vorlage sagt {$k['kontoart']} – unverändert";
                } else {
                    $erg['vorhanden']++;
                    if ($bezeichnungen && $alt['bezeichnung'] !== $k['bezeichnung']) {
                        $db->executeStatement('SELECT kern.konto_aendern(?, ?, ?)', [$subjekt, $k['nr'], $k['bezeichnung']]);
                        $erg['umbenannt']++;
                    }
                }
            }
            $db->executeStatement('SELECT kontenrahmen.angewendet_vermerken(?, ?, ?, ?, ?::jsonb, ?::jsonb, ?, ?::jsonb)', [
                $subjekt, $v->daten['name'], $v->daten['rahmen'], $v->daten['fassung'],
                json_encode($v->daten['quelle'], JSON_UNESCAPED_UNICODE), json_encode($v->daten['regelwerk']), $v->sha256(),
                json_encode(['angelegt' => $erg['angelegt'], 'vorhanden' => $erg['vorhanden'], 'umbenannt' => $erg['umbenannt'],
                             'konflikte' => count($erg['konflikte'])]),
            ]);
            return $erg;
        });
    }
}
