# BESTELLANFORDERUNG.md – Aus „Nachbestellen“ wird eine Bestellanforderung (Stufe 1 gebaut mit 0.7.98)

Stand: 2026-11-25. Bezug: LAGER.md (Stufe 2: Nachbestellliste, Y14–Y19; Rollen RL1–RL6; Abschnitt 17 Artikelgruppen),
STUECKLISTE.md 12 (Teile eines Stücks, „unvollständig“), ARTIKELEIGENSCHAFTEN.md 3.9 (Beschaffen), FAKTURA.md,
ARBEITSVORRAT.md, F31, F44, F106, F134. Maßgeblich bleibt AGENTS.md.

**Vorgabe des Verantwortlichen (2026-11-25, 12:06, an der Seite „Nachbestellen“): „Hier machen wir faktisch eine SAP-BANF
draus.“**

**Status: Stufe 1 gebaut mit 0.7.98 (F222) – was gebaut ist, steht in Abschnitt 10 und geht bei Widerspruch den Abschnitten 1 bis 8 vor.** Zuvor: Entwurf. Entschieden am 2026-11-25, 12:13 (F217): BA2–BA5 und vier Ergänzungen – Abschnitt 9; bei Widerspruch gilt Abschnitt 9.** Gelesen ist die Vorgabe so: Die Seite „Nachbestellen“ zeigt heute nur eine Liste
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

### 9.4 Antworten vom 2026-11-25, 12:25

| Nr. (9.2) | Antwort | Was daraus folgt |
|---|---|---|
| BA19 | „Beides ist richtig.“ | **Entschieden:** Die Anforderung hängt am Bedarf, der Lieferant an der Bestellung. Eine Bestellung geht an einen Lieferanten und nimmt Positionen aus mehreren Anforderungen auf; eine Anforderung kann auf mehrere Bestellungen verteilt werden. Der Zustand der Anforderung ergibt sich aus ihren Positionen. |
| BA20 | „Derzeit werden Bestellungen durch Online-Shopping abgewickelt. Klassische Bestellschreiben gibt es da nicht mehr.“ | **Entschieden: kein Bestellschreiben.** kumero erzeugt kein Schreiben an den Lieferanten. Die Bestellung in kumero ist die **Erfassung dessen, was im Shop bestellt wurde**: Lieferant (Shop), Bestellnummer des Shops, Bestelldatum, zugesagter Liefertermin, Positionen mit Menge und Preis. Lesart, **zu bestätigen:** (a) Der Einkauf arbeitet mit einer Bestellliste je Lieferant am Bildschirm (was ist freigegeben und in den Warenkorb zu legen, mit Lieferantenartikelnummer zum Kopieren) und trägt danach die Bestellnummer ein. (b) Die Bestellbestätigung des Shops (E-Mail, PDF) ist ein Dokument an der Bestellung – kommt mit den Dokument-Verweisen (F166) über Paperless, nicht in Stufe 1. (c) Kein Abruf aus Shops, keine Schnittstelle zu einem Shop. |
| BA16 | „Positionen, die nie ins Lager gehen: Ja, das gibt es natürlich viel.“ | **Kein Randfall, sondern gleichberechtigt.** Eine Position ist entweder **fürs Lager** (wird spätestens beim Bestellen ein Artikel, BA12; geliefert durch den Zugang) oder **nicht fürs Lager** (Verbrauch ohne Bestandsführung, Dienstleistung, Gerät, Software, Abonnement; bleibt Text mit Menge, Einheit und Preis, ohne Artikel). Lesart, **zu bestätigen:** (a) Die Wahl trifft der Anforderer, der Einkauf darf sie ändern (BA18). (b) „Nicht fürs Lager“ wird von Hand als **erhalten** gemeldet – ganz oder in Teilmengen, mit Datum und Namen; das Recht dazu hat, wer anfordern darf, für die eigene Anforderung, und der Einkauf für alle. (c) Die Beschaffung braucht dafür das Lager nicht: Das Modul läuft auch in einem Buch ohne Lager, dann gibt es nur solche Positionen. (d) Geräte gehen später über „als Anlagegut anlegen“ in die Anlagen (LAGER.md IN4) – nicht in Stufe 1. |

Damit ist aus 9.2 nichts mehr offen; zu bestätigen bleiben BA14 (Bestellung und Rechnung zur selben Ware: einmal einbuchen,
zusammenführen) und die Lesarten oben.

### 9.5 Berichtigung vom 2026-11-25, 12:34 – gearbeitet wird an der Anforderung, nicht an einer Bestellliste

„Eher anders. Die Positionen in der BANF werden abgehakt. Die Bestellliste je Lieferant habe ich derzeit nicht. Was man tun kann:
die Artikel nehmen und die bekannten Lieferanten bei den Artikeln erfassen. Was aber nicht heißt, dass die bei der nächsten
Bestellung noch die besten Konditionen haben.“

Damit entfällt die Lesart (a) zu BA20 in 9.4 (Bestellliste je Lieferant am Bildschirm).

