-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Kern, Migration 0005: Schutz-Trigger (F17, F64, F66, F72)
--
-- Die Anwendung hat ohnehin nur SELECT (F64). Diese Trigger schützen die
-- Invarianten zusätzlich gegen Fehler in den eigenen Funktionen und gegen
-- direkte Eingriffe mit Eigentümerrechten (Prinzip "doppelt abgesichert").
-- Wer Superuser-Rechte hat, kann Trigger abschalten – das bleibt durch
-- Ketten und externe Siegel nachweisbar (F13, F14, F35, F69).

-- ---------------------------------------------------------------------------
-- Allgemein: nur Einfügen erlaubt
-- ---------------------------------------------------------------------------
CREATE FUNCTION kern.tg_nur_einfuegen() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    PERFORM kern.verweigern(format('%s ist unveränderlich (%s nicht erlaubt)', TG_TABLE_NAME, TG_OP));
    RETURN NULL;
END $$;

CREATE FUNCTION kern.tg_kein_truncate() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    PERFORM kern.verweigern(format('TRUNCATE auf %s ist nicht erlaubt', TG_TABLE_NAME));
    RETURN NULL;
END $$;

DO $$
DECLARE t text;
BEGIN
    FOR t IN SELECT tablename FROM pg_tables WHERE schemaname = 'kern' LOOP
        EXECUTE format('CREATE TRIGGER kein_truncate BEFORE TRUNCATE ON kern.%I
                        FOR EACH STATEMENT EXECUTE FUNCTION kern.tg_kein_truncate()', t);
    END LOOP;
END $$;

-- Reine Einfüge-Tabellen
CREATE TRIGGER nur_einfuegen BEFORE UPDATE OR DELETE ON kern.freigabe
    FOR EACH ROW EXECUTE FUNCTION kern.tg_nur_einfuegen();
CREATE TRIGGER nur_einfuegen BEFORE UPDATE OR DELETE ON kern.freigabe_position
    FOR EACH ROW EXECUTE FUNCTION kern.tg_nur_einfuegen();
CREATE TRIGGER nur_einfuegen BEFORE UPDATE OR DELETE ON kern.restore_pruefung
    FOR EACH ROW EXECUTE FUNCTION kern.tg_nur_einfuegen();
CREATE TRIGGER nur_einfuegen BEFORE UPDATE OR DELETE ON kern.outbox
    FOR EACH ROW EXECUTE FUNCTION kern.tg_nur_einfuegen();

-- ---------------------------------------------------------------------------
-- Buch (F11, F59, F70)
-- ---------------------------------------------------------------------------
CREATE FUNCTION kern.tg_buch() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    kette1 bigint;
BEGIN
    IF TG_OP = 'DELETE' THEN
        PERFORM kern.verweigern('Das Buch kann nicht gelöscht werden');
    END IF;
    SELECT letzte_nr INTO kette1 FROM kern.kettenkopf WHERE kette = 1;
    IF NEW.buchwaehrung <> OLD.buchwaehrung AND kette1 > 0 THEN
        PERFORM kern.verweigern('Buchwährung ist nach der ersten Festschreibung unveränderlich (F11)');
    END IF;
    IF OLD.betriebsart = 'produktiv' AND NEW.betriebsart <> 'produktiv' THEN
        PERFORM kern.verweigern('Der Produktivbetrieb ist nicht umkehrbar (F59)');
    END IF;
    IF OLD.betriebsart = 'test' AND NEW.betriebsart = 'produktiv' AND kette1 > 0 THEN
        PERFORM kern.verweigern('Produktivbetrieb nur bei leerer Buchungskette (F70)', 'KM003');
    END IF;
    IF OLD.produktiv_seit IS NOT NULL AND NEW.produktiv_seit IS DISTINCT FROM OLD.produktiv_seit THEN
        PERFORM kern.verweigern('produktiv_seit ist unveränderlich');
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER schutz BEFORE UPDATE OR DELETE ON kern.buch
    FOR EACH ROW EXECUTE FUNCTION kern.tg_buch();

-- ---------------------------------------------------------------------------
-- Hilfsfunktionen: Wird ein Stammdatum von festgeschriebenen Buchungen genutzt?
-- ---------------------------------------------------------------------------
CREATE FUNCTION kern.konto_bebucht(k uuid) RETURNS boolean
LANGUAGE sql STABLE AS $$
    SELECT EXISTS (SELECT 1 FROM kern.buchungszeile z JOIN kern.buchung b ON b.id = z.buchung
                    WHERE z.konto = k AND b.status = 'festgeschrieben')
$$;

-- Konto: nie löschen; Nummer und Kontoart nach Nutzung unveränderlich (F7)
CREATE FUNCTION kern.tg_konto() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        PERFORM kern.verweigern('Konten werden nie gelöscht, nur deaktiviert');
    END IF;
    IF NEW.id <> OLD.id THEN
        PERFORM kern.verweigern('Konto-ID ist unveränderlich');
    END IF;
    IF (NEW.nr <> OLD.nr OR NEW.kontoart <> OLD.kontoart) AND kern.konto_bebucht(OLD.id) THEN
        PERFORM kern.verweigern(format('Konto %s ist bebucht: Nummer und Kontoart sind unveränderlich (F7)', OLD.nr));
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER schutz BEFORE UPDATE OR DELETE ON kern.konto
    FOR EACH ROW EXECUTE FUNCTION kern.tg_konto();

-- Tag: Name nach Nutzung in festgeschriebener Buchung unveränderlich, dann nicht löschbar
CREATE FUNCTION kern.tg_tag() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE genutzt boolean;
BEGIN
    SELECT EXISTS (SELECT 1 FROM kern.zeile_tag zt
                     JOIN kern.buchungszeile z ON z.id = zt.zeile
                     JOIN kern.buchung b ON b.id = z.buchung
                    WHERE zt.tag = OLD.id AND b.status = 'festgeschrieben') INTO genutzt;
    IF genutzt AND (TG_OP = 'DELETE' OR NEW.name <> OLD.name OR NEW.id <> OLD.id) THEN
        PERFORM kern.verweigern(format('Tag "%s" ist in festgeschriebenen Buchungen verwendet', OLD.name));
    END IF;
    RETURN CASE TG_OP WHEN 'DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER schutz BEFORE UPDATE OR DELETE ON kern.tag
    FOR EACH ROW EXECUTE FUNCTION kern.tg_tag();

-- Buchungsart und Nummernkreis: Kürzel nach Nutzung unveränderlich, dann nicht löschbar
CREATE FUNCTION kern.tg_kuerzel_stamm() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE genutzt boolean;
BEGIN
    EXECUTE format('SELECT EXISTS (SELECT 1 FROM kern.buchung WHERE %I = $1 AND status = ''festgeschrieben'')',
                   CASE TG_TABLE_NAME WHEN 'buchungsart' THEN 'buchungsart' ELSE 'nummernkreis' END)
       INTO genutzt USING OLD.id;
    IF genutzt AND (TG_OP = 'DELETE' OR NEW.kuerzel <> OLD.kuerzel OR NEW.id <> OLD.id) THEN
        PERFORM kern.verweigern(format('%s "%s" ist in festgeschriebenen Buchungen verwendet', TG_TABLE_NAME, OLD.kuerzel));
    END IF;
    RETURN CASE TG_OP WHEN 'DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER schutz BEFORE UPDATE OR DELETE ON kern.buchungsart
    FOR EACH ROW EXECUTE FUNCTION kern.tg_kuerzel_stamm();
CREATE TRIGGER schutz BEFORE UPDATE OR DELETE ON kern.nummernkreis
    FOR EACH ROW EXECUTE FUNCTION kern.tg_kuerzel_stamm();

-- Geschäftsjahr: Zeitraum und Kennung nach erster Festschreibung fest; nicht löschbar, wenn genutzt
CREATE FUNCTION kern.tg_geschaeftsjahr() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE genutzt boolean;
BEGIN
    SELECT EXISTS (SELECT 1 FROM kern.buchung WHERE geschaeftsjahr = OLD.id) INTO genutzt;
    IF TG_OP = 'DELETE' THEN
        IF genutzt OR EXISTS (SELECT 1 FROM kern.nummernkreis_jahr WHERE geschaeftsjahr_id = OLD.id AND zaehler > 0) THEN
            PERFORM kern.verweigern(format('Geschäftsjahr %s ist bebucht und kann nicht gelöscht werden', OLD.kennung));
        END IF;
        RETURN OLD;
    END IF;
    IF genutzt AND (NEW.zeitraum <> OLD.zeitraum OR NEW.kennung <> OLD.kennung OR NEW.id <> OLD.id) THEN
        PERFORM kern.verweigern(format('Geschäftsjahr %s ist bebucht: Zeitraum und Kennung sind unveränderlich', OLD.kennung));
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER schutz BEFORE UPDATE OR DELETE ON kern.geschaeftsjahr
    FOR EACH ROW EXECUTE FUNCTION kern.tg_geschaeftsjahr();

-- Nummernkreis-Jahr: Festlegung nach erster Vergabe unveränderlich; Zähler nur +1 (F25, F17)
CREATE FUNCTION kern.tg_nummernkreis_jahr() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.zaehler > 0 THEN
            PERFORM kern.verweigern('Nummernkreis-Jahr mit vergebenen Nummern kann nicht gelöscht werden');
        END IF;
        RETURN OLD;
    END IF;
    IF NEW.nummernkreis_id <> OLD.nummernkreis_id OR NEW.geschaeftsjahr_id <> OLD.geschaeftsjahr_id THEN
        PERFORM kern.verweigern('Schlüssel des Nummernkreis-Jahres ist unveränderlich');
    END IF;
    IF OLD.zaehler > 0 AND (NEW.vorlage <> OLD.vorlage OR NEW.zuruecksetzen <> OLD.zuruecksetzen
                            OR NEW.startwert <> OLD.startwert
                            OR NEW.festgelegt_am <> OLD.festgelegt_am OR NEW.festgelegt_von <> OLD.festgelegt_von) THEN
        PERFORM kern.verweigern('Festlegung des Nummernkreises ist nach der ersten Vergabe unveränderlich (F25)');
    END IF;
    -- Erste Vergabe setzt den Zähler auf den Startwert, danach genau +1.
    IF NEW.zaehler <> OLD.zaehler AND NOT (
           (OLD.zaehler = 0 AND NEW.zaehler = NEW.startwert)
        OR (OLD.zaehler > 0 AND NEW.zaehler = OLD.zaehler + 1)) THEN
        PERFORM kern.verweigern('Zähler darf nur lückenlos steigen (F17)');
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER schutz BEFORE UPDATE OR DELETE ON kern.nummernkreis_jahr
    FOR EACH ROW EXECUTE FUNCTION kern.tg_nummernkreis_jahr();

-- Erweiterungsattribut: Definition nach Nutzung unveränderlich
CREATE FUNCTION kern.tg_erweiterungsattribut() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM kern.erweiterungswert WHERE schluessel = OLD.schluessel)
       AND (TG_OP = 'DELETE' OR NEW.ebene <> OLD.ebene OR NEW.datentyp <> OLD.datentyp
            OR NEW.auswahlwerte IS DISTINCT FROM OLD.auswahlwerte OR NEW.schluessel <> OLD.schluessel) THEN
        PERFORM kern.verweigern(format('Erweiterungsattribut %s ist in Verwendung', OLD.schluessel));
    END IF;
    RETURN CASE TG_OP WHEN 'DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER schutz BEFORE UPDATE OR DELETE ON kern.erweiterungsattribut
    FOR EACH ROW EXECUTE FUNCTION kern.tg_erweiterungsattribut();

