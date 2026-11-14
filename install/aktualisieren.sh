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

# 5. Plan
PSQL=(psql -X -qtA -U "${KUMERO_DB_USER:-kumero_owner}")
mapfile -t dbs < <("${PSQL[@]}" -d postgres -c "
    SELECT d.datname FROM pg_database d JOIN pg_roles r ON r.oid = d.datdba
     WHERE r.rolname = 'kumero_owner' AND d.datname <> 'kumero_verwaltung'
       AND d.datname NOT LIKE 'kumero_test%' ORDER BY d.datname")
((${#dbs[@]})) || abbruch "Keine Mandanten-Datenbanken gefunden."
melde "Anstehende Änderungen am Kern ($installiert → $VERSION):"
for db in "${dbs[@]}"; do bash "$ziel/db/migrieren.sh" --plan "$db"; done
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
    KUMERO_FREIGABE="$VERSION" KUMERO_RELEASE_SHA256="$release_sha" KUMERO_FREIGABE_SCHLUESSEL="$fingerabdruck" \
        bash "$ziel/db/migrieren.sh" "$db"
done
if ((VERWALTUNG)); then
    KUMERO_FREIGABE="$VERSION" KUMERO_RELEASE_SHA256="$release_sha" KUMERO_FREIGABE_SCHLUESSEL="$fingerabdruck" \
        bash "$ziel/db/migrieren.sh" --verwaltung kumero_verwaltung
fi
install -d -m 0755 "$(dirname "$STAND")"
echo "$VERSION" > "$STAND"
melde "Kern ist auf Version $VERSION."
