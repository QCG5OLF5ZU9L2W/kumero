<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

use Kumero\Plattform\Datenbank;
use Kumero\Plattform\Identitaet;
use Kumero\Plattform\Kernel;
use Kumero\Plattform\Konfiguration;
use Kumero\Plattform\Webauthn\Base64Url;
use Kumero\Tests\Hilfe\Authentifikator;
use Kumero\Tests\Hilfe\Umgebung;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Buchen über REST (0.7.0, F78, F83): Entwürfe mit ETag, Vorbereiten, Freigabe im Testbetrieb und
 * mit Passkey, Stapel, Storno, Journal, Salden, Kontoblatt, Rechte; dazu das Modul buchen mit den
 * Geschäftsvorfällen. Ende-zu-Ende gegen eine echte PostgreSQL, nur synthetische Daten.
 */
final class BuchenTest extends Rahmen
{
    private const ORIGIN = Umgebung::ORIGIN;
    private const MANDANT = 'kumero_buchpraxis';
    private const P = '/m/buchpraxis/api/v1';

    private Umgebung $u;
    private ?string $cookie = null;
    private int $schluessel = 0;

    public function vorher(): void
    {
        $this->u = new Umgebung([self::MANDANT]);
        $this->u->aufbauen();
    }

    public function nachher(): void
    {
        $this->u->abbauen();
    }

