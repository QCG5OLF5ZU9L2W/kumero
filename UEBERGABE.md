# UEBERGABE.md – Stand der Arbeit für den nächsten Chat

Stand: 2026-11-18, kumero **0.7.21**. Diese Datei sagt, wo die Arbeit steht und wie sie praktisch abläuft.
Maßgeblich bleiben **AGENTS.md** (Entscheidungen F1–F112, offene Punkte P10–P26) und **CLAUDE.md** (Arbeitsweise, Grenzen).
Wer weiterarbeitet, liest zuerst diese drei Dateien und danach die Spezifikation des Teils, an dem gearbeitet wird.

## 1. Wo der Code liegt

| | |
|---|---|
| Repository | `https://github.com/QCG5OLF5ZU9L2W/kumero` (öffentlich, AGPL) |
| **Arbeitszweig** | **`release-0.7.0`** – dort liegt alles bis 0.7.21 |
| `main` | steht noch auf dem Stand **vor 0.7.0** (`ca9d42c`). Wer `main` liest, sieht anderthalb Tage alte Dateien. Der Verantwortliche zieht `main` nach (`git push origin origin/release-0.7.0:main`). |
| Tags | setzt der Verantwortliche selbst auf `sbs-server`; aus einer Claude-Sitzung werden Tag-Pushes abgelehnt (HTTP 403). Zweig-Pushes gehen (`git -c push.negotiate=true push origin release-0.7.0`). |

Im lokalen Klon des Verantwortlichen auf `sbs-server` liegen uncommittete Dateien von nopaper (`nopaper.py`, `install.sh`, `lib/`,
`systemd/` …). Sie gehören nicht in kumero. Offen, was mit ihnen geschieht.

## 2. Wie eine Version abgegeben wird

1. Bauen, gezielt testen (F103), `VERSION`, `README.md` („Version …“), Modulversionen in `module/<name>/modul.json`, `CHANGELOG.md`.
   Jede Seite eines Moduls liegt unter `/module/<name>/<version>/…` – **ohne neue Modulversion lädt der Browser die alte Seite**.
2. Entscheidungen als neues F in AGENTS.md, offene Punkte als P; Festlegungen aus der Umsetzung ausdrücklich „zu bestätigen“.
3. Commit, Tag `v<version>`, `bash werkzeuge/release-bauen.sh` → `dist/kumero-<v>.tar.gz` und `.sha256`. Beide Dateien gehen an den Verantwortlichen.
4. Der Verantwortliche signiert und installiert auf der Test-VM (Host `aequa`, Mandant `spiel`, kein Git auf der VM):
   ```
   gpg --armor --detach-sign /root/kumero-<v>.sha256
   bash /var/lib/kumero/releases/<zuletzt installierte Version>/install/aktualisieren.sh /root/kumero-<v>.tar.gz
   cd /var/www/kumero && bin/kumero modul:aktivieren <modul> spiel     # nur bei einem neuen Modul
   ```
   Migrationen bereits aktiver Module spielt `aktualisieren.sh` selbst ein. Den Pfad immer mit der **tatsächlich installierten** Version angeben – nicht jede Version wird installiert.
5. Die Abgabe nennt genau, **was geprüft wurde und was nicht** (F103).

## 3. Regeln aus der Zusammenarbeit, die über AGENTS.md hinaus zu beachten sind

- **Testen (F103):** Beim Bauen nur die Tests des bearbeiteten Teils. Integrationstests macht der Verantwortliche auf der VM, mit eigenen Musterbelegen über die Kette nopaper → Paperless → kumero.
- **Nur synthetische Daten.** Der Verantwortliche schickt Bildschirmfotos; was darin steht (Beträge, Namen, Belegnummern), kommt **nie** in Code, Tests oder Doku. Muster erzeugen die Werkzeuge in `werkzeuge/` (`e-rechnung-muster.php`, `kontenrahmen-muster*.py`).
- **Keine Tokens, Schlüssel, Registrierungslinks** im Chat – auch nicht für einen Testzugang.
- **Nichts verschiebt sich (F106):** Meldungen in der Statusleiste, Knöpfe sperren statt ausblenden, gesperrte nennen beim Überfahren den Grund, fester Listenkopf.
- **Aktionen gelten für die markierten Einträge**, ohne Haken für die gewählte Zeile (F100, F107).
- **kumero hält kein Dokument dauerhaft (F110).**
- **Module lesen und schreiben keine Tabellen des Kerns oder anderer Module** (F44) – nur Modul-API und REST.
- Der Verantwortliche entscheidet kurz und erwartet danach das Bauen. Wo eine Vorgabe mehrere Lesarten hat oder etwas Größeres umgebaut würde, erst nachfragen – einmal, konkret, mit Vorschlag.

