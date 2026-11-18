<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

use Kumero\Modul\Arbeitsvorrat\Aehnlich;
use Kumero\Modul\Import\Csv;
use Kumero\Plattform\Datenbank;
use Kumero\Plattform\Identitaet;
use Kumero\Plattform\Kernel;
use Kumero\Plattform\Konfiguration;
use Kumero\Plattform\Webauthn\Base64Url;
use Kumero\Tests\Hilfe\Authentifikator;
use Kumero\Tests\Hilfe\Umgebung;
use Symfony\Component\HttpFoundation\Request;

/**
 * Modul import (IMPORT.md, F104): CSV lesen, Einnahme/Ausgabe bestimmen, Schlüssel der Zeilen, markierte Fehlerzeilen;
 * Markierung vermutlich gleicher Vorgänge im Arbeitsvorrat (C8). Die ersten Tests sind reine Unittests ohne Datenbank.
 * Nur synthetische Daten.
 */
final class ImportTest extends Rahmen
{
    private const MANDANT = 'kumero_importpraxis';
    private const P = '/m/importpraxis/api/v1';
    private const BANK = "Musterbank;Umsatzliste\nKonto;DE00 0000 0000\n\nBuchungstag;Valuta;Name;Verwendungszweck;Betrag;Währung;Saldo\n"
        . "02.03.2026;03.03.2026;Musterlieferant GmbH;RE-0815;-109,43;EUR;890,57\n04.03.2026;04.03.2026;Musterpatient;Honorar;1.250,00;EUR;2.140,57\n";

    private ?Umgebung $u = null;
    private ?string $cookie = null;
    private int $schluessel = 0;

    public function nachher(): void
    {
        $this->u?->abbauen();
        $this->u = null;
    }

    /** @return array<string, mixed> */
    private static function einstellungen(string $roh, array $mehr = []): array
    {
        $e = Csv::erkennen($roh);
        $t = Csv::lesen($roh, $e);
        [$ok, $f] = Csv::pruefen(array_replace($e + Csv::vorschlag($t['kopf']) + ['geldkonto' => '1200', 'buchungsart' => null, 'zahlweise' => 'unbar'], $mehr));
        if ($f !== []) {
            throw new \RuntimeException(json_encode($f, JSON_UNESCAPED_UNICODE));
        }
        return $ok;
    }

    public function testAufbauErkennen(): void
    {
        $e = Csv::erkennen(self::BANK);
        $this->gleich(['utf-8', ';', 4, 'TT.MM.JJJJ', ','], [$e['zeichensatz'], $e['trenner'], $e['kopfzeile'], $e['datum'], $e['dezimal']], 'Bankexport mit Vorspann: Kopfzeile in Zeile 4');
        $t = Csv::lesen(self::BANK, $e);
        $this->gleich([['Buchungstag', 'Valuta', 'Name', 'Verwendungszweck', 'Betrag', 'Währung', 'Saldo'], 2, 5], [$t['kopf'], count($t['zeilen']), $t['zeilen'][0]['nr']], 'Spalten und Zeilen mit Zeilennummer der Datei');
        $v = Csv::vorschlag($t['kopf']);
        $this->gleich([['Buchungstag'], ['Valuta'], ['Name'], ['Verwendungszweck'], ['Betrag'], 'vorzeichen'],
            [$v['spalten']['datum'], $v['spalten']['zahltag'], $v['spalten']['gegenpartei'], $v['spalten']['betreff'], $v['spalten']['betrag'], $v['richtung']['art']], 'Vorschlag aus den Spaltennamen');
        $us = "Date,Payee,Category,Amount\n03/02/2026,Musterhandel,Office,\"1,234.50\"\n";
        $e2 = Csv::erkennen(mb_convert_encoding($us, 'Windows-1252', 'UTF-8'));
        $this->gleich([',', 1, 'MM/TT/JJJJ', '.'], [$e2['trenner'], $e2['kopfzeile'], $e2['datum'], $e2['dezimal']], 'Komma als Trenner, Dezimalpunkt, amerikanisches Datum');
        $this->gleich('windows-1252', Csv::erkennen("Datum;Empf\xE4nger;Betrag\n01.02.2026;M\xFCller;1,00\n")['zeichensatz'], 'Windows-1252 erkannt');
    }