-- ---------------------------------------------------------------------------
-- Buchung (F66, F30)
-- ---------------------------------------------------------------------------
CREATE FUNCTION kern.tg_buchung() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'entwurf' THEN
            PERFORM kern.verweigern('Neue Buchungen beginnen immer als Entwurf', 'KM003');
        END IF;
        RETURN NEW;
    END IF;

    IF OLD.status = 'festgeschrieben' THEN
        PERFORM kern.verweigern(format('Buchung %s ist festgeschrieben und unveränderlich – Korrektur nur per Storno (F30)', OLD.nr));
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;     -- Entwürfe und freigabebereite Buchungen dürfen gelöscht werden (F31)
    END IF;

    IF NEW.id <> OLD.id OR NEW.erfasst_am <> OLD.erfasst_am OR NEW.erfasst_von <> OLD.erfasst_von THEN
        PERFORM kern.verweigern('ID und Erfassungsdaten sind unveränderlich');
    END IF;

    -- Erlaubte Übergänge (F66): entwurf ↔ freigabebereit → festgeschrieben
    IF NOT (   (OLD.status = 'entwurf'        AND NEW.status IN ('entwurf', 'freigabebereit'))
            OR (OLD.status = 'freigabebereit' AND NEW.status IN ('entwurf', 'freigabebereit', 'festgeschrieben'))) THEN
        PERFORM kern.verweigern(format('Statuswechsel %s → %s ist nicht erlaubt (F66)', OLD.status, NEW.status), 'KM003');
    END IF;

    -- Eingefrorener Inhalt: Ab "freigabebereit" ändert sich am Kopf nichts mehr,
    -- außer beim Zurücksetzen auf Entwurf (F66).
    IF OLD.status = 'freigabebereit' AND NEW.status <> 'entwurf' AND (
           NEW.buchungsart  IS DISTINCT FROM OLD.buchungsart
        OR NEW.nummernkreis IS DISTINCT FROM OLD.nummernkreis
        OR NEW.belegdatum   IS DISTINCT FROM OLD.belegdatum
        OR NEW.buchungsdatum IS DISTINCT FROM OLD.buchungsdatum
        OR NEW.belegnummer  IS DISTINCT FROM OLD.belegnummer
        OR NEW.text         IS DISTINCT FROM OLD.text
        OR NEW.herkunft     IS DISTINCT FROM OLD.herkunft
        OR NEW.herkunft_ref IS DISTINCT FROM OLD.herkunft_ref
        OR NEW.storno_von   IS DISTINCT FROM OLD.storno_von
        OR NEW.vorbereitet_am  IS DISTINCT FROM OLD.vorbereitet_am
        OR NEW.vorbereitet_von IS DISTINCT FROM OLD.vorbereitet_von
        OR NEW.freigabe_hash   IS DISTINCT FROM OLD.freigabe_hash) THEN
        PERFORM kern.verweigern('Freigabebereiter Inhalt ist eingefroren – zuerst auf Entwurf zurücksetzen (F66)');
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER schutz BEFORE INSERT OR UPDATE OR DELETE ON kern.buchung
    FOR EACH ROW EXECUTE FUNCTION kern.tg_buchung();

