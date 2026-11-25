-- Modul zeit 0.6.0: Wie lange ein offener Abschnitt als laufend gilt, ist eine Regel des Mandanten (ZEIT.md Abschnitt 11, F206, F207).
--
-- Bis 0.5.0 galt eine feste Grenze von 13 Stunden seit dem Kommen (0.4.1, ohne Freigabe gebaut). Entschieden am 2026-11-25:
-- einstellbar am Mandanten (GV8), Vorgabe „AZG 10 Stunden“ (GV2) – und zwar 10 Stunden Arbeitszeit, nicht 10 Stunden seit dem
-- Kommen. „Gehen“ schließt den Abschnitt ohne Rückfrage, solange diese Arbeitszeit nicht überschritten ist; danach wird das
-- Gehen nachgetragen (GV4, Abschnitt 12).
--
-- Arbeitszeit heißt hier dasselbe wie im Monatsblatt (T1): die Abschnitte des Tages, der offene bis jetzt gerechnet, abzüglich
-- dessen, was an Pause nach der Regel fehlt. Wer um 8:00 kommt und keine Pause stempelt, gilt mit der Vorgabe bis 18:45 als
-- eingestempelt (10 Stunden Arbeit und 45 Minuten Pause).

ALTER TABLE zeit.einstellung ADD COLUMN offen_minuten integer NOT NULL DEFAULT 600 CHECK (offen_minuten BETWEEN 60 AND 2880);
COMMENT ON COLUMN zeit.einstellung.offen_minuten IS 'Ein offener Abschnitt gilt als laufend, bis die Arbeitszeit des Tages diese Minuten überschreitet';

DROP FUNCTION zeit._offen_veraltet(date, time, timestamp);
DROP FUNCTION zeit._offen_veraltet_nach();

-- Arbeitszeit des Tages eines offenen Abschnitts, bis p_jetzt gerechnet: geschlossene Abschnitte desselben Tages und der offene,
-- abzüglich der fehlenden Pause (wie zeit.tage, ohne Rahmen der Gleitzeit).
CREATE FUNCTION zeit._offen_arbeit(p_person uuid, p_tag date, p_von time, p_jetzt timestamp) RETURNS integer LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, zeit, pg_temp AS $$
DECLARE e zeit.einstellung; a record; s integer; en integer; brutto integer; erster integer; letzter integer; pause integer; pflicht integer; stufe integer;
BEGIN
    SELECT * INTO e FROM zeit.einstellung;
    erster := zeit._min(p_von);
    letzter := greatest(erster, floor(extract(epoch FROM p_jetzt - p_tag::timestamp) / 60)::integer);
    brutto := letzter - erster;
    FOR a IN SELECT von, bis FROM zeit.abschnitt WHERE person = p_person AND tag = p_tag AND bis IS NOT NULL LOOP
        s := zeit._min(a.von); en := zeit._min(a.bis) + CASE WHEN a.bis <= a.von THEN 1440 ELSE 0 END;
        erster := least(erster, s); letzter := greatest(letzter, en);
        brutto := brutto + (en - s);
    END LOOP;
    pause := greatest(0, (letzter - erster) - brutto);
    SELECT max((x->>'pause')::integer), max((x->>'ab')::integer) INTO pflicht, stufe FROM jsonb_array_elements(e.pausen) x WHERE brutto > (x->>'ab')::integer;
    RETURN brutto - least(greatest(0, coalesce(pflicht, 0) - pause), greatest(0, brutto - coalesce(stufe, 0)));
END $$;

-- Ein offener Abschnitt gilt nicht mehr als laufend, wenn die Arbeitszeit des Tages die Regel des Mandanten überschreitet
CREATE FUNCTION zeit._offen_veraltet(p_person uuid, p_tag date, p_von time, p_jetzt timestamp) RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, zeit, pg_temp AS $$
    SELECT zeit._offen_arbeit(p_person, p_tag, p_von, p_jetzt) > (SELECT offen_minuten FROM zeit.einstellung)
$$;

