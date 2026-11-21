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
 * Modul zeit (ZEIT.md Abschnitt 9, F145): Stempeln, Nachtrag und Korrektur, Pausen nach Regel, Höchstzeit, Regelarbeitstage,
 * Gleitzeit mit Rahmen, Zeitkonto, Monatsabschluss, Sicht je Rolle – und die Schnittstelle zum Personal in beiden
 * Richtungen (Soll, Feiertage, Abwesenheiten hinein; Ist-Stunden in die Lohnvorbereitung hinaus). Nur erfundene Personen.
 */
final class ZeitTest extends Rahmen
{
    use Zugaenge;

    private const ORIGIN = Umgebung::ORIGIN;
    private const MANDANT = 'kumero_zeitpraxis';
    private const M = '/m/zeitpraxis/api/v1';
    private const P = '/m/zeitpraxis/api/v1/personal';

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

    public function testZeiterfassung(): void
    {
        $m = self::M;
        $p = self::P;
        $z = "$m/zeit";
        $adamPk = $this->passkeyEinrichten($this->mandantAnlegen('zeitpraxis', 'Zeitpraxis'));
        [$paula, $pkPaula] = $this->nutzer('zeitpraxis', 'Paula', 'Personalerin', []);
        [$viktor, $pkViktor] = $this->nutzer('zeitpraxis', 'Viktor', 'Vorsteher', []);
        [$mia, $pkMia] = $this->nutzer('zeitpraxis', 'Mia', 'Mitarbeit', []);
        [$lars, $pkLars] = $this->nutzer('zeitpraxis', 'Lars', 'Lohnmann', []);
        $this->wirft(\RuntimeException::class, fn () => $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'zeit', 'mandant' => 'zeitpraxis']), 'ohne das Personal lässt sich die Zeiterfassung nicht einschalten');
        $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'personal', 'mandant' => 'zeitpraxis']);
        $aus = $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'zeit', 'mandant' => 'zeitpraxis']);
        foreach (['Rolle Zeiterfassung angelegt', 'Rolle Vorgesetzter: Zeit: Team freigeben', 'Modul zeit 0.1.0 ist aktiv.'] as $t) {
            $this->ist(str_contains($aus, $t), "Aktivieren nennt „{$t}“ – $aus");
        }
        $this->gleich(['f'], $this->sql("SELECT has_schema_privilege('kumero_lesen', 'zeit', 'USAGE')"), 'das Lesekonto der Datenbank sieht das Schema nicht');
        $this->anmelden($adamPk);
        foreach ([[$paula, 'Personal'], [$viktor, 'Vorgesetzter'], [$viktor, 'Zeiterfassung'], [$mia, 'Zeiterfassung'], [$mia, 'Mitarbeiter'], [$lars, 'Lohn']] as [$wer, $rolle]) {
            $this->status(201, $this->http('POST', "$m/zuweisungen", ['nutzer' => $wer, 'rolle' => $rolle]), "Rolle $rolle zugewiesen");
        }
        $this->problem(403, 'recht.fehlt', $this->http('GET', $z), 'Administrator: keine Zeiten');

        // Personal: Personen mit Vertrag Montag bis Freitag je acht Stunden
        $this->anmelden($pkPaula);
        $j = (int) date('Y') - 1;                      // eine Woche ohne Feiertag: ab dem ersten Montag im Juli des Vorjahres
        $mo = (int) date('j', strtotime("first monday of july $j"));
        $d = fn (int $plus) => sprintf('%d-07-%02d', $j, $mo + $plus);
        $monat = "$j-07";
        $vertrag = ['gueltig_ab' => ($j - 1) . '-01-01', 'art' => 'unbefristet', 'beginn' => ($j - 1) . '-01-01', 'verteilung' => [480, 480, 480, 480, 480, 0, 0], 'urlaubstage' => '30'];
        $pv = $this->status(201, $this->http('POST', "$p/personen", ['personalnummer' => '100', 'familienname' => 'Vorsteher', 'vornamen' => 'Viktor', 'nutzer' => $viktor]), 'Vorgesetzter')['id'];
        $pm = $this->status(201, $this->http('POST', "$p/personen", ['personalnummer' => '101', 'familienname' => 'Mitarbeit', 'vornamen' => 'Mia', 'vorgesetzter' => $pv, 'nutzer' => $mia]), 'Mitarbeiterin')['id'];
        foreach ([$pv, $pm] as $id) {
            $this->status(201, $this->http('POST', "$p/personen/$id/vertraege", $vertrag), 'Vertrag');
        }
        $st = $this->status(200, $this->http('GET', "$z?monat=$monat"), 'Zeiten des Monats');
        $this->gleich([['alle' => true, 'pflegen' => true, 'freigeben' => true], ['Mitarbeit, Mia', 'Vorsteher, Viktor'], 'person', 600], [$st['darf'], array_column($st['personen'], 'name'), $st['einstellung']['monatsabschluss'], $st['einstellung']['hoechst_minuten']], 'Personal sieht alle; Regeln sind vorbelegt');
        $this->gleich([['ab' => 360, 'pause' => 30], ['ab' => 540, 'pause' => 45]], $st['einstellung']['pausen'], 'Pausen: über 6 Std. 30 Min., über 9 Std. 45 Min.');

        // Das Personal trägt für Mia eine Woche nach – das gilt sofort
        $nach = fn (int $plus, string $von, string $bis) => $this->status(201, $this->http('POST', "$z/personen/$pm/antraege", ['art' => 'nachtrag', 'tag' => $d($plus), 'von' => $von, 'bis' => $bis, 'begruendung' => 'Stempeluhr ausgefallen']), "Nachtrag {$von}–{$bis}")['id'];
        $this->status(400, $this->http('POST', "$z/personen/$pm/antraege", ['art' => 'nachtrag', 'tag' => $d(0), 'von' => '08:00', 'bis' => '16:30']), 'ein Nachtrag braucht eine Begründung');
        $this->status(400, $this->http('POST', "$z/personen/$pm/antraege", ['art' => 'nachtrag', 'tag' => $d(0), 'von' => '8 Uhr', 'bis' => '16:30', 'begruendung' => 'x']), 'Uhrzeiten haben eine Form');
        $nach(0, '08:00', '16:30');                    // Montag ohne Pause
        $nach(1, '08:00', '12:00'); $nach(1, '12:20', '17:00');   // Dienstag mit 20 Minuten Pause
        $nach(2, '08:00', '14:10');                    // Mittwoch knapp über sechs Stunden
        $nach(3, '07:00', '19:00');                    // Donnerstag zwölf Stunden
        $nach(5, '09:00', '12:00');                    // Samstag – kein Regelarbeitstag
        $this->problem(409, 'KM003', $this->http('POST', "$z/personen/$pm/antraege", ['art' => 'nachtrag', 'tag' => $d(0), 'von' => '16:00', 'bis' => '18:00', 'begruendung' => 'doppelt']), 'Abschnitte eines Tages überschneiden sich nicht');
        $this->problem(422, 'KM002', $this->http('POST', "$z/personen/$pm/antraege", ['art' => 'nachtrag', 'tag' => self::tag(3), 'von' => '08:00', 'bis' => '09:00', 'begruendung' => 'künftig']), 'kein Nachtrag für die Zukunft');
        $blatt = fn (string $wer = null) => $this->status(200, $this->http('GET', "$z/personen/" . ($wer ?? $pm) . "?monat=$monat"), 'Monatsblatt');
        $tag = fn (int $plus, ?array $b = null) => array_column(($b ?? $blatt())['tage'], null, 'tag')[$d($plus)];
        $werte = fn (array $t) => [$t['brutto'], $t['pause'], $t['abzug'], $t['gezaehlt'], $t['soll'], $t['saldo']];
        $b = $blatt();
        $this->gleich([510, 0, 30, 480, 480, 0], $werte($tag(0, $b)), 'Montag: 8:30 ohne Pause – 30 Minuten abgezogen');
        $this->gleich([520, 20, 10, 510, 480, 30], $werte($tag(1, $b)), 'Dienstag: 20 Minuten Pause gestempelt – nur die fehlenden 10 abgezogen');
        $this->gleich([370, 0, 10, 360, 480, -120], $werte($tag(2, $b)), 'Mittwoch: 6:10 – der Abzug geht nicht unter die Schwelle von sechs Stunden');
        $this->gleich([720, 0, 45, 600, 480, 120, ['ueber_hoechst']], array_merge($werte($t = $tag(3, $b)), [$t['braucht']]), 'Donnerstag: über zehn Stunden zählt nichts – ohne Sonderfreigabe');
        $this->gleich([0, 0, 0, 0, 480, -480, []], array_merge($werte($t = $tag(4, $b)), [$t['abschnitte']]), 'Freitag: nichts gebucht – das Soll fehlt');
        $this->gleich([180, 0, 0, 0, 0, 0, ['ausserhalb'], false], array_merge($werte($t = $tag(5, $b)), [$t['braucht'], $t['arbeitstag']]), 'Samstag: kein Regelarbeitstag – zählt erst mit Genehmigung');
        $zeile = array_column($this->status(200, $this->http('GET', "$z?monat=$monat"), 'Übersicht')['personen'], null, 'person')[$pm];
        $this->gleich([2, 0, true], [$zeile['tage_offen'], $zeile['antraege'], $zeile['zustaendig']], 'die Übersicht zählt, was auf eine Freigabe wartet');

        // Freigaben einzelner Tage
        $this->status(400, $this->http('PUT', "$z/personen/$pm/tage/{$d(3)}/freigabe", ['art' => 'egal', 'genehmigt' => true]), 'unbekannte Art');
        $this->problem(422, 'KM002', $this->http('PUT', "$z/personen/$pm/tage/{$d(5)}/freigabe", ['art' => 'ausserhalb', 'genehmigt' => false]), 'eine Ablehnung braucht einen Grund');
        $this->status(204, $this->http('PUT', "$z/personen/$pm/tage/{$d(3)}/freigabe", ['art' => 'ueber_hoechst', 'genehmigt' => true, 'grund' => 'Notdienst']), 'Sonderfreigabe über zehn Stunden');
        $this->status(204, $this->http('PUT', "$z/personen/$pm/tage/{$d(5)}/freigabe", ['art' => 'ausserhalb', 'genehmigt' => false, 'grund' => 'nicht angeordnet']), 'Samstag abgelehnt');
        $b = $blatt();
        $this->gleich([[675, 195, []], [0, 0, []]], [[($t = $tag(3, $b))['gezaehlt'], $t['saldo'], $t['braucht']], [($t = $tag(5, $b))['gezaehlt'], $t['saldo'], $t['braucht']]], 'mit Sonderfreigabe zählt der ganze Tag; der abgelehnte Samstag zählt nicht und wartet nicht mehr');
        $this->status(204, $this->http('PUT', "$z/personen/$pm/tage/{$d(5)}/freigabe", ['art' => 'ausserhalb', 'genehmigt' => true]), 'Samstag doch genehmigt');
        $this->gleich([180, 180], [($t = $tag(5))['gezaehlt'], $t['saldo']], 'genehmigte Arbeit außerhalb der Regelarbeitstage zählt ganz als Plus');

        // Zeitkonto: Anfangssaldo und die Salden seit Beginn
        $this->status(400, $this->http('PUT', "$z/personen/$pm/profil", ['modell' => 'schicht']), 'unbekanntes Modell');
        $this->status(204, $this->http('PUT', "$z/personen/$pm/profil", ['modell' => 'fest', 'anfangssaldo' => 60, 'beginn' => $d(0)]), 'Zeitprofil: Beginn und Anfangssaldo');
        $b = $blatt();
        $erwartet = 60 + array_sum(array_map(fn (array $t) => $t['tag'] >= $d(0) ? $t['saldo'] : 0, $b['tage']));
        $this->gleich([$erwartet, 'fest', true], [$b['konto'], $b['profil']['modell'], $b['profil']['gesetzt']], 'Zeitkonto am Monatsende');
        // bezahlte Abwesenheit schreibt das Soll gut; ein Feiertag hat kein Soll
        $arten = array_column($this->status(200, $this->http('GET', "$p/abwesenheiten"), 'Arten')['arten'], 'id', 'name');
        $this->status(201, $this->http('POST', "$p/abwesenheiten", ['person' => $pm, 'art' => $arten['Urlaub'], 'von' => $d(7), 'bis' => $d(7)]), 'Urlaub am folgenden Montag');
        $this->gleich([480, 480, 0, 1.0], [($t = $tag(7))['soll'], $t['gutschrift'], $t['saldo'], (float) $t['abwesenheit']], 'Urlaub: das Soll ist gutgeschrieben');
        $neujahr = array_column($this->status(200, $this->http('GET', "$z/personen/$pm?monat=$j-01"), 'Januar')['tage'], null, 'tag')["$j-01-01"];
        $this->gleich([0, 'Neujahr', false], [$neujahr['soll'], $neujahr['feiertag'], $neujahr['arbeitstag']], 'Feiertag aus dem Personal: kein Soll');

        // Regeln des Mandanten: anpassen und die Vorgabe wieder einlesen
        $this->status(400, $this->http('PUT', "$z/einstellung", ['pausen' => 'keine']), 'Pausen sind eine Liste');
        $this->status(204, $this->http('PUT', "$z/einstellung", ['pausen' => [['ab' => 360, 'pause' => 45]], 'hoechst_minuten' => 540]), 'eigene Regeln');
        $this->gleich([465, 495], [$tag(0)['gezaehlt'], $tag(1)['gezaehlt']], 'eigene Pausenregel wirkt: 45 Minuten schon über sechs Stunden');
        $this->gleich('2026-11-21', $this->status(200, $this->http('POST', "$z/einstellung/vorgabe", []), 'Vorgabe einlesen')['stand'], 'die Vorgabe des Releases');
        $this->gleich([480, 510], [$tag(0)['gezaehlt'], $tag(1)['gezaehlt']], 'wieder nach der Vorgabe');

        // Gleitzeit mit Rahmen: außerhalb zählt nicht; Kernzeit als Hinweis
        $this->status(204, $this->http('PUT', "$z/personen/$pm/profil", ['modell' => 'gleitzeit', 'rahmen_von' => '07:30', 'rahmen_bis' => '18:00', 'kern_von' => '09:00', 'kern_bis' => '15:00', 'anfangssaldo' => 60, 'beginn' => $d(0)]), 'Gleitzeit mit Rahmen und Kernzeit');
        $this->problem(422, 'KM002', $this->http('PUT', "$z/personen/$pm/profil", ['modell' => 'gleitzeit', 'rahmen_von' => '18:00', 'rahmen_bis' => '07:00']), 'der Rahmen endet nach seinem Beginn');
        $t = $tag(3);
        $this->gleich([630, 585], [$t['brutto'], $t['gezaehlt']], 'Donnerstag 7–19 Uhr im Rahmen 7:30–18 Uhr: nur 10:30 zählen, abzüglich Pause');
        $this->ist(in_array('außerhalb des Rahmens', $t['hinweise'], true) && in_array('Kernzeit nicht abgedeckt', $tag(2)['hinweise'], true), 'Hinweise: außerhalb des Rahmens; Kernzeit nicht abgedeckt');
        $this->status(204, $this->http('PUT', "$z/personen/$pm/profil", ['modell' => 'gleitzeit', 'anfangssaldo' => 60, 'beginn' => $d(0)]), 'Gleitzeit ohne Rahmen');

        // Mia: sieht nur das Eigene; Nachtrag als Antrag an den Vorgesetzten
        $this->anmelden($pkMia);
        $this->problem(403, 'recht.fehlt', $this->http('GET', "$z?monat=$monat"), 'Mitarbeiterin: keine Liste');
        $ich = $this->status(200, $this->http('GET', "$z/meine?monat=$monat"), 'Meine Zeiten');
        $this->gleich(['Mitarbeit, Mia', null, 'gleitzeit', 675], [$ich['name'], $ich['eingestempelt'], $ich['profil']['modell'], array_column($ich['tage'], null, 'tag')[$d(3)]['gezaehlt']], 'das eigene Monatsblatt');
        $r = $this->status(201, $this->http('POST', "$z/meine/antraege", ['art' => 'nachtrag', 'tag' => $d(4), 'von' => '08:00', 'bis' => '16:30', 'begruendung' => 'Stempeln vergessen']), 'Nachtrag beantragt');
        $this->gleich('offen', $r['zustand'], 'wartet auf den Vorgesetzten');
        $this->problem(409, 'KM003', $this->http('POST', "$z/meine/antraege", ['art' => 'nachtrag', 'tag' => $d(4), 'von' => '08:00', 'bis' => '16:30', 'begruendung' => 'nochmal']), 'nicht zweimal derselbe Antrag');
        $ich = $this->status(200, $this->http('GET', "$z/meine?monat=$monat"), 'Meine Zeiten');
        $this->gleich([0, 'offen', '08:00'], [array_column($ich['tage'], null, 'tag')[$d(4)]['gezaehlt'], ($x = array_column($ich['antraege'], null, 'id')[$r['id']])['zustand'], $x['von']], 'solange offen, zählt nichts');
        $this->problem(403, 'recht.fehlt', $this->http('POST', "$z/antraege/{$r['id']}/entscheidung", ['annehmen' => true]), 'über den eigenen Antrag entscheidet nicht die Person');
        // Stempeln
        $this->status(400, $this->http('POST', "$z/meine/stempel", ['art' => 'pause']), 'kommen oder gehen');
        $this->problem(409, 'KM003', $this->http('POST', "$z/meine/stempel", ['art' => 'gehen']), 'gehen ohne gekommen zu sein');
        $this->status(204, $this->http('POST', "$z/meine/stempel", ['art' => 'kommen']), 'Kommen');
        $this->ist($this->status(200, $this->http('GET', "$z/meine"), 'Meine Zeiten')['eingestempelt'] !== null, 'eingestempelt');
        $this->problem(409, 'KM003', $this->http('POST', "$z/meine/stempel", ['art' => 'kommen']), 'nicht zweimal kommen');
        $this->sql("UPDATE zeit.abschnitt SET von = (von - interval '2 hours')::time WHERE bis IS NULL AND von >= '02:00'");   // damit zwischen Kommen und Gehen Zeit liegt
        $this->status(204, $this->http('POST', "$z/meine/stempel", ['art' => 'gehen']), 'Gehen');
        $this->gleich(null, $this->status(200, $this->http('GET', "$z/meine"), 'Meine Zeiten')['eingestempelt'], 'ausgestempelt');

        // Viktor: sein Team, nicht sich selbst
        $this->anmelden($pkViktor);
        $st = $this->status(200, $this->http('GET', "$z?monat=$monat"), 'Zeiten des Teams');
        $this->gleich([['Mitarbeit, Mia'], 1, ['alle' => false, 'pflegen' => false, 'freigeben' => true]], [array_column($st['personen'], 'name'), $st['personen'][0]['antraege'], $st['darf']], 'der Vorgesetzte sieht sein Team mit dem offenen Antrag');
        $this->problem(404, 'nicht_gefunden', $this->http('GET', "$z/personen/$pv?monat=$monat"), 'das eigene Blatt steht unter „Meine Zeiten“, nicht in der Liste');
        $this->problem(404, 'nicht_gefunden', $this->http('PUT', "$z/personen/$pv/tage/{$d(0)}/freigabe", ['art' => 'ausserhalb', 'genehmigt' => true]), 'niemand gibt sich selbst frei');
        $this->problem(403, 'recht.fehlt', $this->http('PUT', "$z/personen/$pm/profil", ['modell' => 'fest']), 'Zeitprofile pflegt das Personal');
        $this->status(400, $this->http('POST', "$z/antraege/{$r['id']}/entscheidung", ['annehmen' => false]), 'eine Ablehnung braucht einen Grund');
        $this->status(204, $this->http('POST', "$z/antraege/{$r['id']}/entscheidung", ['annehmen' => true]), 'Antrag angenommen');
        $this->problem(409, 'KM003', $this->http('POST', "$z/antraege/{$r['id']}/entscheidung", ['annehmen' => true]), 'nur einmal');
        $b = $blatt();
        $x = array_column($b['antraege'], null, 'id')[$r['id']];
        $this->gleich([480, 'angenommen', null, null, 'Stempeln vergessen'], [$tag(4, $b)['gezaehlt'], $x['zustand'], $x['von'], $x['bis'], $x['begruendung']], 'ZE3: der Tag zählt; am Antrag bleibt die Begründung, nicht der Wert');

        // Korrektur: je Person sofort oder mit Freigabe; der alte Wert wird nicht aufbewahrt
        $abschnitt = $tag(4, $b)['abschnitte'][0]['id'];
        $this->anmelden($pkMia);
        $k = $this->status(201, $this->http('POST', "$z/meine/antraege", ['art' => 'korrektur', 'abschnitt' => $abschnitt, 'von' => '08:15', 'bis' => '16:30', 'begruendung' => 'später gekommen']), 'Korrektur beantragt');
        $this->gleich('offen', $k['zustand'], 'bei Mia braucht die Korrektur die Freigabe');
        $this->anmelden($pkPaula);
        $this->status(204, $this->http('POST', "$z/antraege/{$k['id']}/entscheidung", ['annehmen' => false, 'grund' => 'laut Plan pünktlich']), 'Korrektur abgelehnt');
        $this->status(204, $this->http('PUT', "$z/personen/$pm/profil", ['modell' => 'gleitzeit', 'korrektur' => 'sofort', 'anfangssaldo' => 60, 'beginn' => $d(0)]), 'Korrekturen gelten bei Mia künftig sofort');
        $this->anmelden($pkMia);
        $k = $this->status(201, $this->http('POST', "$z/meine/antraege", ['art' => 'korrektur', 'abschnitt' => $abschnitt, 'von' => '08:30', 'bis' => '16:30', 'begruendung' => 'Arzttermin am Morgen']), 'Korrektur');
        $this->gleich('angenommen', $k['zustand'], 'gilt sofort');
        $ich = $this->status(200, $this->http('GET', "$z/meine?monat=$monat"), 'Meine Zeiten');
        $a1 = array_column($ich['tage'], null, 'tag')[$d(4)]['abschnitte'][0];
        $this->gleich(['08:30', '16:30', true, 450], [$a1['von'], $a1['bis'], $a1['korrigiert'], array_column($ich['tage'], null, 'tag')[$d(4)]['gezaehlt']], 'der Abschnitt trägt den neuen Wert und das Kennzeichen „korrigiert“');
        $this->gleich(['0'], $this->sql("SELECT count(*) FROM zeit.antrag WHERE von IS NOT NULL OR bis IS NOT NULL"), 'entschiedene Anträge tragen keine Uhrzeit mehr');
        $e = $this->status(201, $this->http('POST', "$z/meine/antraege", ['art' => 'entfernen', 'abschnitt' => $tag(5, $ich)['abschnitte'][0]['id'], 'begruendung' => 'falscher Tag']), 'Abschnitt entfernen');
        $this->gleich(['angenommen', []], [$e['zustand'], array_column($this->status(200, $this->http('GET', "$z/meine?monat=$monat"), 'Meine Zeiten')['tage'], null, 'tag')[$d(5)]['abschnitte']], 'entfernt');

        // Monatsabschluss und Lohnvorbereitung: erst der bestätigte Monat liefert Ist-Stunden
        $this->anmelden($pkPaula);
        $lohn = fn () => array_column($this->status(200, $this->http('GET', "$p/lohn?monat=$monat"), 'Lohnvorbereitung')['personen'], null, 'person')[$pm]['inhalt'];
        $i = $lohn();
        $this->ist($i['ist_minuten'] === null && str_contains($i['ist_hinweis'], 'nicht bestätigt'), 'Lohnvorbereitung: ohne bestätigten Monat „Nachweis fehlt“ (PE11)');
        $this->anmelden($pkMia);
        $this->status(204, $this->http('POST', "$z/meine/monate/$monat/bestaetigung", []), 'Monat bestätigt');
        $this->problem(409, 'KM003', $this->http('POST', "$z/meine/monate/$monat/bestaetigung", []), 'nur einmal');
        $this->problem(409, 'KM003', $this->http('POST', "$z/meine/antraege", ['art' => 'nachtrag', 'tag' => $d(8), 'von' => '08:00', 'bis' => '12:00', 'begruendung' => 'zu spät']), 'ein abgeschlossener Monat nimmt nichts mehr an');
        $this->problem(422, 'KM002', $this->http('POST', "$z/meine/monate/" . date('Y-m') . '/bestaetigung', []), 'der laufende Monat lässt sich noch nicht bestätigen');
        $this->anmelden($pkPaula);
        $b = $blatt();
        $i = $lohn();
        $this->gleich([$b['summe']['gezaehlt'], 0, 'bestaetigt'], [$i['ist_minuten'], $i['zeitarten']['sonntag'], $b['monatsstand']['zustand']], 'die Lohnvorbereitung bekommt die Ist-Stunden des bestätigten Monats');
        $this->gleich($b['summe']['gezaehlt'] + $b['summe']['gutschrift'] - $b['summe']['soll'], $b['summe']['saldo'], 'die Summen des Monats gehen auf: was zählt und Gutschrift weniger Soll ist der Saldo');
        $this->status(204, $this->http('PUT', "$z/einstellung", ['pausen' => [['ab' => 360, 'pause' => 30], ['ab' => 540, 'pause' => 45]], 'monatsabschluss' => 'freigabe']), 'strenger: der Vorgesetzte gibt den Monat frei');
        $this->ist(str_contains($lohn()['ist_hinweis'], 'nicht freigegeben'), 'jetzt reicht die Bestätigung nicht mehr');
        $this->anmelden($pkViktor);
        $this->status(204, $this->http('POST', "$z/personen/$pm/monate/$monat/freigabe", []), 'Monat freigegeben');
        $this->problem(409, 'KM003', $this->http('POST', "$z/personen/$pm/monate/$monat/freigabe", []), 'nur einmal');
        $this->anmelden($pkPaula);
        $this->gleich([$b['summe']['gezaehlt'], 'freigegeben'], [$lohn()['ist_minuten'], $blatt()['monatsstand']['zustand']], 'freigegeben: die Ist-Stunden stehen in der Lohnvorbereitung');
        $this->status(204, $this->http('POST', "$z/personen/$pm/monate/$monat/oeffnung", []), 'Monat wieder geöffnet');
        $this->status(204, $this->http('PUT', "$z/einstellung", ['pausen' => [['ab' => 360, 'pause' => 30], ['ab' => 540, 'pause' => 45]], 'monatsabschluss' => 'aus']), 'lax: kein Monatsabschluss');
        $this->gleich([$b['summe']['gezaehlt'], 'ohne Monatsabschluss'], [($i = $lohn())['ist_minuten'], $i['ist_hinweis']], 'ohne Abschluss zählt der Stand von jetzt – mit Vermerk');
        $this->anmelden($pkMia);
        $this->problem(409, 'KM003', $this->http('POST', "$z/meine/monate/$monat/bestaetigung", []), 'ohne Monatsabschluss gibt es nichts zu bestätigen');

        // Lohn sieht alle Zeiten, entscheidet nichts
        $this->anmelden($pkLars);
        $this->gleich(2, count($this->status(200, $this->http('GET', "$z?monat=$monat"), 'Lohn sieht die Zeiten')['personen']), 'Lohn sieht alle');
        $this->problem(403, 'recht.fehlt', $this->http('POST', "$z/personen/$pm/monate/$monat/freigabe", []), 'Lohn gibt nichts frei');
        $this->problem(403, 'recht.fehlt', $this->http('GET', "$z/meine"), 'Lohn stempelt nicht');

        // Jahreskalender im Personal (ZE8): Feiertage, freie Tage, Brückentage
        $this->anmelden($pkMia);
        $kal = $this->status(200, $this->http('GET', "$p/meine"), 'Meine Daten')['kalender'];
        $this->gleich([(int) date('Y'), (int) date('Y') + 1], array_column($kal, 'jahr'), 'laufendes und nächstes Jahr');
        $arten = array_count_values(array_column($kal[0]['tage'], 'art'));
        $this->ist(($arten['feiertag'] ?? 0) >= 9, 'mindestens die neun bundeseinheitlichen Feiertage');
        $bruecken = array_filter($kal[0]['tage'], fn (array $t) => $t['art'] === 'bruecke');
        $this->ist($bruecken !== [] && array_filter($bruecken, fn (array $t) => !in_array((int) date('N', strtotime($t['datum'])), [1, 5], true)) === [], 'Brückentage sind Montage und Freitage (nach Christi Himmelfahrt gibt es immer einen)');

        // Protokoll ohne Uhrzeiten; Tabellen nur über Funktionen
        $alles = implode("\n", $this->sql("SELECT typ || coalesce(vorher::text, '') || coalesce(nachher::text, '') || ids::text FROM mandant.protokoll WHERE typ LIKE 'zeit.%' AND typ <> 'zeit.einstellung_gesetzt'"));
        foreach (['08:00', '16:30', 'Stempeln vergessen', 'Arzttermin', 'Notdienst'] as $wert) {
            $this->ist(!str_contains($alles, $wert), "Protokoll enthält „{$wert}“ nicht");
        }
        foreach (['zeit.gestempelt', 'zeit.berichtigt', 'zeit.antrag_gestellt', 'zeit.antrag_angenommen', 'zeit.antrag_abgelehnt', 'zeit.tag_genehmigt', 'zeit.tag_abgelehnt', 'zeit.monat_bestaetigt', 'zeit.monat_freigegeben', 'zeit.monat_geoeffnet', 'zeit.profil_gesetzt'] as $t) {
            $this->ist(str_contains($alles, $t), "Protokoll kennt $t");
        }
        $this->gleich([], $this->sql('SELECT nr, fehler FROM mandant.kette_pruefen()'), 'die Kette des Mandanten stimmt');
        $this->wirft(\RuntimeException::class, fn () => $this->sql("SET ROLE kumero_lesen; SELECT count(*) FROM zeit.abschnitt"), 'das Lesekonto liest keine Zeiten');
        $this->wirft(\RuntimeException::class, fn () => $this->sql("SET ROLE kumero_app; UPDATE zeit.abschnitt SET von = '00:00'"), 'Tabellen sind nur über Funktionen schreibbar');

        // Zeiterfassung ausgeschaltet: die Lohnvorbereitung zeigt wieder „Nachweis fehlt“
        $this->u->cli(['command' => 'modul:ausschalten', 'modul' => 'zeit', 'mandant' => 'zeitpraxis']);
        $this->anmelden($pkPaula);
        $this->ist(($i = $lohn())['ist_minuten'] === null && str_contains($i['ist_hinweis'], 'Zeiterfassung'), 'ohne aktive Zeiterfassung: „Nachweis fehlt“');
        $this->wirft(\RuntimeException::class, fn () => $this->u->cli(['command' => 'modul:ausschalten', 'modul' => 'personal', 'mandant' => 'nichtda']), 'unbekannter Mandant');
    }
}
