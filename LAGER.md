# LAGER.md – Warenwirtschaft: Lager mit Chargen und Verfallsdaten (Fassung 7: Stufen 0 bis 3 gebaut, Rollen mit 0.7.41; Dokumente am Artikel als Entwurf, Abschnitt 15; Gegenstände für den Eigenbedarf, Abschnitt 16; Artikelgruppen gebaut mit 0.7.73, Abschnitt 17; Aufgabe der Artikelgruppe und neue Maske mit 0.7.83, Abschnitt 17.6; Dialog „Lagerorte“ mit Bestand je Ort mit 0.7.84, Abschnitt 18; Seriennummern in Artikelgruppen mit 0.7.85, Abschnitt 17.7)

Stand: 2026-11-22. Bezug: F31 (Module erzeugen nur Entwürfe), F44 (Module lesen den Kern nur über die Modul-API),
F90 (Arbeitsvorrat als Merkmal jedes Moduls), F106 (nichts verschiebt sich), F110 (kumero hält kein Dokument),
F126 (je Buch), F127 (Sidecar von nopaper), F129 (Anlagen), BELEGEINGANG.md Abschnitt 9, IMPORT.md Abschnitt 15.
**Status: WW1–WW24 vom Verantwortlichen am 2026-11-20 freigegeben („bau.“). Stufe 0 (kumero 0.7.37, F130) und Stufe 1
(kumero 0.7.38, F131), Stufe 2 (kumero 0.7.39, F132) und Stufe 3 (kumero 0.7.40, Modul `lager` 0.3.0, F133) sind gebaut.** Das Mockup „kumero – Lager
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
| WW18 | *(Soweit es Medizinproduktebuch und Bestandsverzeichnis betrifft, aufgehoben durch die Entscheidung vom 2026-11-25 – ARTIKELEIGENSCHAFTEN.md AE9; nicht gebaut.)*  Geräte | nicht im Lager; Verweis auf `anlagen`. Medizinproduktebuch und Bestandsverzeichnis sind nicht Teil von kumero. |
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
| Y16 | *(ersetzt durch die Rollen unten, F134)* **Rollen:** Erfasser bewegt (Zugang, Entnahme, Umlagern, Ausbuchen); Buchhalter und Verantwortlicher führen (dazu Artikel, Lagerorte, Sperren, Einstellungen); Steuerberater und Prüfer sehen. |

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
| Y17 | **Nachbestellt wird nach dem verfügbaren Bestand:** Abgelaufene und gesperrte Chargen zählen nicht mit; die Liste nennt, wie viel davon noch daliegt. **Dasselbe gilt für „unter Mindestbestand“ auf der Seite „Bestand“ (Kachel, Zustand, Filter) – berichtigt mit 0.7.60; vorher zählte dort der ganze Bestand.** |
| Y18 | **Vorschlag** = Sollbestand − verfügbar; ohne Sollbestand bis zum Mindestbestand. Bestelleinheiten = Vorschlag ÷ Faktor der zuletzt gemerkten Zuordnung, aufgerundet. Artikel ohne Mindestbestand stehen nie auf der Liste. |
| Y19 | **Letzter Lieferant und Preis** kommen vom letzten Zugang aus einem Beleg; Zugänge ohne Beleg tragen keinen Lieferanten. |
| Y20 | **Eine offene Inventur je Lagerort;** eine Inventur über alle Lagerorte schließt jede andere offene aus. Während der Inventur wird weiter bewegt. |
| Y21 | **Stichtag der Inventur** ist der Tag des Anlegens. Die Zählliste nennt Chargen mit Bestand; was sonst daliegt, kommt über „Zeile hinzufügen“ dazu – die Charge muss bekannt sein. |
| Y22 | **Abschluss:** Ist seit der Zählung so viel entnommen worden, dass eine negative Differenz nicht mehr abzubuchen ist, wird der Abschluss abgewiesen und die Zeile ist neu zu zählen. |
| Y23 | *(ersetzt durch die Rollen unten, F134)* **Recht** `lager.inventur.fuehren`: Buchhalter und Verantwortlicher (Baustein „Lager führen“); der Baustein „Lager zählen“ ist keiner Rolle vorbelegt. |

Die Zählliste zeigt den Bestand (keine verdeckte Zählung). Nicht gebaut: Nachbestellliste als Bestellung an den
Lieferanten, Bestellstatus, Zähler im Menü.

**Stufe 3 (0.7.40, F133):**

| # | Festlegung |
|---|---|
| Y24 | **Letzter Einstandspreis:** der ganze Bestand eines Artikels × Preis des letzten Zugangs, der einen Preis trägt – auch für Mengen, die ohne Preis kamen. |
| Y25 | **Gleitender Durchschnitt:** Zugänge mit Preis heben Menge und Wert, Abgänge gehen zum Durchschnitt heraus. Ein Zugang ohne Preis geht zum laufenden Durchschnitt ein; gibt es noch keinen, hat diese Menge keinen Wert und der Artikel ist als „ohne vollständigen Preis“ gemeldet. Bei Abgängen geht zuerst, was einen Wert hat. |
| Y26 | **Gerechnet wird in ganzen Zahlen** (Mengen in Tausendsteln, Preise in Zehntausendstel Euro), gerundet kaufmännisch erst am Ende je Artikel. Bewertet wird je Artikel, nicht je Charge. |
| Y27 | **Stichtag:** alle Bewegungen bis zum Ende des Tages; maßgeblich ist der Zeitpunkt der Buchung im Lager. Ein Abschlag für abgelaufene oder gesperrte Ware wird nicht gerechnet – Abwertung ist die Ausbuchung. |
| Y28 | **Preise netto oder brutto ist eine eigene Einstellung des Lagers** (das Modul liest die Einstellung der Anlagen nicht, F44). Brutto = Einzelpreis × (1 + Steuersatz der Position); ohne Steuersatz an der Position bleibt der Preis, wie er ist. Gebuchte Zugänge behalten ihren Preis. |
| Y29 | **Bestandsveränderung:** Entwurf über Lagerwert − Saldo der Vorratskonten zum Stichtag. Mehr im Lager: Bestandskonto im Soll, Konto der Bestandsveränderung im Haben; weniger: umgekehrt. Belegdatum und Buchungsdatum sind der Stichtag. Solange ein Entwurf zum Stichtag offen ist, ist ein zweiter gesperrt. |
| Y30 | **Abstimmung** nur, wenn der Stichtag in einem Geschäftsjahr liegt; der Saldo kommt aus der Abfrage `summen` des Kerns vom Jahresbeginn bis zum Stichtag (festgeschriebene Buchungen). |
| Y31 | **Hinweis „Anlagegut?“:** Einzelpreis der Position (netto, je Einheit des Belegs) über der GWG-Grenze aus dem Regelwerk der Anlagen, nur bei aktivem Modul `anlagen`. Der Hinweis ändert nichts am Wareneingang. |

**Vom Verantwortlichen zu prüfen:** ob diese Verfahren und die Buchung der Bestandsveränderung für den Betrieb richtig
sind – das ist Bilanzierung und keine Technik; kumero rechnet nur, was eingestellt ist.

**Rollen (0.7.41, F134) – entschieden vom Verantwortlichen am 2026-11-20 (RL1–RL6):** Das Lager war zuerst nur an die
Rollen der Buchhaltung gehängt; wer Ware entnehmen sollte, hätte auch buchen dürfen. Jetzt:

| Rolle | darf im Lager | Bausteine |
|---|---|---|
| **Lager** (neu, vom Modul mitgebracht) | sehen, Wareneingang, Entnahme, Umlagern, Ausbuchen, Inventur anlegen und zählen | Lager bewegen, Lager zählen |
| **Lagerleitung** (neu) | dazu Artikel, Lagerorte, Einstellungen, Chargen sperren, Inventur abschließen und verwerfen | Lager bewegen, Lager zählen, Lager führen |
| Buchhalter, Verantwortlicher | alles; dazu den Buchungsentwurf der Bestandsveränderung (Recht des Kerns) | wie Lagerleitung |
| Steuerberater, Prüfer, Leser | sehen | Lager ansehen |
| Erfasser | nichts – Buchhaltung und Lager sind getrennt zuweisbar | – |

„Lager“ und „Lagerleitung“ sehen weder Buchungen noch den Arbeitsvorrat. Den Wareneingang aus Belegen bedienen sie
trotzdem: Die Positionen reicht das Lager über den Dienst des Arbeitsvorrats durch.

**Festgestellt beim Bauen:** nopaper liest nach seinem eigenen Stand keine Positionen (dort E13: „keine Rechnungspositionen
…, denn dort stünden die Art.-9-Inhalte“; Profil BASIC WL ohne Positionen). Der Leser in kumero ist vorbereitet und an
nachgebauten Mustern geprüft. Ob nopaper Positionen schreibt, ist dort zu entscheiden; bis dahin kommen Positionen nur
aus E-Rechnungen.

## 15. Dokumente am Artikel: Anleitungen und Gefahrenhinweise (Entwurf, zur Freigabe – P47)

**Vorgabe des Verantwortlichen vom 2026-11-21** am Dialog „Artikel bearbeiten“ (F163): **Zum Artikelstamm gehören auch
Anleitungen und Gefahrenhinweise. Sie müssen in Paperless aufbewahrt werden. Je Artikel können es n Dokumente sein.**
**Ergänzt am 2026-11-22** an einem Bild der Seite „Bestand“: Vor dem Artikel steht ein kleines Dreieck; ein Klick zeigt
die Dokumente in einer Untertabelle. **Auch die Charge hat Dokumente** – solche, die in der Lieferkette entstanden sind
(Temperaturprotokolle, Lieferscheine, Hinweise zur Charge). **Liegen zur Charge Rechnungen vor, führt von dort ein Sprung
zur Buchung, wenn die Berechtigung vorliegt.**
**Antworten vom 2026-11-22** zu AD10, AD6 und AD7: Der Paperless-Zugang gilt in erster Linie vom Mandanten, das Lager
kann einen eigenen haben. Hochladen ohne bereites Paperless ist gesperrt. Es wird nicht ein Tag, sondern n Tags.
**Weitere Antworten vom 2026-11-22:** Wer zusätzlich eine Rolle der Buchhaltung hat, sieht die Buchung natürlich –
Rechte aus mehreren Rollen addieren sich (F41). Dokumente an die Charge hängen darf jeder mit dem Recht (AD20).
AD18, AD21 und AD23 sind am 2026-11-22 auf Nachfrage entschieden; AD2 ist um den Link nach außen erweitert (AD26).
Entschieden sind damit AD1–AD3, AD6, AD7, AD9, AD12, AD16, AD18–AD21 und AD23; AD10 ist wieder offen und führt über
AD24 (Nutzer und Gruppen in Paperless, Token beim Anlegen des Benutzers); alles andere sind Vorschläge. **Nicht gebaut.**

### 15.1 Grundsatz

**Erweitert am 2026-11-22** (Beispiel des Verantwortlichen: ein Handscanner mit einer Kurzanleitung als PDF, die nach
Paperless kommt, und einem Handbuch, das nur als Seite beim Hersteller steht): **Ein Dokument am Artikel ist entweder
eine Datei in Paperless oder ein Link nach außen.** Beides muss das Lager abdecken (AD26). Der folgende Absatz gilt für
Dokumente in Paperless; für Links nach außen gilt 15.3a.

