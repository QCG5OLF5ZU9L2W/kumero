-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Tests Kern: Zugang mit Link und Code, letzter Administrator und letzter Verantwortlicher
-- (Migration 0015; VERWALTUNG.md Abschnitt 4 und 6; F125). Nur synthetische Daten.

SET ROLE kumero_app;
SELECT kern.buch_anlegen('system:test', 'Testpraxis', 'EUR');
SELECT kern.rollenvorlagen_anlegen('system:test');
CREATE TEMP TABLE t (name text PRIMARY KEY, wert text);
GRANT ALL ON t TO PUBLIC;
INSERT INTO t SELECT 'adam', kern.nutzer_anlegen('system:cli', 'Admin', 'Adam', 'adam@example.org', NULL, repeat('A', 43));
INSERT INTO t SELECT 'vera', kern.nutzer_anlegen('system:cli', 'Verantwortlich', 'Vera', 'vera@example.org', NULL, repeat('V', 43));
INSERT INTO t SELECT 'berta', kern.nutzer_anlegen('system:cli', 'Buchhalter', 'Berta', 'berta@example.org', NULL, repeat('B', 43));
INSERT INTO t SELECT 'z_adam', kern.zuweisen('system:cli', 'nutzer:' || (SELECT wert FROM t WHERE name = 'adam'), 'Administrator');
INSERT INTO t SELECT 'z_vera', kern.zuweisen('system:cli', 'nutzer:' || (SELECT wert FROM t WHERE name = 'vera'), 'Verantwortlicher');
INSERT INTO t SELECT 'z_berta', kern.zuweisen('system:cli', 'nutzer:' || (SELECT wert FROM t WHERE name = 'berta'), 'Buchhalter');

-- Zugang: ein neuer macht den vorigen ungültig; das Protokoll nennt weder Link noch Code
SELECT kern.registrierung_ausstellen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'berta'), repeat('1', 64), interval '1 hour', repeat('a', 64));
SELECT kern.registrierung_ausstellen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'berta'), repeat('2', 64), interval '24 hours', repeat('b', 64));
SELECT test.ok('Der vorige Zugang derselben Identität ist ungültig',
    (SELECT ungueltig_am IS NOT NULL FROM kern.registrierung WHERE token_hash = repeat('1', 64))
    AND (SELECT ungueltig_am IS NULL FROM kern.registrierung WHERE token_hash = repeat('2', 64)));
SELECT kern.registrierung_ausstellen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'vera'), repeat('3', 64));
SELECT test.ok('Der Zugang einer anderen Identität bleibt',
    (SELECT ungueltig_am IS NULL FROM kern.registrierung WHERE token_hash = repeat('2', 64)));
SELECT test.ok('Protokoll: mit Code, aber ohne Hash von Link oder Code',
    (SELECT bool_or((nachher->>'mit_code')::boolean) FROM kern.protokoll WHERE typ = 'nutzer.zugang_ausgestellt')
    AND NOT EXISTS (SELECT 1 FROM kern.protokoll WHERE coalesce(nachher::text, '') ~ ('(' || repeat('2', 64) || '|' || repeat('b', 64) || ')')));

-- Code prüfen: falsche Eingaben zählen, die fünfte macht den Zugang ungültig
SELECT test.ok('Zugang ohne Code (Kommandozeile) ist mit jedem Code in Ordnung',
    kern.registrierung_code_pruefen(repeat('3', 64), repeat('f', 64)) = 'ok');
SELECT test.ok('Ungültiger Zugang', kern.registrierung_code_pruefen(repeat('1', 64), repeat('a', 64)) = 'ungueltig');
SELECT test.ok('Unbekannter Link', kern.registrierung_code_pruefen(repeat('9', 64), repeat('a', 64)) = 'ungueltig');
SELECT test.ok('Richtiger Code', kern.registrierung_code_pruefen(repeat('2', 64), repeat('b', 64)) = 'ok');
SELECT test.ok('Falscher Code', kern.registrierung_code_pruefen(repeat('2', 64), repeat('c', 64)) = 'falsch');
SELECT test.ok('… wird gezählt', (SELECT fehlversuche = 1 FROM kern.registrierung WHERE token_hash = repeat('2', 64)));
SELECT test.ok('Der richtige Code danach', kern.registrierung_code_pruefen(repeat('2', 64), repeat('b', 64)) = 'ok');
SELECT test.ok('… zählt nicht', (SELECT fehlversuche = 1 FROM kern.registrierung WHERE token_hash = repeat('2', 64)));
SELECT kern.registrierung_code_pruefen(repeat('2', 64), repeat('c', 64));
SELECT kern.registrierung_code_pruefen(repeat('2', 64), repeat('c', 64));
SELECT test.ok('Vierter falscher Code: noch gültig', kern.registrierung_code_pruefen(repeat('2', 64), repeat('c', 64)) = 'falsch');
SELECT test.ok('Fünfter falscher Code: ungültig', kern.registrierung_code_pruefen(repeat('2', 64), repeat('c', 64)) = 'ungueltig');
SELECT test.ok('Danach hilft der richtige Code nicht mehr', kern.registrierung_code_pruefen(repeat('2', 64), repeat('b', 64)) = 'ungueltig');
SELECT test.ok('Der Zugang ist ungültig, fünf Fehlversuche vermerkt',
    (SELECT ungueltig_am IS NOT NULL AND fehlversuche = 5 FROM kern.registrierung WHERE token_hash = repeat('2', 64)));
