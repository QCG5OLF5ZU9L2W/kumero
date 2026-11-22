# SPIELWIESE.md – kumero offen und ohne Anmeldung zum Ausprobieren (Entwurf 1, zur Freigabe)

Stand: 2026-11-22. Bezug: F59 (Testbetrieb), F78 O1 (im Testbetrieb darf die Freigabe per Passkey fehlen), F126 (ein
Testbuch wird nie produktiv), F170–F172 (`installer.sh`), F175 (echte Daten nur in einer Produktionsumgebung), F183.
**Status: Entwurf zur Freigabe, nichts gebaut.** Entschieden sind SW1–SW7 und SW16 (Verantwortlicher, 2026-11-22); zu entscheiden
SW8–SW15.

## 1. Was die Spielwiese ist

Eine eigene Maschine, die offen im Netz steht: Wer die Adresse aufruft, arbeitet ohne Anmeldung in kumero – buchen,
Belege ansehen, Lager, Personal. Alle Besucher teilen einen Bestand. In festen Abständen ist alles wieder, wie es war.
Anlass: Wer ausprobiert, ist kein Software-Mensch (F172); ein SSH-Tunnel und ein Passkey sind dafür zu viel.

## 2. Entschieden (2026-11-22)

| # | Entscheidung |
|---|---|
| SW1 | **Die Maschine wird nur als Spielwiese installiert.** Sie führt nie echte Daten und wird nie etwas anderes (F175). |
| SW2 | **Zurückgesetzt wird von außen: Snapshot in Proxmox, alle vier Stunden** – „dann ist immer alles weg“. kumero setzt nichts zurück und kennt den Mechanismus nicht. |
| SW3 | **Paperless wird mit einem Spiel-Nutzer gleich mit bereitgestellt** und ist für die Spiel-Identität verbunden. |
| SW4 | **Verbindungen zu weiteren Servern unterbindet die Firewall der Maschine (UFW)** – pragmatisch, nicht kumero. In kumero wird dafür nichts gesperrt. |
| SW5 | **Die Rollen der Spiel-Identität vergibt das Installationsskript.** Eine Auswahl der Rolle in der Oberfläche gibt es nicht. |
| SW6 | **Missbrauch: Darauf ist zu reagieren, wenn er auftritt.** kumero baut keine Begrenzung je Aufrufer. |
| SW7 | **Dass alle Eingaben öffentlich sind, sagt eine vorgeschaltete Seite.** |
| SW16 | **Die Spielwiese bekommt ein eigenes Installationsskript** (14:04) – getrennt von `installer.sh`, kein Modus darin. So kann kein Schalter und keine Antwort im Installer des Betriebs versehentlich eine Spielwiese ergeben, und umgekehrt. |

## 3. Was dafür in kumero zu bauen ist

- **Schalter der Installation** in `/etc/kumero/kumero.php` (`'spielwiese' => [...]`): Kennung des Mandanten, ID der
  Spiel-Identität, Text zum Rücksetzen. Nur root setzt ihn; in der Oberfläche gibt es ihn nicht.
- **Sitzung ohne Passkey:** Ist der Schalter gesetzt, legt ein eigener Aufruf eine Sitzung für die Spiel-Identität an –
  mit derselben Funktion wie die Anmeldung, nur ohne Passkey davor. Alles Weitere läuft wie mit einer Anmeldung: Rechte
  aus Rollen, Protokoll, Freigabe ohne Passkey (Testbetrieb).
- **Vorgeschaltete Seite** (SW7): Statt der Anmeldeseite zeigt kumero den Hinweis; erst der Klick „Verstanden, weiter“
  legt die Sitzung an.
- **Dauerhafte Kennzeichnung** im Kopf: „Spielwiese – alles hier ist öffentlich und wird regelmäßig gelöscht“.
- **Riegel:** Der Schalter wirkt nur, wenn die Maschine als Spielwiese gekennzeichnet ist (`/etc/kumero/spielwiese`,
  vom Installer angelegt) **und** jedes Buch der Installation ein Testbuch ist; sonst startet kumero nicht in diesem
  Modus und sagt warum. Der Selbsttest meldet den Modus.
