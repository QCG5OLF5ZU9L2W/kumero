-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Kern, Migration 0003: Freigabe und Buchungen (Datenmodell Gruppen B und C)

-- ---------------------------------------------------------------------------
-- C. Freigabe (F38, F67, F68)
-- ---------------------------------------------------------------------------
CREATE TABLE kern.credential (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    subjekt         text  NOT NULL,
    credential_id   bytea NOT NULL UNIQUE,
    public_key      bytea NOT NULL,                 -- COSE-Schlüssel
    algorithmus     integer NOT NULL,               -- COSE-Algorithmus (z. B. -7 ES256, -8 EdDSA)
    bezeichnung     text  NOT NULL,
    sign_count      bigint NOT NULL DEFAULT 0 CHECK (sign_count >= 0),
    registriert_am  timestamptz NOT NULL DEFAULT now(),
    widerrufen_am   timestamptz,
    CHECK (widerrufen_am IS NULL OR widerrufen_am >= registriert_am)
);

CREATE TABLE kern.challenge (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    zufall          bytea NOT NULL CHECK (length(zufall) >= 32),
    ablauf          timestamptz NOT NULL,
    mandant         text  NOT NULL,
    art             kern.handlung NOT NULL,
    subjekt         text  NOT NULL,
    inhalt_hash     text  NOT NULL CHECK (inhalt_hash ~ '^[0-9a-f]{64}$'),
    wert            text  NOT NULL UNIQUE CHECK (wert ~ '^[0-9a-f]{64}$'),
    erstellt_am     timestamptz NOT NULL DEFAULT now(),
    verbraucht_am   timestamptz,
    CHECK (ablauf > erstellt_am)
);
COMMENT ON COLUMN kern.challenge.wert IS 'SHA-256 über JCS {ablauf, art, inhalt_hash, mandant, subjekt, zufall} (F68).';

