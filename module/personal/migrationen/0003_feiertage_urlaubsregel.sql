-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul personal, Migration 0003: Feiertage je Arbeitsort, Korrektur der Arbeitstage, Urlaubsregel
-- (PERSONAL.md Abschnitt 10c; Entscheidungen des Verantwortlichen vom 2026-11-20 zu FT1–FT6 und UR1–UR5)
--
-- Feiertage: ein Regelwerk (personal.feiertag) mit den neun bundeseinheitlichen und den Feiertagen der Länder; bewegliche
--   werden aus dem Osterdatum gerechnet und gelten für jedes Jahr von selbst. Die **Vorgabe ist die Steuerdatei**
--   `module/personal/regelwerk/feiertage-de.json` (dieselben Einträge wie unten); der Mandant passt sein Regelwerk in der
--   Oberfläche an (Feiertag ändern, eigenen anlegen, entfernen) und kann die Vorgabe wieder einlesen. Ein **Arbeitsort** ist ein Bundesland mit
--   beliebiger Zu- und Abwahl aus dem Regelwerk (z. B. „Augsburg“: Bayern mit Friedensfest und Mariä Himmelfahrt). Jede
--   Person hat ihren Arbeitsort; ohne Angabe gilt der des Mandanten; ohne jeden Arbeitsort gelten die bundeseinheitlichen.
-- Korrektur: Die festgehaltenen Arbeitstage einer Abwesenheit lassen sich neu rechnen oder von Hand setzen.
-- Urlaubsregel: anteiliger Anspruch (§ 5 BUrlG oder vertraglich), Übertrag des Rests, Verfall zum Stichtag – als
--   Einstellung des Mandanten mit „gilt ab Jahr“; je Person und Jahr lässt sich alles festsetzen.
-- **Das Regelwerk und die Lesart des Gesetzes sind vom Verantwortlichen zu prüfen.** Pflege: eine neue Migration.

-- ---------------------------------------------------------------------------
-- Regelwerk der Feiertage
-- ---------------------------------------------------------------------------
CREATE TABLE personal.feiertag (
    kennung      text PRIMARY KEY CHECK (kennung ~ '^[a-z][a-z0-9_]{1,39}$'),
    name         text NOT NULL,
    art          text NOT NULL CHECK (art IN ('fest', 'ostern', 'busstag')),
    monat        integer CHECK (monat BETWEEN 1 AND 12),
    tag          integer CHECK (tag BETWEEN 1 AND 31),
    abstand      integer,                               -- Tage ab Ostersonntag
    bundesweit   boolean NOT NULL DEFAULT false,
    laender      text[] NOT NULL DEFAULT '{}',          -- Länder, in denen er im ganzen Land gilt
    teile        text[] NOT NULL DEFAULT '{}',          -- Länder, in denen er nur in Teilen gilt (am Arbeitsort zuzuwählen)
    reihenfolge  integer NOT NULL,
    CHECK ((art = 'fest') = (monat IS NOT NULL AND tag IS NOT NULL)),
    CHECK ((art = 'ostern') = (abstand IS NOT NULL))
);
INSERT INTO personal.feiertag (kennung, name, art, monat, tag, abstand, bundesweit, laender, teile, reihenfolge) VALUES
    ('neujahr',              'Neujahr',                   'fest',   1,  1, NULL, true,  '{}', '{}', 10),
    ('heilige_drei_koenige', 'Heilige Drei Könige',       'fest',   1,  6, NULL, false, '{BW,BY,ST}', '{}', 20),
    ('frauentag',            'Internationaler Frauentag', 'fest',   3,  8, NULL, false, '{BE,MV}', '{}', 30),
    ('karfreitag',           'Karfreitag',                'ostern', NULL, NULL, -2, true,  '{}', '{}', 40),
    ('ostersonntag',         'Ostersonntag',              'ostern', NULL, NULL,  0, false, '{BB}', '{}', 50),
    ('ostermontag',          'Ostermontag',               'ostern', NULL, NULL,  1, true,  '{}', '{}', 60),
    ('tag_der_arbeit',       'Tag der Arbeit',            'fest',   5,  1, NULL, true,  '{}', '{}', 70),
    ('christi_himmelfahrt',  'Christi Himmelfahrt',       'ostern', NULL, NULL, 39, true,  '{}', '{}', 80),
    ('pfingstsonntag',       'Pfingstsonntag',            'ostern', NULL, NULL, 49, false, '{BB}', '{}', 90),
    ('pfingstmontag',        'Pfingstmontag',             'ostern', NULL, NULL, 50, true,  '{}', '{}', 100),
    ('fronleichnam',         'Fronleichnam',              'ostern', NULL, NULL, 60, false, '{BW,BY,HE,NW,RP,SL}', '{SN,TH}', 110),
    ('friedensfest',         'Augsburger Friedensfest',   'fest',   8,  8, NULL, false, '{}', '{BY}', 120),
    ('mariae_himmelfahrt',   'Mariä Himmelfahrt',         'fest',   8, 15, NULL, false, '{SL}', '{BY}', 130),
    ('weltkindertag',        'Weltkindertag',             'fest',   9, 20, NULL, false, '{TH}', '{}', 140),
    ('deutsche_einheit',     'Tag der Deutschen Einheit', 'fest',  10,  3, NULL, true,  '{}', '{}', 150),
    ('reformationstag',      'Reformationstag',           'fest',  10, 31, NULL, false, '{BB,HB,HH,MV,NI,SN,ST,SH,TH}', '{}', 160),
    ('allerheiligen',        'Allerheiligen',             'fest',  11,  1, NULL, false, '{BW,BY,NW,RP,SL}', '{}', 170),
    ('buss_und_bettag',      'Buß- und Bettag',           'busstag', NULL, NULL, NULL, false, '{SN}', '{}', 180),
    ('weihnachten_1',        '1. Weihnachtstag',          'fest',  12, 25, NULL, true,  '{}', '{}', 190),
    ('weihnachten_2',        '2. Weihnachtstag',          'fest',  12, 26, NULL, true,  '{}', '{}', 200);