| Nr. | Festlegung | Stand |
|---|---|---|
| BA21 | **Der Einkauf arbeitet in der Anforderung.** Er öffnet die freigegebene Anforderung und hakt Position für Position ab: „bestellt“. Eine eigene Liste je Lieferant gibt es nicht. | entschieden |
| BA22 | **Bekannte Lieferanten stehen am Artikel und sind ein Hinweis, keine Vorgabe.** An der Position zeigt kumero, wo der Artikel zuletzt bezogen wurde: Lieferant, dessen Artikelnummer, letzter Preis **mit Datum**. Vorbelegt wird nichts, was der Einkauf dann unbesehen übernimmt – die Konditionen können sich geändert haben; er wählt den Lieferanten bei jeder Bestellung selbst. | entschieden; Darstellung zu bestätigen |
| BA23 | **Beim Abhaken wird erfasst** (Erfassung an der Quelle, BA2): Lieferant, bestellte Menge, Preis, Bestellnummer des Shops, zugesagter Termin. Lieferant, Bestellnummer und Termin bleiben für die nächsten Positionen stehen, bis der Einkauf sie ändert – wer fünf Positionen im selben Warenkorb hat, tippt sie einmal. | Lesart, zu bestätigen |
| BA24 | **Der Artikel lernt mit:** Mit dem Abhaken wird der Lieferant mit Artikelnummer, Preis und Datum am Artikel nachgetragen (`lager.lieferantenartikel`, über einen Dienst des Lagers). Frühere Lieferanten bleiben stehen; so entsteht die Liste der bekannten Bezugsquellen von selbst. | Lesart, zu bestätigen |
| BA25 | **Die Bestellung ist kein Gegenstand, den jemand anlegt**, sondern ergibt sich: Positionen mit demselben Lieferanten und derselben Bestellnummer gehören zusammen. Das genügt für BA19 (eine Bestellung aus mehreren Anforderungen, eine Anforderung in mehreren Bestellungen) und für den Wareneingang aus der Bestellung (BA13: Paket da, Bestellnummer wählen, Positionen stehen bereit). | Lesart, zu bestätigen |
| BA26 | Nicht jede Position muss am selben Tag bestellt werden; eine Teilmenge abzuhaken geht (Rest bleibt offen). Der Zustand der Anforderung ergibt sich aus den Positionen: offen, teilweise bestellt, bestellt, teilweise erhalten, erledigt. | Lesart, zu bestätigen |

### 9.6 Antworten vom 2026-11-25, 12:37

„Der Einkauf kann auch die Menge ändern, z. B. wegen Packungsgrößen. Die sollte dann vorgemerkt sein für die Eingangsbuchung. Der
Lieferant ist nicht wirklich relevant, wir sourcen eh immer Bestpreise. 1., 2., 3. passt so. 4.: Der Einkäufer entscheidet am Ende
über das tatsächliche Volumen!“

| Nr. | Festlegung | Stand |
|---|---|---|
| BA23, BA24, BA25 | Erfassen beim Abhaken, der Artikel lernt mit, die Bestellung ergibt sich aus Lieferant und Bestellnummer. | **bestätigt** |
| BA27 | **Der Einkäufer entscheidet über die Menge.** Angefordert ist ein Bedarf, bestellt wird, was der Einkäufer festlegt – mehr (Packungsgröße, Staffel) oder weniger. Beide Mengen bleiben an der Position stehen: angefordert und bestellt. Mit dem Abhaken ist die Position **bestellt**, auch wenn weniger bestellt wurde; ein Rest bleibt nicht von selbst offen (ersetzt BA26). Will der Einkäufer den Rest später bestellen, teilt er die Position. Der Anforderer sieht die Abweichung, eine neue Freigabe braucht sie nicht (BA18). | entschieden; Teilen der Position zu bestätigen |
| BA28 | **Die bestellte Menge ist für den Wareneingang vorgemerkt.** Die Eingangsbuchung schlägt die bestellte Menge vor, nicht die angeforderte. Wo in Packungen bestellt wird, steht beides da (z. B. „2 Packungen zu 50 = 100 Stück“); gebucht wird in der Einheit des Artikels. Weicht die Lieferung ab, ändert der Wareneingang die Menge, und die Abweichung steht an der Position. | entschieden; Packung als eigene Angabe erst mit den Packungsebenen (ARTIKELEIGENSCHAFTEN.md 3.9), bis dahin Menge in der Einheit des Artikels und ein Vermerk |
| BA29 | **Der Lieferant steuert nichts.** Bezogen wird zum besten Preis, wo es ihn gerade gibt. Der Lieferant wird beim Abhaken festgehalten (BA23), weil Wareneingang und Rechnung ihn brauchen, um zusammenzufinden – aber es gibt keinen Stammlieferanten, keine Vorbelegung, keine Auswertung und keine Sortierung nach Lieferant. Der Hinweis aus BA22 schrumpft auf eine Zeile: letzter Preis mit Datum und Quelle. | entschieden |

## 10. Stufe 1 – was gebaut ist (0.7.98, F222)

Auftrag des Verantwortlichen am 2026-11-25, 12:39 und 13:54: „dann bau mal die BANF!“

### 10.1 Der Vorgang, wie er läuft

1. **Anfordern.** „Anforderung neu …“ oder aus dem Vorschlag „In Anforderung übernehmen …“. Der Entwurf lässt sich
   speichern; „Anfordern“ gibt ihn ab, er bekommt die Nummer `BA-Jahr-0001` und folgende.
2. **Weiterleiten** (freiwillig) an eine Person oder eine Rolle – zur Freigabe, zur Rückfrage, zum Bestellen.
3. **Freigeben** oder **Ablehnen** (mit Grund) – wer das Recht hat.
4. **Abhaken.** Der Einkauf wählt unten eine Position: „Als bestellt abhaken …“ (Doppelklick auf die offene Position).
5. **Eingang melden** an der bestellten Position (Doppelklick) – ganz, in Teilmengen oder „es kommt nichts mehr“.
6. Sind alle Positionen erhalten oder gestrichen, ist die Anforderung **erledigt**.

Der Stand in der Liste: Entwurf, angefordert, freigegeben, teilweise bestellt, bestellt, teilweise erhalten, erledigt –
dazu abgelehnt und zurückgezogen. „überfällig“ steht dabei, wenn ein zugesagter Liefertermin vorbei ist.

### 10.2 Festlegungen aus der Umsetzung – zu bestätigen

