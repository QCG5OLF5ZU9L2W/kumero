-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Tests Kern: Siegel auf Anforderung, Signaturen und Schlüssel im Mandanten (F119, F120; SIEGEL.md).
-- Nur synthetische Daten; Schlüssel und Signaturen sind hier Platzhalter – die echte Prüfung tut die Anwendung.

SET ROLE kumero_app;
SELECT kern.buch_anlegen('system:test', 'Testpraxis', 'EUR');
CREATE TEMP TABLE t (name text PRIMARY KEY, wert text);
GRANT ALL ON t TO PUBLIC;

-- Anfordern
INSERT INTO t SELECT 'k2', letzte_nr::text || ':' || letzter_hash FROM kern.kettenkopf WHERE kette = 2;
INSERT INTO t SELECT 's1', kern.siegel_anfordern('nutzer:test', 'praxis');
SELECT test.ok('Siegel 1 hält beide Kettenköpfe fest',
    (SELECT nr = 1 AND status = 'ausstehend' AND buchungen_nr = 0 AND buchungen_hash = repeat('0', 64)
            AND protokoll_nr::text || ':' || protokoll_hash = (SELECT wert FROM t WHERE name = 'k2')
       FROM kern.siegel));
SELECT test.ok('Siegelinhalt ist kanonisch und nennt Mandant, Nummer und Köpfe',
    (SELECT inhalt = kern.jcs(inhalt::jsonb) AND inhalt_sha256 = kern.sha256_hex(inhalt)
            AND inhalt::jsonb->>'art' = 'kumero-siegel' AND inhalt::jsonb->>'mandant' = 'praxis'
            AND (inhalt::jsonb->>'nr')::int = 1 AND inhalt::jsonb->'protokoll'->>'hash' = protokoll_hash
            AND inhalt::jsonb->'vorgaenger' = 'null'::jsonb
       FROM kern.siegel));
SELECT test.ok('Das Ereignis nennt die Prüfsumme des Inhalts und folgt auf den gesiegelten Kopf',
    (SELECT p.nachher->>'inhalt_sha256' = s.inhalt_sha256 AND p.nr = s.protokoll_nr + 1 AND p.vorgaenger_hash = s.protokoll_hash
       FROM kern.protokoll p, kern.siegel s WHERE p.typ = 'siegel.angefordert'));
INSERT INTO t SELECT 's2', kern.siegel_anfordern('nutzer:test', 'praxis');
SELECT test.ok('Siegel 2 nennt seinen Vorgänger',
    (SELECT b.inhalt::jsonb->>'vorgaenger' = a.inhalt_sha256 FROM kern.siegel a, kern.siegel b WHERE a.nr = 1 AND b.nr = 2));
SELECT test.fehler('Kennung des Mandanten ist Pflicht',
    $$SELECT kern.siegel_anfordern('nutzer:test', NULL)$$, 'KM002');

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

-- Signieren
SELECT test.fehler('Unbekannter Schlüssel signiert nicht',
    $$SELECT kern.siegel_signieren('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's1'), repeat('C', 40), 'pgp', '-----BEGIN PGP SIGNATURE-----x', now())$$, 'KM003');
SELECT kern.siegel_signieren('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's1'), repeat('A', 40), 'pgp', '-----BEGIN PGP SIGNATURE-----x', now());
SELECT test.ok('Siegel 1 ist signiert, Siegel 2 steht aus',
    (SELECT status = 'signiert' FROM kern.siegel WHERE nr = 1) AND (SELECT status = 'ausstehend' FROM kern.siegel WHERE nr = 2));
SELECT test.ok('Ereignis siegel.signiert nennt Siegel und Schlüssel',
    (SELECT ids->>'schluessel' = repeat('A', 40) AND ids->>'siegel' = (SELECT wert FROM t WHERE name = 's1') FROM kern.protokoll WHERE typ = 'siegel.signiert'));
SELECT test.fehler('Je Schlüssel nur eine Signatur am Siegel',
    $$SELECT kern.siegel_signieren('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's1'), repeat('A', 40), 'pgp', '-----BEGIN PGP SIGNATURE-----y', now())$$, 'KM003');
SELECT test.fehler('Die Anwendung schreibt nicht an den Funktionen vorbei',
    $$INSERT INTO kern.siegel_signatur (siegel, schluessel, art, signatur, signiert_am, eingereicht_von)
      VALUES ((SELECT wert::uuid FROM t WHERE name = 's2'), repeat('A', 40), 'pgp', '-----BEGIN PGP SIGNATURE-----x', now(), 'x')$$, '42501');

-- Zweiter Schlüssel am selben Siegel (m:n), dann zurückziehen
SELECT kern.siegelschluessel_anmelden('nutzer:test', 'pgp', repeat('B', 40), 'Max Muster <max@example.org>', 'rsa4096', '-----BEGIN PGP PUBLIC KEY BLOCK-----x');
SELECT kern.siegel_signieren('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's1'), repeat('B', 40), 'pgp', '-----BEGIN PGP SIGNATURE-----z', now());
SELECT test.ok('Ein Siegel trägt mehrere Signaturen', (SELECT count(*) = 2 FROM kern.siegel_signatur));
SELECT test.fehler('Zurückziehen verlangt einen Grund',
    $$SELECT kern.siegelschluessel_zurueckziehen('nutzer:test', repeat('B', 40), ' ')$$, 'KM002');
SELECT kern.siegelschluessel_zurueckziehen('nutzer:test', repeat('B', 40), 'Schlüssel ersetzt');
SELECT test.fehler('Zurückgezogener Schlüssel signiert nicht mehr',
    $$SELECT kern.siegel_signieren('nutzer:test', (SELECT wert::uuid FROM t WHERE name = 's2'), repeat('B', 40), 'pgp', '-----BEGIN PGP SIGNATURE-----x', now())$$, 'KM003');
SELECT test.fehler('Zurückziehen nur einmal',
    $$SELECT kern.siegelschluessel_zurueckziehen('nutzer:test', repeat('B', 40), 'noch einmal')$$, 'KM003');
SELECT test.ok('Die alte Signatur bleibt', (SELECT count(*) = 2 FROM kern.siegel_signatur));

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
    $$SELECT kern.siegel_anfordern('nutzer:test', 'praxis')$$, 'KM003');
RESET ROLE;
