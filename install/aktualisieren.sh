#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Aktualisiert den installierten Kern auf ein genehmigtes Release (F73).
#
#   sudo ./aktualisieren.sh kumero-<version>.tar.gz [--ja]
#
# Im selben Verzeichnis müssen liegen:
#   kumero-<version>.sha256       Prüfsumme
#   kumero-<version>.sha256.asc   Signatur des Projektverantwortlichen
#
# Ablauf:
#   1. Signatur prüfen gegen /etc/kumero/freigabe-schluessel.gpg (nur dort
#      hinterlegte Schlüssel gelten als Genehmigung)
#   2. Prüfsumme des Archivs prüfen
#   3. Kein Rückschritt: neue Version > installierte Version
#   4. Release nach /var/lib/kumero/releases/<version> entpacken (nur root)
#   5. Anstehende Migrationen je Datenbank anzeigen, Bestätigung abfragen
#      (entfällt mit --ja; die Signatur ist die eigentliche Genehmigung)
#   6. Migrationen einspielen, Installation in meta.version vermerken
#   7. Anwendung umschalten
#   8. Bestand überführen (ab 0.7.30, F126; direkt von 0.7.22 oder neuer): aus jeder Datenbank eines Mandanten wird sein erstes Buch
#      `kumero_<mandant>__haupt`, die Nutzer ziehen in die neue Datenbank des Mandanten –
#      `bin/kumero system:buecher-ueberfuehren`, wiederholbar. Vorher sichern (INSTALL.md Abschnitt 4).

set -euo pipefail
ARCHIV="${1:?Pfad zu kumero-<version>.tar.gz angeben}"
JA="${2:-}"
SCHLUESSEL="${KUMERO_FREIGABE_KEYRING:-/etc/kumero/freigabe-schluessel.gpg}"
RELEASES="${KUMERO_RELEASES:-/var/lib/kumero/releases}"
STAND="${KUMERO_STAND:-/var/lib/kumero/installiert}"

