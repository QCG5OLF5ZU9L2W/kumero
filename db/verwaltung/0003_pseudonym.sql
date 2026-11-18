-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Verwaltungsdatenbank, Migration 0003: Nutzer gehören zum Mandanten (F114; VERWALTUNG.md Abschnitt 2, 3 und 11)
--
-- Nutzer, ihre Passkeys, Sitzungen und Einstellungen liegen ab hier im Mandanten (Kern, Migration 0012).
-- Die Verwaltungsdatenbank behält die Mandantenliste, die Wasserstandsmarken und die System-Verwalter –
-- und führt neu die Zuordnung Pseudonym → Mandant, mit der die eine Anmeldeseite der Installation den
-- Mandanten eines vorgelegten Passkeys bestimmt (U1).
--
--   * verwaltung.pseudonym: Pseudonym → Mandant, sonst nichts. Kein Name, keine E-Mail, keine Rolle, kein
--     Schlüssel, kein Zeitpunkt, kein Protokolleintrag. Geprüft wird ein Passkey im Mandanten; ein falscher
--     Eintrag hier führt in einen Mandanten, der den Passkey nicht kennt – er verschafft keinen Zugang.
--   * verwaltung.nutzer, anmelde_credential, registrierung, sitzung, nutzer_einstellung gelten nur noch für
--     System-Verwalter. Anmeldung, Sitzung und Passkey verlangen das Kennzeichen „verwalter“.
--   * verwaltung.zugang wird für Nutzer nicht mehr gelesen; `bin/kumero system:nutzer-ueberfuehren` überführt
--     den Bestand in die Mandanten und leert die Einträge der Nutzer. Dienstkonten und Tokens bleiben bis zu
--     einer späteren Stufe hier (U7).

CREATE TABLE verwaltung.pseudonym (
    pseudonym text PRIMARY KEY CHECK (pseudonym ~ '^[A-Za-z0-9_-]{22,86}$'),   -- base64url, 16–64 Byte (WebAuthn user.id)
    mandant   text REFERENCES verwaltung.mandant                               -- NULL = Installation (System-Verwalter)
);
CREATE INDEX pseudonym_mandant_idx ON verwaltung.pseudonym (mandant);

ALTER TABLE verwaltung.nutzer ADD COLUMN pseudonym text UNIQUE REFERENCES verwaltung.pseudonym;

