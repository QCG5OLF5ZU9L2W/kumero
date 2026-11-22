# SCHLUESSEL.md – Verschlüsselung personenbezogener Daten und Postkorb (aufgenommen, vertagt)

Stand: 2026-11-20. Bezug: PERSONAL.md (PE9, Abschnitt 8a), ZEIT.md, F41 (Rechtemodell), F43 (Administrator ohne
Einsicht), F110 (kumero hält kein Dokument), F114 (Nutzer gehören zum Mandanten), F134 (Module bringen Rollen mit).
**Status: aufgenommen und vertagt (P38). Kein Entwurf zur Freigabe, nichts davon ist gebaut.** Die Datei hält fest, was
am 2026-11-20 besprochen wurde, damit es beim Wiederaufnehmen nicht neu hergeleitet werden muss.

## 1. Vorgabe des Verantwortlichen

- Alles mit Personenbezug gehört **verschlüsselt** in die Datenbank. Grundsatz: **Kenntnis nur, wenn nötig.**
- Verschlüsselte Platte und verschlüsselte Sicherungen (Stufe A) sind ohnehin Standard und kein Ersatz dafür.
- Ein Schlüssel je Mandant allein (Stufe B) **erlaubt zu viel**: wer ihn hat, liest alles.
- Aufgenommen wird **B und C zusammen**; im Kern von C steht ein **Rollenschlüssel**.
- Erkannt: Mit dem Rollenschlüssel kommen ein **Arbeitsablauf** (wer gibt wem einen Schlüssel) und eine
  **Hierarchie** (wer steht über wem) dazu. Das ist der Grund für die Vertagung.

## 2. Die Stufen

| Stufe | Schützt gegen | Schlüssel liegt |
|---|---|---|
| **A** Platte und Sicherungen | gestohlene Platte, verlorene Sicherung | am Server |
| **B** Felder verschlüsselt, Schlüssel je Mandant | zusätzlich: Datenbankauszug, Datenbankverwalter ohne Schlüsseldatei | Datei am Server, außerhalb der Datenbank |
| **C** Felder verschlüsselt, Schlüssel je Rolle und Nutzer | zusätzlich: Server-Verwalter und Administrator, solange kein Berechtigter angemeldet ist | beim Nutzer; am Server nur verpackt |

## 3. Skizze zu C: drei Ebenen

| Ebene | Was | Wer hat es |
|---|---|---|
| **Nutzer** | ein Schlüsselpaar je Nutzer | nur der Nutzer |
| **Rolle (Gruppe)** | ein Schlüsselpaar je Rolle („Personal“, „Lohn“), je Team eines Vorgesetzten und je Person für sich selbst | jedes Mitglied, verpackt mit seinem Nutzerschlüssel |
| **Daten** | ein Schlüssel je Person und Datenklasse | jede Rolle, die diese Klasse lesen darf |

- **Datenklassen** (Vorschlag): Stamm, Lohnmerkmale, Vergütung, Gesundheit (AU), Beurteilung, Zeiten.
- Welche Rolle welche Klasse liest, stünde im Manifest wie heute die Rechte; kumero leitet daraus ab, welcher
  Datenschlüssel für welche Rolle verpackt wird. Kein zweites Rollenkonzept neben dem bestehenden.
- Schlüssel **je Person**, damit „Vorgesetzter“ auf sein Team begrenzt ist.
- **Schreiben ohne Lesen:** Selfservice, Terminal und Leser verschlüsseln mit dem öffentlichen Teil.
- **Rolle entziehen:** Päckchen löschen, Rollenschlüssel erneuern; nur Verpackungen werden neu geschrieben.
- **Notfallschlüssel** je Mandant auf Papier beim Verantwortlichen, nie am Server.

## 4. Was offen ist (Grund der Vertagung)

1. **Arbeitsablauf:** Verpacken kann nur, wer selbst lesen darf. Der Administrator weist eine Rolle zu, wirksam wird
   sie erst durch ein Mitglied der Rolle oder den Notfallschlüssel – ein Vier-Augen-Schritt, den es heute nicht gibt.
2. **Hierarchie:** Teams, Vertretung, Wechsel des Vorgesetzten, mehrere Ebenen; wer erbt welche Einsicht.
3. **Erster Berechtigter** je Mandant und Verwahrung des Notfallschlüssels.
4. **Schlüssel des Nutzers:** aus dem Passkey abgeleitet (Erweiterung PRF, Unterstützung je Gerät nicht geprüft) oder
   eigene Passphrase.
5. **Ort der Entschlüsselung:** am Server während der Sitzung oder nur im Browser (dann entsteht auch das PDF dort).
6. **Was im Klartext bleiben muss:** IDs, Zeitpunkte, Zustände, „löschen ab“ – sonst kann die Datenbank nicht rechnen.
   Zeitbuchungen wären pseudonym (Personen-ID mit verschlüsseltem Namen), nicht verschlüsselt.
7. **Folgen:** kein Suchen und Sortieren in SQL über verschlüsselte Felder; nichts läuft ohne angemeldeten
   Berechtigten; ein Terminal kann nach dem Stempeln keinen Kontostand zeigen.
8. **Geltungsbereich:** nur neue Module oder auch Name und E-Mail der bestehenden Nutzer (heute Klartext).

