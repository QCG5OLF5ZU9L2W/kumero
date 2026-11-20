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
 * Modul personal, Stufe 1 und 2 (PERSONAL.md, F136, F138): Abwesenheiten mit Antrag und Genehmigung, AU ohne Diagnose, Urlaubskonto;
 * Stufe 1: Personen, private Angaben, Verträge in Fassungen, Fristen und
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

    /** Urlaubskonto eines Jahres aus „Meine Daten“ – dort stehen das laufende und das nächste Jahr. */
    private static function kontoVon(self $t, string $pfad, int $jahr): array
    {
        return array_column($t->status(200, $t->http('GET', $pfad), 'Meine Daten')['urlaubskonto'], null, 'jahr')[$jahr];
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
        foreach (['Rolle Mitarbeiter angelegt', 'Rolle Vorgesetzter angelegt', 'Rolle Personal angelegt', 'Rolle Lohn angelegt', 'Modul personal 0.3.0 ist aktiv.'] as $t) {
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
        $this->gleich(4, $this->status(200, $this->http('GET', "$p/zaehler"), 'Zähler')['anzahl'], 'Zähler: überfällig und fällig');
        $neu = $this->status(201, $this->http('POST', "$p/fristarten", ['name' => 'Zeugnis', 'warn_tage' => 14]), 'neue Art')['id'];
        $this->problem(409, 'KM003', $this->http('POST', "$p/fristarten", ['name' => 'zeugnis']), 'Arten heißen verschieden');
        $this->status(204, $this->http('PUT', "$p/fristarten/$neu", ['name' => 'Zeugnis', 'warn_tage' => 14, 'stillgelegt' => true]), 'stillgelegt');
        $this->problem(422, 'KM002', $this->http('POST', "$p/fristen", ['person' => $pm, 'art' => $neu, 'faellig' => self::tag(9)]), 'eine stillgelegte Art nimmt keine neue Frist');
        $this->status(204, $this->http('PUT', "$p/einstellung", ['warn_tage' => 7]), 'Warnfrist für Termine aus dem Vertrag');
        $this->gleich(2, $this->status(200, $this->http('GET', "$p/zaehler"), 'Zähler')['anzahl'], 'mit kürzerer Warnfrist sind die Vertragstermine noch nicht fällig');
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

        // ── Stufe 2: Abwesenheiten (F138) ────────────────────────────────────────────────────────────────────────
        // Eine Woche Montag bis Freitag, weit genug voraus, dass für Mia die Fassung mit fünf Arbeitstagen gilt
        $mo = strtotime('monday', strtotime('+70 days'));
        if (date('md', $mo) > '1220') {
            $mo = strtotime('+14 days', $mo);
        }
        $t = fn (int $plus) => date('Y-m-d', strtotime("+$plus days", $mo));
        $jahr = (int) date('Y', $mo);
        $ab = "$p/abwesenheiten";
        // Die Vorgabe der Urlaubsregel (Teilurlaub nach dem Gesetz, Übertrag, Verfall am 31.03.) gilt ab dem laufenden Jahr;
        // für diesen Teil wird sie abgeschaltet – die Regel selbst prüft der Block „Urlaubsregel“ weiter unten.
        $s0 = $this->status(200, $this->http('GET', $ab), 'Stand');
        $this->gleich([['ab_jahr' => (int) date('Y'), 'anteilig' => 'gesetz', 'rundung' => 'gesetz', 'uebertrag' => 'rest', 'uebertrag_hoechstens' => null, 'verfall' => 'bestaetigt', 'verfall_tag' => 31, 'verfall_monat' => 3]],
            $s0['urlaubsregeln'], 'Vorgabe der Urlaubsregel ab dem laufenden Jahr');
        $ohneRegel = ['anteilig' => 'aus', 'rundung' => 'gesetz', 'uebertrag' => 'nie', 'verfall' => 'nie'];
        $this->status(204, $this->http('PUT', "$p/urlaubsregeln/" . date('Y'), $ohneRegel), 'Urlaubsregel abgeschaltet');
        $st = $this->status(200, $this->http('GET', "$ab?jahr=$jahr"), 'Abwesenheiten, Stand');
        $this->gleich([true, true, true, [], 2], [$st['darf']['abwesenheit_pflegen'], $st['darf']['genehmigen'], $st['darf']['au_sehen'], $st['abwesenheiten'], count($st['konten'])], 'Personal: darf alles, noch nichts da, Konten der zwei Personen, die nicht ausgeschieden sind');
        $this->gleich(['Urlaub', 'Arbeitsunfähigkeit', 'Elternzeit', 'Fortbildung', 'Freistellung bezahlt', 'Freistellung unbezahlt', 'Mutterschutz', 'Sonderurlaub', 'Sonstige'], array_column($st['arten'], 'name'), 'Arten sind vorbelegt');
        $art = array_column($st['arten'], 'id', 'name');
        $this->gleich([28, false, 28], [($k = array_column($st['konten'], null, 'person')[$pm])['anspruch'], $k['festgesetzt'], $k['rest']], 'Anspruch aus dem Vertrag');
        $this->status(204, $this->http('PUT', "$p/freie-tage/{$t(2)}", ['name' => 'Betriebsausflug']), 'freier Tag des Betriebs');
        $this->status(400, $this->http('PUT', "$p/freie-tage/2027-02-30", ['name' => 'Unsinn']), 'ein freier Tag ist ein Datum');

        // Mitarbeiterin beantragt und meldet sich krank
        $this->anmelden($pkMia);
        $ich = $this->status(200, $this->http('GET', "$p/meine"), 'Meine Daten');
        $this->gleich([['Urlaub', 'Arbeitsunfähigkeit', 'Fortbildung', 'Freistellung bezahlt', 'Freistellung unbezahlt', 'Sonderurlaub'], [], true],
            [array_column($ich['abwesenheitsarten'], 'name'), $ich['abwesenheiten'], $ich['darf']['beantragen']], 'zur Wahl stehen nur Arten, die man selbst beantragt');
        $r = $this->status(201, $this->http('POST', "$p/meine/abwesenheiten", ['art' => $art['Urlaub'], 'von' => $t(0), 'bis' => $t(4), 'bemerkung' => 'Familienfeier']), 'Urlaub beantragt');
        $u1 = $r['id'];
        $this->gleich('beantragt', $r['zustand'], 'Urlaub braucht eine Genehmigung');
        $ich = $this->status(200, $this->http('GET', "$p/meine"), 'Meine Daten');
        $this->gleich([4, 'beantragt', 'Urlaub', null], [$ich['abwesenheiten'][0]['tage'], $ich['abwesenheiten'][0]['zustand'], $ich['abwesenheiten'][0]['art_name'], $ich['abwesenheiten'][0]['au']], 'vier Arbeitstage: der freie Tag des Betriebs zählt nicht');
        $kj = array_column($ich['urlaubskonto'], null, 'jahr')[$jahr] ?? self::kontoVon($this, "$p/meine", $jahr);
        $this->gleich([4, 28, 24], [$kj['beantragt'], $kj['rest'], $kj['rest_nach_antraegen']], 'Urlaubskonto: beantragt, Rest noch voll');
        $this->problem(409, 'KM003', $this->http('POST', "$p/meine/abwesenheiten", ['art' => $art['Fortbildung'], 'von' => $t(3), 'bis' => $t(3)]), 'zwei Abwesenheiten überschneiden sich nicht');
        $this->problem(422, 'KM002', $this->http('POST', "$p/meine/abwesenheiten", ['art' => $art['Urlaub'], 'von' => $t(7), 'bis' => $t(8), 'halber_tag' => true]), 'ein halber Tag ist genau ein Tag');
        $this->problem(422, 'KM002', $this->http('POST', "$p/meine/abwesenheiten", ['art' => $art['Urlaub'], 'von' => $t(5), 'bis' => $t(6)]), 'Urlaub am Wochenende: kein Arbeitstag');
        $this->status(400, $this->http('POST', "$p/meine/abwesenheiten", ['art' => $art['Elternzeit'], 'von' => $t(30), 'bis' => $t(60)]), 'Elternzeit trägt das Personal ein');
        $this->status(400, $this->http('POST', "$p/meine/abwesenheiten", ['art' => $art['Urlaub'], 'von' => $t(9), 'bis' => $t(8)]), 'das Ende liegt nicht vor dem Beginn');
        $this->status(400, $this->http('POST', "$p/meine/abwesenheiten", ['art' => $art['Urlaub'], 'von' => $t(8), 'bis' => $t(8), 'person' => $pv]), 'im Selfservice nennt man keine Person');
        $au = ['art' => $art['Arbeitsunfähigkeit'], 'von' => self::tag(0), 'bis' => self::tag(1)];
        $this->status(400, $this->http('POST', "$p/meine/abwesenheiten", $au + ['au' => ['diagnose' => 'erfunden']]), 'eine Diagnose nimmt kumero nicht an');
        $this->problem(422, 'KM002', $this->http('POST', "$p/meine/abwesenheiten", $au + ['bemerkung' => 'erfundene Beschwerden']), 'eine AU trägt keine Bemerkung');
        $this->problem(422, 'KM002', $this->http('POST', "$p/meine/abwesenheiten", ['art' => $art['Urlaub'], 'von' => $t(8), 'bis' => $t(8), 'au' => ['folge' => true]]), 'Angaben zur Bescheinigung nur bei einer AU');
        $r = $this->status(201, $this->http('POST', "$p/meine/abwesenheiten", $au + ['au' => ['festgestellt_am' => self::tag(0), 'folge' => false, 'arbeitsunfall' => true]]), 'krank gemeldet');
        $au1 = $r['id'];
        $this->gleich('genehmigt', $r['zustand'], 'eine AU gilt mit der Meldung');
        $ich = $this->status(200, $this->http('GET', "$p/meine"), 'Meine Daten');
        $eigene = array_column($ich['abwesenheiten'], null, 'id');
        $this->gleich(['festgestellt_am' => self::tag(0), 'folge' => false, 'arbeitsunfall' => true, 'nachweis' => false], $eigene[$au1]['au'], 'die eigene Bescheinigung sieht die Person');
        $this->problem(409, 'KM003', $this->http('POST', "$p/meine/abwesenheiten/$au1/ruecknahme", []), 'was begonnen hat, nimmt nur das Personal zurück');
        $this->problem(403, 'recht.fehlt', $this->http('POST', "$ab/$u1/entscheidung", ['genehmigen' => true]), 'niemand genehmigt sich selbst');
        $this->problem(403, 'recht.fehlt', $this->http('GET', $ab), 'die Liste der Abwesenheiten öffnet die Mitarbeiterin nicht');

        // Vorgesetzter: Anträge seines Teams mit Art, alles andere nur „abwesend“
        $this->anmelden($pkViktor);
        $this->gleich(1, $this->status(200, $this->http('GET', "$ab/zaehler"), 'Zähler')['anzahl'], 'ein Antrag wartet auf ihn');
        $st = $this->status(200, $this->http('GET', "$ab?jahr=$jahr"), 'Abwesenheiten aus Sicht des Vorgesetzten');
        $x = array_column($st['abwesenheiten'], null, 'id')[$u1];
        $this->gleich(['Urlaub', 'Familienfeier', 4, true, null], [$x['art_name'], $x['bemerkung'], $x['tage'], $x['entscheidbar'], $x['ist_au']], 'den Antrag sieht er mit Art und Bemerkung');
        $this->gleich([2, false, false], [count($st['konten']), $st['darf']['abwesenheit_pflegen'], $st['darf']['au_sehen']], 'Urlaubskonten des Teams und das eigene');
        $st0 = $this->status(200, $this->http('GET', "$ab?jahr=" . date('Y')), 'Abwesenheiten heute');
        $x = array_column($st0['abwesenheiten'], null, 'id')[$au1];
        $this->gleich([null, 'abwesend', null, null, null, null, 'genehmigt'], [$x['art'], $x['art_name'], $x['au'], $x['ist_au'], $x['tage'], $x['angelegt_am'], $x['zustand']], 'die AU ist für ihn nur „abwesend“');
        $this->ist(!str_contains(json_encode($st0['abwesenheiten']), $art['Arbeitsunfähigkeit']) && !str_contains(json_encode($st0['abwesenheiten']), 'arbeitsunfall":true'), 'nichts in der Antwort verrät die Art oder die Bescheinigung');
        $this->gleich(null, $this->status(200, $this->http('GET', "$p/personen/$pm"), 'Akte')['abwesenheiten'], 'in der Akte sieht er keine Abwesenheiten');
        $eigen = $this->status(201, $this->http('POST', "$p/meine/abwesenheiten", ['art' => $art['Urlaub'], 'von' => $t(14), 'bis' => $t(14), 'halber_tag' => true]), 'eigener Antrag des Vorgesetzten')['id'];
        $this->status(404, $this->http('POST', "$ab/$eigen/entscheidung", ['genehmigen' => true]), 'über sich selbst entscheidet er nicht');
        $this->problem(403, 'recht.fehlt', $this->http('POST', $ab, ['person' => $pm, 'art' => $art['Urlaub'], 'von' => $t(21), 'bis' => $t(21)]), 'eintragen darf nur das Personal');
        $this->problem(403, 'recht.fehlt', $this->http('PUT', "$ab/$au1/nachweis", ['liegt_vor' => true]), 'den Nachweis vermerkt nur das Personal');
        $this->problem(403, 'recht.fehlt', $this->http('PUT', "$p/urlaubskonten/$pm/$jahr", ['anspruch' => '30']), 'Urlaubskonten pflegt nur das Personal');
        $this->status(400, $this->http('POST', "$ab/$u1/entscheidung", ['genehmigen' => false]), 'eine Ablehnung braucht einen Grund');
        $this->status(204, $this->http('POST', "$ab/$u1/entscheidung", ['genehmigen' => true]), 'genehmigt');
        $this->problem(409, 'KM003', $this->http('POST', "$ab/$u1/entscheidung", ['genehmigen' => true]), 'nur einmal entscheiden');
        $this->gleich(0, $this->status(200, $this->http('GET', "$ab/zaehler"), 'Zähler')['anzahl'], 'der eigene Antrag zählt für ihn nicht');

        // Mitarbeiterin: genehmigt, zurückgenommen, abgelehnt
        $this->anmelden($pkMia);
        $kj = self::kontoVon($this, "$p/meine", $jahr);
        $this->gleich([0, 4, 24], [$kj['beantragt'], $kj['genehmigt'], $kj['rest']], 'Urlaubskonto: genehmigt');
        $this->status(204, $this->http('POST', "$p/meine/abwesenheiten/$u1/ruecknahme", []), 'Genehmigtes vor dem Beginn zurückgenommen');
        $this->gleich(28, self::kontoVon($this, "$p/meine", $jahr)['rest'], 'zurückgenommen zählt nicht');
        $this->status(404, $this->http('POST', "$p/meine/abwesenheiten/$eigen/ruecknahme", []), 'eine fremde Abwesenheit sieht aus wie eine unbekannte');
        $u2 = $this->status(201, $this->http('POST', "$p/meine/abwesenheiten", ['art' => $art['Urlaub'], 'von' => $t(0), 'bis' => $t(1)]), 'neuer Antrag im frei gewordenen Zeitraum')['id'];

        // Personal: entscheidet für alle, trägt ein, ändert, vermerkt den Nachweis
        $this->anmelden($pkPaula);
        $this->gleich(2, $this->status(200, $this->http('GET', "$ab/zaehler"), 'Zähler')['anzahl'], 'das Personal entscheidet alle offenen Anträge');
        $this->status(204, $this->http('POST', "$ab/$u2/entscheidung", ['genehmigen' => false, 'grund' => 'Praxis ist unterbesetzt']), 'abgelehnt mit Grund');
        $this->status(204, $this->http('POST', "$ab/$eigen/entscheidung", ['genehmigen' => true]), 'den Antrag des Vorgesetzten entscheidet das Personal (PE18)');
        $this->status(400, $this->http('POST', $ab, ['art' => $art['Elternzeit'], 'von' => $t(30), 'bis' => $t(400)]), 'beim Eintragen gehört die Person dazu');
        $ez = $this->status(201, $this->http('POST', $ab, ['person' => $po, 'art' => $art['Elternzeit'], 'von' => self::tag(-30), 'bis' => self::tag(-10)]), 'Elternzeit eingetragen – auch rückwirkend')['id'];
        $this->problem(422, 'KM002', $this->http('POST', $ab, ['person' => $po, 'art' => $art['Urlaub'], 'von' => self::tag(5), 'bis' => self::tag(6)]), 'nach dem Austritt keine neue Abwesenheit');
        // Urlaub über den Jahreswechsel: die Tage teilen sich auf zwei Urlaubskonten
        $von = ($jahr + 1) . '-12-28';
        $bis = ($jahr + 2) . '-01-08';
        $werktage = fn (string $a, string $b) => count(array_filter(range(strtotime($a), strtotime($b), 86400), fn (int $x) => (int) date('N', $x + 43200) <= 5 && date('md', $x + 43200) !== '0101'));   // Neujahr ist bundeseinheitlich frei
        $jw = $this->status(201, $this->http('POST', $ab, ['person' => $pm, 'art' => $art['Urlaub'], 'von' => $von, 'bis' => $bis]), 'Urlaub über den Jahreswechsel eingetragen')['id'];
        $k1 = array_column($this->status(200, $this->http('GET', "$ab?jahr=" . ($jahr + 1)), 'Stand')['konten'], null, 'person')[$pm];
        $st2 = $this->status(200, $this->http('GET', "$ab?jahr=" . ($jahr + 2)), 'Stand Folgejahr');
        $k2 = array_column($st2['konten'], null, 'person')[$pm];
        $this->gleich([$werktage($von, ($jahr + 1) . '-12-31'), $werktage(($jahr + 2) . '-01-01', $bis)], [$k1['genehmigt'], $k2['genehmigt']], 'je Jahr der Teil, der in ihm liegt');
        $this->gleich($werktage($von, $bis), array_column($st2['abwesenheiten'], null, 'id')[$jw]['tage'], 'der Eintrag steht in beiden Jahren mit allen Tagen');
        $this->problem(422, 'KM002', $this->http('POST', $ab, ['person' => $pv, 'art' => $art['Urlaub'], 'von' => "$jahr-12-01", 'bis' => ($jahr + 2) . '-01-05']), 'Urlaub reicht höchstens über einen Jahreswechsel');
        $this->status(204, $this->http('PUT', "$ab/$jw/ende", ['bis' => ($jahr + 1) . '-12-31']), 'Ende geändert');
        $k2 = array_column($this->status(200, $this->http('GET', "$ab?jahr=" . ($jahr + 2)), 'Stand')['konten'], null, 'person')[$pm];
        $this->gleich(0, $k2['genehmigt'], 'gekürzt: im Folgejahr zählt nichts mehr');
        $this->status(400, $this->http('POST', "$ab/$jw/ruecknahme", []), 'das Personal nimmt mit Grund zurück');
        $this->status(204, $this->http('POST', "$ab/$jw/ruecknahme", ['grund' => 'falsch eingetragen']), 'zurückgenommen');
        $this->problem(409, 'KM003', $this->http('PUT', "$ab/$jw/ende", ['bis' => ($jahr + 1) . '-12-30']), 'Zurückgenommenes ändert niemand mehr');
        // AU im genehmigten Urlaub: beides bleibt stehen, das Personal sieht den Hinweis
        $u3 = $this->status(201, $this->http('POST', $ab, ['person' => $pm, 'art' => $art['Urlaub'], 'von' => $t(21), 'bis' => $t(25)]), 'Urlaub eingetragen')['id'];
        $au2 = $this->status(201, $this->http('POST', $ab, ['person' => $pm, 'art' => $art['Arbeitsunfähigkeit'], 'von' => $t(22), 'bis' => $t(23), 'au' => ['folge' => true]]), 'AU im Urlaub')['id'];
        $st = array_column($this->status(200, $this->http('GET', "$ab?jahr=$jahr"), 'Stand')['abwesenheiten'], null, 'id');
        $this->gleich([true, false, true, true], [$st[$u3]['ueberschneidet_au'], $st[$au2]['ueberschneidet_au'], $st[$au2]['au']['folge'], $st[$au2]['ist_au']], 'Urlaub und AU zugleich: Hinweis am Urlaub');
        $this->status(204, $this->http('PUT', "$ab/$au2/nachweis", ['liegt_vor' => true]), 'Nachweis vermerkt');
        $this->problem(409, 'KM003', $this->http('PUT', "$ab/$u3/nachweis", ['liegt_vor' => true]), 'einen Nachweis gibt es nur zur AU');
        $this->gleich(true, array_column($this->status(200, $this->http('GET', "$ab?jahr=$jahr"), 'Stand')['abwesenheiten'], null, 'id')[$au2]['au']['nachweis'], 'der Nachweis liegt vor');
        // Urlaubskonto festsetzen
        $this->status(400, $this->http('PUT', "$p/urlaubskonten/$pm/$jahr", ['anspruch' => '20,5']), 'Tage mit Punkt');
        $this->status(204, $this->http('PUT', "$p/urlaubskonten/$pm/$jahr", ['anspruch' => '20', 'uebertrag' => '2.5']), 'Anspruch festgesetzt, Übertrag eingetragen');
        $k = array_column($this->status(200, $this->http('GET', "$ab?jahr=$jahr"), 'Stand')['konten'], null, 'person')[$pm];
        $this->gleich([20, true, 2.5, 5, 17.5], [$k['anspruch'], $k['festgesetzt'], $k['uebertrag'], $k['genehmigt'], $k['rest']], 'Rest = Anspruch + Übertrag − genehmigt');
        $this->status(204, $this->http('PUT', "$p/urlaubskonten/$pm/$jahr", ['anspruch' => null, 'uebertrag' => '2.5']), 'Anspruch wieder aus dem Vertrag');
        $this->gleich(28, array_column($this->status(200, $this->http('GET', "$ab?jahr=$jahr"), 'Stand')['konten'], null, 'person')[$pm]['anspruch'], 'aus dem Vertrag');
        // Arten
        $hosp = $this->status(201, $this->http('POST', "$p/abwesenheitsarten", ['name' => 'Hospitation', 'bezahlt' => true, 'genehmigung' => true, 'selbst' => true]), 'eigene Art')['id'];
        $this->problem(409, 'KM003', $this->http('POST', "$p/abwesenheitsarten", ['name' => 'hospitation']), 'Arten heißen verschieden');
        $this->status(204, $this->http('PUT', "$p/abwesenheitsarten/$hosp", ['name' => 'Hospitation', 'stillgelegt' => true]), 'stillgelegt');
        $this->problem(422, 'KM002', $this->http('POST', $ab, ['person' => $pm, 'art' => $hosp, 'von' => $t(40), 'bis' => $t(40)]), 'eine stillgelegte Art nimmt nichts Neues');
        $this->problem(409, 'KM003', $this->http('PUT', "$p/abwesenheitsarten/{$art['Arbeitsunfähigkeit']}", ['name' => 'Arbeitsunfähigkeit', 'stillgelegt' => true]), 'die AU lässt sich nicht stilllegen');
        $this->status(204, $this->http('PUT', "$p/abwesenheitsarten/{$art['Arbeitsunfähigkeit']}", ['name' => 'Krank', 'genehmigung' => true, 'zaehlt_urlaub' => true]), 'umbenennen geht');
        $krank = array_column($this->status(200, $this->http('GET', "$ab?jahr=$jahr"), 'Stand')['arten'], null, 'name')['Krank'];
        $this->gleich(['au', false, false], [$krank['kennung'], $krank['genehmigung'], $krank['zaehlt_urlaub']], 'die Bedeutung der AU bleibt');
        // Akte und Löschfrist
        $a = $this->status(200, $this->http('GET', "$p/personen/$pm"), 'Akte');
        $this->gleich([6, 2, true], [count($a['abwesenheiten']), count($a['urlaubskonto']), array_key_exists('loeschen_ab', $a['abwesenheiten'][0])], 'die Akte zeigt Abwesenheiten und Urlaubskonto');
        $this->gleich(true, array_column($this->status(200, $this->http('GET', $p), 'Stand')['personen'], 'frist_fehlt', 'id')[$po], 'eine Abwesenheit ohne Löschfrist: „Frist fehlt“');
        $this->status(204, $this->http('PUT', "$p/loeschfrist", ['objektart' => 'abwesenheit', 'id' => $ez, 'loeschen_ab' => '2035-01-01', 'grund' => 'Vorgabe des Verantwortlichen']), 'Löschfrist an einer Abwesenheit');
        $this->status(204, $this->http('PUT', "$p/freie-tage/{$t(2)}", ['name' => null]), 'freien Tag entfernt');
        $this->problem(409, 'KM003', $this->http('PUT', "$p/freie-tage/{$t(2)}", ['name' => null]), 'nur einmal');


        // ── Feiertage je Arbeitsort, Regelwerk, Korrektur der Arbeitstage (Abschnitt 10c) ─────────────────────────
        $st = $this->status(200, $this->http('GET', "$ab?jahr=2027"), 'Stand 2027');
        $this->gleich([20, []], [count($st['regelwerk']), $st['arbeitsorte']], 'Regelwerk mit zwanzig Feiertagen; noch kein Arbeitsort');
        $ohneOrt = array_column($st['feiertage'][''], 'name', 'datum');
        $this->gleich(['Neujahr', 'Karfreitag', 'Ostermontag', 'Tag der Arbeit', 'Christi Himmelfahrt', 'Pfingstmontag', 'Tag der Deutschen Einheit', '1. Weihnachtstag', '2. Weihnachtstag'],
            array_values($ohneOrt), 'ohne Arbeitsort gelten die neun bundeseinheitlichen');
        $this->gleich(['Karfreitag', 'Ostermontag', 'Christi Himmelfahrt', 'Pfingstmontag'], [$ohneOrt['2027-03-26'], $ohneOrt['2027-03-29'], $ohneOrt['2027-05-06'], $ohneOrt['2027-05-17']], 'bewegliche Feiertage 2027 aus dem Osterdatum (28.03.)');
        $bayern = array_column(array_filter($st['regelwerk'], fn ($f) => $f['bundesweit'] || in_array('BY', $f['laender'], true)), 'kennung');
        $this->status(400, $this->http('POST', "$p/arbeitsorte", ['name' => 'Nirgendwo', 'land' => 'Bayern']), 'das Land als Kürzel');
        $this->problem(422, 'KM002', $this->http('POST', "$p/arbeitsorte", ['name' => 'Nirgendwo', 'land' => 'XX']), 'unbekanntes Land');
        $augsburg = $this->status(201, $this->http('POST', "$p/arbeitsorte", ['name' => 'Augsburg', 'land' => 'BY', 'feiertage' => array_merge($bayern, ['friedensfest', 'mariae_himmelfahrt'])]), 'Arbeitsort Augsburg')['id'];
        $dresden = $this->status(201, $this->http('POST', "$p/arbeitsorte", ['name' => 'Praxis Dresden', 'land' => 'SN', 'standard' => true]), 'Arbeitsort des Mandanten')['id'];
        $this->problem(409, 'KM003', $this->http('POST', "$p/arbeitsorte", ['name' => 'augsburg', 'land' => 'BY']), 'Arbeitsorte heißen verschieden');
        $this->problem(409, 'KM003', $this->http('PUT', "$p/arbeitsorte/$dresden", ['name' => 'Praxis Dresden', 'land' => 'SN', 'stillgelegt' => true]), 'der Arbeitsort des Mandanten wird nicht stillgelegt');
        $st = $this->status(200, $this->http('GET', "$ab?jahr=2027"), 'Stand 2027');
        $orte = array_column($st['arbeitsorte'], null, 'id');
        $this->gleich([['friedensfest', 'mariae_himmelfahrt'], [], false, true], [$orte[$augsburg]['zusatz'], $orte[$augsburg]['ohne'], $orte[$augsburg]['standard'], $orte[$dresden]['standard']], 'Zuwahl gegenüber dem Land; Standard');
        $a27 = array_column($st['feiertage'][$augsburg], 'name', 'datum');
        $d27 = array_column($st['feiertage'][$dresden], 'name', 'datum');
        $this->gleich(['Fronleichnam', 'Augsburger Friedensfest', 'Mariä Himmelfahrt', 'Allerheiligen', null], [$a27['2027-05-27'], $a27['2027-08-08'], $a27['2027-08-15'], $a27['2027-11-01'], $a27['2027-10-31'] ?? null], 'Augsburg: Bayern mit Friedensfest');
        $this->gleich(['Reformationstag', 'Buß- und Bettag', null], [$d27['2027-10-31'], $d27['2027-11-17'], $d27['2027-11-01'] ?? null], 'Sachsen: Reformationstag und Buß- und Bettag, kein Allerheiligen');
        $this->status(204, $this->http('PUT', "$p/personen/$pm", ['personalnummer' => '101', 'familienname' => 'Mitarbeit', 'vornamen' => 'Mia', 'vorgesetzter' => $pv, 'nutzer' => $mia, 'arbeitsort' => $augsburg]), 'Mia arbeitet in Augsburg');
        $zeilen = array_column($this->status(200, $this->http('GET', $p), 'Stand')['personen'], 'arbeitsort_name', 'id');
        $this->gleich(['Augsburg', 'Praxis Dresden'], [$zeilen[$pm], $zeilen[$pv]], 'Arbeitsort je Person, sonst der des Mandanten');
        $fort = ['art' => $art['Fortbildung'], 'von' => '2027-11-01', 'bis' => '2027-11-02'];   // Montag (Allerheiligen) und Dienstag
        $fm = $this->status(201, $this->http('POST', $ab, ['person' => $pm] + $fort), 'Fortbildung in Augsburg')['id'];
        $fv = $this->status(201, $this->http('POST', $ab, ['person' => $pv] + $fort), 'Fortbildung in Dresden')['id'];
        $tage = fn () => array_map(fn ($x) => [$x['tage'], $x['tage_von_hand']], array_column($this->status(200, $this->http('GET', "$ab?jahr=2027"), 'Stand')['abwesenheiten'], null, 'id'));
        $this->gleich([[1, false], [2, false]], [$tage()[$fm], $tage()[$fv]], 'derselbe Zeitraum: in Augsburg ein Arbeitstag, in Dresden zwei');
        // Korrektur der Arbeitstage: von Hand setzen, neu rechnen
        $this->status(400, $this->http('PUT', "$ab/$fm/tage", ['tage' => '1,5']), 'Tage mit Punkt');
        $this->problem(422, 'KM002', $this->http('PUT', "$ab/$fm/tage", ['tage' => '9']), 'nicht mehr Arbeitstage als Kalendertage');
        $this->problem(422, 'KM002', $this->http('PUT', "$ab/$fm/tage", ['tage' => '1', 'tage_folgejahr' => '1']), '„im Folgejahr“ nur über den Jahreswechsel');
        $this->status(204, $this->http('PUT', "$ab/$fm/tage", ['tage' => '1.5']), 'Arbeitstage von Hand berichtigt');
        $this->gleich([1.5, true], $tage()[$fm], 'von Hand gesetzt und so gekennzeichnet');
        $this->status(204, $this->http('PUT', "$ab/$fm/tage", ['tage' => null]), 'neu gerechnet');
        $this->gleich([1, false], $tage()[$fm], 'wieder gerechnet');
        // Das Regelwerk in der Oberfläche anpassen – und die Vorgabe aus der Steuerdatei wieder einlesen
        $allerheiligen = array_column($st['regelwerk'], null, 'kennung')['allerheiligen'];
        $this->status(204, $this->http('PUT', "$p/feiertage/allerheiligen", ['laender' => []] + array_diff_key($allerheiligen, ['kennung' => 1])), 'Allerheiligen gilt in keinem Land mehr');
        $this->gleich([1, false], $tage()[$fm], 'Festgehaltenes ändert sich nicht von selbst');
        $this->status(204, $this->http('PUT', "$ab/$fm/tage", ['tage' => null]), 'neu gerechnet');
        $this->gleich(2, $tage()[$fm][0], 'nach dem geänderten Regelwerk zwei Arbeitstage');
        $this->status(400, $this->http('POST', "$p/feiertage", ['name' => 'Praxisjubiläum', 'art' => 'fest', 'monat' => 11]), 'ein festes Datum braucht Tag und Monat');
        $this->status(400, $this->http('POST', "$p/feiertage", ['name' => 'Praxisjubiläum', 'art' => 'fest', 'monat' => 11, 'tag' => 2, 'laender' => ['Bayern']]), 'Länder als Kürzel');
        $eigen = $this->status(201, $this->http('POST', "$p/feiertage", ['name' => 'Praxisjubiläum', 'art' => 'fest', 'monat' => 11, 'tag' => 2, 'bundesweit' => true]), 'eigener Feiertag')['kennung'];
        $this->status(204, $this->http('PUT', "$ab/$fm/tage", ['tage' => null]), 'neu gerechnet');
        $this->gleich(1, $tage()[$fm][0], 'der eigene Feiertag zählt');
        $r = $this->status(200, $this->http('POST', "$p/feiertage/vorgabe", []), 'Vorgabe aus der Steuerdatei eingelesen');
        $this->gleich(20, $r['anzahl'], 'zwanzig Einträge der Steuerdatei');
        $rw = array_column($this->status(200, $this->http('GET', "$ab?jahr=2027"), 'Stand')['regelwerk'], null, 'kennung');
        $this->gleich([['BW', 'BY', 'NW', 'RP', 'SL'], 21, 'Praxisjubiläum'], [$rw['allerheiligen']['laender'], count($rw), $rw[$eigen]['name']], 'die Vorgabe ist zurück, der eigene Feiertag bleibt');
        $this->status(204, $this->http('PUT', "$ab/$fm/tage", ['tage' => null]), 'neu gerechnet');
        $this->gleich(0, $tage()[$fm][0], 'Allerheiligen und der eigene Feiertag');
$this->status(204, $this->http('PUT', "$p/feiertage/$eigen", ['entfernen' => true]), 'eigenen Feiertag entfernt');
        $this->problem(409, 'KM003', $this->http('PUT', "$p/feiertage/$eigen", ['entfernen' => true]), 'nur einmal');
        $datei = json_decode((string) file_get_contents(dirname(__DIR__) . '/module/personal/regelwerk/feiertage-de.json'), true)['feiertage'];
        $this->gleich(array_column($datei, 'name', 'kennung'), array_column(array_column($this->status(200, $this->http('GET', "$ab?jahr=2027"), 'Stand')['regelwerk'], null, 'kennung'), 'name', 'kennung'), 'Regelwerk und Steuerdatei nennen dieselben Feiertage');

        // ── Urlaubsregel: Teilurlaub nach § 5 BUrlG, Übertrag, Verfall (Abschnitt 10c) ────────────────────────────
        $regel = ['anteilig' => 'gesetz', 'rundung' => 'gesetz', 'uebertrag' => 'rest', 'verfall' => 'bestaetigt', 'verfall_tag' => 31, 'verfall_monat' => 3];
        $this->status(400, $this->http('PUT', "$p/urlaubsregeln/$jahr", ['anteilig' => 'immer'] + $regel), 'unbekannter Wert');
        $this->status(400, $this->http('PUT', "$p/urlaubsregeln/$jahr", ['verfall_tag' => 31, 'verfall_monat' => 2] + $regel), 'der Stichtag ist ein Datum');
        $this->status(204, $this->http('PUT', "$p/urlaubsregeln/$jahr", $regel), "Regel ab $jahr");
        $v = fn (string $beginn, string $urlaub) => ['gueltig_ab' => $beginn, 'beginn' => $beginn, 'art' => 'unbefristet', 'befristet_bis' => null, 'probezeit_bis' => null, 'urlaubstage' => $urlaub,
            'verteilung' => [480, 480, 480, 480, 480, 0, 0]] + $vertrag;
        $nina = $this->status(201, $this->http('POST', "$p/personen", ['personalnummer' => '110', 'familienname' => 'Neu', 'vornamen' => 'Nina']), 'Person')['id'];
        $fritz = $this->status(201, $this->http('POST', "$p/personen", ['personalnummer' => '111', 'familienname' => 'Früh', 'vornamen' => 'Fritz']), 'Person')['id'];
        $this->status(201, $this->http('POST', "$p/personen/$nina/vertraege", $v("$jahr-08-01", '25')), 'Eintritt am 1. August');
        $this->status(201, $this->http('POST', "$p/personen/$fritz/vertraege", $v("$jahr-03-01", '24')), 'Eintritt am 1. März');
        $konto = function (string $person, int $j) use ($ab): array {
            $k = array_column($this->status(200, $this->http('GET', "$ab?jahr=$j"), 'Stand')['konten'], null, 'person')[$person];
            return array_map(fn ($x) => is_int($x) ? (float) $x : $x, array_diff_key($k, ['monate' => 1])) + ['monate' => $k['monate']];
        };
        $k = $konto($nina, $jahr);
        $this->gleich([10.42, 5, 25.0, false], [$k['anspruch'], $k['monate'], $k['voll'], $k['festgesetzt']], 'Eintritt in der zweiten Jahreshälfte: fünf Zwölftel; ein Bruchteil unter einem halben Tag bleibt stehen (§ 5 Abs. 2)');
        $k = $konto($fritz, $jahr);
        $this->gleich([24.0, null], [$k['anspruch'], $k['monate']], 'Eintritt in der ersten Jahreshälfte: die Wartezeit ist im Jahr erfüllt, voller Anspruch');
        $k = $konto($nina, $jahr + 1);
        $this->gleich([25.0, 10.42, false, ($jahr + 1) . '-03-31', 10.42, 0.0, 35.42], [$k['anspruch'], $k['uebertrag'], $k['uebertrag_festgesetzt'], $k['verfall_am'], $k['verfall_droht'], $k['verfallen'], $k['rest']],
            'Folgejahr: voller Anspruch, der Rest ist übertragen und verfällt am 31. März, wenn er nicht genommen wird');
        $this->status(204, $this->http('PUT', "$p/personen/$fritz", ['personalnummer' => '111', 'familienname' => 'Früh', 'vornamen' => 'Fritz', 'austritt' => ($jahr + 1) . '-04-30']), 'Austritt Ende April des Folgejahres');
        $k = $konto($fritz, $jahr + 1);
        $this->gleich([8.0, 4], [$k['anspruch'], $k['monate']], 'Ausscheiden in der ersten Jahreshälfte: vier Zwölftel');
        $this->status(204, $this->http('PUT', "$p/personen/$fritz", ['personalnummer' => '111', 'familienname' => 'Früh', 'vornamen' => 'Fritz', 'austritt' => ($jahr + 1) . '-09-30']), 'Austritt Ende September');
        $this->gleich(24.0, $konto($fritz, $jahr + 1)['anspruch'], 'Ausscheiden in der zweiten Jahreshälfte nach erfüllter Wartezeit: voller Anspruch');
        $this->status(204, $this->http('PUT', "$p/urlaubsregeln/$jahr", ['anteilig' => 'zwoelftel', 'rundung' => 'halbe'] + $regel), 'vertragliche Regel: immer Zwölftel, auf halbe Tage');
        $this->gleich([[10.5, 5], [20.0, 10], [18.0, 9]], [[($k = $konto($nina, $jahr))['anspruch'], $k['monate']], [($k = $konto($fritz, $jahr))['anspruch'], $k['monate']], [($k = $konto($fritz, $jahr + 1))['anspruch'], $k['monate']]],
            'Zwölftel in jedem Jahr des Ein- oder Austritts, gerundet auf halbe Tage');
        $this->status(204, $this->http('PUT', "$p/urlaubskonten/$nina/$jahr", ['anspruch' => '12']), 'für die Person festgesetzt');
        $k = $konto($nina, $jahr);
        $this->gleich([12.0, true], [$k['anspruch'], $k['festgesetzt']], 'was festgesetzt ist, geht der Regel vor (UR5)');
        $this->gleich(12.0, $konto($nina, $jahr + 1)['uebertrag'], 'und der Übertrag folgt dem festgesetzten Anspruch');
        $this->status(204, $this->http('PUT', "$p/personen/$fritz", ['personalnummer' => '111', 'familienname' => 'Früh', 'vornamen' => 'Fritz']), 'kein Austritt mehr');

        // Verfall an einem Stichtag, der vorbei ist: Eintritt 2024, Regel ab 2024
        $anna = $this->status(201, $this->http('POST', "$p/personen", ['personalnummer' => '112', 'familienname' => 'Alt', 'vornamen' => 'Anna']), 'Person')['id'];
        $this->status(201, $this->http('POST', "$p/personen/$anna/vertraege", $v('2024-01-01', '20')), 'Vertrag seit 2024');
        $this->gleich([20.0, 0.0], [($k = $konto($anna, 2025))['anspruch'], $k['uebertrag']], 'ohne Regel für 2025 kein Übertrag');
        $this->status(204, $this->http('PUT', "$p/urlaubsregeln/2024", $regel), 'Regel ab 2024');
        $k = $konto($anna, 2025);
        $this->gleich([20.0, '2025-03-31', 20.0, 0.0, 0.0, 40.0], [$k['uebertrag'], $k['verfall_am'], $k['verfall_offen'], $k['verfallen'], $k['verfall_droht'], $k['rest']],
            'Stichtag vorbei: Der Verfall wartet auf die Bestätigung des Personals – bis dahin zählt der Übertrag');
        $this->status(204, $this->http('PUT', "$p/urlaubskonten/$anna/2025", ['verfallen' => '20']), 'Verfall bestätigt');
        $k = $konto($anna, 2025);
        $this->gleich([20.0, 0.0, 20.0], [$k['verfallen'], $k['verfall_offen'], $k['rest']], 'bestätigt: verfallen, nichts gelöscht');
        $this->status(204, $this->http('PUT', "$p/urlaubskonten/$anna/2025", ['verfallen' => '0']), 'das Personal entscheidet: Es verfällt nichts');
        $this->gleich([0.0, 0.0, 40.0], [($k = $konto($anna, 2025))['verfallen'], $k['verfall_offen'], $k['rest']], 'null Tage verfallen, bestätigt');
        $this->status(204, $this->http('PUT', "$p/urlaubskonten/$anna/2025", []), 'Festsetzung zurückgenommen');
        $this->status(204, $this->http('PUT', "$p/urlaubsregeln/2024", ['verfall' => 'selbst'] + $regel), 'Verfall von selbst');
        $this->gleich([20.0, 0.0, 20.0], [($k = $konto($anna, 2025))['verfallen'], $k['verfall_offen'], $k['rest']], 'von selbst verfallen nach dem Stichtag');
        $this->status(201, $this->http('POST', $ab, ['person' => $anna, 'art' => $art['Urlaub'], 'von' => '2025-02-03', 'bis' => '2025-02-07']), 'fünf Tage Urlaub vor dem Stichtag nachgetragen');
        $k = $konto($anna, 2025);
        $this->gleich([15.0, 5.0, 20.0], [$k['verfallen'], $k['genommen'], $k['rest']], 'der Übertrag wird zuerst verbraucht (UR4): Es verfallen nur noch 15');
        $this->status(204, $this->http('PUT', "$p/urlaubsregeln/2024", ['uebertrag_hoechstens' => '5'] + $regel), 'höchstens fünf Tage Übertrag');
        $this->gleich([5.0, 0.0, 0.0, 20.0], [($k = $konto($anna, 2025))['uebertrag'], $k['verfall_offen'], $k['verfallen'], $k['rest']], 'begrenzter Übertrag, verbraucht – nichts verfällt');
        $this->status(204, $this->http('PUT', "$p/urlaubsregeln/2024", ['verfall' => 'nie', 'uebertrag_hoechstens' => null] + $regel), 'kein Verfall');
        $this->gleich([20.0, null, 35.0], [($k = $konto($anna, 2025))['uebertrag'], $k['verfall_am'], $k['rest']], 'ohne Verfall bleibt der Übertrag stehen');
        $this->status(204, $this->http('PUT', "$p/urlaubsregeln/2024", ['entfernen' => true]), 'Regel entfernt');
        $this->problem(409, 'KM003', $this->http('PUT', "$p/urlaubsregeln/2024", ['entfernen' => true]), 'nur einmal');
        $this->gleich(0.0, $konto($anna, 2025)['uebertrag'], 'ohne Regel wie zuvor');

        // Lohn: sieht alles mit Art und Bescheinigung, pflegt nichts
        $this->anmelden($pkLars);
        $st = $this->status(200, $this->http('GET', "$ab?jahr=" . date('Y')), 'Abwesenheiten für Lohn');
        $x = array_column($st['abwesenheiten'], null, 'id');
        $this->gleich(['Krank', true, 'Elternzeit', false], [$x[$au1]['art_name'], $x[$au1]['au']['arbeitsunfall'], $x[$ez]['art_name'], array_key_exists('loeschen_ab', $x[$ez])], 'Lohn sieht Art und Bescheinigung, keine Löschfrist');
        $this->problem(403, 'recht.fehlt', $this->http('POST', "$ab/$u3/ruecknahme", ['grund' => 'Probe']), 'Lohn nimmt nichts zurück');
        $this->problem(403, 'recht.fehlt', $this->http('POST', "$ab/$u3/entscheidung", ['genehmigen' => true]), 'und entscheidet nichts');
        $this->problem(403, 'recht.fehlt', $this->http('POST', "$p/arbeitsorte", ['name' => 'Fremd', 'land' => 'HE']), 'Arbeitsorte pflegt das Personal');
        $this->problem(403, 'recht.fehlt', $this->http('POST', "$p/feiertage/vorgabe", []), 'ebenso das Regelwerk');
        $this->problem(403, 'recht.fehlt', $this->http('PUT', "$p/urlaubsregeln/$jahr", $regel), 'und die Urlaubsregel');
        $this->problem(403, 'recht.fehlt', $this->http('PUT', "$ab/$fm/tage", ['tage' => null]), 'und die Korrektur der Arbeitstage');

        // Mitarbeiterin sieht die Ablehnung mit Grund
        $this->anmelden($pkMia);
        $eigene = array_column($this->status(200, $this->http('GET', "$p/meine"), 'Meine Daten')['abwesenheiten'], null, 'id');
        $this->gleich(['abgelehnt', 'Praxis ist unterbesetzt', 'zurueckgenommen'], [$eigene[$u2]['zustand'], $eigene[$u2]['grund'], $eigene[$u1]['zustand']], 'Ablehnung mit Grund; nichts verschwindet');
        $this->anmelden($pkPaula);

        // Protokoll: in der Kette des Mandanten, ohne einen einzigen Wert (PERSONAL.md Abschnitt 8)
        $typen = $this->sql("SELECT DISTINCT typ FROM mandant.protokoll WHERE typ LIKE 'personal.%' ORDER BY 1");
        foreach (['personal.person_angelegt', 'personal.person_geaendert', 'personal.privat_geaendert', 'personal.vertrag_angelegt', 'personal.frist_angelegt', 'personal.frist_erledigt',
                  'personal.vorschlag_angelegt', 'personal.vorschlag_uebernommen', 'personal.vorschlag_abgelehnt', 'personal.loeschfrist_gesetzt',
                  'personal.abwesenheit_beantragt', 'personal.abwesenheit_eingetragen', 'personal.abwesenheit_genehmigt', 'personal.abwesenheit_abgelehnt', 'personal.abwesenheit_zurueckgenommen',
                  'personal.abwesenheit_geaendert', 'personal.au_nachweis_vermerkt', 'personal.urlaubskonto_gesetzt', 'personal.abwesenheitsart_gesetzt', 'personal.freier_tag_gesetzt',
                  'personal.arbeitsort_gesetzt', 'personal.feiertag_gesetzt', 'personal.feiertag_entfernt', 'personal.urlaubsregel_gesetzt', 'personal.urlaubsregel_entfernt'] as $t) {
            $this->ist(in_array($t, $typen, true), "Protokoll kennt $t");
        }
        $alles = implode("\n", $this->sql("SELECT coalesce(vorher::text, '') || coalesce(nachher::text, '') || ids::text FROM mandant.protokoll WHERE typ LIKE 'personal.%'"));
        foreach (['Lindenweg', 'Mitarbeit', '0000 333333', 'privat.example', '1990-04-12', 'Fachangestellte', 'Nora', $mia, 'Familienfeier', 'unterbesetzt', 'falsch eingetragen', $art['Urlaub'], '2.5'] as $wert) {
            $this->ist(!str_contains($alles, $wert), "Protokoll enthält „{$wert}“ nicht");
        }
        $this->gleich(['{"felder": ["notfall_name", "telefon"]}'], $this->sql("SELECT nachher FROM mandant.protokoll WHERE typ = 'personal.vorschlag_angelegt' ORDER BY nr LIMIT 1"), 'genannt sind nur die Namen der Felder');
        $this->gleich([''], $this->sql("SELECT DISTINCT coalesce(vorher::text, '') || coalesce(nachher::text, '') FROM mandant.protokoll WHERE typ IN ('personal.abwesenheit_beantragt', 'personal.abwesenheit_eingetragen', 'personal.abwesenheit_genehmigt')") ?: [''], 'Abwesenheiten stehen nur mit IDs im Protokoll – ohne Art, ohne Zeitraum');
        $this->gleich([], $this->sql('SELECT nr, fehler FROM mandant.kette_pruefen()'), 'die Kette des Mandanten stimmt');
        $this->wirft(\RuntimeException::class, fn () => $this->sql("SET ROLE kumero_lesen; SELECT count(*) FROM personal.person"), 'das Lesekonto liest keine Personaldaten');
        $this->wirft(\RuntimeException::class, fn () => $this->sql("SET ROLE kumero_app; UPDATE personal.privat SET ort = 'x'"), 'Tabellen sind nur über Funktionen schreibbar');

        // Auskunft an Module eines Buchs: nur ID und Name, ohne Ausgeschiedene
        $k = Konfiguration::laden();
        $d = new Datenbank($k);
        $anfrage = new Anfrage(Request::create('/'), $k, $d, [], null, null, null, 'test', $d->verbindung(self::MANDANT));
        $this->gleich([['id' => $anna, 'name' => 'Alt, Anna'], ['id' => $fritz, 'name' => 'Früh, Fritz'], ['id' => $pm, 'name' => 'Mitarbeit, Mia'], ['id' => $nina, 'name' => 'Neu, Nina'], ['id' => $pv, 'name' => 'Vorsteher, Viktor']],
            Dienste::auskunftAmMandanten($anfrage, 'personal', 'personen'), 'wer zur Auswahl steht – ohne die ausgeschiedene Person');
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
