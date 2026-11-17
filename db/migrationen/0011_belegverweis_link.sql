-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Kern, Migration 0011: Belegverweis als ganzer Link (F88, ändert F10; PAPERLESS.md L15)
--
-- Der Verweis auf einen Beleg ist der ganze Link, z. B. https://paperless.example.org/documents/5210/ –
-- er nennt Instanz und Dokument zugleich und bleibt bei mehreren Paperless eindeutig. MD5 und SHA-256
-- des Originals bleiben: Der Link sagt, wo der Beleg liegt, die Prüfsummen, welche Datei es ist.
--
-- Prüfsummen: Ein Verweis darf zunächst ungeprüft sein (nur Link, ohne MD5 und SHA-256) – Entwürfe
-- dürfen unvollständig sein (F62), und die Prüfsummen ermittelt erst das Modul paperless am Original
-- (F51 Nr. 4). Festgeschrieben wird ein ungeprüfter Verweis nur im Testbetrieb (F59); im
-- Produktivbetrieb lehnt die Datenbank ab. Der Übergang zum Produktivbetrieb verlangt ein leeres
-- Journal (F70), ungeprüfte Verweise aus dem Test gelangen also nie in ein produktives Buch.
--
-- Bestehende Verweise (nur Dokument-ID) bleiben unverändert stehen und behalten in der kanonischen
-- Form ihre alte Gestalt; damit ändert sich kein Hash einer festgeschriebenen Buchung. Neue Verweise
-- gibt es nur noch mit Link.

ALTER TABLE kern.belegverweis ALTER COLUMN paperless_id DROP NOT NULL;
ALTER TABLE kern.belegverweis ADD COLUMN link text;
ALTER TABLE kern.belegverweis ADD CONSTRAINT belegverweis_link_form CHECK (
    link IS NULL OR (length(link) <= 500 AND link ~ '^https?://[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:[0-9]{1,5})?(/[^[:space:]?#]*)?$'));
ALTER TABLE kern.belegverweis ADD CONSTRAINT belegverweis_link_oder_id CHECK ((link IS NULL) <> (paperless_id IS NULL));
ALTER TABLE kern.belegverweis ALTER COLUMN md5 DROP NOT NULL;
ALTER TABLE kern.belegverweis ALTER COLUMN sha256 DROP NOT NULL;
ALTER TABLE kern.belegverweis ADD CONSTRAINT belegverweis_pruefsummen CHECK (
    (md5 IS NULL) = (sha256 IS NULL) AND (md5 IS NOT NULL OR link IS NOT NULL));
ALTER TABLE kern.belegverweis ADD CONSTRAINT belegverweis_buchung_link_key UNIQUE (buchung, link);
CREATE INDEX belegverweis_link_idx ON kern.belegverweis (link);
COMMENT ON COLUMN kern.belegverweis.link IS 'Ganzer Link auf den Beleg (F88): Schema und Host klein, ohne Zugangsdaten, Abfrage und Fragment';
COMMENT ON COLUMN kern.belegverweis.paperless_id IS 'Nur Altbestand vor 0.7.2 (F10 alt): Dokument-ID ohne Instanz. Neue Verweise tragen link.';

-- Prüft die Schreibweise mit verständlicher Meldung; die CHECK-Bedingung der Tabelle bleibt letzte Instanz.
CREATE FUNCTION kern._beleg_link(p_link text) RETURNS text
LANGUAGE plpgsql IMMUTABLE AS $$
BEGIN
    IF p_link IS NULL OR length(p_link) > 500
       OR p_link !~ '^https?://[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:[0-9]{1,5})?(/[^[:space:]?#]*)?$' THEN
        PERFORM kern.verweigern('Belegverweis: Link erwartet (http oder https, Host klein geschrieben, ohne Zugangsdaten, Abfrage und Fragment, höchstens 500 Zeichen)', 'KM002');
    END IF;
    RETURN p_link;
END $$;

