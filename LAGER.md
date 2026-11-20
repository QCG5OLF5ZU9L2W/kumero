# LAGER.md – Warenwirtschaft: Lager mit Chargen und Verfallsdaten (Fassung 4: Stufen 0 bis 2 gebaut, 0.7.39)

Stand: 2026-11-20. Bezug: F31 (Module erzeugen nur Entwürfe), F44 (Module lesen den Kern nur über die Modul-API),
F90 (Arbeitsvorrat als Merkmal jedes Moduls), F106 (nichts verschiebt sich), F110 (kumero hält kein Dokument),
F126 (je Buch), F127 (Sidecar von nopaper), F129 (Anlagen), BELEGEINGANG.md Abschnitt 9, IMPORT.md Abschnitt 15.
**Status: WW1–WW24 vom Verantwortlichen am 2026-11-20 freigegeben („bau.“). Stufe 0 (kumero 0.7.37, F130) und Stufe 1
(kumero 0.7.38, F131) und Stufe 2 (kumero 0.7.39, Modul `lager` 0.2.0, F132) sind gebaut; Stufe 3 ist offen.** Das Mockup „kumero – Lager
(Mockup)“ war die Vorlage der Oberfläche; maßgeblich ist jetzt die gebaute Seite.

> **Keine Rechtsberatung.** Abschnitt 5 nennt Pflichten nach dem Stand vom November 2026, wie er öffentlich
> beschrieben ist. Der Verantwortliche prüft sie, bevor gebaut wird. Das Modul ist ein Lager, kein
> Medizinproduktebuch, kein Betäubungsmittelbuch und keine Patientenakte.

## 1. Anlass

Vorgaben des Verantwortlichen vom 2026-11-20: Die Positionen einer Rechnung sollen einer Warenwirtschaft dienen
(permanente Inventur, Beschaffung). **Chargen und Verfallsdaten müssen sein, denn im Lager liegen Medizinprodukte und
Arzneimittel.** Geprüft wurden InvenTree, Part-DB, Dolibarr und die großen ERP-Systeme; Ergebnis: Kein Fremdsystem
passt ohne zweite Buchführung oder ohne zweite Datenbankwelt. Entschieden ist die Richtung: **eigenes Modul `lager`,
im Zuschnitt der Funktionen nach Dolibarr (Lager, Chargen/Serien, Sollbestand), ohne dessen Code.**

## 2. Begriffe

| Begriff | Bedeutung |
|---|---|
| **Artikel** | was im Lager geführt wird: Bezeichnung, Einheit, Art (Medizinprodukt, Arzneimittel, Verbrauch, sonstiges), Führungsart |
| **Führungsart** | ohne Charge · mit Charge · mit Seriennummer (je Stück eine Nummer) |
| **Lieferantenartikel** | derselbe Artikel bei einem Lieferanten: dessen Artikelnummer, Packungsgröße, letzter Preis |
| **Charge** | eine Herstellungseinheit eines Artikels: Chargenbezeichnung, Verfallsdatum, Zustand (frei, gesperrt) |
| **Verfallsdatum** | bis wann die Charge verwendet werden darf; je Artikel Pflicht oder nicht |
| **Lagerort** | wo es liegt (Raum, Schrank, Kühlschrank); Orte können ineinander liegen |
| **Bewegung** | Zugang, Entnahme, Umlagerung, Inventurdifferenz, Ausbuchung (Verfall, Bruch, Rückgabe) – unveränderlich |
| **Bestand** | Summe der Bewegungen je Artikel, Charge und Lagerort; wird nie von Hand gesetzt |
| **Mindestbestand, Sollbestand** | ab wann nachbestellt wird und bis wohin |
| **Inventur** | Zählung zu einem Stichtag; die Abweichung wird eine Bewegung |
| **UDI** | einmalige Produktkennung eines Medizinprodukts: Produktkennung (UDI-DI, meist die GTIN) und Herstellungskennung (Charge, Serie, Verfall) |

## 3. Grundsatz und Abgrenzung

**Das Lager führt Mengen, das Buch führt Werte.** Eine Bewegung im Lager ist keine Buchung. Wo ein Wert ins Buch
gehört (Bestand zum Abschluss, Ausbuchung), erzeugt das Modul einen Buchungsentwurf (F31); festgeschrieben wird von
einem Menschen.