    public function testBetragUndDatum(): void
    {
        $this->gleich([-10943, 125000, 1250, -1250, -1250, 123450, 500, null, null, null],
            [Csv::betrag('-109,43', ','), Csv::betrag('1.250,00 €', ','), Csv::betrag('12,5', ','), Csv::betrag('12,50-', ','), Csv::betrag('(12,50)', ','),
             Csv::betrag('1,234.50', '.'), Csv::betrag('+5', ','), Csv::betrag('abc', ','), Csv::betrag('', ','), Csv::betrag('1.2.3', ',')], 'Beträge in Cent, Unlesbares ist null');
        $this->gleich(['2026-03-02', '2026-03-02', '2026-03-02', null, null], [Csv::datum('02.03.2026', 'TT.MM.JJJJ'), Csv::datum('02.03.26', 'TT.MM.JJ'), Csv::datum('2026-03-02', 'JJJJ-MM-TT'),
            Csv::datum('31.02.2026', 'TT.MM.JJJJ'), Csv::datum('2026-03-02', 'TT.MM.JJJJ')], 'Datum streng in der genannten Form');
    }

    public function testRichtung(): void
    {
        // V – Vorzeichen: negativ = Ausgabe; das Geldkonto geht mit
        $e = self::einstellungen(self::BANK);
        $r = Csv::anwenden(Csv::lesen(self::BANK, $e), $e);
        $v = array_column($r['zeilen'], 'vorschlag');
        $this->gleich([['ausgabe', '109.43', '1200', '2026-03-02', '2026-03-03', 'Musterlieferant GmbH', 'RE-0815'], ['einnahme', '1250.00', '1200']],
            [[$v[0]['richtung'], $v[0]['betrag'], $v[0]['geldkonto'], $v[0]['datum'], $v[0]['zahltag'], $v[0]['gegenpartei'], $v[0]['betreff']], [$v[1]['richtung'], $v[1]['betrag'], $v[1]['geldkonto']]],
            'Vorzeichen: Ausgabe und Einnahme, Betrag positiv, Buchungsdatum = Valuta');
        $this->gleich(['anfang' => 100000, 'ende' => 214057, 'stimmt' => true], $r['salden'], 'Saldenkontrolle geht auf');
        // V′ – umgekehrt
        $e2 = self::einstellungen(self::BANK, ['richtung' => ['art' => 'vorzeichen_umgekehrt']]);
        $this->gleich(['einnahme', 'ausgabe'], array_column(Csv::anwenden(Csv::lesen(self::BANK, $e2), $e2)['zeilen'], 'richtung'), 'Vorzeichen umgekehrt');
        // Z – zwei Spalten; beide gefüllt oder beide leer ist ein Fehler
        $zwei = "Datum;Text;Einnahme;Ausgabe\n01.03.2026;Miete;;800,00\n02.03.2026;Honorar;95,00;\n03.03.2026;beides;1,00;2,00\n04.03.2026;nichts;;\n";
        $e3 = self::einstellungen($zwei);
        $z = Csv::anwenden(Csv::lesen($zwei, $e3), $e3)['zeilen'];
        $this->gleich(['zwei_spalten', 'ausgabe', 'einnahme', null, null], [$e3['richtung']['art'], $z[0]['richtung'], $z[1]['richtung'], $z[2]['richtung'], $z[3]['richtung']], 'zwei Spalten');
        $this->gleich(['Einnahme und Ausgabe sind beide gefüllt', 'Weder Einnahme noch Ausgabe gefüllt'], [$z[2]['fehler'], $z[3]['fehler']], 'nicht bestimmbar: Fehler, nicht geraten');
        // K – Kennzeichen
        $kz = "Datum;Text;Betrag;S/H\n01.03.2026;Miete;800,00;S\n02.03.2026;Honorar;95,00;h\n03.03.2026;unklar;5,00;X\n";
        $e4 = self::einstellungen($kz);
        $z = Csv::anwenden(Csv::lesen($kz, $e4), $e4)['zeilen'];
        $this->gleich(['kennzeichen', 'ausgabe', 'einnahme', null], [$e4['richtung']['art'], $z[0]['richtung'], $z[1]['richtung'], $z[2]['richtung']], 'Kennzeichen, Groß-/Kleinschreibung egal, Unbekanntes ist ein Fehler');
        // F – fest
        $e5 = self::einstellungen(self::BANK, ['richtung' => ['art' => 'fest', 'fest' => 'ausgabe']]);
        $this->gleich(['ausgabe', 'ausgabe'], array_column(Csv::anwenden(Csv::lesen(self::BANK, $e5), $e5)['zeilen'], 'richtung'), 'fest: alles Ausgabe');
    }

