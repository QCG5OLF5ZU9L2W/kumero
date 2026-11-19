-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Verwaltungsdatenbank, Migration 0004: Aufträge der Mandantenverwaltung (VERWALTUNG.md Abschnitt 5 und 14, F118)
--
-- Die Webanwendung (Rolle kumero_app) darf keine Datenbanken anlegen, sichern oder löschen. Die Seite „Mandanten“
-- des System-Verwalters schreibt deshalb einen **Auftrag**; ein eigener Dienst führt ihn als kumero_owner aus
-- (`bin/kumero system:auftraege`). Die Web-Rolle bekommt dadurch keine neuen Rechte.
--
-- Datenschutz (F114): Nichts über einen Nutzer eines Mandanten gehört dauerhaft in die Verwaltungsdatenbank. Die
-- Angaben zum ersten Administrator stehen nur so lange am Auftrag, bis er ausgeführt ist – danach sind sie NULL.
-- Das Protokoll nennt nur Auftrag, Art und Mandant.

CREATE TABLE verwaltung.auftrag (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    art          text NOT NULL CHECK (art IN ('mandant_anlegen', 'mandant_exportieren', 'mandant_loeschen')),
    mandant      text NOT NULL CHECK (mandant ~ '^[a-z][a-z0-9-]{1,31}$'),   -- ohne Fremdschlüssel: gibt es noch nicht bzw. nicht mehr
    angaben      jsonb,                                  -- nur bis zur Ausführung
    zustand      text NOT NULL DEFAULT 'offen' CHECK (zustand IN ('offen', 'laeuft', 'erledigt', 'fehlgeschlagen')),
    angelegt_am  timestamptz NOT NULL DEFAULT clock_timestamp(),
    angelegt_von text NOT NULL,
    begonnen_am  timestamptz,
    beendet_am   timestamptz,
    ergebnis     jsonb,                                  -- Export: Datei, Größe, SHA-256; Fehler: Code
    CHECK (zustand IN ('offen', 'laeuft') OR angaben IS NULL),
    CHECK ((zustand IN ('erledigt', 'fehlgeschlagen')) = (beendet_am IS NOT NULL))
);

-- je Mandant läuft höchstens ein Auftrag
CREATE UNIQUE INDEX auftrag_offen_idx ON verwaltung.auftrag (mandant) WHERE zustand IN ('offen', 'laeuft');
CREATE INDEX auftrag_zeit_idx ON verwaltung.auftrag (angelegt_am DESC);

-- Legt einen Auftrag an. Aufträge stellt nur ein aktiver System-Verwalter (oder die Kommandozeile, system:…).
CREATE FUNCTION verwaltung.auftrag_anlegen(p_subjekt text, p_art text, p_mandant text, p_angaben jsonb DEFAULT NULL)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE
    m   verwaltung.mandant;
    aid uuid;
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    IF p_subjekt LIKE 'nutzer:%' AND NOT EXISTS (
        SELECT 1 FROM verwaltung.nutzer WHERE id::text = substr(p_subjekt, 8) AND verwalter AND aktiv) THEN
        PERFORM verwaltung.verweigern('Aufträge stellt nur ein System-Verwalter', 'KM002');
    END IF;
    IF p_mandant IS NULL OR p_mandant !~ '^[a-z][a-z0-9-]{1,31}$' OR p_mandant IN ('verwaltung', 'vorlage') THEN
        PERFORM verwaltung.verweigern('Kennung: Kleinbuchstaben, Ziffern, Bindestrich; nicht „verwaltung“ oder „vorlage“', 'KM002');
    END IF;
    IF EXISTS (SELECT 1 FROM verwaltung.auftrag WHERE mandant = p_mandant AND zustand IN ('offen', 'laeuft')) THEN
        PERFORM verwaltung.verweigern('Für diesen Mandanten läuft schon ein Auftrag', 'KM003');
    END IF;
    SELECT * INTO m FROM verwaltung.mandant WHERE kennung = p_mandant;
    IF p_art = 'mandant_anlegen' THEN
        IF m.kennung IS NOT NULL OR EXISTS (SELECT 1 FROM verwaltung.mandant WHERE datenbank = 'kumero_' || replace(p_mandant, '-', '_')) THEN
            PERFORM verwaltung.verweigern('Mandant gibt es schon', 'KM003');
        END IF;
        IF p_angaben IS NULL OR length(trim(coalesce(p_angaben->>'name', ''))) = 0
           OR length(trim(coalesce(p_angaben->>'vornamen', ''))) = 0 OR length(trim(coalesce(p_angaben->>'familienname', ''))) = 0
           OR coalesce(p_angaben->>'email', '') !~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'
           OR coalesce(p_angaben->>'token_hash', '') !~ '^[0-9a-f]{64}$' THEN
            PERFORM verwaltung.verweigern('Name des Mandanten, Vorname(n), Familienname und E-Mail des Administrators erwartet', 'KM002');
        END IF;
    ELSIF p_art = 'mandant_exportieren' THEN
        IF m.kennung IS NULL THEN
            PERFORM verwaltung.verweigern('Mandant unbekannt', 'KM003');
        END IF;
        p_angaben := NULL;
    ELSIF p_art = 'mandant_loeschen' THEN
        IF m.kennung IS NULL THEN
            PERFORM verwaltung.verweigern('Mandant unbekannt', 'KM003');
        END IF;
        IF m.stillgelegt_am IS NULL THEN
            PERFORM verwaltung.verweigern('Nur ein stillgelegter Mandant lässt sich löschen', 'KM003');
        END IF;
        -- vorher exportieren: Die Sicherung muss nach dem Stilllegen entstanden sein – danach ändert sich nichts mehr
        IF NOT EXISTS (SELECT 1 FROM verwaltung.auftrag WHERE mandant = p_mandant AND art = 'mandant_exportieren'
                          AND zustand = 'erledigt' AND begonnen_am >= m.stillgelegt_am) THEN
            PERFORM verwaltung.verweigern('Vor dem Löschen muss der stillgelegte Mandant exportiert sein', 'KM003');
        END IF;
        p_angaben := NULL;
    ELSE
        PERFORM verwaltung.verweigern('Art des Auftrags unbekannt', 'KM002');
    END IF;
    INSERT INTO verwaltung.auftrag (art, mandant, angaben, angelegt_von) VALUES (p_art, p_mandant, p_angaben, p_subjekt) RETURNING id INTO aid;
    PERFORM verwaltung._protokoll('auftrag.angelegt', p_subjekt, jsonb_build_object('auftrag', aid::text, 'mandant', p_mandant, 'art', p_art));
    RETURN aid;
