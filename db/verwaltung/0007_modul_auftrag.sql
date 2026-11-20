-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Verwaltungsdatenbank, Migration 0007: Aufträge für Module (VERWALTUNG.md Abschnitt 16, F142)
--
-- Der Administrator eines Mandanten schaltet Module in der Weboberfläche ein und aus und zieht sie nach einem Update
-- nach. Einschalten spielt Migrationen ein und braucht deshalb den Eigentümer der Datenbank: Es läuft – wie „Buch
-- anlegen“ – als Auftrag an den Dienst. Als Auftraggeber steht `system:mandant` (F114); wer es war, steht nur bis zur
-- Ausführung in den Angaben und danach im Protokoll des Buchs bzw. des Mandanten.

ALTER TABLE verwaltung.auftrag DROP CONSTRAINT auftrag_art_check;
ALTER TABLE verwaltung.auftrag ADD CONSTRAINT auftrag_art_check
    CHECK (art IN ('mandant_anlegen', 'mandant_exportieren', 'mandant_loeschen', 'buch_anlegen',
                   'modul_einschalten', 'modul_ausschalten', 'module_nachziehen'));

-- p_angaben: subjekt; für ein einzelnes Modul außerdem modul und – bei einem Modul eines Buchs – buch.
CREATE FUNCTION verwaltung.modul_auftrag_anlegen(p_mandant text, p_art text, p_angaben jsonb) RETURNS uuid
LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, verwaltung, pg_temp AS $$
DECLARE aid uuid;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM verwaltung.mandant WHERE kennung = p_mandant AND stillgelegt_am IS NULL) THEN
        PERFORM verwaltung.verweigern('Mandant unbekannt oder stillgelegt', 'KM003');
    END IF;
    IF p_art IS NULL OR p_art NOT IN ('modul_einschalten', 'modul_ausschalten', 'module_nachziehen') THEN
        PERFORM verwaltung.verweigern('Art des Auftrags unbekannt', 'KM002');
    END IF;
    IF coalesce(p_angaben->>'subjekt', '') !~ '^(nutzer|system):[A-Za-z0-9._-]+$' THEN
        PERFORM verwaltung.verweigern('Angaben zum Auftrag unvollständig', 'KM002');
    END IF;
    IF p_art = 'module_nachziehen' THEN
        p_angaben := jsonb_build_object('subjekt', p_angaben->>'subjekt');
    ELSE
        IF coalesce(p_angaben->>'modul', '') !~ '^[a-z][a-z0-9-]{0,31}$' OR p_angaben->>'modul' = 'kern' THEN
            PERFORM verwaltung.verweigern('Modul fehlt oder ist ungültig', 'KM002');
        END IF;
        IF p_angaben ? 'buch' AND p_angaben->>'buch' IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM verwaltung.buch WHERE mandant = p_mandant AND kennung = p_angaben->>'buch') THEN
            PERFORM verwaltung.verweigern('Buch unbekannt', 'KM003');
        END IF;
        p_angaben := jsonb_strip_nulls(jsonb_build_object('subjekt', p_angaben->>'subjekt', 'modul', p_angaben->>'modul', 'buch', p_angaben->>'buch'));
    END IF;
    IF EXISTS (SELECT 1 FROM verwaltung.auftrag WHERE mandant = p_mandant AND zustand IN ('offen', 'laeuft')) THEN
        PERFORM verwaltung.verweigern('Für diesen Mandanten läuft schon ein Auftrag', 'KM003');
    END IF;
    INSERT INTO verwaltung.auftrag (art, mandant, angaben, angelegt_von) VALUES (p_art, p_mandant, p_angaben, 'system:mandant')
        RETURNING id INTO aid;
    PERFORM verwaltung._protokoll('auftrag.angelegt', 'system:mandant',
        jsonb_strip_nulls(jsonb_build_object('auftrag', aid::text, 'mandant', p_mandant, 'art', p_art, 'modul', p_angaben->>'modul', 'buch', p_angaben->>'buch')));
    RETURN aid;
END $$;
REVOKE EXECUTE ON FUNCTION verwaltung.modul_auftrag_anlegen(text, text, jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION verwaltung.modul_auftrag_anlegen(text, text, jsonb) TO kumero_app;
