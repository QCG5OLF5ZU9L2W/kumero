# kumero – Installer für Debian 13

**Den Ablauf Schritt für Schritt – Release bauen, neue VM, Update – nennt `INSTALL.md` im Wurzelverzeichnis.** Diese Datei beschreibt, was der Installer einrichtet.

Richtet einen **nackten Debian 13 (trixie)** für kumero ein: PostgreSQL, PHP, nginx, Verzeichnisse, die Umgebung für den Signierdienst und vollautomatische Updates.
Der Anwendungscode kommt danach als signiertes Release über `aktualisieren.sh` (F73); `/var/www/kumero` zeigt dann auf das aktive Release.

## Grundsätze

- **Debian-Standard:** Pakete kommen nur über `apt`. Die Konfiguration liegt in den `*.d`-Verzeichnissen, die Debian dafür vorsieht. Dienste laufen über systemd. Debian-eigene Dateien wie `50unattended-upgrades`, `postgresql.conf` oder `php.ini` bleiben unverändert. Die einzige Ausnahme sind markierte Blöcke in `pg_hba.conf` und `pg_ident.conf`.
- **Zwei zusätzliche, signierte Paketquellen** (F54):
  - **PostgreSQL** von `apt.postgresql.org` (PGDG). Eingerichtet wird sie mit dem Skript aus Debians eigenem Paket `postgresql-common`.
  - **PHP** von `packages.sury.org`. Der Schlüssel wird über das Paket `debsuryorg-archive-keyring` gepflegt.
- **Idempotent:** `install.sh` darf beliebig oft laufen. Jeder Lauf stellt denselben Zielzustand her.
- **Updates laufen vollautomatisch** über `unattended-upgrades`, und zwar für Debian, PGDG und sury – zweimal am Tag, das Einspielen (gegen 06:10 und 18:10) je eine halbe Stunde nach dem Holen der Paketlisten (F160). Ein Wechsel der Hauptversion geschieht nie automatisch.

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
# Nutzer gehören zum Mandanten (F114): Ein Mandant entsteht mit seinem ersten Administrator; wer bucht, bekommt
# im Mandanten eine eigene Identität. Jeder Befehl gibt einen Registrierungslink aus (15 Minuten, einmal verwendbar).
php /var/www/kumero/bin/kumero verwalter:anlegen "Vorname Name"                  # System-Verwalter der Installation
php /var/www/kumero/bin/kumero mandant:anlegen praxis "Praxis (Test)" --administrator Vorname Familienname mail@example.org
php /var/www/kumero/bin/kumero nutzer:anlegen praxis Vorname Familienname mail@example.org --rolle Verantwortlicher
php /var/www/kumero/bin/kumero nutzer:liste                                      # wer in welchem Mandanten steht

# auf dem Arbeitsplatz
ssh -L 8080:127.0.0.1:8080 root@<server>
# im Browser den ausgegebenen Registrierungslink öffnen (http://localhost:8080/app.html#registrierung=…)
```

## Kontenrahmen einlesen (Modul `kontenrahmen`)

```bash
K="php /var/www/kumero/bin/kumero"
$K modul:aktivieren kontenrahmen praxis                      # einmal je Mandant
$K modul:aktivieren buchen praxis                            # Buchungsliste und Buchungserfassung (ab 0.7.0), einmal je Mandant
$K modul:aktivieren arbeitsvorrat praxis                     # Arbeitsvorrat (ab 0.7.3), braucht buchen, einmal je Mandant
$K modul:aktivieren paperless praxis                         # Paperless-ngx als Belegarchiv (ab 0.7.4), braucht arbeitsvorrat, einmal je Mandant
$K modul:aktivieren import praxis                            # CSV-Dateien (ab 0.7.12) und E-Rechnungen (ab 0.7.16) einlesen, braucht arbeitsvorrat, einmal je Mandant; nach 0.7.18 erneut (Migration 0002, Zwischenablage); Vorschau braucht poppler-utils
$K modul:aktivieren siegel praxis                            # Siegel auf Anforderung (ab 0.7.26), einmal je Mandant; braucht gpg und gpgv (im Installer enthalten)
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
| 50-webserver | nginx; HTTPS aus `/etc/kumero/tls/` (anfangs „snakeoil“, mit `ZERTIFIKAT_QUELLE` von außen geliefert, `kumero-zertifikat`), Weiterleitung von HTTP, Sicherheits-Header, nur `index.php` ausführbar |
| 60-kumero | `/var/www/kumero` (bis zum ersten Release ein Platzhalter, danach Symlink auf das aktive Release), `/etc/kumero` mit `kumero.php`, `/var/lib/kumero` (0751), `/var/log/kumero`, logrotate |
| 70-signierdienst | Benutzer `kumero-ssm`, GnuPG-Verzeichnis (0700), Gruppe `kumero-ssm-client`, gehärtete systemd-Unit (noch **nicht** aktiviert) |
| 80-updates | `unattended-upgrades` mit Debian, PGDG und sury; optional automatischer Neustart und Bericht per Mail |

