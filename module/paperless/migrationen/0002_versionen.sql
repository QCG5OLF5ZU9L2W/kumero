-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul paperless, Migration 0002: Version von Paperless und seiner API je Verbindung (F94)
--
-- kumero 0.7.4 verlangte fest die API-Version 5; Paperless-ngx 3.x bedient nur 9 und 10 und antwortete
-- mit HTTP 406. Jetzt wird je Verbindung mitgeführt, was Paperless meldet und was kumero verwendet.
ALTER TABLE paperless.verbindung
    ADD COLUMN paperless_version text CHECK (paperless_version ~ '^[0-9A-Za-z.+-]{1,30}$'),
    ADD COLUMN api_gemeldet      integer CHECK (api_gemeldet > 0),
    ADD COLUMN api_verwendet     integer CHECK (api_verwendet > 0),
    ADD COLUMN festgestellt_am   timestamptz;

-- Merkt die festgestellten Versionen; ins Protokoll kommt nur ein Wechsel.
CREATE FUNCTION paperless.version_merken(p_subjekt text, p_verbindung uuid, p_version text, p_api_gemeldet integer, p_api_verwendet integer)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, paperless, pg_temp AS $$
DECLARE v paperless.verbindung;
BEGIN
    SELECT * INTO v FROM paperless.verbindung WHERE id = p_verbindung AND subjekt = p_subjekt FOR UPDATE;
    IF NOT FOUND THEN RETURN; END IF;
    UPDATE paperless.verbindung SET paperless_version = p_version, api_gemeldet = p_api_gemeldet, api_verwendet = p_api_verwendet,
           festgestellt_am = clock_timestamp() WHERE id = p_verbindung;
    IF v.paperless_version IS DISTINCT FROM p_version OR v.api_gemeldet IS DISTINCT FROM p_api_gemeldet OR v.api_verwendet IS DISTINCT FROM p_api_verwendet THEN
        PERFORM kern.modul_protokoll(p_subjekt, 'paperless', 'paperless.version_festgestellt', jsonb_build_object('verbindung', p_verbindung::text),
            CASE WHEN v.festgestellt_am IS NULL THEN NULL ELSE jsonb_build_object('paperless', v.paperless_version, 'api_gemeldet', v.api_gemeldet, 'api_verwendet', v.api_verwendet) END,
            jsonb_build_object('paperless', p_version, 'api_gemeldet', p_api_gemeldet, 'api_verwendet', p_api_verwendet));
    END IF;
END $$;
REVOKE EXECUTE ON FUNCTION paperless.version_merken(text, uuid, text, integer, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION paperless.version_merken(text, uuid, text, integer, integer) TO kumero_app;
