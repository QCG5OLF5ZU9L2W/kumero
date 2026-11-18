-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul import, Migration 0003: auch eine E-Rechnung als reines XML liegt in der Zwischenablage (IMPORT.md Abschnitt 16, D5; F112)
--
-- Bisher lag nur das PDF einer ZUGFeRD-Rechnung vorläufig in kumero. Nimmt das DMS auch XML an, gilt für eine
-- XRechnung dasselbe: Zwischenablage, nach der Buchung Übergabe an das DMS, Beleg nachgereicht, in kumero gelöscht.
-- Lehnt das DMS eine Datei ab, wird der Grund vermerkt – die Seite „Datei einlesen“ nennt, wie viele das betrifft.

ALTER TABLE import.datei DROP CONSTRAINT datei_typ_check;
ALTER TABLE import.datei ADD CONSTRAINT datei_typ_check CHECK (typ IN ('application/pdf', 'application/xml'));
ALTER TABLE import.datei ADD COLUMN abgelehnt_grund text CHECK (abgelehnt_grund IS NULL OR length(abgelehnt_grund) BETWEEN 1 AND 100);

-- Vermerkt den Fehlercode der letzten Übergabe (NULL: kein Fehler mehr). Protokolliert wird nur ein Wechsel.
CREATE FUNCTION import.datei_abgelehnt(p_subjekt text, p_id uuid, p_grund text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, import, pg_temp AS $$
BEGIN
    UPDATE import.datei SET abgelehnt_grund = p_grund WHERE id = p_id AND inhalt IS NOT NULL AND abgelehnt_grund IS DISTINCT FROM p_grund;
    IF FOUND AND p_grund IS NOT NULL THEN
        PERFORM kern.modul_protokoll(p_subjekt, 'import', 'import.datei_abgelehnt', jsonb_build_object('datei', p_id::text), NULL, jsonb_build_object('grund', p_grund));
    END IF;
END $$;

REVOKE EXECUTE ON FUNCTION import.datei_abgelehnt(text, uuid, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION import.datei_abgelehnt(text, uuid, text) TO kumero_app;
GRANT SELECT (abgelehnt_grund) ON import.datei TO kumero_lesen;

-- Wie in Migration 0002; neu: Wird derselbe Schlüssel neu belegt, ist auch ein vermerkter Ablehnungsgrund weg.
CREATE OR REPLACE FUNCTION import.datei_ablegen(p_subjekt text, p_schluessel text, p_name text, p_typ text, p_inhalt_b64 text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, import, pg_temp AS $$
DECLARE
    v_inhalt bytea := decode(p_inhalt_b64, 'base64');
    v_id     uuid;
BEGIN
    INSERT INTO import.datei (schluessel, name, typ, groesse, sha256, md5, inhalt, abgelegt_von)
    VALUES (p_schluessel, p_name, p_typ, octet_length(v_inhalt), encode(sha256(v_inhalt), 'hex'), md5(v_inhalt), v_inhalt, p_subjekt)
    ON CONFLICT (schluessel) DO UPDATE SET name = EXCLUDED.name, typ = EXCLUDED.typ, groesse = EXCLUDED.groesse, sha256 = EXCLUDED.sha256,
        md5 = EXCLUDED.md5, inhalt = EXCLUDED.inhalt, abgelegt_am = clock_timestamp(), abgelegt_von = EXCLUDED.abgelegt_von,
        geloescht_am = NULL, geloescht_von = NULL, geloescht_grund = NULL, gesendet_am = NULL, dms_link = NULL, abgelehnt_grund = NULL
    RETURNING id INTO v_id;
    -- das Protokoll nennt Prüfsumme und Größe, nie den Inhalt und nicht den Dateinamen
    PERFORM kern.modul_protokoll(p_subjekt, 'import', 'import.datei_abgelegt', jsonb_build_object('datei', v_id::text), NULL,
        jsonb_build_object('sha256', encode(sha256(v_inhalt), 'hex'), 'groesse', octet_length(v_inhalt)));
    RETURN v_id;
END $$;
