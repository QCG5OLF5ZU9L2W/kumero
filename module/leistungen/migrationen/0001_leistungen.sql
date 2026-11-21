-- Modul leistungen 0.1.0: Leistungskatalog und erbrachte Leistungen am Mandanten (LEISTUNGEN.md Abschnitt 10, F152, F154).
--
-- Die Leistungen gehören zum Mandanten, nicht ins Buch (LE1). Sie sind die Grundlage der Zuschläge, die an den Verkauf von
-- Dienstleistungen gebunden sind: Der Mitarbeiter trägt ein, der Vorgesetzte bestätigt (LE6), aus den bestätigten Zeilen
-- eines Monats rechnet die Lohnvorbereitung den Zuschlag samt Nachweis (personal._zuschlag_nachweis → leistungen.nachweis).
--   leistungen.leistung   – Katalog: Bezeichnung, Gruppe, Einheit; stillgelegt statt gelöscht            (keine Personendaten)
--   leistungen.preis      – Preise als Fassungen mit „gültig ab“                                          (keine Personendaten)
--   leistungen.zeile      – erbrachte Leistung: Person, Tag, Leistung, Menge, Betrag, Vorgangskennung     (Personendaten)
-- Kein Empfänger, kein Freitext an der Zeile (LE2). Kein Storno: Eine Zeile wird gelöscht (LE7) – aber nicht mehr, wenn die
-- Lohnvorbereitung des Monats geprüft oder übergeben ist. Bezeichnung, Gruppe und Preis werden beim Eintragen in die Zeile
-- kopiert: Wer den Katalog später ändert, ändert keine eingetragene Leistung. Das Modul bucht nicht (LE8).
-- Vom Personal liest es nur über dessen Schnittstelle (personal.api_personen, personal.api_lohn_fest).

CREATE SCHEMA leistungen AUTHORIZATION kumero_owner;
REVOKE ALL ON SCHEMA leistungen FROM PUBLIC;
GRANT USAGE ON SCHEMA leistungen TO kumero_app;

CREATE TABLE leistungen.leistung (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    bezeichnung   text NOT NULL CHECK (length(bezeichnung) BETWEEN 1 AND 60),
    gruppe        text CHECK (gruppe IS NULL OR length(gruppe) BETWEEN 1 AND 60),
    einheit       text NOT NULL DEFAULT 'Stück' CHECK (length(einheit) BETWEEN 1 AND 20),
    preis_frei    boolean NOT NULL DEFAULT false,      -- der Betrag darf je Zeile vom Katalogpreis abweichen
    stillgelegt   boolean NOT NULL DEFAULT false,
    geaendert_am  timestamptz NOT NULL DEFAULT clock_timestamp(),
    geaendert_von text NOT NULL
);
CREATE UNIQUE INDEX leistung_bezeichnung ON leistungen.leistung (lower(bezeichnung));

CREATE TABLE leistungen.preis (
    leistung     uuid NOT NULL REFERENCES leistungen.leistung,
    gueltig_ab   date NOT NULL,
    preis_cent   integer NOT NULL CHECK (preis_cent BETWEEN 0 AND 100000000),
    angelegt_von text NOT NULL,
    PRIMARY KEY (leistung, gueltig_ab)
);

CREATE TABLE leistungen.zeile (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    person         uuid NOT NULL,
    tag            date NOT NULL,
    leistung       uuid NOT NULL REFERENCES leistungen.leistung,
    bezeichnung    text NOT NULL,                      -- Stand des Katalogs beim Eintragen
    gruppe         text,
    einheit        text NOT NULL,
    menge          numeric(9,2) NOT NULL CHECK (menge > 0 AND menge <= 100000),
    einzel_cent    integer NOT NULL CHECK (einzel_cent BETWEEN 0 AND 100000000),
    betrag_cent    bigint NOT NULL CHECK (betrag_cent BETWEEN 0 AND 100000000000),
    vorgang        text CHECK (vorgang IS NULL OR vorgang ~ '^[A-Za-z0-9][A-Za-z0-9._/-]{0,39}$'),   -- neutrale Kennung, kein Name
    bestaetigt_am  timestamptz,
    bestaetigt_von text,
    angelegt_am    timestamptz NOT NULL DEFAULT clock_timestamp(),
    angelegt_von   text NOT NULL
    -- vorbereitet, nicht gebaut (P41): Kunde und Projekt kommen als eigene Spalten dazu
);
CREATE INDEX zeile_person_tag ON leistungen.zeile (person, tag);