| # | Festlegung |
|---|---|
| BU1 | **Rechte und Bausteine.** `beschaffung.vorgang.sehen` (eigene Anforderungen und was bei einem liegt), `beschaffung.vorgang.alle`, `beschaffung.bedarf.anfordern`, `.freigeben`, `.bestellen`. Bausteine: „Bedarf ansehen“ (sehen, alle), „Bedarf anfordern“ (sehen, anfordern), „Einkauf“ (alles). Vorgabe je Rolle: **Einkauf** und **Verantwortlicher** → „Einkauf“; **Lager**, **Lagerleitung** → „Bedarf anfordern“; **Buchhalter** → „Bedarf anfordern“ und „Bedarf ansehen“; **Steuerberater, Prüfer, Leser** → „Bedarf ansehen“. Der Erfasser bekommt nichts. Was der Administrator einer Rolle nimmt, bleibt genommen (F143). |
| BU2 | **Der Einkauf legt Artikel an, ohne das Recht `lager.artikel.pflegen`** (anders als in 9.1 vorgeschlagen): Er tut es nur beim Abhaken, über den Dienst des Lagers – dieselbe Prüfung, dieselbe Funktion, im Protokoll unter seinem Namen. Ändern, Stilllegen und Löschen von Artikeln bleibt beim Lager. An einem vorhandenen Artikel kann er nur eine fehlende GTIN ergänzen. |
| BU3 | **Die Artikelmaske beim Bestellen ist eine verkürzte Maske im Dialog:** Bezeichnung, Artikelnummer, Art, Lagereinheit, hat Charge, hat Seriennummer, in Stücken gezählt, Verfallsdatum Pflicht, GTIN. Mindest- und Sollbestand, üblicher Lagerort, Stückliste und die **Artikelgruppe** stehen dort nicht – die trägt das Lager nach. Die volle Maske des Bestands von hier aus zu öffnen, ist nicht gebaut. |
| BU4 | **„Eingang melden“ gibt es für jede bestellte Position, auch für Lagerware – und bucht nichts ins Lager.** Das ist die Anschlussstelle für Stufe 2 (Wareneingang aus der Bestellung): Bis dahin wird der Zugang wie bisher gebucht und der Eingang hier von Hand gemeldet. Der Dialog sagt das. |
| BU5 | **Die Nummer entsteht mit „Anfordern“**, nicht mit dem Entwurf – so gibt es keine Lücken durch gelöschte Entwürfe. Ein Entwurf wird mit „Zurückziehen“ gelöscht, eine angeforderte Anforderung bleibt als „zurückgezogen“ stehen. |
| BU6 | **Sichtbarkeit.** Jeder sieht, was er angelegt hat und was bei ihm oder seiner Rolle liegt. Alle Anforderungen sieht, wer „alle sehen“, „freigeben“ oder „bestellen“ hat. **Preise** sieht nur, wer freigibt oder bestellt (BA6); „zuletzt bestellt bei …“ und die Liste der Lieferanten nur, wer bestellt. |
| BU7 | **„Bei mir“** ist: der eigene Entwurf; was an mich oder meine Rolle weitergeleitet ist; für den, der freigeben darf, jede angeforderte Anforderung, die bei niemandem liegt; für den, der bestellen darf, jede freigegebene mit offenen Positionen, die bei niemandem liegt. |
| BU8 | **Weiterleiten** geht an jeden, der das Nötige darf (zur Freigabe: freigeben; zum Bestellen: bestellen; zur Rückfrage: jeder, der mit Anforderungen arbeitet), nicht an sich selbst. Mit Freigabe oder Ablehnung liegt die Anforderung wieder bei niemandem. **Eine Rückfrage hat kein „beantwortet“:** Wer gefragt ist, leitet zurück. |
| BU9 | **Ändern** lässt sich eine Anforderung bis zur Freigabe – von dem, der sie angelegt hat, und von dem, der freigeben darf. Danach nur noch über die Positionen: abhaken mit anderer Menge oder anderem Artikel (BA18), Rest abteilen (BA27), streichen mit Grund, Haken zurücknehmen (solange nichts erhalten ist). Eine Bestellung ändern heißt: Haken zurücknehmen und neu setzen. |
| BU10 | **Pflicht beim Abhaken** sind Menge, Lieferant und Bestelldatum (nicht in der Zukunft). Bestellnummer, Artikelnummer dort, Liefertermin, Preis und Vermerk sind freiwillig. Der Preis gilt je Einheit, mit bis zu vier Nachkommastellen, ohne Angabe zu netto oder brutto. Der Lieferant ist ein Text mit Vorschlägen aus früheren Bestellungen (BA10). |
| BU11 | **Was stehen bleibt** (BA23): Lieferant, Bestellnummer, Bestelldatum und Liefertermin der letzten Bestellung – bis die Seite neu geladen wird. Wählt der Einkäufer einen anderen Lieferanten, bleibt die Bestellnummer stehen und der Hinweis nennt, woher sie stammt; geleert wird sie nie von selbst (SA6). |
| BU12 | **Der Artikel lernt die Bezugsquelle nur mit einer „Artikelnummer dort“** – `lager.lieferantenartikel` führt Lieferant und dessen Nummer. Eine Zuordnung, die es schon gibt, bleibt unverändert (ihr Faktor wird nicht zurückgesetzt). Der Hinweis „zuletzt bestellt am … bei … zu …“ kommt aus den Bestellungen der Beschaffung selbst. |
| BU13 | **Der Vorschlag** zieht ab, was angefordert (nicht: Entwurf) oder bestellt und noch nicht als erhalten gemeldet ist; für eine Artikelgruppe zählt auch, was für einen ihrer Artikel unterwegs ist. **Die Seite „Nachbestellen“ des Lagers bleibt unverändert daneben stehen** – ob sie entfällt, wenn die Beschaffung aktiv ist, ist offen. |
| BU14 | **Menü:** „Bedarf“ im Bereich „Lager“, auch in einem Buch ohne Lager (einen Bereich „Beschaffung“ gibt es nicht). |
| BU15 | **Kern:** neuer Dienst `Dienste::berechtigte()` – wer im Buch eines der Rechte hat und welche Rollen sie tragen. Nur Subjekte und Namen von Rollen. |

### 10.3 Nicht gebaut

