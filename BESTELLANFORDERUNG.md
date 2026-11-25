# BESTELLANFORDERUNG.md – Aus „Nachbestellen“ wird eine Bestellanforderung (Entwurf 3, zur Freigabe – nicht gebaut)

Stand: 2026-11-25. Bezug: LAGER.md (Stufe 2: Nachbestellliste, Y14–Y19; Rollen RL1–RL6; Abschnitt 17 Artikelgruppen),
STUECKLISTE.md 12 (Teile eines Stücks, „unvollständig“), ARTIKELEIGENSCHAFTEN.md 3.9 (Beschaffen), FAKTURA.md,
ARBEITSVORRAT.md, F31, F44, F106, F134. Maßgeblich bleibt AGENTS.md.

**Vorgabe des Verantwortlichen (2026-11-25, 12:06, an der Seite „Nachbestellen“): „Hier machen wir faktisch eine SAP-BANF
draus.“**

**Status: Entwurf. Nichts ist gebaut. Entschieden am 2026-11-25, 12:13 (F217): BA2–BA5 und vier Ergänzungen – Abschnitt 9; bei Widerspruch gilt Abschnitt 9.** Gelesen ist die Vorgabe so: Die Seite „Nachbestellen“ zeigt heute nur eine Liste
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

## 9. Entscheidungen vom 2026-11-25, 12:13 (F217) – und wie sie gelesen sind

| # | Vorgabe des Verantwortlichen | Gelesen als |
|---|---|---|
| BA2 | „Datenerfassung an der Quelle – die Quelle ist spätestens der Einkäufer.“ | **Die Bestellung ist kein bloßes Kennzeichen.** Wer bestellt, erfasst, was dann feststeht: Lieferant, Bestellnummer, Termin, je Position Artikel, Menge, Einheit, Preis. Niemand tippt das beim Wareneingang noch einmal. Der Vorschlag „nur Kennzeichen“ ist verworfen. |
| BA3 | „Das hängt mit dem Lager zusammen, viel BANF ist Ergänzung des Mindestbestands … eigenes Modul mit Rückwirkungen ginge.“ | **Eigenes Modul `beschaffung` am Buch**, das auf das Lager zurückwirkt: Es liest Bedarf und Artikel über einen Dienst des Lagers und meldet dorthin, was offen und was bestellt ist (F44 – kein Modul liest die Tabellen eines anderen). Ohne Modul `lager` nimmt es freie Positionen. |
| BA4 | „Dem ist so.“ | **Eigene Rolle „Einkauf“** mit Freigeben und Bestellen. |
| BA5 | „Wir bleiben beim Rollenkonzept. Hat jemand beide Rollen, darf er das.“ | **Keine Vier-Augen-Regel neben den Rollen.** Wer anfordern und freigeben darf, gibt auch die eigene Anforderung frei. Kein Schalter. |
| BA12 | „Bei ‚bestellt‘ legen wir gleich die bestellten Artikel und ggf. die Artikelgruppe mit an bzw. füllen sie aus.“ | **Spätestens beim Bestellen wird aus jeder Position ein Artikel:** Eine freie Position öffnet die Artikelmaske (vorbelegt aus der Position) und, wo gewünscht, die Maske der Artikelgruppe; bei vorhandenen Artikeln werden fehlende Angaben ergänzt (GTIN, Lieferantenartikelnummer, Packungsinhalt – ARTIKELEIGENSCHAFTEN.md 3.1, 3.8, 3.9). Erst dann lässt sich die Position als bestellt speichern. Für Dinge, die nie ins Lager gehen, bleibt die freie Position (BA16). |
| BA13 | „Der Wareneingang kann die Positionen aus der Bestellung buchen.“ | **Die Bestellung ist eine zweite Quelle des Wareneingangs** neben dem Beleg aus dem Arbeitsvorrat: Beleg wählen **oder** Bestellung wählen; die Positionen stehen mit Artikel und Menge schon da, zu erfassen bleiben Charge bzw. Seriennummer, Verfall, Lagerort und die gelieferte Menge. |
| BA14 | „Soweit eine GTIN auf beiden Seiten vorhanden ist, wird beides eingebucht?“ | **Nein – es wird einmal eingebucht.** Kommen Bestellung und Rechnung zur selben Ware, erkennt kumero die Position über die GTIN (sonst über die Artikelnummer des Lieferanten) und führt beide zusammen: Der Zugang entsteht aus der Quelle, die zuerst da ist; die zweite wird nur **verknüpft** und liefert, was fehlt (die Rechnung den Preis und den Beleg, die Bestellung die Anforderung). Passt die Menge nicht, steht das als Zustand da. **Lesart der Frage bitte bestätigen.** |
| BA15 | „Eine Weiterleitung an weitere Personen muss auch drin sein.“ | **Eine Anforderung lässt sich weiterleiten:** an eine Person mit passender Rolle, mit einem Satz dazu – zur Freigabe, zur Rückfrage oder zum Bestellen. Sie steht dann bei dieser Person unter „Bei mir“; der Verlauf (wer an wen, wann, wozu) bleibt an der Anforderung. Mehrere Stationen hintereinander sind möglich. Keine E-Mail und kein Dienst außerhalb – die Benachrichtigung ist die Liste und eine Kachel auf der Übersicht. |

