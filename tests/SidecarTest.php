<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Tests;

use Kumero\Modul\Arbeitsvorrat\Dienst;
use Kumero\Modul\Paperless\Sidecar;
use Kumero\Modul\Paperless\Zuordnung;

/**
 * Sidecar von nopaper am Dokument in Paperless (BELEGEINGANG.md, F127). Die Muster sind dem Aufbau nachgebildet, den
 * nopaper 1.8 schreibt (CII, Profil BASIC WL, Herkunftsnachweis als Notiz) – alle Inhalte sind erfunden.
 */
final class SidecarTest extends Rahmen
{
    private const FELDER = [
        'Erkannt: Rechnungsnummer' => ['id' => 12, 'name' => 'Erkannt: Rechnungsnummer', 'data_type' => 'string'],
        'Erkannt: Betrag brutto' => ['id' => 17, 'name' => 'Erkannt: Betrag brutto', 'data_type' => 'monetary'],
        'Erkannt: Sidecar' => ['id' => 30, 'name' => 'Erkannt: Sidecar', 'data_type' => 'longtext'],
    ];
    private const NAMEN = ['korrespondenten' => [], 'typen' => [], 'tags' => [1 => 'zu buchen']];

    /** @param array<string, mixed> $o */
    private static function xml(array $o = []): string
    {
        $o += ['id' => 5210, 'kennung' => 'SCAN-SIDECAR/1.1', 'typ' => '380', 'nummer' => 'RE-0815', 'datum' => '20260227', 'waehrung' => 'EUR',
               'status' => 'ok', 'codes' => 'ZWEITE_LESUNG', 'brutto' => '109.43', 'netto' => '95.00', 'steuer' => '14.43',
               'zeilen' => [['19.00', '65.00', '12.35'], ['7.00', '30.00', '2.08']], 'belegart' => 'rechnung', 'positionen' => ''];
        $steuer = '';
        foreach ($o['zeilen'] as [$satz, $basis, $betrag]) {
            $steuer .= "<ram:ApplicableTradeTax><ram:CalculatedAmount>$betrag</ram:CalculatedAmount><ram:TypeCode>VAT</ram:TypeCode>"
                . "<ram:BasisAmount>$basis</ram:BasisAmount><ram:CategoryCode>S</ram:CategoryCode><ram:RateApplicablePercent>$satz</ram:RateApplicablePercent></ram:ApplicableTradeTax>";
        }
        $notiz = implode("\n", [$o['kennung'], "paperless-document-id={$o['id']}", "belegart={$o['belegart']}", 'sha256-original=' . str_repeat('a', 64),
            'quelle=nachbearbeitung', 'extraktion-software=nopaper 1.8.1', "pruefstatus={$o['status']}", "pruefhinweise={$o['codes']}", 'hinweis=Buchungshilfe, keine E-Rechnung']);
        return '<?xml version=\'1.0\' encoding=\'UTF-8\'?>' . "\n"
            . '<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">'
            . '<rsm:ExchangedDocumentContext><ram:GuidelineSpecifiedDocumentContextParameter><ram:ID>urn:factur-x.eu:1p0:basicwl</ram:ID></ram:GuidelineSpecifiedDocumentContextParameter></rsm:ExchangedDocumentContext>'
            . "<rsm:ExchangedDocument><ram:ID>{$o['nummer']}</ram:ID><ram:TypeCode>{$o['typ']}</ram:TypeCode>"
            . "<ram:IssueDateTime><udt:DateTimeString format=\"102\">{$o['datum']}</udt:DateTimeString></ram:IssueDateTime>"
            . '<ram:IncludedNote><ram:Content>' . htmlspecialchars($notiz, ENT_XML1) . '</ram:Content></ram:IncludedNote></rsm:ExchangedDocument>'
            . '<rsm:SupplyChainTradeTransaction><ram:ApplicableHeaderTradeAgreement><ram:SellerTradeParty><ram:Name>Musterlieferant GmbH &amp; Co. KG</ram:Name>'
            . '<ram:SpecifiedTaxRegistration><ram:ID schemeID="FC">12/345/67890</ram:ID></ram:SpecifiedTaxRegistration>'
            . '<ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">DE000000000</ram:ID></ram:SpecifiedTaxRegistration></ram:SellerTradeParty>'
            . '<ram:BuyerTradeParty><ram:Name>Muster GmbH</ram:Name></ram:BuyerTradeParty></ram:ApplicableHeaderTradeAgreement><ram:ApplicableHeaderTradeDelivery/>'
            . "<ram:ApplicableHeaderTradeSettlement><ram:PaymentReference>KD-4711 RE-0815</ram:PaymentReference><ram:InvoiceCurrencyCode>{$o['waehrung']}</ram:InvoiceCurrencyCode>"
            . '<ram:SpecifiedTradeSettlementPaymentMeans><ram:TypeCode>58</ram:TypeCode><ram:PayeePartyCreditorFinancialAccount><ram:IBANID>DE02120300000000202051</ram:IBANID></ram:PayeePartyCreditorFinancialAccount></ram:SpecifiedTradeSettlementPaymentMeans>'
            . $steuer
            . '<ram:SpecifiedTradePaymentTerms><ram:Description>#SKONTO#TAGE=10#PROZENT=2.00#' . "\n" . '</ram:Description><ram:DueDateDateTime><udt:DateTimeString format="102">20260329</udt:DateTimeString></ram:DueDateDateTime></ram:SpecifiedTradePaymentTerms>'
            . "<ram:SpecifiedTradeSettlementHeaderMonetarySummation><ram:LineTotalAmount>{$o['netto']}</ram:LineTotalAmount><ram:TaxBasisTotalAmount>{$o['netto']}</ram:TaxBasisTotalAmount>"
            . "<ram:TaxTotalAmount currencyID=\"{$o['waehrung']}\">{$o['steuer']}</ram:TaxTotalAmount><ram:GrandTotalAmount>{$o['brutto']}</ram:GrandTotalAmount>"
            . "<ram:DuePayableAmount>{$o['brutto']}</ram:DuePayableAmount></ram:SpecifiedTradeSettlementHeaderMonetarySummation>"
            . '</ram:ApplicableHeaderTradeSettlement>' . $o['positionen'] . '</rsm:SupplyChainTradeTransaction></rsm:CrossIndustryInvoice>' . "\n";
    }

