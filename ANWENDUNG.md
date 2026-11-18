# ANWENDUNG.md – PHP-Gerüst und Modul-API (Fassung 1)

Stand: 2026-11-14. Bezug: F18–F21, F37–F48, F53, F56, F64, F66–F73, F75–F76.
**Status: Fassung 1 freigegeben am 2026-11-14** (F77) mit den Entscheidungen E1–E8 in Abschnitt 11. Abschnitt 10 und 6 sind in Kern 0.4.0 umgesetzt.

Dieser Entwurf legt fest, wie die PHP-Anwendung auf dem fertigen Datenbankkern (0.3.0) aufsetzt:
Aufbau, Weg einer Anfrage, Anmeldung, Modul-API, Konfiguration, Auslieferung. Er enthält noch
keine Oberfläche und keine Fachmodule.

---

## 1. Grundsätze

1. **Die Datenbank bleibt die letzte Instanz** (F72). PHP prüft vorher, was es prüfen muss
   (Anmeldung, Rechte, WebAuthn-Signatur, Modulprüfungen), aber jede Regel, die PostgreSQL
   erzwingt, wird in PHP **nicht nachgebaut**, sondern nur übersetzt (Fehlercodes → Problem Details).
2. **Ein Dienstaufruf = eine Transaktion** (F46). Ruft ein Modul innerhalb eines laufenden Aufrufs
   weitere Dienste, laufen diese in derselben Transaktion.
3. **Module sehen nur die Modul-API** (Namensraum `Kumero\Api\V1`), nie Symfony, DBAL oder
   Kern-Interna (F53). Was nicht in `Api\V1` steht, ist für Module nicht vorhanden.
4. **Keine Geheimnisse in Konfigurationsdateien.** Die Datenbank wird über Unix-Socket und Peer-
   Authentisierung erreicht (Systembenutzer `kumero` → Rolle `kumero_app`); der Signierdienst über
   seinen Socket. Die Konfiguration darf also lesbar sein, ohne dass etwas verloren ist.
5. **Was ausgeführt wird, ist signiert** (F73): Anwendungscode einschließlich aller Abhängigkeiten
   kommt nur über ein freigegebenes Release auf den Server.

## 2. Verzeichnisaufbau

Im Repository:

```
composer.json, composer.lock   Abhängigkeiten, exakt festgeschrieben
public/index.php               einziger Einstieg für nginx (Front-Controller)
bin/kumero                     Kommandozeile (Symfony Console)
src/Plattform/                 Kernel, Konfiguration, Datenbank, Transaktion, Anmeldung, Sitzung,
                               Fehlerabbildung, Übersetzung, Protokollierung      (intern)
src/Kern/                      Anwendungsdienste des Kerns, Implementierung       (intern)
src/Api/V1/                    öffentliche Modul-API: Schnittstellen, Datenobjekte,
                               Ausnahmen, Erweiterungspunkte                       (stabil)
src/Http/                      REST-Adapter: Routen, Controller, Problem Details  (intern)
openapi/v1.yaml                Vertrag der REST-API – wird vor dem Code geschrieben (F18)
uebersetzungen/{de,en}.yaml    Texte und Fehlermeldungen (T5)
module/<name>/                 mitgelieferte Module, je Modul:
    modul.json                   Manifest (F44)
    src/                         Code, Namensraum Kumero\Modul\<Name>
    migrationen/                 SQL, nur im Schema <name>
    uebersetzungen/
db/migrationen/                Kern (wie bisher)
db/verwaltung/                 Migrationen der Verwaltungsdatenbank (neu, Abschnitt 6)
tests/                         PHPUnit; Datenbanktests gegen kumero_test*
```

Auf dem Server:

```
/var/lib/kumero/releases/<version>/   entpackte, signierte Releases (root, nur lesbar)
/var/www/kumero  →  Symlink auf das aktive Release (F56)
/etc/kumero/kumero.php                Konfiguration (root:kumero 0640)
/var/lib/kumero/daten/                veränderliche Daten der Anwendung (kumero, 0700), z. B. Exporte
```

