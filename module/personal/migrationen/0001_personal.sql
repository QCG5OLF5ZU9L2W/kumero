-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul personal, Migration 0001: Personen, Verträge, Fristen und Nachweise am Mandanten (PERSONAL.md Stufe 1, F136)
--
-- Das Modul gehört zum Mandanten (F135): Dieses Schema liegt in der Datenbank des Mandanten, nicht in der eines Buchs.
-- Die Daten sind nach **Datenklassen** getrennt abgelegt (SCHLUESSEL.md Abschnitt 6), jede mit eigenem Recht, damit
-- eine spätere Verschlüsselung je Klasse nichts umbauen muss:
--   Stamm    personal.person   – wer ist es: Personalnummer, Name, Vorgesetzter, Nutzer, Austritt
--   Privat   personal.privat   – Geburtsdatum, Anschrift, private Kontaktdaten, Notfallkontakt (auch: personal.vorschlag)
--   Vertrag  personal.vertrag  – Fassungen mit „gültig ab“, ohne Vergütung
--   Fristen  personal.frist    – was wann fällig ist
-- Vergütung und Lohnmerkmale (Bank, Steuer, Sozialversicherung) führt diese Stufe nicht.
--
-- Geschrieben wird nur über Funktionen (F64). Das Protokoll nennt IDs und die Namen geänderter Felder, nie einen Wert
-- (PERSONAL.md Abschnitt 8). Jedes Objekt trägt seine eigene Löschfrist (`loeschen_ab`, PE12); kumero gibt keine vor.
-- Das Lesekonto der Datenbank (kumero_lesen) sieht dieses Schema nicht.

CREATE SCHEMA personal AUTHORIZATION kumero_owner;
REVOKE ALL ON SCHEMA personal FROM PUBLIC;
GRANT USAGE ON SCHEMA personal TO kumero_app;

CREATE TABLE personal.einstellung (
    einzig         boolean PRIMARY KEY DEFAULT true CHECK (einzig),
    warn_tage      integer NOT NULL DEFAULT 60 CHECK (warn_tage BETWEEN 0 AND 365),   -- Vorlauf für Fristen aus dem Vertrag
    geaendert_am   timestamptz NOT NULL DEFAULT now(),
    geaendert_von  text
);
INSERT INTO personal.einstellung DEFAULT VALUES;

-- Datenklasse Stamm
CREATE TABLE personal.person (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    personalnummer  text NOT NULL UNIQUE CHECK (personalnummer ~ '^[A-Za-z0-9][A-Za-z0-9._/-]{0,19}$'),
    familienname    text NOT NULL CHECK (length(trim(familienname)) BETWEEN 1 AND 100),
    vornamen        text CHECK (vornamen IS NULL OR length(trim(vornamen)) BETWEEN 1 AND 100),
    vorgesetzter    uuid REFERENCES personal.person CHECK (vorgesetzter IS DISTINCT FROM id),
    nutzer          uuid UNIQUE,                    -- kumero-Nutzer des Mandanten für den Selfservice (PE3); die ID verlässt den Mandanten nicht
    austritt        date,                           -- letzter Tag; danach gilt die Person als ausgeschieden
    angelegt_am     timestamptz NOT NULL DEFAULT now(),
    geaendert_am    timestamptz NOT NULL DEFAULT now(),
    geaendert_von   text NOT NULL,
    loeschen_ab     date
);
CREATE INDEX person_vorgesetzter ON personal.person (vorgesetzter);

