-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul lager, Migration 0010: Teile eines Stücks ohne eigenen Artikel (STUECKLISTE.md 12.2, F212)
--
-- Nicht jedes Teil ist ein eigener Artikel: Handgerät, Ladeschale und Kabel eines Scanners werden zusammen gekauft
-- und nie einzeln geführt – tragen aber eigene Nummern. Ein solches Teil steht mit Bezeichnung und, wenn es eine hat,
-- Seriennummer am Stück. Es hat keinen eigenen Bestand, keine Bewegung und keinen Wert; es gehört dem Stück.
-- Teile, die eigene Artikel sind, bleiben in lager.stueck_teil (eigener Bestand, „verbaut in“).
-- Nichts wird gelöscht: Lösen beendet die Zeile.

CREATE TABLE lager.stueck_freiteil (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    ganzes       uuid NOT NULL REFERENCES lager.charge,
    bezeichnung  text NOT NULL CHECK (length(trim(bezeichnung)) BETWEEN 1 AND 200),
    seriennummer text CHECK (seriennummer IS NULL OR length(trim(seriennummer)) BETWEEN 1 AND 50),
    menge        numeric(15,3) NOT NULL CHECK (menge > 0),
    seit         timestamptz NOT NULL DEFAULT now(),
    seit_von     text NOT NULL,
    bis          timestamptz,
    bis_von      text,
    bis_grund    text CHECK (bis_grund IS NULL OR bis_grund IN ('geloest', 'entnahme', 'ausbuchung')),
    CHECK ((bis IS NULL) = (bis_von IS NULL) AND (bis IS NULL) = (bis_grund IS NULL)),
    CHECK (seriennummer IS NULL OR menge = 1)
);
CREATE INDEX stueck_freiteil_ganzes_idx ON lager.stueck_freiteil (ganzes) WHERE bis IS NULL;

-- Trägt ein Teil ohne eigenen Artikel an einem Stück ein. Das Stück trägt eine Seriennummer.
CREATE FUNCTION lager.freiteil_eintragen(p_subjekt text, p_ganzes uuid, p_bezeichnung text, p_seriennummer text, p_menge numeric)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE g lager.charge; ga lager.artikel; v_bez text := trim(coalesce(p_bezeichnung, '')); v_sn text := nullif(trim(coalesce(p_seriennummer, '')), ''); neu uuid;
BEGIN
    SELECT * INTO g FROM lager.charge WHERE id = p_ganzes FOR UPDATE;
    IF NOT FOUND THEN PERFORM lager._fehler('Stück unbekannt', 'KM003'); END IF;
    SELECT * INTO ga FROM lager.artikel WHERE id = g.artikel;
    IF NOT ga.hat_serie OR g.ohne_serie THEN
        PERFORM lager._fehler('Teile gehören zu einem Stück mit Seriennummer', 'KM003');
    END IF;
    IF v_bez = '' OR length(v_bez) > 200 THEN
        PERFORM lager._fehler('Bezeichnung des Teils mit höchstens 200 Zeichen erwartet');
    END IF;
    IF length(v_sn) > 50 THEN
        PERFORM lager._fehler('Seriennummer des Teils: höchstens 50 Zeichen');
    END IF;
    IF p_menge IS NULL OR p_menge <= 0 OR p_menge <> round(p_menge, 3) THEN
        PERFORM lager._fehler('Menge größer als null mit höchstens drei Nachkommastellen erwartet');
    END IF;
    IF v_sn IS NOT NULL AND p_menge <> 1 THEN
        PERFORM lager._fehler('Ein Teil mit Seriennummer ist ein einzelnes Teil – Menge 1');
    END IF;
    IF v_sn IS NOT NULL AND EXISTS (SELECT 1 FROM lager.stueck_freiteil WHERE ganzes = p_ganzes AND bis IS NULL AND lower(bezeichnung) = lower(v_bez) AND seriennummer = v_sn) THEN
        PERFORM lager._fehler('Dieses Teil steht mit dieser Seriennummer schon an diesem Stück', 'KM003');
    END IF;
    IF (SELECT count(*) FROM lager.stueck_freiteil WHERE ganzes = p_ganzes AND bis IS NULL) >= 200 THEN
        PERFORM lager._fehler('Ein Stück trägt höchstens 200 Teile ohne eigenen Artikel', 'KM003');
    END IF;
    INSERT INTO lager.stueck_freiteil (ganzes, bezeichnung, seriennummer, menge, seit, seit_von) VALUES (p_ganzes, v_bez, v_sn, p_menge, clock_timestamp(), p_subjekt) RETURNING id INTO neu;
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.freiteil_eingetragen', jsonb_build_object('stueck', p_ganzes::text, 'teil', neu::text),
        NULL, jsonb_strip_nulls(jsonb_build_object('bezeichnung', v_bez, 'seriennummer', v_sn, 'menge', lager._menge(p_menge))));
    RETURN neu;
