# BUECHER.md – Mehrere Bücher je Mandant

Stand: 2026-11-20 · Entwurf 1 · **B1–B8 freigegeben (F123), nichts davon gebaut.**
Maßgeblich bleibt AGENTS.md. Dieses Dokument ändert **F34** („ein Buch je Datenbank“, Mandant = Buch),
**F39** (Pfad), **F63** (Rollen im Mandanten) und berührt F37, F40, F59, F69, F70, F114, F118 und F120.

## 1. Anlass

Vorgabe des Verantwortlichen vom 2026-11-20: Innerhalb eines Mandanten kann es **mehrere eigenständige
Buchhaltungen** geben. Eine Kanzlei ermittelt z. B. ihre Einnahmen als EÜR und führt zugleich als
Datenschutzbeauftragte eine Doppik. Dazu kommen Bücher **zum Testen und Spielen**, auch mit Übernahmen aus
echten Büchern.

Heute ist ein Mandant genau eine Datenbank mit genau einem Buch (`kern.buch`, eine Zeile). Wer zwei Bücher
braucht, müsste zwei Mandanten anlegen – mit getrennten Nutzern, Passkeys und Anmeldungen (F114).

## 2. Begriffe

| Begriff | Bedeutung |
|---|---|
| **Mandant** | Die Klammer: wer hier arbeitet (Nutzer, Passkeys, Sitzung, Einstellungen) und welche Bücher es gibt. Nutzer gehören weiter zu genau einem Mandanten (F114). |
| **Buch** | Eine eigenständige Buchhaltung: eigenes Journal, eigene Ketten, Nummernkreise, Geschäftsjahre, Konten, Siegel, Module und Rollenzuweisungen. Was AGENTS.md bisher „Mandanten-Datenbank“ nennt, ist künftig die Datenbank **eines Buchs**. |
| **Testbuch** | Ein Buch zum Testen und Spielen. Dauerhaft als solches gekennzeichnet, nie produktiv. |
| **Übernahme** | Ein Testbuch, das als Kopie eines anderen Buchs desselben Mandanten entsteht. |

## 3. Entscheidungen (freigegeben am 2026-11-20)

| Nr. | Entscheidung |
|---|---|
| B1 | **Je Buch eine eigene Datenbank; der Mandant ist die Klammer darüber.** Der Kern bleibt, wie er ist – eine `buch_id` in den Tabellen des Kerns gibt es weiter nicht (F34 hatte sie gestrichen). Die Mandanten-Datenbank hält Nutzer, Anmelde-Passkeys, Registrierung, Sitzung, Einstellungen und die Liste der Bücher. |
| B2 | **Pfad:** `/m/{mandant}/b/{buch}/api/v1/…`, in der Oberfläche `#/m/{mandant}/b/{buch}/…`. Im Kopf der Oberfläche steht die **Auswahl des Buchs**; ein Wechsel des Buchs ist kein neues Anmelden (anders als der Wechsel des Mandanten, U3). |
| B3 | **Rollen werden je Buch zugewiesen.** Wer in einem Buch bucht, sieht ein anderes Buch desselben Mandanten nicht von selbst. Die Rolle Administrator gilt für den Mandanten (Nutzer, Bücher, Zuweisungen) und liest weiter keine Buchungsdaten (F43). |
| B4 | **Der Kern bleibt doppisch (F9).** „EÜR“ oder „Bilanz“ ist ein Merkmal des Buchs (Art der Gewinnermittlung); es steuert, welche Module und Auswertungen sinnvoll sind, nicht das Buchen. |
| B5 | **Module, Kontenrahmen und Vorgaben (z. B. Paperless-Adresse, Belegablage) gelten je Buch.** Der Paperless-Zugang eines Nutzers gilt für alle Bücher seines Mandanten. |
| B6 | **Testbücher sind eine eigene Art von Buch:** dauerhaft gekennzeichnet, in der Oberfläche jederzeit als Testbuch erkennbar, nie auf Produktivbetrieb umstellbar (F59, F70). |
| B7 | **Übernahme aus einem echten Buch:** Kopie der Datenbank in ein Testbuch **desselben Mandanten**, mit neuer Kennung und dem Vermerk „Kopie von … Stand …“. Siegel und Wasserstandsmarke der Kopie gelten nicht als echte. Nie über Mandantengrenzen. **Die beiden offenen Fragen dazu (Abschnitt 6, O1 und O2) bleiben vorerst liegen** (Verantwortlicher, 2026-11-20). |
| B8 | **Bestand:** Jeder heutige Mandant wird ein Mandant mit genau einem Buch; `spiel` wird ein Testbuch. |

## 4. Was wo liegt

| Ort | Inhalt |
|---|---|
| Verwaltungsdatenbank | Mandantenliste, **Bücherliste je Mandant mit Verbindungsdaten**, Pseudonym → Mandant, System-Verwalter, Aufträge, Wasserstandsmarke **je Buch** (F69). Weiter keine Namen, Rollen oder Buchungsdaten. |
| Mandanten-Datenbank | Identität: Nutzer, Anmelde-Passkeys, Registrierung, Sitzung, Einstellungen des Nutzers; Bücher des Mandanten mit Name, Art (B4) und Kennzeichen Testbuch (B6); Zuweisung der Rolle Administrator. Eigenes Protokoll für diese Vorgänge. |
| Datenbank des Buchs | Alles, was heute `kern.*` ohne die Nutzer ist: Konten, Buchungen, Ketten, Geschäftsjahre, Nummernkreise, Freigaben, Siegel, Rollen und Zuweisungen des Buchs, aktive Module mit ihren Schemata. |

