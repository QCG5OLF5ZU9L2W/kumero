# UEBERGABE.md – Stand der Arbeit für den nächsten Chat

Stand: 2026-11-21, kumero **0.7.69**. Diese Datei sagt, wo die Arbeit steht und wie sie praktisch abläuft.
Maßgeblich bleiben **AGENTS.md** (Entscheidungen F1–F156, offene Punkte P10–P44) und **CLAUDE.md** (Arbeitsweise, Grenzen).
Wer weiterarbeitet, liest zuerst diese drei Dateien und danach die Spezifikation des Teils, an dem gearbeitet wird.

## 0. Das Wichtigste für den nächsten Chat (Stand 2026-11-20, später Abend)

**Eingecheckt und hochgeladen:** kumero **0.7.69** auf `release-0.7.0`. Commits für die Tags: **0.7.42 = `a35cd61`**,
**0.7.43 = `6003b01`**, **0.7.44 = `40780fa`**, **0.7.45 = `c4da481`** (spätere
Commits ändern nur diese Datei). `v0.7.41` steht auf GitHub; **die Tags ab `v0.7.42` hat der Verantwortliche noch zu setzen**
(soweit nicht geschehen), dann auf der VM zu bauen, zu signieren und einzuspielen. 0.7.44 muss nicht eigens installiert
werden – jede spätere Version enthält die früheren. **0.7.46 = `11aca80`, 0.7.47 = `f32064b`, 0.7.48 = `99e182e`, 0.7.49 = `eecddec`, 0.7.50 = `6797438`, 0.7.51 = `22c153d`.**

**Was an diesem Tag entstand (in dieser Reihenfolge):**
1. Lager Stufen 0–3 und eigene Rollen (0.7.37–0.7.41, F130–F134, LAGER.md).
2. Entwürfe PERSONAL.md, ZEIT.md, LEISTUNGEN.md, DIENSTPLAN.md (P34–P37); SCHLUESSEL.md (Verschlüsselung mit
   Rollenschlüsseln und Postkorb – **aufgenommen und vertagt**, P38).
3. **0.7.42 (F135):** Module, Rechte und Rollen am Mandanten – noch ohne ein solches Modul.
4. **0.7.43 (F136, F137):** Modul `personal` Stufe 1 am Mandanten; Baustein `teiler.js`.
5. **0.7.44 (Fehler vom Verantwortlichen gemeldet, Bild „Wareneingang“ mit „Bitte anmelden“):** Nach Ablauf der Sitzung
   gab es nichts mehr zu sehen. Jetzt: Dialog „Bitte neu anmelden“ über der stehenden Seite, danach weiter an derselben
   Stelle (OBERFLAECHE.md „Abgelaufene Sitzung“). Dazu: Zähler „Fristen und Nachweise“ erschien nie (`zahl` statt
   `anzahl`); Hinweis unter „Meine Daten“ für den, der selbst das Personal führt (zweites Bild des Verantwortlichen).
6. **0.7.45 (F138):** `personal` Stufe 2 – Abwesenheiten, AU ohne Diagnose, Urlaubskonto, freie Tage, Kalender
   (PERSONAL.md 10b, Festlegungen AW1–AW18).
7. **0.7.46 (F139), auf die Antworten zu AW2 und AW11:** Feiertage je Arbeitsort aus einem Regelwerk (Steuerdatei, in der
   Oberfläche anzupassen), Korrektur der Arbeitstage, Urlaubsregel (Teilurlaub nach § 5 BUrlG, Übertrag, Verfall am 31.03.,
   alles einstellbar) – PERSONAL.md 10c, entschieden FT1–FT6 und UR1–UR5, zu bestätigen FU1–FU10.

8. **0.7.47 (F140), Auftrag „bau mal Stufe 3 und 4“ mit dem Zusatz „wenn was hängt, bau so, dass wir ändern können“:**
   `personal` Stufe 3 **ohne** `zeit` und `leistungen` – Zuschlagsregeln mit Fassungen und Zuordnung, Zuschlag von Hand,
   Lohnvorbereitung je Monat (offen → geprüft → übergeben, weitere Fassung mit Vermerk), Druck einzeln und als
   Sammelliste. **Anschlussstelle `personal._zuschlag_nachweis()`** liefert heute NULL → „Nachweis fehlt“ statt eines
   Betrags. PERSONAL.md 10d, zu bestätigen L1–L18.
9. **0.7.48 (F141):** `personal` Stufe 4 – Vorlagen, Beurteilungen (Selbsteinschätzung, Beurteiler, Gespräch,
   Kenntnisnahme), Ziele (vorschlagen, bestätigen, Stand, bewerten). PERSONAL.md 10e, zu bestätigen B1–B14.
10. **0.7.49 (F142), auf die Frage nach einem Befehl für alle Module – „das muss in die WebUI“, mit Ausschalten:** Seite
   „Module“ im Bereich „Mandant“ (einschalten, ausschalten, alle nachziehen – als Aufträge an den Dienst);
   `aktualisieren.sh` zieht aktive Module nach jedem Update selbst nach (`modul:nachziehen`). VERWALTUNG.md Abschnitt 16,
   zu bestätigen MO1–MO10.
11. **0.7.50 (F143), Antwort auf MO9 „darf nicht passieren“:** Ein Modul gibt einer Rolle jeden Baustein genau einmal
   (`modul_rollenbaustein` in Kern und Mandant); Einschalten und Nachziehen geben nichts zurück, was der Administrator
   einer Rolle genommen hat. Bestätigt: MO1, MO3. Zu MO5 gefragt und beantwortet (Kontenrahmen aus = Buchen geht weiter).
12. **0.7.51 (F144), Wunsch nach einem Skript für das Update:** `werkzeuge/update-neueste.sh` (auf `aequa`, INSTALL.md 3a) –
    holen, bauen, Signatur (fragt), Sicherung (fragt), aktualisieren, nachziehen, Frage „alle Module?“ (`modul:alle`).
    Berichtigt: `aktualisieren.sh` übergibt an das Skript der neuen Version – „dieses eine Mal von Hand nachziehen“ war
    zu vorsichtig gesagt.
13. **0.7.52 (P39 teilweise, auf ein Bild der Seite „Bestand“: „hier müssten die Regeln für Filter, Spalten und
    Verschiebbarkeit eingehalten werden“):** „Bestand“ mit `tabellenfilter` an Artikeln und Chargen und mit `teiler`
    (`lg-teilung`); Menüleiste verschiebbar und ausblendbar (`menueleiste()` in `rahmen.js`). Modul `lager` 0.4.1.
    Zu bestätigen O1–O3 (OBERFLAECHE.md). Geprüft nur im Browser mit nachgestellten Daten; `tests/browser/lager.mjs`
    ist ergänzt (im Chat zu 0.7.53 gelaufen: 57 Prüfungen grün). **Tag `v0.7.52` setzt der Verantwortliche** (Commit: der mit „0.7.52:“ beginnt).
    **Aufgefallen, nicht geändert:** Die Werkzeugleiste von „Bestand“ bricht bei schmalem Fenster in eine zweite Zeile um (F106 Nr. 2).
14. **0.7.53 (F145), Zeiterfassung nach den Vorgaben vom 2026-11-21:** eigenes Modul `zeit` (0.1.0) am Mandanten mit eigener
    Rolle „Zeiterfassung“, „Meine Zeiten“ unter Personal → Meine Daten, Seite „Zeiten“ für Vorgesetzte, Personal, Lohn.
    Minutengenau, Pausenabzug nach Regel, über 10 Std. nur mit Sonderfreigabe, außerhalb der Regelarbeitstage nur mit
    Genehmigung, Gleitzeit mit Zeitkonto, Korrektur ohne Aufbewahren des alten Werts (je Person sofort oder mit Freigabe),
    Monatsabschluss aus/Person/Freigabe. `personal` 0.6.0: Jahreskalender je Person, Ist-Stunden in der Lohnvorbereitung.
    ZEIT.md Abschnitt 9, **zu bestätigen T1–T16** (vor allem T1 Pausenabzug nie unter die Schwelle, T10 Abweichung von PE11,
    T15 Lohn sieht alle Zeiten). Nicht gebaut: Terminal, Leser, Ruhezeit, Schichtplan, Beträge der Zeitzuschläge.
    Die Rolle „Zeiterfassung“ kommt nicht von selbst zu „Mitarbeiter“ – der Administrator weist sie zu.
15. **0.7.54 (F146, aus einem zweiten Chat, der gleichzeitig lief: „definiere, dass die Spalten verschiebbar sein müssen,
    als generelle Anforderung, und passe das Lagermodul vollständig an“; auf Nachfrage: Breite **und** Reihenfolge):**
    `tabellenfilter(spalten, beiAenderung, {id})` mit Griff für die Breite, Ziehen des Titels für die Reihenfolge, Tasten,
    Merken je Browser, `spalten()`. Alle fünf Seiten des Lagers umgestellt (Modul 0.4.2); Wareneingang hält seine Zeilen und
    Zellen fest und hängt sie nur um (`tabelleZeichnen()`), damit keine Eingabe verloren geht. Werkzeugleiste von „Bestand“
    in zwei festen Zeilen. Zu bestätigen O4–O9 (OBERFLAECHE.md). **Tag `v0.7.54` setzt der Verantwortliche** (Commit: der mit
    „0.7.54:“ beginnt). Geprüft nur im Browser mit nachgestellten Daten (59 Prüfungen); `tests/browser/lager.mjs` ist um vier
    Prüfungen ergänzt und **nicht gelaufen**; Firefox und Finger ungeprüft.
    **Für die anderen Module (P39, auch `zeit`):** `{id}` übergeben, jeder Spalte eine `id` geben, Zellen nach `spalten()`
    zeichnen, Regeln mit `td:nth-child` durch Klassen ersetzen; Seiten mit Eingabefeldern wie Wareneingang bauen.
    **Zwei Chats arbeiten auf demselben Zweig:** vor dem Hochladen `git fetch` – Versions- und F-Nummern können vergeben sein.
16. **0.7.55 (F147), auf ein Bild des Dialogs „Person anlegen“:** Die Personalnummer vergibt kumero zufällig (sechs Zeichen
    ohne verwechselbare Zeichen, niemand tippt sie); bestehende bleiben, bis das Personal eine neue vergeben lässt.
    Akademischer Titel an der Person, im Namen vor dem Vornamen. `personal` 0.7.0, PERSONAL.md 10g, zu bestätigen PN1–PN6
    (vor allem PN3 kein Lauf „alle neu“, PN4 die Nummer ist nicht die des Lohnbüros).
    Dazu `zeit` 0.1.1, auf ein Bild der leeren Seite „Zeiten“ von der VM („Kommen und Gehen fehlen?“): Personen ohne Vertrag
    stehen jetzt in der Liste („ohne Vertrag“), die leere Liste nennt ihren Grund, die Seite verweist auf „Meine Zeiten“.
    **Zweimal hat ein gleichzeitig laufender Chat dieselbe Versionsnummer und dieselbe F-Nummer vergeben** (0.7.52, 0.7.54/F146) –
    vor jedem Commit `git fetch` und die Nummern prüfen. **Tag `v0.7.55`: der Commit, der mit „0.7.55:“ beginnt.**
17. **0.7.56 (F148), auf ein Bild des Dialogs „Vertrag: neue Fassung“:** Kündigungsfrist nach Gesetz (§ 622 BGB, Regelwerk
    `kuendigung-de.json`, anzupassen), nach einem Tarifvertrag des Mandanten oder individuell; „Kündigungsregeln …“ auf der
    Seite „Personen“; Akte und „Meine Daten“ nennen die Frist von heute. `personal` 0.8.0, PERSONAL.md 10h, zu bestätigen
    KF1–KF9 (vor allem KF1 Form einer Regel, KF3 nur Anzeige, KF5 Regeln ohne Fassungen). **Die Vorgabe prüft der
    Verantwortliche. Tag `v0.7.56`: der Commit, der mit „0.7.56:“ beginnt.** Das Bild zeigte einen echten Namen – er steht
    nirgends in Code, Tests oder Doku.
