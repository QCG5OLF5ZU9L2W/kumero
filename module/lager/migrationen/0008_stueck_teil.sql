-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul lager, Migration 0008: Teile eines Stücks (STUECKLISTE.md Abschnitt 12, F201)
--
-- Ein Stück ist ein Artikel mit Seriennummer im Bestand (seine „Charge“). Seine Teile sind andere Chargen bzw.
-- Seriennummern, die in ihm stecken: die Ladeschale mit ihrer Seriennummer im Handscanner mit seiner.
-- Entscheidung des Verantwortlichen (ZS5, Antwort a): Jedes Teil hat seinen eigenen Bestand und ist als
-- „verbaut in …“ gekennzeichnet – es zählt in Inventur und Wert beim Teil.
-- Ein verbautes Teil liegt am Lagerort seines Stücks und wird nicht allein bewegt: erst lösen oder das Stück bewegen.
-- Nichts wird gelöscht: Lösen beendet die Zeile.

CREATE TABLE lager.stueck_teil (
    id        uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    ganzes    uuid NOT NULL REFERENCES lager.charge,
    teil      uuid NOT NULL REFERENCES lager.charge,
    menge     numeric(15,3) NOT NULL CHECK (menge > 0),
    seit      timestamptz NOT NULL DEFAULT now(),
    seit_von  text NOT NULL,
    bis       timestamptz,
    bis_von   text,
    bis_grund text CHECK (bis_grund IS NULL OR bis_grund IN ('geloest', 'entnahme', 'ausbuchung', 'umlagerung')),
    CHECK (ganzes <> teil),
    CHECK ((bis IS NULL) = (bis_von IS NULL) AND (bis IS NULL) = (bis_grund IS NULL))
);
CREATE UNIQUE INDEX stueck_teil_paar_idx ON lager.stueck_teil (ganzes, teil) WHERE bis IS NULL;
CREATE INDEX stueck_teil_teil_idx ON lager.stueck_teil (teil) WHERE bis IS NULL;

-- Baut ein Teil in ein Stück ein. Das Stück trägt eine Seriennummer; beide liegen am selben Lagerort.
CREATE FUNCTION lager.teil_einbauen(p_subjekt text, p_ganzes uuid, p_teil uuid, p_menge numeric)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE g lager.charge; t lager.charge; ga lager.artikel; ta lager.artikel; v_ort uuid; v_da numeric; v_verbaut numeric; neu uuid;
BEGIN
    SELECT * INTO g FROM lager.charge WHERE id = p_ganzes FOR UPDATE;
    IF NOT FOUND THEN PERFORM lager._fehler('Stück unbekannt', 'KM003'); END IF;
    SELECT * INTO t FROM lager.charge WHERE id = p_teil FOR UPDATE;
    IF NOT FOUND THEN PERFORM lager._fehler('Teil unbekannt', 'KM003'); END IF;
    IF p_ganzes = p_teil THEN PERFORM lager._fehler('Ein Stück ist nicht sein eigenes Teil', 'KM003'); END IF;
    SELECT * INTO ga FROM lager.artikel WHERE id = g.artikel;
    SELECT * INTO ta FROM lager.artikel WHERE id = t.artikel;
    IF ga.fuehrung <> 'serie' THEN
        PERFORM lager._fehler('Teile gehören zu einem Stück mit Seriennummer – dieser Artikel wird nicht mit Seriennummer geführt', 'KM003');
    END IF;
    IF p_menge IS NULL OR p_menge <= 0 OR p_menge <> round(p_menge, 3) THEN
        PERFORM lager._fehler('Menge größer als null mit höchstens drei Nachkommastellen erwartet');
    END IF;
    SELECT ort INTO v_ort FROM lager.bestand WHERE charge = p_ganzes AND menge > 0 LIMIT 1;
    IF v_ort IS NULL THEN PERFORM lager._fehler('Das Stück hat keinen Bestand', 'KM003'); END IF;
    SELECT coalesce(sum(menge), 0) INTO v_da FROM lager.bestand WHERE charge = p_teil AND ort = v_ort;
    SELECT coalesce(sum(menge), 0) INTO v_verbaut FROM lager.stueck_teil WHERE teil = p_teil AND bis IS NULL;
    IF EXISTS (SELECT 1 FROM lager.stueck_teil WHERE ganzes = p_ganzes AND teil = p_teil AND bis IS NULL) THEN
        PERFORM lager._fehler('Dieses Teil steckt schon in diesem Stück', 'KM003');
    END IF;
    IF v_da - v_verbaut < p_menge THEN
        PERFORM lager._fehler(CASE WHEN v_verbaut > 0 AND ta.fuehrung = 'serie' THEN 'Das Teil steckt schon in einem anderen Stück – erst dort lösen'
            ELSE format('Am Lagerort des Stücks sind vom Teil nur %s frei – erst umlagern oder zubuchen', lager._menge(greatest(v_da - v_verbaut, 0))) END, 'KM003');
    END IF;
    -- kein Kreis: Das Stück steckt nicht selbst – auch nicht über Umwege – in diesem Teil
    IF EXISTS (WITH RECURSIVE runter(c, tiefe) AS (
                   SELECT p_teil, 0
                   UNION ALL
                   SELECT s.teil, r.tiefe + 1 FROM runter r JOIN lager.stueck_teil s ON s.ganzes = r.c AND s.bis IS NULL WHERE r.tiefe < 20)
               SELECT 1 FROM runter WHERE c = p_ganzes) THEN
        PERFORM lager._fehler('Das ergäbe einen Kreis: Das Stück steckt selbst in diesem Teil', 'KM003');
    END IF;
    INSERT INTO lager.stueck_teil (ganzes, teil, menge, seit, seit_von) VALUES (p_ganzes, p_teil, p_menge, clock_timestamp(), p_subjekt) RETURNING id INTO neu;
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.teil_eingebaut', jsonb_build_object('stueck', p_ganzes::text, 'teil', p_teil::text),
        NULL, jsonb_build_object('menge', lager._menge(p_menge)));
    RETURN neu;
