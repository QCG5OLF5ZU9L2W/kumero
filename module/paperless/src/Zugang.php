<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Paperless;

use Kumero\Api\V1\Problem;
use Kumero\Plattform\Anfrage;

/**
 * Verbindungen des angemeldeten Nutzers und ihr für die Sitzung entsperrtes Token (PAPERLESS.md L2, L3).
 * Entsperrt ist eine Verbindung nur in der Sitzung, in der die Passphrase eingegeben wurde, und nur,
 * solange der Browser den Sitzungsschlüssel als Cookie mitschickt.
 */
final class Zugang
{
    /** @var list<array<string, mixed>> */
    private array $verbindungen;
    private ?string $schluessel;

    public function __construct(private readonly Anfrage $a)
    {
        $id = $a->identitaet;
        if ($id === null || $id->art !== 'sitzung' || $id->sitzungHash === null) {
            throw new Problem(403, 'paperless.nur_nutzer', 'Paperless gibt es nur für angemeldete Nutzer, nicht für Dienstkonten (L2)');
        }
        $c = (string) $a->http->cookies->get(Tresor::COOKIE, '');
        $roh = $c === '' ? false : sodium_base642bin($c, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        $this->schluessel = is_string($roh) && strlen($roh) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $roh : null;
        $this->laden();
    }

    private function laden(): void
    {
        $this->verbindungen = $this->a->db()->fetchAllAssociative(
            "SELECT v.id, v.bezeichnung, v.adresse, v.tag_offen, v.tag_offen_id, kern.zeitpunkt(v.geaendert_am) AS geaendert_am,
                    v.paperless_version, v.api_gemeldet, v.api_verwendet, kern.zeitpunkt(v.festgestellt_am) AS festgestellt_am,
                    encode(v.token_chiffre, 'base64') AS token_chiffre, encode(v.token_nonce, 'base64') AS token_nonce,
                    encode(v.kdf_salz, 'base64') AS kdf_salz, v.kdf_ops, v.kdf_speicher,
                    encode(e.chiffre, 'base64') AS s_chiffre, encode(e.nonce, 'base64') AS s_nonce
               FROM paperless.verbindung v
               LEFT JOIN paperless.entsperrt e ON e.verbindung = v.id AND e.sitzung = ? AND e.bis > now()
              WHERE v.subjekt = ? ORDER BY v.angelegt_am", [$this->a->identitaet->sitzungHash, $this->a->kontext()->subjekt]);
    }

    /** @return list<array<string, mixed>> ohne Geheimnisse */
    public function liste(): array
    {
        return array_map(fn ($v) => ['id' => $v['id'], 'bezeichnung' => $v['bezeichnung'], 'adresse' => $v['adresse'], 'tag_offen' => $v['tag_offen'],
            'entsperrt' => $this->token($v) !== null, 'geaendert_am' => $v['geaendert_am'],
            'paperless_version' => $v['paperless_version'], 'api_gemeldet' => $v['api_gemeldet'], 'api_verwendet' => $v['api_verwendet'],
            'festgestellt_am' => $v['festgestellt_am']], $this->verbindungen);
    }

    /** @param array<string, mixed> $v */
    private function token(array $v): ?string
    {
        if ($this->schluessel === null || $v['s_chiffre'] === null) {
            return null;
        }
        return Tresor::ausSitzung(base64_decode($v['s_chiffre']), base64_decode($v['s_nonce']), $this->schluessel);
    }

    /** @return array<string, mixed> */
    public function verbindung(string $id): array
    {
        foreach ($this->verbindungen as $v) {
            if ($v['id'] === $id) {
                return $v;
            }
        }
        throw Problem::nichtGefunden('Verbindung unbekannt');
    }

    /** @param array<string, mixed> $v */
    public function client(array $v): Paperless
    {
        $t = $this->token($v) ?? throw new Problem(409, 'paperless.gesperrt', 'Paperless ist gesperrt: bitte unter „Paperless – mein Zugang“ mit der Passphrase entsperren');
        return $this->mitVersion($v, $t);
    }

    /**
     * Client in der gemerkten API-Version; stellt er die Versionen (neu) fest, werden sie an der Verbindung gemerkt (F94).
     *
     * @param array<string, mixed> $v
     */
    private function mitVersion(array $v, #[\SensitiveParameter] string $token): Paperless
    {
        $c = new Paperless($v['adresse'], $token, $v['api_verwendet'] === null ? null : (int) $v['api_verwendet']);
        $c->version = $v['paperless_version'];
        $c->apiGemeldet = $v['api_gemeldet'] === null ? null : (int) $v['api_gemeldet'];
        $c->beiFeststellung = fn (Paperless $p) => $this->merken($v['id'], $p);
        return $c;
    }

    public function merken(string $verbindung, Paperless $p): void
    {
        $this->a->db()->executeStatement('SELECT paperless.version_merken(?, ?::uuid, ?, ?, ?)',
            [$this->a->kontext()->subjekt, $verbindung, $p->version, $p->apiGemeldet, $p->api]);
    }

    /** Entsperrte Verbindungen mit Client. @return list<array{0: array<string, mixed>, 1: Paperless}> */
    public function entsperrte(): array
    {
        $erg = [];
        foreach ($this->verbindungen as $v) {
            $t = $this->token($v);
            if ($t !== null) {
                $erg[] = [$v, $this->mitVersion($v, $t)];
            }
        }
        return $erg;
    }

    /** Verbindung und Dokument-ID zu einem Beleglink. @return array{0: array<string, mixed>, 1: int} */
    public function zuLink(string $link): array
    {
        foreach ($this->verbindungen as $v) {
            $id = Paperless::idAusLink($v['adresse'], $link);
            if ($id !== null) {
                return [$v, $id];
            }
        }
        throw new Problem(404, 'paperless.fremder_link', 'Der Link gehört zu keinem deiner Paperless');
    }

    /**
     * Entsperrt alle Verbindungen, deren Token sich mit der Passphrase öffnen lässt.
     *
     * @return array{anzahl: int, schluessel: ?string} neuer Sitzungsschlüssel für das Cookie
     */
    public function entsperren(#[\SensitiveParameter] string $passphrase, int $stunden): array
    {
        $s = $this->schluessel ?? Tresor::sitzungsschluessel();
        $n = 0;
        foreach ($this->verbindungen as $v) {
            $t = Tresor::oeffnen(base64_decode($v['token_chiffre']), base64_decode($v['token_nonce']), base64_decode($v['kdf_salz']),
                (int) $v['kdf_ops'], (int) $v['kdf_speicher'], $passphrase);
            if ($t === null) {
                continue;
            }
            $x = Tresor::fuerSitzung($t, $s);
            $this->a->db()->executeStatement("SELECT paperless.entsperren(?, ?::uuid, ?, decode(?, 'base64'), decode(?, 'base64'), now() + make_interval(hours => ?))",
                [$this->a->kontext()->subjekt, $v['id'], $this->a->identitaet->sitzungHash, base64_encode($x['chiffre']), base64_encode($x['nonce']), $stunden]);
            $n++;
        }
        $this->schluessel = $n > 0 ? $s : $this->schluessel;
        $this->laden();
        return ['anzahl' => $n, 'schluessel' => $n > 0 ? $s : null];
    }
}