CREATE TABLE personal.arbeitsort (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name         text NOT NULL UNIQUE CHECK (length(trim(name)) BETWEEN 1 AND 60),
    land         text NOT NULL CHECK (land IN ('BW', 'BY', 'BE', 'BB', 'HB', 'HH', 'HE', 'MV', 'NI', 'NW', 'RP', 'SL', 'SN', 'ST', 'SH', 'TH')),
    zusatz       text[] NOT NULL DEFAULT '{}',   -- Feiertage, die hier zusätzlich gelten
    ohne         text[] NOT NULL DEFAULT '{}',   -- Feiertage, die hier nicht gelten sollen
    stillgelegt  boolean NOT NULL DEFAULT false
);
ALTER TABLE personal.einstellung ADD COLUMN arbeitsort uuid REFERENCES personal.arbeitsort;   -- Arbeitsort des Mandanten
ALTER TABLE personal.person ADD COLUMN arbeitsort uuid REFERENCES personal.arbeitsort;        -- leer = der des Mandanten
ALTER TABLE personal.abwesenheit ADD COLUMN tage_von_hand boolean NOT NULL DEFAULT false;

-- Ostersonntag nach der gregorianischen Osterformel (Meeus/Jones/Butcher)
CREATE FUNCTION personal._ostern(p_jahr integer) RETURNS date LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE a integer := p_jahr % 19; b integer := p_jahr / 100; c integer := p_jahr % 100; d integer; e integer; f integer; g integer;
        h integer; i integer; k integer; l integer; m integer;
BEGIN
    d := b / 4; e := b % 4; f := (b + 8) / 25; g := (b - f + 1) / 3;
    h := (19 * a + b - d - g + 15) % 30; i := c / 4; k := c % 4;
    l := (32 + 2 * e + 2 * i - h - k) % 7; m := (a + 11 * h + 22 * l) / 451;
    RETURN make_date(p_jahr, (h + l - 7 * m + 114) / 31, ((h + l - 7 * m + 114) % 31) + 1);
END $$;

-- Buß- und Bettag: der Mittwoch vor dem 23. November
CREATE FUNCTION personal._busstag(p_jahr integer) RETURNS date LANGUAGE sql IMMUTABLE AS $$
    SELECT make_date(p_jahr, 11, 22) - ((extract(isodow FROM make_date(p_jahr, 11, 22))::integer - 3 + 7) % 7)
$$;

CREATE FUNCTION personal._arbeitsort_von(p_person uuid) RETURNS uuid LANGUAGE sql STABLE AS $$
    SELECT coalesce((SELECT arbeitsort FROM personal.person WHERE id = p_person), (SELECT arbeitsort FROM personal.einstellung))
$$;

-- Feiertage an einem Arbeitsort in einem Zeitraum; ohne Arbeitsort die bundeseinheitlichen
CREATE FUNCTION personal.feiertage(p_ort uuid, p_von date, p_bis date)
RETURNS TABLE (datum date, name text, kennung text)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
    WITH ort AS (SELECT o.land, o.zusatz, o.ohne FROM personal.arbeitsort o WHERE o.id = p_ort),
         j AS (SELECT generate_series(extract(year FROM p_von)::integer, extract(year FROM p_bis)::integer) AS jahr),
         alle AS (SELECT f.kennung, f.name, f.reihenfolge,
                         CASE f.art WHEN 'fest' THEN CASE WHEN f.monat = 2 AND f.tag = 29 AND NOT (j.jahr % 4 = 0 AND (j.jahr % 100 <> 0 OR j.jahr % 400 = 0)) THEN NULL ELSE make_date(j.jahr, f.monat, f.tag) END
                                    WHEN 'ostern' THEN personal._ostern(j.jahr) + f.abstand
                                    ELSE personal._busstag(j.jahr) END AS datum
                    FROM personal.feiertag f CROSS JOIN j
                   WHERE (f.bundesweit OR EXISTS (SELECT 1 FROM ort WHERE ort.land = ANY (f.laender) OR f.kennung = ANY (ort.zusatz)))
                     AND NOT EXISTS (SELECT 1 FROM ort WHERE f.kennung = ANY (ort.ohne)))
    SELECT a.datum, a.name, a.kennung FROM alle a WHERE a.datum BETWEEN p_von AND p_bis ORDER BY a.datum, a.reihenfolge
$$;

-- Arbeitstage: wie bisher nach dem Vertrag und ohne die freien Tage des Betriebs – jetzt auch ohne die Feiertage am
-- Arbeitsort der Person.
CREATE OR REPLACE FUNCTION personal._arbeitstage(p_person uuid, p_von date, p_bis date, p_halb boolean DEFAULT false)
RETURNS numeric LANGUAGE sql STABLE AS $$
    WITH ft AS MATERIALIZED (SELECT datum FROM personal.feiertage(personal._arbeitsort_von(p_person), p_von, p_bis))
    SELECT (CASE WHEN p_halb THEN least(count(*), 1) * 0.5 ELSE count(*) END)::numeric(5,1)
      FROM generate_series(p_von::timestamp, p_bis::timestamp, interval '1 day') AS g(t)
     WHERE NOT EXISTS (SELECT 1 FROM personal.freier_tag f WHERE f.datum = g.t::date)
       AND NOT EXISTS (SELECT 1 FROM ft WHERE ft.datum = g.t::date)
       AND coalesce((SELECT v.verteilung[extract(isodow FROM g.t)::integer] FROM personal.vertrag v WHERE v.id = personal.vertrag_am(p_person, g.t::date)),
                    CASE WHEN extract(isodow FROM g.t) <= 5 THEN 1 ELSE 0 END) > 0
$$;

-- Das Regelwerk in der Oberfläche anpassen (Vorgabe des Verantwortlichen: Steuerdatei, in der GUI anzupassen).
-- p leer = Feiertag entfernen. p: name, art (fest | ostern | busstag), monat, tag, abstand, bundesweit, laender, teile.
CREATE FUNCTION personal.feiertag_setzen(p_subjekt text, p_kennung text, p jsonb)
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE v_kennung text := coalesce(nullif(trim(p_kennung), ''), 'eigen_' || substr(md5(gen_random_uuid()::text), 1, 10)); alt jsonb; neu jsonb;
        v_laender text[]; v_teile text[]; LAENDER CONSTANT text[] := ARRAY['BW', 'BY', 'BE', 'BB', 'HB', 'HH', 'HE', 'MV', 'NI', 'NW', 'RP', 'SL', 'SN', 'ST', 'SH', 'TH'];
