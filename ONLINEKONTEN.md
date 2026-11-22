# ONLINEKONTEN.md – Modul `bank`: Umsätze online bei der Bank abrufen (Fassung 2, zur Freigabe)

Stand: 2026-11-22. Bezug: L3 (kein Cloud-Zwang, auch nicht für den Bankabruf), L4 (FinTS/CAMT), F44, F84, F89, F100,
F110, F116 (CAMT-Dateien im Modul `import`), F121, IMPORT.md Abschnitt 17, PAPERLESS.md L3a, AGENTS.md P15.

**Status: Entwurf zur Freigabe, nichts gebaut.** Wieder aufgerufen vom Verantwortlichen am 2026-11-22 („was fehlt, ist
ein Online-Banking-Interface“, F173); bis dahin zurückgestellt. **Entschieden: BK2 – FinTS/HBCI (F174).** Zu entscheiden
sind BK1 und BK3–BK16 (Abschnitt 10); vier
Angaben kann nur der Verantwortliche liefern (Abschnitt 11). Entwurf 1 vom 2026-11-17 steht als Anhang A am Ende.

## 1. Was da ist und was fehlt

- **Da (F116, kumero 0.7.x, Modul `import`):** Kontoauszüge als Datei – camt.053 und camt.054 – über „Datei einlesen“
  in den Arbeitsvorrat, mit Vorschau, Saldenprüfung, Erkennen schon eingelieferter Umsätze und Rücknahme. Der Weg von
  der Bank zu kumero ist Handarbeit: im Online-Banking anmelden, Auszug herunterladen, in kumero hochladen.
- **Fehlt:** kumero holt die Umsätze selbst bei der Bank. Das ist dieser Entwurf.
- **Lesart (BK1):** „Online-Banking-Interface“ heißt hier **Umsätze und Salden abrufen**. Zahlungen auslösen
  (Überweisungen, Lastschriften) ist ein anderes Vorhaben mit anderem Risiko und steht nicht in diesem Entwurf.

## 2. Die drei Wege zur Bank

| Weg | Für wen | Freigabe beim Abruf | Vorlauf | Urteil |
|---|---|---|---|---|
| **FinTS 3.0 mit PIN und TAN** (früher HBCI) | Privat- und Geschäftskonten bei Sparkassen, Genossenschaftsbanken und vielen weiteren Banken; ohne eigenen Vertrag | PIN bei jedem Abruf, dazu regelmäßig eine TAN bzw. Freigabe in der App der Bank | **Registrierung von kumero bei der Deutschen Kreditwirtschaft** – kostenlos, dauert nach den Angaben anderer Projekte einige Wochen; ohne Produktkennung weisen die Banken die Verbindung ab | **Vorschlag: zuerst** (BK2) |
| **EBICS** | Geschäftskunden mit EBICS-Vertrag bei ihrer Bank (meist gegen Entgelt) | keine TAN: einmalig Schlüssel erzeugen und per unterschriebenem Brief bei der Bank freischalten; danach läuft der Abruf ohne Menschen | Vertrag und Freischaltung bei der Bank; keine Produktregistrierung | **Vorschlag: zweite Stufe** – der einzige Weg zu einem Abruf nach Zeitplan |
| **PSD2-Schnittstelle der Banken (XS2A)** | Zahlungsdienstleister mit Erlaubnis der Aufsicht | – | Zulassung als Kontoinformationsdienst oder ein Dienstleister dazwischen | **verworfen:** verlangt einen Dritten zwischen kumero und Bank (L3) |

Zu wissen: Einzelne Banken bieten FinTS nicht oder nicht mehr vollständig an (ING gar nicht; manche stellen die
Zahlungen über FinTS ein und lassen den Abruf der Umsätze bestehen). Welche Bank der Referenzbetrieb hat, entscheidet
deshalb mit (Abschnitt 11).

## 3. Schnitt

