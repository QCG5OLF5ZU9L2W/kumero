# SPIELWIESE.md – kumero offen und ohne Anmeldung zum Ausprobieren (Entwurf 3, zur Freigabe)

Stand: 2026-11-22. Bezug: F59 (Testbetrieb), F78 O1 (im Testbetrieb darf die Freigabe per Passkey fehlen), F126 (ein
Testbuch wird nie produktiv), F170–F172 (`installer.sh`), F175 (echte Daten nur in einer Produktionsumgebung), F183.
**Status: Entwurf zur Freigabe, nichts gebaut.** Entschieden sind SW1–SW10, SW14–SW21 und SW23 (Verantwortlicher, 2026-11-22); zu
entscheiden SW11–SW13, SW22 und SW24. **Entwurf 2 ersetzt den Schalter in kumero aus Entwurf 1 durch einen Pförtner
außerhalb von kumero (SW17).**

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

## 3. Der Weg: nichts an kumero, ein Pförtner daneben (SW17)

Der Verantwortliche zu Entwurf 1 („ein Schalter in der Konfiguration und die Sitzung ohne Passkey“): **„Der ist gefährlich,
eine winzige Änderung, alles liegt offen. Das Installationsskript muss das sauber und getrennt regeln“ – und: „wir fummeln
nicht an kumero selber rum.“** Deshalb:

- **kumero bleibt, wie es ist.** Es gibt darin keinen Schalter, keine Sitzung ohne Passkey, keinen Modus. Eine Maschine
  des Betriebs lässt sich durch keine Einstellung öffnen, weil es das, was öffnet, dort nicht gibt.
- **Der Pförtner** ist ein kleines eigenes Programm, das nur `install/spielwiese.sh` auf die Maschine bringt – außerhalb
  des Verzeichnisses von kumero und nicht im Release, das der Installer des Betriebs einspielt. Er zeigt die
  **vorgeschaltete Seite** (SW7). Erst der Klick „Verstanden, weiter“ lässt ihn für den Besucher tun, was sonst ein Mensch
  mit seinem Passkey tut: **Er meldet die Spiel-Identität bei kumero an – über dieselbe Schnittstelle wie jeder Browser,
  mit einem Passkey, der als Datei auf dieser Maschine liegt** – und reicht dem Besucher die Sitzung weiter.
- **Paperless (SW9):** Derselbe Klick entsperrt den Paperless-Zugang der Spiel-Identität mit ihrer Passphrase über den
  vorhandenen Aufruf des Moduls `paperless`. Die Passphrase liegt beim Pförtner, nicht in kumero.
- **Neutraler Name:** Der Mandant heißt neutral, etwa „Demo“ (14:19) – nicht „Spielwiese“ und ohne Warntext im Namen.
  Dass alles öffentlich ist und gelöscht wird, sagt die vorgeschaltete Seite. Das Buch ist ein Testbuch und als solches
  gekennzeichnet.
- **Was `install/spielwiese.sh` tut** (SW16): kumero installieren wie sonst auch; Mandant, Testbuch und alle Module;
  **eine** Spiel-Identität mit jeder Rolle und dem Amt des Administrators (SW8); deren Passkey erzeugen und beim Pförtner
  ablegen; Paperless mit dem Spiel-Nutzer bereitstellen und verbinden (SW3); den Pförtner und seine nginx-Site einrichten
  (Startseite ist die vorgeschaltete Seite, Grenze für Hochgeladenes 5 MB, SW14; `X-Robots-Tag: noindex`); die Firewall
  setzen (SW4). Danach nimmt der Betreiber den Snapshot (SW2).

Geprüft am Stand von 0.7.79, nicht ausprobiert: Sitzungen einer Identität bestehen nebeneinander (eine Anmeldung beendet
keine andere), die Anmeldung braucht nur die Schnittstelle (Challenge liegt am Server), und das Modul `paperless` hat
einen Aufruf zum Entsperren mit Passphrase. Einen Passkey als Programm gibt es schon für die Tests
(`tests/Hilfe/Authentifikator.php`); der Pförtner bekommt seinen eigenen, nicht den aus den Tests.

