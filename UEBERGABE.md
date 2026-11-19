# UEBERGABE.md – Stand der Arbeit für den nächsten Chat

Stand: 2026-11-19, kumero **0.7.25**. Diese Datei sagt, wo die Arbeit steht und wie sie praktisch abläuft.
Maßgeblich bleiben **AGENTS.md** (Entscheidungen F1–F119, offene Punkte P10–P28) und **CLAUDE.md** (Arbeitsweise, Grenzen).
Wer weiterarbeitet, liest zuerst diese drei Dateien und danach die Spezifikation des Teils, an dem gearbeitet wird.

## 0. Übergabe 0.7.23

Dateiimport für camt.053 und camt.054 (F116), aufbauend auf der Benutzerverwaltung aus 0.7.22
(Commit `8e5a555`). Release-Archiv und Prüfsummendatei sind zur Signatur durch den Verantwortlichen vorgesehen.
Die Freigabe, Installation und Prüfung auf der Test-VM stehen aus.

Geprüft mit den Bibliotheken aus `composer.lock`, PHP 8.4 und PostgreSQL 17: 1170 PHP-Prüfungen,
die SQL-Suite einschließlich Paralleltest sowie alle fünf Browserläufe (210 Prüfungen).
Die Importprüfungen verwenden synthetische Bankdaten. Die Überführung der Nutzer auf einem echten Bestand
und das Aktualisierungsskript auf der Test-VM sind weiterhin offen.

## 1. Wo der Code liegt

| | |
|---|---|
| Repository | `https://github.com/QCG5OLF5ZU9L2W/kumero` (öffentlich, AGPL) |
| **Arbeitszweig** | **`release-0.7.0`** – lokaler Abgabestand 0.7.23 |
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

### Besonderheit beim Update auf 0.7.22 (Nutzer wandern in den Mandanten)

```
pg_dump -U kumero_owner -Fc kumero_verwaltung > /root/kumero_verwaltung-vor-0.7.22.dump     # vorher, als root
bash /var/lib/kumero/releases/<installiert>/install/aktualisieren.sh /root/kumero-0.7.22.tar.gz
```
- `aktualisieren.sh` ruft nach den Migrationen `bin/kumero system:nutzer-ueberfuehren` auf. Der Befehl ist wiederholbar;
  seine Ausgabe nennt je Nutzer, wohin er überführt wurde und wer einen neuen Passkey braucht.
- **Auf der VM zu erwarten:** Der Verantwortliche ist heute Verwalter **und** hat Zugang zu `spiel`. Sein Passkey führt
  danach in den Mandanten `spiel` (dort fragt die Oberfläche einmal Vorname(n) und E-Mail ab). Für die Verwaltung der
  Installation braucht er einen zweiten Passkey: `bin/kumero verwalter:liste`, dann `bin/kumero verwalter:link <id>`.
- **Notausgang**, falls niemand mehr hineinkommt: `verwalter:link` (Verwaltung) bzw. `nutzer:liste` und
  `nutzer:link <mandant> <nutzer>` (Mandant). Beide brauchen nur die Kommandozeile.
- Zwei Passkeys im selben Passkey-Speicher: Der Browser fragt bei der Anmeldung, welcher gemeint ist; die Einträge heißen
  „<Name> – <Mandant>“ bzw. „<Name> – Verwaltung“.

## 3. Regeln aus der Zusammenarbeit, die über AGENTS.md hinaus zu beachten sind

