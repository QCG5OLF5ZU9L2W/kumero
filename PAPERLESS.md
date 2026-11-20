# PAPERLESS.md – Modul `paperless`, erste Stufe: Verbindung, Tags, offene Belege, Vorschau, Belegverweis (Entwurf 1, zur Freigabe)

Stand: 2026-11-17. Bezug: T8 (Belege liegen in Paperless-ngx), F10/F27 (Belegverweis), F49/F50
(Rückschreiben, Kern ist führend), F51 (Paperless → Buchhaltung), F59 (Ausfälle blockieren das Buchen nicht),
BUCHUNGSMASKE.md M16/M17, BELEGEINGANG.md Abschnitt 1a und 2a, PROFIL.md U11.
**Status: erste Stufe gebaut in kumero 0.7.4 als Modul `paperless` 0.1.0 (F92), Auftrag vom 2026-11-17.** Was gebaut ist und
wo die Umsetzung vom Entwurf abweicht, steht in Abschnitt 10 und ist noch zu bestätigen. Nicht gegen ein echtes
Paperless geprüft, nur gegen einen nachgebauten Server.

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
  - **Im Kern umgesetzt mit kumero 0.7.2 (F88):** Der Belegverweis trägt den Link; Prüfsummen dürfen im Testbetrieb fehlen, bis dieses Modul sie am Original ermittelt. Offen bleibt die Paperless-ID an übernommenen Tags. Stand vor 0.7.2:
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

## 10. Stand der Umsetzung (0.7.4) und Festlegungen zur Bestätigung

Gebaut: Zugang des Nutzers mit eigenem Token (L2), Vorgabe des Mandanten und mehrere Instanzen (L11), Verbindungstest,
Tags auswählen (L4, L4a), Vorschau und Original (L7), Belegverweis mit Prüfung (L8, Abschnitt 6), Quelle des
Arbeitsvorrats (L6), Tag nach dem Buchen entfernen und bei Storno wieder setzen (L10, B9, B14).

1. **Entsperren nur mit Passphrase (L3a).** Der Weg über den Passkey (WebAuthn PRF, L3) ist noch nicht gebaut; er
   verlangt eine Ergänzung der Anmeldung im Kern. Die Passphrase hat mindestens 10 Zeichen; aus ihr wird mit
   Argon2id der Schlüssel abgeleitet, das Token liegt mit XSalsa20-Poly1305 verschlüsselt in der Mandanten-Datenbank.
2. **„Im Arbeitsspeicher des Servers“ ist anders gelöst.** PHP hält zwischen zwei Anfragen nichts im Speicher.
   Stattdessen: Beim Entsperren entsteht ein zufälliger Sitzungsschlüssel, den **nur der Browser** als Cookie hält
   (`__Host-kumero-paperless`: nicht für Skripte lesbar, nur über HTTPS, nur an kumero). Das Token liegt für die
   Sitzung ein zweites Mal verschlüsselt in der Datenbank, mit diesem Schlüssel und gebunden an die Sitzung. Aus der
   Datenbank allein – Sicherung, Betreiber – ist das Token damit weiterhin nicht zu gewinnen; „Sperren“ und das Ende
   der Sitzung machen es unbrauchbar.
3. **Wo der Zugang steht:** nicht auf der Seite „Profil und Einstellungen“ (die ist ohne Mandant), sondern als
   eigene Seite **Einstellungen → „Paperless – mein Zugang“** im Mandanten. Dort stehen auch das Tag für offene
   Belege (U11) und die Auswahl der Tags.
4. **Token wird beim Anlegen geprüft**, das Tag für offene Belege muss es in Paperless geben; kumero legt dort
   nichts an (L9).
5. **Adresse:** nur `https://`. Einzige Ausnahme ist `http://localhost` bzw. `http://127.0.0.1` für ein Paperless
   auf derselben Maschine (und für die Tests). Eine Liste erlaubter Hosts des Betreibers gibt es noch nicht.
6. **Tags (L4a):** Gewählte Tags werden im Mandanten angelegt. Dass die Auswahl zusätzlich bestimmt, welche Tags
   dem einzelnen Nutzer in der Maske angeboten werden, ist nicht gebaut – alle Tags des Mandanten stehen allen zur
   Wahl. Ein gleichnamiges oder mit gleicher Paperless-ID vorhandenes Tag wird übergangen, nie überschrieben.
   Umbenannte und gelöschte Tags (L5) werden noch nicht gemeldet.