CREATE FUNCTION leistungen._fehler(p_text text, p_code text DEFAULT 'KM002') RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION USING ERRCODE = p_code, MESSAGE = p_text;
END $$;

-- Ist die Lohnvorbereitung des Monats geprüft oder übergeben, ändert sich an seinen Leistungen nichts mehr (LF4).
CREATE FUNCTION leistungen._offen_verlangen(p_person uuid, p_tag date) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    IF personal.api_lohn_fest(p_person, date_trunc('month', p_tag)::date) THEN
        PERFORM leistungen._fehler('Die Lohnvorbereitung dieses Monats ist geprüft oder übergeben – an seinen Leistungen ändert sich nichts mehr', 'KM003');
    END IF;
END $$;

-- Katalog: Leistung anlegen (p_id NULL) oder ändern. p: bezeichnung, gruppe, einheit, preis_frei, stillgelegt
CREATE FUNCTION leistungen.leistung_setzen(p_subjekt text, p_id uuid, p jsonb) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, leistungen, pg_temp AS $$
DECLARE neu uuid; b text := nullif(trim(p->>'bezeichnung'), ''); g text := nullif(trim(p->>'gruppe'), ''); e text := coalesce(nullif(trim(p->>'einheit'), ''), 'Stück');
BEGIN
    IF b IS NULL OR length(b) > 60 OR length(coalesce(g, '')) > 60 OR length(e) > 20 THEN
        PERFORM leistungen._fehler('Leistung: Bezeichnung und Gruppe mit höchstens 60 Zeichen, Einheit mit höchstens 20');
    END IF;
    IF EXISTS (SELECT 1 FROM leistungen.leistung WHERE lower(bezeichnung) = lower(b) AND id IS DISTINCT FROM p_id) THEN
        PERFORM leistungen._fehler('Eine Leistung mit dieser Bezeichnung gibt es schon', 'KM003');
    END IF;
    IF p_id IS NULL THEN
        INSERT INTO leistungen.leistung (bezeichnung, gruppe, einheit, preis_frei, stillgelegt, geaendert_von)
        VALUES (b, g, e, coalesce((p->>'preis_frei')::boolean, false), coalesce((p->>'stillgelegt')::boolean, false), p_subjekt) RETURNING id INTO neu;
        PERFORM mandant.modul_protokoll(p_subjekt, 'leistungen', 'leistungen.leistung_angelegt', jsonb_build_object('leistung', neu::text), NULL, jsonb_build_object('bezeichnung', b, 'gruppe', g));
        RETURN neu;
    END IF;
    UPDATE leistungen.leistung SET bezeichnung = b, gruppe = g, einheit = e, preis_frei = coalesce((p->>'preis_frei')::boolean, false),
           stillgelegt = coalesce((p->>'stillgelegt')::boolean, false), geaendert_am = clock_timestamp(), geaendert_von = p_subjekt WHERE id = p_id;
    IF NOT FOUND THEN PERFORM leistungen._fehler('Leistung unbekannt', 'KM003'); END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'leistungen', 'leistungen.leistung_geaendert', jsonb_build_object('leistung', p_id::text), NULL, jsonb_build_object('bezeichnung', b, 'gruppe', g));
    RETURN p_id;
END $$;

-- Preis als Fassung: gilt ab seinem Tag; derselbe Tag noch einmal berichtigt den Preis dieses Tages.
CREATE FUNCTION leistungen.preis_setzen(p_subjekt text, p_leistung uuid, p_gueltig_ab date, p_preis_cent integer) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, leistungen, pg_temp AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM leistungen.leistung WHERE id = p_leistung) THEN PERFORM leistungen._fehler('Leistung unbekannt', 'KM003'); END IF;
    IF p_gueltig_ab IS NULL OR p_preis_cent IS NULL OR p_preis_cent NOT BETWEEN 0 AND 100000000 THEN
        PERFORM leistungen._fehler('Preis: „gültig ab“ und ein Betrag in Cent gehören dazu');
    END IF;
    INSERT INTO leistungen.preis (leistung, gueltig_ab, preis_cent, angelegt_von) VALUES (p_leistung, p_gueltig_ab, p_preis_cent, p_subjekt)
    ON CONFLICT (leistung, gueltig_ab) DO UPDATE SET preis_cent = EXCLUDED.preis_cent, angelegt_von = EXCLUDED.angelegt_von;
    PERFORM mandant.modul_protokoll(p_subjekt, 'leistungen', 'leistungen.preis_gesetzt', jsonb_build_object('leistung', p_leistung::text), NULL,
        jsonb_build_object('gueltig_ab', p_gueltig_ab::text, 'preis_cent', p_preis_cent));