-- Datenklasse Privat
CREATE TABLE personal.privat (
    person          uuid PRIMARY KEY REFERENCES personal.person ON DELETE CASCADE,
    geburtsdatum    date CHECK (geburtsdatum IS NULL OR geburtsdatum BETWEEN DATE '1900-01-01' AND DATE '2100-01-01'),
    strasse         text CHECK (strasse IS NULL OR length(strasse) <= 100),
    plz             text CHECK (plz IS NULL OR length(plz) <= 10),
    ort             text CHECK (ort IS NULL OR length(ort) <= 100),
    telefon         text CHECK (telefon IS NULL OR length(telefon) <= 40),
    email           text CHECK (email IS NULL OR (length(email) <= 254 AND email ~ '^[^@[:space:]]+@[^@[:space:]]+$')),
    notfall_name    text CHECK (notfall_name IS NULL OR length(notfall_name) <= 100),
    notfall_telefon text CHECK (notfall_telefon IS NULL OR length(notfall_telefon) <= 40),
    geaendert_am    timestamptz NOT NULL DEFAULT now(),
    geaendert_von   text NOT NULL,
    loeschen_ab     date
);

-- Datenklasse Vertrag: Fassungen, keine wird überschrieben (PE4). Es gilt die Fassung mit dem spätesten „gültig ab“,
-- das nicht in der Zukunft liegt; bei gleichem Tag die zuletzt angelegte – so wird ein Schreibfehler berichtigt.
CREATE TABLE personal.vertrag (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    person           uuid NOT NULL REFERENCES personal.person,
    gueltig_ab       date NOT NULL,
    art              text NOT NULL CHECK (art IN ('unbefristet', 'befristet', 'ausbildung', 'minijob', 'werkstudent')),
    funktion         text CHECK (funktion IS NULL OR length(funktion) <= 100),
    beginn           date NOT NULL,
    probezeit_bis    date,
    befristet_bis    date,
    kuendigungsfrist text CHECK (kuendigungsfrist IS NULL OR length(kuendigungsfrist) <= 200),
    wochenminuten    integer NOT NULL CHECK (wochenminuten BETWEEN 0 AND 6000),
    verteilung       integer[] NOT NULL CHECK (cardinality(verteilung) = 7 AND 0 <= ALL (verteilung) AND 1440 >= ALL (verteilung)),   -- Minuten Mo–So
    urlaubstage      numeric(4,1) CHECK (urlaubstage IS NULL OR urlaubstage BETWEEN 0 AND 99),
    lohnbuch         text CHECK (lohnbuch IS NULL OR lohnbuch ~ '^[a-z][a-z0-9-]{1,19}$'),   -- Kennung des Buchs, das den Lohn trägt (PE5)
    angelegt_am      timestamptz NOT NULL DEFAULT clock_timestamp(),
    angelegt_von     text NOT NULL,
    loeschen_ab      date,
    CHECK (art <> 'befristet' OR befristet_bis IS NOT NULL),
    CHECK (befristet_bis IS NULL OR befristet_bis >= beginn),
    CHECK (probezeit_bis IS NULL OR probezeit_bis >= beginn)
);
CREATE INDEX vertrag_person ON personal.vertrag (person, gueltig_ab DESC, angelegt_am DESC);

-- Datenklasse Fristen
CREATE TABLE personal.fristart (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name         text NOT NULL UNIQUE CHECK (length(trim(name)) BETWEEN 1 AND 60),
    warn_tage    integer NOT NULL DEFAULT 60 CHECK (warn_tage BETWEEN 0 AND 365),
    stillgelegt  boolean NOT NULL DEFAULT false
);
INSERT INTO personal.fristart (name, warn_tage) VALUES ('Unterweisung', 30), ('Einweisung', 30), ('Fortbildung', 60), ('Nachweis', 60);

CREATE TABLE personal.frist (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    person        uuid NOT NULL REFERENCES personal.person,
    art           uuid NOT NULL REFERENCES personal.fristart,
    faellig       date NOT NULL,
    bemerkung     text CHECK (bemerkung IS NULL OR length(bemerkung) <= 200),
    erledigt_am   timestamptz,
    erledigt_von  text,
    angelegt_am   timestamptz NOT NULL DEFAULT now(),
    angelegt_von  text NOT NULL,
    loeschen_ab   date
);
CREATE INDEX frist_offen ON personal.frist (faellig) WHERE erledigt_am IS NULL;