Nicht Teil des Moduls, auch später nicht:
- **Patienten.** Kein Name, keine Nummer, kein Fall. Eine Entnahme nennt Artikel, Charge, Menge, Zeit, wer entnommen
  hat und optional einen Zweck aus einer Auswahlliste des Buchs (WW14, kein freier Text). An wem eine Charge angewendet wurde,
  steht in der Patientenakte des Praxissystems (Art. 9 DSGVO; CLAUDE.md „Grenzen“).
- **Verkauf**, Angebote, Lieferscheine, Ausgangsrechnungen, Kasse.
- **Geräte.** Aktive Medizinprodukte (EKG, Defibrillator) sind Anlagegüter (ANLAGEN.md) und gehören ins
  Bestandsverzeichnis und Medizinproduktebuch der Praxis, nicht ins Lager.

## 4. Was das Modul tut

1. **Artikel führen** mit Führungsart, Verfallspflicht, Einheit, Mindest- und Sollbestand, Standard-Lagerort,
   Lagerbedingung (z. B. gekühlt) als Text, GTIN, Lieferantenartikel.
2. **Wareneingang aus dem Beleg:** Die Positionen einer Eingangsrechnung (Sidecar von nopaper, E-Rechnung) werden
   Vorschläge für Zugänge. Der Mensch ordnet jede Position einem Artikel zu (einmal je Lieferantenartikel, danach
   gemerkt), erfasst Charge und Verfall (Tastatur oder Scanner, Abschnitt 7) und bestätigt die Menge. Zugang ohne
   Beleg ist möglich (Muster, Schenkung, Anfangsbestand).
3. **Entnahme mit Vorschlag:** Vorgeschlagen wird die freie Charge mit dem frühesten Verfall (FEFO), bei gleichem
   Verfall die ältere. **Abgelaufene und gesperrte Chargen sind nicht entnehmbar** – die Schaltfläche ist gesperrt und
   nennt den Grund (F106). Wer von der vorgeschlagenen Charge abweicht, kann es; die Abweichung steht in der Bewegung.
4. **Umlagern** zwischen Lagerorten, **Ausbuchen** mit Grund (verfallen, beschädigt, zurückgegeben, vernichtet).
5. **Sperren und Rückruf:** Eine Charge wird mit Grund gesperrt und wieder freigegeben. Die Rückverfolgung zeigt zu
   einer Charge: von wem, wann, mit welchem Beleg zugegangen; wo sie liegt; wann, von wem und wohin entnommen.
6. **Verfall im Blick:** Liste „läuft ab“ (abgelaufen, in 30, 60, 90 Tagen – Schwellen je Buch), Zähler im Menü.
7. **Nachbestellen:** Artikel unter Mindestbestand stehen in einer Liste mit Vorschlag bis zum Sollbestand und dem
   letzten Lieferanten und Preis. Ausgabe als Bestellliste (Druck, CSV). kumero bestellt nicht selbst.
8. **Inventur:** Zählliste je Lagerort, gespeichert und fortsetzbar; gezählt wird je Charge. Abschließen erzeugt die
   Differenzbewegungen und sperrt die Inventur. Zwischen Zählen und Abschließen gebuchte Bewegungen werden
   berücksichtigt (gerechnet wird gegen den Bestand zum Zeitpunkt der Zählung jeder Zeile).
9. **Bestandsliste zum Stichtag** mit Menge und Wert (Bildschirm, Druck, CSV – wie AUSWERTUNGEN.md Abschnitt 4).

**Arbeitsvorrat des Moduls (F90):** offene Wareneingänge (Positionen gebuchter oder vorliegender Belege ohne Zugang),
Positionen ohne Artikelzuordnung, abgelaufene Chargen mit Bestand, Artikel unter Mindestbestand, offene Inventuren.

## 5. Pflichten rund um das Lager (Stand 11/2026, zu prüfen)

