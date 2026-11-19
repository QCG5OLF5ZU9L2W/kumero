-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Kern, Migration 0013: Siegel auf Anforderung, Signaturen und Schlüssel im Mandanten (F119, F120; SIEGEL.md)
--
-- Bisher war ein Siegel je Kette vorgesehen, signiert von einem Systemschlüssel auf dem Server (F14, F38). Gebaut
-- war davon nur die Tabelle. Ab hier gilt:
--
--   * Ein Siegel hält beide Kettenköpfe fest (Buchungen und Protokoll) und entsteht auf Anforderung einer Person.
--   * Der **Siegelinhalt** ist ein kanonisches JSON (RFC 8785, F29). Genau diese Bytes werden signiert – außerhalb
--     der Datenbank und außerhalb des Servers. Der private Schlüssel erreicht kumero nie.
--   * Ein Siegel trägt beliebig viele Signaturen (m:n zu den Schlüsseln). Ein Schlüssel wird im Mandanten angemeldet
--     und gehört nur zu ihm: Es liegt nur der öffentliche Teil vor.
--   * Die Signatur prüft die Anwendung (gpgv) vor dem Eintragen; die Datenbank kann das nicht. Weil Inhalt, Signatur
--     und öffentlicher Schlüssel beisammen liegen, kann jeder die Prüfung wiederholen.
--   * Geschrieben wird nur über Funktionen (F64), jede Handlung steht im Protokoll (F48).

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM kern.siegel) THEN
        RAISE EXCEPTION 'kern.siegel enthält bereits Einträge – der Umbau auf F120 ist dafür nicht vorgesehen';
    END IF;
END $$;

DROP TRIGGER schutz ON kern.siegel;
ALTER TABLE kern.siegel
    DROP COLUMN kette, DROP COLUMN bis_nr, DROP COLUMN ketten_hash, DROP COLUMN signatur, DROP COLUMN schluessel_fpr;
ALTER TABLE kern.siegel RENAME COLUMN erstellt_am TO angefordert_am;
ALTER TABLE kern.siegel
    ADD COLUMN nr              bigint NOT NULL UNIQUE CHECK (nr >= 1),
    ADD COLUMN angefordert_von text   NOT NULL,
    ADD COLUMN buchungen_nr    bigint NOT NULL CHECK (buchungen_nr >= 0),
    ADD COLUMN buchungen_hash  text   NOT NULL CHECK (buchungen_hash ~ '^[0-9a-f]{64}$'),
    ADD COLUMN protokoll_nr    bigint NOT NULL CHECK (protokoll_nr >= 0),
    ADD COLUMN protokoll_hash  text   NOT NULL CHECK (protokoll_hash ~ '^[0-9a-f]{64}$'),
    ADD COLUMN inhalt          text   NOT NULL,                                   -- genau diese Bytes werden signiert
    ADD COLUMN inhalt_sha256   text   NOT NULL UNIQUE CHECK (inhalt_sha256 ~ '^[0-9a-f]{64}$'),
    ADD CONSTRAINT siegel_gestempelt CHECK (status <> 'gestempelt' OR tsr IS NOT NULL);
COMMENT ON TABLE kern.siegel IS 'Siegel auf Anforderung: beide Kettenköpfe zu einem Zeitpunkt, als signierbarer Inhalt (F119, F120).';

-- Öffentliche Schlüssel, mit denen im Mandanten gesiegelt wird. Der private Teil liegt nie hier.
CREATE TABLE kern.siegelschluessel (
    fpr                  text PRIMARY KEY CHECK (fpr ~ '^[0-9A-F]{40,64}$'),
    art                  text NOT NULL CHECK (art IN ('pgp')),                     -- später: x509 (QES)
    kennung              text NOT NULL CHECK (length(trim(kennung)) BETWEEN 1 AND 300),   -- User-ID des Schlüssels
    algorithmus          text NOT NULL CHECK (length(algorithmus) BETWEEN 1 AND 40),
    oeffentlich          text NOT NULL CHECK (oeffentlich LIKE '-----BEGIN PGP PUBLIC KEY BLOCK-----%'),
    angemeldet_am        timestamptz NOT NULL DEFAULT now(),
    angemeldet_von       text NOT NULL,
    zurueckgezogen_am    timestamptz,
    zurueckgezogen_von   text,
    zurueckgezogen_grund text,
    CHECK ((zurueckgezogen_am IS NULL) = (zurueckgezogen_von IS NULL) AND (zurueckgezogen_am IS NULL) = (zurueckgezogen_grund IS NULL))
);