melde()   { printf '\033[1;34m[kumero]\033[0m %s\n' "$*"; }
abbruch() { printf '\033[1;31m[kumero] ABBRUCH:\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 || -n "${KUMERO_TEST:-}" ]] || abbruch "Bitte als root ausführen."
[[ -f "$ARCHIV" ]] || abbruch "Archiv nicht gefunden: $ARCHIV"
name="$(basename "$ARCHIV")"
[[ "$name" =~ ^kumero-([0-9]+\.[0-9]+\.[0-9]+)\.tar\.gz$ ]] || abbruch "Unerwarteter Dateiname: $name"
VERSION="${BASH_REMATCH[1]}"
verz="$(cd "$(dirname "$ARCHIV")" && pwd)"
summen="$verz/kumero-$VERSION.sha256"
signatur="$summen.asc"

# 1. Genehmigung
command -v gpgv >/dev/null || abbruch "gpgv fehlt (Debian 13: apt-get install gpgv, oder install.sh erneut ausführen)."
[[ -f "$SCHLUESSEL" ]] || abbruch "Kein Freigabe-Schlüssel hinterlegt ($SCHLUESSEL). Siehe install/README.md."
[[ -f "$summen" && -f "$signatur" ]] || abbruch "Prüfsumme oder Signatur fehlt – Release ist nicht genehmigt."
gpg_meldung="$(mktemp)"
if ! status="$(gpgv --status-fd 1 --keyring "$SCHLUESSEL" "$signatur" "$summen" 2>"$gpg_meldung")"; then
    sed 's/^/    /' "$gpg_meldung" >&2; rm -f "$gpg_meldung"
    abbruch "Signatur ungültig oder nicht von einem hinterlegten Freigabe-Schlüssel – nicht genehmigt."
fi
rm -f "$gpg_meldung"
fingerabdruck="$(awk '/\[GNUPG:\] VALIDSIG/ {print $3; exit}' <<<"$status")"
[[ -n "$fingerabdruck" ]] || abbruch "Signatur konnte nicht zugeordnet werden."
melde "Genehmigt durch Schlüssel $fingerabdruck"

# 2. Unversehrtheit
(cd "$verz" && sha256sum --quiet -c "kumero-$VERSION.sha256") || abbruch "Prüfsumme stimmt nicht – Archiv verändert."
release_sha="$(cut -d' ' -f1 "$summen")"

# 3. Kein Rückschritt
installiert="$(cat "$STAND" 2>/dev/null || echo 0.0.0)"
if [[ "$installiert" == "$VERSION" ]]; then
    melde "Version $VERSION ist bereits installiert."
elif [[ "$(printf '%s\n%s\n' "$installiert" "$VERSION" | sort -V | tail -1)" != "$VERSION" ]]; then
    abbruch "Rückschritt von $installiert auf $VERSION ist nicht erlaubt."
elif [[ "$installiert" != 0.0.0 && "$(printf '%s\n0.7.22\n' "$installiert" | sort -V | head -1)" != 0.7.22 ]]; then
    # Seit 0.7.30 (F126) gibt es das Überführen der Nutzer von 0.7.22 nicht mehr; ab 0.7.22 spielen die Migrationen
    # den Stand von 0.7.29 ein, von dem aus die Bücher überführt werden
    abbruch "Von $installiert führt kein direkter Weg auf $VERSION – erst 0.7.29 einspielen (INSTALL.md Abschnitt 4)."
fi

# 4. Entpacken (unveränderlich ablegen)
ziel="$RELEASES/$VERSION"
if [[ ! -d "$ziel" ]]; then
    install -d -m 0755 "$RELEASES"
    tmp="$(mktemp -d "$RELEASES/.neu.XXXXXX")"
    tar -xzf "$ARCHIV" -C "$tmp" --no-same-owner
    [[ "$(cat "$tmp/kumero-$VERSION/VERSION")" == "$VERSION" ]] || abbruch "VERSION im Archiv passt nicht."
    mv "$tmp/kumero-$VERSION" "$ziel"; rmdir "$tmp"
    chmod -R a-w "$ziel"
fi
melde "Release liegt in $ziel"

# 4a. Übergabe: Die weiteren Schritte führt das Skript aus dem gerade geprüften
#     Release aus. So greifen Änderungen am Update-Ablauf sofort, auch wenn eine
#     ältere Kopie dieses Skripts aufgerufen wurde. Das Release-Skript prüft
#     Signatur und Prüfsumme erneut.
neu="$ziel/install/aktualisieren.sh"
if [[ -z "${KUMERO_UEBERGEBEN:-}" && -f "$neu" ]] && ! cmp -s "${BASH_SOURCE[0]}" "$neu"; then
    melde "Übergabe an aktualisieren.sh aus Release $VERSION"
    KUMERO_UEBERGEBEN=1 exec bash "$neu" "$ARCHIV" ${JA:+"$JA"}
fi

# 5. Plan
PSQL=(psql -X -qtA -U "${KUMERO_DB_USER:-kumero_owner}")
mapfile -t dbs < <("${PSQL[@]}" -d postgres -c "
    SELECT d.datname FROM pg_database d JOIN pg_roles r ON r.oid = d.datdba
     WHERE r.rolname = 'kumero_owner' AND d.datname <> 'kumero_verwaltung'
       AND d.datname NOT LIKE 'kumero_test%' ORDER BY d.datname")
((${#dbs[@]})) || abbruch "Keine Mandanten-Datenbanken gefunden."
# Seit 0.7.30 (F126) gibt es zwei Arten: die Datenbank des Mandanten (Schema „mandant“) und die seiner Bücher (Schema „kern“)
declare -A art=()
for db in "${dbs[@]}"; do
    if [[ "$("${PSQL[@]}" -d "$db" -c "SELECT to_regnamespace('mandant') IS NOT NULL AND to_regnamespace('kern') IS NULL")" == t ]]; then art[$db]=mandant; else art[$db]=buch; fi
done
melde "Anstehende Änderungen am Kern ($installiert → $VERSION):"
# Je Mandant die aktiven Module (K6) und ihre Migrationen
declare -A module=()
for db in "${dbs[@]}"; do
    if [[ "${art[$db]}" == mandant ]]; then
        bash "$ziel/db/migrieren.sh" --plan --mandant "$db"
        # Module am Mandanten (F135) – die Tabelle gibt es ab 0.7.42
        if [[ "$("${PSQL[@]}" -d "$db" -c "SELECT to_regclass('mandant.modul') IS NOT NULL")" == t ]]; then
            module[$db]="$("${PSQL[@]}" -d "$db" -c "SELECT string_agg(name, ' ' ORDER BY name) FROM mandant.modul WHERE aktiv")"
        fi
        for m in ${module[$db]:-}; do
            [[ -d "$ziel/module/$m/migrationen" ]] && bash "$ziel/db/migrieren.sh" --plan --modul "$m" "$db"
        done
        continue
    fi
    bash "$ziel/db/migrieren.sh" --plan "$db"
    if [[ "$("${PSQL[@]}" -d "$db" -c "SELECT to_regclass('kern.modul') IS NOT NULL")" == t ]]; then
        module[$db]="$("${PSQL[@]}" -d "$db" -c "SELECT string_agg(name, ' ' ORDER BY name) FROM kern.modul WHERE aktiv AND name <> 'kern'")"
    fi
    for m in ${module[$db]:-}; do
        [[ -d "$ziel/module/$m/migrationen" ]] && bash "$ziel/db/migrieren.sh" --plan --modul "$m" "$db"
    done
done
# Verwaltungsdatenbank (F37) hat eigene Migrationen (ab 0.4.0)
VERWALTUNG=0
if [[ -d "$ziel/db/verwaltung" ]] && "${PSQL[@]}" -d postgres -c "SELECT 1 FROM pg_database WHERE datname = 'kumero_verwaltung'" | grep -q 1; then
    VERWALTUNG=1
    bash "$ziel/db/migrieren.sh" --plan --verwaltung kumero_verwaltung
fi

if [[ "$JA" != "--ja" ]]; then
    read -r -p "Zum Einspielen die Version eintippen ($VERSION): " antwort
    [[ "$antwort" == "$VERSION" ]] || abbruch "Nicht bestätigt – nichts geändert."
fi

# 6. Einspielen
for db in "${dbs[@]}"; do
    if [[ "${art[$db]}" == mandant ]]; then
        KUMERO_FREIGABE="$VERSION" KUMERO_RELEASE_SHA256="$release_sha" KUMERO_FREIGABE_SCHLUESSEL="$fingerabdruck" \
            bash "$ziel/db/migrieren.sh" --mandant "$db"
        for m in ${module[$db]:-}; do
            if [[ -d "$ziel/module/$m/migrationen" ]]; then
                KUMERO_FREIGABE="$VERSION" KUMERO_RELEASE_SHA256="$release_sha" KUMERO_FREIGABE_SCHLUESSEL="$fingerabdruck" \
                    bash "$ziel/db/migrieren.sh" --modul "$m" "$db"
            fi
        done
        continue
    fi
    KUMERO_FREIGABE="$VERSION" KUMERO_RELEASE_SHA256="$release_sha" KUMERO_FREIGABE_SCHLUESSEL="$fingerabdruck" \
        bash "$ziel/db/migrieren.sh" "$db"
    for m in ${module[$db]:-}; do
        if [[ -d "$ziel/module/$m/migrationen" ]]; then
            KUMERO_FREIGABE="$VERSION" KUMERO_RELEASE_SHA256="$release_sha" KUMERO_FREIGABE_SCHLUESSEL="$fingerabdruck" \
                bash "$ziel/db/migrieren.sh" --modul "$m" "$db"
        fi
    done
done
if ((VERWALTUNG)); then
    KUMERO_FREIGABE="$VERSION" KUMERO_RELEASE_SHA256="$release_sha" KUMERO_FREIGABE_SCHLUESSEL="$fingerabdruck" \
        bash "$ziel/db/migrieren.sh" --verwaltung kumero_verwaltung
fi
# 7. Anwendung umschalten (E7): /var/www/kumero zeigt auf das aktive Release.
#    Erst nach erfolgreicher Migration; bis dahin läuft das alte Release weiter.
VERZ="${KUMERO_VERZ:-/var/www/kumero}"
if [[ -f "$ziel/public/index.php" ]]; then
    [[ -f "$ziel/vendor/autoload.php" ]] || abbruch "Release ohne vendor/ – nicht umgeschaltet (E1)."
    chmod 0751 "$(dirname "$RELEASES")" 2>/dev/null || true      # Durchgang für nginx
    if [[ -d "$VERZ" && ! -L "$VERZ" ]]; then
        mv "$VERZ" "$VERZ.platzhalter-$(date +%Y%m%d%H%M%S)"
    fi
    ln -sfn "$ziel" "$VERZ.neu" && mv -Tf "$VERZ.neu" "$VERZ"
    melde "Anwendung zeigt auf $ziel"
    if [[ -z "${KUMERO_TEST:-}" ]]; then
        systemctl reload 'php*-fpm.service' 2>/dev/null || true
        if [[ -f /etc/kumero/kumero.php ]]; then
            # 8. Bestand überführen (F126): jede Datenbank eines Mandanten wird sein erstes Buch „haupt“, die Nutzer
            #    ziehen in die neue Datenbank des Mandanten. Datenbanken werden umbenannt – deshalb ein Befehl nach
            #    den Migrationen statt einer SQL-Migration. Wiederholbar; findet nach dem ersten Lauf nichts mehr.
            #    Die Dienste halten Verbindungen offen und stehen solange still. Bis er durch ist, meldet sich niemand an.
            if ((VERWALTUNG)); then
                melde "Bestand überführen: Mandanten bekommen ihr erstes Buch (F126)"
                dienste=(kumero-auftraege.timer kumero-auftraege.service)
                systemctl stop "${dienste[@]}" 2>/dev/null || true
                KUMERO_FREIGABE="$VERSION" KUMERO_RELEASE_SHA256="$release_sha" KUMERO_FREIGABE_SCHLUESSEL="$fingerabdruck" \
                    php "$ziel/bin/kumero" system:buecher-ueberfuehren \
                    || melde "ACHTUNG: Überführen unvollständig – Ursache beheben, dann: php $VERZ/bin/kumero system:buecher-ueberfuehren (Notausgang für Verwalter: verwalter:liste, verwalter:link)"
                systemctl start kumero-auftraege.timer 2>/dev/null || true
            fi
            # 9. Aktive Module nachziehen (F142): Die Migrationen sind oben gelaufen; hier kommen Version, neue Rechte und
            #    die Bausteine der Rollen dazu – am Mandanten und in jedem Buch. Vorher war das je Modul ein Handgriff.
            melde "Aktive Module nachziehen"
            php "$ziel/bin/kumero" modul:nachziehen \
                || melde "ACHTUNG: Nicht jedes Modul ließ sich nachziehen – in der Oberfläche unter Mandant → Module „Alle nachziehen“ oder: php $VERZ/bin/kumero modul:nachziehen"
            php "$ziel/bin/kumero" selbsttest || melde "Selbsttest meldet Fehler – siehe oben."
        fi
    fi
fi

install -d -m 0755 "$(dirname "$STAND")"
echo "$VERSION" > "$STAND"
melde "Kern ist auf Version $VERSION."