CREATE OR REPLACE FUNCTION zeit.stempeln(p_subjekt text, p_person uuid, p_art text, p_ort text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, zeit, pg_temp AS $$
DECLARE jetzt timestamp := zeit._jetzt(); a zeit.abschnitt; neu uuid; laeuft boolean; grenze integer;
BEGIN
    -- zwei Stempel derselben Person nacheinander
    PERFORM pg_advisory_xact_lock(hashtextextended('zeit.stempeln:' || p_person::text, 0));
    SELECT * INTO a FROM zeit.abschnitt WHERE person = p_person AND bis IS NULL ORDER BY tag DESC, von DESC LIMIT 1 FOR UPDATE;
    laeuft := FOUND AND NOT zeit._offen_veraltet(p_person, a.tag, a.von, jetzt);
    IF p_art = 'kommen' THEN
        IF laeuft THEN PERFORM zeit._fehler('Du bist schon eingestempelt', 'KM003'); END IF;
        PERFORM zeit._offen_verlangen(p_person, jetzt::date);
        INSERT INTO zeit.abschnitt (person, tag, von, herkunft, ort, angelegt_von) VALUES (p_person, jetzt::date, jetzt::time, 'stempel', zeit._ort(p_ort), p_subjekt) RETURNING id INTO neu;
    ELSIF p_art = 'gehen' THEN
        IF a.id IS NULL THEN PERFORM zeit._fehler('Du bist nicht eingestempelt', 'KM003'); END IF;
        IF NOT laeuft THEN
            SELECT offen_minuten INTO grenze FROM zeit.einstellung;
            PERFORM zeit._fehler(format('Der offene Abschnitt vom %s, %s Uhr liegt über %s:%s Std. Arbeitszeit – „Gehen“ nimmt ihn nicht mehr an; das Gehen dazu nachtragen (nur die Uhrzeit des Gehens). „Kommen“ geht trotzdem',
                to_char(a.tag, 'DD.MM.YYYY'), to_char(a.von, 'HH24:MI'), grenze / 60, lpad((grenze % 60)::text, 2, '0')), 'KM003');
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

-- p: pausen [{ab, pause}], hoechst_minuten, offen_minuten, nacht_von, nacht_bis, monatsabschluss, zeitzone
CREATE OR REPLACE FUNCTION zeit.einstellung_setzen(p_subjekt text, p jsonb)
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
           hoechst_minuten = coalesce((p->>'hoechst_minuten')::integer, hoechst_minuten), offen_minuten = coalesce((p->>'offen_minuten')::integer, offen_minuten),
           nacht_von = coalesce(zeit._uhrzeit(p, 'nacht_von'), nacht_von),
           nacht_bis = coalesce(zeit._uhrzeit(p, 'nacht_bis'), nacht_bis), monatsabschluss = coalesce(p->>'monatsabschluss', monatsabschluss),
           zeitzone = coalesce(p->>'zeitzone', zeitzone), geaendert_am = clock_timestamp(), geaendert_von = p_subjekt;
    PERFORM mandant.modul_protokoll(p_subjekt, 'zeit', 'zeit.einstellung_gesetzt', '{}'::jsonb, NULL,
        (SELECT jsonb_build_object('pausen', pausen, 'hoechst_minuten', hoechst_minuten, 'offen_minuten', offen_minuten, 'nacht_von', nacht_von::text, 'nacht_bis', nacht_bis::text, 'monatsabschluss', monatsabschluss, 'zeitzone', zeitzone) FROM zeit.einstellung));
EXCEPTION WHEN check_violation OR invalid_text_representation THEN
    PERFORM zeit._fehler('Regeln: ein Wert ist unbekannt oder liegt außerhalb');
END $$;

-- Die Anwendung fragt je offenem Abschnitt, ob er noch läuft; die Hilfsfunktionen darunter bleiben ihr verschlossen
REVOKE EXECUTE ON FUNCTION zeit._offen_arbeit(uuid, date, time, timestamp), zeit._offen_veraltet(uuid, date, time, timestamp) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION zeit._offen_veraltet(uuid, date, time, timestamp) TO kumero_app;
