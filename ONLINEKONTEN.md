# ONLINEKONTEN.md – Modul `bank`, erste Stufe: Kontoumsätze als Quelle des Arbeitsvorrats (Entwurf 1, zur Freigabe)

Stand: 2026-11-17. Bezug: L3 (kein Cloud-Zwang, auch nicht für den Bankabruf), L4 (FinTS/CAMT), F9/F26
(EÜR: Zu- und Abflusstag), F31 (Stapel), F45 und Projektschritt 6 (Modul `bank`), BELEGEINGANG.md
Abschnitt 1a und B8, BUCHUNGSMASKE.md.
**Status: Entwurf, früh – zurückgestellt (2026-11-17): Online-Konten werden ein eigenes Modul in einem eigenen
Schritt und gehören nicht zum jetzt beschriebenen Umfang. Gebaut wird erst nach Freigabe** (Entscheidungen O1–O12 in Abschnitt 8). Mehrere
Punkte brauchen Angaben des Betreibers, bevor sie sich festlegen lassen.

Kontoumsätze sind die dritte Quelle des Arbeitsvorrats: Jeder Umsatz, der noch keiner Buchung zugeordnet
ist, ist ein offener Vorgang. Er bringt mit, was bei unbaren Zahlungen sonst fehlt – den **Tag des
tatsächlichen Geldflusses** (BELEGEINGANG.md B8) – und gehört oft zu einem Beleg, der schon im Arbeitsvorrat
steht.

---

Seit F116 erfolgt der Dateiimport für camt.053 und camt.054 im Modul `import` (IMPORT.md Abschnitt 17).
Die folgenden Stufen für den Onlineabruf bleiben ein Entwurf.

## 1. Was „Online-Konten“ umfasst

| Art | Weg | Stufe |
|---|---|---|
| Girokonto, Tagesgeld | Kontoauszug als Datei: **CAMT.053** (Tagesauszug) bzw. CAMT.052; ersatzweise MT940 oder CSV der Bank | **1** |
| dasselbe | Abruf über **FinTS/HBCI** direkt bei der Bank | 2 |
| Kreditkarte, PayPal und ähnliche | Datei (CSV) des Anbieters | 2, je Anbieter |

**Stufe 1 ist der Datei-Import**: Er braucht keine Zugangsdaten der Bank auf dem Server und keine
Registrierung. Der Abruf über FinTS kommt danach und hat eigene Hürden (Abschnitt 7).

## 2. Ablauf

```
Bank → Auszug als Datei → Hochladen in kumero → Umsätze im Mandanten
Umsatz ohne Buchung = offener Vorgang im Arbeitsvorrat
   → Abgleich mit Belegen im Arbeitsvorrat (Betrag, Datum, Rechnungsnummer im Verwendungszweck, IBAN)
   → Buchungserfassung vorbefüllt → Speichern (Stapel) oder Buchen
Umsatz ist erledigt, sobald die zugeordnete Buchung festgeschrieben ist
```

## 3. Bankkonten und Umsätze

- **Bankkonto** = eine Bankverbindung des Mandanten (IBAN, Bezeichnung) mit genau einem **Sachkonto**
  (z. B. 1200 Bank). Gepflegt unter Einstellungen → „Bankkonten“.
- **Umsatz** (eigenes Schema `bank`, F44; Schreiben nur über Funktionen mit Protokoll): Bankkonto,
  Buchungstag, Valuta, Betrag mit Richtung (Eingang/Ausgang; Betrag selbst positiv, F24), Währung, Name und
  IBAN der Gegenseite, Verwendungszweck, Kennung der Bank (Referenz), Herkunft (Datei mit SHA-256),
  Stand (offen, zugeordnet, erledigt, kein Buchungsfall mit Grund).
- **Keine Doppelten:** Derselbe Umsatz aus zwei Auszügen oder zweimal hochgeladen wird erkannt (Referenz
  der Bank; fehlt sie, Bankkonto + Buchungstag + Betrag + Gegenseite + Verwendungszweck).
- **Lückenprüfung:** Bei CAMT.053 müssen Anfangssaldo und Endsaldo aufeinanderfolgender Auszüge
  zusammenpassen; eine Lücke wird angezeigt.
- Verwendungszwecke können in einer Praxis Namen von Patienten enthalten (Art. 9 DSGVO): Umsätze bleiben
  in der Mandanten-Datenbank, erscheinen in keinem Log und in keinem Webhook (F48).
- Die hochgeladene Datei wird nicht aufbewahrt, nur ihr SHA-256 (wie beim Kontenrahmen-Import). Zu klären
  (O6): Soll der Auszug stattdessen als Beleg nach Paperless gehen?

## 4. Abgleich mit Belegen

Ein Vorgang, mehrere Quellen (BELEGEINGANG.md Abschnitt 1a): Zum Umsatz sucht kumero passende offene Belege.

| Treffer | Merkmale | Folge |
|---|---|---|
| sicher | Betrag gleich **und** Rechnungsnummer im Verwendungszweck (oder IBAN der Gegenseite = IBAN auf dem Beleg und nur ein Beleg mit diesem Betrag offen) | wird vorgeschlagen und ist mit einem Tastendruck bestätigt |
| möglich | Betrag gleich, Datum plausibel (Umsatz nach Rechnungsdatum, nahe an der Fälligkeit) | Auswahlliste |
| keiner | – | Umsatz steht allein im Arbeitsvorrat |

