# AGENTS.md – kumero (offene Buchhaltung, doppischer Kern)

Diese Datei hält fest, **was** gebaut wird und **welche Entscheidungen bereits gefallen sind**.
Wer an diesem Projekt arbeitet (Mensch oder Coding-Agent), hält sich daran. Abweichungen nur nach
Rücksprache mit dem Projektverantwortlichen. **Es wird erst gebaut, wenn der jeweilige Schritt definiert ist.**

Stand: 2026-11-17 (F4–F78; Datenmodell Fassung 3.2 → DATENMODELL.md; Anwendung Fassung 1 → ANWENDUNG.md; Version 0.4.1)

---

## 1. Ziel

Eine offene, selbst betriebene Buchhaltungssoftware für kleine und mittlere Unternehmen, Praxen und
Freiberufler – als Alternative zu geschlossenen Produkten wie Sage 50 oder Lexware.
Ordnungsmäßigkeit (GoBD) ist Bestandteil des Kerns, nicht ein Zusatzmodul.

## 2. Leitlinien

| # | Leitlinie |
|---|---|
| L1 | **Ordnungsmäßigkeit im Kern**: unveränderliches Journal, Korrektur nur per Storno, lückenloses Protokoll, Datenzugriff für die Betriebsprüfung, Vorlage für die Verfahrensdokumentation. |
| L2 | **Die Daten gehören dem Anwender**: offene, dokumentierte Formate, vollständiger Export jederzeit, keine verschlüsselten oder proprietären Datenbestände. |
| L3 | **Selbst betrieben, kein Cloud-Zwang** – auch für Zusatzfunktionen (z. B. KI, Bankabruf). |
| L4 | **Offene Schnittstellen nach außen**: DATEV-Export für den Steuerberater, ELSTER über ERiC (bezieht der Betreiber selbst, ERiC wird nicht mitgeliefert), E-Rechnung, FinTS/CAMT, Paperless-ngx. |

## 3. Technische Festlegungen

| # | Festlegung |
|---|---|
| T1 | **Betriebssystem: Linux.** |
| T2 | **Datenbank: PostgreSQL.** |
| T3 | **Programmiersprache: PHP.** |
| T4 | **Webserver: nginx** (festgelegt 2026-11-13; Apache wird nicht unterstützt). |
| T5 | **Mehrsprachig** (Oberfläche, Meldungen, Fehlertexte). |
| T6 | **Mehrbenutzerfähig.** Nutzerverwaltung **lokal** und über **Zero-Trust-Anbindung**, z. B. Keycloak, mit den dort üblichen Verfahren. |
| T7 | **Hochmodular.** Der Buchungskern ist ein eigenes Modul; alles Weitere setzt darauf auf. |
| T8 | **Dokumente liegen immer in Paperless-ngx.** Tags und weitere Ordnungsmerkmale werden von dort übernommen. In Paperless werden **zu jedem Beleg die Buchungsdaten jeder zugehörigen Buchung** mitgeführt. |

Hinweis: Ein früherer Python-Entwurf des Buchungskerns (`doppik-kern`) ist durch T3 überholt und
dient höchstens als Diskussionsgrundlage für das Datenmodell.

## 4. Getroffene fachliche Entscheidungen

