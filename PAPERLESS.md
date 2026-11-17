# PAPERLESS.md – Modul `paperless`, erste Stufe: Verbindung, Tags, offene Belege, Vorschau, Belegverweis (Entwurf 1, zur Freigabe)

Stand: 2026-11-17. Bezug: T8 (Belege liegen in Paperless-ngx), F10/F27 (Belegverweis), F49/F50
(Rückschreiben, Kern ist führend), F51 (Paperless → Buchhaltung), F59 (Ausfälle blockieren das Buchen nicht),
BUCHUNGSMASKE.md M16/M17, BELEGEINGANG.md Abschnitt 1a und 2a, PROFIL.md U11.
**Status: Entwurf. Gebaut wird erst nach Freigabe** (Entscheidungen L1–L15 in Abschnitt 9; L2, L3, L3a, L4,
L4a, L7, L10, L11, L13, L14 und L15 sind am 2026-11-17 entschieden).

Das Modul verbindet einen Mandanten mit **seinem** Paperless-ngx. Es ist das Integrationsmodul, das in
AGENTS.md (F45, Anhang A4) vorgesehen ist und bisher fehlt. Die erste Stufe liefert, was Buchungsmaske und
Arbeitsvorrat brauchen; das Rückschreiben der Buchungsdaten (F49) folgt als zweite Stufe.

Hinweis: Die Angaben zur Schnittstelle von Paperless-ngx (Pfade, Feldnamen) stammen aus der Dokumentation
von Paperless-ngx und sind vor dem Bauen gegen die eingesetzte Version zu prüfen (nopaper nennt 3.x).

---

## 1. Umfang

| Stufe | Inhalt |
|---|---|
| **1 (dieser Entwurf)** | Verbindung je Mandant · Tags übernehmen (M17) · Belege mit dem Tag für offene Belege als Quelle des Arbeitsvorrats · Vorschau und Link (M16) · Belegverweis mit Prüfung (F10, F51 Nr. 4) · Tag nach dem Buchen entfernen (BELEGEINGANG.md B14) |
| 2 (später) | Buchungsdaten in benutzerdefinierte Felder und Notizen zurückschreiben (F49), Prüfbericht über Abweichungen (F50), Siegelprotokoll als PDF nach Paperless (F35) |

Der Kern bleibt ohne Paperless lauffähig (F44); ohne das Modul bleibt alles wie in 0.7.0.

## 2. Verbindung: der Nutzer mit seinem eigenen Paperless-Konto

Entschieden am 2026-11-17: **Das Token für Paperless gehört dem Nutzer.** Sein Konto in kumero und sein
Konto in Paperless sind dieselbe Identität. kumero handelt in Paperless also immer als dieser Mensch und
sieht dort genau, was er sehen darf – einen gemeinsamen API-Benutzer je Mandant gibt es nicht.

- **Eingerichtet wird die Verbindung in „Profil und Einstellungen“** (PROFIL.md, Abschnitt „Paperless“):
  Adresse des Paperless (nur `https://`), das eigene Token, das **Tag für offene Belege** (U11) und die
  Auswahl der Tags (Abschnitt 3). Wer die Verbindung anlegt, legt auch das Tag fest.
- **Vorgabe des Mandanten, eigene Einträge des Nutzers** (L11, entschieden am 2026-11-17): Der Mandant
  kann ein Paperless **vorgeben** (Adresse, Bezeichnung). Der Nutzer kann die Vorgabe **überschreiben**
  und **weitere Instanzen** einrichten – z. B. wenn Firmen zusammengeführt wurden und ihre Belege in
  verschiedenen Paperless liegen. Jede Instanz hat ihre eigene Adresse, ihr eigenes Token, ihr eigenes Tag
  für offene Belege und ihre eigene Tag-Auswahl.
- **Der Verweis auf einen Beleg ist der ganze Link** (L15, entschieden am 2026-11-17), z. B.
  `https://ngx.5st.eu/documents/5210/`. Er nennt Instanz und Dokument zugleich und ist damit auch bei
  mehreren Paperless eindeutig. Die Dokument-ID allein genügt nicht.
  - **Folge für den Kern:** Der Belegverweis speichert heute nur die Dokument-ID als Zahl
    (`kern.belegverweis.paperless_id`, F10); übernommene Tags ebenso. Beides wird auf den Link umgestellt
    bzw. um ihn ergänzt – eine Änderung an Datenmodell, API (`Belegverweis`) und kanonischer Form (F29),
    solange es nur Testbuchungen gibt. MD5 und SHA-256 des Originals bleiben daneben bestehen: Der Link
    sagt, **wo** der Beleg liegt, die Prüfsummen, **welche Datei** es ist.
  - Einheitliche Schreibweise des Links (Schema, Host klein, Pfad `/documents/<id>/`), damit derselbe
    Beleg immer denselben Verweis hat. nopaper liefert Adresse und ID bereits getrennt im Sidecar
    (`paperless-url`, `paperless-document-id`).
  - Zieht ein Paperless auf eine andere Adresse um, bleiben die versiegelten Links, wie sie waren; der
    Mandant hinterlegt dann „frühere Adresse → heutige Adresse“, damit Vorschau und Abgleich weiter gehen.
