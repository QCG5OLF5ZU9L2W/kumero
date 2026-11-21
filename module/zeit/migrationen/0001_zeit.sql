-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul zeit, Migration 0001: Zeiterfassung am Mandanten (ZEIT.md Abschnitt 9, F145)
--
-- Datenklasse **Zeit** (eigene Rechte): Abschnitte (Kommen–Gehen) je Person und Tag, Anträge (Nachtrag, Korrektur),
-- Freigaben einzelner Tage, Abschluss des Monats, Zeitprofil je Person, Regeln des Mandanten.
-- Das Modul liest keine Tabellen des Personals (F44): Soll, Feiertage und Abwesenheiten kommen über personal.api_tage().
-- Geschrieben wird nur über Funktionen (F64); im Protokoll stehen IDs und der Tag, nie eine Uhrzeit.
-- Uhrzeiten sind Ortszeit des Mandanten (zeit.einstellung.zeitzone), minutengenau, ohne Rundung (ZE1).

CREATE SCHEMA zeit AUTHORIZATION kumero_owner;
REVOKE ALL ON SCHEMA zeit FROM PUBLIC;
GRANT USAGE ON SCHEMA zeit TO kumero_app;

CREATE TABLE zeit.einstellung (
    einzig           boolean PRIMARY KEY DEFAULT true CHECK (einzig),
    zeitzone         text NOT NULL DEFAULT 'Europe/Berlin',
    pausen           jsonb NOT NULL DEFAULT '[{"ab": 360, "pause": 30}, {"ab": 540, "pause": 45}]',   -- über „ab“ Minuten Arbeit gehören „pause“ Minuten Pause dazu
    hoechst_minuten  integer NOT NULL DEFAULT 600 CHECK (hoechst_minuten BETWEEN 60 AND 1440),          -- darüber zählt nichts ohne Sonderfreigabe
    nacht_von        time NOT NULL DEFAULT '20:00',
    nacht_bis        time NOT NULL DEFAULT '06:00',
    monatsabschluss  text NOT NULL DEFAULT 'person' CHECK (monatsabschluss IN ('aus', 'person', 'freigabe')),
    geaendert_am     timestamptz NOT NULL DEFAULT now(),
    geaendert_von    text
);
INSERT INTO zeit.einstellung DEFAULT VALUES;

-- Zeitprofil einer Person. Ohne Zeile gelten die Vorgaben: festes Modell, Regelarbeitstage aus dem Vertrag
-- (Tage mit Stunden), Korrekturen brauchen die Freigabe.
CREATE TABLE zeit.profil (
    person         uuid PRIMARY KEY,
    modell         text NOT NULL DEFAULT 'fest' CHECK (modell IN ('fest', 'gleitzeit')),
    regeltage      boolean[] CHECK (regeltage IS NULL OR cardinality(regeltage) = 7),    -- Mo–So; leer: aus dem Vertrag
    rahmen_von     time, rahmen_bis time,          -- Gleitzeit: Arbeit außerhalb des Rahmens zählt nicht
    kern_von       time, kern_bis time,            -- Gleitzeit: Hinweis, wenn die Kernzeit nicht abgedeckt ist
    korrektur      text NOT NULL DEFAULT 'freigabe' CHECK (korrektur IN ('sofort', 'freigabe')),
    anfangssaldo   integer NOT NULL DEFAULT 0 CHECK (anfangssaldo BETWEEN -600000 AND 600000),   -- Minuten
    beginn         date,                           -- ab wann das Zeitkonto zählt; leer: ab dem ersten gebuchten Tag
    geaendert_am   timestamptz NOT NULL DEFAULT now(),
    geaendert_von  text NOT NULL,
    CHECK ((rahmen_von IS NULL) = (rahmen_bis IS NULL) AND (rahmen_von IS NULL OR rahmen_von < rahmen_bis)),
    CHECK ((kern_von IS NULL) = (kern_bis IS NULL) AND (kern_von IS NULL OR kern_von < kern_bis))
);

-- Ein Abschnitt Arbeit: Kommen bis Gehen. Er gehört zu dem Tag, an dem er beginnt; endet er nicht später als er
-- beginnt, reicht er über Mitternacht. Eine Pause ist die Lücke zwischen zwei Abschnitten.
CREATE TABLE zeit.abschnitt (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    person        uuid NOT NULL,
    tag           date NOT NULL,
    von           time NOT NULL,
    bis           time,                             -- leer: eingestempelt, noch nicht gegangen
    herkunft      text NOT NULL CHECK (herkunft IN ('stempel', 'nachtrag')),
    korrigiert    boolean NOT NULL DEFAULT false,   -- der alte Wert wird nicht aufbewahrt (ZE3); die Begründung steht am Antrag
    angelegt_am   timestamptz NOT NULL DEFAULT clock_timestamp(),
    angelegt_von  text NOT NULL
);
CREATE INDEX abschnitt_person_tag ON zeit.abschnitt (person, tag);
CREATE UNIQUE INDEX abschnitt_offen ON zeit.abschnitt (person) WHERE bis IS NULL;

