# SIEGEL.md – Siegel auf Anforderung

Stand: 2026-11-19 · kumero 0.7.26 · Modul `siegel` 0.1.0 · Entscheidungen F119 und F120 in AGENTS.md

Diese Datei beschreibt, wie kumero siegelt. Die erste Stufe ist gebaut (Abschnitt 1–8); was offen ist, steht in
Abschnitt 9 und 10. Sie ersetzt die bisherige Planung eines Signierdienstes mit Systemschlüssel auf dem Server
(F14, F22, F23, F28, F38 Ebene 1).

## 1. Was das Siegel leistet

- Jede Buchung und jedes Protokollereignis trägt einen SHA-256-Hash über seinen Inhalt und den Hash des Vorgängers
  (F13). Die Kette allein beweist die Unveränderlichkeit nur gegenüber jemandem, der sie nicht neu berechnen kann. Wer
  die Datenbank beherrscht, kann nach einer Änderung alle folgenden Hashes neu rechnen.
- Das **Siegel** ist der Kettenkopf zu einem Zeitpunkt, signiert mit einem Schlüssel, der **nicht** auf dem Server
  liegt, und außer Haus aufbewahrt. Wer später die Kette ändert, kann die Signatur nicht nachbilden.
- Das Siegel belegt die **technische Integrität**: Seit dem Siegel ist nichts verändert. Es belegt **nicht**, dass
  richtig gebucht wurde. Die Signatur einer Person fügt hinzu, **wer** den Stand bestätigt hat.
- Ein Siegel verankert alle Buchungen davor. Der Abstand zwischen zwei Siegeln bestimmt nur das Zeitfenster, in dem
  eine Änderung unbemerkt bliebe, nicht den Wert des Siegels. Schon ein Siegel zum Jahresabschluss genügt (F119).

## 2. Grundsätze (F120)

1. **Gesiegelt wird nach Bedarf, auf Anforderung einer Person** – nicht in festen Abständen, nicht automatisch.
2. **Der private Schlüssel erreicht kumero nie.** Nicht dauerhaft, nicht flüchtig, auch kein Passwort dazu. Signiert
   wird außerhalb; hochgeladen wird nur die Signatur.
3. **Der Schlüssel gehört dem Mandanten.** In der Datenbank des Mandanten liegt sein öffentlicher Teil. Die
   Verwaltungsdatenbank kennt keinen Siegelschlüssel.
4. **Es gibt keinen Systemschlüssel und keinen Signierdienst auf dem Server.** Jedes Siegel stammt von einer Person.
5. **Siegel und Schlüssel stehen m:n:** Ein Siegel kann mehrere Signaturen tragen (z. B. Verantwortlicher und
   Steuerberater); ein Schlüssel signiert viele Siegel.
6. **Jeder Dritte kann die Prüfung wiederholen** – mit drei Dateien und `gpg`, ohne kumero (Abschnitt 8).
7. **Erste Art der Signatur: OpenPGP.** Die QES (X.509/CAdES) kommt als zweite Art auf demselben Weg dazu (Abschnitt 9).

## 3. Der Siegelinhalt

Beim Anfordern hält kumero **beide** Kettenköpfe fest und bildet daraus ein JSON in kanonischer Form (RFC 8785, F29).
Genau diese Bytes – ohne Zeilenende am Schluss – werden signiert:

```json
{"art":"kumero-siegel","buchungen":{"bis_nr":128,"hash":"…64 Hexzeichen…"},"fassung":1,"mandant":"praxis","nr":3,
 "protokoll":{"bis_nr":911,"hash":"…"},"vorgaenger":"…SHA-256 des Inhalts von Siegel 2, sonst null…","zeitpunkt":"2026-11-19T17:10:00.123456Z"}
```