END $$;

-- Der Preis einer Leistung an einem Tag: die Fassung mit dem spätesten „gültig ab“, das erreicht ist.
CREATE FUNCTION leistungen.preis_am(p_leistung uuid, p_tag date) RETURNS integer LANGUAGE sql STABLE AS $$
    SELECT preis_cent FROM leistungen.preis WHERE leistung = p_leistung AND gueltig_ab <= p_tag ORDER BY gueltig_ab DESC LIMIT 1
$$;

-- Leistung eintragen. p: tag, leistung, menge, einzel_cent (nur bei freiem Preis), vorgang. p_bestaetigt: Der Vorgesetzte
-- oder das Personal trägt für jemanden ein – die Zeile gilt sofort als bestätigt (LE6, LF3).
CREATE FUNCTION leistungen.zeile_eintragen(p_subjekt text, p_person uuid, p jsonb, p_bestaetigt boolean) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, leistungen, pg_temp AS $$
DECLARE l leistungen.leistung; t date; m numeric; einzel integer; neu uuid;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM personal.api_personen() x WHERE x.id = p_person) THEN PERFORM leistungen._fehler('Person unbekannt', 'KM003'); END IF;
    IF coalesce(p->>'tag', '') !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' OR coalesce(p->>'menge', '') !~ '^[0-9]{1,6}(\.[0-9]{1,2})?$' THEN
        PERFORM leistungen._fehler('Leistung eintragen: Tag als JJJJ-MM-TT und eine Menge gehören dazu');
    END IF;
    t := (p->>'tag')::date; m := (p->>'menge')::numeric;
    IF t > current_date + 1 THEN PERFORM leistungen._fehler('Eine Leistung wird nicht im Voraus eingetragen'); END IF;
    IF m <= 0 THEN PERFORM leistungen._fehler('Die Menge ist größer als null'); END IF;
    SELECT * INTO l FROM leistungen.leistung WHERE id = nullif(p->>'leistung', '')::uuid;
    IF NOT FOUND OR l.stillgelegt THEN PERFORM leistungen._fehler('Leistung unbekannt oder stillgelegt', 'KM003'); END IF;
    PERFORM leistungen._offen_verlangen(p_person, t);
    einzel := leistungen.preis_am(l.id, t);
    IF l.preis_frei AND p ? 'einzel_cent' AND jsonb_typeof(p->'einzel_cent') = 'number' THEN einzel := (p->>'einzel_cent')::integer; END IF;
    IF einzel IS NULL THEN PERFORM leistungen._fehler('Für diese Leistung gilt an dem Tag kein Preis – im Katalog einen Preis mit „gültig ab“ setzen'); END IF;
    INSERT INTO leistungen.zeile (person, tag, leistung, bezeichnung, gruppe, einheit, menge, einzel_cent, betrag_cent, vorgang, bestaetigt_am, bestaetigt_von, angelegt_von)
    VALUES (p_person, t, l.id, l.bezeichnung, l.gruppe, l.einheit, m, einzel, round(m * einzel)::bigint, nullif(trim(p->>'vorgang'), ''),
            CASE WHEN coalesce(p_bestaetigt, false) THEN clock_timestamp() END, CASE WHEN coalesce(p_bestaetigt, false) THEN p_subjekt END, p_subjekt)
    RETURNING id INTO neu;
    PERFORM mandant.modul_protokoll(p_subjekt, 'leistungen', 'leistungen.zeile_eingetragen', jsonb_build_object('person', p_person::text, 'zeile', neu::text));
    RETURN neu;
EXCEPTION WHEN check_violation THEN
    PERFORM leistungen._fehler('Leistung eintragen: Betrag oder Vorgangskennung passt nicht (Kennung: Buchstaben, Ziffern und . _ / -, höchstens 40 Zeichen)');
    RETURN NULL;
END $$;

CREATE FUNCTION leistungen.zeile_bestaetigen(p_subjekt text, p_id uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, leistungen, pg_temp AS $$
DECLARE z leistungen.zeile;
BEGIN
    SELECT * INTO z FROM leistungen.zeile WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM leistungen._fehler('Leistung unbekannt', 'KM003'); END IF;
    IF z.bestaetigt_am IS NOT NULL THEN PERFORM leistungen._fehler('Die Leistung ist schon bestätigt', 'KM003'); END IF;
    PERFORM leistungen._offen_verlangen(z.person, z.tag);
    UPDATE leistungen.zeile SET bestaetigt_am = clock_timestamp(), bestaetigt_von = p_subjekt WHERE id = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'leistungen', 'leistungen.zeile_bestaetigt', jsonb_build_object('person', z.person::text, 'zeile', p_id::text));
