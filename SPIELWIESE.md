# SPIELWIESE.md – kumero offen und ohne Anmeldung zum Ausprobieren (gebaut mit 0.7.81)

Stand: 2026-11-22. Bezug: F59 (Testbetrieb), F78 O1 (im Testbetrieb darf die Freigabe per Passkey fehlen), F126 (ein
Testbuch wird nie produktiv), F170–F172 (`installer.sh`), F175 (echte Daten nur in einer Produktionsumgebung), F183.
**Status: gebaut mit 0.7.81 (F188, Abschnitt 7) – nie auf einer echten Maschine gelaufen.** Entschieden sind SW1–SW10, SW14–SW21 und SW23 (Verantwortlicher, 2026-11-22); zu
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
| SW11 | Vorgeschaltete Seite | **Entschieden (14:31, F185): Der Pförtner zeigt sie, nicht kumero** – „die rechtlichen Punkte zeigt der Pförtner, dann brauchen wir kumero nicht belasten“. Dort stehen Betreiber, Impressum, Datenschutzhinweis, „alle Eingaben sind öffentlich“, die Quelle des Kontenrahmens und der Knopf zum Eintreten. kumero bekommt dafür keine Seite und keinen Text in der Konfiguration. |
| SW12 | Hinweis auf das Rücksetzen | **Entschieden (14:31, F185): ebenfalls am Pförtner** – fester Text („alle vier Stunden: 0, 4, 8, 12, 16, 20 Uhr“). kumero kennt den Snapshot nicht und zeigt nichts dazu. |
| SW13 | Was die Spiel-Identität nicht kann | **Entschieden (14:42, F186): Nichts wird gesperrt** – „Warum sollten wir etwas sperren? Wir machen Open Source.“ Von selbst nicht möglich bleibt nur, was dem System-Verwalter gehört; alles andere ist nach dem Rücksetzen wieder weg. |
| SW14 | Hochladen großer Dateien | **Einverstanden.** Grenze des Webservers auf der Spielwiese klein stellen (z. B. 5 MB) – eine Zeile in der nginx-Vorlage des Modus, kein Code. |
| SW15 | Verhältnis zum Installer des Betriebs | **Entschieden: Im regulären Installer gibt es keinen Weg zur Spielwiese.** Kein Modus, keine Frage, kein gemeinsamer Schalter. Ob `installer.sh --test` (lokal, Tunnel, Passkeys; F172) bleibt, ist damit nicht gesagt – SW21. |
| SW17 | Wo die Öffnung liegt | **Entschieden: nicht in kumero.** Kein Schalter in der Konfiguration, keine Sitzung ohne Passkey im Kern; das Installationsskript der Spielwiese regelt es getrennt (Abschnitt 3). |
| SW18 | Wo der Pförtner liegt | **Entschieden: Der Pförtner bleibt getrennt vom Release.** Sein Quelltext steht im Repository unter `install/spielwiese/`, `release-bauen.sh` lässt das Verzeichnis aus, `spielwiese.sh` holt es eigens; auf einer Maschine des Betriebs kommt er nicht einmal als Datei an. Läuft unter `/opt/kumero-spielwiese` in PHP mit eigenem Systembenutzer (Vorschlag). |
| SW19 | Passkey der Spiel-Identität | Wird bei der Installation auf der Maschine erzeugt und über den gewöhnlichen Registrierungslink eingerichtet. Er ist ein Geheimnis dieser Spielwiese und sonst nichts wert; er liegt im Snapshot. |
| SW20 | Abgelaufene Sitzung | **Entschieden: Das muss der Pförtner abfangen.** Ausführung: SW22. |
| SW21 | Test-Installation des Installers (F172) | **Entschieden: Der Tunnel kann bleiben – „schadet nicht, nutzt nicht“.** `installer.sh --test` bleibt, wie es ist. |
| SW22 | Wie der Pförtner die abgelaufene Sitzung abfängt (zu SW20) | **Entschieden (14:42, F186): „war besprochen, der Pförtner meldet sich neu an“.** Der Pförtner steht für alle Aufrufe der Schnittstelle **vor** kumero (der Webserver der Spielwiese reicht `/api/…`, `/m/…` und `/b/…` an ihn, Dateien der Oberfläche liefert nginx direkt). Er gibt jede Anfrage unverändert weiter; antwortet kumero mit „Anmeldung erforderlich“, meldet er die Spiel-Identität neu an, entsperrt Paperless und wiederholt die Anfrage einmal. Der Besucher sieht nie eine Anmeldeseite – auch nicht nach dem Rücksetzen. **Bedingung:** nur für Besucher, die die vorgeschaltete Seite bestätigt haben (eigenes Merkzeichen des Pförtners im Browser); alle anderen leitet er dorthin. Dazu längere Sitzungen in der Konfiguration dieser Maschine (`sitzung` in `kumero.php`, vorhandene Einstellung), damit das selten nötig ist. |
| SW23 | Kontenrahmen der Spielwiese (zu SW10) | **Entschieden (14:25): kein SKR 03.** Dem Hinweis auf F75 zugestimmt – „dann machen wir was anderes: Wir bauen einen Kontenrahmen aus der EÜR des BMF; behördliche Informationen haben kein Urheberrecht“. Der Bestand der Spielwiese bucht auf diesen **EÜR-Kontenrahmen** (F184, KONTENRAHMEN.md Abschnitt 12). SW10 gilt sinngemäß: jeweils etwa 50 Buchungen darin. |
| SW24 | Was „jeweils“ meint und was neben den Buchungen dazugehört (zu SW10) | **Inhalt entschieden (14:31, F185): frei erfunden – „nimm einen Maler oder einen Anwalt und erfinde was“.** Gewählt: ein Malerbetrieb (Abschnitt 4a). **Annahme, nicht bestätigt:** „jeweils“ = ein Buch der Demo mit etwa 50 Buchungen; Arbeitsvorrat, Belege in Paperless und Lager wie im Vorschlag zuvor. |