    /** @return array{0: Response, 1: mixed} */
    private function http(string $methode, string $pfad, ?array $json = null, array $kopf = []): array
    {
        $k = Konfiguration::laden();
        $server = ['HTTPS' => 'on', 'HTTP_HOST' => 'kumero.test', 'HTTP_ORIGIN' => self::ORIGIN];
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        if ($methode !== 'GET' && str_starts_with($pfad, '/m/')) {
            $server['HTTP_IDEMPOTENCY_KEY'] = 'test-' . (++$this->schluessel) . '-' . bin2hex(random_bytes(4));
        }
        foreach ($kopf as $n => $w) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $n))] = $w;
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
        $this->gleich($erwartet, $erg[0]->getStatusCode(), $was . ' – ' . $erg[0]->getContent());
        return $erg[1];
    }

    /** Legt einen Nutzer an, registriert seinen Passkey und meldet ihn an. @return array{0: string, 1: Authentifikator} */
    private function nutzer(string $name, bool $verwalter = false): array
    {
        $aus = $this->u->cli(['command' => 'nutzer:anlegen', 'name' => $name] + ($verwalter ? ['--verwalter' => true] : []));
        preg_match('/Nutzer angelegt: ([0-9a-f-]{36})/', $aus, $m);
        preg_match('/#registrierung=([A-Za-z0-9_-]+)/', $aus, $t);
        $pk = new Authentifikator('kumero.test', self::ORIGIN, -7);
        $this->cookie = null;
        $opt = $this->status(200, $this->http('POST', '/api/v1/registrierung/optionen', ['token' => $t[1]]), "Optionen $name");
        $this->status(201, $this->http('POST', '/api/v1/registrierung', ['token' => $t[1]] + $pk->registrieren(Base64Url::dekodieren($opt['challenge']))), "$name registriert");
        return [$m[1], $pk];
    }

    private function anmelden(Authentifikator $pk): void
    {
        $this->cookie = null;
        $opt = $this->status(200, $this->http('POST', '/api/v1/anmeldung/optionen'), 'Anmeldeoptionen');
        $this->status(200, $this->http('POST', '/api/v1/anmeldung', $pk->anmelden(Base64Url::dekodieren($opt['challenge']))), 'angemeldet');
    }

    /** @param list<array{0: string, 1: string, 2: string}> $zeilen Konto, Seite, Betrag */
    private static function inhalt(string $text, array $zeilen, string $datum = '2026-03-02', ?string $beleg = null): array
    {
        return ['buchungsart' => 'BANK', 'belegdatum' => $datum, 'buchungsdatum' => $datum, 'belegnummer' => $beleg, 'text' => $text,
                'zeilen' => array_map(fn ($z) => ['konto' => $z[0], 'seite' => $z[1], 'betrag' => $z[2]] + (isset($z[3]) ? ['tags' => $z[3]] : []), $zeilen)];
    }

    /** Legt an, bereitet vor und schreibt im Testbetrieb fest. @return array<string, mixed> Ergebnis von /freigaben */
    private function buchen(array $inhalt): array
    {
        $b = $this->status(201, $this->http('POST', self::P . '/buchungen', $inhalt + ['vorbereiten' => true]), 'angelegt und vorbereitet');
        return $this->status(200, $this->http('POST', self::P . '/freigaben', ['buchungen' => [$b['id']]]), 'festgeschrieben');
    }

    public function testBuchenUeberRest(): void
    {
        $p = self::P;
        [$erika, $pkErika] = $this->nutzer('Erika Test', true);
        $this->anmelden($pkErika);
        $this->u->cli(['command' => 'mandant:anlegen', 'kennung' => 'buchpraxis', 'name' => 'Buchpraxis']);
        $this->u->cli(['command' => 'zugang:gewaehren', 'nutzer' => $erika, 'mandant' => 'buchpraxis']);
        foreach ([['1000', 'Kasse', 'aktiv'], ['1200', 'Bank', 'aktiv'], ['4930', 'Bürobedarf', 'aufwand'], ['8100', 'Honorare', 'ertrag']] as [$nr, $bez, $art]) {
            $this->status(201, $this->http('POST', "$p/konten", ['nr' => $nr, 'bezeichnung' => $bez, 'kontoart' => $art]), "Konto $nr");
        }
        $this->status(201, $this->http('POST', "$p/geschaeftsjahre", ['kennung' => '2026', 'beginn' => '2026-01-01', 'ende' => '2026-12-31']), 'Geschäftsjahr');
        $this->status(201, $this->http('POST', "$p/nummernkreise", ['kuerzel' => 'ALLG', 'bezeichnung' => 'Allgemein']), 'Nummernkreis');
        $this->status(200, $this->http('PUT', "$p/nummernkreise/ALLG/jahre/2026", ['vorlage' => 'ALLG-{JJJJ}-{NR:5}', 'zuruecksetzen' => true]), 'Nummernkreis festgelegt');
        $this->status(201, $this->http('POST', "$p/buchungsarten", ['kuerzel' => 'BANK', 'bezeichnung' => 'Bank', 'nummernkreis' => 'ALLG']), 'Buchungsart');
        $this->status(201, $this->http('POST', "$p/tags", ['name' => 'Praxis']), 'Tag');

        // --- Entwurf: unvollständig erlaubt (F62), Form wird geprüft, ETag (F19) ---
        $this->gleich([], $this->status(200, $this->http('GET', "$p/buchungen"), 'leeres Journal')['eintraege'], 'noch keine Buchungen');
        $erg = $this->http('POST', "$p/buchungen", ['buchungsart' => 'BANK', 'text' => 'Druckerpapier (Muster)',
            'zeilen' => [['konto' => '4930', 'seite' => 'S', 'betrag' => '23.80', 'tags' => ['Praxis']]]]);
        $b = $this->status(201, $erg, 'unvollständiger Entwurf');
        $id = $b['id'];
        $etag = (string) $erg[0]->headers->get('ETag');
        $this->gleich(['entwurf', '23.80', 'manuell', 'nutzer:' . $erika, null], [$b['status'], $b['summe'], $b['herkunft'], $b['erfasst_von'], $b['journal_nr']], 'Entwurf im Ergebnis');
        $this->gleich(['Praxis'], $b['zeilen'][0]['tags'], 'Tags an der Zeile (F8)');
        $this->ist(preg_match('/^"[0-9a-f]{64}"$/', $etag) === 1, 'ETag am Entwurf');
        $this->gleich("$p/buchungen/$id", $erg[0]->headers->get('Location'), 'Location');
        $f = $this->status(400, $this->http('POST', "$p/buchungen", ['text' => 'x', 'unbekannt' => 1,
            'zeilen' => [['konto' => '4930', 'seite' => 'X', 'betrag' => '23.8'], ['betrag' => 12]]]), 'Form der Eingabe');
        $this->gleich(['unbekannt', 'zeilen[0].betrag', 'zeilen[0].seite', 'zeilen[1].betrag'],
            (function () use ($f) { $x = array_column($f['fehler'], 'feld'); sort($x); return $x; })(), 'Fehler je Feld mit Pfad');
        $this->gleich('KM002', $this->status(422, $this->http('POST', "$p/buchungen", ['zeilen' => [['konto' => '0815', 'seite' => 'S', 'betrag' => '1.00']]]),
            'unbekanntes Konto')['code'], 'Regel aus der Datenbank');
        $this->status(404, $this->http('GET', "$p/buchungen/00000000-0000-4000-8000-000000000000"), 'unbekannte Buchung');

        $voll = self::inhalt('Druckerpapier (Muster)', [['4930', 'S', '23.80', ['Praxis']], ['1200', 'H', '23.80']], '2026-03-02', 'RE-4711');
        $this->gleich('etag.fehlt', $this->status(428, $this->http('PUT', "$p/buchungen/$id", $voll), 'If-Match Pflicht')['code'], '428');
        $this->gleich('KM005', $this->status(412, $this->http('PUT', "$p/buchungen/$id", $voll, ['If-Match' => '"' . str_repeat('0', 64) . '"']), 'veralteter ETag')['code'], '412');
        $this->gleich('KM002', $this->status(422, $this->http('POST', "$p/buchungen/$id/vorbereiten", null, ['If-Match' => $etag]), 'Vorbereiten verlangt zwei Zeilen (F4)')['code'], 'KM002');
        $erg = $this->http('PUT', "$p/buchungen/$id", $voll, ['If-Match' => $etag]);
        $this->gleich('RE-4711', $this->status(200, $erg, 'Entwurf ersetzt')['belegnummer'], 'ersetzt');
        $etag2 = (string) $erg[0]->headers->get('ETag');
        $this->ist($etag2 !== $etag, 'neuer ETag nach Änderung');

        // --- Vorbereiten, Ändern setzt zurück (F66) ---
        $this->status(412, $this->http('POST', "$p/buchungen/$id/vorbereiten", null, ['If-Match' => $etag]), 'Vorbereiten mit altem ETag');
        $b = $this->status(200, $this->http('POST', "$p/buchungen/$id/vorbereiten", null, ['If-Match' => $etag2]), 'vorbereitet');
        $this->ist($b['status'] === 'freigabebereit' && preg_match('/^[0-9a-f]{64}$/', (string) $b['freigabe_hash']) === 1, 'freigabebereit mit Freigabe-Hash');
        $b = $this->status(200, $this->http('PUT', "$p/buchungen/$id", $voll, ['If-Match' => '*']), 'Ändern nach dem Vorbereiten');
        $this->gleich(['entwurf', null], [$b['status'], $b['freigabe_hash']], 'fällt auf Entwurf zurück (F66)');
        $this->gleich('KM003', $this->status(409, $this->http('POST', "$p/freigaben", ['buchungen' => [$id]]), 'Entwurf wird nicht festgeschrieben')['code'], 'erst vorbereiten');
        $b = $this->status(200, $this->http('POST', "$p/buchungen/$id/vorbereiten", null, ['If-Match' => '*']), 'erneut vorbereitet');
        $this->gleich('entwurf', $this->status(200, $this->http('POST', "$p/buchungen/$id/zuruecksetzen"), 'zurückgesetzt')['status'], 'wieder Entwurf');
        $b = $this->status(200, $this->http('POST', "$p/buchungen/$id/vorbereiten", null, ['If-Match' => '*']), 'vorbereitet');

        // --- Freigabe im Testbetrieb: Challenge zeigt den Inhalt, Assertion darf fehlen (F78 O1) ---
        $c = $this->status(201, $this->http('POST', "$p/freigaben/challenge", ['art' => 'festschreibung', 'buchungen' => [$id]]), 'Challenge');
        $this->gleich($b['freigabe_hash'], $c['inhalt_hash'], 'Inhalts-Hash = Freigabe-Hash der Buchung');
        $this->gleich(['Druckerpapier (Muster)', '23.80'], [$c['inhalt']['buchungen'][0]['inhalt']['text'], $c['inhalt']['buchungen'][0]['inhalt']['zeilen'][0]['betrag']],
            'anzuzeigender Inhalt (F38)');
        $this->gleich([[], 'required', 'kumero.test'], [$c['optionen']['allowCredentials'], $c['optionen']['userVerification'], $c['optionen']['rpId']], 'WebAuthn-Optionen');
        $this->status(501, $this->http('POST', "$p/freigaben/challenge", ['art' => 'gj_sperren', 'geschaeftsjahr' => '2026']), 'Jahressperre noch nicht über REST');
        $f = $this->status(200, $this->http('POST', "$p/freigaben", ['buchungen' => [$id]]), 'festgeschrieben');
        $this->gleich([null, [['id' => $id, 'journal_nr' => 1, 'nr' => 'ALLG-2026-00001']]], [$f['freigabe'], $f['buchungen']], 'Journal- und Belegnummer (F25)');
        $erg = $this->http('GET', "$p/buchungen/$id");
        $b = $this->status(200, $erg, 'festgeschriebene Buchung');
        $this->gleich(['festgeschrieben', true, 'nutzer:' . $erika], [$b['status'], $b['testbetrieb'], $b['festgeschrieben_von']], 'als Testbuchung gekennzeichnet (F59)');
        $this->ist(preg_match('/^[0-9a-f]{64}$/', (string) $b['hash']) === 1 && $erg[0]->headers->get('ETag') === null, 'Kettenglied mit Hash, kein ETag mehr');
        $this->gleich('KM003', $this->status(409, $this->http('PUT', "$p/buchungen/$id", $voll, ['If-Match' => '*']), 'kein Ändern (F20)')['code'], 'gebucht ist gebucht');
        $this->gleich('KM003', $this->status(409, $this->http('DELETE', "$p/buchungen/$id", null, ['If-Match' => '*']), 'kein Löschen (F20)')['code'], 'kein Löschen');

        // --- Splitbuchung in einem Zug, Entwurf löschen ---
        $f = $this->buchen(self::inhalt('Honorare Muster', [['1200', 'S', '300.00'], ['8100', 'H', '250.00'], ['1000', 'H', '50.00']], '2026-03-05', 'AR-1'));
        $split = $f['buchungen'][0]['id'];
        $this->gleich(2, $f['buchungen'][0]['journal_nr'], 'zweite Buchung');
        $this->gleich('KM002', $this->status(422, $this->http('POST', "$p/buchungen",
            self::inhalt('nicht ausgeglichen', [['1200', 'S', '10.00'], ['8100', 'H', '9.99']]) + ['vorbereiten' => true]), 'Σ Soll ≠ Σ Haben (F4)')['code'], 'KM002');
        $erg = $this->http('POST', "$p/buchungen", self::inhalt('wird gelöscht', [['1200', 'S', '1.00']]));
        $weg = $this->status(201, $erg, 'Entwurf zum Löschen')['id'];
        $this->status(428, $this->http('DELETE', "$p/buchungen/$weg"), 'Löschen verlangt If-Match');
        $this->status(204, $this->http('DELETE', "$p/buchungen/$weg", null, ['If-Match' => (string) $erg[0]->headers->get('ETag')]), 'Entwurf gelöscht');
        $this->status(404, $this->http('GET', "$p/buchungen/$weg"), 'Entwurf ist weg');

        // --- Stapelfreigabe (F67) ---
        $s1 = $this->status(201, $this->http('POST', "$p/buchungen", self::inhalt('Stapel 1', [['1000', 'S', '10.00'], ['8100', 'H', '10.00']], '2026-04-01') + ['vorbereiten' => true]), 'Stapel 1')['id'];
        $s2 = $this->status(201, $this->http('POST', "$p/buchungen", self::inhalt('Stapel 2', [['1000', 'S', '20.00'], ['8100', 'H', '20.00']], '2026-04-02') + ['vorbereiten' => true]), 'Stapel 2')['id'];
        $c = $this->status(201, $this->http('POST', "$p/freigaben/challenge", ['art' => 'festschreibung', 'buchungen' => [$s2, $s1]]), 'Challenge für den Stapel');
        $this->gleich([[1, $s2], [2, $s1]], array_map(fn ($x) => [$x['pos'], $x['buchung']], $c['inhalt']['buchungen']), 'geordnete Stapelliste');
        $this->ist(!in_array($c['inhalt_hash'], array_column($c['inhalt']['buchungen'], 'freigabe_hash'), true), 'Stapel-Hash über die Liste');
        $f = $this->status(200, $this->http('POST', "$p/freigaben", ['buchungen' => [$s2, $s1]]), 'Stapel festgeschrieben');
        $this->gleich([[$s2, 3], [$s1, 4]], array_map(fn ($x) => [$x['id'], $x['journal_nr']], $f['buchungen']), 'in der Reihenfolge der Liste');
        $this->status(400, $this->http('POST', "$p/freigaben", ['buchungen' => [$s1, $s1]]), 'doppelte ID');

        // --- Journal: Filter, Seiten per Cursor, Reihenfolge ---
        $offen = $this->status(201, $this->http('POST', "$p/buchungen", ['text' => 'offener Entwurf']), 'Entwurf ohne alles')['id'];
        $alle = $this->status(200, $this->http('GET', "$p/buchungen"), 'alle');
        $this->gleich([$offen, $id, $split, $s2, $s1], array_column($alle['eintraege'], 'id'), 'Entwürfe zuerst, dann Journal nach Nummer');
        $this->gleich([1, 2, 3, 4], array_column($this->status(200, $this->http('GET', "$p/buchungen?status=festgeschrieben"), 'Journal')['eintraege'], 'journal_nr'), 'Journal');
        $this->gleich([$offen], array_column($this->status(200, $this->http('GET', "$p/buchungen?status=entwurf,freigabebereit"), 'Stapel')['eintraege'], 'id'), 'Stapel');
        $this->gleich([2], array_column($this->status(200, $this->http('GET', "$p/buchungen?konto=1200&von=2026-03-03&bis=2026-03-31"), 'Filter')['eintraege'], 'journal_nr'), 'Konto und Zeitraum');
        $this->gleich([3, 4], array_column($this->status(200, $this->http('GET', "$p/buchungen?konto=1000&buchungsart=BANK&erfasst_von=" . rawurlencode('nutzer:' . $erika) . '&von=2026-04-01'),
            'weitere Filter')['eintraege'], 'journal_nr'), 'Konto, Buchungsart, Erfasser');
        $seite = $this->status(200, $this->http('GET', "$p/buchungen?status=festgeschrieben&anzahl=3&reihenfolge=ab"), 'Seite 1 absteigend');
        $this->gleich([4, 3, 2], array_column($seite['eintraege'], 'journal_nr'), 'neueste zuerst');
        $seite = $this->status(200, $this->http('GET', "$p/buchungen?status=festgeschrieben&anzahl=3&reihenfolge=ab&cursor=" . $seite['weiter']), 'Seite 2');
        $this->gleich([[1], null], [array_column($seite['eintraege'], 'journal_nr'), $seite['weiter']], 'Rest ohne weiteren Cursor');
        $this->status(400, $this->http('GET', "$p/buchungen?status=gebucht"), 'unbekannter Status');
        $this->status(400, $this->http('GET', "$p/buchungen?cursor=unsinn"), 'ungültiger Cursor');

        // --- Salden und Kontoblatt (B4) ---
        $salden = array_column($this->status(200, $this->http('GET', "$p/salden?stichtag=2026-12-31"), 'Salden'), null, 'konto');
        $this->gleich(['300.00', '23.80', ['betrag' => '276.20', 'seite' => 'S']], [$salden['1200']['soll'], $salden['1200']['haben'], $salden['1200']['saldo']], 'Bank');
        $this->gleich(['betrag' => '280.00', 'seite' => 'H'], $salden['8100']['saldo'], 'Honorare im Haben, Betrag positiv (F24)');
        $this->gleich(['betrag' => '20.00', 'seite' => 'H'], $salden['1000']['saldo'], 'Kasse');
        $this->gleich(4, count($salden), 'alle Konten');
        $this->gleich('0.00', $this->status(200, $this->http('GET', "$p/salden?stichtag=2026-03-04&konto=8100,1000"), 'Stichtag und Kontenfilter')[1]['saldo']['betrag'], 'vor der Buchung 0,00');
        $this->status(404, $this->http('GET', "$p/salden?stichtag=2026-12-31&geschaeftsjahr=1999"), 'unbekanntes Geschäftsjahr');
        $this->gleich(4, count($this->status(200, $this->http('GET', "$p/salden?stichtag=2026-12-31&geschaeftsjahr=2026"), 'Salden des Jahres')), 'je Geschäftsjahr');
        $kb = $this->status(200, $this->http('GET', "$p/konten/1000/kontoblatt?von=2026-04-01&bis=2026-12-31"), 'Kontoblatt');
        $this->gleich([['betrag' => '50.00', 'seite' => 'H'], ['betrag' => '20.00', 'seite' => 'H']], [$kb['anfangssaldo'], $kb['endsaldo']], 'Anfangs- und Endsaldo');
        $this->gleich([[4, 'S', '10.00', ['8100']], [3, 'S', '20.00', ['8100']]],
            array_map(fn ($x) => [$x['journal_nr'], $x['seite'], $x['betrag'], $x['gegenkonten']], $kb['bewegungen']), 'Bewegungen nach Buchungsdatum, mit Gegenkonten');
        $kb = $this->status(200, $this->http('GET', "$p/konten/8100/kontoblatt?von=2026-01-01&bis=2026-12-31&anzahl=2"), 'Kontoblatt Seite 1');
        $kb2 = $this->status(200, $this->http('GET', "$p/konten/8100/kontoblatt?von=2026-01-01&bis=2026-12-31&anzahl=2&cursor=" . $kb['weiter']), 'Kontoblatt Seite 2');
        $this->gleich([[2, 4], [3], null], [array_column($kb['bewegungen'], 'journal_nr'), array_column($kb2['bewegungen'], 'journal_nr'), $kb2['weiter']], 'seitenweise');
        $this->status(404, $this->http('GET', "$p/konten/9999/kontoblatt?von=2026-01-01&bis=2026-12-31"), 'unbekanntes Konto');
        $this->status(400, $this->http('GET', "$p/konten/1000/kontoblatt?von=2026-12-31&bis=2026-01-01"), 'von nach bis');

        // --- Storno (F30, F78 O2): nur ein Entwurf, eigene Freigabe ---
        $st = $this->status(201, $this->http('POST', "$p/buchungen/$split/storno", ['buchungsdatum' => '2026-03-06']), 'Storno-Entwurf');
        $this->gleich(['entwurf', $split, [['1200', 'H', '300.00'], ['8100', 'S', '250.00'], ['1000', 'S', '50.00']]],
            [$st['status'], $st['storno_von'], array_map(fn ($z) => [$z['konto'], $z['seite'], $z['betrag']], $st['zeilen'])], 'getauschte Seiten, Betrag positiv');
        $this->gleich('KM003', $this->status(409, $this->http('POST', "$p/buchungen/$split/storno", []), 'zweites Storno')['code'], 'höchstens einmal');
        $this->gleich('KM003', $this->status(409, $this->http('POST', "$p/buchungen/$offen/storno", []), 'Storno eines Entwurfs')['code'], 'nur festgeschriebene');
        $this->status(200, $this->http('POST', "$p/buchungen/{$st['id']}/vorbereiten", null, ['If-Match' => '*']), 'Storno vorbereitet');
        $n = $this->status(201, $this->http('POST', "$p/buchungen", self::inhalt('normal', [['1000', 'S', '5.00'], ['8100', 'H', '5.00']], '2026-05-01') + ['vorbereiten' => true]), 'normale Buchung')['id'];
        $this->gleich('KM003', $this->status(409, $this->http('POST', "$p/freigaben", ['buchungen' => [$st['id'], $n]]), 'Storno und andere gemischt')['code'], 'getrennt freigeben');
        $this->gleich(5, $this->status(200, $this->http('POST', "$p/freigaben", ['buchungen' => [$st['id']]]), 'Storno festgeschrieben')['buchungen'][0]['journal_nr'], 'eigene Nummer');
        $this->gleich($st['id'], $this->status(200, $this->http('GET', "$p/buchungen/$split"), 'Original')['storniert_durch'], 'Original nennt sein Storno');
        $this->gleich(['300.00', '323.80'], (fn ($x) => [$x['soll'], $x['haben']])($this->status(200, $this->http('GET', "$p/salden?stichtag=2026-03-31&konto=1200"), 'Salden nach Storno')[0]), 'Storno als eigene Bewegung, nichts gelöscht');
        $this->gleich(['betrag' => '23.80', 'seite' => 'H'], $this->status(200, $this->http('GET', "$p/salden?stichtag=2026-03-31&konto=1200"), 'Saldo nach Storno')[0]['saldo'], 'Storno gleicht aus');

        // --- Beleg nachreichen (F27) ---
        $beleg = ['paperless_id' => 101, 'md5' => str_repeat('a', 32), 'sha256' => str_repeat('b', 64)];
        $this->status(201, $this->http('POST', "$p/buchungen/$id/belege", $beleg), 'Beleg nachgereicht');
        $this->status(400, $this->http('POST', "$p/buchungen/$id/belege", ['paperless_id' => 0, 'md5' => 'x']), 'Belegverweis geprüft (F10)');
        $this->status(409, $this->http('POST', "$p/buchungen/$offen/belege", $beleg), 'bei Entwürfen über Ändern');

        // --- Freigabe mit Passkey (F38, F68): Credential im Mandanten, Assertion über die Challenge ---
        $k = Konfiguration::laden();
        $db = new Datenbank($k);
        $m = $db->verbindung(self::MANDANT);
        $freigabePk = new Authentifikator('kumero.test', self::ORIGIN, -7);
        $m->executeStatement("SELECT kern.credential_registrieren('system:test', ?, decode(?, 'hex'), decode(?, 'hex'), -7, 'Freigabe-Passkey (Test)')",
            ['nutzer:' . $erika, bin2hex($freigabePk->credentialId), bin2hex($freigabePk->cose())]);
        $c = $this->status(201, $this->http('POST', "$p/freigaben/challenge", ['art' => 'festschreibung', 'buchungen' => [$n]]), 'Challenge mit Passkey');
        $this->gleich([['type' => 'public-key', 'id' => Base64Url::kodieren($freigabePk->credentialId)]], $c['optionen']['allowCredentials'], 'eigener Freigabe-Passkey zur Auswahl');
        $falsch = $freigabePk->anmelden(random_bytes(32));
        $this->gleich('webauthn.ungueltig', $this->status(422, $this->http('POST', "$p/freigaben", ['buchungen' => [$n], 'challenge_id' => $c['challenge_id'], 'assertion' => $falsch]),
            'Assertion über eine andere Challenge')['code'], 'Signatur an die Challenge gebunden');
        $fremd = $freigabePk->anmelden(Base64Url::dekodieren($c['optionen']['challenge']), 0x05, 'https://boese.test');
        $this->status(422, $this->http('POST', "$p/freigaben", ['buchungen' => [$n], 'challenge_id' => $c['challenge_id'], 'assertion' => $fremd]), 'fremder Origin');
        $n2 = $this->status(201, $this->http('POST', "$p/buchungen", self::inhalt('anderer Inhalt', [['1000', 'S', '6.00'], ['8100', 'H', '6.00']], '2026-05-02') + ['vorbereiten' => true]), 'zweite Buchung')['id'];
        $gut = $freigabePk->anmelden(Base64Url::dekodieren($c['optionen']['challenge']));
        $this->gleich('KM003', $this->status(409, $this->http('POST', "$p/freigaben", ['buchungen' => [$n2], 'challenge_id' => $c['challenge_id'], 'assertion' => $gut]),
            'Challenge gilt für einen anderen Inhalt')['code'], 'Bindung an den Inhalt prüft die Datenbank (F72)');
        $gut = $freigabePk->anmelden(Base64Url::dekodieren($c['optionen']['challenge']));
        $f = $this->status(200, $this->http('POST', "$p/freigaben", ['buchungen' => [$n], 'challenge_id' => $c['challenge_id'], 'assertion' => $gut]), 'mit Passkey freigegeben');
        $this->ist(preg_match('/^[0-9a-f-]{36}$/', (string) $f['freigabe']) === 1, 'Freigabe gespeichert');
        $this->gleich(false, $this->status(200, $this->http('GET', "$p/buchungen/$n"), 'freigegebene Buchung')['testbetrieb'], 'keine Testbuchung');
        $this->gleich(1, (int) $m->fetchOne('SELECT count(*) FROM kern.freigabe_position WHERE buchung = ?', [$n]), 'Stapelliste zur Freigabe gespeichert (F67)');
        $c2 = $this->status(201, $this->http('POST', "$p/freigaben/challenge", ['art' => 'festschreibung', 'buchungen' => [$n2]]), 'neue Challenge');
        $this->status(422, $this->http('POST', "$p/freigaben", ['buchungen' => [$n2], 'challenge_id' => $c['challenge_id'],
            'assertion' => $freigabePk->anmelden(Base64Url::dekodieren($c2['optionen']['challenge']))]), 'verbrauchte Challenge-ID mit neuer Signatur');

        // --- Modul buchen: Geschäftsvorfälle ---
        $this->gleich('modul.nicht_aktiv', $this->status(404, $this->http('GET', "$p/buchen/vorlagen"), 'Modul nicht aktiv')['code'], '404 ohne Modul');
        $this->ist(!in_array('buchen', array_column($this->status(200, $this->http('GET', "$p/oberflaeche/menue"), 'Menü')['bereiche'], 'id'), true), 'ohne Modul kein Bereich Buchen');
        $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'buchen', 'mandant' => 'buchpraxis']);
        $menue = $this->status(200, $this->http('GET', "$p/oberflaeche/menue"), 'Menü mit Modul');
        $buchen = array_values(array_filter($menue['bereiche'], fn ($x) => $x['id'] === 'buchen'))[0];
        $this->gleich(['#/m/buchpraxis/buchen/liste', '#/m/buchpraxis/buchen/erfassen', '#/m/buchpraxis/buchen/vorlagen'], array_column($buchen['eintraege'], 'pfad'), 'Bereich Buchen');
        $this->ist(isset($menue['seiten']['buchen/kontoblatt']), 'Kontoblatt als Seite ohne Menüeintrag');
        $this->gleich([], $this->status(200, $this->http('GET', "$p/buchen/vorlagen"), 'Vorlagen'), 'anfangs leer');
        $this->gleich(['angelegt' => 7, 'vorhanden' => 0], $this->status(200, $this->http('POST', "$p/buchen/vorlagen/vorgaben"), 'mitgelieferte übernehmen'), 'sieben allgemeine (M5)');
        $this->gleich(['angelegt' => 0, 'vorhanden' => 7], $this->status(200, $this->http('POST', "$p/buchen/vorlagen/vorgaben"), 'erneut'), 'nichts doppelt');
        $v = $this->status(201, $this->http('POST', "$p/buchen/vorlagen", ['gruppe' => 'Zahlungseingang', 'name' => 'Honorar Bank', 'konto' => '1200',
            'gegenkonto' => '8100', 'buchungsart' => 'BANK', 'text' => 'Zahlungseingang', 'tags' => ['Praxis'], 'reihenfolge' => 5]), 'eigene Vorlage');
        $this->gleich(['1200', '8100', ['Praxis'], 5], [$v['konto'], $v['gegenkonto'], $v['tags'], $v['reihenfolge']], 'Vorlage im Ergebnis');
        $this->gleich('konflikt.vorhanden', $this->status(409, $this->http('POST', "$p/buchen/vorlagen", ['gruppe' => 'Zahlungseingang', 'name' => 'Honorar Bank']), 'doppelt')['code'], 'Gruppe + Name eindeutig');
        $f = $this->status(400, $this->http('POST', "$p/buchen/vorlagen", ['name' => 'ohne Gruppe', 'konto' => '1200', 'gegenkonto' => '1200', 'x' => 1]), 'ungültige Vorlage');
        $this->gleich(['gegenkonto', 'gruppe', 'x'], (function () use ($f) { $x = array_column($f['fehler'], 'feld'); sort($x); return $x; })(), 'Fehler je Feld');
        $v2 = $this->status(200, $this->http('PUT', "$p/buchen/vorlagen/{$v['id']}", ['gruppe' => 'Zahlungseingang', 'name' => 'Honorar Bank', 'konto' => '1200', 'gegenkonto' => '8100', 'text' => 'Honorar']), 'geändert');
        $this->gleich(['Honorar', null, [], 100], [$v2['text'], $v2['buchungsart'], $v2['tags'], $v2['reihenfolge']], 'ersetzt, nicht ergänzt');
        $this->gleich('Honorar Bank', $this->status(200, $this->http('GET', "$p/buchen/vorlagen"), 'Liste')[7]['name'], 'sortiert nach Reihenfolge');
        $this->status(204, $this->http('DELETE', "$p/buchen/vorlagen/{$v['id']}"), 'gelöscht');
        $this->status(404, $this->http('DELETE', "$p/buchen/vorlagen/{$v['id']}"), 'schon gelöscht');
        $typen = $m->fetchAllKeyValue("SELECT typ, count(*) FROM kern.protokoll WHERE typ LIKE 'buchen.%' GROUP BY typ");
        $this->gleich(['buchen.vorlage_angelegt' => 8, 'buchen.vorlage_geaendert' => 1, 'buchen.vorlage_geloescht' => 1], array_map('intval', $typen), 'jede Änderung im Protokoll (F48)');
        $this->ist((bool) $m->fetchOne("SELECT vorher->>'text' = 'Zahlungseingang' AND nachher->>'text' = 'Honorar' FROM kern.protokoll WHERE typ = 'buchen.vorlage_geaendert'"),
            'mit Vorher- und Nachher-Stand');

        // --- Erfasser (F31): erfasst und bereitet vor, gibt aber nicht frei ---
        [$azubi, $pkAzubi] = $this->nutzer('Anton Azubi');
        $this->u->cli(['command' => 'zugang:gewaehren', 'nutzer' => $azubi, 'mandant' => 'buchpraxis', '--rolle' => ['Erfasser']]);
        $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'buchen', 'mandant' => 'buchpraxis']);
        $this->anmelden($pkAzubi);
        $e = $this->status(201, $this->http('POST', "$p/buchungen", self::inhalt('vom Azubi', [['1000', 'S', '7.00'], ['8100', 'H', '7.00']], '2026-06-01') + ['vorbereiten' => true]), 'Erfasser legt an')['id'];
        $this->gleich('recht.fehlt', $this->status(403, $this->http('POST', "$p/freigaben", ['buchungen' => [$e]]), 'Erfasser gibt nicht frei')['code'], 'kein Festschreiben');
        $this->gleich('recht.fehlt', $this->status(403, $this->http('POST', "$p/freigaben/challenge", ['art' => 'festschreibung', 'buchungen' => [$e]]), 'keine Challenge')['code'], 'auch keine Challenge');
        $this->status(201, $this->http('POST', "$p/buchungen/$id/storno", []), 'Erfasser schlägt Storno vor');
        $this->gleich(7, count($this->status(200, $this->http('GET', "$p/buchen/vorlagen"), 'Erfasser nutzt Vorlagen')), 'Vorlagen lesen');
        $this->gleich('recht.fehlt', $this->status(403, $this->http('POST', "$p/buchen/vorlagen", ['gruppe' => 'x', 'name' => 'y']), 'Erfasser pflegt keine Vorlagen')['code'], 'Pflege nur mit Recht');
        $menue = $this->status(200, $this->http('GET', "$p/oberflaeche/menue"), 'Menü des Erfassers');
        $buchen = array_values(array_filter($menue['bereiche'], fn ($x) => $x['id'] === 'buchen'))[0];
        $this->gleich(['buchen.liste', 'buchen.erfassen'], array_column($buchen['eintraege'], 'id'), 'ohne „Geschäftsvorfälle“');
        // Vier Augen: die Verantwortliche gibt den Entwurf des Erfassers frei
        $this->anmelden($pkErika);
        $this->status(200, $this->http('POST', "$p/freigaben", ['buchungen' => [$e]]), 'ohne Assertion im Testbetrieb weiter möglich');
        $b = $this->status(200, $this->http('GET', "$p/buchungen/$e"), 'Vier-Augen-Buchung');
        $this->gleich(['nutzer:' . $azubi, 'nutzer:' . $erika], [$b['erfasst_von'], $b['festgeschrieben_von']], 'erfasst von und festgeschrieben von (F31)');

        // --- Dienstkonto (F78 O3): erfasst über die API, gibt aber nie frei – auch nicht mit der Rolle Buchhalter ---
        $v = $db->verwaltung();
        $dienst = (string) $v->fetchOne("SELECT verwaltung.dienstkonto_anlegen('system:test', 'Bank-Bot (Test)', 'buchpraxis')");
        $token = 'kmr_' . Base64Url::kodieren(random_bytes(32));
        $v->executeStatement("SELECT verwaltung.token_ausstellen('system:test', ?::uuid, ?, now() + interval '1 hour')", [$dienst, hash('sha256', $token)]);
        $m->executeStatement("SELECT kern.zuweisen('system:test', ?, 'Buchhalter')", ['dienst:' . $dienst]);
        $this->cookie = null;
        $bot = ['Authorization' => 'Bearer ' . $token];
        $x = $this->status(201, $this->http('POST', "$p/buchungen", self::inhalt('vom Bot', [['1200', 'S', '8.00'], ['8100', 'H', '8.00']], '2026-06-02') + ['vorbereiten' => true], $bot), 'Dienstkonto erfasst');
        $this->gleich(['api', 'dienst:' . $dienst, 'freigabebereit'], [$x['herkunft'], $x['erfasst_von'], $x['status']], 'Herkunft api');
        $this->gleich('freigabe.nur_persoenlich', $this->status(403, $this->http('POST', "$p/freigaben", ['buchungen' => [$x['id']]], $bot), 'Dienstkonto gibt nicht frei')['code'], 'nie durch einen Bot (F38)');
        $this->gleich('freigabe.nur_persoenlich', $this->status(403, $this->http('POST', "$p/freigaben/challenge", ['art' => 'festschreibung', 'buchungen' => [$x['id']]], $bot),
            'Dienstkonto bekommt keine Challenge')['code'], 'auch keine Challenge');

        // --- Ketten und Protokoll ---
        $this->gleich(0, (int) $m->fetchOne('SELECT count(*) FROM kern.kette_pruefen()'), 'Ketten des Mandanten intakt');
        $this->gleich(7, (int) $m->fetchOne("SELECT count(*) FROM kern.buchung WHERE status = 'festgeschrieben'"), 'sieben festgeschriebene Buchungen');
        $this->ist((int) $m->fetchOne("SELECT count(*) FROM kern.protokoll WHERE typ = 'buchung.festgeschrieben' AND ids->>'idempotenz' IS NOT NULL") === 7,
            'Festschreibungen mit Idempotenzschlüssel protokolliert (F19)');
        $db->schliessen();
    }
}
