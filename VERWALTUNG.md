# VERWALTUNG.md – Mandanten, Nutzer, Zugang und Rollen (Entwurf 1, zur Freigabe)

Stand: 2026-11-18. Bezug: F34 (ein Buch je Datenbank), F37 (Verwaltungsdatenbank), F40 (Mandant anlegen),
F41–F43 (RBAC, Rollenvorlagen, Invarianten), F63 (Rollen liegen im Mandanten), F77 E2 (Anmeldung per Passkey),
F80 N3 (Bereich „Verwaltung“), F84 Nr. 6 (Registrierungslink), F106 (nichts verschiebt sich).
**Status: Entwurf. V1–V8, V12, V13, V15 und V16 sind am 2026-11-18 entschieden (F113); gebaut ist nichts davon.**
Offen sind V9–V11, V14 und V17.

Anlass: Mandanten, Nutzer, Zugänge und Module lassen sich heute nur auf der Kommandozeile pflegen
(`mandant:anlegen`, `nutzer:anlegen`, `nutzer:link`, `zugang:gewaehren`, `modul:aktivieren`). Die Weboberfläche zeigt
im Bereich „Verwaltung“ nur den Kontenrahmen-Import. Einiges gibt es nur als Datenbankfunktion ohne Befehl:
Mandant stilllegen, Zugang entziehen, Nutzer ändern, Passkey widerrufen, Rollenzuweisung beenden, Dienstkonten und Tokens.

---

## 1. Zwei Ebenen

| | **System-Verwalter** | **Mandanten-Verwalter** |
|---|---|---|
| Was es ist | das Kennzeichen `verwalter` am Nutzer (Verwaltungsdatenbank) | die Rolle **„Administrator“** im Mandanten (Vorlage nach F42) |
| Gilt für | die ganze Installation | genau einen Mandanten |
| Darf | Mandanten anlegen, stilllegen, löschen · Nutzer anlegen, sperren · Zugang ausstellen (Link, Einmal-Code) · Passkeys widerrufen · Zugang zu Mandanten geben und entziehen · Kontenrahmen-Vorlagen | Module ein- und ausschalten · Rollen pflegen · Rollen zuweisen und beenden · Vorgaben des Mandanten |
| Buchungsdaten | nein (F43) | nein (F43) |

Ein neuer Begriff entsteht nicht: Der Mandanten-Verwalter **ist** die Rolle „Administrator“; sie bekommt Seiten.

## 2. Der Verwalter als „root“

- **Der System-Verwalter darf in jedem Mandanten jede Rolle zuweisen – auch sich selbst**, auch Buchhalter oder
  Verantwortlicher. Damit kann er sich alles geben.
- **Er hat ein Recht erst, wenn er es sich gegeben hat.** Die Zuweisung steht im Protokoll des Mandanten (F48). Wer bucht,
  steht mit einer Rolle im Protokoll; ein Prüfer sieht, wer wann welche Rechte hatte.
- F42 („kein alle Rechte für alle“) und F43 (Administrator ohne Buchungsdaten) bleiben damit unverändert.
- **Freigaben und Notfall-Ereignisse bleiben beim Verantwortlichen** und verlangen weiter die persönliche Bestätigung per
  Passkey (F38). Das Verwalter-Kennzeichen ersetzt sie nicht.
- **Der Prüfer bleibt technisch nur lesend** (F43), gleich wer ihn anlegt.

## 3. Wer nicht entfernt werden kann

- **Der letzte System-Verwalter** lässt sich nicht entfernen und nicht sperren.
- **Der letzte Administrator eines Mandanten** lässt sich nicht einzeln entfernen. **Er geht mit dem Mandanten** – wird
  der Mandant gelöscht, enden alle Zuweisungen und Zugänge mit ihm (Entscheidung vom 2026-11-18).
- Für den **letzten Verantwortlichen** gilt dasselbe (V9, zu bestätigen): Ohne ihn könnte niemand mehr festschreiben,
  sperren oder einen Notfall bestätigen.

## 4. Mandant löschen

Ein Mandant muss ganz verschwinden können (Datenschutz). Heute gibt es nur das **Stilllegen** (Kennzeichen in der
Verwaltungsdatenbank; die Datenbank bleibt).

