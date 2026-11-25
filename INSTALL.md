# INSTALL.md – vom Git-Stand zur laufenden VM

Stand: 2026-11-22 · kumero 0.7.76

Diese Datei nennt **alles, was zu tun ist**, um kumero aus dem Repository auf eine **neue** VM zu bringen oder eine
**schon installierte** VM zu aktualisieren. Einzelheiten dazu, was der Installer einrichtet (Paketquellen, Rechte,
Zertifikat), stehen in `install/README.md`.

**Der Weg in einem Satz:** Aus einem Tag im Repository entsteht ein Release-Archiv; der Projektverantwortliche signiert
dessen Prüfsumme; auf der VM wird nur ein signiertes Archiv eingespielt (F73). **Die VM braucht kein Git.**

| Abschnitt | Wo | Wann |
|---|---|---|
| 1 Release aus Git bauen und signieren | Baurechner mit Klon des Repositorys; Rechner mit dem Freigabeschlüssel | für jede Version |
| 2 Neue VM einrichten | frisches Debian 13 | einmal je VM |
| 2a Alles in einem Lauf, auf Wunsch mit Paperless | frisches Debian 13 | einmal je VM |
| 3 Vorhandene VM aktualisieren | installierte VM | für jede Version |
| 4 Einmalige Schritte je Version | installierte VM | nur beim Sprung über die genannte Version |
| 5 Wenn etwas hängt | | |
| 6 Neue Version herausgeben | nur wer Versionen veröffentlicht | je Version |

Nicht geprüft: Abschnitt 2 ist aus den Skripten und der bisherigen Anleitung zusammengestellt; ein vollständiger
Durchlauf auf einem frischen Debian 13 mit dieser Fassung steht aus.

---

## 1. Release aus Git bauen und signieren

Ein Release ist ein Archiv aus genau einem Tag `v<version>` samt Bibliotheken (`vendor/`) und Prüfsumme. Gebaut wird in
einem **eigenen, sauberen Klon** – ohne lokale Änderungen. `dist/` und `vendor/` sind von Git ausgenommen und stören nicht.

### 1.1 Einmalig einrichten

Der Block gilt für einen Baurechner mit **Debian 12 oder 13**, als root. Er richtet die Programme ein, legt den Klon an
und prüft am Ende alles. Er darf wiederholt werden.

```bash
(
  set -euo pipefail

  # Programme: git, unzip, gnupg, curl
  apt-get update
  apt-get install -y git unzip gnupg curl ca-certificates

  # PHP 8.5 für die Kommandozeile aus packages.sury.org (dieselbe Quelle wie auf der VM)
  if ! dpkg-query -W -f='${Status}' debsuryorg-archive-keyring 2>/dev/null | grep -q "install ok installed"; then
    tmp="$(mktemp --suffix=.deb)"
    curl -fsSL -o "$tmp" https://packages.sury.org/debsuryorg-archive-keyring.deb
    dpkg -i "$tmp"
    rm -f "$tmp"
  fi
  . /etc/os-release
  cat > /etc/apt/sources.list.d/php-sury.sources <<QUELLE
Types: deb
URIs: https://packages.sury.org/php/
Suites: ${VERSION_CODENAME}
Components: main
Signed-By: /usr/share/keyrings/debsuryorg-archive-keyring.gpg
QUELLE
  apt-get update
  apt-get install -y php8.5-cli php8.5-pgsql php8.5-mbstring composer

  # Klon anlegen (nur beim ersten Mal)
  [[ -d /root/kumero-release/.git ]] || git clone https://github.com/QCG5OLF5ZU9L2W/kumero /root/kumero-release
  cd /root/kumero-release
  git fetch origin
  git switch release-0.7.0
  git pull --ff-only

  # Prüfen
  echo "── Prüfung"
  php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' || { echo "PHP ist älter als 8.4: $(php -v | head -1)"; exit 1; }
  for e in json openssl pdo_pgsql sodium mbstring; do
    php -m | grep -qix "$e" || { echo "PHP-Erweiterung fehlt: $e"; exit 1; }
  done
  composer --version
  git ls-remote --heads origin release-0.7.0 >/dev/null && echo "GitHub erreichbar"
  echo "Baurechner bereit: $(php -v | head -1)"
)
```

Was der Block **nicht** tut:

- **Freigabeschlüssel.** Signiert wird dort, wo der private Schlüssel liegt. Er liegt nie auf einem Server
  (CLAUDE.md, „Grenzen“); steht der Klon auf einem Server, wird die `.sha256` zum Signieren auf den Rechner mit dem
  Schlüssel geholt und die `.sha256.asc` zurückgelegt. Der öffentliche Teil ist auf der VM unter
  `/etc/kumero/freigabe-schluessel.gpg` hinterlegt (Abschnitt 2.3).
- **Zugriffslimit von GitHub.** `composer install` holt die Bibliotheken direkt aus den Repositorys von Symfony und
  Doctrine (ohne Packagist, F84 Nr. 1). Meldet GitHub dabei ein Limit, einmal ein Token nur für composer hinterlegen
  (`composer config -g github-oauth.github.com <token>`) – das Token gehört weder ins Repository noch in einen Chat.

Auf einem anderen System genügt: `git`, `unzip`, `gnupg`, `composer` 2 und PHP **ab 8.4** für die Kommandozeile mit den
Erweiterungen `json`, `openssl`, `pdo_pgsql`, `sodium`, `mbstring`.

### 1.2 Je Version: bauen

Der Block holt den Stand von GitHub und baut die **höchste veröffentlichte Version**. Er liest nur – GitHub fragt dabei
nach keinem Namen und keinem Passwort.

```bash
(
  set -euo pipefail
  cd /root/kumero-release
  git switch release-0.7.0
  git pull --ff-only
  bash werkzeuge/release-vom-tag.sh
  ls -l dist/
)
```

Das Skript nennt am Ende die gebaute Version. Es bricht ab, wenn der Klon lokale Änderungen hat. Danach steht der Klon
auf dem Tag (ohne Zweig); deshalb beginnt jeder Lauf mit `git switch release-0.7.0`.

### 1.3 Signieren

Die VM spielt nur ein Archiv ein, dessen Prüfsumme mit dem dort hinterlegten Schlüssel signiert ist (2.3). Signiert wird
auf dem Rechner mit dem privaten Schlüssel – er liegt nie auf einem Server. Steht der Klon auf einem Server, die
`.sha256` dorthin holen und die `.sha256.asc` zurücklegen.

Liegt der Schlüssel auf dem Rechner mit dem Klon, signiert dieser Block die zuletzt gebaute Version:

```bash
(
  set -euo pipefail
  cd /root/kumero-release/dist
  summe="$(ls -1 kumero-*.sha256 | sort -V | tail -n 1)"
  gpg --armor --detach-sign "$summe"
  ls -l "${summe%.sha256}".*
)
```

Auf einem anderen Rechner, im Verzeichnis mit der geholten Datei (hier für 0.7.26):
`gpg --armor --detach-sign kumero-0.7.26.sha256`

Ergebnis sind **drei Dateien**, die immer zusammen auf die VM gehen: `kumero-<version>.tar.gz`,
`kumero-<version>.sha256`, `kumero-<version>.sha256.asc`. Signiert wird die Prüfsumme des selbst gebauten Archivs – sie
kann von einem anderswo gebauten Archiv derselben Version abweichen.