-- Änderungsvorschlag aus dem Selfservice (PE17): Die Person schlägt vor, das Personal übernimmt oder lehnt ab.
CREATE TABLE personal.vorschlag (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    person          uuid NOT NULL REFERENCES personal.person,
    felder          jsonb NOT NULL CHECK (jsonb_typeof(felder) = 'object'),
    zustand         text NOT NULL DEFAULT 'offen' CHECK (zustand IN ('offen', 'uebernommen', 'abgelehnt')),
    grund           text CHECK (grund IS NULL OR length(grund) <= 200),
    angelegt_am     timestamptz NOT NULL DEFAULT now(),
    angelegt_von    text NOT NULL,
    entschieden_am  timestamptz,
    entschieden_von text,
    loeschen_ab     date
);
CREATE INDEX vorschlag_offen ON personal.vorschlag (person) WHERE zustand = 'offen';

-- ---------------------------------------------------------------------------
-- Hilfen
-- ---------------------------------------------------------------------------
CREATE FUNCTION personal._fehler(p_text text, p_code text DEFAULT 'KM002') RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION USING ERRCODE = p_code, MESSAGE = p_text;
END $$;

-- Text aus einem Feld: beschnitten, leer wird NULL
CREATE FUNCTION personal._t(p jsonb, p_feld text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
    SELECT nullif(trim(p->>p_feld), '')
$$;

CREATE FUNCTION personal._datum(p jsonb, p_feld text) RETURNS date LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE t text := nullif(trim(p->>p_feld), '');
BEGIN
    IF t IS NULL THEN RETURN NULL; END IF;
    IF t !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN
        PERFORM personal._fehler(format('%s: Datum als JJJJ-MM-TT erwartet', p_feld));
    END IF;
    RETURN t::date;
EXCEPTION WHEN datetime_field_overflow OR invalid_datetime_format THEN
    PERFORM personal._fehler(format('%s: kein gültiges Datum', p_feld));
    RETURN NULL;
END $$;

-- Namen der Felder, in denen sich zwei Stände unterscheiden – für das Protokoll, ohne Werte
CREATE FUNCTION personal._geaendert(p_alt jsonb, p_neu jsonb) RETURNS jsonb LANGUAGE sql IMMUTABLE AS $$
    SELECT coalesce(jsonb_agg(k ORDER BY k), '[]'::jsonb)
      FROM (SELECT jsonb_object_keys(coalesce(p_alt, '{}') || coalesce(p_neu, '{}')) AS k) s
     WHERE (coalesce(p_alt, '{}') -> k) IS DISTINCT FROM (coalesce(p_neu, '{}') -> k)
$$;

-- Die geltende Fassung des Vertrags einer Person an einem Tag
CREATE FUNCTION personal.vertrag_am(p_person uuid, p_tag date DEFAULT current_date) RETURNS uuid LANGUAGE sql STABLE AS $$
    SELECT id FROM personal.vertrag WHERE person = p_person AND gueltig_ab <= p_tag
     ORDER BY gueltig_ab DESC, angelegt_am DESC LIMIT 1
$$;

-- ---------------------------------------------------------------------------
-- Person (Stamm) und private Angaben
-- ---------------------------------------------------------------------------
CREATE FUNCTION personal.person_setzen(p_subjekt text, p_id uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE alt personal.person; neu personal.person; v uuid := nullif(p->>'vorgesetzter', '')::uuid; n uuid := nullif(p->>'nutzer', '')::uuid;
        x uuid; tiefe integer := 0;
BEGIN
    IF personal._t(p, 'personalnummer') IS NULL OR personal._t(p, 'familienname') IS NULL THEN
        PERFORM personal._fehler('Personalnummer und Familienname gehören dazu');
    END IF;
    IF EXISTS (SELECT 1 FROM personal.person WHERE lower(personalnummer) = lower(personal._t(p, 'personalnummer')) AND id IS DISTINCT FROM p_id) THEN
        PERFORM personal._fehler('Diese Personalnummer gibt es schon', 'KM003');
    END IF;
    IF n IS NOT NULL AND EXISTS (SELECT 1 FROM personal.person WHERE nutzer = n AND id IS DISTINCT FROM p_id) THEN
        PERFORM personal._fehler('Dieser Nutzer ist schon mit einer anderen Person verknüpft', 'KM003');
    END IF;
    IF v IS NOT NULL AND NOT EXISTS (SELECT 1 FROM personal.person WHERE id = v) THEN
        PERFORM personal._fehler('Vorgesetzter unbekannt');
    END IF;
    -- kein Kreis: Der Vorgesetzte darf nicht unter der Person stehen
    x := v;
    WHILE x IS NOT NULL AND p_id IS NOT NULL LOOP
        IF x = p_id THEN PERFORM personal._fehler('Die Person stünde über sich selbst – der Vorgesetzte steht unter ihr'); END IF;
        tiefe := tiefe + 1;
        EXIT WHEN tiefe > 50;
        SELECT vorgesetzter INTO x FROM personal.person WHERE id = x;
    END LOOP;
    IF p_id IS NULL THEN
        INSERT INTO personal.person (personalnummer, familienname, vornamen, vorgesetzter, nutzer, austritt, geaendert_von)
        VALUES (personal._t(p, 'personalnummer'), personal._t(p, 'familienname'), personal._t(p, 'vornamen'), v, n, personal._datum(p, 'austritt'), p_subjekt)
        RETURNING * INTO neu;
        INSERT INTO personal.privat (person, geaendert_von) VALUES (neu.id, p_subjekt);
        PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.person_angelegt', jsonb_build_object('person', neu.id::text));
        RETURN neu.id;
    END IF;
    SELECT * INTO alt FROM personal.person WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Person unbekannt', 'KM003'); END IF;
    UPDATE personal.person SET personalnummer = personal._t(p, 'personalnummer'), familienname = personal._t(p, 'familienname'),
           vornamen = personal._t(p, 'vornamen'), vorgesetzter = v, nutzer = n, austritt = personal._datum(p, 'austritt'),
           geaendert_am = clock_timestamp(), geaendert_von = p_subjekt
     WHERE id = p_id RETURNING * INTO neu;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.person_geaendert', jsonb_build_object('person', p_id::text), NULL,
        jsonb_build_object('felder', personal._geaendert(
            to_jsonb(alt) - ARRAY['geaendert_am', 'geaendert_von', 'angelegt_am', 'loeschen_ab'],
            to_jsonb(neu) - ARRAY['geaendert_am', 'geaendert_von', 'angelegt_am', 'loeschen_ab'])));
    RETURN p_id;
END $$;

CREATE FUNCTION personal._privat_schreiben(p_subjekt text, p_person uuid, p jsonb, p_nur_genannte boolean)
RETURNS jsonb LANGUAGE plpgsql AS $$
DECLARE alt personal.privat; neu personal.privat;
BEGIN
    SELECT * INTO alt FROM personal.privat WHERE person = p_person FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Person unbekannt', 'KM003'); END IF;
    UPDATE personal.privat SET
        geburtsdatum    = CASE WHEN p_nur_genannte AND NOT p ? 'geburtsdatum' THEN geburtsdatum ELSE personal._datum(p, 'geburtsdatum') END,
        strasse         = CASE WHEN p_nur_genannte AND NOT p ? 'strasse' THEN strasse ELSE personal._t(p, 'strasse') END,
        plz             = CASE WHEN p_nur_genannte AND NOT p ? 'plz' THEN plz ELSE personal._t(p, 'plz') END,
        ort             = CASE WHEN p_nur_genannte AND NOT p ? 'ort' THEN ort ELSE personal._t(p, 'ort') END,
        telefon         = CASE WHEN p_nur_genannte AND NOT p ? 'telefon' THEN telefon ELSE personal._t(p, 'telefon') END,
        email           = CASE WHEN p_nur_genannte AND NOT p ? 'email' THEN email ELSE lower(personal._t(p, 'email')) END,
        notfall_name    = CASE WHEN p_nur_genannte AND NOT p ? 'notfall_name' THEN notfall_name ELSE personal._t(p, 'notfall_name') END,
        notfall_telefon = CASE WHEN p_nur_genannte AND NOT p ? 'notfall_telefon' THEN notfall_telefon ELSE personal._t(p, 'notfall_telefon') END,
        geaendert_am = clock_timestamp(), geaendert_von = p_subjekt
     WHERE person = p_person RETURNING * INTO neu;
    RETURN personal._geaendert(to_jsonb(alt) - ARRAY['geaendert_am', 'geaendert_von', 'loeschen_ab'],
                               to_jsonb(neu) - ARRAY['geaendert_am', 'geaendert_von', 'loeschen_ab']);
END $$;

CREATE FUNCTION personal.privat_setzen(p_subjekt text, p_person uuid, p jsonb)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE felder jsonb;
BEGIN
    felder := personal._privat_schreiben(p_subjekt, p_person, p, false);
    IF jsonb_array_length(felder) > 0 THEN
        PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.privat_geaendert', jsonb_build_object('person', p_person::text), NULL,
            jsonb_build_object('felder', felder));
    END IF;