| # | Entscheidung |
|---|---|
| F1 | Der Buchungskern ist **doppisch** und **webfähig**. |
| F2 | **Konten und Kontenrahmen sind Stammdaten**, keine Sache des Buchungskerns. Ein Kontenrahmen ist eine Sammlung von Konten mit Bezeichnungen und Zuordnungen (Bilanz, GuV usw.). Der Kern kennt Konten nur als Kennungen. |
| F3 | Buchungen werden **gesiegelt**, damit Unveränderbarkeit nachweisbar ist. Das Verfahren ist noch festzulegen (siehe 5., P2). |
| F4 | **Buchungssatz = Kopf + n ≥ 2 Zeilen.** Mindestens eine Zeile im Soll und eine im Haben, kein Konto auf beiden Seiten desselben Buchungssatzes, Σ Soll = Σ Haben. **Splitbuchungen sind eine Erfassungsmaske**, kein eigener Typ und keine mehreren Buchungen. |
| F5 | **Konten liegen in einer Stammdatentabelle**, Buchungszeilen verweisen per Fremdschlüssel darauf. Kontoblätter sind Abfragen, keine eigenen Tabellen. Keine Tabelle pro Konto. |
| F6 | Die Ausgeglichenheit (Σ Soll = Σ Haben) wird **zusätzlich in PostgreSQL** per Constraint-Trigger beim Commit (`DEFERRABLE INITIALLY DEFERRED`) erzwungen. |
| F7 | **Konto = Pflichtklassifizierung jeder Buchungszeile**: genau eines je Zeile, aus der gepflegten Kontenliste, mit **Kontoart** (Aktiv, Passiv, Aufwand, Ertrag). Die Kontoart steuert den Abschluss (Saldovortrag bzw. Abschluss über GuV), nicht das Buchen. |
| F8 | **Tags = freie, zusätzliche Dimensionen je Zeile**: beliebig viele, ohne Saldenpflicht (z. B. Kostenstelle, Projekt, Standort, Tags aus Paperless). Sie dienen Filter und Auswertung, nie der Saldenbildung. |
| F9 | **Ein Journal, eigene EÜR-Regeln** (korrigiert): Kern ist das doppische Journal; die EÜR ist **keine bloße Auswertung der Geldkonten**, sondern wird vom Modul `euer` nach eigenem fachlichem Regelwerk (§ 4 Abs. 3 EStG) aus dem Journal abgeleitet: Zufluss-/Abflussprinzip (§ 11 EStG), Absetzung für Abnutzung statt Abfluss bei Anlagegütern (aus Modul `anlagen`), Ausschluss durchlaufender Posten, vereinnahmte/verausgabte und erstattete Umsatzsteuer als Betriebseinnahme/-ausgabe, nicht abziehbare Betriebsausgaben, Zuordnung zu den Zeilen der Anlage EÜR. Ein Kern bedient EÜR und Bilanz. |
| F10 | **Belegverweis im versiegelten Buchungskopf**: Paperless-Dokument-ID, **MD5 des Originals** (identisch zum Verfahren von Paperless-ngx, `original_checksum`, für den Abgleich) und **SHA-256 derselben Originaldatei** (kryptografisch belastbarer Nachweis; entspricht `sha256-original` aus nopaper). Maßgeblich ist immer das **Original**, nie die Archivfassung (diese kann neu erzeugt werden). |
| F11 | **Eine Buchwährung je Buch** (z. B. EUR). Nur der Betrag in Buchwährung zählt für Σ Soll = Σ Haben und für Salden. |
| F12 | **Fremdwährung als feste, versiegelte Felder je Zeile**: `fw_betrag` (exakt mit den Nachkommastellen der Währung), `fw_waehrung` (ISO 4217), `kurs` (mind. 6 Nachkommastellen), `kurs_datum`, `kurs_art` (z. B. `tageskurs_bank`, `ezb_referenz`, `bmf_ust_monat`). Entweder alle gefüllt oder alle leer. **Kursrichtung: Mengennotierung** (Einheiten Fremdwährung je 1 EUR); es gilt `betrag = fw_betrag / kurs`, kaufmännisch auf Cent gerundet – der Kern prüft das und weist Abweichungen ab. Der Kurs gilt **je Zeile** (z. B. Tageskurs für Aufwand, BMF-Monatskurs für Vorsteuer nach § 16 Abs. 6 UStG). Kursdifferenzen und Kursbeschaffung sind Aufgabe von Modulen außerhalb des Kerns. |
| F13 | **Die Hash-Kette bildet die Serie.** Jede festgeschriebene Buchung trägt `hash = SHA-256(hash der Vorgängerin ‖ kanonische Form der Buchung)`; die kanonische Form umfasst Kopf, alle Zeilen, Belegverweis und Fremdwährungsfelder. Reihenfolge und Lückenlosigkeit ergeben sich aus der Kette. Das Protokoll (Stammdaten, Entwürfe) wird ebenso verkettet. |
| F14 | **Verankerung durch Zeitstempel**: Der jeweils neueste Ketten-Hash wird in festen Abständen (sofern neue Buchungen vorliegen) sowie bei jedem Periodenabschluss vom Signierdienst **per PGP signiert** und mit einem **qualifizierten eIDAS-Zeitstempel nach RFC 3161** versehen. Ein Stempel verankert alle Buchungen davor. Erster Anbieter: **BalTstamp**, austauschbar (Standardprotokoll). Eine nachträglich neu berechnete Kette kann keine Stempel aus der Vergangenheit erhalten – Manipulation bleibt nachweisbar. |
| F15 | **Signierdienst = Sicherheitsmodul mit API**, getrennt von der Anwendung (eigener Prozess/Systembenutzer, möglichst eigener Rechner). Nur er hat Zugriff auf den Signaturschlüssel (möglichst OpenPGP-Smartcard). Nach außen geht **ausschließlich der Hash**, nie Buchungsdaten. |
| F16 | **Ausfall des Zeitstempeldienstes**: Festschreiben läuft weiter, der Kettenkopf erhält den Status „Stempel ausstehend“, der Stempel wird nachgeholt; die Lücke bleibt sichtbar. **Langzeitnachweis**: Zertifikatskette und Sperrinformationen des Anbieters werden archiviert; vor Ablauf der Sicherheit eines Verfahrens wird neu gestempelt (Evidence Record, RFC 4998). |
| F17 | **Datenbank-Absicherung**: Die Anwendung hat keine UPDATE/DELETE/TRUNCATE-Rechte auf Journal und Protokoll; Festschreiben erfolgt nur über eine PostgreSQL-Funktion, die Σ Soll = Σ Haben prüft, die lfd. Nummer lückenlos vergibt (gesperrte Zählerzeile, keine Sequenz) und den Hash berechnet. Zusätzlich verbieten Trigger UPDATE/DELETE. Präzisiert durch F64: Die Anwendung schreibt **überhaupt nur über Datenbankfunktionen**, auch bei Entwürfen und Stammdaten. |
| F18 | **API-first**: Alle Funktionen des Kerns und jedes Moduls sind über eine **REST-API (HTTPS, JSON)** erreichbar, beschrieben in **OpenAPI 3.1** (erst der Vertrag, dann der Code). Die eigene Weboberfläche nutzt **ausschließlich** diese API. Versionierung über den Pfad (`/api/v1/…`), alte Versionen laufen eine Übergangszeit weiter. |
| F19 | **API-Konventionen**: Anmeldung per **OAuth 2.0 / OIDC** (Keycloak) sowie lokale Tokens für Nutzer und Dienstkonten. Schreibende Aufrufe mit **Idempotenzschlüssel** (`Idempotency-Key`), damit Wiederholungen nie doppelt buchen. **ETag / If-Match** bei Entwürfen. Fehler nach **RFC 9457** (Problem Details) mit festen Fehlercodes und übersetzten Texten. **Beträge als Dezimal-String**, nie als Fließkommazahl. Seitenweise Abfragen per Cursor; große Exporte als asynchrone Aufträge. Jeder schreibende Aufruf wird mit Nutzer, Zeit und Idempotenzschlüssel im verketteten Protokoll erfasst. |
| F20 | **Fachliche Befehle statt Tabellenzugriff**: Für Buchungen gibt es nur Anlegen (Entwurf), Festschreiben, Stornieren und Lesen – **kein Ändern, kein Löschen**, auch nicht über die API. |
| F21 | **Ereignisse für Module über Webhooks** (z. B. `buchung.festgeschrieben`, `siegel.erstellt`), zuverlässig zugestellt über eine Outbox-Tabelle in PostgreSQL. |
| F22 | **Abstrakte Signier-API**: Der Signierdienst bietet eine einheitliche Schnittstelle; die Verfahren dahinter sind austauschbar (Software-PGP, Hardware-Token wie YubiKey/Smartcard, TPM, eigene Hardware, qualifiziertes eSiegel). **Start mit Software-PGP** als eigenem Sicherheitsmodul auf dem Server, entsperrt durch eine Passphrase. Ist der Dienst gesperrt oder nicht erreichbar, gilt der Status „Signatur ausstehend“ und wird nachgeholt (wie F16). Nutzer melden sich mit YubiKey über Keycloak/WebAuthn an; ein Auslesen von Smartcards über den Browser ist nicht nötig. |
| F23 | **Schlüsselverwaltung**: OpenPGP-Schlüssel getrennt in **Stammschlüssel** (nur Bestätigen und Widerrufen) und **Signier-Unterschlüssel**. Auf dem Server liegt nur der Unterschlüssel, im GnuPG-Verzeichnis des Signierdienstes, mit Passphrase und optional `systemd-creds` (TPM-Bindung nur für die laufende Kopie). Der Stammschlüssel liegt **nie auf dem Server**, sondern offline in zwei verschlüsselten, **nicht** an einen Server gebundenen Kopien an getrennten Orten; dazu ein vorab erzeugtes Widerrufszertifikat, getrennt abgelegt. Notfälle: (1) Server defekt → Unterschlüssel aus Offline-Sicherung einspielen, Ereignis „Schlüssel wiederhergestellt“; (2) Unterschlüssel verloren/kompromittiert → per Stammschlüssel widerrufen und neuen bestätigen; (3) Stammschlüssel verloren → Widerrufszertifikat veröffentlichen, neuer Stammschlüssel, **Notfall-Ereignis** mit Bestätigung durch eine verantwortliche Person (z. B. persönliche QES oder über den Steuerberater) und Zeitstempel. Wechsel, Widerruf und Wiederherstellung sind Ereignisse in der Kette. Die Unveränderbarkeit bleibt in allen Fällen über Kette und Zeitstempel nachweisbar. Wiederherstellung wird **jährlich geübt** und protokolliert; Verwahrung und Befugnisse regelt die Verfahrensdokumentation. |
| F24 | **Betragsdarstellung**: In Datenbank und API ist der Betrag je Zeile **immer positiv** (`betrag numeric(15,2)`, `CHECK (betrag > 0)`), die Seite steht im Feld **`seite`** (`S` = Soll, `H` = Haben). Ausgeglichen ist ein Buchungssatz bei Σ Soll = Σ Haben (F4). Fremdwährungsbeträge (F12) sind ebenfalls positiv. Vorzeichen gibt es nur intern in Auswertungs-Sichten (Views) zur Saldenbildung, nie in gespeicherten Daten oder der API. |
| F25 | **Nummerierung – drei Nummern mit getrennten Aufgaben** (eine Buchungstabelle, F5). (1) **`journal_nr`**: vom System beim Festschreiben vergeben, lückenlos je Buch, **endlos** über alle Jahre, nicht konfigurierbar; Position in der Hash-Kette (F13) und Nachweis der Lückenlosigkeit über alle Nummernkreise. (2) **`nr` aus einem Nummernkreis**: vom System beim Festschreiben vergeben, lückenlos je Kreis; **mehrere Nummernkreise je Buch** zulässig, jeder Kreis gehört zu einer **Buchungsart** (z. B. Kasse, Bank, Eingangsrechnung, Ausgangsrechnung), jede Buchungsart hat einen Standardkreis. Je Kreis und Geschäftsjahr konfigurierbar: **Formatvorlage** (Platzhalter z. B. `{JJJJ}`, `{JJ}`, `{MM}`, `{NR:n}`, fester Text) und **Zurücksetzen** (neu je Geschäftsjahr oder fortlaufend). Die Festlegung erfolgt vor der ersten Festschreibung im Geschäftsjahr, ist danach unveränderlich und selbst ein Ereignis der Protokollkette. Gespeichert werden `nr_zahl` und die formatierte `nr`; die formatierte Nummer geht in den Hash ein und ist je Buch eindeutig (Constraint). (3) **`belegnummer`**: Freitext des Anwenders, externe Rechnungs-/Belegnummer (entspricht DATEV „Belegfeld 1“, Schlüssel für OP-Ausgleich). Vorbild: SAP (Nummernkreis je Belegart), DATEV/Sage (interne Journalfolge + Belegnummer). Entwürfe erhalten keine Nummern. |
| F26 | **Kopffelder des Buchungssatzes**: `id` (UUID, beim Anlegen), `buchungsart`, `nummernkreis_id`, `journal_nr`, `nr_zahl`, `nr` (F25, erst beim Festschreiben), `status` (`entwurf` / `festgeschrieben`; Storno ist eine eigene Buchung, kein Status), `belegdatum` (Datum laut Beleg), `buchungsdatum` (bestimmt Periode und Geschäftsjahr; bei EÜR Zu-/Abflusstag), `belegnummer` (optional, Freitext), `text` (Pflicht), `herkunft` (`manuell`, `api`, `import`, `bank`) + `herkunft_ref` (optional), `storno_von` (optional, Verweis auf stornierte Buchung, eindeutig), `erfasst_am`, `erfasst_von`, `festgeschrieben_am`, `festgeschrieben_von`, `hash`, `vorgaenger_hash` (F13). Fachliche Zusatzfelder (z. B. Steuerschlüssel, Steuersatz, Leistungsdatum) sind **Erweiterungsattribute nach F58** und damit mitversiegelt; Kostenstellen u. Ä. in Tags (F8). |
| F27 | **Belegzuordnung n:m** in eigener Tabelle `buchung_beleg`: eine Buchung kann mehrere Belege haben (z. B. Rechnung + Zahlungsnachweis), ein Beleg mehrere Buchungen. Je Zuordnung der Belegverweis nach F10 (Paperless-ID, MD5 des Originals, SHA-256). Die Zuordnungen zum Zeitpunkt der Festschreibung sind Teil des Hashes. Danach dürfen Belege **nachgereicht, aber nie entfernt** werden: Nachreichen ist ein eigenes, verkettetes Ereignis „Beleg nachgereicht“ (Zeitpunkt, Nutzer, Belegverweis); die festgeschriebene Buchung und ihr Hash bleiben unverändert. Eine falsche Zuordnung wird über Storno und Neubuchung korrigiert. |
| F28 | **Abstand der Zeitstempel** (zu F14): Standard **15 Minuten**, je Buch einstellbar; gestempelt wird nur, wenn seit dem letzten Stempel neue Ereignisse vorliegen, und zusätzlich immer beim Periodenabschluss. |
| F29 | **Kanonische Form** für alle Hashes (Buchungen, Ereignisse, Siegel): **JSON Canonicalization Scheme nach RFC 8785**. Beträge und Kurse als Dezimal-Strings (wie in der API, F19), Zeitpunkte in UTC nach RFC 3339. So ist jeder Hash unabhängig von Programmiersprache und Datenbank nachrechenbar. |
| F30 | **Gebucht ist gebucht**: Festgeschriebene Buchungen sind endgültig; Korrektur nur über eine **Stornobuchung**. Sie spiegelt die Zeilen der Originalbuchung mit **getauschten Seiten** (kein negativer Betrag, F24), erhält eigene Nummern (F25) und `storno_von`; jede Buchung kann höchstens einmal storniert werden. Buchungsdatum standardmäßig der Storno-Tag, bei offenem Geschäftsjahr wahlweise das Originaldatum. |
| F31 | **Erfassungsstapel mit Freigabe, konfigurierbar** über Rechte von Nutzern, Rollen und API-Clients: **Erfasser** (z. B. Azubis, Bots, Importe) buchen in einen Stapel (`status = entwurf`); ein **verantwortlicher Buchhalter** sichtet und gibt einzeln oder stapelweise frei – die Freigabe ist die Festschreibung (`erfasst_von` = Erfasser, `festgeschrieben_von` = Freigebender, Vier-Augen-Nachweis). **Bucht der Buchhalter selbst, wird sofort festgeschrieben.** Erfasser dürfen Stornos nur als Entwurf vorschlagen. Entwürfe sind änderbar und löschbar; jede Änderung wird in der Protokollkette festgehalten (F13). |
| F32 | **Keine aufdringlichen Warnungen**: Hinweise (z. B. auf alte, nicht freigegebene Entwürfe) sind optional, **standardmäßig aus** und abschaltbar. Keine automatische Festschreibung. |
| F33 | **Sperre je Geschäftsjahr**: Mehrere Geschäftsjahre dürfen gleichzeitig offen sein. Der Jahresabschluss sperrt das Geschäftsjahr; danach keine Buchungen mit Buchungsdatum in diesem Jahr. Die Sperre ist ein Ereignis der Protokollkette und wird sofort gesiegelt und gestempelt (F14). Eine **Monatssperre** gibt es als Option (z. B. nach UStVA), **standardmäßig aus**. |
| F34 | **Mandanten: ein Buch je Datenbank.** Jeder Mandant hat eine eigene PostgreSQL-Datenbank mit genau einem Buch (eine Buchwährung, F11; alle Geschäftsjahre darin). Daraus folgt: eigene Hash-Kette, eigene Nummernkreise, eigene Sicherung, Aufbewahrung und Löschung je Mandant; keine Vermischung von Daten verschiedener Mandanten möglich. Das Feld `buch_id` (F26) entfällt im Kern. Mandantenübergreifende Funktionen (z. B. Übersicht für Steuerberater) nur über die API. |
| F35 | **Siegel-Export** (zu P2 d): Modul mit austauschbaren Zielen. **Ziel 1, immer aktiv: Paperless** – bei jedem Periodenabschluss ein Siegelprotokoll als PDF/A mit eingebettetem Zeitstempel-Token (.tsr) und PGP-Signatur. **Ziel 2, mindestens eines Pflicht, außer Haus**: z. B. E-Mail an Inhaber/Steuerberater, SFTP/rsync auf zweiten Rechner, WebDAV oder eigener S3-kompatibler Speicher, Git-Repository. Jedes Siegel geht sofort an die Ziele 2. Es verlassen nur Hash, Signatur und Zeitstempel-Token den Server (F15), keine Buchungsdaten. Fehlschlag → Status „Export ausstehend“, wird nachgeholt (wie F16). Zweck: Root kann Siegel nicht unbemerkt beseitigen. |
| F36 | **Wiederöffnen eines gesperrten Geschäftsjahres** (zu F33) nur über ein **Notfall-Ereignis**: Begründung, Bestätigung durch den Verantwortlichen, Ereignis in der Protokollkette, sofort gesiegelt und gestempelt. Die erneute Sperre ist ebenfalls ein Ereignis. Bereits festgeschriebene Buchungen bleiben unverändert (F30). |
| F37 | **Zentrale Verwaltungsdatenbank** (zu F34, präzisiert): enthält nur Mandantenliste, Verbindungsdaten und **wer Zugang zu welchem Mandanten hat** – **keine Buchungsdaten, keine Rollen**. Rollen und Rollenzuweisungen liegen **in der Mandanten-Datenbank** (F63), damit sie mitprotokolliert, mitgesichert und mit dem Mandanten gelöscht werden. Anmeldung weiter lokal oder über Keycloak/OIDC (T6). |
| F38 | **Zwei Ebenen der Signatur** (korrigiert): (1) **Systemschlüssel je Mandant** (Aufbau nach F23) siegelt die Ketten (F14); er liegt im Signierdienst und belegt nur, dass **das System** gesiegelt hat. (2) **Persönliche Freigabe durch den Nutzer selbst per WebAuthn/Passkey** (z. B. YubiKey, Plattform-Passkey): Der Browser lässt den Authentifikator des Nutzers einen **Freigabe-Hash** signieren – gebildet nach RFC 8785 (F29) aus den **angezeigten** Daten der Handlung (Buchungsinhalt ohne Kettenfelder, bei Stapelfreigabe die Liste der Freigabe-Hashes). Die Assertion (Credential-ID, authenticatorData, clientDataJSON, Signatur) wird zur Handlung gespeichert und geht in den Ketten-Hash ein. Der private Schlüssel verlässt nie das Gerät des Nutzers; ein Server-seitiger Nutzerschlüssel wird **nicht** verwendet. Gilt für Festschreibung/Freigabe (F31), Storno (F30), Sperre/Wiederöffnen (F33, F36), Notfall (F23). Öffentliche Schlüssel (Credentials) werden je Nutzer registriert; Registrierung und Widerruf sind Protokollereignisse; frühere Freigaben bleiben durch die Zeitstempel prüfbar. **API-Clients/Bots geben nie frei** (korrigiert durch F78): Sie erfassen nur Entwürfe (F31); freigegeben wird immer persönlich durch einen Menschen. Die Oberfläche muss vor der Freigabe genau die Daten anzeigen, die in den Freigabe-Hash eingehen. |
| F39 | **Auswahl des Mandanten über den Pfad**: `/m/{mandant}/api/v1/...`, in der Weboberfläche entsprechend. |
| F40 | **Anlegen eines Mandanten**: neue Datenbank aus einer Vorlage (`CREATE DATABASE … TEMPLATE`); Migrationen laufen je Datenbank, jede Datenbank führt ihre eigene Schema-Version. |
| F41 | **Rechtemodell RBAC** (Role-Based Access Control nach NIST / ANSI INCITS 359) mit attributbasierten Einschränkungen, in sieben Schichten: (1) **Rechtekatalog** – jedes Modul meldet seine Rechte selbst an als `modul.ressource.aktion` (z. B. `kern.buchung.festschreiben`, `bank.umsatz.zuordnen`); einheitliche Aktionen *sehen, anlegen, ändern, freigeben/festschreiben, stornieren/löschen*, dazu modulspezifische (z. B. *Geschäftsjahr sperren*, *Notfall*). (2) **Einschränkungen** – optionale Attribute je Recht: Buchungsart, Konto/Kontoart, weitere durch Module definierbar. (3) **Bausteine** – kleine, in sich konfliktfreie Rechtebündel (z. B. „Buchungen erfassen“, „Buchungen freigeben“). (4) **Rollen** – Bausteine plus Einschränkungen; als Vorlagen mitgeliefert, frei anpassbar. (5) **Zuweisung** – Nutzer oder API-Client ↔ Rolle **je Mandant**, optional befristet; mehrere Rollen werden addiert. (6) **Invarianten** – siehe F43. (7) **Nachweis** – Rechteänderungen und Prüferzugriffe sind Ereignisse der Protokollkette; ein Rechtebericht zeigt Konflikte der Funktionstrennung (z. B. „erfasst und gibt selbst frei“), ohne Pop-ups (F32). Grundsätze: **nur erlauben, nie verbieten** (Freigabeprinzip, keine expliziten Sperren); **Identität und Autorisierung getrennt** (Keycloak/lokal liefert nur die Identität, die Anwendung entscheidet je Mandant; Keycloak-Gruppen optional auf Rollen abbildbar); **Durchsetzung zentral an der API** (F18). Vorbilder: Sage (Modul × Aufgabe), SAP (Objekt × Aktivität, Organisationswerte, konfliktfreie Business Catalogs, SoD), DATEV (Freigabeprinzip, Prüferzugang), Lexware (Benutzergruppen). |
| F42 | **Mitgelieferte Rollenvorlagen**: **Erfasser** (Entwürfe anlegen/ändern/löschen, Storno vorschlagen, lesen), **Buchhalter** (zusätzlich festschreiben/freigeben, stornieren), **Verantwortlicher** (zusätzlich Geschäftsjahr sperren/öffnen, Notfall, Nummernkreise festlegen), **Steuerberater** (Buchhalter + Exporte, typischerweise extern über OIDC), **Leser** (Buchungen und Auswertungen lesen), **Prüfer** (nur lesen inkl. Protokoll und Siegel, Export für Datenträgerüberlassung, befristet, jeder Zugriff protokolliert), **Administrator** (Mandanten, Nutzer, Rollenzuweisung, Betrieb). Eine Einzelplatz-Installation legt einen Nutzer mit der Rolle Verantwortlicher an – kein „alle Rechte für alle“. |
| F43 | **Invarianten**, durch keine Rolle aufhebbar: Administrator liest und schreibt **keine Buchungsdaten** (wer beides braucht, erhält beide Rollen ausdrücklich); Prüfer ist **technisch nur lesend** und nicht erweiterbar; Notfall-Ereignisse nur durch den Verantwortlichen; Datenbankrechte nach F17. |
| F44 | **Regeln für Module** (korrigiert): Module sprechen mit dem Kern **nur über die Modul-API (PHP-Anwendungsdienste, F46/F53) und Ereignisse (F21)**, nie direkt mit Kern-Tabellen; REST (F18) ist der Zugang von außen. Eigene Daten in einem **eigenen PostgreSQL-Schema** in der Mandanten-Datenbank (F34). Jedes Modul hat ein **Manifest** (Name, Version, Abhängigkeiten, Rechte nach F41, Erweiterungsattribute nach F58, Erweiterungspunkte nach F46, ausgelöste/abonnierte Ereignisse, Migrationen). **Keine Abhängigkeitskreise.** Module sind ohne Datenverlust deaktivierbar; der **Kern ist technisch allein lauffähig** – welche Integrationen für den Produktivbetrieb Pflicht sind, regelt F59. Details: Anhang A, A1. |
| F45 | **Umfang des Kerns, eigenständige Dienste und Modulliste** wie in Anhang A, A2–A4: Kern = Konten (nur Struktur), Tags, Buchungen, Belegverweise, Nummerierung, Geschäftsjahre, Protokollkette/Siegel-Anbindung, Rechte, API/Outbox, Basisabfragen. Eigene Dienste: Signierdienst, Mandantenverwaltung. Module: `kontenrahmen`, `paperless`, `siegel-export`, `weboberflaeche`, `bank`, `kasse`, `opos`, `kurse`, `steuer`, `anlagen`, `euer`, `auswertungen`, `import`, `export-datev`, `export-pruefung`, `e-rechnung`, `elster`. |
| F46 | **Module im selben PHP-Prozess, Aufruf über Anwendungsdienste** (korrigiert): Module laufen als Composer-Pakete im selben Prozess und rufen die **PHP-Anwendungsdienste** des Kerns direkt auf – **kein internes HTTP**. Diese Dienste sind die stabile Modul-API (F53); REST (F18) ist nur ein Adapter darüber. **Transaktionsgrenze: ein Dienstaufruf = eine Datenbanktransaktion.** Module klinken sich auf zwei Arten ein: (1) **synchrone Erweiterungspunkte** in derselben Transaktion – **anreichern nur bei der Vorbereitung** (vor Anzeige und Freigabe, F66), **beim Festschreiben ausschließlich prüfen** (F66), **Beitrag zur Jahressperre** (`gj_sperren_beitrag`, F75) – schlägt ein Modul fehl, wird die ganze Transaktion zurückgerollt; (2) **asynchrone Ereignisse** nach dem Commit über die Outbox (F21). Ein späteres Auslagern eines Moduls als eigener Dienst bleibt über REST möglich. |
| F47 | **Endpunkte des Kerns** wie in Anhang A, Teil B, unter `/m/{mandant}/api/v1`. Klarstellung zu F20: Für **festgeschriebene** Buchungen gibt es nur Anlegen, Festschreiben, Stornieren, Lesen; Ändern und Löschen gilt ausschließlich für Entwürfe (F31). |
| F48 | **Protokoll und Ereignisse sind zweierlei** (korrigiert): (1) Das **interne Protokoll** (Protokollkette, F13) hält Änderungen **vollständig rekonstruierbar** fest: bei Entwürfen, Stammdaten, Nummernkreisen, Rechten, Konfiguration und Webhook-Abos den **Vorher- und Nachher-Stand**; bei festgeschriebenen Buchungen genügt der Verweis, da die Buchung selbst unveränderlich ist. Das Protokoll bleibt in der Mandanten-Datenbank. (2) **Externe Ereignisse/Webhooks** (Katalog in Anhang A, Teil C) enthalten **nur IDs und das Nötigste** (Typ, Zeitpunkt, Nutzer, betroffene IDs), **keine Buchungstexte oder Beträge** – Rücksicht auf Art. 9 DSGVO; Details holt der Empfänger über die API mit passendem Recht. Module definieren eigene Ereignisse mit ihrem Namen als Präfix. |
| F49 | **Paperless: Buchungsdaten in benutzerdefinierten Feldern.** Das Modul `paperless` legt in Paperless folgende Felder an und befüllt sie: `Buchungsstatus` (Auswahl: offen / gebucht / gebucht (mehrfach) / storniert; **kein** Status „Entwurf“ – Paperless wird erst bei Festschreibung beschrieben), `Buchungsnummer` (Text; bei mehreren Buchungen alle Nummern als Liste), `Journalnummer` (Ganzzahl), `Buchungsdatum` (Datum), `Belegdatum` (Datum), `Betrag` (Geldbetrag, Σ Soll in Buchwährung), `Fremdwährungsbetrag` (Geldbetrag mit ISO-Währung, nur bei Fremdwährung), `Kurs` (Text, Dezimal-String), `Soll-Konto` und `Haben-Konto` (Text, z. B. `4930 Bürobedarf`, mehrere kommagetrennt), `Buchungstext` (Text), `Buchungsart` (Text), `Buchungs-Hash` (Text), `Buchung` (Link zur Weboberfläche). **Mehrere Buchungen je Beleg** (F27): Die Felder zeigen die **zuletzt festgeschriebene, nicht stornierte** Buchung; `Buchungsnummer` listet alle; Status „gebucht (mehrfach)“. Zusätzlich **je Buchung eine Notiz** mit dem vollständigen Buchungssatz (alle Zeilen, Hash, Link) als Protokoll; bei Storno eine weitere Notiz „storniert durch Nr. …“, alte Notizen bleiben. Auslöser: Ereignisse `buchung.festgeschrieben`, `buchung.storniert`, `beleg.nachgereicht` (F48). |
| F50 | **Der Kern ist führend**, Paperless erhält eine Spiegelung. Änderungen an den Feldern in Paperless überschreibt das Modul beim nächsten Abgleich; ein Prüfbericht zeigt Abweichungen. |
| F51 | **Paperless → Buchhaltung**: (1) **Tags** werden in den Kern übernommen (F8), welche, ist konfigurierbar (Präfix oder Liste), damit private Ablage-Tags draußen bleiben. (2) **Eingangskorb**: Belege mit Status *offen* werden in der Buchhaltung angezeigt. (3) **Buchungsvorschlag** aus Korrespondent, Datum, Betrag bzw. strukturierten E-Rechnungsdaten – immer nur als **Entwurf** (F31). (4) **Verknüpfen** nur nach Prüfung: Original laden, SHA-256 berechnen, MD5 gegen `original_checksum` abgleichen (F10); bei Abweichung keine Verknüpfung. |
| F52 | **Lizenz: GNU Affero General Public License, Version 3 oder später** (SPDX: `AGPL-3.0-or-later`). Gilt für Kern, Dienste und mitgelieferte Module. Begründung: Wer eine veränderte Fassung über ein Netzwerk anbietet, muss deren Quellcode den Nutzern zugänglich machen – Schutz gegen geschlossene Cloud-Ableger. |
| F53 | **Framework nach dem Vorbild Nextcloud**: kein Komplett-Framework, sondern **einzelne Symfony-Komponenten** (8.x-Linie, z. B. HttpFoundation, Routing, Console, EventDispatcher, Translation, Messenger) plus **Doctrine DBAL** (kein ORM; handgeschriebenes SQL, PostgreSQL-Funktionen und Trigger nach F6/F17) plus eine **eigene, stabile öffentliche Modul-API**. Module programmieren nur gegen diese API, nie gegen Symfony- oder DBAL-Interna; Symfony-Upgrades treffen Module dadurch nicht. Architektur: modularer Monolith (F46). |
| F54 | **Mindestversionen**: **PHP 8.5**, **PostgreSQL 19**. Entwicklung startet auf PostgreSQL 19 RC2; produktiv erst ab GA (geplant 19.11.2026). Auf Debian 13 kommen beide aus zusätzlichen Paketquellen (PostgreSQL: offizielles PGDG-Repository; PHP: sury.org). |
| F55 | **nopaper** wird später angebunden, sobald es fertig ist (eigenes Modul oder Erweiterung von `paperless`). Bis dahin keine Abhängigkeit. |
| F56 | **Betriebsaufbau möglichst einfach, drei Teile**: (1) **PostgreSQL**, (2) **Signierdienst / Sicherheitsmodul (SSM)** als eigener Dienst (F15), (3) **Anwendung unter `/var/www/kumero`**. Wiederherstellung ist in F60 festgelegt; Paketierung und Backup-Werkzeuge werden **im laufenden Entwicklungszyklus mitgeschrieben**. |
| F57 | **Projektname: kumero** (umbenannt am 2026-11-14, vorher Arbeitsname „Aequa“; Bezug: Doppik für KMU). Schreibweise durchgehend klein `kumero` (Pfad `/var/www/kumero`, Systembenutzer, Datenbankrollen, Paket- und Repository-Namen). Vor der ersten Veröffentlichung: Markenrecherche bei DPMA und EUIPO in den Nizza-Klassen 9 und 42 sowie Prüfung von Domain, GitHub und Packagist. Eine erste Websuche (14.11.2026) fand keine Software dieses Namens, nur ähnlich klingende (z. B. Kumo, Kumospace, Kumulos) außerhalb der Buchhaltung. |
| F58 | **Erweiterungsattribute (mitversiegelt)**: Module melden im Manifest typisierte Zusatzfelder für Buchungskopf oder -zeile an, mit Namensraum des Moduls (z. B. `steuer.schluessel`, `steuer.satz`, `steuer.leistungsdatum`). Die Werte werden **mit der Buchung im Kern gespeichert, beim Festschreiben validiert (Erweiterungspunkt nach F46) und gehen in den Hash ein** (F13, F29). Werte, die sich auf veränderliche Stammdaten eines Moduls beziehen, werden als **Momentaufnahme** gespeichert (z. B. Steuerschlüssel *und* damals gültiger Satz), nicht nur als Verweis. Damit kann sich die steuerliche Bedeutung einer festgeschriebenen Buchung nachträglich nicht ändern. Nach Festschreibung unveränderlich wie die Buchung selbst. |
| F59 | **Betriebsarten**: (1) **Testbetrieb** – Kern läuft ohne Signierdienst, Zeitstempeldienst, externes Siegelziel und Paperless; Siegel bleiben „ausstehend“; deutlich als Testbetrieb gekennzeichnet. (2) **Produktivbetrieb** – lässt sich erst einschalten, wenn alle Voraussetzungen erfüllt und geprüft sind: Signierdienst mit Systemschlüssel (F23), Zeitstempeldienst konfiguriert, mindestens ein externes Siegelziel (F35), Paperless erreichbar (T8), mindestens ein Nutzer mit registriertem WebAuthn-Credential in der Rolle Verantwortlicher (F38). Das Einschalten ist ein Protokollereignis und nicht umkehrbar. **Ausfälle im Produktivbetrieb blockieren das Buchen nicht**: Siegel, Stempel und Exporte gehen in „ausstehend“ und werden nachgeholt (F16, F35); fehlende Belegprüfungen werden über „Beleg nachgereicht“ (F27) geschlossen. Eine Statusanzeige zeigt jederzeit, was aussteht. |
| F60 | **Wiederherstellung mit Pflichtprüfung**: Nach jedem Restore startet der Mandant im **Lesemodus** und durchläuft automatisch: (1) beide Ketten vollständig nachrechnen, (2) Kettenköpfe mit den **externen Siegelkopien** (F35) und deren Zeitstempeln abgleichen, (3) Belegprüfsummen gegen Paperless abgleichen (F10), (4) Schlüsselzustand prüfen (F23). Kennt ein externes Siegel ein Kettenglied, das in der Datenbank fehlt oder abweicht, ist ein **Zurücksetzen auf einen älteren Stand erkannt** – das wird angezeigt und protokolliert, die Lücke ist durch die zeitgestempelten Siegel außer Haus beweisbar. Erst nach bestandener Prüfung (oder ausdrücklicher Bestätigung der erkannten Lücke als Notfall-Ereignis durch den Verantwortlichen, F23/F38) wird der Mandant freigegeben. Der Restore selbst ist das Protokollereignis „wiederhergestellt“ mit Bezug auf das letzte bekannte externe Siegel. Wiederherstellung wird jährlich geübt (F23). |
| F61 | **Modul `anlagen`** (neu): Anlagenverzeichnis, Absetzung für Abnutzung, geringwertige Wirtschaftsgüter und Sammelposten; erzeugt AfA-Buchungen als Entwürfe (F31). Voraussetzung für korrekte EÜR (F9) und Bilanz. |
| F62 | **Entwürfe dürfen unvollständig sein** (z. B. noch nicht ausgeglichen, nur eine Zeile, Konto fehlt). Alle Regeln aus F4, F12, F24, F58 werden **beim Festschreiben** streng geprüft. |
| F63 | **Rollen und Zuweisungen liegen im Mandanten** (Mandanten-Datenbank), nicht in der Verwaltungsdatenbank (siehe F37). |
| F64 | **Schreiben nur über Datenbankfunktionen**: Die Datenbankrolle der Anwendung hat nur SELECT und EXECUTE auf freigegebene Funktionen – kein INSERT/UPDATE/DELETE auf Kern-Tabellen, auch nicht für Entwürfe. Jede Funktion schreibt ihren Protokolleintrag (F48) in derselben Transaktion; eine Änderung ohne Protokoll ist damit technisch ausgeschlossen. |
| F65 | **Geschäftsjahr konfigurierbar**: Beginn und Ende je Geschäftsjahr frei festlegbar (abweichendes Wirtschaftsjahr, Rumpfgeschäftsjahr); Zeiträume dürfen sich nicht überschneiden und müssen lückenlos aneinander anschließen. |
| F66 | **Vorbereiten, dann freigeben, dann nur noch prüfen**: Festschreiben verläuft in zwei Schritten. (1) **Vorbereiten**: Kernregeln prüfen, Module **anreichern** (Erweiterungswerte, F58) und prüfen; der Inhalt wird eingefroren (Status `freigabebereit`), der Freigabe-Hash gebildet. (2) **Festschreiben**: Inhalt erneut hashen und mit dem freigegebenen Hash vergleichen; Module dürfen **nur noch prüfen, nicht verändern**. Jede Änderung nach der Vorbereitung setzt die Buchung auf `entwurf` zurück und verlangt eine neue Freigabe. |
| F67 | **Stapelfreigabe vollständig gespeichert**: Zu jeder Freigabe wird die **geordnete Liste** der freigegebenen Buchungen mit Buchungs-ID und Freigabe-Hash gespeichert. Signiert wird der Hash über genau diese Liste (RFC 8785). So ist die Zugehörigkeit jeder Buchung später unabhängig nachprüfbar. |
| F68 | **WebAuthn-Prüfvertrag**: Die Challenge ist **nicht** der Buchungs-Hash, sondern SHA-256 über die kanonische Form (F29) von: **einmaliger Zufallswert** (≥ 32 Byte), **Ablaufzeit** (Standard 5 Minuten), **Mandant**, **Art der Handlung**, **Subjekt**, **Inhalts-Hash** (Freigabe- bzw. Stapel-Hash). Die Challenge-Daten werden serverseitig gespeichert und sind **nur einmal verwendbar**. Geprüft werden: Typ `webauthn.get`, Challenge, **Origin** gegen die erlaubten Origins, **RP-ID-Hash**, Flags **UP und UV** (Nutzerverifikation Pflicht), Signatur über authenticatorData ‖ SHA-256(clientDataJSON), Signaturzähler (sofern vom Authentifikator geführt), Credential gehört zum Subjekt und ist nicht widerrufen. Challenge-Daten und Assertion werden mit der Freigabe gespeichert, damit ein unabhängiges Prüfwerkzeug alles nachrechnen kann. WebAuthn signiert die Assertion mit Challengebindung – die Bindung an die Buchung entsteht über den Inhalts-Hash in der Challenge. |
| F69 | **Restore-Erkennung außerhalb der wiederhergestellten Datenbank**: Die Verwaltungsdatenbank (F37) führt je Mandant eine **Wasserstandsmarke** (höchstes gesiegeltes Glied je Kette mit Hash), nachgeführt bei jedem Siegel; die externen Siegelkopien (F35) bilden die zweite, unabhängige Marke. Bei jedem Öffnen eines Mandanten und periodisch vergleicht die Anwendung den Kettenkopf der Datenbank mit beiden Marken: Ist die Datenbank **hinter der Marke** oder weicht der Hash des markierten Glieds ab, wird **Lesemodus erzwungen** – unabhängig davon, was in der Datenbank steht – und die Restore-Prüfung nach F60 gestartet. Der reguläre Weg ist das Werkzeug `kumero restore`, das die Prüfung von sich aus anstößt; ein manuelles Einspielen wird über die Marken erkannt. |
| F70 | **Übergang Test → Produktiv** nur, wenn **Kette 1 leer ist** (keine festgeschriebenen Buchungen). Stammdaten dürfen bleiben. Tests mit Buchungen laufen in einem **eigenen Testmandanten**. |
| F71 | **Idempotenz und Sperrreihenfolge**: (1) Eine Tabelle **Idempotenz** speichert je Subjekt und Schlüssel: Endpunkt, Hash der Anfrage, Antwortstatus und -inhalt, Ablauf; gleicher Schlüssel mit anderem Anfrage-Hash → Fehler 409. (2) **Feste Sperrreihenfolge** für alle schreibenden Funktionen: Buch-Zustand → Geschäftsjahr → Nummernkreis-Jahr → Kettenkopf 1 → Kettenkopf 2. Festschreiben sperrt das Geschäftsjahr **teilend** (FOR SHARE) und prüft den Status unter dieser Sperre; die Jahressperre sperrt **exklusiv** (FOR UPDATE). Damit kann keine Buchung nach einer erfolgreichen Jahressperre durchrutschen. |
| F72 | **Wer erzwingt was** – PostgreSQL erzwingt selbst: Unveränderlichkeit, Zeilen- und Saldenregeln (F4, F12, F24), Geschäftsjahr offen und Zeitraum, lückenlose Nummern, Hash-Bildung und Ketten, Freigabe-Bindung (Inhalts-Hash neu berechnet = freigegeben; Challenge einmalig und nicht abgelaufen; Credential gehört zum Subjekt, nicht widerrufen), Pflicht-Erweiterungswerte vorhanden und typgerecht (Registry in der DB), Lesemodus, Betriebsart, Protokollpflicht, **Prüfvermerke**: Für jedes aktive Modul mit Erweiterungspunkt „Festschreiben prüfen“ muss ein Prüfvermerk (Modul, Version, Ergebnis) vorliegen, sonst lehnt die Datenbank ab. Die **Anwendung** verantwortet: Authentisierung, RBAC-Entscheidung (F41), kryptografische Prüfung der WebAuthn-Signatur (F68) und die fachliche Logik der Modulprüfungen. Was die Anwendung verantwortet, wird so gespeichert, dass ein unabhängiges Prüfwerkzeug es nachrechnen kann (Assertion, Challenge-Daten, Prüfvermerke). |
| F73 | **Änderungen am installierten Kern nur mit Genehmigung des Projektverantwortlichen.** Versionen nach SemVer (`VERSION`, `CHANGELOG.md`, Git-Tag `v<version>`). Ein Release (`werkzeuge/release-bauen.sh`) ist ein Archiv aus genau diesem Tag plus Prüfsumme; **genehmigt ist es erst, wenn der Projektverantwortliche die Prüfsumme mit seinem OpenPGP-Schlüssel signiert**. Installiert wird nur über `install/aktualisieren.sh` (nach der Prüfung übernimmt das Skript aus dem geprüften Release den weiteren Ablauf): Signatur gegen die auf dem Server hinterlegten Freigabe-Schlüssel (`/etc/kumero/freigabe-schluessel.gpg`), Prüfsumme, kein Rückschritt, Anzeige der anstehenden Änderungen, Bestätigung, dann Einspielen. `db/migrieren.sh` verweigert neue Migrationen ohne diese Freigabe (Ausnahme: Testdatenbanken `kumero_test*`). Jede Installation wird je Datenbank in `meta.version` mit Version, Archiv-Prüfsumme und Fingerabdruck des genehmigenden Schlüssels vermerkt. |
| F74 | **Festlegungen aus der Umsetzung (bestätigt 2026-11-14):** (1) Bebuchte Konten: Nummer und Kontoart fest, Bezeichnung änderbar und nicht Teil des Hashes; analog Name/Kürzel bei Tag, Buchungsart, Nummernkreis. (2) Eigene Fehlerklasse `KM001`–`KM005` (Unveränderlichkeit, Buchungsregel, Zustand, kanonische Form, Konflikt). (3) Ein PostgreSQL-Superuser kann Schutz-Trigger abschalten; das ist nicht verhinderbar, aber über Ketten und externe Siegel nachweisbar (`kern.kette_pruefen()`). (4) Nummernvorlage: `{JJJJ}`/`{JJ}` = Jahr des Geschäftsjahresbeginns, `{MM}` = Monat des Buchungsdatums, `{NR:n}` = Zahl mit mindestens n Stellen. |
| F75 | **Modul `kontenrahmen`**: (1) **Alle Kontenrahmen nutzbar**: SKR 03, 04, 14, 42, 45, 49, 51, 70, 80, 81, 99 usw., IKR, GKR und eigene. (2) **Konverter statt mitgelieferter Fremdlisten**: Das Modul enthält Konverter aus den offiziellen Quellen – DATEV-Kontenrahmen-PDF (kostenlos bei DATEV) und DATEV-Format „Kontenbeschriftungen“ (Export aus einem bestehenden DATEV-Bestand, samt individueller Konten) – sowie aus CSV (Nummer, Bezeichnung, Kontoart). **Jeder Nutzer entscheidet selbst**, welche Quelle er konvertiert und einspielt; das Projekt liefert keine konvertierten DATEV-Listen aus. (3) **Gefüllte Referenz im Repository**: ein eigener, vollständig gefüllter Referenz-Kontenrahmen im Vorlagenformat – mit Kontoarten und Zuordnungen zu Anlage EÜR und E-Bilanz –, der das Format verbindlich zeigt, als Testgrundlage dient und kumero ohne Fremddaten startfähig macht. (4) Ein konvertierter oder eingelesener Rahmen wird als **Vorlage im eigenen Datenformat** gespeichert und steht allen Mandanten zur Auswahl. (5) **Vorlage, keine laufende Verbindung**: Beim Anlegen eines Mandanten werden die Konten aus der gewählten Vorlage angelegt; danach gehören sie dem Mandanten (F2, F34). Spätere Änderungen einer Vorlage werden nur **angeboten**, nie automatisch übernommen; bebuchte Konten bleiben fest (F74). (6) Am Mandanten vermerkt: **angewendeter Rahmen, Fassung und Quelle** (z. B. „SKR 03, Stand 2026, konvertiert aus DATEV-PDF“). (7) **Zuordnung je Geschäftsjahr** jedes Kontos zu den **amtlichen Zielen** – Zeilen der Anlage EÜR und E-Bilanz-Taxonomie der Finanzverwaltung –, Grundlage für die Module `euer` und `auswertungen`. Das Modul schlägt die Zuordnung anhand der Kontenbereiche vor; der Verantwortliche bestätigt sie. Ablage im Schema des Moduls in der Mandanten-Datenbank (F44). (8) **Wechsel des Rahmens** nur zum Geschäftsjahreswechsel: neue Konten, Salden umbuchen, altes Jahr unverändert. (9) Echte Kontenpläne von Mandanten bleiben **lokal** und kommen nie ins Repository, in Tests oder Chats. (10) Rechtliche Einordnung (ohne Gewähr): Einzelne Konten haben keine Schöpfungshöhe; gegen einen Datenbankschutz nach §§ 87a ff. UrhG spricht, dass DATEV die Konten selbst erzeugt und nicht beschafft. Weil die Konvertierung beim Nutzer liegt, trägt das Projekt kein Verbreitungsrisiko. (11) **Zuordnung mit der Jahressperre eingefroren (GoBD)**: Mit der Sperre eines Geschäftsjahres (F33) wird dessen Zuordnung zu Anlage EÜR und E-Bilanz unveränderlich und als Hash Teil des freigegebenen und gesiegelten Sperrinhalts – umgesetzt allgemein über den Erweiterungspunkt `gj_sperren_beitrag` (Kern 0.3.0): Module liefern vor der Sperre den Hash ihres Datenstands (`kern.gj_beitrag_setzen`), der Kern nimmt alle Beiträge in `kern.gj_sperr_inhalt` auf, verweigert die Sperre bei fehlendem Beitrag und hält die Beiträge danach unveränderlich; das Modul sperrt seine eigenen Daten des Jahres anhand des Jahresstatus. Vorher wird jede Änderung der Zuordnung mit Vorher-/Nachher-Stand protokolliert (F48). So ergibt eine abgeschlossene Buchhaltung später immer dieselbe EÜR bzw. Bilanz. Eine Wiederöffnung (F36) gibt auch die Zuordnung wieder frei. (12) **Kontenplan in Verfahrensdokumentation und Datenüberlassung**: Der verwendete Kontenplan samt Rahmen, Fassung, Quelle und Zuordnungen je Geschäftsjahr wird in die Verfahrensdokumentation übernommen (Projektschritt 11) und bei der Datenträgerüberlassung an die Betriebsprüfung mitgeliefert (Modul `export-pruefung`). |
| F76 | **Ohne Steuerberater vollständig** und **erster Kontenrahmen SKR 81** (2026-11-14): (1) Der Referenzbetrieb bucht selbst; ein Steuerberater ist **nicht** beteiligt. kumero muss den ganzen Weg vom Beleg über Buchung und Jahressperre bis zur Anlage EÜR und zur Übermittlung über ELSTER (L4, Modul `elster`) **ohne Dritte** abdecken. Rolle Steuerberater (F42) und DATEV-Export bleiben optionale Angebote für andere Betreiber; kein Arbeitsablauf setzt sie voraus. (2) **Erster Kontenrahmen ist SKR 81** (DATEV, Arztpraxen). DATEV hat ihn zum 31.12.2016 abgekündigt und pflegt ihn nicht mehr. Daraus folgt: Die **Zuordnung der SKR-81-Konten zu den Zeilen der Anlage EÜR pflegt kumero selbst**, je Geschäftsjahr und versioniert im Modul `kontenrahmen` (F75 Nr. 7 und 11); neue Vordrucke der Anlage EÜR werden dort nachgezogen, nicht bei DATEV. (3) Reihenfolge im Modul `kontenrahmen`: zuerst der Weg für SKR 81 (Einlesen des eigenen Kontenplans aus CSV oder DATEV-Kontenbeschriftungen, Zuordnung zur Anlage EÜR), danach SKR 03/04 für andere Betreiber. Die Architektur bleibt dieselbe. (4) Der echte Kontenplan des Referenzbetriebs bleibt lokal (F75 Nr. 9); Referenz und Tests nutzen synthetische Konten im Aufbau des SKR 81. |
| F77 | **Anwendung nach ANWENDUNG.md, Fassung 1** (freigegeben 2026-11-14): Aufbau, Weg einer Anfrage, Anmeldung, Verwaltungsdatenbank, Modul-API `Kumero\Api\V1`, Konfiguration und Auslieferung wie dort beschrieben. Entscheidungen: (E1) Abhängigkeiten (`vendor/`) gehören zum signierten Release; (E2) Anmeldung zunächst nur per Passkey, OIDC später; (E3) eigene, kleine WebAuthn-Prüfung für ES256 und EdDSA; (E4) Subjekte `nutzer:`, `dienst:`, `system:` mit UUID bzw. Name; (E5) Modul-Manifest deklarativ als `modul.json`; (E6) Konfiguration `/etc/kumero/kumero.php` ohne Geheimnisse; (E7) `/var/www/kumero` ist ein Symlink auf das aktive Release, umgeschaltet nach erfolgreicher Migration; (E8) Reihenfolge: Kern 0.4.0 → OpenAPI → Gerüst und REST → Modul `kontenrahmen` (SKR 81) → erste Oberfläche, jeder Schritt als eigenes Release. Ergänzung zu F74 (2): Fehlercode **`KM006`** = Idempotenzschlüssel für eine andere Anfrage verwendet (HTTP 409). Eine mit QES signierte Erklärung zum Freigabeschlüssel wurde erwogen und verworfen; der Schutz des privaten Schlüssels durch Passphrase und Ablage genügt. |
| F78 | **REST-API v1 nach `openapi/v1.yaml`** (Entwurf 1, Entscheidungen 2026-11-17): (O1) **Freigabe immer in drei Schritten** – vorbereiten, `POST /freigaben/challenge` (liefert den anzuzeigenden Inhalt, F38), ausführen mit Assertion; einheitlich für Buchungen, Stapel, Jahressperre und Wiederöffnen. `…/festschreiben` und `festschreiben=true` entfallen, stattdessen `vorbereiten: true` beim Anlegen. **Im Testbetrieb** (F59) darf die Assertion fehlen, damit die Entwicklung schnell bleibt; **im Produktivbetrieb ist sie Pflicht** – das erzwingt die Datenbank je Mandant, nicht eine Einstellung. (O2) **Storno legt nur einen Entwurf an**; festgeschrieben wird er über denselben Freigabeweg mit dem Recht `kern.buchung.stornieren`. (O3) **Bots und Dienstkonten geben nie frei**, sie erfassen nur Entwürfe; F38 ist entsprechend korrigiert. |

