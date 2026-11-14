-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Tests Migration 0009: Idempotenz, Modulprotokoll, Empfänger, Webhooks

SET ROLE kumero_app;
SELECT kern.buch_anlegen('test:admin', 'Integration (Test)', 'EUR');
SELECT kern.konto_anlegen('test:admin', '1000', 'Kasse', 'aktiv');
SELECT kern.konto_anlegen('test:admin', '8400', 'Erlöse', 'ertrag');
RESET ROLE;

-- ---------------------------------------------------------------------------
-- Idempotenz: eine Transaktion je Aufruf, wie in der Anwendung
-- ---------------------------------------------------------------------------
SET ROLE kumero_app;
BEGIN;
SELECT test.ok('Erster Aufruf ist neu',
    kern.idempotenz_beginnen('nutzer:frank', 'schluessel-0001', 'POST /buchungen', repeat('a', 64)) = '{"neu": true}');
SELECT kern.entwurf_anlegen('nutzer:frank', '{"belegdatum":"2026-03-01","buchungsdatum":"2026-03-01","text":"Testverkauf",
    "zeilen":[{"konto":"1000","seite":"S","betrag":"10.00"},{"konto":"8400","seite":"H","betrag":"10.00"}]}');
SELECT test.ok('Schlüssel steht im Protokolleintrag (F19)',
    (SELECT ids->>'idempotenz' FROM kern.protokoll ORDER BY nr DESC LIMIT 1) = 'schluessel-0001');
SELECT kern.idempotenz_abschliessen('nutzer:frank', 'schluessel-0001', 201, '{"id":"x"}');
COMMIT;

BEGIN;
SELECT test.ok('Wiederholung liefert die gespeicherte Antwort',
    kern.idempotenz_beginnen('nutzer:frank', 'schluessel-0001', 'POST /buchungen', repeat('a', 64))
        = '{"neu": false, "status": 201, "inhalt": {"id": "x"}}');
COMMIT;

SELECT test.fehler('Gleicher Schlüssel, andere Anfrage → KM006 (F71)',
    $$SELECT kern.idempotenz_beginnen('nutzer:frank', 'schluessel-0001', 'POST /buchungen', repeat('b', 64))$$, 'KM006');
SELECT test.ok('Schlüssel gelten je Subjekt',
    kern.idempotenz_beginnen('nutzer:anna', 'schluessel-0001', 'POST /buchungen', repeat('b', 64)) = '{"neu": true}');
RESET ROLE;

SET ROLE kumero_app;
BEGIN;
SELECT kern.idempotenz_beginnen('nutzer:frank', 'schluessel-0002', 'POST /buchungen', repeat('c', 64));
SELECT test.fehler('Nur ein idempotenter Aufruf je Transaktion',
    $$SELECT kern.idempotenz_beginnen('nutzer:frank', 'schluessel-0003', 'POST /buchungen', repeat('c', 64))$$, 'KM003');
ROLLBACK;
SELECT test.ok('Abgebrochener Aufruf hinterlässt nichts – Wiederholung läuft neu',
    kern.idempotenz_beginnen('nutzer:frank', 'schluessel-0002', 'POST /buchungen', repeat('c', 64)) = '{"neu": true}');
RESET ROLE;

-- ---------------------------------------------------------------------------
-- Modulprotokoll
-- ---------------------------------------------------------------------------
SET ROLE kumero_app;
SELECT kern.modul_registrieren('test:admin', 'kontenrahmen', '0.1.0');
SELECT kern.modul_protokoll('nutzer:frank', 'kontenrahmen', 'kontenrahmen.zuordnung_geaendert',
    '{"konto":"8400"}', '{"zeile":"12"}', '{"zeile":"14"}');
SELECT test.ok('Moduleintrag mit Vorher/Nachher in der Kette',
    (SELECT vorher->>'zeile' || '>' || (nachher->>'zeile') FROM kern.protokoll
      WHERE typ = 'kontenrahmen.zuordnung_geaendert') = '12>14');
SELECT test.ok('… und als schmales Ereignis in der Outbox (F48)',
    (SELECT inhalt FROM kern.outbox WHERE typ = 'kontenrahmen.zuordnung_geaendert') = '{"konto":"8400"}');
SELECT test.fehler('Fremdes Präfix verweigert',
    $$SELECT kern.modul_protokoll('nutzer:frank', 'kontenrahmen', 'buchung.festgeschrieben', '{}')$$, 'KM002');