- **Modul `bank` am Buch** (F135: Vorgabe), abhängig von `import` und `arbeitsvorrat`. Es bringt die Bankverbindungen,
  den Abruf und eine Seite; **es führt keine eigene Tabelle der Umsätze** – abweichend von Entwurf 1 (A3).
- **Ein abgerufener Auszug nimmt denselben Weg wie eine eingelesene Datei:** FinTS liefert die Umsätze als CAMT. Das
  Modul `bank` reicht die Daten an einen **Dienst des Moduls `import`** weiter („CAMT einliefern“, neu in dessen
  Modul-Schnittstelle); Lesen, Saldenprüfung, Auflösen von Sammelbuchungen, Erkennen von Vorhandenem, Vorschau,
  Verlauf und Rücknahme bleiben an einer Stelle (BK3). `bank` liest und schreibt keine Tabellen von `import` (F44).
- **Dafür am Modul `import` zu ergänzen:** der Dienst; das Format **camt.052** (das liefert FinTS – heute erkennt
  „Datei einlesen“ nur camt.053 und camt.054); als Herkunft eines Imports neben „Datei“ auch „Abruf“ mit Bankkonto und
  Zeitraum statt Dateiname.
- **Die Bank hinter einer eigenen Schnittstelle:** `bank` spricht mit einem `Bankzugang` (Konten nennen, Saldo,
  Umsätze von–bis, TAN-Schritt fortsetzen). FinTS ist die erste Umsetzung, EBICS die zweite; die Seite kennt keines von
  beiden beim Namen (wie das Belegarchiv, F97).
- **Nichts wird aufbewahrt, was `import` nicht ohnehin hält:** Der abgerufene Auszug wird wie die Datei nach dem
  Einliefern verworfen (F110, IMPORT.md C9); es bleiben Zeitraum, Prüfsumme und Zahlen des Abrufs.

## 4. FinTS selbst schreiben oder eine Bibliothek nehmen (BK4)

- **Vorschlag: die freie Bibliothek `nemiah/php-fints` (phpFinTS).** Sie kann Konten, Saldo und Umsätze, kennt die
  TAN-Verfahren nach PSD2 einschließlich der Freigabe in der App, verlangt PHP ≥ 8.3 und die Erweiterungen `curl` und
  `mbstring`, und wird gepflegt. Eingebunden wie die übrigen Abhängigkeiten (F84 Nr. 1): über Git, mit festem Commit in
  `composer.lock`, im signierten Release. **Vor dem Einbinden zu prüfen:** Lizenz (nach Erinnerung MIT – verträglich
  mit AGPL), ob sie ohne weitere Pakete auskommt, und ob ihr Protokollieren ganz abschaltbar ist (Abschnitt 7).
- **Die Alternative – FinTS in kumero selbst umsetzen –** kostet Wochen, braucht Tests gegen mehrere Banken und bringt
  nichts, was die Schnittstelle `Bankzugang` nicht auch offen hält. „Funktionen abschauen, kein fremder Code“ galt für
  fremde ERP-Systeme, nicht für Bibliotheken; zu bestätigen.
- Folge für die Pflege (wie F94 für Paperless): Ändert eine Bank ihr Verfahren, ist das eine Pflegeaufgabe – Bibliothek
  prüfen, anheben, ausliefern. Die Sicherheitsmeldungen der Bibliothek sind von Hand zu verfolgen (F84 Nr. 8).

## 5. Zugangsdaten, PIN und TAN

- **Der Bankzugang gehört einer Person, das Bankkonto dem Buch** (BK5) – wie bei Paperless (L2): Die Bank kennt den
  Kontoinhaber oder Bevollmächtigten, nicht „die Buchhaltung“. Am Buch steht: Bank (BLZ, Adresse des Bankrechners),
  IBAN, Bezeichnung, zugeordnetes Geldkonto. Am Nutzer steht: seine Benutzerkennung bei dieser Bank, das gewählte
  TAN-Verfahren und, wenn er es will, die PIN.
