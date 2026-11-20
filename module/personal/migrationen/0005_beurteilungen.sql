-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul personal, Migration 0005: Beurteilungen und Zielvereinbarungen (PERSONAL.md Stufe 4, Abschnitt 10e, F141)
--
-- Neue Datenklasse **Beurteilung** (eigene Rechte): Die Rolle Lohn sieht sie nicht (PE15).
--   personal.beurteilungsvorlage – Vorlagen des Mandanten: Kriterien und Skala
--   personal.beurteilung         – eine Beurteilung einer Person für einen Zeitraum; Kriterien und Skala sind mit dem
--                                  Anlegen aus der Vorlage **kopiert**, eine spätere Änderung der Vorlage ändert sie nicht
--   personal.ziel                – Zielvereinbarung: vorgeschlagen → vereinbart (die Person bestätigt) → bewertet
--
-- Ablauf einer Beurteilung: offen (Selbsteinschätzung der Person, Einschätzung des Beurteilers) → abgeschlossen
-- (Gespräch mit Datum, Ergebnis) → zur Kenntnis genommen (mit Stellungnahme der Person, wenn sie will).
-- Kenntnisnahme ist keine Zustimmung. Im Protokoll stehen IDs, nie ein Wert und kein Text.

CREATE TABLE personal.beurteilungsvorlage (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    name          text NOT NULL UNIQUE CHECK (length(trim(name)) BETWEEN 1 AND 60),
    skala_von     integer NOT NULL DEFAULT 1 CHECK (skala_von BETWEEN 0 AND 1),
    skala_bis     integer NOT NULL DEFAULT 5 CHECK (skala_bis BETWEEN 2 AND 10),
    skala_niedrig text CHECK (skala_niedrig IS NULL OR length(skala_niedrig) <= 60),   -- was das untere Ende bedeutet
    skala_hoch    text CHECK (skala_hoch IS NULL OR length(skala_hoch) <= 60),
    kriterien     jsonb NOT NULL CHECK (jsonb_typeof(kriterien) = 'array' AND jsonb_array_length(kriterien) BETWEEN 1 AND 30),   -- [{kennung, name, beschreibung}]
    stillgelegt   boolean NOT NULL DEFAULT false,
    geaendert_am  timestamptz NOT NULL DEFAULT now(),
    geaendert_von text NOT NULL
);

CREATE TABLE personal.beurteilung (
    id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    person            uuid NOT NULL REFERENCES personal.person,
    vorlage           uuid NOT NULL REFERENCES personal.beurteilungsvorlage,
    vorlage_name      text NOT NULL,
    kriterien         jsonb NOT NULL,
    skala_von         integer NOT NULL,
    skala_bis         integer NOT NULL,
    skala_niedrig     text,
    skala_hoch        text,
    zeitraum_von      date NOT NULL,
    zeitraum_bis      date NOT NULL,
    beurteiler        uuid REFERENCES personal.person,      -- wer einschätzt; leer: das Personal
    zustand           text NOT NULL DEFAULT 'offen' CHECK (zustand IN ('offen', 'abgeschlossen', 'zur_kenntnis', 'aufgehoben')),
    selbst            jsonb,                                -- {"werte": {kennung: Zahl}, "texte": {kennung: Text}, "gesamt": Text}
    selbst_am         timestamptz,
    fremd             jsonb,
    fremd_am          timestamptz,
    fremd_von         text,
    gespraech_am      date,
    ergebnis          text CHECK (ergebnis IS NULL OR length(ergebnis) <= 2000),
    abgeschlossen_am  timestamptz,
    abgeschlossen_von text,
    kenntnis_am       timestamptz,
    stellungnahme     text CHECK (stellungnahme IS NULL OR length(stellungnahme) <= 2000),
    grund             text CHECK (grund IS NULL OR length(grund) <= 200),   -- warum aufgehoben
    angelegt_am       timestamptz NOT NULL DEFAULT clock_timestamp(),
    angelegt_von      text NOT NULL,
    loeschen_ab       date,
    CHECK (zeitraum_bis >= zeitraum_von),
    CHECK (beurteiler IS DISTINCT FROM person)
);
CREATE INDEX beurteilung_person ON personal.beurteilung (person, zeitraum_bis DESC);
CREATE INDEX beurteilung_beurteiler ON personal.beurteilung (beurteiler) WHERE zustand = 'offen';

