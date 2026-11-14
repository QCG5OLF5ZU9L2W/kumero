-- SPDX-License-Identifier: AGPL-3.0-or-later
-- Tests Migration 0008: Rechte und Rollen (F41–F43)

SET ROLE kumero_app;
SELECT kern.buch_anlegen('test:admin', 'Rechte (Test)', 'EUR');
SELECT kern.rollenvorlagen_anlegen('system:cli');

SELECT test.ok('Sieben Rollenvorlagen angelegt (F42)',
    (SELECT count(*) FROM kern.rolle WHERE vorlage) = 7);
SELECT test.fehler('Vorlagen nur einmal',
    $$SELECT kern.rollenvorlagen_anlegen('system:cli')$$, 'KM003');
SELECT test.ok('Rechteänderungen stehen im Protokoll',
    (SELECT count(*) FROM kern.protokoll WHERE typ IN ('recht.angemeldet', 'baustein.gesetzt', 'rolle.geaendert')) = 25 + 10 + 7);

-- Zuweisung und effektive Rechte
SELECT kern.zuweisen('system:cli', 'nutzer:frank', 'Verantwortlicher');
SELECT kern.zuweisen('system:cli', 'nutzer:azubi', 'Erfasser');
SELECT test.ok('Verantwortlicher darf festschreiben und wiederöffnen',
    EXISTS (SELECT 1 FROM kern.rechte_des_subjekts('nutzer:frank') WHERE recht = 'kern.buchung.festschreiben')
    AND EXISTS (SELECT 1 FROM kern.rechte_des_subjekts('nutzer:frank') WHERE recht = 'kern.geschaeftsjahr.oeffnen'));
SELECT test.ok('Erfasser darf nicht festschreiben',
    NOT EXISTS (SELECT 1 FROM kern.rechte_des_subjekts('nutzer:azubi') WHERE recht = 'kern.buchung.festschreiben')
    AND EXISTS (SELECT 1 FROM kern.rechte_des_subjekts('nutzer:azubi') WHERE recht = 'kern.entwurf.anlegen'));
SELECT test.ok('Ohne Zuweisung keine Rechte',
    NOT EXISTS (SELECT 1 FROM kern.rechte_des_subjekts('nutzer:fremd')));
SELECT test.fehler('Subjekt muss die festgelegte Form haben (E4)',
    $$SELECT kern.zuweisen('system:cli', 'frank', 'Leser')$$, '23514');

-- Einschränkungen: eine Rolle mit Einschränkung, eine ohne → ohne gewinnt
SELECT kern.rolle_setzen('system:cli', 'Kasse erfassen', 'normal', 'nur Buchungsart Kasse',
    '[{"baustein":"Buchungen erfassen","einschraenkung":{"buchungsart":["KA"]}}]');
SELECT kern.zuweisen('system:cli', 'dienst:kassenbot', 'Kasse erfassen');
SELECT test.ok('Einschränkung wird geliefert',
    (SELECT einschraenkungen FROM kern.rechte_des_subjekts('dienst:kassenbot') WHERE recht = 'kern.entwurf.anlegen')
        = '[{"buchungsart": ["KA"]}]'::jsonb);
SELECT kern.zuweisen('system:cli', 'dienst:kassenbot', 'Erfasser');
SELECT test.ok('Rollen werden addiert: uneingeschränkt schlägt eingeschränkt',
    (SELECT einschraenkungen FROM kern.rechte_des_subjekts('dienst:kassenbot') WHERE recht = 'kern.entwurf.anlegen') IS NULL);

-- Zuweisung beenden
SELECT kern.zuweisung_beenden('system:cli', (SELECT id FROM kern.zuweisung WHERE subjekt = 'nutzer:azubi'));
SELECT test.ok('Beendete Zuweisung wirkt nicht mehr',
    NOT EXISTS (SELECT 1 FROM kern.rechte_des_subjekts('nutzer:azubi')));
SELECT test.fehler('Beenden nur einmal',
    $$SELECT kern.zuweisung_beenden('system:cli', (SELECT id FROM kern.zuweisung WHERE subjekt = 'nutzer:azubi'))$$, 'KM003');
SELECT test.ok('Beenden steht mit Vorher/Nachher im Protokoll',
    (SELECT count(*) FROM kern.protokoll WHERE typ = 'zuweisung.geaendert' AND vorher ? 'gueltig_bis') = 1);
RESET ROLE;

-- Invarianten (F43): werden beim Commit geprüft, daher je Fall eine eigene Transaktion
SET ROLE kumero_app;
SELECT test.fehler('Prüfer bleibt nur lesend (F43)',
    $t$DO $x$ BEGIN
        PERFORM kern.rolle_setzen('system:cli', 'Prüfer', 'pruefer', 'versuchsweise erweitert',
            '[{"baustein":"Lesen"},{"baustein":"Buchungen erfassen"}]');
        SET CONSTRAINTS ALL IMMEDIATE;
      END $x$ $t$, 'KM002');
SELECT test.fehler('Administrator sieht keine Buchungsdaten (F43)',
    $t$DO $x$ BEGIN
        PERFORM kern.rolle_setzen('system:cli', 'Administrator', 'administrator', 'versuchsweise',
            '[{"baustein":"Verwaltung"},{"baustein":"Lesen"}]');
        SET CONSTRAINTS ALL IMMEDIATE;
      END $x$ $t$, 'KM002');
SELECT test.fehler('Notfall nur für Verantwortliche (F43)',
    $t$DO $x$ BEGIN
        PERFORM kern.rolle_setzen('system:cli', 'Buchhalter', 'normal', 'versuchsweise',
            '[{"baustein":"Lesen"},{"baustein":"Notfall"}]');
        SET CONSTRAINTS ALL IMMEDIATE;
      END $x$ $t$, 'KM002');
SELECT test.fehler('Auch über den Baustein nicht umgehbar (F43)',
    $t$DO $x$ BEGIN
        PERFORM kern.baustein_setzen('system:cli', 'Verwaltung', 'versuchsweise',
            ARRAY['kern.rechte.sehen', 'kern.buchung.sehen']);
        SET CONSTRAINTS ALL IMMEDIATE;
      END $x$ $t$, 'KM002');
SELECT test.fehler('Auch nicht über die Merkmale eines Rechts (F43)',
    $t$DO $x$ BEGIN
        PERFORM kern.recht_anmelden('system:cli', 'kern.siegel.sehen', 'versuchsweise', true, false, false);
        SET CONSTRAINTS ALL IMMEDIATE;
      END $x$ $t$, 'KM002');
SELECT test.fehler('Rechte nur für registrierte Module',
    $$SELECT kern.recht_anmelden('system:cli', 'fremd.ding.tun', 'x', false, true)$$, 'KM002');
RESET ROLE;

SET ROLE kumero_app;
SELECT test.fehler('Anwendung schreibt Rollen nicht direkt (F64)',
    $$INSERT INTO kern.rolle (name) VALUES ('Hintertür')$$, '42501');
SELECT test.ok('Kette nachrechenbar', NOT EXISTS (SELECT 1 FROM kern.kette_pruefen()));
RESET ROLE;