BEGIN
    SELECT to_jsonb(f) INTO alt FROM personal.feiertag f WHERE f.kennung = v_kennung;
    IF p IS NULL OR p = 'null'::jsonb THEN
        DELETE FROM personal.feiertag WHERE kennung = v_kennung;
        IF NOT FOUND THEN PERFORM personal._fehler('Diesen Feiertag gibt es nicht', 'KM003'); END IF;
        PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.feiertag_entfernt', jsonb_build_object('feiertag', v_kennung), alt, NULL);
        RETURN v_kennung;
    END IF;
    IF personal._t(p, 'name') IS NULL OR length(personal._t(p, 'name')) > 60 THEN PERFORM personal._fehler('Feiertag: Name mit höchstens 60 Zeichen'); END IF;
    SELECT coalesce(array_agg(DISTINCT x), '{}') INTO v_laender FROM jsonb_array_elements_text(CASE WHEN jsonb_typeof(p->'laender') = 'array' THEN p->'laender' ELSE '[]'::jsonb END) x;
    SELECT coalesce(array_agg(DISTINCT x), '{}') INTO v_teile FROM jsonb_array_elements_text(CASE WHEN jsonb_typeof(p->'teile') = 'array' THEN p->'teile' ELSE '[]'::jsonb END) x;
    IF NOT (v_laender <@ LAENDER AND v_teile <@ LAENDER) THEN PERFORM personal._fehler('Feiertag: unbekanntes Bundesland'); END IF;
    IF personal._t(p, 'art') = 'fest' AND ((p->>'monat') IS NULL OR (p->>'tag') IS NULL
       OR (p->>'tag')::integer > CASE WHEN (p->>'monat')::integer = 2 THEN 29 WHEN (p->>'monat')::integer IN (4, 6, 9, 11) THEN 30 ELSE 31 END) THEN
        PERFORM personal._fehler('Feiertag: Tag und Monat ergeben kein Datum');
    END IF;
    INSERT INTO personal.feiertag (kennung, name, art, monat, tag, abstand, bundesweit, laender, teile, reihenfolge)
    VALUES (v_kennung, personal._t(p, 'name'), personal._t(p, 'art'),
            CASE WHEN p->>'art' = 'fest' THEN (p->>'monat')::integer END, CASE WHEN p->>'art' = 'fest' THEN (p->>'tag')::integer END,
            CASE WHEN p->>'art' = 'ostern' THEN (p->>'abstand')::integer END, coalesce((p->>'bundesweit')::boolean, false), v_laender, v_teile,
            coalesce((p->>'reihenfolge')::integer, (SELECT coalesce(max(reihenfolge), 0) + 10 FROM personal.feiertag)))
    ON CONFLICT (kennung) DO UPDATE SET name = EXCLUDED.name, art = EXCLUDED.art, monat = EXCLUDED.monat, tag = EXCLUDED.tag, abstand = EXCLUDED.abstand,
           bundesweit = EXCLUDED.bundesweit, laender = EXCLUDED.laender, teile = EXCLUDED.teile,
           reihenfolge = CASE WHEN p ? 'reihenfolge' THEN EXCLUDED.reihenfolge ELSE personal.feiertag.reihenfolge END;
    SELECT to_jsonb(f) INTO neu FROM personal.feiertag f WHERE f.kennung = v_kennung;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.feiertag_gesetzt', jsonb_build_object('feiertag', v_kennung), alt, neu);
    RETURN v_kennung;
EXCEPTION WHEN check_violation OR not_null_violation OR invalid_text_representation THEN
    PERFORM personal._fehler('Feiertag: Art unbekannt oder Angaben unvollständig (festes Datum: Tag und Monat; beweglich: Tage ab Ostersonntag)');
    RETURN NULL;
END $$;

