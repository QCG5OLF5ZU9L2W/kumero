-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Kleiner Testrahmen: ok(name, bedingung) und fehler(name, sql, sqlstate).
-- Läuft als Superuser in der Testdatenbank; Tests nehmen per SET ROLE die
-- jeweilige Rolle ein.
\set QUIET on
SET client_min_messages = notice;

CREATE SCHEMA IF NOT EXISTS test;
GRANT USAGE ON SCHEMA test TO PUBLIC;

CREATE OR REPLACE FUNCTION test.ok(name text, bedingung boolean) RETURNS void
LANGUAGE plpgsql AS $$
BEGIN
    IF bedingung IS NOT TRUE THEN
        RAISE EXCEPTION 'NICHT OK: %', name;
    END IF;
    RAISE NOTICE 'ok – %', name;
END $$;

CREATE OR REPLACE FUNCTION test.fehler(name text, anweisung text, erwartet text) RETURNS void
LANGUAGE plpgsql AS $$
DECLARE
    code text;
    meldung text;
BEGIN
    BEGIN
        EXECUTE anweisung;
    EXCEPTION WHEN OTHERS THEN
        GET STACKED DIAGNOSTICS code = RETURNED_SQLSTATE, meldung = MESSAGE_TEXT;
        IF code <> erwartet THEN
            RAISE EXCEPTION 'NICHT OK: % – erwartet %, erhalten % (%)', name, erwartet, code, meldung;
        END IF;
        RAISE NOTICE 'ok – % [% %]', name, code, meldung;
        RETURN;
    END;
    RAISE EXCEPTION 'NICHT OK: % – Fehler % erwartet, aber Anweisung lief durch', name, erwartet;
END $$;
GRANT EXECUTE ON FUNCTION test.ok(text, boolean), test.fehler(text, text, text) TO PUBLIC;
