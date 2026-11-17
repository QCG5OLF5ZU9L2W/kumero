<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Paperless;

use Kumero\Api\V1\RoutenAnbieter;
use Kumero\Modul\Arbeitsvorrat\Dienst;
use Kumero\Modul\Arbeitsvorrat\Quelle;
use Kumero\Plattform\Anfrage;

/**
 * Einstieg des Moduls paperless (PAPERLESS.md, F92): verbindet den Nutzer mit seinem Paperless-ngx.
 * Für den Arbeitsvorrat ist das Modul eine Quelle (ARBEITSVORRAT.md Abschnitt 3): Belege mit dem
 * Tag für offene Belege werden Einträge; nach dem Buchen entfernt es das Tag, bei Storno setzt es
 * es wieder (L10, B14).
 */
final class Modul implements RoutenAnbieter, Quelle
{
    public const QUELLE = 'paperless';

    public function routen(): array
    {
        $w = new WebController();
        $s = 'paperless.beleg.sehen';
        $m = fn (string $name, string $methode, string $pfad, callable $h, string $recht = 'paperless.beleg.sehen') =>
            ['name' => $name, 'methode' => $methode, 'pfad' => "/paperless$pfad", 'handler' => $h, 'recht' => $recht, 'mandant' => true];
        return [
            $m('verbindungen', 'GET', '/verbindungen', $w->verbindungen(...)),
            $m('verbindung.anlegen', 'POST', '/verbindungen', $w->anlegen(...)),
            $m('verbindung.aendern', 'PUT', '/verbindungen/{id}', $w->aendern(...)),
            $m('verbindung.loeschen', 'DELETE', '/verbindungen/{id}', $w->loeschen(...)),
            $m('verbindung.status', 'GET', '/verbindungen/{id}/status', $w->status(...)),
            $m('verbindung.tags', 'GET', '/verbindungen/{id}/tags', $w->tags(...)),
            $m('verbindung.tags.uebernehmen', 'PUT', '/verbindungen/{id}/tags', $w->tagsUebernehmen(...)),
            $m('entsperren', 'POST', '/entsperren', $w->entsperren(...)),
            $m('sperren', 'POST', '/sperren', $w->sperren(...)),
            $m('vorschau', 'GET', '/vorschau', $w->vorschau(...)),
            $m('original', 'GET', '/original', $w->original(...)),
            $m('verweis', 'POST', '/verweis', $w->verweis(...)),
            $m('vorgabe', 'PUT', '/vorgabe', $w->vorgabeSetzen(...), 'paperless.vorgabe.pflegen'),
        ];
    }

    /**
     * Abgleich beim Öffnen des Arbeitsvorrats – mit den entsperrten Verbindungen des angemeldeten Nutzers.
     * Ohne entsperrte Verbindung geschieht nichts; was im Vorrat liegt, bleibt liegen.
     */
    public function arbeitsvorratAbgleich(Anfrage $a, Dienst $dienst): void
    {
        try {
            $zugang = new Zugang($a);
        } catch (\Kumero\Api\V1\Problem) {
            return;   // Dienstkonto oder kein Recht: keine Quelle
        }
        if (!$a->kontext()->darf('paperless.beleg.sehen')) {
            return;
        }
        $db = $a->db();
        $subjekt = $a->kontext()->subjekt;
        $stand = $dienst->beitraege(self::QUELLE);
        foreach ($zugang->entsperrte() as [$v, $c]) {
            if ($v['tag_offen_id'] === null) {
                continue;
            }
            $tag = (int) $v['tag_offen_id'];
            $korrespondenten = $c->namen('correspondents');
            $arten = $c->namen('document_types');
            $offen = [];
            foreach ($c->liste("/api/documents/?tags__id__all=$tag&ordering=created&fields=id,title,created,correspondent,document_type,original_file_name") as $d) {
                if (!is_int($d['id'] ?? null)) {
                    continue;
                }
                $link = Paperless::link($v['adresse'], $d['id']);
                $offen[$link] = true;
                $zustand = $stand[$link]['zustand'] ?? null;
                if ($zustand === 'gebucht' || $zustand === 'verworfen') {
                    // erledigt: Tag für offene Belege entfernen (L10, B14) – nur dieses eine Tag
                    if ($c->tagSetzen($d['id'], $tag, false)) {
                        $db->executeStatement('SELECT paperless.tag_merken(?, ?::uuid, ?, true)', [$subjekt, $v['id'], $link]);
                    }
                    continue;
                }
                $titel = trim((string) ($d['title'] ?? '')) ?: "Beleg {$d['id']}";
                $vorschlag = ['betreff' => mb_substr($titel, 0, 200), 'belege' => [['link' => $link]]];
                $k = $korrespondenten[$d['correspondent'] ?? -1] ?? null;
                if (is_string($k) && trim($k) !== '') {
                    $vorschlag['gegenpartei'] = mb_substr(trim($k), 0, 200);
                }
                if (is_string($d['created'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}/', $d['created'], $m)) {
                    $vorschlag['datum'] = $m[0];
                }
                $art = $arten[$d['document_type'] ?? -1] ?? null;
                $stichwort = is_string($art) ? trim((string) preg_replace('/[^a-z0-9_-]+/', '-', strtr(mb_strtolower($art), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'])), '-') : '';
                if (preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $stichwort)) {
                    $vorschlag['art'] = $stichwort;
                }
                $angaben = [['bezeichnung' => 'Paperless', 'wert' => (string) $v['bezeichnung']]];
                if (is_string($art) && $art !== '') {
                    $angaben[] = ['bezeichnung' => 'Dokumenttyp', 'wert' => mb_substr($art, 0, 200)];
                }
                if (is_string($d['original_file_name'] ?? null) && $d['original_file_name'] !== '') {
                    $angaben[] = ['bezeichnung' => 'Datei', 'wert' => mb_substr($d['original_file_name'], 0, 200)];
                }
                $dienst->einliefern(self::QUELLE, $link, $vorschlag, $angaben);
            }
            $gemerkt = array_flip($db->fetchFirstColumn('SELECT link FROM paperless.tag_entfernt WHERE verbindung = ?::uuid', [$v['id']]));
            foreach ($stand as $link => $s) {
                $id = Paperless::idAusLink($v['adresse'], $link);
                if ($id === null || isset($offen[$link])) {
                    continue;
                }
                if (isset($gemerkt[$link])) {
                    // kumero hat das Tag entfernt; ist der Eintrag wieder offen (Storno, wieder geöffnet), kommt es zurück (B9)
                    if ($s['zustand'] === 'offen' || $s['zustand'] === 'in_arbeit') {
                        $c->tagSetzen($id, $tag, true);
                        $db->executeStatement('SELECT paperless.tag_merken(?, ?::uuid, ?, false)', [$subjekt, $v['id'], $link]);
                    }
                } elseif (!$s['zurueckgezogen']) {
                    // Tag in Paperless von Hand entfernt: die Quelle hält den Beleg nicht mehr für offen
                    $dienst->zurueckziehen(self::QUELLE, $link);
                }
            }
        }
    }
}