## 5. Offene Punkte (in dieser Reihenfolge zu definieren)

| # | Punkt | Stand |
|---|---|---|
| P1 | **Was ist eine Buchung im Kern?** Entschieden: F4–F12, F24–F27 (Zeilenregeln, Konto als Pflichtklassifizierung, Tags, Belegverweis, Fremdwährung, Betragsdarstellung, Nummerierung, Kopffelder, Belegzuordnung). Steuer und Kontenrahmen bleiben außerhalb des Kerns. | **entschieden** |
| P2 | **Siegel**: Entschieden F13–F17, F22–F23, F28–F29, F35. | **entschieden** |
| P3 | **Erfassung und Festschreibung**: Entschieden F30–F33, F36. | **entschieden** |
| P4 | **Mandanten**: Entschieden F34, F37–F40. | **entschieden** |
| P5 | **Rollen und Rechte**: Entschieden F41–F43 (RBAC). | **entschieden** |
| P6 | **Modulschnitt und Schnittstellen**: Entschieden F18–F21, F44–F48, Anhang A. | **entschieden** |
| P7 | **Paperless-Anbindung**: Entschieden F49–F51, F55. | **entschieden** |
| P8 | **Versionen und Betrieb**: Entschieden F53, F54, F56; Details werden im laufenden Zyklus mitgeschrieben. | **entschieden** |
| P9 | **Projektname und Lizenz**: Entschieden F52 (AGPL-3.0-or-later), F57 (kumero). | **entschieden** |