CREATE TABLE zeit.antrag (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    person          uuid NOT NULL,
    tag             date NOT NULL,
    art             text NOT NULL CHECK (art IN ('nachtrag', 'korrektur', 'entfernen')),
    abschnitt       uuid,
    von             time, bis time,                 -- nach der Entscheidung geleert (ZE3)
    begruendung     text NOT NULL CHECK (length(trim(begruendung)) BETWEEN 1 AND 200),
    zustand         text NOT NULL DEFAULT 'offen' CHECK (zustand IN ('offen', 'angenommen', 'abgelehnt')),
    grund           text CHECK (grund IS NULL OR length(grund) <= 200),
    angelegt_am     timestamptz NOT NULL DEFAULT clock_timestamp(),
    angelegt_von    text NOT NULL,
    entschieden_am  timestamptz,
    entschieden_von text
);
CREATE INDEX antrag_person ON zeit.antrag (person, tag);

-- Freigabe eines einzelnen Tages: Arbeit außerhalb der Regelarbeitstage, Arbeit über die Höchstzeit hinaus.
CREATE TABLE zeit.tagesfreigabe (
    person          uuid NOT NULL,
    tag             date NOT NULL,
    art             text NOT NULL CHECK (art IN ('ausserhalb', 'ueber_hoechst')),
    genehmigt       boolean NOT NULL,
    grund           text CHECK (grund IS NULL OR length(grund) <= 200),
    entschieden_am  timestamptz NOT NULL DEFAULT clock_timestamp(),
    entschieden_von text NOT NULL,
    PRIMARY KEY (person, tag, art)
);

CREATE TABLE zeit.monat (
    person          uuid NOT NULL,
    monat           date NOT NULL CHECK (extract(day FROM monat) = 1),
    zustand         text NOT NULL CHECK (zustand IN ('bestaetigt', 'freigegeben')),
    bestaetigt_am   timestamptz, bestaetigt_von text,
    freigegeben_am  timestamptz, freigegeben_von text,
    PRIMARY KEY (person, monat)
);

-- ---------------------------------------------------------------------------
-- Hilfen
-- ---------------------------------------------------------------------------
CREATE FUNCTION zeit._fehler(p_text text, p_code text DEFAULT 'KM002') RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION USING ERRCODE = p_code, MESSAGE = p_text;
END $$;

CREATE FUNCTION zeit._min(p time) RETURNS integer LANGUAGE sql IMMUTABLE AS $$
    SELECT (extract(hour FROM p) * 60 + extract(minute FROM p))::integer
$$;

-- Minuten, die [a1, a2) und [b1, b2) gemeinsam haben
CREATE FUNCTION zeit._gemeinsam(a1 integer, a2 integer, b1 integer, b2 integer) RETURNS integer LANGUAGE sql IMMUTABLE AS $$
    SELECT greatest(0, least(a2, b2) - greatest(a1, b1))
$$;

-- Jetzt in der Ortszeit des Mandanten, auf die Minute
CREATE FUNCTION zeit._jetzt() RETURNS timestamp LANGUAGE sql STABLE AS $$
    SELECT date_trunc('minute', clock_timestamp() AT TIME ZONE (SELECT zeitzone FROM zeit.einstellung))
$$;

CREATE FUNCTION zeit._uhrzeit(p jsonb, p_feld text) RETURNS time LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE t text := nullif(trim(p->>p_feld), '');
BEGIN
    IF t IS NULL THEN RETURN NULL; END IF;
    IF t !~ '^([01][0-9]|2[0-3]):[0-5][0-9]$' THEN PERFORM zeit._fehler(format('%s: Uhrzeit als HH:MM erwartet', p_feld)); END IF;
    RETURN t::time;
END $$;

-- Ein abgeschlossener Monat nimmt nichts mehr an – wenn der Mandant den Abschluss nutzt.
CREATE FUNCTION zeit._offen_verlangen(p_person uuid, p_tag date) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    IF (SELECT monatsabschluss FROM zeit.einstellung) <> 'aus'
       AND EXISTS (SELECT 1 FROM zeit.monat WHERE person = p_person AND monat = date_trunc('month', p_tag)::date) THEN
        PERFORM zeit._fehler('Der Monat ist abgeschlossen – erst wieder öffnen lassen', 'KM003');
    END IF;
END $$;

