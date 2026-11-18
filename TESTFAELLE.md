# TESTFAELLE.md – Fachliche Testfälle (Entwurf 1, Ergebnisse noch festzulegen)

Stand: 2026-11-18. Bezug: F103 (Integrationstests gemeinsam), F101 (Steuer), F95 (Zuordnung Paperless),
F89/F96 (Arbeitsvorrat), F88 (Belegverweis), F30/F31 (Storno, Stapel).

**Arbeitsweise:** Die Fälle beschreibt Claude. **Das erwartete Ergebnis legt der Verantwortliche fest** – bis dahin
ist die Spalte „Erwartet“ leer und der Fall gilt als *offen*. Ein Fall mit festgelegtem Ergebnis ist *gültig*;
weicht kumero davon ab, ist das ein Fehler in kumero oder ein Grund, die Vorgabe zu ändern – nie umgekehrt still
angepasst. Rechenfälle (Gruppe S) werden nach der Festlegung zusätzlich als Unittests hinterlegt.

**Daten:** nur synthetisch (F75 Nr. 9). Alle Fälle nutzen den Mustermandanten unten. Belege sind eigens erzeugte
Musterbelege, keine echten. **Die Musterbelege erzeugt der Verantwortliche außerhalb dieses Projekts**
(entschieden am 2026-11-18); die Tabelle in Abschnitt B ist die Vorgabe, welche Belege gebraucht werden.

## 0. Mustermandant

| Konto | Bezeichnung | Kontoart | Steuersatz |
|---|---|---|---|
| 1000 | Kasse | aktiv | – |
| 1200 | Bank | aktiv | – |
| 1400 | Abziehbare Vorsteuer 19 % | aktiv | 19 |
| 1401 | Abziehbare Vorsteuer 7 % | aktiv | 7 |
| 1776 | Umsatzsteuer 19 % | passiv | 19 |
| 4930 | Bürobedarf | aufwand | – |
| 4940 | Zeitschriften, Bücher | aufwand | – |
| 8100 | Honorare (steuerfrei) | ertrag | – |
| 8400 | Erlöse 19 % (z. B. Gutachten) | ertrag | – |

Buchungsarten `BANK` und `KASSE`, Geschäftsjahr 2026, Testbetrieb. Betragseingabe „dezimal“, sofern nicht anders genannt.

## S. Steuer in der Splitbuchung (F101)

Schreibweise der Eingabe: Konto / Betrag / Gegenkonto, danach „Aufteilen“ und die genannten Zeilen.
Schreibweise des Ergebnisses: je Zeile `Konto Seite Betrag`.

| Nr. | Fall | Eingabe | Erwartet (legt der Verantwortliche fest) |
|---|---|---|---|
| S1 | Vorsteuer 19 % aus dem Brutto | 4930 / 119,00 / 1200; Zeile 1400 | |
| S2 | Vorsteuer 7 % aus dem Brutto | 4940 / 107,00 / 1200; Zeile 1401 | |
| S3 | Rundung, kleiner Betrag | 4930 / 10,37 / 1000; Zeile 1400 | |
| S4 | Rundung, Betrag mit halbem Cent in der Rechnung | 4930 / 0,05 / 1000; Zeile 1400 | |
| S5 | Rundung, großer Betrag | 4930 / 123.456,78 / 1200; Zeile 1400 | |
| S6 | Weitere Aufwandszeile, gleicher Satz | wie S1; dazu Zeile 4940 Soll 40,00 | |
| S7 | Zwei weitere Aufwandszeilen | wie S1; dazu 4940 Soll 40,00 und 4930 Soll 10,00 | |
| S8 | Aufwandszeilen größer als das Netto | wie S1; dazu Zeile 4940 Soll 150,00 | |
| S9 | Restzeile von Hand überschrieben | wie S6; danach in der ersten Zeile 70,00 eintippen | |
| S10 | Steuerbetrag von Hand überschrieben | wie S1; in der Zeile 1400 den Betrag 18,00 eintippen | |
| S11 | Von Hand gesetzten Betrag wieder leeren | wie S10; danach das Feld der Zeile 1400 leeren | |
| S12 | Einnahme mit Umsatzsteuer | 1200 / 119,00 / 8400; Zeile 1776 | |
| S13 | Steuerfreie Einnahme, kein Steuerkonto | 1200 / 80,00 / 8100; „Aufteilen“ ohne Steuerzeile | |
| S14 | Haken „netto“, eine Aufwandszeile | wie S1 ohne Rechnen: Haken netto, 4930 Soll 100,00, Zeile 1400, 1200 im Haben | |
| S15 | Haken „netto“, zwei Aufwandszeilen | Haken netto; 4930 Soll 60,00, 4940 Soll 40,00, Zeile 1400, 1200 im Haben | |
| S16 | Haken „netto“ nachträglich setzen | wie S1; danach den Haken setzen | |
| S17 | Zwei Steuersätze auf einem Beleg | 4930 / 226,00 / 1200; Zeilen 1400 und 1401, 4940 | |
| S18 | Steuerkonto ohne hinterlegten Satz | Satz an 1400 gelöscht; wie S1 | |
| S19 | Geld im Soll, Aufwand im Haben (vertauscht eingegeben) | 1000 / 10,37 / 4930; Zeile 1400 | |
| S20 | Gespeicherten Splitentwurf wieder öffnen | S6 als Entwurf speichern, wieder öffnen, nichts ändern | |
| S21 | Steuersatz ändern, alter Entwurf | S1 als Entwurf speichern; Satz an 1400 auf 16 ändern; Entwurf öffnen | |
| S22 | Kaufmännische Eingabe | Betragseingabe „kaufmännisch“; 4930 / `11900` / 1200; Zeile 1400 | |

