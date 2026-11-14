-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Kern, Migration 0006: Schreibfunktionen (DDL Teil 2)
--
-- Alle schreibenden Zugriffe der Anwendung laufen über diese Funktionen (F64).
-- Sie sind SECURITY DEFINER (Eigentümer kumero_owner), schreiben in derselben
-- Transaktion ihren Protokolleintrag (F48) und halten die Sperrreihenfolge
-- nach F71 ein:  Buch → Buchungen → Freigabe → Geschäftsjahr → Nummernkreis-Jahr
--                → Kettenkopf 1 → Kettenkopf 2
--
-- Das Subjekt (wer handelt) übergibt die Anwendung; Authentisierung und
-- RBAC-Entscheidung liegen bei ihr (F72).
--
-- Zusätzlicher Fehlercode:  KM005  Konflikt (ETag/If-Match, F19)

-- ===========================================================================
-- Hilfen (nicht für die Anwendung freigegeben)
-- ===========================================================================
CREATE FUNCTION kern._subjekt(p text) RETURNS text
LANGUAGE plpgsql IMMUTABLE AS $$
BEGIN
    IF p IS NULL OR length(trim(p)) = 0 THEN
        PERFORM kern.verweigern('Subjekt fehlt', 'KM002');
    END IF;
    RETURN p;
END $$;

-- Schreibbarkeit prüfen und Buch teilend sperren (Anfang der Sperrreihenfolge).
CREATE FUNCTION kern._schreibbar() RETURNS kern.buch
LANGUAGE plpgsql AS $$
DECLARE b kern.buch;
BEGIN
    SELECT * INTO b FROM kern.buch FOR SHARE;
    IF NOT FOUND THEN
        PERFORM kern.verweigern('Buch ist noch nicht angelegt', 'KM003');
    END IF;
    IF b.lesemodus THEN
        PERFORM kern.verweigern(format('Mandant ist im Lesemodus: %s (F60, F69)', b.lesemodus_grund), 'KM003');
    END IF;
    RETURN b;
END $$;

-- Protokolleintrag (Kette 2) und schmales externes Ereignis (Outbox, F48).
CREATE FUNCTION kern._protokoll(p_typ text, p_subjekt text, p_ids jsonb,
                                p_vorher jsonb DEFAULT NULL, p_nachher jsonb DEFAULT NULL,
                                p_freigabe uuid DEFAULT NULL) RETURNS bigint
LANGUAGE plpgsql AS $$
DECLARE n bigint;
BEGIN
    INSERT INTO kern.protokoll (typ, subjekt, ids, vorher, nachher, freigabe)
         VALUES (p_typ, p_subjekt, coalesce(p_ids, '{}'), p_vorher, p_nachher, p_freigabe)
      RETURNING nr INTO n;
    INSERT INTO kern.outbox (protokoll_nr, typ, inhalt)
         VALUES (n, p_typ, coalesce(p_ids, '{}'));
    INSERT INTO kern.zustellung (abo, protokoll_nr)
         SELECT a.id, n FROM kern.webhook_abo a
          WHERE a.aktiv AND (p_typ = ANY (a.ereignisse) OR '*' = ANY (a.ereignisse));
    RETURN n;
END $$;

-- Dezimal-String prüfen (F19): nur Ziffern, Punkt, höchstens n Nachkommastellen.
CREATE FUNCTION kern._dezimal_lesen(p jsonb, feld text, nachkomma integer) RETURNS numeric
LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE t text;
BEGIN
    IF p IS NULL OR jsonb_typeof(p) = 'null' THEN
        RETURN NULL;
    END IF;
    t := p #>> '{}';
    IF t !~ ('^[0-9]{1,15}(\.[0-9]{1,' || nachkomma || '})?$') THEN
        PERFORM kern.verweigern(format('%s: "%s" ist kein Dezimal-String mit höchstens %s Nachkommastellen (F19)',
                                       feld, t, nachkomma), 'KM002');
    END IF;
    RETURN t::numeric;
END $$;

-- ===========================================================================
-- Inhalt einer Buchung in kanonierbarer Form (F29)
--   Freigabe-Inhalt: alles, was der Nutzer sieht und freigibt (ohne Kettenfelder)
--   Kettenglied:     Freigabe-Inhalt + Festschreibungsdaten + Freigabe + Prüfvermerke
-- Konten erscheinen mit Nummer und Kontoart (nicht Bezeichnung), Tags mit Name,
-- Buchungsart/Nummernkreis mit Kürzel (siehe db/README.md, Festlegung 3).
-- ===========================================================================
CREATE FUNCTION kern.buchung_inhalt(p_id uuid) RETURNS jsonb
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
        'belege',        coalesce((SELECT jsonb_agg(jsonb_build_object(
                                              'paperless_id', v.paperless_id, 'md5', v.md5, 'sha256', v.sha256)
                                          ORDER BY v.paperless_id)
                                     FROM kern.belegverweis v
                                    WHERE v.buchung = b.id AND v.art = 'festschreibung'), '[]'))
      FROM kern.buchung b
      LEFT JOIN kern.buchungsart ba ON ba.id = b.buchungsart
      LEFT JOIN kern.nummernkreis nk ON nk.id = b.nummernkreis
     WHERE b.id = p_id
$$;

CREATE FUNCTION kern.inhalt_hash(p jsonb) RETURNS text
LANGUAGE sql IMMUTABLE STRICT RETURN kern.sha256_hex(kern.jcs(p));

CREATE FUNCTION kern.buchung_freigabe_hash(p_id uuid) RETURNS text
LANGUAGE sql STABLE RETURN kern.inhalt_hash(kern.buchung_inhalt(p_id));

-- ETag eines Entwurfs (F19): Inhalt + Status
CREATE FUNCTION kern.entwurf_etag(p_id uuid) RETURNS text
LANGUAGE sql STABLE RETURN kern.inhalt_hash(
    kern.buchung_inhalt(p_id) || jsonb_build_object('status', (SELECT status::text FROM kern.buchung WHERE id = p_id)));

-- Kettenglied aus ausdrücklich übergebenen Festschreibungsdaten (damit der Hash
-- in derselben Anweisung wie die Festschreibung gebildet werden kann).
CREATE FUNCTION kern._kettenglied(p_id uuid, p_freigabe_hash text, p_journal_nr bigint, p_nr text,
                                  p_nr_zahl bigint, p_gj uuid, p_am timestamptz, p_von text,
                                  p_testbetrieb boolean, p_freigabe uuid) RETURNS jsonb
LANGUAGE sql STABLE AS $$
    SELECT jsonb_build_object(
        'inhalt',              kern.buchung_inhalt(p_id),
        'freigabe_hash',       p_freigabe_hash,
        'journal_nr',          p_journal_nr,
        'nr',                  p_nr,
        'nr_zahl',             p_nr_zahl,
        'geschaeftsjahr',      (SELECT kennung FROM kern.geschaeftsjahr WHERE id = p_gj),
        'festgeschrieben_am',  kern.zeitpunkt(p_am),
        'festgeschrieben_von', p_von,
        'testbetrieb',         p_testbetrieb,
        'freigabe',            (SELECT jsonb_build_object(
                                   'id',          f.id::text,
                                   'challenge',   c.wert,
                                   'inhalt_hash', f.inhalt_hash,
                                   'credential',  encode(cr.credential_id, 'hex'),
                                   'signatur',    encode(f.signatur, 'hex'))
                                  FROM kern.freigabe f
                                  JOIN kern.challenge c ON c.id = f.challenge
                                  JOIN kern.credential cr ON cr.id = f.credential
                                 WHERE f.id = p_freigabe),
        'pruefvermerke',       coalesce((SELECT jsonb_agg(jsonb_build_object(
                                              'modul', p.modul, 'version', p.modulversion,
                                              'punkt', p.erweiterungspunkt, 'ergebnis', p.ergebnis,
                                              'meldung', p.meldung)
                                          ORDER BY p.modul COLLATE "C", p.erweiterungspunkt COLLATE "C")
                                     FROM kern.pruefvermerk p WHERE p.buchung = p_id), '[]'))
$$;

CREATE FUNCTION kern.buchung_kettenglied(p_id uuid) RETURNS jsonb
LANGUAGE sql STABLE AS $$
    SELECT kern._kettenglied(b.id, b.freigabe_hash, b.journal_nr, b.nr, b.nr_zahl, b.geschaeftsjahr,
                             b.festgeschrieben_am, b.festgeschrieben_von, b.testbetrieb, b.freigabe)
      FROM kern.buchung b WHERE b.id = p_id
