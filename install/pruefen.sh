#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# kumero – Prüft den Zielzustand der Installation, ändert nichts.
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
pruefe "nur lokal (listen_addresses leer)" grep -q "^listen_addresses = ''" "/etc/postgresql/$PG_VERSION/main/conf.d/50-kumero.conf"
for rolle in kumero_owner kumero_app kumero_lesen; do
    pruefe "Rolle $rolle vorhanden" bash -c "cd / && runuser -u postgres -- psql -X --cluster $PG_VERSION/main -tAc \"SELECT 1 FROM pg_roles WHERE rolname='$rolle'\" | grep -q 1"
done
for db in kumero_verwaltung kumero_vorlage; do
    pruefe "Datenbank $db vorhanden" bash -c "cd / && runuser -u postgres -- psql -X --cluster $PG_VERSION/main -tAc \"SELECT 1 FROM pg_database WHERE datname='$db'\" | grep -q 1"
done
pruefe "Anwendung meldet sich per Peer als kumero_app an" bash -c "cd / && runuser -u kumero -- psql -X --cluster $PG_VERSION/main -U kumero_app -d kumero_verwaltung -tAc 'SELECT current_user' | grep -qx kumero_app"
pruefe "Anwendung kann NICHT als kumero_owner anmelden" bash -c "cd / && ! runuser -u kumero -- psql -X --cluster $PG_VERSION/main -U kumero_owner -d kumero_verwaltung -tAc 'SELECT 1'"

echo "PHP $PHP_VERSION"
pruefe "php$PHP_VERSION-fpm läuft" systemctl is-active "php${PHP_VERSION}-fpm"
pruefe "php zeigt auf $PHP_VERSION" bash -c "php -r 'echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;' | grep -qx '$PHP_VERSION'"
for ext in pgsql pdo_pgsql intl mbstring bcmath gmp sodium curl zip dom; do
    pruefe "Erweiterung $ext" bash -c "php -m | grep -qix '$ext'"
done
pruefe "FPM-Socket für kumero vorhanden" test -S "/run/php/php${PHP_VERSION}-fpm-kumero.sock"

echo "Webserver (nginx)"
pruefe "nginx läuft" systemctl is-active nginx
pruefe "nginx-Konfiguration gültig" nginx -t
# Ohne Anmeldung antwortet die Anwendung mit einem Problem „urn:kumero:problem:…“ (401);
# vor dem ersten Release mit dem Platzhalter {"kumero":"installiert",…}.
pruefe "HTTPS antwortet (lokal)" bash -c "curl -sk --resolve '$SERVERNAME:443:127.0.0.1' 'https://$SERVERNAME/api/v1/ich' | grep -q kumero"
if [[ -n "${ZERTIFIKAT_QUELLE:-}" ]]; then
    pruefe "Zertifikatsquelle $ZERTIFIKAT_QUELLE in Ordnung" env KUMERO_ZERT_OHNE_WARTEN=1 /usr/local/sbin/kumero-zertifikat --pruefen
    pruefe "nginx liefert das Zertifikat für $SERVERNAME aus" bash -c "echo | openssl s_client -connect 127.0.0.1:443 -servername '$SERVERNAME' 2>/dev/null | openssl x509 -noout -checkhost '$SERVERNAME' | grep -q 'does match'"
    pruefe "Überwachung der Quelle aktiv (kumero-zertifikat.path)" systemctl is-active kumero-zertifikat.path
fi
if [[ "${ENTWICKLUNG_TUNNEL:-nein}" == ja ]]; then
    pruefe "Tunnel-Zugang 127.0.0.1:8080 antwortet" bash -c "curl -s http://127.0.0.1:8080/api/v1/ich | grep -q kumero"
fi

echo "kumero"
pruefe "Benutzer kumero vorhanden" id kumero
pruefe "Anwendungskonfiguration /etc/kumero/kumero.php" test -f /etc/kumero/kumero.php
pruefe "Verzeichnis $KUMERO_VERZ/public" test -d "$KUMERO_VERZ/public"
pruefe "/etc/kumero nicht für alle lesbar" bash -c "[[ \$(stat -c %a /etc/kumero) == 750 ]]"

echo "Signierdienst (vorbereitet)"
pruefe "Benutzer kumero-ssm vorhanden" id kumero-ssm
pruefe "GnuPG-Verzeichnis 0700" bash -c "[[ \$(stat -c %a /var/lib/kumero-ssm/gnupg) == 700 ]]"
pruefe "kumero ist in Gruppe kumero-ssm-client" bash -c "id -nG kumero | grep -qw kumero-ssm-client"
pruefe "Unit kumero-ssm.service vorhanden" test -f /etc/systemd/system/kumero-ssm.service

echo "Freigabe von Releases (F73)"
pruefe "gpgv installiert" command -v gpgv
pruefe "Freigabe-Schlüssel hinterlegt" test -s /etc/kumero/freigabe-schluessel.gpg

echo "Automatische Updates"
pruefe "unattended-upgrades aktiv (20auto-upgrades)" grep -q 'Unattended-Upgrade "1"' /etc/apt/apt.conf.d/20auto-upgrades
pruefe "PGDG und sury in Origins-Pattern" bash -c "grep -q apt.postgresql.org /etc/apt/apt.conf.d/52kumero-unattended-upgrades && grep -q deb.sury.org /etc/apt/apt.conf.d/52kumero-unattended-upgrades"
pruefe "apt-daily-upgrade.timer aktiv" systemctl is-active apt-daily-upgrade.timer

echo
if ((fehler)); then
    echo "Ergebnis: Es gibt Abweichungen (✗)."
    exit 1
fi
echo "Ergebnis: Alles in Ordnung."
