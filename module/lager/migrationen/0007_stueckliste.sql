-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul lager, Migration 0007: Stückliste am Artikel (STUECKLISTE.md Abschnitt 11, F197)
--
-- Ein Artikel kann aus Teilen bestehen: Der Handscanner aus Scanner, Ladeschale und Kabel, das Set aus Kleinteilen.
-- Die Stückliste nennt je Zeile ein Teil mit Menge – einen anderen Artikel, eine Artikelgruppe („einer von diesen“)
-- oder eine freie Zeile für ein Teil, das nicht als Artikel geführt wird – und ob es erforderlich oder optional ist.
-- Hat ein Teil selbst eine Stückliste, entsteht der Baum (Gerät, Module, Module in Modulen, Teile; ST3).
--
-- Diese Stufe beschreibt nur: Die Stückliste ändert keinen Bestand und löst keine Bewegung aus. Der Bestand eines
-- Artikels bleibt die Summe seiner Bewegungen (WW3); ein Teil, das auch einzeln im Lager liegt, hat seinen eigenen.
-- Nichts wird gelöscht (WW23): Eine neue Fassung der Liste beendet die alten Zeilen, sie bleiben stehen.

CREATE TABLE lager.stueckliste_zeile (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    artikel       uuid NOT NULL REFERENCES lager.artikel,           -- wessen Stückliste
    stelle        integer NOT NULL CHECK (stelle >= 1),              -- Reihenfolge in der Liste
    teil_artikel  uuid REFERENCES lager.artikel,
    teil_meta     uuid REFERENCES lager.meta_artikel,
    bezeichnung   text CHECK (bezeichnung IS NULL OR length(trim(bezeichnung)) BETWEEN 1 AND 200),
    menge         numeric(15,3) NOT NULL CHECK (menge > 0),
    erforderlich  boolean NOT NULL DEFAULT true,
    angelegt_am   timestamptz NOT NULL DEFAULT now(),
    angelegt_von  text NOT NULL,
    beendet_am    timestamptz,
    beendet_von   text,
    CHECK (num_nonnulls(teil_artikel, teil_meta, bezeichnung) = 1),  -- genau eines: Artikel, Artikelgruppe oder freie Zeile
    CHECK (teil_artikel IS DISTINCT FROM artikel),
    CHECK ((beendet_am IS NULL) = (beendet_von IS NULL))
);
CREATE INDEX stueckliste_artikel_idx ON lager.stueckliste_zeile (artikel) WHERE beendet_am IS NULL;
CREATE INDEX stueckliste_teil_idx ON lager.stueckliste_zeile (teil_artikel) WHERE beendet_am IS NULL AND teil_artikel IS NOT NULL;

