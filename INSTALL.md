# INSTALL.md – vom Git-Stand zur laufenden VM

Stand: 2026-11-19 · kumero 0.7.26

Diese Datei nennt **alles, was zu tun ist**, um kumero aus dem Repository auf eine **neue** VM zu bringen oder eine
**schon installierte** VM zu aktualisieren. Einzelheiten dazu, was der Installer einrichtet (Paketquellen, Rechte,
Zertifikat), stehen in `install/README.md`.

**Der Weg in einem Satz:** Aus einem Tag im Repository entsteht ein Release-Archiv; der Projektverantwortliche signiert
dessen Prüfsumme; auf der VM wird nur ein signiertes Archiv eingespielt (F73). **Die VM braucht kein Git.**

| Abschnitt | Wo | Wann |
|---|---|---|
| 1 Release bauen und signieren | Baurechner mit Klon des Repositorys; Rechner mit dem Freigabeschlüssel | für jede Version |
| 2 Neue VM einrichten | frisches Debian 13 | einmal je VM |
| 3 Vorhandene VM aktualisieren | installierte VM | für jede Version |
| 4 Einmalige Schritte je Version | installierte VM | nur beim Sprung über die genannte Version |
| 5 Wenn etwas hängt | | |

Nicht geprüft: Abschnitt 2 ist aus den Skripten und der bisherigen Anleitung zusammengestellt; ein vollständiger
Durchlauf auf einem frischen Debian 13 mit dieser Fassung steht aus.

---

## 1. Release bauen und signieren

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

Was der Block **nicht** tut, weil es nur der Verantwortliche selbst kann:

- **Schreibzugang für Tags.** Nur nötig, wenn der Tag noch nicht auf GitHub steht – sonst holt der Block in 1.2 ihn
  einfach. GitHub nimmt über HTTPS **kein Kontopasswort** an („Password authentication is not supported“). Zwei Wege:
  - **SSH-Schlüssel** des Baurechners bei GitHub hinterlegen (Repository → Settings → Deploy keys, mit Schreibrecht),
    dann einmal: `git -C /root/kumero-release remote set-url --push origin git@github.com:QCG5OLF5ZU9L2W/kumero.git`
  - **Zugangstoken** (GitHub → Settings → Developer settings → Fine-grained token, nur dieses Repository,
    „Contents: Read and write“) und bei der Frage nach dem Passwort das Token eingeben.
  - Oder den Tag wie bisher auf dem Rechner setzen, der schon Schreibzugang hat, und hier nur bauen.
- **Freigabeschlüssel.** Signiert wird dort, wo der private Schlüssel liegt. Er liegt nie auf einem Server
  (CLAUDE.md, „Grenzen“); steht der Klon auf einem Server, wird die `.sha256` zum Signieren auf den Rechner mit dem
  Schlüssel geholt und die `.sha256.asc` zurückgelegt. Der öffentliche Teil ist auf der VM unter
  `/etc/kumero/freigabe-schluessel.gpg` hinterlegt (Abschnitt 2.3).
- **Zugriffslimit von GitHub.** `composer install` holt die Bibliotheken direkt aus den Repositorys von Symfony und
  Doctrine (ohne Packagist, F84 Nr. 1). Meldet GitHub dabei ein Limit, einmal ein Token nur für composer hinterlegen
  (`composer config -g github-oauth.github.com <token>`) – das Token gehört weder ins Repository noch in einen Chat.

Auf einem anderen System genügt: `git`, `unzip`, `gnupg`, `composer` 2 und PHP **ab 8.4** für die Kommandozeile mit den
Erweiterungen `json`, `openssl`, `pdo_pgsql`, `sodium`, `mbstring`.

### 1.2 Je Version

Zwei Werte eintragen, dann den ganzen Block einfügen. Den Hash nennt die Abgabe der Version (der Commit, in dem
`VERSION` auf diese Version steht).

