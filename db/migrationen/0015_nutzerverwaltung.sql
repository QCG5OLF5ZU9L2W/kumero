-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Kern, Migration 0015: Nutzer und Rollen in der Weboberfläche (VERWALTUNG.md Abschnitt 4, 6 und 15; F125)
--
--   * **Zugang mit Link und Code** (Abschnitt 6): Der Code hängt am Link. `kern.registrierung_code_pruefen` zählt
--     falsche Eingaben; nach fünf ist der Zugang ungültig. Ein neuer Zugang macht den vorigen derselben Identität
--     ungültig.
--   * **Wer nicht entfernt werden kann** (Abschnitt 4): Der letzte Administrator und der letzte Verantwortliche eines
--     Mandanten werden nicht einzeln gesperrt, und ihre letzte Zuweisung wird nicht beendet – sie gehen mit dem
--     Mandanten. Das erzwingt die Datenbank, nicht die Oberfläche.

-- Zahl der aktiven Nutzer, die zum Zeitpunkt eine Rolle dieser Art tragen (Dienstkonten zählen nicht).
CREATE FUNCTION kern._traeger(p_art text, p_zeitpunkt timestamptz) RETURNS bigint
LANGUAGE sql STABLE SET search_path = pg_catalog, kern, pg_temp AS $$
    SELECT count(DISTINCT n.id)
      FROM kern.nutzer n
      JOIN kern.zuweisung z ON z.subjekt = 'nutzer:' || n.id
      JOIN kern.rolle r ON r.id = z.rolle
     WHERE n.aktiv AND r.art = p_art
       AND z.gueltig_von <= p_zeitpunkt AND (z.gueltig_bis IS NULL OR z.gueltig_bis > p_zeitpunkt)
$$;

-- Stammdaten ändern, sperren und entsperren. Sperren beendet alle Sitzungen.
CREATE OR REPLACE FUNCTION kern.nutzer_aendern(p_subjekt text, p_nutzer uuid, p_familienname text, p_vornamen text,
                                               p_email text, p_aktiv boolean)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE alt kern.nutzer; neu kern.nutzer; a0 bigint; v0 bigint; jetzt timestamptz := clock_timestamp();
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT * INTO alt FROM kern.nutzer WHERE id = p_nutzer FOR UPDATE;
    IF NOT FOUND THEN PERFORM kern.verweigern('Nutzer unbekannt', 'KM002'); END IF;
    a0 := kern._traeger('administrator', jetzt); v0 := kern._traeger('verantwortlich', jetzt);
    UPDATE kern.nutzer SET vornamen = nullif(trim(p_vornamen), ''), familienname = trim(p_familienname),
                           email = nullif(lower(trim(p_email)), ''), aktiv = p_aktiv
     WHERE id = p_nutzer RETURNING * INTO neu;
    IF NOT p_aktiv THEN
        IF a0 > 0 AND kern._traeger('administrator', jetzt) = 0 THEN
            PERFORM kern.verweigern('Der letzte Administrator wird nicht gesperrt – er geht mit dem Mandanten', 'KM003');
        END IF;
        IF v0 > 0 AND kern._traeger('verantwortlich', jetzt) = 0 THEN
            PERFORM kern.verweigern('Der letzte Verantwortliche wird nicht gesperrt – er geht mit dem Mandanten', 'KM003');
        END IF;
        UPDATE kern.sitzung SET beendet_am = now() WHERE nutzer = p_nutzer AND beendet_am IS NULL;
    END IF;
    IF kern._nutzer_stand(alt) IS DISTINCT FROM kern._nutzer_stand(neu) THEN
        PERFORM kern._protokoll('nutzer.geaendert', p_subjekt, jsonb_build_object('nutzer', p_nutzer::text),
                                kern._nutzer_stand(alt), kern._nutzer_stand(neu));
    END IF;
END $$;

