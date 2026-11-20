<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Http;

use Kumero\Api\V1\Problem;
use Kumero\Plattform\Anfrage;
use Kumero\Plattform\Kernel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bücher eines Mandanten (BUECHER.md Abschnitt 8, F126) – Ebene Mandant.
 *
 * Ein Buch ist eine eigenständige Buchhaltung mit eigener Datenbank. Anlegen darf die Webanwendung keine Datenbank:
 * Sie schreibt einen Auftrag, den der Dienst `kumero-auftraege` ausführt (wie „Mandant anlegen“, F118).
 */
final class BuecherController
{
    /** Bücher, in die der Nutzer hineindarf; der Administrator sieht alle – ohne Inhalt. */
    public function liste(Anfrage $a): Response
    {
        $ktx = $a->kontext();
        $admin = $ktx->darf(Kernel::RECHT_ADMINISTRATOR);
        $datenbanken = $a->verwaltung()->fetchAllKeyValue('SELECT kennung, datenbank FROM verwaltung.buch WHERE mandant = ?', [$ktx->mandant]);
        $buecher = [];
        foreach ($a->mandantDb()->fetchAllAssociative(
            "SELECT kennung, name, art, testbuch, kopie_von, to_char(kopie_stand AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS kopie_stand
               FROM mandant.buch ORDER BY testbuch, lower(name), kennung") as $b) {
            if (!isset($datenbanken[$b['kennung']])) {
                continue;
            }
            // wer in ein Buch hineindarf, sagt allein das Buch: eine laufende Zuweisung (A11)
            $db = $a->datenbank->verbindung($datenbanken[$b['kennung']]);
            $zugang = (bool) $db->fetchOne('SELECT 1 FROM kern.rechte_des_subjekts(?) LIMIT 1', [$ktx->subjekt]);
            if (!$zugang && !$admin) {
                continue;
            }
            $stand = $db->fetchAssociative('SELECT betriebsart::text AS betriebsart, lesemodus FROM kern.buch');
            $buecher[] = ['kennung' => $b['kennung'], 'name' => $b['name'], 'art' => $b['art'], 'testbuch' => (bool) $b['testbuch'],
                'betriebsart' => $stand['betriebsart'] ?? null, 'lesemodus' => (bool) ($stand['lesemodus'] ?? false),
                'kopie_von' => $b['kopie_von'], 'kopie_stand' => $b['kopie_stand'], 'zustand' => 'bereit', 'zugang' => $zugang];
        }
        // ein Buch, das gerade angelegt wird, sieht der Administrator als Auftrag
        $auftraege = !$admin ? [] : $a->verwaltung()->fetchAllAssociative(
            "SELECT id, zustand, to_char(angelegt_am AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS angelegt_am, ergebnis::text AS ergebnis
               FROM verwaltung.auftrag WHERE mandant = ? AND art = 'buch_anlegen' AND angelegt_am > now() - interval '7 days'
              ORDER BY angelegt_am DESC LIMIT 20", [$ktx->mandant]);
        return Antwort::json(['buecher' => $buecher, 'administrator' => $admin,
            'auftraege' => array_map(fn (array $x) => ['id' => $x['id'], 'zustand' => $x['zustand'], 'angelegt_am' => $x['angelegt_am'],
                'ergebnis' => $x['ergebnis'] === null ? null : json_decode((string) $x['ergebnis'], true)], $auftraege)]);
    }

    /** Buch anlegen – als Auftrag an den Dienst. */
    public function anlegen(Anfrage $a): Response
    {
        $e = new Eingabe($j = $a->json());
        $e->nurFelder(['kennung', 'name', 'art', 'buchwaehrung', 'testbuch']);
        $kennung = (string) $e->text('kennung', true, 20);
        $name = (string) $e->text('name', true, 100);
        $art = $e->auswahl('art', ['euer', 'bilanz']);
        $waehrung = $e->text('buchwaehrung', false, 3) ?? 'EUR';
        $testbuch = $e->wahr('testbuch', false) ?? false;
        $e->pruefen();
        if (!preg_match('/^[a-z][a-z0-9-]{1,19}$/', $kennung)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'kennung', 'meldung' => 'Kleinbuchstaben, Ziffern, Bindestrich; 2 bis 20 Zeichen; beginnt mit einem Buchstaben']]);
        }
        if (!preg_match('/^[A-Z]{3}$/', $waehrung)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'buchwaehrung', 'meldung' => 'Währung nach ISO 4217, z. B. EUR']]);
        }
        $ktx = $a->kontext();
        $id = (string) $a->verwaltung()->fetchOne('SELECT verwaltung.buch_auftrag_anlegen(?, ?::jsonb)', [$ktx->mandant, json_encode([
            'kennung' => $kennung, 'name' => trim($name), 'art' => $art, 'buchwaehrung' => $waehrung, 'testbuch' => $testbuch, 'subjekt' => $ktx->subjekt,
        ], JSON_UNESCAPED_UNICODE)]);
        return Antwort::json(['id' => $id, 'art' => 'buch_anlegen', 'zustand' => 'offen'], 202);
    }

    public function umbenennen(Anfrage $a): Response
    {
        $e = new Eingabe($a->json());
        $e->nurFelder(['name']);
        $name = (string) $e->text('name', true, 100);
        $e->pruefen();
        if (!$a->mandantDb()->fetchOne('SELECT 1 FROM mandant.buch WHERE kennung = ?', [$a->param('buch')])) {
            throw Problem::nichtGefunden('Buch unbekannt');
        }
        $a->mandantDb()->executeStatement('SELECT mandant.buch_umbenennen(?, ?, ?)', [$a->kontext()->subjekt, $a->param('buch'), $name]);
        return Antwort::leer();
    }
}