-- Arbeitsort anlegen oder ändern. p: name, land, feiertage (Kennungen aller Feiertage, die hier gelten sollen – daraus
-- folgen Zu- und Abwahl gegenüber dem Land; fehlt die Liste, gilt das Land), stillgelegt, standard (Arbeitsort des Mandanten).
CREATE FUNCTION personal.arbeitsort_setzen(p_subjekt text, p_id uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE neu uuid; v_land text := personal._t(p, 'land'); v_name text := personal._t(p, 'name'); gewollt text[]; vorgabe text[];
        v_zusatz text[] := '{}'; v_ohne text[] := '{}'; still boolean := coalesce((p->>'stillgelegt')::boolean, false);
BEGIN
    IF v_name IS NULL OR length(v_name) > 60 THEN PERFORM personal._fehler('Arbeitsort: Name mit höchstens 60 Zeichen'); END IF;
    IF v_land IS NULL OR v_land NOT IN ('BW', 'BY', 'BE', 'BB', 'HB', 'HH', 'HE', 'MV', 'NI', 'NW', 'RP', 'SL', 'SN', 'ST', 'SH', 'TH') THEN
        PERFORM personal._fehler('Arbeitsort: Bundesland fehlt oder ist unbekannt');
    END IF;
    IF EXISTS (SELECT 1 FROM personal.arbeitsort WHERE lower(name) = lower(v_name) AND id IS DISTINCT FROM p_id) THEN
        PERFORM personal._fehler('Einen Arbeitsort mit diesem Namen gibt es schon', 'KM003');
    END IF;
    IF jsonb_typeof(p->'feiertage') = 'array' THEN
        SELECT coalesce(array_agg(x), '{}') INTO gewollt FROM jsonb_array_elements_text(p->'feiertage') x;
        IF EXISTS (SELECT 1 FROM unnest(gewollt) x WHERE NOT EXISTS (SELECT 1 FROM personal.feiertag f WHERE f.kennung = x)) THEN
            PERFORM personal._fehler('Arbeitsort: unbekannter Feiertag');
        END IF;
        SELECT coalesce(array_agg(kennung), '{}') INTO vorgabe FROM personal.feiertag WHERE bundesweit OR v_land = ANY (laender);
        SELECT coalesce(array_agg(x ORDER BY x), '{}') INTO v_zusatz FROM unnest(gewollt) x WHERE x <> ALL (vorgabe);
        SELECT coalesce(array_agg(x ORDER BY x), '{}') INTO v_ohne FROM unnest(vorgabe) x WHERE x <> ALL (gewollt);
    ELSIF p_id IS NOT NULL THEN
        SELECT zusatz, ohne INTO v_zusatz, v_ohne FROM personal.arbeitsort WHERE id = p_id;
    END IF;
    IF p_id IS NULL THEN
        INSERT INTO personal.arbeitsort (name, land, zusatz, ohne) VALUES (v_name, v_land, v_zusatz, v_ohne) RETURNING id INTO neu;
    ELSE
        IF still AND (coalesce((p->>'standard')::boolean, false) OR EXISTS (SELECT 1 FROM personal.einstellung WHERE arbeitsort = p_id AND NOT (p ? 'standard' AND NOT (p->>'standard')::boolean))) THEN
            PERFORM personal._fehler('Der Arbeitsort des Mandanten lässt sich nicht stilllegen', 'KM003');
        END IF;
        UPDATE personal.arbeitsort SET name = v_name, land = v_land, zusatz = v_zusatz, ohne = v_ohne, stillgelegt = still WHERE id = p_id RETURNING id INTO neu;
        IF neu IS NULL THEN PERFORM personal._fehler('Arbeitsort unbekannt', 'KM003'); END IF;
    END IF;
    IF p ? 'standard' THEN
        IF (p->>'standard')::boolean THEN UPDATE personal.einstellung SET arbeitsort = neu, geaendert_am = clock_timestamp(), geaendert_von = p_subjekt;
        ELSE UPDATE personal.einstellung SET arbeitsort = NULL, geaendert_am = clock_timestamp(), geaendert_von = p_subjekt WHERE arbeitsort = neu;
        END IF;
    END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.arbeitsort_gesetzt', jsonb_build_object('arbeitsort', neu::text), NULL,
        (SELECT to_jsonb(x) - 'id' FROM personal.arbeitsort x WHERE x.id = neu)
        || jsonb_build_object('standard', EXISTS (SELECT 1 FROM personal.einstellung WHERE arbeitsort = neu)));
    RETURN neu;
END $$;

CREATE FUNCTION personal.person_arbeitsort_setzen(p_subjekt text, p_person uuid, p_ort uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE alt uuid;
BEGIN
    SELECT arbeitsort INTO alt FROM personal.person WHERE id = p_person FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Person unbekannt', 'KM003'); END IF;
    IF p_ort IS NOT NULL AND NOT EXISTS (SELECT 1 FROM personal.arbeitsort WHERE id = p_ort AND (NOT stillgelegt OR id = alt)) THEN
        PERFORM personal._fehler('Arbeitsort unbekannt oder stillgelegt');
    END IF;
    IF alt IS NOT DISTINCT FROM p_ort THEN RETURN; END IF;
    UPDATE personal.person SET arbeitsort = p_ort, geaendert_am = clock_timestamp(), geaendert_von = p_subjekt WHERE id = p_person;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.person_geaendert', jsonb_build_object('person', p_person::text), NULL,
        jsonb_build_object('felder', jsonb_build_array('arbeitsort')));
END $$;

-- ---------------------------------------------------------------------------
-- Korrektur der Arbeitstage einer Abwesenheit (FT5): neu rechnen (p_tage leer) oder von Hand setzen
-- ---------------------------------------------------------------------------
CREATE FUNCTION personal.abwesenheit_tage_setzen(p_subjekt text, p_id uuid, p_tage numeric, p_folgejahr numeric)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE a personal.abwesenheit; art personal.abwesenheitsart; r record; ueber boolean;
BEGIN
    SELECT * INTO a FROM personal.abwesenheit WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Abwesenheit unbekannt', 'KM003'); END IF;
    IF a.zustand NOT IN ('beantragt', 'genehmigt') THEN PERFORM personal._fehler('Die Abwesenheit ist abgelehnt oder zurückgenommen', 'KM003'); END IF;
    ueber := extract(year FROM a.bis) > extract(year FROM a.von);
    IF p_tage IS NULL THEN
        SELECT * INTO art FROM personal.abwesenheitsart WHERE id = a.art;
        SELECT * INTO r FROM personal._abwesenheit_rechnen(a.person, art, a.von, a.bis, a.halber_tag, a.id);
        UPDATE personal.abwesenheit SET tage = r.o_tage, tage_folgejahr = r.o_folgejahr, tage_von_hand = false WHERE id = p_id;
    ELSE
        IF p_tage < 0 OR p_tage > (a.bis - a.von + 1) OR p_tage * 2 <> trunc(p_tage * 2) THEN
            PERFORM personal._fehler('Arbeitstage: halbe oder ganze Tage, höchstens so viele wie der Zeitraum Kalendertage hat');
        END IF;
        IF coalesce(p_folgejahr, 0) < 0 OR coalesce(p_folgejahr, 0) > p_tage OR coalesce(p_folgejahr, 0) * 2 <> trunc(coalesce(p_folgejahr, 0) * 2)
           OR (NOT ueber AND coalesce(p_folgejahr, 0) <> 0) THEN
            PERFORM personal._fehler('Davon im Folgejahr: nur bei einer Abwesenheit über den Jahreswechsel, höchstens die Arbeitstage');
        END IF;
        UPDATE personal.abwesenheit SET tage = p_tage, tage_folgejahr = coalesce(p_folgejahr, 0), tage_von_hand = true WHERE id = p_id;
    END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.abwesenheit_geaendert',
        jsonb_build_object('person', a.person::text, 'abwesenheit', p_id::text), NULL, jsonb_build_object('felder', jsonb_build_array('tage')));
END $$;

