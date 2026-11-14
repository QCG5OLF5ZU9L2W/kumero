-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Kern, Migration 0004: Nachweis, Integration, Rechte (Gruppen D, E, F)

-- ---------------------------------------------------------------------------
-- D. Nachweis
-- ---------------------------------------------------------------------------
-- Kettenköpfe: 1 = Buchungen, 2 = Protokoll (F13). Startwert: 64 Nullen.
CREATE TABLE kern.kettenkopf (
    kette       smallint PRIMARY KEY CHECK (kette IN (1, 2)),
    letzte_nr   bigint NOT NULL DEFAULT 0 CHECK (letzte_nr >= 0),
    letzter_hash text NOT NULL DEFAULT repeat('0', 64) CHECK (letzter_hash ~ '^[0-9a-f]{64}$')
);
INSERT INTO kern.kettenkopf (kette) VALUES (1), (2);

-- Protokoll = Kette 2 (F48): vollständiger Vorher-/Nachher-Stand
CREATE TABLE kern.protokoll (
    nr              bigint PRIMARY KEY CHECK (nr >= 1),
    zeitpunkt       timestamptz NOT NULL DEFAULT clock_timestamp(),
    typ             text NOT NULL CHECK (typ ~ '^[a-z][a-z0-9-]*\.[a-z][a-z0-9_]*$'),
    subjekt         text NOT NULL,
    ids             jsonb NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(ids) = 'object'),
    vorher          jsonb,
    nachher         jsonb,
    freigabe        uuid REFERENCES kern.freigabe,
    vorgaenger_hash text NOT NULL CHECK (vorgaenger_hash ~ '^[0-9a-f]{64}$'),
    hash            text NOT NULL UNIQUE CHECK (hash ~ '^[0-9a-f]{64}$')
);
CREATE INDEX protokoll_typ_idx ON kern.protokoll (typ);

CREATE TABLE kern.siegel (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    kette           smallint NOT NULL CHECK (kette IN (1, 2)),
    bis_nr          bigint NOT NULL CHECK (bis_nr >= 1),
    ketten_hash     text NOT NULL CHECK (ketten_hash ~ '^[0-9a-f]{64}$'),
    erstellt_am     timestamptz NOT NULL DEFAULT now(),
    signatur        text,                -- PGP, ASCII-armored (Systemschlüssel)
    schluessel_fpr  text CHECK (schluessel_fpr ~ '^[0-9A-F]{40,64}$'),
    tsr             bytea,               -- RFC-3161-Zeitstempel-Token
    tsa             text,
    gestempelt_am   timestamptz,
    status          kern.siegelstatus NOT NULL DEFAULT 'ausstehend',
    UNIQUE (kette, bis_nr),
    CHECK ((signatur IS NULL) = (schluessel_fpr IS NULL)),
    CHECK ((tsr IS NULL) = (tsa IS NULL) AND (tsr IS NULL) = (gestempelt_am IS NULL)),
    CHECK (status <> 'signiert'   OR signatur IS NOT NULL),
    CHECK (status <> 'gestempelt' OR (signatur IS NOT NULL AND tsr IS NOT NULL))
);

CREATE TABLE kern.siegel_export (
    siegel      uuid NOT NULL REFERENCES kern.siegel,
    ziel        text NOT NULL,
    status      kern.erledigung NOT NULL DEFAULT 'ausstehend',
    versuche    integer NOT NULL DEFAULT 0 CHECK (versuche >= 0),
    erledigt_am timestamptz,
    PRIMARY KEY (siegel, ziel),
    CHECK ((status = 'erledigt') = (erledigt_am IS NOT NULL))
);

CREATE TABLE kern.restore_pruefung (
    id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    zeitpunkt           timestamptz NOT NULL DEFAULT now(),
    ergebnisse          jsonb NOT NULL,
    luecke              boolean NOT NULL,
    letztes_externes    jsonb,
    freigabe            uuid REFERENCES kern.freigabe,
    CHECK (NOT luecke OR letztes_externes IS NOT NULL)
);

