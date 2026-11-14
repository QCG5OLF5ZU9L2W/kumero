# SPDX-License-Identifier: AGPL-3.0-or-later
# Schritt 30: PostgreSQL (F54, F17, F64)
#
# Datenbankrollen (keine Passwörter, Anmeldung nur lokal per Peer über den
# Unix-Socket):
#   aequa_owner  – besitzt alle Objekte; führt Migrationen aus.
#                  Anmeldung nur für den Systembenutzer root.
#   aequa_app    – Rolle der Anwendung; erhält später nur SELECT und EXECUTE
#                  (F64). Anmeldung nur für den Systembenutzer aequa.
#   aequa_lesen  – nur lesend (Prüfer-Zugriff, Auswertungen); NOLOGIN, wird
#                  bei Bedarf einzelnen Rollen zugewiesen.
#
# Datenbanken:
#   aequa_verwaltung – zentrale Verwaltungsdatenbank (F37)
#   aequa_vorlage    – Vorlage für neue Mandanten (F40); das Schema wird später
#                      von den Migrationen eingespielt.

pakete "postgresql-${PG_VERSION}" "postgresql-client-${PG_VERSION}"

# Debian legt den Cluster ${PG_VERSION}/main automatisch an.
if ! pg_lsclusters -h | awk '{print $1"/"$2}' | grep -qx "${PG_VERSION}/main"; then
    pg_createcluster --start "$PG_VERSION" main
fi
systemctl enable --now "postgresql@${PG_VERSION}-main" >/dev/null

PG_KONF="/etc/postgresql/${PG_VERSION}/main"

# Debian-Weg für eigene Einstellungen: conf.d (wird von postgresql.conf eingebunden).
datei_schreiben "$PG_KONF/conf.d/50-aequa.conf" 0644 postgres:postgres <<'EOF' && pg_neu_laden=1 || true
# Aequa – PostgreSQL-Einstellungen
# Nur lokale Verbindungen über den Unix-Socket.
listen_addresses = ''
# Zeitpunkte werden in UTC gespeichert und verglichen.
timezone = 'UTC'
log_timezone = 'UTC'
# Protokollierung von Rechteverstößen und langsamen Abfragen.
log_min_error_statement = error
log_min_duration_statement = 2000
# Datenprüfsummen sind ab PostgreSQL 18 bei neuen Clustern standardmäßig aktiv.
EOF

# Peer-Anmeldung mit Zuordnung Systembenutzer → Datenbankrolle.
block_setzen "$PG_KONF/pg_ident.conf" "zuordnung" <<'EOF' && pg_neu_laden=1 || true
# MAPNAME       SYSTEM-USER   PG-USER
aequa_app       aequa         aequa_app
aequa_owner     root          aequa_owner
EOF

block_setzen "$PG_KONF/pg_hba.conf" "zugang" <<'EOF' && pg_neu_laden=1 || true
# Aequa: nur lokal, nur Peer, nur zugeordnete Systembenutzer
local   all   aequa_app     peer map=aequa_app
local   all   aequa_owner   peer map=aequa_owner
EOF
chown postgres:postgres "$PG_KONF/pg_hba.conf" "$PG_KONF/pg_ident.conf"

if [[ "${pg_neu_laden:-0}" == 1 ]]; then
    systemctl reload "postgresql@${PG_VERSION}-main"
fi

psql_su() { (cd / && runuser -u postgres -- psql -X -q -v ON_ERROR_STOP=1 --cluster "${PG_VERSION}/main" "$@"); }

psql_su -d postgres <<'SQL'
DO $$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'aequa_owner') THEN
    CREATE ROLE aequa_owner LOGIN NOSUPERUSER NOCREATEROLE CREATEDB;
  END IF;
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'aequa_app') THEN
    CREATE ROLE aequa_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
  END IF;
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'aequa_lesen') THEN
    CREATE ROLE aequa_lesen NOLOGIN;
  END IF;
END
$$;
SQL

for db in aequa_verwaltung aequa_vorlage; do
    if ! psql_su -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$db'" | grep -q 1; then
        psql_su -d postgres -c "CREATE DATABASE $db OWNER aequa_owner ENCODING 'UTF8' TEMPLATE template0"
    fi
done

# Standardrechte: PUBLIC darf in den Aequa-Datenbanken nichts außer verbinden
# zurückziehen; Zugriffe werden ausschließlich gezielt vergeben.
for db in aequa_verwaltung aequa_vorlage; do
    psql_su -d "$db" <<SQL
REVOKE ALL ON DATABASE $db FROM PUBLIC;
GRANT CONNECT ON DATABASE $db TO aequa_app, aequa_lesen;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
SQL
done
psql_su -d postgres -c "ALTER DATABASE aequa_vorlage IS_TEMPLATE true"
