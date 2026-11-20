<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Paperless;

/**
 * Sidecar von nopaper am Dokument in Paperless (BELEGEINGANG.md Abschnitt 3, F127; nopaper E61): nopaper schreibt die
 * erkannten Rechnungsdaten als CII-XML in ein benutzerdefiniertes Feld für langen Text. kumero liest ihn beim
 * Abgleich mit – es gibt keine Einlieferung und keinen Aufruf von nopaper an kumero.
 *
 * Der Sidecar ist eine Buchungshilfe, keine E-Rechnung: Wer das Dokument in Paperless bearbeiten darf, kann auch das
 * Feld ändern. Gelesen wird streng, nie geraten; was nicht passt, wird ausgelassen und als Hinweis vermerkt.
 * Beträge kommen mit dem Vorzeichen, mit dem sie auf dem Beleg stehen (nopaper E60).
 */
final class Sidecar
{
    public const KENNUNG = 'SCAN-SIDECAR/';
    private const MAX_BYTES = 1048576;

    /** Das Feld mit dem Sidecar: erkannt an der Kennung im Inhalt, nicht am Namen des Felds. */
    public static function finden(array $dok): ?string
    {
        foreach ($dok['custom_fields'] ?? [] as $cf) {
            $w = is_array($cf) ? ($cf['value'] ?? null) : null;
            if (is_string($w) && str_contains($w, self::KENNUNG) && str_contains($w, 'CrossIndustryInvoice')) {
                return $w;
            }
        }
        return null;
    }

    /**
     * @return array{werte: array<string, mixed>, hinweise: list<array<string, string>>, angaben: list<array{bezeichnung: string, wert: string}>}|null
     *         null, wenn das Dokument keinen Sidecar trägt
     */
    public static function lesen(array $dok): ?array
    {
        $xml = self::finden($dok);
        if ($xml === null) {
            return null;
        }
        $verworfen = fn (string $grund) => ['werte' => [], 'angaben' => [],
            'hinweise' => [['text' => "Sidecar von nopaper nicht verwendet: $grund"]]];
        if (strlen($xml) > self::MAX_BYTES || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return $verworfen('zu groß oder mit unzulässigen Bestandteilen');
        }
        $dom = new \DOMDocument();
        $vorher = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);
        if (!$ok || $dom->documentElement === null || $dom->documentElement->localName !== 'CrossIndustryInvoice') {
            return $verworfen('kein lesbares XML');
        }
        $x = new \DOMXPath($dom);
        // unabhängig vom Präfix der Namensräume: Pfad aus lokalen Namen
        $pfad = fn (string $p) => '/' . implode('/', array_map(fn (string $n) => "*[local-name()='$n']", explode('/', $p)));
        $text = function (string $p, ?\DOMNode $von = null) use ($x, $pfad): ?string {
            $n = $x->query($von === null ? $pfad($p) : '.' . $pfad($p), $von);
            $t = $n !== false && $n->length > 0 ? trim((string) $n->item(0)->textContent) : '';
            return $t === '' ? null : $t;
        };
        $kopf = 'CrossIndustryInvoice/ExchangedDocument';
        $handel = 'CrossIndustryInvoice/SupplyChainTradeTransaction';
        $zahlung = "$handel/ApplicableHeaderTradeSettlement";

        // Herkunftsnachweis: erste Zeile die Kennung mit Fassung, danach schluessel=wert
        $h = [];
        $fassung = null;
        foreach ($x->query($pfad("$kopf/IncludedNote/Content")) ?: [] as $n) {
            $zeilen = preg_split('/\R/', trim((string) $n->textContent)) ?: [];
            if (preg_match('~^SCAN-SIDECAR/(\d+)\.(\d+)$~', trim((string) ($zeilen[0] ?? '')), $m)) {
                $fassung = (int) $m[1];
                foreach (array_slice($zeilen, 1) as $z) {
                    if (str_contains($z, '=')) {
                        [$k, $w] = explode('=', $z, 2);
                        $h[trim($k)] = trim($w);
                    }
                }
                break;
            }
        }
        if ($fassung !== 1) {
            return $verworfen('unbekannte Fassung');
        }
        // Der Sidecar muss zu diesem Dokument gehören (ein kopiertes oder vertauschtes Feld wird nicht verwendet)
        if (!isset($dok['id']) || !ctype_digit($h['paperless-document-id'] ?? '') || (int) $h['paperless-document-id'] !== (int) $dok['id']) {
            return $verworfen('er gehört zu einem anderen Dokument');
        }

