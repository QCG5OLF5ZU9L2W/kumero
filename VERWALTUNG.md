# VERWALTUNG.md – Mandanten, Nutzer, Zugang und Rollen (Entwurf 2; Stufe 1 gebaut in 0.7.22)

Stand: 2026-11-18. Bezug: F34 (ein Buch je Datenbank), F37 (Verwaltungsdatenbank), F40 (Mandant anlegen),
F41–F43 (RBAC, Rollenvorlagen, Invarianten), F63 (Rollen liegen im Mandanten), F77 E2 (Anmeldung per Passkey),
F80 N3 (Bereich „Verwaltung“), F84 Nr. 6 (Registrierungslink), F86 (Nutzerprofil), F106 (nichts verschiebt sich).
**Status: Die Grundsätze sind am 2026-11-18 entschieden (F113, F114). Stufe 1 – der Umbau – ist in 0.7.22 gebaut (F115,
Abschnitt 13); Stufe 2 und 3 sind nicht gebaut.**
Entwurf 2 ersetzt Entwurf 1 in drei Punkten: **Nutzer gehören zum Mandanten** (Abschnitt 2), **Link und Code werden
beide gebraucht** (Abschnitt 6), **das Löschen eines Mandanten hängt nicht an § 147 AO** (Abschnitt 5).
Alle Fragen V1–V18 und U1–U8 sind entschieden. Die Lesart des Pseudonyms (Abschnitt 3) hat der Verantwortliche am 2026-11-18 vor dem Bauen der Anmeldung bestätigt (F115).

Anlass: Mandanten, Nutzer, Zugänge und Module lassen sich heute nur auf der Kommandozeile pflegen
(`mandant:anlegen`, `nutzer:anlegen`, `nutzer:rolle`, `nutzer:link`, `verwalter:anlegen`, `modul:aktivieren`). Die Weboberfläche zeigt
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

### Das systemweite Pseudonym (zu U1) – bestätigt am 2026-11-18 (F115)

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
| **1 – gebaut (0.7.22, F115)** | Umbau: Nutzer, Passkeys, Sitzung und Einstellungen in den Mandanten (Abschnitt 2 und 3), Anmeldung über das Pseudonym, System-Verwalter als eigene Identität, Überführen des Bestands, Identität des Verwalters im Testmandanten |
| **2** | Zugang mit Link und Code · Seiten „Nutzer und Rollen“, „Module“, „Mandanten“ (ohne Löschen) · Administrator einsetzen |
| **3** | Mandant herausgeben und löschen · Dienstkonten und Tokens · eigene Rollen · Rechtebericht |
| zurückgestellt | Zugang selbst erneuern mit E-Mail über Postfix (Abschnitt 7) · Anmeldung über Keycloak / OIDC (T6) |

## 11. Bauplan für Stufe 1 (aus dem Bestand abgeleitet, 2026-11-18)

Damit der Umbau ohne erneutes Einlesen beginnen kann. Betroffen sind heute `src/Plattform/Identitaet.php`, `Kernel.php`,
`Einstellungen.php`, `src/Http/AnmeldungController.php`, `src/Konsole/Befehle.php`, `db/verwaltung/0001` und `0002`,
der Rahmen der Oberfläche sowie **alle** PHP- und Browsertests (jeder legt Nutzer an und meldet sich an).

**Mandanten-Datenbank (neue Migration des Kerns):** `kern.nutzer` (ID, Vorname(n), Familienname, E-Mail, Sprache, aktiv,
Pseudonym), `kern.anmelde_credential`, `kern.registrierung` (Hash des Tokens, Hash des Codes, Ablauf, Fehlversuche),
`kern.sitzung`, `kern.nutzer_einstellung` – mit denselben Funktionen, die heute im Schema `verwaltung` liegen
(anlegen, Zugang ausstellen, Passkey registrieren und widerrufen, Anmeldung vermerken, Sitzung anlegen/prüfen/beenden,
Sprache, Einstellung), jeweils mit Eintrag im Protokoll des Mandanten (F48, F64).

**Verwaltungsdatenbank (neue Migration):** Tabelle `verwaltung.pseudonym` (Pseudonym → Mandant, sonst nichts).
`verwaltung.nutzer`, seine Passkeys und Sitzungen bleiben – künftig **nur für System-Verwalter**. Die Tabelle `zugang`
wird nicht mehr gelesen und nach der Überführung geleert.

**Anmeldung:** `POST /api/v1/anmeldung` liest das Pseudonym aus der Antwort des Passkeys (`userHandle`), schlägt den
Mandanten nach und prüft den Passkey **in dessen Datenbank** (System-Verwalter: in der Verwaltung). Die Challenge bleibt
systemweit. Das Sitzungs-Cookie nennt den Mandanten und das Geheimnis; geprüft wird die Sitzung im Mandanten. **Ein
Browser hat eine Sitzung** – Mandant wechseln heißt neu anmelden (U3).

**Weg einer Anfrage (`Kernel`):** Auf Mandantenpfaden gilt eine Sitzung nur für ihren Mandanten (heute schon so für
Tokens); die Prüfung gegen `verwaltung.zugang` entfällt. `/api/v1/ich` und `/ich/einstellungen` arbeiten für Nutzer in
der Datenbank ihres Mandanten und nennen genau diesen einen Mandanten.

**Zugang (Link):** Das Token im Link nennt den Mandanten, damit die Seite ohne Anmeldung weiß, wohin. Der Code
(Abschnitt 6) kommt mit Stufe 2; bis dahin gilt der Link allein wie heute.

**Kommandozeile:** `mandant:anlegen <kennung> <name> --administrator <Vorname> <Familienname> <E-Mail>` (U5) gibt den Link
des ersten Administrators aus · `nutzer:anlegen <mandant> … --rolle …` · `nutzer:link <mandant> <nutzer>` ·
`verwalter:anlegen`, `verwalter:link` für System-Verwalter · `zugang:gewaehren` entfällt. **`verwalter:link` ist der
Notausgang**, falls nach der Überführung niemand mehr hineinkommt.

**Überführung des Bestands (U6):** ein Befehl `system:nutzer-ueberfuehren`, den `aktualisieren.sh` nach den Migrationen
einmal aufruft (zwei Datenbanken – das geht nicht in einer SQL-Migration): Je Mandant und Nutzer mit Zugang eine
Identität mit derselben ID; Name wird Familienname, Vorname und E-Mail bleiben leer und werden bei der nächsten Anmeldung
abgefragt. Vorhandene Passkeys tragen die Nutzer-ID als Kennung; sie wird als Pseudonym für **einen** Mandanten
eingetragen (Testmandant, sonst der zuerst gewährte). System-Verwalter bleiben in der Verwaltung, bekommen dort aber
einen neuen Passkey über `verwalter:link` – ihr bisheriger gehört jetzt zum Mandanten.

**Oberfläche:** Die Auswahl „Mandant“ im Rahmen entfällt; nach der Anmeldung geht es in den Mandanten des Passkeys
bzw. für System-Verwalter in den Bereich „Verwaltung“. Kontenrahmen-Import und Vorlagen bleiben beim System-Verwalter;
**anwenden** geschieht im Mandanten durch dessen Administrator bzw. wer das Recht hat.

**Tests:** Die Hilfen `nutzer()` und `anmelden()` stehen in jeder Testdatei einzeln – sie werden in `tests/Hilfe/`
zusammengezogen und auf „Mandant anlegen → Identität mit Rolle → Passkey → Anmeldung“ umgestellt. Tests, die als
Verwalter **und** im Mandanten arbeiten (Kontenrahmen, Oberfläche), brauchen zwei Identitäten und wechseln die Anmeldung.

**Risiko:** Ein Fehler in Anmeldung oder Überführung sperrt aus. Deshalb: eigene Version nur für diesen Umbau, alle
PHP- und Browserläufe vor der Abgabe (Ausnahme von F103), auf der VM zuerst eine Sicherung der Verwaltungsdatenbank.

## 12. Entscheidungen

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
| U1–U8 | Umbau nach Abschnitt 3 | **entschieden 2026-11-18**; die Lesart des Pseudonyms **bestätigt am 2026-11-18** (F115); U1–U6 und U8 gebaut in 0.7.22, U7 (Dienstkonten, Tokens) folgt mit Stufe 3 |

