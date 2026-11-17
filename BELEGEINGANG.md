# BELEGEINGANG.md – Arbeitsvorrat: offene Belege aus Paperless, vorbefüllt mit den Daten aus nopaper (Entwurf 2, zur Freigabe)

Stand: 2026-11-17. Bezug: T8 (Belege liegen in Paperless), F10/F27 (Belegverweis), F31 (Stapel),
F51 (Paperless → Buchhaltung: Eingangskorb, Buchungsvorschlag nur als Entwurf), F55 (nopaper anbinden),
F83 (Modul `buchen`), nopaper AGENTS.md E10, E15, E34–E39 und Abschnitt 6.1 (Sidecar).
**Status: Entwurf. Gebaut wird erst nach Freigabe** (Entscheidungen B1–B14 in Abschnitt 8; B2, B2a, B8, B12
und B13 sind am 2026-11-17 entschieden). Der Auftrag an das Projekt nopaper steht in **NOPAPER-AUFTRAG.md**.

Anlass: Belege liegen in Paperless (T8). Welche davon **noch zu buchen** sind, kennzeichnet dort ein Tag.
Diese offenen Belege bilden in kumero den **Arbeitsvorrat**. nopaper liest gescannte Rechnungen und Belege
aus und liefert je Beleg eine XML-Datei mit den Rechnungsdaten an kumero. Wird ein Beleg aus dem
Arbeitsvorrat aufgerufen, gleicht kumero ihn mit den Daten aus nopaper ab und öffnet die Buchungserfassung
**vorbefüllt je nach Belegart und erkannten Werten**. Gebucht wird weiter von einem Menschen.

---

## 1. Was nopaper liefert (Stand des öffentlichen Repositorys)

- **Je Beleg eine Datei** `<paperless-id, 7-stellig>.xml` im Abholordner
  (`/srv/nopaper/ausgang/rechnungsdaten`, Gruppe `nopaper-abholung`), atomar geschrieben; Dateien mit
  Punkt am Anfang sind unfertig. Die Abholung löscht (nopaper E15), nopaper behält keine Kopie.
- **Format:** UN/CEFACT CII D16B, Profil Factur-X/ZUGFeRD BASIC WL – das Datenmodell der EN 16931 **ohne
  Positionen**. Es ist eine Buchungshilfe, **keine E-Rechnung** im Sinne von § 14 UStG.
- **Inhalt (nur Kopfdaten):** Rechnungsnummer, Rechnungs- und Leistungsdatum, Fälligkeit, Rechnungssteller
  mit Steuernummer/USt-IdNr., Brutto, Netto, Steuer je Satz, Zahlbetrag, Skonto, IBAN, Verwendungszweck,
  Dokumentart 380 (Rechnung) bzw. 381 (Gutschrift).
- **Herkunftsnachweis** in der Notiz des Dokuments (`SCAN-SIDECAR/1.0`): `paperless-document-id`,
  `paperless-url`, `sha256-original`, `sha256-archiv`, `quelle`, `pruefstatus` (`ok` | `pruefen`),
  `pruefhinweise` (Codes wie `BRUTTO_NICHT_IM_TEXT`, `DUPLIKAT_VON_DOKUMENT_<id>`), Angaben zur Extraktion.
- **Belegart** (rechnung, kassenbon, bewirtung, versicherung, behoerde, gutschrift) steht heute **nur im
  Paperless-Feld** „Erkannt: Belegart“, im Sidecar nur als 380/381 → Entscheidung B4.
- nopaper ist **buchhaltungsneutral** (E38): Es erkennt, was auf dem Beleg steht, und entscheidet nie über
  Konto, Vorsteuer oder Periode. Sidecars kommen auch bei Prüfstatus „prüfen“ (E36); geprüft wird durch
  die Buchung.
