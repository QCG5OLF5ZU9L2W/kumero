# ZEIT.md – Zeiterfassung: Kommen, Gehen, Zeitkonto, Monatsabschluss (Fassung 3)

Stand: 2026-11-21. Bezug: PERSONAL.md (Person, Vertrag, Abwesenheiten, Zuschlagsregeln, Rollen, Ebene Mandant),
DIENSTPLAN.md, F106, F134. Vorlage der Funktionen: TimeTrex (Regeln als Bausteine), kein fremder Code.
**Status: Stufe 1 und 2 ohne Geräte gebaut mit 0.7.53 (Modul `zeit` 0.1.0, F145) – was gebaut ist und was abweicht, steht in
Abschnitt 9 und geht den Abschnitten 2 bis 7 vor. Terminal und Leser (Abschnitt 3, Stufe 3) sind nicht gebaut.**

**Abschnitt 11 (Gehen vergessen):** Schritt 1 gebaut mit 0.7.91 (`zeit` 0.6.0, F207) – die Dauer ist eine Regel des Mandanten; Schritt 2, die Regelsätze, ist noch zu entwerfen. **Gebaut mit 0.7.87 (`zeit` 0.5.0, F200):** Abschnitt 12 – nur die
fehlende Buchung nachtragen (Vorgabe vom 2026-11-25, F199; hebt DK9 auf); offen daraus NB3 und NB6.

> **Keine Rechtsberatung.** Abschnitt 6 nennt den Stand vom November 2026, wie er öffentlich beschrieben ist.

## 1. Anlass

Vorgabe des Verantwortlichen vom 2026-11-20: Eine Zeiterfassung gehört dazu – „quasi mit allem“: Anmeldung im Browser
mit Eintrag von Hand, gewöhnliche Kommen- und Gehen-Buchungen, gegebenenfalls **RFID-Anhänger mit Lesern im Netz**.
Die Zeiten sind die Grundlage für das Zeitkonto, für zeitabhängige Zuschläge und für die Lohnvorbereitung.

## 2. Was das Modul tut

1. **Stempeln:** Kommen, Gehen, Pause Beginn und Ende – auf drei Wegen, die dasselbe erzeugen:
   - **eigener Browser** (Rechner oder Telefon), angemeldet mit Passkey;
   - **gemeinsames Terminal:** ein Browser im Vollbild an einem Gerät der Praxis; die Person weist sich je Buchung
     aus (Anhänger am Leser, der wie eine Tastatur schreibt, oder Kurzkennung mit PIN);
   - **Leser im Netz:** ein RFID-Leser meldet Anhänger und Zeit über eine lokale Schnittstelle an kumero.
2. **Nachtragen von Hand:** Beginn, Ende, Pausen für einen Tag, mit Begründung. Ein Nachtrag ist gekennzeichnet.
3. **Nichts wird überschrieben:** Eine Korrektur ist eine neue Buchung, die die alte ersetzt; die alte bleibt
   sichtbar mit wer, wann, warum.
4. **Tageswerte:** aus den Buchungen Arbeitszeit, Pausen, Soll und Saldo des Tages. Das **Soll** kommt aus dem Vertrag
   (Wochenstunden und Verteilung) oder, wenn es ihn gibt, aus dem Dienstplan. Tage mit genehmigter Abwesenheit aus
   `personal` sind gefüllt; Feiertage kommen aus einem Kalender des Mandanten.
5. **Zeitkonto:** laufender Saldo, Überstunden, Ausgleich; Übertrag in den nächsten Monat.
6. **Hinweise statt Sperren:** fehlende Gehen-Buchung, Pause zu kurz, Tag zu lang, Ruhezeit zu kurz – als Hinweis an
   der Zeile und in einer Liste; gestempelt werden kann immer.
7. **Regeln (nach TimeTrex):** Zeitarten (regulär, Überstunden, Nacht, Sonntag, Feiertag), jede mit einem Fenster
   oder einer Schwelle. Aus einem Tag entstehen Stunden je Zeitart; **eine Schicht zählt für ein Fenster nur mit dem
   Teil, der darin liegt**. Daraus rechnet `personal` die zeitabhängigen Zuschläge.
8. **Monatsabschluss:** Die Person bestätigt ihren Monat, der Vorgesetzte gibt frei; danach ist der Monat gesperrt und
   geht in die Lohnvorbereitung. Berichtigt wird danach nur über einen Nachtrag im Folgemonat.
9. **Auswertung:** Monatsblatt je Person (Bildschirm, Druck, CSV) – das ist zugleich die Einzelaufzeichnung für
   Zuschläge.

## 3. Terminal und Leser

- **Anhänger** gehören zu einer Person; gespeichert wird die Kennung des Anhängers, kein weiteres Merkmal. Ein
  verlorener Anhänger wird gesperrt.
- **Terminal:** ein Gerät wird einmal vom Personal angemeldet und bekommt ein Geräte-Token, das nur stempeln darf –
  keine Einsicht in Daten. Die Seite zeigt nach der Buchung Name, Uhrzeit und Art für wenige Sekunden.
- **Leser im Netz:** eine schmale Schnittstelle `POST …/zeit/geraet/buchung` mit Geräte-Token, Kennung des Anhängers
  und Zeitpunkt. Leser sprechen kumero im eigenen Netz an; nichts geht nach außen (CLAUDE.md „Grenzen“). Fällt das
  Netz aus, sendet der Leser gepufferte Buchungen nach – maßgeblich ist sein Zeitpunkt, die Verspätung ist vermerkt.
- **Ort (ZE12, entschieden 2026-11-20):** Jedes Terminal und jeder Leser wird mit seinem Aufstellort angemeldet
  (Bezeichnung, z. B. „Empfang“). Die Buchung trägt das Gerät, und damit steht der Ort fest – das wird nicht versteckt,
  sondern im Monatsblatt gezeigt. Buchungen im eigenen Browser tragen „Browser“; geortet wird dort nicht (kein GPS).
  Je Person ist einstellbar, welche Wege gelten (z. B. nur Terminal).
- **Welche Geräte:** nicht festgelegt. Unterstützt wird, was die Schnittstelle bedienen kann; ein kleiner Vermittler
  für Leser mit eigenem Protokoll wäre ein eigenes Stück außerhalb von kumero.

