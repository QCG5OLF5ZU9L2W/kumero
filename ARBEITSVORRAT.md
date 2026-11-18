# ARBEITSVORRAT.md – Modul `arbeitsvorrat`: der Posteingang der Buchhaltung (Fassung 1, freigegeben 2026-11-17 → F89)

Stand: 2026-11-17. Bezug: F31 (Entwürfe), F44/F46 (Regeln für Module, Erweiterungspunkte), F51 Nr. 2 und 3
(Eingangskorb, Buchungsvorschlag), F62 (Entwürfe dürfen unvollständig sein), F88 (Belegverweis als Link),
BUCHUNGSMASKE.md, PROFIL.md.
**Status: freigegeben am 2026-11-17 (F89), erste Stufe umgesetzt in kumero 0.7.3 als Modul `arbeitsvorrat` 0.1.0.**
Abweichungen und Ergänzungen aus der Umsetzung stehen in Abschnitt 12 und sind noch zu bestätigen.

Vorgabe des Verantwortlichen vom 2026-11-17: **Der Arbeitsvorrat ist ein abstraktes Modul, in der Art eines
Posteingangs, der zu buchen ist. Paperless ist eine Datenquelle dafür. Der Arbeitsvorrat ist unabhängig von
der Quelle der Arbeit.**

Dieses Dokument ersetzt den bisherigen Schnitt aus BELEGEINGANG.md (Modul `belegeingang`, „ein Eintrag je
Paperless-ID“). BELEGEINGANG.md beschreibt künftig nur noch die **Quelle nopaper**.

---

## 1. Das Bild

Der Arbeitsvorrat ist der **Posteingang der Buchhaltung**: Dort liegt alles, was noch zu buchen ist – egal,
woher es kommt. Wer bucht, arbeitet ihn von oben nach unten ab: Eintrag öffnen, die Buchungsmaske ist
vorbefüllt, prüfen, speichern oder buchen, nächster Eintrag.

- Der Arbeitsvorrat **kennt keine Quelle beim Namen**. Er weiß nichts von Paperless, nopaper oder Bankkonten.
- Eine **Quelle** ist ein Modul, das Einträge einliefert. Jede Quelle ist austauschbar und abschaltbar.
- Der Arbeitsvorrat ist **ohne jede Quelle nutzbar**: Einträge lassen sich von Hand anlegen.
- Er bucht nie selbst. Aus einem Eintrag entsteht immer nur ein **Entwurf** (F31, F51 Nr. 3).

| Quelle (Beispiele) | Was sie einliefert | Modul der Quelle | Stand |
|---|---|---|---|
| von Hand | selbst angelegter Vorgang („noch zu buchen“) | `arbeitsvorrat` selbst | dieser Entwurf |
| nopaper | gescannter Beleg mit erkannten Rechnungsdaten | Quelle nopaper | BELEGEINGANG.md, NOPAPER-AUFTRAG.md |
| Paperless | Belege mit dem Tag für offene Belege | `paperless` | PAPERLESS.md |
| Online-Konten | Kontoumsätze ohne Buchung | `bank` | ONLINEKONTEN.md, zurückgestellt |
| E-Rechnungen | XRechnung, ZUGFeRD | `e-rechnung` | noch zu beschreiben |

## 2. Der Eintrag

Ein Eintrag ist **ein Vorgang, der zu buchen ist** – nicht ein Beleg, nicht ein Umsatz. Er hat eine eigene
ID und trägt nur, was jede Quelle liefern kann. Alle Felder außer dem Betreff dürfen fehlen.

| Feld | Inhalt |
|---|---|
| ID | eigene UUID des Eintrags |
| Betreff | eine Zeile, die den Vorgang benennt (z. B. „Rechnung Musterlieferant RE-0815“) |
| Art | freies Stichwort der Quelle, z. B. `rechnung`, `gutschrift`, `kassenbon`, `kontoumsatz`; Grundlage der Regeln (Abschnitt 7) |
| Gegenpartei | Name, dazu wenn bekannt eine Kennung (Steuer-ID, IBAN) |
| Datum, Betrag, Währung, Belegnummer | soweit bekannt; Betrag immer positiv mit Richtung „Ausgabe“ oder „Einnahme“ |
| Zahlweise, Zahltag | `bar` / `unbar` und, wenn bekannt, der Tag des Geldflusses (für das Buchungsdatum, BELEGEINGANG.md B8) |
| Belege | Liste von Belegverweisen nach F88: Link, wenn vorhanden MD5 und SHA-256 |
| Hinweise | kurze Texte der Quelle, auf Wunsch zu einem Feld (z. B. „Betrag steht nicht wörtlich im erkannten Text“) |
| Zustand | `offen`, `in_arbeit`, `gebucht`, `verworfen` (Abschnitt 4) |
| Eingegangen am, erledigt am/von | |

Ein Eintrag hat einen oder mehrere **Beiträge** – je Quelle einen:

