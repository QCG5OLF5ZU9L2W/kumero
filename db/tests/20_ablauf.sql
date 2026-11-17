-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Tests DDL Teil 2 im Testbetrieb: kompletter Ablauf ausschließlich über die
-- Funktionen und als Anwendungsrolle (F64). Nur synthetische Musterdaten.

SET ROLE kumero_app;

-- ---------------------------------------------------------------------------
-- Einrichtung
-- ---------------------------------------------------------------------------
SELECT kern.buch_anlegen('test:admin', 'Musterpraxis (Test)', 'EUR');
SELECT kern.konto_anlegen('test:admin', '1000', 'Kasse', 'aktiv');
SELECT kern.konto_anlegen('test:admin', '1200', 'Bank', 'aktiv');
SELECT kern.konto_anlegen('test:admin', '4930', 'Bürobedarf', 'aufwand');
SELECT kern.konto_anlegen('test:admin', '8400', 'Erlöse', 'ertrag');
SELECT kern.konto_anlegen('test:admin', '9999', 'Altkonto', 'aufwand');
SELECT kern.konto_aendern('test:admin', '9999', p_aktiv => false);
SELECT kern.tag_anlegen('test:admin', 'Kostenstelle Empfang');
SELECT kern.nummernkreis_anlegen('test:admin', 'ALLG', 'Allgemein');
SELECT kern.buchungsart_anlegen('test:admin', 'BANK', 'Bank', 'ALLG');
SELECT kern.geschaeftsjahr_anlegen('test:admin', '2026', '2026-01-01', '2026-12-31');
SELECT kern.geschaeftsjahr_anlegen('test:admin', '2027', '2027-01-01', '2027-12-31');
SELECT kern.nummernkreis_festlegen('test:admin', 'ALLG', '2026', '{JJJJ}-{NR:5}', true);
SELECT test.ok('Einrichtung protokolliert', (SELECT count(*) FROM kern.protokoll) >= 12);

SELECT test.ok('Nummer formatieren',
    kern.nr_formatieren('AP-{JJ}/{MM}/{NR:3}', 7, '2026-01-01', '2026-03-15') = 'AP-26/03/007'
    AND kern.nr_formatieren('{NR:2}', 12345, '2026-01-01', '2026-01-01') = '12345');

-- ---------------------------------------------------------------------------
-- Entwurf: unvollständig erlaubt, Regeln beim Vorbereiten
-- ---------------------------------------------------------------------------
CREATE TEMP TABLE t (name text PRIMARY KEY, id uuid, wert text);

INSERT INTO t SELECT 'b1', kern.entwurf_anlegen('test:azubi', '{
    "buchungsart": "BANK", "belegdatum": "2026-03-02", "buchungsdatum": "2026-03-03",
    "belegnummer": "RE-4711", "text": "Druckerpapier (Muster)",
    "zeilen": [ {"konto": "4930", "seite": "S", "betrag": "23.80", "tags": ["Kostenstelle Empfang"]} ]
}'::jsonb), NULL;
SELECT test.ok('Unvollständiger Entwurf gespeichert (F62)',
    (SELECT status FROM kern.buchung WHERE id = (SELECT id FROM t WHERE name = 'b1')) = 'entwurf');
SELECT test.fehler('Vorbereiten verlangt zwei Zeilen (F4)',
    $$SELECT kern.vorbereiten('test:frank', (SELECT id FROM t WHERE name = 'b1'))$$, 'KM002');

SELECT test.fehler('Betrag mit drei Nachkommastellen wird abgelehnt (F19)',
    $$SELECT kern.entwurf_aendern('test:azubi', (SELECT id FROM t WHERE name = 'b1'),
        '{"buchungsart":"BANK","zeilen":[{"konto":"4930","seite":"S","betrag":"23.805"}]}')$$, 'KM002');
SELECT test.fehler('Unbekanntes Konto',
    $$SELECT kern.entwurf_aendern('test:azubi', (SELECT id FROM t WHERE name = 'b1'),
        '{"buchungsart":"BANK","zeilen":[{"konto":"0815","seite":"S","betrag":"1.00"}]}')$$, 'KM002');