    /** @param list<array{field: int, value: mixed}> $felder */
    private static function dok(array $felder, int $id = 5210): array
    {
        return ['id' => $id, 'title' => 'Rechnung Musterlieferant', 'created' => '2026-03-02T00:00:00+01:00', 'correspondent' => null, 'document_type' => null,
                'tags' => [1], 'custom_fields' => $felder];
    }

    /** Wie beim Abgleich: Zuordnung anwenden, das Ergebnis muss der Arbeitsvorrat annehmen. */
    private function vorschlag(array $dok, ?array $regeln = null): array
    {
        $regeln ??= Zuordnung::standard() + ['belegnummer' => [['art' => 'feld', 'name' => 'Erkannt: Rechnungsnummer']],
            'betrag' => [['art' => 'feld', 'name' => 'Erkannt: Betrag brutto']], 'richtung' => [['art' => 'tag', 'name' => 'zu buchen', 'wert' => 'ausgabe']]];
        [$v, $angaben] = Zuordnung::anwenden($regeln, $dok, self::FELDER, self::NAMEN);
        [, $fehler] = Dienst::pruefen($v + ['betreff' => 'x'], $angaben);
        $this->gleich([], $fehler, 'der Arbeitsvorrat nimmt den Vorschlag an');
        return [$v, $angaben];
    }

    private static function texte(array $v): string
    {
        return json_encode($v['hinweise'] ?? [], JSON_UNESCAPED_UNICODE);
    }

