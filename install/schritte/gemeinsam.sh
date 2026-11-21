# SPDX-License-Identifier: AGPL-3.0-or-later
# Gemeinsame Funktionen für alle Schritte. Wird per "source" eingebunden.

export DEBIAN_FRONTEND=noninteractive
export LC_ALL=C.UTF-8

KONFIG_DATEI="/etc/kumero/install.conf"

melde()  { printf '\033[1;34m[kumero]\033[0m %s\n' "$*"; }
warne()  { printf '\033[1;33m[kumero] WARNUNG:\033[0m %s\n' "$*" >&2; }
abbruch(){ printf '\033[1;31m[kumero] FEHLER:\033[0m %s\n' "$*" >&2; exit 1; }

voraussetzungen_pruefen() {
    [[ $EUID -eq 0 ]] || abbruch "Bitte als root ausführen (sudo)."
    [[ -r /etc/os-release ]] || abbruch "/etc/os-release fehlt."
    # shellcheck source=/dev/null
    . /etc/os-release
    [[ "${ID:-}" == "debian" && "${VERSION_CODENAME:-}" == "trixie" ]] \
        || abbruch "Unterstützt wird nur Debian 13 (trixie), gefunden: ${PRETTY_NAME:-unbekannt}."
    command -v systemctl >/dev/null || abbruch "systemd wird benötigt."
}

# Liest /etc/kumero/install.conf. Fehlt sie, wird sie aus der Vorlage angelegt.
konfiguration_laden() {
    if [[ ! -f "$KONFIG_DATEI" ]]; then
        install -d -m 0755 /etc/kumero
        install -m 0640 "$BASIS/vorlagen/install.conf" "$KONFIG_DATEI"
        melde "Konfiguration angelegt: $KONFIG_DATEI (Standardwerte)."
    fi
    # shellcheck source=/dev/null
    source "$KONFIG_DATEI"

    : "${PHP_VERSION:=8.5}"
    : "${PG_VERSION:=19}"
    : "${PG_VORABVERSION:=auto}"
    : "${SERVERNAME:=$(hostname -f 2>/dev/null || hostname)}"
    : "${KUMERO_VERZ:=/var/www/kumero}"
    : "${UPDATES_NEUSTART:=nein}"
    : "${UPDATES_NEUSTART_ZEIT:=03:30}"
    : "${UPDATES_MAIL:=}"
    : "${FREIGABE_SCHLUESSEL:=}"
    : "${ENTWICKLUNG_TUNNEL:=nein}"
    : "${ZERTIFIKAT_QUELLE:=}"

    [[ "$ENTWICKLUNG_TUNNEL" =~ ^(ja|nein)$ ]] || abbruch "ENTWICKLUNG_TUNNEL muss 'ja' oder 'nein' sein."
    if [[ "$ENTWICKLUNG_TUNNEL" == ja ]]; then ADRESSE=http://localhost:8080; else ADRESSE="https://$SERVERNAME"; fi
    [[ "$PG_VORABVERSION" =~ ^(auto|ja|nein)$ ]] \
        || abbruch "PG_VORABVERSION muss 'auto', 'ja' oder 'nein' sein."

    export PHP_VERSION PG_VERSION PG_VORABVERSION SERVERNAME KUMERO_VERZ \
           UPDATES_NEUSTART UPDATES_NEUSTART_ZEIT UPDATES_MAIL FREIGABE_SCHLUESSEL ENTWICKLUNG_TUNNEL ADRESSE ZERTIFIKAT_QUELLE
}

