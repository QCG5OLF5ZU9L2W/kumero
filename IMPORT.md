# IMPORT.md – Modul `import`: CSV-Dateien und E-Rechnungen als Quelle des Arbeitsvorrats (Fassung 1, erste Stufe gebaut in 0.7.12 → F104)

Stand: 2026-11-18. Bezug: F89 (Arbeitsvorrat, Vertrag für Quellen), F96 (Vorauswahl, Stapel, Deduplizieren),
F101 (Steuer), F31 (Entwürfe), F48 (Protokoll), F75 Nr. 9 (nur synthetische Testdaten), AGENTS.md Anhang A4
(Modul `import`: QIF, CSV, DATEV-Buchungsstapel), ONLINEKONTEN.md (Modul `bank`, zurückgestellt).
**Status: Der reine Import ist gebaut (kumero 0.7.12, Modul `import` 0.1.0; Korrektur 0.1.1 mit kumero 0.7.15).** **E-Rechnungen (XRechnung, ZUGFeRD / Factur-X) liest das Modul seit 0.2.0 (kumero 0.7.16) – Abschnitt 15.** **Seit 0.4.0 (kumero 0.7.20, F112) geht auch eine XRechnung als reines XML an das Belegarchiv – Abschnitt 16, D5.** Was gebaut ist und wo die Umsetzung vom Entwurf abweicht, steht in Abschnitt 14.

Vorgabe des Verantwortlichen vom 2026-11-18: **Die nächste Datenquelle des Arbeitsvorrats sind CSV-Dateien, die in
kumero erfasst werden.** Schon beim Einlesen wird gefragt, **ob und wie Einnahmen und Ausgaben gekennzeichnet
sind** (eigene Spalten, negatives Vorzeichen usw.); daraus lassen sich Soll- bzw. Haben-Konto bestimmen. Quellen sind
z. B. Quicken / Finanzmanager oder Online-Konten mit einem Export der Daten.

---

## 1. Das Bild

Eine CSV-Datei ist eine Liste von Vorgängen, meist die Umsätze **eines Geldkontos** (Bankkonto, Kasse, Kreditkarte).
Das Modul `import` liest sie ein und liefert **je Zeile einen Eintrag** in den Arbeitsvorrat – über denselben Vertrag
wie jede Quelle (F89 W5). Es bucht nie selbst; gebucht wird aus dem Arbeitsvorrat, einzeln oder im Stapel.

```
Datei wählen → Aufbau erkennen → Spalten zuordnen → Einnahme/Ausgabe festlegen → Geldkonto wählen
            → Vorschau prüfen → einliefern → Arbeitsvorrat
```

Was beim ersten Mal eingestellt wird, merkt sich kumero als **Importprofil** („Sparkasse Girokonto“,
„Finanzmanager Export“). Beim nächsten Mal genügt: Datei wählen, Profil wird erkannt, Vorschau, einliefern.

## 2. Schritt 1: Aufbau der Datei erkennen

kumero schlägt vor, der Nutzer bestätigt oder ändert. Erkannt werden:

| Merkmal | Möglichkeiten |
|---|---|
| Zeichensatz | UTF-8 (mit und ohne BOM), Windows-1252 / ISO-8859-15 |
| Trennzeichen | Semikolon, Komma, Tabulator; Textbegrenzer `"` |
| Kopfzeile | in welcher Zeile die Spaltennamen stehen – Bankexporte haben davor oft Vorspann (Kontoinhaber, Zeitraum, Saldo) |
| Fußzeilen | Zeilen am Ende, die keine Umsätze sind (Summen, Endsaldo) |
| Datum | `TT.MM.JJJJ`, `TT.MM.JJ`, `JJJJ-MM-TT`, `MM/TT/JJJJ` – je Datei einheitlich |
| Zahlen | Dezimalkomma oder Dezimalpunkt, Tausendertrennzeichen, Währungszeichen, nachgestelltes Minus (`12,50-`) |

Die Datei wird auf dem Server gelesen (PHP), nicht im Browser. Grenzen: höchstens 5 MB und 20 000 Zeilen je Datei.

## 3. Schritt 2: Spalten zuordnen

Je Spalte der Datei ein Ziel – die Felder des Eintrags im Arbeitsvorrat (ARBEITSVORRAT.md Abschnitt 2):

| Ziel | typische Spaltennamen | Pflicht |
|---|---|---|
| Datum (Belegdatum) | Buchungstag, Datum, Buchungsdatum | ja |
| Tag des Geldflusses (Zahltag) | Valuta, Wertstellung | nein – fehlt er, gilt das Datum |
| Betrag | Betrag, Umsatz | ja (oder die Paare aus Abschnitt 4) |
| Gegenpartei | Name, Empfänger/Auftraggeber, Beguenstigter/Zahlungspflichtiger, Payee | nein |
| Kennung der Gegenpartei | IBAN, Kontonummer | nein |
| Betreff | Verwendungszweck, Buchungstext, Memo | nein – fehlt er, wird er aus Gegenpartei und Datum gebildet |
| Belegnummer | Belegnummer, Referenz, Schecknummer | nein |
| Art | Kategorie (Quicken / Finanzmanager), Umsatzart | nein – Grundlage der Regel „Art → Geschäftsvorfall“ |
| Währung | Währung | nein – nur die Buchwährung wird als Betrag übernommen (wie PAPERLESS.md 12.2) |
| Saldo | Saldo nach Buchung | nein – nur zur Kontrolle (Abschnitt 7) |
| nur anzeigen | alles Übrige | – steht beim Buchen neben der Maske |
| nicht übernehmen | | |

