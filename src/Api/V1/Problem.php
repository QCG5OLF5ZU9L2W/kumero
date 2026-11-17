<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Api\V1;

/**
 * Fachlicher Fehler mit festem Code (RFC 9457, openapi/v1.yaml Problem).
 * Teil der stabilen Modul-API: Module werfen ihn, die Anwendung macht daraus
 * die Antwort. Die Meldung darf keine Buchungsinhalte enthalten (Art. 9 DSGVO).
 */
class Problem extends \RuntimeException
{
    /** @param list<array{feld: string, meldung: string}> $fehler */
    public function __construct(
        public readonly int $status,
        public readonly string $fehlercode,
        string $detail,
        public readonly array $fehler = [],
        ?\Throwable $ursache = null,
    ) {
        parent::__construct($detail, 0, $ursache);
    }

    public static function ungueltig(string $detail, array $fehler = []): self
    {
        return new self(400, 'anfrage.ungueltig', $detail, $fehler);
    }

    public static function nichtGefunden(string $detail = 'Nicht gefunden'): self
    {
        return new self(404, 'nicht_gefunden', $detail);
    }
}
