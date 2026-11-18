-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Tests Verwaltungsdatenbank nach dem Umbau (F114; VERWALTUNG.md Abschnitt 3): Pseudonym, System-Verwalter

SET ROLE kumero_owner;
CREATE TEMP TABLE t (name text PRIMARY KEY, wert text);
GRANT ALL ON t TO PUBLIC;
SELECT verwaltung.mandant_eintragen('system:cli', 'praxis', 'kumero_praxis', 'Praxis (Test)');
SELECT verwaltung.mandant_eintragen('system:cli', 'labor', 'kumero_labor', 'Labor (Test)');

-- Pseudonym → Mandant: nur einfügen, nie umlenken
SELECT verwaltung.pseudonym_eintragen(repeat('P', 43), 'praxis');
SELECT test.fehler('Ein Pseudonym lässt sich nicht auf einen anderen Mandanten umlenken',
    $$SELECT verwaltung.pseudonym_eintragen(repeat('P', 43), 'labor')$$, '23505');
SELECT test.fehler('Pseudonym nur für bekannte Mandanten',
    $$SELECT verwaltung.pseudonym_eintragen(repeat('Q', 43), 'fremd')$$, '23503');
SELECT test.fehler('Pseudonym der Installation nur mit einem System-Verwalter',
    $$SELECT verwaltung.pseudonym_eintragen(repeat('Q', 43), NULL)$$, 'KM003');
SELECT test.fehler('Pseudonym hat die Form base64url, mindestens 16 Byte',
    $$SELECT verwaltung.pseudonym_eintragen('kurz', 'praxis')$$, '23514');
SELECT test.ok('Die Zuordnung hat genau zwei Spalten – kein Name, keine Rolle, kein Schlüssel, kein Zeitpunkt',
    (SELECT array_agg(attname::text ORDER BY attnum) FROM pg_attribute
      WHERE attrelid = 'verwaltung.pseudonym'::regclass AND attnum > 0 AND NOT attisdropped) = ARRAY['pseudonym', 'mandant']);
SELECT test.ok('Pseudonyme stehen nicht im Protokoll der Verwaltung',
    NOT EXISTS (SELECT 1 FROM verwaltung.protokoll WHERE ids::text LIKE '%' || repeat('P', 43) || '%'));

-- System-Verwalter: eigene Identität der Installation
INSERT INTO t SELECT 'vera', verwaltung.verwalter_anlegen('system:cli', 'Vera Verwalter', NULL, repeat('V', 43));
SELECT test.ok('Verwalter hat ein Pseudonym der Installation',
    (SELECT mandant IS NULL FROM verwaltung.pseudonym WHERE pseudonym = repeat('V', 43)));
SELECT verwaltung.registrierung_ausstellen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'vera'), repeat('1', 64));
SELECT verwaltung.credential_registrieren('system:registrierung', (SELECT wert::uuid FROM t WHERE name = 'vera'),
    'Y3JlZGVudGlhbC12ZXJhLTAwMDE', '\x01020304', -7, 0, 'Schlüssel', repeat('1', 64));
SELECT test.ok('Anmeldung des Verwalters',
    verwaltung.anmeldung_vermerken('Y3JlZGVudGlhbC12ZXJhLTAwMDE', 1)::text = (SELECT wert FROM t WHERE name = 'vera'));
SELECT verwaltung.sitzung_anlegen('nutzer:' || (SELECT wert FROM t WHERE name = 'vera'), repeat('5', 64));
SELECT test.ok('Sitzung des Verwalters gilt',
    verwaltung.sitzung_pruefen(repeat('5', 64)) = 'nutzer:' || (SELECT wert FROM t WHERE name = 'vera'));
SELECT test.fehler('Der letzte System-Verwalter lässt sich nicht sperren (V4)',
    $$SELECT verwaltung.nutzer_aendern('system:cli', (SELECT wert::uuid FROM t WHERE name = 'vera'), 'Vera Verwalter', NULL, true, false)$$, 'KM003');
