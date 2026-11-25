-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul beschaffung, Migration 0001: Bestellanforderung (BESTELLANFORDERUNG.md, F217, F222)
--
-- Eine Anforderung hängt am Bedarf, nicht am Lieferanten (BA19). Der Einkauf hakt ihre Positionen ab (BA21) und
-- erfasst dabei, was dann feststeht (BA23). Eine Bestellung legt niemand an: Positionen mit demselben Lieferanten und
-- derselben Bestellnummer gehören zusammen (BA25). Nichts wird gebucht (F31). Artikel und Artikelgruppen des Lagers
-- stehen hier nur als Kennung mit einer Abschrift von Bezeichnung und Einheit – das Modul liest keine Tabelle des
-- Lagers (F44). Schreiben nur über Funktionen mit Protokoll; im Protokoll stehen IDs, Zustände und Mengen, keine Preise (BA11).

CREATE SCHEMA beschaffung AUTHORIZATION kumero_owner;
REVOKE ALL ON SCHEMA beschaffung FROM PUBLIC;
GRANT USAGE ON SCHEMA beschaffung TO kumero_app, kumero_lesen;

CREATE TABLE beschaffung.zaehler (
    jahr    integer PRIMARY KEY,
    letzte  integer NOT NULL CHECK (letzte > 0)
);

CREATE TABLE beschaffung.anforderung (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    nummer        text UNIQUE,                                                        -- BA-JJJJ-NNNN, vergeben mit „Anfordern“ (BA8)
    wofuer        text NOT NULL CHECK (length(trim(wofuer)) BETWEEN 1 AND 200),       -- ohne Personenbezug (WW14)
    termin        date,                                                               -- gewünscht bis
    zustand       text NOT NULL DEFAULT 'entwurf' CHECK (zustand IN ('entwurf', 'angefordert', 'freigegeben', 'abgelehnt', 'zurueckgezogen')),
    grund         text CHECK (grund IS NULL OR length(trim(grund)) BETWEEN 1 AND 300), -- der Ablehnung
    bei_subjekt   text,                                                               -- weitergeleitet an diese Person …
    bei_rolle     text,                                                               -- … oder an alle mit dieser Rolle (BA17)
    bei_wozu      text CHECK (bei_wozu IS NULL OR bei_wozu IN ('freigabe', 'rueckfrage', 'bestellen')),
    angelegt_von  text NOT NULL,
    angelegt_am   timestamptz NOT NULL DEFAULT clock_timestamp(),
    angefordert_am timestamptz,
    entschieden_von text,
    entschieden_am timestamptz,
    CHECK (bei_subjekt IS NULL OR bei_rolle IS NULL),
    CHECK ((bei_subjekt IS NULL AND bei_rolle IS NULL) = (bei_wozu IS NULL)),
    CHECK ((zustand = 'entwurf') = (nummer IS NULL))
);