---

## 2. Neue VM einrichten

Voraussetzung: **Debian 13 (trixie)**, frisch installiert, mit systemd, Zugang als root und Netz zu den Paketquellen
von Debian, `apt.postgresql.org` und `packages.sury.org`. Andere Systeme lehnt der Installer ab.

### 2.1 Dateien auf die VM bringen

Vom Baurechner nach `/root` der VM kopieren (z. B. mit `scp`):

- `kumero-<version>.tar.gz`, `kumero-<version>.sha256`, `kumero-<version>.sha256.asc`
- den **öffentlichen** Freigabeschlüssel als ASCII-Datei, z. B. `freigabe.asc`
  (`gpg --armor --export <Schlüssel-ID> > freigabe.asc`, auf dem Rechner mit dem Schlüssel)

Der Installer steckt im Archiv. Entpackt wird es nur, um ihn zu starten:

```bash
cd /root
archiv="$(ls -1 kumero-*.tar.gz | sort -V | tail -n 1)"     # die neueste der kopierten Versionen
tar -xzf "$archiv"                                           # → /root/kumero-<version>/
cd "${archiv%.tar.gz}/install"
```

### 2.2 System einrichten

```bash
./install.sh                      # erster Lauf: legt /etc/kumero/install.conf an und richtet das System ein
nano /etc/kumero/install.conf     # Einstellungen, siehe unten
./install.sh                      # erneut: wendet die Einstellungen an
./pruefen.sh                      # prüft den Zielzustand, ändert nichts
```

`install.sh` darf beliebig oft laufen. In `/etc/kumero/install.conf` sind zu setzen:

| Einstellung | Wert |
|---|---|
| `FREIGABE_SCHLUESSEL` | `/root/freigabe.asc` – ohne ihn lässt sich kein Release einspielen |
| `ENTWICKLUNG_TUNNEL` | `ja` für eine Test-VM ohne echtes Zertifikat (Zugang über SSH-Tunnel, `http://localhost:8080`); `nein` für den Betrieb |
| `SERVERNAME` | vollständiger Hostname; für den Betrieb Pflicht |
| `ZERTIFIKAT_QUELLE` | Verzeichnis, in das ein Zertifikat von außen geliefert wird (optional; `install/README.md`, „Zertifikat“) |
| `UPDATES_NEUSTART`, `UPDATES_MAIL` | automatische Updates des Betriebssystems (optional) |

Eingerichtet sind danach: PostgreSQL, PHP-FPM, nginx, die Verzeichnisse `/etc/kumero`, `/var/lib/kumero`,
`/var/log/kumero`, der Dienst `kumero-auftraege` (Mandanten anlegen, exportieren, löschen), `gnupg` und `gpgv`,
automatische Updates. `/var/www/kumero` ist bis zum ersten Release ein Platzhalter.

### 2.3 Freigabeschlüssel prüfen

`install.sh` hinterlegt den öffentlichen Schlüssel unter `/etc/kumero/freigabe-schluessel.gpg` und nennt seinen
Fingerabdruck. **Den Fingerabdruck mit dem eigenen Schlüssel vergleichen** – nur Releases, die mit diesem Schlüssel
signiert sind, lassen sich einspielen.

### 2.4 Erstes Release einspielen

```bash
cd /root
archiv="$(ls -1 kumero-*.tar.gz | sort -V | tail -n 1)"
bash "${archiv%.tar.gz}/install/aktualisieren.sh" "/root/$archiv"
```

Das Skript prüft Signatur und Prüfsumme, zeigt die anstehenden Migrationen, fragt die Versionsnummer als Bestätigung ab,
legt das Release unter `/var/lib/kumero/releases/<version>` ab und schaltet `/var/www/kumero` darauf um. Das entpackte
Verzeichnis `/root/kumero-<version>` wird danach nicht mehr gebraucht.

### 2.5 Verwalter, Mandant, Nutzer, Module

```bash
K="php /var/www/kumero/bin/kumero"
$K verwalter:anlegen "Vorname Name"                                  # System-Verwalter der Installation
$K mandant:anlegen praxis "Praxis" --administrator Vorname Familienname mail@example.org
$K buch:anlegen praxis haupt "Praxis"                                # das erste Buch; --art bilanz, --testbuch
$K nutzer:anlegen praxis Vorname Familienname mail@example.org --rolle Verantwortlicher    # wer bucht

for m in kontenrahmen buchen arbeitsvorrat paperless import siegel auswertungen anlagen; do $K modul:aktivieren $m praxis; done
```

- `verwalter:anlegen`, `mandant:anlegen` und `nutzer:anlegen` geben je einen **Registrierungslink** aus (einmal verwendbar, kurz gültig). Der Link gehört
  nur der Person, für die er ausgestellt ist – nicht in Chats, Tickets oder Mails an Dritte.
- Nutzer gehören zum Mandanten (F114): Der System-Verwalter, der Administrator des Mandanten und wer bucht, sind **drei
  Identitäten mit je eigenem Passkey**. Der Administrator hat keine Rechte auf Buchungsdaten.
- Die Reihenfolge der Module ist die der Abhängigkeiten. Welche gebraucht werden, entscheidet der Betrieb; `paperless`
  setzt ein erreichbares Paperless-ngx ab 3.0 voraus.
- Ein Mandant hat Bücher (F126); jedes ist eine eigene Buchhaltung. Solange es nur eines gibt, meinen die Befehle
  dieses; sonst nennt man es (`--buch`, bei `modul:aktivieren` als drittes Argument). Weitere Bücher legt der
  Administrator in der Oberfläche an (Mandant → Bücher).
- Weitere Mandanten legt der System-Verwalter danach auch in der Oberfläche an (Verwaltung → Mandanten) – ohne Buch;
  das erste Buch legt der Administrator des Mandanten an. Die Module werden je Buch auf der Kommandozeile aktiviert.

### 2.6 Erste Anmeldung

**Test-VM** (`ENTWICKLUNG_TUNNEL=ja`):

```bash
ssh -L 8080:127.0.0.1:8080 root@NAME-DER-VM       # auf dem Arbeitsplatz; Namen der VM einsetzen
# im Browser den Registrierungslink öffnen: http://localhost:8080/app.html#registrierung=…
```

**Betrieb** (`ENTWICKLUNG_TUNNEL=nein`): `SERVERNAME` gesetzt, ein Zertifikat, dem der Browser vertraut, und `adresse` in
`/etc/kumero/kumero.php` auf `https://<SERVERNAME>`. Passkeys hängen am Hostnamen: Wer von Tunnel auf HTTPS wechselt,
registriert neu (`bin/kumero nutzer:link`, `verwalter:link`).

### 2.7 Kontenrahmen

In der Oberfläche als System-Verwalter einlesen und im Mandanten anwenden, oder auf der Kommandozeile
(`install/README.md`, „Kontenrahmen einlesen“). Kontenrahmen-PDFs von DATEV bezieht der Betreiber selbst; sie gehören
nicht ins Repository.

---

## 2a. Alles in einem Lauf: `install/installer.sh`

Seit 0.7.74 (F170) fasst ein Skript Abschnitt 2 zusammen – auf Wunsch mit Paperless-ngx auf derselben Maschine. Auf dem
frischen Debian 13, als root; die eine Datei genügt:

```bash
curl -fsSLO https://raw.githubusercontent.com/QCG5OLF5ZU9L2W/kumero/main/install/installer.sh
bash installer.sh
```

