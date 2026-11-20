# BUECHER.md – Mehrere Bücher je Mandant

Stand: 2026-11-20 · Entwurf 2 · **B1–B9 freigegeben (F123, F124). Stufe A (Vertrag und Bauplan, Abschnitte 8–13) liegt
zur Freigabe vor; gebaut ist nichts.**
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
| B9 | **Ein Siegel gilt immer nur für ein Buch** (Verantwortlicher, 2026-11-20; F124): Siegel, Siegelinhalt und öffentliche Schlüssel liegen je Buch; der Siegelinhalt nennt Mandant **und** Buch. |
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
5. **Siegel (F120, F124).** Ein Siegel gilt für ein Buch (B9): Der Siegelinhalt nennt Mandant **und** Buch
   (neue Fassung des Inhalts), öffentliche Schlüssel und offene Anforderung liegen je Buch.
6. **Kommandozeile, Tests, INSTALL.md.** `modul:aktivieren <modul> <mandant> <buch>`, `buch:anlegen`,
   `buch:liste`; `tests/Hilfe/Zugaenge.php` legt Mandant, Buch, Identität und Zuweisung an.
7. **Benutzerverwaltung Stufe 2** (VERWALTUNG.md Abschnitt 6 und 8) baut auf diesem Schnitt auf: Die Seite
   „Nutzer und Rollen“ weist je Buch zu, die Seite „Module“ gilt je Buch, neu ist die Seite „Bücher“.

## 6. Offen

| Nr. | Frage | Stand |
|---|---|---|
| O1 | **Belegverweise in einer Übernahme:** Die Kopie trägt die echten Links nach Paperless. Ein Storno oder Verwerfen im Testbuch setzt oder entfernt dort Tags am echten Beleg. Vorschlag: In Testbüchern schreibt kumero nie in ein DMS. | liegt (2026-11-20) |
| O2 | **Löschen und Export je Buch** oder nur je Mandant? Vorschlag: Testbücher lassen sich einzeln löschen, echte Bücher nur mit dem Mandanten oder nach Export wie in F118. | liegt (2026-11-20) |
| O3 | **Freigabe-Passkey je Buch:** einmal im Mandanten einrichten, je Buch eintragen. Ausgearbeitet in Abschnitt 10 (A9); mit dem Auftrag „Stufe A bauen“ vom 2026-11-20 als Arbeitsannahme genommen. | **Arbeitsannahme, zu bestätigen** |
| O4 | **Kennung des Buchs** und Name der Datenbank → Abschnitt 9 (A4, A5). | ausgearbeitet |
| O5 | **Buchwährung und Sprache** je Buch (stehen heute in `kern.buch`) – bleibt so. | Vorschlag |
| O6 | **Arbeitsvorrat:** Einträge gehören dem Buch (W8 sagt „dem Mandanten“). Eine Quelle (Paperless-Tag, Datei) liefert in das Buch, in dem sie eingerichtet ist. | Vorschlag |
| O8 | **Paperless-Zugang des Nutzers für alle Bücher (B5):** Das Modul `paperless` hält Zugang, Token und Tags heute in seinem Schema im Buch. „Gilt für alle Bücher“ verlangt Daten eines Moduls in der Mandanten-Datenbank – das gibt es noch nicht (F44 kennt nur das Schema im Buch). In Stufe B und C richtet der Nutzer Paperless je Buch ein; der gemeinsame Zugang wird eigens beschrieben. | offen |
| O9 | **Rollen in einer Übernahme (B7):** Gelten die Zuweisungen des Originals auch in der Kopie, oder beginnt die Kopie nur mit dem, der sie zieht? | offen, Stufe D |
| O7 | **Startseite:** Ohne gewähltes Buch zeigt der Rahmen die Bücher des Mandanten, auf die der Nutzer eine Zuweisung hat; bei genau einem Buch geht es direkt hinein. | Vorschlag |

## 7. Stufen (Vorschlag)

