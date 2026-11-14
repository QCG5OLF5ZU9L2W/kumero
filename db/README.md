# Datenbank des Buchungskerns

Umsetzung des freigegebenen [Datenmodells](../DATENMODELL.md) für PostgreSQL. Ziel ist PostgreSQL 19 (F54). Getestet wird zusätzlich gegen PostgreSQL 16; Funktionen, die es erst in 19 gibt, werden nicht verwendet.

## Inhalt

| Datei | Inhalt |
|---|---|
| `migrationen/0001_grundlagen.sql` | Schema `kern`, Rechte-Voreinstellungen, Typen, kanonische Form nach RFC 8785 (`kern.jcs`), Hash-Funktionen |
| `migrationen/0002_stammdaten.sql` | Buch, Konto, Tag, Nummernkreis, Buchungsart, Geschäftsjahr, Nummernkreis-Jahr, Modul, Erweiterungsattribut |
| `migrationen/0003_buchungen.sql` | Credential, Challenge, Freigabe, Buchung, Zeile, Zeile↔Tag, Erweiterungswert, Prüfvermerk, Belegverweis, Freigabe-Position |
| `migrationen/0004_nachweis_integration_rechte.sql` | Kettenköpfe, Protokoll, Siegel, Siegel-Export, Restore-Prüfung, Outbox, Webhooks, Idempotenz, RBAC-Tabellen |
| `migrationen/0005_schutz.sql` | Schutz-Trigger (Unveränderlichkeit, Statusübergänge, Zähler, Ketten) und Rechte der Anwendung |
| `migrationen/0007_gj_beitraege.sql` | Beiträge von Modulen zur Jahressperre (F75 Nr. 11): Erweiterungspunkt `gj_sperren_beitrag`, `kern.gj_beitrag_setzen`, Sperrinhalt mit Beiträgen |
| `migrationen/0006_funktionen.sql` | Schreibfunktionen (Teil 2): Stammdaten, Entwürfe, Vorbereiten, Challenge, Festschreiben, Storno, Belege nachreichen, Geschäftsjahr sperren/öffnen, Produktivbetrieb, Kettenprüfung |
| `migrieren.sh` | spielt Migrationen ein (Prüfsumme je Migration); auf installierten Systemen nur über `install/aktualisieren.sh` mit genehmigtem Release (F73) |
| `tests/` | Testlauf mit synthetischen Daten (`PGHOST=… PGUSER=postgres tests/ausfuehren.sh`) |

Stand: **Version 0.3.0**, Teil 1 (Struktur, Schutz), Teil 2 (Schreibfunktionen) und Beiträge zur Jahressperre. Tests: 127 Prüfungen in fünf Dateien, jede mit frischer Datenbank, darunter echte Parallelität (`40_parallel.sh`).

## Ablauf einer Buchung (Funktionen)

1. `kern.entwurf_anlegen(subjekt, daten)` → ID. Der Entwurf darf unvollständig sein (F62). Danach `kern.entwurf_aendern(subjekt, id, daten, etag)`.
2. Module reichern an und prüfen: Sie setzen Erweiterungswerte über `entwurf_aendern` und schreiben mit `kern.pruefvermerk_setzen(…)` einen Prüfvermerk.
3. `kern.vorbereiten(subjekt, id)` → Freigabe-Hash. Dabei werden die Regeln streng geprüft und der Inhalt eingefroren (F66).
4. Die Anwendung zeigt den Inhalt an und holt mit `kern.challenge_ausstellen(…)` eine Challenge. Den Inhalts-Hash liefert für eine einzelne Buchung `buchung_freigabe_hash`, für einen Stapel `stapel_hash`. Anschließend prüft die Anwendung die WebAuthn-Assertion.
5. Module prüfen ein letztes Mal: `pruefvermerk_setzen(…, 'festschreiben_pruefen', …)`.
6. `kern.festschreiben(subjekt, ARRAY[ids], freigabe)` vergibt die Nummern, bildet die Kette und schreibt Protokoll und Outbox (F71).

Storno: Mit `kern.storno_anlegen(subjekt, original)` entsteht ein Entwurf mit getauschten Seiten. Danach geht es weiter wie ab Schritt 3.

## Festlegungen, die beim Umsetzen getroffen wurden

1. **Fehlercodes:** Eigene SQLSTATE-Klasse `KM`.
   - `KM001`: Unveränderlichkeit
   - `KM002`: Buchungsregel
   - `KM003`: Zustand erlaubt die Handlung nicht
   - `KM004`: unzulässiger Wert für die kanonische Form
   - `KM005`: Konflikt (ETag)
2. **Kanonische Form:** `kern.jcs` erzwingt den in F29 erlaubten Wertebereich.
   - Nur ASCII-Schlüssel und Ganzzahlen unter 2^53 sind erlaubt.
   - Beträge gehören als String hinein. Eine Dezimalzahl im Protokoll wird deshalb abgewiesen.
3. **Was am Konto unveränderlich wird:** Ist ein Konto in einer festgeschriebenen Buchung verwendet, sind **Nummer und Kontoart** fest. Die **Bezeichnung** bleibt änderbar und wird protokolliert.
   - Konsequenz für den Buchungs-Hash in Teil 2: Er enthält Kontonummer und Kontoart, nicht die Bezeichnung.
   - Für Tags, Buchungsarten und Nummernkreise gilt dasselbe mit Name bzw. Kürzel.
4. **Protokollkette:** Ein Trigger verkettet jeden Protokolleintrag automatisch (Nummer, Zeitpunkt, Vorgänger-Hash, Hash). Ein Eintrag ohne korrekte Verkettung ist damit nicht möglich.
5. **Nummernkreis-Zähler:** Die erste Vergabe setzt den Zähler auf den Startwert, danach steigt er genau um 1. Das erzwingt ein Trigger.
6. **Geschäftsjahr:** Der Zeitraum ist halboffen gespeichert, `[Beginn, Ende+1)`. Überschneidungen verhindert ein Exclusion-Constraint, Lücken ein verzögerter Constraint-Trigger (F65).

## Grenzen

Die Schutz-Trigger wirken gegen die Anwendung und gegen den Eigentümer `kumero_owner`. Ein PostgreSQL-Superuser kann Trigger abschalten. Solche Eingriffe bleiben über die Ketten und die externen Siegel nachweisbar (F13, F14, F35, F69). Verhindern lassen sie sich nicht. Das ist Absicht und entspricht der Diskussion zu „root kann ändern, aber nicht unbemerkt“.
