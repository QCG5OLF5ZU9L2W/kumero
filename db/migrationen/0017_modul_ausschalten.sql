-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Kern, Migration 0017: Ein Modul im Buch ausschalten (VERWALTUNG.md Abschnitt 16, F142)
--
-- `kern.modul.aktiv` gibt es seit Migration 0002; gesetzt wurde es bisher nur auf „an“ (kern.modul_registrieren).
-- Ausschalten nimmt dem Modul seine Wege – Menü, Seiten, Schnittstelle, Erweiterungspunkte –, **nicht seine Daten**:
-- Schema, Tabellen, Rechte, Bausteine und Rollen bleiben. Wieder einschalten ist ein erneutes Registrieren.
-- Wie das Registrieren führt es nur der Dienst bzw. die Kommandozeile aus (kumero_owner).

CREATE FUNCTION kern.modul_ausschalten(p_subjekt text, p_name text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE alt kern.modul;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    IF p_name = 'kern' THEN
        PERFORM kern.verweigern('Der Kern lässt sich nicht ausschalten', 'KM002');
    END IF;
    SELECT * INTO alt FROM kern.modul WHERE name = p_name FOR UPDATE;
    IF NOT FOUND OR NOT alt.aktiv THEN
        PERFORM kern.verweigern(format('Modul %s ist in diesem Buch nicht aktiv', p_name), 'KM003');
    END IF;
    UPDATE kern.modul SET aktiv = false WHERE name = p_name;
    PERFORM kern._protokoll('modul.ausgeschaltet', p_subjekt, jsonb_build_object('modul', p_name),
        jsonb_build_object('version', alt.version, 'aktiv', true), jsonb_build_object('version', alt.version, 'aktiv', false));
END $$;
REVOKE EXECUTE ON FUNCTION kern.modul_ausschalten(text, text) FROM PUBLIC;
