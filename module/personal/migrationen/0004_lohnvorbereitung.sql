-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul personal, Migration 0004: Zuschlagsregeln, Zuschlag von Hand, Lohnvorbereitung (PERSONAL.md Stufe 3, Abschnitt 10d, F140)
--
-- Neue Datenklasse **Lohn** (eigene Rechte, SCHLUESSEL.md Abschnitt 6):
--   personal.zuschlagsregel, personal.zuschlagsregel_fassung, personal.zuschlag_zuordnung – Regeln des Mandanten
--   personal.zuschlag_hand      – Zuschlag von Hand mit Betrag und Begründung
--   personal.lohnvorbereitung   – je Monat und Person; offen → geprüft → übergeben, danach gesperrt
--
-- Die Quellen der Regeln – erbrachte Leistungen (`leistungen`) und geprüfte Zeiten (`zeit`) – gibt es noch nicht. Die
-- Anschlussstelle ist die Funktion personal._zuschlag_nachweis(): Sie liefert zu einer Regel, Person und Monat den
-- Betrag samt Nachweis oder NULL („Nachweis fehlt“). Heute liefert sie immer NULL; ein Modul, das die Quelle bringt,
-- ersetzt sie mit CREATE OR REPLACE – sonst ändert sich nichts (L2).
--
-- Gerechnet wird in ganzen Cent und in Hundertstel Prozent (Basispunkte), nie mit Dezimalzahlen. Das Protokoll nennt
-- IDs, nie einen Betrag.

CREATE TABLE personal.zuschlagsregel (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name         text NOT NULL UNIQUE CHECK (length(trim(name)) BETWEEN 1 AND 60),
    grundlage    text NOT NULL CHECK (grundlage IN ('leistung', 'zeit')),
    lohnart      text CHECK (lohnart IS NULL OR length(lohnart) <= 20),          -- für die Übergabe; die Zuordnung kommt mit dem Export
    stillgelegt  boolean NOT NULL DEFAULT false,
    angelegt_am  timestamptz NOT NULL DEFAULT now(),
    angelegt_von text NOT NULL
);

-- Fassungen mit „gültig ab“: keine wird überschrieben; vergangene Monate werden nicht neu gerechnet.
CREATE TABLE personal.zuschlagsregel_fassung (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    regel               uuid NOT NULL REFERENCES personal.zuschlagsregel,
    gueltig_ab          date NOT NULL,
    form                text NOT NULL CHECK (form IN ('anteil', 'betrag', 'staffel', 'zeitfenster')),
    satz_bp             integer CHECK (satz_bp BETWEEN 0 AND 100000),             -- Hundertstel Prozent: 2500 = 25 %
    betrag_cent         integer CHECK (betrag_cent BETWEEN 0 AND 100000000),
    schwelle_cent       integer CHECK (schwelle_cent BETWEEN 0 AND 2000000000),   -- Staffel: ab diesem Betrag im Monat gilt der zweite Satz
    satz_ab_schwelle_bp integer CHECK (satz_ab_schwelle_bp BETWEEN 0 AND 100000),
    umfang              text CHECK (umfang IN ('alle', 'gruppe', 'leistung')),
    bezug               text CHECK (bezug IS NULL OR length(bezug) <= 60),         -- Gruppe oder Nummer der Leistung
    zeitart             text CHECK (zeitart IN ('nacht', 'sonntag', 'feiertag', 'ueberstunden')),
    angelegt_am         timestamptz NOT NULL DEFAULT clock_timestamp(),
    angelegt_von        text NOT NULL
);
CREATE INDEX zuschlagsregel_fassung_regel ON personal.zuschlagsregel_fassung (regel, gueltig_ab DESC, angelegt_am DESC);

-- Eine Regel hängt an einer Person oder an einer Funktion (Text der Funktion im Vertrag), von–bis.
CREATE TABLE personal.zuschlag_zuordnung (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    regel        uuid NOT NULL REFERENCES personal.zuschlagsregel,
    person       uuid REFERENCES personal.person,
    funktion     text CHECK (funktion IS NULL OR length(trim(funktion)) BETWEEN 1 AND 100),
    von          date NOT NULL,
    bis          date,
    angelegt_am  timestamptz NOT NULL DEFAULT now(),
    angelegt_von text NOT NULL,
    CHECK ((person IS NULL) <> (funktion IS NULL)),
    CHECK (bis IS NULL OR bis >= von)
);
CREATE INDEX zuschlag_zuordnung_person ON personal.zuschlag_zuordnung (person);

CREATE TABLE personal.zuschlag_hand (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    person              uuid NOT NULL REFERENCES personal.person,
    monat               date NOT NULL CHECK (extract(day FROM monat) = 1),
    betrag_cent         integer NOT NULL CHECK (betrag_cent <> 0 AND betrag_cent BETWEEN -100000000 AND 100000000),   -- negativ: Berichtigung
    begruendung         text NOT NULL CHECK (length(trim(begruendung)) BETWEEN 1 AND 200),
    lohnart             text CHECK (lohnart IS NULL OR length(lohnart) <= 20),
    angelegt_am         timestamptz NOT NULL DEFAULT clock_timestamp(),
    angelegt_von        text NOT NULL,
    zurueckgenommen_am  timestamptz,
    zurueckgenommen_von text,
    loeschen_ab         date
);
CREATE INDEX zuschlag_hand_person ON personal.zuschlag_hand (person, monat);

