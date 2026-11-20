<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Plattform;

use Kumero\Api\V1\Kontext;
use Kumero\Api\V1\MenueUnterpunkte;

/**
 * Menü der Weboberfläche aus den Manifesten (OBERFLAECHE.md, F80).
 *
 * - Einträge stehen deklarativ im Abschnitt `oberflaeche` von modul.json (N1).
 * - Feste Bereiche (N2); start und verwaltung sind systemweit, die übrigen gelten im Mandanten.
 * - Sichtbar ist ein Eintrag, wenn das Modul aktiv ist und das genannte Recht in den Rollen des
 *   Subjekts steckt (N3). Systemweit gibt es nur `angemeldet` und `verwaltung.verwalter`.
 * - Wechselnde Unterpunkte über den Erweiterungspunkt menue_unterpunkte (N3a), vom Kern gefiltert.
 * - Ausblenden ist kein Schutz: jeder REST-Endpunkt prüft sein Recht selbst.
 */
final class Menue
{
    public const BEREICHE = ['start', 'mandant', 'buchen', 'stammdaten', 'auswertungen', 'abschluss', 'einstellungen', 'verwaltung'];
    public const SYSTEMWEIT = ['start', 'verwaltung'];
    public const SYSTEMRECHTE = ['angemeldet', 'verwaltung.verwalter'];
    public const TIEFE = 3;

    /** Einträge und Seiten des Kerns im selben Format wie ein Modul (OBERFLAECHE.md 3). */
    public const KERN = [
        'name' => 'kern',
        'rechte' => [],
        'oberflaeche' => [
            'menue' => [
                ['id' => 'kern.start', 'bereich' => 'start', 'titel' => 'menue.start', 'ziel' => 'start', 'symbol' => 'symbole/start.svg', 'reihenfolge' => 10, 'recht' => 'angemeldet'],
                ['id' => 'kern.konten', 'bereich' => 'stammdaten', 'titel' => 'menue.konten', 'ziel' => 'konten', 'symbol' => 'symbole/konten.svg', 'reihenfolge' => 10, 'recht' => 'kern.stammdaten.sehen'],
                // Bereich „mandant“ (BUECHER.md Abschnitt 11): Seiten des Mandanten, nicht eines Buchs – nur der Kern hängt sich hier ein
                ['id' => 'kern.buecher', 'bereich' => 'mandant', 'titel' => 'menue.buecher', 'ziel' => 'buecher', 'symbol' => 'symbole/buecher.svg', 'reihenfolge' => 10, 'recht' => 'angemeldet'],
                ['id' => 'kern.nutzer', 'bereich' => 'mandant', 'titel' => 'menue.nutzer', 'ziel' => 'nutzer', 'symbol' => 'symbole/nutzer.svg', 'reihenfolge' => 20, 'recht' => 'mandant.administrator'],
                ['id' => 'kern.mandanten', 'bereich' => 'verwaltung', 'titel' => 'menue.mandanten', 'ziel' => 'mandanten', 'symbol' => 'symbole/mandanten.svg', 'reihenfolge' => 5, 'recht' => 'verwaltung.verwalter'],
            ],
            'seiten' => ['start' => 'start.js', 'konten' => 'konten.js', 'profil' => 'profil.js', 'mandanten' => 'mandanten.js', 'nutzer' => 'nutzer.js', 'buecher' => 'buecher.js'],
        ],
    ];

    /** @var array<string, array<string, string>> Textdateien je Modul und Sprache */
    private array $texte = [];

    /**
     * @param array<string, array<string, mixed>> $manifeste Name → Manifest der installierten Module
     * @param list<string> $aktiv im Mandanten aktive Module (leer ohne Mandant)
     * @param list<string> $rechteSystem systemweite Rechte des Subjekts
     * @param list<string> $rechteMandant Rechte aus den Rollen im Mandanten
     * @param \Closure(string): ?object $einstieg Name → Einstiegsobjekt des Moduls
     */
    public function __construct(
        private readonly array $manifeste,
        private readonly array $aktiv,
        private readonly ?string $mandant,
        private readonly array $rechteSystem,
        private readonly array $rechteMandant,
        private readonly string $sprache,
        private readonly ?Kontext $kontextMandant,
        private readonly Kontext $kontextSystem,
        private readonly \Closure $einstieg,
        private readonly string $kernVersion,
        /** Kennung des gewählten Buchs; ohne Buch gibt es nur die Einträge der Installation und des Mandanten */
        private readonly ?string $buch = null,
    ) {
    }

