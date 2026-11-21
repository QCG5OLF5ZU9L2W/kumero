-- Modul personal 0.7.0: Die Personalnummer vergibt kumero – zufällig; akademischer Titel an der Person (F147).
--
-- Vorgabe des Verantwortlichen vom 2026-11-21: Eine aufsteigende Nummer verrät, wie lange jemand im Betrieb ist.
-- Die Nummer ist deshalb ein zufälliger, eindeutiger Wert aus Großbuchstaben und Ziffern ohne Zeichen, die sich
-- verwechseln lassen (kein 0/O, 1/I/L, 2/Z, 5/S, 8/B). Sechs Zeichen aus 25 – rund 244 Millionen Werte.
-- Niemand tippt sie mehr ein. Bestehende Nummern bleiben, bis das Personal eine neue vergeben lässt
-- (person_setzen mit "personalnummer_neu": true) – ein Update ändert sie nicht von selbst.

-- Akademischer Titel („Dr.“, „Prof. Dr. med.“): freier Text, Teil des Namens – wer den Namen sieht, sieht ihn.
ALTER TABLE personal.person ADD COLUMN akademischer_titel text CHECK (akademischer_titel IS NULL OR length(akademischer_titel) BETWEEN 1 AND 40);

CREATE FUNCTION personal._personalnummer() RETURNS text
LANGUAGE plpgsql VOLATILE SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE zeichen CONSTANT text := 'ACDEFGHJKMNPQRTUVWXY34679';   -- 25 Zeichen
        bytes bytea; b integer; nr text;
BEGIN
    FOR versuch IN 1..50 LOOP
        nr := '';
        WHILE length(nr) < 6 LOOP
            -- gen_random_uuid() ist die Zufallsquelle des Servers; Byte 6 und 8 tragen Version und Variante und bleiben weg
            bytes := uuid_send(gen_random_uuid());
            FOREACH b IN ARRAY ARRAY[0, 1, 2, 3, 4, 5, 9, 10, 11, 12, 13, 14, 15] LOOP
                -- 250 = 10 × 25: Bytes darüber verwerfen, sonst wären die ersten Zeichen häufiger
                IF get_byte(bytes, b) < 250 AND length(nr) < 6 THEN
                    nr := nr || substr(zeichen, get_byte(bytes, b) % 25 + 1, 1);
                END IF;
            END LOOP;
        END LOOP;
        IF NOT EXISTS (SELECT 1 FROM personal.person WHERE upper(personalnummer) = nr) THEN RETURN nr; END IF;
    END LOOP;
    RAISE EXCEPTION 'Keine freie Personalnummer gefunden';
END $$;
REVOKE EXECUTE ON FUNCTION personal._personalnummer() FROM PUBLIC;