- Rückweg laut nopaper E39: kumero schreibt je gebuchtem Beleg eine **Korrekturdatei** (`<uuid>.json`),
  aus der nopaper lernt. Die dort genannten Entscheidungsnummern (F66–F69) stammen aus einem älteren
  Stand und bedeuten in kumero heute etwas anderes → Entscheidung B11.

## 1a. Ein Arbeitsvorrat, viele Quellen

Entschieden am 2026-11-17: Der Arbeitsvorrat füllt sich aus **verschiedenen Quellen** – nopaper, Paperless,
Online-Konten und weitere. Er ist deshalb unabhängig von der Quelle geschnitten:

| Quelle | Was sie beiträgt | Modul | Stand |
|---|---|---|---|
| Paperless | Belege mit dem Tag für offene Belege (Abschnitt 2a) | `paperless` | Entwurf: PAPERLESS.md |
| nopaper | erkannte Rechnungsdaten je Beleg (Sidecar, Abschnitt 3) | `belegeingang` | dieser Entwurf |
| Online-Konten | Kontoumsätze, die noch keiner Buchung zugeordnet sind | `bank` (CAMT, später FinTS) | eigenes Modul, eigener Schritt: ONLINEKONTEN.md (zurückgestellt) |
| E-Rechnungen | XRechnung, ZUGFeRD | `e-rechnung` | noch zu beschreiben |
| von Hand | selbst angelegter Vorgang („noch zu buchen“) | `belegeingang` | dieser Entwurf |

- **Ein Eintrag je Vorgang, nicht je Quelle:** Beleg, nopaper-Daten und Kontoumsatz zum selben Vorgang
  gehören zusammen (Rechnung in Paperless + erkannte Werte + Abbuchung auf dem Konto). Der Abgleich läuft
  über Paperless-ID, Betrag, Datum und Rechnungsnummer bzw. Verwendungszweck; unsichere Treffer werden nur
  vorgeschlagen, nie still verbunden.
- **Quellen melden sich an:** Ein Modul liefert Einträge und Ergänzungen über einen Erweiterungspunkt des
  Arbeitsvorrats; der Arbeitsvorrat kennt keine Quelle beim Namen. So kommen Online-Konten später dazu,
  ohne dass sich Liste und Maske ändern.
- Der Kontoumsatz liefert bei unbaren Zahlungen zugleich das **Buchungsdatum** (Tag des Geldflusses, B8).

## 2. Ablauf

```
Scanner → nopaper → Paperless: Original, Felder „Erkannt: …“, Tag „zu buchen“
                 └→ kumero:    Sidecar 0000042.xml  (HTTPS, Bearer-Token, Abschnitt 3)

kumero  Arbeitsvorrat = Belege in Paperless mit dem Tag für offene Belege (Abschnitt 2a)
Nutzer  Beleg aufrufen → Abgleich mit den nopaper-Daten zur selben Paperless-ID
        → Buchungserfassung vorbefüllt → Speichern (Stapel) oder Buchen
kumero  nach dem Festschreiben: Belegverweis an der Buchung (F10), Tag in Paperless entfernen (B14),
        Buchungsdaten nach Paperless zurückschreiben (F49)
```

## 2a. Arbeitsvorrat aus dem Paperless-Tag

Entschieden am 2026-11-17: **Der Arbeitsvorrat kommt aus Paperless**, nicht aus dem Eingang der Sidecars.

- **Das Tag** für offene, noch zu buchende Belege wird in den **Einstellungen des Nutzers** festgelegt
  (PROFIL.md U11), an seiner Paperless-Verbindung für den Mandanten (PAPERLESS.md Abschnitt 2). kumero
  liest Paperless mit dem **eigenen Token des Nutzers**; der Arbeitsvorrat aus Paperless ist damit je Nutzer.
- **Mehrere Paperless:** Der Mandant kann eine Instanz vorgeben, der Nutzer weitere einrichten
  (PAPERLESS.md L11). Der Arbeitsvorrat führt die offenen Belege aller Instanzen des Nutzers zusammen und
  nennt je Beleg die Instanz; abgeglichen wird über den **ganzen Link** des Belegs (PAPERLESS.md L15).
