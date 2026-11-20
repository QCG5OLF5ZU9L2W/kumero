-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Mandanten-Datenbank, Migration 0001 (BUECHER.md Abschnitt 9, A2; F123, F126)
--
-- Ein Mandant ist die Klammer über seinen Büchern: wer hier arbeitet und welche Bücher es gibt. Diese Datenbank
-- (`kumero_<mandant>`) hält deshalb nur das Schema `mandant`: Nutzer, Anmelde-Passkeys, Zugänge, Sitzungen,
-- Einstellungen, Administratoren und die Liste der Bücher – dazu eine eigene verkettete Protokolltabelle.
-- Buchungen, Konten, Rollen und Siegel liegen in der Datenbank des jeweiligen Buchs (Schema `kern`).
--
-- Die Tabellen und Funktionen für Nutzer entsprechen denen, die bis 0.7.29 im Kern des Mandanten lagen
-- (Kern-Migrationen 0012 und 0015); nur das Schema heißt jetzt `mandant`. Geschrieben wird nur über Funktionen (F64).
-- Einen Lesemodus gibt es hier nicht: Er gehört zum Buch (F60, F69).

CREATE SCHEMA mandant AUTHORIZATION kumero_owner;
REVOKE ALL ON SCHEMA mandant FROM PUBLIC;
GRANT USAGE ON SCHEMA mandant TO kumero_app, kumero_lesen;
ALTER DEFAULT PRIVILEGES FOR ROLE kumero_owner REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
ALTER DEFAULT PRIVILEGES FOR ROLE kumero_owner IN SCHEMA mandant
    GRANT SELECT ON TABLES TO kumero_app, kumero_lesen;

-- ---------------------------------------------------------------------------
-- Grundfunktionen – gleiche Definition wie im Kern (0001_grundlagen.sql),
-- damit beide Ketten mit demselben Verfahren nachgerechnet werden (F29).
-- ---------------------------------------------------------------------------
CREATE FUNCTION mandant.jcs(wert jsonb) RETURNS text
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
        SELECT '{' || coalesce(string_agg(to_json(e.key)::text || ':' || mandant.jcs(e.value),
                                          ',' ORDER BY e.key COLLATE "C"), '') || '}'
          INTO ergebnis
          FROM jsonb_each(wert) e;
        RETURN ergebnis;
    WHEN 'array' THEN
        SELECT '[' || coalesce(string_agg(mandant.jcs(a.elem), ',' ORDER BY a.nr), '') || ']'
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

CREATE FUNCTION mandant.sha256_hex(daten text) RETURNS text
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
RETURN encode(sha256(convert_to(daten, 'UTF8')), 'hex');

CREATE FUNCTION mandant.kettenhash(vorgaenger text, inhalt jsonb) RETURNS text
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
RETURN mandant.sha256_hex(vorgaenger || mandant.jcs(inhalt));