CREATE TABLE beschaffung.position (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    anforderung   uuid NOT NULL REFERENCES beschaffung.anforderung ON DELETE CASCADE,
    nr            integer NOT NULL CHECK (nr > 0),
    art           text NOT NULL CHECK (art IN ('lager', 'frei')),                     -- fürs Lager | nicht fürs Lager (BA16)
    artikel       uuid,                                                               -- Artikel des Lagers (kein Fremdschlüssel, F44)
    meta          uuid,                                                               -- Artikelgruppe des Lagers: „einer davon“ (BA7)
    bezeichnung   text NOT NULL CHECK (length(trim(bezeichnung)) BETWEEN 1 AND 200),
    einheit       text NOT NULL CHECK (length(trim(einheit)) BETWEEN 1 AND 20),
    menge         numeric(15,3) NOT NULL CHECK (menge > 0),                           -- angefordert
    vermerk       text CHECK (vermerk IS NULL OR length(trim(vermerk)) BETWEEN 1 AND 200),
    zustand       text NOT NULL DEFAULT 'offen' CHECK (zustand IN ('offen', 'bestellt', 'erhalten', 'gestrichen')),
    grund         text CHECK (grund IS NULL OR length(trim(grund)) BETWEEN 1 AND 200), -- warum gestrichen
    bestellt_menge numeric(15,3) CHECK (bestellt_menge IS NULL OR bestellt_menge > 0), -- entscheidet der Einkäufer (BA27)
    lieferant     text CHECK (lieferant IS NULL OR length(trim(lieferant)) BETWEEN 1 AND 200),
    lieferant_nummer text CHECK (lieferant_nummer IS NULL OR length(trim(lieferant_nummer)) BETWEEN 1 AND 200),   -- Artikelnummer dort
    bestellnummer text CHECK (bestellnummer IS NULL OR length(trim(bestellnummer)) BETWEEN 1 AND 100),
    bestellt_am   date,
    liefertermin  date,
    preis         numeric(15,4) CHECK (preis IS NULL OR preis >= 0),                  -- je Einheit, netto wie im Shop angezeigt
    bestell_vermerk text CHECK (bestell_vermerk IS NULL OR length(trim(bestell_vermerk)) BETWEEN 1 AND 200),   -- z. B. „2 Packungen zu 50“ (BA28)
    bestellt_von  text,
    erhalten_menge numeric(15,3) NOT NULL DEFAULT 0 CHECK (erhalten_menge >= 0),
    geteilt_aus   uuid REFERENCES beschaffung.position,
    UNIQUE (anforderung, nr),
    CHECK (art = 'lager' OR (artikel IS NULL AND meta IS NULL)),
    CHECK (artikel IS NULL OR meta IS NULL),
    CHECK ((zustand IN ('bestellt', 'erhalten')) = (bestellt_menge IS NOT NULL)),
    CHECK (bestellt_menge IS NULL OR (lieferant IS NOT NULL AND bestellt_am IS NOT NULL AND bestellt_von IS NOT NULL))
);
CREATE INDEX position_bestellung ON beschaffung.position (lower(lieferant), bestellnummer) WHERE bestellt_menge IS NOT NULL;
CREATE INDEX position_artikel ON beschaffung.position (artikel) WHERE artikel IS NOT NULL;

-- Was von einer Position angekommen ist – von Hand gemeldet (BA16). Für Lagerpositionen ersetzt das der Wareneingang
-- aus der Bestellung (Stufe 2); bis dahin ist es die Anschlussstelle.
CREATE TABLE beschaffung.erhalt (
    id        uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    position  uuid NOT NULL REFERENCES beschaffung.position ON DELETE CASCADE,
    menge     numeric(15,3) NOT NULL CHECK (menge > 0),
    am        date NOT NULL,
    subjekt   text NOT NULL,
    zeit      timestamptz NOT NULL DEFAULT clock_timestamp()
);

-- Der Verlauf einer Anforderung: wer, wann, was, an wen (BA15). Nur anfügen.
CREATE TABLE beschaffung.verlauf (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    nr           bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    anforderung  uuid NOT NULL REFERENCES beschaffung.anforderung ON DELETE CASCADE,
    position     uuid REFERENCES beschaffung.position ON DELETE CASCADE,
    zeit         timestamptz NOT NULL DEFAULT clock_timestamp(),
    subjekt      text NOT NULL,
    art          text NOT NULL CHECK (art IN ('angefordert', 'weitergeleitet', 'uebernommen', 'freigegeben', 'abgelehnt', 'zurueckgezogen',
                                              'bestellt', 'zurueckgenommen', 'gestrichen', 'geteilt', 'erhalten')),
    an_subjekt   text,
    an_rolle     text,
    wozu         text,
    text         text CHECK (text IS NULL OR length(text) <= 300)
);
CREATE INDEX verlauf_anforderung ON beschaffung.verlauf (anforderung, nr);

CREATE FUNCTION beschaffung._fehler(p_text text, p_code text DEFAULT 'KM002') RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION USING ERRCODE = p_code, MESSAGE = p_text;
END $$;

CREATE FUNCTION beschaffung._menge(m numeric) RETURNS text LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN m = trunc(m) THEN trunc(m)::text ELSE rtrim(m::text, '0') END
$$;

