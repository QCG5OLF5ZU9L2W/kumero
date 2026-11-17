# CLAUDE.md – Hinweise für die Arbeit mit Claude an kumero

Maßgeblich ist **AGENTS.md** (Ziel, Leitlinien, alle Entscheidungen F1 ff.). Diese Datei fasst nur
zusammen, wie gearbeitet wird.

## Arbeitsweise
- **Erst definieren, dann bauen.** Neue Schritte werden als Entwurf beschrieben (eigene .md-Datei oder
  Abschnitt), mit nummerierten Entscheidungen. Gebaut wird erst nach Freigabe durch den
  Projektverantwortlichen; die Entscheidung kommt dann als neues F in AGENTS.md.
- **Jeder Schritt ist ein Release** (F73): `VERSION`, `CHANGELOG.md`, Tag `v<version>`,
  `werkzeuge/release-bauen.sh`. Installiert wird nur, was der Projektverantwortliche signiert hat
  (`install/aktualisieren.sh`). Am installierten Kern ändert niemand etwas ohne diese Freigabe.
- Sprache in Code, Doku und Commits: Deutsch.

## Grenzen (nicht verhandelbar)
- Daten nach Art. 9 DSGVO bleiben lokal: keine Cloud, keine externe API, keine Telemetrie.
- Tests nur mit **synthetischen** Daten. Echte Belege, Buchungen und Kontenpläne von Mandanten kommen nie
  ins Repository, in Tests, Fixtures, Issues oder Chats (F75 Nr. 9).
- DATEV-Kontenrahmen-PDFs werden nicht eingecheckt; Tests nutzen nachgebaute Muster-PDFs
  (`werkzeuge/kontenrahmen-muster*.py`).
- Keine Tokens, Schlüssel oder Registrierungslinks in Commits oder Chats. Der private Freigabeschlüssel
  liegt nie auf einem Server.
- Logs enthalten keine Buchungsinhalte.

## Aufbau
| Pfad | Inhalt |
|---|---|
| `db/migrationen/`, `db/verwaltung/` | Kern und Verwaltungsdatenbank (PostgreSQL ≥ 19, F54), `db/migrieren.sh` |
| `db/tests/` | SQL-Tests (`db/tests/ausfuehren.sh`) |
| `src/` | PHP-Anwendung (≥ 8.5), Modul-API `Kumero\Api\V1` |
| `module/<name>/` | Module mit `modul.json`, Migrationen, `src/`, `oeffentlich/` (Seiten) |
| `public/` | Front-Controller, Rahmen der Oberfläche (`app.html`, `kern/`) |
| `openapi/v1.yaml` | REST-Vertrag (erst Vertrag, dann Code, F18) |
| `install/` | Installer für Debian 13, `aktualisieren.sh` |
| `tests/` | PHP-Tests (`php tests/lauf.php`), Browsertests `tests/browser/import.mjs` und `tests/browser/buchen.mjs` |
| `werkzeuge/` | Release bauen, Entwicklungsserver, Muster-PDFs |

Entwürfe und Spezifikationen: ANWENDUNG.md, DATENMODELL.md, KONTENRAHMEN.md, OBERFLAECHE.md,
BUCHUNGSMASKE.md, PROFIL.md, BELEGEINGANG.md, NOPAPER-AUFTRAG.md (Auftrag an das Projekt nopaper), PAPERLESS.md, ONLINEKONTEN.md, LIVELOG.md. Was noch zur Freigabe ansteht, nennt AGENTS.md Abschnitt 5.

## Tests lokal
```bash
composer install                                   # Abhängigkeiten aus den VCS-Quellen in composer.json
# PostgreSQL mit Superuser, Socket z. B. /tmp/pgtest
KUMERO_TEST_PGHOST=/tmp/pgtest KUMERO_TEST_PGUSER=postgres php tests/lauf.php
bash db/tests/ausfuehren.sh                        # SQL-Tests
PLAYWRIGHT=… CHROME=… node tests/browser/import.mjs   # Browsertests mit virtuellem Passkey
PLAYWRIGHT=… CHROME=… node tests/browser/buchen.mjs
```
Testdatenbanken heißen immer `kumero_test*`; nur für sie verzichtet `db/migrieren.sh` auf die Freigabe.