## 6. Projektschritte (Vorschlag)

| Schritt | Inhalt | Voraussetzung |
|---|---|---|
| 0 | **Definition**: offene Punkte P1–P9 entscheiden, diese Datei fortschreiben. | – |
| 1 | **Buchungskern**: Datenmodell, Journal (nur anhängen), Hash-Kette, Storno, Festschreibung, Periodensperre, Protokoll, Siegel, Prüffunktion. | P1–P4, P6, P8 |
| 2 | **Nutzerverwaltung**: lokal und über OIDC (z. B. Keycloak); Rollen und Rechte. | P5 |
| 3 | **Stammdaten**: Konten, Modul `kontenrahmen` mit Konvertern für alle Kontenrahmen und gefülltem Referenzrahmen (F75), Zuordnung zu Anlage EÜR und E-Bilanz je Geschäftsjahr. | 1 |
| 4 | **Paperless-Anbindung**: Belegverweis, Übernahme von Tags, Rückschreiben der Buchungsdaten. | 1, P7 |
| 5 | **Weboberfläche** (mehrsprachig): Erfassen, Journal, Kontoblatt, Salden. | 1–3 |
| 6 | **Bank**: CAMT-Import, FinTS-Abruf, Abgleich mit Belegen. | 1, 4 |
| 7 | **Steuern**: Umsatzsteuer-Logik als Modul, UStVA. | 1, 3 |
| 8 | **Auswertungen**: Summen- und Saldenliste, EÜR, Bilanz/GuV. | 1, 3 |
| 9 | **Exporte**: DATEV, Datenzugriff für die Betriebsprüfung, Gesamtexport. | 1, 3 |
| 10 | **ELSTER** über ERiC (vom Betreiber bezogen). | 7, 8 |
| 11 | **Verfahrensdokumentation** (Vorlage). | laufend |

