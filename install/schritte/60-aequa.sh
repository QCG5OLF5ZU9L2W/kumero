# SPDX-License-Identifier: AGPL-3.0-or-later
# Schritt 60: Verzeichnisse der Anwendung (F56)
#
#   /var/www/aequa          Anwendung (Code), gehört root, für aequa lesbar
#   /var/www/aequa/public   Dokumentwurzel des Webservers
#   /etc/aequa              Konfiguration
#   /var/lib/aequa          veränderliche Daten der Anwendung (Cache u. Ä.)
#   /var/log/aequa          eigene Protokolle (zusätzlich journald)
#
# Der Code selbst wird später per Paket bzw. Bereitstellungswerkzeug
# ausgeliefert; dieser Schritt legt nur Struktur und Rechte an.

install -d -m 0755 -o root  -g root  "$AEQUA_VERZ"
install -d -m 0755 -o root  -g root  "$AEQUA_VERZ/public"
install -d -m 0750 -o root  -g aequa /etc/aequa
install -d -m 0750 -o aequa -g aequa /var/lib/aequa
install -d -m 0750 -o aequa -g aequa /var/lib/aequa/cache
install -d -m 0750 -o aequa -g adm   /var/log/aequa

# Platzhalter, solange noch kein Code ausgeliefert ist.
if [[ ! -e "$AEQUA_VERZ/public/index.php" ]]; then
    datei_schreiben "$AEQUA_VERZ/public/index.php" 0644 root:root <<'EOF' || true
<?php
// Platzhalter des Aequa-Installers. Wird bei der Auslieferung des Codes ersetzt.
header('Content-Type: application/json');
echo json_encode(['aequa' => 'installiert', 'code' => 'noch nicht ausgeliefert', 'php' => PHP_VERSION]);
EOF
fi

# Logrotate für /var/log/aequa (Debian-Standardwerkzeug).
datei_schreiben /etc/logrotate.d/aequa 0644 root:root <<'EOF' || true
/var/log/aequa/*.log {
    weekly
    rotate 12
    compress
    delaycompress
    missingok
    notifempty
    create 0640 aequa adm
}
EOF
