<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Paperless;

/** Fehler beim Gespräch mit Paperless: nur ein Stichwort und der HTTP-Status, nie Inhalte. */
final class PaperlessFehler extends \RuntimeException
{
    public function __construct(public readonly string $grund, public readonly int $status)
    {
        parent::__construct("paperless: $grund ($status)");
    }
}