CREATE FUNCTION mandant.zeitpunkt(ts timestamptz) RETURNS text
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
RETURN to_char(ts AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"');

CREATE FUNCTION mandant.verweigern(meldung text, code text DEFAULT 'KM001') RETURNS void
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION USING ERRCODE = code, MESSAGE = meldung;
END $$;

-- ---------------------------------------------------------------------------
-- Protokollkette des Mandanten
-- ---------------------------------------------------------------------------
CREATE TABLE mandant.kettenkopf (
    id           boolean PRIMARY KEY DEFAULT true CHECK (id),
    letzte_nr    bigint NOT NULL DEFAULT 0,
    letzter_hash text NOT NULL DEFAULT repeat('0', 64)
);
INSERT INTO mandant.kettenkopf DEFAULT VALUES;

CREATE TABLE mandant.protokoll (
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

CREATE FUNCTION mandant.protokoll_inhalt(p mandant.protokoll) RETURNS jsonb
LANGUAGE sql IMMUTABLE AS $$
    SELECT jsonb_build_object('nr', p.nr, 'zeitpunkt', mandant.zeitpunkt(p.zeitpunkt), 'typ', p.typ,
                              'subjekt', p.subjekt, 'ids', p.ids, 'vorher', p.vorher, 'nachher', p.nachher)
$$;

CREATE FUNCTION mandant.tg_protokoll() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE kopf mandant.kettenkopf;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        PERFORM mandant.verweigern('Protokoll ist unveränderlich');
    END IF;
    SELECT * INTO kopf FROM mandant.kettenkopf FOR UPDATE;
    NEW.nr              := kopf.letzte_nr + 1;
    NEW.zeitpunkt       := clock_timestamp();
    NEW.vorgaenger_hash := kopf.letzter_hash;
    NEW.hash            := mandant.kettenhash(kopf.letzter_hash, mandant.protokoll_inhalt(NEW));
    UPDATE mandant.kettenkopf SET letzte_nr = NEW.nr, letzter_hash = NEW.hash;
    RETURN NEW;
END $$;
CREATE TRIGGER verketten BEFORE INSERT OR UPDATE OR DELETE ON mandant.protokoll
    FOR EACH ROW EXECUTE FUNCTION mandant.tg_protokoll();

CREATE FUNCTION mandant.tg_kein_truncate() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    PERFORM mandant.verweigern(format('TRUNCATE auf %s ist nicht erlaubt', TG_TABLE_NAME));
    RETURN NULL;
END $$;
CREATE TRIGGER kein_truncate BEFORE TRUNCATE ON mandant.protokoll
    FOR EACH STATEMENT EXECUTE FUNCTION mandant.tg_kein_truncate();

CREATE FUNCTION mandant._protokoll(p_typ text, p_subjekt text, p_ids jsonb,
                                      p_vorher jsonb DEFAULT NULL, p_nachher jsonb DEFAULT NULL)
RETURNS void LANGUAGE sql AS $$
    INSERT INTO mandant.protokoll (nr, typ, subjekt, ids, vorher, nachher, vorgaenger_hash, hash)
         VALUES (0, p_typ, p_subjekt, coalesce(p_ids, '{}'), p_vorher, p_nachher, '', '');
$$;

CREATE FUNCTION mandant._subjekt(p text) RETURNS text
LANGUAGE plpgsql IMMUTABLE AS $$
BEGIN
    IF p IS NULL OR p !~ '^(nutzer|dienst|system|test):[A-Za-z0-9._-]+$' THEN
        PERFORM mandant.verweigern(format('Subjekt %s hat nicht die Form nutzer:/dienst:/system:', p), 'KM002');
    END IF;
    RETURN p;
END $$;

-- Prüft die Kette vollständig; liefert die erste Abweichung (leer = in Ordnung).
CREATE FUNCTION mandant.kette_pruefen() RETURNS TABLE (nr bigint, fehler text)
LANGUAGE plpgsql STABLE AS $$
DECLARE p mandant.protokoll; vorg text := repeat('0', 64); erwartet bigint := 1;
BEGIN
    FOR p IN SELECT * FROM mandant.protokoll ORDER BY protokoll.nr LOOP
        IF p.nr <> erwartet THEN nr := p.nr; fehler := 'Lücke'; RETURN NEXT; RETURN; END IF;
        IF p.vorgaenger_hash <> vorg OR p.hash <> mandant.kettenhash(vorg, mandant.protokoll_inhalt(p)) THEN
            nr := p.nr; fehler := 'Hash weicht ab'; RETURN NEXT; RETURN;
        END IF;
        vorg := p.hash; erwartet := erwartet + 1;
    END LOOP;
    IF (SELECT letzter_hash FROM mandant.kettenkopf) <> vorg THEN
        nr := erwartet - 1; fehler := 'Kettenkopf weicht ab'; RETURN NEXT;
    END IF;
END $$;

-- ---------------------------------------------------------------------------

CREATE TABLE mandant.nutzer (
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

-- Anmelde-Passkeys (WebAuthn). Getrennt von den Freigabe-Credentials mandant.credential (F38).
CREATE TABLE mandant.anmelde_credential (
    credential_id   text PRIMARY KEY CHECK (credential_id ~ '^[A-Za-z0-9_-]+$' AND length(credential_id) BETWEEN 16 AND 1366),
    nutzer          uuid NOT NULL REFERENCES mandant.nutzer,
    public_key      bytea NOT NULL,
    alg             integer NOT NULL CHECK (alg IN (-7, -8)),     -- ES256, EdDSA (ANWENDUNG.md E3)
    zaehler         bigint NOT NULL DEFAULT 0 CHECK (zaehler >= 0),
    bezeichnung     text,
    registriert_am  timestamptz NOT NULL DEFAULT now(),
    widerrufen_am   timestamptz
);
CREATE INDEX anmelde_credential_nutzer_idx ON mandant.anmelde_credential (nutzer);

-- Einmalige Zugänge zum Einrichten eines Passkeys: Link, ab Stufe 2 zusätzlich ein Code (VERWALTUNG.md Abschnitt 6).
CREATE TABLE mandant.registrierung (
    token_hash    text PRIMARY KEY CHECK (token_hash ~ '^[0-9a-f]{64}$'),
    nutzer        uuid NOT NULL REFERENCES mandant.nutzer,
    code_hash     text CHECK (code_hash IS NULL OR code_hash ~ '^[0-9a-f]{64}$'),
    ablauf        timestamptz NOT NULL,
    fehlversuche  integer NOT NULL DEFAULT 0 CHECK (fehlversuche >= 0),
    verwendet_am  timestamptz,
    ungueltig_am  timestamptz
);
CREATE INDEX registrierung_nutzer_idx ON mandant.registrierung (nutzer);

CREATE TABLE mandant.sitzung (
    hash            text PRIMARY KEY CHECK (hash ~ '^[0-9a-f]{64}$'),
    nutzer          uuid NOT NULL REFERENCES mandant.nutzer,
    angelegt_am     timestamptz NOT NULL DEFAULT now(),
    zuletzt_am      timestamptz NOT NULL DEFAULT now(),
    ablauf_absolut  timestamptz NOT NULL,
    beendet_am      timestamptz
);
CREATE INDEX sitzung_nutzer_idx ON mandant.sitzung (nutzer) WHERE beendet_am IS NULL;

-- Einstellungen des Nutzers (PROFIL.md, F86) – jetzt je Identität im Mandanten.
CREATE TABLE mandant.nutzer_einstellung (
    nutzer       uuid NOT NULL REFERENCES mandant.nutzer,
    schluessel   text NOT NULL CHECK (schluessel ~ '^[a-z][a-z0-9-]{0,31}\.[a-z][a-z0-9_]{0,63}$'),
    wert         jsonb NOT NULL,
    geaendert_am timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (nutzer, schluessel)
);

-- Administratoren des Mandanten (BUECHER.md A10): Sie pflegen Nutzer, Bücher und Zuweisungen und haben in jedem Buch
-- die Rechte des Bausteins „Verwaltung“ – Buchungsdaten sehen sie nicht (F43).
CREATE TABLE mandant.administrator (
    id      uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    nutzer  uuid NOT NULL REFERENCES mandant.nutzer,
    seit    timestamptz NOT NULL DEFAULT clock_timestamp(),
    bis     timestamptz,
    CHECK (bis IS NULL OR bis > seit)
);
CREATE UNIQUE INDEX administrator_laufend_idx ON mandant.administrator (nutzer) WHERE bis IS NULL;

-- Bücher des Mandanten (BUECHER.md A2). Die Datenbank eines Buchs nennt die Verwaltung; hier stehen Name und Art.
CREATE TABLE mandant.buch (
    kennung      text PRIMARY KEY CHECK (kennung ~ '^[a-z][a-z0-9-]{1,19}$'),
    name         text NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 100),
    art          text NOT NULL CHECK (art IN ('euer', 'bilanz')),
    testbuch     boolean NOT NULL DEFAULT false,
    kopie_von    text,
    kopie_stand  timestamptz,
    angelegt_am  timestamptz NOT NULL DEFAULT now(),
    CHECK ((kopie_von IS NULL) = (kopie_stand IS NULL)),
    CHECK (kopie_von IS NULL OR testbuch)
);

-- Zahl der aktiven Administratoren
CREATE FUNCTION mandant._administratoren() RETURNS bigint
LANGUAGE sql STABLE SET search_path = pg_catalog, mandant, pg_temp AS $$
    SELECT count(*) FROM mandant.administrator a JOIN mandant.nutzer n ON n.id = a.nutzer WHERE a.bis IS NULL AND n.aktiv
$$;

-- ---------------------------------------------------------------------------
-- Nutzer
-- ---------------------------------------------------------------------------
CREATE FUNCTION mandant._nutzer_stand(n mandant.nutzer) RETURNS jsonb
LANGUAGE sql IMMUTABLE AS $$
    SELECT jsonb_build_object('vornamen', n.vornamen, 'familienname', n.familienname, 'email', n.email,
                              'sprache', n.sprache, 'aktiv', n.aktiv)
$$;

-- p_id nur beim Überführen des Bestands (U6): dieselbe ID wie bisher, damit Protokoll und Buchungen stimmen.
CREATE FUNCTION mandant.nutzer_anlegen(p_subjekt text, p_familienname text, p_vornamen text, p_email text,
                                    p_sprache text, p_pseudonym text, p_id uuid DEFAULT NULL)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE n mandant.nutzer;
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    INSERT INTO mandant.nutzer (id, vornamen, familienname, email, sprache, pseudonym)
         VALUES (coalesce(p_id, gen_random_uuid()), nullif(trim(p_vornamen), ''), trim(p_familienname),
                 nullif(lower(trim(p_email)), ''), p_sprache, p_pseudonym)
      RETURNING * INTO n;
    PERFORM mandant._protokoll('nutzer.angelegt', p_subjekt, jsonb_build_object('nutzer', n.id::text), NULL, mandant._nutzer_stand(n));
    RETURN n.id;
END $$;

-- Stammdaten ändern, sperren und entsperren. Sperren beendet alle Sitzungen.
CREATE FUNCTION mandant.nutzer_aendern(p_subjekt text, p_nutzer uuid, p_familienname text, p_vornamen text,
                                       p_email text, p_aktiv boolean)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE alt mandant.nutzer; neu mandant.nutzer; a0 bigint;
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    SELECT * INTO alt FROM mandant.nutzer WHERE id = p_nutzer FOR UPDATE;
    IF NOT FOUND THEN PERFORM mandant.verweigern('Nutzer unbekannt', 'KM002'); END IF;
    a0 := mandant._administratoren();
    UPDATE mandant.nutzer SET vornamen = nullif(trim(p_vornamen), ''), familienname = trim(p_familienname),
                              email = nullif(lower(trim(p_email)), ''), aktiv = p_aktiv
     WHERE id = p_nutzer RETURNING * INTO neu;
    IF NOT p_aktiv THEN
        IF a0 > 0 AND mandant._administratoren() = 0 THEN
            PERFORM mandant.verweigern('Der letzte Administrator wird nicht gesperrt – er geht mit dem Mandanten', 'KM003');
        END IF;
        UPDATE mandant.sitzung SET beendet_am = now() WHERE nutzer = p_nutzer AND beendet_am IS NULL;
    END IF;
    IF mandant._nutzer_stand(alt) IS DISTINCT FROM mandant._nutzer_stand(neu) THEN
        PERFORM mandant._protokoll('nutzer.geaendert', p_subjekt, jsonb_build_object('nutzer', p_nutzer::text),
                                   mandant._nutzer_stand(alt), mandant._nutzer_stand(neu));
    END IF;
END $$;

-- Der Nutzer ergänzt seine Angaben selbst, solange die E-Mail-Adresse fehlt (überführter Bestand, U6).
-- Danach ändert die Verwaltung des Mandanten Namen und Kontaktdaten (PROFIL.md U2).
CREATE FUNCTION mandant.nutzer_angaben_ergaenzen(p_subjekt text, p_nutzer uuid, p_familienname text, p_vornamen text, p_email text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE alt mandant.nutzer; neu mandant.nutzer;
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    IF p_subjekt <> 'nutzer:' || p_nutzer THEN
        PERFORM mandant.verweigern('Fehlende Angaben ergänzt nur der Nutzer selbst', 'KM003');
    END IF;
    SELECT * INTO alt FROM mandant.nutzer WHERE id = p_nutzer FOR UPDATE;
    IF NOT FOUND THEN PERFORM mandant.verweigern('Nutzer unbekannt', 'KM002'); END IF;
    IF alt.email IS NOT NULL THEN
        PERFORM mandant.verweigern('Die Angaben sind vollständig; Änderungen nimmt die Verwaltung des Mandanten vor', 'KM003');
    END IF;
    IF nullif(trim(p_vornamen), '') IS NULL OR nullif(trim(p_email), '') IS NULL THEN
        PERFORM mandant.verweigern('Vorname(n) und E-Mail-Adresse sind anzugeben', 'KM002');
    END IF;
    UPDATE mandant.nutzer SET vornamen = trim(p_vornamen), familienname = trim(p_familienname), email = lower(trim(p_email))
     WHERE id = p_nutzer RETURNING * INTO neu;
    PERFORM mandant._protokoll('nutzer.geaendert', p_subjekt, jsonb_build_object('nutzer', p_nutzer::text),
                            mandant._nutzer_stand(alt), mandant._nutzer_stand(neu));
END $$;

-- Der Nutzer stellt seine Sprache selbst ein; NULL = nicht gesetzt.
CREATE FUNCTION mandant.sprache_setzen(p_subjekt text, p_nutzer uuid, p_sprache text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE alt mandant.nutzer;
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    SELECT * INTO alt FROM mandant.nutzer WHERE id = p_nutzer FOR UPDATE;
    IF NOT FOUND THEN PERFORM mandant.verweigern('Nutzer unbekannt', 'KM002'); END IF;
    IF alt.sprache IS NOT DISTINCT FROM p_sprache THEN RETURN; END IF;
    UPDATE mandant.nutzer SET sprache = p_sprache WHERE id = p_nutzer;
    PERFORM mandant._protokoll('nutzer.sprache_geaendert', p_subjekt, jsonb_build_object('nutzer', p_nutzer::text),
        jsonb_build_object('sprache', alt.sprache), jsonb_build_object('sprache', p_sprache));
END $$;

-- Setzt eine Einstellung; p_wert NULL löscht sie (zurück zum Standard). Das Protokoll nennt nur den
-- Schlüssel, nicht den Wert (PROFIL.md 4a). Welche Schlüssel und Werte es gibt, prüft die Anwendung.
CREATE FUNCTION mandant.einstellung_setzen(p_subjekt text, p_nutzer uuid, p_schluessel text, p_wert jsonb)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE alt jsonb;
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    IF NOT EXISTS (SELECT 1 FROM mandant.nutzer WHERE id = p_nutzer) THEN
        PERFORM mandant.verweigern('Nutzer unbekannt', 'KM002');
    END IF;
    SELECT wert INTO alt FROM mandant.nutzer_einstellung WHERE nutzer = p_nutzer AND schluessel = p_schluessel FOR UPDATE;
    IF p_wert IS NOT NULL AND jsonb_typeof(p_wert) = 'null' THEN p_wert := NULL; END IF;
    IF alt IS NOT DISTINCT FROM p_wert THEN RETURN; END IF;
    IF p_wert IS NULL THEN
        DELETE FROM mandant.nutzer_einstellung WHERE nutzer = p_nutzer AND schluessel = p_schluessel;
    ELSE
        INSERT INTO mandant.nutzer_einstellung (nutzer, schluessel, wert) VALUES (p_nutzer, p_schluessel, p_wert)
            ON CONFLICT (nutzer, schluessel) DO UPDATE SET wert = EXCLUDED.wert, geaendert_am = now();
    END IF;
    PERFORM mandant._protokoll('nutzer.einstellung_geaendert', p_subjekt,
        jsonb_build_object('nutzer', p_nutzer::text, 'schluessel', p_schluessel));
END $$;

-- ---------------------------------------------------------------------------
-- Zugang ausstellen und Passkeys
-- ---------------------------------------------------------------------------
-- Der Code (p_code_hash) kommt mit Stufe 2; bis dahin gilt der Link allein.
CREATE FUNCTION mandant.registrierung_ausstellen(p_subjekt text, p_nutzer uuid, p_token_hash text,
                                                         p_dauer interval DEFAULT interval '15 minutes', p_code_hash text DEFAULT NULL)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    IF p_dauer <= interval '0' OR p_dauer > interval '24 hours' THEN
        PERFORM mandant.verweigern('Zugänge gelten höchstens 24 Stunden', 'KM002');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM mandant.nutzer WHERE id = p_nutzer AND aktiv) THEN
        PERFORM mandant.verweigern('Nutzer unbekannt oder gesperrt', 'KM003');
    END IF;
    UPDATE mandant.registrierung SET ungueltig_am = now()
     WHERE nutzer = p_nutzer AND verwendet_am IS NULL AND ungueltig_am IS NULL;
    INSERT INTO mandant.registrierung (token_hash, nutzer, code_hash, ablauf) VALUES (p_token_hash, p_nutzer, p_code_hash, now() + p_dauer);
    PERFORM mandant._protokoll('nutzer.zugang_ausgestellt', p_subjekt, jsonb_build_object('nutzer', p_nutzer::text),
        NULL, jsonb_build_object('ablauf', mandant.zeitpunkt(now() + p_dauer), 'mit_code', p_code_hash IS NOT NULL));
END $$;

-- Registriert einen Anmelde-Passkey: mit einem gültigen Zugang (p_registrierung_hash) oder durch den
-- angemeldeten Nutzer selbst. Die kryptografische Prüfung macht die Anwendung (F72).
CREATE FUNCTION mandant.anmelde_credential_registrieren(p_subjekt text, p_nutzer uuid, p_credential_id text, p_public_key bytea,
                                                     p_alg integer, p_zaehler bigint, p_bezeichnung text,
                                                     p_registrierung_hash text DEFAULT NULL)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE r mandant.registrierung;
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    IF p_registrierung_hash IS NOT NULL THEN
        SELECT * INTO r FROM mandant.registrierung WHERE token_hash = p_registrierung_hash FOR UPDATE;
        IF NOT FOUND OR r.nutzer <> p_nutzer OR r.verwendet_am IS NOT NULL OR r.ungueltig_am IS NOT NULL OR r.ablauf <= now() THEN
            PERFORM mandant.verweigern('Registrierungslink ungültig, abgelaufen oder verbraucht', 'KM003');
        END IF;
        UPDATE mandant.registrierung SET verwendet_am = now() WHERE token_hash = p_registrierung_hash;
    ELSIF p_subjekt <> 'nutzer:' || p_nutzer THEN
        PERFORM mandant.verweigern('Passkeys registriert nur der Nutzer selbst oder ein Registrierungslink', 'KM003');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM mandant.nutzer WHERE id = p_nutzer AND aktiv) THEN
        PERFORM mandant.verweigern('Nutzer unbekannt oder gesperrt', 'KM003');
    END IF;
    INSERT INTO mandant.anmelde_credential (credential_id, nutzer, public_key, alg, zaehler, bezeichnung)
         VALUES (p_credential_id, p_nutzer, p_public_key, p_alg, p_zaehler, p_bezeichnung);
    PERFORM mandant._protokoll('nutzer.passkey_registriert', p_subjekt,
        jsonb_build_object('nutzer', p_nutzer::text, 'credential', p_credential_id), NULL,
        jsonb_build_object('alg', p_alg, 'bezeichnung', p_bezeichnung,
                           'public_key_sha256', encode(sha256(p_public_key), 'hex')));
END $$;

CREATE FUNCTION mandant.anmelde_credential_widerrufen(p_subjekt text, p_credential_id text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE c mandant.anmelde_credential;
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    UPDATE mandant.anmelde_credential SET widerrufen_am = now()
     WHERE credential_id = p_credential_id AND widerrufen_am IS NULL RETURNING * INTO c;
    IF NOT FOUND THEN PERFORM mandant.verweigern('Passkey unbekannt oder bereits widerrufen', 'KM003'); END IF;
    PERFORM mandant._protokoll('nutzer.passkey_widerrufen', p_subjekt,
        jsonb_build_object('nutzer', c.nutzer::text, 'credential', p_credential_id));
END $$;

-- Nur für das Überführen des Bestands (U6), nicht für die Anwendung freigegeben: übernimmt einen Passkey,
-- der bisher in der Verwaltungsdatenbank lag, mit Zähler und Datum der Registrierung.
CREATE FUNCTION mandant.anmelde_credential_uebernehmen(p_subjekt text, p_nutzer uuid, p_credential_id text, p_public_key bytea,
                                                    p_alg integer, p_zaehler bigint, p_bezeichnung text, p_registriert_am timestamptz)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    INSERT INTO mandant.anmelde_credential (credential_id, nutzer, public_key, alg, zaehler, bezeichnung, registriert_am)
         VALUES (p_credential_id, p_nutzer, p_public_key, p_alg, p_zaehler, p_bezeichnung, p_registriert_am);
    PERFORM mandant._protokoll('nutzer.passkey_uebernommen', p_subjekt,
        jsonb_build_object('nutzer', p_nutzer::text, 'credential', p_credential_id), NULL,
        jsonb_build_object('alg', p_alg, 'bezeichnung', p_bezeichnung, 'registriert_am', mandant.zeitpunkt(p_registriert_am),
                           'public_key_sha256', encode(sha256(p_public_key), 'hex')));
END $$;

-- ---------------------------------------------------------------------------
-- Anmeldung und Sitzungen (technisch, ohne Protokollkette; auch im Lesemodus)
-- ---------------------------------------------------------------------------
-- Nach erfolgreicher Signaturprüfung durch die Anwendung: Zähler nachführen.
-- Führt der Authentifikator einen Zähler (> 0), muss er steigen (F68).
CREATE FUNCTION mandant.anmeldung_vermerken(p_credential_id text, p_zaehler bigint)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE c mandant.anmelde_credential;
BEGIN
    SELECT * INTO c FROM mandant.anmelde_credential WHERE credential_id = p_credential_id FOR UPDATE;
    IF NOT FOUND OR c.widerrufen_am IS NOT NULL THEN
        PERFORM mandant.verweigern('Passkey unbekannt oder widerrufen', 'KM003');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM mandant.nutzer WHERE id = c.nutzer AND aktiv) THEN
        PERFORM mandant.verweigern('Nutzer gesperrt', 'KM003');
    END IF;
    IF (p_zaehler > 0 OR c.zaehler > 0) AND p_zaehler <= c.zaehler THEN
        PERFORM mandant.verweigern('Signaturzähler ist nicht gestiegen – möglicherweise geklonter Passkey', 'KM003');
    END IF;
    UPDATE mandant.anmelde_credential SET zaehler = p_zaehler WHERE credential_id = p_credential_id;
    UPDATE mandant.nutzer SET letzte_anmeldung = now() WHERE id = c.nutzer;
    RETURN c.nutzer;
END $$;

CREATE FUNCTION mandant.sitzung_anlegen(p_nutzer uuid, p_hash text, p_hoechstens interval DEFAULT interval '12 hours')
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM mandant.nutzer WHERE id = p_nutzer AND aktiv) THEN
        PERFORM mandant.verweigern('Nutzer unbekannt oder gesperrt', 'KM003');
    END IF;
    INSERT INTO mandant.sitzung (hash, nutzer, ablauf_absolut) VALUES (p_hash, p_nutzer, now() + p_hoechstens);
END $$;

-- Liefert das Subjekt einer gültigen Sitzung und frischt sie auf; sonst NULL.
CREATE FUNCTION mandant.sitzung_pruefen(p_hash text, p_leerlauf interval DEFAULT interval '30 minutes')
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE s mandant.sitzung;
BEGIN
    SELECT * INTO s FROM mandant.sitzung WHERE hash = p_hash FOR UPDATE;
    IF NOT FOUND OR s.beendet_am IS NOT NULL OR s.ablauf_absolut <= now() OR s.zuletzt_am + p_leerlauf <= now() THEN
        RETURN NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM mandant.nutzer WHERE id = s.nutzer AND aktiv) THEN
        RETURN NULL;
    END IF;
    UPDATE mandant.sitzung SET zuletzt_am = now() WHERE hash = p_hash;
    RETURN 'nutzer:' || s.nutzer;
