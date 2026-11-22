#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
# EÜR-Kontenrahmen (F184, F185, KONTENRAHMEN.md Abschnitt 12): eine Quelle für Kontenrahmen und Zuordnung je Jahr.
# Aufruf: python3 werkzeuge/euer-kontenrahmen.py module/kontenrahmen/referenz
# Pflege je Vordruck (KE9): Wortlaut, Zeile und Kennzahl in Z am neuen Vordruck prüfen, Dateinamen der Zuordnung auf das Jahr stellen.
# Nie die Nummer eines vorhandenen Kontos ändern oder ein Konto umdeuten (F74).
# Erzeugt den EÜR-Kontenrahmen und die Zuordnung 2026 aus einer Beschreibung; Wortlaut der Zeilen aus dem Vordruck 2026.
import csv, sys, re
ziel = sys.argv[1]
V, O, E = 'Vordruck', 'Vordruck, angepasst', 'eigen'
# Zeile, Kennzahl, Wortlaut, Art, Konten
Z = [
 ('12','111','Betriebseinnahmen als umsatzsteuerlicher Kleinunternehmer (nach § 19 Abs. 1 UStG) ohne Beträge laut Zeilen 18 bis 22','saldo','1100 1110'),
 ('13','119','davon nicht steuerbare Umsätze sowie Umsätze nach § 19 Abs. 2 Satz 1 Nr. 1 und 2 sowie Satz 2 UStG','davon','1110'),
 ('14','104','Betriebseinnahmen als Land- und Forstwirt, soweit die Durchschnittssatzbesteuerung nach § 24 UStG angewandt wird','saldo','1200'),
 ('15','112','Umsatzsteuerpflichtige Betriebseinnahmen (ohne solche zum ermäßigten Steuersatz von 0 %)','saldo','1300 1310 1320'),
 ('16','103','Betriebseinnahmen, die umsatzsteuerfrei (§ 4 UStG) oder nicht umsatzsteuerbar sind oder nach § 12 Abs. 3 UStG dem ermäßigten Steuersatz von 0 % unterliegen oder für die der Leistungsempfänger die Umsatzsteuer nach § 13b UStG schuldet','saldo','1400 1410 1420 1430 1440'),
 ('17','140','Vereinnahmte Umsatzsteuer sowie Umsatzsteuer auf unentgeltliche Wertabgaben','haben','7500 7510 7530'),
 ('18','141','Vom Finanzamt erstattete und ggf. verrechnete Umsatzsteuer (Die Regelung zum 10-Tageszeitraum - § 11 Abs. 1 Satz 2 EStG - ist zu beachten.)','haben','7900'),
 ('19','102','Veräußerung oder Entnahme von Anlagevermögen','saldo','1500'),
 ('20','106','Private Kfz-Nutzung','saldo','1600'),
 ('21','108','Sonstige Sach-, Nutzungs- und Leistungsentnahmen','saldo','1700'),
 ('22','','Auflösung von Rücklagen und/oder Ausgleichsposten (Übertrag aus Zeile 106)','-',''),
 ('23','159','Summe Betriebseinnahmen (Übertrag in Zeile 77)','summe',''),
 ('24','195','Betriebsausgabenpauschale für bestimmte Berufsgruppen','-',''),
 ('25','','Sachlicher Bebauungskostenrichtbetrag und Ausbaukostenrichtbeträge für Weinbaubetriebe (Übertrag aus Zeile 13 der Anlage LuF)','-',''),
 ('26','','Betriebsausgabenpauschale für Forstwirte (Übertrag aus Zeile 17 der Anlage LuF)','-',''),
 ('27','','Bildung Rücklage Tierbewertung bei Land- und Forstwirten (Übertrag aus Zeile 18 der Anlage LuF)','-',''),
 ('28','','Übertrag (Summe Zeilen 24 bis 27)','summe',''),
 ('29','100','Waren, Rohstoffe und Hilfsstoffe einschließlich der Nebenkosten','saldo','2100'),
 ('30','110','Bezogene Fremdleistungen','saldo','2200'),
 ('31','120','Ausgaben für eigenes Personal (z. B. Gehälter, Löhne und Versicherungsbeiträge)','saldo','2300 2310 2320 2330'),
 ('32','136','AfA auf Grundstücke/grundstücksgleiche Rechte (Übertrag aus Zeile 22 der Anlage AVEÜR)','saldo','2400'),
 ('33','131','AfA auf immaterielle Wirtschaftsgüter (Übertrag aus Zeile 37 der Anlage AVEÜR)','saldo','2410'),
 ('34','130','AfA auf bewegliche Wirtschaftsgüter (Übertrag aus Zeile 63 der Anlage AVEÜR)','saldo','2420'),
 ('35','134','Sonderabschreibungen nach § 7b EStG und § 7g Abs. 5 und 6 EStG (Übertrag der Summe der Zeilen 12 und 62 der Anlage AVEÜR)','saldo','2430'),
 ('36','138','Herabsetzungsbeträge nach § 7g Abs. 2 Satz 3 EStG (Erläuterungen auf gesondertem Blatt)','saldo','2440'),
 ('37','132','Aufwendungen für geringwertige Wirtschaftsgüter nach § 6 Abs. 2 EStG','saldo','2450'),
 ('38','137','Auflösung Sammelposten nach § 6 Abs. 2a EStG (Übertrag aus Zeile 82 der Anlage AVEÜR)','saldo','2460'),
 ('39','135','Restbuchwerte der ausgeschiedenen Anlagegüter (Übertrag der Summe der Beträge aus Zeilen 7, 14, 20, 26, 32, 38, 45, 53, 60, 86, 91 der Anlage AVEÜR)','saldo','2470'),
 ('40','150','Miete/Pacht für Geschäftsräume und betrieblich genutzte Grundstücke','saldo','2500'),
 ('41','152','Aufwendungen für doppelte Haushaltsführung (z. B. Miete)','saldo','2510'),
 ('42','151','Sonstige Aufwendungen für betrieblich genutzte Grundstücke (ohne Schuldzinsen und AfA)','saldo','2520 2521 2522 2530'),
 ('43','153','in Zeile 42 enthaltene Erhaltungsaufwendungen','davon','2530'),
 ('44','280','Aufwendungen für Telekommunikation (z. B. Telefon, Internet)','saldo','3100'),
 ('45','221','Übernachtungs- und Reisenebenkosten bei Geschäftsreisen des Steuerpflichtigen','saldo','3150'),
 ('46','281','Fortbildungskosten (ohne Reisekosten)','saldo','3200'),
 ('47','194','Kosten für Rechts- und Steuerberatung, Buchführung','saldo','3250'),
 ('48','222','Miete/Leasing für bewegliche Wirtschaftsgüter (ohne Kfz)','saldo','3300'),
 ('49','225','Erhaltungsaufwendungen (z. B. Instandhaltung, Wartung, Reparatur; ohne solche für Gebäude und Kfz)','saldo','3350'),
 ('50','223','Beiträge, Gebühren, Abgaben und Versicherungen (ohne solche für Gebäude und Kfz)','saldo','3400 3410 3420'),
 ('51','228','Laufende EDV-Kosten (z. B. Beratung, Wartung, Reparatur)','saldo','3450'),
 ('52','229','Arbeitsmittel (z. B. Bürobedarf, Porto, Fachliteratur)','saldo','3500 3510 3520 3530'),
 ('53','226','Kosten für Abfallbeseitigung und Entsorgung','saldo','3550'),
 ('54','227','Kosten für Verpackung und Transport','saldo','3600'),
 ('55','224','Werbekosten (z. B. Inserate, Werbespots, Plakate)','saldo','3650'),
 ('56','232','Schuldzinsen zur Finanzierung von Anschaffungs- und Herstellungskosten von Wirtschaftsgütern des Anlagevermögens (ohne häusliches Arbeitszimmer)','saldo','3700'),
 ('57','234','Übrige Schuldzinsen','saldo','3710'),
 ('58','185','Gezahlte und nach § 15 UStG abziehbare Vorsteuerbeträge','soll','7100 7110'),
 ('59','186','An das Finanzamt gezahlte und ggf. verrechnete Umsatzsteuer (Die Regelung zum 10-Tageszeitraum - § 11 Abs. 2 Satz 2 EStG - ist zu beachten.)','soll','7900'),
 ('60','','Rücklagen, stille Reserven und/oder Ausgleichsposten (Übertrag aus Zeile 103)','-',''),
 ('61','183','Übrige unbeschränkt abziehbare Betriebsausgaben (auch zurückgezahlte Hilfen/Zuschüsse aufgrund der Corona-Pandemie)','saldo','3900 3910'),
 ('62','','Übertrag (Summe Zeilen 24 bis 27 und Zeilen 29 bis 61)','summe',''),
 ('63','164','Geschenke – Spalte „nicht abziehbar“','saldo','4105'),
 ('63','174','Geschenke – Spalte „abziehbar“','saldo','4100'),
 ('64','165','Bewirtungsaufwendungen – Spalte „nicht abziehbar“','saldo','4115'),
 ('64','175','Bewirtungsaufwendungen – Spalte „abziehbar“','saldo','4110'),
 ('65','171','Verpflegungsmehraufwendungen','saldo','4120'),
 ('66','162','Aufwendungen für ein häusliches Arbeitszimmer (siehe Anleitung) – Spalte „nicht abziehbar“','saldo','4135'),
 ('66','172','Aufwendungen für ein häusliches Arbeitszimmer (siehe Anleitung) – Spalte „abziehbar“','saldo','4130'),
 ('67','163','Tagespauschale für die Tätigkeit in der häuslichen Wohnung (siehe Anleitung)','saldo','4140'),
 ('68','168','Sonstige beschränkt abziehbare Betriebsausgaben – Spalte „nicht abziehbar“','saldo','4155'),
 ('68','177','Sonstige beschränkt abziehbare Betriebsausgaben – Spalte „abziehbar“','saldo','4150'),
 ('69','144','Leasingkosten','saldo','4200'),
 ('70','145','Steuern, Versicherungen und Maut','saldo','4210'),
 ('71','146','Sonstige tatsächliche Fahrtkosten ohne AfA und Zinsen (z. B. Reparaturen, Wartungen, Treibstoff, Kosten für Flugstrecken, Kosten für öffentliche Verkehrsmittel)','saldo','4220 4221 4222'),
 ('72','147','Fahrtkosten für nicht zum Betriebsvermögen gehörende Fahrzeuge (Nutzungseinlage)','saldo','4230'),
 ('73','142','Fahrtkosten für Wege zwischen Wohnung und erster Betriebsstätte; Familienheimfahrten (pauschaliert oder tatsächlich)','-',''),
 ('74','176','Mindestens abziehbare Fahrtkosten für Wege zwischen Wohnung und erster Betriebsstätte (Entfernungspauschale); Familienheimfahrten','-',''),
 ('75','139','Nicht abziehbare Beträge (Beispiele siehe Anleitung)','-',''),
 ('76','199','Summe Betriebsausgaben (Übertrag in Zeile 78)','summe',''),
]
wort = {}
for z, kz, w, art, konten in Z:
    if art == 'saldo' and konten:
        wort.setdefault(konten.split()[0], w)
