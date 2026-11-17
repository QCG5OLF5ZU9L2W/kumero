<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

/**
 * Kleiner Testrahmen ohne Fremdabhängigkeiten: Jede Methode test*() einer
 * Klasse *Test ist ein Test. Nur synthetische Daten (AGENTS.md).
 */
abstract class Rahmen
{
    public int $pruefungen = 0;

    protected function ist(bool $bedingung, string $was): void
    {
        $this->pruefungen++;
        if (!$bedingung) {
            throw new \AssertionError("NICHT OK: $was");
        }
    }

    protected function gleich(mixed $erwartet, mixed $erhalten, string $was): void
    {
        $this->pruefungen++;
        if ($erwartet !== $erhalten) {
            throw new \AssertionError("NICHT OK: $was\n  erwartet: " . var_export($erwartet, true)
                . "\n  erhalten: " . var_export($erhalten, true));
        }
    }

    /** @param class-string<\Throwable> $klasse */
    protected function wirft(string $klasse, callable $f, string $was, ?string $meldungEnthaelt = null): void
    {
        $this->pruefungen++;
        try {
            $f();
        } catch (\Throwable $e) {
            if (!$e instanceof $klasse) {
                throw new \AssertionError("NICHT OK: $was – erwartet $klasse, erhalten " . $e::class . ': ' . $e->getMessage());
            }
            if ($meldungEnthaelt !== null && !str_contains($e->getMessage(), $meldungEnthaelt)) {
                throw new \AssertionError("NICHT OK: $was – Meldung „{$e->getMessage()}“ enthält nicht „{$meldungEnthaelt}“");
            }
            return;
        }
        throw new \AssertionError("NICHT OK: $was – keine Ausnahme");
    }

    public function vorher(): void
    {
    }

    public function nachher(): void
    {
    }
}