| Feld des Beitrags | Inhalt |
|---|---|
| Quelle, Schlüssel | Name des Moduls und dessen eigener Schlüssel für den Vorgang; zusammen eindeutig |
| Angaben | was die Quelle weiß, als Liste „Bezeichnung – Wert“ zur Anzeige neben der Maske; der Arbeitsvorrat deutet sie nicht |
| Vorschlag | die Felder des Eintrags aus Sicht dieser Quelle |
| Stand | geliefert am, zuletzt geändert am, zurückgezogen am |

- Die Felder des Eintrags ergeben sich aus den Vorschlägen seiner Beiträge. Liefern zwei Quellen
  Verschiedenes zum selben Feld, gilt der zuerst eingegangene Wert; der andere steht als Hinweis daneben.
  Nichts wird still überschrieben.
- **Zusammenführen:** Zwei Beiträge gehören zum selben Eintrag, wenn sie **denselben Beleglink** nennen
  (z. B. Paperless-Beleg und nopaper-Daten dazu). Alles andere – gleicher Betrag, ähnliches Datum – wird
  höchstens vorgeschlagen und von einem Menschen bestätigt; das kommt mit den Online-Konten.

## 3. Vertrag für Quellen

Quellen sprechen mit dem Arbeitsvorrat nur über dessen **Dienst der Modul-API** (`Kumero\Api\V1`), nie über
dessen Tabellen (F44). Ein Modul meldet sich im Manifest als Quelle an (Name, Titel, Zeichen für die Liste).

| Aufruf der Quelle | Wirkung |
|---|---|
| **einliefern** (Schlüssel, Vorschlag, Angaben) | legt den Beitrag an oder ersetzt ihn; derselbe Schlüssel ergibt nie einen zweiten Eintrag |
| **zurückziehen** (Schlüssel) | die Quelle hält den Vorgang nicht mehr für offen (z. B. Tag in Paperless entfernt); ein unbearbeiteter Eintrag ohne weiteren Beitrag verschwindet aus „offen“ |

Zwei Arten von Quellen, beide über denselben Vertrag:

- **Liefernde Quelle:** Sie bringt Einträge, sobald sie welche hat (nopaper schickt einen Sidecar,
  ein Kontoauszug wird eingelesen).
- **Befragte Quelle:** Der Arbeitsvorrat ruft beim Öffnen den Erweiterungspunkt **`arbeitsvorrat_abgleich`**
  auf; die Quelle liefert dann ein und zieht zurück (Paperless: Belege mit dem Tag des Nutzers). Das läuft
  mit kurzer Frist und nach bestem Bemühen: Antwortet eine Quelle nicht, zeigt die Liste das an und
  arbeitet mit dem weiter, was da ist (F59).

Rückmeldung an die Quellen: Der Arbeitsvorrat meldet **`arbeitsvorrat.eintrag_gebucht`**,
**`…_verworfen`** und **`…_wieder_offen`** – nur mit IDs, Quelle und Schlüssel (F48). Was eine Quelle daraus
macht, ist ihre Sache (Paperless entfernt das Tag, PAPERLESS.md L10).

Ein geänderter Beitrag zu einem Eintrag, der schon in Arbeit oder gebucht ist, ändert dessen Felder nicht
mehr; er wird als Hinweis am Eintrag vermerkt.

## 4. Zustände

| Zustand | Bedeutung | Wechsel |
|---|---|---|
| `offen` | liegt im Posteingang | Ausgangszustand |
| `in_arbeit` | es gibt einen Entwurf dazu | Speichern in der Maske |
| `gebucht` | eine Buchung dazu ist festgeschrieben | Festschreiben |
| `verworfen` | kein Buchungsfall | „Verwerfen“ mit Pflichtgrund; „Wieder öffnen“ macht es rückgängig |

- Der Eintrag merkt sich die **Buchungen**, die aus ihm entstanden sind (mehrere möglich). Umgekehrt trägt
  der Entwurf im Kern `herkunft_ref = arbeitsvorrat:<ID>` (F26).
- `in_arbeit` und `gebucht` werden **aus dem Stand der Buchungen abgeleitet**, nicht von Hand gesetzt:
  Wird der Entwurf gelöscht, ist der Eintrag wieder offen. Wird die Buchung storniert, ist er wieder offen,
  mit Hinweis auf das Storno.
- Ein Eintrag wird **nie gelöscht**. Erledigte und verworfene bleiben als Nachweis, woher eine Buchung kam.

## 5. Oberfläche

- Bereich **Buchen → „Arbeitsvorrat“**, mit dem Zähler der offenen Einträge im Menü.
- **Liste** wie die Buchungsliste: Eingang, Quelle (Zeichen und Text), Art, Betreff, Gegenpartei,
  Belegnummer, Datum, Betrag, Hinweise. Ansichten: offen, in Arbeit, gebucht, verworfen. Filter: Quelle,
  Suche. Tastatur wie in der Buchungsliste.
