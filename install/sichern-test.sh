#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Prüft install/sichern.sh und install/wiederherstellen.sh gegen einen PostgreSQL-Cluster zum Wegwerfen (F176).
#
#   PGHOST=/tmp/pgtest bash install/sichern-test.sh
#
# Braucht einen Superuser „postgres“ am Socket und die Rollen kumero_owner, kumero_app (wie die PHP-Tests). Legt nur
# Datenbanken an, deren Name mit kumero_sitest beginnt, und räumt sie wieder ab. Geprüft wird damit das Sichern und
# Zurückspielen der Datenbanken und der Konfiguration – nicht systemd, nicht Debian, nicht ein echter Umzug der Maschine.

set -euo pipefail
export LC_ALL=C
cd "$(dirname "${BASH_SOURCE[0]}")"
: "${PGHOST:?PGHOST auf den Socket des Test-Clusters setzen}"
export PGHOST KUMERO_PG_ADMIN=postgres KUMERO_SICHERN_MUSTER='kumero_sitest%'

t="$(mktemp -d)"
trap 'rm -rf "$t"; for d in kumero_sitest_verwaltung kumero_sitest_m kumero_sitest_m__haupt; do psql -X -q -U postgres -d postgres -c "DROP DATABASE IF EXISTS $d" >/dev/null 2>&1 || true; done' EXIT
export KUMERO_ETC="$t/etc" KUMERO_VERZ="$t/www"
mkdir -p "$KUMERO_ETC/tls" "$KUMERO_ETC/paperless" "$KUMERO_VERZ/bin" "$t/ziel"
echo "0.0.1" > "$KUMERO_VERZ/VERSION"
printf '#!/bin/bash\n[[ "$1" == selbsttest ]] && echo "selbsttest ok"\n' > "$KUMERO_VERZ/bin/kumero"; chmod +x "$KUMERO_VERZ/bin/kumero"
cat > "$KUMERO_ETC/kumero.php" <<'EOF'
<?php
return ['adresse' => 'https://kumero.example.org', 'verwaltung_db' => 'kumero_sitest_verwaltung'];
EOF
echo "geheim-paperless" > "$KUMERO_ETC/paperless/env"
echo "privater-schluessel" > "$KUMERO_ETC/tls/privkey.pem"
echo "freigabe" > "$KUMERO_ETC/freigabe-schluessel.gpg"

n=0; fehler=0
pruefe() { n=$((n + 1)); if "${@:2}"; then echo "ok – $1"; else echo "FEHLER – $1"; fehler=$((fehler + 1)); fi; }
sql()    { psql -X -qAt -U postgres -d "$1" -c "$2"; }
anlegen() {
    for d in kumero_sitest_verwaltung kumero_sitest_m kumero_sitest_m__haupt; do sql postgres "DROP DATABASE IF EXISTS $d" >/dev/null; done
    sql postgres "CREATE DATABASE kumero_sitest_verwaltung OWNER kumero_owner" >/dev/null
    psql -X -q -U kumero_owner -d kumero_sitest_verwaltung -c "CREATE SCHEMA verwaltung" -c "CREATE TABLE verwaltung.mandant (kennung text PRIMARY KEY)" >/dev/null
}
bestand() {
    sql kumero_sitest_verwaltung "INSERT INTO verwaltung.mandant VALUES ('m')" >/dev/null
    for d in kumero_sitest_m kumero_sitest_m__haupt; do
        sql postgres "CREATE DATABASE $d OWNER kumero_owner" >/dev/null
        psql -X -q -U kumero_owner -d "$d" -c "CREATE TABLE t (id int PRIMARY KEY, wert text)" \
            -c "INSERT INTO t SELECT g, md5(g::text) FROM generate_series(1, 500) g" \
            -c "CREATE FUNCTION f() RETURNS int LANGUAGE sql SECURITY DEFINER AS 'SELECT count(*)::int FROM t'" \
            -c "REVOKE ALL ON FUNCTION f() FROM PUBLIC" -c "GRANT EXECUTE ON FUNCTION f() TO kumero_app" >/dev/null
    done
}
abdruck() { sql "$1" "SELECT md5(string_agg(id || wert, ',' ORDER BY id)) FROM t"; }

