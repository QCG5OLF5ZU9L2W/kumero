# AUSWERTUNGEN.md – Auswertungen aus dem Journal (Fassung 2: erste Stufe gebaut in 0.7.35)

Stand: 2026-11-20. Bezug: F9 (ein Journal), F24 (Betrag und Seite), F33 (Sperre je Geschäftsjahr), F44/F45 (Module lesen
den Kern nur über die Modul-API; Basisabfragen gehören zum Kern), F80 (Bereich „Auswertungen“ im Menü), F91 (Tabellen
mit Filtern), F106 (nichts verschiebt sich), F126 (je Buch), Anhang A (Modul `auswertungen`).
**Status: U1–U14 freigegeben am 2026-11-20, zusammen mit der Vorgabe „der Benutzer soll sich auch eigene Auswertungen frei
erstellen können“. Die erste Stufe ist gebaut (kumero 0.7.35, Modul `auswertungen` 0.1.0, F128) – Abschnitt 8.**

## 1. Anlass

Vorgabe des Verantwortlichen vom 2026-11-20: Von dem, was für eine echte Buchhaltung fehlt, sind die Auswertungen das
Nächste. Heute gibt es nur das Kontoblatt und die Salden zum Stichtag. Wer bucht, kann nicht sehen, ob die Bücher im
Ganzen stimmen, und nichts davon aus der Hand geben.

## 2. Was die erste Stufe liefert

| Auswertung | Inhalt |
|---|---|
| **Summen- und Saldenliste** | je Konto: Vortrag, Soll und Haben des Zeitraums, Soll und Haben seit Jahresbeginn, Saldo; gruppiert nach Kontoart, mit Zwischensummen; unten die Kontrolle Summe Soll = Summe Haben |
| **Journal** | die Buchungen des Zeitraums in der Reihenfolge ihrer Nummern, je Buchung alle Zeilen, Belegdatum, Buchungsdatum, Text, Belegverweis, Storno-Bezug |
| **Kontoblätter** | alle Kontoblätter des Zeitraums hintereinander (heute nur einzeln im Modul `buchen`) |

Jede Auswertung gilt für **ein Buch** und einen **Zeitraum** (Monat, Quartal, Geschäftsjahr oder von–bis).

## 3. Regeln

1. **Nur lesen.** Eine Auswertung ändert nichts und bucht nichts.
2. **Festgeschrieben ist der Maßstab.** Gerechnet wird mit festgeschriebenen Buchungen. Entwürfe lassen sich
   zuschalten; die Auswertung ist dann oben und auf jeder Seite als **vorläufig** gekennzeichnet.
3. **Zeitraum nach Buchungsdatum** – es bestimmt das Geschäftsjahr (F33).
4. **Vortrag wird gerechnet, nicht gebucht.** Bestandskonten (aktiv, passiv) beginnen mit ihrem Saldo vom Tag vor dem
   Zeitraum, aus allen Buchungen davor. Erfolgskonten (Aufwand, Ertrag) beginnen in jedem Geschäftsjahr bei null. So
   stimmt die Liste über den Jahreswechsel, auch solange es keine Eröffnungsbuchungen gibt.
5. **Jede Auswertung nennt ihren Stand:** Buch, Zeitraum, Zeitpunkt der Erstellung, die letzte berücksichtigte
   Journalnummer – damit zwei Ausdrucke vergleichbar sind. In einem Testbuch und im Testbetrieb steht das auf jeder
   Seite.
6. **Storno bleibt sichtbar:** Im Journal stehen stornierte Buchung und Storno beide; in der Summenliste heben sie
   sich auf.
7. **Rechte:** Wer Buchungen sehen darf (`kern.buchung.sehen`), darf auswerten. Der Administrator des Mandanten sieht
   keine Auswertung (F43).

## 4. Ausgabe

- **Bildschirm:** Tabelle mit festem Kopf, Spaltenfilter wie im Arbeitsvorrat (F91), Klick auf ein Konto öffnet das
  Kontoblatt, Klick auf eine Buchung die Buchung.
- **Druck:** eigene Druckansicht (Seitenkopf mit Buch, Zeitraum, Stand; Seitenzahlen; Zwischensummen nicht am
  Seitenende abgeschnitten). Ein PDF entsteht über „Drucken → als PDF speichern“ des Browsers; kumero erzeugt in
  dieser Stufe selbst kein PDF.
- **CSV:** dieselben Zeilen als Datei – UTF-8 mit BOM, Semikolon, Dezimalkomma, Datum `TT.MM.JJJJ` (öffnet sich in
  Tabellenprogrammen mit deutschen Einstellungen ohne Nacharbeit).
- kumero hält keine erzeugte Datei vor (F110); die Datei entsteht bei der Anfrage.

## 5. Schnitt

