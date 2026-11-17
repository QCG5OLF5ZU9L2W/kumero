# kumero – Installer für Debian 13

Richtet einen **nackten Debian 13 (trixie)** für kumero ein: PostgreSQL, PHP, nginx, Verzeichnisse, die Umgebung für den Signierdienst und vollautomatische Updates.
Der Anwendungscode kommt danach als signiertes Release über `aktualisieren.sh` (F73); `/var/www/kumero` zeigt dann auf das aktive Release.

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
cd kumero/install
./install.sh             # legt beim ersten Lauf /etc/kumero/install.conf an
nano /etc/kumero/install.conf   # z. B. SERVERNAME setzen
./install.sh             # erneut ausführen, um Änderungen anzuwenden
./pruefen.sh             # Zielzustand prüfen (ändert nichts)
```

**PostgreSQL 19 vor der stabilen Version:** Mit `PG_VORABVERSION=auto` (Standard) nimmt der Installer die Beta- oder RC-Pakete, solange es keine stabile Version gibt. Nach der stabilen Veröffentlichung genügt ein erneuter Lauf von `install.sh`.

## Erste Anmeldung (Test-VM über SSH-Tunnel)

Passkeys brauchen einen Hostnamen mit gültigem Zertifikat – oder `http://localhost`. Für die Test-VM ohne echtes Zertifikat:

```bash
# auf dem Server, einmalig
echo ENTWICKLUNG_TUNNEL=ja >> /etc/kumero/install.conf
./install.sh                                    # legt /etc/kumero/kumero.php an, nginx lauscht auf 127.0.0.1:8080
php /var/www/kumero/bin/kumero nutzer:anlegen "Vorname Name" --verwalter
php /var/www/kumero/bin/kumero mandant:anlegen praxis "Praxis (Test)"
php /var/www/kumero/bin/kumero zugang:gewaehren <nutzer-id> praxis

# auf dem Arbeitsplatz
ssh -L 8080:127.0.0.1:8080 root@<server>
# im Browser den ausgegebenen Registrierungslink öffnen (http://localhost:8080/start.html#registrierung=…)
```

## Kontenrahmen einlesen (Modul `kontenrahmen`)

```bash
K="php /var/www/kumero/bin/kumero"
$K modul:aktivieren kontenrahmen praxis                      # einmal je Mandant
$K kontenrahmen:einlesen /root/SKR81.pdf --name "SKR 81 Praxis"   # Prüfbericht, Vorlage wird gespeichert
$K kontenrahmen:zeigen VORLAGE --offen                       # was noch zu klären ist
$K kontenrahmen:einlesen /root/SKR81.pdf --ergaenzung /root/ergaenzung.csv --name "SKR 81 Praxis"
$K kontenrahmen:anwenden VORLAGE praxis                      # Konten anlegen
```

Ergänzung als CSV: `Nummer;Bezeichnung;Kontoart`, Kontoart `aktiv`, `passiv`, `aufwand`, `ertrag`, leer (Regelwerk) oder `-` (weglassen).

Für den Betrieb `ENTWICKLUNG_TUNNEL=nein`, `SERVERNAME` setzen, ein echtes Zertifikat eintragen und `adresse` in `/etc/kumero/kumero.php` auf `https://<SERVERNAME>` stellen.

## Was eingerichtet wird

