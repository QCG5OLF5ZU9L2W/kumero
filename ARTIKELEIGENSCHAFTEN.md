# ARTIKELEIGENSCHAFTEN.md – Welche Eigenschaften ein Artikel braucht (Recherche und Entwurf 2, nicht gebaut)

Stand: 2026-11-25. Bezug: LAGER.md Abschnitt 19 (Eigenschaften statt „Führung“, EG1–EG10), STUECKLISTE.md Abschnitt 4
(Arten), 6 (Produktangaben und Merkmale), 9.1 (Ebenen System, Mandant, Buch), 10 (Angaben wie in einer
Apotheken-Warenwirtschaft), KATALOG.md, ANLAGEN.md. Maßgeblich bleibt AGENTS.md.

**Auftrag des Verantwortlichen (2026-11-25, 10:50):** „Da fehlen noch mehr Eigenschaften am Artikel, Lagerbedingungen
und Co. Recherchiere das mal gründlich aus.“

**Status:** Recherche mit Quellen (Abschnitt 6) und ein Vorschlag, was davon wie an den Artikel kommt. **Nichts ist
gebaut. Entschieden am 2026-11-25, 10:56 (F210): AE3, AE4, AE5, AE9 – Abschnitt 5.1.** Was mit „nicht nachgeschlagen“ gekennzeichnet ist, stammt aus allgemeinem Wissen und ist
vor dem Bau zu prüfen. **Keine Rechtsberatung:** Welche Pflicht für welchen Betrieb gilt, prüft der Verantwortliche.

## 1. Was der Artikel heute trägt

Bezeichnung, eigene Artikelnummer, Art (vier feste Werte), Lagereinheit, Führung (ohne Charge, mit Charge, mit
Seriennummer), „Verfallsdatum ist Pflicht“, Betäubungsmittel (dann nicht geführt), GTIN, Mindest- und Sollbestand,
üblicher Lagerort, **Lagerbedingung als freier Text**, stillgelegt, Artikelgruppe, Stückliste. Der Lagerort trägt
„gekühlt“ – ohne dass kumero es mit der Lagerbedingung des Artikels vergleicht.

## 2. Der Grundgedanke: drei Sorten von Eigenschaften

| Sorte | Was sie ist | Beispiel |
|---|---|---|
| **wirksam** | kumero verhält sich danach: sperrt, warnt, schlägt vor, rechnet | hat Charge, Lagertemperatur, Aufbrauchfrist |
| **Auskunft** | steht da, wird gesucht, gefiltert, gedruckt – ändert kein Verhalten | Farbe, Schnittstellen, Latex |
| **Verweis** | zeigt auf etwas außerhalb des Artikels | Sicherheitsdatenblatt (Dokument), Katalog-Fassung, Lieferant |

Wirksame Eigenschaften müssen feste, geprüfte Felder sein; Auskunft kann ein freies Merkmal sein (STUECKLISTE.md PM);
Verweise sind eigene Gegenstände (Dokumente F166, Katalog F164). **Je Art gilt ein Merkmalssatz** (WG1): Er nennt, welche
Eigenschaften ein Artikel dieser Art trägt, welche Pflicht sind und welche feststehen.

## 3. Die Eigenschaften nach Gruppen

Spalte „Sorte“: W = wirksam, A = Auskunft, V = Verweis. Spalte „heute“: ✓ vorhanden, ~ teilweise, – fehlt.

### 3.1 Erkennen und Benennen

| Eigenschaft | Sorte | heute | Bemerkung |
|---|---|---|---|
| Bezeichnung, eigene Artikelnummer | A | ✓ | |
| GTIN (Produktkennung auf der Packung) | W | ✓ | Scannen im Wareneingang; **mehrere GTIN je Artikel** fehlen (jede Packungsebene hat eine eigene – 3.8) |
| PZN (Pharmazentralnummer) | W | – | Arzneimittel und viele Praxisartikel werden in Deutschland über die PZN bestellt; GS1 führt sie als zusätzliche Kennung neben der GTIN (Quelle 9) |
| Hersteller, Herstellernummer, Modell/Typ | A | – | STUECKLISTE.md 6.2; für Geräte Pflichtangaben des Bestandsverzeichnisses (3.6) |
| UDI-DI / Basis-UDI-DI | A | – | Kennung des Medizinprodukts in EUDAMED; die GTIN ist bei GS1-Kennzeichnung die UDI-DI (nicht nachgeschlagen) |
| Art (erweiterbar) und Warengruppe | W | ~ | STUECKLISTE.md 4, WG; rechtliche Produktart und betriebliche Warengruppe getrennt (F195) |

