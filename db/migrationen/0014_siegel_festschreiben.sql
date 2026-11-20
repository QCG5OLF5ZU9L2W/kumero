-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Kern, Migration 0014: Ein Siegel wird erst nach Abschluss und Bestätigung festgeschrieben (F124; SIEGEL.md)
--
-- Bisher (0013) schrieb schon „Siegel anfordern“ ein Siegel mit Nummer und einen Protokolleintrag fest; ein nie
-- signiertes Siegel blieb für immer stehen. Ab hier gilt:
--
--   * **Anfordern schreibt nichts fest.** Es entsteht eine vorläufige Anforderung mit dem Siegelinhalt – ohne Eintrag
--     in `kern.siegel`, ohne Protokoll. Sie lässt sich verwerfen und verfällt nach 24 Stunden. Es gibt höchstens eine,
--     weil Nummer und Prüfsumme des vorigen Siegels im signierten Inhalt stehen.
--   * **Die geprüfte Signatur steht zunächst an der Anforderung.** Auch das ist vorläufig.
--   * **Erst das Festschreiben schreibt** – Siegel, Signatur und Protokolleintrag in einer Transaktion.
--   * Was festgeschrieben ist, bleibt wie bisher unveränderlich. Weitere Signaturen trägt nur ein Siegel, das schon
--     festgeschrieben ist.
--   * Siegel, die vor dieser Migration angefordert und nie signiert wurden, bleiben als „ausstehend“ stehen
--     (der Kern löscht nichts) und zählen in Nummer und Vorgänger mit; signiert werden sie nicht mehr.

CREATE TABLE kern.siegel_anforderung (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),     -- wird beim Festschreiben die ID des Siegels
    eins            boolean NOT NULL DEFAULT true UNIQUE CHECK (eins),   -- höchstens eine offene Anforderung
    nr              bigint NOT NULL CHECK (nr >= 1),
    angefordert_am  timestamptz NOT NULL,
    angefordert_von text NOT NULL,
    gueltig_bis     timestamptz NOT NULL,
    buchungen_nr    bigint NOT NULL CHECK (buchungen_nr >= 0),
    buchungen_hash  text   NOT NULL CHECK (buchungen_hash ~ '^[0-9a-f]{64}$'),
    protokoll_nr    bigint NOT NULL CHECK (protokoll_nr >= 0),
    protokoll_hash  text   NOT NULL CHECK (protokoll_hash ~ '^[0-9a-f]{64}$'),
    vorgaenger      text   CHECK (vorgaenger ~ '^[0-9a-f]{64}$'),
    inhalt          text   NOT NULL,                                -- genau diese Bytes werden signiert
    inhalt_sha256   text   NOT NULL CHECK (inhalt_sha256 ~ '^[0-9a-f]{64}$'),
    -- die geprüfte, noch nicht festgeschriebene Signatur
    schluessel      text REFERENCES kern.siegelschluessel,
    art             text CHECK (art IN ('pgp')),
    signatur        text CHECK (signatur LIKE '-----BEGIN PGP SIGNATURE-----%'),
    signiert_am     timestamptz,
    geprueft_am     timestamptz,
    geprueft_von    text,
    CHECK ((schluessel IS NULL) = (art IS NULL) AND (schluessel IS NULL) = (signatur IS NULL)
       AND (schluessel IS NULL) = (signiert_am IS NULL) AND (schluessel IS NULL) = (geprueft_am IS NULL)
       AND (schluessel IS NULL) = (geprueft_von IS NULL))
);
COMMENT ON TABLE kern.siegel_anforderung IS
    'Vorläufige Anforderung eines Siegels: nicht Teil der Ketten, ohne Protokoll, verwerfbar. Festgeschrieben wird erst nach Signatur und Bestätigung (F124).';

-- Verfallene Anforderung entfernen. Nur für die Funktionen unten.
CREATE FUNCTION kern._siegel_anforderung_aufraeumen() RETURNS void
LANGUAGE sql AS $$
    DELETE FROM kern.siegel_anforderung WHERE gueltig_bis <= clock_timestamp();
$$;

-- Siegel anfordern: liest beide Kettenköpfe und bildet den Siegelinhalt – vorläufig, ohne Siegel und ohne Protokoll.
CREATE OR REPLACE FUNCTION kern.siegel_anfordern(p_subjekt text, p_mandant text) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE
    k1 kern.kettenkopf; k2 kern.kettenkopf;
    n bigint; jetzt timestamptz := clock_timestamp(); text_ text; vorg text; neu uuid;
