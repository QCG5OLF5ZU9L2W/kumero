-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul import, Migration 0002: Zwischenablage für das Original einer E-Rechnung (IMPORT.md Abschnitt 16, F110)
--
-- Das PDF einer eingelesenen E-Rechnung liegt in kumero nur so lange, bis feststeht, was aus dem Eintrag wird:
-- Wird er verworfen oder der Import zurückgezogen, wird der Inhalt gelöscht – ohne Buchung fehlt der Grund, die
-- Datei aufzubewahren (DSGVO, z. B. Fehlversand). Wird gebucht, geht das Original an das DMS, wird dort als Beleg der
-- Buchung nachgereicht (F27) und in kumero gelöscht. kumero hält kein Dokument dauerhaft.
-- Es bleibt immer die Zeile mit Prüfsummen als Nachweis; der Inhalt (bytea) wird auf NULL gesetzt.

CREATE TABLE import.datei (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    schluessel     text NOT NULL UNIQUE CHECK (length(schluessel) BETWEEN 1 AND 200),   -- Schlüssel des Beitrags im Arbeitsvorrat
    name           text NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
    typ            text NOT NULL CHECK (typ IN ('application/pdf')),
    groesse        integer NOT NULL CHECK (groesse BETWEEN 1 AND 5242880),
    sha256         text NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    md5            text NOT NULL CHECK (md5 ~ '^[0-9a-f]{32}$'),
    inhalt         bytea,
    abgelegt_am    timestamptz NOT NULL DEFAULT clock_timestamp(),
    abgelegt_von   text NOT NULL,
    gesendet_am    timestamptz,                    -- an das DMS gesendet; es verarbeitet die Datei noch
    dms_link       text,                           -- Belegverweis im DMS, sobald es die Datei führt
    geloescht_am   timestamptz,
    geloescht_von  text,
    geloescht_grund text CHECK (geloescht_grund IN ('verworfen', 'zurueckgezogen', 'uebergeben')),
    CHECK ((inhalt IS NULL) = (geloescht_am IS NOT NULL)),
    CHECK (inhalt IS NULL OR octet_length(inhalt) = groesse)
);

-- Legt das Original ab (Inhalt als Base64). Derselbe Schlüssel mit gelöschtem Inhalt wird neu belegt – z. B. wenn eine
-- zurückgezogene Rechnung wieder eingelesen wird.
CREATE FUNCTION import.datei_ablegen(p_subjekt text, p_schluessel text, p_name text, p_typ text, p_inhalt_b64 text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, import, pg_temp AS $$
DECLARE
    v_inhalt bytea := decode(p_inhalt_b64, 'base64');
    v_id     uuid;
BEGIN
    INSERT INTO import.datei (schluessel, name, typ, groesse, sha256, md5, inhalt, abgelegt_von)
    VALUES (p_schluessel, p_name, p_typ, octet_length(v_inhalt), encode(sha256(v_inhalt), 'hex'), md5(v_inhalt), v_inhalt, p_subjekt)
    ON CONFLICT (schluessel) DO UPDATE SET name = EXCLUDED.name, typ = EXCLUDED.typ, groesse = EXCLUDED.groesse, sha256 = EXCLUDED.sha256,
        md5 = EXCLUDED.md5, inhalt = EXCLUDED.inhalt, abgelegt_am = clock_timestamp(), abgelegt_von = EXCLUDED.abgelegt_von,
        geloescht_am = NULL, geloescht_von = NULL, geloescht_grund = NULL, gesendet_am = NULL, dms_link = NULL
    RETURNING id INTO v_id;
    -- das Protokoll nennt Prüfsumme und Größe, nie den Inhalt und nicht den Dateinamen
    PERFORM kern.modul_protokoll(p_subjekt, 'import', 'import.datei_abgelegt', jsonb_build_object('datei', v_id::text), NULL,
        jsonb_build_object('sha256', encode(sha256(v_inhalt), 'hex'), 'groesse', octet_length(v_inhalt)));
    RETURN v_id;
END $$;

-- Löscht den Inhalt; die Zeile bleibt als Nachweis. Liefert false, wenn es nichts mehr zu löschen gab.
CREATE FUNCTION import.datei_loeschen(p_subjekt text, p_id uuid, p_grund text)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, import, pg_temp AS $$
DECLARE v_sha text;
BEGIN
    UPDATE import.datei SET inhalt = NULL, geloescht_am = clock_timestamp(), geloescht_von = p_subjekt, geloescht_grund = p_grund
     WHERE id = p_id AND inhalt IS NOT NULL RETURNING sha256 INTO v_sha;
    IF NOT FOUND THEN
        RETURN false;
    END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'import', 'import.datei_geloescht', jsonb_build_object('datei', p_id::text),
        jsonb_build_object('sha256', v_sha), jsonb_build_object('grund', p_grund));
    RETURN true;
END $$;

-- Vermerkt, dass die Datei an das DMS gesendet ist (Übergabe nach der Buchung, F110)
CREATE FUNCTION import.datei_gesendet(p_subjekt text, p_id uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, import, pg_temp AS $$
BEGIN
    UPDATE import.datei SET gesendet_am = clock_timestamp() WHERE id = p_id AND inhalt IS NOT NULL AND gesendet_am IS NULL;
    IF FOUND THEN
        PERFORM kern.modul_protokoll(p_subjekt, 'import', 'import.datei_gesendet', jsonb_build_object('datei', p_id::text), NULL, NULL);
    END IF;
END $$;

-- Das DMS führt die Datei: Belegverweis merken und den Inhalt löschen – kumero hält kein Dokument dauerhaft
CREATE FUNCTION import.datei_uebergeben(p_subjekt text, p_id uuid, p_link text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, import, pg_temp AS $$
BEGIN
    UPDATE import.datei SET dms_link = p_link WHERE id = p_id AND inhalt IS NOT NULL;
    IF NOT FOUND THEN
        RAISE EXCEPTION USING ERRCODE = 'KM003', MESSAGE = 'Datei unbekannt oder schon gelöscht';
    END IF;
    PERFORM import.datei_loeschen(p_subjekt, p_id, 'uebergeben');
END $$;

-- Den Inhalt liest nur die Anwendung, nicht die Leserolle
REVOKE ALL ON import.datei FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON import.datei TO kumero_app;
GRANT SELECT (id, schluessel, name, typ, groesse, sha256, md5, abgelegt_am, abgelegt_von, gesendet_am, dms_link, geloescht_am, geloescht_von, geloescht_grund) ON import.datei TO kumero_lesen;
REVOKE EXECUTE ON FUNCTION import.datei_ablegen(text, text, text, text, text), import.datei_loeschen(text, uuid, text),
    import.datei_gesendet(text, uuid), import.datei_uebergeben(text, uuid, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION import.datei_ablegen(text, text, text, text, text), import.datei_loeschen(text, uuid, text),
    import.datei_gesendet(text, uuid), import.datei_uebergeben(text, uuid, text) TO kumero_app;
