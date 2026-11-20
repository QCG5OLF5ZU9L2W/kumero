# AUSWERTUNGEN.md – Auswertungen aus dem Journal (Entwurf 1, zur Freigabe)

Stand: 2026-11-20. Bezug: F9 (ein Journal), F24 (Betrag und Seite), F33 (Sperre je Geschäftsjahr), F44/F45 (Module lesen
den Kern nur über die Modul-API; Basisabfragen gehören zum Kern), F80 (Bereich „Auswertungen“ im Menü), F91 (Tabellen
mit Filtern), F106 (nichts verschiebt sich), F126 (je Buch), Anhang A (Modul `auswertungen`).
**Status: Entwurf. Gebaut wird erst nach Freigabe** (Entscheidungen U1–U14 in Abschnitt 6).

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

## 6. Entscheidungen zur Freigabe

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
