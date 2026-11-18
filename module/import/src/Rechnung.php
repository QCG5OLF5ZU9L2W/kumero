<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Import;

/**
 * E-Rechnungen lesen (IMPORT.md Abschnitt 15, F108): strukturierte Rechnungen nach EN 16931 –
 * XRechnung als XML in beiden Syntaxen (UN/CEFACT CII und UBL) und ZUGFeRD / Factur-X als PDF mit eingebettetem
 * CII-XML. Gelesen werden die Kopfdaten und Summen; daraus wird ein Eintrag des Arbeitsvorrats.
 *
 * Die Klasse rechnet nichts nach und prüft keine Rechnung auf Gültigkeit (keine Schematron-Prüfung): Sie
 * übernimmt, was dasteht, und nennt, was fehlt oder nicht zusammenpasst. Reine Funktionen, ohne Datenbank.
 */
final class Rechnung
{
    public const HOECHSTENS_BYTES = 5242880;
    /** Entpackt wird höchstens so viel – Schutz vor gepackten Riesen im PDF */
    private const HOECHSTENS_ENTPACKT = 20971520;

    private const NS_CII = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    private const NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    private const NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';
    private const NS_UBL_RECHNUNG = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    private const NS_UBL_GUTSCHRIFT = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';
    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    /** Rechnungsarten nach UNTDID 1001, die eine Gutschrift sind (drehen die Richtung) */
    private const GUTSCHRIFTEN = ['381', '261', '262', '296', '308', '396', '420', '458', '532'];
    private const KORREKTUREN = ['384'];

    /**
     * Liest eine Datei: XML (CII oder UBL) oder PDF mit eingebettetem XML.
     *
     * @return array<string, mixed> die Rechnung in einheitlicher Form, dazu `behaelter` (xml|pdf)
     * @throws \InvalidArgumentException mit einem Text für den Nutzer, wenn die Datei keine lesbare E-Rechnung ist
     */
    public static function lesen(string $roh): array
    {
        if ($roh === '') {
            throw new \InvalidArgumentException('Die Datei ist leer');
        }
        if (strlen($roh) > self::HOECHSTENS_BYTES) {
            throw new \InvalidArgumentException('Die Datei ist größer als 5 MB');
        }
        if (str_starts_with(ltrim(substr($roh, 0, 1024)), '%PDF-')) {
            return ['behaelter' => 'pdf'] + self::ausXml(self::xmlAusPdf($roh));
        }
        return ['behaelter' => 'xml'] + self::ausXml($roh);
    }