- kumero liest die Belege mit diesem Tag über das Modul `paperless` (AGENTS.md P13) – der Arbeitsvorrat
  setzt dieses Modul also voraus. Angezeigt werden Titel, Datum, Korrespondent und die Felder
  „Erkannt: …“, soweit vorhanden.
- **Abgleich beim Aufruf:** Zur Paperless-ID des Belegs sucht kumero den eingelieferten Sidecar.
  - Sidecar da und SHA-256 des Originals stimmt mit Paperless überein → Maske vorbefüllt (Abschnitt 6).
  - Sidecar da, aber **Abweichung** (anderes Original, ältere Fassung) → nichts vorbefüllen, Hinweis.
  - **Kein Sidecar** (von Hand abgelegter Beleg, nopaper noch nicht fertig) → leere Maske mit dem Beleg
    daneben; der Beleg ist trotzdem buchbar.
- Ein Sidecar ohne Tag in Paperless erzeugt keinen Eintrag im Arbeitsvorrat; er wird aufbewahrt und beim
  Aufruf verwendet, falls der Beleg später das Tag bekommt.
- Der Arbeitsvorrat ist damit immer so aktuell wie Paperless: Tag gesetzt = offen, Tag entfernt = erledigt
  oder kein Buchungsfall.

## 3. Schnittstelle: nopaper liefert Sidecars ein

Entschieden am 2026-11-17: nopaper und kumero laufen auf **getrennten Maschinen**. **nopaper liefert selbst
ein** (B2a); die Vorgaben der Schnittstelle macht kumero, umgesetzt wird sie im Projekt nopaper – der
Auftrag dafür ist **NOPAPER-AUFTRAG.md**. Die Verbindung nutzt nur Protokolle, die über einen
**Reverse-Proxy** (z. B. Caddy) laufen: gewöhnliches HTTPS mit einer Anfrage und einer Antwort, keine
Client-Zertifikate, keine dauerhaft offenen Verbindungen, keine Abhängigkeit von der Adresse des Absenders.

```
PUT /m/{mandant}/api/v1/belegeingang/sidecars/{paperless_id}
Authorization: Bearer kmr_…            Dienstkonto des Mandanten, nur mit dem Recht einzuliefern
Content-Type: application/xml           der Sidecar unverändert, höchstens 1 MB
Idempotency-Key: <SHA-256 der Datei>    Wiederholung nach Abbruch ändert nichts (F71)

200/201  { "ergebnis": "angelegt" | "unveraendert" | "ersetzt" | "vermerkt", "paperless_id": 42, "sha256": "…" }
401/403  Token ungültig, abgelaufen oder ohne Recht
413      zu groß
422      Problem Details mit Code (z. B. sidecar.kennung, sidecar.id_abweichend) – nur Codes, keine Inhalte
429      zu viele Anfragen, mit Retry-After
5xx      vorübergehend – später erneut

GET /m/{mandant}/api/v1/belegeingang/status      prüft Adresse, Token und Recht (für `nopaper check`)
```

- **Jede 2xx-Antwort heißt: angenommen**, der Absender darf die Datei löschen (nopaper E15). `ersetzt`: ein
  neuerer Sidecar zur selben Paperless-ID; `vermerkt`: der Beleg ist schon in Arbeit oder gebucht, der neue
  Sidecar wird nur als Hinweis festgehalten.
- kumero ruft nopaper nie auf und braucht keinen Zugang dorthin.
- **Token:** Dienstkonto `dienst:<uuid>` je Mandant mit Ablaufdatum; in kumero nur als SHA-256 gespeichert.
  Es darf **nur einliefern**: keine Buchungen lesen, nie freigeben (F78 O3). Ausstellen und Widerrufen über
  die Kommandozeile, später die Verwaltung.