- **Stufe 2:** Wareneingang aus der Bestellung (BA13), Zusammenführen von Bestellung und Rechnung (BA14).
- **Stufe 3:** Anfordern aus dem unvollständigen Stück.
- Kachel auf der Übersicht (BA15); Drucken und CSV der Anforderungen; Bestellbestätigung als Dokument (F166);
  Packung als eigene Angabe (BA28 – bis dahin der Vermerk); Scannen der GTIN im Dialog; Artikelgruppe anlegen oder
  zuordnen beim Bestellen (BA12, BU3); Freigabegrenzen nach Wert (BA6); „als Anlagegut anlegen“ für Geräte.

### 10.4 Geprüft

`php tests/lauf.php Beschaffung` (150 Prüfungen: der ganze Vorgang mit fünf Rollen, ein Buch ohne Lager),
`php tests/lauf.php Lager` (436), `php tests/lauf.php Oberflaeche` (112), `node tests/browser/beschaffung.mjs` (49),
`node tests/browser/lager.mjs` (153, nach dem Umbau der Nachbestellung auf den Dienst).
Nicht geprüft: PHP 8.5, PostgreSQL 19, das Einschalten auf der VM, ein Update eines Bestands.

## 11. Aufgenommen am 2026-11-25, 17:37: Der Dialog „Anforderung“ als Grid (F223) – nicht gebaut

Am Bild des Dialogs „Anforderung neu“ (leer: Wofür, Gewünscht bis, der Kasten „Noch keine Position“, darunter „Position
dazu“ mit der Auswahlliste „Was“ und den Feldern Bezeichnung, Menge, Einheit, Vermerk, „Dazu“):

> „Das ist nicht wirklich ergonomisch. Aus meiner Sicht muss das ein Grid sein wie alle Steuerelemente, das filterfähig
> ist. Baue nichts, nimm nur die Anforderungen auf.“

### 11.1 Die Anforderung

| # | Anforderung | Herkunft |
|---|---|---|
| GR1 | Der Dialog, wie er mit 0.7.98 gebaut ist, ist **nicht ergonomisch**. | wörtlich |
| GR2 | Die Eingabe muss ein **Grid** sein. | wörtlich |
| GR3 | Das Grid ist **filterfähig**. | wörtlich |
| GR4 | Es verhält sich **wie alle Steuerelemente** in kumero – kein eigener Weg für diesen Dialog. | wörtlich |

### 11.2 Wie das gelesen ist – zu bestätigen

Der Satz sagt nicht, *was* im Grid steht. Gelesen ist er so:

| # | Lesart | Warum |
|---|---|---|
| GR5 | **Was heute die Auswahlliste „Was“ ist, wird ein Grid der Artikel und Artikelgruppen:** eine Liste mit Spalten (Bezeichnung, Nummer, Einheit, Artikelgruppe, verfügbar, Mindest, fehlt, schon angefordert oder bestellt), mit Sortierung, Filter und Freitext am Spaltenkopf (F91, F198) und verschiebbaren Spalten (F146) – derselbe Baustein wie in „Bestand“ und im „Vorschlag“. | Eine Auswahlliste mit einigen hundert Artikeln lässt sich nicht filtern; „wie alle Steuerelemente“ verweist auf die Listen mit Spaltenkopf. |
| GR6 | **Angefordert wird in der Zeile:** Die Menge steht als Eingabefeld in der Zeile des Artikels; was eine Menge trägt, ist Position. Kein „wählen – Felder füllen – Dazu“ je Position. | Das ist der Schritt, der heute je Position vier Handgriffe kostet. |
| GR7 | **Die Positionen der Anforderung sind dasselbe Grid, gefiltert** („nur was angefordert ist“) – nicht ein zweiter Kasten darüber. | Ein Grid statt zweier Bereiche; nichts verschiebt sich (F106). |
| GR8 | **Was kein Artikel ist** – „neu fürs Lager“ und „nicht fürs Lager“ (BA12, BA16) – wird als eigene Zeile im selben Grid erfasst: Bezeichnung, Einheit, Menge, Vermerk direkt in der Zeile. | Diese Positionen gibt es viel (9.4); sie brauchen denselben Weg, nicht einen Nebenweg. |
| GR9 | **Der Vorschlag aus dem Bestand geht im selben Grid auf:** „fehlt“ und „Vorschlag“ sind Spalten, ein Filter „unter Mindestbestand“ zeigt, was die Ansicht „Vorschlag“ heute zeigt. Ob die eigene Ansicht dann entfällt, ist offen. | Es ist dieselbe Liste der Artikel, einmal gefiltert. |
| GR10 | **Tastatur und Scanner:** Tippen filtert, Pfeiltasten wechseln die Zeile, Enter springt in die Menge; ein gescannter Code (GTIN) findet die Zeile. | „ergonomisch“ – so arbeiten die anderen Listen; der Scanner ist das laufende Beispiel. |

### 11.3 Offen dazu

- Ist GR5 gemeint – ein Grid der **Artikel**, in dem man anfordert – oder nur ein Grid der **Positionen** (Zeilen direkt
  bearbeitbar, die Auswahl des Artikels bleibt eine Liste)?
  **Beantwortet am 2026-11-25, 17:47 (F225): das Grid der Positionen, frei auszufüllen – Abschnitt 13.**
- Bleibt es ein **Dialog**, oder wird die Anforderung eine eigene **Seite**? Ein Grid mit acht Spalten und Filter
  braucht Platz.
- Gilt dasselbe für die anderen Dialoge mit Positionen – „Als bestellt abhaken …“ (mehrere Positionen auf einmal, im
  Grid) und im Lager die Stückliste im Dialog des Artikels?
- Welche Spalten zuerst – und sieht, wer nur anfordern darf, den Bestand (verfügbar, Mindest)?

## 12. Aufgenommen am 2026-11-25, 17:42: Wareneingangskontrolle und Zugang nach der Bestellung (F224) – nicht gebaut