Ein Update schaltet den Symlink erst um, **nachdem** alle Migrationen erfolgreich waren, und lädt
danach PHP-FPM neu. Zurück auf ein älteres Release geht nicht (F73: kein Rückschritt).

## 3. Weg einer Anfrage

```
nginx → public/index.php → Kernel
  1. Anfrage einlesen (HttpFoundation), Anfrage-ID vergeben
  2. Route bestimmen
       /api/v1/...                  global: Anmeldung, eigene Mandantenliste
       /m/{mandant}/api/v1/...      im Mandanten (F39)
  3. Anmeldung prüfen → Subjekt  (Abschnitt 5)
  4. Mandant auflösen: Verwaltungsdatenbank → Datenbankname, Zugang des Subjekts
  5. Wasserstand prüfen (F69): Kettenkopf hinter der Marke → Lesemodus erzwingen
  6. Recht prüfen (F41): Recht der Route + Einschränkungen gegen die Rollen des Subjekts
  7. Schreibend? → Idempotenz-Schlüssel prüfen (F71); gleiche Anfrage → gespeicherte Antwort
  8. Controller → genau ein Anwendungsdienst → eine Transaktion
        → Kernfunktion(en) in PostgreSQL
        → synchrone Erweiterungspunkte der Module (in derselben Transaktion)
  9. Antwort als JSON; Fehler als Problem Details (RFC 9457) mit festem Code und übersetztem Text
 10. Nach dem Commit: Ereignisse liegen in der Outbox, ein Hintergrunddienst stellt sie zu (F21)
```

**Fehlerabbildung** (aus SQLSTATE des Kerns, F74):

| Code | Bedeutung | HTTP |
|---|---|---|
| KM001 | Unveränderlichkeit verletzt | 409 |
| KM002 | Buchungsregel verletzt | 422 |
| KM003 | falscher Zustand (z. B. Jahr gesperrt, nicht freigabebereit) | 409 |
| KM004 | kanonische Form ungültig | 422 |
| KM005 | Konflikt (ETag) | 412 |
| KM006 | Idempotenzschlüssel für eine andere Anfrage verwendet | 409 |
| 40001 / 40P01 | Serialisierung / Verklemmung | einmal automatisch wiederholen, sonst 503 |
| andere | Programmfehler | 500, Details nur im Server-Log, nie in der Antwort |

Server-Logs enthalten **keine Buchungsinhalte** (Art. 9 DSGVO), nur Anfrage-ID, Route, Subjekt, Code.

## 4. Subjekte

Der Kern führt Subjekte als Text (`p_subjekt`). Festgelegt wird die Form:

| Form | Wer |
|---|---|
| `nutzer:<uuid>` | Mensch, angemeldet über Passkey (später auch OIDC) |
| `dienst:<uuid>` | Dienstkonto/Bot mit API-Token |
| `system:<name>` | Kommandozeile und Hintergrunddienste, z. B. `system:cli`, `system:outbox` |

Die UUID kommt aus der Verwaltungsdatenbank und ist mandantenübergreifend gleich.

## 5. Anmeldung (erste Stufe)

> **Nachtrag 0.7.22 (F114, F115; VERWALTUNG.md Abschnitt 3 und 11):** Nutzer gehören zum Mandanten. Anmelde-Passkey,
> Sitzung und Einstellungen eines Nutzers liegen in der **Datenbank des Mandanten** (`kern.nutzer`,
> `kern.anmelde_credential`, `kern.sitzung`, `kern.registrierung`, `kern.nutzer_einstellung`), nicht mehr in der
> Verwaltungsdatenbank. Der Passkey trägt ein Pseudonym; die Verwaltungsdatenbank sagt dazu nur den Mandanten.
> Das Cookie `__Host-kumero` nennt Mandant und Geheimnis; eine Sitzung gilt nur für ihren Mandanten.
> Nr. 2 bis 4 unten beschreiben den Stand davor und gelten so nur noch für System-Verwalter.

