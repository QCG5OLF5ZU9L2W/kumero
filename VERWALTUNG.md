# VERWALTUNG.md – Mandanten, Nutzer, Zugang und Rollen (Entwurf 2, zur Freigabe)

Stand: 2026-11-18. Bezug: F34 (ein Buch je Datenbank), F37 (Verwaltungsdatenbank), F40 (Mandant anlegen),
F41–F43 (RBAC, Rollenvorlagen, Invarianten), F63 (Rollen liegen im Mandanten), F77 E2 (Anmeldung per Passkey),
F80 N3 (Bereich „Verwaltung“), F84 Nr. 6 (Registrierungslink), F86 (Nutzerprofil), F106 (nichts verschiebt sich).
**Status: Entwurf. Die Grundsätze sind am 2026-11-18 entschieden (F113, F114); gebaut ist nichts davon.**
Entwurf 2 ersetzt Entwurf 1 in drei Punkten: **Nutzer gehören zum Mandanten** (Abschnitt 2), **Link und Code werden
beide gebraucht** (Abschnitt 6), **das Löschen eines Mandanten hängt nicht an § 147 AO** (Abschnitt 5).
Alle Fragen V1–V18 und U1–U8 sind entschieden. Die Lesart des Pseudonyms (Abschnitt 3) ist nicht ausdrücklich bestätigt und gilt als Arbeitsannahme.

Anlass: Mandanten, Nutzer, Zugänge und Module lassen sich heute nur auf der Kommandozeile pflegen
(`mandant:anlegen`, `nutzer:anlegen`, `nutzer:link`, `zugang:gewaehren`, `modul:aktivieren`). Die Weboberfläche zeigt
im Bereich „Verwaltung“ nur den Kontenrahmen-Import.

---

## 1. Zwei Ebenen

| | **System-Verwalter** | **Mandanten-Verwalter** |
|---|---|---|
| Was es ist | eine Identität der Installation (Verwaltungsdatenbank) | eine Identität **im Mandanten** mit der Rolle **„Administrator“** (Vorlage nach F42) |
| Gilt für | die Installation | genau einen Mandanten |
| Darf | Mandanten anlegen, stilllegen, löschen · **die Administratoren der Mandanten vergeben** · Kontenrahmen-Vorlagen | **Nutzer seines Mandanten anlegen und Zugänge vergeben** · Rollen zuweisen und beenden · Module ein- und ausschalten · Vorgaben des Mandanten |
| Buchungsdaten | nein (F43) | nein (F43) |

- **Der System-Verwalter darf in jedem Mandanten jede Rolle vergeben** (bestätigt am 2026-11-18). Im Normalfall setzt er
  nur den Administrator ein; der vergibt den Rest. Will er selbst in einem Mandanten arbeiten, legt er sich dort eine
  Identität an – wie jeder andere (Abschnitt 2). Er hat ein Recht erst mit der protokollierten Zuweisung (F48).
- **Freigaben und Notfall-Ereignisse bleiben beim Verantwortlichen**, mit persönlicher Bestätigung per Passkey (F38).
- **Der Prüfer bleibt technisch nur lesend** (F43).

## 2. Nutzer gehören zum Mandanten, nicht zur Installation

Entscheidung des Verantwortlichen vom 2026-11-18 (F114): **Ein Nutzer ist eine Identität in genau einem Mandanten.**
Arbeitet eine natürliche Person für mehrere Mandanten, hat sie **in jedem eine eigene Identität** – mit eigenem Passkey,
eigenen Rollen, eigenen Einstellungen. Grund: Gehören Nutzer zur Installation, kann eine einzige Fehlkonfiguration allen
Mandanten alle Informationen offenlegen. Mit Identitäten je Mandant gibt es diese Stelle nicht.

**Das ändert den Bestand.** Heute liegen Nutzer, Passkeys, Sitzungen und Einstellungen in der Verwaltungsdatenbank; eine
Tabelle `zugang` sagt, wer in welchen Mandanten darf (F37). Künftig:

| | heute | künftig |
|---|---|---|
| Nutzer (Name, Kontaktdaten) | Verwaltungsdatenbank | **Mandanten-Datenbank** |
| Anmelde-Passkeys | Verwaltungsdatenbank | **Mandanten-Datenbank** |
| Zugang zum Mandanten | Tabelle `zugang` in der Verwaltungsdatenbank | **entfällt** – wer im Mandanten als Nutzer steht, hat Zugang; was er darf, sagen die Rollen (F63) |
| Sitzung | systemweit | **je Mandant** |
| Einstellungen des Nutzers (F86) | Verwaltungsdatenbank | **Mandanten-Datenbank** |
| System-Verwalter | Kennzeichen am Nutzer | **eigene Identität** der Installation, getrennt von allen Mandanten |
| Verwaltungsdatenbank | Mandanten, Nutzer, Zugänge | **nur noch** Mandantenliste, Verbindungsdaten, System-Verwalter, Wasserstandsmarken |

