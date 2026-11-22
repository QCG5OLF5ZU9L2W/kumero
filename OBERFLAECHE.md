# OBERFLAECHE.md – Rahmen der Weboberfläche und Einbindung der Module ins Menü (Fassung 1, freigegeben 2026-11-17 → F80)

Stand: 2026-11-17. Entscheidungen in Abschnitt 7 freigegeben (F80); umgesetzt ab 0.6.1.
Bezug: F18 (Oberfläche nutzt nur die API), F39 (Mandant im Pfad), F41 (Rechte), F44/F46 (Module),
F73 (signierte Releases), T5 (mehrsprachig), BUCHUNGSMASKE.md (erste Maske im Rahmen).

## 1. Vorbild Nextcloud – was wir übernehmen

Nextcloud-Apps melden ihre Menüeinträge **deklarativ** in `appinfo/info.xml` an
(`<navigations>` mit Name, Route, Symbol, Reihenfolge, Typ) und ihre Einstellungsseiten als
Abschnitte (`<settings>`). Der Server baut daraus das Menü; sichtbar ist nur, was für den Nutzer
aktiviert ist. Jede App liefert ihr eigenes JavaScript, der Rahmen (Kopfzeile, Menü, Einstellungen)
gehört dem Server.

Übernommen:
- **Deklaration im Manifest** statt Code: Installer, `bin/kumero` und der Kern lesen das Menü, ohne
  Modulcode auszuführen (wie schon `modul.json` nach ANWENDUNG.md 7.1).
- **Feste Reihenfolgezahl** mit Lücken (10, 20, 30 …), damit Module sich einsortieren können.
- **Einstellungen als Abschnitte** in einer gemeinsamen Einstellungsseite statt eigener Menüpunkte.
- **Rahmen gehört dem Kern**, das Modul liefert nur den Inhalt seiner Seiten.

Anders als Nextcloud:
- Sichtbarkeit hängt an **Rollen** (F41), nicht an Gruppen – und am Aktivsein **im Mandanten**.
  Ein Menüeintrag nennt nur das Recht, das er braucht; **wer** es hat, entscheidet allein das
  Rollenkonzept: Recht → Baustein → Rolle → Zuweisung an Nutzer oder Dienstkonto
  (`kern.zuweisen`). Rechte werden nie direkt an Nutzer vergeben, auch nicht fürs Menü.
- Kein frei wählbarer Hauptbereich: Module hängen sich in **feste Bereiche** (Abschnitt 2), damit das
  Menü bei vielen Modulen geordnet bleibt.
- Die **festen** Einträge eines Moduls stehen deklarativ im Manifest, beliebig verschachtelt.
  **Wechselnde Unterpunkte** (z. B. gespeicherte Auswertungen) liefert das Modul selbst über einen
  Erweiterungspunkt – ähnlich Nextcloud `INavigationManager`, aber nur unterhalb seiner eigenen
  Einträge (Abschnitt 3a). Dazu kommt optional ein **Zähler** (z. B. „Stapel 3“).
- Keine fremden Quellen: Skripte, Schriften und Symbole nur vom eigenen Server (Art. 9 DSGVO,
  keine Telemetrie).

## 2. Aufbau des Rahmens (im Stil von Sage 50)

```
┌ Kopf: kumero · Mandant ▾ · Geschäftsjahr ▾ · Suche · Nutzer ▾ ────────────────┐
├ Seitenleiste (Bereiche) ┬ Inhalt der Seite (Modul oder Kern) ─────────────────┤
│ Start                    │                                                     │
│ Buchen      ▸ Stapel (3) │                                                     │
│             ▸ Journal    │                                                     │
│ Stammdaten  ▸ Konten …   │                                                     │
│ Auswertungen             │                                                     │
│ Abschluss                │                                                     │
│ Einstellungen            │                                                     │
│ Verwaltung (system)      │                                                     │
└──────────────────────────┴─────────────────────────────────────────────────────┘
```

