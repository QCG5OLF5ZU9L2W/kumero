# PERSONAL.md – Personal: Stamm, Verträge, Abwesenheiten, Zuschläge, Lohnvorbereitung (Fassung 8)

Stand: 2026-11-20. Bezug: F31 (Module erzeugen nur Entwürfe), F41 (Rechtemodell), F43 (Administrator ohne
Buchungsdaten), F44 (Module), F106 (nichts verschiebt sich), F110 (kumero hält kein Dokument), F114 (Nutzer gehören zum
Mandanten), F126 (Bücher), F134 (Module bringen Rollen mit). Geschwister: **ZEIT.md**, **LEISTUNGEN.md**,
**DIENSTPLAN.md**.
**Status: Stufe 0 (Kern: Module, Rechte und Rollen am Mandanten) gebaut mit 0.7.42 (F135); Stufe 1 (Person, Vertrag,
Fristen, Akte, Selfservice – ohne Vergütung und Lohnmerkmale) gebaut mit 0.7.43 (F136, Abschnitt 10a); Stufe 2
(Abwesenheiten, AU, Urlaubskonto, Kalender) gebaut mit 0.7.45 (F138, Abschnitt 10b); Feiertage je Arbeitsort, Korrektur der Arbeitstage und Urlaubsregel mit 0.7.46
(F139, Abschnitt 10c); Stufe 3 (Zuschlagsregeln, Zuschlag von Hand, Lohnvorbereitung – ohne die Quellen `zeit` und `leistungen`) mit 0.7.47
(F140, Abschnitt 10d); Stufe 4 (Beurteilungen und Zielvereinbarungen) mit 0.7.48 (F141, Abschnitt 10e).**

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

### 2a. Stufe 0 – gebaut mit 0.7.42 (F135)

- **Manifest:** `"ebene": "mandant"` (ohne Angabe `buch`). Ein Modul am Mandanten hat keine Erweiterungspunkte und kann
  sich nicht auf ein Modul eines Buchs stützen. Rechte nennen statt `buchungsdaten` das Merkmal `personendaten`.
- **Datenbank des Mandanten** (`db/mandant/0002_module_rollen.sql`): `mandant.modul`, `recht`, `baustein`, `rolle`,
  `zuweisung` mit denselben Funktionen und Signaturen wie im Kern eines Buchs; `mandant.modul_protokoll()` schreibt in
  die Kette des Mandanten. Ein Modul bringt sein eigenes Schema in die Datenbank des Mandanten.
- **Aktivieren:** `bin/kumero modul:aktivieren <modul> <mandant>` – ohne Buch. `modul:liste` zeigt den Stand je Mandant;
  `install/aktualisieren.sh` spielt Migrationen solcher Module in die Datenbank des Mandanten ein.
- **Pfade:** `/m/<mandant>/api/v1/<modul>/…`; die Datenbank der Anfrage ist die des Mandanten, der Kontext hat kein Buch.
- **Rollen:** `…/m/<mandant>/api/v1/zuweisungen` (lesen, zuweisen, beenden) – nur der Administrator. Die Seite „Nutzer und
  Rollen“ zeigt Rollen am Mandanten in einer eigenen Spalte und in derselben Auswahl, sobald ein Modul dort aktiv ist.
- **Menü:** neuer Bereich „Personal“ über den Büchern; Einträge zeigen auf `#/m/<mandant>/<modul>/<seite>` und stehen
  auch dann im Menü, wenn ein Buch gewählt ist.
- **Dienst zwischen den Ebenen:** `Kumero\Api\V1\Mandantenauskunft` (Modul am Mandanten) und
  `Dienste::auskunftAmMandanten()` (Modul eines Buchs); der Kern gibt je Eintrag nur ID und Anzeigenamen weiter.

**Festlegungen beim Bauen – bitte bestätigen oder ändern:**

| # | Festlegung |
|---|---|
| Z1 | **Der Administrator weist zu und hat selbst keines der Rechte** der Module am Mandanten. Er kann sich eine Rolle selbst zuweisen (kleine Betriebe, PE18); das steht dann im Protokoll. Eine Sperre dagegen gibt es nicht. |
| Z2 | Rollen am Mandanten haben **keine Art** (kein „Verantwortlicher“, kein „Prüfer“); die Invarianten aus F43 gelten nur im Buch. |
| Z3 | Im **Buch** trägt der Kontext einer Anfrage nur die Rechte des Buchs; Rechte am Mandanten gelten nur auf Pfaden des Mandanten. Das Menü zeigt beides. |
| Z4 | Ein **gesperrter Nutzer** hat am Mandanten keine Rechte, auch wenn seine Zuweisung weiterläuft. |
| Z5 | Bereich des Menüs: **„Personal“** für alle Module am Mandanten (`personal`, `zeit`, `dienstplan`); „Mandant“ bleibt dem Kern (Bücher, Nutzer und Rollen). |
| Z6 | Die **Auskunft** an Buchmodule ist auf ID und Anzeigenamen beschnitten, und zwar im Kern – ein Modul kann nicht mehr herausgeben. Ob der Aufrufer fragen darf, prüft das Buchmodul mit seinem eigenen Recht. |
| Z7 | Rollen am Mandanten **weist nur der Administrator zu**; ein eigenes Recht „Rollen am Mandanten verwalten“ für die Rolle „Personal“ gibt es nicht. |
| Z8 | Ein **letzter Träger** einer Rolle am Mandanten ist nicht geschützt (anders als der letzte Verantwortliche eines Buchs). |

Geprüft: `tests/MandantenmoduleTest.php` mit einem Prüfmodul aus `tests/fixtures` (96 Prüfungen), dazu die Tests zu
Nutzerverwaltung, Oberfläche, Anwendung, Lager und Siegel sowie der Browserlauf `nutzer.mjs`. **Nicht geprüft:** Rollen
am Mandanten im Dialog im Browser (das Prüfmodul gibt es nur in den PHP-Tests), ein Modul am Mandanten mit eigenen
Migrationen, der Weg über `install/aktualisieren.sh`.

## 3. Was das Modul führt

**Person.** Personalnummer, Name, Geburtsdatum, Anschrift, Kontakt, Notfallkontakt; Verknüpfung mit dem
kumero-Nutzer (für den Selfservice); Zustand aktiv, ausgeschieden.

**Lohnmerkmale (PE9, entschieden 2026-11-20).** kumero führt sie an der Person: Bankverbindung (IBAN, Kontoinhaber),
Steuermerkmale (Identifikationsnummer, Steuerklasse, Kinderfreibeträge, Kirchensteuermerkmal) und Sozialversicherung
(Versicherungsnummer, Krankenkasse, Personengruppe, Beitragsgruppen). Sie sind ein eigener Gegenstand mit eigenem Recht:
sehen und ändern dürfen nur **Personal** und **Lohn**; die Person sieht ihre eigenen und schlägt Änderungen vor (PE17);
Vorgesetzte sehen sie nie. Fassungen mit „gültig ab“ wie beim Vertrag. Im Protokoll steht, **dass** sich ein Merkmal
geändert hat, nicht der Wert.

**Kirchensteuermerkmal (entschieden 2026-11-20).** kumero fragt nie nach Konfession oder Glauben (Art. 140 GG mit
Art. 136 Abs. 3 der Weimarer Reichsverfassung). Geführt wird nur das abstrakte Abzugsmerkmal, und nur, wenn die Person
einer Religionsgemeinschaft angehört, die Steuern erhebt; sonst bleibt das Feld leer. Kein Freitext, keine Auswertung;
auch dieses Merkmal ist ein Datum nach Art. 9 DSGVO.

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
- **Zeitabhängige Zuschläge nur aus der Einzelaufzeichnung (Rechtspflicht, PE11):** In die Lohnvorbereitung kommt ein
  Zuschlag für Sonntags-, Feiertags- oder Nachtarbeit nur aus gestempelten Stunden eines **freigegebenen Monatsblatts**
  (ZEIT.md ZE9). Ohne Abschluss des Monats steht dort „Nachweis fehlt“ statt eines Betrags; pauschale Stunden gibt es
  nicht.