END $$;

-- ---------------------------------------------------------------------------
-- Vertrag
-- ---------------------------------------------------------------------------
CREATE FUNCTION personal.vertrag_anlegen(p_subjekt text, p_person uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE neu uuid; v integer[]; w integer;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM personal.person WHERE id = p_person) THEN
        PERFORM personal._fehler('Person unbekannt', 'KM003');
    END IF;
    IF personal._datum(p, 'gueltig_ab') IS NULL OR personal._datum(p, 'beginn') IS NULL OR personal._t(p, 'art') IS NULL THEN
        PERFORM personal._fehler('Gültig ab, Beginn und Art gehören dazu');
    END IF;
    IF jsonb_typeof(p->'verteilung') IS DISTINCT FROM 'array' OR jsonb_array_length(p->'verteilung') <> 7
       OR EXISTS (SELECT 1 FROM jsonb_array_elements(p->'verteilung') e WHERE jsonb_typeof(e) <> 'number' OR e::text !~ '^[0-9]{1,4}$') THEN
        PERFORM personal._fehler('Verteilung: sieben Werte in Minuten, Montag bis Sonntag');
    END IF;
    SELECT array_agg(e::text::integer ORDER BY nr) INTO v FROM jsonb_array_elements(p->'verteilung') WITH ORDINALITY AS a(e, nr);
    SELECT sum(x) INTO w FROM unnest(v) x;
    INSERT INTO personal.vertrag (person, gueltig_ab, art, funktion, beginn, probezeit_bis, befristet_bis, kuendigungsfrist, wochenminuten,
                                  verteilung, urlaubstage, lohnbuch, angelegt_von)
    VALUES (p_person, personal._datum(p, 'gueltig_ab'), personal._t(p, 'art'), personal._t(p, 'funktion'), personal._datum(p, 'beginn'),
            personal._datum(p, 'probezeit_bis'), personal._datum(p, 'befristet_bis'), personal._t(p, 'kuendigungsfrist'), w, v,
            nullif(p->>'urlaubstage', '')::numeric, personal._t(p, 'lohnbuch'), p_subjekt)
    RETURNING id INTO neu;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.vertrag_angelegt',
        jsonb_build_object('person', p_person::text, 'vertrag', neu::text));
    RETURN neu;