## Anhang A – Modulschnitt, Endpunkte, Ereignisse (zu F44–F48)

### Teil A. Modulschnitt

### A1. Regeln für alle Module

1. **Module sprechen mit dem Kern nur über die Modul-API (PHP-Anwendungsdienste, F46) und Ereignisse (F21).** Kein Modul liest oder schreibt Tabellen des Kerns direkt. REST (F18) ist der Zugang von außen.
2. **Eigene Daten** (z. B. Bankumsätze) legt ein Modul in einem **eigenen PostgreSQL-Schema** in der Datenbank des Mandanten ab (F34). Damit gelten Sicherung und Löschung je Mandant automatisch auch für die Daten des Moduls.
3. Jedes Modul hat ein **Manifest** mit:
   - Name und Version
   - Abhängigkeiten
   - Rechten (F41)
   - Ereignissen, die es auslöst oder abonniert
   - Migrationen
4. **Keine Kreise in den Abhängigkeiten.** Ein Modul darf nur von Modulen abhängen, die näher am Kern liegen.
5. Ein Modul kann **ohne Datenverlust deaktiviert** werden. Der Kern funktioniert immer allein.

### A2. Der Kern (Projektschritt 1)

| Baustein | Inhalt | Bezug |
|---|---|---|
| Konten | Nummer, Name, Kontoart, aktiv/inaktiv – nur die Struktur, keine Kontenrahmen | F2, F5, F7 |
| Tags | freie Dimensionen | F8 |
| Buchungen | Kopf, Zeilen, Fremdwährungsfelder, Entwürfe, Festschreibung, Storno | F4, F11, F12, F24–F27, F30, F31 |
| Belegverweise | Tabelle `buchung_beleg`, nur Verweise (Paperless-ID, MD5, SHA-256) | F10, F27 |
| Nummerierung | Buchungsarten, Nummernkreise, `journal_nr` | F25 |
| Geschäftsjahre | Anlegen, Sperren, Wiederöffnen per Notfall | F33, F36 |
| Protokollkette und Siegel | Hash-Kette, Anbindung an die Signier-API und den Zeitstempeldienst | F13–F17, F22, F28, F29 |
| Rechte | RBAC-Maschine, Rechtekatalog, Rollen, Invarianten | F41–F43 |
| API und Outbox | REST, OpenAPI, Webhooks | F18–F21 |
| Basisabfragen | Kontoblatt, Salden zum Stichtag, Journal | F5 |