BEGIN
    PERFORM kern._subjekt(p_subjekt);
    PERFORM kern._schreibbar();
    IF p_mandant IS NULL OR p_mandant !~ '^[a-z][a-z0-9-]{1,31}$' THEN
        PERFORM kern.verweigern('Kennung des Mandanten fehlt', 'KM002');
    END IF;
    -- Reihenfolge der Sperren wie beim Festschreiben: erst Kette 1, dann Kette 2
    SELECT * INTO k1 FROM kern.kettenkopf WHERE kette = 1 FOR UPDATE;
    SELECT * INTO k2 FROM kern.kettenkopf WHERE kette = 2 FOR UPDATE;
    PERFORM kern._siegel_anforderung_aufraeumen();
    IF EXISTS (SELECT 1 FROM kern.siegel_anforderung) THEN
        PERFORM kern.verweigern('Es gibt schon eine offene Anforderung – erst festschreiben oder verwerfen', 'KM003');
    END IF;
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
    INSERT INTO kern.siegel_anforderung (nr, angefordert_am, angefordert_von, gueltig_bis, buchungen_nr, buchungen_hash,
                                         protokoll_nr, protokoll_hash, vorgaenger, inhalt, inhalt_sha256)
         VALUES (n, jetzt, p_subjekt, jetzt + interval '24 hours', k1.letzte_nr, k1.letzter_hash,
                 k2.letzte_nr, k2.letzter_hash, vorg, text_, kern.sha256_hex(text_))
      RETURNING id INTO neu;
    RETURN neu;
END $$;

