-- Modul personal 0.15.0: Urlaub mit Vertretung und Mitzeichnung – ein Ablauf vor der Genehmigung (PERSONAL.md 10n, F218–F221).
--
-- Vorgaben des Verantwortlichen vom 2026-11-25: „Beim Urlaub müssen wir einen Workflow hinterlegen können. Personen können
-- sich vertreten lassen, dann müssen die mitzeichnen, dann erst zum Genehmiger.“ – die Vertretung wird im Antrag gewählt
-- (WF2), wer genehmigt, ist eine Frage der Rollen (WF7, AW6 bleibt), der Ablauf ist frei hinterlegbar (WF14) und bleibt im
-- Personal (AB6).
--
-- Ein Ablauf ist eine geordnete Liste von Schritten je Art der Abwesenheit. Ein Schritt sagt: was (mitzeichnen, genehmigen,
-- zur Kenntnis), wer (die gewählte Vertretung, der Vorgesetzte, dessen Vorgesetzter, das Personal, eine benannte Person) und
-- wann (immer, nur mit Vertretung, erst ab einer Zahl von Arbeitstagen). Mit dem Antrag werden seine Schritte festgehalten;
-- ein später geänderter Ablauf ändert laufende Anträge nicht. Der Zustand „beantragt“ bleibt, bis der letzte Schritt getan
-- ist – alle Auswertungen, die auf „genehmigt“ sehen, bleiben damit richtig.

ALTER TABLE personal.abwesenheitsart ADD COLUMN ablauf jsonb;      -- leer: die Vorgabe (personal._ablauf_vorgabe)
ALTER TABLE personal.abwesenheit ADD COLUMN vertretung uuid REFERENCES personal.person;
ALTER TABLE personal.abwesenheit ADD CONSTRAINT abwesenheit_vertretung_andere CHECK (vertretung IS DISTINCT FROM person);
CREATE INDEX abwesenheit_vertretung ON personal.abwesenheit (vertretung) WHERE vertretung IS NOT NULL;

-- Datenklasse Abwesenheit: die Schritte eines Antrags, festgehalten beim Stellen
CREATE TABLE personal.abwesenheit_schritt (
    abwesenheit     uuid NOT NULL REFERENCES personal.abwesenheit ON DELETE CASCADE,
    nr              integer NOT NULL CHECK (nr BETWEEN 1 AND 8),
    was             text NOT NULL CHECK (was IN ('mitzeichnen', 'genehmigen', 'kenntnis')),
    wer             text NOT NULL CHECK (wer IN ('vertretung', 'vorgesetzter', 'vorgesetzter2', 'personal', 'person')),
    person          uuid REFERENCES personal.person,     -- wer gemeint ist, festgestellt beim Antrag; leer beim Personal oder wenn niemand hinterlegt ist
    zustand         text NOT NULL DEFAULT 'offen' CHECK (zustand IN ('offen', 'erledigt', 'abgelehnt', 'entfallen')),
    entschieden_am  timestamptz,
    entschieden_von text,
    PRIMARY KEY (abwesenheit, nr)
);
CREATE INDEX abwesenheit_schritt_person ON personal.abwesenheit_schritt (person) WHERE person IS NOT NULL;
GRANT SELECT ON personal.abwesenheit_schritt TO kumero_app;

-- Die Vorgabe: Mitzeichnung der Vertretung, falls eine gewählt ist, dann die Genehmigung durch den Vorgesetzten (bzw. das Personal, AW6)
CREATE FUNCTION personal._ablauf_vorgabe() RETURNS jsonb LANGUAGE sql IMMUTABLE AS $$
    SELECT '[{"was": "mitzeichnen", "wer": "vertretung", "wann": "vertretung"}, {"was": "genehmigen", "wer": "vorgesetzter", "wann": "immer"}]'::jsonb
$$;