Feste Bereiche (Schlüssel → Anzeige): `start`, `buchen`, `stammdaten`, `auswertungen`,
`abschluss`, `einstellungen`, `verwaltung`. `verwaltung` ist systemweit (Mandanten, Nutzer,
Kontenrahmen-Vorlagen), alle anderen gelten im gewählten Mandanten (`/m/{mandant}/…`, F39).
Ein Bereich ohne sichtbaren Eintrag wird ausgeblendet.

**Seit 0.7.27 (F122):** `buchen` wird als **„Buchführung“** angezeigt. Der Abschluss gehört zur Buchführung –
„Siegel“ und später z. B. Steuerverprobung und Jahressperre stehen im Bereich `buchen`; `abschluss` hat keinen
Eintrag mehr. **Jeder Eintrag trägt ein Symbol** (`symbol`, Pflicht, auch bei Unterpunkten): SVG, 16 × 16, nur
Linien, Strichstärke 1,5, Farbe `#2a6f97`. Wechselnde Unterpunkte tragen das Symbol ihres Eintrags.

## 3. Manifest: Abschnitt `oberflaeche`

```json
"oberflaeche": {
  "menue": [
    {
      "id": "kontenrahmen.vorlagen",
      "bereich": "verwaltung",
      "titel": "menue.vorlagen",
      "ziel": "vorlagen",
      "symbol": "symbole/kontenrahmen.svg",
      "reihenfolge": 40,
      "recht": "kontenrahmen.vorlage.anwenden"
    },
    {
      "id": "kontenrahmen.angewendet",
      "bereich": "stammdaten",
      "eltern": "kern.konten",
      "titel": "menue.angewendet",
      "ziel": "angewendet",
      "reihenfolge": 20,
      "recht": "kontenrahmen.angewendet.sehen",
      "zaehler": null
    }
  ],
  "seiten": {
    "vorlagen":   "vorlagen.js",
    "angewendet": "angewendet.js"
  },
  "einstellungen": [
    {"id": "kontenrahmen.allgemein", "ebene": "mandant", "titel": "einst.titel",
     "seite": "einstellungen.js", "recht": "kontenrahmen.vorlage.anwenden",
     "reihenfolge": 50}
  ],
  "texte": "sprachen/"
}
```

Regeln (geprüft in `Kumero\Plattform\Menue::pruefen`, bei jedem Lesen des Manifests):
- Seiten liegen unter `module/<name>/oeffentlich/`; `seiten` nennt Dateien relativ dazu, ebenso
  `symbol` (SVG). Texte unter `oeffentlich/sprachen/<sprache>.json`.
- Systemweite Bereiche (`start`, `verwaltung`) kennen kein Rollenkonzept; dort gelten nur
  `angemeldet` und `verwaltung.verwalter` (seit 0.7.22: nur die Sitzung eines System-Verwalters – eine eigene Identität der Installation, F114; nie Dienstkonten, nie Nutzer eines Mandanten).
- Ein Eintrag, dessen Eltern-Eintrag nicht sichtbar ist, entfällt mit.
- `id` beginnt mit dem Modulnamen (Namensraum wie bei Rechten und Erweiterungsattributen, F58).
- `eltern` nur auf einen Eintrag eines Moduls, von dem das Modul abhängt (`abhaengigkeiten`), oder
  des Kerns – so entstehen keine Kreise (F44).
- `recht` ist Pflicht und nennt einen **Rechte-Code**, keinen Nutzer und keine Rolle. Das Recht muss
  vom Modul selbst oder einer Abhängigkeit stammen. Welche Rollen es enthalten, pflegt der
  Verantwortliche in Bausteinen und Rollen; die Rollenvorlagen des Moduls schlagen eine Belegung vor.
- Unterpunkte entstehen über `eltern` (beliebige Tiefe, angezeigt bis 3 Ebenen) oder über
  `unterpunkte` (Abschnitt 3a).
- `titel` ist ein Textschlüssel; Übersetzungen liegen unter `texte` (T5).
- `zaehler` (optional): relativer GET-Pfad der eigenen REST-API, Antwort `{"anzahl": n}`.
- `modul:aktivieren` prüft das alles und lehnt ein fehlerhaftes Manifest ab (wie heute die Rechte).