-- Anforderung verwerfen: spurlos. Festgeschrieben war noch nichts.
CREATE FUNCTION kern.siegel_anforderung_verwerfen(p_subjekt text, p_anforderung uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
BEGIN
    PERFORM kern._subjekt(p_subjekt);
    PERFORM kern._schreibbar();
    DELETE FROM kern.siegel_anforderung WHERE id = p_anforderung;
    IF NOT FOUND THEN
        PERFORM kern.verweigern('Anforderung unbekannt oder schon erledigt', 'KM003');
    END IF;
END $$;

-- Geprüfte Signatur an der Anforderung vermerken. Die Prüfung gegen Inhalt und Schlüssel hat die Anwendung vorher
-- getan. Eine frühere Signatur an der Anforderung wird ersetzt – festgeschrieben ist noch nichts.
CREATE FUNCTION kern.siegel_anforderung_signatur(p_subjekt text, p_anforderung uuid, p_fpr text, p_art text,
                                                 p_signatur text, p_signiert_am timestamptz) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE s kern.siegelschluessel; a kern.siegel_anforderung;
BEGIN
    PERFORM kern._subjekt(p_subjekt);
    PERFORM kern._schreibbar();
    SELECT * INTO a FROM kern.siegel_anforderung WHERE id = p_anforderung FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM kern.verweigern('Anforderung unbekannt oder schon erledigt', 'KM003');
    END IF;
    IF a.gueltig_bis <= clock_timestamp() THEN
        PERFORM kern.verweigern('Die Anforderung ist verfallen – neu anfordern', 'KM003');
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
    UPDATE kern.siegel_anforderung
       SET schluessel = p_fpr, art = p_art, signatur = p_signatur, signiert_am = p_signiert_am,
           geprueft_am = clock_timestamp(), geprueft_von = p_subjekt
     WHERE id = p_anforderung;
END $$;

-- Siegel festschreiben: Siegel, Signatur und Protokolleintrag in einer Transaktion. Erst hier entsteht etwas Bleibendes.
CREATE FUNCTION kern.siegel_festschreiben(p_subjekt text, p_anforderung uuid) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE a kern.siegel_anforderung; s kern.siegelschluessel; n bigint; vorg text;
BEGIN
    PERFORM kern._subjekt(p_subjekt);
    PERFORM kern._schreibbar();
    -- Sperren wie beim Anfordern: Damit läuft kein zweites Festschreiben und kein Anfordern nebenher
    PERFORM 1 FROM kern.kettenkopf WHERE kette = 1 FOR UPDATE;
    PERFORM 1 FROM kern.kettenkopf WHERE kette = 2 FOR UPDATE;
    SELECT * INTO a FROM kern.siegel_anforderung WHERE id = p_anforderung FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM kern.verweigern('Anforderung unbekannt oder schon erledigt', 'KM003');
    END IF;
    IF a.gueltig_bis <= clock_timestamp() THEN
        PERFORM kern.verweigern('Die Anforderung ist verfallen – neu anfordern', 'KM003');
    END IF;
    IF a.schluessel IS NULL THEN
        PERFORM kern.verweigern('Ohne geprüfte Signatur wird kein Siegel festgeschrieben', 'KM003');
    END IF;
    SELECT * INTO s FROM kern.siegelschluessel WHERE fpr = a.schluessel;
    IF s.zurueckgezogen_am IS NOT NULL THEN
        PERFORM kern.verweigern('Der Schlüssel ist zurückgezogen', 'KM003');
    END IF;
    -- Nummer und Vorgänger stehen im signierten Inhalt; sie müssen noch stimmen
    SELECT coalesce(max(nr), 0) + 1 INTO n FROM kern.siegel;
    SELECT inhalt_sha256 INTO vorg FROM kern.siegel ORDER BY nr DESC LIMIT 1;
    IF a.nr <> n OR a.vorgaenger IS DISTINCT FROM vorg OR a.inhalt_sha256 <> kern.sha256_hex(a.inhalt) THEN
        PERFORM kern.verweigern('Die Anforderung passt nicht mehr zum Stand der Siegel – neu anfordern', 'KM003');
    END IF;
    INSERT INTO kern.siegel (id, nr, angefordert_am, angefordert_von, buchungen_nr, buchungen_hash, protokoll_nr, protokoll_hash,
                             inhalt, inhalt_sha256, status)
         VALUES (a.id, a.nr, a.angefordert_am, a.angefordert_von, a.buchungen_nr, a.buchungen_hash, a.protokoll_nr, a.protokoll_hash,
                 a.inhalt, a.inhalt_sha256, 'signiert');
    INSERT INTO kern.siegel_signatur (siegel, schluessel, art, signatur, signiert_am, eingereicht_von)
         VALUES (a.id, a.schluessel, a.art, a.signatur, a.signiert_am, a.geprueft_von);
    DELETE FROM kern.siegel_anforderung WHERE id = a.id;
    -- Das Ereignis nennt die Prüfsumme des Inhalts und verankert das Siegel damit selbst in der Kette
    PERFORM kern._protokoll('siegel.festgeschrieben', p_subjekt, jsonb_build_object('siegel', a.id, 'schluessel', a.schluessel),
        NULL, jsonb_build_object('nr', a.nr, 'inhalt_sha256', a.inhalt_sha256, 'buchungen_nr', a.buchungen_nr,
                                 'protokoll_nr', a.protokoll_nr, 'art', a.art, 'signiert_am', kern.zeitpunkt(a.signiert_am),
                                 'angefordert_von', a.angefordert_von, 'angefordert_am', kern.zeitpunkt(a.angefordert_am)));
    RETURN a.id;
END $$;

-- Weitere Signatur an einem festgeschriebenen Siegel (m:n). Ein Siegel, das vor 0014 angefordert und nie signiert
-- wurde, nimmt keine Signatur mehr an: Es wäre ein Siegel, das ohne Bestätigung festgeschrieben wurde.
CREATE OR REPLACE FUNCTION kern.siegel_signieren(p_subjekt text, p_siegel uuid, p_fpr text, p_art text, p_signatur text,
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
    IF g.status = 'ausstehend' THEN
        PERFORM kern.verweigern('Dieses Siegel wurde nie signiert und nimmt keine Signatur mehr an – neu anfordern', 'KM003');
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
    PERFORM kern._protokoll('siegel.signiert', p_subjekt, jsonb_build_object('siegel', p_siegel, 'schluessel', p_fpr),
        NULL, jsonb_build_object('nr', g.nr, 'art', p_art, 'signiert_am', kern.zeitpunkt(p_signiert_am)));
END $$;

-- ---------------------------------------------------------------------------
-- Rechte
-- ---------------------------------------------------------------------------
REVOKE ALL ON kern.siegel_anforderung FROM PUBLIC;
GRANT SELECT ON kern.siegel_anforderung TO kumero_app, kumero_lesen;
REVOKE EXECUTE ON FUNCTION
    kern._siegel_anforderung_aufraeumen(),
    kern.siegel_anforderung_verwerfen(text, uuid),
    kern.siegel_anforderung_signatur(text, uuid, text, text, text, timestamptz),
    kern.siegel_festschreiben(text, uuid)
  FROM PUBLIC;
GRANT EXECUTE ON FUNCTION
    kern.siegel_anforderung_verwerfen(text, uuid),
    kern.siegel_anforderung_signatur(text, uuid, text, text, text, timestamptz),
    kern.siegel_festschreiben(text, uuid)
  TO kumero_app;