**Zum Ausprobieren (seit 0.7.76, F172):** Die erste Frage heißt „Als Test-Installation einrichten?“ (Vorgabe ja; ohne die
Frage: `bash installer.sh --test`). Dann fragt das Skript nur noch nach Paperless und richtet alles andere selbst ein:
Zugang über SSH-Tunnel, Freigabeschlüssel auf der Maschine, Mandant `test` mit einem **Testbuch**, alle Module und drei
Zugänge mit erfundenen Namen (Administrator, Buchhaltung, System-Verwalter). Am Ende stehen die Zeile für den Tunnel und
je Zugang ein Link. Nicht für echte Daten – das Testbuch wird nie produktiv.

Wer die Frage verneint, wird alles einzeln gefragt; die Tabelle unten gilt dafür. Mitzubringen ist nichts. **Zwei Wege zur Freigabe:**

- **Einfach (seit 0.7.75, F171):** Liegt kein Freigabeschlüssel da, erzeugt das Skript auf Nachfrage einen auf der
  Maschine und fragt vor dem Einspielen, ob damit freigegeben wird. Die Maschine gibt sich ihre Versionen dann selbst
  frei: Wer dort root ist, kann jede Version einspielen. Das steht in `/etc/kumero/selbst-freigegeben` und am Ende jedes
  Laufs.
- **Getrennt:** Der **öffentliche** Teil des eigenen Schlüssels liegt vorher als `/root/freigabe.asc` auf der VM
  (Abschnitt 2.1); signiert wird auf dem Rechner mit dem privaten Schlüssel. Wer woanders gebaut und signiert hat, legt
  die drei Dateien des Releases dazu; sonst baut das Skript selbst.

Vom einfachen zum getrennten Weg: `gpg --armor --export-secret-keys > <Datei>`, die Datei auf den eigenen Rechner holen
und dort einlesen, auf der VM `gpg --delete-secret-keys <Fingerabdruck>`. Danach wartet jedes Update auf die Signatur
von dort.

**Es fragt zuerst alles ab und arbeitet dann durch:**

| Frage | Vorgabe | Was geschieht |
|---|---|---|
| „Test-VM mit Zugang über SSH-Tunnel (ohne Zertifikat)?“ | ja | `ENTWICKLUNG_TUNNEL` in `install.conf`. Bei nein: Hostname von kumero und, wer will, das Verzeichnis für ein geliefertes Zertifikat. |
| „Hier einen Freigabeschlüssel erzeugen?“ | ja | Nur wenn `/root/freigabe.asc` fehlt und kein fertiges Release in `/root` liegt. Bei nein fragt das Skript nach der Datei mit dem öffentlichen Schlüssel. **Eine Datei mit privatem Schlüssel weist es ab.** |
| „Paperless-ngx mit auf diese Maschine installieren?“ | nein | Siehe unten. Im Betrieb fragt es den eigenen Hostnamen von Paperless ab, sonst nur Port und Sprachen der Texterkennung. |
| „System-Verwalter und einen Mandanten mit erstem Buch anlegen?“ | ja | Namen, Kennung, Administrator, Gewinnermittlung; auf Wunsch alle Module einschalten (`modul:alle`). |
| „Von GitHub holen und hier bauen?“ | ja | Nur wenn in `/root` kein Release liegt: Klon nach `/root/kumero-release`, gebaut wird die **höchste Version mit Tag**. |
| „Ist das dein Freigabeschlüssel?“ | nein | Nur beim mitgebrachten Schlüssel: zeigt den Fingerabdruck. Ohne Bestätigung wird nichts eingespielt. |
| „Version … jetzt hier freigeben?“ | ja | Nur beim erzeugten Schlüssel: signiert die Prüfsumme des eben gebauten Archivs. Bei nein wird nichts eingespielt. |
| „Eingabetaste, sobald die Signatur daliegt“ | – | Nur wenn die Signatur fehlt: Das Skript nennt die Befehle für den Rechner mit dem Schlüssel und wartet. `a` bricht ab; ein neuer Lauf macht dort weiter. |

Danach läuft `aktualisieren.sh` wie in 2.4 und fragt die Versionsnummer ab. **Das Skript signiert nie ohne
Nachfrage.** Ein mitgebrachtes Release prüft es gegen den Schlüssel, bevor es daraus `install.sh` startet; beim
Bauen aus GitHub läuft `install.sh` des Tags vor der Signatur – eingespielt wird auch dann nur das signierte Archiv.

Das Skript darf wiederholt werden. Antworten zur Technik stehen in `/etc/kumero/installer.conf` (keine Namen, keine
Geheimnisse). Ist kumero schon installiert, überspringt es System und Release und bietet nur noch Paperless und die erste
Einrichtung an – so kommt Paperless auch nachträglich auf eine vorhandene VM. Updates macht weiter Abschnitt 3a.

### Paperless-ngx auf derselben Maschine

| Was | Wo |
|---|---|
| Docker und Compose | Pakete `docker.io` und `docker-compose` aus Debian; die Abbilder von Paperless, PostgreSQL und Redis kommen aus deren Registern |
| Einrichtung | `/etc/kumero/paperless/compose.yml` und `paperless.env` (nur root; dort Abbild, Port, Adresse, Geheimnisse) |
| Daten | `/var/lib/kumero-paperless/` (`data`, `media`, `consume`, `export`, `pgdata`, `redis`) |
| Erreichbar | nur `127.0.0.1:<Port>` (Vorgabe 8000). Test-VM: `ssh -L 8080:127.0.0.1:8080 -L 8000:127.0.0.1:8000 root@<vm>`. Betrieb: eigener Servername in `/etc/nginx/sites-available/kumero-paperless`, Zertifikat in `/etc/kumero/tls-paperless/` (anfangs selbst signiert) |
| Aufruf | `kumero-paperless <compose-Befehl>`, z. B. `kumero-paperless ps`, `kumero-paperless logs webserver` |
| Sicherung von Paperless | `kumero-paperless-sicherung.timer`, jede Nacht 02:30: `document_exporter` nach `/var/lib/kumero-paperless/export`. **Die Sicherung von kumero ist eine andere** (Abschnitt 3b, `kumero-sicherung.timer`) und enthält Paperless nicht. |
| Erstes Konto | `admin`; das erzeugte Passwort steht in `paperless.env` und wird nicht angezeigt. Nach der ersten Anmeldung ändern |

Von Hand bleibt: in Paperless je Nutzer ein Konto und ein API-Token sowie das Tag für offene Belege anlegen; in kumero
unter „Paperless – mein Zugang“ Adresse, Token und Tag eintragen. Die Adresse ist auf der Test-VM
`http://localhost:8000` (die eine Ausnahme von `https://`), im Betrieb `https://<Name von Paperless>` – sie steht in
jedem Verweis auf einen Beleg (F88) und muss deshalb die sein, unter der auch die Nutzer das Paperless öffnen.

**Updates von Paperless geschehen nie von selbst:** erst wenn kumero die neue Version kennt (PAPERLESS.md, F94), dann
`kumero-paperless pull && kumero-paperless up -d`.

### Offen

- **Kein Lauf auf einem echten Debian 13** – weder für kumero noch für Paperless. Geprüft sind mit
  `bash install/installer-test.sh` nur die Teile, die ohne die Maschine laufen.
