<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Kontenrahmen;

use Kumero\Api\V1\Problem;
use Kumero\Http\Antwort;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST-Endpunkte des Moduls für die Weboberfläche (F80, KONTENRAHMEN.md Abschnitt 8).
 * Systemweit (Verwalter): Regelwerke, Entwürfe, Vorlagen. Im Mandanten: anwenden, angewendet.
 */
final class WebController
{
    private const HOECHSTENS_BYTES = 32 * 1024 * 1024;
    private const ENTWURF_FELDER = ['kontoart', 'bezeichnung', 'weglassen'];

    public function regelwerke(Anfrage $a): Response
    {
        return Antwort::json(Regelwerk::alle());
    }

    public function entwurfAnlegen(Anfrage $a): Response
    {
        $r = $a->http;
        $roh = $r->getContent();
        if ($roh === '') {
            throw Problem::ungueltig('Datei fehlt (Körper der Anfrage)');
        }
        if (strlen($roh) > self::HOECHSTENS_BYTES) {
            throw new Problem(413, 'anfrage.zu_gross', 'Datei größer als 32 MB');
        }
        $typ = strtolower(trim(explode(';', (string) $r->headers->get('Content-Type', ''))[0]));
        $art = match (true) {
            $typ === 'application/pdf' => 'pdf',
            in_array($typ, ['text/csv', 'text/plain', 'application/vnd.ms-excel'], true) => 'csv',
            default => throw new Problem(415, 'anfrage.medientyp', 'Erwartet wird application/pdf oder text/csv'),
        };
        if ($art === 'pdf' && !str_starts_with($roh, '%PDF-')) {
            throw Problem::ungueltig('Die Datei ist keine PDF-Datei');
        }
        $regelwerk = strtolower((string) $r->query->get('regelwerk', 'auto'));
        $name = trim((string) $r->query->get('name', ''));
        if (mb_strlen($name) > 120) {
            throw Problem::ungueltig('Name zu lang', [['feld' => 'name', 'meldung' => 'höchstens 120 Zeichen']]);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'kumero-kr');
        try {
            file_put_contents($tmp, $roh);
            try {
                $daten = Einlesen::datei($tmp, $art);
            } catch (\RuntimeException $e) {
                // Meldungen der Leser enthalten keine Kontendaten (z. B. „PDF ohne Textebene“)
                throw new Problem(422, 'kontenrahmen.einlesen', $e->getMessage());
            }
        } finally {
            @unlink($tmp);
        }
        // „auto“: Regelwerk passend zum Rahmen aus der Kopfzeile des PDF
        if ($regelwerk === 'auto') {
            $erkannt = $daten['rahmen_erkannt'] ?? null;
            $passend = array_values(array_filter(Regelwerk::alle(), fn ($x) => $x['rahmen'] === $erkannt));
            if ($passend === []) {
                throw Problem::ungueltig($erkannt === null ? 'Rahmen nicht erkannt – bitte Regelwerk wählen'
                    : "Für $erkannt gibt es noch kein Regelwerk – bitte Regelwerk wählen", [['feld' => 'regelwerk', 'meldung' => 'wählen']]);
            }
            $regelwerk = $passend[0]['name'];
        }
        try {
            $regeln = Regelwerk::laden($regelwerk);
        } catch (\InvalidArgumentException) {
            throw Problem::ungueltig('Regelwerk unbekannt', [['feld' => 'regelwerk', 'meldung' => 'unbekannt']]);
        }
        $rahmen = (string) ($regeln->daten['rahmen'] ?? strtoupper($regelwerk));
        if ($name === '') {
            $name = $rahmen . ($daten['fassung'] ? ', ' . $daten['fassung'] : '') . ($art === 'pdf' ? ', aus PDF' : ', aus CSV');
        }
        $e = $this->entwuerfeAblage($a)->anlegen($name, $rahmen, $regeln->name(), $daten, $a->identitaet->subjekt);
        return Antwort::json($this->darstellung($e), 201, ['Location' => '/api/v1/kontenrahmen/entwuerfe/' . $e['id']]);
    }

    public function entwuerfe(Anfrage $a): Response
    {
        return Antwort::json(array_map(fn ($e) => $this->kurz($e), $this->entwuerfeAblage($a)->alle()));
    }

    public function entwurf(Anfrage $a): Response
    {
        return Antwort::json($this->darstellung($this->entwurfLaden($a)));
    }

