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
}
