# CLAUDE.md – Hinweise für die Arbeit mit Claude an kumero

Maßgeblich ist **AGENTS.md** (Ziel, Leitlinien, alle Entscheidungen F1 ff.). Diese Datei fasst nur
zusammen, wie gearbeitet wird.

**Wer neu einsteigt, liest zuerst UEBERGABE.md** – dort steht der laufende Stand: Arbeitszweig, letzte Version, was als
Nächstes ansteht und welche Entscheidungen offen sind. Gearbeitet wird auf dem Zweig `release-0.7.0`, nicht auf `main`.

## Arbeitsweise
- **Erst definieren, dann bauen.** Neue Schritte werden als Entwurf beschrieben (eigene .md-Datei oder
  Abschnitt), mit nummerierten Entscheidungen. Gebaut wird erst nach Freigabe durch den
  Projektverantwortlichen; die Entscheidung kommt dann als neues F in AGENTS.md.
- **Module** schaltet der Administrator in der Oberfläche (Mandant → Module, F142); nach einem Update zieht `aktualisieren.sh` die aktiven nach. Eine neue Modulversion braucht deshalb keinen Handgriff mehr in INSTALL.md Abschnitt 4 – nur noch, was am System zu tun ist. Ein Modul gibt einer Rolle jeden Baustein genau einmal (F143): Was der Administrator einer Rolle nimmt, kommt nicht zurück – **nie etwas bauen, das eine Einstellung des Administrators bei einem Update stillschweigend zurücksetzt.**
- **Jeder Schritt ist ein Release** (F73): `VERSION`, `CHANGELOG.md`, **`HASHLISTE` neu erzeugen (`bash werkzeuge/hashliste.sh erzeugen`, als Letztes vor dem Commit, F162)**, Tag `v<version>`. Den Tag setzt und das Archiv
  baut der Projektverantwortliche selbst (`INSTALL.md`, `werkzeuge/release-vom-tag.sh`); abgegeben wird der Commit. Installiert wird nur, was der Projektverantwortliche signiert hat
  (`install/aktualisieren.sh`). Am installierten Kern ändert niemand etwas ohne diese Freigabe.
- **Ein Entwurf für ein neues Modul nennt seine Rollen**, nicht nur seine Rechte: Wer arbeitet damit, und was darf er sonst nicht
  sehen? Module können eigene Rollen mitbringen (`eigene_rollen` im Manifest, F134).
- **Ein Modul gehört zu einer Ebene** (F135): zu einem Buch (Vorgabe) oder zum Mandanten (`"ebene": "mandant"` im Manifest). Was Personen
  betrifft und nicht eine Buchhaltung, gehört zum Mandanten: Schema in der Datenbank des Mandanten, Rechte und Rollen im Schema
  `mandant`, Pfade `/m/<mandant>/api/v1/<modul>/…`, aktiviert ohne Buch. Ein Buchmodul liest dort nichts, es fragt über
  `Dienste::auskunftAmMandanten()` und bekommt nur ID und Anzeigenamen.
- **Personaldaten:** nach Datenklassen getrennt ablegen, jede mit eigenem Recht; im Protokoll nur IDs und Namen geänderter Felder, nie ein
  Wert; kein Zugriff für `kumero_lesen`; Antworten enthalten nur, was die Rolle sehen darf (F136).
- **Oberfläche:** Listen tragen Sortierung und Filter am Spaltenkopf (`tabellenfilter.js`, F91; dazu als Anforderung, noch nicht gebaut: ein freier Textfilter je Spalte, F193); **ihre Spalten sind verschiebbar – Breite und
  Reihenfolge (F146): `tabellenfilter(spalten, zeichnen, {id})`, Zellen in der Reihenfolge von `spalten()`**; eine Zweiteilung aus Liste und
  Einzelheiten nutzt den verschiebbaren Teiler (`teiler.js`, F137). **Ein Doppelklick (und Enter) auf einen Eintrag öffnet seine Einzelheiten – wo etwas zu entscheiden ist, die Entscheidung (F150).** Nichts verschiebt sich von selbst (F106).