## 4. Rollen (am Mandanten, mit PERSONAL.md)

| Rolle | darf |
|---|---|
| **Mitarbeiter** | eigene Zeiten stempeln, nachtragen, den eigenen Monat bestätigen; sieht nur das Eigene |
| **Vorgesetzter** | Zeiten seines Teams sehen, Nachträge und Monate freigeben |
| **Personal** | alles; Regeln, Feiertage, Anhänger und Geräte pflegen; Korrekturen für andere mit Begründung |
| **Lohn** | freigegebene Monate lesen |

## 5. Entscheidungen (zur Freigabe)

| # | Frage | Vorschlag |
|---|---|---|
| ZE1 | Genauigkeit | **Entschieden 2026-11-21:** minutengenau; Pausen werden automatisch nach der Rechtslage abgezogen (derzeit nach 6 Std. 30 Min., nach 9 Std. 45 Min.); über 10 Std. effektiver Arbeitszeit wird nicht mehr gerechnet, außer mit Sonderfreigabe; Regelarbeitstage stehen im Profil der Person; Buchungen außerhalb brauchen die Genehmigung des Vorgesetzten oder einen Schichtplan. |
| ZE2 | Wege | Browser, Terminal, Leser im Netz – alle erzeugen dieselbe Buchung mit Kennzeichen der Herkunft. |
| ZE3 | Korrektur | **Entschieden 2026-11-21** („wir sind nicht in der Buchhaltung“): Die Begründung bleibt sichtbar, die Korrektur selbst nicht. Je Person eingestellt: sofort wirksam oder mit Freigabe des Vorgesetzten. |
| ZE4 | Soll | **Zugestimmt 2026-11-21:** aus dem Vertrag; mit `dienstplan` aus der geplanten Schicht (nicht gebaut – der Dienstplan hängt nicht mit der Zeiterfassung zusammen). |
| ZE5 | Pausen | **Geklärt mit ZE1 (2026-11-21):** automatischer Abzug nach der Regel. Der frühere Vorschlag (nur Hinweis) gilt nicht. |
| ZE6 | Regelverstöße | **Geklärt mit ZE1 (2026-11-21):** Stempeln wird nie gesperrt; was über der Höchstzeit oder außerhalb der Regelarbeitstage liegt, zählt aber erst mit Freigabe. |
| ZE7 | Zeitarten und Fenster | pflegt der Mandant; vorbelegt Nacht, Sonntag, Feiertag, Überstunden. |
| ZE8 | Feiertage | **Zugestimmt 2026-11-21**, dazu: Feiertage und Brückentage sind im **Jahreskalender je Person** sichtbar (PERSONAL.md 10f). Die Feiertage kommen aus dem Regelwerk des Personals (F139). |
| ZE9 | Monatsabschluss | **Entschieden 2026-11-21:** kann, muss nicht – Einstellung des Mandanten: aus, die Person bestätigt, mit Freigabe. |
| ZE10 | Terminal | Geräte-Token, nur stempeln; Ausweis je Buchung durch Anhänger oder Kurzkennung mit PIN. |
| ZE11 | Leser im Netz | lokale Schnittstelle mit Geräte-Token; kein Dienst außerhalb. |
| ZE12 | Ort | **Entschieden 2026-11-20:** Der Ort ergibt sich aus dem Gerät, an dem gebucht wird; Terminal und Leser haben einen Aufstellort, die Buchung zeigt ihn. Keine Ortung im Browser (kein GPS). Je Person einstellbar, welche Wege gelten. |
| ZE13 | Leistungs- und Verhaltenskontrolle | **Bestätigt 2026-11-21.** Auswertungen nur je Person für Zeitkonto und Lohn; keine Ranglisten, keine Auswertung von Pausenverhalten. |
| ZE14 | Aufbewahrung | Monatsblätter sind Objekte mit eigener Löschfrist je Mitarbeiter (PERSONAL.md Abschnitt 8a). |

## 6. Regeln (Stand 11/2026, zu prüfen)

| Gegenstand | Was gilt | Folge |
|---|---|---|
| **Pflicht zur Zeiterfassung** | Arbeitgeber müssen die Arbeitszeit erfassen (Bundesarbeitsgericht 2022, gestützt auf das Arbeitsschutzgesetz); eine Form ist bisher nicht vorgeschrieben | Beginn, Ende und Dauer je Tag |
| **Geplante Reform** | Ein Referentenentwurf vom Juni 2026 sieht eine elektronische, taggleiche Erfassung vor; beschlossen ist er nicht | minutengenau und am selben Tag ist ohnehin der Vorschlag (ZE1) |
| **Pausen, Höchstzeit, Ruhezeit** (Arbeitszeitgesetz) | Grenzen je Tag und zwischen zwei Tagen | als Hinweise (ZE6); die genauen Werte sind vor dem Bauen nachzuschlagen |
| **Einzelaufzeichnung für Zuschläge** | steuerfreie Zuschläge brauchen den Nachweis der tatsächlich geleisteten Stunden | **Rechtspflicht** (entschieden 2026-11-20): Das freigegebene Monatsblatt ist dieser Nachweis; ohne es rechnet die Lohnvorbereitung keinen Zeitzuschlag (PERSONAL.md PE11) |

## 7. Stufen

| Stufe | Inhalt |
|---|---|
| **1** | Stempeln im Browser, Nachtragen, Korrektur, Tageswerte, Soll aus dem Vertrag, Zeitkonto, Hinweise, Monatsblatt. |
| **2** | Zeitarten und Fenster, Feiertage, Monatsabschluss mit Freigabe, Übergabe an die Lohnvorbereitung. |
| **3** | Terminal mit Anhänger oder Kurzkennung; Schnittstelle für Leser im Netz. |

## 8. Offen und ungeprüft

- Die Werte des Arbeitszeitgesetzes sind nicht einzeln nachgeschlagen; Sonderregeln (Jugendliche, Mutterschutz,
  Bereitschaft) sind nicht betrachtet.
- Welche Leser es gibt und was sie sprechen, ist nicht geprüft; ohne ein benanntes Gerät bleibt Stufe 3 eine
  Schnittstelle ohne Gegenstück.