CREATE TABLE personal.ziel (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    person           uuid NOT NULL REFERENCES personal.person,
    titel            text NOT NULL CHECK (length(trim(titel)) BETWEEN 1 AND 120),
    beschreibung     text CHECK (beschreibung IS NULL OR length(beschreibung) <= 1000),
    frist            date NOT NULL,
    zustand          text NOT NULL DEFAULT 'vorgeschlagen' CHECK (zustand IN ('vorgeschlagen', 'vereinbart', 'bewertet', 'aufgehoben')),
    stand            integer NOT NULL DEFAULT 0 CHECK (stand BETWEEN 0 AND 100),   -- Prozent
    stand_text       text CHECK (stand_text IS NULL OR length(stand_text) <= 500),
    stand_am         timestamptz,
    bewertung        text CHECK (bewertung IN ('erreicht', 'teilweise', 'nicht')),
    bewertung_text   text CHECK (bewertung_text IS NULL OR length(bewertung_text) <= 1000),
    grund            text CHECK (grund IS NULL OR length(grund) <= 200),           -- warum abgelehnt oder aufgehoben
    angelegt_am      timestamptz NOT NULL DEFAULT clock_timestamp(),
    angelegt_von     text NOT NULL,
    vereinbart_am    timestamptz,
    bewertet_am      timestamptz,
    bewertet_von     text,
    loeschen_ab      date
);
CREATE INDEX ziel_person ON personal.ziel (person, frist);