-- Stand für das Protokoll: Zustände und Mengen, keine Preise, kein Lieferant (BA11)
CREATE FUNCTION beschaffung._stand(p_id uuid) RETURNS jsonb LANGUAGE sql STABLE AS $$
    SELECT jsonb_strip_nulls(jsonb_build_object('nummer', a.nummer, 'zustand', a.zustand, 'termin', a.termin::text, 'bei_subjekt', a.bei_subjekt, 'bei_rolle', a.bei_rolle,
               'positionen', coalesce((SELECT jsonb_agg(jsonb_strip_nulls(jsonb_build_object('position', p.id::text, 'nr', p.nr, 'art', p.art, 'artikel', p.artikel::text, 'meta', p.meta::text,
                       'menge', beschaffung._menge(p.menge), 'zustand', p.zustand, 'bestellt', beschaffung._menge(p.bestellt_menge), 'erhalten', beschaffung._menge(nullif(p.erhalten_menge, 0)))) ORDER BY p.nr)
                     FROM beschaffung.position p WHERE p.anforderung = a.id), '[]'::jsonb)))
      FROM beschaffung.anforderung a WHERE a.id = p_id
$$;

CREATE FUNCTION beschaffung._holen(p_id uuid) RETURNS beschaffung.anforderung LANGUAGE plpgsql AS $$
DECLARE a beschaffung.anforderung;
BEGIN
    SELECT * INTO a FROM beschaffung.anforderung WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM beschaffung._fehler('Anforderung unbekannt', 'KM003');
    END IF;
    RETURN a;
END $$;

-- Anlegen oder als Ganzes ersetzen. Ändern lässt sich eine Anforderung, bis sie freigegeben oder abgelehnt ist:
-- von dem, der sie angelegt hat – oder von jedem, wenn p_alle (wer freigeben darf; das prüft die Anwendung).
-- p: {wofuer, termin?, positionen: [{art, artikel?, meta?, bezeichnung, einheit, menge, vermerk?}]}
CREATE FUNCTION beschaffung.anforderung_setzen(p_subjekt text, p_id uuid, p jsonb, p_alle boolean DEFAULT false)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, beschaffung, pg_temp AS $$
DECLARE a beschaffung.anforderung; alt jsonb; z jsonb; n integer := 0; v_id uuid := p_id;
BEGIN
    IF jsonb_typeof(p->'positionen') IS DISTINCT FROM 'array' OR jsonb_array_length(p->'positionen') > 200 THEN
        PERFORM beschaffung._fehler('Positionen: eine Liste mit höchstens 200 Einträgen erwartet');
    END IF;
    IF p_id IS NULL THEN
        INSERT INTO beschaffung.anforderung (wofuer, termin, angelegt_von) VALUES (trim(p->>'wofuer'), (p->>'termin')::date, p_subjekt) RETURNING id INTO v_id;
    ELSE
        a := beschaffung._holen(p_id);
        IF a.zustand NOT IN ('entwurf', 'angefordert') THEN
            PERFORM beschaffung._fehler('Die Anforderung ist ' || CASE a.zustand WHEN 'freigegeben' THEN 'freigegeben' WHEN 'abgelehnt' THEN 'abgelehnt' ELSE 'zurückgezogen' END || ' und lässt sich nicht mehr ändern', 'KM003');
        END IF;
        IF a.angelegt_von <> p_subjekt AND NOT p_alle THEN
            PERFORM beschaffung._fehler('Ändern kann die Anforderung, wer sie angelegt hat oder wer freigeben darf', 'KM003');
        END IF;
        alt := beschaffung._stand(p_id);
        UPDATE beschaffung.anforderung SET wofuer = trim(p->>'wofuer'), termin = (p->>'termin')::date WHERE id = p_id;
        DELETE FROM beschaffung.position WHERE anforderung = p_id;
    END IF;
    FOR z IN SELECT * FROM jsonb_array_elements(p->'positionen') LOOP
        n := n + 1;
        INSERT INTO beschaffung.position (anforderung, nr, art, artikel, meta, bezeichnung, einheit, menge, vermerk)
        VALUES (v_id, n, z->>'art', (z->>'artikel')::uuid, (z->>'meta')::uuid, trim(z->>'bezeichnung'), trim(z->>'einheit'), (z->>'menge')::numeric, nullif(trim(coalesce(z->>'vermerk', '')), ''));
    END LOOP;
    IF a.zustand = 'angefordert' AND n = 0 THEN
        PERFORM beschaffung._fehler('Eine angeforderte Anforderung braucht mindestens eine Position – sonst zurückziehen', 'KM003');
    END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'beschaffung', CASE WHEN p_id IS NULL THEN 'beschaffung.anforderung_angelegt' ELSE 'beschaffung.anforderung_geaendert' END,
        jsonb_build_object('anforderung', v_id::text), alt, beschaffung._stand(v_id));
    RETURN v_id;