-- ---------------------------------------------------------------------------
-- E. Integration (F21, F48, F71)
-- ---------------------------------------------------------------------------
CREATE TABLE kern.outbox (
    protokoll_nr    bigint PRIMARY KEY REFERENCES kern.protokoll,
    typ             text NOT NULL,
    inhalt          jsonb NOT NULL CHECK (jsonb_typeof(inhalt) = 'object'),   -- nur IDs (F48)
    erstellt_am     timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE kern.webhook_abo (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    url             text NOT NULL CHECK (url ~ '^https://'),
    ereignisse      text[] NOT NULL CHECK (cardinality(ereignisse) > 0),
    geheimnis_ref   text NOT NULL,       -- Verweis, nie das Geheimnis selbst
    aktiv           boolean NOT NULL DEFAULT true,
    angelegt_am     timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE kern.zustellung (
    abo                 uuid   NOT NULL REFERENCES kern.webhook_abo,
    protokoll_nr        bigint NOT NULL REFERENCES kern.outbox,
    status              kern.erledigung NOT NULL DEFAULT 'ausstehend',
    versuche            integer NOT NULL DEFAULT 0 CHECK (versuche >= 0),
    naechster_versuch   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (abo, protokoll_nr)
);
CREATE INDEX zustellung_faellig_idx ON kern.zustellung (naechster_versuch) WHERE status = 'ausstehend';

CREATE TABLE kern.idempotenz (
    subjekt         text NOT NULL,
    schluessel      text NOT NULL CHECK (length(schluessel) BETWEEN 8 AND 255),
    endpunkt        text NOT NULL,
    anfrage_hash    text NOT NULL CHECK (anfrage_hash ~ '^[0-9a-f]{64}$'),
    antwort_status  integer CHECK (antwort_status BETWEEN 100 AND 599),
    antwort_inhalt  jsonb,
    erstellt_am     timestamptz NOT NULL DEFAULT now(),
    ablauf          timestamptz NOT NULL,
    PRIMARY KEY (subjekt, schluessel)
);

-- ---------------------------------------------------------------------------
-- F. Rechte (F41–F43, F63) – im Mandanten
-- ---------------------------------------------------------------------------
CREATE TABLE kern.recht (
    code            text PRIMARY KEY CHECK (code ~ '^[a-z][a-z0-9-]*\.[a-z][a-z0-9_-]*\.[a-z][a-z0-9_-]*$'),
    modul           text NOT NULL REFERENCES kern.modul,
    beschreibung    text NOT NULL,
    CHECK (split_part(code, '.', 1) = modul)
);

CREATE TABLE kern.baustein (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name            text NOT NULL UNIQUE,
    beschreibung    text
);

CREATE TABLE kern.baustein_recht (
    baustein    uuid NOT NULL REFERENCES kern.baustein ON DELETE CASCADE,
    recht       text NOT NULL REFERENCES kern.recht,
    PRIMARY KEY (baustein, recht)
);

CREATE TABLE kern.rolle (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name            text NOT NULL UNIQUE,
    vorlage         boolean NOT NULL DEFAULT false,
    beschreibung    text
);

CREATE TABLE kern.rolle_baustein (
    rolle           uuid NOT NULL REFERENCES kern.rolle ON DELETE CASCADE,
    baustein        uuid NOT NULL REFERENCES kern.baustein,
    einschraenkung  jsonb CHECK (einschraenkung IS NULL OR jsonb_typeof(einschraenkung) = 'object'),
    PRIMARY KEY (rolle, baustein)
);

CREATE TABLE kern.zuweisung (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    subjekt         text NOT NULL,
    rolle           uuid NOT NULL REFERENCES kern.rolle,
    gueltig_von     timestamptz NOT NULL DEFAULT now(),
    gueltig_bis     timestamptz,
    UNIQUE (subjekt, rolle, gueltig_von),
    CHECK (gueltig_bis IS NULL OR gueltig_bis > gueltig_von)
);

-- Der Kern meldet sich selbst als Modul an (für Rechte und Erweiterungspunkte).
INSERT INTO kern.modul (name, version) VALUES ('kern', '0.1.0');