### 3a. Unterpunkte, die das Modul selbst steuert

Beispiel Modul `auswertungen`: Fest im Manifest steht nur der Eintrag „Auswertungen“ mit den Gruppen
„Standard“ und „Eigene“. Welche Auswertungen darunter erscheinen, weiß nur das Modul – neue
Berichte, gespeicherte Filter, eigene Auswertungen des Mandanten, Berichte weiterer Module.

```json
{"id": "auswertungen.eigene", "bereich": "auswertungen", "titel": "menue.eigene",
 "reihenfolge": 20, "recht": "auswertungen.sehen", "unterpunkte": true}
```

- `"unterpunkte": true` meldet, dass das Modul für diesen Eintrag den Erweiterungspunkt
  `menue_unterpunkte(Kontext, eintragId)` umsetzt (F46, im selben Prozess, nur lesend).
- Rückgabe: Liste von Einträgen im selben Format (`id`, `titel` als fertiger Text oder Textschlüssel,
  `ziel` mit Parametern, `recht`, `reihenfolge`, wieder `unterpunkte` für tiefere Ebenen).
- Der Kern prüft: `id` im Namensraum des Moduls, Einhängen nur unter eigenen Einträgen, Recht
  angemeldet. Dann **filtert er jeden Unterpunkt selbst nach den Rollen des Subjekts** – das Modul
  kann damit nichts sichtbar machen, was das Rollenkonzept nicht erlaubt.
- So kann jede Auswertung ihr eigenes Recht haben (`auswertungen.bwa.sehen`,
  `auswertungen.eigene.sehen` …), und der Verantwortliche bündelt sie in Rollen, z. B. eine Rolle
  „Praxismanagerin“ mit BWA, aber ohne Personalkosten.
- Gesteuert wird über das Modul: Legt jemand im Modul eine Auswertung an oder blendet sie aus,
  erscheint oder verschwindet der Unterpunkt beim nächsten Laden des Menüs, ohne Manifest-Änderung
  und ohne Release.
- Fehler im Erweiterungspunkt: Der Eintrag erscheint ohne Unterpunkte, der Fehler steht im
  Server-Log (ohne Inhalte), das übrige Menü bleibt intakt. Ungültige Unterpunkte werden übergangen.

Der Kern trägt seine eigenen Einträge (`kern.stapel`, `kern.journal`, `kern.konten` …) im selben
Format in ein Kern-Manifest ein – er ist auch hier nur ein Modul (ANWENDUNG.md 7.2).

## 4. Wie das Menü entsteht

1. `GET /api/v1/oberflaeche/menue` (systemweit) bzw. `GET /m/{mandant}/api/v1/oberflaeche/menue`.
2. Der Kern sammelt die Einträge des Kerns und aller **im Mandanten aktiven** Module, behält nur die,
   deren Recht in den Rollen des Subjekts enthalten ist (`rechte_des_subjekts`, Stichtag jetzt),
   holt Unterpunkte nach 3a, löst `eltern` auf, sortiert nach `reihenfolge`, dann
   `id`, übersetzt die Titel in die Sprache der Sitzung.
3. Antwort: Baum je Bereich mit `id`, `titel`, `pfad` (`#/m/spiel/kontenrahmen/angewendet`; unter der eigenen Domain
   eines Mandanten ohne ihn: `#/b/haupt/kontenrahmen/angewendet`, F181),
   `symbol`-URL, `zaehler`-URL. Das Ergebnis wird je Subjekt/Mandant/Sprache/Modulstand
   zwischengespeichert.
4. **Ausblenden ist kein Schutz:** Jede Seite ruft nur REST-Endpunkte auf, die das Recht selbst
   prüfen (Kernel, ANWENDUNG.md 3). Ein Eintrag ohne Recht fehlt nur zur Übersicht.

## 5. Wie eine Modulseite geladen wird

- Dateien unter `module/<name>/oeffentlich/` sind Teil des signierten Releases (F73) und werden von
  nginx statisch unter `/module/<name>/<version>/…` ausgeliefert (Version im Pfad = sauberes
  Zwischenspeichern nach Updates).