- **DATEV-Export: zurückgestellt** (Entscheidung vom 2026-11-20). Das PDF ist bis dahin die Übergabe. Die Unterlage
  ist so gegliedert (Personalnummer, Art der Angabe, Wert), dass eine Datei im ASCII-Format für LODAS bzw. Lohn und
  Gehalt später daraus entstehen kann; die Zuordnung zu Lohnarten kommt erst mit dem Export.
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
| **Zuschläge für Sonntags-, Feiertags- und Nachtarbeit** (§ 3b EStG) | steuerfrei nur in Grenzen des Grundlohns (Nacht 25 %, Sonntag 50 %, weitere Sätze), Grundlohn höchstens 50 € je Stunde, **Einzelaufzeichnung der tatsächlich geleisteten Stunden nötig** | **Pflicht, nicht Kür:** kumero liefert Stunden, Grundlohn und Satz je Tag als Nachweis und rechnet einen Zeitzuschlag nur aus gestempelten, freigegebenen Stunden; ob steuerfrei, entscheidet das Lohnbüro |
| **DATEV-Übergabe** | Stamm- und Bewegungsdaten lassen sich als ASCII-Datei in LODAS und Lohn und Gehalt einlesen; den Satzaufbau gibt DATEV vor | **zurückgestellt**; wenn er kommt: vorher eine Musterdatei mit dem Lohnbüro abstimmen |

## 8a. Aufbewahren und Löschen (PE12)

- **Objekt** ist alles, was das Modul zu einer Person führt: Stammdaten, Lohnmerkmale, jede Vertragsfassung, jede
  Abwesenheit (AU eigens), jede Frist und jeder Nachweis, jede Lohnvorbereitung, jede Beurteilung und
  Zielvereinbarung; aus `zeit` die Monatsblätter mit ihren Buchungen.
- **Jedes Objekt trägt seine eigene Frist** („löschen ab“), festgelegt für diesen Mitarbeiter. Beim Anlegen wird sie
  aus einer Vorgabe des Mandanten für die Art des Objekts vorbelegt; das Personal kann sie am einzelnen Objekt ändern,
  mit Begründung. kumero gibt **keine** Fristen vor – die Vorgaben sind anfangs leer, und ein Objekt ohne Frist wird
  nicht gelöscht, sondern in der Übersicht als „Frist fehlt“ gezeigt.
- **Beginn der Frist** je Art einstellbar: ab Anlage, ab Ende des Kalenderjahres oder ab Austritt.
- **Löschen ist ein Vorgang, kein Automatismus:** Eine Liste zeigt je Mitarbeiter, was fällig ist; das Personal
  bestätigt, erst dann wird gelöscht. Im Protokoll bleibt, dass ein Objekt welcher Art gelöscht wurde, ohne Inhalt.
- Nach dem Austritt ist die Person gesperrt (nicht mehr wählbar, kein Selfservice); sie verschwindet, wenn ihr letztes
  Objekt gelöscht ist.
- Was schon in einer übergebenen Lohnvorbereitung steht, wird mit dieser gelöscht, nicht vorher.

## 9. Entscheidungen (zur Freigabe)

| # | Frage | Vorschlag |
|---|---|---|
| PE1 | Schnitt | vier Module wie in Abschnitt 1; Lohnabrechnung nie. **Entschieden 2026-11-21:** Die Zeiterfassung ist ein eigenes Modul mit eigener Rolle, steht im Menü aber unter Personal → Meine Daten; der Dienstplan hängt nicht mit ihr zusammen (ZEIT.md Abschnitt 9, F145). |
| PE2 | Ebene | `personal`, `zeit`, `dienstplan` am **Mandanten**; `leistungen` am **Buch**. Dafür Mandantenmodule mit eigenen Rechten und Rollen (Abschnitt 2). |
| PE3 | Person und Nutzer | Eine Person kann ohne Nutzer geführt werden; für den Selfservice wird sie mit genau einem Nutzer verknüpft. |
| PE4 | Vertrag | Fassungen mit „gültig ab“; keine wird überschrieben. |
| PE5 | Lohn tragendes Buch | je Vertrag genau ein Buch; Aufteilung auf mehrere Bücher später. |
| PE6 | Abwesenheitsarten | feste Liste aus Abschnitt 3, vom Mandanten ergänzbar; jede Art sagt: bezahlt, zählt gegen Urlaub, braucht Genehmigung. |
| PE7 | AU | Felder wie im eAU-Verfahren, nie eine Diagnose; kein Abruf bei Krankenkassen. |
| PE8 | Sichtbarkeit von Gründen | Vorgesetzter und Dienstplan sehen „abwesend“ ohne Art; Art und AU-Angaben sehen nur Personal und Lohn. |
| PE9 | Lohnmerkmale | **Entschieden 2026-11-20:** kumero führt Bankverbindung, Steuer- und Sozialversicherungsmerkmale (Abschnitt 3). Eigenes Recht; nur Personal und Lohn, die Person ihre eigenen; im Protokoll kein Wert. |
| PE10 | Zuschlagsregeln | **Freigegeben 2026-11-20 („bau mal Stufe 3“), gebaut mit 0.7.47.** Zwei Grundlagen (Leistung, Zeit), Formen wie in Abschnitt 5; am Vertrag oder an der Funktion; Fassungen mit Gültigkeit. |
| PE11 | Nachweis | Zu jedem Zuschlag gehört die Liste, aus der er gerechnet ist; die Person sieht ihren Nachweis. **Entschieden 2026-11-20:** Für Zeitzuschläge ist die Einzelaufzeichnung Rechtspflicht – ohne freigegebenes Monatsblatt kein Betrag. |
| PE12 | Aufbewahrung und Löschen | Nach dem Austritt wird eine Person gesperrt, nicht gelöscht; **Entschieden 2026-11-20:** Die Löschfrist wird **je Objekt und Mitarbeiter** festgelegt (Abschnitt 8a). |
| PE13 | Lohnvorbereitung | **Freigegeben 2026-11-20, gebaut mit 0.7.47.** Je Monat und Person; offen → geprüft → übergeben; danach gesperrt. |
| PE14 | Übergabe | lesbares PDF. **Entschieden 2026-11-20:** Der DATEV-Export ist zurückgestellt; die Unterlage bleibt so gegliedert, dass er später daraus entstehen kann. Keine Übertragung durch kumero. |
| PE15 | Beurteilungen | **Freigegeben 2026-11-20 („bau mal Stufe 3 und 4“), gebaut mit 0.7.48.** Vorlagen des Mandanten; Selbsteinschätzung, Vorgesetzter, Gespräch, Kenntnisnahme; die Rolle Lohn sieht sie nicht. |
| PE16 | Akte | Übersicht und Verweise auf Paperless; kumero hält kein Dokument. |
| PE17 | Selfservice | Rolle „Mitarbeiter“ sieht nur das Eigene; Änderungen an Stammdaten sind Vorschläge an das Personal. |
| PE18 | Kleine Betriebe | Eine Person kann mehrere Rollen haben; ohne Vorgesetzten genehmigt das Personal. |

## 10. Stufen

| Stufe | Inhalt |
|---|---|
| **0** | Kern: Mandantenmodule, Rechte und Rollen am Mandanten, Dienste zwischen den Ebenen. |
| **1** | Person, Vertrag, Fristen und Nachweise, Übersicht der Akte, Rollen, Selfservice (lesen, Vorschläge). |
| **2** | Abwesenheiten: Urlaub, AU, Freistellung, Antrag und Genehmigung, Urlaubskonto, Kalender. |
| **3** | Zuschlagsregeln und Lohnvorbereitung mit PDF – braucht `leistungen`, für Stunden `zeit`. **Gebaut mit 0.7.47 ohne die Quellen (Abschnitt 10d).** |
| **4** | Beurteilungen und Zielvereinbarungen. **Gebaut mit 0.7.48 (Abschnitt 10e).** |
| später | DATEV-Export (zurückgestellt), Lohnjournal einlesen und buchen, Aufteilung auf Bücher, Qualifikationsmatrix. |

**Reihenfolge über alle vier Module:** `personal` 0–2 → `zeit` → `leistungen` → `personal` 3 → `dienstplan` →
`personal` 4.

## 10a. Stufe 1 – gebaut mit 0.7.43 (F136)

Modul `personal` 0.1.0 am Mandanten: `bin/kumero modul:aktivieren personal <mandant>`, danach weist der Administrator
unter „Nutzer und Rollen“ die Rollen zu.

**Datenklassen** (getrennte Tabellen, je eigenes Recht – SCHLUESSEL.md Abschnitt 6):

