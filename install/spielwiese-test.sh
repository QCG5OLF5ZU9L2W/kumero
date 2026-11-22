#!/bin/bash
set -euo pipefail
# SPDX-License-Identifier: AGPL-3.0-or-later
# Prüft die Teile von spielwiese.sh, die ohne Debian 13 und ohne root laufen: Vorabprüfung, Angaben, Konfiguration des
# Pförtners, Vorlagen für nginx und PHP-FPM, Regeln der Firewall (mit Attrappen für ufw und die Kommandozeile von kumero).
# Der Pförtner selbst wird gegen ein laufendes kumero geprüft: php tests/spielwiese/lauf.php
# Aufruf:  bash install/spielwiese-test.sh
HIER="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT; ok=0; f=0
pruefe() { if eval "$2"; then ok=$((ok+1)); else f=$((f+1)); echo "FEHLT: $1"; fi; }
mkdir -p "$T/kumero/bin" "$T/kumero/public" "$T/etc" "$T/nginx/sites-available" "$T/nginx/sites-enabled" "$T/php/8.5/fpm/pool.d" "$T/bin"
echo '<?php echo getenv("ATTRAPPE_MANDANTEN") ?: "";' > "$T/kumero/bin/kumero"
echo "<?php return ['adresse' => 'https://demo.example.org'];" > "$T/etc/kumero.php"
sed -e 's|@PHP_VERSION@|8.5|g' -e 's|@SERVERNAME@|demo.example.org|g' -e "s|@KUMERO_VERZ@|$T/kumero|g" "$HIER/vorlagen/nginx-kumero.conf" > "$T/nginx/sites-available/kumero"
printf '#!/bin/bash\necho "ufw $*" >> "%s/ufw.log"\n' "$T" > "$T/bin/ufw"; chmod +x "$T/bin/ufw"
export PATH="$T/bin:$PATH" SPIELWIESE_NUR_FUNKTIONEN=1 KUMERO_VERZ="$T/kumero" KUMERO_ETC="$T/etc" SPIELWIESE_ETC="$T/sw-etc" \
       SPIELWIESE_VERZ="$T/opt" SPIELWIESE_DATEN="$T/daten" SPIELWIESE_NGINX="$T/nginx" SPIELWIESE_FPM="$T/php"
source "$HIER/spielwiese.sh"
set +e
EUID_ECHT=$EUID
pruefe "Adresse und PHP-Version aus der Installation gelesen" '[[ $(adresse_lesen) == https://demo.example.org && $(php_version) == 8.5 ]]'
if [[ $EUID_ECHT -eq 0 ]]; then
    pruefe "frische Maschine angenommen" '( vorab_pruefen ) >/dev/null 2>&1'
    pruefe "Maschine mit Mandanten abgewiesen" '! ( ATTRAPPE_MANDANTEN="praxis kumero_praxis Praxis" vorab_pruefen ) >/dev/null 2>&1'
    echo "<?php return ['adresse' => 'http://localhost:8080'];" > "$T/etc/kumero.php"
    pruefe "Tunnel-Adresse abgewiesen" '! ( vorab_pruefen ) >/dev/null 2>&1'
    echo "<?php return ['adresse' => 'https://demo.example.org'];" > "$T/etc/kumero.php"
