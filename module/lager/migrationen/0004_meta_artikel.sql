-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul lager, Migration 0004: Meta-Artikel – in der Oberfläche „Artikelgruppe“ (LAGER.md Abschnitt 17, F168, F169)
--
-- Ein Meta-Artikel nennt eine Funktion („Einmalhandschuh Nitril, Größe M“), kein Produkt. Die konkreten Artikel, die
-- sie erfüllen, sind seine Mitglieder und ersetzen einander. Der Meta-Artikel hat keine Charge, keine Bewegung und
-- keinen eigenen Bestand: Bewegt wird immer der konkrete Artikel. Er trägt Mindest- und Sollbestand – gerechnet wird
-- mit der Summe der Mitglieder –, und der Vorschlag für die Entnahme gilt über alle Mitglieder (frühester Verfall).

CREATE TABLE lager.meta_artikel (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    bezeichnung   text NOT NULL CHECK (length(trim(bezeichnung)) BETWEEN 1 AND 200),
    einheit       text NOT NULL CHECK (length(trim(einheit)) BETWEEN 1 AND 20),      -- die Lagereinheit aller Mitglieder (MA5)
    mindest       numeric(15,3) CHECK (mindest IS NULL OR mindest >= 0),
    soll          numeric(15,3) CHECK (soll IS NULL OR soll >= 0),
    stillgelegt   boolean NOT NULL DEFAULT false,
    angelegt_am   timestamptz NOT NULL DEFAULT now(),
    geaendert_am  timestamptz NOT NULL DEFAULT now(),
    geaendert_von text NOT NULL,
    CHECK (soll IS NULL OR mindest IS NULL OR soll >= mindest)
);
CREATE UNIQUE INDEX meta_artikel_bezeichnung ON lager.meta_artikel (lower(trim(bezeichnung)));

-- Ein Artikel gehört zu höchstens einem Meta-Artikel (MA4). Als Mitglied führt er keinen eigenen Mindest- und
-- Sollbestand (MA7) – sonst meldete das Lager ihn einzeln, obwohl vom anderen Produkt genug da ist.
ALTER TABLE lager.artikel
    ADD COLUMN meta_artikel uuid REFERENCES lager.meta_artikel,
    ADD CONSTRAINT artikel_meta_ohne_mindest CHECK (meta_artikel IS NULL OR (mindest IS NULL AND soll IS NULL));
CREATE INDEX artikel_meta_idx ON lager.artikel (meta_artikel) WHERE meta_artikel IS NOT NULL;

-- Was ein Mitglied sein darf (MA2, MA5): kein Arzneimittel, kein Betäubungsmittel, keine Seriennummern, dieselbe
-- Lagereinheit wie der Meta-Artikel. Geprüft an der Tabelle, damit es für jede Art zu schreiben gilt – auch wenn
-- später jemand Art, Führung oder Einheit eines Mitglieds ändert.
CREATE FUNCTION lager.tg_artikel_meta() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE m lager.meta_artikel;
BEGIN
    IF NEW.meta_artikel IS NULL THEN
        RETURN NEW;
    END IF;
    SELECT * INTO m FROM lager.meta_artikel WHERE id = NEW.meta_artikel;
    IF m.stillgelegt AND (TG_OP = 'INSERT' OR OLD.meta_artikel IS DISTINCT FROM NEW.meta_artikel) THEN
        PERFORM lager._fehler('Die Artikelgruppe ist stillgelegt', 'KM003');
    END IF;
    IF NEW.art = 'arzneimittel' THEN
        PERFORM lager._fehler('Arzneimittel gehören zu keiner Artikelgruppe: Ob eines ein anderes ersetzt, ist eine Entscheidung im Einzelfall', 'KM003');
    END IF;
    IF NEW.btm THEN
        PERFORM lager._fehler('Betäubungsmittel gehören zu keiner Artikelgruppe', 'KM003');
    END IF;
    IF NEW.fuehrung = 'serie' THEN
        PERFORM lager._fehler('Artikel mit Seriennummer gehören zu keiner Artikelgruppe: Jedes Stück ist ein einzelnes', 'KM003');
    END IF;
    IF lower(trim(NEW.einheit)) <> lower(trim(m.einheit)) THEN
        PERFORM lager._fehler(format('Die Lagereinheit des Artikels (%s) ist nicht die der Artikelgruppe (%s)', NEW.einheit, m.einheit), 'KM003');
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER artikel_meta BEFORE INSERT OR UPDATE ON lager.artikel FOR EACH ROW EXECUTE FUNCTION lager.tg_artikel_meta();