- **Testen (F103):** Beim Bauen nur die Tests des bearbeiteten Teils. Integrationstests macht der Verantwortliche auf der VM, mit eigenen Musterbelegen über die Kette nopaper → Paperless → kumero.
- **Nur synthetische Daten.** Der Verantwortliche schickt Bildschirmfotos; was darin steht (Beträge, Namen, Belegnummern), kommt **nie** in Code, Tests oder Doku. Muster erzeugen die Werkzeuge in `werkzeuge/` (`e-rechnung-muster.php`, `kontenrahmen-muster*.py`).
- **Keine Tokens, Schlüssel, Registrierungslinks** im Chat – auch nicht für einen Testzugang.
- **Nichts verschiebt sich (F106):** Meldungen in der Statusleiste, Knöpfe sperren statt ausblenden, gesperrte nennen beim Überfahren den Grund, fester Listenkopf.
- **Aktionen gelten für die markierten Einträge**, ohne Haken für die gewählte Zeile (F100, F107).
- **kumero hält kein Dokument dauerhaft (F110).**
- **Module lesen und schreiben keine Tabellen des Kerns oder anderer Module** (F44) – nur Modul-API und REST.
- **Nutzer gehören zum Mandanten (F114).** Nichts über einen Nutzer eines Mandanten gehört in die Verwaltungsdatenbank – dort steht nur sein Pseudonym. Die ID `nutzer:<uuid>` verlässt den Mandanten nicht.
- Der Verantwortliche entscheidet kurz und erwartet danach das Bauen. Wo eine Vorgabe mehrere Lesarten hat oder etwas Größeres umgebaut würde, erst nachfragen – einmal, konkret, mit Vorschlag.

## 4. Was gebaut ist (0.7.x, Kurzfassung)

| Teil | Stand | Beschreibung |
|---|---|---|
| Buchen (Liste, Erfassung, Kontoblatt, Geschäftsvorfälle), Steuerkonten mit Satz, Split | gebaut | BUCHUNGSMASKE.md, F83, F85, F101 |
| Arbeitsvorrat: Quellen, Vorbelegung, Stapel, Spaltenfilter, „Doppelt?“, Vergleichen/Zusammenführen, Blättern, Verwerfen der Auswahl | gebaut | ARBEITSVORRAT.md, F89, F96, F100, F105, F107 |
| Paperless: Zugang je Nutzer, Tags, Quelle, Vorschau, Belegprüfung, Feldzuordnung, API-Versionen 9/10, **Belegablage** | gebaut | PAPERLESS.md, F92, F94, F95, F110 |
| Import: CAMT.053 und CAMT.054 (F116), CSV, **E-Rechnungen** (XRechnung CII/UBL, ZUGFeRD/Factur-X), mehrere Steuersätze, **Zwischenablage mit Vorschau, Übergabe an das DMS nach der Buchung – PDF und (mit Plugin im Paperless) XML** | gebaut | IMPORT.md, F104, F108, F109, F110, F112 |
| Kontenrahmen-Import, Konten ändern, Erweiterungspunkt der Kontenliste | gebaut | KONTENRAHMEN.md, F79–F81, F102 |
| **Benutzerverwaltung Stufe 1:** Nutzer, Passkeys, Sitzung, Einstellungen im Mandanten; Anmeldung über das Pseudonym; System-Verwalter als eigene Identität; Überführen des Bestands | gebaut (0.7.22), mit den Bibliotheken aus composer.lock geprüft | VERWALTUNG.md Abschnitt 11 und 13, F114, F115 |
| **Mandantenverwaltung des System-Verwalters:** Seite „Mandanten“ – anlegen mit erstem Administrator, stilllegen, exportieren (pg_dump auf dem Server), löschen; Aufträge und Dienst `kumero-auftraege` | gebaut (0.7.25); **systemd-Einheit auf der VM zu prüfen** | VERWALTUNG.md Abschnitt 14, F118 |

## 5. Was als Nächstes ansteht

**Wartet auf den Test auf der VM (0.7.25, F118 – Seite „Mandanten“ des System-Verwalters):**
- Nach dem Update einmalig von Hand: Export-Verzeichnis, `kumero-auftraege.service` und `.timer` einrichten, nginx auf `client_max_body_size 350m` (Befehle im CHANGELOG 0.7.25).
- Als System-Verwalter: Verwaltung → Mandanten. Einen Testmandanten anlegen, den Link des Administrators verwenden, stilllegen, exportieren, löschen. **Nicht den Mandanten `spiel` löschen.**
- **Die systemd-Einheit ist ungeprüft.** Bleibt ein Auftrag auf „wartet auf den Dienst“: `systemctl status kumero-auftraege.timer`, `journalctl -u kumero-auftraege`; von Hand geht `bin/kumero system:auftraege`. Scheitert der Dienst an seinen Einschränkungen, in der Einheit `ProtectSystem`, `PrivateNetwork`, `RestrictAddressFamilies` auskommentieren und melden.
- Zu bestätigen: VERWALTUNG.md Abschnitt 14, M1–M9 – vor allem M2 (keine Zahlen aus dem Mandanten vor dem Löschen), M5 (die Sicherung bleibt nach dem Löschen liegen), M8 (Dienst läuft als root).