END $$;

-- Entwurf → angefordert; vergibt die Nummer (fortlaufend je Jahr, nie neu vergeben – BA8)
CREATE FUNCTION beschaffung.anfordern(p_subjekt text, p_id uuid)
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, beschaffung, pg_temp AS $$
DECLARE a beschaffung.anforderung; alt jsonb; v_jahr integer := extract(year FROM current_date)::integer; v_n integer; v_nummer text;
BEGIN
    a := beschaffung._holen(p_id);
    IF a.zustand <> 'entwurf' THEN
        PERFORM beschaffung._fehler('Die Anforderung ist schon angefordert', 'KM003');
    END IF;
    IF a.angelegt_von <> p_subjekt THEN
        PERFORM beschaffung._fehler('Anfordern kann den Entwurf nur, wer ihn angelegt hat', 'KM003');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM beschaffung.position WHERE anforderung = p_id) THEN
        PERFORM beschaffung._fehler('Die Anforderung hat noch keine Position', 'KM003');
    END IF;
    alt := beschaffung._stand(p_id);
    INSERT INTO beschaffung.zaehler (jahr, letzte) VALUES (v_jahr, 1) ON CONFLICT (jahr) DO UPDATE SET letzte = beschaffung.zaehler.letzte + 1 RETURNING letzte INTO v_n;
    v_nummer := format('BA-%s-%s', v_jahr, lpad(v_n::text, 4, '0'));
    UPDATE beschaffung.anforderung SET zustand = 'angefordert', nummer = v_nummer, angefordert_am = clock_timestamp() WHERE id = p_id;
    INSERT INTO beschaffung.verlauf (anforderung, subjekt, art) VALUES (p_id, p_subjekt, 'angefordert');
    PERFORM kern.modul_protokoll(p_subjekt, 'beschaffung', 'beschaffung.angefordert', jsonb_build_object('anforderung', p_id::text), alt, beschaffung._stand(p_id));
    RETURN v_nummer;
END $$;

-- Ein Entwurf wird gelöscht (er war nie angefordert), eine angeforderte Anforderung zurückgezogen und bleibt stehen.
CREATE FUNCTION beschaffung.zurueckziehen(p_subjekt text, p_id uuid, p_alle boolean DEFAULT false)
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, beschaffung, pg_temp AS $$
DECLARE a beschaffung.anforderung; alt jsonb;
BEGIN
    a := beschaffung._holen(p_id);
    IF a.angelegt_von <> p_subjekt AND NOT p_alle THEN
        PERFORM beschaffung._fehler('Zurückziehen kann die Anforderung, wer sie angelegt hat oder wer freigeben darf', 'KM003');
    END IF;
    alt := beschaffung._stand(p_id);
    IF a.zustand = 'entwurf' THEN
        DELETE FROM beschaffung.anforderung WHERE id = p_id;
        PERFORM kern.modul_protokoll(p_subjekt, 'beschaffung', 'beschaffung.entwurf_geloescht', jsonb_build_object('anforderung', p_id::text), alt, NULL);
        RETURN 'geloescht';
    ELSIF a.zustand <> 'angefordert' THEN
        PERFORM beschaffung._fehler('Zurückziehen lässt sich eine Anforderung nur, solange sie nicht freigegeben oder abgelehnt ist', 'KM003');
    END IF;
    UPDATE beschaffung.anforderung SET zustand = 'zurueckgezogen', bei_subjekt = NULL, bei_rolle = NULL, bei_wozu = NULL WHERE id = p_id;
    INSERT INTO beschaffung.verlauf (anforderung, subjekt, art) VALUES (p_id, p_subjekt, 'zurueckgezogen');
    PERFORM kern.modul_protokoll(p_subjekt, 'beschaffung', 'beschaffung.zurueckgezogen', jsonb_build_object('anforderung', p_id::text), alt, beschaffung._stand(p_id));
    RETURN 'zurueckgezogen';
END $$;