| Klasse | Tabelle | Inhalt | Recht zum Sehen |
|---|---|---|---|
| Stamm | `personal.person` | Personalnummer, Name, Vorgesetzter, Nutzer, Austritt | `personal.person.sehen` (alle) · `personal.team.sehen` (eigenes Team) · `personal.eigenes.sehen` |
| Privat | `personal.privat`, `personal.vorschlag` | Geburtsdatum, Anschrift, private Kontaktdaten, Notfallkontakt; Änderungsvorschläge | `personal.privat.sehen` · die Person selbst |
| Vertrag | `personal.vertrag` | Fassungen: Art, Funktion, Beginn, Probezeit, Befristung, Minuten je Wochentag, Urlaubstage, Lohn tragendes Buch, Kündigungsfrist | `personal.vertrag.sehen` · die Person selbst |
| Fristen | `personal.frist`, `personal.fristart` | Art, Fälligkeit, Bemerkung, erledigt | wer die Person sieht |

**Rollen:** Mitarbeiter (Eigenes sehen, Kontaktdaten vorschlagen) · Vorgesetzter (Name, Funktion und Fristen der
unmittelbar Unterstellten) · Personal (alles) · Lohn (alle Personen und Verträge, keine privaten Angaben). Der
Administrator sieht nichts davon.

**Seiten** im Bereich „Personal“: „Meine Daten“ · „Personen“ (Liste mit Filtern, darunter die Akte, Teiler verschiebbar –
F137) · „Fristen und Nachweise“ (mit Zähler im Menü).

**Festlegungen beim Bauen – bitte bestätigen oder ändern:**

| # | Festlegung |
|---|---|
| S1 | **Vergütung und Lohnmerkmale haben noch keine Tabelle.** Sie sind als eigene Datenklassen vorgesehen und kommen mit dem Schritt, der sie braucht; eine leere Tabelle auf Vorrat hätte ihre Form vorweggenommen. |
| S2 | **Arbeitszeit** wird je Wochentag eingegeben (Montag bis Sonntag, minutengenau); die Wochenstunden sind die Summe. |
| S3 | **Geltende Fassung** ist die mit dem spätesten „gültig ab“, das erreicht ist; bei gleichem Tag die zuletzt angelegte. So wird ein Schreibfehler durch eine neue Fassung zum selben Tag berichtigt, die alte bleibt sichtbar. |
| S4 | **Zustand** einer Person ergibt sich aus dem Austritt: ab dem Tag danach „ausgeschieden“. Sie bleibt in der Liste, grau, und ist für neue Fristen nicht mehr wählbar. |
| S5 | **Vorgesetzter** sieht nur, wer ihm **unmittelbar** untersteht – keine Kette über mehrere Ebenen, keine Vertretung (das gehört zur vertagten Hierarchie, SCHLUESSEL.md). |
| S6 | **Der Vorgesetzte sieht keine Termine aus dem Vertrag** (Ende der Probezeit, Ende der Befristung): Das sind Vertragsdaten. Er sieht die Fristen, die das Personal anlegt. |
| S7 | **Termine aus dem Vertrag** stehen in der Fristenliste, solange sie nicht vorbei sind, und werden nicht „erledigt“ – sie enden mit einer neuen Fassung. Ihre Warnfrist ist eine Einstellung (Vorgabe 60 Tage). |
| S8 | **Arten von Fristen** sind vorbelegt mit Unterweisung, Einweisung, Fortbildung, Nachweis; jede hat ihre Warnfrist; stillgelegt statt gelöscht. |
| S9 | **Selfservice:** vorschlagen lassen sich Anschrift, Telefon, private E-Mail und Notfallkontakt – nicht Name und Geburtsdatum. Höchstens fünf offene Vorschläge je Person. Eine Ablehnung braucht einen Grund, den die Person sieht. |
| S10 | **„Meine Daten“ zeigt nur das Eigene**, auch wenn die Rollen der Person mehr erlauben. Wer die Rolle Mitarbeiter hat, aber mit keiner Person verknüpft ist, bekommt einen Hinweis statt Daten. |
| S11 | **Löschfrist:** jedes Objekt (Stamm, private Angaben, jede Fassung, jede Frist, jeder Vorschlag) hat ein eigenes „löschen ab“, gesetzt vom Personal mit Begründung. Es gibt noch **keine Vorgaben je Objektart und keinen Löschlauf** – die Liste zeigt „Frist fehlt“, gelöscht wird in dieser Stufe nichts. |
| S12 | **Protokoll** (Kette des Mandanten): IDs und die **Namen** geänderter Felder, nie ein Wert; bei der Löschfrist Datum und Begründung. |
| S13 | **Das Lesekonto der Datenbank** (`kumero_lesen`) hat keinen Zugriff auf das Schema `personal`. |
| S14 | **Nutzer-ID:** Antworten nennen die ID des verknüpften Nutzers nur dem, der Personen pflegt; alle anderen sehen nur „verknüpft“ oder „ohne“. |
| S15 | **Dokumente der Akte** (Verweise auf Paperless) sind **nicht** gebaut: Ein Modul am Mandanten kann sich nicht auf das Modul `paperless` eines Buchs stützen. |
| S16 | Die Bemerkung an einer Frist ist freier Text (200 Zeichen) mit dem Hinweis „keine Gesundheitsangaben“ – technisch verhindern lässt sich das nicht. |

Geprüft: `tests/PersonalTest.php` (168 Prüfungen: Sicht je Rolle, Fassungen, Fristen, Vorschläge, Löschfrist, Protokoll
ohne Werte, Auskunft an Buchmodule) und `tests/browser/personal.mjs` (33 Prüfungen, auch Rollen am Mandanten im Dialog,
Teiler, Filter). **Nicht geprüft:** viele Personen (Suche und Filter laufen im Browser), Ausdruck, Bedienung am
Telefon, mehrere Vorgesetzten-Ebenen, der Weg über `install/aktualisieren.sh` mit einem Modul am Mandanten.

**Nur mit erfundenen Personen nutzen, bis die Verschlüsselung steht** (SCHLUESSEL.md, P38): Die Daten liegen
unverschlüsselt in der Datenbank des Mandanten, geschützt durch die verschlüsselte Platte und die Rollen.

## 10b. Stufe 2 – gebaut mit 0.7.45 (F138)

Modul `personal` 0.2.0. Nach dem Update je Mandant einmal `bin/kumero modul:aktivieren personal <mandant>` – das meldet
die neuen Rechte an und gibt den vier Rollen ihre neuen Bausteine. Zuweisungen bleiben, wie sie sind.

**Datenklassen** (Migration `0002_abwesenheiten.sql`):

| Klasse | Tabelle | Inhalt | wer sieht es |
|---|---|---|---|
| Abwesenheit | `personal.abwesenheit` | Person, Art, von, bis, halber Tag, Arbeitstage, Bemerkung, Zustand, Grund | die Person · `personal.abwesenheit.sehen` (Personal, Lohn) · der Vorgesetzte eingeschränkt (AW7) |
| AU | `personal.au` | ärztlich festgestellt am, Erst- oder Folgebescheinigung, Arbeitsunfall, Nachweis liegt vor | die Person · `personal.au.sehen` (Personal, Lohn) |
| Urlaubskonto | `personal.urlaubskonto` | festgesetzter Anspruch und Übertrag je Person und Jahr | wer die Person sieht |
| – | `personal.abwesenheitsart`, `personal.freier_tag` | Arten; Feiertage und Betriebsferien | keine Personendaten |

**Rechte, Bausteine, Rollen:**

| Recht | Baustein | Rolle |
|---|---|---|
| `personal.eigenes.beantragen` | Personal: Abwesenheit beantragen | Mitarbeiter |
| `personal.abwesenheit.genehmigen` (eigenes Team) | Personal: Abwesenheiten genehmigen | Vorgesetzter |
| `personal.abwesenheit.sehen`, `personal.au.sehen` | Personal: Abwesenheiten ansehen | Lohn |
| dazu `personal.abwesenheit.pflegen`, `personal.abwesenheit.genehmigen` (alle) | Abwesenheiten führen | Personal |

**Seiten:** „Meine Daten“ bekommt das Urlaubskonto (laufendes und nächstes Jahr), „Meine Abwesenheiten“ und die Knöpfe
„Abwesenheit beantragen …“ und „Krank melden …“. Neu im Bereich „Personal“: **„Abwesenheiten“** (Vorgesetzter, Personal,
Lohn) – oben die Liste des Jahres mit Sortierung und Filter, darunter (Teiler, F137) der Kalender „wer fehlt wann“ und
die Urlaubskonten; Zähler im Menü für Anträge, die man entscheiden kann. Die Akte unter „Personen“ zeigt Abwesenheiten
und Urlaubskonto.