Am Bild der Seite „Wareneingang“ (Auswahl „Beleg“ mit „– kein Beleg mit Positionen –“, „Zugang ohne Beleg …“, „Zugang
buchen“, das Feld „Code scannen“, darunter eine leere Fläche):

> „Für die Folge nach einer BANF muss dann die Wareneingangskontrolle und der Zugang gemacht und dokumentiert werden
> können. Nimm das in die Anforderungen mit auf.“

### 12.1 Die Anforderung

| # | Anforderung | Herkunft |
|---|---|---|
| WK1 | Der Vorgang endet nicht mit „bestellt“: **Nach der Bestellanforderung folgen Wareneingangskontrolle und Zugang.** | wörtlich |
| WK2 | Beides muss sich in kumero **machen** lassen – auf der Seite „Wareneingang“, die heute nur Belege aus dem Arbeitsvorrat kennt. | wörtlich; Ort aus dem Bild |
| WK3 | Beides muss **dokumentiert** sein: Es bleibt nachlesbar, dass kontrolliert wurde und was zugegangen ist. | wörtlich |

### 12.2 Wie das gelesen ist – zu bestätigen

| # | Lesart | Warum |
|---|---|---|
| WK4 | **Die Bestellung ist eine Quelle des Wareneingangs** (BA13): In der Auswahl oben stehen neben den Belegen die offenen Bestellungen (Lieferant, Bestellnummer, Bestelldatum, Liefertermin; überfällige gekennzeichnet). Gewählt, stehen ihre Positionen mit Artikel und **bestellter** Menge da (BA28). | Das Bild zeigt genau die Lücke: „kein Beleg mit Positionen“, obwohl bestellt ist. |
| WK5 | **Die Wareneingangskontrolle ist ein eigener Schritt vor dem Zugang**, je Position: stimmt der Artikel (Abgleich über die gescannte GTIN, wo eine da ist), stimmt die Menge (geliefert gegen bestellt), ist die Ware unversehrt, stimmen Charge bzw. Seriennummer und Verfall mit der Packung, reicht die Restlaufzeit. | „Wareneingangskontrolle“ ist mehr als die Menge einzutragen; welche Prüfpunkte gelten, ist offen (12.3). |
| WK6 | **Das Ergebnis je Position:** in Ordnung · mit Abweichung angenommen · nicht angenommen – bei Abweichung mit Grund aus einer Liste (Fehlmenge, Mehrmenge, falscher Artikel, beschädigt, Verfall zu kurz, Kühlkette fraglich) und einem Satz dazu. | Dokumentiert heißt: Die Abweichung steht da, nicht nur die gebuchte Zahl. |
| WK7 | **Der Zugang folgt aus der Kontrolle:** Gebucht wird, was angenommen ist – mit Charge bzw. Seriennummer, Verfall, Lagerort, wie beim Zugang heute. Was nicht angenommen ist, geht nicht in den Bestand; die Position der Bestellung bleibt mit dieser Menge offen oder wird abgeschlossen (der Einkäufer entscheidet, BA27). | Kein Zugang ohne Kontrolle, kein Bestand aus abgelehnter Ware. |
| WK8 | **Was gesperrt angenommen wird:** Ware mit Abweichung, über die noch entschieden wird, geht als **gesperrte** Charge in den Bestand (die Sperre gibt es) – sichtbar, aber nicht entnehmbar. | Die Ware liegt körperlich da; verschwinden darf sie nicht. |
| WK9 | **Die Dokumentation ist ein Wareneingangsprotokoll:** je Lieferung wer, wann, zu welcher Bestellung und Anforderung, je Position bestellt, geliefert, angenommen, Ergebnis der Kontrolle mit Grund, Charge bzw. Seriennummer, Verfall, Lagerort – nachlesbar an der Bestellung, an der Anforderung und in der Rückverfolgung der Charge; druckbar. Unveränderlich: berichtigt wird durch einen Nachtrag. | „dokumentiert“ – und im Lager gilt schon: Bewegungen werden nur angefügt. |
| WK10 | **Der Lieferschein** (Papier oder PDF) ist ein Dokument an der Lieferung – über die Dokument-Verweise (F166, Paperless); bis dahin seine Nummer als Feld. | Die Kontrolle geschieht gegen den Lieferschein. |
| WK11 | **Rückwirkung auf die Anforderung:** Der Zugang setzt die Position auf „erhalten“ bzw. „teilweise erhalten“; „Eingang melden“ von Hand (BU4) entfällt dann für Lagerware und bleibt für „nicht fürs Lager“ – dort ist die Kontrolle dieselbe, nur ohne Zugang. | So schließt sich der Vorgang von der BANF bis zum Bestand. |
| WK12 | **Rolle:** Kontrolle und Zugang macht, wer im Lager bewegen darf (`lager.bewegung.buchen`); er sieht dabei die Bestellung ohne Preise. Ob jemand anderes als der Besteller kontrollieren muss, ist offen. | Ein Entwurf nennt seine Rollen (CLAUDE.md); BA5: kein Vier-Augen neben den Rollen. |

### 12.3 Offen dazu

- **Welche Prüfpunkte** gehören zur Wareneingangskontrolle bei euch – feste Liste für alle, oder je Art des Artikels
  (Arzneimittel, Medizinprodukt, Verbrauch)? Gibt es eine Vorgabe aus eurem Qualitätsmanagement, die abzubilden ist?
- **Teillieferungen:** je Lieferung ein eigenes Protokoll zur selben Bestellung?
- **Lieferung ohne Bestellung in kumero** („Zugang ohne Beleg“): gilt die Kontrolle dort genauso?
- **Rechnung und Bestellung** zur selben Ware (BA14): Die Kontrolle hängt an der Lieferung – die Rechnung wird nur
  verknüpft. Lesart weiter zu bestätigen.
