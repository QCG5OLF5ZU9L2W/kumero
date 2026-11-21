-- Modul personal 0.13.0: Form des AU-Nachweises – elektronisch oder Papier (F155).
--
-- Vorgabe des Verantwortlichen vom 2026-11-21 am Dialog „Nachweis liegt vor?“: „AU elektronisch oder Papier; bei Papier mit
-- Upload erfassen.“ Hier kommt die Form dazu. Die Datei einer Papier-AU hält kumero nicht (F110): Sie geht an Paperless,
-- „unter dem Nutzer“ (Entscheidung vom selben Tag) – das ist als P44 entworfen und noch nicht gebaut.

ALTER TABLE personal.au ADD COLUMN nachweis_form text CHECK (nachweis_form IN ('elektronisch', 'papier'));

DROP FUNCTION personal.au_nachweis_setzen(text, uuid, boolean);
CREATE FUNCTION personal.au_nachweis_setzen(p_subjekt text, p_id uuid, p_liegt_vor boolean, p_form text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE v_person uuid;
BEGIN
    SELECT a.person INTO v_person FROM personal.au u JOIN personal.abwesenheit a ON a.id = u.abwesenheit WHERE u.abwesenheit = p_id FOR UPDATE OF u;
    IF NOT FOUND THEN PERFORM personal._fehler('Zu dieser Abwesenheit gibt es keine Bescheinigung', 'KM003'); END IF;
    IF coalesce(p_liegt_vor, false) AND coalesce(p_form, '') NOT IN ('elektronisch', 'papier') THEN
        PERFORM personal._fehler('Nachweis: elektronisch oder auf Papier');
    END IF;
    UPDATE personal.au SET nachweis_form = CASE WHEN coalesce(p_liegt_vor, false) THEN p_form END, nachweis = coalesce(p_liegt_vor, false), nachweis_am = clock_timestamp(), nachweis_von = p_subjekt WHERE abwesenheit = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.au_nachweis_vermerkt',
        jsonb_build_object('person', v_person::text, 'abwesenheit', p_id::text), NULL, jsonb_build_object('felder', jsonb_build_array('nachweis')));
END $$;

REVOKE EXECUTE ON FUNCTION personal.au_nachweis_setzen(text, uuid, boolean, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION personal.au_nachweis_setzen(text, uuid, boolean, text) TO kumero_app;
