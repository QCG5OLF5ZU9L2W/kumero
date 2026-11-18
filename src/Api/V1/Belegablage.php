<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Api\V1;

use Kumero\Plattform\Anfrage;

/**
 * Schnittstelle für DMS-Module, die ein Original annehmen (F110). kumero hält kein Dokument dauerhaft: Ein Modul, das
 * eine Datei vorläufig führt (z. B. das PDF einer eingelesenen E-Rechnung), übergibt sie nach der Buchung an das DMS
 * und löscht seine Kopie, sobald das DMS sie führt. Wer übergibt, kennt kein DMS beim Namen – er ruft
 * `Dienste::belegUebergeben()`.
 *
 * Ein DMS verarbeitet ein Dokument meist nicht sofort. Die Übergabe ist deshalb wiederholbar: Solange das DMS das
 * Original noch nicht führt, kommt null zurück; der Aufrufer fragt später wieder.
 */
interface Belegablage
{
    /** Kann der angemeldete Nutzer jetzt in dieses DMS ablegen (verbunden, entsperrt, Recht vorhanden)? */
    public function belegAblageBereit(Anfrage $anfrage): bool;

    /**
     * Übergibt das Original. Führt das DMS diese Datei schon (erkannt an der Prüfsumme), kommt ihr Belegverweis zurück
     * und es wird nichts gesendet. Sonst wird die Datei gesendet – außer $gesendet ist true, dann wurde sie schon
     * gesendet und das DMS verarbeitet sie noch.
     *
     * @param array{titel?: string, datum?: string} $merkmale Titel und Belegdatum (JJJJ-MM-TT) für das DMS
     * @return ?array{link: string, md5: string, sha256: string} der Belegverweis oder null, solange das DMS die Datei noch nicht führt
     */
    public function belegUebergeben(Anfrage $anfrage, #[\SensitiveParameter] string $inhalt, string $dateiname, array $merkmale, bool $gesendet): ?array;
}
