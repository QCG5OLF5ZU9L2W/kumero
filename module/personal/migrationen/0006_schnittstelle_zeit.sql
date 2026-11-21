-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul personal, Migration 0006: Schnittstelle für die Zeiterfassung (ZEIT.md Abschnitt 9, F145)
--
-- Das Modul `zeit` liest keine Tabellen des Personals (F44). Es bekommt, was es braucht, über Funktionen:
--   personal.api_personen()                – wer ist wer: ID, Name, Nutzer, Vorgesetzter, Ein- und Austritt
--   personal.api_tage(person, von, bis)    – je Tag: Soll aus dem Vertrag, Feiertag am Arbeitsort, freier Tag des
--                                            Betriebs, genehmigte Abwesenheit (ganz oder halb, bezahlt oder nicht)
-- Wer aufrufen darf, entscheidet das aufrufende Modul mit seinen eigenen Rechten; die Funktionen geben keine privaten
-- Angaben, keine Art einer Abwesenheit und nichts aus dem Vertrag außer den Minuten des Tages heraus.
--
-- Umgekehrt holt die Lohnvorbereitung Ist- und Überstunden und die Stunden je Zeitfenster bei der Zeiterfassung ab,
-- wenn sie am Mandanten aktiv ist (personal._ist) – sonst bleibt „Nachweis fehlt“.

CREATE FUNCTION personal.api_personen()
RETURNS TABLE (id uuid, personalnummer text, name text, nutzer uuid, vorgesetzter uuid, eintritt date, austritt date)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
    SELECT p.id, p.personalnummer, p.familienname || coalesce(', ' || p.vornamen, ''), p.nutzer, p.vorgesetzter,
           (SELECT min(v.beginn) FROM personal.vertrag v WHERE v.person = p.id), p.austritt
      FROM personal.person p ORDER BY lower(p.familienname), lower(coalesce(p.vornamen, '')), p.id
$$;

CREATE FUNCTION personal.api_tage(p_person uuid, p_von date, p_bis date)
RETURNS TABLE (tag date, soll_minuten integer, feiertag text, frei text, abwesenheit numeric, bezahlt boolean)
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
    WITH ft AS MATERIALIZED (SELECT datum, name FROM personal.feiertage(personal._arbeitsort_von(p_person), p_von, p_bis)),
         pers AS (SELECT (SELECT min(v.beginn) FROM personal.vertrag v WHERE v.person = p.id) AS eintritt, p.austritt FROM personal.person p WHERE p.id = p_person)
    SELECT g.t::date,
           CASE WHEN g.t::date >= pers.eintritt AND (pers.austritt IS NULL OR g.t::date <= pers.austritt)
                THEN coalesce((SELECT v.verteilung[extract(isodow FROM g.t)::integer] FROM personal.vertrag v WHERE v.id = personal.vertrag_am(p_person, g.t::date)), 0) ELSE 0 END,
           (SELECT ft.name FROM ft WHERE ft.datum = g.t::date),
           (SELECT f.name FROM personal.freier_tag f WHERE f.datum = g.t::date),
           coalesce((SELECT max(CASE WHEN x.halber_tag THEN 0.5 ELSE 1 END) FROM personal.abwesenheit x
                      WHERE x.person = p_person AND x.zustand = 'genehmigt' AND g.t::date BETWEEN x.von AND x.bis), 0),
           coalesce((SELECT bool_or(art.bezahlt) FROM personal.abwesenheit x JOIN personal.abwesenheitsart art ON art.id = x.art
                      WHERE x.person = p_person AND x.zustand = 'genehmigt' AND g.t::date BETWEEN x.von AND x.bis), false)
      FROM pers, generate_series(p_von::timestamp, p_bis::timestamp, interval '1 day') AS g(t)
     WHERE p_bis >= p_von AND p_bis - p_von <= 400
$$;

-- Ist- und Überstunden eines Monats aus der Zeiterfassung – oder NULL, wenn sie am Mandanten nicht aktiv ist.
-- Erwartet: {"ist_minuten", "ueber_minuten", "zeitarten": {"nacht", "sonntag", "feiertag", "ueberstunden"}, "hinweis"}
CREATE FUNCTION personal._ist(p_person uuid, p_monat date) RETURNS jsonb
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE erg jsonb;
BEGIN
    IF to_regprocedure('zeit.lohn_ist(uuid,date)') IS NULL OR NOT EXISTS (SELECT 1 FROM mandant.modul WHERE name = 'zeit' AND aktiv) THEN
        RETURN NULL;
    END IF;
    EXECUTE 'SELECT zeit.lohn_ist($1, $2)' INTO erg USING p_person, p_monat;
    RETURN erg;
END $$;

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
        'person', jsonb_build_object('personalnummer', p.personalnummer, 'familienname', p.familienname, 'vornamen', p.vornamen),
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

REVOKE EXECUTE ON FUNCTION personal.api_personen(), personal.api_tage(uuid, date, date), personal._ist(uuid, date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION personal.api_personen(), personal.api_tage(uuid, date, date) TO kumero_app;
