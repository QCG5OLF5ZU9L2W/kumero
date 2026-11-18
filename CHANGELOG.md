# Änderungen

Format nach [Keep a Changelog](https://keepachangelog.com/de/), Versionen nach [SemVer](https://semver.org/lang/de/).
Jede Version wird erst installiert, wenn der Projektverantwortliche das Release signiert hat (F73).

## [Unveröffentlicht]

### Behoben
- **Nach einem Update lief alter Kern mit neuen Modulseiten** („The requested module '/kern/ui/beleg.js' doesn't provide an export named …“): Die Dateien des Kerns unter `/kern/` haben keine Version im Pfad, und nginx lieferte sie ohne Angabe zur Gültigkeit – der Browser nahm sie aus seinem Zwischenspeicher. Die nginx-Vorlagen setzen dafür jetzt `expires -1` (der Browser fragt jedes Mal nach, ob sich die Datei geändert hat). **Auf bestehenden Installationen von Hand nachzuziehen** – `aktualisieren.sh` schreibt die nginx-Konfiguration nicht neu.

## [0.7.18] – 2026-11-18

### Neu – kumero hält kein Dokument dauerhaft (F110)
- **Vorschau für das PDF einer E-Rechnung:** Das PDF liegt nach dem Einliefern in einer **Zwischenablage** (Modul `import` 0.3.0, Migration `0002_datei.sql`). Am Eintrag gibt es damit die Vorschau wie bei jedem Beleg – im Arbeitsvorrat, im Vergleich und in der Buchungsmaske; „Beleg öffnen“ zeigt das PDF.
- **Ohne Buchung wird gelöscht:** Wird der Eintrag verworfen (beim nächsten Abgleich) oder der Import zurückgezogen (sofort), löscht kumero das Original. Es bleiben nur Prüfsummen und Zeitpunkte als Nachweis.
- **Mit der Buchung geht das PDF an das DMS und wird in kumero gelöscht:** Nach dem Festschreiben übergibt kumero das Original beim Abgleich des Arbeitsvorrats an Paperless (Modul `paperless` 0.3.0), reicht den Beleg mit Link und Prüfsummen der Buchung nach (F27) und löscht seine Kopie. Verarbeitet Paperless noch oder ist es gesperrt, bleibt die Übergabe ausstehend und wird beim nächsten Abgleich nachgeholt; gesendet wird nur einmal. Führt Paperless die Datei schon, wird nichts gesendet.
- **Ein vorläufiger Beleglink gelangt nie in eine Buchung:** Maske und Stapel lassen ihn weg (Kennzeichen „Zwischenablage“), der Kern lehnt ihn ab (`422 beleg.vorlaeufig`).
- Modul-API: `Kumero\Api\V1\Belegablage`, `Dienste::belegablage()`, `Dienste::belegNachreichen()`; Belegarchive dürfen sich als `vorlaeufig` melden. „Datei einlesen“ nennt den Stand der Zwischenablage.

### Geändert
- „Im Archiv öffnen“ ist bei einem Beleg aus der Zwischenablage gesperrt und nennt den Grund.

### Nach dem Einspielen
- `bin/kumero modul:aktivieren import <mandant>` (neue Migration). Die Vorschau braucht `pdftocairo` (Paket poppler-utils, wie der Kontenrahmen-Import).

### Bekannt
- **Die Übergabe ist nicht an einem echten Paperless gelaufen**, nur am Nachbau. Zu bestätigen: dass Paperless 3.4.2 das Hochladen so annimmt und kumero den neuen Beleg danach findet. Gelingt das nicht, bleibt das Original in der Zwischenablage liegen – es geht nichts verloren.
- Eine XRechnung als reines XML wird nicht zwischengelagert und nicht übergeben. Im DMS setzt kumero nur Titel und Belegdatum.
- Der Beleg steht an der Buchung als „nachgereicht“, nicht im versiegelten Kopf – Paperless kennt den Link erst nach dem Festschreiben.
- Geprüft nach F103: PHP-Tests Import (174, darunter Zwischenablage, Sperre im Kern, Löschen nach Verwerfen und Zurückziehen), Paperless (163), Arbeitsvorrat (154), Buchen (229), Anwendung (81), Oberfläche (91); SQL-Tests (203, weil der Kern berührt ist); Browserläufe Paperless (38, darunter Vorschau aus der Zwischenablage, Buchen, Übergabe in zwei Abgleichen, nachgereichter Beleg, gelöschte Datei), Datei einlesen (48), Buchen (58), Arbeitsvorrat (41). Nicht gelaufen: die übrigen PHP-Tests.

## [0.7.17] – 2026-11-18

### Neu
- **Mehrere Steuersätze werden je Satz gebucht** (F109; Module `import` 0.2.1, `arbeitsvorrat` 0.5.3, `buchen` 0.3.3): Enthält eine E-Rechnung z. B. 19 % und 7 %, geht die Aufteilung mit an den Eintrag. Mit vorbelegtem Steuerkonto belegt die **Buchungsmaske** die Splitbuchung je Satz vor – Sachzeile und Steuerzeile je Satz, Geldkonto mit dem Rechnungsbetrag –, und die **Übergabe in den Stapel** legt die Entwürfe ebenso an. Das Steuerkonto je Satz findet kumero über die Steuersätze der Konten. Bisher hieß es „von Hand zu splitten“.
- **Prüftabelle der E-Rechnungen mit mehr Angaben:** Leistungsdatum, Fälligkeit, Positionen (Anzahl und die ersten Namen) und die **Steuer je Satz** stehen schon vor dem Einliefern da.

### Geändert
- Ein ZUGFeRD-PDF im Profil EN 16931 heißt „ZUGFeRD / Factur-X (EN 16931), CII im PDF“ statt nur „EN 16931“.
- Einzahl: „1 Rechnung in den Arbeitsvorrat einliefern“, „1 Eintrag“ in den Fußzeilen der Tabellen.

### Noch nicht gebaut – Entwurf zur Freigabe
- **Vorschau des PDF und Übergabe an das DMS** (Vorgabe vom 2026-11-18): beschrieben in IMPORT.md Abschnitt 16 mit den Entscheidungen D1–D8 (P25). Der Schritt braucht eine neue Schnittstelle der Modul-API und lädt zum ersten Mal Dokumente nach Paperless – deshalb erst die Freigabe.

### Bekannt
- Aufgeteilt wird nur mit vorbelegtem Steuerkonto (wie bisher: ohne Steuerkonto brutto). Ist zu einem Satz kein Steuerkonto eindeutig, bleibt das Feld in der Maske leer; im Stapel bleibt der Eintrag liegen und nennt den Satz.
- Geprüft nach F103: PHP-Tests Import (154), Arbeitsvorrat (154), Oberfläche (91); Browserläufe Datei einlesen (48, darunter Split je Satz in der Maske und im Stapel), Buchen (58), Arbeitsvorrat (41), Paperless (26). Nur mit selbst nachgebauten Musterdateien. Nicht gelaufen: die übrigen PHP-Tests und die SQL-Tests.

## [0.7.16] – 2026-11-18

### Neu
- **E-Rechnungen einlesen** (F108; Modul `import` 0.2.0, IMPORT.md Abschnitt 15): „Datei einlesen“ nimmt jetzt auch E-Rechnungen – **XRechnung** als XML (Syntax CII oder UBL, auch UBL-Gutschriften) und **ZUGFeRD ab 2.0 / Factur-X** als PDF mit eingebettetem XML. Es ist derselbe Importer: kumero erkennt an der Datei, ob sie CSV oder E-Rechnung ist. Mehrere E-Rechnungen auf einmal (bis 50, je 5 MB).
  - Je Rechnung ein Eintrag im Arbeitsvorrat: Art (Rechnung, Gutschrift, Korrektur), Gegenpartei mit USt-IdNr., Rechnungsnummer, Datum, Brutto, dazu **Netto und Steuer**, wenn sie den Rechnungsbetrag ergeben – damit belegt die Buchungsmaske die Splitbuchung vor (F101). Neben der Maske stehen Format, beide Parteien, Leistungsdatum, Fälligkeit, Steuer je Satz, Zahlbetrag, IBAN und die ersten Positionen.
  - **Eingangs- oder Ausgangsrechnungen** wählt der Nutzer beim Einlesen (die Datei sagt es nicht); Gutschriften drehen die Richtung.
  - Vor dem Einliefern zeigt eine Tabelle je Datei, was daraus würde: neu, schon im Arbeitsvorrat, unvollständig (wird markiert eingeliefert) oder nicht lesbar – mit Grund.
  - Dieselbe Rechnung als XML und im PDF ergibt einen Eintrag. Jede Datei steht im Verlauf der Importe und lässt sich zurückziehen.
  - Endpunkte `POST /import/rechnung/vorschau` und `/import/rechnung/einliefern`. Synthetische Muster: `werkzeuge/e-rechnung-muster.php`.

### Geändert
- **Menü: „Datei einlesen“ steht als Unterpunkt unter dem Arbeitsvorrat** (eingerückt darunter), nicht mehr als eigener Punkt neben der Buchungsliste; die Seite heißt „Datei in den Arbeitsvorrat einlesen“.
- Die Stufe des Importers für Quicken / Finanzmanager (Splitbuchungen, Umbuchungen, QIF) hat in IMPORT.md keine feste Nummer mehr; 0.2.0 sind die E-Rechnungen.

### Bekannt
- kumero **prüft die E-Rechnung nicht** gegen EN 16931 oder die XRechnung-Regeln (kein Schema, kein Schematron) und rechnet nicht nach. ZUGFeRD 1.0 und verschlüsselte PDFs werden nicht gelesen. Bei mehreren Steuersätzen steht die Aufteilung nur bei den Angaben.
- Die Datei wird nicht gespeichert und es entsteht kein Belegverweis; das Original der E-Rechnung gehört ins Belegarchiv.
- Geprüft nach F103: PHP-Tests Import (144, darunter 25 zum Lesen von CII, UBL und PDF und der Ablauf über REST) und Oberfläche; Browserlauf Datei einlesen (41, darunter Menü, Erkennen der Dateiart, vier Muster-Rechnungen, abgelehntes PDF, Ausgangsrechnung, Wiedereinlesen). **Nur mit selbst nachgebauten Musterdateien – nicht mit einer echten XRechnung oder ZUGFeRD-Datei.** Nicht gelaufen: die übrigen PHP-, SQL- und Browsertests.

## [0.7.15] – 2026-11-18

### Neu
- **Blättern im Arbeitsvorrat** (F107; Modul `arbeitsvorrat` 0.5.2, Baustein `kern/ui/blaettern.js`): Unter der Liste stehen an festem Platz „«“, „‹ Vorher“, **Seite _n_ von _m_** (eintippbar), „Weiter ›“, „»“ und die Seitengröße (50/100/200/500, Vorgabe 100), daneben „1–100 von 1.234“. Bild↑/Bild↓ blättern. Suche, Spaltenfilter, Zähler und Summe gelten für **alle** Einträge, nicht nur für die Seite; „Alle markieren“ markiert alle gefilterten Einträge über alle Seiten. Die Liste lädt bis zu 5.000 Einträge (bisher 500); erst darüber erscheint der Hinweis.
- **Verwerfen gilt für die Auswahl:** Sind Einträge markiert, werden **alle markierten** mit einem gemeinsamen Grund verworfen (Knopf „Verwerfen (n)“, Dialog „n Einträge verwerfen“) – ohne Haken wie bisher die gewählte Zeile. Neuer Endpunkt `POST /arbeitsvorrat/eintraege/verwerfen`: ganz oder gar nicht, jeder Eintrag mit eigenem Protokollsatz. Bisher wurde trotz Markierung nur die gewählte Zeile verworfen.
- **Keine Vorschau – mit konkretem Grund:** Statt „Keine Vorschau verfügbar.“ steht da, woran es liegt, z. B. „Paperless ist gesperrt: bitte unter ‚Paperless – mein Zugang‘ mit der Passphrase entsperren“, „Paperless ist nicht erreichbar“, „Paperless kennt diesen Beleg nicht oder dein Konto darf ihn nicht sehen“, „Paperless hat das Token abgelehnt“ – jeweils mit der Fehlernummer. Gilt für die kleine Vorschau am Zeiger, das Vorschaufenster und das Bild im Vergleich.

### Geändert
- **„Konten vorbelegen“ zeigt, was schon da ist:** Was an allen markierten Einträgen gleich ist (Konto, Gegenkonto, Steuerkonto, Buchungsart), steht bereits im Feld, daneben die Bezeichnung des Kontos; Unterschiedliches ist als „verschieden“ gekennzeichnet. Gespeichert wird nur, was geändert wurde.
- Sammelaktionen des Arbeitsvorrats (Vorbelegen, Leeren, Verwerfen) nehmen bis zu 5.000 Einträge an (bisher 500).
- **Datei einlesen** (Modul `import` 0.1.1): Der Vorschlag der Spaltenzuordnung erkennt „Zahlungsgrund“ / „Zahlungszweck“ als Betreff und „Buchungsart“ / „Umsatzart“ als Art. Dieselbe Datei wird nur einmal gelesen – ein doppelt gemeldeter Dateiwechsel konnte Eingaben (z. B. das Geldkonto) wieder leeren.
- IMPORT.md: Die zweite Stufe des Importers (Splitbuchungen, Umbuchungen, QIF) heißt jetzt 0.2.0, weil 0.1.1 für diese Korrektur vergeben ist.

### Bekannt
- Ein Vorschaubild, das die Seite schon gezeigt hat, hält der Browser bis zum Neuladen im Speicher; es bleibt in demselben Fenster also auch nach dem Sperren von Paperless sichtbar. Vom Server kommt nach dem Sperren nichts mehr.
- „Wieder öffnen“ gilt weiter nur für die gewählte Zeile. Blättern gibt es bisher nur im Arbeitsvorrat, noch nicht in Buchungsliste und Kontenliste.
- Geprüft nach F103: PHP-Tests Arbeitsvorrat (154), Import (101) und Oberfläche (91); Browserläufe Datei einlesen (29, darunter Blättern, Vorbelegen und Verwerfen der Auswahl), Arbeitsvorrat (41) und Paperless (26, darunter der Grund bei gesperrtem Paperless). Nicht gelaufen: die übrigen PHP-Tests, die SQL-Tests, die Browserläufe Buchen und Kontenrahmen; nicht gemessen: das Verwerfen von mehreren hundert Einträgen auf einmal und die Liste mit 5.000 Einträgen.

## [0.7.14] – 2026-11-18

### Geändert – nichts verschiebt sich mehr (F106)
- **Meldungen stehen in der Statusleiste** oben, an festem Platz neben dem Mandanten. Der Inhalt der Seite rückt nicht mehr nach unten, wenn eine Meldung erscheint. Lange Meldungen stehen in einer Zeile; ein Klick klappt den ganzen Text über der Seite auf, beim Überfahren steht er als Hilfe da.
- **Keine dynamische Oberfläche in den Listen:** Knöpfe werden nie ein- und ausgeblendet, sondern gesperrt. **Ein gesperrter Knopf nennt beim Überfahren den Grund** („Erst eine Zeile wählen.“, „Die gewählte Zeile hat keine Marke ‚Doppelt?‘ …“). Neuer Baustein `sperren()` im Kern.
- **Arbeitsvorrat** (0.5.1): Werkzeugleiste in zwei festen Zeilen – oben die Aktionen für die gewählte Zeile, darunter die für die Auswahl; Knöpfe mit wechselnder Beschriftung haben eine feste Breite; „Trennen“ und „Gruppen von selbst zusammenführen“ sind immer da (gesperrt mit Grund); der Hinweis auf ausgefallene Quellen hat einen festen Platz in der Filterzeile.
- **Fester Kopf:** In Arbeitsvorrat, Buchungsliste (`buchen` 0.3.2) und Kontenliste bleiben Seitentitel, Werkzeugleiste, Filter und Tabellenkopf stehen; nur die Zeilen rollen.

### Noch offen
- Die Regel gilt für alle Seiten, umgesetzt ist sie in den drei Listen. Noch dynamisch: die Schritte von „Datei einlesen“ (erscheinen nach der Dateiwahl), in der Buchungsmaske die Splittabelle und die Knöpfe „Vorschau“ und „Soll ⇄ Haben“, in der Kontenliste das Formular „Neu“, die Seite „Paperless – mein Zugang“.
- Geprüft nach F103: Browserläufe der betroffenen Seiten – Arbeitsvorrat (41), Datei einlesen und Vergleich (20, darunter „die Werkzeugleiste steht nach einer Meldung an derselben Stelle“), Buchen (58), Paperless und Kontenrahmen-Import. Ob der Browser die Hilfe an gesperrten Knöpfen anzeigt, lässt sich im Testlauf nicht sehen – bitte am Bildschirm prüfen.

## [0.7.13] – 2026-11-18

### Neu
- **Vergleichen und zusammenführen im Arbeitsvorrat** (X3, F105; Modul `arbeitsvorrat` 0.5.0, Migration `0005_zusammenfuehren.sql`): Knopf **„Vergleichen“** an einem Eintrag mit Markierung „Doppelt?“ öffnet beide Einträge **links und rechts** – je Feld beide Werte, Abweichungen gekennzeichnet, darunter die Angaben der Quellen und, wenn ein Belegarchiv zuständig ist, das **Bild des Belegs** (Klick vergrößert).
  - **Je Feld wählbar**, welcher Wert gilt. Vorgeschlagen ist: Belegangaben (Datum, Belegnummer, Gegenpartei, Netto, Steuer …) vom Beleg, Geldfluss (Tag, Zahlweise) vom Umsatz. Es bleibt der Eintrag mit dem Beleg.
  - **Zusammenführen:** Aus zwei Einträgen wird einer; die Beiträge beider Quellen und die Vorbelegung (z. B. das Geldkonto) bleiben daran. Der aufgegangene Eintrag wird nicht gelöscht. Was abweicht, steht als Hinweis am Eintrag.
  - **„Kein Duplikat“:** Das Paar bleibt getrennt und wird nicht wieder gemeinsam markiert.
  - **„Trennen“** macht das Zusammenführen rückgängig, solange der Eintrag offen ist.
- **Zusammenführen von selbst – gebaut und getestet, aber ausgeschaltet.** Schalter unter „Regeln für den Arbeitsvorrat“ (Vorgabe: aus). Eingeschaltet erscheint ein Knopf, der nur eindeutige Gruppen zusammenführt: genau zwei Einträge, genau einer mit Beleg; „Kein Duplikat“ wird geachtet, alles andere bleibt und wird genannt.

### Geändert
- `install/README.md` nennt das Aktivieren des Moduls `import`.

### Noch offen
- Im Vergleich wird bei Gruppen mit mehr als zwei Einträgen je ein Paar gezeigt; ein Zusammenführen vieler Gruppen in einem Schritt gibt es nur über „von selbst“.
- Geprüft nach F103: Unittests und Ablauf über REST (100 Prüfungen im Test des Importers, darunter Vorschlag, „kein Duplikat“, Zusammenführen, Trennen, von selbst), Tests des Arbeitsvorrats (141), ein Browserlauf der Seiten „Datei einlesen“ und Vergleich (17). Das Belegbild im Vergleich ist nicht gegen ein Paperless gelaufen.

## [0.7.12] – 2026-11-18

### Neu
- **Modul `import` 0.1.0: CSV-Dateien als Quelle des Arbeitsvorrats** (IMPORT.md, F104). Seite **Buchen → „Datei einlesen“**: Datei wählen → Aufbau erkennen (Zeichensatz, Trennzeichen, Kopfzeile nach Vorspann, Datum, Dezimalzeichen) → Spalten zuordnen (Vorschlag aus den Spaltennamen) → **Einnahme oder Ausgabe** (Vorzeichen, Vorzeichen umgekehrt, zwei Spalten, Kennzeichen, fest) → **Geldkonto** → Vorschau mit Summen und Saldenkontrolle → einliefern. Jede Zeile wird ein Eintrag.
  - **Soll und Haben aus dem Geldkonto:** bei einer Ausgabe steht es im Haben, bei einer Einnahme im Soll – als Vorauswahl am Eintrag. Buchungsdatum ist der Tag des Geldflusses (Valuta, sonst der Buchungstag).
  - **Importprofile:** Aufbau, Spalten, Richtung und Geldkonto merken; Vorgabe des Mandanten oder eigenes Profil; an der Kopfzeile wiedererkannt.
  - **Nichts doppelt:** Schlüssel aus dem Inhalt der Zeile, überlappende Auszüge und dieselbe Datei ergeben keine zweiten Einträge.
  - **Fehlerhafte Zeilen werden markiert eingeliefert** (C10): mit dem, was lesbar ist, einem Hinweis mit Zeilennummer und allen Spalten als Angaben.
  - **Die Daten der Datei bleiben am Eintrag** (C9): Was keinem Feld zugeordnet ist, steht beim Buchen neben der Maske. Die Datei selbst wird nicht gespeichert; je Import bleiben Dateiname, Hash und Zahlen.
  - Import **zurückziehen**, solange keiner seiner Einträge bearbeitet ist.
- **Arbeitsvorrat 0.4.0: Spalte „Doppelt?“** (C8): Einträge mit gleichem Betrag und derselben Gegenpartei oder Belegnummer – etwa Abbuchung und Rechnung – sind als Gruppe markiert, sortierbar und filterbar. Zusammengeführt wird noch nicht.
- Vertrag für Quellen: Eine Quelle darf das **Geldkonto** nennen (C5).

### Noch offen
- Zusammenführen der markierten Einträge (einzeln und im Stapel), Splitbuchungen, Umbuchungen und QIF (Importer 0.1.1), Profile als Datei weitergeben, eigene Seite für Importprofile.
- Geprüft nach F103: Unittests des Importers und der Markierung (58 Prüfungen, darunter der Ablauf über REST), Tests des Arbeitsvorrats (141), Oberfläche und ein Browserlauf nur der neuen Seite (12). Die übrigen Läufe sind nicht wiederholt. Nur synthetische Dateien; an einem echten Bankexport ist nichts gelaufen.

## [0.7.11] – 2026-11-18

### Neu
- **Steuersatz in der Kontenliste** (Stammdaten → Konten; Modul `buchen` 0.3.1): Spalte „Steuersatz“ je Konto – anklicken, Satz eintragen, Enter; ein geleertes Feld löscht ihn. Vorschläge aus der Bezeichnung („… 19 %“) sind mit „?“ gekennzeichnet und lassen sich über **„Steuersätze übernehmen (n Vorschläge)“** in einem Schritt bestätigen.
- **Konten ändern in der Kontenliste:** Knopf „Ändern“ je Zeile (auch Doppelklick oder Enter) – Bezeichnung und „aktiv“ immer, die Kontoart nur, solange das Konto nicht bebucht ist (F74). Die Nummer bleibt. Genutzt wird der vorhandene Endpunkt `PATCH /konten/{nr}`.
- **Erweiterungspunkt für Seiten des Kerns** (F102): Aktive Module nennen im Manifest unter `oberflaeche_erweiterungen` eine Datei je Punkt (bisher nur `konten`); der Kern liefert die Pfade mit dem Menü (`erweiterungen`) und lädt sie in der Kontenliste. **Der Kern kennt weiter nur Konten** – der Steuersatz liegt im Schema des Moduls `buchen`.
- **Kontenrahmen anwenden** (Modul `kontenrahmen` 0.1.4): Nach dem Anwenden weist die Seite darauf hin, dass in der Kontenliste Vorschläge zu den neuen Konten bereitstehen.
- Tests: SQL 203, PHP 796, Browser 10 + 58 + 41 + 25 Prüfungen (PHP 8.3 / PostgreSQL 16). Ab jetzt gilt F103: geprüft wird beim Bauen nur der bearbeitete Teil.

## [0.7.10] – 2026-11-18

### Neu
- **Steuerkonten mit Steuersatz** (F101; Modul `buchen` 0.3.0, Migration `0002_steuerkonto.sql`): Unter „Buchen einrichten → Steuerkonten“ bekommt ein Konto seinen Steuersatz (z. B. Vorsteuer 19 %). kumero schlägt den Satz aus der Kontobezeichnung vor; er gilt erst nach dem Speichern. Endpunkte `GET/PUT /buchen/steuerkonten`.
- **Splitbuchung rechnet die Steuer aus dem Brutto**: Steht ein Steuerkonto in der Tabelle, setzt die Maske Steuer = Brutto − Brutto ÷ (1 + Satz) und den Rest in die erste Sachzeile. **Die Summe bleibt immer der Rechnungsbetrag:** Weitere Aufwandszeilen gleicht die Restzeile aus. Gerechnete Beträge sind gekennzeichnet (kursiv, gestrichelt); ein eigener Betrag im Feld schaltet das Rechnen für die Zeile ab.
- **Steuerrichtung aus Soll und Haben:** Die Steuerzeile steht auf der Seite des Erfolgskontos – Vorsteuer im Soll bei Aufwand, Umsatzsteuer im Haben bei Ertrag.
- **Haken „Beträge netto eingeben“**: Dann sind die Sachzeilen netto, Steuer und Zahlbetrag werden dazugerechnet. Vorgabe ist brutto.
- **Arbeitsvorrat 0.3.2:** Mit vorbelegtem Steuerkonto entsteht in der Maske und bei „In den Stapel“ eine Splitbuchung – Netto und Steuer vom Beleg (DMS), wenn sie den Bruttobetrag ergeben, sonst aus dem Steuersatz gerechnet. Netto und Steuer vom Beleg sind keine Voraussetzung mehr.

### Bestätigt
- Paperless-ngx 3.4.2 nennt am Dokument die **SHA-256** des Originals („Original SHA256 checksum“). Damit ist die Ursache von „Prüfsumme stimmt nicht“ aus 0.7.8 bestätigt; der Vergleich seit 0.7.8 ist richtig.

### Noch offen
- Mehrere Steuersätze auf einem Beleg rechnet die Maske nicht von selbst auseinander (zwei Steuerzeilen: Beträge von Hand, die Summe muss aufgehen).
- Eine einfache Buchung (ohne „Aufteilen“) kennt kein Steuerkonto; der Schalter des Mandanten „brutto oder aufteilen“ (X2b) fehlt weiter.
- Tests: SQL 203, PHP 795, Browser 10 + 53 + 41 + 25 Prüfungen (PHP 8.3 / PostgreSQL 16, Paperless als Nachbau).

## [0.7.9] – 2026-11-18

### Neu
- **Buchungsliste mit sortierbaren Spalten und Filter je Spalte** (F91; Modul `buchen` 0.2.7): derselbe Baustein wie im Arbeitsvorrat – Klick auf den Spaltentitel sortiert, im Filter am Spaltenkopf lässt sich je Wert finden (＋) oder ausschließen (−). Spalten: Nr., Belegdatum, Buchungstext, Beleg-Nr., Konto, Soll, Gegenkonto, Haben. Der Haken „alle markieren“ gilt für die gefilterten Entwürfe; Anzahl und Kontrollsumme im Fuß ebenso.
- Tests: SQL 203, PHP 786, Browser 10 + 49 + 41 + 25 Prüfungen (PHP 8.3 / PostgreSQL 16).

### Noch offen
- Spaltenfilter in den übrigen Tabellen: Konten, Kontoblatt, Geschäftsvorfälle, Regeln, Kontenrahmen-Import.

## [0.7.8] – 2026-11-18

### Geändert
- **Arbeitsvorrat lädt sofort; der Abgleich mit den Quellen läuft im Hintergrund** (Modul `arbeitsvorrat` 0.3.1; Anlass: fast eine Minute Ladezeit bei 53 Einträgen am echten Paperless). Die Liste kommt aus der Datenbank (`GET /arbeitsvorrat/eintraege?abgleich=nein`); die Quellen werden über den neuen Endpunkt `POST /arbeitsvorrat/abgleich` gefragt – beim Öffnen von selbst, **höchstens alle fünf Minuten** je Mandant und Browserfenster, und jederzeit über **„Jetzt abgleichen“**. Danach frischt sich die Liste still auf. Neben dem Knopf steht „Abgeglichen um …“; beim Überfahren die Dauer je Quelle (nur Modulname und Sekunden). Der Sprung zum nächsten Eintrag in der Buchungsmaske fragt die Quellen nicht mehr.
- **Aktionen ohne Haken:** „Konten vorbelegen“, „Konten leeren“ und „In den Stapel“ gelten für die markierten Einträge – ist nichts markiert, für die gewählte Zeile (wie in der Buchungsliste).
- **„Konten leeren“** löscht Konto, Gegenkonto, Steuerkonto und Buchungsart der Auswahl wieder.
- **Buchungsliste** (Modul `buchen` 0.2.6): Haken im Tabellenkopf markiert alle angezeigten Entwürfe bzw. hebt die Markierung auf.

### Behoben
- **Modul `paperless` 0.2.1: „Die Prüfsumme des Originals stimmt nicht mit der Angabe von Paperless überein“** trotz unverändertem Beleg. kumero verglich die Angabe von Paperless immer mit der MD5; nennt Paperless die SHA-256 des Originals, wird jetzt damit verglichen. **Vermutete Ursache, am echten Paperless noch zu bestätigen.**

### Noch offen
- Die Ursache der langen Dauer des Abgleichs selbst ist nicht gefunden (mit dem Nachbau 0,06 s für 55 Belege); die Dauer je Quelle wird jetzt angezeigt.
- „Steuerbuchungen optimieren“ in Buchungsmaske und Arbeitsvorrat: noch zu beschreiben.
- Tests: SQL 203, PHP 786, Browser 10 + 49 + 39 + 25 Prüfungen; gelaufen auf PHP 8.3 / PostgreSQL 16, Paperless nur als Nachbau.

## [0.7.7] – 2026-11-18

### Neu
- **Zuordnung Paperless-Feld → Buchungssatz** (F95, PAPERLESS.md Abschnitt 12/13; Modul `paperless` 0.2.0, Migration `0003_zuordnung.sql`): Je Feld der Buchung (Betreff, Art, Gegenpartei, Kennung, Belegdatum, Betrag brutto, Netto, Steuerbetrag, Richtung, Belegnummer, Zahlweise, Zahltag) eine Reihenfolge von Quellen – benutzerdefinierte Felder, feste Felder (Titel, Korrespondent, Dokumenttyp, Erstelldatum, Archivnummer) und Tags (für Richtung und Zahlweise). **Vorgabe des Mandanten** (Recht `paperless.vorgabe.pflegen`), die **eigene Zuordnung** des Nutzers überschreibt je Feld. Vorschlag aus den Feldnamen, gespeichert wird erst nach Bestätigung. Werte werden streng gelesen: Was nicht passt (fremde Währung, kein Datum), wird nicht geraten, sondern steht als Hinweis am Eintrag. Einstellung unter „Paperless – mein Zugang → Felder zuordnen“. Endpunkte `GET/PUT /paperless/verbindungen/{id}/zuordnung`.
- **Splitbuchung mit Vorsteuer/Umsatzsteuer** (X2; Modul `arbeitsvorrat` 0.3.0, Migration `0004_steuer_pruefsumme.sql`; Modul `buchen` 0.2.5): Einträge tragen **Netto** und **Steuerbetrag**; in der Stapelvorbelegung gibt es das **Steuerkonto**. Ist es gewählt und sind Netto und Steuer bekannt, belegt die Buchungsmaske als Splitbuchung vor (Aufwand netto, Steuer, Geldkonto brutto) und „In den Stapel“ erzeugt dreizeilige Entwürfe. **Ohne Steuerkonto bleibt es brutto.**
- **Buchungsmaske übernimmt die Vorauswahl** aus dem Arbeitsvorrat (Konto, Gegenkonto, Buchungsart) – bisher wirkte sie nur bei der Übergabe in den Stapel.
- **Deduplizieren über die Prüfsumme** (X3, Stufe 1 und 2): Liefern Quellen denselben Beleg mit gleichem Link **oder gleicher Prüfsumme** (SHA-256, MD5), wird es ein Eintrag.

### Geändert
- **Belegvorschau hängt rechts neben dem Mauszeiger** (links, wenn rechts kein Platz ist; immer ganz im Fenster) und folgt ihm. Ausgelöst wird sie an einem **Augen-Symbol** am Beleg (eigene Spalte im Arbeitsvorrat, in der Buchungsliste) statt beim Überfahren der ganzen Zeile; Klick öffnet die große Vorschau, Taste V bleibt.
- **Buchungserfassung:** Überfahren des Knopfs „Vorschau“ zeigt dieselbe kleine Vorschau – auch in der Ansicht einer festgeschriebenen Buchung.
- Hinweis „unbar: Tag des Geldflusses eintragen“ bricht um, statt aus dem Fenster zu laufen.

### Noch offen
- X2b Voreinstellung des Mandanten „brutto oder aufteilen“ als eigener Schalter (derzeit entscheidet das Steuerkonto), X3 Vorschläge „vermutlich doppelt“ mit Stapelbestätigung, X4 Hinweis über Belegnummer und Betrag, Paperless-Zugang im Nutzerprofil (F93), Spaltenfilter in den übrigen Tabellen (F91), Arbeitsvorrat als Merkmal jedes Moduls (F90), Modul `nopaper`.
- Tests: SQL 203, PHP 777, Browser 10 + 49 + 33 + 24 Prüfungen; gelaufen auf PHP 8.3 / PostgreSQL 16, Paperless nur als Nachbau – die Zuordnung ist am echten Paperless noch nicht gelaufen.

## [0.7.6] – 2026-11-18

### Neu
- **Belegvorschau als abstraktes Merkmal** (F97): Schnittstelle `Kumero\Api\V1\Belegarchiv` für DMS-Module und Endpunkte des Kerns `/belege/archive`, `/belege/vorschau`, `/belege/original`, `/belege/verweis`. Baustein `public/kern/ui/beleg.js`: große Vorschau im Fenster, kleine Vorschau **beim Überfahren mit der Maus** am Rand (verschiebt nichts) und per Tastatur. Sie funktioniert überall, wo ein Belegverweis aus einem angebundenen DMS vorliegt – Arbeitsvorrat, Buchungsliste, Buchungsmaske, festgeschriebene Buchung. Paperless ist das erste Archiv.
- **Tabellen: sortierbare Spalten und Filter je Spalte** (F91) als Baustein `public/kern/ui/tabellenfilter.js`: Klick auf den Titel sortiert; im Filter am Spaltenkopf lässt sich je vorkommendem Wert **finden (＋)** oder **ausschließen (−)** wählen. Eingesetzt in der Liste des Arbeitsvorrats.
- **Modul `arbeitsvorrat` 0.2.0** (ARBEITSVORRAT.md Abschnitt 13, F96):
  - **Konto und Gegenkonto in der Liste** je Eintrag wählbar (X7) – eine Vorbelegung, keine Buchung (Migration `0003_vorauswahl.sql`).
  - **Massenauswahl** („Alle markieren“ für die gefilterten Einträge, Leertaste je Zeile) und **Stapelvorbelegung**: Konto, Gegenkonto, Buchungsart für alle markierten auf einmal (X1).
  - **In den Stapel** (X1a): Markierte, vollständig vorbelegte Einträge werden **freigabebereite Entwürfe** im Erfassungsstapel; festgeschrieben wird dort mit der Stapelfreigabe. Unvollständige bleiben im Vorrat und werden genannt.
  - **Schon gebucht?** (X4): Gibt es zu einem offenen Eintrag eine festgeschriebene, nicht stornierte Buchung mit demselben Beleg – gleicher Link oder gleiche Prüfsumme –, gilt er als gebucht und wird mit ihr verknüpft.
  - **Vorschau des Belegs beim Überfahren** (X6); Taste V auf der gewählten Zeile.
- Modul-API: `Dienste::buchungenZuBelegen()`.

### Geändert
- **Modul `buchen` 0.2.4** fragt für Vorschau und Belegprüfung nur noch den Baustein des Kerns und kennt Paperless nicht mehr beim Namen; Vorschau beim Überfahren auch in der Buchungsliste.
- **Modul `paperless` 0.1.2** stellt sich dem Kern als Belegarchiv bereit.

### Noch offen (aus dem Auftrag „alles von heute“ nicht gebaut)
- Zuordnung Paperless-Feld → Buchungssatz (F95), Splitbuchung mit Vorsteuer/Umsatzsteuer (X2), Deduplizieren über Prüfsumme und Vorschläge (X3), Paperless-Zugang im Nutzerprofil (F93), Spaltenfilter in den übrigen Tabellen (F91), Arbeitsvorrat als Merkmal jedes Moduls (F90), Modul `nopaper`.
- Tests: SQL 203, PHP 750, Browser 10 + 49 + 33 + 16 Prüfungen; gelaufen auf PHP 8.3 / PostgreSQL 16, Paperless nur als Nachbau.

## [0.7.5] – 2026-11-18

### Behoben
- **Modul `paperless` 0.1.1: Verbinden scheiterte mit „Paperless hat unerwartet geantwortet (HTTP 406)“.** kumero verlangte fest die API-Version 5; Paperless-ngx 3.x bedient nur 9 und 10. Bestätigt am Paperless 3.4.2 des Referenzbetriebs (meldet API-Version 10).

### Neu
- **API-Versionen von Paperless ab 3.x** (F94): gepflegte Tabelle `module/paperless/versionen.json` (API 9 und 10). kumero stellt fest, was Paperless meldet, spricht die höchste gemeinsame Version, merkt Paperless-Version und API-Version je Verbindung (Migration `0002_versionen.sql`) und wechselt nach einem Update von Paperless von selbst. Der Verbindungstest zeigt beide Versionen und sagt, wenn Paperless neuer ist als geprüft.
- Klare Absagen statt „unerwartet geantwortet“: Paperless vor 3.0, keine bekannte API-Version mehr, kein Paperless unter der Adresse.

### Entschieden
- **F93**: Der Zugang zu Paperless gehört ins Nutzerprofil (noch nicht umgezogen). **F94**: Das Modul kennt und spricht alle API-Versionen ab Paperless 3.x; jede neue Paperless-Version ist eine Pflegeaufgabe.

### Noch offen
- Am echten Paperless ist bisher nur der Aufruf mit `curl` bestätigt; kumero selbst lief nur gegen den Nachbau.
- Tests: **nur die API-Anbindung** – PHP `PaperlessTest` 135 Prüfungen, Browser `paperless.mjs` 14. Die übrigen Tests liefen für diese Version nicht erneut; geändert ist nur das Modul `paperless`.

## [0.7.4] – 2026-11-17

### Neu
- **Modul `paperless` 0.1.0** (PAPERLESS.md, F92): Paperless-ngx als Belegarchiv, mit dem **eigenen Konto des Nutzers**.
  - **Zugang** (Einstellungen → „Paperless – mein Zugang“): Adresse, Token, Tag für offene Belege. Das Token wird gegen Paperless geprüft und nur verschlüsselt gespeichert; je Sitzung entsperrt es eine **Passphrase**. Verbindungstest, Sperren, Vorgabe des Mandanten, mehrere Paperless je Nutzer.
  - **Tags auswählen**: Tags des eigenen Paperless-Kontos ansehen und die gewünschten im Mandanten anlegen.
  - **Arbeitsvorrat**: Belege mit dem Tag für offene Belege erscheinen als Einträge (Titel, Korrespondent, Datum, Dokumenttyp, Beleglink). Nach dem Buchen oder Verwerfen entfernt kumero das Tag in Paperless, bei Storno setzt es es wieder.
  - **Buchungsmaske**: Der Beleglink wird am Original geprüft – MD5 und SHA-256 stehen am Entwurf, der Verweis gilt als geprüft. Vorschau neben dem Feld.
  - **Buchungsliste** und festgeschriebene Buchung: Vorschau des Belegs.
  - Aktivieren je Mandant: `bin/kumero modul:aktivieren paperless MANDANT` (braucht `arbeitsvorrat`).
- **Modul `arbeitsvorrat` 0.1.1**: Quellen bekommen die Anfrage und die Auskunft über ihre Beiträge; der Abgleich liest erst den Stand der Buchungen, dann die Quellen; Einträge einer Lieferung behalten ihre Reihenfolge (Migration `0002_eingang.sql`).
- **Modul `buchen` 0.2.3**: Vorschau und Belegprüfung, wenn das Modul `paperless` aktiv ist; ohne das Modul unverändert.

### Behoben
- In der Karte „Aus dem Arbeitsvorrat“ stand unter den Angaben das Wort „null“.

### Noch offen
- **Nicht gegen ein echtes Paperless geprüft**, nur gegen einen nachgebauten Server; die Aufrufe folgen der Dokumentation von Paperless-ngx.
- Entsperren per Passkey (WebAuthn PRF), Livelog, Rückschreiben der Buchungsdaten nach Paperless (F49), Quelle nopaper.
- Festlegungen aus der Umsetzung in PAPERLESS.md Abschnitt 10 sind zu bestätigen.
- Tests: SQL 203, PHP 696, Browser 10 + 49 + 21 + 14 Prüfungen; gelaufen auf PHP 8.3 / PostgreSQL 16.

## [0.7.3] – 2026-11-17

### Neu
- **Modul `arbeitsvorrat` 0.1.0** (ARBEITSVORRAT.md, F89): der **Posteingang der Buchhaltung** – alles, was noch zu buchen ist, unabhängig von der Quelle.
  - **Einträge** mit eigener ID; Pflicht ist nur der Betreff. **Einträge von Hand** über „Neu“.
  - **Vertrag für Quellen**: einliefern und zurückziehen (`Kumero\Modul\Arbeitsvorrat\Dienst`), befragte Quellen über `arbeitsvorrat_abgleich`. Beiträge mit demselben Beleglink landen im selben Eintrag; der zuerst eingegangene Wert gilt, Abweichungen stehen als Hinweis.
  - **Liste** mit den Ansichten Offen / In Arbeit / Gebucht / Verworfen, Filter nach Quelle, Suche, Tastatur wie in der Buchungsliste, **Zähler im Menü**.
  - **Buchen aus dem Eintrag**: Die Buchungserfassung ist vorbefüllt (Datum, Betrag, Belegnummer, Text, Beleglink), daneben stehen die Angaben der Quellen. Nach Speichern oder Buchen springt die Maske zum nächsten offenen Eintrag; „Überspringen“ lässt einen Eintrag liegen.
  - **Zustände aus den Buchungen**: Entwurf gespeichert → in Arbeit, festgeschrieben → gebucht; Entwurf gelöscht oder Buchung storniert → wieder offen.
  - **Verwerfen** mit Pflichtgrund und **Wieder öffnen**; nichts wird gelöscht.
  - **Regeln**: Art → Geschäftsvorfall (gepflegt), Gegenpartei → Konto (beim Buchen gemerkt, einsehbar und löschbar).
  - Rechte `arbeitsvorrat.eintrag.sehen / .anlegen / .bearbeiten`, `arbeitsvorrat.regel.pflegen`; Endpunkte unter `/m/{mandant}/api/v1/arbeitsvorrat`.
  - Aktivieren je Mandant: `bin/kumero modul:aktivieren arbeitsvorrat MANDANT` (braucht `buchen`).
- **Modul-API des Kerns**: `Kumero\Api\V1\Dienste` – Stand von Buchungen und aktive Module, damit Module keine Tabellen des Kerns lesen (F44).
- **Rahmen**: Menüeinträge zeigen ihren Zähler (`zaehler` aus dem Manifest).
- **Modul `buchen` 0.2.2**: nimmt einen Eintrag des Arbeitsvorrats entgegen (`?vorrat=<id>`); bei unbarer Zahlung ohne bekannten Zahltag bleibt das Buchungsdatum leer, mit Hinweis.

### Entschieden
- **F89**: Der Arbeitsvorrat ist ein abstraktes Modul, unabhängig von der Quelle der Arbeit; Einträge gehören dem Mandanten; nopaper, Paperless und Online-Konten sind Quellen in eigenen Modulen. Das Modul `belegeingang` aus dem früheren Entwurf entfällt.
- Der Endpunkt für nopaper heißt künftig `/nopaper/sidecars/{paperless_id}` (BELEGEINGANG.md, NOPAPER-AUFTRAG.md angepasst).

### Noch offen
- Es gibt noch keine Quelle außer „von Hand“: nopaper und Paperless folgen als eigene Module.
- Festlegungen aus der Umsetzung in ARBEITSVORRAT.md Abschnitt 12 sind zu bestätigen.
- Tests: SQL 203, PHP 590, Browser 10 + 49 + 21 Prüfungen; gelaufen auf PHP 8.3 / PostgreSQL 16.

## [0.7.2] – 2026-11-17

### Neu
- **Belegverweis ist der ganze Link** (F88, ändert F10; Migration `0011_belegverweis_link.sql`): `link` statt Dokument-ID, z. B. `https://paperless.example.org/documents/5210/`. Die Datenbank erzwingt die Schreibweise (http/https, Host klein, ohne Zugangsdaten, Abfrage und Fragment) und nimmt den Link in kanonische Form und Hash auf. MD5 und SHA-256 bleiben; sie dürfen gemeinsam fehlen (ungeprüfter Verweis) – festgeschrieben wird das **nur im Testbetrieb**, im Produktivbetrieb lehnt die Datenbank ab.
- API: `belege: [{link, md5?, sha256?}]` bei Entwürfen und `POST /buchungen/{id}/belege`; Buchungen liefern zusätzlich `belege_nachgereicht`. Die bloße `paperless_id` wird abgewiesen.
- **Modul `buchen` 0.2.1**: Feld **„Beleg / Link“** in der Erfassung mit Prüfung und „öffnen“; Belege an der festgeschriebenen Buchung als Links mit Kennzeichen geprüft / ungeprüft / nachgereicht; Zeichen ↗ in der Buchungsliste. Eine Kopie beginnt ohne Beleg.

### Geändert
- Erfassung: **Buchungsdatum wieder neben dem Belegdatum**, der **Kalenderknopf sitzt im Datumsfeld** (Rückmeldung zu 0.7.1). Ein Tab-Halt je Feld bleibt.
- Ein Link in der Belegnummer wird mit Hinweis abgewiesen (er passte bis 36 Zeichen noch hinein).
- Wochentage erscheinen in der Sprache der Texte (deutsch), auch wenn das Datum einer anderen Schreibweise folgt.
- Ein doppelt nachgereichter Beleg gibt 409 mit klarer Meldung.

### Bestand
- Vorhandene Verweise mit Dokument-ID bleiben unverändert und behalten ihre kanonische Gestalt; kein Hash ändert sich. Links, die in 0.7.0/0.7.1 in der Belegnummer festgeschrieben wurden, bleiben dort stehen (festgeschrieben ist festgeschrieben).

### Noch offen
- Texte gibt es nur auf Deutsch; die Spracheinstellung bestimmt bisher nur die Schreibweise des Datums.
- Prüfsummen ermitteln, Vorschau und Tags aus Paperless: Modul `paperless`. Paperless-ID an Tags unverändert.
- Tests: SQL 203, PHP 482, Browser 10 + 49 Prüfungen; gelaufen auf PHP 8.3 / PostgreSQL 16.

## [0.7.1] – 2026-11-17

### Neu
- **Nutzermenü und „Profil und Einstellungen“** (PROFIL.md, F86): Menü am Namen oben rechts (Profil, Abmelden). Einstellungen liegen am Nutzer in der Verwaltungsdatenbank (`db/verwaltung/0002_profil.sql`): **Sprache und Schreibweise** (leer = wie der Browser), **Betragseingabe** dezimal oder kaufmännisch, **Schriftgröße**, **Kontrast**. REST: `PATCH /api/v1/ich`, `GET`/`PUT /api/v1/ich/einstellungen`. Das Protokoll nennt bei Einstellungen nur den Schlüssel, nie den Wert.
- **Modul `buchen` 0.2.0** (BUCHUNGSMASKE.md Abschnitt 8, F85):
  - **Kontenvorschau** beim Tippen von Nummer oder Bezeichnung (M9) – in Erfassung, Splitzeilen, Listenfilter, Kontoblatt und Geschäftsvorfällen.
  - **Betragseingabe kaufmännisch** auf Wunsch: 1000 wird 10,00; Rechnen im Feld in beiden Arten (M10).
  - **Datum** in der Schreibweise der Nutzersprache bzw. des Browsers, mit Kurzeingabe (`17`, `1711`) und Pfeiltasten (M11).
  - **Feste Spalten** im Formular: Bezeichnung, Hinweise und Saldo haben ihren Platz, nichts verschiebt sich (M12).
  - **Hilfen beim Überfahren** an Feldern und Knöpfen, mit Tastenkürzel (M13).
  - **Festgeschriebene Buchung** als feste Ansicht mit Schloss, Hash und Verweis auf Storno bzw. Original statt als gesperrtes Formular; Schloss-Zeichen in der Liste (M14).
  - **Ein Tab-Halt je Feld**: eigenes Datumsfeld, der Kalender ist der nächste Halt (M15).
- Baustein des Kerns `datumFeld()` und Datumshilfen in `bausteine.js`; Grundschrift der Oberfläche etwas kleiner, über die Schriftgröße im Profil einstellbar.

### Behoben
- Der Saldo des vorigen Kontos blieb stehen, wenn die Kontonummer geändert oder gelöscht wurde.
- Eine Belegnummer über 36 Zeichen wurde still abgeschnitten; jetzt Hinweis, gespeichert wird erst mit gültiger Länge.
- Geschäftsvorfälle: Felder mit und ohne Hinweiszeile stehen auf gleicher Höhe.

### Geändert
- `verwaltung.nutzer.sprache` darf leer sein; `nutzer:anlegen` setzt ohne `--sprache` keine Sprache mehr. **Bestehende Nutzer behalten `de`** und sehen deutsche Datumsangaben, bis sie im Profil „wie der Browser“ wählen.

### Entschieden
- **F85** Maskenergänzungen M9–M15, **F86** Profil erste Stufe, **F87** Entscheidungen zu Arbeitsvorrat, Paperless, nopaper und Livelog (noch nicht gebaut).

### Noch offen
- Zahlen bleiben in deutscher Schreibweise, auch wenn das Datum einer anderen Sprache folgt.
- Rest des Profils (automatische Abmeldung, Freigabe-Passkeys, Anrede, Sitzungen), Livelog, Belegverweis als Link, Module `paperless` und `belegeingang`, M16/M17: folgende 0.7.x.
- Tests: SQL 194, PHP 467, Browser 10 + 45 Prüfungen; gelaufen auf PHP 8.3 / PostgreSQL 16, nicht auf dem Zielstand PHP 8.5 / PostgreSQL 19.

## [0.7.0] – 2026-11-17

### Neu
- **Buchen über REST** (F78, F83): Endpunkte des Kerns für Buchungen – Entwürfe anlegen, ersetzen, löschen (mit `ETag` / `If-Match`), vorbereiten, zurücksetzen, Storno als Entwurf, Beleg nachreichen –, für die **Freigabe** (`POST /freigaben/challenge` mit dem anzuzeigenden Inhalt, `POST /freigaben` einzeln oder als Stapel) und für die Abfragen **Journal** (Filter, Cursor, `reihenfolge`), **Salden** und **Kontoblatt**. Regeln prüft weiterhin die Datenbank; die Anwendung prüft nur die Form der Eingabe und die WebAuthn-Signatur der Freigabe (F72).
- **Freigabe**: im Testbetrieb ohne Assertion (Testbuchung, F59); mit Assertion prüft die Anwendung Signatur, Origin, RP-ID und Nutzerverifikation gegen die Challenge aus der Datenbank (F68). Stornos brauchen `kern.buchung.stornieren`, alle anderen `kern.buchung.festschreiben`.
- **Modul `buchen` 0.1.0** (BUCHUNGSMASKE.md, M1–M8): **Buchungsliste** (Ansichten Stapel / Journal / Alle, Werkzeugleiste, Kontrollsumme, Stapelfreigabe, Storno, Splitbuchungen aufklappbar) und **Buchungserfassung** (Konto / Gegenkonto mit Saldo, Rechnen im Betragsfeld, Belegnummer hoch- und herunterzählen, Buchungstext mit Vorschlägen, Zeilentabelle für Splitbuchungen, Speichern = Stapel, Buchen = festschreiben), **Kontoblatt** mit laufendem Saldo, **Geschäftsvorfälle** als gepflegte Vorlagen (Schema `buchen`, Rechte `buchen.vorlage.sehen` / `.pflegen`, sieben mitgelieferte allgemeine) und die Seite **„Buchen einrichten“** (Geschäftsjahre, Buchungsarten mit Nummernkreis, Nummernvorlagen, Tags). Alles mit der Tastatur bedienbar; Texte aus `sprachen/de.json`.
- Aktivieren je Mandant: `bin/kumero modul:aktivieren buchen MANDANT`.
- Oberfläche des Kerns: `api()` kann Kopfzeilen senden und zurückgeben (`kopf`, `mitKopf`); `passkeyBestaetigen()` für Freigaben.
- Vertrag `openapi/v1.yaml`: `reihenfolge` bei `GET /buchungen`, `konto` bei `GET /salden`, Antwort 428 ohne `If-Match`, Endpunkte des Moduls `buchen`.
- Tests: PHP 447 Prüfungen (neu: `tests/BuchenTest.php` mit 204), Browsertest `tests/browser/buchen.mjs` mit 27 Prüfungen.

### Entschieden
- **F83**: M1–M8 freigegeben; die Maske ist ein eigenes Modul `buchen`, die Endpunkte für Buchungen bleiben im Kern. Reihenfolge: 0.7.0 Buchen, 0.8.0 Zuordnung zur Anlage EÜR.
- **F84**: Festlegungen aus der Umsetzung von 0.5.0 (ANWENDUNG.md 10a, Punkte 1–8) bestätigt.

### Noch offen
- Festlegungen aus der Umsetzung in BUCHUNGSMASKE.md Abschnitt 7 sind zu bestätigen.
- Freigabe-Passkeys im Mandanten registrieren (`/passkeys`), Jahressperre und Wiederöffnen über REST.

## [0.6.4] – 2026-11-17

### Neu
- **Kontoart aus dem Bilanz-/GuV-Posten** (F81, Modul `kontenrahmen` 0.1.3): eigener Leser für die aktuellen DATEV-Kontenrahmen mit Posten-Spalte (SKR 03/04 nach BilRUG) über Wortpositionen und Kastenlinien (`pdftotext -bbox-layout`, `pdftocairo -svg`). Posten → Kontoart nach §§ 266, 275 HGB (`posten/hgb.json`); Posten und Programmverbindung stehen am Konto.
- Regelwerk **`skr04`**; die Regelwerke sind nur noch Rückfall und Gegenprüfung. Widersprechen sich Posten und Nummernbereich, ist das Konto zu bestätigen.
- Von DATEV reservierte Konten (R) werden ausgelassen; Bereichsenden mit vier Stellen, Kennzeichen `S/AV`/`S/AM`, Bereichsfunktionen mit Fußnote und zwei nebeneinander werden gelesen.
- Oberfläche: Herkunft der Kontoart mit Posten („aus Posten …“).
- Tests: PHP 243 Prüfungen (neu: Muster-PDF mit Posten-Spalte, Posten-Zuordnung).

## [0.6.3] – 2026-11-17

### Neu
- **Regelwerk `skr03`** (Modul `kontenrahmen` 0.1.2): Kontoart nach Nummernbereichen des SKR 03; unsichere Bereiche (ausstehende Einlagen, Rücklagen, Verrechnungskonten, durchlaufende Posten, Bestände, Bestandsveränderungen, kalkulatorische Kosten) werden zur Bestätigung angezeigt, Klasse 5/6 bleibt offen, Klasse 9 wird ausgelassen.
- Import: Regelwerk **„automatisch aus dem PDF“** (Rahmen aus der Kopfzeile, z. B. „SKR 03“); passt das gewählte Regelwerk nicht zum erkannten Rahmen, steht ein Hinweis im Prüfbericht.
- Tests: PHP 220 Prüfungen.

## [0.6.2] – 2026-11-17

### Neu
- **TLS-Zertifikat von außen** (z. B. ACME-Client der OPNsense, Let's Encrypt): `ZERTIFIKAT_QUELLE` in `install.conf`. `kumero-zertifikat` prüft ein hochgeladenes Zertifikat (lesbar, nicht abgelaufen, gilt für `SERVERNAME`, Schlüssel passt), übernimmt es nach `/etc/kumero/tls/`, prüft `nginx -t` und lädt nginx neu – sonst bleibt das alte. Ausgelöst von einer Path-Unit bei jedem Upload und täglich per Timer; Meldungen im Journal (`-t kumero-zertifikat`).
- nginx liest das Zertifikat immer aus `/etc/kumero/tls/` (anfangs eine Kopie von „snakeoil“).
- `install.sh` warnt, wenn `adresse` in `/etc/kumero/kumero.php` nicht zu `install.conf` passt, und nennt den Befehl zum Umstellen.
- `pruefen.sh`: Zertifikatsquelle, ausgeliefertes Zertifikat und Überwachung.

## [0.6.1] – 2026-11-17

### Neu
- **Weboberfläche: Rahmen und Menü** nach OBERFLAECHE.md (F80): Kopfleiste mit Mandantenwahl, Navigation links mit festen Bereichen (Start, Buchen, Stammdaten, Auswertungen, Abschluss, Einstellungen, Verwaltung), Seiten als ES-Module, gemeinsame Bausteine (Liste mit Tastaturführung, Reiter, Kacheln, Dialog). Einstieg `/app.html`; die alte Testseite `start.html` leitet dorthin weiter (Registrierungslinks bleiben gültig).
- **Menü aus den Manifesten**: Abschnitt `oberflaeche` in `modul.json` (Einträge, Seiten, Texte, Symbole), geprüft beim Lesen des Manifests; sichtbar nur bei aktivem Modul und Recht aus den Rollen (keine Rechte an Nutzern); systemweit `angemeldet` und `verwaltung.verwalter`. Wechselnde Unterpunkte über den Erweiterungspunkt `menue_unterpunkte`. Endpunkte `GET /api/v1/oberflaeche/menue` und `GET /m/{mandant}/api/v1/oberflaeche/menue`.
- **REST-Endpunkte der Module** über `Kumero\Api\V1\RoutenAnbieter`; auf Mandantenpfaden nur, wenn das Modul im Mandanten aktiv ist (`modul.nicht_aktiv`). Systemweite Endpunkte mit Recht `verwaltung.verwalter`.
- **Kontenrahmen-Import in der Weboberfläche** (Modul `kontenrahmen` 0.1.1): PDF oder CSV hochladen → Entwurf mit Prüfbericht → Kontoart wählen oder bestätigen, Bezeichnung ergänzen, Konten weglassen → als Vorlage speichern → im Mandanten anwenden; Vorlagen erscheinen als Unterpunkte im Menü. Seiten „Kontenrahmen anwenden“ und „Angewendete Kontenrahmen“ unter Stammdaten → Konten. Die hochgeladene Datei wird nicht aufbewahrt, nur ihr SHA-256.
- Seiten des Kerns: Übersicht und Kontenliste (mit Anlegen).
- nginx: strenge Content-Security-Policy (nur eigene Quellen, kein Inline-Code), Auslieferung der Modulseiten unter `/module/<name>/<version>/`.
- Installer: `/var/lib/kumero/kontenrahmen` jetzt `2770 root:kumero` (Import aus der Oberfläche), Unterverzeichnis `entwuerfe`.
- Werkzeuge: `werkzeuge/entwicklungsserver.php` (eingebauter PHP-Server wie nginx samt CSP); Browsertest `tests/browser/import.mjs` mit virtuellem Passkey in Chrome.
- OpenAPI: Tags „Oberfläche“ und „Kontenrahmen“ (39 Pfade, 51 Operationen).
- Tests: PHP 209 Prüfungen (neu: Menü, Manifestprüfung, Import über REST, Sichtbarkeit nach Rollen); Browser 10 Prüfungen.

### Geändert
- `kontenrahmen:einlesen` nutzt denselben Einlesepfad wie die Oberfläche (`Einlesen`).
- Registrierungslinks zeigen auf `/app.html#registrierung=…`.

### Noch nicht enthalten
- Buchen und Buchungsmaske (BUCHUNGSMASKE.md, 0.7.0); Zuordnung zur Anlage EÜR danach.
- Einstellungsabschnitte und Zähler im Menü (Format vorgesehen).

## [0.6.0] – 2026-11-17

### Neu
- **Modulrahmen** (KONTENRAHMEN.md K6): Manifest `module/<name>/modul.json`, Migrationen je Modul (`db/migrieren.sh --modul`, in `meta.migration` mit Präfix), `bin/kumero modul:liste` und `modul:aktivieren` (Migrationen, Registrierung, Rechte, Bausteine, Ergänzung der Rollen). `install/aktualisieren.sh` spielt die Migrationen aktiver Module je Mandant mit ein.
- **Modul `kontenrahmen` 0.1.0** (F75, F76):
  - PDF-Konverter für gedruckte DATEV-Kontenrahmen (`pdftotext -layout`): zwei Spalten, Kontenklassen, Untergruppen, Funktionskennzeichen, umbrochene Beschriftungen, Kontenbereiche, Fußnoten; Personenkonten werden übergangen, unsichere Zeilen kommen in den Prüfbericht.
  - CSV-Konverter und Ergänzung per CSV (Kontoart setzen, Konten ergänzen oder weglassen), UTF-8 oder Windows-1252.
  - Eigenes Regelwerk `skr81`: Kontoart nach Nummernbereichen; unsichere Bereiche werden zur Bestätigung angezeigt, Vortrags- und statistische Konten ausgelassen.
  - Vorlagen im Format `kumero-kontenrahmen/1`, installationsweit unter `/var/lib/kumero/kontenrahmen/` (K2), mit SHA-256 über die kanonische Form.
  - Anwenden auf einen Mandanten über `kern.konto_anlegen`; vorhandene Konten bleiben, abweichende Kontoarten werden als Konflikt gemeldet; Vermerk im Schema `kontenrahmen` und in der Protokollkette (F75 Nr. 6).
  - Kommandos `kontenrahmen:einlesen`, `kontenrahmen:vorlagen`, `kontenrahmen:zeigen`, `kontenrahmen:anwenden`.
  - Synthetischer Referenz-Kontenplan `referenz-skr81.csv` (F75 Nr. 3).
- `src/Plattform/Jcs.php`: kanonische Form nach RFC 8785 in PHP, gleich `kern.jcs`.
- Installer: Paket `poppler-utils`, Verzeichnis `/var/lib/kumero/kontenrahmen`. `pruefen.sh` prüft die Anwendung über `/api/v1/ich`, den Tunnel-Zugang und `kumero.php`; `selbsttest` prüft `pdftotext`.
- Tests: PHP 123 Prüfungen (neu: Kontenrahmen mit einem selbst erzeugten PDF im DATEV-Aufbau und erfundenen Konten).

### Noch nicht enthalten
- Zuordnung der Konten zur Anlage EÜR je Geschäftsjahr und Beitrag zur Jahressperre: Modul `kontenrahmen` 0.2.0 mit kumero 0.7.0.

## [0.5.0] – 2026-11-17

### Neu
- **PHP-Anwendung** nach ANWENDUNG.md (E8 c): Front-Controller, Weg einer Anfrage mit Anmeldung, Mandant, Wasserstand (F69), Recht (F41), Idempotenz (F71) und einer Transaktion je Aufruf; Fehler als Problem Details mit den Codes `KM001`–`KM006`.
- **Anmeldung per Passkey** (E2) mit eigener WebAuthn-Prüfung (E3) für ES256 und EdDSA; Registrierungslinks, Sitzungen in der Verwaltungsdatenbank, Cookie `__Host-kumero`, Herkunftsprüfung gegen CSRF.
- **REST-API** (openapi/v1.yaml, erste Stufe): Anmeldung, Registrierung, Profil; Konten, Tags, Nummernkreise samt Festlegung je Geschäftsjahr, Buchungsarten, Geschäftsjahre.
- **Kommandozeile** `bin/kumero`: `nutzer:anlegen`, `nutzer:link`, `nutzer:liste`, `mandant:anlegen`, `mandant:eintragen`, `mandant:liste`, `zugang:gewaehren`, `selbsttest`.
- **Testseite** `start.html`: Passkey registrieren, anmelden, Konten ansehen und anlegen.
- **Kern** `0010_verzoegerte_trigger.sql`: Die verzögerte Prüfung der lückenlosen Geschäftsjahre (F65) meldet als Anwendung jetzt `KM002` statt „keine Berechtigung“.
- **Installer:** `/etc/kumero/kumero.php`; Option `ENTWICKLUNG_TUNNEL` (nginx nur auf 127.0.0.1:8080 für den SSH-Tunnel); `/var/lib/kumero` 0751. `aktualisieren.sh` schaltet `/var/www/kumero` nach erfolgreicher Migration auf das neue Release um (E7), lädt PHP-FPM neu und führt `bin/kumero selbsttest` aus.
- **Release** enthält `vendor/` nach `composer.lock` (E1): Symfony 8.1, Doctrine DBAL 4.6, bezogen direkt aus den Git-Repositorys der Projekte; Archiv reproduzierbar.
- Tests: Datenbank 194 Prüfungen, PHP 85 Prüfungen (WebAuthn mit Software-Authentifikator, Ende-zu-Ende gegen PostgreSQL).

### Definition
- F78: REST-API v1 – Freigabe in drei Schritten, Storno als Entwurf, Bots geben nie frei.

 – 2026-11-16

### Behoben
- `install/aktualisieren.sh` übergibt nach Prüfung von Signatur und Prüfsumme an das Skript aus dem geprüften Release. Bisher lief der ganze Ablauf mit der aufgerufenen, womöglich älteren Kopie; bei 0.4.0 wurde dadurch die Verwaltungsdatenbank nicht migriert, wenn das Skript aus einem alten Checkout gestartet wurde.
- `db/migrieren.sh` vermerkt eine Version in `meta.version` nur einmal je Release, auch wenn das Update wiederholt wird.

Am Kern (Datenbank) ändert sich nichts.

## [0.4.0] – 2026-11-14

### Neu
- **Rechte und Rollen** (`0008_rechte.sql`, F41–F43): Pflege nur über Funktionen mit Protokoll; Rechtekatalog des Kerns und die sieben Rollenvorlagen aus F42 (`kern.rollenvorlagen_anlegen`); effektive Rechte samt Einschränkungen (`kern.rechte_des_subjekts`). Die Invarianten aus F43 erzwingt die Datenbank beim Commit: Notfall nur für Verantwortliche, Prüfer nur lesend, Administrator ohne Buchungsdaten.
- **Integration** (`0009_integration.sql`): Idempotenz nach F71 mit neuem Fehlercode `KM006`; Protokoll und Ereignisse von Modulen (`kern.modul_protokoll`); Empfänger im Prozess mit Lesestand je Modul; Webhook-Abos mit Protokoll; Zustellung mit Wiederholung.
- **Verwaltungsdatenbank** (`db/verwaltung/0001_verwaltung.sql`, F37, F69): Mandanten, Nutzer, Anmelde-Passkeys, Registrierungslinks, Zugänge, Dienstkonten, Tokens, Sitzungen, Wasserstände, eigene Protokollkette. Geheimnisse nur als SHA-256.
- `db/migrieren.sh --verwaltung`; `install/aktualisieren.sh` spielt die Verwaltungsdatenbank mit ein.
- Tests: 192 Prüfungen.

### Definition
- F76: ohne Steuerberater vollständig; erster Kontenrahmen SKR 81.
- F77: `ANWENDUNG.md` (PHP-Gerüst und Modul-API) freigegeben, Entscheidungen E1–E8.

## [0.3.0] – 2026-11-14

### Neu
- **Beitrag zur Jahressperre** (`db/migrationen/0007_gj_beitraege.sql`, F75 Nr. 11): neuer Erweiterungspunkt `gj_sperren_beitrag`.
  - Module liefern vor der Sperre den Hash ihres Datenstands für das Jahr (`kern.gj_beitrag_setzen`), z. B. die EÜR- und E-Bilanz-Zuordnung.
  - Der Kern nimmt die Beiträge in den freigegebenen und gesiegelten Sperrinhalt auf, verweigert die Sperre bei fehlendem oder veraltetem Beitrag und hält die Beiträge danach unveränderlich.
- Tests: 127 Prüfungen.

### Definition
- F75: Modul `kontenrahmen` – Konverter für alle Kontenrahmen (DATEV-PDF, DATEV-Kontenbeschriftungen, CSV); jeder Nutzer entscheidet selbst, was er konvertiert; gefüllter Referenz-Kontenrahmen im Repository; Zuordnung zu Anlage EÜR und E-Bilanz je Geschäftsjahr; Zuordnung wird mit der Jahressperre eingefroren; Kontenplan in Verfahrensdokumentation und Prüferexport (GoBD).

## [0.2.1] – 2026-11-14

### Behoben
- Debian 13 hat `gpgv` nicht mehr vorinstalliert (apt nutzt `sqv`). Der Installer installiert das Paket jetzt in Schritt 10 mit.
- `aktualisieren.sh` prüft vorher, ob `gpgv` vorhanden ist, und zeigt bei einer fehlgeschlagenen Prüfung die Meldung von `gpgv` an.
- `pruefen.sh` prüft zusätzlich, ob `gpgv` installiert und ein Freigabe-Schlüssel hinterlegt ist.

Am Kern (Datenbank) ändert sich nichts.

## [0.2.0] – 2026-11-14

### Neu
- **DDL Teil 2** (`db/migrationen/0006_funktionen.sql`): Schreibfunktionen des Kerns. Die Anwendung schreibt ausschließlich über sie (F64).
  - Stammdaten: Buch, Konto, Tag, Nummernkreis, Buchungsart, Geschäftsjahr, Nummernkreis festlegen, Modul, Erweiterungsattribut
  - Entwürfe: anlegen, ändern (mit ETag), löschen, jeweils mit Vorher-/Nachher-Stand im Protokoll
  - Vorbereiten mit strenger Regelprüfung (F4, F12, F24, F58), Prüfvermerke der Module (F72)
  - Challenge ausstellen; Festschreiben einzeln oder als Stapel mit WebAuthn-Freigabe (F38, F66–F68)
  - Storno, Beleg nachreichen, Geschäftsjahr sperren und im Notfall öffnen, Produktivbetrieb einschalten
  - `kern.kette_pruefen()`: rechnet beide Ketten unabhängig nach
- **Versions- und Genehmigungsablauf (F73):** `VERSION`, `CHANGELOG.md`, `werkzeuge/release-bauen.sh`, `install/aktualisieren.sh`. `db/migrieren.sh` verweigert Änderungen am installierten Kern ohne genehmigtes Release.
- Tests: 118 Prüfungen, darunter Manipulationserkennung und echte Parallelität (F71).

## [0.1.0] – 2026-11-14

### Neu
- Definition (AGENTS.md F1–F72), abstraktes Datenmodell (Fassung 3)
- Installer für Debian 13 (PostgreSQL 19 aus PGDG, PHP 8.5 aus sury, nginx, unattended-upgrades)
- DDL Teil 1: Schema `kern`, Constraints, Schutz-Trigger, kanonische Form nach RFC 8785