-- Bestandteile einer Buchung: nur im Entwurf änderbar. Ausnahmen:
--   pruefvermerk 'festschreiben_pruefen' darf bei 'freigabebereit' hinzukommen;
--   belegverweis art 'nachgereicht' darf bei 'festgeschrieben' hinzukommen (F27).
CREATE FUNCTION kern.tg_buchung_bestandteil() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    zeile   record;
    b_id    uuid;
    st      kern.buchungsstatus;
BEGIN
    zeile := CASE TG_OP WHEN 'DELETE' THEN OLD ELSE NEW END;

    IF TG_TABLE_NAME = 'zeile_tag' THEN
        SELECT z.buchung INTO b_id FROM kern.buchungszeile z WHERE z.id = zeile.zeile;
    ELSE
        b_id := zeile.buchung;
    END IF;

    SELECT status INTO st FROM kern.buchung WHERE id = b_id;
    IF NOT FOUND THEN
        RETURN zeile;   -- Buchung bereits gelöscht (kaskadierendes Löschen eines Entwurfs)
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF TG_TABLE_NAME = 'zeile_tag' THEN
            IF NEW.zeile <> OLD.zeile THEN
                PERFORM kern.verweigern('Bestandteile können nicht verschoben werden');
            END IF;
        ELSIF NEW.buchung <> OLD.buchung THEN
            PERFORM kern.verweigern('Bestandteile können nicht in eine andere Buchung verschoben werden');
        END IF;
    END IF;

    IF st = 'entwurf' THEN
        RETURN zeile;
    END IF;
    -- (PL/pgSQL wertet AND nicht verkürzt aus; daher verschachtelt.)
    IF TG_OP = 'INSERT' THEN
        IF TG_TABLE_NAME = 'pruefvermerk' THEN
            IF st = 'freigabebereit' AND NEW.erweiterungspunkt = 'festschreiben_pruefen' THEN
                RETURN zeile;
            END IF;
        ELSIF TG_TABLE_NAME = 'belegverweis' THEN
            IF NEW.art = 'nachgereicht' THEN
                IF st = 'festgeschrieben' THEN
                    RETURN zeile;
                END IF;
                PERFORM kern.verweigern('Belege werden erst bei festgeschriebenen Buchungen nachgereicht (F27)', 'KM003');
            END IF;
        END IF;
    END IF;

    PERFORM kern.verweigern(format('%s einer Buchung im Status "%s" ist unveränderlich (F66, F30)', TG_TABLE_NAME, st));
    RETURN NULL;