- **Ablage des Tokens (L3, entschieden am 2026-11-17):** verschlüsselt, und **entschlüsselt wird mit der
  Anmeldung des Nutzers**, z. B. per Passkey. Es muss dabei **nutzbar bleiben**. Vorschlag für die Umsetzung:
  - Jede Verbindung hat einen eigenen Datenschlüssel; das Token liegt nur damit verschlüsselt in der
    Mandanten-Datenbank. Der Datenschlüssel liegt nirgends offen: Er ist mit einem Schlüssel verpackt, den
    nur der **Passkey des Nutzers** bei der Anmeldung liefert (WebAuthn-Erweiterung „PRF“). Eine Sicherung
    der Datenbank, der Betreiber und ein Angreifer mit Datenbankzugriff kommen damit nicht an das Token.
  - **Mit der Anmeldung** wird der Datenschlüssel entpackt und für die Dauer der Sitzung im Arbeitsspeicher
    des Servers gehalten (nie auf Platte, nie im Log). Solange ist das Token nutzbar – auch für das, was
    kurz nach einer Buchung im Hintergrund geschieht (Tag entfernen, Abschnitt 7).
  - **Nach der Abmeldung** ist das Token gesperrt. Was dann noch offen ist, bleibt „ausstehend“ und läuft
    bei der nächsten Anmeldung von selbst weiter (wie F16/F59: nachholen, nichts blockiert).
  - **Mehrere Passkeys:** Jeder registrierte Passkey verpackt den Datenschlüssel für sich; ein weiterer
    Passkey wird hinzugefügt, während man mit einem vorhandenen angemeldet ist. Sind alle Passkeys
    verloren, ist das Token nicht wiederherstellbar – es wird in Paperless neu erzeugt und neu eingetragen.
  - Angezeigt wird das Token nach dem Speichern nie wieder, nur „gesetzt am …“ sowie Ersetzen und Löschen.
  - **Vor dem Bauen zu prüfen (L3a):** Die Erweiterung „PRF“ muss der verwendete Passkey und der Browser
    können (aktuelle Sicherheitsschlüssel und Plattform-Passkeys in der Regel ja, Passwortmanager
    unterschiedlich). Für Passkeys ohne PRF braucht es eine Entscheidung: Token bei jeder Anmeldung neu
    eingeben, eine eigene Passphrase – oder kein Paperless mit diesem Passkey. Die eigene WebAuthn-Prüfung
    (F77 E3) wird um die Erweiterung ergänzt.
- **Arbeiten im Hintergrund** (entschieden am 2026-11-17): mit dem Token dessen, der festgeschrieben hat –
  solange er angemeldet ist, sonst bei seiner nächsten Anmeldung.
- **Verbindungstest** in der Oberfläche: erreichbar, Token gültig, Version, Name des Paperless-Kontos, Tag
  für offene Belege vorhanden, Recht zum Ändern von Dokumenten vorhanden.
- kumero spricht nur mit der eingetragenen Adresse; es folgt keinen Weiterleitungen zu anderen Hosts und
  lädt nie beliebige Adressen (L3, Schutz gegen Missbrauch des Servers als Abrufstelle). Welche Adressen
  überhaupt eingetragen werden dürfen, kann der Betreiber einschränken (Liste erlaubter Hosts).
- Aufrufe gehen vom Server aus (PHP), nicht vom Browser: Die strenge CSP der Oberfläche bleibt ohne
  Ausnahme (F80 N5), und das Token verlässt den Server nie in Richtung Browser.
- Das Modul legt in Paperless nichts an, außer es ist ausdrücklich entschieden (L9).

## 3. Tags übernehmen (M17, F51 Nr. 1)

- Entschieden am 2026-11-17: **Der Nutzer wählt aus.** Ein aufklappbares Fenster zeigt alle Tags, die sein
  Paperless-Konto sieht, mit Suchfeld und Auswahlkästchen; angehakt sind die, die er haben will. Private
  Ablage-Tags bleiben einfach ungewählt. Das Tag für offene Belege und die Tags von nopaper sind
  Arbeits-Tags; sie stehen in der Liste, werden aber als solche gekennzeichnet und nicht vorgeschlagen.
