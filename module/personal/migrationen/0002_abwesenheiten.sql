-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul personal, Migration 0002: Abwesenheiten (PERSONAL.md Stufe 2, Abschnitt 10b)
--
-- Urlaub, Arbeitsunfähigkeit (AU), Freistellungen und weitere Arten; Antrag → Genehmigung oder Ablehnung mit Grund →
-- genommen; Rücknahme; Urlaubskonto je Jahr; freie Tage des Betriebs.
--
-- Datenklassen (SCHLUESSEL.md Abschnitt 6), wie in 0001 getrennt abgelegt:
--   Abwesenheit  personal.abwesenheit   – wer wann fehlt, Art, Zustand (die Art sehen nur Personal, Lohn und die Person;
--                                          der Vorgesetzte sieht sie nur bei Anträgen, die er entscheidet)
--   AU           personal.au            – Tag der ärztlichen Feststellung, Erst-/Folgebescheinigung, Arbeitsunfall,
--                                          Nachweis liegt vor. **Nie eine Diagnose** (PE7): Es gibt kein Feld dafür, und
--                                          eine AU trägt keine Bemerkung.
--   Urlaubskonto personal.urlaubskonto  – Anspruch (wenn abweichend vom Vertrag) und Übertrag je Person und Jahr
-- Keine Personendaten: personal.abwesenheitsart, personal.freier_tag.
--
-- Geschrieben wird nur über Funktionen (F64); das Protokoll nennt IDs und Namen geänderter Felder, nie einen Wert und
-- nie die Art einer Abwesenheit.

CREATE TABLE personal.abwesenheitsart (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    kennung        text UNIQUE CHECK (kennung IN ('urlaub', 'au')),      -- die beiden Arten mit fester Bedeutung; eigene Arten haben keine
    name           text NOT NULL UNIQUE CHECK (length(trim(name)) BETWEEN 1 AND 60),
    bezahlt        boolean NOT NULL,                 -- Angabe für die Lohnvorbereitung (Stufe 3)
    zaehlt_urlaub  boolean NOT NULL DEFAULT false,   -- zählt gegen das Urlaubskonto
    genehmigung    boolean NOT NULL DEFAULT true,    -- braucht eine Genehmigung
    selbst         boolean NOT NULL DEFAULT false,   -- die Person kann sie selbst beantragen bzw. melden
    stillgelegt    boolean NOT NULL DEFAULT false,
    CHECK (kennung IS DISTINCT FROM 'au' OR (NOT zaehlt_urlaub AND NOT genehmigung)),
    CHECK (kennung IS DISTINCT FROM 'urlaub' OR zaehlt_urlaub)
);
INSERT INTO personal.abwesenheitsart (kennung, name, bezahlt, zaehlt_urlaub, genehmigung, selbst) VALUES
    ('urlaub', 'Urlaub',                 true,  true,  true,  true),
    ('au',     'Arbeitsunfähigkeit',     true,  false, false, true),
    (NULL,     'Freistellung bezahlt',   true,  false, true,  true),
    (NULL,     'Freistellung unbezahlt', false, false, true,  true),
    (NULL,     'Sonderurlaub',           true,  false, true,  true),
    (NULL,     'Fortbildung',            true,  false, true,  true),
    (NULL,     'Mutterschutz',           true,  false, false, false),
    (NULL,     'Elternzeit',             false, false, false, false),
    (NULL,     'Sonstige',               false, false, true,  false);

-- Freie Tage des Betriebs (Feiertage, Betriebsferien): zählen nicht als Arbeitstag. kumero gibt keine vor.
CREATE TABLE personal.freier_tag (
    datum         date PRIMARY KEY CHECK (datum BETWEEN DATE '2000-01-01' AND DATE '2100-12-31'),
    name          text NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 60),
    angelegt_am   timestamptz NOT NULL DEFAULT now(),
    angelegt_von  text NOT NULL
);

