-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul kontenrahmen, Migration 0001: Vermerk angewendeter Vorlagen (F75 Nr. 6, KONTENRAHMEN.md Abschnitt 6)
--
-- Eigene Daten im eigenen Schema (F44). Schreiben nur über Funktionen mit
-- Protokoll über kern.modul_protokoll (F48, F64). Die Zuordnung zur Anlage EÜR
-- und der Beitrag zur Jahressperre folgen in Migration 0002 (Modul 0.2.0).

CREATE SCHEMA kontenrahmen AUTHORIZATION kumero_owner;
REVOKE ALL ON SCHEMA kontenrahmen FROM PUBLIC;
GRANT USAGE ON SCHEMA kontenrahmen TO kumero_app, kumero_lesen;

CREATE TABLE kontenrahmen.angewendet (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name            text NOT NULL,
    rahmen          text NOT NULL,
    fassung         text,
    quelle          jsonb NOT NULL CHECK (jsonb_typeof(quelle) = 'object'),
    regelwerk       jsonb NOT NULL CHECK (jsonb_typeof(regelwerk) = 'object'),
    vorlage_sha256  text NOT NULL CHECK (vorlage_sha256 ~ '^[0-9a-f]{64}$'),
    ergebnis        jsonb NOT NULL CHECK (jsonb_typeof(ergebnis) = 'object'),   -- Anzahl angelegt, vorhanden, Konflikte
    angewendet_am   timestamptz NOT NULL DEFAULT now(),
    angewendet_von  text NOT NULL
);

CREATE FUNCTION kontenrahmen.tg_nur_einfuegen() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION USING ERRCODE = 'KM001', MESSAGE = 'Vermerke angewendeter Kontenrahmen sind unveränderlich';
END $$;
CREATE TRIGGER nur_einfuegen BEFORE UPDATE OR DELETE ON kontenrahmen.angewendet
    FOR EACH ROW EXECUTE FUNCTION kontenrahmen.tg_nur_einfuegen();

CREATE FUNCTION kontenrahmen.angewendet_vermerken(p_subjekt text, p_name text, p_rahmen text, p_fassung text,
                                                  p_quelle jsonb, p_regelwerk jsonb, p_vorlage_sha256 text, p_ergebnis jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kontenrahmen, pg_temp AS $$
DECLARE aid uuid;
BEGIN
    INSERT INTO kontenrahmen.angewendet (name, rahmen, fassung, quelle, regelwerk, vorlage_sha256, ergebnis, angewendet_von)
         VALUES (p_name, p_rahmen, p_fassung, p_quelle, p_regelwerk, p_vorlage_sha256, p_ergebnis, p_subjekt)
      RETURNING id INTO aid;
    PERFORM kern.modul_protokoll(p_subjekt, 'kontenrahmen', 'kontenrahmen.angewendet',
        jsonb_build_object('angewendet', aid::text), NULL,
        jsonb_build_object('name', p_name, 'rahmen', p_rahmen, 'fassung', p_fassung, 'quelle', p_quelle,
                           'regelwerk', p_regelwerk, 'vorlage_sha256', p_vorlage_sha256, 'ergebnis', p_ergebnis));
    RETURN aid;
END $$;

REVOKE ALL ON ALL TABLES IN SCHEMA kontenrahmen FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON ALL TABLES IN SCHEMA kontenrahmen TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA kontenrahmen FROM PUBLIC;
GRANT EXECUTE ON FUNCTION kontenrahmen.angewendet_vermerken(text, text, text, text, jsonb, jsonb, text, jsonb) TO kumero_app;