END $$;

CREATE FUNCTION lager._freiteil_beenden(p_subjekt text, p_id uuid, p_grund text) RETURNS void LANGUAGE plpgsql AS $$
DECLARE s lager.stueck_freiteil;
BEGIN
    UPDATE lager.stueck_freiteil SET bis = clock_timestamp(), bis_von = p_subjekt, bis_grund = p_grund WHERE id = p_id AND bis IS NULL RETURNING * INTO s;
    IF NOT FOUND THEN
        PERFORM lager._fehler('Dieses Teil steht nicht (mehr) an einem Stück', 'KM003');
    END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.freiteil_geloest', jsonb_build_object('stueck', s.ganzes::text, 'teil', s.id::text),
        jsonb_strip_nulls(jsonb_build_object('bezeichnung', s.bezeichnung, 'seriennummer', s.seriennummer, 'menge', lager._menge(s.menge))), jsonb_build_object('grund', p_grund));
END $$;

CREATE FUNCTION lager.freiteil_loesen(p_subjekt text, p_id uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
BEGIN
    PERFORM lager._freiteil_beenden(p_subjekt, p_id, 'geloest');
END $$;

-- Bewegt eine Charge samt dem, was in ihr steckt – wie in 0008, dazu: Verlässt ein Stück das Lager (Entnahme,
-- Ausbuchung), enden seine Teile ohne eigenen Artikel mit ihm. Sie haben keinen Bestand, der bleiben könnte.
CREATE OR REPLACE FUNCTION lager._stueck_bewegen(p_subjekt text, p_art text, p_charge uuid, p_ort uuid, p_nach uuid, p_menge numeric, p_text text, p_mit boolean)
RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE bid uuid; s lager.stueck_teil; f uuid;
BEGIN
    bid := CASE p_art WHEN 'entnahme' THEN lager.entnahme(p_subjekt, p_charge, p_ort, p_menge, p_text)
                      WHEN 'umlagerung' THEN lager.umlagerung(p_subjekt, p_charge, p_ort, p_nach, p_menge)
                      WHEN 'ausbuchung' THEN lager.ausbuchung(p_subjekt, p_charge, p_ort, p_menge, p_text) END;
    FOR s IN SELECT * FROM lager.stueck_teil WHERE ganzes = p_charge AND bis IS NULL ORDER BY seit, id LOOP
        IF p_mit THEN
            PERFORM lager._stueck_bewegen(p_subjekt, p_art, s.teil, p_ort, p_nach, s.menge, p_text, true);
            IF p_art <> 'umlagerung' THEN
                PERFORM lager._teil_beenden(p_subjekt, s.id, p_art);
            END IF;
        ELSE
            PERFORM lager._teil_beenden(p_subjekt, s.id, CASE WHEN p_art = 'umlagerung' THEN 'umlagerung' ELSE 'geloest' END);
        END IF;
    END LOOP;
    IF p_art <> 'umlagerung' AND NOT EXISTS (SELECT 1 FROM lager.bestand WHERE charge = p_charge AND menge > 0) THEN
        FOR f IN SELECT id FROM lager.stueck_freiteil WHERE ganzes = p_charge AND bis IS NULL ORDER BY seit, id LOOP
            PERFORM lager._freiteil_beenden(p_subjekt, f, p_art);
        END LOOP;
    END IF;
    RETURN bid;
END $$;

REVOKE ALL ON lager.stueck_freiteil FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON lager.stueck_freiteil TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON FUNCTION lager.freiteil_eintragen(text, uuid, text, text, numeric), lager.freiteil_loesen(text, uuid), lager._freiteil_beenden(text, uuid, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION lager.freiteil_eintragen(text, uuid, text, text, numeric), lager.freiteil_loesen(text, uuid) TO kumero_app;
