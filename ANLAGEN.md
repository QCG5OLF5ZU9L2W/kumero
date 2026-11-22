# ANLAGEN.md – Anlagenbuchhaltung (Fassung 2: erste Stufe gebaut in 0.7.36)

Stand: 2026-11-20. Bezug: F9 (EÜR: Absetzung für Abnutzung statt Anschaffungskosten), F31 (Module erzeugen nur
Entwürfe), F44 (Module lesen den Kern nur über die Modul-API), **F61 (Modul `anlagen`)**, F76 (ohne Steuerberater
vollständig), F81 (Kontoart aus dem Bilanzposten), F126 (je Buch), Anhang A.
**Status: G1–G18 freigegeben am 2026-11-20, G7, G10 und G11 dabei vom Verantwortlichen geändert (Abschnitt 10). Die erste
Stufe ist gebaut (kumero 0.7.36, Modul `anlagen` 0.1.0, F129).**

> **Keine Steuerberatung.** Die genannten Grenzen und Fristen sind der Stand vom November 2026, wie er öffentlich
> beschrieben ist; der Verantwortliche prüft sie, bevor gebaut wird. Sie stehen später in einem Regelwerk mit
> Gültigkeitszeitraum, nicht im Programm (G6).

## 1. Anlass

Vorgabe des Verantwortlichen vom 2026-11-20: „Was mir noch fehlt, ist eine Anlagenbuchhaltung.“ Wer ein Wirtschaftsgut
anschafft, das länger als ein Jahr genutzt wird, darf die Anschaffungskosten nicht auf einmal abziehen; sie werden über
die Nutzungsdauer verteilt. Das gilt auch bei der Einnahmenüberschussrechnung, und dort ist ein Verzeichnis der
Anlagegüter zu führen. Ohne Anlagenbuchhaltung ist weder die EÜR (F9) noch eine Bilanz richtig.

## 2. Begriffe

| Begriff | Bedeutung |
|---|---|
| **Anlagegut** | ein Wirtschaftsgut des Anlagevermögens mit Anschaffungs- oder Herstellungskosten, Zugangsdatum und Nutzungsdauer |
| **AfA** | Absetzung für Abnutzung: der Teil der Kosten, der auf ein Jahr entfällt |
| **GWG** | geringwertiges Wirtschaftsgut: darf im Jahr der Anschaffung ganz abgezogen werden |
| **Sammelposten** | Topf eines Jahres für Güter mittleren Werts, über fünf Jahre gleichmäßig aufgelöst |
| **Buchwert** | Anschaffungskosten abzüglich aller bisherigen AfA |
| **Anlagenverzeichnis** | Liste aller Anlagegüter mit Zugang, Kosten, AfA des Jahres, Buchwert, Abgang |

## 3. Was das Modul tut

1. **Anlagegüter führen:** Bezeichnung, Zugangsdatum, Kosten, Nutzungsdauer, Art der Abschreibung, Bestandskonto und
   Aufwandskonto, Verweis auf die Buchung der Anschaffung (und damit auf den Beleg in Paperless), Bemerkung,
   Inventarnummer (frei), Standort (frei).
2. **Vorschlagen statt entscheiden:** Wird eine Buchung auf einem Anlagekonto festgeschrieben, steht sie im Modul als
   „noch kein Anlagegut“. kumero schlägt nach dem Betrag vor, was in Frage kommt (Sofortabzug, Sammelposten, Verteilung
   über die Nutzungsdauer); **welches Wahlrecht ausgeübt wird, entscheidet der Mensch.**
3. **AfA rechnen und als Entwurf buchen** (F31): Der AfA-Lauf eines Geschäftsjahres erzeugt je Anlagegut einen
   Buchungsentwurf mit Verweis auf das Gut. Festgeschrieben wird wie jede Buchung – von einem Menschen.
4. **Abgang:** Verkauf, Verschrottung oder Entnahme beenden die AfA mit dem Monat des Abgangs; der Rest des Buchwerts
   wird als Entwurf ausgebucht. Den Erlös bucht der Mensch.
