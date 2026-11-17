# Änderungen

Format nach [Keep a Changelog](https://keepachangelog.com/de/), Versionen nach [SemVer](https://semver.org/lang/de/).
Jede Version wird erst installiert, wenn der Projektverantwortliche das Release signiert hat (F73).

## [0.5.0] – 2026-11-17

### Neu
- **PHP-Anwendung** nach ANWENDUNG.md (E8 c): Front-Controller, Weg einer Anfrage mit Anmeldung, Mandant, Wasserstand (F69), Recht (F41), Idempotenz (F71) und einer Transaktion je Aufruf; Fehler als Problem Details mit den Codes `KM001`–`KM006`.
- **Anmeldung per Passkey** (E2) mit eigener WebAuthn-Prüfung (E3) für ES256 und EdDSA; Registrierungslinks, Sitzungen in der Verwaltungsdatenbank, Cookie `__Host-kumero`, Herkunftsprüfung gegen CSRF.
- **REST-API** (openapi/v1.yaml, erste Stufe): Anmeldung, Registrierung, Profil; Konten, Tags, Nummernkreise samt Festlegung je Geschäftsjahr, Buchungsarten, Geschäftsjahre.
- **Kommandozeile** `bin/kumero`: `nutzer:anlegen`, `nutzer:link`, `nutzer:liste`, `mandant:anlegen`, `mandant:eintragen`, `mandant:liste`, `zugang:gewaehren`, `selbsttest`.
- **Testseite** `start.html`: Passkey registrieren, anmelden, Konten ansehen und anlegen.
- **Kern** `0010_verzoegerte_trigger.sql`: Die verzögerte Prüfung der lückenlosen Geschäftsjahre (F65) meldet als Anwendung jetzt `KM002` statt „keine Berechtigung“.
- **Installer:** `/etc/kumero/kumero.php`; Option `ENTWICKLUNG_TUNNEL` (nginx nur auf 127.0.0.1:8080 für den SSH-Tunnel); `/var/lib/kumero` 0751. `aktualisieren.sh` schaltet `/var/www/kumero` nach erfolgreicher Migration auf das neue Release um (E7), lädt PHP-FPM neu und führt `bin/kumero selbsttest` aus.
- **Release** enthält `vendor/` nach `composer.lock` (E1): Symfony 8.1, Doctrine DBAL 4.6, bezogen direkt aus den Git-Repositorys der Projekte; Archiv reproduzierbar.
- Tests: Datenbank 194 Prüfungen, PHP 85 Prüfungen (WebAuthn mit Software-Authentifikator, Ende-zu-Ende gegen PostgreSQL).

### Definition
- F78: REST-API v1 – Freigabe in drei Schritten, Storno als Entwurf, Bots geben nie frei.

 – 2026-11-16

### Behoben
- `install/aktualisieren.sh` übergibt nach Prüfung von Signatur und Prüfsumme an das Skript aus dem geprüften Release. Bisher lief der ganze Ablauf mit der aufgerufenen, womöglich älteren Kopie; bei 0.4.0 wurde dadurch die Verwaltungsdatenbank nicht migriert, wenn das Skript aus einem alten Checkout gestartet wurde.
- `db/migrieren.sh` vermerkt eine Version in `meta.version` nur einmal je Release, auch wenn das Update wiederholt wird.

Am Kern (Datenbank) ändert sich nichts.

## [0.4.0] – 2026-11-14

### Neu
- **Rechte und Rollen** (`0008_rechte.sql`, F41–F43): Pflege nur über Funktionen mit Protokoll; Rechtekatalog des Kerns und die sieben Rollenvorlagen aus F42 (`kern.rollenvorlagen_anlegen`); effektive Rechte samt Einschränkungen (`kern.rechte_des_subjekts`). Die Invarianten aus F43 erzwingt die Datenbank beim Commit: Notfall nur für Verantwortliche, Prüfer nur lesend, Administrator ohne Buchungsdaten.
- **Integration** (`0009_integration.sql`): Idempotenz nach F71 mit neuem Fehlercode `KM006`; Protokoll und Ereignisse von Modulen (`kern.modul_protokoll`); Empfänger im Prozess mit Lesestand je Modul; Webhook-Abos mit Protokoll; Zustellung mit Wiederholung.
- **Verwaltungsdatenbank** (`db/verwaltung/0001_verwaltung.sql`, F37, F69): Mandanten, Nutzer, Anmelde-Passkeys, Registrierungslinks, Zugänge, Dienstkonten, Tokens, Sitzungen, Wasserstände, eigene Protokollkette. Geheimnisse nur als SHA-256.
- `db/migrieren.sh --verwaltung`; `install/aktualisieren.sh` spielt die Verwaltungsdatenbank mit ein.
- Tests: 192 Prüfungen.

### Definition
- F76: ohne Steuerberater vollständig; erster Kontenrahmen SKR 81.
- F77: `ANWENDUNG.md` (PHP-Gerüst und Modul-API) freigegeben, Entscheidungen E1–E8.

## [0.3.0] – 2026-11-14

### Neu
- **Beitrag zur Jahressperre** (`db/migrationen/0007_gj_beitraege.sql`, F75 Nr. 11): neuer Erweiterungspunkt `gj_sperren_beitrag`.
  - Module liefern vor der Sperre den Hash ihres Datenstands für das Jahr (`kern.gj_beitrag_setzen`), z. B. die EÜR- und E-Bilanz-Zuordnung.
  - Der Kern nimmt die Beiträge in den freigegebenen und gesiegelten Sperrinhalt auf, verweigert die Sperre bei fehlendem oder veraltetem Beitrag und hält die Beiträge danach unveränderlich.
- Tests: 127 Prüfungen.

### Definition
- F75: Modul `kontenrahmen` – Konverter für alle Kontenrahmen (DATEV-PDF, DATEV-Kontenbeschriftungen, CSV); jeder Nutzer entscheidet selbst, was er konvertiert; gefüllter Referenz-Kontenrahmen im Repository; Zuordnung zu Anlage EÜR und E-Bilanz je Geschäftsjahr; Zuordnung wird mit der Jahressperre eingefroren; Kontenplan in Verfahrensdokumentation und Prüferexport (GoBD).

## [0.2.1] – 2026-11-14

### Behoben
- Debian 13 hat `gpgv` nicht mehr vorinstalliert (apt nutzt `sqv`). Der Installer installiert das Paket jetzt in Schritt 10 mit.
- `aktualisieren.sh` prüft vorher, ob `gpgv` vorhanden ist, und zeigt bei einer fehlgeschlagenen Prüfung die Meldung von `gpgv` an.
- `pruefen.sh` prüft zusätzlich, ob `gpgv` installiert und ein Freigabe-Schlüssel hinterlegt ist.

Am Kern (Datenbank) ändert sich nichts.

## [0.2.0] – 2026-11-14

### Neu
- **DDL Teil 2** (`db/migrationen/0006_funktionen.sql`): Schreibfunktionen des Kerns. Die Anwendung schreibt ausschließlich über sie (F64).
  - Stammdaten: Buch, Konto, Tag, Nummernkreis, Buchungsart, Geschäftsjahr, Nummernkreis festlegen, Modul, Erweiterungsattribut
  - Entwürfe: anlegen, ändern (mit ETag), löschen, jeweils mit Vorher-/Nachher-Stand im Protokoll
  - Vorbereiten mit strenger Regelprüfung (F4, F12, F24, F58), Prüfvermerke der Module (F72)
  - Challenge ausstellen; Festschreiben einzeln oder als Stapel mit WebAuthn-Freigabe (F38, F66–F68)
  - Storno, Beleg nachreichen, Geschäftsjahr sperren und im Notfall öffnen, Produktivbetrieb einschalten
  - `kern.kette_pruefen()`: rechnet beide Ketten unabhängig nach
- **Versions- und Genehmigungsablauf (F73):** `VERSION`, `CHANGELOG.md`, `werkzeuge/release-bauen.sh`, `install/aktualisieren.sh`. `db/migrieren.sh` verweigert Änderungen am installierten Kern ohne genehmigtes Release.
- Tests: 118 Prüfungen, darunter Manipulationserkennung und echte Parallelität (F71).

## [0.1.0] – 2026-11-14

### Neu
- Definition (AGENTS.md F1–F72), abstraktes Datenmodell (Fassung 3)
- Installer für Debian 13 (PostgreSQL 19 aus PGDG, PHP 8.5 aus sury, nginx, unattended-upgrades)
- DDL Teil 1: Schema `kern`, Constraints, Schutz-Trigger, kanonische Form nach RFC 8785
