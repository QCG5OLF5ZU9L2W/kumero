# Mitgelieferte Kontenrahmen (F75 Nr. 3)

## EÜR-Kontenrahmen (F184, F185, F187; KONTENRAHMEN.md Abschnitt 12)

`euer-kontenrahmen.csv` ist ein **eigener Kontenrahmen des Projekts kumero** für Betriebe mit
Einnahmenüberschussrechnung – kein SKR, keine DATEV-Beschriftungen.

```bash
php /var/www/kumero/bin/kumero kontenrahmen:einlesen /var/www/kumero/module/kontenrahmen/referenz/euer-kontenrahmen.csv --rahmen EUER --name "EÜR-Kontenrahmen"
```

**Quelle der Erfolgskonten:** Vordruck „Einnahmenüberschussrechnung – Anlage EÜR“ 2026 und „Anlage AVEÜR“ 2026,
bekannt gegeben mit Schreiben des Bundesministeriums der Finanzen vom 1. September 2026,
IV C 6 - S 2142/00024/010/001, „Standardisierte Einnahmenüberschussrechnung nach § 60 Absatz 4 EStDV; Anlage EÜR 2026“
(im Einvernehmen mit den obersten Finanzbehörden der Länder; veröffentlicht auf bundesfinanzministerium.de,
gelesen am 2026-11-22 aus der PDF-Datei des Ministeriums). Das Ministerium weist auf seiner Formularseite darauf hin,
dass bei einer Quellenangabe auch das Finanzministerium des Landes zu nennen ist, in dem der Nutzer sitzt – wer die
Dateien weitergibt oder abdruckt, ergänzt das. Der Vordruck selbst liegt nicht im Repository.

**Was woher kommt** (Spalte „Herkunft“ der Datei; kumero liest nur die ersten drei Spalten):

| Herkunft | Bedeutung |
|---|---|
| `Vordruck` | Bezeichnung der Zeile, Wort für Wort |
| `Vordruck, angepasst` | Bezeichnung der Zeile ohne Verweise auf Zeilennummern, Anleitung und Überträge; bei zweispaltigen Zeilen mit „abziehbar“ oder „nicht abziehbar“, bei den Kfz-Kosten mit „Kfz:“ davor |
| `Anlage AVEÜR` | Gruppe des Anlageverzeichnisses |
| `eigen` | Konto des Projekts: Unterkonten für den Alltag, Geld, Umsatzsteuer, Privat, Kapital |

**Nummern:** eigenes Schema, über die Jahre fest. Die erste Ziffer folgt dem Vordruck: 1 Betriebseinnahmen,
2 bis 4 Betriebsausgaben, 5 Anlagevermögen, 6 Geld, 7 Umsatzsteuer, 8 Privat und Kapital. **Zeilennummern des Vordrucks
stehen in keiner Kontonummer und keiner Bezeichnung** – sie ändern sich von Jahr zu Jahr.

`euer-zuordnung-2026.csv` ordnet je Zeile 12 bis 76 des Vordrucks 2026 Kennzahl, unveränderten Wortlaut und Konten zu.
Spalte „Art“: `saldo` (Saldo der Konten), `soll`/`haben` (nur diese Seite der Bewegungen – Vorsteuer, Umsatzsteuer,
Zahllast), `davon` (Teilbetrag einer anderen Zeile), `summe` (rechnet der Vordruck), `-` (kein Konto: Pauschalen,
Überträge aus anderen Anlagen, Berechnungen der Erklärung wie die Entfernungspauschale). Die Datei wird noch von keinem
Programmteil gelesen.

**Pflege:** Beide Dateien erzeugt `werkzeuge/euer-kontenrahmen.py`. Mit jedem neuen Vordruck: Zeilen vergleichen,
Zuordnung des Jahres ergänzen, neue Zeilen als Konten anbieten – nie ein vorhandenes Konto umdeuten (F74).

**Ohne Gewähr.** Der Kontenrahmen ist keine steuerliche Beratung; welche Zeile eine Ausgabe trifft, entscheidet, wer bucht.

## Referenz im Aufbau des SKR 81

`referenz-skr81.csv` ist ein **eigener, synthetischer** Kontenplan im Aufbau des SKR 81:
allgemeine Kontenbezeichnungen, keine DATEV-Beschriftungen. Er dient als Testgrundlage und
als Startpunkt ohne Fremddaten:

```bash
php /var/www/kumero/bin/kumero kontenrahmen:einlesen /var/www/kumero/module/kontenrahmen/referenz/referenz-skr81.csv --name "Referenz SKR 81"
```
