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
    private const HOECHSTENS = 5000;
    private const SQL = "SELECT e.id, e.zustand, e.betreff, e.art, e.gegenpartei, e.gegenpartei_kennung, e.datum::text AS datum,
                                e.betrag::text AS betrag, e.richtung, e.belegnummer, e.zahlweise, e.zahltag::text AS zahltag,
                                e.vor_konto, e.vor_gegenkonto, e.vor_steuerkonto, e.vor_buchungsart, e.netto::text AS netto, e.steuer::text AS steuer,
                                e.belege::text AS belege, e.hinweise::text AS hinweise, e.vermerke::text AS vermerke, e.verworfen_grund,
                                kern.zeitpunkt(e.eingegangen_am) AS eingegangen_am, kern.zeitpunkt(e.erledigt_am) AS erledigt_am, e.erledigt_von,
                                (SELECT coalesce(jsonb_agg(DISTINCT b.quelle), '[]')::text FROM arbeitsvorrat.beitrag b
                                  WHERE b.eintrag = e.id AND b.zurueckgezogen_am IS NULL) AS quellen,
                                (SELECT coalesce(jsonb_agg(jsonb_build_object('id', v.buchung::text, 'stand', v.stand) ORDER BY v.verknuepft_am), '[]')::text
                                   FROM arbeitsvorrat.eintrag_buchung v WHERE v.eintrag = e.id) AS buchungen,
                                (SELECT count(*) FROM arbeitsvorrat.eintrag z WHERE z.zusammengefuehrt_in = e.id) AS aufgegangen
                           FROM arbeitsvorrat.eintrag e";

    public function liste(Anfrage $a): Response
    {
        $q = $a->http->query;
        $zustand = (string) $q->get('zustand', 'offen');
        if (!in_array($zustand, self::ZUSTAENDE, true)) {
            throw Problem::ungueltig('zustand: offen, in_arbeit, gebucht oder verworfen erwartet');
        }
        // abgleich=nein: nur der Stand der Buchungen (schnell, aus der Datenbank) – die Quellen fragt die Oberfläche im Hintergrund über POST /abgleich
        $ausgefallen = $this->abgleichen($a, null, (string) $q->get('abgleich', 'ja') !== 'nein');
        $wo = ['e.zustand = ?'];
        $werte = [$zustand];
        foreach (['art' => 'e.art = ?', 'quelle' => 'EXISTS (SELECT 1 FROM arbeitsvorrat.beitrag b WHERE b.eintrag = e.id AND b.quelle = ? AND b.zurueckgezogen_am IS NULL)'] as $p => $sql) {
            if ((string) $q->get($p, '') !== '') {
                $wo[] = $sql;
                $werte[] = (string) $q->get($p);
            }
        }
        $zeilen = $a->db()->fetchAllAssociative(self::SQL . ' WHERE ' . implode(' AND ', $wo) . ' ORDER BY e.eingegangen_am, e.id LIMIT ' . (self::HOECHSTENS + 1), $werte);
        // Die Oberfläche blättert, filtert und sortiert über alle geladenen Einträge (F107); darüber hinaus wird abgeschnitten und gemeldet
        $mehr = count($zeilen) > self::HOECHSTENS;
        $eintraege = array_map([self::class, 'eintrag'], array_slice($zeilen, 0, self::HOECHSTENS));
        // vermutlich derselbe Vorgang (X3 Stufe 3, IMPORT.md C8): nur markieren, damit sich danach sortieren und filtern lässt
        $gruppen = $zustand === 'offen' ? self::gruppen($a, $eintraege) : [];
        foreach ($eintraege as $n => $e) {
            $eintraege[$n]['aehnlich'] = $gruppen[$e['id']] ?? null;
        }
        return Antwort::json([
            'eintraege' => $eintraege,
            'mehr' => $mehr,
            'quellen' => $this->quellen($a),
            'quellen_ausgefallen' => $ausgefallen,
            'auto_zusammenfuehren' => self::einstellung($a, 'auto_zusammenfuehren') === true,
        ]);
    }

    // --- Zusammenführen (X3, F105) -----------------------------------------------------------

    /** Vergleich zweier offener Einträge: beide mit ihren Beiträgen und der Vorschlag, welcher Wert je Feld gilt. */
    public function vergleich(Anfrage $a): Response
    {
        $x = $this->mitBeitraegen($a, $a->param('id'));
        $y = $this->mitBeitraegen($a, $a->param('anderer'));
        return Antwort::json(['links' => $x, 'rechts' => $y] + Zusammen::vorschlag($x, $y));
    }

    /** Führt zwei offene Einträge zusammen: {ziel, quelle, felder?} – ohne felder gilt der Vorschlag. */
    public function zusammenfuehren(Anfrage $a): Response
    {
        $j = $a->json();
        $ziel = $j['ziel'] ?? null;
        $quelle = $j['quelle'] ?? null;
        if (array_diff(array_keys($j), ['ziel', 'quelle', 'felder']) !== [] || !is_string($ziel) || !is_string($quelle)
            || !preg_match(self::UUID, $ziel) || !preg_match(self::UUID, $quelle) || $ziel === $quelle) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'ziel', 'meldung' => 'ziel und quelle (zwei verschiedene Einträge), dazu wahlweise felder erwartet']]);
        }
        $felder = $j['felder'] ?? null;
        if ($felder === null) {
            $felder = Zusammen::vorschlag(self::holen($a, $ziel), self::holen($a, $quelle))['felder'];
        } else {
            if (!is_array($felder) || array_diff(array_keys($felder), array_merge(Zusammen::VOM_BELEG, Zusammen::VOM_UMSATZ)) !== []) {
                throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'felder', 'meldung' => 'Objekt mit Feldern des Eintrags erwartet']]);
            }
            [$felder, $f] = Dienst::pruefen($felder + ['betreff' => $felder['betreff'] ?? self::holen($a, $ziel)['betreff']]);
            if ($f !== []) {
                throw Problem::ungueltig('Eingabe ungültig', $f);
            }
        }
        $a->db()->executeStatement('SELECT arbeitsvorrat.zusammenfuehren(?, ?::uuid, ?::uuid, ?::jsonb)',
            [$a->kontext()->subjekt, $ziel, $quelle, json_encode((object) $felder, JSON_UNESCAPED_UNICODE)]);
        return Antwort::json(self::holen($a, $ziel));
    }

    /** Trennt wieder, was in diesem (offenen) Eintrag aufgegangen ist. */
    public function trennen(Anfrage $a): Response
    {
        $e = self::holen($a, $a->param('id'));
        $n = (int) $a->db()->fetchOne('SELECT arbeitsvorrat.trennen(?, ?::uuid)', [$a->kontext()->subjekt, $e['id']]);
        return Antwort::json(['getrennt' => $n]);
    }

    /** Die genannten Einträge sind kein Duplikat voneinander: Sie werden nicht wieder gemeinsam markiert. */
    public function keinDuplikat(Anfrage $a): Response
    {
        $ids = $a->json()['eintraege'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || count($ids) < 2 || count($ids) > 50 || array_filter($ids, fn ($x) => !is_string($x) || !preg_match(self::UUID, $x)) !== []) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'eintraege', 'meldung' => 'Liste mit 2 bis 50 IDs erwartet']]);
        }
        foreach ($ids as $id) {
            self::holen($a, $id);
        }
        $n = (int) $a->db()->fetchOne('SELECT arbeitsvorrat.kein_duplikat_setzen(?, ?::uuid[])', [$a->kontext()->subjekt, '{' . implode(',', $ids) . '}']);
        return Antwort::json(['paare' => $n]);
    }

    /**
     * Zusammenführen von selbst – nur wenn im Mandanten eingeschaltet (Vorgabe: aus). Es werden nur Gruppen aus genau
     * zwei offenen Einträgen zusammengeführt, von denen genau einer einen Beleg hat; alles andere bleibt und wird genannt.
     */
    public function automatisch(Anfrage $a): Response
    {
        if (self::einstellung($a, 'auto_zusammenfuehren') !== true) {
            throw new Problem(409, 'arbeitsvorrat.auto_aus', 'Das Zusammenführen von selbst ist für diesen Mandanten nicht eingeschaltet');
        }
        $nur = $a->json(false)['eintraege'] ?? null;
        if ($nur !== null && (!is_array($nur) || array_filter($nur, fn ($x) => !is_string($x) || !preg_match(self::UUID, $x)) !== [])) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'eintraege', 'meldung' => 'Liste von IDs erwartet']]);
        }
        $db = $a->db();
        $offen = array_map([self::class, 'eintrag'], $db->fetchAllAssociative(self::SQL . " WHERE e.zustand = 'offen' ORDER BY e.eingegangen_am, e.id LIMIT 2000"));
        $nach = array_column($offen, null, 'id');
        $jeGruppe = [];
        foreach (self::gruppen($a, $offen) as $id => $g) {
            $jeGruppe[$g][] = $id;
        }
        $zusammen = 0;
        $uebersprungen = [];
        foreach ($jeGruppe as $ids) {
            if ($nur !== null && array_intersect($ids, $nur) === []) {
                continue;
            }
            $v = count($ids) === 2 ? Zusammen::vorschlag($nach[$ids[0]], $nach[$ids[1]]) : null;
            if ($v === null || !$v['eindeutig']) {
                $uebersprungen[] = ['eintraege' => $ids, 'grund' => $v === null ? 'mehr als zwei Einträge in der Gruppe' : 'nicht genau ein Eintrag mit Beleg'];
                continue;
            }
            $db->executeStatement('SELECT arbeitsvorrat.zusammenfuehren(?, ?::uuid, ?::uuid, ?::jsonb)',
                [$a->kontext()->subjekt, $v['ziel'], $v['quelle'], json_encode((object) $v['felder'], JSON_UNESCAPED_UNICODE)]);
            $zusammen++;
        }
        return Antwort::json(['zusammengefuehrt' => $zusammen, 'uebersprungen' => $uebersprungen]);
    }

    public function einstellungen(Anfrage $a): Response
    {
        return Antwort::json(['auto_zusammenfuehren' => self::einstellung($a, 'auto_zusammenfuehren') === true]);
    }

    public function einstellungenSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        if (array_keys($j) !== ['auto_zusammenfuehren'] || !is_bool($j['auto_zusammenfuehren'])) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'auto_zusammenfuehren', 'meldung' => 'true oder false erwartet']]);
        }
        $a->db()->executeStatement('SELECT arbeitsvorrat.einstellung_setzen(?, ?, ?::jsonb)', [$a->kontext()->subjekt, 'auto_zusammenfuehren', $j['auto_zusammenfuehren'] ? 'true' : 'false']);
        return $this->einstellungen($a);
    }

    private static function einstellung(Anfrage $a, string $schluessel): mixed
    {
        $w = $a->db()->fetchOne('SELECT wert::text FROM arbeitsvorrat.einstellung WHERE schluessel = ?', [$schluessel]);
        return $w === false ? null : json_decode((string) $w, true);
    }

    /** Gruppen vermutlich gleicher Vorgänge unter den genannten Einträgen, ohne die Paare „kein Duplikat“. @return array<string, int> */
    private static function gruppen(Anfrage $a, array $eintraege): array
    {
        $ausnahmen = [];
        foreach ($a->db()->fetchAllAssociative('SELECT a::text AS a, b::text AS b FROM arbeitsvorrat.kein_duplikat') as $z) {
            $ausnahmen[Aehnlich::paar($z['a'], $z['b'])] = true;
        }
        return Aehnlich::gruppen(array_map(fn ($e) => ['id' => $e['id'], 'betrag' => $e['betrag'], 'gegenpartei' => $e['gegenpartei'], 'belegnummer' => $e['belegnummer']], $eintraege), $ausnahmen);
    }

    /** @return array<string, mixed> */
    private function mitBeitraegen(Anfrage $a, string $id): array
    {
        $e = self::holen($a, $id);
        $e['beitraege'] = array_map(fn ($b) => ['quelle' => $b['quelle'], 'angaben' => json_decode((string) $b['angaben'], true), 'geliefert_am' => $b['geliefert_am']],
            $a->db()->fetchAllAssociative('SELECT quelle, angaben::text AS angaben, kern.zeitpunkt(geliefert_am) AS geliefert_am
               FROM arbeitsvorrat.beitrag WHERE eintrag = ?::uuid AND zurueckgezogen_am IS NULL AND rang = 0 ORDER BY geliefert_am, id', [$e['id']]));
        return $e;
    }

    /** Abgleich mit den befragten Quellen (W5) auf Anforderung; nennt je Quelle die Dauer – nur Modulname und Millisekunden. */
    public function abgleich(Anfrage $a): Response
    {
        $dauer = [];
        $ausgefallen = $this->abgleichen($a, null, true, $dauer);
        return Antwort::json(['quellen_ausgefallen' => $ausgefallen, 'dauer_ms' => (object) $dauer]);
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

    /** Vorauswahl für viele Einträge auf einmal (X1) oder für einen in der Liste (X7): Vorbelegung, keine Buchung. */
    public function vorauswahl(Anfrage $a): Response
    {
        $j = $a->json();
        $f = [];
        $daten = [];
        foreach (array_keys($j) as $k) {
            if (!in_array($k, ['eintraege', 'konto', 'gegenkonto', 'steuerkonto', 'buchungsart'], true)) {
                $f[] = ['feld' => (string) $k, 'meldung' => 'Unbekanntes Feld'];
            }
        }
        $ids = $j['eintraege'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > self::HOECHSTENS || array_filter($ids, fn ($x) => !is_string($x) || !preg_match(self::UUID, $x)) !== []) {
            $f[] = ['feld' => 'eintraege', 'meldung' => 'Liste mit 1 bis ' . self::HOECHSTENS . ' IDs erwartet'];
        }
        foreach (['konto' => 20, 'gegenkonto' => 20, 'steuerkonto' => 20, 'buchungsart' => 16] as $feld => $max) {
            if (!array_key_exists($feld, $j)) {
                continue;
            }
            $w = $j[$feld];
            if ($w !== null && (!is_string($w) || trim($w) === '' || strlen(trim($w)) > $max)) {
                $f[] = ['feld' => $feld, 'meldung' => "Text mit höchstens $max Zeichen oder null erwartet"];
                continue;
            }
            $daten[$feld] = $w === null ? null : trim($w);
        }
        if ($daten === [] && $f === []) {
            $f[] = ['feld' => 'konto', 'meldung' => 'Mindestens konto, gegenkonto, steuerkonto oder buchungsart angeben'];
        }
        if ($f !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        $n = (int) $a->db()->fetchOne('SELECT arbeitsvorrat.vorauswahl_setzen(?, ?::uuid[], ?::jsonb)',
            [$a->kontext()->subjekt, '{' . implode(',', $ids) . '}', json_encode((object) $daten)]);
        return Antwort::json(['geaendert' => $n]);
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

    /**
     * Viele offene Einträge mit einem Grund verwerfen – ganz oder gar nicht: Ist einer nicht mehr offen,
     * bleibt alles, wie es war. Jeder Eintrag bekommt seinen eigenen Protokollsatz.
     */
    public function vieleVerwerfen(Anfrage $a): Response
    {
        $j = $a->json();
        $f = [];
        $grund = $j['grund'] ?? null;
        $ids = $j['eintraege'] ?? null;
        if (array_diff(array_keys($j), ['grund', 'eintraege']) !== []) {
            $f[] = ['feld' => 'eintraege', 'meldung' => 'Nur eintraege und grund erwartet'];
        }
        if (!is_string($grund) || trim($grund) === '' || mb_strlen($grund) > 300) {
            $f[] = ['feld' => 'grund', 'meldung' => 'Grund mit 1 bis 300 Zeichen angeben'];
        }
        if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > self::HOECHSTENS || array_filter($ids, fn ($x) => !is_string($x) || !preg_match(self::UUID, $x)) !== []) {
            $f[] = ['feld' => 'eintraege', 'meldung' => 'Liste mit 1 bis ' . self::HOECHSTENS . ' IDs erwartet'];
        }
        if ($f !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        $ids = array_values(array_unique($ids));
        $db = $a->db();
        $subjekt = $a->kontext()->subjekt;
        $db->transactional(function () use ($db, $subjekt, $ids, $grund): void {
            foreach ($ids as $id) {
                $db->executeStatement('SELECT arbeitsvorrat.verwerfen(?, ?::uuid, ?)', [$subjekt, $id, $grund]);
            }
        });
        return Antwort::json(['verworfen' => count($ids)]);
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
    private function abgleichen(Anfrage $a, ?string $nur = null, bool $quellen = true, array &$dauer = []): array
    {
        $db = $a->db();
        $k = $a->kontext();
        $ausgefallen = [];
        if ($k->lesemodus) {
            return [];
        }
        // Erst der Stand der Buchungen, dann die Quellen: So sehen sie schon, was gebucht oder wieder offen ist
        $zeilen = $db->fetchAllAssociative(
            "SELECT v.eintrag, v.buchung, v.stand FROM arbeitsvorrat.eintrag_buchung v JOIN arbeitsvorrat.eintrag e ON e.id = v.eintrag
              WHERE e.zustand IN ('in_arbeit', 'gebucht')" . ($nur === null ? '' : ' AND e.id = ?::uuid') . ' LIMIT 5000',
            $nur === null ? [] : [preg_match(self::UUID, $nur) ? $nur : '00000000-0000-0000-0000-000000000000']);
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
        if ($nur === null) {
            $this->schonGebucht($a);
        }
        if ($nur === null && $quellen) {
            $dienst = new Dienst($db, $k->subjekt);
            foreach (Dienste::aktiveModule($db) as $name => $m) {
                if (!isset($m['manifest']['arbeitsvorrat_quelle']) || !$m['einstieg'] instanceof Quelle) {
                    continue;
                }
                $beginn = hrtime(true);
                try {
                    $db->transactional(fn () => $m['einstieg']->arbeitsvorratAbgleich($a, $dienst));
                } catch (\Throwable $f) {
                    error_log(sprintf('kumero[%s] arbeitsvorrat_abgleich von %s fehlgeschlagen: %s', $k->anfrageId, $name, $f::class));
                    $ausgefallen[] = $name;
                }
                $dauer[$name] = (int) round((hrtime(true) - $beginn) / 1e6);
            }
        }
        return $ausgefallen;
    }

    /**
     * Abgleich mit dem Journal (X4): Gibt es zu einem offenen Eintrag schon eine festgeschriebene, nicht
     * stornierte Buchung mit demselben Beleg – gleicher Link oder gleiche Prüfsumme –, gilt er als gebucht.
     */
    private function schonGebucht(Anfrage $a): void
    {
        $db = $a->db();
        $offen = $db->fetchAllAssociative("SELECT id, belege::text AS belege FROM arbeitsvorrat.eintrag WHERE zustand = 'offen' AND belege <> '[]' LIMIT 2000");
        $belege = [];
        foreach ($offen as $n => $e) {
            $offen[$n]['belege'] = json_decode((string) $e['belege'], true);
            array_push($belege, ...$offen[$n]['belege']);
        }
        $treffer = Dienste::buchungenZuBelegen($db, $belege);
        if ($treffer === []) {
            return;
        }
        foreach ($offen as $e) {
            foreach ($treffer as $t) {
                foreach ($e['belege'] as $b) {
                    if (($b['link'] ?? null) === $t['link'] || (isset($b['sha256']) && $b['sha256'] === $t['sha256']) || (isset($b['md5']) && $b['md5'] === $t['md5'])) {
                        try {
                            $db->transactional(function () use ($db, $a, $e, $t): void {
                                $db->executeStatement('SELECT arbeitsvorrat.verknuepfen(?, ?::uuid, ?::uuid)', [$a->kontext()->subjekt, $e['id'], $t['buchung']]);
                                $db->executeStatement('SELECT arbeitsvorrat.abgleichen(?, ?::uuid, ?::jsonb)', [$a->kontext()->subjekt, $e['id'], json_encode([$t['buchung'] => 'festgeschrieben'])]);
                            });
                        } catch (\Throwable) {
                            // die Buchung gehört schon zu einem anderen Eintrag: dieser bleibt offen
                        }
                        continue 3;
                    }
                }
            }
        }
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
        $z['aufgegangen'] = (int) ($z['aufgegangen'] ?? 0);
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