- Mitbestimmung bei technischen Einrichtungen zur Überwachung ist nicht betrachtet.

## 9. Was gebaut ist (0.7.53, Modul `zeit` 0.1.0, F145)

**Schnitt (Vorgabe vom 2026-11-21):** Zeiterfassung ist ein Nutzerrecht und bekommt eine eigene Rolle – deshalb ein eigenes
Modul am Mandanten (`zeit`, baut auf `personal` ≥ 0.6.0 auf), dessen Seite „Meine Zeiten“ im Menü unter Personal → Meine
Daten steht. Der Dienstplan gehört nicht dazu.

| Rolle | bekommt | darf |
|---|---|---|
| **Zeiterfassung** (neu, eigene Rolle des Moduls) | Zeit: Eigene Zeiten | stempeln, nachtragen und berichtigen lassen, eigenes Zeitkonto sehen, eigenen Monat bestätigen |
| **Vorgesetzter** | Zeit: Team freigeben | Zeiten der unmittelbar Unterstellten sehen; Anträge, Tage und Monate freigeben – nie die eigenen |
| **Personal** | Zeiten führen | alles: Zeitprofile, Regeln, für alle nachtragen, freigeben, Monate wieder öffnen |
| **Lohn** | Zeit: Zeiten ansehen | Zeiten und Zeitkonten aller lesen |

Die Rolle „Mitarbeiter“ bekommt die Zeiterfassung **nicht** von selbst: Wer stempelt, entscheidet der Administrator.

**Seiten:** „Meine Zeiten“ (Kommen, Gehen, Nachtragen oder berichtigen, Monat bestätigen, Monatsblatt, eigene Anträge) und
„Zeiten“ (Liste der Personen mit Summen und dem, was auf eine Entscheidung wartet; Monatsblatt; Antrag entscheiden, Tag
freigeben, Monat freigeben oder öffnen, Nachtragen, Zeitprofil, Regeln).

**Festlegungen – zu bestätigen:**

| # | Festlegung |
|---|---|
| T1 | **Abgezogen wird nur, was an Pause fehlt, und nie unter die Schwelle.** Wer 6:10 ohne Pause arbeitet, bekommt 10 Minuten abgezogen (zählt 6:00), nicht 30; wer 20 Minuten Pause gestempelt hat, bekommt 10 dazu. Eine Pause ist die Lücke zwischen zwei Abschnitten eines Tages. Der Abzug ist im Monatsblatt mit * gekennzeichnet. |
| T2 | **Die Werte sind Regeln des Mandanten** (Pausenstufen, Höchstzeit, Nachtfenster, Zeitzone, Monatsabschluss) mit Vorgabe aus `regelwerk/arbeitszeit-de.json` (Stand 2026-11-21); das Personal ändert sie unter „Regeln …“ und kann zur Vorgabe zurück. |
| T3 | **Höchstzeit 10 Std. nach Abzug der Pause.** Darüber zählen 10 Std.; mit Sonderfreigabe des Tages zählt alles. Eine abgelehnte Freigabe bleibt als Entscheidung stehen. |
| T4 | **Regelarbeitstage:** die Tage mit Soll aus dem Vertrag; das Zeitprofil kann eigene sieben Wochentage setzen. An einem anderen Tag, an einem Feiertag oder freien Tag des Betriebs zählt gestempelte Zeit **0, bis der Tag genehmigt ist**. Der Schichtplan als zweite Quelle ist nicht gebaut. |
| T5 | **Gleitzeit:** Rahmen (davor und danach zählt nichts), Kernzeit (nicht abgedeckt → Hinweis, kein Abzug), Zeitkonto. Feste Arbeitszeit führt dasselbe Zeitkonto ohne Rahmen. |
| T6 | **Zeitkonto:** Anfangsstand aus dem Zeitprofil und die Salden aller abgelaufenen Tage seit dem Beginn im Zeitprofil – ohne Angabe ab dem ersten gebuchten Tag, damit die Zeit vor der Einführung kein Minus erzeugt. Der laufende Tag zählt nicht. Eine Kappung oder Auszahlung gibt es nicht. |
| T7 | **Bezahlte Abwesenheit** schreibt ihren Anteil des Solls gut; das Modul erfährt vom Personal nur Anteil und „bezahlt“, nie die Art. |
| T8 | **Nachtrag und Korrektur sind Anträge mit Begründung.** Zeitprofil „sofort“: wirkt gleich; „mit Freigabe“ (Vorgabe): Vorgesetzter oder Personal entscheidet. Nach dem Übernehmen steht am Abschnitt ✎ und die Begründung; **der alte Wert wird nicht aufbewahrt**. Auch das Protokoll nennt nur IDs. |
| T9 | **Monatsabschluss, Vorgabe „die Person bestätigt“.** Aus: nichts wird gesperrt. Person: bestätigt sperrt. Freigabe: Person bestätigt, Vorgesetzter oder Personal gibt frei. Bestätigen geht erst nach Monatsende; öffnen kann nur das Personal. |
| T10 | **Lohnvorbereitung:** bei „Freigabe“ zählt nur der freigegebene, bei „Person“ der bestätigte Monat, sonst „Nachweis fehlt“; bei „aus“ zählt der Stand von jetzt mit Vermerk „ohne Monatsabschluss“. **Das weicht bei „Person“ und „aus“ von PE11 ab** (dort: ohne freigegebenes Monatsblatt kein Betrag) – solange kein Betrag gerechnet wird, hat es keine Folge; vor dem Grundlohn zu entscheiden. |
| T11 | **Nacht 20:00–06:00** als Vorgabe (das Fenster der steuerfreien Zuschläge), Sonntag und Feiertag nach Kalendertag; die Stunden in den Fenstern sind Arbeitszeit vor Abzug der Pause. |
| T12 | **Ein Abschnitt über Mitternacht gehört zum Tag seines Beginns.** Wer nicht ausstempelt, bleibt eingestempelt; der offene Abschnitt zählt nicht und wird über einen Nachtrag geschlossen. **Wie lange „eingestempelt“ gilt, wird neu geregelt – Abschnitt 11 (Entwurf).** |
| T13 | **Uhrzeiten sind Ortszeit des Mandanten** (Vorgabe Europe/Berlin), nicht die des Browsers. |
| T14 | **Niemand gibt sich selbst frei** – außer wer das Personal führt (wie AW6, kleine Betriebe). |
| T15 | **Lohn sieht alle Zeiten**, nicht nur freigegebene Monate (Abschnitt 4 sagte „freigegebene“): Ohne Pflicht zum Abschluss gäbe es sonst nichts zu sehen. |
| T16 | **Keine Auswertung über Personen hinweg** außer der Liste des Monats für die, die freigeben; keine Rangfolge, keine Pausenstatistik (ZE13). |