- **Werkzeuge:** Buchen (Enter), Neu (Eintrag von Hand), Verwerfen, Wieder öffnen, Beleg öffnen.
- **Buchen** öffnet die Buchungserfassung, vorbefüllt nach Abschnitt 6. Neben dem Formular stehen die
  Angaben der Quellen zum Vergleich und die Belege als Links. Hinweise stehen als Text am Feld, nie als
  Pop-up (F32).
- Nach Speichern oder Buchen springt die Maske zum **nächsten offenen Eintrag**.
- **Eintrag von Hand:** Betreff (Pflicht), dazu nach Wunsch Art, Gegenpartei, Datum, Betrag, Belegnummer,
  Beleglink, Notiz.

## 6. Vorbefüllen der Buchungsmaske

Aus den Feldern des Eintrags, ohne Rätselraten:

| Feld der Maske | aus dem Eintrag |
|---|---|
| Belegdatum | Datum |
| Buchungsdatum | Zahlweise `bar`: das Belegdatum; `unbar`: der Zahltag, sonst leer, bis er bekannt ist |
| Belegnummer | Belegnummer |
| Buchungstext | Gegenpartei, dazu die Belegnummer |
| Betrag | Betrag |
| Beleg / Link | erster Belegverweis; weitere bleiben am Entwurf erhalten |
| Konto, Gegenkonto, Buchungsart, Tags | nur aus den Regeln (Abschnitt 7), sonst leer |

Jede Vorbelegung ist überschreibbar. Die Maske erfindet keine Konten und bucht nie ohne Sichtung.

## 7. Regeln des Mandanten

1. **Art → Geschäftsvorfall** (Vorlage aus dem Modul `buchen`), vom Nutzer gepflegt: z. B. `kassenbon` →
   „Barausgabe“, `rechnung` → „Zahlungsausgang Bank“. Bei Richtung „Einnahme“ stehen die Seiten getauscht.
2. **Gegenpartei → Konto**, beim Buchen gemerkt: Je Gegenpartei (Kennung, sonst Name) merkt sich kumero das
   zuletzt bestätigte Konto und schlägt es beim nächsten Eintrag vor. Die gemerkten Zuordnungen sind
   einsehbar und löschbar.

## 8. REST

Alles unter `/m/{mandant}/api/v1/arbeitsvorrat` (Modulpfade beginnen mit dem Modulnamen):

| Methode | Pfad | Zweck | Recht |
|---|---|---|---|
| GET | `/eintraege?zustand=&quelle=&art=` | Liste mit Cursor; stößt den Abgleich der befragten Quellen an | `arbeitsvorrat.eintrag.sehen` |
| GET | `/eintraege/{id}` | Eintrag mit Beiträgen, Hinweisen, Buchungen | `arbeitsvorrat.eintrag.sehen` |
| POST | `/eintraege` | Eintrag von Hand | `arbeitsvorrat.eintrag.anlegen` |
| POST | `/eintraege/{id}/buchung` | Entwurf mit diesem Eintrag verknüpfen (ruft die Maske nach dem Speichern) | `arbeitsvorrat.eintrag.bearbeiten` |
| POST | `/eintraege/{id}/verwerfen`, `/oeffnen` | verwerfen mit Grund, wieder öffnen | `arbeitsvorrat.eintrag.bearbeiten` |
| GET | `/zaehler` | Zahl der offenen Einträge für das Menü | `arbeitsvorrat.eintrag.sehen` |
| GET / PUT / DELETE | `/regeln…` | Regeln nach Abschnitt 7 | `arbeitsvorrat.regel.pflegen` |

Quellen haben **keine** eigenen Endpunkte im Arbeitsvorrat. Wer von außen einliefert (nopaper), spricht mit
dem Endpunkt seiner Quelle und braucht deren Recht – nie ein Recht des Arbeitsvorrats oder des Buchens.

## 9. Schnitt, Rechte, Daten

- Modul **`arbeitsvorrat`** mit Manifest, eigenem Schema `arbeitsvorrat` (Einträge, Beiträge, Verknüpfung zu
  Buchungen, Regeln), Schreiben nur über Funktionen mit Protokoll (F48, F64). Abhängig von `buchen`
  (Geschäftsvorfälle, Maske).
- Die Buchungsmaske bleibt im Modul `buchen`; sie bekommt den Eintrag über einen Parameter und holt die
  Vorbelegung über REST. Ohne das Modul `arbeitsvorrat` ändert sich an der Maske nichts.
- Rechte: `arbeitsvorrat.eintrag.sehen`, `.anlegen`, `.bearbeiten` (Erfasser, Buchhalter, Verantwortlicher,
  Steuerberater), `arbeitsvorrat.regel.pflegen` (Buchhalter, Verantwortlicher, Steuerberater).
- **Daten:** Angaben und Vorschläge können besondere Kategorien nach Art. 9 DSGVO enthalten (Rechnung
  eines Patienten). Sie bleiben in der Mandanten-Datenbank; Logs und Ereignisse nennen nur IDs, Quelle,
  Schlüssel und Codes. Das Protokoll hält Zustandswechsel und Regeländerungen fest.

