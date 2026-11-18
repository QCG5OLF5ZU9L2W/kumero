# BUCHUNGSMASKE.md – Buchungsliste und Buchungserfassung (Fassung 1, freigegeben 2026-11-17 → F83)

Stand: 2026-11-17. Bezug: F4, F8, F24–F27, F30–F32, F38, F66–F68, F78; Vorbild: Bedienung von Sage 50
(Aufbau und Abläufe, nicht Aussehen, Logo oder Begriffe).
**Status: M1–M8 freigegeben am 2026-11-17 (F83), umgesetzt in kumero 0.7.0 als Modul `buchen` 0.1.0. M9–M15 freigegeben am 2026-11-17 (F85), umgesetzt in kumero 0.7.1 als Modul `buchen` 0.2.0. M16 erster Teil (Feld „Beleg / Link“, F88) in kumero 0.7.2 als Modul `buchen` 0.2.1; Vorschau (M16) und M17 folgen mit dem Modul `paperless`.**
Abweichungen und Ergänzungen aus der Umsetzung stehen in Abschnitt 7 und sind noch zu bestätigen.

Grundsatz: **Liste und Formular**, wie bei Sage 50. Die Liste zeigt und steuert, das Formular erfasst.
Beides nutzt nur die REST-API (F18). Alles ist mit der Tastatur bedienbar.

---

## 1. Buchungsliste

```
┌ Buchungsliste – 2026 – [Stapel ▾]                                              ┐
│ Suchen  Neu  Kopieren  Bearbeiten  Löschen  Freigeben  Stornieren  Kontoblatt  │
├────────┬──────────┬───────────────────┬─────────┬───────┬────────┬───────┬─────┤
│ Nr.    │Belegdatum│ Buchungstext      │Beleg-Nr.│ Konto │ Soll   │Gegenkto│Haben│
├────────┼──────────┼───────────────────┼─────────┼───────┼────────┼───────┼─────┤
│ …      │          │                   │         │       │        │       │     │
├────────┴──────────┴───────────────────┴─────────┴───────┴────────┴───────┴─────┤
│ Anzahl: 27   Kontrollsumme Soll − Haben: 0,00   Stapel: 3 freigabebereit       │
└────────────────────────────────────────────────────────────────────────────────┘
```

- **Ansichten** (Auswahlfeld oben): *Stapel* = Entwürfe und freigabebereite Buchungen (F31, F66),
  *Journal* = festgeschriebene Buchungen in Journal-Reihenfolge, *Alle*. Dazu Geschäftsjahr und Filter
  (Konto, Zeitraum, Erfasser, Buchungsart).
- **Spalten** wie im Vorbild: Nummer (im Stapel leer, im Journal `nr` aus dem Nummernkreis),
  Belegdatum, Buchungstext, Belegnummer, Konto, Soll-Betrag, Gegenkonto, Haben-Betrag, Betrag.
  Eine Buchung mit mehr als zwei Zeilen erscheint mit „Splitt“ statt Gegenkonto und lässt sich aufklappen.
- **Statuszeichen** je Zeile: Entwurf, freigabebereit, festgeschrieben, storniert, Testbuchung (F59).
- **Werkzeugleiste**:
  - Neu, Kopieren (Entwurf aus vorhandener Buchung), Bearbeiten und Löschen (nur Entwürfe, F20),
  - **Freigeben** (markierte Stapelbuchungen, eine Freigabe für den ganzen Stapel, F67),
  - Stornieren (legt Storno-Entwurf an, F30, F78), Kontoblatt des Kontos der Zeile.
- **Fußzeile**: Anzahl, Kontrollsumme Soll − Haben der angezeigten Buchungen (muss im Journal 0,00 sein),
  Zahl der freigabebereiten Buchungen.
- Tastatur: Pfeile bewegen, Enter bearbeitet, Einfg legt neu an, Leertaste markiert, Strg+F sucht.

## 2. Buchungserfassung (Formular)