| Stufe | Inhalt |
|---|---|
| A | **Vertrag und Bauplan** – Abschnitte 8–13 dieses Dokuments und `openapi/buecher-entwurf.yaml`. Kein Code. **Liegt vor, zur Freigabe.** |
| B | **Umbau:** Verwaltungsdatenbank (Bücherliste), neue Mandanten-Datenbank, Umzug der Identität mit gleichen IDs, Kernel öffnet das Buch aus dem Pfad, Rahmen mit Auswahl des Buchs, Kommandozeile. Ein Buch je Mandant, sonst alles wie bisher. Überführen des Bestands in `aktualisieren.sh`, wiederholbar. |
| C | **Zweites Buch:** Buch anlegen (Auftrag + Dienst), Art des Buchs (B4), Zuweisung je Buch (B3), Module je Buch (B5). |
| D | **Testbuch und Übernahme** (B6, B7) – nach O1 und O2. |

Stufe B ist der große Schritt und ändert Kern und Verwaltung; sie ist eine eigene Version mit einem einmaligen
Schritt auf der VM (INSTALL.md Abschnitt 4).

---

# Stufe A – Vertrag und Bauplan (Entwurf, zur Freigabe)

Die Abschnitte 8–13 beschreiben, was Stufe B und C bauen. **`openapi/v1.yaml` bleibt bis dahin, wie es ist** – es
beschreibt den Stand, der läuft (0.7.29), und wird zusammen mit dem Code umgestellt, damit der Vertrag nie etwas
anderes sagt als die Anwendung. Die neuen Endpunkte stehen bis dahin in `openapi/buecher-entwurf.yaml`.

## 8. Vertrag: drei Ebenen im Pfad

| Ebene | Pfad | Was dort liegt |
|---|---|---|
| Installation | `/api/v1/…` | Anmeldung, Abmeldung, `ich`, Registrierung, Menü der Installation, `verwaltung/…` (unverändert) |
| **Mandant** | `/m/{mandant}/api/v1/…` | wer hier arbeitet und welche Bücher es gibt: `buecher`, `nutzer`, `nutzer/{id}/zugang`, `nutzer/{id}/passkeys/{credential}`, `administratoren`, `oberflaeche/menue` (Mandant) |
| **Buch** | `/m/{mandant}/b/{buch}/api/v1/…` | alles, was heute unter `/m/{mandant}/api/v1/…` liegt und nicht in der Zeile darüber steht |

**Regel für den Umzug:** Jeder heutige Pfad `/m/{mandant}/api/v1/X` wird zu `/m/{mandant}/b/{buch}/api/v1/X` –
mit denselben Methoden, Feldern, Rechten und Fehlercodes. Ausnahmen sind genau diese:

| Heute (0.7.29) | Künftig | Warum |
|---|---|---|
| `GET`/`POST …/nutzer`, `PATCH …/nutzer/{id}` | bleibt auf der Ebene **Mandant** | Nutzer gehören zum Mandanten (F114) |
| `POST …/nutzer/{id}/zugang`, `DELETE …/nutzer/{id}/passkeys/{credential}` | bleibt auf der Ebene **Mandant** | Passkeys und Zugänge sind Teil der Identität |
| `POST …/zuweisungen`, `DELETE …/zuweisungen/{id}` | Ebene **Buch** | Rollen je Buch (B3) |
| `GET …/nutzer` nennt Rollen und `rollen` | `GET /m/{mandant}/api/v1/nutzer` ohne Rollen; neu `GET /m/{mandant}/b/{buch}/api/v1/zuweisungen` (Nutzer des Mandanten mit ihren Rollen in diesem Buch, dazu `rollen`) | die Seite „Nutzer und Rollen“ zeigt die Rollen des gewählten Buchs |
| `GET …/oberflaeche/menue` | zweimal: Mandant (Start, Bücher, Nutzer) und Buch (alles Übrige) | das Menü des Buchs hängt an seinen Modulen und Rollen |

**Neu auf der Ebene Mandant** (`openapi/buecher-entwurf.yaml`):