**Endpunkte** unter `/m/<mandant>/api/v1/personal`: `GET /abwesenheiten?jahr=`, `GET /abwesenheiten/zaehler`,
`POST /abwesenheiten` (eintragen), `POST /abwesenheiten/{id}/entscheidung`, `POST /abwesenheiten/{id}/ruecknahme`,
`PUT /abwesenheiten/{id}/ende`, `PUT /abwesenheiten/{id}/nachweis`, `POST`/`PUT /abwesenheitsarten[/{id}]`,
`PUT /freie-tage/{datum}`, `PUT /urlaubskonten/{person}/{jahr}`, `POST /meine/abwesenheiten`,
`POST /meine/abwesenheiten/{id}/ruecknahme`; `GET /meine` und `GET /personen/{id}` liefern Abwesenheiten und Urlaubskonto mit.

**Festlegungen beim Bauen – bitte bestätigen oder ändern:**

| # | Festlegung |
|---|---|
| AW1 | **Arten** sind vorbelegt mit den neun aus Abschnitt 3. Jede sagt: bezahlt, zählt gegen Urlaub, braucht Genehmigung (PE6) – und neu: **die Person kann sie selbst beantragen** (Urlaub, AU, beide Freistellungen, Sonderurlaub, Fortbildung ja; Mutterschutz, Elternzeit, Sonstige trägt das Personal ein). **Die Vorbelegung von „bezahlt“ ist eine Annahme und zu prüfen.** Urlaub und AU haben eine feste Bedeutung und lassen sich nicht stilllegen, nur umbenennen. |
| AW2 | **Geändert durch FT1–FT6 (Abschnitt 10c): Feiertage kommen aus dem Regelwerk und dem Arbeitsort.** **Arbeitstage** rechnet kumero: jeder Tag, an dem nach der an diesem Tag geltenden Fassung des Vertrags gearbeitet wird (Stunden > 0); ohne Vertrag Montag bis Freitag. **Freie Tage des Betriebs** (Feiertage, Betriebsferien) zählen nicht – das Personal pflegt sie; **kumero gibt keine Feiertage vor** (sie hängen vom Ort ab und wären ein Regelwerk, das zu prüfen ist). Ein halber Tag zählt 0,5 und ist genau ein Tag. |
| AW3 | **Ergänzt durch FT5: Die festgehaltenen Tage lassen sich berichtigen.** Die Arbeitstage werden **festgehalten**: beim Anlegen, neu beim Genehmigen und bei „Ende ändern“. Ein später geänderter Vertrag oder freier Tag ändert vergangene Einträge nicht. |
| AW4 | **Zustände:** beantragt, genehmigt, abgelehnt, zurückgenommen. „Läuft“ und „genommen“ ergeben sich aus dem Datum. Nichts wird gelöscht. |
| AW5 | Eine Art **ohne Genehmigung gilt mit der Meldung** (AU). Was das **Personal einträgt, gilt ohne Antrag**, gleich welcher Art. |
| AW6 | **Wer entscheidet:** der Vorgesetzte für die ihm unmittelbar Unterstellten (wie S5), das Personal für alle (PE18). Als Vorgesetzter entscheidet niemand über sich selbst. **Wer das Personal führt, kann auch den eigenen Antrag entscheiden** – in kleinen Betrieben gibt es sonst niemanden; es steht im Protokoll (wie Z1). |
| AW7 | **Sicht des Vorgesetzten** (PE8): Bei Arten, die eine Genehmigung brauchen, sieht er Art, Bemerkung und Tage – er entscheidet den Antrag. Alles andere (AU, Mutterschutz, Elternzeit) sieht er nur als **„abwesend“ von–bis**, ohne Art, und nur, wenn es gilt. Die Urlaubskonten seines Teams sieht er. |
| AW8 | **AU:** Beginn, voraussichtliches Ende (Pflicht; fortgeschrieben mit „Ende ändern“), festgestellt am, Folgebescheinigung, Arbeitsunfall, Nachweis liegt vor. **Kein Feld für eine Diagnose, keine Bemerkung an einer AU** – die Datenbank weist sie ab, die Schnittstelle weist unbekannte Felder ab. Den Nachweis vermerkt nur das Personal; kumero ruft nichts ab. |
| AW9 | **Zwei Abwesenheiten einer Person überschneiden sich nicht – außer eine ist eine AU.** Wer im Urlaub krank wird, ist beides: Am Urlaub steht ein Hinweis, das Personal kürzt ihn oder nimmt ihn zurück. kumero schreibt nichts von selbst gut. |
| AW10 | **Rücknahme:** Die Person nimmt einen Antrag jederzeit zurück, Genehmigtes bis zum Tag vor dem Beginn. Danach – und bei einer gemeldeten AU – nur das Personal, mit Grund. |
| AW11 | **Geändert durch UR1–UR5 (Abschnitt 10c): Anteil, Übertrag und Verfall folgen der Urlaubsregel des Mandanten.** **Urlaubskonto:** Anspruch = Urlaubstage der Fassung, die am 1. Januar gilt (beginnt der Vertrag im Jahr: seiner ersten). **Keine Zwölftelung, kein Verfall, kein Übertrag von selbst** – das Personal setzt Anspruch und Übertrag je Jahr fest. Rest = Anspruch + Übertrag − genommen − genehmigt; Beantragtes steht daneben. Ein negativer Rest wird gezeigt, nicht verhindert. |
| AW12 | Über den **Jahreswechsel** teilen sich die Tage auf beide Jahre. Urlaub reicht höchstens über einen Jahreswechsel; eine Abwesenheit dauert höchstens drei Jahre. |
| AW13 | **Mitarbeiter sehen nur die eigenen Abwesenheiten** – keinen Kalender der Kollegen. Das kommt mit dem Dienstplan. |
| AW14 | **Lohn** sieht alle Abwesenheiten mit Art und den Angaben zur Bescheinigung, pflegt und entscheidet nichts. |
| AW15 | **Protokoll:** bei Abwesenheiten nur IDs und das Ereignis – keine Art, kein Zeitraum, kein Grund. Arten und freie Tage stehen mit ihren Werten im Protokoll (keine Personendaten). |
| AW16 | **Löschfrist:** Jede Abwesenheit ist ein Objekt mit eigener Frist; die Angaben der AU gehen mit ihr. Das Urlaubskonto hat das Feld, aber noch keine Pflege – es kommt mit den Vorgaben je Objektart (S11). |
| AW17 | **Keine Benachrichtigung:** Der Postkorb ist vertagt (P38). Eine Entscheidung sieht die Person unter „Meine Daten“; wer entscheidet, sieht den Zähler im Menü. |
| AW18 | Nach dem **Austritt** nimmt eine Person keine neue Abwesenheit an; frühere lassen sich nachtragen. |

Geprüft: `tests/PersonalTest.php` (309 Prüfungen, davon Stufe 2: Sicht je Rolle, AU ohne Diagnose, Rechnen der
Arbeitstage, Jahreswechsel, Urlaubskonto, Rücknahme, Protokoll ohne Werte) und `tests/browser/personal.mjs`
(53 Prüfungen, Bilder angesehen). **Nicht geprüft:** viele Personen im Kalender, Bedienung am Telefon, Ausdruck, das
erneute Aktivieren auf einem Mandanten mit 0.1.0 (nur frisch aktiviert geprüft), mehrere Vorgesetzten-Ebenen.
**Nicht gebaut:** Benachrichtigung und Postkorb, Vertretung, stundenweise Abwesenheit, Feiertage als Regelwerk,
Verfall und Übertrag von selbst, Sperrzeiten, Export des Kalenders, Abwesenheitstage in der Lohnvorbereitung (Stufe 3).

## 10c. Feiertage je Arbeitsort, Korrektur der Arbeitstage, Urlaubsregel – gebaut mit 0.7.46 (F139)

Modul `personal` 0.3.0, Migration `0003_feiertage_urlaubsregel.sql`. Anlass: die Antworten des Verantwortlichen vom 2026-11-20
auf AW2 („Komfortfunktion: die bundeseinheitlichen und die für den Arbeitsort pflegen“) und AW11 („frei definierbar?“).

**Entschieden vom Verantwortlichen (2026-11-20):**

