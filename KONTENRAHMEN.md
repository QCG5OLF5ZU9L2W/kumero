# KONTENRAHMEN.md – Modul `kontenrahmen` (Entwurf 1)

Stand: 2026-11-17. Bezug: F2, F7, F44, F46, F48, F58, F74, **F75**, **F76**, ANWENDUNG.md (E5, E8 d).
**Status: K1–K6 entschieden am 2026-11-17 (F79).** Umgesetzt in kumero 0.6.0 (Modul 0.1.0): Abschnitte 2, 3, 4, 7 und der Modulrahmen. Seit kumero 0.6.1 (Modul 0.1.1, F80): Import, Vorlagen und Anwenden auch über REST und die Weboberfläche (Abschnitt 8). Seit 0.6.4 (Modul 0.1.3, F81): Kontoart aus dem Bilanz-/GuV-Posten der aktuellen DATEV-PDFs (Abschnitt 2b), Regelwerke `skr03` und `skr04`. Es folgen nach dem Buchen (0.7.0): Abschnitte 5 und 6 (Anlage EÜR, Zuordnung, Beitrag zur Jahressperre) samt REST.

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

### 2b. PDF mit Posten-Spalte (SKR 03/04 ab BilRUG, F81)

- Erkannt an der Kopfzeile „Bilanz-/GuV-Posten | Programmverbindung/Abschlusszweck | Kontenklasse“, je
  Seitenhälfte drei Spalten. Spaltengrenzen aus den senkrechten Linien im Tabellenkopf.
- Wortpositionen aus `pdftotext -bbox-layout`, Kastenlinien aus `pdftocairo -svg` (beides poppler-utils).
  Ein Konto gehört zu dem Posten, in dessen Kasten seine Zeile liegt; liegen mehrere Textblöcke zwischen
  zwei Linien, gilt jeder ab seiner ersten Zeile. Ein Kasten, der über das Spaltenende läuft, setzt sich
  oben in der nächsten Spalte fort.
- Die Kontozeilen liest derselbe Zeilenleser wie in 2a; ein Abstand von mehr als einer Zeile beendet eine
  umbrochene Beschriftung (Zwischenüberschriften).
- **Kontoart aus dem Posten** über `posten/hgb.json` (§§ 266, 275 HGB, eigene Zuordnung des Projekts):
  `kontoart_herkunft: posten`. Wechselposten nehmen die zuerst genannte Seite. Posten ohne eindeutige
  Seite (z. B. „Rechnungsabgrenzungsposten“) und Konten ohne Posten nehmen den Nummernbereich des
  Regelwerks. Widersprechen sich Posten und Nummernbereich, ist das Konto zu bestätigen.
- Posten und Programmverbindung (z. B. U, K, G) stehen am Konto der Vorlage.
- Konten mit Kennzeichen R (von DATEV reserviert) werden ausgelassen.
- Stand 2026 (DATEV-PDFs Art.-Nr. 11174/11175): SKR 03 rund 1 370 Konten, davon rund 1 310 mit Kontoart
  aus dem Posten und rund 110 zu bestätigen; SKR 04 rund 1 410 Konten, rund 75 zu bestätigen.
- Test mit einem selbst erzeugten PDF im selben Aufbau und erfundenen Konten
  (`werkzeuge/kontenrahmen-muster-posten-pdf.py`).

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

`kontoart_herkunft`: `quelle` (aus der Datei), `posten` (aus dem Bilanz-/GuV-Posten, F81), `regel` (aus dem Regelwerk) oder `hand` (im Prüfschritt gesetzt).

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
- Das Regelwerk für SKR 81 erstellt das Projekt (F76 Nr. 2); `skr03` folgt seit 0.6.3 (Wunsch des Verantwortlichen), SKR 04 später.

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

Weboberfläche und REST (seit 0.6.1, F80; Vertrag in openapi/v1.yaml, Tag „Kontenrahmen“):

