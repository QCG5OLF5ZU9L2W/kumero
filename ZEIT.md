# ZEIT.md – Zeiterfassung: Kommen, Gehen, Zeitkonto, Monatsabschluss (Entwurf, Fassung 1)

Stand: 2026-11-20. Bezug: PERSONAL.md (Person, Vertrag, Abwesenheiten, Zuschlagsregeln, Rollen, Ebene Mandant),
DIENSTPLAN.md, F106, F134. Vorlage der Funktionen: TimeTrex (Regeln als Bausteine), kein fremder Code.
**Status: Entwurf zur Freigabe (P35). Nichts davon ist gebaut.**

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
| ZE1 | Genauigkeit | **minutengenau** mit Beginn, Ende und Pausen; kein „heute acht Stunden“. Gerundet wird nicht. |
| ZE2 | Wege | Browser, Terminal, Leser im Netz – alle erzeugen dieselbe Buchung mit Kennzeichen der Herkunft. |
| ZE3 | Korrektur | neue Buchung ersetzt die alte, mit Begründung; nichts wird gelöscht. |
| ZE4 | Soll | aus dem Vertrag; mit `dienstplan` aus der geplanten Schicht. |
| ZE5 | Pausen | gestempelt; fehlt die Pause, wird **nichts abgezogen**, sondern ein Hinweis gezeigt. (Die Alternative – automatischer Abzug der Mindestpause – ist eine Einstellung des Mandanten.) |
| ZE6 | Regelverstöße | Hinweis, nie Sperre. |
| ZE7 | Zeitarten und Fenster | pflegt der Mandant; vorbelegt Nacht, Sonntag, Feiertag, Überstunden. |
| ZE8 | Feiertage | Kalender des Mandanten, vorbelegt nach Bundesland; von Hand zu ergänzen. |
| ZE9 | Monatsabschluss | Person bestätigt, Vorgesetzter gibt frei, dann gesperrt. Ohne Vorgesetzten gibt das Personal frei. |
| ZE10 | Terminal | Geräte-Token, nur stempeln; Ausweis je Buchung durch Anhänger oder Kurzkennung mit PIN. |
| ZE11 | Leser im Netz | lokale Schnittstelle mit Geräte-Token; kein Dienst außerhalb. |
| ZE12 | Ort | Es wird **kein Standort** erfasst (kein GPS, keine Netzadresse in der Auswertung). |
| ZE13 | Leistungs- und Verhaltenskontrolle | Auswertungen nur je Person für Zeitkonto und Lohn; keine Ranglisten, keine Auswertung von Pausenverhalten. |
| ZE14 | Aufbewahrung | wie die Lohnunterlagen des Mandanten (PERSONAL.md PE12). |

## 6. Regeln (Stand 11/2026, zu prüfen)

| Gegenstand | Was gilt | Folge |
|---|---|---|
| **Pflicht zur Zeiterfassung** | Arbeitgeber müssen die Arbeitszeit erfassen (Bundesarbeitsgericht 2022, gestützt auf das Arbeitsschutzgesetz); eine Form ist bisher nicht vorgeschrieben | Beginn, Ende und Dauer je Tag |
| **Geplante Reform** | Ein Referentenentwurf vom Juni 2026 sieht eine elektronische, taggleiche Erfassung vor; beschlossen ist er nicht | minutengenau und am selben Tag ist ohnehin der Vorschlag (ZE1) |
| **Pausen, Höchstzeit, Ruhezeit** (Arbeitszeitgesetz) | Grenzen je Tag und zwischen zwei Tagen | als Hinweise (ZE6); die genauen Werte sind vor dem Bauen nachzuschlagen |
| **Einzelaufzeichnung für Zuschläge** | steuerfreie Zuschläge brauchen den Nachweis der tatsächlich geleisteten Stunden | das Monatsblatt ist dieser Nachweis |

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