- **Reklamation und Rücksendung** nach „nicht angenommen“: nur als Zustand und Vermerk, oder ein eigener Vorgang?
- **Kühlware:** Temperatur beim Eingang als Messwert erfassen (ARTIKELEIGENSCHAFTEN.md AE3, AE4)?

## 13. Beschrieben am 2026-11-25, 17:47: Die Anforderung ohne Artikel – ein Grid zum Ausfüllen (F225) – nicht gebaut

Am Bild des Dialogs „Anforderung neu“ (Wofür „Bestellung Praxisbedarf Dezember 2026“, gewünscht bis 20.12.2026, noch
keine Position):

> „Die BANF muss auch ohne Artikel gehen, es muss also einfach ein Grid aufgefüllt werden können, das Bezeichnung und Co.
> enthält. Bitte nur beschreiben, nicht bauen.“

Das berichtigt Abschnitt 11: Gemeint ist nicht zuerst ein Grid der Artikel (GR5), sondern **das Grid der Positionen**,
in das man schreibt. Der Artikel ist eine Zugabe, keine Voraussetzung.

### 13.1 Die Anforderung

| # | Anforderung | Herkunft |
|---|---|---|
| FG1 | **Eine Anforderung braucht keinen Artikel.** Sie lässt sich vollständig anlegen und anfordern, ohne dass es im Lager einen Artikel gibt oder einer gewählt wird. | wörtlich |
| FG2 | **Die Positionen sind ein Grid, das man einfach ausfüllt:** Zeile für Zeile, Zelle für Zelle, ohne Auswahlliste davor und ohne „Dazu“. | wörtlich |
| FG3 | Das Grid enthält **Bezeichnung „und Co.“** – die Angaben, die eine Position beschreiben (13.2). | wörtlich; die Spalten sind Lesart |

### 13.2 Wie das beschrieben ist – zu bestätigen

**Das Grid**

| # | Beschreibung |
|---|---|
| FG4 | **Spalten:** Nr. · **Bezeichnung** · **Menge** · **Einheit** · Vermerk · Artikel (leer oder zugeordnet) · fürs Lager (ja/nein). Pflicht je Zeile sind nur Bezeichnung, Menge und Einheit; die Einheit ist mit „Stück“ vorbelegt. |
| FG5 | **Immer eine leere Zeile am Ende.** Wer in sie schreibt, hat eine Position angelegt, und darunter steht die nächste leere. Eine Zeile ohne Bezeichnung zählt nicht und wird nicht gespeichert. |
| FG6 | **Tastatur wie in einer Tabelle:** Tab und Umschalt+Tab von Zelle zu Zelle, Enter in die nächste Zeile derselben Spalte, Pfeil hoch und runter zwischen den Zeilen. Zeile entfernen über einen Knopf am Zeilenende und mit Strg+Entf. Einfügen mehrerer Zeilen aus der Zwischenablage (aus einer Tabelle oder Liste kopiert: je Zeile eine Position, Spalten in der Reihenfolge des Grids). |
| FG7 | **Filterfähig wie alle Listen** (GR3, GR4): Sortierung, Filter und Freitext am Spaltenkopf, Spalten verschiebbar (F91, F146, F198) – derselbe Baustein, hier mit bearbeitbaren Zellen. Ein Filter blendet nur aus; gespeichert werden alle Zeilen. |
| FG8 | **Nichts verschiebt sich** (F106): Das Grid hat eine feste Höhe und rollt in sich; Fehler stehen an der Zelle und in einer festen Zeile darunter („Zeile 3: Menge fehlt“), der Knopf zum Speichern ist so lange gesperrt und nennt den Grund. |

**Der Artikel als Zugabe**

| # | Beschreibung |
|---|---|
| FG9 | **Beim Tippen in „Bezeichnung“ schlägt kumero passende Artikel und Artikelgruppen vor** (Bezeichnung, Nummer, GTIN – auch gescannt). Wer einen Vorschlag annimmt, hat die Position dem Artikel zugeordnet: Bezeichnung und Einheit kommen vom Lager und sind dann fest. Wer weitertippt, hat eine freie Position. Der Vorschlag drängt sich nicht auf und ändert nie von selbst, was getippt ist (SA6). |
| FG10 | **Ohne Lager** (das Modul ist im Buch nicht aktiv) gibt es keine Vorschläge und keine Spalten „Artikel“ und „fürs Lager“ – das Grid bleibt dasselbe. |
| FG11 | **„Fürs Lager“ muss nicht der Anforderer entscheiden:** Vorgabe ist „offen“. Eine Zeile mit Artikel ist fürs Lager; bei einer freien Zeile legt es spätestens der Einkauf beim Abhaken fest – dort wird sie ein Artikel (BA12) oder bleibt „nicht fürs Lager“ (BA16). Der Anforderer kann es angeben, wenn er es weiß. |
| FG12 | **Der Vorschlag aus dem Bestand** bleibt der zweite Weg: „In Anforderung übernehmen …“ füllt dasselbe Grid mit den markierten Artikeln vor; weitere Zeilen tippt man dazu. |

**Was sich gegenüber 0.7.98 ändert**

| # | Beschreibung |
|---|---|
| FG13 | Es entfallen im Dialog: die Auswahlliste „Was“ mit ihren vier Arten, der Kasten „Position dazu“, der Knopf „Dazu“ und der getrennte Kasten der Positionen. An ihre Stelle tritt das eine Grid. Kopf (Wofür, Gewünscht bis) und die Knöpfe „Als Entwurf speichern“, „Speichern und anfordern“ bleiben. |
| FG14 | **Daten und Schnittstelle bleiben:** Eine freie Zeile ist heute schon eine Position mit Bezeichnung und Einheit ohne Artikel. Neu wäre nur der Zustand „fürs Lager: offen“ (heute muss es beim Anfordern feststehen) – das ist eine Migration und eine Änderung an der Prüfung. |

### 13.3 Offen dazu