Folgen, die gewollt sind:

- **Mit dem Mandanten gehen seine Nutzer.** Wird ein Mandant gelöscht, ist von seinen Nutzern nichts mehr da –
  auch der letzte Administrator „geht mit dem Mandanten“.
- **Die Sicherung eines Mandanten ist vollständig**: Buch, Rollen, Nutzer, Passkeys in einer Datenbank.
- **Der Administrator eines Mandanten kann nichts außerhalb seines Mandanten berühren** – die Frage aus Entwurf 1, ob er
  sich über einen neuen Zugang das Konto eines Nutzers in einem anderen Mandanten verschaffen könnte, stellt sich nicht mehr.

## 3. Der Umbau

Das ist ein Umbau an Anmeldung, Sitzung und Verwaltungsdatenbank (ändert F37, F77, F86). Er ist jetzt billig, weil es nur
Testdaten gibt (wie bei F88). Entscheidungen des Verantwortlichen vom 2026-11-18:

| # | Frage | Stand |
|---|---|---|
| U1 | **Wo meldet man sich an?** | **Entschieden: Der Passkey gehört zu einem Mandanten – damit ist die Anmeldung bestimmt.** Es gibt eine Anmeldeseite für die Installation; der vorgelegte Passkey sagt, in welchen Mandanten es geht. Dafür braucht es ein **systemweites Pseudonym** (siehe unten). |
| U2 | **Passkeys** | **Entschieden:** je Identität eigene. Eine Person mit zwei Mandanten hat zwei Passkeys; der Eintrag im Passkey-Speicher nennt den Mandanten. |
| U3 | **Mandant wechseln** | **Entschieden: neue Anmeldung.** Die Auswahl „Mandant“ im Rahmen entfällt; wer in einen anderen Mandanten will, meldet sich mit dem Passkey dieses Mandanten an. |
| U4 | **System-Verwalter** | **Entschieden wie U1:** dieselbe Anmeldeseite; sein Passkey gehört zur Installation statt zu einem Mandanten. Er sieht Mandantenliste und Verwaltungsseiten, keinen Mandanten von innen. |
| U5 | **Administrator einsetzen** | **Entschieden: Mandant anlegen und ersten Administrator einsetzen sind ein Vorgang.** Der System-Verwalter legt einen Mandanten an, indem er dessen ersten Administrator einsetzt – einen Mandanten ohne Administrator gibt es nicht. Weitere Administratoren und andere Rollen vergibt er über dieselbe Funktion der Mandanten-Datenbank (Identität mit Rolle anlegen, Zugang ausstellen); protokolliert im Mandanten und in der Verwaltung. |
| U6 | **Bestand überführen** | **Entschieden:** Jeder heutige Nutzer wird in jedem Mandanten, zu dem er Zugang hat, eine Identität – mit derselben ID, damit Protokoll und Buchungen (`nutzer:<uuid>`) stimmen. Passkeys siehe unten. Heutige Verwalter bleiben System-Verwalter. |
| U7 | **Dienstkonten und Tokens** | **Entschieden:** wandern ebenfalls in den Mandanten (spätere Stufe). |
| U8 | **Der Verwalter behält seinen Spielmandanten** | **Entschieden:** Der System-Verwalter hat im Testmandanten eine eigene Identität mit Rollen – sonst kann er nicht testen, entwickeln oder Fehler nachstellen. Allgemein: Ein System-Verwalter darf in einem Mandanten eine Identität haben wie jeder andere; getestet und nachgestellt wird im Testmandanten, nicht im Mandanten eines Kunden. |

### Das systemweite Pseudonym (zu U1) – Lesart, Arbeitsannahme

Die Anmeldeseite kennt keinen Mandanten. Der Browser legt einen Passkey vor; kumero muss daraus den Mandanten bestimmen,
ohne dass in der Verwaltungsdatenbank wieder Nutzer stehen.

- Jede Identität bekommt beim Einrichten des Passkeys ein **Pseudonym**: einen zufälligen Wert ohne Bedeutung
  (32 Byte), der im Passkey als Nutzerkennung steckt (WebAuthn `user.id`). Der Browser liefert ihn bei der Anmeldung mit.
