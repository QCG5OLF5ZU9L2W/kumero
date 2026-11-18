# IMPORT.md – Modul `import`: CSV-Dateien als Quelle des Arbeitsvorrats (Entwurf 1, zur Freigabe)

Stand: 2026-11-18. Bezug: F89 (Arbeitsvorrat, Vertrag für Quellen), F96 (Vorauswahl, Stapel, Deduplizieren),
F101 (Steuer), F31 (Entwürfe), F48 (Protokoll), F75 Nr. 9 (nur synthetische Testdaten), AGENTS.md Anhang A4
(Modul `import`: QIF, CSV, DATEV-Buchungsstapel), ONLINEKONTEN.md (Modul `bank`, zurückgestellt).
**Status: Entwurf. Nichts davon ist gebaut.**

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
| CAMT.053, FinTS | Kontoauszug in einem festen Format | **nicht hier** – Modul `bank` (ONLINEKONTEN.md) |

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

## 12. Entscheidungen zur Freigabe

| # | Frage | Vorschlag |
|---|---|---|
| C1 | Schnitt | Modul `import` als Quelle des Arbeitsvorrats; CSV zuerst, QIF als zweite Stufe; CAMT und FinTS bleiben beim Modul `bank`. |
| C2 | Eine Zeile = ein Eintrag | ja; Splitbuchungen der Quelle werden nicht zusammengesetzt (erste Stufe). |
| C3 | Einnahme / Ausgabe | die fünf Arten aus Abschnitt 4 (Vorzeichen, Vorzeichen umgekehrt, zwei Spalten, Kennzeichen, fest); unbestimmbare Zeilen werden nicht eingeliefert. |
| C4 | Geldkonto je Datei | Pflicht; daraus Soll bei Einnahme, Haben bei Ausgabe. Die Gegenseite bleibt offen und kommt aus Regeln oder vom Nutzer. |
| C5 | Vorauswahl durch die Quelle | Der Vertrag für Quellen wird erweitert: Eine Quelle darf das Geldkonto vorschlagen; der Nutzer kann es überschreiben. |
| C6 | Datum | Belegdatum = Buchungstag der Datei; Buchungsdatum = Valuta, sonst der Buchungstag. Zu bestätigen: ob bei Umsatzlisten das Belegdatum besser leer bleibt, weil der Beleg ein anderes Datum trägt. |
| C7 | Profile | Mandant gibt vor, Nutzer darf eigene haben; Wiedererkennen an der Kopfzeile; keine mitgelieferten Bankformate. |
| C8 | Doppelte | Schlüssel aus den Inhalten der Zeile mit laufender Nummer je Datei; bekannte Zeilen werden übersprungen und genannt. Umsatz und Beleg werden nicht von selbst verbunden (X3 Stufe 3). |
| C9 | Aufbewahren der Datei | nicht in kumero; nur Hash, Dateiname und Bericht. Die Datei gehört bei Bedarf nach Paperless. |
| C10 | Fehlerhafte Zeilen | bleiben draußen, stehen im Bericht; die übrigen werden eingeliefert. Alternative: bei einem Fehler gar nichts einliefern. |
| C11 | Zurückziehen | ein Import lässt sich als Ganzes zurückziehen, solange nichts davon in Arbeit oder gebucht ist. |
| C12 | Fremdwährung | Zeilen in anderer Währung werden eingeliefert, der Betrag bleibt leer und steht als Hinweis da (wie bei Paperless). |
| C13 | Umbuchungen zwischen eigenen Konten | offen: erkennen (Gegenpartei ist ein eigenes Geldkonto) und nur einmal vorschlagen – oder dem Nutzer überlassen. |
| C14 | Grenzen | 5 MB, 20 000 Zeilen je Datei; größere Dateien werden abgewiesen. |
| C15 | Testdaten | nur synthetisch nachgebaute Dateien im Aufbau der jeweiligen Quelle; echte Exporte kommen nie ins Repository oder in den Chat. |
