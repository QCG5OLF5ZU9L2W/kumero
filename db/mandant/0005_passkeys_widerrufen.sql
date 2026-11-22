-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Datenbank des Mandanten, Migration 0005: Alle Passkeys widerrufen (VERWALTUNG.md Abschnitt 18, DM7; F177)
--
-- Ein Passkey gilt für den Hostnamen, unter dem er eingerichtet wurde. Bekommt ein Mandant eine eigene Domain, wechselt
-- sie oder gibt sie auf, gilt keiner seiner Passkeys mehr. Sie bleiben dann nicht still liegen: Sie werden widerrufen,
-- offene Sitzungen enden, und im Protokoll des Mandanten steht, dass und warum. Danach sind alle Zugänge neu auszustellen.
-- Aufgerufen vom System-Verwalter (Weboberfläche oder Kommandozeile) zusammen mit verwaltung.mandant_adresse_setzen.

CREATE FUNCTION mandant.passkeys_alle_widerrufen(p_subjekt text, p_grund text)
RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE n integer;
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    IF p_grund IS NULL OR p_grund NOT IN ('adresse') THEN
        PERFORM mandant.verweigern('Unbekannter Grund', 'KM002');
    END IF;
    UPDATE mandant.anmelde_credential SET widerrufen_am = now() WHERE widerrufen_am IS NULL;
    GET DIAGNOSTICS n = ROW_COUNT;
    UPDATE mandant.sitzung SET beendet_am = now() WHERE beendet_am IS NULL;
    PERFORM mandant._protokoll('mandant.passkeys_widerrufen', p_subjekt, jsonb_build_object('grund', p_grund, 'anzahl', n));
    RETURN n;
END $$;

REVOKE ALL ON FUNCTION mandant.passkeys_alle_widerrufen(text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mandant.passkeys_alle_widerrufen(text, text) TO kumero_app;