Nie wird still verbunden; jede Zuordnung bestätigt ein Mensch. Skonto und Teilzahlungen: Der Betrag darf
abweichen, wenn der Nutzer es bestätigt – die Differenz bucht er selbst (Splitbuchung); eine Automatik dafür
kommt mit dem Modul `opos`.

## 5. Vorbefüllen der Buchungsmaske aus einem Umsatz

| Feld | Quelle |
|---|---|
| Konto / Gegenkonto | Sachkonto des Bankkontos – bei Eingang im Soll, bei Ausgang im Haben |
| Betrag | Betrag des Umsatzes |
| **Buchungsdatum** | **Buchungstag des Umsatzes** = Tag des Geldflusses (O4) |
| Belegdatum | Rechnungsdatum des zugeordneten Belegs, sonst der Buchungstag |
| Belegnummer | Rechnungsnummer des zugeordneten Belegs |
| Buchungstext | Name der Gegenseite, dazu Rechnungsnummer bzw. gekürzter Verwendungszweck (O9) |
| Buchungsart | Buchungsart des Bankkontos (z. B. `BANK`) |
| anderes Konto | aus der Regel „Gegenseite → Konto“ (gemerkt beim Buchen, wie BELEGEINGANG.md B10), sonst leer |

Ein Sammelumsatz (z. B. Abrechnung einer Verrechnungsstelle) öffnet die Zeilentabelle.

## 6. Schnitt und Rechte

- Modul `bank`, abhängig vom Kern und von `belegeingang` (Arbeitsvorrat); `paperless` optional.
- Es meldet sich als Quelle am Arbeitsvorrat an und erzeugt Entwürfe nur über die Dienste des Kerns.
- Rechte: `bank.konto.pflegen`, `bank.umsatz.einlesen`, `bank.umsatz.sehen`, `bank.umsatz.zuordnen`
  (F41 nennt `bank.umsatz.zuordnen` bereits als Beispiel).

## 7. Stufe 2: Abruf über FinTS – was vorher zu klären ist

- **Registrierung:** Für den FinTS-Zugang verlangt die Deutsche Kreditwirtschaft eine registrierte
  Produktkennung des Programms. Die müsste das Projekt beantragen; ohne sie lehnen Banken den Zugang ab.
- **Starke Kundenauthentifizierung (PSD2):** Der Abruf verlangt regelmäßig eine TAN bzw. Freigabe in der
  App der Bank. Ein Abruf ganz ohne Menschen (Timer) ist damit nur eingeschränkt möglich.
- **Zugangsdaten** der Bank dürften nie in Datenbank oder Konfiguration stehen; sie gehörten in den
  Signierdienst bzw. würden je Abruf vom Nutzer eingegeben.
- **Eigene FinTS-Umsetzung in PHP** oder eine vorhandene freie Bibliothek – eine Abhängigkeit, die ins
  signierte Release käme (F77 E1) und zu prüfen wäre.
- L3: kein Dienstleister dazwischen; kumero spräche direkt mit der Bank.

## 8. Entscheidungen zur Freigabe

| # | Frage | Vorschlag |
|---|---|---|
| O1 | Umfang der ersten Stufe | Datei-Import (CAMT.053), Bankkonten, Umsätze als Quelle des Arbeitsvorrats, Abgleich und Vorbefüllen. FinTS als Stufe 2. |
| O2 | Formate | Zuerst CAMT.053; MT940 und CSV nur, wenn die Bank des Referenzbetriebs kein CAMT liefert. **Zu klären:** Welche Bank(en), welche Formate bietet sie an? |
| O3 | Bankkonto ↔ Sachkonto | Genau ein Sachkonto je Bankverbindung. |
| O4 | Tag des Geldflusses | Der **Buchungstag** laut Auszug, nicht die Valuta. Zu bestätigen. |
| O5 | Doppelte und Lücken | Erkennen wie in Abschnitt 3; Lücken anzeigen, nicht verhindern. |
| O6 | Auszugsdatei | Nicht aufbewahren, nur SHA-256. Alternative: als Beleg nach Paperless und am Umsatz verknüpfen. |
| O7 | Abgleich | Dreistufig wie Abschnitt 4; jede Zuordnung bestätigt ein Mensch. |
| O8 | Abweichender Betrag | Erlaubt nach Bestätigung; Differenz von Hand als Splitbuchung. |
| O9 | Verwendungszweck im Buchungstext | Nur Name der Gegenseite und Rechnungsnummer vorbelegen, den Verwendungszweck nicht ungefragt übernehmen (er kann Patientennamen enthalten und stünde dann unveränderlich im Journal). |
| O10 | Kein Buchungsfall | Umsatz mit Grund als erledigt kennzeichnen (z. B. Umbuchung zwischen eigenen Konten, schon anders gebucht). |
| O11 | Weitere Anbieter | Kreditkarte, PayPal usw. später je Anbieter als Datei-Import. **Zu klären:** Welche gibt es im Referenzbetrieb? |
| O12 | FinTS | Erst entscheiden, wenn Stufe 1 läuft: Registrierung beantragen ja/nein, TAN-Verfahren der Bank. |