| Methode | Pfad | Zweck | Recht |
|---|---|---|---|
| GET | `/buecher` | Bücher des Mandanten, in die der Nutzer hineindarf; der Administrator sieht alle (ohne Inhalt) | angemeldet |
| POST | `/buecher` | Buch anlegen: Kennung, Name, Art, Buchwährung, Testbuch ja/nein → Auftrag an den Dienst | Administrator |
| PATCH | `/buecher/{buch}` | Namen ändern | Administrator |
| POST | `/buecher/{buch}/kopie` | Übernahme in ein Testbuch (B7) → Auftrag an den Dienst; **Stufe D** | Administrator und Recht `kern.buchung.sehen` im Original |
| DELETE | `/buecher/{buch}` | nur Testbücher (O2); **Stufe D** | Administrator |
| GET / POST / DELETE | `/administratoren`, `/administratoren/{nutzer}` | Administratoren des Mandanten einsetzen und abberufen; der letzte bleibt | Administrator |

`GET /api/v1/ich` nennt weiter den einen Mandanten der Anmeldung; die Bücher holt die Oberfläche aus `…/buecher`.

**Oberfläche:** `#/m/{mandant}/b/{buch}/{modul}/{ziel}` für Seiten im Buch, `#/m/{mandant}/{modul}/{ziel}` für Seiten des
Mandanten (`kern/buecher`, `kern/nutzer`). Alte Lesezeichen `#/m/{mandant}/{modul}/{ziel}` einer Buchseite führen in
das zuletzt gewählte Buch; gibt es keines, auf die Liste der Bücher.

## 9. Datenmodell

| Nr. | Festlegung |
|---|---|
| A1 | **Verwaltungsdatenbank:** `verwaltung.mandant.datenbank` nennt die **Mandanten-Datenbank**. Neu `verwaltung.buch (mandant, kennung, datenbank, angelegt_am)` – ohne Name, ohne Art. Die Wasserstandsmarke (F69) hängt am Buch: `verwaltung.wasserstand (mandant, buch, kette, …)`. Aufträge bekommen die Arten `buch_anlegen`, später `buch_kopieren`, `buch_loeschen`. |
| A2 | **Mandanten-Datenbank**, neues Schema `mandant`: `nutzer`, `anmelde_credential`, `registrierung`, `sitzung`, `nutzer_einstellung` (heute `kern.*`, unverändert im Aufbau), `buch (kennung, name, art, testbuch, kopie_von, kopie_stand, angelegt_am)`, `administrator (nutzer, seit, bis)`, `freigabe_credential` (A9), `idempotenz`, eine eigene verkettete `protokoll`-Tabelle. Geschrieben wird nur über Funktionen (F64). |
| A3 | **Datenbank des Buchs:** `kern.*` wie heute **ohne** die fünf Nutzertabellen. `kern.buch` bekommt `kennung`, `art` (`euer` \| `bilanz`) und `testbuch` (unveränderlich). Nutzer erscheinen nur als Subjekt `nutzer:<uuid>`; Namen für die Anzeige holt die Anwendung aus der Mandanten-Datenbank. |
| A4 | **Kennung des Buchs:** `^[a-z][a-z0-9-]{1,19}$` (2–20 Zeichen), eindeutig je Mandant, unveränderlich; der Name ist änderbar. |
| A5 | **Namen der Datenbanken:** Mandant `kumero_<mandant>`, Buch `kumero_<mandant>__<buch>` (Bindestriche als Unterstrich, doppelter Unterstrich als Trenner; höchstens 61 Zeichen – PostgreSQL erlaubt 63). Die Prüfung in `verwaltung.mandant` (heute 40 Zeichen) wird erweitert. |
| A6 | **Art des Buchs (B4)** ist Pflicht beim Anlegen und danach nur zum Geschäftsjahreswechsel änderbar (wie der Wechsel des Kontenrahmens, F75 Nr. 8). Bis das Modul `euer` da ist, hat sie keine Wirkung außer der Anzeige. |
| A7 | **Testbuch (B6):** `kern.buch.testbuch` ist unveränderlich; `kern.produktiv_einschalten` lehnt für ein Testbuch ab. In Testbüchern schreibt kumero nie in ein DMS (Vorschlag zu O1) – erst zu entscheiden, bevor Stufe D gebaut wird. |

