-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Kern, Migration 0012: Nutzer gehören zum Mandanten (F114; VERWALTUNG.md Abschnitt 2, 3 und 11)
--
-- Nutzer, Anmelde-Passkeys, Zugänge (Registrierungslinks), Sitzungen und Einstellungen liegen ab hier in der
-- Datenbank des Mandanten – nicht mehr in der Verwaltungsdatenbank. Wer hier als Nutzer steht, hat Zugang;
-- was er darf, sagen die Rollen (F63). Die Verwaltungsdatenbank kennt von einem Nutzer nur noch sein
-- Pseudonym (Zuordnung Pseudonym → Mandant), keinen Namen, keine Rolle, keinen Schlüssel.
--
--   * Die ID des Nutzers (`nutzer:<uuid>` in Protokoll und Buchungen) verlässt den Mandanten nicht.
--   * Das Pseudonym steckt als Nutzerkennung im Passkey (WebAuthn user.id); es steht nicht im Protokoll.
--   * Geschrieben wird nur über Funktionen (F64), mit Eintrag im Protokoll des Mandanten (F48).
--     Anmeldung und Sitzungen sind technisch und stehen nicht in der Kette – sie funktionieren deshalb
--     auch im Lesemodus (F60, F69).
--   * Geheimnisse (Sitzung, Link, Code) nur als SHA-256.

CREATE TABLE kern.nutzer (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    vornamen         text CHECK (vornamen IS NULL OR length(trim(vornamen)) > 0),
    familienname     text NOT NULL CHECK (length(trim(familienname)) > 0),
    email            text CHECK (email IS NULL OR email ~ '^[^@[:space:]]+@[^@[:space:]]+$'),
    sprache          text CHECK (sprache IS NULL OR sprache ~ '^[a-z]{2}$'),     -- NULL = wie der Browser (PROFIL.md U5)
    aktiv            boolean NOT NULL DEFAULT true,
    pseudonym        text NOT NULL UNIQUE CHECK (pseudonym ~ '^[A-Za-z0-9_-]{22,86}$'),   -- base64url, 16–64 Byte
    angelegt_am      timestamptz NOT NULL DEFAULT now(),
    letzte_anmeldung timestamptz
);

-- Anmelde-Passkeys (WebAuthn). Getrennt von den Freigabe-Credentials kern.credential (F38).
CREATE TABLE kern.anmelde_credential (
    credential_id   text PRIMARY KEY CHECK (credential_id ~ '^[A-Za-z0-9_-]+$' AND length(credential_id) BETWEEN 16 AND 1366),
    nutzer          uuid NOT NULL REFERENCES kern.nutzer,
    public_key      bytea NOT NULL,
    alg             integer NOT NULL CHECK (alg IN (-7, -8)),     -- ES256, EdDSA (ANWENDUNG.md E3)
    zaehler         bigint NOT NULL DEFAULT 0 CHECK (zaehler >= 0),
    bezeichnung     text,
    registriert_am  timestamptz NOT NULL DEFAULT now(),
    widerrufen_am   timestamptz
);
CREATE INDEX anmelde_credential_nutzer_idx ON kern.anmelde_credential (nutzer);

-- Einmalige Zugänge zum Einrichten eines Passkeys: Link, ab Stufe 2 zusätzlich ein Code (VERWALTUNG.md Abschnitt 6).
CREATE TABLE kern.registrierung (
    token_hash    text PRIMARY KEY CHECK (token_hash ~ '^[0-9a-f]{64}$'),
    nutzer        uuid NOT NULL REFERENCES kern.nutzer,
    code_hash     text CHECK (code_hash IS NULL OR code_hash ~ '^[0-9a-f]{64}$'),
    ablauf        timestamptz NOT NULL,
    fehlversuche  integer NOT NULL DEFAULT 0 CHECK (fehlversuche >= 0),
    verwendet_am  timestamptz,
    ungueltig_am  timestamptz
);
CREATE INDEX registrierung_nutzer_idx ON kern.registrierung (nutzer);

CREATE TABLE kern.sitzung (
    hash            text PRIMARY KEY CHECK (hash ~ '^[0-9a-f]{64}$'),
    nutzer          uuid NOT NULL REFERENCES kern.nutzer,
    angelegt_am     timestamptz NOT NULL DEFAULT now(),
    zuletzt_am      timestamptz NOT NULL DEFAULT now(),
    ablauf_absolut  timestamptz NOT NULL,
    beendet_am      timestamptz
);
CREATE INDEX sitzung_nutzer_idx ON kern.sitzung (nutzer) WHERE beendet_am IS NULL;