5. **Anlagenverzeichnis ausgeben** (Bildschirm, Druck, CSV – wie AUSWERTUNGEN.md Abschnitt 4), mit der **Abstimmung
   zum Journal**: Die Summe der Buchwerte muss dem Saldo der Anlagekonten entsprechen; weicht sie ab, sagt die Seite,
   auf welchem Konto und um wie viel.

## 4. Arten der Abschreibung

| Art | Stufe | Regel (Stand 11/2026, zu prüfen) |
|---|---|---|
| **linear** | 1 | gleiche Jahresbeträge über die Nutzungsdauer; im Jahr des Zugangs und des Abgangs **nach Monaten** (der Monat des Zugangs zählt voll) |
| **GWG, Sofortabzug** | 1 | selbständig nutzbares, bewegliches, abnutzbares Gut bis 800 € netto: ganz im Jahr der Anschaffung; über 250 € netto mit Eintrag im Verzeichnis |
| **Sammelposten** | 1 | Güter über 250 € bis 1.000 € netto in einem Topf je Jahr, fünf Jahre je ein Fünftel, unabhängig von Abgang und Nutzungsdauer; das Wahlrecht gilt für ein Jahr einheitlich |
| **keine AfA** | 1 | nicht abnutzbar (z. B. Grund und Boden) – nur im Verzeichnis |
| degressiv | 2 | für bewegliche Güter mit Anschaffung nach dem 30.06.2025 und vor dem 01.01.2028: fester Satz vom Restwert, höchstens das Dreifache des linearen Satzes und höchstens 30 %; mit Wechsel zur linearen AfA |
| Sonderabschreibung, Leistungs-AfA, nachträgliche Kosten, Teilabgang, Zuschreibung | 2 | eigens zu beschreiben |

**Netto oder brutto:** Die Grenzen gelten netto. Wer keine Vorsteuer abzieht, hat als Anschaffungskosten den
Bruttobetrag; geprüft wird die Grenze trotzdem am Nettowert. Ob ein Buch Vorsteuer abzieht, ist eine Einstellung des
Buchs (G7).

## 5. Regeln

1. **Je Buch** (F126): Anlagegüter, Läufe und Einstellungen gehören zum Buch; eigenes Schema `anlagen` in der Datenbank
   des Buchs.
2. **Das Journal bleibt maßgeblich.** Das Modul schreibt nie in den Kern; es legt Entwürfe über die Modul-API an und
   liest festgeschriebene Buchungen. Was im Verzeichnis steht und nicht gebucht ist, zeigt die Abstimmung.
3. **Der AfA-Lauf ist wiederholbar:** Ein zweiter Lauf für dasselbe Jahr erzeugt nur, was fehlt oder sich geändert hat
   (als Differenz). Liegt für ein Gut schon ein festgeschriebener Betrag vor, wird er nicht überschrieben. In einem
   gesperrten Geschäftsjahr läuft nichts.
4. **Jahresweise** rechnen (ein Entwurf je Gut und Jahr); ein Monatslauf ist eine Einstellung für später.
5. **Nichts wird erfunden:** Eine Nutzungsdauer trägt der Mensch ein. kumero bietet die zuletzt verwendete Dauer für
   dieselbe Art von Gut an; die amtlichen AfA-Tabellen sind in dieser Stufe nicht hinterlegt.
6. **Änderungen sind nachvollziehbar:** Angaben eines Anlageguts, auf denen schon eine festgeschriebene AfA beruht
   (Kosten, Zugang, Nutzungsdauer, Art), werden nicht überschrieben, sondern mit Datum und Grund geändert; die alte
   Fassung bleibt sichtbar.
7. **Bestand übernehmen:** Ein Gut, das vor dem ersten Geschäftsjahr in kumero angeschafft wurde, wird mit
   Anschaffungskosten, bisher abgesetzter AfA und Buchwert zum Stichtag angelegt; die AfA läuft von dort weiter.

## 6. Oberfläche

