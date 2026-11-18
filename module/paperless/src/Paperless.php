<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Paperless;

/**
 * Kleiner Client für die REST-API von Paperless-ngx (PAPERLESS.md Abschnitt 2). Spricht nur mit der
 * eingetragenen Adresse, folgt keinen Weiterleitungen und lädt nie beliebige Adressen. Das Token
 * steht nur im Kopf der Anfrage; Fehlermeldungen nennen nur Pfad und Status, nie Inhalte.
 */
final class Paperless
{
    public const ADRESSE = '~^(https://[a-z0-9]([a-z0-9.-]*[a-z0-9])?|http://(localhost|127\.0\.0\.1))(:[0-9]{1,5})?(/[a-z0-9._\~/-]*[a-z0-9._\~-])?$~';
    private const ZEITLIMIT = 15;
    private const HOECHSTENS = 52428800;   // 50 MB je Original

    /** Version von Paperless (Kopf X-Version) und die größte API-Version, die es meldet (Kopf X-Api-Version). */
    public ?string $version = null;
    public ?int $apiGemeldet = null;
    /** API-Version, die kumero in jedem Aufruf verlangt; null = noch nicht festgestellt. */
    public ?int $api;
    /** Wird aufgerufen, sobald die Versionen (neu) festgestellt sind. @var ?callable(self): void */
    public $beiFeststellung = null;