-- Genehmigen rechnet die Tage neu – außer sie sind von Hand gesetzt
CREATE OR REPLACE FUNCTION personal.abwesenheit_entscheiden(p_subjekt text, p_id uuid, p_genehmigen boolean, p_grund text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE a personal.abwesenheit; art personal.abwesenheitsart; r record;
BEGIN
    SELECT * INTO a FROM personal.abwesenheit WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Abwesenheit unbekannt', 'KM003'); END IF;
    IF a.zustand <> 'beantragt' THEN PERFORM personal._fehler('Über den Antrag ist schon entschieden', 'KM003'); END IF;
    IF NOT coalesce(p_genehmigen, false) AND nullif(trim(p_grund), '') IS NULL THEN PERFORM personal._fehler('Eine Ablehnung braucht einen Grund'); END IF;
    IF length(coalesce(p_grund, '')) > 200 THEN PERFORM personal._fehler('Grund: höchstens 200 Zeichen'); END IF;
    IF coalesce(p_genehmigen, false) THEN
        SELECT * INTO art FROM personal.abwesenheitsart WHERE id = a.art;
        SELECT * INTO r FROM personal._abwesenheit_rechnen(a.person, art, a.von, a.bis, a.halber_tag, a.id);
        UPDATE personal.abwesenheit SET zustand = 'genehmigt', tage = CASE WHEN a.tage_von_hand THEN tage ELSE r.o_tage END,
               tage_folgejahr = CASE WHEN a.tage_von_hand THEN tage_folgejahr ELSE r.o_folgejahr END, grund = nullif(trim(p_grund), ''),
               entschieden_am = clock_timestamp(), entschieden_von = p_subjekt WHERE id = p_id;
    ELSE
        UPDATE personal.abwesenheit SET zustand = 'abgelehnt', grund = trim(p_grund), entschieden_am = clock_timestamp(), entschieden_von = p_subjekt WHERE id = p_id;
    END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', CASE WHEN coalesce(p_genehmigen, false) THEN 'personal.abwesenheit_genehmigt' ELSE 'personal.abwesenheit_abgelehnt' END,
        jsonb_build_object('person', a.person::text, 'abwesenheit', p_id::text));
END $$;

-- Ein neues Ende rechnet neu; eine Korrektur von Hand gilt für den alten Zeitraum und entfällt
CREATE OR REPLACE FUNCTION personal.abwesenheit_ende_setzen(p_subjekt text, p_id uuid, p_bis date)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE a personal.abwesenheit; art personal.abwesenheitsart; r record;
BEGIN
    SELECT * INTO a FROM personal.abwesenheit WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Abwesenheit unbekannt', 'KM003'); END IF;
    IF a.zustand NOT IN ('beantragt', 'genehmigt') THEN PERFORM personal._fehler('Die Abwesenheit ist abgelehnt oder zurückgenommen', 'KM003'); END IF;
    IF a.halber_tag THEN PERFORM personal._fehler('Ein halber Tag hat kein anderes Ende – zurücknehmen und neu eintragen', 'KM003'); END IF;
    SELECT * INTO art FROM personal.abwesenheitsart WHERE id = a.art;
    SELECT * INTO r FROM personal._abwesenheit_rechnen(a.person, art, a.von, p_bis, false, a.id);
    UPDATE personal.abwesenheit SET bis = p_bis, tage = r.o_tage, tage_folgejahr = r.o_folgejahr, tage_von_hand = false WHERE id = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.abwesenheit_geaendert',
        jsonb_build_object('person', a.person::text, 'abwesenheit', p_id::text), NULL, jsonb_build_object('felder', jsonb_build_array('bis')));
END $$;

-- ---------------------------------------------------------------------------
-- Urlaubsregel des Mandanten
-- ---------------------------------------------------------------------------
-- anteilig:  aus       – immer der volle Anspruch des Vertrags
--            gesetz    – § 5 Abs. 1 BUrlG: ein Zwölftel je vollem Monat des Bestehens nur (a) im Eintrittsjahr, wenn die
--                        Wartezeit von sechs Monaten (§ 4) im Kalenderjahr nicht erfüllt wird (Eintritt ab dem 1. Juli),
--                        (b) bei Ausscheiden vor erfüllter Wartezeit, (c) bei Ausscheiden nach erfüllter Wartezeit in der
--                        ersten Hälfte des Kalenderjahres; sonst der volle Anspruch
--            zwoelftel – in jedem Jahr des Ein- oder Austritts ein Zwölftel je vollem Monat (vertragliche Regelung; sie
--                        darf den gesetzlichen Mindesturlaub nicht unterschreiten – das prüft kumero nicht)
-- rundung:   gesetz (§ 5 Abs. 2: Bruchteile ab einem halben Tag auf volle Tage auf, kleinere bleiben stehen), halbe
--            (kaufmännisch auf halbe Tage), ganze (immer auf ganze Tage auf), kaufmaennisch (auf ganze Tage), keine
-- uebertrag: nie | rest (der Rest des Vorjahres geht ins neue Jahr, wahlweise mit Höchstzahl)
-- verfall:   nie | bestaetigt (nach dem Stichtag bestätigt das Personal je Person) | selbst (nach dem Stichtag von selbst)
CREATE TABLE personal.urlaubsregel (
    ab_jahr              integer PRIMARY KEY CHECK (ab_jahr BETWEEN 2000 AND 2100),
    anteilig             text NOT NULL DEFAULT 'gesetz' CHECK (anteilig IN ('aus', 'gesetz', 'zwoelftel')),
    rundung              text NOT NULL DEFAULT 'gesetz' CHECK (rundung IN ('gesetz', 'halbe', 'ganze', 'kaufmaennisch', 'keine')),
    uebertrag            text NOT NULL DEFAULT 'rest' CHECK (uebertrag IN ('nie', 'rest')),
    uebertrag_hoechstens numeric(4,1) CHECK (uebertrag_hoechstens IS NULL OR uebertrag_hoechstens BETWEEN 0 AND 99),
    verfall              text NOT NULL DEFAULT 'bestaetigt' CHECK (verfall IN ('nie', 'bestaetigt', 'selbst')),
    verfall_tag          integer NOT NULL DEFAULT 31,
    verfall_monat        integer NOT NULL DEFAULT 3 CHECK (verfall_monat BETWEEN 1 AND 12),
    geaendert_am         timestamptz NOT NULL DEFAULT now(),
    geaendert_von        text,
    CHECK (verfall_tag BETWEEN 1 AND CASE WHEN verfall_monat = 2 THEN 28 WHEN verfall_monat IN (4, 6, 9, 11) THEN 30 ELSE 31 END)
);
-- Vorgabe ab dem laufenden Jahr: Teilurlaub nach dem Gesetz, der Rest geht ins Folgejahr und verfällt dort am 31. März –
-- nach Bestätigung durch das Personal. Frühere Jahre bleiben ohne Regel (wie bisher).
INSERT INTO personal.urlaubsregel (ab_jahr) VALUES (extract(year FROM current_date)::integer);

