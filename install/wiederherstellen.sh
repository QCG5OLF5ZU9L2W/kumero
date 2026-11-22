#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Spielt eine Sicherung von kumero auf einer frisch installierten Maschine ein (INSTALL.md Abschnitt 3b, F176).
#
# Aufruf als root:   bash /var/www/kumero/install/wiederherstellen.sh ARCHIV [--ja]
#
# Voraussetzungen – das Skript prüft sie und ändert nichts, wenn eine fehlt:
#   - kumero ist hier in derselben Version installiert wie in der Sicherung,
#   - die Maschine läuft unter demselben Hostnamen (Adresse in /etc/kumero/kumero.php): Passkeys sind an ihn gebunden,
#   - hier liegt noch kein Mandant – eine Sicherung wird nie über einen Bestand gespielt.
# Ein verschlüsseltes Archiv (.gpg) entschlüsselt das Skript nur, wenn der private Schlüssel im Schlüsselbund liegt;
# sonst vorher auf dem Rechner mit dem Schlüssel entschlüsseln und das .tar herüberbringen.
#
# Zurückgespielt werden die Datenbanken von kumero und die Konfiguration unter /etc/kumero. Paperless gehört nicht dazu
# (F176 Nr. 2). Die Ausgabe nennt keine Inhalte.

set -euo pipefail
umask 077
export LC_ALL=C

melde()   { printf '[kumero] %s\n' "$*"; }
abbruch() { printf '[kumero] ABBRUCH: %s\n' "$*" >&2; exit 1; }

ETC="${KUMERO_ETC:-/etc/kumero}"
VERZ="${KUMERO_VERZ:-/var/www/kumero}"
DB_USER="${KUMERO_DB_USER:-kumero_owner}"
# Zurückspielen braucht den Verwalter der Datenbank (Eigentümer und Rechte bleiben erhalten): als Systembenutzer postgres,
# in Tests über KUMERO_PG_ADMIN als Rolle am Socket
als_verwalter() {
    local programm="$1"; shift
    if [[ -n "${KUMERO_PG_ADMIN:-}" ]]; then
        "$programm" -U "$KUMERO_PG_ADMIN" "$@"
    else
        runuser -u postgres -- "$programm" "$@"
    fi
}

archiv=""; ja=nein
while [[ $# -gt 0 ]]; do
    case "$1" in
        --ja) ja=ja; shift ;;
        -*)   abbruch "Unbekannte Angabe: $1" ;;
        *)    [[ -z "$archiv" ]] || abbruch "Nur ein Archiv angeben"; archiv="$1"; shift ;;
    esac
done
[[ -n "$archiv" && -f "$archiv" ]] || abbruch "Aufruf: wiederherstellen.sh ARCHIV [--ja]"
[[ -f "$ETC/kumero.php" && -f "$VERZ/VERSION" ]] || abbruch "kumero ist hier nicht installiert – erst installieren, dann zurückspielen"
for programm in pg_restore psql php tar sha256sum; do
    command -v "$programm" >/dev/null || abbruch "Das Programm $programm fehlt"
done

arbeit="$(mktemp -d)"
trap 'rm -rf "$arbeit"' EXIT
if [[ "$archiv" == *.gpg ]]; then
    command -v gpg >/dev/null || abbruch "Für das verschlüsselte Archiv fehlt gpg"
    gpg --quiet --output "$arbeit/archiv.tar" --decrypt "$archiv" \
        || abbruch "Das Archiv ließ sich nicht entschlüsseln – der private Schlüssel fehlt hier. Auf dem Rechner mit dem Schlüssel entschlüsseln (gpg --decrypt) und das .tar herüberbringen."
    archiv="$arbeit/archiv.tar"
fi

# Nur die erwarteten Namen auspacken; nichts aus dem Archiv wird ausgeführt
mkdir "$arbeit/inhalt"
while IFS= read -r eintrag; do
    [[ "$eintrag" =~ ^(MANIFEST|SHA256SUMS|konfiguration\.tar|db/?|db/[a-z0-9_]+\.dump)$ ]] \
        || abbruch "Das Archiv enthält Unerwartetes – nichts geändert"
done < <(tar -tf "$archiv")
tar -C "$arbeit/inhalt" --no-same-owner -xf "$archiv"
( cd "$arbeit/inhalt" && sha256sum --quiet -c SHA256SUMS >/dev/null 2>&1 ) || abbruch "Die Prüfsummen des Archivs stimmen nicht – nichts geändert"