- **Paperless 3.x:** Abbild (`ghcr.io/paperless-ngx/paperless-ngx:latest`), Einstellungen, PostgreSQL 17 und Redis 7 im
  Paket sowie der Aufruf von `document_exporter` sind aus der Dokumentation der Vorgänger übernommen und gegen 3.x nicht
  nachgeprüft. Das Skript stellt nach dem Start die Version fest und warnt unter 3.0.
- **Im Betrieb erreicht kumero das Paperless über dessen Hostnamen.** Die VM muss ihn auflösen können und dem Zertifikat
  vertrauen; mit dem selbst signierten Zertifikat des Anfangs schlägt der Verbindungstest fehl.
- **Die Sicherung bleibt auf der Maschine.** Wegbringen muss sie der Betrieb.
- **Größe der VM:** Paperless mit Texterkennung braucht deutlich mehr Speicher als kumero allein; eine Zahl gibt es erst
  nach einem Probelauf.
- **Docker ist eine Abweichung vom Grundsatz des Installers „Pakete nur über apt“:** Die Abbilder kommen nicht von Debian
  und werden nicht von `unattended-upgrades` erfasst.

---

## 3. Vorhandene VM aktualisieren

Die drei Dateien der neuen Version liegen in einem Verzeichnis – nach dem Bau auf derselben Maschine ist das
`/root/kumero-release/dist`, sonst das Verzeichnis, in das sie kopiert wurden. **Vorher Abschnitt 4 lesen:** Gibt es für
eine Version zwischen der installierten und der neuen einmalige Schritte?

```bash
(
  set -euo pipefail
  QUELLE=/root/kumero-release/dist          # Verzeichnis mit .tar.gz, .sha256 und .sha256.asc

  installiert="$(cat /var/lib/kumero/installiert)"
  archiv="$(ls -1 "$QUELLE"/kumero-*.tar.gz | sort -V | tail -n 1)"
  echo "Installiert: $installiert   Neu: $(basename "$archiv")"
  [[ -f "${archiv%.tar.gz}.sha256.asc" ]] || { echo "Abbruch: Die Signatur ${archiv%.tar.gz}.sha256.asc fehlt (Abschnitt 1.3)."; exit 1; }
  bash "/var/lib/kumero/releases/$installiert/install/aktualisieren.sh" "$archiv"
)
```

Das Skript zeigt die anstehenden Migrationen und fragt die Versionsnummer als Bestätigung ab. Ein neues Modul schaltet der Administrator des Mandanten
in der Oberfläche ein (Mandant → Module) – oder je Mandant: `php /var/www/kumero/bin/kumero modul:aktivieren <modul> <mandant>`

- Nicht jede Version wird installiert; Versionen dürfen übersprungen werden. Ein Rückschritt wird abgelehnt.
- `aktualisieren.sh` spielt die Migrationen des Kerns, der Verwaltungsdatenbank und der **schon aktiven** Module ein.
  Ein **neues** Modul aktiviert es nicht – das geht in der Oberfläche unter Mandant → Module.
- Seit 0.7.49 zieht `aktualisieren.sh` danach alle aktiven Module nach (`modul:nachziehen`: Version, neue Rechte, Bausteine der
  Rollen); ein eigenes `modul:aktivieren` nach dem Update ist nicht mehr nötig.
- `aktualisieren.sh` ändert **nichts am System**: keine nginx-Konfiguration, keine systemd-Einheiten, keine Pakete.
  Bringt eine Version dort etwas Neues, steht es in Abschnitt 4. Der andere Weg ist, `install.sh` der neuen Version
  erneut laufen zu lassen (`bash /var/www/kumero/install/install.sh`) – das stellt den Zielzustand der Vorlagen her und
  **überschreibt von Hand geänderte Dateien** unter `/etc/nginx/sites-available/kumero*` und den Einheiten von kumero.
- Nach dem Update im Browser neu laden. Zeigt er „doesn't provide an export named …“, einmal die Websitedaten löschen.
- Vor einem Update mit Migrationen des Kerns ist eine Sicherung sinnvoll:
  `pg_dump -U kumero_owner -Fc kumero_<mandant> > /root/kumero_<mandant>-vor-<neu>.dump` (als root).

---

## 3a. Alles in einem Lauf: `werkzeuge/update-neueste.sh`

Seit 0.7.51 fasst ein Skript die Abschnitte 1.2, 1.3 und 3 zusammen. Als root im lesenden Klon:

```bash
cd /root/kumero-release && git switch release-0.7.0 && git pull --ff-only && bash werkzeuge/update-neueste.sh
```

Es bringt zuerst die Pakete des Systems auf den Stand, holt dann den Stand von GitHub, baut die **höchste veröffentlichte Version**, und fragt an vier Stellen:

| Frage | Vorgabe | Was geschieht |
|---|---|---|
| „n Pakete des Systems lassen sich aktualisieren. Jetzt einspielen?“ | ja | Seit 0.7.67, nur wenn Updates anstehen: `apt-get update`, dann – seit 0.7.68 – immer ein full-upgrade (`apt-get -y dist-upgrade`; Debian, PostgreSQL, PHP). **apt darf dabei Pakete entfernen, wenn ein Update es verlangt.** Scheitert das, endet das Skript, bevor es kumero anfasst. Braucht die Maschine danach einen Neustart, sagt es das am Ende – es startet nie selbst neu. |
| „Jetzt hier signieren?“ | nein | Nur wenn die Signatur fehlt und auf der Maschine ein geheimer Schlüssel liegt. Die Signatur ist die Freigabe (F73) – sie geschieht nie von selbst. Ohne Schlüssel nennt das Skript, was auf dem Rechner mit dem Schlüssel zu tun ist, und endet. |
| „Vorher alle Datenbanken von kumero sichern?“ | ja | `pg_dump` jeder Datenbank nach `/root/kumero-sicherung/<installierte Version>-<Zeit>/`. |
| „Überall die Module einschalten, die noch nie eingeschaltet waren?“ | nein | `bin/kumero modul:alle`: in jedem Buch und am Mandanten, in der Reihenfolge der Abhängigkeiten. **Was jemand ausgeschaltet hat, bleibt aus.** |

Dazwischen läuft `aktualisieren.sh` wie in Abschnitt 3 – es zeigt die Migrationen und fragt die Versionsnummer ab – und
danach `modul:nachziehen`. Ist die neueste Version schon installiert, bleibt nur die Frage nach den Modulen.
Das Skript schreibt nichts nach GitHub und fragt nie nach Zugangsdaten; der Tag muss vorher gesetzt sein (Abschnitt 6).
Nach dem Update ruft es `install/nachziehen.sh` der installierten Version auf (seit 0.7.69): PHP-Einstellungen von kumero und die Zeiten der automatischen Updates kommen auf den Stand der Vorlagen, PHP-FPM wird neu geladen. **Die nginx-Datei schreibt es nicht** – es meldet nur, wenn dort Verbindungen zu PHP-FPM wiederverwendet werden.
**Steht im Zweig schon eine Version ohne Tag, installiert das Skript die höchste mit Tag und sagt das ausdrücklich** („… wartet auf seinen Tag“).
Einmalige Schritte am System (Abschnitt 4) macht es nicht.

---

## 3b. Sichern und Wiederherstellen (seit 0.7.77, F176)