- Die Verwaltungsdatenbank führt **nur die Zuordnung Pseudonym → Mandant** (bzw. → Installation für System-Verwalter).
  **Kein Name, keine E-Mail, keine Rolle, kein Schlüssel.** Wer diese Tabelle liest, erfährt, wie viele Identitäten ein
  Mandant hat – sonst nichts.
- Geprüft wird der Passkey **im Mandanten**: Dort liegen öffentlicher Schlüssel, Identität und Rollen. Ein falscher
  Eintrag in der Zuordnung führt in einen Mandanten, der den Passkey nicht kennt – die Anmeldung scheitert. **Die
  Zuordnung kann keinen Zugang verschaffen**, auch nicht durch eine Fehlkonfiguration.
- Die ID des Nutzers in Protokoll und Buchungen (`nutzer:<uuid>`) bleibt etwas anderes als das Pseudonym und verlässt den
  Mandanten nicht.
- **Folge für U6:** Vorhandene Passkeys tragen als Nutzerkennung die bisherige Nutzer-ID. Für Nutzer mit genau einem
  Mandanten wird diese ID als Pseudonym eingetragen; der Passkey gilt weiter. Wer heute Zugang zu mehreren Mandanten hat,
  behält seinen Passkey für **einen** Mandanten (Vorschlag: den Testmandanten bzw. den zuerst gewährten) und richtet für
  die übrigen über Link und Code einen neuen ein.

## 4. Wer nicht entfernt werden kann

- **Der letzte System-Verwalter** lässt sich nicht entfernen und nicht sperren.
- **Der letzte Administrator eines Mandanten** lässt sich nicht einzeln entfernen – **er geht mit dem Mandanten**.
- Für den **letzten Verantwortlichen** gilt dasselbe (entschieden): Er wird nicht einzeln entfernt. **Entfernt wird der Mandant – durch den System-Verwalter.**

## 5. Mandant löschen

Ein Mandant muss ganz verschwinden können. Heute gibt es nur das **Stilllegen** (die Datenbank bleibt).

**Grundlage (Entscheidung vom 2026-11-18):** Das ist **keine Frage des § 147 AO** – für die Aufbewahrung seiner Bücher ist
der **Mandant** verantwortlich, nicht der Betreiber. Fällt der Vertrag zwischen Betreiber und Mandant weg (der
Verantwortliche nennt Art. 26 DSGVO), entfällt für den Betreiber die Rechtsgrundlage nach Art. 6 Abs. 1 DSGVO – die Daten
sind zu löschen. kumero prüft deshalb keine Aufbewahrungsfristen und hält ein Löschen ihretwegen nicht auf.

| Schritt | Was geschieht |
|---|---|
| **Stilllegen** | Niemand kann sich mehr anmelden; die Daten bleiben. Umkehrbar. |
| **Herausgeben** | Vollständiger Export für den Mandanten (L2) – damit er seiner Aufbewahrungspflicht nachkommen kann. Angeboten, nicht erzwungen. |
| **Löschen** | Die Datenbank des Mandanten wird entfernt – mit Buch, Nutzern, Passkeys, Rollen (Abschnitt 2) –, dazu Wasserstandsmarke und Eintrag in der Mandantenliste. **Nicht umkehrbar.** |

1. Nur ein **stillgelegter** Mandant lässt sich löschen.
2. Die Oberfläche nennt vorher, was verloren geht (Zahl der Buchungen, letztes Buchungsdatum, Betriebsart) und ob ein
   Export gezogen wurde.
3. Bestätigt wird durch Eintippen der Kennung.
4. In der Verwaltungsdatenbank bleibt ein Protokolleintrag: Kennung, Zeitpunkt, wer gelöscht hat, letzter Kettenkopf –
   keine Buchungsdaten, kein Name (entschieden).
5. Sicherungen und die Siegelkopien außer Haus (F35) kann kumero nicht löschen; die Oberfläche sagt das, die
   Verfahrensdokumentation regelt es.

## 6. Zugang ausstellen: Link **und** Code

Angemeldet wird weiter **nur per Passkey** (F77 E2). Wer neu ist oder seinen Passkey verloren hat, braucht einmalig die
Berechtigung, einen Passkey einzurichten. Dafür braucht es **beides** (Entscheidung vom 2026-11-18):

1. **Der Link** führt auf die Seite „Passkey einrichten“ dieses einen Zugangs (langes Token im Fragment, F84 Nr. 6).
2. **Die Seite fragt den Code ab** – sechs Zeichen. Erst Link, dann Code.

**Der Code hängt am Link.** Ohne den Link gibt es nichts, woran sich ein Code ausprobieren ließe; ohne den Code nützt ein
abgefangener Link nichts. Link und Code gehen auf **zwei verschiedenen Wegen** zum Nutzer (z. B. Link per Mail oder
Nachricht, Code am Telefon oder auf einem Zettel).