# Installiert Pakete nur, wenn sie fehlen (idempotent, ohne Empfehlungen).
pakete() {
    local fehlend=()
    for p in "$@"; do
        dpkg-query -W -f='${Status}' "$p" 2>/dev/null | grep -q "install ok installed" || fehlend+=("$p")
    done
    if ((${#fehlend[@]})); then
        apt-get install -y --no-install-recommends "${fehlend[@]}"
    fi
}

# Gibt es für das Paket einen installierbaren Kandidaten?
# (apt-cache show ist hier ungeeignet: es meldet auch rein virtuelle Pakete.)
paket_vorhanden() {
    local kandidat
    kandidat="$(apt-cache policy "$1" 2>/dev/null | awk '/Candidate:/ {print $2}')"
    [[ -n "$kandidat" && "$kandidat" != "(none)" ]]
}

# Schreibt eine Datei nur, wenn sich der Inhalt ändert. Rückgabe 0 = geändert.
# Nutzung: datei_schreiben ZIEL MODUS EIGENTUEMER:GRUPPE < inhalt
datei_schreiben() {
    local ziel="$1" modus="$2" besitz="$3" tmp
    tmp="$(mktemp)"
    cat >"$tmp"
    if [[ -f "$ziel" ]] && cmp -s "$tmp" "$ziel"; then
        rm -f "$tmp"
        return 1
    fi
    install -D -m "$modus" -o "${besitz%%:*}" -g "${besitz##*:}" "$tmp" "$ziel"
    rm -f "$tmp"
    melde "geschrieben: $ziel"
    return 0
}

# Ersetzt @PLATZHALTER@ in einer Vorlage durch Umgebungsvariablen.
vorlage() {
    local datei="$BASIS/vorlagen/$1"
    [[ -f "$datei" ]] || abbruch "Vorlage fehlt: $1"
    sed -e "s|@PHP_VERSION@|${PHP_VERSION}|g" \
        -e "s|@PG_VERSION@|${PG_VERSION}|g" \
        -e "s|@SERVERNAME@|${SERVERNAME}|g" \
        -e "s|@KUMERO_VERZ@|${KUMERO_VERZ}|g" \
        -e "s|@ADRESSE@|${ADRESSE}|g" \
        -e "s|@ZERTIFIKAT_QUELLE@|${ZERTIFIKAT_QUELLE}|g" \
        -e "s|@UPDATES_NEUSTART@|$([[ "$UPDATES_NEUSTART" == "ja" ]] && echo true || echo false)|g" \
        -e "s|@UPDATES_NEUSTART_ZEIT@|${UPDATES_NEUSTART_ZEIT}|g" \
        -e "s|@UPDATES_MAIL@|${UPDATES_MAIL}|g" \
        "$datei"
}

# Fügt einen markierten Block in eine Konfigurationsdatei ein bzw. ersetzt ihn.
# Inhalt außerhalb des Blocks bleibt unberührt.  Rückgabe 0 = geändert.
block_setzen() {
    local datei="$1" marke="$2" inhalt anfang ende tmp
    inhalt="$(cat)"
    anfang="# >>> kumero: $marke >>>"
    ende="# <<< kumero: $marke <<<"
    tmp="$(mktemp)"
    if grep -qF "$anfang" "$datei"; then
        awk -v a="$anfang" -v e="$ende" -v neu="$inhalt" '
            $0==a {print; print neu; ueber=1; next}
            $0==e {ueber=0}
            !ueber {print}' "$datei" >"$tmp"
    else
        # Block an den Anfang setzen: in pg_hba.conf gilt die erste passende Zeile.
        { echo "$anfang"; echo "$inhalt"; echo "$ende"; cat "$datei"; } >"$tmp"
    fi
    if cmp -s "$tmp" "$datei"; then rm -f "$tmp"; return 1; fi
    cat "$tmp" >"$datei"; rm -f "$tmp"
    melde "Block '$marke' gesetzt in $datei"
    return 0
}

systemuser() {
    local name="$1" heim="$2" kommentar="$3"
    if ! id -u "$name" >/dev/null 2>&1; then
        adduser --system --group --home "$heim" --no-create-home \
                --shell /usr/sbin/nologin --comment "$kommentar" "$name"
    fi
}

# Eigene PHP-Einstellungen über den Debian-Weg conf.d (für FPM und CLI). Rückgabe 0 = etwas geändert.
php_ini_schreiben() {
    local sapi geaendert=1
    for sapi in fpm cli; do
        [[ -d "/etc/php/${PHP_VERSION}/${sapi}/conf.d" ]] || continue
        datei_schreiben "/etc/php/${PHP_VERSION}/${sapi}/conf.d/90-kumero.ini" 0644 root:root < <(vorlage php-kumero.ini) && geaendert=0 || true
    done
    return $geaendert
}

# Zeiten der automatischen Updates (F160): Paketlisten holen und einspielen folgen aufeinander, zweimal am Tag.
# Als Ergänzung (drop-in) der Timer von Debian; deren eigene Dateien bleiben unverändert.
update_timer_einrichten() {
    local t neu=0
    for t in apt-daily apt-daily-upgrade; do
        datei_schreiben "/etc/systemd/system/$t.timer.d/kumero.conf" 0644 root:root < <(vorlage "$t.timer.conf") && neu=1 || true
    done
    if ((neu)); then
        systemctl daemon-reload
        systemctl restart apt-daily.timer apt-daily-upgrade.timer
    fi
    systemctl enable --now apt-daily.timer apt-daily-upgrade.timer >/dev/null
}

# Erfasst unattended-upgrades wirklich alle drei Quellen? Geprüft am Probelauf, nicht am Text der eigenen Datei.
# Gibt die Namen der fehlenden Quellen aus; Rückgabe 0 = alle erfasst.
update_quellen_fehlen() {
    local zeile q fehlt=()
    zeile="$(unattended-upgrade --dry-run --debug 2>&1 | grep -m1 'Allowed origins are' || true)"
    for q in "origin=Debian,codename=trixie-security" "origin=apt.postgresql.org" "origin=deb.sury.org"; do
        [[ "$zeile" == *"$q"* ]] || fehlt+=("${q#origin=}")
    done
    ((${#fehlt[@]} == 0)) && return 0
    printf '%s\n' "${fehlt[*]}"
    return 1
}

# Verwendet nginx Verbindungen zu PHP-FPM wieder? (fastcgi_keep_conn on oder keepalive in einem upstream)
nginx_haelt_verbindungen() {
    nginx -T 2>/dev/null | grep -Eq '^[[:space:]]*(fastcgi_keep_conn[[:space:]]+on|keepalive[[:space:]]+[0-9]+)[[:space:]]*;'
}
