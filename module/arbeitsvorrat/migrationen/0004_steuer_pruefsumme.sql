-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul arbeitsvorrat, Migration 0004: Netto und Steuer am Eintrag, Steuerkonto in der Vorauswahl,
-- Zusammenführen über die Belegprüfsumme (ARBEITSVORRAT.md X2, X2a, X3; F96)
--
-- X2: Liegen Netto und Steuerbetrag vor, wird die Buchung als Splitbuchung vorbelegt. Das Steuerkonto
-- kommt wie Konto und Gegenkonto aus der Vorauswahl, einzeln oder im Stapel (X2a).
-- X3: Beiträge zu derselben Datei – gleiche Prüfsumme des Originals, auch unter anderem Link – gehören
-- zum selben Eintrag.
ALTER TABLE arbeitsvorrat.eintrag
    ADD COLUMN netto           numeric(15,2) CHECK (netto > 0),
    ADD COLUMN steuer          numeric(15,2) CHECK (steuer >= 0),
    ADD COLUMN vor_steuerkonto text CHECK (length(vor_steuerkonto) BETWEEN 1 AND 20);

CREATE OR REPLACE FUNCTION arbeitsvorrat._felder_setzen(p_eintrag uuid) RETURNS void
LANGUAGE plpgsql AS $$
DECLARE
    b      arbeitsvorrat.beitrag;
    f      text;
    v_wert   jsonb := '{}';
    v_belege jsonb := '[]';
    v_hinw   jsonb := '[]';
    x      jsonb;
BEGIN
    FOR b IN SELECT * FROM arbeitsvorrat.beitrag WHERE eintrag = p_eintrag AND zurueckgezogen_am IS NULL
              ORDER BY geliefert_am, id LOOP
        FOREACH f IN ARRAY ARRAY['betreff', 'art', 'gegenpartei', 'gegenpartei_kennung', 'datum', 'betrag', 'richtung',
                                 'belegnummer', 'zahlweise', 'zahltag', 'netto', 'steuer'] LOOP
            CONTINUE WHEN coalesce(b.vorschlag->>f, '') = '';
            IF NOT v_wert ? f THEN
                v_wert := v_wert || jsonb_build_object(f, b.vorschlag->>f);
            ELSIF v_wert->>f <> b.vorschlag->>f AND f <> 'betreff' THEN
                v_hinw := v_hinw || jsonb_build_object('quelle', b.quelle, 'feld', f, 'abweichend', b.vorschlag->>f);
            END IF;
        END LOOP;
        FOR x IN SELECT * FROM jsonb_array_elements(coalesce(b.vorschlag->'belege', '[]')) LOOP
            IF NOT EXISTS (SELECT 1 FROM jsonb_array_elements(v_belege) y WHERE y->>'link' = x->>'link') THEN
                v_belege := v_belege || jsonb_build_array(x);
            END IF;
        END LOOP;
        FOR x IN SELECT * FROM jsonb_array_elements(coalesce(b.vorschlag->'hinweise', '[]')) LOOP
            v_hinw := v_hinw || (jsonb_build_object('quelle', b.quelle) || x);
        END LOOP;
    END LOOP;
    UPDATE arbeitsvorrat.eintrag SET
           betreff = coalesce(v_wert->>'betreff', betreff), art = v_wert->>'art',
           gegenpartei = v_wert->>'gegenpartei', gegenpartei_kennung = v_wert->>'gegenpartei_kennung',
           datum = (v_wert->>'datum')::date, betrag = (v_wert->>'betrag')::numeric(15,2), richtung = v_wert->>'richtung',
           belegnummer = v_wert->>'belegnummer', zahlweise = v_wert->>'zahlweise', zahltag = (v_wert->>'zahltag')::date,
           netto = (v_wert->>'netto')::numeric(15,2), steuer = (v_wert->>'steuer')::numeric(15,2),
           belege = v_belege, hinweise = v_hinw
     WHERE id = p_eintrag;
END $$;

CREATE OR REPLACE FUNCTION arbeitsvorrat.einliefern(p_subjekt text, p_quelle text, p_schluessel text, p_vorschlag jsonb, p_angaben jsonb DEFAULT '[]')
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, arbeitsvorrat, pg_temp AS $$
DECLARE
    b        arbeitsvorrat.beitrag;
    e        arbeitsvorrat.eintrag;
    eid      uuid;
    ergebnis text;
