-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Datenbank des Mandanten, Migration 0003: Ein Modul am Mandanten ausschalten (VERWALTUNG.md Abschnitt 16, F142)
--
-- Gleiche Funktion wie im Kern eines Buchs (kern.modul_ausschalten). Am Mandanten nimmt das Ausschalten auch die
-- Rechte weg: mandant.rechte_des_subjekts zählt nur Rechte aktiver Module. Daten, Rollen und Zuweisungen bleiben.

CREATE FUNCTION mandant.modul_ausschalten(p_subjekt text, p_name text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE alt mandant.modul;
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    SELECT * INTO alt FROM mandant.modul WHERE name = p_name FOR UPDATE;
    IF NOT FOUND OR NOT alt.aktiv THEN
        PERFORM mandant.verweigern(format('Modul %s ist am Mandanten nicht aktiv', p_name), 'KM003');
    END IF;
    UPDATE mandant.modul SET aktiv = false WHERE name = p_name;
    PERFORM mandant._protokoll('modul.ausgeschaltet', p_subjekt, jsonb_build_object('modul', p_name),
        jsonb_build_object('version', alt.version, 'aktiv', true), jsonb_build_object('version', alt.version, 'aktiv', false));
END $$;
REVOKE EXECUTE ON FUNCTION mandant.modul_ausschalten(text, text) FROM PUBLIC;