| Schritt | Was geschieht |
|---|---|
| **Stilllegen** | Der Mandant ist nicht mehr wählbar; Daten und Zugänge bleiben. Umkehrbar. |
| **Löschen** | Die Datenbank des Mandanten wird entfernt (F34: Löschung je Mandant), dazu Zugänge, Wasserstandsmarke und Sitzungsbezüge in der Verwaltungsdatenbank. **Nicht umkehrbar.** |

Vorschlag für das Löschen:

1. Nur ein **stillgelegter** Mandant lässt sich löschen.
2. Die Oberfläche nennt vorher, was verloren geht: Zahl der Buchungen, letztes Buchungsdatum, gesperrte Geschäftsjahre,
   Betriebsart, letzter externer Siegelexport.
3. Bestätigt wird durch Eintippen der Kennung des Mandanten – und im Produktivbetrieb zusätzlich per Passkey.
4. In der Verwaltungsdatenbank bleibt **ein Protokolleintrag**: Kennung, Zeitpunkt, wer gelöscht hat, letzter Kettenkopf
   (Hash) – keine Buchungsdaten, kein Name des Mandanten (V11).
5. Sicherungen und die Siegelkopien außer Haus (F35) kann kumero nicht löschen; die Oberfläche sagt das, und die
   Verfahrensdokumentation regelt es.

**Zu klären (V10):** Bücher und Belege unterliegen Aufbewahrungsfristen (§ 147 AO). Löschen aus Datenschutzgründen und
Aufbewahren aus Steuergründen können sich widersprechen. Vorschlag: kumero **verhindert** das Löschen nicht, **warnt**
aber deutlich, wenn festgeschriebene Buchungen aus Jahren vorliegen, deren Frist nach den hinterlegten Angaben noch
läuft, und bietet vorher den vollständigen Export an (L2). Die Entscheidung trägt der Verwalter.

## 5. Zugang ausstellen: Link oder Einmal-Code

Angemeldet wird weiter **nur per Passkey** (F77 E2). Ein neuer Nutzer – oder einer, der seinen Passkey verloren hat –
braucht einmalig eine Berechtigung, einen Passkey einzurichten. Dafür gibt es **zwei gleichwertige Wege**, beide lösen
dieselbe Registrierung ein:

| Weg | Wie | Wann |
|---|---|---|
| **Link** (vorhanden) | Adresse mit dem Token im Fragment (F84 Nr. 6); heute 15 Minuten gültig, höchstens 24 Stunden | wenn sich der Link sicher übergeben lässt |
| **Einmal-Code** (neu) | kurzer Code, den der Verwalter vorliest oder aufschreibt; der Nutzer gibt ihn auf der Anmeldeseite ein | wenn der Link nicht durch Chat, Mail oder Log gehen soll |

**Der Einmal-Code ist kein Passwort zum Anmelden.** Er berechtigt genau einmal dazu, einen Passkey einzurichten.

Ablauf:

1. Der Verwalter legt den Nutzer an oder wählt „Zugang ausstellen“ und entscheidet sich für Link oder Code.
2. kumero zeigt den Code **einmal** an. Gespeichert wird nur sein Hash; Protokoll und Logs nennen, **dass** ein Code
   ausgestellt wurde, nie den Code.
3. Der Verwalter gibt ihn auf einem zweiten Weg weiter (Telefon, Zettel, persönlich).
4. Der Nutzer öffnet die normale Adresse, wählt „Passkey einrichten“, gibt den Code und **seine Angaben** ein –
   **Vorname(n), Familienname und Kontaktdaten, mindestens die E-Mail-Adresse** (Entscheidung vom 2026-11-18) – und
   registriert seinen Passkey. Damit ist der Code verbraucht.

**Der Code hat sechs Zeichen** (Entscheidung vom 2026-11-18), ohne verwechselbare Zeichen (kein 0/O, 1/l/I),
Groß-/Kleinschreibung egal. Er gilt 24 Stunden (kürzbar) und wird nach fünf falschen Eingaben ungültig. Weil er kurz ist,
tragen Gültigkeit, Fehlversuche und die Bindung an den Nutzer zusammen die Sicherheit.

**Wer ausstellt** (Entscheidung vom 2026-11-18): **Der Administrator eines Mandanten vergibt Zugänge für seinen Mandanten;
der System-Verwalter vergibt die Administratoren der Mandanten.**

**Im System wird der Nutzer mit einer eindeutigen ID geführt** (UUID, `verwaltung.nutzer.id`) – das ist heute schon so
und ändert sich nicht. Die ID steht in Protokollen und Buchungen (`nutzer:<uuid>`); Name und E-Mail sind Stammdaten daneben.

