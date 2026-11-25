# STUECKLISTE.md – Artikelgruppe nacharbeiten; Art des Artikels; Artikel aus mehreren Teilen; Produktangaben (Entwurf 7: Artikelgruppe gebaut mit 0.7.83; alles andere zur Freigabe)

Stand: 2026-11-25. Bezug: LAGER.md Abschnitt 2, 15.6, 16 (Gegenstände für den Eigenbedarf, F165) und 17
(Artikelgruppen, F168/F169), KATALOG.md (KA3, KA7, KA9, KA17, KA20), ANLAGEN.md (Abschnitt 9 „Zusammengehörige Güter“,
Abschnitt 11), F31, F44, F106, F139, F166, **F189, F190**, P40, **P54**. Maßgeblich bleibt AGENTS.md.
**Status: Gebaut mit kumero 0.7.83 (Modul `lager` 0.6.0) ist allein die Artikelgruppe – Abschnitt 2 und 3 (AG1–AG8),
Auftrag des Verantwortlichen vom 2026-11-25 „dann bau mal für kumero, beachte alle Vorgaben“; Stand und Festlegungen in
Abschnitt 8. Alles andere ist Entwurf und nicht gebaut.** Entschieden am 2026-11-25: die Vorgaben aus Abschnitt 1, **AG1,
AG7, AG8, AR1, ST3, ST6, ST7, ST10, ST13, ST16, ST17, ST19 (dass es beides gibt), PM1, PM3 und PM9** sowie **die drei
Ebenen System, Mandant und Buch für Arten und Merkmale (AR3, PM3)**. Alles andere sind Vorschläge.

Entwurf 2 hat die flache Liste durch den Baum ersetzt und AG8 aufgenommen; Entwurf 3 ergänzt Abschnitt 4 (die Art des
Artikels ist erweiterbar). Entwurf 4 nimmt die Antworten vom 2026-11-25 auf: Module sind eigene Gegenstände mit
Versionen, die zwischen Geräten wandern (5.2, 5.3); Wert bei Umbau und Modernisierung (5.5); Sets im Lager (5.6).
Entwurf 5 ergänzt Abschnitt 6 (Produktangaben und Merkmale). Entwurf 6 hält den Bau der Artikelgruppe fest (Abschnitt 8),
PM9 als entschieden und berichtigt Abschnitt 3 am Code. **Entwurf 7 nimmt die Antworten vom 2026-11-25, 06:02 auf
(Abschnitt 9, F190):** Arten und Merkmale auf drei Ebenen, die Version als Angabe dessen, der herstellt, Verträglichkeit je
Version, der Wert wie in 5.5 gelesen, Sets in beiden Formen. **Die Abschnitte 4, 5.3, 5.6 und 6 sind dort berichtigt, wo
Abschnitt 9 es sagt – bei Widerspruch gilt Abschnitt 9.**

**Nachgezogen mit 0.7.83:** AGENTS.md (F189, P54), LAGER.md (Abschnitt 17.6), KATALOG.md und ANLAGEN.md (Verweis hierher),
DATENMODELL.md, CLAUDE.md, UEBERGABE.md. Die Entwürfe der Abschnitte 4 bis 6 ändern KATALOG.md, ANLAGEN.md und LAGER.md
erst, wenn sie freigegeben und gebaut sind.

> **Keine Steuerberatung.** Abschnitt 5.5 nennt, wie kumero rechnet, nicht, was steuerlich richtig ist. Ob Teile ein
> Wirtschaftsgut bilden oder mehrere, entscheidet der Mensch; der Verantwortliche prüft die Lesart, bevor gebaut wird.

## 1. Anlass

Vorgaben des Verantwortlichen vom 2026-11-25 an den Dialogen „Artikelgruppe anlegen“ und „Artikel anlegen“ und an der
Seite „Bestand“ (kumero 0.7.73):

1. **Die Artikelgruppe hat eine Aufgabe, die mit beschrieben werden muss** – als eigenes Feld (AG1).
2. **Die Maske ist leicht chaotisch aufgebaut.**
3. **Ein Artikel kann auch aus einer Stückliste bestehen; das muss beschrieben und eingebaut werden.** Beispiel: ein
   Handscanner. Er wird nicht ins Lager eingebucht, sondern ist in Nutzung, besteht aber aus mindestens drei Teilen –
   Scanner, Ladeschale (Cradle) und Kabel. Das muss abbildbar sein.
4. **Es gibt nicht nur medizinische Artikel, sondern auch normale.** Beschreibung, Beispiele und Platzhalter dürfen
   nicht so tun, als ginge es nur um Medizinprodukte.
5. **Ist eine Artikelgruppe markiert, führt „Artikel neu …“ immer gleich in diese Gruppe** (AG8).
6. **Stücklisten sind vollständige Bäume wie ein Dateisystem:** Ein Gerät kann aus mehreren Modulen bestehen, jedes
   Modul aus weiteren Modulen, und die Module bestehen ihrerseits aus Teilen (ST3).
7. **Die Art des Artikels muss erweiterbar sein** (AR1) – heute sind es fest Medizinprodukt, Arzneimittel, Verbrauch
   und sonstiges.
8. **Ob ein Modul erforderlich ist, hängt am Modul:** Module können auch woanders genutzt werden oder optional sein (ST6).
9. **Module wandern grundsätzlich auch zwischen Geräten – aber mit Versionierung der Module** (ST13).
10. **Der Wert ist eine Frage von Zu- und Abschreibungen bei Änderungen oder Modernisierungen** (ST7).
11. **Sets als Lagerware gibt es:** Sterilgut für Eingriffe besteht immer aus diversen Kleinteilen – Skalpelle, Zangen,
    Tupfer und so weiter (ST10).
12. **Produktangaben, wie ein Händler sie nennt, sollen mit erfassbar sein** (PM1, Abschnitt 6).

## 2. Artikelgruppe: die Aufgabe

Eine Artikelgruppe ist eine Funktion, kein Produkt (LAGER.md 17.1). Diese Funktion steht heute nur im Namen. Die Gruppe
bekommt das Feld **„Aufgabe“**: wofür die Artikel der Gruppe dienen und was ein Artikel können muss, um dazuzugehören.
Die Aufgabe ist der Maßstab, nach dem jemand entscheidet, ob ein neues Produkt die anderen ersetzen darf.

Beispiele, bewusst gemischt (Vorgabe 4):

| Bezeichnung | Aufgabe |
|---|---|
| Kopierpapier A4 | weiß, 80 g/m², für alle Drucker und Kopierer im Haus |
| Batterie AA | Mignon 1,5 V, nicht wiederaufladbar; für Messgeräte und Fernbedienungen |
| Müllbeutel 60 l | für die Abfalleimer in den Behandlungs- und Büroräumen, reißfest |
| Einmalhandschuh Nitril M | puderfrei, unsteril, Größe M; für Untersuchung und Reinigung |

| # | Frage | Stand |
|---|---|---|
| AG1 | Was heißt „Aufgabe“? | **Entschieden 2026-11-25: ein eigenes Feld an der Gruppe.** Vorschlag zur Ausführung: Text, höchstens 500 Zeichen. |
| AG2 | Pflicht? | Vorschlag: ja, beim Anlegen. Gruppen, die es schon gibt, bleiben gültig und tragen den Zustand „Aufgabe fehlt“ in Worten, bis sie nachgetragen ist; nichts wird gesperrt. |
| AG3 | Wo ist die Aufgabe zu sehen? | Vorschlag: Dialog der Gruppe; auf „Bestand“ im Kopf der Untertabelle hinter dem Dreieck und in der Kopfzeile der unteren Hälfte; **im Dialog „Artikel“ neben der Auswahl der Gruppe** (dort fällt die Entscheidung „gehört dazu“); im Dialog „Entnahme“; auf der Nachbestellliste (Bildschirm, Druck, CSV). |
| AG4 | Inhalt | Vorschlag: freier Text zu Sachen, nie zu Personen; der Dialog sagt das. Änderungen stehen mit Vorher und Nachher im Protokoll (F48). Die Aufgabe steht im Buch, nicht im Katalog (LAGER.md MA3). |
| AG5 | Nicht nur Medizin | MA2 lässt Gruppen schon für „Verbrauch“, „sonstiges“ und Medizinprodukte ohne Seriennummer zu – daran ändert sich nichts (für eigene Arten: AR4). Geändert werden die Texte: Der Platzhalter der Bezeichnung nennt ein gewöhnliches Beispiel („z. B. Kopierpapier A4“), der erklärende Satz bleibt ohne Bezug auf eine Art. |

## 3. Maske „Artikelgruppe“ und der Weg in die Gruppe

**Was auf dem Bild des Dialogs stört:**
- „Bezeichnung“ sitzt tiefer als „Lagereinheit“, weil nur eines der beiden Felder einen Hinweis darunter hat.
- Die Felder sind schmal; der Platzhalter der Bezeichnung ist abgeschnitten.
- Hinweise stehen mal unter dem Feld, mal als eigener Absatz zwischen Feldern und Haken.
- „stillgelegt“ sieht beim Anlegen bedienbar aus. *(Berichtigt am Code: Das Feld war schon gesperrt und nannte den
  Grund; auf dem Bild war das nicht zu erkennen.)*
- Die Artikel der Gruppe, um die es eigentlich geht, kommen im Dialog nicht vor.