    public function __construct(private readonly string $adresse, #[\SensitiveParameter] private readonly string $token, ?int $api = null)
    {
        if (!preg_match(self::ADRESSE, $adresse)) {
            throw new PaperlessFehler('adresse', 0);
        }
        $this->api = $api !== null && isset(self::tabelle()['api'][(string) $api]) ? $api : null;
    }

    /** Gepflegte Tabelle der API-Versionen ab Paperless 3.0 (F94). @return array<string, mixed> */
    public static function tabelle(): array
    {
        static $t = null;
        return $t ??= json_decode((string) file_get_contents(dirname(__DIR__) . '/versionen.json'), true, 8, JSON_THROW_ON_ERROR);
    }

    /** Ist dieses Paperless neuer, als kumero geprüft ist? Dann gilt die Pflegeaufgabe (F94). */
    public function neuerAlsGeprueft(): bool
    {
        $t = self::tabelle();
        return ($this->apiGemeldet !== null && $this->apiGemeldet > max(array_map('intval', array_keys($t['api']))))
            || ($this->version !== null && version_compare(self::kurz($this->version), $t['paperless_geprueft_bis'], '>'));
    }

    private static function kurz(string $version): string
    {
        return preg_match('/^(\d+)\.(\d+)/', $version, $m) ? "$m[1].$m[2]" : '0.0';
    }

    /**
     * Stellt fest, welches Paperless antwortet und welche API-Version kumero mit ihm spricht: die größte,
     * die beide Seiten kennen. Paperless vor 3.0 und ein Paperless, das keine bekannte Version mehr bedient,
     * werden abgelehnt – mit einem Grund, den die Oberfläche nennen kann.
     */
    public function feststellen(): void
    {
        $this->api = null;
        $this->abrufen('GET', '/api/tags/?page_size=1', null, 1048576, 'Accept: application/json');
        $t = self::tabelle();
        $bekannt = array_map('intval', array_keys($t['api']));
        if ($this->version === null || $this->apiGemeldet === null) {
            throw new PaperlessFehler('kein_paperless', 200);
        }
        if (version_compare(self::kurz($this->version), $t['paperless_ab'], '<') || $this->apiGemeldet < min($bekannt)) {
            throw new PaperlessFehler('zu_alt', 200);
        }
        $this->api = min($this->apiGemeldet, max($bekannt));
        // Bedient Paperless die gewählte Version wirklich? (Es kündigt alte Versionen ab.)
        try {
            $this->abrufen('GET', '/api/tags/?page_size=1', null, 1048576, 'Accept: application/json; version=' . $this->api);
        } catch (PaperlessFehler $f) {
            $this->api = null;
            throw $f->status === 406 ? new PaperlessFehler('api_unbekannt', 406) : $f;
        }
        if (is_callable($this->beiFeststellung)) {
            ($this->beiFeststellung)($this);
        }
    }

    /** Link eines Dokuments in der einheitlichen Schreibweise (F88, L15). */
    public static function link(string $adresse, int $id): string
    {
        return "$adresse/documents/$id/";
    }

    /** Dokument-ID aus einem Link dieser Adresse oder null. */
    public static function idAusLink(string $adresse, string $link): ?int
    {
        return preg_match('~^' . preg_quote($adresse, '~') . '/documents/([1-9][0-9]{0,9})(/[a-z]*)?/?$~', $link, $m) ? (int) $m[1] : null;
    }

    /**
     * Aufruf in der festgestellten API-Version. Antwortet Paperless mit 406 (es wurde aktualisiert und bedient
     * die gemerkte Version nicht mehr), wird einmal neu festgestellt und der Aufruf wiederholt.
     *
     * @return array{status: int, typ: string, inhalt: string}
     */
    public function roh(string $methode, string $pfad, ?array $json = null, int $hoechstens = 5242880): array
    {
        if ($this->api === null) {
            $this->feststellen();
        }
        try {
            return $this->abrufen($methode, $pfad, $json, $hoechstens, 'Accept: application/json; version=' . $this->api);
        } catch (PaperlessFehler $f) {
            if ($f->status !== 406) {
                throw $f;
            }
            $this->feststellen();
            return $this->abrufen($methode, $pfad, $json, $hoechstens, 'Accept: application/json; version=' . $this->api);
        }
    }

    /** @return array{status: int, typ: string, inhalt: string} */
    private function abrufen(string $methode, string $pfad, ?array $json, int $hoechstens, string $accept, ?string $koerper = null, ?string $koerperTyp = null): array
    {
        $kopf = ['Authorization: Token ' . $this->token, $accept, 'User-Agent: kumero'];
        if ($json !== null) {
            $kopf[] = 'Content-Type: application/json';
        } elseif ($koerper !== null) {
            $kopf[] = 'Content-Type: ' . $koerperTyp;
            $kopf[] = 'Content-Length: ' . strlen($koerper);
        }
        $kontext = stream_context_create(['http' => ['method' => $methode, 'header' => implode("\r\n", $kopf), 'timeout' => self::ZEITLIMIT,
            'follow_location' => 0, 'max_redirects' => 0, 'ignore_errors' => true, 'content' => $json === null ? $koerper : json_encode($json)],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $h = @fopen($this->adresse . $pfad, 'rb', false, $kontext);
        if ($h === false) {
            throw new PaperlessFehler('nicht_erreichbar', 0);
        }
        $inhalt = (string) stream_get_contents($h, $hoechstens + 1);
        $meta = stream_get_meta_data($h);
        fclose($h);
        if (strlen($inhalt) > $hoechstens) {
            throw new PaperlessFehler('zu_gross', 0);
        }
        $status = 0;
        $typ = '';
        foreach ($meta['wrapper_data'] ?? [] as $z) {
            if (preg_match('~^HTTP/\S+ (\d{3})~', $z, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($z, 'Content-Type:') === 0) {
                $typ = trim(explode(';', substr($z, 13))[0]);
            } elseif (stripos($z, 'X-Version:') === 0 && preg_match('/^[0-9A-Za-z.+-]{1,30}$/', trim(substr($z, 10)))) {
                $this->version = trim(substr($z, 10));
            } elseif (stripos($z, 'X-Api-Version:') === 0 && ctype_digit(trim(substr($z, 14)))) {
                $this->apiGemeldet = (int) trim(substr($z, 14));
            }
        }
        if ($status === 401 || $status === 403) {
            throw new PaperlessFehler('token', $status);
        }
        if ($status === 404) {
            throw new PaperlessFehler('nicht_gefunden', $status);
        }
        if ($status < 200 || $status > 299) {
            throw new PaperlessFehler('antwort', $status);
        }
        return ['status' => $status, 'typ' => $typ, 'inhalt' => $inhalt];
    }

    /**
     * Übergibt ein Dokument an Paperless (`POST /api/documents/post_document/`, Formular mit Datei). Paperless nimmt es
     * an und verarbeitet es danach; die Antwort nennt nur den Vorgang. Ob das Dokument da ist, zeigt `finden()`.
     *
     * @param array<string, string> $felder z. B. title, created
     */
    public function hochladen(#[\SensitiveParameter] string $inhalt, string $dateiname, array $felder, string $medientyp = 'application/pdf'): void
    {
        if ($this->api === null) {
            $this->feststellen();
        }
        $grenze = '----kumero' . bin2hex(random_bytes(12));
        $koerper = '';
        foreach ($felder as $name => $wert) {
            $koerper .= "--$grenze\r\nContent-Disposition: form-data; name=\"" . preg_replace('/[^a-z_]/', '', $name) . "\"\r\n\r\n" . str_replace(["\r", "\n"], ' ', $wert) . "\r\n";
        }
        $datei = preg_replace('/[^A-Za-z0-9._ -]/', '_', $dateiname) ?: 'beleg.pdf';
        $koerper .= "--$grenze\r\nContent-Disposition: form-data; name=\"document\"; filename=\"$datei\"\r\nContent-Type: $medientyp\r\n\r\n$inhalt\r\n--$grenze--\r\n";
        $this->abrufen('POST', '/api/documents/post_document/', null, 65536, 'Accept: application/json; version=' . $this->api, $koerper, "multipart/form-data; boundary=$grenze");
    }

    /**
     * Sucht das Dokument, dessen Original die genannte Prüfsumme hat. Welche Filter Paperless kennt, ist je Version
     * verschieden; deshalb wird jeder Treffer an seiner `original_checksum` geprüft – ein unbekannter Filter schadet nicht.
     */
    public function finden(string $md5, string $sha256, string $titel): ?int
    {
        $fragen = ['checksum__iexact=' . $md5, 'checksum__iexact=' . $sha256];
        if ($titel !== '') {
            $fragen[] = 'title__iexact=' . rawurlencode($titel);
        }
        $gesehen = [];
        foreach ($fragen as $f) {
            try {
                $d = $this->json('GET', "/api/documents/?$f&ordering=-added&page=1&page_size=5");
            } catch (PaperlessFehler $x) {
                if (in_array($x->grund, ['token', 'nicht_erreichbar'], true)) {
                    throw $x;
                }
                continue;   // Filter in dieser Version unbekannt
            }
            foreach (array_slice($d['results'] ?? [], 0, 5) as $z) {
                $id = is_array($z) && is_int($z['id'] ?? null) ? $z['id'] : null;
                if ($id === null || isset($gesehen[$id])) {
                    continue;
                }
                $gesehen[$id] = true;
                $meta = $this->json('GET', "/api/documents/$id/metadata/");
                $angabe = is_string($meta['original_checksum'] ?? null) ? strtolower($meta['original_checksum']) : '';
                if ($angabe === $md5 || $angabe === $sha256) {
                    return $id;
                }
            }
        }
        return null;
    }

    /** @return array<mixed> */
    public function json(string $methode, string $pfad, ?array $json = null): array
    {
        $d = json_decode($this->roh($methode, $pfad, $json)['inhalt'], true);
        if (!is_array($d)) {
            throw new PaperlessFehler('antwort', 200);
        }
        return $d;
    }

    /** Alle Seiten einer Liste (höchstens $seiten). @return list<array<string, mixed>> */
    public function liste(string $pfad, int $seiten = 10): array
    {
        $erg = [];
        for ($s = 1; $s <= $seiten; $s++) {
            $d = $this->json('GET', $pfad . (str_contains($pfad, '?') ? '&' : '?') . "page=$s&page_size=100");
            foreach ($d['results'] ?? [] as $z) {
                if (is_array($z)) {
                    $erg[] = $z;
                }
            }
            if (empty($d['next'])) {
                break;
            }
        }
        return $erg;
    }

    /** @return array<int, string> ID → Name */
    public function namen(string $art): array
    {
        return array_column($this->liste("/api/$art/"), 'name', 'id');
    }

    /** Lädt das Original und rechnet die Prüfsummen selbst (F10, F51 Nr. 4). @return array{md5: string, sha256: string, md5_paperless: ?string} */
    public function pruefsummen(int $id): array
    {
        $meta = $this->json('GET', "/api/documents/$id/metadata/");
        $datei = $this->roh('GET', "/api/documents/$id/download/?original=true", null, self::HOECHSTENS)['inhalt'];
        $angabe = is_string($meta['original_checksum'] ?? null) ? strtolower($meta['original_checksum']) : null;
        // Paperless nennt je nach Version die MD5 oder die SHA-256 des Originals; verglichen wird mit der passenden
        $md5 = md5($datei);
        $sha = hash('sha256', $datei);
        return ['md5' => $md5, 'sha256' => $sha, 'md5_paperless' => $angabe, 'stimmt' => $angabe !== null && ($angabe === $md5 || $angabe === $sha)];
    }

    /** Setzt oder entfernt genau ein Tag am Dokument; alles andere bleibt unberührt. Gibt zurück, ob sich etwas geändert hat. */
    public function tagSetzen(int $id, int $tag, bool $an): bool
    {
        $d = $this->json('GET', "/api/documents/$id/");
        $tags = array_values(array_filter($d['tags'] ?? [], 'is_int'));
        if (in_array($tag, $tags, true) === $an) {
            return false;
        }
        $neu = $an ? [...$tags, $tag] : array_values(array_diff($tags, [$tag]));
        $this->json('PATCH', "/api/documents/$id/", ['tags' => $neu]);
        return true;
    }
}
