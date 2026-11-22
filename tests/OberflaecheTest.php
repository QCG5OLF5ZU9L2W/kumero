<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

use Kumero\Plattform\Datenbank;
use Kumero\Plattform\Identitaet;
use Kumero\Plattform\Kernel;
use Kumero\Plattform\Konfiguration;
use Kumero\Plattform\Menue;
use Kumero\Plattform\Webauthn\Base64Url;
use Kumero\Tests\Hilfe\Authentifikator;
use Kumero\Tests\Hilfe\Umgebung;
use Kumero\Tests\Hilfe\Zugaenge;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menü der Oberfläche (OBERFLAECHE.md, F80) und Kontenrahmen-Import über REST:
 * Entwurf hochladen, korrigieren, als Vorlage speichern, im Mandanten anwenden.
 * Sichtbarkeit nur über Rollen und das Verwalter-Kennzeichen. Nur synthetische Daten.
 */
final class OberflaecheTest extends Rahmen
{
    use Zugaenge;

    private const ORIGIN = Umgebung::ORIGIN;
    private const PDF = __DIR__ . '/fixtures/kontenrahmen/skr81-muster.pdf';
    private ?Umgebung $u = null;
    private ?string $cookie = null;

    public function nachher(): void
    {
        $this->u?->abbauen();
    }

