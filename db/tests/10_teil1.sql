-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Tests DDL Teil 1: kanonische Form, Constraints, Schutz-Trigger, Rechte.
-- Nur synthetische Musterdaten.

-- ===========================================================================
-- 1. Kanonische Form (RFC 8785, F29)
-- ===========================================================================
SELECT test.ok('JCS sortiert Schlüssel',
    kern.jcs('{"b":1,"a":"x"}') = '{"a":"x","b":1}');
SELECT test.ok('JCS sortiert nach Code-Einheiten (B < _ < a)',
    kern.jcs('{"a":1,"_":2,"B":3}') = '{"B":3,"_":2,"a":1}');
SELECT test.ok('JCS leeres Objekt und Array',
    kern.jcs('{"o":{},"a":[]}') = '{"a":[],"o":{}}');
SELECT test.ok('JCS verschachtelt, Reihenfolge im Array bleibt',
    kern.jcs('{"z":[3,{"y":true,"x":null},"s"]}') = '{"z":[3,{"x":null,"y":true},"s"]}');
SELECT test.ok('JCS maskiert wie ECMAScript (Steuerzeichen klein-hex, Unicode roh)',
    kern.jcs(jsonb_build_object('t', E'a"b\\c\n\t' || chr(1) || 'é€/')) = '{"t":"a\"b\\c\n\t\u0001é€/"}');
SELECT test.fehler('JCS lehnt Dezimalzahl ab (Beträge als String)',
    $$SELECT kern.jcs('{"betrag":12.50}')$$, 'KM004');
SELECT test.fehler('JCS lehnt Ganzzahl ≥ 2^53 ab',
    $$SELECT kern.jcs('9007199254740992')$$, 'KM004');
SELECT test.fehler('JCS lehnt Nicht-ASCII-Schlüssel ab',
    $$SELECT kern.jcs('{"größe":1}')$$, 'KM004');
SELECT test.ok('Kettenhash = SHA-256(Vorgänger ‖ JCS)',
    kern.kettenhash(repeat('0',64), '{"b":"2","a":1}')
    = encode(sha256(convert_to(repeat('0',64) || '{"a":1,"b":"2"}', 'UTF8')), 'hex'));
SELECT test.ok('Zeitpunkt RFC 3339 in UTC',
    kern.zeitpunkt('2026-11-14 10:00:00+01') = '2026-11-14T09:00:00.000000Z');

-- ===========================================================================
-- 2. Stammdaten (als Eigentümer, Trigger aktiv)
-- ===========================================================================
SET ROLE kumero_owner;

INSERT INTO kern.buch (name, buchwaehrung) VALUES ('Musterpraxis (Test)', 'EUR');
SELECT test.fehler('Nur ein Buch je Datenbank',
    $$INSERT INTO kern.buch (eins, name, buchwaehrung) VALUES (true, 'Zweites', 'EUR')$$, '23505');
SELECT test.fehler('Buchwährung nur ISO-Format',
    $$UPDATE kern.buch SET buchwaehrung = 'eu'$$, '23514');

INSERT INTO kern.konto (nr, bezeichnung, kontoart) VALUES
    ('1000', 'Kasse', 'aktiv'),
    ('1200', 'Bank', 'aktiv'),
    ('4930', 'Bürobedarf', 'aufwand'),
    ('8400', 'Erlöse', 'ertrag');
SELECT test.fehler('Konten werden nie gelöscht',
    $$DELETE FROM kern.konto WHERE nr = '8400'$$, 'KM001');

INSERT INTO kern.nummernkreis (kuerzel, bezeichnung) VALUES ('ALLG', 'Allgemein');
INSERT INTO kern.buchungsart (kuerzel, bezeichnung, standard_nummernkreis)
    SELECT 'BANK', 'Bank', id FROM kern.nummernkreis WHERE kuerzel = 'ALLG';