Der Artikel trägt **Verweise**, nie die Datei (T8, F110). Ein Verweis ist der ganze Link auf das Dokument in Paperless mit
MD5 und SHA-256 des Originals – dieselbe Gestalt wie der Belegverweis (F88), aber **ohne Buchung**: nicht gesiegelt, kein
Teil einer Kette. Der Link sagt, wo das Dokument liegt, die Prüfsummen, welche Datei es ist.

### 15.2 Was am Artikel steht

**Geändert am 2026-11-22 (F166, 16.3):** Die Dokument-Verweise sind ein abstraktes Merkmal. Was hier als Tabellen des
Lagers beschrieben ist, liegt in einer gemeinsamen Tabelle des Kerns; die Felder und Regeln bleiben. Artikel und Charge
sind die ersten Gegenstände, die das Merkmal tragen.

Neue Tabelle `lager.artikel_dokument`, je Artikel beliebig viele Zeilen. Sie hängt an der ID von `lager.artikel` **im
Buch** – auch wenn der Artikel seine Produktangaben künftig aus dem Katalog bezieht (15.9):

| Feld | Inhalt |
|---|---|
| `artikel` | der Artikel |
| `art` | Gebrauchsanweisung · Gefahrenhinweis · Sicherheitsdatenblatt · Fach- oder Gebrauchsinformation · sonstiges (AD4) |
| `bezeichnung` | kurzer Name in kumero, z. B. „Gebrauchsanweisung DE, Stand 03/2026“ |
| `stand` | Datum, das das Dokument selbst trägt („Stand 03/2026“), freiwillig |
| `ort` | `paperless` (Datei im DMS) oder `extern` (Link nach außen, AD26) |
| `link`, `md5`, `sha256` | bei `paperless`: Verweis auf das Original, Schreibweise wie F88, mit beiden Prüfsummen; bei `extern`: nur der Link, ohne Prüfsummen |
| `zurueckgezogen_am`, `_von` | ein Verweis wird zurückgezogen, nie gelöscht (WW23) |
| `angelegt_am`, `_von` | wie überall |

Derselbe Link darf an mehreren Artikeln hängen (eine Anleitung für zwei Packungsgrößen), je Artikel aber nur einmal.
Geschrieben wird nur über SQL-Funktionen mit Protokoll; im Protokoll stehen Artikel-ID, Dokument-ID und Art.

**Dokumente der Charge** liegen in einer zweiten Tabelle gleicher Gestalt, `lager.charge_dokument`, mit eigenen Arten:
Lieferschein · Temperaturprotokoll · Hinweis zur Charge (z. B. Rückruf, Sicherheitsinformation des Herstellers) ·
Prüf- oder Analysenzertifikat · sonstiges (AD17). Ein Lieferschein gilt oft für mehrere Chargen; derselbe Link darf
deshalb an mehreren Chargen hängen. WW19 bleibt: kumero misst und zeichnet keine Temperaturen auf – das Protokoll des
Lieferanten oder Spediteurs ist ein Dokument wie jedes andere. Ebenso WW8: Der Lieferschein ist hier ein Dokument an
der Charge, keine Belegart, aus der ein Zugang entsteht.

**Rechnungen werden nicht ein zweites Mal verknüpft.** Ein Zugang aus einem Beleg trägt schon den Eintrag des
Arbeitsvorrats (`lager.bewegung.eintrag`); daraus ergeben sich der Beleglink der Rechnung und, sobald gebucht, die
Buchung. Das Lager fragt beides über den Dienst des Arbeitsvorrats ab (neue Auskunft: Eintrag → Beleglink, Buchung,
Stand) und speichert nichts davon (F44).

### 15.3 Zwei Wege zum Verweis

1. **Verknüpfen:** Das Dokument liegt schon in Paperless. Der Nutzer fügt den Link ein; kumero prüft ihn am Original über
   das Belegarchiv (`belegPruefen`, F97) und speichert Link und Prüfsummen. Ohne bestandene Prüfung kein Verweis.
2. **Hochladen:** Der Nutzer wählt eine Datei. kumero übergibt sie **sofort** an die Belegablage (F110) – hier gibt es
   keine Buchung, auf die zu warten wäre. Paperless verarbeitet nicht sofort: Bis es die Datei führt, steht der Eintrag
   als „wird übergeben“ da, und die Datei liegt so lange in einer Zwischenablage des Lagers; danach wird sie in kumero
   gelöscht. Ist kein Paperless bereit (nicht verbunden, gesperrt), ist „Hochladen …“ gesperrt und nennt den Grund (F106)
   – es wird nichts auf Vorrat zwischengelagert (AD6).

In Paperless setzt kumero Titel (Art und Bezeichnung des Artikels) und Datum (`stand`, sonst heute) und **Tags – nicht
eines, sondern n** (entschieden am 2026-11-22, AD7). Vorschlag zur Ausführung: Die Einstellungen des Lagers führen eine
**Tag-Zuordnung** – Tags, die jedes Dokument des Lagers bekommt, und dazu Tags je Dokumentart (z. B.
„Sicherheitsdatenblatt“ → zwei Tags, „Temperaturprotokoll“ → andere). Gewählt wird aus den Tags, die es in Paperless
schon gibt (L9: kumero legt dort nichts an), im selben aufklappbaren Fenster wie bei den Belegen (L4). Die Schnittstelle
`Belegablage` bekommt dafür das Merkmal `tags` als Liste. Das Tag für offene Belege wird nie gesetzt – ein Dokument
des Lagers kommt nicht in den Arbeitsvorrat. Beim **Verknüpfen** eines vorhandenen Dokuments **ergänzt kumero die Tags der
Zuordnung** und entfernt nie welche (entschieden am 2026-11-22, AD23).

### 15.3a Link nach außen (AD26)

Ein dritter Weg neben Verknüpfen und Hochladen: **„Link eintragen …“** – Adresse, Art, Bezeichnung, Stand.

- **kumero ruft die Adresse nie ab** – nicht zum Prüfen, nicht für eine Vorschau, nicht zum Ablegen. Der Server
  spricht nur mit dem eingetragenen Paperless (PAPERLESS.md Abschnitt 2); ein Abruf beliebiger Adressen wäre genau der
  Missbrauch als Abrufstelle, den L3 ausschließt.
- **Geöffnet wird der Link vom Browser des Nutzers in einem neuen Fenster** (wie L7), ohne Herkunftsangabe
  (`rel="noopener noreferrer"`): Der Hersteller erfährt die Adresse der kumero-Installation nicht.
- **Schreibweise:** nur `https://`, ohne Zugangsdaten in der Adresse, höchstens 500 Zeichen; Abfrage und Fragment
  bleiben erhalten (Handbuchseiten brauchen sie), anders als beim Belegverweis.
- **Keine Prüfsumme, keine Vorschau, keine Tags, kein „Verweise prüfen“.** In der Liste steht der Ort in Worten
  („extern“) und die Domain; „Vorschau“ ist gesperrt und nennt den Grund, „Öffnen“ öffnet.
- **Was ein Link nicht leistet:** Die Seite kann sich ändern oder verschwinden; kumero kann nicht sagen, was dort am
  Tag einer Entnahme stand. Der Dialog sagt das. **Was nachzuweisen ist, gehört als Datei nach Paperless** – der
  Nutzer lädt sie beim Hersteller selbst herunter und lädt sie hoch; kumero holt sie nicht.
- Ein Link nennt den Hersteller, nicht den Mandanten. Er ist damit – anders als ein Verweis nach Paperless – eine
  Angabe zum Produkt und könnte im Katalog stehen (KATALOG.md KA19, Vorschlag).

### 15.4 Oberfläche

- **Dialog „Artikel bearbeiten“:** Abschnitt „Dokumente“ an festem Platz mit fester Höhe (F106, F151) – Liste mit Art,
  Bezeichnung, Stand und Zustand; Knöpfe „Verknüpfen …“, „Hochladen …“, „Zurückziehen“, „Vorschau“. Die Liste rollt,
  der Dialog wächst nicht. Beim Anlegen eines Artikels ist der Abschnitt gesperrt („erst nach dem Anlegen“).
- **Seite „Bestand“, Artikelliste – Dreieck und Untertabelle** (Vorgabe vom 2026-11-22, AD16): Vor dem Artikel steht in
  einer eigenen schmalen Spalte ein kleines Dreieck. Ein Klick (Tastatur: → öffnet, ← schließt) zeigt unter der Zeile
  eine **Untertabelle mit den gültigen Dokumenten** des Artikels: Art, Bezeichnung, Stand, Zustand, Vorschau über den
  Baustein `beleg.js` (F97; Überfahren, Taste, groß im Fenster). Die Spalte steht immer da; hat ein Artikel keine
  Dokumente, ist das Dreieck gesperrt und nennt den Grund (F106 Nr. 2). Mehrere Untertabellen dürfen zugleich offen
  sein; sie bleiben beim Sortieren, Filtern und Blättern bei ihrer Zeile. **In der Untertabelle wird auch gepflegt**
  (entschieden am 2026-11-22, AD18): „Verknüpfen …“, „Hochladen …“, „Zurückziehen“ an festem Platz, ohne Recht gesperrt
  mit Grund.
  **Das ändert Abschnitt 9 Nr. 1 („nichts klappt auf“):** Die Untertabelle schiebt die Zeilen darunter nach unten –
  aber nur auf den Klick des Nutzers, nie von selbst (F106 in der Lesart von F137).
- **Chargen des gewählten Artikels (untere Hälfte) – dasselbe Dreieck vor der Charge** (AD12, AD19). Die Untertabelle
  der Charge zeigt zweierlei:
  1. die **Dokumente der Charge** (Lieferschein, Temperaturprotokoll, Hinweis zur Charge …), mit Vorschau;
  2. die **Rechnungen**, mit denen die Charge zugegangen ist – je Zugang aus einem Beleg eine Zeile mit Datum des
     Zugangs, Menge, Vorschau der Rechnung und dem Knopf **„Zur Buchung“** – beides nur mit `kern.buchung.sehen` (AD21), sonst
     gesperrt mit Grund. Der Knopf führt zur Buchung im Modul
     `buchen`, wenn der Nutzer `kern.buchung.sehen` hat; ohne das Recht ist er gesperrt und nennt den Grund, ebenso
     solange der Beleg noch nicht gebucht ist („noch nicht gebucht“). Die Antwort des Servers enthält die Kennung der
     Buchung nur für den, der sie sehen darf.
  Dieselben Angaben stehen in der **Rückverfolgung** der Charge.
- **Dialog „Entnahme“:** die gültigen Dokumente des Artikels und die Hinweise zur gewählten Charge – dort werden
  Gefahrenhinweis und Chargenhinweis gebraucht (AD9).
- **Wareneingang und „Zugang ohne Beleg“:** Knopf „Dokument zur Charge …“ – Lieferschein und Temperaturprotokoll
  kommen mit der Ware und werden dort angehängt, wo die Charge erfasst wird (AD20).