### 9.1 Was daraus folgt

- **Rollen des Moduls `beschaffung`:** „Anfordern“ (Recht `beschaffung.bedarf.anfordern`), „Einkauf“ (`…freigeben`,
  `…bestellen`, dazu im Lager `lager.artikel.pflegen`, weil der Einkauf Artikel anlegt – BA12), alle sehen ihren eigenen
  Vorgang; Preise sieht, wer freigibt oder bestellt. Die Rollen „Lager“ und „Lagerleitung“ bekommen „Anfordern“ als
  Baustein angeboten, nicht aufgezwungen (F143).
- **Dienst des Lagers** für die Beschaffung: Vorschlag (unter Mindestbestand, abzüglich offener Mengen), Auskunft zu
  Artikeln und Gruppen, Anlegen und Ergänzen von Artikeln mit dem Recht dessen, der handelt; in die andere Richtung
  meldet die Beschaffung dem Lager offene und bestellte Mengen je Artikel und liefert dem Wareneingang die Positionen.
- **Personen** kommen vom Mandanten (`Dienste::nutzerAmMandanten()`), nur ID und Anzeigename.
- **Stufe 1 wächst:** Anforderung, Weiterleitung, Freigabe **und** Bestellung mit Artikelanlage; Stufe 2 der
  Wareneingang aus der Bestellung mit dem Zusammenführen nach BA14; Stufe 3 der Weg aus dem unvollständigen Stück.

### 9.2 Noch offen

| # | Frage | Vorschlag |
|---|---|---|
| BA16 | Positionen, die nie ins Lager gehen (Gerät für die Anlagen, Dienstleistung) | bleiben freie Positionen ohne Artikel; der Wareneingang hakt sie von Hand ab. Geräte gehen später über „als Anlagegut anlegen“ (LAGER.md IN4). |
| BA17 | Weiterleiten: an eine Person oder auch an eine Rolle („an den Einkauf“)? | Beides – an eine Rolle heißt: steht bei allen mit dieser Rolle unter „Bei mir“, wer sie öffnet und übernimmt, hat sie. |
| BA18 | Darf nach der Freigabe noch geändert werden? | Der Einkauf darf Menge, Artikel und Lieferant beim Bestellen ändern; die Änderung steht im Verlauf und der Anforderer sieht sie. Größere Abweichungen zurück zur Freigabe: nicht in der ersten Stufe. |
| BA19 | Eine Bestellung aus mehreren Anforderungen (Sammelbestellung je Lieferant)? | Ja – bestellt wird je Lieferant; jede Position behält den Verweis auf ihre Anforderung. |
| BA20 | Bestellschreiben als PDF | nicht in Stufe 1; die Bestellliste je Lieferant lässt sich drucken. |

### 9.3 Antworten vom 2026-11-25, 12:19

Im Chat standen die offenen Punkte ohne Nummer und in anderer Reihenfolge als in 9.2; der Verantwortliche hat sie der Reihe nach
gezählt. Hier stehen sie nach ihrem Inhalt, mit der Nummer aus 9.2.

| Nr. (9.2) | Antwort | Stand |
|---|---|---|
| BA17 | Weiterleiten auch an eine Rolle: **ja.** | entschieden |
| BA18 | Der Einkauf darf nach der Freigabe Menge, Artikel und Lieferant ändern, die Änderung steht im Verlauf: **ja.** | entschieden |
| BA19 | „Eine BANF hängt nicht am Lieferanten, sondern am Bedarf.“ – **So ist es, und so bleibt es:** Die Anforderung kennt nur den Bedarf (was, wie viel, wofür, bis wann); ein Lieferant ist dort höchstens ein Vorschlag. Der Lieferant gehört zur **Bestellung**, und die legt der Einkauf an. Eine Bestellung geht an einen Lieferanten und nimmt Positionen aus beliebig vielen Anforderungen auf; umgekehrt kann eine Anforderung auf mehrere Bestellungen verteilt werden. Der Zustand einer Anforderung ergibt sich aus ihren Positionen (offen, teilweise bestellt, bestellt, geliefert). | Lesart, **zu bestätigen** |
| BA16 / BA20 | „Verstehe ich nicht, ist doch nur ein Report?“ – nicht eindeutig, welcher der beiden Punkte gemeint ist. Gelesen für **BA20** (Bestellschreiben): Es ist ein Bericht aus den Daten der Bestellung, kein eigener Gegenstand – dann gehört es wie jede andere Liste dazu (drucken, als PDF speichern) und braucht keine eigene Stufe. **BA16** (Positionen, die nie ins Lager gehen) bleibt damit offen. | **Rückfrage gestellt** |