INSERT INTO kern.geschaeftsjahr (kennung, zeitraum) VALUES ('2026', '[2026-01-01,2027-01-01)');
SELECT test.fehler('Geschäftsjahre überschneiden sich nicht',
    $$INSERT INTO kern.geschaeftsjahr (kennung, zeitraum) VALUES ('X', '[2026-06-01,2027-06-01)')$$, '23P01');
SELECT test.fehler('Geschäftsjahre schließen lückenlos an (F65)',
    $$INSERT INTO kern.geschaeftsjahr (kennung, zeitraum) VALUES ('2028', '[2028-01-01,2029-01-01)');
      SET CONSTRAINTS ALL IMMEDIATE$$, 'KM002');
-- Rumpfgeschäftsjahr direkt anschließend ist erlaubt
INSERT INTO kern.geschaeftsjahr (kennung, zeitraum) VALUES ('2027R', '[2027-01-01,2027-07-01)');
SELECT test.ok('Rumpfgeschäftsjahr angelegt', (SELECT count(*) FROM kern.geschaeftsjahr) = 2);

INSERT INTO kern.nummernkreis_jahr (nummernkreis_id, geschaeftsjahr_id, vorlage, zuruecksetzen, startwert, festgelegt_von)
    SELECT n.id, g.id, '{JJJJ}-{NR:6}', true, 1, 'test:frank'
      FROM kern.nummernkreis n, kern.geschaeftsjahr g WHERE n.kuerzel = 'ALLG' AND g.kennung = '2026';
SELECT test.fehler('Vorlage braucht {NR}',
    $$UPDATE kern.nummernkreis_jahr SET vorlage = '{JJJJ}-X'$$, '23514');

-- ===========================================================================
-- 3. Buchungen: Regeln und Statusübergänge
-- ===========================================================================
SELECT test.fehler('Neue Buchung nur als Entwurf',
    $$INSERT INTO kern.buchung (status, erfasst_von) VALUES ('festgeschrieben', 'test')$$, 'KM003');

CREATE TEMP TABLE ids AS SELECT gen_random_uuid() AS b1;
GRANT SELECT ON ids TO PUBLIC;

INSERT INTO kern.buchung (id, buchungsart, nummernkreis, belegdatum, buchungsdatum, text, erfasst_von)
    SELECT (SELECT b1 FROM ids), a.id, a.standard_nummernkreis, '2026-03-02', '2026-03-03', 'Druckerpapier (Muster)', 'test:azubi'
      FROM kern.buchungsart a WHERE a.kuerzel = 'BANK';
SELECT test.ok('Entwurf darf unvollständig sein (F62)',
    (SELECT status FROM kern.buchung WHERE id = (SELECT b1 FROM ids)) = 'entwurf');

INSERT INTO kern.buchungszeile (buchung, pos, konto, seite, betrag)
    SELECT (SELECT b1 FROM ids), 1, id, 'S', 23.80 FROM kern.konto WHERE nr = '4930';
INSERT INTO kern.buchungszeile (buchung, pos, konto, seite, betrag)
    SELECT (SELECT b1 FROM ids), 2, id, 'H', 23.80 FROM kern.konto WHERE nr = '1200';

SELECT test.fehler('Betrag immer positiv (F24)',
    $$INSERT INTO kern.buchungszeile (buchung, pos, betrag) SELECT b1, 9, -1 FROM ids$$, '23514');
SELECT test.fehler('Fremdwährung nur vollständig (F12)',
    $$INSERT INTO kern.buchungszeile (buchung, pos, fw_betrag) SELECT b1, 9, 10 FROM ids$$, '23514');
SELECT test.fehler('Umrechnung betrag = round(fw/kurs, 2) (F12)',
    $$INSERT INTO kern.buchungszeile (buchung, pos, betrag, fw_betrag, fw_waehrung, kurs, kurs_datum, kurs_art)
      SELECT b1, 9, 10.00, 11.00, 'USD', 1.0850000000, '2026-03-02', 'tageskurs' FROM ids$$, '23514');