## 13. Stufe 1 – was gebaut ist (0.7.22, F115)

| Teil | Wo |
|---|---|
| Nutzer, Anmelde-Passkeys, Zugänge (Links), Sitzungen, Einstellungen im Mandanten | `db/migrationen/0012_nutzer.sql` – `kern.nutzer`, `kern.anmelde_credential`, `kern.registrierung`, `kern.sitzung`, `kern.nutzer_einstellung` mit ihren Funktionen |
| Zuordnung Pseudonym → Mandant, System-Verwalter | `db/verwaltung/0003_pseudonym.sql` – `verwaltung.pseudonym`, `verwalter_anlegen`, `administrator_vermerken`; Anmeldung, Sitzung und Passkey der Verwaltung nur noch für Verwalter |
| Anmeldung über das Pseudonym, Sitzung je Mandant | `src/Http/AnmeldungController.php`, `src/Plattform/Identitaet.php`, `src/Plattform/Kernel.php` |
| Kommandozeile | `mandant:anlegen … --administrator …`, `nutzer:anlegen`, `nutzer:rolle`, `nutzer:link`, `nutzer:liste`, `verwalter:anlegen`, `verwalter:link`, `verwalter:liste`, `system:nutzer-ueberfuehren`; `zugang:gewaehren` entfällt |
| Überführen des Bestands | `system:nutzer-ueberfuehren`, von `install/aktualisieren.sh` nach den Migrationen aufgerufen; wiederholbar |
| Oberfläche | keine Auswahl „Mandant“ mehr; fester Platz für den Mandanten der Anmeldung; einmalige Abfrage fehlender Angaben; Start- und Profilseite |
| Tests | `db/tests/55_nutzer.sql`, `72_verwaltung_pseudonym.sql`; `tests/Hilfe/Zugaenge.php` (gemeinsame Hilfen); `AnwendungTest` (Anmeldung, zwei Mandanten, Verwalter, Überführen); alle PHP- und Browsertests auf zwei Identitäten umgestellt |

### Festlegungen aus der Umsetzung – zu bestätigen

| # | Festlegung |
|---|---|
| S1 | **Das Pseudonym entsteht mit der Identität**, nicht erst beim Einrichten des Passkeys (Abschnitt 3 sagt „beim Einrichten“): So gibt es keinen Zustand „Identität ohne Zuordnung“, und alle Passkeys einer Identität tragen dasselbe Pseudonym. Erst wird die Zuordnung in der Verwaltung eingetragen, dann die Identität im Mandanten – eine Zuordnung ohne Identität führt nirgends hin. |
| S2 | **Form von Cookie und Link:** Sitzungs-Cookie `<kennung>.<geheimnis>`, für System-Verwalter `.<geheimnis>`; Token im Link `<kennung>.<zufall>`, für System-Verwalter ohne Kennung. Gespeichert ist jeweils nur der SHA-256. |
| S3 | **Die Zuordnung hat keinen Zeitpunkt und keinen Protokolleintrag.** `verwaltung.pseudonym` hat genau zwei Spalten. Ein Eintrag lässt sich nicht umlenken (nur einfügen). In Stufe 1 trägt nur die Kommandozeile ein; die Anwendung bekommt das Recht mit den Seiten der Stufe 2. |
| S4 | **`mandant:anlegen <kennung> <name> --administrator <Vorname> <Familienname> <E-Mail>`** – die drei Angaben sind Pflicht (U5); der Administrator bekommt die Rolle „Administrator“ und einen Link (15 Minuten, wie bisher; 24 Stunden und der Code kommen mit Stufe 2). In der Verwaltung steht dazu `mandant.administrator_eingesetzt` nur mit der Kennung des Mandanten. |
| S5 | **`nutzer:rolle <mandant> <nutzer> --rolle …`** ist neu: Es ersetzt den Teil von `zugang:gewaehren`, der einer vorhandenen Identität weitere Rollen gab. |
| S6 | **Fehlende Angaben (U6):** `PATCH /api/v1/ich` nimmt Vorname(n), Familienname und E-Mail genau so lange, wie die E-Mail fehlt; die Oberfläche fragt sie nach der Anmeldung ab, bevor der Rahmen erscheint. Danach ändert die Verwaltung des Mandanten (PROFIL.md U2). |
| S7 | **Überführen, Wahl des Mandanten für den vorhandenen Passkey:** der Mandant im Testbetrieb, bei mehreren der zuerst gewährte; gibt es keinen im Testbetrieb, der zuerst gewährte. |
| S8 | **Überführen, was in der Verwaltung bleibt:** Wer kein Verwalter ist, bleibt als gesperrter Eintrag mit dem Namen „(überführt)“; seine Passkeys sind dort widerrufen, seine Zugänge entzogen. **Die Protokollkette der Verwaltung ist unveränderlich – die alten Einträge mit Namen bleiben darin stehen.** Zugänge von Dienstkonten bleiben bis U7. |
| S9 | **Überführen und Verwalter:** Ein Verwalter mit Zugang zu einem Mandanten behält seinen Passkey dort und braucht für die Verwaltung einen neuen (`verwalter:link`); ein Verwalter ohne Mandant behält ihn in der Verwaltung. |
| S10 | **Lesemodus (F60, F69):** Anmelden und Sitzung gehen weiter (sie stehen nicht in der Kette); Nutzer anlegen, Passkey einrichten, Angaben und Einstellungen ändern gehen im Lesemodus nicht. |
| S11 | **Lesezugriff (`kumero_lesen`)** sieht von `kern.nutzer` nur ID, Namen und „aktiv“ – keine E-Mail, kein Pseudonym, keine Passkeys, Sitzungen oder Zugänge. |
| S12 | **Entfernt:** `verwaltung.nutzer_anlegen` und `verwaltung.zugang_gewaehren`. Sitzungen aus der Zeit vor dem Umbau enden mit der Migration. |
| S13 | **Bis Stufe 2** kann der Administrator eines Mandanten in der Oberfläche noch nichts verwalten; Nutzer und Rollen pflegt die Kommandozeile. Der Bereich „Verwaltung“ zeigt dem System-Verwalter weiter nur den Kontenrahmen-Import. |

## 14. Mandantenverwaltung des System-Verwalters – was gebaut ist (0.7.25, F118)

Auftrag des Verantwortlichen vom 2026-11-19: „relativ schlicht – Mandanten anlegen, Administrator mit E-Mail anlegen und
fertig. Mandanten löschen und vorher exportieren.“ Dazu entschieden: **Auftrag + Dienst** und **pg_dump auf dem Server**.

### Auftrag und Dienst

Die Webanwendung läuft als Datenbankrolle `kumero_app` und darf keine Datenbanken anlegen, sichern oder löschen. Die Seite
schreibt deshalb einen **Auftrag** in die Verwaltungsdatenbank (`verwaltung.auftrag`, Migration 0004); ausgeführt wird er
von `bin/kumero system:auftraege` als `kumero_owner`. Die Web-Rolle bekommt **keine neuen Rechte** – sie darf nur
Aufträge anlegen, stilllegen und reaktivieren.

- **Dienst:** `kumero-auftraege.service` (einmaliger Lauf) und `kumero-auftraege.timer` (alle zehn Sekunden). Er läuft als
  root, weil PostgreSQL nur root auf `kumero_owner` abbildet; ohne Netz, schreibend nur im Export-Verzeichnis.
  **Ohne den Dienst** bleiben Aufträge „wartet auf den Dienst“; `bin/kumero system:auftraege` von Hand tut dasselbe.
- Je Mandant läuft **ein** Auftrag zur Zeit. Ein gescheiterter Auftrag nennt ein festes Stichwort (z. B.
  `export.pg_dump_fehlt`), nie eine Meldung mit Inhalten; er hält die übrigen nicht auf. Bleibt ein Auftrag nach einem
  Absturz „in Arbeit“ stehen, gilt er nach einer Stunde als gescheitert.

### Seite „Mandanten“ (Bereich Verwaltung)

