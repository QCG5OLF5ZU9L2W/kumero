-- Modul personal 0.11.0: Vergütung am Vertrag (F153).
--
-- Vorgabe des Verantwortlichen vom 2026-11-21 am Dialog „Vertrag: neue Fassung“: „Hier fehlen die grundlegenden Angaben:
-- Gehalt, Arbeitszeit-Sondervereinbarungen, Urlaubsgeld und Weihnachtsgeld.“ Damit kommt die Vergütung vor der
-- Verschlüsselung (P38) – unverschlüsselt wie alles im Personal bis dahin, weiter nur mit erfundenen Personen.
--
--   Vergütung  personal.verguetung  – eigene Datenklasse, je Fassung des Vertrags höchstens ein Satz, eigenes Recht
--   Vertrag    arbeitszeit_vereinbarung – Sondervereinbarungen zur Arbeitszeit als Text der Fassung
--
-- Eine Fassung wird nie geändert, also auch ihre Vergütung nicht: Ein neues Gehalt ist eine neue Fassung.

ALTER TABLE personal.vertrag ADD COLUMN arbeitszeit_vereinbarung text CHECK (arbeitszeit_vereinbarung IS NULL OR length(arbeitszeit_vereinbarung) BETWEEN 1 AND 2000);

CREATE TABLE personal.verguetung (
    vertrag              uuid PRIMARY KEY REFERENCES personal.vertrag ON DELETE CASCADE,   -- geht mit der Fassung (Löschfrist der Fassung)
    art                  text NOT NULL CHECK (art IN ('monat', 'stunde')),                 -- Monatsgehalt oder Stundenlohn, brutto
    betrag               numeric(9,2) NOT NULL CHECK (betrag > 0),
    urlaubsgeld_art      text NOT NULL DEFAULT 'keins' CHECK (urlaubsgeld_art IN ('keins', 'betrag', 'prozent')),
    urlaubsgeld_wert     numeric(9,2),                                                     -- Euro oder Prozent eines Monatsgehalts
    urlaubsgeld_monat    smallint CHECK (urlaubsgeld_monat BETWEEN 1 AND 12),              -- Monat der Auszahlung
    weihnachtsgeld_art   text NOT NULL DEFAULT 'keins' CHECK (weihnachtsgeld_art IN ('keins', 'betrag', 'prozent')),
    weihnachtsgeld_wert  numeric(9,2),
    weihnachtsgeld_monat smallint CHECK (weihnachtsgeld_monat BETWEEN 1 AND 12),
    vereinbarung         text CHECK (vereinbarung IS NULL OR length(vereinbarung) BETWEEN 1 AND 1000),   -- Stichtag, Rückzahlung, Vorbehalt
    CHECK ((urlaubsgeld_art = 'keins') = (urlaubsgeld_wert IS NULL) AND (urlaubsgeld_art <> 'keins' OR urlaubsgeld_monat IS NULL)),
    CHECK ((weihnachtsgeld_art = 'keins') = (weihnachtsgeld_wert IS NULL) AND (weihnachtsgeld_art <> 'keins' OR weihnachtsgeld_monat IS NULL)),
    CHECK (urlaubsgeld_wert IS NULL OR urlaubsgeld_wert > 0),
    CHECK (weihnachtsgeld_wert IS NULL OR weihnachtsgeld_wert > 0),
    CHECK (art = 'monat' OR (urlaubsgeld_art <> 'prozent' AND weihnachtsgeld_art <> 'prozent'))
);
REVOKE ALL ON personal.verguetung FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON personal.verguetung TO kumero_app;   -- kein Zugriff für kumero_lesen (F136)