-- ---------------------------------------------------------------------------
-- Vorlagen
-- ---------------------------------------------------------------------------
-- p: name, skala_von, skala_bis, skala_niedrig, skala_hoch, kriterien [{kennung?, name, beschreibung?}], stillgelegt
CREATE FUNCTION personal.beurteilungsvorlage_setzen(p_subjekt text, p_id uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE neu uuid; k jsonb := '[]'; e jsonb; nr integer := 0; kennung text; von integer := coalesce((p->>'skala_von')::integer, 1); bis integer := coalesce((p->>'skala_bis')::integer, 5);
BEGIN
    IF personal._t(p, 'name') IS NULL THEN PERFORM personal._fehler('Die Vorlage braucht einen Namen'); END IF;
    IF EXISTS (SELECT 1 FROM personal.beurteilungsvorlage WHERE lower(name) = lower(personal._t(p, 'name')) AND id IS DISTINCT FROM p_id) THEN
        PERFORM personal._fehler('Eine Vorlage mit diesem Namen gibt es schon', 'KM003');
    END IF;
    IF jsonb_typeof(p->'kriterien') IS DISTINCT FROM 'array' OR jsonb_array_length(p->'kriterien') NOT BETWEEN 1 AND 30 THEN
        PERFORM personal._fehler('Eine Vorlage hat ein bis dreißig Kriterien');
    END IF;
    IF bis <= von THEN PERFORM personal._fehler('Die Skala braucht mindestens zwei Stufen'); END IF;
    FOR e IN SELECT * FROM jsonb_array_elements(p->'kriterien') LOOP
        nr := nr + 1;
        IF jsonb_typeof(e) <> 'object' OR personal._t(e, 'name') IS NULL OR length(personal._t(e, 'name')) > 100 OR length(coalesce(e->>'beschreibung', '')) > 300 THEN
            PERFORM personal._fehler(format('Kriterium %s: Name fehlt oder ist zu lang (Name 100, Beschreibung 300 Zeichen)', nr));
        END IF;
        kennung := coalesce(nullif(e->>'kennung', ''), 'k' || substr(md5(gen_random_uuid()::text), 1, 8));
        IF kennung !~ '^[a-z0-9]{1,12}$' OR EXISTS (SELECT 1 FROM jsonb_array_elements(k) x WHERE x->>'kennung' = kennung) THEN
            PERFORM personal._fehler(format('Kriterium %s: Kennung ungültig oder doppelt', nr));
        END IF;
        k := k || jsonb_build_object('kennung', kennung, 'name', personal._t(e, 'name'), 'beschreibung', personal._t(e, 'beschreibung'));
    END LOOP;
    IF p_id IS NULL THEN
        INSERT INTO personal.beurteilungsvorlage (name, skala_von, skala_bis, skala_niedrig, skala_hoch, kriterien, geaendert_von)
        VALUES (personal._t(p, 'name'), von, bis, personal._t(p, 'skala_niedrig'), personal._t(p, 'skala_hoch'), k, p_subjekt) RETURNING id INTO neu;
    ELSE
        UPDATE personal.beurteilungsvorlage SET name = personal._t(p, 'name'), skala_von = von, skala_bis = bis, skala_niedrig = personal._t(p, 'skala_niedrig'),
               skala_hoch = personal._t(p, 'skala_hoch'), kriterien = k, stillgelegt = coalesce((p->>'stillgelegt')::boolean, stillgelegt),
               geaendert_am = clock_timestamp(), geaendert_von = p_subjekt WHERE id = p_id RETURNING id INTO neu;
        IF neu IS NULL THEN PERFORM personal._fehler('Vorlage unbekannt', 'KM003'); END IF;
    END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.beurteilungsvorlage_gesetzt', jsonb_build_object('vorlage', neu::text));
    RETURN neu;
EXCEPTION WHEN check_violation OR invalid_text_representation THEN
    PERFORM personal._fehler('Vorlage: ein Wert ist zu lang oder die Skala liegt außerhalb (von 0 oder 1, bis höchstens 10)');
    RETURN NULL;
END $$;

-- ---------------------------------------------------------------------------
-- Beurteilung
-- ---------------------------------------------------------------------------
CREATE FUNCTION personal.beurteilung_anlegen(p_subjekt text, p_person uuid, p_vorlage uuid, p_von date, p_bis date, p_beurteiler uuid)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE v personal.beurteilungsvorlage; pers personal.person; neu uuid;
BEGIN
    SELECT * INTO pers FROM personal.person WHERE id = p_person;
    IF NOT FOUND THEN PERFORM personal._fehler('Person unbekannt', 'KM003'); END IF;
    IF pers.austritt IS NOT NULL AND pers.austritt < current_date THEN PERFORM personal._fehler('Die Person ist ausgeschieden'); END IF;
    SELECT * INTO v FROM personal.beurteilungsvorlage WHERE id = p_vorlage AND NOT stillgelegt;
    IF NOT FOUND THEN PERFORM personal._fehler('Vorlage unbekannt oder stillgelegt'); END IF;
    IF p_von IS NULL OR p_bis IS NULL OR p_bis < p_von THEN PERFORM personal._fehler('Der Zeitraum fehlt oder endet vor seinem Beginn'); END IF;
    IF p_beurteiler = p_person THEN PERFORM personal._fehler('Niemand beurteilt sich selbst – dafür gibt es die Selbsteinschätzung'); END IF;
    IF p_beurteiler IS NOT NULL AND NOT EXISTS (SELECT 1 FROM personal.person WHERE id = p_beurteiler) THEN PERFORM personal._fehler('Beurteiler unbekannt'); END IF;
    IF EXISTS (SELECT 1 FROM personal.beurteilung WHERE person = p_person AND zustand = 'offen' AND zeitraum_von <= p_bis AND zeitraum_bis >= p_von) THEN
        PERFORM personal._fehler('Für diesen Zeitraum läuft schon eine Beurteilung der Person', 'KM003');
    END IF;
    INSERT INTO personal.beurteilung (person, vorlage, vorlage_name, kriterien, skala_von, skala_bis, skala_niedrig, skala_hoch, zeitraum_von, zeitraum_bis, beurteiler, angelegt_von)
    VALUES (p_person, v.id, v.name, v.kriterien, v.skala_von, v.skala_bis, v.skala_niedrig, v.skala_hoch, p_von, p_bis, p_beurteiler, p_subjekt) RETURNING id INTO neu;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.beurteilung_angelegt', jsonb_build_object('person', p_person::text, 'beurteilung', neu::text));
    RETURN neu;
END $$;

-- Eine Einschätzung prüfen und in fester Form zurückgeben: zu jedem Kriterium ein Wert der Skala.
CREATE FUNCTION personal._einschaetzung(b personal.beurteilung, p jsonb) RETURNS jsonb LANGUAGE plpgsql AS $$
DECLARE k jsonb; werte jsonb := '{}'; texte jsonb := '{}'; w text; t text;
BEGIN
    IF jsonb_typeof(p->'werte') IS DISTINCT FROM 'object' THEN PERFORM personal._fehler('Die Werte fehlen'); END IF;
    IF EXISTS (SELECT 1 FROM jsonb_object_keys(p->'werte') s WHERE NOT EXISTS (SELECT 1 FROM jsonb_array_elements(b.kriterien) x WHERE x->>'kennung' = s))
       OR (jsonb_typeof(p->'texte') = 'object' AND EXISTS (SELECT 1 FROM jsonb_object_keys(p->'texte') s WHERE NOT EXISTS (SELECT 1 FROM jsonb_array_elements(b.kriterien) x WHERE x->>'kennung' = s))) THEN
        PERFORM personal._fehler('Ein Kriterium gehört nicht zu dieser Beurteilung');
    END IF;
    FOR k IN SELECT * FROM jsonb_array_elements(b.kriterien) LOOP
        w := p->'werte'->>(k->>'kennung');
        IF w IS NULL OR w !~ '^[0-9]{1,2}$' OR w::integer NOT BETWEEN b.skala_von AND b.skala_bis THEN
            PERFORM personal._fehler(format('„%s“: ein Wert von %s bis %s gehört dazu', k->>'name', b.skala_von, b.skala_bis));
        END IF;
        werte := werte || jsonb_build_object(k->>'kennung', w::integer);
        t := nullif(trim(p->'texte'->>(k->>'kennung')), '');
        IF length(t) > 500 THEN PERFORM personal._fehler(format('„%s“: Anmerkung höchstens 500 Zeichen', k->>'name')); END IF;
        IF t IS NOT NULL THEN texte := texte || jsonb_build_object(k->>'kennung', t); END IF;
    END LOOP;
    IF length(coalesce(p->>'gesamt', '')) > 2000 THEN PERFORM personal._fehler('Gesamteinschätzung: höchstens 2000 Zeichen'); END IF;
    RETURN jsonb_build_object('werte', werte, 'texte', texte, 'gesamt', personal._t(p, 'gesamt'));
END $$;

-- p_selbst: die Selbsteinschätzung der Person; sonst die Einschätzung des Beurteilers. Solange die Beurteilung offen
-- ist, lässt sich die eigene Einschätzung neu abgeben.
CREATE FUNCTION personal.beurteilung_einschaetzen(p_subjekt text, p_id uuid, p_selbst boolean, p jsonb)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE b personal.beurteilung; e jsonb;
BEGIN
    SELECT * INTO b FROM personal.beurteilung WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Beurteilung unbekannt', 'KM003'); END IF;
    IF b.zustand <> 'offen' THEN PERFORM personal._fehler('Die Beurteilung ist abgeschlossen oder aufgehoben', 'KM003'); END IF;
    e := personal._einschaetzung(b, p);
    IF coalesce(p_selbst, false) THEN
        UPDATE personal.beurteilung SET selbst = e, selbst_am = clock_timestamp() WHERE id = p_id;
    ELSE
        UPDATE personal.beurteilung SET fremd = e, fremd_am = clock_timestamp(), fremd_von = p_subjekt WHERE id = p_id;
    END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', CASE WHEN coalesce(p_selbst, false) THEN 'personal.beurteilung_selbst_eingeschaetzt' ELSE 'personal.beurteilung_eingeschaetzt' END,
        jsonb_build_object('person', b.person::text, 'beurteilung', p_id::text));
