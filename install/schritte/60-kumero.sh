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

if [[ ! -L "$KUMERO_VERZ" ]]; then
    install -d -m 0755 -o root  -g root  "$KUMERO_VERZ"
    install -d -m 0755 -o root  -g root  "$KUMERO_VERZ/public"
fi
install -d -m 0750 -o root  -g kumero /etc/kumero
install -d -m 0750 -o kumero -g kumero /var/lib/kumero
# nginx (www-data) muss durch /var/lib/kumero zu releases/ gelangen, wohin
# /var/www/kumero zeigt (E7): nur Durchgang (x), kein Auflisten.
chmod 0751 /var/lib/kumero
install -d -m 0750 -o kumero -g kumero /var/lib/kumero/cache
install -d -m 0750 -o kumero -g adm   /var/log/kumero

# Anwendungskonfiguration (E6), ohne Geheimnisse. Wird nur angelegt, nie überschrieben.
if [[ ! -f /etc/kumero/kumero.php ]]; then
    datei_schreiben /etc/kumero/kumero.php 0640 root:kumero < <(vorlage kumero.php) || true
fi

# Platzhalter, solange noch kein Code ausgeliefert ist. Nach dem ersten
# Release ist $KUMERO_VERZ ein Symlink auf das aktive Release (E7).
if [[ ! -L "$KUMERO_VERZ" && ! -e "$KUMERO_VERZ/public/index.php" ]]; then
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

# Freigabe-Schlüssel (F73): Nur Releases, die mit einem hier hinterlegten
# Schlüssel signiert sind, dürfen den installierten Kern ändern.
install -d -m 0755 -o root -g root /var/lib/kumero/releases
if [[ -n "$FREIGABE_SCHLUESSEL" ]]; then
    [[ -f "$FREIGABE_SCHLUESSEL" ]] || abbruch "FREIGABE_SCHLUESSEL nicht gefunden: $FREIGABE_SCHLUESSEL"
    tmp="$(mktemp)"
    gpg --batch --yes --dearmor -o "$tmp" "$FREIGABE_SCHLUESSEL"
    datei_schreiben /etc/kumero/freigabe-schluessel.gpg 0644 root:root < "$tmp" || true
    rm -f "$tmp"
    melde "Freigabe-Schlüssel hinterlegt: $(gpg --show-keys --with-colons "$FREIGABE_SCHLUESSEL" | awk -F: '/^fpr/ {print $10; exit}')"
elif [[ ! -f /etc/kumero/freigabe-schluessel.gpg ]]; then
    warne "Kein Freigabe-Schlüssel hinterlegt – Aktualisierungen des Kerns sind bis dahin gesperrt (F73)."
fi
