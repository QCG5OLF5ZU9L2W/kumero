<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

use Kumero\Plattform\Datenbank;
use Kumero\Plattform\Identitaet;
use Kumero\Plattform\Kernel;
use Kumero\Plattform\Konfiguration;
use Kumero\Tests\Hilfe\Umgebung;
use Kumero\Tests\Hilfe\Zugaenge;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Modul beschaffung (BESTELLANFORDERUNG.md Stufe 1, F222): Anforderung mit Positionen fürs Lager und nicht fürs Lager,
 * Vorschlag aus dem Bestand abzüglich dessen, was unterwegs ist, Weiterleiten an Rolle und Person, Freigabe und
 * Ablehnung, Abhaken als bestellt mit Artikelanlage, Teilen, Streichen, Zurücknehmen, Eingang von Hand; Sichtbarkeit
 * nach Rolle, Preise nur für den Einkauf; ein Buch ohne Lager.
 * Alle Artikel, Lieferanten, Nummern und Preise sind erfunden.
 */
final class BeschaffungTest extends Rahmen
{
    use Zugaenge;

    private const ORIGIN = Umgebung::ORIGIN;

    private Umgebung $u;
    private ?string $cookie = null;
    private int $schluessel = 0;

    public function vorher(): void
    {
        $this->u = new Umgebung(['kumero_einkaufpraxis', 'kumero_ohnelager']);
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

    /** Ein einzelner Wert aus der Datenbank des Buchs. */
    private function wert(string $sql): string
    {
        return trim(explode("\n", $this->u->psql('kumero_einkaufpraxis__haupt', $sql))[2] ?? '');
    }

    public function testBeschaffung(): void
    {
        $p = '/m/einkaufpraxis/b/haupt/api/v1';
        $b = "$p/beschaffung";
        $this->mandantAnlegen('einkaufpraxis', 'Einkaufpraxis');
        foreach (['lager', 'beschaffung'] as $modul) {
            $this->u->cli(['command' => 'modul:aktivieren', 'modul' => $modul, 'mandant' => 'einkaufpraxis']);
        }
        [, $pkVera] = $this->nutzer('einkaufpraxis', 'Vera', 'Verantwortlich', ['Verantwortlicher']);
        [$lena, $pkLena] = $this->nutzer('einkaufpraxis', 'Lena', 'Lager', ['Lager']);
        [$eva, $pkEva] = $this->nutzer('einkaufpraxis', 'Eva', 'Einkauf', ['Einkauf']);
        [$paul, $pkPaul] = $this->nutzer('einkaufpraxis', 'Paul', 'Pruefer', ['Prüfer']);
        [, $pkEmil] = $this->nutzer('einkaufpraxis', 'Emil', 'Erfasser', ['Erfasser']);
        $jahr = date('Y');
        $heute = date('Y-m-d');

        // Lager: ein Artikel unter Mindestbestand, eine Artikelgruppe mit einem Artikel
        $this->anmelden($pkVera);
        $hand = $this->status(201, $this->http('POST', "$p/lager/artikel", ['bezeichnung' => 'Musterhandschuh [TESTDATEN]', 'art' => 'verbrauch', 'einheit' => 'Stück',
            'hat_charge' => false, 'hat_serie' => false, 'in_stuecken' => true, 'mindest' => '10', 'soll' => '20']), 'Artikel mit Mindestbestand')['id'];
        $gruppe = $this->status(201, $this->http('POST', "$p/lager/meta", ['bezeichnung' => 'Mustertupfer [TESTDATEN]', 'aufgabe' => 'Tupfen im Test', 'einheit' => 'Stück']), 'Artikelgruppe')['id'];
        $tupfer = $this->status(201, $this->http('POST', "$p/lager/artikel", ['bezeichnung' => 'Mustertupfer klein [TESTDATEN]', 'art' => 'verbrauch', 'einheit' => 'Stück', 'fuehrung' => 'ohne']), 'Artikel der Gruppe')['id'];
        $this->status(200, $this->http('POST', "$p/lager/artikel/$tupfer/meta", ['meta' => $gruppe]), 'Artikel in die Gruppe');
        $fremd = $this->status(201, $this->http('POST', "$p/lager/artikel", ['bezeichnung' => 'Musterpflaster [TESTDATEN]', 'art' => 'verbrauch', 'einheit' => 'Stück', 'fuehrung' => 'ohne']), 'Artikel außerhalb der Gruppe')['id'];

        // wer nichts mit Anforderungen zu tun hat, sieht die Seite nicht
        $this->anmelden($pkEmil);
        $this->status(403, $this->http('GET', $b), 'Erfasser: kein Recht');

        // Lena (Lager) fordert an
        $this->anmelden($pkLena);
        $s = $this->status(200, $this->http('GET', $b), 'Stand');
        $this->gleich([[], true, ['alle' => false, 'anfordern' => true, 'freigeben' => false, 'bestellen' => false, 'preise' => false]], [$s['anforderungen'], $s['lager'], $s['darf']], 'leer; Lena darf anfordern');
        $this->gleich([['Musterhandschuh [TESTDATEN]', '20', '0', '20']], array_map(fn ($v) => [$v['bezeichnung'], $v['fehlt'], $v['unterwegs'], $v['vorschlag']], $s['vorschlag']), 'Vorschlag bis zum Sollbestand');
        $this->gleich([[], []], [$s['lieferanten'], (array) $s['bezug']], 'Lieferanten und letzter Bezug nur für den Einkauf');
        $this->ist(in_array(['name' => 'Einkauf', 'wozu' => ['freigabe', 'bestellen', 'rueckfrage']], $s['rollen'], true), 'Rolle Einkauf zur Auswahl');
        $this->gleich(['Eva Einkauf', 'Vera Verantwortlich'], array_values(array_map(fn ($x) => $x['name'], array_filter($s['personen'], fn ($x) => in_array('freigabe', $x['wozu'], true)))), 'wer freigeben darf');

        $pos = [['art' => 'lager', 'artikel' => $hand, 'menge' => '12'],
                ['art' => 'frei', 'bezeichnung' => 'Wartung Mustergerät [TESTDATEN]', 'einheit' => 'Stück', 'menge' => '1', 'vermerk' => 'jährlich'],
                ['art' => 'lager', 'bezeichnung' => 'Neues Musterteil [TESTDATEN]', 'einheit' => 'Stück', 'menge' => '5'],
                ['art' => 'lager', 'meta' => $gruppe, 'menge' => '100']];
        $f = $this->status(400, $this->http('POST', "$b/anforderungen", ['wofuer' => ' ', 'positionen' => [['art' => 'frei', 'artikel' => $hand, 'bezeichnung' => 'x', 'einheit' => 'Stück', 'menge' => '1'],
            ['art' => 'lager', 'artikel' => $hand, 'menge' => '1.5'], ['art' => 'frei', 'menge' => '0']]]), 'ungültige Anforderung');
        $this->gleich(['wofuer', 'positionen[0].artikel', 'positionen[1].menge', 'positionen[2].menge', 'positionen[2].bezeichnung', 'positionen[2].einheit'], array_column($f['fehler'], 'feld'), 'Fehler je Feld');
        $id = $this->status(201, $this->http('POST', "$b/anforderungen", ['wofuer' => 'Auffüllen Behandlungsraum [TESTDATEN]', 'termin' => $heute, 'positionen' => array_slice($pos, 0, 2)]), 'Entwurf')['id'];
        $this->status(200, $this->http('PUT', "$b/anforderungen/$id", ['wofuer' => 'Auffüllen Behandlungsraum [TESTDATEN]', 'positionen' => $pos]), 'Entwurf ändern');
        $s = $this->status(200, $this->http('GET', $b), 'Stand');
        $x = $s['anforderungen'][0];
        $this->gleich([null, 'entwurf', 'entwurf', true, true, 'Lena Lager', ['Musterhandschuh [TESTDATEN]', 'Wartung Mustergerät [TESTDATEN]', 'Neues Musterteil [TESTDATEN]', 'Mustertupfer [TESTDATEN]']],
            [$x['nummer'], $x['zustand'], $x['stand'], $x['eigene'], $x['bei_mir'], $x['von'], array_column($x['positionen'], 'bezeichnung')], 'Entwurf: Bezeichnungen kommen vom Lager');
        $this->gleich('20', $s['vorschlag'][0]['vorschlag'], 'ein Entwurf zieht vom Vorschlag nichts ab');
        $this->status(409, $this->http('POST', "$b/anforderungen/$id/weiterleiten", ['rolle' => 'Einkauf', 'wozu' => 'freigabe']), 'Entwurf lässt sich nicht weiterleiten');
        $this->gleich("BA-$jahr-0001", $this->status(200, $this->http('POST', "$b/anforderungen/$id/anfordern"), 'anfordern')['nummer'], 'Nummer');
        $this->status(409, $this->http('POST', "$b/anforderungen/$id/anfordern"), 'zweimal anfordern');
        $s = $this->status(200, $this->http('GET', $b), 'Stand');
        $this->gleich(['12', '8', false], [$s['vorschlag'][0]['unterwegs'], $s['vorschlag'][0]['vorschlag'], $s['anforderungen'][0]['bei_mir']], 'Angefordertes zieht der Vorschlag ab');
        $this->status(403, $this->http('POST', "$b/anforderungen/$id/freigeben"), 'Lena darf nicht freigeben');

        // Weiterleiten: an eine Rolle, die freigeben darf – nicht an sich selbst, nicht an jemanden, der es nicht darf
        $this->status(400, $this->http('POST', "$b/anforderungen/$id/weiterleiten", ['person' => "nutzer:$lena", 'wozu' => 'rueckfrage']), 'an sich selbst');
        $this->status(400, $this->http('POST', "$b/anforderungen/$id/weiterleiten", ['person' => "nutzer:$paul", 'wozu' => 'freigabe']), 'Prüfer gibt nicht frei');
        $this->status(400, $this->http('POST', "$b/anforderungen/$id/weiterleiten", ['rolle' => 'Lager', 'wozu' => 'freigabe']), 'Rolle Lager gibt nicht frei');
        $this->status(409, $this->http('POST', "$b/anforderungen/$id/weiterleiten", ['rolle' => 'Einkauf', 'wozu' => 'bestellen']), 'zum Bestellen erst nach der Freigabe');
        $this->status(204, $this->http('POST', "$b/anforderungen/$id/weiterleiten", ['rolle' => 'Einkauf', 'wozu' => 'freigabe', 'text' => 'Bitte bis Freitag']), 'an die Rolle Einkauf');
        $this->status(403, $this->http('POST', "$b/anforderungen/$id/uebernehmen"), 'Lena hat die Rolle nicht');

        // Paul (Prüfer) sieht alles, ohne Preise, und ändert nichts
        $this->anmelden($pkPaul);
        $s = $this->status(200, $this->http('GET', $b), 'Stand');
        $this->gleich([1, false, false, 'Einkauf', true], [count($s['anforderungen']), $s['darf']['preise'], $s['anforderungen'][0]['bei_mir'], $s['anforderungen'][0]['bei'], $s['anforderungen'][0]['bei_rolle']], 'Prüfer sieht alle');
        $this->status(403, $this->http('POST', "$b/anforderungen", ['wofuer' => 'x', 'positionen' => []]), 'Prüfer legt nichts an');
        $this->status(403, $this->http('POST', "$b/anforderungen/$id/weiterleiten", ['rolle' => 'Einkauf', 'wozu' => 'rueckfrage']), 'Prüfer leitet nicht weiter');

        // Eva (Einkauf) übernimmt, gibt frei und bestellt
        $this->anmelden($pkEva);
        $s = $this->status(200, $this->http('GET', $b), 'Stand');
        $x = $s['anforderungen'][0];
        $this->gleich([true, true, 'freigabe'], [$x['bei_mir'], $x['uebernehmbar'], $x['bei_wozu']], 'liegt bei Evas Rolle');
        [$p1, $p2, $p3, $p4] = array_column($x['positionen'], 'id');
        $this->status(204, $this->http('POST', "$b/anforderungen/$id/uebernehmen"), 'übernehmen');
        $this->status(409, $this->http('POST', "$b/anforderungen/$id/uebernehmen"), 'schon übernommen');
        $best = ['menge' => '15', 'lieferant' => 'Mustershop [TESTDATEN]', 'lieferant_nummer' => 'MS-1', 'bestellnummer' => 'B-1', 'preis' => '1.2345', 'vermerk' => '3 Packungen zu 5', 'gtin' => '04012345678901'];
        $this->status(409, $this->http('POST', "$b/positionen/$p1/bestellen", $best), 'bestellen erst nach der Freigabe');
        $this->status(400, $this->http('POST', "$b/anforderungen/$id/ablehnen", []), 'Ablehnen braucht einen Grund');
        $this->status(204, $this->http('POST', "$b/anforderungen/$id/freigeben"), 'freigeben');
        $this->status(409, $this->http('POST', "$b/anforderungen/$id/ablehnen", ['grund' => 'zu spät']), 'schon entschieden');
        $this->status(409, $this->http('PUT', "$b/anforderungen/$id", ['wofuer' => 'x', 'positionen' => $pos]), 'nach der Freigabe nicht mehr ändern');

        $f = $this->status(400, $this->http('POST', "$b/positionen/$p1/bestellen", ['menge' => '0', 'preis' => '1,20', 'gtin' => '123', 'bestellt_am' => '2999-01-01']), 'ungültige Bestellung');
        $this->gleich(['menge', 'lieferant', 'bestellt_am', 'preis', 'gtin'], array_column($f['fehler'], 'feld'), 'Fehler je Feld');
        $this->status(200, $this->http('POST', "$b/positionen/$p1/bestellen", $best), 'Position 1 bestellt: 15 statt 12');
        $this->status(409, $this->http('POST', "$b/positionen/$p1/bestellen", $best), 'schon bestellt');
        $this->gleich('04012345678901', $this->wert("SELECT gtin FROM lager.artikel WHERE id = '$hand'"), 'GTIN am Artikel ergänzt');
        $this->gleich('mustershop [testdaten]|ms-1|Mustershop [TESTDATEN]|MS-1', $this->wert("SELECT lieferant || '|' || nummer || '|' || lieferant_name || '|' || nummer_beleg FROM lager.lieferantenartikel WHERE artikel = '$hand'"), 'der Artikel lernt die Bezugsquelle');

        // nicht fürs Lager: kein Artikel; ein falscher Haken lässt sich zurücknehmen
        $this->status(400, $this->http('POST', "$b/positionen/$p2/bestellen", ['menge' => '1', 'lieferant' => 'Musterdienst [TESTDATEN]', 'artikel' => $hand]), 'frei: kein Artikel');
        $this->status(200, $this->http('POST', "$b/positionen/$p2/bestellen", ['menge' => '1', 'lieferant' => 'Falscher Shop [TESTDATEN]']), 'Position 2 bestellt');
        $this->status(204, $this->http('POST', "$b/positionen/$p2/zuruecknehmen"), 'Haken zurücknehmen');
        $this->status(409, $this->http('POST', "$b/positionen/$p2/zuruecknehmen"), 'nicht bestellt');
        $this->status(200, $this->http('POST', "$b/positionen/$p2/bestellen", ['menge' => '1', 'lieferant' => 'Musterdienst [TESTDATEN]', 'liefertermin' => '2000-01-01']), 'Position 2 neu bestellt');

        // noch kein Artikel: spätestens beim Bestellen wird er angelegt (BA12); der Rest bleibt als neue Position offen
        $this->status(409, $this->http('POST', "$b/positionen/$p3/bestellen", ['menge' => '4', 'lieferant' => 'Mustershop [TESTDATEN]']), 'Artikel fehlt');
        $neu = ['bezeichnung' => 'Musterteil A [TESTDATEN]', 'art' => 'sonstiges', 'einheit' => 'Stück', 'hat_charge' => false, 'hat_serie' => true, 'in_stuecken' => true];
        $this->status(400, $this->http('POST', "$b/positionen/$p3/bestellen", ['menge' => '2.5', 'lieferant' => 'Mustershop [TESTDATEN]', 'neuer_artikel' => $neu]), 'Stücke: ganze Zahl');
        $this->gleich('0', $this->wert("SELECT count(*) FROM lager.artikel WHERE bezeichnung = 'Musterteil A [TESTDATEN]'"), 'nach dem Fehler ist kein Artikel angelegt');
        $this->status(400, $this->http('POST', "$b/positionen/$p3/bestellen", ['menge' => '4', 'lieferant' => 'Mustershop [TESTDATEN]', 'neuer_artikel' => ['bezeichnung' => 'x']]), 'Artikel unvollständig');
        $rest = $this->status(200, $this->http('POST', "$b/positionen/$p3/bestellen", ['menge' => '4', 'lieferant' => 'Mustershop [TESTDATEN]', 'bestellnummer' => 'B-1', 'neuer_artikel' => $neu, 'rest' => '1']), 'Position 3 bestellt, Rest 1')['rest'];
        $this->ist(is_string($rest), 'Rest als neue Position');
        // Artikelgruppe: bestellt wird ein Artikel der Gruppe
        $this->status(400, $this->http('POST', "$b/positionen/$p4/bestellen", ['menge' => '100', 'lieferant' => 'Mustershop [TESTDATEN]', 'artikel' => $fremd]), 'Artikel gehört nicht zur Gruppe');
        $this->status(409, $this->http('POST', "$b/positionen/$p4/bestellen", ['menge' => '100', 'lieferant' => 'Mustershop [TESTDATEN]']), 'Gruppe: Artikel wählen');
        $this->status(400, $this->http('POST', "$b/positionen/$rest/streichen", []), 'Streichen braucht einen Grund');
        $this->status(204, $this->http('POST', "$b/positionen/$rest/streichen", ['grund' => 'reicht so']), 'Rest gestrichen');

        $s = $this->status(200, $this->http('GET', $b), 'Stand');
        $x = $s['anforderungen'][0];
        $this->gleich(['teilweise_bestellt', true, true, null], [$x['stand'], $x['bei_mir'], $x['ueberfaellig'], $x['bei']], 'teilweise bestellt; überfällig; liegt bei niemandem mehr');
        $this->gleich([['bestellt', '12', '15', '1.2345', 'Musterhandschuh [TESTDATEN]'], ['bestellt', '1', '1', null, 'Wartung Mustergerät [TESTDATEN]'], ['bestellt', '5', '4', null, 'Musterteil A [TESTDATEN]'],
                       ['offen', '100', null, null, 'Mustertupfer [TESTDATEN]'], ['gestrichen', '1', null, null, 'Neues Musterteil [TESTDATEN]']],
            array_map(fn ($q) => [$q['zustand'], $q['menge'], $q['bestellt_menge'], $q['preis'], $q['bezeichnung']], $x['positionen']), 'Positionen nach dem Bestellen');
        $this->gleich(['Musterdienst [TESTDATEN]', 'Mustershop [TESTDATEN]'], (function (array $l) { sort($l); return $l; })($s['lieferanten']), 'Lieferanten zur Auswahl – der zurückgenommene nicht');
        $this->gleich(['lieferant' => 'Mustershop [TESTDATEN]', 'lieferant_nummer' => 'MS-1', 'preis' => '1.2345', 'am' => $heute], $s['bezug'][$hand], 'letzter Bezug als Hinweis');
        $this->gleich(['15', '5'], [$s['vorschlag'][0]['unterwegs'], $s['vorschlag'][0]['vorschlag']], 'Vorschlag zieht die bestellte Menge ab');
        $this->status(200, $this->http('POST', "$b/positionen/$p4/bestellen", ['menge' => '120', 'lieferant' => 'Mustershop [TESTDATEN]', 'artikel' => $tupfer]), 'Position 4: Artikel der Gruppe');
        $this->status(204, $this->http('POST', "$b/anforderungen/$id/weiterleiten", ['person' => "nutzer:$lena", 'wozu' => 'rueckfrage', 'text' => 'Kommt die Wartung noch?']), 'Rückfrage an Lena');

        // Lena meldet den Eingang zur eigenen Anforderung – ohne Preise zu sehen
        $this->anmelden($pkLena);
        $this->status(403, $this->http('POST', "$b/positionen/$p1/zuruecknehmen"), 'Lena nimmt keinen Haken zurück');
        $this->status(422, $this->http('POST', "$b/positionen/$p1/erhalten", ['menge' => '10', 'am' => '2999-01-01']), 'Datum in der Zukunft');
        $this->gleich('bestellt', $this->status(200, $this->http('POST', "$b/positionen/$p1/erhalten", ['menge' => '10']), 'Teilmenge erhalten')['zustand'], 'noch nicht ganz da');
        $s = $this->status(200, $this->http('GET', $b), 'Stand');
        $x = $s['anforderungen'][0];
        $this->gleich(['teilweise_erhalten', true, 'rueckfrage', null, '10'], [$x['stand'], $x['bei_mir'], $x['bei_wozu'], $x['positionen'][0]['preis'], $x['positionen'][0]['erhalten_menge']], 'teilweise erhalten; Rückfrage bei Lena; kein Preis');
        $this->gleich('erhalten', $this->status(200, $this->http('POST', "$b/positionen/$p1/erhalten", ['menge' => '5']), 'Rest erhalten')['zustand'], 'ganz da');
        $this->status(409, $this->http('POST', "$b/positionen/$p1/erhalten", ['menge' => '1']), 'schon ganz erhalten');
        foreach ([$p2, $p4] as $q) {
            $this->status(200, $this->http('POST', "$b/positionen/$q/erhalten", ['menge' => $q === $p2 ? '1' : '120']), 'erhalten');
        }
        $this->gleich('erhalten', $this->status(200, $this->http('POST', "$b/positionen/$p3/erhalten", ['menge' => '3', 'fertig' => true]), 'Unterlieferung abschließen')['zustand'], 'abgeschlossen mit weniger');
        $s = $this->status(200, $this->http('GET', $b), 'Stand');
        $x = $s['anforderungen'][0];
        $this->gleich(['erledigt', '0', '20'], [$x['stand'], $s['vorschlag'][0]['unterwegs'], $s['vorschlag'][0]['vorschlag']], 'erledigt; der Bestand ist nicht gebucht – der Vorschlag steht wieder ganz da');
        $arten = array_column($x['verlauf'], 'art');
        $this->gleich(['angefordert', 'weitergeleitet', 'uebernommen', 'freigegeben', 'bestellt'], array_slice($arten, 0, 5), 'Verlauf beginnt mit dem Vorgang');
        $this->gleich(['15 statt 12 Stück', 'abgeschlossen mit 3 von 4'], [$x['verlauf'][4]['text'], explode(' – ', (string) end($x['verlauf'])['text'])[1] ?? null], 'Verlauf nennt die Abweichungen');
        $this->gleich('0', $this->wert("SELECT count(*) FROM kern.protokoll WHERE typ LIKE 'beschaffung.%' AND (vorher::text || nachher::text) ~ '1\\.2345|Mustershop'"), 'im Protokoll stehen weder Preis noch Lieferant');
        $this->status(409, $this->http('POST', "$b/anforderungen/$id/weiterleiten", ['rolle' => 'Einkauf', 'wozu' => 'freigabe']), 'schon freigegeben');

        // Entwurf löschen; zweite Anforderung ablehnen; dritte zurückziehen – die Nummern laufen weiter
        $e = $this->status(201, $this->http('POST', "$b/anforderungen", ['wofuer' => 'Versehen [TESTDATEN]', 'positionen' => []]), 'leerer Entwurf')['id'];
        $this->status(409, $this->http('POST', "$b/anforderungen/$e/anfordern"), 'ohne Position nicht anfordern');
        $this->gleich('geloescht', $this->status(200, $this->http('POST', "$b/anforderungen/$e/zurueckziehen"), 'Entwurf weg')['ergebnis'], 'ein Entwurf wird gelöscht');
        $frei = ['wofuer' => 'Musterabo [TESTDATEN]', 'positionen' => [['art' => 'frei', 'bezeichnung' => 'Musterzeitschrift [TESTDATEN]', 'einheit' => 'Jahr', 'menge' => '1']]];
        $zwei = $this->status(201, $this->http('POST', "$b/anforderungen", $frei), 'zweite')['id'];
        $this->gleich("BA-$jahr-0002", $this->status(200, $this->http('POST', "$b/anforderungen/$zwei/anfordern"), 'anfordern')['nummer'], 'zweite Nummer');
        $drei = $this->status(201, $this->http('POST', "$b/anforderungen", $frei), 'dritte')['id'];
        $this->status(200, $this->http('POST', "$b/anforderungen/$drei/anfordern"), 'anfordern');
        $this->gleich('zurueckgezogen', $this->status(200, $this->http('POST', "$b/anforderungen/$drei/zurueckziehen"), 'zurückziehen')['ergebnis'], 'angefordert: bleibt stehen');
        $this->anmelden($pkEva);
        $this->status(204, $this->http('POST', "$b/anforderungen/$zwei/ablehnen", ['grund' => 'Gibt es schon digital']), 'ablehnen');
        $this->status(409, $this->http('POST', "$b/anforderungen/$drei/freigeben"), 'zurückgezogen: nicht freigeben');
        $s = $this->status(200, $this->http('GET', $b), 'Stand');
        $this->gleich(['zurueckgezogen' => "BA-$jahr-0003", 'abgelehnt' => "BA-$jahr-0002", 'erledigt' => "BA-$jahr-0001"], array_column($s['anforderungen'], 'nummer', 'stand'), 'drei Anforderungen, nichts bei Eva offen');
        $this->gleich([false, false, false], array_column($s['anforderungen'], 'bei_mir'), 'nichts liegt bei Eva');
        $this->gleich('Gibt es schon digital', array_column($s['anforderungen'], 'grund', 'stand')['abgelehnt'], 'Grund der Ablehnung');
    }

    /** Ein Buch ohne Lager (BA16): nur Positionen „nicht fürs Lager“, kein Vorschlag. */
    public function testOhneLager(): void
    {
        $b = '/m/ohnelager/b/haupt/api/v1/beschaffung';
        $this->mandantAnlegen('ohnelager', 'Ohne Lager');
        $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'beschaffung', 'mandant' => 'ohnelager']);
        [, $pk] = $this->nutzer('ohnelager', 'Vera', 'Verantwortlich', ['Verantwortlicher']);
        $this->anmelden($pk);
        $s = $this->status(200, $this->http('GET', $b), 'Stand');
        $this->gleich([false, [], [], true], [$s['lager'], $s['artikel'], $s['vorschlag'], $s['darf']['bestellen']], 'ohne Lager kein Vorschlag');
        $f = $this->status(400, $this->http('POST', "$b/anforderungen", ['wofuer' => 'Mustertest [TESTDATEN]', 'positionen' => [['art' => 'lager', 'bezeichnung' => 'Musterteil [TESTDATEN]', 'einheit' => 'Stück', 'menge' => '1']]]), 'Position fürs Lager');
        $this->ist(str_contains($f['fehler'][0]['meldung'], 'nicht aktiv'), 'Meldung nennt den Grund');
        $id = $this->status(201, $this->http('POST', "$b/anforderungen", ['wofuer' => 'Mustertest [TESTDATEN]', 'positionen' => [['art' => 'frei', 'bezeichnung' => 'Musterwartung [TESTDATEN]', 'einheit' => 'Stück', 'menge' => '1']]]), 'frei')['id'];
        $this->status(200, $this->http('POST', "$b/anforderungen/$id/anfordern"), 'anfordern');
        $this->status(204, $this->http('POST', "$b/anforderungen/$id/freigeben"), 'wer beide Rechte hat, gibt die eigene frei (BA5)');
        $s = $this->status(200, $this->http('GET', $b), 'Stand');
        $pos = $s['anforderungen'][0]['positionen'][0]['id'];
        $this->status(200, $this->http('POST', "$b/positionen/$pos/bestellen", ['menge' => '1', 'lieferant' => 'Musterdienst [TESTDATEN]', 'preis' => '99']), 'bestellen');
        $this->status(200, $this->http('POST', "$b/positionen/$pos/erhalten", ['menge' => '1']), 'erhalten');
        $s = $this->status(200, $this->http('GET', $b), 'Stand');
        $this->gleich(['erledigt', '99.00'], [$s['anforderungen'][0]['stand'], $s['anforderungen'][0]['positionen'][0]['preis']], 'erledigt, mit Preis');
    }
}