Mehrere Spalten dürfen auf dasselbe Textziel zeigen (Verwendungszweck 1 bis 4 → Betreff, zusammengefügt).
kumero schlägt die Zuordnung aus den Spaltennamen vor.

## 4. Schritt 3: Einnahme oder Ausgabe?

Die Kernfrage der Vorgabe. Der Nutzer wählt eine von fünf Arten; kumero schlägt die passende vor und zeigt an den
ersten Zeilen der Datei sofort, was daraus würde.

| Art | Wie die Datei es zeigt | Beispiel |
|---|---|---|
| **V – Vorzeichen** | eine Betragsspalte; negativ = Ausgabe, positiv = Einnahme | `-12,50` / `100,00` |
| **V′ – Vorzeichen umgekehrt** | wie V, aber positiv = Ausgabe (Kreditkartenabrechnungen) | `12,50` = Belastung |
| **Z – zwei Spalten** | je eine Spalte für Einnahme und Ausgabe (auch „Soll“ / „Haben“, „Belastung“ / „Gutschrift“); je Zeile ist genau eine gefüllt | `;12,50;` / `100,00;;` |
| **K – Kennzeichen** | Betrag ohne Vorzeichen, daneben eine Spalte mit Kennzeichen; der Nutzer sagt, welche Werte Ausgabe und welche Einnahme bedeuten | `12,50;S` / `100,00;H`, „Lastschrift“ / „Gutschrift“ |
| **F – fest** | die ganze Datei ist nur Ausgabe oder nur Einnahme | Liste von Barausgaben |

Regeln:

- Der Betrag im Eintrag ist immer positiv, die Richtung steht daneben (F24, ARBEITSVORRAT.md Abschnitt 2).
- **Nicht raten:** Eine Zeile, deren Richtung sich nicht bestimmen lässt (beide Spalten gefüllt, unbekanntes
  Kennzeichen, Betrag 0,00), wird nicht eingeliefert, sondern in der Vorschau als Fehler gezeigt.
- Bei Art Z mit „Soll“ / „Haben“ fragt kumero ausdrücklich, was „Soll“ in dieser Datei bedeutet – auf einem
  Kontoauszug der Bank ist Soll die Belastung, in einem Export aus einer Buchhaltung die Sollseite des Geldkontos.

## 5. Schritt 4: Geldkonto – daraus folgen Soll und Haben

Zu jeder Datei gehört **ein Geldkonto** des Mandanten (Bank, Kasse, Kreditkarte); es steht im Importprofil.
Damit ist eine Seite jeder Buchung bestimmt:

| Richtung | Soll | Haben |
|---|---|---|
| Ausgabe | **offen** (Aufwand, Verbindlichkeit …) | Geldkonto |
| Einnahme | Geldkonto | **offen** (Erlös, Forderung …) |

- Das Geldkonto kommt als **Vorauswahl der Quelle** an den Eintrag. Die offene Seite füllen wie bisher die Regeln
  (Gegenpartei → Konto, Art → Geschäftsvorfall) oder der Nutzer – einzeln oder im Stapel (F96 X1).
- **Zahlweise** folgt dem Geldkonto: Kasse → bar, sonst unbar. **Buchungsdatum** ist der Tag des Geldflusses
  (Valuta, sonst das Datum) – so, wie es die EÜR verlangt (BELEGEINGANG.md B8).
- Die Buchungsart kann im Profil vorgegeben werden (z. B. `BANK`).
- Steuer: Mit vorbelegtem Steuerkonto rechnet kumero aus dem Brutto (F101). Eine Umsatzliste nennt keine
  Steuerbeträge; ob aufgeteilt wird, entscheidet der Nutzer wie bei jedem Eintrag.

**Folge für den Arbeitsvorrat:** Der Vertrag für Quellen bekommt die Möglichkeit, **Konto oder Gegenkonto
vorzuschlagen**. Bisher darf nur der Nutzer vorauswählen. Vorschlag: Die Quelle liefert das Feld
„Geldkonto“; der Arbeitsvorrat setzt es je nach Richtung als Soll oder Haben, solange der Nutzer nichts anderes
gewählt hat. Damit gilt die Regel „Konto ist immer Soll“ (ARBEITSVORRAT.md Abschnitt 13) für solche Einträge weiter,
nur dass die richtige Seite schon gefüllt ist.

## 6. Importprofile

- Ein Profil hält: Name, Merkmale aus Abschnitt 2, Spaltenzuordnung, Art der Richtung, Geldkonto, Buchungsart.
- **Der Mandant gibt vor, der Nutzer darf eigene Profile haben** (wie immer, F96 Nr. 2).
- **Wiedererkennen:** Passt die Kopfzeile einer Datei zu einem Profil, wird es vorgeschlagen. Passen mehrere
  (zwei Konten derselben Bank), wählt der Nutzer.
- **Mitgelieferte Vorlagen:** keine bankspezifischen. Die Exporte der Banken ändern sich, und kumero soll keine
  gepflegte Liste fremder Formate versprechen (anders als bei Paperless, F94). Stattdessen der Vorschlag aus den
  Spaltennamen. Profile lassen sich als Datei aus- und einlesen, um sie weiterzugeben.
- Jede Änderung eines Profils steht mit Vorher- und Nachher-Stand im Protokoll (F48).

## 7. Vorschau und Kontrolle

Vor dem Einliefern zeigt kumero:

- Anzahl der Zeilen: neu, schon vorhanden (Abschnitt 8), fehlerhaft – fehlerhafte mit Zeilennummer und Grund;
- Summe der Einnahmen, Summe der Ausgaben, Zeitraum;
- **Saldenkontrolle**, wenn die Datei Salden nennt: Anfangssaldo + Einnahmen − Ausgaben = Endsaldo. Geht das nicht
  auf, wird das angezeigt; eingeliefert werden kann trotzdem (die Entscheidung liegt beim Nutzer, kein Pop-up, F32);