| Schritt | Inhalt |
|---|---|
| 10-basis | Grundpakete, deutsche Locale, `needrestart` (startet Dienste nach Updates automatisch neu) |
| 20-paketquellen | PGDG und sury als deb822-Quellen mit `Signed-By` |
| 30-postgresql | Cluster `PG_VERSION/main`, nur Unix-Socket, UTC; Rollen `kumero_owner`, `kumero_app`, `kumero_lesen`; Datenbanken `kumero_verwaltung` und `kumero_vorlage`; Anmeldung **ohne Passwort** per Peer: Systembenutzer `kumero` → `kumero_app`, `root` → `kumero_owner` |
| 40-php | PHP-CLI und PHP-FPM mit den nötigen Erweiterungen; eigener FPM-Pool `kumero` unter dem Benutzer `kumero`; `open_basedir`, keine Umgebungsvariablen |
| 50-webserver | nginx; HTTPS mit dem Debian-Zertifikat „snakeoil“, Weiterleitung von HTTP, Sicherheits-Header, nur `index.php` ausführbar |
| 60-kumero | `/var/www/kumero` (bis zum ersten Release ein Platzhalter, danach Symlink auf das aktive Release), `/etc/kumero` mit `kumero.php`, `/var/lib/kumero` (0751), `/var/log/kumero`, logrotate |
| 70-signierdienst | Benutzer `kumero-ssm`, GnuPG-Verzeichnis (0700), Gruppe `kumero-ssm-client`, gehärtete systemd-Unit (noch **nicht** aktiviert) |
| 80-updates | `unattended-upgrades` mit Debian, PGDG und sury; optional automatischer Neustart und Bericht per Mail |

## Sicherheitsgrenzen

- PostgreSQL ist **nicht über das Netz** erreichbar.
- Die Anwendung kann sich **nicht** als `kumero_owner` anmelden. `pruefen.sh` testet das.
- Die Rechte von `kumero_app` auf die Tabellen (nur SELECT und EXECUTE, F64) vergeben später die Migrationen.
- Der **Stammschlüssel** (F23) wird nie auf diesem Server erzeugt oder abgelegt.

## Zertifikat

Für den Produktivbetrieb ein echtes Zertifikat verwenden, zum Beispiel:

```bash
apt-get install -y python3-certbot-nginx
certbot --nginx -d kumero.example.org
```

certbot verlängert das Zertifikat danach selbst über einen systemd-Timer.

## Bekannte Punkte zum Prüfen beim ersten echten Lauf

- Die Origin-Namen für unattended-upgrades (`apt.postgresql.org`, `deb.sury.org`) zeigt `apt-cache policy`. `80-updates.sh` warnt, wenn PGDG nicht erfasst wird.
- `MemoryDenyWriteExecute=yes` in der Unit des Signierdienstes kann je nach späterer Implementierung angepasst werden müssen, etwa bei einer JIT-Sprache.

## Kern aktualisieren – nur mit Genehmigung (F73)

Der installierte Kern ändert sich **nur** durch ein Release, das der Projektverantwortliche mit seinem OpenPGP-Schlüssel signiert hat. `apt` und `unattended-upgrades` berühren kumero nicht.

**Einmalig: Freigabe-Schlüssel hinterlegen**

```bash
# öffentlichen Schlüssel des Projektverantwortlichen auf den Server kopieren, z. B. /root/freigabe.asc
# in /etc/kumero/install.conf:  FREIGABE_SCHLUESSEL=/root/freigabe.asc
./install.sh
```

**Release bauen** (Entwicklung) und **genehmigen** (Projektverantwortlicher)

```bash
werkzeuge/release-bauen.sh                      # dist/kumero-<v>.tar.gz + .sha256
gpg --armor --detach-sign dist/kumero-<v>.sha256  # → .sha256.asc = Genehmigung
```

**Einspielen** (Server, als root; alle drei Dateien im selben Verzeichnis)

```bash
./aktualisieren.sh /root/kumero-<v>.tar.gz
```

Das Skript lehnt in diesen Fällen ab:
- Die Signatur fehlt, ist ungültig oder stammt von einem fremden Schlüssel.
- Die Prüfsumme stimmt nicht.
- Die Version ist älter als die installierte.

Andernfalls zeigt es die anstehenden Migrationen je Datenbank, fragt nach der Versionsnummer als Bestätigung und vermerkt die Installation in `meta.version`. Die Releases liegen unveränderlich unter `/var/lib/kumero/releases/<v>`.