    /** @return array{0: Response, 1: mixed} */
    private function http(string $methode, string $pfad, mixed $json = null, ?string $roh = null, ?string $typ = null): array
    {
        $k = Konfiguration::laden();
        $server = ['HTTPS' => 'on', 'HTTP_HOST' => 'kumero.test', 'HTTP_ORIGIN' => self::ORIGIN];
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
            $roh = json_encode($json);
        } elseif ($typ !== null) {
            $server['CONTENT_TYPE'] = $typ;
        }
        if ($methode !== 'GET' && str_starts_with($pfad, '/m/')) {
            $server['HTTP_IDEMPOTENCY_KEY'] = 'test-' . bin2hex(random_bytes(8));
        }
        $r = Request::create('https://kumero.test' . $pfad, $methode, [], $this->cookie ? [Identitaet::COOKIE => $this->cookie] : [], [], $server, $roh);
        $antwort = (new Kernel($k, new Datenbank($k)))->bearbeiten($r);
        foreach ($antwort->headers->getCookies() as $c) {
            if ($c->getName() === Identitaet::COOKIE) {
                $this->cookie = $c->isCleared() ? null : $c->getValue();
            }
        }
        return [$antwort, json_decode((string) $antwort->getContent(), true)];
    }

    private function status(int $erwartet, array $erg, string $was): mixed
    {
        $this->gleich($erwartet, $erg[0]->getStatusCode(), $was . ' – ' . substr((string) $erg[0]->getContent(), 0, 400));
        return $erg[1];
    }

    /** @return array<string, array<string, mixed>> Eintrag-ID → Eintrag (flach, mit Unterpunkten) */
    private static function eintraege(array $menue): array
    {
        $erg = [];
        $lauf = function (array $liste) use (&$lauf, &$erg): void {
            foreach ($liste as $e) {
                $erg[$e['id']] = $e;
                $lauf($e['unter'] ?? []);
            }
        };
        foreach ($menue['bereiche'] as $b) {
            $lauf($b['eintraege']);
        }
        return $erg;
    }

    public function testMenueUndImport(): void
    {
        $this->u = new Umgebung(['kumero_testpraxis']);
        $this->u->aufbauen();
        [, $pkVera] = $this->verwalter('Vera Verwalterin');
        $this->gleich(true, $this->anmelden($pkVera)['verwalter'], 'System-Verwalterin angemeldet');
        $this->ist(str_contains($this->u->cli(['command' => 'verwalter:liste']), 'Vera Verwalterin'), 'Verwalter in der Liste');

        // Systemweites Menü des Verwalters
        $menue = $this->status(200, $this->http('GET', '/api/v1/oberflaeche/menue'), 'Menü systemweit');
        $this->gleich(['start', 'verwaltung'], array_column($menue['bereiche'], 'id'), 'ohne Mandant nur Start und Verwaltung');
        $this->gleich('Verwaltung', $menue['bereiche'][1]['titel'], 'Bereichstitel übersetzt');
        $e = self::eintraege($menue);
        $this->gleich('Kontenrahmen importieren', $e['kontenrahmen.import']['titel'], 'Titel aus den Texten des Moduls');
        $this->gleich('#/system/kontenrahmen/import', $e['kontenrahmen.import']['pfad'], 'Pfad systemweit');
        $this->gleich('/module/kontenrahmen/0.2.0/symbole/import.svg', $e['kontenrahmen.import']['symbol'], 'Symbol aus dem Release mit Version im Pfad');
        $this->gleich([], $e['kontenrahmen.vorlagen']['unter'], 'noch keine Vorlagen als Unterpunkte');
        $this->ist(str_starts_with((string) $e['kern.mandanten']['symbol'], '/kern/seiten/symbole/mandanten.svg?v='), 'Symbol eines Eintrags des Kerns');
        $this->gleich([], array_keys(array_filter($e, fn ($x) => !is_string($x['symbol'] ?? null))), 'jeder Eintrag trägt ein Symbol (F122)');
        $this->gleich('/module/kontenrahmen/0.2.0/import.js', $menue['seiten']['kontenrahmen/import']['url'], 'Seite des Moduls');
        $this->ist(isset($menue['seiten']['kern/start']), 'Seiten des Kerns');
        $this->gleich(['angemeldet', 'verwaltung.verwalter'], $menue['rechte_system'], 'Systemrechte des Verwalters');

        // Entwurf hochladen
        $pdf = (string) file_get_contents(self::PDF);
        $this->gleich('anfrage.medientyp', $this->status(415, $this->http('POST', '/api/v1/kontenrahmen/entwuerfe', null, $pdf, 'image/png'), 'falscher Typ')['code'], '415');
        $this->status(400, $this->http('POST', '/api/v1/kontenrahmen/entwuerfe', null, 'kein pdf', 'application/pdf'), 'kein PDF');
        $this->status(400, $this->http('POST', '/api/v1/kontenrahmen/entwuerfe?regelwerk=skr99', null, $pdf, 'application/pdf'), 'Regelwerk unbekannt');
        $x = $this->status(201, $this->http('POST', '/api/v1/kontenrahmen/entwuerfe?regelwerk=skr03', null, $pdf, 'application/pdf'), 'SKR-81-PDF mit Regelwerk SKR 03');
        $this->gleich('rahmen', $x['hinweise'][0]['art'] ?? null, 'Hinweis: Rahmen passt nicht zum Regelwerk');
        $this->status(204, $this->http('DELETE', "/api/v1/kontenrahmen/entwuerfe/{$x['id']}"), 'Entwurf verworfen');
        $this->status(400, $this->http('POST', '/api/v1/kontenrahmen/entwuerfe', null, "1000;Kasse;aktiv\n", 'text/csv'), 'CSV ohne Regelwerk: automatisch geht nicht');
        $w = $this->status(201, $this->http('POST', '/api/v1/kontenrahmen/entwuerfe', null, $pdf, 'application/pdf'), 'Entwurf aus PDF, Regelwerk automatisch');
        $this->gleich('skr81', $w['regelwerk']['name'], 'Regelwerk aus der Kopfzeile erkannt');
        $this->gleich('SKR81, 2015, aus PDF', $w['name'], 'Name aus Rahmen und Fassung');
        $this->gleich(hash_file('sha256', self::PDF), $w['quelle']['datei_sha256'], 'nur der Hash der Datei');
        $this->gleich(1, $w['bericht']['offen'], 'ein offener Punkt (Klasse 5 ohne Gliederung)');
        $this->ist(!$w['bericht']['bereit'], 'noch nicht bereit');
        $id = $w['id'];
        $this->gleich(1, count($this->status(200, $this->http('GET', '/api/v1/kontenrahmen/entwuerfe'), 'Entwürfe')), 'ein Entwurf');
        $this->gleich('kontenrahmen.offen', $this->status(409, $this->http('POST', "/api/v1/kontenrahmen/entwuerfe/$id/vorlage"), 'mit offenen Punkten nicht speicherbar')['code'], '409');

        // Korrigieren
        $f = $this->status(400, $this->http('PUT', "/api/v1/kontenrahmen/entwuerfe/$id/korrekturen",
            ['korrekturen' => ['5100' => ['kontoart' => 'guthaben'], '0200' => ['farbe' => 'rot']]]), 'ungültige Korrekturen');
        $this->gleich(['korrekturen.0200', 'korrekturen.5100.kontoart'], (function () use ($f) { $x = array_column($f['fehler'], 'feld'); sort($x); return $x; })(), 'Fehler je Feld');
        $anzahl = $w['bericht']['konten'];
        $w = $this->status(200, $this->http('PUT', "/api/v1/kontenrahmen/entwuerfe/$id/korrekturen",
            ['korrekturen' => ['5100' => ['kontoart' => 'aufwand'], '0010' => ['weglassen' => true]]]), 'korrigiert');
        $this->gleich([0, true, $anzahl - 1], [$w['bericht']['offen'], $w['bericht']['bereit'], $w['bericht']['konten']], 'offen geklärt, ein Konto weggelassen');
        $k5100 = array_values(array_filter($w['konten'], fn ($x) => $x['nr'] === '5100'))[0];
        $this->gleich(['aufwand', 'hand'], [$k5100['kontoart'], $k5100['kontoart_herkunft']], 'Kontoart von Hand');
        $this->ist(in_array('0010', array_column($w['ausgelassen'], 'nr'), true), 'weggelassen erscheint unter ausgelassen');

        // Speichern
        $v = $this->status(201, $this->http('POST', "/api/v1/kontenrahmen/entwuerfe/$id/vorlage"), 'Vorlage gespeichert');
        $kennung = $v['kennung'];
        $this->status(404, $this->http('GET', "/api/v1/kontenrahmen/entwuerfe/$id"), 'Entwurf danach entfernt');
        $this->gleich([$kennung], array_column($this->status(200, $this->http('GET', '/api/v1/kontenrahmen/vorlagen'), 'Vorlagen'), 'kennung'), 'Vorlage gelistet');
        $voll = $this->status(200, $this->http('GET', "/api/v1/kontenrahmen/vorlagen/$kennung"), 'Vorlage lesen');
        $this->gleich($anzahl - 1, count($voll['konten']), 'Konten der Vorlage');
        $this->ist(isset($voll['quelle']['ergaenzung_sha256']), 'Korrekturen in der Quelle vermerkt');
        $e = self::eintraege($this->status(200, $this->http('GET', '/api/v1/oberflaeche/menue'), 'Menü'));
        $unter = $e['kontenrahmen.vorlagen']['unter'];
        $this->gleich(1, count($unter), 'Vorlage als Unterpunkt (Erweiterungspunkt menue_unterpunkte)');
        $this->gleich("#/system/kontenrahmen/vorlage?kennung=$kennung", $unter[0]['pfad'], 'Pfad des Unterpunkts');

        // Mandant ohne aktives Modul
        $aus = $this->mandantAnlegen('testpraxis', 'Testpraxis');
        $this->ist(str_contains($aus, '/app.html#registrierung=testpraxis.'), 'Registrierungslink führt zur Oberfläche und nennt den Mandanten');
        $p = '/m/testpraxis/b/haupt/api/v1';
        $this->gleich('mandant.unbekannt', $this->status(404, $this->http('GET', "$p/oberflaeche/menue"), 'Verwalterin im Mandanten')['code'], 'Die Verwalterin sieht keinen Mandanten von innen (U4)');
        // Im Mandanten arbeitet eine eigene Identität; Mandant wechseln heißt neu anmelden (U3)
        [$erika, $pkErika] = $this->nutzer('testpraxis', 'Erika', 'Test');
        $this->anmelden($pkErika);
        $this->gleich('recht.fehlt', $this->status(403, $this->http('GET', '/api/v1/kontenrahmen/vorlagen'), 'Nutzerin systemweit')['code'], 'Vorlagen pflegt nur die System-Verwalterin');
        $menue = $this->status(200, $this->http('GET', "$p/oberflaeche/menue"), 'Menü im Mandanten');
        $this->gleich(['start', 'mandant', 'stammdaten'], array_column($menue['bereiche'], 'id'), 'Bereiche im Buch: Start, Mandant (Bücher), Stammdaten – keine Verwaltung der Installation');
        $this->gleich(['haupt', ['kern.buecher'], '#/m/testpraxis/kern/buecher'], [$menue['buch'], array_column($menue['bereiche'][1]['eintraege'], 'id'), $menue['bereiche'][1]['eintraege'][0]['pfad']],
            'Bereich „Mandant“: Bücher für alle, „Nutzer und Rollen“ nur für Administratoren; Pfad ohne Buch');
        $ohne = $this->status(200, $this->http('GET', '/m/testpraxis/api/v1/oberflaeche/menue'), 'Menü des Mandanten, ohne Buch');
        $this->gleich([['start', 'mandant'], null], [array_column($ohne['bereiche'], 'id'), $ohne['buch']], 'ohne gewähltes Buch nur Start und Mandant');
        $this->gleich('buch.unbekannt', $this->status(404, $this->http('GET', '/m/testpraxis/b/fehlt/api/v1/oberflaeche/menue'), 'unbekanntes Buch')['code'], '404');
        $this->gleich(['angemeldet'], $menue['rechte_system'], 'Systemrechte einer Nutzerin');
        $e = self::eintraege($menue);
        $this->gleich('#/m/testpraxis/b/haupt/kern/konten', $e['kern.konten']['pfad'], 'Pfad im Buch');
        $this->gleich([], $e['kern.konten']['unter'], 'ohne aktives Modul keine Kontenrahmen-Einträge');
        $this->ist(!isset($menue['seiten']['kontenrahmen/anwenden']) || isset($e['kontenrahmen.import']), 'Seiten nur sichtbarer Module');
        // Seiten der Verwaltung gibt es nur für den, der ihren Menüeintrag sehen darf – auch nicht über die Adresse
        $this->gleich([false, false, true, true], [isset($menue['seiten']['kern/mandanten']), isset($menue['seiten']['kern/nutzer']),
            isset($menue['seiten']['kern/buecher']), isset($menue['seiten']['kern/profil'])], 'Nutzerin: keine Seite „Mandanten“, keine „Nutzer und Rollen“; Bücher und Profil schon');
        $this->gleich('modul.nicht_aktiv', $this->status(404, $this->http('GET', "$p/kontenrahmen/vorlagen"), 'Modul nicht aktiv')['code'], '404');

        // Modul aktivieren, anwenden
        $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'kontenrahmen', 'mandant' => 'testpraxis']);
        $e = self::eintraege($this->status(200, $this->http('GET', "$p/oberflaeche/menue"), 'Menü mit Modul'));
        $this->gleich(['kontenrahmen.anwenden', 'kontenrahmen.angewendet'], array_column($e['kern.konten']['unter'], 'id'), 'Unterpunkte unter Konten, sortiert');
        $this->gleich([$kennung], array_column($this->status(200, $this->http('GET', "$p/kontenrahmen/vorlagen"), 'Vorlagen im Mandanten'), 'kennung'), 'zur Auswahl');
        $this->status(400, $this->http('POST', "$p/kontenrahmen/anwenden", ['vorlage' => '../etc']), 'Kennung geprüft');
        $this->status(404, $this->http('POST', "$p/kontenrahmen/anwenden", ['vorlage' => 'gibt-es-nicht']), 'unbekannte Vorlage');
        $erg = $this->status(200, $this->http('POST', "$p/kontenrahmen/anwenden", ['vorlage' => $kennung]), 'angewendet');
        $this->gleich([$anzahl - 1, 0], [$erg['angelegt'], count($erg['konflikte'])], 'alle Konten angelegt');
        $this->gleich($anzahl - 1, count($this->status(200, $this->http('GET', "$p/konten"), 'Konten')), 'Konten im Mandanten');
        $ang = $this->status(200, $this->http('GET', "$p/kontenrahmen/angewendet"), 'Vermerk');
        $this->gleich(['nutzer:' . $erika, $voll['sha256']], [$ang[0]['angewendet_von'], $ang[0]['vorlage_sha256']], 'Vermerk mit Subjekt und Hash');

        // Leser: sieht den Vermerk, aber nicht „anwenden“; keine Verwaltung
        [, $pkLeo] = $this->nutzer('testpraxis', 'Leo', 'Leser', ['Leser'], -8);
        $this->anmelden($pkLeo);
        $menue = $this->status(200, $this->http('GET', "$p/oberflaeche/menue"), 'Menü des Lesers');
        $this->gleich(['start', 'mandant', 'stammdaten'], array_column($menue['bereiche'], 'id'), 'keine Verwaltung für Nutzer eines Mandanten');
        $this->gleich(['kontenrahmen.angewendet'], array_column(self::eintraege($menue)['kern.konten']['unter'], 'id'), 'nur was die Rolle erlaubt');
        $this->gleich('recht.fehlt', $this->status(403, $this->http('POST', "$p/kontenrahmen/anwenden", ['vorlage' => $kennung]), 'Leser wendet nicht an')['code'], 'Recht im Mandanten');
        $this->gleich('recht.fehlt', $this->status(403, $this->http('POST', '/api/v1/kontenrahmen/entwuerfe', null, $pdf, 'application/pdf'), 'Leser lädt nicht hoch')['code'], 'Systemrecht');
        $this->status(403, $this->http('GET', '/api/v1/kontenrahmen/vorlagen'), 'Leser sieht Vorlagen nicht systemweit');
    }

    public function testManifestPruefung(): void
    {
        $basis = ['name' => 'probe', 'rechte' => [['code' => 'probe.sehen']], 'einstieg' => null];
        $dir = sys_get_temp_dir() . '/kumero-probe-' . bin2hex(random_bytes(3));
        mkdir($dir);
        file_put_contents("$dir/seite.js", 'export function zeigen() {}');
        file_put_contents("$dir/zeichen.svg", '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $m = fn (array $e, array $extra = []) => $basis + ['oberflaeche' => ['menue' => $e, 'seiten' => ['seite' => 'seite.js']]] + $extra;
        $gut = ['id' => 'probe.liste', 'bereich' => 'auswertungen', 'titel' => 't', 'ziel' => 'seite', 'symbol' => 'zeichen.svg', 'recht' => 'probe.sehen'];
        try {
            Menue::pruefen($m([$gut]), [], $dir);
            $this->ist(true, 'gültiges Manifest');
            Menue::pruefen($m([['eltern' => 'kern.konten', 'bereich' => 'stammdaten'] + $gut]), [], $dir);
            $this->ist(true, 'unter einem Kerneintrag im selben Bereich');
            $this->wirft(\RuntimeException::class, fn () => Menue::pruefen($m([['id' => 'fremd.x'] + $gut]), [], $dir), 'ID ohne Modulpräfix', 'Modulpräfix');
            $this->wirft(\RuntimeException::class, fn () => Menue::pruefen($m([['bereich' => 'irgendwo'] + $gut]), [], $dir), 'Bereich fest', 'Bereich');
            $this->wirft(\RuntimeException::class, fn () => Menue::pruefen($m([['recht' => 'anderes.recht'] + $gut]), [], $dir), 'fremdes Recht', 'Recht');
            $this->wirft(\RuntimeException::class, fn () => Menue::pruefen($m([['bereich' => 'verwaltung'] + $gut]), [], $dir), 'systemweit nur Systemrechte', 'systemweit');
            $this->wirft(\RuntimeException::class, fn () => Menue::pruefen($m([['eltern' => 'kern.konten'] + $gut]), [], $dir), 'eltern in anderem Bereich', 'eltern');
            $this->wirft(\RuntimeException::class, fn () => Menue::pruefen($m([['eltern' => 'probe.b', 'id' => 'probe.a'] + $gut, ['eltern' => 'probe.a', 'id' => 'probe.b'] + $gut]), [], $dir),
                'Kreis', 'Kreis');
            $this->wirft(\RuntimeException::class, fn () => Menue::pruefen($m([['ziel' => 'fehlt'] + $gut]), [], $dir), 'Ziel muss Seite sein', 'ziel');
            $this->wirft(\RuntimeException::class, fn () => Menue::pruefen($m([array_diff_key($gut, ['symbol' => 1])]), [], $dir), 'kein Eintrag ohne Symbol (F122)', 'Symbol');
            $this->wirft(\RuntimeException::class, fn () => Menue::pruefen($m([['symbol' => 'fehlt.svg'] + $gut]), [], $dir), 'Symbol muss als Datei vorliegen', 'Symbol');
            $this->wirft(\RuntimeException::class, fn () => Menue::pruefen($m([['unterpunkte' => true] + $gut]), [], $dir), 'Unterpunkte brauchen Einstieg', 'einstieg');
            $this->wirft(\RuntimeException::class, fn () => Menue::pruefen($basis + ['oberflaeche' => ['seiten' => ['x' => '../x.js']]], [], $dir), 'Seitenpfad', 'Seite');
        } finally {
            unlink("$dir/seite.js");
            unlink("$dir/zeichen.svg");
            rmdir($dir);
        }
    }
}
