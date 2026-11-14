-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Verwaltungsdatenbank, Migration 0001 (F37, F69; ANWENDUNG.md Abschnitt 5 und 6)
--
-- Läuft als kumero_owner in kumero_verwaltung. Enthält nur Mandantenliste,
-- Nutzer und ihre Anmelde-Passkeys, Zugänge, Dienstkonten, Sitzungen und die
-- Wasserstandsmarken – keine Buchungsdaten und keine Rollen (F37, F63).
-- Schreiben nur über Funktionen; Änderungen an Stammdaten stehen mit
-- Vorher/Nachher in einer eigenen Protokollkette (wie F48).
-- Geheimnisse (Sitzungs-IDs, Tokens, Registrierungslinks) nur als SHA-256.

CREATE SCHEMA verwaltung AUTHORIZATION kumero_owner;
REVOKE ALL ON SCHEMA verwaltung FROM PUBLIC;
GRANT USAGE ON SCHEMA verwaltung TO kumero_app, kumero_lesen;
ALTER DEFAULT PRIVILEGES FOR ROLE kumero_owner REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
ALTER DEFAULT PRIVILEGES FOR ROLE kumero_owner IN SCHEMA verwaltung
    GRANT SELECT ON TABLES TO kumero_app, kumero_lesen;

-- ---------------------------------------------------------------------------
-- Grundfunktionen – gleiche Definition wie im Kern (0001_grundlagen.sql),
-- damit beide Ketten mit demselben Verfahren nachgerechnet werden (F29).
-- ---------------------------------------------------------------------------
CREATE FUNCTION verwaltung.jcs(wert jsonb) RETURNS text
LANGUAGE plpgsql IMMUTABLE STRICT PARALLEL SAFE
AS $$
DECLARE
    ergebnis text;
