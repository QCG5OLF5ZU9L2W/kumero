-- Modul personal 0.8.0: Kündigungsfrist nach Gesetz, Tarifvertrag des Mandanten oder individuell (F148).
--
-- Vorgabe des Verantwortlichen vom 2026-11-21. Bisher war die Kündigungsfrist ein freier Text am Vertrag.
-- Jetzt sagt jede Fassung, woher die Frist kommt:
--   gesetzlich   – aus der Regel „Gesetz“ des Mandanten (Vorgabe: regelwerk/kuendigung-de.json, § 622 BGB)
--   tarif        – aus einem Tarifvertrag, den der Mandant als Regel anlegt
--   individuell  – der Wortlaut aus dem Vertrag (wie bisher)
-- Eine Regel: Frist in der Probezeit (Wochen), Grundfrist (Wochen, Termin), Staffel für die Kündigung durch den
-- Arbeitgeber nach Jahren im Betrieb (Monate zum Monatsende), und ob für die Person die Grundfrist gilt oder dieselbe
-- Staffel. Welche Frist heute gilt, rechnet die Anwendung daraus (Kumero\Modul\Personal\Kuendigung).
-- Regeln sind keine Personendaten. Bestehende Fassungen bleiben „individuell“ mit ihrem Wortlaut – nichts ändert sich von selbst.

CREATE TABLE personal.kuendigungsregel (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    art           text NOT NULL CHECK (art IN ('gesetz', 'tarif')),
    name          text NOT NULL CHECK (length(name) BETWEEN 1 AND 80),
    regel         jsonb NOT NULL,
    stillgelegt   boolean NOT NULL DEFAULT false,
    geaendert_am  timestamptz NOT NULL DEFAULT now(),
    geaendert_von text NOT NULL
);
CREATE UNIQUE INDEX kuendigungsregel_gesetz ON personal.kuendigungsregel ((true)) WHERE art = 'gesetz';   -- genau eine gesetzliche
CREATE UNIQUE INDEX kuendigungsregel_name ON personal.kuendigungsregel (lower(name));

INSERT INTO personal.kuendigungsregel (art, name, regel, geaendert_von)
VALUES ('gesetz', 'Gesetzlich (§ 622 BGB)', '{"probezeit_wochen": 2, "grund_wochen": 4, "grund_termin": "15_oder_monatsende", "staffel": [{"ab_jahren": 2, "monate": 1}, {"ab_jahren": 5, "monate": 2}, {"ab_jahren": 8, "monate": 3}, {"ab_jahren": 10, "monate": 4}, {"ab_jahren": 12, "monate": 5}, {"ab_jahren": 15, "monate": 6}, {"ab_jahren": 20, "monate": 7}], "arbeitnehmer": "grund"}'::jsonb, 'system:migration');

ALTER TABLE personal.vertrag
    ADD COLUMN kuendigung_art text NOT NULL DEFAULT 'individuell' CHECK (kuendigung_art IN ('gesetzlich', 'tarif', 'individuell')),
    ADD COLUMN kuendigungsregel uuid REFERENCES personal.kuendigungsregel (id),
    ADD CONSTRAINT vertrag_kuendigung_tarif CHECK ((kuendigung_art = 'tarif') = (kuendigungsregel IS NOT NULL));