| Aktion | Was geschieht |
|---|---|
| **Mandant anlegen** | Kennung, Name, dazu Vorname(n), Familienname und E-Mail des ersten Administrators – ein Vorgang (U5). Die Seite zeigt den **Zugang (Link) einmal**; gespeichert ist nur sein Hash. Der Link gilt 24 Stunden ab dem Anlegen. |
| **Stilllegen / Reaktivieren** | sofort, ohne Auftrag. Stillgelegt: Niemand meldet sich mehr an, die Daten bleiben. |
| **Eigene Domain …** (seit 0.7.79) | Hostname des Mandanten setzen, ändern, entfernen – sofort, ohne Auftrag; widerruft alle Passkeys des Mandanten, Bestätigung durch Eintippen der Kennung. Abschnitt 18. |
| **Exportieren** | `pg_dump` im eigenen Format in `/var/lib/kumero/exporte/<kennung>-<Zeit>.dump` (Verzeichnis 0700, Datei 0600, root). Die Seite nennt Datei, Größe und SHA-256. **Kein Download im Browser.** |
| **Löschen** | nur **stillgelegt und danach exportiert**; die Kennung ist einzutippen. Der Dienst löscht die Datenbank und entfernt Mandant, Pseudonyme, Wasserstandsmarken, Dienstkonten und Tokens aus der Verwaltung. |

Nach dem Löschen bleibt in der Verwaltung **ein Protokolleintrag** `mandant.geloescht`: Kennung, Zeitpunkt, wer gelöscht
hat, letzter Kettenkopf (Journal und Protokoll) – V11.

### Datenschutz

- **Der System-Verwalter sieht nichts aus dem Inneren eines Mandanten** (F43): Die Liste nennt Kennung, Name, Zustand,
  Zeitpunkte und den letzten Export – keine Zahl der Buchungen, kein Buchungsdatum.
- **Die Angaben zum Administrator** (Name, E-Mail, Hash des Zugangs) stehen nur bis zur Ausführung am Auftrag – in der
  Regel Sekunden – und werden dann entfernt. Die Anwendung kann diese Spalte nicht lesen. Protokoll und Ausgabe des
  Dienstes nennen sie nie.
- **Der Name eines Mandanten** steht ab 0.7.25 nicht mehr im Protokoll der Verwaltung (`mandant.eingetragen` nennt nur
  noch Kennung und Datenbank). **Ältere Einträge der unveränderlichen Kette behalten den Namen** – das gilt für alle
  Mandanten, die vor 0.7.25 angelegt wurden (wie S8).

### Festlegungen aus der Umsetzung (zu bestätigen)

| # | Festlegung |
|---|---|
| M1 | **Zugang nur als Link**, 24 Stunden gültig. Der sechsstellige Code (Abschnitt 6) kommt mit Stufe 2; bis dahin trägt der Link allein. |
| M2 | **Keine Zahlen vor dem Löschen.** Abschnitt 5 Nr. 2 sah vor, dass die Oberfläche nennt, was verloren geht (Zahl der Buchungen, letztes Buchungsdatum). Das widerspräche F43; gezeigt wird nur der letzte Export. |
| M3 | **Bestätigung durch Eintippen der Kennung**, ohne zusätzlichen Passkey (Abschnitt 5 Nr. 3 sah ihn für den Produktivbetrieb vor). |
| M4 | **Ein Export aus dem laufenden Betrieb ist erlaubt**, zählt aber nicht als Voraussetzung für das Löschen – dafür muss er nach dem Stilllegen entstanden sein. |
| M5 | **Die Sicherung bleibt nach dem Löschen liegen.** Der Verwalter übergibt sie dem Mandanten und entfernt sie danach von Hand; kumero löscht sie nicht und führt keine Frist. |
| M6 | **„Herausgeben“ ist die Sicherung mit pg_dump** – vollständig, aber nur mit PostgreSQL lesbar. Ein Export in offenen Formaten (L2) ist nicht gebaut. |
| M7 | **Buchwährung EUR, Betriebsart Test** für jeden neu angelegten Mandanten; die Seite fragt beides nicht ab. |
| M8 | **Der Dienst läuft als root** mit eingeschränkten Rechten der systemd-Einheit (kein Netz, nur das Export-Verzeichnis beschreibbar). |
| M9 | Rollen der Datenbank: `kumero_app` liest von `verwaltung.auftrag` alles außer den Angaben; übernehmen und abschließen darf nur `kumero_owner`. |

### Nicht gebaut

Seiten „Nutzer und Rollen“ und „Module“ im Mandanten; weitere Administratoren einsetzen; Link **und** Code; Seite
„System-Verwalter“; Löschen der Sicherungen; Export in offenen Formaten; `aktualisieren.sh` richtet den Dienst auf
bestehenden Installationen nicht ein (von Hand, siehe CHANGELOG 0.7.25).

**Geprüft:** PHP-Test `AnwendungTest::testMandantenverwaltung` (77 Prüfungen) und Browserlauf `mandanten.mjs` (26) –
beide rufen den Dienst als Befehl auf. **Nicht geprüft: die systemd-Einheiten** (Timer, Rechte, Zugriff auf den Socket
von PostgreSQL unter den Einschränkungen) und der Lauf unter PostgreSQL 19.

## 15. Nutzer und Rollen in der Weboberfläche – was gebaut ist (0.7.29, F125)

Auftrag des Verantwortlichen vom 2026-11-20: „jetzt die WebUI für die Benutzerverwaltung“. Gebaut ist aus Stufe 2 der
Zugang mit **Link und Code** und die Seite **„Nutzer und Rollen“** des Administrators. Nicht gebaut: die Seite „Module“,
„Administrator einsetzen“ durch den System-Verwalter, eigene Rollen und der Rechtebericht (unten).

**Seit 0.7.30 (F126, BUECHER.md):** Die Seite steht im Bereich „Mandant“ und gehört dem Administrator des Mandanten
(ein Amt, keine Rolle eines Buchs). Nutzer, Zugang, Passkeys und Sperren gelten im Mandanten; **Rollen gelten für das
im Kopf gewählte Buch** (`…/b/{buch}/api/v1/zuweisungen`). Neu: „Als Administrator einsetzen“ und „Abberufen“.
Wo unten „Datenbank des Mandanten“ und `kern.nutzer…` steht, heißt das Schema heute `mandant`.

### Seite „Nutzer und Rollen“ (Bereich Mandant, bis 0.7.29 Einstellungen)

Sichtbar mit `kern.rechte.sehen`, Ändern mit `kern.rechte.verwalten` – beide trägt die Rolle **Administrator**. Der
Administrator sieht weiter keine Buchungsdaten (F43); die Verantwortliche sieht die Seite nicht.

| Aktion | Was geschieht |
|---|---|
| **Nutzer anlegen …** | Vorname(n), Familienname, E-Mail, Rollen; auf Wunsch gleich ein Zugang |
| **Bearbeiten …** | Name und E-Mail – Stammdaten, die ID bleibt |
| **Zugang ausstellen …** | Link und Code, 1 bis 24 Stunden; auf Wunsch werden die vorhandenen Passkeys widerrufen (verlorener Passkey) |
| **Rolle zuweisen …** | für die markierten Nutzer, auf Wunsch befristet bis einschließlich eines Tages |
| **Rolle beenden …** | eine Zuweisung, sofort |
| **Passkey widerrufen …** | ein Passkey, nicht umkehrbar |
| **Sperren / Entsperren** | für die markierten Nutzer; Sperren beendet die Sitzungen |

Die Liste nennt Name, E-Mail, Rollen, Zustand, Zahl der Passkeys, offenen Zugang und letzte Anmeldung. Kopf und
Werkzeugleiste stehen fest; gesperrte Knöpfe nennen den Grund (F106). Sperren, Entsperren und „Rolle zuweisen“ gelten
für die markierten Einträge, ohne Haken für die gewählte Zeile (F107).

### Zugang mit Link und Code (Abschnitt 6)

- **Ausstellen** liefert Link und Code **genau einmal** – in der Antwort und im Dialog der Seite, der nur über seinen
  Knopf schließt. Gespeichert sind Hashes: am Zugang `token_hash` und `code_hash` (der Code gebunden an den Link). Auch
  der Idempotenzspeicher bekommt die Antwort nicht; eine Wiederholung derselben Anfrage ergibt 409 `idempotenz.einmalig`.
- **Code:** sechs Zeichen aus `ABCDEFGHJKMNPQRSTUVWXYZ23456789`; beim Eintippen sind Groß-/Kleinschreibung, Leerzeichen
  und Bindestriche egal.
