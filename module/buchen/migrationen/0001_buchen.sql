-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul buchen, Migration 0001: Geschäftsvorfälle (Buchungsvorlagen, BUCHUNGSMASKE.md Abschnitt 3, M5)
--
-- Eigene Daten im eigenen Schema (F44). Schreiben nur über Funktionen mit Protokoll
-- über kern.modul_protokoll samt Vorher-/Nachher-Stand (F48, F64).
-- Vorlagen sind Daten, keine Logik: Sie belegen das Formular nur vor. Konten, Buchungsart
-- und Tags stehen als Nummer, Kürzel und Name darin – ohne Verweis in Tabellen des Kerns;
-- ob es sie gibt, prüft der Kern beim Buchen.

CREATE SCHEMA buchen AUTHORIZATION kumero_owner;
REVOKE ALL ON SCHEMA buchen FROM PUBLIC;
GRANT USAGE ON SCHEMA buchen TO kumero_app, kumero_lesen;

CREATE TABLE buchen.vorlage (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    gruppe        text NOT NULL CHECK (length(trim(gruppe)) BETWEEN 1 AND 60),     -- Ast im Baum, z. B. „Zahlungseingang“
    name          text NOT NULL CHECK (length(trim(name)) BETWEEN 1 AND 100),
    konto         text CHECK (konto IS NULL OR length(konto) BETWEEN 1 AND 20),      -- Soll
    gegenkonto    text CHECK (gegenkonto IS NULL OR length(gegenkonto) BETWEEN 1 AND 20),  -- Haben
    buchungsart   text CHECK (buchungsart IS NULL OR length(buchungsart) BETWEEN 1 AND 16),
    text          text CHECK (text IS NULL OR length(text) BETWEEN 1 AND 500),
    tags          text[] NOT NULL DEFAULT '{}',
    reihenfolge   integer NOT NULL DEFAULT 100,
    geaendert_am  timestamptz NOT NULL DEFAULT now(),
    geaendert_von text NOT NULL,
    UNIQUE (gruppe, name)
);

CREATE FUNCTION buchen._stand(v buchen.vorlage) RETURNS jsonb
LANGUAGE sql IMMUTABLE AS $$
    SELECT jsonb_build_object('gruppe', v.gruppe, 'name', v.name, 'konto', v.konto, 'gegenkonto', v.gegenkonto,
                              'buchungsart', v.buchungsart, 'text', v.text, 'tags', to_jsonb(v.tags), 'reihenfolge', v.reihenfolge)
$$;

-- Legt eine Vorlage an (p_id NULL) oder ersetzt sie.
-- p_daten: {gruppe, name, konto?, gegenkonto?, buchungsart?, text?, tags?: [name], reihenfolge?}
CREATE FUNCTION buchen.vorlage_setzen(p_subjekt text, p_id uuid, p_daten jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, buchen, pg_temp AS $$
DECLARE
    alt  buchen.vorlage;
    neu  buchen.vorlage;
    t    text[] := coalesce((SELECT array_agg(x ORDER BY x) FROM jsonb_array_elements_text(coalesce(p_daten->'tags', '[]')) x), '{}');
BEGIN
    IF p_id IS NULL THEN
        INSERT INTO buchen.vorlage (gruppe, name, konto, gegenkonto, buchungsart, text, tags, reihenfolge, geaendert_von)
             VALUES (p_daten->>'gruppe', p_daten->>'name', p_daten->>'konto', p_daten->>'gegenkonto', p_daten->>'buchungsart',
                     p_daten->>'text', t, coalesce((p_daten->>'reihenfolge')::integer, 100), p_subjekt)
          RETURNING * INTO neu;
        PERFORM kern.modul_protokoll(p_subjekt, 'buchen', 'buchen.vorlage_angelegt',
            jsonb_build_object('vorlage', neu.id::text), NULL, buchen._stand(neu));
        RETURN neu.id;
    END IF;
    SELECT * INTO alt FROM buchen.vorlage WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN
        RAISE EXCEPTION USING ERRCODE = 'KM003', MESSAGE = 'Vorlage unbekannt';
    END IF;
    UPDATE buchen.vorlage SET gruppe = p_daten->>'gruppe', name = p_daten->>'name', konto = p_daten->>'konto',
           gegenkonto = p_daten->>'gegenkonto', buchungsart = p_daten->>'buchungsart', text = p_daten->>'text', tags = t,
           reihenfolge = coalesce((p_daten->>'reihenfolge')::integer, 100),
           geaendert_am = clock_timestamp(), geaendert_von = p_subjekt
     WHERE id = p_id RETURNING * INTO neu;
    PERFORM kern.modul_protokoll(p_subjekt, 'buchen', 'buchen.vorlage_geaendert',
        jsonb_build_object('vorlage', p_id::text), buchen._stand(alt), buchen._stand(neu));
    RETURN p_id;
END $$;

CREATE FUNCTION buchen.vorlage_loeschen(p_subjekt text, p_id uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, buchen, pg_temp AS $$
DECLARE alt buchen.vorlage;
BEGIN
    DELETE FROM buchen.vorlage WHERE id = p_id RETURNING * INTO alt;
    IF NOT FOUND THEN
        RAISE EXCEPTION USING ERRCODE = 'KM003', MESSAGE = 'Vorlage unbekannt';
    END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'buchen', 'buchen.vorlage_geloescht',
        jsonb_build_object('vorlage', p_id::text), buchen._stand(alt), NULL);
END $$;

REVOKE ALL ON ALL TABLES IN SCHEMA buchen FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON ALL TABLES IN SCHEMA buchen TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA buchen FROM PUBLIC;
GRANT EXECUTE ON FUNCTION buchen.vorlage_setzen(text, uuid, jsonb), buchen.vorlage_loeschen(text, uuid) TO kumero_app;