CREATE TABLE personal.lohnvorbereitung (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    person         uuid NOT NULL REFERENCES personal.person,
    monat          date NOT NULL CHECK (extract(day FROM monat) = 1),
    fassung        integer NOT NULL DEFAULT 1 CHECK (fassung >= 1),
    zustand        text NOT NULL DEFAULT 'offen' CHECK (zustand IN ('offen', 'geprueft', 'uebergeben')),
    inhalt         jsonb,                                -- eingefroren mit der Prüfung; offen wird gerechnet
    bemerkung      text CHECK (bemerkung IS NULL OR length(bemerkung) <= 500),
    vermerk        text CHECK (vermerk IS NULL OR length(vermerk) <= 200),   -- warum es diese weitere Fassung gibt
    angelegt_am    timestamptz NOT NULL DEFAULT clock_timestamp(),
    angelegt_von   text NOT NULL,
    geprueft_am    timestamptz,
    geprueft_von   text,
    uebergeben_am  timestamptz,
    uebergeben_von text,
    loeschen_ab    date,
    UNIQUE (person, monat, fassung),
    CHECK ((zustand = 'offen') = (inhalt IS NULL)),
    CHECK (fassung = 1 OR vermerk IS NOT NULL)
);

-- ---------------------------------------------------------------------------
-- Hilfen
-- ---------------------------------------------------------------------------
-- Die geltende Fassung einer Regel an einem Tag
CREATE FUNCTION personal._regel_fassung_am(p_regel uuid, p_tag date) RETURNS uuid LANGUAGE sql STABLE AS $$
    SELECT id FROM personal.zuschlagsregel_fassung WHERE regel = p_regel AND gueltig_ab <= p_tag ORDER BY gueltig_ab DESC, angelegt_am DESC LIMIT 1
$$;

-- **Anschlussstelle der Quellen.** Liefert zu einer Fassung einer Regel, einer Person und einem Monat
--   {"betrag_cent": ganze Zahl, "nachweis": [{"datum", "text", "menge", "grundlage_cent", "betrag_cent"}, …]}
-- oder NULL, wenn die Quelle fehlt oder der Monat dort nicht abgeschlossen ist (PE11: ohne Nachweis kein Betrag).
-- Heute gibt es keine Quelle. Das Modul, das sie bringt, ersetzt diese Funktion.
CREATE FUNCTION personal._zuschlag_nachweis(p_fassung uuid, p_person uuid, p_monat date) RETURNS jsonb LANGUAGE sql STABLE AS $$
    SELECT NULL::jsonb
$$;

-- Was das Lohnbüro für einen Monat und eine Person braucht – eine Stelle für Anzeige und Prüfung.
CREATE FUNCTION personal._lohn_inhalt(p_person uuid, p_monat date) RETURNS jsonb LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE p personal.person; v personal.vertrag; ende date := (p_monat + interval '1 month' - interval '1 day')::date;
        eintritt date; von date; bis date; soll integer := 0; arbeitstage integer := 0; abw jsonb; summen jsonb; aenderungen jsonb; zus jsonb := '[]'; hand jsonb;
        ort uuid; r record; n jsonb; summe bigint := 0; hsumme bigint;
