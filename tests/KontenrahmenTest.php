<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

use Kumero\Modul\Kontenrahmen\CsvLeser;
use Kumero\Modul\Kontenrahmen\PdfLeser;
use Kumero\Modul\Kontenrahmen\Regelwerk;
use Kumero\Modul\Kontenrahmen\Vorlage;
use Kumero\Plattform\Datenbank;
use Kumero\Plattform\Jcs;
use Kumero\Plattform\Konfiguration;
use Kumero\Tests\Hilfe\Umgebung;

/**
 * Modul kontenrahmen: PDF im DATEV-Aufbau (erfundene Konten), Regelwerk, Ergänzung,
 * Vorlage, Aktivieren, Anwenden auf einen Mandanten. Kein DATEV-Inhalt im Test (F75 Nr. 2, 9).
 */
final class KontenrahmenTest extends Rahmen
{
    private const PDF = __DIR__ . '/fixtures/kontenrahmen/skr81-muster.pdf';
    private ?Umgebung $u = null;

    public function nachher(): void
    {
        $this->u?->abbauen();
    }

    private static function lesen(): array
    {
        return PdfLeser::lesen(PdfLeser::textAus(self::PDF)['text']);
    }

    public function testPdfLesen(): void
    {
        $g = self::lesen();
        $this->gleich('2015', $g['fassung'], 'Fassung aus der Kopfzeile');
        $konten = array_column($g['konten'], null, 'nr');
        $this->gleich(['0010', '0200', '0420', '0800', '0950', '1000', '1200', '1576', '1600', '1776', '1900', '4120', '4210', '4930', '4980', '5100', '8100', '8590', '9000'],
            (function () use ($konten) { $n = array_keys($konten); sort($n, SORT_STRING); return array_map('strval', $n); })(), 'alle Konten beider Spalten');
        $this->gleich('Büroeinrichtung Muster mit einer langen Beschriftung', $konten['0420']['bezeichnung'], 'umbrochene Beschriftung links');
        $this->gleich('Sonstige betriebliche Aufwendungen Muster', $konten['4980']['bezeichnung'], 'umbrochene Beschriftung rechts');
        $this->gleich('AV', $konten['4930']['kennzeichen'], 'Funktionskennzeichen');
        $this->gleich(['Umsatzsteuer Muster', '18'], [$konten['1776']['bezeichnung'], $konten['1776']['fussnote']], 'Fußnote abgetrennt');
        $this->gleich('Kapital', $konten['0800']['gruppe'], 'Untergruppe');
        $this->gleich([['0810', '0869'], ['1401', '1406']], array_map(fn ($b) => [$b['von'], $b['bis']], $g['bereiche']), 'Bereiche mit Endung in der Folgezeile');
        $this->gleich([['kennzeichen' => 'KU', 'von' => '1000', 'bis' => '1371']], $g['bereichsfunktionen'], 'Zusatzfunktion eines Bereichs');
        $this->ist(!isset($konten['10000']), 'Personenkonten übergangen');
        $this->gleich([], $g['hinweise'], 'keine unsicheren Zeilen');
    }

    public function testRegelwerkSkr03(): void
    {
        $r = Regelwerk::laden('skr03');
        $art = fn (string $nr) => $r->einordnen($nr)['kontoart'] ?? null;
        $this->gleich(['aktiv', 'passiv', 'passiv', 'aktiv', 'aktiv', 'passiv', 'passiv', 'aufwand', 'ertrag', 'aufwand', 'aufwand', 'aktiv', 'ertrag'],
            array_map($art, ['0420', '0650', '0880', '1000', '1576', '1600', '1776', '2100', '2650', '3400', '4930', '7000', '8400']), 'Kontoarten nach Bereichen');
        $this->ist($r->einordnen('1590')['pruefen'], 'durchlaufende Posten zu bestätigen');
        $this->ist($r->einordnen('9000')['auslassen'], 'Vortragskonten ausgelassen');
        $this->gleich(null, $art('5000'), 'Klasse 5 offen');
        $this->ist($r->istPersonenkonto('10000') && $r->istPersonenkonto('70001'), 'Personenkonten');
        $this->gleich(['skr03', 'skr81'], array_column(Regelwerk::alle(), 'name'), 'Regelwerke');
    }