- die ersten Zeilen so, wie sie im Arbeitsvorrat stehen würden.

Eingeliefert wird **ganz oder gar nicht** für die fehlerfreien Zeilen; fehlerhafte bleiben draußen und stehen im
Bericht des Imports.

## 8. Doppelte vermeiden

Kontoauszüge überlappen sich: Wer monatlich exportiert, hat Zeilen doppelt.

- **Schlüssel einer Zeile:** Geldkonto, Datum, Tag des Geldflusses, Betrag, Richtung, Gegenpartei, Betreff – als
  Hash. Kommt dieselbe Zeile in derselben Datei mehrfach vor (zwei gleiche Abbuchungen am selben Tag), zählt eine
  laufende Nummer mit.
- Eine Zeile mit bekanntem Schlüssel ergibt **keinen zweiten Eintrag** (Vertrag für Quellen: derselbe Schlüssel
  ergibt nie einen zweiten Eintrag). Die Vorschau nennt sie als „schon vorhanden“.
- **Dieselbe Datei noch einmal:** wird am Hash der Datei erkannt und gemeldet.
- **Umsatz und Beleg zum selben Vorgang** (die Abbuchung aus der CSV und die Rechnung aus Paperless) werden
  **nicht von selbst** verbunden. Das ist der Vorschlag „vermutlich doppelt“ bzw. „gehört zusammen“ aus
  ARBEITSVORRAT.md X3, Stufe 3 – stapelfähig zu bestätigen, noch nicht gebaut. Bis dahin liegen beide im Vorrat;
  gebucht wird einer, der andere wird verworfen oder die Buchung bekommt den Beleg nachgereicht.

## 9. Quellen im Einzelnen

| Quelle | Was sie liefert | Besonderheiten |
|---|---|---|
| Online-Konto, CSV-Export der Bank | Umsätze eines Kontos | Vorspann und Fußzeilen, Richtung meist V, mehrere Verwendungszweck-Spalten, oft Windows-1252 |
| Kreditkartenabrechnung | Umsätze einer Karte | Richtung oft V′, Fremdwährung mit Kurs in eigenen Spalten |
| Quicken / Finanzmanager, CSV-Export | Buchungen eines Kontos mit Kategorie | Kategorie → Art; Splitbuchungen stehen als mehrere Zeilen oder gar nicht im CSV |
| Quicken / Finanzmanager, QIF | dasselbe, vollständiger (Splits, Kategorien) | eigenes Format, kein CSV – **zweite Stufe** des Moduls |
| eigene Tabelle (Kassenbuch in Excel, als CSV gespeichert) | beliebig | Richtung oft Z oder F |
| camt.053, camt.054 | Kontoauszug, Umsatzanzeige | Modul `import` (F116, Abschnitt 17) |
| FinTS | Onlineabruf | **nicht hier** – Modul `bank` (ONLINEKONTEN.md) |

Zu klären anhand einer **synthetisch nachgebauten** Beispieldatei je Quelle: wie Finanzmanager Splitbuchungen und
Umbuchungen zwischen eigenen Konten exportiert. Umbuchungen (Bank → Kasse) erscheinen in beiden Konten und dürfen
nur einmal gebucht werden.

## 10. Daten und Aufbewahrung

- Eine Importdatei enthält Zahlungsdaten und in einer Praxis oft Namen von Patienten (Art. 9 DSGVO): Sie bleibt in
  der Mandanten-Datenbank; Logs und Ereignisse nennen nur IDs, Zeilenzahlen und Codes.
- **Gespeichert wird je Import:** wer, wann, Profil, Dateiname, Hash der Datei, Zahl der Zeilen (neu, vorhanden,
  fehlerhaft) – als Nachweis, woher ein Eintrag stammt. Der Eintrag nennt Import und Zeilennummer.
- **Die Datei selbst** wird nach dem Einliefern nicht aufbewahrt (Vorschlag, siehe C9). Gehört sie zu den
  aufbewahrungspflichtigen Unterlagen, legt der Nutzer sie in Paperless ab – dort liegen alle Dokumente (T8).
- Ein Import lässt sich **zurückziehen**, solange keiner seiner Einträge in Arbeit oder gebucht ist.

## 11. Schnitt, Rechte, REST

- Modul **`import`** mit Manifest, eigenem Schema `import` (Profile, Importe, Zeilenschlüssel), abhängig von
  `arbeitsvorrat`. Es liefert über `Kumero\Modul\Arbeitsvorrat\Dienst` ein und liest keine Tabellen des Kerns (F44).
- Seite **Buchen → Arbeitsvorrat → „Datei einlesen“** und **Einstellungen → „Importprofile“**.
- Rechte: `import.datei.einlesen` (Erfasser, Buchhalter, Verantwortlicher, Steuerberater), `import.profil.pflegen`
  (Buchhalter, Verantwortlicher, Steuerberater) für die Vorgaben des Mandanten.

| Methode | Pfad | Zweck |
|---|---|---|
| POST | `/import/dateien` | Datei hochladen; liefert erkannte Merkmale, Spalten, passende Profile, erste Zeilen |
| POST | `/import/dateien/{id}/vorschau` | mit Profil oder Einstellungen: Zeilen, Summen, Fehler, schon vorhandene |
| POST | `/import/dateien/{id}/einliefern` | fehlerfreie neue Zeilen in den Arbeitsvorrat |
| DELETE | `/import/dateien/{id}` | hochgeladene, noch nicht eingelieferte Datei verwerfen |
| GET | `/import/importe`, `/import/importe/{id}` | bisherige Importe mit Bericht |
| POST | `/import/importe/{id}/zurueckziehen` | Import zurückziehen (Abschnitt 10) |
| GET / PUT / DELETE | `/import/profile…` | Importprofile |

