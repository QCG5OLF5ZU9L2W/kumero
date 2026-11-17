# PROFIL.md – Nutzerprofil und Einstellungen (Entwurf 1, zur Freigabe)

Stand: 2026-11-17. Bezug: T5 (mehrsprachig), F38 (Freigabe-Passkeys), F77 E2 (Anmeldung per Passkey),
F80 (Rahmen der Oberfläche, Einstellungsabschnitte N1), ANWENDUNG.md Abschnitt 5 und 6, BUCHUNGSMASKE.md M10.
**Status: erste Stufe gebaut in kumero 0.7.1 (F86):** Nutzermenü, Seite „Profil und Einstellungen“, Ablage am Nutzer, Sprache (U5), Betragseingabe, Schriftgröße, Kontrast, REST `PATCH /api/v1/ich` und `GET`/`PUT /api/v1/ich/einstellungen`. **Noch nicht gebaut:** automatische Abmeldung mit Vorwarnung, Freigabe-Passkeys, Sitzungen, Anrede, Zeilenhöhe, Bewegung, Einstellungen aus Modulen (Abschnitt 4), U11 (kommt mit dem Modul `paperless`).

Anlass: Umschalter für bestimmte Darstellungen (z. B. Betragseingabe dezimal oder kaufmännisch) hängen am
Nutzer, nicht am Mandanten und nicht am Gerät. Alles, was zum Nutzer gehört, bekommt einen Ort: ein
**Nutzermenü** oben rechts und eine Seite **„Profil und Einstellungen“**.

---

## 1. Nutzermenü

Heute stehen oben rechts der Name und der Knopf „Abmelden“. Künftig:

```
┌──────────────────────────────────────────────────────────────── Erika Muster ▾ ┐
                                                          │ Profil und Einstellungen │
                                                          │ Abmelden                 │
                                                          └──────────────────────────┘
```

- Ein Klick auf den Namen (oder Tastatur) öffnet das Menü. Abmelden wandert dort hinein.
- Dort steht auch der Schalter **„Livelog“** (LIVELOG.md).
- Die Seite ist systemweit (`#/system/kern/profil`), also ohne gewählten Mandanten erreichbar, und steht
  nicht in der Navigation links.

## 2. Seite „Profil und Einstellungen“

Abschnitte auf einer Seite (Vorbild Nextcloud: Einstellungen als Abschnitte, OBERFLAECHE.md Abschnitt 1):

| Abschnitt | Inhalt |
|---|---|
| **Profil** | Anzeigename, Subjekt (`nutzer:<uuid>`, nur Anzeige), Verwalter-Kennzeichen (nur Anzeige), Mandanten mit den eigenen Rollen (nur Anzeige). |
| **Sprache** | Sprache der Oberfläche, Meldungen und Fehlertexte (T5) und damit die Schreibweise von Datum und Zahlen. Auswahl: **„wie der Browser“** (nichts gesetzt) oder eine der Sprachen, für die der Kern Texte mitbringt – heute nur Deutsch. Das Feld gibt es schon (`verwaltung.nutzer.sprache`), bisher nur über die Kommandozeile und immer gesetzt (`de`); künftig darf es leer sein. |
| **Darstellung** | **Betragseingabe**: dezimal (`1000` → 1.000,00) oder kaufmännisch (`1000` → 10,00), siehe BUCHUNGSMASKE.md M10. Weitere Schalter kommen hier dazu, auch aus Modulen (Abschnitt 4). |
| **Bedienung und Lesbarkeit** | Einstellungen für eine barrierearme, diskriminierungsarme Nutzung (Abschnitt 4a): Schriftgröße, Kontrast, Zeilenabstand der Listen, Bewegung, Anrede. |
| **Paperless** (je Mandant, wenn das Modul `paperless` aktiv ist) | Die eigenen Verbindungen – die Vorgabe des Mandanten, überschreibbar, und beliebig viele weitere Instanzen. Je Instanz: Adresse, **eigenes Token** (kumero-Konto und Paperless-Konto sind eine Identität), **Tag für offene Belege**, Auswahl der Tags in einem aufklappbaren Fenster, Verbindungstest. Einzelheiten: PAPERLESS.md Abschnitt 2 und 3. Belege mit dem Tag bilden den Arbeitsvorrat des Nutzers (BELEGEINGANG.md Abschnitt 2a). |
| **Sitzung** | **Automatische Abmeldung** nach Leerlauf: eigener Wert in Minuten, höchstens der vom Betreiber gesetzte (`sitzung.leerlauf_min` in `/etc/kumero/kumero.php`, heute 30). Anzeige der eigenen laufenden Sitzungen mit „überall abmelden“. |
| **Passkeys** | Anmelde-Passkeys: ansehen, weiteren registrieren, widerrufen (der letzte lässt sich nicht widerrufen). **Freigabe-Passkeys je Mandant** (F38): registrieren und widerrufen – das ist die Lücke aus BUCHUNGSMASKE.md Abschnitt 7 Nr. 11, ohne die im Produktivbetrieb niemand freigeben kann. |