- **Stammdaten → Anlagen:** Liste mit Inventarnummer, Bezeichnung, Zugang, Kosten, Art, Buchwert, Zustand (aktiv,
  abgeschrieben, abgegangen); oben die Zahl der Buchungen auf Anlagekonten ohne Anlagegut. Feste Werkzeugleiste:
  Neu, Bearbeiten, Abgang, Buchung öffnen, Beleg ansehen.
- **Anlagegut anlegen:** aus der Liste der offenen Buchungen (Kosten, Datum, Konto und Beleg sind vorbelegt) oder von
  Hand. Die Maske zeigt den Vorschlag zur Art mit seiner Begründung und den Plan der AfA über alle Jahre.
- **Buchführung → AfA-Lauf:** Geschäftsjahr wählen, Vorschau je Gut (Buchwert vorher, AfA, Buchwert nachher),
  „Entwürfe anlegen“. Die Entwürfe stehen danach in der Buchungsliste.
- **Auswertungen → Anlagenverzeichnis:** je Geschäftsjahr, mit Abstimmung zum Journal.

## 7. Schnitt

- **Modul `anlagen`** (F61), abhängig vom Kern und – für die Erkennung der Anlagekonten – vom Modul `kontenrahmen`.
- **Anlagekonten:** Welche Konten Anlagevermögen sind, folgt aus dem Bilanzposten des Kontenrahmens (F81); wo der
  fehlt (eigener Kontenplan), kennzeichnet der Nutzer die Konten im Modul.
- **Vom Kern gebraucht:** ein Ereignis oder eine Abfrage „festgeschriebene Buchungen auf diesen Konten seit …“ und das
  Anlegen von Entwürfen mit einer Herkunft (`anlagen:<gut>:<jahr>`), über die das Modul seine Buchungen wiederfindet.
  Beides über die Modul-API; erst der Vertrag (F18).
- Die EÜR (Modul `euer`) und die Bilanz lesen das Verzeichnis über die Modul-API des Moduls, nicht dessen Tabellen.

## 8. Entscheidungen zur Freigabe

| Nr. | Vorschlag |
|---|---|
| G1 | Eigenes Modul `anlagen`, je Buch, eigenes Schema; schreibt nur Entwürfe. |
| G2 | Erste Stufe: linear, GWG-Sofortabzug, Sammelposten, „keine AfA“; Abgang; Anlagenverzeichnis mit Abstimmung. |
| G3 | Degressive AfA, Sonderabschreibungen, nachträgliche Kosten, Teilabgang: zweite Stufe. |
| G4 | Anlagegüter entstehen aus festgeschriebenen Buchungen auf Anlagekonten (Vorschlag) oder von Hand. |
| G5 | kumero schlägt die Art nach dem Betrag vor; das Wahlrecht übt der Mensch aus. Wird im selben Jahr teils Sofortabzug, teils Sammelposten gewählt, weist kumero darauf hin, dass das Wahlrecht einheitlich gilt – und hindert nicht. |
| G6 | Grenzen (250 €, 800 €, 1.000 €), Sätze und Fristen stehen in einem **Regelwerk mit Gültigkeit** als Datei (wie die Regelwerke des Kontenrahmens), nicht im Programm. |
| G7 | Einstellung je Buch „zieht Vorsteuer ab: ja/nein“ – bestimmt, ob die Anschaffungskosten netto oder brutto sind. |
| G8 | AfA im Zugangs- und Abgangsjahr nach Monaten; der Monat des Zugangs zählt voll, der Monat des Abgangs zählt mit. |
| G9 | AfA-Lauf je Geschäftsjahr, ein Entwurf je Gut; wiederholbar; nie in einem gesperrten Jahr. |
| G10 | Ende der AfA: bei Art `euer` auf 0 €, bei Art `bilanz` auf einen Erinnerungswert von 1 €, solange das Gut vorhanden ist; je Buch änderbar. |
| G11 | Nutzungsdauer von Hand, mit Vorschlag aus den eigenen bisherigen Gütern; keine hinterlegten AfA-Tabellen in Stufe 1. |
| G12 | Konten je Anlagegut: Bestandskonto und Aufwandskonto; Vorgabe je Bestandskonto einstellbar. Gebucht wird direkt (Aufwand an Bestand), ohne Wertberichtigungskonto. |
| G13 | Abgang: AfA bis zum Monat des Abgangs, Restbuchwert als Entwurf ausbuchen; der Erlös ist eine gewöhnliche Buchung. |
| G14 | Sammelposten: ein Topf je Geschäftsjahr; Güter darin bleiben im Verzeichnis sichtbar, ein Abgang ändert den Topf nicht. |
| G15 | Bestand vor kumero: Anlegen mit bisheriger AfA und Buchwert zum Stichtag. |
| G16 | Rechte: `anlagen.gut.sehen`, `anlagen.gut.pflegen`, `anlagen.lauf.ausfuehren`; Rollenvorlagen: Erfasser sieht, Buchhalter pflegt und führt den Lauf aus. |
| G17 | Anlagenverzeichnis als Auswertung mit Abstimmung zum Saldo der Anlagekonten. |
| G18 | Nicht Teil des Moduls: Privatanteile (z. B. Kfz), Investitionsabzugsbetrag, Leasing, Inventur. |