-- Gestalt eines Verweises in der kanonischen Form (F29): neu mit Link, Altbestand wie bisher.
CREATE FUNCTION kern._beleg_json(v kern.belegverweis) RETURNS jsonb
LANGUAGE sql IMMUTABLE RETURN
    CASE WHEN v.link IS NULL
         THEN jsonb_build_object('paperless_id', v.paperless_id, 'md5', v.md5, 'sha256', v.sha256)
         ELSE jsonb_build_object('link', v.link, 'md5', v.md5, 'sha256', v.sha256) END;

CREATE OR REPLACE FUNCTION kern.buchung_inhalt(p_id uuid) RETURNS jsonb
LANGUAGE sql STABLE AS $$
    SELECT jsonb_build_object(
        'id',            b.id::text,
        'buchungsart',   ba.kuerzel,
        'nummernkreis',  nk.kuerzel,
        'belegdatum',    to_char(b.belegdatum, 'YYYY-MM-DD'),
        'buchungsdatum', to_char(b.buchungsdatum, 'YYYY-MM-DD'),
        'belegnummer',   b.belegnummer,
        'text',          b.text,
        'herkunft',      b.herkunft::text,
        'herkunft_ref',  b.herkunft_ref,
        'storno_von',    b.storno_von::text,
        'erfasst_am',    kern.zeitpunkt(b.erfasst_am),
        'erfasst_von',   b.erfasst_von,
        'erweiterung',   coalesce((SELECT jsonb_object_agg(w.schluessel, w.wert)
                                     FROM kern.erweiterungswert w
                                    WHERE w.buchung = b.id AND w.zeile IS NULL), '{}'),
        'zeilen',        coalesce((
            SELECT jsonb_agg(jsonb_build_object(
                       'pos',      z.pos,
                       'konto',    k.nr,
                       'kontoart', k.kontoart::text,
                       'seite',    z.seite::text,
                       'betrag',   kern.dezimal(z.betrag),
                       'fw',       CASE WHEN z.fw_betrag IS NULL THEN NULL ELSE jsonb_build_object(
                                       'betrag',     kern.dezimal(z.fw_betrag),
                                       'waehrung',   z.fw_waehrung,
                                       'kurs',       kern.dezimal(z.kurs),
                                       'kurs_datum', to_char(z.kurs_datum, 'YYYY-MM-DD'),
                                       'kurs_art',   z.kurs_art) END,
                       'tags',     coalesce((SELECT jsonb_agg(t.name ORDER BY t.name COLLATE "C")
                                               FROM kern.zeile_tag zt JOIN kern.tag t ON t.id = zt.tag
                                              WHERE zt.zeile = z.id), '[]'),
                       'erweiterung', coalesce((SELECT jsonb_object_agg(w.schluessel, w.wert)
                                                  FROM kern.erweiterungswert w WHERE w.zeile = z.id), '{}'))
                   ORDER BY z.pos)
              FROM kern.buchungszeile z LEFT JOIN kern.konto k ON k.id = z.konto
             WHERE z.buchung = b.id), '[]'),
        'belege',        coalesce((SELECT jsonb_agg(kern._beleg_json(v)
                                          ORDER BY v.link COLLATE "C" NULLS FIRST, v.paperless_id)
                                     FROM kern.belegverweis v
                                    WHERE v.buchung = b.id AND v.art = 'festschreibung'), '[]'))
      FROM kern.buchung b
      LEFT JOIN kern.buchungsart ba ON ba.id = b.buchungsart
      LEFT JOIN kern.nummernkreis nk ON nk.id = b.nummernkreis
     WHERE b.id = p_id
$$;

CREATE OR REPLACE FUNCTION kern._entwurf_setzen(p_id uuid, d jsonb, p_subjekt text) RETURNS void
LANGUAGE plpgsql AS $$
DECLARE
    ba     kern.buchungsart;
    nk_id  uuid;
    z      jsonb;
    zpos   bigint;
    zid    uuid;
    kid    uuid;
    tname  text;
    tid    uuid;
    e      record;
    beleg  jsonb;