## Sicherheitsgrenzen

- PostgreSQL ist **nicht über das Netz** erreichbar.
- Die Anwendung kann sich **nicht** als `kumero_owner` anmelden. `pruefen.sh` testet das.
- Die Rechte von `kumero_app` auf die Tabellen (nur SELECT und EXECUTE, F64) vergeben später die Migrationen.
- Der **Stammschlüssel** (F23) wird nie auf diesem Server erzeugt oder abgelegt.

## Zertifikat

nginx liest das Zertifikat aus `/etc/kumero/tls/` (`fullchain.pem`, `privkey.pem`); bis ein echtes da ist,
liegt dort eine Kopie des Debian-Zertifikats „snakeoil“. Passkeys verlangen ein Zertifikat, dem der
Browser vertraut – eine weggeklickte Warnung reicht nicht.

**Zertifikat von außen (z. B. ACME-Client der OPNsense, Let's Encrypt):** Der Client lädt Zertifikat und
Schlüssel per SFTP in ein Verzeichnis auf dem Server; kumero übernimmt sie selbst.

```bash
# /etc/kumero/install.conf
SERVERNAME=kumero.example.org
ZERTIFIKAT_QUELLE=/root/upload/kumero.example.org
ENTWICKLUNG_TUNNEL=nein
```

`install.sh` richtet dann `kumero-zertifikat` mit einer Path-Unit (reagiert auf jeden Upload) und einem
täglichen Timer ein. Übernommen wird nur ein Zertifikat, das gültig ist, für `SERVERNAME` gilt und zum
Schlüssel passt; danach `nginx -t` und Neuladen, sonst bleibt das alte. Protokoll:
`journalctl -t kumero-zertifikat`. Von Hand: `kumero-zertifikat --pruefen` bzw. `kumero-zertifikat`.

Erkannte Dateinamen: `fullchain.pem|.cer|.crt` oder `cert.*` plus `ca.*`/`chain.pem`; Schlüssel
`privkey.pem`, `key.pem` oder `<name>.key`.

Empfehlung für den SFTP-Zugang der OPNsense: eigener SSH-Schlüssel, in `authorized_keys` auf SFTP
beschränkt, z. B. `restrict,command="internal-sftp" ssh-ed25519 AAAA… opnsense-acme`.

Wechsel von Tunnel auf HTTPS: `adresse` in `/etc/kumero/kumero.php` auf `https://<SERVERNAME>` stellen
(`install.sh` nennt den Befehl), php-fpm neu laden und den Passkey neu registrieren
(`bin/kumero nutzer:link NUTZER-ID`) – Passkeys hängen am Hostnamen.

Alternativ certbot direkt auf dem Server (`python3-certbot-nginx`), dann `ssl_certificate` selbst pflegen.

## Bekannte Punkte zum Prüfen beim ersten echten Lauf

- Die Origin-Namen für unattended-upgrades (`apt.postgresql.org`, `deb.sury.org`) zeigt `apt-cache policy`. `80-updates.sh`, `nachziehen.sh` und `pruefen.sh` prüfen am Probelauf, ob Debian-Security, PGDG und sury wirklich erfasst werden.
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
# oder, im eigenen Klon des Verantwortlichen, für den höchsten Tag von origin (INSTALL.md Abschnitt 1):
werkzeuge/release-vom-tag.sh
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
