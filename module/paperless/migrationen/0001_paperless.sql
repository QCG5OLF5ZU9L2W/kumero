-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul paperless, Migration 0001: Zugang des Nutzers zu seinem Paperless-ngx (PAPERLESS.md, F92)
--
-- Das Token gehört dem Nutzer (L2) und liegt nur verschlüsselt hier: mit einem Schlüssel aus seiner
-- Passphrase (L3a, Argon2id). Für die Dauer einer Sitzung liegt es ein zweites Mal verschlüsselt in
-- paperless.entsperrt – mit einem Schlüssel, den nur der Browser des Nutzers als Cookie hält. Aus der
-- Datenbank allein (Sicherung, Betreiber) ist das Token in keinem Fall zu gewinnen.
-- Schreiben nur über Funktionen mit Protokoll (F48, F64); das Protokoll enthält nie Token oder Chiffren.

CREATE SCHEMA paperless AUTHORIZATION kumero_owner;
REVOKE ALL ON SCHEMA paperless FROM PUBLIC;
GRANT USAGE ON SCHEMA paperless TO kumero_app;

CREATE TABLE paperless.verbindung (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    subjekt       text NOT NULL,
    bezeichnung   text NOT NULL CHECK (length(trim(bezeichnung)) BETWEEN 1 AND 60),
    adresse       text NOT NULL CHECK (adresse ~ '^https?://[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:[0-9]{1,5})?(/[a-z0-9._~/-]*[a-z0-9._~-])?$'),
    tag_offen     text CHECK (length(tag_offen) BETWEEN 1 AND 100),   -- Tag für offene Belege (PROFIL.md U11)
    tag_offen_id  integer,
    token_chiffre bytea NOT NULL,
    token_nonce   bytea NOT NULL,
    kdf_salz      bytea NOT NULL,
    kdf_ops       integer NOT NULL,
    kdf_speicher  bigint NOT NULL,
    angelegt_am   timestamptz NOT NULL DEFAULT now(),
    geaendert_am  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (subjekt, adresse),
    CHECK ((tag_offen IS NULL) = (tag_offen_id IS NULL))
);

-- Für die laufende Sitzung entsperrte Tokens: verschlüsselt mit dem Schlüssel aus dem Cookie des Nutzers
CREATE TABLE paperless.entsperrt (
    verbindung  uuid PRIMARY KEY REFERENCES paperless.verbindung ON DELETE CASCADE,
    sitzung     text NOT NULL,
    chiffre     bytea NOT NULL,
    nonce       bytea NOT NULL,
    bis         timestamptz NOT NULL
);

-- Vorgabe des Mandanten (L11): eine Adresse, die Nutzern vorgeschlagen wird
CREATE TABLE paperless.vorgabe (
    einzig        boolean PRIMARY KEY DEFAULT true CHECK (einzig),
    adresse       text NOT NULL,
    bezeichnung   text NOT NULL,
    geaendert_am  timestamptz NOT NULL DEFAULT now(),
    geaendert_von text NOT NULL
);

-- Belege, deren Tag für offene Belege kumero entfernt hat (L10, B14) – damit es bei Storno wieder gesetzt
-- und der Eintrag im Arbeitsvorrat nicht zurückgezogen wird.
CREATE TABLE paperless.tag_entfernt (
    link          text PRIMARY KEY,
    verbindung    uuid NOT NULL REFERENCES paperless.verbindung ON DELETE CASCADE,
    entfernt_am   timestamptz NOT NULL DEFAULT now()
);

CREATE FUNCTION paperless._fehler(p_text text, p_code text DEFAULT 'KM003') RETURNS void
LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION USING ERRCODE = p_code, MESSAGE = p_text; END $$;

-- Legt eine Verbindung an (p_id NULL) oder ändert sie. Ohne neue Chiffre bleibt das Token, wie es ist.
CREATE FUNCTION paperless.verbindung_setzen(p_subjekt text, p_id uuid, p_bezeichnung text, p_adresse text, p_tag text, p_tag_id integer,
        p_chiffre bytea DEFAULT NULL, p_nonce bytea DEFAULT NULL, p_salz bytea DEFAULT NULL, p_ops integer DEFAULT NULL, p_speicher bigint DEFAULT NULL)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, paperless, pg_temp AS $$
DECLARE v paperless.verbindung;
BEGIN
    IF p_id IS NULL THEN
        IF p_chiffre IS NULL THEN PERFORM paperless._fehler('Ohne Token keine Verbindung', 'KM002'); END IF;
        IF EXISTS (SELECT 1 FROM paperless.verbindung WHERE subjekt = p_subjekt AND adresse = p_adresse) THEN
            PERFORM paperless._fehler('Zu dieser Adresse hast du schon eine Verbindung');
        END IF;
        INSERT INTO paperless.verbindung (subjekt, bezeichnung, adresse, tag_offen, tag_offen_id, token_chiffre, token_nonce, kdf_salz, kdf_ops, kdf_speicher)
             VALUES (p_subjekt, trim(p_bezeichnung), p_adresse, p_tag, p_tag_id, p_chiffre, p_nonce, p_salz, p_ops, p_speicher) RETURNING * INTO v;
        PERFORM kern.modul_protokoll(p_subjekt, 'paperless', 'paperless.verbindung_angelegt', jsonb_build_object('verbindung', v.id::text), NULL,
            jsonb_build_object('adresse', v.adresse, 'bezeichnung', v.bezeichnung, 'tag_offen', v.tag_offen));
        RETURN v.id;
    END IF;
    SELECT * INTO v FROM paperless.verbindung WHERE id = p_id AND subjekt = p_subjekt FOR UPDATE;
    IF NOT FOUND THEN PERFORM paperless._fehler('Verbindung unbekannt'); END IF;
    UPDATE paperless.verbindung SET bezeichnung = trim(p_bezeichnung), tag_offen = p_tag, tag_offen_id = p_tag_id,
           token_chiffre = coalesce(p_chiffre, token_chiffre), token_nonce = coalesce(p_nonce, token_nonce),
           kdf_salz = coalesce(p_salz, kdf_salz), kdf_ops = coalesce(p_ops, kdf_ops), kdf_speicher = coalesce(p_speicher, kdf_speicher),
           geaendert_am = clock_timestamp()
     WHERE id = p_id;
    IF p_chiffre IS NOT NULL THEN DELETE FROM paperless.entsperrt WHERE verbindung = p_id; END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'paperless', 'paperless.verbindung_geaendert', jsonb_build_object('verbindung', p_id::text),
        jsonb_build_object('bezeichnung', v.bezeichnung, 'tag_offen', v.tag_offen),
        jsonb_build_object('bezeichnung', trim(p_bezeichnung), 'tag_offen', p_tag, 'token_ersetzt', p_chiffre IS NOT NULL));
    RETURN p_id;
