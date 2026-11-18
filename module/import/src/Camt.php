<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Modul\Import;

use DOMElement;
use DOMXPath;
use InvalidArgumentException;

/** Lokaler ISO-20022-Leser. XPath bleibt immer im Namensraum der Nachricht. */
final class Camt
{
    private DOMXPath $xp;

    private function wert(DOMElement $knoten, string $pfad): string
    {
        return trim((string) $this->xp->evaluate("string($pfad)", $knoten));
    }

    private function kinder(DOMElement $knoten, string $pfad): array
    {
        return iterator_to_array($this->xp->query($pfad, $knoten), false);
    }

    private function cent(DOMElement $betrag): int
    {
        $text = trim($betrag->textContent);
        if ($betrag->getAttribute('Ccy') !== 'EUR' || !preg_match('/^([0-9]{1,13})(?:\.([0-9]{1,2}))?$/D', $text, $m)) {
            throw new InvalidArgumentException('EUR-Betrag mit höchstens zwei Nachkommastellen erwartet.');
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    private function betrag(DOMElement $e, string $pfad): int
    {
        $knoten = $this->kinder($e, $pfad);
        if (count($knoten) !== 1) {
            throw new InvalidArgumentException('Der Buchungsbetrag fehlt oder ist mehrdeutig.');
        }
        return $this->cent($knoten[0]);
    }

    private function seite(string $code): int
    {
        return match ($code) {
            'CRDT' => 1, 'DBIT' => -1,
            default => throw new InvalidArgumentException('Unbekanntes Soll-/Haben-Kennzeichen.'),
        };
    }

    private function tag(DOMElement $e, string $feld): string
    {
        $text = $this->wert($e, "c:$feld/c:Dt | c:$feld/c:DtTm");
        if ($text === '' && $feld === 'ValDt') {
            return '';
        }
        if (!preg_match('/^(\d{4})-(\d\d)-(\d\d)(?:T.*)?$/D', $text, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException("Ungültiges oder fehlendes Datum ($feld).");
        }
        return substr($text, 0, 10);
    }

    private static function hash(array $teile): string
    {
        return substr(hash('sha256', json_encode($teile, JSON_THROW_ON_ERROR)), 0, 32);
    }

    private function referenz(DOMElement $e, array $pfade): string
    {
        foreach ($pfade as $pfad) {
            $wert = $this->wert($e, $pfad);
            if ($wert !== '' && !in_array(strtoupper($wert), ['NOTPROVIDED', 'NONREF', 'NOTAVAILABLE'], true)) {
                return $wert;
            }
        }
        return '';
    }

    public function lesen(string $roh, string $geldkonto): array
    {
        if (strlen($roh) > Csv::HOECHSTENS_BYTES || str_contains($roh, "\0") || stripos($roh, '<!DOCTYPE') !== false) {
            throw new InvalidArgumentException('Unzulässige CAMT-Datei (Größe, Kodierung oder DTD).');
        }
        $dom = new \DOMDocument();
        if ($roh === '' || !@$dom->loadXML($roh, LIBXML_NONET | LIBXML_COMPACT) || $dom->doctype !== null) {
            throw new InvalidArgumentException('Ungültiges CAMT-XML.');
        }
        $wurzel = $dom->documentElement;
        if ($wurzel->localName !== 'Document' || !preg_match('/^urn:iso:std:iso:20022:tech:xsd:camt\.(053|054)\.001\.(02|08)$/D', $wurzel->namespaceURI ?? '', $m)) {
            throw new InvalidArgumentException('Unterstützt werden camt.053 und camt.054 in Version .001.02 und .001.08.');
        }
        $typ = $m[1];
        $this->xp = new DOMXPath($dom);
        $this->xp->registerNamespace('c', $wurzel->namespaceURI);
        $berichte = $this->kinder($wurzel, $typ === '053' ? 'c:BkToCstmrStmt/c:Stmt' : 'c:BkToCstmrDbtCdtNtfctn/c:Ntfctn');
        if (!$berichte) {
            throw new InvalidArgumentException('Auszug oder Umsatzanzeige fehlt.');
        }
        $konto = null;
        $gruppen = [];
        $salden = null;
        $saldoVor = null;
        $anzahl = 0;
        $umsatzCent = 0;
        $vorkommen = [];
        foreach ($berichte as $bericht) {
            $iban = $this->wert($bericht, 'c:Acct/c:Id/c:IBAN');
            $kennung = $iban !== '' ? strtoupper(str_replace(' ', '', $iban)) : $this->wert($bericht, 'c:Acct/c:Id/c:Othr/c:Id');
            if ($kennung === '' || ($konto !== null && $konto !== $kennung)) {
                throw new InvalidArgumentException('Eine Datei muss genau einem identifizierten Bankkonto zuzuordnen sein.');
            }
            $konto = $kennung;
            $wirkung = 0;
            foreach ($this->kinder($bericht, 'c:Ntry') as $eintrag) {
                $gruppe = $this->gruppe($eintrag, $typ, $konto, $geldkonto);
                $id = $gruppe['id'];
                if (!$gruppe['bankreferenz']) {
                    // Gleiche Abbuchungen ohne Referenz bleiben nach ihrer Häufigkeit unterscheidbar.
                    $vorkommen[$id] = ($vorkommen[$id] ?? 0) + 1;
                    $id .= '#' . $vorkommen[$id];
                    $gruppe['id'] = $id;
                }
                if (isset($gruppen[$id])) {
                    throw new InvalidArgumentException('Zwei Umsätze sind in dieser Datei nicht eindeutig unterscheidbar. Bitte Bankreferenzen prüfen.');
                }
                $gruppen[$id] = $gruppe;
                foreach ($gruppe['zeilen'] as $zeile) {
                    if ($zeile['cent'] > PHP_INT_MAX - $umsatzCent) {
                        throw new InvalidArgumentException('Die Umsatzsumme dieser Datei ist zu groß.');
                    }
                    $umsatzCent += $zeile['cent'];
                }
                $wirkung += $gruppe['wirkung'];
                $anzahl += count($gruppe['zeilen']);
                if ($anzahl > Csv::HOECHSTENS_ZEILEN) {
                    throw new InvalidArgumentException('Zu viele Zahlungen in einer Datei.');
                }
            }
            $werte = [];
            foreach ($this->kinder($bericht, 'c:Bal') as $b) {
                $art = $this->wert($b, 'c:Tp/c:CdOrPrtry/c:Cd');
                if (in_array($art, ['OPBD', 'CLBD'], true)) {
                    if (isset($werte[$art])) {
                        throw new InvalidArgumentException('Mehrdeutiger Anfangs- oder Endsaldo.');
                    }
                    $werte[$art] = $this->betrag($b, 'c:Amt') * $this->seite($this->wert($b, 'c:CdtDbtInd'));
                }
            }
            if (isset($werte['OPBD'], $werte['CLBD'])) {
                if ($werte['OPBD'] + $wirkung !== $werte['CLBD'] || ($saldoVor !== null && $saldoVor !== $werte['OPBD'])) {
                    throw new InvalidArgumentException('Auszugssalden passen nicht zu den Umsätzen oder zum vorherigen Auszug in dieser Datei.');
                }
                $saldoVor = $werte['CLBD'];
                $salden = ['anfang' => $salden['anfang'] ?? $werte['OPBD'], 'ende' => $saldoVor, 'stimmt' => true];
            } else {
                $saldoVor = null;
            }
        }
        return ['format' => "camt.$typ.001." . $m[2], 'konto' => $konto, 'gruppen' => $gruppen, 'salden' => count($berichte) === 1 ? $salden : null];
    }

    private function gruppe(DOMElement $e, string $typ, string $konto, string $geldkonto): array
    {
        if ($this->wert($e, 'c:Sts/c:Cd | c:Sts[not(*)]') !== 'BOOK') {
            throw new InvalidArgumentException('Die Datei enthält einen Umsatz ohne den Buchungsstatus BOOK.');
        }
        $datum = $this->tag($e, 'BookgDt');
        $valuta = $this->tag($e, 'ValDt') ?: $datum;
        $seite = $this->seite($this->wert($e, 'c:CdtDbtInd'));
        $summe = $this->betrag($e, 'c:Amt') * $seite;
        $details = $this->kinder($e, 'c:NtryDtls/c:TxDtls');
        $anzahl = max(1, count($details));
        $zeilen = [];
        $gesamt = 0;
        $ref = $this->referenz($e, ['c:AcctSvcrRef', 'c:NtryRef']);
        if ($ref === '' && count($details) === 1) {
            $ref = $this->referenz($details[0], ['c:Refs/c:AcctSvcrRef', 'c:Refs/c:TxId']);
        }
        foreach ($this->kinder($e, 'c:NtryDtls') as $block) {
            $gemeldet = $this->wert($block, 'c:Btch/c:NbOfTxs');
            $geliefert = count($this->kinder($block, 'c:TxDtls'));
            if ($gemeldet !== '' && $geliefert && (int) $gemeldet !== $geliefert) {
                throw new InvalidArgumentException('Der Sammler enthält nur einen Teil der angekündigten Einzelzahlungen.');
            }
        }
        foreach ($details ?: [$e] as $tx) {
            $richtung = $this->wert($tx, 'c:CdtDbtInd');
            $vorzeichen = $richtung === '' ? $seite : $this->seite($richtung);
            $cent = abs($summe);
            $pfad = 'c:Amt';
            if (!$this->kinder($tx, $pfad)) {
                $pfad = $this->kinder($tx, 'c:AmtDtls/c:TxAmt/c:Amt') ? 'c:AmtDtls/c:TxAmt/c:Amt' : 'c:AmtDtls/c:InstdAmt/c:Amt';
            }
            if ($tx !== $e && ($anzahl > 1 || $this->kinder($tx, $pfad))) {
                $cent = $this->betrag($tx, $pfad);
            }
            if ($cent === 0) {
                throw new InvalidArgumentException('Umsatz ohne positiven Betrag.');
            }
            $gesamt += $cent * $vorzeichen;
            $partner = $vorzeichen === 1 ? 'Dbtr' : 'Cdtr';
            $partei = $this->wert($tx, "c:RltdPties/c:$partner/c:Nm | c:RltdPties/c:$partner/c:Pty/c:Nm");
            $iban = $this->wert($tx, "c:RltdPties/c:{$partner}Acct/c:Id/c:IBAN");
            $texte = $this->kinder($tx, 'c:RmtInf/c:Ustrd | c:RmtInf/c:Strd/c:CdtrRefInf/c:Ref | c:RmtInf/c:Strd/c:AddtlRmtInf');
            $zweck = trim(implode(' ', array_map(fn ($n) => trim($n->textContent), $texte)));
            $zweck = $zweck ?: ($this->wert($tx, 'c:AddtlTxInf') ?: $this->wert($e, 'c:AddtlNtryInf'));
            $txref = $this->referenz($tx, ['c:Refs/c:AcctSvcrRef', 'c:Refs/c:TxId', 'c:Refs/c:EndToEndId']);
            $v = ['betreff' => mb_substr($zweck ?: ($partei ?: 'Bankumsatz'), 0, 200), 'gegenpartei' => mb_substr($partei, 0, 200),
                'gegenpartei_kennung' => mb_substr($iban, 0, 60), 'geldkonto' => $geldkonto, 'datum' => $datum, 'zahltag' => $valuta,
                'richtung' => $vorzeichen === 1 ? 'einnahme' : 'ausgabe', 'betrag' => sprintf('%d.%02d', intdiv($cent, 100), $cent % 100), 'zahlweise' => 'unbar'];
            $belege = $this->kinder($tx, 'c:RmtInf/c:Strd/c:RfrdDocInf/c:Nb');
            if (count($belege) === 1 && mb_strlen(trim($belege[0]->textContent)) <= 36) {
                $v['belegnummer'] = trim($belege[0]->textContent);
            }
            $angaben = [];
            $merkmale = ['Bankkonto' => $konto, 'Bankreferenz' => $ref, 'Zahlungsreferenz' => $txref,
                'End-to-End-Referenz' => $this->referenz($tx, ['c:Refs/c:EndToEndId']),
                'Stornokennzeichen' => $this->wert($e, 'c:RvslInd'), 'Verwendungszweck' => $zweck];
            foreach ($merkmale as $label => $wert) {
                if ($wert !== '') {
                    $angaben[] = ['bezeichnung' => $label, 'wert' => mb_substr($wert, 0, 1000)];
                }
            }
            $zeilen[] = ['vorschlag' => $v, 'angaben' => $angaben, 'cent' => $cent, 'richtung' => $v['richtung'], 'fehler' => null,
                'teil' => self::hash([$txref, $cent * $vorzeichen, $txref === '' ? [$partei, $iban, $zweck] : []])];
        }
        if ($gesamt !== $summe) {
            throw new InvalidArgumentException('Einzelzahlungen ergeben nicht den Betrag der Sammelbuchung.');
        }
        $tag = self::hash([$konto]) . ':' . $datum;
        $teile = array_column($zeilen, 'teil');
        sort($teile);
        $identitaet = $ref !== '' ? ['bank', $ref] : ['inhalt', $summe, $valuta, $teile];
        $id = $tag . ':' . self::hash($identitaet);
        return ['id' => $id, 'bankreferenz' => $ref !== '', 'tag' => $tag, 'typ' => $typ, 'wirkung' => $summe, 'zeilen' => $zeilen];
    }

    /** Prüft gegen aktive Quellenbeiträge beider Formate, unabhängig vom gewählten Sachkonto. */
    public static function abgleichen(array $datei, array $stand): array
    {
        $gruppen = $datei['gruppen'];
        $bestand = [];
        $ungeklaert = [];
        $referenztage = [];
        foreach ($stand as $key => $zustand) {
            if ($zustand['zurueckgezogen'] || !str_starts_with($key, 'camt:')) {
                continue;
            }
            [, $konto, $datum, $referenz, $typ, $wirkung, $teil] = explode(':', $key, 7);
            $tag = "$konto:$datum";
            $id = "$tag:$referenz";
            $bestand[$id]['teile'][$teil] = $key;
            $bestand[$id]['wirkung'] = (int) $wirkung;
            if (!str_contains($referenz, '#')) {
                $referenztage["$konto:$referenz"][$typ][$datum] = true;
            }
            if (!isset($gruppen[$id])) {
                $ungeklaert[$tag][$typ] = true;
            }
        }
        $zeilen = [];
        foreach ($gruppen as $id => $g) {
            $alt = $bestand[$id] ?? null;
            if ($alt && $alt['wirkung'] !== $g['wirkung']) {
                throw new InvalidArgumentException('Abweichender Betrag zu einer bereits importierten Bankreferenz.');
            }
            $andererTyp = $g['typ'] === '053' ? '054' : '053';
            [$konto, , $referenz] = explode(':', $id);
            if (!$alt && isset($referenztage["$konto:$referenz"][$andererTyp])) {
                throw new InvalidArgumentException('Die Bankreferenz kommt mit abweichendem Buchungstag im anderen Format vor.');
            }
            if (!$alt && isset($ungeklaert[$g['tag']][$andererTyp])) {
                throw new InvalidArgumentException('Am selben Tag existieren Umsätze des anderen CAMT-Formats ohne eindeutige gemeinsame Referenz. Auszüge und Umsatzanzeigen bitte abgleichen.');
            }
            $zaehler = [];
            $anzahl = count($g['zeilen']);
            foreach ($g['zeilen'] as $z) {
                $hash = $z['teil'];
                $zaehler[$hash] = ($zaehler[$hash] ?? 0) + 1;
                $teil = "$hash#" . $zaehler[$hash];
                $key = "camt:$id:" . $g['typ'] . ':' . $g['wirkung'] . ":$teil";
                if ($alt) {
                    if ($anzahl === 1) {
                        // Ein bereits aufgelöster Sammler deckt seine Summenzeile vollständig ab.
                        $key = reset($alt['teile']);
                    } elseif (count($alt['teile']) === $anzahl && isset($alt['teile'][$teil])) {
                        $key = $alt['teile'][$teil];
                    } else {
                        throw new InvalidArgumentException('Die Aufteilung eines vorhandenen Umsatzes weicht ab. Bei einem noch offenen Import zuerst den bisherigen Lauf zurückziehen und die Detaildatei einlesen.');
                    }
                }
                $z['schluessel'] = $key;
                $z['nr'] = count($zeilen) + 1;
                $zeilen[] = $z;
            }
        }
        return ['zeilen' => $zeilen, 'salden' => $datei['salden']];
    }
}
