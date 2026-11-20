-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Tests Mandanten-Datenbank: Zugang mit Link und Code, Administratoren, Bücherliste
-- (VERWALTUNG.md Abschnitt 4 und 6, BUECHER.md A2 und A10; F125, F126). Nur synthetische Daten.

SET ROLE kumero_app;
CREATE TEMP TABLE t (name text PRIMARY KEY, wert text);
GRANT ALL ON t TO PUBLIC;
INSERT INTO t SELECT 'adam', mandant.nutzer_anlegen('system:cli', 'Admin', 'Adam', 'adam@example.org', NULL, repeat('A', 43));
INSERT INTO t SELECT 'vera', mandant.nutzer_anlegen('system:cli', 'Verantwortlich', 'Vera', 'vera@example.org', NULL, repeat('V', 43));
INSERT INTO t SELECT 'berta', mandant.nutzer_anlegen('system:cli', 'Buchhalter', 'Berta', 'berta@example.org', NULL, repeat('B', 43));

-- Zugang: ein neuer macht den vorigen ungültig; das Protokoll nennt weder Link noch Code
SELECT mandant.registrierung_ausstellen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'berta'), repeat('1', 64), interval '1 hour', repeat('a', 64));
SELECT mandant.registrierung_ausstellen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'berta'), repeat('2', 64), interval '24 hours', repeat('b', 64));
SELECT test.ok('Der vorige Zugang derselben Identität ist ungültig',
    (SELECT ungueltig_am IS NOT NULL FROM mandant.registrierung WHERE token_hash = repeat('1', 64))
    AND (SELECT ungueltig_am IS NULL FROM mandant.registrierung WHERE token_hash = repeat('2', 64)));
SELECT mandant.registrierung_ausstellen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'vera'), repeat('3', 64));
SELECT test.ok('Der Zugang einer anderen Identität bleibt',
    (SELECT ungueltig_am IS NULL FROM mandant.registrierung WHERE token_hash = repeat('2', 64)));
SELECT test.ok('Protokoll: mit Code, aber ohne Hash von Link oder Code',
    (SELECT bool_or((nachher->>'mit_code')::boolean) FROM mandant.protokoll WHERE typ = 'nutzer.zugang_ausgestellt')
    AND NOT EXISTS (SELECT 1 FROM mandant.protokoll WHERE coalesce(nachher::text, '') ~ ('(' || repeat('2', 64) || '|' || repeat('b', 64) || ')')));

-- Code prüfen: falsche Eingaben zählen, die fünfte macht den Zugang ungültig
SELECT test.ok('Zugang ohne Code (Kommandozeile) ist mit jedem Code in Ordnung',
    mandant.registrierung_code_pruefen(repeat('3', 64), repeat('f', 64)) = 'ok');
SELECT test.ok('Ungültiger Zugang', mandant.registrierung_code_pruefen(repeat('1', 64), repeat('a', 64)) = 'ungueltig');
SELECT test.ok('Unbekannter Link', mandant.registrierung_code_pruefen(repeat('9', 64), repeat('a', 64)) = 'ungueltig');
SELECT test.ok('Richtiger Code', mandant.registrierung_code_pruefen(repeat('2', 64), repeat('b', 64)) = 'ok');
SELECT test.ok('Falscher Code', mandant.registrierung_code_pruefen(repeat('2', 64), repeat('c', 64)) = 'falsch');
SELECT test.ok('… wird gezählt', (SELECT fehlversuche = 1 FROM mandant.registrierung WHERE token_hash = repeat('2', 64)));
SELECT test.ok('Der richtige Code danach', mandant.registrierung_code_pruefen(repeat('2', 64), repeat('b', 64)) = 'ok');
SELECT test.ok('… zählt nicht', (SELECT fehlversuche = 1 FROM mandant.registrierung WHERE token_hash = repeat('2', 64)));
SELECT mandant.registrierung_code_pruefen(repeat('2', 64), repeat('c', 64));
SELECT mandant.registrierung_code_pruefen(repeat('2', 64), repeat('c', 64));
SELECT test.ok('Vierter falscher Code: noch gültig', mandant.registrierung_code_pruefen(repeat('2', 64), repeat('c', 64)) = 'falsch');
SELECT test.ok('Fünfter falscher Code: ungültig', mandant.registrierung_code_pruefen(repeat('2', 64), repeat('c', 64)) = 'ungueltig');
SELECT test.ok('Danach hilft der richtige Code nicht mehr', mandant.registrierung_code_pruefen(repeat('2', 64), repeat('b', 64)) = 'ungueltig');
SELECT test.ok('Der Zugang ist ungültig, fünf Fehlversuche vermerkt',
    (SELECT ungueltig_am IS NOT NULL AND fehlversuche = 5 FROM mandant.registrierung WHERE token_hash = repeat('2', 64)));
