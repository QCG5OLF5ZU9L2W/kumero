<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Paperless;

use Kumero\Api\V1\Belegablage;
use Kumero\Api\V1\Problem;
use Kumero\Api\V1\Belegarchiv;
use Kumero\Api\V1\RoutenAnbieter;
use Symfony\Component\HttpFoundation\Response;
use Kumero\Modul\Arbeitsvorrat\Dienst;
use Kumero\Modul\Arbeitsvorrat\Quelle;
use Kumero\Plattform\Anfrage;

/**
 * Einstieg des Moduls paperless (PAPERLESS.md, F92): verbindet den Nutzer mit seinem Paperless-ngx.
 * Für den Arbeitsvorrat ist das Modul eine Quelle (ARBEITSVORRAT.md Abschnitt 3): Belege mit dem
 * Tag für offene Belege werden Einträge; nach dem Buchen entfernt es das Tag, bei Storno setzt es
 * es wieder (L10, B14).
 */
final class Modul implements RoutenAnbieter, Quelle, Belegarchiv, Belegablage
{
    // --- Belegablage (F110): Paperless nimmt Originale an, die kumero nach der Buchung übergibt ----
    public function belegAblageBereit(Anfrage $anfrage): bool
    {
        return $anfrage->kontext()->darf('paperless.beleg.sehen') && (new Zugang($anfrage))->entsperrte() !== [];
    }

    /** PDF immer; eine E-Rechnung als XML nur, wenn der Mandant es eingestellt hat (Parser-Plugin im Paperless, F112). */
    public function belegAblageNimmt(Anfrage $anfrage, string $medientyp): bool
    {
        return match ($medientyp) {
            'application/pdf' => true,
            'application/xml' => (bool) $anfrage->db()->fetchOne('SELECT e_rechnung_xml FROM paperless.ablage'),
            default => false,
        };
    }

    public function belegUebergeben(Anfrage $anfrage, #[\SensitiveParameter] string $inhalt, string $dateiname, array $merkmale, bool $gesendet): ?array
    {
        $z = new Zugang($anfrage);
        [$v, $c] = $z->entsperrte()[0] ?? throw new Problem(409, 'paperless.gesperrt', 'Paperless ist gesperrt: bitte unter „Paperless – mein Zugang“ mit der Passphrase entsperren');
        $md5 = md5($inhalt);
        $sha = hash('sha256', $inhalt);
        $titel = mb_substr(trim((string) ($merkmale['titel'] ?? '')), 0, 120);
        return WebController::mitPaperless(function () use ($c, $v, $md5, $sha, $titel, $inhalt, $dateiname, $merkmale, $gesendet): ?array {
            $id = $c->finden($md5, $sha, $titel);
            if ($id !== null) {
                return ['link' => $v['adresse'] . "/documents/$id/", 'md5' => $md5, 'sha256' => $sha];
            }
            if (!$gesendet) {
                $felder = $titel === '' ? [] : ['title' => $titel];
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($merkmale['datum'] ?? ''))) {
                    $felder['created'] = $merkmale['datum'];
                }
                // Paperless erkennt den Typ am Inhalt; der Dateiname muss trotzdem zur Datei passen
                $xml = !str_starts_with($inhalt, '%PDF');
                $endung = $xml ? '.xml' : '.pdf';
                $name = str_ends_with(strtolower($dateiname), $endung) ? $dateiname : $dateiname . $endung;
                try {
                    $c->hochladen($inhalt, $name, $felder, $xml ? 'application/xml' : 'application/pdf');
                } catch (PaperlessFehler $x) {
                    if ($xml && $x->status === 400) {
                        throw new Problem(409, 'paperless.xml_abgelehnt', 'Paperless nimmt die E-Rechnung als XML nicht an – dafür braucht es dort ein Parser-Plugin für E-Rechnungen');
                    }
                    throw $x;
                }
            }
            return null;
        });
    }

    // --- Belegarchiv (F97): Paperless ist das erste angebundene DMS ---------------------------
    public function belegArchive(Anfrage $anfrage): array
    {
        if (!$anfrage->kontext()->darf('paperless.beleg.sehen')) {
            return [];
        }
        return array_map(fn ($v) => ['praefix' => $v['adresse'] . '/documents/', 'titel' => $v['bezeichnung'], 'bereit' => $v['entsperrt']],
            (new Zugang($anfrage))->liste());
    }

    public function belegVorschau(Anfrage $anfrage, string $link): Response
    {
        return (new WebController())->vorschau($anfrage);
    }

    public function belegOriginal(Anfrage $anfrage, string $link): Response
    {
        return (new WebController())->original($anfrage);
    }

    public function belegPruefen(Anfrage $anfrage, string $link): array
    {
        return (new WebController())->pruefen($anfrage, $link);
    }

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
            $m('verbindung.zuordnung', 'GET', '/verbindungen/{id}/zuordnung', $w->zuordnung(...)),
            $m('verbindung.zuordnung.setzen', 'PUT', '/verbindungen/{id}/zuordnung', $w->zuordnungSetzen(...)),
            $m('entsperren', 'POST', '/entsperren', $w->entsperren(...)),
            $m('sperren', 'POST', '/sperren', $w->sperren(...)),
            $m('vorschau', 'GET', '/vorschau', $w->vorschau(...)),
            $m('original', 'GET', '/original', $w->original(...)),
            $m('verweis', 'POST', '/verweis', $w->verweis(...)),
            $m('vorgabe', 'PUT', '/vorgabe', $w->vorgabeSetzen(...), 'paperless.vorgabe.pflegen'),
            $m('ablage', 'PUT', '/ablage', $w->ablageSetzen(...), 'paperless.vorgabe.pflegen'),
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
            $namen = ['korrespondenten' => $c->namen('correspondents'), 'typen' => $c->namen('document_types'), 'tags' => $c->namen('tags')];
            $felder = array_column($c->liste('/api/custom_fields/'), null, 'name');
            // Zuordnung Paperless-Feld → Buchungssatz (F95): Vorgabe des Mandanten, je Ziel überschrieben von der eigenen
            $regeln = Zuordnung::laden($db, $v['adresse'], $subjekt)['wirksam'];
            $offen = [];
            foreach ($c->liste("/api/documents/?tags__id__all=$tag&ordering=created&fields=id,title,created,correspondent,document_type,original_file_name,tags,custom_fields,archive_serial_number") as $d) {
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
                [$vorschlag, $angaben] = Zuordnung::anwenden($regeln, $d, $felder, $namen);
                $vorschlag['betreff'] ??= mb_substr(trim((string) ($d['title'] ?? '')) ?: "Beleg {$d['id']}", 0, 200);
                $vorschlag['belege'] = [['link' => $link]];
                array_unshift($angaben, ['bezeichnung' => 'Paperless', 'wert' => (string) $v['bezeichnung']]);
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