END $$;

CREATE FUNCTION lager._teil_beenden(p_subjekt text, p_id uuid, p_grund text) RETURNS void LANGUAGE plpgsql AS $$
DECLARE s lager.stueck_teil;
BEGIN
    UPDATE lager.stueck_teil SET bis = clock_timestamp(), bis_von = p_subjekt, bis_grund = p_grund WHERE id = p_id AND bis IS NULL RETURNING * INTO s;
    IF NOT FOUND THEN
        PERFORM lager._fehler('Dieses Teil ist nicht (mehr) eingebaut', 'KM003');
    END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.teil_geloest', jsonb_build_object('stueck', s.ganzes::text, 'teil', s.teil::text),
        jsonb_build_object('menge', lager._menge(s.menge)), jsonb_build_object('grund', p_grund));
END $$;

-- Löst ein Teil aus seinem Stück. Das Teil bleibt im Bestand, wo es liegt.
CREATE FUNCTION lager.teil_loesen(p_subjekt text, p_id uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
BEGIN
    PERFORM lager._teil_beenden(p_subjekt, p_id, 'geloest');
END $$;

-- Bewegt eine Charge samt dem, was in ihr steckt. p_art: entnahme | umlagerung | ausbuchung.
-- p_mit: true – die Teile gehen mit (bei Entnahme und Ausbuchung endet danach die Zugehörigkeit, das Stück hat das Lager
-- verlassen); false – die Teile bleiben liegen und sind gelöst.
CREATE FUNCTION lager._stueck_bewegen(p_subjekt text, p_art text, p_charge uuid, p_ort uuid, p_nach uuid, p_menge numeric, p_text text, p_mit boolean)
RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE bid uuid; s lager.stueck_teil;
BEGIN
    bid := CASE p_art WHEN 'entnahme' THEN lager.entnahme(p_subjekt, p_charge, p_ort, p_menge, p_text)
                      WHEN 'umlagerung' THEN lager.umlagerung(p_subjekt, p_charge, p_ort, p_nach, p_menge)
                      WHEN 'ausbuchung' THEN lager.ausbuchung(p_subjekt, p_charge, p_ort, p_menge, p_text) END;
    FOR s IN SELECT * FROM lager.stueck_teil WHERE ganzes = p_charge AND bis IS NULL ORDER BY seit, id LOOP
        IF p_mit THEN
            -- der Zweck einer Entnahme gilt auch für die Teile; der Grund einer Ausbuchung ebenso
            PERFORM lager._stueck_bewegen(p_subjekt, p_art, s.teil, p_ort, p_nach, s.menge, p_text, true);
            IF p_art <> 'umlagerung' THEN
                PERFORM lager._teil_beenden(p_subjekt, s.id, p_art);
            END IF;
        ELSE
            PERFORM lager._teil_beenden(p_subjekt, s.id, CASE WHEN p_art = 'umlagerung' THEN 'umlagerung' ELSE 'geloest' END);
        END IF;
    END LOOP;
    RETURN bid;
END $$;

-- Entnahme, Umlagerung, Ausbuchung über eine Stelle: Ein verbautes Teil geht nicht allein; hat die Charge Teile,
-- muss gesagt sein, ob sie mitgehen (p_mit_teilen true oder false) – nichts geschieht still.
CREATE FUNCTION lager.stueck_bewegen(p_subjekt text, p_art text, p_charge uuid, p_ort uuid, p_nach uuid, p_menge numeric, p_text text, p_mit_teilen boolean)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE v_in text;
BEGIN
    IF p_art NOT IN ('entnahme', 'umlagerung', 'ausbuchung') THEN
        PERFORM lager._fehler('Art der Bewegung unbekannt');
    END IF;
    SELECT format('%s, Seriennummer %s', a.bezeichnung, c.bezeichnung) INTO v_in
      FROM lager.stueck_teil s JOIN lager.charge c ON c.id = s.ganzes JOIN lager.artikel a ON a.id = c.artikel
     WHERE s.teil = p_charge AND s.bis IS NULL LIMIT 1;
    IF v_in IS NOT NULL AND (SELECT fuehrung FROM lager.artikel a JOIN lager.charge c ON c.artikel = a.id WHERE c.id = p_charge) = 'serie' THEN
        PERFORM lager._fehler(format('Dieses Teil ist verbaut in „%s“ – erst dort lösen oder das Stück als Ganzes bewegen', v_in), 'KM003');
    END IF;
    IF v_in IS NOT NULL AND p_menge > (SELECT coalesce(sum(menge), 0) FROM lager.bestand WHERE charge = p_charge AND ort = p_ort)
                                    - (SELECT coalesce(sum(menge), 0) FROM lager.stueck_teil WHERE teil = p_charge AND bis IS NULL) THEN
        PERFORM lager._fehler(format('Ein Teil dieser Charge ist verbaut (in „%s“) – frei ist weniger als die gewünschte Menge; erst dort lösen', v_in), 'KM003');
    END IF;
    IF p_mit_teilen IS NULL AND EXISTS (SELECT 1 FROM lager.stueck_teil WHERE ganzes = p_charge AND bis IS NULL) THEN
        PERFORM lager._fehler('Das Stück hat Teile: Es muss gesagt sein, ob sie mitgehen (mit_teilen)', 'KM003');
    END IF;
    RETURN lager._stueck_bewegen(p_subjekt, p_art, p_charge, p_ort, p_nach, p_menge, p_text, coalesce(p_mit_teilen, false));
END $$;

REVOKE ALL ON lager.stueck_teil FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON lager.stueck_teil TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON FUNCTION lager.teil_einbauen(text, uuid, uuid, numeric), lager.teil_loesen(text, uuid), lager._teil_beenden(text, uuid, text),
    lager._stueck_bewegen(text, text, uuid, uuid, uuid, numeric, text, boolean), lager.stueck_bewegen(text, text, uuid, uuid, uuid, numeric, text, boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION lager.teil_einbauen(text, uuid, uuid, numeric), lager.teil_loesen(text, uuid),
    lager.stueck_bewegen(text, text, uuid, uuid, uuid, numeric, text, boolean) TO kumero_app;
