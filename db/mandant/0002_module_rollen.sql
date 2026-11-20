-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Mandanten-Datenbank, Migration 0002: Module, Rechte und Rollen am Mandanten (PERSONAL.md Abschnitt 2, F135)
--
-- Bis 0.7.41 hing jedes Modul an einem Buch; die Datenbank des Mandanten kannte nur Nutzer, Bücher und das Amt des
-- Administrators. Was zum Mandanten gehört und nicht zu einem Buch (Personal, Zeiterfassung, Dienstplan), braucht
-- dasselbe am Mandanten: Module, die hier registriert sind, und Rechte → Bausteine → Rollen → Zuweisungen.
--
-- Die Funktionen tragen dieselben Namen und Signaturen wie im Kern eines Buchs (Kern-Migrationen 0006, 0008, 0009),
-- nur im Schema `mandant`: Der Modulrahmen bedient beide Ebenen gleich. Unterschiede:
--   * Es gibt keine Erweiterungspunkte (die gehören zum Buchen) und keinen Lesemodus (der gehört zum Buch).
--   * Ein Recht sagt statt „Buchungsdaten“, ob es Personendaten berührt; Notfall-Rechte gibt es nicht.
--   * Rollen haben keine Art. Der Administrator ist ein Amt (mandant.administrator), keine Rolle: Er weist Rollen zu
--     und hat selbst keines der Rechte, die Module mitbringen (wie F43 für Buchungsdaten).
-- Geschrieben wird nur über Funktionen mit Protokoll (F48, F64).