- **Geprüft wird streng:** Kennung `SCAN-SIDECAR/1.0`, Paperless-ID im Pfad = ID in der Notiz,
  `sha256-original` vorhanden, Beträge als Dezimalzahlen. Nie raten.
- XML wird ohne externe Entitäten und ohne Netzzugriff gelesen; es gilt als fremde Eingabe.
- **Mandant:** steht im Pfad und ist durch das Token festgelegt (ein Token gilt für genau einen Mandanten).
- Jede Einlieferung steht im Protokoll des Mandanten (Paperless-ID, SHA-256 des Sidecars, Dienstkonto);
  Logs enthalten keine Belegangaben, nur Paperless-ID, Codes und Zähler.
- **Rückweg** (Korrekturdateien für nopaper, B11) später über dieselbe Verbindung: nopaper holt sie mit
  demselben Token ab.

## 4. Eintrag im Arbeitsvorrat und nopaper-Daten

Ob ein Beleg **offen** ist, sagt das Tag in Paperless (Abschnitt 2a). kumero führt je Paperless-ID einen
Eintrag („Aufgabe“) mit den nopaper-Daten und dem eigenen Bearbeitungsstand:

Eigenes Schema in der Mandanten-Datenbank (F44), Schreiben nur über Funktionen mit Protokoll (F48, F64):

| Feld | Inhalt |
|---|---|
| Quelle | `nopaper` (später auch echte E-Rechnungen, Modul `e-rechnung`) |
| Paperless-ID, `sha256_original` | für den Belegverweis (F10) |
| Belegart | aus Sidecar bzw. Paperless (B4) |
| Belegangaben | die erkannten Kopfdaten als JSON, unverändert |
| Prüfstatus, Prüfhinweise | von nopaper |
| Bearbeitungsstand | `in_arbeit` (Entwurf angelegt), `gebucht`, `verworfen` (mit Grund); ohne Eintrag oder ohne Stand gilt der Beleg als unbearbeitet |
| Buchungen | Verweise auf die Entwürfe/Buchungen, die aus der Aufgabe entstanden sind (n, F27) |
| eingegangen am, erledigt am/von | |

- Eine Aufgabe ist **erledigt**, wenn eine daraus entstandene Buchung festgeschrieben ist. Wird der
  Entwurf gelöscht, ist sie wieder offen; wird die Buchung storniert, entscheidet B9.
- **Verwerfen** (kein Buchungsfall, z. B. Lieferschein, doppelt gescannt) mit Pflichtgrund; die Aufgabe
  bleibt sichtbar unter „verworfen“.
- Kommt für dieselbe Paperless-ID ein **neuer Sidecar** (Nachbearbeitung in nopaper): offene Aufgabe wird
  ersetzt; ist sie schon in Arbeit oder gebucht, entsteht ein Hinweis an der Aufgabe, keine zweite.

## 5. Oberfläche

- Bereich **Buchen → „Arbeitsvorrat“** mit Zähler der offenen Belege im Menü (OBERFLAECHE.md: `zaehler`).
- **Liste** wie die Buchungsliste: Eingang, Belegart, Rechnungssteller, Rechnungsnummer, Rechnungsdatum,
  Brutto, Zahlbetrag, Fälligkeit, Prüfstatus. Ansichten: offen, in Arbeit, erledigt, verworfen. Enter
  öffnet die Buchungserfassung; Werkzeuge: Buchen, Verwerfen, Beleg in Paperless öffnen.
- **Buchungserfassung vorbefüllt** (Abschnitt 6). Neben dem Formular stehen die erkannten Belegangaben
  zum Vergleich und ein Verweis auf das Original in Paperless. Prüfhinweise von nopaper stehen als Text an
  den betroffenen Feldern (F32: keine Pop-ups), z. B. „Brutto steht nicht wörtlich im erkannten Text“.
- Nach dem Speichern oder Buchen springt die Maske zur **nächsten offenen Aufgabe** (Abarbeiten des
  Vorrats ohne Umweg über die Liste).