**Abgelaufene Sitzung – das fängt der Pförtner ab (SW20, entschieden 14:19).** Ohne ihn zeigte kumero nach Ablauf der
Sitzung (30 Minuten ohne Eingabe, höchstens 12 Stunden) oder nach dem Rücksetzen seine gewöhnliche Anmeldeseite mit
Passkey, und der Besucher käme nicht weiter. Ausführung als Vorschlag in SW22.

## 4. Zu entscheiden

| # | Frage | Vorschlag |
|---|---|---|
| SW8 | Eine Identität mit allen Rollen – auch Administrator? | **Entschieden: ja – „wie soll einer testen, wenn er nicht alles kann“.** Ausführung: eine Spiel-Identität mit jeder Rolle jedes Moduls **und** dem Amt des Administrators. Im Betrieb sind das getrennte Identitäten (F43); die Spielwiese zeigt alles auf einmal, die vorgeschaltete Seite sagt das. |
| SW9 | Paperless-Zugang ohne Passphrase | **Entschieden: unkritisch; die vorgeschaltete Seite übernimmt die Eingabe der Passphrase – nicht kumero** (SW17). Ursprünglicher Vorschlag, damit überholt: Das Token des Spiel-Nutzers liegt in kumero verschlüsselt und wird je Sitzung mit einer Passphrase entsperrt (PAPERLESS.md L3a). Für die Spielwiese steht diese Passphrase in der Konfiguration der Maschine und entsperrt beim Anlegen der Sitzung von selbst. Nur dort – es ist das Geheimnis eines Spiel-Paperless. |
| SW10 | Ausgangsbestand | **Entschieden (14:19): jeweils etwa 50 Buchungen in einem SKR 03.** „Jeweils“ gelesen als: je Buch der Demo. **Zu klären – SW23 und SW24.** |
| SW11 | Vorgeschaltete Seite | In kumero, nicht davor: Text aus der Konfiguration (Betreiber, Hinweis, Rücksetzen), ein Knopf. Impressum und Datenschutzhinweis verlinkt der Betreiber dort. |
| SW12 | Hinweis auf das Rücksetzen | Fester Text aus der Konfiguration („alle vier Stunden: 0, 4, 8, 12, 16, 20 Uhr“). kumero rechnet keine Restzeit – es kennt den Snapshot nicht. |
| SW13 | Was die Spiel-Identität nicht kann | Nichts wird eigens gesperrt. Von selbst nicht möglich: alles des System-Verwalters (Mandanten, eigene Domain) – die Spiel-Identität ist keiner. Zugänge ausstellen, Module schalten, Paperless umstellen bleiben möglich und sind nach dem Rücksetzen wieder weg. |
| SW14 | Hochladen großer Dateien | **Einverstanden.** Grenze des Webservers auf der Spielwiese klein stellen (z. B. 5 MB) – eine Zeile in der nginx-Vorlage des Modus, kein Code. |
| SW15 | Verhältnis zum Installer des Betriebs | **Entschieden: Im regulären Installer gibt es keinen Weg zur Spielwiese.** Kein Modus, keine Frage, kein gemeinsamer Schalter. Ob `installer.sh --test` (lokal, Tunnel, Passkeys; F172) bleibt, ist damit nicht gesagt – SW21. |
| SW17 | Wo die Öffnung liegt | **Entschieden: nicht in kumero.** Kein Schalter in der Konfiguration, keine Sitzung ohne Passkey im Kern; das Installationsskript der Spielwiese regelt es getrennt (Abschnitt 3). |
| SW18 | Wo der Pförtner liegt | **Entschieden: Der Pförtner bleibt getrennt vom Release.** Sein Quelltext steht im Repository unter `install/spielwiese/`, `release-bauen.sh` lässt das Verzeichnis aus, `spielwiese.sh` holt es eigens; auf einer Maschine des Betriebs kommt er nicht einmal als Datei an. Läuft unter `/opt/kumero-spielwiese` in PHP mit eigenem Systembenutzer (Vorschlag). |
| SW19 | Passkey der Spiel-Identität | Wird bei der Installation auf der Maschine erzeugt und über den gewöhnlichen Registrierungslink eingerichtet. Er ist ein Geheimnis dieser Spielwiese und sonst nichts wert; er liegt im Snapshot. |
| SW20 | Abgelaufene Sitzung | **Entschieden: Das muss der Pförtner abfangen.** Ausführung: SW22. |
| SW21 | Test-Installation des Installers (F172) | **Entschieden: Der Tunnel kann bleiben – „schadet nicht, nutzt nicht“.** `installer.sh --test` bleibt, wie es ist. |
| SW22 | Wie der Pförtner die abgelaufene Sitzung abfängt (zu SW20) | Der Pförtner steht für alle Aufrufe der Schnittstelle **vor** kumero (der Webserver der Spielwiese reicht `/api/…`, `/m/…` und `/b/…` an ihn, Dateien der Oberfläche liefert nginx direkt). Er gibt jede Anfrage unverändert weiter; antwortet kumero mit „Anmeldung erforderlich“, meldet er die Spiel-Identität neu an, entsperrt Paperless und wiederholt die Anfrage einmal. Der Besucher sieht nie eine Anmeldeseite – auch nicht nach dem Rücksetzen. **Bedingung:** nur für Besucher, die die vorgeschaltete Seite bestätigt haben (eigenes Merkzeichen des Pförtners im Browser); alle anderen leitet er dorthin. Dazu längere Sitzungen in der Konfiguration dieser Maschine (`sitzung` in `kumero.php`, vorhandene Einstellung), damit das selten nötig ist. |
| SW23 | Kontenrahmen der Spielwiese (zu SW10) | **Entschieden (14:25): kein SKR 03.** Dem Hinweis auf F75 zugestimmt – „dann machen wir was anderes: Wir bauen einen Kontenrahmen aus der EÜR des BMF; behördliche Informationen haben kein Urheberrecht“. Der Bestand der Spielwiese bucht auf diesen **EÜR-Kontenrahmen** (F184, KONTENRAHMEN.md Abschnitt 12). SW10 gilt sinngemäß: jeweils etwa 50 Buchungen darin. |
| SW24 | Was „jeweils“ meint und was neben den Buchungen dazugehört (zu SW10) | Vorschlag: ein Buch (EÜR, EÜR-Kontenrahmen nach SW23) mit etwa 50 festgeschriebenen Buchungen über ein Geschäftsjahr – Bank, Kasse, Eingangs- und Ausgangsrechnungen, Vorsteuer und Umsatzsteuer, zwei Splitbuchungen, ein Storno; dazu rund zehn offene Einträge im Arbeitsvorrat, einige Belege in Paperless, je eine Handvoll Artikel, Anlagegüter und erfundene Personen. Alles aus einer Steuerdatei im Verzeichnis des Pförtners, erzeugt über die gewöhnliche Schnittstelle. |

## 5. Was der Betreiber außerhalb von kumero verantwortet

Snapshot und Zeitplan in Proxmox; Firewall-Regeln (ausgehend nur, was die Maschine selbst braucht – Paketquellen fallen
sonst mit weg); DNS und Zertifikat; Impressum und Datenschutzhinweis; das Reagieren auf Missbrauch. **Fremde können dort
Text und Dateien ablegen, die bis zum nächsten Rücksetzen jeder sieht** – das ist mit SW6 und SW7 bewusst so.

## 6. Prüfbar

Im Chat: der Pförtner gegen den Kern (Anmeldung mit seinem Passkey, Weiterreichen der Sitzung, Entsperren von Paperless am
Nachbau); ein eigener Test für `install/spielwiese.sh` nach dem Muster von `install/installer-test.sh`;
ein Browserlauf vom Aufruf über die vorgeschaltete Seite bis zur ersten Buchung. Nicht im Chat: Debian, UFW, Proxmox,
Paperless im Container.
