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
 * Modul anlagen (ANLAGEN.md, F61, F129): Einstellungen des Buchs, Anlagegüter, Rechnung des Geschäftsjahres, Abgang,
 * Vermerk der Buchungen des AfA-Laufs, Kostenstelle am Gut, Gemietetes und Geleastes (LAGER.md IN9, IN10). Die Rechnung selbst prüft AnlagenRechnerTest. Alle Güter sind erfunden.
 */
final class AnlagenTest extends Rahmen
{
    use Zugaenge;

    private const ORIGIN = Umgebung::ORIGIN;
    private const MANDANT = 'kumero_anlpraxis';
    private const P = '/m/anlpraxis/b/haupt/api/v1';

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

    public function testAnlagen(): void
    {
        $p = self::P;
        $an = "$p/anlagen";
        $this->mandantAnlegen('anlpraxis', 'Anlagenpraxis');
        [, $pkBert] = $this->nutzer('anlpraxis', 'Bert', 'Buchhalter', ['Buchhalter']);
        [, $pkEmil] = $this->nutzer('anlpraxis', 'Emil', 'Erfasser', ['Erfasser']);
        $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'anlagen', 'mandant' => 'anlpraxis']);
        $this->anmelden($pkBert);
        $jahr = 'kennung=2026&beginn=2026-01-01&ende=2026-12-31';

        // Einstellungen: der Vorsteuerabzug ist eine Sache des Buchs und anfangs nicht festgelegt
        $s = $this->status(200, $this->http('GET', $an), 'Stand ohne Jahr');
        $this->gleich([null, 'null', '', [], true, true], [$s['einstellung']['vorsteuerabzug'], $s['einstellung']['restwert'], $s['einstellung']['anlagekonten'], $s['gueter'], $s['darf_pflegen'], $s['darf_lauf']], 'leer, nichts festgelegt');
        $this->ist(count($s['regelwerk']['nutzungsdauern']) > 20 && $s['regelwerk']['grenzen'][0]['gwg_bis'] === '800.00', 'Regelwerk: Grenzen und Nutzungsdauern');
        $this->status(204, $this->http('PUT', "$an/einstellung", ['vorsteuerabzug' => false, 'restwert' => 'erinnerung', 'anlagekonten' => '0100-0899']), 'Einstellungen');
        $this->status(400, $this->http('PUT', "$an/einstellung", ['vorsteuerabzug' => 'vielleicht', 'restwert' => 'null']), 'ungültige Einstellung');

        // Vorschau: Vorschlag nach dem Nettobetrag, Plan über die Jahre – speichert nichts
        $v = $this->status(200, $this->http('POST', "$an/plan", ['zugang' => '2026-03-15', 'kosten' => '773.50', 'netto' => '650.00', 'art' => 'gwg', 'jahresbeginn' => '2026-01-01']), 'Vorschau GWG');
        $this->gleich([['gwg', 'sammelposten', 'linear'], 'gwg', 1, '773.50'], [$v['vorschlag']['arten'], $v['vorschlag']['vorschlag'], count($v['plan']), $v['plan'][0]['afa']], 'Grenze am Nettobetrag, abgesetzt werden die Kosten (brutto)');
        $this->status(400, $this->http('POST', "$an/plan", ['zugang' => '2026-03-15', 'kosten' => '1200.00', 'art' => 'linear', 'jahresbeginn' => '2026-01-01']), 'linear ohne Nutzungsdauer');

        // Anlagegut anlegen: Nutzungsdauer weicht vom Vorschlag der Tabelle ab, mit Begründung
        $stuhl = ['bezeichnung' => 'Behandlungsliege (Muster)', 'inventarnr' => 'INV-001', 'zugang' => '2026-03-15', 'kosten' => '1200.00', 'art' => 'linear', 'nutzungsdauer' => 3,
                  'nd_vorschlag' => 13, 'nd_quelle' => 'Büromöbel (AV 6.15)', 'nd_begruendung' => 'gebraucht gekauft', 'restwert' => 'null', 'bestandskonto' => '0420', 'aufwandskonto' => '4830'];
        $g = $this->status(201, $this->http('POST', "$an/gueter", $stuhl), 'Anlagegut');
        $this->gleich([3, 13, 'gebraucht gekauft', '1200.00', null], [$g['nutzungsdauer'], $g['nd_vorschlag'], $g['nd_begruendung'], $g['kosten'], $g['abgang']], 'gespeichert mit Vorschlag und Begründung');
        $this->status(409, $this->http('POST', "$an/gueter", $stuhl), 'dieselbe Inventarnummer');
        foreach (['ohne Aufwandskonto' => ['aufwandskonto' => null], 'Kosten 0' => ['kosten' => '0.00'], 'Art' => ['art' => 'degressiv'], 'Übernahme vor Zugang' => ['uebernahme' => '2026-01-01', 'bisherige_afa' => '10.00'],
                  'bisher ohne Stichtag' => ['bisherige_afa' => '10.00'], 'unbekanntes Feld' => ['abgang' => '2026-12-01']] as $was => $ab) {
            $this->status(400, $this->http('POST', "$an/gueter", $ab + ['inventarnr' => null] + $stuhl), "abgewiesen: $was");
        }
        $gwg = $this->status(201, $this->http('POST', "$an/gueter", ['bezeichnung' => 'Regal (Muster)', 'zugang' => '2026-05-02', 'kosten' => '773.50', 'netto' => '650.00', 'art' => 'gwg',
            'nutzungsdauer' => 13, 'bestandskonto' => '0480', 'aufwandskonto' => '4855']), 'GWG');
        $this->gleich([null, 'null'], [$gwg['nutzungsdauer'], $gwg['restwert']], 'ohne Verteilung wird keine Nutzungsdauer mitgeführt');

        // Rechnung des Geschäftsjahres
        $s = $this->status(200, $this->http('GET', "$an?$jahr"), 'Stand 2026');
        $j = array_column(array_map(fn ($x) => ['b' => $x['bezeichnung']] + $x['jahr'], $s['gueter']), null, 'b');
        $this->gleich(['0.00', '333.33', '866.67', true, []], [$j['Behandlungsliege (Muster)']['buchwert_anfang'], $j['Behandlungsliege (Muster)']['afa'], $j['Behandlungsliege (Muster)']['buchwert_ende'],
            $j['Behandlungsliege (Muster)']['zugang_im_jahr'], $j['Behandlungsliege (Muster)']['vermerke']], 'linear: zehn Monate im ersten Jahr');
        $this->gleich(['773.50', '0.00'], [$j['Regal (Muster)']['afa'], $j['Regal (Muster)']['buchwert_ende']], 'GWG: ganz im Jahr der Anschaffung');

        // AfA-Lauf: die Seite legt den Entwurf im Kern an und meldet ihn; der Lauf ist wiederholbar
        $buchung = '11111111-2222-4333-8444-555555555555';
        $this->status(204, $this->http('POST', "$an/vermerke", ['gut' => $g['id'], 'jahr' => '2026', 'buchung' => $buchung, 'zweck' => 'afa', 'betrag' => '333.33']), 'Vermerk');
        $this->status(204, $this->http('POST', "$an/vermerke", ['gut' => $g['id'], 'jahr' => '2026', 'buchung' => $buchung, 'zweck' => 'afa', 'betrag' => '333.33']), 'derselbe Vermerk noch einmal');
        $s = $this->status(200, $this->http('GET', "$an?$jahr"), 'Stand mit Vermerk');
        $this->gleich([['buchung' => $buchung, 'zweck' => 'afa', 'betrag' => '333.33']], $s['gueter'][0]['jahr']['vermerke'], 'ein Vermerk, nicht zwei');
        $this->gleich([], $this->status(200, $this->http('GET', "$an?kennung=2027&beginn=2027-01-01&ende=2027-12-31"), 'Stand 2027')['gueter'][0]['jahr']['vermerke'], 'im nächsten Jahr noch nichts gebucht');
        $this->status(404, $this->http('POST', "$an/vermerke", ['gut' => '99999999-2222-4333-8444-555555555555', 'jahr' => '2026', 'buchung' => $buchung, 'zweck' => 'afa', 'betrag' => '1.00']), 'unbekanntes Gut');
        $this->status(204, $this->http('POST', "$an/vermerke/entfernen", ['gut' => $g['id'], 'jahr' => '2026', 'buchung' => $buchung]), 'Vermerk entfernt (Entwurf gelöscht)');

        // Abgang: AfA bis zum Monat des Abgangs, der Rest geht mit
        $ab = $this->status(200, $this->http('POST', "$an/gueter/{$g['id']}/abgang", ['datum' => '2027-06-10', 'art' => 'verkauf']), 'Abgang');
        $this->gleich(['2027-06-10', 'verkauf'], [$ab['abgang'], $ab['abgang_art']], 'Abgang eingetragen');
        $j27 = $this->status(200, $this->http('GET', "$an?kennung=2027&beginn=2027-01-01&ende=2027-12-31"), 'Stand 2027')['gueter'][0]['jahr'];
        $this->gleich(['866.67', '200.00', '666.67', '0.00', true], [$j27['buchwert_anfang'], $j27['afa'], $j27['abgang_rest'], $j27['buchwert_ende'], $j27['abgang_im_jahr']], '2027: AfA und Rest ergeben den Buchwert vom Jahresanfang');
        $this->status(400, $this->http('POST', "$an/gueter/{$g['id']}/abgang", ['datum' => '2026-01-01', 'art' => 'verkauf']), 'Abgang vor dem Zugang');
        $this->status(400, $this->http('PUT', "$an/gueter/{$g['id']}", ['zugang' => '2028-01-01', 'inventarnr' => 'INV-001'] + $stuhl), 'Zugang nach dem Abgang');
        $this->gleich(null, $this->status(200, $this->http('POST', "$an/gueter/{$g['id']}/abgang", ['datum' => null]), 'Abgang zurückgenommen')['abgang'], 'wieder da');

        // ändern: die alte Fassung steht im Protokoll
        $neu = $this->status(200, $this->http('PUT', "$an/gueter/{$g['id']}", ['nutzungsdauer' => 13, 'nd_begruendung' => 'egal'] + $stuhl), 'geändert');
        $this->gleich([13, 'egal'], [$neu['nutzungsdauer'], $neu['nd_begruendung']], 'Nutzungsdauer geändert');

        // Kostenstelle am Gut (LAGER.md IN10): der Name eines Tags, den die Seite in die AfA-Entwürfe übernimmt
        $mitKst = $this->status(200, $this->http('PUT', "$an/gueter/{$g['id']}", ['kostenstelle' => ' Praxis Nord '] + $stuhl), 'Kostenstelle gesetzt');
        $this->gleich('Praxis Nord', $mitKst['kostenstelle'], 'Kostenstelle gespeichert, ohne Leerraum am Rand');
        $this->gleich(null, $this->status(200, $this->http('PUT', "$an/gueter/{$g['id']}", $stuhl), 'Kostenstelle entfernt')['kostenstelle'], 'ohne Kostenstelle');
        $this->status(400, $this->http('PUT', "$an/gueter/{$g['id']}", ['kostenstelle' => str_repeat('x', 101)] + $stuhl), 'Kostenstelle zu lang');

        // Gemietet und geleast (LAGER.md IN9): da, aber nicht Eigentum – ohne Kosten, Konten und Absetzung
        $leih = ['bezeichnung' => 'Monitor, geleast (Muster)', 'inventarnr' => 'L-001', 'zugang' => '2026-04-01', 'art' => 'fremd', 'standort' => 'Empfang', 'kostenstelle' => 'Praxis Nord'];
        $f = $this->status(201, $this->http('POST', "$an/gueter", $leih), 'fremdes Gut');
        $this->gleich(['fremd', null, null, null, 'Empfang', 'Praxis Nord', '0.00'], [$f['art'], $f['kosten'], $f['bestandskonto'], $f['aufwandskonto'], $f['standort'], $f['kostenstelle'], $f['bisherige_afa']], 'ohne Kosten und Konten gespeichert');
        foreach (['mit Kosten' => ['kosten' => '500.00'], 'mit Bestandskonto' => ['bestandskonto' => '0420'], 'mit Aufwandskonto' => ['aufwandskonto' => '4830'],
                  'mit Buchung' => ['buchung' => $buchung], 'mit Übernahme' => ['uebernahme' => '2026-06-01']] as $was => $zu) {
            $this->status(400, $this->http('POST', "$an/gueter", $zu + ['inventarnr' => null] + $leih), "fremd abgewiesen: $was");
        }
        $this->gleich(['vorschlag' => null, 'plan' => []], $this->status(200, $this->http('POST', "$an/plan", ['zugang' => '2026-04-01', 'art' => 'fremd', 'jahresbeginn' => '2026-01-01']), 'Vorschau fremd'), 'nichts abzusetzen, kein Vorschlag');
        $s = $this->status(200, $this->http('GET', "$an?$jahr"), 'Stand mit fremdem Gut');
        $jf = array_values(array_filter($s['gueter'], fn ($x) => $x['art'] === 'fremd'))[0]['jahr'];
        $this->gleich(['0.00', '0.00', '0.00', '0.00', false], [$jf['buchwert_anfang'], $jf['afa'], $jf['abgang_rest'], $jf['buchwert_ende'], $jf['zugang_im_jahr']], 'fremd: kein Wert, keine Absetzung, kein Zugang im Verzeichnis');
        $this->status(400, $this->http('POST', "$an/vermerke", ['gut' => $f['id'], 'jahr' => '2026', 'buchung' => $buchung, 'zweck' => 'afa', 'betrag' => '1.00']), 'kein AfA-Vermerk an Fremdem');
        // eigen bleibt eigen, fremd bleibt fremd
        $this->status(400, $this->http('PUT', "$an/gueter/{$f['id']}", ['inventarnr' => 'L-001'] + $stuhl), 'aus fremd wird nicht eigen');
        $this->status(400, $this->http('PUT', "$an/gueter/{$g['id']}", ['inventarnr' => 'INV-001'] + $leih), 'aus eigen wird nicht fremd');
        $this->gleich('Lager', $this->status(200, $this->http('PUT', "$an/gueter/{$f['id']}", ['standort' => 'Lager'] + $leih), 'fremdes Gut geändert')['standort'], 'Standort geändert');
        // zurückgegeben wird nur Fremdes; Fremdes wird nicht verkauft
        $this->status(400, $this->http('POST', "$an/gueter/{$f['id']}/abgang", ['datum' => '2027-03-31', 'art' => 'verkauf']), 'Fremdes wird nicht verkauft');
        $this->status(400, $this->http('POST', "$an/gueter/{$g['id']}/abgang", ['datum' => '2027-03-31', 'art' => 'rueckgabe']), 'Eigenes wird nicht zurückgegeben');
        $rg = $this->status(200, $this->http('POST', "$an/gueter/{$f['id']}/abgang", ['datum' => '2027-03-31', 'art' => 'rueckgabe']), 'Rückgabe');
        $this->gleich(['2027-03-31', 'rueckgabe'], [$rg['abgang'], $rg['abgang_art']], 'Rückgabe eingetragen');

        // Erfasser: sieht, pflegt nicht
        $this->anmelden($pkEmil);
        $s = $this->status(200, $this->http('GET', "$an?$jahr"), 'Stand des Erfassers');
        $this->gleich([3, false, false], [count($s['gueter']), $s['darf_pflegen'], $s['darf_lauf']], 'sehen ja, pflegen und Lauf nein');
        $this->status(403, $this->http('POST', "$an/gueter", $stuhl), 'Erfasser legt nichts an');
        $this->status(403, $this->http('PUT', "$an/einstellung", ['vorsteuerabzug' => true, 'restwert' => 'null']), 'Erfasser ändert die Einstellungen nicht');
        $menue = $this->status(200, $this->http('GET', "$p/oberflaeche/menue"), 'Menü');
        $buchen = array_values(array_filter($menue['bereiche'], fn ($b) => $b['id'] === 'buchen'))[0] ?? ['eintraege' => []];
        $this->ist(in_array('anlagen.anlagen', array_column($buchen['eintraege'], 'id'), true), '„Anlagen“ steht im Bereich Buchführung');
    }
}
