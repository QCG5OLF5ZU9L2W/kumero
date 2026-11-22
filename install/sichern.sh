#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Sicherung von kumero in ein Archiv (INSTALL.md Abschnitt 3b, F176).
#
# Aufruf als root:   bash /var/www/kumero/install/sichern.sh [--ziel VERZEICHNIS] [--empfaenger SCHLUESSEL.asc] [--behalten N]
#
# Gesichert wird, was zu kumero gehört – nicht mehr:
#   - jede Datenbank von kumero (Verwaltung, Mandanten, Bücher) mit pg_dump im eigenen Format,
#   - die Konfiguration unter /etc/kumero (Adresse der Installation, Freigabe-Schlüssel, Angaben des Installers).
# Nicht gesichert wird Paperless: Es hält seine Daten selbst, verantwortlich ist, wer es betreibt (F176 Nr. 2). In der
# Sicherung von kumero steht nur, wie kumero Paperless erreicht – das liegt in den Datenbanken.
#
# Das Archiv enthält alle Daten aller Mandanten im Klartext. Mit --empfaenger (oder EMPFAENGER in
# /etc/kumero/sicherung.conf) wird es an einen öffentlichen OpenPGP-Schlüssel verschlüsselt; der private Teil gehört nicht
# auf diese Maschine. Private Schlüssel der Zertifikate (/etc/kumero/tls) kommen nur in ein verschlüsseltes Archiv.
#
# Das Skript bringt nichts von der Maschine weg, fragt nie nach Zugangsdaten und nennt in seiner Ausgabe keine Inhalte.
# Zurückgespielt wird mit install/wiederherstellen.sh – auf einer Maschine mit demselben Hostnamen (F176 Nr. 3).

set -euo pipefail
umask 077
export LC_ALL=C

melde()   { printf '[kumero] %s\n' "$*"; }
abbruch() { printf '[kumero] ABBRUCH: %s\n' "$*" >&2; exit 1; }

ETC="${KUMERO_ETC:-/etc/kumero}"
VERZ="${KUMERO_VERZ:-/var/www/kumero}"
DB_USER="${KUMERO_DB_USER:-kumero_owner}"
ZIEL=/var/lib/kumero/sicherungen
EMPFAENGER=""
BEHALTEN=14
# Nur für Tests: Muster der Datenbanknamen (Vorgabe: alles von kumero außer Testdatenbanken)
MUSTER="${KUMERO_SICHERN_MUSTER:-}"

# Vorgaben der Installation; Angaben beim Aufruf gehen vor
if [[ -f "$ETC/sicherung.conf" ]]; then
    while IFS='=' read -r name wert; do
        case "$name" in
            ZIEL)       ZIEL="$wert" ;;
            EMPFAENGER) EMPFAENGER="$wert" ;;
            BEHALTEN)   BEHALTEN="$wert" ;;
        esac
    done < <(grep -E '^(ZIEL|EMPFAENGER|BEHALTEN)=' "$ETC/sicherung.conf" || true)
fi
while [[ $# -gt 0 ]]; do
    case "$1" in
        --ziel)       ZIEL="${2:?--ziel braucht ein Verzeichnis}"; shift 2 ;;
        --empfaenger) EMPFAENGER="${2:?--empfaenger braucht eine Datei}"; shift 2 ;;
        --behalten)   BEHALTEN="${2:?--behalten braucht eine Zahl}"; shift 2 ;;
        *) abbruch "Unbekannte Angabe: $1" ;;
    esac
done
[[ "$BEHALTEN" =~ ^[0-9]+$ ]] || abbruch "BEHALTEN muss eine Zahl sein (0 = nichts löschen)"
[[ -f "$ETC/kumero.php" ]] || abbruch "$ETC/kumero.php fehlt – ist kumero hier installiert?"
[[ -f "$VERZ/VERSION" ]] || abbruch "$VERZ/VERSION fehlt – ist kumero hier installiert?"
for programm in pg_dump psql php tar sha256sum; do
    command -v "$programm" >/dev/null || abbruch "Das Programm $programm fehlt"
done
if [[ -n "$EMPFAENGER" ]]; then
    command -v gpg >/dev/null || abbruch "Für die Verschlüsselung fehlt gpg"
    [[ -f "$EMPFAENGER" ]] || abbruch "Öffentlicher Schlüssel nicht gefunden: $EMPFAENGER"
    ! grep -q 'PRIVATE KEY' "$EMPFAENGER" || abbruch "$EMPFAENGER enthält einen privaten Schlüssel – der gehört nicht auf diese Maschine"
fi