7. **Arbeitsvorrat (L6, W8):** Beim Öffnen liefert das Modul die Belege mit dem Tag für offene Belege ein – mit
   den entsperrten Verbindungen dessen, der gerade öffnet. Die Einträge gehören danach dem Mandanten und bleiben
   liegen, auch wenn Paperless gesperrt oder nicht erreichbar ist. Übernommen werden Titel, Korrespondent,
   Erstelldatum, Dokumenttyp (als Art) und der Beleglink; Inhalte der Belege nie.
8. **Tag entfernen (L10):** nicht „mit dem Token dessen, der festgeschrieben hat“, sondern beim nächsten Öffnen des
   Arbeitsvorrats durch jemanden, dessen Verbindung zu diesem Paperless entsperrt ist. Entfernt wird es bei
   gebuchten und bei verworfenen Einträgen; bei Storno oder Wiederöffnen setzt kumero es wieder. Entfernt jemand
   das Tag in Paperless von Hand, verschwindet der unbearbeitete Eintrag aus dem Vorrat.
9. **Belegprüfung in der Maske:** Gehört der Link zu einem entsperrten Paperless des Nutzers, prüft die Maske ihn
   beim Verlassen des Felds von selbst und speichert MD5 und SHA-256 am Entwurf. Stimmt die Prüfsumme nicht, bleibt
   der Verweis ungeprüft, mit Hinweis am Feld. Bereits festgeschriebene ungeprüfte Verweise lassen sich nicht
   nachträglich prüfen.
10. **Vorschau:** in der Buchungsmaske, in der Buchungsliste und an der festgeschriebenen Buchung, als Bild in einem
    Fenster; das Original öffnet ein neues Browserfenster. Beides reicht kumero durch, mit `Cache-Control: no-store`.
    Im Arbeitsvorrat selbst gibt es nur den Link; die Vorschau steht in der Maske.
11. **Nicht gebaut:** Livelog (7a), Warteschlange „ausstehend“ mit Wiederholung, Zähler aus Paperless, Abgleich mit
    nopaper-Sidecars (kommt mit dem Modul `nopaper`), Rückschreiben nach F49 (Stufe 2).
12. **Aufrufe an Paperless-ngx** (aus dessen Dokumentation, gegen 3.x noch zu prüfen): `Authorization: Token …`,
    `/api/tags/`, `/api/correspondents/`, `/api/document_types/`, `/api/documents/?tags__id__all=`,
    `/api/documents/{id}/`, `…/metadata/` (`original_checksum`), `…/download/?original=true`, `…/thumb/`,
    `PATCH /api/documents/{id}/` nur mit `tags`, `/api/ui_settings/`.

## 11. Rückmeldung aus dem ersten Test mit echtem Paperless (2026-11-18, noch nicht gebaut)

1. **Ort des Zugangs (ändert Abschnitt 10 Nr. 3):** Die Einstellungen für das eigene Paperless gehören nach
   Ansicht des Verantwortlichen unter **Benutzer → „Profil und Einstellungen“**, nicht als eigene Seite unter
   Einstellungen des Mandanten – so, wie es PROFIL.md Abschnitt 2 vorsah. Zu beschreiben ist dabei, wie die Seite
   ohne gewählten Mandanten mit Verbindungen umgeht, die je Mandant gelten (F93, P20).
