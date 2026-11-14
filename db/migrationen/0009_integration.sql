-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Kern, Migration 0009: Idempotenz, Modulprotokoll, Ereigniszustellung, Webhooks
--
--   * Idempotenz (F19, F71): Ein schreibender Aufruf beginnt mit
--     kern.idempotenz_beginnen() und endet mit kern.idempotenz_abschliessen() –
--     beides in derselben Transaktion wie die eigentliche Arbeit. Scheitert die
--     Arbeit, bleibt nichts zurück und eine Wiederholung läuft erneut.
--     Der Schlüssel wird in jedem Protokolleintrag der Transaktion vermerkt (F19).
--     Neuer Fehlercode KM006: gleicher Schlüssel, andere Anfrage → HTTP 409.
--   * kern.modul_protokoll(): Protokolleintrag und Ereignis eines Moduls (F48),
--     Typ mit dem Modulnamen als Präfix.
--   * Empfänger im Prozess (F46 Teil 2): je Modul ein Lesestand in der Outbox.
--   * Webhooks (F21): Abonnements mit Protokoll, Zustellung mit Wiederholung.

-- ---------------------------------------------------------------------------
-- Protokoll: Idempotenzschlüssel der laufenden Transaktion mitschreiben (F19)
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION kern._protokoll(p_typ text, p_subjekt text, p_ids jsonb,
                                           p_vorher jsonb DEFAULT NULL, p_nachher jsonb DEFAULT NULL,
                                           p_freigabe uuid DEFAULT NULL) RETURNS bigint
LANGUAGE plpgsql AS $$
DECLARE n bigint; ids jsonb := coalesce(p_ids, '{}'); schluessel text;
BEGIN
    schluessel := nullif(current_setting('kumero.idempotenz', true), '');
    IF schluessel IS NOT NULL THEN
        ids := ids || jsonb_build_object('idempotenz', schluessel);
    END IF;
    INSERT INTO kern.protokoll (typ, subjekt, ids, vorher, nachher, freigabe)
         VALUES (p_typ, p_subjekt, ids, p_vorher, p_nachher, p_freigabe)
      RETURNING nr INTO n;
    INSERT INTO kern.outbox (protokoll_nr, typ, inhalt) VALUES (n, p_typ, ids);
    INSERT INTO kern.zustellung (abo, protokoll_nr)
         SELECT a.id, n FROM kern.webhook_abo a
          WHERE a.aktiv AND (p_typ = ANY (a.ereignisse) OR '*' = ANY (a.ereignisse));
    RETURN n;
END $$;

-- ---------------------------------------------------------------------------
-- Idempotenz (F71)
-- ---------------------------------------------------------------------------
CREATE FUNCTION kern.idempotenz_beginnen(p_subjekt text, p_schluessel text, p_endpunkt text, p_anfrage_hash text,
                                         p_dauer interval DEFAULT interval '24 hours')
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE i kern.idempotenz; neu boolean;
BEGIN
    PERFORM kern._subjekt(p_subjekt);
    IF nullif(current_setting('kumero.idempotenz', true), '') IS NOT NULL THEN
        PERFORM kern.verweigern('In dieser Transaktion läuft bereits ein idempotenter Aufruf', 'KM003');
    END IF;
    DELETE FROM kern.idempotenz WHERE subjekt = p_subjekt AND schluessel = p_schluessel AND ablauf <= now();
    -- Ein gleichzeitiger Aufruf mit demselben Schlüssel wartet hier, bis der erste fertig ist.
    INSERT INTO kern.idempotenz (subjekt, schluessel, endpunkt, anfrage_hash, ablauf)
         VALUES (p_subjekt, p_schluessel, p_endpunkt, p_anfrage_hash, now() + p_dauer)
    ON CONFLICT DO NOTHING
    RETURNING true INTO neu;
    IF neu THEN
        PERFORM set_config('kumero.idempotenz', p_schluessel, true);
        RETURN jsonb_build_object('neu', true);
    END IF;
    SELECT * INTO i FROM kern.idempotenz WHERE subjekt = p_subjekt AND schluessel = p_schluessel;
    IF i.endpunkt <> p_endpunkt OR i.anfrage_hash <> p_anfrage_hash THEN
        PERFORM kern.verweigern('Idempotenzschlüssel wurde schon für eine andere Anfrage verwendet (F71)', 'KM006');
    END IF;
    RETURN jsonb_build_object('neu', false, 'status', i.antwort_status, 'inhalt', i.antwort_inhalt);
END $$;

