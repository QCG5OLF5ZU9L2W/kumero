-- Modul zeit 0.4.0: Wo gearbeitet wird – im Betrieb, mobil, auf Dienstgang (F151).
--
-- Vorgabe des Verantwortlichen vom 2026-11-21: „Zusätzlich gesondert zu erfassen ist mobiles Arbeiten (Kommen, Gehen) etc.“
-- Jeder Abschnitt trägt, wo gearbeitet wurde. Gestempelt wird mit der Angabe; ein Nachtrag nennt sie, eine Korrektur
-- kann sie ändern. Was bisher gebucht ist, gilt als „im Betrieb“. Gezählt wird überall gleich.

CREATE FUNCTION zeit._ort(p text) RETURNS text LANGUAGE plpgsql IMMUTABLE AS $$
BEGIN
    IF coalesce(nullif(p, ''), 'betrieb') NOT IN ('betrieb', 'mobil', 'dienstgang') THEN
        PERFORM zeit._fehler('Wo gearbeitet wird: im Betrieb, mobil oder auf Dienstgang');
    END IF;
    RETURN coalesce(nullif(p, ''), 'betrieb');
END $$;

ALTER TABLE zeit.abschnitt ADD COLUMN ort text NOT NULL DEFAULT 'betrieb' CHECK (ort IN ('betrieb', 'mobil', 'dienstgang'));
ALTER TABLE zeit.antrag ADD COLUMN ort text CHECK (ort IS NULL OR ort IN ('betrieb', 'mobil', 'dienstgang'));

DROP FUNCTION zeit.stempeln(text, uuid, text);
CREATE FUNCTION zeit.stempeln(p_subjekt text, p_person uuid, p_art text, p_ort text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, zeit, pg_temp AS $$
DECLARE jetzt timestamp := zeit._jetzt(); a zeit.abschnitt; neu uuid;
BEGIN
    SELECT * INTO a FROM zeit.abschnitt WHERE person = p_person AND bis IS NULL FOR UPDATE;
    IF p_art = 'kommen' THEN
        IF FOUND THEN PERFORM zeit._fehler('Du bist schon eingestempelt', 'KM003'); END IF;
        PERFORM zeit._offen_verlangen(p_person, jetzt::date);
        INSERT INTO zeit.abschnitt (person, tag, von, herkunft, ort, angelegt_von) VALUES (p_person, jetzt::date, jetzt::time, 'stempel', zeit._ort(p_ort), p_subjekt) RETURNING id INTO neu;
    ELSIF p_art = 'gehen' THEN
        IF NOT FOUND THEN PERFORM zeit._fehler('Du bist nicht eingestempelt', 'KM003'); END IF;
        IF jetzt - (a.tag + a.von) > interval '24 hours' THEN
            PERFORM zeit._fehler('Der offene Abschnitt ist älter als einen Tag – bitte mit einer Korrektur schließen', 'KM003');
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
    IF v_art IS NULL OR v_art NOT IN ('nachtrag', 'korrektur', 'entfernen') THEN PERFORM zeit._fehler('Nachtrag, Korrektur oder Entfernen'); END IF;
    IF nullif(trim(p->>'begruendung'), '') IS NULL OR length(p->>'begruendung') > 200 THEN PERFORM zeit._fehler('Die Begründung fehlt (höchstens 200 Zeichen)'); END IF;
    IF v_art = 'nachtrag' THEN
        IF coalesce(p->>'tag', '') !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' THEN PERFORM zeit._fehler('Der Tag fehlt'); END IF;
        v_tag := (p->>'tag')::date;
    ELSE
        SELECT * INTO a FROM zeit.abschnitt WHERE id = v_ab AND person = p_person;
        IF NOT FOUND THEN PERFORM zeit._fehler('Abschnitt unbekannt', 'KM003'); END IF;
        v_tag := a.tag;
    END IF;
    IF v_art <> 'entfernen' AND (v_von IS NULL OR v_bis IS NULL OR v_von = v_bis) THEN PERFORM zeit._fehler('Beginn und Ende gehören dazu'); END IF;
    IF v_tag > zeit._jetzt()::date THEN PERFORM zeit._fehler('Der Tag liegt in der Zukunft'); END IF;
    PERFORM zeit._offen_verlangen(p_person, v_tag);
    IF EXISTS (SELECT 1 FROM zeit.antrag WHERE person = p_person AND zustand = 'offen' AND ((v_ab IS NOT NULL AND abschnitt = v_ab) OR (v_ab IS NULL AND art = 'nachtrag' AND tag = v_tag AND von = v_von))) THEN
        PERFORM zeit._fehler('Dazu wartet schon ein Antrag auf seine Entscheidung', 'KM003');
    END IF;
    INSERT INTO zeit.antrag (person, tag, art, abschnitt, von, bis, ort, begruendung, angelegt_von)
    VALUES (p_person, v_tag, v_art, v_ab, CASE WHEN v_art <> 'entfernen' THEN v_von END, CASE WHEN v_art <> 'entfernen' THEN v_bis END,
            CASE WHEN v_art = 'entfernen' OR nullif(p->>'ort', '') IS NULL THEN NULL ELSE zeit._ort(p->>'ort') END, trim(p->>'begruendung'), p_subjekt)
    RETURNING id INTO neu;
    sofort := coalesce(p_sofort, false) OR coalesce((SELECT korrektur FROM zeit.profil WHERE person = p_person), 'freigabe') = 'sofort';
    IF sofort THEN PERFORM zeit._antrag_anwenden(p_subjekt, neu); END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'zeit', CASE WHEN sofort THEN 'zeit.berichtigt' ELSE 'zeit.antrag_gestellt' END,
        jsonb_build_object('person', p_person::text, 'antrag', neu::text, 'tag', v_tag::text));
    RETURN neu;
