# NOPAPER-AUFTRAG.md – Auftrag an das Projekt nopaper: Sidecars an kumero einliefern (Entwurf 1, zur Freigabe)

Stand: 2026-11-17. Auftraggeber: Projekt kumero (früher „Aequa“). Grundlage: BELEGEINGANG.md, Entscheidungen
B2, B2a, B4, B6. **Status: Entwurf.** Er wird erst an nopaper übergeben, wenn der Projektverantwortliche die
Schnittstelle freigegeben hat; bis dahin baut auf keiner Seite jemand.

Dieses Dokument ist so geschrieben, dass es **für sich allein** im Projekt nopaper verwendet werden kann.
Es beschreibt, was nopaper tun soll und woran die Umsetzung gemessen wird – nicht, wie nopaper es intern
löst. nopaper wird im eigenen Projekt nach den eigenen Regeln (`AGENTS.md` dort) gepflegt.

---

## 1. Worum es geht

Die Buchhaltung kumero führt einen **Arbeitsvorrat** der noch zu buchenden Belege. Die Belege selbst
liegen in paperless und tragen dort ein Tag für „noch zu buchen“. Ruft ein Mensch in kumero einen solchen
Beleg auf, wird die Buchungsmaske mit den Rechnungsdaten vorbefüllt, die nopaper erkannt hat. Dafür braucht
kumero den **Sidecar** jedes Belegs.

Bisher schreibt nopaper die Sidecars in einen Abholordner (E10, E15). nopaper und kumero laufen aber auf
**getrennten Maschinen**. Deshalb soll **nopaper die Sidecars selbst an kumero senden**.

Das ändert in nopaper die Entscheidung **E34** („keine direkte Verbindung, keine API-Aufrufe“): Es gibt
künftig genau **einen** ausgehenden Aufruf zu kumero. Unverändert bleibt: nopaper ist buchhaltungsneutral
(E38), kumero ruft nopaper nie auf, und nopaper funktioniert ohne kumero weiter wie bisher.

## 2. Was nopaper liefern soll

### 2.1 Einliefern

Nach jedem geschriebenen Sidecar – auch bei Prüfstatus „prüfen“ (E36) – sendet nopaper die Datei an kumero.

```
PUT {kumero.url}/m/{kumero.mandant}/api/v1/belegeingang/sidecars/{paperless_id}

Authorization:   Bearer <Token aus der Token-Datei>
Content-Type:    application/xml
Idempotency-Key: <SHA-256 des gesendeten Inhalts, 64 Hex-Zeichen>
Accept:          application/json

<der Sidecar, Byte für Byte wie geschrieben>
```

- `{paperless_id}` ist die Dokument-ID als Zahl ohne führende Nullen (`42`, nicht `0000042`); sie muss der
  `paperless-document-id` in der Notiz des Sidecars entsprechen.
- Der Körper ist höchstens 1 MB groß.
- Es wird nur über **HTTPS** mit geprüftem Zertifikat gesendet; eine `http://`-Adresse lehnt nopaper beim
  Start ab (wie bei paperless).
- Die Verbindung muss über einen **Reverse-Proxy** (z. B. Caddy) laufen können. Deshalb: eine gewöhnliche
  Anfrage mit einer Antwort, kein Client-Zertifikat, keine dauerhaft offene Verbindung, kein Streaming,
  Zeitlimit 30 Sekunden. kumero wertet die Absenderadresse nicht aus.

### 2.2 Antworten und was nopaper daraufhin tut

| Antwort | Bedeutung | nopaper |
|---|---|---|
| **200** oder **201** mit `{"ergebnis": "angelegt" \| "unveraendert" \| "ersetzt" \| "vermerkt", "paperless_id": 42, "sha256": "…"}` | angenommen | Prüfen, dass `sha256` dem gesendeten Inhalt entspricht; dann gilt der Sidecar als zugestellt und wird aus der Warteschlange entfernt. Weicht `sha256` ab: wie 5xx behandeln und melden. |
| **401**, **403** | Token ungültig, abgelaufen oder ohne Recht | Nicht wiederholen. Einlieferung anhalten, deutlich melden (`KUMERO_TOKEN`), Sidecars behalten. Die übrige Verarbeitung läuft weiter. |
| **404** | Mandant unbekannt oder Modul in kumero nicht aktiv | wie 401/403 (`KUMERO_ZIEL`). |
| **413** | zu groß | wie 422. |
| **422** | Sidecar abgelehnt; Körper `application/problem+json` mit `code` | Nicht wiederholen. Sidecar in einen Ordner `abgelehnt/` legen, daneben eine Begleitdatei mit `code` und Zeitpunkt (nur Codes, keine Inhalte). |
| **429** | zu viele Anfragen | Wartezeit aus `Retry-After` einhalten, dann erneut. |
| **5xx**, Zeitüberschreitung, keine Verbindung | vorübergehend | Sidecar behalten, später erneut mit wachsendem Abstand (z. B. 1 min, 5 min, 15 min, dann stündlich). Nichts geht verloren, die Reihenfolge ist egal. |

