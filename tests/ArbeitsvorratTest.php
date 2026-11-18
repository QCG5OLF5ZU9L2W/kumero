<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

use Kumero\Modul\Arbeitsvorrat\Dienst;
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
 * Modul arbeitsvorrat (0.7.3, ARBEITSVORRAT.md, F89): Einträge von Hand, Vertrag für Quellen
 * (einliefern, zurückziehen, zusammenführen über den Beleglink), Zustände aus dem Stand der
 * Buchungen, Verwerfen, Regeln, Rechte. Ende-zu-Ende gegen eine echte PostgreSQL, nur synthetische Daten.
 */
final class ArbeitsvorratTest extends Rahmen
{
    private const ORIGIN = Umgebung::ORIGIN;
    private const MANDANT = 'kumero_vorratpraxis';
    private const P = '/m/vorratpraxis/api/v1';

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

    /** @return array{0: string, 1: Authentifikator} */
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

    /** @return list<string> Betreffe der Liste in einem Zustand */
    private function betreffe(string $zustand, string $filter = ''): array
    {
        return array_column($this->status(200, $this->http('GET', self::P . "/arbeitsvorrat/eintraege?zustand=$zustand$filter"), "Liste $zustand")['eintraege'], 'betreff');
    }

    public function testArbeitsvorrat(): void
    {
        $p = self::P;
        $v = "$p/arbeitsvorrat";
        [$erika, $pkErika] = $this->nutzer('Erika Test', true);
        $this->anmelden($pkErika);
        $this->u->cli(['command' => 'mandant:anlegen', 'kennung' => 'vorratpraxis', 'name' => 'Vorratpraxis']);
        $this->u->cli(['command' => 'zugang:gewaehren', 'nutzer' => $erika, 'mandant' => 'vorratpraxis']);
        $this->status(404, $this->http('GET', "$v/eintraege"), 'ohne aktives Modul kein Endpunkt');
        $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'buchen', 'mandant' => 'vorratpraxis']);
        $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'arbeitsvorrat', 'mandant' => 'vorratpraxis']);
        foreach ([['1000', 'Kasse', 'aktiv'], ['1200', 'Bank', 'aktiv'], ['4930', 'Bürobedarf', 'aufwand'], ['8100', 'Honorare', 'ertrag']] as [$nr, $bez, $art]) {
            $this->status(201, $this->http('POST', "$p/konten", ['nr' => $nr, 'bezeichnung' => $bez, 'kontoart' => $art]), "Konto $nr");
        }
        $this->status(201, $this->http('POST', "$p/geschaeftsjahre", ['kennung' => '2026', 'beginn' => '2026-01-01', 'ende' => '2026-12-31']), 'Geschäftsjahr');
        $this->status(201, $this->http('POST', "$p/nummernkreise", ['kuerzel' => 'ALLG', 'bezeichnung' => 'Allgemein']), 'Nummernkreis');
        $this->status(200, $this->http('PUT', "$p/nummernkreise/ALLG/jahre/2026", ['vorlage' => 'ALLG-{JJJJ}-{NR:5}', 'zuruecksetzen' => true]), 'Nummernkreis festgelegt');
        $this->status(201, $this->http('POST', "$p/buchungsarten", ['kuerzel' => 'BANK', 'bezeichnung' => 'Bank', 'nummernkreis' => 'ALLG']), 'Buchungsart');

        // --- leer, Menü mit Zähler ---
        $l = $this->status(200, $this->http('GET', "$v/eintraege"), 'leerer Arbeitsvorrat');
        $this->gleich([[], false, [['name' => 'arbeitsvorrat', 'titel' => 'von Hand']], []], [$l['eintraege'], $l['mehr'], $l['quellen'], $l['quellen_ausgefallen']], 'leer, einzige Quelle: von Hand');
        $menue = $this->status(200, $this->http('GET', "$p/oberflaeche/menue"), 'Menü');
        $eintrag = null;
        foreach ($menue['bereiche'] as $b) {
            foreach ($b['eintraege'] as $e) {
                if ($e['id'] === 'arbeitsvorrat.liste') {
                    $eintrag = [$b['id'], $e['zaehler']];
                }
            }
        }
        $this->gleich(['buchen', "$v/zaehler"], $eintrag, 'Menüeintrag im Bereich Buchen mit Zähler');

        // --- Eintrag von Hand (W9): nur der Betreff ist Pflicht ---
        $f = $this->status(400, $this->http('POST', "$v/eintraege", ['art' => 'Rechnung', 'betrag' => '-5', 'datum' => '2026-02-30', 'fremd' => 1,
            'belege' => [['link' => 'https://paperless.example.org/documents/1/?x=1']]]), 'ungültiger Eintrag');
        $this->gleich(['art', 'belege[0]', 'betrag', 'betreff', 'datum', 'fremd'], (function () use ($f) { $x = array_column($f['fehler'], 'feld'); sort($x); return $x; })(), 'jedes Feld geprüft');
        $a = $this->status(201, $this->http('POST', "$v/eintraege", ['betreff' => 'Nur ein Betreff (Muster)']), 'Eintrag nur mit Betreff');
        $this->gleich(['offen', null, null, [], ['arbeitsvorrat']], [$a['zustand'], $a['betrag'], $a['datum'], $a['belege'], $a['quellen']], 'alles andere darf fehlen');
        $r = $this->status(201, $this->http('POST', "$v/eintraege", ['betreff' => 'Rechnung Musterlieferant RE-0815', 'art' => 'rechnung', 'richtung' => 'ausgabe',
            'gegenpartei' => 'Musterlieferant GmbH', 'datum' => '2026-03-02', 'betrag' => '109.4', 'zahlweise' => 'unbar', 'belegnummer' => 'RE-0815',
            'belege' => [['link' => 'https://paperless.example.org/documents/5210/']], 'notiz' => 'Bitte bis Monatsende (Muster)']), 'vollständiger Eintrag');
        $this->gleich(['109.40', '2026-03-02', 'unbar', [['link' => 'https://paperless.example.org/documents/5210/']]], [$r['betrag'], $r['datum'], $r['zahlweise'], $r['belege']], 'Felder des Eintrags');
        $this->gleich(2, $this->status(200, $this->http('GET', "$v/zaehler"), 'Zähler')['anzahl'], 'zwei offene Einträge');
        $voll = $this->status(200, $this->http('GET', "$v/eintraege/{$r['id']}"), 'Eintrag lesen');
        $this->gleich([['wert' => 'Bitte bis Monatsende (Muster)', 'bezeichnung' => 'Notiz']], $voll['beitraege'][0]['angaben'], 'Notiz als Angabe der Quelle');
        $this->gleich(['vorlage' => null, 'konto_gegenpartei' => null], $voll['vorbelegung'], 'noch keine Regel');

        // --- Vertrag für Quellen (W3–W5): eine zweite Quelle liefert zum selben Beleg, eine dritte etwas Eigenes ---
        $k = Konfiguration::laden();
        $db = new Datenbank($k);
        $m = $db->verbindung(self::MANDANT);
        $dienst = new Dienst($m, 'nutzer:' . $erika);
        $link = 'https://paperless.example.org/documents/5210/';
        $e1 = $dienst->einliefern('musterquelle', 'dok-5210', ['betreff' => 'Scan 5210', 'gegenpartei' => 'Musterlieferant GmbH', 'betrag' => '109.43', 'datum' => '2026-03-02',
            'belege' => [['link' => $link, 'md5' => str_repeat('a', 32), 'sha256' => str_repeat('b', 64)]], 'hinweise' => [['feld' => 'betrag', 'text' => 'Betrag steht nicht wörtlich im erkannten Text']]],
            [['bezeichnung' => 'Netto', 'wert' => '91,96'], ['bezeichnung' => 'Steuer 19 %', 'wert' => '17,47']]);
        $this->gleich([$r['id'], 'ersetzt'], [$e1['eintrag'], $e1['ergebnis']], 'derselbe Beleglink: derselbe Eintrag (W4)');
        $voll = $this->status(200, $this->http('GET', "$v/eintraege/{$r['id']}"), 'zusammengeführter Eintrag');
        $this->gleich(['109.40', 'Rechnung Musterlieferant RE-0815', ['arbeitsvorrat', 'musterquelle']], [$voll['betrag'], $voll['betreff'], $voll['quellen']], 'der zuerst eingegangene Wert gilt (W3)');
        $hinweise = array_map(function ($x) { ksort($x); return $x; }, $voll['hinweise']);
        $this->gleich([['abweichend' => '109.43', 'feld' => 'betrag', 'quelle' => 'musterquelle'], ['feld' => 'betrag', 'quelle' => 'musterquelle', 'text' => 'Betrag steht nicht wörtlich im erkannten Text']],
            $hinweise, 'Abweichung und Hinweis der Quelle stehen daneben');
        $this->gleich(2, count($voll['beitraege']), 'zwei Beiträge an einem Eintrag');
        $this->gleich('unveraendert', $dienst->einliefern('musterquelle', 'dok-5210', ['betreff' => 'Scan 5210', 'gegenpartei' => 'Musterlieferant GmbH', 'betrag' => '109.43', 'datum' => '2026-03-02',
            'belege' => [['link' => $link, 'md5' => str_repeat('a', 32), 'sha256' => str_repeat('b', 64)]], 'hinweise' => [['feld' => 'betrag', 'text' => 'Betrag steht nicht wörtlich im erkannten Text']]],
            [['bezeichnung' => 'Netto', 'wert' => '91,96'], ['bezeichnung' => 'Steuer 19 %', 'wert' => '17,47']])['ergebnis'], 'Wiederholung ändert nichts');
        $e2 = $dienst->einliefern('musterquelle', 'dok-77', ['betreff' => 'Kassenbon Musterbäcker', 'art' => 'kassenbon', 'zahlweise' => 'bar', 'betrag' => '4.50', 'datum' => '2026-03-03']);
        $this->gleich('angelegt', $e2['ergebnis'], 'eigener Schlüssel ohne Beleglink: eigener Eintrag');
        $this->gleich('ersetzt', $dienst->einliefern('musterquelle', 'dok-77', ['betreff' => 'Kassenbon Musterbäcker', 'art' => 'kassenbon', 'zahlweise' => 'bar', 'betrag' => '4.80', 'datum' => '2026-03-03'])['ergebnis'], 'derselbe Schlüssel ersetzt');
        $this->gleich(['Nur ein Betreff (Muster)', 'Rechnung Musterlieferant RE-0815', 'Kassenbon Musterbäcker'], $this->betreffe('offen'), 'drei offene Einträge, älteste zuerst');
        $this->gleich(['Rechnung Musterlieferant RE-0815', 'Kassenbon Musterbäcker'], $this->betreffe('offen', '&quelle=musterquelle'), 'Filter nach Quelle');
        $this->gleich(['Kassenbon Musterbäcker'], $this->betreffe('offen', '&art=kassenbon'), 'Filter nach Art');
        $this->wirft(\Kumero\Api\V1\Problem::class, fn () => $dienst->einliefern('musterquelle', 'x', ['art' => 'rechnung']), 'Beitrag ohne Betreff abgewiesen');
        // zurückziehen: der Eintrag ohne weiteren Beitrag verschwindet aus „offen“; mit weiterem Beitrag bleibt er
        $this->ist($dienst->zurueckziehen('musterquelle', 'dok-77') && !$dienst->zurueckziehen('musterquelle', 'dok-77'), 'zurückziehen wirkt einmal');
        $this->status(404, $this->http('GET', "$v/eintraege/{$e2['eintrag']}"), 'zurückgezogener Eintrag ist weg');
        $dienst->zurueckziehen('musterquelle', 'dok-5210');
        $voll = $this->status(200, $this->http('GET', "$v/eintraege/{$r['id']}"), 'Eintrag nach Rückzug eines Beitrags');
        $this->gleich([['arbeitsvorrat'], [], [['link' => $link]]], [$voll['quellen'], $voll['hinweise'], $voll['belege']], 'Felder wieder nur aus dem verbliebenen Beitrag');
        $this->gleich('ersetzt', $dienst->einliefern('musterquelle', 'dok-77', ['betreff' => 'Kassenbon Musterbäcker', 'art' => 'kassenbon', 'zahlweise' => 'bar', 'betrag' => '4.80', 'datum' => '2026-03-03'])['ergebnis'], 'erneut geliefert: wieder offen');
        $this->gleich(3, $this->status(200, $this->http('GET', "$v/zaehler"), 'Zähler')['anzahl'], 'wieder drei offene');

        // --- Regeln (W11): Art → Geschäftsvorfall gepflegt ---
        $vorlage = $this->status(201, $this->http('POST', "$p/buchen/vorlagen", ['gruppe' => 'Zahlungsausgang', 'name' => 'Rechnung per Bank', 'gegenkonto' => '1200', 'buchungsart' => 'BANK']), 'Geschäftsvorfall')['id'];
        $this->status(400, $this->http('PUT', "$v/regeln/arten/rechnung", ['vorlage' => 'x']), 'Regel braucht einen Geschäftsvorfall');
        $this->status(200, $this->http('PUT', "$v/regeln/arten/rechnung", ['vorlage' => $vorlage]), 'Regel gesetzt');
        $regeln = $this->status(200, $this->http('GET', "$v/regeln"), 'Regeln');
        $this->gleich([[['art' => 'rechnung', 'vorlage' => $vorlage]], [], ['kassenbon', 'rechnung']], [$regeln['arten'], $regeln['gegenparteien'], $regeln['bekannte_arten']], 'Regel und bekannte Arten');
        $this->gleich($vorlage, $this->status(200, $this->http('GET', "$v/eintraege/{$r['id']}"), 'Eintrag mit Regel')['vorbelegung']['vorlage'], 'Vorbelegung nennt den Geschäftsvorfall');

        // --- Buchen aus dem Eintrag: Entwurf verknüpfen → in Arbeit; Konto der Gegenpartei wird gemerkt ---
        $inhalt = ['buchungsart' => 'BANK', 'belegdatum' => '2026-03-02', 'buchungsdatum' => '2026-03-04', 'belegnummer' => 'RE-0815', 'text' => 'Musterlieferant GmbH RE-0815',
            'belege' => [['link' => $link]], 'zeilen' => [['konto' => '4930', 'seite' => 'S', 'betrag' => '109.40'], ['konto' => '1200', 'seite' => 'H', 'betrag' => '109.40']]];
        $entwurf = $this->status(201, $this->http('POST', "$p/buchungen", $inhalt), 'Entwurf aus dem Eintrag')['id'];
        $this->status(400, $this->http('POST', "$v/eintraege/{$r['id']}/buchung", ['buchung' => '00000000-0000-4000-8000-000000000000']), 'unbekannte Buchung');
        $x = $this->status(200, $this->http('POST', "$v/eintraege/{$r['id']}/buchung", ['buchung' => $entwurf, 'konto' => '4930']), 'Entwurf verknüpft');
        $this->gleich(['in_arbeit', [['id' => $entwurf, 'stand' => 'entwurf']]], [$x['zustand'], $x['buchungen']], 'in Arbeit');
        $this->status(409, $this->http('POST', "$v/eintraege/{$a['id']}/buchung", ['buchung' => $entwurf]), 'eine Buchung gehört zu einem Eintrag');
        $this->gleich(['Rechnung Musterlieferant RE-0815'], $this->betreffe('in_arbeit'), 'Ansicht in Arbeit');
        $this->gleich('4930', $this->status(200, $this->http('GET', "$v/regeln"), 'Regeln')['gegenparteien'][0]['konto'], 'Konto der Gegenpartei gemerkt');
        $this->status(409, $this->http('POST', "$v/eintraege/{$r['id']}/verwerfen", ['grund' => 'doch nicht']), 'in Arbeit: nicht verwerfen');
        // Entwurf gelöscht → wieder offen (W7)
        $this->status(204, $this->http('DELETE', "$p/buchungen/$entwurf", null, ['If-Match' => '*']), 'Entwurf gelöscht');
        $this->gleich(['offen', []], (fn ($y) => [$y['zustand'], $y['buchungen']])($this->status(200, $this->http('GET', "$v/eintraege/{$r['id']}"), 'nach dem Löschen')), 'Entwurf gelöscht: wieder offen');
        // neuer Entwurf, festgeschrieben → gebucht
        $b = $this->status(201, $this->http('POST', "$p/buchungen", $inhalt + ['vorbereiten' => true]), 'neuer Entwurf, vorbereitet');
        $this->status(200, $this->http('POST', "$v/eintraege/{$r['id']}/buchung", ['buchung' => $b['id']]), 'verknüpft');
        $this->status(200, $this->http('POST', "$p/freigaben", ['buchungen' => [$b['id']]]), 'festgeschrieben');
        $this->gleich(['Rechnung Musterlieferant RE-0815'], $this->betreffe('gebucht'), 'nach dem Festschreiben: gebucht');
        $g = $this->status(200, $this->http('GET', "$v/eintraege/{$r['id']}"), 'gebuchter Eintrag');
        $this->gleich(['gebucht', 'festgeschrieben', 'nutzer:' . $erika], [$g['zustand'], $g['buchungen'][0]['stand'], $g['erledigt_von']], 'erledigt mit Verweis auf die Buchung');
        $this->gleich('vermerkt', $dienst->einliefern('musterquelle', 'dok-5210', ['betreff' => 'Scan 5210 neu', 'betrag' => '1.00', 'belege' => [['link' => $link]]])['ergebnis'], 'Lieferung nach dem Buchen wird nur vermerkt');
        $this->gleich('109.40', $this->status(200, $this->http('GET', "$v/eintraege/{$r['id']}"), 'unverändert')['betrag'], 'Felder eines gebuchten Eintrags bleiben');
        // Storno → wieder offen, mit Vermerk
        $st = $this->status(201, $this->http('POST', "$p/buchungen/{$b['id']}/storno", []), 'Storno-Entwurf');
        $this->status(200, $this->http('POST', "$p/buchungen/{$st['id']}/vorbereiten", null, ['If-Match' => '*']), 'Storno vorbereitet');
        $this->status(200, $this->http('POST', "$p/freigaben", ['buchungen' => [$st['id']]]), 'Storno festgeschrieben');
        $o = $this->status(200, $this->http('GET', "$v/eintraege/{$r['id']}"), 'nach dem Storno');
        $this->gleich(['offen', 'storniert', 'Die Buchung zu diesem Eintrag wurde storniert'], [$o['zustand'], $o['buchungen'][0]['stand'], end($o['vermerke'])['text']], 'Storno öffnet wieder (W7)');

        // --- Verwerfen mit Pflichtgrund, wieder öffnen (W12: nichts wird gelöscht) ---
        $this->status(400, $this->http('POST', "$v/eintraege/{$a['id']}/verwerfen", ['grund' => ' ']), 'Grund ist Pflicht');
        $w = $this->status(200, $this->http('POST', "$v/eintraege/{$a['id']}/verwerfen", ['grund' => 'doppelt gescannt']), 'verworfen');
        $this->gleich(['verworfen', 'doppelt gescannt'], [$w['zustand'], $w['verworfen_grund']], 'mit Grund');
        $this->gleich(['Nur ein Betreff (Muster)'], $this->betreffe('verworfen'), 'bleibt unter verworfen sichtbar');
        $this->status(409, $this->http('POST', "$v/eintraege/{$a['id']}/buchung", ['buchung' => $b['id']]), 'verworfen: erst wieder öffnen');
        $this->gleich('offen', $this->status(200, $this->http('POST', "$v/eintraege/{$a['id']}/oeffnen", []), 'wieder geöffnet')['zustand'], 'wieder offen');
        $this->status(409, $this->http('POST', "$v/eintraege/{$a['id']}/oeffnen", []), 'nur Verworfenes wird geöffnet');

        // --- Vorauswahl am Eintrag (X1, X7): einzeln und im Stapel, nur Vorbelegung ---
        $neu = $this->status(201, $this->http('POST', "$v/eintraege", ['betreff' => 'Stapel A (Muster)', 'betrag' => '10.00', 'datum' => '2026-03-10', 'zahlweise' => 'bar']), 'Eintrag A')['id'];
        $neu2 = $this->status(201, $this->http('POST', "$v/eintraege", ['betreff' => 'Stapel B (Muster)', 'betrag' => '20.00', 'datum' => '2026-03-11', 'zahlweise' => 'bar',
            'belege' => [['link' => 'https://paperless.example.org/documents/7001/', 'md5' => str_repeat('c', 32), 'sha256' => str_repeat('d', 64)]]]), 'Eintrag B')['id'];
        $this->gleich(2, $this->status(200, $this->http('PUT', "$v/eintraege/vorauswahl", ['eintraege' => [$neu, $neu2], 'konto' => '4930', 'gegenkonto' => '1200']), 'Stapelvorbelegung')['geaendert'], 'zwei Einträge auf einmal vorbelegt');
        $x = $this->status(200, $this->http('GET', "$v/eintraege/$neu"), 'vorbelegter Eintrag');
        $this->gleich(['4930', '1200', null, 'offen'], [$x['vor_konto'], $x['vor_gegenkonto'], $x['vor_buchungsart'], $x['zustand']], 'Vorauswahl steht am Eintrag, gebucht ist nichts');
        $this->gleich(1, $this->status(200, $this->http('PUT', "$v/eintraege/vorauswahl", ['eintraege' => [$neu], 'buchungsart' => 'BANK', 'gegenkonto' => null]), 'einzeln in der Zeile')['geaendert'], 'nur genannte Felder ändern sich, null leert');
        $x = $this->status(200, $this->http('GET', "$v/eintraege/$neu"), 'geändert');
        $this->gleich(['4930', null, 'BANK'], [$x['vor_konto'], $x['vor_gegenkonto'], $x['vor_buchungsart']], 'Konto bleibt, Gegenkonto geleert, Buchungsart gesetzt');
        $this->status(422, $this->http('PUT', "$v/eintraege/vorauswahl", ['eintraege' => [$neu2], 'konto' => '1200']), 'Konto und Gegenkonto müssen verschieden sein');
        $this->status(400, $this->http('PUT', "$v/eintraege/vorauswahl", ['eintraege' => [], 'konto' => '4930']), 'ohne Einträge abgewiesen');
        $this->status(400, $this->http('PUT', "$v/eintraege/vorauswahl", ['eintraege' => [$neu]]), 'ohne Feld abgewiesen');
        $this->gleich(0, $this->status(200, $this->http('PUT', "$v/eintraege/vorauswahl", ['eintraege' => [$r['id']], 'konto' => '4930']), 'gebuchter/offener fremder Zustand')['geaendert'] > 1 ? 1 : 0, 'höchstens die offenen Einträge');

        // --- Abgleich mit dem Journal (X4): schon gebucht und nicht storniert? – erkannt am Beleg ---
        $hand = $this->status(201, $this->http('POST', "$p/buchungen", ['buchungsart' => 'BANK', 'belegdatum' => '2026-03-11', 'buchungsdatum' => '2026-03-11', 'text' => 'Von Hand gebucht (Muster)',
            'belege' => [['link' => 'https://anderswo.example.org/documents/9/', 'md5' => str_repeat('c', 32), 'sha256' => str_repeat('d', 64)]],
            'zeilen' => [['konto' => '4930', 'seite' => 'S', 'betrag' => '20.00'], ['konto' => '1200', 'seite' => 'H', 'betrag' => '20.00']], 'vorbereiten' => true]), 'Buchung von Hand mit derselben Datei unter anderem Link');
        $this->ist(in_array('Stapel B (Muster)', $this->betreffe('offen'), true), 'als Entwurf zählt die Buchung noch nicht');
        $this->status(200, $this->http('POST', "$p/freigaben", ['buchungen' => [$hand['id']]]), 'festgeschrieben');
        $this->ist(!in_array('Stapel B (Muster)', $this->betreffe('offen'), true), 'gleiche Belegprüfsumme: der Eintrag gilt als gebucht');
        $g = $this->status(200, $this->http('GET', "$v/eintraege/$neu2"), 'erkannter Eintrag');
        $this->gleich(['gebucht', $hand['id']], [$g['zustand'], $g['buchungen'][0]['id']], 'mit der gefundenen Buchung verknüpft');
        $this->ist(in_array('Stapel A (Muster)', $this->betreffe('offen'), true), 'Einträge ohne Beleg bleiben offen');

        // --- Regeln löschen ---
        $this->status(204, $this->http('DELETE', "$v/regeln/arten/rechnung", null), 'Regel gelöscht');
        $this->status(404, $this->http('DELETE', "$v/regeln/arten/rechnung", null), 'Regel schon weg');
        $schl = $this->status(200, $this->http('GET', "$v/regeln"), 'Regeln')['gegenparteien'][0]['schluessel'];
        $this->status(204, $this->http('POST', "$v/regeln/gegenparteien/loeschen", ['schluessel' => $schl]), 'gemerktes Konto gelöscht');

        // --- Rechte: Erfasser bearbeitet, pflegt aber keine Regeln; Leser sieht nichts ---
        [$azubi, $pkAzubi] = $this->nutzer('Azubi Test');
        $this->u->cli(['command' => 'zugang:gewaehren', 'nutzer' => $azubi, 'mandant' => 'vorratpraxis', '--rolle' => ['Erfasser']]);
        $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'arbeitsvorrat', 'mandant' => 'vorratpraxis']);
        $this->anmelden($pkAzubi);
        $this->status(201, $this->http('POST', "$v/eintraege", ['betreff' => 'Vom Azubi (Muster)']), 'Erfasser legt an');
        $this->status(403, $this->http('PUT', "$v/regeln/arten/rechnung", ['vorlage' => $vorlage]), 'Erfasser pflegt keine Regeln');
        $this->status(200, $this->http('GET', "$v/regeln"), 'Erfasser sieht die Regeln');

        // --- Protokoll und Ketten ---
        $this->gleich(0, (int) $m->fetchOne('SELECT count(*) FROM kern.kette_pruefen()'), 'Ketten des Mandanten intakt');
        $typen = $m->fetchFirstColumn("SELECT DISTINCT typ FROM kern.protokoll WHERE typ LIKE 'arbeitsvorrat.%' ORDER BY typ");
        $this->gleich(['arbeitsvorrat.beitrag_geliefert', 'arbeitsvorrat.beitrag_zurueckgezogen', 'arbeitsvorrat.eintrag_gebucht', 'arbeitsvorrat.eintrag_verknuepft',
            'arbeitsvorrat.eintrag_verworfen', 'arbeitsvorrat.eintrag_wieder_offen', 'arbeitsvorrat.regel_geaendert', 'arbeitsvorrat.vorauswahl_gesetzt'], $typen, 'jeder Schritt im Protokoll');
        $this->gleich(0, (int) $m->fetchOne("SELECT count(*) FROM kern.protokoll WHERE typ = 'arbeitsvorrat.beitrag_geliefert' AND nachher::text LIKE '%Muster%'"), 'Protokoll der Lieferung ohne Inhalte');
        $db->schliessen();
    }
}
