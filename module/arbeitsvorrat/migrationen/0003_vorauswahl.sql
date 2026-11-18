-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul arbeitsvorrat, Migration 0003: Vorauswahl des Nutzers am Eintrag (ARBEITSVORRAT.md X1, X7; F96)
--
-- Konto, Gegenkonto und Buchungsart lassen sich schon im Arbeitsvorrat wählen – je Eintrag in der Liste
-- oder für viele markierte auf einmal. Das ist eine Vorbelegung, keine Buchung; sie steht getrennt von den
-- Vorschlägen der Quellen und geht beim Neubilden der Felder nicht verloren.
ALTER TABLE arbeitsvorrat.eintrag
    ADD COLUMN vor_konto       text CHECK (length(vor_konto) BETWEEN 1 AND 20),
    ADD COLUMN vor_gegenkonto  text CHECK (length(vor_gegenkonto) BETWEEN 1 AND 20),
    ADD COLUMN vor_buchungsart text CHECK (length(vor_buchungsart) BETWEEN 1 AND 16);

-- Setzt die Vorauswahl für viele Einträge auf einmal. p_daten nennt nur, was sich ändern soll:
-- {konto?, gegenkonto?, buchungsart?}; null leert. Nur offene Einträge. Ergebnis: Zahl der geänderten.
CREATE FUNCTION arbeitsvorrat.vorauswahl_setzen(p_subjekt text, p_eintraege uuid[], p_daten jsonb)
RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, arbeitsvorrat, pg_temp AS $$
DECLARE n integer;
BEGIN
    IF p_daten ->> 'konto' IS NOT NULL AND p_daten ->> 'konto' = p_daten ->> 'gegenkonto' THEN
        PERFORM arbeitsvorrat._fehler('Konto und Gegenkonto müssen verschieden sein (F4)', 'KM002');
    END IF;
    UPDATE arbeitsvorrat.eintrag SET
           vor_konto       = CASE WHEN p_daten ? 'konto'       THEN nullif(p_daten ->> 'konto', '')       ELSE vor_konto END,
           vor_gegenkonto  = CASE WHEN p_daten ? 'gegenkonto'  THEN nullif(p_daten ->> 'gegenkonto', '')  ELSE vor_gegenkonto END,
           vor_buchungsart = CASE WHEN p_daten ? 'buchungsart' THEN nullif(p_daten ->> 'buchungsart', '') ELSE vor_buchungsart END
     WHERE id = ANY(p_eintraege) AND zustand = 'offen';
    GET DIAGNOSTICS n = ROW_COUNT;
    IF EXISTS (SELECT 1 FROM arbeitsvorrat.eintrag WHERE id = ANY(p_eintraege) AND vor_konto IS NOT NULL AND vor_konto = vor_gegenkonto) THEN
        PERFORM arbeitsvorrat._fehler('Konto und Gegenkonto müssen verschieden sein (F4)', 'KM002');
    END IF;
    IF n > 0 THEN
        PERFORM kern.modul_protokoll(p_subjekt, 'arbeitsvorrat', 'arbeitsvorrat.vorauswahl_gesetzt',
            jsonb_build_object('eintraege', n), NULL, p_daten);
    END IF;
    RETURN n;
END $$;
REVOKE EXECUTE ON FUNCTION arbeitsvorrat.vorauswahl_setzen(text, uuid[], jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION arbeitsvorrat.vorauswahl_setzen(text, uuid[], jsonb) TO kumero_app;