UPDATE t SET wert = kern.entwurf_etag(id) WHERE name = 'b1';
SELECT kern.entwurf_aendern('test:azubi', (SELECT id FROM t WHERE name = 'b1'), '{
    "buchungsart": "BANK", "belegdatum": "2026-03-02", "buchungsdatum": "2026-03-03",
    "belegnummer": "RE-4711", "text": "Druckerpapier (Muster)",
    "belege": [ {"link": "https://paperless.example.org/documents/101/", "md5": "0123456789abcdef0123456789abcdef",
                 "sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"} ],
    "zeilen": [ {"konto": "4930", "seite": "S", "betrag": "20.00", "tags": ["Kostenstelle Empfang"]},
                {"konto": "4930", "seite": "H", "betrag": "20.00"} ]
}'::jsonb, (SELECT wert FROM t WHERE name = 'b1'));
SELECT test.fehler('ETag veraltet → Konflikt (F19)',
    $$SELECT kern.entwurf_aendern('test:azubi', (SELECT id FROM t WHERE name = 'b1'), '{}', (SELECT wert FROM t WHERE name = 'b1'))$$, 'KM005');
SELECT test.fehler('Konto nicht im Soll und Haben derselben Buchung (F4)',
    $$SELECT kern.vorbereiten('test:frank', (SELECT id FROM t WHERE name = 'b1'))$$, 'KM002');

SELECT kern.entwurf_aendern('test:azubi', (SELECT id FROM t WHERE name = 'b1'), '{
    "buchungsart": "BANK", "belegdatum": "2026-03-02", "buchungsdatum": "2026-03-03",
    "belegnummer": "RE-4711", "text": "Druckerpapier (Muster)",
    "belege": [ {"link": "https://paperless.example.org/documents/101/", "md5": "0123456789abcdef0123456789abcdef",
                 "sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"} ],
    "zeilen": [ {"konto": "4930", "seite": "S", "betrag": "20.00", "tags": ["Kostenstelle Empfang"]},
                {"konto": "1200", "seite": "H", "betrag": "19.99"} ]
}'::jsonb);
SELECT test.fehler('Σ Soll = Σ Haben (F4)',
    $$SELECT kern.vorbereiten('test:frank', (SELECT id FROM t WHERE name = 'b1'))$$, 'KM002');

SELECT kern.entwurf_aendern('test:azubi', (SELECT id FROM t WHERE name = 'b1'), '{
    "buchungsart": "BANK", "belegdatum": "2026-03-02", "buchungsdatum": "2026-03-03",
    "belegnummer": "RE-4711", "text": "Druckerpapier (Muster)",
    "belege": [ {"link": "https://paperless.example.org/documents/101/", "md5": "0123456789abcdef0123456789abcdef",
                 "sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"} ],
    "zeilen": [ {"konto": "4930", "seite": "S", "betrag": "20.00", "tags": ["Kostenstelle Empfang"]},
                {"konto": "1200", "seite": "H", "betrag": "20.00"} ]
}'::jsonb);
UPDATE t SET wert = kern.vorbereiten('test:frank', id) WHERE name = 'b1';
SELECT test.ok('Vorbereitet, Freigabe-Hash = Hash des Inhalts (F66)',
    (SELECT wert FROM t WHERE name = 'b1') = kern.buchung_freigabe_hash((SELECT id FROM t WHERE name = 'b1')));

-- Änderung nach Vorbereitung setzt zurück (F66)
SELECT kern.entwurf_aendern('test:frank', (SELECT id FROM t WHERE name = 'b1'), kern.buchung_inhalt((SELECT id FROM t WHERE name = 'b1'))
       || '{"buchungsart":"BANK","text":"Druckerpapier A4 (Muster)"}'::jsonb
       || jsonb_build_object('zeilen', '[{"konto":"4930","seite":"S","betrag":"20.00","tags":["Kostenstelle Empfang"]},{"konto":"1200","seite":"H","betrag":"20.00"}]'::jsonb));
SELECT test.ok('Änderung setzt freigabebereite Buchung auf Entwurf zurück (F66)',
    (SELECT status FROM kern.buchung WHERE id = (SELECT id FROM t WHERE name = 'b1')) = 'entwurf');
SELECT test.fehler('Festschreiben nur, wenn freigabebereit',
    $$SELECT * FROM kern.festschreiben('test:frank', ARRAY[(SELECT id FROM t WHERE name = 'b1')])$$, 'KM003');

SELECT kern.vorbereiten('test:frank', (SELECT id FROM t WHERE name = 'b1'));
CREATE TEMP TABLE erg AS SELECT * FROM kern.festschreiben('test:frank', ARRAY[(SELECT id FROM t WHERE name = 'b1')]);
SELECT test.ok('Festgeschrieben im Testbetrieb ohne Signatur (F59)',
    (SELECT o_nr FROM erg) = '2026-00001' AND (SELECT o_journal_nr FROM erg) = 1
    AND (SELECT testbetrieb FROM kern.buchung WHERE id = (SELECT id FROM t WHERE name = 'b1')));