- **Dialoge mit mehreren Feldern in einer Zeile nutzen das Raster** (`rfeld`, `rwahl` in `module/lager/oeffentlich/gemeinsam.js`, Klasse `lg-raster`; STUECKLISTE.md AG6): Beschriftung über dem Feld, darunter immer eine Zeile für den Hinweis. **Eine Auswahl des Nutzers wird nie still zurückgesetzt** – was nicht zusammenpasst, sperrt den Knopf und nennt den Grund (SA6).
- **Dokument-Verweise sind ein abstraktes Merkmal (F166, noch nicht gebaut):** Ein Entwurf nennt, welche Gegenstände eines Moduls Dokumente tragen; die Technik liegt im Kern, nicht im Modul.
- **Was an einem Modul hängt, das es noch nicht gibt:** als Anschlussstelle bauen, die sich ersetzen lässt, und sichtbar machen, was fehlt (F140: `personal._zuschlag_nachweis()`, „Nachweis fehlt“ statt eines Betrags) – nie eine Annahme fest verdrahten.
- **Regelwerke** (Feiertage, AfA-Tabelle, Arbeitszeit, Kündigungsfristen): Vorgabe als Steuerdatei im Modul, vom Mandanten in der Oberfläche anzupassen (F139); der Verantwortliche prüft sie.
- **Pfadparameter `{kennung}`** lässt der Kern nur mit Kleinbuchstaben, Ziffern und Bindestrich zu – für anderes einen anderen Namen wählen.
- **DATENMODELL.md beschreibt den gebauten Stand** (Fassung 4): Wer eine Migration schreibt, zieht es im selben Commit nach – Geplantes steht dort nur in Abschnitt 8.
- **Pfade im Mandanten:** am Server `Anfrage::mandantPfad()`, in der Oberfläche `kontext.apiBasis`, `seitenBasis`, `buchSeiten()` – nie `/m/<mandant>` von Hand zusammensetzen; unter der eigenen Domain eines Mandanten steht er nicht im Pfad (F181).
- **Adresse von kumero, RP-ID, Passkey-Prüfer, Links auf kumero:** nur über die Anfrage (`Anfrage::adresse()`, `rpId()`, `pruefer()`, `link()`), nie aus der Konfiguration (F180; `tests/AdresseTest.php` wacht darüber).
- Sprache in Code, Doku und Commits: Deutsch.

## Grenzen (nicht verhandelbar)
- Daten nach Art. 9 DSGVO bleiben lokal: keine Cloud, keine externe API, keine Telemetrie.
- Tests nur mit **synthetischen** Daten. Echte Belege, Buchungen und Kontenpläne von Mandanten kommen nie
  ins Repository, in Tests, Fixtures, Issues oder Chats (F75 Nr. 9).
- DATEV-Kontenrahmen-PDFs werden nicht eingecheckt; Tests nutzen nachgebaute Muster-PDFs
  (`werkzeuge/kontenrahmen-muster*.py`).
- Keine Tokens, Schlüssel oder Registrierungslinks in Commits oder Chats. Der private Freigabeschlüssel
  des Projektverantwortlichen liegt nie auf einem Server. Benannte Ausnahme (F171): `installer.sh` erzeugt auf Nachfrage einen
  Schlüssel auf der Maschine; sie gibt sich dann selbst frei, und das steht sichtbar in `/etc/kumero/selbst-freigegeben`.
- Logs enthalten keine Buchungsinhalte.
- **Keine Sitzung ohne Passkey in kumero – auch nicht als Schalter, Modus oder Testhilfe.** Die Spielwiese öffnet sich durch einen Pförtner außerhalb von kumero (F183); wer dafür etwas braucht, baut es dort.

## Aufbau
| Pfad | Inhalt |
|---|---|
| `db/migrationen/`, `db/mandant/`, `db/verwaltung/` | Kern (Datenbank eines **Buchs**), Datenbank des Mandanten (Nutzer, Bücher; F126) und Verwaltungsdatenbank (PostgreSQL ≥ 19, F54), `db/migrieren.sh` |
| `db/mandant/0002_module_rollen.sql` | Module, Rechte, Bausteine, Rollen und Zuweisungen am Mandanten (F135) – gleiche Funktionen wie im Kern eines Buchs |
| `db/tests/` | SQL-Tests (`db/tests/ausfuehren.sh`) |
| `src/` | PHP-Anwendung (≥ 8.5), Modul-API `Kumero\Api\V1` |
| `module/<name>/` | Module mit `modul.json`, Migrationen, `src/`, `oeffentlich/` (Seiten) |
| `public/` | Front-Controller, Rahmen der Oberfläche (`app.html`, `kern/`) |
| `openapi/v1.yaml` | REST-Vertrag (erst Vertrag, dann Code, F18) |
| `install/` | Installer für Debian 13, `aktualisieren.sh`, **`sichern.sh` und `wiederherstellen.sh` (Sicherung von kumero ohne Paperless, Zurückspielen nur auf eine leere Maschine gleichen Hostnamens, INSTALL.md 3b, F176; Test `install/sichern-test.sh`)**, **`installer.sh` (Vollinstallation in einem Lauf, auf Wunsch mit Paperless, INSTALL.md 2a, F170; Test `install/installer-test.sh`)**; der Ablauf steht in `INSTALL.md` – **einmalige Schritte einer Version dort in Abschnitt 4 nachtragen** |
| `install/spielwiese.sh`, `install/spielwiese/` | **Spielwiese (SPIELWIESE.md): Pförtner außerhalb von kumero und sein Installationsskript – nur im Repository, nie im Release (`release-bauen.sh` lässt sie aus), nie von `installer.sh` aus erreichbar; lädt keinen Code von kumero.** Tests `install/spielwiese-test.sh`, `php tests/spielwiese/lauf.php` |
| `tests/` | PHP-Tests (`php tests/lauf.php`), Browsertests je Seite unter `tests/browser/` (z. B. `buchen.mjs`, `auswertungen.mjs`, `lager.mjs`, `personal.mjs` – dort auch die Zeiterfassung) |
| `werkzeuge/` | Release bauen, **`update-neueste.sh` (Update der VM in einem Lauf, INSTALL.md 3a, F144)**, Entwicklungsserver, Muster-PDFs |

