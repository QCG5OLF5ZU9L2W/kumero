<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Buchen;

use Kumero\Api\V1\Problem;
use Kumero\Http\Antwort;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Geschäftsvorfälle: vom Nutzer gepflegte Vorlagen für die Buchungserfassung (M5).
 * Lesen per SELECT im eigenen Schema, Schreiben nur über die Funktionen des Moduls (F44, F64).
 */
final class WebController
{
    private const FELDER = ['gruppe', 'name', 'konto', 'gegenkonto', 'buchungsart', 'text', 'tags', 'reihenfolge'];
    private const SQL = 'SELECT id, gruppe, name, konto, gegenkonto, buchungsart, text, to_jsonb(tags)::text AS tags, reihenfolge
                           FROM buchen.vorlage';

    public function liste(Anfrage $a): Response
    {
        return Antwort::json(array_map([self::class, 'vorlage'],
            $a->db()->fetchAllAssociative(self::SQL . ' ORDER BY reihenfolge, gruppe, name')));
    }

    public function anlegen(Anfrage $a): Response
    {
        $id = (string) $a->db()->fetchOne('SELECT buchen.vorlage_setzen(?, NULL, ?::jsonb)',
            [$a->kontext()->subjekt, self::json(self::pruefen($a->json()))]);
        return Antwort::json(self::holen($a, $id), 201);
    }

    public function ersetzen(Anfrage $a): Response
    {
        $id = $a->param('id');
        self::holen($a, $id);
        $a->db()->fetchOne('SELECT buchen.vorlage_setzen(?, ?::uuid, ?::jsonb)',
            [$a->kontext()->subjekt, $id, self::json(self::pruefen($a->json()))]);
        return Antwort::json(self::holen($a, $id));
    }

    public function loeschen(Anfrage $a): Response
    {
        $id = $a->param('id');
        self::holen($a, $id);
        $a->db()->executeStatement('SELECT buchen.vorlage_loeschen(?, ?::uuid)', [$a->kontext()->subjekt, $id]);
        return Antwort::leer();
    }

    /** Steuerkonten des Mandanten: Konto und hinterlegter Steuersatz (F101). */
    public function steuerkonten(Anfrage $a): Response
    {
        return Antwort::json(['steuerkonten' => $a->db()->fetchAllAssociative('SELECT konto, satz::text AS satz FROM buchen.steuerkonto ORDER BY konto')]);
    }