EXCEPTION WHEN check_violation THEN
    PERFORM personal._fehler('Vertrag: Art unbekannt, Befristung ohne Ende, oder ein Datum liegt vor dem Beginn');
    RETURN NULL;
END $$;

-- ---------------------------------------------------------------------------
-- Fristen und Nachweise
-- ---------------------------------------------------------------------------
CREATE FUNCTION personal.fristart_setzen(p_subjekt text, p_id uuid, p_name text, p_warn_tage integer, p_stillgelegt boolean)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE neu uuid;
BEGIN
    IF nullif(trim(p_name), '') IS NULL OR length(trim(p_name)) > 60 OR coalesce(p_warn_tage, 60) NOT BETWEEN 0 AND 365 THEN
        PERFORM personal._fehler('Art der Frist: Name mit höchstens 60 Zeichen, Warnfrist 0 bis 365 Tage');
    END IF;
    IF EXISTS (SELECT 1 FROM personal.fristart WHERE lower(name) = lower(trim(p_name)) AND id IS DISTINCT FROM p_id) THEN
        PERFORM personal._fehler('Eine Art mit diesem Namen gibt es schon', 'KM003');
    END IF;
    IF p_id IS NULL THEN
        INSERT INTO personal.fristart (name, warn_tage) VALUES (trim(p_name), coalesce(p_warn_tage, 60)) RETURNING id INTO neu;
    ELSE
        UPDATE personal.fristart SET name = trim(p_name), warn_tage = coalesce(p_warn_tage, 60), stillgelegt = coalesce(p_stillgelegt, false)
         WHERE id = p_id RETURNING id INTO neu;
        IF neu IS NULL THEN PERFORM personal._fehler('Art unbekannt', 'KM003'); END IF;
    END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.fristart_gesetzt', jsonb_build_object('fristart', neu::text), NULL,
        jsonb_build_object('name', trim(p_name), 'warn_tage', coalesce(p_warn_tage, 60)::text, 'stillgelegt', coalesce(p_stillgelegt, false)));
    RETURN neu;
