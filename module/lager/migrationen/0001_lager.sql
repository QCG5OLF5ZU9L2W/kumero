-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul lager, Migration 0001: Lager eines Buchs mit Chargen, Seriennummern und Verfallsdaten (LAGER.md Stufe 1, F131)
--
-- Das Lager führt Mengen, das Buch führt Werte: Eine Bewegung ist keine Buchung. Eigene Daten im eigenen Schema
-- (F44), Schreiben nur über Funktionen mit Protokoll (F48, F64). Bewegungen werden nur angefügt; der Bestand ist ihre
-- Summe und wird nie gesetzt (WW3). Es gibt keinen Patientenbezug und kein freies Textfeld an der Entnahme (WW14).

CREATE SCHEMA lager AUTHORIZATION kumero_owner;
REVOKE ALL ON SCHEMA lager FROM PUBLIC;
GRANT USAGE ON SCHEMA lager TO kumero_app, kumero_lesen;

CREATE TABLE lager.einstellung (
    einzig         boolean PRIMARY KEY DEFAULT true CHECK (einzig),
    warn_tage      integer NOT NULL DEFAULT 90 CHECK (warn_tage BETWEEN 1 AND 730),   -- „läuft ab“ ab so vielen Tagen vor dem Verfall
    zwecke         jsonb NOT NULL DEFAULT '["Verbrauch"]' CHECK (jsonb_typeof(zwecke) = 'array'),   -- Auswahlliste für die Entnahme (WW14)
    geaendert_am   timestamptz NOT NULL DEFAULT now(),
    geaendert_von  text
);
INSERT INTO lager.einstellung DEFAULT VALUES;

