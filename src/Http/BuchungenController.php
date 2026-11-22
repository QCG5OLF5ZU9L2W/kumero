<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Http;

use Doctrine\DBAL\Connection;
use Kumero\Api\V1\Problem;
use Kumero\Plattform\Anfrage;
use Kumero\Plattform\Webauthn\Base64Url;
use Kumero\Plattform\Webauthn\WebauthnFehler;
use Symfony\Component\HttpFoundation\Response;

/**
 * Buchungen, Freigabe und Abfragen (openapi/v1.yaml, Anhang A B3/B4; F20, F30, F31, F66–F68, F78).
 *
 * Lesen per SELECT, Schreiben ausschließlich über Kernfunktionen (F64). Die Buchungsregeln prüft
 * PostgreSQL (F72); hier wird nur die Form der Eingabe geprüft und das Ergebnis dargestellt.
 * Meldungen und Logs enthalten keine Buchungsinhalte.
 */
final class BuchungenController
{
    private const STATUS = ['entwurf', 'freigabebereit', 'festgeschrieben'];
    private const BELEG_LINK = '~^https?://[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:[0-9]{1,5})?(/[^\s?#]*)?$~';
    private const KOPF = ['buchungsart', 'nummernkreis', 'belegdatum', 'buchungsdatum', 'belegnummer', 'text', 'erweiterung', 'belege', 'zeilen'];
    private const ZEILE = ['konto', 'seite', 'betrag', 'fw_betrag', 'fw_waehrung', 'kurs', 'kurs_datum', 'kurs_art', 'tags', 'erweiterung'];
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    // --- Liste -------------------------------------------------------------------
    public function liste(Anfrage $a): Response
    {
        $q = $a->http->query;
        $wo = [];
        $p = [];
        if ($q->has('status')) {
            $status = array_values(array_filter(explode(',', (string) $q->get('status')), fn ($s) => $s !== ''));
            if ($status === [] || array_diff($status, self::STATUS) !== []) {
                throw Problem::ungueltig('status: erlaubt sind ' . implode(', ', self::STATUS));
            }
            $wo[] = 'b.status::text IN (' . implode(',', array_fill(0, count($status), '?')) . ')';
            array_push($p, ...$status);
        }
        foreach (['von' => '>=', 'bis' => '<='] as $name => $vergleich) {
            if ($q->has($name)) {
                $wo[] = "b.buchungsdatum $vergleich ?::date";
                $p[] = self::datum((string) $q->get($name), $name);
            }
        }
        if ($q->has('konto')) {
            $wo[] = 'EXISTS (SELECT 1 FROM kern.buchungszeile z JOIN kern.konto k ON k.id = z.konto WHERE z.buchung = b.id AND k.nr = ?)';
            $p[] = (string) $q->get('konto');
        }
        if ($q->has('buchungsart')) {
            $wo[] = 'b.buchungsart = (SELECT id FROM kern.buchungsart WHERE kuerzel = ?)';
            $p[] = (string) $q->get('buchungsart');
        }
        if ($q->has('erfasst_von')) {
            $wo[] = 'b.erfasst_von = ?';
            $p[] = (string) $q->get('erfasst_von');
        }
        $anzahl = self::anzahl($a);
        $ab = match ((string) $q->get('reihenfolge', 'auf')) {
            'auf' => false,
            'ab' => true,
            default => throw Problem::ungueltig('reihenfolge: auf oder ab'),
        };
        // Festgeschriebene nach Journalnummer, Entwürfe nach Erfassungszeit – ein gemeinsamer Sortierschlüssel
        $schluessel = "((b.status = 'festgeschrieben')::int, coalesce(b.journal_nr, 0), b.erfasst_am, b.id)";
        if ($q->has('cursor')) {
            $c = self::cursorLesen((string) $q->get('cursor'), 4);
            $wo[] = "$schluessel " . ($ab ? '<' : '>') . ' (?::int, ?::bigint, ?::timestamptz, ?::uuid)';
            array_push($p, ...$c);
        }
        $richtung = $ab ? 'DESC' : 'ASC';
        $zeilen = $a->db()->fetchAllAssociative(
            self::sql() . ($wo ? ' WHERE ' . implode(' AND ', $wo) : '')
            . " ORDER BY (b.status = 'festgeschrieben')::int $richtung, coalesce(b.journal_nr, 0) $richtung, b.erfasst_am $richtung, b.id $richtung"
            . ' LIMIT ' . ($anzahl + 1), $p);
        $weiter = null;
        if (count($zeilen) > $anzahl) {
            array_pop($zeilen);
            $l = $zeilen[$anzahl - 1];
            $weiter = self::cursor([$l['status'] === 'festgeschrieben' ? 1 : 0, (int) ($l['journal_nr'] ?? 0), $l['sort_erfasst'], $l['id']]);
        }
        return Antwort::json(['eintraege' => array_map([self::class, 'buchung'], $zeilen), 'weiter' => $weiter]);
    }

