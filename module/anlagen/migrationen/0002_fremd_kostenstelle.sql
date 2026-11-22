-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul anlagen, Migration 0002: Gemietetes und Geleastes, Kostenstelle (LAGER.md IN9 und IN10, F165, F167)
--
-- (1) Art „fremd“: ein Gegenstand, der da ist, dem Betrieb aber nicht gehört. Er hat keine Anschaffungskosten, kein
--     Bestandskonto und keine Absetzung; er steht nie im Anlagenverzeichnis. Fremd oder eigen ist ein Gut vom Anlegen
--     an – gewechselt wird nicht, sonst hingen Vermerke und Buchwerte an etwas, das es so nie gab.
-- (2) Kostenstelle: der Name eines Tags des Kerns am Gut. Die Seite übernimmt ihn in die Aufwandszeile der
--     AfA-Entwürfe. Das Modul liest keine Tabelle des Kerns (F44): Ob es das Tag gibt, zeigt sich beim Anlegen des
--     Entwurfs.

ALTER TABLE anlagen.gut ALTER COLUMN kosten DROP NOT NULL;
ALTER TABLE anlagen.gut ALTER COLUMN bestandskonto DROP NOT NULL;
ALTER TABLE anlagen.gut ADD COLUMN kostenstelle text CHECK (kostenstelle IS NULL OR length(trim(kostenstelle)) BETWEEN 1 AND 100);

-- Die Prüfungen der ersten Migration tragen vergebene Namen; gesucht wird deshalb nach ihrem Inhalt.
DO $$
DECLARE c record;
BEGIN
    FOR c IN SELECT conname, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = 'anlagen.gut'::regclass AND contype = 'c' LOOP
        IF c.def LIKE '%linear%sammelposten%'                                         -- art IN (…)
           OR c.def LIKE '%verschrottung%'                                            -- abgang_art IN (…)
           OR (c.def LIKE '%keine%' AND c.def LIKE '%aufwandskonto%') THEN            -- art = 'keine' OR aufwandskonto …
            EXECUTE format('ALTER TABLE anlagen.gut DROP CONSTRAINT %I', c.conname);
        END IF;
    END LOOP;
END $$;

ALTER TABLE anlagen.gut
    ADD CONSTRAINT gut_art CHECK (art IN ('linear', 'gwg', 'sammelposten', 'keine', 'fremd')),
    ADD CONSTRAINT gut_abgang_art CHECK (abgang_art IS NULL OR abgang_art IN ('verkauf', 'verschrottung', 'entnahme', 'sonstiges', 'rueckgabe')),
    ADD CONSTRAINT gut_aufwandskonto CHECK (art IN ('keine', 'fremd') OR aufwandskonto IS NOT NULL),
    -- eigen: mit Kosten und Bestandskonto; fremd: ohne Kosten, Konten, Buchung und Übernahme
    ADD CONSTRAINT gut_eigen CHECK (art = 'fremd' OR (kosten IS NOT NULL AND bestandskonto IS NOT NULL)),
    ADD CONSTRAINT gut_fremd CHECK (art <> 'fremd' OR (kosten IS NULL AND netto IS NULL AND bestandskonto IS NULL AND aufwandskonto IS NULL
                                                       AND buchung IS NULL AND uebernahme IS NULL AND bisherige_afa = 0)),
    ADD CONSTRAINT gut_rueckgabe CHECK (abgang_art IS DISTINCT FROM 'rueckgabe' OR art = 'fremd');