**Folgen für das Datenmodell:** Ein Nutzer hat heute nur einen Anzeigenamen. Neu: Vorname(n), Familienname, E-Mail
(eindeutig), weitere Kontaktdaten wahlfrei. kumero versendet selbst keine E-Mails (keine externe API); die Adresse ist
Kontaktangabe und – nach Vorschlag V14 – das, woran der Code gebunden ist.

**Zu klären (V14) – woran ist der Code gebunden?** Ein Code aus sechs Zeichen darf nicht allein gelten: Sonst ließen sich
alle offenen Codes zugleich durchprobieren, und die Grenze von fünf Fehlversuchen griffe nicht. Vorschlag: **Wer den
Zugang ausstellt, trägt die E-Mail-Adresse des Nutzers ein; der Nutzer gibt beim Einlösen dieselbe Adresse und den Code
ein.** Vor- und Familienname gibt er dabei selbst an. Die Fehlversuche zählen je Adresse.

**Zu klären (V17) – Nutzer in mehreren Mandanten:** Nutzer gehören der Installation, nicht einem Mandanten. Dürfte der
Administrator von Mandant A einem Nutzer, der auch in Mandant B arbeitet, einen neuen Zugang ausstellen, könnte er sich
damit dessen Konto – und dessen Rechte in B – verschaffen. Vorschlag: Der Administrator eines Mandanten darf **neue Nutzer
anlegen** (sie bekommen Zugang zu seinem Mandanten) und für Nutzer, die **nur in seinem Mandanten** Zugang haben, einen
neuen Zugang ausstellen. Für System-Verwalter und für Nutzer mit Zugang zu weiteren Mandanten stellt nur der
System-Verwalter aus.

## 6. Seiten

Bereich **„Verwaltung“** (systemweit, nur mit `verwaltung.verwalter`):

| Seite | Inhalt |
|---|---|
| **Mandanten** | Liste mit Kennung, Name, Betriebsart, Zustand · anlegen (aus der Vorlage, F40) · stilllegen · löschen (Abschnitt 4) · Zugänge des Mandanten |
| **Nutzer** | Liste mit Vorname(n), Familienname, E-Mail, Verwalter, aktiv, Passkeys, letzte Anmeldung · anlegen · Zugang ausstellen (Link oder Code) · Passkey widerrufen · sperren · Verwalter setzen |
| **Dienstkonten** (zweite Stufe) | anlegen, Token ausstellen und widerrufen |

Bereich **„Einstellungen“** im Mandanten (mit der Rolle Administrator bzw. als System-Verwalter):

| Seite | Inhalt |
|---|---|
| **Zugang und Rollen** | wer hat welche Rolle, seit wann, befristet bis · zuweisen · beenden · **Nutzer für diesen Mandanten anlegen und Zugang ausstellen** (V16, V17) |
| **Module** | aktive Module, ein- und ausschalten |
| **Rollen** (zweite Stufe) | eigene Rollen aus Bausteinen, Rechtebericht (F41 Nr. 7) |

Alle Seiten nach F106: fester Kopf, Aktionen gelten für die markierten Einträge, gesperrte Knöpfe nennen den Grund.

## 7. REST

Erst der Vertrag in `openapi/v1.yaml`, dann der Code (F18). Systemweit unter `/api/v1/verwaltung/…`, im Mandanten unter
`/m/{mandant}/api/v1/…` (Anhang A, B6 nennt `/rollen`, `/zuweisungen`, `/rechtebericht` bereits).

| Methode | Pfad | Zweck |
|---|---|---|
| GET / POST | `/api/v1/verwaltung/mandanten` | auflisten, anlegen |
| POST | `/api/v1/verwaltung/mandanten/{kennung}/stilllegen`, `/reaktivieren` | stilllegen und zurück |
| DELETE | `/api/v1/verwaltung/mandanten/{kennung}` | löschen (Abschnitt 4) |
| GET / POST / PATCH | `/api/v1/verwaltung/nutzer`, `/nutzer/{id}` | auflisten, anlegen, ändern, sperren |
| POST | `/api/v1/verwaltung/nutzer/{id}/zugang` | Link oder Einmal-Code ausstellen; die Antwort nennt ihn einmal |
| DELETE | `/api/v1/verwaltung/nutzer/{id}/passkeys/{credential}` | Passkey widerrufen |
| PUT / DELETE | `/api/v1/verwaltung/mandanten/{kennung}/zugaenge/{subjekt}` | Zugang geben, entziehen |
| POST | `/api/v1/registrierung/code` | Kennung und Einmal-Code einlösen (ohne Anmeldung, mit Begrenzung der Versuche) |
| GET / POST / DELETE | `/m/{mandant}/api/v1/zuweisungen` | Rollen zuweisen, beenden |
| GET / PUT | `/m/{mandant}/api/v1/module` | Module des Mandanten |