CREATE TABLE kern.siegel_signatur (
    siegel          uuid NOT NULL REFERENCES kern.siegel,
    schluessel      text NOT NULL REFERENCES kern.siegelschluessel,
    art             text NOT NULL CHECK (art IN ('pgp')),
    signatur        text NOT NULL CHECK (signatur LIKE '-----BEGIN PGP SIGNATURE-----%'),
    signiert_am     timestamptz NOT NULL,          -- Zeitangabe aus der Signatur (Uhr des Unterzeichners)
    eingereicht_am  timestamptz NOT NULL DEFAULT now(),
    eingereicht_von text NOT NULL,
    PRIMARY KEY (siegel, schluessel)
);

-- Schutz: Siegel und Signaturen bleiben, wie sie sind; am Schlüssel ändert sich nur das Zurückziehen, einmal.
CREATE OR REPLACE FUNCTION kern.tg_siegel() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        PERFORM kern.verweigern('Siegel werden nie gelöscht');
    END IF;
    IF (NEW.id, NEW.nr, NEW.angefordert_am, NEW.angefordert_von, NEW.buchungen_nr, NEW.buchungen_hash,
        NEW.protokoll_nr, NEW.protokoll_hash, NEW.inhalt, NEW.inhalt_sha256)
       IS DISTINCT FROM (OLD.id, OLD.nr, OLD.angefordert_am, OLD.angefordert_von, OLD.buchungen_nr, OLD.buchungen_hash,
        OLD.protokoll_nr, OLD.protokoll_hash, OLD.inhalt, OLD.inhalt_sha256)
       OR (OLD.tsr IS NOT NULL AND (NEW.tsr, NEW.tsa, NEW.gestempelt_am) IS DISTINCT FROM (OLD.tsr, OLD.tsa, OLD.gestempelt_am))
       OR NEW.status < OLD.status THEN
        PERFORM kern.verweigern('Siegelinhalte werden nur einmal befüllt (F14)');
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER schutz BEFORE UPDATE OR DELETE ON kern.siegel
    FOR EACH ROW EXECUTE FUNCTION kern.tg_siegel();

CREATE TRIGGER nur_einfuegen BEFORE UPDATE OR DELETE ON kern.siegel_signatur
    FOR EACH ROW EXECUTE FUNCTION kern.tg_nur_einfuegen();
CREATE TRIGGER kein_truncate BEFORE TRUNCATE ON kern.siegel_signatur
    FOR EACH STATEMENT EXECUTE FUNCTION kern.tg_kein_truncate();

CREATE FUNCTION kern.tg_siegelschluessel() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        PERFORM kern.verweigern('Siegelschlüssel werden nie gelöscht – mit ihnen werden alte Siegel geprüft');
    END IF;
    IF (NEW.fpr, NEW.art, NEW.kennung, NEW.algorithmus, NEW.oeffentlich, NEW.angemeldet_am, NEW.angemeldet_von)
       IS DISTINCT FROM (OLD.fpr, OLD.art, OLD.kennung, OLD.algorithmus, OLD.oeffentlich, OLD.angemeldet_am, OLD.angemeldet_von)
       OR OLD.zurueckgezogen_am IS NOT NULL THEN
        PERFORM kern.verweigern('Am Siegelschlüssel ändert sich nur das Zurückziehen, und das nur einmal');
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER schutz BEFORE UPDATE OR DELETE ON kern.siegelschluessel
    FOR EACH ROW EXECUTE FUNCTION kern.tg_siegelschluessel();
CREATE TRIGGER kein_truncate BEFORE TRUNCATE ON kern.siegelschluessel
    FOR EACH STATEMENT EXECUTE FUNCTION kern.tg_kein_truncate();

-- ---------------------------------------------------------------------------
-- Funktionen
-- ---------------------------------------------------------------------------

-- Siegel anfordern: hält beide Kettenköpfe fest und bildet den Siegelinhalt. Der Kopf des Protokolls ist der Stand
-- unmittelbar vor dem Ereignis `siegel.angefordert`; das Ereignis nennt die Prüfsumme des Inhalts und verankert das
-- Siegel damit selbst in der Kette.
CREATE FUNCTION kern.siegel_anfordern(p_subjekt text, p_mandant text) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE
    k1 kern.kettenkopf; k2 kern.kettenkopf;
    n bigint; jetzt timestamptz := clock_timestamp(); text_ text; summe text; vorg text; neu uuid;