-- 11.00 USD / 1.085 = 10.138… → 10.14 EUR: korrekt
INSERT INTO kern.buchungszeile (buchung, pos, betrag, fw_betrag, fw_waehrung, kurs, kurs_datum, kurs_art)
    SELECT b1, 9, 10.14, 11.00, 'USD', 1.0850000000, '2026-03-02', 'tageskurs' FROM ids;
DELETE FROM kern.buchungszeile WHERE pos = 9;
SELECT test.ok('Entwurfszeilen sind änderbar und löschbar (F31)', true);

SELECT test.fehler('Kein Sprung Entwurf → festgeschrieben (F66)',
    $$UPDATE kern.buchung SET status = 'festgeschrieben' WHERE id = (SELECT b1 FROM ids)$$, 'KM003');

-- Vorbereiten (F66): Inhalt einfrieren
UPDATE kern.buchung SET status = 'freigabebereit', vorbereitet_am = now(), vorbereitet_von = 'test:frank',
       freigabe_hash = repeat('a', 64)
 WHERE id = (SELECT b1 FROM ids);
SELECT test.fehler('Freigabebereiter Kopf ist eingefroren',
    $$UPDATE kern.buchung SET text = 'geändert' WHERE id = (SELECT b1 FROM ids)$$, 'KM001');
SELECT test.fehler('Freigabebereite Zeilen sind eingefroren',
    $$UPDATE kern.buchungszeile SET betrag = 1 WHERE pos = 1$$, 'KM001');
SELECT test.fehler('Anreichern nach Vorbereitung verboten (F66)',
    $$INSERT INTO kern.pruefvermerk (buchung, modul, modulversion, erweiterungspunkt, ergebnis)
      SELECT b1, 'kern', '0.1.0', 'vorbereiten_anreichern', true FROM ids$$, 'KM001');
INSERT INTO kern.pruefvermerk (buchung, modul, modulversion, erweiterungspunkt, ergebnis)
    SELECT b1, 'kern', '0.1.0', 'festschreiben_pruefen', true FROM ids;
SELECT test.ok('Prüfvermerk "festschreiben_pruefen" bei freigabebereit erlaubt', true);

-- Festschreiben (Teil 2 macht das per Funktion; hier von Hand als Eigentümer)
UPDATE kern.nummernkreis_jahr SET zaehler = 1;
SELECT test.fehler('Zähler nur lückenlos (+2 verboten)',
    $$UPDATE kern.nummernkreis_jahr SET zaehler = 3$$, 'KM001');
SELECT test.fehler('Festlegung nach erster Vergabe unveränderlich',
    $$UPDATE kern.nummernkreis_jahr SET vorlage = '{NR}'$$, 'KM001');
UPDATE kern.kettenkopf SET letzte_nr = 1, letzter_hash = repeat('b', 64) WHERE kette = 1;
UPDATE kern.buchung SET status = 'festgeschrieben',
       geschaeftsjahr = (SELECT id FROM kern.geschaeftsjahr WHERE kennung = '2026'),
       journal_nr = 1, nr_zahl = 1, nr = '2026-000001',
       festgeschrieben_am = now(), festgeschrieben_von = 'test:frank', testbetrieb = true,
       vorgaenger_hash = repeat('0', 64), hash = repeat('b', 64)
 WHERE id = (SELECT b1 FROM ids);
SELECT test.ok('Festgeschrieben', (SELECT status FROM kern.buchung WHERE id = (SELECT b1 FROM ids)) = 'festgeschrieben');

SELECT test.fehler('Festgeschriebene Buchung unveränderlich (F30)',
    $$UPDATE kern.buchung SET belegnummer = 'X' WHERE id = (SELECT b1 FROM ids)$$, 'KM001');
SELECT test.fehler('Festgeschriebene Buchung nicht löschbar',
    $$DELETE FROM kern.buchung WHERE id = (SELECT b1 FROM ids)$$, 'KM001');
