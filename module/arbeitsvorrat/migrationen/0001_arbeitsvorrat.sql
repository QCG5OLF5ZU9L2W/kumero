-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul arbeitsvorrat, Migration 0001: der Posteingang der Buchhaltung (ARBEITSVORRAT.md, F89)
--
-- Ein Eintrag ist ein Vorgang, der zu buchen ist – unabhängig von der Quelle. Quellen liefern
-- Beiträge (Quelle + Schlüssel, Angaben, Vorschlag); die Felder des Eintrags ergeben sich daraus.
-- Eigene Daten im eigenen Schema (F44), Schreiben nur über Funktionen mit Protokoll (F48, F64).
-- Buchungen des Kerns werden nur als ID gemerkt; ihren Stand liefert die Anwendung über die
-- Modul-API, die Regeln für den Zustand stehen hier (arbeitsvorrat.abgleichen).

CREATE SCHEMA arbeitsvorrat AUTHORIZATION kumero_owner;
REVOKE ALL ON SCHEMA arbeitsvorrat FROM PUBLIC;
GRANT USAGE ON SCHEMA arbeitsvorrat TO kumero_app, kumero_lesen;

CREATE TABLE arbeitsvorrat.eintrag (
    id                   uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    zustand              text NOT NULL DEFAULT 'offen'
                         CHECK (zustand IN ('offen', 'in_arbeit', 'gebucht', 'verworfen', 'zurueckgezogen')),
    betreff              text NOT NULL CHECK (length(trim(betreff)) BETWEEN 1 AND 200),
    art                  text CHECK (art ~ '^[a-z][a-z0-9_-]{0,39}$'),
    gegenpartei          text CHECK (length(gegenpartei) BETWEEN 1 AND 200),
    gegenpartei_kennung  text CHECK (length(gegenpartei_kennung) BETWEEN 1 AND 60),
    datum                date,
    betrag               numeric(15,2) CHECK (betrag > 0),
    richtung             text CHECK (richtung IN ('ausgabe', 'einnahme')),
    belegnummer          text CHECK (length(belegnummer) BETWEEN 1 AND 36),
    zahlweise            text CHECK (zahlweise IN ('bar', 'unbar')),
    zahltag              date,
    belege               jsonb NOT NULL DEFAULT '[]',   -- [{link, md5?, sha256?}] nach F88
    hinweise             jsonb NOT NULL DEFAULT '[]',   -- [{quelle, feld?, text}] aus den Beiträgen
    vermerke             jsonb NOT NULL DEFAULT '[]',   -- [{am, text}] aus dem Ablauf (Storno, späte Lieferung)
    verworfen_grund      text,
    eingegangen_am       timestamptz NOT NULL DEFAULT now(),
    erledigt_am          timestamptz,
    erledigt_von         text,
    CHECK ((zustand = 'verworfen') = (verworfen_grund IS NOT NULL))
);
CREATE INDEX eintrag_zustand_idx ON arbeitsvorrat.eintrag (zustand, eingegangen_am);

CREATE TABLE arbeitsvorrat.beitrag (
    id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    eintrag            uuid NOT NULL REFERENCES arbeitsvorrat.eintrag,
    quelle             text NOT NULL CHECK (quelle ~ '^[a-z][a-z0-9-]{0,31}$'),
    schluessel         text NOT NULL CHECK (length(schluessel) BETWEEN 1 AND 200),
    angaben            jsonb NOT NULL DEFAULT '[]',     -- [{bezeichnung, wert}] nur zur Anzeige
    vorschlag          jsonb NOT NULL,
    geliefert_am       timestamptz NOT NULL DEFAULT now(),
    geaendert_am       timestamptz NOT NULL DEFAULT now(),
    zurueckgezogen_am  timestamptz,
    UNIQUE (quelle, schluessel)
);
CREATE INDEX beitrag_eintrag_idx ON arbeitsvorrat.beitrag (eintrag);

