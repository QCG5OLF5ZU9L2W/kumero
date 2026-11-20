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
 * Modul auswertungen (AUSWERTUNGEN.md, F128): gespeicherte Auswertungen – eigene und für alle im Buch freigegebene.
 * Die Zahlen selbst prüft BuchenTest an der Basisabfrage `summen`; hier geht es um Speichern, Teilen und Rechte.
 */
final class AuswertungenTest extends Rahmen
{
    use Zugaenge;

    private const ORIGIN = Umgebung::ORIGIN;
    private const MANDANT = 'kumero_auswpraxis';
    private const P = '/m/auswpraxis/b/haupt/api/v1';

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

    public function testGespeicherteAuswertungen(): void
    {
        $v = self::P . '/auswertungen/vorlagen';
        $this->mandantAnlegen('auswpraxis', 'Auswertungspraxis');
        [, $pkBert] = $this->nutzer('auswpraxis', 'Bert', 'Buchhalter', ['Buchhalter']);
        [, $pkEmil] = $this->nutzer('auswpraxis', 'Emil', 'Erfasser', ['Erfasser']);
        [, $pkPaul] = $this->nutzer('auswpraxis', 'Paul', 'Prüfer', ['Prüfer']);
        $this->anmelden($pkBert);
        $this->gleich('modul.nicht_aktiv', $this->status(404, $this->http('GET', $v), 'ohne Modul')['code'], 'erst aktivieren');
        $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'auswertungen', 'mandant' => 'auswpraxis']);

        $l = $this->status(200, $this->http('GET', $v), 'leere Liste');
        $this->gleich([[], true, true], [$l['vorlagen'], $l['darf_speichern'], $l['darf_teilen']], 'Buchhalter darf speichern und teilen');
        $raum = ['art' => 'konten', 'zeitraum' => 'quartal', 'gliederung' => 'eigene', 'spalten' => ['saldo', 'soll', 'haben'], 'einzeln' => true,
                 'zeilen' => [['titel' => 'Raumkosten', 'konten' => '4200-4299'], ['titel' => 'Fahrzeug', 'konten' => '4500-4599, 4900'], ['titel' => 'Kosten gesamt', 'summe' => true]]];
        $a = $this->status(201, $this->http('POST', $v, ['name' => ' Kostenübersicht ', 'definition' => $raum]), 'eigene Auswertung');
        $this->gleich(['Kostenübersicht', false, true, ['soll', 'haben', 'saldo'], 'eigene', false, true, 3],
            [$a['name'], $a['geteilt'], $a['eigene'], $a['definition']['spalten'], $a['definition']['gliederung'], $a['definition']['entwuerfe'], $a['definition']['ohne_null'], count($a['definition']['zeilen'])],
            'gespeichert: Name ohne Rand, Spalten in fester Reihenfolge, Vorgaben ergänzt');
        $this->gleich(['Kosten gesamt', true, false], [$a['definition']['zeilen'][2]['titel'], $a['definition']['zeilen'][2]['summe'], isset($a['definition']['zeilen'][2]['konten'])], 'Summenzeile');
        $this->gleich([], $a['definition']['kontoarten'], 'ohne Einschränkung der Kontoarten');
        $this->status(409, $this->http('POST', $v, ['name' => 'Kostenübersicht', 'definition' => $raum]), 'derselbe Name ein zweites Mal');

        // Form der Definition: streng, unbekannte Felder werden abgewiesen
        foreach (['Art' => ['art' => 'sql'], 'unbekanntes Feld' => ['abfrage' => 'SELECT 1'], 'Spalten' => ['spalten' => ['gewinn']], 'Kontoart' => ['kontoarten' => ['eigenkapital']], 'leere Spalten' => ['spalten' => []],
                  'Konten' => ['konten' => "1000'; DROP"], 'Zeile ohne Konten' => ['gliederung' => 'eigene', 'zeilen' => [['titel' => 'x', 'konten' => '']]],
                  'eigene Gliederung ohne Zeilen' => ['gliederung' => 'eigene', 'zeilen' => []], 'Datum' => ['zeitraum' => 'frei', 'von' => '2026-02-30']] as $was => $d) {
            $this->status(400, $this->http('POST', $v, ['name' => "falsch $was", 'definition' => $d]), "abgewiesen: $was");
        }
        $this->status(400, $this->http('POST', $v, ['name' => '', 'definition' => ['art' => 'journal']]), 'ohne Namen');

        // ändern, teilen
        $a = $this->status(200, $this->http('PUT', "$v/{$a['id']}", ['name' => 'Kostenübersicht', 'geteilt' => true, 'definition' => ['art' => 'journal', 'zeitraum' => 'frei', 'von' => '2026-01-01', 'bis' => '2026-03-31', 'konten' => '42*']]), 'geändert und geteilt');
        $this->gleich([true, 'journal', '2026-03-31', '42*'], [$a['geteilt'], $a['definition']['art'], $a['definition']['bis'], $a['definition']['konten']], 'für alle im Buch');

        // Emil sieht die geteilte, kann sie aber weder ändern noch löschen noch selbst teilen
        $this->anmelden($pkEmil);
        $l = $this->status(200, $this->http('GET', $v), 'Liste des Erfassers');
        $this->gleich([['Kostenübersicht'], [false], true, false], [array_column($l['vorlagen'], 'name'), array_column($l['vorlagen'], 'eigene'), $l['darf_speichern'], $l['darf_teilen']], 'geteilte Auswertung sichtbar, nicht die eigene');
        $this->status(404, $this->http('PUT', "$v/{$a['id']}", ['name' => 'gekapert', 'definition' => ['art' => 'journal']]), 'fremde Auswertung ändern');
        $this->status(404, $this->http('DELETE', "$v/{$a['id']}"), 'fremde Auswertung löschen');
        $this->gleich('recht.fehlt', $this->status(403, $this->http('POST', $v, ['name' => 'Emils', 'geteilt' => true, 'definition' => ['art' => 'konten']]), 'teilen ohne Recht')['code'], 'Erfasser teilt nicht');
        $e = $this->status(201, $this->http('POST', $v, ['name' => 'Kostenübersicht', 'definition' => ['art' => 'konten']]), 'eigene mit demselben Namen wie die fremde');
        $this->gleich(2, count($this->status(200, $this->http('GET', $v), 'Liste')['vorlagen']), 'eigene und geteilte');

        // Prüfer: sieht geteilte, speichert nichts
        $this->anmelden($pkPaul);
        $l = $this->status(200, $this->http('GET', $v), 'Liste des Prüfers');
        $this->gleich([1, false, false], [count($l['vorlagen']), $l['darf_speichern'], $l['darf_teilen']], 'nur die geteilte; Emils eigene nicht');
        $this->status(403, $this->http('POST', $v, ['name' => 'Pauls', 'definition' => ['art' => 'konten']]), 'Prüfer speichert nicht');

        // Bert nimmt die Freigabe zurück und löscht
        $this->anmelden($pkBert);
        $this->status(200, $this->http('PUT', "$v/{$a['id']}", ['name' => 'Kostenübersicht', 'geteilt' => false, 'definition' => ['art' => 'konten']]), 'nicht mehr geteilt');
        $this->status(204, $this->http('DELETE', "$v/{$a['id']}"), 'gelöscht');
        $this->status(404, $this->http('DELETE', "$v/{$a['id']}"), 'ein zweites Mal');
        $this->status(404, $this->http('DELETE', "$v/{$e['id']}"), 'Emils eigene löscht Bert nicht');
        $this->gleich([], $this->status(200, $this->http('GET', $v), 'Liste am Ende')['vorlagen'], 'Bert sieht nichts mehr');

        // Menü: Bereich Auswertungen im Buch
        $menue = $this->status(200, $this->http('GET', self::P . '/oberflaeche/menue'), 'Menü');
        $this->ist(in_array('auswertungen', array_column($menue['bereiche'], 'id'), true) && isset($menue['seiten']['auswertungen/auswertung']), 'Bereich „Auswertungen“ mit der Seite');
    }
}