- Entschieden am 2026-11-17 (L4a): Tags an Buchungen gehören dem **Mandanten** (`kern.tag`), nicht dem
  einzelnen Nutzer. Ein gewähltes Tag wird im Mandanten angelegt (dafür braucht der Nutzer das Recht
  `kern.stammdaten.aendern`) und steht dann allen zur Verfügung; die Auswahl des Nutzers bestimmt
  zusätzlich, welche Tags **ihm** in der Buchungsmaske angeboten werden.
- Der Kern ist vorbereitet: `kern.tag` mit Herkunft `paperless` und `paperless_id`, angelegt über
  `kern.tag_anlegen`. Das Modul schreibt Tags nur über diese Funktion (F44, F64).
- **Abgleich** beim Öffnen des Fensters und auf Knopfdruck. Ergebnis als kurzer Hinweis: neu in Paperless,
  in Paperless umbenannt, in Paperless gelöscht.
- **Umbenannt in Paperless:** Der Name eines Tags geht in den Inhalt festgeschriebener Buchungen ein.
  Ein bebuchtes Tag wird in kumero deshalb nicht still umbenannt; der Hinweis zeigt die Abweichung (L5).
- **Gelöscht in Paperless:** Das Tag bleibt in kumero bestehen, wird aber nicht mehr zur Auswahl angeboten
  (dafür braucht der Kern ein Kennzeichen „nicht mehr anbieten“ am Tag – kleine Ergänzung im Kern).
- Richtung nur Paperless → kumero. Tags, die in kumero von Hand angelegt wurden, bleiben daneben bestehen.

## 4. Offene Belege für den Arbeitsvorrat

- Das Modul ist eine **Quelle** des Arbeitsvorrats (BELEGEINGANG.md Abschnitt 1a): Es liefert die Belege,
  die in Paperless das Tag für offene Belege tragen (PROFIL.md U11, BELEGEINGANG.md B13a).
- Je Beleg: Paperless-ID, Titel, Erstelldatum, Korrespondent, Dokumenttyp, Dateiname des Originals, die
  Felder „Erkannt: …“ von nopaper, soweit vorhanden. **Nichts davon wird dauerhaft in kumero gespeichert**;
  die Liste wird beim Öffnen des Arbeitsvorrats gelesen und kurz zwischengehalten (höchstens wenige
  Minuten). Dauerhaft liegt nur, was an einer Buchung hängt (Belegverweis).
- Zähler im Menü („Arbeitsvorrat (12)“) aus derselben Abfrage.
- Ist Paperless nicht erreichbar, zeigt der Arbeitsvorrat das an und arbeitet mit den übrigen Quellen
  weiter (F59).

## 5. Vorschau und Link (M16)

- In der Buchungserfassung steht das Feld **„Beleg / Link“**. Erkannt werden: die Paperless-ID als Zahl
  und ein Link auf ein Dokument des angebundenen Paperless (Adresse wie in der Verbindung, Pfad mit der
  Dokument-ID). Andere Adressen bekommen **keine Vorschau** (L7) – sie werden als gewöhnlicher Link
  angezeigt, den der Browser in einem neuen Fenster öffnet.
- **Vorschau** neben dem Formular: das Vorschaubild sofort, das Original (PDF) auf Wunsch groß. Beides holt
  kumero über das Modul und reicht es durch – unter der eigenen Adresse, ohne es zu speichern:

  | Methode | Pfad | Zweck | Recht |
  |---|---|---|---|
  | GET / PUT / DELETE | `/m/{mandant}/api/v1/paperless/verbindung` | eigene Verbindung lesen (ohne Token), setzen, löschen | `paperless.beleg.sehen` |
  | GET | `/m/{mandant}/api/v1/paperless/status` | Verbindungstest der eigenen Verbindung | `paperless.beleg.sehen` |
  | GET | `/m/{mandant}/api/v1/paperless/tags` | Tags des eigenen Paperless-Kontos für das Auswahlfenster | `paperless.beleg.sehen` |
    | GET | `/m/{mandant}/api/v1/paperless/dokumente?offen=1` | offene Belege (Quelle des Arbeitsvorrats) | `paperless.beleg.sehen` |
  | GET | `/m/{mandant}/api/v1/paperless/dokumente/{id}` | Angaben zu einem Beleg | `paperless.beleg.sehen` |
  | GET | `/m/{mandant}/api/v1/paperless/dokumente/{id}/vorschau` | Vorschaubild | `paperless.beleg.sehen` |
  | GET | `/m/{mandant}/api/v1/paperless/dokumente/{id}/original` | Originaldatei | `paperless.beleg.sehen` |
  | POST | `/m/{mandant}/api/v1/paperless/dokumente/{id}/verweis` | Original prüfen, Belegverweis liefern (Abschnitt 6) | `paperless.beleg.sehen` |
  | PUT | `/m/{mandant}/api/v1/paperless/tags/auswahl` | gewählte Tags übernehmen (Abschnitt 3) | `kern.stammdaten.aendern` |