    public function testVorlageMitRegelwerkUndErgaenzung(): void
    {
        $g = self::lesen();
        $regeln = Regelwerk::laden('skr81');
        $q = ['art' => 'datev-pdf', 'datei_sha256' => str_repeat('a', 64), 'eingelesen_am' => '2026-11-17T00:00:00Z'];
        $v = Vorlage::bauen('Muster', 'SKR81', $g['fassung'], $q, $regeln, $g['konten'], $g['bereiche'], $g['bereichsfunktionen'], $g['hinweise']);
        $k = array_column($v->daten['konten'], null, 'nr');
        $this->gleich(['aktiv', 'passiv', 'passiv', 'passiv', 'aufwand', 'ertrag'],
            [$k['1000']['kontoart'], $k['0950']['kontoart'], $k['1600']['kontoart'], $k['1900']['kontoart'], $k['4930']['kontoart'], $k['8100']['kontoart']],
            'Kontoarten aus dem Regelwerk');
        $this->gleich(['9000'], array_column($v->daten['ausgelassen'], 'nr'), 'Vortragskonten ausgelassen');
        $this->gleich(['5100'], array_column($v->offen(), 'nr'), 'Klasse 5 ohne Gliederung bleibt offen');

        $csv = tempnam(sys_get_temp_dir(), 'erg') . '.csv';
        file_put_contents($csv, mb_convert_encoding("Nummer;Bezeichnung;Kontoart\n5100;;aufwand\n4999;Neues Muster;aufwand\n0010;;-\n9000;Saldenvorträge Muster;passiv\n", 'Windows-1252', 'UTF-8'));
        $e = CsvLeser::lesen($csv);
        unlink($csv);
        $v2 = Vorlage::bauen('Muster', 'SKR81', $g['fassung'], $q, $regeln, $g['konten'], $g['bereiche'], [], [], $e);
        $k2 = array_column($v2->daten['konten'], null, 'nr');
        $this->gleich([], $v2->offen(), 'Ergänzung klärt alle offenen Punkte');
        $this->gleich(['aufwand', 'hand'], [$k2['5100']['kontoart'], $k2['5100']['kontoart_herkunft']], 'Kontoart von Hand');
        $this->gleich('Neues Muster', $k2['4999']['bezeichnung'], 'Konto ergänzt (Windows-1252 gelesen)');
        $this->ist(!isset($k2['0010']) && in_array('0010', array_column($v2->daten['ausgelassen'], 'nr'), true), 'Konto weggelassen');
        $this->ist(isset($k2['9000']) && !in_array('9000', array_column($v2->daten['ausgelassen'], 'nr'), true), 'ausgelassenes Konto per Ergänzung aufgenommen');
        $this->gleich($v2->sha256(), Jcs::sha256(json_decode($v2->json(), true)), 'Hash über die kanonische Form, unabhängig von der Darstellung');
        $this->ist($v->sha256() !== $v2->sha256(), 'andere Vorlage, anderer Hash');
    }

    public function testJcsWieDatenbank(): void
    {
        $this->gleich('{"a":{"y":null,"z":true},"b":[1,"ä","/"]}', Jcs::kodieren(['b' => [1, 'ä', '/'], 'a' => ['z' => true, 'y' => null]]), 'gleiche Form wie kern.jcs');
    }