BEGIN
    SELECT * INTO p FROM personal.person WHERE id = p_person;
    IF NOT FOUND THEN PERFORM personal._fehler('Person unbekannt', 'KM003'); END IF;
    SELECT min(beginn) INTO eintritt FROM personal.vertrag WHERE person = p_person;
    SELECT * INTO v FROM personal.vertrag WHERE id = personal.vertrag_am(p_person, least(ende, coalesce(p.austritt, ende)));
    von := greatest(p_monat, coalesce(eintritt, p_monat));
    bis := least(ende, coalesce(p.austritt, ende));
    ort := personal._arbeitsort_von(p_person);

    -- Soll aus dem Vertrag des jeweiligen Tages, ohne Feiertage am Arbeitsort und freie Tage des Betriebs
    IF eintritt IS NOT NULL AND von <= bis THEN
        SELECT coalesce(sum(m), 0), count(*) FILTER (WHERE m > 0) INTO soll, arbeitstage
          FROM (SELECT (SELECT x.verteilung[extract(isodow FROM g.t)::integer] FROM personal.vertrag x WHERE x.id = personal.vertrag_am(p_person, g.t::date)) AS m
                  FROM generate_series(von::timestamp, bis::timestamp, interval '1 day') AS g(t)
                 WHERE NOT EXISTS (SELECT 1 FROM personal.freier_tag f WHERE f.datum = g.t::date)
                   AND NOT EXISTS (SELECT 1 FROM personal.feiertage(ort, von, bis) ft WHERE ft.datum = g.t::date)) s;
    END IF;

    -- Abwesenheiten, die gelten und den Monat berühren; über die Monatsgrenze nur mit dem Teil im Monat
    WITH z AS (
        SELECT art.name, art.bezahlt, greatest(x.von, p_monat) AS von, least(x.bis, ende) AS bis, x.halber_tag,
               (x.von < p_monat OR x.bis > ende) AS anteilig,
               CASE WHEN x.von >= p_monat AND x.bis <= ende THEN x.tage
                    ELSE personal._arbeitstage(p_person, greatest(x.von, p_monat), least(x.bis, ende)) END AS tage
          FROM personal.abwesenheit x JOIN personal.abwesenheitsart art ON art.id = x.art
         WHERE x.person = p_person AND x.zustand = 'genehmigt' AND x.von <= ende AND x.bis >= p_monat)
    SELECT coalesce((SELECT jsonb_agg(jsonb_build_object('art', z.name, 'bezahlt', z.bezahlt, 'von', z.von::text, 'bis', z.bis::text, 'tage', z.tage,
                                                         'halber_tag', z.halber_tag, 'anteilig', z.anteilig) ORDER BY z.von, z.name) FROM z), '[]'),
           coalesce((SELECT jsonb_agg(jsonb_build_object('art', s.name, 'bezahlt', s.bezahlt, 'tage', s.tage) ORDER BY s.name)
                       FROM (SELECT z.name, z.bezahlt, sum(z.tage) AS tage FROM z GROUP BY z.name, z.bezahlt) s), '[]')
      INTO abw, summen;

    -- Fassungen des Vertrags, die im Monat zu gelten beginnen (ohne die erste): nur die Namen der geänderten Felder
    SELECT coalesce(jsonb_agg(jsonb_build_object('gueltig_ab', f.gueltig_ab::text, 'felder', f.felder) ORDER BY f.gueltig_ab, f.angelegt_am), '[]') INTO aenderungen
      FROM (SELECT x.gueltig_ab, x.angelegt_am,
                   personal._geaendert(to_jsonb(lag(x) OVER w) - ARRAY['id', 'gueltig_ab', 'angelegt_am', 'angelegt_von', 'loeschen_ab'],
                                       to_jsonb(x) - ARRAY['id', 'gueltig_ab', 'angelegt_am', 'angelegt_von', 'loeschen_ab']) AS felder,
                   row_number() OVER w AS nr
              FROM personal.vertrag x WHERE x.person = p_person WINDOW w AS (ORDER BY x.gueltig_ab, x.angelegt_am)) f
     WHERE f.nr > 1 AND f.gueltig_ab BETWEEN p_monat AND ende;

    -- Zuschläge aus Regeln: an der Person oder an ihrer Funktion, mit der Fassung, die am Monatsende gilt
    FOR r IN SELECT DISTINCT ON (g.id) g.id, g.name, g.grundlage, g.lohnart, fa.id AS fassung, fa.gueltig_ab
               FROM personal.zuschlag_zuordnung z JOIN personal.zuschlagsregel g ON g.id = z.regel AND NOT g.stillgelegt
               JOIN personal.zuschlagsregel_fassung fa ON fa.id = personal._regel_fassung_am(g.id, ende)
              WHERE z.von <= ende AND (z.bis IS NULL OR z.bis >= p_monat)
                AND (z.person = p_person OR (z.funktion IS NOT NULL AND lower(trim(z.funktion)) = lower(trim(coalesce(v.funktion, '')))))
              ORDER BY g.id LOOP
        n := personal._zuschlag_nachweis(r.fassung, p_person, p_monat);
        zus := zus || jsonb_build_object('art', 'regel', 'regel', r.id::text, 'name', r.name, 'grundlage', r.grundlage, 'lohnart', r.lohnart, 'fassung_ab', r.gueltig_ab::text,
            'betrag_cent', n->'betrag_cent', 'nachweis', n->'nachweis',
            'hinweis', CASE WHEN n IS NULL THEN 'Nachweis fehlt – ' || CASE r.grundlage WHEN 'zeit' THEN 'kein freigegebenes Monatsblatt (Zeiterfassung)' ELSE 'keine bestätigten Leistungen (Modul Leistungen)' END END);
        summe := summe + coalesce((n->>'betrag_cent')::bigint, 0);
    END LOOP;
    zus := (SELECT coalesce(jsonb_agg(e ORDER BY e->>'name'), '[]') FROM jsonb_array_elements(zus) e);

    -- Zuschläge von Hand
    SELECT coalesce(jsonb_agg(jsonb_build_object('art', 'hand', 'id', h.id::text, 'name', h.begruendung, 'lohnart', h.lohnart, 'betrag_cent', h.betrag_cent) ORDER BY h.angelegt_am), '[]'),
           coalesce(sum(h.betrag_cent), 0)
      INTO hand, hsumme FROM personal.zuschlag_hand h WHERE h.person = p_person AND h.monat = p_monat AND h.zurueckgenommen_am IS NULL;
    summe := summe + hsumme;

    RETURN jsonb_build_object(
        'monat', to_char(p_monat, 'YYYY-MM'),
        'person', jsonb_build_object('personalnummer', p.personalnummer, 'familienname', p.familienname, 'vornamen', p.vornamen),
        'vertrag', CASE WHEN v.id IS NULL THEN NULL ELSE jsonb_build_object('art', v.art, 'funktion', v.funktion, 'wochenminuten', v.wochenminuten, 'lohnbuch', v.lohnbuch,
                        'beginn', v.beginn::text, 'befristet_bis', v.befristet_bis::text) END,
        'eintritt', CASE WHEN eintritt BETWEEN p_monat AND ende THEN eintritt::text END,
        'austritt', CASE WHEN p.austritt BETWEEN p_monat AND ende THEN p.austritt::text END,
        'soll_minuten', soll, 'arbeitstage', arbeitstage,
        'ist_minuten', NULL, 'ist_hinweis', 'Nachweis fehlt – Ist- und Überstunden kommen aus der Zeiterfassung',
        'abwesenheiten', abw, 'abwesenheit_summen', summen, 'vertragsaenderungen', aenderungen,
        'zuschlaege', zus || hand, 'zuschlag_summe_cent', summe);
