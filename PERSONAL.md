# PERSONAL.md – Personal: Stamm, Verträge, Abwesenheiten, Zuschläge, Lohnvorbereitung (Entwurf, Fassung 1)

Stand: 2026-11-20. Bezug: F31 (Module erzeugen nur Entwürfe), F41 (Rechtemodell), F43 (Administrator ohne
Buchungsdaten), F44 (Module), F106 (nichts verschiebt sich), F110 (kumero hält kein Dokument), F114 (Nutzer gehören zum
Mandanten), F126 (Bücher), F134 (Module bringen Rollen mit). Geschwister: **ZEIT.md**, **LEISTUNGEN.md**,
**DIENSTPLAN.md**.
**Status: Entwurf zur Freigabe (P34). Nichts davon ist gebaut.**

> **Keine Rechtsberatung.** Abschnitt 8 nennt Regeln nach dem Stand vom November 2026, wie er öffentlich beschrieben
> ist. Der Verantwortliche prüft sie, bevor gebaut wird. kumero rechnet keinen Lohn ab.

## 1. Anlass und Zuschnitt

Vorgaben des Verantwortlichen vom 2026-11-20: ein Modul für das Personal. Dazu gehören Vertragsdaten, Abwesenheiten
mit **Arbeitsunfähigkeit (AU) und Freistellungen**, **Selfservice**, **Beurteilungen und Zielvereinbarungen**, eine
**Übersicht der Personalakte**, die **Verwaltung von Zuschlägen** und die **Vorbereitung der Lohnunterlagen**.
Zuschläge sind **an den Verkauf von Dienstleistungen gebunden und müssen dokumentierbar sein**; zeitabhängige Zuschläge
sind denkbar. Das Lohnbüro arbeitet mit DATEV: **ein Export und ein lesbares PDF** sind das Maß. **Personal gehört zum
Mandanten, nicht zum Buch.**

Daraus werden vier Module, die aufeinander aufbauen:

| Modul | Inhalt | Ebene | Entwurf |
|---|---|---|---|
| `personal` | Stamm, Verträge, Fristen, Abwesenheiten, Akte, Beurteilungen, Zuschlagsregeln, Lohnvorbereitung | Mandant | diese Datei |
| `zeit` | Kommen, Gehen, Pausen, Zeitkonto, Monatsabschluss | Mandant | ZEIT.md |
| `leistungen` | Katalog der Dienstleistungen, erbrachte Leistungen | Buch | LEISTUNGEN.md |
| `dienstplan` | Schichten, Besetzung, Tausch | Mandant | DIENSTPLAN.md |

**Nicht Teil, auch später nicht:** Lohnabrechnung (Brutto–Netto, Sozialversicherung, Meldungen, ELSTER),
Bewerbungen. **Vorlagen** sind OrangeHRM (Zuschnitt, Selfservice), Frappe HR (Beurteilungen), Dolibarr (Antragsweg bei
Abwesenheiten) und TimeTrex (Regeln für Zeit und Zuschläge) – Funktionen abgeschaut, kein fremder Code (wie LAGER.md).

## 2. Voraussetzung im Kern: Module und Rollen am Mandanten

Heute hängt jedes Modul an einem **Buch**: eigenes Schema in der Datenbank des Buchs, Rechte und Rollen je Buch. Die
Datenbank des Mandanten kennt nur Nutzer, Bücher und das Amt des Administrators – **keine Module, keine Rechte, keine
Rollen**. Personal am Mandanten braucht deshalb zuerst:

1. **Mandantenmodule:** ein Modul nennt im Manifest seine Ebene (`buch` wie bisher oder `mandant`). Ein
   Mandantenmodul hat sein Schema in der Datenbank des Mandanten, seine Pfade heißen `/m/<mandant>/api/v1/…`, seine
   Seiten stehen im Menübereich des Mandanten, es wird je Mandant aktiviert.
2. **Rechte und Rollen am Mandanten:** derselbe Aufbau wie im Buch (Recht → Baustein → Rolle → Zuweisung), in der
   Datenbank des Mandanten. Der Administrator weist zu; er selbst sieht keine Personaldaten (wie F43 für Buchungen).
3. **Protokoll:** Mandantenmodule schreiben in die Kette des Mandanten (`mandant.protokoll`).
4. **Dienste zwischen den Ebenen:** Ein Buchmodul (`leistungen`) fragt Personen beim Mandantenmodul an – über einen
   Dienst, nie über Tabellen; es bekommt nur ID und Anzeigename.

