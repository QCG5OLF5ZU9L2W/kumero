<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Arbeitsvorrat;

use Doctrine\DBAL\Connection;
use Kumero\Api\V1\Problem;

/**
 * Vertrag für Quellen (ARBEITSVORRAT.md Abschnitt 3): einliefern und zurückziehen. Quellen sind
 * Module; sie sprechen mit dem Arbeitsvorrat nur über diesen Dienst, nie über dessen Tabellen.
 * Der Arbeitsvorrat kennt keine Quelle beim Namen – die Quelle nennt sich selbst.
 */
final class Dienst
{
    public const BELEG_LINK = '~^https?://[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:[0-9]{1,5})?(/[^\s?#]*)?$~';
    private const FELDER = ['betreff', 'art', 'gegenpartei', 'gegenpartei_kennung', 'datum', 'betrag', 'richtung',
                            'belegnummer', 'zahlweise', 'zahltag', 'belege', 'hinweise'];

    public function __construct(private readonly Connection $db, private readonly string $subjekt)
    {
    }

    /**
     * Legt den Beitrag der Quelle an oder ersetzt ihn. Derselbe Schlüssel ergibt nie einen zweiten Eintrag.
     *
     * @param array<string, mixed> $vorschlag betreff (Pflicht), art, gegenpartei, gegenpartei_kennung, datum, betrag,
     *        richtung (ausgabe|einnahme), belegnummer, zahlweise (bar|unbar), zahltag, belege [{link, md5?, sha256?}],
     *        hinweise [{feld?, text}]
     * @param list<array{bezeichnung: string, wert: string}> $angaben nur zur Anzeige neben der Maske
     * @return array{eintrag: string, ergebnis: string} ergebnis: angelegt | ersetzt | unveraendert | vermerkt
     */
    public function einliefern(string $quelle, string $schluessel, array $vorschlag, array $angaben = []): array
    {
        if (!preg_match('/^[a-z][a-z0-9-]{0,31}$/', $quelle) || $schluessel === '' || mb_strlen($schluessel) > 200) {
            throw Problem::ungueltig('Quelle oder Schlüssel ungültig');
        }
        [$v, $f] = self::pruefen($vorschlag, $angaben);
        if ($f !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        $json = fn ($w) => json_encode($w, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return json_decode((string) $this->db->fetchOne('SELECT arbeitsvorrat.einliefern(?, ?, ?, ?::jsonb, ?::jsonb)',
            [$this->subjekt, $quelle, $schluessel, $json((object) $v), $json(array_values($angaben))]), true);
    }

    /**
     * Beiträge einer Quelle mit dem Zustand ihres Eintrags – damit die Quelle weiß, was aus ihren Lieferungen wurde.
     *
     * @return array<string, array{zustand: string, zurueckgezogen: bool}> Schlüssel → Stand
     */
    public function beitraege(string $quelle): array
    {
        $erg = [];
        foreach ($this->db->fetchAllAssociative('SELECT b.schluessel, e.zustand, b.zurueckgezogen_am IS NOT NULL AS weg
                  FROM arbeitsvorrat.beitrag b JOIN arbeitsvorrat.eintrag e ON e.id = b.eintrag WHERE b.quelle = ?', [$quelle]) as $z) {
            $erg[$z['schluessel']] = ['zustand' => $z['zustand'], 'zurueckgezogen' => (bool) $z['weg']];
        }
        return $erg;
    }

    /** Die Quelle hält den Vorgang nicht mehr für offen. Gibt false zurück, wenn es nichts zurückzuziehen gab. */
    public function zurueckziehen(string $quelle, string $schluessel): bool
    {
        return (bool) $this->db->fetchOne('SELECT arbeitsvorrat.zurueckziehen(?, ?, ?)', [$this->subjekt, $quelle, $schluessel]);
    }

    /**
     * Prüft die Form von Vorschlag und Angaben; leere Werte fallen weg.
     *
     * @param array<string, mixed> $j
     * @return array{0: array<string, mixed>, 1: list<array{feld: string, meldung: string}>}
     */
    public static function pruefen(array $j, mixed $angaben = []): array
    {
        $f = [];
        $v = [];
        foreach (array_keys($j) as $k) {
            if (!in_array($k, self::FELDER, true)) {
                $f[] = ['feld' => (string) $k, 'meldung' => 'Unbekanntes Feld'];
            }
        }
        foreach (['betreff' => 200, 'gegenpartei' => 200, 'gegenpartei_kennung' => 60, 'belegnummer' => 36] as $feld => $max) {
            $w = $j[$feld] ?? null;
            if ($w === null || (is_string($w) && trim($w) === '')) {
                if ($feld === 'betreff') {
                    $f[] = ['feld' => $feld, 'meldung' => 'Pflichtfeld'];
                }
                continue;
            }
            if (!is_string($w) || mb_strlen(trim($w)) > $max) {
                $f[] = ['feld' => $feld, 'meldung' => "Text mit höchstens $max Zeichen erwartet"];
                continue;
            }
            $v[$feld] = trim($w);
        }
        $muster = ['art' => ['/^[a-z][a-z0-9_-]{0,39}$/', 'Stichwort in Kleinbuchstaben erwartet, z. B. rechnung'],
                   'richtung' => ['/^(ausgabe|einnahme)$/', 'ausgabe oder einnahme erwartet'],
                   'zahlweise' => ['/^(bar|unbar)$/', 'bar oder unbar erwartet'],
                   'betrag' => ['/^\d{1,13}(\.\d{1,2})?$/', 'Betrag als Dezimal-String erwartet, z. B. "109.43" (F19)']];
        foreach ($muster as $feld => [$regel, $meldung]) {
            $w = $j[$feld] ?? null;
            if ($w === null || $w === '') {
                continue;
            }
            if (!is_string($w) || !preg_match($regel, $w) || ($feld === 'betrag' && (float) $w <= 0)) {
                $f[] = ['feld' => $feld, 'meldung' => $meldung];
                continue;
            }
            $v[$feld] = $feld === 'betrag' ? number_format((float) $w, 2, '.', '') : $w;
        }
        foreach (['datum', 'zahltag'] as $feld) {
            $w = $j[$feld] ?? null;
            if ($w === null || $w === '') {
                continue;
            }
            $d = is_string($w) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $w) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $w) : false;
            if ($d === false || $d->format('Y-m-d') !== $w) {
                $f[] = ['feld' => $feld, 'meldung' => 'Datum als JJJJ-MM-TT erwartet'];
                continue;
            }
            $v[$feld] = $w;
        }
        $belege = $j['belege'] ?? [];
        if (!is_array($belege) || !array_is_list($belege) || count($belege) > 20) {
            $f[] = ['feld' => 'belege', 'meldung' => 'Liste mit höchstens 20 Belegverweisen erwartet'];
        } else {
            foreach ($belege as $n => $b) {
                $md5 = is_array($b) ? ($b['md5'] ?? null) : null;
                $sha = is_array($b) ? ($b['sha256'] ?? null) : null;
                if (!is_array($b) || array_diff(array_keys($b), ['link', 'md5', 'sha256']) !== []
                    || !is_string($b['link'] ?? null) || strlen($b['link']) > 500 || !preg_match(self::BELEG_LINK, $b['link'])
                    || ($md5 !== null && (!is_string($md5) || !preg_match('/^[0-9a-f]{32}$/', $md5)))
                    || ($sha !== null && (!is_string($sha) || !preg_match('/^[0-9a-f]{64}$/', $sha)))
                    || ($md5 === null) !== ($sha === null)) {
                    $f[] = ['feld' => "belege[$n]", 'meldung' => 'Belegverweis erwartet: link, dazu md5 und sha256 gemeinsam oder gar nicht (F88)'];
                    continue;
                }
                $v['belege'][] = $md5 === null ? ['link' => $b['link']] : ['link' => $b['link'], 'md5' => $md5, 'sha256' => $sha];
            }
        }
        $hinweise = $j['hinweise'] ?? [];
        if (!is_array($hinweise) || !array_is_list($hinweise) || count($hinweise) > 30) {
            $f[] = ['feld' => 'hinweise', 'meldung' => 'Liste mit höchstens 30 Hinweisen erwartet'];
        } else {
            foreach ($hinweise as $n => $h) {
                if (!is_array($h) || array_diff(array_keys($h), ['feld', 'text']) !== [] || !is_string($h['text'] ?? null)
                    || trim($h['text']) === '' || mb_strlen($h['text']) > 300
                    || (isset($h['feld']) && !in_array($h['feld'], self::FELDER, true))) {
                    $f[] = ['feld' => "hinweise[$n]", 'meldung' => 'Hinweis erwartet: text (höchstens 300 Zeichen), dazu wahlweise feld'];
                    continue;
                }
                $v['hinweise'][] = isset($h['feld']) ? ['feld' => $h['feld'], 'text' => trim($h['text'])] : ['text' => trim($h['text'])];
            }
        }
        if (!is_array($angaben) || !array_is_list($angaben) || count($angaben) > 60) {
            $f[] = ['feld' => 'angaben', 'meldung' => 'Liste mit höchstens 60 Angaben erwartet'];
        } else {
            foreach ($angaben as $n => $x) {
                if (!is_array($x) || array_diff(array_keys($x), ['bezeichnung', 'wert']) !== [] || !is_string($x['bezeichnung'] ?? null)
                    || !is_string($x['wert'] ?? null) || mb_strlen($x['bezeichnung']) > 60 || mb_strlen($x['wert']) > 1000) {
                    $f[] = ['feld' => "angaben[$n]", 'meldung' => 'Angabe erwartet: bezeichnung (höchstens 60 Zeichen) und wert (höchstens 1000 Zeichen)'];
                }
            }
        }
        return [$v, $f];
    }
}