    public function testFehlerzeilenUndSchluessel(): void
    {
        $roh = "Datum;Name;Buchungstext;Betrag;Währung;Vermerk\n01.03.2026;Musterhandel;Papier;-12,50;EUR;a\n01.03.2026;Musterhandel;Papier;-12,50;EUR;b\n"
             . "02.03.2026;Musterhandel;kaputt;abc;EUR;c\n03.03.2026;Fernhandel;Dollar;-20,00;USD;d\n";
        $e = self::einstellungen($roh);
        $z = Csv::anwenden(Csv::lesen($roh, $e), $e)['zeilen'];
        $this->ist(substr($z[0]['schluessel'], 0, -1) === substr($z[1]['schluessel'], 0, -1) && str_ends_with($z[0]['schluessel'], '#1') && str_ends_with($z[1]['schluessel'], '#2'),
            'zwei gleiche Zeilen in einer Datei: gleicher Schlüssel mit laufender Nummer');
        $this->gleich($z[0]['schluessel'], Csv::anwenden(Csv::lesen($roh, $e), $e)['zeilen'][0]['schluessel'], 'derselbe Inhalt ergibt immer denselben Schlüssel (überlappende Auszüge)');
        // fehlerhafte Zeile: wird nicht verworfen, sondern markiert – ohne Betrag und Richtung, mit allen Spalten als Angabe (C9, C10)
        $this->gleich(['Betrag „abc“ nicht lesbar', false, false, 'kaputt', '2026-03-02'],
            [$z[2]['fehler'], isset($z[2]['vorschlag']['betrag']), isset($z[2]['vorschlag']['geldkonto']), $z[2]['vorschlag']['betreff'], $z[2]['vorschlag']['datum']], 'fehlerhafte Zeile behält, was lesbar ist');
        $this->ist(str_contains($z[2]['vorschlag']['hinweise'][0]['text'], 'Zeile 4 der Datei') && in_array(['bezeichnung' => 'Betrag', 'wert' => 'abc'], $z[2]['angaben'], true), 'Hinweis nennt die Zeile, die ganze Zeile steht in den Angaben');
        // nicht zugeordnete Spalte bleibt als Angabe am Eintrag
        $this->gleich([['bezeichnung' => 'Vermerk', 'wert' => 'a']], $z[0]['angaben'], 'nicht zugeordnete Spalten gehen nicht verloren');
        // Fremdwährung: kein Betrag, Hinweis
        $this->ist(!isset($z[3]['vorschlag']['betrag']) && str_contains($z[3]['vorschlag']['hinweise'][0]['text'], 'USD'), 'Fremdwährung wird nicht als Betrag übernommen');
        [, $f] = Csv::pruefen(['zeichensatz' => 'utf-8']);
        $this->ist(count($f) >= 5, 'unvollständige Einstellungen werden abgewiesen');
    }