- **Einlösen:** Die Seite „Passkey registrieren“ fragt zuerst den Link ab (`POST /api/v1/registrierung/pruefen` – gültig?
  Code nötig? nennt die Person nicht), dann den Code. Ein fehlender Code ist kein Fehlversuch, ein falscher wird gezählt;
  **nach fünf falschen ist der Zugang ungültig**, auch für den richtigen Code.
- **Ein neuer Zugang macht den vorigen derselben Identität ungültig.**
- Links der Kommandozeile (`nutzer:anlegen`, `nutzer:link`, `mandant:anlegen`) bleiben der Notausgang **ohne Code**,
  15 Minuten; das Code-Feld der Seite ist dann gesperrt und sagt das.

### Wer nicht entfernt werden kann (Abschnitt 4)

Die **Datenbank** erzwingt es (Kern-Migration 0015), nicht die Oberfläche – es gilt damit auch für die Kommandozeile:
Der letzte aktive Nutzer mit einer Rolle der Art `administrator` bzw. `verantwortlich` wird nicht gesperrt, und seine
letzte Zuweisung wird nicht beendet (`KM003`). Ein Mandant ganz ohne Verantwortlichen ist kein Hindernis.

### Zwei Datenbanken

Anlegen schreibt erst die Zuordnung Pseudonym → Mandant in die Verwaltung, dann die Identität im Mandanten. Dafür darf
die Anwendung seit Verwaltung-Migration 0005 `verwaltung.pseudonym_eintragen` aufrufen und mit
`verwaltung.pseudonym_entfernen` ein eben eingetragenes Pseudonym **desselben Mandanten** wieder wegnehmen, wenn das
Zweite scheitert. Umlenken lässt sich eine Zuordnung weiter nicht; `administrator_vermerken` bleibt bei der Kommandozeile.

### REST

`GET`/`POST /m/{mandant}/api/v1/nutzer`, `PATCH …/nutzer/{id}`, `POST …/nutzer/{id}/zugang`,
`DELETE …/nutzer/{id}/passkeys/{credential}`, `POST …/zuweisungen`, `DELETE …/zuweisungen/{id}`;
`POST /api/v1/registrierung/pruefen`, Feld `code` bei `…/registrierung/optionen` und `…/registrierung`
(`openapi/v1.yaml`). Abweichend von Abschnitt 9: kein `…/registrierung/code` im Mandanten – der Code wird bei den
vorhandenen Aufrufen der Registrierung geprüft; `GET /zuweisungen` und `/rollen` stecken in `GET /nutzer`.

### Festlegungen aus der Umsetzung – zu bestätigen

| Nr. | Festlegung |
|---|---|
| N1 | Die Angaben zur Person (Name, E-Mail) trägt der **Administrator** beim Anlegen ein, nicht die Person beim Einlösen. |
| N2 | Die Seite verlangt die Rechte `kern.rechte.sehen` / `kern.rechte.verwalten` des Kerns; neue Rechte gibt es nicht. |
| N3 | Die Anwendung darf Pseudonyme eintragen und ein Pseudonym desselben Mandanten wieder entfernen (Verwaltung 0005). |
| N4 | Die Antwort mit Link und Code steht nicht im Idempotenzspeicher (allgemein: jede Antwort mit `Cache-Control: no-store` aus einem Handler). |
| N5 | Links der Kommandozeile bleiben ohne Code (Notausgang). |
| N6 | „Befristet bis“ ist ein Tag; die Rolle gilt bis zu seinem Ende nach der Zeit des Servers. Läuft die befristete Rolle des letzten Administrators ab, verhindert das niemand. |
| N7 | Wer sich selbst sperrt oder sich die Rolle Administrator nimmt, wird nicht eigens gewarnt – die Regel „der letzte bleibt“ schützt den Mandanten, nicht die Person. |
| N8 | Rollen gelten heute je Mandant. Mit mehreren Büchern (BUECHER.md B3) wird die Zuweisung je Buch; die Seite bekommt dann die Auswahl des Buchs. |

### Offen aus Stufe 2

Seite „Module“: **gebaut mit 0.7.49 (Abschnitt 16)** · **Administrator einsetzen** durch den
System-Verwalter in der Weboberfläche · Seite „System-Verwalter“.

## 16. Module in der Weboberfläche – was gebaut ist (0.7.49, F142)

Anlass (2026-11-20): Nach jedem Update war je Modul und Mandant ein Befehl auf der Kommandozeile nötig; einen Befehl für
alle gab es nicht. Vorgabe des Verantwortlichen: „das muss in die WebUI“ – mit Ausschalten; nach einem Update zieht der
Server die aktiven Module von selbst nach.

**Gebaut:**

- **Seite „Module“** im Bereich „Mandant“, für den Administrator: je Modul und Ort – der Mandant oder ein Buch – der Zustand
  (aktiv mit Fassung, ausgeschaltet, nie eingeschaltet), worauf es aufbaut und was es tut; Sortierung und Filter am
  Spaltenkopf. Knöpfe **Einschalten**, **Ausschalten …**, **Alle nachziehen** – fest, gesperrt mit Grund (F106).
- **Jeder Schritt ist ein Auftrag an den Dienst** (`kumero-auftraege`, wie „Buch anlegen“): Einschalten spielt Migrationen
  ein und braucht den Eigentümer der Datenbank. Die Seite wartet und meldet das Ergebnis; das Menü lädt neu.
- **Ausschalten** setzt `aktiv = false` (`kern.modul_ausschalten`, `mandant.modul_ausschalten`). Weg sind Menü, Seiten,
  Schnittstelle und Erweiterungspunkte des Moduls; **es bleiben** Schema und Daten, Rechte, Bausteine, Rollen und
  Zuweisungen. Einschalten bringt alles zurück.
- **Nachziehen** bringt jedes aktive Modul am Mandanten und in allen Büchern auf die Fassung des installierten Releases
  (Migrationen, Version, neue Rechte, Bausteine der Rollen), in der Reihenfolge der Abhängigkeiten.
  **`install/aktualisieren.sh` ruft es nach jedem Update selbst auf** (`bin/kumero modul:nachziehen`).
- **Kommandozeile:** `modul:nachziehen [mandant]`, `modul:ausschalten <modul> <mandant> [buch]`; `modul:aktivieren` bleibt.
- **Schnittstelle:** `GET /m/{mandant}/api/v1/module`, `POST /m/{mandant}/api/v1/module/auftraege` (`openapi/v1.yaml`).

**Festlegungen beim Bauen (MO1, MO3 bestätigt, MO9 entschieden und geändert; die übrigen zu bestätigen):**

