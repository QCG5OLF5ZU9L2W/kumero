<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

use Kumero\Plattform\Datenbank;
use Kumero\Plattform\Identitaet;
use Kumero\Plattform\Kernel;
use Kumero\Plattform\Konfiguration;
use Kumero\Tests\Hilfe\Authentifikator;
use Kumero\Tests\Hilfe\Umgebung;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ende-zu-Ende gegen eine echte PostgreSQL: Kommandozeile, Passkey-Registrierung
 * und -Anmeldung, Mandant, Rechte, Idempotenz, Stammdaten. Nur synthetische Daten.
 *
 * Voraussetzung: KUMERO_TEST_PGHOST (z. B. /tmp/pgtest), Superuser KUMERO_TEST_PGUSER.
 */
final class AnwendungTest extends Rahmen
{
    private const ORIGIN = Umgebung::ORIGIN;
    private const MANDANT = 'kumero_testpraxis';

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

    private function cli(array $eingabe): string
    {
        return $this->u->cli($eingabe);
    }

    /** @return array{0: Response, 1: mixed} */
    private function http(string $methode, string $pfad, ?array $json = null, array $kopf = [], bool $idempotent = true, ?string $origin = self::ORIGIN): array
    {
        $k = Konfiguration::laden();
        $server = ['HTTPS' => 'on', 'HTTP_HOST' => 'kumero.test'];
        if ($origin !== null) {
            $server['HTTP_ORIGIN'] = $origin;
        }
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        if ($idempotent && $methode !== 'GET' && str_starts_with($pfad, '/m/')) {
            $server['HTTP_IDEMPOTENCY_KEY'] = 'test-' . (++$this->schluessel) . '-' . bin2hex(random_bytes(4));
        }
        foreach ($kopf as $n => $w) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $n))] = $w;
        }
        $r = Request::create('https://kumero.test' . $pfad, $methode, [], $this->cookie ? [Identitaet::COOKIE => $this->cookie] : [], [], $server,
            $json === null ? null : json_encode($json));
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

    private function registrieren(Authentifikator $a, string $token): void
    {
        $opt = $this->status(200, $this->http('POST', '/api/v1/registrierung/optionen', ['token' => $token]), 'Registrierungsoptionen');
        $this->gleich('required', $opt['authenticatorSelection']['userVerification'], 'UV verlangt');
        $reg = $a->registrieren(\Kumero\Plattform\Webauthn\Base64Url::dekodieren($opt['challenge']));
        $this->status(201, $this->http('POST', '/api/v1/registrierung', ['token' => $token, 'bezeichnung' => 'Test-Schlüssel'] + $reg), 'Passkey registriert');
    }

    private function anmelden(Authentifikator $a): array
    {
        $opt = $this->status(200, $this->http('POST', '/api/v1/anmeldung/optionen', null), 'Anmeldeoptionen');
        $as = $a->anmelden(\Kumero\Plattform\Webauthn\Base64Url::dekodieren($opt['challenge']));
        return [$this->http('POST', '/api/v1/anmeldung', $as), $as];
    }

    private static function token(string $ausgabe): string
    {
        preg_match('/#registrierung=([A-Za-z0-9_-]+)/', $ausgabe, $m) ?: throw new \RuntimeException("Kein Link: $ausgabe");
        return $m[1];
    }

    public function testEndeZuEnde(): void
    {
        // Erster Nutzer über die Kommandozeile, Passkey über den Link
        $aus = $this->cli(['command' => 'nutzer:anlegen', 'name' => 'Erika Test', '--verwalter' => true]);
        preg_match('/Nutzer angelegt: ([0-9a-f-]{36})/', $aus, $m);
        $erika = $m[1];
        $token = self::token($aus);
        $pk = new Authentifikator('kumero.test', self::ORIGIN, -7);
        $this->registrieren($pk, $token);
        $this->status(403, $this->http('POST', '/api/v1/registrierung/optionen', ['token' => $token]), 'Link nur einmal verwendbar');

        // Anmeldung
        $this->status(401, $this->http('GET', '/api/v1/ich'), 'ohne Anmeldung 401');
        [$erg, $as] = $this->anmelden($pk);
        $ich = $this->status(200, $erg, 'angemeldet');
        $this->gleich('nutzer:' . $erika, $ich['subjekt'], 'Subjekt (E4)');
        $this->ist($this->cookie !== null, 'Sitzungs-Cookie gesetzt');
        $this->gleich([], $this->status(200, $this->http('GET', '/api/v1/ich'), 'Profil')['mandanten'], 'noch keine Mandanten');
        $alt = $this->cookie;
        $this->status(401, $this->http('POST', '/api/v1/anmeldung', $as), 'Wiederholte Assertion wird abgewiesen (Challenge verbraucht)');
        $this->cookie = $alt;

        // Profil und Einstellungen (PROFIL.md, F86): am Nutzer gespeichert, nur der eigene, mit Protokoll ohne Werte
        $ich = $this->status(200, $this->http('GET', '/api/v1/ich'), 'Profil mit Einstellungen');
        $this->gleich([null, ['de'], ['kern.betragseingabe' => 'dezimal', 'kern.schriftgroesse' => 'normal', 'kern.kontrast' => 'system']],
            [$ich['sprache'], $ich['sprachen'], $ich['einstellungen']], 'Sprache nicht gesetzt (wie der Browser), Standardwerte');
        $e = $this->status(200, $this->http('PUT', '/api/v1/ich/einstellungen', ['kern.betragseingabe' => 'kaufmaennisch', 'kern.schriftgroesse' => 'gross']), 'Einstellungen setzen');
        $this->gleich(['kaufmaennisch', 'gross', 'system'], array_values($e), 'gesetzt, Rest bleibt Standard');
        $this->gleich($e, $this->status(200, $this->http('GET', '/api/v1/ich/einstellungen'), 'Einstellungen lesen'), 'gespeichert');
        $f = $this->status(400, $this->http('PUT', '/api/v1/ich/einstellungen', ['kern.betragseingabe' => 'römisch', 'fremd.schalter' => 'an']), 'ungültige Einstellung');
        $this->gleich(['fremd.schalter', 'kern.betragseingabe'], (function () use ($f) { $x = array_column($f['fehler'], 'feld'); sort($x); return $x; })(), 'unbekannter Schlüssel und unbekannter Wert');
        $this->gleich('dezimal', $this->status(200, $this->http('PUT', '/api/v1/ich/einstellungen', ['kern.betragseingabe' => null]), 'zurück zum Standard')['kern.betragseingabe'], 'null löscht');
        $this->gleich('de', $this->status(200, $this->http('PATCH', '/api/v1/ich', ['sprache' => 'de']), 'Sprache setzen')['sprache'], 'Sprache des Nutzers geht vor (U5)');
        $this->status(400, $this->http('PATCH', '/api/v1/ich', ['sprache' => 'xx']), 'unbekannte Sprache');
        $this->status(400, $this->http('PATCH', '/api/v1/ich', ['name' => 'Anders']), 'der Name ist nicht selbst änderbar (U2)');
        $this->gleich(null, $this->status(200, $this->http('PATCH', '/api/v1/ich', ['sprache' => null]), 'Sprache zurücksetzen')['sprache'], 'wieder wie der Browser');
        $this->gleich('anfrage.origin', $this->status(403, $this->http('PUT', '/api/v1/ich/einstellungen', ['kern.kontrast' => 'hoch'], [], true, 'https://boese.test'), 'fremde Herkunft')['code'], 'Origin auch hier');

        // Mandant und Zugang
        $this->cli(['command' => 'mandant:anlegen', 'kennung' => 'testpraxis', 'name' => 'Testpraxis']);
        $this->status(404, $this->http('GET', '/m/testpraxis/api/v1/konten'), 'ohne Zugang wie unbekannt');
        $this->cli(['command' => 'zugang:gewaehren', 'nutzer' => $erika, 'mandant' => 'testpraxis']);
        $ich = $this->status(200, $this->http('GET', '/api/v1/ich'), 'Profil mit Mandant');
        $this->gleich('test', $ich['mandanten'][0]['betriebsart'], 'Mandant im Testbetrieb');

        // Konten mit Idempotenz
        $p = '/m/testpraxis/api/v1';
        $this->status(400, $this->http('POST', "$p/konten", ['nr' => '1000', 'bezeichnung' => 'Kasse', 'kontoart' => 'aktiv'], [], false), 'Idempotency-Key Pflicht');
        $k1 = $this->http('POST', "$p/konten", ['nr' => '1000', 'bezeichnung' => 'Kasse', 'kontoart' => 'aktiv'], ['Idempotency-Key' => 'konto-1000-anlegen']);
        $this->gleich(['nr' => '1000', 'bezeichnung' => 'Kasse', 'kontoart' => 'aktiv', 'aktiv' => true, 'bebucht' => false],
            $this->status(201, $k1, 'Konto angelegt'), 'Konto im Ergebnis');
        $k2 = $this->http('POST', "$p/konten", ['nr' => '1000', 'bezeichnung' => 'Kasse', 'kontoart' => 'aktiv'], ['Idempotency-Key' => 'konto-1000-anlegen']);
        $this->status(201, $k2, 'Wiederholung liefert dieselbe Antwort');
        $this->gleich('true', $k2[0]->headers->get('Idempotency-Replayed'), 'als Wiederholung gekennzeichnet');
        $this->gleich($k1[1], $k2[1], 'gleicher Inhalt');
        $this->gleich('KM006', $this->status(409, $this->http('POST', "$p/konten", ['nr' => '1001', 'bezeichnung' => 'x', 'kontoart' => 'aktiv'],
            ['Idempotency-Key' => 'konto-1000-anlegen']), 'Schlüssel für andere Anfrage')['code'], 'KM006');
        $this->gleich('konflikt.vorhanden', $this->status(409, $this->http('POST', "$p/konten", ['nr' => '1000', 'bezeichnung' => 'Doppelt', 'kontoart' => 'aktiv']),
            'Kontonummer doppelt')['code'], 'Konflikt');
        $f = $this->status(400, $this->http('POST', "$p/konten", ['nr' => '1200', 'bezeichnung' => '', 'kontoart' => 'guthaben', 'extra' => 1]), 'ungültige Eingabe');
        $this->gleich(['bezeichnung', 'extra', 'kontoart'], (function () use ($f) { $x = array_column($f['fehler'], 'feld'); sort($x); return $x; })(), 'Fehler je Feld');
        $this->status(201, $this->http('POST', "$p/konten", ['nr' => '8400', 'bezeichnung' => 'Erlöse', 'kontoart' => 'ertrag']), 'zweites Konto');
        $this->gleich(['1000', '8400'], array_column($this->status(200, $this->http('GET', "$p/konten"), 'Kontenliste'), 'nr'), 'Kontenliste');
        $this->gleich(['8400'], array_column($this->status(200, $this->http('GET', "$p/konten?kontoart=ertrag"), 'Filter'), 'nr'), 'Filter Kontoart');
        $this->gleich('Barkasse', $this->status(200, $this->http('PATCH', "$p/konten/1000", ['bezeichnung' => 'Barkasse']), 'Konto ändern')['bezeichnung'], 'geändert');
        $this->status(404, $this->http('GET', "$p/konten/9999"), 'unbekanntes Konto');

        // Herkunft (CSRF) und Methoden
        $this->gleich('anfrage.origin', $this->status(403, $this->http('POST', "$p/tags", ['name' => 'x'], [], true, 'https://boese.test'), 'fremde Herkunft')['code'], 'Origin');
        $this->status(405, $this->http('DELETE', "$p/konten"), 'Methode nicht erlaubt');

        // Geschäftsjahre, Nummernkreise, Buchungsarten
        $this->status(201, $this->http('POST', "$p/geschaeftsjahre", ['kennung' => '2026', 'beginn' => '2026-01-01', 'ende' => '2026-12-31']), 'Geschäftsjahr 2026');
        $this->gleich('KM002', $this->status(422, $this->http('POST', "$p/geschaeftsjahre", ['kennung' => '2028', 'beginn' => '2028-01-01', 'ende' => '2028-12-31']),
            'Lücke zwischen Geschäftsjahren')['code'], 'F65 aus der Datenbank');
        $this->status(201, $this->http('POST', "$p/nummernkreise", ['kuerzel' => 'ER', 'bezeichnung' => 'Eingangsrechnungen']), 'Nummernkreis');
        $this->status(200, $this->http('PUT', "$p/nummernkreise/ER/jahre/2026", ['vorlage' => 'ER-{JJJJ}-{NR:4}', 'zuruecksetzen' => true]), 'Nummernkreis festlegen');
        $this->gleich('ER-{JJJJ}-{NR:4}', $this->status(200, $this->http('GET', "$p/nummernkreise"), 'Nummernkreise')[0]['jahre'][0]['vorlage'], 'Vorlage gespeichert');
        $this->status(201, $this->http('POST', "$p/buchungsarten", ['kuerzel' => 'ER', 'bezeichnung' => 'Eingangsrechnung', 'nummernkreis' => 'ER']), 'Buchungsart');
        $this->status(201, $this->http('POST', "$p/tags", ['name' => 'Praxis']), 'Tag');

        // Zweite Person mit Rolle Leser: lesen ja, schreiben nein (F41)
        $aus = $this->cli(['command' => 'nutzer:anlegen', 'name' => 'Leo Leser']);
        preg_match('/Nutzer angelegt: ([0-9a-f-]{36})/', $aus, $m);
        $this->cli(['command' => 'zugang:gewaehren', 'nutzer' => $m[1], 'mandant' => 'testpraxis', '--rolle' => ['Leser']]);
        $leo = new Authentifikator('kumero.test', self::ORIGIN, -8);
        $this->cookie = null;
        $this->registrieren($leo, self::token($aus));
        $this->status(200, $this->anmelden($leo)[0], 'Leser angemeldet (EdDSA)');
        $this->status(200, $this->http('GET', "$p/konten"), 'Leser liest Konten');
        $this->gleich('recht.fehlt', $this->status(403, $this->http('POST', "$p/konten", ['nr' => '4930', 'bezeichnung' => 'Bürobedarf', 'kontoart' => 'aufwand']),
            'Leser legt nichts an')['code'], 'Recht fehlt');

        // Abmelden
        $this->status(204, $this->http('POST', '/api/v1/abmeldung'), 'abgemeldet');
        $this->ist($this->cookie === null, 'Cookie gelöscht');
        $this->status(401, $this->http('GET', '/api/v1/ich'), 'nach Abmeldung 401');

        // Protokoll und Ketten
        $k = Konfiguration::laden();
        $db = new Datenbank($k);
        $this->gleich(0, (int) $db->verwaltung()->fetchOne('SELECT count(*) FROM verwaltung.kette_pruefen()'), 'Kette der Verwaltung intakt');
        $this->gleich([3, 0], [(int) $db->verwaltung()->fetchOne("SELECT count(*) FROM verwaltung.protokoll WHERE typ = 'nutzer.einstellung_geaendert'"),
            (int) $db->verwaltung()->fetchOne("SELECT count(*) FROM verwaltung.protokoll WHERE typ = 'nutzer.einstellung_geaendert' AND (vorher IS NOT NULL OR nachher IS NOT NULL)")],
            'Einstellungen im Protokoll: nur Schlüssel, keine Werte (PROFIL.md 4a)');
        $this->gleich(2, (int) $db->verwaltung()->fetchOne("SELECT count(*) FROM verwaltung.protokoll WHERE typ = 'nutzer.sprache_geaendert'"), 'Sprachwechsel protokolliert');
        $this->gleich(0, (int) $db->verbindung(self::MANDANT)->fetchOne('SELECT count(*) FROM kern.kette_pruefen()'), 'Ketten des Mandanten intakt');
        $this->ist((int) $db->verbindung(self::MANDANT)->fetchOne(
            "SELECT count(*) FROM kern.protokoll WHERE typ = 'konto.angelegt' AND subjekt = ? AND ids->>'idempotenz' IS NOT NULL", ['nutzer:' . $erika]) === 2,
            'Konten mit Subjekt und Idempotenzschlüssel protokolliert (F19)');
        $db->schliessen();
    }
}