END $$;

-- Der Dienst übernimmt den ältesten offenen Auftrag (nur kumero_owner).
CREATE FUNCTION verwaltung.auftrag_uebernehmen()
RETURNS TABLE (id uuid, art text, mandant text, angaben jsonb, angelegt_von text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE aid uuid;
BEGIN
    SELECT a.id INTO aid FROM verwaltung.auftrag a WHERE a.zustand = 'offen' ORDER BY a.angelegt_am, a.id LIMIT 1 FOR UPDATE SKIP LOCKED;
    IF aid IS NULL THEN
        RETURN;
    END IF;
    UPDATE verwaltung.auftrag a SET zustand = 'laeuft', begonnen_am = clock_timestamp() WHERE a.id = aid;
    RETURN QUERY SELECT a.id, a.art, a.mandant, a.angaben, a.angelegt_von FROM verwaltung.auftrag a WHERE a.id = aid;
END $$;

-- Schließt einen Auftrag ab; die Angaben (Name, E-Mail, Hash des Zugangs) werden dabei entfernt.
CREATE FUNCTION verwaltung.auftrag_abschliessen(p_id uuid, p_erfolg boolean, p_ergebnis jsonb DEFAULT NULL)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE a verwaltung.auftrag;
BEGIN
    UPDATE verwaltung.auftrag SET zustand = CASE WHEN p_erfolg THEN 'erledigt' ELSE 'fehlgeschlagen' END,
           beendet_am = clock_timestamp(), angaben = NULL, ergebnis = p_ergebnis
     WHERE id = p_id AND zustand = 'laeuft' RETURNING * INTO a;
    IF a.id IS NULL THEN
        PERFORM verwaltung.verweigern('Auftrag unbekannt oder nicht in Arbeit', 'KM003');
    END IF;
    PERFORM verwaltung._protokoll(CASE WHEN p_erfolg THEN 'auftrag.erledigt' ELSE 'auftrag.fehlgeschlagen' END, 'system:auftrag',
        jsonb_build_object('auftrag', a.id::text, 'mandant', a.mandant, 'art', a.art), NULL, p_ergebnis);
END $$;

-- Aufträge, die beim Absturz des Dienstes „in Arbeit“ stehen geblieben sind, gelten nach einer Stunde als gescheitert.
CREATE FUNCTION verwaltung.auftraege_aufraeumen()
RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE a verwaltung.auftrag; n integer := 0;
BEGIN
    FOR a IN SELECT * FROM verwaltung.auftrag WHERE zustand = 'laeuft' AND begonnen_am < clock_timestamp() - interval '1 hour' FOR UPDATE LOOP
        PERFORM verwaltung.auftrag_abschliessen(a.id, false, jsonb_build_object('code', 'auftrag.abgebrochen'));
        n := n + 1;
    END LOOP;
    RETURN n;
END $$;

CREATE FUNCTION verwaltung.mandant_reaktivieren(p_subjekt text, p_kennung text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    IF EXISTS (SELECT 1 FROM verwaltung.auftrag WHERE mandant = p_kennung AND art = 'mandant_loeschen' AND zustand IN ('offen', 'laeuft')) THEN
        PERFORM verwaltung.verweigern('Der Mandant wird gerade gelöscht', 'KM003');
    END IF;
    UPDATE verwaltung.mandant SET stillgelegt_am = NULL WHERE kennung = p_kennung AND stillgelegt_am IS NOT NULL;
    IF NOT FOUND THEN PERFORM verwaltung.verweigern('Mandant unbekannt oder nicht stillgelegt', 'KM003'); END IF;
    PERFORM verwaltung._protokoll('mandant.reaktiviert', p_subjekt, jsonb_build_object('mandant', p_kennung));
END $$;

-- Entfernt den Mandanten aus der Verwaltung, nachdem der Dienst seine Datenbank gelöscht hat (V11): Es bleibt nur der
-- Protokolleintrag mit Kennung, Zeitpunkt, wer gelöscht hat, und dem letzten Kettenkopf – keine Buchungsdaten, kein Name.
CREATE FUNCTION verwaltung.mandant_loeschen(p_subjekt text, p_kennung text, p_kettenkopf jsonb)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    IF NOT EXISTS (SELECT 1 FROM verwaltung.mandant WHERE kennung = p_kennung AND stillgelegt_am IS NOT NULL) THEN
        PERFORM verwaltung.verweigern('Mandant unbekannt oder nicht stillgelegt', 'KM003');
    END IF;
    DELETE FROM verwaltung.pseudonym WHERE mandant = p_kennung;
    DELETE FROM verwaltung.wasserstand WHERE mandant = p_kennung;
    DELETE FROM verwaltung.zugang WHERE mandant = p_kennung;
    DELETE FROM verwaltung.token t USING verwaltung.dienstkonto d WHERE t.dienstkonto = d.id AND d.mandant = p_kennung;
    DELETE FROM verwaltung.dienstkonto WHERE mandant = p_kennung;
    DELETE FROM verwaltung.mandant WHERE kennung = p_kennung;
    PERFORM verwaltung._protokoll('mandant.geloescht', p_subjekt, jsonb_build_object('mandant', p_kennung), NULL, p_kettenkopf);
END $$;

REVOKE ALL ON verwaltung.auftrag FROM kumero_app, kumero_lesen, PUBLIC;
-- Die Anwendung sieht den Stand der Aufträge, nie die Angaben
GRANT SELECT (id, art, mandant, zustand, angelegt_am, angelegt_von, begonnen_am, beendet_am, ergebnis) ON verwaltung.auftrag TO kumero_app;
REVOKE EXECUTE ON FUNCTION verwaltung.auftrag_anlegen(text, text, text, jsonb), verwaltung.auftrag_uebernehmen(),
    verwaltung.auftrag_abschliessen(uuid, boolean, jsonb), verwaltung.auftraege_aufraeumen(),
    verwaltung.mandant_reaktivieren(text, text), verwaltung.mandant_loeschen(text, text, jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION verwaltung.auftrag_anlegen(text, text, text, jsonb), verwaltung.mandant_reaktivieren(text, text),
    verwaltung.mandant_stilllegen(text, text) TO kumero_app;

-- Der Name eines Mandanten gehört nicht in die unveränderliche Protokollkette: Nach dem Löschen soll von einem
-- Mandanten nur die Kennung bleiben (V11). Ab hier nennt der Eintrag „mandant.eingetragen“ den Namen nicht mehr;
-- ältere Einträge lassen sich nicht ändern.
CREATE OR REPLACE FUNCTION verwaltung.mandant_eintragen(p_subjekt text, p_kennung text, p_datenbank text, p_name text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    INSERT INTO verwaltung.mandant (kennung, datenbank, name) VALUES (p_kennung, p_datenbank, p_name);
    PERFORM verwaltung._protokoll('mandant.eingetragen', p_subjekt, jsonb_build_object('mandant', p_kennung),
        NULL, jsonb_build_object('datenbank', p_datenbank));
END $$;
