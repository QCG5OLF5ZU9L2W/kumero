# FAKTURA.md – Kunden, Projekte und Rechnungen (Entwurf, Fassung 1)

Stand: 2026-11-21. Bezug: LEISTUNGEN.md (LE2, LE9, Abschnitte 10 und 11), ZEIT.md (Abschnitt 10, DK5), AGENTS.md P41, F31
(Module erzeugen nur Entwürfe), F110, F135, CLAUDE.md „Grenzen“.
**Status: Entwurf zur Freigabe (P41). Nichts davon ist gebaut.**

## 1. Anlass

Vorgaben des Verantwortlichen vom 2026-11-21: „Was wir noch nicht haben, aber planen müssen, ist Faktura auf Kunden und
Projekte“; zu LE2: „intern ist die Faktura auf Kunden oder Projekte für die Zukunft zu planen“; zu LE9: „ein Modul Faktura
und Rechnungserstellung ist nur geplant, aber nicht da“. Für die Übergabe an das Lohnbüro bleibt die Leistung ohne Empfänger.

## 2. Was dazugehört

1. **Kunde:** wem etwas berechnet wird – Name, Anschrift, Ansprechpartner, Zahlungsziel, Umsatzsteuer-Angaben.
2. **Projekt:** worauf Zeiten und Leistungen laufen – Bezeichnung, Kunde (oder intern), Zeitraum, Zustand, Abrechnungsart
   (nach Aufwand, Festpreis, nicht abrechenbar).
3. **Zuordnung:** Ein Zeitabschnitt (`zeit`) und eine Leistungszeile (`leistungen`) können ein Projekt tragen; über das
   Projekt steht der Kunde fest. Ohne Projekt bleibt alles, wie es ist.
4. **Abrechnung:** aus bestätigten Leistungen und freigegebenen Zeiten eines Projekts entsteht ein Rechnungsentwurf mit
   Positionen; daraus die Rechnung mit Nummer, als PDF und als E-Rechnung.
5. **Buchhaltung:** Die Rechnung erzeugt im Buch einen Buchungsentwurf (Forderung an Erlös, Umsatzsteuer) – nie eine
   festgeschriebene Buchung (F31).

## 3. Die Grenze, die sich verschiebt

LEISTUNGEN.md Abschnitt 3 hält fest: Wer eine Leistung empfangen hat, steht nicht in kumero (Art. 9 DSGVO,
Berufsgeheimnis – Patienten einer Praxis, Klienten einer Kanzlei). **Mit Kunden an der Leistung fällt diese Grenze.** Das
ist für einen Betrieb mit gewerblichen Kunden unkritisch, für eine Praxis nicht. Der Entwurf trennt deshalb: Kunde und
Projekt sind eine Einstellung des Mandanten; ein Mandant, der sie nicht einschaltet, behält die empfängerlose Leistung.

## 4. Entscheidungen (zur Freigabe)

| # | Frage | Vorschlag |
|---|---|---|
| FK1 | Schnitt | **Zwei Module:** `projekte` (Kunden, Projekte, Zuordnung von Zeit und Leistung) am Mandanten; `faktura` (Rechnungsentwurf, Rechnung, Nummernkreis, Buchungsentwurf) am Buch – die Rechnung gehört zu einer Buchhaltung. |
| FK2 | Wer ist Kunde | Ein eigener Stamm „Kunden“ am Mandanten. Nicht die Debitoren eines Buchs: Ein Kunde kann in mehreren Büchern berechnet werden; das Buch bekommt beim Fakturieren sein Debitorenkonto. |
| FK3 | Personen als Kunden | Erlaubt, als Einstellung des Mandanten mit ausdrücklichem Hinweis; **für Patienten und Klienten bleibt die Vorgangskennung der Weg** (LE2). kumero prüft das nicht, es sagt es nur. |
| FK4 | Projekt ohne Kunde | Ja – interne Projekte, nicht abrechenbar; sie dienen der Auswertung der Zeiten. |
| FK5 | Zuordnung der Zeit | Am Abschnitt (Kommen bis Gehen) genau ein Projekt, gewählt beim Kommen wie heute der Arbeitsort; wer das Projekt wechselt, stempelt Gehen und Kommen. Nachträglich änderbar über die Korrektur. |
| FK6 | Zuordnung der Leistung | An der Zeile ein Projekt; der Kunde ergibt sich daraus. Die Vorgangskennung bleibt daneben. |
| FK7 | Was abrechenbar ist | Bestätigte Leistungen und freigegebene Zeiten; die Zeit mit einem Stundensatz des Projekts oder der Person für dieses Projekt – **nicht** mit dem Gehalt. |
| FK8 | Lohn und Faktura | Getrennt: Der Zuschlag rechnet weiter aus allen bestätigten Leistungen der Person, gleich ob und wem sie berechnet werden. |
| FK9 | Rechnung | Entwurf → geprüft → gestellt (Nummer, gesperrt); Korrektur nur durch Storno- oder Korrekturrechnung. Ausgabe als PDF und E-Rechnung (XRechnung/ZUGFeRD – ab 2025 für Geschäftskunden Pflicht, Stand und Fristen vor dem Bauen nachzuschlagen). |
| FK10 | Rollen | `projekte`: „Projekte führen“ (Kunden und Projekte pflegen), jeder mit Zeit- oder Leistungsrolle wählt aus den aktiven Projekten. `faktura`: „Faktura“ (Rechnungen erstellen und stellen), Buchhalter und Verantwortlicher sehen und buchen den Entwurf. Personal und Lohn sehen keine Kunden. |
| FK11 | Wer sieht Kunden | Wer Zeiten oder Leistungen erfasst, sieht Namen aktiver Projekte, nicht die Stammdaten der Kunden. |
| FK12 | Reihenfolge | Erst `projekte` mit der Zuordnung (macht Zeiten und Leistungen nach Projekt auswertbar), dann `faktura`. |

## 5. Stufen

| Stufe | Inhalt |
|---|---|
| **1** | `projekte`: Kunden, Projekte, Zuordnung an Zeitabschnitt und Leistungszeile, Auswertung je Projekt. |
| **2** | `faktura`: Rechnungsentwurf aus einem Projekt, Positionen, PDF, Nummernkreis, Buchungsentwurf im Buch. |
| **3** | E-Rechnung, Storno und Korrektur, Zahlungseingang gegen offene Posten. |

## 6. Offen und ungeprüft

- Pflichtangaben einer Rechnung (§ 14 UStG), Nummernkreis und Aufbewahrung sind nicht nachgeschlagen.
- Der Stand der E-Rechnungspflicht ist nicht geprüft; das Modul `import` liest E-Rechnungen schon, schreibt aber keine.
- Offene Posten und Mahnwesen sind nicht betrachtet.
- Kundendaten sind Personenbezug, sobald ein Kunde eine natürliche Person ist: Verschlüsselung wie P38.
