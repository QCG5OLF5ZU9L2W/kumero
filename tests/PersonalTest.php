<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

use Kumero\Api\V1\Dienste;
use Kumero\Plattform\Anfrage;
use Kumero\Plattform\Datenbank;
use Kumero\Plattform\Identitaet;
use Kumero\Plattform\Kernel;
use Kumero\Plattform\Konfiguration;
use Kumero\Tests\Hilfe\Umgebung;
use Kumero\Tests\Hilfe\Zugaenge;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Modul personal, Stufe 1 (PERSONAL.md, F136): Personen, private Angaben, Verträge in Fassungen, Fristen und
 * Nachweise, Selfservice mit Änderungsvorschlag, Löschfrist je Objekt – und vor allem: wer was sieht. Vier Rollen am
 * Mandanten (Mitarbeiter, Vorgesetzter, Personal, Lohn); der Administrator sieht nichts. Das Protokoll nennt keine
 * Werte. Nur erfundene Personen.
 */
final class PersonalTest extends Rahmen
{
    use Zugaenge;

    private const ORIGIN = Umgebung::ORIGIN;
    private const MANDANT = 'kumero_personalpraxis';
    private const M = '/m/personalpraxis/api/v1';
    private const P = '/m/personalpraxis/api/v1/personal';

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

    private function problem(int $status, string $code, array $erg, string $was): void
    {
        $this->gleich([$status, $code], [$erg[0]->getStatusCode(), $erg[1]['code'] ?? null], $was . ' – ' . substr((string) $erg[0]->getContent(), 0, 300));
    }

    /** @return list<string> */
    private function sql(string $abfrage, string $datenbank = self::MANDANT): array
    {
        $aus = Umgebung::sh(sprintf('psql -X -q -At -v ON_ERROR_STOP=1 -h %s -U %s -d %s -c %s', escapeshellarg((string) getenv('KUMERO_TEST_PGHOST')),
            escapeshellarg((string) getenv('KUMERO_TEST_PGUSER')), $datenbank, escapeshellarg($abfrage)));
        return $aus === '' ? [] : explode("\n", $aus);
    }

    private static function tag(int $plus): string
    {
        return date('Y-m-d', strtotime(($plus >= 0 ? '+' : '') . $plus . ' days'));
    }

