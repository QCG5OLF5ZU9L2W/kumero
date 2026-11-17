# KONTENRAHMEN.md – Modul `kontenrahmen` (Entwurf 1)

Stand: 2026-11-17. Bezug: F2, F7, F44, F46, F48, F58, F74, **F75**, **F76**, ANWENDUNG.md (E5, E8 d).
**Status: K1–K6 entschieden am 2026-11-17 (F79).** Umgesetzt in kumero 0.6.0 (Modul 0.1.0): Abschnitte 2, 3, 4, 7 und der Modulrahmen. Es folgen mit 0.7.0: Abschnitte 5 und 6 (Anlage EÜR, Zuordnung, Beitrag zur Jahressperre) und die REST-Schnittstelle aus Abschnitt 8.

Das Modul liest Kontenrahmen ein, legt daraus die Konten eines Mandanten an und pflegt je
Geschäftsjahr die Zuordnung der Konten zur Anlage EÜR. Es ist das erste Modul; mit ihm entsteht
auch der Modulrahmen aus ANWENDUNG.md Abschnitt 7 (Manifest, Migrationen je Modul, Aktivieren).

---

## 1. Ablauf in drei Schritten

```
Quelle (DATEV-PDF, CSV)
   │  1. einlesen   → Vorlage im eigenen Format + Prüfbericht      (nichts wird angewendet)
   ▼
Vorlage „SKR 81, eigener Kontenplan, eingelesen am …“
   │  2. anwenden   → Konten im Mandanten über kern.konto_anlegen   (F2, F74)
   ▼
Konten des Mandanten
   │  3. zuordnen   → je Geschäftsjahr: Konto → Zeile der Anlage EÜR (Vorschlag, dann Bestätigung)
   ▼
Jahressperre: Hash der Zuordnung als Beitrag (gj_sperren_beitrag, F75 Nr. 11)
```

Jeder Schritt ist einzeln aufrufbar, protokolliert und ohne den nächsten folgenlos.

## 2. Quellen und Konverter (Reihenfolge nach F76)

| # | Quelle | Inhalt | Kontoart | Stand |
|---|---|---|---|---|
| 1 | **DATEV-Kontenrahmen als PDF** | gedruckter Kontenrahmen mit Kontonummern, Beschriftungen, Bereichen und Funktionskennzeichen | aus dem Regelwerk | **zuerst** (K1) |
| 2 | **CSV** (Nummer; Bezeichnung; optional Kontoart) | eigene Listen, Ergänzungen, Exporte anderer Programme | aus der Datei oder dem Regelwerk | zuerst, als einfacher Weg für Ergänzungen |
| 3 | **DATEV-Format „Kontenbeschriftungen“** (CSV mit Kopfzeile `EXTF`/`DTVF`, Datenkategorie 20) | Kontonummer, Beschriftung, Sprach-ID, lange Beschriftung | aus dem Regelwerk | später, bei Bedarf |

### 2a. PDF-Konverter

- Text mit `pdftotext -layout` (Debian-Paket `poppler-utils`, kommt in den Installer); keine Texterkennung –
  ein PDF ohne Textebene (Scan) wird abgewiesen.
- Erkannt werden Zeilen der Form „Kontonummer  Beschriftung“ in den Spalten des DATEV-Layouts;
  Kontenklassen-Überschriften und Bereichsangaben werden mitgelesen, Funktionskennzeichen vermerkt.
- PDF-Layouts sind fehleranfällig: Der Prüfbericht zeigt jede Zeile, die wie ein Konto aussieht, aber
  nicht sicher gelesen wurde (z. B. umbrochene Beschriftung, zwei Spalten ineinander). Solche Zeilen
  korrigierst du in der Vorlage oder ergänzt sie per CSV; erst dann lässt sie sich anwenden.