SELECT test.fehler('Der Kern ist kein Fremdmodul',
    $$SELECT kern.modul_protokoll('nutzer:frank', 'kern', 'kern.etwas', '{}')$$, 'KM002');
SELECT test.fehler('Unbekanntes Modul verweigert',
    $$SELECT kern.modul_protokoll('nutzer:frank', 'bank', 'bank.umsatz_importiert', '{}')$$, 'KM002');
RESET ROLE;

-- ---------------------------------------------------------------------------
-- Empfänger im Prozess
-- ---------------------------------------------------------------------------
SET ROLE kumero_app;
SELECT kern.modul_registrieren('test:admin', 'paperless', '0.1.0');
BEGIN;
SELECT test.ok('Neuer Empfänger beginnt beim aktuellen Stand',
    NOT EXISTS (SELECT 1 FROM kern.empfaenger_holen('paperless')));
COMMIT;
SELECT kern.tag_anlegen('test:admin', 'Praxis');
SELECT kern.tag_anlegen('test:admin', 'Labor');
BEGIN;
CREATE TEMP TABLE geholt ON COMMIT DROP AS SELECT * FROM kern.empfaenger_holen('paperless');
SELECT test.ok('Neue Ereignisse in Commit-Reihenfolge',
    (SELECT array_agg(typ ORDER BY protokoll_nr) FROM geholt) = ARRAY['tag.angelegt', 'tag.angelegt']);
SELECT kern.empfaenger_vermerken('paperless', (SELECT max(protokoll_nr) FROM geholt));
COMMIT;
BEGIN;
SELECT test.ok('Vermerkte Ereignisse kommen nicht wieder',
    NOT EXISTS (SELECT 1 FROM kern.empfaenger_holen('paperless')));
SELECT test.fehler('Lesestand nur vorwärts',
    $$SELECT kern.empfaenger_vermerken('paperless', 1)$$, 'KM003');
COMMIT;
RESET ROLE;

-- ---------------------------------------------------------------------------
-- Webhooks
-- ---------------------------------------------------------------------------
SET ROLE kumero_app;
SELECT kern.webhook_abo_anlegen('nutzer:frank', 'https://empfaenger.example/kumero', ARRAY['tag.angelegt'], 'webhook-1');
SELECT kern.tag_anlegen('test:admin', 'Privat');
SELECT kern.konto_anlegen('test:admin', '4930', 'Bürobedarf', 'aufwand');
SELECT test.ok('Nur abonnierte Ereignisse werden zugestellt',
    (SELECT count(*) FROM kern.zustellung_holen()) = 1
    AND (SELECT typ FROM kern.zustellung_holen()) = 'tag.angelegt');
SELECT kern.zustellung_vermerken(z.abo, z.protokoll_nr, false) FROM kern.zustellung_holen() z;
SELECT test.ok('Fehlschlag: später erneut, Versuch gezählt',
    NOT EXISTS (SELECT 1 FROM kern.zustellung_holen())
    AND (SELECT versuche FROM kern.zustellung) = 1);
RESET ROLE;
UPDATE kern.zustellung SET naechster_versuch = now();       -- Zeit vorspulen (Superuser)
SET ROLE kumero_app;
SELECT kern.zustellung_vermerken(z.abo, z.protokoll_nr, true) FROM kern.zustellung_holen() z;
SELECT test.ok('Erfolg: erledigt',
    (SELECT status FROM kern.zustellung) = 'erledigt');
SELECT test.fehler('Erledigte Zustellung nicht erneut vermerken',
    $$SELECT kern.zustellung_vermerken((SELECT abo FROM kern.zustellung), (SELECT protokoll_nr FROM kern.zustellung), true)$$, 'KM003');
SELECT kern.webhook_abo_aendern('nutzer:frank', (SELECT id FROM kern.webhook_abo), ARRAY['tag.angelegt'], false);
SELECT kern.tag_anlegen('test:admin', 'Ruhe');
SELECT test.ok('Inaktives Abo erhält nichts; Änderung mit Vorher/Nachher protokolliert',
    (SELECT count(*) FROM kern.zustellung) = 1
    AND (SELECT (vorher->>'aktiv')::boolean AND NOT (nachher->>'aktiv')::boolean FROM kern.protokoll WHERE typ = 'webhook.geaendert'));
SELECT test.fehler('Anwendung schreibt die Zustellung nicht direkt (F64)',
    $$UPDATE kern.zustellung SET status = 'erledigt'$$, '42501');
SELECT test.ok('Kette nachrechenbar', NOT EXISTS (SELECT 1 FROM kern.kette_pruefen()));
RESET ROLE;
