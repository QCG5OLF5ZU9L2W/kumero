-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Tests Verwaltungsdatenbank (F37, F69; ANWENDUNG.md Abschnitt 5 und 6)

SET ROLE kumero_app;
SELECT test.ok('Gleiche kanonische Form wie im Kern (F29)',
    verwaltung.jcs('{"b":[1,"ä"],"a":{"z":true,"y":null}}') = '{"a":{"y":null,"z":true},"b":[1,"ä"]}');

CREATE TEMP TABLE t (name text PRIMARY KEY, wert text);
GRANT ALL ON t TO PUBLIC;

-- Erster Nutzer über die Kommandozeile, Registrierungslink
RESET ROLE;
SET ROLE kumero_owner;      -- Verwalter legt in dieser Stufe nur die Kommandozeile an (F114)
INSERT INTO t SELECT 'frank', verwaltung.verwalter_anlegen('system:cli', 'Frank', 'de', repeat('F', 43));
RESET ROLE;
SET ROLE kumero_app;
SELECT verwaltung.registrierung_ausstellen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'frank'), repeat('1', 64));
SELECT verwaltung.credential_registrieren('system:registrierung', (SELECT wert::uuid FROM t WHERE name = 'frank'),
    'Y3JlZGVudGlhbC1mcmFuay0wMDE', '\x01020304', -7, 0, 'YubiKey', repeat('1', 64));
SELECT test.ok('Passkey über Registrierungslink registriert',
    EXISTS (SELECT 1 FROM verwaltung.anmelde_credential WHERE credential_id = 'Y3JlZGVudGlhbC1mcmFuay0wMDE'));
SELECT test.fehler('Registrierungslink nur einmal verwendbar',
    $$SELECT verwaltung.credential_registrieren('system:registrierung', (SELECT wert::uuid FROM t WHERE name = 'frank'),
        'Y3JlZGVudGlhbC1mcmFuay0wMDI', '\x05', -7, 0, 'zweiter', repeat('1', 64))$$, 'KM003');
SELECT test.fehler('Fremde dürfen keinen Passkey für jemand anderen registrieren',
    $$SELECT verwaltung.credential_registrieren('nutzer:00000000-0000-0000-0000-000000000000',
        (SELECT wert::uuid FROM t WHERE name = 'frank'), 'Y3JlZGVudGlhbC1mcmFuay0wMDI', '\x05', -7, 0, 'x')$$, 'KM003');
SELECT test.fehler('Nur ES256 und EdDSA (E3)',
    $$SELECT verwaltung.credential_registrieren('nutzer:' || (SELECT wert FROM t WHERE name = 'frank'),
        (SELECT wert::uuid FROM t WHERE name = 'frank'), 'Y3JlZGVudGlhbC1mcmFuay0wMDI', '\x05', -257, 0, 'RSA')$$, '23514');

-- Anmeldung: Challenge einmalig, Zähler steigt
SELECT verwaltung.challenge_ausstellen(repeat('A', 43));
SELECT verwaltung.challenge_einloesen(repeat('A', 43));
SELECT test.fehler('Challenge nur einmal (F68)',
    $$SELECT verwaltung.challenge_einloesen(repeat('A', 43))$$, 'KM003');
SELECT test.ok('Anmeldung liefert den Nutzer',
    verwaltung.anmeldung_vermerken('Y3JlZGVudGlhbC1mcmFuay0wMDE', 5)::text = (SELECT wert FROM t WHERE name = 'frank'));
SELECT test.fehler('Zähler muss steigen (geklonter Passkey)',
    $$SELECT verwaltung.anmeldung_vermerken('Y3JlZGVudGlhbC1mcmFuay0wMDE', 5)$$, 'KM003');

-- Sitzung
SELECT verwaltung.sitzung_anlegen('nutzer:' || (SELECT wert FROM t WHERE name = 'frank'), repeat('5', 64));
SELECT test.ok('Gültige Sitzung liefert das Subjekt',
    verwaltung.sitzung_pruefen(repeat('5', 64)) = 'nutzer:' || (SELECT wert FROM t WHERE name = 'frank'));