Nutzer erscheinen im Buch weiter nur als Subjekt `nutzer:<uuid>`; die ID verlässt den Mandanten nicht (F114).

## 5. Folgen im Bestand

1. **Trennung der heutigen Mandanten-Datenbank.** Sie enthält seit 0.7.22 Identität **und** Buch. Beim Umbau
   bleibt die vorhandene Datenbank die des (ersten) Buchs – Journal und Ketten werden nicht angefasst, kein Hash
   ändert sich. Die Identität zieht in eine neue Mandanten-Datenbank um, mit gleichen IDs (wie U6).
2. **Freigabe-Passkeys.** `kern.credential` liegt im Buch, und die Datenbank prüft selbst, dass das Credential
   zum Subjekt gehört (F72). Ein Nutzer braucht seinen Freigabe-Passkey also in jedem Buch, in dem er freigibt
   → O3.
3. **Sitzung und Anfrage.** Eine Sitzung gilt für den Mandanten; je Anfrage öffnet der Kernel die Datenbank des
   Buchs aus dem Pfad und prüft dort die Rolle. Globale Routen (`/api/v1/anmeldung`, `/ich`, `/registrierung`)
   bleiben; neu ist `/m/{mandant}/api/v1/…` für das, was zum Mandanten gehört (Bücher, Nutzer, Zuweisungen).
4. **Mandantenverwaltung (F118).** Stilllegen, Exportieren und Löschen gelten für den Mandanten mit allen
   Büchern → O2. Anlegen eines Mandanten legt weiter den ersten Administrator an, aber noch kein Buch; Bücher
   legt der Administrator an (Auftrag an den Dienst `kumero-auftraege`, wie beim Mandanten).
5. **Siegel (F120).** Der Siegelinhalt nennt Mandant **und** Buch. Öffentliche Schlüssel bleiben je Buch, bis
   anders entschieden.
6. **Kommandozeile, Tests, INSTALL.md.** `modul:aktivieren <modul> <mandant> <buch>`, `buch:anlegen`,
   `buch:liste`; `tests/Hilfe/Zugaenge.php` legt Mandant, Buch, Identität und Zuweisung an.
7. **Benutzerverwaltung Stufe 2** (VERWALTUNG.md Abschnitt 6 und 8) baut auf diesem Schnitt auf: Die Seite
   „Nutzer und Rollen“ weist je Buch zu, die Seite „Module“ gilt je Buch, neu ist die Seite „Bücher“.

## 6. Offen

| Nr. | Frage | Stand |
|---|---|---|
| O1 | **Belegverweise in einer Übernahme:** Die Kopie trägt die echten Links nach Paperless. Ein Storno oder Verwerfen im Testbuch setzt oder entfernt dort Tags am echten Beleg. Vorschlag: In Testbüchern schreibt kumero nie in ein DMS. | liegt (2026-11-20) |
| O2 | **Löschen und Export je Buch** oder nur je Mandant? Vorschlag: Testbücher lassen sich einzeln löschen, echte Bücher nur mit dem Mandanten oder nach Export wie in F118. | liegt (2026-11-20) |
| O3 | **Freigabe-Passkey je Buch:** einmal im Mandanten einrichten und in jedes Buch eintragen, in dem der Nutzer eine Rolle mit Freigabe hat – oder je Buch neu einrichten? Vorschlag: einmal einrichten; die Zuweisung trägt das Credential ins Buch ein, protokolliert. | zu entscheiden vor Stufe B |
| O4 | **Kennung des Buchs:** wie die des Mandanten (`^[a-z][a-z0-9-]{1,31}$`), eindeutig je Mandant; Name der Datenbank `kumero_<mandant>_<buch>` (Grenze 40 Zeichen beachten). | Vorschlag |
| O5 | **Buchwährung und Sprache** je Buch (stehen heute in `kern.buch`) – bleibt so. | Vorschlag |
| O6 | **Arbeitsvorrat:** Einträge gehören dem Buch (W8 sagt „dem Mandanten“). Eine Quelle (Paperless-Tag, Datei) liefert in das Buch, in dem sie eingerichtet ist. | Vorschlag |
| O7 | **Startseite:** Ohne gewähltes Buch zeigt der Rahmen die Bücher des Mandanten, auf die der Nutzer eine Zuweisung hat; bei genau einem Buch geht es direkt hinein. | Vorschlag |

## 7. Stufen (Vorschlag)

| Stufe | Inhalt |
|---|---|
| A | **Vertrag:** `openapi/v1.yaml` mit dem neuen Pfad (B2) und den Endpunkten des Mandanten (Bücher, später Nutzer und Zuweisungen); DATENMODELL.md und ANWENDUNG.md nachziehen. Kein Code. |
| B | **Umbau:** Verwaltungsdatenbank (Bücherliste), neue Mandanten-Datenbank, Umzug der Identität mit gleichen IDs, Kernel öffnet das Buch aus dem Pfad, Rahmen mit Auswahl des Buchs, Kommandozeile. Ein Buch je Mandant, sonst alles wie bisher. Überführen des Bestands in `aktualisieren.sh`, wiederholbar. |
| C | **Zweites Buch:** Buch anlegen (Auftrag + Dienst), Art des Buchs (B4), Zuweisung je Buch (B3), Module je Buch (B5). |
| D | **Testbuch und Übernahme** (B6, B7) – nach O1 und O2. |

Stufe B ist der große Schritt und ändert Kern und Verwaltung; sie ist eine eigene Version mit einem einmaligen
Schritt auf der VM (INSTALL.md Abschnitt 4).
