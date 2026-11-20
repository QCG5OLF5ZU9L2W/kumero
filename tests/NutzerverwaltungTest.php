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
 * Nutzer und Rollen in der Weboberfläche (0.7.29, VERWALTUNG.md Abschnitt 6 und 15, F125): Nutzer anlegen, Zugang mit
 * Link **und** Code, Rollen zuweisen und beenden, sperren, Passkey widerrufen; der letzte Administrator und der letzte
 * Verantwortliche bleiben. Nur synthetische Daten.
 */
final class NutzerverwaltungTest extends Rahmen
{
    use Zugaenge;

    private const ORIGIN = Umgebung::ORIGIN;
    private const MANDANT = 'kumero_nutzerpraxis';
    private const P = '/m/nutzerpraxis/api/v1';

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

    /** @return list<string> Ergebniszeilen einer Abfrage im Mandanten, ohne Kopf */
    private function sql(string $abfrage): array
    {
        $aus = Umgebung::sh(sprintf('psql -X -q -At -v ON_ERROR_STOP=1 -h %s -U %s -d %s -c %s', escapeshellarg((string) getenv('KUMERO_TEST_PGHOST')),
            escapeshellarg((string) getenv('KUMERO_TEST_PGUSER')), self::MANDANT, escapeshellarg($abfrage)));
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

    public function testNutzerUndRollen(): void
    {
        $p = self::P;
        $adamPk = $this->passkeyEinrichten($this->mandantAnlegen('nutzerpraxis', 'Nutzerpraxis'));
        [$vera, $pkVera] = $this->nutzer('nutzerpraxis', 'Vera', 'Verantwortlich');

        // Rechte: Die Verantwortliche bucht, verwaltet aber keine Nutzer; der Administrator sieht keine Buchungen
        $this->anmelden($pkVera);
        $this->status(403, $this->http('GET', "$p/nutzer"), 'Verantwortliche sieht die Nutzerverwaltung nicht');
        $this->ist(!in_array('kern.nutzer', array_column(array_merge(...array_column($this->status(200, $this->http('GET', "$p/oberflaeche/menue"), 'Menü')['bereiche'], 'eintraege')), 'id'), true),
            'und hat den Menüpunkt nicht');
        $ich = $this->anmelden($adamPk);
        $menue = $this->status(200, $this->http('GET', "$p/oberflaeche/menue"), 'Menü des Administrators');
        $ein = array_values(array_filter($menue['bereiche'], fn ($b) => $b['id'] === 'einstellungen'))[0] ?? ['eintraege' => []];
        $this->gleich(['Nutzer und Rollen', "#/m/nutzerpraxis/kern/nutzer"], [$ein['eintraege'][0]['titel'] ?? null, $ein['eintraege'][0]['pfad'] ?? null], 'Menüpunkt „Nutzer und Rollen“ unter Einstellungen');
        $this->ist(is_string($ein['eintraege'][0]['symbol'] ?? null), 'mit Symbol (F122)');
        $this->status(403, $this->http('GET', "$p/buchungen"), 'Administrator sieht keine Buchungen (F43)');
        $l = $this->status(200, $this->http('GET', "$p/nutzer"), 'Liste');
        $this->gleich(['Admin', 'Verantwortlich'], array_column($l['nutzer'], 'familienname'), 'beide Nutzer, nach Name');
        $adam = $l['nutzer'][0]['id'];
        $this->gleich([$adam, ['Administrator'], 1], [$l['ich'], array_column($l['nutzer'][0]['zuweisungen'], 'rolle'), count($l['nutzer'][0]['passkeys'])], 'eigene Identität, Rolle und Passkey');
        $this->ist(in_array('Buchhalter', array_column($l['rollen'], 'name'), true) && !str_contains(json_encode($l), 'pseudonym'), 'Rollen zur Auswahl; kein Pseudonym in der Liste');

        // anlegen
        $neu = ['vornamen' => 'Berta', 'familienname' => 'Buchhalter', 'email' => 'Berta@Example.org', 'rollen' => ['Buchhalter']];
        $this->status(400, $this->http('POST', "$p/nutzer", ['email' => 'keine-adresse'] + $neu), 'E-Mail-Adresse hat eine Form');
        $this->status(400, $this->http('POST', "$p/nutzer", ['rollen' => ['Gibtsnicht']] + $neu), 'unbekannte Rolle');
        $this->status(400, $this->http('POST', "$p/nutzer", ['familienname' => ' '] + $neu), 'Familienname ist Pflicht');
        $this->gleich(['2'], $this->sql('SELECT count(*) FROM kern.nutzer'), 'abgelehnt: nichts angelegt');
        $berta = $this->status(201, $this->http('POST', "$p/nutzer", $neu), 'Nutzer angelegt')['id'];
        $l = $this->status(200, $this->http('GET', "$p/nutzer"), 'Liste');
        $b = array_values(array_filter($l['nutzer'], fn ($n) => $n['id'] === $berta))[0];
        $this->gleich(['berta@example.org', true, ['Buchhalter'], [], null], [$b['email'], $b['aktiv'], array_column($b['zuweisungen'], 'rolle'), $b['passkeys'], $b['zugang_bis']],
            'angelegt mit Rolle, ohne Passkey und ohne Zugang');

        // Zugang: Link und Code einmal; gespeichert sind nur Hashes
        $this->status(400, $this->http('POST', "$p/nutzer/$berta/zugang", ['stunden' => 25]), 'höchstens 24 Stunden');
        $z = $this->status(201, $this->http('POST', "$p/nutzer/$berta/zugang", []), 'Zugang ausgestellt');
        $this->ist(preg_match('#^https://kumero\.test/app\.html\#registrierung=nutzerpraxis\.[A-Za-z0-9_-]{43}$#', $z['link']) === 1, 'Link nennt den Mandanten, Token im Fragment');
        $this->ist(preg_match('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{6}$/', $z['code']) === 1, 'Code: sechs Zeichen ohne verwechselbare');
        $token = self::linkToken($z['link']);
        $this->gleich(['0', '0', '1'], [
            $this->sql("SELECT count(*) FROM kern.idempotenz WHERE antwort_inhalt::text LIKE '%" . $z['code'] . "%' OR antwort_inhalt::text LIKE '%" . substr($token, -43) . "%'")[0],
            $this->sql("SELECT count(*) FROM kern.protokoll WHERE coalesce(nachher::text, '') LIKE '%" . $z['code'] . "%' OR coalesce(nachher::text, '') LIKE '%" . substr($token, -43) . "%'")[0],
            $this->sql("SELECT count(*) FROM kern.registrierung WHERE code_hash IS NOT NULL AND token_hash = '" . hash('sha256', $token) . "'")[0],
        ], 'weder Link noch Code im Idempotenzspeicher oder Protokoll; am Zugang nur Hashes');
        $this->gleich($z['gueltig_bis'], array_values(array_filter($this->status(200, $this->http('GET', "$p/nutzer"), 'Liste')['nutzer'], fn ($n) => $n['id'] === $berta))[0]['zugang_bis'], 'die Liste nennt den offenen Zugang');

        // einlösen: erst Link, dann Code
        $this->cookie = null;
        $pr = $this->status(200, $this->http('POST', '/api/v1/registrierung/pruefen', ['token' => $token]), 'Link geprüft');
        $this->gleich([true, 'Nutzerpraxis'], [$pr['code_noetig'], $pr['mandant']['name']], 'zum Link gehört ein Code');
        $this->ist(!str_contains(json_encode($pr), 'Berta'), 'der Link allein verrät die Person nicht');
        $this->problem(403, 'registrierung.code_fehlt', $this->http('POST', '/api/v1/registrierung/optionen', ['token' => $token]), 'ohne Code keine Optionen');
        $this->gleich(['0'], $this->sql('SELECT fehlversuche FROM kern.registrierung WHERE verwendet_am IS NULL AND ungueltig_am IS NULL'), 'ein fehlender Code ist kein Fehlversuch');
        $this->problem(403, 'registrierung.code_falsch', $this->http('POST', '/api/v1/registrierung/optionen', ['token' => $token, 'code' => self::falscherCode($z['code'])]), 'falscher Code');
        $this->gleich(['1'], $this->sql('SELECT fehlversuche FROM kern.registrierung WHERE verwendet_am IS NULL AND ungueltig_am IS NULL'), 'und gezählt');
        $pkBerta = $this->einrichten($z['link'], strtolower(substr($z['code'], 0, 3)) . ' ' . substr($z['code'], 3));
        $this->gleich('Berta', $this->anmelden($pkBerta)['vornamen'] ?? null, 'Berta meldet sich mit ihrem Passkey an (Code klein und mit Leerzeichen getippt)');
        $this->status(200, $this->http('GET', "$p/konten"), 'und arbeitet als Buchhalterin');
        $this->einrichten($z['link'], $z['code'], 403);

        // neuer Zugang macht den alten ungültig; fünf falsche Codes machen ihn ungültig
        $this->anmelden($adamPk);
        $z1 = $this->status(201, $this->http('POST', "$p/nutzer/$berta/zugang", ['stunden' => 1]), 'Zugang 1');
        $z2 = $this->status(201, $this->http('POST', "$p/nutzer/$berta/zugang", ['stunden' => 1]), 'Zugang 2');
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
        $z3 = $this->status(201, $this->http('POST', "$p/nutzer/$berta/zugang", ['passkeys_widerrufen' => true]), 'Zugang mit Widerruf');
        $this->gleich([1, ['0']], [$z3['passkeys_widerrufen'], $this->sql("SELECT count(*) FROM kern.anmelde_credential WHERE nutzer = '$berta' AND widerrufen_am IS NULL")], 'alter Passkey widerrufen');
        $pkBerta = $this->einrichten($z3['link'], $z3['code']);

        // Rollen zuweisen, befristen, beenden
        $this->anmelden($adamPk);
        $morgen = date('Y-m-d', time() + 86400);
        $this->status(409, $this->http('POST', "$p/zuweisungen", ['nutzer' => $berta, 'rolle' => 'Buchhalter']), 'Rolle nicht doppelt');
        $this->status(400, $this->http('POST', "$p/zuweisungen", ['nutzer' => $berta, 'rolle' => 'Leser', 'bis' => '2020-01-01']), 'Ende in der Vergangenheit');
        $this->status(400, $this->http('POST', "$p/zuweisungen", ['nutzer' => '00000000-0000-4000-8000-000000000000', 'rolle' => 'Leser']), 'unbekannter Nutzer');
        $zu = $this->status(201, $this->http('POST', "$p/zuweisungen", ['nutzer' => $berta, 'rolle' => 'Leser', 'bis' => $morgen]), 'befristet zugewiesen')['id'];
        $b = array_values(array_filter($this->status(200, $this->http('GET', "$p/nutzer"), 'Liste')['nutzer'], fn ($n) => $n['id'] === $berta))[0];
        $leser = array_values(array_filter($b['zuweisungen'], fn ($x) => $x['rolle'] === 'Leser'))[0];
        $this->ist($leser['gueltig_bis'] !== null && strtotime($leser['gueltig_bis']) > time() + 80000, 'die Rolle gilt bis zum Ende des genannten Tages');
        $this->status(204, $this->http('DELETE', "$p/zuweisungen/$zu"), 'Zuweisung beendet');
        $this->status(409, $this->http('DELETE', "$p/zuweisungen/$zu"), 'nur einmal');
        $this->status(404, $this->http('DELETE', "$p/zuweisungen/00000000-0000-4000-8000-000000000000"), 'unbekannte Zuweisung');

        // ändern und sperren
        $this->status(204, $this->http('PATCH', "$p/nutzer/$berta", ['familienname' => 'Buchmann']), 'Name geändert');
        $this->status(204, $this->http('PATCH', "$p/nutzer/$berta", ['aktiv' => false]), 'gesperrt');
        $b = array_values(array_filter($this->status(200, $this->http('GET', "$p/nutzer"), 'Liste')['nutzer'], fn ($n) => $n['id'] === $berta))[0];
        $this->gleich(['Buchmann', 'Berta', false], [$b['familienname'], $b['vornamen'], $b['aktiv']], 'geändert; was nicht genannt wurde, bleibt');
        $this->status(409, $this->http('POST', "$p/nutzer/$berta/zugang", []), 'kein Zugang für Gesperrte');
        $this->status(404, $this->http('PATCH', "$p/nutzer/00000000-0000-4000-8000-000000000000", ['aktiv' => false]), 'unbekannter Nutzer');
        $this->status(204, $this->http('PATCH', "$p/nutzer/$berta", ['aktiv' => true]), 'entsperrt');

        // Passkey widerrufen
        $cred = array_values(array_filter($this->status(200, $this->http('GET', "$p/nutzer"), 'Liste')['nutzer'], fn ($n) => $n['id'] === $berta))[0]['passkeys'][0]['id'];
        $this->status(404, $this->http('DELETE', "$p/nutzer/$vera/passkeys/$cred"), 'Passkey gehört zu Berta, nicht zu Vera');
        $this->status(204, $this->http('DELETE', "$p/nutzer/$berta/passkeys/$cred"), 'Passkey widerrufen');
        $this->status(409, $this->http('DELETE', "$p/nutzer/$berta/passkeys/$cred"), 'nur einmal');

        // wer nicht entfernt werden kann (VERWALTUNG.md Abschnitt 4)
        $this->problem(409, 'KM003', $this->http('PATCH', "$p/nutzer/$adam", ['aktiv' => false]), 'der letzte Administrator wird nicht gesperrt');
        $this->problem(409, 'KM003', $this->http('PATCH', "$p/nutzer/$vera", ['aktiv' => false]), 'die letzte Verantwortliche wird nicht gesperrt');
        $l = $this->status(200, $this->http('GET', "$p/nutzer"), 'Liste');
        $zuAdam = array_values(array_filter($l['nutzer'], fn ($n) => $n['id'] === $adam))[0]['zuweisungen'][0]['id'];
        $zuVera = array_values(array_filter($l['nutzer'], fn ($n) => $n['id'] === $vera))[0]['zuweisungen'][0]['id'];
        $this->problem(409, 'KM003', $this->http('DELETE', "$p/zuweisungen/$zuAdam"), 'die letzte Zuweisung „Administrator“ wird nicht beendet');
        $this->problem(409, 'KM003', $this->http('DELETE', "$p/zuweisungen/$zuVera"), 'die letzte Zuweisung „Verantwortlicher“ auch nicht');
        $this->status(201, $this->http('POST', "$p/zuweisungen", ['nutzer' => $berta, 'rolle' => 'Administrator']), 'zweite Administratorin');
        $this->status(204, $this->http('DELETE', "$p/zuweisungen/$zuAdam"), 'jetzt lässt sich die erste Zuweisung beenden');
        $this->status(403, $this->http('GET', "$p/nutzer"), 'und Adam verwaltet keine Nutzer mehr');

        // Protokoll: jede Handlung, mit dem Administrator als Subjekt
        $typen = $this->sql("SELECT typ FROM kern.protokoll WHERE subjekt = 'nutzer:$adam' GROUP BY typ ORDER BY typ");
        $this->gleich(['nutzer.angelegt', 'nutzer.geaendert', 'nutzer.passkey_widerrufen', 'nutzer.zugang_ausgestellt', 'zuweisung.geaendert'], $typen, 'Ereignisse des Administrators im Protokoll');
    }
}
