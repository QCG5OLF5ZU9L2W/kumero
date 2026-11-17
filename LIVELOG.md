# LIVELOG.md – Livelog: zusehen, was kumero gerade tut (Entwurf 1, zur Freigabe)

Stand: 2026-11-17. Bezug: ANWENDUNG.md Abschnitt 3 (Weg einer Anfrage, Server-Logs ohne Buchungsinhalte),
F48 (Protokoll und Ereignisse), F59 (Statusanzeige), PAPERLESS.md, BELEGEINGANG.md, ONLINEKONTEN.md.
**Status: Entwurf. Gebaut wird erst nach Freigabe** (Entscheidungen V1–V8 in Abschnitt 5; V1 und V2 sind am
2026-11-17 entschieden, V3–V8 gelten „nach bestem Bemühen“ wie vorgeschlagen).

Entschieden am 2026-11-17: Das Livelog ist **allgemein**, nicht nur für Paperless. Es zeigt auf Wunsch, was
kumero gerade tut – auch den Ablauf einer Transaktion –, und lässt sich **auf die Module filtern, die gerade
arbeiten**. Es dient dem Testen und der Fehlersuche.

---

## 1. Was es ist – und was nicht

| | Livelog | Protokollkette (F13, F48) | Server-Log |
|---|---|---|---|
| Zweck | zusehen und Fehler finden | Nachweis, unveränderlich | Betrieb |
| Inhalt | Abläufe in Stichworten, nur Metadaten | vollständige Vorher-/Nachher-Stände | Anfrage-ID, Route, Code |
| Dauer | kurz (Stunden) | dauerhaft, gesiegelt | nach Betreiber |
| Sichtbar für | den Nutzer in der Oberfläche | Berechtigte | Betreiber |

Das Livelog ist **kein Nachweis** und ersetzt nichts davon. Es darf jederzeit geleert werden.

## 2. Was es zeigt

Ein Eintrag je Schritt, in der Reihenfolge des Geschehens:

| Quelle | Einträge |
|---|---|
| **Kern – Anfrage** | Beginn und Ende jeder Anfrage: Route, Methode, Antwortstatus, Dauer, Anfrage-ID, bei Fehlern der Code (`KM002`, `recht.fehlt` …). |
| **Kern – Transaktion** | die Schritte dazwischen: Recht geprüft, Idempotenz (neu oder Wiederholung), aufgerufene Kernfunktion (`kern.vorbereiten`, `kern.festschreiben` …), erzeugte Protokollereignisse mit Typ und IDs (`buchung.festgeschrieben`, Journal 17), Commit oder Rollback. |
| **Module** | was ein Modul meldet, mit seinem Namen: `paperless` (Aufruf, Pfad, Paperless-ID, Status, Dauer, „MD5 stimmt“), `belegeingang` (Sidecar angenommen, Code bei Ablehnung), `bank` (Auszug eingelesen: 42 Umsätze, 3 doppelt), `kontenrahmen` (Import: Seiten, Konten). |
| **Hintergrund** | Aufgaben ohne angemeldeten Nutzer: Ereigniszustellung, „Tag in Paperless entfernen“, Wiederholungen. |

Jeder Eintrag trägt: Zeit (auf die Millisekunde), **Modul**, Stufe (Info, Hinweis, Fehler), Anfrage-ID,
Stichwort, Kennungen (IDs, Nummern), Dauer. Einträge derselben Anfrage lassen sich zusammenklappen.

**Nie im Livelog** (Art. 9 DSGVO, CLAUDE.md „Logs enthalten keine Buchungsinhalte“): Buchungstexte,
Beträge, Kontobewegungen, Namen, Verwendungszwecke, Inhalte und Titel von Belegen, Tokens und andere
Geheimnisse. Erlaubt sind technische Kennungen: UUID, Journalnummer, Paperless-ID, Codes, Zähler, Dauern.
Module schreiben nur über die Schnittstelle des Kerns, die freie Texte nicht annimmt (Stichwort aus einer
festen Liste des Moduls plus Kennungen) – so kann kein Modul versehentlich Inhalte hineinschreiben.

## 3. Anzeige

