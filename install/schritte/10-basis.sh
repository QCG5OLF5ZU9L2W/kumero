# SPDX-License-Identifier: AGPL-3.0-or-later
# Schritt 10: Grundpakete aus Debian main

apt-get update
apt-get -y upgrade

pakete \
    ca-certificates \
    curl \
    gnupg \
    lsb-release \
    locales \
    tzdata \
    ssl-cert \
    acl \
    unattended-upgrades \
    apt-listchanges \
    needrestart

# Deutsche Locale zusätzlich verfügbar machen (Systemstandard bleibt unverändert).
if ! locale -a 2>/dev/null | grep -qi '^de_DE\.utf8$'; then
    sed -i 's/^# *\(de_DE\.UTF-8 UTF-8\)/\1/' /etc/locale.gen
    locale-gen
fi

# needrestart: Dienste nach Bibliotheks-Updates automatisch neu starten,
# damit unbeaufsichtigte Updates auch wirklich wirksam werden.
datei_schreiben /etc/needrestart/conf.d/50-kumero.conf 0644 root:root <<'EOF' || true
# kumero: Dienste nach Updates ohne Rückfrage neu starten
$nrconf{restart} = 'a';
EOF