1. **Menschen melden sich mit Passkey an** (WebAuthn, Nutzerverifikation Pflicht), ohne Passwort.
   Für die Freigabe braucht jeder Buchhalter ohnehin einen Passkey (F38), eine zweite Methode bringt
   nur Angriffsfläche.
2. **Erster Nutzer** über die Kommandozeile: `bin/kumero nutzer:anlegen` gibt einen
   Registrierungslink aus, gültig 15 Minuten, einmal verwendbar.
3. **Anmelde- und Freigabe-Credential sind getrennt**: Das Anmelde-Credential liegt in der
   Verwaltungsdatenbank; das Freigabe-Credential je Mandant in `kern.credential` (F38, F68). Derselbe
   YubiKey kann beides sein, es sind aber zwei Registrierungen.
4. **Sitzung**: serverseitig in der Verwaltungsdatenbank (nur Hash der Sitzungs-ID), Cookie
   `__Host-kumero` mit `Secure`, `HttpOnly`, `SameSite=Strict`; Leerlauf 30 Minuten, höchstens
   12 Stunden; widerrufbar. Schreibende Anfragen mit Sitzung prüfen zusätzlich den `Origin`.
5. **Dienstkonten**: `Authorization: Bearer kmr_…`, in der Datenbank nur als SHA-256 gespeichert,
   an **einen** Mandanten gebunden, mit Ablaufdatum. Ein Dienstkonto kann im Produktivbetrieb nie
   festschreiben, weil dafür ein WebAuthn-Nachweis eines Menschen nötig ist (F31, F38) – das erzwingt
   bereits die Datenbank.
6. **OIDC/Keycloak** (T6) kommt später hinter derselben Schnittstelle `Anmeldeverfahren` dazu.

## 6. Verwaltungsdatenbank `kumero_verwaltung` (F37)

> **Nachtrag 0.7.22 (F114, F115):** Die Verwaltungsdatenbank hält nur noch Mandantenliste, Wasserstandsmarken,
> System-Verwalter (`nutzer`, `anmelde_credential`, `sitzung`, `nutzer_einstellung` gelten nur für sie) und neu
> `pseudonym` (Pseudonym → Mandant, sonst nichts). `zugang` wird für Nutzer nicht mehr gelesen; Dienstkonten und
> Tokens bleiben bis zu einer späteren Stufe hier (U7).

Eigene Migrationen in `db/verwaltung/`, Schema `verwaltung`, Schreiben ebenfalls nur über Funktionen
mit eigener Protokollkette:

| Tabelle | Inhalt |
|---|---|
| `mandant` | Kennung (für den Pfad), Datenbankname, Anzeigename, angelegt, stillgelegt |
| `nutzer` | UUID, Anzeigename, Sprache, aktiv |
| `anmelde_credential` | WebAuthn-Credential zur Anmeldung (öffentlicher Schlüssel, Zähler, widerrufen) |
| `zugang` | welcher Nutzer bzw. welches Dienstkonto welchen Mandanten öffnen darf |
| `dienstkonto`, `token` | Dienstkonten und Token-Hashes mit Ablauf |
| `sitzung` | Hash der Sitzungs-ID, Subjekt, Zeiten |
| `wasserstand` | je Mandant und Kette: höchstes gesiegeltes Glied mit Hash (F69) |
| `protokoll` | eigene Hash-Kette für alle Änderungen hier |

Keine Buchungsdaten und keine Rollen (die liegen im Mandanten, F63).

## 7. Modul-API `Kumero\Api\V1`

### 7.1 Manifest `modul.json`

Deklarativ, damit Installer und `bin/kumero` es lesen können, ohne Modulcode auszuführen:

```json
{
  "name": "kontenrahmen",
  "version": "0.1.0",
  "api": "1",
  "einstieg": "Kumero\\Modul\\Kontenrahmen\\Modul",
  "abhaengigkeiten": {},
  "rechte": [
    {"code": "kontenrahmen.vorlage.einspielen", "beschreibung": "…"},
    {"code": "kontenrahmen.zuordnung.aendern",  "beschreibung": "…"}
  ],
  "erweiterungsattribute": [],
  "erweiterungspunkte": ["gj_sperren_beitrag"],
  "ereignisse": {"loest_aus": ["kontenrahmen.zuordnung_geaendert"], "abonniert": []}
}
```

Beim Aktivieren in einem Mandanten trägt `bin/kumero modul:aktivieren` das Manifest über die
vorhandenen Kernfunktionen ein (`modul_registrieren`, `erweiterungsattribut_anmelden`, neu:
`recht_anmelden`) und führt die Migrationen des Moduls aus – nur mit Freigabe (F73).

### 7.2 Was ein Modul bekommt

Jeder Aufruf erhält einen **`Kontext`**: Mandant, Subjekt, Sprache, Betriebsart, Lesemodus,
Anfrage-ID. Darüber hinaus stellt die API bereit:

| Dienst | Inhalt |
|---|---|
| `Konten`, `Tags`, `Buchungsarten`, `Geschaeftsjahre` | lesen; anlegen/ändern nur mit dem passenden Recht des Subjekts |
| `Buchungen` | Entwurf anlegen/ändern/löschen, vorbereiten, festschreiben, stornieren, Beleg nachreichen, lesen |
| `Abfragen` | Journal, Kontoblatt, Salden zum Stichtag |
| `Rechte` | `darf(recht, einschraenkung)` für das aktuelle Subjekt |
| `Protokoll` | eigene Änderungen protokollieren (Vorher/Nachher, F48), Typ mit Modulpräfix |
| `Ereignisse` | eigenes Ereignis in die Outbox legen (nur IDs, F48) |
| `ModulDatenbank` | SQL **nur im eigenen Schema**, in der laufenden Transaktion |
| `Uebersetzer` | Texte des Moduls |

Der Kern ist dabei selbst ein Modul (Rechte `kern.*`), benutzt aber dieselben Dienste wie alle.

**Grenze des eigenen Schemas:** Alle Module laufen im selben Prozess mit derselben Datenbankrolle.
PHP kann sie nicht hart voneinander trennen. Den Kern schützt das nicht: `kumero_app` darf
Kern-Tabellen ohnehin nicht schreiben (F64), jede Kernregel gilt also auch gegen ein fehlerhaftes
Modul. Zwischen Modulen gilt die Regel aus F44, geprüft durch Review und einen Test, der das SQL der
Module auf fremde Schemanamen durchsucht.

### 7.3 Erweiterungspunkte (synchron, F46)

| Schnittstelle | Wann | Darf |
|---|---|---|
| `BuchungAnreichern` | beim Vorbereiten | Erweiterungswerte setzen (F58) |
| `BuchungPruefenVorbereitung` | beim Vorbereiten | ablehnen mit Begründung |
| `BuchungPruefenFestschreiben` | beim Festschreiben | nur prüfen → Prüfvermerk (F72) |
| `StornoPruefen` | beim Storno | nur prüfen |
| `GeschaeftsjahrSperrenBeitrag` | vor der Jahressperre | Hash des eigenen Jahresstands liefern (F75 Nr. 11) |

Der Kern ruft die Module in der Reihenfolge ihrer Abhängigkeiten; eine Ausnahme rollt die ganze
Transaktion zurück. Prüfvermerke und Jahresbeiträge schreibt der Kern selbst über
`pruefvermerk_setzen` bzw. `gj_beitrag_setzen` mit Name und Version des Moduls.

### 7.4 Ereignisse (asynchron, F21)

Ein Hintergrunddienst (`kumero-ereignisse.service`, `bin/kumero ereignisse:zustellen`) liest die
Outbox jedes Mandanten mit `FOR UPDATE SKIP LOCKED`, ruft abonnierende Module auf (`EreignisEmpfaenger`)
und stellt Webhooks zu. Zustellung mindestens einmal; Empfänger müssen mit Wiederholungen umgehen.