def kurz(w):
    w = re.sub(r' \((Übertrag[^)]*|siehe Anleitung|Erläuterungen auf gesondertem Blatt|Die Regelung[^)]*)\)', '', w)
    w = w.replace(' ohne Beträge laut Zeilen 18 bis 22', '')
    return w
K = []   # Nummer, Bezeichnung, Kontoart, Herkunft
def k(nr, art, bez=None, herkunft=None):
    if bez is None:
        w = wort[nr]; bez = kurz(w); herkunft = V if bez == w else O
    K.append((nr, bez, art, herkunft or E))
e, a = 'ertrag', 'aufwand'
k('1100', e); k('1110', e, 'Kleinunternehmer: nicht steuerbare Umsätze sowie Umsätze nach § 19 Abs. 2 UStG')
k('1200', e); k('1300', e); k('1310', e, 'Betriebseinnahmen 19 % Umsatzsteuer'); k('1320', e, 'Betriebseinnahmen 7 % Umsatzsteuer')
k('1400', e); k('1410', e, 'Umsatzsteuerfreie Betriebseinnahmen (§ 4 UStG)'); k('1420', e, 'Nicht umsatzsteuerbare Betriebseinnahmen')
k('1430', e, 'Betriebseinnahmen zum Steuersatz von 0 % (§ 12 Abs. 3 UStG)'); k('1440', e, 'Betriebseinnahmen, für die der Leistungsempfänger die Umsatzsteuer schuldet (§ 13b UStG)')
k('1500', e); k('1600', e); k('1700', e)
k('2100', a); k('2200', a); k('2300', a); k('2310', a, 'Löhne und Gehälter'); k('2320', a, 'Sozialversicherungsbeiträge'); k('2330', a, 'Abgeführte Lohnsteuer')
for n in ('2400','2410','2420','2430','2440','2450','2460','2470','2500','2510','2520'): k(n, a)
k('2521', a, 'Strom, Heizung, Wasser'); k('2522', a, 'Reinigung'); k('2530', a, 'Erhaltungsaufwendungen für betrieblich genutzte Grundstücke')
for n in ('3100','3150','3200','3250','3300','3350','3400'): k(n, a)
k('3410', a, 'Beiträge und Gebühren'); k('3420', a, 'Versicherungen (ohne Gebäude und Kfz)')
k('3450', a); k('3500', a); k('3510', a, 'Bürobedarf'); k('3520', a, 'Porto'); k('3530', a, 'Fachliteratur')
for n in ('3550','3600','3650','3700','3710','3900'): k(n, a)
k('3910', a, 'Kosten des Geldverkehrs')
k('4100', a, 'Geschenke – abziehbar', O); k('4105', a, 'Geschenke – nicht abziehbar', O)
k('4110', a, 'Bewirtungsaufwendungen – abziehbar', O); k('4115', a, 'Bewirtungsaufwendungen – nicht abziehbar', O)
k('4120', a)
k('4130', a, 'Aufwendungen für ein häusliches Arbeitszimmer – abziehbar', O); k('4135', a, 'Aufwendungen für ein häusliches Arbeitszimmer – nicht abziehbar', O)
k('4140', a)
k('4150', a, 'Sonstige beschränkt abziehbare Betriebsausgaben – abziehbar', O); k('4155', a, 'Sonstige beschränkt abziehbare Betriebsausgaben – nicht abziehbar', O)
k('4200', a, 'Kfz: Leasingkosten', O); k('4210', a, 'Kfz: Steuern, Versicherungen und Maut', O); k('4220', a)
k('4221', a, 'Treibstoff'); k('4222', a, 'Kfz-Reparaturen und Wartung'); k('4230', a)
A = 'Anlage AVEÜR'
for nr, bez in (('5100','Grundstücke und grundstücksgleiche Rechte'),('5150','Häusliches Arbeitszimmer'),('5200','Immaterielle Wirtschaftsgüter'),
                ('5300','Kfz'),('5310','Büroausstattung'),('5320','Andere bewegliche Wirtschaftsgüter'),('5400','Sammelposten'),('5500','Finanzanlagen')):
    k(nr, 'aktiv', bez, A)