- **Artikelliste:** Spalte „Dokumente“ (Anzahl gültiger), sortier- und filterbar (F91) – auch „ohne Gebrauchsanweisung“
  und „ohne Gefahrenhinweis“.
- Fehlt die Vorschau, nennt die Oberfläche den Grund aus der Antwort des Servers (F107): Paperless gesperrt, kein Zugang,
  Dokument dort nicht mehr vorhanden.

### 15.5 Rechte und Rollen

Verweise am Artikel pflegen: `lager.artikel.pflegen` (Lagerleitung, Buchhalter, Verantwortlicher). Dokumente an der
Charge anhängen: `lager.bewegung.buchen` (auch die Rolle „Lager“ – sie nimmt die Ware an); zurückziehen:
`lager.artikel.pflegen` (AD20). Die Listen sehen: `lager.bestand.sehen`. Zur Buchung springen: `kern.buchung.sehen` –
die Rollen „Lager“ und „Lagerleitung“ haben es nicht (RL5) und sehen, dass eine Rechnung vorliegt, aber nicht die
Buchung. **Wer daneben eine Rolle der Buchhaltung hat, die das Recht trägt, springt und sieht** (bestätigt am
2026-11-22) – es zählt das Recht aus allen zugewiesenen Rollen, nicht die einzelne Rolle (F41). Auch die Vorschau der Rechnung gibt es in kumero nur mit diesem Recht (entschieden am 2026-11-22, AD21). **Zugang zu Paperless für die Dokumente des Lagers (entschieden am 2026-11-22, AD10): Er gilt in erster Linie vom
Mandanten; das Lager kann einen eigenen haben.** Mein Vorschlag, den Rollen „Lager“ und „Lagerleitung“ den Baustein
„Paperless nutzen“ mit je eigenem Konto zu geben, ist damit verworfen. Gelesen ist die Entscheidung so:

- Es gibt einen **Paperless-Zugang des Mandanten** – Adresse und Zugangsdaten, einmal hinterlegt, nicht je Nutzer. Über
  ihn zeigt und übergibt das Lager seine Dokumente. Wer im Lager arbeitet, braucht kein eigenes Konto in Paperless und
  keine Passphrase.
- **Das Lager eines Buchs kann einen eigenen Zugang haben** (anderes Paperless oder anderes Konto); er geht dem des
  Mandanten vor – wie überall: der Mandant gibt vor, die engere Stelle darf abweichen.
- **Das ändert PAPERLESS.md L2 für diesen Zweck:** Bisher handelt kumero in Paperless immer als der einzelne Mensch,
  mit dessen eigenem Token; einen gemeinsamen Zugang gab es ausdrücklich nicht. Für **Belege der Buchhaltung bleibt
  das so.** Der gemeinsame Zugang dient nur den Dokumenten des Lagers.
- Daraus folgt für die **Rechnung an der Charge** (AD21): Ihre Vorschau läuft nicht über den gemeinsamen Zugang,
  sondern wie bisher über den eigenen des Nutzers. Das Konto des gemeinsamen Zugangs sollte in Paperless nur die
  Dokumente des Lagers sehen (über die Tags aus 15.3) – das richtet der Betreiber in Paperless ein.
- Es ist derselbe Zugang am Mandanten, den das Personal für die Papier-AU braucht (PERSONAL.md AP2) – einmal zu
  bauen, für beide.

**Offen (AD22): Wo liegt das Token des gemeinsamen Zugangs?** Das Token eines Nutzers ist mit seiner Passphrase
verschlüsselt und aus der Datenbank allein nicht zu gewinnen (L3). Ein Zugang, den jeder im Lager ohne Eingabe nutzen
soll, kann so nicht geschützt sein: Sein Schlüssel muss dem Server zur Verfügung stehen (Vorschlag: Schlüssel außerhalb
der Datenbank, `systemd-creds`, nie in Sicherung und Log). Wer den Server betreibt, kommt dann an dieses Token – ein
Grund mehr, dem Konto in Paperless nur die Dokumente des Lagers zu geben.

### 15.6 Fassungen, Prüfung, Pflichten

- **Neuer Stand** einer Anleitung = neuer Verweis; der alte wird zurückgezogen und bleibt mit Zeitpunkt sichtbar. So ist
  später zu sagen, welcher Stand wann galt (AD11). „Fassung“ heißt in kumero nur noch die Fassung des Katalogartikels
  (KATALOG.md KA3) – ein Dokument hat einen **Stand**.
- **Je Charge abweichende Anleitungen** gibt es (geänderte Gebrauchsanweisung ab einer Charge). Sie hängen dann als
  „Hinweis zur Charge“ an der Charge (AD12).
- **„Verweise prüfen“** vergleicht auf Knopfdruck die Prüfsummen mit dem Original in Paperless; eine Abweichung oder ein
  dort gelöschtes Dokument steht als Hinweis am Verweis. kumero ändert und löscht in Paperless nichts (AD13).
- **Pflicht oder Hinweis?** Vorschlag: kein Sperren. Fehlt bei Art „Medizinprodukt“ oder „Arzneimittel“ die
  Gebrauchsanweisung bzw. die Gebrauchsinformation, steht das als Zustand in Worten in der Artikelliste und gehört zum
  Arbeitsvorrat des Moduls (F90). Ob ein Artikel ein Gefahrstoff ist, weiß kumero heute nicht – dafür ein Kennzeichen
  „Gefahrstoff“, bei dem das Sicherheitsdatenblatt erwartet wird. **Das Kennzeichen ist eine Angabe zum Produkt und
  gehört deshalb in die Fassung des Katalogartikels, nicht ins Buch** (KATALOG.md Abschnitt 3; nur bei Artikeln ohne
  GTIN steht es im Buch). Es wird nicht mit den Dokumenten gebaut, sondern mit dem Katalog (AD14, 15.9).
- **Welche Vorschrift welches Dokument verlangt** (Gebrauchsanweisung nach MPBetreibV, Sicherheitsdatenblatt und
  Betriebsanweisung nach GefStoffV, Fach- und Gebrauchsinformation nach AMG), **ist nicht nachgeschlagen.** Der
  Verantwortliche prüft es; die Liste der Arten ist deshalb eine Steuerdatei des Moduls, vom Buch anzupassen (F139).
- Anleitungen und Sicherheitsdatenblätter sind Unterlagen des Herstellers ohne Personenbezug. Hochgeladen wird nur
  das; der Dialog sagt es. kumero prüft den Inhalt nicht.

### 15.7 Endpunkte (erst Vertrag, dann Code – F18)

`GET /lager/artikel/{id}/dokumente` · `POST /lager/artikel/{id}/dokumente` (Verknüpfen: Link, Art, Bezeichnung, Stand) ·
`POST /lager/artikel/{id}/dokumente/datei` (Hochladen) · `POST /lager/artikel/{id}/dokumente/{dokument}/rueckzug` ·
`POST /lager/artikel/{id}/dokumente/pruefung`; für die Charge dieselben fünf unter `/lager/chargen/{id}/dokumente…`.
`GET /lager/chargen/{id}` nennt zusätzlich je Zugang aus einem Beleg den Beleglink und – nur mit `kern.buchung.sehen` –
die Buchung. Vorschau und Original kommen über die Endpunkte des Kerns (`/belege/vorschau`, `/belege/original`).

### 15.8 Entscheidungen

| # | Frage | Stand |
|---|---|---|
| AD1 | Gehören Anleitungen und Gefahrenhinweise zum Artikelstamm? | **Entschieden 2026-11-21: ja.** |
| AD2 | Wo liegen sie? | **Entschieden 2026-11-21: in Paperless.** kumero hält nur den Verweis (T8, F110). **Erweitert am 2026-11-22: oder als Link nach außen** (Handbuch beim Hersteller) – dann hält kumero nur die Adresse (AD26). |
| AD3 | Wie viele je Artikel? | **Entschieden 2026-11-21: n.** |
| AD4 | Arten | Vorschlag: Gebrauchsanweisung, Kurzanleitung, Handbuch, Gefahrenhinweis, Sicherheitsdatenblatt, Fach-/Gebrauchsinformation, sonstiges – als Steuerdatei, vom Buch anzupassen. |
| AD5 | Wege | Vorschlag: Verknüpfen (Link mit Prüfung am Original), Hochladen (Übergabe an die Belegablage) **und Link eintragen** (nach außen, AD26). |
| AD6 | Hochladen ohne bereites Paperless | **Entschieden 2026-11-22 (Zustimmung):** gesperrt mit Grund; keine Zwischenlagerung auf Vorrat. |
| AD7 | Kennzeichnung in Paperless | **Entschieden 2026-11-22: nicht ein Tag, sondern n Tags.** Vorschlag zur Ausführung: Tag-Zuordnung in den Einstellungen des Lagers – Tags für alle Dokumente und Tags je Dokumentart, gewählt aus den vorhandenen; `Belegablage` bekommt `tags` als Liste. Dokumenttyp und Korrespondent setzt kumero nicht. |
| AD8 | Verweis ohne Prüfsumme | Vorschlag: bei Dokumenten in Paperless nein – auch im Testbetrieb nur mit bestandener Prüfung (anders als F88, hier hängt kein Buchen daran). Links nach außen haben nie eine Prüfsumme (AD26). |
| AD9 | Wo sichtbar | **Entschieden 2026-11-22: auf „Bestand“ als Untertabelle unter dem Artikel (AD16).** Dazu als Vorschlag: Dialog des Artikels (Pflege), Dialog „Entnahme“, Rückverfolgung, Spalte in der Artikelliste. |
| AD10 | Zugang zu Paperless für die Dokumente des Lagers | **Entschieden 2026-11-22: Der Zugang gilt in erster Linie vom Mandanten; das Lager kann einen eigenen haben.** Gelesen als gemeinsamer Zugang am Mandanten, den das Lager eines Buchs überschreiben kann (15.5) – **ändert L2 für die Dokumente des Lagers; bitte die Lesart bestätigen.** |
| AD11 | Neuer Stand eines Dokuments | Vorschlag: neuer Verweis, alter zurückgezogen, nichts gelöscht. Das Wort „Fassung“ bleibt dem Katalog vorbehalten. |
| AD12 | Dokumente an der Charge | **Entschieden 2026-11-22: ja** – die Charge hat eigene Dokumente aus der Lieferkette (Temperaturprotokolle, Lieferscheine, Hinweise zur Charge). |
| AD13 | Prüfung des Bestands an Verweisen | Vorschlag: auf Knopfdruck; kumero ändert nichts in Paperless. |
| AD14 | Fehlende Dokumente | Vorschlag: Hinweis und Filter, kein Sperren – zunächst nur nach der Art (Medizinprodukt, Arzneimittel). **Geändert am 2026-11-22 wegen KATALOG.md:** Das Kennzeichen „Gefahrstoff“ kommt nicht als Spalte an `lager.artikel`, sondern als Angabe der Katalog-Fassung, und wird mit dem Katalog gebaut. Zusammen mit P40. |
| AD15 | Grenzen | Vorschlag: je Datei höchstens 50 MB; angenommen wird, was die Belegablage annimmt (`belegablageNimmt`, heute PDF); je Artikel und je Charge höchstens 50 gültige Verweise. |
| AD16 | Dreieck und Untertabelle | **Entschieden 2026-11-22:** kleines Dreieck vor dem Artikel, Klick zeigt die Dokumente in einer Untertabelle. Vorschlag zur Ausführung: eigene feste Spalte, ohne Dokumente gesperrt mit Grund, mehrere zugleich offen, nach dem Neuladen der Seite alle zu. **Ändert Abschnitt 9 Nr. 1 („nichts klappt auf“).** |
| AD17 | Arten an der Charge | Vorschlag: Lieferschein, Temperaturprotokoll, Hinweis zur Charge, Prüf-/Analysenzertifikat, sonstiges – in derselben Steuerdatei wie AD4. |
| AD18 | Pflegen in der Untertabelle? | **Entschieden 2026-11-22: ja, auch dort wird gepflegt.** In der Untertabelle stehen „Verknüpfen …“, „Hochladen …“ und „Zurückziehen“ an festem Platz; ohne Recht gesperrt mit Grund (F106). Der Dialog des Artikels bzw. der Charge behält dieselben Knöpfe. |
| AD19 | Rechnung und Buchung an der Charge | **Entschieden 2026-11-22:** Liegt zur Charge eine Rechnung vor, führt ein Sprung zur Buchung, wenn die Berechtigung vorliegt. Vorschlag zur Ausführung: abgeleitet aus dem Zugang (Eintrag des Arbeitsvorrats), nichts doppelt gespeichert; Recht `kern.buchung.sehen`; ohne Recht oder ohne Buchung gesperrt mit Grund. |
| AD20 | Wer hängt Dokumente an die Charge? | **Entschieden 2026-11-22: jeder, der das Recht hat** – `lager.bewegung.buchen`, im Wareneingang und im Dialog der Charge. Zurückziehen nur mit `lager.artikel.pflegen` (Zustimmung vom 2026-11-22). |
| AD21 | Rechnung ansehen ohne Recht auf Buchungen | **Entschieden 2026-11-22: Vorschau der Rechnung in kumero nur mit `kern.buchung.sehen`.** Wer nur im Lager arbeitet, sieht, dass eine Rechnung vorliegt (Datum des Zugangs, Menge), nicht die Rechnung und nicht die Buchung; der Server liefert ihm weder Beleglink noch Kennung der Buchung. |