-- Datenklasse Abwesenheit
CREATE TABLE personal.abwesenheit (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    person          uuid NOT NULL REFERENCES personal.person,
    art             uuid NOT NULL REFERENCES personal.abwesenheitsart,
    von             date NOT NULL,
    bis             date NOT NULL,
    halber_tag      boolean NOT NULL DEFAULT false,
    tage            numeric(5,1) NOT NULL CHECK (tage >= 0),            -- Arbeitstage nach Vertrag, ohne freie Tage – festgehalten beim Anlegen und Entscheiden
    tage_folgejahr  numeric(5,1) NOT NULL DEFAULT 0 CHECK (tage_folgejahr >= 0),   -- davon im Jahr von „bis“, wenn es über den Jahreswechsel geht
    bemerkung       text CHECK (bemerkung IS NULL OR length(bemerkung) <= 200),
    zustand         text NOT NULL CHECK (zustand IN ('beantragt', 'genehmigt', 'abgelehnt', 'zurueckgenommen')),
    grund           text CHECK (grund IS NULL OR length(grund) <= 200),  -- der Ablehnung oder der Rücknahme
    angelegt_am     timestamptz NOT NULL DEFAULT clock_timestamp(),
    angelegt_von    text NOT NULL,
    entschieden_am  timestamptz,
    entschieden_von text,
    loeschen_ab     date,
    CHECK (bis >= von),
    CHECK (bis - von <= 1100),
    CHECK (NOT halber_tag OR von = bis),
    CHECK (tage_folgejahr <= tage)
);
CREATE INDEX abwesenheit_person ON personal.abwesenheit (person, von);
CREATE INDEX abwesenheit_offen ON personal.abwesenheit (von) WHERE zustand = 'beantragt';

-- Datenklasse AU: genau die Angaben, die ein Arbeitgeber im eAU-Verfahren erhält (PE7) – ohne Diagnose
CREATE TABLE personal.au (
    abwesenheit      uuid PRIMARY KEY REFERENCES personal.abwesenheit ON DELETE CASCADE,
    festgestellt_am  date,
    folge            boolean NOT NULL DEFAULT false,     -- Folgebescheinigung (sonst Erstbescheinigung)
    arbeitsunfall    boolean NOT NULL DEFAULT false,
    nachweis         boolean NOT NULL DEFAULT false,     -- der Nachweis liegt vor; kumero ruft nichts ab
    nachweis_am      timestamptz,
    nachweis_von     text
);

-- Datenklasse Urlaubskonto: nur was vom Vertrag abweicht bzw. nicht aus ihm folgt
CREATE TABLE personal.urlaubskonto (
    person         uuid NOT NULL REFERENCES personal.person,
    jahr           integer NOT NULL CHECK (jahr BETWEEN 2000 AND 2100),
    anspruch       numeric(4,1) CHECK (anspruch IS NULL OR anspruch BETWEEN 0 AND 99),   -- leer = aus dem Vertrag
    uebertrag      numeric(4,1) NOT NULL DEFAULT 0 CHECK (uebertrag BETWEEN -99 AND 99),
    geaendert_am   timestamptz NOT NULL DEFAULT now(),
    geaendert_von  text NOT NULL,
    loeschen_ab    date,
    PRIMARY KEY (person, jahr)
);

-- ---------------------------------------------------------------------------
-- Rechnen
-- ---------------------------------------------------------------------------
-- Arbeitstage einer Person in einem Zeitraum: Tage, an denen nach der an diesem Tag geltenden Fassung des Vertrags
-- gearbeitet wird (ohne Vertrag: Montag bis Freitag), ohne die freien Tage des Betriebs. Ein halber Tag zählt 0,5.
CREATE FUNCTION personal._arbeitstage(p_person uuid, p_von date, p_bis date, p_halb boolean DEFAULT false)
RETURNS numeric LANGUAGE sql STABLE AS $$
    SELECT (CASE WHEN p_halb THEN least(count(*), 1) * 0.5 ELSE count(*) END)::numeric(5,1)
      FROM generate_series(p_von::timestamp, p_bis::timestamp, interval '1 day') AS g(t)
     WHERE NOT EXISTS (SELECT 1 FROM personal.freier_tag f WHERE f.datum = g.t::date)
       AND coalesce((SELECT v.verteilung[extract(isodow FROM g.t)::integer] FROM personal.vertrag v WHERE v.id = personal.vertrag_am(p_person, g.t::date)),
                    CASE WHEN extract(isodow FROM g.t) <= 5 THEN 1 ELSE 0 END) > 0
