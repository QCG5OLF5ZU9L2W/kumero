-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul lager, Migration 0002: Sollbestand und Nachbestellliste, Inventur (LAGER.md Stufe 2, F132)
--
-- Die Inventur zählt je Charge und Lagerort. Gerechnet wird gegen den Bestand im Augenblick der Zählung jeder Zeile:
-- Was danach entnommen wird, verfälscht die Differenz nicht. Abschließen überschreibt nichts – jede Abweichung wird
-- eine Bewegung mit dem Grund „inventur“ (WW3).

ALTER TABLE lager.artikel ADD COLUMN soll numeric(15,3) CHECK (soll IS NULL OR soll >= 0),
    ADD CONSTRAINT artikel_soll_mindest_check CHECK (soll IS NULL OR mindest IS NULL OR soll >= mindest);

-- Von wem ein Zugang kam (Name der Gegenpartei des Belegs) – für „letzter Lieferant“ in der Nachbestellliste.
ALTER TABLE lager.bewegung ADD COLUMN lieferant text CHECK (lieferant IS NULL OR length(lieferant) <= 200),
    ADD COLUMN inventur uuid;
ALTER TABLE lager.bewegung DROP CONSTRAINT bewegung_grund_check;
ALTER TABLE lager.bewegung ADD CONSTRAINT bewegung_grund_check
    CHECK (grund IS NULL OR grund IN ('verfallen', 'beschaedigt', 'zurueckgegeben', 'vernichtet', 'berichtigung', 'anfangsbestand', 'muster', 'inventur'));

-- Die Artikelnummer des Lieferanten so, wie sie auf dem Beleg steht (der Schlüssel `nummer` ist klein geschrieben).
ALTER TABLE lager.lieferantenartikel ADD COLUMN nummer_beleg text CHECK (nummer_beleg IS NULL OR length(nummer_beleg) <= 200),
    ADD COLUMN lieferant_name text CHECK (lieferant_name IS NULL OR length(lieferant_name) <= 200);

CREATE TABLE lager.inventur (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    nr               bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    ort              uuid REFERENCES lager.ort,                                      -- NULL: alle Lagerorte
    stichtag         date NOT NULL DEFAULT current_date,
    zustand          text NOT NULL DEFAULT 'offen' CHECK (zustand IN ('offen', 'abgeschlossen', 'verworfen')),
    angelegt_am      timestamptz NOT NULL DEFAULT clock_timestamp(),
    angelegt_von     text NOT NULL,
    beendet_am       timestamptz,
    beendet_von      text,
    CHECK ((zustand = 'offen') = (beendet_am IS NULL))
);

CREATE TABLE lager.inventur_zeile (
    inventur      uuid NOT NULL REFERENCES lager.inventur,
    charge        uuid NOT NULL REFERENCES lager.charge,
    ort           uuid NOT NULL REFERENCES lager.ort,
    bestand       numeric(15,3),                                                     -- Bestand im Augenblick der Zählung
    ist           numeric(15,3) CHECK (ist IS NULL OR ist >= 0),
    gezaehlt_am   timestamptz,
    gezaehlt_von  text,
    PRIMARY KEY (inventur, charge, ort),
    CHECK ((ist IS NULL) = (gezaehlt_am IS NULL) AND (ist IS NULL) = (bestand IS NULL))
);

CREATE OR REPLACE FUNCTION lager._artikel_stand(a lager.artikel) RETURNS jsonb LANGUAGE sql STABLE AS $$
    SELECT to_jsonb(a) - 'id' - 'angelegt_am' - 'geaendert_am' - 'geaendert_von' || jsonb_build_object('mindest', a.mindest::text, 'soll', a.soll::text)
$$;

