# Aequa – Installer für Debian 13

Richtet einen **nackten Debian 13 (trixie)** für Aequa ein: PostgreSQL, PHP, nginx, Verzeichnisse, die Umgebung für den Signierdienst und vollautomatische Updates.
Der Anwendungscode selbst ist **nicht** enthalten. Er wird später ausgeliefert.

## Grundsätze

- **Debian-Standard:** Pakete kommen nur über `apt`. Die Konfiguration liegt in den `*.d`-Verzeichnissen, die Debian dafür vorsieht. Dienste laufen über systemd. Debian-eigene Dateien wie `50unattended-upgrades`, `postgresql.conf` oder `php.ini` bleiben unverändert. Die einzige Ausnahme sind markierte Blöcke in `pg_hba.conf` und `pg_ident.conf`.
- **Zwei zusätzliche, signierte Paketquellen** (F54):
  - **PostgreSQL** von `apt.postgresql.org` (PGDG). Eingerichtet wird sie mit dem Skript aus Debians eigenem Paket `postgresql-common`.
  - **PHP** von `packages.sury.org`. Der Schlüssel wird über das Paket `debsuryorg-archive-keyring` gepflegt.
- **Idempotent:** `install.sh` darf beliebig oft laufen. Jeder Lauf stellt denselben Zielzustand her.
- **Updates laufen vollautomatisch** über `unattended-upgrades`, und zwar für Debian, PGDG und sury. Ein Wechsel der Hauptversion geschieht nie automatisch.

## Ablauf

```bash
# auf dem frischen Debian 13 als root
apt-get install -y git   # oder das Archiv anders auf den Server bringen
cd aequa/install
./install.sh             # legt beim ersten Lauf /etc/aequa/install.conf an
nano /etc/aequa/install.conf   # z. B. SERVERNAME setzen
./install.sh             # erneut ausführen, um Änderungen anzuwenden
./pruefen.sh             # Zielzustand prüfen (ändert nichts)
```

**PostgreSQL 19 vor der stabilen Version:** Solange nur Beta- oder RC-Pakete existieren, `PG_VORABVERSION=ja` setzen. Nach der stabilen Veröffentlichung auf `nein` umstellen und `install.sh` erneut ausführen.

## Was eingerichtet wird

| Schritt | Inhalt |
|---|---|
| 10-basis | Grundpakete, deutsche Locale, `needrestart` (startet Dienste nach Updates automatisch neu) |
| 20-paketquellen | PGDG und sury als deb822-Quellen mit `Signed-By` |
| 30-postgresql | Cluster `PG_VERSION/main`, nur Unix-Socket, UTC; Rollen `aequa_owner`, `aequa_app`, `aequa_lesen`; Datenbanken `aequa_verwaltung` und `aequa_vorlage`; Anmeldung **ohne Passwort** per Peer: Systembenutzer `aequa` → `aequa_app`, `root` → `aequa_owner` |
| 40-php | PHP-CLI und PHP-FPM mit den nötigen Erweiterungen; eigener FPM-Pool `aequa` unter dem Benutzer `aequa`; `open_basedir`, keine Umgebungsvariablen |
| 50-webserver | nginx; HTTPS mit dem Debian-Zertifikat „snakeoil“, Weiterleitung von HTTP, Sicherheits-Header, nur `index.php` ausführbar |
| 60-aequa | `/var/www/aequa` (Code, gehört root), `/etc/aequa`, `/var/lib/aequa`, `/var/log/aequa`, logrotate |
| 70-signierdienst | Benutzer `aequa-ssm`, GnuPG-Verzeichnis (0700), Gruppe `aequa-ssm-client`, gehärtete systemd-Unit (noch **nicht** aktiviert) |
| 80-updates | `unattended-upgrades` mit Debian, PGDG und sury; optional automatischer Neustart und Bericht per Mail |

## Sicherheitsgrenzen

- PostgreSQL ist **nicht über das Netz** erreichbar.
- Die Anwendung kann sich **nicht** als `aequa_owner` anmelden. `pruefen.sh` testet das.
- Die Rechte von `aequa_app` auf die Tabellen (nur SELECT und EXECUTE, F64) vergeben später die Migrationen.
- Der **Stammschlüssel** (F23) wird nie auf diesem Server erzeugt oder abgelegt.

## Zertifikat

Für den Produktivbetrieb ein echtes Zertifikat verwenden, zum Beispiel:

```bash
apt-get install -y python3-certbot-nginx
certbot --nginx -d aequa.example.org
```

certbot verlängert das Zertifikat danach selbst über einen systemd-Timer.

## Bekannte Punkte zum Prüfen beim ersten echten Lauf

- Die Origin-Namen für unattended-upgrades (`apt.postgresql.org`, `deb.sury.org`) zeigt `apt-cache policy`. `80-updates.sh` warnt, wenn PGDG nicht erfasst wird.
- `MemoryDenyWriteExecute=yes` in der Unit des Signierdienstes kann je nach späterer Implementierung angepasst werden müssen, etwa bei einer JIT-Sprache.