- Belege enthalten in einer Praxis besondere Kategorien von Daten (Art. 9 DSGVO): Die durchgereichten
  Antworten tragen `Cache-Control: no-store`; im Server-Log steht nur die Paperless-ID.
- Jeder sieht über kumero nur die Belege, die **sein eigenes Paperless-Konto** sehen darf; die Rechte in
  Paperless gelten unverändert weiter.

## 6. Belegverweis mit Prüfung (F10, F51 Nr. 4)

Beim Zuordnen eines Belegs zu einem Entwurf:

1. Angaben des Dokuments lesen, darin die MD5 des Originals (`original_checksum`).
2. **Original laden**, SHA-256 und MD5 selbst berechnen.
3. MD5 gegen die Angabe von Paperless vergleichen; liegt ein Sidecar von nopaper vor, zusätzlich
   `sha256-original` vergleichen.
4. Stimmt alles: Belegverweis (Paperless-ID, MD5, SHA-256) in den Entwurf; er geht beim Festschreiben in
   den Hash ein. **Weicht etwas ab: keine Verknüpfung**, Hinweis am Feld (F51 Nr. 4).

Ist Paperless beim Buchen nicht erreichbar, wird ohne Beleg gebucht und der Verweis später über „Beleg
nachgereicht“ ergänzt (F27, F59).

## 7. Nach dem Festschreiben

- Das Modul hört auf `buchung.festgeschrieben` und `buchung.storniert` (F21) und arbeitet **nach** dem
  Commit: Tag für offene Belege am Beleg entfernen bzw. bei Storno wieder setzen (BELEGEINGANG.md B9, B14).
- Schlägt das fehl, bleibt die Aufgabe „ausstehend“ und wird wiederholt; die Statusanzeige nennt sie
  (F59). Die Buchung selbst ist davon nie betroffen.
- Geändert wird in Paperless nur dieses eine Tag am Dokument – nie Titel, Inhalt, andere Tags oder Felder
  anderer (nopaper E35: die Felder „Erkannt: …“ gehören nopaper).

## 7a. Livelog

Das Modul schreibt seine Aufrufe an Paperless in das **allgemeine Livelog** des Kerns (LIVELOG.md), mit
dem Modulnamen `paperless` und damit filterbar: Auslöser, Methode und Pfad, Instanz, Paperless-ID,
Antwortstatus, Dauer, Ergebnis als Stichwort („MD5 stimmt“, „Tag entfernt“, „Token abgelehnt“). Nie Token,
Inhalte, Titel oder Korrespondenten.

## 8. Schnitt und Rechte

- Modul `paperless` mit Manifest, eigenem Schema `paperless` (Verbindungen je Nutzer mit verschlüsseltem
  Token, Tag-Auswahl, Warteschlange „ausstehend“), Abschnitt in „Profil und Einstellungen“,
  Endpunkten aus Abschnitt 5.
- Rechte: `paperless.beleg.sehen` (Erfasser, Buchhalter, Verantwortlicher, Steuerberater, Prüfer) – es
  erlaubt, die eigene Verbindung einzurichten und darüber Belege zu sehen; `paperless.vorgabe.pflegen`
  (Verantwortlicher) für die vorgegebene Adresse. Administratoren sehen keine Belege (F43).
- Abhängigkeiten: nur der Kern. `belegeingang` und `buchen` nutzen das Modul, wenn es aktiv ist.
- **Testen ohne echtes Paperless:** ein nachgebauter Paperless-Server für die Tests (nur synthetische
  Belege). Vor dem ersten Einsatz zusätzlich ein Lauf gegen das echte Paperless des Betreibers – dort
  durch den Betreiber, nicht aus dem Chat.

## 9. Entscheidungen zur Freigabe

