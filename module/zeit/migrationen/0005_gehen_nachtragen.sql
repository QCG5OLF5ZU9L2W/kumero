-- Modul zeit 0.5.0: Nur die fehlende Buchung nachtragen (ZEIT.md Abschnitt 12, F199, F200).
--
-- Vorgabe des Verantwortlichen vom 2026-11-25: „Das Paar vollständig manuell abzugrenzen, wenn schon Buchungen im
-- Arbeitszeitkonto sind, macht keinen Sinn. Es muss nur die fehlende Buchung nachgetragen werden.“ Bisher verlangten Nachtrag
-- und Korrektur immer Kommen und Gehen (DK9) – auch dann, wenn das Kommen gestempelt war und nur das Gehen fehlte.
--
-- Jetzt gibt es eine vierte Art des Antrags: „gehen“. Sie nennt einen offenen Abschnitt und nur die Uhrzeit des Gehens. Das
-- gestempelte Kommen bleibt, wie es ist, mit seiner Herkunft; der Abschnitt wird geschlossen und trägt wie nach einer
-- Korrektur das Kennzeichen „korrigiert“ (T8). Freigabe wie bei jedem Antrag: sofort oder durch Vorgesetzten oder Personal.

ALTER TABLE zeit.antrag DROP CONSTRAINT antrag_art_check;
ALTER TABLE zeit.antrag ADD CONSTRAINT antrag_art_check CHECK (art IN ('nachtrag', 'korrektur', 'entfernen', 'gehen'));

-- Das nachgetragene Gehen eines offenen Abschnitts: nach dem Kommen (eine Uhrzeit bis zum Kommen heißt: am nächsten Tag, T12),
-- nicht in der Zukunft, nicht über das nächste Kommen der Person hinaus, ohne Überschneidung am Tag.
CREATE FUNCTION zeit._gehen_verlangen(p_abschnitt uuid, p_person uuid, p_bis time) RETURNS void LANGUAGE plpgsql AS $$
DECLARE a zeit.abschnitt; ende timestamp; n record;
BEGIN
    SELECT * INTO a FROM zeit.abschnitt WHERE id = p_abschnitt AND person = p_person FOR UPDATE;
    IF NOT FOUND THEN PERFORM zeit._fehler('Den Abschnitt gibt es nicht mehr', 'KM003'); END IF;
    IF a.bis IS NOT NULL THEN PERFORM zeit._fehler('Der Abschnitt hat schon ein Gehen – zum Ändern „Korrigieren“ wählen', 'KM003'); END IF;
    IF p_bis IS NULL THEN PERFORM zeit._fehler('Die Uhrzeit des Gehens fehlt'); END IF;
    IF p_bis = a.von THEN PERFORM zeit._fehler('Das Gehen liegt nach dem Kommen – nicht in derselben Minute'); END IF;
    ende := a.tag + p_bis + CASE WHEN p_bis <= a.von THEN interval '1 day' ELSE interval '0' END;
    IF ende > zeit._jetzt() THEN PERFORM zeit._fehler('Das Gehen liegt in der Zukunft'); END IF;
    SELECT b.tag, b.von INTO n FROM zeit.abschnitt b
     WHERE b.person = a.person AND b.id <> a.id AND (b.tag + b.von) > (a.tag + a.von) AND (b.tag + b.von) < ende ORDER BY b.tag, b.von LIMIT 1;
    IF FOUND THEN
        PERFORM zeit._fehler(format('Das Gehen läge nach dem nächsten Kommen am %s um %s Uhr', to_char(n.tag, 'DD.MM.YYYY'), to_char(n.von, 'HH24:MI')), 'KM003');
    END IF;
    PERFORM zeit._frei_verlangen(a.person, a.tag, a.von, p_bis, a.id);
END $$;

CREATE OR REPLACE FUNCTION zeit._antrag_anwenden(p_subjekt text, p_id uuid) RETURNS void LANGUAGE plpgsql AS $$
DECLARE x zeit.antrag;
BEGIN
    SELECT * INTO x FROM zeit.antrag WHERE id = p_id FOR UPDATE;
    PERFORM zeit._offen_verlangen(x.person, x.tag);
    IF x.art = 'nachtrag' THEN
        PERFORM zeit._frei_verlangen(x.person, x.tag, x.von, x.bis, NULL);
        INSERT INTO zeit.abschnitt (person, tag, von, bis, herkunft, ort, angelegt_von) VALUES (x.person, x.tag, x.von, x.bis, 'nachtrag', coalesce(x.ort, 'betrieb'), x.angelegt_von);
    ELSIF x.art = 'korrektur' THEN
        PERFORM zeit._frei_verlangen(x.person, x.tag, x.von, x.bis, x.abschnitt);
        UPDATE zeit.abschnitt SET von = x.von, bis = x.bis, ort = coalesce(x.ort, ort), korrigiert = true WHERE id = x.abschnitt AND person = x.person;
        IF NOT FOUND THEN PERFORM zeit._fehler('Den Abschnitt gibt es nicht mehr', 'KM003'); END IF;
    ELSIF x.art = 'gehen' THEN
        -- nur das Ende: Kommen, Herkunft, Ort und Anmerkung des Abschnitts bleiben
        PERFORM zeit._gehen_verlangen(x.abschnitt, x.person, x.bis);
        UPDATE zeit.abschnitt SET bis = x.bis, korrigiert = true WHERE id = x.abschnitt AND person = x.person;
    ELSE
        DELETE FROM zeit.abschnitt WHERE id = x.abschnitt AND person = x.person;
        IF NOT FOUND THEN PERFORM zeit._fehler('Den Abschnitt gibt es nicht mehr', 'KM003'); END IF;
    END IF;
    -- die Begründung bleibt, die Werte nicht
    UPDATE zeit.antrag SET zustand = 'angenommen', von = NULL, bis = NULL, entschieden_am = clock_timestamp(), entschieden_von = p_subjekt WHERE id = p_id;
