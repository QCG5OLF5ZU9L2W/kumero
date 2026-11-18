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
 * Modul paperless (0.7.4, PAPERLESS.md, F92) gegen einen nachgebauten Paperless-Server: Zugang des Nutzers
 * mit verschlüsseltem Token, Entsperren mit Passphrase, Tags, Vorschau, geprüfter Belegverweis, Quelle des
 * Arbeitsvorrats, Tag nach dem Buchen entfernen und bei Storno wieder setzen. Nur synthetische Daten.
 */
final class PaperlessTest extends Rahmen
{
    private const ORIGIN = Umgebung::ORIGIN;
    private const MANDANT = 'kumero_plpraxis';
    private const P = '/m/plpraxis/api/v1';
    private const PORT = 8096;
    private const ADRESSE = 'http://localhost:8096';
    private const TOKEN = 'tok_muster_0815';
    private const PASS = 'eine lange Muster-Passphrase';

    private Umgebung $u;
    /** @var array<string, string> */
    private array $cookies = [];
    private int $schluessel = 0;
    /** @var resource|null */
    private $server = null;
    private string $zustand;

    public function vorher(): void
    {
        $this->u = new Umgebung([self::MANDANT]);
        $this->u->aufbauen();
        $this->zustand = sys_get_temp_dir() . '/paperless_nachbau_' . getmypid() . '.json';
        @unlink($this->zustand);
        $this->server = proc_open(['php', '-S', 'localhost:' . self::PORT, __DIR__ . '/Hilfe/paperless_nachbau.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rohre, null, ['PAPERLESS_NACHBAU_ZUSTAND' => $this->zustand] + getenv());
        for ($i = 0; $i < 50 && @fsockopen('localhost', self::PORT) === false; $i++) {
            usleep(100000);
        }
    }

    public function nachher(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        @unlink($this->zustand);
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
        $r = Request::create('https://kumero.test' . $pfad, $methode, [], $this->cookies, [], $server, $json === null ? null : json_encode($json === [] ? new \stdClass() : $json));
        $antwort = (new Kernel($k, new Datenbank($k)))->bearbeiten($r);
        foreach ($antwort->headers->getCookies() as $c) {
            if ($c->isCleared()) {
                unset($this->cookies[$c->getName()]);
            } else {
                $this->cookies[$c->getName()] = (string) $c->getValue();
            }
        }
        return [$antwort, json_decode((string) $antwort->getContent(), true)];
    }

    private function status(int $erwartet, array $erg, string $was): mixed
    {
        $this->gleich($erwartet, $erg[0]->getStatusCode(), $was . ' – ' . substr((string) $erg[0]->getContent(), 0, 400));
        return $erg[1];
    }

    /** @return array{0: string, 1: Authentifikator} */
    private function nutzer(string $name, bool $verwalter = false): array
    {
        $aus = $this->u->cli(['command' => 'nutzer:anlegen', 'name' => $name] + ($verwalter ? ['--verwalter' => true] : []));
        preg_match('/Nutzer angelegt: ([0-9a-f-]{36})/', $aus, $m);
        preg_match('/#registrierung=([A-Za-z0-9_-]+)/', $aus, $t);
        $pk = new Authentifikator('kumero.test', self::ORIGIN, -7);
        $this->cookies = [];
        $opt = $this->status(200, $this->http('POST', '/api/v1/registrierung/optionen', ['token' => $t[1]]), "Optionen $name");
        $this->status(201, $this->http('POST', '/api/v1/registrierung', ['token' => $t[1]] + $pk->registrieren(Base64Url::dekodieren($opt['challenge']))), "$name registriert");
        return [$m[1], $pk];
    }

    private function anmelden(Authentifikator $pk): void
    {
        $this->cookies = [];
        $opt = $this->status(200, $this->http('POST', '/api/v1/anmeldung/optionen'), 'Anmeldeoptionen');
        $this->status(200, $this->http('POST', '/api/v1/anmeldung', $pk->anmelden(Base64Url::dekodieren($opt['challenge']))), 'angemeldet');
    }

    /** @return list<int> Tags eines Dokuments im nachgebauten Paperless */
    private function tagsIn(int $dokument): array
    {
        return json_decode((string) file_get_contents($this->zustand), true)['documents'][$dokument]['tags'];
    }

    /** Stellt den nachgebauten Server auf eine Paperless-Version und die API-Versionen um, die sie bedient. */
    private function paperlessIst(string $version, array $api): void
    {
        $z = json_decode((string) file_get_contents($this->zustand), true);
        $z['x_version'] = $version;
        $z['api'] = $api;
        file_put_contents($this->zustand, json_encode($z));
    }

    /** @return list<string> */
    private function vorrat(string $zustand): array
    {
        $l = $this->status(200, $this->http('GET', self::P . "/arbeitsvorrat/eintraege?zustand=$zustand"), "Arbeitsvorrat $zustand");
        $this->gleich([], $l['quellen_ausgefallen'], 'keine Quelle ausgefallen');
        return array_column($l['eintraege'], 'betreff');
    }

    public function testPaperless(): void
    {
        $p = self::P;
        $pl = "$p/paperless";
        [$erika, $pkErika] = $this->nutzer('Erika Test', true);
        $this->anmelden($pkErika);
        $this->u->cli(['command' => 'mandant:anlegen', 'kennung' => 'plpraxis', 'name' => 'Paperlesspraxis']);
        $this->u->cli(['command' => 'zugang:gewaehren', 'nutzer' => $erika, 'mandant' => 'plpraxis']);
        foreach (['buchen', 'arbeitsvorrat', 'paperless'] as $modul) {
            $this->u->cli(['command' => 'modul:aktivieren', 'modul' => $modul, 'mandant' => 'plpraxis']);
        }
        foreach ([['1200', 'Bank', 'aktiv'], ['4930', 'Bürobedarf', 'aufwand']] as [$nr, $bez, $art]) {
            $this->status(201, $this->http('POST', "$p/konten", ['nr' => $nr, 'bezeichnung' => $bez, 'kontoart' => $art]), "Konto $nr");
        }
        $this->status(201, $this->http('POST', "$p/geschaeftsjahre", ['kennung' => '2026', 'beginn' => '2026-01-01', 'ende' => '2026-12-31']), 'Geschäftsjahr');
        $this->status(201, $this->http('POST', "$p/nummernkreise", ['kuerzel' => 'ALLG', 'bezeichnung' => 'Allgemein']), 'Nummernkreis');
        $this->status(200, $this->http('PUT', "$p/nummernkreise/ALLG/jahre/2026", ['vorlage' => 'ALLG-{JJJJ}-{NR:5}', 'zuruecksetzen' => true]), 'Nummernkreis festgelegt');
        $this->status(201, $this->http('POST', "$p/buchungsarten", ['kuerzel' => 'BANK', 'bezeichnung' => 'Bank', 'nummernkreis' => 'ALLG']), 'Buchungsart');

        // --- Vorgabe des Mandanten (L11) und Verbindung des Nutzers (L2) ---
        $this->gleich(['vorgabe' => null, 'verbindungen' => [], 'passphrase_min' => 10], $this->status(200, $this->http('GET', "$pl/verbindungen"), 'noch keine Verbindung'), 'leer');
        $this->status(200, $this->http('PUT', "$pl/vorgabe", ['adresse' => self::ADRESSE . '/', 'bezeichnung' => 'Praxisarchiv']), 'Vorgabe gesetzt');
        $this->gleich(['adresse' => self::ADRESSE, 'bezeichnung' => 'Praxisarchiv'], $this->status(200, $this->http('GET', "$pl/verbindungen"), 'Vorgabe')['vorgabe'], 'Vorgabe ohne Schrägstrich am Ende');
        $f = $this->status(400, $this->http('POST', "$pl/verbindungen", ['adresse' => 'http://paperless.example.org', 'token' => 'x', 'passphrase' => 'kurz']), 'ungültige Verbindung');
        $this->gleich(['adresse', 'passphrase', 'token'], (function () use ($f) { $x = array_column($f['fehler'], 'feld'); sort($x); return $x; })(), 'nur https, Token, Passphrase mit Mindestlänge');
        $this->status(409, $this->http('POST', "$pl/verbindungen", ['adresse' => self::ADRESSE, 'token' => 'falsches_token', 'passphrase' => self::PASS]), 'Paperless lehnt das Token ab');
        $this->status(400, $this->http('POST', "$pl/verbindungen", ['adresse' => self::ADRESSE, 'token' => self::TOKEN, 'passphrase' => self::PASS, 'tag_offen' => 'gibt es nicht']), 'Tag muss es in Paperless geben (L9)');
        $v = $this->status(201, $this->http('POST', "$pl/verbindungen", ['adresse' => self::ADRESSE, 'bezeichnung' => 'Praxisarchiv', 'token' => self::TOKEN,
            'passphrase' => self::PASS, 'tag_offen' => 'zu buchen']), 'Verbindung angelegt');
        $id = $v['id'];
        $this->gleich([['Praxisarchiv', self::ADRESSE, 'zu buchen', true]], array_map(fn ($x) => [$x['bezeichnung'], $x['adresse'], $x['tag_offen'], $x['entsperrt']], $v['verbindungen']), 'angelegt und gleich entsperrt');
        $this->ist(!str_contains(json_encode($v), self::TOKEN), 'das Token kommt nie zurück');
        $this->status(409, $this->http('POST', "$pl/verbindungen", ['adresse' => self::ADRESSE, 'token' => self::TOKEN, 'passphrase' => self::PASS]), 'je Adresse eine Verbindung');

        // Ablage: nur Chiffren; ohne den Sitzungsschlüssel aus dem Cookie ist nichts zu gewinnen
        $k = Konfiguration::laden();
        $db = new Datenbank($k);
        $m = $db->verbindung(self::MANDANT);
        $roh = $m->fetchAssociative("SELECT encode(token_chiffre, 'escape') AS c, (SELECT encode(chiffre, 'escape') FROM paperless.entsperrt) AS s FROM paperless.verbindung");
        $this->ist(!str_contains((string) $roh['c'], self::TOKEN) && !str_contains((string) $roh['s'], self::TOKEN), 'Token liegt nirgends im Klartext');
        $this->gleich(0, (int) $m->fetchOne("SELECT count(*) FROM kern.protokoll WHERE (vorher::text || nachher::text) LIKE '%tok_muster%'"), 'Token nicht im Protokoll');
        $s = $this->status(200, $this->http('GET', "$pl/verbindungen/$id/status"), 'Verbindungstest');
        $this->gleich([true, '3.4.2', 'erika.muster', true], [$s['erreichbar'], $s['version'], $s['konto'], $s['tag_offen_vorhanden']], 'erreichbar, Version, Konto, Tag vorhanden');

        // --- API-Versionen von Paperless ab 3.x (F94): mitführen und von selbst anwenden ---
        $this->gleich([10, 10, false], [$s['api_gemeldet'], $s['api_verwendet'], $s['neuer_als_geprueft']], 'Paperless 3.4.2: meldet API 10, kumero spricht 10');
        $vb = fn () => $this->status(200, $this->http('GET', "$pl/verbindungen"), 'Verbindung')['verbindungen'][0];
        $this->gleich(['3.4.2', 10, 10], [$vb()['paperless_version'], $vb()['api_gemeldet'], $vb()['api_verwendet']], 'Versionen an der Verbindung gemerkt');
        $this->status(409, $this->http('POST', "$pl/verweis", ['link' => self::ADRESSE . '/documents/5213/']), 'Aufruf in Version 10');
        // Der Aufruf, an dem 0.7.4 scheiterte: Version 5 beantwortet Paperless 3.x mit 406
        $alt = stream_context_create(['http' => ['header' => "Authorization: Token " . self::TOKEN . "\r\nAccept: application/json; version=5", 'ignore_errors' => true]]);
        $this->gleich(['HTTP/1.1 406 Not Acceptable', '{"detail":"Invalid version in \"Accept\" header."}'],
            [(function () use ($alt, &$http_response_header) { $x = file_get_contents(self::ADRESSE . '/api/tags/', false, $alt); return [$http_response_header[0], $x]; })()][0],
            'Nachbau verhält sich wie Paperless 3.4.2: version=5 → 406');
        // Paperless 3.0 mit API 9 und 10, kumero hatte zuletzt 9 gemerkt → bleibt bei dem, was gilt
        $this->u->psql(self::MANDANT, 'UPDATE paperless.verbindung SET api_verwendet = 9');
        $this->gleich(4, count($this->status(200, $this->http('GET', "$pl/verbindungen/$id/tags"), 'Aufruf in Version 9')), 'API-Version 9 wird gesprochen');
        $this->gleich(9, $vb()['api_verwendet'], 'ohne Anlass kein Wechsel');
        // Paperless aktualisiert und bedient die gemerkte Version nicht mehr: 406 → neu feststellen, Aufruf wiederholen
        $this->paperlessIst('3.4.9', [10]);
        $this->gleich(4, count($this->status(200, $this->http('GET', "$pl/verbindungen/$id/tags"), 'nach dem Update von Paperless')), 'von selbst auf die neue Version gewechselt');
        $this->gleich(['3.4.9', 10, 10], [$vb()['paperless_version'], $vb()['api_gemeldet'], $vb()['api_verwendet']], 'neue Versionen gemerkt');
        // Paperless neuer als geprüft: meldet API 11, bedient 10 noch → kumero arbeitet mit 10 und sagt es
        $this->paperlessIst('3.9.0', [10, 11]);
        $s = $this->status(200, $this->http('GET', "$pl/verbindungen/$id/status"), 'neueres Paperless');
        $this->gleich([true, '3.9.0', 11, 10, true], [$s['erreichbar'], $s['version'], $s['api_gemeldet'], $s['api_verwendet'], $s['neuer_als_geprueft']], 'neuer als geprüft: höchste bekannte Version, mit Hinweis');
        // Paperless bedient keine bekannte Version mehr → klare Absage statt „unerwartet geantwortet“
        $this->paperlessIst('4.1.0', [11, 12]);
        $s = $this->status(200, $this->http('GET', "$pl/verbindungen/$id/status"), 'zu neues Paperless');
        $this->gleich([false, 'api_unbekannt', '4.1.0'], [$s['erreichbar'], $s['grund'], $s['version']], 'keine bekannte API-Version: kumero muss aktualisiert werden');
        $this->gleich('paperless.api_unbekannt', $this->status(409, $this->http('GET', "$pl/verbindungen/$id/tags"), 'Aufruf an zu neues Paperless')['code'], 'auch im Aufruf mit Grund');
        // Paperless vor 3.0 wird abgelehnt, obwohl es API 9 spricht
        $this->paperlessIst('2.20.13', [1, 2, 3, 4, 5, 6, 7, 8, 9]);
        $s = $this->status(200, $this->http('GET', "$pl/verbindungen/$id/status"), 'altes Paperless');
        $this->gleich([false, 'zu_alt', '2.20.13'], [$s['erreichbar'], $s['grund'], $s['version']], 'Paperless vor 3.0 abgelehnt');
        // kein Paperless: Antwort ohne die Kopfzeilen X-Version und X-Api-Version gibt es beim Nachbau nicht – geprüft wird die Absage beim Anlegen
        $this->paperlessIst('3.4.2', [9, 10]);
        $s = $this->status(200, $this->http('GET', "$pl/verbindungen/$id/status"), 'wieder 3.4.2');
        $this->gleich([true, '3.4.2', 10], [$s['erreichbar'], $s['version'], $s['api_verwendet']], 'zurück auf dem Stand des Referenzbetriebs');
        $this->ist((int) $m->fetchOne("SELECT count(*) FROM kern.protokoll WHERE typ = 'paperless.version_festgestellt'") >= 4, 'jeder Wechsel der Version steht im Protokoll');
        $t = \Kumero\Modul\Paperless\Paperless::tabelle();
        $this->gleich(['3.0', ['9', '10']], [$t['paperless_ab'], array_map('strval', array_keys($t['api']))], 'gepflegte Tabelle: Paperless ab 3.0 mit den API-Versionen 9 und 10');

        // --- Sperren und Entsperren mit Passphrase (L3a) ---
        $schluessel = $this->cookies['__Host-kumero-paperless'];
        unset($this->cookies['__Host-kumero-paperless']);
        $this->gleich(false, $this->status(200, $this->http('GET', "$pl/verbindungen"), 'ohne Cookie')['verbindungen'][0]['entsperrt'], 'ohne den Sitzungsschlüssel im Cookie gesperrt');
        $this->status(409, $this->http('GET', "$pl/verbindungen/$id/status"), 'gesperrt: kein Aufruf an Paperless');
        $this->cookies['__Host-kumero-paperless'] = $schluessel;
        $this->status(200, $this->http('POST', "$pl/sperren", []), 'gesperrt');
        $this->ist(!isset($this->cookies['__Host-kumero-paperless']) && (int) $m->fetchOne('SELECT count(*) FROM paperless.entsperrt') === 0, 'Sperren räumt Cookie und Sitzungschiffre ab');
        $this->gleich('paperless.passphrase', $this->status(403, $this->http('POST', "$pl/entsperren", ['passphrase' => 'falsche Passphrase']), 'falsche Passphrase')['code'], 'falsche Passphrase abgewiesen');
        $this->gleich(1, $this->status(200, $this->http('POST', "$pl/entsperren", ['passphrase' => self::PASS]), 'entsperrt')['entsperrt'], 'eine Verbindung entsperrt');

        // --- Tags auswählen (L4, L4a) ---
        $tags = $this->status(200, $this->http('GET', "$pl/verbindungen/$id/tags"), 'Tags aus Paperless');
        $this->gleich(['Kostenstelle Empfang', 'Praxis', 'Privat', 'zu buchen'], array_column($tags, 'name'), 'alle Tags des Kontos, sortiert');
        $this->gleich([4 => false, 2 => false, 3 => false, 1 => true], array_column($tags, 'arbeitstag', 'id'), 'Tag für offene Belege als Arbeits-Tag gekennzeichnet');
        $this->status(201, $this->http('POST', "$p/tags", ['name' => 'Praxis']), 'gleichnamiges Tag von Hand');
        $u = $this->status(200, $this->http('PUT', "$pl/verbindungen/$id/tags", ['tags' => [2, 4]]), 'Tags übernommen');
        $this->gleich([['Kostenstelle Empfang'], ['Praxis']], [$u['angelegt'], $u['uebergangen']], 'neu angelegt; vorhandener Name bleibt, wie er ist');
        $this->gleich(['paperless', 4], array_values($m->fetchAssociative("SELECT herkunft, paperless_id FROM kern.tag WHERE name = 'Kostenstelle Empfang'")), 'Tag mit Herkunft Paperless im Mandanten');

        // --- Vorschau und Original: durchgereicht, nicht gespeichert ---
        $link = self::ADRESSE . '/documents/5210/';
        $bild = $this->http('GET', "$pl/vorschau?link=" . rawurlencode($link));
        $this->gleich([200, 'image/png', 'no-store, private'], [$bild[0]->getStatusCode(), $bild[0]->headers->get('Content-Type'), $bild[0]->headers->get('Cache-Control')], 'Vorschaubild mit no-store');
        $orig = $this->http('GET', "$pl/original?link=" . rawurlencode($link));
        $this->gleich([200, 'application/pdf', "%PDF-1.4 Muster 5210\n"], [$orig[0]->getStatusCode(), $orig[0]->headers->get('Content-Type'), $orig[0]->getContent()], 'Original, nicht die Archivfassung (F10)');
        $this->status(404, $this->http('GET', "$pl/vorschau?link=" . rawurlencode('https://anderswo.example.org/documents/1/')), 'fremde Adressen lädt kumero nie');
        $this->status(404, $this->http('GET', "$pl/vorschau?link=" . rawurlencode(self::ADRESSE . '/documents/99/')), 'unbekannter Beleg');

        // --- Belegverweis mit Prüfung (F10, F51 Nr. 4) ---
        $vw = $this->status(200, $this->http('POST', "$pl/verweis", ['link' => self::ADRESSE . '/documents/5210/details']), 'Belegverweis geprüft');
        $this->gleich(['link' => $link, 'md5' => md5("%PDF-1.4 Muster 5210\n"), 'sha256' => hash('sha256', "%PDF-1.4 Muster 5210\n")], $vw, 'einheitlicher Link, MD5 und SHA-256 des Originals');
        $this->gleich('paperless.pruefsumme', $this->status(409, $this->http('POST', "$pl/verweis", ['link' => self::ADRESSE . '/documents/5213/']), 'Prüfsumme weicht ab')['code'], 'bei Abweichung keine Verknüpfung');
        // Paperless, das statt der MD5 die SHA-256 des Originals nennt: geprüft wird gegen die passende Summe
        $z = json_decode((string) file_get_contents($this->zustand), true);
        $z['pruefsumme'] = 'sha256';
        file_put_contents($this->zustand, json_encode($z));
        $vw2 = $this->status(200, $this->http('POST', "$pl/verweis", ['link' => self::ADRESSE . '/documents/5210/']), 'Belegverweis bei SHA-256-Angabe');
        $this->gleich([$vw['md5'], $vw['sha256']], [$vw2['md5'], $vw2['sha256']], 'gleicher geprüfter Verweis, gleich welche Summe Paperless nennt');
        $this->status(409, $this->http('POST', "$pl/verweis", ['link' => self::ADRESSE . '/documents/5213/']), 'falsche Angabe bleibt falsch');
        unset($z['pruefsumme']);
        file_put_contents($this->zustand, json_encode($z));

        // --- Quelle des Arbeitsvorrats: Belege mit dem Tag für offene Belege ---
        $this->gleich(['Rechnung Musterlieferant RE-0815', 'Kassenbon Musterbäcker'], $this->vorrat('offen'), 'zwei Belege tragen das Tag');
        $l = $this->status(200, $this->http('GET', "$p/arbeitsvorrat/eintraege"), 'Liste')['eintraege'];
        $this->gleich(['Musterlieferant GmbH', '2026-03-02', 'rechnung', [['link' => $link]], ['paperless']],
            [$l[0]['gegenpartei'], $l[0]['datum'], $l[0]['art'], $l[0]['belege'], $l[0]['quellen']], 'Korrespondent, Datum, Art aus dem Dokumenttyp, Beleglink');
        $this->gleich(['Paperless', 'Datei'], array_column($this->status(200, $this->http('GET', "$p/arbeitsvorrat/eintraege/{$l[0]['id']}"), 'Eintrag')['beitraege'][0]['angaben'], 'bezeichnung'), 'Angaben der Quelle');
        $this->gleich(2, count($this->vorrat('offen')), 'erneutes Öffnen legt nichts doppelt an');

        // --- Zuordnung Paperless-Feld → Buchungssatz (F95): Vorgabe des Mandanten, eigene Einstellung überschreibt ---
        $zu = $this->status(200, $this->http('GET', "$pl/verbindungen/$id/zuordnung"), 'Zuordnung lesen');
        $this->gleich([null, null, true, 8], [$zu['mandant'], $zu['eigene'], $zu['darf_vorgeben'], count($zu['felder'])], 'noch keine Zuordnung, acht benutzerdefinierte Felder');
        $this->gleich([['art' => 'feld', 'name' => 'Erkannt: Rechnungsnummer']], $zu['vorschlag']['belegnummer'], 'Vorschlag aus dem Feldnamen');
        $this->gleich([['Erkannt: Betrag Steuer'], ['Erkannt: Betrag netto'], ['Erkannt: Betrag brutto']], [array_column($zu['vorschlag']['steuer'], 'name'), array_column($zu['vorschlag']['netto'], 'name'), array_column($zu['vorschlag']['betrag'], 'name')], 'Vorschlag für Steuer, Netto und Brutto');
        $this->gleich(['Erkannt: Rechnungssteller', 'korrespondent'], array_column($zu['vorschlag']['gegenpartei'], 'name'), 'Vorschlag: erst das Feld, sonst der Korrespondent');
        $this->status(400, $this->http('PUT', "$pl/verbindungen/$id/zuordnung", ['fuer' => 'mandant', 'regeln' => ['konto' => [['art' => 'feld', 'name' => 'x']]]]), 'Konto ist kein Ziel (Z2)');
        $feld = fn (string $n) => ['art' => 'feld', 'name' => "Erkannt: $n"];
        $vorgabe = ['belegnummer' => [$feld('Rechnungsnummer')], 'datum' => [$feld('Rechnungsdatum'), ['art' => 'fest', 'name' => 'erstellt']],
            'gegenpartei' => [$feld('Rechnungssteller'), ['art' => 'fest', 'name' => 'korrespondent']], 'art' => [$feld('Belegart'), ['art' => 'fest', 'name' => 'dokumenttyp']],
            'betrag' => [$feld('Betrag brutto')], 'netto' => [$feld('Betrag netto')], 'steuer' => [$feld('Betrag Steuer')],
            'zahlweise' => [['art' => 'feld', 'name' => 'Zahlart']], 'richtung' => [['art' => 'tag', 'name' => 'zu buchen', 'wert' => 'ausgabe']], 'anzeigen' => [$feld('Betrag netto')]];
        $gespeichert = $this->status(200, $this->http('PUT', "$pl/verbindungen/$id/zuordnung", ['fuer' => 'mandant', 'regeln' => $vorgabe]), 'Vorgabe des Mandanten gesetzt')['mandant'];
        ksort($gespeichert);
        $erwartet = $vorgabe;
        ksort($erwartet);
        $this->gleich($erwartet, $gespeichert, 'Vorgabe gespeichert');
        // abgleich=nein fragt die Quellen nicht: Die neue Zuordnung wirkt erst mit dem Abgleich (POST /abgleich oder Liste ohne den Schalter)
        $this->gleich(null, $this->status(200, $this->http('GET', "$p/arbeitsvorrat/eintraege?abgleich=nein"), 'Liste ohne Quellen')['eintraege'][0]['belegnummer'], 'ohne Abgleich bleibt der Eintrag, wie er war');
        $ab = $this->status(200, $this->http('POST', "$p/arbeitsvorrat/abgleich", []), 'Abgleich auf Anforderung');
        $this->gleich([[], true], [$ab['quellen_ausgefallen'], is_int($ab['dauer_ms']['paperless'] ?? null)], 'Abgleich nennt Ausfälle und die Dauer je Quelle');
        $this->gleich('RE-0815', $this->status(200, $this->http('GET', "$p/arbeitsvorrat/eintraege?abgleich=nein"), 'Liste nach Abgleich')['eintraege'][0]['belegnummer'], 'danach steht die Zuordnung am Eintrag');
        $l = $this->status(200, $this->http('GET', "$p/arbeitsvorrat/eintraege"), 'Liste mit Zuordnung')['eintraege'];
        $this->gleich(['RE-0815', '2026-02-27', 'Musterlieferant GmbH & Co. KG', 'rechnung', '109.43', '91.96', '17.47', 'unbar', 'ausgabe'],
            [$l[0]['belegnummer'], $l[0]['datum'], $l[0]['gegenpartei'], $l[0]['art'], $l[0]['betrag'], $l[0]['netto'], $l[0]['steuer'], $l[0]['zahlweise'], $l[0]['richtung']],
            'offener Eintrag neu gebildet: Felder aus den benutzerdefinierten Feldern (Z10), Auswahlfeld über die ID der Option');
        $this->gleich(['kassenbon', null, '4.21', 'bar', 'Musterbäcker', '2026-03-03'], [$l[1]['art'], $l[1]['betrag'], $l[1]['netto'], $l[1]['zahlweise'], $l[1]['gegenpartei'], $l[1]['datum']],
            'zweiter Beleg: Art als Stichwort, fremde Währung nicht als Betrag, leere Felder fallen auf die nächste Quelle');
        $this->ist(str_contains(json_encode($l[1]['hinweise'], JSON_UNESCAPED_UNICODE), 'USD 4.50') && str_contains(json_encode($l[1]['hinweise'], JSON_UNESCAPED_UNICODE), 'Nur der Nettobetrag'), 'was nicht passt, steht als Hinweis da – nicht geraten');
        // eigene Einstellung überschreibt je Ziel
        $e = $this->status(200, $this->http('PUT', "$pl/verbindungen/$id/zuordnung", ['fuer' => 'mich', 'regeln' => ['gegenpartei' => [['art' => 'fest', 'name' => 'korrespondent']]]]), 'eigene Zuordnung');
        $this->gleich([[['art' => 'fest', 'name' => 'korrespondent']], [$feld('Rechnungsnummer')]], [$e['wirksam']['gegenpartei'], $e['wirksam']['belegnummer']], 'eigenes Ziel überschreibt, der Rest kommt vom Mandanten');
        $this->gleich('Musterlieferant GmbH', $this->status(200, $this->http('GET', "$p/arbeitsvorrat/eintraege"), 'Liste')['eintraege'][0]['gegenpartei'], 'wirkt beim nächsten Abgleich');
        $this->gleich(null, $this->status(200, $this->http('PUT', "$pl/verbindungen/$id/zuordnung", ['fuer' => 'mich', 'regeln' => null]), 'eigene gelöscht')['eigene'], 'zurück zur Vorgabe');
        $l = $this->status(200, $this->http('GET', "$p/arbeitsvorrat/eintraege"), 'Liste')['eintraege'];

        // gebucht → Tag in Paperless wird entfernt (L10, B14), nur dieses eine
        $b = $this->status(201, $this->http('POST', "$p/buchungen", ['buchungsart' => 'BANK', 'belegdatum' => '2026-03-02', 'buchungsdatum' => '2026-03-04', 'text' => 'Musterlieferant GmbH',
            'belege' => [$vw], 'zeilen' => [['konto' => '4930', 'seite' => 'S', 'betrag' => '109.43'], ['konto' => '1200', 'seite' => 'H', 'betrag' => '109.43']], 'vorbereiten' => true]), 'Entwurf mit geprüftem Beleg');
        $this->gleich([$vw], array_map(function ($x) { ksort($x); return $x; }, $b['belege']), 'geprüfter Verweis am Entwurf');
        $this->status(200, $this->http('POST', "$p/arbeitsvorrat/eintraege/{$l[0]['id']}/buchung", ['buchung' => $b['id']]), 'verknüpft');
        $this->status(200, $this->http('POST', "$p/freigaben", ['buchungen' => [$b['id']]]), 'festgeschrieben');
        $this->gleich(['Kassenbon Musterbäcker'], $this->vorrat('offen'), 'gebuchter Beleg nicht mehr offen');
        $this->gleich([2], $this->tagsIn(5210), 'Tag für offene Belege in Paperless entfernt, andere Tags unberührt');
        $this->gleich(['Rechnung Musterlieferant RE-0815'], $this->vorrat('gebucht'), 'bleibt als gebucht im Vorrat');

        // Storno → Tag kommt zurück (B9)
        $st = $this->status(201, $this->http('POST', "$p/buchungen/{$b['id']}/storno", []), 'Storno-Entwurf');
        $this->status(200, $this->http('POST', "$p/buchungen/{$st['id']}/vorbereiten", null, ['If-Match' => '*']), 'Storno vorbereitet');
        $this->status(200, $this->http('POST', "$p/freigaben", ['buchungen' => [$st['id']]]), 'Storno festgeschrieben');
        $this->gleich(2, count($this->vorrat('offen')), 'nach dem Storno wieder offen');
        $this->gleich(2, count($this->vorrat('offen')), 'und bleibt offen');
        $this->gleich([2, 1], $this->tagsIn(5210), 'Tag für offene Belege wieder gesetzt');

        // Tag in Paperless von Hand entfernt → die Quelle zieht zurück
        $z = json_decode((string) file_get_contents($this->zustand), true);
        $z['documents'][5211]['tags'] = [];
        file_put_contents($this->zustand, json_encode($z));
        $this->gleich(['Rechnung Musterlieferant RE-0815'], $this->vorrat('offen'), 'ohne Tag nicht mehr im Vorrat');

        // gesperrt: der Arbeitsvorrat zeigt, was da ist, und ruft Paperless nicht auf
        $this->status(200, $this->http('POST', "$pl/sperren", []), 'gesperrt');
        $this->gleich(['Rechnung Musterlieferant RE-0815'], $this->vorrat('offen'), 'gesperrt: Vorrat bleibt, wie er ist');
        $this->gleich('paperless.gesperrt', $this->status(409, $this->http('POST', "$pl/verweis", ['link' => $link]), 'gesperrt')['code'], 'gesperrt: keine Prüfung');

        // --- ein anderer Nutzer sieht die Verbindung nicht; Löschen ---
        [$azubi, $pkAzubi] = $this->nutzer('Azubi Test');
        $this->u->cli(['command' => 'zugang:gewaehren', 'nutzer' => $azubi, 'mandant' => 'plpraxis', '--rolle' => ['Erfasser']]);
        foreach (['arbeitsvorrat', 'paperless'] as $modul) {
            $this->u->cli(['command' => 'modul:aktivieren', 'modul' => $modul, 'mandant' => 'plpraxis']);
        }
        $this->anmelden($pkAzubi);
        $x = $this->status(200, $this->http('GET', "$pl/verbindungen"), 'Verbindungen des Azubi');
        $this->gleich([[], 'Praxisarchiv'], [$x['verbindungen'], $x['vorgabe']['bezeichnung']], 'eigene Verbindungen je Nutzer, Vorgabe für alle');
        $this->status(404, $this->http('GET', "$pl/verbindungen/$id/status"), 'fremde Verbindung unbekannt');
        $this->status(403, $this->http('PUT', "$pl/vorgabe", ['adresse' => self::ADRESSE]), 'Vorgabe nur mit Recht');
        $this->gleich(['Rechnung Musterlieferant RE-0815'], $this->vorrat('offen'), 'Einträge gehören dem Mandanten (W8)');
        $this->anmelden($pkErika);
        $this->status(204, $this->http('DELETE', "$pl/verbindungen/$id", null), 'Verbindung gelöscht');
        $this->gleich(0, (int) $m->fetchOne('SELECT count(*) FROM kern.kette_pruefen()'), 'Ketten des Mandanten intakt');
        $db->schliessen();
    }
}
