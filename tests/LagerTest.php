<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

use Kumero\Modul\Arbeitsvorrat\Dienst;
use Kumero\Plattform\Datenbank;
use Kumero\Plattform\Identitaet;
use Kumero\Plattform\Kernel;
use Kumero\Plattform\Konfiguration;
use Kumero\Tests\Hilfe\Umgebung;
use Kumero\Tests\Hilfe\Zugaenge;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Modul lager (LAGER.md Stufe 1, F131): Artikel, Lagerorte, Chargen und Seriennummern, Zugang (von Hand und aus den
 * Positionen eines Belegs), Entnahme mit Vorschlag, Sperre, Verfall, Umlagerung, Ausbuchung, Rückverfolgung.
 * Alle Artikel, Lieferanten und Chargen sind erfunden; Verfallsdaten stehen relativ zum heutigen Tag.
 */
final class LagerTest extends Rahmen
{
    use Zugaenge;

    private const ORIGIN = Umgebung::ORIGIN;
    private const MANDANT = 'kumero_lagerpraxis';
    private const P = '/m/lagerpraxis/b/haupt/api/v1';

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

    public function testLager(): void
    {
        $l = self::P . '/lager';
        $this->mandantAnlegen('lagerpraxis', 'Lagerpraxis');
        [$bert, $pkBert] = $this->nutzer('lagerpraxis', 'Bert', 'Buchhalter', ['Buchhalter']);
        [, $pkEmil] = $this->nutzer('lagerpraxis', 'Emil', 'Erfasser', ['Erfasser']);
        [, $pkPia] = $this->nutzer('lagerpraxis', 'Pia', 'Pruefer', ['Prüfer']);
        foreach (['arbeitsvorrat', 'lager'] as $modul) {
            $this->u->cli(['command' => 'modul:aktivieren', 'modul' => $modul, 'mandant' => 'lagerpraxis']);
        }
        $this->anmelden($pkBert);
        $tag = fn (int $n) => (new \DateTimeImmutable('today'))->modify(($n >= 0 ? '+' : '') . "$n days")->format('Y-m-d');

        // leer; Einstellungen des Buchs
        $s = $this->status(200, $this->http('GET', $l), 'Stand');
        $this->gleich([[], [], 90, ['Verbrauch'], true, true], [$s['artikel'], $s['orte'], $s['einstellung']['warn_tage'], $s['einstellung']['zwecke'], $s['vorrat'], $s['darf']['pflegen']], 'leeres Lager, Vorgaben');
        $this->status(204, $this->http('PUT', "$l/einstellung", ['warn_tage' => 60, 'zwecke' => ['Sprechstunde', 'Labor']]), 'Einstellungen');
        $this->status(400, $this->http('PUT', "$l/einstellung", ['warn_tage' => 0, 'zwecke' => []]), 'ungültige Einstellungen');

        // Lagerorte und Artikel
        $keller = $this->status(201, $this->http('POST', "$l/orte", ['name' => 'Lager Keller']), 'Lagerort')['id'];
        $raum = $this->status(201, $this->http('POST', "$l/orte", ['name' => 'Behandlung 1', 'gekuehlt' => false]), 'zweiter Lagerort')['id'];
        $this->status(422, $this->http('POST', "$l/orte", ['name' => 'lager keller']), 'derselbe Name');
        $amp = ['bezeichnung' => 'Musterampulle 5 ml [TESTDATEN]', 'nummer' => 'A-001', 'art' => 'arzneimittel', 'einheit' => 'Ampulle', 'fuehrung' => 'charge', 'verfall_pflicht' => true,
                'gtin' => '04012345678901', 'mindest' => '20', 'standardort' => $keller];
        $ampulle = $this->status(201, $this->http('POST', "$l/artikel", $amp), 'Artikel mit Charge')['id'];
        $papier = $this->status(201, $this->http('POST', "$l/artikel", ['bezeichnung' => 'Musterpapier [TESTDATEN]', 'art' => 'sonstiges', 'einheit' => 'Packung', 'fuehrung' => 'ohne']), 'Artikel ohne Charge')['id'];
        $geraet = $this->status(201, $this->http('POST', "$l/artikel", ['bezeichnung' => 'Musterimplantat [TESTDATEN]', 'art' => 'medizinprodukt', 'einheit' => 'Stück', 'fuehrung' => 'serie']), 'Artikel mit Seriennummer')['id'];
        $btm = $this->status(201, $this->http('POST', "$l/artikel", ['bezeichnung' => 'Musterbetäubungsmittel [TESTDATEN]', 'art' => 'arzneimittel', 'einheit' => 'Ampulle', 'fuehrung' => 'charge', 'btm' => true]), 'Betäubungsmittel')['id'];
        foreach (['ohne Bezeichnung' => ['bezeichnung' => ''], 'Art' => ['art' => 'geraet'], 'Verfall ohne Charge' => ['fuehrung' => 'ohne'], 'GTIN' => ['gtin' => '12ab']] as $was => $ab) {
            $this->status(400, $this->http('POST', "$l/artikel", $ab + ['nummer' => null] + $amp), "Artikel abgewiesen: $was");
        }
        $this->status(409, $this->http('POST', "$l/artikel", $amp), 'dieselbe Artikelnummer');

        // Zugang von Hand: Regeln je Führungsart
        $zu = fn (array $z) => $this->http('POST', "$l/zugaenge", ['zeilen' => [$z]]);
        $this->status(422, $zu(['artikel' => $ampulle, 'ort' => $keller, 'menge' => '10']), 'ohne Charge');
        $this->status(422, $zu(['artikel' => $ampulle, 'ort' => $keller, 'menge' => '10', 'charge' => 'T-ALT']), 'ohne Verfall');
        $this->status(409, $zu(['artikel' => $btm, 'ort' => $keller, 'menge' => '1', 'charge' => 'T-B']), 'Betäubungsmittel werden nicht geführt');
        $this->status(422, $zu(['artikel' => $papier, 'ort' => $keller, 'menge' => '1', 'charge' => 'T-X']), 'Artikel ohne Charge nimmt keine');
        $this->status(422, $zu(['artikel' => $geraet, 'ort' => $keller, 'menge' => '2', 'charge' => 'SN-1']), 'Seriennummer: Menge 1');
        $this->status(400, $zu(['artikel' => $ampulle, 'ort' => $keller, 'menge' => '-1', 'charge' => 'T-1', 'verfall' => $tag(30)]), 'negative Menge');
        $this->status(201, $zu(['artikel' => $ampulle, 'ort' => $raum, 'menge' => '6', 'charge' => 'T-ALT', 'verfall' => $tag(-5), 'grund' => 'anfangsbestand']), 'abgelaufene Charge als Anfangsbestand');
        $this->status(201, $zu(['artikel' => $ampulle, 'ort' => $raum, 'menge' => '12', 'charge' => 'T-FRUEH', 'verfall' => $tag(40), 'preis' => '1.8900']), 'Charge mit frühem Verfall');
        $this->status(201, $zu(['artikel' => $ampulle, 'ort' => $keller, 'menge' => '20', 'charge' => 'T-SPAET', 'verfall' => $tag(400)]), 'Charge mit spätem Verfall');
        $this->status(422, $zu(['artikel' => $ampulle, 'ort' => $keller, 'menge' => '5', 'charge' => 'T-SPAET', 'verfall' => $tag(401)]), 'dieselbe Charge mit anderem Verfall');
        $this->status(201, $zu(['artikel' => $papier, 'ort' => $keller, 'menge' => '2.5']), 'ohne Charge');
        $this->status(201, $zu(['artikel' => $geraet, 'ort' => $keller, 'menge' => '1', 'charge' => 'SN-1']), 'Seriennummer');
        $this->status(422, $zu(['artikel' => $geraet, 'ort' => $keller, 'menge' => '1', 'charge' => 'SN-1']), 'Seriennummer schon im Bestand');

        // Bestand: Summe der Bewegungen, Zustand in Worten, Vorschlag = frühester Verfall unter den freien Chargen
        $s = $this->status(200, $this->http('GET', $l), 'Bestand');
        $a = array_column($s['artikel'], null, 'id');
        $ch = array_column($a[$ampulle]['chargen'], null, 'bezeichnung');
        $this->gleich(['38', 'abgelaufen', $tag(-5), false, ['abgelaufen', 'laeuft_ab', 'frei'], [false, true, false]],
            [$a[$ampulle]['bestand'], $a[$ampulle]['zustand'], $a[$ampulle]['naechster_verfall'], $a[$ampulle]['unter_mindest'], array_column($a[$ampulle]['chargen'], 'zustand'), array_column($a[$ampulle]['chargen'], 'vorschlag')],
            'Ampulle: 38 im Bestand, eine Charge abgelaufen, eine läuft ab (60 Tage), Vorschlag ist die mit dem frühesten Verfall');
        $this->gleich(['2.5', 'in_ordnung', [['ort' => $keller, 'menge' => '2.5']]], [$a[$papier]['bestand'], $a[$papier]['zustand'], $a[$papier]['chargen'][0]['orte']], 'ohne Charge: eine Zeile, Menge mit Nachkommastelle');
        $this->gleich(['abgelaufen' => 1, 'laeuft_ab' => 1, 'gesperrt' => 0, 'unter_mindest' => 0], $s['zahlen'], 'Zahlen der Kacheln');

        // Entnahme: abgelaufen ist gesperrt, kein negativer Bestand, Zweck nur aus der Liste, Abweichung vom Vorschlag wird vermerkt
        $raus = fn (string $c, string $ort, string $menge, ?string $zweck = 'Sprechstunde') => $this->http('POST', "$l/entnahmen", ['charge' => $c, 'ort' => $ort, 'menge' => $menge, 'zweck' => $zweck]);
        $r = $this->status(409, $raus($ch['T-ALT']['id'], $raum, '1'), 'abgelaufene Charge');
        $this->ist(str_contains($r['detail'] ?? '', 'abgelaufen'), 'mit Grund: ' . ($r['detail'] ?? ''));
        $r = $this->status(409, $raus($ch['T-FRUEH']['id'], $raum, '13'), 'mehr als da ist');
        $this->ist(str_contains($r['detail'] ?? '', 'nur 12 verfügbar'), 'nennt die verfügbare Menge: ' . ($r['detail'] ?? ''));
        $this->status(409, $raus($ch['T-FRUEH']['id'], $keller, '1'), 'am falschen Lagerort ist nichts');
        $this->status(422, $raus($ch['T-FRUEH']['id'], $raum, '1', 'Frau Muster'), 'freier Text als Zweck');
        $this->gleich(false, $this->status(201, $raus($ch['T-FRUEH']['id'], $raum, '2'), 'Entnahme aus dem Vorschlag')['abweichung'], 'keine Abweichung');
        $this->gleich(true, $this->status(201, $raus($ch['T-SPAET']['id'], $keller, '1', null), 'Entnahme aus der späteren Charge')['abweichung'], 'Abweichung vom Vorschlag vermerkt');

        // Sperren: gesperrt ist nicht entnehmbar, der Vorschlag weicht aus; freigeben
        $this->status(400, $this->http('POST', "$l/chargen/{$ch['T-FRUEH']['id']}/sperre", ['grund' => ' ']), 'Sperre ohne Grund');
        $this->status(204, $this->http('POST', "$l/chargen/{$ch['T-FRUEH']['id']}/sperre", ['grund' => 'Rückruf des Herstellers (Muster)']), 'sperren');
        $this->status(409, $raus($ch['T-FRUEH']['id'], $raum, '1'), 'gesperrte Charge');
        $s = $this->status(200, $this->http('GET', $l), 'Bestand nach Sperre');
        $c2 = array_column(array_column($s['artikel'], null, 'id')[$ampulle]['chargen'], null, 'bezeichnung');
        $this->gleich(['gesperrt', false, true, 1], [$c2['T-FRUEH']['zustand'], $c2['T-FRUEH']['vorschlag'], $c2['T-SPAET']['vorschlag'], $s['zahlen']['gesperrt']], 'gesperrt; vorgeschlagen wird die nächste freie Charge');

        // Umlagern und Ausbuchen (auch aus gesperrten und abgelaufenen Chargen)
        $this->status(201, $this->http('POST', "$l/umlagerungen", ['charge' => $ch['T-SPAET']['id'], 'von' => $keller, 'nach' => $raum, 'menge' => '4']), 'Umlagerung');
        $this->status(409, $this->http('POST', "$l/umlagerungen", ['charge' => $ch['T-SPAET']['id'], 'von' => $keller, 'nach' => $keller, 'menge' => '1']), 'Umlagerung an denselben Ort');
        $this->status(422, $this->http('POST', "$l/ausbuchungen", ['charge' => $ch['T-ALT']['id'], 'ort' => $raum, 'menge' => '6', 'grund' => 'verschenkt']), 'Ausbuchung mit unbekanntem Grund');
        $this->status(201, $this->http('POST', "$l/ausbuchungen", ['charge' => $ch['T-ALT']['id'], 'ort' => $raum, 'menge' => '6', 'grund' => 'verfallen']), 'abgelaufene Charge ausbuchen');
        $this->status(204, $this->http('POST', "$l/chargen/{$ch['T-FRUEH']['id']}/sperre", ['grund' => null]), 'freigeben');

        // Rückverfolgung: alle Bewegungen der Charge mit Bestand danach
        $rv = $this->status(200, $this->http('GET', "$l/chargen/{$ch['T-SPAET']['id']}"), 'Rückverfolgung');
        $this->gleich([['zugang', '20', '20'], ['entnahme', '1', '19'], ['umlagerung', '4', '19']], array_map(fn ($b) => [$b['art'], $b['menge'], $b['danach']], $rv['bewegungen']), 'Zugang, Entnahme, Umlagerung');
        $this->gleich(['20', '1', '19', true, "nutzer:$bert", 'Lager Keller', 'Behandlung 1'], [$rv['summen']['zugang'], $rv['summen']['entnahme'], $rv['summen']['bestand'], $rv['bewegungen'][1]['abweichung'],
            $rv['bewegungen'][1]['subjekt'], $rv['bewegungen'][2]['von'], $rv['bewegungen'][2]['nach']], 'Summen, Abweichung, wer, von und nach');
        $sp = $this->status(200, $this->http('GET', "$l/chargen/{$ch['T-FRUEH']['id']}"), 'Rückverfolgung der gesperrten Charge')['sperren'];
        $this->gleich(['Rückruf des Herstellers (Muster)', null], array_column($sp, 'grund'), 'Sperre und Freigabe stehen in der Rückverfolgung');
        $s = $this->status(200, $this->http('GET', $l), 'Bestand am Ende');
        $a = array_column($s['artikel'], null, 'id');
        $this->gleich(['29', 'laeuft_ab', true, 0], [$a[$ampulle]['bestand'], $a[$ampulle]['zustand'] === 'laeuft_ab' ? 'laeuft_ab' : $a[$ampulle]['zustand'], count($a[$ampulle]['chargen']) === 2, $s['zahlen']['abgelaufen']], 'ausgebuchte Charge steht nicht mehr im Bestand');

        // Führungsart steht nach der ersten Bewegung fest; stilllegen nur ohne Bestand
        $this->status(409, $this->http('PUT', "$l/artikel/$ampulle", ['fuehrung' => 'serie', 'verfall_pflicht' => false] + $amp), 'Führungsart ändern');
        $this->status(409, $this->http('PUT', "$l/artikel/$ampulle", ['stillgelegt' => true] + $amp), 'stilllegen mit Bestand');
        $this->gleich('15', $this->status(200, $this->http('PUT', "$l/artikel/$ampulle", ['mindest' => '15'] + $amp), 'Mindestbestand ändern')['mindest'], 'geändert');
        $this->status(409, $this->http('PUT', "$l/orte/$keller", ['name' => 'Lager Keller', 'stillgelegt' => true]), 'Lagerort mit Bestand stilllegen');

        // --- Wareneingang aus den Positionen eines Belegs (über den Dienst des Arbeitsvorrats) ---
        $k = Konfiguration::laden();
        $dienst = new Dienst((new Datenbank($k))->verbindung(self::MANDANT . '__haupt'), "nutzer:$bert");
        $e = $dienst->einliefern('musterquelle', 'rechnung-1', ['betreff' => 'Rechnung Musterlieferant R-1', 'gegenpartei' => 'Musterlieferant GmbH', 'gegenpartei_kennung' => 'DE000000000',
            'belegnummer' => 'R-1', 'datum' => '2026-11-17', 'betrag' => '57.36', 'netto' => '48.20', 'positionen' => [
                ['nr' => '1', 'bezeichnung' => 'Musteramp. 5ml 10 St. [TESTDATEN]', 'artikelnummer' => 'ML-22017', 'menge' => '2', 'einheit' => 'C62', 'einzelpreis' => '18.9', 'netto' => '37.80'],
                ['nr' => '2', 'bezeichnung' => 'Musterpapier A4 [TESTDATEN]', 'menge' => '1', 'netto' => '3.50', 'gtin' => '04012345678918'],
                ['nr' => '3', 'bezeichnung' => 'Versandkosten', 'menge' => '1', 'netto' => '6.90']]]);
        $eid = $e['eintrag'];
        $w = $this->status(200, $this->http('GET', "$l/wareneingang"), 'Wareneingang')['eintraege'];
        $this->gleich([[$eid, 3, 0, 'Musterlieferant GmbH']], array_map(fn ($x) => [$x['id'], $x['positionen'], $x['zugaenge'], $x['gegenpartei']], $w), 'ein Eintrag mit drei Positionen, noch kein Zugang');
        $p = $this->status(200, $this->http('GET', "$l/wareneingang/$eid"), 'Positionen des Eintrags');
        $this->gleich(['de000000000', [null, null, null], 'ml-22017', null], [$p['lieferant'], array_column($p['positionen'], 'zuordnung'), $p['positionen'][0]['schluessel'], $p['summenhinweis']], 'noch nichts gemerkt, Summe stimmt');
        // Position 1: 2 Packungen = 20 Ampullen, Zuordnung merken; Position 3: kein Lagerartikel
        $this->status(201, $this->http('POST', "$l/zugaenge", ['eintrag' => $eid, 'zeilen' => [
            ['artikel' => $ampulle, 'ort' => $keller, 'menge' => '20', 'charge' => 'T-NEU', 'verfall' => $tag(700), 'preis' => '1.89', 'position' => '1',
             'code' => '(01)04012345678901(17)281231(10)T-NEU', 'merken' => ['nummer' => 'ML-22017', 'faktor' => '10']]]]), 'Zugang aus Position 1');
        $this->status(204, $this->http('POST', "$l/zuordnungen", ['eintrag' => $eid, 'nummer' => 'Versandkosten', 'artikel' => null]), 'Versandkosten: kein Lagerartikel');
        $p = $this->status(200, $this->http('GET', "$l/wareneingang/$eid"), 'Positionen nach dem Zugang');
        $this->gleich([['artikel' => $ampulle, 'kein_lager' => false, 'faktor' => '10', 'gemerkt' => true], null, ['artikel' => null, 'kein_lager' => true, 'faktor' => '1', 'gemerkt' => true]],
            array_column($p['positionen'], 'zuordnung'), 'Zuordnung und „kein Lagerartikel“ sind gemerkt');
        $this->gleich([['20', 'T-NEU', 'Lager Keller']], array_map(fn ($z) => [$z['menge'], $z['charge'], $z['ort']], $p['positionen'][0]['zugaenge']), 'der Zugang steht an der Position');
        $this->gleich(1, $this->status(200, $this->http('GET', "$l/wareneingang"), 'Wareneingang danach')['eintraege'][0]['zugaenge'], 'eine Position zugegangen');
        // ganz oder gar nicht: die zweite Zeile ist falsch, die erste wird nicht gebucht
        $vorher = array_column($this->status(200, $this->http('GET', $l), 'Bestand')['artikel'], 'bestand', 'id')[$papier];
        $this->status(422, $this->http('POST', "$l/zugaenge", ['eintrag' => $eid, 'zeilen' => [['artikel' => $papier, 'ort' => $keller, 'menge' => '1', 'position' => '2'],
            ['artikel' => $ampulle, 'ort' => $keller, 'menge' => '1', 'position' => '2']]]), 'zwei Zeilen, die zweite ohne Charge');
        $this->gleich($vorher, array_column($this->status(200, $this->http('GET', $l), 'Bestand')['artikel'], 'bestand', 'id')[$papier], 'nichts gebucht');
        $this->status(400, $this->http('POST', "$l/zugaenge", ['eintrag' => '11111111-2222-4333-8444-555555555555', 'zeilen' => [['artikel' => $papier, 'ort' => $keller, 'menge' => '1']]]), 'unbekannter Eintrag');

        // --- Stufe 2: Sollbestand und Nachbestellliste ---
        $this->status(400, $this->http('PUT', "$l/artikel/$ampulle", ['mindest' => '60', 'soll' => '50'] + $amp), 'Sollbestand unter Mindestbestand');
        $this->gleich(['60', '100'], array_values(array_intersect_key($this->status(200, $this->http('PUT', "$l/artikel/$ampulle", ['mindest' => '60', 'soll' => '100'] + $amp), 'Mindest- und Sollbestand'), ['mindest' => 1, 'soll' => 1])), 'gespeichert');
        $n = $this->status(200, $this->http('GET', "$l/nachbestellung"), 'Nachbestellliste')['artikel'];
        $this->gleich([[$ampulle, '49', '0', '51', 6, 'Musterlieferant GmbH', 'ML-22017', '10', '1.8900']],
            array_map(fn ($x) => [$x['artikel'], $x['verfuegbar'], $x['nicht_verfuegbar'], $x['vorschlag'], $x['bestelleinheiten'], $x['lieferant'], $x['lieferant_nummer'], $x['faktor'], $x['letzter_preis']], $n),
            'unter Mindestbestand: Vorschlag bis zum Sollbestand, in Bestelleinheiten des Lieferanten, mit letztem Preis');
        $neu = array_column(array_column($this->status(200, $this->http('GET', $l), 'Bestand')['artikel'], null, 'id')[$ampulle]['chargen'], null, 'bezeichnung')['T-NEU']['id'];
        $this->status(204, $this->http('POST', "$l/chargen/$neu/sperre", ['grund' => 'Muster']), 'Charge sperren');
        $n = $this->status(200, $this->http('GET', "$l/nachbestellung"), 'Nachbestellliste mit gesperrter Charge')['artikel'][0];
        $this->gleich(['29', '20', '71', 8], [$n['verfuegbar'], $n['nicht_verfuegbar'], $n['vorschlag'], $n['bestelleinheiten']], 'gesperrter Bestand zählt nicht als verfügbar');
        // Bestand und Kachel rechnen wie die Nachbestellliste (Y17): 49 liegen da, 29 sind verwendbar – Mindestbestand 40 ist unterschritten
        $this->status(200, $this->http('PUT', "$l/artikel/$ampulle", ['mindest' => '40', 'soll' => '100'] + $amp), 'Mindestbestand zwischen verfügbar und Bestand');
        $s = $this->status(200, $this->http('GET', $l), 'Bestand mit gesperrter Charge');
        $x = array_column($s['artikel'], null, 'id')[$ampulle];
        $this->gleich(['49', '29', true, 1], [$x['bestand'], $x['verfuegbar'], $x['unter_mindest'], $s['zahlen']['unter_mindest']], 'unter Mindestbestand zählt nur, was verwendbar ist – auch wenn der Rest nicht ausgebucht ist');
        $this->status(200, $this->http('PUT', "$l/artikel/$ampulle", ['mindest' => '60', 'soll' => '100'] + $amp), 'Mindestbestand zurück');
        $this->status(204, $this->http('POST', "$l/chargen/$neu/sperre", ['grund' => null]), 'wieder freigeben');

        // --- Stufe 2: Inventur – gezählt wird je Charge und Lagerort, gerechnet gegen den Bestand im Augenblick der Zählung ---
        $inv = $this->status(201, $this->http('POST', "$l/inventuren", ['ort' => $raum]), 'Inventur anlegen');
        $iv = "$l/inventuren/{$inv['id']}";
        $this->gleich(['offen', 'Behandlung 1', [['T-FRUEH', '10', null], ['T-SPAET', '4', null]], 0],
            [$inv['zustand'], $inv['ort_name'], array_map(fn ($z) => [$z['charge_name'], $z['bestand'], $z['ist']], $inv['zeilen']), $inv['gezaehlt']], 'Zählliste: je Charge mit Bestand am Lagerort eine Zeile');
        $this->status(409, $this->http('POST', "$l/inventuren", ['ort' => $raum]), 'zweite Inventur für denselben Lagerort');
        $this->status(409, $this->http('POST', "$l/inventuren", ['ort' => null]), 'Inventur über alle Lagerorte, solange eine offen ist');
        $zaehlen = fn (string $c, ?string $ist, string $ort) => $this->http('PUT', "$iv/zeilen", ['charge' => $c, 'ort' => $ort, 'ist' => $ist]);
        $i = $this->status(200, $zaehlen($ch['T-FRUEH']['id'], '9', $raum), 'erste Zeile zählen');
        $this->gleich([1, 1, '-1'], [$i['gezaehlt'], $i['abweichungen'], $i['zeilen'][0]['differenz']], 'eine gezählt, Differenz −1');
        $this->status(409, $this->http('POST', "$iv/abschluss"), 'Abschluss, solange Zeilen offen sind');
        // nach der Zählung wird entnommen: die Differenz der gezählten Zeile bleibt
        $this->status(201, $raus($ch['T-FRUEH']['id'], $raum, '2'), 'Entnahme nach der Zählung');
        $this->status(400, $zaehlen($ch['T-SPAET']['id'], '-1', $raum), 'negative Zählung');
        $this->status(200, $zaehlen($ch['T-SPAET']['id'], '5', $raum), 'zweite Zeile zählen');
        // Fund: eine bekannte Charge liegt an einem Ort, an dem sie laut Bestand nicht ist
        $this->status(409, $this->http('POST', "$iv/zeilen", ['charge' => $neu, 'ort' => $keller]), 'Zeile für einen anderen Lagerort');
        $this->status(201, $this->http('POST', "$iv/zeilen", ['charge' => $neu, 'ort' => $raum]), 'Zeile hinzufügen');
        $i = $this->status(200, $zaehlen($neu, '2', $raum), 'Fund zählen');
        $this->gleich([3, 3, ['-1', '+', '1']], [$i['gezaehlt'], $i['abweichungen'], [$i['zeilen'][0]['differenz'], '+', $i['zeilen'][1]['differenz']]], 'drei Zeilen, drei Abweichungen');
        $fertig = $this->status(200, $this->http('POST', "$iv/abschluss"), 'Inventur abschließen');
        $this->gleich(['abgeschlossen', 3], [$fertig['zustand'], $fertig['abweichungen']], 'abgeschlossen mit drei Differenzbewegungen');
        $this->status(409, $zaehlen($neu, '3', $raum), 'nach dem Abschluss wird nicht mehr gezählt');
        $c3 = array_column(array_column($this->status(200, $this->http('GET', $l), 'Bestand nach der Inventur')['artikel'], null, 'id')[$ampulle]['chargen'], null, 'bezeichnung');
        $jeOrt = fn (array $c) => array_column($c['orte'], 'menge', 'ort');
        $this->gleich(['7', '5', '2'], [$jeOrt($c3['T-FRUEH'])[$raum], $jeOrt($c3['T-SPAET'])[$raum], $jeOrt($c3['T-NEU'])[$raum]], 'Bestand: 10 − 2 entnommen − 1 Differenz; 4 + 1; Fund 2');
        $rv = $this->status(200, $this->http('GET', "$l/chargen/{$ch['T-FRUEH']['id']}"), 'Rückverfolgung nach der Inventur')['bewegungen'];
        $this->gleich(['ausbuchung', 'inventur', '1'], [end($rv)['art'], end($rv)['grund'], end($rv)['menge']], 'die Differenz ist eine Bewegung mit dem Grund Inventur');
        // verwerfen gibt den Lagerort wieder frei
        $zweite = $this->status(201, $this->http('POST', "$l/inventuren", ['ort' => null]), 'Inventur über alle Lagerorte');
        $this->gleich('verworfen', $this->status(200, $this->http('POST', "$l/inventuren/{$zweite['id']}/verwerfen"), 'verwerfen')['zustand'], 'verworfen');
        $this->gleich([['verworfen', null], ['abgeschlossen', 'Behandlung 1']], array_map(fn ($x) => [$x['zustand'], $x['ort_name']], $this->status(200, $this->http('GET', "$l/inventuren"), 'Liste der Inventuren')['inventuren']), 'Liste, neueste zuerst');

        // --- Stufe 3: Bewertung und Bestandsliste zum Stichtag ---
        $heute = $tag(0);
        $bl = $this->status(200, $this->http('GET', "$l/bestandsliste"), 'Bestandsliste heute');
        $w = array_column($bl['artikel'], null, 'artikel');
        $this->gleich([$heute, 'letzter', 'aufwand', 'netto', '49', '1.8900', '92.61', false, null, true, '92.61', 2],
            [$bl['stichtag'], $bl['einstellung']['bewertung'], $bl['einstellung']['buchfuehrung'], $bl['einstellung']['preise'], $w[$ampulle]['menge'], $w[$ampulle]['preis'], $w[$ampulle]['wert'],
             $w[$ampulle]['ohne_preis'], $w[$papier]['wert'], $w[$papier]['ohne_preis'], $bl['summe'], $bl['ohne_preis']], 'letzter Einstandspreis: 49 × 1,89; zwei Artikel ohne Preis');
        $leer = $this->status(200, $this->http('GET', "$l/bestandsliste?stichtag=" . $tag(-1)), 'Bestandsliste gestern');
        $this->gleich([[], '0.00'], [$leer['artikel'], $leer['summe']], 'zum Stichtag gestern gab es noch keinen Bestand');
        $this->status(400, $this->http('GET', "$l/bestandsliste?stichtag=31.12.2026"), 'Stichtag in falscher Form');
        // Einstellungen: genannte Felder werden gesetzt, die übrigen bleiben
        $this->status(204, $this->http('PUT', "$l/einstellung", ['bewertung' => 'durchschnitt']), 'Bewertung umstellen');
        $bl = $this->status(200, $this->http('GET', "$l/bestandsliste"), 'Bestandsliste mit Durchschnitt');
        $w = array_column($bl['artikel'], null, 'artikel');
        $this->gleich([60, ['Sprechstunde', 'Labor'], '1.8900', '81.27', true], [$bl['einstellung']['warn_tage'], $bl['einstellung']['zwecke'], $w[$ampulle]['preis'], $w[$ampulle]['wert'], $w[$ampulle]['ohne_preis']],
            'gleitender Durchschnitt: 6 Ampullen kamen ohne Preis und haben keinen Wert; Warnfrist und Zwecke sind geblieben');
        $this->status(400, $this->http('PUT', "$l/einstellung", ['buchfuehrung' => 'bestand']), 'Bestandsführung ohne Konten');
        $this->status(400, $this->http('PUT', "$l/einstellung", ['bewertung' => 'fifo']), 'unbekanntes Verfahren');
        $this->status(400, $this->http('PUT', "$l/einstellung", ['patienten' => true]), 'unbekanntes Feld');
        $this->status(204, $this->http('PUT', "$l/einstellung", ['buchfuehrung' => 'bestand', 'bestandskonto' => '3970', 'aufwandskonto' => '3960', 'vorratskonten' => '3970-3989', 'preise' => 'brutto', 'bewertung' => 'letzter']), 'Bestandsführung mit Konten');
        // Vermerk der Buchung der Bestandsveränderung (die Buchung selbst legt die Seite im Kern an)
        $buchung = '11111111-2222-4333-8444-555555555555';
        $this->status(400, $this->http('POST', "$l/abschluss", ['stichtag' => $heute, 'buchung' => $buchung, 'betrag' => '0.00', 'richtung' => 'erhoehung']), 'Vermerk ohne Betrag');
        $this->status(204, $this->http('POST', "$l/abschluss", ['stichtag' => $heute, 'buchung' => $buchung, 'betrag' => '92.61', 'richtung' => 'erhoehung']), 'Vermerk');
        $bl = $this->status(200, $this->http('GET', "$l/bestandsliste"), 'Bestandsliste mit Vermerk');
        $this->gleich([[['buchung' => $buchung, 'betrag' => '92.61', 'richtung' => 'erhoehung']], 'bestand', '3970', 'brutto'], [$bl['vermerke'], $bl['einstellung']['buchfuehrung'], $bl['einstellung']['bestandskonto'], $bl['einstellung']['preise']], 'Vermerk und Einstellungen');
        $this->status(204, $this->http('POST', "$l/abschluss/entfernen", ['stichtag' => $heute, 'buchung' => $buchung]), 'Vermerk entfernen');
        $this->gleich([], $this->status(200, $this->http('GET', "$l/bestandsliste"), 'Bestandsliste ohne Vermerk')['vermerke'], 'entfernt');
        // Wareneingang: Preise des Buchs und – ohne das Modul anlagen – keine Grenze für den Hinweis „Anlagegut?“
        $p = $this->status(200, $this->http('GET', "$l/wareneingang/$eid"), 'Positionen mit Einstellungen');
        $this->gleich(['brutto', null], [$p['preise'], $p['anlagegrenze']], 'Preise brutto; Modul anlagen ist nicht aktiv');
        $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'anlagen', 'mandant' => 'lagerpraxis']);
        $this->gleich('800.00', $this->status(200, $this->http('GET', "$l/wareneingang/$eid"), 'Positionen mit Anlagen')['anlagegrenze'], 'mit dem Modul anlagen: Grenze aus dessen Regelwerk');

        // Protokoll: nur IDs, Mengen und Kennungen – keine Bezeichnungen
        $prot = $this->u->psql(self::MANDANT . '__haupt', "SELECT string_agg(typ || ' ' || coalesce(nachher::text, ''), E'\n') FROM kern.protokoll WHERE typ IN ('lager.zugang', 'lager.entnahme')");
        $this->ist(str_contains($prot, '"menge": "20"') && !str_contains($prot, 'TESTDATEN') && !str_contains($prot, 'Musterlieferant'), 'Protokoll trägt Mengen als Text und keine Bezeichnungen');

        // --- Rollen (F134): das Modul bringt „Lager“ und „Lagerleitung“ mit; Buchhaltung und Lager sind getrennt zuweisbar ---
        [, $pkLena] = $this->nutzer('lagerpraxis', 'Lena', 'Lager', ['Lager']);
        [, $pkLars] = $this->nutzer('lagerpraxis', 'Lars', 'Leitung', ['Lagerleitung']);
        [, $pkLeo] = $this->nutzer('lagerpraxis', 'Leo', 'Leser', ['Leser']);
        // Rolle „Lager“: sehen, bewegen, zählen – nicht sperren, nicht pflegen, nicht abschließen, keine Buchhaltung
        $this->anmelden($pkLena);
        $d = $this->status(200, $this->http('GET', $l), 'Lager sieht den Bestand')['darf'];
        $this->gleich(['buchen' => true, 'sperren' => false, 'pflegen' => false, 'einstellung' => false], $d, 'Rechte der Rolle Lager');
        $this->status(201, $raus($ch['T-FRUEH']['id'], $raum, '1'), 'Lager entnimmt');
        $this->status(403, $this->http('POST', "$l/chargen/{$ch['T-FRUEH']['id']}/sperre", ['grund' => 'x']), 'Lager sperrt nicht');
        $this->status(403, $this->http('POST', "$l/orte", ['name' => 'Schrank']), 'Lager legt keinen Lagerort an');
        $dritte = $this->status(201, $this->http('POST', "$l/inventuren", ['ort' => $keller]), 'Lager legt eine Zählliste an');
        $this->gleich([true, false], [$dritte['darf'], $dritte['darf_abschliessen']], 'zählen ja, abschließen nein');
        foreach ($dritte['zeilen'] as $z) {
            $this->status(200, $this->http('PUT', "$l/inventuren/{$dritte['id']}/zeilen", ['charge' => $z['charge'], 'ort' => $z['ort'], 'ist' => $z['bestand']]), 'Lager zählt');
        }
        $this->status(403, $this->http('POST', "$l/inventuren/{$dritte['id']}/abschluss"), 'Lager schließt die Inventur nicht ab');
        $this->status(403, $this->http('GET', self::P . '/buchungen'), 'Lager sieht keine Buchungen');
        $this->status(403, $this->http('GET', self::P . '/arbeitsvorrat/eintraege'), 'Lager sieht den Arbeitsvorrat nicht');
        // Rolle „Lagerleitung“: dazu sperren, pflegen, abschließen – weiterhin keine Buchhaltung
        $this->anmelden($pkLars);
        $this->gleich(0, $this->status(200, $this->http('POST', "$l/inventuren/{$dritte['id']}/abschluss"), 'Lagerleitung schließt ab')['abweichungen'], 'ohne Abweichung');
        $this->status(204, $this->http('POST', "$l/chargen/{$ch['T-FRUEH']['id']}/sperre", ['grund' => 'Muster']), 'Lagerleitung sperrt');
        $this->status(204, $this->http('POST', "$l/chargen/{$ch['T-FRUEH']['id']}/sperre", ['grund' => null]), 'und gibt frei');
        $this->status(201, $this->http('POST', "$l/orte", ['name' => 'Schrank']), 'Lagerleitung legt einen Lagerort an');
        $bl = $this->status(200, $this->http('GET', "$l/bestandsliste"), 'Lagerleitung sieht die Bestandsliste');
        $this->gleich([true, false], [$bl['darf'], $bl['darf_buchen']], 'Einstellungen ja, Buchungsentwurf nein – der gehört der Buchhaltung');
        $this->status(403, $this->http('GET', self::P . '/buchungen'), 'Lagerleitung sieht keine Buchungen');
        // Erfasser (Buchhaltung) hat mit dem Lager nichts zu tun; Leser sieht es
        $this->anmelden($pkEmil);
        $this->status(403, $this->http('GET', $l), 'Erfasser sieht das Lager nicht');
        $this->anmelden($pkLeo);
        $this->gleich(false, $this->status(200, $this->http('GET', $l), 'Leser sieht den Bestand')['darf']['buchen'], 'Leser darf nicht buchen');
        // Ein Buch, in dem Erfasser die Lager-Rechte aus einer früheren Fassung noch hat: Das erneute Aktivieren nimmt sie
        $this->u->psql(self::MANDANT . '__haupt', "INSERT INTO kern.rolle_baustein (rolle, baustein) SELECT r.id, b.id FROM kern.rolle r, kern.baustein b WHERE r.name = 'Erfasser' AND b.name = 'Lager bewegen'");
        $this->anmelden($pkEmil);
        $this->status(200, $this->http('GET', $l), 'Erfasser mit altem Baustein sieht das Lager');
        $aus = $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'lager', 'mandant' => 'lagerpraxis']);
        $this->ist(str_contains($aus, 'Rolle Erfasser ohne: Lager bewegen') && !str_contains($aus, 'Rolle Lager angelegt'), 'erneutes Aktivieren nimmt den Baustein und legt vorhandene Rollen nicht neu an');
        $this->anmelden($pkEmil);
        $this->status(403, $this->http('GET', $l), 'danach sieht der Erfasser das Lager nicht mehr');
        $this->anmelden($pkPia);
        $this->gleich(false, $this->status(200, $this->http('GET', $l), 'Prüfer sieht den Bestand')['darf']['buchen'], 'Prüfer darf nicht buchen');
        $this->status(403, $raus($ch['T-FRUEH']['id'], $raum, '1'), 'Prüfer entnimmt nicht');
    }

    /** Meta-Artikel (LAGER.md Abschnitt 17, F168): Klammer über Artikeln gleicher Funktion. Alle Artikel sind erfunden. */
    public function testMetaArtikel(): void
    {
        $l = self::P . '/lager';
        $this->mandantAnlegen('lagerpraxis', 'Lagerpraxis');
        [, $pkBert] = $this->nutzer('lagerpraxis', 'Bert', 'Buchhalter', ['Buchhalter']);
        [, $pkPia] = $this->nutzer('lagerpraxis', 'Pia', 'Pruefer', ['Prüfer']);
        $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'lager', 'mandant' => 'lagerpraxis']);
        $this->anmelden($pkBert);
        $tag = fn (int $n) => (new \DateTimeImmutable('today'))->modify(($n >= 0 ? '+' : '') . "$n days")->format('Y-m-d');
        $ort = $this->status(201, $this->http('POST', "$l/orte", ['name' => 'Schrank 1']), 'Lagerort')['id'];
        $art = fn (string $name, array $mehr = []) => $this->status(201, $this->http('POST', "$l/artikel",
            $mehr + ['bezeichnung' => "$name [TESTDATEN]", 'art' => 'verbrauch', 'einheit' => 'Stück', 'fuehrung' => 'charge', 'verfall_pflicht' => true]), "Artikel $name")['id'];
        $hA = $art('Musterhandschuh A', ['mindest' => '50', 'soll' => '100']);
        $hB = $art('Musterhandschuh B');
        $zu = fn (string $artikel, string $charge, int $tage, string $menge) => $this->status(201, $this->http('POST', "$l/zugaenge",
            ['zeilen' => [['artikel' => $artikel, 'ort' => $ort, 'menge' => $menge, 'charge' => $charge, 'verfall' => $tag($tage), 'grund' => 'anfangsbestand']]]), "Zugang $charge");
        $zu($hA, 'T-A1', 200, '30');
        $zu($hB, 'T-B1', 100, '40');

        // anlegen und prüfen
        $aufgabe = 'puderfrei, unsteril, Größe M; für Untersuchung und Reinigung [TESTDATEN]';
        $m = $this->status(201, $this->http('POST', "$l/meta", ['bezeichnung' => 'Einmalhandschuh M [TESTDATEN]', 'aufgabe' => "  $aufgabe ", 'einheit' => 'Stück', 'mindest' => '80', 'soll' => '200']), 'Meta-Artikel');
        $this->gleich(['Einmalhandschuh M [TESTDATEN]', $aufgabe, 'Stück', '80', '200', false, []], [$m['bezeichnung'], $m['aufgabe'], $m['einheit'], $m['mindest'], $m['soll'], $m['stillgelegt'], $m['mitglieder']],
            'angelegt mit Aufgabe (ohne Leerraum am Rand), ohne Mitglieder');
        $this->status(409, $this->http('POST', "$l/meta", ['bezeichnung' => 'einmalhandschuh m [testdaten]', 'aufgabe' => 'gleich [TESTDATEN]', 'einheit' => 'Stück']), 'dieselbe Bezeichnung');
        foreach (['ohne Bezeichnung' => ['bezeichnung' => ''], 'Soll unter Mindest' => ['mindest' => '10', 'soll' => '5'], 'unbekanntes Feld' => ['gtin' => '1'],
                  'Aufgabe leer' => ['aufgabe' => '  '], 'Aufgabe null' => ['aufgabe' => null], 'Aufgabe kein Text' => ['aufgabe' => 7], 'Aufgabe über 500 Zeichen' => ['aufgabe' => str_repeat('ä', 501)]] as $was => $ab) {
            $this->status(400, $this->http('POST', "$l/meta", $ab + ['bezeichnung' => 'Anderes [TESTDATEN]', 'aufgabe' => 'Papier [TESTDATEN]', 'einheit' => 'Stück']), "Meta-Artikel abgewiesen: $was");
        }
        // Aufgabe ist beim Anlegen Pflicht (STUECKLISTE.md AG2); genau 500 Zeichen gehen
        $ohne = $this->status(400, $this->http('POST', "$l/meta", ['bezeichnung' => 'Ohne Aufgabe [TESTDATEN]', 'einheit' => 'Stück']), 'ohne Aufgabe abgewiesen');
        $this->gleich(['aufgabe'], array_column($ohne['fehler'] ?? [], 'feld'), 'die Antwort nennt das Feld');
        $lang = $this->status(201, $this->http('POST', "$l/meta", ['bezeichnung' => 'Kopierpapier A4 [TESTDATEN]', 'aufgabe' => str_repeat('ä', 500), 'einheit' => 'Packung']), '500 Zeichen');
        $this->gleich(500, mb_strlen($lang['aufgabe']), 'Aufgabe mit 500 Zeichen gespeichert');

        // zuordnen: der eigene Mindestbestand des Artikels entfällt
        $a = $this->status(200, $this->http('POST', "$l/artikel/$hA/meta", ['meta' => $m['id']]), 'A zugeordnet');
        $this->gleich([$m['id'], null, null], [$a['meta_artikel'], $a['mindest'], $a['soll']], 'Mitglied ohne eigenen Mindest- und Sollbestand');
        $this->status(200, $this->http('POST', "$l/artikel/$hB/meta", ['meta' => $m['id']]), 'B zugeordnet');
        $this->status(400, $this->http('PUT', "$l/artikel/$hA", ['bezeichnung' => 'Musterhandschuh A [TESTDATEN]', 'art' => 'verbrauch', 'einheit' => 'Stück', 'fuehrung' => 'charge', 'verfall_pflicht' => true, 'mindest' => '10']),
            'Mindestbestand am Mitglied wird abgewiesen');

        // wer kein Mitglied sein darf
        $amp = $this->status(201, $this->http('POST', "$l/artikel", ['bezeichnung' => 'Musterampulle [TESTDATEN]', 'art' => 'arzneimittel', 'einheit' => 'Stück', 'fuehrung' => 'charge', 'verfall_pflicht' => true]), 'Arzneimittel')['id'];
        $ser = $this->status(201, $this->http('POST', "$l/artikel", ['bezeichnung' => 'Mustergerät [TESTDATEN]', 'art' => 'medizinprodukt', 'einheit' => 'Stück', 'fuehrung' => 'serie']), 'mit Seriennummer')['id'];
        $pck = $this->status(201, $this->http('POST', "$l/artikel", ['bezeichnung' => 'Musterpackung [TESTDATEN]', 'art' => 'verbrauch', 'einheit' => 'Packung', 'fuehrung' => 'ohne']), 'andere Einheit')['id'];
        foreach (['Arzneimittel' => $amp, 'andere Lagereinheit' => $pck] as $was => $id) {
            $this->status(409, $this->http('POST', "$l/artikel/$id/meta", ['meta' => $m['id']]), "kein Mitglied: $was");
        }
        $this->status(409, $this->http('PUT', "$l/artikel/$hB", ['bezeichnung' => 'Musterhandschuh B [TESTDATEN]', 'art' => 'arzneimittel', 'einheit' => 'Stück', 'fuehrung' => 'charge', 'verfall_pflicht' => true]),
            'ein Mitglied wird nicht zum Arzneimittel');
        $this->status(404, $this->http('POST', "$l/artikel/$hB/meta", ['meta' => '99999999-2222-4333-8444-555555555555']), 'unbekannter Meta-Artikel');

        // Stand: Summe, Zustand und Vorschlag über alle Mitglieder
        $s = $this->status(200, $this->http('GET', $l), 'Stand');
        $sm = array_column($s['meta'], null, 'id')[$m['id']];
        $this->gleich($aufgabe, $sm['aufgabe'], 'der Stand nennt die Aufgabe');
        $this->gleich(['70', '70', $tag(100), true, 'unter_mindest', 1], [$sm['bestand'], $sm['verfuegbar'], $sm['naechster_verfall'], $sm['unter_mindest'], $sm['zustand'], $s['zahlen']['unter_mindest']],
            'Summe 70 unter dem Mindestbestand 80; ein Meta-Artikel unter Mindestbestand, kein Artikel');
        $nachId = array_column($s['artikel'], null, 'id');
        $this->gleich([[false], [true], false, false], [array_column($nachId[$hA]['chargen'], 'vorschlag'), array_column($nachId[$hB]['chargen'], 'vorschlag'), $nachId[$hA]['unter_mindest'], $nachId[$hB]['unter_mindest']],
            'vorgeschlagen wird die Charge mit dem frühesten Verfall über alle Mitglieder – die von B');
        $cA = $nachId[$hA]['chargen'][0]['id'];
        $cB = $nachId[$hB]['chargen'][0]['id'];

        // Entnahme: aus A weicht vom Vorschlag ab (B verfällt früher), aus B nicht
        $this->gleich(true, $this->status(201, $this->http('POST', "$l/entnahmen", ['charge' => $cA, 'ort' => $ort, 'menge' => '5']), 'Entnahme aus A')['abweichung'], 'Abweichung über die Mitglieder hinweg vermerkt');
        $this->gleich(false, $this->status(201, $this->http('POST', "$l/entnahmen", ['charge' => $cB, 'ort' => $ort, 'menge' => '5']), 'Entnahme aus B')['abweichung'], 'der Vorschlag selbst ist keine Abweichung');

        // Nachbestellliste: eine Zeile für den Meta-Artikel, die Mitglieder darunter – nicht einzeln
        $n = $this->status(200, $this->http('GET', "$l/nachbestellung"), 'Nachbestellliste');
        $this->gleich([[], 1, '60', '140', ['Musterhandschuh A [TESTDATEN]', 'Musterhandschuh B [TESTDATEN]'], ['25', '35'], $aufgabe],
            [$n['artikel'], count($n['meta']), $n['meta'][0]['verfuegbar'], $n['meta'][0]['vorschlag'], array_column($n['meta'][0]['mitglieder'], 'bezeichnung'), array_column($n['meta'][0]['mitglieder'], 'verfuegbar'), $n['meta'][0]['aufgabe']],
            'verfügbar 60, bis zum Sollbestand 200 fehlen 140; die Liste nennt die Aufgabe');
        $zu($hB, 'T-B2', 300, '30');
        $this->gleich([], $this->status(200, $this->http('GET', "$l/nachbestellung"), 'nach dem Zugang')['meta'], 'mit 90 über dem Mindestbestand: nichts nachzubestellen');

        // ändern, stilllegen, lösen
        $this->status(409, $this->http('PUT', "$l/meta/{$m['id']}", ['bezeichnung' => $m['bezeichnung'], 'einheit' => 'Paar']), 'Lagereinheit steht fest, solange es Mitglieder gibt');
        $this->status(409, $this->http('PUT', "$l/meta/{$m['id']}", ['bezeichnung' => $m['bezeichnung'], 'einheit' => 'Stück', 'stillgelegt' => true]), 'mit Mitgliedern nicht stillzulegen');
        $g = $this->status(200, $this->http('PUT', "$l/meta/{$m['id']}", ['bezeichnung' => $m['bezeichnung'], 'einheit' => 'Stück', 'mindest' => '100']), 'Mindestbestand geändert');
        $this->gleich(['100', $aufgabe], [$g['mindest'], $g['aufgabe']], 'geändert; ohne den Schlüssel bleibt die Aufgabe, wie sie ist');
        // Aufgabe ändern geht, leeren nicht (AG2); die Änderung steht mit Vorher und Nachher im Protokoll (AG4)
        foreach (['leer' => '', 'null' => null] as $was => $w) {
            $this->status(400, $this->http('PUT', "$l/meta/{$m['id']}", ['bezeichnung' => $m['bezeichnung'], 'aufgabe' => $w, 'einheit' => 'Stück', 'mindest' => '100']), "Aufgabe leeren abgewiesen: $was");
        }
        $neueAufgabe = 'puderfrei, unsteril, Größe M; auch für die Reinigung der Geräte [TESTDATEN]';
        $this->gleich($neueAufgabe, $this->status(200, $this->http('PUT', "$l/meta/{$m['id']}", ['bezeichnung' => $m['bezeichnung'], 'aufgabe' => $neueAufgabe, 'einheit' => 'Stück', 'mindest' => '100']), 'Aufgabe geändert')['aufgabe'], 'neue Aufgabe');
        $this->gleich("$aufgabe|$neueAufgabe", trim($this->sql("SELECT (vorher->>'aufgabe') || '|' || (nachher->>'aufgabe') FROM kern.protokoll WHERE typ = 'lager.meta_geaendert' ORDER BY nr DESC LIMIT 1")), 'Protokoll mit Vorher und Nachher');
        // Gruppen von vor 0.7.83 haben keine Aufgabe: Sie bleiben gültig und änderbar, bis sie nachgetragen ist
        $this->sql("UPDATE lager.meta_artikel SET aufgabe = NULL WHERE id = '{$lang['id']}'");
        $altG = $this->status(200, $this->http('PUT', "$l/meta/{$lang['id']}", ['bezeichnung' => $lang['bezeichnung'], 'einheit' => 'Packung', 'mindest' => '5']), 'Gruppe ohne Aufgabe geändert');
        $this->gleich([null, '5'], [$altG['aufgabe'], $altG['mindest']], 'ohne Aufgabe weiter änderbar');
        $this->gleich(null, $this->status(200, $this->http('PUT', "$l/meta/{$lang['id']}", ['bezeichnung' => $lang['bezeichnung'], 'aufgabe' => '', 'einheit' => 'Packung']), 'leer bleibt leer')['aufgabe'], 'leer bleibt leer');
        $this->gleich('weiß, 80 g/m² [TESTDATEN]', $this->status(200, $this->http('PUT', "$l/meta/{$lang['id']}", ['bezeichnung' => $lang['bezeichnung'], 'aufgabe' => 'weiß, 80 g/m² [TESTDATEN]', 'einheit' => 'Packung']), 'nachgetragen')['aufgabe'], 'Aufgabe nachgetragen');
        $los = $this->status(200, $this->http('POST', "$l/artikel/$hA/meta", ['meta' => null]), 'A gelöst');
        $this->gleich([null, null], [$los['meta_artikel'], $los['mindest']], 'gelöst; der eigene Mindestbestand ist leer, bis er gesetzt wird');
        $s = $this->status(200, $this->http('GET', $l), 'Stand nach dem Lösen');
        $sm = array_column($s['meta'], null, 'id')[$m['id']];
        $this->gleich([[$hB], '65'], [$sm['mitglieder'], $sm['bestand']], 'nur noch B zählt');
        $this->status(200, $this->http('POST', "$l/artikel/$hB/meta", ['meta' => null]), 'B gelöst');
        $this->gleich(true, $this->status(200, $this->http('PUT', "$l/meta/{$m['id']}", ['bezeichnung' => $m['bezeichnung'], 'einheit' => 'Stück', 'stillgelegt' => true]), 'stillgelegt')['stillgelegt'], 'ohne Mitglieder stillgelegt');
        $this->status(409, $this->http('POST', "$l/artikel/$hB/meta", ['meta' => $m['id']]), 'einem stillgelegten Meta-Artikel wird nichts zugeordnet');

        // Seit 0.7.85 (F192): Auch Artikel mit Seriennummer gehören in eine Gruppe – Geräte gleicher Funktion ersetzen einander.
        // Die Gruppe zählt die Stücke; bewegt wird das einzelne Stück mit seiner Seriennummer.
        $gs = $this->status(201, $this->http('POST', "$l/meta", ['bezeichnung' => 'Mustergerät zum Messen [TESTDATEN]', 'aufgabe' => 'misst; jedes Gerät gleicher Funktion ersetzt das andere [TESTDATEN]', 'einheit' => 'Stück']), 'Gruppe für Geräte');
        $ser2 = $this->status(201, $this->http('POST', "$l/artikel", ['bezeichnung' => 'Mustergerät B [TESTDATEN]', 'art' => 'sonstiges', 'einheit' => 'Stück', 'fuehrung' => 'serie']), 'zweites Gerät')['id'];
        $this->gleich($gs['id'], $this->status(200, $this->http('POST', "$l/artikel/$ser/meta", ['meta' => $gs['id']]), 'Gerät mit Seriennummer zugeordnet')['meta_artikel'], 'Seriennummer in der Gruppe');
        $this->status(200, $this->http('POST', "$l/artikel/$ser2/meta", ['meta' => $gs['id']]), 'zweites Gerät zugeordnet');
        foreach ([[$ser, 'SN-T-0001'], [$ser, 'SN-T-0002'], [$ser2, 'SN-T-0101']] as [$art0, $sn]) {
            $this->status(201, $this->http('POST', "$l/zugaenge", ['zeilen' => [['artikel' => $art0, 'ort' => $ort, 'menge' => '1', 'charge' => $sn, 'grund' => 'anfangsbestand']]]), "Zugang $sn");
        }
        $sg = array_column($this->status(200, $this->http('GET', $l), 'Stand mit Geräten')['meta'], null, 'id')[$gs['id']];
        $this->gleich(['3', '3', 2], [$sg['bestand'], $sg['verfuegbar'], count($sg['mitglieder'])], 'die Gruppe zählt drei Stücke aus zwei Artikeln');
        $this->status(409, $this->http('PUT', "$l/artikel/$ser", ['bezeichnung' => 'Mustergerät [TESTDATEN]', 'art' => 'arzneimittel', 'einheit' => 'Stück', 'fuehrung' => 'serie']), 'auch ein Gerät in der Gruppe wird nicht zum Arzneimittel');

        // Stückliste am Artikel (0.7.86, F197): Artikel, Artikelgruppe oder freie Zeile – sie beschreibt nur, der Bestand bleibt
        $sl = fn (string $id, array $zeilen) => $this->http('PUT', "$l/artikel/$id/stueckliste", ['zeilen' => $zeilen]);
        $liste = [['artikel' => $pck, 'menge' => '1'], ['meta' => $lang['id'], 'menge' => '2', 'erforderlich' => false], ['bezeichnung' => ' Musterkabel [TESTDATEN] ', 'menge' => '1.5']];
        $r = $this->status(200, $sl($ser, $liste), 'Stückliste gesetzt');
        $this->gleich([[$pck, null, null, '1', true], [null, $lang['id'], null, '2', false], [null, null, 'Musterkabel [TESTDATEN]', '1.5', true]],
            array_map(fn (array $z) => [$z['artikel'], $z['meta'], $z['bezeichnung'], $z['menge'], $z['erforderlich']], $r['zeilen']), 'drei Zeilen in der Reihenfolge der Liste; erforderlich ist die Vorgabe');
        $st = array_column($this->status(200, $this->http('GET', $l), 'Stand mit Stückliste')['artikel'], null, 'id');
        $this->gleich([3, [], '2'], [count($st[$ser]['stueckliste']), $st[$pck]['stueckliste'], $st[$ser]['bestand']], 'der Stand nennt die Stückliste; der Bestand ist unverändert');
        $this->status(200, $sl($ser, $liste), 'dieselbe Liste noch einmal');
        $this->gleich('1|3|0', trim($this->sql("SELECT (SELECT count(*) FROM kern.protokoll WHERE typ = 'lager.stueckliste_gesetzt') || '|' || count(*) FILTER (WHERE beendet_am IS NULL) || '|' || count(*) FILTER (WHERE beendet_am IS NOT NULL) FROM lager.stueckliste_zeile WHERE artikel = '$ser'")),
            'unverändert: kein zweiter Protokolleintrag, keine beendeten Zeilen');
        foreach (['sich selbst' => [['artikel' => $ser, 'menge' => '1']], 'zwei Angaben' => [['artikel' => $pck, 'bezeichnung' => 'x', 'menge' => '1']], 'keine Angabe' => [['menge' => '1']],
                  'Menge null' => [['artikel' => $pck, 'menge' => '0']], 'Menge als Zahl' => [['artikel' => $pck, 'menge' => 1]], 'unbekanntes Feld' => [['artikel' => $pck, 'menge' => '1', 'preis' => '2']],
                  'erforderlich kein Wahrheitswert' => [['artikel' => $pck, 'menge' => '1', 'erforderlich' => 'ja']]] as $was => $zeilen) {
            $this->status(400, $sl($ser, $zeilen), "Stückliste abgewiesen: $was");
        }
        $this->status(400, $this->http('PUT', "$l/artikel/$ser/stueckliste", ['zeilen' => ['a' => 1]]), 'keine Liste');
        $this->status(409, $sl($ser, [['artikel' => '99999999-2222-4333-8444-555555555555', 'menge' => '1']]), 'unbekannter Artikel als Teil');
        $this->status(404, $sl('99999999-2222-4333-8444-555555555555', []), 'unbekannter Artikel');
        // kein Kreis – auch nicht über Umwege und nicht über eine Artikelgruppe
        $this->status(409, $sl($pck, [['artikel' => $ser, 'menge' => '1']]), 'Kreis: das Teil enthielte wieder das Ganze');
        $this->status(409, $sl($ser2, [['meta' => $gs['id'], 'menge' => '1']]), 'Kreis über die Gruppe, zu der der Artikel selbst gehört');
        $this->gleich(3, count(array_column($this->status(200, $this->http('GET', $l), 'Stand nach den Abweisungen')['artikel'], null, 'id')[$ser]['stueckliste']), 'nach einer Abweisung gilt die alte Liste weiter');
        // Baum: Das Teil hat selbst eine Stückliste
        $this->status(200, $sl($pck, [['bezeichnung' => 'Musterschraube [TESTDATEN]', 'menge' => '4']]), 'das Teil bekommt eine eigene Stückliste');
        // ersetzen: Die alten Zeilen bleiben stehen (nichts wird gelöscht), aufheben mit leerer Liste
        $this->gleich(1, count($this->status(200, $sl($ser, [['artikel' => $pck, 'menge' => '2']]), 'Stückliste ersetzt')['zeilen']), 'eine Zeile');
        $this->gleich('1|3', trim($this->sql("SELECT count(*) FILTER (WHERE beendet_am IS NULL) || '|' || count(*) FILTER (WHERE beendet_am IS NOT NULL) FROM lager.stueckliste_zeile WHERE artikel = '$ser'")), 'die drei alten Zeilen sind beendet, nicht gelöscht');
        $this->gleich([], $this->status(200, $sl($ser, []), 'Stückliste aufgehoben')['zeilen'], 'leer');
        $this->status(200, $sl($ser, [['artikel' => $pck, 'menge' => '1']]), 'wieder gesetzt');

        // Teile eines Stücks (0.7.88, F201): Der Zugang des Stücks bucht seine Teile mit – jedes mit eigenem Bestand, „verbaut in“
        $schale = $this->status(201, $this->http('POST', "$l/artikel", ['bezeichnung' => 'Musterschale [TESTDATEN]', 'art' => 'sonstiges', 'einheit' => 'Stück', 'fuehrung' => 'serie']), 'Teil mit Seriennummer')['id'];
        $ort2 = $this->status(201, $this->http('POST', "$l/orte", ['name' => 'Teileschrank [TESTDATEN]']), 'zweiter Lagerort')['id'];
        $zu = fn (array $teile, string $sn = 'T-G1') => $this->http('POST', "$l/zugaenge", ['zeilen' => [['artikel' => $ser2, 'ort' => $ort, 'menge' => '1', 'charge' => $sn, 'grund' => 'anfangsbestand', 'teile' => $teile]]]);
        $this->status(422, $zu([['artikel' => $schale, 'menge' => '1', 'charge' => '']]), 'Teil ohne Seriennummer: abgewiesen');
        $this->gleich('0', trim($this->sql("SELECT count(*) FROM lager.charge WHERE bezeichnung = 'T-G1'")), 'alles oder nichts: Auch das Stück ist nicht zugegangen');
        $this->status(400, $zu([['artikel' => 'x', 'menge' => '1']]), 'Teil ohne Artikel');
        $r = $this->status(201, $zu([['artikel' => $schale, 'menge' => '1', 'charge' => 'T-S1'], ['artikel' => $pck, 'menge' => '2']]), 'Zugang des Stücks mit zwei Teilen');
        $this->gleich(3, count($r['bewegungen']), 'drei Bewegungen: das Stück und seine Teile');
        $chargen = function () use ($l): array {
            $erg = [];
            foreach ($this->status(200, $this->http('GET', $l), 'Stand')['artikel'] as $x) {
                foreach ($x['chargen'] as $c) {
                    $erg[$x['id'] . '|' . $c['bezeichnung']] = $c;
                }
            }
            return $erg;
        };
        $c = $chargen();
        $stueck = $c["$ser2|T-G1"]; $teilS = $c["$schale|T-S1"]; $teilP = $c["$pck|"];
        $this->gleich([[$teilS['id'], '1'], [$teilP['id'], '2']], array_map(fn (array $t) => [$t['charge'], $t['menge']], $stueck['teile']), 'das Stück nennt seine Teile');
        $this->gleich([$stueck['id'], 'T-G1', '1'], [$teilS['verbaut_in'][0]['charge'], $teilS['verbaut_in'][0]['bezeichnung'], $teilS['menge']], 'das Teil hat eigenen Bestand und ist „verbaut in“');
        // ein verbautes Teil geht nicht allein
        $this->status(409, $this->http('POST', "$l/entnahmen", ['charge' => $teilS['id'], 'ort' => $ort, 'menge' => '1']), 'verbautes Teil: Entnahme abgewiesen');
        $this->status(409, $this->http('POST', "$l/umlagerungen", ['charge' => $teilS['id'], 'von' => $ort, 'nach' => $ort2, 'menge' => '1']), 'verbautes Teil: Umlagerung abgewiesen');
        // das Stück mit Teilen verlangt die Angabe, ob sie mitgehen
        $this->status(409, $this->http('POST', "$l/umlagerungen", ['charge' => $stueck['id'], 'von' => $ort, 'nach' => $ort2, 'menge' => '1']), 'Stück mit Teilen ohne Angabe: abgewiesen');
        $this->status(400, $this->http('POST', "$l/umlagerungen", ['charge' => $stueck['id'], 'von' => $ort, 'nach' => $ort2, 'menge' => '1', 'mit_teilen' => 'ja']), 'mit_teilen kein Wahrheitswert');
        $this->status(201, $this->http('POST', "$l/umlagerungen", ['charge' => $stueck['id'], 'von' => $ort, 'nach' => $ort2, 'menge' => '1', 'mit_teilen' => true]), 'Stück mit Teilen umgelagert');
        $c = $chargen();
        $this->gleich([$ort2, $ort2, 2], [$c["$ser2|T-G1"]['orte'][0]['ort'], $c["$schale|T-S1"]['orte'][0]['ort'], count($c["$ser2|T-G1"]['teile'])], 'die Teile sind mitgegangen und bleiben verbaut');
        $this->status(409, $this->http('POST', "$l/chargen/{$teilS['id']}/teile", ['teil' => $stueck['id']]), 'Kreis: Das Stück steckt nicht in seinem eigenen Teil');
        // lösen und wieder einbauen
        $this->status(200, $this->http('POST', "$l/teile/{$stueck['teile'][0]['id']}/loesen"), 'Teil gelöst');
        $this->status(409, $this->http('POST', "$l/teile/{$stueck['teile'][0]['id']}/loesen"), 'zweimal lösen: abgewiesen');
        $c = $chargen();
        $this->gleich([1, []], [count($c["$ser2|T-G1"]['teile']), $c["$schale|T-S1"]['verbaut_in']], 'gelöst: Das Teil ist frei und bleibt im Bestand');
        $this->status(201, $this->http('POST', "$l/chargen/{$stueck['id']}/teile", ['teil' => $teilS['id']]), 'wieder eingebaut');
        $this->status(409, $this->http('POST', "$l/chargen/{$stueck['id']}/teile", ['teil' => $teilS['id']]), 'doppelt eingebaut: abgewiesen');
        $this->status(409, $this->http('POST', "$l/chargen/{$teilP['id']}/teile", ['teil' => $teilS['id']]), 'Stück ohne Seriennummer: abgewiesen');
        // Entnahme des Stücks mit Teilen: alles geht, die Zugehörigkeit endet
        $this->status(201, $this->http('POST', "$l/entnahmen", ['charge' => $stueck['id'], 'ort' => $ort2, 'menge' => '1', 'mit_teilen' => true]), 'Stück mit Teilen entnommen');
        $c = $chargen();
        $this->gleich([false, false, false], [isset($c["$ser2|T-G1"]), isset($c["$schale|T-S1"]), isset($c["$pck|"]) && (float) $c["$pck|"]['menge'] >= 2], 'Stück und Teile sind aus dem Bestand');
        $this->gleich('0|3', trim($this->sql("SELECT count(*) FILTER (WHERE bis IS NULL) || '|' || count(*) FROM lager.stueck_teil")), 'keine Zugehörigkeit offen, nichts gelöscht');
        $this->gleich('3|3', trim($this->sql("SELECT count(*) FILTER (WHERE typ = 'lager.teil_eingebaut') || '|' || count(*) FILTER (WHERE typ = 'lager.teil_geloest') FROM kern.protokoll")), 'Protokoll: dreimal eingebaut, dreimal beendet');

        // Teile ohne eigenen Artikel (F212): Bezeichnung und Seriennummer am Stück – kein Bestand, keine Bewegung
        $r = $this->status(201, $this->http('POST', "$l/zugaenge", ['zeilen' => [['artikel' => $ser2, 'ort' => $ort, 'menge' => '1', 'charge' => 'T-G7', 'grund' => 'anfangsbestand',
            'teile' => [['bezeichnung' => ' Musterkabel [TESTDATEN] ', 'seriennummer' => 'T-K7'], ['bezeichnung' => 'Musterschraube [TESTDATEN]', 'menge' => '4'], ['artikel' => $schale, 'menge' => '1', 'charge' => 'T-S7']]]]]), 'Zugang mit Teilen ohne und mit Artikel');
        $this->gleich(2, count($r['bewegungen']), 'zwei Bewegungen: das Stück und das Teil, das ein Artikel ist – Teile ohne Artikel bewegen nichts');
        $g7 = $chargen()["$ser2|T-G7"];
        $this->gleich([['Musterkabel [TESTDATEN]', 'T-K7', '1'], ['Musterschraube [TESTDATEN]', null, '4']], array_map(fn (array $t) => [$t['bezeichnung'], $t['seriennummer'], $t['menge']], $g7['freiteile']), 'das Stück nennt seine Teile ohne Artikel');
        $this->status(422, $this->http('POST', "$l/chargen/{$g7['id']}/freiteile", ['bezeichnung' => 'Musterakku [TESTDATEN]', 'seriennummer' => 'T-A7', 'menge' => '2']), 'Seriennummer mit Menge 2: abgewiesen');
        $this->status(400, $this->http('POST', "$l/chargen/{$g7['id']}/freiteile", ['bezeichnung' => '']), 'ohne Bezeichnung: abgewiesen');
        $this->status(409, $this->http('POST', "$l/chargen/{$g7['id']}/freiteile", ['bezeichnung' => 'musterkabel [testdaten]', 'seriennummer' => 'T-K7']), 'dasselbe Teil mit derselben Seriennummer: abgewiesen');
        $ohneSn = trim($this->sql("SELECT c.id FROM lager.charge c JOIN lager.artikel a ON a.id = c.artikel WHERE NOT a.hat_serie ORDER BY c.id LIMIT 1"));
        $this->status(409, $this->http('POST', "$l/chargen/$ohneSn/freiteile", ['bezeichnung' => 'Musterakku [TESTDATEN]']), 'Stück ohne Seriennummer: abgewiesen');
        $neuT = $this->status(201, $this->http('POST', "$l/chargen/{$g7['id']}/freiteile", ['bezeichnung' => 'Musterakku [TESTDATEN]', 'seriennummer' => 'T-A7']), 'Teil nachgetragen')['id'];
        $this->status(200, $this->http('POST', "$l/freiteile/$neuT/loesen"), 'Teil gelöst');
        $this->status(409, $this->http('POST', "$l/freiteile/$neuT/loesen"), 'zweimal lösen: abgewiesen');
        $this->status(201, $this->http('POST', "$l/umlagerungen", ['charge' => $g7['id'], 'von' => $ort, 'nach' => $ort2, 'menge' => '1', 'mit_teilen' => true]), 'Umlagern: die Teile ohne Artikel bleiben am Stück');
        $this->gleich(2, count($chargen()["$ser2|T-G7"]['freiteile']), 'nach dem Umlagern weiter zwei Teile ohne Artikel');
        $this->status(201, $this->http('POST', "$l/ausbuchungen", ['charge' => $g7['id'], 'ort' => $ort2, 'menge' => '1', 'grund' => 'beschaedigt', 'mit_teilen' => true]), 'Ausbuchen des Stücks');
        $this->gleich('0|3', trim($this->sql("SELECT count(*) FILTER (WHERE bis IS NULL) || '|' || count(*) FROM lager.stueck_freiteil")), 'mit dem Stück enden seine Teile ohne Artikel; nichts gelöscht');

        // Eigenschaften statt „Führung“ (0.7.92, F211): einzeln, verbindbar, mit Bestand änderbar (EG1, EG2, EG5)
        $art = fn (string $name, array $mehr) => ['bezeichnung' => "$name [TESTDATEN]", 'art' => 'sonstiges', 'einheit' => 'Stück'] + $mehr;
        $neuA = fn (string $name, array $mehr) => $this->status(201, $this->http('POST', "$l/artikel", $art($name, $mehr)), "Artikel $name");
        $e1 = $neuA('Eigenschaft Charge', ['hat_charge' => true]);
        $this->gleich(['charge', true, false, false], [$e1['fuehrung'], $e1['hat_charge'], $e1['hat_serie'], $e1['in_stuecken']], 'hat Charge: „fuehrung“ ist der abgeleitete Wert');
        $this->gleich('serie', $neuA('Eigenschaft alt', ['fuehrung' => 'serie'])['fuehrung'], 'wer noch „fuehrung“ schickt, wird verstanden');
        $this->status(400, $this->http('POST', "$l/artikel", $art('Beides geschickt', ['fuehrung' => 'charge', 'hat_charge' => true])), 'fuehrung und Eigenschaften zugleich: abgewiesen');
        $this->status(400, $this->http('POST', "$l/artikel", $art('Serie ohne Stück', ['hat_serie' => true, 'in_stuecken' => false])), 'Seriennummer ohne „in Stücken“: abgewiesen');
        // in Stücken gezählt: nur ganze Zahlen
        $e2 = $neuA('Eigenschaft Stück', ['in_stuecken' => true]);
        $zuE = fn (array $z) => $this->http('POST', "$l/zugaenge", ['zeilen' => [$z + ['ort' => $ort, 'grund' => 'anfangsbestand']]]);
        $this->status(422, $zuE(['artikel' => $e2['id'], 'menge' => '1.5']), 'in Stücken gezählt: Bruchteil abgewiesen');
        $this->status(201, $zuE(['artikel' => $e2['id'], 'menge' => '3']), 'in Stücken gezählt: ganze Zahl');
        // Charge und Seriennummer zugleich: jedes Stück gehört zu einer Charge, der Verfall gilt für die Charge
        $e3 = $neuA('Eigenschaft beides', ['hat_charge' => true, 'hat_serie' => true, 'verfall_pflicht' => true]);
        $this->gleich(['serie', true, true, true], [$e3['fuehrung'], $e3['hat_charge'], $e3['hat_serie'], $e3['in_stuecken']], 'beides: Seriennummer führt, in Stücken folgt');
        $this->status(422, $zuE(['artikel' => $e3['id'], 'menge' => '1', 'charge' => 'T-B1', 'verfall' => '2031-01-31']), 'beides: ohne Charge abgewiesen');
        $this->status(201, $zuE(['artikel' => $e3['id'], 'menge' => '1', 'charge' => 'T-B1', 'los' => 'T-LOS', 'verfall' => '2031-01-31']), 'beides: Stück mit Charge');
        $this->status(422, $zuE(['artikel' => $e3['id'], 'menge' => '1', 'charge' => 'T-B2', 'los' => 'T-LOS', 'verfall' => '2031-02-28']), 'anderes Stück derselben Charge mit anderem Verfall: abgewiesen');
        $this->status(201, $zuE(['artikel' => $e3['id'], 'menge' => '1', 'charge' => 'T-B2', 'los' => 'T-LOS', 'verfall' => '2031-01-31']), 'zweites Stück derselben Charge');
        $this->status(422, $zuE(['artikel' => $e3['id'], 'menge' => '1', 'charge' => 'T-B1', 'los' => 'T-LOS', 'verfall' => '2031-01-31']), 'dieselbe Seriennummer noch einmal: abgewiesen');
        $this->status(422, $zuE(['artikel' => $e1['id'], 'menge' => '1', 'charge' => 'T-C1', 'los' => 'T-LOS']), 'Charge neben der Charge: abgewiesen, wenn der Artikel nicht beides hat');
        $st3 = array_column($this->status(200, $this->http('GET', $l), 'Stand')['artikel'], null, 'id')[$e3['id']];
        $this->gleich([['T-B1', 'T-LOS', false], ['T-B2', 'T-LOS', false]], array_map(fn (array $c) => [$c['bezeichnung'], $c['los'], $c['ohne_serie']], $st3['chargen']), 'der Stand nennt Seriennummer und Charge je Stück');
        // mit Bestand ändern: dazunehmen geht, wegnehmen nur, solange kein Bestand die Eigenschaft trägt
        $this->status(201, $zuE(['artikel' => $e1['id'], 'menge' => '5', 'charge' => 'T-C1']), 'Bestand mit Charge');
        $setze = fn (array $x, array $mehr) => $this->http('PUT', "$l/artikel/{$x['id']}", ['bezeichnung' => $x['bezeichnung'], 'art' => 'sonstiges', 'einheit' => 'Stück'] + $mehr);
        $this->status(409, $setze($e1, ['hat_charge' => false]), '„hat Charge“ wegnehmen bei Bestand mit Charge: abgewiesen');
        $r = $this->status(200, $setze($e1, ['hat_charge' => true, 'hat_serie' => true]), '„hat Seriennummer“ dazunehmen bei Bestand');
        $this->gleich(['serie', true], [$r['fuehrung'], $r['in_stuecken']], 'dazugenommen');
        $c1 = array_column($this->status(200, $this->http('GET', $l), 'Stand')['artikel'], null, 'id')[$e1['id']]['chargen'][0];
        $this->gleich(['T-C1', 'T-C1', true, '5'], [$c1['bezeichnung'], $c1['los'], $c1['ohne_serie'], $c1['menge']], 'der alte Bestand bleibt, trägt seine Charge und steht als „ohne Seriennummer“ da');
        $this->status(201, $this->http('POST', "$l/entnahmen", ['charge' => $c1['id'], 'ort' => $ort, 'menge' => '2']), 'der alte Bestand ist weiter entnehmbar');
        $this->status(201, $zuE(['artikel' => $e1['id'], 'menge' => '1', 'charge' => 'T-S9', 'los' => 'T-C1']), 'neues Stück mit Seriennummer in derselben Charge');
        $this->status(409, $setze($e1, ['hat_charge' => true]), '„hat Seriennummer“ wegnehmen bei Stück im Bestand: abgewiesen');
        $this->status(200, $setze($e2, ['in_stuecken' => true, 'hat_charge' => true]), '„hat Charge“ dazunehmen bei Bestand ohne Charge');
        $this->status(200, $setze($e2, ['in_stuecken' => true]), 'und wieder wegnehmen – kein Bestand trägt eine Charge');

        // Löschen (F214): nur, was nie Bestand hatte und in keiner geltenden Stückliste steht
        $falsch = $neuA('Falsch angelegt', ['hat_charge' => true]);
        $this->status(200, $sl($e2['id'], [['artikel' => $falsch['id'], 'menge' => '1']]), 'der falsche Artikel steht in einer Stückliste');
        $st = array_column($this->status(200, $this->http('GET', $l), 'Stand')['artikel'], null, 'id');
        $this->gleich([false, true, true, false], [$st[$falsch['id']]['bewegt'], $st[$falsch['id']]['in_stueckliste'], $st[$e1['id']]['bewegt'], $st[$e1['id']]['in_stueckliste']], 'der Stand sagt, was gegen das Löschen spricht');
        $this->status(409, $this->http('DELETE', "$l/artikel/{$falsch['id']}"), 'Artikel in einer Stückliste: nicht löschbar');
        $this->status(200, $sl($e2['id'], []), 'aus der Stückliste entfernt');
        $this->status(200, $sl($falsch['id'], [['bezeichnung' => 'Musterteil [TESTDATEN]', 'menge' => '1']]), 'er hat selbst eine Stückliste');
        $this->status(409, $this->http('DELETE', "$l/artikel/{$e1['id']}"), 'Artikel mit Bestand: nicht löschbar');
        $this->gleich(true, $this->status(200, $this->http('DELETE', "$l/artikel/{$falsch['id']}"), 'falsch angelegter Artikel gelöscht')['geloescht'], 'gelöscht');
        $this->status(404, $this->http('DELETE', "$l/artikel/{$falsch['id']}"), 'zweimal löschen: nicht gefunden');
        $this->gleich('1|0|' . 'Falsch angelegt [TESTDATEN]', trim($this->sql("SELECT (SELECT count(*) FROM kern.protokoll WHERE typ = 'lager.artikel_geloescht') || '|' || (SELECT count(*) FROM lager.stueckliste_zeile WHERE artikel = '{$falsch['id']}' OR teil_artikel = '{$falsch['id']}') || '|' || (SELECT bezeichnung FROM lager.stueckliste_zeile WHERE artikel = '{$e2['id']}' AND beendet_am IS NOT NULL ORDER BY beendet_am DESC LIMIT 1)")),
            'im Protokoll; seine Stückliste ist weg; die frühere Stückliste, die ihn nannte, behält seinen Namen als Text');
        $leer = $this->status(201, $this->http('POST', "$l/meta", ['bezeichnung' => 'Falsche Gruppe [TESTDATEN]', 'aufgabe' => 'falsch angelegt [TESTDATEN]', 'einheit' => 'Stück']), 'leere Gruppe');
        $this->status(409, $this->http('DELETE', "$l/meta/{$gs['id']}"), 'Gruppe mit Artikeln: nicht löschbar');
        $this->status(200, $this->http('DELETE', "$l/meta/{$leer['id']}"), 'leere Gruppe gelöscht');

        // Prüfer: sieht, pflegt nicht
        $this->anmelden($pkPia);
        $this->gleich(3, count($this->status(200, $this->http('GET', $l), 'Stand des Prüfers')['meta']), 'der Prüfer sieht die Meta-Artikel');
        $this->status(403, $this->http('POST', "$l/meta", ['bezeichnung' => 'X [TESTDATEN]', 'aufgabe' => 'x [TESTDATEN]', 'einheit' => 'Stück']), 'der Prüfer legt keinen an');
        $this->status(403, $this->http('POST', "$l/artikel/$hB/meta", ['meta' => null]), 'der Prüfer ordnet nicht zu');
        $this->status(403, $this->http('PUT', "$l/artikel/$ser/stueckliste", ['zeilen' => []]), 'der Prüfer ändert keine Stückliste');
        $this->status(403, $this->http('DELETE', "$l/artikel/$ser"), 'der Prüfer löscht keinen Artikel');
    }

    /** Eine Abfrage im Buch als Superuser, ohne Kopfzeile – für Werte, die keine Schnittstelle nennt (Protokoll, Altbestand). */
    private function sql(string $abfrage): string
    {
        return Umgebung::sh(sprintf('psql -X -q -At -v ON_ERROR_STOP=1 -h %s -U %s -d %s -c %s', escapeshellarg((string) getenv('KUMERO_TEST_PGHOST')),
            escapeshellarg((string) getenv('KUMERO_TEST_PGUSER')), self::MANDANT . '__haupt', escapeshellarg($abfrage)));
    }
}