    // --- Entwürfe ------------------------------------------------------------------
    public function anlegen(Anfrage $a): Response
    {
        $j = $a->json();
        $vorbereiten = $j['vorbereiten'] ?? false;
        unset($j['vorbereiten']);
        if (!is_bool($vorbereiten)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'vorbereiten', 'meldung' => 'true oder false erwartet']]);
        }
        if ($vorbereiten && !$a->kontext()->darf('kern.entwurf.aendern')) {
            throw new Problem(403, 'recht.fehlt', 'Recht fehlt: kern.entwurf.aendern');
        }
        $daten = self::inhaltPruefen($j);
        BelegeController::vorlaeufigAblehnen($a, $daten['belege'] ?? []);
        $db = $a->db();
        $id = (string) $db->fetchOne('SELECT kern.entwurf_anlegen(?, ?::jsonb, ?::kern.herkunft)',
            [$a->kontext()->subjekt, self::json($daten), $a->identitaet?->art === 'token' ? 'api' : 'manuell']);
        if ($vorbereiten) {
            $db->fetchOne('SELECT kern.vorbereiten(?, ?::uuid)', [$a->kontext()->subjekt, $id]);
        }
        return self::antwort($a, $id, 201, ['Location' => "/m/{$a->kontext()->mandant}/b/{$a->kontext()->buch}/api/v1/buchungen/$id"]);
    }

    public function lesen(Anfrage $a): Response
    {
        return self::antwort($a, $a->param('id'));
    }

    public function ersetzen(Anfrage $a): Response
    {
        $id = $a->param('id');
        $daten = self::inhaltPruefen($a->json());
        BelegeController::vorlaeufigAblehnen($a, $daten['belege'] ?? []);
        self::holen($a->db(), $id);
        $a->db()->fetchOne('SELECT kern.entwurf_aendern(?, ?::uuid, ?::jsonb, ?)',
            [$a->kontext()->subjekt, $id, self::json($daten), self::ifMatch($a)]);
        return self::antwort($a, $id);
    }

    public function loeschen(Anfrage $a): Response
    {
        $id = $a->param('id');
        self::holen($a->db(), $id);
        $a->db()->executeStatement('SELECT kern.entwurf_loeschen(?, ?::uuid, ?)', [$a->kontext()->subjekt, $id, self::ifMatch($a)]);
        return Antwort::leer();
    }

    public function vorbereiten(Anfrage $a): Response
    {
        $id = $a->param('id');
        $b = self::holen($a->db(), $id);
        $etag = self::ifMatch($a);
        if ($etag !== null && $etag !== $b['etag']) {
            throw new Problem(412, 'KM005', 'Der Entwurf wurde zwischenzeitlich geändert (ETag)');
        }
        $a->db()->fetchOne('SELECT kern.vorbereiten(?, ?::uuid)', [$a->kontext()->subjekt, $id]);
        return self::antwort($a, $id);
    }

    public function zuruecksetzen(Anfrage $a): Response
    {
        $id = $a->param('id');
        self::holen($a->db(), $id);
        $a->db()->executeStatement('SELECT kern.zuruecksetzen(?, ?::uuid)', [$a->kontext()->subjekt, $id]);
        return self::antwort($a, $id);
    }

    public function storno(Anfrage $a): Response
    {
        $id = $a->param('id');
        self::holen($a->db(), $id);
        $e = new Eingabe($a->json(false));
        $e->nurFelder(['buchungsdatum', 'text']);
        $datum = $e->datum('buchungsdatum', false);
        $text = $e->text('text', false, 500);
        $e->pruefen();
        $sid = (string) $a->db()->fetchOne('SELECT kern.storno_anlegen(?, ?::uuid, ?::date, ?)', [$a->kontext()->subjekt, $id, $datum, $text]);
        return self::antwort($a, $sid, 201, ['Location' => "/m/{$a->kontext()->mandant}/b/{$a->kontext()->buch}/api/v1/buchungen/$sid"]);
    }

    public function belegNachreichen(Anfrage $a): Response
    {
        $id = $a->param('id');
        $b = self::holen($a->db(), $id);
        if ($b['status'] !== 'festgeschrieben') {
            throw new Problem(409, 'KM003', 'Nachgereicht wird nur zu festgeschriebenen Buchungen; Entwürfe werden geändert (F27)');
        }
        $j = $a->json();
        $fehler = self::belegFehler($j, '');
        if ($fehler !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $fehler);
        }
        BelegeController::vorlaeufigAblehnen($a, [$j]);
        $a->db()->executeStatement('SELECT kern.beleg_nachreichen(?, ?::uuid, ?, ?, ?)',
            [$a->kontext()->subjekt, $id, $j['link'], $j['md5'] ?? null, $j['sha256'] ?? null]);
        return Antwort::json(['link' => $j['link'], 'md5' => $j['md5'] ?? null, 'sha256' => $j['sha256'] ?? null], 201);
    }

    // --- Freigabe (F38, F66–F68, F78) ----------------------------------------------
    public function challenge(Anfrage $a): Response
    {
        $j = $a->json();
        $art = $j['art'] ?? null;
        if (in_array($art, ['gj_sperren', 'gj_oeffnen'], true)) {
            throw new Problem(501, 'nicht_umgesetzt', 'Sperren und Öffnen von Geschäftsjahren über REST folgen in einer späteren Version');
        }
        if ($art !== 'festschreibung' || array_diff(array_keys($j), ['art', 'buchungen']) !== []) {
            throw Problem::ungueltig('Erwartet: {"art": "festschreibung", "buchungen": […]}');
        }
        $db = $a->db();
        $ids = self::idListe($j['buchungen'] ?? null);
        [$handlung, $inhaltHash, $inhalt] = $this->gegenstand($a, $ids);
        $c = $db->fetchAssociative(
            "SELECT o_id AS id, o_wert AS wert, kern.zeitpunkt(o_ablauf) AS ablauf
               FROM kern.challenge_ausstellen(?, ?::kern.handlung, ?, ?, decode(?, 'hex'))",
            [$a->kontext()->subjekt, $handlung, $inhaltHash, $a->kontext()->mandant, bin2hex(random_bytes(32))]);
        $erlaubt = [];
        foreach ($db->fetchFirstColumn('SELECT credential_id FROM kern.credential WHERE subjekt = ? AND widerrufen_am IS NULL ORDER BY registriert_am',
            [$a->kontext()->subjekt]) as $cid) {
            $erlaubt[] = ['type' => 'public-key', 'id' => Base64Url::kodieren(AnmeldungController::bytea($cid))];
        }
        return Antwort::json([
            'challenge_id' => $c['id'],
            'art' => 'festschreibung',
            'inhalt' => $inhalt,
            'inhalt_hash' => $inhaltHash,
            'ablauf' => $c['ablauf'],
            'optionen' => [
                // F68: die WebAuthn-Challenge ist der SHA-256 über die gespeicherten Challenge-Daten
                'challenge' => Base64Url::kodieren((string) hex2bin($c['wert'])),
                'rpId' => $a->rpId(),
                'timeout' => 300000,
                'userVerification' => 'required',
                'allowCredentials' => $erlaubt,
            ],
        ], 201);
    }

    public function freigeben(Anfrage $a): Response
    {
        $j = $a->json();
        if (array_diff(array_keys($j), ['buchungen', 'challenge_id', 'assertion']) !== []) {
            throw Problem::ungueltig('Erlaubt: buchungen, challenge_id, assertion');
        }
        $db = $a->db();
        $ids = self::idListe($j['buchungen'] ?? null);
        $this->gegenstand($a, $ids);
        $freigabe = null;
        if (($j['assertion'] ?? null) !== null || ($j['challenge_id'] ?? null) !== null) {
            $freigabe = $this->assertionPruefen($a, $j);
        }
        $zeilen = $db->fetchAllAssociative(
            'SELECT o_buchung AS id, o_journal_nr AS journal_nr, o_nr AS nr FROM kern.festschreiben(?, ?::uuid[], ?::jsonb)',
            [$a->kontext()->subjekt, '{' . implode(',', $ids) . '}', $freigabe === null ? null : self::json($freigabe)]);
        return Antwort::json([
            'freigabe' => $db->fetchOne('SELECT freigabe FROM kern.buchung WHERE id = ?', [$ids[0]]) ?: null,
            'buchungen' => array_map(fn ($z) => ['id' => $z['id'], 'journal_nr' => (int) $z['journal_nr'], 'nr' => $z['nr']], $zeilen),
        ]);
    }

    /**
     * Prüft Rechte und Zustand der Stapelliste und liefert Handlung, Inhalts-Hash und anzuzeigenden Inhalt.
     * Stornos brauchen kern.buchung.stornieren, alle anderen kern.buchung.festschreiben (F78 O2).
     *
     * @param list<string> $ids
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function gegenstand(Anfrage $a, array $ids): array
    {
        // F38, F78 O3: Bots und Dienstkonten geben nie frei – unabhängig von ihren Rollen
        if ($a->identitaet?->art !== 'sitzung' || $a->identitaet->nutzerId() === null) {
            throw new Problem(403, 'freigabe.nur_persoenlich', 'Freigegeben wird immer persönlich durch einen angemeldeten Menschen (F38)');
        }
        $db = $a->db();
        $nach = [];
        foreach ($db->fetchAllAssociative(
            'SELECT b.id, b.status::text AS status, b.storno_von, b.freigabe_hash, kern.buchung_inhalt(b.id)::text AS inhalt
               FROM kern.buchung b WHERE b.id::text IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $z) {
            $nach[$z['id']] = $z;
        }
        $stornos = 0;
        $liste = [];
        foreach ($ids as $i => $id) {
            $z = $nach[$id] ?? throw Problem::nichtGefunden('Mindestens eine Buchung ist unbekannt');
            if ($z['status'] !== 'freigabebereit') {
                throw new Problem(409, 'KM003', 'Mindestens eine Buchung ist nicht freigabebereit – erst vorbereiten (F66)');
            }
            $stornos += $z['storno_von'] === null ? 0 : 1;
            $liste[] = ['pos' => $i + 1, 'buchung' => $id, 'freigabe_hash' => $z['freigabe_hash'], 'inhalt' => json_decode($z['inhalt'], true)];
        }
        if ($stornos !== 0 && $stornos !== count($ids)) {
            throw new Problem(409, 'KM003', 'Stornos und andere Buchungen werden getrennt freigegeben');
        }
        $recht = $stornos > 0 ? 'kern.buchung.stornieren' : 'kern.buchung.festschreiben';
        if (!$a->kontext()->darf($recht)) {
            throw new Problem(403, 'recht.fehlt', "Recht fehlt: $recht");
        }
        $hash = count($ids) === 1 ? (string) $liste[0]['freigabe_hash']
            : (string) $db->fetchOne('SELECT kern.stapel_hash(?::uuid[])', ['{' . implode(',', $ids) . '}']);
        return [$stornos > 0 ? 'storno' : 'festschreibung', $hash, ['buchungen' => $liste]];
    }

    /**
     * Kryptografische Prüfung der Assertion (F68, F72). Bindung an Subjekt, Handlung und Inhalt
     * prüft danach die Datenbank (kern._freigabe_einloesen).
     *
     * @param array<string, mixed> $j
     * @return array<string, mixed> Freigabe für kern.festschreiben
     */
    private function assertionPruefen(Anfrage $a, array $j): array
    {
        $cid = $j['challenge_id'] ?? null;
        $as = $j['assertion'] ?? null;
        if (!is_string($cid) || !preg_match(self::UUID, $cid) || !is_array($as) || !is_string($as['id'] ?? null) || !is_array($as['response'] ?? null)) {
            throw Problem::ungueltig('challenge_id und assertion gehören zusammen');
        }
        $db = $a->db();
        try {
            $ch = $db->fetchAssociative('SELECT wert FROM kern.challenge WHERE id = ? AND subjekt = ?', [$cid, $a->kontext()->subjekt]);
            if ($ch === false) {
                throw new WebauthnFehler('Challenge unbekannt');
            }
            $cr = $db->fetchAssociative(
                "SELECT id, public_key, sign_count FROM kern.credential
                  WHERE credential_id = decode(?, 'hex') AND subjekt = ? AND widerrufen_am IS NULL",
                [bin2hex(Base64Url::dekodieren($as['id'])), $a->kontext()->subjekt]);
            if ($cr === false) {
                throw new WebauthnFehler('Passkey unbekannt, widerrufen oder nicht für diesen Mandanten registriert');
            }
            $r = $as['response'];
            $cd = Base64Url::dekodieren((string) ($r['clientDataJSON'] ?? ''));
            $auth = Base64Url::dekodieren((string) ($r['authenticatorData'] ?? ''));
            $sig = Base64Url::dekodieren((string) ($r['signature'] ?? ''));
            $zaehler = $a->pruefer()
                ->assertion($cd, $auth, $sig, (string) hex2bin($ch['wert']), AnmeldungController::bytea($cr['public_key']), (int) $cr['sign_count']);
        } catch (WebauthnFehler $e) {
            error_log(sprintf('kumero[%s] freigabe abgewiesen: %s', $a->anfrageId, $e->getMessage()));
            throw new Problem(422, 'webauthn.ungueltig', 'Freigabe mit diesem Passkey nicht möglich');
        }
        return [
            'challenge' => $cid,
            'credential' => $cr['id'],
            'sign_count' => $zaehler,
            'authenticator_data' => bin2hex($auth),
            'client_data_json' => bin2hex($cd),
            'signatur' => bin2hex($sig),
            'pruefergebnis' => ['ok' => true, 'origin' => $a->adresse(), 'rp_id' => $a->rpId(), 'up' => true, 'uv' => true,
                                'pruefer' => 'kumero ' . trim((string) @file_get_contents(dirname(__DIR__, 2) . '/VERSION'))],
        ];
    }

    // --- Abfragen (B4) ----------------------------------------------------------------
    public function salden(Anfrage $a): Response
    {
        $q = $a->http->query;
        if (!$q->has('stichtag')) {
            throw Problem::ungueltig('stichtag fehlt');
        }
        $p = [self::datum((string) $q->get('stichtag'), 'stichtag')];
        $gj = '';
        if ($q->has('geschaeftsjahr')) {
            $id = $a->db()->fetchOne('SELECT id FROM kern.geschaeftsjahr WHERE kennung = ?', [(string) $q->get('geschaeftsjahr')])
                ?: throw Problem::nichtGefunden('Geschäftsjahr unbekannt');
            $gj = ' AND b.geschaeftsjahr = ?::uuid';
            $p[] = $id;
        }
        $wo = '';
        if ($q->has('konto')) {
            $nrn = array_values(array_filter(explode(',', (string) $q->get('konto')), fn ($s) => $s !== ''));
            if ($nrn === [] || count($nrn) > 200) {
                throw Problem::ungueltig('konto: 1 bis 200 Kontonummern, mit Komma getrennt');
            }
            $wo = ' WHERE k.nr IN (' . implode(',', array_fill(0, count($nrn), '?')) . ')';
            array_push($p, ...$nrn);
        }
        $zeilen = $a->db()->fetchAllAssociative(
            "SELECT k.nr, k.bezeichnung, k.kontoart::text AS kontoart, s.soll::text AS soll, s.haben::text AS haben,
                    abs(s.soll - s.haben)::text AS betrag, CASE WHEN s.soll >= s.haben THEN 'S' ELSE 'H' END AS seite
               FROM kern.konto k
               CROSS JOIN LATERAL (
                    SELECT coalesce(sum(z.betrag) FILTER (WHERE z.seite = 'S'), 0)::numeric(15,2) AS soll,
                           coalesce(sum(z.betrag) FILTER (WHERE z.seite = 'H'), 0)::numeric(15,2) AS haben
                      FROM kern.buchungszeile z JOIN kern.buchung b ON b.id = z.buchung
                     WHERE z.konto = k.id AND b.status = 'festgeschrieben' AND b.buchungsdatum <= ?::date$gj) s
              $wo ORDER BY k.nr", $p);
        return Antwort::json(array_map(fn ($z) => [
            'konto' => $z['nr'], 'bezeichnung' => $z['bezeichnung'], 'kontoart' => $z['kontoart'],
            'soll' => $z['soll'], 'haben' => $z['haben'], 'saldo' => ['betrag' => $z['betrag'], 'seite' => $z['seite']],
        ], $zeilen));
    }

    /**
     * Summen je Konto und Zeitraum (AUSWERTUNGEN.md Regel 3 und 4, F128): Grundlage der Summen- und Saldenliste und
     * jeder eigenen Auswertung. Der Vortrag wird gerechnet, nicht gebucht – Bestandskonten beginnen mit ihrem Saldo vom
     * Tag vor dem Zeitraum, Erfolgskonten in jedem Geschäftsjahr bei null. Maßstab sind festgeschriebene Buchungen;
     * mit entwuerfe=ja zählen Entwürfe mit Buchungsdatum mit, und die Antwort sagt, wie viele es sind.
     */
    public function summen(Anfrage $a): Response
    {
        $q = $a->http->query;
        foreach (['von', 'bis'] as $feld) {
            if (!$q->has($feld)) {
                throw Problem::ungueltig("$feld fehlt");
            }
        }
        $von = self::datum((string) $q->get('von'), 'von');
        $bis = self::datum((string) $q->get('bis'), 'bis');
        $entwuerfe = match ((string) $q->get('entwuerfe', 'nein')) {
            'ja' => true, 'nein' => false, default => throw Problem::ungueltig('entwuerfe: ja oder nein'),
        };
        if ($von > $bis) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'bis', 'meldung' => 'Das Ende liegt vor dem Anfang']]);
        }
        $db = $a->db();
        $gj = $db->fetchAssociative('SELECT kennung, lower(zeitraum)::text AS beginn, (upper(zeitraum) - 1)::text AS ende, status::text AS status
                                       FROM kern.geschaeftsjahr WHERE zeitraum @> ?::date AND zeitraum @> ?::date', [$von, $bis]);
        if ($gj === false) {
            throw new Problem(400, 'zeitraum.geschaeftsjahr', 'Der Zeitraum muss in einem Geschäftsjahr liegen',
                fehler: [['feld' => 'von', 'meldung' => 'Anfang und Ende müssen im selben angelegten Geschäftsjahr liegen']]);
        }
        $status = $entwuerfe ? "b.buchungsdatum IS NOT NULL" : "b.status = 'festgeschrieben'";
        $zeilen = $db->fetchAllAssociative(
            "SELECT k.nr, k.bezeichnung, k.kontoart::text AS kontoart, s.vortrag::text AS vortrag, s.soll::text AS soll, s.haben::text AS haben,
                    s.soll_jahr::text AS soll_jahr, s.haben_jahr::text AS haben_jahr, s.bewegt,
                    (s.vortrag + s.soll - s.haben)::numeric(15,2)::text AS saldo
               FROM kern.konto k
               CROSS JOIN LATERAL (
                    SELECT coalesce(sum(CASE WHEN z.seite = 'S' THEN z.betrag ELSE -z.betrag END) FILTER (WHERE b.buchungsdatum < :von
                               AND (k.kontoart IN ('aktiv', 'passiv') OR b.buchungsdatum >= :beginn)), 0)::numeric(15,2) AS vortrag,
                           coalesce(sum(z.betrag) FILTER (WHERE z.seite = 'S' AND b.buchungsdatum >= :von), 0)::numeric(15,2) AS soll,
                           coalesce(sum(z.betrag) FILTER (WHERE z.seite = 'H' AND b.buchungsdatum >= :von), 0)::numeric(15,2) AS haben,
                           coalesce(sum(z.betrag) FILTER (WHERE z.seite = 'S' AND b.buchungsdatum >= :beginn), 0)::numeric(15,2) AS soll_jahr,
                           coalesce(sum(z.betrag) FILTER (WHERE z.seite = 'H' AND b.buchungsdatum >= :beginn), 0)::numeric(15,2) AS haben_jahr,
                           count(*) > 0 AS bewegt
                      FROM kern.buchungszeile z JOIN kern.buchung b ON b.id = z.buchung
                     WHERE z.konto = k.id AND $status AND b.buchungsdatum <= :bis) s
              ORDER BY k.nr", ['von' => $von, 'bis' => $bis, 'beginn' => $gj['beginn']]);
        $betrag = fn (string $w) => ['betrag' => ltrim($w, '-'), 'seite' => str_starts_with($w, '-') ? 'H' : 'S'];
        $konten = [];
        foreach ($zeilen as $z) {
            $konten[] = ['konto' => $z['nr'], 'bezeichnung' => $z['bezeichnung'], 'kontoart' => $z['kontoart'], 'bewegt' => (bool) $z['bewegt'],
                'vortrag' => $betrag($z['vortrag']), 'soll' => $z['soll'], 'haben' => $z['haben'],
                'soll_jahr' => $z['soll_jahr'], 'haben_jahr' => $z['haben_jahr'], 'saldo' => $betrag($z['saldo'])];
        }
        // Stand der Auswertung: damit zwei Ausgaben vergleichbar sind (AUSWERTUNGEN.md Regel 5)
        $stand = $db->fetchAssociative("SELECT max(journal_nr) AS journal_nr, (SELECT nr FROM kern.buchung WHERE status = 'festgeschrieben' ORDER BY journal_nr DESC LIMIT 1) AS nr
                                          FROM kern.buchung WHERE status = 'festgeschrieben'");
        $offen = (int) $db->fetchOne("SELECT count(*) FROM kern.buchung WHERE status <> 'festgeschrieben' AND buchungsdatum BETWEEN ?::date AND ?::date", [$gj['beginn'], $bis]);
        return Antwort::json(['von' => $von, 'bis' => $bis,
            'geschaeftsjahr' => ['kennung' => $gj['kennung'], 'beginn' => $gj['beginn'], 'ende' => $gj['ende'], 'status' => $gj['status']],
            'entwuerfe' => $entwuerfe, 'entwuerfe_im_jahr' => $offen,
            'stand' => ['journal_nr' => $stand['journal_nr'] === null ? null : (int) $stand['journal_nr'], 'nr' => $stand['nr'], 'erstellt_am' => gmdate('Y-m-d\TH:i:s\Z')],
            'konten' => $konten]);
    }

    public function kontoblatt(Anfrage $a): Response
    {
        $db = $a->db();
        $q = $a->http->query;
        $konto = $db->fetchAssociative(
            "SELECT k.id, k.nr, k.bezeichnung, k.kontoart::text AS kontoart, k.aktiv,
                    EXISTS (SELECT 1 FROM kern.buchungszeile z JOIN kern.buchung b ON b.id = z.buchung
                             WHERE z.konto = k.id AND b.status = 'festgeschrieben') AS bebucht
               FROM kern.konto k WHERE k.nr = ?", [$a->param('nr')]) ?: throw Problem::nichtGefunden('Konto unbekannt');
        if (!$q->has('von') || !$q->has('bis')) {
            throw Problem::ungueltig('von und bis fehlen');
        }
        $von = self::datum((string) $q->get('von'), 'von');
        $bis = self::datum((string) $q->get('bis'), 'bis');
        if ($von > $bis) {
            throw Problem::ungueltig('von liegt nach bis');
        }
        $anzahl = self::anzahl($a);
        $saldo = function (string $bedingung, string $datum) use ($db, $konto): array {
            $z = $db->fetchAssociative(
                "SELECT abs(s - h)::text AS betrag, CASE WHEN s >= h THEN 'S' ELSE 'H' END AS seite FROM (
                    SELECT coalesce(sum(z.betrag) FILTER (WHERE z.seite = 'S'), 0)::numeric(15,2) AS s,
                           coalesce(sum(z.betrag) FILTER (WHERE z.seite = 'H'), 0)::numeric(15,2) AS h
                      FROM kern.buchungszeile z JOIN kern.buchung b ON b.id = z.buchung
                     WHERE z.konto = ?::uuid AND b.status = 'festgeschrieben' AND b.buchungsdatum $bedingung ?::date) x",
                [$konto['id'], $datum]);
            return ['betrag' => $z['betrag'], 'seite' => $z['seite']];
        };
        $p = [$konto['id'], $von, $bis];
        $nach = '';
        if ($q->has('cursor')) {
            $nach = ' AND (b.buchungsdatum, b.journal_nr, z.pos) > (?::date, ?::bigint, ?::int)';
            array_push($p, ...self::cursorLesen((string) $q->get('cursor'), 3));
        }
        $zeilen = $db->fetchAllAssociative(
            "SELECT b.id, b.journal_nr, b.nr, b.buchungsdatum::text AS buchungsdatum, b.belegnummer, b.text,
                    z.seite::text AS seite, z.betrag::text AS betrag, z.pos,
                    coalesce((SELECT jsonb_agg(DISTINCT k2.nr ORDER BY k2.nr) FROM kern.buchungszeile z2
                                JOIN kern.konto k2 ON k2.id = z2.konto
                               WHERE z2.buchung = b.id AND z2.seite <> z.seite), '[]')::text AS gegenkonten
               FROM kern.buchungszeile z JOIN kern.buchung b ON b.id = z.buchung
              WHERE z.konto = ?::uuid AND b.status = 'festgeschrieben' AND b.buchungsdatum BETWEEN ?::date AND ?::date$nach
              ORDER BY b.buchungsdatum, b.journal_nr, z.pos LIMIT " . ($anzahl + 1), $p);
        $weiter = null;
        if (count($zeilen) > $anzahl) {
            array_pop($zeilen);
            $l = $zeilen[$anzahl - 1];
            $weiter = self::cursor([$l['buchungsdatum'], (int) $l['journal_nr'], (int) $l['pos']]);
        }
        return Antwort::json([
            'konto' => ['nr' => $konto['nr'], 'bezeichnung' => $konto['bezeichnung'], 'kontoart' => $konto['kontoart'],
                        'aktiv' => (bool) $konto['aktiv'], 'bebucht' => (bool) $konto['bebucht']],
            'von' => $von, 'bis' => $bis,
            'anfangssaldo' => $saldo('<', $von),
            'endsaldo' => $saldo('<=', $bis),
            'bewegungen' => array_map(fn ($z) => [
                'buchung' => $z['id'], 'journal_nr' => (int) $z['journal_nr'], 'nr' => $z['nr'], 'buchungsdatum' => $z['buchungsdatum'],
                'belegnummer' => $z['belegnummer'], 'text' => $z['text'], 'seite' => $z['seite'], 'betrag' => $z['betrag'],
                'gegenkonten' => json_decode($z['gegenkonten'], true),
            ], $zeilen),
            'weiter' => $weiter,
        ]);
    }

    // --- Darstellung --------------------------------------------------------------------
    private static function sql(): string
    {
        return "SELECT b.id, b.status::text AS status, b.journal_nr, b.nr, b.freigabe_hash, b.hash, b.testbetrieb,
                       b.festgeschrieben_von, kern.zeitpunkt(b.festgeschrieben_am) AS festgeschrieben_am,
                       b.erfasst_am::text AS sort_erfasst,
                       kern.buchung_inhalt(b.id)::text AS inhalt,
                       kern.buchung_belege(b.id)::text AS belege,
                       (SELECT s.id FROM kern.buchung s WHERE s.storno_von = b.id AND s.status = 'festgeschrieben') AS storniert_durch,
                       (SELECT coalesce(sum(z.betrag), 0)::numeric(15,2)::text FROM kern.buchungszeile z
                         WHERE z.buchung = b.id AND z.seite = 'S') AS summe,
                       CASE WHEN b.status <> 'festgeschrieben' THEN kern.entwurf_etag(b.id) END AS etag
                  FROM kern.buchung b";
    }

    /** @return array<string, mixed> */
    private static function holen(Connection $db, string $id): array
    {
        return $db->fetchAssociative(self::sql() . ' WHERE b.id = ?::uuid', [$id]) ?: throw Problem::nichtGefunden('Buchung unbekannt');
    }

    private static function antwort(Anfrage $a, string $id, int $status = 200, array $kopf = []): Response
    {
        $z = self::holen($a->db(), $id);
        if ($z['etag'] !== null) {
            $kopf['ETag'] = '"' . $z['etag'] . '"';
        }
        return Antwort::json(self::buchung($z), $status, $kopf);
    }

    /** @param array<string, mixed> $z @return array<string, mixed> */
    private static function buchung(array $z): array
    {
        $i = json_decode((string) $z['inhalt'], true);
        $zeilen = [];
        foreach ($i['zeilen'] as $x) {
            $fw = $x['fw'] ?? null;
            $zeilen[] = [
                'konto' => $x['konto'], 'seite' => $x['seite'], 'betrag' => $x['betrag'],
                'fw_betrag' => $fw['betrag'] ?? null, 'fw_waehrung' => $fw['waehrung'] ?? null, 'kurs' => $fw['kurs'] ?? null,
                'kurs_datum' => $fw['kurs_datum'] ?? null, 'kurs_art' => $fw['kurs_art'] ?? null,
                'tags' => $x['tags'], 'erweiterung' => (object) $x['erweiterung'],
            ];
        }
        return [
            'id' => $z['id'],
            'status' => $z['status'],
            'buchungsart' => $i['buchungsart'], 'nummernkreis' => $i['nummernkreis'],
            'belegdatum' => $i['belegdatum'], 'buchungsdatum' => $i['buchungsdatum'],
            'belegnummer' => $i['belegnummer'], 'text' => $i['text'],
            'erweiterung' => (object) $i['erweiterung'], 'belege' => $i['belege'],
            'belege_nachgereicht' => array_values(array_filter(json_decode((string) ($z['belege'] ?? '[]'), true), fn ($x) => $x['art'] === 'nachgereicht')),
            'zeilen' => $zeilen,
            'herkunft' => $i['herkunft'], 'storno_von' => $i['storno_von'], 'storniert_durch' => $z['storniert_durch'],
            'erfasst_am' => $i['erfasst_am'], 'erfasst_von' => $i['erfasst_von'],
            'freigabe_hash' => $z['freigabe_hash'],
            'journal_nr' => $z['journal_nr'] === null ? null : (int) $z['journal_nr'], 'nr' => $z['nr'],
            'festgeschrieben_am' => $z['festgeschrieben_am'], 'festgeschrieben_von' => $z['festgeschrieben_von'],
            'testbetrieb' => (bool) $z['testbetrieb'], 'hash' => $z['hash'], 'summe' => $z['summe'],
        ];
    }

    // --- Eingabe ----------------------------------------------------------------------------
    /**
     * Prüft die Form von BuchungInhalt. Entwürfe dürfen unvollständig sein (F62); die Regeln
     * selbst gelten erst beim Vorbereiten und stehen in der Datenbank.
     *
     * @param array<string, mixed> $j
     * @return array<string, mixed>
     */
    private static function inhaltPruefen(array $j): array
    {
        $f = [];
        foreach (array_keys($j) as $k) {
            if (!in_array($k, self::KOPF, true)) {
                $f[] = ['feld' => (string) $k, 'meldung' => 'Unbekanntes Feld'];
            }
        }
        $text = function (array $q, string $feld, string $pfad, int $max) use (&$f): void {
            $w = $q[$feld] ?? null;
            if ($w !== null && (!is_string($w) || mb_strlen($w) > $max)) {
                $f[] = ['feld' => $pfad . $feld, 'meldung' => "Text mit höchstens $max Zeichen erwartet"];
            }
        };
        $datum = function (array $q, string $feld, string $pfad) use (&$f): void {
            $w = $q[$feld] ?? null;
            if ($w !== null && !self::istDatum($w)) {
                $f[] = ['feld' => $pfad . $feld, 'meldung' => 'Datum YYYY-MM-DD erwartet'];
            }
        };
        $muster = function (array $q, string $feld, string $pfad, string $regex, string $meldung) use (&$f): void {
            $w = $q[$feld] ?? null;
            if ($w !== null && (!is_string($w) || !preg_match($regex, $w))) {
                $f[] = ['feld' => $pfad . $feld, 'meldung' => $meldung];
            }
        };
        $erweiterung = function (array $q, string $pfad) use (&$f): void {
            $w = $q['erweiterung'] ?? null;
            if ($w === null) {
                return;
            }
            if (!is_array($w) || ($w !== [] && array_is_list($w))) {
                $f[] = ['feld' => $pfad . 'erweiterung', 'meldung' => 'Objekt erwartet'];
                return;
            }
            foreach ($w as $k => $v) {
                if (!is_scalar($v)) {
                    $f[] = ['feld' => $pfad . "erweiterung.$k", 'meldung' => 'Einfacher Wert erwartet'];
                }
            }
        };

        $text($j, 'buchungsart', '', 16);
        $text($j, 'nummernkreis', '', 16);
        $datum($j, 'belegdatum', '');
        $datum($j, 'buchungsdatum', '');
        $text($j, 'belegnummer', '', 36);
        $text($j, 'text', '', 500);
        $erweiterung($j, '');

        $belege = $j['belege'] ?? [];
        if (!is_array($belege) || !array_is_list($belege)) {
            $f[] = ['feld' => 'belege', 'meldung' => 'Liste erwartet'];
        } else {
            foreach ($belege as $n => $b) {
                array_push($f, ...self::belegFehler($b, "belege[$n]."));
            }
        }

        $zeilen = $j['zeilen'] ?? [];
        if (!is_array($zeilen) || !array_is_list($zeilen) || count($zeilen) > 999) {
            $f[] = ['feld' => 'zeilen', 'meldung' => 'Liste mit höchstens 999 Zeilen erwartet'];
            $zeilen = [];
        }
        foreach ($zeilen as $n => $z) {
            $pf = "zeilen[$n].";
            if (!is_array($z) || ($z !== [] && array_is_list($z))) {
                $f[] = ['feld' => "zeilen[$n]", 'meldung' => 'Objekt erwartet'];
                continue;
            }
            foreach (array_keys($z) as $k) {
                if (!in_array($k, self::ZEILE, true)) {
                    $f[] = ['feld' => $pf . $k, 'meldung' => 'Unbekanntes Feld'];
                }
            }
            $text($z, 'konto', $pf, 20);
            $muster($z, 'seite', $pf, '/^[SH]$/', 'S oder H erwartet');
            $muster($z, 'betrag', $pf, '/^[0-9]{1,13}\.[0-9]{2}$/', 'Dezimal-String mit zwei Nachkommastellen erwartet, z. B. "119.00" (F19, F24)');
            $muster($z, 'fw_betrag', $pf, '/^[0-9]{1,15}(\.[0-9]{1,2})?$/', 'Dezimal-String erwartet');
            $muster($z, 'fw_waehrung', $pf, '/^[A-Z]{3}$/', 'Währung nach ISO 4217 erwartet');
            $muster($z, 'kurs', $pf, '/^[0-9]{1,9}\.[0-9]{6,10}$/', 'Kurs mit 6 bis 10 Nachkommastellen erwartet (F12)');
            $datum($z, 'kurs_datum', $pf);
            $text($z, 'kurs_art', $pf, 32);
            $erweiterung($z, $pf);
            $tags = $z['tags'] ?? [];
            if (!is_array($tags) || !array_is_list($tags) || array_filter($tags, fn ($t) => !is_string($t) || $t === '') !== []) {
                $f[] = ['feld' => $pf . 'tags', 'meldung' => 'Liste von Tag-Namen erwartet'];
            }
        }
        if ($f !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        // Leere Objekte kommen aus json_decode als leere Liste – weglassen, sonst würden sie als [] gespeichert
        if (($j['erweiterung'] ?? null) === []) {
            unset($j['erweiterung']);
        }
        foreach ($zeilen as $n => $z) {
            if (($z['erweiterung'] ?? null) === []) {
                unset($j['zeilen'][$n]['erweiterung']);
            }
        }
        return $j;
    }

    /** @return list<array{feld: string, meldung: string}> */
    private static function belegFehler(mixed $b, string $pfad): array
    {
        if (!is_array($b)) {
            return [['feld' => rtrim($pfad, '.') ?: 'beleg', 'meldung' => 'Objekt erwartet']];
        }
        $f = [];
        foreach (array_keys($b) as $k) {
            if (!in_array($k, ['link', 'md5', 'sha256'], true)) {
                $f[] = ['feld' => $pfad . $k, 'meldung' => $k === 'paperless_id'
                    ? 'Der Belegverweis ist der ganze Link (link), nicht die Dokument-ID (F88)' : 'Unbekanntes Feld'];
            }
        }
        if (!is_string($b['link'] ?? null) || strlen($b['link']) > 500 || !preg_match(self::BELEG_LINK, $b['link'])) {
            $f[] = ['feld' => $pfad . 'link', 'meldung' => 'Link erwartet: http oder https, Host klein geschrieben, ohne Zugangsdaten, Abfrage und Fragment, höchstens 500 Zeichen (F88)'];
        }
        // Prüfsummen dürfen fehlen (ungeprüfter Verweis, nur im Testbetrieb festschreibbar) – dann aber beide
        $md5 = $b['md5'] ?? null;
        $sha = $b['sha256'] ?? null;
        if ($md5 !== null && (!is_string($md5) || !preg_match('/^[0-9a-f]{32}$/', $md5))) {
            $f[] = ['feld' => $pfad . 'md5', 'meldung' => 'MD5 als 32 Hex-Zeichen erwartet (F10)'];
        }
        if ($sha !== null && (!is_string($sha) || !preg_match('/^[0-9a-f]{64}$/', $sha))) {
            $f[] = ['feld' => $pfad . 'sha256', 'meldung' => 'SHA-256 als 64 Hex-Zeichen erwartet (F10)'];
        }
        if (($md5 === null) !== ($sha === null)) {
            $f[] = ['feld' => $pfad . 'md5', 'meldung' => 'MD5 und SHA-256 gehören zusammen: beide oder keine (F88)'];
        }
        return $f;
    }

    /** @return list<string> */
    private static function idListe(mixed $w): array
    {
        if (!is_array($w) || !array_is_list($w) || $w === [] || count($w) > 500) {
            throw Problem::ungueltig('buchungen: Liste mit 1 bis 500 IDs erwartet');
        }
        foreach ($w as $id) {
            if (!is_string($id) || !preg_match(self::UUID, $id)) {
                throw Problem::ungueltig('buchungen: UUIDs erwartet');
            }
        }
        if (count(array_unique($w)) !== count($w)) {
            throw Problem::ungueltig('buchungen: eine Buchung ist doppelt angegeben');
        }
        return $w;
    }

    /** If-Match ist Pflicht (F19); „*“ gilt für jeden Stand. Liefert den erwarteten ETag oder null. */
    private static function ifMatch(Anfrage $a): ?string
    {
        $w = trim((string) $a->http->headers->get('If-Match', ''));
        if ($w === '') {
            throw new Problem(428, 'etag.fehlt', 'Header If-Match fehlt (ETag des Entwurfs oder *)');
        }
        if ($w === '*') {
            return null;
        }
        return trim((string) preg_replace('#^W/#', '', $w), '"');
    }

    private static function istDatum(mixed $w): bool
    {
        if (!is_string($w)) {
            return false;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $w);
        return $d !== false && $d->format('Y-m-d') === $w;
    }

    private static function datum(string $w, string $feld): string
    {
        return self::istDatum($w) ? $w : throw Problem::ungueltig("$feld: Datum YYYY-MM-DD erwartet");
    }

    private static function anzahl(Anfrage $a): int
    {
        $w = $a->http->query->get('anzahl', '100');
        if (!is_string($w) || !ctype_digit($w) || (int) $w < 1 || (int) $w > 500) {
            throw Problem::ungueltig('anzahl: 1 bis 500');
        }
        return (int) $w;
    }

    /** @param list<mixed> $teile */
    private static function cursor(array $teile): string
    {
        return Base64Url::kodieren((string) json_encode($teile));
    }

    /** @return list<mixed> */
    private static function cursorLesen(string $c, int $anzahl): array
    {
        try {
            $w = json_decode(Base64Url::dekodieren($c), true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $w = null;
        }
        if (!is_array($w) || !array_is_list($w) || count($w) !== $anzahl || array_filter($w, fn ($x) => !is_scalar($x)) !== []) {
            throw Problem::ungueltig('cursor ungültig');
        }
        return $w;
    }

    /** @param array<string, mixed> $w */
    private static function json(array $w): string
    {
        return $w === [] ? '{}' : json_encode($w, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