SELECT test.ok('Kette nachrechenbar', NOT EXISTS (SELECT 1 FROM kern.kette_pruefen()));

-- ---------------------------------------------------------------------------
-- Weitere Regeln
-- ---------------------------------------------------------------------------
INSERT INTO t SELECT 'b2', kern.entwurf_anlegen('test:azubi', '{
    "buchungsart": "BANK", "belegdatum": "2026-04-01", "buchungsdatum": "2026-04-01", "text": "Altkonto",
    "zeilen": [ {"konto": "9999", "seite": "S", "betrag": "5.00"}, {"konto": "1200", "seite": "H", "betrag": "5.00"} ]
}'::jsonb), NULL;
SELECT test.fehler('Deaktiviertes Konto wird abgelehnt',
    $$SELECT kern.vorbereiten('test:frank', (SELECT id FROM t WHERE name = 'b2'))$$, 'KM002');
SELECT kern.entwurf_loeschen('test:frank', (SELECT id FROM t WHERE name = 'b2'));
SELECT test.ok('Entwurf gelöscht, mit Vorher-Stand im Protokoll (F48)',
    NOT EXISTS (SELECT 1 FROM kern.buchung WHERE id = (SELECT id FROM t WHERE name = 'b2'))
    AND (SELECT vorher->>'text' FROM kern.protokoll WHERE typ = 'buchung.entwurf_geloescht') = 'Altkonto');

INSERT INTO t SELECT 'b3', kern.entwurf_anlegen('test:azubi', '{
    "buchungsart": "BANK", "belegdatum": "2027-01-05", "buchungsdatum": "2027-01-05", "text": "Januar 2027",
    "zeilen": [ {"konto": "1000", "seite": "S", "betrag": "50.00"}, {"konto": "8400", "seite": "H", "betrag": "50.00"} ]
}'::jsonb), NULL;
SELECT kern.vorbereiten('test:frank', (SELECT id FROM t WHERE name = 'b3'));
SELECT test.fehler('Nummernkreis für 2027 nicht festgelegt (F25)',
    $$SELECT * FROM kern.festschreiben('test:frank', ARRAY[(SELECT id FROM t WHERE name = 'b3')])$$, 'KM003');
SELECT kern.nummernkreis_festlegen('test:admin', 'ALLG', '2027', '{JJJJ}-{NR:5}', true);
SELECT * FROM kern.festschreiben('test:frank', ARRAY[(SELECT id FROM t WHERE name = 'b3')]);
SELECT test.ok('Zähler beginnt im neuen Jahr neu',
    (SELECT nr FROM kern.buchung WHERE id = (SELECT id FROM t WHERE name = 'b3')) = '2027-00001');

-- ---------------------------------------------------------------------------
-- Storno (F30)
-- ---------------------------------------------------------------------------
INSERT INTO t SELECT 's1', kern.storno_anlegen('test:frank', (SELECT id FROM t WHERE name = 'b1'), '2026-03-10'), NULL;
SELECT test.ok('Storno spiegelt die Seiten',
    (SELECT string_agg(k.nr || z.seite::text, ',' ORDER BY z.pos) FROM kern.buchungszeile z JOIN kern.konto k ON k.id = z.konto
      WHERE z.buchung = (SELECT id FROM t WHERE name = 's1')) = '4930H,1200S');
SELECT test.fehler('Nur ein Storno je Buchung',
    $$SELECT kern.storno_anlegen('test:frank', (SELECT id FROM t WHERE name = 'b1'))$$, 'KM003');
SELECT kern.vorbereiten('test:frank', (SELECT id FROM t WHERE name = 's1'));
SELECT * FROM kern.festschreiben('test:frank', ARRAY[(SELECT id FROM t WHERE name = 's1')]);
SELECT test.ok('Storno festgeschrieben mit eigener Nummer',
    (SELECT nr FROM kern.buchung WHERE id = (SELECT id FROM t WHERE name = 's1')) = '2026-00002');
SELECT test.fehler('Festgeschriebene Buchung nicht änderbar (F30)',
    $$SELECT kern.entwurf_aendern('test:frank', (SELECT id FROM t WHERE name = 'b1'), '{}')$$, 'KM003');