-- Buchungen (Entwürfe und festgeschriebene), die aus einem Eintrag entstanden sind
CREATE TABLE arbeitsvorrat.eintrag_buchung (
    eintrag        uuid NOT NULL REFERENCES arbeitsvorrat.eintrag,
    buchung        uuid NOT NULL UNIQUE,
    stand          text NOT NULL DEFAULT 'entwurf' CHECK (stand IN ('entwurf', 'festgeschrieben', 'storniert')),
    verknuepft_am  timestamptz NOT NULL DEFAULT now(),
    verknuepft_von text NOT NULL,
    PRIMARY KEY (eintrag, buchung)
);

-- Regel 1: Art → Geschäftsvorfall (Vorlage des Moduls buchen, nur als ID gemerkt)
CREATE TABLE arbeitsvorrat.regel_art (
    art           text PRIMARY KEY CHECK (art ~ '^[a-z][a-z0-9_-]{0,39}$'),
    vorlage       uuid NOT NULL,
    geaendert_am  timestamptz NOT NULL DEFAULT now(),
    geaendert_von text NOT NULL
);

-- Regel 2: Gegenpartei → zuletzt bestätigtes Konto, beim Buchen gemerkt
CREATE TABLE arbeitsvorrat.gemerkt (
    schluessel    text PRIMARY KEY,                 -- Kennung, sonst Name in Kleinbuchstaben
    gegenpartei   text NOT NULL,
    konto         text NOT NULL CHECK (length(konto) BETWEEN 1 AND 20),
    geaendert_am  timestamptz NOT NULL DEFAULT now(),
    geaendert_von text NOT NULL
);

CREATE FUNCTION arbeitsvorrat._fehler(p_text text, p_code text DEFAULT 'KM003') RETURNS void
LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION USING ERRCODE = p_code, MESSAGE = p_text; END $$;

CREATE FUNCTION arbeitsvorrat._gp_schluessel(p_kennung text, p_name text) RETURNS text
LANGUAGE sql IMMUTABLE RETURN coalesce(nullif(trim(p_kennung), ''), lower(trim(p_name)));

-- Setzt die Felder eines Eintrags neu aus seinen nicht zurückgezogenen Beiträgen: Der zuerst
-- eingegangene Wert gilt, Abweichungen späterer Quellen stehen als Hinweis daneben (W3).
CREATE FUNCTION arbeitsvorrat._felder_setzen(p_eintrag uuid) RETURNS void
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
                                 'belegnummer', 'zahlweise', 'zahltag'] LOOP
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
           belege = v_belege, hinweise = v_hinw
     WHERE id = p_eintrag;
END $$;

CREATE FUNCTION arbeitsvorrat._vermerk(p_eintrag uuid, p_text text) RETURNS void
LANGUAGE sql AS $$
    UPDATE arbeitsvorrat.eintrag
       SET vermerke = vermerke || jsonb_build_object('am', to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"'), 'text', p_text)
     WHERE id = p_eintrag
$$;

-- Vertrag für Quellen (W5): einliefern. Derselbe Schlüssel ergibt nie einen zweiten Eintrag;
-- Beiträge mit demselben Beleglink gehören zum selben Eintrag (W4).
-- Ergebnis: {eintrag, ergebnis: angelegt | ersetzt | unveraendert | vermerkt}
CREATE FUNCTION arbeitsvorrat.einliefern(p_subjekt text, p_quelle text, p_schluessel text, p_vorschlag jsonb, p_angaben jsonb DEFAULT '[]')
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
           AND EXISTS (SELECT 1 FROM jsonb_array_elements(coalesce(p_vorschlag->'belege', '[]')) n
                        WHERE x.belege @> jsonb_build_array(jsonb_build_object('link', n->>'link')))
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

-- Vertrag für Quellen (W5): zurückziehen – die Quelle hält den Vorgang nicht mehr für offen.
CREATE FUNCTION arbeitsvorrat.zurueckziehen(p_subjekt text, p_quelle text, p_schluessel text)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, arbeitsvorrat, pg_temp AS $$
DECLARE
    b arbeitsvorrat.beitrag;
    e arbeitsvorrat.eintrag;
BEGIN
    SELECT * INTO b FROM arbeitsvorrat.beitrag WHERE quelle = p_quelle AND schluessel = p_schluessel FOR UPDATE;
    IF NOT FOUND OR b.zurueckgezogen_am IS NOT NULL THEN
        RETURN false;
    END IF;
    UPDATE arbeitsvorrat.beitrag SET zurueckgezogen_am = clock_timestamp() WHERE id = b.id;
    SELECT * INTO e FROM arbeitsvorrat.eintrag WHERE id = b.eintrag FOR UPDATE;
    IF e.zustand = 'offen' THEN
        IF EXISTS (SELECT 1 FROM arbeitsvorrat.beitrag WHERE eintrag = e.id AND zurueckgezogen_am IS NULL) THEN
            PERFORM arbeitsvorrat._felder_setzen(e.id);
        ELSE
            UPDATE arbeitsvorrat.eintrag SET zustand = 'zurueckgezogen' WHERE id = e.id;
        END IF;
    ELSE
        PERFORM arbeitsvorrat._vermerk(e.id, format('Quelle %s hat ihren Beitrag zurückgezogen', p_quelle));
    END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'arbeitsvorrat', 'arbeitsvorrat.beitrag_zurueckgezogen',
        jsonb_build_object('eintrag', e.id::text, 'quelle', p_quelle, 'schluessel', p_schluessel));
    RETURN true;