END $$;

CREATE FUNCTION mandant.sitzung_beenden(p_hash text)
RETURNS void LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
    UPDATE mandant.sitzung SET beendet_am = now() WHERE hash = p_hash AND beendet_am IS NULL;
$$;

CREATE FUNCTION mandant.sitzungen_aufraeumen() RETURNS void
LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
    DELETE FROM mandant.sitzung WHERE coalesce(beendet_am, ablauf_absolut) < now() - interval '30 days';
    DELETE FROM mandant.registrierung WHERE ablauf < now() - interval '30 days';
$$;

-- ---------------------------------------------------------------------------
-- Rechte: Anwendung nur lesen und Funktionen ausführen (F64)

CREATE FUNCTION mandant.registrierung_code_pruefen(p_token_hash text, p_code_hash text) RETURNS text
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE r mandant.registrierung;
BEGIN
    SELECT * INTO r FROM mandant.registrierung WHERE token_hash = p_token_hash FOR UPDATE;
    IF NOT FOUND OR r.verwendet_am IS NOT NULL OR r.ungueltig_am IS NOT NULL OR r.ablauf <= now() THEN
        RETURN 'ungueltig';
    END IF;
    IF r.code_hash IS NULL OR r.code_hash = p_code_hash THEN
        RETURN 'ok';
    END IF;
    UPDATE mandant.registrierung
       SET fehlversuche = fehlversuche + 1,
           ungueltig_am = CASE WHEN fehlversuche + 1 >= 5 THEN now() END
     WHERE token_hash = p_token_hash;
    RETURN CASE WHEN r.fehlversuche + 1 >= 5 THEN 'ungueltig' ELSE 'falsch' END;