SELECT test.fehler('Zeile einer festgeschriebenen Buchung nicht änderbar',
    $$UPDATE kern.buchungszeile SET betrag = 99 WHERE pos = 1$$, 'KM001');
SELECT test.fehler('Keine neue Zeile an festgeschriebener Buchung',
    $$INSERT INTO kern.buchungszeile (buchung, pos) SELECT b1, 5 FROM ids$$, 'KM001');
SELECT test.fehler('Kein Beleg "bei Festschreibung" nachträglich',
    $$INSERT INTO kern.belegverweis (buchung, link, md5, sha256, art, zugeordnet_von)
      SELECT b1, 'https://paperless.example.org/documents/42/', repeat('c',32), repeat('d',64), 'festschreibung', 'test' FROM ids$$, 'KM001');
INSERT INTO kern.belegverweis (buchung, link, md5, sha256, art, zugeordnet_von)
    SELECT b1, 'https://paperless.example.org/documents/42/', repeat('c',32), repeat('d',64), 'nachgereicht', 'test:frank' FROM ids;
SELECT test.ok('Beleg nachreichen erlaubt (F27)', true);
SELECT test.fehler('Nachgereichter Beleg nicht entfernbar',
    $$DELETE FROM kern.belegverweis WHERE link LIKE '%/documents/42/'$$, 'KM001');
-- Belegverweis als ganzer Link (F88)
SELECT test.fehler('Belegverweis ohne Link und ohne ID abgewiesen',
    $$INSERT INTO kern.belegverweis (buchung, md5, sha256, art, zugeordnet_von)
      SELECT b1, repeat('c',32), repeat('d',64), 'nachgereicht', 'test' FROM ids$$, '23514');
SELECT test.fehler('Link mit Zugangsdaten abgewiesen',
    $$INSERT INTO kern.belegverweis (buchung, link, md5, sha256, art, zugeordnet_von)
      SELECT b1, 'https://nutzer:geheim@paperless.example.org/documents/43/', repeat('c',32), repeat('d',64), 'nachgereicht', 'test' FROM ids$$, '23514');
SELECT test.fehler('Link mit Abfrage abgewiesen',
    $$INSERT INTO kern.belegverweis (buchung, link, md5, sha256, art, zugeordnet_von)
      SELECT b1, 'https://paperless.example.org/documents/43/?token=x', repeat('c',32), repeat('d',64), 'nachgereicht', 'test' FROM ids$$, '23514');
SELECT test.fehler('Host in Großbuchstaben abgewiesen (eine Schreibweise je Beleg)',
    $$INSERT INTO kern.belegverweis (buchung, link, md5, sha256, art, zugeordnet_von)
      SELECT b1, 'https://Paperless.Example.org/documents/43/', repeat('c',32), repeat('d',64), 'nachgereicht', 'test' FROM ids$$, '23514');
SELECT test.ok('Altbestand behält in der kanonischen Form seine Gestalt, neue Verweise tragen den Link (F88)',
    kern._beleg_json(ROW(gen_random_uuid(), gen_random_uuid(), 7, repeat('a',32), repeat('b',64), 'festschreibung', now(), 'x', NULL)::kern.belegverweis)
        = jsonb_build_object('paperless_id', 7, 'md5', repeat('a',32), 'sha256', repeat('b',64))
    AND kern._beleg_json(ROW(gen_random_uuid(), gen_random_uuid(), NULL, repeat('a',32), repeat('b',64), 'festschreibung', now(), 'x', 'https://p.example.org/documents/7/')::kern.belegverweis)
        = jsonb_build_object('link', 'https://p.example.org/documents/7/', 'md5', repeat('a',32), 'sha256', repeat('b',64)));

SELECT test.fehler('Bebuchtes Konto: Nummer unveränderlich (F7)',
    $$UPDATE kern.konto SET nr = '4931' WHERE nr = '4930'$$, 'KM001');
