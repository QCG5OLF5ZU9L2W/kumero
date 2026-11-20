-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Tests Kern: Siegel auf Anforderung, Signaturen und Schlüssel im Mandanten (F119, F120; SIEGEL.md);
-- festgeschrieben wird erst nach Signatur und Bestätigung (F124, Migration 0014).
-- Nur synthetische Daten; Schlüssel und Signaturen sind hier Platzhalter – die echte Prüfung tut die Anwendung.

SET ROLE kumero_app;
SELECT kern.buch_anlegen('system:test', 'Testpraxis', 'EUR');
RESET ROLE;
SET ROLE kumero_owner;
SELECT kern.buch_einrichten('system:test', 'haupt', 'euer', false);
RESET ROLE;
SET ROLE kumero_app;
CREATE TEMP TABLE t (name text PRIMARY KEY, wert text);
GRANT ALL ON t TO PUBLIC;

-- Anfordern: vorläufig – kein Siegel, kein Protokoll (F124)
INSERT INTO t SELECT 'k2', letzte_nr::text || ':' || letzter_hash FROM kern.kettenkopf WHERE kette = 2;
INSERT INTO t SELECT 's1', kern.siegel_anfordern('nutzer:test', 'praxis', 'haupt');
SELECT test.ok('Anfordern schreibt kein Siegel und kein Protokoll',
    (SELECT count(*) = 0 FROM kern.siegel) AND (SELECT count(*) = 0 FROM kern.protokoll WHERE typ LIKE 'siegel.%')
    AND (SELECT letzte_nr::text || ':' || letzter_hash = (SELECT wert FROM t WHERE name = 'k2') FROM kern.kettenkopf WHERE kette = 2));
SELECT test.ok('Die Anforderung hält beide Kettenköpfe fest und gilt 24 Stunden',
    (SELECT nr = 1 AND buchungen_nr = 0 AND buchungen_hash = repeat('0', 64) AND schluessel IS NULL
            AND protokoll_nr::text || ':' || protokoll_hash = (SELECT wert FROM t WHERE name = 'k2')
            AND gueltig_bis = angefordert_am + interval '24 hours'
       FROM kern.siegel_anforderung));
SELECT test.ok('Siegelinhalt ist kanonisch und nennt Mandant, Nummer und Köpfe',
    (SELECT inhalt = kern.jcs(inhalt::jsonb) AND inhalt_sha256 = kern.sha256_hex(inhalt)
            AND inhalt::jsonb->>'art' = 'kumero-siegel' AND inhalt::jsonb->>'mandant' = 'praxis'
            AND (inhalt::jsonb->>'nr')::int = 1 AND inhalt::jsonb->'protokoll'->>'hash' = protokoll_hash
            AND inhalt::jsonb->'vorgaenger' = 'null'::jsonb
       FROM kern.siegel_anforderung));
SELECT test.fehler('Höchstens eine offene Anforderung',
    $$SELECT kern.siegel_anfordern('nutzer:test', 'praxis', 'haupt')$$, 'KM003');
SELECT test.fehler('Kennung des Mandanten ist Pflicht',
    $$SELECT kern.siegel_anfordern('nutzer:test', NULL, 'haupt')$$, 'KM002');