    public function testSidecarGehtDenFeldernVor(): void
    {
        [$v, $angaben] = $this->vorschlag(self::dok([['field' => 12, 'value' => 'RE-0815'], ['field' => 17, 'value' => 'EUR109.43'], ['field' => 30, 'value' => self::xml()]]));
        $this->gleich(['RE-0815', '2026-02-27', 'Musterlieferant GmbH & Co. KG', 'DE000000000', 'rechnung', '109.43', '95.00', '14.43', 'ausgabe'],
            [$v['belegnummer'], $v['datum'], $v['gegenpartei'], $v['gegenpartei_kennung'], $v['art'], $v['betrag'], $v['netto'], $v['steuer'], $v['richtung']],
            'Kopfdaten aus dem Sidecar: Rechnungsdatum statt Dokumentdatum, USt-IdNr. vor Steuernummer; Richtung weiter aus dem Tag');
        $this->gleich([['satz' => '19.00', 'netto' => '65.00', 'steuer' => '12.35'], ['satz' => '7.00', 'netto' => '30.00', 'steuer' => '2.08']], $v['steuerzeilen'], 'Steuer je Satz – das kennen die einzelnen Felder nicht');
        $this->ist(!isset($v['hinweise']), 'Felder und Sidecar stimmen überein, nopaper meldet ok: kein Hinweis');
        $a = array_column($angaben, 'wert', 'bezeichnung');
        $this->gleich(['2026-03-29', '#SKONTO#TAGE=10#PROZENT=2.00#', 'DE02120300000000202051', 'KD-4711 RE-0815', 'gelesen, ohne Beanstandung (nopaper 1.8.1)', 'ZWEITE_LESUNG'],
            [$a['Fällig am'], $a['Skonto'], $a['IBAN des Rechnungsstellers'], $a['Verwendungszweck'], $a['nopaper'], $a['Hinweise von nopaper']], 'Zahlungsangaben und Herkunft stehen neben der Maske');
        $this->ist(!isset($a['Zahlbetrag laut nopaper']), 'Zahlbetrag nur, wenn er vom Betrag abweicht');

        // Jemand hat das Feld in Paperless geändert: vorbelegt bleibt der Sidecar, die Abweichung steht da
        [$v] = $this->vorschlag(self::dok([['field' => 12, 'value' => 'RE-0816'], ['field' => 17, 'value' => 'EUR119.43'], ['field' => 30, 'value' => self::xml()]]));
        $this->gleich(['RE-0815', '109.43'], [$v['belegnummer'], $v['betrag']], 'der Sidecar gilt');
        $this->ist(str_contains(self::texte($v), 'Betrag: Paperless-Feld (119.43) weicht vom Sidecar (109.43) ab') && str_contains(self::texte($v), 'Belegnummer: Paperless-Feld (RE-0816)'), 'Abweichung als Hinweis');

        // Ohne Sidecar bleibt alles wie bisher: die Felder
        [$v, $angaben] = $this->vorschlag(self::dok([['field' => 12, 'value' => 'RE-0816'], ['field' => 17, 'value' => 'EUR119.43']]));
        $this->gleich(['RE-0816', '119.43', '2026-03-02', false], [$v['belegnummer'], $v['betrag'], $v['datum'], isset($v['steuerzeilen'])], 'Rückfall auf die Felder');
        $this->gleich([], $angaben, 'keine Angaben von nopaper');
    }

