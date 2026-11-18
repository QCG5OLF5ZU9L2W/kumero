<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

use Kumero\Modul\Arbeitsvorrat\Aehnlich;
use Kumero\Modul\Arbeitsvorrat\Zusammen;
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
        // Spaltennamen eines Bankexports mit „Zahlungsgrund“, „Buchungsart“ und getrennten Spalten Soll/Haben
        $v2 = Csv::vorschlag(['Kontoinhaber', 'Kontonummer/IBAN', 'Wertstellung', 'Buchungsdatum', 'Empfaenger/Auftraggeber', 'Soll EUR', 'Haben EUR', 'Buchungsart', 'Zahlungsgrund', 'Referenz', 'Saldo EUR', 'IBAN Gegenkonto']);
        $this->gleich([['Zahlungsgrund'], ['Buchungsart'], ['Soll EUR'], ['Haben EUR'], ['Wertstellung'], ['Buchungsdatum'], ['Referenz'], 'zwei_spalten'],
            [$v2['spalten']['betreff'], $v2['spalten']['art'], $v2['spalten']['ausgabe'], $v2['spalten']['einnahme'], $v2['spalten']['zahltag'], $v2['spalten']['datum'], $v2['spalten']['belegnummer'], $v2['richtung']['art']],
            'Zahlungsgrund → Betreff, Buchungsart → Art, Soll/Haben → zwei Spalten');
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
        $liste = [['id' => 'a', 'betrag' => '10.00', 'gegenpartei' => 'Musterhandel', 'belegnummer' => null], ['id' => 'b', 'betrag' => '10.00', 'gegenpartei' => 'Musterhandel', 'belegnummer' => null],
                  ['id' => 'c', 'betrag' => '10.00', 'gegenpartei' => 'Musterhandel', 'belegnummer' => null]];
        $this->gleich([['a' => 1, 'b' => 1, 'c' => 1], ['b' => 1, 'c' => 1], []], [Aehnlich::gruppen($liste), Aehnlich::gruppen($liste, ['a|b' => true, 'a|c' => true]),
            Aehnlich::gruppen($liste, ['a|b' => true, 'a|c' => true, 'b|c' => true])], '„kein Duplikat“ nimmt Paare aus der Markierung');
        $this->gleich(['musterlieferant', 'mueller'], [Aehnlich::kern('Musterlieferant GmbH & Co. KG'), Aehnlich::kern('Müller e.K.')], 'Name ohne Rechtsform');
    }

    public function testZusammenVorschlag(): void
    {
        $beleg = ['id' => 'B', 'eingegangen_am' => '2026-03-05T10:00:00Z', 'belege' => [['link' => 'https://paperless.example.org/documents/1/']], 'betreff' => 'Rechnung Musterlieferant',
            'gegenpartei' => 'Musterlieferant GmbH', 'datum' => '2026-02-27', 'belegnummer' => 'RE-0815', 'betrag' => '109.43', 'netto' => '91.96', 'steuer' => '17.47',
            'zahlweise' => null, 'zahltag' => null, 'richtung' => 'ausgabe', 'art' => 'rechnung', 'gegenpartei_kennung' => null];
        $umsatz = ['id' => 'U', 'eingegangen_am' => '2026-03-04T10:00:00Z', 'belege' => [], 'betreff' => 'RE-0815', 'gegenpartei' => 'MUSTERLIEFERANT GMBH BERLIN', 'datum' => '2026-03-02',
            'belegnummer' => null, 'betrag' => '109.43', 'netto' => null, 'steuer' => null, 'zahlweise' => 'unbar', 'zahltag' => '2026-03-03', 'richtung' => 'ausgabe', 'art' => null,
            'gegenpartei_kennung' => 'DE00000000000000000000'];
        foreach ([[$beleg, $umsatz], [$umsatz, $beleg]] as [$x, $y]) {
            $v = Zusammen::vorschlag($x, $y);
            $this->gleich(['B', 'U', true], [$v['ziel'], $v['quelle'], $v['eindeutig']], 'es bleibt der Eintrag mit dem Beleg – gleich, in welcher Reihenfolge verglichen wird');
            $this->gleich(['2026-02-27', 'RE-0815', 'Musterlieferant GmbH', '91.96', 'Rechnung Musterlieferant'], [$v['felder']['datum'], $v['felder']['belegnummer'], $v['felder']['gegenpartei'], $v['felder']['netto'], $v['felder']['betreff']],
                'Belegangaben vom Beleg');
            $this->gleich(['2026-03-03', 'unbar', 'U', 'U'], [$v['felder']['zahltag'], $v['felder']['zahlweise'], $v['wahl']['zahltag'], $v['wahl']['gegenpartei_kennung']], 'Geldfluss vom Umsatz; was dem Beleg fehlt, kommt vom Umsatz');
        }
        $v = Zusammen::vorschlag(['belege' => []] + $beleg, $umsatz);
        $this->gleich(['U', 'B', false, '2026-03-02'], [$v['ziel'], $v['quelle'], $v['eindeutig'], $v['felder']['datum']], 'ohne Beleg bleibt der ältere Eintrag und geht überall vor – nicht eindeutig, also nie von selbst');
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
        // --- Zusammenführen (X3, F105): Beleg von Hand mit Link + Abbuchung aus der Datei ---
        $v = "$p/arbeitsvorrat";
        $rechnung = $this->ok(201, $this->http('POST', "$v/eintraege", ['betreff' => 'Rechnung Musterhandel', 'gegenpartei' => 'Musterhandel GmbH', 'betrag' => '12.50', 'datum' => '2026-02-27',
            'belegnummer' => 'RE-77', 'belege' => [['link' => 'https://paperless.example.org/documents/77/']]]), 'Beleg von Hand')['id'];
        $this->gleich(2, $this->ok(201, $this->http('POST', "$p/import/einliefern$frage", $mehr), 'Auszug wieder eingelesen')['neu'], 'zurückgezogene Zeilen gelten wieder als neu');
        $l = $this->ok(200, $this->http('GET', "$v/eintraege?abgleich=nein"), 'Liste mit Gruppe');
        $abbuchung = array_values(array_filter($l['eintraege'], fn ($x) => $x['betreff'] === 'Papier'))[0];
        $gruppe = array_column(array_filter($l['eintraege'], fn ($x) => $x['aehnlich'] !== null), 'aehnlich', 'id');
        $this->ist(isset($gruppe[$rechnung], $gruppe[$abbuchung['id']]) && $gruppe[$rechnung] === $gruppe[$abbuchung['id']] && $l['auto_zusammenfuehren'] === false,
            'Rechnung und Abbuchung sind als Gruppe markiert; Zusammenführen von selbst ist aus');
        $vgl = $this->ok(200, $this->http('GET', "$v/eintraege/{$abbuchung['id']}/vergleich/$rechnung"), 'Vergleich');
        $this->gleich([$rechnung, $abbuchung['id'], true, $rechnung, $abbuchung['id'], 'import'],
            [$vgl['ziel'], $vgl['quelle'], $vgl['eindeutig'], $vgl['wahl']['datum'], $vgl['wahl']['zahltag'], $vgl['links']['beitraege'][0]['quelle']], 'Vergleich: beide Seiten mit Beiträgen und der Vorschlag');
        // von selbst: nur wenn eingeschaltet
        $this->gleich('arbeitsvorrat.auto_aus', $this->ok(409, $this->http('POST', "$v/eintraege/zusammenfuehren/automatisch", []), 'von selbst, ausgeschaltet')['code'], 'Vorgabe: aus');
        // kein Duplikat – und die Markierung ist weg
        $this->ok(200, $this->http('POST', "$v/eintraege/kein-duplikat", ['eintraege' => [$rechnung, $abbuchung['id']]]), 'kein Duplikat');
        $l = $this->ok(200, $this->http('GET', "$v/eintraege?abgleich=nein"), 'Liste')['eintraege'];
        $this->gleich([null, null], array_column(array_filter($l, fn ($x) => in_array($x['id'], [$rechnung, $abbuchung['id']], true)), 'aehnlich'), 'nach „kein Duplikat“ nicht mehr markiert');
        // trotzdem von Hand zusammenführen, mit eigener Wahl für die Gegenpartei
        $felder = ['gegenpartei' => 'Musterhandel'] + $vgl['felder'];
        $z = $this->ok(200, $this->http('POST', "$v/eintraege/zusammenfuehren", ['ziel' => $rechnung, 'quelle' => $abbuchung['id'], 'felder' => $felder]), 'zusammengeführt');
        $this->gleich(['Musterhandel', '2026-02-27', '2026-03-05', 'unbar', 'RE-77', '1200', 1, ['arbeitsvorrat', 'import']],
            [$z['gegenpartei'], $z['datum'], $z['zahltag'], $z['zahlweise'], $z['belegnummer'], $z['vor_gegenkonto'], $z['aufgegangen'], $z['quellen']],
            'ein Eintrag: Belegdatum vom Beleg, Geldfluss und Geldkonto vom Umsatz, gewählte Gegenpartei, Beiträge beider Quellen');
        $this->ist(str_contains(json_encode($z['hinweise']), 'abweichend'), 'was abweicht, steht als Hinweis da');
        $l = $this->ok(200, $this->http('GET', "$v/eintraege?abgleich=nein"), 'Liste')['eintraege'];
        $this->ist(!in_array($abbuchung['id'], array_column($l, 'id'), true), 'der aufgegangene Eintrag steht nicht mehr in der Liste');
        $this->ok(409, $this->http('POST', "$v/eintraege/zusammenfuehren", ['ziel' => $rechnung, 'quelle' => $abbuchung['id']]), 'nur offene Einträge');
        // derselbe Auszug noch einmal: nichts kommt doppelt, die Zeile gehört jetzt zum zusammengeführten Eintrag
        $this->gleich(0, $this->ok(200, $this->http('POST', "$p/import/vorschau$frage", $mehr), 'Vorschau nach dem Zusammenführen')['neu'], 'die zusammengeführte Zeile gilt weiter als vorhanden');
        // trennen stellt beide wieder her
        $this->gleich(1, $this->ok(200, $this->http('POST', "$v/eintraege/$rechnung/trennen", []), 'getrennt')['getrennt'], 'wieder getrennt');
        $l = array_column($this->ok(200, $this->http('GET', "$v/eintraege?abgleich=nein"), 'Liste')['eintraege'], null, 'id');
        $this->gleich(['Musterhandel GmbH', null, 'Papier', '2026-03-05'], [$l[$rechnung]['gegenpartei'], $l[$rechnung]['zahltag'], $l[$abbuchung['id']]['betreff'], $l[$abbuchung['id']]['zahltag']], 'beide Einträge wie vor dem Zusammenführen');
        $this->ok(409, $this->http('POST', "$v/eintraege/$rechnung/trennen", []), 'nichts mehr zu trennen');
        // von selbst – eingeschaltet: nur eindeutige Zweiergruppen; „kein Duplikat“ wird geachtet
        $this->ok(200, $this->http('PUT', "$v/einstellungen", ['auto_zusammenfuehren' => true]), 'eingeschaltet');
        $this->gleich(0, $this->ok(200, $this->http('POST', "$v/eintraege/zusammenfuehren/automatisch", []), 'von selbst')['zusammengefuehrt'], '„kein Duplikat“ gilt auch für das Zusammenführen von selbst');
        $r2 = $this->ok(201, $this->http('POST', "$v/eintraege", ['betreff' => 'Rechnung Musterlieferant', 'gegenpartei' => 'Musterlieferant GmbH', 'betrag' => '109.43',
            'belege' => [['link' => 'https://paperless.example.org/documents/78/']]]), 'zweiter Beleg')['id'];
        $verworfen = $this->ok(200, $this->http('GET', "$v/eintraege?zustand=verworfen&abgleich=nein"), 'verworfene')['eintraege'][0]['id'];
        $this->ok(200, $this->http('POST', "$v/eintraege/$verworfen/oeffnen", []), 'verworfene Abbuchung wieder offen');
        $auto = $this->ok(200, $this->http('POST', "$v/eintraege/zusammenfuehren/automatisch", []), 'von selbst');
        $this->gleich([1, []], [$auto['zusammengefuehrt'], $auto['uebersprungen']], 'eine eindeutige Gruppe von selbst zusammengeführt');
        $z = $this->ok(200, $this->http('GET', "$v/eintraege/$r2"), 'zusammengeführter Eintrag');
        $this->gleich(['2026-03-03', '1200', 1], [$z['zahltag'], $z['vor_gegenkonto'], $z['aufgegangen']], 'Geldfluss und Geldkonto vom Umsatz');
        $this->ok(200, $this->http('PUT', "$v/einstellungen", ['auto_zusammenfuehren' => false]), 'wieder aus');
    }
}
