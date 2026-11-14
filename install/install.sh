#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Aequa – Installation auf einem nackten Debian 13 (trixie)
#
# Grundsätze:
#   - Alles bleibt am Debian-Standard: Pakete nur über apt, Konfiguration in
#     den von Debian vorgesehenen *.d-Verzeichnissen, Dienste über systemd.
#   - Zusätzliche Paketquellen (F54): PostgreSQL von apt.postgresql.org (PGDG),
#     eingerichtet mit dem Werkzeug aus Debians eigenem Paket postgresql-common;
#     PHP von packages.sury.org. Beide sind per apt signiert und aktualisierbar.
#   - Idempotent: Das Skript darf beliebig oft laufen und bringt das System
#     jedes Mal in denselben Zielzustand.
#   - Nach der Installation laufen Sicherheitsupdates vollautomatisch über
#     unattended-upgrades – auch für PGDG und sury.
#
# Aufruf:   sudo ./install.sh            (liest /etc/aequa/install.conf)
#           sudo ./install.sh --pruefen  (nur prüfen, nichts ändern)

set -euo pipefail

BASIS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export BASIS
# shellcheck source=schritte/gemeinsam.sh
source "$BASIS/schritte/gemeinsam.sh"

if [[ "${1:-}" == "--pruefen" ]]; then
    exec "$BASIS/pruefen.sh"
fi

voraussetzungen_pruefen
konfiguration_laden

melde "Aequa-Installation startet (PHP $PHP_VERSION, PostgreSQL $PG_VERSION, nginx)"

for schritt in \
    10-basis \
    20-paketquellen \
    30-postgresql \
    40-php \
    50-webserver \
    60-aequa \
    70-signierdienst \
    80-updates
do
    melde "── Schritt $schritt"
    # shellcheck source=/dev/null
    source "$BASIS/schritte/$schritt.sh"
done

melde "Installation abgeschlossen. Prüfung:"
"$BASIS/pruefen.sh"