```
┌ Buchungserfassung – Stapel, Erfasser: Frank ──────────────────────────────────────┐
│ Geschäftsvorfälle   │ Konto        [1200   ] Bank                   Saldo 2.345,67 │
│ ▸ Zahlungseingang   │ Betrag       [  109,43]                                        │
│ ▸ Zahlungsausgang   │ Gegenkonto   [8100   ] Honorare Privatpatienten  Saldo …    │
│ ▸ Barzahlungen      │ Belegdatum   [09.10.2026] Freitag    Buchungsdatum [=]         │
│ ▸ Privat            │ Belegnummer  [RE-0815  ] ▲▼                                    │
│ ▸ übrige Buchungen  │ Buchungstext [Zahlungseingang ▾]                              │
│                     │ Buchungsart  [Bank ▾]       Tags [Praxis ▾]                   │
│                     │ Beleg        [Paperless …]  (später)                          │
│                     │                                                                │
│                     │ [Aufteilen]  [Soll-Haben-Split]      [Speichern] [Buchen] [Abbr.]│
└─────────────────────┴────────────────────────────────────────────────────────────────┘
```

- **Konto, Gegenkonto**: Eingabe der Nummer oder Suche nach Bezeichnung; daneben Bezeichnung und
  **laufender Saldo** (aus `/salden`). Konto steht im Soll, Gegenkonto im Haben; Umkehren mit einer Taste.
- **Betrag**: immer positiv (F24), Eingabe mit Komma, Rechnen im Feld erlaubt (`100+9,43`).
- **Belegdatum** mit Wochentag; **Buchungsdatum** standardmäßig gleich Belegdatum (bei EÜR der Zu-/Abflusstag,
  F26), getrennt änderbar.
- **Belegnummer**: Freitext (F25 Nr. 3) mit Hoch-/Runterzählen aus der letzten Erfassung.
- **Buchungstext**: Freitext mit Vorschlägen aus den zuletzt benutzten Texten und aus dem Geschäftsvorfall.
- **Buchungsart** (bestimmt den Nummernkreis, F25) und **Tags** (F8).
- **Steuercode, Kostenstelle**: nicht im Kern; der Steuercode kommt mit dem Modul `steuer` als
  Erweiterungsattribut (F58) ins Formular, Kostenstellen sind Tags.
- **Aufteilen / Soll-Haben-Split**: öffnet unter dem Formular eine **Zeilentabelle** (Konto, Soll, Haben,
  Text, Tags) für Splitbuchungen (F4). Die Restsumme bis zur Ausgeglichenheit steht immer sichtbar daneben.
- **Speichern** legt einen **Entwurf** an (Stapel). **Buchen** bereitet vor und schreibt fest – nur mit dem
  Recht dazu; im Produktivbetrieb mit Passkey-Bestätigung über die angezeigten Daten (F38, F78), im
  Testbetrieb ohne. Nach dem Speichern bleibt das Formular offen und nimmt Datum und Belegnummer mit.
- Tastatur: Tab/Enter von Feld zu Feld, F2 Kontensuche, Strg+Enter speichern, Strg+Umschalt+Enter buchen,
  Esc abbrechen.

## 3. Geschäftsvorfälle (Buchungsvorlagen)

Der Baum links bietet **Vorlagen für häufige Vorfälle**: Er belegt Konto, Gegenkonto, Buchungsart,
Buchungstext und Tags vor, z. B. „Zahlungseingang Bank → Honorare“, „Barzahlung Praxisbedarf“,
„Privatentnahme“. Vorlagen pflegt der Nutzer selbst; sie sind Daten im Mandanten, keine Logik.
Offene-Posten-Vorgänge (OP-Ausgleich) kommen mit dem Modul `opos`, Lohn und Anlagen mit ihren Modulen.

## 4. Was bewusst anders ist als im Vorbild