| AD22 | Token des gemeinsamen Zugangs | Vorschlag: verschlüsselt mit einem Schlüssel außerhalb der Datenbank (`systemd-creds`); einrichten darf ihn der Administrator des Mandanten, den eigenen des Lagers, wer `lager.einstellung.pflegen` hat. **Bitte entscheiden** – der Betreiber des Servers kommt an dieses Token. |
| AD24 | Statt des gemeinsamen Zugangs: Nutzer und Gruppen in Paperless anlegen – **entschieden am 2026-11-25 (F203): ja; AD10 damit entschieden (kein gemeinsamer Zugang), AD22 entfällt. Ausführung: PAPERLESS.md Abschnitt 14.** | **Bedenken des Verantwortlichen vom 2026-11-22 zu AD22** („macht mir Sorgen“): vermutlich müsse kumero Paperless mit den Nutzern befüllen und dort die Gruppen anlegen. **Damit ist AD10 wieder offen.** Einordnung: Der eigene Zugang je Nutzer ist das bestehende, gebaute Konzept (L2) – der gemeinsame Zugang wäre der Bruch gewesen. Neu wären: (a) die Rollen „Lager“ und „Lagerleitung“ bekommen „Paperless nutzen“ (klein); (b) kumero legt Konten und Gruppen in Paperless an (ändert L9 „legt nichts an“, braucht dort ein Verwalterrecht, setzt Paperless am Mandanten voraus – PERSONAL.md AP2; eigener Entwurf in PAPERLESS.md). Vorschlag: (a) jetzt, Konten zunächst von Hand in Paperless; (b) als eigener Schritt. **Entschieden am 2026-11-22: Der System-Verwalter legt nur Mandanten an; das Verwalter-Token für Paperless kennt der Administrator des Mandanten** – er stößt das Anlegen an, mit seinem eigenen, entsperrten Zugang; nichts davon liegt ungeschützt auf dem Server. **Token ohne Handgriff – zu prüfen am Test-Paperless:** Paperless nennt `/api/users/` und `/api/groups/` mit vollem Anlegen und Ändern und gibt an `POST /api/token/` gegen Benutzername und Passwort ein Token aus. Möglicher Weg: kumero legt das Konto mit einem zufälligen Passwort an und holt damit das Token. Dass `/api/users/` beim Anlegen ein Passwort annimmt, ist nur aus der Erinnerung an den Quelltext von Paperless-ngx gesagt, nicht an 3.4 geprüft; ebenso offen, ob der Token-Endpunkt bei abgeschaltetem Passwort-Login oder mit zweitem Faktor antwortet. **Haken:** Das Token eines Nutzers wird mit dessen Passphrase verschlüsselt (L3) – beim Anlegen durch den Administrator ist der Nutzer nicht da. **Entschieden am 2026-11-22: kumero holt das Token beim Anlegen des Benutzers** (mein Vorschlag „erst beim ersten Entsperren“ ist verworfen). Vorschlag, damit das Token bis dahin nicht ungeschützt liegt: Beim Anlegen stellt der Administrator ohnehin den Zugang aus (Link und Code, F125). kumero verschlüsselt das eben geholte Token mit einem Schlüssel aus dem **Geheimnis des Links** – das steht nur im Link, in der Datenbank liegt davon nur der Hash. Löst der Nutzer den Link ein, wird das Token entpackt und mit seiner Passphrase neu verschlüsselt (später ohne Eingabe mit dem Passkey, L3). Verfällt der Zugang uneingelöst, ist das Token verloren; „Zugang neu ausstellen“ holt ein neues. Dafür muss der Administrator beim Anlegen Paperless entsperrt haben; sonst ist „mit Paperless-Konto“ gesperrt und nennt den Grund. |
| AD23 | Tags an verknüpften Dokumenten | **Entschieden 2026-11-22: kumero ergänzt beim Verknüpfen die Tags der Zuordnung am Dokument in Paperless; es entfernt nie welche.** Ändert AD13 in diesem Punkt (sonst ändert kumero dort nichts) und braucht am Dokument das Recht zum Ändern; fehlt es, bleibt der Verweis bestehen und der Grund steht als Hinweis da (Vorschlag). |

| AD26 | Link nach außen | **Entschieden 2026-11-22: Das Lager deckt auch Dokumente ab, die nur als Adresse beim Hersteller stehen.** Vorschlag zur Ausführung (15.3a): nur `https://`; kumero ruft die Adresse nie ab; der Browser öffnet sie in einem neuen Fenster ohne Herkunftsangabe; keine Prüfsumme, keine Vorschau; gilt auch an der Charge. |
| AD27 | Geräte in der Warenwirtschaft? | **Entschieden 2026-11-22: WW18 bleibt – der Handscanner ist für den Eigenbedarf und damit nicht im Lager. Er gehört als Artikel in den Katalog und dann in die Anlagen** (Abschnitt 16, F165, P49); die Dokument-Verweise gelten dort genauso. |
| AD25 | Dokumente und Artikelkatalog | **Folgt aus KATALOG.md (KA1–KA7, KA9, entschieden 2026-11-22):** Dokument-Verweise bleiben im Buch und kommen nie in den Katalog – der Link nennt das Paperless des Mandanten. Jeder Mandant verknüpft seine Dokumente selbst (15.9). |

**Berührt:** Zugang zu Paperless am Mandanten (neu, mit PERSONAL.md AP2), PAPERLESS.md L2, Modul `lager` (Migration, Funktionen, Dialoge, Seite „Bestand“ mit Untertabellen, Wareneingang,
Rückverfolgung, Manifest), Modul `arbeitsvorrat` (Auskunft Eintrag → Beleglink und Buchung im Dienst), Modul-API
(`Belegablage` um `tags`), Modul `paperless` (Tags beim Ablegen setzen), Baustein der Oberfläche für Untertabellen
(gemeinsam, nicht je Seite – F91), OBERFLAECHE.md, `openapi/v1.yaml`. **Ungeprüft:** ob `post_document` von Paperless-ngx 3.4
Tags beim Ablegen annimmt (laut dessen Dokumentation ja) – am Referenzbetrieb zu bestätigen wie alles aus F110.

### 15.9 Verhältnis zum Artikelkatalog (KATALOG.md, F164, P48)

Der Katalog ist ein eigener Entwurf: Produktangaben von Artikeln mit GTIN liegen in Fassungen in einer Datenbank über
alle Mandanten; `lager.artikel` bleibt im Buch, behält seine ID und bekommt `katalog_artikel` und `katalog_fassung`;
Artikel ohne GTIN stehen wie heute ganz im Buch. Für die Dokumente heißt das:

**Was passt, ohne Änderung:**
- Die Dokumente hängen an der ID von `lager.artikel` im Buch, die Dokumente der Charge an der Charge. Beides bleibt beim
  Umbau auf den Katalog unberührt – für Artikel mit und ohne GTIN gleich.
- Der Katalog führt die Dokument-Verweise ausdrücklich im Buch. Das muss so sein: Der Link nennt das Paperless des
  Mandanten und wäre im Katalog ein Rückschluss auf ihn (AD25).
- Dass derselbe Link an mehreren Artikeln hängen darf (15.2), wird wichtiger: Andere Einheit oder Führungsart ist nach
  KA7 ein anderer Katalogartikel und im Buch ein anderer Artikel – mit derselben Anleitung.
- Tag-Zuordnung, Rechte, Untertabelle, Rechnung und Buchung an der Charge: alles im Buch, vom Katalog nicht berührt.

**Wo es sich widersprach – und wie es jetzt steht:**
1. **Kennzeichen „Gefahrstoff“** (AD14): Der Entwurf wollte es als Spalte am Artikel im Buch. Nach dem Katalog ist es
   eine Angabe zum Produkt und steht in der Fassung. Geändert: Es wird hier nicht gebaut; der Hinweis auf fehlende
   Dokumente richtet sich zunächst nur nach der Art.
2. **Art, Bezeichnung und Verfallspflicht kommen künftig aus der Fassung.** Der Hinweis „Gebrauchsanweisung fehlt“
   folgt der Art, der Titel in Paperless der Bezeichnung – beides ist dann die **im Buch verwendete** Fassung, nicht
   die neueste. Übernimmt das Buch eine neuere Fassung, ändert sich an den Dokumenten nichts; schon abgelegte Titel in
   Paperless bleiben, wie sie sind.
3. **Das Wort „Fassung“** stand für zweierlei. Jetzt: Fassung = Katalogartikel, Stand = Dokument (AD11).
4. **„Gehört zum Artikelstamm“** (AD1) ist zu lesen als: gehört zum Artikel **im Buch** – der Stamm hat künftig einen
   Teil im Katalog (Produkt) und einen im Buch (Eigenes, darunter die Dokumente).
5. **Der Dialog „Artikel“ wird von beiden Entwürfen umgebaut** – vom Katalog (GTIN zuerst, Produktangaben aus der
   Fassung gesperrt, „neuere Fassung vorhanden“) und von hier (Abschnitt „Dokumente“). Er ist einmal für beides zu
   zeichnen, damit die Felder ihren festen Platz behalten (F106, F151). Vorschlag: oben das Produkt (Katalog), darunter
   „In diesem Buch“ (Bestände, Lagerort, stillgelegt), darunter „Dokumente“.