## 4a. Der erfundene Betrieb (SW24, F185; nicht gebaut)

**Malerbetrieb Farbenfroh, Inhaberin Mara Muster, Musterstadt** – erfunden, erkennbar Muster: keine echte Firma, keine
echten Kunden, Lieferanten oder Belege (F75 Nr. 9). Ein Maler statt eines Anwalts, weil er mehr Zeilen der Anlage EÜR
berührt: Material, Fremdleistung, Personal, Fahrzeug, Werkstatt. Regelbesteuert mit 19 %; Kunden sind Privatleute und eine
Hausverwaltung – keine Leistungen an andere Bauunternehmer, also kein § 13b UStG im Bestand.

Ein Buch (EÜR, EÜR-Kontenrahmen), Geschäftsjahr = laufendes Kalenderjahr, erstes Quartal, alle Buchungen festgeschrieben:

| Gruppe | Anzahl | Was |
|---|---|---|
| Eröffnung | 2 | Einlage auf das Bankkonto, Wechselgeld in die Kasse |
| Ausgangsrechnungen | 15 | Anstrich, Tapezieren, Fassade, Lackierarbeiten – zwölf über die Bank bezahlt, drei bar |
| Material | 8 | Farben, Lacke, Tapeten, Abdeckmaterial beim Großhandel |
| Fremdleistung | 2 | Gerüstbau |
| Personal | 6 | Lohn eines Gesellen und Sozialabgaben, drei Monate |
| Werkstatt und Lager | 3 | Miete |
| Fahrzeug | 5 | Leasing des Transporters, Tanken, Versicherung, Kfz-Steuer |
| Laufendes | 6 | Telefon, Steuerberatung, Betriebshaftpflicht, Anzeige im Wochenblatt, Entsorgung von Farbresten, Pinsel und Kleinwerkzeug |
| Anlagen | 2 | Farbspritzgerät (Anlagegut), Leiter (geringwertiges Wirtschaftsgut) |
| Umsatzsteuer | 2 | Vorauszahlungen an das Finanzamt |
| Besonderes | 5 | Bewirtung als Splitbuchung (abziehbar/nicht abziehbar), Geschenk an einen Kunden, zwei Privatentnahmen, ein Storno mit Neubuchung |

Zusammen 56 – „etwa 50“. Dazu im Arbeitsvorrat rund zehn offene Bankumsätze des zweiten Quartals, in Paperless einige
selbst erzeugte Muster-Belege dazu, im Lager ein Dutzend Artikel (Farben mit Charge und Verfallsdatum). Beträge, Namen und
Texte legt das Skript der Spielwiese an; sie stehen als Datei beim Pförtner, nicht im Release (SW18).

## 5. Was der Betreiber außerhalb von kumero verantwortet

Snapshot und Zeitplan in Proxmox; Firewall-Regeln (ausgehend nur, was die Maschine selbst braucht – Paketquellen fallen
sonst mit weg); DNS und Zertifikat; Impressum und Datenschutzhinweis; das Reagieren auf Missbrauch. **Fremde können dort
Text und Dateien ablegen, die bis zum nächsten Rücksetzen jeder sieht** – das ist mit SW6 und SW7 bewusst so.

## 6. Prüfbar

Im Chat: der Pförtner gegen den Kern (Anmeldung mit seinem Passkey, Weiterreichen der Sitzung, Entsperren von Paperless am
Nachbau); ein eigener Test für `install/spielwiese.sh` nach dem Muster von `install/installer-test.sh`;
ein Browserlauf vom Aufruf über die vorgeschaltete Seite bis zur ersten Buchung. Nicht im Chat: Debian, UFW, Proxmox,
Paperless im Container.

## 7. Gebaut mit 0.7.81 (F188) – Festlegungen aus der Umsetzung, zu bestätigen

