# LEISTUNGEN.md – Dienstleistungen: Katalog und erbrachte Leistungen (Entwurf, Fassung 2)

Stand: 2026-11-21. Bezug: PERSONAL.md (Zuschläge, Personen am Mandanten), F44, F106, F126 (je Buch), F134, IMPORT.md.
**Status: LE1–LE11 entschieden am 2026-11-21 (Abschnitt 10 – er geht den Abschnitten 2 bis 8 vor, wo sie sich widersprechen).
Nichts davon ist gebaut; vor dem Bauen sind LF1–LF8 zu bestätigen.**

## 1. Anlass

Vorgaben des Verantwortlichen vom 2026-11-20: Zuschläge sind **an den Verkauf von Dienstleistungen gebunden und
müssen dokumentierbar sein** – also braucht es ein Modul Dienstleistungen. Je nach Mandant kommt die **GOÄ oder
Ähnliches** dazu. Erfasst wird **derzeit in kumero**; die **Anbindung an andere Systeme muss möglich sein** (genannt:
RA-MICRO, CGM-Arztsysteme). **Rechnungen werden derzeit nicht gebraucht; das kommt später – nichts verbauen.**

## 2. Was das Modul tut

1. **Katalog** je Buch: Leistung mit Nummer, Bezeichnung, Gruppe, Einheit (Stück, Stunde, Pauschale), Preis,
   Steuersatz, Erlöskonto, gültig von–bis. Preise sind Fassungen mit Datum; eine erbrachte Leistung behält den Preis
   ihres Tages.
2. **Gebührenwerke als Quelle des Katalogs:** Ein Gebührenwerk liefert Nummern, Texte und Bewertung (bei der GOÄ:
   Punktzahl, Punktwert, Steigerungssatz → Betrag). Das Buch übernimmt daraus, was es anbietet. Gebührenwerke sind
   Regelwerke mit Gültigkeit, nicht im Programm (wie das Regelwerk der Anlagen); welche es gibt, hängt vom Mandanten
   ab (Arztpraxis, Kanzlei, andere).
3. **Erbrachte Leistung:** Datum, Leistung, Menge, Einzelbetrag, Betrag, **wer sie erbracht oder verkauft hat**
   (Person des Mandanten), Zustand (erfasst, bestätigt, storniert), Quelle (von Hand, eingelesen) und eine
   **Vorgangskennung**. Storno ist eine Gegenzeile; nichts wird gelöscht.
4. **Erfassen:** schnelle Maske für eine oder mehrere Leistungen eines Vorgangs; Suche im Katalog nach Nummer und
   Text; Vorlagen für häufige Zusammenstellungen. Selfservice: Eine Person erfasst ihre eigenen Leistungen.
5. **Bestätigen:** Erst bestätigte Leistungen zählen für Zuschläge. Ohne zweite Person bestätigt, wer das Recht hat,
   auch die eigenen (Einstellung des Buchs).
6. **Auswertung:** Umsatz je Person, Leistung, Gruppe und Monat; Liste der Leistungen einer Person als **Nachweis für
   Zuschläge** (Bildschirm, Druck, CSV).
7. **Dienst für `personal`:** bestätigte Leistungen je Person und Monat mit Einzelzeilen – über einen Dienst, nie
   über Tabellen.

## 3. Grenze: kein Patient, kein Mandant des Mandanten

Für Katalog, Umsatz und Zuschlag reichen Leistung, Person, Datum, Menge und Betrag. **Wer die Leistung empfangen hat,
steht nicht in kumero** – kein Name, keine Nummer eines Patienten oder Klienten, kein Freitext an der Leistung. Die
**Vorgangskennung** ist eine neutrale Nummer (von kumero vergeben oder aus dem Fremdsystem übernommen), die mehrere
Leistungen zusammenhält und den Rückweg ins führende System erlaubt, ohne dass kumero dessen Daten kennt. Wie im
Lager gilt: An wem die Leistung erbracht wurde, weiß das Praxis- oder Kanzleisystem (Art. 9 DSGVO, Berufsgeheimnis).

## 4. Nichts verbauen: Rechnungen später

Rechnungen sind nicht Teil dieses Entwurfs. Damit sie später dazukommen können, ohne umzubauen:

- Leistungen hängen an einem **Vorgang**; ein Vorgang kann später einen Empfänger und eine Rechnung bekommen.
- Jede Leistung trägt Steuersatz und Erlöskonto schon jetzt.
- Beträge sind netto und brutto ableitbar; Rundung je Zeile in ganzen Cent.
- **Wenn Rechnungen kommen, kommt der Empfänger** – und damit die Frage, ob kumero Patienten- oder Klientendaten
  führen darf. Das ist dann neu zu entscheiden und kein Nebenprodukt dieses Moduls.