## 8. Stufen

| Stufe | Inhalt |
|---|---|
| **1** | Vorname(n), Familienname, E-Mail am Nutzer · Einmal-Code · Seiten Mandanten (ohne Löschen), Nutzer, Zugang und Rollen, Module · die Regeln aus Abschnitt 2 und 3 |
| **2** | Mandant löschen (Abschnitt 4) · Dienstkonten und Tokens · eigene Rollen · Rechtebericht |
| später | Anmeldung über Keycloak / OIDC (T6), Keycloak-Gruppen auf Rollen abbilden |

## 9. Entscheidungen

| # | Frage | Stand |
|---|---|---|
| V1 | Zwei Ebenen: System-Verwalter (Kennzeichen) und Mandanten-Verwalter (Rolle „Administrator“) | **entschieden 2026-11-18** |
| V2 | Der System-Verwalter weist in jedem Mandanten jede Rolle zu, auch sich selbst; immer protokolliert. Rechte hat er erst nach der Zuweisung. | **entschieden 2026-11-18** |
| V3 | Freigaben und Notfall-Ereignisse bleiben beim Verantwortlichen, mit persönlicher Bestätigung per Passkey | **entschieden 2026-11-18** |
| V4 | Der letzte System-Verwalter lässt sich nicht entfernen | **entschieden 2026-11-18** |
| V5 | Der letzte Administrator eines Mandanten wird nicht einzeln entfernt – er geht mit dem Mandanten | **entschieden 2026-11-18** |
| V6 | Ein Mandant lässt sich löschen (Datenschutz) | **entschieden 2026-11-18**; Ablauf siehe V10, V11 |
| V7 | Der Prüfer bleibt nur lesend, gleich wer ihn anlegt | **entschieden 2026-11-18** |
| V8 | Zweiter Weg neben dem Link: Einmal-Code zum Einrichten eines Passkeys; kein Passwort zum Anmelden | **entschieden 2026-11-18** |
| V9 | Gilt V5 auch für den letzten Verantwortlichen eines Mandanten? | Vorschlag: ja |
| V10 | Löschen trotz laufender Aufbewahrungsfrist | Vorschlag: nicht verhindern, deutlich warnen, vorher Export anbieten |
| V11 | Was nach dem Löschen in der Verwaltungsdatenbank bleibt | Vorschlag: Kennung, Zeitpunkt, wer, letzter Kettenkopf – sonst nichts |
| V12 | Form des Codes | **entschieden 2026-11-18: sechs Zeichen**, ohne verwechselbare Zeichen, Groß-/Kleinschreibung egal |
| V13 | Gültigkeit und Fehlversuche | **entschieden 2026-11-18:** 24 Stunden, kürzbar; nach 5 falschen Eingaben ungültig |
| V14 | Angaben des Nutzers; woran der Code gebunden ist | **entschieden 2026-11-18:** Der Nutzer gibt Vorname(n), Familienname und Kontaktdaten an, mindestens die E-Mail; geführt wird er mit seiner eindeutigen ID (UUID). **Offen:** Bindung des Codes an die E-Mail-Adresse, die der Aussteller einträgt (Vorschlag) |
| V15 | Mehrere offene Zugänge | **entschieden 2026-11-18:** Ein neuer Link oder Code macht den vorigen desselben Nutzers ungültig. Bei verlorenem Passkey wählt der Aussteller, ob der alte widerrufen wird. |
| V16 | Wer Zugänge ausstellt | **entschieden 2026-11-18:** Der Administrator eines Mandanten für seinen Mandanten; der System-Verwalter vergibt die Administratoren der Mandanten. |
| V17 | Nutzer mit Zugang zu mehreren Mandanten | Vorschlag: Der Administrator eines Mandanten stellt nur für Nutzer aus, die allein in seinem Mandanten Zugang haben; sonst der System-Verwalter |