## 6. Vorbefüllen je nach Belegart und Werten

Aus den Belegangaben, ohne Rätselraten:

| Feld der Maske | Quelle |
|---|---|
| Belegdatum | Rechnungsdatum |
| Buchungsdatum | bei Barbelegen das Belegdatum; bei unbaren Zahlungen der Tag des tatsächlichen Geldflusses – bleibt leer, bis er bekannt ist (B8) |
| Belegnummer | Rechnungsnummer |
| Buchungstext | Rechnungssteller, dazu Rechnungsnummer |
| Betrag | Brutto (B7) |
| Beleg | Paperless-ID und SHA-256 des Originals aus dem Sidecar; MD5 siehe B6 |

Aus **Regeln des Mandanten**, die der Nutzer pflegt:

1. **Belegart → Geschäftsvorfall** (Vorlage aus dem Modul `buchen`): z. B. *kassenbon* → „Barausgabe“
   (Gegenkonto Kasse), *rechnung* → „Zahlungsausgang Bank“, *bewirtung* → Vorlage mit dem
   Bewirtungskonto, *gutschrift* (381) → Seiten getauscht.
2. **Rechnungssteller → Aufwandskonto**: Beim Buchen merkt sich kumero je Rechnungssteller (Name bzw.
   Steuer-ID) das zuletzt bestätigte Konto und schlägt es beim nächsten Beleg vor. Ohne Treffer bleibt das
   Konto leer.

Was die Maske **nicht** tut: Konten erfinden, Steuer aufteilen (das kommt mit dem Modul `steuer`), ohne
Sichtung buchen. Jeder Vorschlag ist ein Entwurf (F51 Nr. 3), jede Vorbelegung ist überschreibbar.

## 7. Schnitt

- **Eigenes Modul** (B1), abhängig von `buchen` (Vorlagen, Maske). Der CII-Leser ist so geschnitten, dass
  ihn das spätere Modul `e-rechnung` (XRechnung, ZUGFeRD) mitbenutzt.
- Das Modul erzeugt Entwürfe nur über die Anwendungsdienste bzw. Endpunkte des Kerns; das
  Dienstkonto der Einlieferung hat nur das Recht einzuliefern, nie ein Freigaberecht (F78 O3).
- Rechte: `…aufgabe.sehen`, `…aufgabe.bearbeiten` (in Arbeit nehmen, verwerfen), `…regel.pflegen`.
  Erfasser sehen und bearbeiten, Buchhalter zusätzlich Regeln.

## 8. Entscheidungen zur Freigabe