**Regeln (AG6):** ein Raster für den ganzen Dialog; Beschriftung über dem Feld; unter jedem Feld eine reservierte Zeile
für den Hinweis, auch wenn sie leer ist (F106 – nichts verschiebt sich); von oben nach unten: was es ist, wofür es
dient, wie es gezählt wird, was dazugehört, Zustand. Dieselbe Ordnung wie der Dialog „Artikel“ (KATALOG.md Abschnitt 4).

```
┌ Artikelgruppe anlegen ────────────────────────────────────────────────────────┐
│ Eine Artikelgruppe fasst Artikel zusammen, die dieselbe Aufgabe erfüllen und   │
│ einander ersetzen. Gezählt wird die Summe; entnommen wird immer ein Artikel.   │
│                                                                                │
│ Bezeichnung                                                                    │
│ [ z. B. Kopierpapier A4                                                      ] │
│                                                                                │
│ Aufgabe                                                                        │
│ [ wofür die Artikel dienen und was einer können muss, um dazuzugehören       ] │
│ [                                                                            ] │
│ kein Bezug auf Personen                                              0 / 500   │
│                                                                                │
│ Lagereinheit            Mindestbestand            Sollbestand                  │
│ [ Stück            ]    [                    ]    [                    ]       │
│ gilt für alle Artikel   darunter wird             bis dahin wird               │
│ der Gruppe              nachbestellt              nachbestellt                 │
│                                                                                │
│ Artikel der Gruppe                        [Artikel zuordnen …] [Lösen]         │
│ ┌────────────────────────────────────────────────────────────────────────────┐ │
│ │ Artikel                  Hersteller            verfügbar    nächster Verfall│ │
│ │ (gesperrt: erst nach dem Anlegen)                                           │ │
│ └────────────────────────────────────────────────────────────────────────────┘ │
│                                                                                │
│ [ ] stillgelegt   (gesperrt: erst nach dem Anlegen)                            │
│                                                          [Abbrechen] [Anlegen] │
└────────────────────────────────────────────────────────────────────────────────┘
```

| # | Frage | Stand |
|---|---|---|
| AG6 | Ordnung der Maske | Vorschlag: wie gezeichnet. Bezeichnung und Aufgabe über die ganze Breite; Einheit, Mindest- und Sollbestand in einer Zeile mit drei gleich breiten Feldern; Hinweise immer unter dem Feld. Der Dialog ist beim Anlegen und beim Bearbeiten gleich hoch. **Der Dialog „Artikel anlegen“ hat dieselben Mängel** (Felder auf verschiedenen Höhen, Hinweise mal unter, mal neben dem Feld) und bekommt dasselbe Raster – einmal gezeichnet für Katalog, Dokumente, Gruppe und Art (LAGER.md 15.9 Nr. 5). |
| AG7 | Liste „Artikel der Gruppe“ | **Entschieden 2026-11-25: ja, als Komfortmerkmal – im Dialog der Gruppe wird auch zugeordnet und gelöst.** Fester Platz, feste Höhe, die Liste rollt; beim Anlegen gesperrt mit Grund. „Artikel zuordnen …“ zeigt nur Artikel, die in diese Gruppe dürfen (gleiche Lagereinheit, ohne Seriennummer, kein Arzneimittel – MA5, MB1), und sagt beim Zuordnen, dass der eigene Mindest- und Sollbestand des Artikels entfällt (MB2). Der Dialog des Artikels behält seine Auswahl; es ist derselbe Endpunkt. |
| AG8 | „Artikel neu …“ bei markierter Gruppe | **Entschieden 2026-11-25: Ist eine Artikelgruppe markiert, führt „Artikel neu …“ immer gleich in diese Gruppe.** Vorschlag zur Ausführung: Der Dialog „Artikel anlegen“ öffnet mit der Gruppe vorgewählt und ihrer Lagereinheit vorbelegt; Mindest- und Sollbestand sind gesperrt mit Grund (gelten an der Gruppe). Wählt der Nutzer eine Art oder Führung, die nicht in eine Gruppe darf (Arzneimittel, Seriennummer), ist „Anlegen“ gesperrt und nennt den Grund – die Gruppe wird nie still entfernt. Die Vorwahl lässt sich im Dialog ändern. **Dasselbe gilt, wenn ein Artikel der Gruppe hinter dem Dreieck markiert ist** (entschieden 2026-11-25). Nach dem Anlegen bleibt die Gruppe markiert und ist aufgeklappt. Ändert MB8 nicht: Dort geht es um die Vorbelegung einer neuen Gruppe. |
| – | „stillgelegt“ | Beim Anlegen gesperrt mit Grund; beim Bearbeiten gesperrt, solange die Gruppe Artikel hat (MA13), ebenfalls mit Grund. Nicht ausgeblendet (F106). |

## 4. Art des Artikels: erweiterbar

### 4.1 Was die Art heute tut

Die Art ist heute eine feste Liste mit vier Werten – und sie ist nicht nur ein Etikett. An ihr hängen Regeln:

| Regel | hängt heute an |
|---|---|
| „Verfallsdatum ist Pflicht“ vorbelegt (WW5) | Medizinprodukt, Arzneimittel |
| Führung „mit Charge“ fest, Pflichtangaben (P40, KA17) | Arzneimittel |
| nie in einer Artikelgruppe (MA2, MB1) | Arzneimittel |
| Hinweis auf fehlende Gebrauchsanweisung bzw. Gebrauchsinformation (LAGER.md 15.6, AD14) | Medizinprodukt, Arzneimittel |
| Filter und Spalte „Art“ auf „Bestand“ | alle |

Wird die Liste einfach frei erweiterbar, weiß das Lager bei einer neuen Art nicht, welche dieser Regeln gelten.

### 4.2 Vorschlag: eigene Arten mit einer Grundart

- **Grundarten** sind die vier vorhandenen: Medizinprodukt, Arzneimittel, Verbrauch, sonstiges. Sie bleiben fest, weil
  die Regeln aus 4.1 an ihnen hängen.
- **Eine eigene Art** ist ein Name mit einer Grundart: „Bürobedarf“ (Verbrauch), „Reinigungsmittel“ (Verbrauch),
  „Laborbedarf“ (Verbrauch oder Medizinprodukt – das entscheidet, wer sie anlegt), „Ersatzteil“ (sonstiges),
  „Verbandstoff“ (Medizinprodukt). **Alle Regeln folgen der Grundart**; die eigene Art dient dem Ordnen, Filtern und
  Auswerten.
- Die vier Grundarten stehen selbst als Arten in der Liste, wie bisher. Wer nichts anlegt, merkt keinen Unterschied.
- **Gepflegt wird in den Einstellungen des Lagers,** je Buch, mit `lager.einstellung.pflegen`: anlegen, umbenennen,
  Reihenfolge, stilllegen. Im Dialog „Artikel“ steht neben der Auswahl ein Knopf „Arten …“, der dorthin führt (ohne
  Recht gesperrt mit Grund).
- **Nichts wird gelöscht** (WW23): Eine Art wird stillgelegt und steht dann nicht mehr zur Auswahl; Artikel, die sie
  tragen, behalten sie. Der Name ist änderbar; die **Grundart einer Art steht fest, sobald ein Artikel sie trägt** –
  sonst wechselten mit einem Klick die Regeln für vorhandenen Bestand.
- **Die Art eines Artikels wechseln** geht wie heute, solange die Grundart gleich bleibt, ohne weitere Prüfung; bei
  anderer Grundart gelten die heutigen Prüfungen (z. B. MB1: ein Artikel in einer Gruppe wird nicht Arzneimittel).

### 4.3 Katalog

Die Art ist nach KATALOG.md KA7 eine Angabe der Fassung und damit für alle Mandanten sichtbar. Eine eigene Art ist aber
eine Ordnung des Betriebs. Vorschlag: **Im Katalog steht nur die Grundart; die eigene Art steht im Buch** – wie die
eigene Artikelnummer (KA16). So verrät der Katalog nichts über die Ordnung eines Mandanten, und die Regeln eines
Artikels sind in jedem Buch dieselben. KA20 nennt für Geräte den Wert „Gerät“: Er wäre dann eine fünfte Grundart, die
nur die Anlagen verwenden.

### 4.4 Entscheidungen