**Berichtigt mit 0.7.55 (`zeit` 0.1.1):** Wer noch keinen Vertrag hat, steht in der Liste „Zeiten“ mit dem Vermerk „ohne
Vertrag“ (kein Soll, keine Regelarbeitstage – gestempelte Zeit zählt dann erst mit Genehmigung). Eine leere Liste nennt
ihren Grund. Die Seite „Zeiten“ ist die Übersicht; sie sagt, dass Kommen und Gehen unter „Meine Zeiten“ stehen und was
dafür fehlt (Rolle „Zeiterfassung“, Verknüpfung mit einer Person).

**Geändert mit 0.7.57 (`zeit` 0.2.0, F149):** Das Monatsblatt ist eine Tabelle wie jede andere – Sortierung und Filter am
Spaltenkopf, Spalten verschiebbar (F91, F146); die Summe steht im Fuß und gilt für den ganzen Monat, auch wenn gefiltert ist.

**Nicht gebaut:** Terminal, Anhänger, Leser im Netz (ZE2, ZE10–ZE12); eigene Zeitarten und Fenster des Mandanten über das
Nachtfenster hinaus (ZE7); Ruhezeit zwischen zwei Tagen; Schichtplan; Beträge der Zeitzuschläge (Grundlohn fehlt);
Löschfrist der Monatsblätter (ZE14); Druck des Monatsblatts.

**Geprüft:** `tests/ZeitTest.php` (209 Prüfungen), die Seiten im Browserlauf `tests/browser/personal.mjs`. **Nicht geprüft:**
die Werte gegen das Arbeitszeitgesetz im Wortlaut, Sonderregeln (Jugendliche, Mutterschutz, Bereitschaft), Mitbestimmung,
Sommerzeitwechsel in einer Nachtschicht, ein Lauf auf der VM.

## 10. Anmerkung, laufende Zeit, Aufbewahrung, Doppelklick (gebaut mit 0.7.58, `zeit` 0.3.0, F150)

Vorgaben des Verantwortlichen vom 2026-11-21 am Bild der Seite „Meine Zeiten“.

| # | Festlegung – zu bestätigen |
|---|---|
| DK1 | **Die Anmerkung hängt am Abschnitt** (Kommen bis Gehen), nicht am Tag: bis 200 Zeichen, freier Text der Person, mit dem Hinweis „keine Gesundheitsangaben“. Sie lässt sich ändern, solange der Monat offen ist; die frühere wird nicht aufbewahrt. Vorgesetzte, Personal und Lohn lesen sie im Monatsblatt, ändern sie aber nicht. |
| DK2 | **Die angelaufene Zeit im Kopf ist die Zeit seit dem Kommen**, ohne Abzug von Pausen, gerechnet aus dem Stand beim Laden und der Uhr des Browsers. Maßgeblich bleibt, was der Server beim Gehen stempelt. |
| DK3 | **Aufbewahrung:** § 17 Abs. 1 MiLoG verlangt, die Aufzeichnungen mindestens zwei Jahre aufzubewahren (ebenso § 16 Abs. 2 ArbZG für seine Nachweise). Gelesen ist das als **Mindestfrist**: Die Regel steht mit 24 Monaten in der Steuerdatei und an der Einstellung; **kumero löscht keine Zeiten**, die Frist ist damit eingehalten. Ob Zeiten nach Ablauf gelöscht werden sollen (von selbst oder auf Bestätigung, wie PERSONAL.md 8a), ist nicht gebaut und zu entscheiden. In der Oberfläche lässt sich die Zahl noch nicht ändern. |
| DK4 | **Doppelklick im Monatsblatt:** unter „Meine Zeiten“ die Einzelheiten des Tages (Anmerkungen; dazu steht, was auf eine Genehmigung wartet – entscheiden kann die Person das nicht selbst); unter „Zeiten“ die Genehmigung des Tages, sonst der offene Antrag. |
| DK5 | **Faktura auf Kunden und Projekte** ist nur vorgemerkt (AGENTS.md P41) – der Abschnitt trägt noch keinen Kunden und kein Projekt. |
| DK6 | **Verfall von Stunden** (Ausschlussfristen, Kappung des Zeitkontos) ist etwas anderes als die Aufbewahrung und nicht gebaut (T6). Falls mit „Verfallsregeln“ das gemeint war, bitte sagen. |

Geprüft: `tests/ZeitTest.php` (219), Browserlauf `personal.mjs` (127). Nicht geprüft: die laufende Zeit über Mitternacht und
bei falsch gestellter Uhr des Rechners.

**Ergänzt mit 0.7.59 (`zeit` 0.4.0, F151):**

| # | Festlegung – zu bestätigen |
|---|---|
| DK7 | **Wo gearbeitet wird, ist eine feste Liste:** im Betrieb, mobiles Arbeiten, Dienstgang. Das „etc.“ der Vorgabe ist damit nur um den Dienstgang gefüllt; weitere Arten (oder eine Liste, die der Mandant pflegt) fehlen. Die Angabe hängt am Abschnitt; wer zwischendurch wechselt, stempelt Gehen und Kommen. |
| DK8 | **Gezählt wird überall gleich.** Es gibt keine eigene Summe „mobil“ im Monat und keine eigene Regel (etwa andere Pausen); nach „mobil“ lässt sich im Monatsblatt filtern. Die Lohnvorbereitung bekommt die Unterscheidung nicht. |
| DK9 | **Nachtrag und Korrektur buchen immer beide Ereignisse**, Kommen und Gehen. Ein einzelnes vergessenes „Gehen“ wird als Korrektur des offenen Abschnitts mit beiden Uhrzeiten eingetragen – ein Formular nur für das eine Ereignis gibt es nicht. **Abgelehnt vom Verantwortlichen am 2026-11-25** („macht keinen Sinn“) – ersetzt mit 0.7.87 durch Abschnitt 12 (F199, F200). |