| Wo | Endpunkt | Recht |
|---|---|---|
| systemweit | `GET /api/v1/kontenrahmen/regelwerke` | Verwalter |
| systemweit | `POST /api/v1/kontenrahmen/entwuerfe?regelwerk=skr81&name=…` – Datei im Körper (`application/pdf` oder `text/csv`) → **Entwurf** mit Prüfbericht | Verwalter |
| systemweit | `GET/DELETE /api/v1/kontenrahmen/entwuerfe/{id}`, `PUT …/korrekturen` (Kontoart, Bezeichnung, weglassen je Konto) | Verwalter |
| systemweit | `POST /api/v1/kontenrahmen/entwuerfe/{id}/vorlage` – nur ohne offene Punkte; Entwurf wird entfernt | Verwalter |
| systemweit | `GET /api/v1/kontenrahmen/vorlagen`, `…/vorlagen/{kennung}` | Verwalter |
| Mandant | `GET /m/{mandant}/api/v1/kontenrahmen/vorlagen`, `POST …/anwenden` | `kontenrahmen.vorlage.anwenden` |
| Mandant | `GET /m/{mandant}/api/v1/kontenrahmen/angewendet` | `kontenrahmen.angewendet.sehen` |

Entwürfe liegen unter `/var/lib/kumero/kontenrahmen/entwuerfe/` und werden nach 14 Tagen entfernt.
Die hochgeladene Datei wird nicht aufbewahrt, nur ihr SHA-256 (Quelle der Vorlage). Korrekturen gehen
wie eine CSV-Ergänzung in die Vorlage ein (`kontoart_herkunft: hand`, `quelle.ergaenzung_sha256`).
Das Recht `kontenrahmen.vorlage.einlesen` entfällt: Vorlagen sind installationsweit (K2), einlesen darf
deshalb der Verwalter. Zuordnung je Geschäftsjahr (lesen, vorschlagen, bestätigen) folgt mit Abschnitt 5.

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

## 12. EÜR-Kontenrahmen aus dem Vordruck des BMF (gebaut mit 0.7.80, F187)

Stand: 2026-11-22. Anlass: Für die Spielwiese (SPIELWIESE.md SW10, SW23) war ein SKR 03 gewünscht; das Projekt liefert
keine DATEV-Kontenrahmen aus (F75 Nr. 2). Entscheidung des Verantwortlichen: **„Wir bauen einen Kontenrahmen aus der EÜR
des BMF – behördliche Informationen haben kein Urheberrecht.“**

**Die Idee:** Die Anlage EÜR gliedert Betriebseinnahmen und Betriebsausgaben schon so, wie ein Betrieb mit
Einnahmenüberschussrechnung sie am Ende erklären muss. Ein Kontenrahmen, dessen Erfolgskonten diesen Zeilen folgen, ist
ohne fremde Vorlage vollständig – und seine Zuordnung zur Anlage EÜR (F75 Nr. 7, F76 Nr. 2) ergibt sich von selbst.

| # | Frage | Vorschlag |
|---|---|---|
| KE1 | Wem dient er | **Jedem Betrieb mit EÜR, nicht nur der Spielwiese:** ein zweiter mitgelieferter Kontenrahmen neben der synthetischen Referenz im Aufbau des SKR 81 – „kumero ohne Fremddaten startfähig“ (F75 Nr. 3). Er steht im Release unter `module/kontenrahmen/referenz/`. |
| KE2 | Quelle | Der amtliche Vordruck der Anlage EÜR mit Anleitung, wie ihn das BMF bekannt macht, für das jeweilige Jahr. **Gebaut wird aus dem Vordruck selbst, nicht aus dem Gedächtnis:** Die Datei nennt Jahr, Fundstelle und Abrufdatum. |
| KE3 | Erfolgskonten | Je Zeile der Betriebseinnahmen und der Betriebsausgaben ein Konto, mit der Bezeichnung der Zeile; wo eine Zeile im Alltag mehrere Konten braucht (Raumkosten, Fahrzeugkosten, sonstige Ausgaben), wenige Unterkonten mit eigenen, allgemeinen Bezeichnungen. |
| KE4 | Was der Vordruck nicht hergibt | Bestandskonten gibt es dort nicht: Kasse, Bank, Geldtransit, Anlagevermögen in den Gruppen des Anlagenverzeichnisses, Vorsteuer und Umsatzsteuer je Satz, Umsatzsteuer-Zahllast, Privatentnahmen und -einlagen, durchlaufende Posten, Kapital/Saldenvortrag. Diese Konten bekommen eigene, allgemeine Bezeichnungen. |
| KE5 | Nummern | Ein eigenes Schema, erkennbar kein SKR: Die Nummern sind über die Jahre fest; **die Zeilennummern des Vordrucks ändern sich von Jahr zu Jahr und stehen deshalb nicht in der Kontonummer**, sondern in der Zuordnung je Geschäftsjahr (F75 Nr. 7). |
| KE6 | Zuordnung zur Anlage EÜR | Mitgeliefert je Jahr des Vordrucks, für die Erfolgskonten eins zu eins. Damit kommt ein Teil dessen, was für 0.8.0 vorgesehen ist (P14), mit diesem Kontenrahmen früher – nur die Zuordnungsdatei, nicht das Modul `euer`. Zu bestätigen. |
| KE7 | Regelwerk für Kontoarten | Eigenes Regelwerk `euer` neben `skr03`, `skr04`, `skr81` – oder die Kontoart steht fest in der Datei. Vorschlag: fest in der Datei; es ist kein fremder Rahmen zu deuten. |
| KE8 | Steuersätze | Die Konten für Vorsteuer und Umsatzsteuer tragen ihren Satz in der Bezeichnung, damit das Modul `buchen` ihn vorschlagen kann (F102). |
| KE9 | Pflege | Jeder neue Vordruck ist eine Pflegeaufgabe (wie F94): Zeilen vergleichen, Zuordnung des Jahres ergänzen, neue Zeilen als Konten anbieten – nie ein vorhandenes Konto umdeuten (F74). |
| KE10 | Recht | Amtliche Werke genießen nach § 5 UrhG keinen urheberrechtlichen Schutz; für „andere amtliche Werke“ nach Absatz 2 gelten Quellenangabe und Änderungsverbot. Deshalb: Quelle nennen, Zeilenbezeichnungen unverändert übernehmen, Eigenes als Eigenes kennzeichnen. **Einschätzung ohne Gewähr, vom Verantwortlichen zu prüfen.** |

