-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul anlagen, Migration 0001: Anlagegüter eines Buchs (ANLAGEN.md, F61, F129)
--
-- Eigene Daten im eigenen Schema (F44). Das Journal bleibt maßgeblich: Hier steht, was es an Anlagegütern gibt und wie
-- sie abgesetzt werden; gebucht wird über Entwürfe im Kern, die ein Mensch festschreibt (F31). Welche Buchung zu
-- welchem Gut und Jahr gehört, vermerkt das Modul – es liest keine Tabelle des Kerns.
-- Schreiben nur über Funktionen, mit Protokoll samt Vorher-/Nachher-Stand (F48, F64): Eine geänderte Angabe
-- überschreibt nichts unsichtbar.

CREATE SCHEMA anlagen AUTHORIZATION kumero_owner;
REVOKE ALL ON SCHEMA anlagen FROM PUBLIC;
GRANT USAGE ON SCHEMA anlagen TO kumero_app, kumero_lesen;

-- Einstellungen des Buchs (eine Zeile). vorsteuerabzug NULL heißt: noch nicht festgelegt.
CREATE TABLE anlagen.einstellung (
    einzig          boolean PRIMARY KEY DEFAULT true CHECK (einzig),
    vorsteuerabzug  boolean,                                                         -- G7: bestimmt netto oder brutto
    restwert        text NOT NULL DEFAULT 'null' CHECK (restwert IN ('null', 'erinnerung')),   -- G10: Vorgabe für neue Güter
    anlagekonten    text NOT NULL DEFAULT '' CHECK (length(anlagekonten) <= 500),    -- z. B. „0100-0899“
    geaendert_am    timestamptz NOT NULL DEFAULT now(),
    geaendert_von   text
);
INSERT INTO anlagen.einstellung DEFAULT VALUES;

CREATE TABLE anlagen.gut (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    inventarnr      text UNIQUE CHECK (inventarnr IS NULL OR length(trim(inventarnr)) BETWEEN 1 AND 40),
    bezeichnung     text NOT NULL CHECK (length(trim(bezeichnung)) BETWEEN 1 AND 200),
    zugang          date NOT NULL,
    kosten          numeric(15,2) NOT NULL CHECK (kosten > 0),                       -- Anschaffungs- oder Herstellungskosten
    netto           numeric(15,2) CHECK (netto IS NULL OR netto > 0),                -- für die Grenzen, wenn die Kosten brutto sind
    art             text NOT NULL CHECK (art IN ('linear', 'gwg', 'sammelposten', 'keine')),
    nutzungsdauer   integer CHECK (nutzungsdauer BETWEEN 1 AND 100),                 -- Jahre, bei linear
    nd_vorschlag    integer CHECK (nd_vorschlag BETWEEN 1 AND 100),                  -- aus der AfA-Tabelle, falls gewählt
    nd_quelle       text CHECK (nd_quelle IS NULL OR length(nd_quelle) <= 200),      -- Bezeichnung und Fundstelle des Vorschlags
    nd_begruendung  text CHECK (nd_begruendung IS NULL OR length(nd_begruendung) <= 500),  -- bei Abweichung, freiwillig
    restwert        text NOT NULL DEFAULT 'null' CHECK (restwert IN ('null', 'erinnerung')),
    bestandskonto   text NOT NULL CHECK (length(bestandskonto) BETWEEN 1 AND 20),
    aufwandskonto   text CHECK (aufwandskonto IS NULL OR length(aufwandskonto) BETWEEN 1 AND 20),
    buchung         uuid,                                                            -- Buchung der Anschaffung (kein Verweis in den Kern, F44)
    uebernahme      date,                                                            -- Bestand von vor kumero: Stichtag
    bisherige_afa   numeric(15,2) NOT NULL DEFAULT 0 CHECK (bisherige_afa >= 0),
    abgang          date,
    abgang_art      text CHECK (abgang_art IS NULL OR abgang_art IN ('verkauf', 'verschrottung', 'entnahme', 'sonstiges')),
    standort        text CHECK (standort IS NULL OR length(standort) <= 100),
    bemerkung       text CHECK (bemerkung IS NULL OR length(bemerkung) <= 1000),
    angelegt_am     timestamptz NOT NULL DEFAULT now(),
    geaendert_am    timestamptz NOT NULL DEFAULT now(),
    geaendert_von   text NOT NULL,
    CHECK (art <> 'linear' OR nutzungsdauer IS NOT NULL),
    CHECK (art = 'keine' OR aufwandskonto IS NOT NULL),
    CHECK (abgang IS NULL OR abgang >= zugang),
    CHECK ((abgang IS NULL) = (abgang_art IS NULL)),
    CHECK (uebernahme IS NULL OR uebernahme > zugang),
    CHECK (bisherige_afa <= kosten),
    CHECK (uebernahme IS NOT NULL OR bisherige_afa = 0)
);