```bash
(
  set -euo pipefail
  VERSION=0.7.26
  HASH=be8caf7

  cd /root/kumero-release
  git switch release-0.7.0
  git pull --ff-only

  [[ "$(git show "$HASH:VERSION")" == "$VERSION" ]] || { echo "Abbruch: Im Commit $HASH steht nicht Version $VERSION."; exit 1; }
  # Tag: steht er schon auf GitHub, wird er nur geholt; sonst gesetzt und hochgeladen (braucht Schreibzugang)
  auf_github="$(git ls-remote origin "refs/tags/v$VERSION^{}" "refs/tags/v$VERSION" | tail -n 1 | cut -f1)"
  if [[ -n "$auf_github" ]]; then
    [[ "$auf_github" == "$(git rev-parse "$HASH^{commit}")" ]] || { echo "Abbruch: v$VERSION zeigt auf GitHub auf einen anderen Commit."; exit 1; }
    git tag -d "v$VERSION" >/dev/null 2>&1 || true
    git fetch origin tag "v$VERSION"
  else
    git rev-parse -q --verify "refs/tags/v$VERSION" >/dev/null || git tag -a "v$VERSION" "$HASH" -m "kumero $VERSION"
    git push origin "v$VERSION"
  fi

  bash werkzeuge/release-vom-tag.sh
  ls -l "dist/kumero-$VERSION.tar.gz" "dist/kumero-$VERSION.sha256"
)
```

Danach signieren – auf dem Rechner mit dem Freigabeschlüssel:

```bash
gpg --armor --detach-sign /root/kumero-release/dist/kumero-0.7.26.sha256
```

- **Erst der Tag, dann das Skript.** `release-vom-tag.sh` nimmt den höchsten Versions-Tag von `origin`; fehlt der
  neue dort, baut es die vorige Version noch einmal.
- Das Skript bricht ab, wenn der Klon lokale Änderungen hat. `release-bauen.sh` prüft zusätzlich: `VERSION` passt
  zum Tag, HEAD ist der Tag, `CHANGELOG.md` hat den Abschnitt.
- Nach dem Bau steht der Klon auf dem Tag (ohne Zweig); der nächste Lauf beginnt deshalb mit `git switch release-0.7.0`.
- **Signiert wird die Prüfsumme des selbst gebauten Archivs** – sie kann von einem anderswo gebauten Archiv derselben
  Version abweichen.
- Ergebnis sind **drei Dateien**, die immer zusammen auf die VM gehen: `kumero-<version>.tar.gz`,
  `kumero-<version>.sha256`, `kumero-<version>.sha256.asc`.

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
tar -xzf kumero-<version>.tar.gz          # → /root/kumero-<version>/
cd kumero-<version>/install
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
bash /root/kumero-<version>/install/aktualisieren.sh /root/kumero-<version>.tar.gz
```

Das Skript prüft Signatur und Prüfsumme, zeigt die anstehenden Migrationen, fragt die Versionsnummer als Bestätigung ab,
legt das Release unter `/var/lib/kumero/releases/<version>` ab und schaltet `/var/www/kumero` darauf um. Das entpackte
Verzeichnis `/root/kumero-<version>` wird danach nicht mehr gebraucht.

### 2.5 Verwalter, Mandant, Nutzer, Module

```bash
K="php /var/www/kumero/bin/kumero"
$K verwalter:anlegen "Vorname Name"                                  # System-Verwalter der Installation
$K mandant:anlegen praxis "Praxis" --administrator Vorname Familienname mail@example.org
$K nutzer:anlegen praxis Vorname Familienname mail@example.org --rolle Verantwortlicher    # wer bucht

for m in kontenrahmen buchen arbeitsvorrat paperless import siegel; do $K modul:aktivieren $m praxis; done
```

- Jeder der ersten drei Befehle gibt einen **Registrierungslink** aus (einmal verwendbar, kurz gültig). Der Link gehört
  nur der Person, für die er ausgestellt ist – nicht in Chats, Tickets oder Mails an Dritte.
- Nutzer gehören zum Mandanten (F114): Der System-Verwalter, der Administrator des Mandanten und wer bucht, sind **drei
  Identitäten mit je eigenem Passkey**. Der Administrator hat keine Rechte auf Buchungsdaten.
- Die Reihenfolge der Module ist die der Abhängigkeiten. Welche gebraucht werden, entscheidet der Betrieb; `paperless`
  setzt ein erreichbares Paperless-ngx ab 3.0 voraus.
- Weitere Mandanten legt der System-Verwalter danach auch in der Oberfläche an (Verwaltung → Mandanten); die Module
  werden je Mandant auf der Kommandozeile aktiviert.

### 2.6 Erste Anmeldung

**Test-VM** (`ENTWICKLUNG_TUNNEL=ja`):

```bash
ssh -L 8080:127.0.0.1:8080 root@<vm>       # auf dem Arbeitsplatz
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