- **Modul `auswertungen`** (Anhang A), je Buch aktivierbar, Bereich „Auswertungen“ im Menü. Kein eigenes Schema in
  dieser Stufe.
- **Kern:** Die Summen je Konto und Zeitraum sind eine **Basisabfrage des Kerns** (F45), neben `salden` und
  `kontoblatt`: `GET …/summen?von=…&bis=…&entwuerfe=…`. Das Journal gibt es schon (`GET …/buchungen`).
  Erst der Vertrag in `openapi/v1.yaml`, dann der Code (F18).
- Module, die später rechnen (Steuer, EÜR, Anlagen), hängen ihre Auswertungen in denselben Bereich.

## 6. Entscheidungen (freigegeben am 2026-11-20)

| Nr. | Vorschlag |
|---|---|
| U1 | Erste Stufe: Summen- und Saldenliste, Journal, Kontoblätter – in dieser Reihenfolge. |
| U2 | Eigenes Modul `auswertungen`; die Summen rechnet der Kern als Basisabfrage. |
| U3 | Zeitraum nach Buchungsdatum; wählbar Monat, Quartal, Geschäftsjahr, von–bis. |
| U4 | Festgeschriebene Buchungen als Maßstab; Entwürfe zuschaltbar, dann „vorläufig“. |
| U5 | Vortrag wird gerechnet (Regel 4); Eröffnungsbuchungen sind dafür keine Voraussetzung. |
| U6 | Spalten der Summenliste: Konto, Bezeichnung, Vortrag, Soll und Haben des Zeitraums, Soll und Haben seit Jahresbeginn, Saldo. Konten ohne Bewegung und ohne Saldo entfallen (abschaltbar). |
| U7 | Gruppierung nach Kontoart (Aktiv, Passiv, Aufwand, Ertrag) mit Zwischensummen; Kontenklassen des Kontenrahmens erst, wenn der Kontenrahmen sie liefert. |
| U8 | Ausgabe: Bildschirm, Druckansicht, CSV. Kein eigenes PDF in dieser Stufe. |
| U9 | CSV: UTF-8 mit BOM, Semikolon, Dezimalkomma, Datum `TT.MM.JJJJ`. |
| U10 | Jede Auswertung nennt Buch, Zeitraum, Zeitpunkt und letzte Journalnummer; Testbuch und Testbetrieb stehen auf jeder Seite. |
| U11 | Recht: wer Buchungen sehen darf. Kein eigenes Recht in dieser Stufe. |
| U12 | Ein Export für Dritte (Steuerberater, Betriebsprüfung) ist **nicht** Teil dieser Stufe. |
| U13 | Zweite Stufe, eigens zu beschreiben: **Steuerverprobung** (gebuchte Umsatzsteuer gegen die rechnerische aus den Erlös- und Aufwandskonten mit Steuersatz, F101) und die Werte der Umsatzsteuer-Voranmeldung (Modul `steuer`). |
| U14 | Danach: EÜR (Modul `euer`, F9), Bilanz und GuV, BWA; das Anlagenverzeichnis kommt aus dem Modul `anlagen` (ANLAGEN.md). |

## 7. Offen

- Sollen Tags (F8, z. B. Kostenstelle) schon in der ersten Stufe als Filter der Summenliste dienen?
- Vergleich mit dem Vorjahr als weitere Spalte – jetzt oder später?
- Große Bücher: Ab welcher Größe wird die Summenliste nicht mehr bei jeder Anfrage neu gerechnet? (Messen, bevor
  etwas gebaut wird.)

## 8. Was gebaut ist (kumero 0.7.35, F128) – und eigene Auswertungen

Auftrag des Verantwortlichen vom 2026-11-20: „dann bau mal in der Reihenfolge. Die Auswertungen bitte so bauen, dass der
Benutzer sich auch eigene Auswertungen frei erstellen kann. Wie üblich extrem benutzerfreundlich gestalten.“

### 8.1 Eine Seite, vier mitgelieferte Auswertungen, beliebig viele eigene

Eine Auswertung ist eine **Zusammenstellung** aus wenigen Bausteinen – keine Abfragesprache, kein Formelfeld:

| Baustein | Auswahl |
|---|---|
| Art | Kontenliste mit Summen · Journal · Kontoblätter |
| Zeitraum | laufender Monat, Vormonat, laufendes Quartal, Vorquartal, laufendes und voriges Geschäftsjahr, von–bis |
| Konten | leer = alle; einzelne Nummern, Bereiche `4200-4299`, Anfänge `48*`, mit Komma getrennt |
| Kontoarten | Aktiv, Passiv, Aufwand, Ertrag |
| Spalten (Kontenliste) | Vortrag, Soll, Haben, Soll und Haben seit Jahresbeginn, Saldo |
| Gliederung (Kontenliste) | nach Kontoart · **eigene Zeilen** · ohne |
| Eigene Zeilen | je Zeile eine Bezeichnung und Konten; **Summenzeilen** bilden Zwischensummen; wahlweise mit den Konten darunter |
| Schalter | Entwürfe mitrechnen · Konten ohne Bewegung und ohne Saldo weglassen |