BEGIN
    IF d ? 'buchungsart' AND jsonb_typeof(d->'buchungsart') <> 'null' THEN
        SELECT * INTO ba FROM kern.buchungsart WHERE kuerzel = d->>'buchungsart';
        IF NOT FOUND THEN PERFORM kern.verweigern(format('Buchungsart %s unbekannt', d->>'buchungsart'), 'KM002'); END IF;
    END IF;
    IF d ? 'nummernkreis' AND jsonb_typeof(d->'nummernkreis') <> 'null' THEN
        SELECT id INTO nk_id FROM kern.nummernkreis WHERE kuerzel = d->>'nummernkreis';
        IF NOT FOUND THEN PERFORM kern.verweigern(format('Nummernkreis %s unbekannt', d->>'nummernkreis'), 'KM002'); END IF;
    ELSE
        nk_id := ba.standard_nummernkreis;
    END IF;

    UPDATE kern.buchung SET
        buchungsart   = ba.id,
        nummernkreis  = nk_id,
        belegdatum    = (d->>'belegdatum')::date,
        buchungsdatum = (d->>'buchungsdatum')::date,
        belegnummer   = d->>'belegnummer',
        text          = d->>'text'
     WHERE id = p_id;

    DELETE FROM kern.buchungszeile    WHERE buchung = p_id;
    DELETE FROM kern.erweiterungswert WHERE buchung = p_id;
    DELETE FROM kern.belegverweis     WHERE buchung = p_id;

    FOR z, zpos IN SELECT * FROM jsonb_array_elements(coalesce(d->'zeilen', '[]')) WITH ORDINALITY LOOP
        kid := NULL;
        IF z ? 'konto' AND jsonb_typeof(z->'konto') <> 'null' THEN
            SELECT id INTO kid FROM kern.konto WHERE nr = z->>'konto';
            IF NOT FOUND THEN PERFORM kern.verweigern(format('Zeile %s: Konto %s unbekannt', zpos, z->>'konto'), 'KM002'); END IF;
        END IF;
        INSERT INTO kern.buchungszeile (buchung, pos, konto, seite, betrag,
                                        fw_betrag, fw_waehrung, kurs, kurs_datum, kurs_art)
             VALUES (p_id, zpos, kid, (z->>'seite')::kern.seite,
                     kern._dezimal_lesen(z->'betrag', format('Zeile %s, betrag', zpos), 2),
                     kern._dezimal_lesen(z->'fw_betrag', format('Zeile %s, fw_betrag', zpos), 2),
                     z->>'fw_waehrung',
                     kern._dezimal_lesen(z->'kurs', format('Zeile %s, kurs', zpos), 10),
                     (z->>'kurs_datum')::date, z->>'kurs_art')
          RETURNING id INTO zid;
        FOR tname IN SELECT jsonb_array_elements_text(coalesce(z->'tags', '[]')) LOOP
            SELECT id INTO tid FROM kern.tag WHERE name = tname;
            IF NOT FOUND THEN PERFORM kern.verweigern(format('Zeile %s: Tag "%s" unbekannt', zpos, tname), 'KM002'); END IF;
            INSERT INTO kern.zeile_tag (zeile, tag) VALUES (zid, tid) ON CONFLICT DO NOTHING;
        END LOOP;
        FOR e IN SELECT key, value FROM jsonb_each(coalesce(z->'erweiterung', '{}')) LOOP
            INSERT INTO kern.erweiterungswert (buchung, zeile, schluessel, wert)
                 VALUES (p_id, zid, e.key, e.value #>> '{}');
        END LOOP;
    END LOOP;

    FOR e IN SELECT key, value FROM jsonb_each(coalesce(d->'erweiterung', '{}')) LOOP
        INSERT INTO kern.erweiterungswert (buchung, schluessel, wert) VALUES (p_id, e.key, e.value #>> '{}');
    END LOOP;

    FOR beleg IN SELECT * FROM jsonb_array_elements(coalesce(d->'belege', '[]')) LOOP
        IF beleg ? 'paperless_id' OR NOT beleg ? 'link' THEN
            PERFORM kern.verweigern('Belegverweis: der Verweis ist der ganze Link (link), nicht die Dokument-ID (F88)', 'KM002');
        END IF;
        INSERT INTO kern.belegverweis (buchung, link, md5, sha256, art, zugeordnet_von)
             VALUES (p_id, kern._beleg_link(beleg->>'link'), lower(beleg->>'md5'), lower(beleg->>'sha256'),
                     'festschreibung', p_subjekt);
    END LOOP;
END $$;

DROP FUNCTION kern.beleg_nachreichen(text, uuid, integer, text, text);
CREATE FUNCTION kern.beleg_nachreichen(p_subjekt text, p_buchung uuid, p_link text, p_md5 text, p_sha256 text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    PERFORM 1 FROM kern.buchung WHERE id = p_buchung FOR SHARE;
    IF (p_md5 IS NULL OR p_sha256 IS NULL) AND (SELECT betriebsart FROM kern.buch) = 'produktiv' THEN
        PERFORM kern.verweigern('Belegverweis ohne Prüfsummen gibt es nur im Testbetrieb (F10, F88)', 'KM002');
    END IF;
    IF EXISTS (SELECT 1 FROM kern.belegverweis WHERE buchung = p_buchung AND link = p_link) THEN
        PERFORM kern.verweigern('Dieser Beleg ist der Buchung schon zugeordnet', 'KM003');
    END IF;
    INSERT INTO kern.belegverweis (buchung, link, md5, sha256, art, zugeordnet_von)
         VALUES (p_buchung, kern._beleg_link(p_link), lower(p_md5), lower(p_sha256), 'nachgereicht', p_subjekt);
    PERFORM kern._protokoll('beleg.nachgereicht', p_subjekt, jsonb_build_object('buchung', p_buchung::text), NULL,
        jsonb_build_object('link', p_link, 'md5', lower(p_md5), 'sha256', lower(p_sha256)));
END $$;

-- Im Produktivbetrieb wird keine Buchung mit ungeprüftem Beleg vorbereitet (und damit nie festgeschrieben).
CREATE FUNCTION kern.tg_beleg_geprueft() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
BEGIN
    IF (SELECT betriebsart FROM kern.buch) = 'produktiv'
       AND EXISTS (SELECT 1 FROM kern.belegverweis v WHERE v.buchung = NEW.id AND v.md5 IS NULL) THEN
        PERFORM kern.verweigern('Beleg ungeprüft: Im Produktivbetrieb braucht jeder Belegverweis MD5 und SHA-256 des Originals (F10, F88)', 'KM002');
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER beleg_geprueft BEFORE UPDATE OF status ON kern.buchung
    FOR EACH ROW WHEN (NEW.status IN ('freigabebereit', 'festgeschrieben') AND OLD.status IS DISTINCT FROM NEW.status)
    EXECUTE FUNCTION kern.tg_beleg_geprueft();

-- Alle zu einer Buchung gehörenden Verweise, auch nachgereichte (F27), für die Anzeige.
CREATE FUNCTION kern.buchung_belege(p_id uuid) RETURNS jsonb
LANGUAGE sql STABLE AS $$
    SELECT coalesce(jsonb_agg(kern._beleg_json(v) || jsonb_build_object(
                   'art', v.art::text, 'zugeordnet_am', to_char(v.zugeordnet_am AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"'),
                   'zugeordnet_von', v.zugeordnet_von)
               ORDER BY v.zugeordnet_am, v.link COLLATE "C" NULLS FIRST, v.paperless_id), '[]')
      FROM kern.belegverweis v WHERE v.buchung = p_id
$$;

REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA kern FROM PUBLIC;
GRANT EXECUTE ON FUNCTION kern.beleg_nachreichen(text, uuid, text, text, text) TO kumero_app;
GRANT EXECUTE ON FUNCTION kern._beleg_json(kern.belegverweis), kern.buchung_belege(uuid) TO kumero_app, kumero_lesen;