**Reihenfolge:** Die Dokumente brauchen den Katalog nicht und können vor ihm gebaut werden; nichts davon muss beim
Umbau auf den Katalog wieder angefasst werden – außer dem Dialog (Nr. 5). Die Migrationen sind getrennt: eine für die
Dokumenttabellen, eine für `katalog_artikel` und `katalog_fassung`.

**Nicht Sache der Dokumente, aber beim Lesen von KATALOG.md aufgefallen:**
- Die **eigene Artikelnummer** (`lager.artikel.nummer`) nennt KATALOG.md auf keiner Seite. Sie ist eine Nummer des
  Betriebs und gehört ins Buch – das sollte dort stehen.
- **P40** (Pflichtangaben für Arzneimittel: PZN, Wirkstoff, Stärke, Darreichungsform, Packungsgröße, Abgabestatus)
  wären Angaben zum Produkt und damit Felder der Fassung; KATALOG.md nennt sie nicht. Zusammen zu entscheiden.
- **Sortieren und Filtern auf „Bestand“** nach Bezeichnung und Art braucht die Angaben der verwendeten Fassung im
  Buch (zwischengehalten, KA11) – sonst kann die Datenbank des Buchs nicht danach ordnen.
- **Ein Kennzeichen, das in der Fassung wechselt, wirkt im Buch erst nach der Übernahme.** Für „Betäubungsmittel“
  heißt das: Solange ein Buch die alte Fassung verwendet, lässt das Lager Bewegungen zu, die es mit der neuen
  ablehnte (WW17). **Bestätigt am 2026-11-22: Das ist gewollt – das alte Recht gilt für den alten Artikel im Verkehr
  weiter (Bestandsschutz, KATALOG.md KA15).**

**Nachgezogen am 2026-11-22:** Der andere Chat wird nicht weitergeführt; KATALOG.md wird seit Fassung 3 hier gepflegt
und steht als F164 und P48 in AGENTS.md. Die Punkte oben sind dort eingearbeitet (KA11, KA15–KA18); **P40 ist
erweitert** – seine Pflichtangaben sind Felder der Katalog-Fassung (KA17).

## 16. Gegenstände für den Eigenbedarf: Katalog und Anlagen, kein eigenes Inventar (Entwurf, zur Freigabe – P49)

**Vorgaben des Verantwortlichen vom 2026-11-22** (F165), am Beispiel eines Handscanners, der für das Projekt beschafft
wurde: Ein Artikel für den Eigenbedarf ist nicht im Lager (WW18 bleibt). **Er gehört als Artikel in den Katalog und
dann in die Anlagen. Das Inventar ist da** – ein eigenes Inventar neben den Anlagen wird nicht gebaut.
Eine erste Fassung dieses Abschnitts hatte ein eigenes Inventar im Modul `lager` vorgeschlagen; das war ein Irrtum und
ist verworfen. Entschieden sind IN1, IN3, IN9 und IN10; IN2, IN4–IN8 und DV1–DV7 sind Vorschläge. **Nicht gebaut.**

### 16.0 Der Geschäftsfall, allgemein

Der Handscanner war nur ein Beispiel (Klarstellung des Verantwortlichen vom 2026-11-22). Der Geschäftsfall dahinter ist
allgemein: **Etwas wird beschafft, der Beleg nennt es als Position, und es hat Unterlagen** – manche als Datei, manche
nur als Adresse beim Hersteller. kumero muss für jede Position sagen können, was sie ist und wohin sie gehört:

| Die Position ist … | gehört nach | Produktangaben | Dokumente |
|---|---|---|---|
| Ware, die verbraucht oder abgegeben wird | Lager (Artikel, Charge, Bewegung) | Katalog, ohne GTIN im Buch | am Artikel und an der Charge |
| ein Gegenstand für den Eigenbedarf | Anlagen (Anlagegut, auch als GWG) | Katalog, ohne GTIN am Gut | am Anlagegut |
| weder noch (Porto, Dienstleistung) | nur in die Buchung | – | der Beleg selbst |

In allen drei Fällen bleibt der Beleg in Paperless und die Buchung im Buch; von Artikel, Charge und Anlagegut führt
der Weg dorthin, wenn das Recht vorliegt (AD19, AD21). Nichts in diesem Abschnitt gilt nur für Scanner oder Geräte
einer bestimmten Art.

### 16.1 Inventar und Anlage – der Unterschied

- **Anlagevermögen** ist, was dem Betrieb dauernd dient. Der Scanner ist das. Geführt wird es im Modul `anlagen`, das
  schon Inventarnummer, Standort, Zugang, Kosten und die Arten linear, GWG, Sammelposten und „keine AfA“ kennt
  (ANLAGEN.md).
- **Inventar** im Sinn der Buchführung (§ 240 HGB) ist das Verzeichnis **aller** Vermögensgegenstände und Schulden zu
  einem Stichtag – also der Oberbegriff: Anlagen **und** Vorräte und mehr. In kumero ergibt es sich aus dem
  Anlagenverzeichnis und der Bestandsliste des Lagers; es ist kein drittes Ding daneben.
- **Die einzige Lücke:** Was so wenig kostet, dass das Steuerrecht kein Verzeichnis verlangt (nach dem Regelwerk der
  Anlagen bis 250 € netto), muss nicht in den Anlagen stehen. Es **darf** aber: als GWG mit sofortigem Abzug. Wer
  wissen will, was er hat und welche Anleitung dazugehört, trägt es dort ein (IN5).

### 16.2 Was dafür fehlt

Das Anlagegut hat heute Bezeichnung, Inventarnummer und Standort als freien Text – keinen Bezug zum Produkt und keine
Dokumente. Es fehlen:

1. **Verweis auf den Katalog** am Anlagegut (`katalog_artikel`, `katalog_fassung`, wie am Lagerartikel – KATALOG.md):
   Bezeichnung, Hersteller und GTIN kommen dann aus der Fassung; ohne GTIN bleibt alles am Gut. Dazu die
   **Seriennummer** am Gut.
2. **Dokument-Verweise am Anlagegut** – Datei in Paperless oder Link nach außen, n je Gut (Abschnitt 15). Für den
   Scanner: Kurzanleitung als PDF in Paperless, Handbuch als Link zum Hersteller.
3. **Der Weg aus dem Wareneingang:** Der Hinweis „Anlagegut?“ (Y31) erscheint heute nur über der GWG-Grenze. Die
   Position eines Belegs bekommt die Wahl **„als Anlagegut anlegen“** unabhängig vom Betrag; sie führt in die Anlagen,
   mit Bezeichnung, GTIN, Betrag und Beleg vorbelegt. Im Lager ist die Position damit erledigt.

### 16.3 Dokument-Verweise sind ein abstraktes Merkmal (entschieden am 2026-11-22, F166)

**Entscheidung des Verantwortlichen: Die Dokument-Verweise sind abstrakt** – kein Teil des Lagers, sondern ein Merkmal,
das jedes Modul seinen Gegenständen geben kann, so wie die Belegvorschau (F97) und der Arbeitsvorrat (F90). Abschnitt 15
beschreibt damit die erste Anwendung (Artikel und Charge), Abschnitt 16 die zweite (Anlagegut); die Technik liegt an
einer Stelle. Vorschlag zur Ausführung (DV1–DV7 in 16.4):

- **Ablage:** eine Tabelle im Kern des Buchs statt `lager.artikel_dokument` und `lager.charge_dokument`. Schlüssel ist
  Modul, Art des Gegenstands und dessen ID; die Felder sind die aus 15.2 (Art des Dokuments, Bezeichnung, Stand, Ort
  `paperless` oder `extern`, Link, Prüfsummen, zurückgezogen). Kein Fremdschlüssel in das Schema des Moduls (F44).
- **Ein Modul meldet im Manifest an,** welche seiner Gegenstände Dokumente tragen, welche Arten von Dokumenten dort
  gelten (Steuerdatei) und **welches seiner Rechte** zum Sehen, Anhängen und Zurückziehen berechtigt. Der Kern prüft
  das Recht; das Modul sagt auf Nachfrage, ob es den Gegenstand gibt.
- **Dienst und Endpunkte des Kerns** für Verknüpfen, Hochladen, Link eintragen, Zurückziehen, Prüfen – einmal, für
  alle Module. Die Wege aus 15.3 und 15.3a, die Tag-Zuordnung (je Modul und Art des Dokuments) und die Regeln für
  Paperless bleiben, wie beschrieben.
- **Baustein der Oberfläche:** die Untertabelle hinter dem Dreieck und der Abschnitt „Dokumente“ im Dialog – ein
  Baustein, den jede Liste und jeder Dialog einsetzt (wie `tabellenfilter.js`, `beleg.js`).
- **Was Sache des Moduls bleibt:** der Sprung von der Charge zur Rechnung und Buchung (AD19, AD21) – das ist kein
  Dokument-Verweis, sondern folgt aus dem Zugang.
- **Am Mandanten** (Personal: Papier-AU, Dokumente der Akte – PERSONAL.md AP1–AP6, S15) braucht es dasselbe Merkmal
  später noch einmal, mit denselben Funktionen in der Datenbank des Mandanten (wie F135). Dort gelten strengere
  Regeln (Personenbezug); nicht Teil dieses Schritts.

### 16.4 Entscheidungen