18. **0.7.57 (F149), auf zwei Bilder und die Antworten zu PN3, PN4, PN6:** „Zugang anlegen …“ im Dialog der Person (nur
    als Administrator – ZA1, die andere Lesart wäre ein eigenes Recht für das Personal); Feld „Nr. beim Lohnbüro“; Titel im
    Selfservice vorschlagbar; das Monatsblatt der Zeiterfassung als Tabelle mit Filtern und verschiebbaren Spalten.
    `personal` 0.9.0, `zeit` 0.2.0, PERSONAL.md 10i, zu bestätigen ZA1–ZA5. **Tag `v0.7.57`: der Commit, der mit „0.7.57:“
    beginnt.** Die Bilder zeigten echte Namen, Anschrift und Telefonnummer – nichts davon steht in Code, Tests oder Doku.
    **Offen aus F146 (anderer Chat):** verschiebbare Spalten in den übrigen Listen des Personals.
19. **0.7.58 (F150), auf weitere Bilder von der VM:** Bedienregel „Doppelklick öffnet Einzelheiten – wo zu entscheiden ist,
    die Entscheidung“ (OBERFLAECHE.md; gebaut für Personenliste und Monatsblätter, Rest P39); Anmerkung je Zeitabschnitt;
    angelaufene Zeit im Kopf (nur Browser); Aufbewahrung 24 Monate als Regel (MiLoG § 17 – DK3: nur Mindestfrist, kein
    Löschlauf; DK6: Verfall von Stunden ist nicht gebaut); „Zugang anlegen“ merkt vor und legt beim Speichern an; kein Kreis
    in der Auswahl des Vorgesetzten; Aufgaben am Vertrag. `zeit` 0.3.0, `personal` 0.10.0. **Tag `v0.7.58`: der Commit, der
    mit „0.7.58:“ beginnt.**
    **Wartet auf eine Entscheidung: Gehalt am Vertrag** – Vergütung ist bis zur Verschlüsselung zurückgestellt (P38); der
    Verantwortliche vermisst sie (PERSONAL.md 10j). **Vorgemerkt: P41 Faktura auf Kunden und Projekte.**
    Ein Bild zeigte Code und Teil des Links eines echten Zugangs („ist eh nur der Entwicklungsrechner“) – nicht wiederholt.
20. **0.7.59 (F151), am Dialog „Nachtragen oder berichtigen“:** mobiles Arbeiten und Dienstgang gesondert erfasst (Wahl vor
    „Kommen“, am Abschnitt); Formular nennt Kommen und Gehen; Reihen in Formularen oben bündig (Zeit und Personal). `zeit`
    0.4.0, `personal` 0.10.1 (nur CSS). Zu bestätigen DK1–DK9 (ZEIT.md 10). **Tag `v0.7.59`: der Commit, der mit „0.7.59:“
    beginnt.** **Neu vorgemerkt: P42 Auszubildende (Schultage, Ferien, Vertragsart mit eigenen Fristen), P43 Minderjährige.**
    **Der Verantwortliche prüft gerade auf der VM und schickt Bilder im Minutentakt – je Bild ein kleiner Schritt, die
    Nummern vor jedem Commit mit `git fetch` prüfen (ein zweiter Chat arbeitet an der Oberfläche).**
    Dazu in 0.7.59: Doppelklick in „Abwesenheiten“ führt zur Bearbeitung. **Lehre:** Ein `dblclick` kommt nicht an, wenn der
    erste Klick die Liste neu zeichnet – am Listencontainer auf `click` mit `ev.detail === 2` hören und die Zeile über
    `elementFromPoint` suchen (so in `personen.js` und `abwesenheiten.js`).
    **Wartet auf Entscheidungen des Verantwortlichen:** (1) **Gehalt am Vertrag** vor der Verschlüsselung? (PERSONAL.md 10j)
    (2) **AU-Nachweis elektronisch/Papier mit Upload** – der Upload widerspricht F110 (AGENTS.md P44, drei Wege).
21. **F152 (Doku, kein Release): Dienstleistungen entschieden.** Auf die Frage „fehlt die Lohnberechnung für individuelle
    Verkäufe?“: Die Regeln gibt es (10d), die Verkäufe nicht. Der Verantwortliche hat LE1–LE11 entschieden – **am Mandanten,
    nicht im Buch**; Katalog; Mitarbeiter trägt ein, Vorgesetzter bestätigt; Löschen statt Storno (LEISTUNGEN.md Abschnitt 10).
    **Als Nächstes: Modul `leistungen` Stufe 1 bauen, sobald LF3 und LF4 beantwortet sind** – es füllt die Anschlussstelle
    `personal._zuschlag_nachweis()`. Ein zweiter Chat hat inzwischen 0.7.60 (Lager) eingecheckt.
21. **0.7.60, Fehlermeldung des Verantwortlichen an zwei Bildern („Bestand“ und „Nachbestellen“, ein Artikel: 5 Stück abgelaufen,
    Mindestbestand 2):** „Bestand“ zählte für „unter Mindestbestand“ (Kachel, Zustand, Filter) den ganzen Bestand, „Nachbestellen“
    nur den verwendbaren – die Kachel zeigte 0, die Liste 1. Jetzt rechnen beide nach Y17: abgelaufen und gesperrt zählt nicht,
    auch wenn nicht ausgebucht. `lager` 0.4.3, kein neues F (Y17 ergänzt). **Tag `v0.7.60`: der Commit, der mit „0.7.60:“
    beginnt.** `tests/LagerTest.php` ist um den Fall ergänzt und gelaufen (204 Prüfungen grün, siehe 22).
22. **0.7.61 (F153), am Dialog „Vertrag: neue Fassung“ („hier fehlen die grundlegenden Angaben: Gehalt, Arbeitszeit-
    Sondervereinbarungen, Urlaubsgeld und Weihnachtsgeld“):** Vergütung als eigene Datenklasse je Fassung mit eigenem Recht,
    Sondervereinbarungen zur Arbeitszeit als Text. `personal` 0.11.0, PERSONAL.md 10k, **zu bestätigen VG1–VG7**. Damit ist die
    wartende Entscheidung (1) „Gehalt am Vertrag vor der Verschlüsselung?“ beantwortet. **Tag `v0.7.61`: der Commit, der mit
    „0.7.61:“ beginnt.** Nicht gebaut: Vergütung in der Lohnvorbereitung, Lohnmerkmale.
    **Tests laufen in diesem Chat so:** `composer config platform.php 8.3.6 && composer update` (danach `composer.json` und
    `composer.lock` zurücksetzen – nicht einchecken), Wegwerf-Cluster PostgreSQL 16 unter `/tmp/pgtest`, Rollen `kumero_owner`,
    `kumero_app`, `kumero_lesen` von Hand anlegen; Browsertests mit `PLAYWRIGHT=/opt/npm-tools/node_modules/playwright
    CHROME=/usr/bin/google-chrome`. Geprüft ist damit auf PostgreSQL 16 und PHP 8.3, nicht auf 19 und 8.5.
23. **0.7.62, an der Seite „Abwesenheiten“ („hier fehlt eine Jahresansicht; es fehlt ein Eintrag für die möglichen
    Betriebsferien“):** Reiter „Jahr …“ (zwölf Monate einer Person), „Betriebsferien, freie Tage …“ mit Zeitraum von–bis.
    `personal` 0.11.1, PERSONAL.md 10l, **zu entscheiden JB1** (kosten Betriebsferien Urlaub?). Tag `v0.7.62`: der Commit, der
    mit „0.7.62:“ beginnt. Geprüft: `tests/browser/personal.mjs` (134 Prüfungen).
    **Offen aus demselben Chat, nicht nachvollzogen:** (a) „Anmerkungen sind weder im Grid noch hier auszufüllen“ (Dialog
    „Einzelheiten“ unter „Meine Zeiten“) – im Browsertest lässt sich tippen und speichern, auch mit echter Tastatur;
    (b) „die Korrektur kommt nicht an“ (Dialog „Nachtragen oder berichtigen“) – der Ablauf Antrag → „Zeiten“ → annehmen läuft
    im Test. Beim Verantwortlichen nachgefragt, was genau geschieht. Aufgefallen: Das Menü „Zeiten“ trägt keinen Zähler für
    wartende Anträge; Tag und Uhrzeit im Antragsdialog sind Felder des Browsers (zeigen 11/21/2026 bei englischem Browser)
    statt `datumFeld` des Kerns.

**Der Verantwortliche führt aus (nach Rechner getrennt):**
- auf `sbs-server`, Klon `~/kumero-release`: fehlende Tags setzen und pushen (`v0.7.42` a35cd61, `v0.7.43` 6003b01,
  `v0.7.44` 40780fa, `v0.7.45` c4da481, `v0.7.46` 11aca80, `v0.7.47` f32064b, `v0.7.48` 99e182e, `v0.7.49` eecddec, `v0.7.50` 6797438, `v0.7.51` 22c153d, `v0.7.52` = der Commit „0.7.52: Modul zeit …“ (`git log --oneline -3`));
- auf `aequa`, Klon `/root/kumero-release`: **`git switch release-0.7.0 && git pull --ff-only && bash werkzeuge/update-neueste.sh`**
  (baut, fragt nach Signatur, Sicherung und Modulen; INSTALL.md 3a). Danach unter „Personen“ sich selbst als Person anlegen
  und den eigenen Zugang wählen; unter „Abwesenheiten“ → „Arbeitsorte …“ den Arbeitsort des Mandanten; unter „Beurteilungen
  und Ziele“ → „Vorlagen …“ die erste Vorlage. Wer Lohnvorbereitungen übergeben soll, braucht die Rolle „Lohn“. Zuletzt gesehen
  auf der VM: `personal` aktiv, Rollen zugewiesen, noch keine Person verknüpft. **Das Skript ist auf der VM noch nie gelaufen.**