| # | Frage | Stand |
|---|---|---|
| AR1 | Ist die Art erweiterbar? | **Entschieden 2026-11-25: ja.** |
| AR2 | Wie? | *Mit der Antwort „System, Mandant und Buch“ nicht ausdrücklich entschieden – der Vorschlag bleibt und wird so gebaut, wenn kein Einwand kommt (9.1).* Vorschlag: eigene Arten mit je einer Grundart (4.2); die Regeln folgen der Grundart. **Bitte entscheiden** – die Alternative wäre, je Art jede Regel einzeln einzustellen (Verfall vorbelegt, Charge fest, Gruppe erlaubt, erwartete Dokumente). Das ist beweglicher, aber wer eine Art falsch einstellt, schaltet z. B. die Sperre „Arzneimittel nie in einer Gruppe“ ab. |
| AR3 | Wo liegen eigene Arten? | **Entschieden 2026-11-25: System, Mandant und Buch** – drei Ebenen (Abschnitt 9.1). Der Vorschlag „nur je Buch“ ist verworfen. |
| AR4 | Artikelgruppen | Folgt der Grundart: erlaubt bei Verbrauch, sonstiges und Medizinprodukt ohne Seriennummer, nie bei Arzneimittel (MA2, MB1 unverändert). |
| AR5 | Stilllegen und Ändern | Vorschlag: stilllegen statt löschen; Name änderbar (Änderung im Protokoll); Grundart fest ab dem ersten Artikel. Die vier Grundarten lassen sich weder stilllegen noch umbenennen. |
| AR6 | Katalog | **Antwort vom 2026-11-25: System, Mandant und Buch.** Gelesen (9.1): Im Katalog steht eine Art der Ebene System – sie gilt für alle; Arten des Mandanten und des Buchs bleiben dort, wo sie angelegt sind, und kommen nie in den Katalog. Lesart zu bestätigen. |
| AR7 | Auswertung | Vorschlag: Spalte und Filter „Art“ zeigen die eigene Art; die Bestandsliste mit Wert kann nach Art gruppieren. Die Grundart steht beim Überfahren und ist als eigene Spalte zuschaltbar. |
| AR8 | Mitgelieferte Vorschläge | Vorschlag: keine – kumero liefert nur die vier Grundarten. Welche Arten ein Betrieb braucht, weiß er selbst. |

## 5. Artikel aus mehreren Teilen: die Stückliste als Baum

### 5.1 Worum es geht

Manches wird als **ein** Ding gekauft, gebucht und benutzt, besteht aber aus Teilen, die einzeln kaputtgehen, einzeln
ersetzt werden und eigene Seriennummern und Unterlagen haben. Der Fall ist allgemein und hängt an keiner Art von Artikel
(Vorgabe 4).

**Eine Stückliste ist ein vollständiger Baum, wie ein Dateisystem** (entschieden, ST3): Das Gerät ist die Wurzel. Darunter
stehen **Module**, unter einem Modul weitere Module, und ganz unten **Teile**. Ein Modul ist ein Knoten, der etwas
enthält (der Ordner); ein Teil ist ein Knoten, der nichts enthält (die Datei). Die Tiefe ist nicht festgelegt.

```
Handscanner-Arbeitsplatz                         (Gerät = Anlagegut)
├─ Scanner                                       Modul, Seriennr.
│  ├─ Scan-Einheit                               Modul
│  │  ├─ Scanmodul                               Teil
│  │  └─ Sichtfenster                            Teil
│  └─ Akku                                       Teil, Seriennr.
├─ Ladeschale (Cradle)                           Modul, Seriennr.
│  ├─ Netzteil                                   Teil
│  └─ Funkmodul                                  Teil
└─ USB-Kabel                                     Teil
```

**Stückliste und Artikelgruppe sind Gegenstücke:**

| | Artikelgruppe | Stückliste |
|---|---|---|
| Verhältnis | **oder** – ein Artikel statt des anderen | **und** – alles zusammen ist das Ding |
| Gestalt | flach: eine Gruppe, ihre Artikel (MA4) | Baum beliebiger Tiefe |
| Wofür | Verbrauchsmaterial, das sich ersetzt | ein Gerät aus Modulen und Teilen; ein Set aus Kleinteilen |
| Bestand | Summe der Artikel | Gerät: keiner, es ist in Nutzung. Set: am Set oder an den Teilen, nie an beiden (5.6) |
| Wo | Lager (im Buch) | Geräte in den Anlagen, Sets im Lager (beides im Buch); als Vorlage später am Artikel im Katalog |

### 5.2 Drei Dinge: Stück, Platz, Vorlage

Aus den Antworten vom 2026-11-25 (ST6, ST13) folgt: **Ein Modul ist ein eigener Gegenstand, kein Eintrag in der Liste
eines Geräts.** Es kann heute in diesem Gerät stecken und morgen in einem anderen, es kann fehlen dürfen oder nicht, und
es hat Versionen. Der Baum besteht deshalb aus zwei Dingen, dazu kommt die Vorlage:

1. **Stück** – das wirkliche Modul oder Teil: diese Ladeschale mit dieser Seriennummer. Es gehört zum Buch, nicht zu
   einem Gerät, und bleibt dasselbe Stück, wo immer es steckt.
2. **Platz** – die Stelle in einem Gerät oder in einem Modul, an der ein Stück steckt: „Ladeschale“ am
   Handscanner-Arbeitsplatz. **Am Platz steht, ob er besetzt sein muss (erforderlich) oder leer bleiben darf
   (optional)** – das hängt am Modul und an seinem Einbau, nicht am Stück (ST6). Die **Belegung** sagt, welches Stück
   von wann bis wann auf dem Platz steckt.
3. **Vorlage** – die Stückliste am Artikel: „Dieses Produkt besteht aus …“. Aus ihr werden Plätze und Stücke beim
   Anlegen vorbelegt (5.6). Sie kommt mit dem Katalog; bis dahin lässt sich der Baum eines vorhandenen Geräts als
   Vorlage für ein gleiches übernehmen.

Der Baum, den der Nutzer sieht, ist derselbe wie in 5.1: Gerät → Plätze → Stücke → deren Plätze → Stücke.

### 5.3 Stücke, Plätze und Versionen in den Anlagen (Stufe B)

**Stück:**

| Feld | Inhalt |
|---|---|
| Bezeichnung | z. B. „Ladeschale“; später aus dem Katalogartikel, wenn das Stück eine GTIN hat |
| Seriennummer | freiwillig, auf jeder Ebene |
| Menge | ganze Zahl, Vorgabe 1; über 1 nur ohne Seriennummer (vier gleiche Einschübe, zehn Schrauben) |
| Stand des Herstellers | freiwillig: Revision, Firmware – freier Text |
| Standort | freier Text; gebraucht, solange das Stück nirgends steckt |
| Zustand | vorhanden · ausgeschieden – mit Datum und Grund |
| Kosten | freiwillig, mit Verweis auf die Buchung (5.5) |
| Version | laufende Nummer, siehe unten |

**Platz:** Träger (das Gerät oder ein Stück), Bezeichnung, erforderlich oder optional, Reihenfolge; ein Platz wird
aufgehoben, nie gelöscht. **Belegung:** Platz, Stück, seit, bis, Grund.

Ob ein Stück Modul oder Teil ist, wird nicht eingetragen: Modul ist, was Plätze hat (ST15).

**Regeln:**
- **Ein Baum, kein Netz:** Ein Stück steckt zu einer Zeit auf höchstens einem Platz. Es kann nicht in sich selbst oder
  in etwas stecken, das unter ihm liegt; das erzwingt die Datenbank.
- **Nichts wird gelöscht oder überschrieben** (WW23 sinngemäß): Belegungen enden, Plätze werden aufgehoben, Stücke
  scheiden aus. So ist später zu sagen, was wann wo steckte.
- **Ersetzen:** Die Belegung des alten Stücks endet, das neue kommt auf denselben Platz. Das alte Stück bleibt samt
  allem, was in ihm steckt, vorhanden und ist danach **frei** – oder scheidet aus, wenn der Nutzer das wählt.
- **Umhängen – auch in ein anderes Gerät** (entschieden, ST13): Ein Stück wandert samt Inhalt auf einen anderen Platz,
  im selben Gerät oder in einem anderen. Am alten Platz steht „abgegeben am … an …“, am neuen „übernommen am … von …“.
- **Freie Stücke** stecken nirgends (Ersatzmodul im Schrank). Sie stehen in einer eigenen Liste „Module und Teile“ der
  Anlagen und lassen sich von dort einbauen.
- **Vollständig** ist ein Gerät, wenn jeder erforderliche Platz auf jeder Ebene besetzt ist. Ein leerer erforderlicher
  Platz steht als Zustand in Worten in der Liste der Anlagen mit dem Weg dorthin („unvollständig: Ladeschale › Netzteil
  fehlt“) – ein Hinweis, keine Sperre. Ein leerer optionaler Platz ist kein Mangel. Wird ein Modul für ein anderes Gerät
  ausgebaut, sagt das abgebende Gerät damit von selbst, was ihm fehlt.
- **Dokumente** (F166): Unterlagen hängen am Gerät; ein Stück mit eigener Anleitung trägt eigene Verweise und nimmt sie
  mit, wenn es wandert. Das Stück ist dafür ein weiterer Gegenstand im Sinn von LAGER.md 16.3 (DV2).
- **Seriennummer scannen** wie überall: Tastatur-Scanner, kein Dienst außerhalb (WW16).
- **Rechte:** sehen `anlagen.gut.sehen`, pflegen `anlagen.gut.pflegen` – keine neuen.

**Versionen der Module** (Vorgabe zu ST13; **Lesart bitte bestätigen, ST16**):
- Jedes Stück trägt eine Versionsnummer. **Eine neue Version entsteht, wenn sich seine Zusammensetzung ändert** – ein
  Stück kommt hinein, geht heraus oder wird ersetzt, ein Platz kommt dazu oder wird aufgehoben – oder wenn der „Stand
  des Herstellers“ geändert wird. Je Version: Zeitpunkt, Grund, wer.
- Was in einem Dialog zusammen gespeichert wird (ein Umbau mit mehreren Schritten), ist **eine** Version.
- Eine Änderung in einem Modul gibt nur diesem Modul eine neue Version, nicht dem Modul darüber und nicht dem Gerät
  (wie im Dateisystem: Der Ordner bleibt derselbe, wenn sich eine Datei in einem Unterordner ändert). Das Gerät zeigt
  „zuletzt geändert am …“ abgeleitet.
