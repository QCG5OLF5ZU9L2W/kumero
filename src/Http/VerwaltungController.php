<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Http;

use Kumero\Api\V1\Problem;
use Kumero\Plattform\Anfrage;
use Kumero\Plattform\Mandantenpflege;
use Kumero\Plattform\Webauthn\Base64Url;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mandantenverwaltung des System-Verwalters (VERWALTUNG.md Abschnitt 5 und 14, F118): Mandanten auflisten, anlegen
 * (= ersten Administrator einsetzen, U5), stilllegen, exportieren, löschen.
 *
 * Die Webanwendung darf keine Datenbanken anlegen, sichern oder löschen. Diese Seite schreibt deshalb **Aufträge**;
 * ausgeführt werden sie vom Dienst `kumero-auftraege` (`bin/kumero system:auftraege`). Der System-Verwalter sieht
 * dabei keinen Mandanten von innen (F43): keine Buchungszahlen, kein Download der Sicherung.
 */
final class VerwaltungController
{
    public function mandanten(Anfrage $a): Response
    {
        $v = $a->verwaltung();
        $auftraege = $v->fetchAllAssociative("SELECT id, art, mandant, zustand, verwaltung.zeitpunkt(angelegt_am) AS angelegt_am,
                   verwaltung.zeitpunkt(beendet_am) AS beendet_am, ergebnis::text AS ergebnis
              FROM verwaltung.auftrag ORDER BY angelegt_am DESC, id LIMIT 200");
        $laufend = [];
        $export = [];
        foreach ($auftraege as $x) {
            if (in_array($x['zustand'], ['offen', 'laeuft'], true)) {
                $laufend[$x['mandant']] = $x['art'];
            } elseif ($x['art'] === 'mandant_exportieren' && $x['zustand'] === 'erledigt' && !isset($export[$x['mandant']])) {
                $export[$x['mandant']] = ['am' => $x['beendet_am']] + (array) json_decode((string) $x['ergebnis'], true);
            }
        }
        $mandanten = [];
        foreach ($v->fetchAllAssociative('SELECT kennung, name, adresse, verwaltung.zeitpunkt(angelegt_am) AS angelegt_am,
                   verwaltung.zeitpunkt(stillgelegt_am) AS stillgelegt_am,
                   EXISTS (SELECT 1 FROM verwaltung.auftrag x WHERE x.mandant = m.kennung AND x.art = \'mandant_exportieren\'
                              AND x.zustand = \'erledigt\' AND x.begonnen_am >= m.stillgelegt_am) AS loeschbar
              FROM verwaltung.mandant m ORDER BY name, kennung') as $m) {
            $mandanten[] = ['kennung' => $m['kennung'], 'name' => $m['name'], 'adresse' => $m['adresse'], 'angelegt_am' => $m['angelegt_am'],
                            'stillgelegt_am' => $m['stillgelegt_am'], 'auftrag' => $laufend[$m['kennung']] ?? null,
                            'export' => $export[$m['kennung']] ?? null, 'loeschbar' => (bool) $m['loeschbar']];
            unset($laufend[$m['kennung']]);
        }
        // Mandanten, die gerade erst angelegt werden, stehen noch nicht in der Liste
        foreach ($laufend as $kennung => $art) {
            if ($art === 'mandant_anlegen') {
                $mandanten[] = ['kennung' => $kennung, 'name' => null, 'angelegt_am' => null, 'stillgelegt_am' => null,
                                'auftrag' => $art, 'export' => null, 'loeschbar' => false];
            }
        }
        return Antwort::json(['mandanten' => $mandanten, 'auftraege' => array_map(fn ($x) => ['id' => $x['id'], 'art' => $x['art'],
            'mandant' => $x['mandant'], 'zustand' => $x['zustand'], 'angelegt_am' => $x['angelegt_am'], 'beendet_am' => $x['beendet_am'],
            'ergebnis' => $x['ergebnis'] === null ? null : json_decode((string) $x['ergebnis'], true)], array_slice($auftraege, 0, 50))]);
    }

    /**
     * Mandant anlegen = ersten Administrator einsetzen (U5). Die Antwort nennt den Zugang (Link) **einmal**; gespeichert
     * wird nur sein Hash. Der Link gilt, sobald der Auftrag ausgeführt ist, 24 Stunden.
     */
    public function anlegen(Anfrage $a): Response
    {
        $j = $a->json();
        $adm = is_array($j['administrator'] ?? null) ? $j['administrator'] : [];
        $text = fn (mixed $w, int $max) => is_string($w) && trim($w) !== '' && mb_strlen(trim($w)) <= $max ? trim($w) : null;
        $kennung = is_string($j['kennung'] ?? null) ? $j['kennung'] : '';
        $name = $text($j['name'] ?? null, 100);
        $vornamen = $text($adm['vornamen'] ?? null, 100);
        $familienname = $text($adm['familienname'] ?? null, 100);
        $email = $text($adm['email'] ?? null, 200);
        $f = [];
        if (array_diff(array_keys($j), ['kennung', 'name', 'administrator']) !== [] || array_diff(array_keys($adm), ['vornamen', 'familienname', 'email']) !== []) {
            $f[] = ['feld' => 'administrator', 'meldung' => 'Erwartet: kennung, name, administrator {vornamen, familienname, email}'];
        }
        if (!preg_match(Mandantenpflege::KENNUNG, $kennung) || in_array($kennung, ['verwaltung', 'vorlage'], true)) {
            $f[] = ['feld' => 'kennung', 'meldung' => 'Kleinbuchstaben, Ziffern und Bindestrich, 2 bis 32 Zeichen, beginnt mit einem Buchstaben; nicht „verwaltung“ oder „vorlage“'];
        }
        if ($name === null) {
            $f[] = ['feld' => 'name', 'meldung' => 'Name des Mandanten erwartet'];
        }
        if ($vornamen === null) {
            $f[] = ['feld' => 'administrator.vornamen', 'meldung' => 'Vorname(n) erwartet'];
        }
        if ($familienname === null) {
            $f[] = ['feld' => 'administrator.familienname', 'meldung' => 'Familienname erwartet'];
        }
        if ($email === null || !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
            $f[] = ['feld' => 'administrator.email', 'meldung' => 'E-Mail-Adresse erwartet'];
        }
        if ($f !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        if ($a->verwaltung()->fetchOne('SELECT 1 FROM verwaltung.mandant WHERE kennung = ? OR datenbank = ?', [$kennung, Mandantenpflege::datenbankName($kennung)])) {
            throw new Problem(409, 'mandant.vorhanden', 'Einen Mandanten mit dieser Kennung gibt es schon');
        }
        // Das Token nennt den Mandanten, damit die Seite ohne Anmeldung weiß, wohin (VERWALTUNG.md Abschnitt 11)
        $token = $kennung . '.' . Base64Url::kodieren(random_bytes(32));
        $id = $this->auftrag($a, 'mandant_anlegen', $kennung, ['name' => $name, 'waehrung' => 'EUR', 'vornamen' => $vornamen,
            'familienname' => $familienname, 'email' => $email, 'token_hash' => hash('sha256', $token)]);
        return Antwort::json(['auftrag' => $id, 'kennung' => $kennung, 'link' => $a->link('/app.html#registrierung=' . $token),
                              'gueltig_stunden' => 24], 202);
    }

    public function stilllegen(Anfrage $a): Response
    {
        $a->verwaltung()->executeStatement('SELECT verwaltung.mandant_stilllegen(?, ?)', [$a->identitaet->subjekt, $a->param('kennung')]);
        return Antwort::leer();
    }

    public function reaktivieren(Anfrage $a): Response
    {
        $a->verwaltung()->executeStatement('SELECT verwaltung.mandant_reaktivieren(?, ?)', [$a->identitaet->subjekt, $a->param('kennung')]);
        return Antwort::leer();
    }

    /**
     * Eigene Domain des Mandanten setzen, ändern oder entfernen (VERWALTUNG.md Abschnitt 18; DM6, DM7). Der Körper nennt
     * `adresse` (Hostname oder null) und zur Bestätigung noch einmal die Kennung – danach gilt kein Passkey des
     * Mandanten mehr. Den Servernamen im Webserver trägt root von Hand ein (DM14).
     */
    public function adresseSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        $kennung = $a->param('kennung');
        if (($j['bestaetigung'] ?? null) !== $kennung) {
            throw Problem::ungueltig('Zur Bestätigung die Kennung des Mandanten angeben');
        }
        $adresse = $j['adresse'] ?? null;
        if ($adresse !== null && !is_string($adresse)) {
            throw Problem::ungueltig('adresse ist ein Hostname oder null');
        }
        try {
            $erg = \Kumero\Plattform\Mandantenadresse::setzen($a->datenbank, $a->konfig, $a->identitaet->subjekt, $kennung, $adresse === '' ? null : $adresse);
        } catch (\InvalidArgumentException $e) {
            throw Problem::ungueltig($e->getMessage());
        }
        return Antwort::json($erg);
    }

    public function exportieren(Anfrage $a): Response
    {
        return Antwort::json(['auftrag' => $this->auftrag($a, 'mandant_exportieren', $a->param('kennung'), null)], 202);
    }

    /** Löschen verlangt die Kennung noch einmal im Körper – als Bestätigung, dass genau dieser Mandant gemeint ist. */
    public function loeschen(Anfrage $a): Response
    {
        $kennung = $a->param('kennung');
        if (($a->json()['bestaetigung'] ?? null) !== $kennung) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'bestaetigung', 'meldung' => 'Zur Bestätigung die Kennung des Mandanten angeben']]);
        }
        return Antwort::json(['auftrag' => $this->auftrag($a, 'mandant_loeschen', $kennung, null)], 202);
    }

    /** @param array<string, string>|null $angaben */
    private function auftrag(Anfrage $a, string $art, string $kennung, ?array $angaben): string
    {
        return (string) $a->verwaltung()->fetchOne('SELECT verwaltung.auftrag_anlegen(?, ?, ?, ?::jsonb)',
            [$a->identitaet->subjekt, $art, $kennung, $angaben === null ? null : json_encode($angaben, JSON_UNESCAPED_UNICODE)]);
    }
}
