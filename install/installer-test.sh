set -euo pipefail
# SPDX-License-Identifier: AGPL-3.0-or-later
# Prüft die Teile von installer.sh, die ohne Debian 13 und ohne root laufen: Hilfen, install.conf setzen,
# Schlüssel- und Signaturprüfung (mit Wegwerf-Schlüsseln), Dateien für Paperless (docker compose config, falls vorhanden).
# Aufruf:  bash install/installer-test.sh
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT; ok=0; f=0
pruefe() { if eval "$2"; then ok=$((ok+1)); else f=$((f+1)); echo "FEHLT: $1"; fi; }
export KUMERO_INSTALLER_NUR_FUNKTIONEN=1 KUMERO_PAPERLESS_ETC=$T/etc KUMERO_PAPERLESS_DATEN=$T/daten \
  KUMERO_PAPERLESS_BEFEHL=$T/kumero-paperless KUMERO_PAPERLESS_EINHEITEN=$T/einheiten KUMERO_ANTWORTEN=$T/installer.conf KUMERO_ABLAGE=$T/ablage
mkdir -p $T/einheiten $T/ablage
source "$(dirname "${BASH_SOURCE[0]}")/installer.sh"
set +e
# Hilfen
pruefe "hoechste" '[[ $(hoechste 0.7.9 0.7.73) == 0.7.73 && $(hoechste 3.4.2 3.0) == 3.4.2 && $(hoechste 2.14 3.0) == 3.0 ]]'
pruefe "hostname" 'hostname_gueltig kumero.example.org && ! hostname_gueltig kumero && ! hostname_gueltig "a b.de" && ! hostname_gueltig "x;rm.de"'
pruefe "kennung" 'kennung_gueltig praxis && ! kennung_gueltig Praxis && ! kennung_gueltig p'
# conf_setzen an der echten Vorlage
cp "$(dirname "${BASH_SOURCE[0]}")"/vorlagen/install.conf $T/install.conf
conf_setzen $T/install.conf ENTWICKLUNG_TUNNEL ja
conf_setzen $T/install.conf FREIGABE_SCHLUESSEL /root/freigabe.asc
conf_setzen $T/install.conf SERVERNAME kumero.example.org
cp $T/install.conf $T/install2.conf; conf_setzen $T/install2.conf NEUES 'a&b|c\d'
pruefe "conf: Tunnel einmal" '[[ $(grep -c "^ENTWICKLUNG_TUNNEL=" $T/install.conf) == 1 ]] && grep -qx "ENTWICKLUNG_TUNNEL=ja" $T/install.conf'
pruefe "conf: Schlüssel aus Kommentar" 'grep -qx "FREIGABE_SCHLUESSEL=/root/freigabe.asc" $T/install.conf && ! grep -q "^#FREIGABE_SCHLUESSEL" $T/install.conf'
pruefe "conf: Servername" 'grep -qx "SERVERNAME=kumero.example.org" $T/install.conf'
pruefe "conf: Sonderzeichen unverändert" "grep -qxF 'NEUES=a&b|c\\d' $T/install2.conf"
pruefe "conf: Rest bleibt" 'grep -qx "PG_VERSION=19" $T/install.conf && ( source $T/install.conf; [[ $PHP_VERSION == 8.5 ]] )'
# Schlüssel und Signatur mit einem Wegwerf-Schlüssel
export GNUPGHOME=$T/gnupg; mkdir -m 700 $GNUPGHOME
gpg --batch --quiet --pinentry-mode loopback --passphrase '' --quick-generate-key 'Test Freigabe <test@example.org>' ed25519 sign never 2>/dev/null
gpg --armor --export > $T/freigabe.asc; gpg --batch --pinentry-mode loopback --passphrase '' --armor --export-secret-keys > $T/privat.asc 2>/dev/null
pruefe "öffentlicher Schlüssel angenommen" '( schluessel_pruefen $T/freigabe.asc ) 2>/dev/null'
pruefe "privater Schlüssel abgelehnt" '! ( schluessel_pruefen $T/privat.asc ) 2>/dev/null'
pruefe "fehlende Datei abgelehnt" '! ( schluessel_pruefen $T/nichts.asc ) 2>/dev/null'
pruefe "Pfad mit Leerzeichen abgelehnt" '! ( schluessel_pruefen "/tmp/a b.asc" ) 2>/dev/null'
mkdir -p $T/bau/kumero-9.9.9/install; echo 'echo aktualisiert' > $T/bau/kumero-9.9.9/install/aktualisieren.sh
tar -czf $T/ablage/kumero-9.9.9.tar.gz -C $T/bau kumero-9.9.9; echo alt > $T/ablage/kumero-9.9.10.txt
( cd $T/ablage && sha256sum kumero-9.9.9.tar.gz > kumero-9.9.9.sha256 )
pruefe "ohne Signatur abgelehnt" '! ( release_pruefen $T/ablage/kumero-9.9.9.tar.gz $T/freigabe.asc ) >/dev/null 2>&1'
( cd $T/ablage && gpg --batch --pinentry-mode loopback --passphrase '' --armor --detach-sign kumero-9.9.9.sha256 2>/dev/null )
pruefe "signiert angenommen" '( release_pruefen $T/ablage/kumero-9.9.9.tar.gz $T/freigabe.asc ) >/dev/null 2>&1'
pruefe "release_suchen" 'release_suchen && [[ $NEU == 9.9.9 && $ARCHIV == $T/ablage/kumero-9.9.9.tar.gz ]]'
# fremder Schlüssel
GNUPGHOME=$T/gnupg2; mkdir -m 700 $GNUPGHOME; gpg --batch --quiet --pinentry-mode loopback --passphrase '' --quick-generate-key 'Fremd <f@example.org>' ed25519 sign never 2>/dev/null
gpg --armor --export > $T/fremd.asc
pruefe "fremder Schlüssel abgelehnt" '! ( release_pruefen $T/ablage/kumero-9.9.9.tar.gz $T/fremd.asc ) >/dev/null 2>&1'
echo x >> $T/ablage/kumero-9.9.9.tar.gz
pruefe "verändertes Archiv abgelehnt" '! ( release_pruefen $T/ablage/kumero-9.9.9.tar.gz $T/freigabe.asc ) >/dev/null 2>&1'
# Der einfache Weg (F171): Schlüssel hier erzeugen, selbst freigeben
export GNUPGHOME=$T/gnupg3; mkdir -m 700 $GNUPGHOME
A_SCHLUESSEL=$T/selbst.asc; A_ERZEUGEN=ja; export KUMERO_SELBST=$T/selbst-freigegeben; SELBST=$T/selbst-freigegeben
( schluessel_erzeugen ) >/dev/null 2>&1
pruefe "erzeugt: öffentlicher Teil liegt da" '[[ -f $A_SCHLUESSEL ]] && ! grep -q "PRIVATE KEY" $A_SCHLUESSEL'
pruefe "erzeugt: selbst freigegeben erkannt" 'selbst_freigegeben'
pruefe "fremde Datei: nicht selbst freigegeben" '! ( A_SCHLUESSEL=$T/fremd.asc; selbst_freigegeben )'
( schluessel_erzeugen ) >/dev/null 2>&1
pruefe "zweiter Lauf erzeugt keinen zweiten" '[[ $(gpg --batch --list-secret-keys --with-colons | grep -c "^sec") == 1 ]]'
tar -czf $T/ablage/kumero-9.9.8.tar.gz -C $T/bau kumero-9.9.9; ( cd $T/ablage && sha256sum kumero-9.9.8.tar.gz > kumero-9.9.8.sha256 )
ARCHIV=$T/ablage/kumero-9.9.8.tar.gz; NEU=9.9.8
( frage() { return 0; }; signatur_abwarten ) >/dev/null 2>&1
pruefe "selbst signiert und angenommen" '[[ -f $T/ablage/kumero-9.9.8.sha256.asc ]] && ( release_pruefen $ARCHIV $A_SCHLUESSEL ) >/dev/null 2>&1'
rm -f $T/ablage/kumero-9.9.8.sha256.asc
( frage() { return 1; }; signatur_abwarten ) >/dev/null 2>&1
pruefe "ohne Ja keine Signatur" '[[ ! -f $T/ablage/kumero-9.9.8.sha256.asc ]]'
( schluessel_bestaetigen ) >/dev/null 2>&1
pruefe "Vermerk geschrieben" 'grep -q "gibt sich ihre Versionen selbst frei" $T/selbst-freigegeben'
# Test-Installation (F172): Fragen und Eingaben nehmen ihre Vorgabe, ohne zu fragen
MODUS=test
pruefe "Testmodus: Frage nimmt Vorgabe ja" 'frage "x" j </dev/null'
pruefe "Testmodus: Frage nimmt Vorgabe nein" '! frage "x" n </dev/null'
eingabe WERT "x" "vorgabe"
pruefe "Testmodus: Eingabe nimmt Vorgabe" '[[ $WERT == vorgabe ]]'
MODUS=einzeln
# Der ganze Fragenteil als Test-Installation: keine Eingabe außer der Frage nach Paperless (hier: nein)
(
  STAND=$T/nicht-installiert; A_SCHLUESSEL=$T/kein-schluessel.asc; ABLAGE=$T/leer; mkdir -p $ABLAGE; PL_ETC=$T/kein-paperless
  eval "$(declare -f frage | sed '1s/frage/frage_echt/')"
  frage() { if [[ "${3:-}" == immer ]]; then return 1; fi; frage_echt "$@"; }
  fragen --test >/dev/null 2>&1 </dev/null
  [[ $MODUS == test && $A_TUNNEL == ja && $A_ERZEUGEN == ja && $A_PAPERLESS == nein && $E_EINRICHTEN == ja && $E_MANDANT == test \
     && $E_TESTBUCH == ja && $E_MODULE == ja && $E_NUTZER == ja && $E_NUTZER_MAIL == buchhaltung@example.org && $E_ART == euer && $E_VERWALTER == "Test Verwalter" ]]
); rc=$?; pruefe "Test-Installation: alle Antworten ohne Eingabe" '[[ $rc == 0 ]]'
# Antworten merken und laden
A_TUNNEL=nein; A_SERVERNAME=kumero.example.org; A_ZERT_QUELLE=""; A_SCHLUESSEL=/root/freigabe.asc; A_PAPERLESS=ja; A_PL_NAME=paperless.example.org; A_PL_PORT=8000; A_PL_OCR=deu+eng
antworten_merken; A_PL_NAME=x; antworten_laden
pruefe "Antworten" '[[ $A_PL_NAME == paperless.example.org && $A_PL_OCR == deu+eng && $(stat -c %a $T/installer.conf) == 600 ]]'
# Paperless-Dateien
paperless_dateien
pruefe "env nur root" '[[ $(stat -c %a $T/etc/paperless.env) == 600 && $(stat -c %a $T/etc) == 700 ]]'
pruefe "Adresse Betrieb" '[[ $(paperless_wert PAPERLESS_URL) == https://paperless.example.org ]]'
alt="$(paperless_wert PAPERLESS_SECRET_KEY)"; paperless_dateien
pruefe "Geheimnisse bleiben beim zweiten Lauf" '[[ $(paperless_wert PAPERLESS_SECRET_KEY) == "$alt" && ${#alt} == 64 ]]'
pruefe "kein Paperless-Port in der Umgebung" '! grep -q "^PAPERLESS_PORT" $T/etc/paperless.env'
if command -v docker >/dev/null && docker compose version >/dev/null 2>&1; then
aus="$(bash $T/kumero-paperless config 2>&1)"; rc=$?
pruefe "compose config gültig" '[[ $rc == 0 ]]'; [[ $rc == 0 ]] || echo "$aus" | head
pruefe "nur 127.0.0.1" 'grep -q "host_ip: 127.0.0.1" <<<"$aus" && grep -q "published: \"8000\"" <<<"$aus"'
pruefe "DB-Passwort eingesetzt" 'grep -q "POSTGRES_PASSWORD: $(paperless_wert PAPERLESS_DBPASS)" <<<"$aus"'
else echo "übersprungen: docker compose fehlt"; fi
A_TUNNEL=ja; pruefe "Adresse Tunnel" '[[ $(paperless_adresse) == http://localhost:8000 ]]'
echo "bestanden: $ok, fehlgeschlagen: $f"
[[ $f == 0 ]]
