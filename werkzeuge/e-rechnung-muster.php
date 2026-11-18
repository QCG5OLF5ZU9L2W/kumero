<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

/**
 * Synthetische E-Rechnungen für Tests (F75 Nr. 9: nur erfundene Daten): XRechnung als CII und als UBL sowie ein
 * kleines PDF mit eingebettetem CII-XML im Aufbau von ZUGFeRD / Factur-X. Die Dateien bilden nur nach, was kumero
 * liest – sie sind keine geprüften, gültigen Rechnungen.
 *
 * Aufruf: php werkzeuge/e-rechnung-muster.php <Zielverzeichnis>
 */
final class ERechnungMuster
{
    /** @param array<string, mixed> $o */
    public static function cii(array $o = []): string
    {
        $o += ['nummer' => 'RE-MUSTER-1001', 'typ' => '380', 'datum' => '20260312', 'verkaeufer' => 'Musterlieferant GmbH', 'ustid' => 'DE000000001',
               'kaeufer' => 'Musterpraxis (Test)', 'waehrung' => 'EUR', 'netto' => '100.00', 'steuer' => '19.00', 'brutto' => '119.00', 'zahlart' => '58',
               'profil' => 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0', 'steuern' => [['19.00', 'S', '100.00', '19.00']], 'faellig' => '20260411'];
        $steuern = '';
        foreach ($o['steuern'] as [$satz, $kat, $basis, $betrag]) {
            $steuern .= "<ram:ApplicableTradeTax><ram:CalculatedAmount>$betrag</ram:CalculatedAmount><ram:TypeCode>VAT</ram:TypeCode><ram:BasisAmount>$basis</ram:BasisAmount>"
                . "<ram:CategoryCode>$kat</ram:CategoryCode><ram:RateApplicablePercent>$satz</ram:RateApplicablePercent></ram:ApplicableTradeTax>";
        }
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
  <rsm:ExchangedDocumentContext><ram:GuidelineSpecifiedDocumentContextParameter><ram:ID>{$o['profil']}</ram:ID></ram:GuidelineSpecifiedDocumentContextParameter></rsm:ExchangedDocumentContext>
  <rsm:ExchangedDocument><ram:ID>{$o['nummer']}</ram:ID><ram:TypeCode>{$o['typ']}</ram:TypeCode><ram:IssueDateTime><udt:DateTimeString format="102">{$o['datum']}</udt:DateTimeString></ram:IssueDateTime></rsm:ExchangedDocument>
  <rsm:SupplyChainTradeTransaction>
    <ram:IncludedSupplyChainTradeLineItem><ram:AssociatedDocumentLineDocument><ram:LineID>1</ram:LineID></ram:AssociatedDocumentLineDocument><ram:SpecifiedTradeProduct><ram:Name>Musterartikel [TESTDATEN]</ram:Name></ram:SpecifiedTradeProduct></ram:IncludedSupplyChainTradeLineItem>
    <ram:ApplicableHeaderTradeAgreement>
      <ram:SellerTradeParty><ram:Name>{$o['verkaeufer']}</ram:Name><ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">{$o['ustid']}</ram:ID></ram:SpecifiedTaxRegistration></ram:SellerTradeParty>
      <ram:BuyerTradeParty><ram:Name>{$o['kaeufer']}</ram:Name></ram:BuyerTradeParty>
    </ram:ApplicableHeaderTradeAgreement>
    <ram:ApplicableHeaderTradeDelivery><ram:ActualDeliverySupplyChainEvent><ram:OccurrenceDateTime><udt:DateTimeString format="102">{$o['datum']}</udt:DateTimeString></ram:OccurrenceDateTime></ram:ActualDeliverySupplyChainEvent></ram:ApplicableHeaderTradeDelivery>
    <ram:ApplicableHeaderTradeSettlement>
      <ram:InvoiceCurrencyCode>{$o['waehrung']}</ram:InvoiceCurrencyCode>
      <ram:SpecifiedTradeSettlementPaymentMeans><ram:TypeCode>{$o['zahlart']}</ram:TypeCode><ram:PayeePartyCreditorFinancialAccount><ram:IBANID>DE00000000000000000000</ram:IBANID></ram:PayeePartyCreditorFinancialAccount></ram:SpecifiedTradeSettlementPaymentMeans>
      $steuern
      <ram:SpecifiedTradePaymentTerms><ram:DueDateDateTime><udt:DateTimeString format="102">{$o['faellig']}</udt:DateTimeString></ram:DueDateDateTime></ram:SpecifiedTradePaymentTerms>
      <ram:SpecifiedTradeSettlementHeaderMonetarySummation><ram:LineTotalAmount>{$o['netto']}</ram:LineTotalAmount><ram:TaxBasisTotalAmount>{$o['netto']}</ram:TaxBasisTotalAmount><ram:TaxTotalAmount currencyID="{$o['waehrung']}">{$o['steuer']}</ram:TaxTotalAmount><ram:GrandTotalAmount>{$o['brutto']}</ram:GrandTotalAmount><ram:DuePayableAmount>{$o['brutto']}</ram:DuePayableAmount></ram:SpecifiedTradeSettlementHeaderMonetarySummation>
    </ram:ApplicableHeaderTradeSettlement>
  </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>
XML;
    }

    /** @param array<string, mixed> $o */
    public static function ubl(array $o = []): string
    {
        $o += ['nummer' => 'RE-MUSTER-2002', 'gutschrift' => false, 'datum' => '2026-03-14', 'verkaeufer' => 'Musterlabor AG', 'ustid' => 'DE000000002',
               'kaeufer' => 'Musterpraxis (Test)', 'netto' => '50.00', 'steuer' => '3.50', 'brutto' => '53.50', 'satz' => '7'];
        [$wurzel, $ns, $typ, $zeile, $menge] = $o['gutschrift']
            ? ['CreditNote', 'CreditNote-2', '<cbc:CreditNoteTypeCode>381</cbc:CreditNoteTypeCode>', 'CreditNoteLine', 'CreditedQuantity']
            : ['Invoice', 'Invoice-2', '<cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode>', 'InvoiceLine', 'InvoicedQuantity'];
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<$wurzel xmlns="urn:oasis:names:specification:ubl:schema:xsd:$ns" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:CustomizationID>urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0</cbc:CustomizationID>
  <cbc:ID>{$o['nummer']}</cbc:ID><cbc:IssueDate>{$o['datum']}</cbc:IssueDate>$typ<cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode>
  <cac:AccountingSupplierParty><cac:Party><cac:PartyName><cbc:Name>Handelsname</cbc:Name></cac:PartyName><cac:PartyTaxScheme><cbc:CompanyID>{$o['ustid']}</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme><cac:PartyLegalEntity><cbc:RegistrationName>{$o['verkaeufer']}</cbc:RegistrationName></cac:PartyLegalEntity></cac:Party></cac:AccountingSupplierParty>
  <cac:AccountingCustomerParty><cac:Party><cac:PartyLegalEntity><cbc:RegistrationName>{$o['kaeufer']}</cbc:RegistrationName></cac:PartyLegalEntity></cac:Party></cac:AccountingCustomerParty>
  <cac:PaymentMeans><cbc:PaymentMeansCode>10</cbc:PaymentMeansCode></cac:PaymentMeans>
  <cac:TaxTotal><cbc:TaxAmount currencyID="EUR">{$o['steuer']}</cbc:TaxAmount><cac:TaxSubtotal><cbc:TaxableAmount currencyID="EUR">{$o['netto']}</cbc:TaxableAmount><cbc:TaxAmount currencyID="EUR">{$o['steuer']}</cbc:TaxAmount><cac:TaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>{$o['satz']}</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:TaxCategory></cac:TaxSubtotal></cac:TaxTotal>
  <cac:LegalMonetaryTotal><cbc:LineExtensionAmount currencyID="EUR">{$o['netto']}</cbc:LineExtensionAmount><cbc:TaxExclusiveAmount currencyID="EUR">{$o['netto']}</cbc:TaxExclusiveAmount><cbc:TaxInclusiveAmount currencyID="EUR">{$o['brutto']}</cbc:TaxInclusiveAmount><cbc:PayableAmount currencyID="EUR">{$o['brutto']}</cbc:PayableAmount></cac:LegalMonetaryTotal>
  <cac:$zeile><cbc:ID>1</cbc:ID><cbc:$menge unitCode="C62">1</cbc:$menge><cbc:LineExtensionAmount currencyID="EUR">{$o['netto']}</cbc:LineExtensionAmount><cac:Item><cbc:Name>Musterleistung [TESTDATEN]</cbc:Name></cac:Item></cac:$zeile>
</$wurzel>
XML;
    }

    /** Kleines PDF mit einer Seite und dem XML als eingebetteter, gepackter Datei (Aufbau wie ZUGFeRD / Factur-X). */
    public static function pdf(string $xml, string $name = 'factur-x.xml', bool $packen = true): string
    {
        $daten = $packen ? gzcompress($xml) : $xml;
        $filter = $packen ? '/Filter /FlateDecode ' : '';
        $inhalt = "BT /F1 12 Tf 72 720 Td (Musterrechnung - TESTDATEN) Tj ET";
        $objekte = [
            '<< /Type /Catalog /Pages 2 0 R /Names << /EmbeddedFiles << /Names [(' . $name . ') 6 0 R] >> >> /AF [6 0 R] >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length ' . strlen($inhalt) . " >>\nstream\n$inhalt\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Filespec /F (' . $name . ') /UF (' . $name . ') /AFRelationship /Alternative /EF << /F 7 0 R >> >>',
            '<< /Type /EmbeddedFile /Subtype /text#2Fxml ' . $filter . '/Length ' . strlen($daten) . " >>\nstream\n$daten\nendstream",
        ];
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $stellen = [];
        foreach ($objekte as $n => $o) {
            $stellen[] = strlen($pdf);
            $pdf .= ($n + 1) . " 0 obj\n$o\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objekte) + 1) . "\n0000000000 65535 f \n";
        foreach ($stellen as $s) {
            $pdf .= sprintf("%010d 00000 n \n", $s);
        }
        return $pdf . 'trailer << /Size ' . (count($objekte) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $ziel = $argv[1] ?? null;
    if ($ziel === null || !is_dir($ziel)) {
        fwrite(STDERR, "Aufruf: php werkzeuge/e-rechnung-muster.php <Zielverzeichnis>\n");
        exit(2);
    }
    file_put_contents("$ziel/xrechnung-cii.xml", ERechnungMuster::cii());
    file_put_contents("$ziel/xrechnung-ubl.xml", ERechnungMuster::ubl());
    file_put_contents("$ziel/gutschrift-ubl.xml", ERechnungMuster::ubl(['nummer' => 'GS-MUSTER-3003', 'gutschrift' => true, 'netto' => '20.00', 'steuer' => '1.40', 'brutto' => '21.40']));
    file_put_contents("$ziel/zugferd.pdf", ERechnungMuster::pdf(ERechnungMuster::cii(['nummer' => 'RE-MUSTER-4004', 'verkaeufer' => 'Mustertechnik KG', 'ustid' => 'DE000000004',
        'netto' => '200.00', 'steuer' => '38.00', 'brutto' => '238.00', 'steuern' => [['19.00', 'S', '200.00', '38.00']], 'profil' => 'urn:cen.eu:en16931:2017#conformant#urn:factur-x.eu:1p0:extended'])));
    file_put_contents("$ziel/ohne-rechnung.pdf", str_replace('CrossIndustryInvoice', 'EtwasAnderes', ERechnungMuster::pdf('<a>CrossIndustryInvoice</a>', 'notiz.xml', false)));
    echo "Muster geschrieben nach $ziel\n";
}