### A3. Eigenständige Dienste (getrennte Prozesse)

| Dienst | Aufgabe | Bezug |
|---|---|---|
| Signierdienst | Sicherheitsmodul mit eigener API, hält System- und Nutzerschlüssel | F15, F22, F23, F38 |
| Mandantenverwaltung | zentrale Verwaltungsdatenbank, legt Mandanten an | F37, F40 |

### A4. Module (in Projektschritt-Reihenfolge)

| Modul | Aufgabe | braucht |
|---|---|---|
| `kontenrahmen` | Konverter (DATEV-PDF, DATEV-Kontenbeschriftungen, CSV) für alle Kontenrahmen, gefüllter Referenzrahmen, Zuordnung zu Anlage EÜR und E-Bilanz je Geschäftsjahr (F75) | Kern |
| `paperless` | Belege verknüpfen, Tags übernehmen, Buchungsdaten zurückschreiben (T8) | Kern |
| `siegel-export` | Ziele für die Siegelkopien (F35) | Kern |
| `weboberflaeche` | Bedienoberfläche, nutzt nur die API | Kern |
| `bank` | FinTS, CAMT, Zuordnung von Umsätzen | Kern, optional `paperless` |
| `kasse` | Kassenbuch | Kern |
| `opos` | Debitoren, Kreditoren, offene Posten | Kern |
| `kurse` | Kursbeschaffung (EZB, BMF-Monatskurse), Kursdifferenzen | Kern |
| `steuer` | Steuerschlüssel, automatische USt/VSt-Zeilen, UStVA-Werte | Kern, `kontenrahmen` |
| `anlagen` | Anlagenverzeichnis, AfA, GWG, Sammelposten (F61) | Kern, `kontenrahmen` |
| `euer` | EÜR nach eigenem Regelwerk, Anlage EÜR (F9) | Kern, `anlagen`, `steuer` |
| `auswertungen` | SuSa, Bilanz/GuV, BWA | Kern, optional `steuer`, `anlagen` |
| `import` | QIF, CSV, DATEV-Buchungsstapel | Kern |
| `export-datev` | DATEV-Format | Kern |
| `export-pruefung` | Datenträgerüberlassung für die Betriebsprüfung | Kern |
| `e-rechnung` | XRechnung und ZUGFeRD einlesen | Kern, `paperless` |
| `elster` | Übermittlung über ERiC (vom Betreiber selbst bezogen) | `steuer`, `auswertungen` |