BEGIN
    CASE jsonb_typeof(wert)
    WHEN 'object' THEN
        IF EXISTS (SELECT 1 FROM jsonb_object_keys(wert) k WHERE k !~ '^[\x20-\x7E]*$') THEN
            RAISE EXCEPTION USING ERRCODE = 'KM004',
                MESSAGE = 'JCS: Objektschlüssel müssen ASCII sein';
        END IF;
        SELECT '{' || coalesce(string_agg(to_json(e.key)::text || ':' || verwaltung.jcs(e.value),
                                          ',' ORDER BY e.key COLLATE "C"), '') || '}'
          INTO ergebnis
          FROM jsonb_each(wert) e;
        RETURN ergebnis;
    WHEN 'array' THEN
        SELECT '[' || coalesce(string_agg(verwaltung.jcs(a.elem), ',' ORDER BY a.nr), '') || ']'
          INTO ergebnis
          FROM jsonb_array_elements(wert) WITH ORDINALITY AS a(elem, nr);
        RETURN ergebnis;
    WHEN 'string' THEN
        RETURN to_json(wert #>> '{}')::text;
    WHEN 'number' THEN
        ergebnis := wert::text;
        IF ergebnis !~ '^-?(0|[1-9][0-9]*)$' OR abs(ergebnis::numeric) >= 9007199254740992 THEN
            RAISE EXCEPTION USING ERRCODE = 'KM004',
                MESSAGE = format('JCS: nur Ganzzahlen |n| < 2^53 zulässig, gefunden %s (F29)', ergebnis);
        END IF;
        RETURN ergebnis;
    ELSE
        RETURN wert::text;
    END CASE;
END
$$;

CREATE FUNCTION verwaltung.sha256_hex(daten text) RETURNS text
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
RETURN encode(sha256(convert_to(daten, 'UTF8')), 'hex');

CREATE FUNCTION verwaltung.kettenhash(vorgaenger text, inhalt jsonb) RETURNS text
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
RETURN verwaltung.sha256_hex(vorgaenger || verwaltung.jcs(inhalt));

CREATE FUNCTION verwaltung.zeitpunkt(ts timestamptz) RETURNS text
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
RETURN to_char(ts AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"');

CREATE FUNCTION verwaltung.verweigern(meldung text, code text DEFAULT 'KM001') RETURNS void
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION USING ERRCODE = code, MESSAGE = meldung;
END $$;

-- ---------------------------------------------------------------------------
-- Protokollkette der Verwaltung
-- ---------------------------------------------------------------------------
CREATE TABLE verwaltung.kettenkopf (
    id           boolean PRIMARY KEY DEFAULT true CHECK (id),
    letzte_nr    bigint NOT NULL DEFAULT 0,
    letzter_hash text NOT NULL DEFAULT repeat('0', 64)
);
INSERT INTO verwaltung.kettenkopf DEFAULT VALUES;

CREATE TABLE verwaltung.protokoll (
    nr              bigint PRIMARY KEY,
    zeitpunkt       timestamptz NOT NULL DEFAULT clock_timestamp(),
    typ             text NOT NULL CHECK (typ ~ '^[a-z][a-z0-9-]*\.[a-z][a-z0-9_]*$'),
    subjekt         text NOT NULL,
    ids             jsonb NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(ids) = 'object'),
    vorher          jsonb,
    nachher         jsonb,
    vorgaenger_hash text NOT NULL,
    hash            text NOT NULL UNIQUE
);

CREATE FUNCTION verwaltung.protokoll_inhalt(p verwaltung.protokoll) RETURNS jsonb
LANGUAGE sql IMMUTABLE AS $$
    SELECT jsonb_build_object('nr', p.nr, 'zeitpunkt', verwaltung.zeitpunkt(p.zeitpunkt), 'typ', p.typ,
                              'subjekt', p.subjekt, 'ids', p.ids, 'vorher', p.vorher, 'nachher', p.nachher)
$$;

CREATE FUNCTION verwaltung.tg_protokoll() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE kopf verwaltung.kettenkopf;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        PERFORM verwaltung.verweigern('Protokoll ist unveränderlich');
    END IF;
    SELECT * INTO kopf FROM verwaltung.kettenkopf FOR UPDATE;
    NEW.nr              := kopf.letzte_nr + 1;
    NEW.zeitpunkt       := clock_timestamp();
    NEW.vorgaenger_hash := kopf.letzter_hash;
    NEW.hash            := verwaltung.kettenhash(kopf.letzter_hash, verwaltung.protokoll_inhalt(NEW));
    UPDATE verwaltung.kettenkopf SET letzte_nr = NEW.nr, letzter_hash = NEW.hash;
    RETURN NEW;
END $$;
CREATE TRIGGER verketten BEFORE INSERT OR UPDATE OR DELETE ON verwaltung.protokoll
    FOR EACH ROW EXECUTE FUNCTION verwaltung.tg_protokoll();

CREATE FUNCTION verwaltung.tg_kein_truncate() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    PERFORM verwaltung.verweigern(format('TRUNCATE auf %s ist nicht erlaubt', TG_TABLE_NAME));
    RETURN NULL;
END $$;
CREATE TRIGGER kein_truncate BEFORE TRUNCATE ON verwaltung.protokoll
    FOR EACH STATEMENT EXECUTE FUNCTION verwaltung.tg_kein_truncate();

CREATE FUNCTION verwaltung._protokoll(p_typ text, p_subjekt text, p_ids jsonb,
                                      p_vorher jsonb DEFAULT NULL, p_nachher jsonb DEFAULT NULL)
RETURNS void LANGUAGE sql AS $$
    INSERT INTO verwaltung.protokoll (nr, typ, subjekt, ids, vorher, nachher, vorgaenger_hash, hash)
         VALUES (0, p_typ, p_subjekt, coalesce(p_ids, '{}'), p_vorher, p_nachher, '', '');
$$;

CREATE FUNCTION verwaltung._subjekt(p text) RETURNS text
LANGUAGE plpgsql IMMUTABLE AS $$
BEGIN
    IF p IS NULL OR p !~ '^(nutzer|dienst|system|test):[A-Za-z0-9._-]+$' THEN
        PERFORM verwaltung.verweigern(format('Subjekt %s hat nicht die Form nutzer:/dienst:/system:', p), 'KM002');
    END IF;
    RETURN p;
END $$;

-- Prüft die Kette vollständig; liefert die erste Abweichung (leer = in Ordnung).
CREATE FUNCTION verwaltung.kette_pruefen() RETURNS TABLE (nr bigint, fehler text)
LANGUAGE plpgsql STABLE AS $$
DECLARE p verwaltung.protokoll; vorg text := repeat('0', 64); erwartet bigint := 1;
BEGIN
    FOR p IN SELECT * FROM verwaltung.protokoll ORDER BY protokoll.nr LOOP
        IF p.nr <> erwartet THEN nr := p.nr; fehler := 'Lücke'; RETURN NEXT; RETURN; END IF;
        IF p.vorgaenger_hash <> vorg OR p.hash <> verwaltung.kettenhash(vorg, verwaltung.protokoll_inhalt(p)) THEN
            nr := p.nr; fehler := 'Hash weicht ab'; RETURN NEXT; RETURN;
        END IF;
        vorg := p.hash; erwartet := erwartet + 1;
    END LOOP;
    IF (SELECT letzter_hash FROM verwaltung.kettenkopf) <> vorg THEN
        nr := erwartet - 1; fehler := 'Kettenkopf weicht ab'; RETURN NEXT;
    END IF;
END $$;

-- ---------------------------------------------------------------------------
-- Tabellen
-- ---------------------------------------------------------------------------
CREATE TABLE verwaltung.mandant (
    kennung         text PRIMARY KEY CHECK (kennung ~ '^[a-z][a-z0-9-]{1,31}$'),   -- Pfad /m/{kennung} (F39)
    datenbank       text NOT NULL UNIQUE CHECK (datenbank ~ '^kumero_[a-z0-9_]{1,40}$'
                                                AND datenbank NOT IN ('kumero_verwaltung', 'kumero_vorlage')),
    name            text NOT NULL CHECK (length(trim(name)) > 0),
    angelegt_am     timestamptz NOT NULL DEFAULT now(),
    stillgelegt_am  timestamptz
);

CREATE TABLE verwaltung.nutzer (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name        text NOT NULL CHECK (length(trim(name)) > 0),
    sprache     text NOT NULL DEFAULT 'de' CHECK (sprache ~ '^[a-z]{2}$'),
    verwalter   boolean NOT NULL DEFAULT false,      -- darf Nutzer, Mandanten und Zugänge pflegen
    aktiv       boolean NOT NULL DEFAULT true,
    angelegt_am timestamptz NOT NULL DEFAULT now()
);

-- Einmalige Registrierungslinks (z. B. für den ersten Nutzer über bin/kumero)
CREATE TABLE verwaltung.registrierung (
    token_hash   text PRIMARY KEY CHECK (token_hash ~ '^[0-9a-f]{64}$'),
    nutzer       uuid NOT NULL REFERENCES verwaltung.nutzer,
    ablauf       timestamptz NOT NULL,
    verwendet_am timestamptz
);

-- Anmelde-Passkeys (WebAuthn). Getrennt von den Freigabe-Credentials im Mandanten (F38, ANWENDUNG.md 5.3)
CREATE TABLE verwaltung.anmelde_credential (
    credential_id   text PRIMARY KEY CHECK (credential_id ~ '^[A-Za-z0-9_-]+$' AND length(credential_id) BETWEEN 16 AND 1366),   -- base64url
    nutzer          uuid NOT NULL REFERENCES verwaltung.nutzer,
    public_key      bytea NOT NULL,           -- COSE-Schlüssel aus der Registrierung
    alg             integer NOT NULL CHECK (alg IN (-7, -8)),   -- ES256, EdDSA (ANWENDUNG.md E3)
    zaehler         bigint NOT NULL DEFAULT 0 CHECK (zaehler >= 0),
    bezeichnung     text,
    registriert_am  timestamptz NOT NULL DEFAULT now(),
    widerrufen_am   timestamptz
);

-- Challenges der Anmeldung: einmalig, kurz gültig
CREATE TABLE verwaltung.anmelde_challenge (
    zufall       text PRIMARY KEY CHECK (zufall ~ '^[A-Za-z0-9_-]{43,}$'),   -- ≥ 32 Byte, base64url
    ablauf       timestamptz NOT NULL,
    verwendet_am timestamptz
);

CREATE TABLE verwaltung.dienstkonto (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name        text NOT NULL CHECK (length(trim(name)) > 0),
    mandant     text NOT NULL REFERENCES verwaltung.mandant,     -- genau ein Mandant (ANWENDUNG.md 5.5)
    aktiv       boolean NOT NULL DEFAULT true,
    angelegt_am timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE verwaltung.token (
    hash            text PRIMARY KEY CHECK (hash ~ '^[0-9a-f]{64}$'),
    dienstkonto     uuid NOT NULL REFERENCES verwaltung.dienstkonto,
    ablauf          timestamptz NOT NULL,
    angelegt_am     timestamptz NOT NULL DEFAULT now(),
    widerrufen_am   timestamptz
);

CREATE TABLE verwaltung.zugang (
    subjekt     text NOT NULL CHECK (subjekt ~ '^(nutzer|dienst):[0-9a-f-]{36}$'),
    mandant     text NOT NULL REFERENCES verwaltung.mandant,
    gewaehrt_am timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (subjekt, mandant)
);

CREATE TABLE verwaltung.sitzung (
    hash            text PRIMARY KEY CHECK (hash ~ '^[0-9a-f]{64}$'),
    subjekt         text NOT NULL,
    angelegt_am     timestamptz NOT NULL DEFAULT now(),
    zuletzt_am      timestamptz NOT NULL DEFAULT now(),
    ablauf_absolut  timestamptz NOT NULL,
    beendet_am      timestamptz
);
CREATE INDEX sitzung_subjekt_idx ON verwaltung.sitzung (subjekt) WHERE beendet_am IS NULL;

-- Wasserstandsmarken je Mandant und Kette (F69)
CREATE TABLE verwaltung.wasserstand (
    mandant     text NOT NULL REFERENCES verwaltung.mandant,
    kette       smallint NOT NULL CHECK (kette IN (1, 2)),
    nr          bigint NOT NULL CHECK (nr >= 0),
    hash        text NOT NULL CHECK (hash ~ '^[0-9a-f]{64}$'),
    gesetzt_am  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (mandant, kette)
);

-- ---------------------------------------------------------------------------
-- Schreibfunktionen mit Protokoll
-- ---------------------------------------------------------------------------
CREATE FUNCTION verwaltung.mandant_eintragen(p_subjekt text, p_kennung text, p_datenbank text, p_name text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    INSERT INTO verwaltung.mandant (kennung, datenbank, name) VALUES (p_kennung, p_datenbank, p_name);
    PERFORM verwaltung._protokoll('mandant.eingetragen', p_subjekt, jsonb_build_object('mandant', p_kennung),
        NULL, jsonb_build_object('datenbank', p_datenbank, 'name', p_name));
END $$;

CREATE FUNCTION verwaltung.mandant_stilllegen(p_subjekt text, p_kennung text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    UPDATE verwaltung.mandant SET stillgelegt_am = now() WHERE kennung = p_kennung AND stillgelegt_am IS NULL;
    IF NOT FOUND THEN PERFORM verwaltung.verweigern('Mandant unbekannt oder bereits stillgelegt', 'KM003'); END IF;
    PERFORM verwaltung._protokoll('mandant.stillgelegt', p_subjekt, jsonb_build_object('mandant', p_kennung));
END $$;

CREATE FUNCTION verwaltung.nutzer_anlegen(p_subjekt text, p_name text, p_sprache text DEFAULT 'de',
                                          p_verwalter boolean DEFAULT false)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE nid uuid;
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    INSERT INTO verwaltung.nutzer (name, sprache, verwalter) VALUES (p_name, p_sprache, p_verwalter) RETURNING id INTO nid;
    PERFORM verwaltung._protokoll('nutzer.angelegt', p_subjekt, jsonb_build_object('nutzer', nid::text),
        NULL, jsonb_build_object('name', p_name, 'sprache', p_sprache, 'verwalter', p_verwalter, 'aktiv', true));
    RETURN nid;
END $$;

CREATE FUNCTION verwaltung.nutzer_aendern(p_subjekt text, p_nutzer uuid, p_name text, p_sprache text,
                                          p_verwalter boolean, p_aktiv boolean)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE alt verwaltung.nutzer;
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    SELECT * INTO alt FROM verwaltung.nutzer WHERE id = p_nutzer FOR UPDATE;
    IF NOT FOUND THEN PERFORM verwaltung.verweigern('Nutzer unbekannt', 'KM002'); END IF;
    UPDATE verwaltung.nutzer SET name = p_name, sprache = p_sprache, verwalter = p_verwalter, aktiv = p_aktiv
     WHERE id = p_nutzer;
    IF NOT p_aktiv THEN       -- Deaktivieren beendet alle Sitzungen
        UPDATE verwaltung.sitzung SET beendet_am = now()
         WHERE subjekt = 'nutzer:' || p_nutzer AND beendet_am IS NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM verwaltung.nutzer WHERE verwalter AND aktiv) THEN
        PERFORM verwaltung.verweigern('Es muss mindestens ein aktiver Verwalter bleiben', 'KM003');
    END IF;
    PERFORM verwaltung._protokoll('nutzer.geaendert', p_subjekt, jsonb_build_object('nutzer', p_nutzer::text),
        jsonb_build_object('name', alt.name, 'sprache', alt.sprache, 'verwalter', alt.verwalter, 'aktiv', alt.aktiv),
        jsonb_build_object('name', p_name, 'sprache', p_sprache, 'verwalter', p_verwalter, 'aktiv', p_aktiv));
END $$;

CREATE FUNCTION verwaltung.registrierung_ausstellen(p_subjekt text, p_nutzer uuid, p_token_hash text,
                                                    p_dauer interval DEFAULT interval '15 minutes')
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    IF p_dauer > interval '24 hours' THEN
        PERFORM verwaltung.verweigern('Registrierungslinks gelten höchstens 24 Stunden', 'KM002');
    END IF;
    INSERT INTO verwaltung.registrierung (token_hash, nutzer, ablauf) VALUES (p_token_hash, p_nutzer, now() + p_dauer);
    PERFORM verwaltung._protokoll('registrierung.ausgestellt', p_subjekt, jsonb_build_object('nutzer', p_nutzer::text),
        NULL, jsonb_build_object('ablauf', verwaltung.zeitpunkt(now() + p_dauer)));
END $$;

-- Registriert einen Anmelde-Passkey. Entweder mit einem gültigen
-- Registrierungslink (p_registrierung_hash) oder durch den angemeldeten
-- Nutzer selbst (p_subjekt = 'nutzer:<p_nutzer>'). Die kryptografische Prüfung
-- der Registrierung macht die Anwendung (F72).
CREATE FUNCTION verwaltung.credential_registrieren(p_subjekt text, p_nutzer uuid, p_credential_id text, p_public_key bytea,
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
    IF NOT EXISTS (SELECT 1 FROM verwaltung.nutzer WHERE id = p_nutzer AND aktiv) THEN
        PERFORM verwaltung.verweigern('Nutzer unbekannt oder inaktiv', 'KM003');
    END IF;
    INSERT INTO verwaltung.anmelde_credential (credential_id, nutzer, public_key, alg, zaehler, bezeichnung)
         VALUES (p_credential_id, p_nutzer, p_public_key, p_alg, p_zaehler, p_bezeichnung);
    PERFORM verwaltung._protokoll('credential.registriert', p_subjekt,
        jsonb_build_object('nutzer', p_nutzer::text, 'credential', p_credential_id), NULL,
        jsonb_build_object('alg', p_alg, 'bezeichnung', p_bezeichnung,
                           'public_key_sha256', encode(sha256(p_public_key), 'hex')));
END $$;

CREATE FUNCTION verwaltung.credential_widerrufen(p_subjekt text, p_credential_id text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE c verwaltung.anmelde_credential;
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    UPDATE verwaltung.anmelde_credential SET widerrufen_am = now()
     WHERE credential_id = p_credential_id AND widerrufen_am IS NULL RETURNING * INTO c;
    IF NOT FOUND THEN PERFORM verwaltung.verweigern('Passkey unbekannt oder bereits widerrufen', 'KM003'); END IF;
    PERFORM verwaltung._protokoll('credential.widerrufen', p_subjekt,
        jsonb_build_object('nutzer', c.nutzer::text, 'credential', p_credential_id));
END $$;

CREATE FUNCTION verwaltung.zugang_gewaehren(p_subjekt text, p_wer text, p_mandant text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    INSERT INTO verwaltung.zugang (subjekt, mandant) VALUES (p_wer, p_mandant);
    PERFORM verwaltung._protokoll('zugang.gewaehrt', p_subjekt, jsonb_build_object('subjekt', p_wer, 'mandant', p_mandant));
END $$;

CREATE FUNCTION verwaltung.zugang_entziehen(p_subjekt text, p_wer text, p_mandant text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    DELETE FROM verwaltung.zugang WHERE subjekt = p_wer AND mandant = p_mandant;
    IF NOT FOUND THEN PERFORM verwaltung.verweigern('Zugang besteht nicht', 'KM003'); END IF;
    PERFORM verwaltung._protokoll('zugang.entzogen', p_subjekt, jsonb_build_object('subjekt', p_wer, 'mandant', p_mandant));
END $$;

CREATE FUNCTION verwaltung.dienstkonto_anlegen(p_subjekt text, p_name text, p_mandant text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE did uuid;
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    INSERT INTO verwaltung.dienstkonto (name, mandant) VALUES (p_name, p_mandant) RETURNING id INTO did;
    INSERT INTO verwaltung.zugang (subjekt, mandant) VALUES ('dienst:' || did, p_mandant);
    PERFORM verwaltung._protokoll('dienstkonto.angelegt', p_subjekt, jsonb_build_object('dienstkonto', did::text),
        NULL, jsonb_build_object('name', p_name, 'mandant', p_mandant));
    RETURN did;
END $$;

CREATE FUNCTION verwaltung.token_ausstellen(p_subjekt text, p_dienstkonto uuid, p_hash text, p_ablauf timestamptz)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    IF p_ablauf <= now() OR p_ablauf > now() + interval '400 days' THEN
        PERFORM verwaltung.verweigern('Token-Ablauf muss in der Zukunft und höchstens 400 Tage entfernt liegen', 'KM002');
    END IF;
    INSERT INTO verwaltung.token (hash, dienstkonto, ablauf) VALUES (p_hash, p_dienstkonto, p_ablauf);
    PERFORM verwaltung._protokoll('token.ausgestellt', p_subjekt,
        jsonb_build_object('dienstkonto', p_dienstkonto::text, 'token', left(p_hash, 12)),
        NULL, jsonb_build_object('ablauf', verwaltung.zeitpunkt(p_ablauf)));
END $$;

CREATE FUNCTION verwaltung.token_widerrufen(p_subjekt text, p_hash text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE t verwaltung.token;
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    UPDATE verwaltung.token SET widerrufen_am = now() WHERE hash = p_hash AND widerrufen_am IS NULL RETURNING * INTO t;
    IF NOT FOUND THEN PERFORM verwaltung.verweigern('Token unbekannt oder bereits widerrufen', 'KM003'); END IF;
    PERFORM verwaltung._protokoll('token.widerrufen', p_subjekt,
        jsonb_build_object('dienstkonto', t.dienstkonto::text, 'token', left(p_hash, 12)));
END $$;

-- ---------------------------------------------------------------------------
-- Anmeldung und Sitzungen (technisch, ohne Protokollkette)
-- ---------------------------------------------------------------------------
CREATE FUNCTION verwaltung.challenge_ausstellen(p_zufall text, p_dauer interval DEFAULT interval '5 minutes')
RETURNS void LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
    DELETE FROM verwaltung.anmelde_challenge WHERE ablauf < now() - interval '1 day';
    INSERT INTO verwaltung.anmelde_challenge (zufall, ablauf) VALUES (p_zufall, now() + p_dauer);
$$;

CREATE FUNCTION verwaltung.challenge_einloesen(p_zufall text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
BEGIN
    UPDATE verwaltung.anmelde_challenge SET verwendet_am = now()
     WHERE zufall = p_zufall AND verwendet_am IS NULL AND ablauf > now();
    IF NOT FOUND THEN PERFORM verwaltung.verweigern('Challenge unbekannt, abgelaufen oder verbraucht', 'KM003'); END IF;
END $$;

-- Nach erfolgreicher Signaturprüfung durch die Anwendung: Zähler nachführen.
-- Führt der Authentifikator einen Zähler (> 0), muss er steigen (F68).
CREATE FUNCTION verwaltung.anmeldung_vermerken(p_credential_id text, p_zaehler bigint)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE c verwaltung.anmelde_credential;
BEGIN
    SELECT * INTO c FROM verwaltung.anmelde_credential WHERE credential_id = p_credential_id FOR UPDATE;
    IF NOT FOUND OR c.widerrufen_am IS NOT NULL THEN
        PERFORM verwaltung.verweigern('Passkey unbekannt oder widerrufen', 'KM003');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM verwaltung.nutzer WHERE id = c.nutzer AND aktiv) THEN
        PERFORM verwaltung.verweigern('Nutzer inaktiv', 'KM003');
    END IF;
    IF (p_zaehler > 0 OR c.zaehler > 0) AND p_zaehler <= c.zaehler THEN
        PERFORM verwaltung.verweigern('Signaturzähler ist nicht gestiegen – möglicherweise geklonter Passkey', 'KM003');
    END IF;
    UPDATE verwaltung.anmelde_credential SET zaehler = p_zaehler WHERE credential_id = p_credential_id;
    RETURN c.nutzer;
END $$;

CREATE FUNCTION verwaltung.sitzung_anlegen(p_subjekt text, p_hash text, p_hoechstens interval DEFAULT interval '12 hours')
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
BEGIN
    PERFORM verwaltung._subjekt(p_subjekt);
    INSERT INTO verwaltung.sitzung (hash, subjekt, ablauf_absolut) VALUES (p_hash, p_subjekt, now() + p_hoechstens);
END $$;

-- Liefert das Subjekt einer gültigen Sitzung und frischt sie auf; sonst NULL.
CREATE FUNCTION verwaltung.sitzung_pruefen(p_hash text, p_leerlauf interval DEFAULT interval '30 minutes')
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE s verwaltung.sitzung;
BEGIN
    SELECT * INTO s FROM verwaltung.sitzung WHERE hash = p_hash FOR UPDATE;
    IF NOT FOUND OR s.beendet_am IS NOT NULL OR s.ablauf_absolut <= now() OR s.zuletzt_am + p_leerlauf <= now() THEN
        RETURN NULL;
    END IF;
    IF s.subjekt LIKE 'nutzer:%' AND NOT EXISTS (
        SELECT 1 FROM verwaltung.nutzer WHERE 'nutzer:' || id = s.subjekt AND aktiv) THEN
        RETURN NULL;
    END IF;
    UPDATE verwaltung.sitzung SET zuletzt_am = now() WHERE hash = p_hash;
    RETURN s.subjekt;
END $$;

CREATE FUNCTION verwaltung.sitzung_beenden(p_hash text)
RETURNS void LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
    UPDATE verwaltung.sitzung SET beendet_am = now() WHERE hash = p_hash AND beendet_am IS NULL;
$$;

CREATE FUNCTION verwaltung.aufraeumen() RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
    DELETE FROM verwaltung.sitzung WHERE coalesce(beendet_am, ablauf_absolut) < now() - interval '30 days';
    DELETE FROM verwaltung.anmelde_challenge WHERE ablauf < now() - interval '1 day';
$$;

-- Gültiges Token → Subjekt und Mandant (nur lesend)
CREATE FUNCTION verwaltung.token_pruefen(p_hash text)
RETURNS TABLE (subjekt text, mandant text)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
    SELECT 'dienst:' || d.id, d.mandant
      FROM verwaltung.token t
      JOIN verwaltung.dienstkonto d ON d.id = t.dienstkonto
      JOIN verwaltung.mandant m ON m.kennung = d.mandant
     WHERE t.hash = p_hash AND t.widerrufen_am IS NULL AND t.ablauf > now()
       AND d.aktiv AND m.stillgelegt_am IS NULL
$$;

-- Wasserstand (F69): nur vorwärts; dasselbe Glied muss denselben Hash behalten.
CREATE FUNCTION verwaltung.wasserstand_setzen(p_mandant text, p_kette smallint, p_nr bigint, p_hash text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE w verwaltung.wasserstand;
BEGIN
    SELECT * INTO w FROM verwaltung.wasserstand WHERE mandant = p_mandant AND kette = p_kette FOR UPDATE;
    IF FOUND THEN
        IF p_nr < w.nr OR (p_nr = w.nr AND p_hash <> w.hash) THEN
            PERFORM verwaltung.verweigern(format('Wasserstand %s/%s darf nicht zurückgehen (F69)', p_mandant, p_kette), 'KM003');
        END IF;
        UPDATE verwaltung.wasserstand SET nr = p_nr, hash = p_hash, gesetzt_am = now()
         WHERE mandant = p_mandant AND kette = p_kette;
    ELSE
        INSERT INTO verwaltung.wasserstand (mandant, kette, nr, hash) VALUES (p_mandant, p_kette, p_nr, p_hash);
    END IF;
END $$;

-- ---------------------------------------------------------------------------
-- Rechte: Anwendung nur lesen und Funktionen ausführen (F64)
-- ---------------------------------------------------------------------------
REVOKE INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER
    ON ALL TABLES IN SCHEMA verwaltung FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON ALL TABLES IN SCHEMA verwaltung TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA verwaltung FROM PUBLIC;
GRANT EXECUTE ON FUNCTION
    verwaltung.mandant_eintragen(text, text, text, text),
    verwaltung.mandant_stilllegen(text, text),
    verwaltung.nutzer_anlegen(text, text, text, boolean),
    verwaltung.nutzer_aendern(text, uuid, text, text, boolean, boolean),
    verwaltung.registrierung_ausstellen(text, uuid, text, interval),
    verwaltung.credential_registrieren(text, uuid, text, bytea, integer, bigint, text, text),
    verwaltung.credential_widerrufen(text, text),
    verwaltung.zugang_gewaehren(text, text, text),
    verwaltung.zugang_entziehen(text, text, text),
    verwaltung.dienstkonto_anlegen(text, text, text),
    verwaltung.token_ausstellen(text, uuid, text, timestamptz),
    verwaltung.token_widerrufen(text, text),
    verwaltung.challenge_ausstellen(text, interval),
    verwaltung.challenge_einloesen(text),
    verwaltung.anmeldung_vermerken(text, bigint),
    verwaltung.sitzung_anlegen(text, text, interval),
    verwaltung.sitzung_pruefen(text, interval),
    verwaltung.sitzung_beenden(text),
    verwaltung.aufraeumen(),
    verwaltung.token_pruefen(text),
    verwaltung.wasserstand_setzen(text, smallint, bigint, text)
  TO kumero_app;
GRANT EXECUTE ON FUNCTION
    verwaltung.jcs(jsonb), verwaltung.sha256_hex(text), verwaltung.kettenhash(text, jsonb),
    verwaltung.zeitpunkt(timestamptz), verwaltung.protokoll_inhalt(verwaltung.protokoll),
    verwaltung.kette_pruefen()
  TO kumero_app, kumero_lesen;