$$;

-- Urlaubskonto einer Person in einem Jahr. Anspruch: der am Konto festgesetzte, sonst die Urlaubstage der Fassung des
-- Vertrags, die am 1. Januar gilt – beginnt der Vertrag erst im Jahr, seiner ersten Fassung in diesem Jahr. Keine
-- Zwölftelung, kein Verfall, kein Übertrag von selbst: Das setzt das Personal.
CREATE FUNCTION personal.urlaubskonto_stand(p_person uuid, p_jahr integer)
RETURNS TABLE (anspruch numeric, festgesetzt boolean, uebertrag numeric, beantragt numeric, genehmigt numeric, genommen numeric)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
    WITH konto AS (SELECT k.anspruch, k.uebertrag FROM personal.urlaubskonto k WHERE k.person = p_person AND k.jahr = p_jahr),
         fassung AS (SELECT coalesce(personal.vertrag_am(p_person, make_date(p_jahr, 1, 1)),
                                     (SELECT v.id FROM personal.vertrag v WHERE v.person = p_person AND v.gueltig_ab BETWEEN make_date(p_jahr, 1, 1) AND make_date(p_jahr, 12, 31)
                                       ORDER BY v.gueltig_ab, v.angelegt_am DESC LIMIT 1)) AS id),
         teil AS (SELECT a.zustand, a.bis,
                         CASE WHEN extract(year FROM a.von) = p_jahr THEN a.tage - a.tage_folgejahr ELSE a.tage_folgejahr END AS tage
                    FROM personal.abwesenheit a JOIN personal.abwesenheitsart art ON art.id = a.art
                   WHERE a.person = p_person AND art.zaehlt_urlaub AND a.zustand IN ('beantragt', 'genehmigt')
                     AND (extract(year FROM a.von) = p_jahr OR extract(year FROM a.bis) = p_jahr))
    SELECT coalesce((SELECT anspruch FROM konto), (SELECT v.urlaubstage FROM personal.vertrag v, fassung WHERE v.id = fassung.id), 0)::numeric,
           coalesce((SELECT anspruch IS NOT NULL FROM konto), false),
           coalesce((SELECT uebertrag FROM konto), 0)::numeric,
           coalesce((SELECT sum(tage) FROM teil WHERE zustand = 'beantragt'), 0)::numeric,
           coalesce((SELECT sum(tage) FROM teil WHERE zustand = 'genehmigt' AND bis >= current_date), 0)::numeric,
           coalesce((SELECT sum(tage) FROM teil WHERE zustand = 'genehmigt' AND bis < current_date), 0)::numeric
$$;

-- Prüft Zeitraum und Überschneidung und rechnet die Tage. Zwei Abwesenheiten derselben Person überschneiden sich nicht –
-- außer eine davon ist eine AU: Wer im Urlaub krank wird, ist beides; den Urlaub kürzt das Personal.
CREATE FUNCTION personal._abwesenheit_rechnen(p_person uuid, p_art personal.abwesenheitsart, p_von date, p_bis date, p_halb boolean, p_ausser uuid,
                                              OUT o_tage numeric, OUT o_folgejahr numeric)