    /** @return array{bereiche: list<array<string, mixed>>, seiten: array<string, array<string, ?string>>} */
    public function bauen(): array
    {
        $quellen = ['kern' => self::KERN];
        foreach ($this->manifeste as $name => $m) {
            $quellen[$name] = $m;
        }
        $roh = [];
        $seiten = [];
        foreach ($quellen as $name => $m) {
            $o = $m['oberflaeche'] ?? [];
            $imMandant = $name === 'kern' || in_array($name, $this->aktiv, true);
            $sichtbar = false;
            foreach ($o['menue'] ?? [] as $e) {
                $system = in_array($e['bereich'], self::SYSTEMWEIT, true);
                $imBuch = !$system && $e['bereich'] !== 'mandant';
                if (!$system && ($this->mandant === null || !$imMandant) || $imBuch && $this->buch === null) {
                    continue;
                }
                if (!$this->darf($e['recht'], $system)) {
                    continue;
                }
                $sichtbar = true;
                $eintrag = $this->eintrag($name, $e, $system);
                if (!empty($e['unterpunkte'])) {
                    $eintrag['unter'] = $this->unterpunkte($name, $e, $system);
                }
                $roh[$e['id']] = ['e' => $eintrag, 'bereich' => $e['bereich'], 'eltern' => $e['eltern'] ?? null,
                                  'sort' => [(int) ($e['reihenfolge'] ?? 100), $e['id']]];
            }
            if ($sichtbar || $name === 'kern') {
                foreach ($o['seiten'] ?? [] as $ziel => $datei) {
                    $seiten["$name/$ziel"] = ['url' => $this->dateiUrl($name, $m, $datei), 'texte' => $this->texteUrl($name, $m)];
                }
            }
        }

        // Baum: Kinder unter Eltern; ein Kind ohne sichtbaren Eltern-Eintrag entfällt
        uasort($roh, fn ($a, $b) => $a['sort'] <=> $b['sort']);
        $kinder = [];
        foreach ($roh as $id => $r) {
            if ($r['eltern'] !== null) {
                $kinder[$r['eltern']][] = $id;
            }
        }
        $baum = function (string $id, int $tiefe) use (&$baum, $roh, $kinder): array {
            $e = $roh[$id]['e'];
            $unter = [];
            if ($tiefe < self::TIEFE) {
                foreach ($kinder[$id] ?? [] as $k) {
                    $unter[] = $baum($k, $tiefe + 1);
                }
            }
            $e['unter'] = array_merge($unter, $e['unter'] ?? []);
            return $e;
        };
        $bereiche = [];
        foreach (self::BEREICHE as $b) {
            $liste = [];
            foreach ($roh as $id => $r) {
                if ($r['bereich'] === $b && $r['eltern'] === null) {
                    $liste[] = $baum($id, 1);
                }
            }
            if ($liste !== []) {
                $bereiche[] = ['id' => $b, 'titel' => $this->text('kern', 'bereich.' . $b), 'eintraege' => $liste];
            }
        }
        ksort($seiten);
        return ['bereiche' => $bereiche, 'seiten' => $seiten];
    }

    private function darf(string $recht, bool $system): bool
    {
        return in_array($recht, $system ? $this->rechteSystem : array_merge(['angemeldet'], $this->rechteMandant), true);
    }