### 3.2 Zählen und Verfolgen (LAGER.md Abschnitt 19)

| Eigenschaft | Sorte | heute | Bemerkung |
|---|---|---|---|
| hat Charge · hat Seriennummer · in Stücken gezählt | W | ~ | heute eine „Führung“, künftig einzeln und verbindbar (EG1, EG2) |
| Lagereinheit, Bestell- und Verbrauchseinheit mit Umrechnung | W | ~ | Lagereinheit ✓; Umrechnung nur je Lieferantenartikel (Faktor). Fehlt: „1 Packung = 100 Stück“ am Artikel, Entnahme in der kleineren Einheit |
| teilbar (Dezimalmenge erlaubt) | W | – | ergibt sich heute nur aus der Eingabe |
| besteht aus Teilen | W | ✓ | Stückliste, Teile eines Stücks |

### 3.3 Haltbarkeit

| Eigenschaft | Sorte | heute | Bemerkung |
|---|---|---|---|
| hat Verfallsdatum, Pflicht | W | ✓ | |
| **Aufbrauchfrist nach Anbruch** (Zeitspanne) | W | – | Bei Mehrdosenbehältnissen nennt der Hersteller, wie lange das Mittel nach Anbruch haltbar ist; auf dem angebrochenen Behältnis muss das Anbruchdatum stehen (Quelle 12). Sie wird als Zeitspanne angegeben, nicht als Datum (Quelle 13). kumero bräuchte: „Anbruch“ als Vorgang an einer Charge, daraus das frühere von Verfall und Anbruch + Frist |
| **Lagerung nach Anbruch** (anders als vorher) | W | – | Die Lagertemperatur kann sich nach dem Öffnen ändern (Quelle 4) |
| Restlaufzeit beim Wareneingang (Mindestrest in Tagen) | W | – | Warnung, wenn Ware mit zu kurzem Verfall kommt (nicht nachgeschlagen, betriebliche Regel) |
| Warnfrist vor Verfall | W | ~ | heute eine Zahl für das ganze Buch; je Artikel fehlt |
| Art des Datums: Verfalldatum oder Mindesthaltbarkeit | A | – | Arzneimittel und Medizinprodukte: Verfall; Lebensmittel, manche Verbrauchsartikel: Mindesthaltbarkeit (nicht nachgeschlagen) |

### 3.4 Lagerbedingungen

Heute ein freier Text. Gefunden sind feste, überall gleich benannte Bereiche:

| Eigenschaft | Sorte | Werte | Quelle |
|---|---|---|---|
| **Temperaturbereich** | W | Raumtemperatur 15–25 °C (falls vermerkt bis 30 °C) · im Kühlschrank 2–8 °C · tiefgekühlt −15 °C oder kälter; selten „kühl“ 8–15 °C | 1, 2, 3 |
| **Kühlkette** (auch beim Transport ununterbrochen) | W | ja/nein – etwas anderes als „im Kühlschrank lagern“ | 4, 5 |
| nicht einfrieren | W | ja/nein – Kühlware darf nicht gefrieren | 2 |
| vor Licht schützen · vor Sonne schützen | A/W | ja/nein | 6 |
| vor Feuchtigkeit schützen / trocken lagern | A | ja/nein | 1 |
| in der Originalverpackung aufbewahren | A | ja/nein | 1 |
| unter Verschluss / getrennt (z. B. Betäubungsmittel, Gefahrstoff) | W | ja/nein, Art des Verschlusses | 14, 7 |
| aufrecht lagern, nicht stapeln, zerbrechlich | A | ja/nein | nicht nachgeschlagen |
| Medizinprodukte: Lagerungs- und Handhabungsbedingungen | A | Art aus einer festen Liste, dazu Beschreibung | 10 |

