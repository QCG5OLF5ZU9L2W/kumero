-- Modul personal 0.14.0: Vergütung als Raster an der Person (F157).
--
-- Vorgabe des Verantwortlichen vom 2026-11-21 am Dialog „Vertrag: neue Fassung“: Die Vergütung ändert sich mit Verträgen, mit
-- Tarifverträgen und mit Leistungs- und Altersstufen – besser ein Raster mit „gültig ab“, Eingruppierung, Leistungsstufe und
-- Altersstufe, jeweils mit Brutto, dazu Urlaubs- und Weihnachtsgeld in Prozent oder als Betrag.
--
--   personal.entgelt – eine Zeile je Person und „gültig ab“; löst personal.verguetung (ein Satz je Fassung, F153) ab.
--
-- Eine Zeile gilt ab ihrem Tag, bis die nächste gilt. Die Fassung des Vertrags trägt keine Vergütung mehr: Eine neue Stufe oder
-- ein neuer Tarifabschluss ist eine neue Zeile, kein neuer Vertrag.

CREATE TABLE personal.entgelt (
    id                   uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    person               uuid NOT NULL REFERENCES personal.person ON DELETE CASCADE,
    gueltig_ab           date NOT NULL,
    art                  text NOT NULL CHECK (art IN ('monat', 'stunde')),                 -- die Beträge gelten je Monat oder je Stunde, brutto
    eingruppierung       text CHECK (eingruppierung IS NULL OR length(eingruppierung) BETWEEN 1 AND 60),
    grund_betrag         numeric(9,2) CHECK (grund_betrag > 0),                            -- Brutto der Eingruppierung
    leistungsstufe       text CHECK (leistungsstufe IS NULL OR length(leistungsstufe) BETWEEN 1 AND 60),
    leistung_betrag      numeric(9,2) CHECK (leistung_betrag > 0),                         -- Brutto der Leistungsstufe
    altersstufe          text CHECK (altersstufe IS NULL OR length(altersstufe) BETWEEN 1 AND 60),
    alter_betrag         numeric(9,2) CHECK (alter_betrag > 0),                            -- Brutto der Altersstufe
    urlaubsgeld_art      text NOT NULL DEFAULT 'keins' CHECK (urlaubsgeld_art IN ('keins', 'betrag', 'prozent')),
    urlaubsgeld_wert     numeric(9,2) CHECK (urlaubsgeld_wert > 0),                        -- Euro oder Prozent eines Monatsbruttos
    urlaubsgeld_monat    smallint CHECK (urlaubsgeld_monat BETWEEN 1 AND 12),
    weihnachtsgeld_art   text NOT NULL DEFAULT 'keins' CHECK (weihnachtsgeld_art IN ('keins', 'betrag', 'prozent')),
    weihnachtsgeld_wert  numeric(9,2) CHECK (weihnachtsgeld_wert > 0),
    weihnachtsgeld_monat smallint CHECK (weihnachtsgeld_monat BETWEEN 1 AND 12),
    vereinbarung         text CHECK (vereinbarung IS NULL OR length(vereinbarung) BETWEEN 1 AND 1000),
    angelegt_am          timestamptz NOT NULL DEFAULT clock_timestamp(),
    angelegt_von         text NOT NULL,
    geaendert_am         timestamptz,
    geaendert_von        text,
    UNIQUE (person, gueltig_ab),
    CHECK (coalesce(grund_betrag, leistung_betrag, alter_betrag) IS NOT NULL),            -- wenigstens ein Brutto
    CHECK ((urlaubsgeld_art = 'keins') = (urlaubsgeld_wert IS NULL) AND (urlaubsgeld_art <> 'keins' OR urlaubsgeld_monat IS NULL)),
    CHECK ((weihnachtsgeld_art = 'keins') = (weihnachtsgeld_wert IS NULL) AND (weihnachtsgeld_art <> 'keins' OR weihnachtsgeld_monat IS NULL)),
    CHECK (art = 'monat' OR (urlaubsgeld_art <> 'prozent' AND weihnachtsgeld_art <> 'prozent'))
);
REVOKE ALL ON personal.entgelt FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON personal.entgelt TO kumero_app;   -- kein Zugriff für kumero_lesen (F136)

-- Bestand übernehmen: Die Vergütung einer Fassung wird die Zeile zum „gültig ab“ der Fassung, ihr Betrag das Brutto der
-- Eingruppierung. Fassungen, die nur übernommen haben, was vorher galt (VG3), ergeben keine eigene Zeile.
INSERT INTO personal.entgelt (person, gueltig_ab, art, grund_betrag, urlaubsgeld_art, urlaubsgeld_wert, urlaubsgeld_monat,
                              weihnachtsgeld_art, weihnachtsgeld_wert, weihnachtsgeld_monat, vereinbarung, angelegt_am, angelegt_von)