Was **nicht** hierher gehört: Rollen und Zugänge (Verwaltung bzw. Mandant, F41, F63), Einstellungen des
Mandanten (Bereich Einstellungen), Einstellungen des Betreibers (Konfigurationsdatei).

## 3. Wo die Einstellungen liegen

- **Am Nutzer in der Verwaltungsdatenbank** (F37), nicht im Browser: Sie gelten auf jedem Gerät und für alle
  Mandanten. Neue Tabelle `verwaltung.nutzer_einstellung` (Nutzer, Schlüssel, Wert als JSON), Schreiben nur
  über eine Funktion mit Eintrag in der Protokollkette der Verwaltung (Vorher/Nachher).
- Schlüssel tragen den Namen des Besitzers als Präfix: `kern.betragseingabe`, `kern.leerlauf_min`,
  später z. B. `buchen.…`. Unbekannte Schlüssel werden abgewiesen.
- Die Verwaltungsdatenbank enthält weiter **keine Buchungsdaten** (F37); Einstellungen sind keine.
- **Ausnahme Paperless:** Die Verbindung gilt je Mandant und enthält ein Geheimnis (das Token). Sie liegt
  deshalb nicht hier, sondern verschlüsselt in der Mandanten-Datenbank beim Modul `paperless`; entschlüsselt
  wird mit der Anmeldung des Nutzers per Passkey (PAPERLESS.md L3); die Profilseite zeigt sie nur an derselben Stelle an.
- Sprache bleibt das vorhandene Feld am Nutzer; der Nutzer darf es künftig selbst ändern.

## 4. Einstellungen aus Modulen

Module melden Schalter **deklarativ** im Manifest an (Abschnitt `oberflaeche.einstellungen`, in F80 N1
vorgesehen, bisher nicht umgesetzt). Für Nutzereinstellungen kommt ein Eintrag je Schalter dazu:

```json
"nutzereinstellungen": [
  { "schluessel": "buchen.nach_speichern", "titel": "einstellung.nach_speichern",
    "art": "auswahl", "werte": ["leeren", "behalten"], "standard": "leeren" }
]
```

Der Kern zeigt sie im Abschnitt „Darstellung“ unter dem Namen des Moduls, prüft Art und Werte und liefert sie
den Seiten im `kontext` mit. Module schreiben nie selbst in die Verwaltungsdatenbank.

## 4a. Diskriminierungsfrei und barrierearm

Die Buchhaltung soll niemanden wegen einer Einschränkung oder wegen seiner Person ausschließen oder
benachteiligen. Maßstab ist die **EN 301 549 / WCAG 2.2 Stufe AA** (in Deutschland über BITV 2.0 und das
Barrierefreiheitsstärkungsgesetz bekannt) – als Ziel für die Oberfläche, ohne dass hier eine rechtliche
Pflicht behauptet wird.

Was für alle gilt, ohne Schalter:

- **Alles mit der Tastatur**, sichtbarer Fokus, sinnvolle Reihenfolge (BUCHUNGSMASKE.md M15), keine Zeitfalle:
  Vor der automatischen Abmeldung kommt ein Hinweis mit der Möglichkeit zu verlängern.
- **Nie nur Farbe:** Zustände (Entwurf, festgeschrieben, storniert, Fehler) tragen immer auch Text oder
  Zeichen (BUCHUNGSMASKE.md M14).
- **Vorleseprogramme:** Felder haben Beschriftungen, Tabellen Kopfzeilen, Meldungen werden angesagt;
  die kleinen Hilfen (M13) stehen auch dort zur Verfügung.
- **Kontrast** von Text und Bedienelementen mindestens nach AA; Zoom bis 200 % ohne abgeschnittene Inhalte
  (M12).
- **Sprache der Oberfläche:** klar, ohne Fachkürzel ohne Erklärung; geschlechtsneutrale Bezeichnungen
  (z. B. „verantwortliche Person“ statt nur männlicher Form) – auch für die Rollennamen zu prüfen.

Was der Nutzer für sich einstellt (Abschnitt „Bedienung und Lesbarkeit“):

| Einstellung | Werte |
|---|---|
| Schriftgröße | normal, groß, sehr groß |
| Kontrast | normal, hoch; „wie das System“ folgt der Einstellung des Betriebssystems |
| Zeilenhöhe der Listen | dicht (Vorbild Sage), bequem |
| Bewegung | Übergänge an/aus; „wie das System“ |
| Anrede | Du, Sie (entschieden am 2026-11-17: Einstellung des Nutzers) – die Oberfläche duzt heute durchgehend; alle Texte brauchen dafür beide Fassungen |