$$;

-- Stapel-Hash für eine geordnete Liste von Buchungen (F67)
CREATE FUNCTION kern.stapel_hash(p_buchungen uuid[]) RETURNS text
LANGUAGE sql STABLE AS $$
    SELECT kern.inhalt_hash(coalesce(jsonb_agg(jsonb_build_object(
               'pos', a.pos, 'buchung', a.id::text, 'freigabe_hash', b.freigabe_hash) ORDER BY a.pos), '[]'))
      FROM unnest(p_buchungen) WITH ORDINALITY AS a(id, pos)
      JOIN kern.buchung b ON b.id = a.id
$$;

-- Nummer formatieren (F25):
--   {JJJJ}/{JJ} = Jahr des Geschäftsjahresbeginns, {MM} = Monat des Buchungsdatums,
--   {NR:n} = Zahl mit mindestens n Stellen, {NR} = Zahl
CREATE FUNCTION kern.nr_formatieren(p_vorlage text, p_zahl bigint, p_gj_beginn date, p_buchungsdatum date)
RETURNS text LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE
    r text := p_vorlage;
    stellen integer;
    z text := p_zahl::text;
BEGIN
    r := replace(r, '{JJJJ}', to_char(p_gj_beginn, 'YYYY'));
    r := replace(r, '{JJ}',   to_char(p_gj_beginn, 'YY'));
    r := replace(r, '{MM}',   to_char(p_buchungsdatum, 'MM'));
    stellen := substring(r FROM '\{NR:([0-9]+)\}')::integer;
    IF stellen IS NOT NULL THEN
        r := regexp_replace(r, '\{NR:[0-9]+\}', CASE WHEN length(z) >= stellen THEN z ELSE lpad(z, stellen, '0') END);
    END IF;
    RETURN replace(r, '{NR}', z);
END $$;

-- ===========================================================================
-- Freigabe einlösen (F38, F67, F68): prüft die Bindung in der Datenbank.
-- Die kryptografische WebAuthn-Prüfung (Origin, RP-ID, UP/UV, Signatur) macht
-- die Anwendung und liefert ihr Ergebnis in "pruefergebnis" mit "ok": true.
-- p_freigabe = NULL ist nur im Testbetrieb erlaubt (F59).
-- ===========================================================================
CREATE FUNCTION kern._freigabe_einloesen(p_subjekt text, p_art kern.handlung, p_inhalt_hash text,
                                         p_freigabe jsonb, p_pflicht boolean DEFAULT false)
RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE
    buch    kern.buch;
    ch      kern.challenge;
    cr      kern.credential;
    zaehler bigint;
    fid     uuid;
BEGIN
    SELECT * INTO buch FROM kern.buch;
    IF p_freigabe IS NULL THEN
        IF buch.betriebsart = 'produktiv' OR p_pflicht THEN
            PERFORM kern.verweigern(format('Für "%s" ist eine persönliche Freigabe per WebAuthn erforderlich (F38)', p_art), 'KM003');
        END IF;
        RETURN NULL;
    END IF;

    SELECT * INTO ch FROM kern.challenge WHERE id = (p_freigabe->>'challenge')::uuid FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM kern.verweigern('Challenge unbekannt (F68)', 'KM003');
    END IF;
    IF ch.verbraucht_am IS NOT NULL THEN
        PERFORM kern.verweigern('Challenge wurde bereits verwendet (F68)', 'KM003');
    END IF;
    IF ch.ablauf <= clock_timestamp() THEN
        PERFORM kern.verweigern('Challenge ist abgelaufen (F68)', 'KM003');
    END IF;
    IF ch.subjekt <> p_subjekt OR ch.art <> p_art THEN
        PERFORM kern.verweigern('Challenge gehört zu einem anderen Subjekt oder einer anderen Handlung (F68)', 'KM003');
    END IF;
    IF ch.inhalt_hash <> p_inhalt_hash THEN
        PERFORM kern.verweigern('Inhalt hat sich seit der Freigabe geändert – neue Freigabe erforderlich (F66, F68)', 'KM003');
    END IF;

    SELECT * INTO cr FROM kern.credential WHERE id = (p_freigabe->>'credential')::uuid FOR UPDATE;
    IF NOT FOUND OR cr.subjekt <> p_subjekt THEN
        PERFORM kern.verweigern('Credential gehört nicht zum Subjekt (F68)', 'KM003');
    END IF;
    IF cr.widerrufen_am IS NOT NULL THEN
        PERFORM kern.verweigern('Credential ist widerrufen (F38)', 'KM003');
    END IF;
    IF coalesce((p_freigabe->'pruefergebnis'->>'ok')::boolean, false) IS NOT TRUE THEN
        PERFORM kern.verweigern('Die Anwendung hat die WebAuthn-Prüfung nicht bestätigt (F68, F72)', 'KM003');
    END IF;
    zaehler := coalesce((p_freigabe->>'sign_count')::bigint, 0);
    IF (cr.sign_count > 0 OR zaehler > 0) AND zaehler <= cr.sign_count THEN
        PERFORM kern.verweigern('Signaturzähler ist nicht gestiegen – möglicherweise geklonter Authentifikator (F68)', 'KM003');
    END IF;

    UPDATE kern.challenge  SET verbraucht_am = clock_timestamp() WHERE id = ch.id;
    UPDATE kern.credential SET sign_count = zaehler WHERE id = cr.id AND zaehler <> cr.sign_count;

    INSERT INTO kern.freigabe (art, subjekt, credential, challenge, inhalt_hash,
                               authenticator_data, client_data_json, signatur, sign_count, pruefergebnis)
         VALUES (p_art, p_subjekt, cr.id, ch.id, p_inhalt_hash,
                 decode(p_freigabe->>'authenticator_data', 'hex'),
                 decode(p_freigabe->>'client_data_json', 'hex'),
                 decode(p_freigabe->>'signatur', 'hex'),
                 zaehler, p_freigabe->'pruefergebnis')
      RETURNING id INTO fid;
    RETURN fid;
END $$;

-- ===========================================================================
-- Stammdaten
-- ===========================================================================
CREATE FUNCTION kern.buch_anlegen(p_subjekt text, p_name text, p_buchwaehrung text, p_sprache text DEFAULT 'de')
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
BEGIN
    PERFORM kern._subjekt(p_subjekt);
    INSERT INTO kern.buch (name, buchwaehrung, sprache) VALUES (p_name, p_buchwaehrung, p_sprache);
    PERFORM kern._protokoll('buch.angelegt', p_subjekt, '{}',
        NULL, jsonb_build_object('name', p_name, 'buchwaehrung', p_buchwaehrung, 'sprache', p_sprache));
END $$;

CREATE FUNCTION kern.konto_anlegen(p_subjekt text, p_nr text, p_bezeichnung text, p_kontoart kern.kontoart)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE kid uuid;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    INSERT INTO kern.konto (nr, bezeichnung, kontoart) VALUES (p_nr, p_bezeichnung, p_kontoart) RETURNING id INTO kid;
    PERFORM kern._protokoll('konto.angelegt', p_subjekt, jsonb_build_object('konto', kid::text), NULL,
        jsonb_build_object('nr', p_nr, 'bezeichnung', p_bezeichnung, 'kontoart', p_kontoart::text, 'aktiv', true));
    RETURN kid;
END $$;

CREATE FUNCTION kern.konto_aendern(p_subjekt text, p_nr text, p_bezeichnung text DEFAULT NULL,
                                   p_aktiv boolean DEFAULT NULL, p_kontoart kern.kontoart DEFAULT NULL)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE alt kern.konto; neu kern.konto;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT * INTO alt FROM kern.konto WHERE nr = p_nr FOR UPDATE;
    IF NOT FOUND THEN PERFORM kern.verweigern(format('Konto %s unbekannt', p_nr), 'KM002'); END IF;
    UPDATE kern.konto SET bezeichnung = coalesce(p_bezeichnung, bezeichnung),
                          aktiv       = coalesce(p_aktiv, aktiv),
                          kontoart    = coalesce(p_kontoart, kontoart)
     WHERE id = alt.id RETURNING * INTO neu;
    PERFORM kern._protokoll('konto.geaendert', p_subjekt, jsonb_build_object('konto', alt.id::text),
        jsonb_build_object('nr', alt.nr, 'bezeichnung', alt.bezeichnung, 'kontoart', alt.kontoart::text, 'aktiv', alt.aktiv),
        jsonb_build_object('nr', neu.nr, 'bezeichnung', neu.bezeichnung, 'kontoart', neu.kontoart::text, 'aktiv', neu.aktiv));