Derselbe Sidecar darf beliebig oft gesendet werden; kumero erkennt ihn am `Idempotency-Key` und antwortet
mit `unveraendert`. Ein **neuer** Sidecar zum selben Dokument (Nachbearbeitung) hat einen anderen SHA-256
und ersetzt den alten (`ersetzt`) oder wird nur vermerkt, wenn der Beleg schon gebucht ist (`vermerkt`).

Codes bei 422 (Stand dieses Entwurfs): `sidecar.kein_xml`, `sidecar.kennung` (Notiz beginnt nicht mit
`SCAN-SIDECAR/1.0` oder unbekannte Version), `sidecar.id_abweichend` (ID im Pfad ≠ ID in der Notiz),
`sidecar.hash_fehlt` (`sha256-original` fehlt oder ist kein SHA-256), `sidecar.betrag` (Betrag keine
Dezimalzahl), `sidecar.pflichtangabe` (weder Brutto noch Zahlbetrag).

### 2.3 Warteschlange statt Abholordner

- Der bisherige Ausgangsordner wird zur **Warteschlange** der noch nicht zugestellten Sidecars. Eine Datei
  bleibt dort, bis kumero sie angenommen hat; danach wird sie gelöscht (wie bisher „die Abholung löscht“,
  E15).
- Ist **kein** kumero eingerichtet (Abschnitt 2.6 fehlt in der Konfiguration), verhält sich nopaper genau wie
  heute: Sidecars bleiben im Abholordner liegen.
- Die Einlieferung darf die Verarbeitung der Belege **nie** aufhalten oder zum Abbruch bringen. Ist kumero
  nicht erreichbar, läuft alles andere weiter.

### 2.4 Zwei Ergänzungen im Sidecar

In der Notiz (`SCAN-SIDECAR/…`) kommen zwei Zeilen dazu:

| Schlüssel | Inhalt | Wozu |
|---|---|---|
| `belegart` | `rechnung`, `kassenbon`, `bewirtung`, `versicherung`, `behoerde`, `gutschrift` – derselbe Wert wie im paperless-Feld „Erkannt: Belegart“ | kumero wählt danach die Vorbelegung der Buchungsmaske. Heute steht im Sidecar nur 380/381. |
| `md5-original` | MD5 der Originaldatei, 32 Hex-Zeichen – der Wert, den nopaper beim Upload ohnehin gegen paperless prüft | kumero führt am Beleg Paperless-ID, MD5 und SHA-256 des Originals. |

Die Kennung der ersten Zeile wird dafür auf **`SCAN-SIDECAR/1.1`** angehoben. kumero nimmt 1.0 und 1.1 an;
bei 1.0 fehlen ihm die beiden Angaben. Unbekannte Schlüssel überliest kumero, weitere Ergänzungen sind also
ohne Absprache möglich, solange die vorhandenen Schlüssel ihre Bedeutung behalten.

### 2.5 Tag für „noch zu buchen“

Entfällt als Auftrag (Stand 2026-11-17): Das Tag wird in paperless bereits gesetzt. kumero entfernt es nach
dem Buchen; für nopaper ändert sich daran nichts.

### 2.6 Konfiguration und Token

Neuer, optionaler Abschnitt in `/etc/nopaper/config.json`, je Mandant:

```json
"kumero": {
  "url": "https://kumero.example.org",
  "mandant": "praxis",
  "token_datei": "/etc/nopaper/kumero.token"
}
```

- Das Token (`kmr_…`) steht **nur** in der Token-Datei (root:nopaper, 0640), nie in der Konfiguration, nie
  in Logs, nie in Fehlermeldungen. kumero stellt es für ein Dienstkonto aus, das ausschließlich einliefern
  darf; es hat ein Ablaufdatum und wird vom Betreiber erneuert.
- **Netzfreigabe:** `nopaper-aktivieren` gibt heute nur den paperless-Host frei. Ist `kumero` eingerichtet,
  kommt genau dieser eine Host (Port 443) dazu, nichts sonst.

### 2.7 Prüfen und Melden

