<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Bestand der Spielwiese (SPIELWIESE.md Abschnitt 4a, F185): Malerbetrieb Farbenfroh, Inhaberin Mara Muster, Musterstadt.
// FREI ERFUNDEN – keine echte Firma, keine echten Kunden, Lieferanten, Beträge oder Belege (F75 Nr. 9).
// Konten: EÜR-Kontenrahmen von kumero. Regelbesteuert mit 19 %; keine Leistungen an Bauunternehmer (kein § 13b UStG).
// Form: [Tag 'MM-TT', Buchungsart, Belegnummer, Text, Art, Angaben …]
//   'ein'  Brutto, Erlöskonto, Geldkonto            – Ausgangsrechnung bezahlt, 19 % Umsatzsteuer
//   'aus'  Brutto, Aufwandskonto, Geldkonto, Satz   – Eingangsrechnung bezahlt; Satz 19 oder 0
//   'um'   Betrag, Sollkonto, Habenkonto            – ohne Steuer
//   'frei' Zeilen [[Konto, 'S'|'H', Betrag], …]
//   'storno' Belegnummer der Buchung, die storniert wird
declare(strict_types=1);

return [
    'buch' => 'Malerbetrieb Farbenfroh',
    'buchungsarten' => ['BANK' => 'Bank', 'KASSE' => 'Kasse', 'ALLG' => 'Allgemein'],
    'steuerkonten' => ['7100' => '19.00', '7110' => '7.00', '7500' => '19.00', '7510' => '7.00', '7530' => '19.00'],
    'buchungen' => [
        // Eröffnung
        ['01-02', 'BANK', 'EB-1', 'Einlage der Inhaberin zur Eröffnung', 'um', '15000.00', '6200', '8200'],
        ['01-02', 'KASSE', 'EB-2', 'Wechselgeld in die Kasse', 'um', '300.00', '6100', '6200'],
        // Ausgangsrechnungen – zwölf über die Bank, drei bar
        ['01-09', 'BANK', 'AR-001', 'Familie Beispiel, Wohnzimmer und Flur gestrichen', 'ein', '1785.00', '1310', '6200'],
        ['01-14', 'BANK', 'AR-002', 'Hausverwaltung Musterhof, Treppenhaus Haus 3', 'ein', '4165.00', '1310', '6200'],
        ['01-20', 'KASSE', 'AR-003', 'Herr Probe, Heizkörper lackiert', 'ein', '297.50', '1310', '6100'],
        ['01-23', 'BANK', 'AR-004', 'Praxis Dr. Muster, Wartezimmer tapeziert', 'ein', '2261.00', '1310', '6200'],
        ['01-30', 'BANK', 'AR-005', 'Familie Vorlage, Kinderzimmer mit Wandbild', 'ein', '1130.50', '1310', '6200'],
        ['02-04', 'BANK', 'AR-006', 'Hausverwaltung Musterhof, Kellerflure Haus 1 und 2', 'ein', '2915.50', '1310', '6200'],
        ['02-10', 'BANK', 'AR-007', 'Café Beispielhaft, Gastraum gestrichen', 'ein', '3332.00', '1310', '6200'],
        ['02-13', 'KASSE', 'AR-008', 'Frau Test, Fensterbank ausgebessert', 'ein', '142.80', '1310', '6100'],
        ['02-19', 'BANK', 'AR-009', 'Familie Demo, Fassade Gartenseite', 'ein', '5593.00', '1310', '6200'],
        ['02-26', 'BANK', 'AR-010', 'Kanzlei Platzhalter, zwei Büros gestrichen', 'ein', '1963.50', '1310', '6200'],
        ['03-03', 'BANK', 'AR-011', 'Hausverwaltung Musterhof, Wohnung 4 nach Auszug', 'ein', '2499.00', '1310', '6200'],
        ['03-09', 'KASSE', 'AR-012', 'Herr Irgendwer, Gartentor gestrichen', 'ein', '214.20', '1310', '6100'],
        ['03-12', 'BANK', 'AR-013', 'Familie Beispiel, Küche tapeziert', 'ein', '1547.00', '1310', '6200'],
        ['03-19', 'BANK', 'AR-014', 'Kindergarten Musterwiese, Gruppenraum gestrichen', 'ein', '2737.00', '1310', '6200'],
        ['03-26', 'BANK', 'AR-015', 'Frau Niemand, Türen und Zargen lackiert', 'ein', '1249.50', '1310', '6200'],
        // Material
        ['01-07', 'BANK', 'ER-101', 'Farbenkontor Muster GmbH, Wandfarbe weiß 120 l', 'aus', '714.00', '2100', '6200', 19],
        ['01-12', 'BANK', 'ER-102', 'Farbenkontor Muster GmbH, Lacke und Verdünnung', 'aus', '386.75', '2100', '6200', 19],
        ['01-21', 'BANK', 'ER-103', 'Tapetenhaus Beispiel, Vliestapete 40 Rollen', 'aus', '952.00', '2100', '6200', 19],
        ['02-03', 'KASSE', 'ER-104', 'Baumarkt Muster, Abdeckvlies und Klebeband', 'aus', '83.30', '2100', '6100', 19],
        ['02-11', 'BANK', 'ER-105', 'Farbenkontor Muster GmbH, Fassadenfarbe 200 l', 'aus', '1904.00', '2100', '6200', 19],
        ['02-24', 'BANK', 'ER-106', 'Farbenkontor Muster GmbH, Grundierung und Spachtel', 'aus', '297.50', '2100', '6200', 19],
        ['03-05', 'BANK', 'ER-107', 'Tapetenhaus Beispiel, Kleister und Raufaser', 'aus', '214.20', '2100', '6200', 19],
        ['03-16', 'BANK', 'ER-108', 'Farbenkontor Muster GmbH, Wandfarbe getönt 80 l', 'aus', '618.80', '2100', '6200', 19],
        // Fremdleistung
        ['02-17', 'BANK', 'ER-201', 'Gerüstbau Probe, Gerüst Fassade Gartenseite', 'aus', '1428.00', '2200', '6200', 19],
        ['03-18', 'BANK', 'ER-202', 'Gerüstbau Probe, Rollgerüst Treppenhaus', 'aus', '357.00', '2200', '6200', 19],
        // Personal: ein Geselle, drei Monate
        ['01-29', 'BANK', 'LO-01', 'Lohn Januar, Geselle', 'um', '1890.00', '2310', '6200'],
        ['01-29', 'BANK', 'SV-01', 'Sozialversicherung Januar', 'um', '1120.00', '2320', '6200'],
        ['02-26', 'BANK', 'LO-02', 'Lohn Februar, Geselle', 'um', '1890.00', '2310', '6200'],
        ['02-26', 'BANK', 'SV-02', 'Sozialversicherung Februar', 'um', '1120.00', '2320', '6200'],
        ['03-27', 'BANK', 'LO-03', 'Lohn März, Geselle', 'um', '1890.00', '2310', '6200'],
        ['03-27', 'BANK', 'SV-03', 'Sozialversicherung März', 'um', '1120.00', '2320', '6200'],
        // Werkstatt und Lager
        ['01-05', 'BANK', 'MI-01', 'Miete Werkstatt Januar', 'um', '650.00', '2500', '6200'],
        ['02-05', 'BANK', 'MI-02', 'Miete Werkstatt Februar', 'um', '650.00', '2500', '6200'],
        ['03-05', 'BANK', 'MI-03', 'Miete Werkstatt März', 'um', '650.00', '2500', '6200'],
        // Fahrzeug
        ['01-15', 'BANK', 'KF-01', 'Leasing Transporter, erstes Quartal', 'aus', '1071.00', '4200', '6200', 19],
        ['01-16', 'BANK', 'KF-02', 'Kfz-Versicherung Transporter, Jahresbeitrag', 'um', '864.00', '4210', '6200'],
        ['01-27', 'KASSE', 'KF-03', 'Tankstelle Muster, Diesel', 'aus', '95.20', '4221', '6100', 19],
        ['02-02', 'BANK', 'KF-04', 'Kfz-Steuer Transporter', 'um', '286.00', '4210', '6200'],
        ['02-20', 'KASSE', 'KF-05', 'Tankstelle Muster, Diesel', 'aus', '108.29', '4221', '6100', 19],
        // Laufendes
        ['01-08', 'BANK', 'ER-301', 'Telefon und Internet Januar', 'aus', '59.50', '3100', '6200', 19],
        ['01-19', 'BANK', 'ER-302', 'Betriebshaftpflicht, Jahresbeitrag', 'um', '412.00', '3420', '6200'],
        ['02-06', 'BANK', 'ER-303', 'Anzeige im Musterstädter Wochenblatt', 'aus', '178.50', '3650', '6200', 19],
        ['02-18', 'BANK', 'ER-304', 'Entsorgung Farbreste und Gebinde', 'aus', '130.90', '3550', '6200', 19],
        ['03-02', 'KASSE', 'ER-305', 'Pinsel, Rollen und Kleinwerkzeug', 'aus', '71.40', '3500', '6100', 19],
        ['03-23', 'BANK', 'ER-306', 'Steuerberatung, laufende Buchführung', 'aus', '357.00', '3250', '6200', 19],
        // Anlagen
        ['01-13', 'BANK', 'ER-401', 'Farbspritzgerät (Anlagegut)', 'aus', '2142.00', '5320', '6200', 19],
        ['02-09', 'BANK', 'ER-402', 'Teleskopleiter (geringwertiges Wirtschaftsgut)', 'aus', '321.30', '2450', '6200', 19],
        // Umsatzsteuer an das Finanzamt
        ['02-10', 'BANK', 'UST-01', 'Umsatzsteuer-Vorauszahlung Januar', 'um', '512.00', '7900', '6200'],
        ['03-10', 'BANK', 'UST-02', 'Umsatzsteuer-Vorauszahlung Februar', 'um', '1436.00', '7900', '6200'],
        // Besonderes
        ['02-12', 'KASSE', 'ER-501', 'Bewirtung Hausverwaltung Musterhof, Gasthaus Beispiel', 'frei',
            [['4110', 'S', '58.82'], ['4115', 'S', '25.21'], ['7100', 'S', '15.97'], ['6100', 'H', '100.00']]],
        ['03-11', 'KASSE', 'ER-502', 'Blumen zur Praxiseröffnung Dr. Muster (Geschenk)', 'frei',
            [['4100', 'S', '32.71'], ['7110', 'S', '2.29'], ['6100', 'H', '35.00']]],
        ['01-31', 'BANK', 'PR-01', 'Privatentnahme Januar', 'um', '1800.00', '8100', '6200'],
        ['02-27', 'BANK', 'PR-02', 'Privatentnahme Februar', 'um', '1800.00', '8100', '6200'],
        ['03-13', 'KASSE', 'KF-06', 'Tankstelle Muster, Diesel – Zahlendreher', 'aus', '19.24', '4221', '6100', 19],
        ['03-13', 'KASSE', 'KF-06', 'Storno: Zahlendreher im Betrag', 'storno', 'KF-06'],
        ['03-13', 'KASSE', 'KF-06a', 'Tankstelle Muster, Diesel', 'aus', '91.24', '4221', '6100', 19],
    ],
];
