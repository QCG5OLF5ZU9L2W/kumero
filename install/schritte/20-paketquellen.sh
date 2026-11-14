# SPDX-License-Identifier: AGPL-3.0-or-later
# Schritt 20: Zusätzliche Paketquellen (F54)
#
#  PostgreSQL: apt.postgresql.org (PGDG) – eingerichtet mit dem Skript aus
#              Debians eigenem Paket postgresql-common. Schlüssel und Quelle
#              werden von Debian-Paketen gepflegt.
#  PHP:        packages.sury.org – Schlüssel über das Paket
#              debsuryorg-archive-keyring, damit auch der Schlüssel per apt
#              aktualisiert wird.

# --- PostgreSQL (PGDG) ------------------------------------------------------
pakete postgresql-common

pgdg_optionen=(-y)
if [[ "$PG_VORABVERSION" == "ja" ]]; then
    # Beta-/RC-Pakete liegen in der PGDG-Komponente mit der Versionsnummer.
    pgdg_optionen+=(-c "$PG_VERSION")
fi
# Das Skript schreibt /etc/apt/sources.list.d/pgdg.sources (deb822, Signed-By).
# Es ist idempotent und wird bei jedem Lauf neu ausgeführt, damit ein Wechsel
# von PG_VORABVERSION=ja auf nein wirksam wird.
/usr/share/postgresql-common/pgdg/apt.postgresql.org.sh "${pgdg_optionen[@]}"

# --- PHP (sury) -------------------------------------------------------------
SURY_KEYRING=/usr/share/keyrings/debsuryorg-archive-keyring.gpg
if ! dpkg-query -W -f='${Status}' debsuryorg-archive-keyring 2>/dev/null | grep -q "install ok installed"; then
    tmpdeb="$(mktemp --suffix=.deb)"
    curl -fsSL -o "$tmpdeb" https://packages.sury.org/debsuryorg-archive-keyring.deb
    dpkg -i "$tmpdeb"
    rm -f "$tmpdeb"
fi
[[ -f "$SURY_KEYRING" ]] || abbruch "sury-Schlüsselbund fehlt: $SURY_KEYRING"

datei_schreiben /etc/apt/sources.list.d/php-sury.sources 0644 root:root <<EOF || true
# PHP-Pakete von Ondřej Surý (Debian-PHP-Paketbetreuer), für Aequa (F54)
Types: deb
URIs: https://packages.sury.org/php/
Suites: trixie
Components: main
Signed-By: $SURY_KEYRING
EOF

# Kein Pinning: sury liefert nur PHP und die dafür nötigen Bibliotheken.
# Ein Herabsetzen der Priorität würde Abhängigkeiten von PHP unauflösbar machen.
# Eine Datei aus früheren Installer-Versionen wird entfernt.
rm -f /etc/apt/preferences.d/aequa-php-sury

apt-get update

paket_vorhanden "php${PHP_VERSION}-fpm" \
    || abbruch "php${PHP_VERSION}-fpm ist in den Paketquellen nicht verfügbar."
paket_vorhanden "postgresql-${PG_VERSION}" \
    || abbruch "postgresql-${PG_VERSION} ist nicht verfügbar. Vorabversion? Dann PG_VORABVERSION=ja setzen."