- Der Rahmen lädt eine Seite per `import()` (ES-Modul). Jede Seite exportiert
  `zeigen(behaelter, kontext)`; `kontext` enthält Mandant, Geschäftsjahr, Sprache, eine
  `api(pfad, optionen)`-Funktion mit Cookie, CSRF-Schutz und Idempotenzschlüssel (F18) sowie
  `darf(recht)`.
- **Gemeinsame Bausteine vom Kern** (`/kern/ui/…`): Tabelle mit Spaltenwahl und Tastaturführung,
  Formular, Kontofeld mit Saldo, Betragsfeld mit Rechnen, Datumsfeld mit Wochentag, Dialog, Meldung.
  Daraus entsteht das einheitliche Bild nach BUCHUNGSMASKE.md, auch für Module.
- **Content-Security-Policy:** `default-src 'self'; script-src 'self'; style-src 'self';
  img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'` – kein Inline-Skript, keine
  fremde Quelle, auch nicht für Module.

## 5a. Umsetzung 0.6.1

- Rahmen: `public/app.html`, `public/kern/ui/rahmen.js` (Router `#/system/<modul>/<ziel>` und
  `#/m/<mandant>/<modul>/<ziel>`; im Buch `#/m/<mandant>/b/<buch>/<modul>/<ziel>`; **unter der eigenen Domain eines
  Mandanten ohne `/m/<mandant>`** – `#/<modul>/<ziel>` und `#/b/<buch>/<modul>/<ziel>`, VERWALTUNG.md 18, F181. Den
  Anfang bildet allein `mandantAnfang()`; Seiten nehmen `kontext.apiBasis`, `mandantApi`, `buchApi`, `seitenBasis` und
  für den Sprung in ein anderes Buch `buchSeiten(buch)` – nie `/m/…` von Hand), Bausteine in `public/kern/ui/bausteine.js`, Gestaltung in
  `rahmen.css`. Seiten des Kerns: `public/kern/seiten/` (Übersicht, Konten).
- REST-Endpunkte der Module über die Schnittstelle `Kumero\Api\V1\RoutenAnbieter` der
  Einstiegsklasse (`einstieg` im Manifest); auf Mandantenpfaden prüft der Kern, dass das Modul aktiv ist.
- Einstellungsabschnitte und Zähler sind im Format vorgesehen, aber noch nicht umgesetzt.
- Entwicklung und Browsertest: `werkzeuge/entwicklungsserver.php` bildet nginx samt CSP nach;
  `tests/browser/import.mjs` prüft den Ablauf mit virtuellem Passkey in Chrome.

## 6. Module und Plugins

Ein Begriff reicht: **Modul**. „Plugin“ meint bei uns ein Modul, das nicht im kumero-Release
mitkommt (eigenes oder fremdes). Technisch gleich (Manifest, Rechte, Menü). Unterschied nur in der
Herkunft: Ein solches Modul muss mit einem Schlüssel signiert sein, den der Verantwortliche
ausdrücklich zugelassen hat (Schlüsselring wie bei F73), sonst verweigert `modul:aktivieren`.

## 7. Entscheidungen zur Freigabe

| Nr. | Vorschlag |
|---|---|
| N1 | Menüeinträge, Seiten und Einstellungsabschnitte **deklarativ** im Abschnitt `oberflaeche` von `modul.json` (Abschnitt 3), nach dem Vorbild Nextcloud `info.xml`. |
| N2 | **Feste Bereiche** wie Abschnitt 2; Module hängen sich nur dort oder unter einen Eintrag ihrer Abhängigkeiten ein, keine eigenen Hauptbereiche. |
| N3 | Sichtbarkeit = **Modul im Mandanten aktiv + Recht in einer zugewiesenen Rolle**; der Eintrag nennt nur den Rechte-Code, nie Nutzer oder Rollen; keine direkten Nutzerrechte. Schutz bleibt allein beim REST-Endpunkt. |
| N3a | **Unterpunkte**: fest über `eltern` im Manifest, wechselnde über den Erweiterungspunkt `menue_unterpunkte` nur unter eigenen Einträgen; der Kern filtert jeden Unterpunkt nach Rollen. |
| N4 | Menü als **REST-Endpunkt** `…/oberflaeche/menue`, ins OpenAPI aufgenommen (F18). |
| N5 | Modulseiten als **ES-Module** aus dem signierten Release, statisch über nginx; gemeinsame UI-Bausteine vom Kern; strenge CSP ohne Ausnahmen für Module. |
| N6 | „Plugin“ = Modul außerhalb des Releases, gleiche Technik, aber **Signatur mit zugelassenem Schlüssel** Pflicht. |
| N7 | Reihenfolge (geändert): Rahmen + Menü kommen vorgezogen mit **0.6.1**, zusammen mit dem Kontenrahmen-Import in der Weboberfläche; Buchen und Buchungsmaske folgen mit 0.7.0 – als eigenes Modul `buchen` (F83). |