END $$;

CREATE FUNCTION paperless.verbindung_loeschen(p_subjekt text, p_id uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, paperless, pg_temp AS $$
DECLARE v paperless.verbindung;
BEGIN
    DELETE FROM paperless.verbindung WHERE id = p_id AND subjekt = p_subjekt RETURNING * INTO v;
    IF NOT FOUND THEN PERFORM paperless._fehler('Verbindung unbekannt'); END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'paperless', 'paperless.verbindung_geloescht', jsonb_build_object('verbindung', p_id::text),
        jsonb_build_object('adresse', v.adresse, 'bezeichnung', v.bezeichnung), NULL);
END $$;

-- Sitzungszustand, kein fachliches Ereignis: Entsperren und Sperren stehen nicht im Protokoll.
CREATE FUNCTION paperless.entsperren(p_subjekt text, p_verbindung uuid, p_sitzung text, p_chiffre bytea, p_nonce bytea, p_bis timestamptz)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, paperless, pg_temp AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM paperless.verbindung WHERE id = p_verbindung AND subjekt = p_subjekt) THEN
        PERFORM paperless._fehler('Verbindung unbekannt');
    END IF;
    DELETE FROM paperless.entsperrt WHERE bis < now();
    INSERT INTO paperless.entsperrt (verbindung, sitzung, chiffre, nonce, bis) VALUES (p_verbindung, p_sitzung, p_chiffre, p_nonce, p_bis)
    ON CONFLICT (verbindung) DO UPDATE SET sitzung = EXCLUDED.sitzung, chiffre = EXCLUDED.chiffre, nonce = EXCLUDED.nonce, bis = EXCLUDED.bis;
END $$;

CREATE FUNCTION paperless.sperren(p_subjekt text)
RETURNS void LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, paperless, pg_temp AS $$
    DELETE FROM paperless.entsperrt e USING paperless.verbindung v WHERE v.id = e.verbindung AND v.subjekt = p_subjekt
$$;

CREATE FUNCTION paperless.vorgabe_setzen(p_subjekt text, p_adresse text, p_bezeichnung text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, paperless, pg_temp AS $$
DECLARE alt paperless.vorgabe;
BEGIN
    SELECT * INTO alt FROM paperless.vorgabe FOR UPDATE;
    IF p_adresse IS NULL THEN
        DELETE FROM paperless.vorgabe;
    ELSE
        INSERT INTO paperless.vorgabe (adresse, bezeichnung, geaendert_von) VALUES (p_adresse, trim(p_bezeichnung), p_subjekt)
        ON CONFLICT (einzig) DO UPDATE SET adresse = EXCLUDED.adresse, bezeichnung = EXCLUDED.bezeichnung,
               geaendert_am = clock_timestamp(), geaendert_von = EXCLUDED.geaendert_von;
    END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'paperless', 'paperless.vorgabe_geaendert', '{}'::jsonb,
        CASE WHEN alt.adresse IS NULL THEN NULL ELSE jsonb_build_object('adresse', alt.adresse, 'bezeichnung', alt.bezeichnung) END,
        CASE WHEN p_adresse IS NULL THEN NULL ELSE jsonb_build_object('adresse', p_adresse, 'bezeichnung', trim(p_bezeichnung)) END);
END $$;

-- Merkt, dass kumero das Tag für offene Belege entfernt (p_entfernt) bzw. wieder gesetzt hat.
CREATE FUNCTION paperless.tag_merken(p_subjekt text, p_verbindung uuid, p_link text, p_entfernt boolean)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, paperless, pg_temp AS $$
BEGIN
    IF p_entfernt THEN
        INSERT INTO paperless.tag_entfernt (link, verbindung) VALUES (p_link, p_verbindung) ON CONFLICT DO NOTHING;
    ELSE
        DELETE FROM paperless.tag_entfernt WHERE link = p_link;
    END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'paperless', CASE WHEN p_entfernt THEN 'paperless.tag_entfernt' ELSE 'paperless.tag_gesetzt' END,
        jsonb_build_object('beleg', p_link));
END $$;

REVOKE ALL ON ALL TABLES IN SCHEMA paperless FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON ALL TABLES IN SCHEMA paperless TO kumero_app;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA paperless FROM PUBLIC;
GRANT EXECUTE ON FUNCTION
    paperless.verbindung_setzen(text, uuid, text, text, text, integer, bytea, bytea, bytea, integer, bigint),
    paperless.verbindung_loeschen(text, uuid), paperless.entsperren(text, uuid, text, bytea, bytea, timestamptz),
    paperless.sperren(text), paperless.vorgabe_setzen(text, text, text), paperless.tag_merken(text, uuid, text, boolean)
  TO kumero_app;
