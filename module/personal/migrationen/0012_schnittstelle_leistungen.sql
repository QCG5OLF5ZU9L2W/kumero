-- Modul personal 0.12.0: Schnittstelle zum Modul leistungen (F154).
--
-- Die Anschlussstelle personal._zuschlag_nachweis() (F140) lieferte bisher immer NULL – „Nachweis fehlt“. Jetzt fragt sie
-- für Regeln mit der Grundlage „Leistung“ das Modul leistungen, wenn es am Mandanten eingeschaltet ist. Ohne das Modul
-- bleibt es bei „Nachweis fehlt“; Zeitzuschläge bleiben offen, bis es den Grundlohn je Stunde gibt.
-- Dazu personal.api_lohn_fest(): ob die Lohnvorbereitung eines Monats geprüft oder übergeben ist – dann ändert das Modul
-- leistungen an den Leistungen dieses Monats nichts mehr.

CREATE OR REPLACE FUNCTION personal._zuschlag_nachweis(p_fassung uuid, p_person uuid, p_monat date) RETURNS jsonb
LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
DECLARE f personal.zuschlagsregel_fassung; erg jsonb;
BEGIN
    SELECT * INTO f FROM personal.zuschlagsregel_fassung WHERE id = p_fassung;
    IF NOT FOUND OR f.form NOT IN ('anteil', 'betrag', 'staffel') THEN RETURN NULL; END IF;
    IF to_regprocedure('leistungen.nachweis(uuid,date,text,integer,integer,integer,integer,text,text)') IS NULL
       OR NOT EXISTS (SELECT 1 FROM mandant.modul WHERE name = 'leistungen' AND aktiv) THEN
        RETURN NULL;
    END IF;
    EXECUTE 'SELECT leistungen.nachweis($1, $2, $3, $4, $5, $6, $7, $8, $9)' INTO erg
      USING p_person, p_monat, f.form, f.satz_bp, f.betrag_cent, f.schwelle_cent, f.satz_ab_schwelle_bp, f.umfang, f.bezug;
    RETURN erg;
END $$;

CREATE FUNCTION personal.api_lohn_fest(p_person uuid, p_monat date) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, personal, pg_temp AS $$
    SELECT coalesce((SELECT l.zustand <> 'offen' FROM personal.lohnvorbereitung l WHERE l.person = p_person AND l.monat = p_monat ORDER BY l.fassung DESC LIMIT 1), false)
$$;

REVOKE EXECUTE ON FUNCTION personal._zuschlag_nachweis(uuid, uuid, date), personal.api_lohn_fest(uuid, date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION personal.api_lohn_fest(uuid, date) TO kumero_app;
