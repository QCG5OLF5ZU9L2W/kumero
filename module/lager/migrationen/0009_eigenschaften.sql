-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul lager, Migration 0009: Eigenschaften des Artikels statt einer „Führung“ (LAGER.md Abschnitt 19, F211)
--
-- Bisher trug der Artikel eine Angabe „Führung“ mit drei Werten, die sich ausschließen. Jetzt stehen die Eigenschaften
-- einzeln da und lassen sich verbinden: hat Charge, hat Seriennummer, wird in Stücken gezählt (EG1).
-- Charge und Seriennummer zugleich sind erlaubt (EG2): Jedes Stück hat seine Seriennummer und gehört zu einer Charge.
-- Die Zeile des Bestands trägt dafür zwei Felder (EG7): „bezeichnung“ bleibt der Schlüssel der Zeile – die Seriennummer,
-- wenn der Artikel eine hat, sonst die Charge –, „los“ ist die Charge eines Stücks mit Seriennummer.
-- „fuehrung“ bleibt als abgeleiteter Wert stehen (EG9): serie, wenn der Artikel Seriennummern hat, sonst charge, sonst ohne.
-- Kein Bestand ändert sich.

ALTER TABLE lager.artikel
    ADD COLUMN hat_charge  boolean NOT NULL DEFAULT false,
    ADD COLUMN hat_serie   boolean NOT NULL DEFAULT false,
    ADD COLUMN in_stuecken boolean NOT NULL DEFAULT false;
UPDATE lager.artikel SET hat_charge = (fuehrung = 'charge'), hat_serie = (fuehrung = 'serie'), in_stuecken = (fuehrung = 'serie');
ALTER TABLE lager.artikel ADD CONSTRAINT artikel_serie_stueck CHECK (NOT hat_serie OR in_stuecken);

CREATE FUNCTION lager.tg_artikel_fuehrung() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    NEW.fuehrung := CASE WHEN NEW.hat_serie THEN 'serie' WHEN NEW.hat_charge THEN 'charge' ELSE 'ohne' END;
    RETURN NEW;
END $$;
CREATE TRIGGER artikel_fuehrung BEFORE INSERT OR UPDATE ON lager.artikel FOR EACH ROW EXECUTE FUNCTION lager.tg_artikel_fuehrung();

ALTER TABLE lager.charge
    ADD COLUMN los        text CHECK (los IS NULL OR length(trim(los)) BETWEEN 1 AND 50),   -- Charge eines Stücks mit Seriennummer
    ADD COLUMN ohne_serie boolean NOT NULL DEFAULT false;                                    -- Bestand von vor der Eigenschaft „hat Seriennummer“
CREATE INDEX charge_los_idx ON lager.charge (artikel, los) WHERE los IS NOT NULL;