-- Anlegen: die Nummer kommt von kumero. Ändern: sie bleibt, außer es wird ausdrücklich eine neue verlangt.
CREATE OR REPLACE FUNCTION personal.person_setzen(p_subjekt text, p_id uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE alt personal.person; neu personal.person; v uuid := nullif(p->>'vorgesetzter', '')::uuid; n uuid := nullif(p->>'nutzer', '')::uuid;
        x uuid; tiefe integer := 0;
BEGIN
    IF personal._t(p, 'familienname') IS NULL THEN
        PERFORM personal._fehler('Der Familienname gehört dazu');
    END IF;
    IF n IS NOT NULL AND EXISTS (SELECT 1 FROM personal.person WHERE nutzer = n AND id IS DISTINCT FROM p_id) THEN
        PERFORM personal._fehler('Dieser Nutzer ist schon mit einer anderen Person verknüpft', 'KM003');
    END IF;
    IF v IS NOT NULL AND NOT EXISTS (SELECT 1 FROM personal.person WHERE id = v) THEN
        PERFORM personal._fehler('Vorgesetzter unbekannt');
    END IF;
    -- kein Kreis: Der Vorgesetzte darf nicht unter der Person stehen
    x := v;
    WHILE x IS NOT NULL AND p_id IS NOT NULL LOOP
        IF x = p_id THEN PERFORM personal._fehler('Die Person stünde über sich selbst – der Vorgesetzte steht unter ihr'); END IF;
        tiefe := tiefe + 1;
        EXIT WHEN tiefe > 50;
        SELECT vorgesetzter INTO x FROM personal.person WHERE id = x;
    END LOOP;
    IF p_id IS NULL THEN
        INSERT INTO personal.person (personalnummer, familienname, vornamen, akademischer_titel, vorgesetzter, nutzer, austritt, geaendert_von)
        VALUES (personal._personalnummer(), personal._t(p, 'familienname'), personal._t(p, 'vornamen'), personal._t(p, 'akademischer_titel'), v, n, personal._datum(p, 'austritt'), p_subjekt)
        RETURNING * INTO neu;
        INSERT INTO personal.privat (person, geaendert_von) VALUES (neu.id, p_subjekt);
        PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.person_angelegt', jsonb_build_object('person', neu.id::text));
        RETURN neu.id;
    END IF;
    SELECT * INTO alt FROM personal.person WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Person unbekannt', 'KM003'); END IF;
    UPDATE personal.person SET personalnummer = CASE WHEN coalesce((p->>'personalnummer_neu')::boolean, false) THEN personal._personalnummer() ELSE personalnummer END, familienname = personal._t(p, 'familienname'),
           vornamen = personal._t(p, 'vornamen'), akademischer_titel = personal._t(p, 'akademischer_titel'), vorgesetzter = v, nutzer = n, austritt = personal._datum(p, 'austritt'),
           geaendert_am = clock_timestamp(), geaendert_von = p_subjekt
     WHERE id = p_id RETURNING * INTO neu;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.person_geaendert', jsonb_build_object('person', p_id::text), NULL,
        jsonb_build_object('felder', personal._geaendert(
            to_jsonb(alt) - ARRAY['geaendert_am', 'geaendert_von', 'angelegt_am', 'loeschen_ab'],
            to_jsonb(neu) - ARRAY['geaendert_am', 'geaendert_von', 'angelegt_am', 'loeschen_ab'])));
    RETURN p_id;
END $$;

-- Der Name trägt den Titel: Schnittstelle zur Zeiterfassung und Lohnunterlage
CREATE OR REPLACE FUNCTION personal.api_personen()
RETURNS TABLE (id uuid, personalnummer text, name text, nutzer uuid, vorgesetzter uuid, eintritt date, austritt date)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
    SELECT p.id, p.personalnummer, p.familienname || coalesce(', ' || nullif(concat_ws(' ', p.akademischer_titel, p.vornamen), ''), ''), p.nutzer, p.vorgesetzter,
           (SELECT min(v.beginn) FROM personal.vertrag v WHERE v.person = p.id), p.austritt
      FROM personal.person p ORDER BY lower(p.familienname), lower(coalesce(p.vornamen, '')), p.id
$$;

CREATE OR REPLACE FUNCTION personal._lohn_inhalt(p_person uuid, p_monat date) RETURNS jsonb LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE p personal.person; v personal.vertrag; ende date := (p_monat + interval '1 month' - interval '1 day')::date;
        eintritt date; von date; bis date; soll integer := 0; arbeitstage integer := 0; abw jsonb; summen jsonb; aenderungen jsonb; zus jsonb := '[]'; hand jsonb;
        ort uuid; r record; n jsonb; summe bigint := 0; hsumme bigint; ist jsonb := personal._ist(p_person, p_monat);
