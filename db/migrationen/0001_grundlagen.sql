-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Kern, Migration 0001: Grundlagen
-- Schema, Rechte-Grundeinstellungen, Typen, kanonische Form (RFC 8785) und Hash.
-- Läuft als kumero_owner in einer Mandanten-Datenbank (F34).

CREATE SCHEMA kern AUTHORIZATION kumero_owner;
COMMENT ON SCHEMA kern IS 'kumero Buchungskern (F45). Schreiben nur über Funktionen (F64).';

-- Niemand außer dem Eigentümer darf hier etwas anlegen; PUBLIC hat keine Rechte.
REVOKE ALL ON SCHEMA kern FROM PUBLIC;
GRANT USAGE ON SCHEMA kern TO kumero_app, kumero_lesen;

-- Neue Funktionen sind standardmäßig für niemanden ausführbar; Freigaben
-- erfolgen ausdrücklich je Funktion (F64). Das Entziehen muss datenbankweit
-- (ohne IN SCHEMA) erfolgen – schemabezogene Voreinstellungen können nur
-- Rechte hinzufügen.
ALTER DEFAULT PRIVILEGES FOR ROLE kumero_owner
    REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
-- Neue Tabellen sind für Anwendung und Leserolle lesbar, sonst nichts.
ALTER DEFAULT PRIVILEGES FOR ROLE kumero_owner IN SCHEMA kern
    GRANT SELECT ON TABLES TO kumero_app, kumero_lesen;

-- ---------------------------------------------------------------------------
-- Aufzählungstypen
-- ---------------------------------------------------------------------------
CREATE TYPE kern.kontoart          AS ENUM ('aktiv', 'passiv', 'aufwand', 'ertrag');           -- F7
CREATE TYPE kern.seite             AS ENUM ('S', 'H');                                         -- F24
CREATE TYPE kern.buchungsstatus    AS ENUM ('entwurf', 'freigabebereit', 'festgeschrieben');   -- F66
CREATE TYPE kern.gj_status         AS ENUM ('offen', 'gesperrt');                              -- F33
CREATE TYPE kern.betriebsart       AS ENUM ('test', 'produktiv');                              -- F59
CREATE TYPE kern.handlung          AS ENUM ('festschreibung', 'storno', 'gj_sperren',
                                            'gj_oeffnen', 'notfall', 'produktivbetrieb',
                                            'restore_bestaetigung');                           -- F38
CREATE TYPE kern.herkunft          AS ENUM ('manuell', 'api', 'import', 'bank');               -- F26
CREATE TYPE kern.belegart          AS ENUM ('festschreibung', 'nachgereicht');                 -- F27
CREATE TYPE kern.siegelstatus      AS ENUM ('ausstehend', 'signiert', 'gestempelt');           -- F16
CREATE TYPE kern.erledigung        AS ENUM ('ausstehend', 'erledigt', 'fehlgeschlagen');

-- ---------------------------------------------------------------------------
-- Fehler (eigene SQLSTATE-Klasse "KM")
--   KM001  Unveränderlichkeit verletzt
--   KM002  Buchungsregel verletzt (F4, F12, F24)
--   KM003  Zustand erlaubt die Handlung nicht (Status, Geschäftsjahr, Lesemodus)
--   KM004  Kanonische Form: unzulässiger Wert
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- Kanonische Form nach RFC 8785 (JCS), eingeschränkt nach F29:
--   - Objektschlüssel nur ASCII (dann ist die UTF-16-Sortierung = Bytesortierung)
--   - Zahlen nur als Ganzzahlen mit |n| < 2^53; Beträge/Kurse sind Strings
--   - Zeichenketten werden wie in ECMAScript JSON.stringify maskiert;
--     PostgreSQL maskiert identisch (\" \\ \b \f \n \r \t, sonst \u00xx klein).
-- ---------------------------------------------------------------------------
CREATE FUNCTION kern.jcs(wert jsonb) RETURNS text
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
        SELECT '{' || coalesce(string_agg(to_json(e.key)::text || ':' || kern.jcs(e.value),
                                          ',' ORDER BY e.key COLLATE "C"), '') || '}'
          INTO ergebnis
          FROM jsonb_each(wert) e;
        RETURN ergebnis;
    WHEN 'array' THEN
        SELECT '[' || coalesce(string_agg(kern.jcs(a.elem), ',' ORDER BY a.nr), '') || ']'
          INTO ergebnis
          FROM jsonb_array_elements(wert) WITH ORDINALITY AS a(elem, nr);
        RETURN ergebnis;
    WHEN 'string' THEN
        RETURN to_json(wert #>> '{}')::text;
    WHEN 'number' THEN
        ergebnis := wert::text;
        IF ergebnis !~ '^-?(0|[1-9][0-9]*)$' OR abs(ergebnis::numeric) >= 9007199254740992 THEN
            RAISE EXCEPTION USING ERRCODE = 'KM004',
                MESSAGE = format('JCS: nur Ganzzahlen |n| < 2^53 zulässig, gefunden %s (Beträge als String, F29)', ergebnis);
        END IF;
        RETURN ergebnis;
    ELSE -- boolean, null
        RETURN wert::text;
    END CASE;
END
$$;
COMMENT ON FUNCTION kern.jcs(jsonb) IS 'Kanonische JSON-Form nach RFC 8785 für den in F29 erlaubten Wertebereich.';

CREATE FUNCTION kern.sha256_hex(daten text) RETURNS text
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
RETURN encode(sha256(convert_to(daten, 'UTF8')), 'hex');

-- Kettenglied: SHA-256(Vorgänger-Hash ‖ JCS(Inhalt))  (F13, F29)
CREATE FUNCTION kern.kettenhash(vorgaenger text, inhalt jsonb) RETURNS text
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
RETURN kern.sha256_hex(vorgaenger || kern.jcs(inhalt));

-- Beträge und Kurse als kanonischer Dezimal-String (F19, F29):
-- ohne Exponent, ohne führendes '+', Nachkommastellen wie gespeichert.
CREATE FUNCTION kern.dezimal(betrag numeric) RETURNS text
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
RETURN betrag::text;

-- Zeitpunkte als RFC 3339 in UTC mit Mikrosekunden (F29).
CREATE FUNCTION kern.zeitpunkt(ts timestamptz) RETURNS text
LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
RETURN to_char(ts AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"');

-- Gemeinsame Fehlerfunktion für Schutz-Trigger
CREATE FUNCTION kern.verweigern(meldung text, code text DEFAULT 'KM001') RETURNS void
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION USING ERRCODE = code, MESSAGE = meldung;
END
$$;