Entwürfe und Spezifikationen: ANWENDUNG.md, DATENMODELL.md, KONTENRAHMEN.md (darin Abschnitt 12: EÜR-Kontenrahmen aus dem Vordruck des BMF, gebaut mit 0.7.80 – `module/kontenrahmen/referenz/`, erzeugt von `werkzeuge/euer-kontenrahmen.py`, F184, F185, F187), OBERFLAECHE.md,
BUCHUNGSMASKE.md, PROFIL.md, ARBEITSVORRAT.md, BELEGEINGANG.md (Sidecar von nopaper, gelesen aus Paperless – Abschnitt 9, F127), NOPAPER-AUFTRAG.md (überholt: Einlieferung durch nopaper), PAPERLESS.md, ONLINEKONTEN.md (Umsätze online bei der Bank abrufen, Modul `bank` – Entwurf Fassung 2 zur Freigabe, F173, P15), LIVELOG.md, IMPORT.md (CSV-Dateien und E-Rechnungen als Quelle des Arbeitsvorrats), VERWALTUNG.md (Mandanten, Nutzer, Zugang und Rollen – Stufe 1, Mandanten und „Nutzer und Rollen“ gebaut; Seite „Module“ mit Ein- und Ausschalten und Nachziehen gebaut mit 0.7.49, F142, F143 – Abschnitt 16; Passkey verloren: Wiederherstellung im Selfservice – Entwurf zur Freigabe, P45 – Abschnitt 17; eigene Domain je Mandant gebaut mit 0.7.79, F177, F181 – Abschnitt 18; Erscheinungsbild offen), SIEGEL.md (Siegel auf Anforderung, Signatur außerhalb – erste Stufe gebaut), BUECHER.md (mehrere Bücher je Mandant – Stufe B und C gebaut mit 0.7.30, F126; Testbuch als Kopie offen), AUSWERTUNGEN.md (Summen- und Saldenliste, Journal, Kontoblätter, eigene Auswertungen – erste Stufe gebaut, F128), ANLAGEN.md (Anlagenbuchhaltung – erste Stufe gebaut, F129), LAGER.md (Warenwirtschaft: Lager mit Chargen und Verfallsdaten – Stufen 0 bis 3 gebaut mit 0.7.37 bis 0.7.40, eigene Rollen mit 0.7.41, F130–F134; Dokumente am Artikel in Paperless – Entwurf zur Freigabe, F163, P47 – Abschnitt 15; Gegenstände für den Eigenbedarf in Katalog und Anlagen – Entwurf zur Freigabe, F165, P49 – Abschnitt 16; Artikelgruppen für ersetzbares Verbrauchsmaterial gebaut mit 0.7.73, F168, F169 – Abschnitt 17), STUECKLISTE.md (Nacharbeit an der Artikelgruppe – Aufgabe, neue Maske, „Artikel neu …“ in die markierte Gruppe – gebaut mit 0.7.83, F189; dazu als Entwurf zur Freigabe, P54: erweiterbare Art des Artikels, Stücklisten als Baum mit Modulen und Versionen in den Anlagen, Sets im Lager, Produktangaben und Merkmale), KATALOG.md (Artikelkatalog über alle Mandanten – Entwurf zur Freigabe, F164, P48), PERSONAL.md (Personal am Mandanten – Stufe 0, Module und Rollen am Mandanten, gebaut mit 0.7.42, F135; Stufe 1, Modul `personal` mit Person, Vertrag, Fristen, Akte und Selfservice, gebaut mit 0.7.43, F136; Stufe 2, Abwesenheiten mit Antrag und Genehmigung, AU ohne Diagnose, Urlaubskonto, gebaut mit 0.7.45, F138; Feiertage je Arbeitsort aus einem Regelwerk, Urlaubsregel, gebaut mit 0.7.46, F139 – Abschnitt 10c; Stufe 3, Zuschlagsregeln und Lohnvorbereitung ohne die Quellen `zeit` und `leistungen`, gebaut mit 0.7.47, F140 – Abschnitt 10d; Stufe 4, Beurteilungen und Zielvereinbarungen, gebaut mit 0.7.48, F141 – Abschnitt 10e; zufällige Personalnummer und akademischer Titel mit 0.7.55, F147 – Abschnitt 10g; Kündigungsfrist nach Gesetz, Tarifvertrag oder individuell mit 0.7.56, F148 – Abschnitt 10h; Zugang anlegen im Dialog der Person, Nummer beim Lohnbüro mit 0.7.57, F149 – Abschnitt 10i; Vergütung als Raster an der Person mit 0.7.66, F157 – Abschnitt 10m), ZEIT.md (Zeiterfassung – Modul `zeit` mit eigener Rolle, Stufe 1 und 2 ohne Geräte gebaut mit 0.7.53, F145 – Abschnitt 9), LEISTUNGEN.md (Dienstleistungen als Grundlage der Zuschläge – Modul `leistungen` am Mandanten mit eigener Rolle, Stufe 1 gebaut mit 0.7.63, F152, F154 – Abschnitte 10 und 11), DIENSTPLAN.md (Dienstplan – Entwurf zur Freigabe, P37), FAKTURA.md (Kunden, Projekte und Rechnungen – Entwurf zur Freigabe, P41), CODEINTEGRITAET.md (nur freigegebener Code läuft: Hashliste mit Signatur von außen – Entwurf, zurückgestellt bis zur Produktionsreife, P46), SCHLUESSEL.md (Verschlüsselung personenbezogener Daten, Rollenschlüssel, Postkorb – aufgenommen und vertagt, P38), SPIELWIESE.md (kumero offen und ohne Anmeldung zum Ausprobieren – gebaut mit 0.7.81, F183–F186, F188, P53), UEBERGABE.md (laufender Stand für den nächsten Chat), TESTFAELLE.md (fachliche Testfälle; die erwarteten Ergebnisse legt der Verantwortliche fest). Was noch zur Freigabe ansteht, nennt AGENTS.md Abschnitt 5.