Zu klären mit S8, S17, S19: Was soll kumero dort tun – rechnen, nicht rechnen, hinweisen?

## A. Arbeitsvorrat → Buchung (F89, F96)

Einträge werden von Hand angelegt, sofern nicht anders genannt.

| Nr. | Fall | Ausgangslage und Schritte | Erwartet (legt der Verantwortliche fest) |
|---|---|---|---|
| A1 | Barbeleg | Eintrag: Kassenbon, 12,50, Datum 03.03.2026, bar. Buchen öffnen. | |
| A2 | Unbar ohne Zahltag | Eintrag: Rechnung, 109,43, Datum 02.03.2026, unbar, kein Zahltag. Buchen öffnen, „Buchen“ drücken. | |
| A3 | Unbar mit Zahltag | wie A2, Zahltag 10.03.2026. Buchen öffnen. | |
| A4 | Vorbelegung einzeln | In der Liste Konto 4930 und Gegenkonto 1200 eintragen, Buchen öffnen. | |
| A5 | Vorbelegung mit Steuerkonto, ohne Werte vom Beleg | Eintrag 119,00; Konto 4930, Gegenkonto 1200, Steuerkonto 1400. Buchen öffnen. | |
| A6 | Vorbelegung mit Steuerkonto, Werte vom Beleg stimmen | Eintrag 119,00, Netto 100,00, Steuer 19,00; sonst wie A5. | |
| A7 | Werte vom Beleg stimmen nicht | Eintrag 119,00, Netto 100,00, Steuer 18,00; sonst wie A5. | |
| A8 | Beleg mit gemischten Sätzen | Eintrag 226,00, Netto 200,00, Steuer 26,00; Steuerkonto 1400. | |
| A9 | Einnahme | Eintrag Richtung „Einnahme“, 119,00; Konto 1200, Gegenkonto 8400, Steuerkonto 1776. Buchen öffnen. | |
| A10 | Stapel: vollständig | Drei Einträge mit Konto, Gegenkonto, Betrag, Datum, bar. Markieren, „In den Stapel“. | |
| A11 | Stapel: einer unvollständig | wie A10, einem fehlt das Gegenkonto. | |
| A12 | Stapel ohne Haken | Nichts markiert, eine Zeile gewählt, „In den Stapel“. | |
| A13 | Stapel mit Steuerkonto | wie A5, aber „In den Stapel“ statt Buchen öffnen. | |
| A14 | Konten leeren | Zwei vorbelegte Einträge markieren, „Konten leeren“. | |
| A15 | Entwurf löschen | Eintrag in Arbeit; den Entwurf in der Buchungsliste löschen; Arbeitsvorrat öffnen. | |
| A16 | Festschreiben | Entwurf aus A10 im Stapel freigeben; Arbeitsvorrat, Ansicht „gebucht“. | |
| A17 | Storno | Buchung aus A16 stornieren und den Storno festschreiben; Arbeitsvorrat öffnen. | |
| A18 | Schon gebucht, gleicher Beleg | Buchung mit Beleglink von Hand festschreiben; danach Eintrag mit demselben Link anlegen. | |
| A19 | Gleiche Datei unter anderem Link | Zwei Quellen liefern verschiedene Links mit gleicher SHA-256. | |
| A20 | Verwerfen und wieder öffnen | Offenen Eintrag mit Grund verwerfen, wieder öffnen. | |
| A21 | Gemerktes Konto der Gegenpartei | A4 festschreiben; neuer Eintrag derselben Gegenpartei; Buchen öffnen. | |
| A22 | Vorauswahl gegen gemerktes Konto | wie A21, zusätzlich in der Liste ein anderes Konto vorbelegt. | |

## P. Paperless: Zuordnung der Felder (F95)

Paperless-Testinstanz mit den benutzerdefinierten Feldern „Erkannt: Belegart“ (Text), „Erkannt: Rechnungsnummer“
(Text), „Erkannt: Rechnungsdatum“ (Datum), „Erkannt: Rechnungssteller“ (Text), „Erkannt: Betrag brutto“,
„Erkannt: Betrag netto“, „Erkannt: Betrag Steuer“ (Geldbetrag), „Zahlart“ (Auswahl: bar, unbar). Zuordnung: der
Vorschlag von kumero, dazu Zahlart → Zahlweise und Tag „zu buchen“ → Richtung Ausgabe.