-- Legt ein Anlagegut an (p_id NULL) oder ändert es. Ein Abgang wird nur über gut_abgang gesetzt.
CREATE OR REPLACE FUNCTION anlagen.gut_setzen(p_subjekt text, p_id uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, anlagen, pg_temp AS $$
DECLARE alt anlagen.gut; neu anlagen.gut;
BEGIN
    IF p_id IS NULL THEN
        INSERT INTO anlagen.gut (inventarnr, bezeichnung, zugang, kosten, netto, art, nutzungsdauer, nd_vorschlag, nd_quelle, nd_begruendung, restwert,
                                 bestandskonto, aufwandskonto, buchung, uebernahme, bisherige_afa, standort, bemerkung, kostenstelle, geaendert_von)
        VALUES (nullif(trim(p->>'inventarnr'), ''), trim(p->>'bezeichnung'), (p->>'zugang')::date, (p->>'kosten')::numeric, (p->>'netto')::numeric, p->>'art',
                (p->>'nutzungsdauer')::integer, (p->>'nd_vorschlag')::integer, nullif(p->>'nd_quelle', ''), nullif(trim(p->>'nd_begruendung'), ''), coalesce(p->>'restwert', 'null'),
                nullif(p->>'bestandskonto', ''), nullif(p->>'aufwandskonto', ''), (p->>'buchung')::uuid, (p->>'uebernahme')::date, coalesce((p->>'bisherige_afa')::numeric, 0),
                nullif(trim(p->>'standort'), ''), nullif(trim(p->>'bemerkung'), ''), nullif(trim(p->>'kostenstelle'), ''), p_subjekt)
        RETURNING * INTO neu;
        PERFORM kern.modul_protokoll(p_subjekt, 'anlagen', 'anlagen.gut_angelegt', jsonb_build_object('gut', neu.id::text), NULL, anlagen._stand(neu));
        RETURN neu.id;
    END IF;
    SELECT * INTO alt FROM anlagen.gut WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN
        RAISE EXCEPTION USING ERRCODE = 'KM003', MESSAGE = 'Anlagegut unbekannt';
    END IF;
    IF (alt.art = 'fremd') <> (p->>'art' = 'fremd') THEN
        RAISE EXCEPTION USING ERRCODE = 'KM002', MESSAGE = 'Ein Gut bleibt eigen oder fremd, wie es angelegt wurde';
    END IF;
    UPDATE anlagen.gut SET inventarnr = nullif(trim(p->>'inventarnr'), ''), bezeichnung = trim(p->>'bezeichnung'), zugang = (p->>'zugang')::date,
           kosten = (p->>'kosten')::numeric, netto = (p->>'netto')::numeric, art = p->>'art', nutzungsdauer = (p->>'nutzungsdauer')::integer,
           nd_vorschlag = (p->>'nd_vorschlag')::integer, nd_quelle = nullif(p->>'nd_quelle', ''), nd_begruendung = nullif(trim(p->>'nd_begruendung'), ''),
           restwert = coalesce(p->>'restwert', 'null'), bestandskonto = nullif(p->>'bestandskonto', ''), aufwandskonto = nullif(p->>'aufwandskonto', ''),
           buchung = (p->>'buchung')::uuid, uebernahme = (p->>'uebernahme')::date, bisherige_afa = coalesce((p->>'bisherige_afa')::numeric, 0),
           standort = nullif(trim(p->>'standort'), ''), bemerkung = nullif(trim(p->>'bemerkung'), ''), kostenstelle = nullif(trim(p->>'kostenstelle'), ''),
           geaendert_am = clock_timestamp(), geaendert_von = p_subjekt
     WHERE id = p_id RETURNING * INTO neu;
    PERFORM kern.modul_protokoll(p_subjekt, 'anlagen', 'anlagen.gut_geaendert', jsonb_build_object('gut', p_id::text), anlagen._stand(alt), anlagen._stand(neu));
    RETURN p_id;
END $$;

-- Ein fremdes Gut trägt keine Absetzung: Vermerke dazu werden abgewiesen.
CREATE OR REPLACE FUNCTION anlagen.afa_vermerken(p_subjekt text, p_gut uuid, p_jahr text, p_buchung uuid, p_zweck text, p_betrag numeric)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, anlagen, pg_temp AS $$
DECLARE v_art text;
BEGIN
    SELECT art INTO v_art FROM anlagen.gut WHERE id = p_gut;
    IF NOT FOUND THEN
        RAISE EXCEPTION USING ERRCODE = 'KM003', MESSAGE = 'Anlagegut unbekannt';
    END IF;
    IF v_art = 'fremd' THEN
        RAISE EXCEPTION USING ERRCODE = 'KM002', MESSAGE = 'Gemietetes und Geleastes wird nicht abgesetzt';
    END IF;
    INSERT INTO anlagen.afa (gut, jahr, buchung, zweck, betrag, vermerkt_von) VALUES (p_gut, p_jahr, p_buchung, p_zweck, p_betrag, p_subjekt)
        ON CONFLICT (gut, jahr, buchung) DO NOTHING;
    PERFORM kern.modul_protokoll(p_subjekt, 'anlagen', 'anlagen.afa_vermerkt',
        jsonb_build_object('gut', p_gut::text, 'jahr', p_jahr, 'buchung', p_buchung::text), NULL, jsonb_build_object('zweck', p_zweck, 'betrag', p_betrag::text));
END $$;
