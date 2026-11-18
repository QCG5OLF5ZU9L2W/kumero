-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul arbeitsvorrat, Migration 0005: Einträge zusammenführen, „kein Duplikat“, Einstellungen (ARBEITSVORRAT.md X3, F105)
--
-- Zwei offene Einträge, die denselben Vorgang meinen (z. B. Abbuchung und Rechnung), werden einer: Die Beiträge
-- des einen ziehen zum anderen um, die im Vergleich gewählten Werte gehen als eigener Beitrag mit Vorrang ein.
-- Nichts wird gelöscht (W12): Der aufgegangene Eintrag bleibt mit dem Zustand „zusammengefuehrt“ stehen.

ALTER TABLE arbeitsvorrat.eintrag DROP CONSTRAINT eintrag_zustand_check;
ALTER TABLE arbeitsvorrat.eintrag ADD CONSTRAINT eintrag_zustand_check
    CHECK (zustand IN ('offen', 'in_arbeit', 'gebucht', 'verworfen', 'zurueckgezogen', 'zusammengefuehrt'));
ALTER TABLE arbeitsvorrat.eintrag ADD COLUMN zusammengefuehrt_in uuid REFERENCES arbeitsvorrat.eintrag,
    ADD CONSTRAINT eintrag_zusammen_check CHECK ((zustand = 'zusammengefuehrt') = (zusammengefuehrt_in IS NOT NULL));
CREATE INDEX eintrag_zusammen_idx ON arbeitsvorrat.eintrag (zusammengefuehrt_in) WHERE zusammengefuehrt_in IS NOT NULL;

-- rang: Beiträge mit höherem Rang gehen vor (die Wahl aus dem Vergleich); herkunft_eintrag: woher ein Beitrag beim
-- Zusammenführen kam, damit sich wieder trennen lässt
ALTER TABLE arbeitsvorrat.beitrag ADD COLUMN rang integer NOT NULL DEFAULT 0, ADD COLUMN herkunft_eintrag uuid REFERENCES arbeitsvorrat.eintrag;

-- Paare, die nur gleich aussehen: werden nicht wieder gemeinsam markiert
CREATE TABLE arbeitsvorrat.kein_duplikat (
    a   uuid NOT NULL REFERENCES arbeitsvorrat.eintrag,
    b   uuid NOT NULL REFERENCES arbeitsvorrat.eintrag,
    am  timestamptz NOT NULL DEFAULT clock_timestamp(),
    von text NOT NULL,
    PRIMARY KEY (a, b),
    CHECK (a < b)
);

CREATE TABLE arbeitsvorrat.einstellung (
    schluessel   text PRIMARY KEY CHECK (schluessel IN ('auto_zusammenfuehren')),
    wert         jsonb NOT NULL,
    geaendert_am timestamptz NOT NULL DEFAULT clock_timestamp(),
    geaendert_von text NOT NULL
);

-- Felder des Eintrags aus den Beiträgen: höherer Rang zuerst, dann der zuerst eingegangene Wert (W3)
CREATE OR REPLACE FUNCTION arbeitsvorrat._felder_setzen(p_eintrag uuid) RETURNS void
LANGUAGE plpgsql AS $$
DECLARE
    b      arbeitsvorrat.beitrag;
    f      text;
    v_wert   jsonb := '{}';
    v_belege jsonb := '[]';
    v_hinw   jsonb := '[]';
    x      jsonb;
BEGIN
    FOR b IN SELECT * FROM arbeitsvorrat.beitrag WHERE eintrag = p_eintrag AND zurueckgezogen_am IS NULL
              ORDER BY rang DESC, geliefert_am, id LOOP
        FOREACH f IN ARRAY ARRAY['betreff', 'art', 'gegenpartei', 'gegenpartei_kennung', 'datum', 'betrag', 'richtung',
                                 'belegnummer', 'zahlweise', 'zahltag', 'netto', 'steuer'] LOOP
            CONTINUE WHEN coalesce(b.vorschlag->>f, '') = '';
            IF NOT v_wert ? f THEN
                v_wert := v_wert || jsonb_build_object(f, b.vorschlag->>f);
            ELSIF v_wert->>f <> b.vorschlag->>f AND f <> 'betreff' THEN
                v_hinw := v_hinw || jsonb_build_object('quelle', b.quelle, 'feld', f, 'abweichend', b.vorschlag->>f);
            END IF;
        END LOOP;
        FOR x IN SELECT * FROM jsonb_array_elements(coalesce(b.vorschlag->'belege', '[]')) LOOP
            IF NOT EXISTS (SELECT 1 FROM jsonb_array_elements(v_belege) y WHERE y->>'link' = x->>'link') THEN
                v_belege := v_belege || jsonb_build_array(x);
            END IF;
        END LOOP;
        FOR x IN SELECT * FROM jsonb_array_elements(coalesce(b.vorschlag->'hinweise', '[]')) LOOP
            v_hinw := v_hinw || (jsonb_build_object('quelle', b.quelle) || x);
        END LOOP;
    END LOOP;
    UPDATE arbeitsvorrat.eintrag SET
           betreff = coalesce(v_wert->>'betreff', betreff), art = v_wert->>'art',
           gegenpartei = v_wert->>'gegenpartei', gegenpartei_kennung = v_wert->>'gegenpartei_kennung',
           datum = (v_wert->>'datum')::date, betrag = (v_wert->>'betrag')::numeric(15,2), richtung = v_wert->>'richtung',
           belegnummer = v_wert->>'belegnummer', zahlweise = v_wert->>'zahlweise', zahltag = (v_wert->>'zahltag')::date,
           netto = (v_wert->>'netto')::numeric(15,2), steuer = (v_wert->>'steuer')::numeric(15,2),
           belege = v_belege, hinweise = v_hinw
     WHERE id = p_eintrag;