CREATE OR REPLACE FUNCTION personal.vertrag_anlegen(p_subjekt text, p_person uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE neu uuid; v integer[]; w integer; ka text; kr uuid; g jsonb; vor uuid; g_betrag numeric; was text;
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
    -- Vergütung (F153): eigene Datenklasse. p.verguetung = Objekt → diese Angaben; p.verguetung_uebernehmen → die der Fassung, die bisher
    -- zu „gültig ab“ gilt; sonst trägt die Fassung keine. Geprüft wird hier, damit die Meldung sagt, was fehlt.
    g := CASE WHEN jsonb_typeof(p->'verguetung') = 'object' THEN p->'verguetung' END;
    IF p ? 'verguetung' AND g IS NULL AND jsonb_typeof(p->'verguetung') <> 'null' THEN
        PERFORM personal._fehler('Vergütung: ein Objekt mit Art und Betrag erwartet');
    END IF;
    IF g IS NOT NULL THEN
        IF personal._t(g, 'art') IS NULL OR personal._t(g, 'art') NOT IN ('monat', 'stunde') THEN PERFORM personal._fehler('Vergütung: Monatsgehalt oder Stundenlohn'); END IF;
        IF personal._t(g, 'betrag') IS NULL OR personal._t(g, 'betrag') !~ '^[0-9]{1,7}(\.[0-9]{1,2})?$' OR personal._t(g, 'betrag')::numeric <= 0 THEN
            PERFORM personal._fehler('Vergütung: Betrag in Euro, größer als null, höchstens zwei Nachkommastellen');
        END IF;
        g_betrag := personal._t(g, 'betrag')::numeric;
        FOREACH was IN ARRAY ARRAY['urlaubsgeld', 'weihnachtsgeld'] LOOP
            IF coalesce(personal._t(g, was || '_art'), 'keins') NOT IN ('keins', 'betrag', 'prozent') THEN
                PERFORM personal._fehler(format('%s: keins, fester Betrag oder Prozent eines Monatsgehalts', initcap(was)));
            END IF;
            IF coalesce(personal._t(g, was || '_art'), 'keins') = 'keins' THEN
                IF personal._t(g, was || '_wert') IS NOT NULL OR personal._t(g, was || '_monat') IS NOT NULL THEN
                    PERFORM personal._fehler(format('%s: Wert und Monat gehören nur dazu, wenn es vereinbart ist', initcap(was)));
                END IF;
                CONTINUE;
            END IF;
            IF personal._t(g, was || '_wert') IS NULL OR personal._t(g, was || '_wert') !~ '^[0-9]{1,7}(\.[0-9]{1,2})?$' OR personal._t(g, was || '_wert')::numeric <= 0 THEN
                PERFORM personal._fehler(format('%s: Wert größer als null, höchstens zwei Nachkommastellen', initcap(was)));
            END IF;
            IF personal._t(g, was || '_art') = 'prozent' AND (personal._t(g, 'art') <> 'monat' OR personal._t(g, was || '_wert')::numeric > 300) THEN
                PERFORM personal._fehler(format('%s in Prozent: nur bei einem Monatsgehalt, höchstens 300', initcap(was)));
            END IF;
            IF personal._t(g, was || '_monat') IS NOT NULL AND personal._t(g, was || '_monat') !~ '^([1-9]|1[0-2])$' THEN
                PERFORM personal._fehler(format('%s: Monat der Auszahlung von 1 bis 12', initcap(was)));
            END IF;
        END LOOP;
        IF length(personal._t(g, 'vereinbarung')) > 1000 THEN PERFORM personal._fehler('Vergütung: Vereinbarung höchstens 1000 Zeichen'); END IF;
    ELSIF coalesce((p->>'verguetung_uebernehmen')::boolean, false) THEN
        vor := personal.vertrag_am(p_person, personal._datum(p, 'gueltig_ab'));
    END IF;
    SELECT array_agg(e::text::integer ORDER BY nr) INTO v FROM jsonb_array_elements(p->'verteilung') WITH ORDINALITY AS a(e, nr);
    SELECT sum(x) INTO w FROM unnest(v) x;
    INSERT INTO personal.vertrag (person, gueltig_ab, art, funktion, taetigkeit, arbeitszeit_vereinbarung, beginn, probezeit_bis, befristet_bis, kuendigung_art, kuendigungsregel, kuendigungsfrist, wochenminuten,
                                  verteilung, urlaubstage, lohnbuch, angelegt_von)
    VALUES (p_person, personal._datum(p, 'gueltig_ab'), personal._t(p, 'art'), personal._t(p, 'funktion'), personal._t(p, 'taetigkeit'), personal._t(p, 'arbeitszeit_vereinbarung'), personal._datum(p, 'beginn'),
            personal._datum(p, 'probezeit_bis'), personal._datum(p, 'befristet_bis'), ka, CASE WHEN ka = 'tarif' THEN kr END, CASE WHEN ka = 'individuell' THEN personal._t(p, 'kuendigungsfrist') END, w, v,
            nullif(p->>'urlaubstage', '')::numeric, personal._t(p, 'lohnbuch'), p_subjekt)
    RETURNING id INTO neu;
    IF g IS NOT NULL THEN
        INSERT INTO personal.verguetung (vertrag, art, betrag, urlaubsgeld_art, urlaubsgeld_wert, urlaubsgeld_monat, weihnachtsgeld_art, weihnachtsgeld_wert, weihnachtsgeld_monat, vereinbarung)
        VALUES (neu, personal._t(g, 'art'), g_betrag,
                coalesce(personal._t(g, 'urlaubsgeld_art'), 'keins'), personal._t(g, 'urlaubsgeld_wert')::numeric, personal._t(g, 'urlaubsgeld_monat')::smallint,
                coalesce(personal._t(g, 'weihnachtsgeld_art'), 'keins'), personal._t(g, 'weihnachtsgeld_wert')::numeric, personal._t(g, 'weihnachtsgeld_monat')::smallint,
                personal._t(g, 'vereinbarung'));
    ELSIF vor IS NOT NULL THEN
        INSERT INTO personal.verguetung (vertrag, art, betrag, urlaubsgeld_art, urlaubsgeld_wert, urlaubsgeld_monat, weihnachtsgeld_art, weihnachtsgeld_wert, weihnachtsgeld_monat, vereinbarung)
        SELECT neu, a.art, a.betrag, a.urlaubsgeld_art, a.urlaubsgeld_wert, a.urlaubsgeld_monat, a.weihnachtsgeld_art, a.weihnachtsgeld_wert, a.weihnachtsgeld_monat, a.vereinbarung
          FROM personal.verguetung a WHERE a.vertrag = vor;
    END IF;
    -- im Protokoll steht nur, ob die Fassung eine Vergütung trägt – nie ein Betrag (F136)
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.vertrag_angelegt',
        jsonb_build_object('person', p_person::text, 'vertrag', neu::text, 'verguetung', EXISTS (SELECT 1 FROM personal.verguetung a WHERE a.vertrag = neu)));
    RETURN neu;
EXCEPTION WHEN check_violation THEN
    PERFORM personal._fehler('Vertrag: Art unbekannt, Befristung ohne Ende, oder ein Datum liegt vor dem Beginn');
    RETURN NULL;
END $$;
