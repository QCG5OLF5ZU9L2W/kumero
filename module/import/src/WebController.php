<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Import;

use Kumero\Api\V1\Problem;
use Kumero\Http\Antwort;
use Kumero\Modul\Arbeitsvorrat\Dienst;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST des Moduls import (IMPORT.md Abschnitt 11). Die Datei kommt mit jeder Anfrage als Körper und wird nie
 * gespeichert (C9); die Einstellungen stehen als JSON im Parameter „einstellungen“. Logs nennen keine Inhalte.
 */
final class WebController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** Aufbau erkennen: Vorschlag für Merkmale, Spalten und Richtung, passende Profile, erste Zeilen. */
    public function erkennen(Anfrage $a): Response
    {
        $roh = self::datei($a);
        $e = Csv::erkennen($roh);
        // der Nutzer darf die erkannten Merkmale ändern und neu lesen lassen
        foreach (['zeichensatz', 'trenner', 'datum', 'dezimal'] as $k) {
            if ($a->http->query->has($k)) {
                $e[$k] = $k === 'trenner' && $a->http->query->get($k) === 'tab' ? "\t" : (string) $a->http->query->get($k);
            }
        }
        foreach (['kopfzeile', 'fusszeilen'] as $k) {
            if ($a->http->query->has($k)) {
                $e[$k] = (int) $a->http->query->get($k);
            }
        }
        if (!in_array($e['zeichensatz'], Csv::ZEICHENSAETZE, true) || !in_array($e['trenner'], Csv::TRENNER, true) || $e['kopfzeile'] < 1 || $e['fusszeilen'] < 0) {
            throw Problem::ungueltig('Merkmale der Datei ungültig');
        }
        $t = self::lesen($roh, $e);
        $profile = array_values(array_filter(self::profilListe($a), fn ($p) => $p['kopf'] === $t['kopf']));
        return Antwort::json(['merkmale' => $e, 'kopf' => $t['kopf'], 'zeilen_anzahl' => count($t['zeilen']),
            'beispiel' => array_map(fn ($z) => $z['felder'], array_slice($t['zeilen'], 0, 5)),
            'vorspann' => array_slice(explode("\n", Csv::text($roh, $e['zeichensatz'])), 0, min(12, $e['kopfzeile'] + 2)),
            'vorschlag' => Csv::vorschlag($t['kopf']), 'profile' => $profile, 'datei_sha256' => hash('sha256', $roh),
            'schon_eingelesen' => (bool) $a->db()->fetchOne('SELECT 1 FROM import.lauf WHERE datei_sha256 = ? AND zurueckgezogen_am IS NULL LIMIT 1', [hash('sha256', $roh)])]);
    }

    /** Vorschau: was aus den Zeilen würde – neu, schon vorhanden, fehlerhaft –, Summen und Saldenkontrolle. */
    public function vorschau(Anfrage $a): Response
    {
        return Antwort::json($this->auswerten($a)['bericht']);
    }

    /** Einliefern: neue Zeilen werden Einträge des Arbeitsvorrats; fehlerhafte markiert, vorhandene übersprungen. */
    public function einliefern(Anfrage $a): Response
    {
        $roh = self::datei($a);
        $erg = $this->auswerten($a);
        $dienst = new Dienst($a->db(), $a->kontext()->subjekt);
        $name = self::dateiname($a);
        $schluessel = [];
        $db = $a->db();
        $lauf = $db->transactional(function () use ($erg, $dienst, $name, $roh, $a, $db, &$schluessel) {
            foreach ($erg['neu'] as $z) {
                $angaben = array_merge([['bezeichnung' => 'Datei', 'wert' => "$name, Zeile {$z['nr']}"]], $z['angaben']);
                $dienst->einliefern(Modul::QUELLE, $z['schluessel'], $z['vorschlag'], array_slice($angaben, 0, 40));
                $schluessel[] = $z['schluessel'];
            }
            return (string) $db->fetchOne('SELECT import.lauf_anlegen(?, ?, ?, ?, ?, ?, ?, ?, ?::text[])', [$a->kontext()->subjekt, $name, hash('sha256', $roh),
                $erg['profil'], $erg['einstellungen']['geldkonto'], count($schluessel), $erg['bericht']['vorhanden'], $erg['bericht']['fehlerhaft'],
                '{' . implode(',', array_map(fn ($s) => '"' . $s . '"', $schluessel)) . '}']);
        });
        return Antwort::json(['import' => $lauf] + $erg['bericht'], 201);
    }

    /** E-Rechnung ansehen (IMPORT.md Abschnitt 15): was aus der Datei würde – ohne etwas zu speichern. */
    public function rechnungVorschau(Anfrage $a): Response
    {
        return Antwort::json($this->rechnung($a)['bericht']);
    }

    /** E-Rechnung einliefern: Die Rechnung wird ein Eintrag des Arbeitsvorrats; eine schon vorhandene wird übersprungen. */
    public function rechnungEinliefern(Anfrage $a): Response
    {
        $erg = $this->rechnung($a);
        $db = $a->db();
        $name = self::dateiname($a);
        $neu = $erg['bericht']['stand'] !== 'vorhanden';
        $lauf = $db->transactional(function () use ($erg, $db, $a, $name, $neu) {
            if ($neu) {
                $angaben = array_merge([['bezeichnung' => 'Datei', 'wert' => $name], ['bezeichnung' => 'Datei SHA-256', 'wert' => $erg['sha256']]], $erg['eintrag']['angaben']);
                (new Dienst($db, $a->kontext()->subjekt))->einliefern(Modul::QUELLE, $erg['eintrag']['schluessel'], $erg['eintrag']['vorschlag'], array_slice($angaben, 0, 40));
            }
            return (string) $db->fetchOne('SELECT import.lauf_anlegen(?, ?, ?, ?, ?, ?, ?, ?, ?::text[])', [$a->kontext()->subjekt, $name, $erg['sha256'],
                'E-Rechnung', '', $neu ? 1 : 0, $neu ? 0 : 1, $neu && $erg['eintrag']['fehler'] !== null ? 1 : 0, $neu ? '{"' . $erg['eintrag']['schluessel'] . '"}' : '{}']);
        });
        return Antwort::json(['import' => $lauf, 'eingeliefert' => $neu] + $erg['bericht'], 201);
    }

    /**
     * Liest die E-Rechnung aus dem Körper der Anfrage und stellt fest, ob sie schon im Arbeitsvorrat liegt.
     *
     * @return array{bericht: array<string, mixed>, eintrag: array<string, mixed>, sha256: string}
     */
    private function rechnung(Anfrage $a): array
    {
        $roh = $a->http->getContent();
        if ($roh === '') {
            throw Problem::ungueltig('Datei fehlt (Körper der Anfrage)');
        }
        if (strlen($roh) > Rechnung::HOECHSTENS_BYTES) {
            throw new Problem(413, 'anfrage.zu_gross', 'Datei größer als 5 MB');
        }
        $seite = (string) $a->http->query->get('seite', 'eingang');
        if (!in_array($seite, ['eingang', 'ausgang'], true)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'seite', 'meldung' => 'eingang oder ausgang erwartet']]);
        }
        try {
            $r = Rechnung::lesen($roh);
            $e = Rechnung::anwenden($r, $seite);
        } catch (\InvalidArgumentException $x) {
            throw new Problem(422, 'import.rechnung', $x->getMessage());
        }
        $stand = (new Dienst($a->db(), $a->kontext()->subjekt))->beitraege(Modul::QUELLE)[$e['schluessel']] ?? null;
        $vorhanden = $stand !== null && !$stand['zurueckgezogen'];
        return ['eintrag' => $e, 'sha256' => hash('sha256', $roh), 'bericht' => [
            'format' => Rechnung::formatName($r), 'stand' => $vorhanden ? 'vorhanden' : ($e['fehler'] !== null ? 'fehlerhaft' : 'neu'),
            'fehler' => $e['fehler'], 'eintrag' => array_diff_key($e['vorschlag'], ['hinweise' => 1]),
            'hinweise' => array_map(fn ($h) => $h['text'], $e['vorschlag']['hinweise'] ?? []),
            'waehrung' => $r['waehrung'], 'faellig' => $r['faellig'], 'lieferdatum' => $r['lieferdatum'], 'positionen' => $r['positionen'],
            'positionsnamen' => $r['positionsnamen'],
            'steuern' => array_map(fn ($s) => ['satz' => $s['satz'], 'kategorie' => $s['kategorie'], 'basis' => $s['basis'], 'betrag' => $s['betrag']], $r['steuern']),
        ]];
    }

    /** Bisherige Importe, neueste zuerst. */
    public function importe(Anfrage $a): Response
    {
        return Antwort::json(['importe' => array_map(fn ($z) => ['id' => $z['id'], 'am' => $z['am'], 'von' => $z['subjekt'], 'dateiname' => $z['dateiname'],
            'profil' => $z['profil'], 'geldkonto' => $z['geldkonto'], 'neu' => (int) $z['neu'], 'vorhanden' => (int) $z['vorhanden'], 'fehlerhaft' => (int) $z['fehlerhaft'],
            'zurueckgezogen_am' => $z['weg']], $a->db()->fetchAllAssociative('SELECT id, kern.zeitpunkt(am) AS am, subjekt, dateiname, profil, geldkonto, neu, vorhanden, fehlerhaft,
                   kern.zeitpunkt(zurueckgezogen_am) AS weg FROM import.lauf ORDER BY am DESC LIMIT 200'))]);
    }

    /** Import zurückziehen (C11): nur solange keiner seiner Einträge in Arbeit, gebucht oder verworfen ist. */
    public function zurueckziehen(Anfrage $a): Response
    {
        $id = $a->param('id');
        $db = $a->db();
        $z = preg_match(self::UUID, $id) ? $db->fetchAssociative('SELECT schluessel::text AS s, zurueckgezogen_am FROM import.lauf WHERE id = ?::uuid', [$id]) : false;
        if ($z === false) {
            throw Problem::nichtGefunden('Import unbekannt');
        }
        if ($z['zurueckgezogen_am'] !== null) {
            throw new Problem(409, 'import.zurueckgezogen', 'Der Import ist schon zurückgezogen');
        }
        $schluessel = (string) $z['s'] === '{}' ? [] : str_getcsv(trim((string) $z['s'], '{}'), ',', '"', '\\');
        $dienst = new Dienst($db, $a->kontext()->subjekt);
        $stand = $dienst->beitraege(Modul::QUELLE);
        $bearbeitet = count(array_filter($schluessel, fn ($s) => isset($stand[$s]) && !$stand[$s]['zurueckgezogen'] && $stand[$s]['zustand'] !== 'offen'));
        if ($bearbeitet > 0) {
            throw new Problem(409, 'import.bearbeitet', "$bearbeitet Einträge dieses Imports sind schon in Arbeit, gebucht oder verworfen – der Import lässt sich nicht mehr als Ganzes zurückziehen");
        }
        $db->transactional(function () use ($schluessel, $dienst, $db, $a, $id) {
            foreach ($schluessel as $s) {
                $dienst->zurueckziehen(Modul::QUELLE, $s);
            }
            $db->executeStatement('SELECT import.lauf_zurueckgezogen(?, ?::uuid)', [$a->kontext()->subjekt, $id]);
        });
        return Antwort::json(['zurueckgezogen' => count($schluessel)]);
    }

    public function profile(Anfrage $a): Response
    {
        return Antwort::json(['profile' => self::profilListe($a), 'darf_vorgeben' => $a->kontext()->darf('import.profil.pflegen')]);
    }

    /** Profil speichern: {name, fuer: mich|mandant, kopf, einstellungen}; gleicher Name ersetzt. */
    public function profilSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        $fuer = $j['fuer'] ?? 'mich';
        $name = $j['name'] ?? null;
        $kopf = $j['kopf'] ?? null;
        $f = [];
        if (array_diff(array_keys($j), ['name', 'fuer', 'kopf', 'einstellungen']) !== [] || !in_array($fuer, ['mich', 'mandant'], true)) {
            $f[] = ['feld' => 'fuer', 'meldung' => 'name, fuer (mich oder mandant), kopf und einstellungen erwartet'];
        }
        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 80) {
            $f[] = ['feld' => 'name', 'meldung' => 'Name mit höchstens 80 Zeichen erwartet'];
        }
        if (!is_array($kopf) || !array_is_list($kopf) || $kopf === [] || count($kopf) > 200 || array_filter($kopf, fn ($x) => !is_string($x) || mb_strlen($x) > 200) !== []) {
            $f[] = ['feld' => 'kopf', 'meldung' => 'Liste der Spaltennamen erwartet'];
        }
        [$e, $fe] = Csv::pruefen($j['einstellungen'] ?? null);
        if ($f !== [] || $fe !== []) {
            throw Problem::ungueltig('Eingabe ungültig', array_merge($f, $fe));
        }
        if ($fuer === 'mandant' && !$a->kontext()->darf('import.profil.pflegen')) {
            throw new Problem(403, 'recht.fehlt', 'Vorgaben des Mandanten pflegt, wer import.profil.pflegen hat');
        }
        $json = fn ($w) => json_encode($w, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $a->db()->executeStatement('SELECT import.profil_setzen(?, ?::boolean, ?, ?::jsonb, ?::jsonb)',
            [$a->kontext()->subjekt, $fuer === 'mandant' ? 'true' : 'false', trim((string) $name), $json($kopf), $json((object) $e)]);
        return Antwort::json(['profile' => self::profilListe($a)]);
    }

    public function profilLoeschen(Anfrage $a): Response
    {
        $id = $a->param('id');
        $p = preg_match(self::UUID, $id) ? $a->db()->fetchAssociative('SELECT subjekt FROM import.profil WHERE id = ?::uuid AND subjekt IN (?, ?)', [$id, '', $a->kontext()->subjekt]) : false;
        if ($p === false) {
            throw Problem::nichtGefunden('Importprofil unbekannt');
        }
        if ($p['subjekt'] === '' && !$a->kontext()->darf('import.profil.pflegen')) {
            throw new Problem(403, 'recht.fehlt', 'Vorgaben des Mandanten pflegt, wer import.profil.pflegen hat');
        }
        $a->db()->executeStatement('SELECT import.profil_loeschen(?, ?::boolean, ?::uuid)', [$a->kontext()->subjekt, $p['subjekt'] === '' ? 'true' : 'false', $id]);
        return Antwort::leer();
    }

    // --- Hilfen -----------------------------------------------------------------------------

    /**
     * Liest die Datei mit den Einstellungen und teilt die Zeilen ein.
     *
     * @return array{bericht: array<string, mixed>, neu: list<array<string, mixed>>, einstellungen: array<string, mixed>, profil: ?string}
     */
    private function auswerten(Anfrage $a): array
    {
        $roh = self::datei($a);
        $text = (string) $a->http->query->get('einstellungen', '');
        $roheEinstellungen = $text === '' ? null : json_decode($text, true);
        [$e, $f] = Csv::pruefen($roheEinstellungen);
        if ($f !== []) {
            throw Problem::ungueltig('Einstellungen ungültig', $f);
        }
        $profil = trim((string) $a->http->query->get('profil', ''));
        $t = self::lesen($roh, $e);
        try {
            $erg = Csv::anwenden($t, $e);
        } catch (\InvalidArgumentException $x) {
            throw new Problem(422, 'import.spalte', $x->getMessage());
        }
        $stand = (new Dienst($a->db(), $a->kontext()->subjekt))->beitraege(Modul::QUELLE);
        $neu = [];
        $vorhanden = 0;
        $fehler = [];
        $summe = ['einnahme' => 0, 'ausgabe' => 0];
        $von = null;
        $bis = null;
        foreach ($erg['zeilen'] as $z) {
            if (isset($stand[$z['schluessel']]) && !$stand[$z['schluessel']]['zurueckgezogen']) {
                $vorhanden++;
                continue;
            }
            if ($z['fehler'] !== null) {
                $fehler[] = ['zeile' => $z['nr'], 'grund' => $z['fehler']];
            } else {
                $summe[$z['richtung']] += $z['cent'];
                $d = $z['vorschlag']['datum'];
                $von = $von === null || $d < $von ? $d : $von;
                $bis = $bis === null || $d > $bis ? $d : $bis;
            }
            $neu[] = $z;
        }
        $dez = fn (int $c) => sprintf('%s%d.%02d', $c < 0 ? '-' : '', intdiv(abs($c), 100), abs($c) % 100);
        $zeigen = fn (array $z) => ['zeile' => $z['nr'], 'fehler' => $z['fehler']] + array_diff_key($z['vorschlag'], ['hinweise' => 1]);
        return ['neu' => $neu, 'einstellungen' => $e, 'profil' => $profil === '' ? null : mb_substr($profil, 0, 80), 'bericht' => [
            'zeilen' => count($erg['zeilen']), 'neu' => count($neu), 'vorhanden' => $vorhanden, 'fehlerhaft' => count($fehler),
            'fehler' => array_slice($fehler, 0, 100), 'einnahmen' => $dez($summe['einnahme']), 'ausgaben' => $dez($summe['ausgabe']), 'von' => $von, 'bis' => $bis,
            'salden' => $erg['salden'] === null ? null : ['anfang' => $dez($erg['salden']['anfang']), 'ende' => $dez($erg['salden']['ende']), 'stimmt' => $erg['salden']['stimmt']],
            'beispiel' => array_map($zeigen, array_slice($neu, 0, 8)),
        ]];
    }

    private static function datei(Anfrage $a): string
    {
        $roh = $a->http->getContent();
        if ($roh === '') {
            throw Problem::ungueltig('Datei fehlt (Körper der Anfrage)');
        }
        if (strlen($roh) > Csv::HOECHSTENS_BYTES) {
            throw new Problem(413, 'anfrage.zu_gross', 'Datei größer als 5 MB');
        }
        if (str_contains(substr($roh, 0, 4096), "\0")) {
            throw new Problem(415, 'anfrage.medientyp', 'Das ist keine Textdatei – erwartet wird CSV');
        }
        return $roh;
    }

    private static function dateiname(Anfrage $a): string
    {
        $n = trim((string) preg_replace('/[\x00-\x1F\/\\\\]+/', ' ', (string) $a->http->query->get('name', '')));
        return $n === '' ? 'Datei' : mb_substr($n, 0, 200);
    }

    /** @return array{kopf: list<string>, zeilen: list<array{nr: int, felder: array<string, string>}>} */
    private static function lesen(string $roh, array $e): array
    {
        try {
            $t = Csv::lesen($roh, $e);
        } catch (\InvalidArgumentException $x) {
            throw new Problem(422, 'import.aufbau', $x->getMessage());
        }
        if (count($t['zeilen']) > Csv::HOECHSTENS_ZEILEN) {
            throw new Problem(413, 'import.zu_viele_zeilen', 'Mehr als ' . Csv::HOECHSTENS_ZEILEN . ' Zeilen – bitte die Datei teilen');
        }
        if ($t['zeilen'] === []) {
            throw new Problem(422, 'import.leer', 'Unter der Kopfzeile stehen keine Zeilen');
        }
        return $t;
    }

    /** Vorgaben des Mandanten und die eigenen Profile. @return list<array<string, mixed>> */
    private static function profilListe(Anfrage $a): array
    {
        return array_map(fn ($z) => ['id' => $z['id'], 'name' => $z['name'], 'geltung' => $z['subjekt'] === '' ? 'mandant' : 'nutzer',
            'kopf' => json_decode((string) $z['kopf'], true), 'einstellungen' => json_decode((string) $z['einstellungen'], true)],
            $a->db()->fetchAllAssociative("SELECT id, subjekt, name, kopf::text AS kopf, einstellungen::text AS einstellungen FROM import.profil
                  WHERE subjekt IN ('', ?) ORDER BY subjekt = '', name", [$a->kontext()->subjekt]));
    }
}