END $$;

CREATE FUNCTION personal.frist_anlegen(p_subjekt text, p_person uuid, p_art uuid, p_faellig date, p_bemerkung text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE neu uuid;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM personal.person WHERE id = p_person) THEN PERFORM personal._fehler('Person unbekannt', 'KM003'); END IF;
    IF NOT EXISTS (SELECT 1 FROM personal.fristart WHERE id = p_art AND NOT stillgelegt) THEN PERFORM personal._fehler('Art der Frist unbekannt oder stillgelegt'); END IF;
    IF p_faellig IS NULL THEN PERFORM personal._fehler('Fällig am fehlt'); END IF;
    IF length(coalesce(p_bemerkung, '')) > 200 THEN PERFORM personal._fehler('Bemerkung: höchstens 200 Zeichen'); END IF;
    INSERT INTO personal.frist (person, art, faellig, bemerkung, angelegt_von)
    VALUES (p_person, p_art, p_faellig, nullif(trim(p_bemerkung), ''), p_subjekt) RETURNING id INTO neu;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.frist_angelegt',
        jsonb_build_object('person', p_person::text, 'frist', neu::text, 'fristart', p_art::text));
    RETURN neu;
END $$;

CREATE FUNCTION personal.frist_erledigen(p_subjekt text, p_id uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE f personal.frist;
BEGIN
    SELECT * INTO f FROM personal.frist WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Frist unbekannt', 'KM003'); END IF;
    IF f.erledigt_am IS NOT NULL THEN PERFORM personal._fehler('Die Frist ist schon erledigt', 'KM003'); END IF;
    UPDATE personal.frist SET erledigt_am = clock_timestamp(), erledigt_von = p_subjekt WHERE id = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.frist_erledigt',
        jsonb_build_object('person', f.person::text, 'frist', p_id::text));
END $$;