| # | Frage | Stand |
|---|---|---|
| IN1 | Wohin gehören Gegenstände für den Eigenbedarf? | **Entschieden 2026-11-22: als Artikel in den Katalog und dann in die Anlagen. Kein eigenes Inventar; nicht ins Lager (WW18).** |
| IN2 | Katalog am Anlagegut | Vorschlag: `anlagen.gut` bekommt `katalog_artikel`, `katalog_fassung` und `seriennummer`; mit GTIN kommen die Produktangaben aus der Fassung, ohne GTIN stehen sie am Gut. Übernahme einer neueren Fassung wie im Lager, von einem Menschen. |
| IN3 | Wo liegt die Technik der Dokument-Verweise? | **Entschieden 2026-11-22: Die Dokument-Verweise sind abstrakt** – ein Merkmal für jedes Modul, kein Teil des Lagers (16.3, F166). |
| DV1 | Ablage | Vorschlag: eine Tabelle im Kern des Buchs, Schlüssel Modul × Art des Gegenstands × ID; geschrieben nur über Funktionen mit Protokoll (IDs und Art, kein Titel). |
| DV2 | Anmeldung | Vorschlag: im Manifest des Moduls (`dokumente`): Gegenstände, Arten der Dokumente, Rechte zum Sehen, Anhängen, Zurückziehen. |
| DV3 | Rechte | Vorschlag: Der Kern prüft das vom Modul genannte Recht; ein eigenes Recht des Kerns gibt es nicht. Für das Lager gilt, was in Abschnitt 15 entschieden ist (AD20). |
| DV4 | Gibt es den Gegenstand? | Vorschlag: Der Kern fragt das Modul über einen Erweiterungspunkt; ohne Antwort kein Verweis. Wird ein Modul ausgeschaltet, bleiben seine Verweise liegen (F142: Ausschalten löscht nichts). |
| DV5 | Oberfläche | Vorschlag: ein Baustein für Untertabelle und Dialog-Abschnitt; Seiten setzen ihn ein und nennen nur Modul, Art und ID. |
| DV6 | Tag-Zuordnung | Vorschlag: je Modul und Art des Dokuments, gepflegt in den Einstellungen des Moduls (für das Lager wie AD7). |
| DV7 | Am Mandanten | Vorschlag: dasselbe Merkmal später in der Datenbank des Mandanten, als eigener Schritt mit dem Paperless-Zugang am Mandanten (AP2, AD24). |
| IN4 | Weg aus dem Wareneingang | Vorschlag: „als Anlagegut anlegen“ an jeder Position, unabhängig vom Betrag; der Hinweis über der GWG-Grenze bleibt. Braucht `anlagen.gut.pflegen` – die Rolle „Lager“ hat es nicht; ohne das Recht ist die Wahl gesperrt mit Grund, und die Position bleibt für die Buchhaltung offen. |
| IN5 | Güter unter der Verzeichnisgrenze | Vorschlag: dürfen als GWG in den Anlagen stehen; kumero verlangt es nicht und weist nicht darauf hin. |
| IN6 | Der Katalog dient zwei Modulen | Vorschlag: Der Katalog ist nicht mehr Sache des Lagers allein (KATALOG.md KA20): Anlegen und Fortschreiben darf auch, wer Anlagen pflegt; die Art bekommt den Wert „Gerät“; Einheit und Führungsart sind für ein Gerät „Stück“ und „Seriennummer“. |
| IN7 | Inventar zum Stichtag | Vorschlag: später eine Auswertung, die Anlagenverzeichnis und Bestandsliste des Lagers nebeneinanderstellt (§ 240 HGB) – kein neuer Datenbestand. Die körperliche Bestandsaufnahme der Anlagen ist in ANLAGEN.md ausgenommen (G18) und bliebe ein eigener Schritt. |
| IN8 | Medizingeräte | Unverändert WW18: Medizinproduktebuch und Bestandsverzeichnis nach MPBetreibV sind nicht Teil von kumero. Ein Gerät steht als Anlagegut in den Anlagen; das ersetzt beides nicht. |
| IN9 | Gegenstände, die keine Anlage sind, aber da sind | **Entschieden 2026-11-22 („klingt gut, das bauen wir so“):** Billiges geht als GWG in die Anlagen (IN5). **Gemietetes und Geleastes** bekommt am Anlagegut die Art **„fremd (gemietet, geleast)“** – ohne Kosten und ohne AfA, nie im Anlagenverzeichnis und nie im Inventar zum Stichtag; Bezeichnung, Standort, Seriennummer, Katalog-Verweis und Dokumente wie bei jedem Gut. Ändert ANLAGEN.md G18 insoweit, als Geleastes verzeichnet werden kann; gerechnet wird damit weiter nichts. **Gebaut mit kumero 0.7.72 (F167, ANLAGEN.md Abschnitt 11)** – Seriennummer, Katalog-Verweis und Dokumente kommen mit ihren eigenen Schritten. |
| IN10 | Kostenstelle am Anlagegut | **Entschieden 2026-11-22:** ein Tag des Kerns (F8) am Gut, der in die AfA-Entwürfe übernommen wird. Ergänzung der Anlagen, unabhängig von Katalog und Dokumenten. **Gebaut mit kumero 0.7.72 (F167).** |

## 17. Artikelgruppen (Meta-Artikel): Verbrauchsmaterial gleicher Funktion, das sich ersetzt (gebaut mit 0.7.73)

**Vorgabe des Verantwortlichen vom 2026-11-22** (F168): **Für Verbrauchsmaterial braucht es Meta-Artikel – Artikel, die
dieselbe Funktion haben und damit andere Artikel ersetzen können.** **Freigegeben am 2026-11-22 mit „bau …“** – MA2 bis MA14 damit wie vorgeschlagen; **MA15 entschieden: In der Oberfläche heißt es
„Artikelgruppe“.** Gebaut mit kumero 0.7.73, Modul `lager` 0.5.0 (F169); was davon abweicht und was fehlt, steht in 17.5.
„Meta-Artikel“ bleibt der Name in Datenbank und Schnittstelle.

### 17.1 Worum es geht

Im Regal liegen Einmalhandschuhe von drei Herstellern. Für den Betrieb ist das **ein** Ding: „Einmalhandschuh Nitril,
Größe M“. Heute ist jedes Produkt ein eigener Artikel mit eigenem Mindestbestand – das Lager meldet „unter
Mindestbestand“, obwohl vom anderen Hersteller genug da ist, und die Entnahme fragt nach einem Produkt, das dem
Entnehmenden gleichgültig ist.

- **Meta-Artikel** = eine Funktion, kein Produkt: Bezeichnung, Einheit, Mindest- und Sollbestand. Er hat keine GTIN,
  keinen Hersteller, keine Charge und **keinen eigenen Bestand**.
- **Mitglieder** = die konkreten Artikel (mit GTIN, Hersteller, Chargen), die diese Funktion erfüllen. Sie ersetzen
  einander.
- **Bewegt wird immer der konkrete Artikel** mit seiner Charge. Rückverfolgung, Verfall, Sperre, Inventur und Bewertung
  bleiben, wie sie sind – der Meta-Artikel ist eine Klammer darüber, keine zweite Buchführung.

### 17.2 Was der Meta-Artikel bewirkt

1. **Bestand:** Die Liste „Bestand“ zeigt den Meta-Artikel als eine Zeile mit der Summe des verfügbaren Bestands seiner
   Mitglieder, dem nächsten Verfall über alle und dem Zustand. Hinter dem Dreieck (Abschnitt 15.4) stehen die
   Mitglieder mit ihrem Bestand; die Chargen des gewählten Mitglieds stehen wie heute in der unteren Hälfte.
2. **Mindest- und Sollbestand gelten am Meta-Artikel.** „Unter Mindestbestand“ und die Nachbestellliste rechnen mit der
   Summe (verfügbar, ohne abgelaufene und gesperrte Chargen – Y17). Am Mitglied sind die beiden Felder dann gesperrt,
   mit Grund (F106).
3. **Nachbestellen:** eine Zeile je Meta-Artikel mit dem Vorschlag bis zum Sollbestand. Darunter die Mitglieder mit
   letztem Lieferanten, letztem Preis und Packungsgröße – welches Produkt bestellt wird, entscheidet der Mensch.
4. **Entnahme:** Gewählt wird der Meta-Artikel (oder eine Packung wird gescannt – dann ist es dieses Mitglied).
   Vorgeschlagen wird die freie Charge mit dem **frühesten Verfall über alle Mitglieder** (WW6); abweichen darf man wie
   bisher. Gebucht wird die Entnahme am konkreten Artikel.
5. **Wareneingang:** Die Position eines Belegs wird wie heute einem konkreten Artikel zugeordnet. Wird dabei ein neuer
   Artikel angelegt, lässt er sich gleich einem Meta-Artikel zuordnen.
6. **Dokumente** (F166): Anleitungen des Herstellers hängen am Mitglied. Am Meta-Artikel hängt, was für die Funktion
   gilt (z. B. eine Betriebsanweisung) – er ist ein weiterer Gegenstand, der Dokumente tragen kann.

### 17.3 Daten

Im Schema `lager`: die Tabelle **Meta-Artikel** (Bezeichnung, Einheit, Mindest- und Sollbestand, stillgelegt) und die
Zuordnung am Artikel (`lager.artikel.meta_artikel`, leer bei Artikeln ohne Meta-Artikel). Keine
Bewegung, keine Charge, kein Bestand am Meta-Artikel. Zuordnen und Lösen steht im Protokoll mit IDs.

### 17.4 Entscheidungen

| # | Frage | Stand |
|---|---|---|
| MA1 | Braucht es Meta-Artikel? | **Entschieden 2026-11-22: ja – für Verbrauchsmaterial: Artikel gleicher Funktion, die einander ersetzen.** |
| MA2 | Für welche Arten? | Vorschlag: für „Verbrauch“ und „sonstiges“. **Bitte entscheiden für „Medizinprodukt“:** Viel Verbrauchsmaterial einer Praxis ist rechtlich ein Medizinprodukt (Spritzen, Kanülen, Handschuhe). Vorschlag: zulassen, aber nur für Artikel **ohne Seriennummer**; **nie für Arzneimittel** – ob ein Arzneimittel ein anderes ersetzt, ist eine fachliche Entscheidung im Einzelfall, keine Lagerregel. |
| MA3 | Wo liegt der Meta-Artikel? | Vorschlag: **im Buch.** Was sich im eigenen Betrieb ersetzt, entscheidet der Betrieb. Im Katalog (KATALOG.md) stehen Produkte; eine Funktionsklasse dort wäre ein eigener, späterer Schritt. |
| MA4 | Wie viele Meta-Artikel je Artikel? | Vorschlag: höchstens einer; Meta-Artikel lassen sich nicht ineinander stecken. |
| MA5 | Einheit | Vorschlag: Alle Mitglieder führen dieselbe Lagereinheit wie der Meta-Artikel (z. B. Stück) – sonst ist die Summe sinnlos. Unterschiedliche Packungsgrößen trägt wie bisher der Faktor am Lieferantenartikel (WW21). |
| MA6 | Führungsart der Mitglieder | Vorschlag: darf verschieden sein (mit und ohne Charge); die Summe und der Vorschlag nach Verfall gehen trotzdem. |
| MA7 | Mindest- und Sollbestand | Vorschlag: nur am Meta-Artikel; am Mitglied gesperrt. Beim Zuordnen des ersten Mitglieds wird dessen Wert als Vorschlag übernommen, nichts wird still addiert. |
| MA8 | Entnahme | Vorschlag: frühester Verfall über alle Mitglieder; gebucht am konkreten Artikel; die Abweichung vom Vorschlag wird wie heute vermerkt. |
| MA9 | Bevorzugtes Mitglied | Vorschlag: eine Reihenfolge der Mitglieder am Meta-Artikel – nur für die Nachbestellliste (welches zuerst genannt wird), nie für die Entnahme; dort entscheidet der Verfall. |
| MA10 | Ersetzen in eine Richtung? | Vorschlag: nein – Mitglieder eines Meta-Artikels ersetzen sich gegenseitig. „A ersetzt B, aber nicht umgekehrt“ (steril ersetzt unsteril) ist dann kein Fall für einen Meta-Artikel. **Bitte entscheiden**, ob das genügt. |
| MA11 | Zuordnung ändern | Vorschlag: jederzeit; Bewegungen und Chargen bleiben unberührt. Nach dem Lösen gilt am Artikel wieder sein eigener Mindestbestand (leer, bis er gesetzt wird). |
| MA12 | Inventur und Bewertung | Vorschlag: unverändert am konkreten Artikel und an der Charge; die Bestandsliste kann nach Meta-Artikel gruppieren. |
| MA13 | Stilllegen | Vorschlag: Ein Meta-Artikel mit Mitgliedern lässt sich nicht stilllegen; erst die Mitglieder lösen. |
| MA14 | Recht | Vorschlag: `lager.artikel.pflegen` (Lagerleitung, Buchhalter, Verantwortlicher). |
| MA15 | Name in der Oberfläche | **Entschieden 2026-11-22: „Artikelgruppe“.** |