| Feld | Bedeutung |
|---|---|
| `art`, `fassung` | fest `kumero-siegel`, `1`; eine neue Fassung ändert die Zahl |
| `mandant` | Kennung des Mandanten |
| `nr` | laufende Nummer des Siegels im Mandanten |
| `zeitpunkt` | Uhr des Servers beim Anfordern (UTC) |
| `buchungen` | Kopf der Buchungskette: letzte Nummer und ihr Hash (`bis_nr` 0 = noch keine festgeschriebene Buchung) |
| `protokoll` | Kopf der Protokollkette unmittelbar **vor** dem Ereignis `siegel.angefordert` |
| `vorgaenger` | SHA-256 des vorigen Siegelinhalts; verkettet die Siegel untereinander |

Das Ereignis `siegel.angefordert` trägt die SHA-256-Prüfsumme des Inhalts und steht damit selbst in der Protokollkette.

## 4. Ablauf

**Seit 0.7.28 (F124): Festgeschrieben wird ein Siegel erst nach Abschluss und Bestätigung.** Bis dahin ist alles
vorläufig – es steht nichts in den Ketten, und es lässt sich spurlos verwerfen.

1. **Siegel anfordern** (Recht `siegel.siegel.anfordern`). Es entsteht eine **vorläufige Anforderung** mit dem
   Siegelinhalt: kein Siegel, kein Protokolleintrag. Höchstens eine ist offen; sie gilt 24 Stunden.
2. **Siegelinhalt herunterladen:** `kumero-siegel-<mandant>-<nr>.json`.
3. **Außerhalb signieren**, unverändert: `gpg --armor --detach-sign kumero-siegel-praxis-0003.json`
   → `kumero-siegel-praxis-0003.json.asc`. Der Schlüssel darf dabei auf einer Karte oder einem Token liegen.
4. **Signatur hochladen.** kumero prüft sie gegen genau den gespeicherten Inhalt und die angemeldeten öffentlichen
   Schlüssel. Die geprüfte Signatur steht an der Anforderung; sie lässt sich ersetzen.
5. **Siegel festschreiben.** Die Bestätigung zeigt, was festgeschrieben wird (Kettenstände, Prüfsumme, Schlüssel,
   Zeit der Signatur). Erst jetzt entstehen **Siegel, Signatur und Protokolleintrag – in einer Transaktion**.
6. Weitere Personen können ein festgeschriebenes Siegel mit ihrem Schlüssel ebenfalls signieren.

Statt Schritt 5 jederzeit möglich: **Anforderung verwerfen.** Verworfene und verfallene Anforderungen verbrauchen
keine Nummer.

Einmalig davor: **öffentlichen Schlüssel anmelden** (Recht `siegel.schluessel.pflegen`):
`gpg --armor --export ADRESSE > schluessel.asc`, Datei hochladen.

Siegel, die mit 0.7.26/0.7.27 angefordert und nie signiert wurden, bleiben stehen (der Kern löscht nichts), zählen in
Nummer und Vorgänger mit und nehmen keine Signatur mehr an.

## 5. Prüfung beim Hochladen

Die Datenbank kann keine OpenPGP-Signatur prüfen; das tut die Anwendung (`module/siegel/src/Pgp.php`):

- Programme: `gpg --show-keys` (Schlüssel auslesen) und `gpgv` (Signatur prüfen), beide im Installer enthalten.
  Gearbeitet wird in einem Wegwerfverzeichnis, ohne Schlüsselbund des Servers, ohne Agent, ohne Netz.
- **Schlüssel:** genau ein öffentlicher Schlüssel in ASCII-Hülle, höchstens 64 KB, nicht abgelaufen, nicht widerrufen,
  zum Signieren fähig. Fingerabdruck, Kennung (User-ID) und Verfahren liest kumero aus dem Schlüssel. Ein **privater
  Schlüssel wird abgewiesen** und nicht gespeichert.
- **Signatur:** abgesetzte Signatur in ASCII-Hülle, höchstens 16 KB, über die Datei (Klasse 00 oder 01), Hash SHA-256
  oder stärker. Maßgeblich ist der Fingerabdruck des Hauptschlüssels; er muss im Mandanten angemeldet und nicht
  zurückgezogen sein.
- Fehlercodes: `siegel.schluessel_unbekannt` (409), `siegel.signatur_ungueltig`, `siegel.signatur_schwach`,
  `siegel.signatur_unlesbar`, `siegel.schluessel_ungueltig`, `siegel.privater_schluessel` (422),
  `siegel.pruefung_fehlt` (503, `gpg` oder `gpgv` fehlt auf dem Server).