    /** Steuersätze setzen oder löschen: {saetze: {konto: "19.00" | null}}. */
    public function steuerkontenSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        $saetze = $j['saetze'] ?? null;
        $f = [];
        if (array_diff(array_keys($j), ['saetze']) !== [] || !is_array($saetze) || $saetze === [] || array_is_list($saetze) || count($saetze) > 200) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'saetze', 'meldung' => 'Objekt {konto: satz} mit 1 bis 200 Konten erwartet']]);
        }
        foreach ($saetze as $konto => $satz) {
            $konto = (string) $konto;
            if ($satz !== null && (!is_string($satz) || !preg_match('/^\d{1,2}(\.\d{1,2})?$/', $satz) || (float) $satz <= 0)) {
                $f[] = ['feld' => "saetze.$konto", 'meldung' => 'Steuersatz als Dezimal-String zwischen 0 und 100 (z. B. "19.00") oder null erwartet'];
            } elseif ($konto === '' || strlen($konto) > 20) {
                $f[] = ['feld' => "saetze.$konto", 'meldung' => 'Kontonummer mit höchstens 20 Zeichen erwartet'];
            }
        }
        if ($f !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        $a->db()->executeStatement('SELECT buchen.steuerkonten_setzen(?, ?::jsonb)', [$a->kontext()->subjekt, json_encode((object) $saetze)]);
        return $this->steuerkonten($a);
    }

    /** Übernimmt die mitgelieferten allgemeinen Vorlagen, soweit es sie (Gruppe + Name) noch nicht gibt. */
    public function vorgaben(Anfrage $a): Response
    {
        $db = $a->db();
        $datei = dirname(__DIR__) . '/vorgaben/geschaeftsvorfaelle.json';
        $vorgaben = json_decode((string) file_get_contents($datei), true, 8, JSON_THROW_ON_ERROR);
        $angelegt = 0;
        foreach ($vorgaben as $v) {
            if ($db->fetchOne('SELECT 1 FROM buchen.vorlage WHERE gruppe = ? AND name = ?', [$v['gruppe'], $v['name']])) {
                continue;
            }
            $db->fetchOne('SELECT buchen.vorlage_setzen(?, NULL, ?::jsonb)', [$a->kontext()->subjekt, self::json(self::pruefen($v))]);
            $angelegt++;
        }
        return Antwort::json(['angelegt' => $angelegt, 'vorhanden' => count($vorgaben) - $angelegt]);
    }

    /** @return array<string, mixed> */
    private static function holen(Anfrage $a, string $id): array
    {
        $z = $a->db()->fetchAssociative(self::SQL . ' WHERE id = ?::uuid', [$id]) ?: throw Problem::nichtGefunden('Vorlage unbekannt');
        return self::vorlage($z);
    }

    /** @param array<string, mixed> $z @return array<string, mixed> */
    private static function vorlage(array $z): array
    {
        return ['id' => $z['id'], 'gruppe' => $z['gruppe'], 'name' => $z['name'], 'konto' => $z['konto'], 'gegenkonto' => $z['gegenkonto'],
                'buchungsart' => $z['buchungsart'], 'text' => $z['text'], 'tags' => json_decode((string) $z['tags'], true),
                'reihenfolge' => (int) $z['reihenfolge']];
    }

    /**
     * @param array<string, mixed> $j
     * @return array<string, mixed>
     */
    private static function pruefen(array $j): array
    {
        $f = [];
        foreach (array_keys($j) as $k) {
            if (!in_array($k, self::FELDER, true)) {
                $f[] = ['feld' => (string) $k, 'meldung' => 'Unbekanntes Feld'];
            }
        }
        $erg = [];
        foreach (['gruppe' => [true, 60], 'name' => [true, 100], 'konto' => [false, 20], 'gegenkonto' => [false, 20],
                  'buchungsart' => [false, 16], 'text' => [false, 500]] as $feld => [$pflicht, $max]) {
            $w = $j[$feld] ?? null;
            if (is_string($w) && trim($w) === '' && !$pflicht) {
                $w = null;
            }
            if ($w === null) {
                if ($pflicht) {
                    $f[] = ['feld' => $feld, 'meldung' => 'Pflichtfeld'];
                }
            } elseif (!is_string($w) || trim($w) === '' || mb_strlen($w) > $max) {
                $f[] = ['feld' => $feld, 'meldung' => "Text mit 1 bis $max Zeichen erwartet"];
            }
            $erg[$feld] = is_string($w) ? trim($w) : null;
        }
        if ($erg['konto'] !== null && $erg['konto'] === $erg['gegenkonto']) {
            $f[] = ['feld' => 'gegenkonto', 'meldung' => 'Konto und Gegenkonto müssen verschieden sein (F4)'];
        }
        $tags = $j['tags'] ?? [];
        if (!is_array($tags) || !array_is_list($tags) || count($tags) > 20
            || array_filter($tags, fn ($t) => !is_string($t) || trim($t) === '' || mb_strlen($t) > 100) !== []) {
            $f[] = ['feld' => 'tags', 'meldung' => 'Liste von höchstens 20 Tag-Namen erwartet'];
            $tags = [];
        }
        $erg['tags'] = array_values(array_unique($tags));
        $r = $j['reihenfolge'] ?? 100;
        if (!is_int($r) || $r < 0 || $r > 100000) {
            $f[] = ['feld' => 'reihenfolge', 'meldung' => 'Ganzzahl von 0 bis 100000 erwartet'];
        }
        $erg['reihenfolge'] = $r;
        if ($f !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        return $erg;
    }

    /** @param array<string, mixed> $w */
    private static function json(array $w): string
    {
        return json_encode($w, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
