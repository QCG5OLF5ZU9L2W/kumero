# SPDX-License-Identifier: AGPL-3.0-or-later
# Schritt 80: Vollautomatische Updates mit unattended-upgrades
#
# Debian-Standard: unattended-upgrades übernimmt die Sicherheitsupdates von
# Debian. Ergänzt werden PGDG und sury, damit PostgreSQL- und PHP-Updates
# ebenfalls automatisch eingespielt werden.
#
# Wichtig: Automatisch kommen nur Updates INNERHALB einer Hauptversion
# (z. B. PostgreSQL 19.1 → 19.2, PHP 8.5.1 → 8.5.2). Ein Wechsel der
# Hauptversion (PostgreSQL 19 → 20, PHP 8.5 → 8.6) erfolgt nie automatisch,
# sondern bewusst über PG_VERSION/PHP_VERSION in install.conf und einen
# erneuten Lauf von install.sh bzw. pg_upgradecluster.

pakete unattended-upgrades apt-listchanges

# Aktivierung über den Debian-Standardweg (wie dpkg-reconfigure unattended-upgrades).
datei_schreiben /etc/apt/apt.conf.d/20auto-upgrades 0644 root:root <<'EOF' || true
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
EOF

# Eigene Ergänzungen in einer eigenen Datei; 50unattended-upgrades bleibt unverändert.
datei_schreiben /etc/apt/apt.conf.d/52kumero-unattended-upgrades 0644 root:root < <(vorlage 52kumero-unattended-upgrades) || true

# Holen der Paketlisten und Einspielen folgen aufeinander, zweimal am Tag (F160).
update_timer_einrichten

# Probelauf (ändert nichts) – zeigt, ob Debian-Security, PGDG und sury wirklich erfasst werden.
if fehlt="$(update_quellen_fehlen)"; then
    melde "unattended-upgrades erfasst Debian, PGDG und sury."
else
    warne "unattended-upgrades erfasst nicht: $fehlt – bitte 'unattended-upgrade --dry-run --debug' prüfen."
fi