-- Zwei Abschnitte eines Tages überschneiden sich nicht
CREATE FUNCTION zeit._frei_verlangen(p_person uuid, p_tag date, p_von time, p_bis time, p_ausser uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE s integer := zeit._min(p_von); e integer := zeit._min(p_bis) + CASE WHEN p_bis <= p_von THEN 1440 ELSE 0 END;
BEGIN
    IF EXISTS (SELECT 1 FROM zeit.abschnitt a WHERE a.person = p_person AND a.tag = p_tag AND a.id IS DISTINCT FROM p_ausser AND a.bis IS NOT NULL
                  AND zeit._gemeinsam(s, e, zeit._min(a.von), zeit._min(a.bis) + CASE WHEN a.bis <= a.von THEN 1440 ELSE 0 END) > 0) THEN
        PERFORM zeit._fehler('Der Abschnitt überschneidet sich mit einem anderen dieses Tages', 'KM003');
    END IF;
END $$;

-- ---------------------------------------------------------------------------
-- Stempeln
-- ---------------------------------------------------------------------------
CREATE FUNCTION zeit.stempeln(p_subjekt text, p_person uuid, p_art text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, zeit, pg_temp AS $$
DECLARE jetzt timestamp := zeit._jetzt(); a zeit.abschnitt; neu uuid;
BEGIN
    SELECT * INTO a FROM zeit.abschnitt WHERE person = p_person AND bis IS NULL FOR UPDATE;
    IF p_art = 'kommen' THEN
        IF FOUND THEN PERFORM zeit._fehler('Du bist schon eingestempelt', 'KM003'); END IF;
        PERFORM zeit._offen_verlangen(p_person, jetzt::date);
        INSERT INTO zeit.abschnitt (person, tag, von, herkunft, angelegt_von) VALUES (p_person, jetzt::date, jetzt::time, 'stempel', p_subjekt) RETURNING id INTO neu;
    ELSIF p_art = 'gehen' THEN
        IF NOT FOUND THEN PERFORM zeit._fehler('Du bist nicht eingestempelt', 'KM003'); END IF;
        IF jetzt - (a.tag + a.von) > interval '24 hours' THEN
            PERFORM zeit._fehler('Der offene Abschnitt ist älter als einen Tag – bitte mit einer Korrektur schließen', 'KM003');
        END IF;
        IF jetzt::time = a.von AND jetzt::date = a.tag THEN
            DELETE FROM zeit.abschnitt WHERE id = a.id;      -- in derselben Minute gekommen und gegangen: kein Abschnitt
        ELSE
            UPDATE zeit.abschnitt SET bis = jetzt::time WHERE id = a.id;
        END IF;
        neu := a.id;
    ELSE
        PERFORM zeit._fehler('Kommen oder Gehen');
    END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'zeit', 'zeit.gestempelt', jsonb_build_object('person', p_person::text, 'tag', jetzt::date::text));
    RETURN neu;
END $$;

-- ---------------------------------------------------------------------------
-- Nachtrag und Korrektur (ZE3)
-- ---------------------------------------------------------------------------
CREATE FUNCTION zeit._antrag_anwenden(p_subjekt text, p_id uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE x zeit.antrag;
BEGIN
    SELECT * INTO x FROM zeit.antrag WHERE id = p_id FOR UPDATE;
    PERFORM zeit._offen_verlangen(x.person, x.tag);
    IF x.art = 'nachtrag' THEN
        PERFORM zeit._frei_verlangen(x.person, x.tag, x.von, x.bis, NULL);
        INSERT INTO zeit.abschnitt (person, tag, von, bis, herkunft, angelegt_von) VALUES (x.person, x.tag, x.von, x.bis, 'nachtrag', x.angelegt_von);
    ELSIF x.art = 'korrektur' THEN
        PERFORM zeit._frei_verlangen(x.person, x.tag, x.von, x.bis, x.abschnitt);
        UPDATE zeit.abschnitt SET von = x.von, bis = x.bis, korrigiert = true WHERE id = x.abschnitt AND person = x.person;
        IF NOT FOUND THEN PERFORM zeit._fehler('Den Abschnitt gibt es nicht mehr', 'KM003'); END IF;
    ELSE
        DELETE FROM zeit.abschnitt WHERE id = x.abschnitt AND person = x.person;
        IF NOT FOUND THEN PERFORM zeit._fehler('Den Abschnitt gibt es nicht mehr', 'KM003'); END IF;
    END IF;
    -- die Begründung bleibt, die Werte nicht
    UPDATE zeit.antrag SET zustand = 'angenommen', von = NULL, bis = NULL, entschieden_am = clock_timestamp(), entschieden_von = p_subjekt WHERE id = p_id;
END $$;

-- p: art (nachtrag | korrektur | entfernen), tag, abschnitt, von, bis, begruendung. p_sofort: das Personal trägt ein.
CREATE FUNCTION zeit.antrag_stellen(p_subjekt text, p_person uuid, p jsonb, p_sofort boolean)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, zeit, pg_temp AS $$
DECLARE neu uuid; v_art text := p->>'art'; v_tag date; v_von time := zeit._uhrzeit(p, 'von'); v_bis time := zeit._uhrzeit(p, 'bis');
        v_ab uuid := nullif(p->>'abschnitt', '')::uuid; a zeit.abschnitt; sofort boolean;
BEGIN
    IF v_art IS NULL OR v_art NOT IN ('nachtrag', 'korrektur', 'entfernen') THEN PERFORM zeit._fehler('Nachtrag, Korrektur oder Entfernen'); END IF;
    IF nullif(trim(p->>'begruendung'), '') IS NULL OR length(p->>'begruendung') > 200 THEN PERFORM zeit._fehler('Die Begründung fehlt (höchstens 200 Zeichen)'); END IF;
    IF v_art = 'nachtrag' THEN
        IF coalesce(p->>'tag', '') !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN PERFORM zeit._fehler('Der Tag fehlt'); END IF;
        v_tag := (p->>'tag')::date;
    ELSE
        SELECT * INTO a FROM zeit.abschnitt WHERE id = v_ab AND person = p_person;
        IF NOT FOUND THEN PERFORM zeit._fehler('Abschnitt unbekannt', 'KM003'); END IF;
        v_tag := a.tag;
    END IF;
    IF v_art <> 'entfernen' AND (v_von IS NULL OR v_bis IS NULL OR v_von = v_bis) THEN PERFORM zeit._fehler('Beginn und Ende gehören dazu'); END IF;
    IF v_tag > zeit._jetzt()::date THEN PERFORM zeit._fehler('Der Tag liegt in der Zukunft'); END IF;
    PERFORM zeit._offen_verlangen(p_person, v_tag);
    IF EXISTS (SELECT 1 FROM zeit.antrag WHERE person = p_person AND zustand = 'offen' AND ((v_ab IS NOT NULL AND abschnitt = v_ab) OR (v_ab IS NULL AND art = 'nachtrag' AND tag = v_tag AND von = v_von))) THEN
        PERFORM zeit._fehler('Dazu wartet schon ein Antrag auf seine Entscheidung', 'KM003');
    END IF;
    INSERT INTO zeit.antrag (person, tag, art, abschnitt, von, bis, begruendung, angelegt_von)
    VALUES (p_person, v_tag, v_art, v_ab, CASE WHEN v_art <> 'entfernen' THEN v_von END, CASE WHEN v_art <> 'entfernen' THEN v_bis END, trim(p->>'begruendung'), p_subjekt)
    RETURNING id INTO neu;
    sofort := coalesce(p_sofort, false) OR coalesce((SELECT korrektur FROM zeit.profil WHERE person = p_person), 'freigabe') = 'sofort';
    IF sofort THEN PERFORM zeit._antrag_anwenden(p_subjekt, neu); END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'zeit', CASE WHEN sofort THEN 'zeit.berichtigt' ELSE 'zeit.antrag_gestellt' END,
        jsonb_build_object('person', p_person::text, 'antrag', neu::text, 'tag', v_tag::text));
    RETURN neu;
END $$;

CREATE FUNCTION zeit.antrag_entscheiden(p_subjekt text, p_id uuid, p_annehmen boolean, p_grund text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, zeit, pg_temp AS $$
DECLARE x zeit.antrag;
BEGIN
    SELECT * INTO x FROM zeit.antrag WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM zeit._fehler('Antrag unbekannt', 'KM003'); END IF;
    IF x.zustand <> 'offen' THEN PERFORM zeit._fehler('Über den Antrag ist schon entschieden', 'KM003'); END IF;
    IF coalesce(p_annehmen, false) THEN
        PERFORM zeit._antrag_anwenden(p_subjekt, p_id);
    ELSE
        IF nullif(trim(p_grund), '') IS NULL OR length(p_grund) > 200 THEN PERFORM zeit._fehler('Eine Ablehnung braucht einen Grund (höchstens 200 Zeichen)'); END IF;
        UPDATE zeit.antrag SET zustand = 'abgelehnt', von = NULL, bis = NULL, grund = trim(p_grund), entschieden_am = clock_timestamp(), entschieden_von = p_subjekt WHERE id = p_id;
    END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'zeit', CASE WHEN coalesce(p_annehmen, false) THEN 'zeit.antrag_angenommen' ELSE 'zeit.antrag_abgelehnt' END,
        jsonb_build_object('person', x.person::text, 'antrag', p_id::text, 'tag', x.tag::text));
END $$;

-- ---------------------------------------------------------------------------
-- Freigabe eines Tages, Abschluss des Monats
-- ---------------------------------------------------------------------------
CREATE FUNCTION zeit.tag_freigeben(p_subjekt text, p_person uuid, p_tag date, p_art text, p_genehmigt boolean, p_grund text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, zeit, pg_temp AS $$
BEGIN
    IF p_art IS NULL OR p_art NOT IN ('ausserhalb', 'ueber_hoechst') OR p_tag IS NULL OR p_genehmigt IS NULL THEN PERFORM zeit._fehler('Tag, Art und Entscheidung gehören dazu'); END IF;
    IF NOT p_genehmigt AND nullif(trim(p_grund), '') IS NULL THEN PERFORM zeit._fehler('Eine Ablehnung braucht einen Grund'); END IF;
    IF length(coalesce(p_grund, '')) > 200 THEN PERFORM zeit._fehler('Grund: höchstens 200 Zeichen'); END IF;
    PERFORM zeit._offen_verlangen(p_person, p_tag);
    INSERT INTO zeit.tagesfreigabe (person, tag, art, genehmigt, grund, entschieden_von) VALUES (p_person, p_tag, p_art, p_genehmigt, nullif(trim(p_grund), ''), p_subjekt)
    ON CONFLICT (person, tag, art) DO UPDATE SET genehmigt = EXCLUDED.genehmigt, grund = EXCLUDED.grund, entschieden_am = clock_timestamp(), entschieden_von = EXCLUDED.entschieden_von;
    PERFORM mandant.modul_protokoll(p_subjekt, 'zeit', CASE WHEN p_genehmigt THEN 'zeit.tag_genehmigt' ELSE 'zeit.tag_abgelehnt' END,
        jsonb_build_object('person', p_person::text, 'tag', p_tag::text, 'art', p_art));
END $$;

-- p_aktion: bestaetigen (die Person), freigeben (Vorgesetzter oder Personal), oeffnen (wieder bearbeitbar machen)
CREATE FUNCTION zeit.monat_setzen(p_subjekt text, p_person uuid, p_monat date, p_aktion text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, zeit, pg_temp AS $$
DECLARE m zeit.monat; modus text := (SELECT monatsabschluss FROM zeit.einstellung);
BEGIN
    IF p_monat IS NULL OR extract(day FROM p_monat) <> 1 THEN PERFORM zeit._fehler('Monat fehlt'); END IF;
    IF modus = 'aus' THEN PERFORM zeit._fehler('Der Mandant nutzt keinen Monatsabschluss', 'KM003'); END IF;
    SELECT * INTO m FROM zeit.monat WHERE person = p_person AND monat = p_monat FOR UPDATE;
    IF p_aktion = 'bestaetigen' THEN
        IF FOUND THEN PERFORM zeit._fehler('Der Monat ist schon bestätigt', 'KM003'); END IF;
        IF p_monat + interval '1 month' > zeit._jetzt() THEN PERFORM zeit._fehler('Bestätigen lässt sich ein Monat erst, wenn er vorbei ist'); END IF;
        IF EXISTS (SELECT 1 FROM zeit.abschnitt WHERE person = p_person AND bis IS NULL AND date_trunc('month', tag) = p_monat) THEN
            PERFORM zeit._fehler('Im Monat ist ein Abschnitt noch offen – erst schließen', 'KM003');
        END IF;
        IF EXISTS (SELECT 1 FROM zeit.antrag WHERE person = p_person AND zustand = 'offen' AND date_trunc('month', tag) = p_monat) THEN
            PERFORM zeit._fehler('Im Monat wartet noch ein Antrag auf seine Entscheidung', 'KM003');
        END IF;
        INSERT INTO zeit.monat (person, monat, zustand, bestaetigt_am, bestaetigt_von) VALUES (p_person, p_monat, 'bestaetigt', clock_timestamp(), p_subjekt);
    ELSIF p_aktion = 'freigeben' THEN
        IF modus <> 'freigabe' THEN PERFORM zeit._fehler('Der Mandant nutzt keine Freigabe des Monats', 'KM003'); END IF;
        IF NOT FOUND THEN PERFORM zeit._fehler('Erst bestätigt die Person ihren Monat', 'KM003'); END IF;
        IF m.zustand = 'freigegeben' THEN PERFORM zeit._fehler('Der Monat ist schon freigegeben', 'KM003'); END IF;
        UPDATE zeit.monat SET zustand = 'freigegeben', freigegeben_am = clock_timestamp(), freigegeben_von = p_subjekt WHERE person = p_person AND monat = p_monat;
    ELSIF p_aktion = 'oeffnen' THEN
        IF NOT FOUND THEN PERFORM zeit._fehler('Der Monat ist nicht abgeschlossen', 'KM003'); END IF;
        DELETE FROM zeit.monat WHERE person = p_person AND monat = p_monat;
    ELSE
        PERFORM zeit._fehler('Bestätigen, freigeben oder öffnen');
    END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'zeit', 'zeit.monat_' || CASE p_aktion WHEN 'bestaetigen' THEN 'bestaetigt' WHEN 'freigeben' THEN 'freigegeben' ELSE 'geoeffnet' END,
        jsonb_build_object('person', p_person::text, 'monat', to_char(p_monat, 'YYYY-MM')));
END $$;

-- ---------------------------------------------------------------------------
-- Profil und Regeln
-- ---------------------------------------------------------------------------
-- p: modell, regeltage (sieben Wahrheitswerte oder null), rahmen_von, rahmen_bis, kern_von, kern_bis, korrektur, anfangssaldo, beginn
CREATE FUNCTION zeit.profil_setzen(p_subjekt text, p_person uuid, p jsonb)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, zeit, pg_temp AS $$
DECLARE v_tage boolean[];
BEGIN
    IF jsonb_typeof(p->'regeltage') = 'array' THEN
        IF jsonb_array_length(p->'regeltage') <> 7 OR EXISTS (SELECT 1 FROM jsonb_array_elements(p->'regeltage') e WHERE jsonb_typeof(e) <> 'boolean') THEN
            PERFORM zeit._fehler('Regelarbeitstage: sieben Angaben, Montag bis Sonntag');
        END IF;
        SELECT array_agg(e::text::boolean ORDER BY nr) INTO v_tage FROM jsonb_array_elements(p->'regeltage') WITH ORDINALITY AS a(e, nr);
    END IF;
    INSERT INTO zeit.profil (person, modell, regeltage, rahmen_von, rahmen_bis, kern_von, kern_bis, korrektur, anfangssaldo, beginn, geaendert_von)
    VALUES (p_person, coalesce(p->>'modell', 'fest'), v_tage, zeit._uhrzeit(p, 'rahmen_von'), zeit._uhrzeit(p, 'rahmen_bis'), zeit._uhrzeit(p, 'kern_von'), zeit._uhrzeit(p, 'kern_bis'),
            coalesce(p->>'korrektur', 'freigabe'), coalesce((p->>'anfangssaldo')::integer, 0), nullif(p->>'beginn', '')::date, p_subjekt)
    ON CONFLICT (person) DO UPDATE SET modell = EXCLUDED.modell, regeltage = EXCLUDED.regeltage, rahmen_von = EXCLUDED.rahmen_von, rahmen_bis = EXCLUDED.rahmen_bis,
           kern_von = EXCLUDED.kern_von, kern_bis = EXCLUDED.kern_bis, korrektur = EXCLUDED.korrektur, anfangssaldo = EXCLUDED.anfangssaldo, beginn = EXCLUDED.beginn,
           geaendert_am = clock_timestamp(), geaendert_von = EXCLUDED.geaendert_von;
    PERFORM mandant.modul_protokoll(p_subjekt, 'zeit', 'zeit.profil_gesetzt', jsonb_build_object('person', p_person::text));
EXCEPTION WHEN check_violation OR invalid_text_representation OR invalid_datetime_format OR datetime_field_overflow THEN
    PERFORM zeit._fehler('Zeitprofil: ein Wert ist unbekannt, der Rahmen oder die Kernzeit ist unvollständig oder endet vor dem Beginn');
END $$;

-- p: pausen [{ab, pause}], hoechst_minuten, nacht_von, nacht_bis, monatsabschluss, zeitzone
CREATE FUNCTION zeit.einstellung_setzen(p_subjekt text, p jsonb)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, zeit, pg_temp AS $$
DECLARE e jsonb; v_pausen jsonb := '[]';
BEGIN
    IF jsonb_typeof(p->'pausen') IS DISTINCT FROM 'array' OR jsonb_array_length(p->'pausen') > 6 THEN PERFORM zeit._fehler('Pausen: eine Liste aus höchstens sechs Stufen'); END IF;
    FOR e IN SELECT * FROM jsonb_array_elements(p->'pausen') LOOP
        IF coalesce(e->>'ab', '') !~ '^[0-9]{1,4}$' OR coalesce(e->>'pause', '') !~ '^[0-9]{1,3}$' OR (e->>'ab')::integer NOT BETWEEN 1 AND 1440 OR (e->>'pause')::integer NOT BETWEEN 1 AND 240 THEN
            PERFORM zeit._fehler('Pausen: je Stufe „ab“ und „pause“ in ganzen Minuten');
        END IF;
        v_pausen := v_pausen || jsonb_build_object('ab', (e->>'ab')::integer, 'pause', (e->>'pause')::integer);
    END LOOP;
    IF p ? 'zeitzone' AND NOT EXISTS (SELECT 1 FROM pg_timezone_names WHERE name = p->>'zeitzone') THEN PERFORM zeit._fehler('Zeitzone unbekannt'); END IF;
    UPDATE zeit.einstellung SET pausen = (SELECT coalesce(jsonb_agg(x ORDER BY (x->>'ab')::integer), '[]') FROM jsonb_array_elements(v_pausen) x),
           hoechst_minuten = coalesce((p->>'hoechst_minuten')::integer, hoechst_minuten), nacht_von = coalesce(zeit._uhrzeit(p, 'nacht_von'), nacht_von),
           nacht_bis = coalesce(zeit._uhrzeit(p, 'nacht_bis'), nacht_bis), monatsabschluss = coalesce(p->>'monatsabschluss', monatsabschluss),
           zeitzone = coalesce(p->>'zeitzone', zeitzone), geaendert_am = clock_timestamp(), geaendert_von = p_subjekt;
    PERFORM mandant.modul_protokoll(p_subjekt, 'zeit', 'zeit.einstellung_gesetzt', '{}'::jsonb, NULL,
        (SELECT jsonb_build_object('pausen', pausen, 'hoechst_minuten', hoechst_minuten, 'nacht_von', nacht_von::text, 'nacht_bis', nacht_bis::text, 'monatsabschluss', monatsabschluss, 'zeitzone', zeitzone) FROM zeit.einstellung));
EXCEPTION WHEN check_violation OR invalid_text_representation THEN
    PERFORM zeit._fehler('Regeln: ein Wert ist unbekannt oder liegt außerhalb');
END $$;

-- ---------------------------------------------------------------------------
-- Rechnen: die Tage einer Person
-- ---------------------------------------------------------------------------
-- Je Tag: Abschnitte, Arbeit (brutto), gestempelte Pause, Abzug, was zählt, Soll, Gutschrift aus bezahlter Abwesenheit,
-- Saldo, Minuten in den Fenstern Nacht/Sonntag/Feiertag, Hinweise und was noch eine Freigabe braucht.
CREATE FUNCTION zeit.tage(p_person uuid, p_von date, p_bis date)
RETURNS TABLE (tag date, abschnitte jsonb, brutto integer, pause integer, abzug integer, gezaehlt integer, soll integer, gutschrift integer, saldo integer,
               nacht integer, sonntag integer, feiertag_minuten integer, feiertag text, frei text, abwesenheit numeric, arbeitstag boolean,
               hinweise text[], braucht text[], laeuft boolean)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, zeit, pg_temp AS $$
DECLARE e zeit.einstellung; pr zeit.profil; t record; a record; s integer; en integer; erster integer; letzter integer; netto integer; pflicht integer; stufe integer;
        regel boolean; nv integer; nb integer; fg boolean; heute date := zeit._jetzt()::date; k1 integer; k2 integer; gedeckt integer;
BEGIN
    SELECT * INTO e FROM zeit.einstellung;
    SELECT * INTO pr FROM zeit.profil WHERE person = p_person;
    nv := zeit._min(e.nacht_von); nb := zeit._min(e.nacht_bis);
    FOR t IN SELECT * FROM personal.api_tage(p_person, p_von, p_bis) LOOP
        tag := t.tag; feiertag := t.feiertag; frei := t.frei; abwesenheit := t.abwesenheit;
        brutto := 0; nacht := 0; hinweise := '{}'; braucht := '{}'; laeuft := false; erster := NULL; letzter := NULL; abschnitte := '[]'; gedeckt := 0;
        regel := coalesce(pr.regeltage[extract(isodow FROM t.tag)::integer], t.soll_minuten > 0);
        arbeitstag := regel AND t.feiertag IS NULL AND t.frei IS NULL;
        soll := CASE WHEN arbeitstag THEN t.soll_minuten ELSE 0 END;
        -- bezahlte Abwesenheit schreibt das Soll gut, unbezahlte nimmt es weg
        gutschrift := CASE WHEN t.bezahlt THEN round(soll * t.abwesenheit)::integer ELSE 0 END;
        IF NOT t.bezahlt THEN soll := round(soll * (1 - t.abwesenheit))::integer; END IF;
        FOR a IN SELECT x.id, x.von, x.bis, x.herkunft, x.korrigiert FROM zeit.abschnitt x WHERE x.person = p_person AND x.tag = t.tag ORDER BY x.von LOOP
            abschnitte := abschnitte || jsonb_build_object('id', a.id::text, 'von', to_char(a.von, 'HH24:MI'), 'bis', to_char(a.bis, 'HH24:MI'), 'herkunft', a.herkunft, 'korrigiert', a.korrigiert);
            IF a.bis IS NULL THEN
                laeuft := true;
                IF t.tag < heute THEN hinweise := hinweise || 'Gehen fehlt'::text; END IF;
                CONTINUE;
            END IF;
            s := zeit._min(a.von); en := zeit._min(a.bis) + CASE WHEN a.bis <= a.von THEN 1440 ELSE 0 END;
            -- Gleitzeit mit Rahmen: Was außerhalb liegt, zählt nicht
            IF pr.modell = 'gleitzeit' AND pr.rahmen_von IS NOT NULL THEN
                IF s < zeit._min(pr.rahmen_von) OR en > zeit._min(pr.rahmen_bis) THEN hinweise := hinweise || 'außerhalb des Rahmens'::text; END IF;
                s := greatest(s, zeit._min(pr.rahmen_von)); en := least(en, zeit._min(pr.rahmen_bis));
                IF en <= s THEN CONTINUE; END IF;
            END IF;
            erster := least(coalesce(erster, s), s); letzter := greatest(coalesce(letzter, en), en);   -- nach dem Rahmen: Was außerhalb liegt, ist keine Pause
            brutto := brutto + (en - s);
            nacht := nacht + zeit._gemeinsam(s, en, 0, nb) + zeit._gemeinsam(s, en, nv, 1440 + nb) + zeit._gemeinsam(s, en, 1440 + nv, 2880);
            IF pr.kern_von IS NOT NULL THEN gedeckt := gedeckt + zeit._gemeinsam(s, en, zeit._min(pr.kern_von), zeit._min(pr.kern_bis)); END IF;
        END LOOP;
        pause := CASE WHEN erster IS NULL THEN 0 ELSE greatest(0, (letzter - erster) - brutto) END;
        -- Pause nach der Regel: abgezogen wird nur, was an Pause fehlt – und nie unter die Schwelle der Stufe
        SELECT max((x->>'pause')::integer), max((x->>'ab')::integer) INTO pflicht, stufe FROM jsonb_array_elements(e.pausen) x WHERE brutto > (x->>'ab')::integer;
        abzug := least(greatest(0, coalesce(pflicht, 0) - pause), greatest(0, brutto - coalesce(stufe, 0)));
        netto := brutto - abzug;
        gezaehlt := netto;
        fg := NULL;
        IF netto > e.hoechst_minuten THEN
            SELECT f.genehmigt INTO fg FROM zeit.tagesfreigabe f WHERE f.person = p_person AND f.tag = t.tag AND f.art = 'ueber_hoechst';
            IF NOT FOUND THEN braucht := braucht || 'ueber_hoechst'::text; END IF;
            IF NOT coalesce(fg, false) THEN gezaehlt := e.hoechst_minuten; hinweise := hinweise || 'über der Höchstzeit – ohne Sonderfreigabe gekappt'::text; END IF;
        END IF;
        IF brutto > 0 AND NOT arbeitstag THEN
            fg := NULL;
            SELECT f.genehmigt INTO fg FROM zeit.tagesfreigabe f WHERE f.person = p_person AND f.tag = t.tag AND f.art = 'ausserhalb';
            IF NOT FOUND THEN braucht := braucht || 'ausserhalb'::text; END IF;
            IF NOT coalesce(fg, false) THEN gezaehlt := 0; hinweise := hinweise || 'kein Regelarbeitstag – zählt erst mit Genehmigung'::text; END IF;
        END IF;
        IF pr.kern_von IS NOT NULL AND arbeitstag AND brutto > 0 AND t.abwesenheit = 0 AND gedeckt < zeit._min(pr.kern_bis) - zeit._min(pr.kern_von) - coalesce(pflicht, 0) THEN
            hinweise := hinweise || 'Kernzeit nicht abgedeckt'::text;
        END IF;
        sonntag := CASE WHEN extract(isodow FROM t.tag) = 7 THEN brutto ELSE 0 END;
        feiertag_minuten := CASE WHEN t.feiertag IS NOT NULL THEN brutto ELSE 0 END;
        saldo := gezaehlt + gutschrift - soll;
        RETURN NEXT;
    END LOOP;
END $$;

-- Stand des Zeitkontos am Ende eines Tages: Anfangssaldo und die Salden aller Tage seit Beginn. Der laufende Tag zählt nicht.
CREATE FUNCTION zeit.konto(p_person uuid, p_bis date) RETURNS integer
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, zeit, pg_temp AS $$
DECLARE pr zeit.profil; ab date; bis date := least(p_bis, zeit._jetzt()::date - 1); summe integer := 0; v date;
BEGIN
    SELECT * INTO pr FROM zeit.profil WHERE person = p_person;
    ab := coalesce(pr.beginn, (SELECT min(tag) FROM zeit.abschnitt WHERE person = p_person));
    IF ab IS NULL OR ab > bis THEN RETURN coalesce(pr.anfangssaldo, 0); END IF;
    v := ab;
    WHILE v <= bis LOOP      -- in Stücken: personal.api_tage nimmt höchstens rund ein Jahr
        summe := summe + coalesce((SELECT sum(x.saldo) FROM zeit.tage(p_person, v, least(bis, v + 364)) x), 0);
        v := v + 365;
    END LOOP;
    RETURN coalesce(pr.anfangssaldo, 0) + summe;
END $$;

-- Für die Lohnvorbereitung (personal._ist): Ist- und Überstunden und die Stunden je Fenster eines Monats. Nutzt der
-- Mandant den Monatsabschluss, zählt nur ein abgeschlossener Monat – sonst „Nachweis fehlt“ (PE11).
CREATE FUNCTION zeit.lohn_ist(p_person uuid, p_monat date) RETURNS jsonb
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, zeit, pg_temp AS $$
DECLARE modus text := (SELECT monatsabschluss FROM zeit.einstellung); z text; r record;
BEGIN
    SELECT zustand INTO z FROM zeit.monat WHERE person = p_person AND monat = p_monat;
    IF modus = 'freigabe' AND z IS DISTINCT FROM 'freigegeben' THEN
        RETURN jsonb_build_object('ist_minuten', NULL, 'hinweis', 'Nachweis fehlt – das Monatsblatt ist nicht freigegeben');
    ELSIF modus = 'person' AND z IS NULL THEN
        RETURN jsonb_build_object('ist_minuten', NULL, 'hinweis', 'Nachweis fehlt – die Person hat ihren Monat nicht bestätigt');
    END IF;
    SELECT coalesce(sum(x.gezaehlt), 0) AS ist, coalesce(sum(x.gezaehlt + x.gutschrift - x.soll), 0) AS saldo, coalesce(sum(x.nacht), 0) AS nacht,
           coalesce(sum(x.sonntag), 0) AS sonntag, coalesce(sum(x.feiertag_minuten), 0) AS feiertag, count(*) FILTER (WHERE cardinality(x.braucht) > 0) AS offen
      INTO r FROM zeit.tage(p_person, p_monat, (p_monat + interval '1 month' - interval '1 day')::date) x;
    RETURN jsonb_build_object('ist_minuten', r.ist, 'ueber_minuten', greatest(0, r.saldo),
        'zeitarten', jsonb_build_object('nacht', r.nacht, 'sonntag', r.sonntag, 'feiertag', r.feiertag, 'ueberstunden', greatest(0, r.saldo)),
        'hinweis', CASE WHEN r.offen > 0 THEN format('%s Tage warten auf eine Freigabe', r.offen) WHEN modus = 'aus' THEN 'ohne Monatsabschluss' END);
END $$;

-- ---------------------------------------------------------------------------
-- Zugriff
-- ---------------------------------------------------------------------------
REVOKE ALL ON ALL TABLES IN SCHEMA zeit FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON ALL TABLES IN SCHEMA zeit TO kumero_app;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA zeit FROM PUBLIC;
GRANT EXECUTE ON FUNCTION zeit._jetzt(), zeit.stempeln(text, uuid, text), zeit.antrag_stellen(text, uuid, jsonb, boolean), zeit.antrag_entscheiden(text, uuid, boolean, text),
    zeit.tag_freigeben(text, uuid, date, text, boolean, text), zeit.monat_setzen(text, uuid, date, text), zeit.profil_setzen(text, uuid, jsonb),
    zeit.einstellung_setzen(text, jsonb), zeit.tage(uuid, date, date), zeit.konto(uuid, date) TO kumero_app;