    /**
     * Holt das eingebettete Rechnungs-XML aus einem PDF (ZUGFeRD, Factur-X, XRechnung im PDF).
     * Kein PDF-Leser: Es werden nur die Datenströme der Datei durchgesehen und der genommen, der eine Rechnung enthält.
     */
    public static function xmlAusPdf(string $pdf): string
    {
        $ende = strlen($pdf);
        $pos = 0;
        $gefunden = null;
        $stroeme = 0;
        while (($s = strpos($pdf, 'stream', $pos)) !== false) {
            $pos = $s + 6;
            // „endstream“ enthält das Wort auch
            if ($s >= 3 && substr($pdf, $s - 3, 3) === 'end') {
                continue;
            }
            $start = $s + 6;
            if (substr($pdf, $start, 2) === "\r\n") {
                $start += 2;
            } elseif (($pdf[$start] ?? '') === "\n") {
                $start += 1;
            } else {
                continue;
            }
            // das Wörterbuch des Objekts steht zwischen „obj“ und „stream“
            $anfang = strrpos(substr($pdf, max(0, $s - 4096), min(4096, $s)), 'obj');
            $kopf = $anfang === false ? '' : substr($pdf, max(0, $s - 4096) + $anfang, $s - (max(0, $s - 4096) + $anfang));
            $schluss = false;
            if (preg_match('/\/Length\s+(\d+)(?!\s+\d+\s+R)/', $kopf, $m) && $start + (int) $m[1] <= $ende
                && preg_match('/\G\s{0,2}endstream/', $pdf, $x, 0, $start + (int) $m[1])) {
                $schluss = $start + (int) $m[1];
            }
            if ($schluss === false) {
                $schluss = strpos($pdf, 'endstream', $start);
                if ($schluss === false) {
                    break;
                }
            }
            $pos = $schluss + 9;
            // Bilder und Schriften sind keine Rechnung
            if (preg_match('/\/Subtype\s*\/Image|\/DCTDecode|\/JPXDecode|\/CCITTFaxDecode|\/FontFile/', $kopf)) {
                continue;
            }
            if (++$stroeme > 5000) {
                break;
            }
            $daten = substr($pdf, $start, $schluss - $start);
            if (str_contains($kopf, '/FlateDecode')) {
                $entpackt = @gzuncompress($daten, self::HOECHSTENS_ENTPACKT);
                if ($entpackt === false) {
                    $entpackt = @gzinflate($daten, self::HOECHSTENS_ENTPACKT);
                }
                if ($entpackt === false) {
                    continue;
                }
                $daten = $entpackt;
            } elseif (preg_match('/\/Filter/', $kopf)) {
                continue;
            }
            $probe = ltrim(substr($daten, 0, 4096), "\xEF\xBB\xBF \t\r\n");
            if (!str_starts_with($probe, '<')) {
                continue;
            }
            if (str_contains($daten, 'CrossIndustryInvoice') || str_contains($daten, self::NS_UBL_RECHNUNG) || str_contains($daten, self::NS_UBL_GUTSCHRIFT)) {
                return rtrim($daten, "\r\n\0 ");
            }
            if (str_contains($daten, 'CrossIndustryDocument')) {
                $gefunden = 'zugferd1';
            }
        }
        if ($gefunden === 'zugferd1') {
            throw new \InvalidArgumentException('Das PDF enthält eine Rechnung nach ZUGFeRD 1.0 – gelesen werden ZUGFeRD ab 2.0, Factur-X und XRechnung');
        }
        if (preg_match('/\/Encrypt\s+\d+\s+\d+\s+R/', substr($pdf, max(0, $ende - 4096)))) {
            throw new \InvalidArgumentException('Das PDF ist verschlüsselt – das eingebettete Rechnungs-XML lässt sich nicht lesen');
        }
        throw new \InvalidArgumentException('Das PDF enthält kein eingebettetes Rechnungs-XML – es ist keine ZUGFeRD- oder Factur-X-Rechnung');
    }