- **Die Belegung hält fest, in welcher Version ein Stück kam und ging.** Damit lässt sich für jeden Tag sagen, welches
  Modul in welcher Version in welchem Gerät steckte, und für jedes Modul, wo es in welcher Version war.
- Eine frühere Version lässt sich ansehen (der Baum, wie er war), nicht wiederherstellen – zurück geht es nur durch
  einen neuen Umbau.

**Oberfläche:**
- Dialog „Anlagegut“: Abschnitt **„Stückliste“** an festem Platz mit fester Höhe – ein Baum mit Einrückung und dem
  kleinen Dreieck vor jedem Modul; Spalten Platz, Stück, Menge, Seriennummer, Version, erforderlich, Zustand. Ein leerer
  Platz steht als Zeile da („– leer –“). Tastatur wie im Dateisystem: → klappt auf, ← klappt zu bzw. springt zum Knoten
  darüber, ↑ ↓ wandern. Knöpfe an festem Platz, sie gelten für die markierte Zeile: „Platz hinzufügen …“, „Einbauen …“
  (neues oder freies Stück), „Ersetzen …“, „Umhängen …“, „Ausbauen“, „Ausscheiden …“, „Versionen …“, „Von anderem Gut
  übernehmen …“. Der Baum rollt, der Dialog wächst nicht (F106). „Stand am [Datum]“ zeigt den Baum, wie er an einem
  früheren Tag war.
- Beim Anlegen des Guts ist die Stückliste gleich mit erfassbar (anders als die Dokumente), weil sie zum Ding gehört.
- Liste „Anlagen“: das Dreieck vor dem Gut wie im Lager (LAGER.md MB4, derselbe Baustein); dahinter derselbe Baum, nur
  lesend. Ohne Stückliste gesperrt mit Grund.
- Neue Liste **„Module und Teile“**: alle Stücke des Buchs mit Seriennummer, Version, „steckt in“ (als Weg) und
  Zustand; Filter „frei“. Von hier aus: Lebenslauf eines Stücks (wo es wann in welcher Version war).
- Die Suche findet ein Gut auch über Bezeichnung oder Seriennummer eines Stücks und nennt den Weg
  („Handscanner › Ladeschale › Netzteil“).

### 5.4 Daten

Stufe B, im Schema `anlagen`: **Stück** (Bezeichnung, Seriennummer, Menge, Stand des Herstellers, Standort, Zustand,
Kosten, laufende Version), **Platz** (Träger: Gut oder Stück; Bezeichnung, erforderlich, Reihenfolge, aufgehoben am),
**Belegung** (Platz, Stück, seit, bis, Version beim Kommen und Gehen, Grund), **Version** (Stück, Nummer, Zeitpunkt,
Grund, Subjekt). Gelesen wird der Baum mit einer rekursiven Abfrage. Trigger prüfen: höchstens eine laufende Belegung
je Platz und je Stück, kein Kreis, Grenzen aus ST14. Geschrieben nur über Funktionen mit Protokoll (F64); im Protokoll
stehen IDs, Version und Zustand.
Stufe C: Stückliste an `katalog.fassung` bzw. am Artikel im Buch; am Stück der Verweis auf Katalogartikel und Fassung.

### 5.5 Buchhaltung: Zugänge und Abgänge bei Umbau und Modernisierung

**Antwort des Verantwortlichen vom 2026-11-25 zu ST7: Das ist eine Frage von Zu- und Abschreibungen bei Änderungen oder
Modernisierungen.** Entwurf 2 („Stücke ohne Wert, ein Tausch ändert die AfA nicht“) ist damit verworfen. Gelesen ist die
Antwort so (**bitte bestätigen und steuerlich prüfen, ST7**):

- **Ein Stück kann Kosten tragen** (aus der Buchung seiner Anschaffung, mit dem Weg zum Beleg).
- **Beim Einbauen fragt kumero, was der Vorgang ist** – der Mensch entscheidet, kumero schlägt nur vor (G5):
  - **Ersatz** (das kaputte Kabel gegen ein gleiches): Aufwand; am Gut ändert sich kein Wert.
  - **Erweiterung oder Modernisierung** (ein zusätzliches oder besseres Modul): **Zugang am Gut** – die Kosten des
    Stücks erhöhen die Kosten des Guts. Die AfA wird neu gerechnet: Buchwert plus Zugang, verteilt auf die restliche
    Nutzungsdauer; die Nutzungsdauer kann der Mensch dabei ändern, wenn die Modernisierung sie verlängert.
- **Beim Ausbauen oder Ausscheiden eines Stücks mit Wert: Teilabgang am Gut.** Vorgeschlagen wird der Anteil am
  Buchwert nach dem Verhältnis der Kosten des Stücks zu den Kosten des Guts; der Mensch kann den Betrag ändern. Der
  Rest des Guts wird mit dem verminderten Buchwert weiter abgeschrieben.
- **Umhängen in ein anderes Gerät** ist beides: Teilabgang beim abgebenden, Zugang beim aufnehmenden Gut, zum selben
  Betrag.
- **Ein freies Stück mit Wert** ist selbst ein Anlagegut (oder Aufwand, wenn es so gebucht wurde) – es steht nicht
  wertlos neben dem Verzeichnis.
- **Alles sind Buchungsentwürfe** (F31), mit Verweis auf Gut, Stück und Version; festgeschrieben wird von einem
  Menschen. In einem gesperrten Geschäftsjahr geht nichts. Jede Wertänderung steht mit Datum und Grund am Gut
  (ANLAGEN.md Abschnitt 5 Nr. 6).
- Das zieht aus ANLAGEN.md G3 die **nachträglichen Kosten und den Teilabgang** vor. Güter im Sammelposten bleiben, wie
  sie sind (G14: ein Abgang ändert den Topf nicht).

**Nicht nachgeschlagen und vom Verantwortlichen zu prüfen:** wann ein Tausch Erhaltungsaufwand und wann nachträgliche
Anschaffungs- oder Herstellungskosten ist; ab welchem Monat die neue AfA gilt; wie der Teilabgang zu bemessen ist; was
bei einem GWG gilt, dessen Grenze durch einen Zugang überschritten würde. **Offen:** ob mit „Zuschreibung“ auch die
Wertaufholung und mit „Abschreibung“ auch die außerplanmäßige gemeint ist – beides ist hier nicht beschrieben.

### 5.6 Stücklisten im Lager: Sets

**Antwort des Verantwortlichen vom 2026-11-25 zu ST10: Ja – Sterilgut für Eingriffe besteht immer aus diversen
Kleinteilen: Skalpelle, Zangen, Tupfer und so weiter.** Damit gibt es Stücklisten auch im Lager; „das Lager bleibt, wie
es ist“ aus Entwurf 2 gilt nicht mehr.

Ein **Set** ist ein Lagerartikel mit einer Stückliste. Anders als ein Gerät hat es Bestand, Charge und Verfall. Vorschlag
– drei Wege, je Set-Artikel eingestellt:

| Weg | Was geschieht | Bestand |
|---|---|---|
| **fertig gekauft** | Das Set geht als Ganzes zu und wird als Ganzes entnommen, mit seiner Charge und seinem Verfall. Die Stückliste ist Auskunft: was darin ist. | am Set |
| **selbst gepackt** | Bewegung **„Packen“**: In einem Vorgang werden die Teile mit ihren Chargen entnommen (Vorschlag nach frühestem Verfall, WW6) und das Set geht zu. Die Charge des Sets ist die Nummer des Packvorgangs; den Verfall trägt der Mensch ein, höchstens der früheste Verfall der Teile. **Die Rückverfolgung führt von der Charge des Sets zu den Chargen der Teile** und zurück. „Auflösen“ ist die Gegenbewegung. | erst an den Teilen, nach dem Packen am Set |
| **beim Zugang aufgelöst** | Gekauft als Set, geführt nur als Teile: Der Wareneingang bucht die Teile zu, das Set nie. | an den Teilen |

- In keinem Fall steht dieselbe Ware zugleich am Set und an den Teilen im Bestand (WW3, WW22).
- **Eine Zeile der Stückliste zeigt auf einen Artikel oder auf eine Artikelgruppe** („Tupfer – welcher Hersteller, ist
  gleich“): das „oder“ der Gruppe im „und“ der Stückliste (ST18). Beim Packen wird dann wie bei der Entnahme aus einer
  Gruppe vorgeschlagen (MA8).
- Auch die Stückliste eines Sets ist ein Baum (ein Set kann Sets enthalten), mit „erforderlich“ und „optional“ je Zeile.
- Arzneimittel und Betäubungsmittel gehören in kein Set (Vorschlag; wie MB1).
- Kein Patientenbezug: Für welchen Eingriff ein Set entnommen wird, steht höchstens als Zweck aus der Auswahlliste da
  (WW14).

**Abgrenzung, bitte beachten:**
- **Wiederverwendbare Instrumente** (Zangen, Scheren) werden nicht verbraucht, sondern aufbereitet und wieder
  verwendet. Sie sind keine Lagerware, sondern in Nutzung (Anlagen, meist GWG). Ein Sieb mit Instrumenten **und**
  Verbrauchsteilen liegt damit in zwei Modulen, die einander nicht lesen (F44). Wie das geführt wird, ist offen (ST19).
