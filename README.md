# kumero

**Offene, selbst betriebene Buchhaltung für kleine und mittlere Unternehmen:** doppisch, webfähig, mit Ordnungsmäßigkeit nach GoBD im Kern.

> Doppik für KMU.

## Stand

**Version 0.7.79** – im Aufbau, noch nicht für den Produktivbetrieb. Entwickelt wird auf dem Zweig `release-0.7.0`; `main` wird von dort nachgezogen und kann zurückliegen.

**Kern:** Buchungskern in PostgreSQL (unveränderliches Journal, Hash-Ketten, Freigabe, Rechte), Verwaltungsdatenbank, PHP-Anwendung mit Anmeldung per Passkey, REST-API, Weboberfläche mit Menü aus den Modulen. Ein Mandant hat mehrere Bücher, je Buch eine Datenbank; Nutzer gehören zum Mandanten.

**Module je Buch:**

| Modul | Inhalt |
|---|---|
| `kontenrahmen` | Kontenrahmen einlesen (DATEV-PDF, CSV) und anwenden |
| `buchen` | Buchungsliste, Buchungserfassung mit Splitbuchung und Steuer, Kontoblatt, Geschäftsvorfälle |
| `arbeitsvorrat` | Posteingang der Buchhaltung, unabhängig von der Quelle; Stapelvorbelegung, Zusammenführen |
| `import` | Dateien als Quelle des Arbeitsvorrats: CSV, CAMT.053/.054, E-Rechnungen (XRechnung, ZUGFeRD) |
| `paperless` | Paperless-ngx als Belegarchiv: offene Belege, Vorschau, Belegverweis mit Prüfsummen, Ablage |
| `siegel` | Siegel auf Anforderung, Signatur außerhalb (OpenPGP) |
| `auswertungen` | Summen- und Saldenliste, Journal, Kontoblätter, eigene Auswertungen |
| `anlagen` | Anlagenbuchhaltung: AfA linear, GWG, Sammelposten, AfA-Lauf als Entwürfe, Kostenstelle; Gemietetes und Geleastes |
| `lager` | Lager mit Chargen, Seriennummern und Verfallsdaten: Wareneingang aus dem Beleg, Entnahme, Inventur, Bewertung; Artikelgruppen für ersetzbares Verbrauchsmaterial |

**Module am Mandanten:**

| Modul | Inhalt |
|---|---|
| `personal` | Personen, Verträge, Vergütung, Fristen, Abwesenheiten, Urlaubskonto, Lohnvorbereitung, Beurteilungen, Selfservice |
| `zeit` | Zeiterfassung im Browser: Kommen und Gehen, Pausen nach Regel, Gleitzeit, Monatsabschluss |
| `leistungen` | Leistungskatalog und erbrachte Leistungen als Grundlage der Zuschläge |

Was entschieden ist und was zur Freigabe ansteht, steht in [AGENTS.md](AGENTS.md) (Abschnitt 4 und 5); der laufende Stand in [UEBERGABE.md](UEBERGABE.md), jede Version in [CHANGELOG.md](CHANGELOG.md).

## Dokumente