- Aufbau des DATEV-PDF „Kontenrahmen für Ärzte, SKR 81“ (Stand 2015, festgestellt 2026-11-17):
  - Kopf mit Rahmen und Gültigkeitsjahr, Fußzeile mit Artikelnummer und Datum auf jeder Seite → werden
    als Fassung gelesen und sonst übersprungen.
  - Kontenklassen als Überschrift „Ziffer Titel“, darunter Untergruppen als einfache Textzeilen.
  - Kontozeile: optionales Funktionskennzeichen, vierstellige Nummer, Beschriftung, ggf. Fußnotenziffer.
    Beschriftungen können umbrechen (Folgezeile ohne Nummer).
  - Kennzeichen je Konto: automatische Vorsteuer, automatische Umsatzsteuer, Sammelkonto, allgemeine
    Funktion, Bereich (erst nach Zuweisung bebuchbar); je Bereich: keine Umsatzsteuer, Vorsteuer-,
    Umsatzsteuerfunktion. Sie werden **vermerkt, nicht übernommen** – Steuerlogik ist Sache des Moduls
    `steuer` (F58).
  - Kontenbereiche als Nummer mit Endung („0810“ und „-69“, oft in der Folgezeile) → in der Vorlage als
    Bereich vermerkt, nicht als einzelne Konten angelegt.
  - Personenkonten (fünfstellig, Debitoren/Kreditoren) gehören zu `opos` und werden übergangen.
- Tests laufen mit einem selbst erzeugten PDF im selben Aufbau und erfundenen Konten; das DATEV-PDF
  selbst kommt nicht ins Repository (F75 Nr. 2).

Gemeinsam für alle Konverter:
- Zeichensatz: DATEV-Dateien sind ANSI (Windows-1252), Semikolon, Text in Anführungszeichen mit
  verdoppelten Anführungszeichen; CSV wahlweise UTF-8 oder Windows-1252 (erkannt am BOM bzw. angegeben).
- Nur deutsche Beschriftungen (`de-DE`); fehlt die Sprach-ID, gilt Deutsch.
- **Prüfbericht** statt stiller Korrektur: Anzahl Konten, doppelte Nummern, Nummern mit abweichender
  Länge, Konten ohne erkennbare Kontoart, Konten außerhalb aller Bereiche des Regelwerks.
- Ergebnis ist immer eine **Vorlage**; eine Vorlage mit offenen Punkten kann nicht angewendet werden.
- Die eingelesene Datei selbst wird nicht gespeichert, nur ihr SHA-256 (Herkunftsnachweis, F75 Nr. 6).
- Echte Kontenpläne kommen nie ins Repository, in Tests oder Chats (F75 Nr. 9).

## 3. Vorlagenformat `kumero-kontenrahmen/1`

Eine Vorlage ist eine JSON-Datei in kanonischer Form (F29) – lesbar, exportierbar (L2), per Hash
eindeutig:

```json
{
  "format": "kumero-kontenrahmen/1",
  "name": "SKR 81 – Praxis, eigener Kontenplan",
  "rahmen": "SKR81",
  "fassung": "2015",
  "quelle": {"art": "datev-pdf", "datei_sha256": "…", "eingelesen_am": "2026-11-17T…Z"},
  "regelwerk": {"name": "skr81", "version": "1"},
  "konten": [
    {"nr": "1000", "bezeichnung": "Kasse", "kontoart": "aktiv", "kontoart_herkunft": "regel"},
    {"nr": "8100", "bezeichnung": "Honorare Privatpatienten", "kontoart": "ertrag", "kontoart_herkunft": "regel"}
  ]
}
```

`kontoart_herkunft`: `quelle` (aus der Datei), `regel` (aus dem Regelwerk) oder `hand` (im Prüfschritt gesetzt).

## 4. Regelwerk je Kontenrahmen