# ── Sichern ohne Verschlüsselung ──
anlegen; bestand
vorher="$(abdruck kumero_sitest_m__haupt)"
bash ./sichern.sh --ziel "$t/ziel" > "$t/aus" 2>&1; pruefe "sichern läuft durch" test $? -eq 0
archiv="$(ls "$t/ziel"/kumero-sicherung-*.tar)"
pruefe "ein Archiv liegt im Ziel" test -f "$archiv"
pruefe "Archiv nur für root lesbar" test "$(stat -c %a "$archiv")" = 600
pruefe "drei Datenbanken im Archiv" test "$(tar -tf "$archiv" | grep -c '^db/.*\.dump$')" = 3
tar -xOf "$archiv" konfiguration.tar > "$t/konf.tar"
pruefe "Konfiguration enthält kumero.php" bash -c "tar -tf '$t/konf.tar' | grep -qx './kumero.php'"
pruefe "Paperless ist nicht in der Sicherung" bash -c "! tar -tf '$t/konf.tar' | grep -q paperless"
pruefe "unverschlüsselt ohne private Schlüssel der Zertifikate" bash -c "! tar -tf '$t/konf.tar' | grep -q tls"
pruefe "Ausgabe nennt den Hinweis unverschlüsselt" grep -q "unverschlüsselt" "$t/aus"

# ── Zurückspielen verweigert über einem Bestand ──
! bash ./wiederherstellen.sh "$archiv" --ja > "$t/aus" 2>&1; pruefe "verweigert über einem Bestand" grep -q "nie über einen Bestand" "$t/aus"
pruefe "Bestand unberührt" test "$(abdruck kumero_sitest_m__haupt)" = "$vorher"

# ── Zurückspielen auf die leere Maschine ──
anlegen
rm -f "$KUMERO_ETC/freigabe-schluessel.gpg"
bash ./wiederherstellen.sh "$archiv" --ja > "$t/aus" 2>&1; pruefe "wiederherstellen läuft durch" test $? -eq 0
pruefe "Daten des Buchs stimmen" test "$(abdruck kumero_sitest_m__haupt)" = "$vorher"
pruefe "Mandant steht wieder in der Verwaltung" test "$(sql kumero_sitest_verwaltung 'SELECT count(*) FROM verwaltung.mandant')" = 1
pruefe "Eigentümer der Datenbank erhalten" test "$(sql postgres "SELECT r.rolname FROM pg_database d JOIN pg_roles r ON r.oid = d.datdba WHERE datname = 'kumero_sitest_m'")" = kumero_owner
pruefe "Eigentümer der Funktion erhalten" test "$(sql kumero_sitest_m "SELECT r.rolname FROM pg_proc p JOIN pg_roles r ON r.oid = p.proowner WHERE proname = 'f'")" = kumero_owner
pruefe "Rechte erhalten: kumero_app darf die Funktion" test "$(psql -X -qAt -U kumero_app -d kumero_sitest_m -c 'SELECT f()')" = 500
pruefe "Rechte erhalten: kumero_app liest die Tabelle nicht" bash -c "! psql -X -qAt -U kumero_app -d kumero_sitest_m -c 'SELECT 1 FROM t LIMIT 1' >/dev/null 2>&1"
pruefe "Konfiguration zurückgespielt" test -f "$KUMERO_ETC/freigabe-schluessel.gpg"
pruefe "vorheriger Stand der Konfiguration beiseitegelegt" bash -c "ls -d '$KUMERO_ETC'.vor-wiederherstellung-* >/dev/null"
pruefe "Selbsttest gelaufen" grep -q "selbsttest ok" "$t/aus"
pruefe "Ausgabe nennt die Bedingung Hostname" grep -q "kumero.example.org" "$t/aus"