-- Die geltende Stückliste eines Artikels als JSON, in der Reihenfolge der Liste; Mengen als Text (F29).
CREATE FUNCTION lager._stueckliste_stand(p_artikel uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
    SELECT coalesce(jsonb_agg(jsonb_strip_nulls(jsonb_build_object('artikel', teil_artikel::text, 'meta', teil_meta::text, 'bezeichnung', bezeichnung,
               'menge', lager._menge(menge), 'erforderlich', erforderlich)) ORDER BY stelle), '[]'::jsonb)
      FROM lager.stueckliste_zeile WHERE artikel = p_artikel AND beendet_am IS NULL
$$;

-- Setzt die Stückliste eines Artikels als Ganzes: p_zeilen = [{artikel | meta | bezeichnung, menge, erforderlich}].
-- Eine leere Liste hebt sie auf. Unverändert schreibt nichts. Ein Artikel steht nie in seiner eigenen Stückliste –
-- auch nicht über Umwege; die Tiefe des Baums ist auf 12 Ebenen begrenzt, die Liste auf 200 Zeilen (Schutz vor
-- Fehlbedienung, keine fachliche Regel – ST14).
CREATE FUNCTION lager.stueckliste_setzen(p_subjekt text, p_artikel uuid, p_zeilen jsonb)
RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE alt jsonb; neu jsonb; z jsonb; n integer := 0; v_teil uuid; v_meta uuid; v_text text; v_menge numeric; v_tiefe integer; v_jetzt timestamptz := clock_timestamp();
BEGIN
    PERFORM 1 FROM lager.artikel WHERE id = p_artikel FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM lager._fehler('Artikel unbekannt', 'KM003');
    END IF;
    IF p_zeilen IS NULL OR jsonb_typeof(p_zeilen) <> 'array' OR jsonb_array_length(p_zeilen) > 200 THEN
        PERFORM lager._fehler('Stückliste: höchstens 200 Zeilen erwartet');
    END IF;
    alt := lager._stueckliste_stand(p_artikel);
    UPDATE lager.stueckliste_zeile SET beendet_am = v_jetzt, beendet_von = p_subjekt WHERE artikel = p_artikel AND beendet_am IS NULL;
    FOR z IN SELECT * FROM jsonb_array_elements(p_zeilen) LOOP
        n := n + 1;
        v_teil := (z->>'artikel')::uuid;
        v_meta := (z->>'meta')::uuid;
        v_text := nullif(trim(z->>'bezeichnung'), '');
        v_menge := (z->>'menge')::numeric;
        IF num_nonnulls(v_teil, v_meta, v_text) <> 1 THEN
            PERFORM lager._fehler(format('Zeile %s: genau eines erwartet – ein Artikel, eine Artikelgruppe oder eine Bezeichnung', n));
        END IF;
        IF v_menge IS NULL OR v_menge <= 0 OR v_menge <> round(v_menge, 3) THEN
            PERFORM lager._fehler(format('Zeile %s: Menge größer als null mit höchstens drei Nachkommastellen erwartet', n));
        END IF;
        IF v_teil = p_artikel THEN
            PERFORM lager._fehler(format('Zeile %s: Ein Artikel steht nicht in seiner eigenen Stückliste', n), 'KM003');
        END IF;
        IF v_teil IS NOT NULL AND NOT EXISTS (SELECT 1 FROM lager.artikel WHERE id = v_teil) THEN
            PERFORM lager._fehler(format('Zeile %s: Artikel unbekannt', n), 'KM003');
        END IF;
        IF v_meta IS NOT NULL AND NOT EXISTS (SELECT 1 FROM lager.meta_artikel WHERE id = v_meta) THEN
            PERFORM lager._fehler(format('Zeile %s: Artikelgruppe unbekannt', n), 'KM003');
        END IF;
        INSERT INTO lager.stueckliste_zeile (artikel, stelle, teil_artikel, teil_meta, bezeichnung, menge, erforderlich, angelegt_von)
        VALUES (p_artikel, n, v_teil, v_meta, v_text, v_menge, coalesce((z->>'erforderlich')::boolean, true), p_subjekt);
    END LOOP;
    -- Kein Kreis und keine endlose Tiefe: von diesem Artikel abwärts über Artikel und die Artikel der genannten Gruppen
    WITH RECURSIVE baum(artikel, tiefe, kreis) AS (
        SELECT p_artikel, 0, false
        UNION ALL
        SELECT t.id, b.tiefe + 1, t.id = p_artikel
          FROM baum b
          JOIN lager.stueckliste_zeile s ON s.artikel = b.artikel AND s.beendet_am IS NULL
          JOIN lager.artikel t ON t.id = s.teil_artikel OR (s.teil_meta IS NOT NULL AND t.meta_artikel = s.teil_meta)
         WHERE NOT b.kreis AND b.tiefe < 13
    )
    SELECT max(tiefe) FILTER (WHERE kreis), max(tiefe) INTO v_tiefe, n FROM baum;
    IF v_tiefe IS NOT NULL THEN
        PERFORM lager._fehler('Die Stückliste führt im Kreis: Ein Teil enthält – über seine eigene Stückliste oder eine Artikelgruppe – wieder diesen Artikel', 'KM003');
    END IF;
    IF n > 12 THEN
        PERFORM lager._fehler('Die Stückliste ist tiefer als 12 Ebenen', 'KM003');
    END IF;
    neu := lager._stueckliste_stand(p_artikel);
    IF alt = neu THEN
        -- unverändert: die eben beendeten Zeilen gelten weiter, die neuen entfallen – nichts wird protokolliert
        DELETE FROM lager.stueckliste_zeile WHERE artikel = p_artikel AND beendet_am IS NULL;
        UPDATE lager.stueckliste_zeile SET beendet_am = NULL, beendet_von = NULL WHERE artikel = p_artikel AND beendet_am = v_jetzt;
        RETURN jsonb_array_length(neu);
    END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.stueckliste_gesetzt', jsonb_build_object('artikel', p_artikel::text),
        jsonb_build_object('zeilen', alt), jsonb_build_object('zeilen', neu));
    RETURN jsonb_array_length(neu);
END $$;

REVOKE ALL ON lager.stueckliste_zeile FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON lager.stueckliste_zeile TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON FUNCTION lager._stueckliste_stand(uuid), lager.stueckliste_setzen(text, uuid, jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION lager.stueckliste_setzen(text, uuid, jsonb) TO kumero_app;
