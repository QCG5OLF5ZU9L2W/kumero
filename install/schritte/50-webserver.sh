# SPDX-License-Identifier: AGPL-3.0-or-later
# Schritt 50: Webserver nginx (T4)
#
# TLS: Zunächst das Debian-Standardzertifikat "snakeoil" aus ssl-cert.
# Für den Produktivbetrieb ein eigenes Zertifikat eintragen oder certbot
# (Debian-Paket python3-certbot-nginx) verwenden; certbot erneuert dann selbst
# per systemd-Timer.

if dpkg-query -W -f='${Status}' apache2 2>/dev/null | grep -q "install ok installed"; then
    warne "apache2 ist installiert – Aequa nutzt nginx. Ports 80/443 können kollidieren."
fi

pakete nginx

datei_schreiben /etc/nginx/sites-available/aequa 0644 root:root < <(vorlage nginx-aequa.conf) \
    && web_neu=1 || true
ln -sf ../sites-available/aequa /etc/nginx/sites-enabled/aequa
# Die Debian-Beispielseite deaktivieren (die Datei selbst bleibt erhalten).
if [[ -L /etc/nginx/sites-enabled/default ]]; then
    rm -f /etc/nginx/sites-enabled/default
    web_neu=1
fi

nginx -t 2>/dev/null || abbruch "nginx-Konfiguration fehlerhaft (nginx -t)."
systemctl enable --now nginx >/dev/null
if [[ "${web_neu:-0}" == 1 ]]; then
    systemctl reload nginx
fi