Keine Einstellung fragt nach persönlichen Merkmalen (Geschlecht, Alter, Behinderung); gespeichert wird
nur die gewählte Darstellung. Die Einstellungen sind keine besonderen Kategorien nach Art. 9 DSGVO, lassen
aber Rückschlüsse zu: Sie sind nur für den Nutzer selbst sichtbar, nicht für Verwalter, und stehen im
Protokoll nur als „Einstellung geändert“ ohne Wert.

## 5. REST (Ergänzung zu openapi/v1.yaml)

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/api/v1/ich` | wie bisher, zusätzlich `einstellungen` (wirksame Werte) |
| PATCH | `/api/v1/ich` | Anzeigename (falls U2 so entschieden) und Sprache ändern |
| GET / PUT | `/api/v1/ich/einstellungen` | eigene Einstellungen lesen bzw. einzelne Schlüssel setzen |
| GET / DELETE | `/api/v1/ich/sitzungen` | eigene Sitzungen sehen, alle anderen beenden |
| GET / POST / DELETE | `/api/v1/ich/passkeys` | Anmelde-Passkeys |
| GET / POST / DELETE | `/m/{mandant}/api/v1/passkeys` | Freigabe-Passkeys im Mandanten (steht schon im Vertrag, ist nicht umgesetzt) |

Alle Aufrufe betreffen nur den angemeldeten Nutzer selbst; Dienstkonten haben kein Profil.

## 6. Entscheidungen zur Freigabe

| # | Frage | Vorschlag |
|---|---|---|
| U1 | Ort | Nutzermenü oben rechts mit „Profil und Einstellungen“ und „Abmelden“; eine Seite mit Abschnitten. |
| U2 | Anzeigename | Der Nutzer darf seinen Anzeigenamen **nicht** selbst ändern (er steht in Protokoll und Rechtebericht); das bleibt beim Verwalter. Alternative: selbst änderbar, mit Protokolleintrag. |
| U3 | Ablage | Am Nutzer in der Verwaltungsdatenbank, mit Protokoll; nicht im Browser. |
| U4 | Sprache | Der Nutzer stellt sie selbst ein; sie gilt für Oberfläche, Meldungen und Fehlertexte. Solange es nur Deutsch gibt, zeigt die Auswahl nur Deutsch. |
| U5 | Sprache und Schreibweise | **Entschieden am 2026-11-17:** Die Spracheinstellung des Nutzers geht vor; ist keine gesetzt, gilt die Sprache des Browsers. Das gilt für Texte, Datum und Zahlen (BUCHUNGSMASKE.md M11). Folge: `sprache` am Nutzer darf leer sein; gibt es für die Sprache des Browsers keine Texte, bleiben die Texte deutsch, Datum und Zahlen folgen trotzdem dem Browser. |
| U6 | Automatische Abmeldung | Eigener Leerlauf-Wert je Nutzer, nur **kürzer** als die Obergrenze des Betreibers; die Höchstdauer einer Sitzung (12 Stunden) bleibt Sache des Betreibers. |
| U7 | Passkeys | Anmelde- und Freigabe-Passkeys auf dieser Seite; Freigabe-Passkeys je Mandant. Registrieren eines weiteren Passkeys verlangt eine frische Bestätigung mit einem vorhandenen. |
| U8 | Einstellungen aus Modulen | Deklarativ im Manifest (Abschnitt 4); der Kern zeigt und speichert sie. |
| U10 | Diskriminierungsfrei und barrierearm | Ziel WCAG 2.2 AA für die ganze Oberfläche; Grundsätze und Einstellungen wie in Abschnitt 4a. **Anrede: entschieden am 2026-11-17 – Einstellung des Nutzers.** Zu klären: Rollennamen geschlechtsneutral umbenennen (ändert F42) oder nur in der Anzeige. |
| U9 | Umfang der ersten Stufe | Nutzermenü, Seite mit Profil (nur Anzeige), Sprache, Betragseingabe, Schriftgröße und Kontrast, automatische Abmeldung mit Vorwarnung, Freigabe-Passkeys. Anmelde-Passkeys verwalten, Sitzungsliste und Einstellungen aus Modulen in einer zweiten Stufe. |
| U11 | Tag für offene Belege | **Entschieden am 2026-11-17:** Das Tag, das in Paperless die noch zu buchenden Belege kennzeichnet, wird in den Einstellungen des Nutzers festgelegt. Offene Belege kommen in den Arbeitsvorrat und werden beim Aufruf mit den nopaper-Daten abgeglichen (BELEGEINGANG.md B13). Das Tag hängt an der Paperless-Verbindung des Nutzers: Wer die Verbindung anlegt, legt es fest – je Nutzer und Mandant (PAPERLESS.md L2, L11). |