END $$;

-- Kein Storno (LE7): Die Zeile wird gelöscht. Im Protokoll bleibt, dass es sie gab – ohne Inhalt.
CREATE FUNCTION leistungen.zeile_loeschen(p_subjekt text, p_id uuid) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, leistungen, pg_temp AS $$
DECLARE z leistungen.zeile;
BEGIN
    SELECT * INTO z FROM leistungen.zeile WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM leistungen._fehler('Leistung unbekannt', 'KM003'); END IF;
    PERFORM leistungen._offen_verlangen(z.person, z.tag);
    DELETE FROM leistungen.zeile WHERE id = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'leistungen', 'leistungen.zeile_geloescht', jsonb_build_object('person', z.person::text, 'zeile', p_id::text));
END $$;

-- Für die Lohnvorbereitung: Zuschlag einer Regel aus den bestätigten Leistungen einer Person in einem Monat, mit Nachweis.
--   anteil  – satz_bp (Hundertstel Prozent) vom Betrag jeder Zeile
--   betrag  – betrag_cent je Einheit der Menge
--   staffel – bis zur Schwelle im Monat satz_bp, für den Teil darüber satz_ab_schwelle_bp (in der Reihenfolge der Tage)
-- Umfang: alle Leistungen, eine Gruppe oder eine einzelne Leistung (Bezug = ihre Bezeichnung, ohne Groß- und Kleinschreibung).
CREATE FUNCTION leistungen.nachweis(p_person uuid, p_monat date, p_form text, p_satz_bp integer, p_betrag_cent integer, p_schwelle_cent integer,
                                    p_satz_ab_bp integer, p_umfang text, p_bezug text) RETURNS jsonb
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, leistungen, pg_temp AS $$
DECLARE z record; liste jsonb := '[]'; summe bigint := 0; bisher bigint := 0; b bigint; unten bigint;
BEGIN
    FOR z IN SELECT x.* FROM leistungen.zeile x
              WHERE x.person = p_person AND x.tag >= p_monat AND x.tag < (p_monat + interval '1 month')::date AND x.bestaetigt_am IS NOT NULL
                AND (p_umfang = 'alle' OR (p_umfang = 'gruppe' AND lower(x.gruppe) = lower(p_bezug)) OR (p_umfang = 'leistung' AND lower(x.bezeichnung) = lower(p_bezug)))
              ORDER BY x.tag, x.angelegt_am, x.id LOOP
        IF p_form = 'anteil' THEN
            b := round(z.betrag_cent * coalesce(p_satz_bp, 0) / 10000.0);
        ELSIF p_form = 'betrag' THEN
            b := round(z.menge * coalesce(p_betrag_cent, 0));
        ELSE
            unten := greatest(0, least(z.betrag_cent, coalesce(p_schwelle_cent, 0) - bisher));
            b := round(unten * coalesce(p_satz_bp, 0) / 10000.0) + round((z.betrag_cent - unten) * coalesce(p_satz_ab_bp, 0) / 10000.0);
        END IF;
        bisher := bisher + z.betrag_cent;
        summe := summe + b;
        liste := liste || jsonb_build_object('datum', z.tag::text, 'text', z.bezeichnung || coalesce(' · ' || z.vorgang, ''), 'menge', z.menge::text,
                                             'grundlage_cent', z.betrag_cent, 'betrag_cent', b);
    END LOOP;
    -- ohne bestätigte Leistung gibt es keinen Nachweis: Die Unterlage sagt dann „Nachweis fehlt – keine bestätigten Leistungen“
    IF jsonb_array_length(liste) = 0 THEN RETURN NULL; END IF;
    RETURN jsonb_build_object('betrag_cent', summe, 'nachweis', liste);
END $$;

REVOKE ALL ON ALL TABLES IN SCHEMA leistungen FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON ALL TABLES IN SCHEMA leistungen TO kumero_app;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA leistungen FROM PUBLIC;
GRANT EXECUTE ON FUNCTION leistungen.leistung_setzen(text, uuid, jsonb), leistungen.preis_setzen(text, uuid, date, integer), leistungen.preis_am(uuid, date),
    leistungen.zeile_eintragen(text, uuid, jsonb, boolean), leistungen.zeile_bestaetigen(text, uuid), leistungen.zeile_loeschen(text, uuid) TO kumero_app;