| Nr. | Fall | Dokument in Paperless | Erwartet im Arbeitsvorrat (legt der Verantwortliche fest) |
|---|---|---|---|
| P1 | Alle Felder gefüllt | Rechnung, alle Felder, Euro, Zahlart unbar | |
| P2 | Nur feste Felder | Titel, Korrespondent, Erstelldatum, Dokumenttyp; keine benutzerdefinierten Felder | |
| P3 | Erste Quelle leer | „Erkannt: Rechnungssteller“ leer, Korrespondent gesetzt | |
| P4 | Betrag in Fremdwährung | „Erkannt: Betrag brutto“ in USD | |
| P5 | Nur Netto | Brutto und Steuer leer, Netto gefüllt | |
| P6 | Netto und Steuer, kein Brutto | Brutto leer | |
| P7 | Netto + Steuer ≠ Brutto | drei Beträge, die nicht aufgehen | |
| P8 | Betrag 0,00 | Brutto 0,00 | |
| P9 | Belegart mit Großbuchstaben und Umlaut | „Erkannt: Belegart“ = „Bewirtungsbeleg Überweisung“ | |
| P10 | Rechnungsnummer länger als 36 Zeichen | 50 Zeichen | |
| P11 | Tag fehlt | Dokument ohne das Tag für offene Belege | |
| P12 | Eigene Zuordnung gegen Vorgabe | Nutzer ordnet Gegenpartei nur dem Korrespondenten zu | |
| P13 | Zuordnung geändert, Eintrag in Arbeit | Zuordnung ändern, während zu einem Beleg ein Entwurf besteht | |
| P14 | Feld in Paperless umbenannt | „Erkannt: Rechnungsnummer“ → „Rechnungsnr.“ | |
| P15 | Gebucht → Tag | Beleg aus P1 buchen und festschreiben, abgleichen | |
| P16 | Storno → Tag | Buchung aus P15 stornieren, abgleichen | |
| P17 | Tag von Hand entfernt | Tag für offene Belege in Paperless entfernen, abgleichen | |
| P18 | Belegprüfung | Beleg aus P1 in der Maske: Kennzeichen am Feld „Beleg / Link“ | |
| P19 | Paperless gesperrt | Zugang sperren, Arbeitsvorrat öffnen, „Jetzt abgleichen“ | |
| P20 | Abgleich innerhalb der Frist | Neues Dokument taggen, Arbeitsvorrat nach zwei Minuten erneut öffnen | |

## B. Musterbelege für einen Durchlauf von Ende zu Ende

Jeder Beleg ist ein eigens erzeugter Musterbeleg mit erfundenem Aussteller. **Er läuft durch die ganze Kette**
(entschieden am 2026-11-18): **nopaper** (Erkennen, Felder „Erkannt: …“, Sidecar) → **Paperless** (Ablage, Tag für
offene Belege) → **kumero** (Arbeitsvorrat, Buchungsmaske, Festschreiben). Geprüft wird also nicht nur kumero,
sondern was am Ende der Kette herauskommt. Das Ergebnis ist die festgeschriebene Buchung.

Je Beleg wird im Protokoll festgehalten, an welcher Stelle der Kette eine Abweichung entsteht: in nopaper
(falsch erkannt), in Paperless (Feld fehlt oder hat eine andere Form), in der Zuordnung von kumero oder in der
Buchungsmaske. Stand kumero 0.7.11: kumero liest die Felder, die nopaper in Paperless schreibt; die Annahme der
Sidecars (Modul `nopaper`) ist noch nicht gebaut.

| Nr. | Beleg | Besonderheit | Erwartete Buchung (legt der Verantwortliche fest) |
|---|---|---|---|
| B1 | Rechnung Bürobedarf 119,00 | ein Satz 19 %, unbar | |
| B2 | Kassenbon Bäckerei 4,21 | 7 %, bar, keine Rechnungsnummer | |
| B3 | Supermarkt-Bon 23,47 | gemischte Sätze 7 % und 19 %, Pfand, bar bzw. Karte | |
| B4 | Fachzeitschrift 107,00 | 7 % | |
| B5 | Rechnung aus dem EU-Ausland | ohne ausgewiesene Steuer | |
| B6 | Rechnung in USD | Fremdwährung | |
| B7 | Gutschrift eines Lieferanten | negative Rechnung | |
| B8 | Ausgangsrechnung Gutachten 119,00 | Einnahme mit Umsatzsteuer | |
| B9 | Abrechnung steuerfreier Leistungen | Einnahme ohne Steuer | |
| B10 | Rechnung mit Skonto bezahlt | Zahlbetrag kleiner als Rechnungsbetrag | |
| B11 | Derselbe Beleg zweimal gescannt | zwei Dokumente, gleiche Datei | |
| B12 | Rechnung über Anlagegut | Betrag über der Grenze für geringwertige Wirtschaftsgüter | |

Zu B5, B6, B7, B10, B12: Diese Fälle kann kumero heute vermutlich nicht vollständig abbilden. Sie stehen hier, damit
die Erwartung festliegt, bevor gebaut wird.

## Protokoll der Durchläufe

| Datum | Version | Fälle | Ergebnis | Abweichungen |
|---|---|---|---|---|
| | | | | |