- **Zeit:** `signiert_am` ist die Zeitangabe aus der Signatur, also die Uhr des Unterzeichners. Daneben steht
  `eingereicht_am` (Uhr des Servers). Ein qualifizierter Zeitstempel ist offen (Abschnitt 9).

## 6. Daten und Schnittstellen

**Kern, Migrationen 0013 und 0014** (Datenbank des Mandanten):

| Tabelle | Inhalt |
|---|---|
| `kern.siegel` | Nummer, Zeitpunkt, wer angefordert hat, beide Kettenköpfe, `inhalt` (die signierten Bytes), `inhalt_sha256`, Status; Felder für den Zeitstempel (noch leer) |
| `kern.siegelschluessel` | Fingerabdruck, Art (`pgp`), Kennung, Verfahren, öffentlicher Schlüssel, angemeldet von/am, zurückgezogen von/am/Grund |
| `kern.siegel_signatur` | Siegel × Schlüssel: Signatur, `signiert_am`, eingereicht von/am |
| `kern.siegel_anforderung` | **Vorläufig (0014):** höchstens eine Zeile – Nummer, beide Kettenköpfe, `inhalt`, `gueltig_bis`, dazu die geprüfte, noch nicht festgeschriebene Signatur. Nicht Teil der Ketten, ohne Protokoll; wird beim Festschreiben, Verwerfen oder Verfallen entfernt |

Geschrieben wird nur über Funktionen (F64): `kern.siegel_anfordern`, `kern.siegel_anforderung_signatur`,
`kern.siegel_anforderung_verwerfen`, `kern.siegel_festschreiben`, `kern.siegel_signieren` (weitere Signatur),
`kern.siegelschluessel_anmelden`, `kern.siegelschluessel_zurueckziehen`. Siegel, Signaturen und Schlüssel werden nie
gelöscht und nicht verändert; am Schlüssel ändert sich nur das Zurückziehen, einmal. Ereignisse im Protokoll:
`siegel.festgeschrieben` (nennt Siegel, Schlüssel und die Prüfsumme des Inhalts), `siegel.signiert` (weitere
Signatur), `siegel.schluessel_angemeldet`, `siegel.schluessel_zurueckgezogen`. Anfordern, Hochladen an der Anforderung
und Verwerfen schreiben **kein** Protokoll. In der
Kette stehen Fingerabdruck und Prüfsumme, **nicht** die Kennung des Schlüssels (Name, Adresse) und nicht der Schlüssel.
Im Lesemodus wird nicht gesiegelt.

Module erreichen das alles nur über die Modul-API `Kumero\Api\V1\Siegel` (F44).

**Modul `siegel`** (REST unter `/m/{mandant}/api/v1/siegel`, Einzelheiten in `openapi/v1.yaml`):

| Methode | Pfad | Recht |
|---|---|---|
| GET | `/siegel` | `siegel.siegel.sehen` |
| POST | `/siegel` (vorläufige Anforderung) | `siegel.siegel.anfordern` |
| POST | `/siegel/{id}/festschreiben` | `siegel.siegel.anfordern` |
| DELETE | `/siegel/{id}` (Anforderung verwerfen) | `siegel.siegel.anfordern` |
| GET | `/siegel/{id}/inhalt` | `siegel.siegel.sehen` |
| POST | `/siegel/{id}/signaturen` | `siegel.siegel.anfordern` |
| GET | `/siegel/{id}/signaturen/{fpr}` | `siegel.siegel.sehen` |
| POST | `/siegel/schluessel` | `siegel.schluessel.pflegen` |
| GET | `/siegel/schluessel/{fpr}` | `siegel.siegel.sehen` |
| POST | `/siegel/schluessel/{fpr}/zurueckziehen` | `siegel.schluessel.pflegen` |

**Rollen:** Verantwortlicher bekommt den Baustein „Siegeln“ (alle drei Rechte); Buchhalter, Steuerberater und Prüfer
„Siegel ansehen“. Aktiviert wird mit `bin/kumero modul:aktivieren siegel <mandant>`.