CREATE TABLE mandant.modul (
    name            text PRIMARY KEY CHECK (name ~ '^[a-z][a-z0-9-]{0,31}$' AND name NOT IN ('kern', 'mandant')),
    version         text NOT NULL,
    aktiv           boolean NOT NULL DEFAULT true,
    registriert_am  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE mandant.recht (
    code            text PRIMARY KEY CHECK (code ~ '^[a-z][a-z0-9-]*\.[a-z][a-z0-9_-]*\.[a-z][a-z0-9_-]*$'),
    modul           text NOT NULL REFERENCES mandant.modul,
    beschreibung    text NOT NULL,
    personendaten   boolean NOT NULL DEFAULT true,    -- berührt Daten über Personen (lesend oder schreibend)
    schreibend      boolean NOT NULL DEFAULT true,
    CHECK (split_part(code, '.', 1) = modul)
);

CREATE TABLE mandant.baustein (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name            text NOT NULL UNIQUE,
    beschreibung    text
);

CREATE TABLE mandant.baustein_recht (
    baustein    uuid NOT NULL REFERENCES mandant.baustein ON DELETE CASCADE,
    recht       text NOT NULL REFERENCES mandant.recht,
    PRIMARY KEY (baustein, recht)
);

CREATE TABLE mandant.rolle (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name            text NOT NULL UNIQUE,
    beschreibung    text
);

CREATE TABLE mandant.rolle_baustein (
    rolle           uuid NOT NULL REFERENCES mandant.rolle ON DELETE CASCADE,
    baustein        uuid NOT NULL REFERENCES mandant.baustein,
    einschraenkung  jsonb CHECK (einschraenkung IS NULL OR jsonb_typeof(einschraenkung) = 'object'),
    PRIMARY KEY (rolle, baustein)
);

CREATE TABLE mandant.zuweisung (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    subjekt         text NOT NULL CHECK (subjekt ~ '^(nutzer|dienst|system|test):[A-Za-z0-9._-]+$'),
    rolle           uuid NOT NULL REFERENCES mandant.rolle,
    gueltig_von     timestamptz NOT NULL DEFAULT now(),
    gueltig_bis     timestamptz,
    UNIQUE (subjekt, rolle, gueltig_von),
    CHECK (gueltig_bis IS NULL OR gueltig_bis > gueltig_von)
);
CREATE INDEX zuweisung_subjekt ON mandant.zuweisung (subjekt);

-- Stand für das Protokoll (Vorher/Nachher)
CREATE FUNCTION mandant._rolle_stand(p_rolle uuid) RETURNS jsonb
LANGUAGE sql STABLE AS $$
    SELECT jsonb_build_object('name', r.name, 'beschreibung', r.beschreibung,
           'bausteine', coalesce((SELECT jsonb_agg(jsonb_build_object('baustein', b.name, 'einschraenkung', rb.einschraenkung) ORDER BY b.name)
                                    FROM mandant.rolle_baustein rb JOIN mandant.baustein b ON b.id = rb.baustein
                                   WHERE rb.rolle = r.id), '[]'::jsonb))
      FROM mandant.rolle r WHERE r.id = p_rolle
$$;

CREATE FUNCTION mandant._baustein_stand(p_baustein uuid) RETURNS jsonb
LANGUAGE sql STABLE AS $$
    SELECT jsonb_build_object('name', b.name, 'beschreibung', b.beschreibung,
           'rechte', coalesce((SELECT jsonb_agg(br.recht ORDER BY br.recht) FROM mandant.baustein_recht br
                                WHERE br.baustein = b.id), '[]'::jsonb))
      FROM mandant.baustein b WHERE b.id = p_baustein
$$;

-- ---------------------------------------------------------------------------
-- Module
-- ---------------------------------------------------------------------------
CREATE FUNCTION mandant.modul_registrieren(p_subjekt text, p_name text, p_version text, p_erweiterungspunkte text[] DEFAULT '{}')
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE alt mandant.modul;
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    IF coalesce(cardinality(p_erweiterungspunkte), 0) > 0 THEN
        PERFORM mandant.verweigern('Erweiterungspunkte gibt es nur im Buch, nicht am Mandanten', 'KM002');
    END IF;
    SELECT * INTO alt FROM mandant.modul WHERE name = p_name FOR UPDATE;
    INSERT INTO mandant.modul (name, version) VALUES (p_name, p_version)
        ON CONFLICT (name) DO UPDATE SET version = EXCLUDED.version, aktiv = true;
    PERFORM mandant._protokoll('modul.registriert', p_subjekt, jsonb_build_object('modul', p_name),
        CASE WHEN alt IS NULL THEN NULL ELSE jsonb_build_object('version', alt.version, 'aktiv', alt.aktiv) END,
        jsonb_build_object('version', p_version, 'aktiv', true));
END $$;

-- Protokolleintrag eines Mandantenmoduls in der Kette des Mandanten. Zahlen als Text (F29).
CREATE FUNCTION mandant.modul_protokoll(p_subjekt text, p_modul text, p_typ text, p_ids jsonb,
                                        p_vorher jsonb DEFAULT NULL, p_nachher jsonb DEFAULT NULL)
RETURNS bigint LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    IF NOT EXISTS (SELECT 1 FROM mandant.modul WHERE name = p_modul AND aktiv) THEN
        PERFORM mandant.verweigern(format('Modul %s ist am Mandanten nicht aktiv registriert', p_modul), 'KM002');
    END IF;
    IF split_part(p_typ, '.', 1) <> p_modul THEN
        PERFORM mandant.verweigern(format('Ereignistyp %s muss mit %s. beginnen (F48)', p_typ, p_modul), 'KM002');
    END IF;
    IF p_ids IS NULL OR jsonb_typeof(p_ids) <> 'object' THEN
        PERFORM mandant.verweigern('ids als Objekt angeben', 'KM002');
    END IF;
    PERFORM mandant._protokoll(p_typ, p_subjekt, p_ids, p_vorher, p_nachher);
    RETURN (SELECT letzte_nr FROM mandant.kettenkopf);
END $$;

-- ---------------------------------------------------------------------------
-- Rechte, Bausteine, Rollen
-- ---------------------------------------------------------------------------
CREATE FUNCTION mandant.recht_anmelden(p_subjekt text, p_code text, p_beschreibung text,
                                       p_personendaten boolean, p_schreibend boolean, p_notfall boolean DEFAULT false)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE alt mandant.recht; v_modul text := split_part(p_code, '.', 1);
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    IF p_notfall THEN
        PERFORM mandant.verweigern('Notfall-Rechte gibt es nur im Buch', 'KM002');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM mandant.modul WHERE name = v_modul) THEN
        PERFORM mandant.verweigern(format('Modul %s ist am Mandanten nicht registriert', v_modul), 'KM002');
    END IF;
    SELECT * INTO alt FROM mandant.recht WHERE code = p_code FOR UPDATE;
    INSERT INTO mandant.recht (code, modul, beschreibung, personendaten, schreibend)
         VALUES (p_code, v_modul, p_beschreibung, p_personendaten, p_schreibend)
    ON CONFLICT (code) DO UPDATE SET beschreibung = EXCLUDED.beschreibung, personendaten = EXCLUDED.personendaten,
                                     schreibend = EXCLUDED.schreibend;
    PERFORM mandant._protokoll('recht.angemeldet', p_subjekt, jsonb_build_object('recht', p_code),
        CASE WHEN alt IS NULL THEN NULL ELSE jsonb_build_object('beschreibung', alt.beschreibung,
             'personendaten', alt.personendaten, 'schreibend', alt.schreibend) END,
        jsonb_build_object('beschreibung', p_beschreibung, 'personendaten', p_personendaten, 'schreibend', p_schreibend));
