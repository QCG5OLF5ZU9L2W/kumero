-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Kern, Migration 0002: Stammdaten (Datenmodell Gruppe A)

-- Buch: genau eine Zeile je Mandanten-Datenbank (F34)
CREATE TABLE kern.buch (
    eins                boolean PRIMARY KEY DEFAULT true CHECK (eins),
    name                text        NOT NULL CHECK (length(trim(name)) > 0),
    buchwaehrung        char(3)     NOT NULL CHECK (buchwaehrung ~ '^[A-Z]{3}$'),        -- F11
    stempel_intervall   interval    NOT NULL DEFAULT '15 minutes'
                                    CHECK (stempel_intervall >= '1 minute'),             -- F28
    sprache             text        NOT NULL DEFAULT 'de',
    betriebsart         kern.betriebsart NOT NULL DEFAULT 'test',                        -- F59
    produktiv_seit      timestamptz,
    lesemodus           boolean     NOT NULL DEFAULT false,                              -- F60
    lesemodus_grund     text,
    angelegt_am         timestamptz NOT NULL DEFAULT now(),
    CHECK ((betriebsart = 'produktiv') = (produktiv_seit IS NOT NULL)),
    CHECK (NOT lesemodus OR lesemodus_grund IS NOT NULL)
);
COMMENT ON TABLE kern.buch IS 'Genau ein Buch je Datenbank (F34).';

CREATE TABLE kern.konto (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    nr              text NOT NULL UNIQUE CHECK (nr ~ '^[0-9A-Za-z][0-9A-Za-z._-]{0,19}$'),
    bezeichnung     text NOT NULL CHECK (length(trim(bezeichnung)) > 0),
    kontoart        kern.kontoart NOT NULL,                                              -- F7
    aktiv           boolean NOT NULL DEFAULT true,
    angelegt_am     timestamptz NOT NULL DEFAULT now()
);
COMMENT ON TABLE kern.konto IS 'Konten: nur Struktur, keine Kontenrahmen (F2, F5, F7). Nie löschen.';

CREATE TABLE kern.tag (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name            text NOT NULL UNIQUE CHECK (length(trim(name)) > 0),
    herkunft        text NOT NULL DEFAULT 'manuell' CHECK (herkunft IN ('manuell', 'paperless')),
    paperless_id    integer UNIQUE,
    CHECK ((herkunft = 'paperless') = (paperless_id IS NOT NULL))
);

CREATE TABLE kern.nummernkreis (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    kuerzel         text NOT NULL UNIQUE CHECK (kuerzel ~ '^[A-Za-z0-9_-]{1,16}$'),
    bezeichnung     text NOT NULL
);

CREATE TABLE kern.buchungsart (
    id                      uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    kuerzel                 text NOT NULL UNIQUE CHECK (kuerzel ~ '^[A-Za-z0-9_-]{1,16}$'),
    bezeichnung             text NOT NULL,
    standard_nummernkreis   uuid NOT NULL REFERENCES kern.nummernkreis
);

-- Geschäftsjahr mit frei konfigurierbarem Zeitraum (F65), ohne Überschneidung.
CREATE TABLE kern.geschaeftsjahr (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    kennung         text NOT NULL UNIQUE CHECK (length(trim(kennung)) > 0),
    zeitraum        daterange NOT NULL CHECK (NOT isempty(zeitraum)
                                              AND lower_inc(zeitraum) AND NOT upper_inc(zeitraum)
                                              AND NOT lower_inf(zeitraum) AND NOT upper_inf(zeitraum)),
    status          kern.gj_status NOT NULL DEFAULT 'offen',
    angelegt_am     timestamptz NOT NULL DEFAULT now(),
    EXCLUDE USING gist (zeitraum WITH &&)
);
COMMENT ON COLUMN kern.geschaeftsjahr.zeitraum IS 'Halboffen [beginn, ende+1). Lückenlos an Nachbarjahre anschließend (F65).';

-- F65: Geschäftsjahre schließen lückenlos aneinander an.
CREATE FUNCTION kern.tg_gj_lueckenlos() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM (
            SELECT zeitraum, lead(zeitraum) OVER (ORDER BY lower(zeitraum)) AS naechster
              FROM kern.geschaeftsjahr
        ) z
        WHERE naechster IS NOT NULL AND upper(zeitraum) <> lower(naechster)
    ) THEN
        PERFORM kern.verweigern('Geschäftsjahre müssen lückenlos aneinander anschließen (F65)', 'KM002');
    END IF;
    RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER gj_lueckenlos
    AFTER INSERT OR UPDATE OF zeitraum ON kern.geschaeftsjahr
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION kern.tg_gj_lueckenlos();

-- Festlegung eines Nummernkreises je Geschäftsjahr mit Zählerstand (F25, F17)
CREATE TABLE kern.nummernkreis_jahr (
    nummernkreis_id     uuid NOT NULL REFERENCES kern.nummernkreis,
    geschaeftsjahr_id   uuid NOT NULL REFERENCES kern.geschaeftsjahr,
    vorlage             text NOT NULL CHECK (vorlage ~ '\{NR(:[1-9][0-9]?)?\}'),
    zuruecksetzen       boolean NOT NULL,       -- true: Zähler beginnt im Jahr neu; false: fortlaufend
    startwert           bigint  NOT NULL DEFAULT 1 CHECK (startwert >= 1),
    zaehler             bigint  NOT NULL DEFAULT 0 CHECK (zaehler >= 0),
    festgelegt_am       timestamptz NOT NULL DEFAULT now(),
    festgelegt_von      text NOT NULL,
    PRIMARY KEY (nummernkreis_id, geschaeftsjahr_id)
);
COMMENT ON COLUMN kern.nummernkreis_jahr.zaehler IS 'Letzte vergebene Zahl; nur unter Zeilensperre erhöhen (F17, F71).';

-- Registry der Module (F44, F72)
CREATE TABLE kern.modul (
    name                text PRIMARY KEY CHECK (name ~ '^[a-z][a-z0-9-]{0,31}$'),
    version             text NOT NULL,
    aktiv               boolean NOT NULL DEFAULT true,
    erweiterungspunkte  text[] NOT NULL DEFAULT '{}'
        CHECK (erweiterungspunkte <@ ARRAY['vorbereiten_anreichern', 'vorbereiten_pruefen',
                                           'festschreiben_pruefen', 'storno_pruefen']::text[]),
    registriert_am      timestamptz NOT NULL DEFAULT now()
);

-- Erweiterungsattribute (F58)
CREATE TABLE kern.erweiterungsattribut (
    schluessel      text PRIMARY KEY CHECK (schluessel ~ '^[a-z][a-z0-9-]*\.[a-z][a-z0-9_]{0,63}$'),
    modul           text NOT NULL REFERENCES kern.modul,
    ebene           text NOT NULL CHECK (ebene IN ('kopf', 'zeile')),
    datentyp        text NOT NULL CHECK (datentyp IN ('text', 'datum', 'dezimal', 'ganzzahl',
                                                      'wahrheitswert', 'auswahl')),
    auswahlwerte    text[],
    pflicht         boolean NOT NULL DEFAULT false,
    CHECK (split_part(schluessel, '.', 1) = modul),
    CHECK ((datentyp = 'auswahl') = (auswahlwerte IS NOT NULL AND cardinality(auswahlwerte) > 0))
);
