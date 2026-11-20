<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Plattform;

use Doctrine\DBAL\Connection;
use Kumero\Api\V1\Kontext;
use Kumero\Api\V1\Problem;
use Kumero\Http\Routen;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;

/**
 * Weg einer Anfrage (ANWENDUNG.md Abschnitt 3): Route → Anmeldung → Mandant →
 * Wasserstand → Recht → Idempotenz → genau ein Controller in einer Transaktion
 * → JSON bzw. Problem Details.
 */
final class Kernel
{
    public function __construct(private readonly Konfiguration $konfig, private readonly Datenbank $datenbank)
    {
    }

    public function bearbeiten(Request $r): Response
    {
        $id = bin2hex(random_bytes(8));
        $route = '-';
        $subjekt = '-';
        try {
            $antwort = $this->innen($r, $id, $route, $subjekt);
        } catch (\Throwable $e) {
            $p = $e instanceof Problem ? $e : Fehlerabbildung::problem($e);
            if ($p === null) {
                $this->protokoll($id, $route, $subjekt, 'intern', $e);
                $p = new Problem(500, 'intern', 'Interner Fehler – Anfrage-ID ' . $id);
            } elseif ($p->status >= 500) {
                $this->protokoll($id, $route, $subjekt, $p->fehlercode, $e);
            }
            $antwort = self::problemAntwort($p, $id);
        } finally {
            $this->datenbank->schliessen();
        }
        $antwort->headers->set('X-Anfrage-Id', $id);
        if (!$antwort->headers->has('Cache-Control') || str_starts_with((string) $r->getPathInfo(), '/api/')
            || str_starts_with((string) $r->getPathInfo(), '/m/')) {
            $antwort->headers->set('Cache-Control', 'no-store');
        }
        return $antwort;
    }