## 4. Was gebaut ist (0.7.x, Kurzfassung)

| Teil | Stand | Beschreibung |
|---|---|---|
| Buchen (Liste, Erfassung, Kontoblatt, Geschäftsvorfälle), Steuerkonten mit Satz, Split | gebaut | BUCHUNGSMASKE.md, F83, F85, F101 |
| Arbeitsvorrat: Quellen, Vorbelegung, Stapel, Spaltenfilter, „Doppelt?“, Vergleichen/Zusammenführen, Blättern, Verwerfen der Auswahl | gebaut | ARBEITSVORRAT.md, F89, F96, F100, F105, F107 |
| Paperless: Zugang je Nutzer, Tags, Quelle, Vorschau, Belegprüfung, Feldzuordnung, API-Versionen 9/10, **Belegablage** | gebaut | PAPERLESS.md, F92, F94, F95, F110 |
| Import: CSV, **E-Rechnungen** (XRechnung CII/UBL, ZUGFeRD/Factur-X), mehrere Steuersätze, **Zwischenablage mit Vorschau, Übergabe an das DMS nach der Buchung – PDF und (mit Plugin im Paperless) XML** | gebaut | IMPORT.md, F104, F108, F109, F110, F112 |
| Kontenrahmen-Import, Konten ändern, Erweiterungspunkt der Kontenliste | gebaut | KONTENRAHMEN.md, F79–F81, F102 |

## 5. Was als Nächstes ansteht

**Test auf der VM, 0.7.18/0.7.19:** Der Verantwortliche hat am 2026-11-18 gemeldet: „gebaut und getestet“. Einzelheiten (Übergabe an das echte Paperless 3.4.2, Vorschau des ZUGFeRD-PDF, Dauer des Abgleichs) wurden im Chat nicht genannt – bei Gelegenheit nachfragen, bevor „nur am Nachbau gelaufen“ aus den Spezifikationen gestrichen wird.

**Wartet auf den Test auf der VM (0.7.20, F112):**
- Im Paperless des Referenzbetriebs das Plugin `paperless-ngx-erechnung` einrichten, dann in kumero unter „Paperless – mein Zugang“ → „Belegablage für diesen Mandanten“ das Kreuz setzen.
- Eine synthetische XRechnung (`werkzeuge/e-rechnung-muster.php`) einlesen, buchen, zweimal „Jetzt abgleichen“. Zu sehen: Beleg an der Buchung nachgereicht, „Datei einlesen“ meldet 0 Originale, in Paperless liegt das XML als Original mit lesbarer Archivfassung.
- Hängt es: „Datei einlesen“ nennt, bei wie vielen Originalen die letzte Übergabe gescheitert ist; der Grund steht in `import.datei.abgelehnt_grund` (`paperless.xml_abgelehnt` = Paperless nimmt XML nicht an; sonst liegt es an der Suche über `original_checksum`).

**Entscheidungen, die der Verantwortliche noch treffen muss:**
- **P26 / F111 – E-Rechnungen von nopaper:** Pfad (`…/belegeingang/e-rechnungen/…` laut Vorgabe, bisher `…/nopaper/sidecars/…`), Aufbewahrung der XML (F110), unvollständige Paare.
- **IMPORT.md Abschnitt 16, D1–D12:** vor allem D1 (Beleg wird nach dem Festschreiben nachgereicht, steht nicht im versiegelten Kopf). **D5 ist entschieden (F112).** Neu zu bestätigen: D10–D12 (Einstellung je Mandant; kein Tausch XML ↔ PDF; Verhalten nach dem Ausschalten).
- **IMPORT.md Abschnitt 15:** Schnitt (Einlesen im Modul `import` statt eigenem Modul `e-rechnung`), Eingang/Ausgang wählt der Nutzer.
- **Zahlungsgrund im Buchungstext** bei Einträgen aus einer Bankzeile (Vorschlag: Gegenpartei + Zahlungsgrund).
- Festlegungen „zu bestätigen“ in F101, F105, F109, F112 und in den Abschnitten „Festlegungen aus der Umsetzung“ der Spezifikationen.
- Erwartete Ergebnisse in TESTFAELLE.md (die Spalte füllt der Verantwortliche).