SELECT test.fehler('Mit dem ungültigen Zugang entsteht kein Passkey',
    $$SELECT kern.anmelde_credential_registrieren('system:registrierung', (SELECT wert::uuid FROM t WHERE name = 'berta'),
        'Y3JlZGVudGlhbC1iZXJ0YS0wMDE', '\x01', -7, 0, 'x', repeat('2', 64))$$, 'KM003');

-- Wer nicht entfernt werden kann (VERWALTUNG.md Abschnitt 4)
SELECT test.fehler('Der letzte Administrator wird nicht gesperrt',
    $$SELECT kern.nutzer_aendern('system:cli', (SELECT wert::uuid FROM t WHERE name = 'adam'), 'Admin', 'Adam', 'adam@example.org', false)$$, 'KM003');
SELECT test.fehler('Der letzte Verantwortliche wird nicht gesperrt',
    $$SELECT kern.nutzer_aendern('system:cli', (SELECT wert::uuid FROM t WHERE name = 'vera'), 'Verantwortlich', 'Vera', 'vera@example.org', false)$$, 'KM003');
SELECT test.fehler('Die letzte Zuweisung „Administrator“ wird nicht beendet',
    $$SELECT kern.zuweisung_beenden('system:cli', (SELECT wert::uuid FROM t WHERE name = 'z_adam'))$$, 'KM003');
SELECT test.fehler('Die letzte Zuweisung „Verantwortlicher“ wird nicht beendet',
    $$SELECT kern.zuweisung_beenden('system:cli', (SELECT wert::uuid FROM t WHERE name = 'z_vera'))$$, 'KM003');
SELECT test.ok('Abgelehnt: Adam ist weiter aktiv und Administrator',
    (SELECT aktiv FROM kern.nutzer WHERE id = (SELECT wert::uuid FROM t WHERE name = 'adam'))
    AND (SELECT gueltig_bis IS NULL FROM kern.zuweisung WHERE id = (SELECT wert::uuid FROM t WHERE name = 'z_adam')));
SELECT kern.nutzer_aendern('system:cli', (SELECT wert::uuid FROM t WHERE name = 'adam'), 'Administrator', 'Adam', 'adam@example.org', true);
SELECT test.ok('Den Namen des letzten Administrators ändern geht',
    (SELECT familienname = 'Administrator' FROM kern.nutzer WHERE id = (SELECT wert::uuid FROM t WHERE name = 'adam')));
SELECT kern.nutzer_aendern('system:cli', (SELECT wert::uuid FROM t WHERE name = 'berta'), 'Buchhalter', 'Berta', 'berta@example.org', false);
SELECT kern.zuweisung_beenden('system:cli', (SELECT wert::uuid FROM t WHERE name = 'z_berta'));
SELECT test.ok('Andere Nutzer lassen sich sperren, andere Rollen beenden',
    (SELECT NOT aktiv FROM kern.nutzer WHERE id = (SELECT wert::uuid FROM t WHERE name = 'berta')));

-- Eine gesperrte zweite Administratorin zählt nicht; eine aktive schon
INSERT INTO t SELECT 'z_berta_admin', kern.zuweisen('system:cli', 'nutzer:' || (SELECT wert FROM t WHERE name = 'berta'), 'Administrator');
SELECT test.fehler('Gesperrte Träger zählen nicht',
    $$SELECT kern.zuweisung_beenden('system:cli', (SELECT wert::uuid FROM t WHERE name = 'z_adam'))$$, 'KM003');
SELECT kern.nutzer_aendern('system:cli', (SELECT wert::uuid FROM t WHERE name = 'berta'), 'Buchhalter', 'Berta', 'berta@example.org', true);
SELECT kern.zuweisung_beenden('system:cli', (SELECT wert::uuid FROM t WHERE name = 'z_adam'));
SELECT test.ok('Mit einer zweiten aktiven Administratorin lässt sich die erste Zuweisung beenden',
    (SELECT gueltig_bis IS NOT NULL FROM kern.zuweisung WHERE id = (SELECT wert::uuid FROM t WHERE name = 'z_adam')));
SELECT test.fehler('… und nun ist Berta die letzte',
    $$SELECT kern.nutzer_aendern('system:cli', (SELECT wert::uuid FROM t WHERE name = 'berta'), 'Buchhalter', 'Berta', 'berta@example.org', false)$$, 'KM003');

-- Ein Mandant ohne Verantwortlichen ist kein Hindernis: Die Regel schützt nur den letzten, den es gibt
SELECT test.ok('Ketten intakt', NOT EXISTS (SELECT 1 FROM kern.kette_pruefen()));
RESET ROLE;