SELECT test.fehler('Bebuchtes Konto: Kontoart unveränderlich (F7)',
    $$UPDATE kern.konto SET kontoart = 'aktiv' WHERE nr = '4930'$$, 'KM001');
UPDATE kern.konto SET bezeichnung = 'Büromaterial' WHERE nr = '4930';
SELECT test.ok('Kontobezeichnung bleibt änderbar', true);

SELECT test.fehler('Bebuchtes Geschäftsjahr: Zeitraum fest',
    $$UPDATE kern.geschaeftsjahr SET zeitraum = '[2026-01-01,2026-12-31)' WHERE kennung = '2026'$$, 'KM001');
SELECT test.fehler('Buchwährung nach erster Festschreibung fest (F11)',
    $$UPDATE kern.buch SET buchwaehrung = 'CHF'$$, 'KM001');
SELECT test.fehler('Produktivbetrieb nur bei leerer Kette (F70)',
    $$UPDATE kern.buch SET betriebsart = 'produktiv', produktiv_seit = now()$$, 'KM003');

-- ===========================================================================
-- 4. Protokollkette (F13, F48)
-- ===========================================================================
INSERT INTO kern.protokoll (typ, subjekt, ids, nachher)
    VALUES ('konto.angelegt', 'test:frank', '{"konto":"x"}', '{"nr":"4930","bezeichnung":"Bürobedarf"}');
INSERT INTO kern.protokoll (typ, subjekt, ids, vorher, nachher)
    VALUES ('konto.geaendert', 'test:frank', '{"konto":"x"}', '{"bezeichnung":"Bürobedarf"}', '{"bezeichnung":"Büromaterial"}');
SELECT test.ok('Protokoll fortlaufend nummeriert',
    (SELECT array_agg(nr ORDER BY nr) FROM kern.protokoll) = '{1,2}');
SELECT test.ok('Protokoll verkettet und nachrechenbar',
    (SELECT bool_and(ok) FROM (
        SELECT p.hash = kern.kettenhash(p.vorgaenger_hash, kern.protokoll_inhalt(p))
               AND p.vorgaenger_hash = coalesce(lag(p.hash) OVER (ORDER BY p.nr), repeat('0',64)) AS ok
          FROM kern.protokoll p) g));
SELECT test.ok('Kettenkopf 2 zeigt auf letztes Glied',
    (SELECT letzter_hash FROM kern.kettenkopf WHERE kette = 2) = (SELECT hash FROM kern.protokoll WHERE nr = 2));
SELECT test.fehler('Protokoll ist unveränderlich',
    $$UPDATE kern.protokoll SET subjekt = 'jemand' WHERE nr = 1$$, 'KM001');
SELECT test.fehler('Protokoll ist nicht löschbar',
    $$DELETE FROM kern.protokoll WHERE nr = 2$$, 'KM001');
SELECT test.fehler('Kein TRUNCATE',
    $$TRUNCATE kern.protokoll CASCADE$$, 'KM001');
SELECT test.fehler('Protokoll verlangt kanonisierbare Inhalte (Betrag als String)',
    $$INSERT INTO kern.protokoll (typ, subjekt, nachher) VALUES ('buchung.entwurf_angelegt', 't', '{"betrag": 23.80}')$$, 'KM004');
SELECT test.fehler('Kettenkopf springt nicht',
    $$UPDATE kern.kettenkopf SET letzte_nr = letzte_nr + 5 WHERE kette = 2$$, 'KM001');
SELECT test.fehler('Kettenkopf nicht löschbar',
    $$DELETE FROM kern.kettenkopf WHERE kette = 1$$, 'KM001');

-- ===========================================================================
-- 5. Siegel, Credential, Challenge
-- ===========================================================================
-- Siegel entstehen über Funktionen (db/tests/58_siegel.sql); hier nur der Schutz der Tabelle
INSERT INTO kern.siegel (nr, angefordert_von, buchungen_nr, buchungen_hash, protokoll_nr, protokoll_hash, inhalt, inhalt_sha256)
    VALUES (1, 'system:test', 0, repeat('0', 64), 2, repeat('e', 64), '{}', repeat('a', 64));
