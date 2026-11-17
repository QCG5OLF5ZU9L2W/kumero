# kumero

**Offene, selbst betriebene Buchhaltung für kleine und mittlere Unternehmen:** doppisch, webfähig, mit Ordnungsmäßigkeit nach GoBD im Kern.

> Doppik für KMU.

## Stand

**Version 0.5.0** – Buchungskern in PostgreSQL (Journal, Ketten, Freigabe, Rechte), Verwaltungsdatenbank, PHP-Anwendung mit Anmeldung per Passkey, REST-API für Stammdaten und eine erste Testseite. Buchungen über die REST-API, das Modul `kontenrahmen` und die eigentliche Oberfläche folgen (ANWENDUNG.md E8).

| Dokument | Inhalt |
|---|---|
| [AGENTS.md](AGENTS.md) | Ziel, Leitlinien, Technik und alle getroffenen Entscheidungen (F1 ff.); verbindlich für Menschen und Coding-Agenten |
| [DATENMODELL.md](DATENMODELL.md) | Abstraktes Datenmodell des Buchungskerns (Fassung 3, freigegeben) |
| [ANWENDUNG.md](ANWENDUNG.md) | PHP-Gerüst, Weg einer Anfrage, Anmeldung, Modul-API (Fassung 1, freigegeben) |
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

## Technik

Linux (Debian 13) · PostgreSQL 19 · PHP 8.5 · nginx · Symfony-Komponenten + Doctrine DBAL · REST/JSON mit OpenAPI · Belege in Paperless-ngx

## Lizenz

GNU Affero General Public License, Version 3 oder später (`AGPL-3.0-or-later`), siehe [LICENSE](LICENSE).
