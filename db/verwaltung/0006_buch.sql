-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Verwaltungsdatenbank, Migration 0006: Bücher je Mandant (BUECHER.md Abschnitt 9, A1; F123, F126)
--
-- Ein Mandant hat ab hier eine Mandanten-Datenbank (`verwaltung.mandant.datenbank`, Schema `mandant`: Nutzer und
-- Bücherliste) und je Buch eine eigene Datenbank. Die Verwaltung führt je Buch nur Kennung und Datenbank – keinen
-- Namen, keine Art, keine Buchungsdaten. Die Wasserstandsmarke (F69) hängt am Buch.

CREATE TABLE verwaltung.buch (
    mandant      text NOT NULL REFERENCES verwaltung.mandant,
    kennung      text NOT NULL CHECK (kennung ~ '^[a-z][a-z0-9-]{1,19}$'),
    datenbank    text NOT NULL UNIQUE CHECK (datenbank ~ '^kumero_[a-z0-9_]{1,56}$'
                                             AND datenbank NOT IN ('kumero_verwaltung', 'kumero_vorlage')),
    angelegt_am  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (mandant, kennung)
);
REVOKE ALL ON verwaltung.buch FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON verwaltung.buch TO kumero_app, kumero_lesen;

-- Ein Buch eintragen (nur der Dienst und die Kommandozeile, kumero_owner): Das Buch entsteht mit seiner Datenbank.
CREATE FUNCTION verwaltung.buch_eintragen(p_subjekt text, p_mandant text, p_kennung text, p_datenbank text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    IF NOT EXISTS (SELECT 1 FROM verwaltung.mandant WHERE kennung = p_mandant) THEN
        PERFORM verwaltung.verweigern('Mandant unbekannt', 'KM003');
    END IF;
    IF EXISTS (SELECT 1 FROM verwaltung.mandant WHERE datenbank = p_datenbank) THEN
        PERFORM verwaltung.verweigern('Diese Datenbank ist die eines Mandanten', 'KM003');
    END IF;
    INSERT INTO verwaltung.buch (mandant, kennung, datenbank) VALUES (p_mandant, p_kennung, p_datenbank);
    PERFORM verwaltung._protokoll('buch.eingetragen', p_subjekt, jsonb_build_object('mandant', p_mandant, 'buch', p_kennung),
        NULL, jsonb_build_object('datenbank', p_datenbank));
END $$;

-- Wasserstandsmarke je Buch. Vorhandene Marken gehören zum ersten Buch des Mandanten; das Überführen
-- (`system:buecher-ueberfuehren`) setzt die Kennung, sobald das Buch eingetragen ist.
ALTER TABLE verwaltung.wasserstand ADD COLUMN buch text NOT NULL DEFAULT 'haupt';
ALTER TABLE verwaltung.wasserstand ALTER COLUMN buch DROP DEFAULT;
ALTER TABLE verwaltung.wasserstand DROP CONSTRAINT wasserstand_pkey;
ALTER TABLE verwaltung.wasserstand ADD PRIMARY KEY (mandant, buch, kette);

DROP FUNCTION verwaltung.wasserstand_setzen(text, smallint, bigint, text);
CREATE FUNCTION verwaltung.wasserstand_setzen(p_mandant text, p_buch text, p_kette smallint, p_nr bigint, p_hash text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE w verwaltung.wasserstand;
BEGIN
    SELECT * INTO w FROM verwaltung.wasserstand WHERE mandant = p_mandant AND buch = p_buch AND kette = p_kette FOR UPDATE;
    IF FOUND THEN
        IF p_nr < w.nr OR (p_nr = w.nr AND p_hash <> w.hash) THEN
            PERFORM verwaltung.verweigern(format('Wasserstand %s/%s/%s darf nicht zurückgehen (F69)', p_mandant, p_buch, p_kette), 'KM003');
        END IF;
        UPDATE verwaltung.wasserstand SET nr = p_nr, hash = p_hash, gesetzt_am = now()
         WHERE mandant = p_mandant AND buch = p_buch AND kette = p_kette;
    ELSE
        INSERT INTO verwaltung.wasserstand (mandant, buch, kette, nr, hash) VALUES (p_mandant, p_buch, p_kette, p_nr, p_hash);
    END IF;
END $$;
REVOKE EXECUTE ON FUNCTION verwaltung.buch_eintragen(text, text, text, text),
    verwaltung.wasserstand_setzen(text, text, smallint, bigint, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION verwaltung.wasserstand_setzen(text, text, smallint, bigint, text) TO kumero_app;

-- Auftrag „Buch anlegen“ (BUECHER.md A12): Ihn stellt der Administrator eines Mandanten über die Anwendung. Seine ID
-- gehört nicht in die Verwaltungsdatenbank (F114) – als Auftraggeber steht `system:mandant`; wer es war, steht nur
-- bis zur Ausführung in den Angaben und danach im Protokoll des Mandanten.
ALTER TABLE verwaltung.auftrag DROP CONSTRAINT auftrag_art_check;
ALTER TABLE verwaltung.auftrag ADD CONSTRAINT auftrag_art_check
    CHECK (art IN ('mandant_anlegen', 'mandant_exportieren', 'mandant_loeschen', 'buch_anlegen'));

CREATE FUNCTION verwaltung.buch_auftrag_anlegen(p_mandant text, p_angaben jsonb) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE aid uuid;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM verwaltung.mandant WHERE kennung = p_mandant AND stillgelegt_am IS NULL) THEN
        PERFORM verwaltung.verweigern('Mandant unbekannt oder stillgelegt', 'KM003');
    END IF;
    IF coalesce(p_angaben->>'kennung', '') !~ '^[a-z][a-z0-9-]{1,19}$' OR coalesce(p_angaben->>'art', '') NOT IN ('euer', 'bilanz')
       OR length(trim(coalesce(p_angaben->>'name', ''))) NOT BETWEEN 1 AND 100
       OR coalesce(p_angaben->>'buchwaehrung', '') !~ '^[A-Z]{3}$' OR jsonb_typeof(p_angaben->'testbuch') IS DISTINCT FROM 'boolean'
       OR coalesce(p_angaben->>'subjekt', '') !~ '^(nutzer|system):[A-Za-z0-9._-]+$' THEN
        PERFORM verwaltung.verweigern('Angaben zum Buch unvollständig oder ungültig', 'KM002');
    END IF;
    IF EXISTS (SELECT 1 FROM verwaltung.buch WHERE mandant = p_mandant AND kennung = p_angaben->>'kennung') THEN
        PERFORM verwaltung.verweigern('Ein Buch mit dieser Kennung gibt es schon', 'KM003');
    END IF;
    IF EXISTS (SELECT 1 FROM verwaltung.auftrag WHERE mandant = p_mandant AND zustand IN ('offen', 'laeuft')) THEN
        PERFORM verwaltung.verweigern('Für diesen Mandanten läuft schon ein Auftrag', 'KM003');
    END IF;
    INSERT INTO verwaltung.auftrag (art, mandant, angaben, angelegt_von) VALUES ('buch_anlegen', p_mandant, p_angaben, 'system:mandant')
        RETURNING id INTO aid;
    PERFORM verwaltung._protokoll('auftrag.angelegt', 'system:mandant',
        jsonb_build_object('auftrag', aid::text, 'mandant', p_mandant, 'art', 'buch_anlegen', 'buch', p_angaben->>'kennung'));
    RETURN aid;
END $$;
REVOKE EXECUTE ON FUNCTION verwaltung.buch_auftrag_anlegen(text, jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION verwaltung.buch_auftrag_anlegen(text, jsonb) TO kumero_app;

-- Mit dem Mandanten gehen seine Bücher.
CREATE OR REPLACE FUNCTION verwaltung.mandant_loeschen(p_subjekt text, p_kennung text, p_kettenkopf jsonb)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    IF NOT EXISTS (SELECT 1 FROM verwaltung.mandant WHERE kennung = p_kennung AND stillgelegt_am IS NOT NULL) THEN
        PERFORM verwaltung.verweigern('Mandant unbekannt oder nicht stillgelegt', 'KM003');
    END IF;
    DELETE FROM verwaltung.pseudonym WHERE mandant = p_kennung;
    DELETE FROM verwaltung.wasserstand WHERE mandant = p_kennung;
    DELETE FROM verwaltung.buch WHERE mandant = p_kennung;
    DELETE FROM verwaltung.zugang WHERE mandant = p_kennung;
    DELETE FROM verwaltung.token t USING verwaltung.dienstkonto d WHERE t.dienstkonto = d.id AND d.mandant = p_kennung;
    DELETE FROM verwaltung.dienstkonto WHERE mandant = p_kennung;
    DELETE FROM verwaltung.mandant WHERE kennung = p_kennung;
    PERFORM verwaltung._protokoll('mandant.geloescht', p_subjekt, jsonb_build_object('mandant', p_kennung), NULL, p_kettenkopf);
END $$;