CREATE OR REPLACE FUNCTION lager.artikel_setzen(p_subjekt text, p_id uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE alt lager.artikel; neu lager.artikel;
BEGIN
    IF (p->>'soll')::numeric < (p->>'mindest')::numeric THEN
        PERFORM lager._fehler('Der Sollbestand liegt unter dem Mindestbestand');
    END IF;
    IF p_id IS NULL THEN
        INSERT INTO lager.artikel (nummer, bezeichnung, art, einheit, fuehrung, verfall_pflicht, btm, gtin, mindest, soll, standardort, lagerbedingung, geaendert_von)
        VALUES (nullif(trim(p->>'nummer'), ''), trim(p->>'bezeichnung'), p->>'art', trim(p->>'einheit'), p->>'fuehrung', coalesce((p->>'verfall_pflicht')::boolean, false),
                coalesce((p->>'btm')::boolean, false), nullif(p->>'gtin', ''), (p->>'mindest')::numeric, (p->>'soll')::numeric, (p->>'standardort')::uuid,
                nullif(trim(p->>'lagerbedingung'), ''), p_subjekt)
        RETURNING * INTO neu;
        PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.artikel_angelegt', jsonb_build_object('artikel', neu.id::text), NULL, lager._artikel_stand(neu));
        RETURN neu.id;
    END IF;
    SELECT * INTO alt FROM lager.artikel WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM lager._fehler('Artikel unbekannt', 'KM003');
    END IF;
    IF alt.fuehrung <> p->>'fuehrung' AND EXISTS (SELECT 1 FROM lager.bewegung b JOIN lager.charge c ON c.id = b.charge WHERE c.artikel = p_id) THEN
        PERFORM lager._fehler('Die Führungsart steht fest, sobald der Artikel Bewegungen hat', 'KM003');
    END IF;
    IF coalesce((p->>'stillgelegt')::boolean, false) AND EXISTS (SELECT 1 FROM lager.bestand s JOIN lager.charge c ON c.id = s.charge WHERE c.artikel = p_id AND s.menge > 0) THEN
        PERFORM lager._fehler('Der Artikel hat noch Bestand und kann nicht stillgelegt werden', 'KM003');
    END IF;
    UPDATE lager.artikel SET nummer = nullif(trim(p->>'nummer'), ''), bezeichnung = trim(p->>'bezeichnung'), art = p->>'art', einheit = trim(p->>'einheit'),
           fuehrung = p->>'fuehrung', verfall_pflicht = coalesce((p->>'verfall_pflicht')::boolean, false), btm = coalesce((p->>'btm')::boolean, false),
           gtin = nullif(p->>'gtin', ''), mindest = (p->>'mindest')::numeric, soll = (p->>'soll')::numeric, standardort = (p->>'standardort')::uuid,
           lagerbedingung = nullif(trim(p->>'lagerbedingung'), ''), stillgelegt = coalesce((p->>'stillgelegt')::boolean, false),
           geaendert_am = clock_timestamp(), geaendert_von = p_subjekt
     WHERE id = p_id RETURNING * INTO neu;
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.artikel_geaendert', jsonb_build_object('artikel', p_id::text), lager._artikel_stand(alt), lager._artikel_stand(neu));
    RETURN p_id;
END $$;

CREATE OR REPLACE FUNCTION lager._bewegt(p_subjekt text, p_id uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE b lager.bewegung; c lager.charge;
BEGIN
    SELECT * INTO b FROM lager.bewegung WHERE id = p_id;
    SELECT * INTO c FROM lager.charge WHERE id = b.charge;
    -- im Protokoll nur IDs, Mengen und Kennungen (LAGER.md Abschnitt 7) – auch kein Name des Lieferanten
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.' || b.art,
        jsonb_strip_nulls(jsonb_build_object('bewegung', b.id::text, 'charge', b.charge::text, 'artikel', c.artikel::text, 'inventur', b.inventur::text)), NULL,
        jsonb_strip_nulls(jsonb_build_object('menge', lager._menge(b.menge), 'ort_von', b.ort_von::text, 'ort_nach', b.ort_nach::text, 'preis', b.preis::text,
            'eintrag', b.eintrag::text, 'position', b.position, 'zweck', b.zweck, 'grund', b.grund, 'abweichung', nullif(b.abweichung, false))));
END $$;

-- Zugang wie bisher, dazu der Name des Lieferanten (p->>'lieferant').
CREATE OR REPLACE FUNCTION lager.zugang(p_subjekt text, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE a lager.artikel; c lager.charge; o lager.ort; bez text := coalesce(trim(p->>'charge'), ''); v date := (p->>'verfall')::date;
        m numeric := (p->>'menge')::numeric; bid uuid;
BEGIN
    SELECT * INTO a FROM lager.artikel WHERE id = (p->>'artikel')::uuid FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM lager._fehler('Artikel unbekannt', 'KM003');
    END IF;
    IF a.stillgelegt THEN
        PERFORM lager._fehler('Der Artikel ist stillgelegt', 'KM003');
    END IF;
    IF a.btm THEN
        PERFORM lager._fehler('Betäubungsmittel werden nicht im Lager geführt – der Nachweis gehört ins Betäubungsmittelbuch', 'KM003');
    END IF;
    SELECT * INTO o FROM lager.ort WHERE id = (p->>'ort')::uuid;
    IF NOT FOUND OR o.stillgelegt THEN
        PERFORM lager._fehler('Lagerort unbekannt oder stillgelegt', 'KM003');
    END IF;
    IF m IS NULL OR m <= 0 OR m <> round(m, 3) THEN
        PERFORM lager._fehler('Menge größer als null mit höchstens drei Nachkommastellen erwartet');
    END IF;
    IF a.fuehrung = 'ohne' THEN
        IF bez <> '' OR v IS NOT NULL THEN
            PERFORM lager._fehler('Der Artikel wird ohne Charge geführt');
        END IF;
    ELSIF bez = '' THEN
        PERFORM lager._fehler(CASE a.fuehrung WHEN 'serie' THEN 'Seriennummer fehlt' ELSE 'Charge fehlt' END);
    END IF;
    IF a.verfall_pflicht AND v IS NULL THEN
        PERFORM lager._fehler('Verfallsdatum fehlt');
    END IF;
    IF a.fuehrung = 'serie' AND m <> 1 THEN
        PERFORM lager._fehler('Mit Seriennummer ist die Menge je Stück 1');
    END IF;
    SELECT * INTO c FROM lager.charge WHERE artikel = a.id AND bezeichnung = bez FOR UPDATE;
    IF FOUND THEN
        IF c.verfall IS DISTINCT FROM v THEN
            PERFORM lager._fehler(format('Die Charge ist mit Verfall %s bekannt', coalesce(to_char(c.verfall, 'DD.MM.YYYY'), 'ohne Datum')));
        END IF;
        IF a.fuehrung = 'serie' AND EXISTS (SELECT 1 FROM lager.bestand WHERE charge = c.id AND menge > 0) THEN
            PERFORM lager._fehler('Diese Seriennummer ist schon im Bestand');
        END IF;
    ELSE
        INSERT INTO lager.charge (artikel, bezeichnung, verfall, code) VALUES (a.id, bez, v, nullif(p->>'code', '')) RETURNING * INTO c;
    END IF;
    INSERT INTO lager.bewegung (art, charge, ort_nach, menge, preis, subjekt, eintrag, position, grund, lieferant)
    VALUES ('zugang', c.id, o.id, m, (p->>'preis')::numeric, p_subjekt, (p->>'eintrag')::uuid, nullif(p->>'position', ''), nullif(p->>'grund', ''),
            nullif(left(trim(coalesce(p->>'lieferant', '')), 200), ''))
    RETURNING id INTO bid;
    PERFORM lager._bewegt(p_subjekt, bid);
    RETURN bid;
END $$;

CREATE FUNCTION lager.merken(p_subjekt text, p_lieferant text, p_nummer text, p_artikel uuid, p_faktor numeric, p_name text, p_nummer_beleg text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
BEGIN
    PERFORM lager.merken(p_subjekt, p_lieferant, p_nummer, p_artikel, p_faktor);
    UPDATE lager.lieferantenartikel SET lieferant_name = nullif(left(trim(coalesce(p_name, '')), 200), ''), nummer_beleg = nullif(left(trim(coalesce(p_nummer_beleg, '')), 200), '')
     WHERE lieferant = lower(trim(p_lieferant)) AND nummer = lower(trim(p_nummer));
END $$;

-- Legt eine Inventur an: je Charge und Lagerort mit Bestand eine Zeile. p_ort NULL: alle Lagerorte.
CREATE FUNCTION lager.inventur_anlegen(p_subjekt text, p_ort uuid)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE iid uuid; n integer;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtext('lager.inventur'));
    IF p_ort IS NOT NULL AND NOT EXISTS (SELECT 1 FROM lager.ort WHERE id = p_ort) THEN
        PERFORM lager._fehler('Lagerort unbekannt', 'KM003');
    END IF;
    IF EXISTS (SELECT 1 FROM lager.inventur WHERE zustand = 'offen' AND (ort IS NULL OR p_ort IS NULL OR ort = p_ort)) THEN
        PERFORM lager._fehler('Für diesen Lagerort ist schon eine Inventur offen', 'KM003');
    END IF;
    INSERT INTO lager.inventur (ort, angelegt_von) VALUES (p_ort, p_subjekt) RETURNING id INTO iid;
    INSERT INTO lager.inventur_zeile (inventur, charge, ort)
        SELECT iid, s.charge, s.ort FROM lager.bestand s WHERE s.menge > 0 AND (p_ort IS NULL OR s.ort = p_ort);
    GET DIAGNOSTICS n = ROW_COUNT;
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.inventur_angelegt', jsonb_strip_nulls(jsonb_build_object('inventur', iid::text, 'ort', p_ort::text)), NULL,
        jsonb_build_object('zeilen', n));
    RETURN iid;
END $$;

CREATE FUNCTION lager._inventur_offen(p_inventur uuid) RETURNS lager.inventur LANGUAGE plpgsql AS $$
DECLARE i lager.inventur;
BEGIN
    SELECT * INTO i FROM lager.inventur WHERE id = p_inventur FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM lager._fehler('Inventur unbekannt', 'KM003');
    END IF;
    IF i.zustand <> 'offen' THEN
        PERFORM lager._fehler('Die Inventur ist nicht mehr offen', 'KM003');
    END IF;
    RETURN i;
END $$;

-- Eine Zeile dazu: etwas liegt da, das laut Bestand nicht da ist. Die Charge muss bekannt sein.
CREATE FUNCTION lager.inventur_zeile_dazu(p_subjekt text, p_inventur uuid, p_charge uuid, p_ort uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE i lager.inventur;
BEGIN
    i := lager._inventur_offen(p_inventur);
    IF NOT EXISTS (SELECT 1 FROM lager.charge WHERE id = p_charge) OR NOT EXISTS (SELECT 1 FROM lager.ort WHERE id = p_ort AND NOT stillgelegt) THEN
        PERFORM lager._fehler('Charge oder Lagerort unbekannt', 'KM003');
    END IF;
    IF i.ort IS NOT NULL AND i.ort <> p_ort THEN
        PERFORM lager._fehler('Die Inventur gilt für einen anderen Lagerort', 'KM003');
    END IF;
    INSERT INTO lager.inventur_zeile (inventur, charge, ort) VALUES (p_inventur, p_charge, p_ort) ON CONFLICT DO NOTHING;
END $$;

-- Zählen (p_ist gesetzt) oder die Zählung zurücknehmen (p_ist NULL). Der Bestand wird in diesem Augenblick festgehalten.
CREATE FUNCTION lager.inventur_zaehlen(p_subjekt text, p_inventur uuid, p_charge uuid, p_ort uuid, p_ist numeric)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE da numeric; f text;
BEGIN
    PERFORM lager._inventur_offen(p_inventur);
    IF p_ist IS NOT NULL AND (p_ist < 0 OR p_ist <> round(p_ist, 3)) THEN
        PERFORM lager._fehler('Gezählt: eine Menge ab null mit höchstens drei Nachkommastellen erwartet');
    END IF;
    da := lager._bestand_sperren(p_charge, p_ort);
    SELECT a.fuehrung INTO f FROM lager.charge c JOIN lager.artikel a ON a.id = c.artikel WHERE c.id = p_charge;
    IF f = 'serie' AND p_ist NOT IN (0, 1) THEN
        PERFORM lager._fehler('Mit Seriennummer ist ein Stück da (1) oder nicht (0)');
    END IF;
    UPDATE lager.inventur_zeile SET ist = p_ist, bestand = CASE WHEN p_ist IS NULL THEN NULL ELSE da END,
           gezaehlt_am = CASE WHEN p_ist IS NULL THEN NULL ELSE clock_timestamp() END, gezaehlt_von = CASE WHEN p_ist IS NULL THEN NULL ELSE p_subjekt END
     WHERE inventur = p_inventur AND charge = p_charge AND ort = p_ort;
    IF NOT FOUND THEN
        PERFORM lager._fehler('Diese Zeile gehört nicht zur Inventur', 'KM003');
    END IF;
END $$;

-- Abschließen: jede Abweichung wird eine Bewegung; nichts wird überschrieben. Alle Zeilen müssen gezählt sein.
CREATE FUNCTION lager.inventur_abschliessen(p_subjekt text, p_inventur uuid)
RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE z record; da numeric; d numeric; bid uuid; n integer := 0;
BEGIN
    PERFORM lager._inventur_offen(p_inventur);
    IF EXISTS (SELECT 1 FROM lager.inventur_zeile WHERE inventur = p_inventur AND ist IS NULL) THEN
        PERFORM lager._fehler('Es sind noch nicht alle Zeilen gezählt', 'KM003');
    END IF;
    FOR z IN SELECT * FROM lager.inventur_zeile WHERE inventur = p_inventur ORDER BY charge, ort LOOP
        d := z.ist - z.bestand;
        CONTINUE WHEN d = 0;
        da := lager._bestand_sperren(z.charge, z.ort);
        IF d < 0 AND -d > da THEN
            PERFORM lager._fehler(format('Seit der Zählung ist mehr entnommen worden, als danach noch da sein konnte (Charge %s) – diese Zeile bitte neu zählen',
                (SELECT bezeichnung FROM lager.charge WHERE id = z.charge)), 'KM003');
        END IF;
        IF d > 0 THEN
            INSERT INTO lager.bewegung (art, charge, ort_nach, menge, subjekt, grund, inventur) VALUES ('zugang', z.charge, z.ort, d, p_subjekt, 'inventur', p_inventur) RETURNING id INTO bid;
        ELSE
            INSERT INTO lager.bewegung (art, charge, ort_von, menge, subjekt, grund, inventur) VALUES ('ausbuchung', z.charge, z.ort, -d, p_subjekt, 'inventur', p_inventur) RETURNING id INTO bid;
        END IF;
        PERFORM lager._bewegt(p_subjekt, bid);
        n := n + 1;
    END LOOP;
    UPDATE lager.inventur SET zustand = 'abgeschlossen', beendet_am = clock_timestamp(), beendet_von = p_subjekt WHERE id = p_inventur;
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.inventur_abgeschlossen', jsonb_build_object('inventur', p_inventur::text), NULL, jsonb_build_object('abweichungen', n));
    RETURN n;
END $$;

CREATE FUNCTION lager.inventur_verwerfen(p_subjekt text, p_inventur uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
BEGIN
    PERFORM lager._inventur_offen(p_inventur);
    UPDATE lager.inventur SET zustand = 'verworfen', beendet_am = clock_timestamp(), beendet_von = p_subjekt WHERE id = p_inventur;
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.inventur_verworfen', jsonb_build_object('inventur', p_inventur::text), NULL, NULL);
END $$;

REVOKE ALL ON lager.inventur, lager.inventur_zeile FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON lager.inventur, lager.inventur_zeile TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA lager FROM PUBLIC;
GRANT EXECUTE ON FUNCTION lager.inventur_anlegen(text, uuid), lager.inventur_zeile_dazu(text, uuid, uuid, uuid), lager.inventur_zaehlen(text, uuid, uuid, uuid, numeric),
    lager.inventur_abschliessen(text, uuid), lager.inventur_verwerfen(text, uuid), lager.merken(text, text, text, uuid, numeric, text, text) TO kumero_app;
