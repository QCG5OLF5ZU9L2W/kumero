-- Modul zeit 0.3.0: Anmerkung am Abschnitt; Aufbewahrung der Aufzeichnungen (F150).
--
-- Vorgaben des Verantwortlichen vom 2026-11-21:
--   „Ein Anmerkungsfeld gehört dazu“ – jeder Abschnitt (Kommen bis Gehen) trägt eine freie Anmerkung der Person.
--   „Die Arbeitszeiten unterliegen nach dem MiLoG Verfallsregeln (derzeit zwei Jahre)“ – § 17 Abs. 1 MiLoG: Die
--   Aufzeichnungen sind mindestens zwei Jahre aufzubewahren. Die Frist steht als Regel des Mandanten da (Vorgabe 24 Monate
--   aus regelwerk/arbeitszeit-de.json). kumero löscht keine Zeiten; ein Löschlauf nach Ablauf ist nicht gebaut.

ALTER TABLE zeit.abschnitt ADD COLUMN anmerkung text CHECK (anmerkung IS NULL OR length(anmerkung) BETWEEN 1 AND 200);
ALTER TABLE zeit.einstellung ADD COLUMN aufbewahrung_monate integer NOT NULL DEFAULT 24 CHECK (aufbewahrung_monate BETWEEN 1 AND 600);

-- Anmerkung an einem eigenen Abschnitt setzen oder leeren. Im abgeschlossenen Monat ändert sich nichts mehr.
-- Im Protokoll stehen nur IDs, nie der Text.
CREATE FUNCTION zeit.anmerkung_setzen(p_subjekt text, p_person uuid, p_abschnitt uuid, p_text text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, zeit, pg_temp AS $$
DECLARE a zeit.abschnitt; t text := nullif(trim(p_text), '');
BEGIN
    SELECT * INTO a FROM zeit.abschnitt WHERE id = p_abschnitt AND person = p_person FOR UPDATE;
    IF NOT FOUND THEN PERFORM zeit._fehler('Abschnitt unbekannt', 'KM003'); END IF;
    IF length(coalesce(t, '')) > 200 THEN PERFORM zeit._fehler('Anmerkung: höchstens 200 Zeichen'); END IF;
    PERFORM zeit._offen_verlangen(p_person, a.tag);
    UPDATE zeit.abschnitt SET anmerkung = t WHERE id = p_abschnitt;
    PERFORM mandant.modul_protokoll(p_subjekt, 'zeit', 'zeit.anmerkung_gesetzt', jsonb_build_object('person', p_person::text, 'abschnitt', p_abschnitt::text));
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
        FOR a IN SELECT x.id, x.von, x.bis, x.herkunft, x.korrigiert, x.anmerkung FROM zeit.abschnitt x WHERE x.person = p_person AND x.tag = t.tag ORDER BY x.von LOOP
            abschnitte := abschnitte || jsonb_build_object('id', a.id::text, 'von', to_char(a.von, 'HH24:MI'), 'bis', to_char(a.bis, 'HH24:MI'), 'herkunft', a.herkunft, 'korrigiert', a.korrigiert, 'anmerkung', a.anmerkung);
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

REVOKE EXECUTE ON FUNCTION zeit.anmerkung_setzen(text, uuid, uuid, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION zeit.anmerkung_setzen(text, uuid, uuid, text) TO kumero_app;