- **Auf Wunsch:** Ein Schalter im Nutzermenü („Livelog“) öffnet unten ein Fenster, das auf jeder Seite
  stehen bleibt und sich laufend füllt. Ohne den Schalter sieht niemand etwas davon.
- **Filter:** nach **Modul** – angeboten werden die Module, die im gewählten Zeitraum tatsächlich
  Einträge erzeugt haben, mit Zähler –, nach Stufe, nach Anfrage-ID und „nur diese Seite“.
- Knöpfe: anhalten, leeren (nur die Anzeige), als Text kopieren (zum Weitergeben bei einer Fehlermeldung).
- Abgefragt wird in kurzen Abständen mit gewöhnlichen Anfragen (`GET …/livelog?seit=…`), reverse-proxy-
  fähig, ohne dauerhaft offene Verbindung. Die Abfragen des Livelogs selbst erscheinen nicht im Livelog.
- Auch ohne Maus bedienbar; neue Einträge werden Vorleseprogrammen nicht einzeln angesagt (PROFIL.md U10).

## 4. Technik

- **Baustein des Kerns** mit einer Schnittstelle in der Modul-API (`Kumero\Api\V1\Livelog`): Module melden
  im Manifest ihre Stichworte an und schreiben Einträge; sie lesen nie fremde.
- **Ablage:** eigene, nicht verkettete Tabelle je Mandant (systemweite Vorgänge in der
  Verwaltungsdatenbank), außerhalb der fachlichen Transaktion geschrieben – ein Eintrag bleibt auch dann
  stehen, wenn die Transaktion zurückgerollt wird (gerade dann will man ihn sehen). Begrenzt auf Zeit und
  Menge (V5), ältere Einträge werden laufend gelöscht.
- Das Livelog darf den Betrieb nie stören: Schlägt das Schreiben eines Eintrags fehl, läuft die Anfrage
  unverändert weiter.
- Endpunkte: `GET /api/v1/livelog` (systemweit, eigene Einträge) und `GET /m/{mandant}/api/v1/livelog`
  mit `seit`, `modul`, `stufe`, `anfrage`.

## 5. Entscheidungen zur Freigabe

| # | Frage | Vorschlag |
|---|---|---|
| V1 | Umfang | **Entschieden am 2026-11-17:** allgemein für Kern und alle Module, einschließlich des Ablaufs der Transaktion; nicht nur Paperless. |
| V2 | Filter | **Entschieden am 2026-11-17:** filterbar auf die Module, die gerade arbeiten. |
| V3 | Inhalt | Nur Metadaten wie in Abschnitt 2; nie Buchungs- oder Beleginhalte, nie Geheimnisse. |
| V4 | Wer sieht was | Jeder sieht die Einträge **seiner eigenen** Anfragen und der Hintergrundaufgaben, die er ausgelöst hat. Der Verantwortliche des Mandanten sieht mit einem eigenen Recht (`kern.livelog.alle`) alle Einträge des Mandanten. Administratoren sehen nur Systemweites (F43). |
| V5 | Aufbewahrung | 24 Stunden, höchstens 10 000 Einträge je Mandant; vom Betreiber einstellbar bis 7 Tage. |
| V6 | Immer mitschreiben? | Ja, immer – nur die Anzeige ist „auf Wunsch“. So ist der Fehler von eben noch zu sehen, wenn man das Livelog erst danach öffnet. Alternative: nur mitschreiben, solange es jemand geöffnet hat. |
| V7 | Tiefe der Transaktion | Schritte wie in Abschnitt 2 (Recht, Idempotenz, Kernfunktion, Ereignisse, Commit/Rollback). Keine SQL-Texte und keine Parameter. |
| V8 | Produktivbetrieb | Auch dort verfügbar, mit denselben Grenzen. Alternative: im Produktivbetrieb nur für den Verantwortlichen. |

**Entschieden am 2026-11-17 zu V3–V8: „best effort“.** Die Vorschläge gelten als Arbeitsgrundlage. Das
Livelog ist ein Hilfsmittel ohne Zusicherung: Es darf Einträge auslassen oder verlieren und wird nie zur
Voraussetzung für einen Ablauf.