BEGIN
    PERFORM kern._subjekt(p_subjekt);
    PERFORM kern._schreibbar();
    IF p_mandant IS NULL OR p_mandant !~ '^[a-z][a-z0-9-]{1,31}$' THEN
        PERFORM kern.verweigern('Kennung des Mandanten fehlt', 'KM002');
    END IF;
    -- Reihenfolge der Sperren wie beim Festschreiben: erst Kette 1, dann Kette 2
    SELECT * INTO k1 FROM kern.kettenkopf WHERE kette = 1 FOR UPDATE;
    SELECT * INTO k2 FROM kern.kettenkopf WHERE kette = 2 FOR UPDATE;
    SELECT coalesce(max(nr), 0) + 1 INTO n FROM kern.siegel;
    SELECT inhalt_sha256 INTO vorg FROM kern.siegel ORDER BY nr DESC LIMIT 1;
    text_ := kern.jcs(jsonb_build_object(
        'art',        'kumero-siegel',
        'fassung',    1,
        'mandant',    p_mandant,
        'nr',         n,
        'zeitpunkt',  kern.zeitpunkt(jetzt),
        'buchungen',  jsonb_build_object('bis_nr', k1.letzte_nr, 'hash', k1.letzter_hash),
        'protokoll',  jsonb_build_object('bis_nr', k2.letzte_nr, 'hash', k2.letzter_hash),
        'vorgaenger', vorg));
    summe := kern.sha256_hex(text_);
    INSERT INTO kern.siegel (nr, angefordert_am, angefordert_von, buchungen_nr, buchungen_hash, protokoll_nr, protokoll_hash, inhalt, inhalt_sha256)
         VALUES (n, jetzt, p_subjekt, k1.letzte_nr, k1.letzter_hash, k2.letzte_nr, k2.letzter_hash, text_, summe)
      RETURNING id INTO neu;
    PERFORM kern._protokoll('siegel.angefordert', p_subjekt, jsonb_build_object('siegel', neu),
        NULL, jsonb_build_object('nr', n, 'inhalt_sha256', summe, 'buchungen_nr', k1.letzte_nr, 'protokoll_nr', k2.letzte_nr));
    RETURN neu;
END $$;

