-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Kern, Migration 0007: Beiträge von Modulen zur Jahressperre (F75 Nr. 11)
--
-- Module, deren Daten das Ergebnis eines Geschäftsjahres bestimmen (z. B. die
-- Zuordnung der Konten zu Anlage EÜR und E-Bilanz im Modul kontenrahmen),
-- melden den Erweiterungspunkt 'gj_sperren_beitrag' an. Vor der Sperre
-- liefern sie in derselben Transaktion (F46) den Hash ihres Datenstands für
-- dieses Jahr. Der Kern nimmt alle Beiträge in den freigegebenen und
-- gesiegelten Sperrinhalt auf und verweigert die Sperre, solange ein
-- Pflichtbeitrag fehlt. Nach der Sperre sind die Beiträge unveränderlich;
-- das Modul selbst sperrt seine Daten des Jahres anhand des Jahresstatus.
-- Der Kern kennt dabei keine Moduldaten, nur deren Hash (F44).

-- Neuer Erweiterungspunkt
ALTER TABLE kern.modul DROP CONSTRAINT modul_erweiterungspunkte_check;
ALTER TABLE kern.modul ADD CONSTRAINT modul_erweiterungspunkte_check
    CHECK (erweiterungspunkte <@ ARRAY['vorbereiten_anreichern', 'vorbereiten_pruefen',
                                       'festschreiben_pruefen', 'storno_pruefen',
                                       'gj_sperren_beitrag']::text[]);

CREATE TABLE kern.gj_beitrag (
    geschaeftsjahr  uuid NOT NULL REFERENCES kern.geschaeftsjahr,
    modul           text NOT NULL REFERENCES kern.modul,
    modulversion    text NOT NULL,
    inhalt_hash     text NOT NULL CHECK (inhalt_hash ~ '^[0-9a-f]{64}$'),
    gesetzt_am      timestamptz NOT NULL DEFAULT now(),
    gesetzt_von     text NOT NULL,
    PRIMARY KEY (geschaeftsjahr, modul)
);
COMMENT ON TABLE kern.gj_beitrag IS 'Hash des Moduldatenstands je Geschäftsjahr, Teil des Sperrinhalts (F75 Nr. 11).';
GRANT SELECT ON kern.gj_beitrag TO kumero_app, kumero_lesen;

CREATE TRIGGER kein_truncate BEFORE TRUNCATE ON kern.gj_beitrag
    FOR EACH STATEMENT EXECUTE FUNCTION kern.tg_kein_truncate();

-- Unveränderlich, solange das Geschäftsjahr gesperrt ist
CREATE FUNCTION kern.tg_gj_beitrag() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    gj_id uuid := CASE TG_OP WHEN 'DELETE' THEN OLD.geschaeftsjahr ELSE NEW.geschaeftsjahr END;
    st    kern.gj_status;
BEGIN
    SELECT status INTO st FROM kern.geschaeftsjahr WHERE id = gj_id;
    IF st = 'gesperrt' THEN
        PERFORM kern.verweigern('Beiträge eines gesperrten Geschäftsjahres sind unveränderlich (F33, F75)');
    END IF;
    RETURN CASE TG_OP WHEN 'DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER schutz BEFORE INSERT OR UPDATE OR DELETE ON kern.gj_beitrag
    FOR EACH ROW EXECUTE FUNCTION kern.tg_gj_beitrag();