UPDATE kern.siegel SET status = 'signiert';
SELECT test.fehler('Siegelinhalt nur einmal',
    $$UPDATE kern.siegel SET inhalt = '{"x":1}'$$, 'KM001');
SELECT test.fehler('Siegelstatus nur vorwärts',
    $$UPDATE kern.siegel SET status = 'ausstehend'$$, 'KM001');

INSERT INTO kern.challenge (zufall, ablauf, mandant, art, subjekt, inhalt_hash, wert)
    VALUES (decode(repeat('ab', 32), 'hex'), now() + interval '5 minutes', 'muster', 'festschreibung',
            'test:frank', repeat('a', 64), repeat('f', 64));
SELECT test.fehler('Challenge braucht ≥ 32 Byte Zufall (F68)',
    $$INSERT INTO kern.challenge (zufall, ablauf, mandant, art, subjekt, inhalt_hash, wert)
      VALUES ('\x01', now() + interval '5 minutes', 'm', 'festschreibung', 's', repeat('a',64), repeat('1',64))$$, '23514');
UPDATE kern.challenge SET verbraucht_am = now();
SELECT test.fehler('Challenge nur einmal verwendbar (F68)',
    $$UPDATE kern.challenge SET verbraucht_am = now()$$, 'KM003');
SELECT test.fehler('Verbrauchte Challenge bleibt erhalten',
    $$DELETE FROM kern.challenge$$, 'KM001');

INSERT INTO kern.credential (subjekt, credential_id, public_key, algorithmus, bezeichnung)
    VALUES ('test:frank', '\x0102', '\x0a0b', -7, 'YubiKey Muster');
UPDATE kern.credential SET sign_count = 5;
SELECT test.fehler('Signaturzähler sinkt nicht',
    $$UPDATE kern.credential SET sign_count = 4$$, 'KM001');
SELECT test.fehler('Öffentlicher Schlüssel unveränderlich',
    $$UPDATE kern.credential SET public_key = '\x00'$$, 'KM001');
UPDATE kern.credential SET widerrufen_am = now();
SELECT test.fehler('Widerruf endgültig',
    $$UPDATE kern.credential SET widerrufen_am = NULL$$, 'KM001');
SELECT test.fehler('Credential nicht löschbar',
    $$DELETE FROM kern.credential$$, 'KM001');

RESET ROLE;

-- ===========================================================================
-- 6. Rechte der Anwendung (F64)
-- ===========================================================================
SET ROLE kumero_app;
SELECT test.ok('Anwendung darf lesen', (SELECT count(*) FROM kern.konto) = 4);
SELECT test.ok('Anwendung darf JCS nutzen', kern.jcs('{"a":1}') = '{"a":1}');
SELECT test.fehler('Anwendung darf nicht einfügen',
    $$INSERT INTO kern.konto (nr, bezeichnung, kontoart) VALUES ('9999', 'x', 'aktiv')$$, '42501');
SELECT test.fehler('Anwendung darf nicht ändern',
    $$UPDATE kern.konto SET bezeichnung = 'x'$$, '42501');
SELECT test.fehler('Anwendung darf nicht löschen',
    $$DELETE FROM kern.buchung$$, '42501');
SELECT test.fehler('Anwendung darf nichts im Schema anlegen',
    $$CREATE TABLE kern.fremd (x int)$$, '42501');
SELECT test.fehler('Anwendung darf interne Trigger-Hilfen nicht aufrufen',
    $$SELECT kern.verweigern('x')$$, '42501');
RESET ROLE;

SET ROLE kumero_lesen;
SELECT test.fehler('Leserolle darf nicht schreiben',
    $$INSERT INTO kern.tag (name) VALUES ('x')$$, '42501');
RESET ROLE;
