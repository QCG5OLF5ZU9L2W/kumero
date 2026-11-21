-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Kern, Migration 0018: Was ein Modul einer Rolle schon gegeben hat (VERWALTUNG.md Abschnitt 16, MO9; F143)
--
-- Ein Modul nennt im Manifest, welche Rolle welchen Baustein bekommt. Bisher gab jedes Einschalten und jedes Nachziehen
-- einer Rolle jeden dieser Bausteine zurück – auch einen, den der Administrator ihr bewusst genommen hatte. Das darf
-- nicht sein (Entscheidung des Verantwortlichen vom 2026-11-21). Hier steht deshalb, welches Paar aus Rolle und
-- Baustein ein Modul **schon einmal gegeben** hat: Gegeben wird jedes Paar genau einmal. Was danach an der Rolle
-- geändert wird, bleibt.
-- Geschrieben wird nur beim Einschalten bzw. Nachziehen (Dienst oder Kommandozeile, kumero_owner).

CREATE TABLE kern.modul_rollenbaustein (
    modul       text NOT NULL REFERENCES kern.modul,
    rolle       text NOT NULL,
    baustein    text NOT NULL,
    gegeben_am  timestamptz NOT NULL DEFAULT now(),
    gegeben     boolean NOT NULL,                 -- false: beim ersten Lauf dieser Regel als „früher gegeben“ übernommen
    PRIMARY KEY (modul, rolle, baustein)
);
REVOKE ALL ON kern.modul_rollenbaustein FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON kern.modul_rollenbaustein TO kumero_app;

CREATE FUNCTION kern.modul_rollenbaustein_merken(p_subjekt text, p_modul text, p_rolle text, p_baustein text, p_gegeben boolean)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    INSERT INTO kern.modul_rollenbaustein (modul, rolle, baustein, gegeben) VALUES (p_modul, p_rolle, p_baustein, coalesce(p_gegeben, true))
        ON CONFLICT DO NOTHING;
END $$;
REVOKE EXECUTE ON FUNCTION kern.modul_rollenbaustein_merken(text, text, text, text, boolean) FROM PUBLIC;