Mitgeliefert und nicht änderbar: **Summen- und Saldenliste**, **Aufwand und Ertrag**, **Journal**, **Kontoblätter**. Jede ist
Ausgangspunkt für eine eigene: „Anpassen …“ ändert die Anzeige sofort, „Speichern unter …“ legt sie unter einem Namen
ab. Wer nichts speichern darf oder will, passt trotzdem an.

### 8.2 Regeln der eigenen Auswertungen

1. **Gespeichert wird die Zusammenstellung, keine Zahl.** Gerechnet wird bei jedem Aufruf neu aus dem Journal.
2. Eine gespeicherte Auswertung **gehört dem, der sie angelegt hat**. Mit dem Recht dazu gibt er sie **für alle im Buch**
   frei; ändern und löschen kann nur er. Für alle anderen ist sie Ausgangspunkt für eine eigene Kopie.
3. **Nichts verschwindet stillschweigend:** Eine eigene Gliederung nennt bewegte Konten, die in keiner Zeile stehen, und
   Konten, die in mehr als einer Zeile gezählt sind. Die Kontrolle „Soll = Haben“ erscheint nur, wenn alle Konten in
   der Auswertung stehen.
4. Saldo und Vortrag stehen mit **S** oder **H** dahinter; in der CSV als Zahl mit Vorzeichen (Soll positiv).
5. Die zuletzt gewählte Auswertung merkt sich der Browser je Buch.
6. Gespeicherte Auswertungen stehen im Schema `auswertungen` des Buchs; jede Änderung steht im Protokoll.

### 8.3 Wo es steht

| Teil | Wo |
|---|---|
| Summen je Konto und Zeitraum | Kern: `BuchungenController::summen`, `GET …/summen` (`openapi/v1.yaml`) |
| Gespeicherte Auswertungen | `module/auswertungen/migrationen/0001_auswertungen.sql`, `src/WebController.php`, `GET/POST/PUT/DELETE …/auswertungen/vorlagen` |
| Seite | `module/auswertungen/oeffentlich/auswertung.js` – rechnet Gliederung, Journal und Kontoblätter aus den Antworten des Kerns, druckt und schreibt CSV |
| Druck | `public/kern/ui/rahmen.css` (für alle Seiten), `auswertungen.css` |
| Tests | `tests/BuchenTest.php` (Summen), `tests/AuswertungenTest.php`, `tests/browser/auswertungen.mjs` |

**Umgesetzt mit 0.7.36 (Modul 0.1.1):** Der Verantwortliche will „Auswertungen“ im Bereich **„Buchführung“** des
Menüs, nicht als eigenen Bereich (ändert Abschnitt 5 und F80 für diesen Eintrag; siehe UEBERGABE.md „Gemerkt“).

### 8.4 Abweichungen vom Entwurf und Festlegungen aus der Umsetzung – zu bestätigen

| Nr. | Festlegung |
|---|---|
| E1 | Eigene Auswertungen bestehen aus den Bausteinen in 8.1. Formeln, Kennzahlen, Vergleichsspalten (Vorjahr, Vormonat) und Auswertungen nach Tags gibt es noch nicht. |
| E2 | Eigene Auswertungen sind persönlich; freigeben für alle im Buch dürfen Buchhalter, Verantwortlicher und Steuerberater. Speichern darf auch der Erfasser, der Prüfer nicht (er passt an, ohne zu speichern). |
| E3 | Ein Zeitraum liegt immer in **einem** Geschäftsjahr; über die Jahresgrenze rechnet die Summenabfrage nicht. |
| E4 | Kontoblätter entstehen aus dem Journal des Zeitraums und dem gerechneten Vortrag (Regel 4), nicht aus der Abfrage `kontoblatt` – dort beginnt auch ein Erfolgskonto mit dem Saldo aller Vorjahre. |
| E5 | Öffnen per **Doppelklick** auf Konto oder Buchung (führt in das Modul `buchen`); Spaltenfilter (F91) hat die Seite in dieser Stufe nicht. |
| E6 | Journal und Kontoblätter lesen höchstens 20.000 Buchungen je Aufruf und sagen es, wenn es mehr sind. |
| E7 | U11 geändert: Zum Speichern und Teilen gibt es zwei eigene Rechte; ansehen darf weiter, wer Buchungen sehen darf. |
| E8 | Der Dateiname der CSV enthält nur ASCII (Umlaute umschrieben), weil manche Browser sonst den ganzen Namen verwerfen. |