## 11. Gehen vergessen – wie lange ein offener Abschnitt als laufend gilt (GV2, GV4, GV5, GV8 gebaut mit 0.7.91; GV3 offen)

**Status: entschieden am 2026-11-25, 10:41 (F206) – GV2, GV3, GV4, GV5, GV8; die Entscheidungen stehen an den Zeilen. Die beiden Rückfragen sind am selben Tag um 10:43 beantwortet („10 Stunden Arbeitszeit“, „ok am Mandanten“); Schritt 1 ist gebaut mit 0.7.91 (F207), siehe unter der Tabelle. Der Entwurf der Regelsätze (GV3) steht aus.** Gemeldet vom Verantwortlichen am 2026-11-25 an einem Bild von „Meine Zeiten“
(offener Abschnitt, „läuft seit 91:19 Std.“, „Kommen“ gesperrt): Wer vergessen hat zu gehen, kann am nächsten Arbeitstag
nicht sofort wieder kommen. Das widerspricht Abschnitt 2 Nr. 6 und ZE6 („gestempelt werden kann immer“).

**Was ohne Freigabe gebaut wurde (0.7.82, `zeit` 0.4.1) und warum es so nicht bleibt:** eine feste Grenze von 13 Stunden
(24 Stunden weniger elf Stunden Ruhezeit nach § 5 Abs. 1 ArbZG), nach der „Kommen“ frei und „Gehen“ gesperrt ist; der alte
Abschnitt bleibt offen bis zur Korrektur. Abgelehnt vom Verantwortlichen am 2026-11-25: Das bildet nur die Regel des Gesetzes
ab, nicht Notfälle und andere Ereignisse; **die Arbeitszeit ist eine Regel des Mandanten, es gibt oft Sonderregeln, das muss
einstellbar sein** – und es wurde gebaut, ohne vorher zu schreiben. 0.7.82 ist deshalb **nicht zu taggen**, bis GV8 entschieden
ist.

**Woran die feste Grenze scheitert** (ArbZG, wie öffentlich beschrieben – nicht im Wortlaut geprüft, keine Rechtsberatung):
verkürzte Ruhezeit in Krankenhäusern und Pflege (§ 5 Abs. 2, 3); längere Dienste mit Arbeitsbereitschaft oder
Bereitschaftsdienst nach Tarifvertrag oder Betriebsvereinbarung (§ 7); Notfälle und außergewöhnliche Fälle (§ 14);
Bewilligungen der Aufsichtsbehörde (§ 15); Personen, für die das Gesetz nicht gilt, etwa leitende Angestellte und Chefärzte
(§ 18). Dazu, was ein Betrieb für sich vereinbart hat.

