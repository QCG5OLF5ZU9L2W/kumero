# CODEINTEGRITAET.md – Nur freigegebener Code läuft (Entwurf, zurückgestellt bis zur Produktionsreife, P46)

Stand: 2026-11-21. Bezug: F73 (jeder Schritt ein Release, installiert wird nur Signiertes), F23 (Schlüssel getrennt, der
private Freigabeschlüssel liegt nie auf einem Server), F119/F120 (Siegel, Signatur außerhalb), F144/F158–F160
(`werkzeuge/update-neueste.sh`, `install/nachziehen.sh`), SIEGEL.md (Dauer des Vertrauens – eigener Schritt, Abschnitt 8 hier).
**Nichts davon ist gebaut.**

> **Zurückgestellt (Entscheidung des Verantwortlichen vom 2026-11-21):** „Den Rest stellen wir zurück bis zur
> Produktionsreife. Aber wir haben es bedacht.“ CI1–CI3 gelten als entschieden; CI4–CI12 und der Bau ruhen, ebenso die
> Dauer des Vertrauens (Abschnitt 8). **Vor dem Produktivbetrieb ist dieser Entwurf wieder aufzurufen** (F59).
> **Schon mitgeführt wird die Hashliste selbst** (F162, 0.7.71: `HASHLISTE`, `werkzeuge/hashliste.sh`) – ohne Signatur, der
> Bau vergleicht und meldet nur. Bis dahin
> bleiben die Lücken L1–L6 bestehen – bekannt und hingenommen, weil die Test-VM aus dem Netz nicht erreichbar ist und nur
> erfundene Daten trägt.

## 1. Anlass

Am 2026-11-21 hat der Verantwortliche festgestellt: „Deinem Installer schiebe ich unsignierten Code unter.“ Das stimmt.
Die Daten sind über Ketten und Siegel abgesichert; der Code ist es nur an einer Stelle – `aktualisieren.sh` prüft, dass ein
Archiv zu einer Signatur passt. Davor und danach prüft nichts.

## 2. Wo heute unsignierter Code läuft

| # | Stelle | Was geschieht |
|---|---|---|
| L1 | `werkzeuge/update-neueste.sh` | läuft als root aus dem Klon, vom Stand des Zweigs – vor jeder Prüfung |
| L2 | `werkzeuge/release-vom-tag.sh`, `release-bauen.sh` | laufen als root aus dem Klon; der Tag ist nicht signiert, sein Inhalt wird ungeprüft gebaut |
| L3 | „Jetzt hier signieren?“ | liegt der Schlüssel auf der VM, bestätigt die Signatur nur, was dort gebaut wurde – und widerspricht F23 |
| L4 | `composer install` im Bau | holt die Abhängigkeiten aus dem Netz; gebunden an Git-Commits der Lock-Datei, nicht an die Freigabe |
| L5 | `install/install.sh` (neue VM) | läuft ganz ohne Signatur |
| L6 | nach dem Einspielen | niemand prüft, ob Dateien, Funktionen der Datenbank und Konfiguration noch der freigegebene Stand sind |

## 3. Entschieden vom Verantwortlichen (2026-11-21)

| # | Entscheidung |
|---|---|
| CI1 | **Maß ist: Jedes Codeobjekt ist durch eine PGP-Signatur gedeckt, deren öffentlicher Schlüssel vorliegt** – einzeln signiert oder über eine **Hashliste, deren Unversehrtheit eine PGP-Signatur sichert**. |
| CI2 | **Die Hashliste bekommt ihre Signatur von außen** – nie auf der Maschine, auf der der Code läuft. Damit ist die Kette geschlossen, **auch für den Stand in Git**. |
| CI3 | **Integrität und Dauer des Vertrauens sind zwei Fragen.** Integrität („ist es jetzt das Freigegebene?“) wird mit heutigen Verfahren gelöst; die Dauer („gilt der Nachweis in zehn Jahren?“) betrifft Siegel und Ketten und ist ein eigener Schritt. |

## 4. Die Hashliste