### 7.5 Stabilität

`Api\V1` folgt SemVer für sich: Innerhalb von V1 nur Ergänzungen. Was entfällt, wird erst als
veraltet markiert und frühestens mit V2 entfernt; V1 und V2 laufen eine Übergangszeit nebeneinander.

## 8. Konfiguration `/etc/kumero/kumero.php`

Eine PHP-Datei, die ein Array zurückgibt (kein Parser, Fehler sofort sichtbar). Vom Installer
angelegt, danach vom Betreiber gepflegt:

```php
<?php return [
    'adresse'        => 'https://kumero.example.org',   // Origin und RP-ID für WebAuthn (F68)
    'sprache'        => 'de',
    'verwaltung_db'  => 'kumero_verwaltung',            // Peer-Auth, kein Passwort
    'signierdienst'  => '/run/kumero-ssm/ssm.sock',
    'sitzung'        => ['leerlauf_min' => 30, 'hoechstens_std' => 12],
    'protokoll'      => ['stufe' => 'info'],            // Server-Log, ohne Buchungsinhalte
];
```

## 9. Auslieferung

1. `composer.lock` liegt im Repository. `werkzeuge/release-bauen.sh` baut aus dem Tag, führt
   `composer install --no-dev --classmap-authoritative` aus und packt **`vendor/` mit ins Archiv**.
   Damit deckt deine Signatur jede Zeile Code ab, die auf dem Server läuft (F73).
2. `composer audit` läuft beim Bauen; eine bekannte Lücke bricht den Bau ab.
3. Sicherheitsupdates von Symfony oder DBAL kommen dadurch als neues kumero-Release, nicht über apt.
   Betriebssystem, PHP und PostgreSQL bleiben bei Debian/sury/PGDG mit automatischen Updates.
4. `aktualisieren.sh`: wie bisher, zusätzlich Modul-Migrationen, Verwaltungsdatenbank, Umschalten des
   Symlinks, Neustart von PHP-FPM und Ereignisdienst.

## 10. Ergänzungen im Datenbankkern (umgesetzt in Version 0.4.0)

Die Anwendung schreibt nur über Funktionen (F64). Dafür kamen hinzu:

| Funktion | Zweck |
|---|---|
| `recht_anmelden`, `baustein_setzen`, `rolle_setzen`, `zuweisen`, `zuweisung_beenden` | Rechte und Rollen pflegen (F41), mit Protokoll; Invarianten F43 beim Commit |
| `rollenvorlagen_anlegen` | Rechtekatalog des Kerns und Vorlagen aus F42 beim Anlegen des Buchs |
| `rechte_des_subjekts` | effektive Rechte samt Einschränkungen zum Prüfen in PHP |
| `idempotenz_beginnen`, `idempotenz_abschliessen`, `idempotenz_aufraeumen` | Idempotenz (F71); Fehlercode `KM006` → HTTP 409 |
| `modul_protokoll` | Protokolleintrag eines Moduls; erzeugt zugleich das Ereignis in der Outbox |
| `empfaenger_holen`, `empfaenger_vermerken` | Ereignisse für Module im selben Prozess, Lesestand je Modul |
| `webhook_abo_anlegen`, `webhook_abo_aendern`, `zustellung_holen`, `zustellung_vermerken` | Webhooks (F21) |

Dazu `db/verwaltung/` (Abschnitt 6). In der Fehlerabbildung (Abschnitt 3) gilt zusätzlich: `KM006` → 409.

## 10a. Festlegungen aus der Umsetzung (0.5.0, bestätigt am 2026-11-17 → F84)