END $$;

-- ---------------------------------------------------------------------------
-- Administratoren und Bücher
-- ---------------------------------------------------------------------------
CREATE FUNCTION mandant.administrator_einsetzen(p_subjekt text, p_nutzer uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    IF NOT EXISTS (SELECT 1 FROM mandant.nutzer WHERE id = p_nutzer AND aktiv) THEN
        PERFORM mandant.verweigern('Nutzer unbekannt oder gesperrt', 'KM003');
    END IF;
    IF EXISTS (SELECT 1 FROM mandant.administrator WHERE nutzer = p_nutzer AND bis IS NULL) THEN
        PERFORM mandant.verweigern('Der Nutzer ist schon Administrator', 'KM003');
    END IF;
    INSERT INTO mandant.administrator (nutzer) VALUES (p_nutzer);
    PERFORM mandant._protokoll('administrator.eingesetzt', p_subjekt, jsonb_build_object('nutzer', p_nutzer::text));
END $$;

CREATE FUNCTION mandant.administrator_abberufen(p_subjekt text, p_nutzer uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE a0 bigint;
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    a0 := mandant._administratoren();
    UPDATE mandant.administrator SET bis = clock_timestamp() WHERE nutzer = p_nutzer AND bis IS NULL;
    IF NOT FOUND THEN PERFORM mandant.verweigern('Der Nutzer ist kein Administrator', 'KM003'); END IF;
    IF a0 > 0 AND mandant._administratoren() = 0 THEN
        PERFORM mandant.verweigern('Der letzte Administrator wird nicht abberufen – er geht mit dem Mandanten', 'KM003');
    END IF;
    PERFORM mandant._protokoll('administrator.abberufen', p_subjekt, jsonb_build_object('nutzer', p_nutzer::text));
END $$;

CREATE FUNCTION mandant.buch_eintragen(p_subjekt text, p_kennung text, p_name text, p_art text, p_testbuch boolean,
                                       p_kopie_von text DEFAULT NULL, p_kopie_stand timestamptz DEFAULT NULL) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    INSERT INTO mandant.buch (kennung, name, art, testbuch, kopie_von, kopie_stand)
         VALUES (p_kennung, trim(p_name), p_art, p_testbuch, p_kopie_von, p_kopie_stand);
    PERFORM mandant._protokoll('buch.angelegt', p_subjekt, jsonb_build_object('buch', p_kennung), NULL,
        jsonb_build_object('name', trim(p_name), 'art', p_art, 'testbuch', p_testbuch, 'kopie_von', p_kopie_von));
END $$;

CREATE FUNCTION mandant.buch_umbenennen(p_subjekt text, p_kennung text, p_name text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE alt text;
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    SELECT name INTO alt FROM mandant.buch WHERE kennung = p_kennung FOR UPDATE;
    IF NOT FOUND THEN PERFORM mandant.verweigern('Buch unbekannt', 'KM003'); END IF;
    UPDATE mandant.buch SET name = trim(p_name) WHERE kennung = p_kennung;
    IF alt IS DISTINCT FROM trim(p_name) THEN
        PERFORM mandant._protokoll('buch.umbenannt', p_subjekt, jsonb_build_object('buch', p_kennung),
            jsonb_build_object('name', alt), jsonb_build_object('name', trim(p_name)));
    END IF;
END $$;

-- ---------------------------------------------------------------------------
-- Idempotenz der schreibenden Aufrufe auf der Ebene Mandant (F71)
-- ---------------------------------------------------------------------------
CREATE TABLE mandant.idempotenz (
    subjekt         text NOT NULL,
    schluessel      text NOT NULL CHECK (length(schluessel) BETWEEN 8 AND 255),
    endpunkt        text NOT NULL,
    anfrage_hash    text NOT NULL CHECK (anfrage_hash ~ '^[0-9a-f]{64}$'),
    antwort_status  integer CHECK (antwort_status BETWEEN 100 AND 599),
    antwort_inhalt  jsonb,
    erstellt_am     timestamptz NOT NULL DEFAULT now(),
    ablauf          timestamptz NOT NULL,
    PRIMARY KEY (subjekt, schluessel)
);

CREATE FUNCTION mandant.idempotenz_beginnen(p_subjekt text, p_schluessel text, p_endpunkt text, p_anfrage_hash text,
                                         p_dauer interval DEFAULT interval '24 hours')
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
DECLARE i mandant.idempotenz; neu boolean;
BEGIN
    PERFORM mandant._subjekt(p_subjekt);
    IF nullif(current_setting('kumero.idempotenz', true), '') IS NOT NULL THEN
        PERFORM mandant.verweigern('In dieser Transaktion läuft bereits ein idempotenter Aufruf', 'KM003');
    END IF;
    DELETE FROM mandant.idempotenz WHERE subjekt = p_subjekt AND schluessel = p_schluessel AND ablauf <= now();
    -- Ein gleichzeitiger Aufruf mit demselben Schlüssel wartet hier, bis der erste fertig ist.
    INSERT INTO mandant.idempotenz (subjekt, schluessel, endpunkt, anfrage_hash, ablauf)
         VALUES (p_subjekt, p_schluessel, p_endpunkt, p_anfrage_hash, now() + p_dauer)
    ON CONFLICT DO NOTHING
    RETURNING true INTO neu;
    IF neu THEN
        PERFORM set_config('kumero.idempotenz', p_schluessel, true);
        RETURN jsonb_build_object('neu', true);
    END IF;
    SELECT * INTO i FROM mandant.idempotenz WHERE subjekt = p_subjekt AND schluessel = p_schluessel;
    IF i.endpunkt <> p_endpunkt OR i.anfrage_hash <> p_anfrage_hash THEN
        PERFORM mandant.verweigern('Idempotenzschlüssel wurde schon für eine andere Anfrage verwendet (F71)', 'KM006');
    END IF;
    RETURN jsonb_build_object('neu', false, 'status', i.antwort_status, 'inhalt', i.antwort_inhalt);
END $$;

CREATE FUNCTION mandant.idempotenz_abschliessen(p_subjekt text, p_schluessel text, p_status integer, p_inhalt jsonb)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
BEGIN
    IF nullif(current_setting('kumero.idempotenz', true), '') IS DISTINCT FROM p_schluessel THEN
        PERFORM mandant.verweigern('Idempotenzschlüssel gehört nicht zu dieser Transaktion', 'KM003');
    END IF;
    UPDATE mandant.idempotenz SET antwort_status = p_status, antwort_inhalt = p_inhalt
     WHERE subjekt = p_subjekt AND schluessel = p_schluessel AND antwort_status IS NULL;
    IF NOT FOUND THEN PERFORM mandant.verweigern('Idempotenter Aufruf nicht offen', 'KM003'); END IF;
    PERFORM set_config('kumero.idempotenz', '', true);
END $$;

CREATE FUNCTION mandant.idempotenz_aufraeumen() RETURNS integer
LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, mandant, pg_temp AS $$
    WITH weg AS (DELETE FROM mandant.idempotenz WHERE ablauf <= now() RETURNING 1) SELECT count(*)::integer FROM weg
$$;

-- ---------------------------------------------------------------------------
-- Rechte: Anwendung nur lesen und Funktionen ausführen (F64)
-- ---------------------------------------------------------------------------
REVOKE ALL ON ALL TABLES IN SCHEMA mandant FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON ALL TABLES IN SCHEMA mandant TO kumero_app;
GRANT SELECT ON mandant.buch, mandant.protokoll, mandant.kettenkopf, mandant.administrator TO kumero_lesen;
GRANT SELECT (id, vornamen, familienname, aktiv, angelegt_am) ON mandant.nutzer TO kumero_lesen;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA mandant FROM PUBLIC;
GRANT EXECUTE ON FUNCTION
    mandant.jcs(jsonb), mandant.sha256_hex(text), mandant.kettenhash(text, jsonb),
    mandant.zeitpunkt(timestamptz), mandant.protokoll_inhalt(mandant.protokoll), mandant.kette_pruefen()
  TO kumero_app, kumero_lesen;
GRANT EXECUTE ON FUNCTION
    mandant.nutzer_anlegen(text, text, text, text, text, text, uuid),
    mandant.nutzer_aendern(text, uuid, text, text, text, boolean),
    mandant.nutzer_angaben_ergaenzen(text, uuid, text, text, text),
    mandant.sprache_setzen(text, uuid, text),
    mandant.einstellung_setzen(text, uuid, text, jsonb),
    mandant.registrierung_ausstellen(text, uuid, text, interval, text),
    mandant.registrierung_code_pruefen(text, text),
    mandant.anmelde_credential_registrieren(text, uuid, text, bytea, integer, bigint, text, text),
    mandant.anmelde_credential_widerrufen(text, text),
    mandant.anmeldung_vermerken(text, bigint),
    mandant.sitzung_anlegen(uuid, text, interval),
    mandant.sitzung_pruefen(text, interval),
    mandant.sitzung_beenden(text),
    mandant.sitzungen_aufraeumen(),
    mandant.administrator_einsetzen(text, uuid),
    mandant.administrator_abberufen(text, uuid),
    mandant.buch_umbenennen(text, text, text),
    mandant.idempotenz_beginnen(text, text, text, text, interval),
    mandant.idempotenz_abschliessen(text, text, integer, jsonb),
    mandant.idempotenz_aufraeumen()
  TO kumero_app;
-- mandant.buch_eintragen ruft nur der Dienst (kumero_owner): Ein Buch entsteht mit seiner Datenbank.
