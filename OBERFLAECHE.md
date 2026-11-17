# OBERFLAECHE.md – Rahmen der Weboberfläche und Einbindung der Module ins Menü (Entwurf 1, zur Freigabe)

Stand: 2026-11-17. Gebaut wird erst nach Freigabe der Entscheidungen in Abschnitt 7.
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
- Sichtbarkeit hängt am **Recht** (F41), nicht an Gruppen – und am Aktivsein **im Mandanten**.
- Kein frei wählbarer Hauptbereich: Module hängen sich in **feste Bereiche** (Abschnitt 2), damit das
  Menü bei vielen Modulen geordnet bleibt.
- Keine dynamischen Einträge per PHP-Code (Nextcloud `INavigationManager`). Was sich ändert, ist nur
  ein **Zähler** (z. B. „Stapel 3“), geliefert von einem im Manifest genannten API-Pfad.
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
    "vorlagen":   "oberflaeche/vorlagen.js",
    "angewendet": "oberflaeche/angewendet.js"
  },
  "einstellungen": [
    {"id": "kontenrahmen.allgemein", "ebene": "mandant", "titel": "einst.titel",
     "seite": "oberflaeche/einstellungen.js", "recht": "kontenrahmen.vorlage.anwenden",
     "reihenfolge": 50}
  ],
  "texte": "sprachen/"
}
```

Regeln:
- `id` beginnt mit dem Modulnamen (Namensraum wie bei Rechten und Erweiterungsattributen, F58).
- `eltern` nur auf einen Eintrag eines Moduls, von dem das Modul abhängt (`abhaengigkeiten`), oder
  des Kerns – so entstehen keine Kreise (F44).
- `recht` ist Pflicht. Ein Recht muss vom Modul selbst oder einer Abhängigkeit stammen.
- `titel` ist ein Textschlüssel; Übersetzungen liegen unter `texte` (T5).
- `zaehler` (optional): relativer GET-Pfad der eigenen REST-API, Antwort `{"anzahl": n}`.
- `modul:aktivieren` prüft das alles und lehnt ein fehlerhaftes Manifest ab (wie heute die Rechte).

Der Kern trägt seine eigenen Einträge (`kern.stapel`, `kern.journal`, `kern.konten` …) im selben
Format in ein Kern-Manifest ein – er ist auch hier nur ein Modul (ANWENDUNG.md 7.2).

## 4. Wie das Menü entsteht

1. `GET /api/v1/oberflaeche/menue` (systemweit) bzw. `GET /m/{mandant}/api/v1/oberflaeche/menue`.
2. Der Kern sammelt die Einträge des Kerns und aller **im Mandanten aktiven** Module, behält nur die,
   deren Recht `rechte_des_subjekts` liefert, löst `eltern` auf, sortiert nach `reihenfolge`, dann
   `id`, übersetzt die Titel in die Sprache der Sitzung.
3. Antwort: Baum je Bereich mit `id`, `titel`, `pfad` (`#/m/spiel/kontenrahmen/angewendet`),
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
| N3 | Sichtbarkeit = **Modul im Mandanten aktiv + Recht vorhanden**; Recht je Eintrag Pflicht; Schutz bleibt allein beim REST-Endpunkt. |
| N4 | Menü als **REST-Endpunkt** `…/oberflaeche/menue`, ins OpenAPI aufgenommen (F18). |
| N5 | Modulseiten als **ES-Module** aus dem signierten Release, statisch über nginx; gemeinsame UI-Bausteine vom Kern; strenge CSP ohne Ausnahmen für Module. |
| N6 | „Plugin“ = Modul außerhalb des Releases, gleiche Technik, aber **Signatur mit zugelassenem Schlüssel** Pflicht. |
| N7 | Reihenfolge: Rahmen + Menü kommen mit 0.7.0, zusammen mit Buchen und der Buchungsmaske (BUCHUNGSMASKE.md M7); `kontenrahmen` bekommt dabei seine ersten Seiten. |