version="$(tr -d '[:space:]' < "$VERZ/VERSION")"
adresse="$(php -r '$k = require $argv[1]; echo is_array($k) ? ($k["adresse"] ?? "") : "";' "$ETC/kumero.php")"
[[ -n "$adresse" ]] || abbruch "In $ETC/kumero.php steht keine Adresse"
zeit="$(date -u +%Y%m%dT%H%M%SZ)"
install -d -m 0700 "$ZIEL"
arbeit="$(mktemp -d "$ZIEL/.in-arbeit.XXXXXX")"
trap 'rm -rf "$arbeit"' EXIT
mkdir "$arbeit/db"

if [[ -n "$MUSTER" ]]; then
    bedingung="d.datname LIKE '${MUSTER//\'/}'"
else
    bedingung="d.datname NOT LIKE 'kumero\_test%'"
fi
mapfile -t datenbanken < <(psql -X -qAt -U "$DB_USER" -d postgres -c "
    SELECT d.datname FROM pg_database d JOIN pg_roles r ON r.oid = d.datdba
     WHERE r.rolname = 'kumero_owner' AND $bedingung ORDER BY d.datname")
[[ ${#datenbanken[@]} -gt 0 ]] || abbruch "Keine Datenbank von kumero gefunden"

for db in "${datenbanken[@]}"; do
    [[ "$db" =~ ^[a-z0-9_]+$ ]] || abbruch "Unerwarteter Datenbankname – nichts gesichert"
    pg_dump -U "$DB_USER" --format=custom --file="$arbeit/db/$db.dump" "$db" 2>/dev/null \
        || abbruch "pg_dump ist an einer Datenbank gescheitert – nichts gesichert"
done
melde "${#datenbanken[@]} Datenbanken gesichert"

# Konfiguration: alles unter /etc/kumero außer dem, was Paperless gehört; private Schlüssel der Zertifikate nur verschlüsselt
ausnahmen=(--exclude=./paperless --exclude=./tls-paperless)
mit_tls=ja
if [[ -z "$EMPFAENGER" ]]; then
    ausnahmen+=(--exclude=./tls)
    mit_tls=nein
fi
tar -C "$ETC" "${ausnahmen[@]}" -cf "$arbeit/konfiguration.tar" .

{
    echo "format=1"
    echo "kumero=$version"
    echo "zeit=$zeit"
    echo "adresse=$adresse"
    echo "postgresql=$(psql -X -qAt -U "$DB_USER" -d postgres -c 'SHOW server_version_num')"
    echo "zertifikate=$mit_tls"
    for db in "${datenbanken[@]}"; do echo "datenbank=$db"; done
} > "$arbeit/MANIFEST"
( cd "$arbeit" && sha256sum MANIFEST konfiguration.tar db/*.dump > SHA256SUMS )

name="kumero-sicherung-$zeit.tar"
tar -C "$arbeit" -cf "$ZIEL/.$name" MANIFEST SHA256SUMS konfiguration.tar db
if [[ -n "$EMPFAENGER" ]]; then
    gnupg="$(mktemp -d)"
    trap 'rm -rf "$arbeit" "$gnupg"' EXIT
    gpg --batch --quiet --homedir "$gnupg" --trust-model always --recipient-file "$EMPFAENGER" \
        --output "$ZIEL/.$name.gpg" --encrypt "$ZIEL/.$name" 2>/dev/null \
        || { rm -f "$ZIEL/.$name" "$ZIEL/.$name.gpg"; abbruch "Die Verschlüsselung ist gescheitert – nichts gesichert"; }
    rm -f "$ZIEL/.$name"
    name="$name.gpg"
fi
mv "$ZIEL/.$name" "$ZIEL/$name"
melde "Sicherung: $ZIEL/$name ($(du -h "$ZIEL/$name" | cut -f1), SHA-256 $(sha256sum "$ZIEL/$name" | cut -c1-16)…)"
[[ -n "$EMPFAENGER" ]] || melde "ACHTUNG: unverschlüsselt – das Archiv enthält alle Daten aller Mandanten; Zertifikate sind nicht enthalten."

# Alte Sicherungen: nur die jüngsten behalten
if [[ "$BEHALTEN" -gt 0 ]]; then
    mapfile -t alle < <(find "$ZIEL" -maxdepth 1 -type f -name 'kumero-sicherung-*.tar*' -printf '%f\n' | sort -r)
    for alt in "${alle[@]:$BEHALTEN}"; do
        rm -f "$ZIEL/$alt"
        melde "entfernt: $alt"
    done
fi
melde "Die Sicherung liegt auf dieser Maschine – wegbringen muss sie der Betrieb."