BEGIN
    SELECT * INTO p FROM personal.person WHERE id = p_person;
    IF NOT FOUND THEN PERFORM personal._fehler('Person unbekannt', 'KM003'); END IF;
    SELECT min(beginn) INTO eintritt FROM personal.vertrag WHERE person = p_person;
    SELECT * INTO v FROM personal.vertrag WHERE id = personal.vertrag_am(p_person, least(ende, coalesce(p.austritt, ende)));
    von := greatest(p_monat, coalesce(eintritt, p_monat));
    bis := least(ende, coalesce(p.austritt, ende));
    ort := personal._arbeitsort_von(p_person);

    -- Soll aus dem Vertrag des jeweiligen Tages, ohne Feiertage am Arbeitsort und freie Tage des Betriebs
    IF eintritt IS NOT NULL AND von <= bis THEN
        SELECT coalesce(sum(m), 0), count(*) FILTER (WHERE m > 0) INTO soll, arbeitstage
          FROM (SELECT (SELECT x.verteilung[extract(isodow FROM g.t)::integer] FROM personal.vertrag x WHERE x.id = personal.vertrag_am(p_person, g.t::date)) AS m
                  FROM generate_series(von::timestamp, bis::timestamp, interval '1 day') AS g(t)
                 WHERE NOT EXISTS (SELECT 1 FROM personal.freier_tag f WHERE f.datum = g.t::date)
                   AND NOT EXISTS (SELECT 1 FROM personal.feiertage(ort, von, bis) ft WHERE ft.datum = g.t::date)) s;
    END IF;

    -- Abwesenheiten, die gelten und den Monat berühren; über die Monatsgrenze nur mit dem Teil im Monat
    WITH z AS (
        SELECT art.name, art.bezahlt, greatest(x.von, p_monat) AS von, least(x.bis, ende) AS bis, x.halber_tag,
               (x.von < p_monat OR x.bis > ende) AS anteilig,
               CASE WHEN x.von >= p_monat AND x.bis <= ende THEN x.tage
                    ELSE personal._arbeitstage(p_person, greatest(x.von, p_monat), least(x.bis, ende)) END AS tage
          FROM personal.abwesenheit x JOIN personal.abwesenheitsart art ON art.id = x.art
         WHERE x.person = p_person AND x.zustand = 'genehmigt' AND x.von <= ende AND x.bis >= p_monat)
    SELECT coalesce((SELECT jsonb_agg(jsonb_build_object('art', z.name, 'bezahlt', z.bezahlt, 'von', z.von::text, 'bis', z.bis::text, 'tage', z.tage,
                                                         'halber_tag', z.halber_tag, 'anteilig', z.anteilig) ORDER BY z.von, z.name) FROM z), '[]'),
           coalesce((SELECT jsonb_agg(jsonb_build_object('art', s.name, 'bezahlt', s.bezahlt, 'tage', s.tage) ORDER BY s.name)
                       FROM (SELECT z.name, z.bezahlt, sum(z.tage) AS tage FROM z GROUP BY z.name, z.bezahlt) s), '[]')
      INTO abw, summen;

    -- Fassungen des Vertrags, die im Monat zu gelten beginnen (ohne die erste): nur die Namen der geänderten Felder
    SELECT coalesce(jsonb_agg(jsonb_build_object('gueltig_ab', f.gueltig_ab::text, 'felder', f.felder) ORDER BY f.gueltig_ab, f.angelegt_am), '[]') INTO aenderungen
      FROM (SELECT x.gueltig_ab, x.angelegt_am,
                   personal._geaendert(to_jsonb(lag(x) OVER w) - ARRAY['id', 'gueltig_ab', 'angelegt_am', 'angelegt_von', 'loeschen_ab'],
                                       to_jsonb(x) - ARRAY['id', 'gueltig_ab', 'angelegt_am', 'angelegt_von', 'loeschen_ab']) AS felder,
                   row_number() OVER w AS nr
              FROM personal.vertrag x WHERE x.person = p_person WINDOW w AS (ORDER BY x.gueltig_ab, x.angelegt_am)) f
     WHERE f.nr > 1 AND f.gueltig_ab BETWEEN p_monat AND ende;

    -- Zuschläge aus Regeln: an der Person oder an ihrer Funktion, mit der Fassung, die am Monatsende gilt
    FOR r IN SELECT DISTINCT ON (g.id) g.id, g.name, g.grundlage, g.lohnart, fa.id AS fassung, fa.gueltig_ab, fa.zeitart
               FROM personal.zuschlag_zuordnung z JOIN personal.zuschlagsregel g ON g.id = z.regel AND NOT g.stillgelegt
               JOIN personal.zuschlagsregel_fassung fa ON fa.id = personal._regel_fassung_am(g.id, ende)
              WHERE z.von <= ende AND (z.bis IS NULL OR z.bis >= p_monat)
                AND (z.person = p_person OR (z.funktion IS NOT NULL AND lower(trim(z.funktion)) = lower(trim(coalesce(v.funktion, '')))))
              ORDER BY g.id LOOP
        n := personal._zuschlag_nachweis(r.fassung, p_person, p_monat);
        zus := zus || jsonb_build_object('art', 'regel', 'regel', r.id::text, 'name', r.name, 'grundlage', r.grundlage, 'lohnart', r.lohnart, 'fassung_ab', r.gueltig_ab::text,
            'betrag_cent', n->'betrag_cent', 'nachweis', n->'nachweis',
            -- Zeit: Liefert die Zeiterfassung die Stunden des Fensters, stehen sie als Nachweis da; der Betrag braucht den Grundlohn
            'menge_minuten', CASE WHEN n IS NULL AND r.grundlage = 'zeit' THEN ist->'zeitarten'->r.zeitart END,
            'hinweis', CASE WHEN n IS NOT NULL THEN NULL
                            WHEN r.grundlage = 'zeit' AND ist->'zeitarten' ? r.zeitart THEN 'Stunden liegen vor – der Betrag braucht den Grundlohn (Vergütung ist zurückgestellt)'
                            ELSE 'Nachweis fehlt – ' || CASE r.grundlage WHEN 'zeit' THEN 'kein freigegebenes Monatsblatt (Zeiterfassung)' ELSE 'keine bestätigten Leistungen (Modul Leistungen)' END END);
        summe := summe + coalesce((n->>'betrag_cent')::bigint, 0);
    END LOOP;
    zus := (SELECT coalesce(jsonb_agg(e ORDER BY e->>'name'), '[]') FROM jsonb_array_elements(zus) e);

    -- Zuschläge von Hand
    SELECT coalesce(jsonb_agg(jsonb_build_object('art', 'hand', 'id', h.id::text, 'name', h.begruendung, 'lohnart', h.lohnart, 'betrag_cent', h.betrag_cent) ORDER BY h.angelegt_am), '[]'),
           coalesce(sum(h.betrag_cent), 0)
      INTO hand, hsumme FROM personal.zuschlag_hand h WHERE h.person = p_person AND h.monat = p_monat AND h.zurueckgenommen_am IS NULL;
    summe := summe + hsumme;

    RETURN jsonb_build_object(
        'monat', to_char(p_monat, 'YYYY-MM'),
        'person', jsonb_build_object('personalnummer', p.personalnummer, 'familienname', p.familienname, 'vornamen', p.vornamen, 'akademischer_titel', p.akademischer_titel),
        'vertrag', CASE WHEN v.id IS NULL THEN NULL ELSE jsonb_build_object('art', v.art, 'funktion', v.funktion, 'wochenminuten', v.wochenminuten, 'lohnbuch', v.lohnbuch,
                        'beginn', v.beginn::text, 'befristet_bis', v.befristet_bis::text) END,
        'eintritt', CASE WHEN eintritt BETWEEN p_monat AND ende THEN eintritt::text END,
        'austritt', CASE WHEN p.austritt BETWEEN p_monat AND ende THEN p.austritt::text END,
        'soll_minuten', soll, 'arbeitstage', arbeitstage,
        'ist_minuten', ist->'ist_minuten', 'ueber_minuten', ist->'ueber_minuten', 'zeitarten', ist->'zeitarten',
        'ist_hinweis', coalesce(ist->>'hinweis', CASE WHEN ist IS NULL THEN 'Nachweis fehlt – Ist- und Überstunden kommen aus der Zeiterfassung' END),
        'abwesenheiten', abw, 'abwesenheit_summen', summen, 'vertragsaenderungen', aenderungen,
        'zuschlaege', zus || hand, 'zuschlag_summe_cent', summe);
END $$;