**Was die Sicherung enthält:** jede Datenbank von kumero (Verwaltung, Mandanten, Bücher) und die Konfiguration unter
`/etc/kumero`. **Was sie nicht enthält: Paperless.** Paperless hält seine Daten selbst; gesichert und zurückgespielt wird
es von dem, der es betreibt, mit dessen eigenen Mitteln. In der Sicherung von kumero steht nur, wie kumero Paperless
erreicht.

**Jede Nacht gegen 01:40** schreibt `kumero-sicherung.timer` ein Archiv nach `/var/lib/kumero/sicherungen`; die jüngsten
14 bleiben liegen. Von Hand:

```
bash /var/www/kumero/install/sichern.sh
```

**Verschlüsseln:** Den öffentlichen Teil eines OpenPGP-Schlüssels auf die Maschine legen und in
`/etc/kumero/sicherung.conf` als `EMPFAENGER=` eintragen. Der private Teil bleibt auf dem Rechner, an dem im Ernstfall
entschlüsselt wird – nie auf dieser Maschine. Ohne Empfänger ist das Archiv unverschlüsselt und enthält alle Daten aller
Mandanten; die privaten Schlüssel der Zertifikate sind dann nicht darin.

```
install -m 0644 sicherung-oeffentlich.asc /etc/kumero/sicherung-schluessel.asc
sed -i 's|^EMPFAENGER=.*|EMPFAENGER=/etc/kumero/sicherung-schluessel.asc|' /etc/kumero/sicherung.conf
bash /var/www/kumero/install/sichern.sh
```

**Die Sicherung bleibt auf der Maschine.** Wegbringen muss sie der Betrieb – kumero verschickt nichts.

**Wiederherstellen auf einer leeren Maschine** – die Probe gehört vor die erste produktive Nutzung (F176):

1. Die neue Maschine bekommt **denselben Hostnamen** wie die alte (DNS, Zertifikat). Passkeys hängen an ihm; unter
   einem anderen Namen meldet sich niemand mehr an.
2. kumero in **derselben Version** installieren wie in der Sicherung (Abschnitt 2 oder 2a), **ohne** einen Mandanten
   anzulegen. Beim Installer in einem Lauf: die Fragen nach Mandant und Zugängen verneinen.
3. Archiv auf die Maschine bringen. Ein verschlüsseltes Archiv vorher auf dem Rechner mit dem privaten Schlüssel
   entschlüsseln (`gpg --output kumero-sicherung.tar --decrypt kumero-sicherung-….tar.gpg`) und das `.tar` herüberbringen.
4. Zurückspielen – das Skript prüft alles vorher und fragt einmal nach:

```
bash /var/www/kumero/install/wiederherstellen.sh /root/kumero-sicherung.tar
```

5. Anmelden mit dem gewohnten Passkey; Buchungen, Arbeitsvorrat und Rollen ansehen. Danach erst aktualisieren.

Das Skript weigert sich und ändert nichts bei anderer Version, anderem Hostnamen, falschen Prüfsummen oder wenn auf der
Maschine schon ein Mandant liegt. Den Stand von `/etc/kumero` vor dem Zurückspielen legt es daneben
(`/etc/kumero.vor-wiederherstellung-…`). **Paperless ist danach eigens zurückzuspielen** – bis dahin zeigen die
Belegverweise auf Dokumente, die es dort noch nicht gibt.

---

## 3c. Eigene Domain für einen Mandanten (seit 0.7.79, F177)

Ein Mandant kann unter seinem eigenen Hostnamen laufen. **Die Domain gehört vor die ersten Nutzer:** Passkeys hängen am
Hostnamen – wird er gesetzt, geändert oder entfernt, gilt kein Passkey des Mandanten mehr, und alle Zugänge sind neu
auszustellen. **Es braucht zwei Hände:** root trägt die Domain im Webserver ein, der System-Verwalter in kumero.
kumero ändert seine Webserver-Konfiguration nie.

**1. DNS und Zertifikat** (wer die Maschine betreibt): Der Hostname zeigt auf die Maschine; Zertifikat und Schlüssel
liegen bereit, zum Beispiel unter `/etc/kumero/tls-mandanten/buchhaltung.example.org/`.

**2. Webserver, als root:** In `/etc/nginx/sites-available/kumero` einen weiteren `server`-Block für die Domain anlegen –
eine Kopie des Blocks mit `listen 443 ssl`, darin `server_name` und die beiden Zeilen `ssl_certificate…` für die Domain.
Dazu den Namen im Block mit `listen 80` ergänzen. Dann prüfen und neu laden:

```
nginx -t && systemctl reload nginx
```

Hält ein Proxy davor (Caddy, nginx des Betreibers) die Zertifikate, reicht er den Hostnamen unverändert durch; der nginx
von kumero braucht den Namen trotzdem bei `server_name`. Was unter keinem eingetragenen Namen ankommt, routet der
Auffang-Server am Anfang der Datei nicht. **Installationen von vor 0.7.79 haben diesen Auffang-Server nicht** – die
Vorlage steht in `/var/www/kumero/install/vorlagen/nginx-kumero.conf`; von Hand übernehmen.

**3. kumero, als System-Verwalter:** Verwaltung → Mandanten → Mandant wählen → „Eigene Domain …“. Oder als root:

```
/var/www/kumero/bin/kumero mandant:adresse praxis buchhaltung.example.org
```

**4. Zugänge neu ausstellen** – die Links tragen jetzt die Domain des Mandanten:

```
/var/www/kumero/bin/kumero nutzer:liste praxis
/var/www/kumero/bin/kumero nutzer:link praxis NUTZER-ID
```

Abgleich, welche Namen kumero erwartet: `/var/www/kumero/bin/kumero mandant:adressen`.

Unter der eigenen Domain steht der Mandant nicht mehr in der Adresse (`#/b/haupt/buchen/liste`). Unter der Adresse der
Installation ist er dann nicht mehr erreichbar; dort bleiben der System-Verwalter und Mandanten ohne eigene Domain.
**Wer später gehen oder selbst betreiben will, nimmt mit einer eigenen Domain seine Passkeys und Adressen mit** –
ohne sie sind beim Umzug alle Zugänge neu auszustellen (VERWALTUNG.md 18.7).

---

## 3d. Spielwiese (nur für eine Maschine, die nie etwas anderes wird)

Eine Spielwiese ist kumero offen im Netz, ohne Anmeldung, mit einem erfundenen Betrieb (SPIELWIESE.md). **Sie gehört auf
eine eigene Maschine, die nie echte Daten führt.** Der Weg liegt nur im Repository, nicht im Release.

1. Maschine wie in Abschnitt 2a einrichten – mit öffentlichem Namen und Zertifikat, **ohne** Test-Installation und ohne
   Mandanten.
2. Als root im Klon des Repositorys:
   ```bash
   bash install/spielwiese.sh
   ```
   Der erste Lauf legt `/etc/kumero-spielwiese/spielwiese.conf` an. Dort Betreiber, Impressum, Datenschutz und das
   Finanzministerium des eigenen Landes eintragen; auf Wunsch Adresse und Token eines Spiel-Paperless.
3. Noch einmal `bash install/spielwiese.sh`, zur Bestätigung `SPIELWIESE` eintippen.
4. Im Browser prüfen (Startseite, „Verstanden, weiter“, eine Buchung), dann in Proxmox den Snapshot nehmen und das
   Zurücksetzen alle vier Stunden einplanen.

