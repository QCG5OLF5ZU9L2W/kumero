# kumero

**Offene, selbst betriebene Buchhaltung für kleine und mittlere Unternehmen:** doppisch, webfähig, mit Ordnungsmäßigkeit nach GoBD im Kern.

> Doppik für KMU.

## Stand

**Version 0.7.26** – Buchungskern in PostgreSQL (Journal, Ketten, Freigabe, Rechte), Verwaltungsdatenbank, PHP-Anwendung mit Anmeldung per Passkey, REST-API für Stammdaten und Buchungen, Weboberfläche mit Menü aus den Modulen. Module: `import` (CSV, CAMT.053/.054 und E-Rechnungen), `kontenrahmen` (Import aus DATEV-PDF und CSV) `buchen` (Buchungsliste, Buchungserfassung, Kontoblatt) `arbeitsvorrat` (Posteingang der Buchhaltung) `paperless` (Paperless-ngx als Belegarchiv) und `siegel` (Siegel auf Anforderung, Signatur außerhalb). Nutzerprofil mit Sprache, Betragseingabe, Schriftgröße und Kontrast. Als Nächstes (0.7.x): Livelog, Paperless-Anbindung und Arbeitsvorrat; danach die Zuordnung der Konten zur Anlage EÜR.

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

## Release bauen

Ein Release ist ein Archiv aus genau einem Tag `v<version>` samt Bibliotheken und Prüfsumme (F73). Der
Projektverantwortliche baut es selbst in einem **eigenen, sauberen Klon** – ohne lokale Änderungen – und genehmigt es,
indem er die Prüfsumme signiert. Installiert wird nur ein signiertes Release (`install/README.md`, „Kern aktualisieren“).
`dist/` und `vendor/` sind von Git ausgenommen und stören nicht.

**Einmalig einrichten**

1. **Programme:** `git`, `unzip`, `gnupg`, PHP **ab 8.4** für die Kommandozeile (das Release enthält Symfony 8.1, das
   8.4 verlangt; Ziel ist 8.5 wie auf dem Server) mit den Erweiterungen `json`, `openssl`, `pdo_pgsql`, `sodium`,
   `mbstring`, und `composer` 2. Prüfen: `php -v`, `php -m`, `composer --version`.
2. **Netz zu github.com.** `composer install` holt die Bibliotheken direkt aus den Repositorys von Symfony und
   Doctrine (ohne Packagist, F84 Nr. 1). Wird GitHub dabei ein Zugriffslimit melden, einmal ein GitHub-Token nur für
   composer hinterlegen (`composer config -g github-oauth.github.com …`) – das Token gehört nicht in den Chat.
3. **Klon anlegen:**
   ```
   git clone https://github.com/QCG5OLF5ZU9L2W/kumero /root/kumero-release
   cd /root/kumero-release && git switch release-0.7.0
   ```
4. **Schreibzugang für Tags:** Der Klon (oder der Arbeitsklon) muss Tags nach GitHub pushen dürfen – SSH-Schlüssel
   oder Zugangstoken des Verantwortlichen, wie bisher beim Setzen der Tags.
5. **Freigabeschlüssel:** Signiert wird dort, wo der private Schlüssel liegt. Er liegt nie auf einem Server
   (CLAUDE.md, „Grenzen“); steht der Klon auf einem Server, wird die `.sha256` zum Signieren auf den Rechner mit dem Schlüssel
   geholt. Der öffentliche Teil ist auf dem Zielserver unter `/etc/kumero/freigabe-schluessel.gpg` hinterlegt
   (install/README.md, „Freigabe-Schlüssel hinterlegen“).

**Je Version**

```
cd /root/kumero-release
git switch release-0.7.0 && git pull --ff-only     # Arbeitszweig
git tag -a v<version> <hash> -m 'kumero <version>'  # Commit, in dem VERSION auf <version> steht
git push origin v<version>
bash werkzeuge/release-vom-tag.sh                   # → dist/kumero-<version>.tar.gz und .sha256
gpg --armor --detach-sign dist/kumero-<version>.sha256
```

- **Erst der Tag, dann das Skript.** `release-vom-tag.sh` nimmt den höchsten Versions-Tag von `origin`; fehlt der
  neue dort, baut es die vorige Version noch einmal.
- Das Skript bricht ab, wenn der Klon lokale Änderungen hat. `release-bauen.sh` prüft zusätzlich: `VERSION` passt
  zum Tag, HEAD ist der Tag, `CHANGELOG.md` hat den Abschnitt.
- Nach dem Bau steht der Klon auf dem Tag (ohne Zweig); der nächste Lauf beginnt deshalb mit `git switch release-0.7.0`.
- Archiv, `.sha256` und `.sha256.asc` gehen zusammen auf den Server und werden dort mit `install/aktualisieren.sh` eingespielt. **Signiert wird die Prüfsumme
  des selbst gebauten Archivs** – sie kann von einem anderswo gebauten Archiv derselben Version abweichen.

## Technik

Linux (Debian 13) · PostgreSQL 19 · PHP 8.5 · nginx · Symfony-Komponenten + Doctrine DBAL · REST/JSON mit OpenAPI · Belege in Paperless-ngx

## Lizenz

GNU Affero General Public License, Version 3 oder später (`AGPL-3.0-or-later`), siehe [LICENSE](LICENSE).
