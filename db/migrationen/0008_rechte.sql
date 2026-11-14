-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Kern, Migration 0008: Rechte und Rollen pflegen (F41–F43, F63)
--
-- Die Tabellen stammen aus 0004. Hier kommen hinzu:
--   * Merkmale je Recht, an denen die Invarianten aus F43 hängen,
--   * die Art einer Rolle (normal, verantwortlich, pruefer, administrator),
--   * Schreibfunktionen mit Protokoll (F48, F64),
--   * die Rollenvorlagen aus F42,
--   * kern.rechte_des_subjekts() für die Entscheidung in der Anwendung (F72).
--
-- Invarianten (F43), von der Datenbank beim Commit erzwungen:
--   * Notfall-Rechte nur in Rollen der Art 'verantwortlich'.
--   * Rollen der Art 'pruefer' enthalten nur nicht schreibende Rechte.
--   * Rollen der Art 'administrator' enthalten keine Rechte auf Buchungsdaten.

ALTER TABLE kern.recht
    ADD COLUMN buchungsdaten boolean NOT NULL DEFAULT true,   -- berührt Buchungsdaten (lesend oder schreibend)
    ADD COLUMN schreibend    boolean NOT NULL DEFAULT true,
    ADD COLUMN notfall       boolean NOT NULL DEFAULT false,
    ADD CONSTRAINT recht_notfall_schreibend CHECK (NOT notfall OR schreibend);

ALTER TABLE kern.rolle
    ADD COLUMN art text NOT NULL DEFAULT 'normal'
        CHECK (art IN ('normal', 'verantwortlich', 'pruefer', 'administrator'));

ALTER TABLE kern.zuweisung
    ADD CONSTRAINT zuweisung_subjekt_form CHECK (subjekt ~ '^(nutzer|dienst|system|test):[A-Za-z0-9._-]+$');

-- ---------------------------------------------------------------------------
-- Invarianten (F43) als verzögerte Prüfung
-- ---------------------------------------------------------------------------
CREATE FUNCTION kern._rolle_pruefen(p_rolle uuid) RETURNS void
LANGUAGE plpgsql AS $$
DECLARE r kern.rolle; v record;
BEGIN
    SELECT * INTO r FROM kern.rolle WHERE id = p_rolle;
    IF NOT FOUND THEN RETURN; END IF;
    FOR v IN SELECT DISTINCT re.* FROM kern.rolle_baustein rb
               JOIN kern.baustein_recht br ON br.baustein = rb.baustein
               JOIN kern.recht re ON re.code = br.recht
              WHERE rb.rolle = p_rolle LOOP
        IF v.notfall AND r.art <> 'verantwortlich' THEN
            PERFORM kern.verweigern(format('Rolle %s: Notfall-Recht %s nur für Verantwortliche (F43)', r.name, v.code), 'KM002');
        END IF;
        IF r.art = 'pruefer' AND v.schreibend THEN
            PERFORM kern.verweigern(format('Rolle %s: Prüfer sind nur lesend, %s ist schreibend (F43)', r.name, v.code), 'KM002');
        END IF;
        IF r.art = 'administrator' AND v.buchungsdaten THEN
            PERFORM kern.verweigern(format('Rolle %s: Administratoren sehen keine Buchungsdaten, %s (F43)', r.name, v.code), 'KM002');
        END IF;
    END LOOP;
END $$;

-- Verzögerte Trigger laufen beim Commit mit den Rechten der Sitzung (kumero_app),
-- daher SECURITY DEFINER.
CREATE FUNCTION kern.tg_rolle_invarianten() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE x uuid;
BEGIN
    IF TG_TABLE_NAME = 'rolle' THEN
        PERFORM kern._rolle_pruefen(NEW.id);
    ELSIF TG_TABLE_NAME = 'rolle_baustein' THEN
        PERFORM kern._rolle_pruefen(NEW.rolle);
    ELSIF TG_TABLE_NAME = 'baustein_recht' THEN
        FOR x IN SELECT rolle FROM kern.rolle_baustein WHERE baustein = NEW.baustein LOOP
            PERFORM kern._rolle_pruefen(x);
        END LOOP;
    ELSIF TG_TABLE_NAME = 'recht' THEN
        FOR x IN SELECT DISTINCT rb.rolle FROM kern.rolle_baustein rb
                   JOIN kern.baustein_recht br ON br.baustein = rb.baustein
                  WHERE br.recht = NEW.code LOOP
            PERFORM kern._rolle_pruefen(x);
        END LOOP;
    END IF;
    RETURN NULL;