END $$;

-- Wer im Monat beschäftigt ist: ein Vertrag hat begonnen, und die Person ist nicht vorher ausgeschieden
CREATE FUNCTION personal._im_monat_beschaeftigt(p_person uuid, p_monat date) RETURNS boolean LANGUAGE sql STABLE AS $$
    SELECT EXISTS (SELECT 1 FROM personal.person p WHERE p.id = p_person AND (p.austritt IS NULL OR p.austritt >= p_monat)
                      AND EXISTS (SELECT 1 FROM personal.vertrag v WHERE v.person = p.id AND v.beginn < p_monat + interval '1 month'))
$$;

-- Die letzte Fassung der Lohnvorbereitung eines Monats; gesperrt für die Dauer der Änderung
CREATE FUNCTION personal._lohn_letzte(p_person uuid, p_monat date) RETURNS personal.lohnvorbereitung LANGUAGE sql AS $$
    SELECT * FROM personal.lohnvorbereitung WHERE person = p_person AND monat = p_monat ORDER BY fassung DESC LIMIT 1 FOR UPDATE
$$;

CREATE FUNCTION personal._lohn_monat_pruefen(p_person uuid, p_monat date) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    IF p_monat IS NULL OR extract(day FROM p_monat) <> 1 THEN PERFORM personal._fehler('Monat fehlt'); END IF;
    IF p_monat > date_trunc('month', current_date) THEN PERFORM personal._fehler('Der Monat liegt in der Zukunft'); END IF;
    IF NOT EXISTS (SELECT 1 FROM personal.person WHERE id = p_person) THEN PERFORM personal._fehler('Person unbekannt', 'KM003'); END IF;
    IF NOT personal._im_monat_beschaeftigt(p_person, p_monat) THEN PERFORM personal._fehler('Die Person ist in diesem Monat nicht beschäftigt'); END IF;
END $$;

-- Solange die Lohnvorbereitung des Monats geprüft oder übergeben ist, ändert sich an ihren Zuschlägen nichts.
CREATE FUNCTION personal._lohn_offen_verlangen(p_person uuid, p_monat date) RETURNS void LANGUAGE plpgsql AS $$
DECLARE l personal.lohnvorbereitung := personal._lohn_letzte(p_person, p_monat);
BEGIN
    IF l.zustand = 'geprueft' THEN
        PERFORM personal._fehler('Die Lohnvorbereitung dieses Monats ist geprüft – erst die Prüfung zurücknehmen', 'KM003');
    ELSIF l.zustand = 'uebergeben' THEN
        PERFORM personal._fehler('Die Lohnvorbereitung dieses Monats ist übergeben – Nachtrag im Folgemonat oder eine neue Fassung mit Vermerk', 'KM003');
    END IF;
END $$;