    public function testPersonal(): void
    {
        $m = self::M;
        $p = self::P;
        $adamPk = $this->passkeyEinrichten($this->mandantAnlegen('personalpraxis', 'Personalpraxis'));
        [$paula, $pkPaula] = $this->nutzer('personalpraxis', 'Paula', 'Personalerin', []);
        [$viktor, $pkViktor] = $this->nutzer('personalpraxis', 'Viktor', 'Vorsteher', []);
        [$mia, $pkMia] = $this->nutzer('personalpraxis', 'Mia', 'Mitarbeit', []);
        [$lars, $pkLars] = $this->nutzer('personalpraxis', 'Lars', 'Lohnmann', []);

        $aus = $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'personal', 'mandant' => 'personalpraxis']);
        foreach (['Rolle Mitarbeiter angelegt', 'Rolle Vorgesetzter angelegt', 'Rolle Personal angelegt', 'Rolle Lohn angelegt', 'Modul personal 0.1.0 ist aktiv.'] as $t) {
            $this->ist(str_contains($aus, $t), "Aktivieren nennt „{$t}“ – $aus");
        }
        $this->gleich(['f'], $this->sql("SELECT has_schema_privilege('kumero_lesen', 'personal', 'USAGE')"), 'das Lesekonto der Datenbank sieht das Schema nicht');

        // Der Administrator weist die Rollen zu und sieht selbst nichts
        $this->anmelden($adamPk);
        foreach ([[$paula, 'Personal'], [$viktor, 'Vorgesetzter'], [$viktor, 'Mitarbeiter'], [$mia, 'Mitarbeiter'], [$lars, 'Lohn']] as [$wer, $rolle]) {
            $this->status(201, $this->http('POST', "$m/zuweisungen", ['nutzer' => $wer, 'rolle' => $rolle]), "Rolle $rolle zugewiesen");
        }
        $this->problem(403, 'recht.fehlt', $this->http('GET', $p), 'Administrator: keine Personenliste');
        $this->problem(403, 'recht.fehlt', $this->http('GET', "$p/meine"), 'Administrator: auch nicht „Meine Daten“');

        // Personal legt an
        $this->anmelden($pkPaula);
        $s = $this->status(200, $this->http('GET', $p), 'Stand');
        $this->gleich([true, true, true, false, []], [$s['darf']['pflegen'], $s['darf']['privat'], $s['darf']['vertrag_pflegen'], $s['darf']['eigenes'], $s['personen']], 'Personal darf pflegen; noch niemand');
        $this->gleich(['Einweisung', 'Fortbildung', 'Nachweis', 'Unterweisung'], array_column($s['fristarten'], 'name'), 'Arten von Fristen sind vorbelegt');
        $privat = ['geburtsdatum' => '1990-04-12', 'strasse' => 'Lindenweg 7', 'plz' => '99999', 'ort' => 'Musterstadt', 'telefon' => '0000 111111', 'email' => 'Mia@Privat.example.org',
                   'notfall_name' => 'Nora Notfall', 'notfall_telefon' => '0000 222222'];
        $pv = $this->status(201, $this->http('POST', "$p/personen", ['personalnummer' => '100', 'familienname' => 'Vorsteher', 'vornamen' => 'Viktor', 'nutzer' => $viktor]), 'Vorgesetzter angelegt')['id'];
        $pm = $this->status(201, $this->http('POST', "$p/personen", ['personalnummer' => '101', 'familienname' => 'Mitarbeit', 'vornamen' => 'Mia', 'vorgesetzter' => $pv, 'nutzer' => $mia, 'privat' => $privat]), 'Mitarbeiterin angelegt')['id'];
        $po = $this->status(201, $this->http('POST', "$p/personen", ['personalnummer' => '102', 'familienname' => 'Ohnezugang', 'vornamen' => 'Otto']), 'Person ohne Nutzer (PE3)')['id'];
        $this->problem(409, 'KM003', $this->http('POST', "$p/personen", ['personalnummer' => '101', 'familienname' => 'Doppelt']), 'Personalnummer nur einmal');
        $this->problem(409, 'KM003', $this->http('POST', "$p/personen", ['personalnummer' => '103', 'familienname' => 'Zweitzugang', 'nutzer' => $mia]), 'ein Nutzer gehört zu einer Person');
        $this->status(400, $this->http('POST', "$p/personen", ['personalnummer' => 'ä 1', 'familienname' => 'Falsch']), 'Form der Personalnummer');
        $this->status(400, $this->http('POST', "$p/personen", ['personalnummer' => '104', 'familienname' => 'Fremd', 'nutzer' => '00000000-0000-4000-8000-000000000000']), 'unbekannter Nutzer');
        $this->status(400, $this->http('POST', "$p/personen", ['personalnummer' => '104', 'familienname' => 'Fremd', 'gehalt' => '5000']), 'unbekanntes Feld – Vergütung führt diese Stufe nicht');
        $this->status(400, $this->http('POST', "$p/personen", ['personalnummer' => '104', 'familienname' => 'Fremd', 'privat' => ['email' => 'keine-adresse']]), 'E-Mail hat eine Form');
        $this->problem(422, 'KM002', $this->http('PUT', "$p/personen/$pv", ['personalnummer' => '100', 'familienname' => 'Vorsteher', 'vornamen' => 'Viktor', 'nutzer' => $viktor, 'vorgesetzter' => $pm]), 'kein Kreis unter den Vorgesetzten');
        $a = $this->status(200, $this->http('GET', "$p/personen/$pm"), 'Akte');
        $this->gleich(['Mitarbeit, Mia', 'Vorsteher, Viktor', 'aktiv', true, $mia], [$a['person']['name'], $a['person']['vorgesetzter_name'], $a['person']['zustand'], $a['person']['mit_nutzer'], $a['person']['nutzer']], 'Stamm');
        $this->gleich(['mia@privat.example.org', 'Lindenweg 7', '1990-04-12'], [$a['privat']['email'], $a['privat']['strasse'], $a['privat']['geburtsdatum']], 'private Angaben');
        $this->gleich([[], []], [$a['vertraege'], $a['vorschlaege']], 'noch kein Vertrag, kein Vorschlag');

        // Vertrag in Fassungen
        $vertrag = ['gueltig_ab' => '2026-01-01', 'art' => 'befristet', 'funktion' => 'Medizinische Fachangestellte', 'beginn' => '2026-01-01', 'probezeit_bis' => self::tag(10),
                    'befristet_bis' => self::tag(30), 'verteilung' => [480, 480, 480, 480, 240, 0, 0], 'urlaubstage' => '28', 'lohnbuch' => 'haupt', 'kuendigungsfrist' => 'vier Wochen zum Monatsende'];
        $this->status(400, $this->http('POST', "$p/personen/$pm/vertraege", ['befristet_bis' => null] + $vertrag), 'befristet braucht ein Ende');
        $this->status(400, $this->http('POST', "$p/personen/$pm/vertraege", ['verteilung' => [480, 480]] + $vertrag), 'sieben Wochentage');
        $this->status(400, $this->http('POST', "$p/personen/$pm/vertraege", ['lohnbuch' => 'gibtsnicht'] + $vertrag), 'das Buch gibt es');
        $this->status(400, $this->http('POST', "$p/personen/$pm/vertraege", ['verguetung' => '3000'] + $vertrag), 'keine Vergütung in dieser Stufe');
        $v1 = $this->status(201, $this->http('POST', "$p/personen/$pm/vertraege", $vertrag), 'erste Fassung')['id'];
        $v2 = $this->status(201, $this->http('POST', "$p/personen/$pm/vertraege", ['gueltig_ab' => self::tag(60), 'art' => 'unbefristet', 'befristet_bis' => null, 'probezeit_bis' => null, 'verteilung' => [480, 480, 480, 480, 480, 0, 0]] + $vertrag), 'künftige Fassung')['id'];
        $a = $this->status(200, $this->http('GET', "$p/personen/$pm"), 'Akte');
        $this->gleich([[$v2, false, 2400], [$v1, true, 2160]], array_map(fn ($v) => [$v['id'], $v['gilt'], $v['wochenminuten']], $a['vertraege']), 'beide Fassungen; es gilt die, deren Tag erreicht ist');
        $this->gleich(['28.0', 'haupt', 'Personalpraxis'], [$a['vertraege'][1]['urlaubstage'], $a['vertraege'][1]['lohnbuch'], $a['vertraege'][1]['lohnbuch_name']], 'Urlaub und Buch');
        $s = $this->status(200, $this->http('GET', $p), 'Stand');
        $zeile = array_column($s['personen'], null, 'id')[$pm];
        $this->gleich(['Medizinische Fachangestellte', true, 0], [$zeile['funktion'], $zeile['frist_fehlt'], $zeile['vorschlaege']], 'Funktion aus der geltenden Fassung; Löschfrist fehlt');

        // Fristen und Nachweise
        $arten = array_column($s['fristarten'], 'id', 'name');
        $f1 = $this->status(201, $this->http('POST', "$p/fristen", ['person' => $pm, 'art' => $arten['Unterweisung'], 'faellig' => self::tag(5), 'bemerkung' => 'Hygiene']), 'Frist angelegt')['id'];
        $f2 = $this->status(201, $this->http('POST', "$p/fristen", ['person' => $po, 'art' => $arten['Nachweis'], 'faellig' => self::tag(-3)]), 'überfällige Frist')['id'];
        $this->status(201, $this->http('POST', "$p/fristen", ['person' => $pv, 'art' => $arten['Fortbildung'], 'faellig' => self::tag(200)]), 'ferne Frist');
        $this->status(400, $this->http('POST', "$p/fristen", ['person' => $pm, 'art' => $arten['Nachweis']]), 'fällig am fehlt');
        $l = $this->status(200, $this->http('GET', "$p/fristen"), 'Fristen');
        $this->gleich([['Nachweis', 'ueberfaellig', 'frist'], ['Unterweisung', 'faellig', 'frist'], ['Ende der Probezeit', 'faellig', 'vertrag'], ['Ende der Befristung', 'faellig', 'vertrag'], ['Fortbildung', 'offen', 'frist']],
            array_map(fn ($f) => [$f['art_name'], $f['zustand'], $f['quelle']], $l['fristen']), 'nach Fälligkeit: eigene Einträge und die Termine aus dem geltenden Vertrag');
        $this->gleich(4, $this->status(200, $this->http('GET', "$p/zaehler"), 'Zähler')['zahl'], 'Zähler: überfällig und fällig');
        $neu = $this->status(201, $this->http('POST', "$p/fristarten", ['name' => 'Zeugnis', 'warn_tage' => 14]), 'neue Art')['id'];
        $this->problem(409, 'KM003', $this->http('POST', "$p/fristarten", ['name' => 'zeugnis']), 'Arten heißen verschieden');
        $this->status(204, $this->http('PUT', "$p/fristarten/$neu", ['name' => 'Zeugnis', 'warn_tage' => 14, 'stillgelegt' => true]), 'stillgelegt');
        $this->problem(422, 'KM002', $this->http('POST', "$p/fristen", ['person' => $pm, 'art' => $neu, 'faellig' => self::tag(9)]), 'eine stillgelegte Art nimmt keine neue Frist');
        $this->status(204, $this->http('PUT', "$p/einstellung", ['warn_tage' => 7]), 'Warnfrist für Termine aus dem Vertrag');
        $this->gleich(2, $this->status(200, $this->http('GET', "$p/zaehler"), 'Zähler')['zahl'], 'mit kürzerer Warnfrist sind die Vertragstermine noch nicht fällig');
        $this->status(204, $this->http('POST', "$p/fristen/$f2/erledigt", []), 'erledigt');
        $this->problem(409, 'KM003', $this->http('POST', "$p/fristen/$f2/erledigt", []), 'nicht zweimal');

        // Löschfrist je Objekt (PE12): mit Begründung; kumero gibt keine vor
        $this->status(400, $this->http('PUT', "$p/loeschfrist", ['objektart' => 'vertrag', 'id' => $v1, 'loeschen_ab' => '2036-12-31']), 'ohne Begründung nicht');
        $this->status(400, $this->http('PUT', "$p/loeschfrist", ['objektart' => 'gehalt', 'id' => $v1, 'loeschen_ab' => '2036-12-31', 'grund' => 'Probe']), 'unbekannte Art');
        $this->status(204, $this->http('PUT', "$p/loeschfrist", ['objektart' => 'vertrag', 'id' => $v1, 'loeschen_ab' => '2036-12-31', 'grund' => 'Vorgabe des Verantwortlichen']), 'Löschfrist an einer Fassung');
        $this->status(204, $this->http('PUT', "$p/loeschfrist", ['objektart' => 'privat', 'id' => $pm, 'loeschen_ab' => '2030-01-01', 'grund' => 'Vorgabe des Verantwortlichen']), 'Löschfrist an den privaten Angaben');
        $a = $this->status(200, $this->http('GET', "$p/personen/$pm"), 'Akte');
        $this->gleich(['2036-12-31', null, '2030-01-01', null], [$a['vertraege'][1]['loeschen_ab'], $a['vertraege'][0]['loeschen_ab'], $a['privat']['loeschen_ab'], $a['person']['loeschen_ab']], 'jedes Objekt trägt seine eigene Frist');

        // Lohn: alle Personen und ihre Verträge, keine privaten Angaben
        $this->anmelden($pkLars);
        $s = $this->status(200, $this->http('GET', $p), 'Stand Lohn');
        $this->gleich([3, false, false, null, null], [count($s['personen']), $s['darf']['pflegen'], $s['darf']['privat'], $s['personen'][0]['nutzer'], $s['personen'][0]['frist_fehlt']], 'Lohn sieht alle, ohne Nutzer-ID und ohne Pflegeangaben');
        $a = $this->status(200, $this->http('GET', "$p/personen/$pm"), 'Akte für Lohn');
        $this->gleich([null, 2, null, null], [$a['privat'], count($a['vertraege']), $a['vorschlaege'], $a['person']['nutzer']], 'Verträge ja, private Angaben und Vorschläge nein');
        $this->ist(!array_key_exists('loeschen_ab', $a['vertraege'][0]) && !str_contains(json_encode($a), 'Lindenweg'), 'keine Löschfristen, nichts Privates in der Antwort');
        $this->problem(403, 'recht.fehlt', $this->http('POST', "$p/personen", ['personalnummer' => '105', 'familienname' => 'Fremd']), 'Lohn legt niemanden an');
        $this->problem(403, 'recht.fehlt', $this->http('POST', "$p/personen/$pm/vertraege", $vertrag), 'und keinen Vertrag');
        $this->problem(403, 'recht.fehlt', $this->http('GET', "$p/auswahl"), 'die Liste der Nutzer bekommt nur, wer Personen pflegt');
        $this->problem(403, 'recht.fehlt', $this->http('GET', "$p/meine"), 'ohne die Rolle Mitarbeiter kein Selfservice');

        // Vorgesetzter: nur sein Team und er selbst; keine privaten Angaben, keine Verträge
        $this->anmelden($pkViktor);
        $s = $this->status(200, $this->http('GET', $p), 'Stand Vorgesetzter');
        $this->gleich([['Mitarbeit, Mia', false], ['Vorsteher, Viktor', true]], array_map(fn ($x) => [$x['name'], $x['ich']], $s['personen']), 'das Team und er selbst – nicht die dritte Person');
        $this->gleich('Medizinische Fachangestellte', $s['personen'][0]['funktion'], 'die Funktion sieht er');
        $this->status(404, $this->http('GET', "$p/personen/$po"), 'eine fremde Person sieht aus wie eine unbekannte');
        $a = $this->status(200, $this->http('GET', "$p/personen/$pm"), 'Akte aus Sicht des Vorgesetzten');
        $this->gleich([null, null, null, ['Unterweisung']], [$a['privat'], $a['vertraege'], $a['vorschlaege'], array_column($a['fristen'], 'art_name')], 'nur Stamm und Fristen – keine Termine aus dem Vertrag');
        $l = $this->status(200, $this->http('GET', "$p/fristen"), 'Fristen des Teams');
        $this->gleich([['Mitarbeit, Mia', 'Unterweisung'], ['Vorsteher, Viktor', 'Fortbildung']], array_map(fn ($f) => [$f['name'], $f['art_name']], $l['fristen']), 'Fristen des Teams und die eigene');
        $this->problem(403, 'recht.fehlt', $this->http('POST', "$p/fristen", ['person' => $pm, 'art' => $arten['Nachweis'], 'faellig' => self::tag(9)]), 'Fristen pflegt das Personal');
        $ich = $this->status(200, $this->http('GET', "$p/meine"), 'Meine Daten des Vorgesetzten');
        $this->gleich(['Vorsteher, Viktor', []], [$ich['person']['name'], $ich['vertraege']], 'sich selbst sieht er ganz');

        // Mitarbeiterin: nur das Eigene, Änderungen als Vorschlag
        $this->anmelden($pkMia);
        $this->problem(403, 'recht.fehlt', $this->http('GET', $p), 'keine Personenliste');
        $this->problem(403, 'recht.fehlt', $this->http('GET', "$p/personen/$pm"), 'auch die eigene Akte nicht über die Liste');
        $ich = $this->status(200, $this->http('GET', "$p/meine"), 'Meine Daten');
        $this->gleich(['Mitarbeit, Mia', 'Lindenweg 7', 2, null], [$ich['person']['name'], $ich['privat']['strasse'], count($ich['vertraege']), $ich['person']['nutzer']], 'Stamm, private Angaben, Vertrag');
        $this->gleich(['Unterweisung', 'Ende der Probezeit', 'Ende der Befristung'], array_column($ich['fristen'], 'art_name'), 'eigene Fristen samt Terminen aus dem eigenen Vertrag');
        $this->ist(!str_contains(json_encode($ich), 'loeschen_ab'), 'Löschfristen sieht nur das Personal');
        $this->status(400, $this->http('POST', "$p/meine/vorschlaege", ['geburtsdatum' => '1991-01-01']), 'nur Kontaktdaten lassen sich vorschlagen');
        $this->status(400, $this->http('POST', "$p/meine/vorschlaege", []), 'ein Vorschlag nennt eine Änderung');
        $vo = $this->status(201, $this->http('POST', "$p/meine/vorschlaege", ['telefon' => '0000 333333', 'notfall_name' => null]), 'Vorschlag')['id'];
        $this->gleich('0000 111111', $this->status(200, $this->http('GET', "$p/meine"), 'Meine Daten')['privat']['telefon'], 'in der Akte ändert sich noch nichts');
        $this->problem(403, 'recht.fehlt', $this->http('POST', "$p/vorschlaege/$vo/entscheidung", ['uebernehmen' => true]), 'niemand übernimmt seinen eigenen Vorschlag');

        // Personal entscheidet
        $this->anmelden($pkPaula);
        $this->gleich(1, array_column($this->status(200, $this->http('GET', $p), 'Stand')['personen'], 'vorschlaege', 'id')[$pm], 'offener Vorschlag steht in der Liste');
        $this->status(204, $this->http('POST', "$p/vorschlaege/$vo/entscheidung", ['uebernehmen' => true]), 'übernommen');
        $this->problem(409, 'KM003', $this->http('POST', "$p/vorschlaege/$vo/entscheidung", ['uebernehmen' => true]), 'nur einmal entscheiden');
        $a = $this->status(200, $this->http('GET', "$p/personen/$pm"), 'Akte');
        $this->gleich(['0000 333333', null, 'Lindenweg 7', 'uebernommen'], [$a['privat']['telefon'], $a['privat']['notfall_name'], $a['privat']['strasse'], $a['vorschlaege'][0]['zustand']], 'nur das Vorgeschlagene geändert; ein leeres Feld leert');
        $this->anmelden($pkMia);
        $vo2 = $this->status(201, $this->http('POST', "$p/meine/vorschlaege", ['ort' => 'Anderstadt']), 'zweiter Vorschlag')['id'];
        $this->anmelden($pkPaula);
        $this->status(400, $this->http('POST', "$p/vorschlaege/$vo2/entscheidung", ['uebernehmen' => false]), 'eine Ablehnung braucht einen Grund');
        $this->status(204, $this->http('POST', "$p/vorschlaege/$vo2/entscheidung", ['uebernehmen' => false, 'grund' => 'Meldebescheinigung fehlt']), 'abgelehnt');
        $this->gleich('Musterstadt', $this->status(200, $this->http('GET', "$p/personen/$pm"), 'Akte')['privat']['ort'], 'abgelehnt: nichts geändert');

        // Austritt: ausgeschieden, bleibt in der Akte
        $this->status(204, $this->http('PUT', "$p/personen/$po", ['personalnummer' => '102', 'familienname' => 'Ohnezugang', 'vornamen' => 'Otto', 'austritt' => self::tag(-1)]), 'Austritt eingetragen');
        $zeile = array_column($this->status(200, $this->http('GET', $p), 'Stand')['personen'], null, 'id')[$po];
        $this->gleich(['ausgeschieden', self::tag(-1)], [$zeile['zustand'], $zeile['austritt']], 'ausgeschieden nach dem letzten Tag');

        // Protokoll: in der Kette des Mandanten, ohne einen einzigen Wert (PERSONAL.md Abschnitt 8)
        $typen = $this->sql("SELECT DISTINCT typ FROM mandant.protokoll WHERE typ LIKE 'personal.%' ORDER BY 1");
        foreach (['personal.person_angelegt', 'personal.person_geaendert', 'personal.privat_geaendert', 'personal.vertrag_angelegt', 'personal.frist_angelegt', 'personal.frist_erledigt',
                  'personal.vorschlag_angelegt', 'personal.vorschlag_uebernommen', 'personal.vorschlag_abgelehnt', 'personal.loeschfrist_gesetzt'] as $t) {
            $this->ist(in_array($t, $typen, true), "Protokoll kennt $t");
        }
        $alles = implode("\n", $this->sql("SELECT coalesce(vorher::text, '') || coalesce(nachher::text, '') || ids::text FROM mandant.protokoll WHERE typ LIKE 'personal.%'"));
        foreach (['Lindenweg', 'Mitarbeit', '0000 333333', 'privat.example', '1990-04-12', 'Fachangestellte', 'Nora', $mia] as $wert) {
            $this->ist(!str_contains($alles, $wert), "Protokoll enthält „{$wert}“ nicht");
        }
        $this->gleich(['{"felder": ["notfall_name", "telefon"]}'], $this->sql("SELECT nachher FROM mandant.protokoll WHERE typ = 'personal.vorschlag_angelegt' ORDER BY nr LIMIT 1"), 'genannt sind nur die Namen der Felder');
        $this->gleich([], $this->sql('SELECT nr, fehler FROM mandant.kette_pruefen()'), 'die Kette des Mandanten stimmt');
        $this->wirft(\RuntimeException::class, fn () => $this->sql("SET ROLE kumero_lesen; SELECT count(*) FROM personal.person"), 'das Lesekonto liest keine Personaldaten');
        $this->wirft(\RuntimeException::class, fn () => $this->sql("SET ROLE kumero_app; UPDATE personal.privat SET ort = 'x'"), 'Tabellen sind nur über Funktionen schreibbar');

        // Auskunft an Module eines Buchs: nur ID und Name, ohne Ausgeschiedene
        $k = Konfiguration::laden();
        $d = new Datenbank($k);
        $anfrage = new Anfrage(Request::create('/'), $k, $d, [], null, null, null, 'test', $d->verbindung(self::MANDANT));
        $this->gleich([['id' => $pm, 'name' => 'Mitarbeit, Mia'], ['id' => $pv, 'name' => 'Vorsteher, Viktor']], Dienste::auskunftAmMandanten($anfrage, 'personal', 'personen'), 'wer zur Auswahl steht');
        $this->gleich([['id' => $po, 'name' => 'Ohnezugang, Otto']], Dienste::auskunftAmMandanten($anfrage, 'personal', 'personen', [$po]), 'ein genannter Eintrag bleibt benennbar, auch nach dem Austritt');

        // Rolle ohne Person: Selfservice sagt, was fehlt
        $this->anmelden($adamPk);
        $this->status(201, $this->http('POST', "$m/zuweisungen", ['nutzer' => $lars, 'rolle' => 'Mitarbeiter']), 'Rolle Mitarbeiter ohne verknüpfte Person');
        $this->anmelden($pkLars);
        $ich = $this->status(200, $this->http('GET', "$p/meine"), 'Meine Daten ohne Person');
        $this->ist($ich['person'] === null && str_contains($ich['hinweis'], 'verknüpft'), 'Hinweis statt Daten');
        $this->problem(409, 'personal.nicht_verknuepft', $this->http('POST', "$p/meine/vorschlaege", ['ort' => 'Irgendwo']), 'ohne Person kein Vorschlag');
    }
}