**Was kumero damit tun kann (Vorschlag):** Der Lagerort bekommt dieselben Angaben (Temperaturbereich, verschlossen,
Gefahrstofflager). Passt der Artikel nicht zum Lagerort – Kühlware in einen ungekühlten Schrank –, **warnt** der Dialog
bei Zugang und Umlagerung und nennt den Grund; ob gesperrt wird, entscheidet der Verantwortliche (AE4). Eine
Temperaturaufzeichnung der Kühlschränke ist nicht Teil dieses Entwurfs.

### 3.5 Gefahrstoff

| Eigenschaft | Sorte | Bemerkung | Quelle |
|---|---|---|---|
| Gefahrstoff ja/nein | W | Einstufung steht im Sicherheitsdatenblatt | 7 |
| **Lagerklasse (LGK) nach TRGS 510** | W | Jeder Gefahrstoff hat genau eine Lagerklasse; sie dient nur der Entscheidung, was zusammen gelagert werden darf. Der Lieferant nennt sie im Sicherheitsdatenblatt (Unterabschnitt 7.2 oder Abschnitt 15) | 7, 8 |
| Menge je Lagerabschnitt | W | Die Regeln der Zusammenlagerung gelten erst oberhalb kleiner Mengen (genannt: bis 400 kg gesamt, höchstens 200 kg je Lagerklasse) – für eine Praxis meist nicht erreicht; kumero könnte die Summe je Lagerort zeigen | 8 |
| Signalwort, Piktogramme, H- und P-Sätze (GHS/CLP) | A | aus dem Etikett bzw. Sicherheitsdatenblatt | nicht nachgeschlagen |
| Wassergefährdungsklasse, UN-Nummer (Transport) | A | | nicht nachgeschlagen |
| **Sicherheitsdatenblatt** | V | Dokument am Artikel (LAGER.md Abschnitt 15); „fehlt“ als Hinweis | 7 |
| Betriebsanweisung vorhanden | V | Dokument | nicht nachgeschlagen |

Nach KATALOG.md gehört das Kennzeichen „Gefahrstoff“ in die Katalog-Fassung (LAGER.md AD14).

### 3.6 Medizinprodukt und Gerät

| Eigenschaft | Sorte | Bemerkung | Quelle |
|---|---|---|---|
| Risikoklasse | A | Angabe zur Basis-UDI-DI | 11 |
| als Einmalprodukt gekennzeichnet · höchste Zahl der Wiederverwendungen | W | Mit begrenzter Zahl könnte kumero Aufbereitungszyklen je Stück zählen (Siebe, STUECKLISTE.md 9.4) | 10, 11 |
| steril geliefert · vor Verwendung zu sterilisieren | A | | 11 |
| enthält Latex | A | Warnhinweis bei der Entnahme denkbar | 10, 11 |
| CMR-Stoffe / hormonstörende Stoffe | A | | 10, 11 |
| wichtige Warnhinweise und Kontraindikationen | A | Art aus einer festen Liste, dazu Beschreibung | 10 |
| klinische Größe (z. B. Länge, Durchmesser, Volumen) | A | Wert, Bereich oder Text mit Einheit | 10 |
| **aktives Medizinprodukt** (auf Energiequelle angewiesen) | W | Dann Bestandsverzeichnis: Bezeichnung, Art und Typ, Loscode oder Seriennummer, Anschaffungsjahr; Hersteller mit Anschrift; betriebliche Identifikationsnummer; Standort und betriebliche Zuordnung | 15 |
| Prüfpflichten: sicherheitstechnische Kontrolle, messtechnische Kontrolle, jeweils Frist | W | Fristen als Termine am Stück – gehört zu den Anlagen (Gerät in Nutzung), nicht ins Lager | 16, 17 |
| Prüfung ortsveränderlicher elektrischer Geräte (DGUV Vorschrift 3) | W | wird in der Praxis im selben Verzeichnis mitgeführt | 17 |
| Gebrauchsanweisung | V | muss dem Anwender zugänglich sein – Dokument am Artikel | 16 |

kumero führt weder Medizinproduktebuch noch Bestandsverzeichnis (LAGER.md WW18, IN8); die Angaben oben wären die
Grundlage, es später daraus zu erzeugen. **Das bleibt eine eigene Entscheidung.**

### 3.7 Arzneimittel