- **Die Dokumentation der Aufbereitung** (Reinigung, Sterilisation, Freigabe, Chargen des Sterilisators) ist eine
  Pflicht eigener Art und **nicht Teil von kumero** – wie Medizinproduktebuch und Bestandsverzeichnis (WW18, IN8).
  Welche Vorschrift was verlangt, ist nicht nachgeschlagen.

**Vorlage für Geräte (Stufe C, mit dem Katalog):**
- Die Stückliste eines Artikels ist ein Baum. Eine Zeile ist entweder frei beschrieben (Module im Inneren eines Geräts
  haben meist keine GTIN) oder zeigt auf einen Katalogartikel in einer Fassung; dann gilt darunter dessen eigene
  Stückliste. Bei Artikeln mit GTIN steht der Baum in der Fassung des Katalogs (nur anfügen, KA3) – **die Fassung ist
  dort die Version der Vorlage**; ohne GTIN steht er im Buch (KA9). Ein Artikel kann in vielen Stücklisten vorkommen,
  aber nie in seiner eigenen – auch nicht über Umwege.
- Eine Stückliste nennt nur Produkte, nie einen Mandanten – sie verträgt sich mit KATALOG.md Abschnitt 5.
- Beim Anlegen eines Geräts wird die Vorlage ganz aufgeklappt; danach gehören Plätze und Stücke dem Buch. Eine neuere
  Fassung der Vorlage ändert vorhandene Geräte nicht (wie KA15).
- Wareneingang: Die Position „Scanner-Set“ wird über „als Anlagegut anlegen“ (LAGER.md IN4) zum Gut, der Baum ist
  vorbelegt, Seriennummern werden am Regal gescannt.
- Ersatzteil aus dem Lager: Ein Kabel auf Vorrat ist ein gewöhnlicher Lagerartikel (z. B. mit der eigenen Art
  „Ersatzteil“, Abschnitt 4). Der Tausch ist eine Entnahme im Lager und „Ersetzen …“ am Gut (ST9).

### 5.7 Entscheidungen

| # | Frage | Stand |
|---|---|---|
| ST1 | Wo werden Stücklisten geführt? | **Folgt aus ST10: an beiden Stellen** – Geräte in Nutzung in den Anlagen (5.3), Sets im Lager (5.6). |
| ST2 | Aufbau | Vorschlag: Stück, Platz mit Belegung, Vorlage (5.2). |
| ST3 | Gestalt der Stückliste | **Entschieden 2026-11-25: ein vollständiger Baum wie ein Dateisystem** – Gerät, Module, Module in Modulen, Teile. Die flache Liste aus Entwurf 1 ist verworfen. |
| ST4 | Tiefe | **Folgt aus ST3: nicht festgelegt.** Technische Grenzen: ST14. |
| ST5 | Ersetzen | Vorschlag: Die Belegung des alten Stücks endet, das neue kommt auf denselben Platz; das alte ist danach frei oder scheidet aus. Nichts wird gelöscht. |
| ST6 | „erforderlich“ und „optional“ | **Entschieden 2026-11-25: modulabhängig – Module können auch woanders genutzt werden oder optional sein.** Ausgeführt als Angabe am Platz (5.2); daraus folgt „unvollständig“ als Hinweis mit dem Weg, keine Sperre. |
| ST7 | Kosten und AfA | **Entschieden 2026-11-25: eine Frage von Zu- und Abschreibungen bei Änderungen oder Modernisierungen; die Lesart in 5.5 ist bestätigt („Ja“):** Zugang am Gut bei Erweiterung oder Modernisierung, Teilabgang beim Ausbau, Ersatz als Aufwand – alles als Entwurf. **Die steuerliche Prüfung bleibt beim Verantwortlichen** (die offenen Punkte am Ende von 5.5). |
| ST8 | Dokumente am Stück | Vorschlag: ja, über das Merkmal aus F166, sobald es gebaut ist; sie wandern mit dem Stück. |
| ST9 | Ersatzteil aus dem Lager | Stufe C. Vorschlag: zwei Schritte von Hand, am Stück ein freies Feld „Herkunft“; eine Verbindung über einen Dienst erst, wenn sie im Betrieb fehlt. |
| ST10 | Sets als Lagerware | **Entschieden 2026-11-25: ja** (Sterilgut für Eingriffe aus Kleinteilen). Vorschlag zur Ausführung: 5.6. *Antwort vom 2026-11-25: sowohl gekauft als auch selbst gepackt* (9.4); ob „beim Zugang aufgelöst“ gebraucht wird, ist nicht gesagt. |
| ST11 | Geliehenes und Geleastes | Vorschlag: Stücklisten gibt es auch an Gütern der Art „fremd“ (IN9); Wert trägt dort nichts. |
| ST12 | Körperliche Bestandsaufnahme | Nicht Teil dieses Schritts (ANLAGEN.md G18); die Seriennummern der Stücke wären dafür die Grundlage. |
| ST13 | Umhängen zwischen Geräten | **Entschieden 2026-11-25: grundsätzlich ja, aber mit Versionierung der Module.** Ausgeführt in 5.3; die Lesart der Versionierung ist ST16. |
| ST14 | Grenzen | Vorschlag: höchstens 12 Ebenen und 2.000 Stücke je Gerät – nur als Schutz vor Fehlbedienung und endlosen Abfragen, nicht als fachliche Regel. |
| ST15 | Modul oder Teil | Vorschlag: kein eigenes Feld – Modul ist, was Plätze hat. Erfasst wird so tief, wie es der Betrieb braucht. |
| ST16 | Was ist eine Version? | **Entschieden 2026-11-25: Die Version vergibt, wer herstellt – bei Fremdherstellung der Hersteller, bei Selbstherstellung der Betrieb.** Der Vorschlag (kumero zählt die Umbauten von selbst) ist verworfen; Ausführung in 9.2. |
| ST17 | Reicht eine Version nach oben durch? | **Entschieden 2026-11-25: Das ist eine Frage des Betriebs** – kumero reicht nichts von selbst durch; ob ein Gerät nach einem Umbau eine neue Version bekommt, trägt der Betrieb ein (9.2). |
| ST18 | Artikelgruppe in der Stückliste | Vorschlag: Eine Zeile einer Set-Stückliste darf auf eine Artikelgruppe zeigen. |
| ST19 | Sets mit wiederverwendbaren Instrumenten | **Entschieden 2026-11-25: Es gibt beides** – gekaufte Einmal-Sets und selbst gepackte Siebe mit aufbereiteten Instrumenten. Der Vorschlag „Instrumente nur als Zeile ohne Bestand“ genügt damit nicht; Entwurf in 9.4, zur Freigabe. *Früherer Vorschlag:* Das Lager führt nur, was verbraucht wird; Instrumente stehen in den Anlagen. In der Stückliste des Sets stehen sie als freie Zeile ohne Bestand („1 Zange – in Nutzung“), damit der Inhalt vollständig beschrieben ist. **Bitte sagen, ob die Sets Einmal-Sets sind oder Siebe mit aufbereiteten Instrumenten.** |

## 6. Produktangaben und Merkmale

### 6.1 Anlass

Vorgabe des Verantwortlichen vom 2026-11-25, an den Angaben eines Händlers zu einer Lade- und Übertragungsstation für
einen Handscanner: **So etwas sollte mit erfassbar sein** – Hersteller, Produktbezeichnung, Artikelnummer des
Herstellers, GTIN, Farbe, Schnittstellen (mehrere), kompatible Geräte, Lieferumfang, Gewicht, Originalzubehör,
Gebrauchtgerät.

Heute kennt der Artikel Bezeichnung, eigene Artikelnummer, Art, Einheit, Führung, GTIN und Lagerbedingung; das Stück aus
5.3 kennt Bezeichnung und Seriennummer. Für das meiste aus der Liste gibt es keinen Platz.

### 6.2 Was die Angaben sind – sie sind nicht alle gleich

| Angabe im Beispiel | Was sie ist | Vorschlag |
|---|---|---|
| Hersteller, Produktbezeichnung, GTIN | Kennung des Produkts | feste Felder (GTIN und Bezeichnung gibt es; Hersteller wird festes Feld) |
| Art-Nr. des Herstellers | Kennung des Produkts beim Hersteller – danach wird nachbestellt und gesucht | **neues festes Feld „Herstellernummer“**, neben der eigenen Artikelnummer (die bleibt die Nummer des Betriebs, KA16) |
| Farbe, Schnittstellen, Gewicht, Originalzubehör | Eigenschaften des Produkts | **freie Merkmale:** Name → ein oder mehrere Werte, bei Zahlen mit Einheit |
| Kompatible Geräte | eine Beziehung zu einem anderen Produkt | **„passt zu“:** Verweis auf einen Artikel oder, wenn der nicht angelegt ist, freier Text |
| Lieferumfang | was in der Packung ist | die **Stückliste** des Artikels (5.6) – nicht noch einmal als Text |
| Gebrauchtgerät | Eigenschaft des gekauften Stücks, nicht des Produkts | Angabe **„neu / gebraucht“ am Stück und am Anlagegut** (berührt die Nutzungsdauer, die der Mensch einträgt – ANLAGEN.md G11) |

### 6.3 Freie Merkmale

