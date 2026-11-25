# BESTELLANFORDERUNG.md – Aus „Nachbestellen“ wird eine Bestellanforderung (Entwurf 1, zur Freigabe – nicht gebaut)

Stand: 2026-11-25. Bezug: LAGER.md (Stufe 2: Nachbestellliste, Y14–Y19; Rollen RL1–RL6; Abschnitt 17 Artikelgruppen),
STUECKLISTE.md 12 (Teile eines Stücks, „unvollständig“), ARTIKELEIGENSCHAFTEN.md 3.9 (Beschaffen), FAKTURA.md,
ARBEITSVORRAT.md, F31, F44, F106, F134. Maßgeblich bleibt AGENTS.md.

**Vorgabe des Verantwortlichen (2026-11-25, 12:06, an der Seite „Nachbestellen“): „Hier machen wir faktisch eine SAP-BANF
draus.“**

**Status: Entwurf. Nichts ist gebaut.** Gelesen ist die Vorgabe so: Die Seite „Nachbestellen“ zeigt heute nur eine Liste
zum Drucken. Sie soll ein Vorgang werden – Bedarf melden, freigeben, bestellen, Eingang abhaken –, wie die
Bestellanforderung (BANF) in SAP. **Wie SAP die BANF im Einzelnen führt, ist nicht nachgeschlagen;** beschrieben ist, was
kumero braucht.

## 1. Was heute da ist

Die Seite „Nachbestellen“ nennt Artikel und Artikelgruppen unter Mindestbestand mit einem Vorschlag bis zum Sollbestand,
zum Drucken und als CSV. Dahinter steht kein Vorgang: niemand fordert an, niemand gibt frei, nichts merkt sich, was schon
bestellt ist – wer die Liste morgen wieder druckt, bestellt dasselbe noch einmal. Der Wareneingang weiß nicht, worauf
gewartet wird.

## 2. Der Vorgang

```
Bedarf ──▶ Anforderung ──▶ Freigabe ──▶ Bestellung ──▶ Wareneingang ──▶ erledigt
(Vorschlag,   (Positionen)   (ja / nein,   (wo, wann,     (Zugang hakt
 von Hand,                    mit Grund)    Nummer)        die Position ab)
 Stück unvollständig)
```

**Anforderung** – der Kopf: laufende Nummer, wer fordert an, wann, wofür (freier Text ohne Personenbezug), gewünschter
Termin, Zustand. **Position:** Artikel, Artikelgruppe („einer davon“) oder freier Text für etwas, das noch kein Artikel
ist; Menge und Einheit; Wunschlieferant (freiwillig); geschätzter Preis (freiwillig); Zustand der Position.

**Zustände der Anforderung:** Entwurf → angefordert → freigegeben | abgelehnt → bestellt → geliefert (teilweise | ganz) →
abgeschlossen; dazu „zurückgezogen“. Nichts wird gelöscht außer einem Entwurf, der nie angefordert war.

## 3. Woher der Bedarf kommt

| Quelle | Was geschieht |
|---|---|
| **Vorschlag aus dem Bestand** | Wie heute: unter Mindestbestand, Menge bis zum Sollbestand. Neu: „In Anforderung übernehmen“ für die markierten Zeilen. Was schon in einer offenen Anforderung oder Bestellung steht, zeigt die Liste mit dieser Menge und schlägt nur den Rest vor. |
| **Von Hand** | Jeder mit dem Recht legt eine Anforderung an – auch für etwas ohne Mindestbestand oder ohne Artikel. |
| **Unvollständiges Stück** | Am Stück („unvollständig: Ladeschale fehlt“): „Fehlendes anfordern …“ legt die Positionen an und merkt sich das Stück; beim Eingang schlägt der Wareneingang vor, das Teil gleich einzubauen. |
| **Verfall** (später) | Was in der Warnfrist abläuft und gebraucht wird. |

## 4. Rollen und Rechte

Ein Entwurf nennt seine Rollen (CLAUDE.md): Wer arbeitet damit, und was sieht er sonst nicht?

| Recht | Wer (Vorschlag) | Was |
|---|---|---|
| `lager.bedarf.anfordern` | Lager, Lagerleitung | Anforderungen anlegen, eigene ändern und zurückziehen, solange sie nicht freigegeben sind |
| `lager.bedarf.freigeben` | Lagerleitung – oder eine neue Rolle „Einkauf“ (BA4) | freigeben oder ablehnen, mit Grund; Mengen dabei ändern |
| `lager.bedarf.bestellen` | Lagerleitung bzw. „Einkauf“ | als bestellt kennzeichnen: Lieferant, Datum, Bestellnummer des Lieferanten, erwarteter Termin |
| `lager.bestand.sehen` | alle Rollen des Lagers | Anforderungen und ihren Stand sehen |

**Vier Augen:** Wer anfordert, gibt seine eigene Anforderung nicht frei – außer der Betrieb schaltet das ab (BA5; in
einer kleinen Praxis ist es oft dieselbe Person). Preise sehen nur, wer freigibt oder bestellt (BA6).

## 5. Oberfläche