## 3. Vorhandene VM aktualisieren

```bash
# 1. drei Dateien nach /root kopieren: kumero-<neu>.tar.gz, .sha256, .sha256.asc
# 2. welche Version ist installiert?
cat /var/lib/kumero/installiert
# 3. Abschnitt 4 lesen: Gibt es für eine Version zwischen der installierten und der neuen einmalige Schritte?
# 4. einspielen – mit dem Skript der INSTALLIERTEN Version
bash /var/lib/kumero/releases/<installiert>/install/aktualisieren.sh /root/kumero-<neu>.tar.gz
# 5. neue Module aktivieren, falls die Version welche bringt (Abschnitt 4)
php /var/www/kumero/bin/kumero modul:aktivieren <modul> <mandant>
```

- Nicht jede Version wird installiert; Versionen dürfen übersprungen werden. Ein Rückschritt wird abgelehnt.
- `aktualisieren.sh` spielt die Migrationen des Kerns, der Verwaltungsdatenbank und der **schon aktiven** Module ein.
  Ein **neues** Modul aktiviert es nicht.
- `aktualisieren.sh` ändert **nichts am System**: keine nginx-Konfiguration, keine systemd-Einheiten, keine Pakete.
  Bringt eine Version dort etwas Neues, steht es in Abschnitt 4. Der andere Weg ist, `install.sh` der neuen Version
  erneut laufen zu lassen (`bash /var/www/kumero/install/install.sh`) – das stellt den Zielzustand der Vorlagen her und
  **überschreibt von Hand geänderte Dateien** unter `/etc/nginx/sites-available/kumero*` und den Einheiten von kumero.
- Nach dem Update im Browser neu laden. Zeigt er „doesn't provide an export named …“, einmal die Websitedaten löschen.
- Vor einem Update mit Migrationen des Kerns ist eine Sicherung sinnvoll:
  `pg_dump -U kumero_owner -Fc kumero_<mandant> > /root/kumero_<mandant>-vor-<neu>.dump` (als root).

---

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

Neue Zeilen kommen mit jeder Version dazu, die einen solchen Schritt verlangt; der Abschnitt „Installation“ der Version
in `CHANGELOG.md` nennt dasselbe.

---

## 5. Wenn etwas hängt

| Meldung oder Zeichen | Ursache und Abhilfe |
|---|---|
| `Kein Freigabe-Schlüssel hinterlegt` | `FREIGABE_SCHLUESSEL` in `/etc/kumero/install.conf` setzen, `install.sh` erneut (2.2) |
| Signatur ungültig oder von fremdem Schlüssel | Die `.sha256.asc` gehört nicht zu dieser `.sha256`, oder signiert wurde mit einem anderen Schlüssel als dem hinterlegten. Archiv und Prüfsumme vom selben Bau nehmen (1.2) |
| Prüfsumme stimmt nicht | Archiv unvollständig kopiert oder aus einem anderen Bau |
| `Version … ist bereits installiert` / Rückschritt | nichts zu tun bzw. nicht vorgesehen |
| `composer install` bricht beim Bauen ab | PHP auf dem Baurechner unter 8.4, eine Erweiterung fehlt, oder kein Netz zu github.com (1.1) |
| `Password authentication is not supported` beim Push des Tags | GitHub verlangt Token oder SSH-Schlüssel (1.1). Steht der Tag schon auf GitHub, ist kein Push nötig |
| Das Bauskript baut die alte Version | Der neue Tag ist noch nicht auf GitHub (1.2) |
| Aufträge bleiben auf „wartet auf den Dienst“ | `systemctl status kumero-auftraege.timer`, `journalctl -u kumero-auftraege`; von Hand: `bin/kumero system:auftraege` |
| Seite „Siegel“: „Auf dem Server fehlt das Programm …“ | `gpg` oder `gpgv` fehlt oder ist für php-fpm nicht erreichbar |
| Niemand kommt mehr hinein | `bin/kumero verwalter:link <id>` bzw. `nutzer:link <mandant> <nutzer>` stellen einen neuen Registrierungslink aus |
| Zustand des Systems unklar | `bash /var/www/kumero/install/pruefen.sh` (ändert nichts) |
