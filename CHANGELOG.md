# Änderungen

Format nach [Keep a Changelog](https://keepachangelog.com/de/), Versionen nach [SemVer](https://semver.org/lang/de/).
Jede Version wird erst installiert, wenn der Projektverantwortliche das Release signiert hat (F73).

## [Unveröffentlicht]

### Definition
- F75: Modul `kontenrahmen` – Konverter für alle Kontenrahmen (DATEV-PDF, DATEV-Kontenbeschriftungen, CSV); jeder Nutzer entscheidet selbst, was er konvertiert; gefüllter Referenz-Kontenrahmen im Repository; Zuordnung zu Anlage EÜR und E-Bilanz je Geschäftsjahr.

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