| # | Frage | Vorschlag |
|---|---|---|
| B1 | Modul | Neues Modul **`belegeingang`** (Arbeitsvorrat für alle Quellen, Abschnitt 1a, und Annahme der Sidecars), abhängig von `buchen`. Alternative nach F55: Teil von `paperless`. |
| B2 | Weg der Dateien | **Entschieden am 2026-11-17:** nopaper läuft auf einer anderen Maschine; die Sidecars kommen über eine REST-Schnittstelle mit Bearer-Token (Abschnitt 3). Die Schnittstelle ist noch festzulegen – Vorschlag in Abschnitt 3. |
| B2a | Wer liefert ein? | **Entschieden am 2026-11-17:** nopaper selbst. nopaper wird in seinem eigenen Projekt gepflegt; kumero macht die Vorgaben der Schnittstelle und beschreibt die Aufgabe (NOPAPER-AUFTRAG.md). Nur Protokolle, die über einen Reverse-Proxy laufen. In nopaper ändert sich damit E34 („keine API-Aufrufe“). |
| B3 | Annehmen | Endpunkt, Antworten und Idempotenz wie in Abschnitt 3; gelöscht wird beim Absender erst nach der Quittung. |
| B4 | Belegart | **nopaper ergänzt die Notiz im Sidecar um `belegart=`** (Teil des Auftrags an nopaper). Bis dahin: 380 → rechnung, 381 → gutschrift. Alternative: kumero liest das Feld aus Paperless (braucht das Modul `paperless`). |
| B5 | Aufgabe | Felder und Zustände wie in Abschnitt 4; Belegangaben bleiben unverändert gespeichert. |
| B6 | Belegverweis | F10 verlangt Paperless-ID, **MD5** und SHA-256 des Originals; der Sidecar liefert kein MD5. Vorschlag: **nopaper ergänzt `md5-original`** (es prüft das MD5 beim Upload ohnehin; Teil des Auftrags an nopaper). Bis das Modul `paperless` das Original selbst nachprüft (F51 Nr. 4), gilt der Verweis als „von nopaper übernommen“ und wird später über „Beleg nachgereicht“ bestätigt. |
| B7 | Betrag | Vorbelegt wird **Brutto**. Solange es das Modul `steuer` nicht gibt, wird brutto gebucht; Netto und Steuer stehen zum Vergleich daneben. |
| B8 | Buchungsdatum bei EÜR | **Entschieden am 2026-11-17:** Bei **Barbelegen** ist der Zahltag immer das Datum des Belegs. Bei **Kontenbuchungen** (unbar) ist es der Tag des tatsächlichen Geldflusses. Folge für die Vorbelegung: Kassenbon und bar bezahlte Belege → Buchungsdatum = Belegdatum; alles Unbare → Buchungsdatum bleibt leer, bis der Geldfluss bekannt ist (Kontoauszug, später Modul `bank`). Die Maske zeigt das als Hinweis am Feld. |
| B9 | Storno | Wird die Buchung zu einem Beleg storniert, setzt kumero das Tag in Paperless wieder; der Beleg steht damit erneut im Arbeitsvorrat (mit Hinweis). |
| B10 | Regeln | Zwei Stufen wie in Abschnitt 6: Belegart → Geschäftsvorfall (gepflegt), Rechnungssteller → Konto (gemerkt beim Buchen, einsehbar und löschbar). |
| B11 | Korrekturdateien für nopaper | Später als eigener Schritt: Schema, Ordner, Aufbewahrung und Platzhalter neu als F in kumero festlegen (die Verweise in nopaper E34/E39 passen nicht mehr zu den heutigen Nummern). Nicht Teil der ersten Stufe. |
| B12 | Reihenfolge | **Entschieden am 2026-11-17:** Solange das Buchen nicht vollständig beschrieben ist, gibt es keine 0.8.0. Maskenergänzungen, Profil und Belegeingang sind Versionen 0.7.x; die Zuordnung zur Anlage EÜR (F83 M7) folgt danach. Die Reihenfolge innerhalb von 0.7.x ist noch festzulegen. |
| B13 | Quelle des Arbeitsvorrats | **Entschieden am 2026-11-17:** die Belege in Paperless mit dem Tag für offene Belege; beim Aufruf Abgleich mit den nopaper-Daten (Abschnitt 2a). Setzt das Modul `paperless` voraus (AGENTS.md P13). |
| B13a | Wo steht das Tag? | **Entschieden am 2026-11-17:** an der Paperless-Verbindung des Nutzers, die er in seinen Einstellungen anlegt – je Nutzer und Mandant (PAPERLESS.md Abschnitt 2). Folge: Jeder Nutzer hat seinen eigenen Arbeitsvorrat aus Paperless; zwei Nutzer mit demselben Tag sehen dieselben Belege, soweit ihre Rechte in Paperless das zulassen. |
| B14 | Tag nach dem Buchen | **Entschieden am 2026-11-17:** Das Tag wird in Paperless heute schon gesetzt; daran ändert sich nichts. kumero entfernt es, sobald eine Buchung zum Beleg festgeschrieben ist, und setzt es bei Storno wieder (PAPERLESS.md L10). „Kein Buchungsfall“ entfernt es ebenfalls, mit Grund. |