-- ---------------------------------------------------------------------------
-- Zuschlagsregeln
-- ---------------------------------------------------------------------------
-- p: name, grundlage (nur beim Anlegen), lohnart, stillgelegt
CREATE FUNCTION personal.zuschlagsregel_setzen(p_subjekt text, p_id uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE alt personal.zuschlagsregel; neu uuid;
BEGIN
    IF personal._t(p, 'name') IS NULL THEN PERFORM personal._fehler('Die Regel braucht einen Namen'); END IF;
    IF EXISTS (SELECT 1 FROM personal.zuschlagsregel WHERE lower(name) = lower(personal._t(p, 'name')) AND id IS DISTINCT FROM p_id) THEN
        PERFORM personal._fehler('Eine Regel mit diesem Namen gibt es schon', 'KM003');
    END IF;
    IF p_id IS NULL THEN
        IF coalesce(p->>'grundlage', '') NOT IN ('leistung', 'zeit') THEN PERFORM personal._fehler('Grundlage: Leistung oder Zeit'); END IF;
        INSERT INTO personal.zuschlagsregel (name, grundlage, lohnart, angelegt_von)
        VALUES (personal._t(p, 'name'), p->>'grundlage', personal._t(p, 'lohnart'), p_subjekt) RETURNING id INTO neu;
        PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.zuschlagsregel_angelegt', jsonb_build_object('regel', neu::text));
        RETURN neu;
    END IF;
    SELECT * INTO alt FROM personal.zuschlagsregel WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Regel unbekannt', 'KM003'); END IF;
    IF p ? 'grundlage' AND p->>'grundlage' IS DISTINCT FROM alt.grundlage THEN PERFORM personal._fehler('Die Grundlage einer Regel ändert sich nicht – lege eine neue Regel an'); END IF;
    UPDATE personal.zuschlagsregel SET name = personal._t(p, 'name'), lohnart = personal._t(p, 'lohnart'), stillgelegt = coalesce((p->>'stillgelegt')::boolean, stillgelegt) WHERE id = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.zuschlagsregel_geaendert', jsonb_build_object('regel', p_id::text));
    RETURN p_id;
EXCEPTION WHEN check_violation THEN
    PERFORM personal._fehler('Regel: Name höchstens 60, Lohnart höchstens 20 Zeichen');
    RETURN NULL;
END $$;

-- p: gueltig_ab, form, satz_bp, betrag_cent, schwelle_cent, satz_ab_schwelle_bp, umfang, bezug, zeitart
CREATE FUNCTION personal.zuschlagsregel_fassung(p_subjekt text, p_regel uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE g personal.zuschlagsregel; neu uuid; f text := p->>'form'; ab date := personal._datum(p, 'gueltig_ab');
        zahl CONSTANT text := '^[0-9]{1,10}$';
BEGIN
    SELECT * INTO g FROM personal.zuschlagsregel WHERE id = p_regel;
    IF NOT FOUND THEN PERFORM personal._fehler('Regel unbekannt', 'KM003'); END IF;
    IF ab IS NULL THEN PERFORM personal._fehler('Gültig ab fehlt'); END IF;
    IF EXISTS (SELECT 1 FROM jsonb_each_text(p) e WHERE e.key IN ('satz_bp', 'betrag_cent', 'schwelle_cent', 'satz_ab_schwelle_bp') AND e.value IS NOT NULL AND e.value !~ zahl) THEN
        PERFORM personal._fehler('Sätze und Beträge sind ganze Zahlen (Hundertstel Prozent, Cent)');
    END IF;
    IF g.grundlage = 'zeit' THEN
        IF f IS DISTINCT FROM 'zeitfenster' OR p->>'satz_bp' IS NULL OR p->>'zeitart' IS NULL THEN
            PERFORM personal._fehler('Ein Zeitzuschlag braucht das Fenster (Nacht, Sonntag, Feiertag, Überstunden) und den Satz in Prozent des Grundlohns');
        END IF;
    ELSE
        IF f IS NULL OR f NOT IN ('anteil', 'betrag', 'staffel') THEN PERFORM personal._fehler('Form: Anteil am Betrag, fester Betrag je Leistung oder Staffel'); END IF;
        IF coalesce(p->>'umfang', '') NOT IN ('alle', 'gruppe', 'leistung') THEN PERFORM personal._fehler('Umfang: alle Leistungen, eine Gruppe oder eine einzelne Leistung'); END IF;
        IF p->>'umfang' <> 'alle' AND personal._t(p, 'bezug') IS NULL THEN PERFORM personal._fehler('Zu Gruppe oder einzelner Leistung gehört deren Bezeichnung'); END IF;
        IF f = 'betrag' AND p->>'betrag_cent' IS NULL THEN PERFORM personal._fehler('Fester Betrag je Leistung fehlt'); END IF;
        IF f IN ('anteil', 'staffel') AND p->>'satz_bp' IS NULL THEN PERFORM personal._fehler('Satz in Prozent fehlt'); END IF;
        IF f = 'staffel' AND (p->>'schwelle_cent' IS NULL OR p->>'satz_ab_schwelle_bp' IS NULL) THEN PERFORM personal._fehler('Eine Staffel braucht die Schwelle im Monat und den Satz ab der Schwelle'); END IF;
    END IF;
    INSERT INTO personal.zuschlagsregel_fassung (regel, gueltig_ab, form, satz_bp, betrag_cent, schwelle_cent, satz_ab_schwelle_bp, umfang, bezug, zeitart, angelegt_von)
    VALUES (p_regel, ab, f,
            CASE WHEN f <> 'betrag' THEN (p->>'satz_bp')::integer END, CASE WHEN f = 'betrag' THEN (p->>'betrag_cent')::integer END,
            CASE WHEN f = 'staffel' THEN (p->>'schwelle_cent')::integer END, CASE WHEN f = 'staffel' THEN (p->>'satz_ab_schwelle_bp')::integer END,
            CASE WHEN g.grundlage = 'leistung' THEN p->>'umfang' END, CASE WHEN g.grundlage = 'leistung' AND p->>'umfang' <> 'alle' THEN personal._t(p, 'bezug') END,
            CASE WHEN g.grundlage = 'zeit' THEN p->>'zeitart' END, p_subjekt)
    RETURNING id INTO neu;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.zuschlagsregel_fassung_angelegt', jsonb_build_object('regel', p_regel::text, 'fassung', neu::text));
    RETURN neu;
EXCEPTION WHEN check_violation OR numeric_value_out_of_range THEN
    PERFORM personal._fehler('Fassung: ein Wert ist unbekannt oder liegt außerhalb');
    RETURN NULL;
END $$;

-- p: person ODER funktion, von, bis
CREATE FUNCTION personal.zuschlag_zuordnen(p_subjekt text, p_regel uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE neu uuid; v_person uuid := nullif(p->>'person', '')::uuid; v_funktion text := personal._t(p, 'funktion');
BEGIN
    IF NOT EXISTS (SELECT 1 FROM personal.zuschlagsregel WHERE id = p_regel AND NOT stillgelegt) THEN PERFORM personal._fehler('Regel unbekannt oder stillgelegt', 'KM003'); END IF;
    IF (v_person IS NULL) = (v_funktion IS NULL) THEN PERFORM personal._fehler('Zuordnen an eine Person oder an eine Funktion – eines von beiden'); END IF;
    IF v_person IS NOT NULL AND NOT EXISTS (SELECT 1 FROM personal.person WHERE id = v_person) THEN PERFORM personal._fehler('Person unbekannt', 'KM003'); END IF;
    IF personal._datum(p, 'von') IS NULL THEN PERFORM personal._fehler('Von fehlt'); END IF;
    IF personal._datum(p, 'bis') < personal._datum(p, 'von') THEN PERFORM personal._fehler('Bis liegt vor Von'); END IF;
    INSERT INTO personal.zuschlag_zuordnung (regel, person, funktion, von, bis, angelegt_von)
    VALUES (p_regel, v_person, v_funktion, personal._datum(p, 'von'), personal._datum(p, 'bis'), p_subjekt) RETURNING id INTO neu;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.zuschlag_zugeordnet',
        jsonb_strip_nulls(jsonb_build_object('regel', p_regel::text, 'zuordnung', neu::text, 'person', v_person::text)));
    RETURN neu;
END $$;

CREATE FUNCTION personal.zuschlag_zuordnung_beenden(p_subjekt text, p_id uuid, p_bis date)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE z personal.zuschlag_zuordnung;
BEGIN
    SELECT * INTO z FROM personal.zuschlag_zuordnung WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Zuordnung unbekannt', 'KM003'); END IF;
    IF p_bis IS NULL OR p_bis < z.von THEN PERFORM personal._fehler('Das Ende fehlt oder liegt vor dem Beginn der Zuordnung'); END IF;
    UPDATE personal.zuschlag_zuordnung SET bis = p_bis WHERE id = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.zuschlag_zuordnung_beendet',
        jsonb_strip_nulls(jsonb_build_object('regel', z.regel::text, 'zuordnung', p_id::text, 'person', z.person::text)));
END $$;

-- ---------------------------------------------------------------------------
-- Zuschlag von Hand
-- ---------------------------------------------------------------------------
CREATE FUNCTION personal.zuschlag_hand_anlegen(p_subjekt text, p_person uuid, p_monat date, p_betrag_cent integer, p_begruendung text, p_lohnart text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE neu uuid;
BEGIN
    PERFORM personal._lohn_monat_pruefen(p_person, p_monat);
    PERFORM personal._lohn_offen_verlangen(p_person, p_monat);
    IF p_betrag_cent IS NULL OR p_betrag_cent = 0 THEN PERFORM personal._fehler('Der Betrag fehlt'); END IF;
    IF nullif(trim(p_begruendung), '') IS NULL OR length(p_begruendung) > 200 THEN PERFORM personal._fehler('Ein Zuschlag von Hand braucht eine Begründung (höchstens 200 Zeichen)'); END IF;
    INSERT INTO personal.zuschlag_hand (person, monat, betrag_cent, begruendung, lohnart, angelegt_von)
    VALUES (p_person, p_monat, p_betrag_cent, trim(p_begruendung), nullif(trim(p_lohnart), ''), p_subjekt) RETURNING id INTO neu;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.zuschlag_hand_angelegt',
        jsonb_build_object('person', p_person::text, 'zuschlag', neu::text, 'monat', to_char(p_monat, 'YYYY-MM')));
    RETURN neu;
EXCEPTION WHEN check_violation THEN
    PERFORM personal._fehler('Zuschlag: Betrag außerhalb oder Lohnart zu lang');
    RETURN NULL;
END $$;

CREATE FUNCTION personal.zuschlag_hand_zuruecknehmen(p_subjekt text, p_id uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE z personal.zuschlag_hand;
BEGIN
    SELECT * INTO z FROM personal.zuschlag_hand WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Zuschlag unbekannt', 'KM003'); END IF;
    IF z.zurueckgenommen_am IS NOT NULL THEN PERFORM personal._fehler('Der Zuschlag ist schon zurückgenommen', 'KM003'); END IF;
    PERFORM personal._lohn_offen_verlangen(z.person, z.monat);
    UPDATE personal.zuschlag_hand SET zurueckgenommen_am = clock_timestamp(), zurueckgenommen_von = p_subjekt WHERE id = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.zuschlag_hand_zurueckgenommen',
        jsonb_build_object('person', z.person::text, 'zuschlag', p_id::text, 'monat', to_char(z.monat, 'YYYY-MM')));
END $$;

-- ---------------------------------------------------------------------------
-- Lohnvorbereitung: offen → geprüft → übergeben
-- ---------------------------------------------------------------------------
CREATE FUNCTION personal._lohn_zeile(p_subjekt text, p_person uuid, p_monat date) RETURNS personal.lohnvorbereitung LANGUAGE plpgsql AS $$
DECLARE l personal.lohnvorbereitung;
BEGIN
    PERFORM personal._lohn_monat_pruefen(p_person, p_monat);
    l := personal._lohn_letzte(p_person, p_monat);
    IF l.id IS NULL THEN
        INSERT INTO personal.lohnvorbereitung (person, monat, angelegt_von) VALUES (p_person, p_monat, p_subjekt) RETURNING * INTO l;
    END IF;
    RETURN l;
END $$;

CREATE FUNCTION personal.lohn_bemerkung_setzen(p_subjekt text, p_person uuid, p_monat date, p_bemerkung text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE l personal.lohnvorbereitung;
BEGIN
    IF length(coalesce(p_bemerkung, '')) > 500 THEN PERFORM personal._fehler('Bemerkung: höchstens 500 Zeichen'); END IF;
    PERFORM personal._lohn_offen_verlangen(p_person, p_monat);
    l := personal._lohn_zeile(p_subjekt, p_person, p_monat);
    UPDATE personal.lohnvorbereitung SET bemerkung = nullif(trim(p_bemerkung), '') WHERE id = l.id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.lohn_bemerkt',
        jsonb_build_object('person', p_person::text, 'lohnvorbereitung', l.id::text, 'monat', to_char(p_monat, 'YYYY-MM')));
END $$;

CREATE FUNCTION personal.lohn_pruefen(p_subjekt text, p_person uuid, p_monat date)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE l personal.lohnvorbereitung;
BEGIN
    l := personal._lohn_zeile(p_subjekt, p_person, p_monat);
    IF l.zustand <> 'offen' THEN PERFORM personal._fehler('Die Lohnvorbereitung ist schon geprüft', 'KM003'); END IF;
    UPDATE personal.lohnvorbereitung SET zustand = 'geprueft', inhalt = personal._lohn_inhalt(p_person, p_monat), geprueft_am = clock_timestamp(), geprueft_von = p_subjekt WHERE id = l.id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.lohn_geprueft',
        jsonb_build_object('person', p_person::text, 'lohnvorbereitung', l.id::text, 'monat', to_char(p_monat, 'YYYY-MM')));
    RETURN l.id;