**Wartet auf den Test auf der VM (0.7.22, F115):**
- Update wie in Abschnitt 2 beschrieben; danach Anmeldung im Mandanten `spiel` mit dem vorhandenen Passkey, Abfrage der Angaben, Buchen wie bisher (Rollen, Freigabe-Passkey und Paperless-Zugang hängen an derselben ID und gelten weiter).
- Zweiter Passkey für die Verwaltung über `verwalter:link`; dort Kontenrahmen-Import. Mit dem Verwalter-Passkey kommt man in **keinen** Mandanten.
- Im Mandanten gilt: Wer nur Verwalter-Rechte hatte, sieht den Bereich „Verwaltung“ nicht mehr; „Kontenrahmen anwenden“ steht unter Stammdaten → Konten, für Rollen mit dem Recht `kontenrahmen.vorlage.anwenden`.

**Test auf der VM, 0.7.18/0.7.19:** Der Verantwortliche hat am 2026-11-18 gemeldet: „gebaut und getestet“. Einzelheiten (Übergabe an das echte Paperless 3.4.2, Vorschau des ZUGFeRD-PDF, Dauer des Abgleichs) wurden im Chat nicht genannt – bei Gelegenheit nachfragen, bevor „nur am Nachbau gelaufen“ aus den Spezifikationen gestrichen wird.

**Wartet auf den Test auf der VM (0.7.20, F112):**
- Im Paperless des Referenzbetriebs das Plugin `paperless-ngx-erechnung` einrichten, dann in kumero unter „Paperless – mein Zugang“ → „Belegablage für diesen Mandanten“ das Kreuz setzen.
- Eine synthetische XRechnung (`werkzeuge/e-rechnung-muster.php`) einlesen, buchen, zweimal „Jetzt abgleichen“. Zu sehen: Beleg an der Buchung nachgereicht, „Datei einlesen“ meldet 0 Originale, in Paperless liegt das XML als Original mit lesbarer Archivfassung.
- Hängt es: „Datei einlesen“ nennt, bei wie vielen Originalen die letzte Übergabe gescheitert ist; der Grund steht in `import.datei.abgelehnt_grund` (`paperless.xml_abgelehnt` = Paperless nimmt XML nicht an; sonst liegt es an der Suche über `original_checksum`).

**Entscheidungen, die der Verantwortliche noch treffen muss:**
- **VERWALTUNG.md Abschnitt 13, S1–S13** – Festlegungen aus der Umsetzung der Stufe 1. Vor allem: S1 (das Pseudonym entsteht mit der Identität, nicht erst beim Einrichten des Passkeys), S5 (neuer Befehl `nutzer:rolle`), S8 (nach dem Überführen bleibt in der Verwaltung ein gesperrter Eintrag „(überführt)“; **die unveränderliche Protokollkette der Verwaltung behält die alten Einträge mit Namen**), S13 (bis Stufe 2 pflegt die Kommandozeile Nutzer und Rollen).
- **IMPORT.md Abschnitt 16, D1–D12:** vor allem D1 (Beleg wird nach dem Festschreiben nachgereicht, steht nicht im versiegelten Kopf). **D5 ist entschieden (F112).** Neu zu bestätigen: D10–D12 (Einstellung je Mandant; kein Tausch XML ↔ PDF; Verhalten nach dem Ausschalten).
- **IMPORT.md Abschnitt 15:** Schnitt (Einlesen im Modul `import` statt eigenem Modul `e-rechnung`), Eingang/Ausgang wählt der Nutzer.
- **Zahlungsgrund im Buchungstext** bei Einträgen aus einer Bankzeile (Vorschlag: Gegenpartei + Zahlungsgrund).
- Festlegungen „zu bestätigen“ in F101, F105, F109, F112, F115 und in den Abschnitten „Festlegungen aus der Umsetzung“ der Spezifikationen.
- Erwartete Ergebnisse in TESTFAELLE.md (die Spalte füllt der Verantwortliche).

