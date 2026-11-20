<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

use Kumero\Plattform\Datenbank;
use Kumero\Plattform\Identitaet;
use Kumero\Plattform\Kernel;
use Kumero\Plattform\Konfiguration;
use Kumero\Plattform\Webauthn\Base64Url;
use Kumero\Tests\Hilfe\Authentifikator;
use Kumero\Tests\Hilfe\Umgebung;
use Kumero\Tests\Hilfe\Zugaenge;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nutzer, Administratoren, Bücher und Rollen (VERWALTUNG.md Abschnitt 6 und 15, BUECHER.md; F125, F126): Nutzer anlegen,
 * Zugang mit Link **und** Code, sperren, Passkey widerrufen auf der Ebene Mandant; Rollen je Buch; Bücher anlegen über
 * den Auftrag an den Dienst; der letzte Administrator (Mandant) und der letzte Verantwortliche (Buch) bleiben.
 * Nur synthetische Daten.
 */
final class NutzerverwaltungTest extends Rahmen
{
    use Zugaenge;

    private const ORIGIN = Umgebung::ORIGIN;
    private const MANDANT = 'kumero_nutzerpraxis';
    private const P = '/m/nutzerpraxis/b/haupt/api/v1';   // Ebene Buch
    private const M = '/m/nutzerpraxis/api/v1';           // Ebene Mandant
    private const BUCH = self::MANDANT . '__haupt';

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
    private function http(string $methode, string $pfad, ?array $json = null, array $kopf = []): array
    {
        $k = Konfiguration::laden();
        $server = ['HTTPS' => 'on', 'HTTP_HOST' => 'kumero.test', 'HTTP_ORIGIN' => self::ORIGIN];
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        if ($methode !== 'GET' && str_starts_with($pfad, '/m/')) {
            $server['HTTP_IDEMPOTENCY_KEY'] = 'test-' . (++$this->schluessel) . '-' . bin2hex(random_bytes(4));
        }
        foreach ($kopf as $n => $w) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $n))] = $w;
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
        $this->gleich([$status, $code], [$erg[0]->getStatusCode(), $erg[1]['code'] ?? ($erg[1]['fehlercode'] ?? null)], $was . ' – ' . substr((string) $erg[0]->getContent(), 0, 300));
    }

    /** @return list<string> Ergebniszeilen einer Abfrage, ohne Kopf – in der Mandanten-Datenbank oder in der eines Buchs */
    private function sql(string $abfrage, string $datenbank = self::MANDANT): array
    {
        $aus = Umgebung::sh(sprintf('psql -X -q -At -v ON_ERROR_STOP=1 -h %s -U %s -d %s -c %s', escapeshellarg((string) getenv('KUMERO_TEST_PGHOST')),
            escapeshellarg((string) getenv('KUMERO_TEST_PGUSER')), $datenbank, escapeshellarg($abfrage)));
        return $aus === '' ? [] : explode("\n", $aus);
    }

    /** Richtet mit Link und Code einen Passkey ein, wie die Seite „Passkey registrieren“. */
    private function einrichten(string $link, ?string $code, int $erwartet = 201): ?Authentifikator
    {
        $token = self::linkToken($link);
        $pk = new Authentifikator('kumero.test', Umgebung::ORIGIN, -7);
        $this->cookie = null;
        $mit = $code === null ? [] : ['code' => $code];
        $erg = $this->http('POST', '/api/v1/registrierung/optionen', ['token' => $token] + $mit);
        if ($erg[0]->getStatusCode() !== 200) {
            $this->gleich($erwartet, $erg[0]->getStatusCode(), 'Optionen abgelehnt wie erwartet – ' . substr((string) $erg[0]->getContent(), 0, 200));
            return null;
        }
        $pk->nutzerkennung = $erg[1]['user']['id'];
        $this->status($erwartet, $this->http('POST', '/api/v1/registrierung', ['token' => $token] + $mit + $pk->registrieren(Base64Url::dekodieren($erg[1]['challenge']))), 'Passkey registriert');
        return $pk;
    }

    private static function falscherCode(string $code): string
    {
        return ($code[0] === 'A' ? 'B' : 'A') . substr($code, 1);
    }

    private function einer(array $liste, string $id): array
    {
        return array_values(array_filter($liste, fn ($n) => ($n['id'] ?? $n['kennung'] ?? null) === $id))[0] ?? [];
    }

    public function testNutzerUndRollen(): void
    {
        $p = self::P;
        $m = self::M;
        $adamPk = $this->passkeyEinrichten($this->mandantAnlegen('nutzerpraxis', 'Nutzerpraxis'));
        [$vera, $pkVera] = $this->nutzer('nutzerpraxis', 'Vera', 'Verantwortlich');

        // Rechte: Die Verantwortliche bucht, verwaltet aber keine Nutzer; der Administrator sieht keine Buchungen
        $this->anmelden($pkVera);
        $this->status(403, $this->http('GET', "$m/nutzer"), 'Verantwortliche sieht die Nutzerverwaltung nicht');
        $eintraege = fn (array $menue) => array_column(array_merge(...array_column($menue['bereiche'], 'eintraege')), 'pfad', 'id');
        $this->ist(!isset($eintraege($this->status(200, $this->http('GET', "$p/oberflaeche/menue"), 'Menü'))['kern.nutzer']), 'und hat den Menüpunkt nicht');
        $this->anmelden($adamPk);
        $e = $eintraege($this->status(200, $this->http('GET', "$m/oberflaeche/menue"), 'Menü des Administrators, ohne Buch'));
        $this->gleich(['#/m/nutzerpraxis/kern/buecher', '#/m/nutzerpraxis/kern/nutzer'], [$e['kern.buecher'] ?? null, $e['kern.nutzer'] ?? null], 'Bereich „Mandant“: Bücher, Nutzer und Rollen');
        $this->problem(403, 'recht.fehlt', $this->http('GET', "$p/buchungen"), 'Administrator sieht keine Buchungen (F43)');
        $this->status(200, $this->http('GET', "$p/zuweisungen"), 'aber die Zuweisungen jedes Buchs (A10)');
        $l = $this->status(200, $this->http('GET', "$m/nutzer"), 'Liste');
        $this->gleich(['Admin', 'Verantwortlich'], array_column($l['nutzer'], 'familienname'), 'beide Nutzer, nach Name');
        $adam = $l['nutzer'][0]['id'];
        $this->gleich([$adam, true, false, 1], [$l['ich'], $l['nutzer'][0]['administrator'], $l['nutzer'][1]['administrator'], count($l['nutzer'][0]['passkeys'])], 'eigene Identität, Administrator, Passkey');
        $this->ist(!str_contains(json_encode($l), 'pseudonym'), 'kein Pseudonym in der Liste');

        // anlegen (Ebene Mandant) – Rollen weist das Buch zu
        $neu = ['vornamen' => 'Berta', 'familienname' => 'Buchhalter', 'email' => 'Berta@Example.org'];
        $this->status(400, $this->http('POST', "$m/nutzer", ['email' => 'keine-adresse'] + $neu), 'E-Mail-Adresse hat eine Form');
        $this->status(400, $this->http('POST', "$m/nutzer", $neu + ['rollen' => ['Buchhalter']]), 'Rollen gehören zum Buch, nicht zum Anlegen');
        $this->status(400, $this->http('POST', "$m/nutzer", ['familienname' => ' '] + $neu), 'Familienname ist Pflicht');
        $this->gleich(['2'], $this->sql('SELECT count(*) FROM mandant.nutzer'), 'abgelehnt: nichts angelegt');
        $berta = $this->status(201, $this->http('POST', "$m/nutzer", $neu), 'Nutzer angelegt')['id'];
        $b = $this->einer($this->status(200, $this->http('GET', "$m/nutzer"), 'Liste')['nutzer'], $berta);
        $this->gleich(['berta@example.org', true, false, [], null], [$b['email'], $b['aktiv'], $b['administrator'], $b['passkeys'], $b['zugang_bis']], 'angelegt, ohne Passkey und ohne Zugang');
        $this->gleich(['0'], $this->sql("SELECT count(*) FROM kern.protokoll WHERE typ LIKE 'nutzer.%'", self::BUCH), 'im Buch steht nichts über Nutzer');

        // Rollen je Buch (B3)
        $z = $this->status(200, $this->http('GET', "$p/zuweisungen"), 'Zuweisungen im Buch');
        $this->gleich([[$vera], ['Verantwortlicher']], [array_column($z['zuweisungen'], 'nutzer'), array_column($z['zuweisungen'], 'rolle')], 'im Buch: Vera als Verantwortliche');
        $this->ist(in_array('Buchhalter', array_column($z['rollen'], 'name'), true) && !in_array('Administrator', array_column($z['rollen'], 'name'), true), 'Rollen des Buchs zur Auswahl – ohne Administrator');
        $this->status(400, $this->http('POST', "$p/zuweisungen", ['nutzer' => $berta, 'rolle' => 'Administrator']), 'Administratoren setzt der Mandant ein, nicht das Buch');
        $this->status(201, $this->http('POST', "$p/zuweisungen", ['nutzer' => $berta, 'rolle' => 'Buchhalter']), 'Rolle zugewiesen');
        $this->status(409, $this->http('POST', "$p/zuweisungen", ['nutzer' => $berta, 'rolle' => 'Buchhalter']), 'Rolle nicht doppelt');
        $this->status(400, $this->http('POST', "$p/zuweisungen", ['nutzer' => $berta, 'rolle' => 'Leser', 'bis' => '2020-01-01']), 'Ende in der Vergangenheit');
        $this->status(400, $this->http('POST', "$p/zuweisungen", ['nutzer' => '00000000-0000-4000-8000-000000000000', 'rolle' => 'Leser']), 'unbekannter Nutzer');
        $zu = $this->status(201, $this->http('POST', "$p/zuweisungen", ['nutzer' => $berta, 'rolle' => 'Leser', 'bis' => date('Y-m-d', time() + 86400)]), 'befristet zugewiesen')['id'];
        $leser = $this->einer($this->status(200, $this->http('GET', "$p/zuweisungen"), 'Zuweisungen')['zuweisungen'], $zu);
        $this->ist($leser['gueltig_bis'] !== null && strtotime($leser['gueltig_bis']) > time() + 80000, 'die Rolle gilt bis zum Ende des genannten Tages');
        $this->status(204, $this->http('DELETE', "$p/zuweisungen/$zu"), 'Zuweisung beendet');
        $this->status(409, $this->http('DELETE', "$p/zuweisungen/$zu"), 'nur einmal');
        $this->status(404, $this->http('DELETE', "$p/zuweisungen/00000000-0000-4000-8000-000000000000"), 'unbekannte Zuweisung');

        // Zugang: Link und Code einmal; gespeichert sind nur Hashes
        $this->status(400, $this->http('POST', "$m/nutzer/$berta/zugang", ['stunden' => 25]), 'höchstens 24 Stunden');
        $z = $this->status(201, $this->http('POST', "$m/nutzer/$berta/zugang", []), 'Zugang ausgestellt');
        $this->ist(preg_match('#^https://kumero\.test/app\.html\#registrierung=nutzerpraxis\.[A-Za-z0-9_-]{43}$#', $z['link']) === 1, 'Link nennt den Mandanten, Token im Fragment');
        $this->ist(preg_match('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{6}$/', $z['code']) === 1, 'Code: sechs Zeichen ohne verwechselbare');
        $token = self::linkToken($z['link']);
        $this->gleich(['0', '0', '1'], [
            $this->sql("SELECT count(*) FROM mandant.idempotenz WHERE antwort_inhalt::text LIKE '%" . $z['code'] . "%' OR antwort_inhalt::text LIKE '%" . substr($token, -43) . "%'")[0],
            $this->sql("SELECT count(*) FROM mandant.protokoll WHERE coalesce(nachher::text, '') LIKE '%" . $z['code'] . "%' OR coalesce(nachher::text, '') LIKE '%" . substr($token, -43) . "%'")[0],
            $this->sql("SELECT count(*) FROM mandant.registrierung WHERE code_hash IS NOT NULL AND token_hash = '" . hash('sha256', $token) . "'")[0],
        ], 'weder Link noch Code im Idempotenzspeicher oder Protokoll; am Zugang nur Hashes');
        $this->gleich($z['gueltig_bis'], $this->einer($this->status(200, $this->http('GET', "$m/nutzer"), 'Liste')['nutzer'], $berta)['zugang_bis'], 'die Liste nennt den offenen Zugang');

        // einlösen: erst Link, dann Code
        $this->cookie = null;
        $pr = $this->status(200, $this->http('POST', '/api/v1/registrierung/pruefen', ['token' => $token]), 'Link geprüft');
        $this->gleich([true, 'Nutzerpraxis'], [$pr['code_noetig'], $pr['mandant']['name']], 'zum Link gehört ein Code');
        $this->ist(!str_contains(json_encode($pr), 'Berta'), 'der Link allein verrät die Person nicht');
        $this->problem(403, 'registrierung.code_fehlt', $this->http('POST', '/api/v1/registrierung/optionen', ['token' => $token]), 'ohne Code keine Optionen');
        $this->gleich(['0'], $this->sql('SELECT fehlversuche FROM mandant.registrierung WHERE verwendet_am IS NULL AND ungueltig_am IS NULL'), 'ein fehlender Code ist kein Fehlversuch');
        $this->problem(403, 'registrierung.code_falsch', $this->http('POST', '/api/v1/registrierung/optionen', ['token' => $token, 'code' => self::falscherCode($z['code'])]), 'falscher Code');
        $this->gleich(['1'], $this->sql('SELECT fehlversuche FROM mandant.registrierung WHERE verwendet_am IS NULL AND ungueltig_am IS NULL'), 'und gezählt');
        $pkBerta = $this->einrichten($z['link'], strtolower(substr($z['code'], 0, 3)) . ' ' . substr($z['code'], 3));
        $this->gleich('Berta', $this->anmelden($pkBerta)['vornamen'] ?? null, 'Berta meldet sich mit ihrem Passkey an (Code klein und mit Leerzeichen getippt)');
        $this->status(200, $this->http('GET', "$p/konten"), 'und arbeitet als Buchhalterin im Buch');
        $this->einrichten($z['link'], $z['code'], 403);

        // neuer Zugang macht den alten ungültig; fünf falsche Codes machen ihn ungültig
        $this->anmelden($adamPk);
        $z1 = $this->status(201, $this->http('POST', "$m/nutzer/$berta/zugang", ['stunden' => 1]), 'Zugang 1');
        $z2 = $this->status(201, $this->http('POST', "$m/nutzer/$berta/zugang", ['stunden' => 1]), 'Zugang 2');
        $this->cookie = null;
        $this->status(403, $this->http('POST', '/api/v1/registrierung/pruefen', ['token' => self::linkToken($z1['link'])]), 'der vorige Zugang ist ungültig');
        $t2 = self::linkToken($z2['link']);
        for ($i = 1; $i <= 4; $i++) {
            $this->problem(403, 'registrierung.code_falsch', $this->http('POST', '/api/v1/registrierung/optionen', ['token' => $t2, 'code' => self::falscherCode($z2['code'])]), "falscher Code $i");
        }
        $this->problem(403, 'registrierung.ungueltig', $this->http('POST', '/api/v1/registrierung/optionen', ['token' => $t2, 'code' => self::falscherCode($z2['code'])]), 'der fünfte falsche Code macht den Zugang ungültig');
        $this->problem(403, 'registrierung.ungueltig', $this->http('POST', '/api/v1/registrierung/optionen', ['token' => $t2, 'code' => $z2['code']]), 'danach hilft auch der richtige Code nicht mehr');

        // verlorener Passkey: Zugang mit Widerruf
        $this->anmelden($adamPk);
        $z3 = $this->status(201, $this->http('POST', "$m/nutzer/$berta/zugang", ['passkeys_widerrufen' => true]), 'Zugang mit Widerruf');
        $this->gleich([1, ['0']], [$z3['passkeys_widerrufen'], $this->sql("SELECT count(*) FROM mandant.anmelde_credential WHERE nutzer = '$berta' AND widerrufen_am IS NULL")], 'alter Passkey widerrufen');
        $pkBerta = $this->einrichten($z3['link'], $z3['code']);

        // ändern und sperren
        $this->anmelden($adamPk);
        $this->status(204, $this->http('PATCH', "$m/nutzer/$berta", ['familienname' => 'Buchmann']), 'Name geändert');
        $this->status(204, $this->http('PATCH', "$m/nutzer/$berta", ['aktiv' => false]), 'gesperrt');
        $b = $this->einer($this->status(200, $this->http('GET', "$m/nutzer"), 'Liste')['nutzer'], $berta);
        $this->gleich(['Buchmann', 'Berta', false], [$b['familienname'], $b['vornamen'], $b['aktiv']], 'geändert; was nicht genannt wurde, bleibt');
        $this->status(409, $this->http('POST', "$m/nutzer/$berta/zugang", []), 'kein Zugang für Gesperrte');
        $this->status(404, $this->http('PATCH', "$m/nutzer/00000000-0000-4000-8000-000000000000", ['aktiv' => false]), 'unbekannter Nutzer');
        $this->status(204, $this->http('PATCH', "$m/nutzer/$berta", ['aktiv' => true]), 'entsperrt');

        // Passkey widerrufen
        $cred = $this->einer($this->status(200, $this->http('GET', "$m/nutzer"), 'Liste')['nutzer'], $berta)['passkeys'][0]['id'];
        $this->status(404, $this->http('DELETE', "$m/nutzer/$vera/passkeys/$cred"), 'Passkey gehört zu Berta, nicht zu Vera');
        $this->status(204, $this->http('DELETE', "$m/nutzer/$berta/passkeys/$cred"), 'Passkey widerrufen');
        $this->status(409, $this->http('DELETE', "$m/nutzer/$berta/passkeys/$cred"), 'nur einmal');

        // wer nicht entfernt werden kann: der letzte Administrator (Mandant), die letzte Verantwortliche (Buch)
        $this->problem(409, 'KM003', $this->http('PATCH', "$m/nutzer/$adam", ['aktiv' => false]), 'der letzte Administrator wird nicht gesperrt');
        $this->problem(409, 'KM003', $this->http('DELETE', "$m/administratoren/$adam"), 'und nicht abberufen');
        $this->problem(409, 'KM003', $this->http('PATCH', "$m/nutzer/$vera", ['aktiv' => false]), 'die letzte Verantwortliche eines Buchs wird nicht gesperrt');
        $zuVera = array_values(array_filter($this->status(200, $this->http('GET', "$p/zuweisungen"), 'Zuweisungen')['zuweisungen'], fn ($x) => $x['nutzer'] === $vera))[0]['id'];
        $this->problem(409, 'KM003', $this->http('DELETE', "$p/zuweisungen/$zuVera"), 'ihre letzte Zuweisung „Verantwortlicher“ wird nicht beendet');

        // Bücher: Der Administrator legt ein zweites an (Auftrag an den Dienst); hinein darf nur, wer dort eine Rolle hat
        $bl = $this->status(200, $this->http('GET', "$m/buecher"), 'Bücher für den Administrator');
        $this->gleich([['haupt'], [false], true], [array_column($bl['buecher'], 'kennung'), array_column($bl['buecher'], 'zugang'), $bl['administrator']], 'der Administrator sieht jedes Buch, ohne Rolle darin');
        $this->status(400, $this->http('POST', "$m/buecher", ['kennung' => 'Zwei!', 'name' => 'Zweites', 'art' => 'bilanz']), 'Kennung hat eine Form');
        $this->status(400, $this->http('POST', "$m/buecher", ['kennung' => 'zwei', 'name' => 'Zweites', 'art' => 'kameral']), 'Art ist euer oder bilanz');
        $this->status(409, $this->http('POST', "$m/buecher", ['kennung' => 'haupt', 'name' => 'Nochmal', 'art' => 'euer']), 'Kennung gibt es schon');
        $auftrag = $this->status(202, $this->http('POST', "$m/buecher", ['kennung' => 'zwei', 'name' => 'DSB-Mandate', 'art' => 'bilanz', 'testbuch' => true]), 'Buch beauftragt');
        $this->gleich('buch_anlegen', $auftrag['art'], 'als Auftrag an den Dienst');
        $this->status(409, $this->http('POST', "$m/buecher", ['kennung' => 'drei', 'name' => 'Drittes', 'art' => 'euer']), 'je Mandant ein Auftrag zur Zeit');
        $this->gleich(['0'], $this->sql("SELECT count(*) FROM verwaltung.auftrag WHERE angelegt_von LIKE 'nutzer:%' OR angaben::text LIKE '%$adam%' AND zustand <> 'offen'", Umgebung::V), 'die ID des Administrators bleibt nicht in der Verwaltung');
        $this->ist(str_contains($this->u->cli(['command' => 'system:auftraege']), 'buch_anlegen nutzerpraxis: erledigt'), 'der Dienst legt das Buch an');
        $this->gleich(['0'], $this->sql("SELECT count(*) FROM verwaltung.auftrag WHERE angaben IS NOT NULL", Umgebung::V), 'die Angaben sind nach der Ausführung weg');
        $bl = $this->status(200, $this->http('GET', "$m/buecher"), 'Bücher');
        $zwei = $this->einer($bl['buecher'], 'zwei');
        $this->gleich(['DSB-Mandate', 'bilanz', true, 'test', false], [$zwei['name'], $zwei['art'], $zwei['testbuch'], $zwei['betriebsart'], $zwei['zugang']], 'zweites Buch: Art Bilanz, Testbuch');
        $this->gleich(["nutzer:$adam"], $this->sql("SELECT subjekt FROM mandant.protokoll WHERE typ = 'buch.angelegt' AND ids->>'buch' = 'zwei'"), 'wer das Buch wollte, steht im Protokoll des Mandanten');
        $p2 = '/m/nutzerpraxis/b/zwei/api/v1';
        $this->anmelden($pkVera);
        $this->gleich(['haupt'], array_column($this->status(200, $this->http('GET', "$m/buecher"), 'Bücher für Vera')['buecher'], 'kennung'), 'Vera sieht nur das Buch, in dem sie eine Rolle hat');
        $this->problem(404, 'buch.unbekannt', $this->http('GET', "$p2/konten"), 'ohne Rolle sieht ein Buch aus wie keines (A8)');
        $this->anmelden($adamPk);
        $this->status(201, $this->http('POST', "$p2/zuweisungen", ['nutzer' => $vera, 'rolle' => 'Leser']), 'Rolle im zweiten Buch');
        $this->status(204, $this->http('PATCH', "$m/buecher/zwei", ['name' => 'Datenschutz']), 'Buch umbenannt');
        $this->status(404, $this->http('PATCH', "$m/buecher/fehlt", ['name' => 'x']), 'unbekanntes Buch');
        $this->anmelden($pkVera);
        $bl = $this->status(200, $this->http('GET', "$m/buecher"), 'Bücher für Vera');
        $this->gleich([['haupt', 'zwei'], 'Datenschutz'], [array_column($bl['buecher'], 'kennung'), $this->einer($bl['buecher'], 'zwei')['name']], 'jetzt beide; Testbücher stehen hinten');
        $this->status(200, $this->http('GET', "$p2/konten"), 'Vera liest im zweiten Buch');
        $this->status(403, $this->http('POST', "$p2/konten", ['nr' => '1000', 'bezeichnung' => 'Kasse', 'kontoart' => 'aktiv']), 'aber nur lesend – ihre Rolle dort ist eine andere als im ersten');
        $this->status(201, $this->http('POST', "$p/konten", ['nr' => '1000', 'bezeichnung' => 'Kasse', 'kontoart' => 'aktiv']), 'im ersten Buch legt sie ein Konto an');
        $this->gleich([], $this->status(200, $this->http('GET', "$p2/konten"), 'Konten im zweiten Buch'), 'die Bücher sind getrennt: das Konto gibt es nur im ersten');

        // Administratoren einsetzen und abberufen
        $this->anmelden($adamPk);
        $this->status(201, $this->http('POST', "$m/administratoren", ['nutzer' => $berta]), 'zweite Administratorin');
        $this->status(409, $this->http('POST', "$m/administratoren", ['nutzer' => $berta]), 'nicht doppelt');
        $this->status(204, $this->http('DELETE', "$m/administratoren/$adam"), 'jetzt lässt sich der erste abberufen');
        $this->status(403, $this->http('GET', "$m/nutzer"), 'und Adam verwaltet keine Nutzer mehr');
        $this->problem(404, 'buch.unbekannt', $this->http('GET', "$p/zuweisungen"), 'ohne Amt und ohne Rolle sieht er auch kein Buch mehr');

        // Protokoll des Mandanten: jede Handlung, mit dem Administrator als Subjekt
        $typen = $this->sql("SELECT typ FROM mandant.protokoll WHERE subjekt = 'nutzer:$adam' GROUP BY typ ORDER BY typ");
        $this->gleich(['administrator.abberufen', 'administrator.eingesetzt', 'buch.angelegt', 'buch.umbenannt', 'nutzer.angelegt', 'nutzer.geaendert',
            'nutzer.passkey_widerrufen', 'nutzer.zugang_ausgestellt'], $typen, 'Ereignisse des Administrators im Protokoll des Mandanten');
        $this->gleich(['zuweisung.geaendert'], $this->sql("SELECT DISTINCT typ FROM kern.protokoll WHERE subjekt = 'nutzer:$adam'", self::BUCH), 'im Buch nur die Zuweisungen');
        $this->gleich(['t'], $this->sql('SELECT NOT EXISTS (SELECT 1 FROM mandant.kette_pruefen())'), 'Kette des Mandanten nachrechenbar');
    }
}