fi
pruefe "erster Lauf legt die Angaben an und hört auf" '( konf_laden ) >/dev/null 2>&1 && [[ -f $T/sw-etc/spielwiese.conf ]]'
pruefe "leere Angaben abgewiesen" '! ( konf_laden ) >/dev/null 2>&1'
cat >> "$T/sw-etc/spielwiese.conf" <<'ANGABEN'
BETREIBER="Muster & Söhne, O'Beispiel \ Co"
IMPRESSUM="https://example.org/impressum"
DATENSCHUTZ="https://example.org/datenschutz"
LAND="Finanzministerium Musterland"
AUSGEHEND_ERLAUBT="192.0.2.10 2001:db8::/32"
ANGABEN
pruefe "ausgefüllte Angaben angenommen" '( konf_laden ) >/dev/null 2>&1'
echo 'IMPRESSUM="http://example.org/x"' >> "$T/sw-etc/spielwiese.conf"
pruefe "Impressum ohne https abgewiesen" '! ( konf_laden ) >/dev/null 2>&1'
sed -i '$d' "$T/sw-etc/spielwiese.conf"
konf_laden >/dev/null
pfoertner_konf "geheim'wort"
pruefe "Konfiguration des Pförtners ist PHP und trägt die Angaben unverändert" 'php -r '"'"'$k = require $argv[1]; exit($k["adresse"] === "https://demo.example.org" && $k["intern"] === "http://127.0.0.1:8081" && $k["texte"]["betreiber"] === "Muster & Söhne, O\u{27}Beispiel \\ Co" && $k["paperless_passphrase"] === "geheim\u{27}wort" && $k["mandant"] === "demo" ? 0 : 1);'"'"' "$T/sw-etc/pfoertner.php"'
pruefe "Der Pförtner liest diese Konfiguration" 'SPIELWIESE_KONFIG="$T/sw-etc/pfoertner.php" php -r '"'"'require $argv[1]; exit(\Kumero\Spielwiese\konfiguration()["buch"] === "haupt" ? 0 : 1);'"'"' "$HIER/spielwiese/lib.php"'
vorlage nginx-spielwiese.conf > "$T/nginx.conf"; vorlage php-fpm-spielwiese.conf > "$T/pool.conf"
pruefe "Vorlagen ohne offene Platzhalter" '! grep -q "@[A-Z_]*@" "$T/nginx.conf" "$T/pool.conf"'
pruefe "nginx: öffentlich nur der Pförtner, kumero nur auf 127.0.0.1" 'grep -q "listen 127.0.0.1:8081;" "$T/nginx.conf" && grep -q "SCRIPT_FILENAME $T/opt/pfoertner.php" "$T/nginx.conf" && [[ $(grep -c "fpm-kumero.sock" "$T/nginx.conf") == 1 ]] && grep -q "server_name demo.example.org;" "$T/nginx.conf"'
pruefe "nginx: kleine Grenze, nicht für Suchmaschinen, Auffang bleibt" 'grep -q "client_max_body_size 5m;" "$T/nginx.conf" && grep -q "X-Robots-Tag" "$T/nginx.conf" && grep -q "ssl_reject_handshake on;" "$T/nginx.conf"'
pruefe "nginx: app.html gibt nur der Pförtner heraus" '! grep -q "try_files \$uri /index.php" <(sed -n "/^# --- öffentlich/,/^# --- nur von dieser Maschine/p" "$T/nginx.conf")'
pruefe "Pool: eigener Benutzer, eigener Socket" 'grep -qx "user = kumero-spielwiese" "$T/pool.conf" && grep -q "php8.5-fpm-kumero-spielwiese.sock" "$T/pool.conf"'
( firewall_setzen ) >/dev/null 2>&1
pruefe "Firewall: alles zu, dann SSH, 80, 443 herein; DNS, Zeit und die genannten Ziele hinaus" 'grep -qx "ufw default deny outgoing" "$T/ufw.log" && grep -qx "ufw default deny incoming" "$T/ufw.log" && grep -qx "ufw allow in 443/tcp" "$T/ufw.log" && grep -qx "ufw allow out to 192.0.2.10" "$T/ufw.log" && grep -qx "ufw allow out to 2001:db8::/32" "$T/ufw.log" && [[ $(tail -n 1 "$T/ufw.log") == "ufw --force enable" ]]'
AUSGEHEND_ERLAUBT='192.0.2.1;reboot'
pruefe "Firewall: Unsinn als Ziel abgewiesen" '! ( firewall_setzen ) >/dev/null 2>&1'
pruefe "Bestätigung: falsches Wort bricht ab, --ja geht" '! ( echo nein | bestaetigen ) >/dev/null 2>&1 && ( bestaetigen --ja )'
pruefe "installer.sh kennt die Spielwiese nicht (SW15)" '! grep -qi "spielwiese" "$HIER/installer.sh" "$HIER/install.sh" "$HIER/aktualisieren.sh"'
pruefe "Der Pförtner lädt keinen Code von kumero (SW17)" '! grep -qE "vendor/autoload|Kumero\\\\(Plattform|Http|Api)" "$HIER"/spielwiese/*.php'
pruefe "Release-Bau lässt die Spielwiese aus (SW18)" 'grep -q "install/spielwiese\"" "$HIER/../werkzeuge/release-bauen.sh"'
echo; echo "$ok Prüfungen grün, $f fehlgeschlagen"; [[ $f -eq 0 ]]