- **Eine Datei `HASHLISTE`** im Wurzelverzeichnis: je Zeile Hash und Pfad, sortiert, für **jede** Datei des Standes – außer
  sich selbst und ihrer Signatur `HASHLISTE.asc`. Format wie `sha512sum`, prüfbar mit Bordmitteln.
- **Hash: SHA-512.** SHA-256 trüge auch; für eine neue Liste kostet die Reserve nichts. **Nicht der Hash von Git:** Git
  rechnet in diesem Repository mit SHA-1 – ein signierter Tag allein hinge an diesem Verfahren.
- **Signatur: abgesetzt (`HASHLISTE.asc`), austauschbar, mehrere nebeneinander möglich** – damit später eine quantenfeste
  dazukommen kann (RFC 9980), ohne dass sich das Format ändert.
- **In Git:** Die Liste nennt alle Dateien des Commits, mit dem das Release fertig ist. Liste und Signatur kommen mit einem
  zweiten Commit dazu, der sonst nichts ändert; der Tag zeigt auf diesen. Wer den Klon hat, prüft mit zwei Befehlen
  (`gpgv`, `sha512sum -c`) und einem Vergleich „keine Datei zu viel, keine zu wenig“.
- **Geprüft wird die Vollständigkeit in beide Richtungen:** Eine fehlende Datei ist ein Fehler, eine zusätzliche auch.

## 5. Ablauf (Vorschlag)

**Auf dem Rechner mit dem Schlüssel** (heute `sbs-server`, besser ein Rechner ohne Dienste):
1. Stand holen, Hashliste erzeugen (`werkzeuge/hashliste.sh`), ansehen, signieren, Liste und Signatur einchecken, Tag setzen.
2. Für das Archiv mit den Abhängigkeiten: eine zweite Liste über den gebauten Inhalt (Abschnitt 6).

**Auf der VM** – sie hat nur den öffentlichen Schlüssel:
1. Das Update startet mit dem Skript des **installierten, geprüften** Release (`/var/lib/kumero/releases/<Version>/…`), nicht
   mit dem aus dem Klon. Der Klon liefert nur noch Daten.
2. Stand holen; **Signatur der Hashliste prüfen, dann jede Datei** – erst danach wird irgendetwas daraus ausgeführt.
3. Bauen, Ergebnis gegen die Liste des Archivs prüfen, einspielen wie bisher.
4. **Im Betrieb:** `bin/kumero system:integritaet` vergleicht den installierten Stand mit der Liste; täglich per Timer, im
   Selbsttest und in `pruefen.sh`; eine Abweichung sieht der System-Verwalter in der Oberfläche.

**Neue VM:** `install.sh` aus einem geprüften Archiv. Der erste öffentliche Schlüssel kommt auf einem zweiten Weg auf die
Maschine (sein Fingerabdruck wird verglichen) – das ist der Anker und lässt sich nicht aus dem Netz ableiten.

## 6. Zu entscheiden