## 10. Erste Stufe und was folgt

| Schritt | Inhalt |
|---|---|
| **0.7.3** | Modul `arbeitsvorrat`: Einträge, Beiträge, Zustände, Vertrag für Quellen, Quelle „von Hand“, Liste, vorbefüllte Maske mit Sprung zum nächsten Eintrag, Regeln |
| danach | Quelle nopaper (Annahme der Sidecars), Modul `paperless` als Quelle, später `bank` und `e-rechnung` – jede für sich, ohne Änderung am Arbeitsvorrat |

## 11. Entscheidungen zur Freigabe

| # | Frage | Vorschlag |
|---|---|---|
| W1 | Schnitt | **Vorgabe vom 2026-11-17:** abstraktes Modul `arbeitsvorrat` als Posteingang, unabhängig von der Quelle. Es ersetzt `belegeingang`. |
| W2 | Eintrag | Ein Eintrag je Vorgang mit eigener ID; Felder wie Abschnitt 2, alle außer dem Betreff freiwillig. |
| W3 | Beiträge | Je Quelle ein Beitrag mit Schlüssel, Angaben und Vorschlag; der zuerst eingegangene Wert gilt, Abweichungen stehen als Hinweis. |
| W4 | Zusammenführen | Nur über denselben Beleglink von selbst; alles andere später als bestätigter Vorschlag. |
| W5 | Vertrag für Quellen | einliefern und zurückziehen über die Modul-API; befragte Quellen über `arbeitsvorrat_abgleich` beim Öffnen, nach bestem Bemühen. |
| W6 | Rückmeldung | Ereignisse gebucht / verworfen / wieder offen nur mit IDs; die Quelle entscheidet, was sie tut. |
| W7 | Zustände | offen, in Arbeit, gebucht, verworfen; in Arbeit und gebucht aus den Buchungen abgeleitet; Storno öffnet wieder (bisher B5, B9). |
| W8 | Wem gehört ein Eintrag? | **Entschieden am 2026-11-17: dem Mandanten.** Jeder mit dem Recht sieht jeden Eintrag; eine Zuordnung an einzelne Nutzer gibt es nicht. Auch was eine Quelle mit dem Zugang eines Nutzers holt (Paperless), liegt danach im Vorrat des Mandanten. |
| W9 | Von Hand | Einträge lassen sich ohne Quelle anlegen; Betreff ist Pflicht. |
| W10 | Vorbefüllen | wie Abschnitt 6; Betrag ist der Bruttobetrag, bis es das Modul `steuer` gibt (bisher B7). |
| W11 | Regeln | Art → Geschäftsvorfall gepflegt, Gegenpartei → Konto gemerkt, einsehbar und löschbar (bisher B10). |
| W12 | Aufbewahren | Einträge werden nie gelöscht; verworfen bleibt sichtbar. |
| W13 | Quelle nopaper | **Entschieden am 2026-11-17:** nopaper ist eine Quelle, die den Arbeitsvorrat füllt – die Sidecars sind genau das, was noch zu buchen ist. Jeder angenommene Sidecar wird ein Beitrag. Umsetzung als eigenes kleines Modul `nopaper` mit dem Endpunkt `PUT /m/{mandant}/api/v1/nopaper/sidecars/{paperless_id}`; der Auftrag an nopaper wird im Pfad angepasst, bevor er übergeben wird. |
| W14 | Umfang 0.7.3 | wie Abschnitt 10: der Arbeitsvorrat mit der Quelle „von Hand“; die Quellen folgen einzeln. |

Die übrigen Punkte (W1–W7, W9–W12, W14) sind mit dem Auftrag zum Bauen vom 2026-11-17 wie vorgeschlagen freigegeben.

## 12. Festlegungen aus der Umsetzung (0.7.3, zur Bestätigung)

1. **Zustand „zurückgezogen“:** Ein unbearbeiteter Eintrag, dessen einziger Beitrag zurückgezogen wurde, wird
   nicht gelöscht (W12), sondern bekommt intern den Zustand `zurueckgezogen` und erscheint in keiner Ansicht.
   Liefert die Quelle erneut, ist er wieder offen.
2. **Abgleich beim Lesen:** Ob ein Eintrag in Arbeit, gebucht oder wieder offen ist, stellt das Modul beim Öffnen
   der Liste und eines Eintrags fest – es fragt den Stand der verknüpften Buchungen über die Modul-API des Kerns
   (`Kumero\Api\V1\Dienste::buchungsstaende`) und lässt die Datenbankfunktion `arbeitsvorrat.abgleichen`
   entscheiden. Einen Hintergrunddienst braucht es dafür nicht. Der Zähler im Menü gleicht nicht ab.