| # | Entscheidung |
|---|---|
| FT1 | **Feiertage hängen an der Person – alle Spielarten müssen gehen.** Regelwerk als **Steuerdatei, in der Oberfläche anzupassen**. |
| FT2 | **Das Bundesland reicht nicht** (Beispiel Augsburg und das Friedensfest): Ein Arbeitsort ist feiner als ein Land. |
| FT3 | Feiertage, die nur in Teilen eines Landes gelten, sind am Arbeitsort zuzuwählen. |
| FT4 | Die „Freien Tage“ des Betriebs bleiben daneben; ein Feiertag lässt sich abwählen. |
| FT5 | **Eine Korrektur der festgehaltenen Arbeitstage muss gehen.** |
| FT6 | Das Regelwerk schreibt kumero, der Verantwortliche prüft es; Gesetzesänderungen sind eine Pflegeaufgabe (wie F94). |
| UR1 | Zwölftelung pauschal ist nicht rechtskonform – **die wirkliche Rechtslage abbilden, aber individuell einstellbar**. |
| UR2 | Die Regel in Deutschland ist der **31.03. des Folgejahres**. |
| UR3 | Verfall mit Bestätigung durch das Personal – **konfigurierbar**; der **Warnhinweis entspricht der Fürsorgepflicht**. |
| UR4 | Der Übertrag wird vor dem Anspruch des Jahres verbraucht. |
| UR5 | Das Festsetzen je Person und Jahr bleibt und geht der Regel vor. |

**Was gebaut ist:**

- **Regelwerk der Feiertage:** Vorgabe ist die Steuerdatei `module/personal/regelwerk/feiertage-de.json` (20 Feiertage: neun
  bundeseinheitliche und die der Länder; bewegliche aus dem Osterdatum, Buß- und Bettag als Mittwoch vor dem 23.11.). Der
  Mandant führt sein Regelwerk in der Datenbank (`personal.feiertag`) und passt es unter „Abwesenheiten“ → „Feiertage …“ an:
  ändern, eigenen Feiertag anlegen, entfernen, **„Vorgabe wieder einlesen“**.
- **Arbeitsort** (`personal.arbeitsort`): Name, Bundesland und die Feiertage, die dort gelten – mit dem Land vorbelegt, frei
  zu- und abwählbar („Arbeitsorte …“). Jede Person hat ihren Arbeitsort („Personen“ → Bearbeiten); ohne Angabe gilt der des
  Mandanten; ohne jeden Arbeitsort gelten die bundeseinheitlichen. Der Kalender zeigt die Feiertage je Person.
- **Korrektur der Arbeitstage** („Arbeitstage …“): neu rechnen nach dem heutigen Stand oder von Hand setzen (gekennzeichnet ✎).
- **Urlaubsregel** des Mandanten (`personal.urlaubsregel`, „Urlaubsregel …“), je „gilt ab Jahr“ eine:

| Einstellung | Möglichkeiten |
|---|---|
| Anteiliger Anspruch | **Teilurlaub nach § 5 BUrlG** · immer ein Zwölftel je vollem Monat (vertraglich) · aus |
| Rundung | **§ 5 Abs. 2** (ab einem halben Tag auf volle Tage auf, kleinere Bruchteile bleiben) · auf halbe · immer auf ganze · kaufmännisch · keine |
| Übertrag | **der Rest geht ins Folgejahr**, wahlweise mit Höchstzahl · nie von selbst |
| Verfall des Übertrags | **nach dem Stichtag bestätigt das Personal** · von selbst · nie; Stichtag frei, **Vorgabe 31.03.** |

  Fett ist die Vorgabe; sie gilt **ab dem laufenden Jahr**. Am Urlaubskonto setzt das Personal je Person und Jahr Anspruch,
  Übertrag und Verfallenes fest – das geht der Regel vor.
- **Hinweis auf drohenden Verfall:** bei der Person unter „Meine Daten“ und beim Personal unter den Urlaubskonten
  („x Tage aus dem Vorjahr verfallen am …“; nach dem Stichtag „das Personal entscheidet“).

**Geprüfte Rechtslage (Stand 11/2026, Gesetzestext und Sekundärquellen – keine Rechtsberatung, vom Verantwortlichen zu prüfen):**
§ 4 BUrlG: voller Anspruch erstmals nach sechs Monaten. § 5 Abs. 1: ein Zwölftel je vollem Monat des Bestehens nur (a) wenn
die Wartezeit im Kalenderjahr nicht erfüllt wird (Eintritt in der zweiten Jahreshälfte), (b) bei Ausscheiden vor erfüllter
Wartezeit, (c) bei Ausscheiden nach erfüllter Wartezeit in der ersten Jahreshälfte; sonst der volle Anspruch. § 5 Abs. 2:
Bruchteile ab einem halben Tag werden aufgerundet. § 7 Abs. 3: Urlaub ist im Kalenderjahr zu nehmen; übertragener Urlaub in
den ersten drei Monaten des Folgejahres. Nach der Rechtsprechung (EuGH, BAG) verfällt Urlaub nur, wenn der Arbeitgeber
vorher konkret und rechtzeitig darauf hingewiesen hat; bei langer Krankheit gelten Besonderheiten (15 Monate).

**Festlegungen beim Bauen – bitte bestätigen oder ändern:**

| # | Festlegung |
|---|---|
| FU1 | **Steuerdatei und Regelwerk:** Die Datei ist die Vorgabe des Releases; gerechnet wird aus der Kopie des Mandanten in der Datenbank. Die Oberfläche ändert diese Kopie, nicht die Datei. Ein Update ändert das Regelwerk eines Mandanten **nicht von selbst** – nach einer Pflege liest man die Vorgabe ein; eigene Feiertage bleiben, eigene Änderungen an Feiertagen der Vorgabe gehen dabei verloren. |
| FU2 | **Der Arbeitsort hängt an der Person**, nicht an der Vertragsfassung. Ein Wechsel wirkt auf künftige Rechnungen; Festgehaltenes berichtigt man mit „Arbeitstage …“. |
| FU3 | Von Hand gesetzte Arbeitstage bleiben beim Genehmigen stehen; „Ende ändern“ rechnet neu. |
| FU4 | **Die Vorgabe der Urlaubsregel gilt ab dem laufenden Jahr** – damit bekommt jedes Konto ab dem Folgejahr den Rest als Übertrag, der am 31.03. zum Verfall ansteht. Frühere Jahre haben keine Regel. |
| FU5 | **Lesart von § 5:** Eintritt ab dem 1. Juli → Teilurlaub (bei Eintritt am 1. Juli ist die Wartezeit erst mit Ablauf des Jahres erfüllt). „Volle Monate“ sind Monate des Bestehens ab dem Eintrittstag, nicht Kalendermonate. Beginn = frühester „Beginn“ in den Fassungen des Vertrags; Austritt = „Austritt“ der Person. |
| FU6 | Die Regel rechnet mit dem **ganzen Urlaub des Vertrags**; kumero trennt gesetzlichen Mindesturlaub und vertraglichen Mehrurlaub nicht und prüft nicht, ob „immer Zwölftel“ den Mindesturlaub unterschreitet. |
| FU7 | **Übertrag ohne Prüfung eines Übertragungsgrunds** (§ 7 Abs. 3 Satz 2): Mit „der Rest geht ins Folgejahr“ wird der ganze Rest übertragen. |
| FU8 | **Auf den Übertrag zählt** genehmigter Urlaub mit seinen Arbeitstagen bis zum Stichtag; was danach liegt, geht vom Anspruch des Jahres ab. |
| FU9 | **kumero vermerkt nicht, dass und wann der Arbeitgeber hingewiesen hat** – die Anzeige unter „Meine Daten“ ist der Hinweis, ein Nachweis ist sie nicht. Lange Krankheit und andere Sonderfälle setzt das Personal am Konto fest („verfallen: 0“). |
| FU10 | Nicht gebaut: Umrechnung bei Wechsel der Arbeitstage im Jahr, Staffeln nach Betriebszugehörigkeit, Zusatzurlaub, Feiertage anderer Staaten, halbe Feiertage (24.12., 31.12. – als „freier Tag“ nur ganz). |

Geprüft: `tests/PersonalTest.php` (435 Prüfungen; darunter bewegliche Feiertage 2027, Augsburg gegen Dresden, Korrektur,
Regelwerk ändern und Vorgabe einlesen, § 5 in allen drei Fällen, Rundung, Übertrag, Verfall bestätigt / von selbst / begrenzt)
und `tests/browser/personal.mjs` (67 Prüfungen, Bilder angesehen). **Nicht geprüft:** das Update eines Mandanten mit
Abwesenheiten aus 0.7.45 (Migration nur frisch gelaufen), Jahre vor 2000 und nach 2100, viele Arbeitsorte, ob das Regelwerk
in jedem Land stimmt – **das prüft der Verantwortliche**.