LANGUAGE plpgsql AS $$
BEGIN
    IF p_von IS NULL OR p_bis IS NULL THEN PERFORM personal._fehler('Von und Bis gehören dazu'); END IF;
    IF p_bis < p_von THEN PERFORM personal._fehler('Das Ende liegt vor dem Beginn'); END IF;
    IF p_bis - p_von > 1100 THEN PERFORM personal._fehler('Eine Abwesenheit dauert höchstens drei Jahre'); END IF;
    IF p_halb AND p_von <> p_bis THEN PERFORM personal._fehler('Ein halber Tag ist genau ein Tag'); END IF;
    IF p_art.zaehlt_urlaub AND extract(year FROM p_bis) - extract(year FROM p_von) > 1 THEN
        PERFORM personal._fehler('Urlaub reicht höchstens über einen Jahreswechsel');
    END IF;
    IF p_art.kennung IS DISTINCT FROM 'au' AND EXISTS (
        SELECT 1 FROM personal.abwesenheit a JOIN personal.abwesenheitsart x ON x.id = a.art
         WHERE a.person = p_person AND a.id IS DISTINCT FROM p_ausser AND a.zustand IN ('beantragt', 'genehmigt')
           AND x.kennung IS DISTINCT FROM 'au' AND a.von <= p_bis AND a.bis >= p_von) THEN
        PERFORM personal._fehler('In diesem Zeitraum gibt es schon eine Abwesenheit', 'KM003');
    END IF;
    o_tage := personal._arbeitstage(p_person, p_von, p_bis, p_halb);
    o_folgejahr := CASE WHEN extract(year FROM p_bis) > extract(year FROM p_von)
                        THEN personal._arbeitstage(p_person, make_date(extract(year FROM p_bis)::integer, 1, 1), p_bis) ELSE 0 END;
    IF p_art.zaehlt_urlaub AND o_tage = 0 THEN
        PERFORM personal._fehler('In diesem Zeitraum liegt kein Arbeitstag – es wäre kein Urlaubstag zu nehmen');
    END IF;
END $$;

-- ---------------------------------------------------------------------------
-- Abwesenheit: anlegen, entscheiden, zurücknehmen, Ende ändern
-- ---------------------------------------------------------------------------
-- p_sofort: Das Personal trägt ein – die Abwesenheit gilt ohne Antrag. Sonst entscheidet die Art: Braucht sie eine
-- Genehmigung, ist es ein Antrag; braucht sie keine (AU), gilt sie mit der Meldung.
CREATE FUNCTION personal.abwesenheit_anlegen(p_subjekt text, p_person uuid, p jsonb, p_sofort boolean)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE pers personal.person; art personal.abwesenheitsart; neu uuid; v_von date := personal._datum(p, 'von'); v_bis date := personal._datum(p, 'bis');
        v_halb boolean := coalesce((p->>'halber_tag')::boolean, false); r record; gilt boolean;
