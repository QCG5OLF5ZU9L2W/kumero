-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul lager, Migration 0011: falsch Angelegtes löschen (LAGER.md 17.12, F214)
--
-- Bisher galt: Nichts wird gelöscht, nur stillgelegt (WW23). Das bleibt für alles, was je bewegt wurde – dort hängt die
-- Rückverfolgung daran. Ein Artikel oder eine Artikelgruppe, die falsch angelegt und nie benutzt wurde, lässt sich
-- dagegen löschen. Der Stand vor dem Löschen steht im Protokoll.

-- Löscht einen Artikel, der nie eine Bewegung hatte und in keiner geltenden Stückliste steht.
CREATE FUNCTION lager.artikel_loeschen(p_subjekt text, p_id uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE alt lager.artikel; v_in text; v_liste jsonb;
BEGIN
    SELECT * INTO alt FROM lager.artikel WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM lager._fehler('Artikel unbekannt', 'KM003');
    END IF;
    IF EXISTS (SELECT 1 FROM lager.charge WHERE artikel = p_id) THEN
        PERFORM lager._fehler('Der Artikel hatte schon Bestand – er bleibt für die Rückverfolgung stehen und lässt sich nur stilllegen', 'KM003');
    END IF;
    SELECT a.bezeichnung INTO v_in FROM lager.stueckliste_zeile s JOIN lager.artikel a ON a.id = s.artikel WHERE s.teil_artikel = p_id AND s.beendet_am IS NULL ORDER BY a.bezeichnung LIMIT 1;
    IF v_in IS NOT NULL THEN
        PERFORM lager._fehler(format('Der Artikel steht in der Stückliste von „%s“ – erst dort entfernen', v_in), 'KM003');
    END IF;
    v_liste := lager._stueckliste_stand(p_id);
    -- frühere Stücklisten, die ihn nannten, behalten seinen Namen als Text
    UPDATE lager.stueckliste_zeile SET teil_artikel = NULL, bezeichnung = left(alt.bezeichnung, 200) WHERE teil_artikel = p_id;
    DELETE FROM lager.stueckliste_zeile WHERE artikel = p_id;
    DELETE FROM lager.lieferantenartikel WHERE artikel = p_id;
    DELETE FROM lager.artikel WHERE id = p_id;
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.artikel_geloescht', jsonb_build_object('artikel', p_id::text),
        lager._artikel_stand(alt) || jsonb_build_object('stueckliste', v_liste), NULL);
END $$;

-- Löscht eine Artikelgruppe, der kein Artikel zugeordnet ist und die in keiner geltenden Stückliste steht.
CREATE FUNCTION lager.meta_loeschen(p_subjekt text, p_id uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE alt lager.meta_artikel; v_in text;
BEGIN
    SELECT * INTO alt FROM lager.meta_artikel WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM lager._fehler('Artikelgruppe unbekannt', 'KM003');
    END IF;
    IF EXISTS (SELECT 1 FROM lager.artikel WHERE meta_artikel = p_id) THEN
        PERFORM lager._fehler('Der Artikelgruppe sind noch Artikel zugeordnet – erst lösen', 'KM003');
    END IF;
    SELECT a.bezeichnung INTO v_in FROM lager.stueckliste_zeile s JOIN lager.artikel a ON a.id = s.artikel WHERE s.teil_meta = p_id AND s.beendet_am IS NULL ORDER BY a.bezeichnung LIMIT 1;
    IF v_in IS NOT NULL THEN
        PERFORM lager._fehler(format('Die Artikelgruppe steht in der Stückliste von „%s“ – erst dort entfernen', v_in), 'KM003');
    END IF;
    UPDATE lager.stueckliste_zeile SET teil_meta = NULL, bezeichnung = left(alt.bezeichnung, 200) WHERE teil_meta = p_id;
    DELETE FROM lager.meta_artikel WHERE id = p_id;
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.meta_geloescht', jsonb_build_object('meta_artikel', p_id::text),
        lager._meta_stand(alt), NULL);
END $$;

REVOKE EXECUTE ON FUNCTION lager.artikel_loeschen(text, uuid), lager.meta_loeschen(text, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION lager.artikel_loeschen(text, uuid), lager.meta_loeschen(text, uuid) TO kumero_app;