    public function testPruefstatusUndVorzeichen(): void
    {
        [$v, $angaben] = $this->vorschlag(self::dok([['field' => 30, 'value' => self::xml(['status' => 'pruefen', 'codes' => 'BRUTTO_NICHT_IM_TEXT,ZWEITE_LESUNG'])]]));
        $this->ist(str_contains(self::texte($v), 'nopaper: Beleg prüfen – BRUTTO_NICHT_IM_TEXT,ZWEITE_LESUNG'), 'Prüfstatus „prüfen“ steht mit den Codes als Hinweis am Eintrag');
        $this->gleich('gelesen, zu prüfen (nopaper 1.8.1)', array_column($angaben, 'wert', 'bezeichnung')['nopaper'], 'und neben der Maske');

        // Auszahlungsbon: Beträge negativ, wie gedruckt → ohne Vorzeichen, Richtung umgekehrt
        [$v] = $this->vorschlag(self::dok([['field' => 30, 'value' => self::xml(['brutto' => '-0.77', 'netto' => '-0.72', 'steuer' => '-0.05',
            'zeilen' => [['7.00', '-0.72', '-0.05']], 'belegart' => 'kassenbon'])]]));
        $this->gleich(['0.77', '0.72', '0.05', 'einnahme', 'kassenbon', [['satz' => '7.00', 'netto' => '0.72', 'steuer' => '0.05']]],
            [$v['betrag'], $v['netto'], $v['steuer'], $v['richtung'], $v['art'], $v['steuerzeilen']], 'negativer Betrag: als Einnahme vorbelegt');
        $this->ist(str_contains(self::texte($v), 'negativen Betrag'), 'mit Hinweis');
        // dasselbe ohne Sidecar, nur aus dem Feld
        [$v] = $this->vorschlag(self::dok([['field' => 17, 'value' => 'EUR-0.77']]));
        $this->gleich(['0.77', 'einnahme'], [$v['betrag'], $v['richtung']], 'auch ein negatives Feld kehrt die Richtung um');
        // Vorzeichen, die nicht zusammenpassen, werden nicht gedeutet
        [$v] = $this->vorschlag(self::dok([['field' => 30, 'value' => self::xml(['netto' => '-95.00'])]]));
        $this->gleich(['109.43', false, 'ausgabe', false], [$v['betrag'], isset($v['netto']), $v['richtung'], isset($v['steuerzeilen']) && $v['steuerzeilen'][0]['netto'] !== '65.00'], 'abweichendes Vorzeichen entfällt');
        $this->ist(str_contains(self::texte($v), 'Vorzeichen passt nicht'), 'mit Hinweis');

        // Gutschrift: Art gesetzt, Richtung nicht geraten
        [$v] = $this->vorschlag(self::dok([['field' => 30, 'value' => self::xml(['typ' => '381', 'belegart' => 'gutschrift'])]]));
        $this->gleich(['gutschrift', 'ausgabe'], [$v['art'], $v['richtung']], 'Gutschrift mit positivem Betrag: Richtung bleibt');
        $this->ist(str_contains(self::texte($v), 'Gutschrift – Richtung der Buchung prüfen'), 'Hinweis zur Richtung');
        // fremde Währung: keine Beträge
        [$v] = $this->vorschlag(self::dok([['field' => 30, 'value' => self::xml(['waehrung' => 'USD'])]]));
        $this->gleich([false, false, 'RE-0815'], [isset($v['betrag']), isset($v['steuerzeilen']), $v['belegnummer']], 'Beträge in fremder Währung werden nicht übernommen, die Kopfdaten schon');
    }

    public function testWasNichtVerwendetWird(): void
    {
        $feld = [['field' => 17, 'value' => 'EUR119.43']];
        // gehört zu einem anderen Dokument (Feld kopiert oder vertauscht)
        [$v, $angaben] = $this->vorschlag(self::dok([...$feld, ['field' => 30, 'value' => self::xml(['id' => 4711])]]));
        $this->gleich(['119.43', false, []], [$v['betrag'], isset($v['belegnummer']), $angaben], 'fremder Sidecar: nichts daraus, die Felder gelten');
        $this->ist(str_contains(self::texte($v), 'gehört zu einem anderen Dokument'), 'mit Grund');
        // unbekannte Fassung, kaputtes XML, DOCTYPE
        foreach (['unbekannte Fassung' => self::xml(['kennung' => 'SCAN-SIDECAR/2.0']),
                  'kein lesbares XML' => substr(self::xml(), 0, 900),
                  'unzulässigen Bestandteilen' => str_replace('<rsm:CrossIndustryInvoice', '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/hostname">]><rsm:CrossIndustryInvoice', self::xml())] as $grund => $xml) {
            [$v] = $this->vorschlag(self::dok([...$feld, ['field' => 30, 'value' => $xml]]));
            $this->ist($v['betrag'] === '119.43' && !isset($v['datum_sidecar']) && str_contains(self::texte($v), $grund), "nicht verwendet: $grund");
        }
        // ein langer Text ohne Kennung ist kein Sidecar
        $this->gleich(null, Sidecar::lesen(self::dok([['field' => 30, 'value' => '<rsm:CrossIndustryInvoice/>']])), 'ohne Kennung kein Sidecar');
        // Steuerzeilen nur vollständig
        [$v] = $this->vorschlag(self::dok([['field' => 30, 'value' => str_replace('<ram:BasisAmount>30.00</ram:BasisAmount>', '', self::xml())]]));
        $this->gleich([false, '14.43'], [isset($v['steuerzeilen']), $v['steuer']], 'fehlt in einer Steuerzeile die Basis, entfällt die Aufteilung – die Summen bleiben');
    }