BEGIN
    SELECT * INTO pers FROM personal.person WHERE id = p_person;
    IF NOT FOUND THEN PERFORM personal._fehler('Person unbekannt', 'KM003'); END IF;
    SELECT * INTO art FROM personal.abwesenheitsart WHERE id = nullif(p->>'art', '')::uuid AND NOT stillgelegt;
    IF NOT FOUND THEN PERFORM personal._fehler('Art der Abwesenheit unbekannt oder stillgelegt'); END IF;
    IF pers.austritt IS NOT NULL AND v_von > pers.austritt THEN PERFORM personal._fehler('Die Person ist zu diesem Zeitpunkt ausgeschieden'); END IF;
    IF art.kennung = 'au' AND personal._t(p, 'bemerkung') IS NOT NULL THEN
        PERFORM personal._fehler('Eine Arbeitsunfähigkeit trägt keine Bemerkung – kumero führt keine Angaben zur Erkrankung');
    END IF;
    IF art.kennung IS DISTINCT FROM 'au' AND p ? 'au' AND jsonb_typeof(p->'au') = 'object' THEN
        PERFORM personal._fehler('Angaben zur Bescheinigung gehören nur zu einer Arbeitsunfähigkeit');
    END IF;
    IF length(coalesce(p->>'bemerkung', '')) > 200 THEN PERFORM personal._fehler('Bemerkung: höchstens 200 Zeichen'); END IF;
    SELECT * INTO r FROM personal._abwesenheit_rechnen(p_person, art, v_von, v_bis, v_halb, NULL);
    gilt := coalesce(p_sofort, false) OR NOT art.genehmigung;
    INSERT INTO personal.abwesenheit (person, art, von, bis, halber_tag, tage, tage_folgejahr, bemerkung, zustand, angelegt_von, entschieden_am, entschieden_von)
    VALUES (p_person, art.id, v_von, v_bis, v_halb, r.o_tage, r.o_folgejahr, personal._t(p, 'bemerkung'),
            CASE WHEN gilt THEN 'genehmigt' ELSE 'beantragt' END, p_subjekt,
            CASE WHEN gilt THEN clock_timestamp() END, CASE WHEN gilt THEN p_subjekt END)
    RETURNING id INTO neu;
    IF art.kennung = 'au' THEN
        IF personal._datum(coalesce(p->'au', '{}'), 'festgestellt_am') > current_date THEN PERFORM personal._fehler('Der Tag der Feststellung liegt in der Zukunft'); END IF;
        INSERT INTO personal.au (abwesenheit, festgestellt_am, folge, arbeitsunfall)
        VALUES (neu, personal._datum(coalesce(p->'au', '{}'), 'festgestellt_am'), coalesce((p->'au'->>'folge')::boolean, false), coalesce((p->'au'->>'arbeitsunfall')::boolean, false));
    END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', CASE WHEN gilt THEN 'personal.abwesenheit_eingetragen' ELSE 'personal.abwesenheit_beantragt' END,
        jsonb_build_object('person', p_person::text, 'abwesenheit', neu::text));
    RETURN neu;
END $$;

CREATE FUNCTION personal.abwesenheit_entscheiden(p_subjekt text, p_id uuid, p_genehmigen boolean, p_grund text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE a personal.abwesenheit; art personal.abwesenheitsart; r record;
BEGIN
    SELECT * INTO a FROM personal.abwesenheit WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Abwesenheit unbekannt', 'KM003'); END IF;
    IF a.zustand <> 'beantragt' THEN PERFORM personal._fehler('Über den Antrag ist schon entschieden', 'KM003'); END IF;
    IF NOT coalesce(p_genehmigen, false) AND nullif(trim(p_grund), '') IS NULL THEN PERFORM personal._fehler('Eine Ablehnung braucht einen Grund'); END IF;
    IF length(coalesce(p_grund, '')) > 200 THEN PERFORM personal._fehler('Grund: höchstens 200 Zeichen'); END IF;
    IF coalesce(p_genehmigen, false) THEN
        -- die Tage gelten mit dem Stand der Genehmigung (Vertrag und freie Tage können sich seit dem Antrag geändert haben)
        SELECT * INTO art FROM personal.abwesenheitsart WHERE id = a.art;
        SELECT * INTO r FROM personal._abwesenheit_rechnen(a.person, art, a.von, a.bis, a.halber_tag, a.id);
        UPDATE personal.abwesenheit SET zustand = 'genehmigt', tage = r.o_tage, tage_folgejahr = r.o_folgejahr, grund = nullif(trim(p_grund), ''),
               entschieden_am = clock_timestamp(), entschieden_von = p_subjekt WHERE id = p_id;
    ELSE
        UPDATE personal.abwesenheit SET zustand = 'abgelehnt', grund = trim(p_grund), entschieden_am = clock_timestamp(), entschieden_von = p_subjekt WHERE id = p_id;
    END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', CASE WHEN coalesce(p_genehmigen, false) THEN 'personal.abwesenheit_genehmigt' ELSE 'personal.abwesenheit_abgelehnt' END,
        jsonb_build_object('person', a.person::text, 'abwesenheit', p_id::text));
END $$;