| Datei | Aufgabe |
|---|---|
| `install/spielwiese.sh` | macht aus einer frischen Installation eine Spielwiese (als root, aus einem Klon des Repositorys) |
| `install/spielwiese/pfoertner.php`, `lib.php`, `seite.css` | der Pförtner: vorgeschaltete Seite, Eintreten, Durchreichen, Neuanmeldung; der Passkey als Datei |
| `install/spielwiese/einrichten.php`, `bestand.php` | Mandant, Buch, Module, Rollen, Passkey, Kontenrahmen, Bestand – über Kommandozeile und Schnittstelle von kumero |
| `install/spielwiese/nginx-spielwiese.conf`, `php-fpm-spielwiese.conf`, `spielwiese.conf` | Vorlagen für Webserver, Pool und die Angaben des Betreibers |
| `install/spielwiese-test.sh`, `tests/spielwiese/lauf.php`, `browser.mjs` | Tests |

**Ablauf:** Maschine mit `installer.sh` einrichten (öffentliche https-Adresse, auf Wunsch Paperless, **kein Mandant**) →
`bash install/spielwiese.sh` legt `/etc/kumero-spielwiese/spielwiese.conf` an → Betreiber, Impressum, Datenschutz, Land
eintragen → `bash install/spielwiese.sh` erneut, „SPIELWIESE“ eintippen → im Browser prüfen → Snapshot in Proxmox.

| # | Festlegung |
|---|---|
| SP1 | **`spielwiese.sh` installiert kumero nicht selbst**, sondern setzt auf einer frischen Installation durch `installer.sh` auf. Es weigert sich, wenn es schon einen Mandanten gibt oder die Adresse nicht https ist (Tunnel der Test-Installation). So entsteht aus einer Maschine mit Daten nie eine Spielwiese. |
| SP2 | **Jeder Besucher bekommt eine eigene Sitzung** der einen Spiel-Identität; „Abmelden“ in kumero meldet nur ihn ab, und der nächste Aufruf meldet ihn wieder an. |
| SP3 | **Die Spiel-Identität ist der erste Administrator des Mandanten „Demo“** („Mara Muster“); jede Rolle des Buchs und des Mandanten weist das Skript über die Funktionen der Datenbank zu – die Kommandozeile hat für Rollen am Mandanten keinen Befehl. |
| SP4 | **Passkey mit Zähler 0** (wie ein Authentifikator ohne Zähler), damit viele Anmeldungen nebeneinander gehen. Die Datei gehört dem Benutzer `kumero-spielwiese`, Modus 0600, und liegt im Snapshot. |
| SP5 | **Öffentlich ist nur der Pförtner.** nginx liefert `/kern/…` und `/module/…` direkt; `app.html` gibt der Pförtner erst nach der Bestätigung heraus; die Schnittstelle antwortet ohne Bestätigung mit 401 `spielwiese.eintreten`. kumero hört nur auf 127.0.0.1. |
| SP6 | **Firewall:** eingehend SSH, 80, 443; ausgehend nur Namensauflösung, Zeit und die in `AUSGEHEND_ERLAUBT` genannten Ziele. Ohne solche Ziele bekommt die Maschine keine Paket-Updates – sie wird ohnehin zurückgesetzt. **Nicht gelöst:** Container von Docker (Paperless) gehen an UFW vorbei. |
| SP7 | **Paperless nur teilweise (SW3):** Der Pförtner verbindet und entsperrt den Zugang der Spiel-Identität, wenn Adresse und Token in den Angaben stehen. Den Spiel-Nutzer und sein Token in Paperless legt das Skript nicht an. |
| SP8 | **Bestand: nur die 58 Buchungen.** Offene Einträge im Arbeitsvorrat, Belege in Paperless, Artikel im Lager und erfundene Personen (Abschnitt 4a) sind nicht gebaut. Geschäftsjahr ist das Jahr der Einrichtung. |
| SP9 | **`einrichten.php` und `bestand.php` werden nach der Einrichtung von der Maschine entfernt.** Die Ausgabe der Einrichtung zeigt nie den Registrierungslink. |
| SP10 | **Die `HASHLISTE` eines Release nennt die ausgelassenen Dateien weiter** – wer sie später auf der Maschine prüft (P46), muss `install/spielwiese*` und `tests/spielwiese` ausnehmen. |

**Geprüft:** `bash install/spielwiese-test.sh` (21); `php tests/spielwiese/lauf.php` (25, davon der Browserlauf mit 7):
Einrichtung gegen ein laufendes kumero, vorgeschaltete Seite, 401 ohne Bestätigung, Eintreten, Bestand, Buchen als
Besucher, ungültige und fehlende Sitzung, Abmelden, kumero direkt bleibt zu. Bilder der Startseite und der Buchungsliste
angesehen.
**Nicht geprüft:** `spielwiese.sh` als Ganzes; Debian 13, nginx und der PHP-Pool (auch: ob `kumero-spielwiese` `app.html`
lesen darf, `open_basedir`), UFW, Proxmox; Paperless verbinden und entsperren; Hochladen über den Pförtner; Last.
