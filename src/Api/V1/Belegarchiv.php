<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Api\V1;

use Kumero\Plattform\Anfrage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Schnittstelle für Module, die ein Belegarchiv (DMS) anbinden (F97). Über sie bekommt jede Seite die
 * Vorschau und die Prüfung eines Belegs – immer dann, wenn ein Belegverweis aus einem angebundenen DMS
 * vorliegt, gleich aus welchem. Seiten und andere Module kennen kein DMS beim Namen.
 * Zuständig ist ein Archiv für alle Beleglinks (F88), die mit einem seiner Präfixe beginnen.
 */
interface Belegarchiv
{
    /**
     * Archive des angemeldeten Nutzers.
     *
     * @return list<array{praefix: string, titel: string, bereit: bool, vorlaeufig?: bool}> bereit = Zugriff jetzt möglich (z. B. entsperrt);
     *         vorlaeufig = das Archiv hält Originale nur, bis sie an das DMS übergeben sind (F110) – solche Links
     *         lässt der Kern an keiner Buchung zu
     */
    public function belegArchive(Anfrage $anfrage): array;

    /** Vorschaubild des Belegs; durchgereicht, nie gespeichert. */
    public function belegVorschau(Anfrage $anfrage, string $link): Response;

    /** Originaldatei des Belegs; durchgereicht, nie gespeichert. */
    public function belegOriginal(Anfrage $anfrage, string $link): Response;

    /**
     * Prüft das Original und liefert den Belegverweis in einheitlicher Schreibweise (F10, F51 Nr. 4).
     *
     * @return array{link: string, md5: string, sha256: string}
     */
    public function belegPruefen(Anfrage $anfrage, string $link): array;
}