SELECT person, gueltig_ab, art, betrag, urlaubsgeld_art, urlaubsgeld_wert, urlaubsgeld_monat, weihnachtsgeld_art, weihnachtsgeld_wert, weihnachtsgeld_monat, vereinbarung, angelegt_am, angelegt_von
  FROM (SELECT x.*, lag(x.inhalt) OVER (PARTITION BY x.person ORDER BY x.gueltig_ab) AS davor
          FROM (SELECT DISTINCT ON (v.person, v.gueltig_ab) v.person, v.gueltig_ab, v.angelegt_am, v.angelegt_von, g.*, to_jsonb(g) - 'vertrag' AS inhalt
                  FROM personal.verguetung g JOIN personal.vertrag v ON v.id = g.vertrag
                 ORDER BY v.person, v.gueltig_ab, v.angelegt_am DESC) x) y
 WHERE davor IS DISTINCT FROM inhalt;
DROP TABLE personal.verguetung;

/** Die Zeile, die an einem Tag gilt: die jüngste mit „gültig ab“ bis zu diesem Tag */
CREATE FUNCTION personal.entgelt_am(p_person uuid, p_tag date DEFAULT current_date) RETURNS uuid LANGUAGE sql STABLE AS $$
    SELECT id FROM personal.entgelt WHERE person = p_person AND gueltig_ab <= p_tag ORDER BY gueltig_ab DESC LIMIT 1;
$$;

