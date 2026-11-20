-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Kern, Migration 0016: Der Kern ist die Datenbank eines Buchs (BUECHER.md Abschnitt 9 und 10; F123, F126)
--
-- Ein Mandant kann mehrere Bücher haben; jedes hat seine eigene Datenbank mit diesem Schema. Daraus folgt:
--
--   * Das Buch kennt seine **Kennung**, seine **Art** (EÜR oder Bilanz – der Kern bleibt doppisch, F9) und ob es ein
--     **Testbuch** ist. Ein Testbuch wird nie produktiv (B6).
--   * **Nutzer liegen nicht mehr im Buch**, sondern in der Mandanten-Datenbank (Schema `mandant`). Im Buch erscheinen
--     sie nur als Subjekt `nutzer:<uuid>`. Die Tabellen aus Migration 0012 entfallen – sofort, wenn sie leer sind
--     (neue Bücher), sonst nach dem Überführen (`kern.nutzer_auslagern`, nur kumero_owner).
--   * **Der Administrator steht im Mandanten** (A10); im Buch bleibt nur die Regel „der letzte Verantwortliche bleibt“.
--   * **Siegelinhalt in Fassung 2** nennt Mandant und Buch (A14). Vorhandene Siegel behalten ihre Fassung.

ALTER TABLE kern.buch
    ADD COLUMN kennung  text CHECK (kennung ~ '^[a-z][a-z0-9-]{1,19}$'),
    ADD COLUMN art      text CHECK (art IN ('euer', 'bilanz')),
    ADD COLUMN testbuch boolean NOT NULL DEFAULT false,
    ADD CONSTRAINT buch_testbuch_nie_produktiv CHECK (NOT testbuch OR betriebsart = 'test');

CREATE OR REPLACE FUNCTION kern.tg_buch() RETURNS trigger
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
    IF OLD.betriebsart = 'test' AND NEW.betriebsart = 'produktiv' AND NEW.testbuch THEN
        PERFORM kern.verweigern('Ein Testbuch wird nie produktiv (BUECHER.md B6)', 'KM003');
    END IF;
    IF OLD.produktiv_seit IS NOT NULL AND NEW.produktiv_seit IS DISTINCT FROM OLD.produktiv_seit THEN
        PERFORM kern.verweigern('produktiv_seit ist unveränderlich');
    END IF;
    IF OLD.kennung IS NOT NULL AND (NEW.kennung, NEW.testbuch) IS DISTINCT FROM (OLD.kennung, OLD.testbuch) THEN
        PERFORM kern.verweigern('Kennung und Kennzeichen Testbuch sind unveränderlich');
    END IF;
    RETURN NEW;
END $$;

-- Kennung, Art und Kennzeichen Testbuch setzen – einmal, beim Anlegen des Buchs bzw. beim Überführen des Bestands.
CREATE FUNCTION kern.buch_einrichten(p_subjekt text, p_kennung text, p_art text, p_testbuch boolean) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE b kern.buch;
BEGIN
    PERFORM kern._subjekt(p_subjekt);
    SELECT * INTO b FROM kern.buch FOR UPDATE;
    IF NOT FOUND THEN PERFORM kern.verweigern('Buch ist noch nicht angelegt', 'KM003'); END IF;
    IF b.kennung IS NOT NULL THEN PERFORM kern.verweigern('Das Buch ist schon eingerichtet', 'KM003'); END IF;
    IF p_kennung IS NULL OR p_art IS NULL OR p_testbuch IS NULL THEN PERFORM kern.verweigern('Kennung, Art und Testbuch angeben', 'KM002'); END IF;
    IF p_testbuch AND b.betriebsart = 'produktiv' THEN PERFORM kern.verweigern('Ein produktives Buch ist kein Testbuch', 'KM003'); END IF;
    UPDATE kern.buch SET kennung = p_kennung, art = p_art, testbuch = p_testbuch;
    PERFORM kern._protokoll('buch.eingerichtet', p_subjekt, '{}', NULL,
        jsonb_build_object('kennung', p_kennung, 'art', p_art, 'testbuch', p_testbuch));
END $$;

-- ---------------------------------------------------------------------------
-- Nutzer liegen im Mandanten
-- ---------------------------------------------------------------------------
-- Entfernt die Nutzertabellen aus dem Buch und die Funktionen, die an ihnen hängen. Aufgerufen vom Überführen,
-- nachdem die Identitäten in die Mandanten-Datenbank umgezogen sind; in einem neuen Buch (leere Tabellen) gleich hier.
-- Das Protokoll des Buchs behält die alten Einträge `nutzer.*` – es ist unveränderlich.
CREATE FUNCTION kern.nutzer_auslagern() RETURNS void
LANGUAGE plpgsql AS $$
DECLARE f regprocedure;
BEGIN
    IF to_regclass('kern.nutzer') IS NULL THEN
        RETURN;
    END IF;
    FOR f IN
        SELECT p.oid::regprocedure FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
         WHERE n.nspname = 'kern' AND p.proname IN (
            '_nutzer_stand', '_traeger', 'nutzer_anlegen', 'nutzer_aendern', 'nutzer_angaben_ergaenzen', 'sprache_setzen',
            'einstellung_setzen', 'registrierung_ausstellen', 'registrierung_code_pruefen', 'anmelde_credential_registrieren',
            'anmelde_credential_widerrufen', 'anmelde_credential_uebernehmen', 'anmeldung_vermerken', 'sitzung_anlegen',
            'sitzung_pruefen', 'sitzung_beenden', 'sitzungen_aufraeumen')
    LOOP
        EXECUTE format('DROP FUNCTION %s CASCADE', f);
    END LOOP;
    DROP TABLE kern.nutzer_einstellung, kern.sitzung, kern.registrierung, kern.anmelde_credential, kern.nutzer CASCADE;