2. **Verbinden scheitert mit „Paperless hat unerwartet geantwortet (HTTP 406)“.** Vermutete Ursache: kumero
   verlangt im Kopf `Accept: application/json; version=5` eine feste API-Version; Paperless-ngx antwortet mit
   406, wenn es die verlangte Version nicht (mehr) bedient. Noch zu prüfen am echten Paperless: welche
   API-Version es meldet (Antwortkopf `X-Api-Version`) und ob der Aufruf mit dieser Version bzw. ohne
   Versionsangabe gelingt. Danach: Version nicht fest verdrahten, sondern die gemeldete verwenden, und den
   Verbindungstest so bauen, dass er den Grund nennt.
   **Bestätigt am 2026-11-18** am Paperless des Referenzbetriebs: Paperless-ngx **3.4.2** meldet
   `X-Api-Version: 10`; ohne Versionsangabe antwortet es mit 200, mit `version=5` mit 406 und
   `{"detail":"Invalid version in \"Accept\" header."}`. Der kumero-Server erreicht das Paperless, das Token gilt.
   Version 5 wird von 3.4.2 also nicht mehr bedient.
   **Vorgabe des Verantwortlichen vom 2026-11-18:** kumero **führt die Version von Paperless und die Version
   seiner API je Verbindung mit und wendet sie von selbst an.** Vorschlag für die Umsetzung (noch nicht gebaut):
   - Je Verbindung werden gespeichert: gemeldete Paperless-Version (`X-Version`), gemeldete API-Version
     (`X-Api-Version`), die von kumero verwendete API-Version und der Zeitpunkt der letzten Feststellung.
   - **Korrigiert am 2026-11-18 (F94) – kein bloßes Aushandeln, keine Beschränkung auf eine Version:** Das Modul
     `paperless` **kennt und spricht alle API-Versionen ab Paperless 3.x** und stellt seine Funktionen mit jeder
     davon korrekt bereit. Grundlage ist eine **gepflegte Tabelle** im Modul: Paperless-Version → API-Version →
     was sich an den von kumero genutzten Aufrufen und Antworten unterscheidet. Für jede API-Version der Tabelle
     gibt es Tests gegen einen Nachbau, der genau diese Version spricht.
   - **Pflegeaufgabe:** Erscheint eine neue Paperless-Version, wird geprüft, ob sich die API-Version geändert hat,
     die Tabelle ergänzt, der Client angepasst und getestet – als eigene kumero-Version. Bis dahin arbeitet kumero
     gegen ein neueres Paperless mit der höchsten ihm bekannten API-Version (ältere Versionen bedient Paperless
     weiter, solange es sie nicht abkündigt) und zeigt den Hinweis „Paperless ist neuer als geprüft“. Paperless
     vor 3.0 wird mit klarer Meldung abgelehnt.
   - **Vor dem Bauen zu ermitteln:** welche API-Versionen die Ausgaben 3.0 bis heute sprechen und was sich dabei an
     den genutzten Aufrufen geändert hat – aus Änderungsprotokoll und API-Beschreibung von Paperless-ngx.
     Gesichert ist bisher nur: 3.4.2 meldet API-Version 10 und lehnt Version 5 ab.
   - **Feststellen:** beim Anlegen, bei jedem Entsperren und beim Verbindungstest; außerdem von selbst, sobald
     Paperless mit 406 antwortet (Paperless wurde aktualisiert) – dann neu aushandeln und den Aufruf einmal wiederholen.
   - Unterschiede zwischen API-Versionen liegen an einer Stelle im Client, nicht verstreut in den Seiten.
   - Verbindungstest und Zugangsseite zeigen beide Versionen; ein Wechsel steht im Protokoll und später im Livelog.
3. Die Adresse war mit Schrägstrich am Ende eingegeben (`https://ngx.5st.eu/`); das ist erlaubt und wird
   abgeschnitten – nicht die Ursache.

4. **Behoben in kumero 0.7.5 (Modul `paperless` 0.1.1, F94):** Der Client verlangt keine feste API-Version mehr.
   Er stellt fest, was Paperless meldet (`X-Version`, `X-Api-Version`), wählt die höchste API-Version aus der
   gepflegten Tabelle `module/paperless/versionen.json`, prüft sie mit einem Aufruf und merkt alles an der
   Verbindung. Bei 406 stellt er einmal neu fest und wiederholt den Aufruf. Der Verbindungstest zeigt beide
   Versionen; Absagen nennen den Grund (zu alt, keine bekannte API-Version, kein Paperless).
   - **Tabelle, Stand 2026-11-18:** Paperless-ngx 3.x bedient die API-Versionen **9 und 10** (Dokumentation von
     Paperless-ngx: „Supported API versions are currently 9 and 10“, ohne Angabe gilt 10). Die von kumero genutzten
     Aufrufe sind in beiden gleich; Version 9 liefert in Listen zusätzlich das Feld `all`, das kumero nicht liest.
   - **Geprüft:** gegen den Nachbau in den Fällen 3.4.2 (9, 10), nur 9, Update auf ein Paperless ohne die gemerkte
     Version, neueres Paperless mit API 11, Paperless ohne bekannte Version, Paperless 2.20. Am echten Paperless
     3.4.2 ist bisher nur der Aufruf mit `curl` bestätigt, nicht kumero selbst – das zeigt der nächste Lauf.
   - „Geprüft bis“ steht in der Tabelle auf 3.4; ein neueres Paperless bekommt im Verbindungstest den Hinweis
     „neuer als geprüft“, bis die Pflege nachgezogen ist.
   - Der Umzug des Zugangs ins Nutzerprofil (Nr. 1, F93) ist **nicht** Teil von 0.7.5.