## 10. Anwendung

| Nr. | Festlegung |
|---|---|
| A8 | **Weg einer Anfrage:** Die Sitzung wird in der Mandanten-Datenbank geprüft. Auf der Ebene Buch öffnet der Kernel danach die Datenbank des Buchs aus dem Pfad und liest dort die Rechte des Subjekts. Unbekanntes Buch, stillgelegtes Buch und „keine Rolle in diesem Buch“ sehen gleich aus: 404 `buch.unbekannt`. Eine Transaktion je schreibender Anfrage, in genau einer Datenbank – der des Buchs oder der des Mandanten. |
| A9 | **Freigabe-Passkey (O3):** Der Nutzer richtet ihn **einmal im Mandanten** ein (`mandant.freigabe_credential`, öffentlicher Teil). Vor der ersten Freigabe in einem Buch trägt die Anwendung ihn dort als `kern.credential` ein – im Namen des Nutzers, mit Protokolleintrag im Buch. Die Datenbank des Buchs prüft weiter selbst, dass das Credential zum Subjekt gehört (F72). Widerrufen im Mandanten widerruft in allen Büchern, in denen er eingetragen ist. |
| A10 | **Administrator (B3):** steht in der Mandanten-Datenbank (`mandant.administrator`), mit Protokoll dort. In jedem Buch hat er die Rechte des Bausteins „Verwaltung“ (`kern.rechte.*`, `kern.modul.verwalten`, `kern.webhook.verwalten`) – der Kernel ergänzt sie aus der Mandanten-Datenbank, ohne Zuweisung in jedem Buch. Buchungsdaten sieht er weiter nicht (F43). Die Rolle „Administrator“ im Buch wird nicht mehr zugewiesen; **der letzte Administrator bleibt** gilt im Mandanten, **der letzte Verantwortliche bleibt** je Buch. |
| A11 | **Wer in ein Buch hineindarf:** wer dort eine laufende Zuweisung hat. `GET …/buecher` fragt dafür jedes Buch des Mandanten – bei wenigen Büchern je Mandant vertretbar, ohne zweite Buchführung über Zugänge. |
| A12 | **Buch anlegen** ist ein Auftrag an den Dienst (wie „Mandant anlegen“, F118): Datenbank aus der Vorlage, Buch einrichten, Rollenvorlagen, Eintrag in Verwaltung und Mandanten-Datenbank. **„Mandant anlegen“ legt künftig Mandanten-Datenbank und ersten Administrator an, aber kein Buch**; das erste Buch legt der Administrator an. |
| A13 | **Modul-API:** `Kontext` bekommt `buch`. In Manifesten und Routen heißt `mandant: true` weiter „im Buch“ – Module ändern sich in PHP nicht. Module erreichen Nutzernamen nur über einen Dienst des Kerns, nie über eine Tabelle. |
| A14 | **Siegel (B9):** Siegelinhalt in **Fassung 2** mit `mandant` **und** `buch`; Dateiname `kumero-siegel-<mandant>-<buch>-<nr>.json`. Vorhandene Siegel behalten Fassung 1. |

## 11. Oberfläche und Module

- **Kopf:** hinter dem Namen des Mandanten steht an festem Platz (F106) das Buch als Auswahl; ein Testbuch ist dort
  und auf jeder Seite gekennzeichnet. Wechsel des Buchs lädt Menü und Seite neu, ohne neue Anmeldung (B2).
- **Menü:** Start und der neue Bereich des Mandanten (Bücher, Nutzer und Rollen) kommen aus dem Menü des Mandanten,
  alles Übrige aus dem Menü des Buchs. Ohne gewähltes Buch zeigt der Rahmen die Bücher (O7).
