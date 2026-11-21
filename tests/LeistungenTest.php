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
 * Modul leistungen (LEISTUNGEN.md Abschnitt 10, F152, F154): Katalog mit Preisen als Fassungen, Eintragen durch die Person,
 * Bestätigen durch den Vorgesetzten, Löschen statt Storno, Sperre nach geprüfter Lohnvorbereitung, Sicht je Rolle – und der
 * Zuschlag aus bestätigten Leistungen in der Lohnvorbereitung (Anteil, fester Betrag, Staffel). Nur erfundene Personen.
 */
final class LeistungenTest extends Rahmen
{
    use Zugaenge;

    private const ORIGIN = Umgebung::ORIGIN;
    private const MANDANT = 'kumero_leistungspraxis';
    private const M = '/m/leistungspraxis/api/v1';
    private const P = '/m/leistungspraxis/api/v1/personal';

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

    public function testLeistungen(): void
    {
        $m = self::M;
        $p = self::P;
        $l = "$m/leistungen";
        $adamPk = $this->passkeyEinrichten($this->mandantAnlegen('leistungspraxis', 'Leistungspraxis'));
        [$paula, $pkPaula] = $this->nutzer('leistungspraxis', 'Paula', 'Personalerin', []);
        [$viktor, $pkViktor] = $this->nutzer('leistungspraxis', 'Viktor', 'Vorsteher', []);
        [$mia, $pkMia] = $this->nutzer('leistungspraxis', 'Mia', 'Mitarbeit', []);
        [$lars, $pkLars] = $this->nutzer('leistungspraxis', 'Lars', 'Lohnmann', []);
        $this->wirft(\RuntimeException::class, fn () => $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'leistungen', 'mandant' => 'leistungspraxis']), 'ohne das Personal lässt sich das Modul nicht einschalten');
        $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'personal', 'mandant' => 'leistungspraxis']);
        $aus = $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'leistungen', 'mandant' => 'leistungspraxis']);
        foreach (['Rolle Leistungen angelegt', 'Rolle Vorgesetzter: Leistungen: Team bestätigen', 'Modul leistungen 0.2.0 ist aktiv.'] as $t) {
            $this->ist(str_contains($aus, $t), "Aktivieren nennt „{$t}“ – $aus");
        }
        $this->gleich(['f'], $this->sql("SELECT has_schema_privilege('kumero_lesen', 'leistungen', 'USAGE')"), 'das Lesekonto der Datenbank sieht das Schema nicht');
        $this->anmelden($adamPk);
        foreach ([[$paula, 'Personal'], [$viktor, 'Vorgesetzter'], [$viktor, 'Leistungen'], [$mia, 'Leistungen'], [$mia, 'Mitarbeiter'], [$lars, 'Lohn']] as [$wer, $rolle]) {
            $this->status(201, $this->http('POST', "$m/zuweisungen", ['nutzer' => $wer, 'rolle' => $rolle]), "Rolle $rolle zugewiesen");
        }
        $this->problem(403, 'recht.fehlt', $this->http('GET', $l), 'Administrator: keine Leistungen');

        // Personal: Personen mit Vertrag, Katalog mit Preisen als Fassungen
        $this->anmelden($pkPaula);
        $monat = date('Y-m', strtotime('first day of last month'));      // ein abgelaufener Monat – dort gibt es eine Lohnvorbereitung
        $d = fn (int $tag) => sprintf('%s-%02d', $monat, $tag);
        $vertrag = ['gueltig_ab' => '2020-01-01', 'art' => 'unbefristet', 'funktion' => 'Therapeutin', 'beginn' => '2020-01-01', 'verteilung' => [480, 480, 480, 480, 480, 0, 0], 'urlaubstage' => '30'];
        $pv = $this->status(201, $this->http('POST', "$p/personen", ['familienname' => 'Vorsteher', 'vornamen' => 'Viktor', 'nutzer' => $viktor]), 'Vorgesetzter')['id'];
        $pm = $this->status(201, $this->http('POST', "$p/personen", ['familienname' => 'Mitarbeit', 'vornamen' => 'Mia', 'vorgesetzter' => $pv, 'nutzer' => $mia]), 'Mitarbeiterin')['id'];
        foreach ([$pv, $pm] as $id) {
            $this->status(201, $this->http('POST', "$p/personen/$id/vertraege", $vertrag), 'Vertrag');
        }
        $massage = $this->status(201, $this->http('POST', "$l/katalog", ['bezeichnung' => 'Massage 30 Minuten', 'gruppe' => 'Therapie', 'einheit' => 'Stück']), 'Leistung im Katalog')['id'];
        $kurs = $this->status(201, $this->http('POST', "$l/katalog", ['bezeichnung' => 'Kursstunde', 'gruppe' => 'Kurse', 'einheit' => 'Stunde', 'preis_frei' => true]), 'Leistung mit freiem Preis')['id'];
        $this->problem(409, 'KM003', $this->http('POST', "$l/katalog", ['bezeichnung' => 'massage 30 minuten']), 'eine Bezeichnung nur einmal');
        $this->status(400, $this->http('POST', "$l/katalog", ['bezeichnung' => '']), 'eine Leistung hat eine Bezeichnung');
        $this->status(400, $this->http('POST', "$l/katalog", ['bezeichnung' => 'X', 'empfaenger' => 'niemand']), 'unbekanntes Feld – einen Empfänger gibt es nicht');
        $this->status(204, $this->http('PUT', "$l/katalog/$massage/preis", ['gueltig_ab' => '2020-01-01', 'preis_cent' => 4000]), 'Preis ab 2020');
        $this->status(204, $this->http('PUT', "$l/katalog/$massage/preis", ['gueltig_ab' => $d(15), 'preis_cent' => 5000]), 'neuer Preis ab der Monatsmitte');
        $this->status(204, $this->http('PUT', "$l/katalog/$kurs/preis", ['gueltig_ab' => '2020-01-01', 'preis_cent' => 6000]), 'Preis der Kursstunde');
        $this->status(400, $this->http('PUT', "$l/katalog/$massage/preis", ['gueltig_ab' => $d(15), 'preis_cent' => 12.5]), 'Preise sind ganze Cent');
        $st = $this->status(200, $this->http('GET', "$l?monat=$monat"), 'Leistungen des Monats');
        $this->gleich([[], ['Mitarbeit, Mia', 'Vorsteher, Viktor'], ['Kursstunde', 'Massage 30 Minuten'], 2, true], [$st['zeilen'], array_column($st['personen'], 'name'), array_column($st['katalog'], 'bezeichnung'),
            count(array_column($st['katalog'], null, 'id')[$massage]['preise']), $st['darf']['pflegen']], 'Personal: noch keine Zeile, alle Personen zur Wahl, Katalog mit den Fassungen der Preise');

        // Mia trägt ein; der Preis ist der ihres Tages; bestätigt ist noch nichts
        $this->anmelden($pkMia);
        $z1 = $this->status(201, $this->http('POST', "$l/meine/zeilen", ['tag' => $d(3), 'leistung' => $massage, 'menge' => '10', 'vorgang' => 'V-2031']), 'eingetragen vor der Preisänderung')['id'];
        $z2 = $this->status(201, $this->http('POST', "$l/meine/zeilen", ['tag' => $d(20), 'leistung' => $massage, 'menge' => '4']), 'eingetragen nach der Preisänderung')['id'];
        $z3 = $this->status(201, $this->http('POST', "$l/meine/zeilen", ['tag' => $d(21), 'leistung' => $kurs, 'menge' => '1.5', 'einzel_cent' => 8000]), 'freier Preis')['id'];
        $this->status(400, $this->http('POST', "$l/meine/zeilen", ['tag' => $d(21), 'leistung' => $massage, 'menge' => '0']), 'die Menge ist größer als null');
        $this->status(400, $this->http('POST', "$l/meine/zeilen", ['tag' => $d(21), 'leistung' => $massage, 'menge' => '1', 'vorgang' => 'Frau Beispiel']), 'der Vorgang ist eine neutrale Kennung, kein Name');
        $this->status(400, $this->http('POST', "$l/meine/zeilen", ['tag' => $d(21), 'leistung' => $massage, 'menge' => '1', 'kunde' => 'x']), 'kein Empfänger an der Leistung');
        $this->problem(422, 'KM002', $this->http('POST', "$l/meine/zeilen", ['tag' => self::tag(5), 'leistung' => $massage, 'menge' => '1']), 'nicht im Voraus');
        $ich = $this->status(200, $this->http('GET', "$l/meine?monat=$monat"), 'Meine Leistungen');
        $zeilen = array_column($ich['zeilen'], null, 'id');
        $this->gleich([[4000, 40000], [5000, 20000], [8000, 12000], 72000, 0, false], [[$zeilen[$z1]['einzel_cent'], $zeilen[$z1]['betrag_cent']], [$zeilen[$z2]['einzel_cent'], $zeilen[$z2]['betrag_cent']],
            [$zeilen[$z3]['einzel_cent'], $zeilen[$z3]['betrag_cent']], $ich['summe_cent'], $ich['bestaetigt_cent'], $zeilen[$z1]['bestaetigt']], 'Preis des Tages, freier Preis, Summen; nichts ist bestätigt');
        $this->ist(!array_key_exists('preise', $ich['katalog'][0]), 'der Katalog für das Eintragen nennt nur den Preis von heute');
        $this->problem(403, 'recht.fehlt', $this->http('GET', $l), 'ohne das Recht keine Übersicht');
        $this->problem(403, 'recht.fehlt', $this->http('POST', "$l/zeilen/$z1/bestaetigung", []), 'niemand bestätigt sich selbst');
        $weg = $this->status(201, $this->http('POST', "$l/meine/zeilen", ['tag' => $d(22), 'leistung' => $massage, 'menge' => '1']), 'eine Zeile zu viel')['id'];
        $this->status(204, $this->http('DELETE', "$l/meine/zeilen/$weg"), 'eine unbestätigte eigene Zeile wird gelöscht – kein Storno');
        $this->gleich([], $this->sql("SELECT id FROM leistungen.zeile WHERE id = '$weg'"), 'die Zeile ist wirklich weg');

        // Der Vorgesetzte bestätigt sein Team, trägt für es ein und entscheidet nicht über Eigenes
        $this->anmelden($pkViktor);
        $st = $this->status(200, $this->http('GET', "$l?monat=$monat"), 'Leistungen des Teams');
        $this->gleich([3, ['Mitarbeit, Mia'], false, false], [count($st['zeilen']), array_column($st['personen'], 'name'), $st['darf']['pflegen'], array_key_exists('preise', $st['katalog'][0])], 'er sieht sein Team, trägt nur für es ein, pflegt keinen Katalog');
        $this->status(204, $this->http('POST', "$l/zeilen/$z1/bestaetigung", []), 'bestätigt');
        $this->status(204, $this->http('POST', "$l/zeilen/$z2/bestaetigung", []), 'bestätigt');
        $this->problem(409, 'KM003', $this->http('POST', "$l/zeilen/$z1/bestaetigung", []), 'nur einmal');
        $z4 = $this->status(201, $this->http('POST', "$l/zeilen", ['person' => $pm, 'tag' => $d(25), 'leistung' => $massage, 'menge' => '2']), 'für die Mitarbeiterin eingetragen')['id'];
        $this->gleich(['t'], $this->sql("SELECT bestaetigt_am IS NOT NULL FROM leistungen.zeile WHERE id = '$z4'"), 'was der Vorgesetzte einträgt, gilt als bestätigt');
        $this->status(404, $this->http('POST', "$l/zeilen", ['person' => $pv, 'tag' => $d(25), 'leistung' => $massage, 'menge' => '2']), 'für sich selbst trägt er hier nicht ein');
        $eigene = $this->status(201, $this->http('POST', "$l/meine/zeilen", ['tag' => $d(10), 'leistung' => $massage, 'menge' => '3']), 'eigene Leistung des Vorgesetzten')['id'];
        $this->problem(403, 'recht.fehlt', $this->http('POST', "$l/zeilen/$eigene/bestaetigung", []), 'über Eigenes entscheidet er nicht');
        $this->problem(403, 'recht.fehlt', $this->http('POST', "$l/katalog", ['bezeichnung' => 'Fremd']), 'den Katalog pflegt das Personal');
        $this->anmelden($pkMia);
        $this->problem(409, 'leistungen.bestaetigt', $this->http('DELETE', "$l/meine/zeilen/$z1"), 'eine bestätigte Zeile löscht die Person nicht mehr selbst');

        // Lohn sieht alles und ändert nichts; das Personal bestätigt die Leistung des Vorgesetzten
        $this->anmelden($pkLars);
        $this->gleich([5, false], [count(($st = $this->status(200, $this->http('GET', "$l?monat=$monat"), 'Lohn sieht alle'))['zeilen']), $st['darf']['bestaetigen']], 'Lohn liest alle Leistungen');
        $this->problem(403, 'recht.fehlt', $this->http('POST', "$l/zeilen/$z3/bestaetigung", []), 'Lohn bestätigt nicht');
        $this->anmelden($pkPaula);
        $this->status(204, $this->http('POST', "$l/zeilen/$eigene/bestaetigung", []), 'das Personal bestätigt die Leistung des Vorgesetzten');

        // Zuschläge aus den bestätigten Leistungen: Anteil an einer Gruppe, fester Betrag je Leistung, Staffel über alles
        $regel = fn (string $name, array $fassung) => $this->status(201, $this->http('POST', "$p/zuschlagsregeln", ['name' => $name, 'grundlage' => 'leistung', 'fassung' => ['gueltig_ab' => '2020-01-01'] + $fassung]), "Regel $name")['id'];
        $ra = $regel('Umsatzbeteiligung Therapie', ['form' => 'anteil', 'satz_bp' => 1000, 'umfang' => 'gruppe', 'bezug' => 'therapie']);
        $rb = $regel('Prämie je Massage', ['form' => 'betrag', 'betrag_cent' => 250, 'umfang' => 'leistung', 'bezug' => 'Massage 30 Minuten']);
        $rs = $regel('Staffel', ['form' => 'staffel', 'satz_bp' => 500, 'schwelle_cent' => 50000, 'satz_ab_schwelle_bp' => 1000, 'umfang' => 'alle']);
        foreach ([$ra, $rb, $rs] as $r) {
            $this->status(201, $this->http('POST', "$p/zuschlagsregeln/$r/zuordnungen", ['person' => $pm, 'von' => '2020-01-01']), 'Regel an der Person');
        }
        $lohn = fn () => array_column($this->status(200, $this->http('GET', "$p/lohn?monat=$monat"), 'Lohnvorbereitung')['personen'], null, 'person')[$pm]['inhalt'];
        $zu = array_column($lohn()['zuschlaege'], null, 'name');
        // bestätigt sind z1 (400,00), z2 (200,00), z4 (100,00) – z3 (Kurs, 120,00) wartet
        $this->gleich([7000, 4000, 4500, 3], [$zu['Umsatzbeteiligung Therapie']['betrag_cent'], $zu['Prämie je Massage']['betrag_cent'], $zu['Staffel']['betrag_cent'], count($zu['Staffel']['nachweis'])],
            '10 % von 700,00 · 2,50 je Massage × 16 · Staffel: 5 % bis 500,00, 10 % auf die 200,00 darüber; die unbestätigte Leistung zählt nicht');
        $n = $zu['Umsatzbeteiligung Therapie']['nachweis'][0];
        $this->gleich([$d(3), 'Massage 30 Minuten · V-2031', '10.00', 40000, 4000], [$n['datum'], $n['text'], $n['menge'], $n['grundlage_cent'], $n['betrag_cent']], 'der Nachweis nennt Tag, Leistung mit Vorgang, Menge, Betrag und Zuschlag');
        $this->status(204, $this->http('POST', "$l/zeilen/$z3/bestaetigung", []), 'das Personal bestätigt die Kursstunde');
        $zu = array_column($lohn()['zuschlaege'], null, 'name');
        $this->gleich([7000, 5700], [$zu['Umsatzbeteiligung Therapie']['betrag_cent'], $zu['Staffel']['betrag_cent']], 'die Kursstunde zählt für die Staffel (820,00), nicht für die Gruppe Therapie');

        // geprüfte Lohnvorbereitung: An den Leistungen des Monats ändert sich nichts mehr
        $this->status(204, $this->http('POST', "$p/lohn/$monat/$pm/pruefung", []), 'Lohnvorbereitung geprüft');
        $this->problem(409, 'KM003', $this->http('DELETE', "$l/zeilen/$z4"), 'nach der Prüfung wird nichts gelöscht');
        $this->problem(409, 'KM003', $this->http('POST', "$l/zeilen", ['person' => $pm, 'tag' => $d(26), 'leistung' => $massage, 'menge' => '1']), 'und nichts eingetragen');
        $this->ist(array_column($this->status(200, $this->http('GET', "$l?monat=$monat"), 'Stand')['zeilen'], null, 'id')[$z4]['fest'], 'die Zeile zeigt, dass sie in der Lohnvorbereitung steht');
        $this->status(204, $this->http('POST', "$p/lohn/$monat/$pm/oeffnung", []), 'Prüfung zurückgenommen');
        $this->status(204, $this->http('DELETE', "$l/zeilen/$z4"), 'jetzt löscht das Personal die Zeile');
        $this->gleich(4700, array_column($lohn()['zuschlaege'], null, 'name')['Staffel']['betrag_cent'], 'ohne die gelöschte Zeile 720,00: 5 % bis 500,00 und 10 % auf die 220,00 darüber');

        // Katalog ändern lässt Eingetragenes stehen; Stillgelegtes steht nicht mehr zur Wahl
        $this->status(204, $this->http('PUT', "$l/katalog/$massage", ['bezeichnung' => 'Massage kurz', 'gruppe' => 'Wellness', 'stillgelegt' => true]), 'Leistung umbenannt und stillgelegt');
        $this->gleich(['Massage 30 Minuten', 'Therapie'], [($z = array_column($this->status(200, $this->http('GET', "$l?monat=$monat"), 'Stand')['zeilen'], null, 'id')[$z1])['bezeichnung'], $z['gruppe']], 'eine eingetragene Leistung behält Bezeichnung und Gruppe ihres Tages');
        $this->problem(409, 'KM003', $this->http('POST', "$l/zeilen", ['person' => $pm, 'tag' => self::tag(0), 'leistung' => $massage, 'menge' => '1']), 'eine stillgelegte Leistung lässt sich nicht eintragen');

        // Protokoll: IDs, nie ein Betrag; ohne das Modul bleibt es bei „Nachweis fehlt“
        $alles = implode("\n", $this->sql("SELECT typ || coalesce(vorher::text, '') || coalesce(nachher::text, '') || ids::text FROM mandant.protokoll WHERE typ LIKE 'leistungen.zeile%'"));
        $this->ist(str_contains($alles, 'leistungen.zeile_geloescht') && !str_contains($alles, 'V-2031') && !str_contains($alles, '40000'), 'im Protokoll der Zeilen stehen IDs, kein Betrag, kein Vorgang');
        $this->u->cli(['command' => 'modul:ausschalten', 'modul' => 'leistungen', 'mandant' => 'leistungspraxis']);
        $this->gleich(null, array_column($lohn()['zuschlaege'], null, 'name')['Staffel']['betrag_cent'], 'ausgeschaltet: wieder „Nachweis fehlt“ statt eines Betrags');
    }
}
