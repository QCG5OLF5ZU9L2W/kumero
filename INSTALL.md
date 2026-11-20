# INSTALL.md – vom Git-Stand zur laufenden VM

Stand: 2026-11-19 · kumero 0.7.26

Diese Datei nennt **alles, was zu tun ist**, um kumero aus dem Repository auf eine **neue** VM zu bringen oder eine
**schon installierte** VM zu aktualisieren. Einzelheiten dazu, was der Installer einrichtet (Paketquellen, Rechte,
Zertifikat), stehen in `install/README.md`.

**Der Weg in einem Satz:** Aus einem Tag im Repository entsteht ein Release-Archiv; der Projektverantwortliche signiert
dessen Prüfsumme; auf der VM wird nur ein signiertes Archiv eingespielt (F73). **Die VM braucht kein Git.**

| Abschnitt | Wo | Wann |
|---|---|---|
| 1 Release aus Git bauen und signieren | Baurechner mit Klon des Repositorys; Rechner mit dem Freigabeschlüssel | für jede Version |
| 2 Neue VM einrichten | frisches Debian 13 | einmal je VM |
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

Das Skript zeigt die anstehenden Migrationen und fragt die Versionsnummer als Bestätigung ab. Bringt die Version ein
neues Modul (Abschnitt 4), danach je Mandant: `php /var/www/kumero/bin/kumero modul:aktivieren <modul> <mandant>`

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
| 0.7.28 | Kein Schritt. Hinweis: Mit 0.7.26/0.7.27 angeforderte, nie signierte Siegel bleiben als „nie signiert“ stehen; neu anfordern, signieren, **festschreiben** |
| 0.7.30 | **Mandanten bekommen Bücher (F126) – Datenbanken werden umbenannt. Mit 0.7.31 direkt von jedem Stand ab 0.7.22 (0.7.30 selbst verlangt 0.7.29); Stände vor 0.7.22 erst auf 0.7.29 bringen.** Vorher sichern, als root: `install -d -m 0700 /root/vor-buecher && cd /root/vor-buecher && for d in $(psql -X -qtA -U kumero_owner -d postgres -c "SELECT datname FROM pg_database WHERE datname LIKE 'kumero\_%' AND NOT datistemplate"); do pg_dump -U kumero_owner -Fc "$d" > "$d.dump"; done`. `aktualisieren.sh` überführt den Bestand selbst (`system:buecher-ueberfuehren`, wiederholbar): aus `kumero_<mandant>` wird das Buch `kumero_<mandant>__haupt`, die Nutzer ziehen in die neue `kumero_<mandant>`. Die Passkeys gelten weiter; alle melden sich einmal neu an. Danach prüfen: `bin/kumero mandant:liste`, `bin/kumero selbsttest`. Eigene Sicherungsskripte nennen ab jetzt beide Arten von Datenbanken. Ein Zurück gibt es nur über die Sicherung |
| 0.7.36 | Neues Modul, je Buch: `bin/kumero modul:aktivieren anlagen <mandant>`; danach auf der Seite „Anlagen“ zuerst die Einstellungen (Vorsteuerabzug, Anlagekonten). `auswertungen` einmal neu aktivieren, damit der Menüeintrag umzieht: `bin/kumero modul:aktivieren auswertungen <mandant>` |
| 0.7.37 | Keine einmaligen Schritte. Die Module `paperless`, `import` und `arbeitsvorrat` haben neue Versionen; `aktualisieren.sh` zieht sie nach. |
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
