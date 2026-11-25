-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul lager, Migration 0005: Aufgabe der Artikelgruppe (STUECKLISTE.md Abschnitt 2, AG1–AG4; F189)
--
-- Eine Artikelgruppe (intern „Meta-Artikel“) ist eine Funktion, kein Produkt. Bisher stand diese Funktion nur im
-- Namen. Die Aufgabe sagt, wofür die Artikel der Gruppe dienen und was einer können muss, um dazuzugehören – der
-- Maßstab für „ersetzt die anderen“. Pflicht beim Anlegen; Gruppen, die es schon gibt, bleiben gültig, bis die
-- Aufgabe nachgetragen ist (AG2). Die Aufgabe steht im Protokoll mit Vorher und Nachher (F48) – sie beschreibt
-- Sachen, nie Personen (AG4).

ALTER TABLE lager.meta_artikel
    ADD COLUMN aufgabe text CHECK (aufgabe IS NULL OR length(trim(aufgabe)) BETWEEN 1 AND 500);

-- Legt einen Meta-Artikel an (p_id NULL) oder ändert ihn. Die Aufgabe ist beim Anlegen Pflicht. Beim Ändern gilt:
-- fehlt der Schlüssel „aufgabe“, bleibt sie, wie sie ist; eine gesetzte Aufgabe wird nicht wieder geleert.
CREATE OR REPLACE FUNCTION lager.meta_setzen(p_subjekt text, p_id uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE alt lager.meta_artikel; neu lager.meta_artikel; v_aufgabe text := nullif(trim(p->>'aufgabe'), '');
BEGIN
    IF (p->>'soll')::numeric < (p->>'mindest')::numeric THEN
        PERFORM lager._fehler('Der Sollbestand liegt unter dem Mindestbestand');
    END IF;
    IF length(v_aufgabe) > 500 THEN
        PERFORM lager._fehler('Aufgabe: höchstens 500 Zeichen');
    END IF;
    IF EXISTS (SELECT 1 FROM lager.meta_artikel WHERE lower(trim(bezeichnung)) = lower(trim(p->>'bezeichnung')) AND id IS DISTINCT FROM p_id) THEN
        PERFORM lager._fehler('Eine Artikelgruppe mit dieser Bezeichnung gibt es schon', 'KM003');
    END IF;
    IF p_id IS NULL THEN
        IF v_aufgabe IS NULL THEN
            PERFORM lager._fehler('Aufgabe fehlt: wofür die Artikel der Gruppe dienen und was einer können muss, um dazuzugehören');
        END IF;
        INSERT INTO lager.meta_artikel (bezeichnung, aufgabe, einheit, mindest, soll, geaendert_von)
        VALUES (trim(p->>'bezeichnung'), v_aufgabe, trim(p->>'einheit'), (p->>'mindest')::numeric, (p->>'soll')::numeric, p_subjekt)
        RETURNING * INTO neu;
        PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.meta_angelegt', jsonb_build_object('meta_artikel', neu.id::text), NULL, lager._meta_stand(neu));
        RETURN neu.id;
    END IF;
    SELECT * INTO alt FROM lager.meta_artikel WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM lager._fehler('Artikelgruppe unbekannt', 'KM003');
    END IF;
    IF NOT (p ? 'aufgabe') THEN
        v_aufgabe := alt.aufgabe;
    ELSIF v_aufgabe IS NULL AND alt.aufgabe IS NOT NULL THEN
        PERFORM lager._fehler('Die Aufgabe lässt sich ändern, aber nicht wieder leeren');
    END IF;
    IF lower(trim(alt.einheit)) <> lower(trim(p->>'einheit')) AND EXISTS (SELECT 1 FROM lager.artikel WHERE meta_artikel = p_id) THEN
        PERFORM lager._fehler('Die Lagereinheit steht fest, solange die Artikelgruppe Artikel hat', 'KM003');
    END IF;
    IF coalesce((p->>'stillgelegt')::boolean, false) AND EXISTS (SELECT 1 FROM lager.artikel WHERE meta_artikel = p_id) THEN
        PERFORM lager._fehler('Die Artikelgruppe hat noch Artikel – erst die Zuordnung lösen', 'KM003');   -- MA13
    END IF;
    UPDATE lager.meta_artikel SET bezeichnung = trim(p->>'bezeichnung'), aufgabe = v_aufgabe, einheit = trim(p->>'einheit'), mindest = (p->>'mindest')::numeric, soll = (p->>'soll')::numeric,
           stillgelegt = coalesce((p->>'stillgelegt')::boolean, false), geaendert_am = clock_timestamp(), geaendert_von = p_subjekt
     WHERE id = p_id RETURNING * INTO neu;
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.meta_geaendert', jsonb_build_object('meta_artikel', p_id::text), lager._meta_stand(alt), lager._meta_stand(neu));
    RETURN p_id;
END $$;