-- Prüft einen Ablauf und gibt ihn in fester Gestalt zurück: je Schritt was, wer, wann und – wo es dazugehört – person und tage
CREATE FUNCTION personal._ablauf_pruefen(p jsonb) RETURNS jsonb LANGUAGE plpgsql STABLE AS $$
DECLARE e jsonb; erg jsonb := '[]'; s jsonb;
BEGIN
    IF jsonb_typeof(p) IS DISTINCT FROM 'array' OR jsonb_array_length(p) NOT BETWEEN 1 AND 8 THEN PERFORM personal._fehler('Ablauf: eine Liste aus einem bis acht Schritten'); END IF;
    FOR e IN SELECT * FROM jsonb_array_elements(p) LOOP
        IF jsonb_typeof(e) IS DISTINCT FROM 'object' OR coalesce(e->>'was', '') NOT IN ('mitzeichnen', 'genehmigen', 'kenntnis')
           OR coalesce(e->>'wer', '') NOT IN ('vertretung', 'vorgesetzter', 'vorgesetzter2', 'personal', 'person')
           OR coalesce(e->>'wann', 'immer') NOT IN ('immer', 'vertretung', 'ab_tage') THEN
            PERFORM personal._fehler('Ablauf: Jeder Schritt sagt, was geschieht, wer es tut und wann');
        END IF;
        s := jsonb_build_object('was', e->>'was', 'wer', e->>'wer', 'wann', coalesce(e->>'wann', 'immer'));
        IF e->>'wer' = 'person' THEN
            IF coalesce(e->>'person', '') !~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' OR NOT EXISTS (SELECT 1 FROM personal.person WHERE id = (e->>'person')::uuid) THEN
                PERFORM personal._fehler('Ablauf: Die benannte Person fehlt oder ist unbekannt');
            END IF;
            s := s || jsonb_build_object('person', e->>'person');
        END IF;
        IF s->>'wann' = 'ab_tage' THEN
            IF coalesce(e->>'tage', '') !~ '^[0-9]{1,3}(\.5)?$' OR (e->>'tage')::numeric NOT BETWEEN 0.5 AND 366 THEN
                PERFORM personal._fehler('Ablauf: „ab … Arbeitstagen“ braucht eine Zahl von 0,5 bis 366');
            END IF;
            s := s || jsonb_build_object('tage', (e->>'tage')::numeric);
        END IF;
        erg := erg || s;
    END LOOP;
    -- jeder Antrag braucht jemanden, der genehmigt: ein Schritt „genehmigen“, der immer gilt und nicht an der Vertretung hängt
    IF NOT EXISTS (SELECT 1 FROM jsonb_array_elements(erg) x WHERE x->>'was' = 'genehmigen' AND x->>'wann' = 'immer' AND x->>'wer' <> 'vertretung') THEN
        PERFORM personal._fehler('Ablauf: Mindestens ein Schritt „genehmigen“ muss immer gelten – und nicht an der Vertretung hängen');
    END IF;
    RETURN erg;
END $$;