END $$;

CREATE FUNCTION mandant.baustein_setzen(p_subjekt text, p_name text, p_beschreibung text, p_rechte text[])
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE bid uuid; vorher jsonb; fehlt text;
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    SELECT x INTO fehlt FROM unnest(coalesce(p_rechte, '{}')) x WHERE NOT EXISTS (SELECT 1 FROM mandant.recht WHERE code = x) LIMIT 1;
    IF fehlt IS NOT NULL THEN PERFORM mandant.verweigern(format('Recht %s unbekannt', fehlt), 'KM002'); END IF;
    SELECT id INTO bid FROM mandant.baustein WHERE name = p_name FOR UPDATE;
    IF bid IS NULL THEN
        INSERT INTO mandant.baustein (name, beschreibung) VALUES (p_name, p_beschreibung) RETURNING id INTO bid;
    ELSE
        vorher := mandant._baustein_stand(bid);
        UPDATE mandant.baustein SET beschreibung = p_beschreibung WHERE id = bid;
        DELETE FROM mandant.baustein_recht WHERE baustein = bid AND recht <> ALL (coalesce(p_rechte, '{}'));
    END IF;
    INSERT INTO mandant.baustein_recht (baustein, recht) SELECT bid, x FROM unnest(coalesce(p_rechte, '{}')) x
        ON CONFLICT DO NOTHING;
    PERFORM mandant._protokoll('baustein.gesetzt', p_subjekt, jsonb_build_object('baustein', bid::text),
                               vorher, mandant._baustein_stand(bid));
    RETURN bid;
END $$;