Das Skript ist **noch nie auf einer echten Maschine gelaufen**. Was es am Webserver ändert: Die Site `kumero` wird ersetzt
(Kopie unter `kumero.vor-spielwiese`); öffentlich antwortet nur noch der Pförtner, kumero hört auf 127.0.0.1.

## 4. Einmalige Schritte je Version

Nur nötig, wenn das Update **über** die genannte Version führt. Auf einer neuen VM (Abschnitt 2) entfallen sie, bis auf
das Aktivieren der Module.

| Ab Version | Schritt |
|---|---|
| 0.7.0 – 0.7.16 | Module je Mandant aktivieren, sobald gebraucht: `buchen` (0.7.0), `arbeitsvorrat` (0.7.3), `paperless` (0.7.4), `import` (0.7.12) |
| 0.7.19 | nginx: Dateien unter `/kern/` nicht zwischenspeichern (`expires -1`). In den Vorlagen enthalten; auf einer älteren VM von Hand oder über `install.sh` |
| 0.7.20 | Nur wenn E-Rechnungen als XML ins DMS sollen: im Paperless das Plugin `paperless-ngx-erechnung` einrichten, dann in kumero unter „Paperless – mein Zugang“ die Belegablage für den Mandanten einschalten |
| 0.7.22 | **Nutzer wandern in den Mandanten.** Vorher: `pg_dump -U kumero_owner -Fc kumero_verwaltung > /root/kumero_verwaltung-vor-0.7.22.dump`. `aktualisieren.sh` überführt den Bestand selbst. Danach braucht der System-Verwalter einen eigenen Passkey: `bin/kumero verwalter:liste`, `bin/kumero verwalter:link <id>`. Notausgang im Mandanten: `nutzer:liste`, `nutzer:link <mandant> <nutzer>` |
| 0.7.25 | Dienst für Aufträge und Export-Verzeichnis, einmalig als root:<br>`install -d -m 0700 -o root -g root /var/lib/kumero/exporte`<br>`install -m 0644 /var/www/kumero/install/vorlagen/kumero-auftraege.service /var/www/kumero/install/vorlagen/kumero-auftraege.timer /etc/systemd/system/`<br>`systemctl daemon-reload && systemctl enable --now kumero-auftraege.timer`<br>nginx: in `/etc/nginx/sites-available/kumero` (und ggf. `kumero-tunnel`) `client_max_body_size 350m;`, dann `nginx -t && systemctl reload nginx` |
| 0.7.26 | `bin/kumero modul:aktivieren siegel <mandant>` je Mandant. Braucht `gpg` und `gpgv` (vom Installer eingerichtet; prüfen mit `command -v gpg gpgv`) |
| 0.7.28 | Kein Schritt. Hinweis: Mit 0.7.26/0.7.27 angeforderte, nie signierte Siegel bleiben als „nie signiert“ stehen; neu anfordern, signieren, **festschreiben** |
| 0.7.30 | **Mandanten bekommen Bücher (F126) – Datenbanken werden umbenannt. Mit 0.7.31 direkt von jedem Stand ab 0.7.22 (0.7.30 selbst verlangt 0.7.29); Stände vor 0.7.22 erst auf 0.7.29 bringen.** Vorher sichern, als root: `install -d -m 0700 /root/vor-buecher && cd /root/vor-buecher && for d in $(psql -X -qtA -U kumero_owner -d postgres -c "SELECT datname FROM pg_database WHERE datname LIKE 'kumero\_%' AND NOT datistemplate"); do pg_dump -U kumero_owner -Fc "$d" > "$d.dump"; done`. `aktualisieren.sh` überführt den Bestand selbst (`system:buecher-ueberfuehren`, wiederholbar): aus `kumero_<mandant>` wird das Buch `kumero_<mandant>__haupt`, die Nutzer ziehen in die neue `kumero_<mandant>`. Die Passkeys gelten weiter; alle melden sich einmal neu an. Danach prüfen: `bin/kumero mandant:liste`, `bin/kumero selbsttest`. Eigene Sicherungsskripte nennen ab jetzt beide Arten von Datenbanken. Ein Zurück gibt es nur über die Sicherung |
| 0.7.36 | Neues Modul, je Buch: `bin/kumero modul:aktivieren anlagen <mandant>`; danach auf der Seite „Anlagen“ zuerst die Einstellungen (Vorsteuerabzug, Anlagekonten). `auswertungen` einmal neu aktivieren, damit der Menüeintrag umzieht: `bin/kumero modul:aktivieren auswertungen <mandant>` |
| 0.7.37 | Keine einmaligen Schritte. Die Module `paperless`, `import` und `arbeitsvorrat` haben neue Versionen; `aktualisieren.sh` zieht sie nach. |
| 0.7.38 | Neues Modul, je Buch: `bin/kumero modul:aktivieren lager <mandant>` (für den Wareneingang aus Belegen auch `arbeitsvorrat`). Danach unter „Bestand“ zuerst Lagerorte und Artikel anlegen; die Zwecke der Entnahme stehen in den Einstellungen. |
| 0.7.39 | Modul `lager` 0.2.0 mit neuem Recht: je Buch einmal `bin/kumero modul:aktivieren lager <mandant>`, damit Rollen das Recht zur Inventur bekommen. Mindest- und Sollbestand stehen am Artikel. |
| 0.7.40 | Modul `lager` 0.3.0: keine einmaligen Schritte. Wer Bestand im Buch führen will, stellt unter „Bestandsliste und Wert“ → „Bewertung und Konten …“ Verfahren, Preise (netto/brutto) und die Konten ein. |
| 0.7.41 | **Rollen des Lagers:** je Buch einmal `bin/kumero modul:aktivieren lager <mandant>`. Danach gibt es die Rollen „Lager“ und „Lagerleitung“ (zuweisen unter „Nutzer und Rollen“); „Erfasser“ verliert die Lager-Rechte. |
| 0.7.42 | Keine einmaligen Schritte. Die Datenbank jedes Mandanten bekommt mit dem Update die Tabellen für Module und Rollen am Mandanten (Migration `0002_module_rollen.sql`); ein Modul am Mandanten bringt diese Version noch nicht mit. |
| 0.7.43 | **Personal:** je Mandant einmal `bin/kumero modul:aktivieren personal <mandant>` (ohne Buch). Danach weist der Administrator unter „Nutzer und Rollen“ die Rollen am Mandanten zu (Mitarbeiter, Vorgesetzter, Personal, Lohn); „Personal“ legt die Personen an und verknüpft sie mit ihrem Nutzer. Vorerst nur mit erfundenen Personen (SCHLUESSEL.md). |
| 0.7.44 | Keine einmaligen Schritte. |
| 0.7.45 | **Personal, Abwesenheiten:** je Mandant einmal `bin/kumero modul:aktivieren personal <mandant>` – spielt die Migration ein (falls `aktualisieren.sh` das nicht schon getan hat), meldet die neuen Rechte an und gibt den Rollen Mitarbeiter, Vorgesetzter, Personal und Lohn ihre neuen Bausteine. Zuweisungen bleiben. Danach unter „Abwesenheiten“ → „Freie Tage …“ die Feiertage eintragen. |
| 0.7.46 | **Personal, Feiertage und Urlaubsregel:** je Mandant einmal `bin/kumero modul:aktivieren personal <mandant>` (Migration, neue Modulversion). Danach unter „Abwesenheiten“ → „Arbeitsorte …“ den Arbeitsort des Mandanten anlegen (Bundesland, Feiertage) – die gesetzlichen Feiertage müssen nicht mehr als „Freie Tage“ eingetragen werden. „Urlaubsregel …“ ansehen: Die Vorgabe (Teilurlaub nach § 5 BUrlG, Übertrag, Verfall am 31.03. nach Bestätigung) gilt ab dem laufenden Jahr. |
| 0.7.47 | **Personal, Lohnvorbereitung:** je Mandant einmal `bin/kumero modul:aktivieren personal <mandant>` (Migration 0004, neue Rechte; die Rollen Personal und Lohn bekommen sie). Wer übergeben soll, braucht die Rolle „Lohn“. |
| 0.7.48 | **Personal, Beurteilungen und Ziele:** je Mandant einmal `bin/kumero modul:aktivieren personal <mandant>` (Migration 0005, neue Rechte für Mitarbeiter, Vorgesetzter und Personal). Danach unter „Beurteilungen und Ziele“ → „Vorlagen …“ die erste Vorlage anlegen. 0.7.48 enthält 0.7.47. |
| 0.7.49 | **Module in der Weboberfläche:** `aktualisieren.sh` übergibt an das Skript der neuen Version (Schritt 4a) und zieht damit die aktiven Module selbst nach. Zur Sicherheit danach einmal `php /var/www/kumero/bin/kumero modul:nachziehen` – es meldet „auf dem Stand“, wenn nichts mehr zu tun ist. Der Dienst `kumero-auftraege` muss laufen (0.7.25), sonst bleiben Aufträge der Seite „Module“ auf „läuft“. |
| 0.7.50 | **Bausteine der Rollen:** nichts von Hand. Nachziehen gibt einer Rolle ab jetzt nichts zurück, was ihr von Hand genommen wurde. |
| 0.7.62 | **Abwesenheiten:** Jahresansicht je Person; Betriebsferien als Zeitraum. Nichts von Hand. |
| 0.7.71 | **Hashliste** des Standes wird mitgeführt (`HASHLISTE`); beim Bau nur verglichen und gemeldet. Nichts von Hand. |
| 0.7.72 | Modul `anlagen` 0.2.0: Gemietetes und Geleastes, Kostenstelle am Anlagegut. Die Migration des Moduls zieht `aktualisieren.sh` nach. Nichts von Hand. |
| 0.7.73 | Modul `lager` 0.5.0: Artikelgruppen. Die Migration des Moduls zieht `aktualisieren.sh` nach. Nichts von Hand. |
| 0.7.70 | **Zugang neu ausstellen** im Dialog der Person. Nichts von Hand. |
| 0.7.77 | **Sichern und Wiederherstellen** (Abschnitt 3b). Die nächtliche Sicherung richtet `install/nachziehen.sh` ein – `update-neueste.sh` ruft es auf. Wer ohne dieses Skript aktualisiert: einmal `bash /var/www/kumero/install/nachziehen.sh`. **Von Hand: den öffentlichen Schlüssel für die Verschlüsselung in `/etc/kumero/sicherung.conf` eintragen und die Sicherungen von der Maschine wegbringen.** |
| 0.7.79 | **Eigene Domain je Mandant** (Abschnitt 3c). Die Migrationen spielt `aktualisieren.sh` ein. **Von Hand, wenn unbekannte Hostnamen schon im Webserver enden sollen:** den Auffang-Server aus `install/vorlagen/nginx-kumero.conf` in `/etc/nginx/sites-available/kumero` übernehmen, `nginx -t && systemctl reload nginx` – kumero ändert die Datei nicht. |
| 0.7.82 | **Zeiterfassung:** „Kommen“ geht auch nach vergessenem „Gehen“. Nichts von Hand. |
| 0.7.83 | Modul `lager` 0.6.0: **Aufgabe der Artikelgruppe**, neue Maske. Die Migration des Moduls zieht `aktualisieren.sh` nach. Nichts von Hand. Vorhandene Artikelgruppen tragen danach „Aufgabe fehlt“, bis sie unter „Bearbeiten …“ nachgetragen ist. |
| 0.7.84 | Modul `lager` 0.6.1: Dialog **„Lagerorte“** als Tabelle mit dem Bestand je Ort. Nichts von Hand. |
| 0.7.86 | Modul `lager` 0.6.3: **Stückliste am Artikel**, Zugang an der Artikelgruppe; im Kern der **Textfilter im Menü jeder Spalte**. Die Migration des Moduls zieht `aktualisieren.sh` nach. Nichts von Hand. |
| 0.7.85 | Modul `lager` 0.6.2: Artikel mit **Seriennummer in Artikelgruppen**. Die Migration des Moduls zieht `aktualisieren.sh` nach. Nichts von Hand. |
| 0.7.87 | Modul `zeit` 0.5.0: **Gehen nachtragen** – nur die fehlende Uhrzeit. Die Migration des Moduls zieht `aktualisieren.sh` nach. Nichts von Hand. |
| 0.7.69 | **Sicherheitsupdates und Härtung:** `update-neueste.sh` zieht es selbst nach (`install/nachziehen.sh`). Wer ohne das Skript aktualisiert: einmal `bash /var/lib/kumero/releases/0.7.69/install/nachziehen.sh`. Von Hand bleibt nur nginx: In `/etc/nginx/sites-available/kumero` darf kein `fastcgi_keep_conn on` und kein `keepalive` zu PHP-FPM stehen – das Skript meldet es, falls doch. |
| 0.7.68 | **`update-neueste.sh`:** Pakete des Systems immer als full-upgrade. Nichts von Hand. |
| 0.7.67 | **`update-neueste.sh`** spielt auf Nachfrage die Pakete des Systems ein und meldet eine Version ohne Tag. Nichts von Hand. |
| 0.7.66 | **Vergütung als Raster** an der Person (Eingruppierung, Leistungs- und Altersstufe). Die Migration übernimmt die Vergütung der Fassungen als Zeilen. Nichts von Hand. |
| 0.7.65 | **Leistungskatalog** als Tabelle mit Suche; Suchfeld beim Eintragen. Nichts von Hand. |
| 0.7.64 | **Auswertung der Leistungen**, Form des AU-Nachweises. Nichts von Hand. |
| 0.7.63 | **Leistungen:** unter Mandant → Module `leistungen` einschalten, die Rolle **„Leistungen“** allen geben, die eintragen sollen, und unter „Leistungen“ → „Katalog …“ die ersten Leistungen mit Preis anlegen. Zuschlagsregeln nach Leistung (Seite „Lohnvorbereitung“) rechnen danach von selbst. |
| 0.7.61 | **Personal:** Vergütung am Vertrag (Gehalt oder Stundenlohn, Urlaubs- und Weihnachtsgeld), Sondervereinbarungen zur Arbeitszeit. Nichts von Hand – die Rollen „Personal“ und „Lohn“ bekommen ihre neuen Bausteine beim Nachziehen. |
| 0.7.60 | **Lager:** „unter Mindestbestand“ zählt auf „Bestand“ nur Verwendbares, wie „Nachbestellen“. Nichts von Hand. |
| 0.7.59 | **Zeiterfassung:** mobiles Arbeiten und Dienstgang gesondert; Formular mit Kommen und Gehen. Nichts von Hand. |
| 0.7.58 | **Bedienung:** Doppelklick öffnet Einzelheiten; Anmerkung je Zeitabschnitt; Aufgaben am Vertrag. Nichts von Hand. |
| 0.7.57 | **Zugang aus dem Personal:** „Personen“ → Bearbeiten → „Zugang anlegen …“ (als Administrator; braucht eine E-Mail unter „Private Angaben“). Rollen danach unter „Nutzer und Rollen“. Nichts von Hand. |
| 0.7.56 | **Kündigungsfrist:** Bestehende Verträge behalten ihren Wortlaut als „individuell“. Wer die gesetzliche oder eine tarifliche Frist will, legt eine neue Fassung an; Tarifverträge vorher unter „Personen“ → „Kündigungsregeln …“. **Die gesetzliche Vorgabe prüfen.** |
| 0.7.55 | **Personalnummern:** Neue Personen bekommen eine zufällige Nummer. Wer schon angelegt ist, behält seine – unter „Personen“ → Bearbeiten den Haken „neue Personalnummer vergeben“ setzen, wenn die alte ersetzt werden soll. **Zeiterfassung:** Kommen und Gehen stehen unter „Meine Zeiten“ – sichtbar mit der Rolle „Zeiterfassung“ und einer verknüpften Person. |
| 0.7.53 | **Zeiterfassung:** nach dem Update unter Mandant → Module `zeit` einschalten (oder im Skript die Frage „alle Module“ mit ja beantworten), dann unter „Nutzer und Rollen“ die Rolle **„Zeiterfassung“** allen geben, die stempeln sollen – sie kommt nicht von selbst zu „Mitarbeiter“. Unter „Zeiten“ → „Regeln …“ die Vorgaben prüfen, unter „Zeitprofil …“ je Person feste Arbeitszeit oder Gleitzeit. Wer stempelt, muss unter „Personen“ mit seinem Zugang verknüpft sein. |
| 0.7.54 | **Oberfläche:** Spalten verschiebbar (F146), Lager vollständig mit Filtern. Nichts von Hand. |
| 0.7.51 | **Update in einem Lauf:** `werkzeuge/update-neueste.sh` (Abschnitt 3a); neuer Befehl `modul:alle`. Nichts von Hand. 0.7.51 enthält 0.7.47 bis 0.7.50. |
| 0.7.52 | **Oberfläche:** Filter und Teiler auf „Bestand“, Menüleiste verschiebbar und ausblendbar (F137). Nichts von Hand. |
| 0.7.35 | Neues Modul, je Buch: `bin/kumero modul:aktivieren auswertungen <mandant>`. Danach steht „Auswertungen“ im Menü; im Browser einmal neu laden |
| 0.7.34 | Kein Schritt in kumero. Soll der Sidecar von nopaper gelesen werden: nopaper auf „erst paperless“ umstellen (dort README), den Abschnitt `kumero` aus dessen Konfiguration nehmen; der Paperless-Zugang des Nutzers in kumero muss das Feld mit dem Sidecar lesen dürfen |
| 0.7.33 | Kein Schritt |
| 0.7.32 | Kein Schritt. Im Browser einmal neu laden |
| 0.7.29 | Kein Schritt. Die Seite „Nutzer und Rollen“ sieht, wer die Rolle Administrator hat; nach dem Update bei „doesn't provide an export named …“ einmal die Websitedaten des Browsers löschen |