-- Urlaubskonto: Übertrag leer = nach der Regel; „verfallen“ leer = nach der Regel bzw. noch nicht bestätigt
ALTER TABLE personal.urlaubskonto ALTER COLUMN uebertrag DROP NOT NULL, ALTER COLUMN uebertrag DROP DEFAULT;
UPDATE personal.urlaubskonto SET uebertrag = NULL WHERE uebertrag = 0;
ALTER TABLE personal.urlaubskonto ADD COLUMN verfallen numeric(4,1) CHECK (verfallen IS NULL OR verfallen BETWEEN 0 AND 99);

CREATE FUNCTION personal._urlaubsregel(p_jahr integer) RETURNS personal.urlaubsregel LANGUAGE sql STABLE AS $$
    SELECT r FROM personal.urlaubsregel r WHERE r.ab_jahr <= p_jahr ORDER BY r.ab_jahr DESC LIMIT 1
$$;

CREATE FUNCTION personal._urlaub_runden(p numeric, p_art text) RETURNS numeric LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE p_art WHEN 'gesetz' THEN CASE WHEN p - floor(p) >= 0.5 THEN ceil(p) ELSE round(p, 2) END
                      WHEN 'halbe' THEN round(p * 2) / 2
                      WHEN 'ganze' THEN ceil(p)
                      WHEN 'kaufmaennisch' THEN round(p)
                      ELSE round(p, 2) END
$$;

DROP FUNCTION personal.urlaubskonto_stand(uuid, integer);

-- Urlaubskonto einer Person in einem Jahr nach der Regel dieses Jahres. Was am Konto festgesetzt ist, geht der Regel vor.
--   voll           Urlaubstage der Fassung des Vertrags, die am 1. Januar gilt (sonst der ersten im Jahr)
--   anspruch       festgesetzt, sonst voll oder – nach der Regel – der Anteil für `monate` volle Monate
--   uebertrag      festgesetzt, sonst nach der Regel der Rest des Vorjahres
--   verfallen      festgesetzt bzw. bestätigt, sonst (Regel „selbst“) nach dem Stichtag der ungenutzte Übertrag
--   verfall_droht  vor dem Stichtag: so viel Übertrag ist bis dahin nicht verplant (Hinweis an Person und Personal)
--   verfall_offen  nach dem Stichtag, Regel „bestaetigt“: wartet auf die Bestätigung des Personals
-- Der Übertrag wird vor dem Anspruch des Jahres verbraucht (UR4): Auf ihn zählt genehmigter Urlaub bis zum Stichtag.
CREATE FUNCTION personal._urlaubskonto(p_person uuid, p_jahr integer, p_tiefe integer DEFAULT 0)
RETURNS TABLE (anspruch numeric, festgesetzt boolean, voll numeric, monate integer, uebertrag numeric, uebertrag_festgesetzt boolean,
               beantragt numeric, genehmigt numeric, genommen numeric, verfallen numeric, verfall_droht numeric, verfall_offen numeric, verfall_am date)
LANGUAGE plpgsql STABLE AS $$
DECLARE r personal.urlaubsregel; k personal.urlaubskonto; v_fassung uuid; v_beginn date; v_austritt date; a date; e date; teil boolean;
        x numeric; vor record; bis_stichtag numeric; anfang date := make_date(p_jahr, 1, 1); ende date := make_date(p_jahr, 12, 31);