| | |
|---|---|
| Form des Codes | sechs Zeichen, ohne verwechselbare Zeichen (kein 0/O, 1/l/I), Groß-/Kleinschreibung egal |
| Gültigkeit | 24 Stunden, beim Ausstellen kürzbar – für Link und Code gemeinsam |
| Fehlversuche | nach fünf falschen Codes ist der Zugang ungültig; es wird ein neuer ausgestellt |
| Mehrere Zugänge | ein neuer Zugang macht den vorigen derselben Identität ungültig |
| Anzeige | Link und Code zeigt kumero **einmal** an; gespeichert sind nur Hashes; Protokoll und Logs nennen sie nie |
| Wer ausstellt | der Administrator für die Nutzer seines Mandanten; der System-Verwalter für die Administratoren |

**Was der Nutzer beim Einrichten angibt:** Vorname(n), Familienname und Kontaktdaten, mindestens die E-Mail-Adresse.
Danach registriert er seinen Passkey; damit ist der Zugang verbraucht.

**Geführt wird der Nutzer mit einer eindeutigen ID** (UUID) – wie heute. Sie steht in Protokoll und Buchungen
(`nutzer:<uuid>`); Name und Kontaktdaten sind Stammdaten daneben und lassen sich ändern, ohne dass sich ein Hash ändert.

**Verlorener Passkey:** derselbe Weg; wer ausstellt, wählt, ob der alte Passkey widerrufen wird.

## 7. Zurückgestellt: Zugang selbst erneuern (eigenes Modul)

Entscheidung vom 2026-11-18: **geplant, aber zurückgestellt.** Damit sich die Administratoren nicht „tot administrieren“,
soll ein Nutzer einen verlorenen Zugang später selbst erneuern können – als **eigenes Modul**. kumero verschickt dafür
E-Mails, schlicht über einen **lokalen Postfix** auf dem Server (kein fremder Dienst, keine externe API). Dafür ist die
E-Mail-Adresse aus Abschnitt 6 da. Zu beschreiben, wenn es aufgerufen wird: was die Mail enthält (Link) und wie der
zweite Weg für den Code aussieht, wenn kein Mensch mehr dazwischen ist.

## 8. Seiten

Bereich **„Verwaltung“** (nur System-Verwalter, eigene Anmeldung):

| Seite | Inhalt |
|---|---|
| **Mandanten** | Liste mit Kennung, Name, Betriebsart, Zustand · **anlegen = ersten Administrator einsetzen** (aus der Vorlage, F40; U5) · weiteren Administrator einsetzen · stilllegen · herausgeben · löschen |
| **System-Verwalter** | Liste · weiteren einsetzen · Passkey widerrufen |

Bereich **„Einstellungen“** im Mandanten (Rolle Administrator):

| Seite | Inhalt |
|---|---|
| **Nutzer und Rollen** | Liste mit Vorname(n), Familienname, E-Mail, Rollen, aktiv, Passkeys, letzte Anmeldung · Nutzer anlegen · Zugang ausstellen (Link und Code) · Passkey widerrufen · sperren · Rolle zuweisen, befristen, beenden |
| **Module** | aktive Module, ein- und ausschalten |
| **Rollen** (zweite Stufe) | eigene Rollen aus Bausteinen, Rechtebericht (F41 Nr. 7) |

Alle Seiten nach F106: fester Kopf, Aktionen gelten für die markierten Einträge, gesperrte Knöpfe nennen den Grund.

## 9. REST

Erst der Vertrag in `openapi/v1.yaml`, dann der Code (F18).

| Methode | Pfad | Zweck |
|---|---|---|
| POST | `/api/v1/anmeldung/optionen`, `/api/v1/anmeldung`, `/abmeldung` | Anmeldung für die Installation; der Passkey bestimmt über sein Pseudonym den Mandanten (U1) |
| GET / POST / PATCH | `/m/{mandant}/api/v1/nutzer`, `/nutzer/{id}` | Nutzer des Mandanten auflisten, anlegen, ändern, sperren |
| POST | `/m/{mandant}/api/v1/nutzer/{id}/zugang` | Zugang ausstellen; die Antwort nennt Link und Code einmal |
| DELETE | `/m/{mandant}/api/v1/nutzer/{id}/passkeys/{credential}` | Passkey widerrufen |
| GET / POST / DELETE | `/m/{mandant}/api/v1/zuweisungen` | Rollen zuweisen, beenden |
| GET / PUT | `/m/{mandant}/api/v1/module` | Module des Mandanten |
| POST | `/m/{mandant}/api/v1/registrierung/code` | Code zum Link prüfen (ohne Anmeldung; zählt Fehlversuche) |
| GET / POST | `/api/v1/verwaltung/mandanten` | auflisten, anlegen |
| POST | `/api/v1/verwaltung/mandanten/{kennung}/administrator` | Administrator einsetzen (U5) |
| POST | `/api/v1/verwaltung/mandanten/{kennung}/stilllegen`, `/reaktivieren` | stilllegen und zurück |
| DELETE | `/api/v1/verwaltung/mandanten/{kennung}` | löschen (Abschnitt 5) |