**A5:** Module laufen im selben PHP-Prozess als Composer-Pakete; Vertrag bleibt die API (F46).

---

### Teil B. Endpunkte des Kerns

Alle Endpunkte liegen unter `/m/{mandant}/api/v1` (F39). Grundregeln:
- Schreibende Aufrufe brauchen einen `Idempotency-Key`; Änderungen erfolgen mit `If-Match` (F19).
- Jeder Endpunkt ist mit einem Recht aus F41 verknüpft.

### B1. Stammdaten

| Methode | Pfad | Zweck |
|---|---|---|
| GET / POST | `/konten` | Konten auflisten oder anlegen |
| GET / PATCH | `/konten/{nr}` | lesen oder ändern (Name, aktiv; Kontoart nur, solange das Konto nicht bebucht ist) |
| GET / POST | `/tags` | Tags auflisten oder anlegen |
| GET / POST | `/buchungsarten` | Buchungsarten |
| GET / POST | `/nummernkreise` | Nummernkreise |
| PUT | `/nummernkreise/{id}/jahre/{jahr}` | Vorlage und Regel zum Zurücksetzen festlegen; danach unveränderlich (F25) |

### B2. Geschäftsjahre

| Methode | Pfad | Zweck |
|---|---|---|
| GET / POST | `/geschaeftsjahre` | auflisten oder anlegen |
| POST | `/geschaeftsjahre/{jahr}/sperren` | Jahresabschluss (F33) |
| POST | `/geschaeftsjahre/{jahr}/oeffnen` | nur als Notfall mit Begründung (F36) |