END $$;

CREATE FUNCTION personal.lohn_oeffnen(p_subjekt text, p_person uuid, p_monat date)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE l personal.lohnvorbereitung := personal._lohn_letzte(p_person, p_monat);
BEGIN
    IF l.id IS NULL OR l.zustand = 'offen' THEN PERFORM personal._fehler('Die Lohnvorbereitung ist nicht geprüft', 'KM003'); END IF;
    IF l.zustand = 'uebergeben' THEN PERFORM personal._fehler('Übergeben ist übergeben – berichtigt wird über einen Nachtrag im Folgemonat oder eine neue Fassung', 'KM003'); END IF;
    UPDATE personal.lohnvorbereitung SET zustand = 'offen', inhalt = NULL, geprueft_am = NULL, geprueft_von = NULL WHERE id = l.id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.lohn_pruefung_zurueckgenommen',
        jsonb_build_object('person', p_person::text, 'lohnvorbereitung', l.id::text, 'monat', to_char(p_monat, 'YYYY-MM')));
END $$;

CREATE FUNCTION personal.lohn_uebergeben(p_subjekt text, p_person uuid, p_monat date)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE l personal.lohnvorbereitung := personal._lohn_letzte(p_person, p_monat);
BEGIN
    IF l.id IS NULL OR l.zustand = 'offen' THEN PERFORM personal._fehler('Übergeben wird nur, was geprüft ist', 'KM003'); END IF;
    IF l.zustand = 'uebergeben' THEN PERFORM personal._fehler('Die Lohnvorbereitung ist schon übergeben', 'KM003'); END IF;
    UPDATE personal.lohnvorbereitung SET zustand = 'uebergeben', uebergeben_am = clock_timestamp(), uebergeben_von = p_subjekt WHERE id = l.id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.lohn_uebergeben',
        jsonb_build_object('person', p_person::text, 'lohnvorbereitung', l.id::text, 'monat', to_char(p_monat, 'YYYY-MM')));
