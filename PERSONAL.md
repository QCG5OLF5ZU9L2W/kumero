# PERSONAL.md – Personal: Stamm, Verträge, Abwesenheiten, Zuschläge, Lohnvorbereitung (Fassung 4)

Stand: 2026-11-20. Bezug: F31 (Module erzeugen nur Entwürfe), F41 (Rechtemodell), F43 (Administrator ohne
Buchungsdaten), F44 (Module), F106 (nichts verschiebt sich), F110 (kumero hält kein Dokument), F114 (Nutzer gehören zum
Mandanten), F126 (Bücher), F134 (Module bringen Rollen mit). Geschwister: **ZEIT.md**, **LEISTUNGEN.md**,
**DIENSTPLAN.md**.
**Status: Stufe 0 (Kern: Module, Rechte und Rollen am Mandanten) gebaut mit 0.7.42 (F135); Stufe 1 (Person, Vertrag,
Fristen, Akte, Selfservice – ohne Vergütung und Lohnmerkmale) gebaut mit 0.7.43 (F136, Abschnitt 10a). Stufen 2 bis 4
sind nicht gebaut.**

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
| PE1 | Schnitt | vier Module wie in Abschnitt 1; Lohnabrechnung nie. |
| PE2 | Ebene | `personal`, `zeit`, `dienstplan` am **Mandanten**; `leistungen` am **Buch**. Dafür Mandantenmodule mit eigenen Rechten und Rollen (Abschnitt 2). |
| PE3 | Person und Nutzer | Eine Person kann ohne Nutzer geführt werden; für den Selfservice wird sie mit genau einem Nutzer verknüpft. |
| PE4 | Vertrag | Fassungen mit „gültig ab“; keine wird überschrieben. |
| PE5 | Lohn tragendes Buch | je Vertrag genau ein Buch; Aufteilung auf mehrere Bücher später. |
| PE6 | Abwesenheitsarten | feste Liste aus Abschnitt 3, vom Mandanten ergänzbar; jede Art sagt: bezahlt, zählt gegen Urlaub, braucht Genehmigung. |
| PE7 | AU | Felder wie im eAU-Verfahren, nie eine Diagnose; kein Abruf bei Krankenkassen. |
| PE8 | Sichtbarkeit von Gründen | Vorgesetzter und Dienstplan sehen „abwesend“ ohne Art; Art und AU-Angaben sehen nur Personal und Lohn. |
| PE9 | Lohnmerkmale | **Entschieden 2026-11-20:** kumero führt Bankverbindung, Steuer- und Sozialversicherungsmerkmale (Abschnitt 3). Eigenes Recht; nur Personal und Lohn, die Person ihre eigenen; im Protokoll kein Wert. |
| PE10 | Zuschlagsregeln | zwei Grundlagen (Leistung, Zeit), Formen wie in Abschnitt 5; am Vertrag oder an der Funktion; Fassungen mit Gültigkeit. |
| PE11 | Nachweis | Zu jedem Zuschlag gehört die Liste, aus der er gerechnet ist; die Person sieht ihren Nachweis. **Entschieden 2026-11-20:** Für Zeitzuschläge ist die Einzelaufzeichnung Rechtspflicht – ohne freigegebenes Monatsblatt kein Betrag. |
| PE12 | Aufbewahrung und Löschen | Nach dem Austritt wird eine Person gesperrt, nicht gelöscht; **Entschieden 2026-11-20:** Die Löschfrist wird **je Objekt und Mitarbeiter** festgelegt (Abschnitt 8a). |
| PE13 | Lohnvorbereitung | je Monat und Person; offen → geprüft → übergeben; danach gesperrt. |
| PE14 | Übergabe | lesbares PDF. **Entschieden 2026-11-20:** Der DATEV-Export ist zurückgestellt; die Unterlage bleibt so gegliedert, dass er später daraus entstehen kann. Keine Übertragung durch kumero. |
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
| **3** | Zuschlagsregeln und Lohnvorbereitung mit PDF – braucht `leistungen`, für Stunden `zeit`. |
| **4** | Beurteilungen und Zielvereinbarungen. |
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