## 7. Seite „Siegel“ (Bereich Buchführung, seit 0.7.27 – F122)

- Werkzeugleiste: **Siegel anfordern**; für das in der Liste gewählte Siegel **Siegelinhalt herunterladen** und
  **Weitere Signatur …**. Gesperrte Knöpfe bleiben stehen und nennen den Grund (F106).
- **Offene Anforderung** (seit 0.7.28): Karte an festem Platz mit immer denselben Feldern (ohne Anforderung Striche)
  und eigenen Knöpfen – **Siegelinhalt herunterladen**, **Signatur hochladen …**, **Siegel festschreiben …**,
  **Anforderung verwerfen**. Zustände: keine · wartet auf Signatur · Signatur geprüft, wartet auf Bestätigung.
- **Stand der Ketten:** Buchungen und Protokoll bis Nummer; wie viele Buchungen seit dem letzten signierten Siegel
  festgeschrieben wurden.
- **Liste der festgeschriebenen Siegel**, neuestes zuerst: Nummer, angefordert am/von, Kettenköpfe, Zustand, Signaturen (jede als Datei
  herunterladbar), Prüfsumme des Inhalts.
- **So wird gesiegelt:** die vier Schritte mit dem Befehl samt Dateiname.
- **Öffentliche Schlüssel:** anmelden (Datei wählen oder Text einfügen), zurückziehen mit Grund; der Schlüssel ist als
  Datei herunterladbar.
- Die Seite nimmt nie einen privaten Schlüssel und kein Passwort entgegen.

## 8. Prüfung durch Dritte

Für ein Siegel genügen drei Dateien, alle von der Seite herunterladbar: Siegelinhalt (`.json`), Signatur (`.asc`),
öffentlicher Schlüssel (`.asc`).

```bash
gpg --import kumero-siegelschluessel-….asc
gpg --verify kumero-siegel-praxis-0003.json.….asc kumero-siegel-praxis-0003.json
```

Danach wird der Kettenkopf aus dem Siegelinhalt mit der Kette verglichen: Die Buchung `bis_nr` muss den genannten Hash
tragen, und die Kette muss sich von vorn bis dorthin nachrechnen lassen (`kern.kette_pruefen`). Ob der Schlüssel der
genannten Person gehört, belegt PGP nicht von selbst; das leistet der Fingerabdruck, den die Person auf anderem Weg
bestätigt, oder später die QES.

**Außer Haus:** Beweiswert gegen jemanden, der den Server beherrscht, hat das Siegel nur, wenn Inhalt und Signatur
auch außerhalb liegen. Die signierende Person hat beide Dateien ohnehin auf ihrem Rechner. Ein eigener Export (F35)
ist nicht gebaut.

## 9. Offen, nicht gebaut

| # | Punkt |
|---|---|
| O1 | **Signieren im Browser** mit einer OpenPGP-Bibliothek (naheliegend OpenPGP.js), damit der Nutzer kein `gpg` braucht. Schlüssel und Passwort blieben im Browser. Vor dem Bauen zu prüfen: Lizenz, Größe, Verträglichkeit mit der strengen CSP (WebAssembly für Argon2). Einschränkung: Wer den Server beherrscht, kann das ausgelieferte JavaScript ändern – deshalb bleibt das Hochladen der sichere Weg. |
| O2 | **QES als zweite Art** (`x509`): Signatur als CAdES über dieselbe Datei; bei X.509 genügt die Signatur selbst, sie trägt das Zertifikat. Prüfung gegen die Vertrauenslisten (z. B. EU DSS). Die Tabellen sehen die Art vor. |
| O3 | **Zeitstempel** (RFC 3161): Felder `tsr`, `tsa`, `gestempelt_am` sind vorhanden, werden aber nicht gefüllt. Ein Zeitstempeldienst ist eine externe Stelle; er erhielte nur den Hash. |
| O4 | **Siegel-Export außer Haus** (F35) und Abgleich nach einer Wiederherstellung (F60, F69): bisher auf laufend gesiegelte Glieder geplant; neu zu beschreiben für Siegel auf Anforderung. |
| O5 | **Produktivbetrieb** (F59) verlangt bisher Signierdienst mit Systemschlüssel und Zeitstempeldienst. Neu festzulegen, z. B.: mindestens ein angemeldeter Schlüssel. Die Funktion `kern.produktiv_einschalten` ist unverändert. |
| O6 | **Siegel bei der Jahressperre** (F33, F36: „sofort gesiegelt und gestempelt“): Vorschlag – die Sperre fordert ein Siegel an und die Seite weist darauf hin; erzwungen wird die Signatur nicht. |
| O7 | **Automatisches Siegeln** für Betreiber, die das wollen: dann mit einem Dienst wie OpenBao oder SignServer als weiterer Unterzeichner. Nicht geplant. |