-- ---------------------------------------------------------------------------
-- Selfservice: Änderungsvorschlag zu den privaten Angaben
-- ---------------------------------------------------------------------------
CREATE FUNCTION personal.vorschlag_anlegen(p_subjekt text, p_person uuid, p_felder jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE neu uuid; k text;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM personal.person WHERE id = p_person) THEN PERFORM personal._fehler('Person unbekannt', 'KM003'); END IF;
    IF p_felder IS NULL OR jsonb_typeof(p_felder) <> 'object' OR p_felder = '{}'::jsonb THEN
        PERFORM personal._fehler('Der Vorschlag nennt keine Änderung');
    END IF;
    FOR k IN SELECT jsonb_object_keys(p_felder) LOOP
        IF k NOT IN ('strasse', 'plz', 'ort', 'telefon', 'email', 'notfall_name', 'notfall_telefon') THEN
            PERFORM personal._fehler(format('%s lässt sich nicht vorschlagen', k));
        END IF;
        IF jsonb_typeof(p_felder->k) NOT IN ('string', 'null') OR length(coalesce(p_felder->>k, '')) > 254 THEN
            PERFORM personal._fehler(format('%s: Text erwartet', k));
        END IF;
    END LOOP;
    IF (SELECT count(*) FROM personal.vorschlag WHERE person = p_person AND zustand = 'offen') >= 5 THEN
        PERFORM personal._fehler('Es sind schon fünf Vorschläge offen – erst entscheiden lassen', 'KM003');
    END IF;
    INSERT INTO personal.vorschlag (person, felder, angelegt_von) VALUES (p_person, p_felder, p_subjekt) RETURNING id INTO neu;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.vorschlag_angelegt',
        jsonb_build_object('person', p_person::text, 'vorschlag', neu::text), NULL,
        jsonb_build_object('felder', (SELECT jsonb_agg(x ORDER BY x) FROM jsonb_object_keys(p_felder) x)));
    RETURN neu;
END $$;

CREATE FUNCTION personal.vorschlag_entscheiden(p_subjekt text, p_id uuid, p_uebernehmen boolean, p_grund text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE v personal.vorschlag; felder jsonb;
BEGIN
    SELECT * INTO v FROM personal.vorschlag WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Vorschlag unbekannt', 'KM003'); END IF;
    IF v.zustand <> 'offen' THEN PERFORM personal._fehler('Über den Vorschlag ist schon entschieden', 'KM003'); END IF;
    IF NOT coalesce(p_uebernehmen, false) AND nullif(trim(p_grund), '') IS NULL THEN
        PERFORM personal._fehler('Eine Ablehnung braucht einen Grund');
    END IF;
    IF length(coalesce(p_grund, '')) > 200 THEN PERFORM personal._fehler('Grund: höchstens 200 Zeichen'); END IF;
    IF coalesce(p_uebernehmen, false) THEN
        felder := personal._privat_schreiben(p_subjekt, v.person, v.felder, true);
    END IF;
    UPDATE personal.vorschlag SET zustand = CASE WHEN coalesce(p_uebernehmen, false) THEN 'uebernommen' ELSE 'abgelehnt' END,
           grund = nullif(trim(p_grund), ''), entschieden_am = clock_timestamp(), entschieden_von = p_subjekt WHERE id = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal',
        CASE WHEN coalesce(p_uebernehmen, false) THEN 'personal.vorschlag_uebernommen' ELSE 'personal.vorschlag_abgelehnt' END,
        jsonb_build_object('person', v.person::text, 'vorschlag', p_id::text), NULL,
        CASE WHEN felder IS NULL THEN NULL ELSE jsonb_build_object('felder', felder) END);
END $$;