## 5. Anbindung fremder Systeme

Erbrachte Leistungen können eingelesen statt erfasst werden: eine Datei aus dem führenden System, über „Datei
einlesen“ (Modul `import`), mit Zuordnung der Spalten zu Leistung, Person, Datum, Menge, Betrag und Vorgangskennung.
Dieselbe Leistung zweimal eingelesen ergibt eine Zeile (Schlüssel aus Quelle und Vorgang). Die Datei wird nicht
aufbewahrt. **Was RA-MICRO und die CGM-Arztsysteme ausgeben können, ist nicht geprüft** – der Entwurf legt nur fest,
was kumero annimmt, und dass eine Datei mit Patientennamen so nicht eingelesen wird (die Spalte wird nicht zugeordnet
und nicht gespeichert).

## 6. Rollen (im Buch, vom Modul mitgebracht)

| Rolle | darf |
|---|---|
| **Leistungen erfassen** | eigene Leistungen erfassen und sehen |
| **Leistungen führen** | alle Leistungen sehen, bestätigen, stornieren; Katalog und Gebührenwerke pflegen; einlesen |
| Buchhalter, Verantwortlicher | wie „Leistungen führen“ |
| Leser, Prüfer, Steuerberater | sehen |

Die Rollen „Personal“ und „Lohn“ am Mandanten lesen über den Dienst, was sie für Zuschläge brauchen.

## 7. Entscheidungen (zur Freigabe)

| # | Frage | Vorschlag |
|---|---|---|
| LE1 | Ebene | **Buch** – Leistungen sind Umsatz eines Buchs; Personen kommen vom Mandanten. |
| LE2 | Empfänger | **keiner**; nur eine neutrale Vorgangskennung. |
| LE3 | Katalog | eigene Leistungen und Übernahme aus Gebührenwerken; Preise als Fassungen mit Datum. |
| LE4 | Gebührenwerke | als Regelwerk mit Gültigkeit; erste Fassung: eigener Katalog. Die GOÄ als zweites, nach Prüfung von Quelle und Stand. |
| LE5 | Wer hat erbracht | genau eine Person je Zeile; Aufteilung auf mehrere Personen später. |
| LE6 | Bestätigung | Zuschläge nur aus bestätigten Leistungen; Selbstbestätigung ist eine Einstellung des Buchs. |
| LE7 | Storno | Gegenzeile mit Grund; wirkt im Monat des Stornos, nicht rückwirkend. |
| LE8 | Buchhaltung | Das Modul bucht nicht. Ein Abgleich des Umsatzes mit den Erlöskonten ist eine spätere Stufe. |
| LE9 | Rechnungen | nicht jetzt; Vorgang, Steuersatz und Erlöskonto sind vorgesehen (Abschnitt 4). |
| LE10 | Einlesen | über `import` mit Spaltenzuordnung; Empfängerdaten werden nicht übernommen. |
| LE11 | Rollen | zwei eigene Rollen (Abschnitt 6). |

## 8. Stufen

| Stufe | Inhalt |
|---|---|
| **1** | Katalog, Erfassen, Bestätigen, Storno, Auswertung, Dienst für `personal`. |
| **2** | Einlesen aus Dateien fremder Systeme. |
| **3** | Gebührenwerke (GOÄ und andere). |
| später | Rechnungen, Abgleich mit den Erlöskonten, Aufteilung einer Leistung auf mehrere Personen. |

## 9. Offen und ungeprüft

- Keine offene Vorlage gefunden und nicht gezielt gesucht: Dieser Entwurf ist aus dem Zweck heraus geschnitten.
- Quelle, Stand und Nutzungsbedingungen einer maschinenlesbaren GOÄ sind nicht geprüft; ebenso wenig das Gegenstück
  für Kanzleien.
- Ausgabeformate von RA-MICRO und den CGM-Arztsystemen sind nicht geprüft.
- Umsatzsteuer (steuerfreie und steuerpflichtige Leistungen in einem Buch) folgt dem Steuersatz der Leistung; mehr ist
  hier nicht betrachtet.

## 10. Entscheidungen des Verantwortlichen vom 2026-11-21 (Fassung 2)