- **Die PIN steht nie im Klartext in Datenbank, Konfiguration oder Log** (BK6). Zwei Arten, der Nutzer wählt:
  (a) **bei jedem Abruf eingeben** – gespeichert wird nichts; (b) **verschlüsselt ablegen und je Sitzung mit der
  eigenen Passphrase entsperren** – derselbe Baustein wie für das Paperless-Token (PAPERLESS.md L3a): Aus der Datenbank
  allein ist die PIN nicht zu gewinnen. Vorgabe ist (a).
- **Abgerufen wird nur auf Knopfdruck eines angemeldeten Menschen** (BK7). Mit FinTS gibt es keinen Abruf nach
  Zeitplan: Er bräuchte die PIN im Zugriff des Servers und scheitert an der TAN. Das kommt mit EBICS (Abschnitt 9).
- **TAN** (BK8): Die Bank verlangt sie beim ersten Abruf und danach in Abständen (nach Erinnerung höchstens alle
  180 Tage, je Bank kürzer; vor dem Bauen nachzuschlagen), außerdem für Zeiträume, die weiter zurückliegen.
  Erste Stufe: **Freigabe in der App der Bank** (kumero wartet und fragt nach, bis freigegeben oder abgebrochen ist)
  und **TAN zum Eintippen** (SMS, Generator mit Eingabe von Hand, Bild der Bank für photoTAN und QR). Nicht in der
  ersten Stufe: Flickercode am Bildschirm, Kartenleser am Rechner.
- **Der Zustand zwischen Auftrag und TAN** (die Bank hält den Vorgang offen) liegt höchstens fünf Minuten am Server,
  verschlüsselt mit einem Schlüssel aus der Sitzung, und wird danach gelöscht – wie eine Challenge, außerhalb der
  Ketten und ohne Protokoll (vgl. F124).
- **Produktkennung** (BK9): Die Registrierung gilt dem Produkt kumero und verlangt Angaben zum Hersteller – das kann
  nur der Verantwortliche. Die Kennung steht in `/etc/kumero/kumero.php`, **nicht im Repository**, bis geklärt ist, ob
  die Deutsche Kreditwirtschaft die Veröffentlichung in offenem Quellcode gestattet. Bis dahin trägt ein anderer
  Betreiber seine eigene Kennung ein. Ohne Kennung ist der Abruf gesperrt und sagt warum (F106).
- **Adresse des Bankrechners:** Die Liste der Institute bekommt, wer registriert ist; ob sie mit ausgeliefert werden
  darf, ist offen. Erste Stufe: Die Adresse trägt ein, wer das Bankkonto anlegt; liegt die Liste des Betreibers unter
  `/etc/kumero/`, schlägt kumero daraus vor.

## 6. Ablauf und Oberfläche

```
Arbeitsvorrat → „Bankkonten“ → Konto wählen → „Umsätze abrufen“
   → (PIN, falls nicht entsperrt) → (TAN oder Freigabe in der App, falls die Bank sie verlangt)
   → Vorschau wie bei „Datei einlesen“: neu / schon vorhanden / Konflikt, Salden geprüft
   → „Einliefern“ → Einträge im Arbeitsvorrat
```

- **Seite „Bankkonten“** als Unterpunkt des Arbeitsvorrats neben „Datei einlesen“ (F108). Liste der Bankkonten des
  Buchs mit Filtern und verschiebbaren Spalten (F91, F146): Bezeichnung, IBAN, Geldkonto, letzter Abruf, Saldo laut
  Bank mit Stand, Saldo des Geldkontos, Abweichung. Doppelklick öffnet das Bankkonto (F150).
- **Zeitraum** (BK10): vorbelegt vom Tag des letzten Abrufs, sieben Tage überlappend, bis heute; beim ersten Abruf
  90 Tage zurück. Der Nutzer kann ihn ändern. Doppelte verhindert die vorhandene Erkennung von `import`.