Weil weder das DATEV-PDF noch DATEV-Exporte eine Kontoart und keine EÜR-Zeile enthalten und DATEV den SKR 81 nicht mehr
pflegt (F76), bringt das Modul je Rahmen ein **eigenes Regelwerk** mit: Nummernbereiche → Kontoart und
→ vorgeschlagene Zeile der Anlage EÜR. Beispiel (Ausschnitt, Werte illustrativ):

```json
{"name": "skr81", "version": "1", "nummernlaenge": 4,
 "bereiche": [
   {"von": "0000", "bis": "0899", "kontoart": "aktiv",   "hinweis": "Anlagevermögen"},
   {"von": "1000", "bis": "1399", "kontoart": "aktiv",   "hinweis": "Finanzkonten"},
   {"von": "8000", "bis": "8999", "kontoart": "ertrag",  "euer": "betriebseinnahmen"}
 ]}
```

- Die Bereiche beschreiben nur den **Aufbau** (Nummernbereiche und ihre Bedeutung), keine Liste von
  DATEV-Beschriftungen – eigene Arbeit des Projekts (F75 Nr. 10).
- Das Regelwerk ist versioniert. Eine neue Version ändert nie bestehende Vorlagen oder bestätigte
  Zuordnungen; sie wird nur für neue Vorschläge genutzt.
- Das Regelwerk für SKR 81 erstellt das Projekt (F76 Nr. 2); für SKR 03/04 folgt es später.

## 5. Anlage EÜR je Jahr

- Der **Vordruck der Anlage EÜR** eines Jahres liegt als Datei im Modul: Zeile, Kennzahl,
  Bezeichnung, Abschnitt (Einnahmen, Ausgaben, Anlagevermögen, Entnahmen/Einlagen, Hinzurechnungen …).
  Quelle ist der amtliche Vordruck des BMF; amtliche Werke sind nach § 5 UrhG frei.
- Jedes Geschäftsjahr bezieht sich auf genau einen Vordruck (des Kalenderjahres, in dem es endet).
  Ein neuer Vordruck kommt mit einem Release des Moduls (F73) und betrifft nur Jahre, die ihn nutzen.
- Die Berechnung der EÜR selbst (Zufluss/Abfluss, AfA, Umsatzsteuer, F9) ist Sache des Moduls
  `euer`; `kontenrahmen` liefert nur die **Zuordnung** Konto → Zeile.

## 6. Daten im Schema `kontenrahmen` (Mandanten-Datenbank, F44)

| Tabelle | Inhalt |
|---|---|
| `angewendet` | welche Vorlage (Name, Rahmen, Fassung, Quelle, Hash) wann von wem angewendet wurde (F75 Nr. 6) |
| `vordruck` | Kopie der genutzten EÜR-Vordrucke (Jahr, Zeile, Kennzahl, Bezeichnung, Abschnitt) – damit ein gesperrtes Jahr auch nach Modul-Updates nachvollziehbar bleibt |
| `zuordnung` | Geschäftsjahr × Konto → Vordruckzeile; Herkunft (`vorschlag`/`bestaetigt`), bestätigt von/am |

Vorlagen selbst liegen installationsweit (K2), nicht im Mandanten.

Regeln:
1. Schreiben nur über Funktionen des Moduls; jede Änderung über `kern.modul_protokoll` mit
   Vorher/Nachher (F48). Rolle `kumero_app` hat auf das Schema nur SELECT und EXECUTE (wie F64).
2. **Sperre:** Vor der Jahressperre liefert das Modul den SHA-256 über die kanonische Form aller
   Zuordnungen des Jahres samt Vordruckkennung als Beitrag (`kern.gj_beitrag_setzen`). Den Beitrag gibt
   es nur, wenn **jedes im Jahr bebuchte Erfolgskonto** (Aufwand/Ertrag) eine bestätigte Zuordnung hat.
3. Ist das Jahr gesperrt, verweigert ein Trigger jede Änderung seiner Zuordnungen. Den Jahresstatus
   liest das Modul über eine Lesefunktion des Kerns, nicht aus Kern-Tabellen (F44).