SELECT kern.beleg_nachreichen('test:frank', (SELECT id FROM t WHERE name = 'b1'), 'https://paperless.example.org/documents/102/',
       'fedcba9876543210fedcba9876543210', repeat('b', 64));
SELECT test.fehler('Derselbe Beleg nicht zweimal an einer Buchung',
    $$SELECT kern.beleg_nachreichen('test:frank', (SELECT id FROM t WHERE name = 'b1'), 'https://paperless.example.org/documents/102/', repeat('a', 32), repeat('b', 64))$$, 'KM003');
SELECT test.fehler('Die Dokument-ID allein ist kein Belegverweis mehr (F88)',
    $$SELECT kern.entwurf_anlegen('test:azubi', '{"belege": [{"paperless_id": 5, "md5": "0123456789abcdef0123456789abcdef", "sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}]}')$$, 'KM002');
SELECT kern.beleg_nachreichen('test:frank', (SELECT id FROM t WHERE name = 'b1'), 'https://paperless.example.org/documents/103/', NULL, NULL);
SELECT test.ok('Im Testbetrieb darf ein Verweis ungeprüft sein: nur der Link (F88)',
    (SELECT kern.buchung_belege(id) @> '[{"link": "https://paperless.example.org/documents/103/", "md5": null, "art": "nachgereicht"}]' FROM t WHERE name = 'b1'));
SELECT test.ok('Nachgereichter Beleg verändert die Kette nicht (F27)', NOT EXISTS (SELECT 1 FROM kern.kette_pruefen()));

-- ---------------------------------------------------------------------------
-- Geschäftsjahr sperren (F33)
-- ---------------------------------------------------------------------------
INSERT INTO t SELECT 'b4', kern.entwurf_anlegen('test:azubi', '{
    "buchungsart": "BANK", "belegdatum": "2026-12-30", "buchungsdatum": "2026-12-30", "text": "Dezember",
    "zeilen": [ {"konto": "1000", "seite": "S", "betrag": "10.00"}, {"konto": "8400", "seite": "H", "betrag": "10.00"} ]
}'::jsonb), NULL;
SELECT kern.vorbereiten('test:frank', (SELECT id FROM t WHERE name = 'b4'));
SELECT test.fehler('Sperre verweigert bei freigabebereiten Buchungen',
    $$SELECT kern.geschaeftsjahr_sperren('test:frank', '2026')$$, 'KM003');
SELECT kern.zuruecksetzen('test:frank', (SELECT id FROM t WHERE name = 'b4'));
SELECT kern.geschaeftsjahr_sperren('test:frank', '2026');
SELECT kern.vorbereiten('test:frank', (SELECT id FROM t WHERE name = 'b4'));
SELECT test.fehler('Keine Festschreibung ins gesperrte Jahr (F33)',
    $$SELECT * FROM kern.festschreiben('test:frank', ARRAY[(SELECT id FROM t WHERE name = 'b4')])$$, 'KM003');
SELECT test.fehler('Wiederöffnen nur mit persönlicher Freigabe, auch im Testbetrieb (F36)',
    $$SELECT kern.geschaeftsjahr_oeffnen('test:frank', '2026', 'Fehlbuchung im Dezember entdeckt', NULL)$$, 'KM003');

-- ---------------------------------------------------------------------------
-- Rechte und Manipulation
-- ---------------------------------------------------------------------------
SELECT test.fehler('Anwendung darf interne Hilfsfunktionen nicht aufrufen',
    $$SELECT kern._protokoll('x.y', 'x', '{}')$$, '42501');
SELECT test.fehler('Produktivbetrieb nur mit persönlicher Freigabe (F59)',
    $$SELECT kern.produktiv_einschalten('test:frank', NULL)$$, 'KM003');
RESET ROLE;

-- Ein Superuser schaltet den Schutz ab und ändert einen Betrag:
ALTER TABLE kern.buchungszeile DISABLE TRIGGER schutz;
UPDATE kern.buchungszeile SET betrag = 2000.00
 WHERE buchung = (SELECT id FROM t WHERE name = 'b3') AND seite = 'S';
UPDATE kern.buchungszeile SET betrag = 2000.00
 WHERE buchung = (SELECT id FROM t WHERE name = 'b3') AND seite = 'H';
ALTER TABLE kern.buchungszeile ENABLE TRIGGER schutz;
SELECT test.ok('Manipulation wird von der Kettenprüfung erkannt (F13)',
    (SELECT o_kette = 1 AND o_nr = 2 FROM kern.kette_pruefen()));