## Erweiterungen von Seiten des Kerns (F102, kumero 0.7.11)

Der Kern kennt keine Steuer, nur Konten. Was ein Modul an einem Konto führt, zeigt und pflegt es selbst – auf der
Seite des Kerns, über einen Erweiterungspunkt der Oberfläche:

- **Manifest:** `"oberflaeche_erweiterungen": {"konten": "konten-steuer.js"}` – je Punkt eine Datei aus dem
  Verzeichnis `oeffentlich/` des Moduls. Bisher gibt es nur den Punkt `konten`.
- **Menü:** `GET …/oberflaeche/menue` liefert `erweiterungen: {konten: ["/module/<name>/<version>/<datei>"]}` für die
  im Mandanten aktiven Module.
- **Vertrag des Punkts `konten`:** Die Datei exportiert `kontenErweiterung(k, {konten(), neuZeichnen()})` und liefert
  `{spalten: [{titel, klasse, wert(konto)}], werkzeuge: [Element]}`. Lädt eine Erweiterung nicht, bleibt die
  Kontenliste ohne sie benutzbar.
- **Rechte:** Die Erweiterung prüft selbst mit `k.darf(…)`; der Schutz liegt wie immer beim Endpunkt des Moduls.

Offen: Texte der Erweiterungen aus der Übersetzungsdatei des Moduls; weitere Punkte (Kontoblatt, Buchungsliste) bei Bedarf.

## Nichts verschiebt sich (F106, kumero 0.7.14)

Vorgabe des Verantwortlichen vom 2026-11-18; gilt für alle Seiten des Kerns und der Module.

1. **Meldungen in der Statusleiste.** Erfolg, Warnung und Fehler stehen oben in der Kopfleiste an festem Platz. Eine
   Zeile; der ganze Text beim Überfahren und nach einem Klick (klappt über der Seite auf). Nie ein Kasten, der den
   Inhalt nach unten schiebt, nie ein Pop-up (F32).
2. **Keine dynamische Oberfläche.** Knöpfe und Felder haben ihren festen Platz. Was gerade nicht geht, wird
   **gesperrt, nicht ausgeblendet**, und nennt beim Überfahren den Grund (Baustein `sperren(element, grund)` in
   `public/kern/ui/bausteine.js`). Wechselt die Beschriftung eines Knopfs (z. B. mit einer Anzahl), hat er eine feste
   Breite. Werkzeugleisten brechen nicht um; reicht eine Zeile nicht, gibt es feste Zeilen mit fester Bedeutung.
   Was ein Nutzer nach seinen Rechten nie darf, wird gar nicht gezeigt – das ändert sich während der Arbeit nicht.
3. **Fester Kopf der Listen.** Eine Listenseite setzt am Seitenbehälter die Klasse `listenseite` und am Bereich mit
   den Zeilen `listen-rollen`: Seitentitel, Werkzeugleiste, Filter und Tabellenkopf bleiben stehen, nur die Zeilen
   rollen; die Fußzeile bleibt unten.
4. Hinweise, die kommen und gehen (z. B. „Quelle nicht erreichbar“), haben einen reservierten Platz.