END $$;

-- Entwurf mit dem Eintrag verknüpfen (ruft die Buchungsmaske nach dem Speichern). p_konto: das vom
-- Nutzer bestätigte Konto der Gegenpartei, wird gemerkt (Regel 2, W11).
CREATE FUNCTION arbeitsvorrat.verknuepfen(p_subjekt text, p_eintrag uuid, p_buchung uuid, p_konto text DEFAULT NULL)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, arbeitsvorrat, pg_temp AS $$
DECLARE e arbeitsvorrat.eintrag;
BEGIN
    SELECT * INTO e FROM arbeitsvorrat.eintrag WHERE id = p_eintrag FOR UPDATE;
    IF NOT FOUND THEN PERFORM arbeitsvorrat._fehler('Eintrag unbekannt'); END IF;
    IF e.zustand IN ('verworfen', 'zurueckgezogen') THEN
        PERFORM arbeitsvorrat._fehler('Der Eintrag ist verworfen oder zurückgezogen; erst wieder öffnen');
    END IF;
    IF EXISTS (SELECT 1 FROM arbeitsvorrat.eintrag_buchung WHERE buchung = p_buchung AND eintrag <> p_eintrag) THEN
        PERFORM arbeitsvorrat._fehler('Die Buchung gehört schon zu einem anderen Eintrag');
    END IF;
    INSERT INTO arbeitsvorrat.eintrag_buchung (eintrag, buchung, verknuepft_von) VALUES (p_eintrag, p_buchung, p_subjekt)
        ON CONFLICT DO NOTHING;
    IF e.zustand = 'offen' THEN
        UPDATE arbeitsvorrat.eintrag SET zustand = 'in_arbeit' WHERE id = p_eintrag;
    END IF;
    IF p_konto IS NOT NULL AND e.gegenpartei IS NOT NULL THEN
        INSERT INTO arbeitsvorrat.gemerkt (schluessel, gegenpartei, konto, geaendert_von)
             VALUES (arbeitsvorrat._gp_schluessel(e.gegenpartei_kennung, e.gegenpartei), e.gegenpartei, p_konto, p_subjekt)
        ON CONFLICT (schluessel) DO UPDATE SET gegenpartei = EXCLUDED.gegenpartei, konto = EXCLUDED.konto,
               geaendert_am = clock_timestamp(), geaendert_von = EXCLUDED.geaendert_von;
    END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'arbeitsvorrat', 'arbeitsvorrat.eintrag_verknuepft',
        jsonb_build_object('eintrag', p_eintrag::text, 'buchung', p_buchung::text));
END $$;

-- Zustand aus dem Stand der Buchungen ableiten (W7). p_staende: {"<buchung>": "entwurf" | "festgeschrieben"
-- | "storniert" | "fehlt"} – geliefert von der Anwendung über die Modul-API des Kerns.
-- Entwurf gelöscht → wieder offen; Buchung storniert → wieder offen, mit Vermerk.
CREATE FUNCTION arbeitsvorrat.abgleichen(p_subjekt text, p_eintrag uuid, p_staende jsonb)
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, arbeitsvorrat, pg_temp AS $$
DECLARE
    e    arbeitsvorrat.eintrag;
    v    arbeitsvorrat.eintrag_buchung;
    s    text;
    neu  text;
