#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Parallelitätstests (F71): zwei echte Sitzungen gleichzeitig.
#   A: Festschreiben läuft, Jahressperre wartet und folgt danach.
#   B: Jahressperre läuft, Festschreiben wartet und wird danach abgewiesen.
#   C: Zwei Festschreibungen gleichzeitig → lückenlose, eindeutige Nummern.
# Aufruf durch ausfuehren.sh mit frischer Datenbank in $DB.
set -uo pipefail
P=(psql -X -q -v ON_ERROR_STOP=1 -d "$DB" -tA)
fehler=0
ok()   { echo "ok – $*" >&2; }
nein() { echo "NICHT OK: $*" >&2; fehler=1; }

"${P[@]}" >/dev/null <<'SQL'
SET ROLE kumero_app;
SELECT kern.buch_anlegen('test:admin', 'Parallel (Test)', 'EUR');
SELECT kern.konto_anlegen('test:admin', '1000', 'Kasse', 'aktiv');
SELECT kern.konto_anlegen('test:admin', '8400', 'Erlöse', 'ertrag');
SELECT kern.nummernkreis_anlegen('test:admin', 'ALLG', 'Allgemein');
SELECT kern.buchungsart_anlegen('test:admin', 'KASSE', 'Kasse', 'ALLG');
SELECT kern.geschaeftsjahr_anlegen('test:admin', '2025', '2025-01-01', '2025-12-31');
SELECT kern.geschaeftsjahr_anlegen('test:admin', '2026', '2026-01-01', '2026-12-31');
SELECT kern.nummernkreis_festlegen('test:admin', 'ALLG', '2025', '{JJJJ}-{NR:4}', true);
SELECT kern.nummernkreis_festlegen('test:admin', 'ALLG', '2026', '{JJJJ}-{NR:4}', true);
SQL

entwurf() {  # $1 = Datum, $2 = Text, $3 = "roh" für Entwurf ohne Vorbereitung → gibt ID aus
    "${P[@]}" -c "SET ROLE kumero_app;" -c "SELECT kern.entwurf_anlegen('test:azubi', jsonb_build_object(
        'buchungsart','KASSE','belegdatum','$1','buchungsdatum','$1','text','$2',
        'zeilen', jsonb_build_array(jsonb_build_object('konto','1000','seite','S','betrag','1.00'),
                                    jsonb_build_object('konto','8400','seite','H','betrag','1.00'))))" | tail -1 \
    | { read -r id; [[ "${3:-}" == roh ]] || "${P[@]}" -c "SET ROLE kumero_app;" -c "SELECT kern.vorbereiten('test:frank', '$id')" >/dev/null; echo "$id"; }
}
abfrage() { "${P[@]}" -c "$1" | tail -1; }

# --- A: Festschreiben hält, Sperre wartet --------------------------------------
a1=$(entwurf 2025-06-01 "A")
"${P[@]}" >/dev/null 2>&1 <<SQL &
SET ROLE kumero_app;
BEGIN;
SELECT * FROM kern.festschreiben('test:frank', ARRAY['$a1'::uuid]);
SELECT pg_sleep(2);
COMMIT;
SQL
sleep 0.7
start=$(date +%s.%N)
sperre=$("${P[@]}" -c "SET ROLE kumero_app;" -c "SELECT kern.geschaeftsjahr_sperren('test:frank', '2025')" 2>&1)
dauer=$(echo "$(date +%s.%N) - $start" | bc)
wait
[[ $(abfrage "SELECT status FROM kern.buchung WHERE id = '$a1'") == festgeschrieben ]] \
    && [[ $(abfrage "SELECT status FROM kern.geschaeftsjahr WHERE kennung = '2025'") == gesperrt ]] \
    && (( $(echo "$dauer > 0.8" | bc) )) \
    && ok "A: Sperre wartet auf laufende Festschreibung (${dauer%???????}s) und folgt danach (F71)" \
    || nein "A: Festschreibung/Sperre ($sperre, ${dauer}s)"

# --- B: Sperre hält, Festschreiben wartet und wird abgewiesen ------------------
"${P[@]}" -c "UPDATE kern.geschaeftsjahr SET status = 'offen' WHERE kennung = '2025'" >/dev/null   # Testaufbau als Superuser
b1=$(entwurf 2025-07-01 "B" roh)    # noch nicht freigabebereit, sonst verweigert die Sperre
"${P[@]}" >/dev/null 2>&1 <<SQL &
SET ROLE kumero_app;
BEGIN;
SELECT kern.geschaeftsjahr_sperren('test:frank', '2025');
SELECT pg_sleep(2);
COMMIT;
SQL
sleep 0.7
fs=$("${P[@]}" -c "SET ROLE kumero_app;" -c "SELECT kern.vorbereiten('test:frank', '$b1'); SELECT * FROM kern.festschreiben('test:frank', ARRAY['$b1'::uuid])" 2>&1)
wait
[[ "$fs" == *"ist gesperrt"* ]] && [[ $(abfrage "SELECT status FROM kern.buchung WHERE id = '$b1'") == entwurf ]] \
    && ok "B: Keine Festschreibung rutscht nach einer Jahressperre durch (F71)" \
    || nein "B: Festschreibung trotz Sperre? ($fs)"

# --- C: Zwei Festschreibungen gleichzeitig -------------------------------------
c1=$(entwurf 2026-02-01 "C1"); c2=$(entwurf 2026-02-02 "C2")
for id in "$c1" "$c2"; do
    "${P[@]}" -c "SET ROLE kumero_app;" -c "BEGIN; SELECT * FROM kern.festschreiben('test:frank', ARRAY['$id'::uuid]); SELECT pg_sleep(0.5); COMMIT;" >/dev/null 2>&1 &
done
wait
nrs=$(abfrage "SELECT string_agg(nr, ',' ORDER BY nr) FROM kern.buchung WHERE id IN ('$c1', '$c2')")
journal=$(abfrage "SELECT string_agg(journal_nr::text, ',' ORDER BY journal_nr) FROM kern.buchung WHERE status = 'festgeschrieben'")
pruefung=$(abfrage "SELECT count(*) FROM kern.kette_pruefen()")
[[ "$nrs" == "2026-0001,2026-0002" && "$journal" == "1,2,3" && "$pruefung" == 0 ]] \
    && ok "C: Gleichzeitige Festschreibungen lückenlos nummeriert, Kette intakt (F17)" \
    || nein "C: Nummern $nrs, Journal $journal, Kettenfehler $pruefung"

exit $fehler