Eine hochgeladene Datei, die nicht eingeliefert wird, löscht kumero nach 24 Stunden.

## 12. Stufen

Entschieden am 2026-11-18: Was von der Eigenart einzelner Programme abhängt, kommt in die **zweite Fassung des
Importers (noch ohne Nummer; 0.1.1 und 0.2.0 sind inzwischen anders vergeben)**, nicht in die erste.

| Fassung | Inhalt |
|---|---|
| **0.1.0** | CSV einlesen: Aufbau erkennen, Spalten zuordnen, Einnahme/Ausgabe (fünf Arten), Geldkonto, Importprofile, Vorschau mit Saldenkontrolle, Doppelte vermeiden, Bericht, Zurückziehen. Jede Zeile ein Eintrag. |
| **0.2.0** | E-Rechnungen: XRechnung (CII, UBL) und ZUGFeRD / Factur-X (PDF) – Abschnitt 15. Gebaut mit kumero 0.7.16. |
| **später** | Eigenarten von Quicken / Finanzmanager: Splitbuchungen, Umbuchungen zwischen eigenen Konten (C13), QIF als zweites Format. Voraussetzung: der Aufbau der Exporte an synthetisch nachgebauten Beispieldateien. |

## 13. Entscheidungen zur Freigabe

| # | Frage | Vorschlag |
|---|---|---|
| C1 | Schnitt | Modul `import` als Quelle des Arbeitsvorrats; CSV zuerst (0.1.0), Splits, Umbuchungen und QIF in einer späteren Stufe (entschieden am 2026-11-18); CAMT-Dateien seit F116 im Modul `import`, FinTS weiterhin im Modul `bank`. |
| C2 | Eine Zeile = ein Eintrag | ja; Splitbuchungen der Quelle werden nicht zusammengesetzt (erste Stufe). |
| C3 | Einnahme / Ausgabe | die fünf Arten aus Abschnitt 4 (Vorzeichen, Vorzeichen umgekehrt, zwei Spalten, Kennzeichen, fest); unbestimmbare Zeilen werden nicht eingeliefert. |
| C4 | Geldkonto je Datei | Pflicht; daraus Soll bei Einnahme, Haben bei Ausgabe. Die Gegenseite bleibt offen und kommt aus Regeln oder vom Nutzer. |
| C5 | Vorauswahl durch die Quelle | Der Vertrag für Quellen wird erweitert: Eine Quelle darf das Geldkonto vorschlagen; der Nutzer kann es überschreiben. |
| C6 | Datum | Belegdatum = Buchungstag der Datei; Buchungsdatum = Valuta, sonst der Buchungstag. Zu bestätigen: ob bei Umsatzlisten das Belegdatum besser leer bleibt, weil der Beleg ein anderes Datum trägt. |
| C7 | Profile | Mandant gibt vor, Nutzer darf eigene haben; Wiedererkennen an der Kopfzeile; keine mitgelieferten Bankformate. |
| C8 | Doppelte | **Entschieden am 2026-11-18:** Was hochwahrscheinlich zusammengehört (gleicher Betrag, gleiche Gegenpartei), wird **schon im Arbeitsvorrat markiert, ist sortierbar und zum Deduplizieren und Zusammenführen vorgesehen** – gebaut ist die Markierung (Spalte „Doppelt?“), das Zusammenführen folgt. Sonst wie vorgeschlagen: Schlüssel aus den Inhalten der Zeile mit laufender Nummer je Datei; bekannte Zeilen werden übersprungen und genannt. Umsatz und Beleg werden nicht von selbst verbunden (X3 Stufe 3). |
| C9 | Aufbewahren der Datei | **Gebaut wie vorgeschlagen (Frage des Verantwortlichen vom 2026-11-18: „Was passiert mit den Daten?“):** Die Daten gehen nicht verloren – jede Zeile liegt vollständig am Eintrag, auch die nicht zugeordneten Spalten. Die Datei selbst: nicht in kumero; nur Hash, Dateiname und Bericht. Die Datei gehört bei Bedarf nach Paperless. |
| C10 | Fehlerhafte Zeilen | **Entschieden am 2026-11-18: Sie werden markiert.** Eine fehlerhafte Zeile wird mit dem eingeliefert, was lesbar ist, bekommt einen Hinweis mit Zeilennummer und Grund und trägt alle ihre Spalten als Angaben. Sie steht im Bericht und im Arbeitsvorrat. |
| C11 | Zurückziehen | ein Import lässt sich als Ganzes zurückziehen, solange nichts davon in Arbeit oder gebucht ist. |
| C12 | Fremdwährung | Zeilen in anderer Währung werden eingeliefert, der Betrag bleibt leer und steht als Hinweis da (wie bei Paperless). |
| C13 | Umbuchungen zwischen eigenen Konten | **Zurückgestellt auf eine spätere Stufe (2026-11-18).** Offen: erkennen (Gegenpartei ist ein eigenes Geldkonto) und nur einmal vorschlagen – oder dem Nutzer überlassen. |
| C14 | Grenzen | 5 MB, 20 000 Zeilen je Datei; größere Dateien werden abgewiesen. |
| C15 | Testdaten | nur synthetisch nachgebaute Dateien im Aufbau der jeweiligen Quelle; echte Exporte kommen nie ins Repository oder in den Chat. |