3. **Rückmeldung an Quellen:** Die Meldungen gebucht / verworfen / wieder offen stehen als Ereignisse im
   Protokoll des Mandanten. Zugestellt werden sie, sobald der Kern Ereignisse an Module verteilt; bis dahin
   kann eine Quelle sie beim Abgleich selbst abfragen.
4. **`herkunft_ref` am Entwurf** wird noch nicht gesetzt; die Verknüpfung liegt nur beim Eintrag. Dafür müsste
   der Kern das Feld über REST annehmen.
5. **Liste ohne Cursor:** höchstens 500 Einträge je Ansicht, älteste zuerst, mit Hinweis, wenn es mehr gibt.
6. **Vorbelegung:** Der Eintrag nennt den Geschäftsvorfall der Regel und das gemerkte Konto der Gegenpartei;
   angewendet werden beide in der Maske. Das gemerkte Konto steht bei Ausgaben im Soll, bei Einnahmen im
   Haben; gemerkt wird nur bei einfachen Buchungen (nicht bei Splitbuchungen).
7. **Buchungstext:** Gegenpartei und Belegnummer; ohne Gegenpartei der Betreff.
8. **Unbar ohne Zahltag:** Das Buchungsdatum bleibt leer, mit Hinweis am Feld. Als Entwurf lässt sich das
   speichern; festschreiben verlangt das Datum.
9. **„Überspringen“** in der Maske lässt den Eintrag offen liegen und öffnet den nächsten.
10. **Verwerfen** geht nur bei offenen Einträgen; zu einem Eintrag in Arbeit ist erst der Entwurf zu löschen.
11. **Erweiterungspunkt `arbeitsvorrat_abgleich`:** Eine befragte Quelle nennt sich im Manifest unter
    `"arbeitsvorrat_quelle": {"titel": "…"}` und implementiert `Kumero\Modul\Arbeitsvorrat\Quelle`. Eingeliefert
    wird über `Kumero\Modul\Arbeitsvorrat\Dienst` (`einliefern`, `zurueckziehen`). Bisher gibt es keine Quelle
    außer „von Hand“; geprüft ist der Vertrag über den Dienst.
12. **Zähler im Menü:** Der Rahmen zeigt den im Manifest genannten Zähler (OBERFLAECHE.md `zaehler`) jetzt an.

## 13. Ergänzungen nach dem ersten Lauf mit Paperless (Entwurf 2)

Vorgaben des Verantwortlichen vom 2026-11-18. Sie ändern Beschreibung und Aufbau des Moduls.

**Seit kumero 0.7.13 (Modul `arbeitsvorrat` 0.5.0, F105):** X3 Stufe 3 ist gebaut – „Vergleichen“ zeigt zwei markierte Einträge links und rechts mit Detaildaten und Belegbild; je Feld wählbar, welcher Wert gilt (Vorschlag: Belegangaben vom Beleg, Geldfluss vom Umsatz); „Zusammenführen“, „Kein Duplikat“, „Trennen“. Das Zusammenführen von selbst ist gebaut und getestet, je Mandant schaltbar und **ausgeschaltet**. Neuer Zustand `zusammengefuehrt` für den aufgegangenen Eintrag; er erscheint in keiner Ansicht.

**Seit kumero 0.7.12 (Modul `arbeitsvorrat` 0.4.0, F104):** Spalte „Doppelt?“ markiert Einträge, die vermutlich denselben Vorgang meinen (X3 Stufe 3: gleicher Betrag und gleiche Gegenpartei oder Belegnummer) – sortierbar und filterbar; zusammengeführt wird noch nicht. Quellen dürfen ein Geldkonto nennen, das je nach Richtung als Soll oder Haben vorbelegt wird (IMPORT.md C5). Neue Quelle: Modul `import` (CSV-Dateien).

**Seit kumero 0.7.8 (Modul `arbeitsvorrat` 0.3.1, F100):** Die Liste lädt ohne die Quellen; der Abgleich läuft im Hintergrund (beim Öffnen höchstens alle fünf Minuten, sonst „Jetzt abgleichen“). Aktionen gelten für die markierten Einträge, ohne Haken für die gewählte Zeile. „Konten leeren“ löscht die Vorbelegung der Auswahl.

**Stand kumero 0.7.7 (Modul `arbeitsvorrat` 0.3.0):**

