#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Testlauf: legt eine frische Testdatenbank an, spielt die Migrationen ein und
# führt alle Tests aus. Nur synthetische Daten – niemals echte Belege (AGENTS.md).
#
# Voraussetzung: Anmeldung als Superuser (legt Rollen und kumero_test an).
# Auf dem Server:
#   runuser -u postgres -- bash tests/ausfuehren.sh
# Wegwerf-Cluster:
#   PGHOST=/tmp/pgtest PGUSER=postgres bash tests/ausfuehren.sh

set -euo pipefail
HIER="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DB="${KUMERO_TEST_DB:-kumero_test}"
PSQL=(psql -X -q -v ON_ERROR_STOP=1)

db_neu() {
"${PSQL[@]}" -d postgres <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'kumero_owner') THEN
    CREATE ROLE kumero_owner LOGIN CREATEDB; END IF;
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'kumero_app') THEN
    CREATE ROLE kumero_app LOGIN; END IF;
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'kumero_lesen') THEN
    CREATE ROLE kumero_lesen NOLOGIN; END IF;
END \$\$;
DROP DATABASE IF EXISTS $DB;
CREATE DATABASE $DB OWNER kumero_owner TEMPLATE template0 ENCODING 'UTF8';
SQL
"${PSQL[@]}" -d "$DB" -c "REVOKE CREATE ON SCHEMA public FROM PUBLIC; GRANT CONNECT ON DATABASE $DB TO kumero_app, kumero_lesen;"
KUMERO_TEST=1 KUMERO_DB_USER="${PGUSER:-postgres}" bash "$HIER/../migrieren.sh" ${ART:-} "$DB" ${PGHOST:+-h "$PGHOST"} >/dev/null
}

status=0
for t in "$HIER"/[0-9][0-9]_*.sql; do
    echo "── $(basename "$t") (frische Datenbank)"
    # Tests der Verwaltungsdatenbank heißen *_verwaltung*.sql (F37)
    if [[ "$(basename "$t")" == *_verwaltung* ]]; then ART=--verwaltung; else ART=; fi
    db_neu
    # Ergebniszeilen verwerfen; "ok – …" und Fehler kommen über stderr.
    if ! "${PSQL[@]}" -d "$DB" -f "$HIER/rahmen.sql" -f "$t" >/dev/null; then
        status=1
    fi
done
ART=
for t in "$HIER"/[0-9][0-9]_*.sh; do
    echo "── $(basename "$t") (frische Datenbank)"
    db_neu
    DB="$DB" bash "$t" || status=1
done
exit $status
