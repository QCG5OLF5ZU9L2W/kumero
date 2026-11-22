# KATALOG.md – Artikelkatalog über alle Mandanten (Entwurf, Fassung 3)

Stand: 2026-11-22. Bezug: LAGER.md (WW2, WW4, WW17, WW23, Abschnitt 8 und 15), BUECHER.md (A8), DATENMODELL.md (F64),
F44 (Module lesen nur über die Modul-API), F126 (je Buch), F164, P40, P48. Maßgeblich bleibt AGENTS.md.
**Status: KA1–KA7, KA9, KA15 und KA17 vom Verantwortlichen am 2026-11-22 entschieden; KA8, KA10–KA14, KA16 und KA18
sind Vorschläge. Nicht gebaut.**

Fassung 3 ergänzt Fassung 2 um das, was beim Abgleich mit den Dokumenten am Artikel (LAGER.md Abschnitt 15.9)
aufgefallen ist: Bestandsschutz (KA15), eigene Artikelnummer (KA16), Pflichtangaben aus P40 (KA17), Übernahme einer
Fassung bei vorhandenem Bestand (KA18), Zwischenhalten für Sortieren und Filtern (KA11).

## 1. Anlass

Vorgabe des Verantwortlichen vom 2026-11-22: **Artikelstammdaten sollen global zur Verfügung stehen – so, dass kein
Rückschluss auf den Mandanten möglich ist.** Heute liegt `lager.artikel` allein in der Datenbank des Buchs (WW2);
jeder Mandant legt denselben Artikel neu an.

## 2. Entscheidungen

| # | Frage | Stand |
|---|---|---|
| KA1 | Kopie oder Referenz? | **Entschieden 2026-11-22: lesende Referenz.** Eine Kopie verlöre Änderungen und Fortschreibungen. |
| KA2 | Wer schreibt in den Katalog? | **Entschieden 2026-11-22: jeder.** Der Mandant schreibt abstrakt und ohne Kennung. |
| KA3 | Fortschreibung | **Entschieden 2026-11-22: Katalogdaten haben Fassungen; die Referenz des Buchs zeigt auf die Fassung und ist damit stabil.** Eine Fassung wird nie geändert. |
| KA4 | Wer hat geschrieben? | **Entschieden 2026-11-22: wird nicht festgehalten.** Jeder Nutzer prüft selbst, ob der Katalogartikel der ist, der verwendet werden soll. Abweichung von F64 (Protokoll mit Subjekt) für den Katalog. |
| KA5 | Stilllegen | **Entschieden 2026-11-22: keine Frage des Katalogs, sondern des Mandanten.** Der Katalog hat keinen Zustand; stillgelegt wird der Artikel im Buch (WW23, wie gebaut). |
| KA6 | Löschen | Im Katalog wird nichts gelöscht und nichts geändert – nur angefügt (folgt aus KA3; davon hängt die Referenz ab). |
| KA7 | Was macht den Artikel aus, was ist Fassung? | **Entschieden 2026-11-22 (Zustimmung): GTIN, Einheit und Führungsart gehören zum Artikel** und ändern sich nie (WW4). Andere Einheit oder Führungsart = anderer Katalogartikel. Fassung: Bezeichnung, Hersteller, Art, Verfallspflicht, Lagerbedingung, Kennzeichen, bei Arzneimitteln die Pflichtangaben (KA17). So kann ein Wechsel der Fassung keinen Bestand verfälschen. |
| KA9 | Artikel außerhalb des Katalogs | **Entschieden 2026-11-22: „nur in diesem Buch“ ist, was keine GTIN hat.** Gelesen als: Die GTIN ist im Katalog Pflicht; ein Artikel ohne GTIN (Hausmischung, Eigenes) steht mit allen Angaben im Buch wie heute und kommt nie in den Katalog. |
| KA15 | Bestandsschutz | **Entschieden 2026-11-22: Das alte Recht gilt für den alten Artikel im Verkehr weiter.** Eine neue Fassung – auch eine mit geändertem Kennzeichen, etwa „Betäubungsmittel“ – wirkt in einem Buch erst, wenn dort ein Mensch sie übernimmt. Bis dahin gilt die verwendete Fassung mit allem, was aus ihr folgt (WW17: Bewegungen bleiben möglich). Das ist kein Mangel, sondern gewollt. Der Hinweis „neuere Fassung vorhanden“ nennt solche Unterschiede zuerst und in Worten. |
| KA17 | Pflichtangaben für Arzneimittel (P40) | **Entschieden 2026-11-22: P40 wird erweitert.** PZN, Wirkstoff, Stärke, Darreichungsform, Packungsgröße, Hersteller bzw. pharmazeutischer Unternehmer, Abgabestatus und Zulassungsnummer sind Angaben zum Produkt und damit **Felder der Fassung**; bei Artikeln ohne GTIN stehen sie im Buch. Ebenso das Kennzeichen „Gefahrstoff“ (LAGER.md AD14). Welche Angabe bei welcher Art Pflicht ist, entscheidet P40. |