| Punkt | Stand in 0.7.7 |
|---|---|
| X1 Stapelvorbelegung | **gebaut:** Konto, Gegenkonto und Buchungsart für alle markierten Einträge auf einmal; Tags noch nicht |
| X1a Übergabe in den Stapel | **gebaut:** markierte, vollständige Einträge werden freigabebereite Entwürfe; unvollständige bleiben und werden genannt |
| X1b Regel aus der Vorauswahl | nicht gebaut, nicht entschieden |
| X2 Splitbuchung mit Steuer | **gebaut (0.7.7):** Netto und Steuerbetrag am Eintrag, Steuerkonto in der Stapelvorbelegung; mit Steuerkonto und bekannten Beträgen Splitbuchung in der Maske und dreizeilige Entwürfe im Stapel, sonst brutto. **Offen:** X2b als eigener Schalter des Mandanten – derzeit entscheidet das gewählte Steuerkonto (zu bestätigen) |
| X3 Deduplizieren | **gebaut:** gleicher Link oder gleiche Prüfsumme führt von selbst zusammen (0.7.7); Markierung „Doppelt?“ (0.7.12); Vergleich, Zusammenführen, „Kein Duplikat“, Trennen (0.7.13); Zusammenführen von selbst gebaut, aber ausgeschaltet |
| X4 Schon gebucht? | **gebaut für sichere Treffer:** gleicher Link oder gleiche Belegprüfsumme an einer festgeschriebenen, nicht stornierten Buchung; der Hinweis über Belegnummer und Betrag fehlt |
| X5 Filter und Sortieren | **gebaut** als gemeinsamer Baustein (F91), eingesetzt in der Liste des Arbeitsvorrats |
| X6 Vorschau beim Überfahren | **gebaut**, abstrakt über das Belegarchiv (F97); seit 0.7.7 am Augen-Symbol in eigener Spalte, die Vorschau hängt rechts neben dem Mauszeiger; Taste V auf der gewählten Zeile |
| X7 Konten in der Liste | **gebaut:** Konto und Gegenkonto je Zeile, Massenauswahl („Alle markieren“, Leertaste); seit 0.7.7 übernimmt auch die Buchungsmaske die Vorauswahl |

Festlegungen aus der Umsetzung, zu bestätigen: Die Vorauswahl gilt nur für offene Einträge. Konto ist immer Soll,
Gegenkonto immer Haben – auch bei Richtung „Einnahme“ wird im Stapel nichts getauscht. Buchungstext der
übergebenen Entwürfe: Gegenpartei und Belegnummer, sonst der Betreff. Buchungsdatum: bei „bar“ und ohne Angabe
das Belegdatum, bei „unbar“ der Zahltag – fehlt er, bleibt der Eintrag im Vorrat. Ohne gewählte Buchungsart gilt
die erste des Mandanten. Die Konten werden in der Liste über die Vorschlagsliste des Browsers gewählt, nicht über
die Kontenvorschau der Buchungsmaske. Die Übergabe legt die Entwürfe einzeln über REST an; bricht sie ab, sind
die schon übergebenen Einträge „in Arbeit“.

### X1. Stapelvorbelegung: Konto und Gegenkonto für viele Einträge auf einmal

- Die Liste des Arbeitsvorrats bekommt die **Filter je Spalte** und das Sortieren nach F91 (Wert finden, Wert
  ausschließen). Der Filter ist das Werkzeug, um gleichartige Einträge zusammenzuholen – z. B. alle Belege
  einer Gegenpartei oder einer Art.
- Für die **gefilterten und markierten** Einträge lassen sich **Konto und Gegenkonto vorauswählen** – in einem
  Schritt für alle. Vorschlag: zusätzlich Buchungsart und Tags, weil sie denselben Zweck haben.
- Das ist eine **Vorbelegung, keine Buchung**: Die Werte stehen am Eintrag und belegen später die Maske vor;
  gesichtet und gebucht wird weiter je Eintrag (F31, F51 Nr. 3).
- Aufbau: Der Eintrag bekommt eigene Felder für die **Vorauswahl des Nutzers** (Konto, Gegenkonto,
  Buchungsart, Tags), getrennt von den Vorschlägen der Quellen. Rangfolge beim Vorbelegen der Maske:
  Vorauswahl am Eintrag → gemerktes Konto der Gegenpartei → Geschäftsvorfall zur Art.
- Die Stapelvorbelegung steht im Protokoll (wer, wie viele Einträge, welche Werte); sie lässt sich für die
  Auswahl wieder leeren. Ob sie auch eine Regel anlegen soll („für diese Gegenpartei künftig immer“), ist zu
  entscheiden.
- Zu klären: ob nach der Vorauswahl auch **Stapelbuchen** gewünscht ist (alle vorbelegten Einträge als
  Entwürfe anlegen und gemeinsam freigeben) oder ob jeder Eintrag einzeln durch die Maske geht.

### X2. Netto und Steuer: Vorbelegen als Splitbuchung

- Der Eintrag kennt künftig neben dem Betrag (brutto) auch **Netto** und **Steuerbeträge je Satz**.
- Liegen sie vor, wird die Maske als **Splitbuchung** vorbelegt (F4): bei einer Ausgabe Aufwand netto im
  Soll, Vorsteuer je Satz im Soll, Zahlungsmittel bzw. Verbindlichkeit brutto im Haben; bei einer Einnahme
  entsprechend mit Umsatzsteuer im Haben.
- Stimmen Netto + Steuer nicht mit Brutto überein, wird nicht geraten: Die Maske zeigt die Abweichung als
  Hinweis, die Restsumme steht sichtbar daneben.