END $$;

-- Berichtigung nach der Übergabe: eine weitere Fassung mit Vermerk; die übergebene bleibt stehen.
CREATE FUNCTION personal.lohn_neue_fassung(p_subjekt text, p_person uuid, p_monat date, p_vermerk text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE l personal.lohnvorbereitung := personal._lohn_letzte(p_person, p_monat); neu uuid;
BEGIN
    IF l.id IS NULL OR l.zustand <> 'uebergeben' THEN PERFORM personal._fehler('Eine neue Fassung gibt es erst nach der Übergabe', 'KM003'); END IF;
    IF nullif(trim(p_vermerk), '') IS NULL OR length(p_vermerk) > 200 THEN PERFORM personal._fehler('Eine neue Fassung braucht einen Vermerk (höchstens 200 Zeichen)'); END IF;
    INSERT INTO personal.lohnvorbereitung (person, monat, fassung, vermerk, bemerkung, angelegt_von)
    VALUES (p_person, p_monat, l.fassung + 1, trim(p_vermerk), l.bemerkung, p_subjekt) RETURNING id INTO neu;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.lohn_neue_fassung',
        jsonb_build_object('person', p_person::text, 'lohnvorbereitung', neu::text, 'monat', to_char(p_monat, 'YYYY-MM')));
    RETURN neu;
