-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Tests DDL Teil 2 im Produktivbetrieb: Freigabe per Challenge (F38, F67, F68).
-- Die WebAuthn-Kryptografie prüft die Anwendung; hier wird ihr Ergebnis
-- ("pruefergebnis": {"ok": true}) mit synthetischen Bytes simuliert.

SET ROLE kumero_app;

SELECT kern.buch_anlegen('test:admin', 'Musterpraxis (Produktiv-Test)', 'EUR');
SELECT kern.konto_anlegen('test:admin', '1200', 'Bank', 'aktiv');
SELECT kern.konto_anlegen('test:admin', '4930', 'Bürobedarf', 'aufwand');
SELECT kern.konto_anlegen('test:admin', '8400', 'Erlöse', 'ertrag');
SELECT kern.nummernkreis_anlegen('test:admin', 'ALLG', 'Allgemein');
SELECT kern.buchungsart_anlegen('test:admin', 'BANK', 'Bank', 'ALLG');
SELECT kern.geschaeftsjahr_anlegen('test:admin', '2026', '2026-01-01', '2026-12-31');
SELECT kern.nummernkreis_festlegen('test:admin', 'ALLG', '2026', 'B{NR:4}', true);

CREATE TEMP TABLE t (name text PRIMARY KEY, id uuid, wert text);
INSERT INTO t SELECT 'frank', kern.credential_registrieren('test:admin', 'test:frank', '\x01'::bytea, '\xa1'::bytea, -7, 'YubiKey Muster'), NULL;
INSERT INTO t SELECT 'azubi', kern.credential_registrieren('test:admin', 'test:azubi', '\x02'::bytea, '\xa2'::bytea, -7, 'Passkey Azubi'), NULL;

-- Hilfsfunktion des Tests: simulierte, von der Anwendung geprüfte Assertion
CREATE FUNCTION pg_temp.assertion(ch uuid, cred uuid, zaehler bigint) RETURNS jsonb
LANGUAGE sql AS $$
    SELECT jsonb_build_object('challenge', ch, 'credential', cred, 'sign_count', zaehler,
        'authenticator_data', 'aabb', 'client_data_json', '7b7d', 'signatur', 'ccdd',
        'pruefergebnis', '{"ok": true, "origin": "https://kumero.test", "uv": true}'::jsonb)
$$;

-- Produktivbetrieb einschalten (F59, F70)
SELECT test.fehler('Produktivbetrieb nur mit Freigabe',
    $$SELECT kern.produktiv_einschalten('test:frank', NULL)$$, 'KM003');
INSERT INTO t SELECT 'ch0', o_id, o_wert FROM kern.challenge_ausstellen('test:frank', 'produktivbetrieb',
    kern.inhalt_hash(kern.produktiv_inhalt()), 'muster', decode(repeat('11', 32), 'hex'));
SELECT kern.produktiv_einschalten('test:frank', pg_temp.assertion((SELECT id FROM t WHERE name = 'ch0'), (SELECT id FROM t WHERE name = 'frank'), 1));
SELECT test.ok('Produktivbetrieb eingeschaltet', (SELECT betriebsart FROM kern.buch) = 'produktiv');

-- Zwei Entwürfe, Stapelfreigabe
INSERT INTO t SELECT 'b'||n, kern.entwurf_anlegen('test:azubi', jsonb_build_object(
    'buchungsart', 'BANK', 'belegdatum', '2026-05-0'||n, 'buchungsdatum', '2026-05-0'||n, 'text', 'Muster '||n,
    'zeilen', jsonb_build_array(jsonb_build_object('konto','4930','seite','S','betrag', n||'0.00'),
                                jsonb_build_object('konto','1200','seite','H','betrag', n||'0.00')))), NULL
  FROM generate_series(1, 2) n;
SELECT kern.vorbereiten('test:frank', id) FROM t WHERE name IN ('b1', 'b2');

SELECT test.fehler('Ohne Freigabe kein Festschreiben im Produktivbetrieb (F38)',
    $$SELECT * FROM kern.festschreiben('test:frank', ARRAY(SELECT id FROM t WHERE name IN ('b1','b2') ORDER BY name))$$, 'KM003');

INSERT INTO t SELECT 'stapel', NULL, kern.stapel_hash(ARRAY(SELECT id FROM t WHERE name IN ('b1','b2') ORDER BY name));
INSERT INTO t SELECT 'ch1', o_id, o_wert FROM kern.challenge_ausstellen('test:frank', 'festschreibung',
    (SELECT wert FROM t WHERE name = 'stapel'), 'muster', decode(repeat('22', 32), 'hex'));

SELECT test.fehler('Challenge ist an das Subjekt gebunden (F68)',
    $$SELECT * FROM kern.festschreiben('test:azubi', ARRAY(SELECT id FROM t WHERE name IN ('b1','b2') ORDER BY name),
        pg_temp.assertion((SELECT id FROM t WHERE name = 'ch1'), (SELECT id FROM t WHERE name = 'azubi'), 1))$$, 'KM003');
SELECT test.fehler('Fremdes Credential wird abgelehnt (F68)',
    $$SELECT * FROM kern.festschreiben('test:frank', ARRAY(SELECT id FROM t WHERE name IN ('b1','b2') ORDER BY name),
        pg_temp.assertion((SELECT id FROM t WHERE name = 'ch1'), (SELECT id FROM t WHERE name = 'azubi'), 1))$$, 'KM003');