-- Trägt ein Pseudonym ein. Bestehende Einträge ändert die Funktion nie: Wer ein Pseudonym kennt, kann es
-- damit nicht auf einen anderen Mandanten umlenken.
CREATE FUNCTION verwaltung.pseudonym_eintragen(p_pseudonym text, p_mandant text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
BEGIN
    IF p_mandant IS NULL THEN
        PERFORM verwaltung.verweigern('Pseudonyme der Installation entstehen nur mit einem System-Verwalter', 'KM003');
    END IF;
    INSERT INTO verwaltung.pseudonym (pseudonym, mandant) VALUES (p_pseudonym, p_mandant);
END $$;

-- ---------------------------------------------------------------------------
-- System-Verwalter: eigene Identität der Installation (U4)
-- ---------------------------------------------------------------------------
DROP FUNCTION verwaltung.nutzer_anlegen(text, text, text, boolean);
DROP FUNCTION verwaltung.zugang_gewaehren(text, text, text);

CREATE FUNCTION verwaltung.verwalter_anlegen(p_subjekt text, p_name text, p_sprache text, p_pseudonym text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE nid uuid;
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    INSERT INTO verwaltung.pseudonym (pseudonym, mandant) VALUES (p_pseudonym, NULL);
    INSERT INTO verwaltung.nutzer (name, sprache, verwalter, pseudonym) VALUES (p_name, p_sprache, true, p_pseudonym)
        RETURNING id INTO nid;
    PERFORM verwaltung._protokoll('nutzer.angelegt', p_subjekt, jsonb_build_object('nutzer', nid::text),
        NULL, jsonb_build_object('name', p_name, 'sprache', p_sprache, 'verwalter', true, 'aktiv', true));
    RETURN nid;
END $$;

-- Nur für das Überführen des Bestands (U6), nicht für die Anwendung freigegeben: gibt einem vorhandenen
-- System-Verwalter sein Pseudonym. Das ist seine bisherige Nutzerkennung, wenn er in keinem Mandanten arbeitet
-- (seine Passkeys gelten weiter), sonst ein neues – die bisherige Kennung gehört dann zu seiner Identität im
-- Mandanten.
CREATE FUNCTION verwaltung.verwalter_pseudonym_setzen(p_subjekt text, p_nutzer uuid, p_pseudonym text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    IF NOT EXISTS (SELECT 1 FROM verwaltung.nutzer WHERE id = p_nutzer AND verwalter AND pseudonym IS NULL) THEN
        PERFORM verwaltung.verweigern('Kein System-Verwalter ohne Pseudonym', 'KM003');
    END IF;
    INSERT INTO verwaltung.pseudonym (pseudonym, mandant) VALUES (p_pseudonym, NULL);
    UPDATE verwaltung.nutzer SET pseudonym = p_pseudonym WHERE id = p_nutzer;
    PERFORM verwaltung._protokoll('nutzer.pseudonym_gesetzt', p_subjekt, jsonb_build_object('nutzer', p_nutzer::text));
END $$;

-- Administrator eines Mandanten eingesetzt (U5): Die Verwaltung vermerkt nur, dass und für welchen Mandanten –
-- die Identität selbst und ihr Protokolleintrag liegen im Mandanten.
CREATE FUNCTION verwaltung.administrator_vermerken(p_subjekt text, p_mandant text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    IF NOT EXISTS (SELECT 1 FROM verwaltung.mandant WHERE kennung = p_mandant) THEN
        PERFORM verwaltung.verweigern('Mandant unbekannt', 'KM003');
    END IF;
    PERFORM verwaltung._protokoll('mandant.administrator_eingesetzt', p_subjekt, jsonb_build_object('mandant', p_mandant));
END $$;

-- Passkeys, Anmeldung und Sitzung in der Verwaltung: nur noch für aktive System-Verwalter
CREATE OR REPLACE FUNCTION verwaltung.credential_registrieren(p_subjekt text, p_nutzer uuid, p_credential_id text, p_public_key bytea,
                                                              p_alg integer, p_zaehler bigint, p_bezeichnung text,
                                                              p_registrierung_hash text DEFAULT NULL)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE r verwaltung.registrierung;
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    IF p_registrierung_hash IS NOT NULL THEN
        SELECT * INTO r FROM verwaltung.registrierung WHERE token_hash = p_registrierung_hash FOR UPDATE;
        IF NOT FOUND OR r.nutzer <> p_nutzer OR r.verwendet_am IS NOT NULL OR r.ablauf <= now() THEN
            PERFORM verwaltung.verweigern('Registrierungslink ungültig, abgelaufen oder verbraucht', 'KM003');
        END IF;
        UPDATE verwaltung.registrierung SET verwendet_am = now() WHERE token_hash = p_registrierung_hash;
    ELSIF p_subjekt <> 'nutzer:' || p_nutzer THEN
        PERFORM verwaltung.verweigern('Passkeys registriert nur der Nutzer selbst oder ein Registrierungslink', 'KM003');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM verwaltung.nutzer WHERE id = p_nutzer AND aktiv AND verwalter AND pseudonym IS NOT NULL) THEN
        PERFORM verwaltung.verweigern('Kein aktiver System-Verwalter', 'KM003');
    END IF;
    INSERT INTO verwaltung.anmelde_credential (credential_id, nutzer, public_key, alg, zaehler, bezeichnung)
         VALUES (p_credential_id, p_nutzer, p_public_key, p_alg, p_zaehler, p_bezeichnung);
    PERFORM verwaltung._protokoll('credential.registriert', p_subjekt,
        jsonb_build_object('nutzer', p_nutzer::text, 'credential', p_credential_id), NULL,
        jsonb_build_object('alg', p_alg, 'bezeichnung', p_bezeichnung,
                           'public_key_sha256', encode(sha256(p_public_key), 'hex')));
END $$;

CREATE OR REPLACE FUNCTION verwaltung.anmeldung_vermerken(p_credential_id text, p_zaehler bigint)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE c verwaltung.anmelde_credential;
BEGIN
    SELECT * INTO c FROM verwaltung.anmelde_credential WHERE credential_id = p_credential_id FOR UPDATE;
    IF NOT FOUND OR c.widerrufen_am IS NOT NULL THEN
        PERFORM verwaltung.verweigern('Passkey unbekannt oder widerrufen', 'KM003');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM verwaltung.nutzer WHERE id = c.nutzer AND aktiv AND verwalter) THEN
        PERFORM verwaltung.verweigern('Kein aktiver System-Verwalter', 'KM003');
    END IF;
    IF (p_zaehler > 0 OR c.zaehler > 0) AND p_zaehler <= c.zaehler THEN
        PERFORM verwaltung.verweigern('Signaturzähler ist nicht gestiegen – möglicherweise geklonter Passkey', 'KM003');
    END IF;
    UPDATE verwaltung.anmelde_credential SET zaehler = p_zaehler WHERE credential_id = p_credential_id;
    RETURN c.nutzer;
END $$;

CREATE OR REPLACE FUNCTION verwaltung.sitzung_pruefen(p_hash text, p_leerlauf interval DEFAULT interval '30 minutes')
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE s verwaltung.sitzung;
BEGIN
    SELECT * INTO s FROM verwaltung.sitzung WHERE hash = p_hash FOR UPDATE;
    IF NOT FOUND OR s.beendet_am IS NOT NULL OR s.ablauf_absolut <= now() OR s.zuletzt_am + p_leerlauf <= now() THEN
        RETURN NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM verwaltung.nutzer WHERE 'nutzer:' || id = s.subjekt AND aktiv AND verwalter) THEN
        RETURN NULL;
    END IF;
    UPDATE verwaltung.sitzung SET zuletzt_am = now() WHERE hash = p_hash;
    RETURN s.subjekt;
END $$;

-- Sitzungen aus der Zeit vor dem Umbau gelten nicht weiter: Das Cookie hat eine neue Form, und Nutzer der
-- Mandanten melden sich künftig dort an.
UPDATE verwaltung.sitzung SET beendet_am = now() WHERE beendet_am IS NULL;

REVOKE ALL ON verwaltung.pseudonym FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON verwaltung.pseudonym TO kumero_app;
-- Pseudonyme eintragen, Verwalter anlegen und Administratoren einsetzen: in dieser Stufe nur über die
-- Kommandozeile (kumero_owner). Die Anwendung bekommt die Rechte mit den Seiten der Stufe 2.
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA verwaltung FROM PUBLIC;
GRANT EXECUTE ON FUNCTION
    verwaltung.jcs(jsonb), verwaltung.sha256_hex(text), verwaltung.kettenhash(text, jsonb),
    verwaltung.zeitpunkt(timestamptz), verwaltung.protokoll_inhalt(verwaltung.protokoll),
    verwaltung.kette_pruefen()
  TO kumero_app, kumero_lesen;
