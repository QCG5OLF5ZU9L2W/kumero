-- SPDX-License-Identifier: AGPL-3.0-or-later
-- kumero – Kern, Migration 0010: verzögerte Prüfungen mit eigenen Rechten
--
-- Verzögerte Constraint-Trigger laufen beim Commit mit den Rechten der Sitzung.
-- Für kumero_app ist kern.verweigern() nicht ausführbar (F64); eine verletzte
-- Regel kam deshalb als 42501 (keine Berechtigung) statt als KM002 an. Wie in
-- 0008 für die Rolleninvarianten läuft die Prüfung der Lückenlosigkeit der
-- Geschäftsjahre (F65) jetzt mit den Rechten des Eigentümers.
ALTER FUNCTION kern.tg_gj_lueckenlos() SECURITY DEFINER SET search_path = pg_catalog, kern, pg_temp;
REVOKE EXECUTE ON FUNCTION kern.tg_gj_lueckenlos() FROM PUBLIC;