| # | Festlegung |
|---|---|
| MO1 | **Bestätigt 2026-11-21** („Teile und herrsche“). **Der Administrator des Mandanten schaltet Module** – am Mandanten und in jedem Buch, auch in Büchern, in denen er keine Rolle hat. Er sieht dabei nur Namen und Fassungen, keinen Inhalt (wie F43). Das Recht `kern.modul.verwalten` einer Rolle im Buch spielt dafür keine Rolle. |
| MO2 | **Ausschalten löscht nichts.** Daten, Rechte, Rollen und Zuweisungen bleiben; ein Löschen der Daten eines Moduls gibt es nicht. |
| MO3 | **Bestätigt 2026-11-21** (Ausschalten entfernt das Modul nicht; sonst müssten nach einem versehentlichen Schalten alle Rollen neu bestimmt werden). **Im Buch bleiben die Rechte eines ausgeschalteten Moduls an den Rollen stehen** – es gibt nur keinen Weg mehr, sie zu nutzen. Am Mandanten zählen sie nicht mehr (`mandant.rechte_des_subjekts`). |
| MO4 | **Ein Modul lässt sich nicht ausschalten, solange ein aktives darauf aufbaut**; eingeschaltet wird nur, was genannt ist – die Seite nennt, was zuerst an- bzw. ausgehen muss. |
| MO5 | **Jedes Modul lässt sich ausschalten, auch `buchen` und `kontenrahmen`.** kumero prüft nicht, ob ein Buch ohne das Modul noch sinnvoll bedienbar ist, und nicht, ob im Modul noch etwas offen ist (unerledigte Einträge im Arbeitsvorrat, laufende Inventur). **Zu `kontenrahmen`:** Ausgeschaltet fehlen im Buch nur „Kontenrahmen anwenden“ und „Angewendete Kontenrahmen“; die Konten liegen im Kern, Buchen geht weiter. |
| MO6 | **Ein ausgeschaltetes Modul wird nicht nachgezogen** und bekommt bei einem Update keine Migrationen; beim Einschalten holt es beides nach. |
| MO7 | **Wer geschaltet hat, steht im Protokoll des Buchs bzw. des Mandanten** (`modul.registriert`, `modul.ausgeschaltet`); in der Verwaltungsdatenbank steht `system:mandant` (F114). |
| MO8 | **Je Mandant ein Auftrag zur Zeit** (wie bisher) – auch Module verschiedener Bücher nacheinander. |
| MO9 | **Entschieden 2026-11-21 („darf nicht passieren“), geändert mit 0.7.50 (F143):** **Was der Administrator einer Rolle genommen hat, kommt nicht zurück.** Ein Modul gibt jedes Paar aus Rolle und Baustein **genau einmal** und merkt es sich (`kern.modul_rollenbaustein`, `mandant.modul_rollenbaustein`); weder Einschalten noch Nachziehen geben es ein zweites Mal. Nennt eine neue Fassung des Moduls ein Paar erstmals, bekommt die Rolle es einmal. **Bestand aus der Zeit davor:** Beim ersten Lauf gilt ein Baustein, den es schon gab, als früher gegeben – er wird nicht nachgereicht; nur Bausteine, die mit der neuen Fassung erst entstehen, werden gegeben. (Bis 0.7.49 gab jedes Einschalten und Nachziehen alle Bausteine des Manifests zurück.) |
| MO10 | **Nachziehen von selbst gilt für alle Mandanten**; stillgelegte bleiben außen vor. Scheitert ein Modul, laufen die übrigen weiter; `aktualisieren.sh` meldet es und bricht nicht ab. |

**Geprüft:** `tests/NutzerverwaltungTest.php` (203 Prüfungen; darin Module: Ablehnungen, Ein- und Ausschalten im Buch und
am Mandanten, Abhängigkeiten, Nachziehen, Protokoll, Kommandozeile), `Oberflaeche`, `Anwendung`, `Mandantenmodule`, die
SQL-Tests; Browserlauf `tests/browser/nutzer.mjs` (38 Prüfungen, Bild angesehen).
**Nicht geprüft:** der Dienst unter systemd mit den neuen Auftragsarten; `aktualisieren.sh` mit dem neuen Schritt (nur
Syntax); ein Aufruf einer Seite eines Moduls, während es ausgeschaltet wird; ein Mandant mit vielen Büchern.

## 17. Passkey verloren: Wiederherstellung im Selfservice (Entwurf zur Freigabe, P45)

Anlass (2026-11-21, am Dialog „Person bearbeiten“): Ein verlorener Passkey braucht heute den Administrator („Zugang
ausstellen …“ unter „Nutzer und Rollen“, seit 0.7.70 auch im Dialog der Person, F161). Der Verantwortliche: „Das hat den
Nachteil, dass immer jemand aktiv werden muss.“ **Nichts davon ist gebaut.**

### 17.1 Entschieden vom Verantwortlichen (2026-11-21)

| # | Entscheidung |
|---|---|
| W1 | **Zwei Wege, beide nötig – wie bei „Link und Code“ (Abschnitt 6):** der **Link kommt per E-Mail**, der **Code per SMS**. |
| W2 | **Kein TOTP:** Liegen Passkey und Authenticator auf demselben Gerät, ist mit dem Gerät beides weg. |
| W3 | **Eingegeben wird die Personalnummer, nicht die E-Mail-Adresse.** Sie ist zufällig (F147) und schwer zuzuordnen; die Wiederherstellung wird geschult, nicht beworben. Aus ihr leitet kumero die Adresse für den Link ab. |
| W4 | **SMS über das Gateway vor Ort** (Teltonika RUT955) – kein Dienstleister, keine Cloud. |
| W5 | **Der alte Passkey wird widerrufen**, sobald der neue eingerichtet ist. |

### 17.2 Ablauf (Vorschlag)

1. Anmeldeseite: „Passkey verloren?“ → Feld „Personalnummer“. **Die Antwort ist immer dieselbe** („Wenn es diesen Zugang gibt,
   ist eine E-Mail unterwegs“) – ob es die Nummer gibt, verrät die Seite nicht.
2. kumero sucht die Person, nimmt die E-Mail-Adresse ihres verknüpften Zugangs und schickt **nur einen Link** (kein Name, kein
   Mandant, keine Personalnummer). Der Link gilt 30 Minuten und einmal.
3. Hinter dem Link: Knopf „Code senden“. **Erst jetzt** geht die SMS an die hinterlegte Mobilnummer – wer nur die
   Personalnummer kennt, kann also keine SMS auslösen, nur eine E-Mail. Sechs Zeichen, 10 Minuten, fünf Versuche.
4. Mit dem Code richtet die Person einen neuen Passkey ein (die Seite „Passkey einrichten“ aus Abschnitt 6).
5. Danach: alle bisherigen Passkeys widerrufen, alle Sitzungen beendet, Eintrag im Protokoll des Mandanten (nur IDs), Hinweis
   unter „Nutzer und Rollen“ („Zugang selbst wiederhergestellt am …“).

### 17.3 Zu entscheiden

| # | Frage | Vorschlag |
|---|---|---|
| W6 | **Wer hat den Selfservice?** | Nur ein Nutzer, der mit einer Person im Modul `personal` verknüpft ist (sonst gibt es keine Personalnummer), der nicht gesperrt und nicht ausgeschieden ist. **Nicht: wer Administrator des Mandanten oder Verantwortlicher eines Buchs ist** – wer Rollen vergibt oder Buchungen freigibt, bekommt einen neuen Zugang nur von einem Menschen. |
| W7 | **Mandant** – die Anmeldeseite kennt keinen, die Personalnummer ist nur je Mandant eindeutig | kumero sucht in allen Mandanten, in denen `personal` aktiv ist; jeder Treffer bekommt seinen eigenen Link. Bei sechs Zeichen aus 25 sind doppelte Nummern praktisch ausgeschlossen. Alternative: zweites Feld „Mandant“. |
| W8 | **Woher Adresse und Nummer?** | E-Mail: die des verknüpften Nutzers (nach ZA3 die private E-Mail der Person). SMS: `Telefon` aus den privaten Angaben der Person – es muss eine Mobilnummer sein; kumero bringt sie in die Form `+49…`. Fehlt eines von beiden, gibt es keinen Selfservice; die Seite antwortet trotzdem gleich. **Eigenes Feld „Mobilnummer für die Wiederherstellung“ statt des allgemeinen Telefons?** |
| W9 | **Auskunft aus `personal` an den Kern** (F44: der Kern liest keine Tabellen eines Moduls) | Neue, enge Auskunft des Moduls: Personalnummer → Nutzer-ID und Mobilnummer. Nur die Wiederherstellung darf sie fragen; sie steht in keiner Schnittstelle nach außen. |
| W10 | **Bremsen** | Je Personalnummer höchstens drei Anforderungen in 24 Stunden, je Absenderadresse zehn in der Stunde; je Link ein „Code senden“ und ein zweites nach 60 Sekunden. Was darüber liegt, wird still verworfen. |
| W11 | **Mailversand – den gibt es in kumero noch nicht** | Über den Mailserver der Installation (lokaler MTA oder SMTP-Relay); Einstellung in `/etc/kumero/kumero.php` (Host, Port, Absender, Zugangsdaten – nie im Repository, nie im Chat). Versand als Auftrag an den Dienst `kumero-auftraege`, damit php-fpm keine Verbindung nach außen braucht. Ohne Einstellung gibt es keinen Selfservice und keinen Knopf auf der Anmeldeseite. |
| W12 | **SMS-Anschluss als Anschlussstelle, die sich ersetzen lässt** | Einstellung in `/etc/kumero/kumero.php`: Art, Adresse des Gateways, Zugangsdaten, Modem. Arten: `teltonika-api` (Anmelden an `/api/login`, senden mit `POST /api/messages/actions/send`: Nummer, Text, Modem) und `teltonika-postget` (ältere Firmware; der Hersteller hat diesen Weg mit RutOS 7.14 entfernt). **Zu klären: welche Firmware auf dem Gerät läuft, ob `aequa` es erreicht, die Kennung des Modems.** Der RUT955 ist beim Hersteller abgekündigt. |
| W13 | **Was die Installation verlässt** | An das Gateway und ins Mobilfunknetz: Mobilnummer und der Code. An den Mailserver: E-Mail-Adresse und der Link. Kein Name, kein Mandant, keine Personalnummer. Im Protokoll nur IDs; in Logs weder Nummer noch Adresse. |
| W14 | **Text der SMS** | „kumero: Code 3F7 K2M – gilt 10 Minuten. Nicht weitergeben.“ Ohne Link (eine SMS mit Link sieht aus wie Betrug). |
| W15 | **Vergibt das Personal eine neue Personalnummer**, gilt für die Wiederherstellung sofort die neue. Wer gesperrt wird oder ausscheidet, verliert den Selfservice. | so |