## 12. Zuordnung: Felder aus Paperless → Buchungssatz (gebaut in 0.7.7 – Stand in Abschnitt 13)

Vorgabe des Verantwortlichen vom 2026-11-18 nach dem ersten Lauf mit echtem Paperless (die Verbindung steht, der
Arbeitsvorrat füllt sich): **Paperless stellt bereits viele Angaben für die Buchhaltung bereit** – als
benutzerdefinierte Felder am Dokument (z. B. „Erkannt: Belegart“, „Erkannt: Rechnungsnummer“, „Erkannt:
Rechnungsdatum“, „Erkannt: Rechnungssteller“, „Erkannt: Betrag netto“), dazu Tags, Korrespondent und Dokumenttyp.
**Das Zuordnen dieser Angaben gehört in das Modul `paperless`.** Gebraucht wird in den Paperless-Einstellungen eine
**Zuordnung von benutzerdefiniertem Feld zu Feld des Buchungssatzes.** 0.7.5 übernimmt bisher nur Titel,
Korrespondent, Erstelldatum und Dokumenttyp.

### 12.1 Was zugeordnet wird

**Quellen** (was Paperless je Dokument liefert):

| Quelle | Beispiele |
|---|---|
| benutzerdefinierte Felder | Text, Zahl, Datum, Geldbetrag (mit Währung), Auswahl, Ja/Nein, Link – alle, die das Konto des Nutzers sieht |
| feste Felder | Titel, Korrespondent, Dokumenttyp, Erstelldatum, Archivnummer |
| Tags | ein Tag gesetzt oder nicht (z. B. „Eingangsrechnung“, „bar bezahlt“) |

**Ziele** (Felder des Eintrags im Arbeitsvorrat, aus denen die Buchungsmaske vorbelegt wird – ARBEITSVORRAT.md
Abschnitt 2 und 6):

| Ziel | wird in der Maske zu |
|---|---|
| Betreff | Anzeige im Arbeitsvorrat |
| Art | Regel „Art → Geschäftsvorfall“ (Konto, Gegenkonto, Buchungsart) |
| Gegenpartei, Kennung der Gegenpartei | Buchungstext; Regel „Gegenpartei → Konto“ |
| Datum | Belegdatum |
| Betrag | Betrag (brutto, W10) |
| Richtung | Ausgabe oder Einnahme – tauscht Soll und Haben |
| Belegnummer | Belegnummer |
| Zahlweise, Zahltag | Buchungsdatum (bar = Belegdatum, unbar = Tag des Geldflusses) |
| nur anzeigen | steht neben der Maske zum Vergleich (z. B. Netto, Steuer, Fälligkeit, IBAN) |

Konto und Gegenkonto sind **kein** Ziel: Sie kommen aus den Regeln des Arbeitsvorrats, damit kein Feld aus
Paperless unbesehen ein Konto bestimmt.

### 12.2 Regeln der Zuordnung

- **Je Ziel eine geordnete Liste von Quellen**; es gilt die erste, die am Dokument einen Wert hat
  (z. B. Gegenpartei: erst „Erkannt: Rechnungssteller“, sonst der Korrespondent).
- **Tags** ordnen feste Werte zu: Tag „Eingangsrechnung“ → Richtung Ausgabe; Tag „bar“ → Zahlweise bar.
- **Auswahlfelder und Text mit festen Werten** bekommen eine Übersetzung der Werte (z. B. Belegart `kassenbon`
  → Art `kassenbon`; ohne Übersetzung wird der Wert in Kleinbuchstaben übernommen, wenn er als Art taugt).