- `nopaper check` prüft zusätzlich: Adresse ist HTTPS, Token-Datei lesbar, und
  `GET {url}/m/{mandant}/api/v1/belegeingang/status` antwortet mit 200 und `{"bereit": true}`.
  Fehlercodes: `KUMERO_URL`, `KUMERO_TOKEN`, `KUMERO_ZIEL`, `KUMERO_NICHT_ERREICHBAR`. Anders als bei
  fehlenden Tags startet der Dienst auch dann, wenn kumero nicht erreichbar ist.
- Eine Übersicht (Befehl oder Teil von `nopaper status`): wie viele Sidecars warten, seit wann der älteste,
  wie viele abgelehnt wurden.
- Logs enthalten **keine Belegangaben**: nur paperless-ID, HTTP-Status, Code und Zähler.

## 3. Was nopaper nicht tun soll

- Keine weiteren Aufrufe an kumero: nichts lesen, nichts abfragen außer `…/status`.
- Keine buchhalterischen Entscheidungen (E38 bleibt): kein Konto, keine Periode, kein Vorsteuerabzug.
- Keine Änderung am Format des Sidecars außer den zwei Zeilen aus 2.4.
- Der Rückweg (Korrekturdateien, E37/E39) ist **nicht** Teil dieses Auftrags; er wird in kumero neu
  beschrieben und folgt später. Die Verweise in E34/E39 auf „Aequa F55, F66–F69“ stimmen nicht mehr: Das
  Projekt heißt kumero, und die Nummern bedeuten dort inzwischen etwas anderes. Bitte dort nur als
  „Buchhaltung kumero, BELEGEINGANG.md“ führen.

## 4. Woran die Umsetzung gemessen wird

Mit einem nachgebauten kumero (kleiner Test-Server, nur synthetische Belege) muss gelten:

1. Ein geschriebener Sidecar kommt als `PUT` mit den vier Kopfzeilen aus 2.1 an; der Körper ist Byte für
   Byte die Datei, der `Idempotency-Key` ihr SHA-256.
2. Nach 201 ist die Datei aus der Warteschlange verschwunden; nach 200 `unveraendert` ebenso.
3. Nach 503 und nach einer Zeitüberschreitung liegt die Datei noch da und wird später erneut gesendet –
   mit demselben `Idempotency-Key`.
4. Nach 422 liegt sie in `abgelehnt/` mit Begleitdatei und wird nicht erneut gesendet.
5. Nach 401 wird nichts mehr gesendet, bis das Token ersetzt ist; die Belegverarbeitung läuft weiter.
6. `429` mit `Retry-After: 30` führt zu frühestens 30 Sekunden Pause.
7. Ohne Abschnitt `kumero` in der Konfiguration verhält sich nopaper wie die heutige Version.
8. Der Sidecar trägt `SCAN-SIDECAR/1.1`, `belegart` und `md5-original`; das MD5 stimmt mit der
   Originaldatei überein.
9. In keinem Log und keiner Fehlermeldung stehen Token oder Belegangaben.
10. `nopaper check` meldet die vier Fehlerfälle aus 2.7 jeweils mit dem richtigen Code.
11. Eine `http://`-Adresse und ein ungültiges Zertifikat werden abgelehnt.

## 5. Was kumero dafür zusagt

- Die Schnittstelle aus Abschnitt 2 bleibt innerhalb von `/api/v1` stabil; Ergänzungen brechen nichts.
- kumero antwortet auf jede Einlieferung innerhalb des Zeitlimits mit einem der genannten Statuscodes.
- kumero ruft nopaper nie auf und verlangt dort keinen offenen Port.
- kumero liefert für die Tests eine Beschreibung der Schnittstelle (OpenAPI) und Beispielantworten.

## 6. Offene Punkte vor der Übergabe

| # | Frage | Vorschlag |
|---|---|---|
| A1 | Pfad und Methode | `PUT …/belegeingang/sidecars/{paperless_id}` wie in 2.1. |
| A2 | Version des Sidecars | Anheben auf `SCAN-SIDECAR/1.1` für `belegart` und `md5-original`. |
| A3 | Tag „noch zu buchen“ durch nopaper | Erledigt: wird bereits gesetzt, kein Auftrag (2.5). |
| A4 | Wiederholen | Abstände wie in 2.2; ohne Obergrenze, mit Meldung, wenn der älteste Sidecar länger als einen Tag wartet. |
| A5 | Mehrere Mandanten | Je nopaper-Mandant ein eigener Abschnitt `kumero` mit eigenem Token; ein Token gilt in kumero für genau einen Mandanten. |