-- Hält die Schritte eines Antrags fest: nach dem Ablauf seiner Art, mit dem, was im Augenblick des Antrags gilt
CREATE FUNCTION personal._ablauf_anlegen(p_id uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE a personal.abwesenheit; ab jsonb; e jsonb; n integer := 0; wen uuid; chef uuid;
BEGIN
    SELECT * INTO a FROM personal.abwesenheit WHERE id = p_id;
    SELECT coalesce(ablauf, personal._ablauf_vorgabe()) INTO ab FROM personal.abwesenheitsart WHERE id = a.art;
    SELECT vorgesetzter INTO chef FROM personal.person WHERE id = a.person;
    FOR e IN SELECT * FROM jsonb_array_elements(ab) LOOP
        -- ein Schritt der Vertretung entfällt ohne Vertretung; die übrigen nach ihrem „wann“
        CONTINUE WHEN (e->>'wer' = 'vertretung' OR e->>'wann' = 'vertretung') AND a.vertretung IS NULL;
        CONTINUE WHEN e->>'wann' = 'ab_tage' AND a.tage < (e->>'tage')::numeric;
        wen := CASE e->>'wer' WHEN 'vertretung' THEN a.vertretung WHEN 'vorgesetzter' THEN chef
                              WHEN 'vorgesetzter2' THEN (SELECT vorgesetzter FROM personal.person WHERE id = chef)
                              WHEN 'person' THEN (e->>'person')::uuid END;
        n := n + 1;
        INSERT INTO personal.abwesenheit_schritt (abwesenheit, nr, was, wer, person) VALUES (p_id, n, e->>'was', e->>'wer', wen);
    END LOOP;
END $$;

-- Geht zum nächsten Schritt, der jemanden aufhält; „zur Kenntnis“ hält nicht auf. Ist keiner mehr offen, gilt der Antrag.
CREATE FUNCTION personal._ablauf_weiter(p_subjekt text, p_id uuid) RETURNS boolean LANGUAGE plpgsql AS $$
DECLARE a personal.abwesenheit; art personal.abwesenheitsart; r record; s personal.abwesenheit_schritt;
BEGIN
    LOOP
        SELECT * INTO s FROM personal.abwesenheit_schritt WHERE abwesenheit = p_id AND zustand = 'offen' ORDER BY nr LIMIT 1;
        EXIT WHEN NOT FOUND;
        IF s.was <> 'kenntnis' THEN RETURN false; END IF;
        UPDATE personal.abwesenheit_schritt SET zustand = 'erledigt', entschieden_am = clock_timestamp() WHERE abwesenheit = p_id AND nr = s.nr;
    END LOOP;
    SELECT * INTO a FROM personal.abwesenheit WHERE id = p_id FOR UPDATE;
    SELECT * INTO art FROM personal.abwesenheitsart WHERE id = a.art;
    -- die Tage gelten mit dem Stand der Genehmigung (Vertrag und freie Tage können sich seit dem Antrag geändert haben)
    SELECT * INTO r FROM personal._abwesenheit_rechnen(a.person, art, a.von, a.bis, a.halber_tag, a.id);
    UPDATE personal.abwesenheit SET zustand = 'genehmigt', tage = CASE WHEN a.tage_von_hand THEN tage ELSE r.o_tage END,
           tage_folgejahr = CASE WHEN a.tage_von_hand THEN tage_folgejahr ELSE r.o_folgejahr END, grund = NULL,
           entschieden_am = clock_timestamp(), entschieden_von = p_subjekt WHERE id = p_id;
    RETURN true;
END $$;

-- Was nicht mehr läuft, hat keine offenen Schritte: nach Ablehnung oder Rücknahme entfallen sie
CREATE FUNCTION personal._ablauf_schliessen() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.zustand IN ('abgelehnt', 'zurueckgenommen') THEN
        UPDATE personal.abwesenheit_schritt SET zustand = 'entfallen' WHERE abwesenheit = NEW.id AND zustand = 'offen';
    END IF;
    RETURN NULL;
END $$;
CREATE TRIGGER abwesenheit_ablauf_schliessen AFTER UPDATE OF zustand ON personal.abwesenheit FOR EACH ROW EXECUTE FUNCTION personal._ablauf_schliessen();

-- Anträge, die beim Update schon warten, bekommen den einen Schritt, den sie bisher hatten: die Genehmigung
INSERT INTO personal.abwesenheit_schritt (abwesenheit, nr, was, wer, person)
SELECT a.id, 1, 'genehmigen', 'vorgesetzter', p.vorgesetzter FROM personal.abwesenheit a JOIN personal.person p ON p.id = a.person WHERE a.zustand = 'beantragt';

-- p_sofort: Das Personal trägt ein – die Abwesenheit gilt ohne Antrag und ohne Ablauf (AW5); eine Vertretung kann es nennen.
-- Sonst entscheidet die Art: Braucht sie eine Genehmigung, ist es ein Antrag mit seinen Schritten; braucht sie keine (AU), gilt sie mit der Meldung.
CREATE OR REPLACE FUNCTION personal.abwesenheit_anlegen(p_subjekt text, p_person uuid, p jsonb, p_sofort boolean)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE pers personal.person; art personal.abwesenheitsart; neu uuid; v_von date := personal._datum(p, 'von'); v_bis date := personal._datum(p, 'bis');
        v_halb boolean := coalesce((p->>'halber_tag')::boolean, false); r record; gilt boolean; v_vertr uuid := nullif(p->>'vertretung', '')::uuid; ver personal.person;
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
    IF v_vertr IS NOT NULL THEN
        IF NOT art.genehmigung THEN PERFORM personal._fehler('Eine Vertretung gehört nur zu einer Abwesenheit, die eine Genehmigung braucht'); END IF;
        IF v_vertr = p_person THEN PERFORM personal._fehler('Niemand vertritt sich selbst'); END IF;
        SELECT * INTO ver FROM personal.person WHERE id = v_vertr;
        IF NOT FOUND THEN PERFORM personal._fehler('Vertretung unbekannt', 'KM003'); END IF;
        IF ver.austritt IS NOT NULL AND ver.austritt < v_bis THEN PERFORM personal._fehler('Die Vertretung ist in diesem Zeitraum ausgeschieden'); END IF;
    END IF;
    SELECT * INTO r FROM personal._abwesenheit_rechnen(p_person, art, v_von, v_bis, v_halb, NULL);
    gilt := coalesce(p_sofort, false) OR NOT art.genehmigung;
    INSERT INTO personal.abwesenheit (person, art, von, bis, halber_tag, tage, tage_folgejahr, bemerkung, vertretung, zustand, angelegt_von, entschieden_am, entschieden_von)
    VALUES (p_person, art.id, v_von, v_bis, v_halb, r.o_tage, r.o_folgejahr, personal._t(p, 'bemerkung'), v_vertr,
            CASE WHEN gilt THEN 'genehmigt' ELSE 'beantragt' END, p_subjekt,
            CASE WHEN gilt THEN clock_timestamp() END, CASE WHEN gilt THEN p_subjekt END)
    RETURNING id INTO neu;
    IF art.kennung = 'au' THEN
        IF personal._datum(coalesce(p->'au', '{}'), 'festgestellt_am') > current_date THEN PERFORM personal._fehler('Der Tag der Feststellung liegt in der Zukunft'); END IF;
        INSERT INTO personal.au (abwesenheit, festgestellt_am, folge, arbeitsunfall)
        VALUES (neu, personal._datum(coalesce(p->'au', '{}'), 'festgestellt_am'), coalesce((p->'au'->>'folge')::boolean, false), coalesce((p->'au'->>'arbeitsunfall')::boolean, false));
    END IF;
    IF NOT gilt THEN PERFORM personal._ablauf_anlegen(neu); END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', CASE WHEN gilt THEN 'personal.abwesenheit_eingetragen' ELSE 'personal.abwesenheit_beantragt' END,
        jsonb_build_object('person', p_person::text, 'abwesenheit', neu::text));
    RETURN neu;
