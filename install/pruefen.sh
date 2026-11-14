#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Aequa – Prüft den Zielzustand der Installation, ändert nichts.
# Rückgabe: 0 = alles in Ordnung, 1 = mindestens ein Fehler.

set -uo pipefail
BASIS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export BASIS
# shellcheck source=schritte/gemeinsam.sh
source "$BASIS/schritte/gemeinsam.sh"
set +e

[[ $EUID -eq 0 ]] || abbruch "Bitte als root ausführen."
[[ -f "$KONFIG_DATEI" ]] || abbruch "$KONFIG_DATEI fehlt – wurde install.sh ausgeführt?"
konfiguration_laden

fehler=0
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*"; }
nein() { printf '  \033[31m✗\033[0m %s\n' "$*"; fehler=1; }
pruefe() { local text="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$text"; else nein "$text"; fi; }

echo "System"
pruefe "Debian 13 (trixie)" grep -q '^VERSION_CODENAME=trixie' /etc/os-release

echo "Paketquellen"
pruefe "PGDG eingerichtet (/etc/apt/sources.list.d/pgdg.sources)" test -f /etc/apt/sources.list.d/pgdg.sources
pruefe "sury eingerichtet (/etc/apt/sources.list.d/php-sury.sources)" test -f /etc/apt/sources.list.d/php-sury.sources
pruefe "sury-Schlüssel per Paket gepflegt" dpkg -s debsuryorg-archive-keyring

echo "PostgreSQL $PG_VERSION"
pruefe "Paket postgresql-$PG_VERSION installiert" dpkg -s "postgresql-$PG_VERSION"
pruefe "Cluster $PG_VERSION/main läuft" systemctl is-active "postgresql@${PG_VERSION}-main"
pruefe "nur lokal (listen_addresses leer)" grep -q "^listen_addresses = ''" "/etc/postgresql/$PG_VERSION/main/conf.d/50-aequa.conf"
for rolle in aequa_owner aequa_app aequa_lesen; do
    pruefe "Rolle $rolle vorhanden" bash -c "cd / && runuser -u postgres -- psql -X --cluster $PG_VERSION/main -tAc \"SELECT 1 FROM pg_roles WHERE rolname='$rolle'\" | grep -q 1"
done
for db in aequa_verwaltung aequa_vorlage; do
    pruefe "Datenbank $db vorhanden" bash -c "cd / && runuser -u postgres -- psql -X --cluster $PG_VERSION/main -tAc \"SELECT 1 FROM pg_database WHERE datname='$db'\" | grep -q 1"
done
pruefe "Anwendung meldet sich per Peer als aequa_app an" bash -c "cd / && runuser -u aequa -- psql -X --cluster $PG_VERSION/main -U aequa_app -d aequa_verwaltung -tAc 'SELECT current_user' | grep -qx aequa_app"
pruefe "Anwendung kann NICHT als aequa_owner anmelden" bash -c "cd / && ! runuser -u aequa -- psql -X --cluster $PG_VERSION/main -U aequa_owner -d aequa_verwaltung -tAc 'SELECT 1'"

echo "PHP $PHP_VERSION"
pruefe "php$PHP_VERSION-fpm läuft" systemctl is-active "php${PHP_VERSION}-fpm"
pruefe "php zeigt auf $PHP_VERSION" bash -c "php -r 'echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;' | grep -qx '$PHP_VERSION'"
for ext in pgsql pdo_pgsql intl mbstring bcmath gmp sodium curl zip dom; do
    pruefe "Erweiterung $ext" bash -c "php -m | grep -qix '$ext'"
done
pruefe "FPM-Socket für Aequa vorhanden" test -S "/run/php/php${PHP_VERSION}-fpm-aequa.sock"

echo "Webserver (nginx)"
pruefe "nginx läuft" systemctl is-active nginx
pruefe "nginx-Konfiguration gültig" nginx -t
pruefe "HTTPS antwortet (lokal)" bash -c "curl -sk --resolve '$SERVERNAME:443:127.0.0.1' 'https://$SERVERNAME/' | grep -q aequa"

echo "Aequa"
pruefe "Benutzer aequa vorhanden" id aequa
pruefe "Verzeichnis $AEQUA_VERZ/public" test -d "$AEQUA_VERZ/public"
pruefe "/etc/aequa nicht für alle lesbar" bash -c "[[ \$(stat -c %a /etc/aequa) == 750 ]]"

echo "Signierdienst (vorbereitet)"
pruefe "Benutzer aequa-ssm vorhanden" id aequa-ssm
pruefe "GnuPG-Verzeichnis 0700" bash -c "[[ \$(stat -c %a /var/lib/aequa-ssm/gnupg) == 700 ]]"
pruefe "aequa ist in Gruppe aequa-ssm-client" bash -c "id -nG aequa | grep -qw aequa-ssm-client"
pruefe "Unit aequa-ssm.service vorhanden" test -f /etc/systemd/system/aequa-ssm.service

echo "Automatische Updates"
pruefe "unattended-upgrades aktiv (20auto-upgrades)" grep -q 'Unattended-Upgrade "1"' /etc/apt/apt.conf.d/20auto-upgrades
pruefe "PGDG und sury in Origins-Pattern" bash -c "grep -q apt.postgresql.org /etc/apt/apt.conf.d/52aequa-unattended-upgrades && grep -q deb.sury.org /etc/apt/apt.conf.d/52aequa-unattended-upgrades"
pruefe "apt-daily-upgrade.timer aktiv" systemctl is-active apt-daily-upgrade.timer

echo
if ((fehler)); then
    echo "Ergebnis: Es gibt Abweichungen (✗)."
    exit 1
fi
echo "Ergebnis: Alles in Ordnung."
