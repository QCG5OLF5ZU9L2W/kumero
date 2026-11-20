<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Personal;

use Doctrine\DBAL\Connection;
use Kumero\Api\V1\Mandantenauskunft;
use Kumero\Api\V1\RoutenAnbieter;

/**
 * Einstieg des Moduls personal (PERSONAL.md Stufe 1 bis 3, F136, F138, F140): Personen, Verträge, Fristen und Nachweise, Abwesenheiten,
 * Zuschläge und Lohnvorbereitung am Mandanten.
 * Das Modul gehört zum Mandanten, nicht zu einem Buch (F135). Es führt keine Vergütung und keine Lohnmerkmale, rechnet
 * keinen Lohn und ruft nichts außerhalb ab. Module eines Buchs bekommen von ihm nur ID und Anzeigenamen von Personen.
 */
final class Modul implements RoutenAnbieter, Mandantenauskunft
{
    public function routen(): array
    {
        $w = new WebController();
        $ab = new AbwesenheitController();
        $lo = new LohnController();
        $m = fn (string $name, string $methode, string $pfad, callable $h, string $recht = 'personal.liste.sehen') =>
            ['name' => $name, 'methode' => $methode, 'pfad' => "/personal$pfad", 'handler' => $h, 'recht' => $recht, 'mandant' => true];
        return [
            $m('stand', 'GET', '', $w->stand(...)),
            $m('akte', 'GET', '/personen/{id}', $w->akte(...)),
            $m('person.anlegen', 'POST', '/personen', $w->personAnlegen(...), 'personal.person.pflegen'),
            $m('person.aendern', 'PUT', '/personen/{id}', $w->personAendern(...), 'personal.person.pflegen'),
            $m('vertrag.anlegen', 'POST', '/personen/{id}/vertraege', $w->vertragAnlegen(...), 'personal.vertrag.pflegen'),
            $m('auswahl', 'GET', '/auswahl', $w->auswahl(...), 'personal.person.pflegen'),
            $m('fristen', 'GET', '/fristen', $w->fristen(...)),
            $m('zaehler', 'GET', '/zaehler', $w->zaehler(...)),
            $m('frist.anlegen', 'POST', '/fristen', $w->fristAnlegen(...), 'personal.frist.pflegen'),
            $m('frist.erledigen', 'POST', '/fristen/{id}/erledigt', $w->fristErledigen(...), 'personal.frist.pflegen'),
            $m('fristart.anlegen', 'POST', '/fristarten', $w->fristartSetzen(...), 'personal.frist.pflegen'),
            $m('fristart.aendern', 'PUT', '/fristarten/{id}', $w->fristartSetzen(...), 'personal.frist.pflegen'),
            $m('einstellung', 'PUT', '/einstellung', $w->einstellung(...), 'personal.frist.pflegen'),
            $m('meine', 'GET', '/meine', $w->meine(...), 'personal.eigenes.sehen'),
            $m('meine.vorschlag', 'POST', '/meine/vorschlaege', $w->vorschlagen(...), 'personal.eigenes.vorschlagen'),
            $m('vorschlag.entscheiden', 'POST', '/vorschlaege/{id}/entscheidung', $w->vorschlagEntscheiden(...), 'personal.person.pflegen'),
            $m('loeschfrist', 'PUT', '/loeschfrist', $w->loeschfrist(...), 'personal.person.pflegen'),
            // Abwesenheiten (Stufe 2, F138)
            $m('abwesenheiten', 'GET', '/abwesenheiten', $ab->stand(...)),
            $m('abwesenheiten.zaehler', 'GET', '/abwesenheiten/zaehler', $ab->zaehler(...)),
            $m('abwesenheit.eintragen', 'POST', '/abwesenheiten', $ab->eintragen(...), 'personal.abwesenheit.pflegen'),
            $m('abwesenheit.entscheiden', 'POST', '/abwesenheiten/{id}/entscheidung', $ab->entscheiden(...), 'personal.abwesenheit.genehmigen'),
            $m('abwesenheit.zuruecknehmen', 'POST', '/abwesenheiten/{id}/ruecknahme', $ab->zuruecknehmen(...), 'personal.abwesenheit.pflegen'),
            $m('abwesenheit.ende', 'PUT', '/abwesenheiten/{id}/ende', $ab->endeSetzen(...), 'personal.abwesenheit.pflegen'),
            $m('abwesenheit.nachweis', 'PUT', '/abwesenheiten/{id}/nachweis', $ab->nachweis(...), 'personal.abwesenheit.pflegen'),
            $m('abwesenheitsart.anlegen', 'POST', '/abwesenheitsarten', $ab->artSetzen(...), 'personal.abwesenheit.pflegen'),
            $m('abwesenheitsart.aendern', 'PUT', '/abwesenheitsarten/{id}', $ab->artSetzen(...), 'personal.abwesenheit.pflegen'),
            $m('freier_tag', 'PUT', '/freie-tage/{datum}', $ab->freierTag(...), 'personal.abwesenheit.pflegen'),
            $m('urlaubskonto', 'PUT', '/urlaubskonten/{person}/{jahr}', $ab->kontoSetzen(...), 'personal.abwesenheit.pflegen'),
            $m('abwesenheit.tage', 'PUT', '/abwesenheiten/{id}/tage', $ab->tageSetzen(...), 'personal.abwesenheit.pflegen'),
            $m('arbeitsort.anlegen', 'POST', '/arbeitsorte', $ab->arbeitsortSetzen(...), 'personal.abwesenheit.pflegen'),
            $m('arbeitsort.aendern', 'PUT', '/arbeitsorte/{id}', $ab->arbeitsortSetzen(...), 'personal.abwesenheit.pflegen'),
            $m('feiertag.anlegen', 'POST', '/feiertage', $ab->feiertagSetzen(...), 'personal.abwesenheit.pflegen'),
            $m('feiertage.vorgabe', 'POST', '/feiertage/vorgabe', $ab->feiertageVorgabe(...), 'personal.abwesenheit.pflegen'),
            $m('feiertag.aendern', 'PUT', '/feiertage/{feiertag}', $ab->feiertagSetzen(...), 'personal.abwesenheit.pflegen'),
            $m('urlaubsregel', 'PUT', '/urlaubsregeln/{jahr}', $ab->urlaubsregelSetzen(...), 'personal.abwesenheit.pflegen'),
            $m('meine.abwesenheit', 'POST', '/meine/abwesenheiten', $ab->beantragen(...), 'personal.eigenes.beantragen'),
            $m('meine.abwesenheit.zuruecknehmen', 'POST', '/meine/abwesenheiten/{id}/ruecknahme', $ab->selbstZuruecknehmen(...), 'personal.eigenes.beantragen'),
            // Zuschläge und Lohnvorbereitung (Stufe 3, F140)
            $m('lohn', 'GET', '/lohn', $lo->stand(...), 'personal.lohn.sehen'),
            $m('lohn.pruefen', 'POST', '/lohn/{monat}/{person}/pruefung', $lo->pruefen(...), 'personal.lohn.pruefen'),
            $m('lohn.oeffnen', 'POST', '/lohn/{monat}/{person}/oeffnung', $lo->oeffnen(...), 'personal.lohn.pruefen'),
            $m('lohn.bemerkung', 'PUT', '/lohn/{monat}/{person}/bemerkung', $lo->bemerkung(...), 'personal.lohn.pruefen'),
            $m('lohn.uebergeben', 'POST', '/lohn/{monat}/{person}/uebergabe', $lo->uebergeben(...), 'personal.lohn.uebergeben'),
            $m('lohn.fassung', 'POST', '/lohn/{monat}/{person}/fassungen', $lo->neueFassung(...), 'personal.lohn.uebergeben'),
            $m('zuschlag.anlegen', 'POST', '/zuschlaege', $lo->zuschlagAnlegen(...), 'personal.zuschlag.pflegen'),
            $m('zuschlag.zuruecknehmen', 'POST', '/zuschlaege/{id}/ruecknahme', $lo->zuschlagZuruecknehmen(...), 'personal.zuschlag.pflegen'),
            $m('zuschlagsregel.anlegen', 'POST', '/zuschlagsregeln', $lo->regelSetzen(...), 'personal.zuschlag.pflegen'),
            $m('zuschlagsregel.aendern', 'PUT', '/zuschlagsregeln/{id}', $lo->regelSetzen(...), 'personal.zuschlag.pflegen'),
            $m('zuschlagsregel.fassung', 'POST', '/zuschlagsregeln/{id}/fassungen', $lo->fassungAnlegen(...), 'personal.zuschlag.pflegen'),
            $m('zuschlagsregel.zuordnen', 'POST', '/zuschlagsregeln/{id}/zuordnungen', $lo->zuordnen(...), 'personal.zuschlag.pflegen'),
            $m('zuschlag.zuordnung.beenden', 'PUT', '/zuschlagszuordnungen/{id}/ende', $lo->zuordnungBeenden(...), 'personal.zuschlag.pflegen'),
        ];
    }

    /** Auskunft an Module eines Buchs: „personen“ – wer zur Auswahl steht (nicht ausgeschieden) bzw. die genannten. */
    public function mandantenauskunft(Connection $mandantDb, string $gegenstand, array $ids): array
    {
        if ($gegenstand !== 'personen') {
            return [];
        }
        $ids = array_values(array_filter($ids, fn (string $i) => (bool) preg_match(WebController::UUID, $i)));
        $zeilen = $ids === []
            ? $mandantDb->fetchAllAssociative('SELECT id, familienname, vornamen FROM personal.person WHERE austritt IS NULL OR austritt >= current_date ORDER BY lower(familienname), lower(coalesce(vornamen, \'\')), id')
            : $mandantDb->fetchAllAssociative('SELECT id, familienname, vornamen FROM personal.person WHERE id = ANY(?::uuid[]) ORDER BY lower(familienname), id', ['{' . implode(',', $ids) . '}']);
        return array_map(fn (array $z) => ['id' => (string) $z['id'], 'name' => WebController::name($z)], $zeilen);
    }
}