    /** Eine Position im Aufbau von CII; alle Inhalte erfunden. */
    private static function position(string $nr, string $name, string $menge, string $preis, string $netto, string $mehr = ''): string
    {
        return "<ram:IncludedSupplyChainTradeLineItem><ram:AssociatedDocumentLineDocument><ram:LineID>$nr</ram:LineID></ram:AssociatedDocumentLineDocument>"
            . "<ram:SpecifiedTradeProduct>$mehr<ram:Name>$name</ram:Name></ram:SpecifiedTradeProduct>"
            . "<ram:SpecifiedLineTradeAgreement><ram:NetPriceProductTradePrice><ram:ChargeAmount>$preis</ram:ChargeAmount></ram:NetPriceProductTradePrice></ram:SpecifiedLineTradeAgreement>"
            . "<ram:SpecifiedLineTradeDelivery><ram:BilledQuantity unitCode=\"C62\">$menge</ram:BilledQuantity></ram:SpecifiedLineTradeDelivery>"
            . "<ram:SpecifiedLineTradeSettlement><ram:ApplicableTradeTax><ram:TypeCode>VAT</ram:TypeCode><ram:CategoryCode>S</ram:CategoryCode><ram:RateApplicablePercent>19.00</ram:RateApplicablePercent></ram:ApplicableTradeTax>"
            . "<ram:SpecifiedTradeSettlementLineMonetarySummation><ram:LineTotalAmount>$netto</ram:LineTotalAmount></ram:SpecifiedTradeSettlementLineMonetarySummation></ram:SpecifiedLineTradeSettlement>"
            . '</ram:IncludedSupplyChainTradeLineItem>';
    }