- **Kein „Monate sperren“** im Formular; Sperren ist der Jahresabschluss (F33).
- **Kein stilles Festschreiben**: Wer nur erfassen darf, sieht keinen Knopf „Buchen“ (F31).
- **Keine Warn-Popups** (F32); Hinweise stehen als Text am Feld.
- **Belegarchiv** heißt bei uns Paperless und kommt mit dem Modul `paperless` (Projektschritt 4).

## 5. Technik

- Eine Seite im Browser, ohne Framework-Abhängigkeit zur Laufzeit (kein CDN, L3); Daten nur über REST.
- **Eigenes Modul `buchen`** (Entscheidung des Verantwortlichen am 2026-11-17, F83; der Entwurf sah Seiten des
  Kerns vor): `module/buchen/` mit Manifest, Migration, Seiten und Texten. Es läuft im Rahmen nach
  OBERFLAECHE.md (F80) im Bereich „Buchen“ und wird je Mandant mit `bin/kumero modul:aktivieren buchen MANDANT`
  eingeschaltet.
- **Im Kern** bleiben die Endpunkte aus openapi/v1.yaml: Buchungen (anlegen, ändern, löschen, vorbereiten,
  zurücksetzen), Freigaben (Challenge, Freigeben), Storno, Beleg nachreichen, Journal mit Filtern, Salden,
  Kontoblatt (`src/Http/BuchungenController.php`). Das Modul liest und schreibt keine Tabellen des Kerns (F44).
- **Im Modul**: Geschäftsvorfall-Vorlagen im Schema `buchen` (Tabelle `buchen.vorlage`, Schreiben nur über
  `buchen.vorlage_setzen` / `buchen.vorlage_loeschen` mit Vorher-/Nachher-Stand im Protokoll, F48) und die
  Endpunkte `/m/{mandant}/api/v1/buchen/vorlagen` (statt `/vorlagen/buchung` im Entwurf – Modulpfade beginnen
  mit dem Modulnamen). Rechte: `buchen.vorlage.sehen` (Erfasser), `buchen.vorlage.pflegen` (Buchhalter,
  Verantwortlicher, Steuerberater).
- **Zuletzt benutzte Texte** leitet die Seite aus den letzten 200 Buchungen des Journals ab
  (`GET /buchungen?reihenfolge=ab`); einen eigenen Endpunkt gibt es dafür nicht.
- Seiten des Moduls: `liste`, `erfassen`, `kontoblatt`, `vorlagen`, `einrichtung` (ES-Module unter
  `/module/buchen/<version>/`, eigenes Stylesheet, strenge CSP ohne Ausnahmen).

## 6. Entscheidungen (freigegeben 2026-11-17 → F83)

| # | Frage | Entscheidung |
|---|---|---|
| M1 | Liste und Formular | **Beides** wie im Vorbild (Abschnitt 1 und 2), Splitbuchungen als Zeilentabelle im Formular. |
| M2 | Konto/Gegenkonto | Konto = Soll, Gegenkonto = Haben, mit Umkehrtaste; laufende Salden neben den Konten. |
| M3 | Buchungsdatum | standardmäßig gleich Belegdatum, getrennt änderbar. |
| M4 | Speichern / Buchen | zwei Knöpfe: Speichern = Stapel (Entwurf), Buchen = festschreiben (nur mit Recht). |
| M5 | Geschäftsvorfälle | als eigene, vom Nutzer gepflegte Vorlagen; mitgeliefert ein paar allgemeine. |
| M6 | Tastenbelegung | wie in Abschnitt 1 und 2; später anpassbar. |
| M7 | Reihenfolge | **Buchen über REST und diese Maske als 0.7.0**, die EÜR-Zuordnung danach als 0.8.0 – damit du früher buchen und testen kannst. |
| M8 | Sprache | Oberfläche zuerst Deutsch, Texte aber von Anfang an aus einer Übersetzungsdatei (T5). |

## 7. Festlegungen aus der Umsetzung (0.7.0, zur Bestätigung)