| Gegenstand | Was verlangt ist | Folge für das Modul |
|---|---|---|
| **Implantierbare Medizinprodukte** (§ 16 MPBetreibV in der Fassung von 2025) | Betreiber muss zu den in der Verordnung genannten Implantaten Patient, Datum, Typ und Chargen- oder Seriennummer so aufbewahren, dass betroffene Patienten binnen drei Werktagen zu ermitteln sind | Das Lager liefert Charge/Serie bis zur Entnahme; die Zuordnung zum Patienten bleibt im Praxissystem (Abschnitt 3). Seriennummernführung ist Stufe 1. |
| **UDI** (Art. 27 Abs. 9 MDR) | Gesundheitseinrichtungen speichern die UDI implantierbarer Produkte der Klasse III, mit denen sie beliefert wurden, vorzugsweise elektronisch; Mitgliedstaaten können das ausweiten | GTIN am Artikel, Charge/Serie/Verfall an der Charge; die volle UDI wird beim Wareneingang aus dem Code gelesen und gespeichert. |
| **Medizinproduktebuch, Bestandsverzeichnis** (§§ 13, 14 MPBetreibV 2025; früher §§ 12, 13) | für aktive und bestimmte messende Produkte: Gerätelebenslauf, Kontrollen, Einweisungen | **Nicht Teil des Lagers** – das sind Geräte, kein Verbrauchsmaterial. |
| **Betäubungsmittel** (§ 13 BtMVV) | Nachweis von Verbleib und Bestand unverzüglich nach jeder Bestandsänderung nach amtlichem Formblatt; elektronisch zulässig, dann Prüfung anhand von Ausdrucken zum Monatsende; Aufbewahrung drei Jahre ab letzter Eintragung. Eine Neufassung der BtMVV ist als Referentenentwurf in Arbeit. | **In Stufe 1 nicht im Lager geführt** (WW17): Der Nachweis nennt Patienten und hat eine eigene Form. Ein Artikel kann als Betäubungsmittel gekennzeichnet werden; dann lehnt das Lager Bewegungen ab und verweist auf das BtM-Buch. |
| **Impfstoffe, Blutprodukte** | Charge gehört in die Patientenakte bzw. den Impfausweis | Das Lager führt Charge und Verfall; die Dokumentation am Patienten bleibt im Praxissystem. |
| **Verfall** | Abgelaufene Arzneimittel und Medizinprodukte dürfen nicht angewendet werden | Sperre der Entnahme (Abschnitt 4 Nr. 3), Liste „läuft ab“. |
| **Kühlkette** | Lagerbedingungen einhalten und (je nach Produkt) nachweisen | Nur Kennzeichen am Artikel und am Lagerort; Temperaturprotokolle sind nicht Teil der Stufe 1 (WW19). |

Quellen der Recherche: MPBetreibV 2025 (gesetze-im-internet.de, §§ 13, 14, 16), Art. 27 MDR, § 13 BtMVV,
Merkblätter der Kassenärztlichen Vereinigungen und Landesbehörden. Wortlaut und Geltung prüft der Verantwortliche.

## 6. Verbindung zur Buchhaltung

1. **Zugang und Beleg:** Ein Zugang trägt den Verweis auf den Eintrag im Arbeitsvorrat und, sobald gebucht, auf die
   Buchung (IDs, kein Inhalt). Der Beleg bleibt in Paperless (F110).
2. **Aufwand oder Bestand – Einstellung des Buchs** (wie der Vorsteuerabzug, ANLAGEN.md H): 
   - **Sofort Aufwand** (üblich bei EÜR und bei Praxisbedarf): Die Rechnung wird wie bisher als Aufwand gebucht. Das
     Lager führt Mengen und einen **nachrichtlichen Wert**; es erzeugt keine Buchungen.
   - **Bestandsführung:** Zum Abschluss (oder auf Anforderung) erzeugt das Modul aus der Bestandsliste einen
     **Buchungsentwurf für die Bestandsveränderung**. Laufende Buchungen je Entnahme gibt es nicht (WW12).
3. **Bewertung:** letzter Einstandspreis oder gleitender Durchschnitt je Artikel, Einstellung des Buchs; Preis netto
   oder brutto folgt dem Vorsteuerabzug des Buchs. Abwertung wegen Verfall ist die Ausbuchung.