END $$;

CREATE TRIGGER schutz BEFORE INSERT OR UPDATE OR DELETE ON kern.buchungszeile
    FOR EACH ROW EXECUTE FUNCTION kern.tg_buchung_bestandteil();
CREATE TRIGGER schutz BEFORE INSERT OR UPDATE OR DELETE ON kern.zeile_tag
    FOR EACH ROW EXECUTE FUNCTION kern.tg_buchung_bestandteil();
CREATE TRIGGER schutz BEFORE INSERT OR UPDATE OR DELETE ON kern.erweiterungswert
    FOR EACH ROW EXECUTE FUNCTION kern.tg_buchung_bestandteil();
CREATE TRIGGER schutz BEFORE INSERT OR UPDATE OR DELETE ON kern.pruefvermerk
    FOR EACH ROW EXECUTE FUNCTION kern.tg_buchung_bestandteil();
CREATE TRIGGER schutz BEFORE INSERT OR UPDATE OR DELETE ON kern.belegverweis
    FOR EACH ROW EXECUTE FUNCTION kern.tg_buchung_bestandteil();

-- Erweiterungswert gehört zur Ebene seines Attributs und die Zeile zur Buchung
CREATE FUNCTION kern.tg_erweiterungswert_passt() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE e text;
BEGIN
    SELECT ebene INTO e FROM kern.erweiterungsattribut WHERE schluessel = NEW.schluessel;
    IF (e = 'zeile') <> (NEW.zeile IS NOT NULL) THEN
        PERFORM kern.verweigern(format('%s gehört zur Ebene "%s"', NEW.schluessel, e), 'KM002');
    END IF;
    IF NEW.zeile IS NOT NULL AND NOT EXISTS (
           SELECT 1 FROM kern.buchungszeile WHERE id = NEW.zeile AND buchung = NEW.buchung) THEN
        PERFORM kern.verweigern('Zeile gehört nicht zur Buchung', 'KM002');
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER passt BEFORE INSERT OR UPDATE ON kern.erweiterungswert
    FOR EACH ROW EXECUTE FUNCTION kern.tg_erweiterungswert_passt();

