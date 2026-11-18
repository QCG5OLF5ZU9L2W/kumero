-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul paperless, Migration 0003: Zuordnung von Paperless-Feldern zum Buchungssatz (PAPERLESS.md Abschnitt 12, F95)
--
-- Je Paperless (Adresse) eine Vorgabe des Mandanten (subjekt = '') und, wer mag, eine eigene Zuordnung des
-- Nutzers, die je Ziel überschreibt (Z3). regeln: {ziel: [quelle, …]}; Quelle: {art: fest|feld|tag, name, wert?}.
CREATE TABLE paperless.zuordnung (
    adresse       text NOT NULL,
    subjekt       text NOT NULL DEFAULT '',
    regeln        jsonb NOT NULL CHECK (jsonb_typeof(regeln) = 'object'),
    geaendert_am  timestamptz NOT NULL DEFAULT now(),
    geaendert_von text NOT NULL,
    PRIMARY KEY (adresse, subjekt)
);

-- Setzt die eigene Zuordnung oder (p_mandant) die Vorgabe des Mandanten; p_regeln NULL löscht sie.
CREATE FUNCTION paperless.zuordnung_setzen(p_subjekt text, p_adresse text, p_mandant boolean, p_regeln jsonb)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, paperless, pg_temp AS $$
DECLARE
    wer text := CASE WHEN p_mandant THEN '' ELSE p_subjekt END;
    alt jsonb;
BEGIN
    SELECT regeln INTO alt FROM paperless.zuordnung WHERE adresse = p_adresse AND subjekt = wer FOR UPDATE;
    IF p_regeln IS NULL THEN
        DELETE FROM paperless.zuordnung WHERE adresse = p_adresse AND subjekt = wer;
    ELSE
        INSERT INTO paperless.zuordnung (adresse, subjekt, regeln, geaendert_von) VALUES (p_adresse, wer, p_regeln, p_subjekt)
        ON CONFLICT (adresse, subjekt) DO UPDATE SET regeln = EXCLUDED.regeln, geaendert_am = clock_timestamp(), geaendert_von = EXCLUDED.geaendert_von;
    END IF;
    PERFORM kern.modul_protokoll(p_subjekt, 'paperless', 'paperless.zuordnung_geaendert',
        jsonb_build_object('adresse', p_adresse, 'geltung', CASE WHEN p_mandant THEN 'mandant' ELSE 'nutzer' END), alt, p_regeln);
END $$;

GRANT SELECT ON paperless.zuordnung TO kumero_app;
REVOKE EXECUTE ON FUNCTION paperless.zuordnung_setzen(text, text, boolean, jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION paperless.zuordnung_setzen(text, text, boolean, jsonb) TO kumero_app;