CREATE FUNCTION kern.idempotenz_abschliessen(p_subjekt text, p_schluessel text, p_status integer, p_inhalt jsonb)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
BEGIN
    IF nullif(current_setting('kumero.idempotenz', true), '') IS DISTINCT FROM p_schluessel THEN
        PERFORM kern.verweigern('Idempotenzschlüssel gehört nicht zu dieser Transaktion', 'KM003');
    END IF;
    UPDATE kern.idempotenz SET antwort_status = p_status, antwort_inhalt = p_inhalt
     WHERE subjekt = p_subjekt AND schluessel = p_schluessel AND antwort_status IS NULL;
    IF NOT FOUND THEN PERFORM kern.verweigern('Idempotenter Aufruf nicht offen', 'KM003'); END IF;
    PERFORM set_config('kumero.idempotenz', '', true);
END $$;

CREATE FUNCTION kern.idempotenz_aufraeumen() RETURNS integer
LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
    WITH weg AS (DELETE FROM kern.idempotenz WHERE ablauf <= now() RETURNING 1) SELECT count(*)::integer FROM weg
$$;

-- ---------------------------------------------------------------------------
-- Protokoll und Ereignisse von Modulen (F44, F48)
-- ---------------------------------------------------------------------------
CREATE FUNCTION kern.modul_protokoll(p_subjekt text, p_modul text, p_typ text, p_ids jsonb,
                                     p_vorher jsonb DEFAULT NULL, p_nachher jsonb DEFAULT NULL)
RETURNS bigint LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    IF p_modul = 'kern' OR NOT EXISTS (SELECT 1 FROM kern.modul WHERE name = p_modul AND aktiv) THEN
        PERFORM kern.verweigern(format('Modul %s ist nicht aktiv registriert', p_modul), 'KM002');
    END IF;
    IF split_part(p_typ, '.', 1) <> p_modul THEN
        PERFORM kern.verweigern(format('Ereignistyp %s muss mit %s. beginnen (F48)', p_typ, p_modul), 'KM002');
    END IF;
    IF p_ids IS NULL OR jsonb_typeof(p_ids) <> 'object' THEN
        PERFORM kern.verweigern('ids als Objekt angeben', 'KM002');
    END IF;
    RETURN kern._protokoll(p_typ, p_subjekt, p_ids, p_vorher, p_nachher);
END $$;

-- ---------------------------------------------------------------------------
-- Empfänger im Prozess: Lesestand je Modul (F21, F46)
-- ---------------------------------------------------------------------------
CREATE TABLE kern.empfaenger (
    modul   text PRIMARY KEY REFERENCES kern.modul,
    bis_nr  bigint NOT NULL CHECK (bis_nr >= 0)
);
GRANT SELECT ON kern.empfaenger TO kumero_app, kumero_lesen;

-- Liefert die nächsten Ereignisse für ein Modul und sperrt dessen Lesestand
-- bis zum Ende der Transaktion. Die Verarbeitung und kern.empfaenger_vermerken()
-- laufen in derselben Transaktion: Datenbankwirkungen genau einmal.
-- Ein neu angemeldeter Empfänger beginnt beim aktuellen Stand (keine Altlasten).
-- Die Reihenfolge der Protokollnummern entspricht der Commit-Reihenfolge,
-- weil Kettenkopf 2 bis zum Commit gesperrt bleibt (F71).
CREATE FUNCTION kern.empfaenger_holen(p_modul text, p_anzahl integer DEFAULT 100)
RETURNS SETOF kern.outbox LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE stand bigint;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM kern.modul WHERE name = p_modul AND aktiv) THEN
        PERFORM kern.verweigern(format('Modul %s ist nicht aktiv registriert', p_modul), 'KM002');
    END IF;
    INSERT INTO kern.empfaenger (modul, bis_nr)
         SELECT p_modul, letzte_nr FROM kern.kettenkopf WHERE kette = 2
    ON CONFLICT DO NOTHING;
    SELECT bis_nr INTO stand FROM kern.empfaenger WHERE modul = p_modul FOR UPDATE;
    RETURN QUERY SELECT * FROM kern.outbox WHERE protokoll_nr > stand ORDER BY protokoll_nr LIMIT p_anzahl;
END $$;

CREATE FUNCTION kern.empfaenger_vermerken(p_modul text, p_bis_nr bigint)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE stand bigint;
BEGIN
    SELECT bis_nr INTO stand FROM kern.empfaenger WHERE modul = p_modul FOR UPDATE;
    IF NOT FOUND THEN PERFORM kern.verweigern('Empfänger unbekannt', 'KM002'); END IF;
    IF p_bis_nr < stand OR NOT EXISTS (SELECT 1 FROM kern.outbox WHERE protokoll_nr = p_bis_nr) THEN
        PERFORM kern.verweigern('Lesestand nur vorwärts und nur auf ein vorhandenes Ereignis', 'KM003');
    END IF;
    UPDATE kern.empfaenger SET bis_nr = p_bis_nr WHERE modul = p_modul;
END $$;

-- ---------------------------------------------------------------------------
-- Webhooks (F21): Abonnements und Zustellung
-- ---------------------------------------------------------------------------
CREATE FUNCTION kern._webhook_stand(a kern.webhook_abo) RETURNS jsonb
LANGUAGE sql IMMUTABLE AS $$
    SELECT jsonb_build_object('url', a.url, 'ereignisse', to_jsonb(a.ereignisse),
                              'geheimnis_ref', a.geheimnis_ref, 'aktiv', a.aktiv)