END $$;

CREATE OR REPLACE FUNCTION zeit.antrag_stellen(p_subjekt text, p_person uuid, p jsonb, p_sofort boolean)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, zeit, pg_temp AS $$
DECLARE neu uuid; v_art text := p->>'art'; v_tag date; v_von time := zeit._uhrzeit(p, 'von'); v_bis time := zeit._uhrzeit(p, 'bis');
        v_ab uuid := nullif(p->>'abschnitt', '')::uuid; a zeit.abschnitt; sofort boolean;
BEGIN
    IF v_art IS NULL OR v_art NOT IN ('nachtrag', 'korrektur', 'entfernen', 'gehen') THEN PERFORM zeit._fehler('Nachtrag, Korrektur, Entfernen oder Gehen nachtragen'); END IF;
    IF nullif(trim(p->>'begruendung'), '') IS NULL OR length(p->>'begruendung') > 200 THEN PERFORM zeit._fehler('Die Begründung fehlt (höchstens 200 Zeichen)'); END IF;
    IF v_art = 'nachtrag' THEN
        IF coalesce(p->>'tag', '') !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN PERFORM zeit._fehler('Der Tag fehlt'); END IF;
        v_tag := (p->>'tag')::date;
    ELSE
        SELECT * INTO a FROM zeit.abschnitt WHERE id = v_ab AND person = p_person;
        IF NOT FOUND THEN PERFORM zeit._fehler('Abschnitt unbekannt', 'KM003'); END IF;
        v_tag := a.tag;
    END IF;
    IF v_art IN ('nachtrag', 'korrektur') AND (v_von IS NULL OR v_bis IS NULL OR v_von = v_bis) THEN PERFORM zeit._fehler('Beginn und Ende gehören dazu'); END IF;
    IF v_art = 'gehen' AND v_von IS NOT NULL THEN PERFORM zeit._fehler('Das Kommen ist gestempelt und bleibt – nachgetragen wird nur das Gehen'); END IF;
    IF v_tag > zeit._jetzt()::date THEN PERFORM zeit._fehler('Der Tag liegt in der Zukunft'); END IF;
    PERFORM zeit._offen_verlangen(p_person, v_tag);
    IF v_art = 'gehen' THEN PERFORM zeit._gehen_verlangen(v_ab, p_person, v_bis); END IF;
    IF EXISTS (SELECT 1 FROM zeit.antrag WHERE person = p_person AND zustand = 'offen' AND ((v_ab IS NOT NULL AND abschnitt = v_ab) OR (v_ab IS NULL AND art = 'nachtrag' AND tag = v_tag AND von = v_von))) THEN
        PERFORM zeit._fehler('Dazu wartet schon ein Antrag auf seine Entscheidung', 'KM003');
    END IF;
    INSERT INTO zeit.antrag (person, tag, art, abschnitt, von, bis, ort, begruendung, angelegt_von)
    VALUES (p_person, v_tag, v_art, v_ab, CASE WHEN v_art IN ('nachtrag', 'korrektur') THEN v_von END, CASE WHEN v_art <> 'entfernen' THEN v_bis END,
            CASE WHEN v_art IN ('entfernen', 'gehen') OR nullif(p->>'ort', '') IS NULL THEN NULL ELSE zeit._ort(p->>'ort') END, trim(p->>'begruendung'), p_subjekt)
    RETURNING id INTO neu;
    sofort := coalesce(p_sofort, false) OR coalesce((SELECT korrektur FROM zeit.profil WHERE person = p_person), 'freigabe') = 'sofort';
    IF sofort THEN PERFORM zeit._antrag_anwenden(p_subjekt, neu); END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'zeit', CASE WHEN sofort THEN 'zeit.berichtigt' ELSE 'zeit.antrag_gestellt' END,
        jsonb_build_object('person', p_person::text, 'antrag', neu::text, 'tag', v_tag::text));
    RETURN neu;
END $$;

-- „Gehen“ an einem veralteten Abschnitt: Die Meldung nennt den neuen Weg (vorher: „mit einer Korrektur schließen“).
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
            PERFORM zeit._fehler(format('Der offene Abschnitt vom %s, %s Uhr ist zu alt für „Gehen“ – das Gehen dazu nachtragen (nur die Uhrzeit des Gehens); „Kommen“ geht trotzdem',
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