END $$;

-- Führt p_quelle in p_ziel: Beide müssen offen sein. p_felder: die im Vergleich gewählten Werte.
CREATE FUNCTION arbeitsvorrat.zusammenfuehren(p_subjekt text, p_ziel uuid, p_quelle uuid, p_felder jsonb)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, arbeitsvorrat, pg_temp AS $$
DECLARE
    z arbeitsvorrat.eintrag;
    q arbeitsvorrat.eintrag;
BEGIN
    IF p_ziel = p_quelle THEN
        RAISE EXCEPTION USING ERRCODE = 'KM002', MESSAGE = 'Ein Eintrag lässt sich nicht mit sich selbst zusammenführen';
    END IF;
    -- feste Reihenfolge der Sperren, damit zwei gleichzeitige Aufrufe sich nicht verklemmen
    PERFORM 1 FROM arbeitsvorrat.eintrag WHERE id IN (p_ziel, p_quelle) ORDER BY id FOR UPDATE;
    SELECT * INTO z FROM arbeitsvorrat.eintrag WHERE id = p_ziel;
    SELECT * INTO q FROM arbeitsvorrat.eintrag WHERE id = p_quelle;
    IF z.id IS NULL OR q.id IS NULL THEN
        RAISE EXCEPTION USING ERRCODE = 'KM003', MESSAGE = 'Eintrag unbekannt';
    END IF;
    IF z.zustand <> 'offen' OR q.zustand <> 'offen' THEN
        RAISE EXCEPTION USING ERRCODE = 'KM003', MESSAGE = 'Zusammenführen geht nur mit offenen Einträgen';
    END IF;
    UPDATE arbeitsvorrat.beitrag SET herkunft_eintrag = coalesce(herkunft_eintrag, eintrag), eintrag = p_ziel WHERE eintrag = p_quelle;
    IF coalesce(p_felder, '{}') <> '{}' THEN
        INSERT INTO arbeitsvorrat.beitrag (eintrag, quelle, schluessel, vorschlag, rang)
        VALUES (p_ziel, 'arbeitsvorrat', 'zusammen:' || p_quelle::text, p_felder, 1)
        ON CONFLICT (quelle, schluessel) DO UPDATE SET eintrag = EXCLUDED.eintrag, vorschlag = EXCLUDED.vorschlag, rang = 1,
            zurueckgezogen_am = NULL, geaendert_am = clock_timestamp();
    END IF;
    -- die Vorauswahl bleibt erhalten: Was am Ziel fehlt, kommt vom aufgegangenen Eintrag (z. B. das Geldkonto des Umsatzes)
    UPDATE arbeitsvorrat.eintrag SET vor_konto = coalesce(vor_konto, q.vor_konto), vor_gegenkonto = coalesce(vor_gegenkonto, q.vor_gegenkonto),
           vor_steuerkonto = coalesce(vor_steuerkonto, q.vor_steuerkonto), vor_buchungsart = coalesce(vor_buchungsart, q.vor_buchungsart)
     WHERE id = p_ziel;
    UPDATE arbeitsvorrat.eintrag SET zustand = 'zusammengefuehrt', zusammengefuehrt_in = p_ziel, erledigt_am = clock_timestamp(), erledigt_von = p_subjekt
     WHERE id = p_quelle;
    -- der aufgegangene Eintrag hat keine Beiträge mehr: Seine Felder und Belege werden leer, damit ihm nichts mehr zugeordnet wird
    PERFORM arbeitsvorrat._felder_setzen(p_quelle);
    PERFORM arbeitsvorrat._felder_setzen(p_ziel);
    PERFORM kern.modul_protokoll(p_subjekt, 'arbeitsvorrat', 'arbeitsvorrat.eintraege_zusammengefuehrt',
        jsonb_build_object('eintrag', p_ziel::text, 'aufgegangen', p_quelle::text), NULL, jsonb_build_object('gewaehlt', coalesce(p_felder, '{}')));
