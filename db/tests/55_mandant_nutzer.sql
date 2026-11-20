-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Tests Mandanten-Datenbank: Nutzer im Mandanten (F114; VERWALTUNG.md Abschnitt 2, 3, 6 und 11; seit BUECHER.md im
-- Schema `mandant`). Nur synthetische Daten.

SET ROLE kumero_app;
CREATE TEMP TABLE t (name text PRIMARY KEY, wert text);
GRANT ALL ON t TO PUBLIC;

-- Anlegen: Stammdaten im Protokoll, das Pseudonym nicht
INSERT INTO t SELECT 'erika', mandant.nutzer_anlegen('system:cli', 'Muster', ' Erika ', 'Erika@Example.org', NULL, repeat('E', 43));
SELECT test.ok('Nutzer angelegt, Angaben bereinigt',
    (SELECT vornamen = 'Erika' AND familienname = 'Muster' AND email = 'erika@example.org' AND aktiv AND sprache IS NULL
       FROM mandant.nutzer WHERE id = (SELECT wert::uuid FROM t WHERE name = 'erika')));
SELECT test.ok('Protokoll nennt den Nutzer mit Stammdaten',
    (SELECT nachher->>'familienname' = 'Muster' AND ids->>'nutzer' = (SELECT wert FROM t WHERE name = 'erika')
       FROM mandant.protokoll WHERE typ = 'nutzer.angelegt'));
SELECT test.ok('Das Pseudonym steht nicht im Protokoll',
    NOT EXISTS (SELECT 1 FROM mandant.protokoll WHERE (ids::text || coalesce(vorher::text, '') || coalesce(nachher::text, '')) LIKE '%' || repeat('E', 43) || '%'));
SELECT test.fehler('Pseudonym ist eindeutig',
    $$SELECT mandant.nutzer_anlegen('system:cli', 'Zweit', 'Zora', 'zora@example.org', NULL, repeat('E', 43))$$, '23505');
SELECT test.fehler('E-Mail-Adresse hat eine Form',
    $$SELECT mandant.nutzer_anlegen('system:cli', 'Zweit', 'Zora', 'keine adresse', NULL, repeat('Z', 43))$$, '23514');
SELECT test.fehler('Familienname ist Pflicht',
    $$SELECT mandant.nutzer_anlegen('system:cli', '  ', 'Zora', 'zora@example.org', NULL, repeat('Z', 43))$$, '23514');

-- Zugang (Link) und Passkey
SELECT mandant.registrierung_ausstellen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'erika'), repeat('1', 64));
SELECT test.fehler('Zugänge gelten höchstens 24 Stunden (V13)',
    $$SELECT mandant.registrierung_ausstellen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'erika'), repeat('2', 64), interval '25 hours')$$, 'KM002');
SELECT mandant.anmelde_credential_registrieren('system:registrierung', (SELECT wert::uuid FROM t WHERE name = 'erika'),
    'Y3JlZGVudGlhbC1lcmlrYS0wMDE', '\x01020304', -7, 0, 'Schlüssel', repeat('1', 64));
SELECT test.fehler('Link nur einmal verwendbar',
    $$SELECT mandant.anmelde_credential_registrieren('system:registrierung', (SELECT wert::uuid FROM t WHERE name = 'erika'),
        'Y3JlZGVudGlhbC1lcmlrYS0wMDI', '\x05', -7, 0, 'zweiter', repeat('1', 64))$$, 'KM003');
SELECT test.fehler('Fremde registrieren keinen Passkey für jemand anderen',
    $$SELECT mandant.anmelde_credential_registrieren('nutzer:00000000-0000-0000-0000-000000000000',
        (SELECT wert::uuid FROM t WHERE name = 'erika'), 'Y3JlZGVudGlhbC1lcmlrYS0wMDI', '\x05', -7, 0, 'x')$$, 'KM003');
SELECT mandant.anmelde_credential_registrieren('nutzer:' || (SELECT wert FROM t WHERE name = 'erika'),
    (SELECT wert::uuid FROM t WHERE name = 'erika'), 'Y3JlZGVudGlhbC1lcmlrYS0wMDI', '\x05', -8, 0, 'zweiter');
SELECT test.fehler('Nur ES256 und EdDSA (E3)',
    $$SELECT mandant.anmelde_credential_registrieren('nutzer:' || (SELECT wert FROM t WHERE name = 'erika'),
        (SELECT wert::uuid FROM t WHERE name = 'erika'), 'Y3JlZGVudGlhbC1lcmlrYS0wMDM', '\x05', -257, 0, 'RSA')$$, '23514');
