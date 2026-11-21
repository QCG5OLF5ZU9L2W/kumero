# kumero

**Offene, selbst betriebene Buchhaltung für kleine und mittlere Unternehmen:** doppisch, webfähig, mit Ordnungsmäßigkeit nach GoBD im Kern.

> Doppik für KMU.

## Stand

**Version 0.7.65** – Buchungskern in PostgreSQL (Journal, Ketten, Freigabe, Rechte), Verwaltungsdatenbank, PHP-Anwendung mit Anmeldung per Passkey, REST-API für Stammdaten und Buchungen, Weboberfläche mit Menü aus den Modulen, mehrere Bücher je Mandant (je Buch eine Datenbank). Module: `import` (CSV, CAMT.053/.054 und E-Rechnungen), `kontenrahmen` (Import aus DATEV-PDF und CSV) `buchen` (Buchungsliste, Buchungserfassung, Kontoblatt) `arbeitsvorrat` (Posteingang der Buchhaltung) `paperless` (Paperless-ngx als Belegarchiv) `siegel` (Siegel auf Anforderung, Signatur außerhalb) `auswertungen` (Summen- und Saldenliste, Journal, Kontoblätter, eigene Auswertungen) und `anlagen` (Anlagenbuchhaltung). Nutzerprofil mit Sprache, Betragseingabe, Schriftgröße und Kontrast. Als Nächstes (0.7.x): Livelog, Paperless-Anbindung und Arbeitsvorrat; danach die Zuordnung der Konten zur Anlage EÜR.

| Dokument | Inhalt |
|---|---|
| [AGENTS.md](AGENTS.md) | Ziel, Leitlinien, Technik und alle getroffenen Entscheidungen (F1 ff.); verbindlich für Menschen und Coding-Agenten |
| [DATENMODELL.md](DATENMODELL.md) | Abstraktes Datenmodell des Buchungskerns (Fassung 3, freigegeben) |
| [ANWENDUNG.md](ANWENDUNG.md) | PHP-Gerüst, Weg einer Anfrage, Anmeldung, Modul-API (Fassung 1, freigegeben) |
| [OBERFLAECHE.md](OBERFLAECHE.md) | Rahmen der Weboberfläche, Menü aus den Modulen (F80) |
| [KONTENRAHMEN.md](KONTENRAHMEN.md) | Modul `kontenrahmen` (F79, F81) |
| [BUCHUNGSMASKE.md](BUCHUNGSMASKE.md) | Buchungsliste und Buchungserfassung, Modul `buchen` (F83) |
| [openapi/v1.yaml](openapi/v1.yaml) | Vertrag der REST-API (F78) |
| [db/](db/) | Migrationen und Tests des Kerns und der Verwaltungsdatenbank |
| [install/](install/) | Installer für ein frisches Debian 13 (PostgreSQL, PHP, nginx, automatische Updates) – noch nicht auf echter Hardware erprobt |

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