SELECT test.fehler('Andere Reihenfolge = anderer Stapel (F67)',
    $$SELECT * FROM kern.festschreiben('test:frank', ARRAY(SELECT id FROM t WHERE name IN ('b1','b2') ORDER BY name DESC),
        pg_temp.assertion((SELECT id FROM t WHERE name = 'ch1'), (SELECT id FROM t WHERE name = 'frank'), 2))$$, 'KM003');
SELECT test.fehler('Signaturzähler muss steigen (F68)',
    $$SELECT * FROM kern.festschreiben('test:frank', ARRAY(SELECT id FROM t WHERE name IN ('b1','b2') ORDER BY name),
        pg_temp.assertion((SELECT id FROM t WHERE name = 'ch1'), (SELECT id FROM t WHERE name = 'frank'), 1))$$, 'KM003');

SELECT * FROM kern.festschreiben('test:frank', ARRAY(SELECT id FROM t WHERE name IN ('b1','b2') ORDER BY name),
    pg_temp.assertion((SELECT id FROM t WHERE name = 'ch1'), (SELECT id FROM t WHERE name = 'frank'), 2));
SELECT test.ok('Stapel festgeschrieben, Positionsliste gespeichert (F67)',
    (SELECT count(*) FROM kern.freigabe_position) = 2
    AND (SELECT string_agg(nr, ',' ORDER BY journal_nr) FROM kern.buchung) = 'B0001,B0002');
SELECT test.ok('Erfasser ≠ Freigebender (Vier-Augen-Nachweis, F31)',
    (SELECT bool_and(erfasst_von = 'test:azubi' AND festgeschrieben_von = 'test:frank' AND NOT testbetrieb) FROM kern.buchung));
SELECT test.ok('Stapel-Hash ist unabhängig nachprüfbar (F67)',
    (SELECT f.inhalt_hash FROM kern.freigabe f WHERE f.art = 'festschreibung')
    = kern.inhalt_hash((SELECT jsonb_agg(jsonb_build_object('pos', p.pos, 'buchung', p.buchung::text, 'freigabe_hash', p.freigabe_hash) ORDER BY p.pos)
                          FROM kern.freigabe_position p)));
SELECT test.ok('Kette nachrechenbar, Signatur eingebunden', NOT EXISTS (SELECT 1 FROM kern.kette_pruefen()));

SELECT test.fehler('Challenge nicht wiederverwendbar (F68)',
    $$SELECT kern.geschaeftsjahr_sperren('test:frank', '2026',
        pg_temp.assertion((SELECT id FROM t WHERE name = 'ch1'), (SELECT id FROM t WHERE name = 'frank'), 3))$$, 'KM003');

-- Inhalt ändert sich zwischen Challenge und Freigabe → abgelehnt
INSERT INTO t SELECT 'ch2', o_id, o_wert FROM kern.challenge_ausstellen('test:frank', 'gj_sperren',
    kern.inhalt_hash(kern.gj_sperr_inhalt('2026')), 'muster', decode(repeat('33', 32), 'hex'));
INSERT INTO t SELECT 'b3', kern.entwurf_anlegen('test:azubi', '{"buchungsart":"BANK","belegdatum":"2026-06-01",
    "buchungsdatum":"2026-06-01","text":"dazwischen","zeilen":[{"konto":"4930","seite":"S","betrag":"1.00"},
    {"konto":"1200","seite":"H","betrag":"1.00"}]}'), NULL;
SELECT kern.vorbereiten('test:frank', (SELECT id FROM t WHERE name = 'b3'));
INSERT INTO t SELECT 'ch3', o_id, o_wert FROM kern.challenge_ausstellen('test:frank', 'festschreibung',
    kern.buchung_freigabe_hash((SELECT id FROM t WHERE name = 'b3')), 'muster', decode(repeat('44', 32), 'hex'));
SELECT * FROM kern.festschreiben('test:frank', ARRAY[(SELECT id FROM t WHERE name = 'b3')],
    pg_temp.assertion((SELECT id FROM t WHERE name = 'ch3'), (SELECT id FROM t WHERE name = 'frank'), 3));
SELECT test.fehler('Sperre mit veraltetem Inhalt wird abgelehnt (F68)',
    $$SELECT kern.geschaeftsjahr_sperren('test:frank', '2026',
        pg_temp.assertion((SELECT id FROM t WHERE name = 'ch2'), (SELECT id FROM t WHERE name = 'frank'), 4))$$, 'KM003');

-- Widerrufenes Credential
SELECT kern.credential_widerrufen('test:admin', (SELECT id FROM t WHERE name = 'frank'), 'Schlüssel verloren (Test)');
INSERT INTO t SELECT 'ch4', o_id, o_wert FROM kern.challenge_ausstellen('test:frank', 'gj_sperren',
    kern.inhalt_hash(kern.gj_sperr_inhalt('2026')), 'muster', decode(repeat('55', 32), 'hex'));
SELECT test.fehler('Widerrufenes Credential wird abgelehnt (F38)',
    $$SELECT kern.geschaeftsjahr_sperren('test:frank', '2026',
        pg_temp.assertion((SELECT id FROM t WHERE name = 'ch4'), (SELECT id FROM t WHERE name = 'frank'), 5))$$, 'KM003');

SELECT test.fehler('Challenge-Gültigkeit begrenzt',
    $$SELECT * FROM kern.challenge_ausstellen('test:frank', 'storno', repeat('a',64), 'm', decode(repeat('66',32),'hex'), '1 hour')$$, 'KM002');

SELECT test.ok('Webhook-Ereignisse enthalten nur IDs (F48)',
    NOT EXISTS (SELECT 1 FROM kern.outbox WHERE inhalt::text ~ 'betrag|text|Muster'));
RESET ROLE;
