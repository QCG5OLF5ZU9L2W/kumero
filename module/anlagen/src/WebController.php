<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Anlagen;

use Kumero\Api\V1\Problem;
use Kumero\Http\Antwort;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anlagegüter eines Buchs (ANLAGEN.md). Lesen per SELECT im eigenen Schema, Schreiben nur über die Funktionen des
 * Moduls (F44, F64). Gerechnet wird in Rechner; Grenzen und Nutzungsdauern kommen aus dem Regelwerk (G6).
 */
final class WebController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const ARTEN = ['linear', 'gwg', 'sammelposten', 'keine', 'fremd'];
    private const ABGANG = ['verkauf', 'verschrottung', 'entnahme', 'sonstiges'];
    /** Gemietetes und Geleastes geht nicht ab, es wird zurückgegeben (LAGER.md IN9). */
    private const ABGANG_FREMD = ['rueckgabe', 'sonstiges'];
    private const SPALTEN = "id, inventarnr, bezeichnung, zugang::text AS zugang, kosten::text AS kosten, netto::text AS netto, art, nutzungsdauer, nd_vorschlag, nd_quelle,
        nd_begruendung, restwert, bestandskonto, aufwandskonto, buchung, uebernahme::text AS uebernahme, bisherige_afa::text AS bisherige_afa,
        abgang::text AS abgang, abgang_art, standort, bemerkung, kostenstelle";

    /** @return array<string, mixed> */
    public static function regelwerk(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__) . '/regelwerk/afa-de.json'), true, 16, JSON_THROW_ON_ERROR);
    }

    /** Die Grenzen, die am Tag des Zugangs gelten. @return array<string, mixed>|null */
    public static function grenzen(string $datum): ?array
    {
        foreach (self::regelwerk()['grenzen'] as $g) {
            if ($g['gueltig_ab'] <= $datum && ($g['gueltig_bis'] === null || $datum <= $g['gueltig_bis'])) {
                return $g;
            }
        }
        return null;
    }

    /** Einstellungen, Regelwerk und alle Güter; mit beginn und ende dazu die Rechnung des Geschäftsjahres und die Vermerke. */
    public function stand(Anfrage $a): Response
    {
        $db = $a->db();
        $q = $a->http->query;
        $jahr = null;
        if ($q->has('beginn') || $q->has('ende')) {
            $jahr = ['beginn' => self::datum($q->get('beginn'), 'beginn'), 'ende' => self::datum($q->get('ende'), 'ende'), 'kennung' => mb_substr((string) $q->get('kennung', ''), 0, 20)];
            if ($jahr['beginn'] > $jahr['ende'] || $jahr['kennung'] === '') {
                throw Problem::ungueltig('Geschäftsjahr: kennung, beginn und ende erwartet');
            }
        }
        $e = $db->fetchAssociative('SELECT vorsteuerabzug, restwert, anlagekonten FROM anlagen.einstellung');
        $vermerke = [];
        if ($jahr !== null) {
            foreach ($db->fetchAllAssociative('SELECT gut, buchung, zweck, betrag::text AS betrag FROM anlagen.afa WHERE jahr = ? ORDER BY vermerkt_am', [$jahr['kennung']]) as $v) {
                $vermerke[$v['gut']][] = ['buchung' => $v['buchung'], 'zweck' => $v['zweck'], 'betrag' => $v['betrag']];
            }
        }
        $gueter = [];
        foreach ($db->fetchAllAssociative('SELECT ' . self::SPALTEN . ' FROM anlagen.gut ORDER BY zugang, bezeichnung, id') as $g) {
            $x = self::gut($g);
            if ($jahr !== null) {
                $j = Rechner::jahr($g, $jahr['beginn'], $jahr['ende']);
                $x['jahr'] = ['buchwert_anfang' => Rechner::dezimal($j['buchwert_anfang']), 'afa' => Rechner::dezimal($j['afa']), 'abgang_rest' => Rechner::dezimal($j['abgang_rest']),
                    'buchwert_ende' => Rechner::dezimal($j['buchwert_ende']), 'zugang_im_jahr' => $j['zugang_im_jahr'], 'abgang_im_jahr' => $j['abgang_im_jahr'],
                    'vermerke' => $vermerke[$g['id']] ?? []];
            }
            $gueter[] = $x;
        }
        $r = self::regelwerk();
        return Antwort::json(['einstellung' => ['vorsteuerabzug' => $e['vorsteuerabzug'] === null ? null : (bool) $e['vorsteuerabzug'], 'restwert' => $e['restwert'], 'anlagekonten' => $e['anlagekonten']],
            'regelwerk' => ['name' => $r['name'], 'stand' => $r['stand'], 'hinweis' => $r['hinweis'], 'grenzen' => $r['grenzen'], 'nutzungsdauern' => $r['nutzungsdauern']],
            'jahr' => $jahr, 'gueter' => $gueter,
            'darf_pflegen' => $a->kontext()->darf('anlagen.gut.pflegen'), 'darf_lauf' => $a->kontext()->darf('anlagen.lauf.ausfuehren')]);
    }

    /** Vorschau für die Maske: Vorschlag zur Art nach dem Nettobetrag und der Plan über alle Jahre – speichert nichts. */
    public function plan(Anfrage $a): Response
    {
        $j = $a->json();
        $jahresbeginn = self::datum($j['jahresbeginn'] ?? null, 'jahresbeginn');
        unset($j['jahresbeginn']);
        $g = self::pruefen($j, true);
        if ($g['art'] === 'fremd') {
            return Antwort::json(['vorschlag' => null, 'plan' => []], 200, ['Cache-Control' => 'no-store']);   // nichts abzusetzen
        }
        $grenzen = self::grenzen($g['zugang']);
        $netto = $g['netto'] ?? $g['kosten'];
        return Antwort::json(['vorschlag' => $grenzen === null ? null : Rechner::vorschlag((string) $netto, $grenzen),
            'plan' => Rechner::plan($g + ['abgang' => null], $jahresbeginn)], 200, ['Cache-Control' => 'no-store']);   // eine Vorschau wird nicht aufbewahrt
    }

    public function einstellungSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        $v = $j['vorsteuerabzug'] ?? null;
        $r = $j['restwert'] ?? 'null';
        $k = $j['anlagekonten'] ?? '';
        $f = [];
        if ($v !== null && !is_bool($v)) {
            $f[] = ['feld' => 'vorsteuerabzug', 'meldung' => 'true, false oder null erwartet'];
        }
        if (!in_array($r, ['null', 'erinnerung'], true)) {
            $f[] = ['feld' => 'restwert', 'meldung' => 'null oder erinnerung erwartet'];
        }
        if (!is_string($k) || !preg_match('/^[0-9A-Za-z*.,;\s\-–]{0,500}$/u', $k)) {
            $f[] = ['feld' => 'anlagekonten', 'meldung' => 'Kontonummern und Bereiche erwartet, z. B. 0100-0899'];
        }
        if ($f !== [] || array_diff(array_keys($j), ['vorsteuerabzug', 'restwert', 'anlagekonten']) !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        $a->db()->executeStatement('SELECT anlagen.einstellung_setzen(?, ?::boolean, ?, ?)', [$a->kontext()->subjekt, $v === null ? null : ($v ? 'true' : 'false'), $r, $k]);
        return Antwort::leer();
    }

    public function anlegen(Anfrage $a): Response
    {
        $id = (string) $a->db()->fetchOne('SELECT anlagen.gut_setzen(?, NULL, ?::jsonb)', [$a->kontext()->subjekt, self::json(self::pruefen($a->json()))]);
        return Antwort::json(self::holen($a, $id), 201);
    }

    public function aendern(Anfrage $a): Response
    {
        $id = $a->param('id');
        $alt = self::holen($a, $id);
        $neu = self::pruefen($a->json());
        if (($alt['art'] === 'fremd') !== ($neu['art'] === 'fremd')) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'art', 'meldung' => 'Ein Gut bleibt eigen oder fremd, wie es angelegt wurde – lege es neu an']]);
        }
        if ($alt['abgang'] !== null && $neu['zugang'] > $alt['abgang']) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'zugang', 'meldung' => 'Der Zugang liegt nach dem eingetragenen Abgang']]);
        }
        $a->db()->fetchOne('SELECT anlagen.gut_setzen(?, ?::uuid, ?::jsonb)', [$a->kontext()->subjekt, $id, self::json($neu)]);
        return Antwort::json(self::holen($a, $id));
    }

    /** Abgang eintragen ({datum, art}) oder zurücknehmen ({datum: null}). */
    public function abgang(Anfrage $a): Response
    {
        $id = $a->param('id');
        $g = self::holen($a, $id);
        $j = $a->json();
        $datum = $j['datum'] ?? null;
        $art = $j['art'] ?? null;
        if ($datum === null) {
            $art = null;
        } else {
            $datum = self::datum($datum, 'datum');
            $erlaubt = $g['art'] === 'fremd' ? self::ABGANG_FREMD : self::ABGANG;
            if (!in_array($art, $erlaubt, true)) {
                throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'art', 'meldung' => 'Erlaubt: ' . implode(', ', $erlaubt)]]);
            }
            if ($datum < $g['zugang']) {
                throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'datum', 'meldung' => 'Der Abgang liegt vor dem Zugang']]);
            }
        }
        $a->db()->executeStatement('SELECT anlagen.gut_abgang(?, ?::uuid, ?::date, ?)', [$a->kontext()->subjekt, $id, $datum, $art]);
        return Antwort::json(self::holen($a, $id));
    }

    /** Die Seite meldet, welche Buchung sie für Gut und Jahr angelegt hat. */
    public function vermerken(Anfrage $a): Response
    {
        [$gut, $jahr, $buchung] = self::vermerk($a);
        $j = $a->json();
        $zweck = $j['zweck'] ?? 'afa';
        $betrag = $j['betrag'] ?? null;
        if (!in_array($zweck, ['afa', 'abgang'], true) || !is_string($betrag) || !preg_match('/^\d{1,13}(\.\d{1,2})?$/', $betrag) || (float) $betrag <= 0) {
            throw Problem::ungueltig('zweck (afa|abgang) und betrag als Dezimal-String erwartet');
        }
        $a->db()->executeStatement('SELECT anlagen.afa_vermerken(?, ?::uuid, ?, ?::uuid, ?, ?::numeric)', [$a->kontext()->subjekt, $gut, $jahr, $buchung, $zweck, $betrag]);
        return Antwort::leer();
    }

    public function vermerkEntfernen(Anfrage $a): Response
    {
        [$gut, $jahr, $buchung] = self::vermerk($a);
        $a->db()->executeStatement('SELECT anlagen.afa_entfernen(?, ?::uuid, ?, ?::uuid)', [$a->kontext()->subjekt, $gut, $jahr, $buchung]);
        return Antwort::leer();
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function vermerk(Anfrage $a): array
    {
        $j = $a->json();
        $gut = $j['gut'] ?? null;
        $jahr = $j['jahr'] ?? null;
        $buchung = $j['buchung'] ?? null;
        if (!is_string($gut) || !preg_match(self::UUID, $gut) || !is_string($buchung) || !preg_match(self::UUID, $buchung) || !is_string($jahr) || $jahr === '' || mb_strlen($jahr) > 20) {
            throw Problem::ungueltig('gut, jahr und buchung erwartet');
        }
        if (self::holen($a, $gut)['art'] === 'fremd') {
            throw Problem::ungueltig('Gemietetes und Geleastes wird nicht abgesetzt');
        }
        return [$gut, $jahr, $buchung];
    }

    /** @return array<string, mixed> */
    private static function holen(Anfrage $a, string $id): array
    {
        $z = preg_match(self::UUID, $id) ? $a->db()->fetchAssociative('SELECT ' . self::SPALTEN . ' FROM anlagen.gut WHERE id = ?::uuid', [$id]) : false;
        return $z === false ? throw Problem::nichtGefunden('Anlagegut unbekannt') : self::gut($z);
    }

    /** @param array<string, mixed> $z @return array<string, mixed> */
    private static function gut(array $z): array
    {
        foreach (['nutzungsdauer', 'nd_vorschlag'] as $f) {
            $z[$f] = $z[$f] === null ? null : (int) $z[$f];
        }
        return $z;
    }

    private static function datum(mixed $w, string $feld): string
    {
        $t = is_string($w) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $w) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $w) : false;
        if ($t === false || $t->format('Y-m-d') !== $w) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => $feld, 'meldung' => 'Datum als JJJJ-MM-TT erwartet']]);
        }
        return $w;
    }

    /**
     * Prüft die Angaben eines Anlageguts. Für die Vorschau ($vorschau) dürfen Bezeichnung und Konten noch fehlen.
     *
     * @param array<string, mixed> $j
     * @return array<string, mixed>
     */
    private static function pruefen(array $j, bool $vorschau = false): array
    {
        $erlaubt = ['inventarnr', 'bezeichnung', 'zugang', 'kosten', 'netto', 'art', 'nutzungsdauer', 'nd_vorschlag', 'nd_quelle', 'nd_begruendung', 'restwert',
                    'bestandskonto', 'aufwandskonto', 'buchung', 'uebernahme', 'bisherige_afa', 'standort', 'bemerkung', 'kostenstelle'];
        $f = [];
        // gemietet oder geleast: da, aber nicht Eigentum – ohne Kosten, Konten und Absetzung (LAGER.md IN9)
        $fremd = ($j['art'] ?? null) === 'fremd';
        foreach (array_keys($j) as $k) {
            if (!in_array($k, $erlaubt, true)) {
                $f[] = ['feld' => (string) $k, 'meldung' => 'Unbekanntes Feld'];
            }
        }
        $g = [];
        $text = function (string $feld, int $max, bool $pflicht) use ($j, &$f, &$g): void {
            $w = $j[$feld] ?? null;
            if ($w === null || $w === '') {
                if ($pflicht) {
                    $f[] = ['feld' => $feld, 'meldung' => 'Pflichtfeld'];
                }
                $g[$feld] = null;
                return;
            }
            if (!is_string($w) || mb_strlen(trim($w)) > $max) {
                $f[] = ['feld' => $feld, 'meldung' => "Text mit höchstens $max Zeichen erwartet"];
                return;
            }
            $g[$feld] = trim($w);
        };
        $text('inventarnr', 40, false);
        $text('bezeichnung', 200, !$vorschau);
        $text('bestandskonto', 20, !$vorschau && !$fremd);
        $text('aufwandskonto', 20, false);
        $text('nd_quelle', 200, false);
        $text('nd_begruendung', 500, false);
        $text('standort', 100, false);
        $text('bemerkung', 1000, false);
        $text('kostenstelle', 100, false);   // Name eines Tags des Kerns (IN10); ob es ihn gibt, zeigt der Entwurf
        $betrag = function (string $feld, bool $pflicht, bool $nullErlaubt = false) use ($j, &$f, &$g): void {
            $w = $j[$feld] ?? null;
            if ($w === null || $w === '') {
                if ($pflicht) {
                    $f[] = ['feld' => $feld, 'meldung' => 'Pflichtfeld'];
                }
                $g[$feld] = null;
                return;
            }
            if (!is_string($w) || !preg_match('/^\d{1,13}(\.\d{1,2})?$/', $w) || (!$nullErlaubt && (float) $w <= 0)) {
                $f[] = ['feld' => $feld, 'meldung' => 'Betrag als Dezimal-String erwartet, z. B. "1299.00"'];
                return;
            }
            $g[$feld] = number_format((float) $w, 2, '.', '');
        };
        $betrag('kosten', !$fremd);
        $betrag('netto', false);
        $betrag('bisherige_afa', false, true);
        $g['bisherige_afa'] ??= '0.00';
        try {
            $g['zugang'] = self::datum($j['zugang'] ?? null, 'zugang');
            $g['uebernahme'] = ($j['uebernahme'] ?? null) === null || $j['uebernahme'] === '' ? null : self::datum($j['uebernahme'], 'uebernahme');
        } catch (Problem $p) {
            array_push($f, ...$p->fehler);
        }
        $g['art'] = $j['art'] ?? null;
        if (!in_array($g['art'], self::ARTEN, true)) {
            $f[] = ['feld' => 'art', 'meldung' => 'Erlaubt: ' . implode(', ', self::ARTEN)];
        }
        $g['restwert'] = $j['restwert'] ?? 'null';
        if (!in_array($g['restwert'], ['null', 'erinnerung'], true)) {
            $f[] = ['feld' => 'restwert', 'meldung' => 'null oder erinnerung erwartet'];
        }
        foreach (['nutzungsdauer', 'nd_vorschlag'] as $feld) {
            $w = $j[$feld] ?? null;
            if ($w !== null && (!is_int($w) || $w < 1 || $w > 100)) {
                $f[] = ['feld' => $feld, 'meldung' => 'Ganze Jahre von 1 bis 100 erwartet'];
                $w = null;
            }
            $g[$feld] = $w;
        }
        $bu = $j['buchung'] ?? null;
        if ($bu !== null && (!is_string($bu) || !preg_match(self::UUID, $bu))) {
            $f[] = ['feld' => 'buchung', 'meldung' => 'ID einer Buchung erwartet'];
        }
        $g['buchung'] = $bu;
        if ($f === [] && $fremd) {
            foreach (['kosten', 'netto', 'bestandskonto', 'aufwandskonto', 'buchung', 'uebernahme'] as $feld) {
                if ($g[$feld] !== null) {
                    $f[] = ['feld' => $feld, 'meldung' => 'Gemietetes und Geleastes hat keine Anschaffungskosten, keine Konten und keine Absetzung'];
                }
            }
            if ((float) $g['bisherige_afa'] > 0) {
                $f[] = ['feld' => 'bisherige_afa', 'meldung' => 'Gemietetes und Geleastes wird nicht abgesetzt'];
            }
            $g['restwert'] = 'null';
        }
        if ($f === []) {
            if ($g['art'] === 'linear' && $g['nutzungsdauer'] === null) {
                $f[] = ['feld' => 'nutzungsdauer', 'meldung' => 'Für die Verteilung über die Nutzungsdauer wird die Nutzungsdauer in Jahren gebraucht'];
            }
            if ($g['art'] !== 'linear') {
                // ohne Verteilung trägt die Nutzungsdauer nichts – sie wird nicht mitgeführt
                $g['nutzungsdauer'] = $g['nd_vorschlag'] = $g['nd_quelle'] = $g['nd_begruendung'] = null;
            }
            if (!$vorschau && !in_array($g['art'], ['keine', 'fremd'], true) && $g['aufwandskonto'] === null) {
                $f[] = ['feld' => 'aufwandskonto', 'meldung' => 'Das Aufwandskonto für die Absetzung fehlt'];
            }
            if ($g['uebernahme'] !== null && $g['uebernahme'] <= $g['zugang']) {
                $f[] = ['feld' => 'uebernahme', 'meldung' => 'Der Stichtag der Übernahme liegt nach dem Zugang'];
            }
            if ($g['uebernahme'] === null && (float) $g['bisherige_afa'] > 0) {
                $f[] = ['feld' => 'uebernahme', 'meldung' => 'Zu einer bisherigen Absetzung gehört der Stichtag der Übernahme'];
            }
            if ((float) $g['bisherige_afa'] > (float) $g['kosten']) {
                $f[] = ['feld' => 'bisherige_afa', 'meldung' => 'Mehr als die Anschaffungskosten'];
            }
            if ($g['uebernahme'] !== null && $g['art'] !== 'linear') {
                $f[] = ['feld' => 'uebernahme', 'meldung' => 'Eine Übernahme mit bisheriger Absetzung gibt es nur bei der Verteilung über die Nutzungsdauer'];
            }
        }
        if ($f !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        return $g;
    }

    /** @param array<string, mixed> $w */
    private static function json(array $w): string
    {
        return json_encode($w, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