-- p_selbst: Die Person nimmt zurück – einen Antrag jederzeit, Genehmigtes nur, solange es nicht begonnen hat.
-- Sonst nimmt das Personal zurück, mit Grund. Nichts wird gelöscht: Der Eintrag bleibt als „zurückgenommen“.
CREATE FUNCTION personal.abwesenheit_zuruecknehmen(p_subjekt text, p_id uuid, p_grund text, p_selbst boolean)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE a personal.abwesenheit;
BEGIN
    SELECT * INTO a FROM personal.abwesenheit WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Abwesenheit unbekannt', 'KM003'); END IF;
    IF a.zustand NOT IN ('beantragt', 'genehmigt') THEN PERFORM personal._fehler('Die Abwesenheit ist schon abgelehnt oder zurückgenommen', 'KM003'); END IF;
    IF coalesce(p_selbst, false) THEN
        IF a.zustand = 'genehmigt' AND a.von <= current_date THEN
            PERFORM personal._fehler('Die Abwesenheit hat schon begonnen – zurücknehmen kann sie nur das Personal', 'KM003');
        END IF;
    ELSIF nullif(trim(p_grund), '') IS NULL THEN
        PERFORM personal._fehler('Die Rücknahme braucht einen Grund');
    END IF;
    IF length(coalesce(p_grund, '')) > 200 THEN PERFORM personal._fehler('Grund: höchstens 200 Zeichen'); END IF;
    UPDATE personal.abwesenheit SET zustand = 'zurueckgenommen', grund = nullif(trim(p_grund), ''), entschieden_am = clock_timestamp(), entschieden_von = p_subjekt WHERE id = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.abwesenheit_zurueckgenommen',
        jsonb_build_object('person', a.person::text, 'abwesenheit', p_id::text));
END $$;

-- Das Ende ändern (verkürzen oder verlängern): früher zurück, Urlaub wegen AU gekürzt, AU dauert länger.
CREATE FUNCTION personal.abwesenheit_ende_setzen(p_subjekt text, p_id uuid, p_bis date)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE a personal.abwesenheit; art personal.abwesenheitsart; r record;
BEGIN
    SELECT * INTO a FROM personal.abwesenheit WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Abwesenheit unbekannt', 'KM003'); END IF;
    IF a.zustand NOT IN ('beantragt', 'genehmigt') THEN PERFORM personal._fehler('Die Abwesenheit ist abgelehnt oder zurückgenommen', 'KM003'); END IF;
    IF a.halber_tag THEN PERFORM personal._fehler('Ein halber Tag hat kein anderes Ende – zurücknehmen und neu eintragen', 'KM003'); END IF;
    SELECT * INTO art FROM personal.abwesenheitsart WHERE id = a.art;
    SELECT * INTO r FROM personal._abwesenheit_rechnen(a.person, art, a.von, p_bis, false, a.id);
    UPDATE personal.abwesenheit SET bis = p_bis, tage = r.o_tage, tage_folgejahr = r.o_folgejahr WHERE id = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.abwesenheit_geaendert',
        jsonb_build_object('person', a.person::text, 'abwesenheit', p_id::text), NULL, jsonb_build_object('felder', jsonb_build_array('bis')));
END $$;

CREATE FUNCTION personal.au_nachweis_setzen(p_subjekt text, p_id uuid, p_liegt_vor boolean)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE v_person uuid;
BEGIN
    SELECT a.person INTO v_person FROM personal.au u JOIN personal.abwesenheit a ON a.id = u.abwesenheit WHERE u.abwesenheit = p_id FOR UPDATE OF u;
    IF NOT FOUND THEN PERFORM personal._fehler('Zu dieser Abwesenheit gibt es keine Bescheinigung', 'KM003'); END IF;
    UPDATE personal.au SET nachweis = coalesce(p_liegt_vor, false), nachweis_am = clock_timestamp(), nachweis_von = p_subjekt WHERE abwesenheit = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.au_nachweis_vermerkt',
        jsonb_build_object('person', v_person::text, 'abwesenheit', p_id::text), NULL, jsonb_build_object('felder', jsonb_build_array('nachweis')));
END $$;

