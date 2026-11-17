# BUCHUNGSMASKE.md – Buchungsliste und Buchungserfassung (Entwurf 1, zur Freigabe)

Stand: 2026-11-17. Bezug: F4, F8, F24–F27, F30–F32, F38, F66–F68, F78; Vorbild: Bedienung von Sage 50
(Aufbau und Abläufe, nicht Aussehen, Logo oder Begriffe).
**Status: Entwurf. Gebaut wird erst nach Freigabe** (Entscheidungen M1–M8 in Abschnitt 6).

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
- Braucht aus openapi/v1.yaml: Buchungen (anlegen, ändern, löschen, vorbereiten), Freigaben (Challenge,
  Freigeben), Storno, Journal mit Filtern, Salden, Kontoblatt; neu: **Geschäftsvorfall-Vorlagen**
  (`/vorlagen/buchung`) und **zuletzt benutzte Texte**.
- Die Maske läuft im Rahmen nach OBERFLAECHE.md (seit 0.6.1, F80) als Seiten des Kerns im Bereich „Buchen“.

## 6. Entscheidungen zur Freigabe

| # | Frage | Vorschlag |
|---|---|---|
| M1 | Liste und Formular | **Beides** wie im Vorbild (Abschnitt 1 und 2), Splitbuchungen als Zeilentabelle im Formular. |
| M2 | Konto/Gegenkonto | Konto = Soll, Gegenkonto = Haben, mit Umkehrtaste; laufende Salden neben den Konten. |
| M3 | Buchungsdatum | standardmäßig gleich Belegdatum, getrennt änderbar. |
| M4 | Speichern / Buchen | zwei Knöpfe: Speichern = Stapel (Entwurf), Buchen = festschreiben (nur mit Recht). |
| M5 | Geschäftsvorfälle | als eigene, vom Nutzer gepflegte Vorlagen; mitgeliefert ein paar allgemeine. |
| M6 | Tastenbelegung | wie in Abschnitt 1 und 2; später anpassbar. |
| M7 | Reihenfolge | **Buchen über REST und diese Maske als 0.7.0**, die EÜR-Zuordnung danach als 0.8.0 – damit du früher buchen und testen kannst. |
| M8 | Sprache | Oberfläche zuerst Deutsch, Texte aber von Anfang an aus einer Übersetzungsdatei (T5). |