**Gemerkt, noch nicht bauen (Vorgabe des Verantwortlichen vom 2026-11-18):**
- **Große Dateien und große Vorräte (F117, P28; 2026-11-19: „bauen müssen wir erstmal nicht“).** Zielwerte: 250.000 Umsätze und 1,5 GB je Datei, keine feste Grenze im Arbeitsvorrat. Gesetzt ist nur die Konfiguration, angepasst an die 350-MB-Grenze des Moduls (nginx-Vorlagen `client_max_body_size 350m` statt 32 MB – in 0.7.25; auf der VM von Hand nachzuziehen). Offen: Die 20.000 Umsätze je Datei greifen bei CAMT schon bei rund 100 MB (IMPORT.md G9). Die Grenzen des Moduls `import` (350 MB, 20.000) und des Arbeitsvorrats (5.000) bleiben, bis umgebaut ist → IMPORT.md Abschnitt 18 (G1–G8), ARBEITSVORRAT.md Abschnitt 14 (H1–H8). Die Jahresangaben aus dem Chat passten nach Auskunft des Verantwortlichen nicht – die Dokumente rechnen deshalb nur in Umsätzen und Bytes.
- **Buchungsliste, Spalte „Beleg-Nr.“: die Symbole nach vorne.** „Beleg öffnen“ (Pfeil) und Vorschau (Auge) stehen heute hinter der Belegnummer und damit je Zeile an anderer Stelle. Sie sollen vor der Belegnummer stehen, an festem Platz (passt zu F106). Beim Bauen prüfen, ob dieselbe Reihenfolge auch im Arbeitsvorrat gelten soll.

**Reihenfolge, entschieden vom Verantwortlichen am 2026-11-18:**
1. **Benutzerverwaltung** nach VERWALTUNG.md. **Stufe 1 ist gebaut (0.7.22), die Seite „Mandanten“ des System-Verwalters mit Anlegen, Stilllegen, Export und Löschen ebenfalls (0.7.25, F118).** Als Nächstes der Rest von **Stufe 2**: Zugang mit Link **und** sechsstelligem Code (Abschnitt 6; `kern.registrierung` hat `code_hash`, `fehlversuche`, `ungueltig_am` schon), Seiten „Nutzer und Rollen“, „Module“ (Mandant, Rolle Administrator) und „Mandanten“ (System-Verwalter, ohne Löschen), Administrator einsetzen. Dafür bekommt die Anwendung (`kumero_app`) die Rechte auf `verwaltung.pseudonym_eintragen` und `verwaltung.administrator_vermerken`, die heute nur die Kommandozeile hat. Erst der Vertrag in `openapi/v1.yaml` (Abschnitt 9), dann der Code.
2. **Signierdienst** (F15, F22, F23) – Voraussetzung für den Produktivbetrieb (F59); heute ist nur die Umgebung vorbereitet (`install/schritte/70-signierdienst.sh`), kein Dienst, kein Zeitstempel, kein Siegel-Export. **Vorentscheidungen vom 2026-11-19 (F119):** gesiegelt wird nach Bedarf und auf Anforderung, nicht alle 15 Minuten – schon ein Siegel zum Jahresabschluss genügt; als Signatur PGP oder QES (Signaturkarte, bestätigt Hash und handelnde Person); Ablage im Mandanten. Noch kein Entwurf – als Nächstes `SIGNIERDIENST.md` mit der Frage, ob es zum Start überhaupt einen Dienst mit Systemschlüssel auf dem Server braucht.
- Dazu klargestellt: **Die EÜR ist nur Reporting**, und die **Jahressperre hindert das Buchen nicht** – beides hält den Echtbetrieb nicht auf.