Das ist der größte Posten des Vorhabens und ein eigener Schritt (Stufe 0).

## 3. Was das Modul führt

**Person.** Personalnummer, Name, Geburtsdatum, Anschrift, Kontakt, Notfallkontakt; Verknüpfung mit dem
kumero-Nutzer (für den Selfservice); Zustand aktiv, ausgeschieden. Bankverbindung, Steuer- und
Sozialversicherungsmerkmale führt **das Lohnbüro**, nicht kumero (PE9).

**Vertrag** – als Fassungen mit „gültig ab“, nie überschrieben: Art (unbefristet, befristet bis, Ausbildung, Minijob,
Werkstudent), Funktion, Beginn, Probezeit bis, Kündigungsfrist als Text, Wochenstunden und ihre Verteilung auf die
Tage, Vergütung (Monatsgehalt oder Stundenlohn; daraus der Grundlohn je Stunde), Urlaubsanspruch im Jahr, Vorgesetzter,
**das Buch, das den Lohn trägt**, zugeordnete Zuschlagsregeln.

**Fristen und Nachweise.** Was je Person wann fällig ist: Probezeitende, Ende der Befristung, Unterweisungen,
Einweisungen, Fortbildungen, Nachweise mit Ablauf. Arten pflegt der Mandant. Liste „läuft ab“ mit Warnfrist, Zähler im
Menü – dasselbe Muster wie im Lager.

**Abwesenheiten.** Arten: Urlaub, AU, Freistellung bezahlt, Freistellung unbezahlt, Sonderurlaub, Fortbildung,
Mutterschutz, Elternzeit, sonstige. Antrag → Genehmigung oder Ablehnung mit Grund → genommen; Rücknahme. Urlaubskonto
je Jahr: Anspruch, Übertrag, beantragt, genehmigt, genommen, Rest. Kalender „wer fehlt wann“.

**AU.** Gemeldet von der Person oder eingetragen vom Personal: Beginn, voraussichtliches Ende, Datum der ärztlichen
Feststellung, Erst- oder Folgebescheinigung, Kennzeichen Arbeitsunfall – das sind die Angaben, die ein Arbeitgeber im
eAU-Verfahren erhält; **eine Diagnose führt kumero nie**. kumero ruft nichts bei Krankenkassen ab (CLAUDE.md: keine
externe API); vermerkt wird nur, dass der Nachweis vorliegt.

**Beurteilungen und Zielvereinbarungen.** Vorlagen mit Kriterien und Skala; Runde je Zeitraum; Selbsteinschätzung der
Person, Einschätzung des Vorgesetzten, Gespräch mit Datum, Ergebnis mit Kenntnisnahme. Ziele mit Frist, Stand und
Bewertung. Ob ein Ziel einen Zuschlag auslöst, ist eine Regel wie jede andere (Abschnitt 5).

**Übersicht der Personalakte.** Eine Seite je Person: Vertrag und seine Fassungen, Fristen, Abwesenheiten, Zeitkonto
(aus `zeit`), Beurteilungen, Lohnvorbereitungen, und **Verweise auf Dokumente in Paperless** (Vertrag, Zeugnisse) –
kumero hält kein Dokument (F110).

## 4. Selfservice

Jede Person ist kumero-Nutzer mit Passkey und der Rolle „Mitarbeiter“. Sie sieht **nur das Eigene**: Vertragsdaten zum
Lesen, Urlaubskonto, eigene Anträge, eigene Fristen, eigene Beurteilungen, eigene Lohnvorbereitung mit Nachweis der
Zuschläge. Sie kann: Urlaub und Freistellung beantragen, AU melden, Kontaktdaten zur Änderung vorschlagen (das
Personal übernimmt), Selbsteinschätzung abgeben, Ergebnis zur Kenntnis nehmen. Mit `zeit`: stempeln und nachtragen.

## 5. Zuschläge

Eine **Zuschlagsregel** hat Namen, Gültigkeit von–bis, Lohnart für die Übergabe und eine von zwei Grundlagen:

| Grundlage | Quelle | Formen |
|---|---|---|
| **Leistung** | erbrachte Leistungen aus `leistungen` | Anteil am Betrag in Prozent · fester Betrag je Leistung · Staffel (ab einer Schwelle im Monat ein anderer Satz) · je einzelne Leistung, Leistungsgruppe oder alle |
| **Zeit** | geprüfte Zeiten aus `zeit` | Zuschlag in Prozent des Grundlohns für Stunden in einem Fenster (Nacht, Sonntag, Feiertag, Überstunden) |

