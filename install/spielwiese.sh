#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# kumero-Spielwiese einrichten (SPIELWIESE.md; F183–F186): kumero offen und ohne Anmeldung zum Ausprobieren.
#
#   NUR FÜR EINE MASCHINE, DIE NIE ETWAS ANDERES WIRD. Nach diesem Skript kann jeder, der die Adresse kennt,
#   ohne Anmeldung alles in kumero tun. Es gibt keinen Weg zurück außer einer neuen Maschine.
#
# Getrennt vom Installer des Betriebs (SW15, SW16): installer.sh kennt dieses Skript nicht, und nichts hiervon liegt
# in einem Release. An kumero wird nichts geändert (SW17) – davor kommt ein Pförtner (install/spielwiese/).
#
# Voraussetzung: Debian 13, kumero mit install/installer.sh frisch installiert, noch KEIN Mandant.
# Aufruf als root aus einem Klon des Repositorys:   bash install/spielwiese.sh
# Erster Lauf legt /etc/kumero-spielwiese/spielwiese.conf an; ausfüllen, dann erneut starten.
set -euo pipefail

QUELLE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/spielwiese"
KUMERO_VERZ="${KUMERO_VERZ:-/var/www/kumero}"
KUMERO_ETC="${KUMERO_ETC:-/etc/kumero}"
SW_ETC="${SPIELWIESE_ETC:-/etc/kumero-spielwiese}"
SW_VERZ="${SPIELWIESE_VERZ:-/opt/kumero-spielwiese}"
SW_DATEN="${SPIELWIESE_DATEN:-/var/lib/kumero-spielwiese}"
NGINX_SITES="${SPIELWIESE_NGINX:-/etc/nginx}"
FPM_ETC="${SPIELWIESE_FPM:-/etc/php}"
NUTZER=kumero-spielwiese

melde()   { printf '\033[1;34m[spielwiese]\033[0m %s\n' "$*"; }
warne()   { printf '\033[1;33m[spielwiese] ACHTUNG:\033[0m %s\n' "$*" >&2; }
abbruch() { printf '\033[1;31m[spielwiese] ABBRUCH:\033[0m %s\n' "$*" >&2; exit 1; }
kumero()  { php "$KUMERO_VERZ/bin/kumero" "$@"; }

# Wert als PHP-Zeichenkette in einfachen Anführungszeichen
php_wert() { printf "'%s'" "$(printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e "s/'/\\\\'/g")"; }

adresse_lesen() {
    php -r '$k = require $argv[1]; echo $k["adresse"] ?? "";' "$KUMERO_ETC/kumero.php"
}

php_version() {
    sed -n 's#.*unix:/run/php/php\([0-9.]*\)-fpm-kumero\.sock.*#\1#p' "$NGINX_SITES/sites-available/kumero" | head -n 1
}