    public function testAktivierenUndAnwenden(): void
    {
        $this->u = new Umgebung(['kumero_testkr']);
        $this->u->aufbauen();
        $u = $this->u;
        $u->cli(['command' => 'mandant:anlegen', 'kennung' => 'testkr', 'name' => 'Kontenrahmen (Test)']);

        // Einlesen mit offenen Punkten → Vorlage, aber nicht anwendbar
        $aus = $u->cli(['command' => 'kontenrahmen:einlesen', 'datei' => self::PDF]);
        $this->ist(str_contains($aus, 'offen: 5100'), 'Prüfbericht nennt offene Konten');
        preg_match('/Vorlage: (\S+)/', $aus, $m);
        $u->cli(['command' => 'kontenrahmen:anwenden', 'vorlage' => $m[1], 'mandant' => 'testkr'], true);

        // Mit Ergänzung einlesen
        $csv = tempnam(sys_get_temp_dir(), 'erg') . '.csv';
        file_put_contents($csv, "5100;;aufwand\n");
        $aus = $u->cli(['command' => 'kontenrahmen:einlesen', 'datei' => self::PDF, '--ergaenzung' => $csv, '--name' => 'Muster SKR 81']);
        unlink($csv);
        $this->ist(str_contains($aus, 'Bereit zum Anwenden'), 'Vorlage bereit');
        preg_match('/Vorlage: (\S+)/', $aus, $m);
        $kennung = $m[1];
        $this->ist(str_contains($u->cli(['command' => 'kontenrahmen:vorlagen']), $kennung), 'Vorlage in der Ablage');

        $aus = $u->cli(['command' => 'kontenrahmen:anwenden', 'vorlage' => $kennung, 'mandant' => 'testkr'], true);
        $this->ist(str_contains($aus, 'nicht aktiv'), 'ohne aktives Modul kein Anwenden');

        $aus = $u->cli(['command' => 'modul:aktivieren', 'modul' => 'kontenrahmen', 'mandant' => 'testkr']);
        $this->ist(str_contains($aus, 'kontenrahmen/0001_kontenrahmen.sql') && str_contains($aus, 'ist aktiv'), 'Migration des Moduls eingespielt');
        $u->cli(['command' => 'modul:aktivieren', 'modul' => 'kontenrahmen', 'mandant' => 'testkr']);   // zweites Mal: nichts doppelt

        $k = Konfiguration::laden();
        $db = (new Datenbank($k->mitDbNutzer('kumero_owner')))->verbindung('kumero_testkr');
        $db->executeStatement("SELECT kern.konto_anlegen('test:vorher', '4120', 'Vorher angelegt', 'ertrag')");

        $aus = $u->cli(['command' => 'kontenrahmen:anwenden', 'vorlage' => $kennung, 'mandant' => 'testkr']);
        $this->ist(str_contains($aus, 'Angelegt: 17') && str_contains($aus, 'Konflikte: 1') && str_contains($aus, '4120'), "Konten angelegt, Konflikt gemeldet\n$aus");
        $this->gleich(18, (int) $db->fetchOne('SELECT count(*) FROM kern.konto'), '17 neue + 1 vorhandenes Konto');
        $this->gleich('ertrag', $db->fetchOne("SELECT kontoart::text FROM kern.konto WHERE nr = '4120'"), 'vorhandenes Konto unverändert');
        $aus = $u->cli(['command' => 'kontenrahmen:anwenden', 'vorlage' => $kennung, 'mandant' => 'testkr']);
        $this->ist(str_contains($aus, 'Angelegt: 0') && str_contains($aus, 'schon vorhanden: 17'), 'erneutes Anwenden ändert nichts');

        $this->gleich(2, (int) $db->fetchOne('SELECT count(*) FROM kontenrahmen.angewendet'), 'Anwendungen vermerkt (F75 Nr. 6)');
        $this->gleich(2, (int) $db->fetchOne("SELECT count(*) FROM kern.protokoll WHERE typ = 'kontenrahmen.angewendet'"), 'Vermerk in der Protokollkette');
        $this->gleich('SKR81', $db->fetchOne('SELECT rahmen FROM kontenrahmen.angewendet LIMIT 1'), 'Rahmen vermerkt');
        $this->ist((bool) $db->fetchOne("SELECT EXISTS (SELECT 1 FROM kern.rechte_des_subjekts('nutzer:x') WHERE false)
            OR EXISTS (SELECT 1 FROM kern.rolle r JOIN kern.rolle_baustein rb ON rb.rolle = r.id JOIN kern.baustein b ON b.id = rb.baustein
                        WHERE r.name = 'Verantwortlicher' AND b.name = 'Kontenrahmen pflegen')"), 'Rolle Verantwortlicher erhält den Baustein des Moduls');
        $this->gleich(1, (int) $db->fetchOne("SELECT count(*) FROM kern.rolle r JOIN kern.rolle_baustein rb ON rb.rolle = r.id
            JOIN kern.baustein b ON b.id = rb.baustein WHERE r.name = 'Verantwortlicher' AND b.name = 'Kontenrahmen pflegen'"), 'Baustein nicht doppelt');
        $this->gleich(0, (int) $db->fetchOne('SELECT count(*) FROM kern.kette_pruefen()'), 'Ketten intakt');
        $app = (new Datenbank($k))->verbindung('kumero_testkr');
        $this->wirft(\Throwable::class, fn () => $app->executeStatement("DELETE FROM kontenrahmen.angewendet"), 'Anwendung darf Vermerke nicht löschen (F64)');
        $db->close();
        $app->close();
    }
}