- **Welche Spalten gehören zu „und Co.“?** Vorgeschlagen sind Bezeichnung, Menge, Einheit, Vermerk. Dazu denkbar:
  Hersteller, Artikelnummer des Herstellers, gewünschter Lieferant oder Link zum Angebot im Shop, geschätzter Preis,
  wofür je Position, gewünscht bis je Position.
- **Link zum Shop** je Position: Der Einkauf bestellt online – ein Feld für die Adresse des Angebots wäre das, womit er
  arbeitet. Aufnehmen?
- **FG11:** Darf „fürs Lager“ beim Anfordern offen bleiben, oder soll der Anforderer es immer sagen?
- **Dialog oder Seite** (schon in 11.3 gefragt): Ein Grid mit sieben Spalten ist im Dialog eng.
- **Dasselbe Grid beim Abhaken?** Mehrere Positionen einer Bestellung in einem Zug erfassen statt Dialog je Position.

### 13.4 Entscheidungen vom 2026-11-25, 17:57 (F226) – und wie sie gelesen sind

| Vorgabe des Verantwortlichen | Stand |
|---|---|
| „Spalten: eine Aut-idem-Spalte.“ | **entschieden** – Lesart FG15 |
| „Ausfüllen passt.“ · „Tastatur passt.“ | **FG5, FG6 bestätigt** |
| „Artikel als Vorschlag passt; bei Enter werden die Werte aus der vorherigen Bestellung übernommen oder einfach bis zum Sollbestand aufgefüllt. Die Menge kann geändert werden. Artikelgruppen sind zu bevorzugen.“ | **FG9 bestätigt**, dazu FG16–FG18 |
| „Fürs Lager: zugestimmt.“ · „Fürs Lager darf natürlich offen bleiben.“ | **FG11 bestätigt** – damit ist die Migration aus FG14 nötig |
| „Termin pro Position, ggf. Hersteller, Hersteller-Nr. als Option.“ | **entschieden** – FG19, FG20 |
| „Dasselbe Grid sollte für die tatsächliche Beschaffung gelten und abgehakt werden können. Die tatsächliche Beschaffung ist dann Basis der Wareneingangskontrolle.“ | **entschieden** – FG21–FG24; bestätigt WK4 |

| # | Gelesen als |
|---|---|
| FG15 | **„aut idem“ je Position:** ein Haken „oder ein gleichwertiges“. Gesetzt, darf der Einkauf ein anderes, gleichwertiges Produkt bestellen (anderer Hersteller, andere Packung); nicht gesetzt, wird genau das Genannte bestellt. Bei einer **Artikelgruppe** ist der Haken gesetzt und fest – „einer davon“ ist ihr Sinn. Bestellt der Einkauf etwas anderes, steht an der Position, was angefordert und was bestellt wurde. **Vorgabe des Hakens (an oder aus) ist offen.** |
| FG16 | **Enter auf einem Vorschlag füllt die Zeile:** Bezeichnung und Einheit vom Artikel bzw. der Gruppe, dazu die **Menge** – und danach steht der Cursor in der Menge, markiert, zum Überschreiben. |
| FG17 | **Woher die Menge kommt** – „aus der vorherigen Bestellung **oder** bis zum Sollbestand“: Hat der Artikel bzw. die Gruppe einen Sollbestand (sonst Mindestbestand) und liegt darunter, ist es die Menge bis dorthin, abzüglich dessen, was schon angefordert oder bestellt ist (wie der Vorschlag, BU13). Sonst die zuletzt bestellte Menge. Gibt es beides nicht, bleibt die Menge leer. Die Zeile nennt in einem Hinweis, woher die Zahl stammt („bis Soll 20“ · „wie am 12.11.“). **Die Reihenfolge der beiden Quellen ist zu bestätigen.** Aus der vorherigen Bestellung kommen außerdem Vermerk (Packung), Hersteller und Herstellernummer mit – **nicht** Lieferant und Preis (BA29: der Lieferant steuert nichts). |
| FG18 | **Artikelgruppen sind zu bevorzugen:** In den Vorschlägen stehen Gruppen vor Artikeln. Gehört ein vorgeschlagener Artikel zu einer Gruppe, steht die Gruppe als erster Vorschlag da und der Artikel darunter; wer den Artikel trotzdem wählt, bekommt genau ihn (dann greift „aut idem“ aus FG15). Der Vorschlag aus dem Bestand führt Gruppen schon als eine Zeile. |
| FG19 | **„Gewünscht bis“ je Position** als Spalte; leer gilt der Termin im Kopf. Der Kopf behält sein Feld als Vorgabe für alle Zeilen. |
| FG20 | **Hersteller und Herstellernummer als wählbare Spalten:** vorhanden, aber in der Vorgabe ausgeblendet; einblenden über die Spaltenwahl am Kopf (wie verschiebbare Spalten, F146 – das Ein- und Ausblenden von Spalten gibt es am Baustein noch nicht und käme dazu). Die Wahl merkt sich der Browser je Nutzer. |
| FG21 | **Ein Grid, zwei Arbeitsschritte:** Die Anforderung und die tatsächliche Beschaffung sind dasselbe Grid der Positionen. Der Anforderer sieht die linken Spalten (was gebraucht wird); der Einkauf sieht rechts daneben die Spalten der Beschaffung und füllt sie in der Zeile: **bestellt** (Haken), Menge bestellt, Artikel, Lieferant, Artikelnummer dort, Bestellnummer, bestellt am, Liefertermin, Preis je Einheit, Vermerk. Die linken Spalten sind nach der Freigabe fest. |
| FG22 | **Abhaken in der Zeile, mehrere in einem Zug:** Der Haken „bestellt“ ist erst setzbar, wenn die Pflichtangaben der Zeile stehen (Menge, Lieferant, Datum – BU10; für Lagerware der Artikel, BA12), sonst nennt er den Grund. Lieferant, Bestellnummer und Termine lassen sich für die markierten Zeilen gemeinsam setzen (ein Warenkorb, BA23). Gespeichert wird das Grid als Ganzes. Der Dialog „Als bestellt abhaken …“ je Position entfällt; das Anlegen eines neuen Artikels bleibt ein Dialog aus der Zeile heraus. |
| FG23 | **Teilen, Streichen, Zurücknehmen** (BA27, BU9) bleiben – als Handlungen an der Zeile. |
| FG24 | **Die tatsächliche Beschaffung ist die Basis der Wareneingangskontrolle:** Kontrolliert wird gegen das, was bestellt ist (Artikel, bestellte Menge, Lieferant, Bestellnummer) – nicht gegen das Angeforderte. Der Wareneingang zeigt dieselben Zeilen weiter rechts mit den Spalten der Kontrolle und des Zugangs (geliefert, angenommen, Ergebnis, Charge bzw. Seriennummer, Verfall, Lagerort – Abschnitt 12). So läuft eine Position von links nach rechts durch: angefordert → bestellt → kontrolliert → zugegangen. |