Neue Zeilen kommen mit jeder Version dazu, die einen solchen Schritt verlangt; der Abschnitt „Installation“ der Version
in `CHANGELOG.md` nennt dasselbe.

---

## 5. Wenn etwas hängt

| Meldung oder Zeichen | Ursache und Abhilfe |
|---|---|
| `Kein Freigabe-Schlüssel hinterlegt` | `FREIGABE_SCHLUESSEL` in `/etc/kumero/install.conf` setzen, `install.sh` erneut (2.2) |
| Signatur ungültig oder von fremdem Schlüssel | Die `.sha256.asc` gehört nicht zu dieser `.sha256`, oder signiert wurde mit einem anderen Schlüssel als dem hinterlegten. Archiv und Prüfsumme vom selben Bau nehmen (1.3) |
| Prüfsumme stimmt nicht | Archiv unvollständig kopiert oder aus einem anderen Bau |
| `Version … ist bereits installiert` / Rückschritt | nichts zu tun bzw. nicht vorgesehen |
| `composer install` bricht beim Bauen ab | PHP auf dem Baurechner unter 8.4, eine Erweiterung fehlt, oder kein Netz zu github.com (1.1) |
| GitHub fragt nach Name und Passwort | Das geschieht nur beim Schreiben, also beim Setzen eines Tags (Abschnitt 6). Bauen und Installieren lesen nur |
| Das Bauskript baut die alte Version | Der Tag der neuen Version steht noch nicht auf GitHub (Abschnitt 6) |
| Aufträge bleiben auf „wartet auf den Dienst“ | `systemctl status kumero-auftraege.timer`, `journalctl -u kumero-auftraege`; von Hand: `bin/kumero system:auftraege` |
| Seite „Siegel“: „Auf dem Server fehlt das Programm …“ | `gpg` oder `gpgv` fehlt oder ist für php-fpm nicht erreichbar |
| Niemand kommt mehr hinein | `bin/kumero verwalter:link <id>` bzw. `nutzer:link <mandant> <nutzer>` stellen einen neuen Registrierungslink aus |
| Zustand des Systems unklar | `bash /var/www/kumero/install/pruefen.sh` (ändert nichts) |

