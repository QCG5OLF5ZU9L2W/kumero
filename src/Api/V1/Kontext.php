<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Api\V1;

/**
 * Kontext eines Aufrufs (ANWENDUNG.md 7.2). Teil der stabilen Modul-API.
 */
final class Kontext
{
    /** @param array<string, ?list<array<string, mixed>>> $rechte Recht → Einschränkungen (null = uneingeschränkt) */
    public function __construct(
        public readonly string $subjekt,
        public readonly ?string $mandant,
        public readonly string $sprache,
        public readonly ?string $betriebsart,
        public readonly bool $lesemodus,
        public readonly string $anfrageId,
        private readonly array $rechte = [],
        /** Kennung des Buchs; null auf der Ebene Mandant und in der Installation (BUECHER.md A13) */
        public readonly ?string $buch = null,
    ) {
    }

    public function darf(string $recht): bool
    {
        return array_key_exists($recht, $this->rechte);
    }

    /** @return ?list<array<string, mixed>> Einschränkungen oder null, wenn uneingeschränkt */
    public function einschraenkungen(string $recht): ?array
    {
        return $this->rechte[$recht] ?? null;
    }

    /** @return list<string> */
    public function rechte(): array
    {
        return array_keys($this->rechte);
    }
}