- **Abgerufen werden gebuchte Umsätze** (BK11); vorgemerkte zeigt die Vorschau nicht und liefert sie nicht ein – sie
  können sich noch ändern, und der Arbeitsvorrat zieht nichts von selbst zurück.
- **Saldo laut Bank neben dem Saldo des Geldkontos** (BK12): eine Zeile zur Abstimmung, keine Buchung, kein Hinweis,
  der sich aufdrängt (F32). Die Abweichung ist erklärbar durch Offenes im Arbeitsvorrat; die Seite nennt dessen Summe.
- **PIN- und TAN-Abfrage** stehen an festem Platz in der Seite; nichts verschiebt sich (F106). Die Wartezeit auf die
  Freigabe in der App zeigt die Statusleiste; „Abbrechen“ beendet den Vorgang bei der Bank.
- **Mehrere Konten einer Bankverbindung** ruft ein Lauf nacheinander ab – eine PIN, höchstens eine TAN.
- Das Buchen selbst ändert sich nicht: Vorbelegung, Stapel, Zusammenführen mit Belegen sind die des Arbeitsvorrats.

## 7. Daten und Datenschutz

- Verwendungszwecke können Namen von Patienten enthalten (Art. 9 DSGVO). **Kein Log, kein Ereignis, keine
  Fehlermeldung nennt Inhalte eines Umsatzes, eine PIN, eine TAN oder eine Benutzerkennung** – nur IDs, Zahlen,
  Zeiträume und Fehlercodes der Bank. Das Protokollieren der Bibliothek bleibt aus.
- **Verbindungen gehen vom Server aus, nur zur eingetragenen Adresse der Bank,** nur über `https://`, ohne
  Weiterleitungen, mit geprüftem Zertifikat (wie beim Modul `paperless`, F92). Kein Dienstleister dazwischen (L3).
- **Im Protokoll des Buchs:** Bankkonto angelegt/geändert/stillgelegt (mit Vorher- und Nachher-Stand, ohne
  Zugangsdaten), Abruf mit Bankkonto, Zeitraum, Zahl der Umsätze, wer. Der Zugang des Nutzers: nur „eingerichtet“,
  „geändert“, „entfernt“ – nie ein Wert.
- Ein Bankkonto wird stillgelegt, nicht gelöscht; der Zugang eines Nutzers wird gelöscht, wenn er ihn entfernt oder
  der Nutzer gesperrt wird.

## 8. Rollen und Rechte (BK13)

Wer arbeitet damit? Wer bucht – nicht das Lager, nicht das Personal, nicht der Administrator (F43).

| Recht | Wofür | Buchhalter | Verantwortlicher | Steuerberater | Erfasser | Leser |
|---|---|---|---|---|---|---|
| `bank.konto.sehen` | Bankkonten, letzter Abruf, Saldo laut Bank | ja | ja | ja | ja | ja |
| `bank.konto.pflegen` | Bankkonto anlegen, Geldkonto zuordnen, stilllegen | – | ja | ja | – | – |
| `bank.umsatz.abrufen` | eigenen Bankzugang einrichten, abrufen, einliefern | ja | ja | ja | – | – |

Keine eigene Rolle: Der Abruf ist ein Handgriff der Buchhaltung wie „Datei einlesen“. Der Erfasser ruft nicht ab
(Vorschlag) – er hat in der Regel keinen Zugang zum Konto; wer es anders will, gibt ihm den Baustein. Einliefern
braucht zusätzlich `import.datei.einlesen`.

## 9. Stufen

