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
# Kontenrahmen-Vorlagen, installationsweit (KONTENRAHMEN.md K2). Seit 0.6.1 schreibt auch die
# Weboberfläche (Import durch den Verwalter, F80): Gruppe kumero darf schreiben, setgid hält die Gruppe.
install -d -m 2770 -o root -g kumero /var/lib/kumero/kontenrahmen
install -d -m 2770 -o root -g kumero /var/lib/kumero/kontenrahmen/entwuerfe
chmod 2770 /var/lib/kumero/kontenrahmen /var/lib/kumero/kontenrahmen/entwuerfe
chgrp -R kumero /var/lib/kumero/kontenrahmen
find /var/lib/kumero/kontenrahmen -maxdepth 1 -type f -name '*.json' -exec chmod 0640 {} +
install -d -m 0750 -o kumero -g kumero /var/lib/kumero/cache
install -d -m 0750 -o kumero -g adm   /var/log/kumero

# Anwendungskonfiguration (E6), ohne Geheimnisse. Wird nur angelegt, nie überschrieben.
if [[ ! -f /etc/kumero/kumero.php ]]; then
    datei_schreiben /etc/kumero/kumero.php 0640 root:kumero < <(vorlage kumero.php) || true
elif ! grep -Eq "'adresse'[[:space:]]*=>[[:space:]]*'${ADRESSE//./\\.}'" /etc/kumero/kumero.php; then
    warne "In /etc/kumero/kumero.php steht eine andere 'adresse' als $ADRESSE (install.conf)."
    warne "Umstellen (danach Passkeys neu registrieren, sie hängen am Hostnamen):"
    warne "  sed -i \"s|'adresse'[[:space:]]*=>[[:space:]]*'[^']*'|'adresse'       => '$ADRESSE'|\" /etc/kumero/kumero.php && systemctl reload php${PHP_VERSION}-fpm"
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

# Mandantenverwaltung (F118): Die Seite „Mandanten“ schreibt Aufträge, dieser Dienst führt sie als kumero_owner aus.
# Sicherungen (pg_dump) enthalten alle Daten eines Mandanten: nur root liest das Verzeichnis.
install -d -m 0700 -o root -g root /var/lib/kumero/exporte
for u in kumero-auftraege.service kumero-auftraege.timer; do
    datei_schreiben "/etc/systemd/system/$u" 0644 root:root < <(vorlage "$u") && auftraege_neu=1 || true
done
[[ "${auftraege_neu:-0}" == 1 ]] && systemctl daemon-reload
systemctl enable --now kumero-auftraege.timer >/dev/null

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