### 17.5 Stand der Umsetzung (0.7.73) und Festlegungen zur Bestätigung

**Gebaut:** Artikelgruppe anlegen, ändern, stilllegen; Artikel zuordnen und lösen (im Dialog des Artikels); auf „Bestand“
die Gruppe als eine Zeile mit Summe, nächstem Verfall und Zustand, das Dreieck mit den Artikeln der Gruppe darunter, die
Chargen aller Artikel in der unteren Hälfte; Mindest- und Sollbestand an der Gruppe; Kachel und Filter „unter
Mindestbestand“; Entnahme-Vorschlag und Abweichung über alle Artikel der Gruppe; Nachbestellliste mit Gruppe und ihren
Artikeln (auch Druck und CSV).

| # | Festlegung |
|---|---|
| MB1 | ***Aufgehoben mit 0.7.85 (F192, 17.7), soweit es Seriennummern betrifft.*** **Artikel mit Seriennummer gehören nie in eine Gruppe** – bei jeder Art, nicht nur bei Medizinprodukten (MA2 hatte es nur dort genannt). Arzneimittel und Betäubungsmittel nie. Das prüft ein Trigger an der Tabelle: Auch wer später Art, Führung oder Einheit eines Gruppenartikels ändert, wird abgewiesen. |
| MB2 | **Zuordnen löscht den eigenen Mindest- und Sollbestand des Artikels** (MA7); der alte Stand steht im Protokoll. Wird ein Artikel im Dialog aus der Gruppe genommen, löst die Seite erst und speichert dann. |
| MB3 | **Die Artikel einer Gruppe stehen in der Liste nur hinter dem Dreieck**, nie einzeln daneben. Die Suche und der gescannte Code finden die Gruppe auch über ihre Artikel und Chargen. Die Zahl unter der Liste zählt eine Gruppe als einen Artikel. |
| MB4 | **Das Dreieck steht vor jeder Zeile** (F106): bei Artikeln ohne Gruppe gesperrt mit Grund. Mit den Dokument-Verweisen (Abschnitt 15) bekommt es dort seine zweite Aufgabe. Aufgeklappt bleibt es bis zum Neuladen der Seite. |
| MB5 | **Die Tabelle der Chargen hat die neue Spalte „Artikel“** – bei einer Gruppe sagt sie, von welchem Produkt die Charge ist; bei einem einzelnen Artikel steht dort immer derselbe Name. |
| MB6 | **„Artikel bearbeiten …“ heißt jetzt „Bearbeiten …“** und öffnet den gewählten Artikel oder die gewählte Gruppe. „Zugang ohne Beleg“ ist an der Gruppe gesperrt: Zugehen kann nur ein konkreter Artikel. |
| MB7 | **Stillgelegte Artikel einer Gruppe** zählen nicht zum verfügbaren Bestand und nicht zum Vorschlag; ihr Restbestand steht in der Summe. |
| MB8 | **Der Vorschlag der Lagereinheit, des Mindest- und Sollbestands** einer neuen Gruppe kommt vom gerade gewählten Artikel, wenn der selbst in eine Gruppe dürfte; zugeordnet wird er dadurch nicht. |
| MB9 | **Nachbestellen:** Die Bestelleinheiten je Artikel der Gruppe rechnen jeweils den ganzen Vorschlag – als Auskunft „so viele wären es bei diesem Produkt“, nicht als Aufteilung. |

**Nicht gebaut:** die Reihenfolge der Artikel in der Gruppe (MA9); Dokumente an der Gruppe (17.2 Nr. 6 – kommt mit F166);
die Gruppierung in „Bestandsliste und Wert“ (MA12); ein Standard-Lagerort der Gruppe; die Zuordnung gleich beim
Wareneingang (17.2 Nr. 5 – ein dort neu angelegter Artikel wird danach im Dialog zugeordnet).

### 17.6 Nacharbeit vom 2026-11-25: Aufgabe der Gruppe, neue Maske, Weg in die Gruppe (gebaut mit 0.7.83, F189)

Vorgaben des Verantwortlichen am Dialog „Artikelgruppe anlegen“; beschrieben und entschieden in **STUECKLISTE.md
Abschnitt 2, 3 und 8** (AG1–AG8, SA1–SA10). Kurz:

- **Die Artikelgruppe trägt ihre Aufgabe** – wofür ihre Artikel dienen und was einer können muss, um dazuzugehören
  (Feld `aufgabe`, Pflicht beim Anlegen, höchstens 500 Zeichen). Das ergänzt 17.1 und 17.3.
- **Die Maske ist neu geordnet** (ein Raster, Hinweis immer unter dem Feld); **im Dialog der Gruppe wird auch zugeordnet
  und gelöst** – das ändert MB2 („zugeordnet wird im Dialog des Artikels“) in ein Sowohl-als-auch.
- **„Artikel neu …“ führt in die markierte Gruppe** – auch wenn ein Artikel der Gruppe markiert ist. MB8 (Vorbelegung
  einer neuen Gruppe) bleibt.
- **Eine gewählte Gruppe wird nie still entfernt:** Passt der Artikel nicht hinein, ist der Knopf gesperrt mit Grund.
- **Beispiele und Platzhalter nennen gewöhnliche Artikel** – es gibt nicht nur medizinische.

Von den Punkten „Nicht gebaut“ in 17.5 bleibt alles offen. Weiter offen aus STUECKLISTE.md: die erweiterbare Art des
Artikels, Sets als Lagerware mit Stückliste, Produktangaben und Merkmale am Artikel – Entwürfe, nicht gebaut (P54).

### 17.7 Artikel mit Seriennummer in der Gruppe (gebaut mit 0.7.85, F192)

**Entscheidung des Verantwortlichen vom 2026-11-25, 06:34**, am Dialog „Artikel anlegen“, der einen Handscanner mit
Seriennummer in der Gruppe „Scanner zur Barcode-Erfassung“ abwies: **„Das ist doch bei Scannern genauso – der Zebra kann
durch einen x-beliebigen anderen ersetzt werden, der funktional identisch ist.“** Damit ist MB1 aufgehoben, soweit es
Seriennummern betrifft, und MA2 („nur für Artikel ohne Seriennummer“) entsprechend geändert. Arzneimittel und
Betäubungsmittel bleiben ausgeschlossen; die gemeinsame Lagereinheit bleibt Bedingung.

- Die Gruppe zählt die Stücke; bewegt wird das einzelne Stück mit seiner Seriennummer (Menge 1).
- Der Vorschlag der Entnahme über alle Artikel der Gruppe gilt auch hier: frühester Verfall, ohne Verfall das zuerst
  zugegangene Stück.
- **Verhältnis zu WW18 und IN1 (bitte bestätigen):** Ein Gerät **auf Vorrat** ist damit Lagerware. Was **in Nutzung**
  ist, gehört weiter in die Anlagen (LAGER.md Abschnitt 16); der Weg vom Lager dorthin („als Anlagegut anlegen“, IN4) und
  die Stückliste des Geräts (STUECKLISTE.md Abschnitt 5) sind nicht gebaut. Die Entnahme eines Geräts aus dem Lager ist
  bis dahin nur eine Entnahme.

**Berührt:** Modul `lager` (Migration, Seite „Bestand“ mit Untertabelle, Dialog „Artikel“, Entnahme, Nachbestellen,
Bestandsliste), DATENMODELL.md, `openapi/v1.yaml`. Hängt mit P47 zusammen (dieselbe Untertabelle hinter dem Dreieck)
und mit P48 (der Dialog „Artikel“ wird von Katalog, Dokumenten und Meta-Artikel umgebaut – einmal für alle drei zeichnen).

### 17.8 Zugang an der Gruppe, Vorgabe der Art, Weg vom Wareneingang (gebaut mit 0.7.86, F197)

**Anlass:** Der Verantwortliche konnte einen Scanner mit Seriennummer auch nach 0.7.85 nicht einbuchen. Nachgestellt im
Browser, mit drei Ursachen – keine davon eine Regel, alle drei Bedienung:

| # | Festlegung aus der Umsetzung – **zu bestätigen** |
|---|---|
| MC1 | **„Zugang ohne Beleg …“ ist auch an der markierten Artikelgruppe frei.** Der Dialog trägt dann die Auswahl „Artikel der Gruppe“; vorgewählt ist der Artikel der gewählten Charge, sonst der erste. Gesperrt mit Grund nur, wenn die Gruppe keinen Artikel hat, der zugehen kann. |
| MC2 | **Vorgabe der Art eines neuen Artikels:** die Art der Gruppe, in die er führt (die ihres ersten Artikels), sonst die zuletzt gewählte (je Browser gemerkt), sonst „Verbrauch“ – nie mehr von vornherein „Medizinprodukt“. „Verfallsdatum ist Pflicht“ folgt der Art wie bisher. |
| MC3 | **Eine Artikelgruppe ohne Artikel steht in der Liste**, auch wenn das Buch noch keinen Artikel hat. |
| MC4 | **„Wareneingang“ trägt den Knopf „Zugang ohne Beleg …“.** Er führt zu „Bestand“ und öffnet dort den Dialog für den markierten Artikel; fehlt etwas (kein Artikel, kein Lagerort), sagt eine Meldung, was. |

Die Stückliste am Artikel steht in STUECKLISTE.md Abschnitt 11.

### 17.9 Scanner mit falscher Tastaturbelegung (gebaut mit 0.7.89, F202)

**Vorgabe des Verantwortlichen (2026-11-25, 07:48, am Dialog „Zugang ohne Beleg“ mit einer gescannten Seriennummer, in
der „ß“ stand): „Der Tastaturfehler muss abgefangen werden – wohl auf dem Client?“** Ja, im Browser: Der Scanner
schreibt wie eine Tastatur (WW16); sendet er die US-Belegung an einen Rechner mit deutscher, wird aus „-“ ein „ß“, und
Y und Z sind vertauscht.

| # | Festlegung aus der Umsetzung – **zu bestätigen** |
|---|---|
| TB1 | **Erkannt wird an Zeichen, die in Codes, Chargen und Seriennummern nicht vorkommen:** ß, Umlaute, ´ § °. Vertauschtes Y und Z allein ist nicht zu erkennen. |
| TB2 | **Feld für Seriennummer oder Charge:** Hinweis in einer festen Zeile und Knopf „Berichtigen: …“ mit der zurückübersetzten Fassung. Nichts wird still geändert – die Berichtigung tauscht auch Y und Z, das soll ein Mensch sehen. Keine Sperre. |
| TB3 | **Feld „Code scannen“ und die Suche auf „Bestand“:** Der Code wird bei solchem Befund vor dem Zerlegen zurückübersetzt (dort tippt niemand von Hand). |
| TB4 | **Nur im Browser** (`gemeinsam.js`: `tastaturVerdacht`, `tastaturBerichtigen`, `tastaturWache`); der Server prüft es nicht. Nur die Verwechslung US an Deutsch. |