- **„Nachbestellen“ wird zur Seite „Bedarf“** mit drei Ansichten an festem Platz: **Vorschlag** (die heutige Liste, mit
  Übernehmen), **Anforderungen** (Liste mit Zustand in Worten, Filter am Spaltenkopf; unten die Positionen der
  gewählten), **Bestellt und offen** (worauf gewartet wird, mit erwartetem Termin; überfällig als Zustand).
- Knöpfe an festem Platz, gesperrt mit Grund (F106): „Anforderung neu …“, „Anfordern“, „Freigeben …“, „Ablehnen …“,
  „Als bestellt kennzeichnen …“, „Zurückziehen“, „Drucken“ (die Bestellliste je Lieferant), „CSV“.
- **Wareneingang:** Zu einer Position des Belegs bzw. beim Zugang ohne Beleg zeigt kumero offene bestellte Positionen
  desselben Artikels und hakt die Menge ab; Über- und Unterlieferung stehen als Zustand da, nichts wird still verrechnet.
- **Übersicht:** Kachel „Anforderungen warten auf Freigabe“ für den, der freigeben darf.

## 6. Abgrenzung

- **Keine Bestellung an den Lieferanten aus kumero** in der ersten Stufe: kein Versand, keine E-Mail, kein Portal –
  kumero merkt sich, dass und wo bestellt wurde. Ein Bestellschreiben als PDF wäre Stufe 2 (BA2).
- **Keine Buchung:** Eine Anforderung oder Bestellung bucht nichts (F31). Die Rechnung kommt wie bisher über den
  Arbeitsvorrat; ein Abgleich Bestellung ↔ Rechnung (Menge, Preis) wäre Stufe 3.
- **Kein Budget, keine Kontierung** in der ersten Stufe; „wofür“ ist freier Text bzw. später Kostenstelle
  (STUECKLISTE.md WG).
- **Kein Patientenbezug** (WW14): „wofür“ nennt nie eine Person.
- **Nicht nur Lagerware?** Geräte und Dienstleistungen ließen sich als freie Position anfordern; ob das hierher gehört
  oder ein eigenes Modul „Beschaffung“ braucht, ist BA3.

## 7. Entscheidungen

| # | Frage | Vorschlag |
|---|---|---|
| BA1 | Ist die Lesart richtig – aus der Liste wird ein Vorgang mit Anforderung, Freigabe, Bestellt-Kennzeichen und Abhaken im Wareneingang? | **Bitte bestätigen.** |
| BA2 | Wie weit in der ersten Stufe? | Bis „bestellt“ als Kennzeichen und Abhaken im Wareneingang. Bestellschreiben und Abgleich mit der Rechnung später. |
| BA3 | Wo liegt es: im Modul `lager` oder als eigenes Modul `beschaffung` am Buch? | Zuerst im Lager – dort sitzen Bedarf, Artikel und Wareneingang. Ein eigenes Modul, sobald Dinge angefordert werden, die nie ins Lager gehen (Geräte, Dienstleistungen); die freie Position hält den Weg offen. |
| BA4 | Wer gibt frei: Lagerleitung oder eine neue Rolle „Einkauf“? | Eine eigene Rolle „Einkauf“ mit Freigeben und Bestellen (F134), damit sich das von der Lagerleitung trennen lässt; die Lagerleitung bekommt die Bausteine ebenfalls. |
| BA5 | Vier Augen: Darf jemand die eigene Anforderung freigeben? | Vorgabe nein; je Buch abschaltbar. |
| BA6 | Freigabegrenzen nach Wert? | Erste Stufe: nein – eine Freigabe für alles. Stufen nach Betrag später, wenn Preise verlässlich da sind. |
| BA7 | Artikelgruppe als Position („einer davon“)? | Ja – bestellt wird ein konkreter Artikel der Gruppe; der Eingang jedes Artikels der Gruppe hakt ab. |
| BA8 | Nummer der Anforderung | Fortlaufend je Buch und Jahr (z. B. BA-2026-0001), nie neu vergeben. |
| BA9 | Was der Vorschlag abzieht | Offene Mengen aus Anforderungen ab „angefordert“ und aus Bestellungen; abgelehnte und zurückgezogene nicht. |
| BA10 | Lieferanten | Erste Stufe: Name als Text mit Vorschlägen aus den bisherigen Zugängen. Ein Lieferantenstamm gehört zu den Geschäftspartnern (FAKTURA.md) und wird hier nicht vorweggenommen. |
| BA11 | Aufbewahrung | Abgeschlossene Anforderungen bleiben stehen; im Protokoll IDs, Zustände und Mengen, keine Preise. |

## 8. Stufen

1. **Anforderung und Freigabe:** Tabellen, Rechte, Rolle, Seite „Bedarf“ mit Vorschlag → Anforderung → Freigabe/Ablehnung.
2. **Bestellt und Eingang:** Kennzeichen „bestellt“, Abhaken im Wareneingang und beim Zugang ohne Beleg, „überfällig“.
3. **Aus dem unvollständigen Stück** und Einbauen beim Eingang.
4. **Später:** Bestellschreiben, Abgleich mit der Rechnung, Freigabegrenzen, Lieferantenstamm, eigenes Modul.

Jede Stufe ist ein Release. Gebaut wird erst nach Freigabe (BA1–BA5 mindestens).
