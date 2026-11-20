-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Verwaltungsdatenbank, Migration 0005: Die Anwendung trägt Pseudonyme ein (VERWALTUNG.md Abschnitt 15, F125)
--
-- Mit der Seite „Nutzer und Rollen“ legt der Administrator eines Mandanten Nutzer in der Weboberfläche an. Dafür
-- braucht die Anwendung (kumero_app) das Recht, die Zuordnung Pseudonym → Mandant einzutragen – bisher hatte es nur
-- die Kommandozeile. Die Zuordnung nennt weiter keinen Namen, keine Rolle und keinen Schlüssel (F114, F115).
--
-- Es gibt keine gemeinsame Transaktion mit der Datenbank des Mandanten: erst die Zuordnung, dann die Identität.
-- Scheitert das Zweite, nimmt `pseudonym_entfernen` die Zuordnung wieder weg. Entfernt wird nur ein Pseudonym genau
-- dieses Mandanten; ein vorhandenes lässt sich weiterhin nicht auf einen anderen Mandanten umlenken.

CREATE FUNCTION verwaltung.pseudonym_entfernen(p_pseudonym text, p_mandant text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
BEGIN
    IF p_mandant IS NULL THEN
        PERFORM verwaltung.verweigern('Pseudonyme der Installation werden nicht entfernt', 'KM003');
    END IF;
    DELETE FROM verwaltung.pseudonym WHERE pseudonym = p_pseudonym AND mandant = p_mandant;
END $$;

REVOKE EXECUTE ON FUNCTION verwaltung.pseudonym_entfernen(text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION verwaltung.pseudonym_eintragen(text, text), verwaltung.pseudonym_entfernen(text, text) TO kumero_app;