- **Typen werden streng gelesen:** Geldbetrag als Dezimalzahl mit Währung (nur die Buchwährung wird als Betrag
  übernommen, anderes steht als Hinweis da), Datum als Datum. Was nicht passt, wird nicht geraten, sondern
  als Hinweis am Eintrag vermerkt.
- **Netto statt Brutto:** Ist nur ein Nettobetrag zugeordnet, wird er nicht als Betrag übernommen, sondern
  nur angezeigt, mit Hinweis – der Betrag der Buchung ist brutto (W10).
- Alle zugeordneten Felder, die kein Ziel im Buchungssatz haben, und auf Wunsch alle übrigen stehen als
  Angaben neben der Maske.
- **Vorschlag beim Einrichten:** kumero liest die benutzerdefinierten Felder des Paperless und schlägt für
  bekannte Namen eine Zuordnung vor (Rechnungsnummer → Belegnummer, Rechnungsdatum → Datum, Rechnungssteller →
  Gegenpartei, Belegart → Art, Betrag brutto → Betrag). Gespeichert wird erst, was bestätigt ist.
- **Probe:** Die Einstellungsseite zeigt an einem offenen Beleg, was aus der Zuordnung herauskäme, bevor sie gilt.

### 12.3 Wo die Zuordnung liegt

- In den **Paperless-Einstellungen** – mit F93 also im Nutzerprofil, beim jeweiligen Paperless.
- Felder und Tags gehören zu einem bestimmten Paperless; die Zuordnung gilt deshalb **je Paperless (Adresse)**.
- Weil die Einträge des Arbeitsvorrats dem Mandanten gehören (W8), schlage ich vor: **eine Zuordnung je
  Paperless und Mandant, für alle gleich** – sonst sähe derselbe Beleg je nach Nutzer anders aus. Pflegen
  darf, wer die Vorgabe pflegen darf (`paperless.vorgabe.pflegen`); alle anderen sehen sie.
- Ablage im Schema `paperless`, Schreiben über Funktionen mit Protokoll (Vorher/Nachher).
- Die Feldwerte selbst sind Belegangaben: Sie stehen nur am Eintrag in der Mandanten-Datenbank, nie in Logs.

### 12.4 Technik

- Paperless-ngx: `/api/custom_fields/` (Name, Datentyp, Auswahlwerte) und `custom_fields` am Dokument
  (`[{field, value}]`); Auswahlwerte tragen seit API-Version 7 eine eigene ID. Beides ist in die Tabelle der
  API-Versionen aufzunehmen (F94) und gegen 3.4.2 zu prüfen.
- Der Nachbau für die Tests bekommt benutzerdefinierte Felder mit synthetischen Werten.

### 12.5 Entscheidungen zur Freigabe