for nr, bez in (('6100','Kasse'),('6200','Bank'),('6210','Bank 2'),('6300','Geldtransit'),('6400','Durchlaufende Posten')): k(nr, 'aktiv', bez)
k('6500', 'passiv', 'Darlehen')
k('7100', 'aktiv', 'Abziehbare Vorsteuer 19 %'); k('7110', 'aktiv', 'Abziehbare Vorsteuer 7 %')
k('7500', 'passiv', 'Umsatzsteuer 19 %'); k('7510', 'passiv', 'Umsatzsteuer 7 %'); k('7530', 'passiv', 'Umsatzsteuer auf unentgeltliche Wertabgaben 19 %')
k('7900', 'passiv', 'Umsatzsteuer-Zahllast (Finanzamt)')
k('8100', 'passiv', 'Privatentnahmen'); k('8200', 'passiv', 'Privateinlagen'); k('8900', 'passiv', 'Kapital'); k('8910', 'passiv', 'Saldenvortrag')
assert len({x[0] for x in K}) == len(K)
with open(ziel + '/euer-kontenrahmen.csv', 'w', encoding='utf-8', newline='') as f:
    w = csv.writer(f, delimiter=';', lineterminator='\n'); w.writerow(['Nummer','Bezeichnung','Kontoart','Herkunft']); w.writerows(K)
with open(ziel + '/euer-zuordnung-2026.csv', 'w', encoding='utf-8', newline='') as f:
    w = csv.writer(f, delimiter=';', lineterminator='\n'); w.writerow(['Zeile','Kennzahl','Bezeichnung im Vordruck 2026','Art','Konten']); w.writerows(Z)
print(len(K), 'Konten,', len(Z), 'Zeilen')