## Tests lokal

**Nur Unittests (F103, F121):** Beim Bauen wird nur getestet, was gerade bearbeitet wird – gezielter Lauf mit Filter
(`php tests/lauf.php Buchen`), betroffene SQL-Tests. Die ganzen Läufe über alle Module, die Browsertests und Läufe
gegen echte Fremdsysteme sind Integrationstests; **die vollständige Integration wird erst getestet, wenn viel weiter
entwickelt ist**, dann gemeinsam mit dem Verantwortlichen. Das Weiterbauen wartet nicht darauf.
```bash
composer install                                   # Abhängigkeiten aus den VCS-Quellen in composer.json
# PostgreSQL mit Superuser, Socket z. B. /tmp/pgtest
KUMERO_TEST_PGHOST=/tmp/pgtest KUMERO_TEST_PGUSER=postgres php tests/lauf.php
bash db/tests/ausfuehren.sh                        # SQL-Tests
PLAYWRIGHT=… CHROME=… node tests/browser/import.mjs   # Browsertests mit virtuellem Passkey
PLAYWRIGHT=… CHROME=… node tests/browser/buchen.mjs
```
Testdatenbanken heißen immer `kumero_test*`; nur für sie verzichtet `db/migrieren.sh` auf die Freigabe.

Seit 0.7.30 (F126) hat ein Mandant Bücher: Pfade der Buchhaltung heißen `/m/<mandant>/b/<buch>/api/v1/…`, die Datenbank
eines Buchs `kumero_<mandant>__<buch>`; `mandantAnlegen()` legt das Buch `haupt` gleich mit an, Rollen gelten je Buch.
Seit 0.7.22 (F114) gehören Nutzer zum Mandanten. Tests legen deshalb erst den Mandanten an (mit seinem Administrator),
dann die Identität mit Rolle, richten den Passkey ein und melden an – die Hilfen dafür stehen gemeinsam in
`tests/Hilfe/Zugaenge.php` (`mandantAnlegen()`, `nutzer()`, `verwalter()`, `anmelden()`). Ein Modul am Mandanten prüft
`tests/MandantenmoduleTest.php` mit dem Prüfmodul `tests/fixtures/module/probe` (`Module::fuerTestsAnmelden()`, nur mit `KUMERO_TEST=1`). Wer als System-Verwalter und im
Mandanten arbeitet, braucht zwei Identitäten und wechselt die Anmeldung.
