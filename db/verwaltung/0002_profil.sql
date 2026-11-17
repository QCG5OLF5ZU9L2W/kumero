-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Verwaltungsdatenbank, Migration 0002: Nutzerprofil und Einstellungen (PROFIL.md, F86)
--
-- Einstellungen hängen am Nutzer, nicht am Mandanten und nicht am Gerät. Sie sind keine
-- Buchungsdaten (F37). Geschrieben wird nur über Funktionen mit Eintrag in der Protokollkette.
-- Das Protokoll nennt bei Einstellungen nur den Schlüssel, nicht den Wert: Darstellungs-
-- einstellungen (Schriftgröße, Kontrast) lassen Rückschlüsse auf die Person zu (PROFIL.md 4a).

-- Sprache „nicht gesetzt“ = wie der Browser (PROFIL.md U5)
ALTER TABLE verwaltung.nutzer ALTER COLUMN sprache DROP NOT NULL;
ALTER TABLE verwaltung.nutzer ALTER COLUMN sprache DROP DEFAULT;

CREATE TABLE verwaltung.nutzer_einstellung (
    nutzer       uuid NOT NULL REFERENCES verwaltung.nutzer,
    schluessel   text NOT NULL CHECK (schluessel ~ '^[a-z][a-z0-9-]{0,31}\.[a-z][a-z0-9_]{0,63}$'),   -- <besitzer>.<name>
    wert         jsonb NOT NULL,
    geaendert_am timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (nutzer, schluessel)
);

-- Der Nutzer stellt seine Sprache selbst ein; NULL = nicht gesetzt.
CREATE FUNCTION verwaltung.sprache_setzen(p_subjekt text, p_nutzer uuid, p_sprache text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE alt verwaltung.nutzer;
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    SELECT * INTO alt FROM verwaltung.nutzer WHERE id = p_nutzer FOR UPDATE;
    IF NOT FOUND THEN PERFORM verwaltung.verweigern('Nutzer unbekannt', 'KM002'); END IF;
    IF alt.sprache IS NOT DISTINCT FROM p_sprache THEN RETURN; END IF;
    UPDATE verwaltung.nutzer SET sprache = p_sprache WHERE id = p_nutzer;
    PERFORM verwaltung._protokoll('nutzer.sprache_geaendert', p_subjekt, jsonb_build_object('nutzer', p_nutzer::text),
        jsonb_build_object('sprache', alt.sprache), jsonb_build_object('sprache', p_sprache));
END $$;

-- Setzt eine Einstellung; p_wert NULL löscht sie (zurück zum Standard).
-- Welche Schlüssel und Werte es gibt, prüft die Anwendung (Kern bzw. Manifest des Moduls).
CREATE FUNCTION verwaltung.einstellung_setzen(p_subjekt text, p_nutzer uuid, p_schluessel text, p_wert jsonb)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE alt jsonb;
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    IF NOT EXISTS (SELECT 1 FROM verwaltung.nutzer WHERE id = p_nutzer) THEN
        PERFORM verwaltung.verweigern('Nutzer unbekannt', 'KM002');
    END IF;
    SELECT wert INTO alt FROM verwaltung.nutzer_einstellung WHERE nutzer = p_nutzer AND schluessel = p_schluessel FOR UPDATE;
    IF alt IS NOT DISTINCT FROM p_wert THEN RETURN; END IF;
    IF p_wert IS NULL OR jsonb_typeof(p_wert) = 'null' THEN
        DELETE FROM verwaltung.nutzer_einstellung WHERE nutzer = p_nutzer AND schluessel = p_schluessel;
    ELSE
        INSERT INTO verwaltung.nutzer_einstellung (nutzer, schluessel, wert) VALUES (p_nutzer, p_schluessel, p_wert)
            ON CONFLICT (nutzer, schluessel) DO UPDATE SET wert = EXCLUDED.wert, geaendert_am = now();
    END IF;
    PERFORM verwaltung._protokoll('nutzer.einstellung_geaendert', p_subjekt,
        jsonb_build_object('nutzer', p_nutzer::text, 'schluessel', p_schluessel));
END $$;

REVOKE ALL ON verwaltung.nutzer_einstellung FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON verwaltung.nutzer_einstellung TO kumero_app;
REVOKE EXECUTE ON FUNCTION verwaltung.sprache_setzen(text, uuid, text), verwaltung.einstellung_setzen(text, uuid, text, jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION verwaltung.sprache_setzen(text, uuid, text), verwaltung.einstellung_setzen(text, uuid, text, jsonb) TO kumero_app;