Nicht Teil dieses Schritts: das Modul `euer`, die Übermittlung, Betriebe mit Bilanz.

**KE1–KE10 bestätigt am 2026-11-22, 14:31 (F185):** „kann man so machen“. Rechtliche Hinweise in der Oberfläche zeigt der
Pförtner der Spielwiese, nicht kumero; die Quellenangabe steht in der `LIESMICH.md` neben der Datei.

### 12.1 Stand der Quelle (2026-11-22)

- **Maßgeblich ist der Vordruck 2026:** BMF-Schreiben vom 1. September 2026, IV C 6 - S 2142/00024/010/001, „Standardisierte
  Einnahmenüberschussrechnung nach § 60 Absatz 4 EStDV; Anlage EÜR 2026“, veröffentlicht auf bundesfinanzministerium.de.
- **Gegenüber 2025 sind Zeilen verschoben** (eine neue Zeile für Land- und Forstwirte; die Summe der Betriebsausgaben steht
  in Zeile 76 statt 75) – nach Angaben Dritter, am Vordruck zu prüfen. Das bestätigt KE5: Zeilen gehören in die Zuordnung
  je Jahr, nicht in die Kontonummer.
- **Zum Recht (KE10)** sagt das BMF auf seiner Formularseite selbst: Formulare sind im Regelfall nach § 5 UrhG nicht
  geschützt, eine Erlaubnis ist im Regelfall nicht nötig; es gelten Änderungsverbot und Quellenangabe (§§ 62, 63 UrhG), und
  weil die Vordrucke mit den Ländern abgestimmt sind, nennt die Quellenangabe auch das Finanzministerium des Landes, in dem
  der Nutzer sitzt. Weiter ohne Gewähr, vom Verantwortlichen zu prüfen.
- **Nicht gebaut, weil der Vordruck nicht verlässlich vorlag:** Die PDF-Datei des BMF ließ sich aus der Sitzung nicht
  abrufen. Eine Kopie bei Dritten war nur über eine Textauswertung lesbar, die Zeilennummern erschloss und lange
  Bezeichnungen kürzte – das genügt weder KE2 (aus dem Vordruck selbst) noch KE10 (unveränderter Wortlaut).
  **Nötig: die PDF-Datei des BMF im Chat oder im Arbeitsverzeichnis** (nicht im Repository). Dann wird mit `pdftotext`
  gelesen und jede Zeile gegen das Bild der Seite geprüft.

### 12.2 Gebaut mit 0.7.80 (F187) – Festlegungen aus der Umsetzung, zu bestätigen