-- Einstellungen des Nutzers (PROFIL.md, F86) – jetzt je Identität im Mandanten.
CREATE TABLE kern.nutzer_einstellung (
    nutzer       uuid NOT NULL REFERENCES kern.nutzer,
    schluessel   text NOT NULL CHECK (schluessel ~ '^[a-z][a-z0-9-]{0,31}\.[a-z][a-z0-9_]{0,63}$'),
    wert         jsonb NOT NULL,
    geaendert_am timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (nutzer, schluessel)
);

-- ---------------------------------------------------------------------------
-- Nutzer
-- ---------------------------------------------------------------------------
CREATE FUNCTION kern._nutzer_stand(n kern.nutzer) RETURNS jsonb
LANGUAGE sql IMMUTABLE AS $$
    SELECT jsonb_build_object('vornamen', n.vornamen, 'familienname', n.familienname, 'email', n.email,
                              'sprache', n.sprache, 'aktiv', n.aktiv)
$$;

-- p_id nur beim Überführen des Bestands (U6): dieselbe ID wie bisher, damit Protokoll und Buchungen stimmen.
CREATE FUNCTION kern.nutzer_anlegen(p_subjekt text, p_familienname text, p_vornamen text, p_email text,
                                    p_sprache text, p_pseudonym text, p_id uuid DEFAULT NULL)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE n kern.nutzer;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    INSERT INTO kern.nutzer (id, vornamen, familienname, email, sprache, pseudonym)
         VALUES (coalesce(p_id, gen_random_uuid()), nullif(trim(p_vornamen), ''), trim(p_familienname),
                 nullif(lower(trim(p_email)), ''), p_sprache, p_pseudonym)
      RETURNING * INTO n;
    PERFORM kern._protokoll('nutzer.angelegt', p_subjekt, jsonb_build_object('nutzer', n.id::text), NULL, kern._nutzer_stand(n));
    RETURN n.id;
END $$;

-- Stammdaten ändern, sperren und entsperren. Sperren beendet alle Sitzungen.
CREATE FUNCTION kern.nutzer_aendern(p_subjekt text, p_nutzer uuid, p_familienname text, p_vornamen text,
                                    p_email text, p_aktiv boolean)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE alt kern.nutzer; neu kern.nutzer;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT * INTO alt FROM kern.nutzer WHERE id = p_nutzer FOR UPDATE;
    IF NOT FOUND THEN PERFORM kern.verweigern('Nutzer unbekannt', 'KM002'); END IF;
    UPDATE kern.nutzer SET vornamen = nullif(trim(p_vornamen), ''), familienname = trim(p_familienname),
                           email = nullif(lower(trim(p_email)), ''), aktiv = p_aktiv
     WHERE id = p_nutzer RETURNING * INTO neu;
    IF NOT p_aktiv THEN
        UPDATE kern.sitzung SET beendet_am = now() WHERE nutzer = p_nutzer AND beendet_am IS NULL;
    END IF;
    IF kern._nutzer_stand(alt) IS DISTINCT FROM kern._nutzer_stand(neu) THEN
        PERFORM kern._protokoll('nutzer.geaendert', p_subjekt, jsonb_build_object('nutzer', p_nutzer::text),
                                kern._nutzer_stand(alt), kern._nutzer_stand(neu));
    END IF;
END $$;

