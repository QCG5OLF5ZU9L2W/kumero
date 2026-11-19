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