## 3. Aufbau

Eigene Datenbank `kumero_katalog`, Schema `katalog`. **Keine Spalte für Mandant, Buch oder Subjekt, kein Zeitstempel.**
Geschrieben wird nur über SQL-Funktionen, die keine Kennung annehmen; die Rolle der Anwendung darf dort nur diese
Funktionen ausführen und lesen.

| Tabelle | Inhalt |
|---|---|
| `katalog.artikel` | zufällige UUID; **GTIN (Pflicht, mit gültiger Prüfziffer)**, Einheit, Führungsart (KA7) |
| `katalog.fassung` | Artikel, laufende Nummer, die fortschreibbaren Angaben – **nur anfügen** |

Eindeutig ist GTIN × Einheit × Führungsart (KA7: andere Einheit = anderer Katalogartikel, bei derselben GTIN).

Im Buch bleibt `lager.artikel` mit seiner ID – Bewegungen, Chargen und Inventuren sind nicht berührt. Die Zeile trägt
neu `katalog_artikel` und `katalog_fassung` (beide leer bei Artikeln ohne GTIN) und sonst das Eigene des Buchs.

| Im Katalog (Angaben zum Produkt) | Im Buch |
|---|---|
| GTIN, Bezeichnung, Hersteller | **eigene Artikelnummer** (KA16), Mindest- und Sollbestand, Standard-Lagerort |
| Art, Einheit, Führungsart | Lieferantenartikel, Preise, gemerkte Zuordnungen |
| Verfallspflicht, Lagerbedingung | Dokument-Verweise an Artikel und Charge (LAGER.md Abschnitt 15 – der Link nennt das Paperless des Mandanten, AD25) |
| Kennzeichen BtM und Gefahrstoff | Chargen, Bewegungen, stillgelegt, verwendete Fassung |
| bei Arzneimitteln: PZN, Wirkstoff, Stärke, Darreichungsform, Packungsgröße, Abgabestatus, Zulassungsnummer (KA17) | Tag-Zuordnung für Paperless, Einstellungen des Lagers |
| | **Artikel ohne GTIN: alle Angaben (KA9)** |

Der Verweis vom Buch in den Katalog hat keinen Fremdschlüssel (zwei Datenbanken). Er hält, weil im Katalog nur
angefügt wird (KA6). Es bleibt bei einer Transaktion in genau einer Datenbank (A8): Wer einen neuen Artikel anlegt,
schreibt erst in den Katalog, dann ins Buch; bricht der zweite Schritt ab, bleibt ein Katalogeintrag ohne Nutzer –
das schadet nicht.

## 4. Ablauf

1. **Verwenden:** Im Dialog „Artikel“ wird die GTIN eingegeben oder gescannt (oder nach Bezeichnung und Hersteller
   gesucht). Der Nutzer wählt den Katalogartikel in seiner neuesten Fassung und prüft ihn (KA4); das Buch hält
   Artikel und Fassung fest.
2. **Anlegen:** Kennt der Katalog die GTIN nicht, legt der Nutzer den Artikel dort an (Fassung 1) und verwendet ihn.
3. **Ohne GTIN:** Der Artikel wird wie heute im Buch angelegt; der Dialog sagt, dass er nur in diesem Buch steht.
4. **Fortschreiben:** Wer eine Angabe berichtigt, schreibt eine neue Fassung. **In keinem Buch ändert sich dadurch
   etwas** (KA15). Jedes Buch, das eine ältere Fassung verwendet, zeigt am Artikel „neuere Fassung vorhanden“ mit dem
   Unterschied in Worten; übernommen wird je Artikel von einem Menschen mit `lager.artikel.pflegen`.
5. **Nachweis im Buch:** Welche Fassung ab wann galt, steht im Protokoll des Buchs (Wechsel der Fassung). So ist
   später zu sagen, wie der Artikel hieß und welche Kennzeichen er trug, als eine Charge entnommen wurde.

**Der Dialog „Artikel“** wird zugleich von LAGER.md Abschnitt 15 umgebaut (Dokumente). Er ist einmal für beides zu
zeichnen, mit festen Plätzen (F106, F151): oben das Produkt aus dem Katalog (bei Artikeln mit GTIN gesperrt, mit
„neuere Fassung vorhanden“), darunter „In diesem Buch“, darunter „Dokumente“.