END $$;

-- Trennt wieder, was in p_ziel aufgegangen ist – solange der Eintrag offen ist
CREATE FUNCTION arbeitsvorrat.trennen(p_subjekt text, p_ziel uuid)
RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, arbeitsvorrat, pg_temp AS $$
DECLARE
    z arbeitsvorrat.eintrag;
    q record;
    n integer := 0;
BEGIN
    SELECT * INTO z FROM arbeitsvorrat.eintrag WHERE id = p_ziel FOR UPDATE;
    IF NOT FOUND OR z.zustand <> 'offen' THEN
        RAISE EXCEPTION USING ERRCODE = 'KM003', MESSAGE = 'Trennen geht nur bei einem offenen Eintrag';
    END IF;
    FOR q IN SELECT id FROM arbeitsvorrat.eintrag WHERE zusammengefuehrt_in = p_ziel ORDER BY id FOR UPDATE LOOP
        UPDATE arbeitsvorrat.beitrag SET eintrag = q.id, herkunft_eintrag = NULL WHERE eintrag = p_ziel AND herkunft_eintrag = q.id;
        UPDATE arbeitsvorrat.beitrag SET zurueckgezogen_am = clock_timestamp() WHERE quelle = 'arbeitsvorrat' AND schluessel = 'zusammen:' || q.id::text;
        UPDATE arbeitsvorrat.eintrag SET zustand = 'offen', zusammengefuehrt_in = NULL, erledigt_am = NULL, erledigt_von = NULL WHERE id = q.id;
        PERFORM arbeitsvorrat._felder_setzen(q.id);
        n := n + 1;
    END LOOP;
    IF n = 0 THEN
        RAISE EXCEPTION USING ERRCODE = 'KM003', MESSAGE = 'In diesem Eintrag ist nichts aufgegangen';
    END IF;
    PERFORM arbeitsvorrat._felder_setzen(p_ziel);
    PERFORM kern.modul_protokoll(p_subjekt, 'arbeitsvorrat', 'arbeitsvorrat.eintraege_getrennt', jsonb_build_object('eintrag', p_ziel::text), NULL, jsonb_build_object('getrennt', n));
    RETURN n;
END $$;

-- Alle Paare aus p_ids gelten als „kein Duplikat“
CREATE FUNCTION arbeitsvorrat.kein_duplikat_setzen(p_subjekt text, p_ids uuid[])
RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, arbeitsvorrat, pg_temp AS $$
DECLARE n integer;
BEGIN
    INSERT INTO arbeitsvorrat.kein_duplikat (a, b, von)
    SELECT x, y, p_subjekt FROM unnest(p_ids) x CROSS JOIN unnest(p_ids) y WHERE x < y
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT;
    PERFORM kern.modul_protokoll(p_subjekt, 'arbeitsvorrat', 'arbeitsvorrat.kein_duplikat',
        jsonb_build_object('eintraege', to_jsonb(ARRAY(SELECT x::text FROM unnest(p_ids) x ORDER BY 1))), NULL, jsonb_build_object('paare', n));
    RETURN n;
END $$;

CREATE FUNCTION arbeitsvorrat.einstellung_setzen(p_subjekt text, p_schluessel text, p_wert jsonb)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, arbeitsvorrat, pg_temp AS $$
DECLARE alt jsonb;
BEGIN
    SELECT wert INTO alt FROM arbeitsvorrat.einstellung WHERE schluessel = p_schluessel FOR UPDATE;
    INSERT INTO arbeitsvorrat.einstellung (schluessel, wert, geaendert_von) VALUES (p_schluessel, p_wert, p_subjekt)
    ON CONFLICT (schluessel) DO UPDATE SET wert = EXCLUDED.wert, geaendert_am = clock_timestamp(), geaendert_von = EXCLUDED.geaendert_von;
    PERFORM kern.modul_protokoll(p_subjekt, 'arbeitsvorrat', 'arbeitsvorrat.einstellung_geaendert', jsonb_build_object('schluessel', p_schluessel),
        CASE WHEN alt IS NULL THEN NULL ELSE jsonb_build_object('wert', alt) END, jsonb_build_object('wert', p_wert));
END $$;

GRANT SELECT ON arbeitsvorrat.kein_duplikat, arbeitsvorrat.einstellung TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON FUNCTION arbeitsvorrat.zusammenfuehren(text, uuid, uuid, jsonb), arbeitsvorrat.trennen(text, uuid),
    arbeitsvorrat.kein_duplikat_setzen(text, uuid[]), arbeitsvorrat.einstellung_setzen(text, text, jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION arbeitsvorrat.zusammenfuehren(text, uuid, uuid, jsonb), arbeitsvorrat.trennen(text, uuid),
    arbeitsvorrat.kein_duplikat_setzen(text, uuid[]), arbeitsvorrat.einstellung_setzen(text, text, jsonb) TO kumero_app;