CREATE OR REPLACE FUNCTION kern.zuweisung_beenden(p_subjekt text, p_zuweisung uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE z kern.zuweisung; ende timestamptz; art_ text; vorher bigint;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT * INTO z FROM kern.zuweisung WHERE id = p_zuweisung FOR UPDATE;
    IF NOT FOUND THEN PERFORM kern.verweigern('Zuweisung unbekannt', 'KM002'); END IF;
    ende := greatest(clock_timestamp(), z.gueltig_von + interval '1 microsecond');
    IF z.gueltig_bis IS NOT NULL AND z.gueltig_bis <= ende THEN
        PERFORM kern.verweigern('Zuweisung ist bereits beendet', 'KM003');
    END IF;
    SELECT art INTO art_ FROM kern.rolle WHERE id = z.rolle;
    IF art_ IN ('administrator', 'verantwortlich') THEN
        vorher := kern._traeger(art_, z.gueltig_von);
    END IF;
    UPDATE kern.zuweisung SET gueltig_bis = ende WHERE id = z.id;
    IF art_ IN ('administrator', 'verantwortlich') AND vorher > 0 AND kern._traeger(art_, ende) = 0 THEN
        PERFORM kern.verweigern(format('Die letzte Zuweisung der Rolle %s wird nicht beendet – sie geht mit dem Mandanten',
            CASE art_ WHEN 'administrator' THEN 'Administrator' ELSE 'Verantwortlicher' END), 'KM003');
    END IF;
    PERFORM kern._protokoll('zuweisung.geaendert', p_subjekt, jsonb_build_object('zuweisung', z.id::text),
        jsonb_build_object('gueltig_bis', kern.zeitpunkt(z.gueltig_bis)),
        jsonb_build_object('gueltig_bis', kern.zeitpunkt(ende)));
END $$;

-- Zugang ausstellen: Ein neuer Zugang macht den vorigen derselben Identität ungültig (VERWALTUNG.md Abschnitt 6).
CREATE OR REPLACE FUNCTION kern.registrierung_ausstellen(p_subjekt text, p_nutzer uuid, p_token_hash text,
                                                         p_dauer interval DEFAULT interval '15 minutes', p_code_hash text DEFAULT NULL)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    IF p_dauer <= interval '0' OR p_dauer > interval '24 hours' THEN
        PERFORM kern.verweigern('Zugänge gelten höchstens 24 Stunden', 'KM002');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM kern.nutzer WHERE id = p_nutzer AND aktiv) THEN
        PERFORM kern.verweigern('Nutzer unbekannt oder gesperrt', 'KM003');
    END IF;
    UPDATE kern.registrierung SET ungueltig_am = now()
     WHERE nutzer = p_nutzer AND verwendet_am IS NULL AND ungueltig_am IS NULL;
    INSERT INTO kern.registrierung (token_hash, nutzer, code_hash, ablauf) VALUES (p_token_hash, p_nutzer, p_code_hash, now() + p_dauer);
    PERFORM kern._protokoll('nutzer.zugang_ausgestellt', p_subjekt, jsonb_build_object('nutzer', p_nutzer::text),
        NULL, jsonb_build_object('ablauf', kern.zeitpunkt(now() + p_dauer), 'mit_code', p_code_hash IS NOT NULL));
END $$;

-- Code zum Link prüfen. Technisch wie die Anmeldung: ohne Protokollkette, auch im Lesemodus. Ein falscher Code
-- wird gezählt; nach fünf ist der Zugang ungültig. Ergebnis: 'ok', 'falsch' oder 'ungueltig'.
-- Ein Zugang ohne Code (Notausgang der Kommandozeile) ist mit jedem Code 'ok'.
CREATE FUNCTION kern.registrierung_code_pruefen(p_token_hash text, p_code_hash text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE r kern.registrierung;
BEGIN
    SELECT * INTO r FROM kern.registrierung WHERE token_hash = p_token_hash FOR UPDATE;
    IF NOT FOUND OR r.verwendet_am IS NOT NULL OR r.ungueltig_am IS NOT NULL OR r.ablauf <= now() THEN
        RETURN 'ungueltig';
    END IF;
    IF r.code_hash IS NULL OR r.code_hash = p_code_hash THEN
        RETURN 'ok';
    END IF;
    UPDATE kern.registrierung
       SET fehlversuche = fehlversuche + 1,
           ungueltig_am = CASE WHEN fehlversuche + 1 >= 5 THEN now() END
     WHERE token_hash = p_token_hash;
    RETURN CASE WHEN r.fehlversuche + 1 >= 5 THEN 'ungueltig' ELSE 'falsch' END;
END $$;

REVOKE EXECUTE ON FUNCTION kern._traeger(text, timestamptz), kern.registrierung_code_pruefen(text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION kern.registrierung_code_pruefen(text, text) TO kumero_app;
