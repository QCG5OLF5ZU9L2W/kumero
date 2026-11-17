<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Http;

use Doctrine\DBAL\Connection;
use Kumero\Api\V1\Problem;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stammdaten und Geschäftsjahre (openapi/v1.yaml, Anhang A B1/B2).
 * Lesen per SELECT, Schreiben ausschließlich über Kernfunktionen (F64).
 */
final class StammdatenController
{
    private const KONTOARTEN = ['aktiv', 'passiv', 'aufwand', 'ertrag'];

    // --- Konten --------------------------------------------------------------
    public function kontenListe(Anfrage $a): Response
    {
        $q = $a->http->query;
        $wo = [];
        $p = [];
        if ($q->has('aktiv')) {
            $wo[] = 'k.aktiv = ?';
            $p[] = filter_var($q->get('aktiv'), FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        }
        if ($q->has('kontoart')) {
            if (!in_array($q->get('kontoart'), self::KONTOARTEN, true)) {
                throw Problem::ungueltig('kontoart unbekannt');
            }
            $wo[] = 'k.kontoart = ?::kern.kontoart';
            $p[] = $q->get('kontoart');
        }
        return Antwort::json(array_map([self::class, 'konto'],
            $a->db()->fetchAllAssociative(self::kontoSql() . ($wo ? ' WHERE ' . implode(' AND ', $wo) : '') . ' ORDER BY k.nr', $p)));
    }

    public function kontoLesen(Anfrage $a): Response
    {
        return Antwort::json(self::konto(self::kontoHolen($a->db(), $a->param('nr'))));
    }

    public function kontoAnlegen(Anfrage $a): Response
    {
        $e = new Eingabe($j = $a->json());
        $e->nurFelder(['nr', 'bezeichnung', 'kontoart']);
        $nr = $e->text('nr', true, 20);
        $bez = $e->text('bezeichnung', true, 200);
        $art = $e->auswahl('kontoart', self::KONTOARTEN);
        $e->pruefen();
        $a->db()->executeStatement('SELECT kern.konto_anlegen(?, ?, ?, ?::kern.kontoart)', [$a->kontext()->subjekt, $nr, $bez, $art]);
        return Antwort::json(self::konto(self::kontoHolen($a->db(), $nr)), 201,
            ['Location' => "/m/{$a->kontext()->mandant}/api/v1/konten/" . rawurlencode($nr)]);
    }

    public function kontoAendern(Anfrage $a): Response
    {
        $e = new Eingabe($j = $a->json());
        $e->nurFelder(['bezeichnung', 'aktiv', 'kontoart']);
        $bez = $e->text('bezeichnung', false, 200);
        $aktiv = $e->wahr('aktiv', false);
        $art = $e->auswahl('kontoart', self::KONTOARTEN, false);
        $e->pruefen();
        if ($j === []) {
            throw Problem::ungueltig('Mindestens ein Feld ändern');
        }
        $nr = $a->param('nr');
        self::kontoHolen($a->db(), $nr);
        $a->db()->executeStatement('SELECT kern.konto_aendern(?, ?, ?, ?::boolean, ?::kern.kontoart)',
            [$a->kontext()->subjekt, $nr, $bez, $aktiv === null ? null : ($aktiv ? 'true' : 'false'), $art]);
        return Antwort::json(self::konto(self::kontoHolen($a->db(), $nr)));
    }

    private static function kontoSql(): string
    {
        return "SELECT k.nr, k.bezeichnung, k.kontoart::text AS kontoart, k.aktiv,
                       EXISTS (SELECT 1 FROM kern.buchungszeile z JOIN kern.buchung b ON b.id = z.buchung
                                WHERE z.konto = k.id AND b.status = 'festgeschrieben') AS bebucht
                  FROM kern.konto k";
    }

    /** @return array<string, mixed> */
    private static function kontoHolen(Connection $db, string $nr): array
    {
        return $db->fetchAssociative(self::kontoSql() . ' WHERE k.nr = ?', [$nr]) ?: throw Problem::nichtGefunden("Konto $nr unbekannt");
    }

    private static function konto(array $z): array
    {
        return ['nr' => $z['nr'], 'bezeichnung' => $z['bezeichnung'], 'kontoart' => $z['kontoart'],
                'aktiv' => (bool) $z['aktiv'], 'bebucht' => (bool) $z['bebucht']];
    }

    // --- Tags -----------------------------------------------------------------
    public function tagsListe(Anfrage $a): Response
    {
        return Antwort::json(array_map([self::class, 'tag'],
            $a->db()->fetchAllAssociative('SELECT id, name, herkunft, paperless_id FROM kern.tag ORDER BY name')));
    }

    public function tagAnlegen(Anfrage $a): Response
    {
        $e = new Eingabe($a->json());
        $e->nurFelder(['name', 'paperless_id']);
        $name = $e->text('name', true, 100);
        $pid = $e->ganzzahl('paperless_id', false);
        $e->pruefen();
        $id = $a->db()->fetchOne('SELECT kern.tag_anlegen(?, ?, ?)', [$a->kontext()->subjekt, $name, $pid]);
        return Antwort::json(self::tag($a->db()->fetchAssociative('SELECT id, name, herkunft, paperless_id FROM kern.tag WHERE id = ?', [$id])), 201);
    }

    private static function tag(array $z): array
    {
        return ['id' => $z['id'], 'name' => $z['name'], 'herkunft' => $z['herkunft'],
                'paperless_id' => $z['paperless_id'] === null ? null : (int) $z['paperless_id']];
    }

    // --- Nummernkreise ----------------------------------------------------------
    public function nummernkreiseListe(Anfrage $a): Response
    {
        $db = $a->db();
        $jahre = [];
        foreach ($db->fetchAllAssociative(
            'SELECT nj.nummernkreis_id, g.kennung, nj.vorlage, nj.zuruecksetzen, nj.zaehler
               FROM kern.nummernkreis_jahr nj JOIN kern.geschaeftsjahr g ON g.id = nj.geschaeftsjahr_id
              ORDER BY lower(g.zeitraum)') as $z) {
            $jahre[$z['nummernkreis_id']][] = ['geschaeftsjahr' => $z['kennung'], 'vorlage' => $z['vorlage'],
                'zuruecksetzen' => (bool) $z['zuruecksetzen'], 'zaehler' => (int) $z['zaehler']];
        }
        return Antwort::json(array_map(fn ($n) => ['kuerzel' => $n['kuerzel'], 'bezeichnung' => $n['bezeichnung'], 'jahre' => $jahre[$n['id']] ?? []],
            $db->fetchAllAssociative('SELECT id, kuerzel, bezeichnung FROM kern.nummernkreis ORDER BY kuerzel')));
    }

    public function nummernkreisAnlegen(Anfrage $a): Response
    {
        $e = new Eingabe($a->json());
        $e->nurFelder(['kuerzel', 'bezeichnung']);
        $k = $e->text('kuerzel', true, 16);
        $b = $e->text('bezeichnung', true, 200);
        $e->pruefen();
        $a->db()->executeStatement('SELECT kern.nummernkreis_anlegen(?, ?, ?)', [$a->kontext()->subjekt, $k, $b]);
        return Antwort::json(['kuerzel' => $k, 'bezeichnung' => $b, 'jahre' => []], 201);
    }

    public function nummernkreisFestlegen(Anfrage $a): Response
    {
        $e = new Eingabe($a->json());
        $e->nurFelder(['vorlage', 'zuruecksetzen']);
        $vorlage = $e->text('vorlage', true, 64);
        $zurueck = $e->wahr('zuruecksetzen');
        $e->pruefen();
        $a->db()->executeStatement('SELECT kern.nummernkreis_festlegen(?, ?, ?, ?, ?::boolean)',
            [$a->kontext()->subjekt, $a->param('kuerzel'), $a->param('geschaeftsjahr'), $vorlage, $zurueck ? 'true' : 'false']);
        return Antwort::json(['geschaeftsjahr' => $a->param('geschaeftsjahr'), 'vorlage' => $vorlage, 'zuruecksetzen' => $zurueck]);
    }

    // --- Buchungsarten ------------------------------------------------------------
    public function buchungsartenListe(Anfrage $a): Response
    {
        return Antwort::json($a->db()->fetchAllAssociative(
            'SELECT b.kuerzel, b.bezeichnung, n.kuerzel AS nummernkreis FROM kern.buchungsart b
               JOIN kern.nummernkreis n ON n.id = b.standard_nummernkreis ORDER BY b.kuerzel'));
    }

    public function buchungsartAnlegen(Anfrage $a): Response
    {
        $e = new Eingabe($a->json());
        $e->nurFelder(['kuerzel', 'bezeichnung', 'nummernkreis']);
        $k = $e->text('kuerzel', true, 16);
        $b = $e->text('bezeichnung', true, 200);
        $n = $e->text('nummernkreis', true, 16);
        $e->pruefen();
        $a->db()->executeStatement('SELECT kern.buchungsart_anlegen(?, ?, ?, ?)', [$a->kontext()->subjekt, $k, $b, $n]);
        return Antwort::json(['kuerzel' => $k, 'bezeichnung' => $b, 'nummernkreis' => $n], 201);
    }

    // --- Geschäftsjahre ---------------------------------------------------------------
    public function geschaeftsjahreListe(Anfrage $a): Response
    {
        return Antwort::json(array_map([self::class, 'gj'], $a->db()->fetchAllAssociative(self::gjSql() . ' ORDER BY lower(zeitraum)')));
    }

    public function geschaeftsjahrAnlegen(Anfrage $a): Response
    {
        $e = new Eingabe($a->json());
        $e->nurFelder(['kennung', 'beginn', 'ende']);
        $k = $e->text('kennung', true, 32);
        $beginn = $e->datum('beginn');
        $ende = $e->datum('ende');
        $e->pruefen();
        $a->db()->executeStatement('SELECT kern.geschaeftsjahr_anlegen(?, ?, ?::date, ?::date)', [$a->kontext()->subjekt, $k, $beginn, $ende]);
        // Lückenlosigkeit (F65) prüft ein verzögerter Trigger erst beim Commit – hier sofort prüfen
        $a->db()->executeStatement('SET CONSTRAINTS ALL IMMEDIATE');
        return Antwort::json(self::gj($a->db()->fetchAssociative(self::gjSql() . ' WHERE kennung = ?', [$k])), 201);
    }

    private static function gjSql(): string
    {
        return "SELECT kennung, lower(zeitraum)::text AS beginn, (upper(zeitraum) - 1)::text AS ende, status::text AS status
                  FROM kern.geschaeftsjahr";
    }

    private static function gj(array $z): array
    {
        return ['kennung' => $z['kennung'], 'beginn' => $z['beginn'], 'ende' => $z['ende'], 'status' => $z['status']];
    }
}