4. **Anlagen:** Eine Position über der GWG-Grenze ist eher ein Anlagegut als Lagerware. Beim Wareneingang steht dann
   der Hinweis „als Anlagegut anlegen?“ mit Sprung ins Modul `anlagen`; entschieden wird vom Menschen.
5. **Abstimmung:** Bei Bestandsführung zeigt die Bestandsliste den Saldo der Vorratskonten (über `/summen`) neben dem
   Lagerwert und nennt die Abweichung – wie das Anlagenverzeichnis.

## 7. Positionen aus dem Beleg

**Quelle 1, Sidecar von nopaper** (BELEGEINGANG.md Abschnitt 9): Der Sidecar ist eine Rechnung im Format CII. Der
Leser `Sidecar` des Moduls `paperless` liest heute Kopf und Steuerzeilen; er wird um die **Positionen** erweitert:
Positionsnummer, Bezeichnung, Menge, Einheit, Einzelpreis netto, Positionsbetrag netto, Steuersatz, Artikelnummer
des Verkäufers, Artikelnummer des Käufers, GTIN (globale Kennung), soweit vorhanden die Charge.
**Quelle 2, E-Rechnung** (Modul `import`): dieselben Felder aus XRechnung und ZUGFeRD – dort stehen sie fehlerfrei;
aus UBL zusätzlich das Verfallsdatum der Charge.

Regeln:
- **Positionen sind ein Vorschlag, nie ein Zugang.** Texterkennung irrt; ohne Bestätigung ändert sich kein Bestand.
- **Summenprüfung:** Ergeben die Positionsbeträge nicht den Nettobetrag des Belegs (Toleranz 1 Cent je Position),
  wird der Wareneingang als „unvollständig gelesen“ markiert; er bleibt bearbeitbar.
- **Aufbewahrung:** Positionen liegen als Beitrag am Eintrag des Arbeitsvorrats (wie die übrigen Angaben). Das Lager
  übernimmt nur, was bestätigt wurde. Positionen ohne Lagerbezug (Porto, Dienstleistung) werden als „kein
  Lagerartikel“ abgehakt und für den Lieferantenartikel gemerkt.
- **Charge und Verfall stehen selten auf der Rechnung.** Sie werden am Regal erfasst: von Hand oder mit einem
  Scanner, der wie eine Tastatur schreibt. kumero zerlegt den Code der Packung (GS1: Produktkennung, Verfall, Charge,
  Seriennummer) im Browser; es gibt keine Kamera-Funktion und keinen Dienst außerhalb (CLAUDE.md „Grenzen“).
- **Inhalte nach Art. 9:** Eine Rechnung an die Praxis nennt in der Regel keine Patienten. Positionstexte werden
  trotzdem nicht in Logs geschrieben (CLAUDE.md), und im Protokoll des Moduls stehen nur IDs, Mengen und Kennungen.

## 8. Daten und Technik

Modul `module/lager/` mit eigenem Schema `lager` in der Datenbank des Buchs (F126). Geschrieben wird nur über
SQL-Funktionen mit `kern.modul_protokoll`; Mengen stehen im Protokoll als Text (F29).

| Tabelle | Inhalt |
|---|---|
| `lager.einstellung` | Bewertung, Aufwand/Bestand, Vorratskonten, Schwellen für „läuft ab“ |
| `lager.ort` | Lagerorte mit Elternort, Kennzeichen gekühlt |
| `lager.artikel` | Stammdaten, Führungsart, Verfallspflicht, Mindest-/Sollbestand, Kennzeichen BtM, GTIN |
| `lager.lieferantenartikel` | Artikel, Lieferant (freier Name bzw. Gegenpartei aus dem Arbeitsvorrat), dessen Nummer, Packung, letzter Preis |
| `lager.charge` | Artikel, Bezeichnung oder Seriennummer, Verfall, Zustand, Sperrgrund |
| `lager.bewegung` | Art, Artikel, Charge, Ort von/nach, Menge, Preis, Zeit, Subjekt, Verweise (Eintrag, Buchung, Inventur), Grund – **nur anfügen** |
| `lager.inventur`, `lager.inventur_zeile` | Stichtag, Ort, Zustand; je Charge gezählt, Bestand zum Zählzeitpunkt |