-- ---------------------------------------------------------------------------
-- Freigabe-Bausteine
-- ---------------------------------------------------------------------------
-- Credential: nur Widerruf (einmalig) und steigender Signaturzähler (F68)
CREATE FUNCTION kern.tg_credential() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        PERFORM kern.verweigern('Credentials werden nie gelöscht, nur widerrufen');
    END IF;
    IF NEW.id <> OLD.id OR NEW.subjekt <> OLD.subjekt OR NEW.credential_id <> OLD.credential_id
       OR NEW.public_key <> OLD.public_key OR NEW.algorithmus <> OLD.algorithmus
       OR NEW.registriert_am <> OLD.registriert_am THEN
        PERFORM kern.verweigern('Credential-Daten sind unveränderlich');
    END IF;
    IF OLD.widerrufen_am IS NOT NULL AND NEW.widerrufen_am IS DISTINCT FROM OLD.widerrufen_am THEN
        PERFORM kern.verweigern('Widerruf ist endgültig');
    END IF;
    IF NEW.sign_count < OLD.sign_count THEN
        PERFORM kern.verweigern('Signaturzähler darf nicht sinken (F68)');
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER schutz BEFORE UPDATE OR DELETE ON kern.credential
    FOR EACH ROW EXECUTE FUNCTION kern.tg_credential();