| # | Frage | Vorschlag |
|---|---|---|
| Z1 | Ort | Zuordnung im Modul `paperless`, in dessen Einstellungen (Vorgabe vom 2026-11-18). |
| Z2 | Ziele | **Entschieden am 2026-11-18:** Felder des Eintrags wie in 12.1. Konto und Gegenkonto kommen nicht aus Paperless-Feldern, sondern aus dem Arbeitsvorrat – dort zusätzlich als **Stapelvorbelegung** nach gesetztem Filter (ARBEITSVORRAT.md Abschnitt 13, X1). |
| Z3 | Geltung | **Entschieden am 2026-11-18 – wie immer:** Der **Mandant gibt vor**, der **Nutzer darf eigene Einstellungen haben**. Die Zuordnung des Mandanten (gepflegt mit `paperless.vorgabe.pflegen`) gilt, solange der Nutzer für dieses Paperless keine eigene hat; die eigene überschreibt je Ziel. Weil Einträge dem Mandanten gehören (W8), ist festzulegen, wessen Zuordnung einen Eintrag bildet – Es gilt die Zuordnung dessen, der den Beleg einliefert; der Eintrag vermerkt das (**zugestimmt am 2026-11-18**). |
| Z4 | Reihenfolge | je Ziel mehrere Quellen, die erste mit Wert gilt. |
| Z5 | Tags und Werte | Tags als feste Werte (Richtung, Zahlweise, Art); Übersetzung für Auswahl- und Textwerte. |
| Z6 | Betrag | **Entschieden am 2026-11-18:** Das ist eine Frage der korrekten Buchung. Liegen Netto und Steuerbeträge vor, ist die Aufgabe eine **Splitbuchung mit den Vorsteuer- bzw. Umsatzsteuerbeträgen**. Die Zuordnung bekommt deshalb die Ziele Netto, Steuerbetrag (je Satz) und Brutto; die Maske wird als Splitbuchung vorbelegt (ARBEITSVORRAT.md Abschnitt 13, X2). |
| Z7 | Übrige Felder | Zugeordnete ohne Ziel immer anzeigen; nicht zugeordnete auf Wunsch. |
| Z8 | Vorschlag und Probe | Zuordnung aus den Feldnamen vorschlagen; Probe an einem offenen Beleg. |
| Z9 | Verhältnis zu nopaper | **Entschieden am 2026-11-18:** Das Modul `nopaper` wird gebraucht – es ist nicht sicher, dass die Angaben aus einer Quelle jeweils vollständig sind. Die Module müssen dafür ihre Datensätze **deduplizieren** und **abgleichen, ob ein Datensatz bereits gebucht ist und keinen Storno hat** (ARBEITSVORRAT.md Abschnitt 13, X3 und X4). |
| Z10 | Bestehende Einträge | Ändert sich die Zuordnung, werden offene Einträge beim nächsten Abgleich neu gebildet; Einträge in Arbeit und gebuchte bleiben. |

## 13. Zuordnung – Stand der Umsetzung (kumero 0.7.7, Modul `paperless` 0.2.0)

**Gebaut:**

- Tabelle `paperless.zuordnung` je Paperless-Adresse: eine **Vorgabe des Mandanten** und je Nutzer eine **eigene
  Zuordnung**, die je Ziel überschreibt (Z3). Es gilt die Zuordnung dessen, der einliefert – also dessen, der den
  Arbeitsvorrat öffnet. Jede Änderung steht im Protokoll (`paperless.zuordnung_geaendert`).
- **Ziele:** Betreff, Art, Gegenpartei, Kennung der Gegenpartei, Belegdatum, Betrag (brutto), Netto, Steuerbetrag,
  Richtung, Belegnummer, Zahlweise, Zahltag – und „nur anzeigen“. **Konto und Gegenkonto sind kein Ziel** (Z2); sie
  werden im Arbeitsvorrat gewählt.
- **Quellen:** benutzerdefinierte Felder (über den Namen), feste Felder (Titel, Korrespondent, Dokumenttyp,
  Erstelldatum, Archivnummer), Tags (für Richtung und Zahlweise: „Tag X bedeutet Ausgabe“). Je Ziel in der Oberfläche
  zwei Quellen in Reihenfolge („zuerst aus“, „sonst aus“); die API nimmt bis zu 20.
- **Strenges Lesen:** Datum nur als Datum, Betrag nur in Euro und größer null, Auswahlfelder über die ID der Option.
  Was nicht passt, wird **nicht geraten**, sondern steht als Hinweis am Eintrag; die nächste Quelle kommt zum Zug.
  Ist kein Brutto zugeordnet, aber Netto und Steuer, wird der Betrag daraus **gerechnet** (mit Hinweis).
- **Vorschlag** aus den Feldnamen (z. B. „Erkannt: Rechnungsnummer“ → Belegnummer) per Knopf; gespeichert wird erst,
  was bestätigt ist.
- **Wirkung:** Offene Einträge werden beim nächsten Öffnen des Arbeitsvorrats neu gebildet. Einträge in Arbeit,
  gebuchte und verworfene bleiben, wie sie sind.
- Ohne jede Zuordnung gilt wie bisher: Titel → Betreff, Korrespondent → Gegenpartei, Erstelldatum → Belegdatum,
  Dokumenttyp → Art.

**Festlegungen aus der Umsetzung, zu bestätigen:** Die Zuordnung hängt an der Adresse des Paperless, nicht an der
einzelnen Verbindung. Felder werden über ihren **Namen** zugeordnet – wird ein Feld in Paperless umbenannt, greift die
Zuordnung nicht mehr (kein Fehler, das Ziel bleibt leer). Die Art wird als Stichwort in Kleinbuchstaben übernommen.