-- Öffentlichen Schlüssel anmelden. Fingerabdruck, Kennung und Algorithmus liest die Anwendung aus dem Schlüssel.
CREATE FUNCTION kern.siegelschluessel_anmelden(p_subjekt text, p_art text, p_fpr text, p_kennung text,
                                               p_algorithmus text, p_oeffentlich text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
BEGIN
    PERFORM kern._subjekt(p_subjekt);
    PERFORM kern._schreibbar();
    IF p_oeffentlich LIKE '%PRIVATE KEY%' THEN
        PERFORM kern.verweigern('Ein privater Schlüssel wird nie gespeichert', 'KM002');
    END IF;
    IF EXISTS (SELECT 1 FROM kern.siegelschluessel WHERE fpr = p_fpr) THEN
        PERFORM kern.verweigern('Dieser Schlüssel ist schon angemeldet', 'KM003');
    END IF;
    INSERT INTO kern.siegelschluessel (fpr, art, kennung, algorithmus, oeffentlich, angemeldet_von)
         VALUES (p_fpr, p_art, trim(p_kennung), p_algorithmus, p_oeffentlich, p_subjekt);
    -- die Kennung (Name, Adresse) steht nicht in der Kette; der Fingerabdruck bezeichnet den Schlüssel eindeutig
    PERFORM kern._protokoll('siegel.schluessel_angemeldet', p_subjekt, jsonb_build_object('schluessel', p_fpr),
        NULL, jsonb_build_object('art', p_art, 'algorithmus', p_algorithmus));
END $$;

-- Schlüssel zurückziehen: Er nimmt keine neuen Signaturen mehr an; was mit ihm gesiegelt ist, bleibt prüfbar.
CREATE FUNCTION kern.siegelschluessel_zurueckziehen(p_subjekt text, p_fpr text, p_grund text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE s kern.siegelschluessel;
BEGIN
    PERFORM kern._subjekt(p_subjekt);
    PERFORM kern._schreibbar();
    IF p_grund IS NULL OR length(trim(p_grund)) = 0 THEN
        PERFORM kern.verweigern('Grund fehlt', 'KM002');
    END IF;
    SELECT * INTO s FROM kern.siegelschluessel WHERE fpr = p_fpr FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM kern.verweigern('Schlüssel unbekannt', 'KM003');
    END IF;
    IF s.zurueckgezogen_am IS NOT NULL THEN
        PERFORM kern.verweigern('Der Schlüssel ist schon zurückgezogen', 'KM003');
    END IF;
    UPDATE kern.siegelschluessel
       SET zurueckgezogen_am = clock_timestamp(), zurueckgezogen_von = p_subjekt, zurueckgezogen_grund = trim(p_grund)
     WHERE fpr = p_fpr;
    PERFORM kern._protokoll('siegel.schluessel_zurueckgezogen', p_subjekt, jsonb_build_object('schluessel', p_fpr),
        NULL, jsonb_build_object('grund', trim(p_grund)));
END $$;

-- Geprüfte Signatur am Siegel eintragen. Die Prüfung gegen Inhalt und Schlüssel hat die Anwendung vorher getan.
CREATE FUNCTION kern.siegel_signieren(p_subjekt text, p_siegel uuid, p_fpr text, p_art text, p_signatur text,
                                      p_signiert_am timestamptz) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE s kern.siegelschluessel; g kern.siegel;
BEGIN
    PERFORM kern._subjekt(p_subjekt);
    PERFORM kern._schreibbar();
    SELECT * INTO g FROM kern.siegel WHERE id = p_siegel FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM kern.verweigern('Siegel unbekannt', 'KM003');
    END IF;
    SELECT * INTO s FROM kern.siegelschluessel WHERE fpr = p_fpr;
    IF NOT FOUND THEN
        PERFORM kern.verweigern('Der Schlüssel ist in diesem Mandanten nicht angemeldet', 'KM003');
    END IF;
    IF s.zurueckgezogen_am IS NOT NULL THEN
        PERFORM kern.verweigern('Der Schlüssel ist zurückgezogen', 'KM003');
    END IF;
    IF s.art <> p_art THEN
        PERFORM kern.verweigern('Art der Signatur passt nicht zum Schlüssel', 'KM002');
    END IF;
    IF EXISTS (SELECT 1 FROM kern.siegel_signatur WHERE siegel = p_siegel AND schluessel = p_fpr) THEN
        PERFORM kern.verweigern('Dieses Siegel trägt schon eine Signatur dieses Schlüssels', 'KM003');
    END IF;
    INSERT INTO kern.siegel_signatur (siegel, schluessel, art, signatur, signiert_am, eingereicht_von)
         VALUES (p_siegel, p_fpr, p_art, p_signatur, p_signiert_am, p_subjekt);
    IF g.status = 'ausstehend' THEN
        UPDATE kern.siegel SET status = 'signiert' WHERE id = p_siegel;
    END IF;
    PERFORM kern._protokoll('siegel.signiert', p_subjekt, jsonb_build_object('siegel', p_siegel, 'schluessel', p_fpr),
        NULL, jsonb_build_object('nr', g.nr, 'art', p_art, 'signiert_am', kern.zeitpunkt(p_signiert_am)));
END $$;

-- ---------------------------------------------------------------------------
-- Rechte
-- ---------------------------------------------------------------------------
REVOKE ALL ON kern.siegelschluessel, kern.siegel_signatur FROM PUBLIC;
GRANT SELECT ON kern.siegelschluessel, kern.siegel_signatur TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON FUNCTION
    kern.siegel_anfordern(text, text),
    kern.siegelschluessel_anmelden(text, text, text, text, text, text),
    kern.siegelschluessel_zurueckziehen(text, text, text),
    kern.siegel_signieren(text, uuid, text, text, text, timestamptz),
    kern.tg_siegelschluessel()
  FROM PUBLIC;
GRANT EXECUTE ON FUNCTION
    kern.siegel_anfordern(text, text),
    kern.siegelschluessel_anmelden(text, text, text, text, text, text),
    kern.siegelschluessel_zurueckziehen(text, text, text),
    kern.siegel_signieren(text, uuid, text, text, text, timestamptz)
  TO kumero_app;