**Noch nicht beantwortet** aus 13.3: Link zum Angebot im Shop je Position; geschätzter Preis; Dialog oder eigene Seite –
mit FG21 (bis zu zwanzig Spalten) spricht alles für eine **eigene Seite** je Anforderung.

### 13.5 Entscheidungen vom 2026-11-25, 18:07 (F227)

| Vorgabe des Verantwortlichen | Stand |
|---|---|
| „Der Aut-idem-Haken ist per Default nicht gesetzt.“ | **entschieden** – FG15: Vorgabe aus. Bei einer Artikelgruppe bleibt er gesetzt und fest (ihr Sinn ist „einer davon“) – **das ist weiter Lesart.** |
| „2. Zustimmung; bei der Frage: Ja, erst Sollbestand und ohne vorherige BANF.“ | **FG16, FG17, FG18 bestätigt.** Reihenfolge der Menge: **erst bis zum Sollbestand** (abzüglich dessen, was unterwegs ist); **gibt es keinen Sollbestand, die Menge aus der vorherigen BANF.** Lesart FG25. |
| „3. Eigener Dialog für die Beschaffung ist OK.“ | **entschieden** – Lesart FG26, FG27. |

| # | Gelesen als |
|---|---|
| FG25 | **„Vorherige BANF“ heißt: die Menge, die zuletzt für diesen Artikel bzw. diese Gruppe angefordert wurde** – nicht die zuletzt bestellte (der Einkäufer kann davon abgewichen sein, BA27). In FG17 stand „zuletzt bestellte Menge“; das ist hiermit ersetzt. Gezählt wird die letzte Anforderung, die nicht abgelehnt und nicht zurückgezogen ist. |
| FG26 | **Zwei Dialoge, ein Grid:** Die Anforderung bleibt ein Dialog mit dem Grid der Positionen (FG4–FG12, FG15, FG19, FG20). Die tatsächliche Beschaffung bekommt einen **eigenen Dialog** „Beschaffung“ zur freigegebenen Anforderung: dasselbe Grid mit denselben Zeilen – die Spalten der Anforderung fest und schmal (Bezeichnung, angefordert, aut idem, gewünscht bis), daneben die Spalten des Einkaufs zum Ausfüllen und Abhaken (FG21, FG22). Eine eigene Seite braucht es dafür nicht. |
| FG27 | **Der Dialog „Beschaffung“ ersetzt „Als bestellt abhaken …“ je Position.** Geöffnet wird er an der Anforderung (Knopf an festem Platz und Doppelklick auf eine freigegebene Anforderung, F150); er zeigt alle ihre Positionen, offene zuerst. Teilen, Streichen, Haken zurücknehmen und „Artikel neu …“ sind Handlungen an der Zeile. |

### 13.6 Entscheidung vom 2026-11-25, 18:10 (F228): der Link zum Shop

> „Ein Link zum Shop wäre nur ein Feld, am besten mit dem kleinen Dreieck davor und einer Zwischenzeile.“

| # | Gelesen als |
|---|---|
| FG28 | **Der Link zum Angebot im Shop ist ein Feld der Position** – ein Feld, kein eigener Gegenstand und keine eigene Spalte. |
| FG29 | **Vor jeder Zeile steht das kleine Dreieck** (wie an der Artikelgruppe im Bestand, LAGER.md AD16). Aufgeklappt erscheint unter der Position eine **Zwischenzeile** über die ganze Breite; darin steht das Feld „Link zum Shop“. Zugeklappt kostet es keinen Platz. Tastatur: → klappt auf, ← klappt zu. |
| FG30 | **Die Zwischenzeile ist der Platz für alles, was selten gebraucht wird und lang ist** – zuerst nur der Link. Kandidaten, ohne Auftrag: ein längerer Vermerk, Hersteller und Herstellernummer (statt wählbarer Spalten, FG20). |
| FG31 | **Hat eine Position einen Link, zeigt das die zugeklappte Zeile** (Dreieck gefüllt bzw. ein Zeichen daneben), damit er nicht übersehen wird. Im Dialog „Beschaffung“ ist der Link **anklickbar** und öffnet das Angebot in einem neuen Fenster – damit arbeitet der Einkauf. kumero ruft die Adresse selbst nie ab (keine externe Schnittstelle). |
| FG32 | **Geprüft wird nur die Form:** eine Adresse mit `https://` (oder `http://`), höchstens 500 Zeichen. Wer einfügt, was nicht so aussieht, bekommt es gesagt; gespeichert wird nichts Stilles. Nach der Freigabe ändert den Link nur noch der Einkauf. |

**Nichts verschiebt sich von selbst (F106):** Die Zwischenzeile erscheint nur, wenn der Nutzer das Dreieck bedient; welche
Zeilen offen sind, bleibt beim Neuzeichnen erhalten.

**Offen bleibt** aus 13.3 nur noch der geschätzte Preis je Position.