CREATE TABLE lager.ort (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name         text NOT NULL UNIQUE CHECK (length(trim(name)) BETWEEN 1 AND 80),
    gekuehlt     boolean NOT NULL DEFAULT false,
    stillgelegt  boolean NOT NULL DEFAULT false,
    angelegt_am  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE lager.artikel (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    nummer          text UNIQUE CHECK (nummer IS NULL OR length(trim(nummer)) BETWEEN 1 AND 40),
    bezeichnung     text NOT NULL CHECK (length(trim(bezeichnung)) BETWEEN 1 AND 200),
    art             text NOT NULL CHECK (art IN ('medizinprodukt', 'arzneimittel', 'verbrauch', 'sonstiges')),
    einheit         text NOT NULL CHECK (length(trim(einheit)) BETWEEN 1 AND 20),
    fuehrung        text NOT NULL CHECK (fuehrung IN ('ohne', 'charge', 'serie')),   -- WW4
    verfall_pflicht boolean NOT NULL DEFAULT false,                                  -- WW5
    btm             boolean NOT NULL DEFAULT false,                                  -- WW17: wird nicht im Lager geführt
    gtin            text CHECK (gtin IS NULL OR gtin ~ '^[0-9]{8,14}$'),
    mindest         numeric(15,3) CHECK (mindest IS NULL OR mindest >= 0),
    standardort     uuid REFERENCES lager.ort,
    lagerbedingung  text CHECK (lagerbedingung IS NULL OR length(lagerbedingung) <= 100),
    stillgelegt     boolean NOT NULL DEFAULT false,                                  -- WW23: nie löschen
    angelegt_am     timestamptz NOT NULL DEFAULT now(),
    geaendert_am    timestamptz NOT NULL DEFAULT now(),
    geaendert_von   text NOT NULL,
    CHECK (fuehrung <> 'ohne' OR NOT verfall_pflicht)
);

-- Eine Charge oder ein einzelnes Stück mit Seriennummer. Artikel ohne Chargenführung haben genau eine Zeile mit
-- leerer Bezeichnung – so ist jede Bewegung einer Charge zugeordnet.
CREATE TABLE lager.charge (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    artikel      uuid NOT NULL REFERENCES lager.artikel,
    bezeichnung  text NOT NULL CHECK (length(bezeichnung) <= 50),
    verfall      date,
    code         text CHECK (code IS NULL OR length(code) <= 200),                   -- gelesener Code der Packung (UDI), falls gescannt
    gesperrt     boolean NOT NULL DEFAULT false,
    sperrgrund   text CHECK (sperrgrund IS NULL OR length(trim(sperrgrund)) BETWEEN 1 AND 200),
    angelegt_am  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (artikel, bezeichnung),
    CHECK (gesperrt = (sperrgrund IS NOT NULL))
);

-- Wann eine Charge gesperrt und wieder freigegeben wurde – für die Rückverfolgung (das Protokoll des Kerns liest das Modul nicht, F44).
CREATE TABLE lager.sperre (
    charge    uuid NOT NULL REFERENCES lager.charge,
    zeit      timestamptz NOT NULL DEFAULT clock_timestamp(),
    subjekt   text NOT NULL,
    grund     text,                                                                  -- NULL: freigegeben
    PRIMARY KEY (charge, zeit)
);

CREATE TABLE lager.bewegung (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    nr          bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    art         text NOT NULL CHECK (art IN ('zugang', 'entnahme', 'umlagerung', 'ausbuchung')),
    charge      uuid NOT NULL REFERENCES lager.charge,
    ort_von     uuid REFERENCES lager.ort,
    ort_nach    uuid REFERENCES lager.ort,
    menge       numeric(15,3) NOT NULL CHECK (menge > 0),
    preis       numeric(15,4) CHECK (preis IS NULL OR preis >= 0),                   -- Einstandspreis je Lagereinheit, nachrichtlich
    zeit        timestamptz NOT NULL DEFAULT clock_timestamp(),
    subjekt     text NOT NULL,                                                       -- wer; verlässt den Mandanten nicht (F114)
    eintrag     uuid,                                                                -- Eintrag im Arbeitsvorrat (kein Verweis in fremde Schemata, F44)
    position    text CHECK (position IS NULL OR length(position) <= 20),
    zweck       text CHECK (zweck IS NULL OR length(zweck) <= 40),                   -- aus der Auswahlliste des Buchs
    grund       text CHECK (grund IS NULL OR grund IN ('verfallen', 'beschaedigt', 'zurueckgegeben', 'vernichtet', 'berichtigung', 'anfangsbestand', 'muster')),
    abweichung  boolean NOT NULL DEFAULT false,                                      -- Entnahme nicht aus der vorgeschlagenen Charge (WW6)
    CHECK ((art = 'zugang') = (ort_von IS NULL)),
    CHECK ((art IN ('entnahme', 'ausbuchung')) = (ort_nach IS NULL)),
    CHECK (art <> 'umlagerung' OR ort_von <> ort_nach),
    CHECK (art <> 'ausbuchung' OR grund IS NOT NULL)
);
CREATE INDEX bewegung_charge_idx ON lager.bewegung (charge, nr);
CREATE INDEX bewegung_eintrag_idx ON lager.bewegung (eintrag) WHERE eintrag IS NOT NULL;

-- Gemerkte Zuordnung: Was ein Lieferant unter seiner Artikelnummer liefert (WW21, erste Fassung: Faktor und Artikel).
CREATE TABLE lager.lieferantenartikel (
    lieferant    text NOT NULL CHECK (length(lieferant) BETWEEN 1 AND 200),          -- Kennung oder Name, klein geschrieben
    nummer       text NOT NULL CHECK (length(nummer) BETWEEN 1 AND 200),             -- Artikelnummer des Lieferanten, sonst die Bezeichnung
    artikel      uuid REFERENCES lager.artikel,
    kein_lager   boolean NOT NULL DEFAULT false,                                     -- z. B. Versandkosten
    faktor       numeric(12,3) NOT NULL DEFAULT 1 CHECK (faktor > 0),                -- 1 Einheit des Belegs = so viele Lagereinheiten
    gemerkt_am   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (lieferant, nummer),
    CHECK (kein_lager = (artikel IS NULL))
);

-- Bestand je Charge und Lagerort: die Summe der Bewegungen.
CREATE VIEW lager.bestand AS
    SELECT charge, ort, sum(menge) AS menge
      FROM (SELECT charge, ort_nach AS ort, menge FROM lager.bewegung WHERE ort_nach IS NOT NULL
            UNION ALL
            SELECT charge, ort_von, -menge FROM lager.bewegung WHERE ort_von IS NOT NULL) b
     GROUP BY charge, ort;

CREATE FUNCTION lager._fehler(p_text text, p_code text DEFAULT 'KM002') RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION USING ERRCODE = p_code, MESSAGE = p_text;
END $$;

CREATE FUNCTION lager._menge(m numeric) RETURNS text LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN m = trunc(m) THEN trunc(m)::text ELSE rtrim(m::text, '0') END
$$;

CREATE FUNCTION lager.einstellung_setzen(p_subjekt text, p_warn_tage integer, p_zwecke jsonb)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE alt jsonb; neu jsonb;
BEGIN
    IF jsonb_typeof(p_zwecke) <> 'array' OR jsonb_array_length(p_zwecke) NOT BETWEEN 1 AND 20
       OR EXISTS (SELECT 1 FROM jsonb_array_elements(p_zwecke) z WHERE jsonb_typeof(z) <> 'string' OR length(trim(z #>> '{}')) NOT BETWEEN 1 AND 40) THEN
        PERFORM lager._fehler('Zwecke: 1 bis 20 Bezeichnungen mit je höchstens 40 Zeichen erwartet');
    END IF;
    SELECT jsonb_build_object('warn_tage', warn_tage, 'zwecke', zwecke) INTO alt FROM lager.einstellung;
    UPDATE lager.einstellung SET warn_tage = p_warn_tage, zwecke = p_zwecke, geaendert_am = clock_timestamp(), geaendert_von = p_subjekt;
    neu := jsonb_build_object('warn_tage', p_warn_tage, 'zwecke', p_zwecke);
    IF alt IS DISTINCT FROM neu THEN
        PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.einstellung_geaendert', '{}'::jsonb, alt, neu);
    END IF;
END $$;

CREATE FUNCTION lager.ort_setzen(p_subjekt text, p_id uuid, p_name text, p_gekuehlt boolean, p_stillgelegt boolean)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE alt lager.ort; neu lager.ort;
BEGIN
    IF EXISTS (SELECT 1 FROM lager.ort WHERE lower(name) = lower(trim(p_name)) AND id IS DISTINCT FROM p_id) THEN
        PERFORM lager._fehler('Einen Lagerort mit diesem Namen gibt es schon');
    END IF;
    IF p_id IS NULL THEN
        INSERT INTO lager.ort (name, gekuehlt) VALUES (trim(p_name), coalesce(p_gekuehlt, false)) RETURNING * INTO neu;
        PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.ort_angelegt', jsonb_build_object('ort', neu.id::text), NULL,
            jsonb_build_object('name', neu.name, 'gekuehlt', neu.gekuehlt));
        RETURN neu.id;
    END IF;
    SELECT * INTO alt FROM lager.ort WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM lager._fehler('Lagerort unbekannt', 'KM003');
    END IF;
    IF coalesce(p_stillgelegt, false) AND EXISTS (SELECT 1 FROM lager.bestand WHERE ort = p_id AND menge > 0) THEN
        PERFORM lager._fehler('Der Lagerort hat noch Bestand und kann nicht stillgelegt werden', 'KM003');
    END IF;
    UPDATE lager.ort SET name = trim(p_name), gekuehlt = coalesce(p_gekuehlt, false), stillgelegt = coalesce(p_stillgelegt, false) WHERE id = p_id RETURNING * INTO neu;
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.ort_geaendert', jsonb_build_object('ort', p_id::text),
        jsonb_build_object('name', alt.name, 'gekuehlt', alt.gekuehlt, 'stillgelegt', alt.stillgelegt),
        jsonb_build_object('name', neu.name, 'gekuehlt', neu.gekuehlt, 'stillgelegt', neu.stillgelegt));
    RETURN p_id;
END $$;

CREATE FUNCTION lager._artikel_stand(a lager.artikel) RETURNS jsonb LANGUAGE sql STABLE AS $$
    -- Mengen als Text: die Kette nimmt keine Dezimalzahlen (F29)
    SELECT to_jsonb(a) - 'id' - 'angelegt_am' - 'geaendert_am' - 'geaendert_von' || jsonb_build_object('mindest', a.mindest::text)
$$;

-- Legt einen Artikel an (p_id NULL) oder ändert ihn. Die Führungsart steht nach der ersten Bewegung fest (WW4).
CREATE FUNCTION lager.artikel_setzen(p_subjekt text, p_id uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE alt lager.artikel; neu lager.artikel;
BEGIN
    IF p_id IS NULL THEN
        INSERT INTO lager.artikel (nummer, bezeichnung, art, einheit, fuehrung, verfall_pflicht, btm, gtin, mindest, standardort, lagerbedingung, geaendert_von)
        VALUES (nullif(trim(p->>'nummer'), ''), trim(p->>'bezeichnung'), p->>'art', trim(p->>'einheit'), p->>'fuehrung', coalesce((p->>'verfall_pflicht')::boolean, false),
                coalesce((p->>'btm')::boolean, false), nullif(p->>'gtin', ''), (p->>'mindest')::numeric, (p->>'standardort')::uuid, nullif(trim(p->>'lagerbedingung'), ''), p_subjekt)
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
           gtin = nullif(p->>'gtin', ''), mindest = (p->>'mindest')::numeric, standardort = (p->>'standardort')::uuid,
           lagerbedingung = nullif(trim(p->>'lagerbedingung'), ''), stillgelegt = coalesce((p->>'stillgelegt')::boolean, false),
           geaendert_am = clock_timestamp(), geaendert_von = p_subjekt
     WHERE id = p_id RETURNING * INTO neu;
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.artikel_geaendert', jsonb_build_object('artikel', p_id::text), lager._artikel_stand(alt), lager._artikel_stand(neu));
    RETURN p_id;
END $$;

-- Bestand einer Charge an einem Ort; sperrt die Charge, damit zwei Entnahmen nicht denselben Bestand nehmen (WW22).
CREATE FUNCTION lager._bestand_sperren(p_charge uuid, p_ort uuid) RETURNS numeric LANGUAGE plpgsql AS $$
DECLARE m numeric;
BEGIN
    PERFORM 1 FROM lager.charge WHERE id = p_charge FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM lager._fehler('Charge unbekannt', 'KM003');
    END IF;
    SELECT coalesce(sum(menge), 0) INTO m FROM lager.bestand WHERE charge = p_charge AND ort = p_ort;
    RETURN m;
END $$;

CREATE FUNCTION lager._bewegt(p_subjekt text, p_id uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE b lager.bewegung; c lager.charge;
BEGIN
    SELECT * INTO b FROM lager.bewegung WHERE id = p_id;
    SELECT * INTO c FROM lager.charge WHERE id = b.charge;
    -- im Protokoll nur IDs, Mengen und Kennungen (LAGER.md Abschnitt 7)
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.' || b.art,
        jsonb_build_object('bewegung', b.id::text, 'charge', b.charge::text, 'artikel', c.artikel::text), NULL,
        jsonb_strip_nulls(jsonb_build_object('menge', lager._menge(b.menge), 'ort_von', b.ort_von::text, 'ort_nach', b.ort_nach::text, 'preis', b.preis::text,
            'eintrag', b.eintrag::text, 'position', b.position, 'zweck', b.zweck, 'grund', b.grund, 'abweichung', nullif(b.abweichung, false))));
END $$;

-- Zugang: findet die Charge oder legt sie an. p: artikel, charge, verfall, ort, menge, preis, eintrag, position, grund, code.
CREATE FUNCTION lager.zugang(p_subjekt text, p jsonb)
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
    INSERT INTO lager.bewegung (art, charge, ort_nach, menge, preis, subjekt, eintrag, position, grund)
    VALUES ('zugang', c.id, o.id, m, (p->>'preis')::numeric, p_subjekt, (p->>'eintrag')::uuid, nullif(p->>'position', ''), nullif(p->>'grund', ''))
    RETURNING id INTO bid;
    PERFORM lager._bewegt(p_subjekt, bid);
    RETURN bid;
END $$;

-- Die Charge, die bei einer Entnahme vorgeschlagen wird: frei, nicht abgelaufen, mit Bestand, frühester Verfall zuerst (WW6).
CREATE FUNCTION lager.vorschlag(p_artikel uuid) RETURNS uuid LANGUAGE sql STABLE AS $$
    SELECT c.id FROM lager.charge c
     WHERE c.artikel = p_artikel AND NOT c.gesperrt AND (c.verfall IS NULL OR c.verfall >= current_date)
       AND EXISTS (SELECT 1 FROM lager.bestand s WHERE s.charge = c.id AND s.menge > 0)
     ORDER BY c.verfall NULLS LAST, c.angelegt_am, c.id LIMIT 1
$$;

CREATE FUNCTION lager.entnahme(p_subjekt text, p_charge uuid, p_ort uuid, p_menge numeric, p_zweck text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE c lager.charge; da numeric; bid uuid; vor uuid; vc lager.charge;
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
    vor := lager.vorschlag(c.artikel);
    IF vor IS NOT NULL AND vor <> c.id THEN
        SELECT * INTO vc FROM lager.charge WHERE id = vor;
    END IF;
    INSERT INTO lager.bewegung (art, charge, ort_von, menge, subjekt, zweck, abweichung)
    VALUES ('entnahme', p_charge, p_ort, p_menge, p_subjekt, p_zweck, vc.id IS NOT NULL AND vc.verfall IS DISTINCT FROM c.verfall)
    RETURNING id INTO bid;
    PERFORM lager._bewegt(p_subjekt, bid);
    RETURN bid;
END $$;

CREATE FUNCTION lager.umlagerung(p_subjekt text, p_charge uuid, p_von uuid, p_nach uuid, p_menge numeric)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE da numeric; bid uuid;
BEGIN
    da := lager._bestand_sperren(p_charge, p_von);
    IF NOT EXISTS (SELECT 1 FROM lager.ort WHERE id = p_nach AND NOT stillgelegt) OR p_von = p_nach THEN
        PERFORM lager._fehler('Ziel: ein anderer, nicht stillgelegter Lagerort erwartet', 'KM003');
    END IF;
    IF p_menge IS NULL OR p_menge <= 0 OR p_menge <> round(p_menge, 3) THEN
        PERFORM lager._fehler('Menge größer als null mit höchstens drei Nachkommastellen erwartet');
    END IF;
    IF p_menge > da THEN
        PERFORM lager._fehler(format('An diesem Lagerort sind nur %s verfügbar', lager._menge(da)), 'KM003');
    END IF;
    INSERT INTO lager.bewegung (art, charge, ort_von, ort_nach, menge, subjekt) VALUES ('umlagerung', p_charge, p_von, p_nach, p_menge, p_subjekt) RETURNING id INTO bid;
    PERFORM lager._bewegt(p_subjekt, bid);
    RETURN bid;
END $$;

-- Ausbuchen geht auch bei gesperrten und abgelaufenen Chargen – dafür ist es da.
CREATE FUNCTION lager.ausbuchung(p_subjekt text, p_charge uuid, p_ort uuid, p_menge numeric, p_grund text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE da numeric; bid uuid;
BEGIN
    da := lager._bestand_sperren(p_charge, p_ort);
    IF p_grund IS NULL OR p_grund NOT IN ('verfallen', 'beschaedigt', 'zurueckgegeben', 'vernichtet', 'berichtigung') THEN
        PERFORM lager._fehler('Grund: verfallen, beschaedigt, zurueckgegeben, vernichtet oder berichtigung erwartet');
    END IF;
    IF p_menge IS NULL OR p_menge <= 0 OR p_menge <> round(p_menge, 3) THEN
        PERFORM lager._fehler('Menge größer als null mit höchstens drei Nachkommastellen erwartet');
    END IF;
    IF p_menge > da THEN
        PERFORM lager._fehler(format('An diesem Lagerort sind nur %s verfügbar', lager._menge(da)), 'KM003');
    END IF;
    INSERT INTO lager.bewegung (art, charge, ort_von, menge, subjekt, grund) VALUES ('ausbuchung', p_charge, p_ort, p_menge, p_subjekt, p_grund) RETURNING id INTO bid;
    PERFORM lager._bewegt(p_subjekt, bid);
    RETURN bid;
END $$;

-- Sperren (p_grund gesetzt) oder freigeben (p_grund NULL).
CREATE FUNCTION lager.charge_sperren(p_subjekt text, p_charge uuid, p_grund text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE c lager.charge; g text := nullif(trim(coalesce(p_grund, '')), '');
BEGIN
    SELECT * INTO c FROM lager.charge WHERE id = p_charge FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM lager._fehler('Charge unbekannt', 'KM003');
    END IF;
    IF c.sperrgrund IS NOT DISTINCT FROM g THEN
        RETURN;
    END IF;
    UPDATE lager.charge SET gesperrt = g IS NOT NULL, sperrgrund = g WHERE id = p_charge;
    INSERT INTO lager.sperre (charge, subjekt, grund) VALUES (p_charge, p_subjekt, g);
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', CASE WHEN g IS NULL THEN 'lager.charge_freigegeben' ELSE 'lager.charge_gesperrt' END,
        jsonb_build_object('charge', p_charge::text, 'artikel', c.artikel::text),
        jsonb_strip_nulls(jsonb_build_object('grund', c.sperrgrund)), jsonb_strip_nulls(jsonb_build_object('grund', g)));
END $$;

CREATE FUNCTION lager.merken(p_subjekt text, p_lieferant text, p_nummer text, p_artikel uuid, p_faktor numeric)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
BEGIN
    IF p_artikel IS NOT NULL AND NOT EXISTS (SELECT 1 FROM lager.artikel WHERE id = p_artikel) THEN
        PERFORM lager._fehler('Artikel unbekannt', 'KM003');
    END IF;
    INSERT INTO lager.lieferantenartikel (lieferant, nummer, artikel, kein_lager, faktor)
    VALUES (lower(trim(p_lieferant)), lower(trim(p_nummer)), p_artikel, p_artikel IS NULL, coalesce(p_faktor, 1))
    ON CONFLICT (lieferant, nummer) DO UPDATE SET artikel = EXCLUDED.artikel, kein_lager = EXCLUDED.kein_lager, faktor = EXCLUDED.faktor, gemerkt_am = clock_timestamp();
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.zuordnung_gemerkt', jsonb_strip_nulls(jsonb_build_object('artikel', p_artikel::text)), NULL,
        jsonb_build_object('kein_lager', p_artikel IS NULL, 'faktor', coalesce(p_faktor, 1)::text));
END $$;

REVOKE ALL ON ALL TABLES IN SCHEMA lager FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON ALL TABLES IN SCHEMA lager TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA lager FROM PUBLIC;
GRANT EXECUTE ON FUNCTION lager.einstellung_setzen(text, integer, jsonb), lager.ort_setzen(text, uuid, text, boolean, boolean), lager.artikel_setzen(text, uuid, jsonb),
    lager.zugang(text, jsonb), lager.vorschlag(uuid), lager.entnahme(text, uuid, uuid, numeric, text), lager.umlagerung(text, uuid, uuid, uuid, numeric),
    lager.ausbuchung(text, uuid, uuid, numeric, text), lager.charge_sperren(text, uuid, text), lager.merken(text, text, text, uuid, numeric) TO kumero_app;
GRANT EXECUTE ON FUNCTION lager._menge(numeric), lager.vorschlag(uuid) TO kumero_app, kumero_lesen;