- **Offen:** Welches Konto zu welchem Steuersatz gehört (Vorsteuer 19 %, 7 %, Umsatzsteuer …), weiß der Kern
  nicht; das Modul `steuer` gibt es noch nicht. Vorschlag bis dahin: eine dritte Regel im Arbeitsvorrat
  „Steuersatz und Richtung → Konto“, vom Nutzer gepflegt. Ebenso offen: Betriebe ohne Vorsteuerabzug
  (z. B. steuerfreie Heilbehandlungen nach § 4 Nr. 14 UStG) buchen brutto – das braucht einen Schalter je
  Mandant oder je Art.

### X3. Deduplizieren

- Derselbe Vorgang kann aus mehreren Quellen kommen (Paperless und nopaper) und in einer Quelle doppelt
  liegen (zweimal gescannt). Bisher fügt nur derselbe Beleglink zusammen (W4).
- Stufen des Abgleichs, von sicher nach unsicher:
  1. **derselbe Beleglink** → derselbe Eintrag (wie bisher);
  2. **dieselbe Datei** (Belegprüfsumme des Originals gleich – MD5 bzw. SHA-256 –, auch unter anderem Link) → derselbe Eintrag;
  3. **dieselben Belegangaben** (Gegenpartei, Belegnummer, Betrag, Datum gleich) → nur **Vorschlag** „vermutlich
     doppelt“, den ein Mensch bestätigt oder ablehnt.
- Stufe 1 und 2 führen von selbst zusammen, Stufe 3 nie. Ein abgelehnter Vorschlag wird gemerkt und nicht
  wieder gestellt.
- Quellen liefern dafür, soweit bekannt, den SHA-256 des Originals mit (nopaper hat ihn im Sidecar, Paperless
  nach der Prüfung).

### X4. Abgleich mit dem Journal: schon gebucht und nicht storniert?

- Beim Einliefern und beim Abgleich wird geprüft, ob es zu einem Eintrag **bereits eine festgeschriebene,
  nicht stornierte Buchung** gibt – auch eine, die nicht aus dem Arbeitsvorrat entstanden ist (von Hand
  gebucht, importiert).
- Erkennung: sicher über den **Belegverweis** (gleicher Link oder gleiche Belegprüfsumme – MD5 bzw. SHA-256 des Originals – an einer Buchung, F88);
  als Hinweis über Belegnummer, Betrag und Datum.
- Folge: Bei sicherem Treffer gilt der Eintrag als **gebucht** und wird mit dieser Buchung verknüpft; er
  erscheint nicht als offen. Bei unsicherem Treffer bleibt er offen, mit dem Hinweis „möglicherweise schon
  gebucht: Nr. …“. Ist die gefundene Buchung storniert, zählt sie nicht.
- Dafür braucht die Modul-API des Kerns eine weitere Auskunft: Buchungen zu einem Belegverweis und zu
  Belegnummer und Betrag, mit Stand und Storno (Ergänzung zu `Kumero\Api\V1\Dienste`).

### X6. Vorschau des Belegs beim Überfahren

- Hat ein Eintrag einen Beleg, zeigt die Liste beim **Überfahren mit der Maus** eine **Vorschau des Belegs**.
- **Abstrakt (F97, entschieden am 2026-11-18):** Maßgeblich ist nicht die Quelle des Eintrags, sondern der
  **Belegverweis**. Zu jedem Beleglink, für den ein angebundenes DMS zuständig ist, gibt es die Vorschau – auch
  bei einem von Hand angelegten Eintrag oder einem aus nopaper. Der folgende Satz gilt entsprechend für das
  DMS-Modul statt für die Quelle.
- Der Arbeitsvorrat bleibt dabei abstrakt (F89): Er lädt nie selbst einen Beleg. Die **Quelle bietet die
  Vorschau an** – ein Modul meldet an, dass es zu den Belegen seiner Einträge ein Vorschaubild liefern kann
  (bei Paperless das vorhandene durchgereichte Bild, PAPERLESS.md Abschnitt 5). Kann keine Quelle eine
  Vorschau liefern, bleibt es beim Link.
- Die Vorschau erscheint neben der Zeile, ohne die Tabelle zu verschieben (M12), verschwindet beim Verlassen
  und ist auch **ohne Maus** erreichbar (Tastatur auf der gewählten Zeile, PROFIL.md 4a). Ein Klick öffnet die
  große Vorschau.
- Belege können besondere Kategorien von Daten enthalten: geladen wird erst beim Überfahren, nichts wird
  zwischengespeichert; ist die Quelle gesperrt oder nicht erreichbar, steht das kurz an der Stelle der Vorschau.

### X7. Konto und Gegenkonto schon in der Liste wählen

- In der Liste des Arbeitsvorrats lassen sich **Konto und Gegenkonto je Eintrag direkt auswählen** – als eigene
  Spalten mit der Kontenvorschau beim Tippen (M9), ohne die Maske zu öffnen.