| Stufe | Inhalt | Hängt an |
|---|---|---|
| **A** | Modul `bank` mit Bankkonten am Buch, Seite „Bankkonten“, Schnittstelle `Bankzugang`; im Modul `import` der Dienst „CAMT einliefern“, camt.052, Herkunft „Abruf“. Abruf gegen einen **nachgebauten Bankrechner** (wie `tests/Hilfe/paperless_nachbau.php`) – ohne Bibliothek, ohne Registrierung | Freigabe BK1–BK3, BK5, BK10–BK13 |
| **B** | FinTS: Bibliothek einbinden, PIN-Ablage, TAN-Schritt, Saldo laut Bank | BK4, BK6–BK9; **Produktkennung** für den ersten echten Abruf |
| **C** | EBICS: Schlüssel, Initialisierungsbrief, Abruf nach Zeitplan über den Dienst `kumero-auftraege` | eigener Entwurf: wo die Schlüssel liegen (BK14) |
| – | Zahlungen, Depots, Kreditkarten, PayPal | nicht geplant (BK15, BK16) |

Stufe A lässt sich bauen, während die Registrierung läuft. Stufe B ist im Chat nur gegen den Nachbau prüfbar (F121);
den ersten Abruf bei einer echten Bank macht der Verantwortliche – **PIN, TAN und Benutzerkennung kommen nie in den
Chat**, eine Fehlermeldung genügt.

## 10. Entscheidungen zur Freigabe

| # | Frage | Vorschlag |
|---|---|---|
| BK1 | Umfang | Umsätze und Salden abrufen. Keine Zahlungen. |
| BK2 | Welcher Weg zuerst | **Entschieden am 2026-11-22 (F174): FinTS/HBCI** – für den Zielanwender, kleine und mittlere Unternehmen, der bessere Weg. EBICS bleibt eine mögliche spätere Stufe (BK14), die PSD2-Schnittstelle nicht. |
| BK3 | Schnitt | Modul `bank` am Buch ohne eigene Umsatztabelle; der Abruf geht durch den CAMT-Leser von `import` in den Arbeitsvorrat. Ändert Entwurf 1 (A3, O1). |
| BK4 | FinTS-Umsetzung | Bibliothek `nemiah/php-fints` hinter der eigenen Schnittstelle `Bankzugang`, nach Prüfung von Lizenz und Abhängigkeiten. |
| BK5 | Wem gehört was | Bankkonto und Geldkonto dem Buch, Benutzerkennung, TAN-Verfahren und PIN dem Nutzer. |
| BK6 | PIN | Vorgabe: bei jedem Abruf eingeben. Auf Wunsch verschlüsselt abgelegt, entsperrt mit der Passphrase des Nutzers (Baustein von Paperless). |
| BK7 | Abruf nach Zeitplan | Mit FinTS nicht – nur auf Knopfdruck. |
| BK8 | TAN-Verfahren der ersten Stufe | Freigabe in der App und TAN zum Eintippen, Bild der Bank anzeigen; kein Flickercode, kein Kartenleser. |
| BK9 | Produktkennung | Der Verantwortliche registriert kumero; die Kennung steht in der Konfiguration der Installation, nicht im Repository. |
| BK10 | Zeitraum | Seit dem letzten Abruf mit sieben Tagen Überlappung; beim ersten Mal 90 Tage; änderbar. |
| BK11 | Vorgemerkte Umsätze | Nicht einliefern. |
| BK12 | Saldo laut Bank | Anzeigen neben dem Saldo des Geldkontos, mit Stand; nichts buchen, nichts melden. |
| BK13 | Rollen | Rechte wie in Abschnitt 8, keine eigene Rolle, Erfasser ruft nicht ab. |
| BK14 | EBICS | Eigener Entwurf nach Stufe B – oder vorgezogen, wenn der Referenzbetrieb einen EBICS-Vertrag hat (Abschnitt 11). |
| BK15 | Zahlungen | Nicht geplant; wenn doch, eigener Entwurf mit Freigabe per Passkey (F38). |
| BK16 | Kreditkarten, PayPal und ähnliche | Weiter als Datei über „Datei einlesen“ (CSV). |

## 11. Was nur der Verantwortliche liefern kann

1. **Welche Bank oder Banken** hat der Referenzbetrieb – und bietet sie FinTS an? (Nur der Name der Bank; keine
   Kontonummer, keine Zugangsdaten.)