## 9. Offen

- **Reihenfolge:** Das Anlagenverzeichnis nutzt die Ausgabe aus AUSWERTUNGEN.md (Druck, CSV). Vorschlag: erst die
  Auswertungen, Stufe 1, dann die Anlagen.
- **Digitale Wirtschaftsgüter** (Hard- und Software mit einjähriger Nutzungsdauer nach Verwaltungsauffassung): als
  Vorschlag der Nutzungsdauer aufnehmen? Zu prüfen.
- **Zusammengehörige Güter** (Rechner mit Bildschirm): ein Gut oder mehrere – entscheidet der Mensch; soll kumero
  mehrere Buchungen zu einem Gut zusammenfassen können (Stufe 1 oder 2)?
- **Übernahme aus einem anderen Programm** als Datei – nicht geplant; bei wenigen Gütern von Hand.

## 10. Was gebaut ist (kumero 0.7.36, F129)

**Entscheidungen des Verantwortlichen vom 2026-11-20, die den Entwurf ändern:**

| Nr. | Entscheidung |
|---|---|
| G7 | **Vorsteuer ist eine Frage des Buchs**, zu dem das Anlagevermögen gehört: ob es umsatzsteuerpflichtige Leistungen erbringt (eine Arztpraxis in der Regel nicht). Einstellung je Buch; ohne sie lässt sich kein Anlagegut anlegen. |
| G10 | **Das Ende der Absetzung bestimmt der Mandant im Buch** – und es hängt daran, wann und wie das Gut das Unternehmen verlässt. Gebaut: Vorgabe je Buch (0,00 € oder 1,00 € Erinnerungswert), je Anlagegut änderbar; beim Abgang wird der Rest des Buchwerts in jedem Fall ausgebucht. Die Art des Buchs (EÜR, Bilanz) legt nichts fest. |
| G11 | **Nutzungsdauer: Vorschlag aus den AfA-Tabellen, Entscheidung durch den Nutzer, bei Abweichung mit freiwilliger Begründung.** Gebaut: Auswahl aus dem Regelwerk setzt die Jahre; eine andere Zahl ist jederzeit möglich, der Vorschlag, seine Fundstelle und die Begründung stehen am Anlagegut. |

**Wo es steht:** Rechnung `module/anlagen/src/Rechner.php` (ohne Datenbank, in Cent: „bis zu einem Monat insgesamt abgesetzt“ –
die AfA eines Jahres ist die Differenz zweier Stände, deshalb ohne Rundungsrest und beliebig wiederholbar); Schema
`anlagen` (`einstellung`, `gut`, `afa`); REST `…/anlagen`, `/plan`, `/einstellung`, `/gueter`, `/gueter/{id}/abgang`, `/vermerke`;
Seite `oeffentlich/anlagen.js`; Regelwerk `regelwerk/afa-de.json`. Tests: `AnlagenRechnerTest`, `AnlagenTest`,
`tests/browser/anlagen.mjs`.

**Festlegungen aus der Umsetzung und Abweichungen – zu bestätigen:**