CREATE FUNCTION personal.entgelt_setzen(p_subjekt text, p_person uuid, p_id uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE alt personal.entgelt; neu personal.entgelt; was text; n text; summe numeric := 0;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM personal.person WHERE id = p_person) THEN PERFORM personal._fehler('Person unbekannt', 'KM003'); END IF;
    IF personal._datum(p, 'gueltig_ab') IS NULL THEN PERFORM personal._fehler('Vergütung: „Gültig ab“ gehört dazu'); END IF;
    IF personal._t(p, 'art') IS NULL OR personal._t(p, 'art') NOT IN ('monat', 'stunde') THEN PERFORM personal._fehler('Vergütung: je Monat oder je Stunde'); END IF;
    FOREACH n IN ARRAY ARRAY['grund_betrag', 'leistung_betrag', 'alter_betrag'] LOOP
        IF personal._t(p, n) IS NULL THEN CONTINUE; END IF;
        IF personal._t(p, n) !~ '^[0-9]{1,7}(\.[0-9]{1,2})?$' OR personal._t(p, n)::numeric <= 0 THEN
            PERFORM personal._fehler('Vergütung: Brutto in Euro, größer als null, höchstens zwei Nachkommastellen');
        END IF;
        summe := summe + personal._t(p, n)::numeric;
    END LOOP;
    IF summe = 0 THEN PERFORM personal._fehler('Vergütung: Wenigstens ein Brutto gehört dazu – an der Eingruppierung, der Leistungsstufe oder der Altersstufe'); END IF;
    FOREACH n IN ARRAY ARRAY['eingruppierung', 'leistungsstufe', 'altersstufe'] LOOP
        IF length(personal._t(p, n)) > 60 THEN PERFORM personal._fehler('Vergütung: Eingruppierung und Stufen höchstens 60 Zeichen'); END IF;
    END LOOP;
    FOREACH was IN ARRAY ARRAY['urlaubsgeld', 'weihnachtsgeld'] LOOP
        IF coalesce(personal._t(p, was || '_art'), 'keins') NOT IN ('keins', 'betrag', 'prozent') THEN
            PERFORM personal._fehler(format('%s: keins, fester Betrag oder Prozent eines Monatsbruttos', initcap(was)));
        END IF;
        IF coalesce(personal._t(p, was || '_art'), 'keins') = 'keins' THEN
            IF personal._t(p, was || '_wert') IS NOT NULL OR personal._t(p, was || '_monat') IS NOT NULL THEN
                PERFORM personal._fehler(format('%s: Wert und Monat gehören nur dazu, wenn es vereinbart ist', initcap(was)));
            END IF;
            CONTINUE;
        END IF;
        IF personal._t(p, was || '_wert') IS NULL OR personal._t(p, was || '_wert') !~ '^[0-9]{1,7}(\.[0-9]{1,2})?$' OR personal._t(p, was || '_wert')::numeric <= 0 THEN
            PERFORM personal._fehler(format('%s: Wert größer als null, höchstens zwei Nachkommastellen', initcap(was)));
        END IF;
        IF personal._t(p, was || '_art') = 'prozent' AND (personal._t(p, 'art') <> 'monat' OR personal._t(p, was || '_wert')::numeric > 300) THEN
            PERFORM personal._fehler(format('%s in Prozent: nur bei Beträgen je Monat, höchstens 300', initcap(was)));
        END IF;
        IF personal._t(p, was || '_monat') IS NOT NULL AND personal._t(p, was || '_monat') !~ '^([1-9]|1[0-2])$' THEN
            PERFORM personal._fehler(format('%s: Monat der Auszahlung von 1 bis 12', initcap(was)));
        END IF;
    END LOOP;
    IF length(personal._t(p, 'vereinbarung')) > 1000 THEN PERFORM personal._fehler('Vergütung: Vereinbarung höchstens 1000 Zeichen'); END IF;
    IF EXISTS (SELECT 1 FROM personal.entgelt WHERE person = p_person AND gueltig_ab = personal._datum(p, 'gueltig_ab') AND id IS DISTINCT FROM p_id) THEN
        PERFORM personal._fehler('Vergütung: Zu diesem Tag gibt es schon eine Zeile – sie lässt sich ändern', 'KM003');
    END IF;
    IF p_id IS NULL THEN
        INSERT INTO personal.entgelt (person, gueltig_ab, art, eingruppierung, grund_betrag, leistungsstufe, leistung_betrag, altersstufe, alter_betrag,
                                      urlaubsgeld_art, urlaubsgeld_wert, urlaubsgeld_monat, weihnachtsgeld_art, weihnachtsgeld_wert, weihnachtsgeld_monat, vereinbarung, angelegt_von)
        VALUES (p_person, personal._datum(p, 'gueltig_ab'), personal._t(p, 'art'),
                personal._t(p, 'eingruppierung'), personal._t(p, 'grund_betrag')::numeric, personal._t(p, 'leistungsstufe'), personal._t(p, 'leistung_betrag')::numeric,
                personal._t(p, 'altersstufe'), personal._t(p, 'alter_betrag')::numeric,
                coalesce(personal._t(p, 'urlaubsgeld_art'), 'keins'), personal._t(p, 'urlaubsgeld_wert')::numeric, personal._t(p, 'urlaubsgeld_monat')::smallint,
                coalesce(personal._t(p, 'weihnachtsgeld_art'), 'keins'), personal._t(p, 'weihnachtsgeld_wert')::numeric, personal._t(p, 'weihnachtsgeld_monat')::smallint,
                personal._t(p, 'vereinbarung'), p_subjekt)
        RETURNING * INTO neu;
    ELSE
        SELECT * INTO alt FROM personal.entgelt WHERE id = p_id AND person = p_person FOR UPDATE;
        IF NOT FOUND THEN PERFORM personal._fehler('Zeile der Vergütung unbekannt', 'KM003'); END IF;
        UPDATE personal.entgelt SET gueltig_ab = personal._datum(p, 'gueltig_ab'), art = personal._t(p, 'art'),
               eingruppierung = personal._t(p, 'eingruppierung'), grund_betrag = personal._t(p, 'grund_betrag')::numeric,
               leistungsstufe = personal._t(p, 'leistungsstufe'), leistung_betrag = personal._t(p, 'leistung_betrag')::numeric,
               altersstufe = personal._t(p, 'altersstufe'), alter_betrag = personal._t(p, 'alter_betrag')::numeric,
               urlaubsgeld_art = coalesce(personal._t(p, 'urlaubsgeld_art'), 'keins'), urlaubsgeld_wert = personal._t(p, 'urlaubsgeld_wert')::numeric, urlaubsgeld_monat = personal._t(p, 'urlaubsgeld_monat')::smallint,
               weihnachtsgeld_art = coalesce(personal._t(p, 'weihnachtsgeld_art'), 'keins'), weihnachtsgeld_wert = personal._t(p, 'weihnachtsgeld_wert')::numeric, weihnachtsgeld_monat = personal._t(p, 'weihnachtsgeld_monat')::smallint,
               vereinbarung = personal._t(p, 'vereinbarung'), geaendert_am = clock_timestamp(), geaendert_von = p_subjekt
         WHERE id = p_id RETURNING * INTO neu;
    END IF;
    -- im Protokoll stehen nur IDs und die Namen geänderter Felder – nie ein Betrag (F136)
    IF p_id IS NULL THEN
        PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.entgelt_angelegt', jsonb_build_object('person', p_person::text, 'entgelt', neu.id::text));
    ELSE
        PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.entgelt_geaendert', jsonb_build_object('person', p_person::text, 'entgelt', neu.id::text), NULL,
            jsonb_build_object('felder', personal._geaendert(to_jsonb(alt) - ARRAY['geaendert_am', 'geaendert_von', 'angelegt_am'], to_jsonb(neu) - ARRAY['geaendert_am', 'geaendert_von', 'angelegt_am'])));
    END IF;
    RETURN neu.id;