## 10d. Stufe 3 – Zuschlagsregeln, Zuschlag von Hand, Lohnvorbereitung – gebaut mit 0.7.47 (F140)

Modul `personal` 0.4.0, Migration `0004_lohnvorbereitung.sql`. Auftrag des Verantwortlichen vom 2026-11-20 („bau mal
Stufe 3 und 4“; „wenn was hängt, bau so, dass wir ändern können“). Damit sind PE10 und PE13 freigegeben.

**Was hängt, und wie es gebaut ist.** Stufe 3 braucht nach Abschnitt 10 die Module `leistungen` und `zeit`; beide sind
Entwürfe ohne Freigabe. Gebaut ist deshalb alles, was das Personal selbst weiß, und eine **Anschlussstelle** für das,
was fehlt:

- Die Funktion `personal._zuschlag_nachweis(fassung, person, monat)` liefert zu einer Regel den Betrag samt Nachweis
  (`{"betrag_cent", "nachweis": [{"datum", "text", "menge", "grundlage_cent", "betrag_cent"}]}`) oder NULL. **Heute
  liefert sie immer NULL.** Das Modul, das die Quelle bringt, ersetzt sie (`CREATE OR REPLACE`); Unterlage, Druck,
  Summen und Selfservice zeigen Betrag und Nachweis dann ohne weitere Änderung.
- Solange NULL kommt, steht in der Unterlage **„Nachweis fehlt“ statt eines Betrags** – für Zeitzuschläge ist das ohnehin
  die Regel aus PE11, für Leistungen gilt es jetzt genauso. Ist- und Überstunden fehlen aus demselben Grund.

**Gebaut:**

- **Zuschlagsregeln** mit Namen, Grundlage (Leistung oder Zeit), Lohnart und **Fassungen mit „gültig ab“**. Formen wie in
  Abschnitt 5: Anteil am Betrag, fester Betrag je Leistung, Staffel (Schwelle im Monat, zweiter Satz); Umfang alle
  Leistungen, eine Gruppe, eine Leistung; für Zeit ein Fenster (Nacht, Sonntag, Feiertag, Überstunden) mit Satz in
  Prozent des Grundlohns. **Zuordnung** an eine Person oder an eine Funktion, von–bis.
- **Zuschlag von Hand** je Person und Monat: Betrag in Cent, Begründung, Lohnart; negativ als Berichtigung. Zurücknehmen
  statt löschen.
- **Lohnvorbereitung** je Monat und Person, Seite „Lohnvorbereitung“: Vertrag am Monatsende, Ein- und Austritt im Monat,
  Sollstunden und Arbeitstage (aus dem Vertrag des jeweiligen Tages, ohne Feiertage am Arbeitsort und freie Tage),
  Abwesenheiten mit dem Teil im Monat und Summen je Art, Zuschläge, Vertragsänderungen im Monat (nur die Namen der
  Felder), Bemerkung. **offen → geprüft → übergeben**; „Prüfung zurücknehmen“; nach der Übergabe „Neue Fassung …“ mit
  Vermerk.
- **Druckansicht** („Drucken“ für die gewählte Person, „Sammelliste drucken“ für alle, je Person eine Seite); das PDF
  entsteht im Druckdialog des Browsers, wie bei den Auswertungen.
- **Selfservice:** Unter „Meine Daten“ sieht die Person ihre **übergebenen** Lohnvorbereitungen.
- **Rechte und Rollen:** `personal.lohn.sehen`, `personal.lohn.pruefen`, `personal.lohn.uebergeben`,
  `personal.zuschlag.pflegen`. Personal: sehen, prüfen, Zuschläge führen. Lohn: sehen, prüfen, übergeben. Vorgesetzter
  und Mitarbeiter haben keinen Zugang zur Seite.

**Festlegungen beim Bauen (zu bestätigen):**

| # | Festlegung |
|---|---|
| L1 | **Stufe 3 ohne `zeit` und `leistungen`.** Regeln lassen sich pflegen und zuordnen; gerechnet wird erst mit der Quelle. |
| L2 | **Anschlussstelle ist eine Funktion der Datenbank** (`_zuschlag_nachweis`), nicht ein fester Bezug auf Tabellen anderer Module. `leistungen` liegt am Buch, `personal` am Mandanten – wie die Daten über diese Grenze kommen, entscheidet sich mit LE1; die Funktion legt nur fest, was ankommen muss. |
| L3 | **Gerechnet wird an einer Stelle** (`_lohn_inhalt`); offen zeigt die Seite den Stand von jetzt, mit der Prüfung wird er **eingefroren**. |
| L4 | **Ändert sich nach der Prüfung etwas** (eine Abwesenheit kommt dazu), bleibt die geprüfte Unterlage, wie sie ist; die Seite zeigt „seit der Prüfung geändert“. |
| L5 | **Solange geprüft oder übergeben ist, ändert sich an Zuschlägen von Hand und an der Bemerkung des Monats nichts.** |
| L6 | **Prüfen dürfen Personal und Lohn, übergeben nur Lohn; Zuschläge führt nur das Personal.** In kleinen Betrieben hat eine Person beide Rollen (PE18). |
| L7 | **„Übergeben“ ist ein Vermerk.** kumero überträgt nichts; übergeben wird der Ausdruck oder das PDF (PE14). |
| L8 | **Berichtigung nach der Übergabe:** weitere Fassung mit Vermerk, die übergebene bleibt stehen; oder Nachtrag im Folgemonat als Zuschlag von Hand. |
| L9 | **Die Person sieht ihre Lohnvorbereitung erst nach der Übergabe**, ohne die Bemerkung an das Lohnbüro. |
| L10 | **Regeln hängen an einer Person oder an einer Funktion** (Text der Funktion im Vertrag am Monatsende, ohne Groß- und Kleinschreibung) – nicht an der einzelnen Fassung des Vertrags. Hängt dieselbe Regel an beidem, zählt sie einmal. |
| L11 | **Es gilt die Fassung der Regel vom Monatsende.** Eine Fassung, die mitten im Monat beginnt, gilt für den ganzen Monat. Tagesgenau zu teilen ist Sache der Quelle. |
| L12 | **Beträge in ganzen Cent, Sätze in Hundertstel Prozent** – keine Dezimalzahlen. |
| L13 | **Abwesenheiten über die Monatsgrenze** zählen mit den Arbeitstagen im Monat, neu gerechnet; von Hand berichtigte Tage gelten nur, wenn die Abwesenheit ganz im Monat liegt. |
| L14 | **Die Unterlage nennt Abwesenheiten mit Art und Zeitraum, auch die AU** – ohne Angaben zur Bescheinigung. |
| L15 | **Vertragsänderungen stehen nur mit den Namen der geänderten Felder in der Unterlage.** |
| L16 | **Eine Lohnvorbereitung gibt es für den laufenden und für vergangene Monate**, für jede Person, die im Monat beschäftigt ist. |
| L17 | **Zuschläge von Hand tragen einen Betrag und liegen unverschlüsselt in der Datenbank** wie alles im Personal bis P38 – weiter nur mit erfundenen Personen. Vergütung und Grundlohn führt das Modul weiterhin nicht. |
| L18 | **Im Protokoll stehen IDs und der Monat, nie ein Betrag, keine Begründung.** Lohnvorbereitung und Zuschlag von Hand sind Objekte mit eigener Löschfrist. |

**Nicht gebaut:** das Rechnen der Zuschläge aus Leistungen und Zeiten (Quelle fehlt), Ist- und Überstunden, der
Grundlohn je Stunde (Vergütung ist zurückgestellt), der DATEV-Export (PE14), eine Sammelübergabe für alle Personen in
einem Schritt, Lohnvorbereitungen in der Akte unter „Personen“.

**Geprüft:** `tests/PersonalTest.php` (557 Prüfungen); `tests/browser/personal.mjs` (78 Prüfungen) mit Regel, Zuordnung,
Zuschlag von Hand, Bemerkung, Prüfen und Druckansicht.
**Nicht geprüft:** das Ersetzen der Anschlussstelle durch eine echte Quelle; viele Personen in einem Monat; der Ausdruck
auf Papier und der Seitenumbruch der Sammelliste im echten Druckdialog; das Update eines Mandanten mit Daten aus 0.3.0.