wert() { sed -n "s/^$1=//p" "$arbeit/inhalt/MANIFEST" | head -n 1; }
[[ "$(wert format)" == 1 ]] || abbruch "Unbekanntes Format der Sicherung"
version_archiv="$(wert kumero)"; adresse_archiv="$(wert adresse)"; pg_archiv="$(wert postgresql)"
mapfile -t datenbanken < <(sed -n 's/^datenbank=//p' "$arbeit/inhalt/MANIFEST")
[[ ${#datenbanken[@]} -gt 0 ]] || abbruch "Die Sicherung nennt keine Datenbank"
for db in "${datenbanken[@]}"; do
    [[ "$db" =~ ^[a-z0-9_]+$ && -f "$arbeit/inhalt/db/$db.dump" ]] || abbruch "Die Sicherung ist unvollständig – nichts geändert"
done
grep -q "  db/" "$arbeit/inhalt/SHA256SUMS" || abbruch "Die Sicherung ist unvollständig – nichts geändert"
[[ "$(grep -c '  db/' "$arbeit/inhalt/SHA256SUMS")" == "${#datenbanken[@]}" ]] || abbruch "Die Sicherung ist unvollständig – nichts geändert"

version_hier="$(tr -d '[:space:]' < "$VERZ/VERSION")"
[[ "$version_hier" == "$version_archiv" ]] \
    || abbruch "Hier ist kumero $version_hier installiert, die Sicherung stammt von $version_archiv. Erst dieselbe Version installieren, zurückspielen, danach aktualisieren."
adresse_hier="$(php -r '$k = require $argv[1]; echo is_array($k) ? ($k["adresse"] ?? "") : "";' "$ETC/kumero.php")"
[[ "$adresse_hier" == "$adresse_archiv" ]] \
    || abbruch "Diese Maschine läuft unter $adresse_hier, die Sicherung gehört zu $adresse_archiv. Nach einer Wiederherstellung muss die Maschine unter demselben Hostnamen laufen (F176) – sonst gilt kein Passkey mehr."
pg_hier="$(als_verwalter psql -X -qAt -d postgres -c 'SHOW server_version_num')"
[[ "$pg_hier" -ge "${pg_archiv:-0}" || "${pg_hier:0:2}" == "${pg_archiv:0:2}" ]] \
    || abbruch "PostgreSQL ist hier älter als in der Sicherung – nichts geändert"

# Kein Bestand: höchstens die leere Verwaltung und die Vorlage einer frischen Installation
verwaltung="$(php -r '$k = require $argv[1]; echo $k["verwaltung_db"] ?? "kumero_verwaltung";' "$ETC/kumero.php")"
if [[ -n "${KUMERO_SICHERN_MUSTER:-}" ]]; then        # nur für Tests
    bedingung="d.datname LIKE '${KUMERO_SICHERN_MUSTER//\'/}'"
else
    bedingung="d.datname NOT LIKE 'kumero\_test%'"
fi
mapfile -t vorhanden < <(als_verwalter psql -X -qAt -d postgres -c "
    SELECT d.datname FROM pg_database d JOIN pg_roles r ON r.oid = d.datdba
     WHERE r.rolname = 'kumero_owner' AND $bedingung ORDER BY 1")
for db in "${vorhanden[@]}"; do
    if [[ "$db" == "$verwaltung" ]]; then
        n="$(als_verwalter psql -X -qAt -d "$db" -c "SELECT count(*) FROM verwaltung.mandant" 2>/dev/null || echo "?")"
        [[ "$n" == 0 ]] || abbruch "Hier liegen schon Mandanten – eine Sicherung wird nie über einen Bestand gespielt"
    elif [[ "$db" != kumero_vorlage ]]; then
        abbruch "Hier liegen schon Datenbanken von Mandanten – eine Sicherung wird nie über einen Bestand gespielt"
    fi
done

melde "Sicherung von kumero $version_archiv, ${#datenbanken[@]} Datenbanken, Adresse $adresse_archiv"
if [[ "$ja" != ja ]]; then
    read -r -p "[kumero] Zum Zurückspielen „wiederherstellen“ eingeben: " antwort </dev/tty || antwort=""
    [[ "$antwort" == wiederherstellen ]] || abbruch "Nichts geändert"
fi

dienste=()
if command -v systemctl >/dev/null && [[ -d /run/systemd/system ]]; then
    for d in kumero-auftraege.timer kumero-sicherung.timer; do
        systemctl is-active --quiet "$d" 2>/dev/null && { systemctl stop "$d"; dienste+=("$d"); } || true
    done
    mapfile -t fpm < <(systemctl list-units --type=service --state=active --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}')
    for d in "${fpm[@]}"; do systemctl stop "$d"; dienste+=("$d"); done
fi

for db in "${vorhanden[@]}"; do
    als_verwalter psql -X -q -d postgres -c "ALTER DATABASE \"$db\" IS_TEMPLATE false" -c "DROP DATABASE \"$db\"" >/dev/null
done
for db in "${datenbanken[@]}"; do
    # Der Verwalter der Datenbank liest die Datei über die Standardeingabe – das Arbeitsverzeichnis gehört root
    als_verwalter pg_restore --create --exit-on-error -d postgres < "$arbeit/inhalt/db/$db.dump" >/dev/null 2>&1 \
        || abbruch "Das Zurückspielen ist an einer Datenbank gescheitert – die Maschine ist in einem Zwischenstand: Datenbanken von kumero entfernen und erneut beginnen"
done
melde "${#datenbanken[@]} Datenbanken zurückgespielt"

# Konfiguration: den Stand der frischen Installation beiseitelegen, dann den gesicherten einspielen
beiseite="$ETC.vor-wiederherstellung-$(date -u +%Y%m%dT%H%M%SZ)"
cp -a "$ETC" "$beiseite"
tar -C "$ETC" --no-same-owner -xf "$arbeit/inhalt/konfiguration.tar"
melde "Konfiguration eingespielt (vorheriger Stand: $beiseite)"
[[ "$(wert zertifikate)" == ja ]] || melde "Zertifikate waren nicht in der Sicherung – $ETC/tls bleibt, wie es hier ist."

for d in "${dienste[@]}"; do systemctl start "$d" || melde "ACHTUNG: $d ließ sich nicht starten"; done

if [[ -x "$VERZ/bin/kumero" ]]; then
    "$VERZ/bin/kumero" selbsttest || abbruch "Der Selbsttest meldet einen Fehler – erst klären, dann in Betrieb nehmen"
fi
melde "Fertig. Die Maschine muss unter $adresse_archiv erreichbar sein – Passkeys gelten nur dort."
melde "Paperless ist nicht Teil dieser Sicherung."