END $$;

CREATE CONSTRAINT TRIGGER invarianten AFTER INSERT OR UPDATE ON kern.rolle
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION kern.tg_rolle_invarianten();
CREATE CONSTRAINT TRIGGER invarianten AFTER INSERT OR UPDATE ON kern.rolle_baustein
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION kern.tg_rolle_invarianten();
CREATE CONSTRAINT TRIGGER invarianten AFTER INSERT OR UPDATE ON kern.baustein_recht
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION kern.tg_rolle_invarianten();
CREATE CONSTRAINT TRIGGER invarianten AFTER UPDATE ON kern.recht
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION kern.tg_rolle_invarianten();

-- Stand einer Rolle bzw. eines Bausteins für das Protokoll (Vorher/Nachher, F48)
CREATE FUNCTION kern._rolle_stand(p_rolle uuid) RETURNS jsonb
LANGUAGE sql STABLE AS $$
    SELECT jsonb_build_object('name', r.name, 'art', r.art, 'beschreibung', r.beschreibung,
           'bausteine', coalesce((SELECT jsonb_agg(jsonb_build_object('baustein', b.name, 'einschraenkung', rb.einschraenkung) ORDER BY b.name)
                                    FROM kern.rolle_baustein rb JOIN kern.baustein b ON b.id = rb.baustein
                                   WHERE rb.rolle = r.id), '[]'::jsonb))
      FROM kern.rolle r WHERE r.id = p_rolle
$$;

CREATE FUNCTION kern._baustein_stand(p_baustein uuid) RETURNS jsonb
LANGUAGE sql STABLE AS $$
    SELECT jsonb_build_object('name', b.name, 'beschreibung', b.beschreibung,
           'rechte', coalesce((SELECT jsonb_agg(br.recht ORDER BY br.recht) FROM kern.baustein_recht br
                                WHERE br.baustein = b.id), '[]'::jsonb))
      FROM kern.baustein b WHERE b.id = p_baustein
$$;

-- ---------------------------------------------------------------------------
-- Schreibfunktionen
-- ---------------------------------------------------------------------------
CREATE FUNCTION kern.recht_anmelden(p_subjekt text, p_code text, p_beschreibung text,
                                    p_buchungsdaten boolean, p_schreibend boolean, p_notfall boolean DEFAULT false)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE alt kern.recht; v_modul text := split_part(p_code, '.', 1);
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    IF NOT EXISTS (SELECT 1 FROM kern.modul WHERE name = v_modul) THEN
        PERFORM kern.verweigern(format('Modul %s ist nicht registriert', v_modul), 'KM002');
    END IF;
    SELECT * INTO alt FROM kern.recht WHERE code = p_code FOR UPDATE;
    INSERT INTO kern.recht (code, modul, beschreibung, buchungsdaten, schreibend, notfall)
         VALUES (p_code, v_modul, p_beschreibung, p_buchungsdaten, p_schreibend, p_notfall)
    ON CONFLICT (code) DO UPDATE SET beschreibung = EXCLUDED.beschreibung, buchungsdaten = EXCLUDED.buchungsdaten,
                                     schreibend = EXCLUDED.schreibend, notfall = EXCLUDED.notfall;
    PERFORM kern._protokoll('recht.angemeldet', p_subjekt, jsonb_build_object('recht', p_code),
        CASE WHEN alt IS NULL THEN NULL ELSE jsonb_build_object('beschreibung', alt.beschreibung,
             'buchungsdaten', alt.buchungsdaten, 'schreibend', alt.schreibend, 'notfall', alt.notfall) END,
        jsonb_build_object('beschreibung', p_beschreibung, 'buchungsdaten', p_buchungsdaten,
                           'schreibend', p_schreibend, 'notfall', p_notfall));