**Gemerkt, noch nicht bauen (Vorgabe des Verantwortlichen vom 2026-11-18):**
- **Buchungsliste, Spalte „Beleg-Nr.“: die Symbole nach vorne.** „Beleg öffnen“ (Pfeil) und Vorschau (Auge) stehen heute hinter der Belegnummer und damit je Zeile an anderer Stelle. Sie sollen vor der Belegnummer stehen, an festem Platz (passt zu F106). Beim Bauen prüfen, ob dieselbe Reihenfolge auch im Arbeitsvorrat gelten soll.

**Nicht gebaut, in etwa dieser Reihenfolge sinnvoll:**
1. Modul `nopaper` (Sidecars annehmen, F89 W13; E-Rechnungen nach F111).
2. F106 auf den übrigen Seiten: „Datei einlesen“ (Schritte erscheinen dynamisch), Buchungsmaske (Splittabelle, einzelne Knöpfe), Formular „Neu“ der Kontenliste, „Paperless – mein Zugang“, Einrichtung.
3. Blättern (F107) und Spaltenfilter (F91) in den übrigen Listen (Buchungsliste, Konten, Kontoblatt, Geschäftsvorfälle, Regeln).
4. F93 Paperless-Zugang ins Nutzerprofil; Entsperren per Passkey (WebAuthn PRF); Livelog; Rückschreiben nach Paperless (F49).
5. Importer für Quicken / Finanzmanager (Splitbuchungen, Umbuchungen, QIF); X1b Regel aus der Vorauswahl; X2b Schalter brutto/aufteilen; X4 Hinweis über Belegnummer und Betrag; F90.
6. Danach 0.8.0: Zuordnung zur Anlage EÜR (P14: erst wenn das Buchen vollständig beschrieben ist).

## 6. Bekannte Stolpersteine

- **Browser-Zwischenspeicher:** Dateien unter `/kern/` haben keine Version im Pfad. Seit 0.7.19 setzen die nginx-Vorlagen `expires -1`; auf der VM ist das von Hand eingetragen. Zeigt der Browser nach einem Update „doesn't provide an export named …“, einmal die Websitedaten löschen.
- **Paperless nimmt XML nur mit Plugin an** (F112). Ohne die Einstellung des Mandanten lagert kumero kein XML zwischen. Der Nachbau lehnt XML ab, solange im Zustand `plugin_e_rechnung` fehlt.
- **Lokale Tests mit PHP 8.3:** `composer.lock` legt Symfony 8.1 fest (braucht PHP ≥ 8.4). Für die Tests einmal `composer update -W --with symfony/console:^7.4 --with symfony/http-foundation:^7.4 --with symfony/routing:^7.4` und danach `git checkout composer.lock` – die Lock-Datei bleibt, wie sie ist. Rollen der Testdatenbank: `kumero_owner` (CREATEDB), `kumero_app`, `kumero_lesen`.
- **Paperless 3.4.2** nennt die **SHA-256** des Originals (nicht MD5) und spricht API-Version 10; `/api/tasks/` hat dort eine andere Gestalt und wird von kumero nicht genutzt.
- **Strenge CSP:** keine `style`-Attribute, kein Inline-Skript; `element.style.x = …` über das CSSOM geht.
- Neue Module brauchen Autoload-Einträge in `composer.json` und danach `composer dump-autoload -o`.
- Ein Manifest darf zusätzliche Schlüssel tragen (`arbeitsvorrat_quelle`, `oberflaeche_erweiterungen`); `erweiterungspunkte` bleibt leer.
- Entwicklung und Tests laufen ohne PHP 8.5 / PostgreSQL 19 auch mit PHP 8.3 und PostgreSQL 16 (F84 Nr. 2); das Release enthält Symfony 8.1 für PHP 8.5.

## 7. Tests lokal (Kurzfassung, Einzelheiten in CLAUDE.md)

```bash
KUMERO_TEST_PGHOST=/tmp/pgtest KUMERO_TEST_PGUSER=postgres php tests/lauf.php Import      # gezielt, mit Filter
bash db/tests/ausfuehren.sh                                                               # SQL-Tests, wenn der Kern berührt ist
PLAYWRIGHT=… CHROME=… node tests/browser/einlesen.mjs [Bildverzeichnis]                   # Browserläufe je Seite:
#   import.mjs (Kontenrahmen), buchen.mjs, arbeitsvorrat.mjs, paperless.mjs, einlesen.mjs
```

Der nachgebaute Paperless-Server für Tests ist `tests/Hilfe/paperless_nachbau.php`.