SELECT test.ok('Leerlauf überschritten → keine Sitzung',
    verwaltung.sitzung_pruefen(repeat('5', 64), interval '-1 second') IS NULL);
SELECT verwaltung.sitzung_beenden(repeat('5', 64));
SELECT test.ok('Beendete Sitzung gilt nicht mehr', verwaltung.sitzung_pruefen(repeat('5', 64)) IS NULL);

-- Mandant, Dienstkonto, Token (Zugänge von Nutzern gibt es in der Verwaltung nicht mehr, F114)
SELECT verwaltung.mandant_eintragen('system:cli', 'praxis', 'kumero_praxis', 'Praxis (Test)');
SELECT test.fehler('Verwaltung und Vorlage sind keine Mandanten',
    $$SELECT verwaltung.mandant_eintragen('system:cli', 'vorlage', 'kumero_vorlage', 'x')$$, '23514');
INSERT INTO t SELECT 'bot', verwaltung.dienstkonto_anlegen('system:cli', 'Kassenimport', 'praxis');
SELECT verwaltung.token_ausstellen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'bot'), repeat('7', 64), now() + interval '90 days');
SELECT test.ok('Token liefert Dienstkonto und genau seinen Mandanten',
    (SELECT subjekt || '@' || mandant FROM verwaltung.token_pruefen(repeat('7', 64)))
        = 'dienst:' || (SELECT wert FROM t WHERE name = 'bot') || '@praxis');
SELECT verwaltung.token_widerrufen('system:cli', repeat('7', 64));
SELECT test.ok('Widerrufenes Token gilt nicht', NOT EXISTS (SELECT 1 FROM verwaltung.token_pruefen(repeat('7', 64))));
SELECT test.fehler('Token höchstens 400 Tage',
    $$SELECT verwaltung.token_ausstellen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'bot'), repeat('8', 64), now() + interval '2 years')$$, 'KM002');

-- Mindestens ein aktiver Verwalter
SELECT test.fehler('Letzter Verwalter kann nicht deaktiviert werden',
    $$SELECT verwaltung.nutzer_aendern('system:cli', (SELECT wert::uuid FROM t WHERE name = 'frank'), 'Frank', 'de', true, false)$$, 'KM003');

-- Wasserstand (F69)
SELECT verwaltung.wasserstand_setzen('praxis', 1::smallint, 10, repeat('a', 64));
SELECT verwaltung.wasserstand_setzen('praxis', 1::smallint, 12, repeat('b', 64));
SELECT test.fehler('Wasserstand geht nicht zurück',
    $$SELECT verwaltung.wasserstand_setzen('praxis', 1::smallint, 11, repeat('c', 64))$$, 'KM003');
SELECT test.fehler('Gleiches Glied, anderer Hash wird erkannt',
    $$SELECT verwaltung.wasserstand_setzen('praxis', 1::smallint, 12, repeat('c', 64))$$, 'KM003');

-- Schutz und Protokoll
SELECT test.fehler('Anwendung schreibt nicht direkt (F64)',
    $$UPDATE verwaltung.nutzer SET verwalter = true$$, '42501');
SELECT test.ok('Geheimnisse stehen nicht im Protokoll',
    NOT EXISTS (SELECT 1 FROM verwaltung.protokoll WHERE ids::text LIKE '%' || repeat('7', 64) || '%'
                                                         OR coalesce(nachher::text, '') LIKE '%' || repeat('1', 64) || '%'));
SELECT test.ok('Änderungen protokolliert, Kette nachrechenbar',
    (SELECT count(*) FROM verwaltung.protokoll) = 7 AND NOT EXISTS (SELECT 1 FROM verwaltung.kette_pruefen()));
RESET ROLE;
SET ROLE kumero_owner;
SELECT test.fehler('Protokoll auch für den Eigentümer unveränderlich',
    $$UPDATE verwaltung.protokoll SET subjekt = 'x'$$, 'KM001');
RESET ROLE;
