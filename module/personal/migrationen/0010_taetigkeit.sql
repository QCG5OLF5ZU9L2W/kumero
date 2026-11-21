-- Modul personal 0.10.0: Aufgaben am Vertrag (F150).
--
-- Vorgabe des Verantwortlichen vom 2026-11-21: „Dem Vertrag fehlen grundlegende Elemente: Gehalt, Stunden, Aufgabe.“
-- Stunden stehen schon da (Minuten je Wochentag). Hier kommt die Beschreibung der Aufgaben dazu, je Fassung.
-- Das Gehalt kommt nicht mit dieser Migration: Vergütung ist bis zur Verschlüsselung zurückgestellt (P38, S1) –
-- das entscheidet der Verantwortliche neu, bevor es gebaut wird.

ALTER TABLE personal.vertrag ADD COLUMN taetigkeit text CHECK (taetigkeit IS NULL OR length(taetigkeit) BETWEEN 1 AND 2000);

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
    INSERT INTO personal.vertrag (person, gueltig_ab, art, funktion, taetigkeit, beginn, probezeit_bis, befristet_bis, kuendigung_art, kuendigungsregel, kuendigungsfrist, wochenminuten,
                                  verteilung, urlaubstage, lohnbuch, angelegt_von)
    VALUES (p_person, personal._datum(p, 'gueltig_ab'), personal._t(p, 'art'), personal._t(p, 'funktion'), personal._t(p, 'taetigkeit'), personal._datum(p, 'beginn'),
            personal._datum(p, 'probezeit_bis'), personal._datum(p, 'befristet_bis'), ka, CASE WHEN ka = 'tarif' THEN kr END, CASE WHEN ka = 'individuell' THEN personal._t(p, 'kuendigungsfrist') END, w, v,
            nullif(p->>'urlaubstage', '')::numeric, personal._t(p, 'lohnbuch'), p_subjekt)
    RETURNING id INTO neu;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.vertrag_angelegt',
        jsonb_build_object('person', p_person::text, 'vertrag', neu::text));
    RETURN neu;
EXCEPTION WHEN check_violation THEN
    PERFORM personal._fehler('Vertrag: Art unbekannt, Befristung ohne Ende, oder ein Datum liegt vor dem Beginn');
    RETURN NULL;
END $$;