| # | Frage | Vorschlag |
|---|---|---|
| CI4 | **Abhängigkeiten (`vendor/`)** stehen nicht in Git | Der Bau geschieht auch außen; die Liste des Archivs deckt `vendor/` mit ab. Die VM baut selbst und vergleicht Datei für Datei – stimmt etwas nicht, wird nicht eingespielt. **Zu prüfen, ob der Bau mit Composer Datei für Datei gleich ausfällt;** wenn nicht, wird außen gebaut und die VM spielt nur noch ein. |
| CI5 | **Datenbank** – die Regeln stecken in Funktionen mit erhöhten Rechten | Zweite Liste `HASHLISTE.db`: Hash jeder Funktion, jedes Triggers und der Rechte, wie die Migrationen des Release sie ergeben; von derselben Signatur gedeckt. `system:integritaet` rechnet sie je Datenbank nach. Module zählen nur, soweit sie eingeschaltet sind. |
| CI6 | **Konfiguration** (`/etc/kumero`, PHP, nginx, systemd) | Nicht in der Liste – sie ist je Installation verschieden. Geprüft wird nur, was `install.sh` aus Vorlagen schreibt und niemand von Hand pflegt (wie `nachziehen.sh`); alles andere meldet `pruefen.sh` nur. |
| CI7 | **Signieren auf der VM** | Entfällt: Die Frage „Jetzt hier signieren?“ wird entfernt. Liegt auf der Maschine ein geheimer Schlüssel, warnt das Skript. Für die Entwicklungs-VM gilt dasselbe – sonst prüft der Test nicht, was später gilt. |
| CI8 | **Signierte Tags** (`git tag -s`) zusätzlich? | Ja, als zweite Sicherung und weil GitHub sie anzeigt – maßgeblich bleibt die Hashliste (SHA-512 statt SHA-1). |
| CI9 | **Mehrere Schlüssel** | Die VM kennt eine Liste erlaubter Freigabeschlüssel; ein Schlüssel wird nur durch ein Release ausgetauscht, das der bisherige signiert hat. Widerruf: Abschnitt 8. |
| CI10 | **Was tut kumero bei einer Abweichung im Betrieb?** | Melden, nicht abschalten: Hinweis für den System-Verwalter, Eintrag im Protokoll der Verwaltung, der Selbsttest wird rot. Ein Abschalten von selbst wäre ein Hebel, mit dem ein Angreifer den Betrieb stoppt. |
| CI11 | **Ins Siegel** | Der Siegelinhalt nennt Version und Hash der `HASHLISTE` des Codes, mit dem das Buch geführt wurde. Damit hält die Signatur außerhalb auch fest, welcher Code galt. |
| CI12 | **Übergang** | Ein letztes Release nach dem alten Verfahren bringt Prüfer und öffentlichen Schlüssel mit; ab dem nächsten gilt nur noch das neue. `update-neueste.sh` im Klon wird zu einem Zweizeiler, der das installierte Skript aufruft. |

## 7. Grenzen

- **Wer root hat, kann den Prüfer und den öffentlichen Schlüssel austauschen.** Belastbar ist die Prüfung deshalb nur von
  außen: vom Rechner mit dem Schlüssel gegen die VM (Liste dort erzeugen lassen, hier vergleichen) – oder über das Siegel,
  das außerhalb signiert wird (CI11).
- Die Liste sichert den Code von kumero, nicht PHP, PostgreSQL und Debian – dafür stehen deren signierte Pakete und die
  automatischen Updates (F160).
- Geprüft wird, **dass** der Code der freigegebene ist – nicht, dass er fehlerfrei ist.

## 8. Dauer des Vertrauens (eigener Schritt, gehört zu SIEGEL.md)

Nicht Teil dieses Entwurfs, hier nur abgegrenzt: Ein Siegel muss am Ende der Aufbewahrungsfrist noch belegen, wie das Buch
aussah. Dafür müssen das Signaturverfahren, der Schlüssel und die Zuordnung des Schlüssels zur Person halten. Mittel:
Zeitstempel (SIEGEL.md, offen), Nachsignieren mit einem neueren Verfahren, Aufbewahren der öffentlichen Schlüssel
außerhalb, geordneter Widerruf, später quantenfeste Signaturen (ML-DSA, SLH-DSA nach RFC 9980). **Die Hashfunktionen sind
nicht die Schwachstelle** (SHA-256 und größer tragen auch gegen Quantencomputer); angreifbar sind RSA und elliptische Kurven.
Über CI11 erbt die Hashliste des Codes die lange Frist des Siegels – sie ist dann mit aufzubewahren.

## 9. Stufen

| Stufe | Inhalt |
|---|---|
| 1 | `werkzeuge/hashliste.sh` (erzeugen, prüfen), Hashliste und Signatur im Repository, Prüfung im Update vor jedem Ausführen, Update mit dem installierten Skript, „hier signieren“ entfernt (L1–L3). |
| 2 | Liste des Archivs mit `vendor/` (L4), `install.sh` aus dem geprüften Archiv (L5). |
| 3 | `system:integritaet` für Dateien und Datenbank, Timer, Selbsttest, Anzeige (L6, CI5, CI10). |
| 4 | Fingerabdruck im Siegel (CI11) – zusammen mit dem Schritt aus Abschnitt 8. |
