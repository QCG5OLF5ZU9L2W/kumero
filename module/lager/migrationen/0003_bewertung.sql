-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul lager, Migration 0003: Bewertung und Bestandsführung im Buch (LAGER.md Stufe 3, F133)
--
-- Das Lager führt Mengen, das Buch führt Werte (WW11–WW13): Der Wert des Bestands wird aus den Einstandspreisen der
-- Zugänge gerechnet und ist zunächst nachrichtlich. Führt das Buch Bestand, legt die Seite zum Stichtag einen
-- Buchungsentwurf für die Bestandsveränderung an – über die REST-Schnittstelle des Kerns, nie von hier (F31).
-- Welche Buchung das war, vermerkt das Modul.

ALTER TABLE lager.einstellung
    ADD COLUMN bewertung      text NOT NULL DEFAULT 'letzter' CHECK (bewertung IN ('letzter', 'durchschnitt')),       -- WW13
    ADD COLUMN buchfuehrung   text NOT NULL DEFAULT 'aufwand' CHECK (buchfuehrung IN ('aufwand', 'bestand')),         -- WW11
    ADD COLUMN preise         text NOT NULL DEFAULT 'netto' CHECK (preise IN ('netto', 'brutto')),                    -- folgt dem Vorsteuerabzug des Buchs
    ADD COLUMN vorratskonten  text NOT NULL DEFAULT '' CHECK (length(vorratskonten) <= 500),                          -- z. B. „3970-3989“
    ADD COLUMN bestandskonto  text CHECK (bestandskonto IS NULL OR length(bestandskonto) BETWEEN 1 AND 20),
    ADD COLUMN aufwandskonto  text CHECK (aufwandskonto IS NULL OR length(aufwandskonto) BETWEEN 1 AND 20);

CREATE TABLE lager.abschluss (
    stichtag      date NOT NULL,
    buchung       uuid NOT NULL,                                                     -- kein Verweis in den Kern (F44)
    betrag        numeric(15,2) NOT NULL CHECK (betrag > 0),
    richtung      text NOT NULL CHECK (richtung IN ('erhoehung', 'minderung')),
    vermerkt_am   timestamptz NOT NULL DEFAULT clock_timestamp(),
    vermerkt_von  text NOT NULL,
    PRIMARY KEY (stichtag, buchung)
);

DROP FUNCTION lager.einstellung_setzen(text, integer, jsonb);

-- Einstellungen als Ganzes: p trägt alle Felder.
CREATE FUNCTION lager.einstellung_setzen(p_subjekt text, p jsonb)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE alt jsonb; neu jsonb; z jsonb := p->'zwecke';
BEGIN
    IF jsonb_typeof(z) <> 'array' OR jsonb_array_length(z) NOT BETWEEN 1 AND 20
       OR EXISTS (SELECT 1 FROM jsonb_array_elements(z) x WHERE jsonb_typeof(x) <> 'string' OR length(trim(x #>> '{}')) NOT BETWEEN 1 AND 40) THEN
        PERFORM lager._fehler('Zwecke: 1 bis 20 Bezeichnungen mit je höchstens 40 Zeichen erwartet');
    END IF;
    IF p->>'buchfuehrung' = 'bestand' AND (coalesce(p->>'bestandskonto', '') = '' OR coalesce(p->>'aufwandskonto', '') = '') THEN
        PERFORM lager._fehler('Bestandsführung braucht ein Bestandskonto und ein Aufwandskonto');
    END IF;
    SELECT to_jsonb(e) - 'einzig' - 'geaendert_am' - 'geaendert_von' INTO alt FROM lager.einstellung e;
    UPDATE lager.einstellung SET warn_tage = (p->>'warn_tage')::integer, zwecke = z, bewertung = p->>'bewertung', buchfuehrung = p->>'buchfuehrung', preise = p->>'preise',
           vorratskonten = coalesce(trim(p->>'vorratskonten'), ''), bestandskonto = nullif(trim(coalesce(p->>'bestandskonto', '')), ''),
           aufwandskonto = nullif(trim(coalesce(p->>'aufwandskonto', '')), ''), geaendert_am = clock_timestamp(), geaendert_von = p_subjekt;
    SELECT to_jsonb(e) - 'einzig' - 'geaendert_am' - 'geaendert_von' INTO neu FROM lager.einstellung e;
    IF alt IS DISTINCT FROM neu THEN
        PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.einstellung_geaendert', '{}'::jsonb, alt, neu);
    END IF;
END $$;

CREATE FUNCTION lager.abschluss_vermerken(p_subjekt text, p_stichtag date, p_buchung uuid, p_betrag numeric, p_richtung text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
BEGIN
    INSERT INTO lager.abschluss (stichtag, buchung, betrag, richtung, vermerkt_von) VALUES (p_stichtag, p_buchung, p_betrag, p_richtung, p_subjekt)
        ON CONFLICT (stichtag, buchung) DO NOTHING;
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.abschluss_vermerkt', jsonb_build_object('buchung', p_buchung::text), NULL,
        jsonb_build_object('stichtag', p_stichtag::text, 'betrag', p_betrag::text, 'richtung', p_richtung));
END $$;

-- Eine Buchung, die es im Kern nicht mehr gibt (gelöschter Entwurf), trägt nichts mehr.
CREATE FUNCTION lager.abschluss_entfernen(p_subjekt text, p_stichtag date, p_buchung uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
BEGIN
    DELETE FROM lager.abschluss WHERE stichtag = p_stichtag AND buchung = p_buchung;
    IF FOUND THEN
        PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.abschluss_entfernt', jsonb_build_object('buchung', p_buchung::text), NULL, jsonb_build_object('stichtag', p_stichtag::text));
    END IF;
END $$;

REVOKE ALL ON lager.abschluss FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON lager.abschluss TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA lager FROM PUBLIC;
GRANT EXECUTE ON FUNCTION lager.einstellung_setzen(text, jsonb), lager.abschluss_vermerken(text, date, uuid, numeric, text), lager.abschluss_entfernen(text, date, uuid) TO kumero_app;