- **Eigenes Skript `install/spielwiese.sh`** (SW16), als einzelne Datei in Bash und wie `installer.sh` mit `curl` zu
  holen; es ruft `install.sh`, `aktualisieren.sh` und `bin/kumero` auf und teilt sich mit `installer.sh` keinen Code,
  den dieser für den Betrieb braucht: installiert, legt Mandant, Testbuch, Spiel-Identität mit ihren Rollen und alle Module
  an, stellt Paperless mit dem Spiel-Nutzer bereit und verbindet es, richtet die Firewall ein, setzt Schalter und
  Kennzeichen. Danach nimmt der Betreiber den Snapshot.
- **Suchmaschinen:** Die Antworten tragen `X-Robots-Tag: noindex`.

Nicht nötig: Rollenwahl, Sperren einzelner Funktionen, Rücksetzen, Begrenzung je Aufrufer (SW2, SW4, SW5, SW6).

## 4. Zu entscheiden

| # | Frage | Vorschlag |
|---|---|---|
| SW8 | Eine Identität mit allen Rollen – auch Administrator? | Ja: eine Spiel-Identität mit jeder Rolle jedes Moduls **und** dem Amt des Administrators. Im Betrieb sind das getrennte Identitäten (F43); die Spielwiese zeigt alles auf einmal, die vorgeschaltete Seite sagt das. |
| SW9 | Paperless-Zugang ohne Passphrase | Das Token des Spiel-Nutzers liegt in kumero verschlüsselt und wird je Sitzung mit einer Passphrase entsperrt (PAPERLESS.md L3a). Für die Spielwiese steht diese Passphrase in der Konfiguration der Maschine und entsperrt beim Anlegen der Sitzung von selbst. Nur dort – es ist das Geheimnis eines Spiel-Paperless. |
| SW10 | Ausgangsbestand | Der Installer legt einen kleinen erfundenen Bestand an (Kontenrahmen der Referenz, einige Buchungen, Artikel mit Chargen, erfundene Personen, zwei Musterbelege in Paperless). Mehr pflegt der Betreiber von Hand ein, bevor er den Snapshot nimmt. |
| SW11 | Vorgeschaltete Seite | In kumero, nicht davor: Text aus der Konfiguration (Betreiber, Hinweis, Rücksetzen), ein Knopf. Impressum und Datenschutzhinweis verlinkt der Betreiber dort. |
| SW12 | Hinweis auf das Rücksetzen | Fester Text aus der Konfiguration („alle vier Stunden: 0, 4, 8, 12, 16, 20 Uhr“). kumero rechnet keine Restzeit – es kennt den Snapshot nicht. |
| SW13 | Was die Spiel-Identität nicht kann | Nichts wird eigens gesperrt. Von selbst nicht möglich: alles des System-Verwalters (Mandanten, eigene Domain) – die Spiel-Identität ist keiner. Zugänge ausstellen, Module schalten, Paperless umstellen bleiben möglich und sind nach dem Rücksetzen wieder weg. |
| SW14 | Hochladen großer Dateien | Grenze des Webservers auf der Spielwiese klein stellen (z. B. 5 MB) – eine Zeile in der nginx-Vorlage des Modus, kein Code. |
| SW15 | Verhältnis zur Test-Installation (F172) | Zu entscheiden: bleibt `installer.sh --test` (lokal, Tunnel, Passkeys) daneben bestehen, oder entfällt er, weil es die Spielwiese mit eigenem Skript gibt (SW16)? |

## 5. Was der Betreiber außerhalb von kumero verantwortet

Snapshot und Zeitplan in Proxmox; Firewall-Regeln (ausgehend nur, was die Maschine selbst braucht – Paketquellen fallen
sonst mit weg); DNS und Zertifikat; Impressum und Datenschutzhinweis; das Reagieren auf Missbrauch. **Fremde können dort
Text und Dateien ablegen, die bis zum nächsten Rücksetzen jeder sieht** – das ist mit SW6 und SW7 bewusst so.

## 6. Prüfbar

Im Chat: PHP-Tests für Schalter, Riegel und Sitzung ohne Passkey; ein eigener Test für `install/spielwiese.sh` nach dem Muster von `install/installer-test.sh`;
ein Browserlauf vom Aufruf über die vorgeschaltete Seite bis zur ersten Buchung. Nicht im Chat: Debian, UFW, Proxmox,
Paperless im Container.