-- ---------------------------------------------------------------------------
-- Löschfrist je Objekt (PE12, PERSONAL.md 8a) und Einstellung
-- ---------------------------------------------------------------------------
CREATE FUNCTION personal.loeschfrist_setzen(p_subjekt text, p_objektart text, p_id uuid, p_loeschen_ab date, p_grund text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE alt date; v_person uuid; gefunden boolean := false;
BEGIN
    IF nullif(trim(p_grund), '') IS NULL OR length(p_grund) > 200 THEN
        PERFORM personal._fehler('Eine Löschfrist braucht eine Begründung (höchstens 200 Zeichen)');
    END IF;
    CASE p_objektart
    WHEN 'person' THEN
        SELECT loeschen_ab, id, true INTO alt, v_person, gefunden FROM personal.person WHERE id = p_id FOR UPDATE;
        UPDATE personal.person SET loeschen_ab = p_loeschen_ab WHERE id = p_id;
    WHEN 'privat' THEN
        SELECT loeschen_ab, p.person, true INTO alt, v_person, gefunden FROM personal.privat p WHERE p.person = p_id FOR UPDATE;
        UPDATE personal.privat p SET loeschen_ab = p_loeschen_ab WHERE p.person = p_id;
    WHEN 'vertrag' THEN
        SELECT loeschen_ab, v.person, true INTO alt, v_person, gefunden FROM personal.vertrag v WHERE id = p_id FOR UPDATE;
        UPDATE personal.vertrag SET loeschen_ab = p_loeschen_ab WHERE id = p_id;
    WHEN 'frist' THEN
        SELECT loeschen_ab, f.person, true INTO alt, v_person, gefunden FROM personal.frist f WHERE id = p_id FOR UPDATE;
        UPDATE personal.frist SET loeschen_ab = p_loeschen_ab WHERE id = p_id;
    WHEN 'vorschlag' THEN
        SELECT loeschen_ab, v.person, true INTO alt, v_person, gefunden FROM personal.vorschlag v WHERE id = p_id FOR UPDATE;
        UPDATE personal.vorschlag SET loeschen_ab = p_loeschen_ab WHERE id = p_id;
    ELSE
        PERFORM personal._fehler('Art des Objekts unbekannt');
    END CASE;
    IF NOT coalesce(gefunden, false) THEN PERFORM personal._fehler('Objekt unbekannt', 'KM003'); END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.loeschfrist_gesetzt',
        jsonb_build_object('person', v_person::text, 'objektart', p_objektart, 'objekt', p_id::text),
        jsonb_strip_nulls(jsonb_build_object('loeschen_ab', alt::text)),
        jsonb_strip_nulls(jsonb_build_object('loeschen_ab', p_loeschen_ab::text, 'grund', trim(p_grund))));
END $$;

CREATE FUNCTION personal.einstellung_setzen(p_subjekt text, p_warn_tage integer)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE alt integer;
BEGIN
    IF p_warn_tage IS NULL OR p_warn_tage NOT BETWEEN 0 AND 365 THEN PERFORM personal._fehler('Warnfrist: 0 bis 365 Tage'); END IF;
    SELECT warn_tage INTO alt FROM personal.einstellung FOR UPDATE;
    UPDATE personal.einstellung SET warn_tage = p_warn_tage, geaendert_am = clock_timestamp(), geaendert_von = p_subjekt;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.einstellung_geaendert', '{}'::jsonb,
        jsonb_build_object('warn_tage', alt::text), jsonb_build_object('warn_tage', p_warn_tage::text));
END $$;

-- ---------------------------------------------------------------------------
-- Zugriff: nur die Anwendung liest; das Lesekonto sieht keine Personaldaten
-- ---------------------------------------------------------------------------
REVOKE ALL ON ALL TABLES IN SCHEMA personal FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON ALL TABLES IN SCHEMA personal TO kumero_app;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA personal FROM PUBLIC;
GRANT EXECUTE ON FUNCTION personal.vertrag_am(uuid, date),
    personal.person_setzen(text, uuid, jsonb), personal.privat_setzen(text, uuid, jsonb), personal.vertrag_anlegen(text, uuid, jsonb),
    personal.fristart_setzen(text, uuid, text, integer, boolean), personal.frist_anlegen(text, uuid, uuid, date, text),
    personal.frist_erledigen(text, uuid), personal.vorschlag_anlegen(text, uuid, jsonb),
    personal.vorschlag_entscheiden(text, uuid, boolean, text), personal.loeschfrist_setzen(text, text, uuid, date, text),
    personal.einstellung_setzen(text, integer) TO kumero_app;