    public function korrekturen(Anfrage $a): Response
    {
        $j = $a->json();
        $liste = $j['korrekturen'] ?? null;
        if (!is_array($liste) || ($liste !== [] && array_is_list($liste))) {
            throw Problem::ungueltig('korrekturen muss ein Objekt Nummer → Korrektur sein');
        }
        $fehler = [];
        $sauber = [];
        foreach ($liste as $nr => $k) {
            $nr = (string) $nr;
            $feld = "korrekturen.$nr";
            if (!preg_match('/^[0-9A-Za-z]{1,20}$/', $nr) || !is_array($k) || array_diff(array_keys($k), self::ENTWURF_FELDER) !== []) {
                $fehler[] = ['feld' => $feld, 'meldung' => 'Nummer oder Felder ungültig (erlaubt: kontoart, bezeichnung, weglassen)'];
                continue;
            }
            $z = [];
            if (array_key_exists('kontoart', $k)) {
                if (!in_array($k['kontoart'], CsvLeser::KONTOARTEN, true)) {
                    $fehler[] = ['feld' => "$feld.kontoart", 'meldung' => 'aktiv, passiv, aufwand oder ertrag'];
                }
                $z['kontoart'] = $k['kontoart'];
            }
            if (array_key_exists('bezeichnung', $k)) {
                if (!is_string($k['bezeichnung']) || trim($k['bezeichnung']) === '' || mb_strlen($k['bezeichnung']) > 200) {
                    $fehler[] = ['feld' => "$feld.bezeichnung", 'meldung' => 'Text mit 1 bis 200 Zeichen'];
                }
                $z['bezeichnung'] = $k['bezeichnung'];
            }
            if (array_key_exists('weglassen', $k)) {
                if (!is_bool($k['weglassen'])) {
                    $fehler[] = ['feld' => "$feld.weglassen", 'meldung' => 'true oder false'];
                }
                if ($k['weglassen'] === true) {
                    $z = ['weglassen' => true];
                }
            }
            if ($z !== []) {
                $sauber[$nr] = $z;
            }
        }
        if (count($sauber) > 20000) {
            $fehler[] = ['feld' => 'korrekturen', 'meldung' => 'zu viele Korrekturen'];
        }
        if ($fehler !== []) {
            throw Problem::ungueltig('Korrekturen ungültig', $fehler);
        }
        $this->entwurfLaden($a);
        return Antwort::json($this->darstellung($this->entwuerfeAblage($a)->korrigieren($a->param('id'), $sauber)));
    }

    public function entwurfLoeschen(Anfrage $a): Response
    {
        try {
            $this->entwuerfeAblage($a)->loeschen($a->param('id'));
        } catch (\OutOfBoundsException) {
            throw Problem::nichtGefunden('Entwurf nicht gefunden');
        }
        return Antwort::leer();
    }

    public function vorlageSpeichern(Anfrage $a): Response
    {
        $e = $this->entwurfLaden($a);
        $v = Entwuerfe::vorlage($e);
        if ($v->offen() !== []) {
            throw new Problem(409, 'kontenrahmen.offen', count($v->offen()) . ' Konten haben noch offene Punkte');
        }
        $ablage = new Ablage($a->konfig->kontenrahmenVerzeichnis);
        $kennung = basename($ablage->speichern($v), '.json');
        $this->entwuerfeAblage($a)->loeschen($e['id']);
        return Antwort::json($this->vorlageKurz($kennung, $v), 201, ['Location' => "/api/v1/kontenrahmen/vorlagen/$kennung"]);
    }

    public function vorlagen(Anfrage $a): Response
    {
        $liste = array_map(fn ($x) => $this->vorlageKurz($x['kennung'], $x['vorlage']),
            (new Ablage($a->konfig->kontenrahmenVerzeichnis))->alle());
        usort($liste, fn ($x, $y) => strcmp((string) $y['eingelesen_am'], (string) $x['eingelesen_am']));
        return Antwort::json($liste);
    }

    public function vorlage(Anfrage $a): Response
    {
        $kennung = $a->param('kennung');
        $v = $this->vorlageLaden($a, $kennung);
        return Antwort::json($this->vorlageKurz($kennung, $v) + [
            'konten' => $v->daten['konten'], 'ausgelassen' => $v->daten['ausgelassen'], 'hinweise' => $v->daten['hinweise'],
            'quelle' => $v->daten['quelle'], 'regelwerk' => $v->daten['regelwerk'],
        ]);
    }