---

## 6. Neue Version herausgeben (nur wer Versionen veröffentlicht)

Wer nur installiert oder aktualisiert, braucht diesen Abschnitt nicht. Eine Version gibt es, sobald ihr Tag `v<version>`
auf GitHub steht; erst dann baut Abschnitt 1.2 sie. Den Tag setzt der Projektverantwortliche – das ist der einzige
Schritt, der **Schreibzugang** zu GitHub braucht.

```bash
(
  set -euo pipefail
  VERSION=0.7.26      # die neue Version
  HASH=be8caf7        # der Commit, in dem VERSION auf diese Version steht; die Abgabe nennt ihn

  cd /root/kumero-release
  git switch release-0.7.0
  git pull --ff-only
  [[ "$(git show "$HASH:VERSION")" == "$VERSION" ]] || { echo "Abbruch: Im Commit $HASH steht nicht Version $VERSION."; exit 1; }
  git tag -a "v$VERSION" "$HASH" -m "kumero $VERSION"
  git push origin "v$VERSION"
)
```

GitHub nimmt über HTTPS **kein Kontopasswort** an („Password authentication is not supported“). Wege zum Schreibzugang:

- **SSH-Schlüssel** des Rechners bei GitHub hinterlegen (Repository → Settings → Deploy keys, mit Schreibrecht), dann
  einmal: `git -C /root/kumero-release remote set-url --push origin git@github.com:QCG5OLF5ZU9L2W/kumero.git`
- **Zugangstoken** (GitHub → Settings → Developer settings → Fine-grained token, nur dieses Repository,
  „Contents: Read and write“) und bei der Frage nach dem Passwort das Token eingeben. Das Token gehört weder ins
  Repository noch in einen Chat.
- Oder den Tag auf dem Rechner setzen, der schon Schreibzugang hat.

Die Startseite des Repositorys zeigt den Zweig `main`. Er wird von Hand nachgezogen:
`git push origin origin/release-0.7.0:main`.