END $$;

CREATE FUNCTION kern.baustein_setzen(p_subjekt text, p_name text, p_beschreibung text, p_rechte text[])
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE bid uuid; vorher jsonb; fehlt text;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT x INTO fehlt FROM unnest(coalesce(p_rechte, '{}')) x WHERE NOT EXISTS (SELECT 1 FROM kern.recht WHERE code = x) LIMIT 1;
    IF fehlt IS NOT NULL THEN PERFORM kern.verweigern(format('Recht %s unbekannt', fehlt), 'KM002'); END IF;
    SELECT id INTO bid FROM kern.baustein WHERE name = p_name FOR UPDATE;
    IF bid IS NULL THEN
        INSERT INTO kern.baustein (name, beschreibung) VALUES (p_name, p_beschreibung) RETURNING id INTO bid;
    ELSE
        vorher := kern._baustein_stand(bid);
        UPDATE kern.baustein SET beschreibung = p_beschreibung WHERE id = bid;
        DELETE FROM kern.baustein_recht WHERE baustein = bid AND recht <> ALL (coalesce(p_rechte, '{}'));
    END IF;
    INSERT INTO kern.baustein_recht (baustein, recht) SELECT bid, x FROM unnest(coalesce(p_rechte, '{}')) x
        ON CONFLICT DO NOTHING;
    PERFORM kern._protokoll('baustein.gesetzt', p_subjekt, jsonb_build_object('baustein', bid::text),
                            vorher, kern._baustein_stand(bid));
    RETURN bid;
END $$;

