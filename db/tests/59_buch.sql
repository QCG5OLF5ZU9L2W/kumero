-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Tests Kern: Der Kern ist die Datenbank eines Buchs (Migration 0016; BUECHER.md A3, A7, A10, A14; F126).
-- Nur synthetische Daten.

SELECT test.ok('In einem neuen Buch gibt es keine Nutzertabellen', to_regclass('kern.nutzer') IS NULL AND to_regclass('kern.sitzung') IS NULL
    AND NOT EXISTS (SELECT 1 FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'kern' AND p.proname IN ('nutzer_anlegen', 'sitzung_pruefen', 'registrierung_ausstellen')));
SET ROLE kumero_app;
SELECT kern.buch_anlegen('system:test', 'Testbuch', 'EUR');
SELECT kern.rollenvorlagen_anlegen('system:test');
SELECT test.fehler('Die Anwendung richtet kein Buch ein', $$SELECT kern.buch_einrichten('system:test', 'haupt', 'euer', false)$$, '42501');
SELECT test.fehler('Ohne eingerichtetes Buch kein Siegel', $$SELECT kern.siegel_anfordern('nutzer:test', 'praxis', 'haupt')$$, 'KM002');
RESET ROLE;
SET ROLE kumero_owner;
SELECT test.fehler('Art ist euer oder bilanz', $$SELECT kern.buch_einrichten('system:test', 'spiel', 'kameral', true)$$, '23514');
SELECT kern.buch_einrichten('system:test', 'spiel', 'bilanz', true);
SELECT test.ok('Buch eingerichtet: Kennung, Art, Testbuch', (SELECT kennung = 'spiel' AND art = 'bilanz' AND testbuch FROM kern.buch));
SELECT test.fehler('Einrichten nur einmal', $$SELECT kern.buch_einrichten('system:test', 'anders', 'euer', false)$$, 'KM003');
SELECT test.fehler('Kennung ist unveränderlich', $$UPDATE kern.buch SET kennung = 'anders'$$, 'KM001');
SELECT test.fehler('Aus einem Testbuch wird kein gewöhnliches Buch', $$UPDATE kern.buch SET testbuch = false$$, 'KM001');
SELECT test.fehler('Ein Testbuch wird nie produktiv', $$UPDATE kern.buch SET betriebsart = 'produktiv', produktiv_seit = now()$$, 'KM003');
SELECT test.ok('Im Protokoll des Buchs', (SELECT nachher->>'kennung' = 'spiel' AND (nachher->>'testbuch')::boolean FROM kern.protokoll WHERE typ = 'buch.eingerichtet'));
RESET ROLE;

-- Siegelinhalt Fassung 2 nennt Mandant und Buch
SET ROLE kumero_app;
SELECT test.fehler('Das Siegel gilt für genau dieses Buch', $$SELECT kern.siegel_anfordern('nutzer:test', 'praxis', 'haupt')$$, 'KM002');
SELECT kern.siegel_anfordern('nutzer:test', 'praxis', 'spiel');
SELECT test.ok('Fassung 2 mit Mandant und Buch',
    (SELECT (inhalt::jsonb->>'fassung')::int = 2 AND inhalt::jsonb->>'mandant' = 'praxis' AND inhalt::jsonb->>'buch' = 'spiel'
            AND inhalt = kern.jcs(inhalt::jsonb) FROM kern.siegel_anforderung));

-- Der letzte Verantwortliche eines Buchs bleibt; die Rolle Administrator im Buch lässt sich beenden
CREATE TEMP TABLE t (name text PRIMARY KEY, wert text);
GRANT ALL ON t TO PUBLIC;
INSERT INTO t SELECT 'v1', kern.zuweisen('system:cli', 'nutzer:11111111-1111-4111-8111-111111111111', 'Verantwortlicher');
INSERT INTO t SELECT 'a1', kern.zuweisen('system:cli', 'nutzer:22222222-2222-4222-8222-222222222222', 'Administrator');
INSERT INTO t SELECT 'd1', kern.zuweisen('system:cli', 'dienst:bot', 'Verantwortlicher');
SELECT test.fehler('Die letzte Zuweisung „Verantwortlicher“ wird nicht beendet (Dienstkonten zählen nicht)',
    $$SELECT kern.zuweisung_beenden('system:cli', (SELECT wert::uuid FROM t WHERE name = 'v1'))$$, 'KM003');
SELECT kern.zuweisung_beenden('system:cli', (SELECT wert::uuid FROM t WHERE name = 'a1'));
SELECT test.ok('Eine alte Zuweisung „Administrator“ im Buch lässt sich beenden – der Administrator steht im Mandanten',
    (SELECT gueltig_bis IS NOT NULL FROM kern.zuweisung WHERE id = (SELECT wert::uuid FROM t WHERE name = 'a1')));
INSERT INTO t SELECT 'v2', kern.zuweisen('system:cli', 'nutzer:33333333-3333-4333-8333-333333333333', 'Verantwortlicher');
SELECT kern.zuweisung_beenden('system:cli', (SELECT wert::uuid FROM t WHERE name = 'v1'));
SELECT test.ok('Mit einem zweiten Verantwortlichen lässt sich die erste Zuweisung beenden',
    (SELECT gueltig_bis IS NOT NULL FROM kern.zuweisung WHERE id = (SELECT wert::uuid FROM t WHERE name = 'v1')));
SELECT test.ok('Ketten intakt', NOT EXISTS (SELECT 1 FROM kern.kette_pruefen()));
RESET ROLE;