-- Challenge: nur einmal verbrauchen; unverbrauchte dürfen aufgeräumt werden
CREATE FUNCTION kern.tg_challenge() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.verbraucht_am IS NOT NULL THEN
            PERFORM kern.verweigern('Verbrauchte Challenges bleiben als Nachweis erhalten (F68)');
        END IF;
        RETURN OLD;
    END IF;
    IF OLD.verbraucht_am IS NOT NULL THEN
        PERFORM kern.verweigern('Challenge wurde bereits verwendet (F68)', 'KM003');
    END IF;
    IF (NEW.id, NEW.zufall, NEW.ablauf, NEW.mandant, NEW.art, NEW.subjekt, NEW.inhalt_hash, NEW.wert, NEW.erstellt_am)
       IS DISTINCT FROM
       (OLD.id, OLD.zufall, OLD.ablauf, OLD.mandant, OLD.art, OLD.subjekt, OLD.inhalt_hash, OLD.wert, OLD.erstellt_am) THEN
        PERFORM kern.verweigern('Challenge-Daten sind unveränderlich');
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER schutz BEFORE UPDATE OR DELETE ON kern.challenge
    FOR EACH ROW EXECUTE FUNCTION kern.tg_challenge();

-- ---------------------------------------------------------------------------
-- Ketten (F13)
-- ---------------------------------------------------------------------------
-- Kettenkopf: weder einfügen noch löschen; Nummer steigt genau um 1
CREATE FUNCTION kern.tg_kettenkopf() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP <> 'UPDATE' THEN
        PERFORM kern.verweigern('Kettenköpfe sind fest angelegt');
    END IF;
    IF NEW.kette <> OLD.kette OR NEW.letzte_nr <> OLD.letzte_nr + 1 THEN
        PERFORM kern.verweigern('Kettenkopf darf nur um genau ein Glied wachsen (F13)');
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER schutz BEFORE INSERT OR UPDATE OR DELETE ON kern.kettenkopf
    FOR EACH ROW EXECUTE FUNCTION kern.tg_kettenkopf();