- Je Artikel bzw. Stück eine Liste: **Merkmal** (Name), **Wert** – mehrere Werte je Merkmal sind möglich
  (Schnittstellen: KBW, USB, RS-232, RS-485) –, bei Zahlen eine **Einheit** (Gewicht: 0,23 kg).
- **Die Namen sind frei, aber nicht beliebig:** Beim Tippen schlägt kumero die Merkmale vor, die im Buch schon
  verwendet werden, damit nicht „Farbe“, „Farbton“ und „Farben“ nebeneinander entstehen. Eine feste Liste gibt es nicht.
- **Suchen und Filtern:** Die Suche findet einen Artikel oder ein Stück auch über Herstellernummer und Merkmalswerte.
  Ein Merkmal lässt sich in Listen als Spalte zuschalten und dann wie jede Spalte filtern (F91).
- **Einfügen aus der Zwischenablage:** „Merkmale einfügen …“ nimmt einen eingefügten Textblock (Zeilen „Name: Wert“
  oder „Name ⇥ Wert“, wie von einer Händlerseite kopiert) und macht daraus einen **Vorschlag**, den der Nutzer Zeile für
  Zeile prüft: GTIN, Hersteller und Herstellernummer gehen in ihre festen Felder, der Rest in die Merkmale. Zerlegt wird
  im Browser. **kumero ruft keine Händler- oder Herstellerseite ab** (wie LAGER.md 15.3a).
- Merkmale beschreiben Sachen, nie Personen; der Dialog sagt das.
- **Die Regeln des Lagers hängen nie an einem Merkmal** – Verfall, Charge, Gruppe folgen der Art (Abschnitt 4).
  Merkmale sind Auskunft.

### 6.4 „passt zu“

- Eine Liste am Artikel bzw. Stück: wozu es passt. Ein Eintrag zeigt auf einen Artikel (später Katalogartikel) oder ist
  freier Text.
- Die Beziehung wird in beide Richtungen gezeigt: Am Scanner steht, welche Ladestationen zu ihm passen.
- **Beim Einbauen** (5.3) schlägt kumero zuerst freie Stücke vor, die zum Gerät passen. Fehlt die Angabe, ist das kein
  Hindernis – ein Hinweis, keine Sperre.
- Ein Platz kann nennen, was auf ihn passt („Ladestation für dieses Modell“); das ist dieselbe Angabe von der anderen
  Seite (PM6).

### 6.5 Wo die Angaben liegen

- Hersteller, Herstellernummer, Merkmale und „passt zu“ sind **Angaben zum Produkt.** Bei Artikeln mit GTIN gehören sie
  in die Fassung des Katalogs (KA7) und sind dann für alle Mandanten da; ohne GTIN stehen sie im Buch (KA9).
- **Der Katalog ist nicht gebaut.** Bis dahin stehen sie am Lagerartikel im Buch und am Stück in den Anlagen – die
  Ladestation ist ja kein Lagerartikel, sondern ein Stück in Nutzung. Mit dem Katalog zeigen beide auf den
  Katalogartikel, und die Angaben wandern von Hand dorthin (wie KA12: nichts wandert von selbst).
- „neu / gebraucht“, Seriennummer und Stand des Herstellers bleiben immer am Stück.

### 6.6 Entscheidungen

| # | Frage | Stand |
|---|---|---|
| PM1 | Sind solche Produktangaben erfassbar? | **Entschieden 2026-11-25: ja.** |
| PM2 | Feste Felder | Vorschlag: Hersteller und Herstellernummer als feste Felder an Artikel und Stück; GTIN auch am Stück (mit Prüfziffer). |
| PM3 | Freie Merkmale | Vorschlag: Name → Werte mit Einheit (6.3); Namen frei mit Vorschlag aus dem Bestand. **Bitte entscheiden,** ob es zusätzlich eine gepflegte Liste von Merkmalen je Buch braucht (strenger, aber mehr Pflege). **Entschieden 2026-11-25 (auf Nachfrage): System, Mandant und Buch** – Merkmale sind gepflegte Listen auf drei Ebenen (9.1). „Eine feste Liste gibt es nicht“ in 6.3 gilt nicht mehr. |
| PM4 | Einfügen aus der Zwischenablage | Vorschlag: ja, als Vorschlag zum Prüfen; kein Abruf fremder Seiten. |
| PM5 | „passt zu“ | Vorschlag: Verweis auf Artikel oder freier Text, in beide Richtungen gezeigt; beim Einbauen ein Hinweis, keine Sperre. |
| PM6 | Vorgabe am Platz | Vorschlag: Ein Platz kann nennen, welcher Artikel auf ihn gehört. Stufe C. |
| PM7 | Lieferumfang | Vorschlag: ist die Stückliste des Artikels (5.6), kein eigenes Textfeld. |
| PM8 | „neu / gebraucht“ | Vorschlag: Angabe am Stück und am Anlagegut. |
| PM9 | Katalog | **Entschieden 2026-11-25: ja** – Merkmale, Herstellernummer und „passt zu“ werden Teil der Fassung (KA7); sie sind für alle Mandanten sichtbar, der Dialog sagt das wie bei der Bezeichnung. Bis der Katalog gebaut ist, im Buch. |
| PM10 | Grenzen | Vorschlag: höchstens 100 Merkmale je Artikel, Name bis 60, Wert bis 200 Zeichen. |

## 7. Stufen

| Stufe | Inhalt | Berührt |
|---|---|---|
| **A1** | **Gebaut mit 0.7.83 (Abschnitt 8):** Artikelgruppe – Feld „Aufgabe“, Maske im Raster mit Zuordnen und Lösen (AG7), „Artikel neu …“ in die markierte Gruppe (AG8), Texte ohne Bezug auf eine Art, Anzeige der Aufgabe (AG3), Dialog „Artikel“ im selben Raster | Modul `lager` 0.6.0 (Migration 0005) |
| **A2** | **Art des Artikels:** eigene Arten mit Grundart, Pflege in den Einstellungen, Auswahl im Dialog „Artikel“, Spalte und Filter (Abschnitt 4). **Produktangaben am Lagerartikel:** Hersteller, Herstellernummer, Merkmale, „passt zu“, Einfügen (Abschnitt 6). | Modul `lager` (Migration, Dialog der Gruppe, Dialog „Artikel“, Einstellungen, „Bestand“, Entnahme, Nachbestellliste, Bestandsliste), LAGER.md Abschnitt 2, 8 und 17, DATENMODELL.md, `openapi/v1.yaml` |
| **B1** | Stücke, Plätze, Belegung und Versionen in den Anlagen: Baum im Dialog und hinter dem Dreieck, Einbauen, Ersetzen, Umhängen auch zwischen Geräten, Liste „Module und Teile“, Hinweis „unvollständig“; Produktangaben am Stück samt „neu / gebraucht“ (Abschnitt 6) – noch ohne Wert | Modul `anlagen` (Migration, Dialog, Listen), Baustein für Bäume in der Oberfläche (gemeinsam, nicht je Seite – F91), ANLAGEN.md, DATENMODELL.md, `openapi/v1.yaml` |
| **B2** | Wert bei Umbau und Modernisierung: Kosten am Stück, Zugang und Teilabgang am Gut als Entwürfe, AfA neu gerechnet (5.5) – erst nach Prüfung durch den Verantwortlichen | Modul `anlagen` (AfA-Lauf, Anlagenverzeichnis, Abstimmung), ANLAGEN.md G3 |
| **S** | Sets im Lager: Stückliste am Lagerartikel, Bewegungen „Packen“ und „Auflösen“, Rückverfolgung Set ↔ Teile, Artikelgruppe als Zeile (5.6) | Modul `lager` (Migration, Dialog „Artikel“, Wareneingang, Entnahme, Rückverfolgung, Inventur, Bewertung), LAGER.md |
| **C** | Stückliste als Vorlage am Artikel (Katalog), Vorbelegen aus dem Wareneingang, Ersatzteil aus dem Lager | KATALOG.md (Fassung um die Stückliste, Herstellernummer, Merkmale und „passt zu“ – PM9; nur Grundart im Katalog, AR6), LAGER.md IN4, Modul-API |

A2 ist nach den Antworten vom 2026-11-25 (Abschnitt 9) so weit entschieden, dass es nach einer Freigabe gebaut werden kann; neu dazu kommt die Pflege auf den Ebenen System und Mandant (9.1). A2, B1 und S hängen nicht voneinander ab; B2 setzt B1 voraus. C setzt den Katalog (P48) und den Weg „als Anlagegut anlegen“ (IN4) voraus.

**Tests** nur mit erfundenen Gegenständen und Seriennummern (CLAUDE.md); Unittests des bearbeiteten Teils (F121) – für
den Baum vor allem: kein Kreis, ein Stück nur auf einem Platz, Ersetzen und Umhängen mit Inhalt, Versionen und Stand
zu einem früheren Tag, „unvollständig“ über mehrere Ebenen; für Sets: nie doppelter Bestand, Rückverfolgung nach dem Packen;
für die Arten: Regeln folgen der Grundart, Grundart fest ab dem ersten Artikel, stillgelegte Art bleibt am Artikel.

## 8. Stand der Umsetzung (0.7.83) und Festlegungen zur Bestätigung

