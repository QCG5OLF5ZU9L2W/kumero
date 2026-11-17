<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Arbeitsvorrat;

use Kumero\Api\V1\Dienste;
use Kumero\Api\V1\Problem;
use Kumero\Http\Antwort;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST des Arbeitsvorrats (ARBEITSVORRAT.md Abschnitt 8). Lesen per SELECT im eigenen Schema,
 * Schreiben nur über die Funktionen des Moduls (F44, F64). Der Stand der Buchungen kommt über
 * die Modul-API des Kerns; die Regeln für den Zustand stehen in der Datenbank.
 */
final class WebController
{
    private const QUELLE_HAND = 'arbeitsvorrat';
    private const ZUSTAENDE = ['offen', 'in_arbeit', 'gebucht', 'verworfen'];
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const SQL = "SELECT e.id, e.zustand, e.betreff, e.art, e.gegenpartei, e.gegenpartei_kennung, e.datum::text AS datum,
                                e.betrag::text AS betrag, e.richtung, e.belegnummer, e.zahlweise, e.zahltag::text AS zahltag,
                                e.belege::text AS belege, e.hinweise::text AS hinweise, e.vermerke::text AS vermerke, e.verworfen_grund,
                                kern.zeitpunkt(e.eingegangen_am) AS eingegangen_am, kern.zeitpunkt(e.erledigt_am) AS erledigt_am, e.erledigt_von,
                                (SELECT coalesce(jsonb_agg(DISTINCT b.quelle), '[]')::text FROM arbeitsvorrat.beitrag b
                                  WHERE b.eintrag = e.id AND b.zurueckgezogen_am IS NULL) AS quellen,
                                (SELECT coalesce(jsonb_agg(jsonb_build_object('id', v.buchung::text, 'stand', v.stand) ORDER BY v.verknuepft_am), '[]')::text
                                   FROM arbeitsvorrat.eintrag_buchung v WHERE v.eintrag = e.id) AS buchungen
                           FROM arbeitsvorrat.eintrag e";

    public function liste(Anfrage $a): Response
    {
        $q = $a->http->query;
        $zustand = (string) $q->get('zustand', 'offen');
        if (!in_array($zustand, self::ZUSTAENDE, true)) {
            throw Problem::ungueltig('zustand: offen, in_arbeit, gebucht oder verworfen erwartet');
        }
        $ausgefallen = $this->abgleichen($a);
        $wo = ['e.zustand = ?'];
        $werte = [$zustand];
        foreach (['art' => 'e.art = ?', 'quelle' => 'EXISTS (SELECT 1 FROM arbeitsvorrat.beitrag b WHERE b.eintrag = e.id AND b.quelle = ? AND b.zurueckgezogen_am IS NULL)'] as $p => $sql) {
            if ((string) $q->get($p, '') !== '') {
                $wo[] = $sql;
                $werte[] = (string) $q->get($p);
            }
        }
        $zeilen = $a->db()->fetchAllAssociative(self::SQL . ' WHERE ' . implode(' AND ', $wo) . ' ORDER BY e.eingegangen_am, e.id LIMIT 501', $werte);
        $mehr = count($zeilen) > 500;
        return Antwort::json([
            'eintraege' => array_map([self::class, 'eintrag'], array_slice($zeilen, 0, 500)),
            'mehr' => $mehr,
            'quellen' => $this->quellen($a),
            'quellen_ausgefallen' => $ausgefallen,
        ]);
    }