-- p_art gibt es nur der gleichen Signatur wegen; am Mandanten ist jede Rolle 'normal'.
-- p_bausteine: [{"baustein": "<Name>", "einschraenkung": {...} | null}, ...]
CREATE FUNCTION mandant.rolle_setzen(p_subjekt text, p_name text, p_art text, p_beschreibung text, p_bausteine jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE rid uuid; vorher jsonb; e jsonb; bid uuid; neu uuid[] := '{}';
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    IF coalesce(p_art, 'normal') <> 'normal' THEN
        PERFORM mandant.verweigern('Rollen am Mandanten haben keine Art', 'KM002');
    END IF;
    IF p_name IS NULL OR length(trim(p_name)) NOT BETWEEN 2 AND 40 THEN
        PERFORM mandant.verweigern('Name der Rolle: 2 bis 40 Zeichen', 'KM002');
    END IF;
    IF p_bausteine IS NULL OR jsonb_typeof(p_bausteine) <> 'array' THEN
        PERFORM mandant.verweigern('Bausteine als Liste angeben', 'KM002');
    END IF;
    SELECT id INTO rid FROM mandant.rolle WHERE name = p_name FOR UPDATE;
    IF rid IS NULL THEN
        INSERT INTO mandant.rolle (name, beschreibung) VALUES (p_name, p_beschreibung) RETURNING id INTO rid;
    ELSE
        vorher := mandant._rolle_stand(rid);
        UPDATE mandant.rolle SET beschreibung = p_beschreibung WHERE id = rid;
    END IF;
    FOR e IN SELECT * FROM jsonb_array_elements(p_bausteine) LOOP
        SELECT id INTO bid FROM mandant.baustein WHERE name = e->>'baustein';
        IF bid IS NULL THEN PERFORM mandant.verweigern(format('Baustein %s unbekannt', e->>'baustein'), 'KM002'); END IF;
        neu := neu || bid;
        INSERT INTO mandant.rolle_baustein (rolle, baustein, einschraenkung)
             VALUES (rid, bid, nullif(e->'einschraenkung', 'null'::jsonb))
        ON CONFLICT (rolle, baustein) DO UPDATE SET einschraenkung = EXCLUDED.einschraenkung;
    END LOOP;
    DELETE FROM mandant.rolle_baustein WHERE rolle = rid AND baustein <> ALL (neu);
    PERFORM mandant._protokoll('rolle.geaendert', p_subjekt, jsonb_build_object('rolle', rid::text),
                               vorher, mandant._rolle_stand(rid));
    RETURN rid;
END $$;

-- ---------------------------------------------------------------------------
-- Zuweisungen
-- ---------------------------------------------------------------------------
CREATE FUNCTION mandant.zuweisen(p_subjekt text, p_wer text, p_rolle text, p_bis timestamptz DEFAULT NULL)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE rid uuid; zid uuid; von timestamptz := clock_timestamp();
BEGIN
    PERFORM mandant._subjekt(p_subjekt); PERFORM mandant._subjekt(p_wer);
    SELECT id INTO rid FROM mandant.rolle WHERE name = p_rolle;
    IF rid IS NULL THEN PERFORM mandant.verweigern(format('Rolle %s unbekannt', p_rolle), 'KM002'); END IF;
    -- Wer als Nutzer eine Rolle bekommt, muss ein Nutzer dieses Mandanten sein
    IF p_wer LIKE 'nutzer:%' AND NOT EXISTS (SELECT 1 FROM mandant.nutzer WHERE id::text = substr(p_wer, 8)) THEN
        PERFORM mandant.verweigern('Nutzer unbekannt', 'KM002');
    END IF;
    IF EXISTS (SELECT 1 FROM mandant.zuweisung WHERE subjekt = p_wer AND rolle = rid
                  AND (gueltig_bis IS NULL OR gueltig_bis > von)) THEN
        PERFORM mandant.verweigern('Diese Rolle ist schon zugewiesen', 'KM003');
    END IF;
    INSERT INTO mandant.zuweisung (subjekt, rolle, gueltig_von, gueltig_bis) VALUES (p_wer, rid, von, p_bis)
        RETURNING id INTO zid;
    PERFORM mandant._protokoll('zuweisung.geaendert', p_subjekt, jsonb_build_object('zuweisung', zid::text),
        NULL, jsonb_build_object('subjekt', p_wer, 'rolle', p_rolle, 'gueltig_von', mandant.zeitpunkt(von),
                                 'gueltig_bis', mandant.zeitpunkt(p_bis)));
    RETURN zid;
END $$;

CREATE FUNCTION mandant.zuweisung_beenden(p_subjekt text, p_zuweisung uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE z mandant.zuweisung; ende timestamptz;
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    SELECT * INTO z FROM mandant.zuweisung WHERE id = p_zuweisung FOR UPDATE;
    IF NOT FOUND THEN PERFORM mandant.verweigern('Zuweisung unbekannt', 'KM002'); END IF;
    ende := greatest(clock_timestamp(), z.gueltig_von + interval '1 microsecond');
    IF z.gueltig_bis IS NOT NULL AND z.gueltig_bis <= ende THEN
        PERFORM mandant.verweigern('Zuweisung ist bereits beendet', 'KM003');
    END IF;
    UPDATE mandant.zuweisung SET gueltig_bis = ende WHERE id = z.id;
    PERFORM mandant._protokoll('zuweisung.geaendert', p_subjekt, jsonb_build_object('zuweisung', z.id::text),
        jsonb_build_object('gueltig_bis', mandant.zeitpunkt(z.gueltig_bis)),
        jsonb_build_object('gueltig_bis', mandant.zeitpunkt(ende)));
END $$;

-- Rechte eines Subjekts am Mandanten: Rollen werden addiert; ein Recht ohne Einschränkung in irgendeiner Rolle gilt
-- uneingeschränkt. Ein gesperrter Nutzer hat keine.
CREATE FUNCTION mandant.rechte_des_subjekts(p_subjekt text, p_zeitpunkt timestamptz DEFAULT clock_timestamp())
RETURNS TABLE (recht text, einschraenkungen jsonb)
LANGUAGE sql STABLE SET search_path = pg_catalog, mandant, pg_temp AS $$
    SELECT br.recht,
           CASE WHEN bool_or(rb.einschraenkung IS NULL) THEN NULL
                ELSE jsonb_agg(DISTINCT rb.einschraenkung) END
      FROM mandant.zuweisung z
      JOIN mandant.rolle_baustein rb ON rb.rolle = z.rolle
      JOIN mandant.baustein_recht br ON br.baustein = rb.baustein
      JOIN mandant.recht re ON re.code = br.recht
      JOIN mandant.modul mo ON mo.name = re.modul AND mo.aktiv
     WHERE z.subjekt = p_subjekt
       AND z.gueltig_von <= p_zeitpunkt
       AND (z.gueltig_bis IS NULL OR z.gueltig_bis > p_zeitpunkt)
       AND (p_subjekt NOT LIKE 'nutzer:%'
            OR EXISTS (SELECT 1 FROM mandant.nutzer n WHERE n.id::text = substr(p_subjekt, 8) AND n.aktiv))
     GROUP BY br.recht
$$;

-- ---------------------------------------------------------------------------
-- Zugriff: lesen dürfen Anwendung und Lesekonto; schreiben nur über die Funktionen
-- ---------------------------------------------------------------------------
REVOKE ALL ON mandant.modul, mandant.recht, mandant.baustein, mandant.baustein_recht, mandant.rolle,
              mandant.rolle_baustein, mandant.zuweisung FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON mandant.modul, mandant.recht, mandant.baustein, mandant.baustein_recht, mandant.rolle,
                mandant.rolle_baustein, mandant.zuweisung TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA mandant FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mandant.rechte_des_subjekts(text, timestamptz) TO kumero_app, kumero_lesen;
GRANT EXECUTE ON FUNCTION
    mandant.modul_registrieren(text, text, text, text[]),
    mandant.modul_protokoll(text, text, text, jsonb, jsonb, jsonb),
    mandant.recht_anmelden(text, text, text, boolean, boolean, boolean),
    mandant.baustein_setzen(text, text, text, text[]),
    mandant.rolle_setzen(text, text, text, text, jsonb),
    mandant.zuweisen(text, text, text, timestamptz),
    mandant.zuweisung_beenden(text, uuid)
  TO kumero_app;