vorab_pruefen() {
    [[ $EUID -eq 0 ]] || abbruch "Bitte als root starten."
    [[ -f "$KUMERO_VERZ/bin/kumero" && -f "$KUMERO_ETC/kumero.php" ]] || abbruch "kumero ist hier nicht installiert – erst install/installer.sh."
    [[ -d "$QUELLE" ]] || abbruch "Das Verzeichnis install/spielwiese fehlt – das Skript läuft aus einem Klon des Repositorys, nicht aus einem Release."
    local mandanten; mandanten="$(kumero mandant:liste 2>/dev/null | grep -c . || true)"
    [[ "$mandanten" == 0 ]] || abbruch "Auf dieser Maschine gibt es schon Mandanten. Eine Spielwiese entsteht nur auf einer frischen Maschine – nie auf einer, die Daten führt."
    [[ "$(adresse_lesen)" == https://* ]] || abbruch "kumero braucht hier eine öffentliche https-Adresse (adresse in $KUMERO_ETC/kumero.php) – nicht den Tunnel der Test-Installation."
    [[ -n "$(php_version)" ]] || abbruch "Die nginx-Site von kumero ist nicht die erwartete ($NGINX_SITES/sites-available/kumero)."
}

konf_laden() {
    if [[ ! -f "$SW_ETC/spielwiese.conf" ]]; then
        install -d -m 0750 "$SW_ETC"
        install -m 0640 "$QUELLE/spielwiese.conf" "$SW_ETC/spielwiese.conf"
        melde "Angelegt: $SW_ETC/spielwiese.conf – bitte ausfüllen (Betreiber, Impressum, Datenschutz) und das Skript erneut starten."
        exit 0
    fi
    BETREIBER=""; IMPRESSUM=""; DATENSCHUTZ=""; LAND=""; QUELLCODE=""; RUECKSETZEN=""
    PAPERLESS_ADRESSE=""; PAPERLESS_TOKEN=""; PAPERLESS_TAG_OFFEN=""; FIREWALL="ja"; AUSGEHEND_ERLAUBT=""; INTERN_PORT="8081"
    # shellcheck disable=SC1091
    source "$SW_ETC/spielwiese.conf"
    [[ -n "$BETREIBER" ]] || abbruch "BETREIBER fehlt in $SW_ETC/spielwiese.conf."
    [[ "$IMPRESSUM" == https://* && "$DATENSCHUTZ" == https://* ]] || abbruch "IMPRESSUM und DATENSCHUTZ müssen https-Adressen sein."
    [[ "$INTERN_PORT" =~ ^[0-9]{4,5}$ ]] || abbruch "INTERN_PORT ist keine Portnummer."
    [[ -z "$PAPERLESS_TOKEN" || "$PAPERLESS_TOKEN" =~ ^[A-Za-z0-9_.-]{8,200}$ ]] || abbruch "PAPERLESS_TOKEN hat nicht die Form eines Tokens."
}

bestaetigen() {
    [[ "${1:-}" == "--ja" ]] && return 0
    warne "Diese Maschine wird zur Spielwiese: $(adresse_lesen) ist danach für jeden ohne Anmeldung bedienbar."
    warne "Sie darf nie echte Daten führen und wird nie etwas anderes."
    local antwort; read -r -p "Zum Fortfahren SPIELWIESE eintippen: " antwort
    [[ "$antwort" == "SPIELWIESE" ]] || abbruch "Nicht bestätigt."
}

# Schreibt die Konfiguration des Pförtners. $1 = Passphrase für Paperless (leer = ohne)
pfoertner_konf() {
    local adresse; adresse="$(adresse_lesen)"
    {
        echo "<?php"
        echo "// Von install/spielwiese.sh geschrieben. Enthält die Passphrase des Spiel-Paperless – sonst kein Geheimnis."
        echo "return ["
        echo "    'adresse' => $(php_wert "$adresse"),"
        echo "    'intern' => 'http://127.0.0.1:$INTERN_PORT',"
        echo "    'passkey' => $(php_wert "$SW_DATEN/passkey.json"),"
        echo "    'mandant' => 'demo', 'mandant_name' => 'Demo', 'buch' => 'haupt',"
        echo "    'app' => $(php_wert "$KUMERO_VERZ/public/app.html"),"
        echo "    'paperless_passphrase' => $(php_wert "$1"),"
        echo "    'paperless' => ['adresse' => $(php_wert "$PAPERLESS_ADRESSE"), 'token' => $(php_wert "$PAPERLESS_TOKEN"), 'tag_offen' => $(php_wert "$PAPERLESS_TAG_OFFEN")],"
        echo "    'texte' => ['betreiber' => $(php_wert "$BETREIBER"), 'impressum' => $(php_wert "$IMPRESSUM"), 'datenschutz' => $(php_wert "$DATENSCHUTZ"),"
        echo "                'land' => $(php_wert "$LAND"), 'quellcode' => $(php_wert "$QUELLCODE"), 'ruecksetzen' => $(php_wert "$RUECKSETZEN")],"
        echo "];"
    } > "$SW_ETC/pfoertner.php"
    php -l "$SW_ETC/pfoertner.php" >/dev/null || abbruch "Die Konfiguration des Pförtners ist kein gültiges PHP."
}

vorlage() {   # $1 = Datei unter install/spielwiese
    local host; host="$(adresse_lesen)"; host="${host#https://}"; host="${host%%[:/]*}"
    sed -e "s|@SERVERNAME@|$host|g" -e "s|@KUMERO_VERZ@|$KUMERO_VERZ|g" -e "s|@PFOERTNER_VERZ@|$SW_VERZ|g" \
        -e "s|@PHP_VERSION@|$(php_version)|g" -e "s|@INTERN_PORT@|$INTERN_PORT|g" "$QUELLE/$1"
}

dateien_einrichten() {
    id "$NUTZER" >/dev/null 2>&1 || useradd --system --home-dir /nonexistent --shell /usr/sbin/nologin "$NUTZER"
    install -d -m 0755 "$SW_VERZ"
    install -m 0644 "$QUELLE/lib.php" "$QUELLE/pfoertner.php" "$QUELLE/einrichten.php" "$QUELLE/bestand.php" "$QUELLE/seite.css" "$SW_VERZ/"
    install -d -m 0750 -o root -g "$NUTZER" "$SW_ETC"
    install -d -m 0700 -o root -g root "$SW_DATEN"
}

webserver_einrichten() {
    local v; v="$(php_version)"
    vorlage php-fpm-spielwiese.conf > "$FPM_ETC/$v/fpm/pool.d/kumero-spielwiese.conf"
    cp -a "$NGINX_SITES/sites-available/kumero" "$NGINX_SITES/sites-available/kumero.vor-spielwiese" 2>/dev/null || true
    vorlage nginx-spielwiese.conf > "$NGINX_SITES/sites-available/kumero"
    rm -f "$NGINX_SITES/sites-enabled/kumero-tunnel"
    nginx -t
    systemctl restart "php$v-fpm"
    systemctl reload nginx
}

firewall_setzen() {
    [[ "$FIREWALL" == "ja" ]] || { warne "Firewall nicht gesetzt (FIREWALL=$FIREWALL) – ausgehende Verbindungen sind offen."; return 0; }
    command -v ufw >/dev/null || apt-get install -y -q ufw
    ufw --force reset >/dev/null
    ufw default deny incoming
    ufw default deny outgoing
    ufw allow in 22/tcp
    ufw allow in 80/tcp
    ufw allow in 443/tcp
    ufw allow out 53
    ufw allow out 123/udp
    local ziel
    for ziel in $AUSGEHEND_ERLAUBT; do
        [[ "$ziel" =~ ^[0-9a-fA-F:./]+$ ]] || abbruch "AUSGEHEND_ERLAUBT: „$ziel“ ist keine Adresse."
        ufw allow out to "$ziel"
    done
    ufw --force enable
}

main() {
    vorab_pruefen
    konf_laden
    bestaetigen "${1:-}"
    local passphrase=""
    [[ -z "$PAPERLESS_ADRESSE" ]] || passphrase="$(head -c 24 /dev/urandom | base64 | tr -d '/+=' | head -c 28)"
    melde "Pförtner nach $SW_VERZ"
    dateien_einrichten
    pfoertner_konf "$passphrase"
    chown root:"$NUTZER" "$SW_ETC/pfoertner.php"; chmod 0640 "$SW_ETC/pfoertner.php"
    melde "Webserver: öffentlich der Pförtner, kumero nur noch auf 127.0.0.1:$INTERN_PORT"
    webserver_einrichten
    melde "Mandant, Spiel-Identität, Kontenrahmen und Bestand"
    SPIELWIESE_KONFIG="$SW_ETC/pfoertner.php" SPIELWIESE_KUMERO="php $KUMERO_VERZ/bin/kumero" \
        SPIELWIESE_KONTENRAHMEN="$KUMERO_VERZ/module/kontenrahmen/referenz/euer-kontenrahmen.csv" php "$SW_VERZ/einrichten.php" alles
    chown "$NUTZER":"$NUTZER" "$SW_DATEN" "$SW_DATEN/passkey.json"; chmod 0700 "$SW_DATEN"; chmod 0600 "$SW_DATEN/passkey.json"
    rm -f "$SW_VERZ/einrichten.php" "$SW_VERZ/bestand.php"
    melde "Firewall"
    firewall_setzen
    melde "Fertig: $(adresse_lesen)"
    melde "Jetzt in Proxmox den Snapshot nehmen und das Zurücksetzen alle vier Stunden einplanen (SW2)."
    melde "Vorher im Browser prüfen: Startseite, „Verstanden, weiter“, eine Buchung."
}

[[ -n "${SPIELWIESE_NUR_FUNKTIONEN:-}" ]] || main "$@"