-- ---------------------------------------------------------------------------
-- Arten, freie Tage, Urlaubskonto
-- ---------------------------------------------------------------------------
CREATE FUNCTION personal.abwesenheitsart_setzen(p_subjekt text, p_id uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE neu uuid; alt personal.abwesenheitsart; b boolean;
BEGIN
    IF personal._t(p, 'name') IS NULL OR length(personal._t(p, 'name')) > 60 THEN PERFORM personal._fehler('Art der Abwesenheit: Name mit höchstens 60 Zeichen'); END IF;
    IF EXISTS (SELECT 1 FROM personal.abwesenheitsart WHERE lower(name) = lower(personal._t(p, 'name')) AND id IS DISTINCT FROM p_id) THEN
        PERFORM personal._fehler('Eine Art mit diesem Namen gibt es schon', 'KM003');
    END IF;
    IF p_id IS NULL THEN
        INSERT INTO personal.abwesenheitsart (name, bezahlt, zaehlt_urlaub, genehmigung, selbst)
        VALUES (personal._t(p, 'name'), coalesce((p->>'bezahlt')::boolean, false), coalesce((p->>'zaehlt_urlaub')::boolean, false),
                coalesce((p->>'genehmigung')::boolean, true), coalesce((p->>'selbst')::boolean, false)) RETURNING id INTO neu;
    ELSE
        SELECT * INTO alt FROM personal.abwesenheitsart WHERE id = p_id FOR UPDATE;
        IF NOT FOUND THEN PERFORM personal._fehler('Art unbekannt', 'KM003'); END IF;
        b := coalesce((p->>'stillgelegt')::boolean, false);
        IF alt.kennung IS NOT NULL AND b THEN PERFORM personal._fehler('Urlaub und Arbeitsunfähigkeit lassen sich nicht stilllegen', 'KM003'); END IF;
        -- die beiden festen Arten behalten ihre Bedeutung: geändert werden Name, „bezahlt“ und „selbst“
        UPDATE personal.abwesenheitsart SET name = personal._t(p, 'name'), bezahlt = coalesce((p->>'bezahlt')::boolean, bezahlt),
               zaehlt_urlaub = CASE WHEN kennung IS NULL THEN coalesce((p->>'zaehlt_urlaub')::boolean, zaehlt_urlaub) ELSE zaehlt_urlaub END,
               genehmigung = CASE WHEN kennung IS NULL OR kennung = 'urlaub' THEN coalesce((p->>'genehmigung')::boolean, genehmigung) ELSE genehmigung END,
               selbst = coalesce((p->>'selbst')::boolean, selbst), stillgelegt = b
         WHERE id = p_id RETURNING id INTO neu;
    END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.abwesenheitsart_gesetzt', jsonb_build_object('abwesenheitsart', neu::text), NULL,
        (SELECT to_jsonb(x) - 'id' FROM personal.abwesenheitsart x WHERE x.id = neu));
    RETURN neu;
END $$;