CREATE FUNCTION lager._meta_stand(m lager.meta_artikel) RETURNS jsonb LANGUAGE sql STABLE AS $$
    SELECT to_jsonb(m) - 'id' - 'angelegt_am' - 'geaendert_am' - 'geaendert_von' || jsonb_build_object('mindest', m.mindest::text, 'soll', m.soll::text)
$$;

-- Legt einen Meta-Artikel an (p_id NULL) oder ändert ihn.
CREATE FUNCTION lager.meta_setzen(p_subjekt text, p_id uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE alt lager.meta_artikel; neu lager.meta_artikel;
BEGIN
    IF (p->>'soll')::numeric < (p->>'mindest')::numeric THEN
        PERFORM lager._fehler('Der Sollbestand liegt unter dem Mindestbestand');
    END IF;
    IF EXISTS (SELECT 1 FROM lager.meta_artikel WHERE lower(trim(bezeichnung)) = lower(trim(p->>'bezeichnung')) AND id IS DISTINCT FROM p_id) THEN
        PERFORM lager._fehler('Eine Artikelgruppe mit dieser Bezeichnung gibt es schon', 'KM003');
    END IF;
    IF p_id IS NULL THEN
        INSERT INTO lager.meta_artikel (bezeichnung, einheit, mindest, soll, geaendert_von)
        VALUES (trim(p->>'bezeichnung'), trim(p->>'einheit'), (p->>'mindest')::numeric, (p->>'soll')::numeric, p_subjekt)
        RETURNING * INTO neu;
        PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.meta_angelegt', jsonb_build_object('meta_artikel', neu.id::text), NULL, lager._meta_stand(neu));
        RETURN neu.id;
    END IF;
    SELECT * INTO alt FROM lager.meta_artikel WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM lager._fehler('Artikelgruppe unbekannt', 'KM003');
    END IF;
    IF lower(trim(alt.einheit)) <> lower(trim(p->>'einheit')) AND EXISTS (SELECT 1 FROM lager.artikel WHERE meta_artikel = p_id) THEN
        PERFORM lager._fehler('Die Lagereinheit steht fest, solange die Artikelgruppe Artikel hat', 'KM003');
    END IF;
    IF coalesce((p->>'stillgelegt')::boolean, false) AND EXISTS (SELECT 1 FROM lager.artikel WHERE meta_artikel = p_id) THEN
        PERFORM lager._fehler('Die Artikelgruppe hat noch Artikel – erst die Zuordnung lösen', 'KM003');   -- MA13
    END IF;
    UPDATE lager.meta_artikel SET bezeichnung = trim(p->>'bezeichnung'), einheit = trim(p->>'einheit'), mindest = (p->>'mindest')::numeric, soll = (p->>'soll')::numeric,
           stillgelegt = coalesce((p->>'stillgelegt')::boolean, false), geaendert_am = clock_timestamp(), geaendert_von = p_subjekt
     WHERE id = p_id RETURNING * INTO neu;
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.meta_geaendert', jsonb_build_object('meta_artikel', p_id::text), lager._meta_stand(alt), lager._meta_stand(neu));
    RETURN p_id;
END $$;

-- Ordnet einen Artikel einem Meta-Artikel zu (p_meta) oder löst die Zuordnung (p_meta NULL). Beim Zuordnen entfällt
-- der eigene Mindest- und Sollbestand des Artikels; der alte Stand steht im Protokoll.
CREATE FUNCTION lager.meta_zuordnen(p_subjekt text, p_artikel uuid, p_meta uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE alt lager.artikel; neu lager.artikel;
BEGIN
    SELECT * INTO alt FROM lager.artikel WHERE id = p_artikel FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM lager._fehler('Artikel unbekannt', 'KM003');
    END IF;
    IF p_meta IS NOT NULL AND NOT EXISTS (SELECT 1 FROM lager.meta_artikel WHERE id = p_meta) THEN
        PERFORM lager._fehler('Artikelgruppe unbekannt', 'KM003');
    END IF;
    IF alt.meta_artikel IS NOT DISTINCT FROM p_meta THEN
        RETURN;
    END IF;
    UPDATE lager.artikel SET meta_artikel = p_meta, mindest = CASE WHEN p_meta IS NULL THEN mindest ELSE NULL END, soll = CASE WHEN p_meta IS NULL THEN soll ELSE NULL END,
           geaendert_am = clock_timestamp(), geaendert_von = p_subjekt
     WHERE id = p_artikel RETURNING * INTO neu;
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', CASE WHEN p_meta IS NULL THEN 'lager.meta_geloest' ELSE 'lager.meta_zugeordnet' END,
        jsonb_strip_nulls(jsonb_build_object('artikel', p_artikel::text, 'meta_artikel', coalesce(p_meta, alt.meta_artikel)::text)),
        lager._artikel_stand(alt), lager._artikel_stand(neu));
END $$;

-- Vorschlag über alle Mitglieder (MA8): die freie Charge mit dem frühesten Verfall, gleich von welchem Produkt.
CREATE FUNCTION lager.vorschlag_meta(p_meta uuid) RETURNS uuid LANGUAGE sql STABLE AS $$
    SELECT c.id FROM lager.charge c JOIN lager.artikel a ON a.id = c.artikel
     WHERE a.meta_artikel = p_meta AND NOT a.stillgelegt AND NOT c.gesperrt AND (c.verfall IS NULL OR c.verfall >= current_date)
       AND EXISTS (SELECT 1 FROM lager.bestand s WHERE s.charge = c.id AND s.menge > 0)
     ORDER BY c.verfall NULLS LAST, c.angelegt_am, c.id LIMIT 1
$$;

-- Entnahme wie bisher; bei einem Mitglied zählt als Vorschlag die Charge über alle Mitglieder des Meta-Artikels.
CREATE OR REPLACE FUNCTION lager.entnahme(p_subjekt text, p_charge uuid, p_ort uuid, p_menge numeric, p_zweck text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE c lager.charge; da numeric; bid uuid; vor uuid; vc lager.charge; v_meta uuid;
BEGIN
    da := lager._bestand_sperren(p_charge, p_ort);
    SELECT * INTO c FROM lager.charge WHERE id = p_charge;
    IF c.gesperrt THEN
        PERFORM lager._fehler('Die Charge ist gesperrt: ' || c.sperrgrund, 'KM003');
    END IF;
    IF c.verfall IS NOT NULL AND c.verfall < current_date THEN
        PERFORM lager._fehler(format('Die Charge ist seit dem %s abgelaufen – sie lässt sich nur ausbuchen', to_char(c.verfall, 'DD.MM.YYYY')), 'KM003');
    END IF;
    IF p_menge IS NULL OR p_menge <= 0 OR p_menge <> round(p_menge, 3) THEN
        PERFORM lager._fehler('Menge größer als null mit höchstens drei Nachkommastellen erwartet');
    END IF;
    IF p_menge > da THEN
        PERFORM lager._fehler(format('An diesem Lagerort sind nur %s verfügbar', lager._menge(da)), 'KM003');
    END IF;
    IF p_zweck IS NOT NULL AND NOT EXISTS (SELECT 1 FROM lager.einstellung e, jsonb_array_elements_text(e.zwecke) z WHERE z = p_zweck) THEN
        PERFORM lager._fehler('Zweck: nur ein Eintrag der Auswahlliste des Buchs');
    END IF;
    SELECT meta_artikel INTO v_meta FROM lager.artikel WHERE id = c.artikel;
    vor := CASE WHEN v_meta IS NULL THEN lager.vorschlag(c.artikel) ELSE lager.vorschlag_meta(v_meta) END;
    IF vor IS NOT NULL AND vor <> c.id THEN
        SELECT * INTO vc FROM lager.charge WHERE id = vor;
    END IF;
    INSERT INTO lager.bewegung (art, charge, ort_von, menge, subjekt, zweck, abweichung)
    VALUES ('entnahme', p_charge, p_ort, p_menge, p_subjekt, p_zweck, vc.id IS NOT NULL AND vc.verfall IS DISTINCT FROM c.verfall)
    RETURNING id INTO bid;
    PERFORM lager._bewegt(p_subjekt, bid);
    RETURN bid;
END $$;

REVOKE ALL ON lager.meta_artikel FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON lager.meta_artikel TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON FUNCTION lager.tg_artikel_meta(), lager._meta_stand(lager.meta_artikel), lager.meta_setzen(text, uuid, jsonb), lager.meta_zuordnen(text, uuid, uuid),
    lager.vorschlag_meta(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION lager.meta_setzen(text, uuid, jsonb), lager.meta_zuordnen(text, uuid, uuid), lager.vorschlag_meta(uuid) TO kumero_app;
