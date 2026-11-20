-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul auswertungen, Migration 0001: gespeicherte Auswertungen (AUSWERTUNGEN.md Abschnitt 8, F128)
--
-- Eigene Daten im eigenen Schema (F44). Eine gespeicherte Auswertung ist eine Beschreibung – welche Konten, welche
-- Spalten, welche Gliederung, welcher Zeitraum –, keine Zahl: Gerechnet wird bei jedem Aufruf neu aus dem Journal.
-- Sie gehört dem, der sie angelegt hat; „geteilt“ macht sie für alle im Buch sichtbar. Ändern und löschen kann nur
-- der Besitzer. Schreiben nur über Funktionen, mit Protokoll (F48, F64).

CREATE SCHEMA auswertungen AUTHORIZATION kumero_owner;
REVOKE ALL ON SCHEMA auswertungen FROM PUBLIC;
GRANT USAGE ON SCHEMA auswertungen TO kumero_app, kumero_lesen;

CREATE TABLE auswertungen.vorlage (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    besitzer      text NOT NULL,
    name          text NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 80),
    geteilt       boolean NOT NULL DEFAULT false,
    definition    jsonb NOT NULL CHECK (jsonb_typeof(definition) = 'object' AND pg_column_size(definition) <= 65536),
    angelegt_am   timestamptz NOT NULL DEFAULT now(),
    geaendert_am  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (besitzer, name)
);

CREATE FUNCTION auswertungen._stand(v auswertungen.vorlage) RETURNS jsonb
LANGUAGE sql IMMUTABLE AS $$
    SELECT jsonb_build_object('name', v.name, 'geteilt', v.geteilt, 'definition', v.definition)
$$;

-- Legt eine Auswertung an (p_id NULL) oder ersetzt die eigene. Die Form der Definition prüft die Anwendung.
CREATE FUNCTION auswertungen.vorlage_setzen(p_subjekt text, p_id uuid, p_name text, p_geteilt boolean, p_definition jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, auswertungen, pg_temp AS $$
DECLARE
    alt auswertungen.vorlage;
    neu auswertungen.vorlage;
BEGIN
    IF p_subjekt IS NULL OR length(p_subjekt) = 0 THEN
        RAISE EXCEPTION USING ERRCODE = 'KM002', MESSAGE = 'Subjekt fehlt';
    END IF;
    IF p_id IS NULL THEN
        IF (SELECT count(*) FROM auswertungen.vorlage WHERE besitzer = p_subjekt) >= 200 THEN
            RAISE EXCEPTION USING ERRCODE = 'KM003', MESSAGE = 'Höchstens 200 gespeicherte Auswertungen je Nutzer';
        END IF;
        IF EXISTS (SELECT 1 FROM auswertungen.vorlage WHERE besitzer = p_subjekt AND name = trim(p_name)) THEN
            RAISE EXCEPTION USING ERRCODE = 'KM003', MESSAGE = 'Eine eigene Auswertung mit diesem Namen gibt es schon';
        END IF;
        INSERT INTO auswertungen.vorlage (besitzer, name, geteilt, definition)
             VALUES (p_subjekt, trim(p_name), coalesce(p_geteilt, false), p_definition) RETURNING * INTO neu;
        PERFORM kern.modul_protokoll(p_subjekt, 'auswertungen', 'auswertungen.vorlage_angelegt',
            jsonb_build_object('vorlage', neu.id::text), NULL, auswertungen._stand(neu));
        RETURN neu.id;
    END IF;
    SELECT * INTO alt FROM auswertungen.vorlage WHERE id = p_id FOR UPDATE;
    IF NOT FOUND OR alt.besitzer <> p_subjekt THEN
        -- eine fremde Auswertung sieht für den, der sie ändern will, aus wie eine unbekannte
        RAISE EXCEPTION USING ERRCODE = 'KM003', MESSAGE = 'Auswertung unbekannt oder nicht die eigene';
    END IF;
    IF EXISTS (SELECT 1 FROM auswertungen.vorlage WHERE besitzer = p_subjekt AND name = trim(p_name) AND id <> p_id) THEN
        RAISE EXCEPTION USING ERRCODE = 'KM003', MESSAGE = 'Eine eigene Auswertung mit diesem Namen gibt es schon';
    END IF;
    UPDATE auswertungen.vorlage SET name = trim(p_name), geteilt = coalesce(p_geteilt, false), definition = p_definition,
           geaendert_am = clock_timestamp() WHERE id = p_id RETURNING * INTO neu;
    PERFORM kern.modul_protokoll(p_subjekt, 'auswertungen', 'auswertungen.vorlage_geaendert',
        jsonb_build_object('vorlage', p_id::text), auswertungen._stand(alt), auswertungen._stand(neu));
    RETURN p_id;
END $$;

CREATE FUNCTION auswertungen.vorlage_loeschen(p_subjekt text, p_id uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, auswertungen, pg_temp AS $$
DECLARE alt auswertungen.vorlage;
BEGIN
    DELETE FROM auswertungen.vorlage WHERE id = p_id AND besitzer = p_subjekt RETURNING * INTO alt;
    IF NOT FOUND THEN
        RAISE EXCEPTION USING ERRCODE = 'KM003', MESSAGE = 'Auswertung unbekannt oder nicht die eigene';
    END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'auswertungen', 'auswertungen.vorlage_geloescht',
        jsonb_build_object('vorlage', p_id::text), auswertungen._stand(alt), NULL);
END $$;

REVOKE ALL ON ALL TABLES IN SCHEMA auswertungen FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON ALL TABLES IN SCHEMA auswertungen TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA auswertungen FROM PUBLIC;
GRANT EXECUTE ON FUNCTION auswertungen.vorlage_setzen(text, uuid, text, boolean, jsonb), auswertungen.vorlage_loeschen(text, uuid) TO kumero_app;