## 14. Stand der Umsetzung (0.7.12) und Festlegungen zur Bestätigung

Gebaut: Abschnitte 2 bis 8 und 10 für CSV – Aufbau erkennen, Spalten zuordnen, die fünf Arten der Richtung, Geldkonto,
Importprofile, Vorschau mit Summen und Saldenkontrolle, Schlüssel der Zeilen, Bericht, Zurückziehen; im Arbeitsvorrat
die Spalte „Doppelt?“.

1. **Die Datei wird gar nicht auf dem Server abgelegt.** Sie bleibt im Browser und geht mit jeder Anfrage mit
   (erkennen, Vorschau, einliefern). Die Endpunkte heißen deshalb `POST /import/erkennen`, `/import/vorschau`,
   `/import/einliefern` statt `/import/dateien…`; das Löschen nach 24 Stunden entfällt.
2. **Eine Seite statt zwei:** „Datei einlesen“ im Bereich Buchen zeigt auch die bisherigen Importe und die
   Importprofile. Eine eigene Seite unter Einstellungen gibt es nicht.
3. **Zahlweise** (bar/unbar) wählt der Nutzer im Profil; sie wird nicht aus der Art des Geldkontos abgeleitet, weil
   das Modul keine Tabellen des Kerns liest (F44).
4. **Geldkonto als Vorauswahl nur beim Anlegen** eines Eintrags; eine spätere Lieferung überschreibt nie, was der
   Nutzer gewählt hat. Die Regel „Konto ist Soll, Gegenkonto ist Haben“ gilt weiter.
5. **Fehlerhafte Zeilen** tragen kein Geldkonto und keinen Betrag, wenn sich Richtung oder Betrag nicht lesen ließen.
   Ihr Schlüssel ist der Hash der ganzen Zeile.
6. **Nicht zugeordnete Spalten** stehen immer als Angaben am Eintrag (höchstens 40 je Zeile); ein Ziel „nicht
   übernehmen“ gibt es nicht.
7. **Markierung „Doppelt?“:** gleicher Betrag und – nach Entfernen von Rechtsform, Satzzeichen und Groß-/Kleinschreibung
   – gleiche Gegenpartei (auch wenn ein Name den anderen enthält) oder gleiche Belegnummer. Nur unter den offenen
   Einträgen, nur Markierung. Bei mehr als 200 Einträgen mit demselben Betrag wird nicht markiert.
8. **Dezimalzeichen und Datum** gelten je Datei; kumero schlägt sie vor.
9. **Rechte:** `import.datei.einlesen` genügt für das Einlesen und für eigene Profile; `import.profil.pflegen` für
   Vorgaben des Mandanten.

Nicht gebaut: Profile als Datei aus- und einlesen; Zusammenführen der markierten Einträge; alles aus der späteren Stufe (Quicken / Finanzmanager).

**Nachtrag 0.1.1 (kumero 0.7.15):** Der Vorschlag der Spaltenzuordnung erkennt „Zahlungsgrund“ / „Zahlungszweck“ als Betreff und „Buchungsart“ / „Umsatzart“ als Art. Dieselbe Datei wird nur einmal gelesen, auch wenn der Browser die Auswahl zweimal meldet. Die Nummer 0.1.1 ist damit eine Korrektur; die Stufe für Quicken / Finanzmanager (Splitbuchungen, Umbuchungen, QIF), zuerst „0.1.1“ genannt, hat seit kumero 0.7.16 keine feste Nummer mehr – 0.2.0 sind die E-Rechnungen.
Geprüft nur mit synthetisch nachgebauten Dateien, nicht mit einem echten Export.

## 15. E-Rechnungen: XRechnung und ZUGFeRD (Modul `import` 0.2.0, kumero 0.7.16 → F108)

Vorgabe des Verantwortlichen vom 2026-11-18: **ein Importer für den Arbeitsvorrat auch für ZUGFeRD und E-Rechnungen** –
Rechnungen in einem strukturierten, maschinenlesbaren XML-Format. **Es ist ein und derselbe Importer:** derselbe
Menüpunkt „Datei einlesen“, dieselbe Seite; kumero erkennt an der Datei, was sie ist. Im Menü steht „Datei einlesen“
als Unterpunkt **unter dem Arbeitsvorrat**, damit klar ist, dass in den Arbeitsvorrat eingelesen wird und nicht in die
Buchungsliste.

### Was gelesen wird

| Format | Datei | Syntax |
|---|---|---|
| XRechnung | `.xml` | UN/CEFACT CII (`CrossIndustryInvoice`) oder UBL 2.1 (`Invoice`, `CreditNote`) |
| ZUGFeRD ab 2.0, Factur-X | `.pdf` mit eingebettetem XML | CII |
| andere Rechnungen nach EN 16931 (z. B. Peppol BIS Billing) | `.xml` | UBL oder CII |

Nicht gelesen: ZUGFeRD 1.0 (anderer Aufbau; wird erkannt und benannt), verschlüsselte PDFs, PDFs ohne eingebettetes XML
(eine gescannte oder gewöhnliche PDF-Rechnung ist keine E-Rechnung – sie kommt über Paperless / nopaper).

### Vom Rechnungsfeld zum Eintrag

