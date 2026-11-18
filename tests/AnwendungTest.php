<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

use Kumero\Plattform\Datenbank;
use Kumero\Plattform\Identitaet;
use Kumero\Plattform\Kernel;
use Kumero\Plattform\Konfiguration;
use Kumero\Tests\Hilfe\Authentifikator;
use Kumero\Plattform\Webauthn\Base64Url;
use Kumero\Tests\Hilfe\Umgebung;
use Kumero\Tests\Hilfe\Zugaenge;
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
    use Zugaenge;

    private const ORIGIN = Umgebung::ORIGIN;
    private const MANDANT = 'kumero_testpraxis';
    private const ZWEITER = 'kumero_testlabor';
    private const DRITTER = 'kumero_testalt';

    private Umgebung $u;
    private ?string $cookie = null;
    private int $schluessel = 0;

    public function vorher(): void
    {
        $this->u = new Umgebung([self::MANDANT, self::ZWEITER, self::DRITTER]);
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

    /** Anmeldung, ohne den Erfolg vorauszusetzen. @return array{0: array, 1: array} Ergebnis von http() und die Assertion */
    private function anmeldeversuch(Authentifikator $a): array
    {
        $opt = $this->status(200, $this->http('POST', '/api/v1/anmeldung/optionen', null), 'Anmeldeoptionen');
        $as = $a->anmelden(Base64Url::dekodieren($opt['challenge']));
        return [$this->http('POST', '/api/v1/anmeldung', $as), $as];
    }

    private function db(): Datenbank
    {
        $k = Konfiguration::laden();
        return new Datenbank($k->mitDbNutzer('kumero_owner'));
    }

    public function testEndeZuEnde(): void
    {
        // Mandant anlegen = ersten Administrator einsetzen (U5); weitere Nutzer legt die Kommandozeile im Mandanten an
        $aus = $this->mandantAnlegen('testpraxis', 'Testpraxis');
        $this->ist(str_contains($aus, '/app.html#registrierung=testpraxis.'), 'Der Link nennt den Mandanten');
        $this->u->cli(['command' => 'mandant:anlegen', 'kennung' => 'testpraxis', 'name' => 'Doppelt', 'vorname' => 'A', 'familienname' => 'B', 'email' => 'a@example.org'], true);
        $this->u->cli(['command' => 'mandant:anlegen', 'kennung' => 'ohneadmin', 'name' => 'Ohne Administrator'], true);
        $aus = $this->cli(['command' => 'nutzer:anlegen', 'mandant' => 'testpraxis', 'vorname' => 'Erika', 'familienname' => 'Test',
                           'email' => 'erika@example.org', '--rolle' => ['Verantwortlicher']]);
        $erika = self::angelegt($aus);
        $token = self::linkToken($aus);

        // Passkey über den Link: Nutzerkennung im Passkey ist das Pseudonym, nicht die ID des Nutzers
        $opt = $this->status(200, $this->http('POST', '/api/v1/registrierung/optionen', ['token' => $token]), 'Registrierungsoptionen');
        $this->gleich('required', $opt['authenticatorSelection']['userVerification'], 'UV verlangt');
        $this->gleich(['Erika Test', 'Erika Test – Testpraxis', 'testpraxis'], [$opt['user']['displayName'], $opt['user']['name'], $opt['mandant']['kennung']],
            'Der Eintrag im Passkey-Speicher nennt den Mandanten (U2)');
        $this->ist(strlen(Base64Url::dekodieren($opt['user']['id'])) === 32 && !str_contains(bin2hex(Base64Url::dekodieren($opt['user']['id'])), str_replace('-', '', $erika)),
            'Pseudonym: 32 zufällige Byte, nicht die Nutzer-ID');
        $pk = new Authentifikator('kumero.test', self::ORIGIN, -7);
        $pk->nutzerkennung = $opt['user']['id'];
        $this->status(201, $this->http('POST', '/api/v1/registrierung', ['token' => $token, 'bezeichnung' => 'Test-Schlüssel'] + $pk->registrieren(Base64Url::dekodieren($opt['challenge']))), 'Passkey registriert');
        $this->status(403, $this->http('POST', '/api/v1/registrierung/optionen', ['token' => $token]), 'Link nur einmal verwendbar');
        $this->status(403, $this->http('POST', '/api/v1/registrierung/optionen', ['token' => 'fremd.' . substr($token, 11)]), 'Link für unbekannten Mandanten');
        $this->status(403, $this->http('POST', '/api/v1/registrierung/optionen', ['token' => substr($token, 11)]), 'Token ohne Mandant ist kein Link dieses Nutzers');

        // Anmeldung: der Passkey bestimmt über sein Pseudonym den Mandanten (U1)
        $this->status(401, $this->http('GET', '/api/v1/ich'), 'ohne Anmeldung 401');
        [$erg, $as] = $this->anmeldeversuch($pk);
        $ich = $this->status(200, $erg, 'angemeldet');
        $this->gleich(['nutzer:' . $erika, 'Erika Test', 'Erika', 'Test', 'erika@example.org', false, false, 'testpraxis'],
            [$ich['subjekt'], $ich['name'], $ich['vornamen'], $ich['familienname'], $ich['email'], $ich['verwalter'], $ich['angaben_fehlen'], $ich['mandant']], 'Profil der Identität im Mandanten');
        $this->gleich([['kennung' => 'testpraxis', 'name' => 'Testpraxis', 'betriebsart' => 'test', 'lesemodus' => false]], $ich['mandanten'], 'genau ein Mandant, im Testbetrieb');
        $this->ist(str_starts_with((string) $this->cookie, 'testpraxis.'), 'Sitzungs-Cookie nennt den Mandanten');
        $alt = $this->cookie;
        $this->status(401, $this->http('POST', '/api/v1/anmeldung', $as), 'Wiederholte Assertion wird abgewiesen (Challenge verbraucht)');
        $ohne = $pk->nutzerkennung;
        $pk->nutzerkennung = null;
        $this->gleich('webauthn.ungueltig', $this->status(401, $this->anmeldeversuch($pk)[0], 'ohne userHandle keine Anmeldung')['code'], 'Pseudonym fehlt');
        $pk->nutzerkennung = Base64Url::kodieren(random_bytes(32));
        $this->status(401, $this->anmeldeversuch($pk)[0], 'unbekanntes Pseudonym');
        $pk->nutzerkennung = $ohne;
        $this->cookie = $alt;

        // Profil und Einstellungen (PROFIL.md, F86): an der Identität im Mandanten gespeichert, mit Protokoll ohne Werte
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
        $this->gleich('KM003', $this->status(409, $this->http('PATCH', '/api/v1/ich', ['vornamen' => 'Anders', 'familienname' => 'Anders', 'email' => 'anders@example.org']),
            'vollständige Angaben ändert der Nutzer nicht selbst')['code'], 'Ergänzen nur, solange die E-Mail fehlt');
        $this->gleich(null, $this->status(200, $this->http('PATCH', '/api/v1/ich', ['sprache' => null]), 'Sprache zurücksetzen')['sprache'], 'wieder wie der Browser');
        $this->gleich('anfrage.origin', $this->status(403, $this->http('PUT', '/api/v1/ich/einstellungen', ['kern.kontrast' => 'hoch'], [], true, 'https://boese.test'), 'fremde Herkunft')['code'], 'Origin auch hier');

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
        [, $leo] = $this->nutzer('testpraxis', 'Leo', 'Leser', ['Leser'], -8);
        $this->anmelden($leo);
        $this->status(200, $this->http('GET', "$p/konten"), 'Leser liest Konten (EdDSA)');
        $this->gleich('recht.fehlt', $this->status(403, $this->http('POST', "$p/konten", ['nr' => '4930', 'bezeichnung' => 'Bürobedarf', 'kontoart' => 'aufwand']),
            'Leser legt nichts an')['code'], 'Recht fehlt');

        // Der Administrator des Mandanten: keine Buchungsdaten (F43)
        $adam = $this->passkeyEinrichten($aus = $this->cli(['command' => 'nutzer:link', 'mandant' => 'testpraxis',
            'nutzer' => (string) $this->db()->verbindung(self::MANDANT)->fetchOne("SELECT id FROM kern.nutzer WHERE familienname = 'Admin'")]));
        $this->gleich('Adam Admin', $this->anmelden($adam)['name'], 'Administrator angemeldet');
        $this->status(403, $this->http('GET', "$p/konten"), 'Administrator sieht keine Buchungsdaten');

        // Zweiter Mandant: eigene Identitäten; eine Sitzung gilt nur für ihren Mandanten (U3)
        $this->mandantAnlegen('testlabor', 'Testlabor');
        [$erikaLabor, $pkLabor] = $this->nutzer('testlabor', 'Erika', 'Test');
        $this->ist($erikaLabor !== $erika, 'Dieselbe Person hat im zweiten Mandanten eine eigene Identität (V17)');
        $this->anmelden($pk);
        $this->gleich('mandant.unbekannt', $this->status(404, $this->http('GET', '/m/testlabor/api/v1/konten'), 'fremder Mandant sieht aus wie unbekannt')['code'], 'kein Zugang über die Sitzung des anderen Mandanten');
        $this->cookie = 'testlabor.' . substr((string) $this->cookie, strlen('testpraxis.'));
        $this->status(401, $this->http('GET', '/api/v1/ich'), 'Das Geheimnis einer Sitzung gilt in keinem anderen Mandanten');
        $ich = $this->anmelden($pkLabor);
        $this->gleich(['nutzer:' . $erikaLabor, 'testlabor'], [$ich['subjekt'], $ich['mandant']], 'Der Passkey des zweiten Mandanten führt in den zweiten Mandanten');
        $this->gleich([], $this->status(200, $this->http('GET', '/m/testlabor/api/v1/konten'), 'eigener Mandant'), 'leerer Kontenplan');
        $this->status(404, $this->http('GET', "$p/konten"), 'und nicht mehr in den ersten');

        // Die Zuordnung Pseudonym → Mandant verschafft keinen Zugang: zeigt sie in den falschen Mandanten, scheitert die Anmeldung
        $db = $this->db();
        $db->verwaltung()->executeStatement("UPDATE verwaltung.pseudonym SET mandant = 'testlabor' WHERE pseudonym = ?", [$pk->nutzerkennung]);
        $this->cookie = null;
        $this->status(401, $this->anmeldeversuch($pk)[0], 'falsch zugeordnetes Pseudonym: der Mandant kennt den Passkey nicht');
        $db->verwaltung()->executeStatement("UPDATE verwaltung.pseudonym SET mandant = 'testpraxis' WHERE pseudonym = ?", [$pk->nutzerkennung]);
        $fremd = clone $pkLabor;
        $fremd->nutzerkennung = $pk->nutzerkennung;
        $this->status(401, $this->anmeldeversuch($fremd)[0], 'fremder Passkey mit dem Pseudonym eines anderen');
        $pkLabor->zaehler = $fremd->zaehler;

        // System-Verwalter: eigene Identität der Installation, sieht keinen Mandanten von innen (U4)
        [$vera, $pkVera] = $this->verwalter('Vera Verwalterin');
        $ich = $this->anmelden($pkVera);
        $this->gleich(['nutzer:' . $vera, 'Vera Verwalterin', true, null, []], [$ich['subjekt'], $ich['name'], $ich['verwalter'], $ich['mandant'], $ich['mandanten']], 'Profil des Verwalters');
        $this->ist(str_starts_with((string) $this->cookie, '.'), 'Sitzung der Installation');
        $this->status(404, $this->http('GET', "$p/konten"), 'Verwalter sieht keinen Mandanten von innen');
        $this->gleich('hoch', $this->status(200, $this->http('PUT', '/api/v1/ich/einstellungen', ['kern.kontrast' => 'hoch']), 'Einstellungen des Verwalters')['kern.kontrast'], 'in der Verwaltung gespeichert');
        $this->status(400, $this->http('PATCH', '/api/v1/ich', ['vornamen' => 'V', 'familienname' => 'V', 'email' => 'v@example.org']), 'Angaben ergänzen gibt es nur im Mandanten');

        // Abmelden
        $this->status(204, $this->http('POST', '/api/v1/abmeldung'), 'abgemeldet');
        $this->ist($this->cookie === null, 'Cookie gelöscht');
        $this->status(401, $this->http('GET', '/api/v1/ich'), 'nach Abmeldung 401');
        $this->anmelden($pk);
        $sitzung = $this->cookie;
        $this->status(204, $this->http('POST', '/api/v1/abmeldung'), 'Nutzer abgemeldet');
        $this->cookie = $sitzung;
        $this->status(401, $this->http('GET', '/api/v1/ich'), 'beendete Sitzung des Mandanten gilt nicht mehr');
        $this->cookie = null;

        // Stillgelegter Mandant: niemand meldet sich an
        $db->verwaltung()->executeStatement("SELECT verwaltung.mandant_stilllegen('system:test', 'testlabor')");
        $this->status(401, $this->anmeldeversuch($pkLabor)[0], 'stillgelegter Mandant');

        // Protokoll und Ketten: Nutzer stehen im Mandanten, die Verwaltung kennt nur Pseudonyme
        $v = $db->verwaltung();
        $m = $db->verbindung(self::MANDANT);
        $this->gleich(0, (int) $v->fetchOne('SELECT count(*) FROM verwaltung.kette_pruefen()'), 'Kette der Verwaltung intakt');
        $this->gleich(0, (int) $m->fetchOne('SELECT count(*) FROM kern.kette_pruefen()'), 'Ketten des Mandanten intakt');
        $this->gleich([3, 0], [(int) $m->fetchOne("SELECT count(*) FROM kern.protokoll WHERE typ = 'nutzer.einstellung_geaendert'"),
            (int) $m->fetchOne("SELECT count(*) FROM kern.protokoll WHERE typ = 'nutzer.einstellung_geaendert' AND (vorher IS NOT NULL OR nachher IS NOT NULL)")],
            'Einstellungen im Protokoll des Mandanten: nur Schlüssel, keine Werte (PROFIL.md 4a)');
        $this->gleich(2, (int) $m->fetchOne("SELECT count(*) FROM kern.protokoll WHERE typ = 'nutzer.sprache_geaendert'"), 'Sprachwechsel protokolliert');
        $this->gleich([3, 3, 4], [(int) $m->fetchOne('SELECT count(*) FROM kern.nutzer'), (int) $m->fetchOne("SELECT count(*) FROM kern.protokoll WHERE typ = 'nutzer.angelegt'"),
            (int) $m->fetchOne("SELECT count(*) FROM kern.protokoll WHERE typ = 'nutzer.zugang_ausgestellt'")], 'Nutzer und Zugänge im Protokoll des Mandanten');
        $this->gleich([1, 0], [(int) $v->fetchOne('SELECT count(*) FROM verwaltung.nutzer'), (int) $v->fetchOne('SELECT count(*) FROM verwaltung.zugang')],
            'In der Verwaltung steht nur der System-Verwalter, kein Zugang');
        $this->gleich(['' => 1, 'testlabor' => 2, 'testpraxis' => 3],
            array_map('intval', $v->fetchAllKeyValue("SELECT coalesce(mandant, ''), count(*) FROM verwaltung.pseudonym GROUP BY 1 ORDER BY 1")),
            'Zuordnung: je Identität ein Pseudonym');
        $this->gleich(0, (int) $v->fetchOne("SELECT count(*) FROM verwaltung.protokoll WHERE (ids::text || coalesce(nachher::text, '')) ~* ('(erika|leser|example\.org|' || ? || ')')", [$erika]),
            'Das Protokoll der Verwaltung nennt keinen Nutzer eines Mandanten');
        $this->gleich(2, (int) $v->fetchOne("SELECT count(*) FROM verwaltung.protokoll WHERE typ = 'mandant.administrator_eingesetzt'"), 'Administrator einsetzen in der Verwaltung vermerkt (U5)');
        $this->ist((int) $m->fetchOne(
            "SELECT count(*) FROM kern.protokoll WHERE typ = 'konto.angelegt' AND subjekt = ? AND ids->>'idempotenz' IS NOT NULL", ['nutzer:' . $erika]) === 2,
            'Konten mit Subjekt und Idempotenzschlüssel protokolliert (F19)');
        $db->schliessen();
    }

    /**
     * Überführen des Bestands (U6): Nutzer, die vor dem Umbau in der Verwaltungsdatenbank standen, werden in jedem
     * Mandanten mit Zugang eine Identität mit derselben ID; ihr Passkey gilt in genau einem Mandanten weiter.
     */
    public function testBestandUeberfuehren(): void
    {
        $this->mandantAnlegen('testpraxis', 'Testpraxis');
        $this->mandantAnlegen('testlabor', 'Testlabor');
        $this->mandantAnlegen('testalt', 'Altmandant');
        $db = $this->db();
        $v = $db->verwaltung();
        // Bestand nachstellen, wie er vor 0.7.22 aussah: Nutzer, Passkey (Kennung = Nutzer-ID), Zugänge, Einstellung, Rollen im Mandanten.
        // Die Schreibfunktionen dafür gibt es nicht mehr; der Test schreibt als Eigentümer direkt.
        $alt = function (string $id, string $name, bool $verwalter, array $mandanten, ?Authentifikator $pk) use ($db, $v): void {
            $v->executeStatement('INSERT INTO verwaltung.nutzer (id, name, sprache, verwalter) VALUES (?, ?, ?, ?::boolean)', [$id, $name, 'de', $verwalter ? 'true' : 'false']);
            if ($pk !== null) {
                $pk->nutzerkennung = Base64Url::kodieren((string) hex2bin(str_replace('-', '', $id)));
                $reg = (new \Kumero\Plattform\Webauthn\Pruefer('kumero.test', [self::ORIGIN]))->registrierung(
                    Base64Url::dekodieren($pk->registrieren($c = random_bytes(32))['response']['clientDataJSON']),
                    Base64Url::dekodieren($pk->registrieren($c)['response']['attestationObject']), $c);
                $v->executeStatement("INSERT INTO verwaltung.anmelde_credential (credential_id, nutzer, public_key, alg, zaehler, bezeichnung) VALUES (?, ?, decode(?, 'hex'), ?, 0, 'Bestand')",
                    [Base64Url::kodieren($reg['credential_id']), $id, bin2hex($reg['public_key']), $reg['alg']]);
            }
            foreach ($mandanten as $i => [$kennung, $rolle]) {
                $v->executeStatement("INSERT INTO verwaltung.zugang (subjekt, mandant, gewaehrt_am) VALUES (?, ?, now() + make_interval(secs => ?))", ['nutzer:' . $id, $kennung, $i]);
                $db->verbindung('kumero_' . $kennung)->executeStatement('SELECT kern.zuweisen(?, ?, ?)', ['system:test', 'nutzer:' . $id, $rolle]);
            }
        };
        $erika = '11111111-1111-4111-8111-111111111111';
        $frank = '22222222-2222-4222-8222-222222222222';
        $vera = '33333333-3333-4333-8333-333333333333';
        $otto = '44444444-4444-4444-8444-444444444444';
        $alt($erika, 'Erika Bestand', false, [['testpraxis', 'Verantwortlicher']], $pkErika = new Authentifikator('kumero.test', self::ORIGIN, -7));
        // Frank arbeitet für zwei Mandanten; der zuerst gewährte ist der produktive, der Testmandant hat Vorrang
        $db->verbindung(self::DRITTER)->executeStatement("UPDATE kern.buch SET betriebsart = 'produktiv', produktiv_seit = now()");
        $alt($frank, 'Frank Bestand', true, [['testalt', 'Leser'], ['testlabor', 'Verantwortlicher']], $pkFrank = new Authentifikator('kumero.test', self::ORIGIN, -8));
        $alt($vera, 'Vera Bestand', true, [], $pkVera = new Authentifikator('kumero.test', self::ORIGIN, -7));
        $alt($otto, 'Otto Ohnezugang', false, [], null);
        $v->executeStatement("INSERT INTO verwaltung.nutzer_einstellung (nutzer, schluessel, wert) VALUES (?, 'kern.kontrast', '\"hoch\"')", [$frank]);
        $db->schliessen();

        // Vor dem Überführen kommt aus dem Bestand niemand hinein (die Pseudonyme fehlen noch)
        $this->status(401, $this->anmeldeversuch($pkErika)[0], 'vor dem Überführen keine Anmeldung');
        $aus = $this->cli(['command' => 'system:nutzer-ueberfuehren']);
        $this->ist(str_contains($aus, "verwalter:link $frank") && str_contains($aus, "nutzer:link testalt $frank"), 'Die Ausgabe nennt, wer einen neuen Passkey braucht');

        // Erika: ein Mandant – Passkey gilt weiter, dieselbe ID, Angaben werden abgefragt
        $ich = $this->status(200, $this->anmeldeversuch($pkErika)[0], 'Erika meldet sich mit dem vorhandenen Passkey an');
        $this->gleich(['nutzer:' . $erika, 'testpraxis', 'Erika Bestand', null, true], [$ich['subjekt'], $ich['mandant'], $ich['familienname'], $ich['vornamen'], $ich['angaben_fehlen']],
            'dieselbe ID; der bisherige Name ist Familienname, Vorname und E-Mail fehlen');
        $this->status(200, $this->http('GET', '/m/testpraxis/api/v1/konten'), 'Die Rollen gelten weiter');
        $f = $this->status(400, $this->http('PATCH', '/api/v1/ich', ['vornamen' => '', 'familienname' => 'Bestand', 'email' => 'keine']), 'unvollständige Angaben');
        $this->gleich(['email', 'vornamen'], (function () use ($f) { $x = array_column($f['fehler'], 'feld'); sort($x); return $x; })(), 'Fehler je Feld');
        $ich = $this->status(200, $this->http('PATCH', '/api/v1/ich', ['vornamen' => 'Erika', 'familienname' => 'Bestand', 'email' => 'Erika@Example.org']), 'Angaben ergänzt');
        $this->gleich(['Erika Bestand', 'erika@example.org', false], [$ich['name'], $ich['email'], $ich['angaben_fehlen']], 'Angaben vollständig');

        // Frank: Passkey gilt im Testmandanten weiter, im anderen braucht er einen neuen; als Verwalter ebenfalls
        $ich = $this->anmelden($pkFrank);
        $this->gleich(['nutzer:' . $frank, 'testlabor', false, 'hoch'], [$ich['subjekt'], $ich['mandant'], $ich['verwalter'], $ich['einstellungen']['kern.kontrast']],
            'Der Passkey führt in den Testmandanten, nicht mehr in die Verwaltung; Einstellungen übernommen');
        $this->status(404, $this->http('GET', '/m/testalt/api/v1/konten'), 'in den anderen Mandanten nur mit eigenem Passkey');
        $pkFrankAlt = $this->passkeyEinrichten($this->cli(['command' => 'nutzer:link', 'mandant' => 'testalt', 'nutzer' => $frank]), -8);
        $this->gleich(['nutzer:' . $frank, 'testalt'], array_slice(array_values(array_intersect_key($this->anmelden($pkFrankAlt), ['subjekt' => 1, 'mandant' => 1])), 0, 2), 'neue Identität im zweiten Mandanten, dieselbe ID');
        $this->status(200, $this->http('GET', '/m/testalt/api/v1/konten'), 'Rolle Leser gilt weiter');
        $pkFrankV = $this->passkeyEinrichten($this->cli(['command' => 'verwalter:link', 'verwalter' => $frank]));
        $this->gleich([true, null], [($ich = $this->anmelden($pkFrankV))['verwalter'], $ich['mandant']], 'Frank als System-Verwalter mit neuem Passkey (Notausgang verwalter:link)');

        // Vera: Verwalterin ohne Mandant – bleibt in der Verwaltung, Passkey gilt weiter
        $this->gleich(['nutzer:' . $vera, true], [($ich = $this->anmelden($pkVera))['subjekt'], $ich['verwalter']], 'Verwalterin ohne Mandant meldet sich wie bisher an');

        // Wiederholen ändert nichts
        $aus = $this->cli(['command' => 'system:nutzer-ueberfuehren']);
        $this->ist(!str_contains($aus, '→ Mandant') && !str_contains($aus, 'Verwalter') && str_contains($aus, 'Bestand überführt: 0 '), 'Zweiter Lauf findet nichts mehr');
        $this->anmelden($pkErika);

        $db = $this->db();
        $v = $db->verwaltung();
        $this->gleich(0, (int) $v->fetchOne("SELECT count(*) FROM verwaltung.zugang WHERE subjekt LIKE 'nutzer:%'"), 'Zugänge der Nutzer sind geleert');
        $this->gleich([['(überführt)', false], ['(überführt)', false]],
            array_map(fn ($z) => [$z['name'], (bool) $z['aktiv']], $v->fetchAllAssociative('SELECT name, aktiv FROM verwaltung.nutzer WHERE NOT verwalter ORDER BY id')),
            'Wer kein Verwalter ist, bleibt in der Verwaltung nur als gesperrter Eintrag ohne Namen');
        $this->gleich(0, (int) $v->fetchOne('SELECT count(*) FROM verwaltung.anmelde_credential WHERE widerrufen_am IS NULL AND nutzer IN (?, ?) AND bezeichnung = ?', [$erika, $frank, 'Bestand']),
            'Überführte Passkeys gelten in der Verwaltung nicht mehr');
        $this->gleich(['Erika', 1, 1], array_values($db->verbindung(self::MANDANT)->fetchAssociative(
            "SELECT n.vornamen, (SELECT count(*) FROM kern.anmelde_credential c WHERE c.nutzer = n.id) AS passkeys,
                    (SELECT count(*) FROM kern.protokoll WHERE typ = 'nutzer.passkey_uebernommen') AS protokoll FROM kern.nutzer n WHERE n.id = ?", [$erika])), 'Passkey im Mandanten, mit Protokoll');
        foreach ([self::MANDANT, self::ZWEITER, self::DRITTER] as $d) {
            $this->gleich(0, (int) $db->verbindung($d)->fetchOne('SELECT count(*) FROM kern.kette_pruefen()'), "Ketten intakt: $d");
        }
        $this->gleich(0, (int) $v->fetchOne('SELECT count(*) FROM verwaltung.kette_pruefen()'), 'Kette der Verwaltung intakt');
        $db->schliessen();
    }
}