2. **Welches TAN-Verfahren** ist dort eingerichtet (App mit Freigabe, SMS, Generator, photoTAN)?
3. ~~Gibt es einen EBICS-Vertrag?~~ Erledigt durch F174: FinTS/HBCI ist der Weg; EBICS wird nicht vorgezogen.
4. **Die Registrierung bei der Deutschen Kreditwirtschaft** anstoßen (`fints.org/de/hersteller/produktregistrierung`).
   Sie ist der Schritt mit Vorlauf; alles andere wartet nicht darauf.

---

# Anhang A – Entwurf 1 vom 2026-11-17 (überholt, soweit Fassung 2 anderes sagt)

Stufe 1 dieses Entwurfs – der Datei-Import – ist mit F116 im Modul `import` gebaut, ohne eigenes Modul `bank` und ohne
eigene Tabelle der Umsätze. Die Entscheidungen O1–O12 wurden nie freigegeben; O4 (Buchungstag), O7 (jede Zuordnung
bestätigt ein Mensch) und O9 (Verwendungszweck nicht ungefragt in den Buchungstext) bleiben als Vorschläge stehen.

Kontoumsätze sind die dritte Quelle des Arbeitsvorrats: Jeder Umsatz, der noch keiner Buchung zugeordnet
ist, ist ein offener Vorgang. Er bringt mit, was bei unbaren Zahlungen sonst fehlt – den **Tag des
tatsächlichen Geldflusses** (BELEGEINGANG.md B8) – und gehört oft zu einem Beleg, der schon im Arbeitsvorrat
steht.

## A1. Was „Online-Konten“ umfasst

| Art | Weg | Stufe |
|---|---|---|
| Girokonto, Tagesgeld | Kontoauszug als Datei: **CAMT.053** (Tagesauszug) bzw. CAMT.052; ersatzweise MT940 oder CSV der Bank | **1** |
| dasselbe | Abruf über **FinTS/HBCI** direkt bei der Bank | 2 |
| Kreditkarte, PayPal und ähnliche | Datei (CSV) des Anbieters | 2, je Anbieter |

**Stufe 1 ist der Datei-Import**: Er braucht keine Zugangsdaten der Bank auf dem Server und keine
Registrierung. Der Abruf über FinTS kommt danach und hat eigene Hürden (Abschnitt A7).

## A2. Ablauf

```
Bank → Auszug als Datei → Hochladen in kumero → Umsätze im Mandanten
Umsatz ohne Buchung = offener Vorgang im Arbeitsvorrat
   → Abgleich mit Belegen im Arbeitsvorrat (Betrag, Datum, Rechnungsnummer im Verwendungszweck, IBAN)
   → Buchungserfassung vorbefüllt → Speichern (Stapel) oder Buchen
Umsatz ist erledigt, sobald die zugeordnete Buchung festgeschrieben ist
```

## A3. Bankkonten und Umsätze

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

## A4. Abgleich mit Belegen

Ein Vorgang, mehrere Quellen (BELEGEINGANG.md Abschnitt 1a): Zum Umsatz sucht kumero passende offene Belege.

| Treffer | Merkmale | Folge |
|---|---|---|
| sicher | Betrag gleich **und** Rechnungsnummer im Verwendungszweck (oder IBAN der Gegenseite = IBAN auf dem Beleg und nur ein Beleg mit diesem Betrag offen) | wird vorgeschlagen und ist mit einem Tastendruck bestätigt |
| möglich | Betrag gleich, Datum plausibel (Umsatz nach Rechnungsdatum, nahe an der Fälligkeit) | Auswahlliste |
| keiner | – | Umsatz steht allein im Arbeitsvorrat |

Nie wird still verbunden; jede Zuordnung bestätigt ein Mensch. Skonto und Teilzahlungen: Der Betrag darf
abweichen, wenn der Nutzer es bestätigt – die Differenz bucht er selbst (Splitbuchung); eine Automatik dafür
kommt mit dem Modul `opos`.