**Wartet auf eine Antwort des Verantwortlichen:**
| Was | Wo |
|---|---|
| Festlegungen zur Seite „Module“: **MO1 und MO3 bestätigt, MO9 entschieden und geändert (F143)**; offen MO2, MO4, MO5 (jedes Modul lässt sich ausschalten, ohne Prüfung auf Offenes), MO6, MO7, MO8, MO10 | VERWALTUNG.md 16 |
| Festlegungen **L1–L18** zu Stufe 3 (vor allem L1/L2 Anschlussstelle statt Quelle, L6 wer prüft und wer übergibt, L9 die Person sieht erst das Übergebene, L11 Fassung vom Monatsende, L14 AU in der Unterlage, L17 Beträge unverschlüsselt bis P38) | PERSONAL.md 10d |
| Festlegungen **B1–B14** zu Stufe 4 (vor allem B2 wer wann welche Einschätzung liest, B4 Selbsteinschätzung freiwillig, B5 Kenntnisnahme, B9 Ziele schlägt nur der Vorgesetzte vor, B11 kein Zuschlag aus einem Ziel); **Mitbestimmung bei Beurteilungsgrundsätzen ist nicht betrachtet** | PERSONAL.md 10e |
| Festlegungen **FU1–FU10** zu Feiertagen und Urlaubsregel (vor allem FU1 Update ändert das Regelwerk eines Mandanten nicht von selbst, FU2 Arbeitsort an der Person, FU4 die Vorgabe gilt ab dem laufenden Jahr, FU5 Lesart von § 5, FU7 Übertrag ohne Prüfung eines Grundes, FU9 kein Vermerk über den Hinweis des Arbeitgebers); **das Regelwerk der Feiertage prüfen** | PERSONAL.md 10c |
| Festlegungen **AW1–AW18** zu `personal` Stufe 2 (AW2, AW3, AW11 sind durch F139 geändert; vor allem AW1 „bezahlt“ je Art, AW2 keine Feiertage vorgegeben, AW6 wer das Personal führt, entscheidet auch Eigenes, AW7 Sicht des Vorgesetzten, AW11 Urlaubskonto ohne Zwölftelung und Verfall) | PERSONAL.md 10b |
| Der Dialog „Bitte neu anmelden“ ist ein modales Fenster – Ausnahme von „nie ein Pop-up“ (F32, F106) | OBERFLAECHE.md |
| Festlegungen **S1–S16** zu `personal` Stufe 1 (vor allem S5 nur unmittelbar Unterstellte, S6 Vorgesetzter ohne Vertragstermine, S9 was sich vorschlagen lässt) | PERSONAL.md 10a |
| Festlegungen **Z1–Z8** zu Modulen am Mandanten – ein „ok“ kam, ausdrücklich bestätigt sind sie nicht | PERSONAL.md 2a |
| **P40: Pflichtangaben für Arzneimittel** im Lager – Feldliste als Vorschlag gegeben (PZN, Wirkstoff, Stärke, Darreichungsform, Packungsgröße, Hersteller, Abgabestatus; Zulassungsnummer freiwillig); **nicht bauen, bevor er sie freigibt oder ändert** | AGENTS.md P40 |
| Festlegungen **Y1–Y31** zum Lager | LAGER.md Abschnitt 14 |
| Entwürfe ZE1–ZE14, LE1–LE11, DP1–DP8; von PERSONAL.md PE16 (PE10, PE13, PE15 sind mit „bau mal Stufe 3 und 4“ freigegeben) | ZEIT.md, LEISTUNGEN.md, DIENSTPLAN.md, PERSONAL.md |
| ältere: SIEGEL.md S1–S10, VERWALTUNG.md M1–M9 und weitere | AGENTS.md Abschnitt 5 |

**Als Nächstes vorgeschlagen (noch nicht beauftragt):**
1. **P39 – Rest (Menüleiste und das ganze Lager sind gebaut, 0.7.52/0.7.54; offen sind Filter und verschiebbare Spalten in den Listen der anderen Module):** Sortierung und Filter in den Tabellenköpfen aller
   Listen, Lager „Bestand“ auf `teiler.js` umstellen, **Menüleiste in der Breite verschiebbar und ausblendbar**. Die
   Vorgabe kam am Bild der Seite „Bestand“; sie gilt grundsätzlich.
2. `zeit` (ZEIT.md, Entwurf Fassung 2 – ZE1–ZE14 sind noch nicht freigegeben; **der Verantwortliche hat am 2026-11-20 nach der
   Arbeitszeiterfassung gefragt** – Vorschlag: erst Stufe 1 und 2, Terminal und Leser danach), danach `leistungen`. Beide füllen die
   Anschlussstelle von `personal` Stufe 3 (`_zuschlag_nachweis`, dazu Ist- und Überstunden in `_lohn_inhalt`); offen ist,
   wie `leistungen` (am Buch) seine Zeilen an den Mandanten gibt (L2).
3. P40, sobald freigegeben. Kleineres am Personal: Löschvorgaben je Objektart und Löschlauf (S11, AW16), Vorwarnung vor
   dem Ablauf der Sitzung (PROFIL.md).

**Entscheidungen des Tages, die leicht untergehen:**
- **Nie die Konfession** – nur das abstrakte Kirchensteuermerkmal, und nur bei einer steuererhebenden Gemeinschaft
  (Art. 140 GG mit Art. 136 Abs. 3 WRV). Kommt erst mit den Lohnmerkmalen.
- **Lohnmerkmale führt kumero** (PE9), aber **erst wenn die Verschlüsselung steht**; ebenso die Vergütung. Bis dahin
  `personal` nur mit erfundenen Personen nutzen.
- **Alles mit Personenbezug gehört verschlüsselt**, „Kenntnis nur, wenn nötig“. Stufe A (Platte) ist Standard, B allein
  erlaubt zu viel, aufgenommen ist B + C mit **Rollenschlüssel**; vertagt wegen Arbeitsablauf und Hierarchie.
- Einzelaufzeichnung für Zeitzuschläge ist **Rechtspflicht**; der Ort einer Zeitbuchung **ergibt sich aus dem Gerät**;
  Löschfrist **je Objekt und Mitarbeiter**; **DATEV-Export zurückgestellt**; ein **Postkorb** je Person gehört dazu.
- Dolibarr und andere: **Funktionen abschauen, kein fremder Code.**

**Lehren aus dem zweiten Chat:**
- **Pfadparameter `{kennung}`** nimmt im Kern keinen Unterstrich (`src/Http/Routen.php`): Ein Aufruf mit Unterstrich endet als 404 ohne Hinweis. Anderen Namen wählen (`{feiertag}`).
- **Dezimalzahlen im Protokoll als Text** (F29, JCS): `to_jsonb()` einer Zeile mit `numeric` wirft KM004.
- **Erst laden, dann melden:** Browsertests warten auf die Meldung; steht sie vor dem Neuladen der Seite, liest der Test den alten Stand.
- **Zähler am Menü heißen `anzahl`** (OBERFLAECHE.md 3) – ein Endpunkt mit anderem Namen fällt nicht auf, der Zähler bleibt nur leer.
- **Abgelaufene Sitzung im Browsertest:** `context.clearCookies()`, dann ein Aufruf – der Dialog `#sitzung-abgelaufen` erscheint.
- JSON kennt kein `28.0`: Tage kommen als Zahl, ganze als `28` – Tests vergleichen streng.
- Die Testumgebung im Chat braucht `/tmp/pgstart.sh` (Wegwerf-Cluster; Rollen `kumero_owner`, `kumero_app`, `kumero_lesen` einmal anlegen) und nach `composer install` den Schritt mit Symfony 7.4 (Abschnitt 6).

**Lehren aus dem ersten Chat:**
- **Rollen mitdenken:** Ein neues Modul braucht eigene Rollen, nicht nur Bausteine an Buchhaltungsrollen (Anlass: Lager
  ohne Rolle fürs Lager). Steht jetzt in CLAUDE.md.
- **Befehle nach Rechner trennen:** Tags auf `sbs-server` (Nutzer `frank`, `~/kumero-release`, Schreibzugang), alles
  andere auf `aequa` (`/root/kumero-release`, nur lesend). Ein Block für beides zusammen läuft nirgends.
- **Nicht mehr versprechen, als sinnvoll ist:** Angekündigt waren leere Tabellen für Vergütung und Lohnmerkmale; gebaut
  wurden keine (S1) – das ist in der Abgabe genannt.
- `replaceChildren(…, null)` schreibt „null“; `knopf(text, fn, art, {class: …})` überschreibt die Klasse des Knopfs –
  Klassen als Liste `['knopf', 'klein', …]` übergeben.
- Die Testdatenbank im Arbeitsverzeichnis fällt gelegentlich aus: vor einem Lauf `bash /tmp/pgstart.sh`.

**Zuletzt:** **0.7.63 (F154), „dann bau mal fertig“:** Modul `leistungen` 0.1.0 am Mandanten – Katalog mit Preisen als Fassungen,
    Eintragen durch die Person, Bestätigen durch den Vorgesetzten, Löschen statt Storno, Sperre nach geprüfter
    Lohnvorbereitung; die Zuschläge nach Leistung rechnen jetzt (`personal._zuschlag_nachweis()` → `leistungen.nachweis()`,
    `personal` 0.12.0). LEISTUNGEN.md Abschnitt 11, **zu bestätigen LG1–LG8** (vor allem LG3 Staffel nur auf den Teil über
    der Schwelle, LG5 Tag der Leistung zählt). **Tag `v0.7.63`: der Commit, der mit „0.7.63:“ beginnt.** Nicht gebaut:
    Einlesen, Auswertung, Kunde und Projekt (P41). Der zweite Chat hat 0.7.60–0.7.62 eingecheckt (Lager, Vergütung am
    Vertrag F153, Abwesenheiten) – **damit ist die offene Frage „Gehalt am Vertrag“ erledigt**; offen bleibt P44 (AU-Upload).

**Danach: 0.7.64 (F155), Auswahl „AU-Nachweis, Kunde und Projekt, Auswertung Leistungen“:** Auswertung der Leistungen
    (`leistungen` 0.2.0, Gliederung, Druck, CSV); Form des AU-Nachweises elektronisch/Papier (`personal` 0.13.0);
    **Entwürfe, nicht gebaut:** FAKTURA.md (P41, FK1–FK12) und Papier-AU nach Paperless „unter dem Nutzer“ (PERSONAL.md 10k,
    P44, AP1–AP6 – vor allem AP1 zwei Lesarten, AP2 der Paperless-Zugang hängt am Buch, das Personal am Mandanten).
    **Tag `v0.7.64`: der Commit, der mit „0.7.64:“ beginnt.** Dieser Chat ist sehr lang geworden – für den nächsten Bau
    (Paperless-Upload oder `projekte`) besser neu beginnen; alles Nötige steht hier und in den genannten Abschnitten.

**Danach: 0.7.65 (F156), „bau das mal um auf einen großen Katalog“:** `leistungen` 0.3.0 – Katalog als eigene Ansicht mit
    Tabelle, Suche und Doppelklick; Suchfeld beim Eintragen; drei Reiter auf der Seite „Leistungen“. Zu bestätigen LK1–LK4.
    Auf die Frage, wo Fixbeträge und Anteile stehen: in den Zuschlagsregeln der Seite „Lohnvorbereitung“, nicht im Katalog –
    **falls der Verantwortliche sie am Katalog will, wäre das ein Umbau (nicht beauftragt).**
    **Tag `v0.7.65`: der Commit, der mit „0.7.65:“ beginnt.**

**Danach: 0.7.66 (F157), Vorgabe am Dialog „Vertrag: neue Fassung“ – Vergütung als Raster:** `personal` 0.14.0. Die Vergütung steht
    nicht mehr an der Fassung (F153 aus dem zweiten Chat), sondern in `personal.entgelt` an der Person: Zeile je „gültig ab“ mit
    Eingruppierung, Leistungs- und Altersstufe (je mit Brutto), Urlaubs- und Weihnachtsgeld. Migration 0014 übernimmt den Bestand
    und entfernt `personal.verguetung`. PERSONAL.md 10m, **zu bestätigen VR1–VR8 – vor allem VR2 (Beträge werden addiert) und VR3
    (keine Entgelttabelle des Tarifvertrags; wäre der nächste Schritt).**
    **Tag `v0.7.66`: der Commit, der mit „0.7.66:“ beginnt.**

**Danach: 0.7.67 (F158), „hinterlässt ein nicht aktuelles System“:** `werkzeuge/update-neueste.sh` spielt auf Nachfrage die Pakete des
    Systems ein (vor kumero, `apt-get upgrade --with-new-pkgs`, meldet nötigen Neustart) und sagt, wenn im Zweig eine Version ohne
    Tag steht (Anlass: 0.7.66 war noch nicht getaggt, das Skript meldete „Fertig: 0.7.65“). Geprüft nur in einem Probeklon mit
    einer Attrappe für `apt-get` – **nicht auf der VM**. Zu bestätigen US1–US3 (AGENTS.md F158).
    **Tag `v0.7.67`: der Commit, der mit „0.7.67:“ beginnt.**