**Auftrag des Verantwortlichen vom 2026-11-25:** „dann bau mal für kumero, beachte alle Vorgaben“ – auf den Vorschlag hin,
mit dem Teil anzufangen, der entschieden ist und nur das Lager berührt. Damit gelten AG2 bis AG6 wie vorgeschlagen.
Gebaut mit kumero 0.7.83, Modul `lager` 0.6.0, Migration `0005_meta_aufgabe.sql` (F189).

**Gebaut:**
- **Aufgabe der Artikelgruppe** (AG1, AG2, AG4): Feld `aufgabe` am Meta-Artikel, höchstens 500 Zeichen, Pflicht beim
  Anlegen; Änderungen stehen mit Vorher und Nachher im Protokoll. Gruppen von vor 0.7.83 bleiben ohne Aufgabe gültig und
  änderbar und tragen in „Bestand“ die Marke „Aufgabe fehlt“.
- **Maske „Artikelgruppe“ im Raster** (AG6): Bezeichnung und Aufgabe über die ganze Breite, Lagereinheit, Mindest- und
  Sollbestand in einer Zeile auf einer Höhe, unter jedem Feld eine reservierte Zeile für den Hinweis, Zähler „n / 500“.
- **Artikel der Gruppe im Dialog** (AG7): Liste an festem Platz mit fester Höhe; „Zuordnen“ (Auswahl dessen, was in die
  Gruppe darf) und „Lösen“ (für die markierte Zeile). Beim Anlegen gesperrt mit Grund.
- **„Artikel neu …“ führt in die markierte Gruppe** (AG8) – auch wenn ein Artikel der Gruppe markiert ist: Gruppe
  vorgewählt, ihre Lagereinheit vorbelegt, Mindest- und Sollbestand gesperrt mit Grund; nach dem Anlegen bleibt die Gruppe
  markiert und ist aufgeklappt. Die Hilfe am Knopf nennt die Gruppe, die Beschriftung bleibt (F106).
- **Anzeige der Aufgabe** (AG3): im Dialog „Artikel“ unter der Auswahl der Gruppe, auf „Bestand“ in der Kopfzeile der
  unteren Hälfte und als Hilfe an der Marke der Gruppe, im Dialog „Entnahme“, in der Nachbestellliste (Bildschirm, Druck, CSV).
- **Texte ohne Bezug auf eine Art** (AG5): Beispiele „Kopierpapier A4“, „Stück, Rolle“, „trocken, gekühlt 2–8 °C“.
- **Dialog „Artikel“ im selben Raster**; „stillgelegt“ steht auch beim Anlegen da und ist gesperrt mit Grund (vorher
  ausgeblendet – F106).

| # | Festlegung aus der Umsetzung, zu bestätigen |
|---|---|
| SA1 | **Aufgabe ändern, nicht leeren:** Eine gesetzte Aufgabe lässt sich ändern, aber nicht wieder entfernen. Fehlt der Schlüssel `aufgabe` in einer Änderung über die Schnittstelle, bleibt sie, wie sie ist – ein älterer Aufrufer leert sie nicht. |
| SA2 | **Die Aufgabe ist ein Absatz:** Zeilenumbrüche werden zu Leerzeichen; Enter im Feld bricht nicht um. |
| SA3 | **Zuordnen und Lösen im Dialog der Gruppe wirken sofort** (wie bei den Lagerorten); die Felder darüber erst mit „Speichern“. Der Dialog bleibt dabei offen. |
| SA4 | **Zur Auswahl steht nur, was noch in keiner Gruppe ist.** Ein Artikel wandert nicht in einem Schritt von einer Gruppe in die andere – erst dort lösen. |
| SA5 | **Die Liste im Dialog zeigt Artikel, Nummer, verfügbar und nächsten Verfall** – nicht den Hersteller wie in der Skizze: Den führt der Artikel noch nicht (Abschnitt 6). |
| SA6 | **Eine gewählte Gruppe wird nie still entfernt – auch beim Bearbeiten:** Passt der Artikel nicht (mehr) hinein (Arzneimittel, Betäubungsmittel, Seriennummer, andere Lagereinheit), ist „Anlegen“ bzw. „Speichern“ gesperrt und nennt den Grund; die Auswahl der Gruppe bleibt bedienbar, damit „– keine –“ gewählt werden kann. Vorher leerte der Dialog die Gruppe von selbst. |
| SA7 | **Ein neuer Artikel übernimmt die Lagereinheit der Gruppe,** sobald eine gewählt wird; bei einem vorhandenen Artikel ändert die Wahl nichts an seiner Einheit. |
| SA8 | **AG8 gilt nicht für eine stillgelegte Gruppe** – sie nimmt keine Artikel auf; „Artikel neu …“ öffnet dann ohne Vorwahl. |
| SA9 | **„Aufgabe fehlt“ ist eine Marke an der Zeile der Gruppe,** kein eigener Zustand in der Spalte „Zustand“ und kein Filter. |
| SA10 | **Die Schnittstelle des Lagers steht nicht in `openapi/v1.yaml`** – festgestellt, nicht geändert: Der Vertrag nennt kein einziges `/lager/…`. Das Feld `aufgabe` ist deshalb nur hier und im Code beschrieben. |

**Geprüft:** `php tests/lauf.php Lager` (296 Prüfungen, PHP 8.3, PostgreSQL 16) – darunter Pflicht und Länge der Aufgabe,
Ändern ohne Leeren, Protokoll mit Vorher und Nachher, Gruppe ohne Aufgabe aus dem Altbestand; Browserlauf
`tests/browser/lager.mjs` (95 Prüfungen, Chrome) mit Bildern der drei Dialoge.
**Nicht geprüft:** PHP 8.5 und PostgreSQL 19; das Update eines Bestands mit vorhandenen Artikelgruppen auf der VM; der
Druck der Nachbestellliste mit langer Aufgabe; schmale Fenster (das Raster hat sechs feste Spalten).

**Nicht gebaut aus diesem Dokument:** die erweiterbare Art (Abschnitt 4), Stücklisten in Anlagen und Lager (Abschnitt 5),
Produktangaben und Merkmale (Abschnitt 6).

## 9. Antworten vom 2026-11-25, 06:02 (F190) – entschieden; die Ausführung ist Vorschlag

### 9.1 Arten und Merkmale auf drei Ebenen: System, Mandant, Buch (PM3, AR3, AR6)

**Entschieden:** Arten des Artikels und Merkmale gibt es auf drei Ebenen – **System** (die Installation), **Mandant** und
**Buch**. Vorschlag zur Ausführung (EB1–EB8), nach dem Grundsatz, der schon überall gilt: Die weitere Ebene gibt vor, die
engere darf ergänzen.

| # | Vorschlag |
|---|---|
| EB1 | **Sichtbar ist die Vereinigung:** In einem Buch stehen zur Auswahl die Einträge des Systems, die seines Mandanten und seine eigenen. Die Auswahl nennt bei jedem Eintrag die Ebene in Worten. |
| EB2 | **Die engere Ebene ergänzt, sie ändert nicht:** Ein Buch kann einen Eintrag des Mandanten oder des Systems nicht umbenennen oder stilllegen. Es kann ihn für sich **ausblenden** (steht dann nicht mehr zur Auswahl; vorhandene Artikel behalten ihn). |
| EB3 | **Gleicher Name auf zwei Ebenen:** wird abgewiesen – beim Anlegen auf der engeren Ebene mit dem Hinweis, dass es den Eintrag weiter oben schon gibt. Legt die weitere Ebene später einen gleichnamigen an, bleibt der engere bestehen und wird als „doppelt“ gezeigt; zusammengeführt wird von einem Menschen. |
| EB4 | **Wer pflegt:** System – der System-Verwalter; Mandant – der Administrator des Mandanten; Buch – wer `lager.einstellung.pflegen` hat (bzw. `anlagen.gut.pflegen` für Merkmale an Stücken). Keiner von ihnen sieht dabei Bestände: Arten und Merkmale sind Stammdaten ohne Buchungsdaten (F43 bleibt). |
| EB5 | **Wo es liegt:** System in der Verwaltungsdatenbank, Mandant in der Datenbank des Mandanten, Buch im Buch – jeweils mit Protokoll. Das Buch liest die beiden oberen Ebenen über einen Dienst der Modul-API (F44), nicht direkt, und hält am Artikel **Name und Ebene als Momentaufnahme** fest, damit ein Buch auch ohne die anderen Datenbanken lesbar und sortierbar bleibt (wie KA11). |
| EB6 | **Arten:** Jede Art jeder Ebene nennt ihre **Grundart** (4.2); die vier Grundarten sind Einträge der Ebene System und fest. *AR2 ist mit der Antwort nicht ausdrücklich entschieden – gebaut wird mit Grundart, wenn kein Einwand kommt.* |
| EB7 | **Katalog (AR6, PM9):** Der Katalog gilt für alle Mandanten und ist damit selbst Ebene System. In einer Fassung stehen deshalb nur **Arten und Merkmale der Ebene System**; was ein Mandant oder ein Buch für sich angelegt hat, bleibt dort und kommt nie in den Katalog. Ein Artikel kann so im Katalog die Art „Verbrauch“ tragen und im Buch zusätzlich die eigene Art „Bürobedarf“. **Lesart, bitte bestätigen.** |
| EB8 | **Merkmale:** Ein Merkmal ist ein gepflegter Eintrag (Name, Art des Werts: Text, Zahl mit Einheit, Ja/Nein, Auswahl aus Werten; ein oder mehrere Werte). Am Artikel wählt man aus der Liste; **ein neues Merkmal legt an, wer auf seiner Ebene pflegen darf** – aus dem Dialog heraus („Merkmal neu …“), ohne ihn zu verlassen. Beim „Merkmale einfügen …“ (6.3) werden unbekannte Namen als „neu auf Ebene Buch“ vorgeschlagen und nur mit dem Recht angelegt; sonst bleiben sie liegen und nennen den Grund. |