| # | Frage | Vorschlag |
|---|---|---|
| L1 | Umfang der ersten Stufe | wie Abschnitt 1; Rückschreiben nach F49 als zweite Stufe. |
| L2 | Zugriff | **Entschieden am 2026-11-17:** mit dem **eigenen Token des Nutzers** (kumero-Konto und Paperless-Konto sind eine Identität), vom Server aus; der Browser spricht nie selbst mit Paperless. |
| L3 | Ablage des Tokens | **Entschieden am 2026-11-17:** verschlüsselt; entschlüsselt mit der Anmeldung des Nutzers (z. B. Passkey); es muss nutzbar bleiben. Umsetzung wie in Abschnitt 2: Datenschlüssel je Verbindung, verpackt mit einem Schlüssel aus dem Passkey (WebAuthn PRF), nutzbar während der Sitzung, danach gesperrt. |
| L3a | Passkeys ohne PRF | Der Referenzbetrieb nutzt **KeePassXC 2.8.0** und damit zunächst den Weg über die Passphrase. Nach einer Übersicht von Corbado (Stand August 2026) unterstützt keine veröffentlichte Version von KeePassXC die Erweiterung PRF; eine Änderung dafür ist offen und für 2.8.1 vorgesehen – vor dem Bauen mit der eingesetzten Version nachprüfen. **Entschieden am 2026-11-17: eigene Passphrase als Ausweg** – zwei gleichwertige Wege zum Entsperren. (1) **Passphrase für Paperless**: einmal je Sitzung eingeben, sobald Paperless gebraucht wird; der Schlüssel wird daraus abgeleitet (Argon2id). Sie kann in KeePassXC liegen und von dort ausgefüllt werden. (2) **Passkey mit PRF**: entsperrt ohne Eingabe gleich bei der Anmeldung, sobald Passkey und Browser es können. Beide verpacken denselben Datenschlüssel. |
| L4 | Welche Tags | **Entschieden am 2026-11-17:** aufklappbares Fenster mit Auswahl; der Nutzer wählt, welche er haben will. |
| L4a | Geltung der Auswahl | Gewählte Tags werden im Mandanten angelegt (mit `kern.stammdaten.aendern`); die Auswahl bestimmt zusätzlich, was dem Nutzer in der Maske angeboten wird. **Entschieden am 2026-11-17.** |
| L5 | Umbenannte Tags | Bebuchte Tags behalten in kumero ihren Namen; der Abgleich meldet die Abweichung. Zu prüfen: wie die Kettenprüfung mit Tag-Namen umgeht (F74 Nr. 1 sagt „Name änderbar und nicht Teil des Hashes“, der Inhalt einer Buchung nennt Tags aber beim Namen). |
| L6 | Offene Belege | Live aus Paperless mit kurzem Zwischenhalten, nichts dauerhaft kopieren. |
| L7 | Link-Vorschau | **Entschieden am 2026-11-17:** zunächst nur für Paperless; andere Links ohne Vorschau. Ob andere Links an der Buchung gespeichert werden, bleibt für später offen. |
| L8 | Belegverweis | Prüfung wie Abschnitt 6; bei Abweichung keine Verknüpfung. |
| L9 | Anlegen in Paperless | Das Modul legt nichts an (wie nopaper E40); das Tag für offene Belege gibt es in Paperless bereits (es wird dort schon gesetzt); der Nutzer trägt es in seinen Einstellungen ein. Erst die zweite Stufe (F49) verlangt Felder – dann neu entscheiden. |
| L10 | Tag nach dem Buchen | **Entschieden am 2026-11-17:** Entfernen nach dem Festschreiben, wieder setzen bei Storno – mit dem Token dessen, der festgeschrieben hat; ist er nicht mehr angemeldet, bei seiner nächsten Anmeldung. |
| L11 | Mehrere Paperless | **Entschieden am 2026-11-17:** Der Mandant kann ein Paperless vorgeben; der Nutzer kann es überschreiben und weitere Instanzen einrichten (z. B. nach dem Zusammenführen von Firmen). |
| L12 | Zu klären: Version | Welche Version von Paperless-ngx läuft beim Referenzbetrieb, und ist das Paperless vom kumero-Server aus per HTTPS erreichbar (gleiches Netz, Reverse-Proxy)? |
| L13 | Livelog | **Entschieden am 2026-11-17:** Es gibt ein Livelog, auf Wunsch angezeigt – allgemein, nicht nur für Paperless, und nach Modul filterbar → LIVELOG.md. |
| L14 | Livelog als Baustein | **Entschieden am 2026-11-17:** allgemeiner Baustein des Kerns (LIVELOG.md V1, V2). |
| L15 | Verweis auf den Beleg | **Entschieden am 2026-11-17:** Der Verweis ist der ganze Link (`https://…/documents/<id>/`), nicht die Dokument-ID allein. Der Kern wird darauf umgestellt (Abschnitt 2); MD5 und SHA-256 bleiben. |