**Danach: 0.7.68 (F159), Entscheidung zu US2 „immer full-upgrade“:** `update-neueste.sh` ruft `apt-get -y dist-upgrade`. Geprüft wie
    0.7.67 nur mit Attrappe, nicht auf der VM. **Tag `v0.7.68`: der Commit, der mit „0.7.68:“ beginnt.**

**Danach: 0.7.69 (F160), „dann bau mal um“ nach dem PHP-Sicherheits-Release vom 2026-11-19:** Auf `aequa` (aus dem Netz nicht
    erreichbar, nichts ausgenutzt) lagen die Korrekturen am 21.11. abends noch offen. Befund aus den Protokollen der VM: Quellen
    und Timer stimmten, der Lauf um 06:17 hatte alte Paketlisten. Gebaut: Timer zweimal am Tag hintereinander, Härtung in
    `php-kumero.ini`, `fastcgi_keep_conn off` in den nginx-Vorlagen, **`install/nachziehen.sh`** (von `update-neueste.sh`
    aufgerufen; schreibt PHP-Datei und Timer, nginx nur prüfen), Prüfung der Quellen am Probelauf. Zu bestätigen SU1–SU3.
    **Geprüft nur mit Attrappen für systemctl, nginx, php-fpm und unattended-upgrade – nicht auf der VM.** Die VM läuft auf
    PostgreSQL 19.0 (am 2026-11-19 freigegeben) und PHP 8.5.13; **die Tests im Chat liefen weiter auf PostgreSQL 16 / PHP 8.3.**
    **Tag `v0.7.69`: der Commit, der mit „0.7.69:“ beginnt.**

## 0a. Verlauf der Übergaben (älteres zuerst unten)

**Stand:** kumero **0.7.46** ist eingecheckt (Zweig `release-0.7.0`); den Tag setzt und das Release baut der Verantwortliche.

- **0.7.35 – Auswertungen (F128, AUSWERTUNGEN.md Abschnitt 8):** neues Modul `auswertungen` – Summen- und Saldenliste, Journal, Kontoblätter und **eigene Auswertungen** aus Bausteinen (Vorgabe: „frei erstellen, extrem benutzerfreundlich“); Druckansicht und CSV; Kern-Abfrage `summen`. Auf der VM: `bin/kumero modul:aktivieren auswertungen spiel`. Geprüft: `Buchen` (Summen), `Auswertungen`, `Oberflaeche`, Browserlauf `auswertungen.mjs` mit Bildern. Nicht geprüft: Druck auf Papier, große Bücher. Zu bestätigen: E1–E8.
- **Entstanden am 2026-11-20 (Doku, kein Release): LAGER.md** (P33, WW1–WW24) – Entwurf einer Warenwirtschaft als Modul `lager`: Chargen, Seriennummern, Verfallsdaten, Entnahme mit Vorschlag, Rückverfolgung, Inventur; Positionen aus Sidecar und E-Rechnung als Vorschlag für den Wareneingang (Stufe 0 erweitert die Leser). Geprüft und verworfen: InvenTree, Part-DB, Dolibarr daneben, große ERP. Vorgabe: Funktionen nach Dolibarr abschauen, keinen Code übernehmen. Ein Mockup der Oberfläche liegt als Artefakt vor. **Nichts gebaut; Freigabe von WW1–WW24 steht aus.** Abschnitt 5 (Pflichten) ist Recherche und zu prüfen.
- **Entstanden am 2026-11-20 (Doku, kein Release): PERSONAL.md, ZEIT.md, LEISTUNGEN.md, DIENSTPLAN.md** (P34–P37). Vier Module: `personal`, `zeit`, `dienstplan` am **Mandanten**, `leistungen` am Buch. **Voraussetzung im Kern: Module, Rechte und Rollen am Mandanten – das gibt es noch nicht** (PERSONAL.md Abschnitt 2, Stufe 0) und ist der größte Posten. Grenzen: keine Lohnabrechnung, keine Diagnose, kein Empfänger an der Leistung, kein Abruf bei Krankenkassen, keine Ortung im Browser. **Entschieden am 2026-11-20 (Fassung 2):** Lohnmerkmale (Bank, Steuer, Sozialversicherung) führt kumero, nur für Personal und Lohn (PE9); der Ort einer Zeitbuchung ergibt sich aus dem Gerät (ZE12); Einzelaufzeichnung für Zeitzuschläge ist Rechtspflicht (PE11); Löschfrist je Objekt und Mitarbeiter, keine Vorgabe durch kumero (PE12, PERSONAL.md 8a); DATEV-Export zurückgestellt (PE14); nie die Konfession, nur das abstrakte Kirchensteuermerkmal. **Vertagt (P38, SCHLUESSEL.md):** Verschlüsselung alles Personenbezogenen mit Rollenschlüsseln samt Arbeitsablauf und Hierarchie, Postkorb je Person. Vorlagen der Funktionen: OrangeHRM, Frappe HR, Dolibarr, TimeTrex. **Nichts gebaut; Freigabe von PE, ZE, LE, DP steht aus.** Reihenfolge: `personal` 0–2 → `zeit` → `leistungen` → `personal` 3 → `dienstplan` → `personal` 4.
- **0.7.46 – Feiertage je Arbeitsort, Korrektur, Urlaubsregel (F139):** Migration `0003_feiertage_urlaubsregel.sql` (feiertag, arbeitsort, urlaubsregel; `personal.feiertage()`, `_arbeitstage` mit Feiertagen, `_urlaubskonto` rekursiv über die Vorjahre); Steuerdatei `module/personal/regelwerk/feiertage-de.json`; Dialoge in `abwesenheiten.js` (Arbeitstage, Urlaubsregel, Arbeitsorte, Feiertage). Tests: `PersonalTest` (435), `personal.mjs` (67). Rechtslage per Websuche geprüft (BUrlG §§ 4, 5, 7), nicht durch einen Juristen. Nicht geprüft: Update eines Mandanten mit Daten aus 0.7.45.
- **0.7.45 – Modul personal Stufe 2 (F138):** Migration `module/personal/migrationen/0002_abwesenheiten.sql` (abwesenheitsart, freier_tag, abwesenheit, au, urlaubskonto; Rechnen der Arbeitstage in `personal._arbeitstage`, Konto in `personal.urlaubskonto_stand`); `AbwesenheitController` (Sicht je Rolle in `liste()`), Seite `abwesenheiten.js`, Selfservice in `meine.js`, gemeinsame Felder in `gemeinsam.js` (`abwesenheitFelder`). Tests: `tests/PersonalTest.php` (309), `tests/browser/personal.mjs` (53, Bilder angesehen). **Zu bestätigen: AW1–AW18.** Nicht geprüft: erneutes Aktivieren auf einem Mandanten mit 0.1.0, viele Personen, Ausdruck.
- **0.7.44 – abgelaufene Sitzung:** `public/kern/ui/api.js` (`abgemeldetMelden`), `rahmen.js` (`sitzungAbgelaufen`). Geprüft im Browserlauf `personal.mjs`. Nicht geprüft: die übrigen Browserläufe; ein Ablauf mitten in einem mehrstufigen Vorgang (Datei einlesen, Stapelfreigabe).
- **0.7.43 – Modul personal Stufe 1 (F136) und Teiler (F137):** `module/personal/` am Mandanten (Schema `personal` in der Datenbank des Mandanten; Tabellen person, privat, vertrag, frist, fristart, vorschlag, einstellung; Funktionen mit `mandant.modul_protokoll`, im Protokoll nur IDs und Feldnamen). Sicht je Rolle steckt in `WebController::sicht()`: alle / Team (unmittelbar Unterstellte) / ich; private Angaben und Verträge je eigenes Recht. Seiten `meine.js`, `personen.js` (Liste mit `tabellenfilter`, darunter Akte, `teiler`), `fristen.js`. Neuer Baustein `public/kern/ui/teiler.js`. Tests: `tests/PersonalTest.php` (168), `tests/browser/personal.mjs` (33; `aufbau.php --personal`). **Zu bestätigen: S1–S16 (PERSONAL.md 10a).** Offen aus F137 (P39): Filter in allen Tabellenköpfen, Teiler im Lager, Menüleiste verschiebbar und ausblendbar. Offen (P40): Pflichtangaben für Arzneimittel im Lager – Feldliste liegt beim Verantwortlichen. **Als Nächstes:** P39 oder `personal` Stufe 2 (Abwesenheiten).
- **0.7.42 – Module und Rollen am Mandanten (F135):** `ebene: mandant` im Manifest; `db/mandant/0002_module_rollen.sql` (modul, recht, baustein, rolle, zuweisung, `modul_protokoll`, gleiche Signaturen wie `kern.*` – `Module::aktivieren()` tauscht nur das Schema); Kernel lädt auf Mandantenpfaden die Rechte aus `mandant.rechte_des_subjekts`, der Administrator bekommt keine Modulrechte; Routen der Mandantenmodule unter `/m/{mandant}/api/v1/<modul>`; `/m/{mandant}/api/v1/zuweisungen`; Menübereich `personal` (`Menue::AM_MANDANTEN`); `Mandantenauskunft` + `Dienste::auskunftAmMandanten()`. Test: `tests/MandantenmoduleTest.php` mit Prüfmodul `tests/fixtures/module/probe` (`Module::fuerTestsAnmelden`, verwirft die gemerkten Routen). **Noch kein Modul am Mandanten im Release.** Zu bestätigen: Z1–Z8 (PERSONAL.md 2a). Nicht geprüft: Rollen am Mandanten im Browser, Mandantenmodul mit Migrationen, Weg über aktualisieren.sh. **Als Nächstes:** `personal` Stufe 1 – vorher Entwurf der Tabellen nach Datenklassen (SCHLUESSEL.md Abschnitt 6), ohne Lohnmerkmale.
- **0.7.41 – Rollen des Lagers (F134):** Module können eigene Rollen mitbringen (`eigene_rollen` im Manifest); das Lager bringt „Lager“ und „Lagerleitung“, „Erfasser“ hat keine Lager-Rechte mehr, „Leser“ sieht das Lager, Inventur abschließen ist ein eigenes Recht. Auf der VM: `php /var/www/kumero/bin/kumero modul:aktivieren lager spiel`. Geprüft: `LagerTest` (auch ein Buch mit den alten Rechten), `Oberflaeche`, `Nutzerverwaltung`, `Anwendung`, Browserlauf `lager.mjs`. Nicht geprüft: die neuen Rollen im Dialog „Rolle zuweisen“ im Browser. **Merken: Ein Entwurf für ein neues Modul nennt seine Rollen.** `anlagen` und `auswertungen` hängen weiter nur an den Buchhaltungsrollen – dort ist das gewollt.
- **0.7.40 – Lager, Stufe 3 (F133):** Seite „Bestandsliste und Wert“ (Stichtag, Bewertung nach letztem Einstandspreis oder gleitendem Durchschnitt, Druck, CSV), Einstellungen des Buchs (Preise netto/brutto, Aufwand oder Bestand, Konten), Abstimmung gegen die Vorratskonten und Buchungsentwurf der Bestandsveränderung; Hinweis „Anlagegut?“ im Wareneingang. Geprüft: `LagerBewertungTest` (ohne Datenbank), `LagerTest`, Browserlauf `lager.mjs` (53, mit Entwurf, Festschreiben und Abstimmung). **Nicht geprüft und vom Verantwortlichen zu prüfen:** ob die Bewertungsverfahren und die Buchung der Bestandsveränderung steuerlich so gewollt sind (Y24–Y31), Rumpfgeschäftsjahre, ein Stichtag außerhalb jedes Geschäftsjahres wird nur gemeldet.
- **0.7.39 – Lager, Stufe 2 (F132):** Sollbestand, Seite „Nachbestellen“ (verfügbarer Bestand unter Mindestbestand, Vorschlag in Bestelleinheiten, Druck, CSV) und Seite „Inventur“ (Zählliste, sofort gespeichert, Abschluss mit Differenzbewegungen, verwerfen). Auf der VM nach dem Update einmal `bin/kumero modul:aktivieren lager spiel` (neues Recht). Geprüft: `LagerTest`, Browserlauf `lager.mjs`. Nicht geprüft: Inventur mit vielen Zeilen, Ausdruck der Zählliste auf Papier, ob das erneute Aktivieren auf der VM das neue Recht den Rollen gibt. Zu bestätigen: Y17–Y23.
- **0.7.38 – Lager, Stufe 1 (F131, LAGER.md):** neues Modul `lager` – Bereich „Lager“ mit „Bestand“ und „Wareneingang“; Artikel ohne/mit Charge/mit Seriennummer, Verfall, Entnahme mit Vorschlag, Sperre abgelaufener und gesperrter Chargen, Umlagern, Ausbuchen, Rückverfolgung, Wareneingang aus den Positionen eines Belegs mit gemerkter Zuordnung und Faktor, Scannen des Codes der Packung. Auf der VM: `bin/kumero modul:aktivieren lager spiel`. Geprüft: `LagerTest` (100), `Oberflaeche`, `Arbeitsvorrat`, Browserlauf `lager.mjs` (32, mit Bildern angesehen). **Nicht geprüft:** ein echter Scanner, echte E-Rechnungen eines Lieferanten, Ausdruck auf Papier, `aktualisieren.sh` mit dem neuen Modul, Verhalten bei vielen Artikeln (die Seite lädt alles auf einmal). **Nicht gebaut:** Nachbestellliste, Inventur, Bewertung. Zu bestätigen: Y6–Y16.
- **0.7.37 – Positionen des Belegs (F130, LAGER.md Stufe 0):** Die Leser für E-Rechnungen und den Sidecar liefern Positionen (`Kumero\Api\V1\Belegpositionen`), der Arbeitsvorrat bewahrt sie am Beitrag auf und zeigt „n Pos.“. Geprüft: `Sidecar`, `Import`, `Arbeitsvorrat`, `Paperless` (gezielte Läufe). Nicht geprüft: die Marke in der Liste im Browser; ein echter Sidecar mit Positionen – **nopaper schreibt nach seinem Stand (E13) keine**, das ist dort zu entscheiden. Zu bestätigen: Y1–Y5.
- **0.7.36 – Anlagenbuchhaltung (F129, ANLAGEN.md Abschnitt 10):** neues Modul `anlagen` – Seite „Anlagen“ im Bereich Buchführung, Einstellungen je Buch (Vorsteuerabzug, Ende der Absetzung, Anlagekonten), Vorschlag der Art und der Nutzungsdauer (AfA-Tabelle), AfA-Lauf als Entwürfe, Abgang, Abstimmung zum Journal. „Auswertungen“ ist in den Bereich Buchführung umgezogen (0.1.1). Auf der VM: `bin/kumero modul:aktivieren anlagen spiel` und `… auswertungen spiel`. Geprüft: `AnlagenRechner` (ohne Datenbank), `AnlagenTest`, `Auswertungen`, `Oberflaeche`, Browserläufe `anlagen.mjs` und `auswertungen.mjs`. **Nicht geprüft und vom Verantwortlichen zu prüfen: das Regelwerk** (`module/anlagen/regelwerk/afa-de.json` – Grenzen, Auszug der AfA-Tabelle; Branchentabelle Gesundheitswesen fehlt). Zu bestätigen: H1–H9.
- **Entstanden am 2026-11-20 (Doku):** **AUSWERTUNGEN.md** (U1–U14: Summen- und Saldenliste, Journal, Kontoblätter; Druck und CSV) und **ANLAGEN.md** (G1–G18: Anlagenbuchhaltung nach F61). Der Verantwortliche hat die Auswertungen als Nächstes genannt und die Anlagenbuchhaltung verlangt. Vorschlag zur Reihenfolge: erst Auswertungen Stufe 1, dann Anlagen. Die Steuerregeln in ANLAGEN.md (Grenzen, degressive AfA) sind der öffentlich beschriebene Stand 11/2026 und vom Verantwortlichen zu prüfen.
- **0.7.34 – Sidecar von nopaper aus Paperless (F127, BELEGEINGANG.md Abschnitt 9):** nopaper ist zustandslos geworden (nopaper 1.6–1.8, dort E61; Repository `QCG5OLF5ZU9L2W/nopaper`, öffentlich). Der Sidecar steht als Feld am Dokument in Paperless; das Modul `paperless` (0.5.0) liest ihn beim Abgleich, er geht den Erkannt-Feldern vor. **Das Modul `nopaper`, der Endpunkt für die Einlieferung und NOPAPER-AUFTRAG.md sind damit überholt.** Negative Beträge werden mit umgekehrter Richtung vorbelegt. Geprüft: `Sidecar` (neu, ohne Datenbank), `Paperless`, `Arbeitsvorrat`. **Nicht geprüft:** ein echter Sidecar aus einem echten Paperless – nopaper hat die Betriebsart selbst noch nicht erprobt. Zu bestätigen: R7–R11. Offen: E-Rechnungen aus dem PDF in Paperless lesen (P26 neu), Rückweg zum Lernen (R6).