    private function innen(Request $r, string $id, string &$routeName, string &$subjekt): Response
    {
        $kontext = (new RequestContext())->fromRequest($r);
        try {
            $treffer = (new UrlMatcher(Routen::sammlung(), $kontext))->match($r->getPathInfo());
        } catch (ResourceNotFoundException) {
            throw Problem::nichtGefunden();
        } catch (MethodNotAllowedException $e) {
            throw new Problem(405, 'methode.nicht_erlaubt', 'Methode nicht erlaubt: ' . implode(', ', $e->getAllowedMethods()));
        }
        $routeName = (string) $treffer['_route'];
        $handler = $treffer['_handler'];
        $param = array_filter($treffer, fn ($k) => !str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_KEY);
        $schreibend = !in_array($r->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true);

        $ident = Identitaet::ermitteln($r, $this->datenbank, $this->konfig);
        $subjekt = $ident?->subjekt ?? '-';
        if (($treffer['_anmeldung'] ?? true) && $ident === null) {
            throw new Problem(401, 'anmeldung.erforderlich', 'Bitte anmelden');
        }
        // Schreibende Aufrufe mit Sitzungs-Cookie nur aus der eigenen Oberfläche (CSRF)
        if ($schreibend && ($ident === null || $ident->art === 'sitzung')) {
            $origin = $r->headers->get('Origin');
            if ($origin !== $this->konfig->adresse) {
                throw new Problem(403, 'anfrage.origin', 'Anfrage von fremder Herkunft');
            }
        }

        if (!($treffer['_mandant'] ?? false)) {
            $recht = $treffer['_recht'] ?? null;
            if ($recht !== null && !in_array($recht, $ident?->systemRechte() ?? [], true)) {
                throw new Problem(403, 'recht.fehlt', "Recht fehlt: $recht");
            }
            $a = new Anfrage($r, $this->konfig, $this->datenbank, $param, $ident, null, null, $id);
            if (!$schreibend) {
                return $handler($a);
            }
            return $this->transaktion($this->datenbank->verwaltung(), fn () => $handler($a));
        }

        // Mandant (F39, F114): Eine Sitzung und ein Token gelten nur für ihren Mandanten. Unbekannt, stillgelegt
        // und „nicht deiner“ sehen gleich aus. Der System-Verwalter sieht keinen Mandanten von innen (U4).
        $kennung = $param['mandant'];
        $m = $ident->mandant !== $kennung ? false : $this->datenbank->verwaltung()->fetchAssociative(
            'SELECT m.datenbank FROM verwaltung.mandant m WHERE m.kennung = ? AND m.stillgelegt_am IS NULL', [$kennung]);
        if ($m === false) {
            throw new Problem(404, 'mandant.unbekannt', 'Mandant unbekannt');
        }
        $db = $this->datenbank->verbindung($m['datenbank']);
        $buch = $db->fetchAssociative('SELECT betriebsart::text AS betriebsart, lesemodus, sprache FROM kern.buch');
        if ($buch === false) {
            throw new Problem(409, 'mandant.nicht_eingerichtet', 'Mandant ist noch nicht eingerichtet');
        }
        if (isset($treffer['_modul']) && !$db->fetchOne('SELECT 1 FROM kern.modul WHERE name = ? AND aktiv', [$treffer['_modul']])) {
            throw new Problem(404, 'modul.nicht_aktiv', 'Modul ist in diesem Mandanten nicht aktiv');
        }
        $lesemodus = (bool) $buch['lesemodus'] || $this->hinterWasserstand($kennung, $db);
        $rechte = [];
        foreach ($db->fetchAllAssociative('SELECT recht, einschraenkungen::text AS e FROM kern.rechte_des_subjekts(?)', [$ident->subjekt]) as $z) {
            $rechte[$z['recht']] = $z['e'] === null ? null : json_decode($z['e'], true);
        }
        $ktx = new Kontext($ident->subjekt, $kennung, $this->konfig->sprache, $buch['betriebsart'], $lesemodus, $id, $rechte);
        $recht = $treffer['_recht'] ?? null;
        if ($recht !== null && !$ktx->darf($recht)) {
            throw new Problem(403, 'recht.fehlt', "Recht fehlt: $recht");
        }
        $a = new Anfrage($r, $this->konfig, $this->datenbank, $param, $ident, $ktx, $db, $id);
        if (!$schreibend) {
            return $handler($a);
        }
        if ($lesemodus) {
            throw new Problem(409, 'lesemodus', 'Mandant ist im Lesemodus (F60, F69)');
        }
        $schluessel = $r->headers->get('Idempotency-Key');
        if ($schluessel === null || strlen($schluessel) < 8 || strlen($schluessel) > 255) {
            throw new Problem(400, 'idempotenz.fehlt', 'Header Idempotency-Key (8–255 Zeichen) fehlt');
        }
        $anfrageHash = hash('sha256', $r->getMethod() . "\n" . $r->getPathInfo() . "\n" . $r->getQueryString() . "\n" . $r->getContent());
        return $this->transaktion($db, function () use ($db, $ident, $schluessel, $routeName, $anfrageHash, $handler, $a): Response {
            $stand = json_decode((string) $db->fetchOne('SELECT kern.idempotenz_beginnen(?, ?, ?, ?)::text',
                [$ident->subjekt, $schluessel, $a->http->getMethod() . ' ' . $routeName, $anfrageHash]), true);
            if (!$stand['neu']) {
                $gespeichert = $stand['inhalt'] ?? [];
                // Der Körper ist als Text gespeichert: die Wiederholung ist byte-gleich
                $antwort = JsonResponse::fromJsonString($gespeichert['body'] ?? '', (int) $stand['status'], $gespeichert['headers'] ?? []);
                if (($gespeichert['body'] ?? '') === '') {
                    $antwort->setContent('');
                }
                $antwort->headers->set('Idempotency-Replayed', 'true');
                return $antwort;
            }
            $antwort = $handler($a);
            $inhalt = [
                'body' => (string) $antwort->getContent(),
                'headers' => array_filter(['ETag' => $antwort->headers->get('ETag'), 'Location' => $antwort->headers->get('Location')]),
            ];
            $status = $antwort->getStatusCode();
            // Eine Antwort mit Geheimnissen (Link und Code eines Zugangs) wird genau einmal gegeben und nicht gespeichert:
            // Die Wiederholung derselben Anfrage sagt nur, dass sie schon ausgeführt ist.
            if ($antwort->headers->hasCacheControlDirective('no-store')) {
                $status = 409;
                $inhalt = ['body' => json_encode(['type' => 'urn:kumero:problem:idempotenz.einmalig', 'title' => 'Schon ausgeführt', 'status' => 409,
                    'code' => 'idempotenz.einmalig', 'detail' => 'Diese Anfrage wurde schon ausgeführt; ihre Antwort wird nur einmal gegeben.'],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'headers' => []];
            }
            $db->executeStatement('SELECT kern.idempotenz_abschliessen(?, ?, ?, ?::jsonb)',
                [$ident->subjekt, $schluessel, $status, json_encode($inhalt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            return $antwort;
        });
    }

    /** Eine Transaktion; bei Serialisierungsfehler oder Verklemmung genau einmal wiederholen. */
    private function transaktion(Connection $db, callable $arbeit): Response
    {
        for ($versuch = 1; ; $versuch++) {
            $db->beginTransaction();
            try {
                $antwort = $arbeit();
                $db->commit();
                return $antwort;
            } catch (\Throwable $e) {
                if ($db->isTransactionActive()) {
                    $db->rollBack();
                }
                if ($versuch === 1 && Fehlerabbildung::istWiederholbar($e)) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /** F69: Liegt die Datenbank hinter der Wasserstandsmarke der Verwaltung, nur lesen. */
    private function hinterWasserstand(string $kennung, Connection $db): bool
    {
        $marken = $this->datenbank->verwaltung()->fetchAllAssociative(
            'SELECT kette, nr, hash FROM verwaltung.wasserstand WHERE mandant = ?', [$kennung]);
        foreach ($marken as $w) {
            if ((int) $w['nr'] === 0) {
                continue;
            }
            $hash = (int) $w['kette'] === 1
                ? $db->fetchOne('SELECT hash FROM kern.buchung WHERE journal_nr = ?', [$w['nr']])
                : $db->fetchOne('SELECT hash FROM kern.protokoll WHERE nr = ?', [$w['nr']]);
            if ($hash !== $w['hash']) {
                return true;
            }
        }
        return false;
    }

    public static function problemAntwort(Problem $p, string $id): JsonResponse
    {
        $koerper = ['type' => 'urn:kumero:problem:' . $p->fehlercode, 'title' => $p->getMessage(), 'status' => $p->status,
                    'code' => $p->fehlercode, 'detail' => $p->getMessage(), 'instance' => 'urn:kumero:anfrage:' . $id];
        if ($p->fehler !== []) {
            $koerper['fehler'] = $p->fehler;
        }
        $a = new JsonResponse($koerper, $p->status);
        $a->headers->set('Content-Type', 'application/problem+json');
        $a->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $a;
    }

    /** Server-Log ohne Buchungsinhalte (Art. 9 DSGVO); Details nur auf Stufe „debug“. */
    private function protokoll(string $id, string $route, string $subjekt, string $code, \Throwable $e): void
    {
        $zeile = sprintf('kumero[%s] %s %s %s %s sqlstate=%s bei %s:%d', $id, $route, $subjekt, $code, $e::class,
            Fehlerabbildung::sqlstate($e) ?? '-', $e->getFile(), $e->getLine());
        if ($this->konfig->protokollStufe === 'debug') {
            $zeile .= ' – ' . $e->getMessage();
        }
        error_log($zeile);
    }
}