| Dokument | Inhalt |
|---|---|
| [AGENTS.md](AGENTS.md) | Ziel, Leitlinien, Technik und alle getroffenen Entscheidungen (F1 ff.); verbindlich für Menschen und Coding-Agenten |
| [DATENMODELL.md](DATENMODELL.md) | Datenmodell am gebauten Stand: drei Ebenen (Installation, Mandant, Buch), Kern eines Buchs, alle Module (Fassung 4) |
| [ANWENDUNG.md](ANWENDUNG.md) | PHP-Gerüst, Weg einer Anfrage, Anmeldung, Modul-API (Fassung 1, freigegeben) |
| [OBERFLAECHE.md](OBERFLAECHE.md) | Rahmen der Weboberfläche, Menü aus den Modulen (F80) |
| [KONTENRAHMEN.md](KONTENRAHMEN.md) | Modul `kontenrahmen` (F79, F81) |
| [BUCHUNGSMASKE.md](BUCHUNGSMASKE.md) | Buchungsliste und Buchungserfassung, Modul `buchen` (F83) |
| [ARBEITSVORRAT.md](ARBEITSVORRAT.md), [IMPORT.md](IMPORT.md), [PAPERLESS.md](PAPERLESS.md), [BELEGEINGANG.md](BELEGEINGANG.md) | Arbeitsvorrat und seine Quellen: Dateien, Paperless-ngx, Sidecar von nopaper |
| [BUECHER.md](BUECHER.md), [VERWALTUNG.md](VERWALTUNG.md), [PROFIL.md](PROFIL.md) | Mandanten und Bücher, Nutzer, Zugang und Rollen, Module schalten, Nutzerprofil |
| [SIEGEL.md](SIEGEL.md), [CODEINTEGRITAET.md](CODEINTEGRITAET.md) | Siegel der Ketten; Hashliste des Codes (Signatur von außen zurückgestellt) |
| [AUSWERTUNGEN.md](AUSWERTUNGEN.md), [ANLAGEN.md](ANLAGEN.md) | Auswertungen und Anlagenbuchhaltung |
| [LAGER.md](LAGER.md), [KATALOG.md](KATALOG.md) | Warenwirtschaft; Entwürfe: Dokumente an Artikel, Charge und Anlagegut, Artikelkatalog über alle Mandanten |
| [PERSONAL.md](PERSONAL.md), [ZEIT.md](ZEIT.md), [LEISTUNGEN.md](LEISTUNGEN.md) | Personal, Zeiterfassung und Leistungen am Mandanten |
| [DIENSTPLAN.md](DIENSTPLAN.md), [FAKTURA.md](FAKTURA.md), [ONLINEKONTEN.md](ONLINEKONTEN.md), [LIVELOG.md](LIVELOG.md), [SCHLUESSEL.md](SCHLUESSEL.md) | Entwürfe, nicht gebaut: Dienstplan, Faktura, Online-Konten, Livelog, Verschlüsselung |
| [INSTALL.md](INSTALL.md), [UEBERGABE.md](UEBERGABE.md), [CHANGELOG.md](CHANGELOG.md), [TESTFAELLE.md](TESTFAELLE.md) | Installieren und aktualisieren, laufender Stand, Versionen, fachliche Testfälle |
| [openapi/v1.yaml](openapi/v1.yaml) | Vertrag der REST-API (F78) |
| [db/](db/) | Migrationen und Tests des Kerns und der Verwaltungsdatenbank |
| [install/](install/) | Installer für ein frisches Debian 13 (PostgreSQL, PHP, nginx, automatische Updates); `installer.sh` macht alles in einem Lauf, auf Wunsch mit Paperless-ngx – noch nicht auf echter Hardware erprobt |

Grundsatz: **Es wird erst gebaut, wenn der jeweilige Schritt definiert ist.**

## Tests

```bash
# Datenbank (Wegwerf-Cluster mit Superuser)
PGHOST=/tmp/pgtest PGUSER=postgres bash db/tests/ausfuehren.sh
# PHP (WebAuthn und Ende-zu-Ende gegen PostgreSQL)
composer install
KUMERO_TEST_PGHOST=/tmp/pgtest KUMERO_TEST_PGUSER=postgres php tests/lauf.php
```

## Installieren und aktualisieren

Der ganze Weg vom Git-Stand bis zur laufenden oder aktualisierten VM steht in **[INSTALL.md](INSTALL.md)**: Release
bauen und signieren, neue VM einrichten, vorhandene VM aktualisieren. Was der Installer im Einzelnen einrichtet, steht
in `install/README.md`.

## Technik

Linux (Debian 13) · PostgreSQL 19 · PHP 8.5 · nginx · Symfony-Komponenten + Doctrine DBAL · REST/JSON mit OpenAPI · Belege in Paperless-ngx

## Lizenz

GNU Affero General Public License, Version 3 oder später (`AGPL-3.0-or-later`), siehe [LICENSE](LICENSE).