SELECT test.ok('Protokoll nennt nur die Prüfsumme des Schlüssels',
    (SELECT count(*) = 2 AND bool_and(nachher ? 'public_key_sha256' AND NOT nachher ? 'public_key')
       FROM mandant.protokoll WHERE typ = 'nutzer.passkey_registriert'));

-- Anmeldung und Sitzung
SELECT test.ok('Anmeldung liefert den Nutzer',
    mandant.anmeldung_vermerken('Y3JlZGVudGlhbC1lcmlrYS0wMDE', 5)::text = (SELECT wert FROM t WHERE name = 'erika'));
SELECT test.ok('… und vermerkt den Zeitpunkt',
    (SELECT letzte_anmeldung IS NOT NULL FROM mandant.nutzer WHERE id = (SELECT wert::uuid FROM t WHERE name = 'erika')));
SELECT test.fehler('Zähler muss steigen (geklonter Passkey, F68)',
    $$SELECT mandant.anmeldung_vermerken('Y3JlZGVudGlhbC1lcmlrYS0wMDE', 5)$$, 'KM003');
SELECT mandant.sitzung_anlegen((SELECT wert::uuid FROM t WHERE name = 'erika'), repeat('5', 64));
SELECT test.ok('Gültige Sitzung liefert das Subjekt',
    mandant.sitzung_pruefen(repeat('5', 64)) = 'nutzer:' || (SELECT wert FROM t WHERE name = 'erika'));
SELECT test.ok('Leerlauf überschritten → keine Sitzung', mandant.sitzung_pruefen(repeat('5', 64), interval '-1 second') IS NULL);
SELECT mandant.sitzung_beenden(repeat('5', 64));
SELECT test.ok('Beendete Sitzung gilt nicht mehr', mandant.sitzung_pruefen(repeat('5', 64)) IS NULL);
SELECT test.ok('Anmeldung und Sitzung stehen nicht in der Protokollkette',
    NOT EXISTS (SELECT 1 FROM mandant.protokoll WHERE typ LIKE 'sitzung.%' OR typ LIKE 'anmeldung.%'));

-- Sprache und Einstellungen
SELECT mandant.sprache_setzen('nutzer:' || (SELECT wert FROM t WHERE name = 'erika'), (SELECT wert::uuid FROM t WHERE name = 'erika'), 'de');
SELECT mandant.einstellung_setzen('nutzer:' || (SELECT wert FROM t WHERE name = 'erika'), (SELECT wert::uuid FROM t WHERE name = 'erika'), 'mandant.kontrast', '"hoch"');
SELECT mandant.einstellung_setzen('nutzer:' || (SELECT wert FROM t WHERE name = 'erika'), (SELECT wert::uuid FROM t WHERE name = 'erika'), 'mandant.kontrast', '"hoch"');
SELECT test.ok('Einstellung gespeichert; das Protokoll nennt nur den Schlüssel, einmal',
    (SELECT wert = '"hoch"' FROM mandant.nutzer_einstellung WHERE schluessel = 'mandant.kontrast')
    AND (SELECT count(*) = 1 AND bool_and(vorher IS NULL AND nachher IS NULL) FROM mandant.protokoll WHERE typ = 'nutzer.einstellung_geaendert'));
SELECT mandant.einstellung_setzen('nutzer:' || (SELECT wert FROM t WHERE name = 'erika'), (SELECT wert::uuid FROM t WHERE name = 'erika'), 'mandant.kontrast', NULL);
SELECT test.ok('NULL löscht die Einstellung', NOT EXISTS (SELECT 1 FROM mandant.nutzer_einstellung));

-- Überführter Bestand: fehlende Angaben ergänzt der Nutzer selbst, genau einmal
INSERT INTO t SELECT 'alt', mandant.nutzer_anlegen('system:cli', 'Alt Bestand', NULL, NULL, 'de', 'ERERERERERERERERERERHg', '11111111-1111-4111-8111-111111111111');
SELECT test.ok('Überführt mit derselben ID (U6)', (SELECT wert FROM t WHERE name = 'alt') = '11111111-1111-4111-8111-111111111111');
SELECT test.fehler('Angaben ergänzt nur der Nutzer selbst',
    $$SELECT mandant.nutzer_angaben_ergaenzen('nutzer:' || (SELECT wert FROM t WHERE name = 'erika'), '11111111-1111-4111-8111-111111111111', 'Bestand', 'Alt', 'alt@example.org')$$, 'KM003');
SELECT test.fehler('Vorname und E-Mail sind anzugeben',
    $$SELECT mandant.nutzer_angaben_ergaenzen('nutzer:11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111', 'Bestand', '', 'alt@example.org')$$, 'KM002');
