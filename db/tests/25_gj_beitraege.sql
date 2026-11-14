-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Tests Migration 0007: Beiträge von Modulen zur Jahressperre (F75 Nr. 11)

SET ROLE kumero_app;
SELECT kern.buch_anlegen('test:admin', 'Beitrag (Test)', 'EUR');
SELECT kern.geschaeftsjahr_anlegen('test:admin', '2026', '2026-01-01', '2026-12-31');
SELECT kern.modul_registrieren('test:admin', 'kontenrahmen', '0.1.0', ARRAY['gj_sperren_beitrag']);

SELECT test.fehler('Sperre ohne Beitrag des Moduls wird verweigert',
    $$SELECT kern.geschaeftsjahr_sperren('test:frank', '2026')$$, 'KM003');
SELECT test.fehler('Beitrag nur von registrierter Modulversion',
    $$SELECT kern.gj_beitrag_setzen('test:frank', '2026', 'kontenrahmen', '9.9.9', repeat('a', 64))$$, 'KM003');

SELECT kern.gj_beitrag_setzen('test:frank', '2026', 'kontenrahmen', '0.1.0', repeat('a', 64));
SELECT kern.gj_beitrag_setzen('test:frank', '2026', 'kontenrahmen', '0.1.0', repeat('b', 64));
SELECT test.ok('Beitrag vor der Sperre änderbar, mit Vorher-Stand im Protokoll',
    (SELECT vorher->>'inhalt_hash' FROM kern.protokoll WHERE typ = 'geschaeftsjahr.beitrag_gesetzt' ORDER BY nr DESC LIMIT 1) = repeat('a', 64));
SELECT test.ok('Beitrag steht im Sperrinhalt',
    kern.gj_sperr_inhalt('2026')->'beitraege'->'kontenrahmen'->>'inhalt_hash' = repeat('b', 64));

SELECT kern.geschaeftsjahr_sperren('test:frank', '2026');
SELECT test.ok('Gesperrt; der Beitrag ist Teil des protokollierten Sperrinhalts',
    (SELECT nachher->'beitraege'->'kontenrahmen'->>'inhalt_hash' FROM kern.protokoll WHERE typ = 'geschaeftsjahr.gesperrt') = repeat('b', 64));
SELECT test.fehler('Beitrag nach der Sperre unveränderlich (F75 Nr. 11)',
    $$SELECT kern.gj_beitrag_setzen('test:frank', '2026', 'kontenrahmen', '0.1.0', repeat('c', 64))$$, 'KM003');
RESET ROLE;
SET ROLE kumero_owner;
SELECT test.fehler('Auch direkt als Eigentümer unveränderlich',
    $$UPDATE kern.gj_beitrag SET inhalt_hash = repeat('d', 64)$$, 'KM001');
RESET ROLE;

-- Nach einem Modul-Update muss der Beitrag neu gesetzt werden
SET ROLE kumero_app;
SELECT kern.geschaeftsjahr_anlegen('test:admin', '2027', '2027-01-01', '2027-12-31');
SELECT kern.gj_beitrag_setzen('test:frank', '2027', 'kontenrahmen', '0.1.0', repeat('e', 64));
SELECT kern.modul_registrieren('test:admin', 'kontenrahmen', '0.2.0', ARRAY['gj_sperren_beitrag']);
SELECT test.fehler('Beitrag einer älteren Modulversion reicht nicht',
    $$SELECT kern.geschaeftsjahr_sperren('test:frank', '2027')$$, 'KM003');
SELECT test.ok('Kette nachrechenbar', NOT EXISTS (SELECT 1 FROM kern.kette_pruefen()));
RESET ROLE;