| Eigenschaft | Sorte | Bemerkung |
|---|---|---|
| Darreichungsform, Wirkstoff, Stärke, Packungsgröße (N1–N3) | A | STUECKLISTE.md 10 |
| ATC-Code | A | F195 |
| Verkaufsabgrenzung (freiverkäuflich, apothekenpflichtig, verschreibungspflichtig, Betäubungsmittel, T-Rezept) | W | F195; Betäubungsmittel führt kumero nicht |
| dokumentationspflichtig bei Anwendung (z. B. Blutprodukte nach dem Transfusionsgesetz) | W | Chargendokumentation je Patient liegt außerhalb von kumero (kein Patientenbezug) – nur Kennzeichen (Quelle 18) |
| Zytostatikum / CMR-Arzneimittel | W | wirkt auf die Entsorgung (3.10) |
| Impfstoff | A | Kühlkette, Chargendokumentation – nur Kennzeichen |
| Sprechstundenbedarf oder Praxisbedarf | A | Kostenträger der Beschaffung (nicht nachgeschlagen) |

### 3.8 Packung und Maße

| Eigenschaft | Sorte | Bemerkung |
|---|---|---|
| **Packungsebenen** mit je eigener GTIN und Inhalt (Stück → Schachtel → Karton) | W | GS1 kennt die Verpackungshierarchie und „Menge je Packung“ als Stammdaten (Quelle 9, 11); löst „Schachtel gescannt, 100 Stück zugebucht“ |
| Nettoinhalt, Abmessungen, Bruttogewicht | A | GS1-Stammdaten (Quelle 9) |
| Farbe, Größe, Material, Schnittstellen, Lieferumfang | A | freie Merkmale; Lieferumfang ist die Stückliste (STUECKLISTE.md 6.2) |

### 3.9 Beschaffen

| Eigenschaft | Sorte | heute | Bemerkung |
|---|---|---|---|
| Mindest- und Sollbestand | W | ✓ | |
| Lieferant(en) mit dessen Artikelnummer und Faktor | W | ~ | wird beim Wareneingang gemerkt; nicht am Artikel pflegbar |
| bevorzugter Lieferant, Bestelleinheit, Mindestbestellmenge, Lieferzeit in Tagen | W | – | Nachbestellliste könnte Menge runden und den Bestelltermin nennen |
| letzter Einkaufspreis, Preiseinheit | A | ~ | aus den Zugängen |
| Kostenstelle / Verwendungsbereich | A | – | STUECKLISTE.md WG |
| Vorratskonto, Aufwandskonto abweichend vom Buch | W | ~ | heute je Buch |

### 3.10 Entsorgen

| Eigenschaft | Sorte | Bemerkung | Quelle |
|---|---|---|---|
| **Abfallschlüssel (AVV)** | W | Für Praxen genannt: 18 01 01 spitze oder scharfe Gegenstände; 18 01 03* infektiös; 18 01 04 ohne besondere Anforderungen; 18 01 08* zytotoxische und zytostatische Arzneimittel; 18 01 09 andere Arzneimittel. Stern = gefährlicher Abfall | 19, 20, 21 |
| Entsorgungshinweis (durchstichsicherer Behälter, nicht ins Abwasser) | A | Text; bei „Ausbuchen – vernichtet“ zeigen | 19 |
| Elektroaltgerät / Batterie | A | eigene Rücknahmewege | 22 |

### 3.11 Abgeben und Verkaufen (nur geplant, F196)

Verkaufspreis, Umsatzsteuersatz, Preisbindung, Abgabebeschränkung – erst mit FAKTURA.md; hier nur genannt, damit der
Merkmalssatz Platz dafür hat.

## 4. Was davon zuerst Nutzen bringt

1. **Lagerbedingungen als feste Angaben** (3.4) an Artikel und Lagerort, mit Warnung bei Zugang und Umlagerung – ersetzt
   den freien Text; für Kühlware der größte Schritt.
2. **Aufbrauchfrist und Anbruch** (3.3).
3. **Packungsebenen mit GTIN und Inhalt** (3.8) und **PZN** (3.1) – weniger Tippen im Wareneingang.
4. **Gefahrstoff mit Lagerklasse und Sicherheitsdatenblatt** (3.5) – braucht die Dokumente am Artikel (P47, P57).
5. **Abfallschlüssel** (3.10) am Ausbuchen.
6. **Angaben des Medizinprodukts** (3.6) als Auskunft; Prüffristen mit den Anlagen.