    /** @return array<string, mixed> */
    private static function ausXml(string $xml): array
    {
        $xml = ltrim($xml, "\xEF\xBB\xBF \t\r\n");
        if (!str_starts_with($xml, '<')) {
            throw new \InvalidArgumentException('Die Datei ist weder XML noch PDF – erwartet wird eine E-Rechnung (XRechnung, ZUGFeRD, Factur-X)');
        }
        // Keine Dokumenttyp-Angaben: Damit gibt es keine Entitäten, die etwas nachladen oder aufblähen könnten
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $xml)) {
            throw new \InvalidArgumentException('Das XML enthält eine Dokumenttyp-Angabe – solche Dateien werden nicht gelesen');
        }
        $d = new \DOMDocument();
        $vorher = libxml_use_internal_errors(true);
        $ok = $d->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);
        if (!$ok || $d->documentElement === null) {
            throw new \InvalidArgumentException('Das XML ist nicht wohlgeformt');
        }
        $wurzel = $d->documentElement;
        $x = new \DOMXPath($d);
        if ($wurzel->localName === 'CrossIndustryInvoice' && $wurzel->namespaceURI === self::NS_CII) {
            return self::ausCii($x);
        }
        if (in_array($wurzel->localName, ['Invoice', 'CreditNote'], true) && in_array($wurzel->namespaceURI, [self::NS_UBL_RECHNUNG, self::NS_UBL_GUTSCHRIFT], true)) {
            return self::ausUbl($x, $wurzel->localName === 'CreditNote');
        }
        if ($wurzel->localName === 'CrossIndustryDocument') {
            throw new \InvalidArgumentException('Das ist eine Rechnung nach ZUGFeRD 1.0 – gelesen werden ZUGFeRD ab 2.0, Factur-X und XRechnung');
        }
        throw new \InvalidArgumentException('Das XML ist keine E-Rechnung nach EN 16931 (erwartet: CrossIndustryInvoice oder UBL Invoice / CreditNote)');
    }

    /** UN/CEFACT Cross Industry Invoice: ZUGFeRD ab 2.0, Factur-X, XRechnung (CII). @return array<string, mixed> */
    private static function ausCii(\DOMXPath $x): array
    {
        $x->registerNamespace('rsm', self::NS_CII);
        $x->registerNamespace('ram', self::NS_RAM);
        $x->registerNamespace('udt', self::NS_UDT);
        $t = fn (string $pfad, ?\DOMNode $von = null) => self::text($x, $pfad, $von);
        $kopf = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction';
        $v = "$kopf/ram:ApplicableHeaderTradeAgreement";
        $s = "$kopf/ram:ApplicableHeaderTradeSettlement";
        $sum = "$s/ram:SpecifiedTradeSettlementHeaderMonetarySummation";
        $waehrung = strtoupper($t("$s/ram:InvoiceCurrencyCode"));
        // Die Steuersumme kann zweimal dastehen (Rechnungs- und Buchwährung): die in Rechnungswährung zählt
        $steuer = $t("$sum/ram:TaxTotalAmount[@currencyID='$waehrung']");
        if ($steuer === '') {
            $steuer = $t("$sum/ram:TaxTotalAmount");
        }
        $partei = fn (string $p) => [
            'name' => $t("$v/$p/ram:Name"),
            'ustid' => $t("$v/$p/ram:SpecifiedTaxRegistration/ram:ID[@schemeID='VA']"),
            'steuernummer' => $t("$v/$p/ram:SpecifiedTaxRegistration/ram:ID[@schemeID='FC']"),
        ];
        $steuern = [];
        foreach ($x->query("$s/ram:ApplicableTradeTax") ?: [] as $k) {
            $steuern[] = ['satz' => $t('ram:RateApplicablePercent', $k), 'kategorie' => $t('ram:CategoryCode', $k),
                          'basis' => $t('ram:BasisAmount', $k), 'betrag' => $t('ram:CalculatedAmount', $k)];
        }
        $positionen = $x->query("$kopf/ram:IncludedSupplyChainTradeLineItem") ?: [];
        $namen = [];
        foreach ($positionen as $p) {
            if (count($namen) < 5 && ($n = $t('ram:SpecifiedTradeProduct/ram:Name', $p)) !== '') {
                $namen[] = $n;
            }
        }
        return [
            'syntax' => 'cii',
            'profil' => $t('/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID'),
            'nummer' => $t('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID'),
            'typ' => $t('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode'),
            'datum' => self::datum($t('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString')),
            'faellig' => self::datum($t("$s/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString")),
            'lieferdatum' => self::datum($t("$kopf/ram:ApplicableHeaderTradeDelivery/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString")),
            'waehrung' => $waehrung,
            'verkaeufer' => $partei('ram:SellerTradeParty'),
            'kaeufer' => $partei('ram:BuyerTradeParty'),
            'netto' => $t("$sum/ram:TaxBasisTotalAmount"),
            'steuer' => $steuer,
            'brutto' => $t("$sum/ram:GrandTotalAmount"),
            'vorausgezahlt' => $t("$sum/ram:TotalPrepaidAmount"),
            'zahlbetrag' => $t("$sum/ram:DuePayableAmount"),
            'zahlart' => $t("$s/ram:SpecifiedTradeSettlementPaymentMeans/ram:TypeCode"),
            'iban' => $t("$s/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:IBANID"),
            'steuern' => $steuern,
            'positionen' => count($positionen),
            'positionsnamen' => $namen,
        ];
    }

    /** UBL 2.1 Invoice / CreditNote: XRechnung (UBL), Peppol BIS Billing. @return array<string, mixed> */
    private static function ausUbl(\DOMXPath $x, bool $gutschrift): array
    {
        $x->registerNamespace('cbc', self::NS_CBC);
        $x->registerNamespace('cac', self::NS_CAC);
        $t = fn (string $pfad, ?\DOMNode $von = null) => self::text($x, $pfad, $von);
        $w = '/*';
        $partei = function (string $p) use ($t, $w): array {
            $name = $t("$w/$p/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName");
            $ustid = $t("$w/$p/cac:Party/cac:PartyTaxScheme[cac:TaxScheme/cbc:ID='VAT']/cbc:CompanyID");
            return ['name' => $name !== '' ? $name : $t("$w/$p/cac:Party/cac:PartyName/cbc:Name"), 'ustid' => $ustid,
                    'steuernummer' => $t("$w/$p/cac:Party/cac:PartyTaxScheme[cac:TaxScheme/cbc:ID!='VAT']/cbc:CompanyID")];
        };
        $steuern = [];
        foreach ($x->query("$w/cac:TaxTotal[1]/cac:TaxSubtotal") ?: [] as $k) {
            $steuern[] = ['satz' => $t('cac:TaxCategory/cbc:Percent', $k), 'kategorie' => $t('cac:TaxCategory/cbc:ID', $k),
                          'basis' => $t('cbc:TaxableAmount', $k), 'betrag' => $t('cbc:TaxAmount', $k)];
        }
        $zeile = $gutschrift ? 'cac:CreditNoteLine' : 'cac:InvoiceLine';
        $positionen = $x->query("$w/$zeile") ?: [];
        $namen = [];
        foreach ($positionen as $p) {
            if (count($namen) < 5 && ($n = $t('cac:Item/cbc:Name', $p)) !== '') {
                $namen[] = $n;
            }
        }
        $typ = $t($gutschrift ? "$w/cbc:CreditNoteTypeCode" : "$w/cbc:InvoiceTypeCode");
        $faellig = $t("$w/cbc:DueDate");
        return [
            'syntax' => 'ubl',
            'profil' => $t("$w/cbc:CustomizationID"),
            'nummer' => $t("$w/cbc:ID"),
            'typ' => $typ !== '' ? $typ : ($gutschrift ? '381' : '380'),
            'datum' => self::datum($t("$w/cbc:IssueDate")),
            'faellig' => self::datum($faellig !== '' ? $faellig : $t("$w/cac:PaymentMeans/cbc:PaymentDueDate")),
            'lieferdatum' => self::datum($t("$w/cac:Delivery/cbc:ActualDeliveryDate")),
            'waehrung' => strtoupper($t("$w/cbc:DocumentCurrencyCode")),
            'verkaeufer' => $partei('cac:AccountingSupplierParty'),
            'kaeufer' => $partei('cac:AccountingCustomerParty'),
            'netto' => $t("$w/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount"),
            'steuer' => $t("$w/cac:TaxTotal[1]/cbc:TaxAmount"),
            'brutto' => $t("$w/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount"),
            'vorausgezahlt' => $t("$w/cac:LegalMonetaryTotal/cbc:PrepaidAmount"),
            'zahlbetrag' => $t("$w/cac:LegalMonetaryTotal/cbc:PayableAmount"),
            'zahlart' => $t("$w/cac:PaymentMeans/cbc:PaymentMeansCode"),
            'iban' => $t("$w/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID"),
            'steuern' => $steuern,
            'positionen' => count($positionen),
            'positionsnamen' => $namen,
        ];
    }

    private static function text(\DOMXPath $x, string $pfad, ?\DOMNode $von): string
    {
        $k = $x->query($pfad, $von);
        if ($k === false || $k->length === 0) {
            return '';
        }
        return trim((string) preg_replace('/\s+/u', ' ', (string) $k->item(0)->textContent));
    }

    /** JJJJMMTT (CII, Format 102) oder JJJJ-MM-TT (UBL) → JJJJ-MM-TT; alles andere gilt als nicht lesbar. */
    public static function datum(string $w): ?string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $w, $m) || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $w, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
        }
        return null;
    }

    /** Betrag der Rechnung in Cent; null, wenn er nicht lesbar ist. E-Rechnungen schreiben immer mit Dezimalpunkt. */
    public static function cent(string $w): ?int
    {
        if (!preg_match('/^(-?)(\d{1,13})(?:\.(\d{1,6}))?$/', trim($w), $m)) {
            return null;
        }
        $bruch = str_pad(substr($m[3] ?? '', 0, 3), 3, '0');
        $c = (int) $m[2] * 100 + intdiv((int) $bruch + 5, 10);
        return $m[1] === '-' ? -$c : $c;
    }

    /**
     * Aus der gelesenen Rechnung wird ein Eintrag für den Arbeitsvorrat.
     *
     * @param array<string, mixed> $r Ergebnis von lesen()
     * @param string $seite `eingang` (wir haben die Rechnung bekommen) oder `ausgang` (wir haben sie gestellt)
     * @return array{schluessel: string, vorschlag: array<string, mixed>, angaben: list<array{bezeichnung: string, wert: string}>, fehler: ?string}
     */
    public static function anwenden(array $r, string $seite, string $buchwaehrung = 'EUR'): array
    {
        if (!in_array($seite, ['eingang', 'ausgang'], true)) {
            throw new \InvalidArgumentException('seite: eingang oder ausgang erwartet');
        }
        $dez = fn (int $c) => sprintf('%d.%02d', intdiv($c, 100), $c % 100);
        $anzeige = fn (?int $c) => $c === null ? '' : ($c < 0 ? '-' : '') . number_format(abs($c) / 100, 2, ',', '.');
        $gutschrift = in_array($r['typ'], self::GUTSCHRIFTEN, true);
        $brutto = self::cent((string) $r['brutto']);
        $netto = self::cent((string) $r['netto']);
        $steuer = self::cent((string) $r['steuer']);
        $voraus = self::cent((string) $r['vorausgezahlt']);
        // Eine Rechnung mit negativer Summe ist der Sache nach eine Gutschrift
        $negativ = $brutto !== null && $brutto < 0;
        $richtung = ($seite === 'eingang') !== ($gutschrift !== $negativ) ? 'ausgabe' : 'einnahme';
        $gegen = $seite === 'eingang' ? $r['verkaeufer'] : $r['kaeufer'];
        $art = $gutschrift || $negativ ? 'gutschrift' : (in_array($r['typ'], self::KORREKTUREN, true) ? 'rechnungskorrektur' : 'rechnung');
        $wort = ['gutschrift' => 'Gutschrift', 'rechnungskorrektur' => 'Rechnungskorrektur', 'rechnung' => 'Rechnung'][$art];

        $hinweise = [];
        $fehler = [];
        $v = ['art' => $art, 'richtung' => $richtung];
        $name = mb_substr((string) $gegen['name'], 0, 200);
        if ($name !== '') {
            $v['gegenpartei'] = $name;
        } else {
            $fehler[] = $seite === 'eingang' ? 'Name des Rechnungsstellers fehlt' : 'Name des Rechnungsempfängers fehlt';
        }
        $kennung = $gegen['ustid'] !== '' ? $gegen['ustid'] : $gegen['steuernummer'];
        if ($kennung !== '' && mb_strlen($kennung) <= 60) {
            $v['gegenpartei_kennung'] = $kennung;
        }
        $nummer = (string) $r['nummer'];
        if ($nummer === '') {
            $fehler[] = 'Rechnungsnummer fehlt';
        } elseif (mb_strlen($nummer) <= 36) {
            $v['belegnummer'] = $nummer;
        } else {
            $hinweise[] = ['feld' => 'belegnummer', 'text' => 'Die Rechnungsnummer ist länger als 36 Zeichen und steht deshalb nur bei den Angaben'];
        }
        $v['betreff'] = mb_substr(trim("$wort $name " . mb_substr($nummer, 0, 60)), 0, 200);
        if ($r['datum'] !== null) {
            $v['datum'] = $r['datum'];
        } else {
            $fehler[] = 'Rechnungsdatum fehlt oder ist nicht lesbar';
        }
        $waehrung = (string) $r['waehrung'];
        if ($brutto === null || $brutto === 0) {
            $fehler[] = 'Rechnungsbetrag (brutto) fehlt oder ist null';
        } elseif ($waehrung !== '' && $waehrung !== $buchwaehrung) {
            $hinweise[] = ['feld' => 'betrag', 'text' => sprintf('Betrag in %s: %s – nur die Buchwährung wird übernommen', mb_substr($waehrung, 0, 3), $anzeige(abs($brutto)))];
        } else {
            $v['betrag'] = $dez(abs($brutto));
            // Netto und Steuer nur, wenn sie den Rechnungsbetrag ergeben (F101 Nr. 6) – sonst rechnet kumero aus dem Brutto
            if ($netto !== null && $steuer !== null && $steuer !== 0 && $netto + $steuer === $brutto && abs($netto) > 0) {
                $v['netto'] = $dez(abs($netto));
                $v['steuer'] = $dez(abs($steuer));
            } elseif ($netto !== null && $steuer !== null && $steuer !== 0) {
                $hinweise[] = ['feld' => 'betrag', 'text' => sprintf('Netto %s und Steuer %s ergeben nicht den Rechnungsbetrag %s – übernommen ist nur der Rechnungsbetrag',
                    $anzeige($netto), $anzeige($steuer), $anzeige($brutto))];
            }
        }
        // Zahlart 10 = bar (UNTDID 4461); der Tag des Geldflusses steht nie in einer Rechnung
        $v['zahlweise'] = (string) $r['zahlart'] === '10' ? 'bar' : 'unbar';
        $saetze = array_values(array_filter($r['steuern'], fn ($s) => (self::cent((string) $s['betrag']) ?? 0) !== 0));
        if (count($saetze) > 1) {
            $hinweise[] = ['feld' => 'betrag', 'text' => 'Die Rechnung enthält ' . count($saetze) . ' Steuersätze – die Aufteilung steht bei den Angaben und ist beim Buchen von Hand zu splitten'];
        }
        foreach ($r['steuern'] as $s) {
            if ($s['kategorie'] === 'AE') {
                $hinweise[] = ['text' => 'Steuerschuldnerschaft des Leistungsempfängers (Reverse Charge) – die Rechnung weist dafür keine Steuer aus'];
                break;
            }
        }
        if ($voraus !== null && $voraus !== 0) {
            $hinweise[] = ['feld' => 'betrag', 'text' => sprintf('Bereits gezahlt: %s, noch zu zahlen: %s', $anzeige($voraus), $anzeige(self::cent((string) $r['zahlbetrag'])))];
        }
        foreach ($fehler as $f) {
            $hinweise[] = ['text' => "E-Rechnung unvollständig: $f"];
        }
        if ($hinweise !== []) {
            $v['hinweise'] = array_slice($hinweise, 0, 30);
        }

        $angaben = [];
        $dazu = function (string $bezeichnung, ?string $wert) use (&$angaben): void {
            if ($wert !== null && trim($wert) !== '') {
                $angaben[] = ['bezeichnung' => mb_substr($bezeichnung, 0, 60), 'wert' => mb_substr(trim($wert), 0, 1000)];
            }
        };
        $dazu('Format', self::formatName($r));
        $dazu('Rechnungsart', "$wort (Code {$r['typ']})");
        $dazu('Rechnungsnummer', $nummer);
        $dazu('Rechnungssteller', $r['verkaeufer']['name']);
        $dazu('USt-IdNr. Rechnungssteller', $r['verkaeufer']['ustid']);
        $dazu('Steuernummer Rechnungssteller', $r['verkaeufer']['steuernummer']);
        $dazu('Rechnungsempfänger', $r['kaeufer']['name']);
        $dazu('USt-IdNr. Rechnungsempfänger', $r['kaeufer']['ustid']);
        $dazu('Leistungsdatum', $r['lieferdatum']);
        $dazu('Fällig am', $r['faellig']);
        $dazu('Währung', $waehrung);
        $dazu('Netto', $anzeige($netto));
        foreach ($r['steuern'] as $s) {
            $dazu('Steuer ' . ($s['satz'] !== '' ? rtrim(rtrim($s['satz'], '0'), '.') . ' %' : $s['kategorie']),
                $anzeige(self::cent((string) $s['betrag'])) . ' auf ' . $anzeige(self::cent((string) $s['basis'])) . ($s['kategorie'] !== '' ? " (Kategorie {$s['kategorie']})" : ''));
        }
        $dazu('Steuer gesamt', $anzeige($steuer));
        $dazu('Rechnungsbetrag', $anzeige($brutto));
        $dazu('Zahlbetrag', $anzeige(self::cent((string) $r['zahlbetrag'])));
        $dazu('Zahlart (Code)', (string) $r['zahlart']);
        $dazu('IBAN des Zahlungsempfängers', (string) $r['iban']);
        $dazu('Positionen', $r['positionen'] > 0 ? (string) $r['positionen'] : null);
        foreach ($r['positionsnamen'] as $n => $p) {
            $dazu('Position ' . ($n + 1), $p);
        }

        // Dieselbe Rechnung ergibt denselben Schlüssel – gleich, ob sie als XML oder im PDF kommt
        $schluessel = 'xr:' . sha1(implode("\x1F", [mb_strtolower($r['verkaeufer']['ustid'] !== '' ? $r['verkaeufer']['ustid'] : $r['verkaeufer']['name']),
            $nummer, (string) $r['datum'], (string) $brutto, (string) $r['typ'], $seite]));
        return ['schluessel' => $schluessel, 'vorschlag' => $v, 'angaben' => array_slice($angaben, 0, 38), 'fehler' => $fehler === [] ? null : implode('; ', $fehler)];
    }

    /** Lesbarer Name des Formats aus Syntax, Behälter und Profilkennung. @param array<string, mixed> $r */
    public static function formatName(array $r): string
    {
        $p = strtolower((string) $r['profil']);
        $profil = match (true) {
            str_contains($p, 'xrechnung') => 'XRechnung',
            str_contains($p, 'factur-x') || str_contains($p, 'zugferd') => ($r['behaelter'] ?? '') === 'pdf' ? 'ZUGFeRD / Factur-X' : 'Factur-X',
            str_contains($p, 'peppol') => 'Peppol BIS Billing',
            str_contains($p, 'en16931') => 'EN 16931',
            default => 'E-Rechnung',
        };
        $stufe = match (true) {
            str_contains($p, ':extended') => ' (Extended)',
            str_contains($p, ':basicwl') => ' (Basic WL)',
            str_contains($p, ':basic') => ' (Basic)',
            str_contains($p, ':minimum') => ' (Minimum)',
            default => '',
        };
        return $profil . $stufe . ', ' . ($r['syntax'] === 'cii' ? 'CII' : 'UBL') . (($r['behaelter'] ?? '') === 'pdf' ? ' im PDF' : '');
    }
}