- **Seite „Bücher“** (Administrator): Liste mit Kennung, Name, Art, Testbuch, Zustand; anlegen; umbenennen.
- **Seite „Nutzer und Rollen“:** Nutzer wie in 0.7.29; die Spalte „Rollen“ und die Knöpfe „Rolle zuweisen“ und „Rolle
  beenden“ gelten für das im Kopf gewählte Buch. Neu: „Als Administrator einsetzen“ und „abberufen“.
- **Modulseiten:** Sie rufen die API schon über `k.apiBasis` auf und brauchen dafür keine Änderung. **Zu ändern:** An
  rund 20 Stellen bauen `buchen` und `arbeitsvorrat` Seitenpfade selbst (`#/m/${k.mandant}/buchen/…`). Der Rahmen
  bekommt dafür `k.seitenBasis` (`#/m/{mandant}/b/{buch}`), die Module nutzen es; `k.buch` und `k.buchName` kommen dazu.
  Jede geänderte Modulseite braucht eine neue Modulversion.

## 12. Überführen des Bestands (Stufe B)

Einmaliger Schritt in `aktualisieren.sh`, wiederholbar, wie das Überführen der Nutzer in 0.7.22:

1. Sicherung vorher (INSTALL.md nennt den Befehl): `kumero_verwaltung` und jede Mandanten-Datenbank.
2. Je Mandant: die vorhandene Datenbank `kumero_<mandant>` wird in `kumero_<mandant>__haupt` **umbenannt** – sie ist das
   erste Buch mit der Kennung `haupt` und dem bisherigen Namen. Journal, Ketten und Siegel bleiben unberührt; **kein
   Hash ändert sich.**
3. Neue Mandanten-Datenbank `kumero_<mandant>`; die Nutzer, Passkeys, offenen Zugänge und Einstellungen ziehen mit
   gleicher ID dorthin um. Sitzungen ziehen nicht um – alle melden sich einmal neu an.
4. Wer im Buch die Rolle „Administrator“ hat, wird Administrator des Mandanten; die Zuweisung im Buch wird beendet.
   Die übrigen Zuweisungen bleiben im Buch.
5. Verwaltung: `verwaltung.buch` und Wasserstandsmarken je Buch.
6. `spiel` wird als Testbuch gekennzeichnet (B8). Alle anderen Bücher bleiben gewöhnliche Bücher im Testbetrieb.
7. Im Protokoll des Buchs bleiben die alten Einträge `nutzer.*` stehen (unveränderlich) – mit Namen und E-Mail, wie
   sie damals eingetragen wurden. Das ist dieselbe Lage wie in VERWALTUNG.md S8.

## 13. Festlegungen der Stufe A – zu bestätigen

A1–A14 oben. Davon tragen am meisten:

| Nr. | Worum es geht |
|---|---|
| A5 und Abschnitt 12 Nr. 2 | Die Datenbank des Bestands wird **umbenannt**; das erste Buch heißt überall `haupt`. |
| A9 | Freigabe-Passkey einmal im Mandanten, je Buch eingetragen vor der ersten Freigabe. |
| A10 | Administrator steht im Mandanten und wirkt in jedem Buch ohne Zuweisung dort. |
| A12 | „Mandant anlegen“ legt kein Buch mehr an. |
| A14 | Siegelinhalt Fassung 2 mit dem Buch. |
| Abschnitt 8 | `openapi/v1.yaml` wird erst mit dem Code umgestellt; bis dahin gilt der Entwurf daneben. |

**Stufe B danach, in dieser Reihenfolge:** Migrationen (Verwaltung, Mandant, Kern) mit SQL-Tests → Überführen mit
Test am Bestand von 0.7.29 → Kernel, Identität und Kommandozeile → Rahmen der Oberfläche → Module (Seitenpfade) →
`openapi/v1.yaml`. Stufe B ist eine Version mit einmaligem Schritt auf der VM; ein Zurück gibt es nur über die
Sicherung.