| # | Vorschlag – zu entscheiden |
|---|---|
| GV1 | **„Kommen“ wird durch einen alten offenen Abschnitt nie gesperrt** (ZE6). Zu regeln ist nur, **wie lange ein offener Abschnitt als laufend gilt** – solange ist die Person „eingestempelt“ und „Kommen“ gesperrt, weil sie schon da ist. |
| GV2 | **Diese Dauer ist eine Regel des Mandanten** („Offener Abschnitt gilt als laufend bis … Stunden“): Spalte in `zeit.einstellung`, Vorgabe in `regelwerk/arbeitszeit-de.json`, gepflegt vom Personal im Dialog „Regeln …“, mit „zurück zur Vorgabe“ (T2, F139). Die 13 Stunden wären nur noch die Vorgabe der Steuerdatei – **welcher Wert dort stehen soll, entscheidet der Verantwortliche**. **Entschieden 2026-11-25 (F206): „AZG 10 Stunden“** – die Vorgabe der Steuerdatei ist 10 Stunden (statt 13), angelehnt an die Höchstarbeitszeit nach § 3 ArbZG. **Rückfrage:** 10 Stunden seit dem Kommen des Abschnitts, oder 10 Stunden Arbeit zuzüglich der Pause nach der Regel (heute 45 Minuten, also 10:45 seit dem Kommen)? Wer keine Pause stempelt, ist an einem vollen Tag länger als 10 Stunden anwesend und müsste sonst jedes Mal das Gehen nachtragen. **Antwort 10:43: „10 Stunden Arbeitszeit“** – also Arbeit zuzüglich der Pause nach der Regel. Gebaut. |
| GV3 | **Sonderregel je Person im Zeitprofil:** eigener Wert, leer = Regel des Mandanten (z. B. 26 Stunden für Bereitschaftsdienste). Ändern darf, wer Zeitprofile pflegt. **Frage:** Genügt der einzelne Wert je Person, oder braucht es **benannte Regelsätze** (z. B. „Bereitschaftsdienst“, „Leitung“), die der Mandant anlegt und Personen zuweist – und die dann auch Pausenstufen und Höchstzeit tragen, die heute nur für den ganzen Mandanten gelten? Das wäre der größere, eigene Schritt. **Entschieden 2026-11-25 (F206): „Regelsätze mit konfigurierbaren Regeln“** – also benannte Regelsätze, die der Mandant anlegt und Personen zuweist, nicht nur ein Wert je Person. Das ist der größere, eigene Schritt und braucht einen eigenen Entwurf (welche Regeln ein Satz trägt, Vorgabe-Satz, Zuweisung, Fassungen). |
| GV4 | **Notfälle und andere Ereignisse lassen sich nicht vorab einstellen.** Vorschlag: Nach Ablauf der Dauer ist **„Gehen“ nicht gesperrt**, sondern fragt nach: Der Abschnitt lief länger als die Regel – **Grund wählen** und mit der Uhrzeit von jetzt schließen. Der Tag läuft dann über die vorhandene Sonderfreigabe (T3): Was über der Höchstzeit liegt, zählt erst, wenn Vorgesetzter oder Personal den Tag freigeben. So geht nach einem Notfalldienst nichts verloren und es braucht keinen Korrekturantrag. Daneben bleibt „Kommen“ frei (GV1) für den, der tatsächlich nur vergessen hat. **Entschieden 2026-11-25 (F206), anders als vorgeschlagen:** „Gehen geht immer zu buchen, solange GV8 nicht überschritten ist. Danach ist eine Korrektur vorzunehmen.“ Gelesen: Innerhalb der eingestellten Dauer schließt „Gehen“ den Abschnitt ohne Rückfrage; danach nimmt „Gehen“ ihn nicht mehr an, und das Gehen wird nachgetragen (Abschnitt 12, seit 0.7.87 mit nur einer Uhrzeit). **Kein Dialog mit Grund beim Stempeln.** |
| GV5 | **Die Gründe sind eine Liste des Mandanten** (Vorgabe: Notfall / außergewöhnlicher Fall, Bereitschaftsdienst, angeordnete Mehrarbeit, Sonstiges mit Text), keine Gesundheitsangaben; der Grund steht am Abschnitt und im Monatsblatt. **Frage:** Welche „anderen Ereignisse“ gehören in die Vorgabe? **Entschieden 2026-11-25 (F206): „Freitext, der eingegeben werden kann“** – keine Liste von Gründen des Mandanten. Gelesen mit GV4: Der Freitext ist die Begründung des Nachtrags, die es schon gibt (bis 200 Zeichen, keine Gesundheitsangaben). |
| GV6 | **Der vergessene Abschnitt bleibt offen stehen**, zählt nicht, trägt „Gehen fehlt“ und steht im Kopf von „Meine Zeiten“; geschlossen wird er über eine Korrektur mit beiden Uhrzeiten (DK9) – **geändert mit 0.7.87 (Abschnitt 12): nur das fehlende Gehen wird nachgetragen**. kumero schließt nichts von selbst und rät kein Ende. Eine Person kann deshalb mehrere offene Abschnitte haben; laufend ist höchstens der jüngste. |
| GV7 | **Nicht Teil dieses Schritts:** die Ruhezeit zwischen zwei Tagen als Hinweis (Abschnitt 9 „nicht gebaut“) und der Schichtplan als Quelle der zulässigen Dauer. |
| GV8 | **Was mit 0.7.82 geschieht:** (a) zurücknehmen und nach der Freigabe neu bauen, oder (b) stehen lassen und darauf umbauen – brauchbar bleiben der nicht mehr eindeutige Index, die Sperre gegen doppeltes Kommen, `gehen_fehlt` in der Antwort und die Anzeige im Kopf; die feste Grenze und das gesperrte „Gehen“ entfallen in jedem Fall. **Entschieden 2026-11-25 (F206): „Parametrisierbar pro Mandanten und Buch“** – gelesen als (b): 0.7.82 bleibt stehen, die feste Grenze von 13 Stunden wird ein einstellbarer Wert (GV2). **Rückfrage zu „und Buch“:** Die Zeiterfassung liegt am Mandanten (F135 – Personen gehören zum Mandanten, nicht zu einer Buchhaltung); ein Buch kennt keine Zeiten. Soll eine Person einem Buch zugeordnet werden und dessen Wert erben, oder ist die Stufe unter dem Mandanten der Regelsatz (GV3)? **Antwort 10:43: „ok am Mandanten“** – nicht je Buch. Gebaut. |
| GV9 | **Von der fehlenden Buchung direkt zum Korrekturantrag** (Vorgabe des Verantwortlichen vom 2026-11-25 am Bild von „Meine Zeiten“ nach dem Update auf 0.7.82; **nur dokumentiert, beim nächsten Bau mit einzuplanen**): Der Hinweis „▲ Gehen fehlt: Tag, Uhrzeit“ im Kopf nennt heute nur in der Hilfe beim Überfahren, was zu tun ist. Er wird ein Verweis, der den Dialog „Nachtragen oder berichtigen“ **fertig vorbelegt** öffnet: Art „Korrigieren“, der betroffene Abschnitt gewählt, Beginn eingetragen, der Zeiger im Feld für das Ende. **Genauso am Hinweis „! Gehen fehlt“ in der Spalte „Hinweise“ des Monatsblatts** (Vorgabe vom selben Tag am Bild der Zeile: „hier genauso“) – derselbe Verweis, derselbe vorbelegte Dialog. Vorschlag dazu, nicht vorgegeben: der Abschnitt ist auch dann wählbar, wenn er in einem anderen als dem angezeigten Monat liegt (heute ist dorthin zu blättern); bei mehreren offenen Abschnitten je einer. Gesperrt mit Grund, wenn der Monat des Abschnitts abgeschlossen ist (F106). Mit der Tastatur erreichbar. **Gebaut mit 0.7.87 in der Fassung von Abschnitt 12 (NB8): Art „Gehen nachtragen“, Zeiger im Feld „Gehen“.** |

**Gebaut mit 0.7.91 (`zeit` 0.6.0, Migration `0006_offen_dauer.sql`) – Schritt 1:** GV1, GV2, GV4, GV5, GV8. Die Einstellung trägt
`offen_minuten` (Vorgabe 600 aus der Steuerdatei); das Personal pflegt den Wert unter „Zeiten“ → „Regeln …“ als „Offener
Abschnitt läuft bis (Std.:Min. Arbeit)“ und kann zur Vorgabe zurück. **Nicht gebaut:** GV3 (Regelsätze) – Schritt 2, eigener
Entwurf; GV7.

| # | Festlegung aus der Umsetzung – zu bestätigen |
|---|---|
| OD1 | **Arbeitszeit heißt dasselbe wie im Monatsblatt (T1):** die Abschnitte des Tages, der offene bis jetzt gerechnet, abzüglich dessen, was an Pause nach der Regel fehlt. Ohne gestempelte Pause gilt ein Abschnitt ab 8:00 mit der Vorgabe bis 18:45 als laufend (10 Std. Arbeit, 45 Min. Pause). |
| OD2 | **Frühere Abschnitte desselben Tages zählen mit.** Wer von 6:00 bis 12:00 gearbeitet hat und um 12:30 wieder kommt, gilt bis 16:45 als eingestempelt, nicht bis in die Nacht. Der Rahmen der Gleitzeit bleibt dabei außer Betracht. |
| OD3 | **Der Wert liegt zwischen 1 und 48 Stunden** und ist unabhängig von der Höchstzeit (T3), auch wenn beide in der Vorgabe 10 Stunden sind: Die Höchstzeit sagt, was zählt; dieser Wert sagt, wie lange „Gehen“ den Abschnitt noch annimmt. |
| OD4 | **Nach dem Update gilt bei jedem Mandanten die Vorgabe** von 10 Stunden statt der festen 13 – ein höherer Wert ist von Hand zu setzen. |

