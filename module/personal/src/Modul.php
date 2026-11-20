<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Personal;

use Doctrine\DBAL\Connection;
use Kumero\Api\V1\Mandantenauskunft;
use Kumero\Api\V1\RoutenAnbieter;

/**
 * Einstieg des Moduls personal (PERSONAL.md Stufe 1, F136): Personen, Verträge, Fristen und Nachweise am Mandanten.
 * Das Modul gehört zum Mandanten, nicht zu einem Buch (F135). Es führt keine Vergütung und keine Lohnmerkmale, rechnet
 * keinen Lohn und ruft nichts außerhalb ab. Module eines Buchs bekommen von ihm nur ID und Anzeigenamen von Personen.
 */
final class Modul implements RoutenAnbieter, Mandantenauskunft
{
    public function routen(): array
    {
        $w = new WebController();
        $m = fn (string $name, string $methode, string $pfad, callable $h, string $recht = 'personal.liste.sehen') =>
            ['name' => $name, 'methode' => $methode, 'pfad' => "/personal$pfad", 'handler' => $h, 'recht' => $recht, 'mandant' => true];
        return [
            $m('stand', 'GET', '', $w->stand(...)),
            $m('akte', 'GET', '/personen/{id}', $w->akte(...)),
            $m('person.anlegen', 'POST', '/personen', $w->personAnlegen(...), 'personal.person.pflegen'),
            $m('person.aendern', 'PUT', '/personen/{id}', $w->personAendern(...), 'personal.person.pflegen'),
            $m('vertrag.anlegen', 'POST', '/personen/{id}/vertraege', $w->vertragAnlegen(...), 'personal.vertrag.pflegen'),
            $m('auswahl', 'GET', '/auswahl', $w->auswahl(...), 'personal.person.pflegen'),
            $m('fristen', 'GET', '/fristen', $w->fristen(...)),
            $m('zaehler', 'GET', '/zaehler', $w->zaehler(...)),
            $m('frist.anlegen', 'POST', '/fristen', $w->fristAnlegen(...), 'personal.frist.pflegen'),
            $m('frist.erledigen', 'POST', '/fristen/{id}/erledigt', $w->fristErledigen(...), 'personal.frist.pflegen'),
            $m('fristart.anlegen', 'POST', '/fristarten', $w->fristartSetzen(...), 'personal.frist.pflegen'),
            $m('fristart.aendern', 'PUT', '/fristarten/{id}', $w->fristartSetzen(...), 'personal.frist.pflegen'),
            $m('einstellung', 'PUT', '/einstellung', $w->einstellung(...), 'personal.frist.pflegen'),
            $m('meine', 'GET', '/meine', $w->meine(...), 'personal.eigenes.sehen'),
            $m('meine.vorschlag', 'POST', '/meine/vorschlaege', $w->vorschlagen(...), 'personal.eigenes.vorschlagen'),
            $m('vorschlag.entscheiden', 'POST', '/vorschlaege/{id}/entscheidung', $w->vorschlagEntscheiden(...), 'personal.person.pflegen'),
            $m('loeschfrist', 'PUT', '/loeschfrist', $w->loeschfrist(...), 'personal.person.pflegen'),
        ];
    }

    /** Auskunft an Module eines Buchs: „personen“ – wer zur Auswahl steht (nicht ausgeschieden) bzw. die genannten. */
    public function mandantenauskunft(Connection $mandantDb, string $gegenstand, array $ids): array
    {
        if ($gegenstand !== 'personen') {
            return [];
        }
        $ids = array_values(array_filter($ids, fn (string $i) => (bool) preg_match(WebController::UUID, $i)));
        $zeilen = $ids === []
            ? $mandantDb->fetchAllAssociative('SELECT id, familienname, vornamen FROM personal.person WHERE austritt IS NULL OR austritt >= current_date ORDER BY lower(familienname), lower(coalesce(vornamen, \'\')), id')
            : $mandantDb->fetchAllAssociative('SELECT id, familienname, vornamen FROM personal.person WHERE id = ANY(?::uuid[]) ORDER BY lower(familienname), id', ['{' . implode(',', $ids) . '}']);
        return array_map(fn (array $z) => ['id' => (string) $z['id'], 'name' => WebController::name($z)], $zeilen);
    }
}