### B3. Buchungen

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/buchungen` | Journal mit Filtern und Cursor-Paging |
| POST | `/buchungen` | Entwurf anlegen; mit `vorbereiten: true` in derselben Transaktion vorbereitet (F66, F78) |
| GET | `/buchungen/{id}` | lesen |
| PUT / DELETE | `/buchungen/{id}` | **nur Entwürfe** ändern oder löschen (F31); die Protokollkette hält es fest |
| POST | `/buchungen/{id}/vorbereiten`, `/zuruecksetzen` | Inhalt einfrieren bzw. zurück zum Entwurf (F66) |
| POST | `/freigaben/challenge` | Challenge mit anzuzeigendem Inhalt für Festschreibung, Jahressperre, Wiederöffnen (F68, F78) |
| POST | `/freigaben` | eine oder mehrere Buchungen freigeben und festschreiben (Stapel mit einem oder mehr Einträgen, F67) |
| POST | `/buchungen/{id}/storno` | Storno-Entwurf anlegen (F30); festgeschrieben über `/freigaben` mit `kern.buchung.stornieren` (F78) |
| POST | `/buchungen/{id}/belege` | Beleg nachreichen (F27) |

### B4. Abfragen

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/konten/{nr}/kontoblatt?von=&bis=` | Kontoblatt |
| GET | `/salden?stichtag=` | Salden aller Konten |

### B5. Nachweis

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/protokoll` | Protokollkette |
| GET | `/siegel`, `/siegel/{id}` | Siegel mit Signatur und Zeitstempel-Token |
| POST | `/pruefung/kette` | Kette und Siegel vollständig prüfen (asynchron, mit Ergebnisbericht) |

### B6. Rechte, Integration, Sonstiges

| Methode | Pfad | Zweck |
|---|---|---|
| GET / POST | `/rollen` | Rollen auflisten oder anlegen (F41, F42) |
| GET / POST / DELETE | `/zuweisungen` | Rollen zuweisen oder entziehen |
| GET | `/rechtebericht` | Konflikte bei der Funktionstrennung |
| GET / POST / DELETE | `/webhooks` | Abonnements für Ereignisse (F21) |
| POST / GET | `/exporte`, `/exporte/{id}` | asynchrone Exporte (F19) |
| GET | `/openapi.json` | Beschreibung der Schnittstelle |

Hinweis zu F20: Für **festgeschriebene** Buchungen gibt es weiterhin nur Anlegen, Festschreiben, Stornieren und Lesen. Ändern und Löschen gelten ausschließlich für Entwürfe, und Entwürfe sind noch keine Buchungen.

---

### Teil C. Ereigniskatalog

**Grundsatz für Art. 9 DSGVO:** Ein externes Ereignis (Webhook) enthält **nur IDs und das Nötigste** (Typ, Zeitpunkt, Nutzer, betroffene IDs), **keine Buchungstexte oder Beträge**. Wer mehr wissen will, holt es über die API und braucht dafür das passende Recht. So landen keine sensiblen Daten in Webhook-Warteschlangen oder Logs.

Alle Ereignisse des Kerns stehen zugleich in der Protokollkette (F13) – dort jedoch mit vollständigem Vorher-/Nachher-Stand (F48).

| Bereich | Ereignisse |
|---|---|
| Buchungen | `buchung.entwurf_angelegt`, `buchung.entwurf_geaendert`, `buchung.entwurf_geloescht`, `buchung.festgeschrieben`, `buchung.storniert`, `beleg.nachgereicht` |
| Stammdaten | `konto.angelegt`, `konto.geaendert`, `tag.angelegt`, `nummernkreis.festgelegt` |
| Geschäftsjahre | `geschaeftsjahr.angelegt`, `geschaeftsjahr.gesperrt`, `geschaeftsjahr.geoeffnet` |
| Siegel | `siegel.erstellt`, `siegel.stempel_ausstehend`, `siegel.nachgeholt`, `siegel.exportiert`, `siegel.export_ausstehend` |
| Schlüssel | `schluessel.bestaetigt`, `schluessel.widerrufen`, `schluessel.wiederhergestellt` |
| Notfall | `notfall.erfasst` |
| Freigabe-Credentials | `credential.registriert`, `credential.widerrufen` |
| Betrieb | `betrieb.produktiv_eingeschaltet`, `betrieb.wiederhergestellt`, `betrieb.ruecksetzung_erkannt` |
| Rechte | `rolle.geaendert`, `zuweisung.geaendert`, `pruefer.zugriff` |

Module dürfen eigene Ereignisse definieren, mit ihrem Namen als Präfix, z. B. `bank.umsatz_importiert`.