CREATE TABLE kern.freigabe (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    art                 kern.handlung NOT NULL,
    subjekt             text  NOT NULL,
    credential          uuid  NOT NULL REFERENCES kern.credential,
    challenge           uuid  NOT NULL UNIQUE REFERENCES kern.challenge,
    inhalt_hash         text  NOT NULL CHECK (inhalt_hash ~ '^[0-9a-f]{64}$'),
    authenticator_data  bytea NOT NULL,
    client_data_json    bytea NOT NULL,
    signatur            bytea NOT NULL,
    sign_count          bigint NOT NULL CHECK (sign_count >= 0),
    pruefergebnis       jsonb NOT NULL,             -- Ergebnis der Anwendung (F68, F72)
    zeitpunkt           timestamptz NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------------
-- B. Buchungen
-- ---------------------------------------------------------------------------
CREATE TABLE kern.buchung (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    status              kern.buchungsstatus NOT NULL DEFAULT 'entwurf',
    buchungsart         uuid REFERENCES kern.buchungsart,
    nummernkreis        uuid REFERENCES kern.nummernkreis,
    belegdatum          date,
    buchungsdatum       date,
    belegnummer         text CHECK (belegnummer IS NULL OR length(belegnummer) <= 36),
    text                text CHECK (text IS NULL OR length(text) <= 500),
    herkunft            kern.herkunft NOT NULL DEFAULT 'manuell',
    herkunft_ref        text,
    storno_von          uuid UNIQUE REFERENCES kern.buchung,
    erfasst_am          timestamptz NOT NULL DEFAULT now(),
    erfasst_von         text NOT NULL,
    -- ab "freigabebereit" (F66)
    vorbereitet_am      timestamptz,
    vorbereitet_von     text,
    freigabe_hash       text CHECK (freigabe_hash ~ '^[0-9a-f]{64}$'),
    -- ab "festgeschrieben"
    geschaeftsjahr      uuid REFERENCES kern.geschaeftsjahr,
    journal_nr          bigint UNIQUE CHECK (journal_nr >= 1),
    nr_zahl             bigint CHECK (nr_zahl >= 1),
    nr                  text UNIQUE,
    festgeschrieben_am  timestamptz,
    festgeschrieben_von text,
    freigabe            uuid REFERENCES kern.freigabe,
    testbetrieb         boolean NOT NULL DEFAULT false,        -- ohne Signatur im Testbetrieb (F59)
    vorgaenger_hash     text CHECK (vorgaenger_hash ~ '^[0-9a-f]{64}$'),
    hash                text UNIQUE CHECK (hash ~ '^[0-9a-f]{64}$'),

    CHECK (storno_von IS NULL OR storno_von <> id),
    -- Pflichtangaben je Status
    CONSTRAINT buchung_freigabebereit CHECK (
        status = 'entwurf' OR (vorbereitet_am IS NOT NULL AND vorbereitet_von IS NOT NULL
                               AND freigabe_hash IS NOT NULL)),
    CONSTRAINT buchung_entwurf_ohne_vorbereitung CHECK (
        status <> 'entwurf' OR (vorbereitet_am IS NULL AND vorbereitet_von IS NULL
                                AND freigabe_hash IS NULL)),
    CONSTRAINT buchung_festgeschrieben CHECK (
        (status = 'festgeschrieben') = (journal_nr IS NOT NULL)),
    CONSTRAINT buchung_festgeschrieben_vollstaendig CHECK (
        status <> 'festgeschrieben' OR (
            buchungsart IS NOT NULL AND nummernkreis IS NOT NULL
            AND belegdatum IS NOT NULL AND buchungsdatum IS NOT NULL
            AND text IS NOT NULL AND length(trim(text)) > 0
            AND geschaeftsjahr IS NOT NULL AND nr_zahl IS NOT NULL AND nr IS NOT NULL
            AND festgeschrieben_am IS NOT NULL AND festgeschrieben_von IS NOT NULL
            AND vorgaenger_hash IS NOT NULL AND hash IS NOT NULL
            AND (freigabe IS NOT NULL OR testbetrieb))),
    CONSTRAINT buchung_ohne_nummern_vor_festschreibung CHECK (
        status = 'festgeschrieben' OR (
            geschaeftsjahr IS NULL AND nr_zahl IS NULL AND nr IS NULL
            AND festgeschrieben_am IS NULL AND festgeschrieben_von IS NULL
            AND freigabe IS NULL AND vorgaenger_hash IS NULL AND hash IS NULL
            AND NOT testbetrieb)),
    UNIQUE (nummernkreis, geschaeftsjahr, nr_zahl)
);
CREATE INDEX buchung_status_idx        ON kern.buchung (status) WHERE status <> 'festgeschrieben';
CREATE INDEX buchung_buchungsdatum_idx ON kern.buchung (buchungsdatum);

CREATE TABLE kern.buchungszeile (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    buchung         uuid NOT NULL REFERENCES kern.buchung ON DELETE CASCADE,
    pos             integer NOT NULL CHECK (pos >= 1),
    konto           uuid REFERENCES kern.konto,
    seite           kern.seite,
    betrag          numeric(15,2) CHECK (betrag > 0),                      -- F24: immer positiv
    -- Fremdwährung (F12), Mengennotierung: betrag = round(fw_betrag / kurs, 2)
    fw_betrag       numeric(18,2) CHECK (fw_betrag > 0),
    fw_waehrung     char(3) CHECK (fw_waehrung ~ '^[A-Z]{3}$'),
    kurs            numeric(24,10) CHECK (kurs > 0),
    kurs_datum      date,
    kurs_art        text CHECK (kurs_art IN ('tageskurs', 'bmf_monat', 'ezb', 'manuell')),
    UNIQUE (buchung, pos),
    CONSTRAINT zeile_fw_vollstaendig CHECK (
        (fw_betrag IS NULL AND fw_waehrung IS NULL AND kurs IS NULL AND kurs_datum IS NULL AND kurs_art IS NULL)
        OR (fw_betrag IS NOT NULL AND fw_waehrung IS NOT NULL AND kurs IS NOT NULL
            AND kurs_datum IS NOT NULL AND kurs_art IS NOT NULL)),
    CONSTRAINT zeile_fw_umrechnung CHECK (
        fw_betrag IS NULL OR betrag IS NULL OR betrag = round(fw_betrag / kurs, 2))
);
CREATE INDEX buchungszeile_konto_idx ON kern.buchungszeile (konto);

CREATE TABLE kern.zeile_tag (
    zeile   uuid NOT NULL REFERENCES kern.buchungszeile ON DELETE CASCADE,
    tag     uuid NOT NULL REFERENCES kern.tag,
    PRIMARY KEY (zeile, tag)
);

-- Erweiterungswerte (F58): Teil des Inhalts und damit des Hashes
CREATE TABLE kern.erweiterungswert (
    id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    buchung     uuid NOT NULL REFERENCES kern.buchung ON DELETE CASCADE,
    zeile       uuid REFERENCES kern.buchungszeile ON DELETE CASCADE,
    schluessel  text NOT NULL REFERENCES kern.erweiterungsattribut,
    wert        text NOT NULL,
    UNIQUE NULLS NOT DISTINCT (buchung, zeile, schluessel)
);

-- Prüfvermerke der Module (F72)
CREATE TABLE kern.pruefvermerk (
    buchung             uuid NOT NULL REFERENCES kern.buchung ON DELETE CASCADE,
    modul               text NOT NULL REFERENCES kern.modul,
    modulversion        text NOT NULL,
    erweiterungspunkt   text NOT NULL CHECK (erweiterungspunkt IN ('vorbereiten_anreichern',
                            'vorbereiten_pruefen', 'festschreiben_pruefen', 'storno_pruefen')),
    ergebnis            boolean NOT NULL,
    meldung             text,
    zeitpunkt           timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (buchung, modul, erweiterungspunkt)
);

-- Belegverweise (F10, F27)
CREATE TABLE kern.belegverweis (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    buchung         uuid NOT NULL REFERENCES kern.buchung ON DELETE CASCADE,
    paperless_id    integer NOT NULL CHECK (paperless_id > 0),
    md5             char(32) NOT NULL CHECK (md5 ~ '^[0-9a-f]{32}$'),
    sha256          char(64) NOT NULL CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    art             kern.belegart NOT NULL DEFAULT 'festschreibung',
    zugeordnet_am   timestamptz NOT NULL DEFAULT now(),
    zugeordnet_von  text NOT NULL,
    UNIQUE (buchung, paperless_id)
);
CREATE INDEX belegverweis_paperless_idx ON kern.belegverweis (paperless_id);

-- Geordnete Liste einer (Stapel-)Freigabe (F67)
CREATE TABLE kern.freigabe_position (
    freigabe        uuid NOT NULL REFERENCES kern.freigabe,
    pos             integer NOT NULL CHECK (pos >= 1),
    buchung         uuid NOT NULL REFERENCES kern.buchung,
    freigabe_hash   text NOT NULL CHECK (freigabe_hash ~ '^[0-9a-f]{64}$'),
    PRIMARY KEY (freigabe, pos),
    UNIQUE (freigabe, buchung)
);