        $werte = [];
        $hinweise = [];
        $angaben = [];
        $angabe = function (string $bezeichnung, ?string $wert) use (&$angaben): void {
            if ($wert !== null && $wert !== '') {
                $angaben[] = ['bezeichnung' => $bezeichnung, 'wert' => mb_substr($wert, 0, 1000)];
            }
        };
        $datum = function (?string $w): ?string {
            if ($w === null || !preg_match('/^(\d{4})(\d{2})(\d{2})$/', $w, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }
            return "$m[1]-$m[2]-$m[3]";
        };
        $betrag = fn (?string $w): ?string => $w !== null && preg_match('/^-?\d{1,13}(\.\d{1,2})?$/', $w) ? number_format((float) $w, 2, '.', '') : null;

        if (($nr = $text("$kopf/ID")) !== null) {
            if (mb_strlen($nr) <= 36) {
                $werte['belegnummer'] = $nr;
            } else {
                $hinweise[] = ['feld' => 'belegnummer', 'text' => 'Sidecar: Rechnungsnummer länger als 36 Zeichen – nicht übernommen'];
            }
        }
        if (($d = $datum($text("$kopf/IssueDateTime/DateTimeString"))) !== null) {
            $werte['datum'] = $d;
        }
        $verkaeufer = "$handel/ApplicableHeaderTradeAgreement/SellerTradeParty";
        if (($name = $text("$verkaeufer/Name")) !== null) {
            $werte['gegenpartei'] = mb_substr($name, 0, 200);
        }
        $kennungen = [];
        foreach ($x->query($pfad("$verkaeufer/SpecifiedTaxRegistration/ID")) ?: [] as $n) {
            $schema = $n instanceof \DOMElement ? $n->getAttribute('schemeID') : '';
            $kennungen[$schema] ??= trim((string) $n->textContent);
        }
        $kennung = $kennungen['VA'] ?? $kennungen['FC'] ?? null;   // USt-IdNr. vor Steuernummer
        if (is_string($kennung) && $kennung !== '' && mb_strlen($kennung) <= 60) {
            $werte['gegenpartei_kennung'] = $kennung;
        }
        if (($art = $h['belegart'] ?? null) !== null && ($stichwort = Zuordnung::stichwort($art)) !== null) {
            $werte['art'] = $stichwort;
        }
        $gutschrift = $text("$kopf/TypeCode") === '381';
        if ($gutschrift) {
            $werte['art'] ??= 'gutschrift';
            $hinweise[] = ['feld' => 'richtung', 'text' => 'Sidecar: Gutschrift – Richtung der Buchung prüfen'];
        }

        // Beträge nur in der Buchwährung der Vorbelegung (wie bei den Feldern: fremde Währung wird nicht umgedeutet)
        $waehrung = $text("$zahlung/InvoiceCurrencyCode");
        $summen = "$zahlung/SpecifiedTradeSettlementHeaderMonetarySummation";
        if ($waehrung === 'EUR') {
            foreach (['betrag' => 'GrandTotalAmount', 'netto' => 'TaxBasisTotalAmount', 'steuer' => 'TaxTotalAmount'] as $ziel => $element) {
                if (($b = $betrag($text("$summen/$element"))) !== null) {
                    $werte[$ziel] = $b;
                }
            }
            // Steuer je Satz: nur vollständig und in sich lesbar, sonst gar nicht
            $zeilen = [];
            $ganz = true;
            foreach ($x->query($pfad("$zahlung/ApplicableTradeTax")) ?: [] as $n) {
                $satz = $text('RateApplicablePercent', $n);
                $basis = $betrag($text('BasisAmount', $n));
                $steuer = $betrag($text('CalculatedAmount', $n));
                if ($satz === null || !preg_match('/^\d{1,2}(\.\d{1,2})?$/', $satz) || $basis === null || $steuer === null) {
                    $ganz = false;
                    break;
                }
                $zeilen[] = ['satz' => $satz, 'netto' => $basis, 'steuer' => $steuer];
            }
            if ($ganz && $zeilen !== [] && count($zeilen) <= 10) {
                $werte['steuerzeilen'] = $zeilen;
            }
            // Positionen (LAGER.md Stufe 0): nur, wenn der Sidecar welche trägt – ein Vorschlag, nie ein Zugang
            $roh = [];
            foreach ($x->query($pfad("$handel/IncludedSupplyChainTradeLineItem")) ?: [] as $n) {
                $menge = $x->query('.' . $pfad('SpecifiedLineTradeDelivery/BilledQuantity'), $n);
                $einheit = $menge !== false && $menge->item(0) instanceof \DOMElement ? $menge->item(0)->getAttribute('unitCode') : '';
                $roh[] = array_filter([
                    'nr' => $text('AssociatedDocumentLineDocument/LineID', $n),
                    'bezeichnung' => $text('SpecifiedTradeProduct/Name', $n),
                    'artikelnummer' => $text('SpecifiedTradeProduct/SellerAssignedID', $n),
                    'kaeufernummer' => $text('SpecifiedTradeProduct/BuyerAssignedID', $n),
                    'gtin' => $text('SpecifiedTradeProduct/GlobalID', $n),
                    'charge' => $text('SpecifiedTradeProduct/IndividualTradeProductInstance/BatchID', $n),
                    'menge' => $text('SpecifiedLineTradeDelivery/BilledQuantity', $n),
                    'einheit' => $einheit === '' ? null : $einheit,
                    'einzelpreis' => $text('SpecifiedLineTradeAgreement/NetPriceProductTradePrice/ChargeAmount', $n),
                    'netto' => $text('SpecifiedLineTradeSettlement/SpecifiedTradeSettlementLineMonetarySummation/LineTotalAmount', $n),
                    'satz' => $text('SpecifiedLineTradeSettlement/ApplicableTradeTax/RateApplicablePercent', $n),
                ], fn ($w) => $w !== null);
            }
            if ($roh !== []) {
                [$positionen, $aus] = \Kumero\Api\V1\Belegpositionen::ordnen($roh);
                if ($positionen !== []) {
                    $werte['positionen'] = $positionen;
                }
                if ($aus > 0) {
                    $hinweise[] = ['feld' => 'positionen', 'text' => "Sidecar: $aus Position(en) nicht lesbar – ausgelassen"];
                }
            }
            $zahlbetrag = $betrag($text("$summen/DuePayableAmount"));
            if ($zahlbetrag !== null && $zahlbetrag !== ($werte['betrag'] ?? null)) {
                $angabe('Zahlbetrag laut nopaper', $zahlbetrag);
            }
        } elseif ($waehrung !== null) {
            $hinweise[] = ['feld' => 'betrag', 'text' => mb_substr("Sidecar: Beträge in $waehrung – nicht übernommen", 0, 300)];
        }

        $angabe('Fällig am', $datum($text("$zahlung/SpecifiedTradePaymentTerms/DueDateDateTime/DateTimeString")));
        $angabe('Skonto', ($sk = $text("$zahlung/SpecifiedTradePaymentTerms/Description")) === null ? null : trim((string) preg_replace('/\s+/', ' ', $sk)));
        $angabe('IBAN des Rechnungsstellers', $text("$zahlung/SpecifiedTradeSettlementPaymentMeans/PayeePartyCreditorFinancialAccount/IBANID"));
        $angabe('Verwendungszweck', $text("$zahlung/PaymentReference"));
        $angabe('Leistungsdatum', $datum($text("$handel/ApplicableHeaderTradeDelivery/ActualDeliverySupplyChainEvent/OccurrenceDateTime/DateTimeString")));

        // Prüfstatus von nopaper: „prüfen“ heißt, dass dort eine Prüfregel angeschlagen hat – der Mensch sieht hin
        $status = $h['pruefstatus'] ?? null;
        $codes = trim((string) ($h['pruefhinweise'] ?? ''));
        if ($status === 'pruefen') {
            $hinweise[] = ['text' => mb_substr('nopaper: Beleg prüfen' . ($codes !== '' ? " – $codes" : ''), 0, 300)];
        } elseif ($status !== 'ok') {
            $hinweise[] = ['text' => 'nopaper: Prüfstatus fehlt im Sidecar – Beleg prüfen'];
        }
        $angabe('nopaper', ($status === 'ok' ? 'gelesen, ohne Beanstandung' : 'gelesen, zu prüfen')
            . (isset($h['extraktion-software']) ? ' (' . mb_substr($h['extraktion-software'], 0, 40) . ')' : ''));
        if ($status === 'ok' && $codes !== '') {
            $angabe('Hinweise von nopaper', $codes);
        }
        return ['werte' => $werte, 'hinweise' => $hinweise, 'angaben' => $angaben];
    }
}