| # | Entscheidung |
|---|---|
| LE1 | **Nicht im Buch.** Die Leistungen gehören zum **Mandanten**; im Buch liegt erst die Abrechnung von Lohn und Gehalt. Damit entfällt die Grenze zwischen Buch und Mandant (PERSONAL.md L2): Das Modul steht neben `personal` und `zeit`. |
| LE2 | **Kein Empfänger – derzeit.** Für die Übergabe an das Lohnbüro bleibt es dabei. Intern ist die **Faktura auf Kunden oder Projekte** für die Zukunft zu planen (AGENTS.md P41): Die Leistungszeile wird so gebaut, dass Kunde und Projekt später dazukommen können. |
| LE3 | **Ja:** ein konfigurierbarer Leistungskatalog, aus dem gewählt wird. |
| LE4 | **Zurückgestellt** (Gebührenwerke, GOÄ). |
| LE5 | **Bleibt:** genau eine Person je Zeile. |
| LE6 | **Der Mitarbeiter trägt ein, der Vorgesetzte bestätigt; der Vorgesetzte kann selbst eintragen.** |
| LE7 | **Kein Storno: Die Leistungszeile wird tatsächlich gelöscht.** |
| LE8 | **Richtig:** nur Vorbereitung. Gebucht wird die Zahlung von Lohn und Gehalt. |
| LE9 | **Richtig:** Ein Modul für Faktura und Rechnungserstellung ist geplant, aber nicht da. |
| LE10 | **Richtig:** Einlesen über `import`, ohne Empfängerdaten. |
| LE11 | **Rollen an LE6 anpassen.** |

**Was daraus folgt – Festlegungen vor dem Bauen, zu bestätigen:**

| # | Festlegung |
|---|---|
| LF1 | **Eigenes Modul `leistungen` am Mandanten**, das auf `personal` aufbaut (wie `zeit`), mit Seiten im Bereich „Personal“: „Meine Leistungen“ unter „Meine Daten“ und „Leistungen“ für Vorgesetzte, Personal und Lohn. |
| LF2 | **Rollen (LE11 nach LE6):** eine eigene Rolle **„Leistungen“** (eigene Leistungen eintragen und sehen – wie „Zeiterfassung“ nicht von selbst bei „Mitarbeiter“); **Vorgesetzter** bestätigt die Leistungen der unmittelbar Unterstellten und trägt für sie ein; **Personal** pflegt den Katalog, sieht und bestätigt alles; **Lohn** liest. |
| LF3 | **„Der Vorgesetzte kann selbst eintragen“ hat zwei Lesarten:** (a) er trägt für seine Leute ein – dann gilt die Zeile sofort als bestätigt; (b) er trägt eigene Leistungen ein – wer bestätigt die? Vorschlag: (a) gilt; eigene Leistungen eines Vorgesetzten bestätigt sein Vorgesetzter, ohne einen solchen das Personal (wie AW6). **Bitte entscheiden.** |
| LF4 | **Löschen (LE7):** Die Person löscht eine eigene Zeile, solange sie nicht bestätigt ist; danach löscht der Vorgesetzte oder das Personal. **Ist die Lohnvorbereitung des Monats geprüft oder übergeben, wird nichts mehr gelöscht oder geändert** – sonst stimmte der eingefrorene Nachweis nicht mehr. Im Protokoll bleibt, dass eine Zeile gelöscht wurde, ohne Inhalt. **Bitte bestätigen, vor allem die Sperre nach der Prüfung.** |
| LF5 | **Katalog:** Leistung mit Bezeichnung, Gruppe und Preis; Preise als Fassungen mit „gültig ab“. Eine Zeile hält Menge und Betrag fest, wie sie beim Eintragen galten; ob der Betrag je Zeile abweichen darf (Nachlass), ist eine Einstellung der Leistung. Die Zuschlagsregeln (PERSONAL.md 10d) beziehen sich über Bezeichnung von Gruppe oder Leistung auf den Katalog. |
| LF6 | **Zuschläge rechnen aus bestätigten Zeilen des Monats** über die Anschlussstelle `personal._zuschlag_nachweis()`; die Lohnunterlage zeigt die Zeilen als Nachweis (Datum, Leistung, Menge, Betrag) – ohne Empfänger, wie LE2. |
| LF7 | **Vorbereitet, nicht gebaut:** Kunde und Projekt an der Zeile (P41), Steuersatz und Erlöskonto (LE9), Einlesen (LE10, Stufe 2). |
| LF8 | **Beträge liegen unverschlüsselt** in der Datenbank des Mandanten wie die Zuschläge von Hand (L17) – bis P38. |