-- Protokoll: Verkettung erfolgt hier automatisch; danach unveränderlich (F48).
-- Inhalt des Glieds (kanonisch): {nr, zeitpunkt, typ, subjekt, ids, vorher, nachher, freigabe}
CREATE FUNCTION kern.protokoll_inhalt(p kern.protokoll) RETURNS jsonb
LANGUAGE sql IMMUTABLE AS $$
    SELECT jsonb_build_object(
        'nr',        p.nr,
        'zeitpunkt', kern.zeitpunkt(p.zeitpunkt),
        'typ',       p.typ,
        'subjekt',   p.subjekt,
        'ids',       p.ids,
        'vorher',    p.vorher,
        'nachher',   p.nachher,
        'freigabe',  p.freigabe)
$$;

CREATE FUNCTION kern.tg_protokoll_verketten() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    kopf kern.kettenkopf;
BEGIN
    SELECT * INTO kopf FROM kern.kettenkopf WHERE kette = 2 FOR UPDATE;
    NEW.nr              := kopf.letzte_nr + 1;
    NEW.zeitpunkt       := clock_timestamp();
    NEW.vorgaenger_hash := kopf.letzter_hash;
    NEW.hash            := kern.kettenhash(kopf.letzter_hash, kern.protokoll_inhalt(NEW));
    UPDATE kern.kettenkopf SET letzte_nr = NEW.nr, letzter_hash = NEW.hash WHERE kette = 2;
    RETURN NEW;
END $$;
CREATE TRIGGER verketten BEFORE INSERT ON kern.protokoll
    FOR EACH ROW EXECUTE FUNCTION kern.tg_protokoll_verketten();
CREATE TRIGGER nur_einfuegen BEFORE UPDATE OR DELETE ON kern.protokoll
    FOR EACH ROW EXECUTE FUNCTION kern.tg_nur_einfuegen();

-- Siegel: Felder werden nur einmal befüllt, Status nur vorwärts (F14, F16)
CREATE FUNCTION kern.tg_siegel() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        PERFORM kern.verweigern('Siegel werden nie gelöscht');
    END IF;
    IF (NEW.id, NEW.kette, NEW.bis_nr, NEW.ketten_hash, NEW.erstellt_am)
       IS DISTINCT FROM (OLD.id, OLD.kette, OLD.bis_nr, OLD.ketten_hash, OLD.erstellt_am)
       OR (OLD.signatur IS NOT NULL AND (NEW.signatur, NEW.schluessel_fpr) IS DISTINCT FROM (OLD.signatur, OLD.schluessel_fpr))
       OR (OLD.tsr IS NOT NULL AND (NEW.tsr, NEW.tsa, NEW.gestempelt_am) IS DISTINCT FROM (OLD.tsr, OLD.tsa, OLD.gestempelt_am))
       OR NEW.status < OLD.status THEN
        PERFORM kern.verweigern('Siegelinhalte werden nur einmal befüllt (F14)');
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER schutz BEFORE UPDATE OR DELETE ON kern.siegel
    FOR EACH ROW EXECUTE FUNCTION kern.tg_siegel();

CREATE TRIGGER nur_aendern BEFORE DELETE ON kern.siegel_export
    FOR EACH ROW EXECUTE FUNCTION kern.tg_nur_einfuegen();

-- ---------------------------------------------------------------------------
-- Rechte der Anwendung (F64): nur lesen. Schreibfunktionen folgen in Teil 2
-- und erhalten EXECUTE einzeln.
-- ---------------------------------------------------------------------------
REVOKE INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER
    ON ALL TABLES IN SCHEMA kern FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON ALL TABLES IN SCHEMA kern TO kumero_app, kumero_lesen;
-- Sicherheitshalber: keine Funktion des Kerns ist für PUBLIC ausführbar.
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA kern FROM PUBLIC;
-- Hilfsfunktionen ohne Schreibwirkung dürfen gelesen/geprüft werden
GRANT EXECUTE ON FUNCTION kern.jcs(jsonb), kern.sha256_hex(text), kern.kettenhash(text, jsonb),
                          kern.dezimal(numeric), kern.zeitpunkt(timestamptz),
                          kern.protokoll_inhalt(kern.protokoll)
    TO kumero_app, kumero_lesen;