Der Verantwortliche hat die PDF-Datei des BMF am 2026-11-22 in den Chat gelegt; damit ist 12.1 letzter Punkt erledigt.
Gelesen mit `pdftotext -layout`; jede Zeile 12 bis 76 am Text abgelesen, der Wortlaut aller Zeilen danach maschinell
gegen den Text der Datei verglichen (Buchstabenfolge; Zeilennummern und Kennzahlen von Hand). Der Vordruck liegt nicht
im Repository.

Entstanden: `module/kontenrahmen/referenz/euer-kontenrahmen.csv` (95 Konten), `euer-zuordnung-2026.csv`,
`LIESMICH.md` mit Quelle; `module/kontenrahmen/regeln/euer.json`; `werkzeuge/euer-kontenrahmen.py`;
`KontenrahmenTest::testEuerKontenrahmen`. Modul `kontenrahmen` 0.2.0.

| # | Festlegung |
|---|---|
| KE11 | **Bezeichnung des Kontos = Wortlaut der Zeile ohne Verweise** auf Zeilennummern, Anleitung und Überträge („(Übertrag aus Zeile 63 der Anlage AVEÜR)“ entfällt) – sonst stünde entgegen KE5 doch eine Zeile des Jahres im Konto. Der unveränderte Wortlaut steht in der Zuordnung des Jahres; die Spalte „Herkunft“ sagt je Konto, was es ist (KE10). |
| KE12 | **Nummern:** vier Stellen, erste Ziffer nach dem Aufbau des Vordrucks – 1 Betriebseinnahmen, 2 bis 4 Betriebsausgaben, 5 Anlagevermögen (Gruppen der Anlage AVEÜR), 6 Geld, 7 Umsatzsteuer, 8 Privat und Kapital. |
| KE13 | **Bestätigt (2026-11-22, 14:52: „dann nimm das Regelwerk“).** **Doch ein Regelwerk `euer`** (anders als KE7 vorgeschlagen): Das Einlesen verlangt eines, und die Oberfläche bietet es so zur Auswahl an. Die Kontoart steht zusätzlich in der Datei; der Test prüft, dass beide dasselbe sagen. |
| KE14 | **Zweispaltige Zeilen** (Geschenke, Bewirtung, Arbeitszimmer, sonstige beschränkt abziehbare) bekommen je ein Konto „abziehbar“ und „nicht abziehbar“ – wer bucht, teilt auf. |
| KE15 | **Kein Konto** für Zeilen, die nicht gebucht werden: Betriebsausgabenpauschale (24), Überträge aus der Anlage LuF (25–27), Rücklagen (22, 60), Wege zwischen Wohnung und Betriebsstätte samt Entfernungspauschale (73, 74), nicht abziehbare Beträge als Kürzung (75). Sie stehen in der Zuordnung mit Art „-“. |
| KE16 | **Umsatzsteuer in der Zuordnung nach Seite, nicht nach Saldo:** vereinnahmte Umsatzsteuer (17) = Haben der Umsatzsteuerkonten, gezahlte Vorsteuer (58) = Soll der Vorsteuerkonten, Zahlung an das Finanzamt (59) = Soll, Erstattung (18) = Haben des Kontos „Umsatzsteuer-Zahllast“. Das ist ein Vorschlag für das spätere Modul `euer`; Stornos und Umbuchungen sind dabei nicht bedacht. |
| KE17 | **Unterkonten nach KE3:** Betriebseinnahmen 19 % und 7 %; umsatzsteuerfrei, nicht steuerbar, 0 %, § 13b; Löhne, Sozialversicherung, Lohnsteuer; Strom/Heizung/Wasser, Reinigung, Erhaltung am Grundstück; Beiträge, Versicherungen; Bürobedarf, Porto, Fachliteratur; Kosten des Geldverkehrs; Treibstoff, Kfz-Reparaturen. Keine Konten für § 13b als Leistungsempfänger und für Umlaufvermögen nach § 4 Abs. 3 Satz 4 EStG. |

**Geprüft:** `php tests/lauf.php Kontenrahmen` (151 Prüfungen, davon 84 neu). **Nicht geprüft:** das Einlesen über die
Kommandozeile und die Oberfläche von Hand, das Anwenden gerade dieser Vorlage auf ein Buch, das Buchen darauf; ob die
Zuordnung steuerlich trägt – das prüft der Verantwortliche. Die Zuordnung 2025 gibt es nicht.