-- Artikel anlegen oder ändern. Die Eigenschaften kommen einzeln (hat_charge, hat_serie, in_stuecken); wer noch
-- „fuehrung“ schickt, wird verstanden. Statt „die Führung steht fest“ gilt (EG5): Dazunehmen geht jederzeit – der
-- vorhandene Bestand trägt dann „ohne Angabe“. Wegnehmen geht nur, solange kein Bestand die Eigenschaft trägt.
CREATE OR REPLACE FUNCTION lager.artikel_setzen(p_subjekt text, p_id uuid, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE alt lager.artikel; neu lager.artikel;
        einzeln boolean := p ? 'hat_charge' OR p ? 'hat_serie';
        v_charge boolean := CASE WHEN einzeln THEN coalesce((p->>'hat_charge')::boolean, false) ELSE p->>'fuehrung' = 'charge' END;
        v_serie boolean := CASE WHEN einzeln THEN coalesce((p->>'hat_serie')::boolean, false) ELSE p->>'fuehrung' = 'serie' END;
        v_stueck boolean;
BEGIN
    v_stueck := coalesce((p->>'in_stuecken')::boolean, v_serie);
    IF v_serie AND NOT v_stueck THEN
        PERFORM lager._fehler('Ein Artikel mit Seriennummer wird in Stücken gezählt');
    END IF;
    IF (p->>'soll')::numeric < (p->>'mindest')::numeric THEN
        PERFORM lager._fehler('Der Sollbestand liegt unter dem Mindestbestand');
    END IF;
    IF p_id IS NULL THEN
        INSERT INTO lager.artikel (nummer, bezeichnung, art, einheit, fuehrung, hat_charge, hat_serie, in_stuecken, verfall_pflicht, btm, gtin, mindest, soll, standardort, lagerbedingung, geaendert_von)
        VALUES (nullif(trim(p->>'nummer'), ''), trim(p->>'bezeichnung'), p->>'art', trim(p->>'einheit'), 'ohne', v_charge, v_serie, v_stueck, coalesce((p->>'verfall_pflicht')::boolean, false),
                coalesce((p->>'btm')::boolean, false), nullif(p->>'gtin', ''), (p->>'mindest')::numeric, (p->>'soll')::numeric, (p->>'standardort')::uuid,
                nullif(trim(p->>'lagerbedingung'), ''), p_subjekt)
        RETURNING * INTO neu;
        PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.artikel_angelegt', jsonb_build_object('artikel', neu.id::text), NULL, lager._artikel_stand(neu));
        RETURN neu.id;
    END IF;
    SELECT * INTO alt FROM lager.artikel WHERE id = p_id FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM lager._fehler('Artikel unbekannt', 'KM003');
    END IF;
    -- wegnehmen nur, solange kein Bestand die Eigenschaft trägt
    IF alt.hat_charge AND NOT v_charge AND EXISTS (SELECT 1 FROM lager.bestand s JOIN lager.charge c ON c.id = s.charge
            WHERE c.artikel = p_id AND s.menge > 0 AND CASE WHEN alt.hat_serie THEN c.los IS NOT NULL ELSE c.bezeichnung <> '' END) THEN
        PERFORM lager._fehler('Es gibt noch Bestand mit Charge – „hat Charge“ lässt sich erst wegnehmen, wenn er verbraucht oder ausgebucht ist', 'KM003');
    END IF;
    IF alt.hat_serie AND NOT v_serie AND EXISTS (SELECT 1 FROM lager.bestand s JOIN lager.charge c ON c.id = s.charge
            WHERE c.artikel = p_id AND s.menge > 0 AND c.bezeichnung <> '' AND NOT c.ohne_serie) THEN
        PERFORM lager._fehler('Es gibt noch Stücke mit Seriennummer im Bestand – „hat Seriennummer“ lässt sich erst wegnehmen, wenn sie verbraucht oder ausgebucht sind', 'KM003');
    END IF;
    IF v_stueck AND NOT alt.in_stuecken AND EXISTS (SELECT 1 FROM lager.bestand s JOIN lager.charge c ON c.id = s.charge WHERE c.artikel = p_id AND s.menge <> round(s.menge)) THEN
        PERFORM lager._fehler('Der Bestand enthält Bruchteile – „in Stücken gezählt“ geht erst, wenn er ganzzahlig ist', 'KM003');
    END IF;
    IF coalesce((p->>'stillgelegt')::boolean, false) AND EXISTS (SELECT 1 FROM lager.bestand s JOIN lager.charge c ON c.id = s.charge WHERE c.artikel = p_id AND s.menge > 0) THEN
        PERFORM lager._fehler('Der Artikel hat noch Bestand und kann nicht stillgelegt werden', 'KM003');
    END IF;
    -- dazunehmen: Was schon da ist, trägt die neue Angabe nicht – es steht als „ohne Angabe“ da
    IF v_serie AND NOT alt.hat_serie THEN
        UPDATE lager.charge SET ohne_serie = true, los = CASE WHEN alt.hat_charge AND bezeichnung <> '' THEN bezeichnung ELSE los END WHERE artikel = p_id;
    END IF;
    UPDATE lager.artikel SET nummer = nullif(trim(p->>'nummer'), ''), bezeichnung = trim(p->>'bezeichnung'), art = p->>'art', einheit = trim(p->>'einheit'),
           hat_charge = v_charge, hat_serie = v_serie, in_stuecken = v_stueck, verfall_pflicht = coalesce((p->>'verfall_pflicht')::boolean, false), btm = coalesce((p->>'btm')::boolean, false),
           gtin = nullif(p->>'gtin', ''), mindest = (p->>'mindest')::numeric, soll = (p->>'soll')::numeric, standardort = (p->>'standardort')::uuid,
           lagerbedingung = nullif(trim(p->>'lagerbedingung'), ''), stillgelegt = coalesce((p->>'stillgelegt')::boolean, false),
           geaendert_am = clock_timestamp(), geaendert_von = p_subjekt
     WHERE id = p_id RETURNING * INTO neu;
    PERFORM kern.modul_protokoll(p_subjekt, 'lager', 'lager.artikel_geaendert', jsonb_build_object('artikel', p_id::text), lager._artikel_stand(alt), lager._artikel_stand(neu));
    RETURN p_id;
END $$;

-- Zugang: wie bisher, dazu p->>'los' – die Charge eines Stücks, wenn der Artikel Charge und Seriennummer hat (EG2).
-- Der Verfall gehört zur Charge und ist für alle ihre Stücke derselbe. „In Stücken gezählt“ verlangt eine ganze Zahl.
CREATE OR REPLACE FUNCTION lager.zugang(p_subjekt text, p jsonb)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, lager, pg_temp AS $$
DECLARE a lager.artikel; c lager.charge; o lager.ort; bez text := coalesce(trim(p->>'charge'), ''); v date := (p->>'verfall')::date;
        m numeric := (p->>'menge')::numeric; bid uuid; v_los text := nullif(trim(coalesce(p->>'los', '')), ''); v_alt date; beide boolean;
BEGIN
    SELECT * INTO a FROM lager.artikel WHERE id = (p->>'artikel')::uuid FOR UPDATE;
    IF NOT FOUND THEN
        PERFORM lager._fehler('Artikel unbekannt', 'KM003');
    END IF;
    IF a.stillgelegt THEN
        PERFORM lager._fehler('Der Artikel ist stillgelegt', 'KM003');
    END IF;
    IF a.btm THEN
        PERFORM lager._fehler('Betäubungsmittel werden nicht im Lager geführt – der Nachweis gehört ins Betäubungsmittelbuch', 'KM003');
    END IF;
    SELECT * INTO o FROM lager.ort WHERE id = (p->>'ort')::uuid;
    IF NOT FOUND OR o.stillgelegt THEN
        PERFORM lager._fehler('Lagerort unbekannt oder stillgelegt', 'KM003');
    END IF;
    IF m IS NULL OR m <= 0 OR m <> round(m, 3) THEN
        PERFORM lager._fehler('Menge größer als null mit höchstens drei Nachkommastellen erwartet');
    END IF;
    IF a.in_stuecken AND m <> round(m) THEN
        PERFORM lager._fehler('Dieser Artikel wird in Stücken gezählt – eine ganze Zahl erwartet');
    END IF;
    beide := a.hat_charge AND a.hat_serie;
    IF a.fuehrung = 'ohne' THEN
        IF bez <> '' OR v IS NOT NULL THEN
            PERFORM lager._fehler('Der Artikel wird ohne Charge geführt');
        END IF;
    ELSIF bez = '' THEN
        PERFORM lager._fehler(CASE a.fuehrung WHEN 'serie' THEN 'Seriennummer fehlt' ELSE 'Charge fehlt' END);
    END IF;
    IF beide AND v_los IS NULL THEN
        PERFORM lager._fehler('Charge fehlt');
    END IF;
    IF NOT beide AND v_los IS NOT NULL THEN
        PERFORM lager._fehler('Eine Charge neben der Seriennummer gibt es nur, wenn der Artikel beides hat');
    END IF;
    IF a.verfall_pflicht AND v IS NULL THEN
        PERFORM lager._fehler('Verfallsdatum fehlt');
    END IF;
    IF a.fuehrung = 'serie' AND m <> 1 THEN
        PERFORM lager._fehler('Mit Seriennummer ist die Menge je Stück 1');
    END IF;
    IF beide THEN
        SELECT x.verfall INTO v_alt FROM lager.charge x WHERE x.artikel = a.id AND x.los = v_los AND x.verfall IS DISTINCT FROM v LIMIT 1;
        IF FOUND THEN
            PERFORM lager._fehler(format('Die Charge %s ist mit Verfall %s bekannt – er gilt für alle ihre Stücke', v_los, coalesce(to_char(v_alt, 'DD.MM.YYYY'), 'ohne Datum')));
        END IF;
    END IF;
    SELECT * INTO c FROM lager.charge WHERE artikel = a.id AND bezeichnung = bez FOR UPDATE;
    IF FOUND THEN
        IF c.verfall IS DISTINCT FROM v THEN
            PERFORM lager._fehler(format('Die Charge ist mit Verfall %s bekannt', coalesce(to_char(c.verfall, 'DD.MM.YYYY'), 'ohne Datum')));
        END IF;
        IF a.fuehrung = 'serie' AND (c.ohne_serie OR EXISTS (SELECT 1 FROM lager.bestand WHERE charge = c.id AND menge > 0)) THEN
            PERFORM lager._fehler('Diese Seriennummer ist schon im Bestand');
        END IF;
        IF beide AND c.los IS DISTINCT FROM v_los THEN
            PERFORM lager._fehler(format('Diese Seriennummer ist mit der Charge %s bekannt', coalesce(c.los, '(ohne Angabe)')));
        END IF;
    ELSE
        INSERT INTO lager.charge (artikel, bezeichnung, verfall, code, los) VALUES (a.id, bez, v, nullif(p->>'code', ''), v_los) RETURNING * INTO c;
    END IF;
    INSERT INTO lager.bewegung (art, charge, ort_nach, menge, preis, subjekt, eintrag, position, grund, lieferant)
    VALUES ('zugang', c.id, o.id, m, (p->>'preis')::numeric, p_subjekt, (p->>'eintrag')::uuid, nullif(p->>'position', ''), nullif(p->>'grund', ''),
            nullif(left(trim(coalesce(p->>'lieferant', '')), 200), ''))
    RETURNING id INTO bid;
    PERFORM lager._bewegt(p_subjekt, bid);
    RETURN bid;
END $$;