BEGIN
    r := personal._urlaubsregel(p_jahr);
    SELECT * INTO k FROM personal.urlaubskonto WHERE person = p_person AND jahr = p_jahr;
    v_fassung := coalesce(personal.vertrag_am(p_person, anfang),
                          (SELECT v.id FROM personal.vertrag v WHERE v.person = p_person AND v.gueltig_ab BETWEEN anfang AND ende ORDER BY v.gueltig_ab, v.angelegt_am DESC LIMIT 1));
    voll := coalesce((SELECT v.urlaubstage FROM personal.vertrag v WHERE v.id = v_fassung), 0);
    SELECT min(v.beginn) INTO v_beginn FROM personal.vertrag v WHERE v.person = p_person;
    SELECT p.austritt INTO v_austritt FROM personal.person p WHERE p.id = p_person;

    anspruch := voll; festgesetzt := false; monate := NULL;
    IF k.anspruch IS NOT NULL THEN
        anspruch := k.anspruch; festgesetzt := true;
    ELSIF r.anteilig IN ('gesetz', 'zwoelftel') AND v_beginn IS NOT NULL AND voll > 0 THEN
        a := greatest(v_beginn, anfang);
        e := least(coalesce(v_austritt, ende), ende);
        IF e < a THEN
            anspruch := 0; monate := 0;
        ELSE
            monate := (extract(year FROM age(e + 1, a)) * 12 + extract(month FROM age(e + 1, a)))::integer;   -- volle Monate des Bestehens im Jahr
            teil := CASE r.anteilig
                WHEN 'zwoelftel' THEN monate < 12
                ELSE (v_beginn >= make_date(p_jahr, 7, 1))                                                                   -- (a) Wartezeit im Jahr nicht erfüllt
                  OR (v_austritt IS NOT NULL AND v_austritt <= ende AND v_austritt < (v_beginn + interval '6 months')::date) -- (b) Ausscheiden vor erfüllter Wartezeit
                  OR (v_austritt IS NOT NULL AND v_austritt <= make_date(p_jahr, 6, 30))                                     -- (c) Ausscheiden in der ersten Jahreshälfte
                END;
            IF teil THEN anspruch := personal._urlaub_runden(voll * monate / 12, r.rundung); ELSE monate := NULL; END IF;
        END IF;
    END IF;

    SELECT coalesce(sum(t.tage) FILTER (WHERE t.zustand = 'beantragt'), 0),
           coalesce(sum(t.tage) FILTER (WHERE t.zustand = 'genehmigt' AND t.bis >= current_date), 0),
           coalesce(sum(t.tage) FILTER (WHERE t.zustand = 'genehmigt' AND t.bis < current_date), 0)
      INTO beantragt, genehmigt, genommen
      FROM (SELECT x.zustand, x.bis, CASE WHEN extract(year FROM x.von) = p_jahr THEN x.tage - x.tage_folgejahr ELSE x.tage_folgejahr END AS tage
              FROM personal.abwesenheit x JOIN personal.abwesenheitsart art ON art.id = x.art
             WHERE x.person = p_person AND art.zaehlt_urlaub AND x.zustand IN ('beantragt', 'genehmigt')
               AND (extract(year FROM x.von) = p_jahr OR extract(year FROM x.bis) = p_jahr)) t;

    uebertrag := 0; uebertrag_festgesetzt := false;
    IF k.uebertrag IS NOT NULL THEN
        uebertrag := k.uebertrag; uebertrag_festgesetzt := true;
    ELSIF r.uebertrag = 'rest' AND p_jahr - 1 >= r.ab_jahr AND p_tiefe < 12 AND v_beginn < anfang THEN
        SELECT * INTO vor FROM personal._urlaubskonto(p_person, p_jahr - 1, p_tiefe + 1);
        x := vor.anspruch + vor.uebertrag - vor.verfallen - vor.genommen - vor.genehmigt;
        uebertrag := greatest(0, least(x, coalesce(r.uebertrag_hoechstens, x)));
    END IF;

    verfallen := 0; verfall_droht := 0; verfall_offen := 0; verfall_am := NULL;
    IF r.verfall IN ('bestaetigt', 'selbst') AND uebertrag > 0 THEN
        verfall_am := make_date(p_jahr, r.verfall_monat, r.verfall_tag);
        SELECT coalesce(sum(CASE WHEN x.bis <= verfall_am THEN j.tage
                                 WHEN greatest(x.von, anfang) > verfall_am THEN 0
                                 ELSE least(j.tage, personal._arbeitstage(p_person, greatest(x.von, anfang), verfall_am)) END), 0)
          INTO bis_stichtag
          FROM personal.abwesenheit x JOIN personal.abwesenheitsart art ON art.id = x.art,
               LATERAL (SELECT CASE WHEN extract(year FROM x.von) = p_jahr THEN x.tage - x.tage_folgejahr ELSE x.tage_folgejahr END AS tage) j
         WHERE x.person = p_person AND art.zaehlt_urlaub AND x.zustand = 'genehmigt'
           AND (extract(year FROM x.von) = p_jahr OR extract(year FROM x.bis) = p_jahr);
        x := greatest(0, uebertrag - bis_stichtag);
        IF current_date <= verfall_am THEN verfall_droht := x;
        ELSIF r.verfall = 'selbst' THEN verfallen := x;
        ELSE verfall_offen := x;
        END IF;
    END IF;
    IF k.verfallen IS NOT NULL THEN verfallen := least(k.verfallen, uebertrag); verfall_offen := 0; verfall_droht := 0; END IF;
    RETURN NEXT;
END $$;

CREATE FUNCTION personal.urlaubskonto_stand(p_person uuid, p_jahr integer)
RETURNS TABLE (anspruch numeric, festgesetzt boolean, voll numeric, monate integer, uebertrag numeric, uebertrag_festgesetzt boolean,
               beantragt numeric, genehmigt numeric, genommen numeric, verfallen numeric, verfall_droht numeric, verfall_offen numeric, verfall_am date)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
    SELECT * FROM personal._urlaubskonto(p_person, p_jahr, 0)
$$;

DROP FUNCTION personal.urlaubskonto_setzen(text, uuid, integer, numeric, numeric);

-- Am Konto festsetzen, was von Vertrag und Regel abweicht: Anspruch, Übertrag, Verfallenes. Leer = nach Vertrag bzw. Regel.
-- „Verfallen“ ist zugleich die Bestätigung des Verfalls (Regel „bestaetigt“); 0 heißt: Es verfällt nichts.
CREATE FUNCTION personal.urlaubskonto_setzen(p_subjekt text, p_person uuid, p_jahr integer, p_anspruch numeric, p_uebertrag numeric, p_verfallen numeric)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE alt personal.urlaubskonto; neu personal.urlaubskonto;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM personal.person WHERE id = p_person) THEN PERFORM personal._fehler('Person unbekannt', 'KM003'); END IF;
    IF p_jahr IS NULL OR p_jahr NOT BETWEEN 2000 AND 2100 THEN PERFORM personal._fehler('Jahr fehlt oder liegt außerhalb'); END IF;
    IF p_anspruch IS NOT NULL AND (p_anspruch NOT BETWEEN 0 AND 99 OR p_anspruch * 2 <> trunc(p_anspruch * 2)) THEN PERFORM personal._fehler('Anspruch: 0 bis 99 Tage, halbe Tage erlaubt'); END IF;
    IF p_uebertrag IS NOT NULL AND (p_uebertrag NOT BETWEEN -99 AND 99 OR p_uebertrag * 2 <> trunc(p_uebertrag * 2)) THEN PERFORM personal._fehler('Übertrag: −99 bis 99 Tage, halbe Tage erlaubt'); END IF;
    IF p_verfallen IS NOT NULL AND (p_verfallen NOT BETWEEN 0 AND 99 OR p_verfallen * 2 <> trunc(p_verfallen * 2)) THEN PERFORM personal._fehler('Verfallen: 0 bis 99 Tage, halbe Tage erlaubt'); END IF;
    SELECT * INTO alt FROM personal.urlaubskonto WHERE person = p_person AND jahr = p_jahr FOR UPDATE;
    INSERT INTO personal.urlaubskonto (person, jahr, anspruch, uebertrag, verfallen, geaendert_von) VALUES (p_person, p_jahr, p_anspruch, p_uebertrag, p_verfallen, p_subjekt)
    ON CONFLICT (person, jahr) DO UPDATE SET anspruch = EXCLUDED.anspruch, uebertrag = EXCLUDED.uebertrag, verfallen = EXCLUDED.verfallen,
           geaendert_am = clock_timestamp(), geaendert_von = EXCLUDED.geaendert_von
    RETURNING * INTO neu;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.urlaubskonto_gesetzt', jsonb_build_object('person', p_person::text, 'jahr', p_jahr::text), NULL,
        jsonb_build_object('felder', personal._geaendert(jsonb_build_object('anspruch', alt.anspruch, 'uebertrag', alt.uebertrag, 'verfallen', alt.verfallen),
                                                         jsonb_build_object('anspruch', neu.anspruch, 'uebertrag', neu.uebertrag, 'verfallen', neu.verfallen))));