| Feld des Eintrags | aus der Rechnung (EN 16931) |
|---|---|
| Art | `rechnung`; `gutschrift` bei Rechnungsart 381 u. Ä. oder negativer Summe; `rechnungskorrektur` bei 384 (BT-3) |
| Richtung | folgt der Angabe des Nutzers: **Eingangsrechnungen** → Ausgabe, **Ausgangsrechnungen** → Einnahme; eine Gutschrift dreht sie |
| Gegenpartei, Kennung | Eingang: Rechnungssteller (BT-27) mit USt-IdNr. (BT-31), sonst Steuernummer; Ausgang: Empfänger (BT-44) |
| Belegnummer | Rechnungsnummer (BT-1); länger als 36 Zeichen: nur bei den Angaben, mit Hinweis |
| Datum | Rechnungsdatum (BT-2) |
| Betrag | Rechnungsbetrag brutto (BT-112) |
| Netto, Steuer | Summe netto (BT-109) und Steuer (BT-110) – **nur, wenn sie den Rechnungsbetrag ergeben** (F101 Nr. 6) |
| Zahlweise | `bar` bei Zahlart 10 (BT-81), sonst `unbar`; **ein Zahltag wird nie gesetzt** – er steht in keiner Rechnung |
| Betreff | „Rechnung / Gutschrift ‹Gegenpartei› ‹Nummer›“ |
| Angaben (neben der Maske) | Format und Profil, beide Parteien, Leistungsdatum, Fälligkeit, Steuer je Satz, Zahlbetrag, Zahlart, IBAN, Zahl und Namen der ersten Positionen, Dateiname und SHA-256 der Datei |

### Festlegungen aus der Umsetzung (zu bestätigen)

1. **Schnitt:** E-Rechnungen liest das Modul `import`, nicht ein eigenes Modul `e-rechnung` (AGENTS.md Anhang A4 nennt
   beides). Ein späteres Modul `e-rechnung` bliebe für das Erzeugen von Ausgangsrechnungen und die Prüfung frei.
2. **Welche Seite?** Die Datei sagt nicht, ob der Mandant Empfänger oder Aussteller ist. Der Nutzer wählt je Einlesen
   „Eingangsrechnungen“ (Vorgabe) oder „Ausgangsrechnungen“. Ein Abgleich mit dem Namen oder der USt-IdNr. des Mandanten
   wäre möglich, sobald kumero diese Stammdaten führt.
3. **Keine Prüfung der Rechnung:** kumero liest, was dasteht, und prüft die Rechnung nicht gegen EN 16931 oder die
   XRechnung-Regeln (kein Schema, kein Schematron) und rechnet die Positionen nicht nach. Fehlt etwas (Nummer, Datum,
   Betrag, Name), wird die Rechnung **markiert eingeliefert** (wie C10). Was gar keine E-Rechnung ist, wird mit Grund
   abgelehnt.
4. **Mehrere Steuersätze (geändert mit 0.2.1, kumero 0.7.17, F109):** Die Aufteilung je Satz geht als `steuerzeilen` an den
   Eintrag, wenn sie den Rechnungsbetrag ergibt. Mit vorbelegtem Steuerkonto bucht kumero **je Satz eine Sach- und eine
   Steuerzeile** – in der Maske und im Stapel. Das Steuerkonto je Satz folgt aus den Steuersätzen der Konten (F101):
   das vorbelegte Konto für seinen Satz, sonst das eine Aktivkonto (Vorsteuer) bzw. Passivkonto (Umsatzsteuer) mit
   diesem Satz. Ist es nicht eindeutig, bleibt das Feld in der Maske leer; im Stapel bleibt der Eintrag liegen und
   nennt den Satz. Ein steuerfreier Teil ist eine eigene Zeile ohne Steuer. Ergibt die Aufteilung den Betrag nicht,
   steht sie nur bei den Angaben.
5. **Fremdwährung:** wie C12 – der Betrag bleibt leer und steht als Hinweis da.
6. **Doppelte:** Schlüssel aus Rechnungssteller (USt-IdNr., sonst Name), Rechnungsnummer, Datum, Betrag, Rechnungsart und
   Seite. Dieselbe Rechnung als XML und im PDF ergibt deshalb **einen** Eintrag. Gegen den Beleg aus Paperless oder den
   Umsatz aus der CSV-Datei greift die Markierung „Doppelt?“ mit Vergleichen und Zusammenführen (F105) – von selbst wird
   nicht verbunden.
7. **Aufbewahrung (geändert mit 0.3.0, F110):** kumero hält kein Dokument dauerhaft. Das PDF liegt nur vorläufig in der
   Zwischenablage (Abschnitt 16) und geht mit der Buchung an das Belegarchiv. Für eine XRechnung als reines XML gilt
   dasselbe, wenn das Belegarchiv XML annimmt (F112, D5); sonst speichert kumero sie nicht – das Original gehört dann
   von Hand ins Belegarchiv.
8. **Sicherheit beim Lesen:** XML mit Dokumenttyp-Angabe (DOCTYPE, Entitäten) wird nicht gelesen, nichts wird aus dem Netz
   nachgeladen. Aus dem PDF werden nur die Datenströme durchgesehen (kein PDF-Leser), entpackt höchstens 20 MB.
9. **Grenzen:** 5 MB je Datei, 50 Dateien je Auswahl; E-Rechnungen und eine CSV-Datei werden nicht in einer Auswahl gemischt.
10. **REST:** `POST /import/rechnung/vorschau` und `/import/rechnung/einliefern` (Datei als Körper, `seite=eingang|ausgang`,
    `name`). Jede Datei ist ein Import im Verlauf (Profil „E-Rechnung“) und lässt sich zurückziehen (C11).

Geprüft nur mit synthetisch nachgebauten Dateien (`werkzeuge/e-rechnung-muster.php`), **nicht mit einer echten XRechnung
oder ZUGFeRD-Datei** und nicht mit den offiziellen Beispieldateien von KoSIT oder FeRD.