## 5. Entscheidungen

| # | Frage | Vorschlag |
|---|---|---|
| AE1 | Drei Sorten (wirksam, Auskunft, Verweis)? | Ja – wirksam nur als feste, geprüfte Felder; Auskunft als Merkmal; Verweis als eigener Gegenstand. |
| AE2 | Wo steht, welche Eigenschaften ein Artikel trägt? | Im Merkmalssatz der Art (WG1), auf den Ebenen System, Mandant, Buch (9.1). kumero liefert Sätze für die Grundarten mit. |
| AE3 | Lagerbedingungen: feste Liste statt freiem Text? | **Entschieden 2026-11-25: Das hängt am Artikel** – die Lagerbedingungen sind Eigenschaften, die beim Anlegen des Artikels gewählt werden (5.1). Vorschlag zur Ausführung: die Bereiche aus 3.4; der bisherige Text bleibt als Bemerkung stehen. |
| AE4 | Artikel passt nicht zum Lagerort: warnen oder sperren? | **Entschieden 2026-11-25: Warnen reicht.** Kein Sperren, auch nicht einschaltbar. |
| AE5 | Aufbrauchfrist: „Anbruch“ als eigener Vorgang? | **Entschieden 2026-11-25: Auch das hängt an der Anlage des Artikels** – in einer Charge kann es eigene Elemente geben, die einzeln entnommen werden, muss es aber nicht. Lesart und Ausführung in 5.1, **zu bestätigen**. |
| AE6 | Packungsebenen am Artikel? | Ja, n Ebenen mit GTIN und Inhalt; der Lieferantenartikel mit Faktor bleibt daneben. |
| AE7 | Was kommt in den Katalog (über alle Mandanten)? | Alles, was das Produkt beschreibt (3.1, 3.3–3.8, 3.10); im Buch bleibt, was der Betrieb festlegt (3.9, Lagerort, Warengruppe). |
| AE8 | Woher kommen die Angaben? | Von Hand; später aus dem Code der Packung und aus E-Rechnungen. **Kein Abruf bei einem Dienst außerhalb** (CLAUDE.md Grenzen) – ein Import aus einer Datei, die der Betrieb selbst beschafft, wäre ein eigener Entwurf. |
| AE9 | Medizinproduktebuch und Bestandsverzeichnis aus kumero? | **Entschieden 2026-11-25: „Warum eigentlich nicht“ – ja, gewollt.** Das hebt LAGER.md WW18 und IN8 in diesem Punkt auf; es braucht einen eigenen Entwurf (5.1) und ist nicht Teil der ersten Schritte. |
| AE10 | Reihenfolge | wie Abschnitt 4; jeweils ein Release; zuerst LAGER.md Abschnitt 19 (Eigenschaften statt Führung), weil 3.2 darauf aufbaut. |

### 5.1 Entscheidungen vom 2026-11-25, 10:56 (F210) – und wie sie gelesen sind

**AE3 – „hängt am Artikel“.** Die Lagerbedingungen sind Eigenschaften des Artikels wie Charge und Seriennummer
(LAGER.md Abschnitt 19): gewählt beim Anlegen, vorbelegt durch die Art, nachträglich änderbar (eine Bedingung ändert
keinen Bestand). Der Lagerort trägt dieselben Angaben nur, damit kumero vergleichen und **warnen** kann (AE4).

**AE5 – Elemente in der Charge.** Gelesen: Ob ein Artikel „angebrochen“ werden kann, ist eine Eigenschaft des Artikels.

