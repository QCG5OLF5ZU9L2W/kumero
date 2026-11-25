-- Modul zeit 0.4.1: Wer das Gehen vergessen hat, kann trotzdem wieder kommen (ZEIT.md Abschnitt 2 Nr. 6, ZE6: „gestempelt
-- werden kann immer“).
--
-- Fehler bis 0.4.0: Ein offener Abschnitt sperrte „Kommen“ ohne Grenze („Du bist schon eingestempelt“), und „Gehen“ nahm ihn
-- nach 24 Stunden nicht mehr an – am nächsten Arbeitstag ging dann beides nicht, bis eine Korrektur übernommen war. Wer vor
-- Ablauf der 24 Stunden „Gehen“ drückte, schloss den vergessenen Abschnitt mit der Uhrzeit von heute.
--
-- Jetzt: Ein offener Abschnitt gilt nach 13 Stunden als veraltet (24 Stunden weniger elf Stunden Ruhezeit – länger reicht kein
-- Arbeitstag). „Gehen“ nimmt ihn dann nicht mehr an, „Kommen“ beginnt einen neuen. Der veraltete bleibt offen stehen, zählt
-- nicht (T12), trägt den Hinweis „Gehen fehlt“ und wird wie bisher über eine Korrektur geschlossen (DK9). Deshalb darf eine
-- Person mehrere offene Abschnitte haben; laufend ist höchstens der jüngste.

DROP INDEX zeit.abschnitt_offen;
CREATE INDEX abschnitt_offen ON zeit.abschnitt (person) WHERE bis IS NULL;

-- Ab wann ein offener Abschnitt nicht mehr als laufend gilt
CREATE FUNCTION zeit._offen_veraltet_nach() RETURNS interval LANGUAGE sql IMMUTABLE AS $$
    SELECT interval '13 hours'
$$;

CREATE FUNCTION zeit._offen_veraltet(p_tag date, p_von time, p_jetzt timestamp) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
    SELECT p_jetzt - (p_tag + p_von) > zeit._offen_veraltet_nach()
$$;

CREATE OR REPLACE FUNCTION zeit.stempeln(p_subjekt text, p_person uuid, p_art text, p_ort text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, zeit, pg_temp AS $$
DECLARE jetzt timestamp := zeit._jetzt(); a zeit.abschnitt; neu uuid; laeuft boolean;
BEGIN
    -- zwei Stempel derselben Person nacheinander (bisher hielt der eindeutige Index ein doppeltes Kommen auf)
    PERFORM pg_advisory_xact_lock(hashtextextended('zeit.stempeln:' || p_person::text, 0));
    SELECT * INTO a FROM zeit.abschnitt WHERE person = p_person AND bis IS NULL ORDER BY tag DESC, von DESC LIMIT 1 FOR UPDATE;
    laeuft := FOUND AND NOT zeit._offen_veraltet(a.tag, a.von, jetzt);
    IF p_art = 'kommen' THEN
        IF laeuft THEN PERFORM zeit._fehler('Du bist schon eingestempelt', 'KM003'); END IF;
        PERFORM zeit._offen_verlangen(p_person, jetzt::date);
        INSERT INTO zeit.abschnitt (person, tag, von, herkunft, ort, angelegt_von) VALUES (p_person, jetzt::date, jetzt::time, 'stempel', zeit._ort(p_ort), p_subjekt) RETURNING id INTO neu;
    ELSIF p_art = 'gehen' THEN
        IF a.id IS NULL THEN PERFORM zeit._fehler('Du bist nicht eingestempelt', 'KM003'); END IF;
        IF NOT laeuft THEN
            PERFORM zeit._fehler(format('Der offene Abschnitt vom %s, %s Uhr ist zu alt für „Gehen“ – bitte mit einer Korrektur schließen; „Kommen“ geht trotzdem',
                to_char(a.tag, 'DD.MM.YYYY'), to_char(a.von, 'HH24:MI')), 'KM003');
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

GRANT EXECUTE ON FUNCTION zeit._offen_veraltet_nach(), zeit._offen_veraltet(date, time, timestamp) TO kumero_app;