END $$;

CREATE OR REPLACE FUNCTION zeit.tage(p_person uuid, p_von date, p_bis date)
RETURNS TABLE (tag date, abschnitte jsonb, brutto integer, pause integer, abzug integer, gezaehlt integer, soll integer, gutschrift integer, saldo integer,
               nacht integer, sonntag integer, feiertag_minuten integer, feiertag text, frei text, abwesenheit numeric, arbeitstag boolean,
               hinweise text[], braucht text[], laeuft boolean)
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, zeit, pg_temp AS $$
DECLARE e zeit.einstellung; pr zeit.profil; t record; a record; s integer; en integer; erster integer; letzter integer; netto integer; pflicht integer; stufe integer;
        regel boolean; nv integer; nb integer; fg boolean; heute date := zeit._jetzt()::date; k1 integer; k2 integer; gedeckt integer;
BEGIN
    SELECT * INTO e FROM zeit.einstellung;
    SELECT * INTO pr FROM zeit.profil WHERE person = p_person;
    nv := zeit._min(e.nacht_von); nb := zeit._min(e.nacht_bis);
    FOR t IN SELECT * FROM personal.api_tage(p_person, p_von, p_bis) LOOP
        tag := t.tag; feiertag := t.feiertag; frei := t.frei; abwesenheit := t.abwesenheit;
        brutto := 0; nacht := 0; hinweise := '{}'; braucht := '{}'; laeuft := false; erster := NULL; letzter := NULL; abschnitte := '[]'; gedeckt := 0;
        regel := coalesce(pr.regeltage[extract(isodow FROM t.tag)::integer], t.soll_minuten > 0);
        arbeitstag := regel AND t.feiertag IS NULL AND t.frei IS NULL;
        soll := CASE WHEN arbeitstag THEN t.soll_minuten ELSE 0 END;
        -- bezahlte Abwesenheit schreibt das Soll gut, unbezahlte nimmt es weg
        gutschrift := CASE WHEN t.bezahlt THEN round(soll * t.abwesenheit)::integer ELSE 0 END;
        IF NOT t.bezahlt THEN soll := round(soll * (1 - t.abwesenheit))::integer; END IF;
        FOR a IN SELECT x.id, x.von, x.bis, x.herkunft, x.korrigiert, x.anmerkung, x.ort FROM zeit.abschnitt x WHERE x.person = p_person AND x.tag = t.tag ORDER BY x.von LOOP
            abschnitte := abschnitte || jsonb_build_object('id', a.id::text, 'von', to_char(a.von, 'HH24:MI'), 'bis', to_char(a.bis, 'HH24:MI'), 'herkunft', a.herkunft, 'korrigiert', a.korrigiert, 'anmerkung', a.anmerkung, 'ort', a.ort);
            IF a.bis IS NULL THEN
                laeuft := true;
                IF t.tag < heute THEN hinweise := hinweise || 'Gehen fehlt'::text; END IF;
                CONTINUE;
            END IF;
            s := zeit._min(a.von); en := zeit._min(a.bis) + CASE WHEN a.bis <= a.von THEN 1440 ELSE 0 END;
            -- Gleitzeit mit Rahmen: Was außerhalb liegt, zählt nicht
            IF pr.modell = 'gleitzeit' AND pr.rahmen_von IS NOT NULL THEN
                IF s < zeit._min(pr.rahmen_von) OR en > zeit._min(pr.rahmen_bis) THEN hinweise := hinweise || 'außerhalb des Rahmens'::text; END IF;
                s := greatest(s, zeit._min(pr.rahmen_von)); en := least(en, zeit._min(pr.rahmen_bis));
                IF en <= s THEN CONTINUE; END IF;
            END IF;
            erster := least(coalesce(erster, s), s); letzter := greatest(coalesce(letzter, en), en);   -- nach dem Rahmen: Was außerhalb liegt, ist keine Pause
            brutto := brutto + (en - s);
            nacht := nacht + zeit._gemeinsam(s, en, 0, nb) + zeit._gemeinsam(s, en, nv, 1440 + nb) + zeit._gemeinsam(s, en, 1440 + nv, 2880);
            IF pr.kern_von IS NOT NULL THEN gedeckt := gedeckt + zeit._gemeinsam(s, en, zeit._min(pr.kern_von), zeit._min(pr.kern_bis)); END IF;
        END LOOP;
        pause := CASE WHEN erster IS NULL THEN 0 ELSE greatest(0, (letzter - erster) - brutto) END;
        -- Pause nach der Regel: abgezogen wird nur, was an Pause fehlt – und nie unter die Schwelle der Stufe
        SELECT max((x->>'pause')::integer), max((x->>'ab')::integer) INTO pflicht, stufe FROM jsonb_array_elements(e.pausen) x WHERE brutto > (x->>'ab')::integer;
        abzug := least(greatest(0, coalesce(pflicht, 0) - pause), greatest(0, brutto - coalesce(stufe, 0)));
        netto := brutto - abzug;
        gezaehlt := netto;
        fg := NULL;
        IF netto > e.hoechst_minuten THEN
            SELECT f.genehmigt INTO fg FROM zeit.tagesfreigabe f WHERE f.person = p_person AND f.tag = t.tag AND f.art = 'ueber_hoechst';
            IF NOT FOUND THEN braucht := braucht || 'ueber_hoechst'::text; END IF;
            IF NOT coalesce(fg, false) THEN gezaehlt := e.hoechst_minuten; hinweise := hinweise || 'über der Höchstzeit – ohne Sonderfreigabe gekappt'::text; END IF;
        END IF;
        IF brutto > 0 AND NOT arbeitstag THEN
            fg := NULL;
            SELECT f.genehmigt INTO fg FROM zeit.tagesfreigabe f WHERE f.person = p_person AND f.tag = t.tag AND f.art = 'ausserhalb';
            IF NOT FOUND THEN braucht := braucht || 'ausserhalb'::text; END IF;
            IF NOT coalesce(fg, false) THEN gezaehlt := 0; hinweise := hinweise || 'kein Regelarbeitstag – zählt erst mit Genehmigung'::text; END IF;
        END IF;
        IF pr.kern_von IS NOT NULL AND arbeitstag AND brutto > 0 AND t.abwesenheit = 0 AND gedeckt < zeit._min(pr.kern_bis) - zeit._min(pr.kern_von) - coalesce(pflicht, 0) THEN
            hinweise := hinweise || 'Kernzeit nicht abgedeckt'::text;
        END IF;
        sonntag := CASE WHEN extract(isodow FROM t.tag) = 7 THEN brutto ELSE 0 END;
        feiertag_minuten := CASE WHEN t.feiertag IS NOT NULL THEN brutto ELSE 0 END;
        saldo := gezaehlt + gutschrift - soll;
        RETURN NEXT;
    END LOOP;
END $$;

REVOKE EXECUTE ON FUNCTION zeit.stempeln(text, uuid, text, text), zeit._ort(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION zeit.stempeln(text, uuid, text, text) TO kumero_app;