Geprüft: `tests/ZeitTest.php` (261), Browserlauf `personal.mjs` (166) – PHP 8.3, Symfony 7.4, PostgreSQL 16.

Unverändert: Der Monat lässt sich nicht bestätigen, solange in ihm ein Abschnitt offen ist. Die Korrektur wählt den Abschnitt
aus dem angezeigten Monat (das nachgetragene Gehen seit 0.7.87 aus allen offenen Abschnitten).

## 12. Nur die fehlende Buchung nachtragen (gebaut mit 0.7.87, `zeit` 0.5.0, F200)

**Status: gebaut ohne NB3 und NB6** – Auftrag des Verantwortlichen vom 2026-11-25, 07:10: „bau die Korrektur bitte“, gelesen
als Freigabe dieses Abschnitts ohne die beiden offenen Fragen; als eigener Schritt vor Abschnitt 11. Was gebaut ist, steht
unter der Tabelle. Vorgabe des Verantwortlichen vom 2026-11-25, 07:01,
am Bild des Dialogs „Nachtragen oder berichtigen“ (im Monatsblatt ein offener Abschnitt „05:10–…“; im Dialog Art
„Nachtragen – ein Abschnitt fehlt“, nur „Gehen“ eingetragen, Begründung „ausbuchen vergessen“, Meldung „Kommen und Gehen
gehören dazu – beide Uhrzeiten“): **Das Paar vollständig von Hand anzugeben, wenn schon Buchungen im Arbeitszeitkonto
stehen, ergibt keinen Sinn. Es wird nur die fehlende Buchung nachgetragen.**

Das hebt DK9 auf und ändert GV6 und GV9, soweit sie „beide Uhrzeiten“ verlangen.

**Wie es bis 0.7.86 gebaut war** (`zeit` 0.4.1, am Code gelesen am 2026-11-25): Der Dialog ist für „Meine Zeiten“ und „Zeiten“
derselbe (`antragFelder` in `module/zeit/oeffentlich/gemeinsam.js`) und kennt drei Arten – Nachtragen, Korrigieren,
Entfernen. Vorgewählt ist „Nachtragen“; das legt einen neuen Abschnitt an, sperrt die Auswahl „Abschnitt“ und verlangt
beide Uhrzeiten. Der offene Abschnitt ließe sich nur über „Korrigieren“ schließen; das sagt die Liste „Was“ nicht, und auch
dort sind beide Felder leer und Pflicht. Beide Uhrzeiten verlangen der Dialog, `WebController::antrag()` und die Funktion
`zeit.antrag_stellen` („Beginn und Ende gehören dazu“).

| # | Vorschlag – zu entscheiden |
|---|---|
| NB1 | **Grundsatz: Was gestempelt ist, wird nicht noch einmal abgefragt.** Steht zu einem Kommen kein Gehen, wird nur das Gehen eingetragen. Das gestempelte Kommen steht fest; es wird gezeigt, nicht eingegeben und durch den Nachtrag nicht verändert. |
| NB2 | **Eigene Art in der Liste „Was“: „Gehen nachtragen – ein Abschnitt ist offen“.** Die Auswahl „Abschnitt“ nennt dann nur die offenen Abschnitte; das Feld „Kommen“ bleibt an seinem Platz, ist mit der gestempelten Uhrzeit gefüllt und gesperrt, mit dem Grund beim Überfahren (F106 – nichts wird ein- oder ausgeblendet). Einzugeben sind die Uhrzeit des Gehens und die Begründung. Gibt es einen offenen Abschnitt, der nicht mehr als laufend gilt (Abschnitt 11), ist diese Art beim Öffnen des Dialogs vorgewählt. Ohne offenen Abschnitt ist die Art gesperrt, mit Grund. |
| NB3 | **Der Antrag trägt nur das Ende.** Beim Übernehmen wird der offene Abschnitt geschlossen; das Kommen behält Uhrzeit und Herkunft (gestempelt). ✎ und Begründung stehen wie bisher am Abschnitt (T8). **Frage:** Soll das Kennzeichen künftig am einzelnen Ereignis stehen (hier: nur am Gehen), damit das Monatsblatt zeigt, welche der beiden Uhrzeiten von Hand kommt? |
| NB4 | **Geprüft wird nur das Gehen:** nach dem Kommen – eine Uhrzeit vor dem Kommen heißt wie heute „am nächsten Tag“ (T12); nicht in der Zukunft; nicht über das nächste Kommen derselben Person hinaus. Was dadurch über der Höchstzeit liegt, zählt erst mit der Sonderfreigabe des Tages (T3). „Wo“ und Anmerkung bleiben, wie sie beim Kommen gesetzt wurden. |
| NB5 | **Freigabe wie bisher** (T8: sofort oder mit Freigabe, nie durch die Person selbst – T14). Wer entscheidet, sieht im Antrag das gestempelte Kommen und das beantragte Gehen nebeneinander. |
| NB6 | **Der umgekehrte Fall – das Kommen fehlt.** Wer vergessen hat zu kommen, hat keinen offenen Abschnitt und kann nicht gehen. Vorschlag: zweite Art **„Kommen nachtragen“** mit nur der Uhrzeit des Kommens. Liegt sie am heutigen Tag, entsteht nach dem Übernehmen ein laufender Abschnitt und „Gehen“ wird gewöhnlich gestempelt; es ist keine zweite Uhrzeit von Hand nötig. **Frage:** Gehört das dazu? Bei „mit Freigabe“ liefe der Abschnitt erst nach der Entscheidung – soll er bis dahin als laufend, aber ungeprüft gelten? |
| NB7 | **Beide Uhrzeiten nur noch dort, wo beide fehlen oder beide zu ändern sind:** „Nachtragen – ein Abschnitt fehlt“ (nichts gestempelt) und „Korrigieren“ eines geschlossenen Abschnitts. Beim Korrigieren sind beide Felder mit den vorhandenen Werten vorbelegt (heute leer); geändert wird nur, was falsch ist. „Entfernen“ bleibt, wie es ist. |
| NB8 | **Die Verweise „Gehen fehlt“ (GV9)** im Kopf von „Meine Zeiten“ und in der Spalte „Hinweise“ öffnen den Dialog mit der Art „Gehen nachtragen“, dem betroffenen Abschnitt und dem Zeiger im Feld „Gehen“ – nicht mehr mit „Korrigieren“. Der offene Abschnitt ist dabei auch wählbar, wenn er nicht im angezeigten Monat liegt (heute nennt die Auswahl nur Abschnitte des angezeigten Monats). |
| NB9 | **Für das Personal gilt dasselbe** beim Nachtragen für andere auf der Seite „Zeiten“ – es ist derselbe Dialog. |
| NB10 | **Daneben bleibt GV4:** Wer nach Ablauf der Dauer noch da ist, schließt mit „Gehen“ und Grund zur Uhrzeit von jetzt. Der Nachtrag nach NB2 ist der Weg für den, der schon gegangen ist. |