### 17.4 Was daneben bleibt

- **Der Weg über einen Menschen** (Administrator: „Nutzer und Rollen“ oder Dialog der Person) – für alle ohne Person, ohne
  Mobilnummer, für Administratoren und Verantwortliche, und wenn Mail oder Gateway ausfallen.
- **Vorsorge, nicht entschieden:** ein zweiter Passkey im Profil (zweites Gerät, Hardware-Schlüssel) – bleibt bei „nur
  Passkey“ und schafft keinen neuen Weg hinein. Die Oberfläche dafür fehlt.

### 17.5 Bewertung

Die Personalnummer ist kein Geheimnis: Sie steht auf der Lohnunterlage und in den Listen von Personal, Lohn und Vorgesetzten.
Sicher ist der Weg, weil **zwei getrennte Zugriffe** nötig sind – auf das Postfach und auf das Telefon. SMS ist der schwächere
der beiden (Rufnummernübernahme, Umleitung); allein würde sie nicht reichen. Der Totalverlust bleibt möglich, wenn Postfach,
Telefon und Passkey auf demselben Gerät liegen – dann hilft nur der Weg über einen Menschen.

### 17.6 Stufen

| Stufe | Inhalt |
|---|---|
| 1 | Mailversand als Auftrag; Auskunft aus `personal`; Anforderung mit Personalnummer; Link; Bremsen; Protokoll. Der Code kommt in dieser Stufe noch nicht per SMS – **allein mit dem Link wird nichts freigegeben**, die Stufe ist ohne Stufe 2 nicht nutzbar. |
| 2 | SMS-Anschluss (W12), „Code senden“, Passkey einrichten, Widerruf der alten. |
| 3 | Hinweis unter „Nutzer und Rollen“, Schulungstext, Prüfung in `pruefen.sh` (Mailweg und Gateway erreichbar). |


## 18. Eigene Domain je Mandant (gebaut mit 0.7.78 und 0.7.79, F180, F181; Erscheinungsbild DM16 offen)

Stand: 2026-11-22. Bezug: F84 Nr. 4 (Adresse der Installation), F68 (Origin und RP-ID), F114 (Nutzer und Passkeys gehören
zu genau einem Mandanten), F176 (gleicher Hostname nach dem Restore), P45 (Selfservice), F177.
**Gebaut mit 0.7.79 (F181):** DM1–DM12, DM14, DM15; DM13 mit 0.7.78. Mit dem Auftrag „dann bau mal …“ gelten DM8, DM9 und DM13
als so bestätigt, wie vorgeschlagen. Offen: DM16 (Erscheinungsbild) und die Festlegungen aus der Umsetzung in 18.8.
Dazu F178: Ein Mandant muss gehen oder selbst betreiben können – die eigene Domain ist dafür die Voraussetzung, dass
seine Passkeys den Umzug überstehen (P52).

### 18.1 Anlass und Stand

Mandanten wollen kumero unter ihrer eigenen Domain erreichen – auch, damit nicht auf den ersten Blick zu sehen ist, dass
sie eine gemeinsam betriebene Installation nutzen. Heute kennt kumero **eine** Adresse je Installation (`adresse` in
`/etc/kumero/kumero.php`); ihr Hostname ist die RP-ID aller Passkeys, mit ihr vergleicht der Kern die Herkunft jeder
schreibenden Anfrage, aus ihr entstehen die Zugangslinks. Eine eigene Domain geht deshalb heute nur mit einer eigenen
Installation je Mandant.

Festgehalten aus dem Gespräch (F177): **Die Domain hält der Proxy** (nginx, Caddy) – Zertifikat und Servername sind nicht
Sache von kumero. **Der Pfad `/m/<mandant>/` ist nur Routing.** **Der Selfservice wird leichter**, weil die Domain den
Mandanten schon vor der Anmeldung nennt. Dass ein Passkey nur unter der Domain gilt, unter der er eingerichtet wurde,
ist **kein Nachteil, sondern konsequent**.

### 18.2 Was kumero dafür wissen muss

Der Browser bindet einen Passkey an den Hostnamen der Seite; kumero prüft bei Anmeldung und Freigabe Herkunft und
RP-ID (F68). Das kann kein Proxy übernehmen. kumero braucht deshalb **je Mandant den Hostnamen, unter dem er erreichbar
ist** – und nimmt nie einen Hostnamen an, den es nicht kennt.

- **Ablage:** in der Verwaltungsdatenbank, am Mandanten: ein Hostname, eindeutig über die Installation, freiwillig.
  Ohne eigenen Hostnamen gilt für den Mandanten die Adresse der Installation, wie heute.
- **Je Anfrage die wirksame Adresse:** Der Kern liest den Hostnamen der Anfrage. Ist es der der Installation, gilt
  alles wie heute. Ist es der eines aktiven Mandanten, sind RP-ID, erlaubte Herkunft und die Adresse in Links die
  dieses Mandanten. Jeder andere Hostname wird abgewiesen, bevor etwas geschieht.
- **Unter der Domain eines Mandanten gibt es nur diesen Mandanten:** Anmelden kann sich dort nur, wessen Pseudonym zu
  ihm gehört; ein Pfad `/m/<anderer>/…` wird abgewiesen; der System-Verwalter meldet sich nur unter der Adresse der
  Installation an.
- **Was sich nicht ändert:** das Sitzungs-Cookie (`__Host-…` gilt ohnehin nur für seinen Hostnamen), die Zuordnung
  Pseudonym → Mandant, die Datenbanken, die Pfade der Schnittstelle.

### 18.3 Stand im Code (0.7.76) und was daraus folgt

Die Prüfung eines Passkeys selbst liegt an **einer** Stelle: `src/Plattform/Webauthn/Pruefer.php` (Registrierung und
Assertion, F68). Verteilt ist, **woher sie RP-ID und erlaubte Herkunft bekommt**: `AnmeldungController` und
`BuchungenController` bauen den Prüfer jeder für sich aus `konfig->rpId` und `konfig->adresse` und nennen dem Browser die
RP-ID an drei Stellen selbst; der Kern vergleicht die Herkunft schreibender Anfragen (CSRF) gesondert mit
`konfig->adresse`; `NutzerController`, `VerwaltungController` und `module/import/src/Ablage.php` setzen Links aus
`konfig->adresse` zusammen. Das war richtig, solange es genau eine Adresse gab, und ist mit Adressen je Mandant eine
Fehlerquelle: Eine vergessene Stelle prüft gegen die falsche Domain.

**Deshalb zuerst zusammenziehen (DM13):** Die Anfrage kennt ihre wirksame Adresse und liefert Prüfer, RP-ID und
Link-Anfang – `Anfrage::adresse()`, `Anfrage::rpId()`, `Anfrage::pruefer()`; kein Controller und kein Modul liest dafür
mehr die Konfiguration. Das ist ein Umbau ohne geändertes Verhalten und lässt sich für sich prüfen; die Adresse je
Mandant ist danach eine Änderung an einer Stelle.

Dazu kommen eine Migration der Verwaltung, ein Befehl, die Seite „Mandanten“, der Vertrag und die nginx-Vorlage.
Überschaubar – aber es ist die Anmeldung, also der Teil, an dem ein Fehler jeden aussperrt oder den Falschen hereinlässt.

### 18.4 Stufen

