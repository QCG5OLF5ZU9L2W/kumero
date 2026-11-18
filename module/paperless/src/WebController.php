<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Paperless;

use Kumero\Api\V1\Problem;
use Kumero\Http\Antwort;
use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST des Moduls paperless (PAPERLESS.md Abschnitt 5): der eigene Zugang, Tags, Vorschau und
 * geprüfter Belegverweis. Alle Aufrufe an Paperless gehen vom Server aus, mit dem Token des
 * angemeldeten Nutzers; durchgereichte Belege tragen `Cache-Control: no-store`.
 */
final class WebController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function verbindungen(Anfrage $a): Response
    {
        $v = $a->db()->fetchAssociative('SELECT adresse, bezeichnung FROM paperless.vorgabe');
        return Antwort::json(['vorgabe' => $v ?: null, 'verbindungen' => (new Zugang($a))->liste(), 'passphrase_min' => Tresor::PASSPHRASE_MIN]);
    }

    /** Neue Verbindung: Das Token wird sofort gegen Paperless geprüft und nur verschlüsselt gespeichert. */
    public function anlegen(Anfrage $a): Response
    {
        $j = $a->json();
        $z = new Zugang($a);
        [$bez, $adresse, $tag, $f] = self::felder($j, ['bezeichnung', 'adresse', 'tag_offen', 'token', 'passphrase']);
        $token = $j['token'] ?? null;
        $pass = $j['passphrase'] ?? null;
        if (!is_string($token) || !preg_match('/^[A-Za-z0-9_.-]{8,200}$/', $token)) {
            $f[] = ['feld' => 'token', 'meldung' => 'Token aus dem Paperless-Profil erwartet'];
        }
        if (!is_string($pass) || mb_strlen($pass) < Tresor::PASSPHRASE_MIN || mb_strlen($pass) > 200) {
            $f[] = ['feld' => 'passphrase', 'meldung' => 'Passphrase mit mindestens ' . Tresor::PASSPHRASE_MIN . ' Zeichen erwartet'];
        }
        if ($f !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        $c = new Paperless($adresse, $token);
        $tagId = self::tagId($c, $tag);
        $t = Tresor::verschliessen($token, $pass);
        $b64 = fn (string $x) => base64_encode($x);
        $id = (string) $a->db()->fetchOne("SELECT paperless.verbindung_setzen(?, NULL, ?, ?, ?, ?, decode(?, 'base64'), decode(?, 'base64'), decode(?, 'base64'), ?, ?)",
            [$a->kontext()->subjekt, $bez, $adresse, $tag, $tagId, $b64($t['chiffre']), $b64($t['nonce']), $b64($t['salz']), $t['ops'], $t['speicher']]);
        $z->merken($id, $c);
        // gleich entsperren: Die Passphrase wurde eben eingegeben
        return $this->entsperrt($a, new Zugang($a), $pass, 201, $id);
    }

    public function aendern(Anfrage $a): Response
    {
        $z = new Zugang($a);
        $v = $z->verbindung($a->param('id'));
        [$bez, , $tag, $f] = self::felder($a->json() + ['adresse' => $v['adresse']], ['bezeichnung', 'adresse', 'tag_offen']);
        if ($f !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        $tagId = $tag === $v['tag_offen'] ? $v['tag_offen_id'] : self::tagId($z->client($v), $tag);
        $a->db()->executeStatement('SELECT paperless.verbindung_setzen(?, ?::uuid, ?, ?, ?, ?)', [$a->kontext()->subjekt, $v['id'], $bez, $v['adresse'], $tag, $tagId]);
        return Antwort::json(['verbindungen' => (new Zugang($a))->liste()]);
    }

    public function loeschen(Anfrage $a): Response
    {
        $v = (new Zugang($a))->verbindung($a->param('id'));
        $a->db()->executeStatement('SELECT paperless.verbindung_loeschen(?, ?::uuid)', [$a->kontext()->subjekt, $v['id']]);
        return Antwort::leer();
    }

    public function entsperren(Anfrage $a): Response
    {
        $j = $a->json();
        if (array_diff(array_keys($j), ['passphrase']) !== [] || !is_string($j['passphrase'] ?? null) || $j['passphrase'] === '' || mb_strlen($j['passphrase']) > 200) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'passphrase', 'meldung' => 'Pflichtfeld']]);
        }
        return $this->entsperrt($a, new Zugang($a), $j['passphrase'], 200);
    }

    public function sperren(Anfrage $a): Response
    {
        new Zugang($a);
        $a->db()->executeStatement('SELECT paperless.sperren(?)', [$a->kontext()->subjekt]);
        $r = Antwort::json(['verbindungen' => (new Zugang($a))->liste()]);
        $r->headers->clearCookie(Tresor::COOKIE, '/', null, true, true, Cookie::SAMESITE_STRICT);
        return $r;
    }

    private function entsperrt(Anfrage $a, Zugang $z, #[\SensitiveParameter] string $passphrase, int $status, ?string $neu = null): Response
    {
        $erg = $z->entsperren($passphrase, $a->konfig->hoechstensStd);
        if ($erg['anzahl'] === 0) {
            throw new Problem(403, 'paperless.passphrase', 'Die Passphrase passt zu keiner deiner Verbindungen');
        }
        $r = Antwort::json(['verbindungen' => $z->liste(), 'entsperrt' => $erg['anzahl']] + ($neu === null ? [] : ['id' => $neu]), $status);
        // Der Sitzungsschlüssel liegt nur im Browser: nicht für Skripte lesbar, nur über HTTPS, nur an diese Seite
        $r->headers->setCookie(Cookie::create(Tresor::COOKIE, sodium_bin2base64($erg['schluessel'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), 0, '/', null, true, true, false, Cookie::SAMESITE_STRICT));
        return $r;
    }

    public function status(Anfrage $a): Response
    {
        $z = new Zugang($a);
        $v = $z->verbindung($a->param('id'));
        $c = $z->client($v);
        try {
            // Der Verbindungstest stellt die Versionen immer neu fest (F94)
            $c->feststellen();
            $tags = $c->namen('tags');
            $konto = null;
            try {
                $konto = $c->json('GET', '/api/ui_settings/')['user']['username'] ?? null;
            } catch (PaperlessFehler) {
                // ohne Kontonamen weiter
            }
            return Antwort::json(['erreichbar' => true, 'version' => $c->version, 'api_gemeldet' => $c->apiGemeldet, 'api_verwendet' => $c->api,
                'neuer_als_geprueft' => $c->neuerAlsGeprueft(), 'konto' => is_string($konto) ? $konto : null,
                'tag_offen_vorhanden' => $v['tag_offen_id'] === null ? null : isset($tags[(int) $v['tag_offen_id']]), 'tags' => count($tags)]);
        } catch (PaperlessFehler $f) {
            return Antwort::json(['erreichbar' => false, 'grund' => $f->grund, 'status' => $f->status, 'version' => $c->version, 'api_gemeldet' => $c->apiGemeldet]);
        }
    }

    /** Tags des eigenen Paperless-Kontos für das Auswahlfenster (L4): übernommen sind die, die es im Mandanten schon gibt. */
    public function tags(Anfrage $a): Response
    {
        $z = new Zugang($a);
        $v = $z->verbindung($a->param('id'));
        $namen = self::aufruf(fn () => $z->client($v)->namen('tags'));
        $vorhanden = $a->db()->fetchAllKeyValue('SELECT name, herkunft FROM kern.tag');
        $erg = [];
        foreach ($namen as $id => $name) {
            $erg[] = ['id' => (int) $id, 'name' => (string) $name, 'uebernommen' => isset($vorhanden[$name]), 'arbeitstag' => (int) $id === (int) $v['tag_offen_id']];
        }
        usort($erg, fn ($x, $y) => strcasecmp($x['name'], $y['name']));
        return Antwort::json($erg);
    }

    /** Gewählte Tags im Mandanten anlegen (L4a). Tags gehören dem Mandanten; vorhandene Namen bleiben, wie sie sind. */
    public function tagsUebernehmen(Anfrage $a): Response
    {
        if (!$a->kontext()->darf('kern.stammdaten.aendern')) {
            throw new Problem(403, 'recht.fehlt', 'Tags im Mandanten anlegen verlangt das Recht kern.stammdaten.aendern');
        }
        $j = $a->json();
        $ids = $j['tags'] ?? null;
        if (array_diff(array_keys($j), ['tags']) !== [] || !is_array($ids) || !array_is_list($ids) || count($ids) > 500 || array_filter($ids, fn ($x) => !is_int($x)) !== []) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'tags', 'meldung' => 'Liste von Tag-IDs erwartet']]);
        }
        $z = new Zugang($a);
        $v = $z->verbindung($a->param('id'));
        $namen = self::aufruf(fn () => $z->client($v)->namen('tags'));
        $db = $a->db();
        $angelegt = [];
        $uebergangen = [];
        foreach ($ids as $id) {
            $name = $namen[$id] ?? null;
            if (!is_string($name) || $name === '' || mb_strlen($name) > 100) {
                continue;
            }
            // gleicher Name oder gleiche Paperless-ID (anderes Paperless) schon da: nichts still überschreiben
            if ($db->fetchOne('SELECT 1 FROM kern.tag WHERE name = ? OR paperless_id = ?', [$name, $id])) {
                $uebergangen[] = $name;
                continue;
            }
            $db->executeStatement('SELECT kern.tag_anlegen(?, ?, ?)', [$a->kontext()->subjekt, $name, $id]);
            $angelegt[] = $name;
        }
        return Antwort::json(['angelegt' => $angelegt, 'uebergangen' => $uebergangen]);
    }

    /** Vorschaubild eines Belegs, durchgereicht und nicht gespeichert. */
    public function vorschau(Anfrage $a): Response
    {
        return $this->durchreichen($a, 'thumb/', 5242880, ['image/png', 'image/webp', 'image/jpeg']);
    }

    /** Originaldatei eines Belegs, durchgereicht und nicht gespeichert. */
    public function original(Anfrage $a): Response
    {
        return $this->durchreichen($a, 'download/?original=true', 52428800, ['application/pdf', 'image/png', 'image/jpeg', 'image/tiff', 'image/webp', 'text/plain']);
    }

    /** @param list<string> $typen */
    private function durchreichen(Anfrage $a, string $ende, int $hoechstens, array $typen): Response
    {
        $z = new Zugang($a);
        [$v, $id] = $z->zuLink((string) $a->http->query->get('link', ''));
        $c = $z->client($v);
        $d = self::aufruf(fn () => $c->roh('GET', "/api/documents/$id/$ende", null, $hoechstens));
        // nur bekannte Arten, nie als Seite ausführbar
        $typ = in_array($d['typ'], $typen, true) ? $d['typ'] : 'application/octet-stream';
        return new Response($d['inhalt'], 200, ['Content-Type' => $typ, 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => ($typ === 'application/octet-stream' ? 'attachment' : 'inline') . "; filename=\"beleg-$id\"",
            'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    /** Belegverweis mit Prüfung (Abschnitt 6): Original laden, MD5 und SHA-256 selbst rechnen, MD5 gegen Paperless abgleichen. */
    public function verweis(Anfrage $a): Response
    {
        $j = $a->json();
        if (array_diff(array_keys($j), ['link']) !== [] || !is_string($j['link'] ?? null)) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'link', 'meldung' => 'Link erwartet']]);
        }
        $z = new Zugang($a);
        [$v, $id] = $z->zuLink($j['link']);
        $c = $z->client($v);
        $p = self::aufruf(fn () => $c->pruefsummen($id));
        if ($p['md5_paperless'] === null || $p['md5_paperless'] !== $p['md5']) {
            throw new Problem(409, 'paperless.pruefsumme', 'Die Prüfsumme des Originals stimmt nicht mit der Angabe von Paperless überein – keine Verknüpfung (F51 Nr. 4)');
        }
        return Antwort::json(['link' => Paperless::link($v['adresse'], $id), 'md5' => $p['md5'], 'sha256' => $p['sha256']]);
    }

    public function vorgabeSetzen(Anfrage $a): Response
    {
        $j = $a->json();
        if (($j['adresse'] ?? null) === null) {
            $a->db()->executeStatement('SELECT paperless.vorgabe_setzen(?, NULL, NULL)', [$a->kontext()->subjekt]);
            return Antwort::json(['vorgabe' => null]);
        }
        [$bez, $adresse, , $f] = self::felder($j, ['bezeichnung', 'adresse']);
        if ($f !== []) {
            throw Problem::ungueltig('Eingabe ungültig', $f);
        }
        $a->db()->executeStatement('SELECT paperless.vorgabe_setzen(?, ?, ?)', [$a->kontext()->subjekt, $adresse, $bez]);
        return Antwort::json(['vorgabe' => ['adresse' => $adresse, 'bezeichnung' => $bez]]);
    }

    // --- Hilfen ---------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $j
     * @param list<string> $erlaubt
     * @return array{0: string, 1: string, 2: ?string, 3: list<array{feld: string, meldung: string}>}
     */
    private static function felder(array $j, array $erlaubt): array
    {
        $f = [];
        foreach (array_keys($j) as $k) {
            if (!in_array($k, $erlaubt, true)) {
                $f[] = ['feld' => (string) $k, 'meldung' => 'Unbekanntes Feld'];
            }
        }
        // Schema und Host klein, ohne Schrägstrich am Ende – eine Schreibweise je Paperless (L15)
        $adresse = is_string($j['adresse'] ?? null) ? rtrim(preg_replace_callback('~^[a-z]+://[^/]*~i', fn ($m) => strtolower($m[0]), trim($j['adresse'])), '/') : '';
        if (!preg_match(Paperless::ADRESSE, $adresse) || strlen($adresse) > 200) {
            $f[] = ['feld' => 'adresse', 'meldung' => 'Adresse des Paperless erwartet, nur https:// (http nur für localhost)'];
        }
        $bez = is_string($j['bezeichnung'] ?? null) && trim($j['bezeichnung']) !== '' ? trim($j['bezeichnung']) : (string) parse_url($adresse, PHP_URL_HOST);
        if ($bez === '' || mb_strlen($bez) > 60) {
            $f[] = ['feld' => 'bezeichnung', 'meldung' => 'Text mit 1 bis 60 Zeichen erwartet'];
        }
        $tag = $j['tag_offen'] ?? null;
        $tag = is_string($tag) && trim($tag) !== '' ? trim($tag) : null;
        if ($tag !== null && mb_strlen($tag) > 100) {
            $f[] = ['feld' => 'tag_offen', 'meldung' => 'Name eines Tags mit höchstens 100 Zeichen erwartet'];
        }
        return [$bez, $adresse, $tag, $f];
    }

    /** ID des Tags für offene Belege; prüft dabei Adresse und Token. Das Modul legt in Paperless nichts an (L9). */
    private static function tagId(Paperless $c, ?string $tag): ?int
    {
        $namen = self::aufruf(fn () => $c->namen('tags'));
        if ($tag === null) {
            return null;
        }
        $id = array_search($tag, $namen, true);
        if ($id === false) {
            throw Problem::ungueltig('Eingabe ungültig', [['feld' => 'tag_offen', 'meldung' => 'Dieses Tag gibt es in deinem Paperless nicht']]);
        }
        return (int) $id;
    }

    /** Übersetzt Fehler des Gesprächs mit Paperless in Problem Details – ohne Inhalte. */
    private static function aufruf(callable $f): mixed
    {
        try {
            return $f();
        } catch (PaperlessFehler $e) {
            throw match ($e->grund) {
                'token' => new Problem(409, 'paperless.token', 'Paperless hat das Token abgelehnt'),
                'nicht_gefunden' => new Problem(404, 'paperless.nicht_gefunden', 'Paperless kennt diesen Beleg nicht oder dein Konto darf ihn nicht sehen'),
                'zu_gross' => new Problem(409, 'paperless.zu_gross', 'Der Beleg ist zu groß'),
                'nicht_erreichbar' => new Problem(503, 'paperless.nicht_erreichbar', 'Paperless ist nicht erreichbar'),
                'kein_paperless' => new Problem(409, 'paperless.kein_paperless', 'Unter dieser Adresse antwortet kein Paperless-ngx (die Kopfzeilen X-Version und X-Api-Version fehlen)'),
                'zu_alt' => new Problem(409, 'paperless.zu_alt', 'Dieses Paperless ist zu alt: kumero spricht mit Paperless-ngx ab ' . Paperless::tabelle()['paperless_ab']),
                'api_unbekannt' => new Problem(409, 'paperless.api_unbekannt', 'Dieses Paperless bedient keine der API-Versionen mehr, die kumero kennt – kumero muss aktualisiert werden'),
                default => new Problem(502, 'paperless.antwort', 'Paperless hat unerwartet geantwortet (HTTP ' . $e->status . ')'),
            };
        }
    }
}
