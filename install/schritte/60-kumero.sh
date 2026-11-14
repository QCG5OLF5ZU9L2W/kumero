# SPDX-License-Identifier: AGPL-3.0-or-later
# Schritt 60: Verzeichnisse der Anwendung (F56)
#
#   /var/www/kumero          Anwendung (Code), gehört root, für kumero lesbar
#   /var/www/kumero/public   Dokumentwurzel des Webservers
#   /etc/kumero              Konfiguration
#   /var/lib/kumero          veränderliche Daten der Anwendung (Cache u. Ä.)
#   /var/log/kumero          eigene Protokolle (zusätzlich journald)
#
# Der Code selbst wird später per Paket bzw. Bereitstellungswerkzeug
# ausgeliefert; dieser Schritt legt nur Struktur und Rechte an.

install -d -m 0755 -o root  -g root  "$KUMERO_VERZ"
install -d -m 0755 -o root  -g root  "$KUMERO_VERZ/public"
install -d -m 0750 -o root  -g kumero /etc/kumero
install -d -m 0750 -o kumero -g kumero /var/lib/kumero
install -d -m 0750 -o kumero -g kumero /var/lib/kumero/cache
install -d -m 0750 -o kumero -g adm   /var/log/kumero

# Platzhalter, solange noch kein Code ausgeliefert ist.
if [[ ! -e "$KUMERO_VERZ/public/index.php" ]]; then
    datei_schreiben "$KUMERO_VERZ/public/index.php" 0644 root:root <<'EOF' || true
<?php
// Platzhalter des kumero-Installers. Wird bei der Auslieferung des Codes ersetzt.
header('Content-Type: application/json');
echo json_encode(['kumero' => 'installiert', 'code' => 'noch nicht ausgeliefert', 'php' => PHP_VERSION]);
EOF
fi

# Logrotate für /var/log/kumero (Debian-Standardwerkzeug).
datei_schreiben /etc/logrotate.d/kumero 0644 root:root <<'EOF' || true
/var/log/kumero/*.log {
    weekly
    rotate 12
    compress
    delaycompress
    missingok
    notifempty
    create 0640 kumero adm
}
EOF
