#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# kumero – zieht auf einer bestehenden Installation nach, was install.sh heute am System anders einrichtet (F160).
#
# Aufruf als root; werkzeuge/update-neueste.sh ruft es nach jedem Update auf. Wiederholbar – ohne Änderung geschieht nichts.
#
# Geschrieben wird nur, was install.sh ohnehin verwaltet und niemand von Hand pflegt:
#   - die PHP-Einstellungen von kumero (/etc/php/<Version>/{fpm,cli}/conf.d/90-kumero.ini), danach PHP-FPM neu laden
#   - die Zeiten der automatischen Updates (Ergänzungen zu apt-daily.timer und apt-daily-upgrade.timer)
# Nur geprüft und gemeldet, nie überschrieben:
#   - nginx (dort stehen Anpassungen von Hand): ob Verbindungen zu PHP-FPM wiederverwendet werden
#   - ob unattended-upgrades Debian-Security, PGDG und sury wirklich erfasst

set -euo pipefail
BASIS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export BASIS
# shellcheck source=schritte/gemeinsam.sh
source "$BASIS/schritte/gemeinsam.sh"

[[ $EUID -eq 0 ]] || abbruch "Bitte als root ausführen."
[[ -f "$KONFIG_DATEI" ]] || abbruch "$KONFIG_DATEI fehlt – wurde install.sh ausgeführt?"
konfiguration_laden

melde "Ziehe die Einrichtung des Systems nach"

# PHP: erst schreiben, dann prüfen – scheitert die Prüfung, wird nicht neu geladen, und der laufende Dienst bleibt, wie er ist.
if php_ini_schreiben; then
    if "php-fpm${PHP_VERSION}" -t 2>/dev/null; then
        systemctl reload "php${PHP_VERSION}-fpm"
        melde "PHP-FPM neu geladen."
    else
        warne "PHP-FPM-Konfiguration fehlerhaft (php-fpm${PHP_VERSION} -t) – nicht neu geladen."
    fi
else
    melde "PHP-Einstellungen sind aktuell."
fi

# Automatische Updates
if command -v unattended-upgrade >/dev/null; then
    update_timer_einrichten
    melde "Automatische Updates: Paketlisten gegen 05:40 und 17:40, Einspielen gegen 06:10 und 18:10."
    if fehlt="$(update_quellen_fehlen)"; then
        melde "unattended-upgrades erfasst Debian, PGDG und sury."
    else
        warne "unattended-upgrades erfasst nicht: $fehlt – bitte 'unattended-upgrade --dry-run --debug' prüfen."
    fi
else
    warne "unattended-upgrades ist nicht installiert – Sicherheitsupdates kommen hier nicht von selbst (install.sh, Schritt 80)."
fi

# nginx: nur melden
if command -v nginx >/dev/null && nginx_haelt_verbindungen; then
    warne "nginx verwendet Verbindungen zu PHP-FPM wieder (fastcgi_keep_conn on oder keepalive) – bitte entfernen; kumero braucht das nicht."
else
    melde "nginx verwendet Verbindungen zu PHP-FPM nicht wieder."
fi