## 10. Stufen

| Stufe | Inhalt |
|---|---|
| **1** | Umbau: Nutzer, Passkeys, Sitzung und Einstellungen in den Mandanten (Abschnitt 2 und 3), Anmeldung über das Pseudonym, System-Verwalter als eigene Identität, Überführen des Bestands, Identität des Verwalters im Testmandanten |
| **2** | Zugang mit Link und Code · Seiten „Nutzer und Rollen“, „Module“, „Mandanten“ (ohne Löschen) · Administrator einsetzen |
| **3** | Mandant herausgeben und löschen · Dienstkonten und Tokens · eigene Rollen · Rechtebericht |
| zurückgestellt | Zugang selbst erneuern mit E-Mail über Postfix (Abschnitt 7) · Anmeldung über Keycloak / OIDC (T6) |

## 11. Entscheidungen

| # | Frage | Stand |
|---|---|---|
| V1 | Zwei Ebenen: System-Verwalter und Mandanten-Verwalter (Rolle „Administrator“) | **entschieden 2026-11-18** |
| V2 | Der System-Verwalter darf in jedem Mandanten jede Rolle vergeben; immer protokolliert | **entschieden 2026-11-18**, bestätigt |
| V3 | Freigaben und Notfall-Ereignisse bleiben beim Verantwortlichen, mit Passkey | **entschieden 2026-11-18** |
| V4 | Der letzte System-Verwalter lässt sich nicht entfernen | **entschieden 2026-11-18** |
| V5 | Der letzte Administrator eines Mandanten geht mit dem Mandanten | **entschieden 2026-11-18** |
| V6 | Ein Mandant lässt sich löschen | **entschieden 2026-11-18** |
| V7 | Der Prüfer bleibt nur lesend | **entschieden 2026-11-18** |
| V8 | Zugang ausstellen: **Link und Code, beide nötig**; der Code hängt am Link; kein Passwort zum Anmelden | **entschieden 2026-11-18** (ändert Entwurf 1: nicht „zweiter Weg“) |
| V9 | Der letzte Verantwortliche eines Mandanten | **entschieden 2026-11-18:** wird nicht einzeln entfernt; der System-Verwalter entfernt den Mandanten |
| V10 | Löschen und Aufbewahrungsfrist | **entschieden 2026-11-18:** keine Frage des § 147 AO; verantwortlich ist der Mandant. kumero prüft keine Fristen; Export wird angeboten. |
| V11 | Was nach dem Löschen in der Verwaltungsdatenbank bleibt | **entschieden 2026-11-18:** Kennung, Zeitpunkt, wer, letzter Kettenkopf – sonst nichts |
| V12 | Form des Codes | **entschieden 2026-11-18:** sechs Zeichen |
| V13 | Gültigkeit und Fehlversuche | **entschieden 2026-11-18:** 24 Stunden, kürzbar; nach 5 falschen Eingaben ungültig |
| V14 | Angaben des Nutzers | **entschieden 2026-11-18:** Vorname(n), Familienname, Kontaktdaten, mindestens E-Mail; geführt mit eindeutiger ID |
| V15 | Mehrere offene Zugänge | **entschieden 2026-11-18:** der neue macht den vorigen ungültig |
| V16 | Wer Zugänge ausstellt | **entschieden 2026-11-18:** der Administrator für seinen Mandanten; der System-Verwalter für die Administratoren |
| V17 | Nutzer gehören zum Mandanten, nicht zur Installation; eine Person mit mehreren Mandanten hat je Mandant eine Identität | **entschieden 2026-11-18** (F114) |
| V18 | Zugang selbst erneuern, E-Mail über lokalen Postfix, eigenes Modul | **geplant, zurückgestellt (2026-11-18)** |
| U1–U8 | Umbau nach Abschnitt 3 | **entschieden 2026-11-18**; die Lesart des Pseudonyms ist nicht ausdrücklich bestätigt und gilt als Arbeitsannahme |