    public function testPositionen(): void
    {
        // ohne Positionen (so schreibt nopaper heute): kein Feld, kein Hinweis
        [$v] = $this->vorschlag(self::dok([['field' => 30, 'value' => self::xml()]]));
        $this->gleich(false, isset($v['positionen']), 'Sidecar ohne Positionen: nichts vorbelegt');

        $pos = self::position('1', 'Musterspritze 5 ml [TESTDATEN]', '4.0000', '10.0000', '40.00',
                '<ram:GlobalID schemeID="0160">04012345678901</ram:GlobalID><ram:SellerAssignedID>ML-10450</ram:SellerAssignedID>')
            . self::position('2', 'Musterauflage steril [TESTDATEN]', '2', '27.50', '55.00',
                '<ram:SellerAssignedID>ML-22017</ram:SellerAssignedID><ram:IndividualTradeProductInstance><ram:BatchID>T26A0001</ram:BatchID></ram:IndividualTradeProductInstance>');
        [$v] = $this->vorschlag(self::dok([['field' => 30, 'value' => self::xml(['positionen' => $pos])]]));
        $sortiert = fn (array $l) => array_map(function (array $p) { ksort($p); return $p; }, $l);
        $this->gleich($sortiert([
            ['nr' => '1', 'bezeichnung' => 'Musterspritze 5 ml [TESTDATEN]', 'artikelnummer' => 'ML-10450', 'menge' => '4', 'einheit' => 'C62', 'einzelpreis' => '10', 'netto' => '40.00', 'satz' => '19', 'gtin' => '04012345678901'],
            ['nr' => '2', 'bezeichnung' => 'Musterauflage steril [TESTDATEN]', 'artikelnummer' => 'ML-22017', 'charge' => 'T26A0001', 'menge' => '2', 'einheit' => 'C62', 'einzelpreis' => '27.5', 'netto' => '55.00', 'satz' => '19'],
        ]), $sortiert($v['positionen']), 'Positionen mit Menge, Preis, Artikelnummer, GTIN und Charge');
        $this->ist(!str_contains(self::texte($v), 'Positionen ergeben'), 'Summe der Positionen 95,00 = netto: kein Hinweis');
        $this->gleich([], Dienst::pruefen($v)[1], 'der Arbeitsvorrat nimmt die Positionen an');

        // Summe passt nicht → Hinweis, die Positionen bleiben ein Vorschlag
        [$v] = $this->vorschlag(self::dok([['field' => 30, 'value' => self::xml(['positionen' => self::position('1', 'Musterspritze [TESTDATEN]', '4', '10', '40.00')])]]));
        $this->ist(count($v['positionen']) === 1 && str_contains(self::texte($v), 'Positionen ergeben 40,00, der Beleg nennt netto 95,00'), 'Summe weicht ab: Hinweis');
        // unlesbare Position (ohne Bezeichnung) wird ausgelassen und gemeldet; ein unlesbarer Betrag entfällt
        [$v] = $this->vorschlag(self::dok([['field' => 30, 'value' => self::xml(['positionen' => self::position('1', '', '1', '1', '1.00') . self::position('2', 'Musterartikel [TESTDATEN]', 'vier', '10', '95.00')])]]));
        $this->gleich([1, false, '95.00'], [count($v['positionen']), isset($v['positionen'][0]['menge']), $v['positionen'][0]['netto']], 'ohne Bezeichnung ausgelassen, unlesbare Menge entfällt');
        $this->ist(str_contains(self::texte($v), '1 Position(en) nicht lesbar'), 'mit Hinweis');
        // negativer Beleg: Vorzeichen der Positionen folgen dem Betrag
        [$v] = $this->vorschlag(self::dok([['field' => 30, 'value' => self::xml(['brutto' => '-0.77', 'netto' => '-0.72', 'steuer' => '-0.05', 'zeilen' => [['7.00', '-0.72', '-0.05']],
            'positionen' => self::position('1', 'Musterpfand [TESTDATEN]', '-3', '0.24', '-0.72')])]]));
        $this->gleich(['-3', '0.72', 'einnahme'], [$v['positionen'][0]['menge'], $v['positionen'][0]['netto'], $v['richtung']], 'negativer Beleg: Betrag ohne Vorzeichen, Menge wie gedruckt (Rückgabe), Richtung umgekehrt');
        // fremde Währung: keine Positionen
        [$v] = $this->vorschlag(self::dok([['field' => 30, 'value' => self::xml(['waehrung' => 'USD', 'positionen' => $pos])]]));
        $this->gleich(false, isset($v['positionen']), 'fremde Währung: keine Positionen');

        // strenge Prüfung beim Einliefern
        $f = Dienst::pruefen(['betreff' => 'x', 'positionen' => [['bezeichnung' => 'a', 'menge' => '1,5'], ['menge' => '1'], ['bezeichnung' => 'b', 'fremd' => 'x'], ['bezeichnung' => 'c', 'gtin' => '0401234567890', 'verfall' => '2027-02-28']]]);
        $this->gleich(['positionen[0]', 'positionen[1]', 'positionen[2]'], array_column($f[1], 'feld'), 'Einliefern: Komma, fehlende Bezeichnung und fremdes Feld werden abgewiesen');
        $this->gleich([['bezeichnung' => 'c', 'gtin' => '0401234567890', 'verfall' => '2027-02-28']], $f[0]['positionen'], 'gültige Position bleibt');
    }
}