-- Der Nutzer ergänzt seine Angaben selbst, solange die E-Mail-Adresse fehlt (überführter Bestand, U6).
-- Danach ändert die Verwaltung des Mandanten Namen und Kontaktdaten (PROFIL.md U2).
CREATE FUNCTION kern.nutzer_angaben_ergaenzen(p_subjekt text, p_nutzer uuid, p_familienname text, p_vornamen text, p_email text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE alt kern.nutzer; neu kern.nutzer;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    IF p_subjekt <> 'nutzer:' || p_nutzer THEN
        PERFORM kern.verweigern('Fehlende Angaben ergänzt nur der Nutzer selbst', 'KM003');
    END IF;
    SELECT * INTO alt FROM kern.nutzer WHERE id = p_nutzer FOR UPDATE;
    IF NOT FOUND THEN PERFORM kern.verweigern('Nutzer unbekannt', 'KM002'); END IF;
    IF alt.email IS NOT NULL THEN
        PERFORM kern.verweigern('Die Angaben sind vollständig; Änderungen nimmt die Verwaltung des Mandanten vor', 'KM003');
    END IF;
    IF nullif(trim(p_vornamen), '') IS NULL OR nullif(trim(p_email), '') IS NULL THEN
        PERFORM kern.verweigern('Vorname(n) und E-Mail-Adresse sind anzugeben', 'KM002');
    END IF;
    UPDATE kern.nutzer SET vornamen = trim(p_vornamen), familienname = trim(p_familienname), email = lower(trim(p_email))
     WHERE id = p_nutzer RETURNING * INTO neu;
    PERFORM kern._protokoll('nutzer.geaendert', p_subjekt, jsonb_build_object('nutzer', p_nutzer::text),
                            kern._nutzer_stand(alt), kern._nutzer_stand(neu));
END $$;

-- Der Nutzer stellt seine Sprache selbst ein; NULL = nicht gesetzt.
CREATE FUNCTION kern.sprache_setzen(p_subjekt text, p_nutzer uuid, p_sprache text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE alt kern.nutzer;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT * INTO alt FROM kern.nutzer WHERE id = p_nutzer FOR UPDATE;
    IF NOT FOUND THEN PERFORM kern.verweigern('Nutzer unbekannt', 'KM002'); END IF;
    IF alt.sprache IS NOT DISTINCT FROM p_sprache THEN RETURN; END IF;
    UPDATE kern.nutzer SET sprache = p_sprache WHERE id = p_nutzer;
    PERFORM kern._protokoll('nutzer.sprache_geaendert', p_subjekt, jsonb_build_object('nutzer', p_nutzer::text),
        jsonb_build_object('sprache', alt.sprache), jsonb_build_object('sprache', p_sprache));
END $$;

-- Setzt eine Einstellung; p_wert NULL löscht sie (zurück zum Standard). Das Protokoll nennt nur den
-- Schlüssel, nicht den Wert (PROFIL.md 4a). Welche Schlüssel und Werte es gibt, prüft die Anwendung.
CREATE FUNCTION kern.einstellung_setzen(p_subjekt text, p_nutzer uuid, p_schluessel text, p_wert jsonb)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE alt jsonb;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    IF NOT EXISTS (SELECT 1 FROM kern.nutzer WHERE id = p_nutzer) THEN
        PERFORM kern.verweigern('Nutzer unbekannt', 'KM002');
    END IF;
    SELECT wert INTO alt FROM kern.nutzer_einstellung WHERE nutzer = p_nutzer AND schluessel = p_schluessel FOR UPDATE;
    IF p_wert IS NOT NULL AND jsonb_typeof(p_wert) = 'null' THEN p_wert := NULL; END IF;
    IF alt IS NOT DISTINCT FROM p_wert THEN RETURN; END IF;
    IF p_wert IS NULL THEN
        DELETE FROM kern.nutzer_einstellung WHERE nutzer = p_nutzer AND schluessel = p_schluessel;
    ELSE
        INSERT INTO kern.nutzer_einstellung (nutzer, schluessel, wert) VALUES (p_nutzer, p_schluessel, p_wert)
            ON CONFLICT (nutzer, schluessel) DO UPDATE SET wert = EXCLUDED.wert, geaendert_am = now();
    END IF;
    PERFORM kern._protokoll('nutzer.einstellung_geaendert', p_subjekt,
        jsonb_build_object('nutzer', p_nutzer::text, 'schluessel', p_schluessel));
END $$;

-- ---------------------------------------------------------------------------
-- Zugang ausstellen und Passkeys
-- ---------------------------------------------------------------------------
-- Der Code (p_code_hash) kommt mit Stufe 2; bis dahin gilt der Link allein.
CREATE FUNCTION kern.registrierung_ausstellen(p_subjekt text, p_nutzer uuid, p_token_hash text,
                                              p_dauer interval DEFAULT interval '15 minutes', p_code_hash text DEFAULT NULL)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    IF p_dauer <= interval '0' OR p_dauer > interval '24 hours' THEN
        PERFORM kern.verweigern('Zugänge gelten höchstens 24 Stunden', 'KM002');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM kern.nutzer WHERE id = p_nutzer AND aktiv) THEN
        PERFORM kern.verweigern('Nutzer unbekannt oder gesperrt', 'KM003');
    END IF;
    INSERT INTO kern.registrierung (token_hash, nutzer, code_hash, ablauf) VALUES (p_token_hash, p_nutzer, p_code_hash, now() + p_dauer);
    PERFORM kern._protokoll('nutzer.zugang_ausgestellt', p_subjekt, jsonb_build_object('nutzer', p_nutzer::text),
        NULL, jsonb_build_object('ablauf', kern.zeitpunkt(now() + p_dauer)));
END $$;

-- Registriert einen Anmelde-Passkey: mit einem gültigen Zugang (p_registrierung_hash) oder durch den
-- angemeldeten Nutzer selbst. Die kryptografische Prüfung macht die Anwendung (F72).
CREATE FUNCTION kern.anmelde_credential_registrieren(p_subjekt text, p_nutzer uuid, p_credential_id text, p_public_key bytea,
                                                     p_alg integer, p_zaehler bigint, p_bezeichnung text,
                                                     p_registrierung_hash text DEFAULT NULL)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE r kern.registrierung;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    IF p_registrierung_hash IS NOT NULL THEN
        SELECT * INTO r FROM kern.registrierung WHERE token_hash = p_registrierung_hash FOR UPDATE;
        IF NOT FOUND OR r.nutzer <> p_nutzer OR r.verwendet_am IS NOT NULL OR r.ungueltig_am IS NOT NULL OR r.ablauf <= now() THEN
            PERFORM kern.verweigern('Registrierungslink ungültig, abgelaufen oder verbraucht', 'KM003');
        END IF;
        UPDATE kern.registrierung SET verwendet_am = now() WHERE token_hash = p_registrierung_hash;
    ELSIF p_subjekt <> 'nutzer:' || p_nutzer THEN
        PERFORM kern.verweigern('Passkeys registriert nur der Nutzer selbst oder ein Registrierungslink', 'KM003');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM kern.nutzer WHERE id = p_nutzer AND aktiv) THEN
        PERFORM kern.verweigern('Nutzer unbekannt oder gesperrt', 'KM003');
    END IF;
    INSERT INTO kern.anmelde_credential (credential_id, nutzer, public_key, alg, zaehler, bezeichnung)
         VALUES (p_credential_id, p_nutzer, p_public_key, p_alg, p_zaehler, p_bezeichnung);
    PERFORM kern._protokoll('nutzer.passkey_registriert', p_subjekt,
        jsonb_build_object('nutzer', p_nutzer::text, 'credential', p_credential_id), NULL,
        jsonb_build_object('alg', p_alg, 'bezeichnung', p_bezeichnung,
                           'public_key_sha256', encode(sha256(p_public_key), 'hex')));
