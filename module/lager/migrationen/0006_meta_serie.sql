-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul lager, Migration 0006: Artikel mit Seriennummer dürfen in eine Artikelgruppe (LAGER.md 17.7, F192)
--
-- Entscheidung des Verantwortlichen vom 2026-11-25: Auch ein Gerät mit Seriennummer kann durch ein beliebiges anderes
-- ersetzt werden, das funktional identisch ist (Beispiel: Handscanner). Die Festlegung MB1 aus 0.7.73 („Artikel mit
-- Seriennummer gehören nie in eine Gruppe“) ist damit aufgehoben. Es bleibt: kein Arzneimittel, kein Betäubungsmittel,
-- dieselbe Lagereinheit wie die Gruppe. Bewegt wird weiter das einzelne Stück mit seiner Seriennummer; die Gruppe zählt
-- die Stücke.

CREATE OR REPLACE FUNCTION lager.tg_artikel_meta() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE m lager.meta_artikel;
BEGIN
    IF NEW.meta_artikel IS NULL THEN
        RETURN NEW;
    END IF;
    SELECT * INTO m FROM lager.meta_artikel WHERE id = NEW.meta_artikel;
    IF m.stillgelegt AND (TG_OP = 'INSERT' OR OLD.meta_artikel IS DISTINCT FROM NEW.meta_artikel) THEN
        PERFORM lager._fehler('Die Artikelgruppe ist stillgelegt', 'KM003');
    END IF;
    IF NEW.art = 'arzneimittel' THEN
        PERFORM lager._fehler('Arzneimittel gehören zu keiner Artikelgruppe: Ob eines ein anderes ersetzt, ist eine Entscheidung im Einzelfall', 'KM003');
    END IF;
    IF NEW.btm THEN
        PERFORM lager._fehler('Betäubungsmittel gehören zu keiner Artikelgruppe', 'KM003');
    END IF;
    IF lower(trim(NEW.einheit)) <> lower(trim(m.einheit)) THEN
        PERFORM lager._fehler(format('Die Lagereinheit des Artikels (%s) ist nicht die der Artikelgruppe (%s)', NEW.einheit, m.einheit), 'KM003');
    END IF;
    RETURN NEW;
END $$;