**Nicht gebaut:** mehr als zwei Quellen je Ziel in der Oberfläche; Tags als Quelle der Art; eine Vorschau „so sähe
dieser Beleg aus“; der Umzug der Einstellungen ins Nutzerprofil (F93). **Nicht am echten Paperless gelaufen** – nur
gegen den Nachbau mit synthetischen Feldern.

**Nachtrag 2026-11-18 (0.7.8/0.7.10):** Paperless-ngx 3.4.2 nennt am Dokument die **SHA-256** des Originals, nicht mehr die MD5 (bestätigt am Referenzbetrieb). kumero vergleicht die Angabe seit 0.7.8 mit der passenden Summe; gespeichert werden weiter MD5 und SHA-256 (F10). Abschnitt 6 Schritt 3 ist entsprechend zu lesen.

**Nachtrag 2026-11-18 (0.7.18, Modul 0.3.0, F110): Paperless als Belegablage.** kumero hält kein Dokument dauerhaft. Ein Modul, das ein Original vorläufig führt (zuerst `import` mit dem PDF einer E-Rechnung), übergibt es nach der Buchung über die Schnittstelle `Kumero\Api\V1\Belegablage`. Paperless nimmt es mit `POST /api/documents/post_document/` an (Formular: `document`, `title`, `created`), mit dem entsperrten Zugang des Nutzers. Ob Paperless das Dokument führt, stellt kumero über die Dokumentliste fest (`checksum__iexact`, `title__iexact`, neueste zuerst) und prüft jeden Treffer an `original_checksum` – ein Filter, den eine Version nicht kennt, schadet deshalb nicht; `/api/tasks/` wird nicht genutzt. Führt Paperless die Datei schon, wird nichts gesendet. Gesetzt werden nur Titel und Belegdatum. **Nicht am echten Paperless gelaufen.**

**Nachtrag 2026-11-18 (0.7.20, Modul 0.4.0, F112): E-Rechnungen als XML.** Paperless-ngx nimmt von Haus aus kein XML an (`File type text/xml not supported`). Seit Version 3 lassen sich Parser als Plugin einhängen; das Plugin `paperless-ngx-erechnung` (github.com/bitbetterde/paperless-ngx-erechnung) meldet sich für `application/xml` und `text/xml` an, erkennt eine XRechnung am Wurzelelement, behält das XML als Original und erzeugt eine lesbare Archivfassung. **Entscheidung des Verantwortlichen: Das Plugin gehört zum Referenzbetrieb – Paperless bleibt das System der Dokumente.** In kumero stellt der Mandant unter „Paperless – mein Zugang“ ein, dass sein Paperless XML annimmt (`PUT /paperless/ablage`, Tabelle `paperless.ablage`, Ereignis `paperless.ablage_geaendert`, Recht `paperless.vorgabe.pflegen`). Erst dann meldet das Modul über `belegAblageNimmt('application/xml')` die Annahme, und das Modul `import` lagert eine XRechnung zwischen. Gesendet wird das XML unverändert, als `application/xml` mit der Endung `.xml`. Lehnt Paperless es mit HTTP 400 ab, meldet das Modul `409 paperless.xml_abgelehnt`; das Original bleibt in kumero liegen. **Nicht am echten Paperless mit Plugin gelaufen.** Zu bestätigen am Referenzbetrieb: Annahme über `post_document`, `original_checksum` = Prüfsumme des XML, Titel und Belegdatum; das Plugin ist jung (Entwicklungsstand `0.1.x`) – seine Version gehört zu den Angaben des Referenzbetriebs.

**Nachtrag 2026-11-20 (0.7.34, Modul 0.5.0, F127): Sidecar von nopaper.** Trägt ein Dokument den Sidecar von nopaper in einem benutzerdefinierten Feld, liest ihn der Abgleich mit; er geht der Feldzuordnung aus Abschnitt 12 vor, die Felder sind der Rückfall. Negative Beträge werden mit umgekehrter Richtung vorbelegt statt verworfen. Beschreibung und Entscheidungen R1–R11: BELEGEINGANG.md Abschnitt 9.