SELECT test.fehler('… und nicht zum gewöhnlichen Nutzer machen',
    $$SELECT verwaltung.nutzer_aendern('system:cli', (SELECT wert::uuid FROM t WHERE name = 'vera'), 'Vera Verwalter', NULL, false, true)$$, 'KM003');

-- Bestand aus der Zeit vor dem Umbau: Nutzer ohne Verwalter-Kennzeichen kommt in der Verwaltung nicht mehr hinein
RESET ROLE;
INSERT INTO verwaltung.nutzer (id, name, verwalter) VALUES ('11111111-1111-4111-8111-111111111111', 'Alt Bestand', false);
INSERT INTO verwaltung.anmelde_credential (credential_id, nutzer, public_key, alg)
     VALUES ('Y3JlZGVudGlhbC1hbHQtMDAwMDE', '11111111-1111-4111-8111-111111111111', '\x05', -7);
INSERT INTO verwaltung.sitzung (hash, subjekt, ablauf_absolut)
     VALUES (repeat('6', 64), 'nutzer:11111111-1111-4111-8111-111111111111', now() + interval '1 hour');
SET ROLE kumero_owner;
SELECT test.fehler('Passkey eines Nutzers ohne Verwalter-Kennzeichen meldet in der Verwaltung nicht an',
    $$SELECT verwaltung.anmeldung_vermerken('Y3JlZGVudGlhbC1hbHQtMDAwMDE', 1)$$, 'KM003');
SELECT test.ok('Seine Sitzung gilt nicht', verwaltung.sitzung_pruefen(repeat('6', 64)) IS NULL);
SELECT test.fehler('Neue Passkeys in der Verwaltung nur für System-Verwalter',
    $$SELECT verwaltung.credential_registrieren('nutzer:11111111-1111-4111-8111-111111111111',
        '11111111-1111-4111-8111-111111111111', 'Y3JlZGVudGlhbC1hbHQtMDAwMDI', '\x05', -7, 0, 'x')$$, 'KM003');

-- Überführen: Verwalter aus dem Bestand bekommt sein Pseudonym genau einmal
RESET ROLE;
INSERT INTO verwaltung.nutzer (id, name, verwalter) VALUES ('22222222-2222-4222-8222-222222222222', 'Alter Verwalter', true);
SET ROLE kumero_owner;
SELECT verwaltung.verwalter_pseudonym_setzen('system:cli', '22222222-2222-4222-8222-222222222222', repeat('W', 43));
SELECT test.fehler('Pseudonym eines Verwalters wird nicht überschrieben',
    $$SELECT verwaltung.verwalter_pseudonym_setzen('system:cli', '22222222-2222-4222-8222-222222222222', repeat('X', 43))$$, 'KM003');
SELECT verwaltung.administrator_vermerken('system:cli', 'praxis');
SELECT test.ok('Administrator einsetzen steht im Protokoll der Verwaltung – nur mit dem Mandanten',
    (SELECT ids = '{"mandant":"praxis"}' AND vorher IS NULL AND nachher IS NULL FROM verwaltung.protokoll
      WHERE typ = 'mandant.administrator_eingesetzt'));

-- Die Anwendung trägt in dieser Stufe nichts davon selbst ein
RESET ROLE;
SET ROLE kumero_app;
SELECT test.fehler('Anwendung trägt keine Pseudonyme ein',
    $$SELECT verwaltung.pseudonym_eintragen(repeat('R', 43), 'praxis')$$, '42501');
SELECT test.fehler('Anwendung legt keine Verwalter an',
    $$SELECT verwaltung.verwalter_anlegen('system:cli', 'x', NULL, repeat('S', 43))$$, '42501');
SELECT test.fehler('Anwendung schreibt die Zuordnung nicht direkt',
    $$UPDATE verwaltung.pseudonym SET mandant = 'labor'$$, '42501');
SELECT test.ok('Anwendung liest die Zuordnung',
    (SELECT mandant FROM verwaltung.pseudonym WHERE pseudonym = repeat('P', 43)) = 'praxis');
SELECT test.ok('Kette der Verwaltung nachrechenbar', NOT EXISTS (SELECT 1 FROM verwaltung.kette_pruefen()));
RESET ROLE;
