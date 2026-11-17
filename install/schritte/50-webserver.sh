# SPDX-License-Identifier: AGPL-3.0-or-later
# Schritt 50: Webserver nginx (T4)
#
# TLS: Zunächst das Debian-Standardzertifikat "snakeoil" aus ssl-cert.
# Für den Produktivbetrieb ein eigenes Zertifikat eintragen oder certbot
# (Debian-Paket python3-certbot-nginx) verwenden; certbot erneuert dann selbst
# per systemd-Timer.

if dpkg-query -W -f='${Status}' apache2 2>/dev/null | grep -q "install ok installed"; then
    warne "apache2 ist installiert – kumero nutzt nginx. Ports 80/443 können kollidieren."
fi

pakete nginx

datei_schreiben /etc/nginx/sites-available/kumero 0644 root:root < <(vorlage nginx-kumero.conf) \
    && web_neu=1 || true
ln -sf ../sites-available/kumero /etc/nginx/sites-enabled/kumero
# Zugang über SSH-Tunnel (ENTWICKLUNG_TUNNEL=ja): nur 127.0.0.1:8080
if [[ "$ENTWICKLUNG_TUNNEL" == ja ]]; then
    datei_schreiben /etc/nginx/sites-available/kumero-tunnel 0644 root:root < <(vorlage nginx-kumero-tunnel.conf) \
        && web_neu=1 || true
    if [[ ! -L /etc/nginx/sites-enabled/kumero-tunnel ]]; then
        ln -sf ../sites-available/kumero-tunnel /etc/nginx/sites-enabled/kumero-tunnel
        web_neu=1
    fi
elif [[ -L /etc/nginx/sites-enabled/kumero-tunnel ]]; then
    rm -f /etc/nginx/sites-enabled/kumero-tunnel
    web_neu=1
fi
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