-- p_bausteine: [{"baustein": "<Name>", "einschraenkung": {...} | null}, ...]
CREATE FUNCTION kern.rolle_setzen(p_subjekt text, p_name text, p_art text, p_beschreibung text, p_bausteine jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE rid uuid; vorher jsonb; e jsonb; bid uuid; neu uuid[] := '{}';
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    IF p_bausteine IS NULL OR jsonb_typeof(p_bausteine) <> 'array' THEN
        PERFORM kern.verweigern('Bausteine als Liste angeben', 'KM002');
    END IF;
    SELECT id INTO rid FROM kern.rolle WHERE name = p_name FOR UPDATE;
    IF rid IS NULL THEN
        INSERT INTO kern.rolle (name, art, beschreibung) VALUES (p_name, p_art, p_beschreibung) RETURNING id INTO rid;
    ELSE
        vorher := kern._rolle_stand(rid);
        UPDATE kern.rolle SET art = p_art, beschreibung = p_beschreibung WHERE id = rid;
    END IF;
    FOR e IN SELECT * FROM jsonb_array_elements(p_bausteine) LOOP
        SELECT id INTO bid FROM kern.baustein WHERE name = e->>'baustein';
        IF bid IS NULL THEN PERFORM kern.verweigern(format('Baustein %s unbekannt', e->>'baustein'), 'KM002'); END IF;
        neu := neu || bid;
        INSERT INTO kern.rolle_baustein (rolle, baustein, einschraenkung)
             VALUES (rid, bid, nullif(e->'einschraenkung', 'null'::jsonb))
        ON CONFLICT (rolle, baustein) DO UPDATE SET einschraenkung = EXCLUDED.einschraenkung;
    END LOOP;
    DELETE FROM kern.rolle_baustein WHERE rolle = rid AND baustein <> ALL (neu);
    PERFORM kern._protokoll('rolle.geaendert', p_subjekt, jsonb_build_object('rolle', rid::text),
                            vorher, kern._rolle_stand(rid));
    RETURN rid;
END $$;

CREATE FUNCTION kern.zuweisen(p_subjekt text, p_wer text, p_rolle text, p_bis timestamptz DEFAULT NULL)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE rid uuid; zid uuid; von timestamptz := clock_timestamp();
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT id INTO rid FROM kern.rolle WHERE name = p_rolle;
    IF rid IS NULL THEN PERFORM kern.verweigern(format('Rolle %s unbekannt', p_rolle), 'KM002'); END IF;
    INSERT INTO kern.zuweisung (subjekt, rolle, gueltig_von, gueltig_bis) VALUES (p_wer, rid, von, p_bis)
        RETURNING id INTO zid;
    PERFORM kern._protokoll('zuweisung.geaendert', p_subjekt, jsonb_build_object('zuweisung', zid::text),
        NULL, jsonb_build_object('subjekt', p_wer, 'rolle', p_rolle, 'gueltig_von', kern.zeitpunkt(von),
                                 'gueltig_bis', kern.zeitpunkt(p_bis)));
    RETURN zid;
END $$;

CREATE FUNCTION kern.zuweisung_beenden(p_subjekt text, p_zuweisung uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE z kern.zuweisung; ende timestamptz;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT * INTO z FROM kern.zuweisung WHERE id = p_zuweisung FOR UPDATE;
    IF NOT FOUND THEN PERFORM kern.verweigern('Zuweisung unbekannt', 'KM002'); END IF;
    ende := greatest(clock_timestamp(), z.gueltig_von + interval '1 microsecond');
    IF z.gueltig_bis IS NOT NULL AND z.gueltig_bis <= ende THEN
        PERFORM kern.verweigern('Zuweisung ist bereits beendet', 'KM003');
    END IF;
    UPDATE kern.zuweisung SET gueltig_bis = ende WHERE id = z.id;
    PERFORM kern._protokoll('zuweisung.geaendert', p_subjekt, jsonb_build_object('zuweisung', z.id::text),
        jsonb_build_object('gueltig_bis', kern.zeitpunkt(z.gueltig_bis)),
        jsonb_build_object('gueltig_bis', kern.zeitpunkt(ende)));
END $$;

-- Effektive Rechte eines Subjekts (F41 Schicht 5: Rollen werden addiert).
-- Ein Recht ohne Einschränkung in irgendeiner Rolle gilt uneingeschränkt.
CREATE FUNCTION kern.rechte_des_subjekts(p_subjekt text, p_zeitpunkt timestamptz DEFAULT clock_timestamp())
RETURNS TABLE (recht text, einschraenkungen jsonb)
LANGUAGE sql STABLE SET search_path = pg_catalog, kern, pg_temp AS $$
    SELECT br.recht,
           CASE WHEN bool_or(rb.einschraenkung IS NULL) THEN NULL
                ELSE jsonb_agg(DISTINCT rb.einschraenkung) END
      FROM kern.zuweisung z
      JOIN kern.rolle_baustein rb ON rb.rolle = z.rolle
      JOIN kern.baustein_recht br ON br.baustein = rb.baustein
     WHERE z.subjekt = p_subjekt
       AND z.gueltig_von <= p_zeitpunkt
       AND (z.gueltig_bis IS NULL OR z.gueltig_bis > p_zeitpunkt)
     GROUP BY br.recht
$$;

-- ---------------------------------------------------------------------------
-- Rechtekatalog des Kerns und Rollenvorlagen (F41, F42)
-- ---------------------------------------------------------------------------
CREATE FUNCTION kern.rollenvorlagen_anlegen(p_subjekt text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE r record;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    IF EXISTS (SELECT 1 FROM kern.rolle WHERE vorlage) THEN
        PERFORM kern.verweigern('Rollenvorlagen sind bereits angelegt', 'KM003');
    END IF;
    -- Rechte: Code, Beschreibung, Buchungsdaten, schreibend, Notfall
    FOR r IN SELECT * FROM (VALUES
        ('kern.buchung.sehen',            'Buchungen und Entwürfe lesen',                     true,  false, false),
        ('kern.entwurf.anlegen',          'Entwürfe anlegen',                                 true,  true,  false),
        ('kern.entwurf.aendern',          'Entwürfe ändern und vorbereiten',                  true,  true,  false),
        ('kern.entwurf.loeschen',         'Entwürfe löschen',                                 true,  true,  false),
        ('kern.storno.vorschlagen',       'Storno als Entwurf vorschlagen',                   true,  true,  false),
        ('kern.beleg.nachreichen',        'Belege nachreichen',                               true,  true,  false),
        ('kern.buchung.festschreiben',    'Buchungen freigeben und festschreiben',            true,  true,  false),
        ('kern.buchung.stornieren',       'Buchungen stornieren',                             true,  true,  false),
        ('kern.stammdaten.sehen',         'Konten, Tags, Buchungsarten, Nummernkreise lesen', true,  false, false),
        ('kern.stammdaten.aendern',       'Konten, Tags, Buchungsarten pflegen',              true,  true,  false),
        ('kern.nummernkreis.festlegen',   'Nummernkreise je Geschäftsjahr festlegen',         true,  true,  false),
        ('kern.geschaeftsjahr.anlegen',   'Geschäftsjahre anlegen',                           true,  true,  false),
        ('kern.geschaeftsjahr.sperren',   'Geschäftsjahr sperren (Jahresabschluss)',          true,  true,  false),
        ('kern.geschaeftsjahr.oeffnen',   'Gesperrtes Geschäftsjahr wieder öffnen (Notfall)', true,  true,  true),
        ('kern.notfall.erfassen',         'Notfall-Ereignisse erfassen',                      true,  true,  true),
        ('kern.betrieb.produktiv',        'Produktivbetrieb einschalten',                     false, true,  false),
        ('kern.protokoll.sehen',          'Protokollkette lesen',                             true,  false, false),
        ('kern.siegel.sehen',             'Siegel und Kettenköpfe lesen',                     false, false, false),
        ('kern.export.erstellen',         'Exporte erstellen',                                true,  false, false),
        ('kern.pruefung.export',          'Datenträgerüberlassung für die Betriebsprüfung',   true,  false, false),
        ('kern.credential.verwalten',     'Eigene Freigabe-Passkeys verwalten',               false, true,  false),
        ('kern.rechte.sehen',             'Rollen, Zuweisungen und Rechtebericht lesen',      false, false, false),
        ('kern.rechte.verwalten',         'Rollen und Zuweisungen pflegen',                   false, true,  false),
        ('kern.webhook.verwalten',        'Webhook-Abonnements pflegen',                      false, true,  false),
        ('kern.modul.verwalten',          'Module aktivieren und registrieren',               false, true,  false)
    ) AS t(code, beschreibung, bd, sw, nf) LOOP
        PERFORM kern.recht_anmelden(p_subjekt, r.code, r.beschreibung, r.bd, r.sw, r.nf);
    END LOOP;

    PERFORM kern.baustein_setzen(p_subjekt, 'Lesen', 'Buchungen und Stammdaten lesen',
        ARRAY['kern.buchung.sehen', 'kern.stammdaten.sehen']);
    PERFORM kern.baustein_setzen(p_subjekt, 'Buchungen erfassen', 'Entwürfe pflegen, Storno vorschlagen, Belege nachreichen',
        ARRAY['kern.entwurf.anlegen', 'kern.entwurf.aendern', 'kern.entwurf.loeschen', 'kern.storno.vorschlagen', 'kern.beleg.nachreichen']);
    PERFORM kern.baustein_setzen(p_subjekt, 'Buchungen freigeben', 'Festschreiben und stornieren',
        ARRAY['kern.buchung.festschreiben', 'kern.buchung.stornieren', 'kern.credential.verwalten']);
    PERFORM kern.baustein_setzen(p_subjekt, 'Stammdaten pflegen', 'Konten, Tags, Buchungsarten',
        ARRAY['kern.stammdaten.aendern']);
    PERFORM kern.baustein_setzen(p_subjekt, 'Jahresabschluss', 'Geschäftsjahre, Nummernkreise, Sperre, Produktivbetrieb',
        ARRAY['kern.geschaeftsjahr.anlegen', 'kern.nummernkreis.festlegen', 'kern.geschaeftsjahr.sperren', 'kern.betrieb.produktiv']);
    PERFORM kern.baustein_setzen(p_subjekt, 'Notfall', 'Wiederöffnen und Notfall-Ereignisse (nur Verantwortliche, F43)',
        ARRAY['kern.geschaeftsjahr.oeffnen', 'kern.notfall.erfassen']);
    PERFORM kern.baustein_setzen(p_subjekt, 'Nachweis lesen', 'Protokoll und Siegel lesen',
        ARRAY['kern.protokoll.sehen', 'kern.siegel.sehen']);
    PERFORM kern.baustein_setzen(p_subjekt, 'Exporte', 'Exporte erstellen',
        ARRAY['kern.export.erstellen']);
    PERFORM kern.baustein_setzen(p_subjekt, 'Prüferexport', 'Datenträgerüberlassung',
        ARRAY['kern.pruefung.export']);
    PERFORM kern.baustein_setzen(p_subjekt, 'Verwaltung', 'Rechte, Webhooks, Module, Siegelstatus',
        ARRAY['kern.rechte.sehen', 'kern.rechte.verwalten', 'kern.webhook.verwalten', 'kern.modul.verwalten', 'kern.siegel.sehen']);

    PERFORM kern.rolle_setzen(p_subjekt, 'Erfasser', 'normal', 'Erfasst Entwürfe (F31)',
        '[{"baustein":"Lesen"},{"baustein":"Buchungen erfassen"}]');
    PERFORM kern.rolle_setzen(p_subjekt, 'Buchhalter', 'normal', 'Erfasst und gibt frei',
        '[{"baustein":"Lesen"},{"baustein":"Buchungen erfassen"},{"baustein":"Buchungen freigeben"},{"baustein":"Stammdaten pflegen"}]');
    PERFORM kern.rolle_setzen(p_subjekt, 'Verantwortlicher', 'verantwortlich', 'Buchhalter plus Jahresabschluss und Notfall',
        '[{"baustein":"Lesen"},{"baustein":"Buchungen erfassen"},{"baustein":"Buchungen freigeben"},{"baustein":"Stammdaten pflegen"},
          {"baustein":"Jahresabschluss"},{"baustein":"Notfall"},{"baustein":"Nachweis lesen"},{"baustein":"Exporte"},{"baustein":"Prüferexport"}]');
    PERFORM kern.rolle_setzen(p_subjekt, 'Steuerberater', 'normal', 'Buchhalter plus Exporte (optional, F76)',
        '[{"baustein":"Lesen"},{"baustein":"Buchungen erfassen"},{"baustein":"Buchungen freigeben"},{"baustein":"Stammdaten pflegen"},
          {"baustein":"Nachweis lesen"},{"baustein":"Exporte"}]');
    PERFORM kern.rolle_setzen(p_subjekt, 'Leser', 'normal', 'Liest Buchungen und Auswertungen',
        '[{"baustein":"Lesen"}]');
    PERFORM kern.rolle_setzen(p_subjekt, 'Prüfer', 'pruefer', 'Nur lesend, Datenträgerüberlassung (F42, F43)',
        '[{"baustein":"Lesen"},{"baustein":"Nachweis lesen"},{"baustein":"Prüferexport"}]');
    PERFORM kern.rolle_setzen(p_subjekt, 'Administrator', 'administrator', 'Betrieb, keine Buchungsdaten (F43)',
        '[{"baustein":"Verwaltung"}]');
    UPDATE kern.rolle SET vorlage = true
     WHERE name IN ('Erfasser', 'Buchhalter', 'Verantwortlicher', 'Steuerberater', 'Leser', 'Prüfer', 'Administrator');
END $$;

REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA kern FROM PUBLIC;
GRANT EXECUTE ON FUNCTION
    kern.recht_anmelden(text, text, text, boolean, boolean, boolean),
    kern.baustein_setzen(text, text, text, text[]),
    kern.rolle_setzen(text, text, text, text, jsonb),
    kern.zuweisen(text, text, text, timestamptz),
    kern.zuweisung_beenden(text, uuid),
    kern.rollenvorlagen_anlegen(text)
  TO kumero_app;
GRANT EXECUTE ON FUNCTION kern.rechte_des_subjekts(text, timestamptz) TO kumero_app, kumero_lesen;