SELECT mandant.nutzer_angaben_ergaenzen('nutzer:11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111', 'Bestand', 'Alt', 'alt@example.org');
SELECT test.fehler('Vollständige Angaben ändert der Nutzer nicht selbst (PROFIL.md U2)',
    $$SELECT mandant.nutzer_angaben_ergaenzen('nutzer:11111111-1111-4111-8111-111111111111', '11111111-1111-4111-8111-111111111111', 'Anders', 'Alt', 'alt@example.org')$$, 'KM003');
SELECT test.fehler('Die Anwendung übernimmt keine Passkeys aus dem Bestand',
    $$SELECT mandant.anmelde_credential_uebernehmen('system:cli', '11111111-1111-4111-8111-111111111111', 'Y3JlZGVudGlhbC1hbHQtMDAwMDE', '\x05', -7, 3, 'alt', now())$$, '42501');
RESET ROLE;
SET ROLE kumero_owner;
SELECT mandant.anmelde_credential_uebernehmen('system:cli', '11111111-1111-4111-8111-111111111111', 'Y3JlZGVudGlhbC1hbHQtMDAwMDE', '\x05', -7, 3, 'alt', now() - interval '30 days');
RESET ROLE;
SET ROLE kumero_app;
SELECT test.ok('Übernommener Passkey meldet an, der Zähler läuft weiter',
    mandant.anmeldung_vermerken('Y3JlZGVudGlhbC1hbHQtMDAwMDE', 4) = '11111111-1111-4111-8111-111111111111');

-- Sperren beendet Sitzungen; gesperrte Nutzer kommen nicht hinein
SELECT mandant.sitzung_anlegen((SELECT wert::uuid FROM t WHERE name = 'erika'), repeat('7', 64));
SELECT mandant.nutzer_aendern('system:cli', (SELECT wert::uuid FROM t WHERE name = 'erika'), 'Muster', 'Erika', 'erika@example.org', false);
SELECT test.ok('Sperren beendet die Sitzungen', mandant.sitzung_pruefen(repeat('7', 64)) IS NULL);
SELECT test.fehler('Gesperrter Nutzer meldet sich nicht an',
    $$SELECT mandant.anmeldung_vermerken('Y3JlZGVudGlhbC1lcmlrYS0wMDE', 9)$$, 'KM003');
SELECT test.fehler('Kein Zugang für gesperrte Nutzer',
    $$SELECT mandant.registrierung_ausstellen('system:cli', (SELECT wert::uuid FROM t WHERE name = 'erika'), repeat('3', 64))$$, 'KM003');
SELECT mandant.anmelde_credential_widerrufen('system:cli', 'Y3JlZGVudGlhbC1lcmlrYS0wMDI');
SELECT test.fehler('Widerrufener Passkey meldet nicht an',
    $$SELECT mandant.anmeldung_vermerken('Y3JlZGVudGlhbC1lcmlrYS0wMDI', 1)$$, 'KM003');

-- Schutz
SELECT test.fehler('Anwendung schreibt nicht direkt (F64)', $$UPDATE mandant.nutzer SET aktiv = true$$, '42501');
SELECT test.fehler('… auch keine Sitzungen', $$INSERT INTO mandant.sitzung (hash, nutzer, ablauf_absolut) SELECT repeat('9', 64), id, now() + interval '1 day' FROM mandant.nutzer LIMIT 1$$, '42501');
SELECT test.ok('Geheimnisse stehen nicht im Protokoll',
    NOT EXISTS (SELECT 1 FROM mandant.protokoll WHERE (ids::text || coalesce(nachher::text, '')) ~ ('(' || repeat('1', 64) || '|' || repeat('5', 64) || ')')));
SELECT test.ok('Ketten intakt', NOT EXISTS (SELECT 1 FROM mandant.kette_pruefen()));
RESET ROLE;
SET ROLE kumero_lesen;
SELECT test.fehler('Lesezugriff sieht keine Sitzungen', $$SELECT count(*) FROM mandant.sitzung$$, '42501');
SELECT test.fehler('… keine Passkeys', $$SELECT count(*) FROM mandant.anmelde_credential$$, '42501');
SELECT test.fehler('… kein Pseudonym und keine E-Mail', $$SELECT pseudonym, email FROM mandant.nutzer$$, '42501');
SELECT test.ok('… aber wer es gibt', (SELECT count(*) FROM (SELECT id, familienname, aktiv FROM mandant.nutzer) x) = 2);
RESET ROLE;