    public function lesen(Anfrage $a): Response
    {
        $this->abgleichen($a, $a->param('id'));
        $e = self::holen($a, $a->param('id'));
        $db = $a->db();
        $e['beitraege'] = array_map(fn ($b) => ['quelle' => $b['quelle'], 'schluessel' => $b['schluessel'],
                'angaben' => json_decode((string) $b['angaben'], true), 'geliefert_am' => $b['geliefert_am'], 'geaendert_am' => $b['geaendert_am'],
                'zurueckgezogen_am' => $b['zurueckgezogen_am']],
            $db->fetchAllAssociative('SELECT quelle, schluessel, angaben::text AS angaben, kern.zeitpunkt(geliefert_am) AS geliefert_am,
                    kern.zeitpunkt(geaendert_am) AS geaendert_am, kern.zeitpunkt(zurueckgezogen_am) AS zurueckgezogen_am
               FROM arbeitsvorrat.beitrag WHERE eintrag = ?::uuid ORDER BY geliefert_am, id', [$e['id']]));
        // Vorbelegung aus den Regeln des Mandanten (Abschnitt 7); die Maske wendet sie an
        $vorlage = $e['art'] === null ? false : $db->fetchOne('SELECT vorlage FROM arbeitsvorrat.regel_art WHERE art = ?', [$e['art']]);
        $konto = $e['gegenpartei'] === null ? false : $db->fetchOne('SELECT konto FROM arbeitsvorrat.gemerkt WHERE schluessel = arbeitsvorrat._gp_schluessel(?, ?)',
            [$e['gegenpartei_kennung'], $e['gegenpartei']]);
        $e['vorbelegung'] = ['vorlage' => $vorlage ?: null, 'konto_gegenpartei' => $konto ?: null];
        return Antwort::json($e);
    }

    /** Eintrag von Hand (W9): läuft über denselben Vertrag wie jede Quelle. */
    public function anlegen(Anfrage $a): Response
    {
        $j = $a->json();
        $notiz = $j['notiz'] ?? null;
        unset($j['notiz']);
        if ($notiz !== null && (!is_string($notiz) || mb_strlen($notiz) > 1000)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'notiz', 'meldung' => 'Text mit höchstens 1000 Zeichen erwartet']]);
        }
        $angaben = is_string($notiz) && trim($notiz) !== '' ? [['bezeichnung' => 'Notiz', 'wert' => trim($notiz)]] : [];
        $erg = (new Dienst($a->db(), $a->kontext()->subjekt))->einliefern(self::QUELLE_HAND, self::uuid(), $j, $angaben);
        return Antwort::json(self::holen($a, $erg['eintrag']), 201);
    }

    public function verknuepfen(Anfrage $a): Response
    {
        $j = $a->json();
        $f = [];
        foreach (array_keys($j) as $k) {
            if (!in_array($k, ['buchung', 'konto'], true)) {
                $f[] = ['feld' => (string) $k, 'meldung' => 'Unbekanntes Feld'];
            }
        }
        if (!is_string($j['buchung'] ?? null) || !preg_match(self::UUID, $j['buchung'])) {
            $f[] = ['feld' => 'buchung', 'meldung' => 'ID der Buchung erwartet'];
        }
        $konto = $j['konto'] ?? null;
        if ($konto !== null && (!is_string($konto) || $konto === '' || strlen($konto) > 20)) {
            $f[] = ['feld' => 'konto', 'meldung' => 'Kontonummer erwartet'];
        }
        if ($f !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        $id = self::holen($a, $a->param('id'))['id'];
        if (Dienste::buchungsstaende($a->db(), [$j['buchung']])[$j['buchung']] === 'fehlt') {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'buchung', 'meldung' => 'Buchung unbekannt']]);
        }
        $a->db()->executeStatement('SELECT arbeitsvorrat.verknuepfen(?, ?::uuid, ?::uuid, ?)', [$a->kontext()->subjekt, $id, $j['buchung'], $konto]);
        $this->abgleichen($a, $id);
        return Antwort::json(self::holen($a, $id));
    }

    public function verwerfen(Anfrage $a): Response
    {
        $j = $a->json();
        $grund = $j['grund'] ?? null;
        if (array_diff(array_keys($j), ['grund']) !== [] || !is_string($grund) || trim($grund) === '' || mb_strlen($grund) > 300) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'grund', 'meldung' => 'Grund mit 1 bis 300 Zeichen angeben']]);
        }
        $id = self::holen($a, $a->param('id'))['id'];
        $a->db()->executeStatement('SELECT arbeitsvorrat.verwerfen(?, ?::uuid, ?)', [$a->kontext()->subjekt, $id, $grund]);
        return Antwort::json(self::holen($a, $id));
    }

    public function oeffnen(Anfrage $a): Response
    {
        $id = self::holen($a, $a->param('id'))['id'];
        $a->db()->executeStatement('SELECT arbeitsvorrat.oeffnen(?, ?::uuid)', [$a->kontext()->subjekt, $id]);
        return Antwort::json(self::holen($a, $id));
    }

    /** Zahl der offenen Einträge für das Menü – ohne Abgleich, damit das Menü nie wartet. */
    public function zaehler(Anfrage $a): Response
    {
        return Antwort::json(['anzahl' => (int) $a->db()->fetchOne("SELECT count(*) FROM arbeitsvorrat.eintrag WHERE zustand = 'offen'")]);
    }

    public function regeln(Anfrage $a): Response
    {
        $db = $a->db();
        return Antwort::json([
            'arten' => $db->fetchAllAssociative('SELECT art, vorlage FROM arbeitsvorrat.regel_art ORDER BY art'),
            'gegenparteien' => $db->fetchAllAssociative('SELECT schluessel, gegenpartei, konto, kern.zeitpunkt(geaendert_am) AS geaendert_am
                                                           FROM arbeitsvorrat.gemerkt ORDER BY lower(gegenpartei)'),
            'bekannte_arten' => $db->fetchFirstColumn('SELECT DISTINCT art FROM arbeitsvorrat.eintrag WHERE art IS NOT NULL ORDER BY art'),
        ]);
    }

    public function regelSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        $art = $a->param('art');
        if (!preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $art)) {
            throw Problem::ungueltig('Art: Stichwort in Kleinbuchstaben erwartet');
        }
        if (array_diff(array_keys($j), ['vorlage']) !== [] || !is_string($j['vorlage'] ?? null) || !preg_match(self::UUID, $j['vorlage'])) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'vorlage', 'meldung' => 'ID eines Geschäftsvorfalls erwartet']]);
        }
        $a->db()->executeStatement('SELECT arbeitsvorrat.regel_art_setzen(?, ?, ?::uuid)', [$a->kontext()->subjekt, $art, $j['vorlage']]);
        return Antwort::json(['art' => $art, 'vorlage' => $j['vorlage']]);
    }

    public function regelLoeschen(Anfrage $a): Response
    {
        if (!$a->db()->fetchOne('SELECT 1 FROM arbeitsvorrat.regel_art WHERE art = ?', [$a->param('art')])) {
            throw Problem::nichtGefunden('Regel unbekannt');
        }
        $a->db()->executeStatement('SELECT arbeitsvorrat.regel_art_setzen(?, ?, NULL)', [$a->kontext()->subjekt, $a->param('art')]);
        return Antwort::leer();
    }

    public function gemerktLoeschen(Anfrage $a): Response
    {
        $j = $a->json();
        if (array_diff(array_keys($j), ['schluessel']) !== [] || !is_string($j['schluessel'] ?? null) || $j['schluessel'] === '') {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'schluessel', 'meldung' => 'Pflichtfeld']]);
        }
        if (!$a->db()->fetchOne('SELECT 1 FROM arbeitsvorrat.gemerkt WHERE schluessel = ?', [$j['schluessel']])) {
            throw Problem::nichtGefunden('Zuordnung unbekannt');
        }
        $a->db()->executeStatement('SELECT arbeitsvorrat.gemerkt_loeschen(?, ?)', [$a->kontext()->subjekt, $j['schluessel']]);
        return Antwort::leer();
    }

    // --- Abgleich -------------------------------------------------------------------------

    /**
     * Vor dem Lesen: befragte Quellen aufrufen (nur für die Liste) und den Zustand aus dem Stand der
     * Buchungen ableiten. Nach bestem Bemühen – im Lesemodus oder bei Ausfall einer Quelle wird
     * gezeigt, was da ist (F59).
     *
     * @return list<string> Namen der Quellen, die nicht geantwortet haben
     */
    private function abgleichen(Anfrage $a, ?string $nur = null): array
    {
        $db = $a->db();
        $k = $a->kontext();
        $ausgefallen = [];
        if ($k->lesemodus) {
            return [];
        }
        if ($nur === null) {
            $dienst = new Dienst($db, $k->subjekt);
            foreach (Dienste::aktiveModule($db) as $name => $m) {
                if (!isset($m['manifest']['arbeitsvorrat_quelle']) || !$m['einstieg'] instanceof Quelle) {
                    continue;
                }
                try {
                    $db->transactional(fn () => $m['einstieg']->arbeitsvorratAbgleich($k, $db, $dienst));
                } catch (\Throwable $f) {
                    error_log(sprintf('kumero[%s] arbeitsvorrat_abgleich von %s fehlgeschlagen: %s', $k->anfrageId, $name, $f::class));
                    $ausgefallen[] = $name;
                }
            }
        }
        $zeilen = $db->fetchAllAssociative(
            "SELECT v.eintrag, v.buchung, v.stand FROM arbeitsvorrat.eintrag_buchung v JOIN arbeitsvorrat.eintrag e ON e.id = v.eintrag
              WHERE e.zustand IN ('in_arbeit', 'gebucht')" . ($nur === null ? '' : ' AND e.id = ?::uuid') . ' LIMIT 5000',
            $nur === null ? [] : [preg_match(self::UUID, $nur) ? $nur : '00000000-0000-0000-0000-000000000000']);
        if ($zeilen === []) {
            return $ausgefallen;
        }
        $staende = Dienste::buchungsstaende($db, array_column($zeilen, 'buchung'));
        $anders = [];
        foreach ($zeilen as $z) {
            if ($staende[$z['buchung']] !== $z['stand']) {
                $anders[$z['eintrag']][$z['buchung']] = $staende[$z['buchung']];
            }
        }
        foreach ($anders as $eintrag => $s) {
            try {
                $db->transactional(fn () => $db->executeStatement('SELECT arbeitsvorrat.abgleichen(?, ?::uuid, ?::jsonb)', [$k->subjekt, $eintrag, json_encode($s)]));
            } catch (\Throwable $f) {
                error_log(sprintf('kumero[%s] arbeitsvorrat.abgleichen fehlgeschlagen: %s', $k->anfrageId, $f::class));
            }
        }
        return $ausgefallen;
    }

    /** @return list<array{name: string, titel: string}> Quellen, die im Mandanten einliefern können */
    private function quellen(Anfrage $a): array
    {
        $erg = [['name' => self::QUELLE_HAND, 'titel' => 'von Hand']];
        foreach (Dienste::aktiveModule($a->db()) as $name => $m) {
            if (isset($m['manifest']['arbeitsvorrat_quelle'])) {
                $erg[] = ['name' => $name, 'titel' => (string) ($m['manifest']['arbeitsvorrat_quelle']['titel'] ?? $name)];
            }
        }
        return $erg;
    }

    // --- Darstellung ----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function holen(Anfrage $a, string $id): array
    {
        $z = preg_match(self::UUID, $id) ? $a->db()->fetchAssociative(self::SQL . " WHERE e.id = ?::uuid AND e.zustand <> 'zurueckgezogen'", [$id]) : false;
        return self::eintrag($z ?: throw Problem::nichtGefunden('Eintrag unbekannt'));
    }

    /** @param array<string, mixed> $z @return array<string, mixed> */
    private static function eintrag(array $z): array
    {
        foreach (['belege', 'hinweise', 'vermerke', 'quellen', 'buchungen'] as $f) {
            $z[$f] = json_decode((string) $z[$f], true);
        }
        return $z;
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
