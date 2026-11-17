-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Tests Migration 0010: verzögerte Prüfungen liefern als Anwendung ihren eigenen Fehlercode

SET ROLE kumero_app;
SELECT kern.buch_anlegen('test:admin', 'Verzögert (Test)', 'EUR');
SELECT kern.geschaeftsjahr_anlegen('test:admin', '2026', '2026-01-01', '2026-12-31');
SELECT test.fehler('Lücke zwischen Geschäftsjahren meldet KM002, nicht 42501 (F65)',
    $t$DO $x$ BEGIN
        PERFORM kern.geschaeftsjahr_anlegen('test:admin', '2028', '2028-01-01', '2028-12-31');
        SET CONSTRAINTS ALL IMMEDIATE;
      END $x$ $t$, 'KM002');
SELECT test.fehler('Ebenso für die Rolleninvarianten (F43)',
    $t$DO $x$ BEGIN
        PERFORM kern.rollenvorlagen_anlegen('system:cli');
        PERFORM kern.rolle_setzen('system:cli', 'Prüfer', 'pruefer', 'x', '[{"baustein":"Buchungen erfassen"}]');
        SET CONSTRAINTS ALL IMMEDIATE;
      END $x$ $t$, 'KM002');
RESET ROLE;