BEGIN
    SELECT * INTO e FROM arbeitsvorrat.eintrag WHERE id = p_eintrag FOR UPDATE;
    IF NOT FOUND OR e.zustand NOT IN ('in_arbeit', 'gebucht') THEN
        RETURN e.zustand;
    END IF;
    FOR v IN SELECT * FROM arbeitsvorrat.eintrag_buchung WHERE eintrag = p_eintrag LOOP
        s := p_staende->>(v.buchung::text);
        CONTINUE WHEN s IS NULL OR s = v.stand;
        IF s = 'fehlt' THEN
            DELETE FROM arbeitsvorrat.eintrag_buchung WHERE eintrag = p_eintrag AND buchung = v.buchung;
        ELSE
            UPDATE arbeitsvorrat.eintrag_buchung SET stand = s WHERE eintrag = p_eintrag AND buchung = v.buchung;
            IF s = 'storniert' THEN
                PERFORM arbeitsvorrat._vermerk(p_eintrag, 'Die Buchung zu diesem Eintrag wurde storniert');
            END IF;
        END IF;
    END LOOP;
    neu := CASE WHEN EXISTS (SELECT 1 FROM arbeitsvorrat.eintrag_buchung WHERE eintrag = p_eintrag AND stand = 'festgeschrieben') THEN 'gebucht'
                WHEN EXISTS (SELECT 1 FROM arbeitsvorrat.eintrag_buchung WHERE eintrag = p_eintrag AND stand = 'entwurf') THEN 'in_arbeit'
                ELSE 'offen' END;
    IF neu <> e.zustand THEN
        UPDATE arbeitsvorrat.eintrag SET zustand = neu,
               erledigt_am = CASE WHEN neu = 'gebucht' THEN clock_timestamp() END,
               erledigt_von = CASE WHEN neu = 'gebucht' THEN p_subjekt END
         WHERE id = p_eintrag;
        IF neu <> 'in_arbeit' THEN
            PERFORM kern.modul_protokoll(p_subjekt, 'arbeitsvorrat',
                CASE neu WHEN 'gebucht' THEN 'arbeitsvorrat.eintrag_gebucht' ELSE 'arbeitsvorrat.eintrag_wieder_offen' END,
                jsonb_build_object('eintrag', p_eintrag::text));
        END IF;
    END IF;
    RETURN neu;
END $$;