END $$;

CREATE FUNCTION personal.beurteilung_abschliessen(p_subjekt text, p_id uuid, p_gespraech_am date, p_ergebnis text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE b personal.beurteilung;
BEGIN
    SELECT * INTO b FROM personal.beurteilung WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Beurteilung unbekannt', 'KM003'); END IF;
    IF b.zustand <> 'offen' THEN PERFORM personal._fehler('Die Beurteilung ist schon abgeschlossen oder aufgehoben', 'KM003'); END IF;
    IF b.fremd_am IS NULL THEN PERFORM personal._fehler('Erst die Einschätzung abgeben, dann abschließen'); END IF;
    IF p_gespraech_am IS NULL THEN PERFORM personal._fehler('Zum Abschluss gehört das Gespräch mit seinem Datum'); END IF;
    IF p_gespraech_am > current_date THEN PERFORM personal._fehler('Das Gespräch liegt in der Zukunft'); END IF;
    IF nullif(trim(p_ergebnis), '') IS NULL OR length(p_ergebnis) > 2000 THEN PERFORM personal._fehler('Das Ergebnis fehlt (höchstens 2000 Zeichen)'); END IF;
    UPDATE personal.beurteilung SET zustand = 'abgeschlossen', gespraech_am = p_gespraech_am, ergebnis = trim(p_ergebnis),
           abgeschlossen_am = clock_timestamp(), abgeschlossen_von = p_subjekt WHERE id = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.beurteilung_abgeschlossen', jsonb_build_object('person', b.person::text, 'beurteilung', p_id::text));
END $$;

-- Die Person nimmt das Ergebnis zur Kenntnis – das ist keine Zustimmung; ihre Stellungnahme steht daneben.
CREATE FUNCTION personal.beurteilung_kenntnis(p_subjekt text, p_id uuid, p_stellungnahme text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE b personal.beurteilung;
BEGIN
    SELECT * INTO b FROM personal.beurteilung WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Beurteilung unbekannt', 'KM003'); END IF;
    IF b.zustand <> 'abgeschlossen' THEN PERFORM personal._fehler('Zur Kenntnis nehmen lässt sich nur ein abgeschlossenes Ergebnis – und nur einmal', 'KM003'); END IF;
    IF length(coalesce(p_stellungnahme, '')) > 2000 THEN PERFORM personal._fehler('Stellungnahme: höchstens 2000 Zeichen'); END IF;
    UPDATE personal.beurteilung SET zustand = 'zur_kenntnis', kenntnis_am = clock_timestamp(), stellungnahme = nullif(trim(p_stellungnahme), '') WHERE id = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.beurteilung_zur_kenntnis', jsonb_build_object('person', b.person::text, 'beurteilung', p_id::text));
END $$;

CREATE FUNCTION personal.beurteilung_aufheben(p_subjekt text, p_id uuid, p_grund text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE b personal.beurteilung;
BEGIN
    SELECT * INTO b FROM personal.beurteilung WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Beurteilung unbekannt', 'KM003'); END IF;
    IF b.zustand <> 'offen' THEN PERFORM personal._fehler('Aufheben lässt sich nur eine offene Beurteilung', 'KM003'); END IF;
    IF nullif(trim(p_grund), '') IS NULL OR length(p_grund) > 200 THEN PERFORM personal._fehler('Das Aufheben braucht einen Grund (höchstens 200 Zeichen)'); END IF;
    UPDATE personal.beurteilung SET zustand = 'aufgehoben', grund = trim(p_grund) WHERE id = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.beurteilung_aufgehoben', jsonb_build_object('person', b.person::text, 'beurteilung', p_id::text));
END $$;

CREATE FUNCTION personal.beurteilung_beurteiler_setzen(p_subjekt text, p_id uuid, p_beurteiler uuid)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE b personal.beurteilung;
BEGIN
    SELECT * INTO b FROM personal.beurteilung WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Beurteilung unbekannt', 'KM003'); END IF;
    IF b.zustand <> 'offen' THEN PERFORM personal._fehler('Die Beurteilung ist abgeschlossen oder aufgehoben', 'KM003'); END IF;
    IF p_beurteiler = b.person THEN PERFORM personal._fehler('Niemand beurteilt sich selbst'); END IF;
    IF p_beurteiler IS NOT NULL AND NOT EXISTS (SELECT 1 FROM personal.person WHERE id = p_beurteiler) THEN PERFORM personal._fehler('Beurteiler unbekannt'); END IF;
    UPDATE personal.beurteilung SET beurteiler = p_beurteiler WHERE id = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.beurteilung_beurteiler_gesetzt', jsonb_build_object('person', b.person::text, 'beurteilung', p_id::text));
END $$;

-- ---------------------------------------------------------------------------
-- Ziele
-- ---------------------------------------------------------------------------
CREATE FUNCTION personal.ziel_anlegen(p_subjekt text, p_person uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE pers personal.person; neu uuid;
BEGIN
    SELECT * INTO pers FROM personal.person WHERE id = p_person;
    IF NOT FOUND THEN PERFORM personal._fehler('Person unbekannt', 'KM003'); END IF;
    IF pers.austritt IS NOT NULL AND pers.austritt < current_date THEN PERFORM personal._fehler('Die Person ist ausgeschieden'); END IF;
    IF personal._t(p, 'titel') IS NULL OR personal._datum(p, 'frist') IS NULL THEN PERFORM personal._fehler('Titel und Frist gehören dazu'); END IF;
    IF length(personal._t(p, 'titel')) > 120 OR length(coalesce(p->>'beschreibung', '')) > 1000 THEN PERFORM personal._fehler('Titel höchstens 120, Beschreibung höchstens 1000 Zeichen'); END IF;
    INSERT INTO personal.ziel (person, titel, beschreibung, frist, angelegt_von)
    VALUES (p_person, personal._t(p, 'titel'), personal._t(p, 'beschreibung'), personal._datum(p, 'frist'), p_subjekt) RETURNING id INTO neu;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.ziel_vorgeschlagen', jsonb_build_object('person', p_person::text, 'ziel', neu::text));
    RETURN neu;
END $$;

-- Die Person bestätigt das vorgeschlagene Ziel – erst dann ist es vereinbart – oder lehnt es mit Grund ab.
CREATE FUNCTION personal.ziel_bestaetigen(p_subjekt text, p_id uuid, p_ja boolean, p_grund text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE z personal.ziel;
BEGIN
    SELECT * INTO z FROM personal.ziel WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Ziel unbekannt', 'KM003'); END IF;
    IF z.zustand <> 'vorgeschlagen' THEN PERFORM personal._fehler('Über das Ziel ist schon entschieden', 'KM003'); END IF;
    IF NOT coalesce(p_ja, false) AND (nullif(trim(p_grund), '') IS NULL OR length(p_grund) > 200) THEN PERFORM personal._fehler('Eine Ablehnung braucht einen Grund (höchstens 200 Zeichen)'); END IF;
    IF coalesce(p_ja, false) THEN
        UPDATE personal.ziel SET zustand = 'vereinbart', vereinbart_am = clock_timestamp() WHERE id = p_id;
    ELSE
        UPDATE personal.ziel SET zustand = 'aufgehoben', grund = trim(p_grund) WHERE id = p_id;
    END IF;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', CASE WHEN coalesce(p_ja, false) THEN 'personal.ziel_vereinbart' ELSE 'personal.ziel_abgelehnt' END,
        jsonb_build_object('person', z.person::text, 'ziel', p_id::text));
END $$;

CREATE FUNCTION personal.ziel_stand_setzen(p_subjekt text, p_id uuid, p_stand integer, p_text text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE z personal.ziel;
BEGIN
    SELECT * INTO z FROM personal.ziel WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Ziel unbekannt', 'KM003'); END IF;
    IF z.zustand <> 'vereinbart' THEN PERFORM personal._fehler('Einen Stand hat nur ein vereinbartes Ziel', 'KM003'); END IF;
    IF p_stand IS NULL OR p_stand NOT BETWEEN 0 AND 100 THEN PERFORM personal._fehler('Stand in Prozent, 0 bis 100'); END IF;
    IF length(coalesce(p_text, '')) > 500 THEN PERFORM personal._fehler('Anmerkung zum Stand: höchstens 500 Zeichen'); END IF;
    UPDATE personal.ziel SET stand = p_stand, stand_text = nullif(trim(p_text), ''), stand_am = clock_timestamp() WHERE id = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.ziel_stand_gesetzt', jsonb_build_object('person', z.person::text, 'ziel', p_id::text));
END $$;

CREATE FUNCTION personal.ziel_bewerten(p_subjekt text, p_id uuid, p_bewertung text, p_text text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE z personal.ziel;
BEGIN
    SELECT * INTO z FROM personal.ziel WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Ziel unbekannt', 'KM003'); END IF;
    IF z.zustand <> 'vereinbart' THEN PERFORM personal._fehler('Bewertet wird nur ein vereinbartes Ziel – und nur einmal', 'KM003'); END IF;
    IF coalesce(p_bewertung, '') NOT IN ('erreicht', 'teilweise', 'nicht') THEN PERFORM personal._fehler('Bewertung: erreicht, teilweise erreicht oder nicht erreicht'); END IF;
    IF length(coalesce(p_text, '')) > 1000 THEN PERFORM personal._fehler('Anmerkung zur Bewertung: höchstens 1000 Zeichen'); END IF;
    UPDATE personal.ziel SET zustand = 'bewertet', bewertung = p_bewertung, bewertung_text = nullif(trim(p_text), ''), bewertet_am = clock_timestamp(), bewertet_von = p_subjekt WHERE id = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.ziel_bewertet', jsonb_build_object('person', z.person::text, 'ziel', p_id::text));
END $$;

CREATE FUNCTION personal.ziel_aufheben(p_subjekt text, p_id uuid, p_grund text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE z personal.ziel;
BEGIN
    SELECT * INTO z FROM personal.ziel WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN PERFORM personal._fehler('Ziel unbekannt', 'KM003'); END IF;
    IF z.zustand NOT IN ('vorgeschlagen', 'vereinbart') THEN PERFORM personal._fehler('Das Ziel ist schon bewertet oder aufgehoben', 'KM003'); END IF;
    IF nullif(trim(p_grund), '') IS NULL OR length(p_grund) > 200 THEN PERFORM personal._fehler('Das Aufheben braucht einen Grund (höchstens 200 Zeichen)'); END IF;
    UPDATE personal.ziel SET zustand = 'aufgehoben', grund = trim(p_grund) WHERE id = p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.ziel_aufgehoben', jsonb_build_object('person', z.person::text, 'ziel', p_id::text));
END $$;

-- ---------------------------------------------------------------------------
-- Löschfrist: Beurteilung und Ziel sind Objekte mit eigener Frist (PE12)
-- ---------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION personal.loeschfrist_setzen(p_subjekt text, p_objektart text, p_id uuid, p_loeschen_ab date, p_grund text)
RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE alt date; v_person uuid; gefunden boolean := false; tabelle text; spalte text := 'person'; schluessel text := 'id';
BEGIN
    IF nullif(trim(p_grund), '') IS NULL OR length(p_grund) > 200 THEN
        PERFORM personal._fehler('Eine Löschfrist braucht eine Begründung (höchstens 200 Zeichen)');
    END IF;
    tabelle := CASE p_objektart WHEN 'person' THEN 'person' WHEN 'privat' THEN 'privat' WHEN 'vertrag' THEN 'vertrag' WHEN 'frist' THEN 'frist'
                                WHEN 'vorschlag' THEN 'vorschlag' WHEN 'abwesenheit' THEN 'abwesenheit' WHEN 'lohnvorbereitung' THEN 'lohnvorbereitung'
                                WHEN 'zuschlag' THEN 'zuschlag_hand' WHEN 'beurteilung' THEN 'beurteilung' WHEN 'ziel' THEN 'ziel' END;
    IF tabelle IS NULL THEN PERFORM personal._fehler('Art des Objekts unbekannt'); END IF;
    IF p_objektart = 'person' THEN spalte := 'id'; ELSIF p_objektart = 'privat' THEN schluessel := 'person'; END IF;
    -- der Name der Tabelle kommt aus der festen Liste oben, nie aus der Eingabe
    EXECUTE format('SELECT loeschen_ab, %I, true FROM personal.%I WHERE %I = $1 FOR UPDATE', spalte, tabelle, schluessel)
       INTO alt, v_person, gefunden USING p_id;
    IF NOT coalesce(gefunden, false) THEN PERFORM personal._fehler('Objekt unbekannt', 'KM003'); END IF;
    EXECUTE format('UPDATE personal.%I SET loeschen_ab = $1 WHERE %I = $2', tabelle, schluessel) USING p_loeschen_ab, p_id;
    PERFORM mandant.modul_protokoll(p_subjekt, 'personal', 'personal.loeschfrist_gesetzt',
        jsonb_build_object('person', v_person::text, 'objektart', p_objektart, 'objekt', p_id::text),
        jsonb_strip_nulls(jsonb_build_object('loeschen_ab', alt::text)),
        jsonb_strip_nulls(jsonb_build_object('loeschen_ab', p_loeschen_ab::text, 'grund', trim(p_grund))));
END $$;

-- ---------------------------------------------------------------------------
-- Zugriff: neue Tabellen lesen, neue Funktionen ausführen
-- ---------------------------------------------------------------------------
REVOKE ALL ON personal.beurteilungsvorlage, personal.beurteilung, personal.ziel FROM kumero_app, kumero_lesen, PUBLIC;
GRANT SELECT ON personal.beurteilungsvorlage, personal.beurteilung, personal.ziel TO kumero_app;
REVOKE EXECUTE ON ALL FUNCTIONS IN SCHEMA personal FROM PUBLIC;
GRANT EXECUTE ON FUNCTION personal.beurteilungsvorlage_setzen(text, uuid, jsonb), personal.beurteilung_anlegen(text, uuid, uuid, date, date, uuid),
    personal.beurteilung_einschaetzen(text, uuid, boolean, jsonb), personal.beurteilung_abschliessen(text, uuid, date, text),
    personal.beurteilung_kenntnis(text, uuid, text), personal.beurteilung_aufheben(text, uuid, text), personal.beurteilung_beurteiler_setzen(text, uuid, uuid),
    personal.ziel_anlegen(text, uuid, jsonb), personal.ziel_bestaetigen(text, uuid, boolean, text), personal.ziel_stand_setzen(text, uuid, integer, text),
    personal.ziel_bewerten(text, uuid, text, text), personal.ziel_aufheben(text, uuid, text) TO kumero_app;