## 10e. Stufe 4 – Beurteilungen und Zielvereinbarungen – gebaut mit 0.7.48 (F141)

Modul `personal` 0.5.0, Migration `0005_beurteilungen.sql`. Auftrag des Verantwortlichen vom 2026-11-20 („bau mal Stufe 3
und 4“); damit ist PE15 freigegeben. Stufe 4 hängt an keinem anderen Modul und ist vollständig gebaut.

**Gebaut:**

- **Vorlagen** des Mandanten: Name, Kriterien (Name, Beschreibung), Skala (von 0 oder 1 bis höchstens 10) mit der
  Bedeutung ihrer Enden; stilllegen statt löschen.
- **Beurteilung** einer Person für einen Zeitraum nach einer Vorlage, mit einem Beurteiler. Ablauf: **offen**
  (Selbsteinschätzung der Person, Einschätzung des Beurteilers – je Kriterium ein Wert und eine Anmerkung, dazu eine
  Gesamteinschätzung) → **abgeschlossen** (Gespräch mit Datum, Ergebnis) → **zur Kenntnis genommen** (mit Stellungnahme
  der Person, wenn sie will). Aufheben mit Grund, solange offen.
- **Ziele:** vorgeschlagen (Vorgesetzter oder Personal) → **vereinbart** (die Person bestätigt; lehnt sie ab, mit Grund)
  → Stand in Prozent mit Anmerkung (Person oder Vorgesetzter) → **bewertet** (erreicht, teilweise, nicht) oder
  aufgehoben.
- **Seite „Beurteilungen und Ziele“** für Vorgesetzte und Personal: zwei Listen in Reitern mit Sortierung und Filter,
  darunter (verschiebbarer Teiler) das Gewählte – beide Einschätzungen nebeneinander. Zwei feste Leisten.
- **Selfservice** unter „Meine Daten“: „Meine Beurteilungen“ (Selbsteinschätzung, zur Kenntnis nehmen) und „Meine Ziele“
  (bestätigen, ablehnen, Stand melden).
- **Rechte und Rollen:** `personal.beurteilung.eigene` (Mitarbeiter), `personal.beurteilung.team` (Vorgesetzter,
  Personal), `personal.beurteilung.sehen` und `personal.beurteilung.pflegen` (Personal). **Die Rolle Lohn hat keines
  davon.**

**Festlegungen beim Bauen (zu bestätigen):**

| # | Festlegung |
|---|---|
| B1 | **Kriterien und Skala werden beim Anlegen in die Beurteilung kopiert.** Wer die Vorlage später ändert, ändert keine laufende und keine abgeschlossene Beurteilung. |
| B2 | **Niemand schreibt vom anderen ab:** Der Beurteiler liest die Selbsteinschätzung erst, wenn er seine eigene abgegeben hat; die Person liest die des Beurteilers, wenn beide abgegeben haben, spätestens mit dem Abschluss. Vorher steht nur da, dass abgegeben ist. Das Personal sieht beide sofort. |
| B3 | **Solange die Beurteilung offen ist, lässt sich die eigene Einschätzung neu abgeben**; die frühere Fassung wird nicht aufbewahrt. Nach dem Abschluss ändert sich nichts mehr. |
| B4 | **Die Selbsteinschätzung ist freiwillig** – abgeschlossen werden kann ohne sie, mit Hinweis. Die Einschätzung des Beurteilers, das Datum des Gesprächs und das Ergebnis sind Pflicht. |
| B5 | **Kenntnisnahme ist keine Zustimmung.** Die Stellungnahme der Person steht bei der Beurteilung; sie lässt sich nur einmal abgeben. Ohne Kenntnisnahme bleibt die Beurteilung „abgeschlossen – Kenntnisnahme fehlt“; erzwungen wird nichts. |
| B6 | **Beurteiler ist eine Person, vorbelegt mit dem Vorgesetzten.** Der Vorgesetzte legt für seine unmittelbar Unterstellten an und beurteilt selbst; das Personal legt für alle an, nennt den Beurteiler oder lässt ihn leer – dann schätzt das Personal ein. |
| B7 | **Über Eigenes entscheidet niemand** – auch wer das Personal führt, sieht die eigene Beurteilung nur wie jede Person. |
| B8 | **Für eine Person läuft je Zeitraum nur eine offene Beurteilung.** |
| B9 | **Ein Ziel ist erst vereinbart, wenn die Person es bestätigt.** Die Person schlägt selbst keine Ziele vor. |
| B10 | **Der Vorgesetzte sieht die Ziele seiner unmittelbar Unterstellten**, Beurteilungen nur, wenn er als Beurteiler eingetragen ist – auch außerhalb seines Teams. |
| B11 | **Ein bewertetes Ziel löst keinen Zuschlag aus.** Wer eine Zielerreichung vergüten will, legt in der Lohnvorbereitung einen Zuschlag von Hand an (Abschnitt 5). |
| B12 | **Beurteilung und Ziele sind nicht verknüpft** – ein Ziel hängt an der Person, nicht an einer Beurteilung. |
| B13 | **Im Protokoll stehen IDs, nie ein Wert und kein Text.** Beurteilung und Ziel sind Objekte mit eigener Löschfrist (PE12). |
| B14 | **Keine Auswertung über Personen hinweg** – keine Durchschnitte, keine Ranglisten. |

**Nicht gebaut:** Beurteilungen und Ziele in der Akte unter „Personen“; Benachrichtigung (kommt mit dem Postkorb, P38);
Runden für viele Personen auf einmal; Druckansicht der Beurteilung; Unterschrift; Zähler im Menü; Vertretung des
Beurteilers.

**Geprüft:** `tests/PersonalTest.php` (684 Prüfungen) – Ablauf, Sichtbarkeit je Rolle und Zeitpunkt, Lohn ohne Zugang,
Protokoll ohne Werte; `tests/browser/personal.mjs` (92 Prüfungen) mit Vorlage, Beurteilung, Einschätzung, Abschluss,
Ziel, Selbsteinschätzung, Kenntnisnahme, Bestätigen und Stand.
**Nicht geprüft:** Mitbestimmung des Betriebsrats bei Beurteilungsgrundsätzen (§ 94 BetrVG – nicht betrachtet, zu
klären, bevor das mit echten Personen genutzt wird); lange Texte und viele Kriterien in der Ansicht; das Update eines
Mandanten mit Daten aus einer früheren Modulversion.

## 11. Offen und ungeprüft

- Abschnitt 8 ist Recherche, keine Prüfung. Der Satzaufbau des DATEV-Imports ist nicht im Einzelnen gelesen; maßgeblich
  ist das Schnittstellenhandbuch und eine Musterdatei des Lohnbüros.
- Aufbewahrungs- und Löschfristen für Personalunterlagen sind nicht nachgeschlagen; kumero belegt deshalb keine vor
  (Abschnitt 8a).
- **Verschlüsselung (Vorgabe vom 2026-11-20):** Alles mit Personenbezug gehört verschlüsselt in die Datenbank;
  Grundsatz „Kenntnis nur, wenn nötig“. Das Konzept (Schlüssel je Mandant und Rollenschlüssel, mit Arbeitsablauf und
  Hierarchie) und der **Postkorb** je Person sind **aufgenommen und vertagt** → SCHLUESSEL.md (P38). Bis dahin: Daten
  nach Datenklassen getrennt, jede mit eigenem Recht, Zugriff über Funktionen – damit nichts verbaut ist.
- Mitbestimmung (Betriebsrat) bei Zeiterfassung und Beurteilung ist nicht betrachtet.
- Tests nur mit erfundenen Personen (CLAUDE.md); echte Namen, Verträge und Zeiten kommen nie in Tests oder Chats.

## 10f. Schnittstelle zur Zeiterfassung und Jahreskalender (gebaut mit 0.7.53, `personal` 0.6.0, F145)

- **Jahreskalender je Person** (Vorgabe zu ZE8): unter „Meine Daten“ für das laufende und das nächste Jahr – Feiertage am
  eigenen Arbeitsort, freie Tage des Betriebs, Brückentage und die eigenen Abwesenheiten. Ein **Brückentag** ist der
  Freitag nach einem Feiertag oder freien Tag am Donnerstag und der Montag vor einem am Dienstag (zu bestätigen).
