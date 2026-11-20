<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Http;

use Kumero\Api\V1\Problem;
use Kumero\Plattform\Anfrage;
use Kumero\Plattform\Module;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module des Mandanten (VERWALTUNG.md Abschnitt 16, F142): Der Administrator sieht, welches Modul wo aktiv ist – am
 * Mandanten und in jedem Buch –, schaltet ein und aus und zieht nach einem Update nach. Ausgeführt wird jeder Schritt
 * als Auftrag vom Dienst auf dem Server (Einschalten spielt Migrationen ein und braucht den Eigentümer der Datenbank).
 * Der Administrator sieht dabei keine Inhalte eines Buchs und keine Personaldaten – nur Namen und Versionen der Module.
 */
final class ModuleController
{
    private const ARTEN = ['einschalten' => 'modul_einschalten', 'ausschalten' => 'modul_ausschalten', 'nachziehen' => 'module_nachziehen'];

    /**
     * Orte, an denen Module leben: der Mandant und seine Bücher, je mit dem Stand der Module dort.
     *
     * @return list<array{buch: ?string, name: string, stand: array<string, array{version: string, aktiv: bool}>}>
     */
    private static function orte(Anfrage $a): array
    {
        $ktx = $a->kontext();
        $lesen = fn ($db, string $s) => array_map(fn (array $z) => ['version' => (string) $z['version'], 'aktiv' => (bool) $z['aktiv']],
            array_column($db->fetchAllAssociative("SELECT name, version, aktiv FROM $s.modul WHERE name <> 'kern'"), null, 'name'));
        $orte = [['buch' => null, 'name' => 'Mandant', 'stand' => $lesen($a->mandantDb(), 'mandant')]];
        $datenbanken = $a->verwaltung()->fetchAllKeyValue('SELECT kennung, datenbank FROM verwaltung.buch WHERE mandant = ?', [$ktx->mandant]);
        foreach ($a->mandantDb()->fetchAllAssociative('SELECT kennung, name FROM mandant.buch ORDER BY testbuch, lower(name), kennung') as $b) {
            if (isset($datenbanken[$b['kennung']])) {
                $orte[] = ['buch' => (string) $b['kennung'], 'name' => (string) $b['name'], 'stand' => $lesen($a->datenbank->verbindung((string) $datenbanken[$b['kennung']]), 'kern')];
            }
        }
        return $orte;
    }

    public function liste(Anfrage $a): Response
    {
        $orte = self::orte($a);
        $module = [];
        foreach (Module::alle() as $name => $m) {
            $amMandanten = Module::ebene($m) === 'mandant';
            $staende = [];
            foreach ($orte as $o) {
                if (($o['buch'] === null) !== $amMandanten) {
                    continue;
                }
                $s = $o['stand'][$name] ?? null;
                $staende[] = ['buch' => $o['buch'], 'ort' => $o['name'], 'zustand' => $s === null ? 'nie' : ($s['aktiv'] ? 'aktiv' : 'aus'),
                    'version' => $s['version'] ?? null, 'veraltet' => $s !== null && $s['aktiv'] && $s['version'] !== $m['version']];
            }
            $module[] = ['name' => $name, 'version' => (string) $m['version'], 'beschreibung' => (string) ($m['beschreibung'] ?? ''), 'ebene' => $amMandanten ? 'mandant' : 'buch',
                'abhaengigkeiten' => array_map('strval', array_keys($m['abhaengigkeiten'] ?? [])), 'staende' => $staende];
        }
        $auftraege = $a->verwaltung()->fetchAllAssociative(
            "SELECT id, art, zustand, to_char(angelegt_am AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS angelegt_am, ergebnis::text AS ergebnis
               FROM verwaltung.auftrag WHERE mandant = ? AND art IN ('modul_einschalten', 'modul_ausschalten', 'module_nachziehen') AND angelegt_am > now() - interval '7 days'
              ORDER BY angelegt_am DESC, coalesce(begonnen_am, 'infinity'::timestamptz) DESC LIMIT 20", [$a->kontext()->mandant]);
        return Antwort::json(['module' => $module,
            'laeuft' => (bool) $a->verwaltung()->fetchOne("SELECT 1 FROM verwaltung.auftrag WHERE mandant = ? AND zustand IN ('offen', 'laeuft')", [$a->kontext()->mandant]),
            'auftraege' => array_map(fn (array $x) => ['id' => $x['id'], 'art' => array_search($x['art'], self::ARTEN, true), 'zustand' => $x['zustand'], 'angelegt_am' => $x['angelegt_am'],
                'ergebnis' => $x['ergebnis'] === null ? null : json_decode((string) $x['ergebnis'], true)], $auftraege)]);
    }

    /** Einschalten, ausschalten oder nachziehen – als Auftrag an den Dienst. Was sich vorher sagen lässt, wird hier schon abgelehnt. */
    public function beauftragen(Anfrage $a): Response
    {
        $e = new Eingabe($a->json());
        $e->nurFelder(['art', 'modul', 'buch']);
        $art = $e->auswahl('art', array_keys(self::ARTEN));
        $modul = $e->text('modul', false, 32);
        $buch = $e->text('buch', false, 20);
        $e->pruefen();
        $ktx = $a->kontext();
        $angaben = ['subjekt' => $ktx->subjekt];
        if ($art !== 'nachziehen') {
            $alle = Module::alle();
            if ($modul === null || !isset($alle[$modul])) {
                throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'modul', 'meldung' => 'Modul unbekannt']]);
            }
            $amMandanten = Module::ebene($alle[$modul]) === 'mandant';
            if ($amMandanten !== ($buch === null)) {
                throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'buch', 'meldung' => $amMandanten ? 'Dieses Modul gehört zum Mandanten, nicht zu einem Buch' : 'Dieses Modul gehört zu einem Buch – welches?']]);
            }
            $ort = null;
            foreach (self::orte($a) as $o) {
                if ($o['buch'] === $buch) {
                    $ort = $o;
                }
            }
            if ($ort === null) {
                throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'buch', 'meldung' => 'Buch unbekannt']]);
            }
            $aktiv = array_keys(array_filter($ort['stand'], fn (array $s) => $s['aktiv']));
            if ($art === 'einschalten') {
                $fehlt = array_diff(array_map('strval', array_keys($alle[$modul]['abhaengigkeiten'] ?? [])), $aktiv);
                if ($fehlt !== []) {
                    throw new Problem(409, 'modul.abhaengigkeit', 'Erst einschalten: ' . implode(', ', $fehlt));
                }
            } else {
                if (!in_array($modul, $aktiv, true)) {
                    throw new Problem(409, 'modul.nicht_aktiv', 'Das Modul ist dort nicht aktiv');
                }
                $braucht = array_filter($aktiv, fn (string $n) => isset($alle[$n]) && array_key_exists($modul, $alle[$n]['abhaengigkeiten'] ?? []));
                if ($braucht !== []) {
                    throw new Problem(409, 'modul.wird_gebraucht', 'Erst ausschalten: ' . implode(', ', $braucht));
                }
            }
            $angaben += ['modul' => $modul, 'buch' => $buch];
        }
        $id = (string) $a->verwaltung()->fetchOne('SELECT verwaltung.modul_auftrag_anlegen(?, ?, ?::jsonb)', [$ktx->mandant, self::ARTEN[$art], json_encode($angaben)]);
        return Antwort::json(['id' => $id, 'art' => $art, 'zustand' => 'offen'], 202);
    }
}