-- Eine Regel prüfen: wirft KM002 mit dem Grund
CREATE FUNCTION personal._kuendigungsregel_pruefen(r jsonb) RETURNS void
LANGUAGE plpgsql IMMUTABLE SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE e jsonb; letzte integer := 0;
BEGIN
    IF jsonb_typeof(r) IS DISTINCT FROM 'object' OR EXISTS (SELECT 1 FROM jsonb_object_keys(r) k WHERE k NOT IN ('probezeit_wochen', 'grund_wochen', 'grund_termin', 'staffel', 'arbeitnehmer')) THEN
        PERFORM personal._fehler('Kündigungsregel: unbekannte Angabe');
    END IF;
    IF coalesce(r->>'probezeit_wochen', '') !~ '^[0-9]{1,2}$' OR coalesce(r->>'grund_wochen', '') !~ '^[0-9]{1,2}$' OR (r->>'grund_wochen')::integer < 1 THEN
        PERFORM personal._fehler('Kündigungsregel: Fristen in ganzen Wochen (Probezeit 0 bis 99, Grundfrist 1 bis 99)');
    END IF;
    IF coalesce(r->>'grund_termin', '') NOT IN ('15_oder_monatsende', 'monatsende', 'jeder_tag') OR coalesce(r->>'arbeitnehmer', '') NOT IN ('grund', 'wie_arbeitgeber') THEN
        PERFORM personal._fehler('Kündigungsregel: Termin oder Frist der Person unbekannt');
    END IF;
    IF jsonb_typeof(r->'staffel') IS DISTINCT FROM 'array' OR jsonb_array_length(r->'staffel') > 12 THEN
        PERFORM personal._fehler('Kündigungsregel: Staffel als Liste mit höchstens zwölf Stufen');
    END IF;
    FOR e IN SELECT x FROM jsonb_array_elements(r->'staffel') x LOOP
        IF coalesce(e->>'ab_jahren', '') !~ '^[0-9]{1,2}$' OR coalesce(e->>'monate', '') !~ '^[0-9]{1,2}$' OR (e->>'ab_jahren')::integer <= letzte OR (e->>'monate')::integer < 1 THEN
            PERFORM personal._fehler('Kündigungsregel: Staffel aufsteigend nach Jahren im Betrieb, Frist in ganzen Monaten');
        END IF;
        letzte := (e->>'ab_jahren')::integer;
    END LOOP;
END $$;

-- Regel anlegen (p_id NULL: neuer Tarifvertrag) oder ändern. Die gesetzliche lässt sich ändern, nicht stilllegen.
CREATE FUNCTION personal.kuendigungsregel_setzen(p_subjekt text, p_id uuid, p_name text, p_regel jsonb, p_stillgelegt boolean)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE alt personal.kuendigungsregel; neu uuid;
BEGIN
    IF nullif(trim(p_name), '') IS NULL OR length(trim(p_name)) > 80 THEN PERFORM personal._fehler('Kündigungsregel: Name mit höchstens 80 Zeichen'); END IF;
    PERFORM personal._kuendigungsregel_pruefen(p_regel);
    IF EXISTS (SELECT 1 FROM personal.kuendigungsregel WHERE lower(name) = lower(trim(p_name)) AND id IS DISTINCT FROM p_id) THEN
        PERFORM personal._fehler('Eine Kündigungsregel mit diesem Namen gibt es schon', 'KM003');
    END IF;
    IF p_id IS NULL THEN
        INSERT INTO personal.kuendigungsregel (art, name, regel, stillgelegt, geaendert_von)
        VALUES ('tarif', trim(p_name), p_regel, coalesce(p_stillgelegt, false), p_subjekt) RETURNING id INTO neu;
        PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.kuendigungsregel_angelegt', jsonb_build_object('kuendigungsregel', neu::text), NULL, jsonb_build_object('name', trim(p_name), 'regel', p_regel));
        RETURN neu;
    END IF;
    SELECT * INTO alt FROM personal.kuendigungsregel WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Kündigungsregel unbekannt', 'KM003'); END IF;
    IF alt.art = 'gesetz' AND coalesce(p_stillgelegt, false) THEN PERFORM personal._fehler('Die gesetzliche Regel lässt sich nicht stilllegen'); END IF;
    UPDATE personal.kuendigungsregel SET name = trim(p_name), regel = p_regel, stillgelegt = coalesce(p_stillgelegt, false),
           geaendert_am = clock_timestamp(), geaendert_von = p_subjekt WHERE id = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.kuendigungsregel_geaendert', jsonb_build_object('kuendigungsregel', p_id::text),
        jsonb_build_object('name', alt.name, 'regel', alt.regel, 'stillgelegt', alt.stillgelegt), jsonb_build_object('name', trim(p_name), 'regel', p_regel, 'stillgelegt', coalesce(p_stillgelegt, false)));
    RETURN p_id;
END $$;

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
    INSERT INTO personal.vertrag (person, gueltig_ab, art, funktion, beginn, probezeit_bis, befristet_bis, kuendigung_art, kuendigungsregel, kuendigungsfrist, wochenminuten,
                                  verteilung, urlaubstage, lohnbuch, angelegt_von)
    VALUES (p_person, personal._datum(p, 'gueltig_ab'), personal._t(p, 'art'), personal._t(p, 'funktion'), personal._datum(p, 'beginn'),
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

GRANT SELECT ON personal.kuendigungsregel TO kumero_app;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA personal FROM PUBLIC;
GRANT EXECUTE ON FUNCTION personal.kuendigungsregel_setzen(text, uuid, text, jsonb, boolean) TO kumero_app;
