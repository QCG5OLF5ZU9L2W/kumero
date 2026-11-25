-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul lager, Migration 0012: GTIN am Artikel ergänzen (BESTELLANFORDERUNG.md BA12, F222)
--
-- Der Einkauf ergänzt beim Bestellen, was am Artikel fehlt. Er ändert den Artikel nicht: Die Funktion trägt nur eine
-- GTIN ein, wo keine steht – eine vorhandene bleibt, wie sie ist (dafür gibt es „Bearbeiten …“ im Bestand).

CREATE FUNCTION lager.artikel_gtin_ergaenzen(p_subjekt text, p_id uuid, p_gtin text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE alt lager.artikel; neu lager.artikel;
BEGIN
    IF p_gtin IS NULL OR p_gtin !~ '^[0-9]{8,14}$' THEN
        PERFORM lager._fehler('GTIN: 8 bis 14 Ziffern erwartet');
    END IF;
    SELECT * INTO alt FROM lager.artikel WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM lager._fehler('Artikel unbekannt', 'KM003');
    END IF;
    IF alt.gtin IS NOT NULL THEN
        IF alt.gtin = p_gtin THEN
            RETURN;
        END IF;
        PERFORM lager._fehler('Am Artikel steht schon eine andere GTIN – ändern lässt sie sich im Bestand unter „Bearbeiten …“', 'KM003');
    END IF;
    UPDATE lager.artikel SET gtin = p_gtin, geaendert_am = clock_timestamp(), geaendert_von = p_subjekt WHERE id = p_id RETURNING * INTO neu;
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.artikel_geaendert', jsonb_build_object('artikel', p_id::text), lager._artikel_stand(alt), lager._artikel_stand(neu));
END $$;

REVOKE EXECUTE ON FUNCTION lager.artikel_gtin_ergaenzen(text, uuid, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION lager.artikel_gtin_ergaenzen(text, uuid, text) TO kumero_app;