END $$;

-- ---------------------------------------------------------------------------
-- Löschfrist: zwei weitere Arten von Objekten (PE12)
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION personal.loeschfrist_setzen(p_subjekt text, p_objektart text, p_id uuid, p_loeschen_ab date, p_grund text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE alt date; v_person uuid; gefunden boolean := false; tabelle text; spalte text := 'person'; schluessel text := 'id';
BEGIN
    IF nullif(trim(p_grund), '') IS NULL OR length(p_grund) > 200 THEN
        PERFORM personal._fehler('Eine Löschfrist braucht eine Begründung (höchstens 200 Zeichen)');
    END IF;
    tabelle := CASE p_objektart WHEN 'person' THEN 'person' WHEN 'privat' THEN 'privat' WHEN 'vertrag' THEN 'vertrag' WHEN 'frist' THEN 'frist'
                                WHEN 'vorschlag' THEN 'vorschlag' WHEN 'abwesenheit' THEN 'abwesenheit' WHEN 'lohnvorbereitung' THEN 'lohnvorbereitung'
                                WHEN 'zuschlag' THEN 'zuschlag_hand' END;
    IF tabelle IS NULL THEN PERFORM personal._fehler('Art des Objekts unbekannt'); END IF;
    IF p_objektart = 'person' THEN spalte := 'id'; ELSIF p_objektart = 'privat' THEN schluessel := 'person'; END IF;
    -- der Name der Tabelle kommt aus der festen Liste oben, nie aus der Eingabe
    EXECUTE format('SELECT loeschen_ab, %I, true FROM personal.%I WHERE %I = $1 FOR UPDATE', spalte, tabelle, schluessel)
       INTO alt, v_person, gefunden USING p_id;
    IF NOT coalesce(gefunden, false) THEN PERFORM personal._fehler('Objekt unbekannt', 'KM003'); END IF;
    EXECUTE format('UPDATE personal.%I SET loeschen_ab = $1 WHERE %I = $2', tabelle, schluessel) USING p_loeschen_ab, p_id;
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
    personal.freier_tag_setzen(text, date, text),
    personal._arbeitsort_von(uuid), personal.feiertage(uuid, date, date), personal.feiertag_setzen(text, text, jsonb), personal.arbeitsort_setzen(text, uuid, jsonb), personal.person_arbeitsort_setzen(text, uuid, uuid),
    personal.abwesenheit_tage_setzen(text, uuid, numeric, numeric),
    personal.urlaubskonto_setzen(text, uuid, integer, numeric, numeric, numeric), personal.urlaubsregel_setzen(text, integer, jsonb),
    personal._lohn_inhalt(uuid, date), personal._im_monat_beschaeftigt(uuid, date), personal._regel_fassung_am(uuid, date),
    personal.zuschlagsregel_setzen(text, uuid, jsonb), personal.zuschlagsregel_fassung(text, uuid, jsonb),
    personal.zuschlag_zuordnen(text, uuid, jsonb), personal.zuschlag_zuordnung_beenden(text, uuid, date),
    personal.zuschlag_hand_anlegen(text, uuid, date, integer, text, text), personal.zuschlag_hand_zuruecknehmen(text, uuid),
    personal.lohn_bemerkung_setzen(text, uuid, date, text), personal.lohn_pruefen(text, uuid, date), personal.lohn_oeffnen(text, uuid, date),
    personal.lohn_uebergeben(text, uuid, date), personal.lohn_neue_fassung(text, uuid, date, text) TO kumero_app;