Der Bestand ist eine Sicht über `lager.bewegung`. Berichtigt wird durch Gegenbewegung, nie durch Ändern.
Mengen sind Dezimalzahlen mit drei Nachkommastellen; Artikel mit Seriennummer haben je Stück die Menge 1.

**Rechte:** `lager.bestand.sehen`, `lager.bewegung.buchen` (Zugang, Entnahme, Umlagerung), `lager.charge.sperren`,
`lager.artikel.pflegen`, `lager.inventur.fuehren`, `lager.einstellung.pflegen`.
**Endpunkte** (im Buch, erst Vertrag, dann Code – F18): `GET /lager/bestand`, `/lager/artikel`, `/lager/chargen`,
`/lager/bewegungen`, `/lager/verfall`, `/lager/nachbestellung`, `/lager/rueckverfolgung/{charge}`;
`POST /lager/zugaenge`, `/lager/entnahmen`, `/lager/umlagerungen`, `/lager/ausbuchungen`,
`/lager/chargen/{id}/sperre`; Inventur `POST /lager/inventuren`, `PUT …/zeilen`, `POST …/abschluss`.
**Menü:** eigener Bereich „Lager“ mit Bestand, Wareneingang, Entnahme, Läuft ab, Nachbestellen, Inventur, Artikel.

## 9. Oberfläche

Wie überall (F106, F107): fester Kopf, dichte Liste, nichts verschiebt sich, gesperrte Knöpfe nennen den Grund,
Aktionen gelten für die Auswahl, Spaltenfilter (F91). Die Seiten:

1. **Bestand:** Liste je Artikel mit Menge, Mindestbestand, nächstem Verfall und Zustand in Worten (nicht nur
   Farbe). Darunter an festem Platz die Chargen des gewählten Artikels je Lagerort – nichts klappt auf. Kacheln oben:
   abgelaufen, läuft ab, unter Mindestbestand, offene Wareneingänge.
2. **Wareneingang:** je Position des Belegs eine Zeile mit Artikel, Menge, Charge, Verfall, Lagerort und Stand; ein
   Feld „Code scannen“ füllt Charge und Verfall. Summenprüfung in der Fußzeile. Fehlt etwas, ist „Zugang buchen“
   gesperrt und nennt die Position.
3. **Entnahme:** Artikel suchen oder Code scannen; die vorgeschlagene Charge ist vorgewählt, abgelaufene sind
   gesperrt und nennen den Grund. Zweck als Auswahl.
4. **Läuft ab** und **Nachbestellen** als Listen mit Ausgabe.
5. **Charge:** Kennungen, Sperre mit Grund, Rückverfolgung als Liste aller Bewegungen mit Bestand danach.
6. **Inventur:** Zählliste je Lagerort mit Differenz; „abschließen“ gesperrt, solange Zeilen offen sind.

**Im Mockup gezeichnet:** 1, 2, 3, 5 und 6 (Bestand und Entnahme bedienbar). Nicht gezeichnet: 4 sowie „Artikel und
Lagerorte“ und die Einstellungen. Das Mockup ist nicht im Browser nachgeprüft.

## 10. Entscheidungen (zur Freigabe)