- **Die VM `aequa` steht seit 2026-11-20 auf 0.7.31** – der Sprung 0.7.26 → 0.7.31 mit dem Überführen der Bücher ist dort gelaufen: Mandant `spiel`, Buch `haupt` als Testbuch, Buchungen und Arbeitsvorrat da, Passkey gilt weiter. `spiel` hatte danach **keinen Administrator** (niemand hatte die Rolle); der Verantwortliche wurde mit `mandant:administrator` eingesetzt. Seit 0.7.33 meldet das der Selbsttest. Der System-Verwalter hatte auf der VM noch keinen Passkey (`verwalter:liste`, `verwalter:link`).
- **0.7.33 – Selbsttest:** Er suchte die Ketten des Buchs noch in der Datenbank des Mandanten und meldete auf der VM nach dem Update auf 0.7.32 einen Fehler (Daten unberührt). Jetzt: Kette des Mandanten, Administrator vorhanden, Ketten je Buch. Geprüft: `Anwendung`. Die VM steht auf 0.7.32.
- **0.7.32 – zwei Fehler aus dem ersten Blick auf die VM:** Seiten der Verwaltung waren über die Adresse zu öffnen (Maske sichtbar, Server wies ab) – `Menue::bauen()` liefert sie jetzt nur noch dem, der den Menüeintrag sehen darf; die gesperrte Buchauswahl sieht nicht mehr bedienbar aus. Geprüft: `Oberflaeche`, `Nutzerverwaltung`, `Anwendung`; Browserlauf `nutzer.mjs`.

- **0.7.31 – nur `aktualisieren.sh`:** Das Update auf die Bücher geht direkt von jedem Stand ab 0.7.22 (0.7.30 verlangte 0.7.29). Anlass: Die VM `aequa` steht auf **0.7.26**; der Tag `v0.7.30` war schon gesetzt. Der Sprung 0.7.26 → 0.7.31 ist auf der VM noch nicht gelaufen.

- **0.7.30 – Mehrere Bücher je Mandant (F126, BUECHER.md Abschnitt 14):** Stufe B und C sind gebaut. Je Buch eine Datenbank `kumero_<mandant>__<buch>`, die Datenbank `kumero_<mandant>` gehört dem Mandanten (Schema `mandant`: Nutzer, Passkeys, Sitzungen, Administratoren, Bücher). Pfade `/m/{mandant}/api/v1/…` und `/m/{mandant}/b/{buch}/api/v1/…`; Oberfläche mit Auswahl des Buchs im Kopf, Bereich „Mandant“ (Bücher, Nutzer und Rollen). Administrator ist ein Amt im Mandanten. **Paperless-Zugang je Buch** (Entscheidung vom 2026-11-20). `openapi/v1.yaml` ist umgestellt.
  - **Update mit einmaligem Schritt:** Sicherung, dann überführt `aktualisieren.sh` selbst (`system:buecher-ueberfuehren`); mit 0.7.31 direkt ab 0.7.22. INSTALL.md Abschnitt 4. **Auf einer echten VM ist das noch nicht gelaufen** – geprüft ist es im Test am Bestand von 0.7.29 (`AnwendungTest::testBuecherUeberfuehren`): Passkeys gelten weiter, die Kette des Buchs ist unverändert.
  - **Geprüft:** SQL-Tests ganz; PHP-Tests `Anwendung`, `Nutzerverwaltung`, `Oberflaeche`, `Siegel`, `Buchen`, `Kontenrahmen`, `Arbeitsvorrat`, `Import`, `Paperless`; alle Browsertests (`nutzer` mit zweitem Buch und Wechsel, `siegel`, `buchen`, `import`, `einlesen`, `arbeitsvorrat`, `paperless`, `mandanten`). **Nicht geprüft:** `aktualisieren.sh` selbst (nur Syntax), der Dienst unter systemd, ein Lauf gegen ein echtes Paperless, PHP 8.5 / PostgreSQL 19.
  - **Nicht gebaut:** Kopie eines Buchs als Testbuch, Löschen und Export je Buch (Stufe D; O1, O2, O9 offen); Freigabe-Passkey im Mandanten (A9) – die Anwendung kann noch gar keine Freigabe-Passkeys einrichten, das ist der nächste fachliche Schritt vor dem Produktivbetrieb.
  - Die Browsertests waren seit 0.7.27/0.7.29 an zwei Stellen veraltet (Bereich „Buchführung“, Feld für den Code auf der Registrierungsseite); sie sind nachgezogen.

