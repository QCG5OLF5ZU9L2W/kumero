# SPDX-License-Identifier: AGPL-3.0-or-later
# Schritt 40: PHP (F54) mit eigenem FPM-Pool für Aequa

V="$PHP_VERSION"

pakete \
    "php${V}-cli" \
    "php${V}-fpm" \
    "php${V}-pgsql" \
    "php${V}-intl" \
    "php${V}-mbstring" \
    "php${V}-xml" \
    "php${V}-bcmath" \
    "php${V}-gmp" \
    "php${V}-curl" \
    "php${V}-zip"

# OPcache ist ab PHP 8.5 fest eingebaut; als eigenes Paket nur, falls vorhanden.
if paket_vorhanden "php${V}-opcache"; then
    pakete "php${V}-opcache"
fi

# Diese PHP-Version als Standard für "php" auf der Kommandozeile.
update-alternatives --set php "/usr/bin/php${V}" >/dev/null

# Systembenutzer für die Anwendung (F56). PHP-FPM läuft unter diesem Benutzer;
# der Webserver spricht nur über den Socket mit ihm.
systemuser aequa "$AEQUA_VERZ" "Aequa Anwendung"

# Eigene PHP-Einstellungen über den Debian-Weg conf.d (für FPM und CLI).
for sapi in fpm cli; do
    datei_schreiben "/etc/php/${V}/${sapi}/conf.d/90-aequa.ini" 0644 root:root < <(vorlage php-aequa.ini) \
        && php_neu=1 || true
done

# Eigener Pool; der Debian-Standardpool "www" bleibt unangetastet.
datei_schreiben "/etc/php/${V}/fpm/pool.d/aequa.conf" 0644 root:root < <(vorlage php-fpm-aequa.conf) \
    && php_neu=1 || true

"php-fpm${V}" -t 2>/dev/null || abbruch "PHP-FPM-Konfiguration fehlerhaft (php-fpm${V} -t)."
systemctl enable --now "php${V}-fpm" >/dev/null
if [[ "${php_neu:-0}" == 1 ]]; then
    systemctl reload "php${V}-fpm"
fi