- Das ist dieselbe **Vorauswahl am Eintrag** wie bei der Stapelvorbelegung (X1): einzeln in der Zeile oder für
  viele auf einmal. Gebucht wird damit noch nichts.
- **Stapelfähig (entschieden am 2026-11-18):** Einträge lassen sich in Massen auswählen (markieren, alle
  gefilterten markieren) und gemeinsam **in die Stapelbuchung vorbereiten** (X1a): Aus den vorbelegten
  Einträgen werden Entwürfe im Erfassungsstapel; freigegeben wird dort.
- Zu klären: ob neben Konto und Gegenkonto weitere Felder in der Liste änderbar sein sollen (Buchungsart,
  Betrag, Datum).

### Entscheidungen zur Freigabe

| # | Frage | Vorschlag |
|---|---|---|
| X1 | Stapelvorbelegung | **Vorgabe vom 2026-11-18:** nach gesetztem Filter Konto und Gegenkonto für alle gewählten Einträge vorauswählen. Vorschlag: auch Buchungsart und Tags; Vorauswahl am Eintrag, nie Buchung. |
| X1a | Stapelbuchen | **Entschieden am 2026-11-18 (mit X7):** Die markierten, vorbelegten Einträge gehen **gemeinsam in die Stapelbuchung** – aus jedem wird ein Entwurf im Erfassungsstapel (F31), der Eintrag gilt als „in Arbeit“. Festgeschrieben wird wie bisher über die Stapelfreigabe mit persönlicher Bestätigung (F38, F67); der Arbeitsvorrat schreibt nie selbst fest. Einträge, denen für eine Buchung etwas fehlt (Konto, Betrag, Buchungsdatum), werden genannt und bleiben im Vorrat. Zu bestätigen: ob die Entwürfe dabei gleich vorbereitet werden (freigabebereit, F66) oder als Entwurf im Stapel liegen. |
| X1b | Regel aus der Vorauswahl | offen: beim Vorauswählen wahlweise als Regel für die Gegenpartei merken. |
| X2 | Splitbuchung | **Vorgabe vom 2026-11-18:** bei Netto und Steuerbeträgen als Splitbuchung mit Vorsteuer bzw. Umsatzsteuer vorbelegen. |
| X2a | Steuerkonten | **Entschieden am 2026-11-18:** keine eigene Regel – das löst die **Stapelvorbelegung**: Auch die Steuerkonten der Splitzeilen werden für die gewählten Einträge im Stapel vorausgewählt (X1). Folge für den Aufbau: Die Vorauswahl am Eintrag kennt neben Konto und Gegenkonto je Steuerzeile ein Konto. |
| X2b | Ohne Vorsteuerabzug | **Entschieden am 2026-11-18:** eine **Voreinstellung des Mandanten** (brutto buchen oder mit Steuer aufteilen) – und im Einzelfall eine **Frage der Buchung**: Auch ein Betrieb mit steuerfreien Leistungen hat steuerpflichtige Geschäftsvorfälle (z. B. Gutachten, Verkauf von Waren). Die Voreinstellung belegt nur vor; je Eintrag und im Stapel lässt sich zwischen „brutto“ und „aufgeteilt“ wechseln. |
| X3 | Deduplizieren | **Vorgabe vom 2026-11-18.** Link und Prüfsumme führen zusammen, gleiche Belegangaben werden nur vorgeschlagen. **Muss stapelfähig sein** (entschieden am 2026-11-18): Vorschläge „vermutlich doppelt“ lassen sich gefiltert und markiert in einem Schritt bestätigen oder ablehnen, nicht nur einzeln. |
| X4 | Schon gebucht? | **Vorgabe vom 2026-11-18:** Abgleich mit dem Journal. **Sicher** ist der Treffer über den Belegverweis – gleicher Link **oder gleiche Belegprüfsumme** (MD5 bzw. SHA-256 des Originals, entschieden am 2026-11-18); Belegnummer und Betrag ergeben nur einen Hinweis. Stornierte Buchungen zählen nicht. |
| X6 | Vorschau beim Überfahren | **Vorgabe vom 2026-11-18, als abstraktes Merkmal (F97):** Die Vorschau funktioniert **immer, wenn ein Beleg aus einem DMS vorhanden ist** – unabhängig davon, aus welcher Quelle der Eintrag kam und welches DMS es ist. Sie ist ein allgemeiner Baustein, kein Sonderfall des Arbeitsvorrats oder von Paperless. Auch per Tastatur erreichbar. |
| X7 | Konten in der Liste | **Vorgabe vom 2026-11-18:** Konto und Gegenkonto sind schon im Arbeitsvorrat je Eintrag wählbar – dieselbe Vorauswahl wie im Stapel (X1). **Stapelfähig** (entschieden am 2026-11-18): Massenauswahl in der Liste und **Vorbereitung in die Stapelbuchung**. |
| X5 | Filter und Sortieren | Die Liste bekommt den Baustein aus F91; er ist Voraussetzung für X1 und wird vorher beschrieben (P19). |
