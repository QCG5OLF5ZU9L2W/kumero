# ZEIT.md – Zeiterfassung: Kommen, Gehen, Zeitkonto, Monatsabschluss (Fassung 3)

Stand: 2026-11-21. Bezug: PERSONAL.md (Person, Vertrag, Abwesenheiten, Zuschlagsregeln, Rollen, Ebene Mandant),
DIENSTPLAN.md, F106, F134. Vorlage der Funktionen: TimeTrex (Regeln als Bausteine), kein fremder Code.
**Status: Stufe 1 und 2 ohne Geräte gebaut mit 0.7.53 (Modul `zeit` 0.1.0, F145) – was gebaut ist und was abweicht, steht in
Abschnitt 9 und geht den Abschnitten 2 bis 7 vor. Terminal und Leser (Abschnitt 3, Stufe 3) sind nicht gebaut.**

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
| T12 | **Ein Abschnitt über Mitternacht gehört zum Tag seines Beginns.** Wer nicht ausstempelt, bleibt eingestempelt; der offene Abschnitt zählt nicht und wird über einen Nachtrag geschlossen. |
| T13 | **Uhrzeiten sind Ortszeit des Mandanten** (Vorgabe Europe/Berlin), nicht die des Browsers. |
| T14 | **Niemand gibt sich selbst frei** – außer wer das Personal führt (wie AW6, kleine Betriebe). |
| T15 | **Lohn sieht alle Zeiten**, nicht nur freigegebene Monate (Abschnitt 4 sagte „freigegebene“): Ohne Pflicht zum Abschluss gäbe es sonst nichts zu sehen. |
| T16 | **Keine Auswertung über Personen hinweg** außer der Liste des Monats für die, die freigeben; keine Rangfolge, keine Pausenstatistik (ZE13). |

**Berichtigt mit 0.7.55 (`zeit` 0.1.1):** Wer noch keinen Vertrag hat, steht in der Liste „Zeiten“ mit dem Vermerk „ohne
Vertrag“ (kein Soll, keine Regelarbeitstage – gestempelte Zeit zählt dann erst mit Genehmigung). Eine leere Liste nennt
ihren Grund. Die Seite „Zeiten“ ist die Übersicht; sie sagt, dass Kommen und Gehen unter „Meine Zeiten“ stehen und was
dafür fehlt (Rolle „Zeiterfassung“, Verknüpfung mit einer Person).

**Nicht gebaut:** Terminal, Anhänger, Leser im Netz (ZE2, ZE10–ZE12); eigene Zeitarten und Fenster des Mandanten über das
Nachtfenster hinaus (ZE7); Ruhezeit zwischen zwei Tagen; Schichtplan; Beträge der Zeitzuschläge (Grundlohn fehlt);
Löschfrist der Monatsblätter (ZE14); Druck des Monatsblatts.

**Geprüft:** `tests/ZeitTest.php` (209 Prüfungen), die Seiten im Browserlauf `tests/browser/personal.mjs`. **Nicht geprüft:**
die Werte gegen das Arbeitszeitgesetz im Wortlaut, Sonderregeln (Jugendliche, Mutterschutz, Bereitschaft), Mitbestimmung,
Sommerzeitwechsel in einer Nachtschicht, ein Lauf auf der VM.
