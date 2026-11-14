#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Spielt die Migrationen des Kerns in eine Mandanten-Datenbank ein (F40).
# Jede Migration läuft in einer eigenen Transaktion als kumero_owner und wird
# mit ihrer SHA-256-Prüfsumme in meta.migration vermerkt. Eine bereits
# eingespielte, aber nachträglich geänderte Migration führt zum Abbruch.
#
# Aufruf: migrieren.sh <datenbank> [weitere psql-Optionen]
# Auf dem Server (Peer-Anmeldung als root → kumero_owner):
#   ./migrieren.sh kumero_vorlage

set -euo pipefail
DB="${1:?Datenbank angeben}"; shift
VERZ="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/migrationen"
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
SQL

for datei in "$VERZ"/[0-9][0-9][0-9][0-9]_*.sql; do
    name="$(basename "$datei")"
    summe="$(sha256sum "$datei" | cut -d' ' -f1)"
    vorhanden="$("${PSQL[@]}" -tAc "SELECT sha256 FROM meta.migration WHERE name = '$name'")"
    if [[ -n "$vorhanden" ]]; then
        [[ "$vorhanden" == "$summe" ]] || { echo "FEHLER: $name wurde nach dem Einspielen verändert." >&2; exit 1; }
        continue
    fi
    echo "spiele ein: $name"
    { echo "BEGIN;"; echo "SET LOCAL ROLE kumero_owner;"; cat "$datei"; echo; echo "INSERT INTO meta.migration (name, sha256) VALUES ('$name', '$summe');"; echo "COMMIT;"; } \
        | "${PSQL[@]}"
done
