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
| `migrieren.sh` | spielt die Migrationen ein; jede Migration wird mit ihrer SHA-256-Summe vermerkt, nachträgliche Änderungen fallen auf |
| `tests/` | Testlauf mit synthetischen Daten (`PGHOST=… PGUSER=postgres tests/ausfuehren.sh`) |

Stand: **Teil 1**, also Struktur und Schutz. Die Schreibfunktionen (Entwurf, Vorbereiten, Festschreiben, Storno, Geschäftsjahr sperren) folgen in Teil 2.

## Festlegungen, die beim Umsetzen getroffen wurden

1. **Fehlercodes:** Eigene SQLSTATE-Klasse `KM`.
   - `KM001`: Unveränderlichkeit
   - `KM002`: Buchungsregel
   - `KM003`: Zustand erlaubt die Handlung nicht
   - `KM004`: unzulässiger Wert für die kanonische Form
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
