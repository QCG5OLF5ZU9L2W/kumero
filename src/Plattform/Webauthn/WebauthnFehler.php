<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Plattform\Webauthn;

/** Jede fehlgeschlagene WebAuthn-Prüfung. Die Meldung geht nur ins Server-Log. */
final class WebauthnFehler extends \RuntimeException
{
}