SELECT test.fehler('Mit dem ungültigen Zugang entsteht kein Passkey',
    $$SELECT mandant.anmelde_credential_registrieren('system:registrierung', (SELECT wert::uuid FROM t WHERE name = 'berta'),
        'Y3JlZGVudGlhbC1iZXJ0YS0wMDE', '\x01', -7, 0, 'x', repeat('2', 64))$$, 'KM003');

-- Administratoren (BUECHER.md A10): einsetzen, abberufen – der letzte bleibt
SELECT mandant.administrator_einsetzen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'adam'));
SELECT test.fehler('Nicht doppelt einsetzen',
    $$SELECT mandant.administrator_einsetzen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'adam'))$$, 'KM003');
SELECT test.fehler('Der letzte Administrator wird nicht gesperrt',
    $$SELECT mandant.nutzer_aendern('system:cli', (SELECT wert::uuid FROM t WHERE name = 'adam'), 'Admin', 'Adam', 'adam@example.org', false)$$, 'KM003');
SELECT test.fehler('Der letzte Administrator wird nicht abberufen',
    $$SELECT mandant.administrator_abberufen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'adam'))$$, 'KM003');
SELECT mandant.nutzer_aendern('system:cli', (SELECT wert::uuid FROM t WHERE name = 'adam'), 'Administrator', 'Adam', 'adam@example.org', true);
SELECT test.ok('Den Namen des letzten Administrators ändern geht',
    (SELECT familienname = 'Administrator' AND aktiv FROM mandant.nutzer WHERE id = (SELECT wert::uuid FROM t WHERE name = 'adam')));
SELECT mandant.nutzer_aendern('system:cli', (SELECT wert::uuid FROM t WHERE name = 'berta'), 'Buchhalter', 'Berta', 'berta@example.org', false);
SELECT test.ok('Andere Nutzer lassen sich sperren',
    (SELECT NOT aktiv FROM mandant.nutzer WHERE id = (SELECT wert::uuid FROM t WHERE name = 'berta')));
SELECT test.fehler('Gesperrte werden nicht Administrator',
    $$SELECT mandant.administrator_einsetzen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'berta'))$$, 'KM003');
SELECT mandant.nutzer_aendern('system:cli', (SELECT wert::uuid FROM t WHERE name = 'berta'), 'Buchhalter', 'Berta', 'berta@example.org', true);
SELECT mandant.administrator_einsetzen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'berta'));
SELECT mandant.nutzer_aendern('system:cli', (SELECT wert::uuid FROM t WHERE name = 'berta'), 'Buchhalter', 'Berta', 'berta@example.org', false);
SELECT test.fehler('Eine gesperrte zweite Administratorin zählt nicht',
    $$SELECT mandant.administrator_abberufen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'adam'))$$, 'KM003');
SELECT mandant.nutzer_aendern('system:cli', (SELECT wert::uuid FROM t WHERE name = 'berta'), 'Buchhalter', 'Berta', 'berta@example.org', true);
SELECT mandant.administrator_abberufen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'adam'));
SELECT test.ok('Mit einer zweiten aktiven Administratorin lässt sich der erste abberufen',
    (SELECT bis IS NOT NULL FROM mandant.administrator WHERE nutzer = (SELECT wert::uuid FROM t WHERE name = 'adam')));