1. **Seite „Buchen einrichten“** (Bereich Einstellungen, Recht `kern.geschaeftsjahr.anlegen`) – im Entwurf nicht
   vorgesehen, aber ohne sie kann niemand buchen: Geschäftsjahre anlegen, Buchungsarten mit Nummernkreis anlegen,
   Nummernvorlage je Geschäftsjahr festlegen (F25), Tags anlegen. Solange noch nichts da ist, bietet sie einen
   Vorschlag an (Geschäftsjahr des laufenden Kalenderjahrs, Buchungsart und Nummernkreis `ALLG`, Vorlage
   `ALLG-{JJJJ}-{NR:5}`). Die Erfassung zeigt, was fehlt, und führt dorthin. Genutzt werden nur vorhandene
   Endpunkte des Kerns.
2. **Ein Knopf „Aufteilen“** statt „Aufteilen“ und „Soll-Haben-Split“: Er öffnet die Zeilentabelle mit den beiden
   Zeilen aus Konto und Gegenkonto und einer leeren; beide Fälle des Vorbilds laufen über dieselbe Tabelle.
3. **Keine Spalte „Text“ je Zeile:** Das Datenmodell kennt nur den Buchungstext im Kopf. Die Tabelle hat Konto,
   Bezeichnung, Soll, Haben, Tags.
4. **Umkehrtaste ist F4** (Soll ⇄ Haben); der Entwurf nannte keine Taste.
5. **Mitgelieferte Geschäftsvorfälle ohne Konten:** sieben allgemeine Vorlagen (Zahlungseingang, Zahlungsausgang,
   Bareinnahme, Barausgabe, Privatentnahme, Privateinlage, Umbuchung) nur mit Gruppe, Name und Buchungstext, weil
   die Konten vom Kontenrahmen abhängen. Sie werden nicht still angelegt, sondern über „Mitgelieferte übernehmen“
   – so steht jede im Protokoll.
6. **Anzeige vor der Freigabe:** Die Stapelfreigabe aus der Liste zeigt immer den Inhalt aus
   `POST /freigaben/challenge` (F38). „Buchen“ aus dem Formular schreibt im **Testbetrieb** ohne diese Anzeige
   fest (F78 O1); im Produktivbetrieb kommt sie immer, gefolgt vom Passkey.
7. **Laufender Saldo** neben Konto und Gegenkonto: Saldo aller festgeschriebenen Buchungen des Kontos, ohne
   Stichtag und ohne Entwürfe.
8. **Listenaktionen ohne ETag:** Löschen und Vorbereiten aus der Liste senden `If-Match: *`; das Formular arbeitet
   mit dem ETag des geladenen Entwurfs (F19).
9. **Nach dem Speichern** bleiben Belegdatum, Buchungsdatum, Belegnummer und Buchungsart stehen; Konto,
   Gegenkonto, Betrag, Text und Tags werden geleert.
10. **Journal in der Liste** lädt höchstens 5 000 Buchungen je Ansicht und weist darauf hin, wenn es mehr gibt.
11. **Noch nicht enthalten:** Freigabe-Passkeys im Mandanten registrieren (`/passkeys` aus dem Vertrag) – die
    Prüfung der Assertion bei `/freigaben` ist gebaut und getestet, es fehlt die Registrierung über REST und
    Oberfläche; Jahressperre und Wiederöffnen über REST (Challenge mit `gj_sperren`/`gj_oeffnen` antwortet 501);
    Einschränkungen von Rechten auf Buchungsart oder Konto (F41 Nr. 2); Belege aus Paperless (Modul `paperless`);
    Steuercode (Modul `steuer`); eine anpassbare Tastenbelegung (M6 „später“).

## 8. Ergänzungen nach dem ersten Test (Entwurf 2)

Rückmeldung des Verantwortlichen vom 2026-11-17 zu 0.7.0. **M9–M15 sind freigegeben und in 0.7.1 gebaut (F85)**, ebenso die genannten Fehler. M16 und M17 sind beschrieben, aber noch nicht gebaut.