**Folgen:** Das ist größer als Stufe A2 bisher – es braucht Tabellen und Seiten auf zwei weiteren Ebenen (Verwaltung:
„Arten und Merkmale“; Mandant: dieselbe Seite) und einen Dienst der Modul-API. Vorschlag zur Reihenfolge: erst die Ebene
Buch mit dem Dienst als Anschlussstelle, die die oberen Ebenen leer liefert, dann Mandant, dann System (CLAUDE.md: „als
Anschlussstelle bauen, die sich ersetzen lässt“). **Bitte entscheiden, ob so gestuft werden darf.**

### 9.2 Version: vergibt, wer herstellt (ST16, ST17)

**Entschieden:** Was eine Version ist, bestimmt der Betrieb: **bei Fremdherstellung nennt sie der Hersteller, bei
Selbstherstellung der Betrieb.** kumero zählt nichts von selbst und reicht nichts durch. Damit ändert sich 5.3:

- **Am Stück steht die Version als Angabe** (Text, wie der Hersteller sie schreibt: „Rev. B“, „2.4.1“) und **wer sie
  vergibt:** Hersteller oder Betrieb. Das Feld „Stand des Herstellers“ aus 5.3 geht darin auf. Am Katalogartikel steht,
  welche Versionen es von dem Produkt gibt.
- **Die Version ändert sich nur, wenn ein Mensch sie ändert** (Firmware eingespielt, Modul umgebaut und vom Betrieb neu
  versioniert). Jede Änderung bleibt mit Datum, Grund und wer stehen – die Version hat eine Geschichte, sie wird nie
  überschrieben.
- **Umbauten bleiben auch ohne neue Version nachvollziehbar:** Was wann wo steckte, sagt weiter die Belegung (5.3). Die
  Belegung hält die Version fest, die das Stück beim Einbau und beim Ausbau trug.
- **Selbst hergestellt:** Der Betrieb trägt die Version ein; kumero schlägt bei einem Umbau vor, eine neue zu vergeben,
  und verlangt es nicht.
- **Verworfen:** die automatische Versionsnummer je Umbau und „eine Version je gespeichertem Dialog“ aus 5.3.

### 9.3 Verträglichkeit je Version (neu)

**Vorgabe:** Manchmal sind bestimmte Versionen einfach nicht kompatibel zu bestimmten Geräten oder Modulen. „passt zu“
(6.4) reicht dafür nicht – es kennt nur Produkte.

| # | Vorschlag |
|---|---|
| VT1 | **„passt zu“ und „passt nicht zu“ nennen auf beiden Seiten wahlweise eine Version:** „Ladestation Rev. B passt nicht zu Scanner bis 1.9“. Ohne Version gilt die Angabe für alle Versionen des Produkts. |
| VT2 | **Versionen werden aufgezählt, nicht verglichen:** Eine Version ist Text; ob „2.10“ nach „2.9“ kommt, weiß kumero nicht. Eine Angabe nennt deshalb die betroffenen Versionen einzeln (Auswahl aus den Versionen des Produkts). Bereiche („ab …“) gibt es nur, wenn der Betrieb die Versionen eines Produkts in eine Reihenfolge gebracht hat. |
| VT3 | **„passt nicht“ geht vor „passt“.** Fehlt jede Angabe, sagt kumero nichts – unbekannt ist nicht unverträglich. |
| VT4 | **Geprüft wird beim Einbauen, Ersetzen und Umhängen und wenn eine Version geändert wird** – gegen das Gerät und gegen alle Module, in denen das Stück steckt und die in ihm stecken. |
| VT5 | **Folge einer Unverträglichkeit – bitte entscheiden:** Vorschlag: Der Einbau ist gesperrt und nennt den Grund; wer es trotzdem tut, bestätigt es ausdrücklich mit einer Begründung, die an der Belegung steht (wie die Abweichung vom Vorschlag bei der Entnahme, WW6). Das Gerät trägt danach den Zustand „unverträglich: …“ in Worten. Die Alternative ist ein bloßer Hinweis. |
| VT6 | **Wo die Angaben liegen:** Angaben zum Produkt – mit GTIN in der Fassung des Katalogs (PM9), sonst im Buch. Eigene Erfahrungen des Betriebs („bei uns verträgt sich das nicht“) stehen immer im Buch und kommen nie in den Katalog. |

### 9.4 Sets: gekauft und selbst gepackt (ST10, ST19)

**Entschieden:** Es gibt beides – **gekaufte Einmal-Sets** und **selbst gepackte Siebe mit aufbereiteten Instrumenten**.

- **Gekaufte Einmal-Sets** sind der Weg „fertig gekauft“ aus 5.6: ein Lagerartikel mit eigener Charge und eigenem Verfall,
  die Stückliste als Auskunft. Dafür reicht 5.6.
- **Selbst gepackte Siebe** bestehen aus zwei Sorten von Dingen, die kumero an zwei Stellen führt: **Instrumente**
  (bleiben, werden aufbereitet – in Nutzung, also Anlagen) und **Verbrauchsteile** (Tupfer, Klingen – Lager, mit Charge
  und Verfall). Vorschlag (SG1–SG6):

| # | Vorschlag |
|---|---|
| SG1 | **Das Sieb ist ein Gegenstand mit Stückliste in den Anlagen** (5.3): Seine Plätze nennen die Instrumente („1 Nadelhalter“, „2 Klemmen“), die Stücke sind die wirklichen Instrumente – mit oder ohne Seriennummer, mit Menge. Das ist derselbe Baum wie beim Gerät; nichts Neues. |
| SG2 | **Ein Platz kann statt eines Stücks einen Lagerartikel oder eine Artikelgruppe verlangen** („10 Tupfer“): ein Verbrauchsplatz. Er wird nicht belegt, sondern bei jedem Packen gefüllt. |
| SG3 | **Packen** ist ein Vorgang am Sieb: Die Instrumente werden bestätigt (fehlt eines, sagt der Vorgang welches – der Platz bleibt leer und das Sieb „unvollständig“), die Verbrauchsteile werden aus dem Lager entnommen (Vorschlag nach frühestem Verfall), und der Vorgang trägt Datum, wer gepackt hat und **„steril bis“**. Das Sieb zeigt danach „gepackt am …, steril bis …“. |
| SG4 | **Verbindung der Module:** Die Entnahme im Lager löst das Modul `anlagen` über einen Dienst der Modul-API aus (F44) – als Entnahme mit dem Zweck „Sieb“ und einem Verweis auf den Packvorgang (nur IDs). Fehlt dem Packenden das Recht `lager.bewegung.buchen`, ist „Packen“ gesperrt mit Grund. Ohne aktives Modul `lager` gibt es keine Verbrauchsplätze. |
| SG5 | **Verbrauchen und Zurückkommen:** „Verwendet“ beendet den Packvorgang (ohne Patientenbezug – höchstens der Zweck aus der Auswahlliste, WW14); die Verbrauchsteile sind mit dem Packen schon entnommen. Wird ein Sieb ungenutzt geöffnet oder läuft „steril bis“ ab, steht es als „neu zu packen“ da; ob unbenutzte Verbrauchsteile zurück ins Lager gehen, entscheidet der Mensch (Zugang mit dem Grund „Rückgabe aus Sieb“). |
| SG6 | **Rückverfolgung:** Von der Charge eines Verbrauchsteils zum Packvorgang und zum Sieb, und umgekehrt. |

**Abgrenzung, weiter als Vorschlag (bisher nicht widersprochen, nicht bestätigt):** Die Dokumentation der Aufbereitung –
Reinigung, Sterilisation, Freigabe, Chargen des Sterilisators – ist nicht Teil von kumero. „Steril bis“ in SG3 ist eine
Angabe des Menschen, kein Nachweis. **Bitte bestätigen oder widersprechen** – mit der Antwort „sowohl als auch“ rückt
kumero nah an diese Pflicht heran, und der Verantwortliche sollte sagen, wo die Grenze liegt.

### 9.5 Was jetzt noch offen ist

1. **EB7** – Lesart „im Katalog nur Arten und Merkmale der Ebene System“.
2. **Stufung aus 9.1** – erst Buch mit Anschlussstelle, dann Mandant, dann System?
3. **AR2** – Grundart (Vorschlag, gilt ohne Einwand).
4. **VT5** – Unverträglichkeit sperrt (mit Bestätigung und Begründung) oder weist nur hin?
5. **Aufbereitung** – außerhalb von kumero (9.4)?
6. **ST7** – die steuerliche Prüfung (5.5 am Ende); die Lesart selbst ist bestätigt.
7. Die übrigen Vorschläge (AR4, AR5, AR7, AR8, ST1, ST2, ST5, ST8, ST9, ST11–ST15, ST18, PM2, PM4–PM8, PM10, EB1–EB6,
   EB8, VT1–VT4, VT6, SG1–SG6) gelten wie üblich mit der Freigabe zum Bauen.