-- Welche Buchung die AfA (oder den Rest beim Abgang) eines Guts für ein Geschäftsjahr trägt. Ob die Buchung noch
-- Entwurf ist, festgeschrieben oder gelöscht, weiß der Kern; die Seite fragt ihn.
CREATE TABLE anlagen.afa (
    gut           uuid NOT NULL REFERENCES anlagen.gut,
    jahr          text NOT NULL CHECK (length(jahr) BETWEEN 1 AND 20),               -- Kennung des Geschäftsjahres
    buchung       uuid NOT NULL,
    zweck         text NOT NULL DEFAULT 'afa' CHECK (zweck IN ('afa', 'abgang')),
    betrag        numeric(15,2) NOT NULL CHECK (betrag > 0),
    vermerkt_am   timestamptz NOT NULL DEFAULT now(),
    vermerkt_von  text NOT NULL,
    PRIMARY KEY (gut, jahr, buchung)
);

CREATE FUNCTION anlagen.einstellung_setzen(p_subjekt text, p_vorsteuerabzug boolean, p_restwert text, p_anlagekonten text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, anlagen, pg_temp AS $$
DECLARE alt jsonb; neu jsonb;
BEGIN
    SELECT to_jsonb(e) - 'einzig' - 'geaendert_am' - 'geaendert_von' INTO alt FROM anlagen.einstellung e;
    UPDATE anlagen.einstellung SET vorsteuerabzug = p_vorsteuerabzug, restwert = p_restwert, anlagekonten = coalesce(trim(p_anlagekonten), ''),
           geaendert_am = clock_timestamp(), geaendert_von = p_subjekt;
    SELECT to_jsonb(e) - 'einzig' - 'geaendert_am' - 'geaendert_von' INTO neu FROM anlagen.einstellung e;
    PERFORM kern.modul_protokoll(p_subjekt, 'anlagen', 'anlagen.einstellung_geaendert', '{}'::jsonb, alt, neu);
END $$;

CREATE FUNCTION anlagen._stand(g anlagen.gut) RETURNS jsonb
LANGUAGE sql STABLE AS $$
    -- Beträge als Text: die Kette nimmt keine Dezimalzahlen (F29)
    SELECT to_jsonb(g) - 'id' - 'angelegt_am' - 'geaendert_am' - 'geaendert_von'
           || jsonb_build_object('kosten', g.kosten::text, 'netto', g.netto::text, 'bisherige_afa', g.bisherige_afa::text)
$$;

-- Legt ein Anlagegut an (p_id NULL) oder ändert es. Ein Abgang wird nur über gut_abgang gesetzt.
CREATE FUNCTION anlagen.gut_setzen(p_subjekt text, p_id uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, anlagen, pg_temp AS $$
DECLARE alt anlagen.gut; neu anlagen.gut;
BEGIN
    IF p_id IS NULL THEN
        INSERT INTO anlagen.gut (inventarnr, bezeichnung, zugang, kosten, netto, art, nutzungsdauer, nd_vorschlag, nd_quelle, nd_begruendung, restwert,
                                 bestandskonto, aufwandskonto, buchung, uebernahme, bisherige_afa, standort, bemerkung, geaendert_von)
        VALUES (nullif(trim(p->>'inventarnr'), ''), trim(p->>'bezeichnung'), (p->>'zugang')::date, (p->>'kosten')::numeric, (p->>'netto')::numeric, p->>'art',
                (p->>'nutzungsdauer')::integer, (p->>'nd_vorschlag')::integer, nullif(p->>'nd_quelle', ''), nullif(trim(p->>'nd_begruendung'), ''), coalesce(p->>'restwert', 'null'),
                p->>'bestandskonto', nullif(p->>'aufwandskonto', ''), (p->>'buchung')::uuid, (p->>'uebernahme')::date, coalesce((p->>'bisherige_afa')::numeric, 0),
                nullif(trim(p->>'standort'), ''), nullif(trim(p->>'bemerkung'), ''), p_subjekt)
        RETURNING * INTO neu;
        PERFORM kern.modul_protokoll(p_subjekt, 'anlagen', 'anlagen.gut_angelegt', jsonb_build_object('gut', neu.id::text), NULL, anlagen._stand(neu));
        RETURN neu.id;
    END IF;
    SELECT * INTO alt FROM anlagen.gut WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN
        RAISE EXCEPTION USING ERRCODE = 'KM003', MESSAGE = 'Anlagegut unbekannt';
    END IF;
    UPDATE anlagen.gut SET inventarnr = nullif(trim(p->>'inventarnr'), ''), bezeichnung = trim(p->>'bezeichnung'), zugang = (p->>'zugang')::date,
           kosten = (p->>'kosten')::numeric, netto = (p->>'netto')::numeric, art = p->>'art', nutzungsdauer = (p->>'nutzungsdauer')::integer,
           nd_vorschlag = (p->>'nd_vorschlag')::integer, nd_quelle = nullif(p->>'nd_quelle', ''), nd_begruendung = nullif(trim(p->>'nd_begruendung'), ''),
           restwert = coalesce(p->>'restwert', 'null'), bestandskonto = p->>'bestandskonto', aufwandskonto = nullif(p->>'aufwandskonto', ''),
           buchung = (p->>'buchung')::uuid, uebernahme = (p->>'uebernahme')::date, bisherige_afa = coalesce((p->>'bisherige_afa')::numeric, 0),
           standort = nullif(trim(p->>'standort'), ''), bemerkung = nullif(trim(p->>'bemerkung'), ''),
           geaendert_am = clock_timestamp(), geaendert_von = p_subjekt
     WHERE id = p_id RETURNING * INTO neu;
    PERFORM kern.modul_protokoll(p_subjekt, 'anlagen', 'anlagen.gut_geaendert', jsonb_build_object('gut', p_id::text), anlagen._stand(alt), anlagen._stand(neu));
    RETURN p_id;
END $$;

-- Abgang setzen (p_datum und p_art) oder zurücknehmen (beide NULL).
CREATE FUNCTION anlagen.gut_abgang(p_subjekt text, p_id uuid, p_datum date, p_art text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, anlagen, pg_temp AS $$
DECLARE alt anlagen.gut; neu anlagen.gut;
BEGIN
    SELECT * INTO alt FROM anlagen.gut WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN
        RAISE EXCEPTION USING ERRCODE = 'KM003', MESSAGE = 'Anlagegut unbekannt';
    END IF;
    IF p_datum IS NOT NULL AND p_datum < alt.zugang THEN
        RAISE EXCEPTION USING ERRCODE = 'KM002', MESSAGE = 'Der Abgang liegt vor dem Zugang';
    END IF;
    UPDATE anlagen.gut SET abgang = p_datum, abgang_art = p_art, geaendert_am = clock_timestamp(), geaendert_von = p_subjekt WHERE id = p_id RETURNING * INTO neu;
    PERFORM kern.modul_protokoll(p_subjekt, 'anlagen', CASE WHEN p_datum IS NULL THEN 'anlagen.abgang_zurueckgenommen' ELSE 'anlagen.gut_abgegangen' END,
        jsonb_build_object('gut', p_id::text), anlagen._stand(alt), anlagen._stand(neu));
END $$;

CREATE FUNCTION anlagen.afa_vermerken(p_subjekt text, p_gut uuid, p_jahr text, p_buchung uuid, p_zweck text, p_betrag numeric)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, anlagen, pg_temp AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM anlagen.gut WHERE id = p_gut) THEN
        RAISE EXCEPTION USING ERRCODE = 'KM003', MESSAGE = 'Anlagegut unbekannt';
    END IF;
    INSERT INTO anlagen.afa (gut, jahr, buchung, zweck, betrag, vermerkt_von) VALUES (p_gut, p_jahr, p_buchung, p_zweck, p_betrag, p_subjekt)
        ON CONFLICT (gut, jahr, buchung) DO NOTHING;
    PERFORM kern.modul_protokoll(p_subjekt, 'anlagen', 'anlagen.afa_vermerkt',
        jsonb_build_object('gut', p_gut::text, 'jahr', p_jahr, 'buchung', p_buchung::text), NULL, jsonb_build_object('zweck', p_zweck, 'betrag', p_betrag::text));
END $$;

-- Eine Buchung, die es im Kern nicht mehr gibt (gelöschter Entwurf), trägt nichts mehr.
CREATE FUNCTION anlagen.afa_entfernen(p_subjekt text, p_gut uuid, p_jahr text, p_buchung uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, anlagen, pg_temp AS $$
BEGIN
    DELETE FROM anlagen.afa WHERE gut = p_gut AND jahr = p_jahr AND buchung = p_buchung;
    IF FOUND THEN
        PERFORM kern.modul_protokoll(p_subjekt, 'anlagen', 'anlagen.afa_entfernt',
            jsonb_build_object('gut', p_gut::text, 'jahr', p_jahr, 'buchung', p_buchung::text), NULL, NULL);
    END IF;
END $$;

REVOKE ALL ON ALL TABLES IN SCHEMA anlagen FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON ALL TABLES IN SCHEMA anlagen TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA anlagen FROM PUBLIC;
GRANT EXECUTE ON FUNCTION anlagen.einstellung_setzen(text, boolean, text, text), anlagen.gut_setzen(text, uuid, jsonb), anlagen.gut_abgang(text, uuid, date, text),
    anlagen.afa_vermerken(text, uuid, text, uuid, text, numeric), anlagen.afa_entfernen(text, uuid, text, uuid) TO kumero_app;