END $$;

CREATE FUNCTION kern.anmelde_credential_widerrufen(p_subjekt text, p_credential_id text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE c kern.anmelde_credential;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    UPDATE kern.anmelde_credential SET widerrufen_am = now()
     WHERE credential_id = p_credential_id AND widerrufen_am IS NULL RETURNING * INTO c;
    IF NOT FOUND THEN PERFORM kern.verweigern('Passkey unbekannt oder bereits widerrufen', 'KM003'); END IF;
    PERFORM kern._protokoll('nutzer.passkey_widerrufen', p_subjekt,
        jsonb_build_object('nutzer', c.nutzer::text, 'credential', p_credential_id));
END $$;

-- Nur für das Überführen des Bestands (U6), nicht für die Anwendung freigegeben: übernimmt einen Passkey,
-- der bisher in der Verwaltungsdatenbank lag, mit Zähler und Datum der Registrierung.
CREATE FUNCTION kern.anmelde_credential_uebernehmen(p_subjekt text, p_nutzer uuid, p_credential_id text, p_public_key bytea,
                                                    p_alg integer, p_zaehler bigint, p_bezeichnung text, p_registriert_am timestamptz)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    INSERT INTO kern.anmelde_credential (credential_id, nutzer, public_key, alg, zaehler, bezeichnung, registriert_am)
         VALUES (p_credential_id, p_nutzer, p_public_key, p_alg, p_zaehler, p_bezeichnung, p_registriert_am);
    PERFORM kern._protokoll('nutzer.passkey_uebernommen', p_subjekt,
        jsonb_build_object('nutzer', p_nutzer::text, 'credential', p_credential_id), NULL,
        jsonb_build_object('alg', p_alg, 'bezeichnung', p_bezeichnung, 'registriert_am', kern.zeitpunkt(p_registriert_am),
                           'public_key_sha256', encode(sha256(p_public_key), 'hex')));
END $$;

-- ---------------------------------------------------------------------------
-- Anmeldung und Sitzungen (technisch, ohne Protokollkette; auch im Lesemodus)
-- ---------------------------------------------------------------------------
-- Nach erfolgreicher Signaturprüfung durch die Anwendung: Zähler nachführen.
-- Führt der Authentifikator einen Zähler (> 0), muss er steigen (F68).
CREATE FUNCTION kern.anmeldung_vermerken(p_credential_id text, p_zaehler bigint)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE c kern.anmelde_credential;
BEGIN
    SELECT * INTO c FROM kern.anmelde_credential WHERE credential_id = p_credential_id FOR UPDATE;
    IF NOT FOUND OR c.widerrufen_am IS NOT NULL THEN
        PERFORM kern.verweigern('Passkey unbekannt oder widerrufen', 'KM003');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM kern.nutzer WHERE id = c.nutzer AND aktiv) THEN
        PERFORM kern.verweigern('Nutzer gesperrt', 'KM003');
    END IF;
    IF (p_zaehler > 0 OR c.zaehler > 0) AND p_zaehler <= c.zaehler THEN
        PERFORM kern.verweigern('Signaturzähler ist nicht gestiegen – möglicherweise geklonter Passkey', 'KM003');
    END IF;
    UPDATE kern.anmelde_credential SET zaehler = p_zaehler WHERE credential_id = p_credential_id;
    UPDATE kern.nutzer SET letzte_anmeldung = now() WHERE id = c.nutzer;
    RETURN c.nutzer;
