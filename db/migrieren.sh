#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Spielt die Migrationen des Kerns in eine Mandanten-Datenbank ein (F40).
#
# Jede Migration läuft in einer eigenen Transaktion als kumero_owner und wird
# mit ihrer SHA-256-Prüfsumme in meta.migration vermerkt. Eine bereits
# eingespielte, aber nachträglich geänderte Migration führt zum Abbruch.
#
# Änderungen am installierten Kern nur mit Genehmigung (F73):
#   Neue Migrationen werden nur eingespielt, wenn dieses Skript von
#   install/aktualisieren.sh nach geprüfter Freigabe-Signatur aufgerufen wird
#   (KUMERO_FREIGABE = Version des Releases). Ausnahme: Testdatenbanken
#   kumero_test* mit KUMERO_TEST=1.
#
# Aufruf: migrieren.sh [--plan] [--verwaltung] <datenbank> [weitere psql-Optionen]
#   --plan         zeigt nur, welche Migrationen fehlen; ändert nichts
#   --verwaltung   Migrationen der Verwaltungsdatenbank (db/verwaltung, F37)
#                  statt der des Kerns
#   --modul NAME   Migrationen des Moduls module/NAME/migrationen (K6); in
#                  meta.migration mit dem Präfix „NAME/“ vermerkt

set -euo pipefail
PLAN=0
ART=migrationen
MODUL=
while [[ "${1:-}" == --* ]]; do
    case "$1" in
        --plan)       PLAN=1 ;;
        --verwaltung) ART=verwaltung ;;
        --modul)      MODUL="${2:?Modulname fehlt}"; shift ;;
        *) echo "Unbekannte Option: $1" >&2; exit 2 ;;
    esac
    shift
done
DB="${1:?Datenbank angeben}"; shift
BASIS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VERZ="$BASIS/$ART"
PRAEFIX=
if [[ -n "$MODUL" ]]; then
    [[ "$MODUL" =~ ^[a-z][a-z0-9-]{0,31}$ ]] || { echo "Modulname ungültig: $MODUL" >&2; exit 2; }
    VERZ="$BASIS/../module/$MODUL/migrationen"
    PRAEFIX="$MODUL/"
    [[ -d "$VERZ" ]] || { echo "Modul $MODUL hat keine Migrationen ($VERZ)" >&2; exit 2; }
fi
VERSION="$(cat "$BASIS/../VERSION" 2>/dev/null || echo unbekannt)"
# Anmeldung standardmäßig als kumero_owner (auf dem Server: root → kumero_owner
# per Peer). Für Tests kann ein Superuser angemeldet sein; alle Objekte werden
# trotzdem per SET ROLE als kumero_owner angelegt und gehören damit ihm.
PSQL=(psql -X -q -v ON_ERROR_STOP=1 -U "${KUMERO_DB_USER:-kumero_owner}" -d "$DB" "$@")

"${PSQL[@]}" <<'SQL'
SET client_min_messages = warning;
SET ROLE kumero_owner;
CREATE SCHEMA IF NOT EXISTS meta;
CREATE TABLE IF NOT EXISTS meta.migration (
    name        text PRIMARY KEY,
    sha256      text NOT NULL,
    eingespielt timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE IF NOT EXISTS meta.version (
    version             text NOT NULL,
    release_sha256      text NOT NULL,
    freigabe_schluessel text NOT NULL,     -- Fingerabdruck des genehmigenden Schlüssels
    installiert_am      timestamptz NOT NULL DEFAULT now(),
    installiert_von     text NOT NULL DEFAULT session_user,
    PRIMARY KEY (version, installiert_am)
);
SQL

# Fehlende Migrationen ermitteln; veränderte bereits eingespielte → Abbruch
offen=()
for datei in "$VERZ"/[0-9][0-9][0-9][0-9]_*.sql; do
    name="$PRAEFIX$(basename "$datei")"
    summe="$(sha256sum "$datei" | cut -d' ' -f1)"
    vorhanden="$("${PSQL[@]}" -tAc "SELECT sha256 FROM meta.migration WHERE name = '$name'")"
    if [[ -n "$vorhanden" ]]; then
        [[ "$vorhanden" == "$summe" ]] || { echo "FEHLER: $name wurde nach dem Einspielen verändert." >&2; exit 1; }
        continue
    fi
    offen+=("$datei")
done

if ((PLAN)); then
    if ((${#offen[@]} == 0)); then echo "  $DB: aktuell"; else
        for d in "${offen[@]}"; do echo "  $DB: $(basename "$d")"; done
    fi
    exit 0
fi

if ((${#offen[@]})); then
    if [[ "${KUMERO_TEST:-}" == 1 && "$DB" == kumero_test* ]]; then
        :   # Testdatenbank
    elif [[ -z "${KUMERO_FREIGABE:-}" ]]; then
        echo "ABBRUCH: Änderungen am installierten Kern nur über install/aktualisieren.sh" >&2
        echo "         mit einem genehmigten (signierten) Release (F73)." >&2
        exit 2
    elif [[ "$KUMERO_FREIGABE" != "$VERSION" ]]; then
        echo "ABBRUCH: Freigabe gilt für Version $KUMERO_FREIGABE, diese Migrationen gehören zu $VERSION." >&2
        exit 2
    fi
fi

for datei in "${offen[@]}"; do
    name="$PRAEFIX$(basename "$datei")"
    summe="$(sha256sum "$datei" | cut -d' ' -f1)"
    echo "spiele ein: $DB: $name"
    { echo "BEGIN;"; echo "SET LOCAL ROLE kumero_owner;"; cat "$datei"; echo
      echo "INSERT INTO meta.migration (name, sha256) VALUES ('$name', '$summe');"; echo "COMMIT;"; } \
        | "${PSQL[@]}"
done

if [[ -n "${KUMERO_FREIGABE:-}" ]]; then
    "${PSQL[@]}" -c "SET ROLE kumero_owner;" \
        -c "INSERT INTO meta.version (version, release_sha256, freigabe_schluessel)
            SELECT '$KUMERO_FREIGABE', '${KUMERO_RELEASE_SHA256:?}', '${KUMERO_FREIGABE_SCHLUESSEL:?}'
             WHERE NOT EXISTS (SELECT 1 FROM meta.version
                                WHERE version = '$KUMERO_FREIGABE' AND release_sha256 = '$KUMERO_RELEASE_SHA256')" >/dev/null
fi