Stand: umgesetzt in Arbeitsvorrat, Buchungsliste und Kontenliste. Offen: „Datei einlesen“, Buchungsmaske
(Splittabelle, einzelne Knöpfe), Formular „Neu“ der Kontenliste, „Paperless – mein Zugang“, Einrichtungsseiten.

## Blättern in langen Listen (F107, kumero 0.7.15)

Baustein `kern/ui/blaettern.js`: `blaettern(beiWechsel, {groesse, groessen, id})` liefert `{el, schnitt(zeilen), erste(), zuIndex(i), weiter(), vorher()}`.
Die Seite gibt ihre gefilterten Zeilen an `schnitt()` und zeichnet, was zurückkommt; `el` gehört in den festen Fuß der Liste (`.listen-fuss`).
Regeln: Die Eingabe steht immer da, auch bei einer einzigen Seite (F106) – gesperrte Knöpfe nennen den Grund. Suche, Spaltenfilter, Zähler,
Summen und „Alle markieren“ gelten für alle Einträge. Ein neuer Filter springt auf Seite 1; ein Wechsel der Seitengröße bleibt an der Stelle.
Bild↑/Bild↓ blättern. Umgesetzt im Arbeitsvorrat; Buchungsliste und Kontenliste folgen.

Belegvorschau: Lässt sich ein Bild nicht laden, fragt `belegGrund(k, link)` (`kern/ui/beleg.js`) den Vorschau-Endpunkt und zeigt dessen
Meldung – den konkreten Grund statt „Keine Vorschau verfügbar.“

## Abgelaufene Sitzung (kumero 0.7.44)

Eine Sitzung endet nach 30 Minuten ohne Aufruf, spätestens nach 12 Stunden. Antwortet danach irgendein Aufruf mit 401
`anmeldung.erforderlich`, meldet `kern/ui/api.js` das dem Rahmen (`abgemeldetMelden`). Keine Seite muss das selbst behandeln.

- Die Seite bleibt stehen; darüber liegt der Dialog „Bitte neu anmelden“ (Passkey) – kein Wechsel auf die Anmeldeseite, damit
  Eingaben nicht verloren gehen. „Zur Anmeldeseite“ verwirft sie ausdrücklich.
- Nach der Anmeldung **derselben** Identität: Hat der Nutzer auf der Seite schon etwas eingegeben, bleibt sie unverändert, und
  die Statusleiste bittet, den letzten Schritt zu wiederholen; sonst wird die Seite neu geladen. Das Menü wird neu geholt.
- Meldet sich eine **andere** Identität an, beginnt die Oberfläche von vorn (nichts von der alten Seite bleibt sichtbar).

Zu bestätigen: Der Dialog ist ein modales Fenster – eine Ausnahme von „nie ein Pop-up“ (F32, F106), weil ohne Anmeldung nichts
weitergeht. Offen: Vorwarnung vor dem Ablauf und einstellbare Dauer (PROFIL.md, automatische Abmeldung).

## Verschiebbare Aufteilung, Filter in jedem Tabellenkopf, Menüleiste (F137, Vorgabe vom 2026-11-20)

Vorgabe des Verantwortlichen an einem Bild der Seite „Bestand“: Die Zweiteilung (oben die Liste, unten das Gewählte) ist
gut, **muss aber grundsätzlich verschiebbar sein**; in den Tabellenköpfen **fehlen die definierten Filterelemente**
(F91); und **die Menüleiste muss ebenfalls verschiebbar und ausblendbar sein**.