## 5. Rückschluss auf den Mandanten – was erreicht ist und was nicht

- **Erreicht:** Der Katalog nennt weder, wer einen Eintrag geschrieben hat, noch, wer ihn verwendet. Verwendung steht
  nur im Buch.
- **Inhalt:** Mit der GTIN als Pflicht (KA9) stehen im Katalog nur Handelsprodukte; Eigenes bleibt im Buch. Die
  Bezeichnung bleibt freier Text – der Dialog sagt, dass sie für alle sichtbar ist.
- **Dokumente:** Verweise auf Anleitungen und Gefahrenhinweise stehen nie im Katalog (LAGER.md AD25).
- **Wenige Mandanten:** Bei zwei Mandanten stammt jeder fremde Eintrag erkennbar vom anderen – er sagt dann, dass der
  andere dieses Handelsprodukt angelegt hat. Das verhindert keine Technik.
- **Betreiber:** Das Zugriffslog nennt den Pfad `/m/{mandant}/…` mit Uhrzeit, und das Buch protokolliert, wann es eine
  Fassung verwendet. Wer Server und Datenbanken betreibt, kann daraus auf den Schreiber schließen. Gegenüber anderen
  Mandanten ist der Katalog dicht, gegenüber dem Betreiber nicht.

## 6. Vorschläge (zur Entscheidung)

| # | Frage | Vorschlag |
|---|---|---|
| KA8 | Gleiche Fassung zweimal | Eine Fassung, die sich von der neuesten nicht unterscheidet, wird abgewiesen. |
| KA10 | Dubletten | Mit KA9 weitgehend erledigt: GTIN × Einheit × Führungsart ist eindeutig; wer eine bekannte GTIN anlegen will, bekommt den vorhandenen Artikel gezeigt. |
| KA11 | Wie liest das Buch? | Das Modul `lager` liest den Katalog über einen Dienst der Modul-API (F44), nicht direkt. Die Fassung ist unveränderlich und darf deshalb im Buch zwischengehalten werden. **Ergänzt:** Die Angaben der verwendeten Fassung, nach denen „Bestand“ sortiert und filtert (Bezeichnung, Art, Kennzeichen), **müssen** im Buch zwischengehalten sein – sonst kann die Datenbank des Buchs nicht danach ordnen. Weil eine Fassung sich nie ändert, veraltet die Kopie nicht. **Ungeprüft:** Laufzeit der Seite „Bestand“ bei vielen Artikeln. |
| KA12 | Bestand der vorhandenen Artikel | Beim Umbau bleiben alle vorhandenen Artikel zunächst im Buch. Artikel mit GTIN bringt ein Mensch je Artikel in den Katalog (oder verbindet sie mit dem dort vorhandenen) – nichts wandert von selbst. |
| KA13 | Recht | Im Katalog anlegen und fortschreiben: `lager.artikel.pflegen`. Suchen und lesen: `lager.bestand.sehen`. |
| KA14 | Produkte mit anderer Kennung als GTIN | Medizinprodukte können ihre UDI-DI auch nach HIBC oder ICCBBA tragen statt nach GS1. Nach KA9 bleiben sie im Buch. Vorschlag: so lassen; andere Kennungen erst, wenn sie im Betrieb vorkommen. **Nicht geprüft**, wie häufig das ist. |
| KA16 | Eigene Artikelnummer | `lager.artikel.nummer` ist eine Nummer des Betriebs, kein Merkmal des Produkts. Vorschlag: Sie bleibt im Buch, für Artikel mit und ohne GTIN, und kommt nie in den Katalog. |
| KA18 | Übernahme einer Fassung bei vorhandenem Bestand | Die Fassung gilt je Artikel im Buch, nicht je Charge. Übernimmt ein Buch eine Fassung mit strengerem Kennzeichen (z. B. Betäubungsmittel), gilt sie sofort auch für den Bestand, der schon daliegt. Vorschlag: Der Dialog der Übernahme nennt den vorhandenen Bestand und sagt das; wer den alten Bestand nach altem Recht aufbrauchen will (KA15), übernimmt erst danach. Eine Fassung je Charge wäre genauer, aber ein tiefer Eingriff – **bitte entscheiden.** |

## 7. Berührt

Neue Datenbank und Rolle (INSTALL.md, Sicherung), Verwaltung (Anlegen der Katalog-Datenbank), Modul-API (Dienst
„Katalog“), Modul `lager` (Migration, Dialog „Artikel“, Hinweis „neuere Fassung“, Seite „Bestand“), LAGER.md WW2,
Abschnitt 8 und Abschnitt 15.9, P40, `openapi/v1.yaml`. Tests nur mit erfundenen Artikeln und GTIN aus dem Testbereich.