BEGIN
    IF coalesce(trim(p_vorschlag->>'betreff'), '') = '' THEN
        PERFORM arbeitsvorrat._fehler('Ein Beitrag braucht einen Betreff', 'KM002');
    END IF;
    PERFORM pg_advisory_xact_lock(hashtext('arbeitsvorrat.einliefern'));
    SELECT * INTO b FROM arbeitsvorrat.beitrag WHERE quelle = p_quelle AND schluessel = p_schluessel FOR UPDATE;
    IF FOUND THEN
        eid := b.eintrag;
        IF b.vorschlag = p_vorschlag AND b.angaben = coalesce(p_angaben, '[]') AND b.zurueckgezogen_am IS NULL THEN
            RETURN jsonb_build_object('eintrag', eid::text, 'ergebnis', 'unveraendert');
        END IF;
        UPDATE arbeitsvorrat.beitrag SET vorschlag = p_vorschlag, angaben = coalesce(p_angaben, '[]'),
               geaendert_am = clock_timestamp(), zurueckgezogen_am = NULL WHERE id = b.id;
        ergebnis := 'ersetzt';
    ELSE
        SELECT x.id INTO eid FROM arbeitsvorrat.eintrag x
         WHERE x.zustand <> 'zurueckgezogen'
           -- derselbe Beleg: gleicher Link oder gleiche Prüfsumme des Originals (X3, Stufe 1 und 2)
           AND EXISTS (SELECT 1 FROM jsonb_array_elements(coalesce(p_vorschlag->'belege', '[]')) n, jsonb_array_elements(x.belege) y
                        WHERE y->>'link' = n->>'link'
                           OR (n->>'sha256' IS NOT NULL AND y->>'sha256' = n->>'sha256')
                           OR (n->>'md5' IS NOT NULL AND y->>'md5' = n->>'md5'))
         ORDER BY x.eingegangen_am LIMIT 1;
        IF eid IS NULL THEN
            INSERT INTO arbeitsvorrat.eintrag (betreff) VALUES (trim(p_vorschlag->>'betreff')) RETURNING id INTO eid;
            ergebnis := 'angelegt';
        ELSE
            ergebnis := 'ersetzt';
        END IF;
        INSERT INTO arbeitsvorrat.beitrag (eintrag, quelle, schluessel, vorschlag, angaben)
             VALUES (eid, p_quelle, p_schluessel, p_vorschlag, coalesce(p_angaben, '[]'));
    END IF;
    SELECT * INTO e FROM arbeitsvorrat.eintrag WHERE id = eid FOR UPDATE;
    IF e.zustand IN ('offen', 'zurueckgezogen') THEN
        UPDATE arbeitsvorrat.eintrag SET zustand = 'offen' WHERE id = eid;
        PERFORM arbeitsvorrat._felder_setzen(eid);
    ELSE
        -- schon in Arbeit, gebucht oder verworfen: Felder bleiben, die Lieferung wird vermerkt
        PERFORM arbeitsvorrat._vermerk(eid, format('Quelle %s hat nach Beginn der Bearbeitung neue Angaben geliefert', p_quelle));
        ergebnis := 'vermerkt';
    END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'arbeitsvorrat', 'arbeitsvorrat.beitrag_geliefert',
        jsonb_build_object('eintrag', eid::text, 'quelle', p_quelle, 'schluessel', p_schluessel), NULL,
        jsonb_build_object('ergebnis', ergebnis));
    RETURN jsonb_build_object('eintrag', eid::text, 'ergebnis', ergebnis);
END $$;

CREATE OR REPLACE FUNCTION arbeitsvorrat.vorauswahl_setzen(p_subjekt text, p_eintraege uuid[], p_daten jsonb)
RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, arbeitsvorrat, pg_temp AS $$
DECLARE n integer;
BEGIN
    UPDATE arbeitsvorrat.eintrag SET
           vor_konto       = CASE WHEN p_daten ? 'konto'       THEN nullif(p_daten ->> 'konto', '')       ELSE vor_konto END,
           vor_gegenkonto  = CASE WHEN p_daten ? 'gegenkonto'  THEN nullif(p_daten ->> 'gegenkonto', '')  ELSE vor_gegenkonto END,
           vor_steuerkonto = CASE WHEN p_daten ? 'steuerkonto' THEN nullif(p_daten ->> 'steuerkonto', '') ELSE vor_steuerkonto END,
           vor_buchungsart = CASE WHEN p_daten ? 'buchungsart' THEN nullif(p_daten ->> 'buchungsart', '') ELSE vor_buchungsart END
     WHERE id = ANY(p_eintraege) AND zustand = 'offen';
    GET DIAGNOSTICS n = ROW_COUNT;
    IF EXISTS (SELECT 1 FROM arbeitsvorrat.eintrag WHERE id = ANY(p_eintraege)
                  AND (vor_konto = vor_gegenkonto OR vor_steuerkonto = vor_gegenkonto OR vor_steuerkonto = vor_konto)) THEN
        PERFORM arbeitsvorrat._fehler('Konto, Gegenkonto und Steuerkonto müssen verschieden sein (F4)', 'KM002');
    END IF;
    IF n > 0 THEN
        PERFORM kern.modul_protokoll(p_subjekt, 'arbeitsvorrat', 'arbeitsvorrat.vorauswahl_gesetzt',
            jsonb_build_object('eintraege', n), NULL, p_daten);
    END IF;
    RETURN n;
END $$;