CREATE FUNCTION arbeitsvorrat.verwerfen(p_subjekt text, p_eintrag uuid, p_grund text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, arbeitsvorrat, pg_temp AS $$
DECLARE e arbeitsvorrat.eintrag;
BEGIN
    SELECT * INTO e FROM arbeitsvorrat.eintrag WHERE id = p_eintrag FOR UPDATE;
    IF NOT FOUND THEN PERFORM arbeitsvorrat._fehler('Eintrag unbekannt'); END IF;
    IF e.zustand <> 'offen' THEN
        PERFORM arbeitsvorrat._fehler('Verworfen wird nur ein offener Eintrag; zu Einträgen in Arbeit erst den Entwurf löschen');
    END IF;
    IF length(trim(coalesce(p_grund, ''))) NOT BETWEEN 1 AND 300 THEN
        PERFORM arbeitsvorrat._fehler('Grund mit 1 bis 300 Zeichen angeben', 'KM002');
    END IF;
    UPDATE arbeitsvorrat.eintrag SET zustand = 'verworfen', verworfen_grund = trim(p_grund),
           erledigt_am = clock_timestamp(), erledigt_von = p_subjekt WHERE id = p_eintrag;
    PERFORM kern.modul_protokoll(p_subjekt, 'arbeitsvorrat', 'arbeitsvorrat.eintrag_verworfen',
        jsonb_build_object('eintrag', p_eintrag::text), NULL, jsonb_build_object('grund', trim(p_grund)));
END $$;

CREATE FUNCTION arbeitsvorrat.oeffnen(p_subjekt text, p_eintrag uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, arbeitsvorrat, pg_temp AS $$
DECLARE e arbeitsvorrat.eintrag;
BEGIN
    SELECT * INTO e FROM arbeitsvorrat.eintrag WHERE id = p_eintrag FOR UPDATE;
    IF NOT FOUND THEN PERFORM arbeitsvorrat._fehler('Eintrag unbekannt'); END IF;
    IF e.zustand <> 'verworfen' THEN
        PERFORM arbeitsvorrat._fehler('Wieder geöffnet wird nur ein verworfener Eintrag');
    END IF;
    UPDATE arbeitsvorrat.eintrag SET zustand = 'offen', verworfen_grund = NULL, erledigt_am = NULL, erledigt_von = NULL
     WHERE id = p_eintrag;
    PERFORM kern.modul_protokoll(p_subjekt, 'arbeitsvorrat', 'arbeitsvorrat.eintrag_wieder_offen',
        jsonb_build_object('eintrag', p_eintrag::text), jsonb_build_object('grund', e.verworfen_grund), NULL);
END $$;

CREATE FUNCTION arbeitsvorrat.regel_art_setzen(p_subjekt text, p_art text, p_vorlage uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, arbeitsvorrat, pg_temp AS $$
DECLARE alt uuid;
BEGIN
    SELECT vorlage INTO alt FROM arbeitsvorrat.regel_art WHERE art = p_art FOR UPDATE;
    IF p_vorlage IS NULL THEN
        DELETE FROM arbeitsvorrat.regel_art WHERE art = p_art;
        IF NOT FOUND THEN PERFORM arbeitsvorrat._fehler('Regel unbekannt'); END IF;
    ELSE
        INSERT INTO arbeitsvorrat.regel_art (art, vorlage, geaendert_von) VALUES (p_art, p_vorlage, p_subjekt)
        ON CONFLICT (art) DO UPDATE SET vorlage = EXCLUDED.vorlage, geaendert_am = clock_timestamp(), geaendert_von = EXCLUDED.geaendert_von;
    END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'arbeitsvorrat', 'arbeitsvorrat.regel_geaendert', jsonb_build_object('art', p_art),
        CASE WHEN alt IS NULL THEN NULL ELSE jsonb_build_object('vorlage', alt::text) END,
        CASE WHEN p_vorlage IS NULL THEN NULL ELSE jsonb_build_object('vorlage', p_vorlage::text) END);
END $$;

CREATE FUNCTION arbeitsvorrat.gemerkt_loeschen(p_subjekt text, p_schluessel text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, arbeitsvorrat, pg_temp AS $$
DECLARE alt arbeitsvorrat.gemerkt;
BEGIN
    DELETE FROM arbeitsvorrat.gemerkt WHERE schluessel = p_schluessel RETURNING * INTO alt;
    IF NOT FOUND THEN PERFORM arbeitsvorrat._fehler('Zuordnung unbekannt'); END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'arbeitsvorrat', 'arbeitsvorrat.regel_geaendert', jsonb_build_object('gegenpartei', p_schluessel),
        jsonb_build_object('konto', alt.konto), NULL);
END $$;

REVOKE ALL ON ALL TABLES IN SCHEMA arbeitsvorrat FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON ALL TABLES IN SCHEMA arbeitsvorrat TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA arbeitsvorrat FROM PUBLIC;
GRANT EXECUTE ON FUNCTION
    arbeitsvorrat.einliefern(text, text, text, jsonb, jsonb), arbeitsvorrat.zurueckziehen(text, text, text),
    arbeitsvorrat.verknuepfen(text, uuid, uuid, text), arbeitsvorrat.abgleichen(text, uuid, jsonb),
    arbeitsvorrat.verwerfen(text, uuid, text), arbeitsvorrat.oeffnen(text, uuid),
    arbeitsvorrat.regel_art_setzen(text, text, uuid), arbeitsvorrat.gemerkt_loeschen(text, text)
  TO kumero_app;
GRANT EXECUTE ON FUNCTION arbeitsvorrat._gp_schluessel(text, text) TO kumero_app, kumero_lesen;