| Stufe | Inhalt |
|---|---|
| **0** | Zusammenziehen (DM13): wirksame Adresse, RP-ID und Prüfer aus der Anfrage; Verhalten unverändert. |
| **1** | Hostname am Mandanten (Verwaltung, Befehl, Seite „Mandanten“); wirksame Adresse je Anfrage; Passkeys, Freigaben und Links unter der Domain des Mandanten; unbekannte Hostnamen schon im Webserver abgewiesen (DM4, DM14); **unter der eigenen Domain kein `/m/<mandant>/` mehr (DM10)**; Name des Mandanten statt „kumero“ (DM11). |
| – | Selfservice (P45) nutzt die Domain, sobald er gebaut wird. |

### 18.4a Der Pfad unter der eigenen Domain (zu DM10, Vorschlag)

Stand im Code (0.7.76): Die Oberfläche bildet ihre Pfade an **einer** Stelle – `public/kern/ui/rahmen.js` (`apiBasis`,
`mandantApi`, `buchApi`, `seitenBasis`); die Seiten der Module nehmen diese Angaben und setzen selbst kein `/m/…`
zusammen. Der Server legt die Routen in `src/Http/Routen.php` an: global unter `/api/v1/…`, am Mandanten unter
`/m/{mandant}/api/v1/…`, im Buch unter `/m/{mandant}/b/{buch}/api/v1/…`.

- **Unter der Domain eines Mandanten** heißen die Pfade `/api/v1/…` (Mandant) und `/b/<buch>/api/v1/…` (Buch), in der
  Adresszeile `#/<modul>/<ziel>` bzw. `#/b/<buch>/<modul>/<ziel>`. Den Mandanten nimmt der Kern aus dem Hostnamen und
  setzt ihn **an einer Stelle** vor das Routing; Controller und Module merken davon nichts.
- **Kein Zusammenstoß mit den globalen Pfaden,** weil es unter der Domain eines Mandanten nichts Globales außer
  Anmeldung, Registrierung und „ich“ gibt (DM5): `/api/v1/oberflaeche/menue` ist dort das Menü des Mandanten,
  `/api/v1/verwaltung/…` gibt es dort nicht. Vor dem Bauen jede globale Route gegen die Routen am Mandanten prüfen.
- **Ein Pfad mit `/m/…` wird unter der Domain eines Mandanten abgewiesen** – auch mit der eigenen Kennung; es gibt
  genau eine Schreibweise.
- **Unter der Adresse der Installation bleibt alles, wie es ist** (`/m/<mandant>/…`) – für Mandanten ohne eigene
  Domain und für den System-Verwalter.
- Die Oberfläche erfährt mit der ersten Antwort (`/api/v1/ich` bzw. Registrierung), ob sie unter der Domain eines
  Mandanten läuft, und `rahmen.js` bildet die Angaben danach. Links in Zugängen und der vorläufige Beleglink des
  Moduls `import` kommen aus derselben Quelle am Server (`Anfrage`, DM13).
- Die interne Kennung des Mandanten bleibt (Datenbankname, Protokoll); sie steht nur nicht mehr in der Adresse.

### 18.5 Entscheidungen zur Freigabe

| # | Frage | Vorschlag |
|---|---|---|
| DM1 | Wie viele Hostnamen je Mandant | **Zugestimmt am 2026-11-22 („das schafft Flexibilität“).** Genau einer, freiwillig, eindeutig über die Installation. Mehrere hieße: Passkeys gelten je Hostname getrennt – das verwirrt. |
| DM2 | Mandanten ohne eigene Domain | **Zugestimmt am 2026-11-22 („das schafft Flexibilität“).** Bleiben unter der Adresse der Installation erreichbar, wie heute. |
| DM3 | Mandant mit eigener Domain unter der Adresse der Installation | **Zugestimmt am 2026-11-22 („das schafft Flexibilität“).** Nicht mehr erreichbar – sonst hätte er zwei Adressen und zwei Sätze Passkeys. |
| DM4 | Unbekannter Hostname | **Entschieden am 2026-11-22: Unbekannte Hostnamen werden schon im Webserver nicht mehr geroutet** – sie erreichen PHP nicht. Folge: DM14. Die Prüfung im Kern bleibt als zweite Linie (Vorschlag, zu bestätigen). |
| DM5 | System-Verwalter | **Zugestimmt am 2026-11-22.** Meldet sich nur unter der Adresse der Installation an; unter der Domain eines Mandanten gibt es ihn nicht. |
| DM6 | Wer setzt den Hostnamen | **Zugestimmt am 2026-11-22:** Der System-Verwalter (Seite „Mandanten“ und `bin/kumero mandant:adresse`) – DNS und Zertifikat liegen bei dem, der die Maschine betreibt. Nicht der Administrator des Mandanten. |
| DM7 | Hostname setzen oder ändern, wenn schon Passkeys bestehen | **Zugestimmt am 2026-11-22 („das ist so“).** Erlaubt, mit deutlicher Anzeige der Folge: Alle Passkeys des Mandanten gelten nicht mehr, alle Zugänge sind neu auszustellen. Die alten Passkeys werden dabei widerrufen (Protokoll), nicht still liegen gelassen. **Die Domain gehört deshalb vor die ersten Nutzer.** |
| DM8 | Zertifikat und Servername | Nicht Sache von kumero. Erste Stufe: Ein Proxy davor (Caddy, nginx des Betreibers) hält die Domains und reicht den Hostnamen unverändert durch; INSTALL.md beschreibt das. Ob `installer.sh` das für nginx selbst einrichten soll, bleibt offen (F173: Caddy im Installer kommt nicht). |
| DM9 | Vertrauen in den durchgereichten Hostnamen | Maßgeblich ist der Hostname, den der eigene nginx sieht; `X-Forwarded-Host` wertet kumero nicht aus. |
| DM10 | Pfad `/m/<mandant>/` unter der eigenen Domain | **Entschieden am 2026-11-22: Der Pfad entfällt gleich mit, nicht in einer späteren Stufe** („unschön und nur ein Verschleppen der Aufgabe“). Ausführung als Vorschlag in 18.4a. |
| DM11 | Name im Passkey-Dialog und im Seitentitel | **Entschieden am 2026-11-22: ja – samt Erscheinungsbild (CD) und Logo des Mandanten.** Unter der eigenen Domain steht der Name des Mandanten statt „kumero“. Ausführung des Erscheinungsbilds: DM16. Die gemeinsame IP-Adresse bleibt sichtbar; eine eigene Domain verbirgt den gemeinsamen Betrieb nur auf den ersten Blick. |
| DM12 | Wiederherstellung (F176) | **Zugestimmt am 2026-11-22.** Die Bedingung „gleicher Hostname“ gilt für die Adresse der Installation **und** für jede Domain eines Mandanten; `wiederherstellen.sh` nennt sie am Ende. |
| DM14 | Woher der Webserver die Hostnamen kennt (Folge aus DM4) | **Entschieden am 2026-11-22: kumero ändert seine Webserver-Konfiguration nie** – das wäre mit dem Sicherheitsgedanken unvereinbar (der erste Vorschlag, eine Namensdatei durch den Dienst `kumero-auftraege` schreiben zu lassen, ist verworfen: Eine Eingabe in der Weboberfläche hätte damit die Konfiguration des Webservers bestimmt). **Stattdessen zwei Hände:** Den Servernamen und das Zertifikat trägt ein, wer root auf der Maschine ist – von Hand, nach INSTALL.md; den Hostnamen am Mandanten setzt der System-Verwalter in kumero (DM6). Erreichbar ist die Domain erst, wenn beides steht; fehlt eines, wird nichts geroutet (DM4) bzw. weist der Kern ab. kumero schreibt nichts unter `/etc/nginx`, lädt nginx nicht neu und stößt das auch über keinen Dienst an. Zu bestätigen bleibt nur: `bin/kumero mandant:adressen` nennt root die gesetzten Hostnamen zum Abgleich – eine Ausgabe, keine Änderung. |
| DM15 | Adresse der Seite in der Adresszeile | **Entschieden am 2026-11-22: Die Adresse bleibt sichtbar und zeigt auf die Stelle** (`#/b/<buch>/<modul>/<ziel>` unter der eigenen Domain, 18.4a) – „die Linkverweise sind ein schlagendes Argument für effektives Arbeiten“: Lesezeichen, weitergegebene Links, der Link von Paperless zur Buchung (F49), der Sprung von der Charge zur Buchung (F163), Öffnen in neuem Tab. Gar nichts anzuzeigen ist verworfen. **Notiert, nicht beauftragt:** dieselben Adressen ohne `#` und im Normalfall ohne Buch (`/buchen/liste`, ein anderes Buch als `?buch=…`) – Umbau in `rahmen.js` und eine Zeile in der nginx-Vorlage. |
| DM16 | Erscheinungsbild des Mandanten (zu DM11; Vorschlag, zu vertiefen) | Am Mandanten: Anzeigename, Logo, eine Hauptfarbe. Gezeigt auf der Anmeldeseite seiner Domain, im Kopf der Oberfläche, im Seitentitel, im Passkey-Dialog (Name), auf Ausdrucken. **Pflegt der Administrator des Mandanten** – es ist sein Auftritt, nicht der des Betreibers. Logo als Rasterbild (PNG/WebP, begrenzte Größe), kein SVG (kann Skript tragen); abgelegt in der Datenbank des Mandanten (kein Beleg im Sinn von F110), ausgeliefert vom Kern. Farben nur als Werte, gesetzt ohne `style`-Attribute (strenge CSP); **die Einstellungen des Nutzers zu Kontrast und Schriftgröße gehen vor** (PROFIL.md), eine Farbe mit zu wenig Kontrast wird beim Speichern abgewiesen. Offen: Was davon ein Mandant ohne eigene Domain bekommt; ob „kumero“ irgendwo stehen bleibt (Impressum der Software, Lizenzhinweis nach AGPL § 13 – der Hinweis auf den Quellcode muss erreichbar bleiben). |
| DM13 | Zusammenziehen vor dem Umbau | **Gebaut mit 0.7.78 (F180).** Stufe 0 als eigener Schritt: Adresse, RP-ID und Prüfer nur noch über die Anfrage. Auch ohne Domains sinnvoll. |