END $$;

-- Entscheidet den Schritt, der gerade wartet: mitzeichnen oder genehmigen – oder ablehnen, mit Grund. Wer das darf, prüft
-- die Anwendung (Rollen, AW6). Nach dem letzten Schritt gilt der Antrag; eine Ablehnung beendet ihn an jedem Schritt.
CREATE OR REPLACE FUNCTION personal.abwesenheit_entscheiden(p_subjekt text, p_id uuid, p_genehmigen boolean, p_grund text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE a personal.abwesenheit; s personal.abwesenheit_schritt; fertig boolean;
BEGIN
    SELECT * INTO a FROM personal.abwesenheit WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Abwesenheit unbekannt', 'KM003'); END IF;
    IF a.zustand <> 'beantragt' THEN PERFORM personal._fehler('Über den Antrag ist schon entschieden', 'KM003'); END IF;
    IF NOT coalesce(p_genehmigen, false) AND nullif(trim(p_grund), '') IS NULL THEN PERFORM personal._fehler('Eine Ablehnung braucht einen Grund'); END IF;
    IF length(coalesce(p_grund, '')) > 200 THEN PERFORM personal._fehler('Grund: höchstens 200 Zeichen'); END IF;
    SELECT * INTO s FROM personal.abwesenheit_schritt WHERE abwesenheit = p_id AND zustand = 'offen' ORDER BY nr LIMIT 1;
    IF coalesce(p_genehmigen, false) THEN
        UPDATE personal.abwesenheit_schritt SET zustand = 'erledigt', entschieden_am = clock_timestamp(), entschieden_von = p_subjekt WHERE abwesenheit = p_id AND nr = s.nr;
        fertig := personal._ablauf_weiter(p_subjekt, p_id);
        IF fertig AND nullif(trim(p_grund), '') IS NOT NULL THEN UPDATE personal.abwesenheit SET grund = trim(p_grund) WHERE id = p_id; END IF;
        PERFORM mandant.modul_protokoll(p_subjekt, 'personal', CASE WHEN fertig THEN 'personal.abwesenheit_genehmigt' WHEN s.was = 'mitzeichnen' THEN 'personal.abwesenheit_mitgezeichnet' ELSE 'personal.abwesenheit_schritt_genehmigt' END,
            jsonb_build_object('person', a.person::text, 'abwesenheit', p_id::text));
    ELSE
        UPDATE personal.abwesenheit_schritt SET zustand = 'abgelehnt', entschieden_am = clock_timestamp(), entschieden_von = p_subjekt WHERE abwesenheit = p_id AND nr = s.nr;
        UPDATE personal.abwesenheit SET zustand = 'abgelehnt', grund = trim(p_grund), entschieden_am = clock_timestamp(), entschieden_von = p_subjekt WHERE id = p_id;
        PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.abwesenheit_abgelehnt', jsonb_build_object('person', a.person::text, 'abwesenheit', p_id::text));
    END IF;
END $$;

-- Art der Abwesenheit setzen – neu mit ihrem Ablauf (leer: die Vorgabe). Eine Art ohne Genehmigung hat keinen.
CREATE OR REPLACE FUNCTION personal.abwesenheitsart_setzen(p_subjekt text, p_id uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE neu uuid; alt personal.abwesenheitsart; b boolean; v_ablauf jsonb;
BEGIN
    IF personal._t(p, 'name') IS NULL OR length(personal._t(p, 'name')) > 60 THEN PERFORM personal._fehler('Art der Abwesenheit: Name mit höchstens 60 Zeichen'); END IF;
    IF EXISTS (SELECT 1 FROM personal.abwesenheitsart WHERE lower(name) = lower(personal._t(p, 'name')) AND id IS DISTINCT FROM p_id) THEN
        PERFORM personal._fehler('Eine Art mit diesem Namen gibt es schon', 'KM003');
    END IF;
    IF p ? 'ablauf' AND jsonb_typeof(p->'ablauf') <> 'null' THEN v_ablauf := personal._ablauf_pruefen(p->'ablauf'); END IF;
    IF p_id IS NULL THEN
        INSERT INTO personal.abwesenheitsart (name, bezahlt, zaehlt_urlaub, genehmigung, selbst, ablauf)
        VALUES (personal._t(p, 'name'), coalesce((p->>'bezahlt')::boolean, false), coalesce((p->>'zaehlt_urlaub')::boolean, false),
                coalesce((p->>'genehmigung')::boolean, true), coalesce((p->>'selbst')::boolean, false), v_ablauf) RETURNING id INTO neu;
    ELSE
        SELECT * INTO alt FROM personal.abwesenheitsart WHERE id = p_id FOR UPDATE;
        IF NOT FOUND THEN PERFORM personal._fehler('Art unbekannt', 'KM003'); END IF;
        b := coalesce((p->>'stillgelegt')::boolean, false);
        IF alt.kennung IS NOT NULL AND b THEN PERFORM personal._fehler('Urlaub und Arbeitsunfähigkeit lassen sich nicht stilllegen', 'KM003'); END IF;
        -- die beiden festen Arten behalten ihre Bedeutung: geändert werden Name, „bezahlt“ und „selbst“
        UPDATE personal.abwesenheitsart SET name = personal._t(p, 'name'), bezahlt = coalesce((p->>'bezahlt')::boolean, bezahlt),
               zaehlt_urlaub = CASE WHEN kennung IS NULL THEN coalesce((p->>'zaehlt_urlaub')::boolean, zaehlt_urlaub) ELSE zaehlt_urlaub END,
               genehmigung = CASE WHEN kennung IS NULL OR kennung = 'urlaub' THEN coalesce((p->>'genehmigung')::boolean, genehmigung) ELSE genehmigung END,
               selbst = coalesce((p->>'selbst')::boolean, selbst), stillgelegt = b,
               -- ohne die Angabe bleibt der Ablauf, wie er ist; „null“ setzt ihn auf die Vorgabe zurück
               ablauf = CASE WHEN p ? 'ablauf' THEN v_ablauf ELSE ablauf END
         WHERE id = p_id RETURNING id INTO neu;
    END IF;
    IF EXISTS (SELECT 1 FROM personal.abwesenheitsart WHERE id = neu AND NOT genehmigung AND ablauf IS NOT NULL) THEN
        PERFORM personal._fehler('Eine Art ohne Genehmigung hat keinen Ablauf');
    END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.abwesenheitsart_gesetzt', jsonb_build_object('abwesenheitsart', neu::text), NULL,
        (SELECT to_jsonb(x) - 'id' FROM personal.abwesenheitsart x WHERE x.id = neu));
    RETURN neu;
END $$;

GRANT EXECUTE ON FUNCTION personal._ablauf_vorgabe() TO kumero_app;