**Sonst nicht gebaut, in etwa dieser Reihenfolge sinnvoll:**
0. **Zurückgestellt (Entscheidung des Verantwortlichen vom 2026-11-18): Modul `nopaper` und der Sidecar** (F89 W13, F111, P26). Nicht bauen und nicht beschreiben, bis der Verantwortliche es wieder aufruft. Die offenen Fragen zu P26 (Pfad, Aufbewahrung der XML, unvollständige Paare) bleiben bis dahin liegen.
1. Kleine Dinge für die nächste 0.7.x: Symbole der Buchungsliste nach vorne (oben „Gemerkt“); Abgleich ohne Fünf-Minuten-Frist, solange Originale als gesendet ausstehen (Vorschlag, noch nicht entschieden).
2. F106 auf den übrigen Seiten: „Datei einlesen“ (Schritte erscheinen dynamisch), Buchungsmaske (Splittabelle, einzelne Knöpfe), Formular „Neu“ der Kontenliste, „Paperless – mein Zugang“, Einrichtung.
3. Blättern (F107) und Spaltenfilter (F91) in den übrigen Listen (Buchungsliste, Konten, Kontoblatt, Geschäftsvorfälle, Regeln).
4. F93 Paperless-Zugang ins Nutzerprofil; Entsperren per Passkey (WebAuthn PRF); Livelog; Rückschreiben nach Paperless (F49).
5. Importer für Quicken / Finanzmanager (Splitbuchungen, Umbuchungen, QIF); X1b Regel aus der Vorauswahl; X2b Schalter brutto/aufteilen; X4 Hinweis über Belegnummer und Betrag; F90.
6. Danach 0.8.0: Zuordnung zur Anlage EÜR (P14: erst wenn das Buchen vollständig beschrieben ist).

## 6. Bekannte Stolpersteine

- **Zwei Datenbanken bei allem, was Nutzer betrifft (seit 0.7.22):** Die Identität liegt im Mandanten (`kern.nutzer` …), die Zuordnung Pseudonym → Mandant in der Verwaltung. Es gibt keine gemeinsame Transaktion – Reihenfolge: **erst die Zuordnung, dann die Identität**; scheitert das Zweite, wird die Zuordnung wieder entfernt. Globale Routen (`/api/v1/anmeldung`, `/ich`, `/registrierung`) laufen im Kernel in einer Transaktion der Verwaltung und schreiben im Mandanten selbst.
- **Sitzungs-Cookie** `__Host-kumero` hat die Form `<kennung>.<geheimnis>` (System-Verwalter: `.<geheimnis>`); `Identitaet::sitzungHash` ist weiter der SHA-256 des Geheimnisses (das Modul `paperless` hängt daran).
- **`mandant:anlegen` braucht drei weitere Angaben** (Vorname, Familienname, E-Mail des ersten Administrators). Der Administrator hat keine Rechte auf Buchungsdaten (F43); wer bucht, wird mit `nutzer:anlegen <mandant> … --rolle Verantwortlicher` angelegt.
- **Tests:** gemeinsame Hilfen in `tests/Hilfe/Zugaenge.php`. Der Test-Authentifikator braucht `nutzerkennung` (das Pseudonym aus `user.id`), sonst fehlt der Anmeldung `userHandle`. Im Browser trägt der virtuelle Authentifikator immer nur einen Passkey (`WebAuthn.clearCredentials` vor dem zweiten), sonst fragt Chrome nach einer Auswahl.
- **Browser-Zwischenspeicher:** Dateien unter `/kern/` haben keine Version im Pfad. Seit 0.7.19 setzen die nginx-Vorlagen `expires -1`; auf der VM ist das von Hand eingetragen. Zeigt der Browser nach einem Update „doesn't provide an export named …“, einmal die Websitedaten löschen. **0.7.22 ändert `/kern/ui/rahmen.js`, `start.js`, `profil.js`.**
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
KUMERO_TEST_PGHOST=/tmp/pgtest KUMERO_TEST_PGUSER=postgres php tests/lauf.php Anwendung   # Anmeldung, zwei Mandanten, Verwalter, Überführen
bash db/tests/ausfuehren.sh                                                               # SQL-Tests, wenn der Kern berührt ist
PLAYWRIGHT=… CHROME=… node tests/browser/einlesen.mjs [Bildverzeichnis]                   # Browserläufe je Seite:
#   import.mjs (Kontenrahmen, zwei Identitäten), buchen.mjs, arbeitsvorrat.mjs, paperless.mjs, einlesen.mjs, mandanten.mjs
```

Der nachgebaute Paperless-Server für Tests ist `tests/Hilfe/paperless_nachbau.php`.