-- Freien Tag setzen; ohne Namen wird er entfernt. Schon festgehaltene Tage einer Abwesenheit ändern sich dadurch nicht.
CREATE FUNCTION personal.freier_tag_setzen(p_subjekt text, p_datum date, p_name text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
BEGIN
    IF p_datum IS NULL OR p_datum NOT BETWEEN DATE '2000-01-01' AND DATE '2100-12-31' THEN PERFORM personal._fehler('Freier Tag: Datum fehlt oder liegt außerhalb'); END IF;
    IF length(coalesce(trim(p_name), '')) > 60 THEN PERFORM personal._fehler('Freier Tag: Name mit höchstens 60 Zeichen'); END IF;
    IF nullif(trim(p_name), '') IS NULL THEN
        DELETE FROM personal.freier_tag WHERE datum = p_datum;
        IF NOT FOUND THEN PERFORM personal._fehler('Diesen freien Tag gibt es nicht', 'KM003'); END IF;
        PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.freier_tag_entfernt', '{}'::jsonb, jsonb_build_object('datum', p_datum::text), NULL);
    ELSE
        INSERT INTO personal.freier_tag (datum, name, angelegt_von) VALUES (p_datum, trim(p_name), p_subjekt)
        ON CONFLICT (datum) DO UPDATE SET name = EXCLUDED.name;
        PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.freier_tag_gesetzt', '{}'::jsonb, NULL, jsonb_build_object('datum', p_datum::text, 'name', trim(p_name)));
    END IF;
END $$;

CREATE FUNCTION personal.urlaubskonto_setzen(p_subjekt text, p_person uuid, p_jahr integer, p_anspruch numeric, p_uebertrag numeric)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE alt personal.urlaubskonto; neu personal.urlaubskonto;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM personal.person WHERE id = p_person) THEN PERFORM personal._fehler('Person unbekannt', 'KM003'); END IF;
    IF p_jahr IS NULL OR p_jahr NOT BETWEEN 2000 AND 2100 THEN PERFORM personal._fehler('Jahr fehlt oder liegt außerhalb'); END IF;
    IF p_anspruch IS NOT NULL AND (p_anspruch NOT BETWEEN 0 AND 99 OR p_anspruch * 2 <> trunc(p_anspruch * 2)) THEN PERFORM personal._fehler('Anspruch: 0 bis 99 Tage, halbe Tage erlaubt'); END IF;
    IF coalesce(p_uebertrag, 0) NOT BETWEEN -99 AND 99 OR coalesce(p_uebertrag, 0) * 2 <> trunc(coalesce(p_uebertrag, 0) * 2) THEN PERFORM personal._fehler('Übertrag: −99 bis 99 Tage, halbe Tage erlaubt'); END IF;
    SELECT * INTO alt FROM personal.urlaubskonto WHERE person = p_person AND jahr = p_jahr FOR UPDATE;
    INSERT INTO personal.urlaubskonto (person, jahr, anspruch, uebertrag, geaendert_von) VALUES (p_person, p_jahr, p_anspruch, coalesce(p_uebertrag, 0), p_subjekt)
    ON CONFLICT (person, jahr) DO UPDATE SET anspruch = EXCLUDED.anspruch, uebertrag = EXCLUDED.uebertrag, geaendert_am = clock_timestamp(), geaendert_von = EXCLUDED.geaendert_von
    RETURNING * INTO neu;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.urlaubskonto_gesetzt', jsonb_build_object('person', p_person::text, 'jahr', p_jahr::text), NULL,
        jsonb_build_object('felder', personal._geaendert(jsonb_build_object('anspruch', alt.anspruch, 'uebertrag', coalesce(alt.uebertrag, 0)),
                                                         jsonb_build_object('anspruch', neu.anspruch, 'uebertrag', neu.uebertrag))));
END $$;

-- ---------------------------------------------------------------------------
-- Löschfrist: Abwesenheiten sind Objekte mit eigener Frist (PE12); die Angaben der AU gehen mit ihrer Abwesenheit
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION personal.loeschfrist_setzen(p_subjekt text, p_objektart text, p_id uuid, p_loeschen_ab date, p_grund text)
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
    WHEN 'abwesenheit' THEN
        SELECT loeschen_ab, a.person, true INTO alt, v_person, gefunden FROM personal.abwesenheit a WHERE id = p_id FOR UPDATE;
        UPDATE personal.abwesenheit SET loeschen_ab = p_loeschen_ab WHERE id = p_id;
    ELSE
        PERFORM personal._fehler('Art des Objekts unbekannt');
    END CASE;
    IF NOT coalesce(gefunden, false) THEN PERFORM personal._fehler('Objekt unbekannt', 'KM003'); END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.loeschfrist_gesetzt',
        jsonb_build_object('person', v_person::text, 'objektart', p_objektart, 'objekt', p_id::text),
        jsonb_strip_nulls(jsonb_build_object('loeschen_ab', alt::text)),
        jsonb_strip_nulls(jsonb_build_object('loeschen_ab', p_loeschen_ab::text, 'grund', trim(p_grund))));
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
    personal.freier_tag_setzen(text, date, text), personal.urlaubskonto_setzen(text, uuid, integer, numeric, numeric) TO kumero_app;