END $$;

CREATE FUNCTION kern.sitzung_anlegen(p_nutzer uuid, p_hash text, p_hoechstens interval DEFAULT interval '12 hours')
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM kern.nutzer WHERE id = p_nutzer AND aktiv) THEN
        PERFORM kern.verweigern('Nutzer unbekannt oder gesperrt', 'KM003');
    END IF;
    INSERT INTO kern.sitzung (hash, nutzer, ablauf_absolut) VALUES (p_hash, p_nutzer, now() + p_hoechstens);
END $$;

-- Liefert das Subjekt einer gültigen Sitzung und frischt sie auf; sonst NULL.
CREATE FUNCTION kern.sitzung_pruefen(p_hash text, p_leerlauf interval DEFAULT interval '30 minutes')
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE s kern.sitzung;
BEGIN
    SELECT * INTO s FROM kern.sitzung WHERE hash = p_hash FOR UPDATE;
    IF NOT FOUND OR s.beendet_am IS NOT NULL OR s.ablauf_absolut <= now() OR s.zuletzt_am + p_leerlauf <= now() THEN
        RETURN NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM kern.nutzer WHERE id = s.nutzer AND aktiv) THEN
        RETURN NULL;
    END IF;
    UPDATE kern.sitzung SET zuletzt_am = now() WHERE hash = p_hash;
    RETURN 'nutzer:' || s.nutzer;
END $$;

CREATE FUNCTION kern.sitzung_beenden(p_hash text)
RETURNS void LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
    UPDATE kern.sitzung SET beendet_am = now() WHERE hash = p_hash AND beendet_am IS NULL;
$$;

CREATE FUNCTION kern.sitzungen_aufraeumen() RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
    DELETE FROM kern.sitzung WHERE coalesce(beendet_am, ablauf_absolut) < now() - interval '30 days';
    DELETE FROM kern.registrierung WHERE ablauf < now() - interval '30 days';
$$;

-- ---------------------------------------------------------------------------
-- Rechte: Anwendung nur lesen und Funktionen ausführen (F64)
-- ---------------------------------------------------------------------------
REVOKE ALL ON kern.nutzer, kern.anmelde_credential, kern.registrierung, kern.sitzung, kern.nutzer_einstellung
    FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON kern.nutzer, kern.anmelde_credential, kern.registrierung, kern.sitzung, kern.nutzer_einstellung TO kumero_app;
-- Prüfer und Lesezugriffe sehen, wer es gibt, aber keine Schlüssel, Sitzungen oder Zugänge
GRANT SELECT (id, vornamen, familienname, aktiv, angelegt_am) ON kern.nutzer TO kumero_lesen;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA kern FROM PUBLIC;
GRANT EXECUTE ON FUNCTION
    kern.nutzer_anlegen(text, text, text, text, text, text, uuid),
    kern.nutzer_aendern(text, uuid, text, text, text, boolean),
    kern.nutzer_angaben_ergaenzen(text, uuid, text, text, text),
    kern.sprache_setzen(text, uuid, text),
    kern.einstellung_setzen(text, uuid, text, jsonb),
    kern.registrierung_ausstellen(text, uuid, text, interval, text),
    kern.anmelde_credential_registrieren(text, uuid, text, bytea, integer, bigint, text, text),
    kern.anmelde_credential_widerrufen(text, text),
    kern.anmeldung_vermerken(text, bigint),
    kern.sitzung_anlegen(uuid, text, interval),
    kern.sitzung_pruefen(text, interval),
    kern.sitzung_beenden(text),
    kern.sitzungen_aufraeumen()
  TO kumero_app;
