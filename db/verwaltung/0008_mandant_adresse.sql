-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Verwaltungsdatenbank, Migration 0008: Eigene Domain je Mandant (VERWALTUNG.md Abschnitt 18, F177)
--
-- Ein Mandant kann unter seinem eigenen Hostnamen erreichbar sein. kumero muss ihn kennen, weil Passkeys an den Hostnamen
-- gebunden sind und die Anwendung Herkunft und RP-ID prüft (F68); einen unbekannten Hostnamen nimmt es nie an. Genau ein
-- Hostname je Mandant, freiwillig, eindeutig über die Installation (DM1). Zertifikat und Servername im Webserver trägt
-- ein, wer root auf der Maschine ist – kumero ändert seine Webserver-Konfiguration nie (DM14).

ALTER TABLE verwaltung.mandant ADD COLUMN adresse text UNIQUE
    CHECK (adresse IS NULL OR (length(adresse) <= 253 AND adresse ~ '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$'));
COMMENT ON COLUMN verwaltung.mandant.adresse IS
    'Hostname der eigenen Domain des Mandanten (klein, ohne Schema, Port und Pfad); NULL = erreichbar unter der Adresse der Installation';

-- Setzt, ändert oder entfernt (NULL) den Hostnamen. Gibt den bisherigen zurück. Dass die Passkeys des Mandanten damit
-- nicht mehr gelten (DM7), setzt der Aufrufer in der Datenbank des Mandanten um (mandant.passkeys_alle_widerrufen).
CREATE FUNCTION verwaltung.mandant_adresse_setzen(p_subjekt text, p_kennung text, p_adresse text)
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE alt text;
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    SELECT adresse INTO alt FROM verwaltung.mandant WHERE kennung = p_kennung FOR UPDATE;
    IF NOT FOUND THEN PERFORM verwaltung.verweigern('Mandant unbekannt', 'KM003'); END IF;
    IF alt IS NOT DISTINCT FROM p_adresse THEN RETURN alt; END IF;
    IF p_adresse IS NOT NULL AND EXISTS (SELECT 1 FROM verwaltung.mandant WHERE adresse = p_adresse) THEN
        PERFORM verwaltung.verweigern('Dieser Hostname gehört schon zu einem Mandanten', 'KM005');
    END IF;
    UPDATE verwaltung.mandant SET adresse = p_adresse WHERE kennung = p_kennung;
    PERFORM verwaltung._protokoll('mandant.adresse_gesetzt', p_subjekt, jsonb_build_object('mandant', p_kennung),
        jsonb_build_object('adresse', alt), jsonb_build_object('adresse', p_adresse));
    RETURN alt;
END $$;

REVOKE ALL ON FUNCTION verwaltung.mandant_adresse_setzen(text, text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION verwaltung.mandant_adresse_setzen(text, text, text) TO kumero_app;