END $$;
REVOKE EXECUTE ON FUNCTION kern.nutzer_auslagern() FROM PUBLIC;

-- Zahl der Subjekte `nutzer:…`, die zum Zeitpunkt eine Rolle dieser Art tragen. Ob ein Nutzer gesperrt ist, weiß
-- nur der Mandant; die Anwendung prüft das beim Sperren (BUECHER.md A10).
CREATE FUNCTION kern._rollentraeger(p_art text, p_zeitpunkt timestamptz) RETURNS bigint
LANGUAGE sql STABLE SET search_path = pg_catalog, kern, pg_temp AS $$
    SELECT count(DISTINCT z.subjekt)
      FROM kern.zuweisung z JOIN kern.rolle r ON r.id = z.rolle
     WHERE z.subjekt LIKE 'nutzer:%' AND r.art = p_art
       AND z.gueltig_von <= p_zeitpunkt AND (z.gueltig_bis IS NULL OR z.gueltig_bis > p_zeitpunkt)
$$;
REVOKE EXECUTE ON FUNCTION kern._rollentraeger(text, timestamptz) FROM PUBLIC;

-- Der letzte Verantwortliche eines Buchs bleibt. Die Rolle Administrator wird im Buch nicht mehr zugewiesen;
-- eine alte Zuweisung lässt sich beenden.
CREATE OR REPLACE FUNCTION kern.zuweisung_beenden(p_subjekt text, p_zuweisung uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE z kern.zuweisung; ende timestamptz; art_ text; vorher bigint;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT * INTO z FROM kern.zuweisung WHERE id = p_zuweisung FOR UPDATE;
    IF NOT FOUND THEN PERFORM kern.verweigern('Zuweisung unbekannt', 'KM002'); END IF;
    ende := greatest(clock_timestamp(), z.gueltig_von + interval '1 microsecond');
    IF z.gueltig_bis IS NOT NULL AND z.gueltig_bis <= ende THEN
        PERFORM kern.verweigern('Zuweisung ist bereits beendet', 'KM003');
    END IF;
    SELECT art INTO art_ FROM kern.rolle WHERE id = z.rolle;
    IF art_ = 'verantwortlich' THEN
        vorher := kern._rollentraeger(art_, z.gueltig_von);
    END IF;
    UPDATE kern.zuweisung SET gueltig_bis = ende WHERE id = z.id;
    IF art_ = 'verantwortlich' AND vorher > 0 AND kern._rollentraeger(art_, ende) = 0 THEN
        PERFORM kern.verweigern('Die letzte Zuweisung der Rolle Verantwortlicher wird nicht beendet – sie geht mit dem Buch', 'KM003');
    END IF;
    PERFORM kern._protokoll('zuweisung.geaendert', p_subjekt, jsonb_build_object('zuweisung', z.id::text),
        jsonb_build_object('gueltig_bis', kern.zeitpunkt(z.gueltig_bis)),
        jsonb_build_object('gueltig_bis', kern.zeitpunkt(ende)));
END $$;

-- ---------------------------------------------------------------------------
-- Siegel: Fassung 2 des Siegelinhalts nennt das Buch (A14)
-- ---------------------------------------------------------------------------
DROP FUNCTION kern.siegel_anfordern(text, text);
CREATE FUNCTION kern.siegel_anfordern(p_subjekt text, p_mandant text, p_buch text) RETURNS uuid
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
    IF p_buch IS NULL OR p_buch IS DISTINCT FROM (SELECT kennung FROM kern.buch) THEN
        PERFORM kern.verweigern('Kennung des Buchs fehlt oder ist nicht die dieses Buchs', 'KM002');
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
        'fassung',    2,
        'mandant',    p_mandant,
        'buch',       p_buch,
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

REVOKE EXECUTE ON FUNCTION kern.buch_einrichten(text, text, text, boolean), kern.siegel_anfordern(text, text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION kern.siegel_anfordern(text, text, text) TO kumero_app;
-- kern.buch_einrichten ruft nur der Dienst bzw. die Kommandozeile (kumero_owner).

-- Neue Bücher (und die Vorlage) haben keine Nutzer: Tabellen gleich entfernen. Im Bestand tut es das Überführen.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM kern.nutzer) THEN
        PERFORM kern.nutzer_auslagern();
    END IF;
END $$;
