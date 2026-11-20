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
 * Modul siegel (0.7.26, SIEGEL.md, F119, F120; seit 0.7.28 F124): Siegel anfordern (vorläufig), Siegelinhalt herunterladen,
 * außerhalb signieren, Signatur hochladen, festschreiben – erst die Bestätigung schreibt Siegel, Signatur und Protokoll. Die Schlüssel entstehen im Test mit gpg in einem Wegwerfverzeichnis – nichts davon ist echt,
 * nichts liegt im Repository.
 */
final class SiegelTest extends Rahmen
{
    use Zugaenge;

    private const ORIGIN = Umgebung::ORIGIN;
    private const MANDANT = 'kumero_siegelpraxis';
    private const P = '/m/siegelpraxis/api/v1';

    private Umgebung $u;
    private ?string $cookie = null;
    private int $schluessel = 0;
    private string $gpg;

    public function vorher(): void
    {
        $this->u = new Umgebung([self::MANDANT]);
        $this->u->aufbauen();
        $this->gpg = sys_get_temp_dir() . '/kumero-siegeltest-' . bin2hex(random_bytes(4));
        mkdir($this->gpg, 0700);
    }

    public function nachher(): void
    {
        foreach (glob($this->gpg . '/*', GLOB_ONLYDIR) ?: [] as $heim) {
            Umgebung::sh('gpgconf --homedir ' . escapeshellarg($heim) . ' --kill all 2>/dev/null || true');
        }
        Umgebung::sh('rm -rf ' . escapeshellarg($this->gpg));
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

    /** Erzeugt ein synthetisches Schlüsselpaar. @return array{heim: string, oeffentlich: string, geheim: string, fpr: string} */
    private function paar(string $name, string $kennung, string $verfahren = 'ed25519'): array
    {
        $heim = "{$this->gpg}/$name";
        mkdir($heim, 0700);
        $g = 'gpg --homedir ' . escapeshellarg($heim) . ' --batch --no-tty';
        Umgebung::sh("$g --passphrase '' --quick-generate-key " . escapeshellarg($kennung) . " $verfahren sign never 2>/dev/null");
        $fpr = trim(Umgebung::sh("$g --with-colons --list-keys 2>/dev/null | awk -F: '/^fpr/ {print \$10; exit}'"));
        return ['heim' => $heim, 'oeffentlich' => Umgebung::sh("$g --armor --export"), 'geheim' => Umgebung::sh("$g --armor --export-secret-keys"), 'fpr' => $fpr];
    }

    /** Signiert wie der Nutzer an seinem Rechner: gpg --armor --detach-sign über die heruntergeladene Datei. */
    private function signiert(array $paar, string $inhalt, string $zusatz = ''): string
    {
        $datei = "{$this->gpg}/inhalt-" . bin2hex(random_bytes(4));
        file_put_contents($datei, $inhalt);
        Umgebung::sh('gpg --homedir ' . escapeshellarg($paar['heim']) . " --batch --no-tty --armor --detach-sign $zusatz " . escapeshellarg($datei) . ' 2>/dev/null');
        return (string) file_get_contents("$datei.asc");
    }

    public function testSiegel(): void
    {
        $s = self::P . '/siegel';
        $this->mandantAnlegen('siegelpraxis', 'Siegelpraxis');
        [$vera, $pkVera] = $this->nutzer('siegelpraxis', 'Vera', 'Verantwortlich');
        [, $pkBert] = $this->nutzer('siegelpraxis', 'Bert', 'Buchhalter', ['Buchhalter']);
        [, $pkEmil] = $this->nutzer('siegelpraxis', 'Emil', 'Erfasser', ['Erfasser']);
        $this->u->cli(['command' => 'modul:aktivieren', 'modul' => 'siegel', 'mandant' => 'siegelpraxis']);
        $erika = $this->paar('erika', 'Erika Muster <erika@example.org>');
        $max = $this->paar('max', 'Max Muster <max@example.org>', 'rsa3072');
        $fremd = $this->paar('fremd', 'Fremde Person <fremd@example.org>');

        // Rechte: Erfasser sieht nichts, Buchhalter liest, Verantwortlicher siegelt
        $this->anmelden($pkEmil);
        $this->status(403, $this->http('GET', $s), 'Erfasser sieht keine Siegel');
        $this->anmelden($pkBert);
        $l = $this->status(200, $this->http('GET', $s), 'Buchhalter liest');
        $this->gleich([[], []], [$l['siegel'], $l['schluessel']], 'noch kein Siegel, kein Schlüssel');
        $this->gleich(0, $l['kettenkoepfe']['buchungen']['nr'], 'Kettenkopf der Buchungen wird gezeigt');
        $this->status(403, $this->http('POST', $s, []), 'Buchhalter fordert kein Siegel an');
        $this->status(403, $this->http('POST', "$s/schluessel", ['schluessel' => $erika['oeffentlich']]), 'und meldet keinen Schlüssel an');

        // anfordern: vorläufig – kein Siegel, kein Protokoll (F124)
        $this->anmelden($pkVera);
        $kopf = $this->sql('SELECT letzte_nr FROM kern.kettenkopf WHERE kette = 2');
        $neu = $this->status(201, $this->http('POST', $s, []), 'Siegel angefordert');
        $this->gleich([1, null], [$neu['nr'], $neu['signatur']], 'Anforderung Nr. 1, noch ohne Signatur');
        $this->gleich([['0'], ['0'], $kopf], [$this->sql('SELECT count(*) FROM kern.siegel'), $this->sql("SELECT count(*) FROM kern.protokoll WHERE typ LIKE 'siegel.%'"),
            $this->sql('SELECT letzte_nr FROM kern.kettenkopf WHERE kette = 2')], 'Anfordern schreibt nichts fest: kein Siegel, kein Protokoll');
        $l = $this->status(200, $this->http('GET', $s), 'Liste');
        $this->gleich([[], $neu['id']], [$l['siegel'], $l['anforderung']['id']], 'die Liste zeigt die Anforderung getrennt von den Siegeln');
        $this->problem(409, 'KM003', $this->http('POST', $s, []), 'höchstens eine offene Anforderung');
        [$r] = $this->http('GET', "$s/{$neu['id']}/inhalt");
        $inhalt = (string) $r->getContent();
        $this->gleich(200, $r->getStatusCode(), 'Siegelinhalt als Datei');
        $this->gleich('attachment; filename="kumero-siegel-siegelpraxis-0001.json"', $r->headers->get('Content-Disposition'), 'Dateiname nennt Mandant und Nummer');
        $this->gleich($neu['inhalt_sha256'], hash('sha256', $inhalt), 'die Datei hat genau die gemeldete Prüfsumme');
        $j = json_decode($inhalt, true);
        $this->gleich(['kumero-siegel', 1, 'siegelpraxis', 1, null], [$j['art'], $j['fassung'], $j['mandant'], $j['nr'], $j['vorgaenger']], 'Inhalt nennt Art, Mandant, Nummer');
        $this->ist(preg_match('/^[0-9a-f]{64}$/', $j['protokoll']['hash']) === 1 && $j['protokoll']['bis_nr'] > 0 && $j['buchungen']['bis_nr'] === 0, 'und beide Kettenköpfe');

        // verwerfen: spurlos; danach wieder Nr. 1
        $this->anmelden($pkBert);
        $this->status(403, $this->http('DELETE', "$s/{$neu['id']}"), 'Buchhalter verwirft nicht');
        $this->anmelden($pkVera);
        $this->status(204, $this->http('DELETE', "$s/{$neu['id']}"), 'Anforderung verworfen');
        $this->status(404, $this->http('GET', "$s/{$neu['id']}/inhalt"), 'verworfen: kein Inhalt mehr');
        $this->gleich([['0'], ['0'], ['0']], [$this->sql('SELECT count(*) FROM kern.siegel_anforderung'), $this->sql('SELECT count(*) FROM kern.siegel'),
            $this->sql("SELECT count(*) FROM kern.protokoll WHERE typ LIKE 'siegel.%'")], 'nichts bleibt zurück');
        $neu = $this->status(201, $this->http('POST', $s, []), 'neu angefordert');
        $this->gleich(1, $neu['nr'], 'wieder Nr. 1 – verworfene Anforderungen verbrauchen keine Nummer');
        $inhalt = (string) $this->http('GET', "$s/{$neu['id']}/inhalt")[0]->getContent();

        // Signatur ohne angemeldeten Schlüssel
        $sig = $this->signiert($erika, $inhalt);
        $this->problem(409, 'siegel.schluessel_unbekannt', $this->http('POST', "$s/{$neu['id']}/signaturen", ['signatur' => $sig]), 'ohne angemeldeten Schlüssel keine Signatur');
        $this->problem(409, 'KM003', $this->http('POST', "$s/{$neu['id']}/festschreiben", []), 'ohne geprüfte Signatur wird nichts festgeschrieben');

        // Schlüssel anmelden: nur öffentlich, nur einmal
        $this->problem(422, 'siegel.privater_schluessel', $this->http('POST', "$s/schluessel", ['schluessel' => $erika['geheim']]), 'privater Schlüssel wird abgewiesen');
        $this->gleich(['0'], $this->sql('SELECT count(*) FROM kern.siegelschluessel'), 'und nicht gespeichert');
        $this->status(400, $this->http('POST', "$s/schluessel", ['schluessel' => 'kein Schlüssel']), 'Text ohne Hülle');
        $this->status(400, $this->http('POST', "$s/schluessel", ['schluessel' => $sig]), 'eine Signatur ist kein Schlüssel');
        $k = $this->status(201, $this->http('POST', "$s/schluessel", ['schluessel' => $erika['oeffentlich']]), 'Schlüssel angemeldet');
        $this->gleich([$erika['fpr'], 'Erika Muster <erika@example.org>'], [$k['fpr'], $k['kennung']], 'Fingerabdruck und Kennung aus dem Schlüssel gelesen');
        $this->status(409, $this->http('POST', "$s/schluessel", ['schluessel' => $erika['oeffentlich']]), 'derselbe Schlüssel nicht zweimal');
        [$r] = $this->http('GET', "$s/schluessel/{$erika['fpr']}");
        $this->ist($r->getStatusCode() === 200 && str_starts_with((string) $r->getContent(), '-----BEGIN PGP PUBLIC KEY BLOCK-----'), 'öffentlicher Schlüssel als Datei');

        // Signatur: falscher Inhalt, fremder Schlüssel, dann gültig – geprüft, aber noch nicht festgeschrieben
        $this->problem(422, 'siegel.signatur_ungueltig', $this->http('POST', "$s/{$neu['id']}/signaturen", ['signatur' => $this->signiert($erika, $inhalt . "\n")]), 'Signatur über veränderte Datei (ein Zeilenende mehr)');
        $this->problem(409, 'siegel.schluessel_unbekannt', $this->http('POST', "$s/{$neu['id']}/signaturen", ['signatur' => $this->signiert($fremd, $inhalt)]), 'Signatur mit nicht angemeldetem Schlüssel');
        $this->problem(422, 'siegel.signatur_schwach', $this->http('POST', "$s/{$neu['id']}/signaturen", ['signatur' => $this->signiert($erika, $inhalt, '--digest-algo SHA1 --allow-weak-digest-algos')]), 'SHA-1 genügt nicht');
        $this->status(400, $this->http('POST', "$s/{$neu['id']}/signaturen", ['signatur' => $erika['oeffentlich']]), 'ein Schlüssel ist keine Signatur');
        $this->status(404, $this->http('POST', "$s/00000000-0000-4000-8000-000000000000/signaturen", ['signatur' => $sig]), 'unbekanntes Siegel');
        $e = $this->status(200, $this->http('POST', "$s/{$neu['id']}/signaturen", ['signatur' => str_replace("\n", "\r\n", $sig)]), 'gültige Signatur geprüft (auch mit Windows-Zeilenenden)');
        $this->gleich([$erika['fpr'], false], [$e['schluessel'], $e['festgeschrieben']], 'kumero nennt den Schlüssel der Signatur; festgeschrieben ist noch nichts');
        $l = $this->status(200, $this->http('GET', $s), 'Liste');
        $this->gleich([[], $erika['fpr'], 'Erika Muster <erika@example.org>'], [$l['siegel'], $l['anforderung']['signatur']['schluessel'], $l['anforderung']['signatur']['kennung']],
            'die Anforderung trägt die geprüfte Signatur, ein Siegel gibt es noch nicht');
        $this->gleich([['0'], ['0'], ['0']], [$this->sql('SELECT count(*) FROM kern.siegel'), $this->sql('SELECT count(*) FROM kern.siegel_signatur'),
            $this->sql("SELECT count(*) FROM kern.protokoll WHERE typ IN ('siegel.angefordert', 'siegel.signiert', 'siegel.festgeschrieben')")], 'vor der Bestätigung steht nichts in den Ketten');
        $this->status(404, $this->http('GET', "$s/{$neu['id']}/signaturen/{$erika['fpr']}"), 'die vorläufige Signatur ist noch keine Signatur des Siegels');

        // Bestätigung: Siegel, Signatur und Protokoll in einem Schritt
        $this->anmelden($pkBert);
        $this->status(403, $this->http('POST', "$s/{$neu['id']}/festschreiben", []), 'Buchhalter schreibt kein Siegel fest');
        $this->anmelden($pkVera);
        $this->status(400, $this->http('POST', "$s/{$neu['id']}/festschreiben", ['x' => 1]), 'Festschreiben nimmt keine Angaben');
        $f = $this->status(201, $this->http('POST', "$s/{$neu['id']}/festschreiben", []), 'Siegel festgeschrieben');
        $this->gleich([$neu['id'], 1, $neu['inhalt_sha256']], [$f['id'], $f['nr'], $f['inhalt_sha256']], 'das Siegel trägt ID, Nummer und Prüfsumme der Anforderung');
        $this->problem(409, 'siegel.festgeschrieben', $this->http('POST', "$s/{$neu['id']}/festschreiben", []), 'festschreiben nur einmal');
        $this->problem(409, 'siegel.festgeschrieben', $this->http('DELETE', "$s/{$neu['id']}"), 'ein festgeschriebenes Siegel wird nicht verworfen');
        $this->gleich(['siegel.festgeschrieben 1'], $this->sql("SELECT typ || ' ' || count(*) FROM kern.protokoll WHERE typ LIKE 'siegel.%' AND typ NOT LIKE 'siegel.schluessel%' GROUP BY typ"),
            'genau ein Ereignis für das Siegel');
        $this->gleich($inhalt, (string) $this->http('GET', "$s/{$neu['id']}/inhalt")[0]->getContent(), 'der Inhalt des Siegels ist der signierte');
        $this->status(409, $this->http('POST', "$s/{$neu['id']}/signaturen", ['signatur' => $sig]), 'je Schlüssel eine Signatur');
        [$r] = $this->http('GET', "$s/{$neu['id']}/signaturen/{$erika['fpr']}");
        $this->gleich(trim($sig), trim((string) $r->getContent()), 'die Signatur lässt sich wieder herunterladen');

        // was abgelegt ist, lässt sich ohne kumero prüfen: Inhalt, Signatur und Schlüssel aus den drei Dateien
        $v = "{$this->gpg}/dritter";
        mkdir($v, 0700);
        file_put_contents("$v/i.json", $inhalt);
        file_put_contents("$v/i.json.asc", (string) $r->getContent());
        file_put_contents("$v/k.asc", (string) $this->http('GET', "$s/schluessel/{$erika['fpr']}")[0]->getContent());
        $aus = Umgebung::sh('gpg --homedir ' . escapeshellarg($v) . ' --batch --no-tty --import ' . escapeshellarg("$v/k.asc") . ' 2>/dev/null; gpg --homedir '
            . escapeshellarg($v) . ' --batch --no-tty --status-fd 1 --verify ' . escapeshellarg("$v/i.json.asc") . ' ' . escapeshellarg("$v/i.json") . ' 2>/dev/null || true');
        $this->ist(str_contains($aus, "VALIDSIG {$erika['fpr']}"), 'ein Dritter prüft das Siegel mit gpg allein');

        // weitere Signatur am festgeschriebenen Siegel (m:n), Anzeige
        $this->status(201, $this->http('POST', "$s/schluessel", ['schluessel' => $max['oeffentlich']]), 'zweiter Schlüssel (RSA)');
        $w = $this->status(201, $this->http('POST', "$s/{$neu['id']}/signaturen", ['signatur' => $this->signiert($max, $inhalt)]), 'weitere Signatur am festgeschriebenen Siegel');
        $this->gleich(true, $w['festgeschrieben'], 'sie ist sofort eingetragen');
        $l = $this->status(200, $this->http('GET', $s), 'Liste');
        $this->gleich(['signiert', 2, 0, null], [$l['siegel'][0]['status'], count($l['siegel'][0]['signaturen']), $l['siegel'][0]['buchungen_danach'], $l['anforderung']], 'Siegel 1 signiert, zwei Signaturen, keine offene Anforderung');
        $this->gleich('Vera Verantwortlich', $l['namen']["nutzer:$vera"] ?? null, 'Name der Anfordernden für die Anzeige');
        $this->gleich([1, 1], array_column($l['schluessel'], 'signaturen'), 'Schlüssel nennen ihre Signaturen');
        $this->ist(!str_contains(json_encode($l), 'BEGIN PGP'), 'die Liste trägt weder Schlüssel noch Signaturen im Volltext');

        // zweites Siegel: Vorgänger; zurückgezogener Schlüssel; Signatur ersetzen
        $zwei = $this->status(201, $this->http('POST', $s, []), 'zweite Anforderung');
        $inhalt2 = (string) $this->http('GET', "$s/{$zwei['id']}/inhalt")[0]->getContent();
        $this->gleich([2, $neu['inhalt_sha256']], [$zwei['nr'], json_decode($inhalt2, true)['vorgaenger']], 'Anforderung 2 nennt die Prüfsumme von Siegel 1');
        $this->problem(422, 'siegel.signatur_ungueltig', $this->http('POST', "$s/{$zwei['id']}/signaturen", ['signatur' => $sig]), 'die Signatur von Siegel 1 passt nicht auf Anforderung 2');
        $this->status(200, $this->http('POST', "$s/{$zwei['id']}/signaturen", ['signatur' => $this->signiert($max, $inhalt2)]), 'mit dem zweiten Schlüssel geprüft');
        $this->status(400, $this->http('POST', "$s/schluessel/{$max['fpr']}/zurueckziehen", ['grund' => ' ']), 'Grund ist Pflicht');
        $this->status(204, $this->http('POST', "$s/schluessel/{$max['fpr']}/zurueckziehen", ['grund' => 'Schlüssel ersetzt']), 'zurückgezogen');
        $this->problem(409, 'KM003', $this->http('POST', "$s/{$zwei['id']}/festschreiben", []), 'mit zurückgezogenem Schlüssel wird nicht festgeschrieben');
        $this->status(409, $this->http('POST', "$s/{$zwei['id']}/signaturen", ['signatur' => $this->signiert($max, $inhalt2)]), 'zurückgezogener Schlüssel signiert nicht mehr');
        $this->status(200, $this->http('POST', "$s/{$zwei['id']}/signaturen", ['signatur' => $this->signiert($erika, $inhalt2)]), 'die vorläufige Signatur lässt sich ersetzen');
        $this->status(201, $this->http('POST', "$s/{$zwei['id']}/festschreiben", []), 'Siegel 2 festgeschrieben');
        $l = $this->status(200, $this->http('GET', $s), 'Liste');
        $this->gleich([[2, 'signiert', 1], [1, 'signiert', 2]], array_map(fn ($x) => [$x['nr'], $x['status'], count($x['signaturen'])], $l['siegel']), 'neuestes Siegel zuerst; das alte behält beide Signaturen');

        // Protokoll: nur was festgeschrieben wurde, ohne Kennung und ohne Schlüsseltext
        $typen = $this->sql("SELECT typ || ' ' || count(*) FROM kern.protokoll WHERE typ LIKE 'siegel.%' GROUP BY typ ORDER BY typ");
        $this->gleich(['siegel.festgeschrieben 2', 'siegel.schluessel_angemeldet 2', 'siegel.schluessel_zurueckgezogen 1', 'siegel.signiert 1'], $typen, 'Ereignisse im Protokoll');
        $this->gleich(['0'], $this->sql("SELECT count(*) FROM kern.protokoll WHERE typ LIKE 'siegel.%' AND (nachher::text LIKE '%Muster%' OR nachher::text LIKE '%BEGIN PGP%')"), 'keine Kennung, kein Schlüssel in der Kette');
        $this->gleich('', trim(Umgebung::sh('ls ' . escapeshellarg(sys_get_temp_dir()) . ' | grep "^kumero-siegel-[0-9a-f]\{16\}$" || true')), 'kein Arbeitsverzeichnis der Prüfung bleibt liegen');
    }
}
