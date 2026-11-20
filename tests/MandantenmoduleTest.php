<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

use Kumero\Api\V1\Dienste;
use Kumero\Plattform\Anfrage;
use Kumero\Plattform\Datenbank;
use Kumero\Plattform\Identitaet;
use Kumero\Plattform\Kernel;
use Kumero\Plattform\Konfiguration;
use Kumero\Plattform\Menue;
use Kumero\Plattform\Module;
use Kumero\Tests\Hilfe\Umgebung;
use Kumero\Tests\Hilfe\Zugaenge;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module, Rechte und Rollen am Mandanten (PERSONAL.md Abschnitt 2, F135): Ein Modul der Ebene `mandant` wird ohne Buch
 * aktiviert, bringt Rechte, Bausteine und Rollen in die Datenbank des Mandanten, seine Pfade liegen über den Büchern,
 * der Administrator weist die Rollen zu und hat selbst keines der Rechte, das Protokoll steht in der Kette des
 * Mandanten, und ein Modul eines Buchs bekommt Auskunft nur als ID und Anzeigename. Geprüft mit einem Prüfmodul aus
 * tests/fixtures – nur erfundene Daten.
 */
final class MandantenmoduleTest extends Rahmen
{
    use Zugaenge;

    private const ORIGIN = Umgebung::ORIGIN;
    private const MANDANT = 'kumero_modulpraxis';
    private const ZWEITER = 'kumero_zweitpraxis';
    private const P = '/m/modulpraxis/b/haupt/api/v1';
    private const M = '/m/modulpraxis/api/v1';

    private Umgebung $u;
    private ?string $cookie = null;
    private int $schluessel = 0;

    public function vorher(): void
    {
        putenv('KUMERO_TEST=1');
        Module::fuerTestsAnmelden('probe', __DIR__ . '/fixtures/module/probe');
        $this->u = new Umgebung([self::MANDANT, self::ZWEITER]);
        $this->u->aufbauen();
    }

    public function nachher(): void
    {
        $this->u->abbauen();
        Module::fuerTestsAnmelden('probe', null);
        putenv('KUMERO_TEST');
    }

    /** @return array{0: Response, 1: mixed} */
    private function http(string $methode, string $pfad, ?array $json = null): array
    {
        $k = Konfiguration::laden();
        $server = ['HTTPS' => 'on', 'HTTP_HOST' => 'kumero.test', 'HTTP_ORIGIN' => self::ORIGIN];
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        if ($methode !== 'GET' && str_starts_with($pfad, '/m/')) {
            $server['HTTP_IDEMPOTENCY_KEY'] = 'test-' . (++$this->schluessel) . '-' . bin2hex(random_bytes(4));
        }
        $r = Request::create('https://kumero.test' . $pfad, $methode, [], $this->cookie ? [Identitaet::COOKIE => $this->cookie] : [], [], $server,
            $json === null ? null : json_encode($json === [] ? new \stdClass() : $json));
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
        $this->gleich($erwartet, $erg[0]->getStatusCode(), $was . ' – ' . substr((string) $erg[0]->getContent(), 0, 300));
        return $erg[1];
    }

    private function problem(int $status, string $code, array $erg, string $was): void
    {
        $this->gleich([$status, $code], [$erg[0]->getStatusCode(), $erg[1]['code'] ?? null], $was . ' – ' . substr((string) $erg[0]->getContent(), 0, 300));
    }

    /** @return list<string> */
    private function sql(string $abfrage, string $datenbank = self::MANDANT): array
    {
        $aus = Umgebung::sh(sprintf('psql -X -q -At -v ON_ERROR_STOP=1 -h %s -U %s -d %s -c %s', escapeshellarg((string) getenv('KUMERO_TEST_PGHOST')),
            escapeshellarg((string) getenv('KUMERO_TEST_PGUSER')), $datenbank, escapeshellarg($abfrage)));
        return $aus === '' ? [] : explode("\n", $aus);
    }

    /** @return array<string, string> Eintrag-ID → Pfad */
    private static function eintraege(array $menue): array
    {
        return array_column(array_merge([], ...array_column($menue['bereiche'], 'eintraege')), 'pfad', 'id');
    }