### 18.6 Prüfbar

Im Chat: PHP-Tests mit zwei Hostnamen über die Kopfzeile der Anfrage (Anmeldung, Freigabe, Abweisen fremder Hostnamen,
Wechsel der Domain). Nicht im Chat: ein echter Browser unter zwei Domains mit Zertifikat, der Proxy davor.

### 18.7 Spannung zwischen DM1–DM3 und der Leitlinie L5 (festgehalten am 2026-11-22, nicht aufgelöst)

Der Verantwortliche: „DM1–DM3 sind mit DM13 problematisch, das sollten wir dokumentieren.“ Mit „DM13“ ist seine Vorgabe
vom selben Tag gemeint, dass ein Mandant einfach gehen oder selbst betreiben können muss – sie steht als **Leitlinie L5
und F178** in AGENTS.md. (DM13 in der Tabelle oben ist das Zusammenziehen im Code und davon nicht berührt.)

DM1–DM3 sind zugestimmt („das schafft Flexibilität“) und bleiben es. Sie haben aber Folgen für den, der geht:

| Entscheidung | Folge für den Weggang (L5) |
|---|---|
| **DM2** – ohne eigene Domain bleibt der Mandant unter der Adresse der Installation | **Seine Passkeys gehören zur Domain des Betreibers.** Geht er, gilt kein einziger mehr: Jeder Nutzer braucht einen neuen Zugang (Link und Code). Auch alle gespeicherten Adressen zeigen danach ins Leere – Lesezeichen, weitergegebene Links, die Links von Paperless zur Buchung (F49), Verweise in Ausdrucken. Die Daten ziehen vollständig um, die Zugänge und Adressen nicht. Das ist eine Schwelle. |
| **DM3** – mit eigener Domain ist der Mandant unter der Adresse der Installation nicht mehr erreichbar | **Der Wechsel auf die eigene Domain ist ein harter Schnitt:** An einem Stichtag gelten alle alten Passkeys nicht mehr (DM7), alle alten Adressen sind tot. Wer die eigene Domain erst nimmt, *um* gehen zu können, zahlt die Schwelle vorher. |
| **DM1** – genau ein Hostname je Mandant | **Kein Übergang möglich:** Es gibt keinen Zeitraum, in dem alte und neue Adresse nebeneinander gelten und die Nutzer nach und nach einen Passkey für die neue Domain einrichten. |

**Ohne Schwelle geht nur, wer von Anfang an unter einer eigenen Domain läuft, die ihm selbst gehört.** Eine Subdomain des
Betreibers hilft nicht – sie bleibt beim Betreiber. Dann überstehen Passkeys und Adressen den Umzug, und der Betreiber
muss nur noch das Paket herausgeben (P52).

Wege, die Spannung zu verkleinern – **keiner ist entschieden:**

1. **Hinnehmen und sagen:** Beim Anlegen eines Mandanten ohne eigene Domain nennt kumero die Folge; beim Weggang werden
   die Zugänge neu ausgestellt (die Funktion gibt es, F125). Nichts zu bauen außer dem Hinweis.
2. **Eigene Domain als Regel:** Der Betreiber legt Mandanten grundsätzlich mit eigener Domain an; die Adresse der
   Installation bleibt dem System-Verwalter und Testmandanten. Eine Sache des Betriebs, nicht des Codes.
3. **Befristeter Übergang:** Für eine begrenzte Zeit gelten zwei Hostnamen; wer unter dem alten angemeldet ist, richtet
   einen Passkey für den neuen ein, danach wird der alte abgeschaltet. Das weicht DM1 und DM3 für diese Frist auf und
   kostet einen eigenen Ablauf im Kern (zwei RP-IDs je Mandant, Passkey „für die andere Domain“ einrichten).
4. **Alte Adressen weiterleiten:** Nach dem Wechsel leitet die alte Adresse eine Zeit lang auf die neue um – rettet
   Lesezeichen und Links, nicht die Passkeys. Verlangt beim Weggang die Mitwirkung des alten Betreibers und ist deshalb
   nach L5 Nr. 3 kein Verlass.

### 18.8 Festlegungen aus der Umsetzung (0.7.79, zu bestätigen)

| # | Festlegung |
|---|---|
| DU1 | **Der Kern weist unbekannte Hostnamen zusätzlich ab** (HTTP 421) – zweite Linie hinter dem Webserver (DM4). Kostet je Anfrage unter einer fremden Domain eine Abfrage der Verwaltung; unter der Adresse der Installation keine. |
| DU2 | **Routen unter der eigenen Domain:** erst die des Mandanten, dann die für jede Adresse (Anmeldung, Registrierung, „ich“). Alles, was ein Recht der Installation verlangt, gibt es dort nicht (404). |
| DU3 | **Der Vermerk im Protokoll des Mandanten nennt als Subjekt `system:verwaltung`**, nicht die Person der Installation (F114); wer es war, steht im Protokoll der Verwaltung (`mandant.adresse_gesetzt` mit Vorher und Nachher). |
| DU4 | **Kein gemeinsamer Abschluss über beide Datenbanken:** erst die Adresse in der Verwaltung, dann der Widerruf im Mandanten. Scheitert das Zweite, sind die Passkeys nicht widerrufen, aber unter der alten Adresse ohnehin nicht mehr verwendbar; ein zweiter Aufruf meldet „unverändert“ und holt den Widerruf nicht nach. |
| DU5 | **Bestätigung in der Weboberfläche durch Eintippen der Kennung**, kein Passkey – wie beim Löschen eines Mandanten. |
| DU6 | **Neue Zugänge nach dem Wechsel stellt die Kommandozeile aus** (`nutzer:link`): Der Administrator des Mandanten hat selbst keinen gültigen Passkey mehr, und „Administrator einsetzen“ für den System-Verwalter gibt es in der Weboberfläche noch nicht. |
| DU7 | **Vorläufige Links der Zwischenablage (`import`)** tragen die Adresse, unter der sie entstanden sind; Dateien, die beim Wechsel der Domain noch in der Zwischenablage liegen, sind danach unter dem alten Link nicht mehr zu finden. Vor dem Wechsel buchen oder verwerfen. |
| DU8 | **Der Auffang-Server steht nur in der Vorlage für neue Installationen;** bestehende zieht kumero nicht nach (DM14, F160 SU3) – INSTALL.md Abschnitt 4. |
| DU9 | **Stillgelegte Mandanten:** Ihr Hostname gilt als unbekannt, bleibt aber vergeben. |