| # | Frage | Vorschlag |
|---|---|---|
| WW1 | Eigenes Modul oder Fremdsystem? | **Eigenes Modul `lager`**; Funktionen nach Dolibarr, kein fremder Code. |
| WW2 | Wem gehört das Lager? | **Dem Buch** (F126) – wie Anlagen. Ein Lager für mehrere Bücher gibt es nicht. |
| WW3 | Was ist Bestand? | Summe unveränderlicher Bewegungen; Berichtigung nur durch Gegenbewegung. |
| WW4 | Führungsart | je Artikel: ohne, Charge, Seriennummer; nach der ersten Bewegung nicht mehr änderbar. |
| WW5 | Verfallsdatum | je Artikel Pflicht oder nicht; bei Arzneimitteln und Medizinprodukten ist „Pflicht“ vorbelegt. |
| WW6 | Entnahme-Vorschlag | frühester Verfall zuerst; Abweichung erlaubt und vermerkt. |
| WW7 | Abgelaufene Chargen | **nicht entnehmbar**, nur ausbuchbar. Keine Ausnahme per Recht. |
| WW8 | Zugang nach Rechnung oder Lieferschein? | **Stufe 1: aus der Rechnung** (die liegt als Beleg vor) oder von Hand. Lieferscheine als eigene Belegart später. |
| WW9 | Positionen: Vorschlag oder Zugang? | Immer Vorschlag; Bestand ändert sich erst mit der Bestätigung. |
| WW10 | Wo liegen ungebuchte Positionen? | Am Eintrag des Arbeitsvorrats als Beitrag der Quelle; das Lager speichert nur Bestätigtes. |
| WW11 | Aufwand oder Bestand? | Einstellung des Buchs; Vorgabe **sofort Aufwand**, Wert nachrichtlich. |
| WW12 | Buchungen je Entnahme? | **Nein.** Bei Bestandsführung ein Entwurf für die Bestandsveränderung zum Abschluss oder auf Anforderung. |
| WW13 | Bewertung | Einstellung des Buchs: letzter Einstandspreis (Vorgabe) oder gleitender Durchschnitt. |
| WW14 | Patientenbezug | **Keiner**, auch kein Freitextfeld, das dazu einlädt: Zweck ist eine Auswahlliste des Buchs (z. B. Sprechstunde, Labor, OP), kein freier Text. |
| WW15 | Wer hat entnommen? | Das Subjekt der Sitzung, wie im Protokoll des Kerns. Die Nutzer-ID verlässt den Mandanten nicht (F114). |
| WW16 | Scanner | Tastatur-Scanner und Zerlegen des GS1-Codes im Browser; keine Kamera, kein externer Dienst. |
| WW17 | Betäubungsmittel | Stufe 1: nicht geführt, Kennzeichen sperrt den Artikel. Ein BtM-Nachweis wäre ein eigener Entwurf. |
| WW18 | Geräte | nicht im Lager; Verweis auf `anlagen`. Medizinproduktebuch und Bestandsverzeichnis sind nicht Teil von kumero. |
| WW19 | Kühlkette | nur Kennzeichen; keine Temperaturprotokolle. |
| WW20 | Bestellen | Vorschlagsliste mit Ausgabe; keine Bestellung an Lieferanten, kein Bestellstatus in Stufe 1. |
| WW21 | Einheiten und Packungen | Lagereinheit je Artikel (Stück, Packung, ml); je Lieferantenartikel ein Umrechnungsfaktor (1 Karton = 10 Packungen). Anbruch wird nicht geführt. |
| WW22 | Negativer Bestand | **nie**; eine Entnahme über den Bestand wird abgelehnt und nennt die verfügbare Menge. |
| WW23 | Löschen | Artikel, Chargen und Orte werden stillgelegt, nicht gelöscht. Aufbewahrung wie die Buchführung des Buchs. |
| WW24 | Wo im Menü? | Eigener Bereich „Lager“ nach „Buchführung“. |

## 11. Stufen

| Stufe | Inhalt |
|---|---|
| **0** | Leser um Positionen erweitern (`paperless`/Sidecar und `import`/E-Rechnung), Positionen als Beitrag am Eintrag, Anzeige im Arbeitsvorrat. Nützt sofort auch dem Buchen. |
| **1** | Modul `lager`: Orte, Artikel, Chargen und Seriennummern, Zugang (aus Positionen und von Hand), Entnahme mit Vorschlag und Sperre, Umlagern, Ausbuchen, Sperren, Rückverfolgung, Liste „läuft ab“, Bestandsliste. |
| **2** | Mindest-/Sollbestand mit Nachbestellliste, Inventur, Scannen (GS1), Lieferantenartikel mit Umrechnung. |
| **3** | Bewertung, Bestandsführung mit Entwurf zum Abschluss und Abstimmung, Hinweis „Anlagegut?“. |
| später | Lieferscheine, Bestellungen mit Status, Etiketten, mehrere Lager über Bücher hinweg, BtM-Nachweis (eigener Entwurf). |

## 12. Tests