## 5. Postkorb

Zu jeder mitarbeitenden Person gehört ein Postkorb: Genehmigung oder Ablehnung von Anträgen, Kenntnisnahme von
Beurteilungen, eigener Zuschlagsnachweis, ablaufende Fristen, Rückfragen. Gedacht als **Dienst am Mandanten**, den
alle Module nutzen. Mit C wird eine Nachricht mit dem öffentlichen Schlüssel der Person verschlüsselt: jeder kann
einwerfen, nur sie liest. Per E-Mail höchstens ein Hinweis ohne Inhalt.

Offen: Darf ein fremdes Dokument (Lohnabrechnung des Lohnbüros) im Postkorb liegen? Das wäre eine Ausnahme von F110;
sonst verweist der Postkorb nach Paperless.

## 6. Was bis dahin gilt

- Stufe A und der Zugriff nur über Rollen. `personal` wird **so entworfen, dass nichts verbaut ist**: Die Daten sind
  von Anfang an nach den Datenklassen aus Abschnitt 3 getrennt abgelegt, jede Klasse mit eigenem Recht; Schreiben und
  Lesen laufen über Funktionen, hinter denen später ver- und entschlüsselt werden kann.
- Ob `personal` vor oder erst nach diesem Konzept Lohnmerkmale aufnimmt, entscheidet der Verantwortliche mit der
  Freigabe von Stufe 1.

## 7. Besprochen am 2026-11-22 (weiter vertagt, nichts entschieden außer dem Vermerkten)

Anlass: der geplante Testbetrieb einer produktiven Instanz. Festgehalten, damit es nicht neu hergeleitet wird.

- **Verantwortlicher:** Unverschlüsselte Personaldaten sind „leider üblich“. Wer alles verschlüsselt, verliert die
  Suche in der Datenbank „und damit die halbe DB-Engine“. Datenschutzrechtlich sei das Thema extrem wichtig, technisch
  derzeit nicht ausgereift – „hier macht wohl die Realität einen Strich durch die Kryptographie“.
- **Leitlinie L2** („keine verschlüsselten oder proprietären Datenbestände“) schließt Feldverschlüsselung für die
  Buchhaltung ohnehin aus; die Frage stellt sich nur für Personaldaten.
- **Schlüsselmanagement, wenn es kommt** (Vorschlag, nicht freigegeben): Schlüssel nie bei den Daten und nie in der
  Sicherung; Hauptschlüssel je Mandant verpackt Datenschlüssel je Klasse; Notfallschlüssel auf Papier an zwei Orten,
  Wiederherstellung geübt; Erzeugen, Wechseln, Zurückziehen, Vernichten als Ereignisse im Protokoll; Löschen durch
  Vernichten des Schlüssels.
- **Engerer Schnitt statt „alles“** (Vorschlag): Platte und Sicherungen verschlüsseln (Stufe A; Sicherung an einen
  öffentlichen Schlüssel, dessen privater Teil nie am Server liegt); **Identität von Fakten trennen** und nur die
  Zuordnung ID → Name, Anschrift, Geburtsdatum sowie reine Anzeigefelder (Bankverbindung, Steuer-ID, SV-Nummer, freie
  Texte) verschlüsseln; IDs, Datumsangaben, Zustände, Zeiten bleiben Klartext. Auf Schlüssel je Person und je Team
  verzichten – „Vorgesetzter sieht nur sein Team“ bleibt eine Frage der Rechte; damit entfielen Hierarchie und der
  größte Teil des Arbeitsablaufs (Abschnitt 4 Nr. 1 und 2). Grenzfall Vergütung: Die Lohnvorbereitung rechnet in SQL.
- **Entschlüsselt im Arbeitsspeicher von PostgreSQL** (gefragt, nicht ausprobiert): (1) transparente Verschlüsselung der
  Datenbankdateien – im Community-PostgreSQL nicht vorhanden (Stand der Quellen 2025, für 19 nicht geprüft), als
  `pg_tde` nur mit gepatchtem Server von Percona; schützt nur wie eine verschlüsselte Platte. (2) Verschlüsselte Spalten,
  je Abfrage über eine Sicht mit einem Schlüssel der Sitzung entschlüsselt – volles SQL, aber keine Indizes und
  Constraints auf diesen Spalten; bei den Mengen eines KMU tragbar. (3) Entsperren in temporäre Tabellen der Sitzung für
  Auswertungen; ein dauerhafter Klartextbestand auf einer RAM-Disk ist abzuraten. Bei (2) und (3): Schlüssel und
  Klartext dürfen nicht in Protokolle, Abfragestatistiken oder temporäre Dateien geraten; gegen root mit Zugriff auf den
  Arbeitsspeicher hilft nichts davon.
- **Stand:** P38 bleibt vertagt. Es gilt weiter Abschnitt 6 (Stufe A, Zugriff nur über Rollen, Daten nach Klassen
  getrennt). **Entschieden am selben Tag (F175):** Testinstanzen und die Entwicklungsmaschine führen
  nur erfundene Daten; eine Produktionsumgebung führt echte Personen – die Regel „`personal` nur mit erfundenen
  Personen“ gilt dort nicht mehr.
