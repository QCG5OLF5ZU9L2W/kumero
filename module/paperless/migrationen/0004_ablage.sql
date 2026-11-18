-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul paperless, Migration 0004: Einstellung des Mandanten zur Belegablage (IMPORT.md Abschnitt 16, D5; F112)
--
-- Paperless-ngx nimmt eine E-Rechnung als reines XML (XRechnung) nur an, wenn dort ein Parser-Plugin für
-- E-Rechnungen eingerichtet ist. Ob das so ist, sagt der Mandant hier. Ohne die Einstellung lagert kumero ein XML gar
-- nicht erst zwischen – sonst bliebe ein Original liegen, das das DMS nie annimmt (F110).

CREATE TABLE paperless.ablage (
    einzig         boolean PRIMARY KEY DEFAULT true CHECK (einzig),
    e_rechnung_xml boolean NOT NULL DEFAULT false,
    geaendert_am   timestamptz NOT NULL DEFAULT now(),
    geaendert_von  text NOT NULL
);

CREATE FUNCTION paperless.ablage_setzen(p_subjekt text, p_e_rechnung_xml boolean)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, paperless, pg_temp AS $$
DECLARE alt boolean;
BEGIN
    SELECT e_rechnung_xml INTO alt FROM paperless.ablage FOR UPDATE;
    INSERT INTO paperless.ablage (e_rechnung_xml, geaendert_von) VALUES (p_e_rechnung_xml, p_subjekt)
    ON CONFLICT (einzig) DO UPDATE SET e_rechnung_xml = EXCLUDED.e_rechnung_xml, geaendert_am = clock_timestamp(), geaendert_von = EXCLUDED.geaendert_von;
    PERFORM kern.modul_protokoll(p_subjekt, 'paperless', 'paperless.ablage_geaendert', '{}'::jsonb,
        jsonb_build_object('e_rechnung_xml', coalesce(alt, false)), jsonb_build_object('e_rechnung_xml', p_e_rechnung_xml));
END $$;

REVOKE ALL ON paperless.ablage FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON paperless.ablage TO kumero_app;
REVOKE EXECUTE ON FUNCTION paperless.ablage_setzen(text, boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION paperless.ablage_setzen(text, boolean) TO kumero_app;