    /** @return array<string, mixed> */
    private function eintrag(string $modul, array $e, bool $system, bool $fertigerTitel = false): array
    {
        $imBuch = !$system && ($e['bereich'] ?? '') !== 'mandant' && $this->buch !== null;
        $basis = $system ? '#/system' : '#/m/' . $this->mandant . ($imBuch ? '/b/' . $this->buch : '');
        $m = $modul === 'kern' ? self::KERN : $this->manifeste[$modul];
        return [
            'id' => $e['id'],
            'titel' => $fertigerTitel ? (string) $e['titel'] : $this->text($modul, (string) $e['titel']),
            'pfad' => "$basis/$modul/" . $e['ziel'],
            'symbol' => is_string($e['symbol'] ?? null) ? $this->dateiUrl($modul, $m, $e['symbol']) : null,
            'zaehler' => isset($e['zaehler']) ? ($system ? '' : '/m/' . $this->mandant . ($imBuch ? '/b/' . $this->buch : '')) . "/api/v1/$modul" . $e['zaehler'] : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function unterpunkte(string $modul, array $e, bool $system): array
    {
        $o = ($this->einstieg)($modul);
        if (!$o instanceof MenueUnterpunkte) {
            return [];
        }
        $ktx = $system ? $this->kontextSystem : $this->kontextMandant;
        try {
            $liste = $o->menueUnterpunkte($ktx, $e['id']);
        } catch (\Throwable $f) {
            error_log(sprintf('kumero: menue_unterpunkte von %s (%s) fehlgeschlagen: %s', $modul, $e['id'], $f::class));
            return [];
        }
        $seiten = array_keys($this->manifeste[$modul]['oberflaeche']['seiten'] ?? []);
        $erg = [];
        foreach ($liste as $u) {
            $ziel = (string) ($u['ziel'] ?? '');
            if (!is_array($u) || !str_starts_with((string) ($u['id'] ?? ''), "$modul.") || !is_string($u['titel'] ?? null)
                || !in_array(strtok($ziel, '?'), $seiten, true) || !is_string($u['recht'] ?? null)
                || !$this->rechtGehoert($modul, $u['recht'], $system)) {
                error_log(sprintf('kumero: ungültiger Unterpunkt von %s unter %s übergangen', $modul, $e['id']));
                continue;
            }
            if ($this->darf($u['recht'], $system)) {
                // Wechselnde Unterpunkte ohne eigenes Symbol tragen das ihres Eintrags (F122)
                $u['symbol'] = $e['symbol'] ?? null;
                $u['bereich'] = $e['bereich'];
                $erg[] = ['sort' => [(int) ($u['reihenfolge'] ?? 100), $u['id']], 'e' => $this->eintrag($modul, $u, $system, true) + ['unter' => []]];
            }
        }
        usort($erg, fn ($a, $b) => $a['sort'] <=> $b['sort']);
        return array_column($erg, 'e');
    }

    private function rechtGehoert(string $modul, string $recht, bool $system): bool
    {
        if ($system) {
            return in_array($recht, self::SYSTEMRECHTE, true);
        }
        return $recht === 'angemeldet' || str_starts_with($recht, "$modul.") || str_starts_with($recht, 'kern.');
    }

    private function dateiUrl(string $modul, array $m, string $datei): string
    {
        return $modul === 'kern' ? "/kern/seiten/$datei?v=" . rawurlencode($this->kernVersion)
            : "/module/$modul/{$m['version']}/$datei";
    }

    private function texteUrl(string $modul, array $m): ?string
    {
        if ($modul === 'kern') {
            return '/kern/sprachen/' . $this->sprache . '.json?v=' . rawurlencode($this->kernVersion);
        }
        return isset($m['oberflaeche']['texte']) ? "/module/$modul/{$m['version']}/sprachen/{$this->sprache}.json" : null;
    }

    public function text(string $modul, string $schluessel): string
    {
        if (!isset($this->texte[$modul])) {
            $verz = $modul === 'kern' ? dirname(__DIR__, 2) . '/public/kern/sprachen' : Module::verzeichnis() . "/$modul/oeffentlich/sprachen";
            $this->texte[$modul] = [];
            foreach (array_unique(['de', $this->sprache]) as $s) {
                $datei = "$verz/$s.json";
                if (is_file($datei)) {
                    $this->texte[$modul] = array_merge($this->texte[$modul], (array) json_decode((string) file_get_contents($datei), true));
                }
            }
        }
        $w = $this->texte[$modul][$schluessel] ?? null;
        return is_string($w) ? $w : $schluessel;
    }

    /**
     * Prüft den Abschnitt `oberflaeche` eines Manifests (OBERFLAECHE.md 3); wirft bei Fehlern.
     *
     * @param array<string, mixed> $m Manifest
     * @param array<string, array<string, mixed>> $abhaengige Manifeste der Abhängigkeiten
     */
    public static function pruefen(array $m, array $abhaengige, string $oeffentlich): void
    {
        $name = $m['name'];
        $o = $m['oberflaeche'] ?? null;
        if ($o === null) {
            return;
        }
        $f = fn (string $t) => throw new \RuntimeException("Manifest von $name, oberflaeche: $t");
        if (!is_array($o)) {
            $f('muss ein Objekt sein');
        }
        $seiten = $o['seiten'] ?? [];
        foreach ($seiten as $ziel => $datei) {
            if (!preg_match('/^[a-z][a-z0-9-]{0,31}$/', (string) $ziel) || !is_string($datei)
                || !preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)*\.js$#', $datei) || !is_file("$oeffentlich/$datei")) {
                $f("Seite $ziel ungültig oder Datei fehlt");
            }
        }
        $eigeneRechte = array_column($m['rechte'] ?? [], 'code');
        $fremdeRechte = [];
        $fremdeEintraege = array_column(self::KERN['oberflaeche']['menue'], 'bereich', 'id');
        foreach ($abhaengige as $a) {
            $fremdeRechte = array_merge($fremdeRechte, array_column($a['rechte'] ?? [], 'code'));
            $fremdeEintraege += array_column($a['oberflaeche']['menue'] ?? [], 'bereich', 'id');
        }
        $eigene = [];
        foreach ($o['menue'] ?? [] as $e) {
            $id = (string) ($e['id'] ?? '');
            if (!preg_match('/^' . preg_quote($name, '/') . '\.[a-z0-9_.-]{1,64}$/', $id) || isset($eigene[$id])) {
                $f("Eintrag-ID $id fehlt das Modulpräfix oder ist doppelt");
            }
            if (!in_array($e['bereich'] ?? null, self::BEREICHE, true) || $e['bereich'] === 'mandant') {
                $f("Eintrag $id: Bereich unbekannt oder dem Kern vorbehalten");
            }
            $system = in_array($e['bereich'], self::SYSTEMWEIT, true);
            $recht = $e['recht'] ?? null;
            $ok = is_string($recht) && ($system ? in_array($recht, self::SYSTEMRECHTE, true)
                : ($recht === 'angemeldet' || in_array($recht, $eigeneRechte, true) || in_array($recht, $fremdeRechte, true) || str_starts_with($recht, 'kern.')));
            if (!$ok) {
                $f("Eintrag $id: Recht fehlt oder gehört nicht zum Modul, seinen Abhängigkeiten oder dem Kern" . ($system ? ' (systemweit nur ' . implode(', ', self::SYSTEMRECHTE) . ')' : ''));
            }
            if (!is_string($e['titel'] ?? null) || !isset($seiten[$e['ziel'] ?? ''])) {
                $f("Eintrag $id: titel fehlt oder ziel ist keine Seite des Moduls");
            }
            // Jeder Eintrag trägt ein Symbol (F122) – auch Unterpunkte
            if (!is_string($e['symbol'] ?? null) || !preg_match('#^[a-z0-9_/-]+\.svg$#', $e['symbol']) || !is_file("$oeffentlich/{$e['symbol']}")) {
                $f("Eintrag $id: Symbol fehlt, ist ungültig oder die Datei fehlt");
            }
            if (isset($e['zaehler']) && !preg_match('#^/[a-z0-9/_-]{1,100}$#', (string) $e['zaehler'])) {
                $f("Eintrag $id: zaehler muss ein relativer Pfad der eigenen API sein");
            }
            if (!empty($e['unterpunkte']) && empty($m['einstieg'])) {
                $f("Eintrag $id: unterpunkte braucht eine Einstiegsklasse (einstieg)");
            }
            $eigene[$id] = $e;
        }
        foreach ($eigene as $id => $e) {
            $eltern = $e['eltern'] ?? null;
            if ($eltern === null) {
                continue;
            }
            $bereich = $eigene[$eltern]['bereich'] ?? $fremdeEintraege[$eltern] ?? null;
            if ($bereich === null || $bereich !== $e['bereich']) {
                $f("Eintrag $id: eltern $eltern unbekannt, nicht aus einer Abhängigkeit oder in anderem Bereich");
            }
            $gesehen = [$id => true];
            for ($k = $eltern; isset($eigene[$k]); $k = $eigene[$k]['eltern'] ?? null) {
                if (isset($gesehen[$k])) {
                    $f("Eintrag $id: Kreis über eltern");
                }
                $gesehen[$k] = true;
            }
        }
        if (isset($o['texte']) && !is_dir("$oeffentlich/sprachen")) {
            $f('texte gesetzt, aber oeffentlich/sprachen fehlt');
        }
    }
}