- **0.7.27 – Menü (F122):** Bereich „Buchführung“ statt „Buchen“, „Siegel“ steht dort; jeder Menüeintrag trägt ein Symbol, ein Manifest ohne Symbol wird abgewiesen. Geprüft: `Oberflaeche`, `Siegel`, `Import`; **im Browser hat die neuen Symbole niemand gesehen.**
- **0.7.28 – Siegel (F124):** „Siegel anfordern“ schreibt nichts mehr fest; es entsteht eine vorläufige Anforderung (höchstens eine, 24 Stunden, verwerfbar). Hochladen prüft nur; **erst „Siegel festschreiben“ schreibt Siegel, Signatur und Protokoll in einer Transaktion.** Kern-Migration 0014, Modul `siegel` 0.2.0. Geprüft: `Siegel`, SQL-Suite, Browserlauf `siegel.mjs`. Auf `spiel` bleibt das mit 0.7.26 angeforderte Siegel Nr. 1 als „nie signiert“ stehen; das nächste wird Nr. 2. **SIEGEL.md S11–S15 sind bestätigt (2026-11-20)**; offen bleiben S1–S10.
- **0.7.29 – Nutzer und Rollen in der Weboberfläche (F125, VERWALTUNG.md Abschnitt 15):** Seite „Nutzer und Rollen“ für den Administrator, Zugang mit **Link und Code**, der letzte Administrator und der letzte Verantwortliche bleiben (Datenbank). Kern-Migration 0015, Verwaltung-Migration 0005. Geprüft: `Nutzerverwaltung`, `Anwendung`, `Oberflaeche`, SQL-Suite, Browserlauf `nutzer.mjs`. **Auf Wunsch des Verantwortlichen vor den Büchern gebaut** – Rollen gelten heute je Mandant. Zu bestätigen: N1–N8. Nicht gebaut: Seite „Module“ (braucht Auftrag + Dienst und ein Deaktivieren im Kern), „Administrator einsetzen“ und „System-Verwalter“ in der Weboberfläche.
- **Mehrere Bücher je Mandant (F123, BUECHER.md):** B1–B9 freigegeben (B9: ein Siegel gilt für ein Buch), **gebaut mit 0.7.30 (oben)**. Je Buch eine Datenbank, Mandant als Klammer, Pfad `/m/{mandant}/b/{buch}/…`, Rollen je Buch, Testbücher, Übernahmen.
- **Bücher, Stufe A (freigegeben am 2026-11-20, gebaut mit 0.7.30):** BUECHER.md Abschnitte 8–13 (A1–A14) und `openapi/buecher-entwurf.yaml`. Kern: drei Ebenen im Pfad (Installation, Mandant, Buch); die Datenbank des Bestands wird in `kumero_<mandant>__haupt` umbenannt und ist das erste Buch; neue Mandanten-Datenbank mit Schema `mandant`; Administrator steht im Mandanten und wirkt in jedem Buch; Freigabe-Passkey einmal im Mandanten (Arbeitsannahme zu O3). `openapi/v1.yaml` wird erst mit dem Code umgestellt. **Nächster Schritt nach der Freigabe: Stufe B** in der Reihenfolge von BUECHER.md Abschnitt 13.
- **Reihenfolge:** Die Bücher sollten vor dem Rest der Benutzerverwaltung kommen (die Seiten hängen an „Rollen je Buch“); die Seite „Nutzer und Rollen“ hat der Verantwortliche am 2026-11-20 trotzdem vorgezogen (0.7.29) – sie wird mit den Büchern um die Auswahl des Buchs ergänzt. Nächster Schritt ist BUECHER.md Stufe A (Vertrag in `openapi/v1.yaml`, DATENMODELL.md, ANWENDUNG.md), danach Stufe B (Umbau).
- **Bestätigt am 2026-11-20 (vor Stufe B):** BUECHER.md A1–A14, vor allem A5 (Umbenennen der Datenbank, erstes Buch `haupt`), A9 (Freigabe-Passkey), A10 (Administrator im Mandanten), A12 („Mandant anlegen“ ohne Buch). O1 und O2 hat der Verantwortliche liegen gelassen; neu offen O8 (Paperless-Zugang für alle Bücher), O9 (Rollen in einer Übernahme).
- Nicht entschieden: ob der Bereich `abschluss` ganz aus dem Vertrag entfällt.
- **Testumgebung im Chat:** Der Wegwerf-Cluster von PostgreSQL überlebt das Ende einer Antwort nicht – vor jedem Lauf prüfen und neu starten. Browserläufe gehen (`PLAYWRIGHT=/opt/npm-tools/node_modules/playwright CHROME=/usr/bin/google-chrome`).
- **Testumgebung im Chat (Abhängigkeiten):** PHP 8.3 und PostgreSQL 16 genügen (Abschnitt 6); `composer install`, dann der dort genannte `composer update -W --with …`, danach `git checkout composer.lock`.

## 0a. Übergabe vom 2026-11-19 (abends)

**Stand:** kumero **0.7.26** ist gebaut, eingecheckt und als Tag `v0.7.26` (`be8caf7`) auf GitHub. Danach kamen nur noch
Doku und das Bauskript dazu (`INSTALL.md`, `werkzeuge/release-vom-tag.sh`); die Version ist unverändert.

**Heute entstanden:**
- 0.7.25 – Mandantenverwaltung des System-Verwalters (F118).
- 0.7.26 – **Siegel auf Anforderung** (F119, F120; SIEGEL.md): Modul `siegel`, Kern-Migration 0013. Signiert wird
  außerhalb, hochgeladen nur die Signatur; kein Signierdienst, kein Systemschlüssel.
- **INSTALL.md** – der ganze Weg vom Git-Stand zur neuen oder aktualisierten VM, mit Blöcken zum Einfügen.
- **Der Verantwortliche baut das Release jetzt selbst** (INSTALL.md Abschnitt 1) – aus dem Chat kommt kein Archiv mehr.

**Wo der Verantwortliche gerade steht (Host `aequa`, lesender Klon `/root/kumero-release`; Tags kommen von `sbs-server`):** Das Release 0.7.26 ist dort gebaut
(`dist/kumero-0.7.26.tar.gz`, `.sha256`). **Offen:** signieren (INSTALL.md 1.3), aktualisieren (Abschnitt 3), danach
`bin/kumero modul:aktivieren siegel spiel`. Welche Version auf der VM installiert ist, wurde im Chat nicht genannt –
bei einem Sprung über 0.7.25 gelten die einmaligen Schritte aus INSTALL.md Abschnitt 4 (Dienst für Aufträge, nginx).

**Testen – Vorgabe des Verantwortlichen vom 2026-11-19 (F121):** Es werden **nur Unittests** gemacht – die Tests des
Teils, an dem gebaut wird. **Die vollständige Integration wird erst getestet, wenn viel weiter entwickelt ist.** Bis
dahin also keine ganzen Läufe über alle Module, keine Browserläufe als Pflicht, keine Läufe gegen echte Fremdsysteme,
und auch kein Warten auf Tests auf der VM, bevor weitergebaut wird. Jede Abgabe nennt, was geprüft wurde und was nicht.

**Für den nächsten Chat zuerst (Stand von vor dem 2026-11-20 – das Aktuelle steht in Abschnitt 0):**
1. Diese Datei, AGENTS.md, CLAUDE.md; dann die Spezifikation des Teils, an dem gearbeitet wird.
2. Fragen, was als Nächstes dran ist. Die Bücher sind gebaut (0.7.30). Naheliegend: Freigabe-Passkeys einrichten
   (mit BUECHER.md A9), Stufe D der Bücher (Kopie als Testbuch – erst O1, O2, O9 entscheiden), der Rest der
   Benutzerverwaltung Stufe 2 (Seite „Module“ je Buch) oder die nächste Stufe des Siegels (SIEGEL.md Abschnitt 9).
3. Zu bestätigen, sobald der Verantwortliche dazu kommt: SIEGEL.md S1–S10, VERWALTUNG.md M1–M9.

## 1. Wo der Code liegt

| | |
|---|---|
| Repository | `https://github.com/QCG5OLF5ZU9L2W/kumero` (öffentlich, AGPL) |
| **Arbeitszweig** | **`release-0.7.0`** – alles ist eingecheckt und hochgeladen |
| `main` | wird vom Verantwortlichen von Hand nachgezogen (`git push origin origin/release-0.7.0:main`) und steht meist einige Commits zurück. Die Startseite von GitHub zeigt `main` – wer dort eine Datei vermisst, sieht im Zweig `release-0.7.0` nach. |
| Tags | setzt der Verantwortliche selbst (INSTALL.md Abschnitt 6); aus einer Claude-Sitzung werden Tag-Pushes abgelehnt (HTTP 403). Zweig-Pushes gehen (`git -c push.negotiate=true push origin release-0.7.0`). Alle Tags bis `v0.7.26` stehen auf GitHub. |
| Bauen | **Zwei Rechner (Klarstellung des Verantwortlichen vom 2026-11-20):** Tags setzt und pusht er auf `sbs-server` im Klon `~/kumero-release` (dort liegt der Schreibzugang zu GitHub). Auf der VM `aequa` zieht er das Repository nur lesend nach `/root/kumero-release`, baut dort mit `werkzeuge/release-vom-tag.sh`, signiert in `dist/` und installiert. Befehlsblöcke deshalb getrennt abgeben: Tag auf `sbs-server`, alles andere auf `aequa`. |

Im Arbeitsklon des Verantwortlichen auf `sbs-server` liegen uncommittete Dateien von nopaper (`nopaper.py`, `install.sh`, `lib/`,
`systemd/` …). Sie gehören nicht in kumero. Offen, was mit ihnen geschieht.

## 2. Wie eine Version abgegeben wird

1. Bauen, **nur Unittests des bearbeiteten Teils** (F103, F121), `VERSION`, `README.md` („Version …“), Modulversionen in `module/<name>/modul.json`, `CHANGELOG.md`, **`INSTALL.md` Abschnitt 4**, wenn die Version auf der VM einen einmaligen Schritt verlangt (neues Modul, Dienst, nginx).
   Jede Seite eines Moduls liegt unter `/module/<name>/<version>/…` – **ohne neue Modulversion lädt der Browser die alte Seite**.
2. Entscheidungen als neues F in AGENTS.md, offene Punkte als P; Festlegungen aus der Umsetzung ausdrücklich „zu bestätigen“.
3. Commit und Push des Arbeitszweigs. **Seit 0.7.26 baut der Verantwortliche das Release selbst** (INSTALL.md Abschnitt 1); aus dem Chat kommt nur der Commit mit seinem Hash für den Tag, kein Archiv mehr. Der Tag muss auf GitHub stehen, bevor gebaut wird (INSTALL.md Abschnitt 6).
4. Der Verantwortliche signiert und installiert auf der Test-VM (Host `aequa`, Mandant `spiel`; dort lesender Klon, kein Schreibzugang zu GitHub):
   ```
   gpg --armor --detach-sign /root/kumero-<v>.sha256
   bash /var/lib/kumero/releases/<zuletzt installierte Version>/install/aktualisieren.sh /root/kumero-<v>.tar.gz
   cd /var/www/kumero && bin/kumero modul:aktivieren <modul> spiel     # nur bei einem neuen Modul
   ```
   Migrationen bereits aktiver Module spielt `aktualisieren.sh` selbst ein. Den Pfad immer mit der **tatsächlich installierten** Version angeben – nicht jede Version wird installiert.
5. Die Abgabe nennt genau, **was geprüft wurde und was nicht** (F103).

## 2a. Release selbst bauen (Verantwortlicher, seit 0.7.26)

Die einmaligen Schritte und der Ablauf je Version stehen in **INSTALL.md** (Abschnitt 1 bauen und signieren, 2 neue VM, 3 aktualisieren, 4 einmalige Schritte je Version). Aus dem Chat kommt
nur der Commit mit seinem Hash für den Tag. Blöcke in der Anleitung sind **fertig zum Einfügen** zu halten – keine Platzhalter wie `<version>` in Befehlen (die Shell liest sie als Umleitung); wer nur installiert, darf nie nach Zugangsdaten gefragt werden.

### Besonderheit beim Update auf 0.7.22 (Nutzer wandern in den Mandanten)

```
pg_dump -U kumero_owner -Fc kumero_verwaltung > /root/kumero_verwaltung-vor-0.7.22.dump     # vorher, als root
bash /var/lib/kumero/releases/<installiert>/install/aktualisieren.sh /root/kumero-0.7.22.tar.gz
```
- `aktualisieren.sh` ruft nach den Migrationen `bin/kumero system:nutzer-ueberfuehren` auf. Der Befehl ist wiederholbar;
  seine Ausgabe nennt je Nutzer, wohin er überführt wurde und wer einen neuen Passkey braucht.
- **Auf der VM zu erwarten:** Der Verantwortliche ist heute Verwalter **und** hat Zugang zu `spiel`. Sein Passkey führt
  danach in den Mandanten `spiel` (dort fragt die Oberfläche einmal Vorname(n) und E-Mail ab). Für die Verwaltung der
  Installation braucht er einen zweiten Passkey: `bin/kumero verwalter:liste`, dann `bin/kumero verwalter:link <id>`.
- **Notausgang**, falls niemand mehr hineinkommt: `verwalter:link` (Verwaltung) bzw. `nutzer:liste` und
  `nutzer:link <mandant> <nutzer>` (Mandant). Beide brauchen nur die Kommandozeile.
- Zwei Passkeys im selben Passkey-Speicher: Der Browser fragt bei der Anmeldung, welcher gemeint ist; die Einträge heißen
  „<Name> – <Mandant>“ bzw. „<Name> – Verwaltung“.

