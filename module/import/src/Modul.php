<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Import;

use Kumero\Api\V1\Belegarchiv;
use Kumero\Api\V1\RoutenAnbieter;
use Kumero\Modul\Arbeitsvorrat\Dienst;
use Kumero\Modul\Arbeitsvorrat\Quelle;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Einstieg des Moduls import (IMPORT.md, F104): CSV-Dateien und E-Rechnungen (XRechnung, ZUGFeRD) als Quelle des Arbeitsvorrats.
 * Eine liefernde Quelle – sie bringt Einträge, wenn der Nutzer eine Datei einliest, und bucht nie selbst.
 */
final class Modul implements RoutenAnbieter, Belegarchiv, Quelle
{
    public const QUELLE = 'import';

    // --- Vorläufiges Belegarchiv (F110): das Original einer E-Rechnung, solange es noch in kumero liegt -----
    public function belegArchive(Anfrage $anfrage): array
    {
        return [['praefix' => Ablage::praefix($anfrage), 'titel' => 'Zwischenablage (noch nicht im Belegarchiv)',
                 'bereit' => $anfrage->kontext()->darf('import.datei.einlesen') || $anfrage->kontext()->darf('kern.buchung.sehen'), 'vorlaeufig' => true]];
    }

    public function belegVorschau(Anfrage $anfrage, string $link): Response
    {
        return Ablage::vorschau($anfrage, $link);
    }

    public function belegOriginal(Anfrage $anfrage, string $link): Response
    {
        return Ablage::original($anfrage, $link);
    }

    public function belegPruefen(Anfrage $anfrage, string $link): array
    {
        $d = Ablage::holen($anfrage, $link);
        return ['link' => $link, 'md5' => $d['md5'], 'sha256' => $d['sha256']];
    }

    /**
     * Abgleich mit dem Arbeitsvorrat (F110) – kumero hält kein Dokument dauerhaft:
     *  - Eintrag verworfen oder Beitrag zurückgezogen → das Original wird gelöscht (ohne Buchung kein Grund, es aufzubewahren);
     *  - Eintrag gebucht → das Original geht an das DMS, wird dort als Beleg der Buchung nachgereicht (F27) und hier gelöscht.
     * Ist gerade kein DMS bereit oder verarbeitet es die Datei noch, bleibt die Übergabe ausstehend und wird beim
     * nächsten Abgleich nachgeholt.
     */
    public function arbeitsvorratAbgleich(Anfrage $anfrage, Dienst $dienst): void
    {
        $db = $anfrage->db();
        $subjekt = $anfrage->kontext()->subjekt;
        $dateien = $db->fetchAllAssociative('SELECT id, schluessel, name, gesendet_am IS NOT NULL AS gesendet FROM import.datei WHERE inhalt IS NOT NULL ORDER BY abgelegt_am LIMIT 5000');
        if ($dateien === []) {
            return;
        }
        $stand = $dienst->beitraege(self::QUELLE);
        $ablage = null;
        $uebergaben = 0;
        $abgelehnt = 0;
        foreach ($dateien as $d) {
            $x = $stand[$d['schluessel']] ?? null;
            if ($x === null || $x['zurueckgezogen']) {
                Ablage::loeschen($db, $subjekt, [$d['schluessel']], 'zurueckgezogen');
                continue;
            }
            if ($x['zustand'] === 'verworfen') {
                Ablage::loeschen($db, $subjekt, [$d['schluessel']], 'verworfen');
                continue;
            }
            // je Abgleich nur wenige Übergaben: Der Abgleich läuft im Hintergrund einer Anfrage. Abgelehnte zählen eigens,
            // damit Dateien, die das DMS nicht annimmt, die übrigen nicht aufhalten.
            if ($x['zustand'] !== 'gebucht' || $uebergaben >= 5 || $abgelehnt >= 5 || !$anfrage->kontext()->darf('kern.beleg.nachreichen')) {
                continue;
            }
            $buchungen = array_column(array_filter($dienst->buchungen(self::QUELLE, $d['schluessel']), fn ($b) => $b['stand'] === 'festgeschrieben'), 'buchung');
            $ablage ??= \Kumero\Api\V1\Dienste::belegablage($anfrage) ?? false;
            if ($buchungen === [] || $ablage === false) {
                continue;
            }
            $uebergaben++;
            $datei = Ablage::holen($anfrage, Ablage::link($anfrage, $d['id']));
            try {
                $verweis = $ablage->belegUebergeben($anfrage, $datei['inhalt'], $datei['name'], $dienst->merkmale(self::QUELLE, $d['schluessel']), (bool) $d['gesendet']);
            } catch (\Kumero\Api\V1\Problem $p) {
                error_log(sprintf('kumero[%s] import: Übergabe an das Belegarchiv ausstehend (%s)', $anfrage->anfrageId, $p->fehlercode));
                // Der Grund bleibt an der Datei stehen; die Seite „Datei einlesen“ nennt, wie viele Originale das DMS nicht annimmt
                $db->executeStatement('SELECT import.datei_abgelehnt(?, ?::uuid, ?)', [$subjekt, $d['id'], mb_substr($p->fehlercode, 0, 100)]);
                $uebergaben--;
                $abgelehnt++;
                continue;
            }
            $db->executeStatement('SELECT import.datei_abgelehnt(?, ?::uuid, NULL)', [$subjekt, $d['id']]);
            if ($verweis === null) {
                $db->executeStatement('SELECT import.datei_gesendet(?, ?::uuid)', [$subjekt, $d['id']]);
                continue;
            }
            foreach ($buchungen as $b) {
                \Kumero\Api\V1\Dienste::belegNachreichen($anfrage, $b, $verweis);
            }
            $db->executeStatement('SELECT import.datei_uebergeben(?, ?::uuid, ?)', [$subjekt, $d['id'], $verweis['link']]);
        }
    }

    public function routen(): array
    {
        $w = new WebController();
        $m = fn (string $name, string $methode, string $pfad, callable $h, string $recht) =>
            ['name' => $name, 'methode' => $methode, 'pfad' => "/import$pfad", 'handler' => $h, 'recht' => $recht, 'mandant' => true];
        return [
            $m('erkennen', 'POST', '/erkennen', $w->erkennen(...), 'import.datei.einlesen'),
            $m('vorschau', 'POST', '/vorschau', $w->vorschau(...), 'import.datei.einlesen'),
            $m('einliefern', 'POST', '/einliefern', $w->einliefern(...), 'import.datei.einlesen'),
            $m('rechnung.vorschau', 'POST', '/rechnung/vorschau', $w->rechnungVorschau(...), 'import.datei.einlesen'),
            $m('rechnung.einliefern', 'POST', '/rechnung/einliefern', $w->rechnungEinliefern(...), 'import.datei.einlesen'),
            $m('importe', 'GET', '/importe', $w->importe(...), 'import.datei.einlesen'),
            $m('import.zurueckziehen', 'POST', '/importe/{id}/zurueckziehen', $w->zurueckziehen(...), 'import.datei.einlesen'),
            $m('profile', 'GET', '/profile', $w->profile(...), 'import.datei.einlesen'),
            $m('profil.setzen', 'PUT', '/profile', $w->profilSetzen(...), 'import.datei.einlesen'),
            $m('profil.loeschen', 'DELETE', '/profile/{id}', $w->profilLoeschen(...), 'import.datei.einlesen'),
        ];
    }
}