| # | Lesart – **zu bestätigen** |
|---|---|
| AB1 | **Eigenschaft „besteht aus Gebinden, die einzeln angebrochen werden“** (Flasche, Tube, Durchstechflasche, Packung mit Einzelstücken). Ohne sie ändert sich nichts: Die Charge ist eine Menge, entnommen wird davon. |
| AB2 | **Mit ihr ist das Gebinde ein eigenes Element der Charge:** Der Zugang nennt die Zahl der Gebinde und den Inhalt je Gebinde (10 Flaschen zu 100 ml). Der Bestand der Charge ist die Summe ihrer Gebinde. |
| AB3 | **„Anbrechen“ ist ein Vorgang an einem Gebinde:** mit Datum; ab dann gilt für dieses Gebinde das frühere von Verfall der Charge und Anbruch plus Aufbrauchfrist, und – wenn am Artikel so gewählt – die Lagerbedingung nach Anbruch. Die übrigen Gebinde der Charge bleiben unberührt. |
| AB4 | **Entnommen wird aus einem angebrochenen Gebinde zuerst;** ein ganzes Gebinde zu entnehmen bleibt möglich (Abgabe einer Flasche). Ist ein Gebinde leer, verschwindet es aus dem Bestand; ist seine Aufbrauchfrist um, steht es als „abgelaufen – nur ausbuchen“ da, die Charge nicht. |
| AB5 | **Kennzeichnung des Gebindes:** kumero vergibt je angebrochenem Gebinde eine kurze laufende Nummer zum Aufschreiben auf das Etikett – ein Gebinde hat sonst nichts, woran man es erkennt. Bei Artikeln mit Seriennummer ist das Stück das Gebinde. |
| AB6 | **Aufbrauchfrist, Lagerung nach Anbruch, Inhalt je Gebinde** sind Eigenschaften des Artikels und nur wählbar, wenn AB1 gewählt ist. |
| AB7 | **Entschieden 2026-11-25, 11:00 (F211): Beim Anbrechen wird gefragt, ob das Gebinde als entnommen zu werten ist.** Gelesen: „ja“ bucht das ganze Gebinde als Entnahme (es verlässt den Bestand, sein Rest wird nicht weiter geführt); „nein“ lässt es im Bestand und führt den Rest mit Anbruchdatum und Aufbrauchfrist (AB3, AB4). Die Vorgabe der Antwort könnte eine Eigenschaft des Artikels sein – **bitte sagen**. |

**AE9 – Medizinproduktebuch und Bestandsverzeichnis.** Gewollt. Vorschlag: eigener Entwurf, sobald die Eigenschaften aus
3.6 am Artikel und die Stücke in den Anlagen stehen (STUECKLISTE.md Stufe B1) – das Bestandsverzeichnis als Auswertung
über aktive Medizinprodukte mit Standort, das Medizinproduktebuch als Verlauf am Stück (Einweisung, Kontrollen,
Störungen). Was die Verordnung im Einzelnen verlangt, ist dafür im Wortlaut zu lesen; hier ist nur § 14 gelesen.

**Reihenfolge, die sich daraus ergibt:** (1) Eigenschaften statt „Führung“ (LAGER.md Abschnitt 19 – **EG1–EG10 sind
noch nicht freigegeben**); (2) Lagerbedingungen am Artikel und am Lagerort mit Warnung (AE3, AE4); (3) Gebinde und
Anbruch (AB1–AB6); danach Packungsebenen und PZN, Gefahrstoff, Abfallschlüssel; AE9 mit den Anlagen.

## 6. Quellen (gelesen am 2026-11-25)