-- Beitrag setzen (synchroner Erweiterungspunkt, F46)
CREATE FUNCTION kern.gj_beitrag_setzen(p_subjekt text, p_kennung text, p_modul text, p_version text, p_inhalt_hash text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE gj kern.geschaeftsjahr; m kern.modul; alt kern.gj_beitrag;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT * INTO gj FROM kern.geschaeftsjahr WHERE kennung = p_kennung FOR SHARE;      -- Sperrreihenfolge F71
    IF NOT FOUND THEN PERFORM kern.verweigern(format('Geschäftsjahr %s unbekannt', p_kennung), 'KM002'); END IF;
    IF gj.status <> 'offen' THEN
        PERFORM kern.verweigern(format('Geschäftsjahr %s ist gesperrt (F33)', p_kennung), 'KM003');
    END IF;
    SELECT * INTO m FROM kern.modul WHERE name = p_modul;
    IF NOT FOUND OR NOT m.aktiv OR m.version <> p_version OR NOT 'gj_sperren_beitrag' = ANY (m.erweiterungspunkte) THEN
        PERFORM kern.verweigern(format('Modul %s %s ist nicht mit dem Erweiterungspunkt gj_sperren_beitrag aktiv (F72)', p_modul, p_version), 'KM003');
    END IF;
    SELECT * INTO alt FROM kern.gj_beitrag WHERE geschaeftsjahr = gj.id AND modul = p_modul FOR UPDATE;
    INSERT INTO kern.gj_beitrag (geschaeftsjahr, modul, modulversion, inhalt_hash, gesetzt_von)
         VALUES (gj.id, p_modul, p_version, lower(p_inhalt_hash), p_subjekt)
    ON CONFLICT (geschaeftsjahr, modul) DO UPDATE
        SET modulversion = EXCLUDED.modulversion, inhalt_hash = EXCLUDED.inhalt_hash,
            gesetzt_am = now(), gesetzt_von = EXCLUDED.gesetzt_von;
    PERFORM kern._protokoll('geschaeftsjahr.beitrag_gesetzt', p_subjekt,
        jsonb_build_object('geschaeftsjahr', gj.id::text, 'modul', p_modul),
        CASE WHEN alt IS NULL THEN NULL ELSE jsonb_build_object('version', alt.modulversion, 'inhalt_hash', alt.inhalt_hash) END,
        jsonb_build_object('version', p_version, 'inhalt_hash', lower(p_inhalt_hash)));
END $$;

-- Sperrinhalt um die Beiträge erweitern. Bereits gegebene Freigaben bleiben
-- prüfbar, weil Protokoll und Freigabe den damaligen Inhalt bzw. Hash speichern.
CREATE OR REPLACE FUNCTION kern.gj_sperr_inhalt(p_kennung text) RETURNS jsonb
LANGUAGE sql STABLE AS $$
    SELECT jsonb_build_object(
        'geschaeftsjahr', g.kennung,
        'beginn',  to_char(lower(g.zeitraum), 'YYYY-MM-DD'),
        'ende',    to_char(upper(g.zeitraum) - 1, 'YYYY-MM-DD'),
        'buchungen', (SELECT count(*) FROM kern.buchung b WHERE b.geschaeftsjahr = g.id),
        'letzte_journal_nr', (SELECT coalesce(max(b.journal_nr), 0) FROM kern.buchung b WHERE b.geschaeftsjahr = g.id),
        'kettenkopf', (SELECT letzter_hash FROM kern.kettenkopf WHERE kette = 1),
        'beitraege', coalesce((SELECT jsonb_object_agg(x.modul, jsonb_build_object(
                                        'version', x.modulversion, 'inhalt_hash', x.inhalt_hash))
                                 FROM kern.gj_beitrag x WHERE x.geschaeftsjahr = g.id), '{}'))
      FROM kern.geschaeftsjahr g WHERE g.kennung = p_kennung
$$;

-- Sperre verlangt die Beiträge aller aktiven Module mit diesem Erweiterungspunkt
CREATE FUNCTION kern._gj_beitraege_vollstaendig(p_gj uuid) RETURNS void
LANGUAGE plpgsql AS $$
DECLARE r record;
BEGIN
    FOR r IN SELECT m.name, m.version, b.modulversion
               FROM kern.modul m
               LEFT JOIN kern.gj_beitrag b ON b.geschaeftsjahr = p_gj AND b.modul = m.name
              WHERE m.aktiv AND 'gj_sperren_beitrag' = ANY (m.erweiterungspunkte) LOOP
        IF r.modulversion IS NULL THEN
            PERFORM kern.verweigern(format('Beitrag von Modul %s zur Jahressperre fehlt (F75)', r.name), 'KM003');
        ELSIF r.modulversion <> r.version THEN
            PERFORM kern.verweigern(format('Beitrag von Modul %s stammt aus einer anderen Version – neu setzen (F72)', r.name), 'KM003');
        END IF;
    END LOOP;
END $$;

CREATE OR REPLACE FUNCTION kern.geschaeftsjahr_sperren(p_subjekt text, p_kennung text, p_freigabe jsonb DEFAULT NULL)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE gj kern.geschaeftsjahr; inhalt jsonb; fid uuid;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT * INTO gj FROM kern.geschaeftsjahr WHERE kennung = p_kennung FOR UPDATE;    -- exklusiv (F71)
    IF NOT FOUND THEN PERFORM kern.verweigern(format('Geschäftsjahr %s unbekannt', p_kennung), 'KM002'); END IF;
    IF gj.status = 'gesperrt' THEN
        PERFORM kern.verweigern(format('Geschäftsjahr %s ist bereits gesperrt', p_kennung), 'KM003');
    END IF;
    IF EXISTS (SELECT 1 FROM kern.buchung WHERE status = 'freigabebereit' AND gj.zeitraum @> buchungsdatum) THEN
        PERFORM kern.verweigern('Es gibt freigabebereite Buchungen in diesem Geschäftsjahr – erst festschreiben oder zurücksetzen', 'KM003');
    END IF;
    PERFORM kern._gj_beitraege_vollstaendig(gj.id);
    inhalt := kern.gj_sperr_inhalt(p_kennung);
    fid := kern._freigabe_einloesen(p_subjekt, 'gj_sperren', kern.inhalt_hash(inhalt), p_freigabe);
    UPDATE kern.geschaeftsjahr SET status = 'gesperrt' WHERE id = gj.id;
    PERFORM kern._protokoll('geschaeftsjahr.gesperrt', p_subjekt, jsonb_build_object('geschaeftsjahr', gj.id::text),
                            jsonb_build_object('status', 'offen'), inhalt || jsonb_build_object('status', 'gesperrt'), fid);
END $$;

REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA kern FROM PUBLIC;
GRANT EXECUTE ON FUNCTION kern.gj_beitrag_setzen(text, text, text, text, text),
                          kern.geschaeftsjahr_sperren(text, text, jsonb)
    TO kumero_app;
GRANT EXECUTE ON FUNCTION kern.gj_sperr_inhalt(text) TO kumero_app, kumero_lesen;