## 3. Regeln aus der Zusammenarbeit, die über AGENTS.md hinaus zu beachten sind

- **Testen (F103, F121):** **Nur Unittests** – beim Bauen die Tests des bearbeiteten Teils, sonst nichts. **Die vollständige Integration wird erst getestet, wenn viel weiter entwickelt ist** (Vorgabe vom 2026-11-19); dann gemeinsam mit dem Verantwortlichen, mit eigenen Musterbelegen über die Kette nopaper → Paperless → kumero. Punkte unter „Wartet auf den Test auf der VM“ halten das Weiterbauen nicht auf.
- **Nur synthetische Daten.** Der Verantwortliche schickt Bildschirmfotos; was darin steht (Beträge, Namen, Belegnummern), kommt **nie** in Code, Tests oder Doku. Muster erzeugen die Werkzeuge in `werkzeuge/` (`e-rechnung-muster.php`, `kontenrahmen-muster*.py`).
- **Keine Tokens, Schlüssel, Registrierungslinks** im Chat – auch nicht für einen Testzugang.
- **Nichts verschiebt sich (F106):** Meldungen in der Statusleiste, Knöpfe sperren statt ausblenden, gesperrte nennen beim Überfahren den Grund, fester Listenkopf.
- **Aktionen gelten für die markierten Einträge**, ohne Haken für die gewählte Zeile (F100, F107).
- **kumero hält kein Dokument dauerhaft (F110).**
- **Module lesen und schreiben keine Tabellen des Kerns oder anderer Module** (F44) – nur Modul-API und REST.
- **Nutzer gehören zum Mandanten (F114).** Nichts über einen Nutzer eines Mandanten gehört in die Verwaltungsdatenbank – dort steht nur sein Pseudonym. Die ID `nutzer:<uuid>` verlässt den Mandanten nicht.
- Der Verantwortliche entscheidet kurz und erwartet danach das Bauen. Wo eine Vorgabe mehrere Lesarten hat oder etwas Größeres umgebaut würde, erst nachfragen – einmal, konkret, mit Vorschlag.

## 4. Was gebaut ist (0.7.x, Kurzfassung)

| Teil | Stand | Beschreibung |
|---|---|---|
| Buchen (Liste, Erfassung, Kontoblatt, Geschäftsvorfälle), Steuerkonten mit Satz, Split | gebaut | BUCHUNGSMASKE.md, F83, F85, F101 |
| Arbeitsvorrat: Quellen, Vorbelegung, Stapel, Spaltenfilter, „Doppelt?“, Vergleichen/Zusammenführen, Blättern, Verwerfen der Auswahl | gebaut | ARBEITSVORRAT.md, F89, F96, F100, F105, F107 |
| Paperless: Zugang je Nutzer, Tags, Quelle, Vorschau, Belegprüfung, Feldzuordnung, API-Versionen 9/10, **Belegablage** | gebaut | PAPERLESS.md, F92, F94, F95, F110 |
| Import: CAMT.053 und CAMT.054 (F116), CSV, **E-Rechnungen** (XRechnung CII/UBL, ZUGFeRD/Factur-X), mehrere Steuersätze, **Zwischenablage mit Vorschau, Übergabe an das DMS nach der Buchung – PDF und (mit Plugin im Paperless) XML** | gebaut | IMPORT.md, F104, F108, F109, F110, F112 |
| Kontenrahmen-Import, Konten ändern, Erweiterungspunkt der Kontenliste | gebaut | KONTENRAHMEN.md, F79–F81, F102 |
| **Benutzerverwaltung Stufe 1:** Nutzer, Passkeys, Sitzung, Einstellungen im Mandanten; Anmeldung über das Pseudonym; System-Verwalter als eigene Identität; Überführen des Bestands | gebaut (0.7.22), mit den Bibliotheken aus composer.lock geprüft | VERWALTUNG.md Abschnitt 11 und 13, F114, F115 |
| **Mandantenverwaltung des System-Verwalters:** Seite „Mandanten“ – anlegen mit erstem Administrator, stilllegen, exportieren (pg_dump auf dem Server), löschen; Aufträge und Dienst `kumero-auftraege` | gebaut (0.7.25); **systemd-Einheit auf der VM zu prüfen** | VERWALTUNG.md Abschnitt 14, F118 |
| **Nutzer und Rollen in der Weboberfläche:** Seite des Administrators – anlegen, Zugang mit Link und Code, Rollen, sperren, Passkey widerrufen; letzter Administrator und letzter Verantwortlicher bleiben | gebaut (0.7.29) | VERWALTUNG.md Abschnitt 15, F125 |
| **Siegel auf Anforderung:** Seite „Siegel“ – anfordern, Siegelinhalt herunterladen, außerhalb mit OpenPGP signieren, Signatur hochladen und prüfen; öffentliche Schlüssel im Mandanten | gebaut (0.7.26), erste Stufe; unter php-fpm ungeprüft | SIEGEL.md, F119, F120 |

## 5. Was als Nächstes ansteht

**Wartet auf den Test auf der VM (0.7.26, F120 – Seite „Siegel“):**
- Nach dem Update: `bin/kumero modul:aktivieren siegel spiel`. Als Verantwortlicher: Abschluss → Siegel.
- **Ablauf seit 0.7.28:** Öffentlichen Schlüssel anmelden (`gpg --armor --export ADRESSE > schluessel.asc`), Siegel anfordern, in der Karte „Offene Anforderung“ den Siegelinhalt herunterladen, `gpg --armor --detach-sign <datei>`, die `.asc` hochladen, dann **„Siegel festschreiben …“** und bestätigen. Zu sehen: erst danach steht das Siegel in der Liste, Zustand „signiert“, Signatur mit Kennung und Zeit.
- **Ungeprüft unter php-fpm:** Die Prüfung legt ein Wegwerfverzeichnis im temporären Verzeichnis an und ruft `gpg --show-keys` und `gpgv` auf. Meldet die Seite „Auf dem Server fehlt das Programm …“ oder einen internen Fehler, liegt es an der Einheit von php-fpm (PATH, temporäres Verzeichnis).
- **Kein Schlüssel, kein Passwort in den Chat** – auch kein Testschlüssel. Ein öffentlicher Schlüssel wäre unbedenklich, wird hier aber nicht gebraucht.
- Zu bestätigen: SIEGEL.md Abschnitt 10, S1–S10 – vor allem S1 (ein Siegel für beide Ketten), S3/S4 (wer siegeln darf), S5 (Schlüssel nur zurückziehen).
- Offen und nicht gebaut (SIEGEL.md Abschnitt 9): Signieren im Browser (erst Bibliothek prüfen: Lizenz, Größe, CSP), QES, Zeitstempel, Export außer Haus, Voraussetzungen des Produktivbetriebs (F59), Siegel bei der Jahressperre.

**Wartet auf den Test auf der VM (0.7.25, F118 – Seite „Mandanten“ des System-Verwalters):**
- Nach dem Update einmalig von Hand: Export-Verzeichnis, `kumero-auftraege.service` und `.timer` einrichten, nginx auf `client_max_body_size 350m` (Befehle im CHANGELOG 0.7.25).
- Als System-Verwalter: Verwaltung → Mandanten. Einen Testmandanten anlegen, den Link des Administrators verwenden, stilllegen, exportieren, löschen. **Nicht den Mandanten `spiel` löschen.**
- **Die systemd-Einheit ist ungeprüft.** Bleibt ein Auftrag auf „wartet auf den Dienst“: `systemctl status kumero-auftraege.timer`, `journalctl -u kumero-auftraege`; von Hand geht `bin/kumero system:auftraege`. Scheitert der Dienst an seinen Einschränkungen, in der Einheit `ProtectSystem`, `PrivateNetwork`, `RestrictAddressFamilies` auskommentieren und melden.
- Zu bestätigen: VERWALTUNG.md Abschnitt 14, M1–M9 – vor allem M2 (keine Zahlen aus dem Mandanten vor dem Löschen), M5 (die Sicherung bleibt nach dem Löschen liegen), M8 (Dienst läuft als root).

**Wartet auf den Test auf der VM (0.7.22, F115):**
- Update wie in Abschnitt 2 beschrieben; danach Anmeldung im Mandanten `spiel` mit dem vorhandenen Passkey, Abfrage der Angaben, Buchen wie bisher (Rollen, Freigabe-Passkey und Paperless-Zugang hängen an derselben ID und gelten weiter).
- Zweiter Passkey für die Verwaltung über `verwalter:link`; dort Kontenrahmen-Import. Mit dem Verwalter-Passkey kommt man in **keinen** Mandanten.
- Im Mandanten gilt: Wer nur Verwalter-Rechte hatte, sieht den Bereich „Verwaltung“ nicht mehr; „Kontenrahmen anwenden“ steht unter Stammdaten → Konten, für Rollen mit dem Recht `kontenrahmen.vorlage.anwenden`.

**Test auf der VM, 0.7.18/0.7.19:** Der Verantwortliche hat am 2026-11-18 gemeldet: „gebaut und getestet“. Einzelheiten (Übergabe an das echte Paperless 3.4.2, Vorschau des ZUGFeRD-PDF, Dauer des Abgleichs) wurden im Chat nicht genannt – bei Gelegenheit nachfragen, bevor „nur am Nachbau gelaufen“ aus den Spezifikationen gestrichen wird.

**Wartet auf den Test auf der VM (0.7.20, F112):**
- Im Paperless des Referenzbetriebs das Plugin `paperless-ngx-erechnung` einrichten, dann in kumero unter „Paperless – mein Zugang“ → „Belegablage für diesen Mandanten“ das Kreuz setzen.
- Eine synthetische XRechnung (`werkzeuge/e-rechnung-muster.php`) einlesen, buchen, zweimal „Jetzt abgleichen“. Zu sehen: Beleg an der Buchung nachgereicht, „Datei einlesen“ meldet 0 Originale, in Paperless liegt das XML als Original mit lesbarer Archivfassung.
- Hängt es: „Datei einlesen“ nennt, bei wie vielen Originalen die letzte Übergabe gescheitert ist; der Grund steht in `import.datei.abgelehnt_grund` (`paperless.xml_abgelehnt` = Paperless nimmt XML nicht an; sonst liegt es an der Suche über `original_checksum`).

**Entscheidungen, die der Verantwortliche noch treffen muss:**
- **VERWALTUNG.md Abschnitt 13, S1–S13** – Festlegungen aus der Umsetzung der Stufe 1. Vor allem: S1 (das Pseudonym entsteht mit der Identität, nicht erst beim Einrichten des Passkeys), S5 (neuer Befehl `nutzer:rolle`), S8 (nach dem Überführen bleibt in der Verwaltung ein gesperrter Eintrag „(überführt)“; **die unveränderliche Protokollkette der Verwaltung behält die alten Einträge mit Namen**), S13 (bis Stufe 2 pflegt die Kommandozeile Nutzer und Rollen).
- **IMPORT.md Abschnitt 16, D1–D12:** vor allem D1 (Beleg wird nach dem Festschreiben nachgereicht, steht nicht im versiegelten Kopf). **D5 ist entschieden (F112).** Neu zu bestätigen: D10–D12 (Einstellung je Mandant; kein Tausch XML ↔ PDF; Verhalten nach dem Ausschalten).
- **IMPORT.md Abschnitt 15:** Schnitt (Einlesen im Modul `import` statt eigenem Modul `e-rechnung`), Eingang/Ausgang wählt der Nutzer.
- **Zahlungsgrund im Buchungstext** bei Einträgen aus einer Bankzeile (Vorschlag: Gegenpartei + Zahlungsgrund).
- Festlegungen „zu bestätigen“ in F101, F105, F109, F112, F115 und in den Abschnitten „Festlegungen aus der Umsetzung“ der Spezifikationen.
- Erwartete Ergebnisse in TESTFAELLE.md (die Spalte füllt der Verantwortliche).