**Gebaut (0.7.87):** NB1, NB2, NB4, NB5, NB7, NB8, NB9; NB10 gilt, sobald Abschnitt 11 gebaut ist. Der Antrag kennt die
vierte Art `gehen` (Migration `0005_gehen_nachtragen.sql`); geprüft wird beim Stellen und noch einmal beim Übernehmen.
**Nicht gebaut, weil nicht entschieden:** NB3 – ✎ und Begründung stehen weiter am Abschnitt; NB6 – „Kommen nachtragen“.

| # | Festlegung aus der Umsetzung – zu bestätigen |
|---|---|
| NU1 | **Zu jedem offenen Abschnitt lässt sich das Gehen nachtragen, auch zum laufenden** (wer gegangen ist und es zwei Stunden später merkt, muss nicht warten, bis der Abschnitt als vergessen gilt). Vorgewählt ist die Art nur, wenn ein Abschnitt nicht mehr als laufend gilt. |
| NU2 | **„Nicht über das nächste Kommen hinaus“ gilt über Tage hinweg**, nicht nur am selben Tag; die Meldung nennt Tag und Uhrzeit dieses Kommens. **Vom Verantwortlichen am 2026-11-25 nicht verstanden – erläutert, wartet auf seine Antwort.** Beispiel: Montag 08:00 gekommen, Gehen vergessen; Dienstag 07:30 gekommen. Wer zum Montag als Gehen „07:45“ einträgt, meint (vor dem Kommen = nächster Tag) Dienstag 07:45 – das läge nach dem Kommen vom Dienstag, beide Abschnitte überschnitten sich. Das weist kumero ab. |
| NU3 | **Der Abschnitt trägt danach ✎ wie nach einer Korrektur**, seine Herkunft bleibt „gestempelt“ (kein ⁺). **Bestätigt 2026-11-25 („OK“).** |
| NU4 | **Das Monatsblatt nennt alle offenen Abschnitte der Person** (`offene`), auch außerhalb des angezeigten Monats – nur so sind sie im Dialog wählbar. Liegt der Abschnitt in einem abgeschlossenen Monat, lehnt der Server mit Grund ab; der Verweis im Kopf ist nur gesperrt, wenn der angezeigte Monat abgeschlossen ist. **Nicht bestätigt – Vorgabe des Verantwortlichen vom 2026-11-25, 10:38, am Bild des Kopfs von „Meine Zeiten“ (F205, nicht gebaut):** „Ich denke, ein Hinweis auf den Monat mit direktem Link sollte reichen; bei mehr als einem Monat wird der älteste vor dem jüngsten korrigiert.“ → NU4a. |
| NU4a | **Lesart der Vorgabe, zu bestätigen:** Fehlt ein Gehen in einem anderen als dem angezeigten Monat, nennt der Kopf **den Monat** als Verweis („▲ Gehen fehlt im Oktober 2026“); der Verweis wechselt in diesen Monat. Dort steht der Abschnitt im Monatsblatt mit „! Gehen fehlt“, und der Dialog wählt wie vor 0.7.87 nur aus den Abschnitten des angezeigten Monats – `offene` über alle Monate entfällt. Bei mehreren Monaten nennt der Kopf **nur den ältesten**; erst wenn dort nichts mehr fehlt, den nächsten. **Offen:** (1) Öffnet der Verweis nach dem Wechsel gleich den Dialog „Gehen nachtragen“ für den ältesten offenen Abschnitt, oder nur den Monat? (2) Ist „der älteste zuerst“ eine Reihenfolge der Hinweise oder eine Sperre – nimmt kumero das Gehen zu einem jüngeren Abschnitt erst an, wenn der ältere geschlossen ist? (3) Fehlt das Gehen im angezeigten Monat, bleibt es bei Tag und Uhrzeit im Kopf wie gebaut? |
| NU5 | **Beim Korrigieren folgt die Vorbelegung dem gewählten Abschnitt, bis die Person selbst eine Uhrzeit eingibt** – was sie eingegeben hat, bleibt beim Wechsel von Art oder Abschnitt stehen. |
| NU6 | **Unter „Zeiten“ führt „! Gehen fehlt“ zum Nachtrag nur für den, der für andere nachtragen darf** (Personal); sonst nennt eine Meldung den Grund. |

Geprüft: `tests/ZeitTest.php` (252), Browserlauf `personal.mjs` (164) – auf PHP 8.3 mit Symfony 7.4 und PostgreSQL 16. Nicht
geprüft: PHP 8.5, PostgreSQL 19, Sommerzeitwechsel, ein Update auf der VM. **Zu entscheiden:** NB3, NB6; NU4a; zu bestätigen NU1, NU2, NU5, NU6.

**Vorgemerkt (AGENTS.md):** P41 Faktura auf Kunden und Projekte, P42 Auszubildende (Schultage, Ferien, Vertragsart mit eigenen
Fristen), P43 Minderjährige.