END $$;

-- Regel setzen (je „gilt ab Jahr“ eine) oder entfernen. Keine Personendaten – die Werte stehen im Protokoll.
CREATE FUNCTION personal.urlaubsregel_setzen(p_subjekt text, p_ab_jahr integer, p jsonb)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE alt jsonb; neu jsonb;
BEGIN
    IF p_ab_jahr IS NULL OR p_ab_jahr NOT BETWEEN 2000 AND 2100 THEN PERFORM personal._fehler('Urlaubsregel: „gilt ab Jahr“ fehlt oder liegt außerhalb'); END IF;
    SELECT to_jsonb(r) - ARRAY['geaendert_am', 'geaendert_von'] || jsonb_build_object('uebertrag_hoechstens', r.uebertrag_hoechstens::text) INTO alt FROM personal.urlaubsregel r WHERE r.ab_jahr = p_ab_jahr;   -- Dezimalzahl als Text (F29)
    IF p IS NULL OR p = 'null'::jsonb THEN
        DELETE FROM personal.urlaubsregel WHERE ab_jahr = p_ab_jahr;
        IF NOT FOUND THEN PERFORM personal._fehler('Für dieses Jahr gibt es keine Regel', 'KM003'); END IF;
        PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.urlaubsregel_entfernt', jsonb_build_object('ab_jahr', p_ab_jahr::text), alt, NULL);
        RETURN;
    END IF;
    INSERT INTO personal.urlaubsregel (ab_jahr, anteilig, rundung, uebertrag, uebertrag_hoechstens, verfall, verfall_tag, verfall_monat, geaendert_von)
    VALUES (p_ab_jahr, coalesce(personal._t(p, 'anteilig'), 'gesetz'), coalesce(personal._t(p, 'rundung'), 'gesetz'), coalesce(personal._t(p, 'uebertrag'), 'rest'),
            nullif(p->>'uebertrag_hoechstens', '')::numeric, coalesce(personal._t(p, 'verfall'), 'bestaetigt'),
            coalesce((p->>'verfall_tag')::integer, 31), coalesce((p->>'verfall_monat')::integer, 3), p_subjekt)
    ON CONFLICT (ab_jahr) DO UPDATE SET anteilig = EXCLUDED.anteilig, rundung = EXCLUDED.rundung, uebertrag = EXCLUDED.uebertrag,
           uebertrag_hoechstens = EXCLUDED.uebertrag_hoechstens, verfall = EXCLUDED.verfall, verfall_tag = EXCLUDED.verfall_tag,
           verfall_monat = EXCLUDED.verfall_monat, geaendert_am = clock_timestamp(), geaendert_von = EXCLUDED.geaendert_von;
    SELECT to_jsonb(r) - ARRAY['geaendert_am', 'geaendert_von'] || jsonb_build_object('uebertrag_hoechstens', r.uebertrag_hoechstens::text) INTO neu FROM personal.urlaubsregel r WHERE r.ab_jahr = p_ab_jahr;   -- Dezimalzahl als Text (F29)
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.urlaubsregel_gesetzt', jsonb_build_object('ab_jahr', p_ab_jahr::text), alt, neu);
EXCEPTION WHEN check_violation THEN
    PERFORM personal._fehler('Urlaubsregel: ein Wert ist unbekannt oder der Stichtag ist kein Datum');
END $$;

-- ---------------------------------------------------------------------------
-- Zugriff
-- ---------------------------------------------------------------------------
REVOKE ALL ON ALL TABLES IN SCHEMA personal FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON ALL TABLES IN SCHEMA personal TO kumero_app;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA personal FROM PUBLIC;
GRANT EXECUTE ON FUNCTION personal.vertrag_am(uuid, date),
    personal.person_setzen(text, uuid, jsonb), personal.privat_setzen(text, uuid, jsonb), personal.vertrag_anlegen(text, uuid, jsonb),
    personal.fristart_setzen(text, uuid, text, integer, boolean), personal.frist_anlegen(text, uuid, uuid, date, text),
    personal.frist_erledigen(text, uuid), personal.vorschlag_anlegen(text, uuid, jsonb),
    personal.vorschlag_entscheiden(text, uuid, boolean, text), personal.loeschfrist_setzen(text, text, uuid, date, text),
    personal.einstellung_setzen(text, integer),
    personal.urlaubskonto_stand(uuid, integer),
    personal.abwesenheit_anlegen(text, uuid, jsonb, boolean), personal.abwesenheit_entscheiden(text, uuid, boolean, text),
    personal.abwesenheit_zuruecknehmen(text, uuid, text, boolean), personal.abwesenheit_ende_setzen(text, uuid, date),
    personal.au_nachweis_setzen(text, uuid, boolean), personal.abwesenheitsart_setzen(text, uuid, jsonb),
    personal.freier_tag_setzen(text, date, text),
    personal._arbeitsort_von(uuid), personal.feiertage(uuid, date, date), personal.feiertag_setzen(text, text, jsonb), personal.arbeitsort_setzen(text, uuid, jsonb), personal.person_arbeitsort_setzen(text, uuid, uuid),
    personal.abwesenheit_tage_setzen(text, uuid, numeric, numeric),
    personal.urlaubskonto_setzen(text, uuid, integer, numeric, numeric, numeric), personal.urlaubsregel_setzen(text, integer, jsonb) TO kumero_app;