END $$;

CREATE FUNCTION kern.tag_anlegen(p_subjekt text, p_name text, p_paperless_id integer DEFAULT NULL)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE tid uuid;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    INSERT INTO kern.tag (name, herkunft, paperless_id)
         VALUES (p_name, CASE WHEN p_paperless_id IS NULL THEN 'manuell' ELSE 'paperless' END, p_paperless_id)
      RETURNING id INTO tid;
    PERFORM kern._protokoll('tag.angelegt', p_subjekt, jsonb_build_object('tag', tid::text), NULL,
        jsonb_build_object('name', p_name, 'paperless_id', p_paperless_id));
    RETURN tid;
END $$;

CREATE FUNCTION kern.nummernkreis_anlegen(p_subjekt text, p_kuerzel text, p_bezeichnung text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE nid uuid;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    INSERT INTO kern.nummernkreis (kuerzel, bezeichnung) VALUES (p_kuerzel, p_bezeichnung) RETURNING id INTO nid;
    PERFORM kern._protokoll('nummernkreis.angelegt', p_subjekt, jsonb_build_object('nummernkreis', nid::text), NULL,
        jsonb_build_object('kuerzel', p_kuerzel, 'bezeichnung', p_bezeichnung));
    RETURN nid;
END $$;

CREATE FUNCTION kern.buchungsart_anlegen(p_subjekt text, p_kuerzel text, p_bezeichnung text, p_nummernkreis text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE aid uuid; nid uuid;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT id INTO nid FROM kern.nummernkreis WHERE kuerzel = p_nummernkreis;
    IF NOT FOUND THEN PERFORM kern.verweigern(format('Nummernkreis %s unbekannt', p_nummernkreis), 'KM002'); END IF;
    INSERT INTO kern.buchungsart (kuerzel, bezeichnung, standard_nummernkreis)
         VALUES (p_kuerzel, p_bezeichnung, nid) RETURNING id INTO aid;
    PERFORM kern._protokoll('buchungsart.angelegt', p_subjekt, jsonb_build_object('buchungsart', aid::text), NULL,
        jsonb_build_object('kuerzel', p_kuerzel, 'bezeichnung', p_bezeichnung, 'nummernkreis', p_nummernkreis));
    RETURN aid;
END $$;

CREATE FUNCTION kern.geschaeftsjahr_anlegen(p_subjekt text, p_kennung text, p_beginn date, p_ende date)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE gid uuid;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    IF p_ende < p_beginn THEN
        PERFORM kern.verweigern('Ende liegt vor dem Beginn', 'KM002');
    END IF;
    INSERT INTO kern.geschaeftsjahr (kennung, zeitraum)
         VALUES (p_kennung, daterange(p_beginn, p_ende + 1, '[)')) RETURNING id INTO gid;
    PERFORM kern._protokoll('geschaeftsjahr.angelegt', p_subjekt, jsonb_build_object('geschaeftsjahr', gid::text), NULL,
        jsonb_build_object('kennung', p_kennung, 'beginn', to_char(p_beginn, 'YYYY-MM-DD'), 'ende', to_char(p_ende, 'YYYY-MM-DD')));
    RETURN gid;
END $$;

-- Nummernkreis für ein Geschäftsjahr festlegen (F25). Fortlaufend (p_zuruecksetzen = false):
-- Startwert schließt an den Zählerstand des vorangehenden Geschäftsjahres an.
CREATE FUNCTION kern.nummernkreis_festlegen(p_subjekt text, p_kreis text, p_gj text, p_vorlage text, p_zuruecksetzen boolean)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE
    nk kern.nummernkreis; gj kern.geschaeftsjahr; alt kern.nummernkreis_jahr; start bigint := 1;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT * INTO nk FROM kern.nummernkreis WHERE kuerzel = p_kreis;
    IF NOT FOUND THEN PERFORM kern.verweigern(format('Nummernkreis %s unbekannt', p_kreis), 'KM002'); END IF;
    SELECT * INTO gj FROM kern.geschaeftsjahr WHERE kennung = p_gj FOR SHARE;
    IF NOT FOUND THEN PERFORM kern.verweigern(format('Geschäftsjahr %s unbekannt', p_gj), 'KM002'); END IF;
    IF NOT p_zuruecksetzen THEN
        SELECT coalesce(max(CASE WHEN j.zaehler > 0 THEN j.zaehler ELSE j.startwert - 1 END), 0) + 1 INTO start
          FROM kern.nummernkreis_jahr j JOIN kern.geschaeftsjahr g ON g.id = j.geschaeftsjahr_id
         WHERE j.nummernkreis_id = nk.id AND upper(g.zeitraum) <= lower(gj.zeitraum);
    END IF;
    SELECT * INTO alt FROM kern.nummernkreis_jahr WHERE nummernkreis_id = nk.id AND geschaeftsjahr_id = gj.id FOR UPDATE;
    IF FOUND THEN
        UPDATE kern.nummernkreis_jahr SET vorlage = p_vorlage, zuruecksetzen = p_zuruecksetzen, startwert = start,
                                          festgelegt_am = now(), festgelegt_von = p_subjekt
         WHERE nummernkreis_id = nk.id AND geschaeftsjahr_id = gj.id;
    ELSE
        INSERT INTO kern.nummernkreis_jahr (nummernkreis_id, geschaeftsjahr_id, vorlage, zuruecksetzen, startwert, festgelegt_von)
             VALUES (nk.id, gj.id, p_vorlage, p_zuruecksetzen, start, p_subjekt);
    END IF;
    PERFORM kern._protokoll('nummernkreis.festgelegt', p_subjekt,
        jsonb_build_object('nummernkreis', nk.id::text, 'geschaeftsjahr', gj.id::text),
        CASE WHEN alt IS NULL THEN NULL ELSE jsonb_build_object('vorlage', alt.vorlage, 'zuruecksetzen', alt.zuruecksetzen, 'startwert', alt.startwert) END,
        jsonb_build_object('vorlage', p_vorlage, 'zuruecksetzen', p_zuruecksetzen, 'startwert', start));
END $$;

CREATE FUNCTION kern.modul_registrieren(p_subjekt text, p_name text, p_version text, p_erweiterungspunkte text[] DEFAULT '{}')
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE alt kern.modul;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT * INTO alt FROM kern.modul WHERE name = p_name FOR UPDATE;
    INSERT INTO kern.modul (name, version, erweiterungspunkte) VALUES (p_name, p_version, p_erweiterungspunkte)
        ON CONFLICT (name) DO UPDATE SET version = EXCLUDED.version, erweiterungspunkte = EXCLUDED.erweiterungspunkte, aktiv = true;
    PERFORM kern._protokoll('modul.registriert', p_subjekt, jsonb_build_object('modul', p_name),
        CASE WHEN alt IS NULL THEN NULL ELSE jsonb_build_object('version', alt.version, 'erweiterungspunkte', to_jsonb(alt.erweiterungspunkte), 'aktiv', alt.aktiv) END,
        jsonb_build_object('version', p_version, 'erweiterungspunkte', to_jsonb(p_erweiterungspunkte), 'aktiv', true));
END $$;

CREATE FUNCTION kern.erweiterungsattribut_anmelden(p_subjekt text, p_schluessel text, p_ebene text, p_datentyp text,
                                                   p_pflicht boolean DEFAULT false, p_auswahlwerte text[] DEFAULT NULL)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    INSERT INTO kern.erweiterungsattribut (schluessel, modul, ebene, datentyp, pflicht, auswahlwerte)
         VALUES (p_schluessel, split_part(p_schluessel, '.', 1), p_ebene, p_datentyp, p_pflicht, p_auswahlwerte);
    PERFORM kern._protokoll('erweiterungsattribut.angemeldet', p_subjekt, jsonb_build_object('schluessel', p_schluessel), NULL,
        jsonb_build_object('ebene', p_ebene, 'datentyp', p_datentyp, 'pflicht', p_pflicht, 'auswahlwerte', to_jsonb(p_auswahlwerte)));
END $$;

-- ===========================================================================
-- Freigabe-Credentials und Challenges (F38, F68)
-- ===========================================================================
CREATE FUNCTION kern.credential_registrieren(p_subjekt text, p_inhaber text, p_credential_id bytea,
                                             p_public_key bytea, p_algorithmus integer, p_bezeichnung text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE cid uuid;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    INSERT INTO kern.credential (subjekt, credential_id, public_key, algorithmus, bezeichnung)
         VALUES (kern._subjekt(p_inhaber), p_credential_id, p_public_key, p_algorithmus, p_bezeichnung)
      RETURNING id INTO cid;
    PERFORM kern._protokoll('credential.registriert', p_subjekt, jsonb_build_object('credential', cid::text), NULL,
        jsonb_build_object('inhaber', p_inhaber, 'credential_id', encode(p_credential_id, 'hex'),
                           'public_key', encode(p_public_key, 'hex'), 'algorithmus', p_algorithmus, 'bezeichnung', p_bezeichnung));
    RETURN cid;
END $$;

CREATE FUNCTION kern.credential_widerrufen(p_subjekt text, p_credential uuid, p_grund text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    UPDATE kern.credential SET widerrufen_am = now() WHERE id = p_credential AND widerrufen_am IS NULL;
    IF NOT FOUND THEN PERFORM kern.verweigern('Credential unbekannt oder bereits widerrufen', 'KM003'); END IF;
    PERFORM kern._protokoll('credential.widerrufen', p_subjekt, jsonb_build_object('credential', p_credential::text), NULL,
        jsonb_build_object('grund', p_grund));
END $$;

-- Challenge ausstellen. Den Zufallswert (≥ 32 Byte) liefert die Anwendung aus
-- ihrem kryptografischen Zufallsgenerator.
CREATE FUNCTION kern.challenge_ausstellen(p_subjekt text, p_art kern.handlung, p_inhalt_hash text, p_mandant text,
                                          p_zufall bytea, p_gueltig interval DEFAULT '5 minutes')
RETURNS TABLE (o_id uuid, o_wert text, o_ablauf timestamptz)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE
    ab timestamptz := date_trunc('second', clock_timestamp() + p_gueltig);
    w  text;
    cid uuid;
BEGIN
    PERFORM kern._subjekt(p_subjekt);
    IF p_gueltig > interval '15 minutes' OR p_gueltig < interval '30 seconds' THEN
        PERFORM kern.verweigern('Gültigkeit einer Challenge: 30 Sekunden bis 15 Minuten', 'KM002');
    END IF;
    w := kern.inhalt_hash(jsonb_build_object(
            'ablauf', kern.zeitpunkt(ab), 'art', p_art::text, 'inhalt_hash', p_inhalt_hash,
            'mandant', p_mandant, 'subjekt', p_subjekt, 'zufall', encode(p_zufall, 'hex')));
    INSERT INTO kern.challenge (zufall, ablauf, mandant, art, subjekt, inhalt_hash, wert)
         VALUES (p_zufall, ab, p_mandant, p_art, p_subjekt, p_inhalt_hash, w) RETURNING id INTO cid;
    RETURN QUERY SELECT cid, w, ab;
END $$;

-- ===========================================================================
-- Entwürfe (F31, F62)
-- ===========================================================================
-- Inhalt eines Entwurfs aus JSON setzen (ersetzt Zeilen, Werte und Belege).
-- Format: {buchungsart, nummernkreis?, belegdatum, buchungsdatum, belegnummer, text,
--          erweiterung: {schluessel: wert}, belege: [{paperless_id, md5, sha256}],
--          zeilen: [{konto, seite, betrag, fw_betrag, fw_waehrung, kurs, kurs_datum, kurs_art,
--                    tags: [name], erweiterung: {schluessel: wert}}]}
CREATE FUNCTION kern._entwurf_setzen(p_id uuid, d jsonb, p_subjekt text) RETURNS void
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
        INSERT INTO kern.belegverweis (buchung, paperless_id, md5, sha256, art, zugeordnet_von)
             VALUES (p_id, (beleg->>'paperless_id')::integer, lower(beleg->>'md5'), lower(beleg->>'sha256'),
                     'festschreibung', p_subjekt);
    END LOOP;
END $$;

CREATE FUNCTION kern.entwurf_anlegen(p_subjekt text, p_daten jsonb, p_herkunft kern.herkunft DEFAULT 'manuell',
                                     p_herkunft_ref text DEFAULT NULL)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE bid uuid;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    INSERT INTO kern.buchung (erfasst_von, herkunft, herkunft_ref) VALUES (p_subjekt, p_herkunft, p_herkunft_ref)
      RETURNING id INTO bid;
    PERFORM kern._entwurf_setzen(bid, p_daten, p_subjekt);
    PERFORM kern._protokoll('buchung.entwurf_angelegt', p_subjekt, jsonb_build_object('buchung', bid::text),
                            NULL, kern.buchung_inhalt(bid));
    RETURN bid;
END $$;

-- Ändert einen Entwurf. Ist er bereits freigabebereit, wird er auf Entwurf
-- zurückgesetzt (F66). p_etag: erwarteter ETag (If-Match, F19), optional.
CREATE FUNCTION kern.entwurf_aendern(p_subjekt text, p_id uuid, p_daten jsonb, p_etag text DEFAULT NULL)
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE b kern.buchung; vorher jsonb;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT * INTO b FROM kern.buchung WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM kern.verweigern('Buchung unbekannt', 'KM002'); END IF;
    IF b.status = 'festgeschrieben' THEN
        PERFORM kern.verweigern('Festgeschriebene Buchungen werden nur per Storno korrigiert (F30)', 'KM003');
    END IF;
    IF p_etag IS NOT NULL AND p_etag <> kern.entwurf_etag(p_id) THEN
        PERFORM kern.verweigern('Der Entwurf wurde zwischenzeitlich geändert (ETag)', 'KM005');
    END IF;
    vorher := kern.buchung_inhalt(p_id) || jsonb_build_object('status', b.status::text);
    IF b.status = 'freigabebereit' THEN
        PERFORM kern._zuruecksetzen(p_id);
    END IF;
    PERFORM kern._entwurf_setzen(p_id, p_daten, p_subjekt);
    PERFORM kern._protokoll('buchung.entwurf_geaendert', p_subjekt, jsonb_build_object('buchung', p_id::text),
                            vorher, kern.buchung_inhalt(p_id) || jsonb_build_object('status', 'entwurf'));
    RETURN kern.entwurf_etag(p_id);
END $$;

CREATE FUNCTION kern.entwurf_loeschen(p_subjekt text, p_id uuid, p_etag text DEFAULT NULL)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE b kern.buchung; vorher jsonb;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT * INTO b FROM kern.buchung WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM kern.verweigern('Buchung unbekannt', 'KM002'); END IF;
    IF b.status = 'festgeschrieben' THEN
        PERFORM kern.verweigern('Festgeschriebene Buchungen werden nicht gelöscht (F30)', 'KM003');
    END IF;
    IF p_etag IS NOT NULL AND p_etag <> kern.entwurf_etag(p_id) THEN
        PERFORM kern.verweigern('Der Entwurf wurde zwischenzeitlich geändert (ETag)', 'KM005');
    END IF;
    vorher := kern.buchung_inhalt(p_id) || jsonb_build_object('status', b.status::text);
    DELETE FROM kern.buchung WHERE id = p_id;
    PERFORM kern._protokoll('buchung.entwurf_geloescht', p_subjekt, jsonb_build_object('buchung', p_id::text), vorher, NULL);
END $$;

-- Prüfvermerk eines Moduls (F72). Anreichern/Vorprüfen nur im Entwurf,
-- "festschreiben_pruefen" nur bei freigabebereit (Trigger aus Teil 1).
CREATE FUNCTION kern.pruefvermerk_setzen(p_subjekt text, p_buchung uuid, p_modul text, p_version text,
                                         p_punkt text, p_ergebnis boolean, p_meldung text DEFAULT NULL)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE m kern.modul;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT * INTO m FROM kern.modul WHERE name = p_modul;
    IF NOT FOUND OR NOT m.aktiv OR m.version <> p_version THEN
        PERFORM kern.verweigern(format('Modul %s in Version %s ist nicht aktiv registriert (F72)', p_modul, p_version), 'KM003');
    END IF;
    IF NOT p_punkt = ANY (m.erweiterungspunkte) THEN
        PERFORM kern.verweigern(format('Modul %s hat den Erweiterungspunkt %s nicht angemeldet', p_modul, p_punkt), 'KM003');
    END IF;
    PERFORM 1 FROM kern.buchung WHERE id = p_buchung FOR UPDATE;
    DELETE FROM kern.pruefvermerk WHERE buchung = p_buchung AND modul = p_modul AND erweiterungspunkt = p_punkt;
    INSERT INTO kern.pruefvermerk (buchung, modul, modulversion, erweiterungspunkt, ergebnis, meldung)
         VALUES (p_buchung, p_modul, p_version, p_punkt, p_ergebnis, p_meldung);
    PERFORM kern._protokoll('buchung.pruefvermerk', p_subjekt, jsonb_build_object('buchung', p_buchung::text), NULL,
        jsonb_build_object('modul', p_modul, 'version', p_version, 'punkt', p_punkt, 'ergebnis', p_ergebnis, 'meldung', p_meldung));
END $$;

-- ===========================================================================
-- Buchungsregeln (F4, F12, F24, F58, F62) – streng, erst beim Vorbereiten
-- ===========================================================================
CREATE FUNCTION kern._regeln_pruefen(p_id uuid) RETURNS void
LANGUAGE plpgsql AS $$
DECLARE
    b      kern.buchung;
    r      record;
    anzahl integer;
    soll   numeric;
    haben  numeric;
    passt  boolean;
BEGIN
    SELECT * INTO b FROM kern.buchung WHERE id = p_id;
    IF b.buchungsart IS NULL OR b.nummernkreis IS NULL THEN
        PERFORM kern.verweigern('Buchungsart und Nummernkreis sind erforderlich', 'KM002');
    END IF;
    IF b.belegdatum IS NULL OR b.buchungsdatum IS NULL THEN
        PERFORM kern.verweigern('Beleg- und Buchungsdatum sind erforderlich', 'KM002');
    END IF;
    IF b.text IS NULL OR length(trim(b.text)) = 0 THEN
        PERFORM kern.verweigern('Buchungstext ist erforderlich', 'KM002');
    END IF;

    SELECT count(*),
           coalesce(sum(betrag) FILTER (WHERE seite = 'S'), 0),
           coalesce(sum(betrag) FILTER (WHERE seite = 'H'), 0)
      INTO anzahl, soll, haben
      FROM kern.buchungszeile WHERE buchung = p_id;
    IF anzahl < 2 THEN
        PERFORM kern.verweigern('Eine Buchung hat mindestens zwei Zeilen (F4)', 'KM002');
    END IF;
    FOR r IN SELECT z.pos, z.konto, z.seite, z.betrag, k.aktiv
               FROM kern.buchungszeile z LEFT JOIN kern.konto k ON k.id = z.konto
              WHERE z.buchung = p_id ORDER BY z.pos LOOP
        IF r.konto IS NULL OR r.seite IS NULL OR r.betrag IS NULL THEN
            PERFORM kern.verweigern(format('Zeile %s: Konto, Seite und Betrag sind erforderlich', r.pos), 'KM002');
        END IF;
        IF NOT r.aktiv THEN
            PERFORM kern.verweigern(format('Zeile %s: Konto ist deaktiviert', r.pos), 'KM002');
        END IF;
    END LOOP;
    IF NOT EXISTS (SELECT 1 FROM kern.buchungszeile WHERE buchung = p_id AND seite = 'S')
       OR NOT EXISTS (SELECT 1 FROM kern.buchungszeile WHERE buchung = p_id AND seite = 'H') THEN
        PERFORM kern.verweigern('Mindestens eine Soll- und eine Haben-Zeile (F4)', 'KM002');
    END IF;
    IF EXISTS (SELECT konto FROM kern.buchungszeile WHERE buchung = p_id
                GROUP BY konto HAVING count(DISTINCT seite) > 1) THEN
        PERFORM kern.verweigern('Ein Konto darf nicht im Soll und im Haben derselben Buchung stehen (F4)', 'KM002');
    END IF;
    IF soll <> haben THEN
        PERFORM kern.verweigern(format('Summe Soll (%s) ≠ Summe Haben (%s) (F4)', soll, haben), 'KM002');
    END IF;

    -- Erweiterungswerte: Pflicht und Datentyp (F58)
    FOR r IN SELECT a.schluessel, a.ebene, a.datentyp, a.auswahlwerte, a.pflicht
               FROM kern.erweiterungsattribut a JOIN kern.modul m ON m.name = a.modul AND m.aktiv LOOP
        IF r.pflicht THEN
            IF r.ebene = 'kopf' AND NOT EXISTS (SELECT 1 FROM kern.erweiterungswert
                                                 WHERE buchung = p_id AND zeile IS NULL AND schluessel = r.schluessel) THEN
                PERFORM kern.verweigern(format('Pflichtangabe %s fehlt (F58)', r.schluessel), 'KM002');
            END IF;
            IF r.ebene = 'zeile' AND EXISTS (SELECT 1 FROM kern.buchungszeile z WHERE z.buchung = p_id
                                                AND NOT EXISTS (SELECT 1 FROM kern.erweiterungswert w
                                                                 WHERE w.zeile = z.id AND w.schluessel = r.schluessel)) THEN
                PERFORM kern.verweigern(format('Pflichtangabe %s fehlt in einer Zeile (F58)', r.schluessel), 'KM002');
            END IF;
        END IF;
    END LOOP;
    FOR r IN SELECT w.schluessel, w.wert, a.datentyp, a.auswahlwerte
               FROM kern.erweiterungswert w JOIN kern.erweiterungsattribut a ON a.schluessel = w.schluessel
              WHERE w.buchung = p_id LOOP
        passt := CASE r.datentyp
                 WHEN 'text'          THEN true
                 WHEN 'dezimal'       THEN r.wert ~ '^-?[0-9]{1,15}(\.[0-9]{1,10})?$'
                 WHEN 'ganzzahl'      THEN r.wert ~ '^-?[0-9]{1,15}$'
                 WHEN 'wahrheitswert' THEN r.wert IN ('true', 'false')
                 WHEN 'datum'         THEN r.wert ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
                                           AND to_char(to_date(r.wert, 'YYYY-MM-DD'), 'YYYY-MM-DD') = r.wert
                 WHEN 'auswahl'       THEN r.wert = ANY (r.auswahlwerte)
               END;
        IF NOT passt THEN
            PERFORM kern.verweigern(format('%s: "%s" passt nicht zum Datentyp %s (F58)', r.schluessel, r.wert, r.datentyp), 'KM002');
        END IF;
    END LOOP;

    -- Storno (F30)
    IF b.storno_von IS NOT NULL AND NOT EXISTS (
           SELECT 1 FROM kern.buchung WHERE id = b.storno_von AND status = 'festgeschrieben') THEN
        PERFORM kern.verweigern('Storniert werden können nur festgeschriebene Buchungen (F30)', 'KM003');
    END IF;
END $$;

-- Pflicht-Prüfvermerke der aktiven Module (F72)
CREATE FUNCTION kern._pruefvermerke_vollstaendig(p_id uuid, p_punkt text) RETURNS void
LANGUAGE plpgsql AS $$
DECLARE r record;
BEGIN
    FOR r IN SELECT m.name, m.version, p.ergebnis, p.modulversion, p.meldung
               FROM kern.modul m
               LEFT JOIN kern.pruefvermerk p ON p.buchung = p_id AND p.modul = m.name AND p.erweiterungspunkt = p_punkt
              WHERE m.aktiv AND p_punkt = ANY (m.erweiterungspunkte) LOOP
        IF r.ergebnis IS NULL THEN
            PERFORM kern.verweigern(format('Prüfvermerk von Modul %s (%s) fehlt (F72)', r.name, p_punkt), 'KM003');
        ELSIF r.modulversion <> r.version THEN
            PERFORM kern.verweigern(format('Prüfvermerk von Modul %s stammt aus einer anderen Version (F72)', r.name), 'KM003');
        ELSIF NOT r.ergebnis THEN
            PERFORM kern.verweigern(format('Modul %s lehnt ab: %s', r.name, coalesce(r.meldung, '–')), 'KM002');
        END IF;
    END LOOP;
END $$;

-- ===========================================================================
-- Vorbereiten und Zurücksetzen (F66)
-- ===========================================================================
CREATE FUNCTION kern._zuruecksetzen(p_id uuid) RETURNS void
LANGUAGE plpgsql AS $$
BEGIN
    UPDATE kern.buchung SET status = 'entwurf', vorbereitet_am = NULL, vorbereitet_von = NULL, freigabe_hash = NULL
     WHERE id = p_id AND status = 'freigabebereit';
    DELETE FROM kern.pruefvermerk WHERE buchung = p_id;
END $$;

CREATE FUNCTION kern.vorbereiten(p_subjekt text, p_id uuid)
RETURNS text LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE b kern.buchung; h text;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT * INTO b FROM kern.buchung WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM kern.verweigern('Buchung unbekannt', 'KM002'); END IF;
    IF b.status = 'freigabebereit' THEN
        RETURN b.freigabe_hash;
    END IF;
    IF b.status <> 'entwurf' THEN
        PERFORM kern.verweigern(format('Buchung ist %s', b.status), 'KM003');
    END IF;
    PERFORM kern._regeln_pruefen(p_id);
    PERFORM kern._pruefvermerke_vollstaendig(p_id, 'vorbereiten_anreichern');
    PERFORM kern._pruefvermerke_vollstaendig(p_id, 'vorbereiten_pruefen');
    h := kern.buchung_freigabe_hash(p_id);
    UPDATE kern.buchung SET status = 'freigabebereit', vorbereitet_am = clock_timestamp(),
                            vorbereitet_von = p_subjekt, freigabe_hash = h
     WHERE id = p_id;
    PERFORM kern._protokoll('buchung.vorbereitet', p_subjekt, jsonb_build_object('buchung', p_id::text), NULL,
                            jsonb_build_object('freigabe_hash', h));
    RETURN h;
END $$;

CREATE FUNCTION kern.zuruecksetzen(p_subjekt text, p_id uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE b kern.buchung;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT * INTO b FROM kern.buchung WHERE id = p_id FOR UPDATE;
    IF NOT FOUND OR b.status <> 'freigabebereit' THEN
        PERFORM kern.verweigern('Nur freigabebereite Buchungen können zurückgesetzt werden', 'KM003');
    END IF;
    PERFORM kern._zuruecksetzen(p_id);
    PERFORM kern._protokoll('buchung.zurueckgesetzt', p_subjekt, jsonb_build_object('buchung', p_id::text),
                            jsonb_build_object('freigabe_hash', b.freigabe_hash), NULL);
END $$;

-- ===========================================================================
-- Festschreiben (F17, F31, F38, F66–F68, F71, F72)
-- ===========================================================================
CREATE FUNCTION kern.festschreiben(p_subjekt text, p_buchungen uuid[], p_freigabe jsonb DEFAULT NULL)
RETURNS TABLE (o_buchung uuid, o_journal_nr bigint, o_nr text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE
    buch     kern.buch;
    b        kern.buchung;
    art      kern.handlung;
    anzahl_storno integer;
    inhalt   text;
    fid      uuid;
    gj       kern.geschaeftsjahr;
    nkj      kern.nummernkreis_jahr;
    kopf     kern.kettenkopf;
    zahl     bigint;
    nummer   text;
    ts       timestamptz;
    i        integer;
BEGIN
    PERFORM kern._subjekt(p_subjekt);
    buch := kern._schreibbar();                                                  -- 1. Buch
    IF p_buchungen IS NULL OR cardinality(p_buchungen) = 0 THEN
        PERFORM kern.verweigern('Keine Buchungen angegeben', 'KM002');
    END IF;
    IF cardinality(p_buchungen) <> (SELECT count(DISTINCT x) FROM unnest(p_buchungen) x) THEN
        PERFORM kern.verweigern('Eine Buchung ist doppelt angegeben', 'KM002');
    END IF;

    -- 2. Buchungen (in fester Reihenfolge der IDs sperren, gegen Verklemmungen)
    PERFORM 1 FROM kern.buchung WHERE id = ANY (p_buchungen) ORDER BY id FOR UPDATE;
    IF (SELECT count(*) FROM kern.buchung WHERE id = ANY (p_buchungen)) <> cardinality(p_buchungen) THEN
        PERFORM kern.verweigern('Mindestens eine Buchung ist unbekannt', 'KM002');
    END IF;
    FOR b IN SELECT * FROM kern.buchung WHERE id = ANY (p_buchungen) LOOP
        IF b.status <> 'freigabebereit' THEN
            PERFORM kern.verweigern(format('Buchung %s ist nicht freigabebereit (Status %s, F66)', b.id, b.status), 'KM003');
        END IF;
        IF b.freigabe_hash <> kern.buchung_freigabe_hash(b.id) THEN
            PERFORM kern.verweigern(format('Inhalt von Buchung %s weicht vom freigegebenen Stand ab (F66)', b.id), 'KM003');
        END IF;
        PERFORM kern._pruefvermerke_vollstaendig(b.id, 'festschreiben_pruefen');
    END LOOP;

    -- 3. Freigabe (F38, F67, F68)
    SELECT count(*) FILTER (WHERE storno_von IS NOT NULL) INTO anzahl_storno FROM kern.buchung WHERE id = ANY (p_buchungen);
    IF anzahl_storno = 0 THEN
        art := 'festschreibung';
    ELSIF anzahl_storno = cardinality(p_buchungen) THEN
        art := 'storno';
    ELSE
        PERFORM kern.verweigern('Stornos und andere Buchungen werden getrennt freigegeben', 'KM003');
    END IF;
    inhalt := CASE WHEN cardinality(p_buchungen) = 1
                   THEN (SELECT freigabe_hash FROM kern.buchung WHERE id = p_buchungen[1])
                   ELSE kern.stapel_hash(p_buchungen) END;
    fid := kern._freigabe_einloesen(p_subjekt, art, inhalt, p_freigabe);
    IF fid IS NOT NULL THEN
        INSERT INTO kern.freigabe_position (freigabe, pos, buchung, freigabe_hash)
             SELECT fid, a.pos, a.id, x.freigabe_hash
               FROM unnest(p_buchungen) WITH ORDINALITY AS a(id, pos)
               JOIN kern.buchung x ON x.id = a.id;
    END IF;

    -- 4. Geschäftsjahre teilend sperren, Status unter Sperre prüfen (F71)
    FOR gj IN SELECT g.* FROM kern.geschaeftsjahr g
               WHERE EXISTS (SELECT 1 FROM kern.buchung x
                              WHERE x.id = ANY (p_buchungen) AND g.zeitraum @> x.buchungsdatum)
               ORDER BY g.id FOR SHARE LOOP
        IF gj.status <> 'offen' THEN
            PERFORM kern.verweigern(format('Geschäftsjahr %s ist gesperrt (F33)', gj.kennung), 'KM003');
        END IF;
    END LOOP;
    IF EXISTS (SELECT 1 FROM kern.buchung x WHERE x.id = ANY (p_buchungen)
                AND NOT EXISTS (SELECT 1 FROM kern.geschaeftsjahr g WHERE g.zeitraum @> x.buchungsdatum)) THEN
        PERFORM kern.verweigern('Für das Buchungsdatum ist kein Geschäftsjahr angelegt (F65)', 'KM003');
    END IF;

    -- 5. Nummernkreis-Jahre sperren
    PERFORM 1 FROM kern.nummernkreis_jahr j
      WHERE (j.nummernkreis_id, j.geschaeftsjahr_id) IN (
            SELECT x.nummernkreis, g.id FROM kern.buchung x JOIN kern.geschaeftsjahr g ON g.zeitraum @> x.buchungsdatum
             WHERE x.id = ANY (p_buchungen))
      ORDER BY j.nummernkreis_id, j.geschaeftsjahr_id FOR UPDATE;

    -- 6. Kettenkopf 1
    SELECT * INTO kopf FROM kern.kettenkopf WHERE kette = 1 FOR UPDATE;

    FOR i IN 1 .. cardinality(p_buchungen) LOOP
        SELECT * INTO b FROM kern.buchung WHERE id = p_buchungen[i];
        SELECT * INTO gj FROM kern.geschaeftsjahr WHERE zeitraum @> b.buchungsdatum;
        SELECT * INTO nkj FROM kern.nummernkreis_jahr
         WHERE nummernkreis_id = b.nummernkreis AND geschaeftsjahr_id = gj.id;
        IF NOT FOUND THEN
            PERFORM kern.verweigern(format('Nummernkreis ist für Geschäftsjahr %s nicht festgelegt (F25)', gj.kennung), 'KM003');
        END IF;
        zahl := CASE WHEN nkj.zaehler = 0 THEN nkj.startwert ELSE nkj.zaehler + 1 END;
        UPDATE kern.nummernkreis_jahr SET zaehler = zahl
         WHERE nummernkreis_id = nkj.nummernkreis_id AND geschaeftsjahr_id = nkj.geschaeftsjahr_id;
        nummer := kern.nr_formatieren(nkj.vorlage, zahl, lower(gj.zeitraum), b.buchungsdatum);
        IF EXISTS (SELECT 1 FROM kern.buchung WHERE nr = nummer) THEN
            PERFORM kern.verweigern(format('Nummer %s ist bereits vergeben – Vorlage ohne Jahr bei mehreren offenen Jahren? (F25)', nummer), 'KM003');
        END IF;
        ts := clock_timestamp();

        UPDATE kern.buchung SET status = 'festgeschrieben', geschaeftsjahr = gj.id,
               journal_nr = kopf.letzte_nr + 1, nr_zahl = zahl, nr = nummer,
               festgeschrieben_am = ts, festgeschrieben_von = p_subjekt,
               freigabe = fid, testbetrieb = (fid IS NULL),
               vorgaenger_hash = kopf.letzter_hash,
               hash = kern.kettenhash(kopf.letzter_hash,
                          kern._kettenglied(b.id, b.freigabe_hash, kopf.letzte_nr + 1, nummer, zahl, gj.id,
                                            ts, p_subjekt, fid IS NULL, fid))
         WHERE id = b.id
         RETURNING journal_nr, hash INTO kopf.letzte_nr, kopf.letzter_hash;
        UPDATE kern.kettenkopf SET letzte_nr = kopf.letzte_nr, letzter_hash = kopf.letzter_hash WHERE kette = 1;

        PERFORM kern._protokoll('buchung.festgeschrieben', p_subjekt,
            jsonb_build_object('buchung', b.id::text, 'journal_nr', kopf.letzte_nr, 'nr', nummer),
            NULL, jsonb_build_object('hash', kopf.letzter_hash), fid);

        o_buchung := b.id; o_journal_nr := kopf.letzte_nr; o_nr := nummer;
        RETURN NEXT;
    END LOOP;
END $$;

-- ===========================================================================
-- Storno (F30): legt die Gegenbuchung als Entwurf an (getauschte Seiten).
-- Festgeschrieben wird sie wie jede Buchung (vorbereiten, freigeben).
-- ===========================================================================
CREATE FUNCTION kern.storno_anlegen(p_subjekt text, p_original uuid, p_buchungsdatum date DEFAULT NULL, p_text text DEFAULT NULL)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE o kern.buchung; sid uuid; z kern.buchungszeile; nz uuid;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT * INTO o FROM kern.buchung WHERE id = p_original FOR SHARE;
    IF NOT FOUND OR o.status <> 'festgeschrieben' THEN
        PERFORM kern.verweigern('Storniert werden können nur festgeschriebene Buchungen (F30)', 'KM003');
    END IF;
    IF EXISTS (SELECT 1 FROM kern.buchung WHERE storno_von = p_original) THEN
        PERFORM kern.verweigern(format('Buchung %s ist bereits storniert oder ein Storno ist in Arbeit (F30)', o.nr), 'KM003');
    END IF;
    INSERT INTO kern.buchung (erfasst_von, herkunft, herkunft_ref, storno_von, buchungsart, nummernkreis,
                              belegdatum, buchungsdatum, belegnummer, text)
         VALUES (p_subjekt, 'manuell', 'storno:' || o.nr, o.id, o.buchungsart, o.nummernkreis,
                 coalesce(p_buchungsdatum, current_date), coalesce(p_buchungsdatum, current_date), o.belegnummer,
                 left(coalesce(p_text, 'Storno ' || o.nr || ': ' || o.text), 500))
      RETURNING id INTO sid;
    FOR z IN SELECT * FROM kern.buchungszeile WHERE buchung = o.id ORDER BY pos LOOP
        INSERT INTO kern.buchungszeile (buchung, pos, konto, seite, betrag, fw_betrag, fw_waehrung, kurs, kurs_datum, kurs_art)
             VALUES (sid, z.pos, z.konto, CASE z.seite WHEN 'S' THEN 'H'::kern.seite ELSE 'S'::kern.seite END,
                     z.betrag, z.fw_betrag, z.fw_waehrung, z.kurs, z.kurs_datum, z.kurs_art)
          RETURNING id INTO nz;
        INSERT INTO kern.zeile_tag (zeile, tag) SELECT nz, tag FROM kern.zeile_tag WHERE zeile = z.id;
        INSERT INTO kern.erweiterungswert (buchung, zeile, schluessel, wert)
             SELECT sid, nz, schluessel, wert FROM kern.erweiterungswert WHERE zeile = z.id;
    END LOOP;
    INSERT INTO kern.erweiterungswert (buchung, schluessel, wert)
         SELECT sid, schluessel, wert FROM kern.erweiterungswert WHERE buchung = o.id AND zeile IS NULL;
    PERFORM kern._protokoll('buchung.storno_angelegt', p_subjekt,
        jsonb_build_object('buchung', sid::text, 'storno_von', o.id::text), NULL, kern.buchung_inhalt(sid));
    RETURN sid;
END $$;

-- ===========================================================================
-- Belege nachreichen (F27)
-- ===========================================================================
CREATE FUNCTION kern.beleg_nachreichen(p_subjekt text, p_buchung uuid, p_paperless_id integer, p_md5 text, p_sha256 text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    PERFORM 1 FROM kern.buchung WHERE id = p_buchung FOR SHARE;
    INSERT INTO kern.belegverweis (buchung, paperless_id, md5, sha256, art, zugeordnet_von)
         VALUES (p_buchung, p_paperless_id, lower(p_md5), lower(p_sha256), 'nachgereicht', p_subjekt);
    PERFORM kern._protokoll('beleg.nachgereicht', p_subjekt, jsonb_build_object('buchung', p_buchung::text), NULL,
        jsonb_build_object('paperless_id', p_paperless_id, 'md5', lower(p_md5), 'sha256', lower(p_sha256)));
END $$;

-- ===========================================================================
-- Geschäftsjahr sperren und im Notfall wieder öffnen (F33, F36, F71)
-- ===========================================================================
-- Inhalt, den der Verantwortliche beim Sperren freigibt.
CREATE FUNCTION kern.gj_sperr_inhalt(p_kennung text) RETURNS jsonb
LANGUAGE sql STABLE AS $$
    SELECT jsonb_build_object(
        'geschaeftsjahr', g.kennung,
        'beginn',  to_char(lower(g.zeitraum), 'YYYY-MM-DD'),
        'ende',    to_char(upper(g.zeitraum) - 1, 'YYYY-MM-DD'),
        'buchungen', (SELECT count(*) FROM kern.buchung b WHERE b.geschaeftsjahr = g.id),
        'letzte_journal_nr', (SELECT coalesce(max(b.journal_nr), 0) FROM kern.buchung b WHERE b.geschaeftsjahr = g.id),
        'kettenkopf', (SELECT letzter_hash FROM kern.kettenkopf WHERE kette = 1))
      FROM kern.geschaeftsjahr g WHERE g.kennung = p_kennung
$$;

CREATE FUNCTION kern.geschaeftsjahr_sperren(p_subjekt text, p_kennung text, p_freigabe jsonb DEFAULT NULL)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE gj kern.geschaeftsjahr; inhalt jsonb; fid uuid;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT * INTO gj FROM kern.geschaeftsjahr WHERE kennung = p_kennung FOR UPDATE;    -- exklusiv (F71)
    IF NOT FOUND THEN PERFORM kern.verweigern(format('Geschäftsjahr %s unbekannt', p_kennung), 'KM002'); END IF;
    IF gj.status = 'gesperrt' THEN
        PERFORM kern.verweigern(format('Geschäftsjahr %s ist bereits gesperrt', p_kennung), 'KM003');
    END IF;
    IF EXISTS (SELECT 1 FROM kern.buchung WHERE status = 'freigabebereit' AND gj.zeitraum @> buchungsdatum) THEN
        PERFORM kern.verweigern('Es gibt freigabebereite Buchungen in diesem Geschäftsjahr – erst festschreiben oder zurücksetzen', 'KM003');
    END IF;
    inhalt := kern.gj_sperr_inhalt(p_kennung);
    fid := kern._freigabe_einloesen(p_subjekt, 'gj_sperren', kern.inhalt_hash(inhalt), p_freigabe);
    UPDATE kern.geschaeftsjahr SET status = 'gesperrt' WHERE id = gj.id;
    PERFORM kern._protokoll('geschaeftsjahr.gesperrt', p_subjekt, jsonb_build_object('geschaeftsjahr', gj.id::text),
                            jsonb_build_object('status', 'offen'), inhalt || jsonb_build_object('status', 'gesperrt'), fid);
END $$;

CREATE FUNCTION kern.gj_oeffnen_inhalt(p_kennung text, p_begruendung text) RETURNS jsonb
LANGUAGE sql STABLE AS $$
    SELECT kern.gj_sperr_inhalt(p_kennung) || jsonb_build_object('begruendung', p_begruendung)
$$;

-- Notfall (F36): immer mit persönlicher Freigabe, auch im Testbetrieb.
CREATE FUNCTION kern.geschaeftsjahr_oeffnen(p_subjekt text, p_kennung text, p_begruendung text, p_freigabe jsonb)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE gj kern.geschaeftsjahr; inhalt jsonb; fid uuid;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    IF p_begruendung IS NULL OR length(trim(p_begruendung)) < 10 THEN
        PERFORM kern.verweigern('Für die Wiederöffnung ist eine Begründung erforderlich (F36)', 'KM002');
    END IF;
    SELECT * INTO gj FROM kern.geschaeftsjahr WHERE kennung = p_kennung FOR UPDATE;
    IF NOT FOUND OR gj.status <> 'gesperrt' THEN
        PERFORM kern.verweigern('Nur gesperrte Geschäftsjahre können wieder geöffnet werden', 'KM003');
    END IF;
    inhalt := kern.gj_oeffnen_inhalt(p_kennung, p_begruendung);
    fid := kern._freigabe_einloesen(p_subjekt, 'gj_oeffnen', kern.inhalt_hash(inhalt), p_freigabe, true);
    UPDATE kern.geschaeftsjahr SET status = 'offen' WHERE id = gj.id;
    PERFORM kern._protokoll('geschaeftsjahr.geoeffnet', p_subjekt, jsonb_build_object('geschaeftsjahr', gj.id::text),
                            jsonb_build_object('status', 'gesperrt'), inhalt || jsonb_build_object('status', 'offen'), fid);
END $$;

-- ===========================================================================
-- Produktivbetrieb einschalten (F59, F70) – immer mit persönlicher Freigabe
-- ===========================================================================
CREATE FUNCTION kern.produktiv_inhalt() RETURNS jsonb
LANGUAGE sql STABLE AS $$
    SELECT jsonb_build_object('buch', b.name, 'buchwaehrung', b.buchwaehrung,
                              'kette1', (SELECT letzte_nr FROM kern.kettenkopf WHERE kette = 1))
      FROM kern.buch b
$$;

CREATE FUNCTION kern.produktiv_einschalten(p_subjekt text, p_freigabe jsonb)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE fid uuid; inhalt jsonb;
BEGIN
    PERFORM kern._subjekt(p_subjekt);
    PERFORM 1 FROM kern.buch FOR UPDATE;
    PERFORM kern._schreibbar();
    inhalt := kern.produktiv_inhalt();
    fid := kern._freigabe_einloesen(p_subjekt, 'produktivbetrieb', kern.inhalt_hash(inhalt), p_freigabe, true);
    UPDATE kern.buch SET betriebsart = 'produktiv', produktiv_seit = now();
    PERFORM kern._protokoll('betrieb.produktiv_eingeschaltet', p_subjekt, '{}',
                            jsonb_build_object('betriebsart', 'test'), inhalt || jsonb_build_object('betriebsart', 'produktiv'), fid);
END $$;

-- ===========================================================================
-- Kettenprüfung (F13, F60): rechnet beide Ketten aus den gespeicherten Daten
-- nach. Liefert das erste fehlerhafte Glied, sonst nichts.
-- ===========================================================================
CREATE FUNCTION kern.kette_pruefen()
RETURNS TABLE (o_kette smallint, o_nr bigint, o_fehler text)
LANGUAGE plpgsql STABLE AS $$
DECLARE
    vorg text;
    r    record;
    pr   kern.protokoll;
    n    bigint;
BEGIN
    -- Kette 1: Buchungen in Journalreihenfolge
    vorg := repeat('0', 64); n := 0;
    FOR r IN SELECT id, journal_nr, vorgaenger_hash, hash FROM kern.buchung
              WHERE status = 'festgeschrieben' ORDER BY journal_nr LOOP
        n := n + 1;
        IF r.journal_nr <> n THEN
            RETURN QUERY SELECT 1::smallint, n, format('Lücke: erwartet Journalnummer %s, gefunden %s', n, r.journal_nr); RETURN;
        END IF;
        IF r.vorgaenger_hash <> vorg THEN
            RETURN QUERY SELECT 1::smallint, n, 'Vorgänger-Hash passt nicht'; RETURN;
        END IF;
        IF r.hash <> kern.kettenhash(vorg, kern.buchung_kettenglied(r.id)) THEN
            RETURN QUERY SELECT 1::smallint, n, 'Inhalt passt nicht zum Hash – Buchung wurde verändert'; RETURN;
        END IF;
        vorg := r.hash;
    END LOOP;
    IF (SELECT letzte_nr <> n OR letzter_hash <> vorg FROM kern.kettenkopf WHERE kette = 1) THEN
        RETURN QUERY SELECT 1::smallint, n, 'Kettenkopf passt nicht zum letzten Glied'; RETURN;
    END IF;

    -- Kette 2: Protokoll
    vorg := repeat('0', 64); n := 0;
    FOR pr IN SELECT * FROM kern.protokoll ORDER BY nr LOOP
        n := n + 1;
        IF pr.nr <> n THEN
            RETURN QUERY SELECT 2::smallint, n, format('Lücke: erwartet Protokollnummer %s, gefunden %s', n, pr.nr); RETURN;
        END IF;
        IF pr.vorgaenger_hash <> vorg OR pr.hash <> kern.kettenhash(vorg, kern.protokoll_inhalt(pr)) THEN
            RETURN QUERY SELECT 2::smallint, n, 'Protokolleintrag wurde verändert'; RETURN;
        END IF;
        vorg := pr.hash;
    END LOOP;
    IF (SELECT letzte_nr <> n OR letzter_hash <> vorg FROM kern.kettenkopf WHERE kette = 2) THEN
        RETURN QUERY SELECT 2::smallint, n, 'Kettenkopf passt nicht zum letzten Glied'; RETURN;
    END IF;
END $$;

-- ===========================================================================
-- Rechte (F64): Anwendung darf genau diese Funktionen ausführen
-- ===========================================================================
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA kern FROM PUBLIC;

GRANT EXECUTE ON FUNCTION
    kern.buch_anlegen(text, text, text, text),
    kern.konto_anlegen(text, text, text, kern.kontoart),
    kern.konto_aendern(text, text, text, boolean, kern.kontoart),
    kern.tag_anlegen(text, text, integer),
    kern.nummernkreis_anlegen(text, text, text),
    kern.buchungsart_anlegen(text, text, text, text),
    kern.geschaeftsjahr_anlegen(text, text, date, date),
    kern.nummernkreis_festlegen(text, text, text, text, boolean),
    kern.modul_registrieren(text, text, text, text[]),
    kern.erweiterungsattribut_anmelden(text, text, text, text, boolean, text[]),
    kern.credential_registrieren(text, text, bytea, bytea, integer, text),
    kern.credential_widerrufen(text, uuid, text),
    kern.challenge_ausstellen(text, kern.handlung, text, text, bytea, interval),
    kern.entwurf_anlegen(text, jsonb, kern.herkunft, text),
    kern.entwurf_aendern(text, uuid, jsonb, text),
    kern.entwurf_loeschen(text, uuid, text),
    kern.pruefvermerk_setzen(text, uuid, text, text, text, boolean, text),
    kern.vorbereiten(text, uuid),
    kern.zuruecksetzen(text, uuid),
    kern.festschreiben(text, uuid[], jsonb),
    kern.storno_anlegen(text, uuid, date, text),
    kern.beleg_nachreichen(text, uuid, integer, text, text),
    kern.geschaeftsjahr_sperren(text, text, jsonb),
    kern.geschaeftsjahr_oeffnen(text, text, text, jsonb),
    kern.produktiv_einschalten(text, jsonb)
TO kumero_app;

-- Lesende Hilfen für Anwendung und Prüfer
GRANT EXECUTE ON FUNCTION
    kern.jcs(jsonb), kern.sha256_hex(text), kern.kettenhash(text, jsonb),
    kern.dezimal(numeric), kern.zeitpunkt(timestamptz), kern.protokoll_inhalt(kern.protokoll),
    kern.buchung_inhalt(uuid), kern.inhalt_hash(jsonb), kern.buchung_freigabe_hash(uuid),
    kern.entwurf_etag(uuid), kern.buchung_kettenglied(uuid), kern.stapel_hash(uuid[]),
    kern._kettenglied(uuid, text, bigint, text, bigint, uuid, timestamptz, text, boolean, uuid),
    kern.nr_formatieren(text, bigint, date, date),
    kern.gj_sperr_inhalt(text), kern.gj_oeffnen_inhalt(text, text), kern.produktiv_inhalt(),
    kern.kette_pruefen()
TO kumero_app, kumero_lesen;