SELECT test.fehler('Ohne Signatur wird nichts festgeschrieben',
    $$SELECT kern.siegel_festschreiben('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's1'))$$, 'KM003');

-- Verwerfen: spurlos
SELECT kern.siegel_anforderung_verwerfen('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's1'));
SELECT test.ok('Verworfen: keine Anforderung, kein Siegel, kein Protokoll',
    (SELECT count(*) = 0 FROM kern.siegel_anforderung) AND (SELECT count(*) = 0 FROM kern.siegel)
    AND (SELECT count(*) = 0 FROM kern.protokoll WHERE typ LIKE 'siegel.%'));
SELECT test.fehler('Verwerfen nur einmal',
    $$SELECT kern.siegel_anforderung_verwerfen('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's1'))$$, 'KM003');
UPDATE t SET wert = kern.siegel_anfordern('nutzer:test', 'praxis', 'haupt') WHERE name = 's1';
SELECT test.ok('Nach dem Verwerfen wieder Nr. 1', (SELECT nr = 1 FROM kern.siegel_anforderung));

-- Schlüssel
SELECT kern.siegelschluessel_anmelden('nutzer:test', 'pgp', repeat('A', 40), ' Erika Muster <erika@example.org> ', 'ed25519',
    '-----BEGIN PGP PUBLIC KEY BLOCK-----' || E'\n\nPLATZHALTER\n-----END PGP PUBLIC KEY BLOCK-----');
SELECT test.ok('Schlüssel angemeldet, Kennung bereinigt',
    (SELECT kennung = 'Erika Muster <erika@example.org>' AND zurueckgezogen_am IS NULL FROM kern.siegelschluessel));
SELECT test.ok('Im Protokoll steht der Fingerabdruck, nicht die Kennung',
    (SELECT ids->>'schluessel' = repeat('A', 40) AND (nachher::text) NOT LIKE '%Erika%' FROM kern.protokoll WHERE typ = 'siegel.schluessel_angemeldet'));
SELECT test.fehler('Schlüssel nur einmal',
    $$SELECT kern.siegelschluessel_anmelden('nutzer:test', 'pgp', repeat('A', 40), 'x', 'ed25519', '-----BEGIN PGP PUBLIC KEY BLOCK-----x')$$, 'KM003');
SELECT test.fehler('Privater Schlüssel wird abgewiesen',
    $$SELECT kern.siegelschluessel_anmelden('nutzer:test', 'pgp', repeat('B', 40), 'x', 'ed25519', '-----BEGIN PGP PRIVATE KEY BLOCK-----x')$$, 'KM002');
SELECT test.fehler('Fingerabdruck hat eine Form',
    $$SELECT kern.siegelschluessel_anmelden('nutzer:test', 'pgp', 'abc', 'x', 'ed25519', '-----BEGIN PGP PUBLIC KEY BLOCK-----x')$$, '23514');

-- Signatur an der Anforderung: vorläufig, ersetzbar
SELECT test.fehler('Unbekannter Schlüssel signiert nicht',
    $$SELECT kern.siegel_anforderung_signatur('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's1'), repeat('C', 40), 'pgp', '-----BEGIN PGP SIGNATURE-----x', now())$$, 'KM003');
SELECT kern.siegel_anforderung_signatur('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's1'), repeat('A', 40), 'pgp', '-----BEGIN PGP SIGNATURE-----w', now());
SELECT kern.siegel_anforderung_signatur('nutzer:pruefer', (SELECT wert::uuid FROM t WHERE name = 's1'), repeat('A', 40), 'pgp', '-----BEGIN PGP SIGNATURE-----x', now());
SELECT test.ok('Geprüfte Signatur steht an der Anforderung; festgeschrieben ist nichts',
    (SELECT signatur = '-----BEGIN PGP SIGNATURE-----x' AND geprueft_von = 'nutzer:pruefer' FROM kern.siegel_anforderung)
    AND (SELECT count(*) = 0 FROM kern.siegel) AND (SELECT count(*) = 0 FROM kern.siegel_signatur)
    AND (SELECT count(*) = 0 FROM kern.protokoll WHERE typ IN ('siegel.festgeschrieben', 'siegel.signiert', 'siegel.angefordert')));
SELECT test.fehler('Die Anwendung schreibt nicht an den Funktionen vorbei',
    $$UPDATE kern.siegel_anforderung SET signatur = '-----BEGIN PGP SIGNATURE-----y'$$, '42501');

-- Festschreiben: Siegel, Signatur und Protokoll in einem Schritt
INSERT INTO t SELECT 'k2f', letzte_nr::text FROM kern.kettenkopf WHERE kette = 2;
SELECT test.ok('Festschreiben liefert die ID der Anforderung als ID des Siegels',
    kern.siegel_festschreiben('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's1')) = (SELECT wert::uuid FROM t WHERE name = 's1'));
SELECT test.ok('Siegel 1 ist festgeschrieben und signiert, die Anforderung ist weg',
    (SELECT nr = 1 AND status = 'signiert' AND id = (SELECT wert::uuid FROM t WHERE name = 's1') FROM kern.siegel)
    AND (SELECT eingereicht_von = 'nutzer:pruefer' AND schluessel = repeat('A', 40) FROM kern.siegel_signatur)
    AND (SELECT count(*) = 0 FROM kern.siegel_anforderung));
SELECT test.ok('Genau ein Ereignis; es nennt Siegel, Schlüssel und die Prüfsumme des Inhalts',
    (SELECT count(*) = 1 FROM kern.protokoll WHERE typ LIKE 'siegel.%' AND typ NOT LIKE 'siegel.schluessel%')
    AND (SELECT p.ids->>'schluessel' = repeat('A', 40) AND p.ids->>'siegel' = s.id::text AND p.nachher->>'inhalt_sha256' = s.inhalt_sha256
                AND p.nr = (SELECT wert::bigint FROM t WHERE name = 'k2f') + 1 AND p.subjekt = 'nutzer:test'
           FROM kern.protokoll p, kern.siegel s WHERE p.typ = 'siegel.festgeschrieben'));
SELECT test.fehler('Festschreiben nur einmal',
    $$SELECT kern.siegel_festschreiben('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's1'))$$, 'KM003');

-- Weitere Signatur am festgeschriebenen Siegel (m:n)
SELECT test.fehler('Je Schlüssel nur eine Signatur am Siegel',
    $$SELECT kern.siegel_signieren('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's1'), repeat('A', 40), 'pgp', '-----BEGIN PGP SIGNATURE-----y', now())$$, 'KM003');
SELECT test.fehler('Auch Signaturen schreibt die Anwendung nicht an den Funktionen vorbei',
    $$INSERT INTO kern.siegel_signatur (siegel, schluessel, art, signatur, signiert_am, eingereicht_von)
      VALUES ((SELECT wert::uuid FROM t WHERE name = 's1'), repeat('A', 40), 'pgp', '-----BEGIN PGP SIGNATURE-----x', now(), 'x')$$, '42501');
SELECT kern.siegelschluessel_anmelden('nutzer:test', 'pgp', repeat('B', 40), 'Max Muster <max@example.org>', 'rsa4096', '-----BEGIN PGP PUBLIC KEY BLOCK-----x');
SELECT kern.siegel_signieren('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's1'), repeat('B', 40), 'pgp', '-----BEGIN PGP SIGNATURE-----z', now());
SELECT test.ok('Ein Siegel trägt mehrere Signaturen', (SELECT count(*) = 2 FROM kern.siegel_signatur));

-- Zweites Siegel: Vorgänger, zurückgezogener Schlüssel, Verfall
INSERT INTO t SELECT 's2', kern.siegel_anfordern('nutzer:test', 'praxis', 'haupt');
SELECT test.ok('Anforderung 2 nennt ihren Vorgänger',
    (SELECT f.nr = 2 AND f.inhalt::jsonb->>'vorgaenger' = s.inhalt_sha256 AND f.vorgaenger = s.inhalt_sha256
       FROM kern.siegel s, kern.siegel_anforderung f WHERE s.nr = 1));
SELECT kern.siegel_anforderung_signatur('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's2'), repeat('B', 40), 'pgp', '-----BEGIN PGP SIGNATURE-----x', now());
SELECT test.fehler('Zurückziehen verlangt einen Grund',
    $$SELECT kern.siegelschluessel_zurueckziehen('nutzer:test', repeat('B', 40), ' ')$$, 'KM002');
SELECT kern.siegelschluessel_zurueckziehen('nutzer:test', repeat('B', 40), 'Schlüssel ersetzt');
SELECT test.fehler('Mit zurückgezogenem Schlüssel wird nicht festgeschrieben',
    $$SELECT kern.siegel_festschreiben('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's2'))$$, 'KM003');
SELECT test.fehler('Zurückgezogener Schlüssel signiert nicht mehr',
    $$SELECT kern.siegel_anforderung_signatur('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's2'), repeat('B', 40), 'pgp', '-----BEGIN PGP SIGNATURE-----x', now())$$, 'KM003');
SELECT test.fehler('Zurückziehen nur einmal',
    $$SELECT kern.siegelschluessel_zurueckziehen('nutzer:test', repeat('B', 40), 'noch einmal')$$, 'KM003');
SELECT test.ok('Die alte Signatur bleibt', (SELECT count(*) = 2 FROM kern.siegel_signatur));
RESET ROLE;
UPDATE kern.siegel_anforderung SET gueltig_bis = clock_timestamp() - interval '1 second';
SET ROLE kumero_app;
SELECT test.fehler('Eine verfallene Anforderung wird nicht festgeschrieben',
    $$SELECT kern.siegel_festschreiben('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's2'))$$, 'KM003');
UPDATE t SET wert = kern.siegel_anfordern('nutzer:test', 'praxis', 'haupt') WHERE name = 's2';
SELECT test.ok('Neu anfordern ersetzt die verfallene Anforderung',
    (SELECT count(*) = 1 AND bool_and(nr = 2 AND schluessel IS NULL AND id = (SELECT wert::uuid FROM t WHERE name = 's2')) FROM kern.siegel_anforderung));

-- Altbestand: vor 0014 angefordert, nie signiert – bleibt stehen, zählt mit, nimmt keine Signatur mehr an
RESET ROLE;
SELECT kern.siegel_anforderung_verwerfen('system:test', (SELECT wert::uuid FROM t WHERE name = 's2'));
INSERT INTO kern.siegel (nr, angefordert_am, angefordert_von, buchungen_nr, buchungen_hash, protokoll_nr, protokoll_hash, inhalt, inhalt_sha256)
     VALUES (2, now(), 'nutzer:alt', 0, repeat('0', 64), 1, repeat('1', 64), '{}', repeat('2', 64));
SET ROLE kumero_app;
SELECT test.fehler('Ein nie signiertes Siegel aus der Zeit vor 0014 nimmt keine Signatur mehr an',
    $$SELECT kern.siegel_signieren('nutzer:test', (SELECT id FROM kern.siegel WHERE nr = 2), repeat('A', 40), 'pgp', '-----BEGIN PGP SIGNATURE-----x', now())$$, 'KM003');
UPDATE t SET wert = kern.siegel_anfordern('nutzer:test', 'praxis', 'haupt') WHERE name = 's2';
SELECT test.ok('Die nächste Anforderung zählt dahinter weiter und nennt es als Vorgänger',
    (SELECT nr = 3 AND vorgaenger = repeat('2', 64) FROM kern.siegel_anforderung));

-- Schutz gegen den Eigentümer
RESET ROLE;
SET ROLE kumero_owner;
SELECT test.fehler('Signaturen sind unveränderlich',
    $$UPDATE kern.siegel_signatur SET signatur = '-----BEGIN PGP SIGNATURE-----anders'$$, 'KM001');
SELECT test.fehler('Signaturen werden nicht gelöscht',
    $$DELETE FROM kern.siegel_signatur$$, 'KM001');
SELECT test.fehler('Schlüssel werden nicht gelöscht',
    $$DELETE FROM kern.siegelschluessel$$, 'KM001');
SELECT test.fehler('Der öffentliche Schlüssel wird nicht ausgetauscht',
    $$UPDATE kern.siegelschluessel SET oeffentlich = '-----BEGIN PGP PUBLIC KEY BLOCK-----anders' WHERE fpr = repeat('A', 40)$$, 'KM001');
SELECT test.fehler('Siegel werden nicht gelöscht',
    $$DELETE FROM kern.siegel$$, 'KM001');
SELECT test.fehler('Kettenkopf im Siegel ist fest',
    $$UPDATE kern.siegel SET buchungen_nr = 5$$, 'KM001');
RESET ROLE;

-- Lesemodus: kein Siegel
UPDATE kern.buch SET lesemodus = true, lesemodus_grund = 'Test';
SET ROLE kumero_app;
SELECT test.fehler('Im Lesemodus wird nicht gesiegelt',
    $$SELECT kern.siegel_festschreiben('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's2'))$$, 'KM003');
SELECT test.fehler('und nichts angefordert oder verworfen',
    $$SELECT kern.siegel_anforderung_verwerfen('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's2'))$$, 'KM003');
RESET ROLE;