## 10. Festlegungen aus dem Bau – zu bestätigen

| # | Festlegung |
|---|---|
| S1 | Ein Siegel hält **beide** Kettenköpfe fest (bisher: ein Siegel je Kette). |
| S2 | Die Siegel sind untereinander verkettet (`vorgaenger`). |
| S3 | Rechte liegen beim Modul: `siegel.siegel.sehen`, `siegel.siegel.anfordern`, `siegel.schluessel.pflegen`. Siegeln und Schlüssel pflegen darf der **Verantwortliche**; Buchhalter, Steuerberater und Prüfer lesen. Das Kernrecht `kern.siegel.sehen` bleibt ungenutzt bestehen. |
| S4 | Wer ein Siegel anfordern darf, darf auch Signaturen einreichen. Ein Steuerberater, der mitsiegeln soll, braucht also den Baustein „Siegeln“. |
| S5 | Ein Schlüssel wird einmal angemeldet und kann nur zurückgezogen, nicht gelöscht oder ersetzt werden. Zurückgezogen nimmt er keine neuen Signaturen an; alte bleiben gültig. |
| S6 | Die Kennung des Schlüssels (Name, Adresse) steht in der Tabelle, nicht im Protokoll. |
| S7 | Angenommen werden Signaturen mit SHA-256 oder stärker; SHA-1 und älter nicht. |
| S8 | ~~Ein neues Siegel lässt sich jederzeit anfordern, auch wenn das vorige noch keine Signatur trägt.~~ Ersetzt durch S11 (0.7.28). |
| S9 | Die Liste zeigt die letzten 200 Siegel. |
| S10 | Die alte Tabelle `kern.siegel` (ein Siegel je Kette, Signatur des Systemschlüssels) wird umgebaut; die Migration bricht ab, falls sie schon Einträge hätte. Gebaut war nichts, das sie füllt. |
| S11 | **Bestätigt am 2026-11-20.** **(0.7.28, F124)** Höchstens **eine** offene Anforderung; sie gilt **24 Stunden**. Eine verfallene wird beim nächsten Anfordern ersetzt. |
| S12 | **Bestätigt am 2026-11-20.** Die Bestätigung ist ein **Knopf mit Anzeige dessen, was festgeschrieben wird** – kein Passkey. Die Signatur weist die Person schon aus. |
| S13 | **Bestätigt am 2026-11-20.** Die vorläufige Anforderung liegt in `kern.siegel_anforderung`, **außerhalb der Ketten und ohne Protokoll** – sonst wäre sie nicht spurlos verwerfbar. Das ist eine Ausnahme von „jede Funktion schreibt ihren Protokolleintrag“ (F64), wie bei den Challenges der Freigabe. |
| S14 | **Bestätigt am 2026-11-20.** Eine **weitere Signatur** an einem schon festgeschriebenen Siegel wird nach der Prüfung sofort eingetragen, ohne eigene Bestätigung. |
| S15 | **Bestätigt am 2026-11-20.** Festschreiben darf jeder mit `siegel.siegel.anfordern`, nicht nur, wer angefordert oder hochgeladen hat. Im Siegel steht, wer angefordert und wer die Signatur eingereicht hat; im Protokoll, wer festgeschrieben hat. |