## 16. Zwischenablage, Vorschau und Übergabe an das DMS (Modul `import` 0.3.0, kumero 0.7.18 → F110)

Vorgaben des Verantwortlichen vom 2026-11-18: **Für das PDF fehlt die Vorschau. Beim Buchen muss das PDF in das DMS
übergeben werden** – und zwar **erst beim Buchen**: Wird ein Eintrag nicht gebucht (z. B. Fehlversand), fehlt der Grund,
die Datei aufzubewahren (DSGVO). **Auch nach dem Buchen wird gelöscht: kumero darf kein Dokument dauerhaft halten.**

### Grundsatz

**kumero hält kein Dokument dauerhaft.** Ein Original liegt in kumero nur in der **Zwischenablage** – so lange, bis
feststeht, was aus dem Eintrag wird:

| Was geschieht mit dem Eintrag | Was geschieht mit dem Original |
|---|---|
| offen, in Arbeit | liegt in der Zwischenablage; Vorschau und Original sind am Eintrag zu sehen |
| verworfen | **gelöscht** beim nächsten Abgleich des Arbeitsvorrats |
| Import zurückgezogen | **gelöscht**, sofort |
| gebucht (festgeschrieben) | an das DMS **übergeben**, der Buchung als **Beleg nachgereicht** (F27), in kumero **gelöscht** |

Es bleibt je Datei eine Zeile mit Dateiname, Größe, Prüfsummen, Zeitpunkten, Grund des Löschens und – nach der Übergabe –
dem Link im DMS. Der Inhalt ist dann weg.

### Ablauf

1. **Einliefern:** Das PDF einer E-Rechnung kommt in die Zwischenablage (`import.datei`, Mandanten-Datenbank). Der
   Eintrag bekommt einen **vorläufigen Belegverweis** `…/m/<mandant>/import/dateien/<id>` mit MD5 und SHA-256.
2. **Vorschau:** Das Modul `import` ist für diese Links ein **vorläufiges Belegarchiv** (F97). Damit gibt es die Vorschau
   überall, wo ein Beleg gezeigt wird – Arbeitsvorrat, Vergleich, Buchungsmaske. Das Bild ist die erste Seite des PDF
   (`pdftocairo` aus poppler-utils, wie beim Kontenrahmen-Import); „Beleg öffnen“ zeigt das PDF.
3. **Buchen:** Ein vorläufiger Link **gelangt nie in eine Buchung** – er würde später ins Leere zeigen und darf nicht
   versiegelt werden. Maske und Stapel lassen ihn weg (Kennzeichen „Zwischenablage“ am Belegfeld); der Kern lehnt ihn
   beim Anlegen, Ändern und Nachreichen ab (`422 beleg.vorlaeufig`).
4. **Übergabe:** Beim Abgleich des Arbeitsvorrats (im Hintergrund beim Öffnen, höchstens alle fünf Minuten, oder „Jetzt
   abgleichen“) sieht das Modul, dass der Eintrag gebucht ist. Es übergibt das Original über die Schnittstelle
   **`Belegablage`** an das DMS. Führt das DMS die Datei (erkannt an der Prüfsumme), wird der Belegverweis des DMS der
   Buchung **nachgereicht** und der Inhalt in kumero gelöscht. Verarbeitet das DMS noch, bleibt die Übergabe
   „gesendet“ und wird beim nächsten Abgleich abgeschlossen. Gesendet wird nur einmal.
5. **Ausstehend:** Ist kein DMS bereit (Paperless gesperrt oder nicht verbunden) oder fehlt dem Nutzer das Recht
   `kern.beleg.nachreichen`, bleibt das Original liegen, bis ein Abgleich es übergeben kann (wie F16, F59: ausstehend,
   wird nachgeholt). Die Seite „Datei einlesen“ nennt, wie viele Originale liegen und wie viele gesendet sind.

### Schnitt

- **Modul-API des Kerns:** `Kumero\Api\V1\Belegablage` (DMS nimmt ein Original an: `belegAblageBereit`,
  `belegUebergeben`), `Dienste::belegablage()` und `Dienste::belegNachreichen()`; Archive dürfen sich als
  `vorlaeufig` melden. Das Modul `import` kennt kein DMS beim Namen.
- **Modul `paperless`** (0.3.0) ist die erste Belegablage: `POST /api/documents/post_document/` mit Titel (Betreff des
  Eintrags) und Belegdatum; gesucht wird über die Dokumentliste und die `original_checksum`, nicht über `/api/tasks/`.
- **Modul `arbeitsvorrat`** (0.5.4): der Dienst nennt einer Quelle die Buchungen und die Merkmale ihres Eintrags.

### Festlegungen aus der Umsetzung (zu bestätigen)