| Nr. | Festlegung |
|---|---|
| H1 | **Das Modul schreibt nicht ins Journal und liest keine Tabelle des Kerns.** Den Entwurf legt die Seite über die REST-Schnittstelle an, mit den Rechten dessen, der den Lauf ausführt; das Modul vermerkt nur, welche Buchung zu Gut und Jahr gehört. Ob sie Entwurf, festgeschrieben oder gelöscht ist, fragt die Seite beim Kern; ein gelöschter Entwurf zählt nicht mehr. Eine Herkunft `anlagen:…` an der Buchung (Abschnitt 7) gibt es nicht. |
| H2 | **Anlagekonten nennt der Nutzer** in den Einstellungen (Bereiche wie `0400-0499`); sie werden nicht aus dem Bilanzposten des Kontenrahmens abgeleitet (ändert G4/Abschnitt 7). |
| H3 | **Regelwerk:** Grenzen mit Gültigkeit ab 2018; Nutzungsdauern als **Auszug** der AfA-Tabelle „AV“ (29 Einträge), ohne Branchentabellen. Die Fundstellen der Fahrzeuge, Tresore und Foto-/Audiogeräte sind nur als „AV“ angegeben. Vom Verantwortlichen zu prüfen und zu ergänzen. |
| H4 | Sind die Kosten brutto (kein Vorsteuerabzug), schlägt kumero die Art erst vor, wenn der **Nettobetrag** eingetragen ist – die Grenzen gelten netto. |
| H5 | Der **Rest beim Abgang** wird auf das Aufwandskonto des Anlageguts gebucht (Entwurf, vor dem Festschreiben änderbar). Ein eigenes Konto für Anlagenabgänge ist nicht einstellbar. |
| H6 | **Änderungen** an einem Anlagegut überschreiben die Angaben; die alte Fassung steht mit Vorher und Nachher im Protokoll des Buchs (Regel 6 in anderer Form). Eine geänderte Angabe ändert die Rechnung auch rückwirkend – was schon gebucht ist, bleibt, die Differenz bietet der nächste Lauf an; ist zu viel gebucht, zeigt es nur die Abstimmung. |
| H7 | Wirtschaftsjahre gelten als zwölf Monate ab dem Beginn des gewählten Geschäftsjahres; Rumpfgeschäftsjahre sind nicht berücksichtigt. |
| H8 | Der Hinweis auf das einheitliche Wahlrecht beim Sammelposten (G5) ist **nicht gebaut**. |
| H9 | Rollen: Der Steuerberater führt die Anlagen wie der Buchhalter; Erfasser und Prüfer sehen nur. |

**Nicht gebaut:** degressive AfA, Sonderabschreibungen, nachträgliche Kosten, Teilabgang (G3); Monatslauf; mehrere Buchungen zu
einem Gut; das Anlagenverzeichnis als eigene Auswertung im Modul `auswertungen` (es steht auf der Seite „Anlagen“).

## Nachtrag 2026-11-22: Katalog, Seriennummer und Dokumente am Anlagegut (Entwurf, F165, P49)

Vorgabe des Verantwortlichen: Ein Gegenstand für den Eigenbedarf (Beispiel: ein Handscanner) gehört als Artikel in den
Katalog und dann in die Anlagen; ein eigenes Inventar gibt es nicht. Dafür fehlen am Anlagegut der Verweis auf den
Katalog, die Seriennummer, Dokument-Verweise (Datei in Paperless oder Link nach außen) und der Weg aus dem Wareneingang.
Beschrieben in LAGER.md Abschnitt 16 (IN1–IN8) und KATALOG.md KA20. **Nicht gebaut.**

**Entschieden am 2026-11-22 (LAGER.md IN9, IN10), noch nicht gebaut:** (1) Art **„fremd (gemietet, geleast)“** am
Anlagegut – ohne Kosten, ohne AfA, nie im Anlagenverzeichnis; damit lässt sich verzeichnen, was da ist, aber dem
Betrieb nicht gehört (ändert G18 für das Verzeichnen, nicht für das Rechnen). (2) **Kostenstelle am Anlagegut** als Tag
des Kerns, der in die AfA-Entwürfe übernommen wird.
