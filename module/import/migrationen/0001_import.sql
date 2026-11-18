-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul import, Migration 0001: CSV-Dateien als Quelle des Arbeitsvorrats (IMPORT.md, F104)
--
-- Gespeichert werden Importprofile und je Import ein Bericht (wer, wann, Dateiname, Hash, Zahlen, Schlüssel der
-- Zeilen). Die Datei selbst wird nicht aufbewahrt; ihre Zeilen liegen als Einträge im Arbeitsvorrat.
-- Schreiben nur über Funktionen mit Protokoll (F48, F64).

CREATE SCHEMA import AUTHORIZATION kumero_owner;
REVOKE ALL ON SCHEMA import FROM PUBLIC;
GRANT USAGE ON SCHEMA import TO kumero_app, kumero_lesen;

-- Importprofil: subjekt '' = Vorgabe des Mandanten, sonst das eigene Profil eines Nutzers
CREATE TABLE import.profil (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    subjekt       text NOT NULL DEFAULT '',
    name          text NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 80),
    kopf          jsonb NOT NULL CHECK (jsonb_typeof(kopf) = 'array'),          -- Spaltennamen zum Wiedererkennen
    einstellungen jsonb NOT NULL CHECK (jsonb_typeof(einstellungen) = 'object'),
    geaendert_am  timestamptz NOT NULL DEFAULT now(),
    geaendert_von text NOT NULL,
    UNIQUE (subjekt, name)
);

CREATE TABLE import.lauf (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    am               timestamptz NOT NULL DEFAULT clock_timestamp(),
    subjekt          text NOT NULL,
    dateiname        text NOT NULL CHECK (length(dateiname) BETWEEN 1 AND 200),
    datei_sha256     text NOT NULL CHECK (datei_sha256 ~ '^[0-9a-f]{64}$'),
    profil           text,
    geldkonto        text NOT NULL,
    neu              integer NOT NULL CHECK (neu >= 0),
    vorhanden        integer NOT NULL CHECK (vorhanden >= 0),
    fehlerhaft       integer NOT NULL CHECK (fehlerhaft >= 0),
    schluessel       text[] NOT NULL,                                           -- Schlüssel der neu eingelieferten Zeilen
    zurueckgezogen_am  timestamptz,
    zurueckgezogen_von text
);
CREATE INDEX lauf_am ON import.lauf (am DESC);

CREATE FUNCTION import.profil_setzen(p_subjekt text, p_mandant boolean, p_name text, p_kopf jsonb, p_einstellungen jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, import, pg_temp AS $$
DECLARE
    wer text := CASE WHEN p_mandant THEN '' ELSE p_subjekt END;
    alt import.profil;
    neu uuid;
BEGIN
    SELECT * INTO alt FROM import.profil WHERE subjekt = wer AND name = trim(p_name) FOR UPDATE;
    INSERT INTO import.profil (subjekt, name, kopf, einstellungen, geaendert_von) VALUES (wer, trim(p_name), p_kopf, p_einstellungen, p_subjekt)
    ON CONFLICT (subjekt, name) DO UPDATE SET kopf = EXCLUDED.kopf, einstellungen = EXCLUDED.einstellungen,
        geaendert_am = clock_timestamp(), geaendert_von = EXCLUDED.geaendert_von
    RETURNING id INTO neu;
    PERFORM kern.modul_protokoll(p_subjekt, 'import', CASE WHEN alt.id IS NULL THEN 'import.profil_angelegt' ELSE 'import.profil_geaendert' END,
        jsonb_build_object('profil', neu::text, 'geltung', CASE WHEN p_mandant THEN 'mandant' ELSE 'nutzer' END),
        CASE WHEN alt.id IS NULL THEN NULL ELSE jsonb_build_object('name', alt.name, 'einstellungen', alt.einstellungen) END,
        jsonb_build_object('name', trim(p_name), 'einstellungen', p_einstellungen));
    RETURN neu;
END $$;

-- Löscht ein eigenes Profil oder (p_mandant) eine Vorgabe des Mandanten
CREATE FUNCTION import.profil_loeschen(p_subjekt text, p_mandant boolean, p_id uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, import, pg_temp AS $$
DECLARE alt import.profil;
BEGIN
    DELETE FROM import.profil WHERE id = p_id AND subjekt = CASE WHEN p_mandant THEN '' ELSE p_subjekt END RETURNING * INTO alt;
    IF NOT FOUND THEN
        RAISE EXCEPTION USING ERRCODE = 'KM003', MESSAGE = 'Importprofil unbekannt';
    END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'import', 'import.profil_geloescht', jsonb_build_object('profil', p_id::text),
        jsonb_build_object('name', alt.name, 'einstellungen', alt.einstellungen), NULL);
END $$;

CREATE FUNCTION import.lauf_anlegen(p_subjekt text, p_dateiname text, p_sha256 text, p_profil text, p_geldkonto text,
                                    p_neu integer, p_vorhanden integer, p_fehlerhaft integer, p_schluessel text[])
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, import, pg_temp AS $$
DECLARE neu_id uuid;
BEGIN
    INSERT INTO import.lauf (subjekt, dateiname, datei_sha256, profil, geldkonto, neu, vorhanden, fehlerhaft, schluessel)
    VALUES (p_subjekt, p_dateiname, p_sha256, p_profil, p_geldkonto, p_neu, p_vorhanden, p_fehlerhaft, p_schluessel) RETURNING id INTO neu_id;
    -- das Protokoll nennt Zahlen und den Hash der Datei, nie Inhalte
    PERFORM kern.modul_protokoll(p_subjekt, 'import', 'import.datei_eingelesen', jsonb_build_object('import', neu_id::text), NULL,
        jsonb_build_object('datei_sha256', p_sha256, 'neu', p_neu, 'vorhanden', p_vorhanden, 'fehlerhaft', p_fehlerhaft));
    RETURN neu_id;
END $$;

CREATE FUNCTION import.lauf_zurueckgezogen(p_subjekt text, p_id uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, import, pg_temp AS $$
BEGIN
    UPDATE import.lauf SET zurueckgezogen_am = clock_timestamp(), zurueckgezogen_von = p_subjekt WHERE id = p_id AND zurueckgezogen_am IS NULL;
    IF NOT FOUND THEN
        RAISE EXCEPTION USING ERRCODE = 'KM003', MESSAGE = 'Import unbekannt oder schon zurückgezogen';
    END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'import', 'import.import_zurueckgezogen', jsonb_build_object('import', p_id::text), NULL, NULL);
END $$;

REVOKE ALL ON ALL TABLES IN SCHEMA import FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON ALL TABLES IN SCHEMA import TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA import FROM PUBLIC;
GRANT EXECUTE ON FUNCTION import.profil_setzen(text, boolean, text, jsonb, jsonb), import.profil_loeschen(text, boolean, uuid),
    import.lauf_anlegen(text, text, text, text, text, integer, integer, integer, text[]), import.lauf_zurueckgezogen(text, uuid) TO kumero_app;