-- Freigeben (p_grund NULL) oder ablehnen (mit Grund)
CREATE FUNCTION beschaffung.entscheiden(p_subjekt text, p_id uuid, p_grund text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, beschaffung, pg_temp AS $$
DECLARE a beschaffung.anforderung; alt jsonb; g text := nullif(trim(coalesce(p_grund, '')), '');
BEGIN
    a := beschaffung._holen(p_id);
    IF a.zustand <> 'angefordert' THEN
        PERFORM beschaffung._fehler(CASE a.zustand WHEN 'entwurf' THEN 'Die Anforderung ist noch ein Entwurf – erst anfordern' ELSE 'Über die Anforderung ist schon entschieden' END, 'KM003');
    END IF;
    alt := beschaffung._stand(p_id);
    UPDATE beschaffung.anforderung SET zustand = CASE WHEN g IS NULL THEN 'freigegeben' ELSE 'abgelehnt' END, grund = g, entschieden_von = p_subjekt, entschieden_am = clock_timestamp(),
           bei_subjekt = NULL, bei_rolle = NULL, bei_wozu = NULL WHERE id = p_id;
    INSERT INTO beschaffung.verlauf (anforderung, subjekt, art, text) VALUES (p_id, p_subjekt, CASE WHEN g IS NULL THEN 'freigegeben' ELSE 'abgelehnt' END, g);
    PERFORM kern.modul_protokoll(p_subjekt, 'beschaffung', CASE WHEN g IS NULL THEN 'beschaffung.freigegeben' ELSE 'beschaffung.abgelehnt' END,
        jsonb_build_object('anforderung', p_id::text), alt, beschaffung._stand(p_id));
END $$;

-- Weiterleiten an eine Person oder an eine Rolle (BA15, BA17). Ob es Person und Rolle gibt, prüft die Anwendung.
CREATE FUNCTION beschaffung.weiterleiten(p_subjekt text, p_id uuid, p_an_subjekt text, p_an_rolle text, p_wozu text, p_text text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, beschaffung, pg_temp AS $$
DECLARE a beschaffung.anforderung; alt jsonb;
BEGIN
    a := beschaffung._holen(p_id);
    IF (p_an_subjekt IS NULL) = (p_an_rolle IS NULL) THEN
        PERFORM beschaffung._fehler('Weiterleiten: genau eine Person oder eine Rolle erwartet');
    END IF;
    IF p_wozu IS NULL OR p_wozu NOT IN ('freigabe', 'rueckfrage', 'bestellen') THEN
        PERFORM beschaffung._fehler('Weiterleiten: wozu – zur Freigabe, zur Rückfrage oder zum Bestellen');
    END IF;
    IF a.zustand NOT IN ('angefordert', 'freigegeben') THEN
        PERFORM beschaffung._fehler(CASE a.zustand WHEN 'entwurf' THEN 'Ein Entwurf lässt sich nicht weiterleiten – erst anfordern' ELSE 'Die Anforderung ist abgeschlossen und lässt sich nicht mehr weiterleiten' END, 'KM003');
    END IF;
    IF p_wozu = 'freigabe' AND a.zustand <> 'angefordert' THEN
        PERFORM beschaffung._fehler('Die Anforderung ist schon freigegeben', 'KM003');
    END IF;
    IF p_wozu = 'bestellen' AND a.zustand <> 'freigegeben' THEN
        PERFORM beschaffung._fehler('Zum Bestellen weiterleiten lässt sich die Anforderung erst nach der Freigabe', 'KM003');
    END IF;
    alt := beschaffung._stand(p_id);
    UPDATE beschaffung.anforderung SET bei_subjekt = p_an_subjekt, bei_rolle = p_an_rolle, bei_wozu = p_wozu WHERE id = p_id;
    INSERT INTO beschaffung.verlauf (anforderung, subjekt, art, an_subjekt, an_rolle, wozu, text)
    VALUES (p_id, p_subjekt, 'weitergeleitet', p_an_subjekt, p_an_rolle, p_wozu, nullif(trim(coalesce(p_text, '')), ''));
    PERFORM kern.modul_protokoll(p_subjekt, 'beschaffung', 'beschaffung.weitergeleitet', jsonb_build_object('anforderung', p_id::text), alt, beschaffung._stand(p_id));
END $$;

-- Wer eine an seine Rolle weitergeleitete Anforderung übernimmt, hat sie (BA17). Dass er die Rolle hat, prüft die Anwendung.
CREATE FUNCTION beschaffung.uebernehmen(p_subjekt text, p_id uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, beschaffung, pg_temp AS $$
DECLARE a beschaffung.anforderung; alt jsonb;
BEGIN
    a := beschaffung._holen(p_id);
    IF a.bei_rolle IS NULL THEN
        PERFORM beschaffung._fehler('Die Anforderung liegt bei keiner Rolle – vielleicht hat sie schon jemand übernommen', 'KM003');
    END IF;
    alt := beschaffung._stand(p_id);
    UPDATE beschaffung.anforderung SET bei_subjekt = p_subjekt, bei_rolle = NULL WHERE id = p_id;
    INSERT INTO beschaffung.verlauf (anforderung, subjekt, art, an_rolle) VALUES (p_id, p_subjekt, 'uebernommen', a.bei_rolle);
    PERFORM kern.modul_protokoll(p_subjekt, 'beschaffung', 'beschaffung.uebernommen', jsonb_build_object('anforderung', p_id::text), alt, beschaffung._stand(p_id));
END $$;

CREATE FUNCTION beschaffung._position(p_id uuid) RETURNS beschaffung.position LANGUAGE plpgsql AS $$
DECLARE p beschaffung.position;
BEGIN
    SELECT * INTO p FROM beschaffung.position WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM beschaffung._fehler('Position unbekannt', 'KM003');
    END IF;
    RETURN p;
END $$;

-- Eine Position als bestellt abhaken (BA21, BA23, BA27).
-- p: {menge, lieferant, lieferant_nummer?, bestellnummer?, bestellt_am, liefertermin?, preis?, vermerk?, artikel?, bezeichnung?, einheit?, rest?}
--    artikel: der konkrete Artikel, wenn die Position eine Artikelgruppe oder noch keinen Artikel nennt (BA12)
--    rest: Menge, die als neue offene Position stehen bleibt (der Einkäufer teilt, BA27)
CREATE FUNCTION beschaffung.bestellen(p_subjekt text, p_position uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, beschaffung, pg_temp AS $$
DECLARE x beschaffung.position; a beschaffung.anforderung; alt jsonb; v_rest uuid; v_r numeric := (p->>'rest')::numeric; v_art uuid := (p->>'artikel')::uuid;
BEGIN
    x := beschaffung._position(p_position);
    a := beschaffung._holen(x.anforderung);
    IF a.zustand <> 'freigegeben' THEN
        PERFORM beschaffung._fehler('Bestellen lässt sich erst, was freigegeben ist', 'KM003');
    END IF;
    IF x.zustand <> 'offen' THEN
        PERFORM beschaffung._fehler(CASE x.zustand WHEN 'gestrichen' THEN 'Die Position ist gestrichen' ELSE 'Die Position ist schon bestellt' END, 'KM003');
    END IF;
    IF x.art = 'lager' AND coalesce(v_art, x.artikel) IS NULL THEN
        PERFORM beschaffung._fehler('Zu dieser Position fehlt der Artikel – spätestens beim Bestellen wird er gewählt oder angelegt', 'KM003');
    END IF;
    IF x.art = 'frei' AND v_art IS NOT NULL THEN
        PERFORM beschaffung._fehler('Eine Position „nicht fürs Lager“ hat keinen Artikel');
    END IF;
    alt := beschaffung._stand(a.id);
    UPDATE beschaffung.position SET zustand = 'bestellt', bestellt_menge = (p->>'menge')::numeric, lieferant = trim(p->>'lieferant'),
           lieferant_nummer = nullif(trim(coalesce(p->>'lieferant_nummer', '')), ''), bestellnummer = nullif(trim(coalesce(p->>'bestellnummer', '')), ''),
           bestellt_am = (p->>'bestellt_am')::date, liefertermin = (p->>'liefertermin')::date, preis = (p->>'preis')::numeric,
           bestell_vermerk = nullif(trim(coalesce(p->>'vermerk', '')), ''), bestellt_von = p_subjekt,
           artikel = coalesce(v_art, artikel), meta = CASE WHEN v_art IS NULL THEN meta ELSE NULL END,
           bezeichnung = coalesce(nullif(trim(coalesce(p->>'bezeichnung', '')), ''), bezeichnung), einheit = coalesce(nullif(trim(coalesce(p->>'einheit', '')), ''), einheit)
     WHERE id = x.id;
    INSERT INTO beschaffung.verlauf (anforderung, position, subjekt, art, text)
    VALUES (a.id, x.id, p_subjekt, 'bestellt', CASE WHEN (p->>'menge')::numeric <> x.menge THEN format('%s statt %s %s', beschaffung._menge((p->>'menge')::numeric), beschaffung._menge(x.menge), x.einheit) END);
    IF v_r IS NOT NULL THEN
        IF v_r <= 0 THEN
            PERFORM beschaffung._fehler('Rest: Menge größer als null erwartet');
        END IF;
        INSERT INTO beschaffung.position (anforderung, nr, art, artikel, meta, bezeichnung, einheit, menge, vermerk, geteilt_aus)
        VALUES (a.id, (SELECT max(nr) + 1 FROM beschaffung.position WHERE anforderung = a.id), x.art, x.artikel, x.meta, x.bezeichnung, x.einheit, v_r, x.vermerk, x.id)
        RETURNING id INTO v_rest;
        INSERT INTO beschaffung.verlauf (anforderung, position, subjekt, art, text) VALUES (a.id, v_rest, p_subjekt, 'geteilt', format('Rest %s %s bleibt offen', beschaffung._menge(v_r), x.einheit));
    END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'beschaffung', 'beschaffung.bestellt', jsonb_build_object('anforderung', a.id::text, 'position', x.id::text), alt, beschaffung._stand(a.id));
    RETURN v_rest;
END $$;

-- Ein falsch gesetzter Haken lässt sich zurücknehmen, solange nichts erhalten ist.
CREATE FUNCTION beschaffung.bestellung_zuruecknehmen(p_subjekt text, p_position uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, beschaffung, pg_temp AS $$
DECLARE x beschaffung.position; alt jsonb;
BEGIN
    x := beschaffung._position(p_position);
    PERFORM beschaffung._holen(x.anforderung);
    IF x.zustand <> 'bestellt' OR x.erhalten_menge > 0 THEN
        PERFORM beschaffung._fehler(CASE WHEN x.zustand = 'offen' THEN 'Die Position ist nicht bestellt' WHEN x.zustand = 'gestrichen' THEN 'Die Position ist gestrichen' ELSE 'Zu der Position ist schon etwas als erhalten gemeldet' END, 'KM003');
    END IF;
    alt := beschaffung._stand(x.anforderung);
    UPDATE beschaffung.position SET zustand = 'offen', bestellt_menge = NULL, lieferant = NULL, lieferant_nummer = NULL, bestellnummer = NULL, bestellt_am = NULL, liefertermin = NULL,
           preis = NULL, bestell_vermerk = NULL, bestellt_von = NULL WHERE id = x.id;
    INSERT INTO beschaffung.verlauf (anforderung, position, subjekt, art) VALUES (x.anforderung, x.id, p_subjekt, 'zurueckgenommen');
    PERFORM kern.modul_protokoll(p_subjekt, 'beschaffung', 'beschaffung.bestellung_zurueckgenommen', jsonb_build_object('anforderung', x.anforderung::text, 'position', x.id::text), alt, beschaffung._stand(x.anforderung));
END $$;

-- Eine offene Position wird nicht bestellt (der Einkäufer entscheidet über das Volumen, BA27) – mit Grund.
CREATE FUNCTION beschaffung.position_streichen(p_subjekt text, p_position uuid, p_grund text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, beschaffung, pg_temp AS $$
DECLARE x beschaffung.position; a beschaffung.anforderung; alt jsonb; g text := nullif(trim(coalesce(p_grund, '')), '');
BEGIN
    x := beschaffung._position(p_position);
    a := beschaffung._holen(x.anforderung);
    IF g IS NULL THEN
        PERFORM beschaffung._fehler('Streichen braucht einen Grund');
    END IF;
    IF a.zustand <> 'freigegeben' OR x.zustand <> 'offen' THEN
        PERFORM beschaffung._fehler(CASE WHEN a.zustand <> 'freigegeben' THEN 'Streichen lässt sich eine Position erst nach der Freigabe – vorher ändert man die Anforderung' ELSE 'Die Position ist nicht mehr offen' END, 'KM003');
    END IF;
    alt := beschaffung._stand(a.id);
    UPDATE beschaffung.position SET zustand = 'gestrichen', grund = g WHERE id = x.id;
    INSERT INTO beschaffung.verlauf (anforderung, position, subjekt, art, text) VALUES (a.id, x.id, p_subjekt, 'gestrichen', g);
    PERFORM kern.modul_protokoll(p_subjekt, 'beschaffung', 'beschaffung.position_gestrichen', jsonb_build_object('anforderung', a.id::text, 'position', x.id::text), alt, beschaffung._stand(a.id));
END $$;

-- Von Hand als erhalten melden: ganz oder in Teilmengen (BA16). p_fertig: Es kommt nichts mehr – die Position ist
-- erledigt, auch wenn weniger ankam als bestellt.
CREATE FUNCTION beschaffung.erhalten(p_subjekt text, p_position uuid, p_menge numeric, p_am date, p_fertig boolean DEFAULT false)
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, beschaffung, pg_temp AS $$
DECLARE x beschaffung.position; alt jsonb; v_summe numeric; v_zustand text;
BEGIN
    x := beschaffung._position(p_position);
    PERFORM beschaffung._holen(x.anforderung);
    IF x.zustand <> 'bestellt' THEN
        PERFORM beschaffung._fehler(CASE x.zustand WHEN 'erhalten' THEN 'Die Position ist schon ganz erhalten' ELSE 'Erhalten lässt sich nur melden, was bestellt ist' END, 'KM003');
    END IF;
    IF p_menge IS NULL AND NOT p_fertig THEN
        PERFORM beschaffung._fehler('Menge erwartet');
    END IF;
    IF p_menge IS NOT NULL AND p_menge <= 0 THEN
        PERFORM beschaffung._fehler('Menge größer als null erwartet');
    END IF;
    IF p_am IS NULL OR p_am > current_date THEN
        PERFORM beschaffung._fehler('Das Datum des Eingangs liegt nicht in der Zukunft');
    END IF;
    alt := beschaffung._stand(x.anforderung);
    IF p_menge IS NOT NULL THEN
        INSERT INTO beschaffung.erhalt (position, menge, am, subjekt) VALUES (x.id, p_menge, p_am, p_subjekt);
    END IF;
    v_summe := x.erhalten_menge + coalesce(p_menge, 0);
    v_zustand := CASE WHEN p_fertig OR v_summe >= x.bestellt_menge THEN 'erhalten' ELSE 'bestellt' END;
    UPDATE beschaffung.position SET erhalten_menge = v_summe, zustand = v_zustand WHERE id = x.id;
    INSERT INTO beschaffung.verlauf (anforderung, position, subjekt, art, text)
    VALUES (x.anforderung, x.id, p_subjekt, 'erhalten', concat_ws(' – ', CASE WHEN p_menge IS NOT NULL THEN format('%s %s am %s', beschaffung._menge(p_menge), x.einheit, to_char(p_am, 'DD.MM.YYYY')) END,
            CASE WHEN p_fertig AND v_summe < x.bestellt_menge THEN format('abgeschlossen mit %s von %s', beschaffung._menge(v_summe), beschaffung._menge(x.bestellt_menge)) END));
    PERFORM kern.modul_protokoll(p_subjekt, 'beschaffung', 'beschaffung.erhalten', jsonb_build_object('anforderung', x.anforderung::text, 'position', x.id::text), alt, beschaffung._stand(x.anforderung));
    RETURN v_zustand;
END $$;

REVOKE ALL ON ALL TABLES IN SCHEMA beschaffung FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON ALL TABLES IN SCHEMA beschaffung TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA beschaffung FROM PUBLIC;
GRANT EXECUTE ON FUNCTION beschaffung.anforderung_setzen(text, uuid, jsonb, boolean), beschaffung.anfordern(text, uuid), beschaffung.zurueckziehen(text, uuid, boolean),
    beschaffung.entscheiden(text, uuid, text), beschaffung.weiterleiten(text, uuid, text, text, text, text), beschaffung.uebernehmen(text, uuid),
    beschaffung.bestellen(text, uuid, jsonb), beschaffung.bestellung_zuruecknehmen(text, uuid), beschaffung.position_streichen(text, uuid, text),
    beschaffung.erhalten(text, uuid, numeric, date, boolean) TO kumero_app;
GRANT EXECUTE ON FUNCTION beschaffung._menge(numeric) TO kumero_app, kumero_lesen;