| # | Regel | Stand |
|---|---|---|
| T1 | **Zweiteilung mit Teiler.** Wo eine Seite Liste und Einzelheiten untereinander zeigt, liegt dazwischen ein Teiler. Er wird mit Maus, Finger oder Tastatur bewegt (Pfeile, mit Umschalt größer; Pos1/Ende; Doppelklick stellt die Vorgabe her). Beide Teile behalten eine Mindesthöhe. | Baustein `public/kern/ui/teiler.js` (`teiler(oben, unten, {id, hoehe, min})`), gebaut mit 0.7.43; genutzt von „Personen“ und „Abwesenheiten“ |
| T2 | **Gemerkt je Seite.** Die gewählte Höhe merkt sich der Browser (kein Personenbezug, nicht am Server). Ohne Speicher gilt die Vorgabe. | gebaut |
| T3 | **F106 gilt weiter.** „Nichts verschiebt sich“ heißt: nichts verschiebt sich **von selbst**. Der Teiler bewegt sich nur, wenn der Nutzer ihn bewegt. | – |
| T4 | **Jeder Tabellenkopf einer Liste trägt Sortierung und Filter** (Baustein `tabellenfilter.js`, F91): Wert finden oder ausschließen, je Spalte. Ausgenommen sind Spalten mit Eingabefeldern oder Markierungen und kleine Tabellen innerhalb einer Akte oder eines Dialogs. | gebaut in „Personen“, „Fristen und Nachweise“ und „Abwesenheiten“; vorher schon Buchungsliste und Arbeitsvorrat. seit 0.7.52 Lager „Bestand“ samt Chargen, seit 0.7.54 das ganze Lager (Wareneingang, Nachbestellen, Inventur, Bestandsliste). **Offen:** Konten, Nutzer und Rollen, Bücher, Anlagen, Auswertungen, Siegel |
| T5 | **Menüleiste verschiebbar:** Die Breite der Navigation wird am rechten Rand gezogen, mit denselben Tasten wie T1; gemerkt je Browser. | gebaut mit 0.7.52 (`rahmen.js`, `menueleiste()`): Griff `#navigation-teiler`, mindestens 160 px, höchstens 640 px oder 60 % des Fensters |
| T6 | **Menüleiste ausblendbar:** Ein Knopf im Kopf blendet die Navigation aus und wieder ein (auch per Tastatur); ausgeblendet bleibt ein schmaler Rand mit dem Knopf. Im Druck ist sie ohnehin weg. | gebaut mit 0.7.52: Knopf `#menue-knopf` links im Kopf, Rand `#navigation-rand` |
| T7 | Bestehende Zweiteilungen werden auf den Baustein umgestellt: Lager „Bestand“ (Chargen unter der Artikelliste) zuerst. | gebaut mit 0.7.52 (`lg-teilung`); weitere Zweiteilungen gibt es derzeit nicht |
| T8 | **Spaltenbreite verschiebbar (F146).** Die Grenze am rechten Rand jedes Spaltenkopfs wird gezogen (Maus, Finger). Mit dem ersten Ziehen stehen alle Breiten fest, wie sie gerade sind – geändert wird nur die gezogene Spalte; die Tabelle ist dann so breit wie ihre Spalten und rollt bei Bedarf quer. Kleinste Breite 48 px, größte 960 px. Doppelklick auf die Grenze: Breiten wieder nach Inhalt. | gebaut mit 0.7.54 im Baustein `tabellenfilter.js`; genutzt im ganzen Lager |
| T9 | **Reihenfolge der Spalten verschiebbar (F146).** Der Titel einer Spalte wird an eine andere Stelle gezogen; ein Strich zeigt, wo sie landet. Das gilt auch für Spalten mit Eingabefeldern oder Markierungen. Ziehen sortiert nicht. | gebaut mit 0.7.54; genutzt im ganzen Lager |
| T10 | **Tastatur.** Am Titel der Spalte: Strg+←/→ verschiebt sie um eine Stelle, Umschalt+←/→ macht sie schmaler oder breiter (mit Strg in größeren Schritten), Umschalt+Pos1/Ende kleinste und größte Breite. Der Fokus bleibt am Titel. | gebaut mit 0.7.54 |
| T11 | **Gemerkt je Browser und Tabelle** (wie T2: kein Personenbezug, nicht am Server). „Spalten nach Vorgabe“ steht in jedem Filtermenü an festem Platz (gesperrt, solange nichts geändert ist). Ein Update setzt die Wahl nicht zurück; neue Spalten kommen ans Ende. | gebaut mit 0.7.54 |

T4 und T8–T11 für die Listen außerhalb des Lagers bleiben ein eigener Schritt (AGENTS.md P39).