**0.7.2:** Das Feld „Beleg / Link“ steht unter der Belegnummer; der Link wird als Belegverweis mit der Buchung festgeschrieben (F88), ohne Prüfsummen nur im Testbetrieb. Ein Link in der Belegnummer wird mit Hinweis abgewiesen. Das Buchungsdatum steht wieder neben dem Belegdatum, der Kalenderknopf sitzt im Datumsfeld (Rückmeldung vom 2026-11-17). Wochentage folgen der Sprache der Texte (deutsch), das Datum der Schreibweise aus dem Profil.

Festlegungen aus der Umsetzung von 0.7.1 (zur Bestätigung): Kontenvorschau zeigt höchstens zwölf Treffer, zuerst Konten, deren Nummer mit der Eingabe beginnt, dann Treffer in der Bezeichnung; Enter übernimmt den ersten Treffer, Esc schließt nur die Vorschau. Datum: Kurzeingaben `17`, `1711`, `171126` und `17.11.` werden ergänzt, Pfeil hoch/runter ändert den Tag. Kaufmännisch gilt nur für reine Ziffernfolgen; mit Komma oder in einer Rechnung zählen die Zahlen, wie sie dastehen. Zahlen werden weiter deutsch geschrieben (1.234,56), auch wenn das Datum einer anderen Sprache folgt.

