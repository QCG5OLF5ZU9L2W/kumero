<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests\Hilfe;

use Doctrine\DBAL\Connection;
use Kumero\Api\V1\Mandantenauskunft;
use Kumero\Api\V1\RoutenAnbieter;
use Kumero\Http\Antwort;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prüfmodul der Tests für Module am Mandanten (F135): Routen über den Büchern, Protokoll in der Kette des Mandanten,
 * Auskunft an Module eines Buchs. Es führt keine Daten und gehört nicht zum Release. Nur erfundene Namen.
 */
final class ProbeModul implements RoutenAnbieter, Mandantenauskunft
{
    public function routen(): array
    {
        $m = fn (string $name, string $methode, string $pfad, callable $h, string $recht) =>
            ['name' => $name, 'methode' => $methode, 'pfad' => "/probe$pfad", 'handler' => $h, 'recht' => $recht, 'mandant' => true];
        return [
            $m('eintraege', 'GET', '/eintraege', $this->eintraege(...), 'probe.eintrag.sehen'),
            $m('eintrag.anlegen', 'POST', '/eintraege', $this->anlegen(...), 'probe.eintrag.schreiben'),
            $m('zaehler', 'GET', '/zaehler', fn (Anfrage $a) => Antwort::json(['zahl' => 0]), 'probe.eintrag.sehen'),
        ];
    }

    public function eintraege(Anfrage $a): Response
    {
        // Ebene Mandant: kein Buch im Kontext, die Datenbank der Anfrage ist die des Mandanten
        return Antwort::json(['eintraege' => [], 'buch' => $a->kontext()->buch, 'mandant' => $a->kontext()->mandant,
            'schema' => $a->db()->fetchOne("SELECT to_regnamespace('mandant') IS NOT NULL AND to_regnamespace('kern') IS NULL")]);
    }

    public function anlegen(Anfrage $a): Response
    {
        $nr = $a->db()->fetchOne('SELECT mandant.modul_protokoll(?, ?, ?, ?::jsonb, NULL, ?::jsonb)',
            [$a->kontext()->subjekt, 'probe', 'probe.eintrag_angelegt', '{"eintrag":"e1"}', '{"anzahl":"1"}']);
        return Antwort::json(['protokoll' => (int) $nr], 201);
    }

    public function mandantenauskunft(Connection $mandantDb, string $gegenstand, array $ids): array
    {
        if ($gegenstand !== 'personen') {
            return [];
        }
        $alle = [['id' => 'p1', 'name' => 'Berta Beispiel', 'gehalt' => '4200'], ['id' => 'p2', 'name' => 'Curt Muster', 'gehalt' => '3900']];
        return $ids === [] ? $alle : array_values(array_filter($alle, fn (array $p) => in_array($p['id'], $ids, true)));
    }
}