Regeln hängen am Vertrag einer Person oder an einer Funktion. **Jeder Zuschlag ist nachweisbar:** Zum Betrag gehört
die Liste der Leistungen bzw. Stunden, aus denen er gerechnet ist – mit Datum, Menge, Betrag, Regel und Fassung der
Regel. Gerechnet wird in ganzen Cent; eine geänderte Regel gilt für die Zukunft, vergangene Monate werden nicht neu
gerechnet. Zusätzlich: **Zuschlag von Hand** mit Begründung (Einmalzahlung, Zielerreichung).

## 6. Lohnvorbereitung

Je Monat und Person sammelt kumero, was das Lohnbüro braucht: Sollstunden, Iststunden und Überstunden (aus `zeit`),
Abwesenheitstage je Art, Zuschläge mit Nachweis, Änderungen am Vertrag im Monat, Ein- und Austritte, Bemerkungen.

- **Ablauf:** offen → geprüft (Personal) → übergeben (gesperrt). Eine Berichtigung nach der Übergabe ist ein Nachtrag
  im Folgemonat oder eine neue Fassung mit Vermerk – nichts wird überschrieben.
- **Lesbares PDF:** Druckansicht je Person und als Sammelliste; der Nachweis der Zuschläge als Anlage.
- **DATEV-Export:** Datei mit den Bewegungsdaten im ASCII-Format für LODAS bzw. Lohn und Gehalt (Personalnummer,
  Lohnart, Wert). **Welche Lohnart zu welcher Angabe gehört, pflegt der Mandant nach den Vorgaben seines Lohnbüros.**
  Die Datei wird heruntergeladen und dem Lohnbüro gegeben – kumero überträgt nichts an DATEV.
- **Später:** Das Lohnjournal des Lohnbüros einlesen und daraus Buchungsentwürfe im Buch vorschlagen, das den Lohn
  trägt (über das Modul `import`).

## 7. Rollen (F134)

| Rolle | sieht | darf |
|---|---|---|
| **Mitarbeiter** | nur das Eigene | beantragen, melden, Selbsteinschätzung, zur Kenntnis nehmen |
| **Vorgesetzter** | sein Team: Abwesenheit ohne Art des Grundes („abwesend“), Urlaubskonto, Fristen, Beurteilungen | genehmigen, beurteilen, Ziele vereinbaren; **keine** Vertrags- und Vergütungsdaten, **keine** AU-Angaben |
| **Personal** | alles | alles pflegen, Lohnvorbereitung prüfen |
| **Lohn** | Verträge mit Vergütung, Abwesenheitstage, Zuschläge, Lohnvorbereitung | Lohnvorbereitung prüfen und übergeben, Export; **keine** Beurteilungen |

Administrator, Buchhaltung und Lager sehen nichts davon. Die Rollen gelten am Mandanten.

## 8. Regeln rund um das Personal (Stand 11/2026, zu prüfen)

| Gegenstand | Was gilt | Folge für das Modul |
|---|---|---|
| **Beschäftigtendaten** | Zweckbindung, Erforderlichkeit, Löschung nach Wegfall des Zwecks | Zugriff nur über die Rollen; Löschkonzept (PE12); in Logs und im Protokoll nur IDs |
| **AU als Gesundheitsdatum** (Art. 9 DSGVO) | besonders geschützt; der Arbeitgeber erhält Beginn, Ende, Feststellungstag, Erst-/Folgebescheinigung und ein Unfallkennzeichen, keine Diagnose | genau diese Felder; Vorgesetzte sehen nur „abwesend“ |
| **Zuschläge für Sonntags-, Feiertags- und Nachtarbeit** (§ 3b EStG) | steuerfrei nur in Grenzen des Grundlohns (Nacht 25 %, Sonntag 50 %, weitere Sätze), Grundlohn höchstens 50 € je Stunde, **Einzelaufzeichnung der tatsächlich geleisteten Stunden nötig** | kumero liefert Stunden, Grundlohn und Satz als Nachweis; ob steuerfrei, entscheidet das Lohnbüro |
| **DATEV-Übergabe** | Stamm- und Bewegungsdaten lassen sich als ASCII-Datei in LODAS und Lohn und Gehalt einlesen; den Satzaufbau gibt DATEV vor | Export nach dieser Beschreibung; vor dem Bauen eine Musterdatei mit dem Lohnbüro abstimmen |

## 9. Entscheidungen (zur Freigabe)