| # | Thema | Vorschlag |
|---|---|---|
| M9 | **Kontenvorschau beim Tippen** | Unter jedem Kontofeld (Konto, Gegenkonto, Zeilentabelle, Filter der Liste, Kontoblatt, Geschäftsvorfälle) erscheint beim Tippen eine Liste der passenden aktiven Konten – Treffer auf den Anfang der Nummer oder auf einen Teil der Bezeichnung –, wie bei den Tags in Paperless. Pfeiltasten wählen, Enter oder Klick übernimmt, Esc schließt nur die Liste. Ist die getippte Nummer selbst ein Konto, lässt Enter sie stehen; sonst übernimmt Enter den ersten Treffer. Beim Verlassen des Felds wird nichts still ersetzt. F2 bleibt die große Suche. |
| M10 | **Betragseingabe dezimal oder kaufmännisch** | Umschalter **je Nutzer** in „Profil und Einstellungen“ (PROFIL.md): *dezimal* – `1000` wird 1.000,00, `10,5` wird 10,50; *kaufmännisch* – die letzten zwei Ziffern sind Cent, `1000` wird 10,00, mit Komma wie geschrieben. Angezeigt wird immer mit Tausenderpunkt und zwei Nachkommastellen. **Rechnen im Feld ist in beiden Arten selbstverständlich** (`100+9,43`, bestätigt am 2026-11-17); offen ist nur, wie Zahlen ohne Komma in einer Rechnung bei kaufmännischer Eingabe zählen – Vorschlag: wie geschrieben, also `100+9,43` = 109,43. |
| M11 | **Datum nach der Sprache des Nutzers, sonst des Browsers** | Entschieden am 2026-11-17: Hat der Nutzer in seinem Profil eine Sprache gesetzt, gilt deren Schreibweise; ist keine gesetzt, die des Browsers (PROFIL.md U5). Das gilt einheitlich für Datumsfelder und Datumsangaben in Listen, Kontoblatt und Freigabe (heute: Felder nach dem Browser, Listen fest `17.11.2026`). Die API bleibt bei `YYYY-MM-DD`. |
| M12 | **Keine verschobenen Elemente** | Formulare in festen Spalten: Beschriftung, Eingabe, Bezeichnung, Saldo stehen je Zeile auf einer Linie; Hinweise („Konto unbekannt“) haben einen festen Platz und verschieben nichts. Felder mit und ohne Hinweiszeile stehen auf gleicher Höhe (Geschäftsvorfälle: Buchungsart und Buchungstext standen tiefer als Konto und Gegenkonto). Bei schmalem Fenster bricht die Zeile als Ganzes um, Beschriftungen werden nie abgeschnitten. |
| M13 | **Kleine Hilfen beim Überfahren mit der Maus** | Jedes Feld und jeder Knopf zeigt einen kurzen Hinweis, bei Knöpfen mit Tastenkürzel (z. B. „Buchen – Strg+Umschalt+Enter“). Texte aus der Übersetzungsdatei (M8); dieselben Texte stehen Vorleseprogrammen zur Verfügung. |
| M14 | **Festgeschriebene Buchungen sichtbar fix** | Eine festgeschriebene Buchung sieht nicht mehr aus wie ein Formular: Werte stehen als Text statt in Eingabefeldern, die Karte bekommt einen eigenen Hintergrund und ein Schloss mit „Festgeschrieben – Korrektur nur per Storno“, dazu Nummer, Journalnummer, Zeitpunkt, wer festgeschrieben hat, und der Hash. Stornierte Buchungen und Stornos sind durchgehend gekennzeichnet, mit Verweis aufeinander. In der Buchungsliste unterscheiden sich Entwurf, freigabebereit und festgeschrieben nicht nur durch Farbe, sondern auch durch Zeichen und Text (PROFIL.md U10). |
| M15 | **Tab springt von Feld zu Feld** | Ein Datumsfeld ist **ein** Halt, nicht drei (Tag, Monat, Jahr). Der nächste Halt ist der Kalender – das bleibt –, danach das nächste Feld. Dafür ersetzt ein eigenes Datumsfeld das des Browsers: Eingabe in der Schreibweise nach M11, auch verkürzt (`17.11.`, `1711`, `17` = Tag im laufenden Monat), Pfeil hoch/runter ändert den Tag. Für alle anderen Felder gilt: ein Halt je Feld; Hilfsknöpfe (Kontensuche, Belegnummer zählen) liegen nicht in der Tab-Folge und sind per Taste erreichbar. |
| M16 | **Link mit Vorschau** | Die Maske bekommt das Feld **„Beleg / Link“** (im Entwurf als „Beleg [Paperless …] (später)“ vorgesehen). Ein eingegebener Link wird als Link erkannt und bekommt eine Vorschau neben dem Formular. Er gehört **nicht** in die Belegnummer (Freitext der Rechnungsnummer, höchstens 36 Zeichen – in 0.7.0 wurde ein Link dort still abgeschnitten). Zu klären: (a) **Welche Ziele?** Vorschlag: nur Adressen, die der Mandant freigibt, zuerst sein Paperless; beliebige Adressen lädt kumero nie selbst (L3, Art. 9 DSGVO). (b) **Wie?** Vorschlag: Vorschaubild und Titel des Paperless-Dokuments über das Modul `paperless` mit dessen Zugang; ein Einbetten der fremden Seite im Browser verlangt eine Ausnahme in der strengen CSP (F80 N5) und klappt nur, wenn Paperless das Einbetten erlaubt. (c) **Speichern:** Ein Paperless-Link wird zum Belegverweis nach F10 (Paperless-ID, MD5, SHA-256, vom Modul geprüft); ob andere Links überhaupt an der Buchung gespeichert werden und damit in den Hash eingehen (Erweiterungsattribut, F58), ist zu entscheiden. |
| M17 | **Tags aus Paperless** | Ist für den Mandanten ein Paperless als Archiv angebunden, kommen die Tags von dort (T8, F51 Nr. 1): Welche übernommen werden, ist einstellbar (Präfix oder Liste), damit private Ablage-Tags draußen bleiben. Das ist Aufgabe des **Integrationsmoduls `paperless`** (F45), das es noch nicht gibt; der Kern ist vorbereitet (Tag mit Herkunft `paperless` und Paperless-ID). Bis dahin werden Tags unter „Buchen einrichten“ von Hand angelegt. Erste Stufe des Moduls wäre: Verbindung je Mandant, Tags übernehmen, Link-Vorschau und Belegverweis (M16) – eigener Entwurf nötig (AGENTS.md P13). |