**So nutzt eine Seite den Baustein:** `const tf = tabellenfilter(spalten, zeichnen, { id: 'modul.tabelle' })`; jede Spalte trägt eine `id`
(auch die ohne Filter: `fest: true`); gezeichnet wird mit `tf.kopf` im `thead` und den Zellen in der Reihenfolge von `tf.spalten()`.
Ohne `{id}` bleibt alles wie bisher (Sortierung und Filter, feste Spalten). Regeln für Zellen hängen an einer Klasse der Spalte,
nie an ihrer Stelle (`td:nth-child(…)`).

**Festlegungen aus der Umsetzung (0.7.52), zu bestätigen:**
- **O1:** Die Filter einer Tabelle der Einzelheiten (Chargen) gelten für den gewählten Eintrag der Liste und beginnen mit jedem anderen Eintrag neu – ein Filter von vorhin verdeckt nie fremden Bestand.
- **O2:** Gewählt ist immer nur eine sichtbare Zeile; Knöpfe wirken nie auf eine weggefilterte.
- **O3:** Der Knopf der Menüleiste steht ganz links im Kopf; Breite und Zustand der Menüleiste gelten je Browser für alle Seiten und Mandanten.

**Festlegungen aus der Umsetzung (0.7.54, F146), zu bestätigen:**
- **O4:** Breiten und Reihenfolge gelten je Browser und Tabelle für alle Mandanten und Bücher (wie O3) – nicht je Nutzer am Server (das wäre PROFIL.md).
- **O5:** Gedruckt wird, was zu sehen ist (Filter, Sortierung, Reihenfolge). CSV-Dateien enthalten immer alle Zeilen in der Reihenfolge der Vorgabe.
- **O6:** Summenzeilen: „Gesamt“ gilt immer für alle Einträge; ist gefiltert, steht darüber eine Zeile „Gezeigt“ mit der Summe der sichtbaren (Bestandsliste).
- **O7:** In Listen mit Eingabefeldern verdeckt ein Filter nur Zeilen: Prüfen, Scannen und Buchen gelten weiter für alle (Wareneingang); Zähler und Abschluss ebenso (Inventur).
- **O8:** Eine Zeile, die sich durch eine Eingabe ändert, bleibt stehen, auch wenn sie danach nicht mehr zum Filter oder zur Sortierung passt – neu geordnet wird erst beim nächsten Filtern oder Laden (F106).
- **O9:** Die Werkzeugleiste von „Bestand“ hat zwei feste Zeilen: oben die Bewegungen und „Drucken“, über der Liste die Suche und die Stammdaten (Artikel, Lagerorte, Einstellungen).


## Doppelklick öffnet Einzelheiten (F150, kumero 0.7.58)

Vorgabe des Verantwortlichen vom 2026-11-21 („Allgemeine Bedienung“): **Ein Doppelklick auf ein Element öffnet die
gegebenenfalls vorhandenen Einzelheiten.** In einer Liste ist das der Dialog zum Eintrag (bearbeiten oder ansehen); wo
an dem Eintrag etwas zu entscheiden ist, führt der Doppelklick zur Entscheidung. Der einfache Klick wählt weiter nur aus.
Mit der Tastatur tut Enter auf der Zeile dasselbe. Darf jemand die Einzelheiten nicht öffnen, geschieht nichts oder die
Statusleiste nennt den Grund – es erscheint kein leerer Dialog.

Gebaut: Personenliste (→ „Bearbeiten“), Monatsblatt „Meine Zeiten“ (→ Einzelheiten des Tages mit Anmerkungen),
Monatsblatt „Zeiten“ (→ Tag freigeben oder Antrag entscheiden). Seit 0.7.59 auch „Abwesenheiten“ (→ „Ende ändern“, sonst „Arbeitstage“ oder Nachweis; **eine Entscheidung fällt nie durch
den Doppelklick selbst**, er öffnet nur). Der Lauscher sitzt an der Liste, nicht an der Zeile: Der erste Klick zeichnet
die Zeile neu, an ihr käme der zweite nicht mehr an. **Offen (P39):** alle übrigen Listen.
