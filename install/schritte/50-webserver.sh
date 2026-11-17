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

pakete nginx ssl-cert openssl

# TLS-Zertifikat unter /etc/kumero/tls/ (nginx liest es als root). Fehlt es,
# zunächst eine Kopie von "snakeoil", damit nginx startet.
install -d -m 0750 -o root -g root /etc/kumero/tls
if [[ ! -f /etc/kumero/tls/fullchain.pem || ! -f /etc/kumero/tls/privkey.pem ]]; then
    install -m 0644 -o root -g root /etc/ssl/certs/ssl-cert-snakeoil.pem /etc/kumero/tls/fullchain.pem
    install -m 0600 -o root -g root /etc/ssl/private/ssl-cert-snakeoil.key /etc/kumero/tls/privkey.pem
    web_neu=1
fi
# Zertifikat von außen (ZERTIFIKAT_QUELLE): Prüf- und Übernahmeskript, Path- und Timer-Unit
datei_schreiben /usr/local/sbin/kumero-zertifikat 0755 root:root < <(vorlage kumero-zertifikat) || true
for u in kumero-zertifikat.service kumero-zertifikat.path kumero-zertifikat.timer; do
    datei_schreiben "/etc/systemd/system/$u" 0644 root:root < <(vorlage "$u") && zert_neu=1 || true
done
[[ "${zert_neu:-0}" == 1 ]] && systemctl daemon-reload

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

if [[ -n "$ZERTIFIKAT_QUELLE" ]]; then
    systemctl enable --now kumero-zertifikat.path kumero-zertifikat.timer >/dev/null
    if KUMERO_ZERT_OHNE_WARTEN=1 /usr/local/sbin/kumero-zertifikat; then
        melde "TLS-Zertifikat aus $ZERTIFIKAT_QUELLE ist aktiv."
    else
        warne "Zertifikat aus $ZERTIFIKAT_QUELLE noch nicht übernommen (siehe oben) – nginx nutzt weiter das bisherige. Nach dem nächsten Upload geschieht das automatisch."
    fi
else
    systemctl disable --now kumero-zertifikat.path kumero-zertifikat.timer >/dev/null 2>&1 || true
fi
