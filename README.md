# Aequa

**Offene, selbst betriebene Buchhaltung für kleine und mittlere Unternehmen:** doppisch, webfähig, mit Ordnungsmäßigkeit nach GoBD im Kern.

> *aequus* (lat.) = gleich: Soll = Haben.

## Stand

Das Projekt ist in der **Definitionsphase**. Es gibt noch keinen Anwendungscode.

| Dokument | Inhalt |
|---|---|
| [AGENTS.md](AGENTS.md) | Ziel, Leitlinien, Technik und alle getroffenen Entscheidungen (F1 ff.); verbindlich für Menschen und Coding-Agenten |
| [DATENMODELL.md](DATENMODELL.md) | Abstraktes Datenmodell des Buchungskerns (Fassung 3, freigegeben) |
| [install/](install/) | Installer für ein frisches Debian 13 (PostgreSQL, PHP, nginx, automatische Updates) – noch nicht auf echter Hardware erprobt |

Grundsatz: **Es wird erst gebaut, wenn der jeweilige Schritt definiert ist.**

## Technik

Linux (Debian 13) · PostgreSQL 19 · PHP 8.5 · nginx · Symfony-Komponenten + Doctrine DBAL · REST/JSON mit OpenAPI · Belege in Paperless-ngx

## Lizenz

GNU Affero General Public License, Version 3 oder später (`AGPL-3.0-or-later`), siehe [LICENSE](LICENSE).