1. Bundesgesundheitsministerium, „Arzneimittel richtig aufbewahren und entsorgen“ – https://www.bundesgesundheitsministerium.de/themen/krankenversicherung/arzneimittelversorgung/aufbewahrung-und-entsorgung.html
2. apotheke adhoc, „Arzneimittel-Lagerung: Welche Temperaturbereiche gibt es?“ – https://www.apotheke-adhoc.de/nachrichten/detail/apothekenpraxis/arzneimittel-lagerung-welche-temperaturbereiche-gibt-es/
3. Pharmazeutische Zeitung, Lagerung in der Apotheke – https://www.pharmazeutische-zeitung.de/index.php?id=66276
4. apotheke adhoc, „Arzneimittel mögen’s kalt: Lagerbedingungen“ – https://www.apotheke-adhoc.de/nachrichten/detail/apo-tipp/arzneimittel-moegens-kalt-lagerbedingungen/
5. Arzneimittelkommission der deutschen Ärzteschaft, „Arzneimittel und Hitze“ – https://www.akdae.de/arzneimitteltherapie/arzneiverordnung-in-der-praxis/ausgaben-archiv/ausgaben-ab-2015/ausgabe/artikel/2020/2020-03-04/arzneimittel-und-hitze-temperaturempfindlichkeit-und-richtige-aufbewahrung-von-arzneimitteln
6. PTA IN LOVE, Zahlen aus dem Abdata-Artikelstamm – https://www.pta-in-love.de/?p=149954
7. TRGS 510, Abschnitt 13 – https://vorschriften.bgn-branchenwissen.de/daten/tr/trgs510/13.htm
8. TRGS 510, Anhang 2 – https://vorschriften.bgn-branchenwissen.de/daten/tr/trgs510/anh2.htm ; Kleinmengen: https://www.komnet.nrw.de/_sitetools/dialog/13282
9. GS1, GDSN Trade Item Implementation Guideline – https://ref.gs1.org/guidelines/tiig/ ; Datenbereitstellung Gesundheitswesen (PZN als zusätzliche Kennung): https://www.eClass.eu/fileadmin/Redaktion/pdf-Dateien/Wiki/GS1XML/GS1XMLCIN_Datenbereitstellung_v05.pdf
10. EUDAMED-Hilfe, „UDI-DI characteristics“ – https://webgate.ec.europa.eu/eudamed-help/en/actor/topics-per-actor/eu-manufacturer/registration-of-a-basic-udi-di-together-with-a-udi-di-of-a-regulation-device/udi-di-characteristics.html
11. Europäische Kommission, „Basic UDI-DI & UDI-DI attributes“ – https://ec.europa.eu/docsroom/documents/35241/attachments/1/translations/en/renditions/native ; VDE, UDI nach MDR – https://www.vde.com/topics-de/health/beratung/udi-fuer-medizinprodukte-nach-mdr
12. Bioscientia ZHI, Einzeldosis- und Mehrdosenbehältnisse – https://www.zhi.bioscientia.de/media/cvebpwc0/221118_hfdm-dezember_einzeldosis-und-mehrdosisbehaeltnisse.pdf
13. Zentrallaboratorium Deutscher Apotheker, Praxishilfe Kennzeichnung – https://zentrallabor.com/pdf/Praxishilfe_Kennzeichnung_Herausforderungen.pdf
14. ABDA, Arbeitsbogen Lagerung – https://abda.de/fileadmin/user_upload/assets/Ausbildung_Studium_Beruf/Leitfaden_3._Abschnitt/LF_3._Abschn_Anl_2_AB_01_Lagerung_24_02_29.pdf
15. MPBetreibV § 14 Bestandsverzeichnis – https://www.gesetze-im-internet.de/mpbetreibv_2025/__14.html
16. MPBetreibV § 13 Medizinproduktebuch – https://www.gesetze-im-internet.de/mpbetreibv_2025/__13.html ; Regierungspräsidium Tübingen, Infoblatt MTK – https://rpt.baden-wuerttemberg.de/fileadmin/RP-Internet/Tuebingen/Abteilung_10/_DocumentLibraries/Infoblatt_MTK_Nachpruef-undBetreiberpflichten.pdf
17. Gesundheit Bremen, Broschüre Medizinprodukte – https://www.gesundheit.bremen.de/sixcms/media.php/13/20190611_Brosch%25C3%25BCre_MP-Aufbereitung.pdf
18. Liste dokumentationspflichtiger Arzneimittel und Medizinprodukte (Hinweis darauf) – https://www.lehmanns.ch/media/100265013
19. Stadt Regensburg, Infoblatt Praxisabfälle – https://www.regensburg.de/fm/121/umwelt-infoblatt-paxisabfaelle-122020.pdf
20. Umweltbundesamt, Stoffstrom Medizin – https://www.umweltbundesamt.de/system/files/medien/377/dokumente/stoffstrom_medizin_med.pdf
21. TRBA 250 Anhang 8, Abfallschlüssel – https://vorschriften.bgn-branchenwissen.de/daten/tr/trba250/anh8.htm
22. LAGA-Mitteilung 18, Vollzugshilfe (Inhaltsverzeichnis) – https://www.laga-online.de/documents/laga-m-18_stand_2021-06-23_1626849905.pdf

**Nicht gelesen und für den Bau nötig:** die Feldliste des ABDA-Artikelstamms im Original (nicht frei zugänglich), die
EUDAMED-Codelisten für Lagerbedingungen und Warnhinweise, die Fristen der Anlagen 1 und 2 der MPBetreibV, CLP-Verordnung
und ADR, das Europäische Arzneibuch im Wortlaut (die Temperaturbereiche oben stammen aus Sekundärquellen).