    public function testAehnlich(): void
    {
        $g = Aehnlich::gruppen([
            ['id' => 'a', 'betrag' => '109.43', 'gegenpartei' => 'Musterlieferant GmbH', 'belegnummer' => 'RE-0815'],
            ['id' => 'b', 'betrag' => '109.43', 'gegenpartei' => 'MUSTERLIEFERANT GMBH BERLIN', 'belegnummer' => null],
            ['id' => 'c', 'betrag' => '109.43', 'gegenpartei' => 'Anderer Handel', 'belegnummer' => null],
            ['id' => 'd', 'betrag' => '50.00', 'gegenpartei' => 'Musterlieferant GmbH', 'belegnummer' => null],
            ['id' => 'e', 'betrag' => '20.00', 'gegenpartei' => null, 'belegnummer' => 'KB 77'],
            ['id' => 'f', 'betrag' => '20', 'gegenpartei' => 'Bäckerei', 'belegnummer' => 'kb-77'],
            ['id' => 'g', 'betrag' => null, 'gegenpartei' => 'Musterlieferant GmbH', 'belegnummer' => null],
        ]);
        $this->gleich(['a' => 1, 'b' => 1, 'e' => 2, 'f' => 2], $g, 'gleicher Betrag und gleiche Gegenpartei oder Belegnummer bilden eine Gruppe; alles andere bleibt unmarkiert');
        $this->gleich(['musterlieferant', 'mueller'], [Aehnlich::kern('Musterlieferant GmbH & Co. KG'), Aehnlich::kern('Müller e.K.')], 'Name ohne Rechtsform');
    }

    // --- über REST: einlesen, Geldkonto als Vorauswahl, Doppelte, Zurückziehen -----------------------------

    /** @return array{0: int, 1: mixed} */
    private function http(string $methode, string $pfad, mixed $koerper = null): array
    {
        $k = Konfiguration::laden();
        $server = ['HTTPS' => 'on', 'HTTP_HOST' => 'kumero.test', 'HTTP_ORIGIN' => Umgebung::ORIGIN, 'CONTENT_TYPE' => is_string($koerper) ? 'text/csv' : 'application/json'];
        if ($methode !== 'GET' && str_starts_with($pfad, '/m/')) {
            $server['HTTP_IDEMPOTENCY_KEY'] = 'imp-' . (++$this->schluessel) . '-' . bin2hex(random_bytes(4));
        }
        $inhalt = is_string($koerper) ? $koerper : ($koerper === null ? null : json_encode($koerper === [] ? new \stdClass() : $koerper));
        $r = Request::create('https://kumero.test' . $pfad, $methode, [], $this->cookie ? [Identitaet::COOKIE => $this->cookie] : [], [], $server, $inhalt);
        $antwort = (new Kernel($k, new Datenbank($k)))->bearbeiten($r);
        foreach ($antwort->headers->getCookies() as $c) {
            if ($c->getName() === Identitaet::COOKIE) {
                $this->cookie = $c->isCleared() ? null : $c->getValue();
            }
        }
        return [$antwort->getStatusCode(), json_decode((string) $antwort->getContent(), true)];
    }

    private function ok(int $erwartet, array $erg, string $was): mixed
    {
        $this->gleich($erwartet, $erg[0], $was . ' – ' . json_encode($erg[1], JSON_UNESCAPED_UNICODE));
        return $erg[1];
    }

