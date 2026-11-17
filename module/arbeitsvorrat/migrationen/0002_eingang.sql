-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Modul arbeitsvorrat, Migration 0002: Eingangszeit je Eintrag
--
-- Liefert eine Quelle mehrere Vorgänge in einer Transaktion (Abgleich mit Paperless), hatten sie
-- bisher alle dieselbe Eingangszeit und damit keine feste Reihenfolge. Jetzt zählt der Augenblick
-- der Lieferung; die Liste zeigt die Einträge in der Reihenfolge, in der die Quelle sie brachte.
ALTER TABLE arbeitsvorrat.eintrag ALTER COLUMN eingegangen_am SET DEFAULT clock_timestamp();
ALTER TABLE arbeitsvorrat.beitrag ALTER COLUMN geliefert_am SET DEFAULT clock_timestamp();