## A5. Vorbefüllen der Buchungsmaske aus einem Umsatz

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

## A6. Schnitt und Rechte

- Modul `bank`, abhängig vom Kern und von `belegeingang` (Arbeitsvorrat); `paperless` optional.
- Es meldet sich als Quelle am Arbeitsvorrat an und erzeugt Entwürfe nur über die Dienste des Kerns.
- Rechte: `bank.konto.pflegen`, `bank.umsatz.einlesen`, `bank.umsatz.sehen`, `bank.umsatz.zuordnen`
  (F41 nennt `bank.umsatz.zuordnen` bereits als Beispiel).

## A7. Stufe 2: Abruf über FinTS – was vorher zu klären ist

- **Registrierung:** Für den FinTS-Zugang verlangt die Deutsche Kreditwirtschaft eine registrierte
  Produktkennung des Programms. Die müsste das Projekt beantragen; ohne sie lehnen Banken den Zugang ab.
- **Starke Kundenauthentifizierung (PSD2):** Der Abruf verlangt regelmäßig eine TAN bzw. Freigabe in der
  App der Bank. Ein Abruf ganz ohne Menschen (Timer) ist damit nur eingeschränkt möglich.
- **Zugangsdaten** der Bank dürften nie in Datenbank oder Konfiguration stehen; sie gehörten in den
  Signierdienst bzw. würden je Abruf vom Nutzer eingegeben.
- **Eigene FinTS-Umsetzung in PHP** oder eine vorhandene freie Bibliothek – eine Abhängigkeit, die ins
  signierte Release käme (F77 E1) und zu prüfen wäre.
- L3: kein Dienstleister dazwischen; kumero spräche direkt mit der Bank.

## A8. Entscheidungen zur Freigabe

| # | Frage | Vorschlag |
|---|---|---|
| O1 | Umfang der ersten Stufe | Datei-Import (CAMT.053), Bankkonten, Umsätze als Quelle des Arbeitsvorrats, Abgleich und Vorbefüllen. FinTS als Stufe 2. |
| O2 | Formate | Zuerst CAMT.053; MT940 und CSV nur, wenn die Bank des Referenzbetriebs kein CAMT liefert. **Zu klären:** Welche Bank(en), welche Formate bietet sie an? |
| O3 | Bankkonto ↔ Sachkonto | Genau ein Sachkonto je Bankverbindung. |
| O4 | Tag des Geldflusses | Der **Buchungstag** laut Auszug, nicht die Valuta. Zu bestätigen. |
| O5 | Doppelte und Lücken | Erkennen wie in Abschnitt A3; Lücken anzeigen, nicht verhindern. |
| O6 | Auszugsdatei | Nicht aufbewahren, nur SHA-256. Alternative: als Beleg nach Paperless und am Umsatz verknüpfen. |
| O7 | Abgleich | Dreistufig wie Abschnitt A4; jede Zuordnung bestätigt ein Mensch. |
| O8 | Abweichender Betrag | Erlaubt nach Bestätigung; Differenz von Hand als Splitbuchung. |
| O9 | Verwendungszweck im Buchungstext | Nur Name der Gegenseite und Rechnungsnummer vorbelegen, den Verwendungszweck nicht ungefragt übernehmen (er kann Patientennamen enthalten und stünde dann unveränderlich im Journal). |
| O10 | Kein Buchungsfall | Umsatz mit Grund als erledigt kennzeichnen (z. B. Umbuchung zwischen eigenen Konten, schon anders gebucht). |
| O11 | Weitere Anbieter | Kreditkarte, PayPal usw. später je Anbieter als Datei-Import. **Zu klären:** Welche gibt es im Referenzbetrieb? |
| O12 | FinTS | Erst entscheiden, wenn Stufe 1 läuft: Registrierung beantragen ja/nein, TAN-Verfahren der Bank. |