    public function testModulAmMandanten(): void
    {
        $m = self::M;
        $p = self::P;
        $adamPk = $this->passkeyEinrichten($this->mandantAnlegen('modulpraxis', 'Modulpraxis'));
        [$hanna, $pkHanna] = $this->nutzer('modulpraxis', 'Hanna', 'Heiter', ['Buchhalter']);
        $this->mandantAnlegen('zweitpraxis', 'Zweitpraxis');

        // vor dem Aktivieren: kein Modul, keine Rollen, kein Pfad
        $this->anmelden($adamPk);
        $this->problem(404, 'modul.nicht_aktiv', $this->http('GET', "$m/probe/eintraege"), 'nicht aktiv: der Pfad antwortet nicht');
        $this->gleich(['zuweisungen' => [], 'rollen' => []], $this->status(200, $this->http('GET', "$m/zuweisungen"), 'Zuweisungen am Mandanten'), 'ohne Modul keine Rollen am Mandanten');
        $adam = $this->status(200, $this->http('GET', "$m/nutzer"), 'Nutzer')['ich'];

        // aktivieren: ohne Buch, in die Datenbank des Mandanten
        $this->ist(str_contains($this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'probe', 'mandant' => 'modulpraxis', 'buch' => 'haupt'], true), 'ohne Buch'),
            'ein Modul am Mandanten wird nicht in einem Buch aktiviert');
        $aus = $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'probe', 'mandant' => 'modulpraxis']);
        foreach (['Mandant modulpraxis:', 'Rolle Probe-Leser angelegt', 'Rolle Probe-Schreiber angelegt', 'Rolle Probe-Leser: Probe ansehen',
                  'Rolle Buchhalter gibt es hier nicht', 'Modul probe 0.1.0 ist aktiv.'] as $erwartet) {
            $this->ist(str_contains($aus, $erwartet), "Ausgabe nennt „{$erwartet}“ – $aus");
        }
        $this->gleich(['probe|0.1.0|t'], $this->sql('SELECT name, version, aktiv FROM mandant.modul'), 'am Mandanten registriert');
        $this->gleich(['probe.eintrag.schreiben|t|t', 'probe.eintrag.sehen|t|f'], $this->sql('SELECT code, personendaten, schreibend FROM mandant.recht ORDER BY code'), 'Rechte mit ihren Merkmalen');
        $this->gleich(['Probe-Leser|Probe ansehen', 'Probe-Schreiber|Probe führen'],
            $this->sql('SELECT r.name, b.name FROM mandant.rolle r JOIN mandant.rolle_baustein rb ON rb.rolle = r.id JOIN mandant.baustein b ON b.id = rb.baustein ORDER BY 1, 2'), 'Rollen mit Bausteinen');
        $this->gleich([], $this->sql("SELECT name FROM kern.modul WHERE name = 'probe'", self::MANDANT . '__haupt'), 'im Buch steht davon nichts');
        $this->gleich([], $this->sql("SELECT name FROM kern.rolle WHERE name LIKE 'Probe%'", self::MANDANT . '__haupt'), 'auch keine Rolle');
        // zweimal aktivieren ändert nichts
        $aus = $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'probe', 'mandant' => 'modulpraxis']);
        $this->ist(!str_contains($aus, 'angelegt') && !str_contains($aus, 'Rolle Probe-Leser:'), "erneutes Aktivieren legt nichts doppelt an – $aus");
        $this->ist(str_contains($this->u->cli(['command' => 'modul:liste']), 'modulpraxis (Mandant)'), 'modul:liste zeigt den Stand je Mandant');
        // ein Modul eines Buchs lässt sich nicht am Mandanten aktivieren und umgekehrt
        $this->wirft(\RuntimeException::class, fn () => Module::aktivieren('lager', self::MANDANT, (new Datenbank(Konfiguration::laden()))->verbindung(self::MANDANT), 'system:test'),
            'Buchmodul in der Datenbank des Mandanten', 'gehört zu einem Buch');
        $this->wirft(\RuntimeException::class, fn () => Module::aktivieren('probe', self::MANDANT . '__haupt', (new Datenbank(Konfiguration::laden()))->verbindung(self::MANDANT . '__haupt'), 'system:test'),
            'Mandantenmodul in der Datenbank eines Buchs', 'ohne Buch');

        // Der Administrator weist zu und hat selbst keines der Rechte (wie F43)
        $this->problem(403, 'recht.fehlt', $this->http('GET', "$m/probe/eintraege"), 'der Administrator sieht nichts vom Modul');
        $z = $this->status(200, $this->http('GET', "$m/zuweisungen"), 'Zuweisungen');
        $this->gleich(['Probe-Leser', 'Probe-Schreiber'], array_column($z['rollen'], 'name'), 'Rollen am Mandanten zur Auswahl');
        $this->status(400, $this->http('POST', "$m/zuweisungen", ['nutzer' => $hanna, 'rolle' => 'Buchhalter']), 'eine Rolle des Buchs gibt es am Mandanten nicht');
        $this->status(400, $this->http('POST', "$m/zuweisungen", ['nutzer' => '00000000-0000-4000-8000-000000000000', 'rolle' => 'Probe-Leser']), 'unbekannter Nutzer');
        $leser = $this->status(201, $this->http('POST', "$m/zuweisungen", ['nutzer' => $hanna, 'rolle' => 'Probe-Leser']), 'Rolle zugewiesen')['id'];
        $this->problem(409, 'zuweisung.vorhanden', $this->http('POST', "$m/zuweisungen", ['nutzer' => $hanna, 'rolle' => 'Probe-Leser']), 'nicht doppelt');
        $z = $this->status(200, $this->http('GET', "$m/zuweisungen"), 'Zuweisungen');
        $this->gleich([[$hanna, 'Probe-Leser', null]], array_map(fn (array $x) => [$x['nutzer'], $x['rolle'], $x['gueltig_bis']], $z['zuweisungen']), 'steht in der Liste');
        $this->ist(!isset(self::eintraege($this->status(200, $this->http('GET', "$m/oberflaeche/menue"), 'Menü'))['probe.uebersicht']), 'Administrator: kein Menüpunkt des Moduls');

        // Wer die Rolle hat, arbeitet – auf der Ebene Mandant, ohne Buch
        $this->anmelden($pkHanna);
        $e = $this->status(200, $this->http('GET', "$m/probe/eintraege"), 'mit Rolle lesbar');
        $this->gleich([null, 'modulpraxis', true], [$e['buch'], $e['mandant'], $e['schema']], 'Kontext ohne Buch, Datenbank des Mandanten');
        $this->problem(403, 'recht.fehlt', $this->http('POST', "$m/probe/eintraege", []), 'lesen ist nicht schreiben');
        $this->problem(403, 'recht.fehlt', $this->http('GET', "$m/zuweisungen"), 'Rollen weist nur der Administrator zu');
        $this->problem(403, 'recht.fehlt', $this->http('POST', "$m/zuweisungen", ['nutzer' => $hanna, 'rolle' => 'Probe-Schreiber']), 'niemand gibt sich selbst eine Rolle');
        $this->status(404, $this->http('GET', "$p/probe/eintraege"), 'im Buch gibt es den Pfad nicht');
        // Menü: Bereich des Mandanten, Adresse ohne Buch – auch wenn ein Buch gewählt ist
        foreach (["$m/oberflaeche/menue" => 'ohne Buch', "$p/oberflaeche/menue" => 'im Buch'] as $pfad => $wo) {
            $menue = $this->status(200, $this->http('GET', $pfad), "Menü $wo");
            $this->gleich('#/m/modulpraxis/probe/uebersicht', self::eintraege($menue)['probe.uebersicht'] ?? null, "Menüpunkt $wo zeigt auf den Mandanten");
            $bereich = array_values(array_filter($menue['bereiche'], fn (array $b) => $b['id'] === 'personal'))[0] ?? null;
            $this->gleich(['Personal', 'Probe', '/m/modulpraxis/api/v1/probe/zaehler'], [$bereich['titel'] ?? null, $bereich['eintraege'][0]['titel'] ?? null, $bereich['eintraege'][0]['zaehler'] ?? null], "Bereich, Titel und Zähler $wo");
            $this->gleich('/module/probe/0.1.0/uebersicht.js', $menue['seiten']['probe/uebersicht']['url'] ?? null, "Seite des Moduls $wo");
            $this->ist(in_array('probe.eintrag.sehen', $menue['rechte_mandant'], true), "Recht steht bei den Rechten $wo");
        }
        $this->gleich(['start', 'mandant', 'personal'], array_column($this->status(200, $this->http('GET', "$m/oberflaeche/menue"), 'Menü')['bereiche'], 'id'), 'Reihenfolge: Mandant vor Personal, ohne Buch keine Bereiche des Buchs');

        // Schreiben mit der zweiten Rolle; das Protokoll steht in der Kette des Mandanten
        $this->anmelden($adamPk);
        $bis = date('Y-m-d', strtotime('+10 days'));
        $schreiber = $this->status(201, $this->http('POST', "$m/zuweisungen", ['nutzer' => $hanna, 'rolle' => 'Probe-Schreiber', 'bis' => $bis]), 'befristet zugewiesen')['id'];
        $this->status(400, $this->http('POST', "$m/zuweisungen", ['nutzer' => $adam, 'rolle' => 'Probe-Schreiber', 'bis' => '2020-01-01']), 'Ende in der Vergangenheit');
        $this->anmelden($pkHanna);
        $nr = $this->status(201, $this->http('POST', "$m/probe/eintraege", []), 'mit der zweiten Rolle schreibbar')['protokoll'];
        $this->gleich(["probe.eintrag_angelegt|nutzer:$hanna|{\"eintrag\": \"e1\"}"], $this->sql("SELECT typ, subjekt, ids FROM mandant.protokoll WHERE nr = $nr"), 'Protokolleintrag des Moduls in der Kette des Mandanten');
        $this->gleich([], $this->sql("SELECT nr FROM kern.protokoll WHERE typ LIKE 'probe.%'", self::MANDANT . '__haupt'), 'nicht in der Kette eines Buchs');
        $typen = $this->sql("SELECT DISTINCT typ FROM mandant.protokoll WHERE typ IN ('modul.registriert','recht.angemeldet','baustein.gesetzt','rolle.geaendert','zuweisung.geaendert') ORDER BY 1");
        $this->gleich(['baustein.gesetzt', 'modul.registriert', 'recht.angemeldet', 'rolle.geaendert', 'zuweisung.geaendert'], $typen, 'Registrierung, Rechte, Rollen und Zuweisungen sind protokolliert');
        $this->gleich([], $this->sql('SELECT nr, fehler FROM mandant.kette_pruefen()'), 'die Kette des Mandanten stimmt');
        $this->wirft(\RuntimeException::class, fn () => $this->sql("SET ROLE kumero_app; SELECT mandant.modul_protokoll('system:test', 'probe', 'lager.fremd', '{}'::jsonb)"), 'fremder Ereignistyp', 'muss mit probe.');
        $this->wirft(\RuntimeException::class, fn () => $this->sql("SET ROLE kumero_app; INSERT INTO mandant.zuweisung (subjekt, rolle) SELECT 'nutzer:x', id FROM mandant.rolle LIMIT 1"), 'Tabellen sind nur über Funktionen schreibbar');

        // Rolle beenden: sofort ohne Recht
        $this->anmelden($adamPk);
        $this->status(204, $this->http('DELETE', "$m/zuweisungen/$schreiber"), 'Rolle beendet');
        $this->status(404, $this->http('DELETE', "$m/zuweisungen/00000000-0000-4000-8000-000000000000"), 'unbekannte Zuweisung');
        $this->anmelden($pkHanna);
        $this->problem(403, 'recht.fehlt', $this->http('POST', "$m/probe/eintraege", []), 'nach dem Ende nicht mehr schreibbar');
        $this->status(200, $this->http('GET', "$m/probe/eintraege"), 'die andere Rolle gilt weiter');
        // Ein gesperrter Nutzer hat am Mandanten keine Rechte
        $this->gleich(['1'], $this->sql("SELECT count(*) FROM mandant.rechte_des_subjekts('nutzer:$hanna')"), 'aktiv: ein Recht');
        $this->anmelden($adamPk);
        $this->status(204, $this->http('PATCH', "$m/nutzer/$hanna", ['aktiv' => false]), 'gesperrt');
        $this->gleich(['0'], $this->sql("SELECT count(*) FROM mandant.rechte_des_subjekts('nutzer:$hanna')"), 'gesperrt: kein Recht');
        $this->status(204, $this->http('PATCH', "$m/nutzer/$hanna", ['aktiv' => true]), 'entsperrt');

        // Der andere Mandant hat das Modul nicht
        $this->gleich([], $this->sql('SELECT name FROM mandant.modul', self::ZWEITER), 'je Mandant aktiviert');

        // Auskunft an Module eines Buchs: nur ID und Anzeigename, gleich was das Modul liefert
        $k = Konfiguration::laden();
        $d = new Datenbank($k);
        $anfrage = fn (string $db) => new Anfrage(Request::create('/'), $k, $d, [], null, null, null, 'test', $d->verbindung($db));
        $this->gleich([['id' => 'p1', 'name' => 'Berta Beispiel'], ['id' => 'p2', 'name' => 'Curt Muster']], Dienste::auskunftAmMandanten($anfrage(self::MANDANT), 'probe', 'personen'), 'alle, ohne weitere Felder');
        $this->gleich([['id' => 'p2', 'name' => 'Curt Muster']], Dienste::auskunftAmMandanten($anfrage(self::MANDANT), 'probe', 'personen', ['p2']), 'nur die genannten');
        $this->gleich([], Dienste::auskunftAmMandanten($anfrage(self::MANDANT), 'probe', 'gehaelter'), 'unbekannter Gegenstand');
        $this->gleich([], Dienste::auskunftAmMandanten($anfrage(self::MANDANT), 'lager', 'personen'), 'ein Modul eines Buchs gibt keine Auskunft am Mandanten');
        $this->gleich([], Dienste::auskunftAmMandanten($anfrage(self::ZWEITER), 'probe', 'personen'), 'nicht aktiv: keine Auskunft');
    }

    public function testManifest(): void
    {
        $verz = __DIR__ . '/fixtures/module/probe/oeffentlich';
        $m = Module::manifest('probe');
        $this->gleich(['mandant', 'buch'], [Module::ebene($m), Module::ebene(Module::manifest('lager'))], 'Ebene aus dem Manifest; ohne Angabe Buch');
        $falsch = $m;
        $falsch['oberflaeche']['menue'][0]['bereich'] = 'buchen';
        $this->wirft(\RuntimeException::class, fn () => Menue::pruefen($falsch, [], $verz), 'Modul am Mandanten mit Eintrag im Bereich eines Buchs', 'passt nicht zur Ebene');
        $falsch = $m;
        $falsch['ebene'] = 'buch';
        $this->wirft(\RuntimeException::class, fn () => Menue::pruefen($falsch, [], $verz), 'Modul eines Buchs mit Eintrag im Bereich des Mandanten', 'passt nicht zur Ebene');
        $falsch = $m;
        $falsch['oberflaeche']['menue'][0]['bereich'] = 'mandant';
        $this->wirft(\RuntimeException::class, fn () => Menue::pruefen($falsch, [], $verz), 'der Bereich „mandant“ bleibt dem Kern', 'dem Kern vorbehalten');
        Menue::pruefen($m, [], $verz);
        $this->ist(true, 'das Prüfmodul selbst ist gültig');
        putenv('KUMERO_TEST');
        $this->wirft(\LogicException::class, fn () => Module::fuerTestsAnmelden('fremd', '/tmp'), 'außerhalb von Tests gibt es nur die Module des Releases');
        putenv('KUMERO_TEST=1');
    }
}