SELECT test.fehler('… und nun ist Berta die letzte',
    $$SELECT mandant.nutzer_aendern('system:cli', (SELECT wert::uuid FROM t WHERE name = 'berta'), 'Buchhalter', 'Berta', 'berta@example.org', false)$$, 'KM003');
SELECT mandant.administrator_einsetzen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'adam'));
SELECT test.ok('Wieder eingesetzt: zwei Einträge für Adam, einer läuft',
    (SELECT count(*) = 2 AND count(*) FILTER (WHERE bis IS NULL) = 1 FROM mandant.administrator WHERE nutzer = (SELECT wert::uuid FROM t WHERE name = 'adam')));
SELECT test.ok('Protokoll nennt Einsetzen und Abberufen',
    (SELECT count(*) FILTER (WHERE typ = 'administrator.eingesetzt') = 3 AND count(*) FILTER (WHERE typ = 'administrator.abberufen') = 1 FROM mandant.protokoll));

-- Idempotenz der Ebene Mandant
BEGIN;
SELECT test.ok('Idempotenter Aufruf beginnt', (mandant.idempotenz_beginnen('nutzer:test', 'schluessel-1', 'POST x', repeat('a', 64))->>'neu')::boolean);
SELECT mandant.idempotenz_abschliessen('nutzer:test', 'schluessel-1', 201, '{"body":"{}"}');
COMMIT;
SELECT test.ok('Die Wiederholung bekommt die gespeicherte Antwort',
    (SELECT (x->>'neu')::boolean = false AND (x->>'status')::int = 201 FROM mandant.idempotenz_beginnen('nutzer:test', 'schluessel-1', 'POST x', repeat('a', 64)) x));
SELECT test.fehler('Derselbe Schlüssel für eine andere Anfrage',
    $$SELECT mandant.idempotenz_beginnen('nutzer:test', 'schluessel-1', 'POST x', repeat('b', 64))$$, 'KM006');
SELECT test.fehler('Anwendung schreibt nicht direkt (F64)', $$INSERT INTO mandant.administrator (nutzer) SELECT id FROM mandant.nutzer LIMIT 1$$, '42501');
SELECT test.fehler('Bücher trägt nur der Dienst ein', $$SELECT mandant.buch_eintragen('system:x', 'haupt', 'Hauptbuch', 'euer', false)$$, '42501');
RESET ROLE;

-- Bücherliste (nur kumero_owner trägt ein; die Anwendung benennt um)
SET ROLE kumero_owner;
SELECT mandant.buch_eintragen('system:auftrag', 'haupt', ' Hauptbuch ', 'euer', false);
SELECT mandant.buch_eintragen('system:auftrag', 'spiel', 'Spielwiese', 'bilanz', true, 'haupt', now());
SELECT test.fehler('Kennung eines Buchs ist eindeutig', $$SELECT mandant.buch_eintragen('system:auftrag', 'haupt', 'x', 'euer', false)$$, '23505');
SELECT test.fehler('Art ist euer oder bilanz', $$SELECT mandant.buch_eintragen('system:auftrag', 'drei', 'x', 'kameral', false)$$, '23514');
SELECT test.fehler('Eine Kopie ist immer ein Testbuch', $$SELECT mandant.buch_eintragen('system:auftrag', 'vier', 'x', 'euer', false, 'haupt', now())$$, '23514');
RESET ROLE;
SET ROLE kumero_app;
SELECT mandant.buch_umbenennen('nutzer:test', 'haupt', 'Praxis');
SELECT test.ok('Buch umbenannt, Kennung bleibt', (SELECT name = 'Praxis' FROM mandant.buch WHERE kennung = 'haupt'));
SELECT test.fehler('Unbekanntes Buch', $$SELECT mandant.buch_umbenennen('nutzer:test', 'fehlt', 'x')$$, 'KM003');
SELECT test.ok('Kette des Mandanten nachrechenbar', NOT EXISTS (SELECT 1 FROM mandant.kette_pruefen()));
RESET ROLE;