    public function anwenden(Anfrage $a): Response
    {
        $j = $a->json();
        $kennung = $j['vorlage'] ?? null;
        $bez = $j['bezeichnungen'] ?? false;
        if (!is_string($kennung) || !preg_match('/^[a-z0-9-]{1,64}$/', $kennung) || !is_bool($bez)) {
            throw Problem::ungueltig('vorlage (Kennung) und optional bezeichnungen (true/false) erwartet');
        }
        $v = $this->vorlageLaden($a, $kennung);
        if ($v->offen() !== []) {
            throw new Problem(409, 'kontenrahmen.offen', 'Die Vorlage hat noch offene Punkte');
        }
        $erg = Anwenden::ausfuehren($a->db(), $v, $a->kontext()->subjekt, $bez);
        return Antwort::json($erg + ['vorlage' => $kennung, 'pruefen' => count($v->pruefen())]);
    }

    public function angewendet(Anfrage $a): Response
    {
        $zeilen = $a->db()->fetchAllAssociative(
            'SELECT id, name, rahmen, fassung, quelle::text AS quelle, vorlage_sha256, ergebnis::text AS ergebnis, angewendet_am, angewendet_von
               FROM kontenrahmen.angewendet ORDER BY angewendet_am DESC');
        return Antwort::json(array_map(fn ($z) => ['quelle' => json_decode($z['quelle'], true), 'ergebnis' => json_decode($z['ergebnis'], true)] + $z, $zeilen));
    }

    // ── Hilfen ──────────────────────────────────────────────────────────────

    private function entwuerfeAblage(Anfrage $a): Entwuerfe
    {
        return new Entwuerfe($a->konfig->kontenrahmenVerzeichnis);
    }

    /** @return array<string, mixed> */
    private function entwurfLaden(Anfrage $a): array
    {
        try {
            return $this->entwuerfeAblage($a)->laden($a->param('id'));
        } catch (\OutOfBoundsException) {
            throw Problem::nichtGefunden('Entwurf nicht gefunden');
        }
    }

    private function vorlageLaden(Anfrage $a, string $kennung): Vorlage
    {
        $treffer = array_values(array_filter((new Ablage($a->konfig->kontenrahmenVerzeichnis))->alle(), fn ($x) => $x['kennung'] === $kennung));
        if ($treffer === []) {
            throw Problem::nichtGefunden('Vorlage nicht gefunden');
        }
        return $treffer[0]['vorlage'];
    }

    /** @return array<string, mixed> */
    private function kurz(array $e): array
    {
        $v = Entwuerfe::vorlage($e);
        return ['id' => $e['id'], 'name' => $e['name'], 'rahmen' => $e['rahmen'], 'angelegt_am' => $e['angelegt_am'],
                'angelegt_von' => $e['angelegt_von'], 'geaendert_am' => $e['geaendert_am'], 'art' => $e['roh']['art'],
                'bericht' => $this->bericht($v)];
    }

    /** @return array<string, mixed> */
    private function darstellung(array $e): array
    {
        $v = Entwuerfe::vorlage($e);
        return $this->kurz($e) + [
            'fassung' => $v->daten['fassung'], 'regelwerk' => $v->daten['regelwerk'], 'quelle' => $v->daten['quelle'],
            'konten' => $v->daten['konten'], 'ausgelassen' => $v->daten['ausgelassen'], 'hinweise' => $v->daten['hinweise'],
            'bereiche' => $v->daten['bereiche'], 'korrekturen' => (object) $e['korrekturen'],
        ];
    }

    /** @return array<string, mixed> */
    private function bericht(Vorlage $v): array
    {
        $d = $v->daten;
        $arten = array_count_values(array_map(fn ($x) => $x['kontoart'] ?? 'ohne', $d['konten']));
        ksort($arten);
        return ['konten' => count($d['konten']), 'bereiche' => count($d['bereiche']), 'ausgelassen' => count($d['ausgelassen']),
                'pruefen' => count($v->pruefen()), 'offen' => count($v->offen()), 'hinweise' => count($d['hinweise']),
                'kontoarten' => (object) $arten, 'bereit' => $v->offen() === []];
    }

    /** @return array<string, mixed> */
    private function vorlageKurz(string $kennung, Vorlage $v): array
    {
        $d = $v->daten;
        return ['kennung' => $kennung, 'name' => $d['name'], 'rahmen' => $d['rahmen'], 'fassung' => $d['fassung'],
                'quelle_art' => $d['quelle']['art'] ?? null, 'eingelesen_am' => $d['quelle']['eingelesen_am'] ?? null,
                'sha256' => $v->sha256(), 'bericht' => $this->bericht($v)];
    }
}
