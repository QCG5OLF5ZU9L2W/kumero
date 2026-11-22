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
    /** Hostname, unter dem die Anfragen kommen – die Adresse der Installation oder die eigene Domain eines Mandanten */
    private string $host = 'kumero.test';

    public function vorher(): void
    {
        $this->u = new Umgebung([self::MANDANT, self::ZWEITER, self::DRITTER, 'kumero_testneu', 'kumero_spiel']);
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
        $server = ['HTTPS' => 'on', 'HTTP_HOST' => $this->host];
        if ($origin !== null) {
            $server['HTTP_ORIGIN'] = $origin === self::ORIGIN ? 'https://' . $this->host : $origin;
        }
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        if ($idempotent && $methode !== 'GET' && (str_starts_with($pfad, '/m/') || str_starts_with($pfad, '/b/'))) {
            $server['HTTP_IDEMPOTENCY_KEY'] = 'test-' . (++$this->schluessel) . '-' . bin2hex(random_bytes(4));
        }
        foreach ($kopf as $n => $w) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $n))] = $w;
        }
        $r = Request::create('https://' . $this->host . $pfad, $methode, [], $this->cookie ? [Identitaet::COOKIE => $this->cookie] : [], [], $server,
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
        $this->gleich([['kennung' => 'testpraxis', 'name' => 'Testpraxis']], $ich['mandanten'], 'genau ein Mandant; Betriebsart und Lesemodus gehören zum Buch');
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
        $p = '/m/testpraxis/b/haupt/api/v1';
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
            'nutzer' => (string) $this->db()->verbindung(self::MANDANT)->fetchOne("SELECT id FROM mandant.nutzer WHERE familienname = 'Admin'")]));
        $this->gleich('Adam Admin', $this->anmelden($adam)['name'], 'Administrator angemeldet');
        $this->status(403, $this->http('GET', "$p/konten"), 'Administrator sieht keine Buchungsdaten');

        // Zweiter Mandant: eigene Identitäten; eine Sitzung gilt nur für ihren Mandanten (U3)
        $this->mandantAnlegen('testlabor', 'Testlabor');
        [$erikaLabor, $pkLabor] = $this->nutzer('testlabor', 'Erika', 'Test');
        $this->ist($erikaLabor !== $erika, 'Dieselbe Person hat im zweiten Mandanten eine eigene Identität (V17)');
        $this->anmelden($pk);
        $this->gleich('mandant.unbekannt', $this->status(404, $this->http('GET', '/m/testlabor/b/haupt/api/v1/konten'), 'fremder Mandant sieht aus wie unbekannt')['code'], 'kein Zugang über die Sitzung des anderen Mandanten');
        $this->cookie = 'testlabor.' . substr((string) $this->cookie, strlen('testpraxis.'));
        $this->status(401, $this->http('GET', '/api/v1/ich'), 'Das Geheimnis einer Sitzung gilt in keinem anderen Mandanten');
        $ich = $this->anmelden($pkLabor);
        $this->gleich(['nutzer:' . $erikaLabor, 'testlabor'], [$ich['subjekt'], $ich['mandant']], 'Der Passkey des zweiten Mandanten führt in den zweiten Mandanten');
        $this->gleich([], $this->status(200, $this->http('GET', '/m/testlabor/b/haupt/api/v1/konten'), 'eigener Mandant'), 'leerer Kontenplan');
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
        $this->gleich(0, (int) $m->fetchOne('SELECT count(*) FROM mandant.kette_pruefen()'), 'Ketten des Mandanten intakt');
        $this->gleich([3, 0], [(int) $m->fetchOne("SELECT count(*) FROM mandant.protokoll WHERE typ = 'nutzer.einstellung_geaendert'"),
            (int) $m->fetchOne("SELECT count(*) FROM mandant.protokoll WHERE typ = 'nutzer.einstellung_geaendert' AND (vorher IS NOT NULL OR nachher IS NOT NULL)")],
            'Einstellungen im Protokoll des Mandanten: nur Schlüssel, keine Werte (PROFIL.md 4a)');
        $this->gleich(2, (int) $m->fetchOne("SELECT count(*) FROM mandant.protokoll WHERE typ = 'nutzer.sprache_geaendert'"), 'Sprachwechsel protokolliert');
        $this->gleich([3, 3, 4], [(int) $m->fetchOne('SELECT count(*) FROM mandant.nutzer'), (int) $m->fetchOne("SELECT count(*) FROM mandant.protokoll WHERE typ = 'nutzer.angelegt'"),
            (int) $m->fetchOne("SELECT count(*) FROM mandant.protokoll WHERE typ = 'nutzer.zugang_ausgestellt'")], 'Nutzer und Zugänge im Protokoll des Mandanten');
        $this->gleich([1, 0], [(int) $v->fetchOne('SELECT count(*) FROM verwaltung.nutzer'), (int) $v->fetchOne('SELECT count(*) FROM verwaltung.zugang')],
            'In der Verwaltung steht nur der System-Verwalter, kein Zugang');
        $this->gleich(['' => 1, 'testlabor' => 2, 'testpraxis' => 3],
            array_map('intval', $v->fetchAllKeyValue("SELECT coalesce(mandant, ''), count(*) FROM verwaltung.pseudonym GROUP BY 1 ORDER BY 1")),
            'Zuordnung: je Identität ein Pseudonym');
        $this->gleich(0, (int) $v->fetchOne("SELECT count(*) FROM verwaltung.protokoll WHERE (ids::text || coalesce(nachher::text, '')) ~* ('(erika|leser|example\.org|' || ? || ')')", [$erika]),
            'Das Protokoll der Verwaltung nennt keinen Nutzer eines Mandanten');
        $this->gleich(2, (int) $v->fetchOne("SELECT count(*) FROM verwaltung.protokoll WHERE typ = 'mandant.administrator_eingesetzt'"), 'Administrator einsetzen in der Verwaltung vermerkt (U5)');
        $this->ist((int) $db->verbindung(self::MANDANT . '__haupt')->fetchOne(
            "SELECT count(*) FROM kern.protokoll WHERE typ = 'konto.angelegt' AND subjekt = ? AND ids->>'idempotenz' IS NOT NULL", ['nutzer:' . $erika]) === 2,
            'Konten mit Subjekt und Idempotenzschlüssel protokolliert (F19)');
        $db->schliessen();
    }

    /**
     * Überführen des Bestands (U6): Nutzer, die vor dem Umbau in der Verwaltungsdatenbank standen, werden in jedem
     * Mandanten mit Zugang eine Identität mit derselben ID; ihr Passkey gilt in genau einem Mandanten weiter.
     */
    /**
     * Mandantenverwaltung des System-Verwalters (VERWALTUNG.md Abschnitt 14, F118): Die Seite schreibt Aufträge, der Dienst
     * (`system:auftraege`) führt sie als kumero_owner aus. Anlegen = ersten Administrator einsetzen; löschen nur
     * stillgelegt und nach dem Export; übrig bleibt in der Verwaltung nur der Protokolleintrag.
     */
    /** Eigene Domain je Mandant (VERWALTUNG.md Abschnitt 18; DM1–DM7, DM10, DM11). */
    public function testEigeneDomain(): void
    {
        $domain = 'buch.praxis-muster.example';
        $this->mandantAnlegen('testpraxis', 'Praxis Muster');
        $this->mandantAnlegen('testlabor', 'Labor Muster');
        [$id, $pk] = $this->nutzer('testpraxis', 'Berta', 'Beispiel');
        [, $pkLabor] = $this->nutzer('testlabor', 'Lars', 'Labor');
        [, $pkVerwalter] = $this->verwalter('Vera Verwalter');
        $profil = $this->anmelden($pk);
        $this->gleich(false, $profil['eigene_domain'], 'ohne eigene Domain steht der Mandant im Pfad');
        $this->status(200, $this->http('GET', '/m/' . 'testpraxis' . '/b/haupt/api/v1/konten'), 'unter der Adresse der Installation erreichbar');

        // Ein unbekannter Hostname wird abgewiesen, bevor etwas geschieht (DM4, zweite Linie)
        $this->host = 'fremd.example';
        $this->status(421, $this->http('POST', '/api/v1/anmeldung/optionen', null), 'unbekannter Hostname');
        $this->host = 'kumero.test';

        // Hostname setzen: ungültige und doppelte werden abgewiesen
        $this->ist(str_contains($this->u->cli(['command' => 'mandant:adresse', 'mandant' => 'testpraxis', 'hostname' => 'https://x.example/', '--ja' => true], true), 'Hostname'), 'kein Origin als Hostname');
        $this->ist(str_contains($this->u->cli(['command' => 'mandant:adresse', 'mandant' => 'testpraxis', 'hostname' => 'kumero.test', '--ja' => true], true), 'Installation'), 'nicht der Hostname der Installation');
        $aus = $this->cli(['command' => 'mandant:adresse', 'mandant' => 'testpraxis', 'hostname' => $domain, '--ja' => true]);
        $this->ist(str_contains($aus, "https://$domain") && str_contains($aus, 'Widerrufene Passkeys: 1'), "Hostname gesetzt, Passkey widerrufen – $aus");
        $this->ist(str_contains($this->u->cli(['command' => 'mandant:adresse', 'mandant' => 'testlabor', 'hostname' => $domain, '--ja' => true], true), 'gehört schon'), 'ein Hostname gehört zu genau einem Mandanten (DM1)');
        $this->ist(str_contains($this->cli(['command' => 'mandant:adressen']), "$domain\tMandant " . 'testpraxis'), 'mandant:adressen nennt den Hostnamen');

        // Unter der Adresse der Installation ist der Mandant nicht mehr erreichbar (DM3), der alte Passkey gilt nicht mehr (DM7)
        $erg = $this->http('GET', '/m/' . 'testpraxis' . '/api/v1/buecher');
        $this->ist(in_array($erg[0]->getStatusCode(), [401, 404], true), 'alte Sitzung endet mit dem Wechsel');
        [$erg] = $this->anmeldeversuch($pk);
        $this->ist($erg[0]->getStatusCode() >= 400, 'alter Passkey meldet nicht mehr an');
        $this->anmelden($pkLabor);
        $this->status(404, $this->http('GET', '/m/' . 'testpraxis' . '/api/v1/buecher'), 'für andere sieht er aus wie unbekannt');
        $this->status(200, $this->http('GET', '/m/' . 'testlabor' . '/api/v1/buecher'), 'der zweite Mandant bleibt unter der Adresse der Installation');

        // Neuer Zugang: Der Link trägt die Domain des Mandanten; unter der Adresse der Installation gilt er nicht
        $aus = $this->cli(['command' => 'nutzer:link', 'mandant' => 'testpraxis', 'nutzer' => $id]);
        $this->ist(str_contains($aus, "https://$domain/app.html#registrierung="), "Link nennt die eigene Domain – $aus");
        $token = self::linkToken($aus);
        $this->cookie = null;
        $this->status(403, $this->http('POST', '/api/v1/registrierung/optionen', ['token' => $token]), 'Link gilt nicht unter der Adresse der Installation');

        // Unter der eigenen Domain: Passkey einrichten, anmelden, arbeiten – ohne Mandant im Pfad (DM10)
        $this->host = $domain;
        $opt = $this->status(200, $this->http('POST', '/api/v1/registrierung/optionen', ['token' => $token]), 'Registrierungsoptionen unter der eigenen Domain');
        $this->gleich($domain, $opt['rp']['id'], 'RP-ID ist die eigene Domain');
        $this->gleich('Praxis Muster', $opt['rp']['name'], 'der Dialog des Passkeys nennt den Mandanten (DM11)');
        $neu = new Authentifikator($domain, "https://$domain");
        $neu->nutzerkennung = $opt['user']['id'];
        $this->status(201, $this->http('POST', '/api/v1/registrierung', ['token' => $token] + $neu->registrieren(Base64Url::dekodieren($opt['challenge']))), 'Passkey unter der eigenen Domain registriert');
        $o = $this->status(200, $this->http('POST', '/api/v1/anmeldung/optionen', null), 'Anmeldeoptionen');
        $this->gleich($domain, $o['rpId'], 'Anmeldung mit der RP-ID der eigenen Domain');
        $profil = $this->status(200, $this->http('POST', '/api/v1/anmeldung', $neu->anmelden(Base64Url::dekodieren($o['challenge']))), 'angemeldet unter der eigenen Domain');
        $this->gleich(true, $profil['eigene_domain'], 'Profil nennt die eigene Domain');
        $this->status(200, $this->http('GET', '/api/v1/ich'), 'ich');
        $this->status(200, $this->http('GET', '/api/v1/buecher'), 'Ebene Mandant ohne /m/');
        $this->status(200, $this->http('GET', '/b/haupt/api/v1/konten'), 'Ebene Buch ohne /m/');
        $this->status(404, $this->http('GET', '/m/' . 'testpraxis' . '/api/v1/buecher'), 'mit /m/ gibt es den Pfad dort nicht – eine Schreibweise');
        $this->status(404, $this->http('GET', '/api/v1/verwaltung/mandanten'), 'die Verwaltung der Installation gibt es dort nicht');
        $menue = $this->status(200, $this->http('GET', '/b/haupt/api/v1/oberflaeche/menue'), 'Menü');
        $roh = json_encode($menue, JSON_UNESCAPED_SLASHES);
        $this->ist(!str_contains($roh, '/m/' . 'testpraxis'), 'das Menü nennt den Mandanten nirgends im Pfad');
        $this->ist(str_contains($roh, '#/b/haupt/'), 'Seiten des Buchs beginnen mit #/b/<buch>');
        $this->status(403, $this->http('POST', '/api/v1/abmeldung', null, [], true, self::ORIGIN . '.fremd'), 'schreibend nur mit der Herkunft der eigenen Domain');

        // Dort gibt es nur diesen Mandanten: weder der System-Verwalter noch ein anderer Mandant meldet sich an (DM5)
        $this->cookie = null;
        foreach ([$pkVerwalter, $pkLabor] as $fremd) {
            [$erg] = $this->anmeldeversuch($fremd);
            $this->ist($erg[0]->getStatusCode() >= 400, 'fremder Passkey meldet unter der Domain des Mandanten nicht an');
        }

        // Der System-Verwalter entfernt die Domain in der Weboberfläche: wieder alle Passkeys widerrufen
        $this->host = 'kumero.test';
        $this->anmelden($pkVerwalter);
        $liste = $this->status(200, $this->http('GET', '/api/v1/verwaltung/mandanten'), 'Mandanten');
        $zeile = array_values(array_filter($liste['mandanten'], fn ($m) => $m['kennung'] === 'testpraxis'))[0];
        $this->gleich($domain, $zeile['adresse'], 'die Liste nennt den Hostnamen');
        $pfad = '/api/v1/verwaltung/mandanten/' . 'testpraxis' . '/adresse';
        $this->ist($this->http('PUT', $pfad, ['adresse' => null])[0]->getStatusCode() >= 400, 'ohne Bestätigung keine Änderung');
        $this->ist($this->http('PUT', $pfad, ['adresse' => 'Kein Hostname', 'bestaetigung' => 'testpraxis'])[0]->getStatusCode() >= 400, 'ungültiger Hostname');
        $erg = $this->status(200, $this->http('PUT', $pfad, ['adresse' => null, 'bestaetigung' => 'testpraxis']), 'Domain entfernt');
        $this->gleich([$domain, null, true, 1], [$erg['vorher'], $erg['adresse'], $erg['geaendert'], $erg['passkeys_widerrufen']], 'Ergebnis nennt Vorher, Nachher und die widerrufenen Passkeys');
        $this->host = $domain;
        $this->status(421, $this->http('POST', '/api/v1/anmeldung/optionen', null), 'die entfernte Domain ist unbekannt');
        $this->host = 'kumero.test';
        $aus = $this->cli(['command' => 'nutzer:link', 'mandant' => 'testpraxis', 'nutzer' => $id]);
        $this->ist(str_contains($aus, self::ORIGIN . '/app.html#registrierung='), 'Link nennt wieder die Adresse der Installation');
        $pk2 = $this->passkeyEinrichten($aus);
        $this->anmelden($pk2);
        $this->status(200, $this->http('GET', '/m/' . 'testpraxis' . '/b/haupt/api/v1/konten'), 'wieder unter der Adresse der Installation erreichbar');
        $protokoll = $this->u->psql('kumero_testpraxis', "SELECT count(*) FROM mandant.protokoll WHERE typ = 'mandant.passkeys_widerrufen'");
        $this->ist(str_contains($protokoll, '2'), 'beide Wechsel stehen im Protokoll des Mandanten');
    }

    public function testMandantenverwaltung(): void
    {
        $vw = '/api/v1/verwaltung/mandanten';
        $exporte = sys_get_temp_dir() . '/kumero-exporte-' . bin2hex(random_bytes(4));
        putenv('KUMERO_EXPORT_VERZEICHNIS=' . $exporte);
        $db = $this->db();
        $v = $db->verwaltung();
        $neu = ['kennung' => 'testneu', 'name' => 'Neue Musterpraxis', 'administrator' => ['vornamen' => 'Anna', 'familienname' => 'Admin', 'email' => 'anna@testneu.example.org']];

        // nur der System-Verwalter
        $this->status(401, $this->http('GET', $vw), 'ohne Anmeldung');
        $this->mandantAnlegen('testpraxis', 'Testpraxis');
        [, $pkNutzer] = $this->nutzer('testpraxis', 'Nora', 'Nutzerin');
        $this->anmelden($pkNutzer);
        $this->status(403, $this->http('GET', $vw), 'ein Nutzer eines Mandanten sieht die Mandantenverwaltung nicht');
        $this->status(403, $this->http('POST', $vw, $neu), 'und legt keine Mandanten an');
        [$vera, $pkVera] = $this->verwalter('Vera Verwalterin');
        $this->anmelden($pkVera);
        $liste = $this->status(200, $this->http('GET', $vw), 'Liste');
        $this->gleich([['testpraxis', 'Testpraxis', null, null, false]], array_map(fn ($m) => [$m['kennung'], $m['name'], $m['stillgelegt_am'], $m['auftrag'], $m['loeschbar']], $liste['mandanten']), 'vorhandener Mandant, nichts von innen');
        $this->gleich(['adresse', 'angelegt_am', 'auftrag', 'export', 'kennung', 'loeschbar', 'name', 'stillgelegt_am'], (function () use ($liste) { $k = array_keys($liste['mandanten'][0]); sort($k); return $k; })(), 'die Liste nennt keine Zahlen aus dem Mandanten');

        // anlegen: Eingabe geprüft, Auftrag geschrieben, Link einmal genannt
        $f = $this->status(400, $this->http('POST', $vw, ['kennung' => 'Verwaltung', 'name' => '', 'administrator' => ['email' => 'kein-at']]), 'ungültige Eingabe');
        $this->gleich(['administrator.email', 'administrator.familienname', 'administrator.vornamen', 'kennung', 'name'], (function () use ($f) { $x = array_column($f['fehler'], 'feld'); sort($x); return $x; })(), 'jedes Feld einzeln benannt');
        $this->status(409, $this->http('POST', $vw, ['kennung' => 'testpraxis'] + $neu), 'Kennung schon vergeben');
        $r = $this->status(202, $this->http('POST', $vw, $neu), 'Auftrag zum Anlegen');
        $this->ist(preg_match('~^' . preg_quote(self::ORIGIN, '~') . '/app\.html#registrierung=testneu\.[A-Za-z0-9_-]{43}$~', $r['link']) === 1, 'der Link nennt den Mandanten und steht im Fragment');
        $this->gleich(24, $r['gueltig_stunden'], 'gilt 24 Stunden');
        $this->status(409, $this->http('POST', $vw, $neu), 'derselbe Mandant nicht zweimal beauftragt');
        $offen = $this->status(200, $this->http('GET', $vw), 'Liste mit offenem Auftrag');
        $this->gleich([['testneu', null, 'mandant_anlegen']], array_values(array_map(fn ($m) => [$m['kennung'], $m['name'], $m['auftrag']], array_filter($offen['mandanten'], fn ($m) => $m['kennung'] === 'testneu'))), 'wird angelegt: steht mit dem Auftrag in der Liste');
        $this->ist(!str_contains(json_encode($offen), 'anna@') && !str_contains(json_encode($offen), substr($r['link'], -43)), 'die Liste nennt weder die E-Mail noch den Zugang');
        $this->gleich(0, (int) $v->fetchOne("SELECT count(*) FROM pg_database WHERE datname = 'kumero_testneu'"), 'die Webanwendung selbst legt keine Datenbank an');

        // der Dienst führt aus
        $aus = $this->cli(['command' => 'system:auftraege']);
        $this->ist(str_contains($aus, 'mandant_anlegen testneu: erledigt') && !str_contains($aus, 'anna@'), "Ausgabe des Dienstes ohne Namen: $aus");
        $this->gleich(['erledigt', null], array_values($v->fetchAssociative('SELECT zustand, angaben::text FROM verwaltung.auftrag WHERE id = ?', [$r['auftrag']])), 'erledigt; die Angaben zum Administrator sind aus der Verwaltung entfernt');
        $this->gleich(0, (int) $v->fetchOne("SELECT count(*) FROM verwaltung.protokoll WHERE (ids::text || coalesce(vorher::text, '') || coalesce(nachher::text, '')) ~ 'anna@|Admin'"), 'auch das Protokoll der Verwaltung nennt den Administrator nicht');
        $m = $db->verbindung('kumero_testneu');
        $this->gleich(['Anna', 'Admin', 'anna@testneu.example.org', true], array_values($m->fetchAssociative(
            "SELECT n.vornamen, n.familienname, n.email, EXISTS (SELECT 1 FROM mandant.administrator a WHERE a.nutzer = n.id AND a.bis IS NULL) FROM mandant.nutzer n")), 'im Mandanten: genau der Administrator, mit Rolle');
        $m->close();
        // Der Mandant entsteht ohne Buch (BUECHER.md A12); das erste legt der Administrator an – hier die Kommandozeile
        $this->gleich(0, (int) $v->fetchOne("SELECT count(*) FROM verwaltung.buch WHERE mandant = 'testneu'"), 'ein neuer Mandant hat noch kein Buch');
        $this->cli(['command' => 'buch:anlegen', 'mandant' => 'testneu', 'kennung' => 'haupt', 'name' => 'Neue Musterpraxis']);

        // der Administrator richtet über den Link seinen Passkey ein und ist im neuen Mandanten
        $this->cookie = null;
        $pkAnna = $this->passkeyEinrichten($r['link']);
        $ich = $this->anmelden($pkAnna);
        $this->gleich(['testneu', 'Neue Musterpraxis'], [$ich['mandant'], $ich['mandanten'][0]['name']], 'Anmeldung im neuen Mandanten');
        $this->status(403, $this->http('GET', $vw), 'der Administrator eines Mandanten ist kein System-Verwalter');
        $this->status(403, $this->http('GET', '/m/testneu/b/haupt/api/v1/buchungen'), 'und sieht keine Buchungsdaten (F43)');

        // löschen: erst stilllegen, dann exportieren – beides ist Voraussetzung
        $this->anmelden($pkVera);
        $this->status(400, $this->http('DELETE', "$vw/testneu", ['bestaetigung' => 'testpraxis']), 'ohne passende Bestätigung');
        $this->status(409, $this->http('DELETE', "$vw/testneu", ['bestaetigung' => 'testneu']), 'in Betrieb: nicht löschbar');
        $this->status(202, $this->http('POST', "$vw/testneu/export", []), 'Export im Betrieb');
        $this->ist(str_contains($this->cli(['command' => 'system:auftraege']), 'mandant_exportieren testneu: erledigt'), 'Export ausgeführt');
        $ex = array_values(array_filter($this->status(200, $this->http('GET', $vw), 'Liste')['mandanten'], fn ($x) => $x['kennung'] === 'testneu'))[0];
        $this->ist(is_file($ex['export']['datei']) && str_starts_with($ex['export']['datei'], "$exporte/testneu-") && $ex['export']['groesse'] === filesize($ex['export']['datei']) + filesize(substr($ex['export']['datei'], 0, -5) . '__haupt.dump')
            && $ex['export']['sha256'] === hash_file('sha256', $ex['export']['datei']), 'Sicherung liegt auf dem Server – Mandanten-Datenbank und je Buch eine Datei; die Liste nennt Datei, Größe aller Dateien und SHA-256');
        $this->gleich(['PGDMP', '0600', '0700'], [substr((string) file_get_contents($ex['export']['datei'], false, null, 0, 5), 0, 5), substr(sprintf('%o', fileperms($ex['export']['datei'])), -4), substr(sprintf('%o', fileperms($exporte)), -4)], 'pg_dump im eigenen Format, nur für den Dienst lesbar');
        $this->gleich(false, $ex['loeschbar'], 'ein Export aus dem laufenden Betrieb genügt nicht');
        $this->status(204, $this->http('POST', "$vw/testneu/stilllegen", []), 'stillgelegt');
        $this->status(409, $this->http('POST', "$vw/testneu/stilllegen", []), 'nicht zweimal');
        $this->cookie = null;
        $this->status(401, $this->anmeldeversuch($pkAnna)[0], 'stillgelegt: der Administrator kommt nicht mehr hinein');
        $this->anmelden($pkVera);
        $this->status(409, $this->http('DELETE', "$vw/testneu", ['bestaetigung' => 'testneu']), 'stillgelegt, aber danach nicht exportiert: nicht löschbar');
        $this->status(204, $this->http('POST', "$vw/testneu/reaktivieren", []), 'reaktiviert');
        $this->status(204, $this->http('POST', "$vw/testneu/stilllegen", []), 'wieder stillgelegt');
        sleep(1);   // der Dateiname der Sicherung nennt die Sekunde
        $this->status(202, $this->http('POST', "$vw/testneu/export", []), 'Export nach dem Stilllegen');
        $this->status(409, $this->http('POST', "$vw/testneu/export", []), 'je Mandant ein Auftrag zur Zeit');
        $this->cli(['command' => 'system:auftraege']);
        $ex2 = array_values(array_filter($this->status(200, $this->http('GET', $vw), 'Liste')['mandanten'], fn ($x) => $x['kennung'] === 'testneu'))[0];
        $this->gleich(true, $ex2['loeschbar'], 'jetzt löschbar');
        $kopf = $db->verbindung('kumero_testneu__haupt')->fetchAssociative('SELECT nr, hash FROM kern.protokoll ORDER BY nr DESC LIMIT 1');
        $db->verbindung('kumero_testneu__haupt')->close();
        $this->status(202, $this->http('DELETE', "$vw/testneu", ['bestaetigung' => 'testneu']), 'Löschen beauftragt');
        $this->status(409, $this->http('POST', "$vw/testneu/reaktivieren", []), 'während des Löschens nicht reaktivierbar');
        $this->ist(str_contains($this->cli(['command' => 'system:auftraege']), 'mandant_loeschen testneu: erledigt'), 'Löschen ausgeführt');

        // weg ist alles – bis auf den Protokolleintrag mit dem letzten Kettenkopf (V11) und die Sicherung für den Mandanten
        $v = $this->db()->verwaltung();
        $this->gleich([0, 0, 0, 0], [(int) $v->fetchOne("SELECT count(*) FROM pg_database WHERE datname LIKE 'kumero_testneu%'"),
            (int) $v->fetchOne("SELECT count(*) FROM verwaltung.mandant WHERE kennung = 'testneu'"),
            (int) $v->fetchOne("SELECT count(*) FROM verwaltung.buch WHERE mandant = 'testneu'"),
            (int) $v->fetchOne("SELECT count(*) FROM verwaltung.pseudonym WHERE mandant = 'testneu'")], 'Datenbanken (Mandant und Buch), Mandant, Buch und Pseudonyme sind entfernt');
        $p = $v->fetchAssociative("SELECT subjekt, ids::text AS ids, nachher::text AS nachher FROM verwaltung.protokoll WHERE typ = 'mandant.geloescht'");
        $n = json_decode((string) $p['nachher'], true);
        $this->gleich(['nutzer:' . $vera, ['mandant' => 'testneu'], (int) $kopf['nr'], $kopf['hash']], [$p['subjekt'], json_decode((string) $p['ids'], true), $n['buecher']['haupt']['protokoll_nr'], $n['buecher']['haupt']['protokoll_hash']], 'Protokoll: wer, welcher Mandant, letzter Kettenkopf je Buch');
        $this->gleich(0, (int) $v->fetchOne("SELECT count(*) FROM verwaltung.protokoll WHERE (ids::text || coalesce(vorher::text, '') || coalesce(nachher::text, '')) LIKE '%Neue Musterpraxis%'"), 'kein Eintrag der Protokollkette nennt den Namen des gelöschten Mandanten');
        $this->ist(is_file($ex2['export']['datei']), 'die Sicherung bleibt für den Mandanten liegen');
        $this->gleich(['testpraxis'], array_column($this->status(200, $this->http('GET', $vw), 'Liste danach')['mandanten'], 'kennung'), 'die Liste kennt den Mandanten nicht mehr');
        $this->cookie = null;
        $this->status(401, $this->anmeldeversuch($pkAnna)[0], 'der Passkey des gelöschten Mandanten führt nirgendwohin');
        $this->gleich(0, (int) $v->fetchOne('SELECT count(*) FROM verwaltung.kette_pruefen()'), 'Protokollkette der Verwaltung intakt');

        // ein Auftrag, der scheitert, hält den Dienst nicht auf und nennt ein Stichwort
        putenv('KUMERO_EXPORT_VERZEICHNIS=/dev/null/nicht-anlegbar');
        $this->anmelden($pkVera);
        $this->status(202, $this->http('POST', "$vw/testpraxis/export", []), 'Export, der scheitern wird');
        $this->ist(str_contains($this->cli(['command' => 'system:auftraege']), 'mandant_exportieren testpraxis: fehlgeschlagen (export.verzeichnis)'), 'gescheitert mit Stichwort');
        $letzter = $this->status(200, $this->http('GET', $vw), 'Liste')['auftraege'][0];
        $this->gleich(['fehlgeschlagen', ['code' => 'export.verzeichnis']], [$letzter['zustand'], $letzter['ergebnis']], 'der Grund steht am Auftrag');
        putenv('KUMERO_EXPORT_VERZEICHNIS');
        array_map('unlink', glob("$exporte/*") ?: []);
        @rmdir($exporte);
    }

    /**
     * Überführen des Bestands von 0.7.29 (BUECHER.md Abschnitt 12): Die Datenbank eines Mandanten – bisher Buch und
     * Nutzer in einem – wird das Buch „haupt“; die Nutzer ziehen mit gleicher ID in die neue Mandanten-Datenbank.
     * Der Bestand entsteht hier mit den Migrationen bis 0015, so wie 0.7.29 ihn angelegt hat.
     */
    public function testBuecherUeberfuehren(): void
    {
        $db = $this->db();
        $v = $db->verwaltung();
        $wurzel = dirname(__DIR__);
        $alt = sys_get_temp_dir() . '/kumero-altstand-' . bin2hex(random_bytes(4));
        mkdir("$alt/db/migrationen", 0700, true);
        copy("$wurzel/db/migrieren.sh", "$alt/db/migrieren.sh");
        copy("$wurzel/VERSION", "$alt/VERSION");
        foreach (glob("$wurzel/db/migrationen/00*.sql") as $f) {
            if ((int) substr(basename($f), 0, 4) <= 15) {
                copy($f, "$alt/db/migrationen/" . basename($f));
            }
        }
        $env = sprintf('KUMERO_TEST=1 KUMERO_DB_USER=%s KUMERO_FREIGABE=%s KUMERO_RELEASE_SHA256=%s KUMERO_FREIGABE_SCHLUESSEL=TEST ', escapeshellarg($this->u->su),
            escapeshellarg(trim((string) file_get_contents("$wurzel/VERSION"))), str_repeat('0', 64));
        $pruefer = new \Kumero\Plattform\Webauthn\Pruefer('kumero.test', [self::ORIGIN]);
        $passkeys = [];
        $ids = [];
        try {
            foreach (['testalt' => 'Altpraxis', 'spiel' => 'Spielwiese'] as $kennung => $name) {
                $d = "kumero_$kennung";
                $this->u->psql('postgres', "CREATE DATABASE $d OWNER kumero_owner TEMPLATE template0");
                $this->u->psql($d, "REVOKE ALL ON DATABASE $d FROM PUBLIC; GRANT CONNECT ON DATABASE $d TO kumero_app, kumero_lesen; REVOKE CREATE ON SCHEMA public FROM PUBLIC");
                Umgebung::sh($env . "bash $alt/db/migrieren.sh $d -h " . escapeshellarg($this->u->host));
                $m = $db->verbindung($d);
                $m->executeStatement('SELECT kern.buch_anlegen(?, ?, ?)', ['system:cli', $name, 'EUR']);
                $m->executeStatement('SELECT kern.rollenvorlagen_anlegen(?)', ['system:cli']);
                $v->executeStatement('SELECT verwaltung.mandant_eintragen(?, ?, ?, ?)', ['system:cli', $kennung, $d, $name]);
                foreach ([['Adam', 'Admin', 'Administrator'], ['Erika', 'Muster', 'Verantwortlicher']] as [$vorname, $familienname, $rolle]) {
                    $pk = new Authentifikator('kumero.test', self::ORIGIN, -7);
                    $pk->nutzerkennung = Base64Url::kodieren(random_bytes(32));
                    $v->executeStatement('SELECT verwaltung.pseudonym_eintragen(?, ?)', [$pk->nutzerkennung, $kennung]);
                    $id = (string) $m->fetchOne('SELECT kern.nutzer_anlegen(?, ?, ?, ?, ?, ?)', ['system:cli', $familienname, $vorname, strtolower($vorname) . '@example.org', null, $pk->nutzerkennung]);
                    $m->executeStatement('SELECT kern.zuweisen(?, ?, ?)', ['system:cli', "nutzer:$id", $rolle]);
                    $a = $pk->registrieren($c = random_bytes(32));
                    $reg = $pruefer->registrierung(Base64Url::dekodieren($a['response']['clientDataJSON']), Base64Url::dekodieren($a['response']['attestationObject']), $c);
                    $m->executeStatement("SELECT kern.anmelde_credential_uebernehmen(?, ?::uuid, ?, decode(?, 'hex'), ?, ?, ?, now())",
                        ['system:cli', $id, Base64Url::kodieren($reg['credential_id']), bin2hex($reg['public_key']), $reg['alg'], $reg['zaehler'], 'Altbestand']);
                    $passkeys["$kennung/$vorname"] = $pk;
                    $ids["$kennung/$vorname"] = $id;
                }
                $m->executeStatement("SELECT kern.einstellung_setzen(?, ?::uuid, 'kern.kontrast', '\"hoch\"')", ['nutzer:' . $ids["$kennung/Erika"], $ids["$kennung/Erika"]]);
                $m->executeStatement('SELECT kern.registrierung_ausstellen(?, ?::uuid, ?, ?::interval, ?)', ['system:cli', $ids["$kennung/Erika"], str_repeat('a', 64), '1 hour', str_repeat('b', 64)]);
                $m->executeStatement("SELECT kern.konto_anlegen('system:cli', '1000', 'Kasse', 'aktiv')");
                // danach das Update auf diese Version: alle Migrationen des Kerns
                Umgebung::sh($env . "bash $wurzel/db/migrieren.sh $d -h " . escapeshellarg($this->u->host));
            }
            $m = $db->verbindung('kumero_testalt');
            $this->ist((bool) $m->fetchOne("SELECT to_regclass('kern.nutzer') IS NOT NULL"), 'Bestand: Die Migration lässt gefüllte Nutzertabellen stehen, bis überführt ist');
            $kopf = $m->fetchAssociative('SELECT letzte_nr, letzter_hash FROM kern.kettenkopf WHERE kette = 2');
            $db->schliessen();

            // überführen
            $aus = $this->cli(['command' => 'system:buecher-ueberfuehren']);
            $this->ist(str_contains($aus, 'Mandant testalt: Buch „haupt“ in kumero_testalt__haupt, 2 Nutzer') && str_contains($aus, '1 Administrator(en)')
                && str_contains($aus, 'Mandant spiel: Buch „haupt“ in kumero_spiel__haupt (Testbuch)') && str_contains($aus, 'Überführen abgeschlossen'), "Ausgabe: $aus");
            $db = $this->db();
            $v = $db->verwaltung();
            $this->gleich([['haupt' => 'kumero_testalt__haupt'], 'kumero_testalt'], [$v->fetchAllKeyValue("SELECT kennung, datenbank FROM verwaltung.buch WHERE mandant = 'testalt'"),
                $v->fetchOne("SELECT datenbank FROM verwaltung.mandant WHERE kennung = 'testalt'")], 'Verwaltung: Buch eingetragen, die Mandanten-Datenbank trägt den bisherigen Namen');
            $m = $db->verbindung('kumero_testalt');
            $b = $db->verbindung('kumero_testalt__haupt');
            $this->gleich([$ids['testalt/Adam'], $ids['testalt/Erika']], $m->fetchFirstColumn('SELECT id FROM mandant.nutzer ORDER BY familienname'), 'Nutzer mit gleicher ID in der Mandanten-Datenbank');
            $this->gleich([[$ids['testalt/Adam']], 2, 1, 1], [$m->fetchFirstColumn('SELECT nutzer FROM mandant.administrator WHERE bis IS NULL'),
                (int) $m->fetchOne('SELECT count(*) FROM mandant.anmelde_credential'), (int) $m->fetchOne("SELECT count(*) FROM mandant.nutzer_einstellung WHERE schluessel = 'kern.kontrast'"),
                (int) $m->fetchOne("SELECT count(*) FROM mandant.registrierung WHERE code_hash = repeat('b', 64)")], 'Administrator, Passkeys, Einstellung und offener Zugang sind umgezogen');
            $this->gleich(['Altpraxis', 'euer', false], array_values($m->fetchAssociative("SELECT name, art, testbuch FROM mandant.buch WHERE kennung = 'haupt'")), 'Bücherliste des Mandanten');
            $this->ist(!$b->fetchOne("SELECT to_regclass('kern.nutzer') IS NOT NULL"), 'im Buch gibt es keine Nutzertabellen mehr');
            $this->gleich(['haupt', 'euer', false], array_values($b->fetchAssociative('SELECT kennung, art, testbuch FROM kern.buch')), 'das Buch kennt seine Kennung');
            $this->gleich([$kopf['letzter_hash'], 0], [$b->fetchOne('SELECT hash FROM kern.protokoll WHERE nr = ?', [$kopf['letzte_nr']]), (int) $b->fetchOne('SELECT count(*) FROM kern.kette_pruefen()')],
                'die Kette des Buchs ist unverändert fortgeschrieben – kein Hash hat sich geändert');
            $this->gleich(['Verantwortlicher'], $b->fetchFirstColumn("SELECT r.name FROM kern.zuweisung z JOIN kern.rolle r ON r.id = z.rolle WHERE z.gueltig_bis IS NULL"), 'im Buch bleibt die Verantwortliche; die Rolle Administrator ist dort beendet');
            $this->gleich([true, 'test'], array_values($db->verbindung('kumero_spiel__haupt')->fetchAssociative('SELECT testbuch, betriebsart::text FROM kern.buch')), 'der Spielmandant ist ein Testbuch (B8)');
            $db->schliessen();

            // die vorhandenen Passkeys gelten weiter
            $ich = $this->anmelden($passkeys['testalt/Erika']);
            $this->gleich(['nutzer:' . $ids['testalt/Erika'], 'testalt', 'hoch'], [$ich['subjekt'], $ich['mandant'], $ich['einstellungen']['kern.kontrast']], 'Erika meldet sich mit ihrem alten Passkey an; ihre Einstellung ist da');
            $this->gleich(['1000'], array_column($this->status(200, $this->http('GET', '/m/testalt/b/haupt/api/v1/konten'), 'Konten im Buch'), 'nr'), 'und arbeitet im Buch „haupt“ weiter');
            $this->gleich([['haupt'], [true]], [array_column($bl = $this->status(200, $this->http('GET', '/m/testalt/api/v1/buecher'), 'Bücher')['buecher'], 'kennung'), array_column($bl, 'zugang')], 'ihr Buch in der Liste');
            $this->anmelden($passkeys['testalt/Adam']);
            $this->gleich(2, count($this->status(200, $this->http('GET', '/m/testalt/api/v1/nutzer'), 'Nutzer')['nutzer']), 'Adam ist Administrator des Mandanten');
            $this->status(403, $this->http('GET', '/m/testalt/b/haupt/api/v1/buchungen'), 'und sieht weiter keine Buchungen (F43)');
            $this->anmelden($passkeys['spiel/Erika']);
            $this->gleich([true], array_column($this->status(200, $this->http('GET', '/m/spiel/api/v1/buecher'), 'Bücher')['buecher'], 'testbuch'), 'im Spielmandanten ist das Buch als Testbuch gekennzeichnet');

            // wiederholbar
            $this->ist(!str_contains($aus2 = $this->cli(['command' => 'system:buecher-ueberfuehren']), 'Mandant ') && str_contains($aus2, 'Überführen abgeschlossen'), 'ein zweiter Lauf findet nichts mehr');
            // Der Selbsttest prüft die Kette des Mandanten und die Ketten jedes Buchs (in der Testumgebung scheitern
            // andere Punkte, z. B. die PHP-Version – deshalb zählt hier nur die Ausgabe)
            try {
                $selbst = $this->u->cli(['command' => 'selbsttest']);
            } catch (\RuntimeException $x) {
                $selbst = $x->getMessage();
            }
            $this->ist(preg_match('/ok\s+Mandant spiel: Protokollkette/', $selbst) === 1 && preg_match('/ok\s+Mandant spiel, Buch haupt: Ketten/', $selbst) === 1
                && !str_contains($selbst, 'does not exist') && preg_match('/ok\s+Mandant spiel: Administrator/', $selbst) === 1,
                'Selbsttest: Kette des Mandanten, Administrator vorhanden, Ketten des Buchs intakt');
        } finally {
            Umgebung::sh('rm -rf ' . escapeshellarg($alt));
        }
    }
}