Fehler aus 0.7.0, die mit behoben werden:

1. Neben „Konto unbekannt“ blieb der Saldo des vorigen Kontos stehen.
2. Am linken Rand waren Beschriftungen abgeschnitten.
3. Eine zu lange Eingabe in der Belegnummer wurde still abgeschnitten; künftig steht ein Hinweis am Feld.

**Reihenfolge (entschieden am 2026-11-17):** Solange das Buchen nicht vollständig beschrieben ist, gibt es keine
0.8.0. Alle Punkte dieses Abschnitts, PROFIL.md und BELEGEINGANG.md sind Versionen 0.7.x; die Zuordnung zur
Anlage EÜR (F83 M7) folgt danach.

Verwandte Entwürfe: **PROFIL.md** (Nutzermenü, Profil und Einstellungen – Voraussetzung für M10) und
**BELEGEINGANG.md** (Aufgabenvorrat aus nopaper, Buchungsmaske vorbefüllt).

## 9. Steuer in der Splitbuchung (F101, gebaut in kumero 0.7.10)

Vorgaben des Verantwortlichen vom 2026-11-18.

- **Steuerkonto = Konto mit Steuersatz.** Gepflegt unter „Buchen einrichten → Steuerkonten“; der Satz wird aus der
  Bezeichnung vorgeschlagen („… 19 %“) und gilt erst nach dem Speichern. Ein leeres Feld löscht ihn.
- **Der primäre Betrag ist brutto.** Steht in der Splitbuchung genau ein Steuerkonto, rechnet die Maske sofort:
  Steuer = Brutto − Brutto ÷ (1 + Satz); Netto ist der Rest. So geht die Summe immer auf den Cent auf.
- **Die Summe bleibt der Rechnungsbetrag.** Der Betrag auf der Seite des Geldkontos steht fest. Kommen weitere
  Sachzeilen dazu, nimmt die **Restzeile** – die erste Sachzeile ohne eigenen Betrag – die Differenz auf.
- **Richtung aus Soll und Haben.** Die Steuerzeile steht auf der Seite des Erfolgskontos: bei Aufwand im Soll
  (Vorsteuer), bei Ertrag im Haben (Umsatzsteuer).
- **Haken „Beträge netto eingeben“.** Dann sind die Sachzeilen netto; Steuer = Netto × Satz, der Zahlbetrag auf der
  Gegenseite wird dazugerechnet.
- **Gerechnet und von Hand.** Gerechnete Beträge sind gekennzeichnet (kursiv, gestrichelter Rand, Hilfetext). Wer in
  ein solches Feld selbst einen Betrag schreibt, schaltet das Rechnen für diese Zeile ab; ein geleertes Feld schaltet
  es wieder ein. Eine gespeicherte Buchung wird beim Öffnen nicht neu gerechnet.
- **Werte vom Beleg gehen vor.** Liefert das DMS oder nopaper Netto und Steuer und ergeben sie den Bruttobetrag,
  stehen diese Werte in der Buchung (wichtig bei gemischten Sätzen). Sonst wird aus dem Satz gerechnet.
- **Arbeitsvorrat:** Mit vorbelegtem Steuerkonto gilt dasselbe bei „In den Stapel“.

Beispiel (synthetisch): Rechnung 119,00, Aufwand 4930, Vorsteuer 1400 mit 19 %, Bank 1200 → 4930 Soll 100,00 ·
1400 Soll 19,00 · 1200 Haben 119,00. Zweite Aufwandszeile mit 40,00 → die erste sinkt auf 60,00.

**Grenzen:** Bei zwei oder mehr Steuerkonten in einer Buchung rechnet die Maske nicht; die Beträge kommen von Hand,
die Summe muss wie bisher aufgehen. Die einfache Buchung ohne „Aufteilen“ kennt kein Steuerkonto. Der Steuersatz
selbst wird nicht mit der Buchung versiegelt – das leistet erst das Modul `steuer` (F58).