| # | Frage | Vorschlag |
|---|---|---|
| PE1 | Schnitt | vier Module wie in Abschnitt 1; Lohnabrechnung nie. |
| PE2 | Ebene | `personal`, `zeit`, `dienstplan` am **Mandanten**; `leistungen` am **Buch**. Dafür Mandantenmodule mit eigenen Rechten und Rollen (Abschnitt 2). |
| PE3 | Person und Nutzer | Eine Person kann ohne Nutzer geführt werden; für den Selfservice wird sie mit genau einem Nutzer verknüpft. |
| PE4 | Vertrag | Fassungen mit „gültig ab“; keine wird überschrieben. |
| PE5 | Lohn tragendes Buch | je Vertrag genau ein Buch; Aufteilung auf mehrere Bücher später. |
| PE6 | Abwesenheitsarten | feste Liste aus Abschnitt 3, vom Mandanten ergänzbar; jede Art sagt: bezahlt, zählt gegen Urlaub, braucht Genehmigung. |
| PE7 | AU | Felder wie im eAU-Verfahren, nie eine Diagnose; kein Abruf bei Krankenkassen. |
| PE8 | Sichtbarkeit von Gründen | Vorgesetzter und Dienstplan sehen „abwesend“ ohne Art; Art und AU-Angaben sehen nur Personal und Lohn. |
| PE9 | Lohnmerkmale | Bankverbindung, Steuerklasse, Sozialversicherung führt das Lohnbüro; kumero führt sie nicht. |
| PE10 | Zuschlagsregeln | zwei Grundlagen (Leistung, Zeit), Formen wie in Abschnitt 5; am Vertrag oder an der Funktion; Fassungen mit Gültigkeit. |
| PE11 | Nachweis | Zu jedem Zuschlag gehört die Liste, aus der er gerechnet ist; die Person sieht ihren Nachweis. |
| PE12 | Aufbewahrung und Löschen | Nach dem Austritt wird eine Person gesperrt, nicht gelöscht; gelöscht wird nach Fristen je Datenart, die der Mandant einstellt (Vorgabe im Entwurf zu Stufe 1). |
| PE13 | Lohnvorbereitung | je Monat und Person; offen → geprüft → übergeben; danach gesperrt. |
| PE14 | Übergabe | lesbares PDF und Datei im DATEV-ASCII-Format; Zuordnung der Lohnarten pflegt der Mandant; keine Übertragung durch kumero. |
| PE15 | Beurteilungen | Vorlagen des Mandanten; Selbsteinschätzung, Vorgesetzter, Gespräch, Kenntnisnahme; die Rolle Lohn sieht sie nicht. |
| PE16 | Akte | Übersicht und Verweise auf Paperless; kumero hält kein Dokument. |
| PE17 | Selfservice | Rolle „Mitarbeiter“ sieht nur das Eigene; Änderungen an Stammdaten sind Vorschläge an das Personal. |
| PE18 | Kleine Betriebe | Eine Person kann mehrere Rollen haben; ohne Vorgesetzten genehmigt das Personal. |

## 10. Stufen

| Stufe | Inhalt |
|---|---|
| **0** | Kern: Mandantenmodule, Rechte und Rollen am Mandanten, Dienste zwischen den Ebenen. |
| **1** | Person, Vertrag, Fristen und Nachweise, Übersicht der Akte, Rollen, Selfservice (lesen, Vorschläge). |
| **2** | Abwesenheiten: Urlaub, AU, Freistellung, Antrag und Genehmigung, Urlaubskonto, Kalender. |
| **3** | Zuschlagsregeln und Lohnvorbereitung mit PDF und DATEV-Export – braucht `leistungen`, für Stunden `zeit`. |
| **4** | Beurteilungen und Zielvereinbarungen. |
| später | Lohnjournal einlesen und buchen, Aufteilung auf Bücher, Qualifikationsmatrix. |

**Reihenfolge über alle vier Module:** `personal` 0–2 → `zeit` → `leistungen` → `personal` 3 → `dienstplan` →
`personal` 4.

## 11. Offen und ungeprüft

- Abschnitt 8 ist Recherche, keine Prüfung. Der Satzaufbau des DATEV-Imports ist nicht im Einzelnen gelesen; maßgeblich
  ist das Schnittstellenhandbuch und eine Musterdatei des Lohnbüros.
- Aufbewahrungs- und Löschfristen für Personalunterlagen sind nicht nachgeschlagen.
- Mitbestimmung (Betriebsrat) bei Zeiterfassung und Beurteilung ist nicht betrachtet.
- Tests nur mit erfundenen Personen (CLAUDE.md); echte Namen, Verträge und Zeiten kommen nie in Tests oder Chats.