# ── Verweigern: andere Version, anderer Hostname, verändertes Archiv ──
anlegen
echo "0.0.2" > "$KUMERO_VERZ/VERSION"
! bash ./wiederherstellen.sh "$archiv" --ja > "$t/aus" 2>&1; pruefe "verweigert bei anderer Version" grep -q "dieselbe Version" "$t/aus"
echo "0.0.1" > "$KUMERO_VERZ/VERSION"
sed -i 's/kumero.example.org/anders.example.org/' "$KUMERO_ETC/kumero.php"
! bash ./wiederherstellen.sh "$archiv" --ja > "$t/aus" 2>&1; pruefe "verweigert bei anderem Hostnamen" grep -q "demselben Hostnamen" "$t/aus"
sed -i 's/anders.example.org/kumero.example.org/' "$KUMERO_ETC/kumero.php"
mkdir "$t/kaputt" && tar -C "$t/kaputt" -xf "$archiv" && echo "x" >> "$t/kaputt/db/kumero_sitest_m.dump" && tar -C "$t/kaputt" -cf "$t/kaputt.tar" MANIFEST SHA256SUMS konfiguration.tar db
! bash ./wiederherstellen.sh "$t/kaputt.tar" --ja > "$t/aus" 2>&1; pruefe "verweigert bei falscher Prüfsumme" grep -q "Prüfsummen" "$t/aus"
tar -C "$t/kaputt" -cf "$t/fremd.tar" MANIFEST SHA256SUMS konfiguration.tar db ../ziel 2>/dev/null || true
! bash ./wiederherstellen.sh "$t/fremd.tar" --ja > "$t/aus" 2>&1; pruefe "verweigert Unerwartetes im Archiv" grep -q "Unerwartetes\|Prüfsummen" "$t/aus"
pruefe "nach den Abweisungen nichts zurückgespielt" test "$(sql postgres "SELECT count(*) FROM pg_database WHERE datname = 'kumero_sitest_m'")" = 0

# ── Verschlüsselt: an einen öffentlichen Schlüssel, Zertifikate dabei, Aufbewahrung ──
if command -v gpg >/dev/null; then
    export GNUPGHOME="$t/gnupg"; mkdir -m 700 "$GNUPGHOME"
    gpg --batch --quiet --passphrase '' --quick-generate-key 'kumero sicherung test' ed25519 cert never 2>/dev/null
    fpr="$(gpg --batch --list-keys --with-colons | awk -F: '/^fpr/ {print $10; exit}')"
    gpg --batch --quiet --passphrase '' --quick-add-key "$fpr" cv25519 encr never 2>/dev/null
    gpg --batch --armor --export "$fpr" > "$t/oeffentlich.asc"
    gpg --batch --armor --export-secret-keys "$fpr" > "$t/privat.asc" 2>/dev/null
    anlegen; bestand
    ! bash ./sichern.sh --ziel "$t/ziel" --empfaenger "$t/privat.asc" > "$t/aus" 2>&1; pruefe "weist einen privaten Schlüssel als Empfänger ab" grep -q "privaten Schlüssel" "$t/aus"
    sleep 1
    bash ./sichern.sh --ziel "$t/ziel" --empfaenger "$t/oeffentlich.asc" --behalten 1 > "$t/aus" 2>&1; pruefe "sichern verschlüsselt läuft durch" test $? -eq 0
    geheim="$(ls "$t/ziel"/kumero-sicherung-*.tar.gpg)"
    pruefe "verschlüsseltes Archiv liegt im Ziel" test -f "$geheim"
    pruefe "kein Klartext daneben, ältere Sicherung entfernt (--behalten 1)" test "$(ls "$t/ziel" | wc -l)" = 1
    pruefe "Archiv ist nicht als tar lesbar" bash -c "! tar -tf '$geheim' >/dev/null 2>&1"
    anlegen
    bash ./wiederherstellen.sh "$geheim" --ja > "$t/aus" 2>&1; pruefe "wiederherstellen aus verschlüsseltem Archiv" test $? -eq 0
    pruefe "Daten stimmen nach dem verschlüsselten Weg" test "$(abdruck kumero_sitest_m__haupt)" = "$vorher"
    pruefe "Zertifikate kamen mit" test -f "$KUMERO_ETC/tls/privkey.pem"
    gpgconf --homedir "$GNUPGHOME" --kill all 2>/dev/null || true
else
    echo "übersprungen – gpg fehlt: Verschlüsselung nicht geprüft"
fi

echo
if [[ $fehler -eq 0 ]]; then echo "$n Prüfungen, alle grün"; else echo "$n Prüfungen, $fehler FEHLER"; exit 1; fi
