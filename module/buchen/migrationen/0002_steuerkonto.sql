-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul buchen, Migration 0002: Steuersatz am Steuerkonto (BUCHUNGSMASKE.md Abschnitt 9, F101)
--
-- Ein Konto wird zum Steuerkonto, indem der Mandant ihm einen Steuersatz hinterlegt (z. B. Vorsteuer 19 %).
-- Die Buchungsmaske rechnet damit aus dem Bruttobetrag; der Kern kennt weiterhin keine Steuer (F45).
CREATE TABLE buchen.steuerkonto (
    konto         text PRIMARY KEY CHECK (length(konto) BETWEEN 1 AND 20),
    satz          numeric(5,2) NOT NULL CHECK (satz > 0 AND satz < 100),
    geaendert_am  timestamptz NOT NULL DEFAULT now(),
    geaendert_von text NOT NULL
);

-- Setzt oder löscht (Satz null) die Steuersätze mehrerer Konten; p_saetze: {konto: "19.00" | null}
CREATE FUNCTION buchen.steuerkonten_setzen(p_subjekt text, p_saetze jsonb)
RETURNS integer LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, buchen, pg_temp AS $$
DECLARE
    e record;
    alt numeric(5,2);
    neu numeric(5,2);
    n integer := 0;
BEGIN
    IF jsonb_typeof(p_saetze) <> 'object' THEN
        RAISE EXCEPTION USING ERRCODE = 'KM002', MESSAGE = 'Objekt {konto: satz} erwartet';
    END IF;
    FOR e IN SELECT key AS konto, value AS wert FROM jsonb_each(p_saetze) ORDER BY key LOOP
        SELECT satz INTO alt FROM buchen.steuerkonto WHERE konto = e.konto FOR UPDATE;
        IF NOT FOUND THEN alt := NULL; END IF;
        neu := CASE WHEN jsonb_typeof(e.wert) = 'null' THEN NULL ELSE (e.wert #>> '{}')::numeric(5,2) END;
        CONTINUE WHEN neu IS NOT DISTINCT FROM alt;
        IF neu IS NULL THEN
            DELETE FROM buchen.steuerkonto WHERE konto = e.konto;
        ELSE
            INSERT INTO buchen.steuerkonto (konto, satz, geaendert_von) VALUES (e.konto, neu, p_subjekt)
            ON CONFLICT (konto) DO UPDATE SET satz = EXCLUDED.satz, geaendert_am = clock_timestamp(), geaendert_von = EXCLUDED.geaendert_von;
        END IF;
        PERFORM kern.modul_protokoll(p_subjekt, 'buchen', 'buchen.steuerkonto_geaendert', jsonb_build_object('konto', e.konto),
            CASE WHEN alt IS NULL THEN NULL ELSE jsonb_build_object('satz', alt::text) END,
            CASE WHEN neu IS NULL THEN NULL ELSE jsonb_build_object('satz', neu::text) END);
        n := n + 1;
    END LOOP;
    RETURN n;
END $$;

REVOKE ALL ON buchen.steuerkonto FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON buchen.steuerkonto TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON FUNCTION buchen.steuerkonten_setzen(text, jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION buchen.steuerkonten_setzen(text, jsonb) TO kumero_app;
