# Änderungen

Format nach [Keep a Changelog](https://keepachangelog.com/de/), Versionen nach [SemVer](https://semver.org/lang/de/).
Jede Version wird erst installiert, wenn der Projektverantwortliche das Release signiert hat (F73).

## [0.7.54] – 2026-11-21

### Neu – Spalten sind verschiebbar (F146, OBERFLAECHE.md T8–T11)
Generelle Anforderung des Verantwortlichen: In jeder Liste lassen sich **Breite und Reihenfolge** der Spalten ändern.
- **Breite:** die Grenze am rechten Rand des Spaltenkopfs ziehen. Die anderen Spalten bleiben, wie sie sind; die Tabelle rollt bei Bedarf quer. Doppelklick auf die Grenze: Breiten wieder nach Inhalt.
- **Reihenfolge:** den Titel der Spalte an eine andere Stelle ziehen – auch Spalten mit Eingabefeldern.
- **Tastatur** am Titel: Strg+←/→ verschieben, Umschalt+←/→ Breite (mit Strg größer), Umschalt+Pos1/Ende kleinste und größte Breite.
- **Gemerkt je Browser und Tabelle;** „Spalten nach Vorgabe“ steht in jedem Filtermenü. Ein Update setzt die Wahl nicht zurück.
- Baustein: `tabellenfilter(spalten, beiAenderung, {id})` mit `spalten()`; ohne `{id}` verhält er sich wie bisher – die Listen der anderen Module sind unverändert.

### Geändert – Modul `lager` 0.4.2 vollständig nach F91, F106, F137 und F146
- **Wareneingang, Nachbestellen, Inventur, Bestandsliste:** Sortierung und Filter an den Spaltenköpfen; alle Spalten verschiebbar. „Bestand“ (Artikel und Chargen) ebenso verschiebbar.
- **Wareneingang:** Filter an den Spalten des Belegs; Eingaben bleiben beim Filtern, Sortieren und Verschieben erhalten. Geprüft, gescannt und gebucht wird weiter über alle Positionen.
- **Inventur:** Eine gezählte Zeile bleibt stehen, auch wenn sie nicht mehr zum Filter passt. Verdeckt ein Filter die gescannte Charge, sagt die Seite das.
- **Bestandsliste:** „Gesamt“ bleibt der Wert des ganzen Lagers; ist gefiltert, steht darüber „Gezeigt“ mit der Summe der sichtbaren Artikel.
- **Bestand:** Die Werkzeugleiste hat zwei feste Zeilen und bricht nicht mehr um – oben die Bewegungen und „Drucken“, über der Liste Suche, Artikel, Lagerorte, Einstellungen.
- CSV-Dateien enthalten wie bisher alle Zeilen in der Reihenfolge der Vorgabe; gedruckt wird, was zu sehen ist.

### Hinweis
- Keine Migration, kein Handgriff. Geprüft: alle fünf Seiten des Lagers im Browser (Chrome) mit nachgestellten Daten, 59 Prüfungen. **Nicht gelaufen:** `tests/browser/lager.mjs` gegen die echte Anwendung (um vier Prüfungen ergänzt) und alle übrigen Läufe; Firefox und Bedienung mit dem Finger sind nicht geprüft.
- Offen (P39): verschiebbare Spalten und fehlende Filter in den Listen der anderen Module – auch im neuen Modul `zeit`. Zu bestätigen: O4–O9 (OBERFLAECHE.md).

## [0.7.53] – 2026-11-21

### Neu
- **Modul `zeit` 0.1.0 – Zeiterfassung (F145, ZEIT.md Abschnitt 9):** eigenes Modul am Mandanten mit eigener Rolle **„Zeiterfassung“**; „Meine Zeiten“ steht im Menü unter Personal → Meine Daten. Kommen und Gehen minutengenau, Nachtrag und Korrektur mit Begründung (je Person sofort oder mit Freigabe des Vorgesetzten; der alte Wert wird nicht aufbewahrt, die Begründung bleibt), feste Arbeitszeit oder **Gleitzeit** mit Rahmen, Kernzeit und Zeitkonto, **Pausenabzug nach der Regel** (über 6 Std. 30 Min., über 9 Std. 45 Min.), **über 10 Stunden zählt nichts ohne Sonderfreigabe**, Zeiten **außerhalb der Regelarbeitstage zählen erst mit Genehmigung**, Monatsabschluss nach Einstellung (aus, Person bestätigt, mit Freigabe). Seite „Zeiten“ für Vorgesetzte, Personal und Lohn.
- **Modul `personal` 0.6.0:** **Jahreskalender je Person** unter „Meine Daten“ mit Feiertagen des Arbeitsorts, freien Tagen des Betriebs, Brückentagen und den eigenen Abwesenheiten; die Lohnvorbereitung zeigt **Ist- und Überstunden** und die Stunden in den Zeitfenstern, sobald `zeit` eingeschaltet ist (Beträge der Zeitzuschläge bleiben offen, bis es den Grundlohn gibt).

### Geändert
- `modul:aktivieren` am Mandanten: Ein Modul lässt sich erst einschalten, wenn das, worauf es aufbaut, dort aktiv ist („Modul zeit baut auf personal auf – erst personal einschalten“). Im Buch bleibt der Befehl, wie er war.

### Nicht gebaut
- Terminal und RFID-Leser (ZEIT.md Stufe 3), Schichtplan als Quelle für Arbeitstage (`dienstplan`), Ruhezeit zwischen zwei Tagen, Beträge der Zeitzuschläge.

## [0.7.52] – 2026-11-21

### Geändert – Oberfläche nachgezogen (F137, OBERFLAECHE.md T4–T7; Modul `lager` 0.4.1)
Anlass: Bild der Seite „Bestand“ vom Verantwortlichen – dort fehlten die Filter an den Spalten und die Verschiebbarkeit.
- **Lager „Bestand“: Sortierung und Filter an jedem Spaltenkopf** (`tabellenfilter.js`, F91) – in der Artikelliste und in der Tabelle der Chargen. Der Fuß der Liste nennt „gefiltert“; passt nichts, steht das in der Tabelle.
- **Lager „Bestand“: Teiler zwischen Artikelliste und Chargen** (`teiler.js`) – Maus, Finger, Tastatur; Doppelklick stellt die Vorgabe her; die Höhe merkt sich der Browser.
- **Menüleiste in der Breite verschiebbar (T5):** Griff am rechten Rand, mit denselben Tasten wie der Teiler (Pfeile, mit Umschalt größer; Pos1/Ende; Doppelklick: Vorgabe).
- **Menüleiste ausblendbar (T6):** Knopf „☰“ links im Kopf, an festem Platz; ausgeblendet bleibt ein schmaler Rand mit einem Knopf zum Einblenden. Breite und Zustand merkt sich der Browser.
- **Gewählt ist nur, was zu sehen ist:** Verdeckt ein Filter die gewählte Charge, wählt die Seite die vorgeschlagene unter den sichtbaren; ist keine zu sehen, sind Entnahme, Umlagern, Ausbuchen und Sperren gesperrt und nennen den Grund.
- **Die Filter der Chargen beginnen mit jedem Artikel neu** – ein Filter von vorhin verdeckt nie den Bestand eines anderen Artikels (Festlegung aus der Umsetzung, zu bestätigen).

### Hinweis
- Keine Migration, kein Handgriff. Geprüft: beide Seiten im Browser mit nachgestellten Daten (28 Prüfungen: sortieren, filtern, Teiler, Menüleiste, Merken, Druck). **Nicht gelaufen:** `tests/browser/lager.mjs` gegen die echte Anwendung (ergänzt, aber hier ohne PostgreSQL 19 nicht ausführbar) und alle übrigen Läufe.
- Weiter offen aus F137 (P39): Filter in den Tabellenköpfen von Wareneingang, Nachbestellen, Inventur, Bestandsliste, Konten, Nutzer und Rollen, Bücher, Anlagen, Auswertungen, Siegel.

## [0.7.51] – 2026-11-21

### Neu
- **`werkzeuge/update-neueste.sh` (F144):** ein Lauf auf der VM für das ganze Update – Stand holen, höchste Version bauen, Signatur (fragt, signiert nie von selbst), Sicherung aller Datenbanken (fragt, Vorgabe ja), `aktualisieren.sh`, Module nachziehen, und am Ende die Frage, ob überall die Module eingeschaltet werden sollen, die noch nie eingeschaltet waren. INSTALL.md Abschnitt 3a.
- **`bin/kumero modul:alle [mandant]`:** schaltet in jedem Buch und am Mandanten ein, was dort noch nie eingeschaltet war, in der Reihenfolge der Abhängigkeiten. Was jemand ausgeschaltet hat, bleibt aus.

### Berichtigt
- Hinweis zu 0.7.49 und 0.7.50: `aktualisieren.sh` übergibt an das Skript der neuen Version und zieht die aktiven Module damit schon beim Update auf 0.7.49 selbst nach; ein `modul:nachziehen` von Hand ist nur eine Absicherung.

## [0.7.50] – 2026-11-21

### Geändert
- **Was der Administrator einer Rolle genommen hat, kommt nicht zurück (F143).** Bisher gaben jedes Einschalten eines Moduls und – seit 0.7.49 – jedes Nachziehen nach einem Update den Rollen alle Bausteine aus dem Manifest zurück, auch bewusst entfernte. Jetzt gibt ein Modul jedes Paar aus Rolle und Baustein genau einmal und merkt es sich. Was eine neue Fassung einer Rolle erstmals gibt, bekommt sie weiterhin.

### Hinweis
- **Erster Lauf im Bestand:** Für Module, die schon aktiv sind, gilt ein Baustein, den es vor dem Update schon gab, als früher gegeben und wird nicht nachgereicht; gegeben werden nur Bausteine, die mit der neuen Fassung entstehen. Wer eine Rolle bewusst enger geschnitten hat, behält das.
- Migrationen: Kern 0018 (je Buch), Mandant 0004.

## [0.7.49] – 2026-11-20

### Neu
- **Seite „Module“ im Bereich „Mandant“ (F142):** Der Administrator sieht, welches Modul am Mandanten und in welchem Buch aktiv ist, schaltet **ein** und **aus** und zieht mit „Alle nachziehen“ alle aktiven Module auf den Stand des Releases. Jeder Schritt läuft als Auftrag über den Dienst auf dem Server.
- **Ausschalten:** Menü, Seiten und Schnittstelle des Moduls sind weg; Daten, Rechte, Rollen und Zuweisungen bleiben. Einschalten bringt alles zurück.
- **Nach einem Update zieht `aktualisieren.sh` die aktiven Module von selbst nach** – der Handgriff `modul:aktivieren` je Modul entfällt.
- Kommandozeile: `modul:nachziehen [mandant]`, `modul:ausschalten <modul> <mandant> [buch]`.

### Hinweis
- Zur Sicherheit nach dem Update einmal `php /var/www/kumero/bin/kumero modul:nachziehen` – oder in der Oberfläche unter Mandant → Module „Alle nachziehen“ (berichtigt mit 0.7.51: `aktualisieren.sh` übergibt an das Skript der neuen Version und zieht selbst nach).
- Migrationen: Kern 0017 (je Buch), Mandant 0003, Verwaltung 0007.

## [0.7.48] – 2026-11-20

### Neu
- **Personal 0.5.0: Beurteilungen und Zielvereinbarungen (F141, PERSONAL.md Stufe 4).** Vorlagen mit Kriterien und Skala; Beurteilung je Person und Zeitraum mit Selbsteinschätzung, Einschätzung des Beurteilers, Gespräch mit Datum, Ergebnis und Kenntnisnahme mit Stellungnahme.
- **Ziele:** vorgeschlagen → von der Person bestätigt (vereinbart) → Stand in Prozent → bewertet oder aufgehoben.
- **Seite „Beurteilungen und Ziele“** für Vorgesetzte und Personal; unter **„Meine Daten“** die eigenen Beurteilungen und Ziele.
- Die Einschätzung des anderen ist erst zu lesen, wenn die eigene abgegeben ist. Die Rolle Lohn sieht keine Beurteilung.
- Rechte `personal.beurteilung.eigene`, `.team`, `.sehen`, `.pflegen`; die Rollen Mitarbeiter, Vorgesetzter und Personal bekommen sie.

### Hinweis
- Je Mandant einmal `bin/kumero modul:aktivieren personal <mandant>`. Danach legt das Personal unter „Beurteilungen und Ziele“ → „Vorlagen …“ die erste Vorlage an.
- Mitbestimmung bei Beurteilungsgrundsätzen ist nicht betrachtet (PERSONAL.md 10e). Weiter nur mit erfundenen Personen (P38).

## [0.7.47] – 2026-11-20

### Neu
- **Personal 0.4.0: Lohnvorbereitung (F140, PERSONAL.md Stufe 3).** Neue Seite „Lohnvorbereitung“: je Monat die beschäftigten Personen; darunter die Unterlage für das Lohnbüro – Vertrag, Ein- und Austritt, Sollstunden, Abwesenheiten mit Summen je Art, Zuschläge, Vertragsänderungen, Bemerkung. Ablauf **offen → geprüft → übergeben**; nach der Übergabe eine weitere Fassung mit Vermerk.
- **Druckansicht:** „Drucken“ für eine Person, „Sammelliste drucken“ für alle, je Person eine Seite; als PDF über den Druckdialog.
- **Zuschlagsregeln** mit Fassungen („gültig ab“) nach Leistung (Anteil, fester Betrag, Staffel) oder Zeit (Fenster mit Satz), zugeordnet an Personen oder Funktionen.
- **Zuschlag von Hand** mit Betrag und Begründung, auch negativ als Berichtigung.
- **Meine Daten:** die eigenen übergebenen Lohnvorbereitungen.
- Rechte `personal.lohn.sehen`, `personal.lohn.pruefen`, `personal.lohn.uebergeben`, `personal.zuschlag.pflegen`; die Rollen Personal und Lohn bekommen sie.

### Hinweis
- **Die Quellen fehlen noch:** Ohne die Module `leistungen` und `zeit` rechnet kumero keinen Zuschlag aus einer Regel – in der Unterlage steht „Nachweis fehlt“ statt eines Betrags, ebenso bei Ist- und Überstunden. Die Anschlussstelle ist die Funktion `personal._zuschlag_nachweis()`.
- Je Mandant einmal `bin/kumero modul:aktivieren personal <mandant>`.
- Weiter nur mit erfundenen Personen: Beträge der Zuschläge von Hand liegen wie alle Personaldaten unverschlüsselt in der Datenbank (P38).

## [0.7.46] – 2026-11-20

### Neu
- **Personal 0.3.0: Feiertage je Arbeitsort (F139).** Regelwerk der gesetzlichen Feiertage als Steuerdatei (`module/personal/regelwerk/feiertage-de.json`: neun bundeseinheitliche und die der Länder, bewegliche aus dem Osterdatum). Unter „Abwesenheiten“ → „Feiertage …“ passt der Mandant sein Regelwerk an (ändern, eigene anlegen, entfernen) und liest die Vorgabe wieder ein.
- **Arbeitsorte:** Bundesland plus frei zu- und abwählbare Feiertage (z. B. Augsburg mit Friedensfest). Jede Person hat ihren Arbeitsort; sonst gilt der des Mandanten, sonst die bundeseinheitlichen Feiertage. Der Kalender zeigt die Feiertage je Person.
- **Arbeitstage berichtigen:** neu rechnen oder von Hand setzen („Arbeitstage …“).
- **Urlaubsregel des Mandanten** („Urlaubsregel …“), je „gilt ab Jahr“: Teilurlaub nach § 5 BUrlG (oder vertraglich immer Zwölftel, oder aus), Rundung, Übertrag des Rests mit wahlweiser Höchstzahl, Verfall zum Stichtag – bestätigt durch das Personal, von selbst oder nie. Am Urlaubskonto lassen sich Anspruch, Übertrag und Verfallenes je Person festsetzen.
- **Hinweis auf drohenden Verfall** unter „Meine Daten“ und bei den Urlaubskonten.

### Geändert
- **Ab dem laufenden Jahr gilt die Vorgabe der Urlaubsregel:** Teilurlaub nach dem Gesetz, der Rest geht ins Folgejahr und steht dort am 31.03. zum Verfall an (nach Bestätigung). Wer das nicht will, stellt die Regel um.
- Arbeitstage zählen keine Feiertage mehr mit. Schon eingetragene Abwesenheiten behalten ihre Tage, bis sie berichtigt werden.
- Die Seite „Abwesenheiten“ hat zwei feste Leisten: oben der gewählte Eintrag, darunter die Einrichtung.

### Hinweis
- Je Mandant einmal `bin/kumero modul:aktivieren personal <mandant>`. Danach: Arbeitsort des Mandanten anlegen („Arbeitsorte …“).
- Das Regelwerk der Feiertage und die Abbildung von §§ 4, 5, 7 BUrlG sind zu prüfen (PERSONAL.md 10c). Keine Rechtsberatung.

## [0.7.45] – 2026-11-20

### Neu
- **Modul `personal` 0.2.0: Abwesenheiten (F138, PERSONAL.md Stufe 2).** Urlaub, Arbeitsunfähigkeit, Freistellungen, Sonderurlaub, Fortbildung, Mutterschutz, Elternzeit und eigene Arten. Antrag → Genehmigung oder Ablehnung mit Grund; Rücknahme; „Ende ändern“. Nichts wird gelöscht.
- **Selfservice unter „Meine Daten“:** Urlaubskonto, eigene Abwesenheiten, „Abwesenheit beantragen …“, „Krank melden …“, Zurücknehmen.
- **Seite „Abwesenheiten“** für Vorgesetzte, Personal und Lohn: Liste des Jahres mit Sortierung und Filter, darunter (verschiebbarer Teiler) der Kalender „wer fehlt wann“ und die Urlaubskonten; Zähler im Menü für Anträge, die man entscheiden kann.
- **Arbeitsunfähigkeit ohne Diagnose:** geführt werden Beginn, voraussichtliches Ende, Tag der ärztlichen Feststellung, Erst- oder Folgebescheinigung, Arbeitsunfall und „Nachweis liegt vor“ – kein Feld für eine Diagnose, keine Bemerkung. Der Vorgesetzte sieht eine AU nur als „abwesend“.
- **Urlaubskonto je Jahr:** Anspruch aus dem Vertrag oder festgesetzt, Übertrag, genommen, genehmigt, beantragt, Rest. Arbeitstage aus dem Vertrag der Person, ohne die **freien Tage des Betriebs**, die das Personal pflegt.
- Neue Rechte `personal.eigenes.beantragen`, `personal.abwesenheit.sehen`, `.genehmigen`, `.pflegen`, `personal.au.sehen`; die Rollen Mitarbeiter, Vorgesetzter, Personal und Lohn bekommen sie über neue Bausteine.

### Hinweis
- **Je Mandant einmal `bin/kumero modul:aktivieren personal <mandant>`** – sonst fehlen den Rollen die neuen Rechte.
- kumero gibt keine Feiertage vor, zwölftelt keinen Anspruch und überträgt oder verfällt nichts von selbst. Benachrichtigungen gibt es nicht (Postkorb vertagt).
- Weiter nur mit erfundenen Personen nutzen, bis die Verschlüsselung steht (SCHLUESSEL.md).

## [0.7.44] – 2026-11-20

### Behoben
- **Abgelaufene Sitzung: Es gab nichts mehr zu sehen.** Nach 30 Minuten ohne Aufruf (spätestens nach 12 Stunden) antwortet der Server mit „Bitte anmelden“. Der Rahmen blieb dann stehen, die Seiten waren leer, und nirgends ließ sich neu anmelden – nur das Neuladen der Seite half. Jetzt fängt der Rahmen das an einer Stelle ab (`kern/ui/api.js`, `rahmen.js`): Über der Seite liegt „Bitte neu anmelden“ mit dem Passkey. Danach geht es **an derselben Stelle** weiter – hat der Nutzer auf der Seite schon etwas eingegeben, bleibt sie mit den Eingaben stehen (der letzte Schritt ist zu wiederholen), sonst wird sie neu geladen. Meldet sich jemand anderes an (anderer Passkey), beginnt die Oberfläche von vorn.
- **Personal: Der Zähler am Menüpunkt „Fristen und Nachweise“ erschien nie** – der Endpunkt nannte `zahl`, der Rahmen liest `anzahl`.
- **Personal, „Meine Daten“ ohne verknüpfte Person:** Wer selbst das Personal führt, liest jetzt, wo er sich verknüpft („Personen“ → Zugang zu kumero), statt „das richtet das Personal ein“.

### Hinweis
- Geändert sind Dateien unter `/kern/ui/` (ohne Version im Pfad). Zeigt der Browser danach einen Fehler „doesn't provide an export named abgemeldetMelden“, einmal die Websitedaten löschen.

## [0.7.43] – 2026-11-20

### Neu
- **Modul `personal` 0.1.0 am Mandanten (F136, PERSONAL.md Stufe 1):** Personen, private Angaben, Verträge in Fassungen (ohne Vergütung), Fristen und Nachweise mit Warnfrist und Zähler, Übersicht der Akte, Selfservice „Meine Daten“ mit Änderungsvorschlag, Löschfrist je Objekt. Rollen Mitarbeiter, Vorgesetzter, Personal und Lohn; der Administrator sieht nichts davon.
- **Datenklassen:** Stamm, Privat, Vertrag und Fristen liegen getrennt und haben je ein eigenes Recht. Das Protokoll nennt IDs und Namen geänderter Felder, nie einen Wert. Das Lesekonto der Datenbank sieht das Schema nicht.
- **Verschiebbarer Teiler (F137):** Baustein `teiler.js` für Seiten mit Liste und Einzelheiten – Maus, Finger, Tastatur; die Höhe merkt sich der Browser. Genutzt von „Personen“.
- **Modul-API:** `Dienste::nutzerAmMandanten()` und `Dienste::buecherAmMandanten()` für Module am Mandanten.

### Hinweis
- Vergütung und Lohnmerkmale führt diese Stufe nicht; Dokumente der Akte (Paperless) ebenfalls nicht.
- Bis die Verschlüsselung personenbezogener Daten steht (SCHLUESSEL.md), nur mit erfundenen Personen nutzen.
- Offen aus F137: Filter in den Tabellenköpfen der übrigen Seiten, Teiler im Lager, verschiebbare und ausblendbare Menüleiste.

## [0.7.42] – 2026-11-20

### Neu
- **Module, Rechte und Rollen am Mandanten (F135, PERSONAL.md Stufe 0).** Ein Modul nennt im Manifest seine Ebene: `buch` (wie bisher) oder `mandant`. Ein Modul am Mandanten hat sein Schema in der Datenbank des Mandanten, seine Pfade liegen unter `/m/<mandant>/api/v1/<modul>/…`, und es wird ohne Buch aktiviert (`bin/kumero modul:aktivieren <modul> <mandant>`).
- **Datenbank des Mandanten:** Module, Rechte, Bausteine, Rollen und Zuweisungen mit denselben Funktionen wie im Kern eines Buchs (`db/mandant/0002_module_rollen.sql`); `mandant.modul_protokoll()` schreibt in die Kette des Mandanten.
- **Rollen am Mandanten zuweisen:** `/m/<mandant>/api/v1/zuweisungen`, nur für den Administrator. Er hat selbst keines der Rechte dieser Module. Die Seite „Nutzer und Rollen“ zeigt Rollen am Mandanten in einer eigenen Spalte und in derselben Auswahl, sobald dort ein Modul aktiv ist.
- **Menü:** Bereich „Personal“ über den Büchern für Module am Mandanten.
- **Modul-API:** `Kumero\Api\V1\Mandantenauskunft` und `Dienste::auskunftAmMandanten()` – ein Modul eines Buchs bekommt von einem Modul am Mandanten je Eintrag nur ID und Anzeigenamen.

### Geändert
- `modul:liste` zeigt Module am Mandanten je Mandant; `install/aktualisieren.sh` spielt ihre Migrationen in die Datenbank des Mandanten ein.

### Hinweis
- Diese Version bringt noch kein Modul am Mandanten mit; sie ist die Voraussetzung für `personal`, `zeit` und `dienstplan`. Geprüft mit einem Prüfmodul, das nur in den Tests liegt.

## [0.7.41] – 2026-11-20

### Geändert – Rollen des Lagers (F134; Modul `lager` 0.4.0)
**Je Buch einmal neu aktivieren:** `bin/kumero modul:aktivieren lager <mandant>` – das legt die neuen Rollen an und stellt die Rechte um.
- **Zwei neue Rollen, getrennt von der Buchhaltung:** **„Lager“** (Bestand sehen, Wareneingang, Entnahme, Umlagern, Ausbuchen, Inventur anlegen und zählen) und **„Lagerleitung“** (dazu Artikel, Lagerorte, Einstellungen, Chargen sperren, Inventur abschließen oder verwerfen). Beide sehen keine Buchungen und keinen Arbeitsvorrat.
- **„Erfasser“ hat keine Lager-Rechte mehr.** Wer bucht und im Lager arbeitet, bekommt beide Rollen.
- **„Leser“ sieht das Lager** (bisher vergessen).
- **Inventur abschließen** ist ein eigenes Recht (`lager.inventur.abschliessen`): Zählen darf die Rolle „Lager“, abschließen die Lagerleitung.
- **Bestandsveränderung buchen** bleibt bei der Buchhaltung: Der Knopf ist gesperrt und sagt es, wenn der Rolle das Recht fehlt, Buchungsentwürfe anzulegen.
- Buchhalter und Verantwortlicher behalten alle Lager-Rechte; Steuerberater und Prüfer sehen.

### Technik
- Module können im Manifest **eigene Rollen** mitbringen (`eigene_rollen`): Beim Aktivieren werden sie im Buch angelegt, wenn es sie noch nicht gibt. `rollen_ohne` nimmt einer Rolle Bausteine aus einer früheren Fassung des Moduls.

## [0.7.40] – 2026-11-20

### Neu – Bewertung und Bestandsführung des Lagers (F133, LAGER.md Stufe 3; Modul `lager` 0.3.0)
- **Seite „Bestandsliste und Wert“:** der Bestand zu einem **Stichtag** mit Menge, Preis je Einheit und Wert, mit Summe; Druck und CSV. Gerechnet wird aus den Bewegungen bis zum Ende des Stichtags.
- **Bewertung je Buch:** letzter Einstandspreis (Vorgabe) oder gleitender Durchschnitt. Artikel, deren Zugänge keinen Preis tragen (Anfangsbestand, Muster), sind als „ohne vollständigen Preis“ gemeldet – ihr Wert fehlt in der Summe.
- **Einstandspreise netto oder brutto** je Buch: Zieht das Buch keine Vorsteuer ab, rechnet der Wareneingang den Preis der Position mit dem Steuersatz hoch. Gilt für künftige Zugänge.
- **Aufwand oder Bestand** je Buch (Vorgabe: sofort Aufwand, der Wert ist dann nachrichtlich). Führt das Buch Bestand, zeigt die Seite den **Saldo der Vorratskonten** neben dem Lagerwert und nennt die Differenz. „Bestandsveränderung buchen …“ legt darüber einen **Buchungsentwurf** an (Bestandskonto gegen Konto der Bestandsveränderung); festgeschrieben wird er wie jede Buchung. Das Lager selbst bucht nie, und je Entnahme entsteht keine Buchung.
- Solange ein solcher Entwurf offen ist, ist ein zweiter gesperrt; ein gelöschter Entwurf zählt nicht mehr.
- **Wareneingang:** Liegt der Preis einer Position je Einheit über der GWG-Grenze, steht der Hinweis „eher ein Anlagegut?“ mit Sprung in die Anlagenbuchhaltung – nur, wenn das Modul `anlagen` im Buch aktiv ist; entschieden wird vom Menschen.

### Technik
- `PUT /lager/einstellung` setzt nur die genannten Felder. Neue Endpunkte `GET /lager/bestandsliste?stichtag=`, `POST /lager/abschluss`, `POST /lager/abschluss/entfernen`. Migration `0003_bewertung.sql`; gerechnet wird in ganzen Zahlen (`Kumero\Modul\Lager\Bewertung`).

## [0.7.39] – 2026-11-20

### Neu – Nachbestellliste und Inventur (F132, LAGER.md Stufe 2; Modul `lager` 0.2.0)
**Das Modul hat ein neues Recht:** je Buch einmal neu aktivieren – `bin/kumero modul:aktivieren lager <mandant>`.
- **Sollbestand** am Artikel, neben dem Mindestbestand: darunter wird nachbestellt, bis dahin wird aufgefüllt.
- **Seite „Nachbestellen“:** Artikel, deren **verfügbarer** Bestand unter dem Mindestbestand liegt – verfügbar ist, was weder abgelaufen noch gesperrt ist. Mit Vorschlag bis zum Sollbestand, umgerechnet in Bestelleinheiten des Lieferanten (z. B. 7 × 10), dessen Artikelnummer, dem letzten Lieferanten und dem letzten Preis. Druck und CSV. kumero bestellt nicht selbst.
- **Seite „Inventur“:** Zählliste für einen Lagerort oder alle, je Charge eine Zeile. Jede Eingabe ist sofort gespeichert – die Zählung lässt sich unterbrechen und fortsetzen; Enter springt zur nächsten Zeile, der Scan zur Zeile der Charge. Gerechnet wird gegen den **Bestand im Augenblick der Zählung** der Zeile: Was danach entnommen wird, verfälscht die Differenz nicht. „Zeile hinzufügen“ für das, was da liegt, aber nicht auf der Liste steht.
- **Abschließen** ist gesperrt, solange Zeilen offen sind, und nennt den Grund. Es erzeugt je Abweichung eine Bewegung „Inventurdifferenz“ und sperrt die Inventur; kein Bestand wird überschrieben. Eine Inventur lässt sich auch verwerfen.
- Zugänge aus Belegen merken den Namen des Lieferanten; die gemerkte Zuordnung behält seine Artikelnummer so, wie sie auf dem Beleg steht.

### Technik
- Neues Recht `lager.inventur.fuehren` (Bausteine „Lager zählen“ und „Lager führen“). Migration `0002_nachbestellen_inventur.sql`.

## [0.7.38] – 2026-11-20

### Neu – Lager mit Chargen und Verfallsdaten (F131, LAGER.md Stufe 1; neues Modul `lager` 0.1.0)
**Je Buch aktivieren:** `bin/kumero modul:aktivieren lager <mandant>`. Für den Wareneingang aus Belegen muss im Buch auch `arbeitsvorrat` aktiv sein.
- **Neuer Bereich „Lager“** im Menü mit den Seiten **Bestand** und **Wareneingang**.
- **Bestand:** Artikel mit Menge, Mindestbestand, nächstem Verfall und Zustand in Worten; darunter an festem Platz die Chargen des gewählten Artikels je Lagerort. Vier Kacheln zählen abgelaufene, bald ablaufende und gesperrte Chargen sowie Artikel unter Mindestbestand – ein Klick schränkt die Liste darauf ein. Suche nach Name, Nummer, Charge oder mit dem gescannten Code der Packung.
- **Artikel** werden ohne Charge, mit Charge oder mit Seriennummer geführt; das Verfallsdatum ist je Artikel Pflicht oder nicht (bei Arzneimitteln und Medizinprodukten vorbelegt). Nach dem ersten Bestand steht die Führungsart fest. Stillgelegt wird, nie gelöscht.
- **Entnahme mit Vorschlag:** vorgewählt ist die freie Charge mit dem frühesten Verfall. **Abgelaufene und gesperrte Chargen sind nicht entnehmbar** – der Knopf ist gesperrt und nennt den Grund. Wer von der vorgeschlagenen Charge abweicht, darf das; es steht an der Bewegung. Nie mehr, als da ist.
- **Kein Patientenbezug:** Die Entnahme kennt nur einen Zweck aus einer Auswahlliste des Buchs, keinen freien Text.
- **Sperren und Freigeben** einer Charge mit Grund, **Umlagern**, **Ausbuchen** (verfallen, beschädigt, zurückgegeben, vernichtet, Berichtigung) – auch für abgelaufene und gesperrte Chargen.
- **Rückverfolgung** je Charge: alle Bewegungen mit Zeit, Menge, Ort, Zweck oder Beleg, wer, und dem Bestand danach; dazu Sperre und Freigabe.
- **Wareneingang:** Die Positionen eines Belegs aus dem Arbeitsvorrat stehen als Vorschlag da. Je Position Artikel wählen (einmal je Lieferantenartikel, danach gemerkt – samt Faktor, z. B. 1 Packung = 10 Ampullen), Charge, Verfall und Lagerort erfassen, „Zugang buchen“. „Kein Lagerartikel“ (z. B. Versandkosten) wird ebenfalls gemerkt. Die Summe der Positionen wird gegen den Nettobetrag geprüft.
- **Code scannen:** Ein Scanner, der wie eine Tastatur schreibt, füllt Charge und Verfall aus dem Code der Packung (Produktkennung, Verfall, Charge, Seriennummer). Zerlegt wird im Browser; es gibt keine Kamera-Funktion und keinen Dienst außerhalb.
- **Betäubungsmittel** werden nicht im Lager geführt: Ein so gekennzeichneter Artikel lehnt Bewegungen ab.
- **Einstellungen je Buch:** Warnfrist vor dem Verfall (Vorgabe 90 Tage), Zwecke der Entnahme.

### Technik
- Schema `lager` je Buch: Bewegungen werden nur angefügt, der Bestand ist ihre Summe; berichtigt wird durch Gegenbewegung. Im Protokoll stehen nur IDs, Mengen (als Text) und Kennungen.
- Neuer Menübereich `lager` im Kern.

## [0.7.37] – 2026-11-20

### Neu – Positionen des Belegs (F130, LAGER.md Stufe 0; Module `paperless` 0.5.1, `import` 0.5.4, `arbeitsvorrat` 0.5.7)
- **E-Rechnungen** (XRechnung, ZUGFeRD / Factur-X) liefern ihre **Positionen** mit: Bezeichnung, Menge, Einheit, Einzelpreis, Betrag, Steuersatz, Artikelnummer des Verkäufers und des Käufers, GTIN; aus UBL auch Charge und Verfall, aus CII die Charge.
- **Sidecar von nopaper:** kumero liest Positionen, sobald der Sidecar welche trägt. Ohne Positionen bleibt alles wie bisher.
- **Im Arbeitsvorrat** steht an Einträgen mit Positionen eine Marke „n Pos.“; die Positionen zeigt sie beim Überfahren.
- Positionen sind ein **Vorschlag**: Aus ihnen entsteht nie von selbst eine Buchung oder ein Zugang. Ergeben sie nicht den Nettobetrag des Belegs (Toleranz 1 Cent je Position), steht das als Hinweis am Eintrag.

### Technik
- Neu in der Modul-API: `Kumero\Api\V1\Belegpositionen` (gemeinsame Gestalt, Prüfung, Summe). Der Vertrag für Quellen des Arbeitsvorrats kennt das Feld `positionen` (höchstens 500); `Dienst::positionen()` und `Dienst::eintraegeMitPositionen()` geben sie an Module weiter.
- Bei einem Beleg mit negativer Summe stehen die Positionsbeträge ohne Vorzeichen, die Menge bleibt wie gedruckt.

## [0.7.36] – 2026-11-20

### Neu – Anlagenbuchhaltung (F129, ANLAGEN.md; neues Modul `anlagen` 0.1.0)
**Je Buch aktivieren:** `bin/kumero modul:aktivieren anlagen <mandant>`.
- **Seite „Anlagen“** im Bereich Buchführung: das Anlagenverzeichnis je Geschäftsjahr – Zugang, Anschaffungskosten, Art der Absetzung, Buchwert am Jahresanfang, AfA des Jahres, Abgang, Buchwert am Jahresende, und ob die Absetzung gebucht ist. Druck und CSV.
- **Einstellungen je Buch:** ob das Buch **Vorsteuer abzieht** (Anschaffungskosten netto oder brutto – eine Sache der Leistungen, die das Buch erbringt), wie die Absetzung endet (0,00 € oder 1,00 € Erinnerungswert, je Anlagegut änderbar) und welche Konten Anlagekonten sind.
- **kumero schlägt vor, der Mensch entscheidet:** die Art nach dem Nettobetrag (Sofortabzug, Sammelposten, Verteilung) und die **Nutzungsdauer aus der AfA-Tabelle**; weicht die gewählte Dauer ab, kann eine Begründung dazu – freiwillig. Die Maske zeigt den Plan der Absetzung über alle Jahre, bevor gespeichert wird.
- **Arten:** linear nach Monaten (der Monat des Zugangs zählt voll), GWG-Sofortabzug, Sammelposten über fünf Jahre, „keine Absetzung“. Bestand von vor kumero mit Stichtag und bisher abgesetztem Betrag.
- **Anlagegut aus einer Buchung:** Festgeschriebene Buchungen im Soll eines Anlagekontos ohne Anlagegut werden gezählt und beim Anlegen angeboten (Datum, Betrag, Konto vorbelegt).
- **AfA-Lauf:** legt je Anlagegut einen **Buchungsentwurf** an (Aufwand an Bestand, zum Jahresende); festgeschrieben wird wie jede Buchung. Wiederholbar – er legt nur an, was fehlt; in einem gesperrten Jahr läuft er nicht.
- **Abgang** (Verkauf, Verschrottung, Entnahme): Absetzung bis zum Monat des Abgangs, der Rest des Buchwerts kommt mit dem nächsten Lauf als Entwurf. Zurücknehmbar.
- **Abstimmung zum Journal:** Summe der Buchwerte gegen den Saldo der Anlagekonten, mit der Differenz.
- **Regelwerk als Datei** (`module/anlagen/regelwerk/afa-de.json`): Grenzen 250 € / 800 € / 1.000 € mit Gültigkeit und ein Auszug der AfA-Tabelle „AV“ – **keine Steuerberatung, vom Verantwortlichen zu prüfen**; Branchentabellen fehlen.
- Rechte: `anlagen.gut.sehen` (alle Rollen), `anlagen.gut.pflegen` und `anlagen.lauf.ausfuehren` (Buchhalter, Verantwortlicher, Steuerberater).

### Geändert
- **„Auswertungen“ steht im Bereich Buchführung**, nicht mehr in einem eigenen Menübereich (Vorgabe vom 2026-11-20; Modul `auswertungen` 0.1.1).

## [0.7.35] – 2026-11-20

### Neu – Auswertungen (F128, AUSWERTUNGEN.md; neues Modul `auswertungen` 0.1.0)
**Je Buch aktivieren:** `bin/kumero modul:aktivieren auswertungen <mandant>`.
- **Summen- und Saldenliste, Journal und Kontoblätter** für einen Zeitraum (Monat, Quartal, Geschäftsjahr, von–bis), im neuen Bereich „Auswertungen“. Dazu „Aufwand und Ertrag“ als viertes mitgeliefertes Beispiel.
- **Eigene Auswertungen:** Mit „Anpassen …“ stellt sich jeder zusammen, was er sehen will – Art (Kontenliste, Journal, Kontoblätter), Konten (einzeln, als Bereich `4200-4299`, als Anfang `48*`), Kontoarten, Spalten und Gliederung. Bei „eigene Zeilen“ fasst jede Zeile die genannten Konten unter einer Bezeichnung zusammen, Summenzeilen bilden Zwischensummen. Die Anpassung gilt sofort für die Anzeige; „Speichern unter …“ legt sie als eigene Auswertung ab, auf Wunsch für alle im Buch.
- **Gespeichert wird die Zusammenstellung, keine Zahl** – gerechnet wird bei jedem Aufruf neu aus dem Journal. Eine Auswertung ändert nichts und bucht nichts.
- **Der Vortrag wird gerechnet, nicht gebucht:** Bestandskonten beginnen mit ihrem Saldo vom Tag vor dem Zeitraum, Erfolgskonten in jedem Geschäftsjahr bei null.
- **Maßstab sind festgeschriebene Buchungen.** „Entwürfe mitrechnen“ kennzeichnet die Auswertung als vorläufig; sonst sagt sie, wie viele Entwürfe nicht mitzählen.
- **Jede Ausgabe nennt ihren Stand:** Buch, Zeitraum, letzte berücksichtigte Buchung, Zeitpunkt; Testbuch und Testbetrieb stehen dabei.
- **Kontrollen:** Die Summenliste sagt, ob Soll und Haben übereinstimmen. Eine eigene Gliederung nennt bewegte Konten, die in keiner Zeile stehen, und Konten, die doppelt gezählt sind.
- **Druck und CSV:** eigene Druckansicht (ohne Kopf, Menü und Knöpfe; PDF über den Druckdialog des Browsers) und dieselben Zeilen als CSV (UTF-8 mit BOM, Semikolon, Dezimalkomma). Doppelklick auf ein Konto öffnet das Kontoblatt, auf eine Buchung die Buchung.
- **Kern:** neue Basisabfrage `GET …/summen?von=&bis=&entwuerfe=` (Vortrag, Soll und Haben des Zeitraums und seit Jahresbeginn, Saldo). Der Rahmen bringt Druckregeln für alle Seiten mit (Klasse `nicht-drucken`).
- Rechte: `auswertungen.vorlage.pflegen` (Erfasser, Buchhalter, Verantwortlicher, Steuerberater), `auswertungen.vorlage.teilen` (ohne Erfasser). Ansehen und anpassen darf, wer Buchungen sehen darf – auch der Prüfer.

## [0.7.34] – 2026-11-20

### Neu – Sidecar von nopaper aus Paperless lesen (F127, BELEGEINGANG.md Abschnitt 9; Modul `paperless` 0.5.0)
- **nopaper liefert nicht mehr an kumero ein.** Seit nopaper 1.6 („erst paperless“) steht der Sidecar als Feld am Dokument in Paperless. kumero liest ihn beim Abgleich des Arbeitsvorrats mit – ohne neuen Endpunkt, ohne Token, ohne Einstellung: Das Feld wird an seiner Kennung erkannt, nicht am Namen. Das geplante Modul `nopaper` und der Auftrag „Sidecars einliefern“ entfallen.
- **Der Sidecar geht den einzelnen Erkannt-Feldern vor;** die Felder bleiben der Rückfall. Weicht ein Feld ab (jemand hat es in Paperless geändert), steht das als Hinweis am Eintrag.
- Aus dem Sidecar: Rechnungsnummer, Rechnungsdatum, Rechnungssteller mit USt-IdNr. oder Steuernummer, Belegart, Brutto, Netto, Steuer und die **Steuer je Satz**. Neben der Maske: Fälligkeit, Skonto, IBAN, Verwendungszweck, Leistungsdatum und was nopaper gemeldet hat.
- **Prüfstatus:** Hat bei nopaper eine Prüfregel angeschlagen, steht „nopaper: Beleg prüfen“ mit den Codes als Hinweis am Eintrag.
- **Negative Beträge** (so, wie sie auf dem Beleg gedruckt sind) werden ohne Vorzeichen und mit umgekehrter Richtung vorbelegt, mit Hinweis – auch aus einem einzelnen Feld. Bisher wurde ein negativer Betrag verworfen.
- Nicht verwendet wird ein Sidecar, der zu einem anderen Dokument gehört, eine unbekannte Fassung hat oder nicht lesbar ist; der Eintrag sagt warum. Beträge in fremder Währung werden nicht übernommen.
- Nur am nachgebildeten Muster geprüft; nopaper selbst hat die Betriebsart noch nicht an einem echten Paperless erprobt.

## [0.7.33] – 2026-11-20

### Behoben
- **`bin/kumero selbsttest` meldete seit 0.7.30 für jeden Mandanten einen Fehler** („schema "kern" does not exist“): Er suchte die Ketten des Buchs noch in der Datenbank des Mandanten. Die Daten waren davon nicht berührt. Jetzt prüft er je Mandant dessen Protokollkette und je Buch die Ketten des Kerns.
- Der Selbsttest meldet einen **Mandanten ohne Administrator** und nennt den Befehl dazu. Das kann nach dem Überführen so sein, wenn vorher niemand die Rolle Administrator hatte.

## [0.7.32] – 2026-11-20

### Behoben
- **Seiten der Verwaltung waren über die Adresse zu öffnen.** Wer `#/system/kern/mandanten` von Hand eingab, sah als Nutzer eines Mandanten die Maske „Mandant anlegen“; der Server wies jede Anfrage ab („Recht fehlt“), Daten waren nicht zu sehen. Jetzt gibt es Seiten der Verwaltung – Installation (Bereich „Verwaltung“) und Mandant („Nutzer und Rollen“) – nur für den, der ihren Menüeintrag sehen darf; sonst heißt es „Diese Seite gibt es nicht“. Seiten eines Buchs bleiben, wie sie sind.
- **Auswahl des Buchs im Kopf:** Bei nur einem Buch sah sie aus wie eine bedienbare Liste. Jetzt steht der Name ohne Rahmen und Pfeil da; der Grund steht weiter beim Überfahren.

## [0.7.31] – 2026-11-20

### Behoben
- **Update auf die Bücher direkt von 0.7.22 bis 0.7.29 aus.** 0.7.30 verlangte genau 0.7.29 als Ausgangsstand – strenger als nötig: Die Migrationen bringen jeden Stand ab 0.7.22 erst auf den von 0.7.29, danach wird überführt. Nur Stände vor 0.7.22 brauchen den Zwischenschritt über 0.7.29. Sonst unverändert gegenüber 0.7.30; **vorher sichern** (INSTALL.md Abschnitt 4).

## [0.7.30] – 2026-11-20

### Geändert – Mehrere Bücher je Mandant (F126; BUECHER.md Stufe B und C)
**Einmaliger Schritt beim Update, vorher sichern – INSTALL.md Abschnitt 4. Direkt nur von 0.7.29 aus (seit 0.7.31: ab 0.7.22). Ein Zurück gibt es nur über die Sicherung.**
- **Ein Mandant hat Bücher; jedes Buch ist eine eigene Buchhaltung mit eigener Datenbank** `kumero_<mandant>__<buch>` (Journal, Konten, Rollen, Module, Protokollkette, Siegel). Die Datenbank `kumero_<mandant>` gehört jetzt dem Mandanten (Schema `mandant`): Nutzer, Passkeys, Zugänge, Sitzungen, Einstellungen, Administratoren, Liste der Bücher, eigene Protokollkette.
- **Drei Ebenen im Pfad:** Installation `/api/v1/…`, Mandant `/m/{mandant}/api/v1/…`, Buch `/m/{mandant}/b/{buch}/api/v1/…`; in der Oberfläche `#/m/{mandant}/b/{buch}/…`. Alle bisherigen Mandantenpfade außer `/nutzer…` liegen im Buch; Felder und Fehlercodes sind unverändert. Alte Lesezeichen führen in das gewählte Buch. `openapi/v1.yaml` ist umgestellt, `openapi/buecher-entwurf.yaml` entfällt.
- **Überführen des Bestands** (`bin/kumero system:buecher-ueberfuehren`, von `aktualisieren.sh` aufgerufen, wiederholbar): Die vorhandene Datenbank jedes Mandanten wird in `kumero_<mandant>__haupt` umbenannt und ist sein erstes Buch `haupt`; kein Hash ändert sich. Nutzer, Passkeys, offene Zugänge und Einstellungen ziehen mit gleicher ID in die neue Datenbank des Mandanten – **die Passkeys gelten weiter**, alle melden sich einmal neu an. Wer die Rolle Administrator hatte, wird Administrator des Mandanten. Das Buch des Mandanten `spiel` wird Testbuch.
- **Administrator ist ein Amt im Mandanten**, keine Rolle eines Buchs: Er pflegt Nutzer, Bücher, Rollen und Module in jedem Buch und sieht keine Buchungsdaten. Der letzte Administrator bleibt (Datenbank). Die Rollen eines Nutzers gelten **je Buch**; ein Buch ohne Rolle antwortet wie ein unbekanntes (404).
- **Oberfläche:** Auswahl des Buchs im Kopf an festem Platz (Wechsel ohne neue Anmeldung), Testbücher sind gekennzeichnet. Neuer Bereich **„Mandant“** mit den Seiten **„Bücher“** (Liste, anlegen, umbenennen) und „Nutzer und Rollen“ (Rollen für das gewählte Buch, Administrator einsetzen und abberufen). Die Übersicht zeigt die Bücher.
- **Buch anlegen** ist ein Auftrag an den Dienst `kumero-auftraege` (wie „Mandant anlegen“): Kennung, Name, Art der Gewinnermittlung (`euer`, `bilanz`), Testbuch. **Ein Testbuch wird nie produktiv** (Datenbank). „Mandant anlegen“ legt kein Buch mehr an.
- **Siegel:** Der Siegelinhalt nennt in Fassung 2 Mandant **und** Buch; die Datei heißt `kumero-siegel-<mandant>-<buch>-<nr>.json`. Siegel der Fassung 1 bleiben gültig.
- **Paperless-Zugang je Buch** (Entscheidung vom 2026-11-20, ändert B5): Zugang, Verbindung und Tags richtet der Nutzer in jedem Buch ein.
- **Kommandozeile:** neu `buch:anlegen`, `mandant:administrator`, `system:buecher-ueberfuehren`; `nutzer:anlegen` und `nutzer:rolle` mit `--buch`, `nutzer:anlegen --administrator`; `modul:aktivieren <modul> <mandant> [buch]`, `kontenrahmen:anwenden … --buch`. Ohne Angabe gilt das eine Buch des Mandanten. Entfallen: `mandant:eintragen`, `system:nutzer-ueberfuehren`.
- **Migrationen:** Kern 0016 (`kern.buch` mit Kennung, Art, Testbuch; Nutzertabellen ziehen aus), Verwaltung 0006 (`verwaltung.buch`, Wasserstand je Buch, Auftrag `buch_anlegen`), neu `db/mandant/0001` (`db/migrieren.sh --mandant`).
- Module (nur Seitenpfade): `buchen` 0.3.6, `arbeitsvorrat` 0.5.6, `kontenrahmen` 0.1.6, `import` 0.5.3, `siegel` 0.2.1.

### Nicht gebaut
- Testbuch als **Kopie** eines echten Buchs und das Löschen einzelner Bücher (Stufe D; O1, O2 und O9 sind offen).
- Freigabe-Passkey einmal im Mandanten (A9): Die Anwendung hat noch keinen Weg, Freigabe-Passkeys einzurichten; das kommt zusammen.

## [0.7.29] – 2026-11-20

### Neu – Nutzer und Rollen in der Weboberfläche (F125, VERWALTUNG.md Abschnitt 15)
- **Seite „Nutzer und Rollen“** im Bereich Einstellungen, für die Rolle Administrator: Nutzer anlegen und bearbeiten, **Zugang ausstellen**, Rolle zuweisen (auch befristet) und beenden, Passkey widerrufen, sperren und entsperren. Sperren, Entsperren und „Rolle zuweisen“ gelten für die markierten Einträge.
- **Zugang mit Link und Code:** kumero zeigt beides genau einmal; gespeichert sind nur Hashes. Der Code hat sechs Zeichen ohne verwechselbare. Die Seite „Passkey registrieren“ fragt ihn ab; nach fünf falschen Eingaben ist der Zugang ungültig. Ein neuer Zugang macht den vorigen derselben Person ungültig. Auf Wunsch werden beim Ausstellen die vorhandenen Passkeys widerrufen.
- **Der letzte Administrator und der letzte Verantwortliche bleiben:** nicht sperrbar, letzte Zuweisung nicht beendbar – erzwungen von der Datenbank, also auch für die Kommandozeile.
- REST: `/m/{mandant}/api/v1/nutzer…`, `/zuweisungen`, `/api/v1/registrierung/pruefen`, Feld `code` bei der Registrierung (`openapi/v1.yaml`).
- **Kern, Migration 0015:** `registrierung_code_pruefen`; `registrierung_ausstellen`, `nutzer_aendern` und `zuweisung_beenden` neu gefasst. **Verwaltung, Migration 0005:** Die Anwendung darf Pseudonyme eintragen und ein eben eingetragenes desselben Mandanten wieder entfernen.
- Antworten mit `Cache-Control: no-store` aus einem Handler legt der Kern nicht mehr im Idempotenzspeicher ab.

### Installation
- Beide Migrationen spielt `aktualisieren.sh` ein. Kein einmaliger Schritt.
- Geändert sind Dateien unter `/kern/` (`ui/rahmen.js`, `ui/api.js`, `ui/rahmen.css`, neue Seite). Zeigt der Browser danach „doesn't provide an export named …“, einmal die Websitedaten löschen.
- Wer die Seite sehen soll, braucht die Rolle **Administrator** – der erste Administrator jedes Mandanten hat sie. Auf `spiel`: mit dem Passkey des Administrators anmelden; gibt es keinen, `bin/kumero nutzer:rolle spiel <Nutzer-ID> --rolle Administrator`.

### Bekannt
- Geprüft: `php tests/lauf.php Nutzerverwaltung` (neu, 93), `Anwendung` (250), `Oberflaeche` (106), die SQL-Suite (mit `56_nutzerverwaltung.sql`, neu), Browserlauf `nutzer.mjs` (neu, 22) – mit PHP 8.3 und PostgreSQL 16. Nicht gelaufen: die übrigen PHP-Tests und Browserläufe; php-fpm auf der VM, PostgreSQL 19.
- **Nicht gebaut:** Seite „Module“, „Administrator einsetzen“ und „System-Verwalter“ in der Weboberfläche, eigene Rollen, Rechtebericht.
- Links der Kommandozeile bleiben ohne Code.
- Rollen gelten je Mandant; mit mehreren Büchern (BUECHER.md) wird daraus „je Buch“.
- Festlegungen N1–N8 in VERWALTUNG.md Abschnitt 15 sind zu bestätigen.

## [0.7.28] – 2026-11-20

### Geändert – Siegel: festgeschrieben wird erst nach Signatur und Bestätigung (F124, SIEGEL.md)
- **„Siegel anfordern“ schreibt nichts mehr fest.** Es entsteht eine **vorläufige Anforderung** mit dem Siegelinhalt – ohne Siegel, ohne Nummer in der Liste, ohne Protokolleintrag. Sie lässt sich **verwerfen** und verfällt nach 24 Stunden. Es gibt höchstens eine offene Anforderung.
- **Hochladen prüft nur.** Die geprüfte Signatur steht an der Anforderung und lässt sich ersetzen.
- **Neu: „Siegel festschreiben …“** – die Bestätigung zeigt Kettenstände, Prüfsumme, Schlüssel und Zeit der Signatur. Erst dann entstehen **Siegel, Signatur und Protokolleintrag in einer Transaktion** (`siegel.festgeschrieben`). Das Ereignis `siegel.angefordert` gibt es nicht mehr.
- Seite „Siegel“ (Modul `siegel` 0.2.0): Karte **„Offene Anforderung“** an festem Platz mit eigenen Knöpfen (Siegelinhalt herunterladen, Signatur hochladen, festschreiben, verwerfen); die Liste zeigt nur festgeschriebene Siegel. „Weitere Signatur …“ gilt für ein festgeschriebenes Siegel und wird sofort eingetragen.
- REST: `POST /siegel` liefert die Anforderung; neu `POST /siegel/{id}/festschreiben` und `DELETE /siegel/{id}`; `GET /siegel` nennt `anforderung`; `POST /siegel/{id}/signaturen` antwortet an der Anforderung mit 200 und `festgeschrieben: false` (`openapi/v1.yaml`).
- **Kern, Migration 0014:** Tabelle `kern.siegel_anforderung` (vorläufig, nicht Teil der Ketten), Funktionen `siegel_anfordern` (neu gefasst), `siegel_anforderung_signatur`, `siegel_anforderung_verwerfen`, `siegel_festschreiben`; `siegel_signieren` nur noch für festgeschriebene Siegel.

### Installation
- Migration 0014 spielt `aktualisieren.sh` ein. Kein einmaliger Schritt.
- **Siegel, die mit 0.7.26/0.7.27 angefordert und nie signiert wurden, bleiben stehen** (der Kern löscht nichts) – angezeigt als „nie signiert (vor 0.7.28 angefordert)“. Sie nehmen keine Signatur mehr an; die Zählung läuft dahinter weiter, und das nächste Siegel nennt sie als Vorgänger.

### Bekannt
- Geprüft: `php tests/lauf.php Siegel` (98), die SQL-Suite (`58_siegel.sql` neu gefasst, 42), Browserlauf `siegel.mjs` (28) – mit PHP 8.3 und PostgreSQL 16. Nicht gelaufen: die übrigen PHP-Tests und Browserläufe; php-fpm auf der VM, PostgreSQL 19.
- Die Bestätigung ist ein Knopf, kein Passkey – die Signatur weist die Person schon aus (SIEGEL.md S12, bestätigt am 2026-11-20).
- Eine weitere Signatur an einem schon festgeschriebenen Siegel wird ohne eigene Bestätigung eingetragen (S14).

## [0.7.27] – 2026-11-20

### Geändert – Menü (F122)
- **Der Bereich „Buchen“ heißt „Buchführung“.** Der Abschluss gehört zur Buchführung: **„Siegel“ steht jetzt dort**, hinter den Geschäftsvorfällen (Modul `siegel` 0.1.1). Der Bereich „Abschluss“ bleibt im Vertrag, hat aber keinen Eintrag mehr und wird deshalb nicht gezeigt.
- **Jeder Menüeintrag trägt ein Symbol** – auch Unterpunkte. Neu: Übersicht, Konten, Mandanten (Kern, `public/kern/seiten/symbole/`), „Datei einlesen“ (Modul `import` 0.5.2), „Kontenrahmen anwenden“ und „Angewendete Kontenrahmen“ (Modul `kontenrahmen` 0.1.5). Wechselnde Unterpunkte (`menue_unterpunkte`) tragen das Symbol ihres Eintrags.
- **Ein Manifest ohne Symbol an einem Menüeintrag wird abgewiesen** (`Menue::pruefen`) – das trifft auch Plugins.

### Beschrieben, nicht gebaut
- **BUECHER.md – mehrere Bücher je Mandant (F123):** je Buch eine eigene Datenbank, der Mandant als Klammer; Pfad `/m/{mandant}/b/{buch}/…`; Rollen, Module und Kontenrahmen je Buch; Testbücher und Übernahmen. B1–B8 sind freigegeben, O1–O7 offen.

### Installation
- Kein einmaliger Schritt. Die drei Module bekommen neue Versionen im Pfad; `aktualisieren.sh` stellt um.

### Bekannt
- Geprüft: `php tests/lauf.php Oberflaeche` (106), `Siegel` (62), `Import` (222) mit PHP 8.3 und PostgreSQL 16. Nicht gelaufen: die übrigen PHP-Tests, die SQL-Suite (kein SQL geändert) und alle Browserläufe – **die neuen Symbole hat niemand im Browser gesehen**.

## [0.7.26] – 2026-11-19

### Neu – Siegel auf Anforderung (F119, F120, SIEGEL.md)
- **Modul `siegel` 0.1.0** mit der Seite **„Siegel“** im Bereich Abschluss: Siegel anfordern, Siegelinhalt herunterladen, außerhalb signieren (`gpg --armor --detach-sign`), Signatur hochladen. kumero prüft die Signatur mit `gpgv` gegen den gespeicherten Inhalt und die angemeldeten öffentlichen Schlüssel und trägt sie erst dann ein.
- **Der private Schlüssel erreicht kumero nie** – auch nicht flüchtig, auch kein Passwort. Es gibt keinen Systemschlüssel und keinen Signierdienst auf dem Server. Ein hochgeladener privater Schlüssel wird abgewiesen und nicht gespeichert.
- **Siegelinhalt:** kanonisches JSON mit beiden Kettenköpfen (Buchungen und Protokoll), Mandant, Nummer, Zeitpunkt und der Prüfsumme des vorigen Siegels.
- **Öffentliche Schlüssel im Mandanten:** anmelden, zurückziehen mit Grund. Ein Siegel kann mehrere Signaturen tragen. Inhalt, Signatur und Schlüssel sind als Dateien herunterladbar; damit wiederholt jeder Dritte die Prüfung mit `gpg` allein.
- **Rechte:** `siegel.siegel.sehen`, `siegel.siegel.anfordern`, `siegel.schluessel.pflegen`. Der Verantwortliche siegelt und pflegt Schlüssel; Buchhalter, Steuerberater und Prüfer lesen.
- **Kern, Migration 0013:** `kern.siegel` umgebaut, neu `kern.siegelschluessel` und `kern.siegel_signatur`, Funktionen `siegel_anfordern`, `siegel_signieren`, `siegelschluessel_anmelden`, `siegelschluessel_zurueckziehen`; Modul-API `Kumero\Api\V1\Siegel`. Ereignisse im Protokoll: `siegel.angefordert`, `siegel.signiert`, `siegel.schluessel_angemeldet`, `siegel.schluessel_zurueckgezogen`.
- REST unter `/m/{mandant}/api/v1/siegel…` (`openapi/v1.yaml`).

### Installation
- Migration 0013 des Kerns spielt `aktualisieren.sh` ein. Sie bricht ab, falls `kern.siegel` schon Einträge hätte – bisher gab es nichts, das die Tabelle füllt.
- Einmal je Mandant: `cd /var/www/kumero && bin/kumero modul:aktivieren siegel <mandant>`.
- Auf dem Server werden `gpg` und `gpgv` gebraucht; beide richtet der Installer seit jeher ein.

### Bekannt
- Geprüft: `php tests/lauf.php Siegel` (neu, 62), `Oberflaeche`, `Anwendung`, die SQL-Suite (mit `58_siegel.sql`, neu), Browserlauf `siegel.mjs` (neu, 20). Nicht gelaufen: die übrigen PHP-Tests und Browserläufe.
- Nicht geprüft: die Prüfung unter php-fpm auf der VM (Wegwerfverzeichnis unter dem temporären Verzeichnis des Dienstes, Aufruf von `gpg` und `gpgv`), Signaturen von einer Karte oder einem Token, PostgreSQL 19.
- **Nicht gebaut:** Signieren im Browser, QES, Zeitstempel, Export der Siegel außer Haus. Der Produktivbetrieb (F59) verlangt unverändert die alten Voraussetzungen; das ist neu festzulegen (SIEGEL.md Abschnitt 9).
- `signiert_am` ist die Zeitangabe aus der Signatur, also die Uhr des Unterzeichners.
- Festlegungen S1–S10 in SIEGEL.md Abschnitt 10 sind zu bestätigen.

## [0.7.25] – 2026-11-19

### Neu – Mandantenverwaltung des System-Verwalters (F118, VERWALTUNG.md Abschnitt 14)
- **Seite „Mandanten“** im Bereich Verwaltung: Mandant anlegen und dabei seinen ersten Administrator mit E-Mail einsetzen, stilllegen, reaktivieren, exportieren, löschen.
- **Auftrag + Dienst:** Die Webanwendung bekommt keine Rechte an Datenbanken. Die Seite schreibt einen Auftrag in die Verwaltungsdatenbank (Migration 0004); der Dienst `kumero-auftraege` (`bin/kumero system:auftraege`) führt ihn als `kumero_owner` aus. Die Seite zeigt den Stand und sieht selbst nach, solange etwas läuft.
- **Anlegen:** Die Seite zeigt den Zugang des Administrators (Link, 24 Stunden) **einmal**; gespeichert ist nur sein Hash.
- **Export:** vollständige Sicherung mit `pg_dump` nach `/var/lib/kumero/exporte/`; die Seite nennt Datei, Größe und SHA-256. **Kein Download im Browser** – der System-Verwalter sieht keinen Mandanten von innen (F43).
- **Löschen:** nur stillgelegt **und danach exportiert**, mit eingetippter Kennung. Übrig bleibt in der Verwaltung der Protokolleintrag mit Kennung, Zeitpunkt, wer gelöscht hat und dem letzten Kettenkopf. Die Sicherung bleibt für den Mandanten liegen.
- Der Name eines Mandanten steht ab jetzt nicht mehr im Protokoll der Verwaltung; ältere Einträge der unveränderlichen Kette behalten ihn.
- REST unter `/api/v1/verwaltung/mandanten…` (`openapi/v1.yaml`), nur mit `verwaltung.verwalter`.

### Geändert
- **nginx-Vorlagen: `client_max_body_size 350m`** (bisher 32 MB) – passend zur Grenze des Moduls `import` für CSV und CAMT (F117). Mit 32 MB kamen größere Dateien gar nicht bei PHP an.
- Die Grenzen des Moduls `import` (350 MB, 20.000 Umsätze je Datei), des Arbeitsvorrats (5.000 Einträge) und von PHP (1 GB) sind unverändert. Zielwerte und nötiger Umbau stehen als Entwurf in IMPORT.md Abschnitt 18 und ARBEITSVORRAT.md Abschnitt 14.

### Installation
- Migration 0004 der Verwaltungsdatenbank spielt `aktualisieren.sh` ein.
- **Von Hand, einmalig als root** – `aktualisieren.sh` richtet weder den Dienst ein noch schreibt es nginx neu:
  ```
  install -d -m 0700 -o root -g root /var/lib/kumero/exporte
  install -m 0644 /var/www/kumero/install/vorlagen/kumero-auftraege.service /var/www/kumero/install/vorlagen/kumero-auftraege.timer /etc/systemd/system/
  systemctl daemon-reload && systemctl enable --now kumero-auftraege.timer
  ```
  In `/etc/nginx/sites-available/kumero` (und ggf. `kumero-tunnel`) `client_max_body_size 350m;` setzen, dann `nginx -t && systemctl reload nginx`.
- Läuft der Dienst nicht, bleiben Aufträge auf „wartet auf den Dienst“; `/var/www/kumero/bin/kumero system:auftraege` führt sie von Hand aus. Fehler stehen in `journalctl -u kumero-auftraege`.

### Bekannt
- Geprüft: alle PHP-Tests (1247), die SQL-Suite, Browserläufe `mandanten.mjs` (neu, 26) und `import.mjs`. Nicht gelaufen: die übrigen vier Browserläufe.
- **Nicht geprüft: die systemd-Einheiten.** Die Tests rufen den Dienst als Befehl auf. Ob die Einschränkungen der Einheit (kein Netz, nur das Export-Verzeichnis beschreibbar) den Zugriff auf den Socket von PostgreSQL und `pg_dump` zulassen, zeigt erst die VM. Schlägt es dort fehl, zuerst `ProtectSystem`, `PrivateNetwork` und `RestrictAddressFamilies` in der Einheit auskommentieren.
- Zugang des Administrators vorerst nur als Link; der Code folgt mit Stufe 2. Festlegungen M1–M9 in VERWALTUNG.md Abschnitt 14 sind zu bestätigen.
- Bei rund 5 KB je CAMT-Umsatz greift die Grenze von 20.000 Umsätzen schon bei etwa 100 MB.

## [0.7.24] – 2026-11-19

- Modul `import` 0.5.1: Dateigrößenlimit für CSV und CAMT auf 350 MB erhöht.
- PHP-Vorlage: Speicherlimit 3 GB, Upload- und POST-Limit 1 GB.


## [0.7.23] – 2026-11-18

- Modul `import` 0.5.0: CAMT-Kontoauszüge (.053) und Umsatzanzeigen (.054), Versionen .001.02 und .001.08. Vorschau mit Zuordnung Bankkonto → Geldkonto, Prüfung der Auszugssalden und Auflösung von Sammelumsätzen. Einlieferung in den Arbeitsvorrat mit gemeinsamem Abgleich beider Formate (F116).

- Geprüft: 1170 PHP-Prüfungen, SQL-Suite und fünf Browserläufe mit insgesamt 210 Prüfungen. Der Test auf der Betreiber-VM mit apoBank-Dateien steht aus.

## [0.7.22] – 2026-11-18

### Geändert – Nutzer gehören zum Mandanten (F114, F115; VERWALTUNG.md Stufe 1)
Eigene Version nur für diesen Umbau. **Nach dem Update kann sich niemand mit einer alten Sitzung anmelden; `aktualisieren.sh` überführt den Bestand.**
- **Ein Nutzer ist eine Identität in genau einem Mandanten.** Nutzer, Anmelde-Passkeys, Zugänge (Links), Sitzungen und Einstellungen liegen in der Datenbank des Mandanten (Kern, Migration 0012). Die Verwaltungsdatenbank hält nur noch Mandantenliste, Wasserstandsmarken und System-Verwalter – und neu die Zuordnung **Pseudonym → Mandant** (Migration 0003), sonst nichts.
- **Anmeldung:** eine Anmeldeseite für die Installation. Der Passkey trägt ein Pseudonym (32 zufällige Byte); es bestimmt den Mandanten, geprüft wird der Passkey im Mandanten. Die Zuordnung verschafft keinen Zugang – zeigt sie in den falschen Mandanten, scheitert die Anmeldung. **Die Lesart hat der Verantwortliche bestätigt.**
- **Eine Sitzung gilt nur für ihren Mandanten**; Mandant wechseln heißt neu anmelden. Die Auswahl „Mandant“ im Rahmen entfällt, an ihrer Stelle steht der Mandant der Anmeldung.
- **System-Verwalter** sind eine eigene Identität der Installation und sehen keinen Mandanten von innen. Kontenrahmen einlesen und Vorlagen bleiben bei ihnen; angewendet wird im Mandanten von jemandem mit dem Recht dazu.
- **Kommandozeile:** `mandant:anlegen <kennung> <name> --administrator <Vorname> <Familienname> <E-Mail>` (Mandant anlegen = ersten Administrator einsetzen), `nutzer:anlegen <mandant> <Vorname> <Familienname> <E-Mail> --rolle …`, `nutzer:rolle`, `nutzer:link <mandant> <nutzer>`, `nutzer:liste`, `verwalter:anlegen`, `verwalter:link` (der Notausgang), `verwalter:liste`. **`zugang:gewaehren` entfällt.**
- **Überführen des Bestands** (`system:nutzer-ueberfuehren`, wiederholbar): Jeder bisherige Nutzer wird in jedem Mandanten mit Zugang eine Identität mit derselben ID – Protokoll, Buchungen und Rollen stimmen weiter. Der bisherige Name wird Familienname; Vorname(n) und E-Mail fragt die Oberfläche bei der nächsten Anmeldung einmal ab. Vorhandene Passkeys gelten in einem Mandanten weiter (Testmandant, sonst der zuerst gewährte); für weitere Mandanten und für die Verwaltung nennt der Befehl, wer einen neuen Link braucht.
- **REST** (`openapi/v1.yaml`): `POST /api/v1/anmeldung` verlangt `response.userHandle`; `Ich` mit `mandant`, `vornamen`, `familienname`, `email`, `angaben_fehlen`; `PATCH /api/v1/ich` nimmt einmalig fehlende Angaben.

### Installation
- **Vorher die Verwaltungsdatenbank sichern:** `pg_dump -U kumero_owner -Fc kumero_verwaltung > /root/kumero_verwaltung-vor-0.7.22.dump`
- `aktualisieren.sh` spielt die Migrationen ein und ruft danach `system:nutzer-ueberfuehren` auf. Die Ausgabe nennt, wer einen neuen Passkey braucht. Wer System-Verwalter **und** Nutzer eines Mandanten war, meldet sich mit dem alten Passkey im Mandanten an und holt sich für die Verwaltung einen neuen: `bin/kumero verwalter:liste`, `bin/kumero verwalter:link <id>`.
- Kein neues Modul, keine neue Modulversion.

### Bekannt
- **Nicht gebaut:** Zugang mit Link **und** Code, die Seiten „Nutzer und Rollen“, „Module“, „Mandanten“ (Stufe 2); Mandant herausgeben und löschen, Dienstkonten und Tokens im Mandanten (Stufe 3). Bis dahin pflegt die Kommandozeile Nutzer und Rollen.
- Festlegungen aus der Umsetzung, zu bestätigen: VERWALTUNG.md Abschnitt 13, S1–S13. Die Protokollkette der Verwaltung ist unveränderlich und behält die alten Einträge mit Namen.
- **Geprüft** (Ausnahme von F103 – alle Läufe): SQL-Tests (`db/tests`, darunter neu `55_nutzer.sql`, `72_verwaltung_pseudonym.sql`), alle PHP-Tests (1139 Prüfungen), alle fünf Browserläufe. **Einschränkung:** In der Sitzung, in der gebaut wurde, ließen sich die Abhängigkeiten nicht laden (kein `composer install`). PHP- und Browserläufe liefen deshalb mit einem **Nachbau** von Symfony HttpFoundation/Routing/Console und Doctrine DBAL, nicht mit den Bibliotheken aus `composer.lock`; dazu PHP 8.3 und PostgreSQL 16. **Nicht gelaufen:** die Tests mit den echten Bibliotheken, `aktualisieren.sh` als Ganzes, das Überführen an einem echten Bestand. Ein Release-Archiv wurde nicht gebaut.

## [0.7.21] – 2026-11-18

### Behoben
- **Buchungsliste zeigte nachgereichte Belege nicht** (Modul `buchen` 0.3.5): Das Zeichen „Beleg öffnen“ und die Vorschau (Auge) erschienen nur für Belege, die mit der Buchung festgeschrieben wurden. Der Beleg einer E-Rechnung wird aber erst nach der Buchung an das Belegarchiv übergeben und **nachgereicht** (F110, D1) – in der Liste sah die Buchung deshalb aus, als hätte sie keinen Beleg, obwohl er in Paperless lag. Jetzt gilt der erste Beleg der Buchung, gleich ob festgeschrieben oder nachgereicht.

### Bekannt
- Der Beleg steht erst an der Buchung, wenn Paperless das Dokument fertig verarbeitet hat **und** der Arbeitsvorrat danach noch einmal abgeglichen wurde (im Hintergrund höchstens alle fünf Minuten, sonst „Jetzt abgleichen“). Bis dahin zeigen Liste und Ansicht keinen Beleg. Das ist unverändert.
- Geprüft nach F103: Browserlauf `paperless.mjs` (um die Buchungsliste nach der Übergabe erweitert). Nicht gelaufen: die übrigen Tests.

## [0.7.20] – 2026-11-18

### Neu – auch die XRechnung als reines XML geht an das Belegarchiv (F112)
Entscheidung des Verantwortlichen zu D5 (IMPORT.md Abschnitt 16): **Paperless bleibt das System der Dokumente.** Im Paperless des Referenzbetriebs wird ein Parser-Plugin für E-Rechnungen eingerichtet (`paperless-ngx-erechnung`); damit nimmt Paperless eine XRechnung als XML an und erzeugt selbst die lesbare Darstellung. kumero baut keine eigene.
- **Modul `paperless` 0.4.0:** Einstellung des Mandanten **„Dieses Paperless nimmt E-Rechnungen als XML an“** (Seite „Paperless – mein Zugang“, Karte „Belegablage für diesen Mandanten“, Recht `paperless.vorgabe.pflegen`; `PUT /paperless/ablage`). Vorgabe: aus. Beim Übergeben geht ein XML als `application/xml` mit der Endung `.xml` an `post_document`.
- **Modul `import` 0.4.0:** Ist die Einstellung an, wird eine XRechnung wie das ZUGFeRD-PDF behandelt – **Zwischenablage, nach der Buchung Übergabe an das DMS, Beleg nachgereicht, in kumero gelöscht**. Ist sie aus, bleibt es wie bisher: Das XML wird nicht gespeichert; die Prüftabelle sagt das jetzt bei der Datei. Eine früher ohne Original eingelesene XRechnung, die noch offen ist, bekommt ihr XML beim erneuten Einlesen nachgeliefert.
- **Vorschau einer XRechnung:** Ein XML hat kein Bild. Am Eintrag steht ein Blatt mit den Angaben, die kumero aus der Datei liest; „Beleg öffnen“ zeigt das XML.
- **Abgelehnte Übergaben werden sichtbar:** Nimmt das DMS ein Original nicht an (z. B. XML ohne Plugin), bleibt es liegen, der Grund wird an der Datei vermerkt, und „Datei einlesen“ nennt die Zahl. Abgelehnte halten die übrigen Übergaben eines Abgleichs nicht mehr auf.
- **Modul-API des Kerns:** `Belegablage::belegAblageNimmt()` und `Dienste::belegablageNimmt()` – ein DMS-Modul sagt, welche Medientypen es annimmt, unabhängig davon, ob der Nutzer gerade verbunden ist.

### Installation
- Migrationen `import` 0003 und `paperless` 0004 spielt `aktualisieren.sh` ein.
- Im Paperless das Plugin einrichten, **dann** in kumero unter „Paperless – mein Zugang“ das Kreuz setzen. Reihenfolge umgekehrt schadet nicht: Das XML bleibt in kumero liegen, bis Paperless es annimmt.

### Bekannt
- Geprüft nach F103: PHP-Tests Paperless (199), Import (191), Anwendung, Oberfläche; Browserläufe `paperless.mjs` (43, erweitert um die Einstellung und die Vorschau der XRechnung) und `einlesen.mjs` – alles gegen den Nachbau, der XML ohne Plugin wie Paperless ablehnt. **Nicht gelaufen:** die übrigen Tests und **alles am echten Paperless 3.4.2 mit dem Plugin**. Dort zu bestätigen: dass `post_document` das XML annimmt, dass Paperless als `original_checksum` die Prüfsumme des XML nennt (daran findet kumero das Dokument wieder) und dass Titel und Belegdatum ankommen.
- Die Übergabe des XML bis zum nachgereichten Beleg prüft der PHP-Test, nicht der Browserlauf.

## [0.7.19] – 2026-11-18

### Behoben
- **Eine E-Rechnung ließ sich nicht noch einmal einlesen** (Modul `import` 0.3.1): Lag die Rechnung schon im Arbeitsvorrat, hieß es nur „schon im Arbeitsvorrat“ und der Knopf war gesperrt – auch wenn der Eintrag dort gar nicht mehr offen zu sehen war. Jetzt:
  - Die Tabelle nennt den **Zustand** des vorhandenen Eintrags: liegt offen, in Arbeit, bereits gebucht, verworfen, zusammengeführt.
  - **Verworfen → wird mit dem Einliefern wieder geöffnet** und bekommt sein PDF zurück.
  - **Offen, aber ohne PDF** (vor 0.7.18 oder als XML eingelesen) **→ das Original wird nachgeliefert**, damit es die Vorschau gibt; es entsteht kein zweiter Eintrag.
  - In Arbeit oder gebucht: wird nicht noch einmal eingeliefert, der Zustand steht da.
  - Ein Einlesen ohne Neues erzeugt keinen Import im Verlauf mehr (dessen „Zurückziehen“ nichts bewirkte).
- **Nach einem Update lief alter Kern mit neuen Modulseiten** („The requested module '/kern/ui/beleg.js' doesn't provide an export named …“): Die Dateien des Kerns unter `/kern/` haben keine Version im Pfad, und nginx lieferte sie ohne Angabe zur Gültigkeit – der Browser nahm sie aus seinem Zwischenspeicher. Die nginx-Vorlagen setzen dafür jetzt `expires -1` (der Browser fragt jedes Mal nach, ob sich die Datei geändert hat). **Auf bestehenden Installationen von Hand nachzuziehen** – `aktualisieren.sh` schreibt die nginx-Konfiguration nicht neu.

### Bekannt
- Geprüft nach F103: PHP-Tests Import (190) und Arbeitsvorrat; Browserläufe Datei einlesen und Paperless. Nicht gelaufen: die übrigen Tests.

## [0.7.18] – 2026-11-18

### Neu – kumero hält kein Dokument dauerhaft (F110)
- **Vorschau für das PDF einer E-Rechnung:** Das PDF liegt nach dem Einliefern in einer **Zwischenablage** (Modul `import` 0.3.0, Migration `0002_datei.sql`). Am Eintrag gibt es damit die Vorschau wie bei jedem Beleg – im Arbeitsvorrat, im Vergleich und in der Buchungsmaske; „Beleg öffnen“ zeigt das PDF.
- **Ohne Buchung wird gelöscht:** Wird der Eintrag verworfen (beim nächsten Abgleich) oder der Import zurückgezogen (sofort), löscht kumero das Original. Es bleiben nur Prüfsummen und Zeitpunkte als Nachweis.
- **Mit der Buchung geht das PDF an das DMS und wird in kumero gelöscht:** Nach dem Festschreiben übergibt kumero das Original beim Abgleich des Arbeitsvorrats an Paperless (Modul `paperless` 0.3.0), reicht den Beleg mit Link und Prüfsummen der Buchung nach (F27) und löscht seine Kopie. Verarbeitet Paperless noch oder ist es gesperrt, bleibt die Übergabe ausstehend und wird beim nächsten Abgleich nachgeholt; gesendet wird nur einmal. Führt Paperless die Datei schon, wird nichts gesendet.
- **Ein vorläufiger Beleglink gelangt nie in eine Buchung:** Maske und Stapel lassen ihn weg (Kennzeichen „Zwischenablage“), der Kern lehnt ihn ab (`422 beleg.vorlaeufig`).
- Modul-API: `Kumero\Api\V1\Belegablage`, `Dienste::belegablage()`, `Dienste::belegNachreichen()`; Belegarchive dürfen sich als `vorlaeufig` melden. „Datei einlesen“ nennt den Stand der Zwischenablage.

### Geändert
- „Im Archiv öffnen“ ist bei einem Beleg aus der Zwischenablage gesperrt und nennt den Grund.

### Nach dem Einspielen
- `bin/kumero modul:aktivieren import <mandant>` (neue Migration). Die Vorschau braucht `pdftocairo` (Paket poppler-utils, wie der Kontenrahmen-Import).

### Bekannt
- **Die Übergabe ist nicht an einem echten Paperless gelaufen**, nur am Nachbau. Zu bestätigen: dass Paperless 3.4.2 das Hochladen so annimmt und kumero den neuen Beleg danach findet. Gelingt das nicht, bleibt das Original in der Zwischenablage liegen – es geht nichts verloren.
- Eine XRechnung als reines XML wird nicht zwischengelagert und nicht übergeben. Im DMS setzt kumero nur Titel und Belegdatum.
- Der Beleg steht an der Buchung als „nachgereicht“, nicht im versiegelten Kopf – Paperless kennt den Link erst nach dem Festschreiben.
- Geprüft nach F103: PHP-Tests Import (174, darunter Zwischenablage, Sperre im Kern, Löschen nach Verwerfen und Zurückziehen), Paperless (163), Arbeitsvorrat (154), Buchen (229), Anwendung (81), Oberfläche (91); SQL-Tests (203, weil der Kern berührt ist); Browserläufe Paperless (38, darunter Vorschau aus der Zwischenablage, Buchen, Übergabe in zwei Abgleichen, nachgereichter Beleg, gelöschte Datei), Datei einlesen (48), Buchen (58), Arbeitsvorrat (41). Nicht gelaufen: die übrigen PHP-Tests.

## [0.7.17] – 2026-11-18

### Neu
- **Mehrere Steuersätze werden je Satz gebucht** (F109; Module `import` 0.2.1, `arbeitsvorrat` 0.5.3, `buchen` 0.3.3): Enthält eine E-Rechnung z. B. 19 % und 7 %, geht die Aufteilung mit an den Eintrag. Mit vorbelegtem Steuerkonto belegt die **Buchungsmaske** die Splitbuchung je Satz vor – Sachzeile und Steuerzeile je Satz, Geldkonto mit dem Rechnungsbetrag –, und die **Übergabe in den Stapel** legt die Entwürfe ebenso an. Das Steuerkonto je Satz findet kumero über die Steuersätze der Konten. Bisher hieß es „von Hand zu splitten“.
- **Prüftabelle der E-Rechnungen mit mehr Angaben:** Leistungsdatum, Fälligkeit, Positionen (Anzahl und die ersten Namen) und die **Steuer je Satz** stehen schon vor dem Einliefern da.

### Geändert
- Ein ZUGFeRD-PDF im Profil EN 16931 heißt „ZUGFeRD / Factur-X (EN 16931), CII im PDF“ statt nur „EN 16931“.
- Einzahl: „1 Rechnung in den Arbeitsvorrat einliefern“, „1 Eintrag“ in den Fußzeilen der Tabellen.

### Noch nicht gebaut – Entwurf zur Freigabe
- **Vorschau des PDF und Übergabe an das DMS** (Vorgabe vom 2026-11-18): beschrieben in IMPORT.md Abschnitt 16 mit den Entscheidungen D1–D8 (P25). Der Schritt braucht eine neue Schnittstelle der Modul-API und lädt zum ersten Mal Dokumente nach Paperless – deshalb erst die Freigabe.

### Bekannt
- Aufgeteilt wird nur mit vorbelegtem Steuerkonto (wie bisher: ohne Steuerkonto brutto). Ist zu einem Satz kein Steuerkonto eindeutig, bleibt das Feld in der Maske leer; im Stapel bleibt der Eintrag liegen und nennt den Satz.
- Geprüft nach F103: PHP-Tests Import (154), Arbeitsvorrat (154), Oberfläche (91); Browserläufe Datei einlesen (48, darunter Split je Satz in der Maske und im Stapel), Buchen (58), Arbeitsvorrat (41), Paperless (26). Nur mit selbst nachgebauten Musterdateien. Nicht gelaufen: die übrigen PHP-Tests und die SQL-Tests.

## [0.7.16] – 2026-11-18

### Neu
- **E-Rechnungen einlesen** (F108; Modul `import` 0.2.0, IMPORT.md Abschnitt 15): „Datei einlesen“ nimmt jetzt auch E-Rechnungen – **XRechnung** als XML (Syntax CII oder UBL, auch UBL-Gutschriften) und **ZUGFeRD ab 2.0 / Factur-X** als PDF mit eingebettetem XML. Es ist derselbe Importer: kumero erkennt an der Datei, ob sie CSV oder E-Rechnung ist. Mehrere E-Rechnungen auf einmal (bis 50, je 5 MB).
  - Je Rechnung ein Eintrag im Arbeitsvorrat: Art (Rechnung, Gutschrift, Korrektur), Gegenpartei mit USt-IdNr., Rechnungsnummer, Datum, Brutto, dazu **Netto und Steuer**, wenn sie den Rechnungsbetrag ergeben – damit belegt die Buchungsmaske die Splitbuchung vor (F101). Neben der Maske stehen Format, beide Parteien, Leistungsdatum, Fälligkeit, Steuer je Satz, Zahlbetrag, IBAN und die ersten Positionen.
  - **Eingangs- oder Ausgangsrechnungen** wählt der Nutzer beim Einlesen (die Datei sagt es nicht); Gutschriften drehen die Richtung.
  - Vor dem Einliefern zeigt eine Tabelle je Datei, was daraus würde: neu, schon im Arbeitsvorrat, unvollständig (wird markiert eingeliefert) oder nicht lesbar – mit Grund.
  - Dieselbe Rechnung als XML und im PDF ergibt einen Eintrag. Jede Datei steht im Verlauf der Importe und lässt sich zurückziehen.
  - Endpunkte `POST /import/rechnung/vorschau` und `/import/rechnung/einliefern`. Synthetische Muster: `werkzeuge/e-rechnung-muster.php`.

### Geändert
- **Menü: „Datei einlesen“ steht als Unterpunkt unter dem Arbeitsvorrat** (eingerückt darunter), nicht mehr als eigener Punkt neben der Buchungsliste; die Seite heißt „Datei in den Arbeitsvorrat einlesen“.
- Die Stufe des Importers für Quicken / Finanzmanager (Splitbuchungen, Umbuchungen, QIF) hat in IMPORT.md keine feste Nummer mehr; 0.2.0 sind die E-Rechnungen.

### Bekannt
- kumero **prüft die E-Rechnung nicht** gegen EN 16931 oder die XRechnung-Regeln (kein Schema, kein Schematron) und rechnet nicht nach. ZUGFeRD 1.0 und verschlüsselte PDFs werden nicht gelesen. Bei mehreren Steuersätzen steht die Aufteilung nur bei den Angaben.
- Die Datei wird nicht gespeichert und es entsteht kein Belegverweis; das Original der E-Rechnung gehört ins Belegarchiv.
- Geprüft nach F103: PHP-Tests Import (144, darunter 25 zum Lesen von CII, UBL und PDF und der Ablauf über REST) und Oberfläche; Browserlauf Datei einlesen (41, darunter Menü, Erkennen der Dateiart, vier Muster-Rechnungen, abgelehntes PDF, Ausgangsrechnung, Wiedereinlesen). **Nur mit selbst nachgebauten Musterdateien – nicht mit einer echten XRechnung oder ZUGFeRD-Datei.** Nicht gelaufen: die übrigen PHP-, SQL- und Browsertests.

## [0.7.15] – 2026-11-18

### Neu
- **Blättern im Arbeitsvorrat** (F107; Modul `arbeitsvorrat` 0.5.2, Baustein `kern/ui/blaettern.js`): Unter der Liste stehen an festem Platz „«“, „‹ Vorher“, **Seite _n_ von _m_** (eintippbar), „Weiter ›“, „»“ und die Seitengröße (50/100/200/500, Vorgabe 100), daneben „1–100 von 1.234“. Bild↑/Bild↓ blättern. Suche, Spaltenfilter, Zähler und Summe gelten für **alle** Einträge, nicht nur für die Seite; „Alle markieren“ markiert alle gefilterten Einträge über alle Seiten. Die Liste lädt bis zu 5.000 Einträge (bisher 500); erst darüber erscheint der Hinweis.
- **Verwerfen gilt für die Auswahl:** Sind Einträge markiert, werden **alle markierten** mit einem gemeinsamen Grund verworfen (Knopf „Verwerfen (n)“, Dialog „n Einträge verwerfen“) – ohne Haken wie bisher die gewählte Zeile. Neuer Endpunkt `POST /arbeitsvorrat/eintraege/verwerfen`: ganz oder gar nicht, jeder Eintrag mit eigenem Protokollsatz. Bisher wurde trotz Markierung nur die gewählte Zeile verworfen.
- **Keine Vorschau – mit konkretem Grund:** Statt „Keine Vorschau verfügbar.“ steht da, woran es liegt, z. B. „Paperless ist gesperrt: bitte unter ‚Paperless – mein Zugang‘ mit der Passphrase entsperren“, „Paperless ist nicht erreichbar“, „Paperless kennt diesen Beleg nicht oder dein Konto darf ihn nicht sehen“, „Paperless hat das Token abgelehnt“ – jeweils mit der Fehlernummer. Gilt für die kleine Vorschau am Zeiger, das Vorschaufenster und das Bild im Vergleich.

### Geändert
- **„Konten vorbelegen“ zeigt, was schon da ist:** Was an allen markierten Einträgen gleich ist (Konto, Gegenkonto, Steuerkonto, Buchungsart), steht bereits im Feld, daneben die Bezeichnung des Kontos; Unterschiedliches ist als „verschieden“ gekennzeichnet. Gespeichert wird nur, was geändert wurde.
- Sammelaktionen des Arbeitsvorrats (Vorbelegen, Leeren, Verwerfen) nehmen bis zu 5.000 Einträge an (bisher 500).
- **Datei einlesen** (Modul `import` 0.1.1): Der Vorschlag der Spaltenzuordnung erkennt „Zahlungsgrund“ / „Zahlungszweck“ als Betreff und „Buchungsart“ / „Umsatzart“ als Art. Dieselbe Datei wird nur einmal gelesen – ein doppelt gemeldeter Dateiwechsel konnte Eingaben (z. B. das Geldkonto) wieder leeren.
- IMPORT.md: Die zweite Stufe des Importers (Splitbuchungen, Umbuchungen, QIF) heißt jetzt 0.2.0, weil 0.1.1 für diese Korrektur vergeben ist.

### Bekannt
- Ein Vorschaubild, das die Seite schon gezeigt hat, hält der Browser bis zum Neuladen im Speicher; es bleibt in demselben Fenster also auch nach dem Sperren von Paperless sichtbar. Vom Server kommt nach dem Sperren nichts mehr.
- „Wieder öffnen“ gilt weiter nur für die gewählte Zeile. Blättern gibt es bisher nur im Arbeitsvorrat, noch nicht in Buchungsliste und Kontenliste.
- Geprüft nach F103: PHP-Tests Arbeitsvorrat (154), Import (101) und Oberfläche (91); Browserläufe Datei einlesen (29, darunter Blättern, Vorbelegen und Verwerfen der Auswahl), Arbeitsvorrat (41) und Paperless (26, darunter der Grund bei gesperrtem Paperless). Nicht gelaufen: die übrigen PHP-Tests, die SQL-Tests, die Browserläufe Buchen und Kontenrahmen; nicht gemessen: das Verwerfen von mehreren hundert Einträgen auf einmal und die Liste mit 5.000 Einträgen.

## [0.7.14] – 2026-11-18

### Geändert – nichts verschiebt sich mehr (F106)
- **Meldungen stehen in der Statusleiste** oben, an festem Platz neben dem Mandanten. Der Inhalt der Seite rückt nicht mehr nach unten, wenn eine Meldung erscheint. Lange Meldungen stehen in einer Zeile; ein Klick klappt den ganzen Text über der Seite auf, beim Überfahren steht er als Hilfe da.
- **Keine dynamische Oberfläche in den Listen:** Knöpfe werden nie ein- und ausgeblendet, sondern gesperrt. **Ein gesperrter Knopf nennt beim Überfahren den Grund** („Erst eine Zeile wählen.“, „Die gewählte Zeile hat keine Marke ‚Doppelt?‘ …“). Neuer Baustein `sperren()` im Kern.
- **Arbeitsvorrat** (0.5.1): Werkzeugleiste in zwei festen Zeilen – oben die Aktionen für die gewählte Zeile, darunter die für die Auswahl; Knöpfe mit wechselnder Beschriftung haben eine feste Breite; „Trennen“ und „Gruppen von selbst zusammenführen“ sind immer da (gesperrt mit Grund); der Hinweis auf ausgefallene Quellen hat einen festen Platz in der Filterzeile.
- **Fester Kopf:** In Arbeitsvorrat, Buchungsliste (`buchen` 0.3.2) und Kontenliste bleiben Seitentitel, Werkzeugleiste, Filter und Tabellenkopf stehen; nur die Zeilen rollen.

### Noch offen
- Die Regel gilt für alle Seiten, umgesetzt ist sie in den drei Listen. Noch dynamisch: die Schritte von „Datei einlesen“ (erscheinen nach der Dateiwahl), in der Buchungsmaske die Splittabelle und die Knöpfe „Vorschau“ und „Soll ⇄ Haben“, in der Kontenliste das Formular „Neu“, die Seite „Paperless – mein Zugang“.
- Geprüft nach F103: Browserläufe der betroffenen Seiten – Arbeitsvorrat (41), Datei einlesen und Vergleich (20, darunter „die Werkzeugleiste steht nach einer Meldung an derselben Stelle“), Buchen (58), Paperless und Kontenrahmen-Import. Ob der Browser die Hilfe an gesperrten Knöpfen anzeigt, lässt sich im Testlauf nicht sehen – bitte am Bildschirm prüfen.

## [0.7.13] – 2026-11-18

### Neu
- **Vergleichen und zusammenführen im Arbeitsvorrat** (X3, F105; Modul `arbeitsvorrat` 0.5.0, Migration `0005_zusammenfuehren.sql`): Knopf **„Vergleichen“** an einem Eintrag mit Markierung „Doppelt?“ öffnet beide Einträge **links und rechts** – je Feld beide Werte, Abweichungen gekennzeichnet, darunter die Angaben der Quellen und, wenn ein Belegarchiv zuständig ist, das **Bild des Belegs** (Klick vergrößert).
  - **Je Feld wählbar**, welcher Wert gilt. Vorgeschlagen ist: Belegangaben (Datum, Belegnummer, Gegenpartei, Netto, Steuer …) vom Beleg, Geldfluss (Tag, Zahlweise) vom Umsatz. Es bleibt der Eintrag mit dem Beleg.
  - **Zusammenführen:** Aus zwei Einträgen wird einer; die Beiträge beider Quellen und die Vorbelegung (z. B. das Geldkonto) bleiben daran. Der aufgegangene Eintrag wird nicht gelöscht. Was abweicht, steht als Hinweis am Eintrag.
  - **„Kein Duplikat“:** Das Paar bleibt getrennt und wird nicht wieder gemeinsam markiert.
  - **„Trennen“** macht das Zusammenführen rückgängig, solange der Eintrag offen ist.
- **Zusammenführen von selbst – gebaut und getestet, aber ausgeschaltet.** Schalter unter „Regeln für den Arbeitsvorrat“ (Vorgabe: aus). Eingeschaltet erscheint ein Knopf, der nur eindeutige Gruppen zusammenführt: genau zwei Einträge, genau einer mit Beleg; „Kein Duplikat“ wird geachtet, alles andere bleibt und wird genannt.

### Geändert
- `install/README.md` nennt das Aktivieren des Moduls `import`.

### Noch offen
- Im Vergleich wird bei Gruppen mit mehr als zwei Einträgen je ein Paar gezeigt; ein Zusammenführen vieler Gruppen in einem Schritt gibt es nur über „von selbst“.
- Geprüft nach F103: Unittests und Ablauf über REST (100 Prüfungen im Test des Importers, darunter Vorschlag, „kein Duplikat“, Zusammenführen, Trennen, von selbst), Tests des Arbeitsvorrats (141), ein Browserlauf der Seiten „Datei einlesen“ und Vergleich (17). Das Belegbild im Vergleich ist nicht gegen ein Paperless gelaufen.

## [0.7.12] – 2026-11-18

### Neu
- **Modul `import` 0.1.0: CSV-Dateien als Quelle des Arbeitsvorrats** (IMPORT.md, F104). Seite **Buchen → „Datei einlesen“**: Datei wählen → Aufbau erkennen (Zeichensatz, Trennzeichen, Kopfzeile nach Vorspann, Datum, Dezimalzeichen) → Spalten zuordnen (Vorschlag aus den Spaltennamen) → **Einnahme oder Ausgabe** (Vorzeichen, Vorzeichen umgekehrt, zwei Spalten, Kennzeichen, fest) → **Geldkonto** → Vorschau mit Summen und Saldenkontrolle → einliefern. Jede Zeile wird ein Eintrag.
  - **Soll und Haben aus dem Geldkonto:** bei einer Ausgabe steht es im Haben, bei einer Einnahme im Soll – als Vorauswahl am Eintrag. Buchungsdatum ist der Tag des Geldflusses (Valuta, sonst der Buchungstag).
  - **Importprofile:** Aufbau, Spalten, Richtung und Geldkonto merken; Vorgabe des Mandanten oder eigenes Profil; an der Kopfzeile wiedererkannt.
  - **Nichts doppelt:** Schlüssel aus dem Inhalt der Zeile, überlappende Auszüge und dieselbe Datei ergeben keine zweiten Einträge.
  - **Fehlerhafte Zeilen werden markiert eingeliefert** (C10): mit dem, was lesbar ist, einem Hinweis mit Zeilennummer und allen Spalten als Angaben.
  - **Die Daten der Datei bleiben am Eintrag** (C9): Was keinem Feld zugeordnet ist, steht beim Buchen neben der Maske. Die Datei selbst wird nicht gespeichert; je Import bleiben Dateiname, Hash und Zahlen.
  - Import **zurückziehen**, solange keiner seiner Einträge bearbeitet ist.
- **Arbeitsvorrat 0.4.0: Spalte „Doppelt?“** (C8): Einträge mit gleichem Betrag und derselben Gegenpartei oder Belegnummer – etwa Abbuchung und Rechnung – sind als Gruppe markiert, sortierbar und filterbar. Zusammengeführt wird noch nicht.
- Vertrag für Quellen: Eine Quelle darf das **Geldkonto** nennen (C5).

### Noch offen
- Zusammenführen der markierten Einträge (einzeln und im Stapel), Splitbuchungen, Umbuchungen und QIF (Importer 0.1.1), Profile als Datei weitergeben, eigene Seite für Importprofile.
- Geprüft nach F103: Unittests des Importers und der Markierung (58 Prüfungen, darunter der Ablauf über REST), Tests des Arbeitsvorrats (141), Oberfläche und ein Browserlauf nur der neuen Seite (12). Die übrigen Läufe sind nicht wiederholt. Nur synthetische Dateien; an einem echten Bankexport ist nichts gelaufen.

## [0.7.11] – 2026-11-18

### Neu
- **Steuersatz in der Kontenliste** (Stammdaten → Konten; Modul `buchen` 0.3.1): Spalte „Steuersatz“ je Konto – anklicken, Satz eintragen, Enter; ein geleertes Feld löscht ihn. Vorschläge aus der Bezeichnung („… 19 %“) sind mit „?“ gekennzeichnet und lassen sich über **„Steuersätze übernehmen (n Vorschläge)“** in einem Schritt bestätigen.
- **Konten ändern in der Kontenliste:** Knopf „Ändern“ je Zeile (auch Doppelklick oder Enter) – Bezeichnung und „aktiv“ immer, die Kontoart nur, solange das Konto nicht bebucht ist (F74). Die Nummer bleibt. Genutzt wird der vorhandene Endpunkt `PATCH /konten/{nr}`.
- **Erweiterungspunkt für Seiten des Kerns** (F102): Aktive Module nennen im Manifest unter `oberflaeche_erweiterungen` eine Datei je Punkt (bisher nur `konten`); der Kern liefert die Pfade mit dem Menü (`erweiterungen`) und lädt sie in der Kontenliste. **Der Kern kennt weiter nur Konten** – der Steuersatz liegt im Schema des Moduls `buchen`.
- **Kontenrahmen anwenden** (Modul `kontenrahmen` 0.1.4): Nach dem Anwenden weist die Seite darauf hin, dass in der Kontenliste Vorschläge zu den neuen Konten bereitstehen.
- Tests: SQL 203, PHP 796, Browser 10 + 58 + 41 + 25 Prüfungen (PHP 8.3 / PostgreSQL 16). Ab jetzt gilt F103: geprüft wird beim Bauen nur der bearbeitete Teil.

## [0.7.10] – 2026-11-18

### Neu
- **Steuerkonten mit Steuersatz** (F101; Modul `buchen` 0.3.0, Migration `0002_steuerkonto.sql`): Unter „Buchen einrichten → Steuerkonten“ bekommt ein Konto seinen Steuersatz (z. B. Vorsteuer 19 %). kumero schlägt den Satz aus der Kontobezeichnung vor; er gilt erst nach dem Speichern. Endpunkte `GET/PUT /buchen/steuerkonten`.
- **Splitbuchung rechnet die Steuer aus dem Brutto**: Steht ein Steuerkonto in der Tabelle, setzt die Maske Steuer = Brutto − Brutto ÷ (1 + Satz) und den Rest in die erste Sachzeile. **Die Summe bleibt immer der Rechnungsbetrag:** Weitere Aufwandszeilen gleicht die Restzeile aus. Gerechnete Beträge sind gekennzeichnet (kursiv, gestrichelt); ein eigener Betrag im Feld schaltet das Rechnen für die Zeile ab.
- **Steuerrichtung aus Soll und Haben:** Die Steuerzeile steht auf der Seite des Erfolgskontos – Vorsteuer im Soll bei Aufwand, Umsatzsteuer im Haben bei Ertrag.
- **Haken „Beträge netto eingeben“**: Dann sind die Sachzeilen netto, Steuer und Zahlbetrag werden dazugerechnet. Vorgabe ist brutto.
- **Arbeitsvorrat 0.3.2:** Mit vorbelegtem Steuerkonto entsteht in der Maske und bei „In den Stapel“ eine Splitbuchung – Netto und Steuer vom Beleg (DMS), wenn sie den Bruttobetrag ergeben, sonst aus dem Steuersatz gerechnet. Netto und Steuer vom Beleg sind keine Voraussetzung mehr.

### Bestätigt
- Paperless-ngx 3.4.2 nennt am Dokument die **SHA-256** des Originals („Original SHA256 checksum“). Damit ist die Ursache von „Prüfsumme stimmt nicht“ aus 0.7.8 bestätigt; der Vergleich seit 0.7.8 ist richtig.

### Noch offen
- Mehrere Steuersätze auf einem Beleg rechnet die Maske nicht von selbst auseinander (zwei Steuerzeilen: Beträge von Hand, die Summe muss aufgehen).
- Eine einfache Buchung (ohne „Aufteilen“) kennt kein Steuerkonto; der Schalter des Mandanten „brutto oder aufteilen“ (X2b) fehlt weiter.
- Tests: SQL 203, PHP 795, Browser 10 + 53 + 41 + 25 Prüfungen (PHP 8.3 / PostgreSQL 16, Paperless als Nachbau).

## [0.7.9] – 2026-11-18

### Neu
- **Buchungsliste mit sortierbaren Spalten und Filter je Spalte** (F91; Modul `buchen` 0.2.7): derselbe Baustein wie im Arbeitsvorrat – Klick auf den Spaltentitel sortiert, im Filter am Spaltenkopf lässt sich je Wert finden (＋) oder ausschließen (−). Spalten: Nr., Belegdatum, Buchungstext, Beleg-Nr., Konto, Soll, Gegenkonto, Haben. Der Haken „alle markieren“ gilt für die gefilterten Entwürfe; Anzahl und Kontrollsumme im Fuß ebenso.
- Tests: SQL 203, PHP 786, Browser 10 + 49 + 41 + 25 Prüfungen (PHP 8.3 / PostgreSQL 16).

### Noch offen
- Spaltenfilter in den übrigen Tabellen: Konten, Kontoblatt, Geschäftsvorfälle, Regeln, Kontenrahmen-Import.

## [0.7.8] – 2026-11-18

### Geändert
- **Arbeitsvorrat lädt sofort; der Abgleich mit den Quellen läuft im Hintergrund** (Modul `arbeitsvorrat` 0.3.1; Anlass: fast eine Minute Ladezeit bei 53 Einträgen am echten Paperless). Die Liste kommt aus der Datenbank (`GET /arbeitsvorrat/eintraege?abgleich=nein`); die Quellen werden über den neuen Endpunkt `POST /arbeitsvorrat/abgleich` gefragt – beim Öffnen von selbst, **höchstens alle fünf Minuten** je Mandant und Browserfenster, und jederzeit über **„Jetzt abgleichen“**. Danach frischt sich die Liste still auf. Neben dem Knopf steht „Abgeglichen um …“; beim Überfahren die Dauer je Quelle (nur Modulname und Sekunden). Der Sprung zum nächsten Eintrag in der Buchungsmaske fragt die Quellen nicht mehr.
- **Aktionen ohne Haken:** „Konten vorbelegen“, „Konten leeren“ und „In den Stapel“ gelten für die markierten Einträge – ist nichts markiert, für die gewählte Zeile (wie in der Buchungsliste).
- **„Konten leeren“** löscht Konto, Gegenkonto, Steuerkonto und Buchungsart der Auswahl wieder.
- **Buchungsliste** (Modul `buchen` 0.2.6): Haken im Tabellenkopf markiert alle angezeigten Entwürfe bzw. hebt die Markierung auf.

### Behoben
- **Modul `paperless` 0.2.1: „Die Prüfsumme des Originals stimmt nicht mit der Angabe von Paperless überein“** trotz unverändertem Beleg. kumero verglich die Angabe von Paperless immer mit der MD5; nennt Paperless die SHA-256 des Originals, wird jetzt damit verglichen. **Vermutete Ursache, am echten Paperless noch zu bestätigen.**

### Noch offen
- Die Ursache der langen Dauer des Abgleichs selbst ist nicht gefunden (mit dem Nachbau 0,06 s für 55 Belege); die Dauer je Quelle wird jetzt angezeigt.
- „Steuerbuchungen optimieren“ in Buchungsmaske und Arbeitsvorrat: noch zu beschreiben.
- Tests: SQL 203, PHP 786, Browser 10 + 49 + 39 + 25 Prüfungen; gelaufen auf PHP 8.3 / PostgreSQL 16, Paperless nur als Nachbau.

## [0.7.7] – 2026-11-18

### Neu
- **Zuordnung Paperless-Feld → Buchungssatz** (F95, PAPERLESS.md Abschnitt 12/13; Modul `paperless` 0.2.0, Migration `0003_zuordnung.sql`): Je Feld der Buchung (Betreff, Art, Gegenpartei, Kennung, Belegdatum, Betrag brutto, Netto, Steuerbetrag, Richtung, Belegnummer, Zahlweise, Zahltag) eine Reihenfolge von Quellen – benutzerdefinierte Felder, feste Felder (Titel, Korrespondent, Dokumenttyp, Erstelldatum, Archivnummer) und Tags (für Richtung und Zahlweise). **Vorgabe des Mandanten** (Recht `paperless.vorgabe.pflegen`), die **eigene Zuordnung** des Nutzers überschreibt je Feld. Vorschlag aus den Feldnamen, gespeichert wird erst nach Bestätigung. Werte werden streng gelesen: Was nicht passt (fremde Währung, kein Datum), wird nicht geraten, sondern steht als Hinweis am Eintrag. Einstellung unter „Paperless – mein Zugang → Felder zuordnen“. Endpunkte `GET/PUT /paperless/verbindungen/{id}/zuordnung`.
- **Splitbuchung mit Vorsteuer/Umsatzsteuer** (X2; Modul `arbeitsvorrat` 0.3.0, Migration `0004_steuer_pruefsumme.sql`; Modul `buchen` 0.2.5): Einträge tragen **Netto** und **Steuerbetrag**; in der Stapelvorbelegung gibt es das **Steuerkonto**. Ist es gewählt und sind Netto und Steuer bekannt, belegt die Buchungsmaske als Splitbuchung vor (Aufwand netto, Steuer, Geldkonto brutto) und „In den Stapel“ erzeugt dreizeilige Entwürfe. **Ohne Steuerkonto bleibt es brutto.**
- **Buchungsmaske übernimmt die Vorauswahl** aus dem Arbeitsvorrat (Konto, Gegenkonto, Buchungsart) – bisher wirkte sie nur bei der Übergabe in den Stapel.
- **Deduplizieren über die Prüfsumme** (X3, Stufe 1 und 2): Liefern Quellen denselben Beleg mit gleichem Link **oder gleicher Prüfsumme** (SHA-256, MD5), wird es ein Eintrag.

### Geändert
- **Belegvorschau hängt rechts neben dem Mauszeiger** (links, wenn rechts kein Platz ist; immer ganz im Fenster) und folgt ihm. Ausgelöst wird sie an einem **Augen-Symbol** am Beleg (eigene Spalte im Arbeitsvorrat, in der Buchungsliste) statt beim Überfahren der ganzen Zeile; Klick öffnet die große Vorschau, Taste V bleibt.
- **Buchungserfassung:** Überfahren des Knopfs „Vorschau“ zeigt dieselbe kleine Vorschau – auch in der Ansicht einer festgeschriebenen Buchung.
- Hinweis „unbar: Tag des Geldflusses eintragen“ bricht um, statt aus dem Fenster zu laufen.

### Noch offen
- X2b Voreinstellung des Mandanten „brutto oder aufteilen“ als eigener Schalter (derzeit entscheidet das Steuerkonto), X3 Vorschläge „vermutlich doppelt“ mit Stapelbestätigung, X4 Hinweis über Belegnummer und Betrag, Paperless-Zugang im Nutzerprofil (F93), Spaltenfilter in den übrigen Tabellen (F91), Arbeitsvorrat als Merkmal jedes Moduls (F90), Modul `nopaper`.
- Tests: SQL 203, PHP 777, Browser 10 + 49 + 33 + 24 Prüfungen; gelaufen auf PHP 8.3 / PostgreSQL 16, Paperless nur als Nachbau – die Zuordnung ist am echten Paperless noch nicht gelaufen.

## [0.7.6] – 2026-11-18

### Neu
- **Belegvorschau als abstraktes Merkmal** (F97): Schnittstelle `Kumero\Api\V1\Belegarchiv` für DMS-Module und Endpunkte des Kerns `/belege/archive`, `/belege/vorschau`, `/belege/original`, `/belege/verweis`. Baustein `public/kern/ui/beleg.js`: große Vorschau im Fenster, kleine Vorschau **beim Überfahren mit der Maus** am Rand (verschiebt nichts) und per Tastatur. Sie funktioniert überall, wo ein Belegverweis aus einem angebundenen DMS vorliegt – Arbeitsvorrat, Buchungsliste, Buchungsmaske, festgeschriebene Buchung. Paperless ist das erste Archiv.
- **Tabellen: sortierbare Spalten und Filter je Spalte** (F91) als Baustein `public/kern/ui/tabellenfilter.js`: Klick auf den Titel sortiert; im Filter am Spaltenkopf lässt sich je vorkommendem Wert **finden (＋)** oder **ausschließen (−)** wählen. Eingesetzt in der Liste des Arbeitsvorrats.
- **Modul `arbeitsvorrat` 0.2.0** (ARBEITSVORRAT.md Abschnitt 13, F96):
  - **Konto und Gegenkonto in der Liste** je Eintrag wählbar (X7) – eine Vorbelegung, keine Buchung (Migration `0003_vorauswahl.sql`).
  - **Massenauswahl** („Alle markieren“ für die gefilterten Einträge, Leertaste je Zeile) und **Stapelvorbelegung**: Konto, Gegenkonto, Buchungsart für alle markierten auf einmal (X1).
  - **In den Stapel** (X1a): Markierte, vollständig vorbelegte Einträge werden **freigabebereite Entwürfe** im Erfassungsstapel; festgeschrieben wird dort mit der Stapelfreigabe. Unvollständige bleiben im Vorrat und werden genannt.
  - **Schon gebucht?** (X4): Gibt es zu einem offenen Eintrag eine festgeschriebene, nicht stornierte Buchung mit demselben Beleg – gleicher Link oder gleiche Prüfsumme –, gilt er als gebucht und wird mit ihr verknüpft.
  - **Vorschau des Belegs beim Überfahren** (X6); Taste V auf der gewählten Zeile.
- Modul-API: `Dienste::buchungenZuBelegen()`.

### Geändert
- **Modul `buchen` 0.2.4** fragt für Vorschau und Belegprüfung nur noch den Baustein des Kerns und kennt Paperless nicht mehr beim Namen; Vorschau beim Überfahren auch in der Buchungsliste.
- **Modul `paperless` 0.1.2** stellt sich dem Kern als Belegarchiv bereit.

### Noch offen (aus dem Auftrag „alles von heute“ nicht gebaut)
- Zuordnung Paperless-Feld → Buchungssatz (F95), Splitbuchung mit Vorsteuer/Umsatzsteuer (X2), Deduplizieren über Prüfsumme und Vorschläge (X3), Paperless-Zugang im Nutzerprofil (F93), Spaltenfilter in den übrigen Tabellen (F91), Arbeitsvorrat als Merkmal jedes Moduls (F90), Modul `nopaper`.
- Tests: SQL 203, PHP 750, Browser 10 + 49 + 33 + 16 Prüfungen; gelaufen auf PHP 8.3 / PostgreSQL 16, Paperless nur als Nachbau.

## [0.7.5] – 2026-11-18

### Behoben
- **Modul `paperless` 0.1.1: Verbinden scheiterte mit „Paperless hat unerwartet geantwortet (HTTP 406)“.** kumero verlangte fest die API-Version 5; Paperless-ngx 3.x bedient nur 9 und 10. Bestätigt am Paperless 3.4.2 des Referenzbetriebs (meldet API-Version 10).

### Neu
- **API-Versionen von Paperless ab 3.x** (F94): gepflegte Tabelle `module/paperless/versionen.json` (API 9 und 10). kumero stellt fest, was Paperless meldet, spricht die höchste gemeinsame Version, merkt Paperless-Version und API-Version je Verbindung (Migration `0002_versionen.sql`) und wechselt nach einem Update von Paperless von selbst. Der Verbindungstest zeigt beide Versionen und sagt, wenn Paperless neuer ist als geprüft.
- Klare Absagen statt „unerwartet geantwortet“: Paperless vor 3.0, keine bekannte API-Version mehr, kein Paperless unter der Adresse.

### Entschieden
- **F93**: Der Zugang zu Paperless gehört ins Nutzerprofil (noch nicht umgezogen). **F94**: Das Modul kennt und spricht alle API-Versionen ab Paperless 3.x; jede neue Paperless-Version ist eine Pflegeaufgabe.

### Noch offen
- Am echten Paperless ist bisher nur der Aufruf mit `curl` bestätigt; kumero selbst lief nur gegen den Nachbau.
- Tests: **nur die API-Anbindung** – PHP `PaperlessTest` 135 Prüfungen, Browser `paperless.mjs` 14. Die übrigen Tests liefen für diese Version nicht erneut; geändert ist nur das Modul `paperless`.

## [0.7.4] – 2026-11-17

### Neu
- **Modul `paperless` 0.1.0** (PAPERLESS.md, F92): Paperless-ngx als Belegarchiv, mit dem **eigenen Konto des Nutzers**.
  - **Zugang** (Einstellungen → „Paperless – mein Zugang“): Adresse, Token, Tag für offene Belege. Das Token wird gegen Paperless geprüft und nur verschlüsselt gespeichert; je Sitzung entsperrt es eine **Passphrase**. Verbindungstest, Sperren, Vorgabe des Mandanten, mehrere Paperless je Nutzer.
  - **Tags auswählen**: Tags des eigenen Paperless-Kontos ansehen und die gewünschten im Mandanten anlegen.
  - **Arbeitsvorrat**: Belege mit dem Tag für offene Belege erscheinen als Einträge (Titel, Korrespondent, Datum, Dokumenttyp, Beleglink). Nach dem Buchen oder Verwerfen entfernt kumero das Tag in Paperless, bei Storno setzt es es wieder.
  - **Buchungsmaske**: Der Beleglink wird am Original geprüft – MD5 und SHA-256 stehen am Entwurf, der Verweis gilt als geprüft. Vorschau neben dem Feld.
  - **Buchungsliste** und festgeschriebene Buchung: Vorschau des Belegs.
  - Aktivieren je Mandant: `bin/kumero modul:aktivieren paperless MANDANT` (braucht `arbeitsvorrat`).
- **Modul `arbeitsvorrat` 0.1.1**: Quellen bekommen die Anfrage und die Auskunft über ihre Beiträge; der Abgleich liest erst den Stand der Buchungen, dann die Quellen; Einträge einer Lieferung behalten ihre Reihenfolge (Migration `0002_eingang.sql`).
- **Modul `buchen` 0.2.3**: Vorschau und Belegprüfung, wenn das Modul `paperless` aktiv ist; ohne das Modul unverändert.

### Behoben
- In der Karte „Aus dem Arbeitsvorrat“ stand unter den Angaben das Wort „null“.

### Noch offen
- **Nicht gegen ein echtes Paperless geprüft**, nur gegen einen nachgebauten Server; die Aufrufe folgen der Dokumentation von Paperless-ngx.
- Entsperren per Passkey (WebAuthn PRF), Livelog, Rückschreiben der Buchungsdaten nach Paperless (F49), Quelle nopaper.
- Festlegungen aus der Umsetzung in PAPERLESS.md Abschnitt 10 sind zu bestätigen.
- Tests: SQL 203, PHP 696, Browser 10 + 49 + 21 + 14 Prüfungen; gelaufen auf PHP 8.3 / PostgreSQL 16.

## [0.7.3] – 2026-11-17

### Neu
- **Modul `arbeitsvorrat` 0.1.0** (ARBEITSVORRAT.md, F89): der **Posteingang der Buchhaltung** – alles, was noch zu buchen ist, unabhängig von der Quelle.
  - **Einträge** mit eigener ID; Pflicht ist nur der Betreff. **Einträge von Hand** über „Neu“.
  - **Vertrag für Quellen**: einliefern und zurückziehen (`Kumero\Modul\Arbeitsvorrat\Dienst`), befragte Quellen über `arbeitsvorrat_abgleich`. Beiträge mit demselben Beleglink landen im selben Eintrag; der zuerst eingegangene Wert gilt, Abweichungen stehen als Hinweis.
  - **Liste** mit den Ansichten Offen / In Arbeit / Gebucht / Verworfen, Filter nach Quelle, Suche, Tastatur wie in der Buchungsliste, **Zähler im Menü**.
  - **Buchen aus dem Eintrag**: Die Buchungserfassung ist vorbefüllt (Datum, Betrag, Belegnummer, Text, Beleglink), daneben stehen die Angaben der Quellen. Nach Speichern oder Buchen springt die Maske zum nächsten offenen Eintrag; „Überspringen“ lässt einen Eintrag liegen.
  - **Zustände aus den Buchungen**: Entwurf gespeichert → in Arbeit, festgeschrieben → gebucht; Entwurf gelöscht oder Buchung storniert → wieder offen.
  - **Verwerfen** mit Pflichtgrund und **Wieder öffnen**; nichts wird gelöscht.
  - **Regeln**: Art → Geschäftsvorfall (gepflegt), Gegenpartei → Konto (beim Buchen gemerkt, einsehbar und löschbar).
  - Rechte `arbeitsvorrat.eintrag.sehen / .anlegen / .bearbeiten`, `arbeitsvorrat.regel.pflegen`; Endpunkte unter `/m/{mandant}/api/v1/arbeitsvorrat`.
  - Aktivieren je Mandant: `bin/kumero modul:aktivieren arbeitsvorrat MANDANT` (braucht `buchen`).
- **Modul-API des Kerns**: `Kumero\Api\V1\Dienste` – Stand von Buchungen und aktive Module, damit Module keine Tabellen des Kerns lesen (F44).
- **Rahmen**: Menüeinträge zeigen ihren Zähler (`zaehler` aus dem Manifest).
- **Modul `buchen` 0.2.2**: nimmt einen Eintrag des Arbeitsvorrats entgegen (`?vorrat=<id>`); bei unbarer Zahlung ohne bekannten Zahltag bleibt das Buchungsdatum leer, mit Hinweis.

### Entschieden
- **F89**: Der Arbeitsvorrat ist ein abstraktes Modul, unabhängig von der Quelle der Arbeit; Einträge gehören dem Mandanten; nopaper, Paperless und Online-Konten sind Quellen in eigenen Modulen. Das Modul `belegeingang` aus dem früheren Entwurf entfällt.
- Der Endpunkt für nopaper heißt künftig `/nopaper/sidecars/{paperless_id}` (BELEGEINGANG.md, NOPAPER-AUFTRAG.md angepasst).

### Noch offen
- Es gibt noch keine Quelle außer „von Hand“: nopaper und Paperless folgen als eigene Module.
- Festlegungen aus der Umsetzung in ARBEITSVORRAT.md Abschnitt 12 sind zu bestätigen.
- Tests: SQL 203, PHP 590, Browser 10 + 49 + 21 Prüfungen; gelaufen auf PHP 8.3 / PostgreSQL 16.

## [0.7.2] – 2026-11-17

### Neu
- **Belegverweis ist der ganze Link** (F88, ändert F10; Migration `0011_belegverweis_link.sql`): `link` statt Dokument-ID, z. B. `https://paperless.example.org/documents/5210/`. Die Datenbank erzwingt die Schreibweise (http/https, Host klein, ohne Zugangsdaten, Abfrage und Fragment) und nimmt den Link in kanonische Form und Hash auf. MD5 und SHA-256 bleiben; sie dürfen gemeinsam fehlen (ungeprüfter Verweis) – festgeschrieben wird das **nur im Testbetrieb**, im Produktivbetrieb lehnt die Datenbank ab.
- API: `belege: [{link, md5?, sha256?}]` bei Entwürfen und `POST /buchungen/{id}/belege`; Buchungen liefern zusätzlich `belege_nachgereicht`. Die bloße `paperless_id` wird abgewiesen.
- **Modul `buchen` 0.2.1**: Feld **„Beleg / Link“** in der Erfassung mit Prüfung und „öffnen“; Belege an der festgeschriebenen Buchung als Links mit Kennzeichen geprüft / ungeprüft / nachgereicht; Zeichen ↗ in der Buchungsliste. Eine Kopie beginnt ohne Beleg.

### Geändert
- Erfassung: **Buchungsdatum wieder neben dem Belegdatum**, der **Kalenderknopf sitzt im Datumsfeld** (Rückmeldung zu 0.7.1). Ein Tab-Halt je Feld bleibt.
- Ein Link in der Belegnummer wird mit Hinweis abgewiesen (er passte bis 36 Zeichen noch hinein).
- Wochentage erscheinen in der Sprache der Texte (deutsch), auch wenn das Datum einer anderen Schreibweise folgt.
- Ein doppelt nachgereichter Beleg gibt 409 mit klarer Meldung.

### Bestand
- Vorhandene Verweise mit Dokument-ID bleiben unverändert und behalten ihre kanonische Gestalt; kein Hash ändert sich. Links, die in 0.7.0/0.7.1 in der Belegnummer festgeschrieben wurden, bleiben dort stehen (festgeschrieben ist festgeschrieben).

### Noch offen
- Texte gibt es nur auf Deutsch; die Spracheinstellung bestimmt bisher nur die Schreibweise des Datums.
- Prüfsummen ermitteln, Vorschau und Tags aus Paperless: Modul `paperless`. Paperless-ID an Tags unverändert.
- Tests: SQL 203, PHP 482, Browser 10 + 49 Prüfungen; gelaufen auf PHP 8.3 / PostgreSQL 16.

## [0.7.1] – 2026-11-17

### Neu
- **Nutzermenü und „Profil und Einstellungen“** (PROFIL.md, F86): Menü am Namen oben rechts (Profil, Abmelden). Einstellungen liegen am Nutzer in der Verwaltungsdatenbank (`db/verwaltung/0002_profil.sql`): **Sprache und Schreibweise** (leer = wie der Browser), **Betragseingabe** dezimal oder kaufmännisch, **Schriftgröße**, **Kontrast**. REST: `PATCH /api/v1/ich`, `GET`/`PUT /api/v1/ich/einstellungen`. Das Protokoll nennt bei Einstellungen nur den Schlüssel, nie den Wert.
- **Modul `buchen` 0.2.0** (BUCHUNGSMASKE.md Abschnitt 8, F85):
  - **Kontenvorschau** beim Tippen von Nummer oder Bezeichnung (M9) – in Erfassung, Splitzeilen, Listenfilter, Kontoblatt und Geschäftsvorfällen.
  - **Betragseingabe kaufmännisch** auf Wunsch: 1000 wird 10,00; Rechnen im Feld in beiden Arten (M10).
  - **Datum** in der Schreibweise der Nutzersprache bzw. des Browsers, mit Kurzeingabe (`17`, `1711`) und Pfeiltasten (M11).
  - **Feste Spalten** im Formular: Bezeichnung, Hinweise und Saldo haben ihren Platz, nichts verschiebt sich (M12).
  - **Hilfen beim Überfahren** an Feldern und Knöpfen, mit Tastenkürzel (M13).
  - **Festgeschriebene Buchung** als feste Ansicht mit Schloss, Hash und Verweis auf Storno bzw. Original statt als gesperrtes Formular; Schloss-Zeichen in der Liste (M14).
  - **Ein Tab-Halt je Feld**: eigenes Datumsfeld, der Kalender ist der nächste Halt (M15).
- Baustein des Kerns `datumFeld()` und Datumshilfen in `bausteine.js`; Grundschrift der Oberfläche etwas kleiner, über die Schriftgröße im Profil einstellbar.

### Behoben
- Der Saldo des vorigen Kontos blieb stehen, wenn die Kontonummer geändert oder gelöscht wurde.
- Eine Belegnummer über 36 Zeichen wurde still abgeschnitten; jetzt Hinweis, gespeichert wird erst mit gültiger Länge.
- Geschäftsvorfälle: Felder mit und ohne Hinweiszeile stehen auf gleicher Höhe.

### Geändert
- `verwaltung.nutzer.sprache` darf leer sein; `nutzer:anlegen` setzt ohne `--sprache` keine Sprache mehr. **Bestehende Nutzer behalten `de`** und sehen deutsche Datumsangaben, bis sie im Profil „wie der Browser“ wählen.

### Entschieden
- **F85** Maskenergänzungen M9–M15, **F86** Profil erste Stufe, **F87** Entscheidungen zu Arbeitsvorrat, Paperless, nopaper und Livelog (noch nicht gebaut).

### Noch offen
- Zahlen bleiben in deutscher Schreibweise, auch wenn das Datum einer anderen Sprache folgt.
- Rest des Profils (automatische Abmeldung, Freigabe-Passkeys, Anrede, Sitzungen), Livelog, Belegverweis als Link, Module `paperless` und `belegeingang`, M16/M17: folgende 0.7.x.
- Tests: SQL 194, PHP 467, Browser 10 + 45 Prüfungen; gelaufen auf PHP 8.3 / PostgreSQL 16, nicht auf dem Zielstand PHP 8.5 / PostgreSQL 19.

## [0.7.0] – 2026-11-17

### Neu
- **Buchen über REST** (F78, F83): Endpunkte des Kerns für Buchungen – Entwürfe anlegen, ersetzen, löschen (mit `ETag` / `If-Match`), vorbereiten, zurücksetzen, Storno als Entwurf, Beleg nachreichen –, für die **Freigabe** (`POST /freigaben/challenge` mit dem anzuzeigenden Inhalt, `POST /freigaben` einzeln oder als Stapel) und für die Abfragen **Journal** (Filter, Cursor, `reihenfolge`), **Salden** und **Kontoblatt**. Regeln prüft weiterhin die Datenbank; die Anwendung prüft nur die Form der Eingabe und die WebAuthn-Signatur der Freigabe (F72).
- **Freigabe**: im Testbetrieb ohne Assertion (Testbuchung, F59); mit Assertion prüft die Anwendung Signatur, Origin, RP-ID und Nutzerverifikation gegen die Challenge aus der Datenbank (F68). Stornos brauchen `kern.buchung.stornieren`, alle anderen `kern.buchung.festschreiben`.
- **Modul `buchen` 0.1.0** (BUCHUNGSMASKE.md, M1–M8): **Buchungsliste** (Ansichten Stapel / Journal / Alle, Werkzeugleiste, Kontrollsumme, Stapelfreigabe, Storno, Splitbuchungen aufklappbar) und **Buchungserfassung** (Konto / Gegenkonto mit Saldo, Rechnen im Betragsfeld, Belegnummer hoch- und herunterzählen, Buchungstext mit Vorschlägen, Zeilentabelle für Splitbuchungen, Speichern = Stapel, Buchen = festschreiben), **Kontoblatt** mit laufendem Saldo, **Geschäftsvorfälle** als gepflegte Vorlagen (Schema `buchen`, Rechte `buchen.vorlage.sehen` / `.pflegen`, sieben mitgelieferte allgemeine) und die Seite **„Buchen einrichten“** (Geschäftsjahre, Buchungsarten mit Nummernkreis, Nummernvorlagen, Tags). Alles mit der Tastatur bedienbar; Texte aus `sprachen/de.json`.
- Aktivieren je Mandant: `bin/kumero modul:aktivieren buchen MANDANT`.
- Oberfläche des Kerns: `api()` kann Kopfzeilen senden und zurückgeben (`kopf`, `mitKopf`); `passkeyBestaetigen()` für Freigaben.
- Vertrag `openapi/v1.yaml`: `reihenfolge` bei `GET /buchungen`, `konto` bei `GET /salden`, Antwort 428 ohne `If-Match`, Endpunkte des Moduls `buchen`.
- Tests: PHP 447 Prüfungen (neu: `tests/BuchenTest.php` mit 204), Browsertest `tests/browser/buchen.mjs` mit 27 Prüfungen.

### Entschieden
- **F83**: M1–M8 freigegeben; die Maske ist ein eigenes Modul `buchen`, die Endpunkte für Buchungen bleiben im Kern. Reihenfolge: 0.7.0 Buchen, 0.8.0 Zuordnung zur Anlage EÜR.
- **F84**: Festlegungen aus der Umsetzung von 0.5.0 (ANWENDUNG.md 10a, Punkte 1–8) bestätigt.

### Noch offen
- Festlegungen aus der Umsetzung in BUCHUNGSMASKE.md Abschnitt 7 sind zu bestätigen.
- Freigabe-Passkeys im Mandanten registrieren (`/passkeys`), Jahressperre und Wiederöffnen über REST.

## [0.6.4] – 2026-11-17

### Neu
- **Kontoart aus dem Bilanz-/GuV-Posten** (F81, Modul `kontenrahmen` 0.1.3): eigener Leser für die aktuellen DATEV-Kontenrahmen mit Posten-Spalte (SKR 03/04 nach BilRUG) über Wortpositionen und Kastenlinien (`pdftotext -bbox-layout`, `pdftocairo -svg`). Posten → Kontoart nach §§ 266, 275 HGB (`posten/hgb.json`); Posten und Programmverbindung stehen am Konto.
- Regelwerk **`skr04`**; die Regelwerke sind nur noch Rückfall und Gegenprüfung. Widersprechen sich Posten und Nummernbereich, ist das Konto zu bestätigen.
- Von DATEV reservierte Konten (R) werden ausgelassen; Bereichsenden mit vier Stellen, Kennzeichen `S/AV`/`S/AM`, Bereichsfunktionen mit Fußnote und zwei nebeneinander werden gelesen.
- Oberfläche: Herkunft der Kontoart mit Posten („aus Posten …“).
- Tests: PHP 243 Prüfungen (neu: Muster-PDF mit Posten-Spalte, Posten-Zuordnung).

## [0.6.3] – 2026-11-17

### Neu
- **Regelwerk `skr03`** (Modul `kontenrahmen` 0.1.2): Kontoart nach Nummernbereichen des SKR 03; unsichere Bereiche (ausstehende Einlagen, Rücklagen, Verrechnungskonten, durchlaufende Posten, Bestände, Bestandsveränderungen, kalkulatorische Kosten) werden zur Bestätigung angezeigt, Klasse 5/6 bleibt offen, Klasse 9 wird ausgelassen.
- Import: Regelwerk **„automatisch aus dem PDF“** (Rahmen aus der Kopfzeile, z. B. „SKR 03“); passt das gewählte Regelwerk nicht zum erkannten Rahmen, steht ein Hinweis im Prüfbericht.
- Tests: PHP 220 Prüfungen.

## [0.6.2] – 2026-11-17

### Neu
- **TLS-Zertifikat von außen** (z. B. ACME-Client der OPNsense, Let's Encrypt): `ZERTIFIKAT_QUELLE` in `install.conf`. `kumero-zertifikat` prüft ein hochgeladenes Zertifikat (lesbar, nicht abgelaufen, gilt für `SERVERNAME`, Schlüssel passt), übernimmt es nach `/etc/kumero/tls/`, prüft `nginx -t` und lädt nginx neu – sonst bleibt das alte. Ausgelöst von einer Path-Unit bei jedem Upload und täglich per Timer; Meldungen im Journal (`-t kumero-zertifikat`).
- nginx liest das Zertifikat immer aus `/etc/kumero/tls/` (anfangs eine Kopie von „snakeoil“).
- `install.sh` warnt, wenn `adresse` in `/etc/kumero/kumero.php` nicht zu `install.conf` passt, und nennt den Befehl zum Umstellen.
- `pruefen.sh`: Zertifikatsquelle, ausgeliefertes Zertifikat und Überwachung.

## [0.6.1] – 2026-11-17

### Neu
- **Weboberfläche: Rahmen und Menü** nach OBERFLAECHE.md (F80): Kopfleiste mit Mandantenwahl, Navigation links mit festen Bereichen (Start, Buchen, Stammdaten, Auswertungen, Abschluss, Einstellungen, Verwaltung), Seiten als ES-Module, gemeinsame Bausteine (Liste mit Tastaturführung, Reiter, Kacheln, Dialog). Einstieg `/app.html`; die alte Testseite `start.html` leitet dorthin weiter (Registrierungslinks bleiben gültig).
- **Menü aus den Manifesten**: Abschnitt `oberflaeche` in `modul.json` (Einträge, Seiten, Texte, Symbole), geprüft beim Lesen des Manifests; sichtbar nur bei aktivem Modul und Recht aus den Rollen (keine Rechte an Nutzern); systemweit `angemeldet` und `verwaltung.verwalter`. Wechselnde Unterpunkte über den Erweiterungspunkt `menue_unterpunkte`. Endpunkte `GET /api/v1/oberflaeche/menue` und `GET /m/{mandant}/api/v1/oberflaeche/menue`.
- **REST-Endpunkte der Module** über `Kumero\Api\V1\RoutenAnbieter`; auf Mandantenpfaden nur, wenn das Modul im Mandanten aktiv ist (`modul.nicht_aktiv`). Systemweite Endpunkte mit Recht `verwaltung.verwalter`.
- **Kontenrahmen-Import in der Weboberfläche** (Modul `kontenrahmen` 0.1.1): PDF oder CSV hochladen → Entwurf mit Prüfbericht → Kontoart wählen oder bestätigen, Bezeichnung ergänzen, Konten weglassen → als Vorlage speichern → im Mandanten anwenden; Vorlagen erscheinen als Unterpunkte im Menü. Seiten „Kontenrahmen anwenden“ und „Angewendete Kontenrahmen“ unter Stammdaten → Konten. Die hochgeladene Datei wird nicht aufbewahrt, nur ihr SHA-256.
- Seiten des Kerns: Übersicht und Kontenliste (mit Anlegen).
- nginx: strenge Content-Security-Policy (nur eigene Quellen, kein Inline-Code), Auslieferung der Modulseiten unter `/module/<name>/<version>/`.
- Installer: `/var/lib/kumero/kontenrahmen` jetzt `2770 root:kumero` (Import aus der Oberfläche), Unterverzeichnis `entwuerfe`.
- Werkzeuge: `werkzeuge/entwicklungsserver.php` (eingebauter PHP-Server wie nginx samt CSP); Browsertest `tests/browser/import.mjs` mit virtuellem Passkey in Chrome.
- OpenAPI: Tags „Oberfläche“ und „Kontenrahmen“ (39 Pfade, 51 Operationen).
- Tests: PHP 209 Prüfungen (neu: Menü, Manifestprüfung, Import über REST, Sichtbarkeit nach Rollen); Browser 10 Prüfungen.

### Geändert
- `kontenrahmen:einlesen` nutzt denselben Einlesepfad wie die Oberfläche (`Einlesen`).
- Registrierungslinks zeigen auf `/app.html#registrierung=…`.

### Noch nicht enthalten
- Buchen und Buchungsmaske (BUCHUNGSMASKE.md, 0.7.0); Zuordnung zur Anlage EÜR danach.
- Einstellungsabschnitte und Zähler im Menü (Format vorgesehen).

## [0.6.0] – 2026-11-17

### Neu
- **Modulrahmen** (KONTENRAHMEN.md K6): Manifest `module/<name>/modul.json`, Migrationen je Modul (`db/migrieren.sh --modul`, in `meta.migration` mit Präfix), `bin/kumero modul:liste` und `modul:aktivieren` (Migrationen, Registrierung, Rechte, Bausteine, Ergänzung der Rollen). `install/aktualisieren.sh` spielt die Migrationen aktiver Module je Mandant mit ein.
- **Modul `kontenrahmen` 0.1.0** (F75, F76):
  - PDF-Konverter für gedruckte DATEV-Kontenrahmen (`pdftotext -layout`): zwei Spalten, Kontenklassen, Untergruppen, Funktionskennzeichen, umbrochene Beschriftungen, Kontenbereiche, Fußnoten; Personenkonten werden übergangen, unsichere Zeilen kommen in den Prüfbericht.
  - CSV-Konverter und Ergänzung per CSV (Kontoart setzen, Konten ergänzen oder weglassen), UTF-8 oder Windows-1252.
  - Eigenes Regelwerk `skr81`: Kontoart nach Nummernbereichen; unsichere Bereiche werden zur Bestätigung angezeigt, Vortrags- und statistische Konten ausgelassen.
  - Vorlagen im Format `kumero-kontenrahmen/1`, installationsweit unter `/var/lib/kumero/kontenrahmen/` (K2), mit SHA-256 über die kanonische Form.
  - Anwenden auf einen Mandanten über `kern.konto_anlegen`; vorhandene Konten bleiben, abweichende Kontoarten werden als Konflikt gemeldet; Vermerk im Schema `kontenrahmen` und in der Protokollkette (F75 Nr. 6).
  - Kommandos `kontenrahmen:einlesen`, `kontenrahmen:vorlagen`, `kontenrahmen:zeigen`, `kontenrahmen:anwenden`.
  - Synthetischer Referenz-Kontenplan `referenz-skr81.csv` (F75 Nr. 3).
- `src/Plattform/Jcs.php`: kanonische Form nach RFC 8785 in PHP, gleich `kern.jcs`.
- Installer: Paket `poppler-utils`, Verzeichnis `/var/lib/kumero/kontenrahmen`. `pruefen.sh` prüft die Anwendung über `/api/v1/ich`, den Tunnel-Zugang und `kumero.php`; `selbsttest` prüft `pdftotext`.
- Tests: PHP 123 Prüfungen (neu: Kontenrahmen mit einem selbst erzeugten PDF im DATEV-Aufbau und erfundenen Konten).

### Noch nicht enthalten
- Zuordnung der Konten zur Anlage EÜR je Geschäftsjahr und Beitrag zur Jahressperre: Modul `kontenrahmen` 0.2.0 mit kumero 0.7.0.

## [0.5.0] – 2026-11-17

### Neu
- **PHP-Anwendung** nach ANWENDUNG.md (E8 c): Front-Controller, Weg einer Anfrage mit Anmeldung, Mandant, Wasserstand (F69), Recht (F41), Idempotenz (F71) und einer Transaktion je Aufruf; Fehler als Problem Details mit den Codes `KM001`–`KM006`.
- **Anmeldung per Passkey** (E2) mit eigener WebAuthn-Prüfung (E3) für ES256 und EdDSA; Registrierungslinks, Sitzungen in der Verwaltungsdatenbank, Cookie `__Host-kumero`, Herkunftsprüfung gegen CSRF.
- **REST-API** (openapi/v1.yaml, erste Stufe): Anmeldung, Registrierung, Profil; Konten, Tags, Nummernkreise samt Festlegung je Geschäftsjahr, Buchungsarten, Geschäftsjahre.
- **Kommandozeile** `bin/kumero`: `nutzer:anlegen`, `nutzer:link`, `nutzer:liste`, `mandant:anlegen`, `mandant:eintragen`, `mandant:liste`, `zugang:gewaehren`, `selbsttest`.
- **Testseite** `start.html`: Passkey registrieren, anmelden, Konten ansehen und anlegen.
- **Kern** `0010_verzoegerte_trigger.sql`: Die verzögerte Prüfung der lückenlosen Geschäftsjahre (F65) meldet als Anwendung jetzt `KM002` statt „keine Berechtigung“.
- **Installer:** `/etc/kumero/kumero.php`; Option `ENTWICKLUNG_TUNNEL` (nginx nur auf 127.0.0.1:8080 für den SSH-Tunnel); `/var/lib/kumero` 0751. `aktualisieren.sh` schaltet `/var/www/kumero` nach erfolgreicher Migration auf das neue Release um (E7), lädt PHP-FPM neu und führt `bin/kumero selbsttest` aus.
- **Release** enthält `vendor/` nach `composer.lock` (E1): Symfony 8.1, Doctrine DBAL 4.6, bezogen direkt aus den Git-Repositorys der Projekte; Archiv reproduzierbar.
- Tests: Datenbank 194 Prüfungen, PHP 85 Prüfungen (WebAuthn mit Software-Authentifikator, Ende-zu-Ende gegen PostgreSQL).

### Definition
- F78: REST-API v1 – Freigabe in drei Schritten, Storno als Entwurf, Bots geben nie frei.

 – 2026-11-16

### Behoben
- `install/aktualisieren.sh` übergibt nach Prüfung von Signatur und Prüfsumme an das Skript aus dem geprüften Release. Bisher lief der ganze Ablauf mit der aufgerufenen, womöglich älteren Kopie; bei 0.4.0 wurde dadurch die Verwaltungsdatenbank nicht migriert, wenn das Skript aus einem alten Checkout gestartet wurde.
- `db/migrieren.sh` vermerkt eine Version in `meta.version` nur einmal je Release, auch wenn das Update wiederholt wird.

Am Kern (Datenbank) ändert sich nichts.

## [0.4.0] – 2026-11-14

### Neu
- **Rechte und Rollen** (`0008_rechte.sql`, F41–F43): Pflege nur über Funktionen mit Protokoll; Rechtekatalog des Kerns und die sieben Rollenvorlagen aus F42 (`kern.rollenvorlagen_anlegen`); effektive Rechte samt Einschränkungen (`kern.rechte_des_subjekts`). Die Invarianten aus F43 erzwingt die Datenbank beim Commit: Notfall nur für Verantwortliche, Prüfer nur lesend, Administrator ohne Buchungsdaten.
- **Integration** (`0009_integration.sql`): Idempotenz nach F71 mit neuem Fehlercode `KM006`; Protokoll und Ereignisse von Modulen (`kern.modul_protokoll`); Empfänger im Prozess mit Lesestand je Modul; Webhook-Abos mit Protokoll; Zustellung mit Wiederholung.
- **Verwaltungsdatenbank** (`db/verwaltung/0001_verwaltung.sql`, F37, F69): Mandanten, Nutzer, Anmelde-Passkeys, Registrierungslinks, Zugänge, Dienstkonten, Tokens, Sitzungen, Wasserstände, eigene Protokollkette. Geheimnisse nur als SHA-256.
- `db/migrieren.sh --verwaltung`; `install/aktualisieren.sh` spielt die Verwaltungsdatenbank mit ein.
- Tests: 192 Prüfungen.

### Definition
- F76: ohne Steuerberater vollständig; erster Kontenrahmen SKR 81.
- F77: `ANWENDUNG.md` (PHP-Gerüst und Modul-API) freigegeben, Entscheidungen E1–E8.

## [0.3.0] – 2026-11-14

### Neu
- **Beitrag zur Jahressperre** (`db/migrationen/0007_gj_beitraege.sql`, F75 Nr. 11): neuer Erweiterungspunkt `gj_sperren_beitrag`.
  - Module liefern vor der Sperre den Hash ihres Datenstands für das Jahr (`kern.gj_beitrag_setzen`), z. B. die EÜR- und E-Bilanz-Zuordnung.
  - Der Kern nimmt die Beiträge in den freigegebenen und gesiegelten Sperrinhalt auf, verweigert die Sperre bei fehlendem oder veraltetem Beitrag und hält die Beiträge danach unveränderlich.
- Tests: 127 Prüfungen.

### Definition
- F75: Modul `kontenrahmen` – Konverter für alle Kontenrahmen (DATEV-PDF, DATEV-Kontenbeschriftungen, CSV); jeder Nutzer entscheidet selbst, was er konvertiert; gefüllter Referenz-Kontenrahmen im Repository; Zuordnung zu Anlage EÜR und E-Bilanz je Geschäftsjahr; Zuordnung wird mit der Jahressperre eingefroren; Kontenplan in Verfahrensdokumentation und Prüferexport (GoBD).

## [0.2.1] – 2026-11-14

### Behoben
- Debian 13 hat `gpgv` nicht mehr vorinstalliert (apt nutzt `sqv`). Der Installer installiert das Paket jetzt in Schritt 10 mit.
- `aktualisieren.sh` prüft vorher, ob `gpgv` vorhanden ist, und zeigt bei einer fehlgeschlagenen Prüfung die Meldung von `gpgv` an.
- `pruefen.sh` prüft zusätzlich, ob `gpgv` installiert und ein Freigabe-Schlüssel hinterlegt ist.

Am Kern (Datenbank) ändert sich nichts.

## [0.2.0] – 2026-11-14

### Neu
- **DDL Teil 2** (`db/migrationen/0006_funktionen.sql`): Schreibfunktionen des Kerns. Die Anwendung schreibt ausschließlich über sie (F64).
  - Stammdaten: Buch, Konto, Tag, Nummernkreis, Buchungsart, Geschäftsjahr, Nummernkreis festlegen, Modul, Erweiterungsattribut
  - Entwürfe: anlegen, ändern (mit ETag), löschen, jeweils mit Vorher-/Nachher-Stand im Protokoll
  - Vorbereiten mit strenger Regelprüfung (F4, F12, F24, F58), Prüfvermerke der Module (F72)
  - Challenge ausstellen; Festschreiben einzeln oder als Stapel mit WebAuthn-Freigabe (F38, F66–F68)
  - Storno, Beleg nachreichen, Geschäftsjahr sperren und im Notfall öffnen, Produktivbetrieb einschalten
  - `kern.kette_pruefen()`: rechnet beide Ketten unabhängig nach
- **Versions- und Genehmigungsablauf (F73):** `VERSION`, `CHANGELOG.md`, `werkzeuge/release-bauen.sh`, `install/aktualisieren.sh`. `db/migrieren.sh` verweigert Änderungen am installierten Kern ohne genehmigtes Release.
- Tests: 118 Prüfungen, darunter Manipulationserkennung und echte Parallelität (F71).

## [0.1.0] – 2026-11-14

### Neu
- Definition (AGENTS.md F1–F72), abstraktes Datenmodell (Fassung 3)
- Installer für Debian 13 (PostgreSQL 19 aus PGDG, PHP 8.5 aus sury, nginx, unattended-upgrades)
- DDL Teil 1: Schema `kern`, Constraints, Schutz-Trigger, kanonische Form nach RFC 8785