END $$;

CREATE FUNCTION personal.entgelt_loeschen(p_subjekt text, p_person uuid, p_id uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
BEGIN
    DELETE FROM personal.entgelt WHERE id = p_id AND person = p_person;
    IF NOT FOUND THEN PERFORM personal._fehler('Zeile der Vergütung unbekannt', 'KM003'); END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.entgelt_geloescht', jsonb_build_object('person', p_person::text, 'entgelt', p_id::text));
END $$;

REVOKE ALL ON FUNCTION personal.entgelt_am(uuid, date), personal.entgelt_setzen(text, uuid, uuid, jsonb), personal.entgelt_loeschen(text, uuid, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION personal.entgelt_am(uuid, date), personal.entgelt_setzen(text, uuid, uuid, jsonb), personal.entgelt_loeschen(text, uuid, uuid) TO kumero_app;

-- Die Fassung des Vertrags trägt keine Vergütung mehr.
CREATE OR REPLACE FUNCTION personal.vertrag_anlegen(p_subjekt text, p_person uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE neu uuid; v integer[]; w integer; ka text; kr uuid;
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
    -- Kündigungsfrist: nach dem Gesetz, nach einem Tarifvertrag des Mandanten oder individuell (F148)
    ka := coalesce(personal._t(p, 'kuendigung_art'), CASE WHEN personal._t(p, 'kuendigungsfrist') IS NOT NULL THEN 'individuell' ELSE 'gesetzlich' END);
    kr := nullif(p->>'kuendigungsregel', '')::uuid;
    IF ka NOT IN ('gesetzlich', 'tarif', 'individuell') THEN PERFORM personal._fehler('Kündigungsfrist: gesetzlich, nach Tarifvertrag oder individuell'); END IF;
    IF ka = 'tarif' AND NOT EXISTS (SELECT 1 FROM personal.kuendigungsregel r WHERE r.id = kr AND r.art = 'tarif' AND NOT r.stillgelegt) THEN
        PERFORM personal._fehler('Kündigungsfrist nach Tarifvertrag: Der Tarifvertrag ist unbekannt oder stillgelegt');
    END IF;
    IF ka = 'individuell' AND personal._t(p, 'kuendigungsfrist') IS NULL THEN
        PERFORM personal._fehler('Eine individuelle Kündigungsfrist braucht ihren Wortlaut');
    END IF;
    SELECT array_agg(e::text::integer ORDER BY nr) INTO v FROM jsonb_array_elements(p->'verteilung') WITH ORDINALITY AS a(e, nr);
    SELECT sum(x) INTO w FROM unnest(v) x;
    INSERT INTO personal.vertrag (person, gueltig_ab, art, funktion, taetigkeit, arbeitszeit_vereinbarung, beginn, probezeit_bis, befristet_bis, kuendigung_art, kuendigungsregel, kuendigungsfrist, wochenminuten,
                                  verteilung, urlaubstage, lohnbuch, angelegt_von)
    VALUES (p_person, personal._datum(p, 'gueltig_ab'), personal._t(p, 'art'), personal._t(p, 'funktion'), personal._t(p, 'taetigkeit'), personal._t(p, 'arbeitszeit_vereinbarung'), personal._datum(p, 'beginn'),
            personal._datum(p, 'probezeit_bis'), personal._datum(p, 'befristet_bis'), ka, CASE WHEN ka = 'tarif' THEN kr END, CASE WHEN ka = 'individuell' THEN personal._t(p, 'kuendigungsfrist') END, w, v,
            nullif(p->>'urlaubstage', '')::numeric, personal._t(p, 'lohnbuch'), p_subjekt)
    RETURNING id INTO neu;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.vertrag_angelegt', jsonb_build_object('person', p_person::text, 'vertrag', neu::text));
    RETURN neu;
EXCEPTION WHEN check_violation THEN
    PERFORM personal._fehler('Vertrag: Art unbekannt, Befristung ohne Ende, oder ein Datum liegt vor dem Beginn');
    RETURN NULL;
END $$;
