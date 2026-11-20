<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Auswertungen;

use Kumero\Api\V1\Problem;
use Kumero\Http\Antwort;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gespeicherte Auswertungen (AUSWERTUNGEN.md Abschnitt 8): eigene und die für alle im Buch freigegebenen.
 * Lesen per SELECT im eigenen Schema, Schreiben nur über die Funktionen des Moduls (F44, F64).
 */
final class WebController
{
    public const ARTEN = ['konten', 'journal', 'kontoblaetter'];
    public const ZEITRAEUME = ['monat', 'vormonat', 'quartal', 'vorquartal', 'jahr', 'vorjahr', 'frei'];
    public const SPALTEN = ['vortrag', 'soll', 'haben', 'soll_jahr', 'haben_jahr', 'saldo'];
    public const GLIEDERUNGEN = ['kontoart', 'eigene', 'keine'];
    public const KONTOARTEN = ['aktiv', 'passiv', 'aufwand', 'ertrag'];
    private const KONTEN = '/^[0-9A-Za-z*.,;\s\-–]{0,500}$/u';

    public function liste(Anfrage $a): Response
    {
        $ich = $a->kontext()->subjekt;
        $zeilen = $a->db()->fetchAllAssociative(
            'SELECT id, name, geteilt, besitzer = ? AS eigene, definition::text AS definition,
                    to_char(geaendert_am AT TIME ZONE \'UTC\', \'YYYY-MM-DD"T"HH24:MI:SS"Z"\') AS geaendert_am
               FROM auswertungen.vorlage WHERE besitzer = ? OR geteilt ORDER BY lower(name), id', [$ich, $ich]);
        return Antwort::json(['vorlagen' => array_map([self::class, 'vorlage'], $zeilen),
            'darf_speichern' => $a->kontext()->darf('auswertungen.vorlage.pflegen'), 'darf_teilen' => $a->kontext()->darf('auswertungen.vorlage.teilen')]);
    }

    public function anlegen(Anfrage $a): Response
    {
        [$name, $geteilt, $def] = self::pruefen($a);
        $id = (string) $a->db()->fetchOne('SELECT auswertungen.vorlage_setzen(?, NULL, ?, ?, ?::jsonb)',
            [$a->kontext()->subjekt, $name, $geteilt ? 'true' : 'false', self::json($def)]);
        return Antwort::json(self::holen($a, $id), 201);
    }

    public function ersetzen(Anfrage $a): Response
    {
        $id = $a->param('id');
        self::holen($a, $id, true);
        [$name, $geteilt, $def] = self::pruefen($a);
        $a->db()->fetchOne('SELECT auswertungen.vorlage_setzen(?, ?::uuid, ?, ?, ?::jsonb)',
            [$a->kontext()->subjekt, $id, $name, $geteilt ? 'true' : 'false', self::json($def)]);
        return Antwort::json(self::holen($a, $id));
    }

    public function loeschen(Anfrage $a): Response
    {
        $id = $a->param('id');
        self::holen($a, $id, true);
        $a->db()->executeStatement('SELECT auswertungen.vorlage_loeschen(?, ?::uuid)', [$a->kontext()->subjekt, $id]);
        return Antwort::leer();
    }

    /** @return array<string, mixed> */
    private static function holen(Anfrage $a, string $id, bool $nurEigene = false): array
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id)) {
            throw Problem::nichtGefunden('Auswertung unbekannt');
        }
        $ich = $a->kontext()->subjekt;
        $z = $a->db()->fetchAssociative(
            'SELECT id, name, geteilt, besitzer = ? AS eigene, definition::text AS definition,
                    to_char(geaendert_am AT TIME ZONE \'UTC\', \'YYYY-MM-DD"T"HH24:MI:SS"Z"\') AS geaendert_am
               FROM auswertungen.vorlage WHERE id = ?::uuid AND (besitzer = ? OR (geteilt AND NOT ?::boolean))', [$ich, $id, $ich, $nurEigene ? 'true' : 'false']);
        // eine fremde Auswertung, die man ändern will, sieht aus wie eine unbekannte
        return $z === false ? throw Problem::nichtGefunden('Auswertung unbekannt oder nicht die eigene') : self::vorlage($z);
    }

    /** @param array<string, mixed> $z @return array<string, mixed> */
    private static function vorlage(array $z): array
    {
        return ['id' => $z['id'], 'name' => $z['name'], 'geteilt' => (bool) $z['geteilt'], 'eigene' => (bool) $z['eigene'],
                'definition' => json_decode((string) $z['definition'], true), 'geaendert_am' => $z['geaendert_am']];
    }

    /**
     * Prüft Namen, Freigabe und die Form der Definition. Unbekannte Felder werden abgewiesen, nicht ignoriert.
     *
     * @return array{0: string, 1: bool, 2: array<string, mixed>}
     */
    private static function pruefen(Anfrage $a): array
    {
        $j = $a->json();
        $f = [];
        foreach (array_keys($j) as $k) {
            if (!in_array($k, ['name', 'geteilt', 'definition'], true)) {
                $f[] = ['feld' => (string) $k, 'meldung' => 'Unbekanntes Feld'];
            }
        }
        $name = $j['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 80) {
            $f[] = ['feld' => 'name', 'meldung' => 'Name mit 1 bis 80 Zeichen erwartet'];
        }
        $geteilt = $j['geteilt'] ?? false;
        if (!is_bool($geteilt)) {
            $f[] = ['feld' => 'geteilt', 'meldung' => 'true oder false erwartet'];
        } elseif ($geteilt && !$a->kontext()->darf('auswertungen.vorlage.teilen')) {
            throw new Problem(403, 'recht.fehlt', 'Recht fehlt: auswertungen.vorlage.teilen');
        }
        $d = $j['definition'] ?? null;
        $def = [];
        if (!is_array($d) || ($d !== [] && array_is_list($d))) {
            $f[] = ['feld' => 'definition', 'meldung' => 'Objekt erwartet'];
        } else {
            $erlaubt = ['art', 'zeitraum', 'von', 'bis', 'entwuerfe', 'spalten', 'ohne_null', 'gliederung', 'einzeln', 'konten', 'kontoarten', 'zeilen'];
            foreach (array_keys($d) as $k) {
                if (!in_array($k, $erlaubt, true)) {
                    $f[] = ['feld' => "definition.$k", 'meldung' => 'Unbekanntes Feld'];
                }
            }
            $wahl = function (string $feld, array $werte, string $vorgabe) use ($d, &$f): string {
                $w = $d[$feld] ?? $vorgabe;
                if (!is_string($w) || !in_array($w, $werte, true)) {
                    $f[] = ['feld' => "definition.$feld", 'meldung' => 'Erlaubt: ' . implode(', ', $werte)];
                    return $vorgabe;
                }
                return $w;
            };
            $schalter = function (string $feld, bool $vorgabe) use ($d, &$f): bool {
                $w = $d[$feld] ?? $vorgabe;
                if (!is_bool($w)) {
                    $f[] = ['feld' => "definition.$feld", 'meldung' => 'true oder false erwartet'];
                    return $vorgabe;
                }
                return $w;
            };
            $def['art'] = $wahl('art', self::ARTEN, 'konten');
            $def['zeitraum'] = $wahl('zeitraum', self::ZEITRAEUME, 'jahr');
            foreach (['von', 'bis'] as $feld) {
                $w = $d[$feld] ?? null;
                if ($w !== null) {
                    $t = is_string($w) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $w) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $w) : false;
                    if ($t === false || $t->format('Y-m-d') !== $w) {
                        $f[] = ['feld' => "definition.$feld", 'meldung' => 'Datum als JJJJ-MM-TT erwartet'];
                    } else {
                        $def[$feld] = $w;
                    }
                }
            }
            $def['entwuerfe'] = $schalter('entwuerfe', false);
            $def['ohne_null'] = $schalter('ohne_null', true);
            $def['einzeln'] = $schalter('einzeln', true);
            $def['gliederung'] = $wahl('gliederung', self::GLIEDERUNGEN, 'kontoart');
            $spalten = $d['spalten'] ?? self::SPALTEN;
            if (!is_array($spalten) || !array_is_list($spalten) || $spalten === [] || array_diff($spalten, self::SPALTEN) !== [] || count(array_unique($spalten)) !== count($spalten)) {
                $f[] = ['feld' => 'definition.spalten', 'meldung' => 'Mindestens eine Spalte aus: ' . implode(', ', self::SPALTEN)];
            } else {
                // in der festen Reihenfolge der Liste – die Reihenfolge der Eingabe trägt keine Bedeutung
                $def['spalten'] = array_values(array_intersect(self::SPALTEN, $spalten));
            }
            $konten = $d['konten'] ?? '';
            if (!is_string($konten) || !preg_match(self::KONTEN, $konten)) {
                $f[] = ['feld' => 'definition.konten', 'meldung' => 'Kontonummern und Bereiche erwartet, z. B. 4200-4299, 4900'];
            } else {
                $def['konten'] = trim($konten);
            }
            $arten = $d['kontoarten'] ?? [];
            if (!is_array($arten) || !array_is_list($arten) || array_diff($arten, self::KONTOARTEN) !== [] || count(array_unique($arten)) !== count($arten)) {
                $f[] = ['feld' => 'definition.kontoarten', 'meldung' => 'Liste aus: ' . implode(', ', self::KONTOARTEN) . ' – leer heißt alle'];
            } else {
                $def['kontoarten'] = array_values(array_intersect(self::KONTOARTEN, $arten));
            }
            $zeilen = $d['zeilen'] ?? [];
            $def['zeilen'] = [];
            if (!is_array($zeilen) || !array_is_list($zeilen) || count($zeilen) > 100) {
                $f[] = ['feld' => 'definition.zeilen', 'meldung' => 'Liste mit höchstens 100 Zeilen erwartet'];
            } else {
                foreach ($zeilen as $n => $z) {
                    $titel = is_array($z) ? ($z['titel'] ?? null) : null;
                    $summe = is_array($z) ? ($z['summe'] ?? false) : false;
                    $kz = is_array($z) ? ($z['konten'] ?? '') : null;
                    if (!is_array($z) || array_diff(array_keys($z), ['titel', 'konten', 'summe']) !== [] || !is_string($titel) || trim($titel) === '' || mb_strlen(trim($titel)) > 100
                        || !is_bool($summe) || !is_string($kz) || !preg_match(self::KONTEN, $kz) || (!$summe && trim($kz) === '')) {
                        $f[] = ['feld' => "definition.zeilen[$n]", 'meldung' => 'Zeile erwartet: titel (höchstens 100 Zeichen) und konten (z. B. 4200-4299) – oder titel und summe: true'];
                        continue;
                    }
                    $def['zeilen'][] = $summe ? ['titel' => trim($titel), 'summe' => true] : ['titel' => trim($titel), 'konten' => trim($kz)];
                }
            }
            if ($def['gliederung'] === 'eigene' && $def['zeilen'] === [] && $f === []) {
                $f[] = ['feld' => 'definition.zeilen', 'meldung' => 'Eine eigene Gliederung braucht mindestens eine Zeile'];
            }
        }
        if ($f !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        return [trim((string) $name), (bool) $geteilt, $def];
    }

    /** @param array<string, mixed> $w */
    private static function json(array $w): string
    {
        return json_encode($w, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
