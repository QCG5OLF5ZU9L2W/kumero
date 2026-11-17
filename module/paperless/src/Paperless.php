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

    public ?string $version = null;

    public function __construct(private readonly string $adresse, #[\SensitiveParameter] private readonly string $token)
    {
        if (!preg_match(self::ADRESSE, $adresse)) {
            throw new PaperlessFehler('adresse', 0);
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

    /** @return array{status: int, typ: string, inhalt: string} */
    public function roh(string $methode, string $pfad, ?array $json = null, int $hoechstens = 5242880): array
    {
        $kopf = ['Authorization: Token ' . $this->token, 'Accept: application/json; version=5', 'User-Agent: kumero'];
        if ($json !== null) {
            $kopf[] = 'Content-Type: application/json';
        }
        $kontext = stream_context_create(['http' => ['method' => $methode, 'header' => implode("\r\n", $kopf), 'timeout' => self::ZEITLIMIT,
            'follow_location' => 0, 'max_redirects' => 0, 'ignore_errors' => true, 'content' => $json === null ? null : json_encode($json)],
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
            } elseif (stripos($z, 'X-Version:') === 0) {
                $this->version = substr(trim(substr($z, 10)), 0, 30);
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
        return ['md5' => md5($datei), 'sha256' => hash('sha256', $datei), 'md5_paperless' => $angabe];
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