4. Wiederöffnen (F36) gibt die Zuordnung wieder frei; die erneute Sperre verlangt einen neuen Beitrag.

## 7. Anwenden einer Vorlage auf einen Mandanten

- Konten werden ausschließlich über `kern.konto_anlegen` angelegt (F2, F64).
- Gibt es die Nummer schon: gleiche Kontoart → übersprungen (Bezeichnung nur auf Wunsch übernommen);
  andere Kontoart → Konflikt im Bericht, nichts geändert (bebuchte Konten sind fest, F74).
- Ein Rahmenwechsel mitten im Jahr ist nicht vorgesehen (F75 Nr. 8).
- Nach dem Anwenden schlägt das Modul für jedes offene Geschäftsjahr die EÜR-Zuordnung vor.

## 8. Rechte und Schnittstellen

Rechte (F41): `kontenrahmen.vorlage.einlesen`, `kontenrahmen.vorlage.anwenden`,
`kontenrahmen.zuordnung.sehen`, `kontenrahmen.zuordnung.aendern`. Vorlagen einlesen und anwenden
bekommt der Verantwortliche, Zuordnung ändern zusätzlich der Buchhalter, sehen alle mit Leserecht.

Kommandozeile:
```
bin/kumero kontenrahmen:einlesen DATEI --art=pdf|csv [--rahmen=SKR81]   → Vorlage + Prüfbericht
bin/kumero kontenrahmen:vorlagen                                          → Liste
bin/kumero kontenrahmen:anwenden VORLAGE MANDANT [--bezeichnungen]
```

REST unter `/m/{mandant}/api/v1/kontenrahmen/…` (Ergänzung zu openapi/v1.yaml, eigener Entwurf):
Vorlagen auflisten und anwenden, Zuordnung je Geschäftsjahr lesen, vorschlagen lassen, bestätigen
und einzeln ändern. Hochladen einer Quelldatei über die Oberfläche folgt mit der Oberfläche.

## 9. Was das Repository enthält

- **Referenz-Kontenrahmen** (F75 Nr. 3): synthetisch im Aufbau des SKR 81, rund 80 Konten mit
  Kontoart und EÜR-Zuordnung, im Vorlagenformat. Testgrundlage und Startpunkt ohne Fremddaten.
- **Regelwerk** `skr81` (Abschnitt 4).
- **Vordruck der Anlage EÜR** für 2025 und 2026.
- Testdateien (PDF im DATEV-Aufbau, CSV) **mit erfundenen Konten**.

## 10. Entscheidungen (2026-11-17)

| # | Frage | Entscheidung |
|---|---|---|
| K1 | Welche Quelle zuerst? | **DATEV-PDF.** Der Referenzbetrieb nutzt die PDFs. Dazu CSV als einfacher Weg für Ergänzungen; DATEV-Exportformat bei Bedarf später. |
| K2 | Wo liegen Vorlagen? | **Installationsweit als Dateien** unter `/var/lib/kumero/kontenrahmen/` im Vorlagenformat (Abschnitt 3). Der Mandant vermerkt die angewendete Vorlage samt Hash. |
| K3 | E-Bilanz-Zuordnung (F75 Nr. 7) | **Viel später, als eigenes Modul.** `zuordnung` bleibt für weitere Ziele offen. |
| K4 | PDF-Konverter | Durch K1 erledigt: Der PDF-Konverter kommt **zuerst** (Abschnitt 2a). |
| K5 | Pflicht zur Zuordnung vor der Sperre | Nur **bebuchte Erfolgskonten** des Jahres (Abschnitt 6 Nr. 2). |
| K6 | Modulrahmen | **Freigegeben:** `module/<name>/modul.json`, Migrationen je Modul über `db/migrieren.sh --modul`, `bin/kumero modul:aktivieren`, Rechte aus dem Manifest. |