| # | Festlegung |
|---|---|
| D1 | **Übergabe nach dem Festschreiben, Beleg nachgereicht** – nicht vor der Buchung. Paperless verarbeitet ein Dokument erst nach Sekunden bis Minuten; der Belegverweis geht aber in den Hash der Buchung ein (F88). Deshalb steht der Beleg nicht im versiegelten Kopf, sondern als Ereignis „Beleg nachgereicht“ (F27) an der Buchung – mit Link und Prüfsummen. |
| D2 | Ein **Entwurf** löst noch keine Übergabe aus; erst die festgeschriebene Buchung. |
| D3 | Im DMS gesetzt werden nur **Titel und Belegdatum**. Kein Korrespondent, keine Tags, kein Dokumenttyp – das ordnet das DMS (bzw. nopaper) selbst. |
| D4 | **Schon im DMS vorhanden** (gleiche Prüfsumme): Es wird nichts gesendet; der vorhandene Beleg wird nachgereicht. |
| D5 | **XRechnung als reines XML – entschieden am 2026-11-18 (F112): Paperless bleibt das System der Dokumente.** Paperless-ngx nimmt XML nur mit einem Parser-Plugin für E-Rechnungen an; im Referenzbetrieb wird es eingerichtet (`paperless-ngx-erechnung`), die lesbare Darstellung erzeugt dann Paperless – kumero baut keine eigene. Der Mandant stellt ein, dass sein Paperless XML annimmt; dann wird das XML wie das PDF zwischengelagert und nach der Buchung übergeben. Ohne die Einstellung bleibt es wie zuvor: nicht zwischengelagert, nicht übergeben. |
| D6 | **Verworfen und wieder geöffnet:** Die Datei ist dann gelöscht; die Vorschau nennt den Grund. Die Angaben der Rechnung bleiben am Eintrag. |
| D7 | Die Übergabe läuft mit dem **Zugang des Nutzers**, der den Abgleich auslöst (L2); je Abgleich höchstens fünf Übergaben. |
| D8 | Wird die Buchung später **storniert**, bleibt der Beleg im DMS – er gehört zur stornierten Buchung. |
| D9 | Der Inhalt liegt als `bytea` in der Mandanten-Datenbank und ist damit in deren Sicherung enthalten, solange er liegt. Die Leserolle der Datenbank sieht ihn nicht. |

**Nachtrag 0.3.1 (kumero 0.7.19) – noch einmal einlesen:** Liegt die Rechnung schon im Arbeitsvorrat, nennt die Prüftabelle den Zustand des Eintrags. Verworfen → das Einliefern öffnet ihn wieder (mit PDF). Offen ohne Original → das PDF wird nachgeliefert. In Arbeit oder gebucht → nichts geschieht. Zurückgezogen → gilt als neu.

**Nachtrag 0.4.0 (kumero 0.7.20, F112) – XRechnung als XML:**

- **Annahme:** Das Modul `import` fragt beim Einlesen über `Dienste::belegablageNimmt('application/xml')`, ob ein DMS XML annimmt – unabhängig davon, ob der Nutzer gerade verbunden ist. Nur dann kommt das XML in die Zwischenablage (`import.datei.typ = application/xml`); der Bericht nennt `original: zwischenablage|keins` und `original_typ: xml|pdf`.
- **Vorschau:** Das Vorschaubild ist ein Blatt (SVG) mit den Angaben, die kumero aus der Datei liest; „Beleg öffnen“ zeigt das XML. Die Darstellung der ganzen Rechnung entsteht erst im DMS.
- **Nachliefern:** Liegt die Rechnung offen und ohne Original im Arbeitsvorrat (eingelesen, als das DMS noch kein XML annahm), wird das XML beim erneuten Einlesen nachgeliefert.
- **Abgelehnt:** Lehnt das DMS ein Original ab (Einstellung an, Plugin fehlt), bleibt es liegen; der Fehlercode steht an der Datei (`abgelehnt_grund`), „Datei einlesen“ nennt die Zahl. Je Abgleich höchstens fünf Übergaben und fünf gescheiterte Versuche.

Festlegungen aus der Umsetzung (zu bestätigen):

| # | Festlegung |
|---|---|
| D10 | Die Einstellung gilt **je Mandant**, nicht je Verbindung eines Nutzers; Vorgabe: aus. |
| D11 | Liegt schon ein Original am Eintrag (XML oder PDF), tauscht ein späteres Einlesen der anderen Form es nicht aus. |
| D12 | Wird die Einstellung wieder ausgeschaltet, bleiben schon zwischengelagerte XML liegen, bis sie übergeben, verworfen oder zurückgezogen sind. |

**Nicht am echten Paperless mit Plugin gelaufen** – nur gegen den Nachbau. Zu bestätigen: `post_document` nimmt das XML an, `original_checksum` ist die Prüfsumme des XML.

**Nicht am echten Paperless gelaufen** – nur gegen den Nachbau. Zu bestätigen am Referenzbetrieb: dass Paperless 3.4.2
`post_document` so annimmt und dass die Suche über die Dokumentliste den neuen Beleg findet.

## 17. CAMT-Kontoauszüge und Umsatzanzeigen (F116)

„Datei einlesen“ erkennt camt.053 und camt.054 anhand des XML-Namensraums (.001.02 und .001.08).
Je Datei wird ein Bankkonto einem Geldkonto zugeordnet. Importiert werden gebuchte EUR-Umsätze.
Die Vorschau prüft Buchungsdatum, Valuta, Richtung, Beträge und die gemeldeten Anfangs- und Endsalden je Auszug.
Sammelbuchungen mit vollständigen Einzelbeträgen werden in Zahlungen aufgelöst. Bei widersprüchlichen
Summen oder unklaren Überschneidungen lehnt der Import die Datei mit einem Prüfhinweis ab.
Vorschau, Verlauf, Rücknahme und Rechte folgen dem vorhandenen Dateiimport. Kontoauszugs-XML wird nicht abgelegt.

Bei einer Sammelbuchung empfiehlt sich die Detaildatei zuerst. Kommt später der Kontoauszug mit derselben
Bankreferenz und demselben Gesamtbetrag, wird die Summenzeile als vorhanden gezählt. Ist die Summenzeile
bereits eingeliefert, meldet die Vorschau den Konflikt: einen noch offenen Import zurückziehen, Detaildatei
einlesen und anschließend den Auszug erneut einlesen. Bereits bearbeitete Einträge bleiben geschützt.
Bei nicht zuordenbaren Überlappungen beider Formate am selben Banktag ist manuelle Prüfung erforderlich.