1. **Abhängigkeiten über Git:** `composer.json` holt Symfony, Doctrine und PSR direkt aus den Git-Repositorys der Projekte (`repositories`, ohne Packagist). `composer.lock` legt jeden Stand per Commit fest; das signierte Release enthält `vendor/` (E1).
2. **Symfony `^7.4 || ^8.1`:** Das Release wird für PHP 8.5 gebaut und enthält Symfony 8.1 (F53). Der Code läuft auch mit 7.4 auf PHP 8.3; so laufen die Tests auch dort, wo kein PHP 8.5 verfügbar ist. Auf dem Zielsystem prüft `bin/kumero selbsttest` nach jedem Update PHP, Erweiterungen, Datenbanken, WebAuthn-Kryptografie und die Anwendung.
3. **Eigener kleiner Testrahmen** (`tests/lauf.php`) statt PHPUnit: keine weiteren Abhängigkeiten, gleiche Struktur wie die Datenbanktests.
4. **Testzugang über SSH-Tunnel:** Passkeys verlangen einen Hostnamen mit gültigem Zertifikat oder `http://localhost`. Mit `ENTWICKLUNG_TUNNEL=ja` lauscht nginx zusätzlich nur auf `127.0.0.1:8080`; die Anwendung erwartet dann `http://localhost:8080`. IP-Adressen als `adresse` weist die Konfiguration ab.
5. **Passkeys als auffindbare Schlüssel** (`residentKey: required`): Anmeldung ohne Eingabe eines Nutzernamens.
6. **Registrierungslink** mit dem Token im Fragment (`app.html#registrierung=…`, bis 0.6.0 `start.html`), damit es nicht in Server-Logs steht.
7. **Fehlerkennung** `type` als URN (`urn:kumero:problem:<code>`), weil das Projekt noch keine eigene Domain hat.
8. **Einschränkung zu Abschnitt 9 Nr. 2:** `composer audit` fragt die Sicherheitsdatenbank von Packagist ab. Ist Packagist beim Bauen nicht erreichbar, findet es nichts und meldet trotzdem Erfolg. Bis das Bauen mit Zugang zu Packagist läuft, ist diese Prüfung also wirkungslos; Sicherheitsmeldungen von Symfony und Doctrine sind von Hand zu verfolgen.

## 11. Entscheidungen (freigegeben 2026-11-14)

| # | Frage | Vorschlag |
|---|---|---|
| E1 | Abhängigkeiten mitliefern? | **Ja**, `vendor/` im signierten Release (Abschnitt 9). Debian-Pakete scheiden aus: trixie hat Symfony 6.4, F53 verlangt 8.x. |
| E2 | Anmeldung der ersten Stufe | **Nur Passkey**, kein Passwort; OIDC später (Abschnitt 5). |
| E3 | WebAuthn-Prüfung in PHP | **Eigene, kleine Prüfung** für ES256 und EdDSA mit `ext-openssl` und `ext-sodium`, Registrierung mit Attestation `none`. Etwa 400 Zeilen, vollständig nachvollziehbar; das unabhängige Prüfwerkzeug (F68) nutzt denselben Code. Alternative: `web-auth/webauthn-lib` (umfangreich, viele Abhängigkeiten). |
| E4 | Subjekt-Form | `nutzer:`, `dienst:`, `system:` mit UUID (Abschnitt 4). |
| E5 | Manifest | deklarativ als `modul.json` (Abschnitt 7.1). |
| E6 | Konfiguration | `/etc/kumero/kumero.php`, ohne Geheimnisse (Abschnitt 8). |
| E7 | Ausrollen | Symlink `/var/www/kumero` auf das aktive Release, Umschalten nach erfolgreicher Migration. |
| E8 | Reihenfolge des Bauens | (a) Kern 0.4.0 mit den Funktionen aus Abschnitt 10 und `db/verwaltung/`, (b) `openapi/v1.yaml` für Anmeldung, Stammdaten, Buchungen, (c) Plattform und REST-Adapter, (d) Modul `kontenrahmen` mit SKR-81-Weg (F76), (e) erste Oberfläche. Jeder Schritt einzeln als Release zur Freigabe. |