**Gemerkt, noch nicht bauen (Vorgabe des Verantwortlichen vom 2026-11-20):**
- **Erledigt mit 0.7.36:** „Auswertungen“ steht im Bereich „Buchführung“. Ursprüngliche Notiz: Umzusetzen mit der nächsten Version, die das Modul anfasst: `bereich` im Manifest auf `buchen`, Reihenfolge hinter „Geschäftsvorfälle“, neue Modulversion; Tests und Browserlauf (`AuswertungenTest`, `auswertungen.mjs`) nachziehen. Zu entscheiden wie beim Bereich `abschluss` (F122): ob der Bereich `auswertungen` ganz aus dem Vertrag entfällt – auch das Anlagenverzeichnis käme dann unter „Buchführung“.

**Gemerkt, noch nicht bauen (Vorgabe des Verantwortlichen vom 2026-11-18):**
- **Große Dateien und große Vorräte (F117, P28; 2026-11-19: „bauen müssen wir erstmal nicht“).** Zielwerte: 250.000 Umsätze und 1,5 GB je Datei, keine feste Grenze im Arbeitsvorrat. Gesetzt ist nur die Konfiguration, angepasst an die 350-MB-Grenze des Moduls (nginx-Vorlagen `client_max_body_size 350m` statt 32 MB – in 0.7.25; auf der VM von Hand nachzuziehen). Offen: Die 20.000 Umsätze je Datei greifen bei CAMT schon bei rund 100 MB (IMPORT.md G9). Die Grenzen des Moduls `import` (350 MB, 20.000) und des Arbeitsvorrats (5.000) bleiben, bis umgebaut ist → IMPORT.md Abschnitt 18 (G1–G8), ARBEITSVORRAT.md Abschnitt 14 (H1–H8). Die Jahresangaben aus dem Chat passten nach Auskunft des Verantwortlichen nicht – die Dokumente rechnen deshalb nur in Umsätzen und Bytes.
- **Buchungsliste, Spalte „Beleg-Nr.“: die Symbole nach vorne.** „Beleg öffnen“ (Pfeil) und Vorschau (Auge) stehen heute hinter der Belegnummer und damit je Zeile an anderer Stelle. Sie sollen vor der Belegnummer stehen, an festem Platz (passt zu F106). Beim Bauen prüfen, ob dieselbe Reihenfolge auch im Arbeitsvorrat gelten soll.

**Reihenfolge, entschieden vom Verantwortlichen am 2026-11-18:**
1. **Benutzerverwaltung** nach VERWALTUNG.md. **Stufe 1 ist gebaut (0.7.22), die Seite „Mandanten“ des System-Verwalters mit Anlegen, Stilllegen, Export und Löschen ebenfalls (0.7.25, F118).** Als Nächstes der Rest von **Stufe 2**: Zugang mit Link **und** sechsstelligem Code (Abschnitt 6; `kern.registrierung` hat `code_hash`, `fehlversuche`, `ungueltig_am` schon), Seiten „Nutzer und Rollen“, „Module“ (Mandant, Rolle Administrator) und „Mandanten“ (System-Verwalter, ohne Löschen), Administrator einsetzen. Dafür bekommt die Anwendung (`kumero_app`) die Rechte auf `verwaltung.pseudonym_eintragen` und `verwaltung.administrator_vermerken`, die heute nur die Kommandozeile hat. Erst der Vertrag in `openapi/v1.yaml` (Abschnitt 9), dann der Code.
2. **Siegel** – **erste Stufe gebaut (0.7.26, F120):** kein Signierdienst, kein Systemschlüssel; die Person signiert außerhalb und lädt die Signatur hoch. `install/schritte/70-signierdienst.sh` und `kumero-ssm.service` sind damit ohne Aufgabe; ob sie entfallen, ist nicht entschieden. Weiter nach SIEGEL.md Abschnitt 9.
- Dazu klargestellt: **Die EÜR ist nur Reporting**, und die **Jahressperre hindert das Buchen nicht** – beides hält den Echtbetrieb nicht auf.

**Sonst nicht gebaut, in etwa dieser Reihenfolge sinnvoll:**
0. **Zurückgestellt (Entscheidung des Verantwortlichen vom 2026-11-18): Modul `nopaper` und der Sidecar** (F89 W13, F111, P26). Nicht bauen und nicht beschreiben, bis der Verantwortliche es wieder aufruft. Die offenen Fragen zu P26 (Pfad, Aufbewahrung der XML, unvollständige Paare) bleiben bis dahin liegen.
1. Kleine Dinge für die nächste 0.7.x: Symbole der Buchungsliste nach vorne (oben „Gemerkt“); Abgleich ohne Fünf-Minuten-Frist, solange Originale als gesendet ausstehen (Vorschlag, noch nicht entschieden).
2. F106 auf den übrigen Seiten: „Datei einlesen“ (Schritte erscheinen dynamisch), Buchungsmaske (Splittabelle, einzelne Knöpfe), Formular „Neu“ der Kontenliste, „Paperless – mein Zugang“, Einrichtung.
3. Blättern (F107) und Spaltenfilter (F91) in den übrigen Listen (Buchungsliste, Konten, Kontoblatt, Geschäftsvorfälle, Regeln).
4. F93 Paperless-Zugang ins Nutzerprofil; Entsperren per Passkey (WebAuthn PRF); Livelog; Rückschreiben nach Paperless (F49).
5. Importer für Quicken / Finanzmanager (Splitbuchungen, Umbuchungen, QIF); X1b Regel aus der Vorauswahl; X2b Schalter brutto/aufteilen; X4 Hinweis über Belegnummer und Betrag; F90.
6. Danach 0.8.0: Zuordnung zur Anlage EÜR (P14: erst wenn das Buchen vollständig beschrieben ist).

## 6. Bekannte Stolpersteine

- **Drei Arten von Datenbanken (seit 0.7.30):** Verwaltung, Mandant (`kumero_<mandant>`, Schema `mandant`), Buch (`kumero_<mandant>__<buch>`, Schema `kern`). Testpfade der Buchhaltung heißen `/m/x/b/haupt/api/v1/…`. Auf der VM heißt das Buch des Mandanten `spiel` nach dem Update `haupt` und ist Testbuch; Befehle wie `modul:aktivieren siegel spiel` gelten weiter, solange es nur ein Buch gibt.
- **Zwei Datenbanken bei allem, was Nutzer betrifft (seit 0.7.22):** Die Identität liegt im Mandanten (seit 0.7.30 `mandant.nutzer` …), die Zuordnung Pseudonym → Mandant in der Verwaltung. Es gibt keine gemeinsame Transaktion – Reihenfolge: **erst die Zuordnung, dann die Identität**; scheitert das Zweite, wird die Zuordnung wieder entfernt. Globale Routen (`/api/v1/anmeldung`, `/ich`, `/registrierung`) laufen im Kernel in einer Transaktion der Verwaltung und schreiben im Mandanten selbst.
- **Sitzungs-Cookie** `__Host-kumero` hat die Form `<kennung>.<geheimnis>` (System-Verwalter: `.<geheimnis>`); `Identitaet::sitzungHash` ist weiter der SHA-256 des Geheimnisses (das Modul `paperless` hängt daran).
- **`mandant:anlegen` braucht drei weitere Angaben** (Vorname, Familienname, E-Mail des ersten Administrators). Der Administrator hat keine Rechte auf Buchungsdaten (F43); wer bucht, wird mit `nutzer:anlegen <mandant> … --rolle Verantwortlicher` angelegt.
- **Tests:** gemeinsame Hilfen in `tests/Hilfe/Zugaenge.php`. Der Test-Authentifikator braucht `nutzerkennung` (das Pseudonym aus `user.id`), sonst fehlt der Anmeldung `userHandle`. Im Browser trägt der virtuelle Authentifikator immer nur einen Passkey (`WebAuthn.clearCredentials` vor dem zweiten), sonst fragt Chrome nach einer Auswahl.
- **Browser-Zwischenspeicher:** Dateien unter `/kern/` haben keine Version im Pfad. Seit 0.7.19 setzen die nginx-Vorlagen `expires -1`; auf der VM ist das von Hand eingetragen. Zeigt der Browser nach einem Update „doesn't provide an export named …“, einmal die Websitedaten löschen. **0.7.22 ändert `/kern/ui/rahmen.js`, `start.js`, `profil.js`.**
- **Paperless nimmt XML nur mit Plugin an** (F112). Ohne die Einstellung des Mandanten lagert kumero kein XML zwischen. Der Nachbau lehnt XML ab, solange im Zustand `plugin_e_rechnung` fehlt.
- **Lokale Tests mit PHP 8.3:** `composer.lock` legt Symfony 8.1 fest (braucht PHP ≥ 8.4). Für die Tests einmal `composer update -W --with symfony/console:^7.4 --with symfony/http-foundation:^7.4 --with symfony/routing:^7.4` und danach `git checkout composer.lock` – die Lock-Datei bleibt, wie sie ist. Rollen der Testdatenbank: `kumero_owner` (CREATEDB), `kumero_app`, `kumero_lesen`.
- **Paperless 3.4.2** nennt die **SHA-256** des Originals (nicht MD5) und spricht API-Version 10; `/api/tasks/` hat dort eine andere Gestalt und wird von kumero nicht genutzt.
- **Strenge CSP:** keine `style`-Attribute, kein Inline-Skript; `element.style.x = …` über das CSSOM geht.
- Neue Module brauchen Autoload-Einträge in `composer.json` und danach `composer dump-autoload -o`.
- **Rechte eines Moduls sind dreiteilig** (`modul.gegenstand.handlung`), sonst weist `kern.recht` sie ab.
- **`Umgebung::psql()` liefert die Ausgabe mit Kopfzeile** – für einzelne Werte in Tests `psql -At` nehmen (siehe `SiegelTest::sql`).
- **Tests mit gpg** erzeugen Schlüssel in einem Wegwerfverzeichnis und beenden den Agenten danach (`gpgconf --homedir … --kill all`).
- Ein Manifest darf zusätzliche Schlüssel tragen (`arbeitsvorrat_quelle`, `oberflaeche_erweiterungen`); `erweiterungspunkte` bleibt leer.
- Entwicklung und Tests laufen ohne PHP 8.5 / PostgreSQL 19 auch mit PHP 8.3 und PostgreSQL 16 (F84 Nr. 2); das Release enthält Symfony 8.1 für PHP 8.5.

## 7. Tests lokal (Kurzfassung, Einzelheiten in CLAUDE.md)

```bash
KUMERO_TEST_PGHOST=/tmp/pgtest KUMERO_TEST_PGUSER=postgres php tests/lauf.php Import      # gezielt, mit Filter
KUMERO_TEST_PGHOST=/tmp/pgtest KUMERO_TEST_PGUSER=postgres php tests/lauf.php Anwendung   # Anmeldung, zwei Mandanten, Verwalter, Überführen
bash db/tests/ausfuehren.sh                                                               # SQL-Tests, wenn der Kern berührt ist
PLAYWRIGHT=… CHROME=… node tests/browser/einlesen.mjs [Bildverzeichnis]                   # Browserläufe je Seite:
#   import.mjs (Kontenrahmen, zwei Identitäten), buchen.mjs, arbeitsvorrat.mjs, paperless.mjs, einlesen.mjs, mandanten.mjs, siegel.mjs, nutzer.mjs
```

Der nachgebaute Paperless-Server für Tests ist `tests/Hilfe/paperless_nachbau.php`.