$$;

CREATE FUNCTION kern.webhook_abo_anlegen(p_subjekt text, p_url text, p_ereignisse text[], p_geheimnis_ref text)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE a kern.webhook_abo;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    INSERT INTO kern.webhook_abo (url, ereignisse, geheimnis_ref) VALUES (p_url, p_ereignisse, p_geheimnis_ref)
        RETURNING * INTO a;
    PERFORM kern._protokoll('webhook.angelegt', p_subjekt, jsonb_build_object('webhook', a.id::text),
                            NULL, kern._webhook_stand(a));
    RETURN a.id;
END $$;

CREATE FUNCTION kern.webhook_abo_aendern(p_subjekt text, p_id uuid, p_ereignisse text[], p_aktiv boolean)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE alt kern.webhook_abo; neu kern.webhook_abo;
BEGIN
    PERFORM kern._subjekt(p_subjekt); PERFORM kern._schreibbar();
    SELECT * INTO alt FROM kern.webhook_abo WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM kern.verweigern('Webhook-Abonnement unbekannt', 'KM002'); END IF;
    UPDATE kern.webhook_abo SET ereignisse = p_ereignisse, aktiv = p_aktiv WHERE id = p_id RETURNING * INTO neu;
    PERFORM kern._protokoll('webhook.geaendert', p_subjekt, jsonb_build_object('webhook', p_id::text),
                            kern._webhook_stand(alt), kern._webhook_stand(neu));
END $$;

-- Fällige Zustellungen; parallele Zusteller überspringen gesperrte Zeilen.
CREATE FUNCTION kern.zustellung_holen(p_anzahl integer DEFAULT 50)
RETURNS TABLE (abo uuid, protokoll_nr bigint, url text, geheimnis_ref text, typ text, inhalt jsonb, versuche integer)
LANGUAGE sql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
    WITH f AS (
        SELECT z.abo, z.protokoll_nr FROM kern.zustellung z
         WHERE z.status = 'ausstehend' AND z.naechster_versuch <= now()
         ORDER BY z.protokoll_nr
         LIMIT p_anzahl
         FOR UPDATE SKIP LOCKED)
    SELECT z.abo, z.protokoll_nr, a.url, a.geheimnis_ref, o.typ, o.inhalt, z.versuche
      FROM f JOIN kern.zustellung z USING (abo, protokoll_nr)
      JOIN kern.webhook_abo a ON a.id = z.abo
      JOIN kern.outbox o ON o.protokoll_nr = z.protokoll_nr
     ORDER BY z.protokoll_nr
$$;

-- Ergebnis einer Zustellung. Fehlschlag: Wiederholung nach 1, 2, 4 … Minuten
-- (höchstens 24 Stunden Abstand); nach 20 Versuchen 'fehlgeschlagen'.
CREATE FUNCTION kern.zustellung_vermerken(p_abo uuid, p_protokoll_nr bigint, p_erfolg boolean)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp AS $$
DECLARE z kern.zustellung;
BEGIN
    SELECT * INTO z FROM kern.zustellung WHERE abo = p_abo AND protokoll_nr = p_protokoll_nr FOR UPDATE;
    IF NOT FOUND OR z.status <> 'ausstehend' THEN
        PERFORM kern.verweigern('Zustellung nicht offen', 'KM003');
    END IF;
    IF p_erfolg THEN
        UPDATE kern.zustellung SET status = 'erledigt', versuche = z.versuche + 1
         WHERE abo = p_abo AND protokoll_nr = p_protokoll_nr;
    ELSE
        UPDATE kern.zustellung
           SET versuche = z.versuche + 1,
               status = CASE WHEN z.versuche + 1 >= 20 THEN 'fehlgeschlagen'::kern.erledigung ELSE 'ausstehend' END,
               naechster_versuch = now() + least(interval '1 minute' * power(2, z.versuche), interval '24 hours')
         WHERE abo = p_abo AND protokoll_nr = p_protokoll_nr;
    END IF;
END $$;

REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA kern FROM PUBLIC;
GRANT EXECUTE ON FUNCTION
    kern.idempotenz_beginnen(text, text, text, text, interval),
    kern.idempotenz_abschliessen(text, text, integer, jsonb),
    kern.idempotenz_aufraeumen(),
    kern.modul_protokoll(text, text, text, jsonb, jsonb, jsonb),
    kern.empfaenger_holen(text, integer),
    kern.empfaenger_vermerken(text, bigint),
    kern.webhook_abo_anlegen(text, text, text[], text),
    kern.webhook_abo_aendern(text, uuid, text[], boolean),
    kern.zustellung_holen(integer),
    kern.zustellung_vermerken(uuid, bigint, boolean)
  TO kumero_app;