- **Was `zeit` vom Personal bekommt:** nur `personal.api_personen()` (ID, Name, Personalnummer, Vorgesetzter, Zugang,
  Ein- und Austritt) und `personal.api_tage(person, von, bis)` (Soll in Minuten, Feiertag, freier Tag, Anteil der
  Abwesenheit und ob sie bezahlt ist) – **nie die Art einer Abwesenheit**, kein Vertrag, keine privaten Angaben.
- **Was die Lohnvorbereitung von `zeit` bekommt:** `zeit.lohn_ist(person, monat)` über die Anschlussstelle
  `personal._ist()` – Ist-Minuten, Überstunden und die Minuten in den Fenstern Nacht, Sonntag, Feiertag. Ist `zeit` nicht
  eingeschaltet, steht wie bisher „Nachweis fehlt“. Zeitzuschläge zeigen die Stunden; **der Betrag bleibt offen**, bis es
  den Grundlohn gibt (P38 und Vergütung sind zurückgestellt).

## 10g. Zufällige Personalnummer und akademischer Titel (gebaut mit 0.7.55, `personal` 0.7.0, F147)

Vorgabe des Verantwortlichen vom 2026-11-21: Eine aufsteigende Personalnummer zeigt jedem, wie lange jemand im Betrieb
ist – besser ein zufälliger, eindeutiger Wert aus Großbuchstaben und Ziffern ohne Verwechslungsmöglichkeit. Und:
akademische Titel fehlen.

**Festlegungen – zu bestätigen:**

| # | Festlegung |
|---|---|
| PN1 | **Sechs Zeichen aus `ACDEFGHJKMNPQRTUVWXY34679`** (25 Zeichen): ohne 0/O, 1/I/L, 2/Z, 5/S, 8/B. Rund 244 Millionen Werte; die Zufallsquelle ist die des Datenbankservers. |
| PN2 | **Niemand gibt die Nummer ein** – auch das Personal nicht, auch nicht über die Schnittstelle. Im Dialog steht das Feld gesperrt da und nennt den Grund (F106). |
| PN3 | **Bestehende Nummern bleiben.** Ein Update ändert keine; das Personal setzt je Person den Haken „neue Personalnummer vergeben“. Einen Lauf „alle neu“ gibt es nicht. |
| PN4 | **Die Nummer ist die von kumero, nicht die des Lohnbüros.** DATEV führt eigene, numerische Personalnummern; ein Feld dafür kommt mit dem Export (PE14, zurückgestellt). Bis dahin trägt die Lohnunterlage die Nummer von kumero. |
| PN5 | **Akademischer Titel:** freier Text bis 40 Zeichen, keine Auswahlliste; er steht vor dem Vornamen („Muster, Dr. Erika“) und ändert die Sortierung nicht. Wer den Namen sieht, sieht ihn. |
| PN6 | Den Titel pflegt das Personal; im Selfservice lässt er sich nicht vorschlagen (wie Name und Geburtsdatum, S9). Schon geprüfte oder übergebene Lohnunterlagen bleiben, wie sie eingefroren sind – ohne Titel. |

Geprüft: `tests/PersonalTest.php` (698), `tests/ZeitTest.php`, `tests/MandantenmoduleTest.php`, Browserlauf `personal.mjs`
(108, Dialog angesehen). Nicht geprüft: die Migration auf einem Mandanten mit Bestand.

## 10h. Kündigungsfrist nach Gesetz, Tarifvertrag oder individuell (gebaut mit 0.7.56, `personal` 0.8.0, F148)

Vorgabe des Verantwortlichen vom 2026-11-21 am Dialog „Vertrag: neue Fassung“: Die Kündigungsfrist muss nach den Regeln
des Gesetzes, nach dem Tarifvertrag des Mandanten oder individuell gehen. Bisher war sie ein freier Text.

**Gebaut:** Am Vertrag die Wahl gesetzlich · Tarifvertrag · individuell (Wortlaut nur dort). „Kündigungsregeln …“ auf der
Seite „Personen“: die gesetzliche Regel (Vorgabe aus der Steuerdatei, anzupassen, „Vorgabe wieder einlesen“) und die
Tarifverträge des Mandanten (anlegen, ändern, stilllegen). Akte und „Meine Daten“ zeigen, was heute gilt, z. B.
„gesetzlich – heute: Arbeitgeber 4 Monate zum Monatsende (ab 10 Jahren im Betrieb) · Arbeitnehmer 4 Wochen zum 15. oder
zum Monatsende“. Endpunkte: `GET`/`POST /kuendigungsregeln`, `PUT /kuendigungsregeln/{id}`, `POST /kuendigungsregeln/vorgabe`
(Recht `personal.vertrag.pflegen`); `POST /personen/{id}/vertraege` nimmt `kuendigung_art` und `kuendigungsregel`.

**Vorgabe (Stand 11/2026, Gesetzestext nachgelesen – keine Rechtsberatung, vom Verantwortlichen zu prüfen):** § 622 BGB
Abs. 1: vier Wochen zum Fünfzehnten oder zum Ende eines Kalendermonats. Abs. 2 (Kündigung durch den Arbeitgeber, jeweils
zum Ende eines Kalendermonats): nach 2 Jahren 1 Monat, 5 → 2, 8 → 3, 10 → 4, 12 → 5, 15 → 6, 20 → 7 Monate. Abs. 3: in
einer vereinbarten Probezeit, längstens sechs Monate, zwei Wochen.

**Festlegungen – zu bestätigen:**

| # | Festlegung |
|---|---|
| KF1 | **Eine Regel hat diese Form:** Probezeit in Wochen (zu jedem Tag; 0 = ohne Frist), Grundfrist in Wochen mit Termin (15. oder Monatsende · Monatsende · jeder Tag), Staffel für den Arbeitgeber (ab vollen Jahren im Betrieb → Monate zum Monatsende, bis zwölf Stufen), und für die Person: Grundfrist oder dieselbe Staffel. Was ein Tarifvertrag darüber hinaus regelt (Fristen in Tagen, Quartalsende, Staffeln nach Alter), passt nicht hinein – dann „individuell“ mit Wortlaut. |
| KF2 | **Neue Fassungen sind gesetzlich vorbelegt.** Bestehende bleiben individuell mit ihrem Wortlaut; eine ohne Wortlaut zeigt keine Frist, bis eine neue Fassung angelegt ist. |
| KF3 | **kumero nennt die Frist von heute**, gerechnet aus dem „Beginn des Arbeitsverhältnisses“ der geltenden Fassung. Es rechnet keinen Kündigungstermin, prüft keine Kündigung und kennt keinen Sonderkündigungsschutz. Maßgeblich ist im Ernstfall der Tag des Zugangs. |
| KF4 | **Probezeit:** Solange „Probezeit bis“ nicht vorbei ist, gilt die Probezeitfrist – kumero prüft nicht, ob die Probezeit länger als sechs Monate vereinbart ist. |
| KF5 | **Die Regel gilt mit ihrem heutigen Stand** für alle Verträge, die auf sie zeigen; Regeln haben keine Fassungen mit „gültig ab“. Wer die gesetzliche Regel ändert, ändert die Anzeige aller gesetzlichen Verträge. |
| KF6 | **Ein Update ändert die Regel des Mandanten nicht** (wie FU1); nach einer Gesetzesänderung liest man die Vorgabe ein. Tarifverträge bleiben dabei unberührt. |
| KF7 | **kumero prüft nicht, ob ein Tarifvertrag oder ein individueller Wortlaut zulässig ist** (§ 622 Abs. 4 bis 6 BGB – etwa dass für die Person keine längere Frist gelten darf als für den Arbeitgeber). |
| KF8 | **Kündigungsregeln pflegt, wer Verträge pflegt** (Rolle Personal); sie sind keine Personendaten und stehen mit ihren Werten im Protokoll. Ein Tarifvertrag, der an einer Fassung hängt, lässt sich stilllegen, nicht löschen. |
| KF9 | **Die Person sieht ihre Frist** unter „Meine Daten“; der Vorgesetzte sieht sie nicht (Vertragsdaten, S6). Ein Schalttag als Beginn zählt das volle Jahr erst am 1. März. |

Geprüft: `tests/PersonalKuendigungTest.php` (ohne Datenbank: Probezeit, Grundfrist, alle sieben Stufen), `tests/PersonalTest.php`
(748), Browserlauf `personal.mjs` (116, beide Dialoge angesehen). Nicht geprüft: die Migration auf einem Mandanten mit
Verträgen, ein echter Tarifvertrag, die Darstellung langer Zeilen in der Akte.