Nur synthetische Daten (CLAUDE.md): erfundene Artikel, Lieferanten, Chargen und GTIN mit gültiger Prüfziffer aus dem
Testbereich. Echte Rechnungen, Lieferanten und Chargen einer Praxis kommen nie in Tests, Fixtures oder Chats.
Unittests je Stufe (F121): SQL-Funktionen (kein negativer Bestand, Sperre, Verfall), Vorschlag der Charge,
GS1-Zerlegung, Summenprüfung der Positionen, Inventur mit Bewegung zwischen Zählen und Abschließen.

## 13. Offen und ungeprüft

- Abschnitt 5 ist eine Recherche, keine Prüfung; besonders der Kreis der Implantate nach § 16 MPBetreibV, die
  nationale Ausweitung der UDI-Pflicht und der Stand der BtMVV-Neufassung.
- nopaper schreibt noch keine Positionen (Abschnitt 14).
- Apothekenpflichtiger Praxisbedarf, Sprechstundenbedarf zulasten der Kassen und dessen Abgrenzung sind nicht
  betrachtet.

## 14. Festlegungen aus der Umsetzung (zu bestätigen)

**Stufe 0 (0.7.37, F130):**

| # | Festlegung |
|---|---|
| Y1 | Höchstens 500 Positionen je Beleg; was darüber liegt oder keine Bezeichnung trägt, wird ausgelassen und als Hinweis gemeldet. |
| Y2 | Die Einheit bleibt der Code des Belegs (z. B. `C62` für Stück); übersetzt wird erst in der Oberfläche des Lagers. |
| Y3 | Beleg mit negativer Summe: Positionsbeträge stehen ohne Vorzeichen (wie der Betrag des Eintrags), die Menge bleibt wie gedruckt. Eine negative Menge ist eine Rückgabe und wird nie ein Zugang. |
| Y4 | Charge wird aus CII (`BatchID`) und UBL (`LotNumberID`) gelesen, das Verfallsdatum nur aus UBL (`ExpiryDate`) – CII hat dafür kein eigenes Feld. |
| Y5 | Positionen nur, wenn der Beleg in der Buchwährung lautet. Unlesbare Zahlen entfallen je Feld; es wird nichts gerechnet oder geraten. |

**Stufe 1 (0.7.38, F131):**

| # | Festlegung |
|---|---|
| Y6 | **Abgelaufen** ist eine Charge ab dem Tag nach dem Verfallsdatum; am Verfallstag selbst ist sie noch entnehmbar. |
| Y7 | **Lagerorte sind eine flache Liste** (Name, gekühlt, stillgelegt). „Orte ineinander“ aus Abschnitt 2 ist nicht gebaut – der Name trägt die Ordnung („Behandlung 1, Schrank 2“). |
| Y8 | **Sperre und Entnahme gelten je Charge**, nicht je Lagerort. Ausbuchen und Umlagern gehen auch bei gesperrten und abgelaufenen Chargen. |
| Y9 | **Dieselbe Chargenbezeichnung hat ein Verfallsdatum:** Ein Zugang zur bekannten Charge mit anderem Verfall wird abgewiesen. |
| Y10 | **Abweichung vom Vorschlag** heißt: Es gibt eine freie Charge mit früherem Verfall. Gleicher Verfall gilt nicht als Abweichung. |
| Y11 | **Zugang abgelaufener Ware ist möglich** (Anfangsbestand, um sie auszubuchen); sie steht sofort als abgelaufen da. |
| Y12 | **Gemerkte Zuordnung:** Schlüssel ist die Kennung des Lieferanten (USt-IdNr., sonst der Name) und dessen Artikelnummer (sonst die Bezeichnung der Position). Gemerkt werden Artikel, Faktor oder „kein Lagerartikel“. Ohne Lieferant am Eintrag wird nichts gemerkt. Ohne gemerkte Zuordnung ist ein Artikel mit derselben GTIN ein Vorschlag. |
| Y13 | **Teilzugang:** Ist zu einer Position weniger zugegangen als Menge × Faktor, bleibt sie offen (z. B. zwei Chargen in einer Lieferung). |
| Y14 | **Preis je Lagereinheit** = Einzelpreis der Position ÷ Faktor, nachrichtlich an der Bewegung; gerechnet wird damit noch nichts (Stufe 3). |
| Y15 | **Zwecke der Entnahme:** Vorgabe ist die Liste „Verbrauch“; das Buch pflegt 1 bis 20 Einträge. Eine Entnahme ohne Zweck ist erlaubt. |
| Y16 | **Rollen:** Erfasser bewegt (Zugang, Entnahme, Umlagern, Ausbuchen); Buchhalter und Verantwortlicher führen (dazu Artikel, Lagerorte, Sperren, Einstellungen); Steuerberater und Prüfer sehen. |