**Offen:** dieselbe Wache an den Feldern für Charge im Wareneingang und in der Inventur; eine Einstellung „Scanner sendet
US-Belegung“ je Arbeitsplatz, die auch Y und Z ohne Befund tauscht. Die eigentliche Abhilfe bleibt die Einstellung am
Scanner.

### 17.10 Stück oder Charge: Die Worte folgen der Führung (gebaut mit 0.7.90, F204)

**Vorgabe des Verantwortlichen (2026-11-25, 10:30, an der Seite „Bestand“ mit einem Scanner): „Das macht keinen Sinn,
dass ein Scanner eine Charge bekommt – die Fallunterscheidung ist nicht korrekt.“**

| # | Festlegung aus der Umsetzung – **zu bestätigen** |
|---|---|
| SC1 | **Mit Seriennummer:** „Stücke“, Spalte „Seriennummer“, in Sätzen „Seriennummer …“ und „das Stück“. **Mit Charge:** wie bisher. **Ohne Charge:** „Bestand“. |
| SC2 | **Artikelgruppe:** nach der Führung ihrer Artikel; sind sie verschieden, „Chargen und Stücke“ und Spalte „Charge / Seriennummer“. |
| SC3 | **Nur Worte der Oberfläche auf „Bestand“.** Die Datenbank führt ein Stück weiter als Zeile in `lager.charge`; Schnittstelle (`chargen`, `charge`) und Protokoll bleiben. |

**Offen:** dieselben Worte in Wareneingang, Inventur, Bestandsliste, Nachbestellliste, den Kacheln und den Meldungen des
Servers. Und: eine falsch erfasste Seriennummer lässt sich nicht berichtigen – nur ausbuchen (Grund „Berichtigung“) und
neu zubuchen; ein „Seriennummer berichtigen“ mit Protokoll wäre ein eigener Schritt.

## 18. Dialog „Lagerorte“: Tabelle mit dem Bestand je Ort (gebaut mit 0.7.84, F191)

**Vorgabe des Verantwortlichen vom 2026-11-25** am Bild des Dialogs: Das Formular kann übersichtlicher werden; die
vorhandenen Lagerorte sollen mit dem vorhandenen Bestand angezeigt werden; **„bau ein Grid mit ein“.**

**Gebaut (Modul `lager` 0.6.1, nur Oberfläche):**
- Oben die **Lagerorte als Tabelle** mit Sortierung und Filter am Spaltenkopf (F91): Lagerort, gekühlt, Artikel, Chargen,
  Zustand in Worten (mit Bestand · leer · stillgelegt).
- Darunter an festem Platz mit fester Höhe der **Bestand des gewählten Lagerorts**, je Charge eine Zeile: Artikel,
  Nummer, Charge, Verfall, Menge mit Einheit, Zustand der Charge; daneben die Summe in Worten.
- **Gewählter Lagerort:** Name und „gekühlt“ ändern („Speichern“), „Stilllegen“ bzw. „Wieder nutzen“. Bei Bestand ist
  „Stilllegen“ gesperrt und nennt den Grund.
- **Neuer Lagerort** wie bisher; der neue Ort ist danach gewählt.

| # | Festlegung aus der Umsetzung, zu bestätigen |
|---|---|
| LO1 | **Der Bestand eines Orts wird nicht summiert,** sondern gezählt (Artikel, Chargen): Mengen verschiedener Lagereinheiten lassen sich nicht addieren. Die Mengen stehen je Charge in der unteren Tabelle. |
| LO2 | **Die untere Tabelle ist nur zum Ansehen.** Umlagern, Entnehmen und Ausbuchen bleiben auf der Seite „Bestand“. |
| LO3 | **Umbenennen ist jetzt in der Oberfläche möglich** (die Schnittstelle konnte es schon). Der Name eines Lagerorts ist kein Teil einer Bewegung – ältere Bewegungen zeigen danach den neuen Namen; der alte steht im Protokoll. |
| LO4 | **Tabellen in Dialogen tragen hier Sortierung und Filter,** obwohl OBERFLAECHE.md T4 kleine Tabellen in Dialogen ausnimmt – auf ausdrücklichen Wunsch. Das Filtermenü öffnet sich innerhalb des rollenden Bereichs. |
| LO5 | **Die Filter des Bestands gelten für den gewählten Ort** und beginnen mit jedem anderen neu (wie O1). |

Y7 bleibt: Lagerorte sind eine flache Liste.

**Gemerkt, nicht gebaut (Verantwortlicher, 2026-11-25, 06:31, an 0.7.84): „Stilllegen geht nicht.“** Auf dem Bild hat
der gewählte Lagerort Bestand (eine abgelaufene Charge) – „Stilllegen“ ist deshalb gesperrt; das ist die Regel aus
Abschnitt 14 (ein Lagerort mit Bestand wird nicht stillgelegt). **Der Grund steht aber nur in der Hilfe beim Überfahren
und ist am gesperrten Knopf nicht zu sehen.** Beim nächsten Bau am Dialog zu klären und zu beheben: (1) den Grund sichtbar
machen – eine feste Zeile neben den Knöpfen, die sagt, warum „Stilllegen“ gesperrt ist und was zu tun ist („erst
umlagern oder ausbuchen“); (2) prüfen, ob der Knopf auch bei einem **leeren** Lagerort nicht geht – das wäre ein Fehler
und ist mit dem Bild nicht belegt; (3) ob ein Ort, an dem nur noch **abgelaufene** Ware liegt, anders zu behandeln ist
(Vorschlag: nein – auch sie ist Bestand, bis sie ausgebucht ist).

## 19. Eigenschaften des Artikels statt einer „Führung“ (Entwurf zur Freigabe, F208, P58 – nicht gebaut)

**Vorgabe des Verantwortlichen (2026-11-25, 10:48):** Die Fälle, in denen die Regel aus 17.10 zu eng ist – Charge und
Seriennummer zugleich, Geräte ohne Seriennummer, die feststehende Führung, Teile nur am Stück –, **„hängen eigentlich
alle bei der Anlage im Artikel. Es ist eher die Frage: Hat der Artikel diese oder jene Eigenschaft?“**

Heute trägt der Artikel **eine** Angabe „Führung“ mit drei Werten, die sich ausschließen: ohne Charge, mit Charge, mit
Seriennummer. An ihr hängen Zugang, Entnahme, Inventur, Rückverfolgung, die Worte der Oberfläche (17.10) und seit 0.7.88
die Teile eines Stücks. Der Vorschlag löst sie in einzelne Eigenschaften auf, die sich frei verbinden.

### 19.1 Die Eigenschaften

| Eigenschaft | Bedeutung | heute |
|---|---|---|
| **hat Charge** | Ware kommt in Losen des Herstellers; Zugang und Rückverfolgung nennen die Charge | Führung „mit Charge“ |
| **hat Seriennummer** | jedes Stück ist einzeln benannt; Menge je Zeile 1 | Führung „mit Seriennummer“ |
| **hat Verfallsdatum** (und: Pflicht) | der Bestand trägt ein Datum; Vorschlag nach frühestem Verfall | „Verfallsdatum ist Pflicht“, nur mit Charge |
| **wird in Stücken gezählt** | ganze Zahlen; die Oberfläche sagt „Stück“ – auch ohne Seriennummer | – (nur über die Lagereinheit) |
| **besteht aus Teilen** | hat eine Stückliste; sein Bestand kann Teile tragen | Stückliste (0.7.86), Teile nur mit Seriennummer (ZT1) |
| **Betäubungsmittel** | wird nicht im Lager geführt | vorhanden |

### 19.2 Entscheidungen

| # | Vorschlag |
|---|---|
| EG1 | **Die Eigenschaften stehen einzeln am Artikel** und werden beim Anlegen gewählt; „Führung“ als Auswahl entfällt in der Oberfläche. |
| EG2 | **Charge und Seriennummer zugleich sind erlaubt:** Jedes Stück hat seine Seriennummer und gehört zu einer Charge; Verfall und Sperre stehen an der Charge und gelten für alle ihre Stücke. Der Code der Packung liefert beides (GS1: 10 und 21) – heute geht eines verloren. |
| EG3 | **Die Art belegt vor** (Merkmalssatz je Art – STUECKLISTE.md WG1, AR1): Wer „Gerät“ wählt, bekommt Seriennummer und Stück vorgeschlagen, bei „Arzneimittel“ stehen Charge und Verfall fest. Der Nutzer kann abweichen, wo die Art es nicht verbietet; nichts wird still gesetzt (SA6). |
| EG4 | **Die Worte folgen den Eigenschaften, nicht einer Führung:** mit Seriennummer oder „in Stücken gezählt“ → „Stücke“; nur Charge → „Chargen“; beides → „Stücke“ mit den Spalten Seriennummer und Charge; keines → „Bestand“. Ersetzt SC1. |
| EG5 | **Ändern, auch wenn Bestand da ist:** Eine Eigenschaft **dazunehmen** geht jederzeit – vorhandener Bestand trägt dann „ohne Angabe“ und lässt sich nachtragen. **Wegnehmen** geht nur, solange kein Bestand sie trägt; sonst gesperrt mit Grund. „Die Führung steht fest“ entfällt. |
| EG6 | **Teile am Bestand:** an einem Stück mit Seriennummer wie gebaut (0.7.88); an einer **Charge** für Sets – die Teile gelten je Stück der Charge (Stufe S, STUECKLISTE.md 5.6, 9.4). An Bestand ohne Charge und ohne Seriennummer gibt es keine Teile – dort ist nichts, woran sie hängen könnten. Ändert ZT1. |
| EG7 | **Daten:** Die Zeile des Bestands (heute `lager.charge`) trägt Charge **und** Seriennummer als zwei Felder; `artikel.fuehrung` wird durch die Eigenschaften ersetzt. Migration: ohne → nichts, charge → hat Charge, serie → hat Seriennummer; kein Bestand ändert sich. |
| EG8 | **Folgen, je eigener Schritt:** Zugang und Wareneingang (beide Felder), Entnahme (Vorschlag nach Verfall der Charge, gewählt wird das Stück), Inventur (je Stück oder je Charge), Rückverfolgung (nach Charge: alle ihre Stücke; nach Seriennummer: ein Stück), Sperre (an der Charge für alle Stücke, am Stück für eines), Artikelgruppe (MA5: gleiche Einheit bleibt; unterschiedliche Eigenschaften in einer Gruppe sind erlaubt). |
| EG9 | **Schnittstelle:** `fuehrung` bleibt für eine Übergangszeit als abgeleiteter Wert lesbar; geschrieben werden die Eigenschaften. |
| EG10 | **Falsch erfasste Angaben berichtigen:** Seriennummer oder Charge einer Zeile lassen sich mit Grund berichtigen (Vorher und Nachher im Protokoll), statt ausbuchen und neu zubuchen (17.10, offen). |

**Reihenfolge, wenn freigegeben:** (1) EG7 mit EG1, EG4, EG5 – die Eigenschaften am Artikel, ohne neues Verhalten;
(2) EG2 – Charge und Seriennummer zugleich in Zugang, Entnahme, Rückverfolgung; (3) EG10; (4) EG3 mit den Arten (Stufe A2);
(5) EG6 mit den Sets (Stufe S). **Berührt:** LAGER.md Abschnitt 2 (WW5), 17, STUECKLISTE.md 4, 12; DATENMODELL.md.