    public function testEinlesenUeberRest(): void
    {
        $this->u = new Umgebung([self::MANDANT]);
        $this->u->aufbauen();
        $aus = $this->u->cli(['command' => 'nutzer:anlegen', 'name' => 'Erika Test', '--verwalter' => true]);
        preg_match('/Nutzer angelegt: ([0-9a-f-]{36})/', $aus, $m);
        preg_match('/#registrierung=([A-Za-z0-9_-]+)/', $aus, $t);
        $pk = new Authentifikator('kumero.test', Umgebung::ORIGIN, -7);
        $opt = $this->ok(200, $this->http('POST', '/api/v1/registrierung/optionen', ['token' => $t[1]]), 'Optionen');
        $this->ok(201, $this->http('POST', '/api/v1/registrierung', ['token' => $t[1]] + $pk->registrieren(Base64Url::dekodieren($opt['challenge']))), 'registriert');
        $opt = $this->ok(200, $this->http('POST', '/api/v1/anmeldung/optionen'), 'Anmeldeoptionen');
        $this->ok(200, $this->http('POST', '/api/v1/anmeldung', $pk->anmelden(Base64Url::dekodieren($opt['challenge']))), 'angemeldet');
        $this->u->cli(['command' => 'mandant:anlegen', 'kennung' => 'importpraxis', 'name' => 'Importpraxis']);
        $this->u->cli(['command' => 'zugang:gewaehren', 'nutzer' => $m[1], 'mandant' => 'importpraxis']);
        foreach (['buchen', 'arbeitsvorrat', 'import'] as $modul) {
            $this->u->cli(['command' => 'modul:aktivieren', 'modul' => $modul, 'mandant' => 'importpraxis']);
        }
        $p = self::P;
        $erk = $this->ok(200, $this->http('POST', "$p/import/erkennen", self::BANK), 'Aufbau erkannt');
        $this->gleich([4, 2, [], false], [$erk['merkmale']['kopfzeile'], $erk['zeilen_anzahl'], $erk['profile'], $erk['schon_eingelesen']], 'Merkmale, noch kein Profil');
        $e = $erk['merkmale'] + $erk['vorschlag'] + ['geldkonto' => '1200', 'buchungsart' => null, 'zahlweise' => 'unbar'];
        $frage = '?' . http_build_query(['einstellungen' => json_encode($e), 'name' => 'umsaetze-maerz.csv', 'profil' => 'Musterbank Giro']);
        $vor = $this->ok(200, $this->http('POST', "$p/import/vorschau$frage", self::BANK), 'Vorschau');
        $this->gleich([2, 2, 0, 0, '1250.00', '109.43', true], [$vor['zeilen'], $vor['neu'], $vor['vorhanden'], $vor['fehlerhaft'], $vor['einnahmen'], $vor['ausgaben'], $vor['salden']['stimmt']], 'Vorschau: zwei neue Zeilen, Summen, Salden');
        $this->ok(400, $this->http('POST', "$p/import/vorschau?" . http_build_query(['einstellungen' => json_encode(['geldkonto' => '1200'])]), self::BANK), 'unvollständige Einstellungen abgewiesen');
        $ein = $this->ok(201, $this->http('POST', "$p/import/einliefern$frage", self::BANK), 'eingeliefert');
        $this->gleich(2, $ein['neu'], 'zwei Einträge');
        $l = $this->ok(200, $this->http('GET', "$p/arbeitsvorrat/eintraege?abgleich=nein"), 'Arbeitsvorrat')['eintraege'];
        $this->gleich([['RE-0815', 'ausgabe', '109.43', null, '1200', 'unbar', '2026-03-03', ['import']], ['Honorar', 'einnahme', '1250.00', '1200', null]],
            [[$l[0]['betreff'], $l[0]['richtung'], $l[0]['betrag'], $l[0]['vor_konto'], $l[0]['vor_gegenkonto'], $l[0]['zahlweise'], $l[0]['zahltag'], $l[0]['quellen']],
             [$l[1]['betreff'], $l[1]['richtung'], $l[1]['betrag'], $l[1]['vor_konto'], $l[1]['vor_gegenkonto']]],
            'Geldkonto als Vorauswahl: Ausgabe im Haben, Einnahme im Soll (C4, C5)');
        // dieselbe Datei noch einmal und ein überlappender Auszug: nichts doppelt
        $this->gleich(true, $this->ok(200, $this->http('POST', "$p/import/erkennen", self::BANK), 'erneut erkannt')['schon_eingelesen'], 'dieselbe Datei wird am Hash erkannt');
        $mehr = self::BANK . "05.03.2026;05.03.2026;Musterhandel;Papier;-12,50;EUR;2.128,07\n06.03.2026;;Unklar;kaputt;abc;EUR;2.128,07\n";
        $vor = $this->ok(200, $this->http('POST', "$p/import/vorschau$frage", $mehr), 'überlappender Auszug');
        $this->gleich([4, 2, 2, 1], [$vor['zeilen'], $vor['neu'], $vor['vorhanden'], $vor['fehlerhaft']], 'zwei schon vorhanden, zwei neu, davon eine mit Fehler');
        $ein2 = $this->ok(201, $this->http('POST', "$p/import/einliefern$frage", $mehr), 'zweiter Import');
        $l = $this->ok(200, $this->http('GET', "$p/arbeitsvorrat/eintraege?abgleich=nein"), 'Arbeitsvorrat')['eintraege'];
        $this->gleich(4, count($l), 'vier Einträge, keiner doppelt');
        $kaputt = array_values(array_filter($l, fn ($x) => $x['betreff'] === 'kaputt'))[0];
        $this->ist($kaputt['betrag'] === null && str_contains($kaputt['hinweise'][0]['text'], 'Fehler beim Einlesen'), 'fehlerhafte Zeile liegt markiert im Vorrat (C10)');
        // Profil speichern und wiedererkennen
        $this->ok(200, $this->http('PUT', "$p/import/profile", ['name' => 'Musterbank Giro', 'fuer' => 'mandant', 'kopf' => $erk['kopf'], 'einstellungen' => $e]), 'Profil gespeichert');
        $erk2 = $this->ok(200, $this->http('POST', "$p/import/erkennen", $mehr), 'mit Profil');
        $this->gleich([['Musterbank Giro', 'mandant', '1200']], array_map(fn ($x) => [$x['name'], $x['geltung'], $x['einstellungen']['geldkonto']], $erk2['profile']), 'Profil an der Kopfzeile wiedererkannt');
        // Zurückziehen: erst wenn nichts bearbeitet ist
        $this->ok(200, $this->http('POST', "$p/import/importe/{$ein2['import']}/zurueckziehen", []), 'zweiter Import zurückgezogen');
        $this->gleich(2, count($this->ok(200, $this->http('GET', "$p/arbeitsvorrat/eintraege?abgleich=nein"), 'Arbeitsvorrat')['eintraege']), 'seine beiden Einträge sind aus dem Vorrat');
        $this->ok(409, $this->http('POST', "$p/import/importe/{$ein2['import']}/zurueckziehen", []), 'nicht zweimal');
        $this->ok(200, $this->http('POST', "$p/arbeitsvorrat/eintraege/{$l[0]['id']}/verwerfen", ['grund' => 'Musterfall']), 'Eintrag des ersten Imports verworfen');
        $this->gleich('import.bearbeitet', $this->ok(409, $this->http('POST', "$p/import/importe/{$ein['import']}/zurueckziehen", []), 'bearbeiteter Import')['code'], 'ein bearbeiteter Import bleibt');
        $imp = $this->ok(200, $this->http('GET', "$p/import/importe"), 'Importe')['importe'];
        $this->gleich([[2, 2, 1, true], [2, 0, 0, false]], array_map(fn ($x) => [$x['neu'], $x['vorhanden'], $x['fehlerhaft'], $x['zurueckgezogen_am'] !== null], $imp), 'Bericht je Import');
        // nach dem Zurückziehen lässt sich der Auszug wieder einlesen
        $this->gleich(2, $this->ok(200, $this->http('POST', "$p/import/vorschau$frage", $mehr), 'Vorschau nach dem Zurückziehen')['neu'], 'zurückgezogene Zeilen gelten wieder als neu');
    }
}