Vorgezogen aus Stufe 2: das Zerlegen des Codes der Packung, die gemerkte Zuordnung mit Faktor, der Mindestbestand als
Anzeige und Kachel. **In Stufe 1 nicht gebaut** (anders als in Abschnitt 4 und 8 beschrieben): Nachbestellliste mit
Sollbestand und Inventur (mit Stufe 2 gebaut), Bewertung und Einstellungen dazu, Bestandsliste zu einem früheren Stichtag, CSV, Zähler im Menü
(Arbeitsvorrat des Moduls, F90), eigene Seiten „Entnahme“, „Läuft ab“ und „Charge“ – das sind Dialoge und Filter der
Seite „Bestand“. Die Endpunkte heißen wie gebaut: `GET /lager`, `/lager/chargen/{id}`, `/lager/wareneingang`,
`/lager/wareneingang/{eintrag}`; `POST /lager/zugaenge`, `/lager/zuordnungen`, `/lager/entnahmen`, `/lager/umlagerungen`,
`/lager/ausbuchungen`, `/lager/chargen/{id}/sperre`, `/lager/artikel`, `/lager/orte`; `PUT /lager/artikel/{id}`,
`/lager/orte/{id}`, `/lager/einstellung`.

**Stufe 2 (0.7.39, F132):**

| # | Festlegung |
|---|---|
| Y17 | **Nachbestellt wird nach dem verfügbaren Bestand:** Abgelaufene und gesperrte Chargen zählen nicht mit; die Liste nennt, wie viel davon noch daliegt. |
| Y18 | **Vorschlag** = Sollbestand − verfügbar; ohne Sollbestand bis zum Mindestbestand. Bestelleinheiten = Vorschlag ÷ Faktor der zuletzt gemerkten Zuordnung, aufgerundet. Artikel ohne Mindestbestand stehen nie auf der Liste. |
| Y19 | **Letzter Lieferant und Preis** kommen vom letzten Zugang aus einem Beleg; Zugänge ohne Beleg tragen keinen Lieferanten. |
| Y20 | **Eine offene Inventur je Lagerort;** eine Inventur über alle Lagerorte schließt jede andere offene aus. Während der Inventur wird weiter bewegt. |
| Y21 | **Stichtag der Inventur** ist der Tag des Anlegens. Die Zählliste nennt Chargen mit Bestand; was sonst daliegt, kommt über „Zeile hinzufügen“ dazu – die Charge muss bekannt sein. |
| Y22 | **Abschluss:** Ist seit der Zählung so viel entnommen worden, dass eine negative Differenz nicht mehr abzubuchen ist, wird der Abschluss abgewiesen und die Zeile ist neu zu zählen. |
| Y23 | **Recht** `lager.inventur.fuehren`: Buchhalter und Verantwortlicher (Baustein „Lager führen“); der Baustein „Lager zählen“ ist keiner Rolle vorbelegt. |

Die Zählliste zeigt den Bestand (keine verdeckte Zählung). Nicht gebaut: Nachbestellliste als Bestellung an den
Lieferanten, Bestellstatus, Zähler im Menü.

**Festgestellt beim Bauen:** nopaper liest nach seinem eigenen Stand keine Positionen (dort E13: „keine Rechnungspositionen
…, denn dort stünden die Art.-9-Inhalte“; Profil BASIC WL ohne Positionen). Der Leser in kumero ist vorbereitet und an
nachgebauten Mustern geprüft. Ob nopaper Positionen schreibt, ist dort zu entscheiden; bis dahin kommen Positionen nur
aus E-Rechnungen.
