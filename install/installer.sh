#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# kumero – Vollinstallation in einem Lauf (INSTALL.md Abschnitt 2a, F170)
#
# Auf einem frischen Debian 13 (trixie), als root:
#
#   curl -fsSLO https://raw.githubusercontent.com/QCG5OLF5ZU9L2W/kumero/main/install/installer.sh
#   bash installer.sh
#
# Diese eine Datei genügt; alles Weitere holt sie selbst.
#
# Zum Ausprobieren (F172): Die erste Frage bietet die Test-Installation an (Vorgabe ja; ohne die Frage: bash installer.sh --test).
# Dann kommt nur noch die Frage nach Paperless, alles andere läuft von selbst: Zugang über SSH-Tunnel, Freigabeschlüssel
# hier erzeugt und die Version damit freigegeben, Mandant „test“ mit einem Testbuch, alle Module, drei Zugänge mit
# erfundenen Namen. Nicht für echte Daten – das Testbuch wird nie produktiv.
#
# Sonst fragt sie zuerst alles ab und arbeitet dann durch:
#
#   1. Quelle: Liegt ein signiertes Release in /root (kumero-<v>.tar.gz, .sha256, .sha256.asc), wird es gegen den
#      Freigabeschlüssel geprüft und verwendet. Sonst holt das Skript den Stand von GitHub nach /root/kumero-release
#      und baut dort die höchste Version, die einen Tag trägt.
#   2. System: install/install.sh dieser Version (PostgreSQL, PHP, nginx, Dienste, automatische Updates).
#   3. Freigabe (F73): Der Fingerabdruck des hinterlegten Schlüssels wird gezeigt und bestätigt. Fehlt die Signatur,
#      wartet das Skript, bis sie vom Rechner mit dem privaten Schlüssel daliegt.
#      Der einfache Weg (F171): Liegt kein Freigabeschlüssel da, erzeugt das Skript auf Nachfrage einen auf dieser
#      Maschine und fragt vor dem Einspielen, ob damit freigegeben wird. Die Maschine gibt sich dann selbst frei –
#      das steht in /etc/kumero/selbst-freigegeben und am Ende jedes Laufs. Signiert wird nie ohne Nachfrage.
#   4. Release einspielen: install/aktualisieren.sh (prüft Signatur und Prüfsumme, fragt die Versionsnummer ab).
#   5. Paperless-ngx – nur auf Wunsch: als Compose-Paket mit eigener Datenbank, erreichbar nur auf 127.0.0.1;
#      im Betrieb dazu ein eigener Servername in nginx. Nächtlicher Export als Sicherung.
#   6. Erste Einrichtung – nur auf Wunsch: System-Verwalter, erster Mandant mit Administrator, erstes Buch, Module.
#      Die Registrierungslinks stehen am Ende, weil sie nur kurz gelten.
#
# Das Skript darf wiederholt werden: Antworten zur Technik merkt es sich in /etc/kumero/installer.conf, was schon
# eingerichtet ist, bleibt. Ist kumero schon installiert, überspringt es die Schritte 1 bis 4 (Updates macht
# werkzeuge/update-neueste.sh) und bietet nur noch Paperless und die erste Einrichtung an.
#
# Was es nicht tut: ohne Nachfrage signieren, Zertifikate beschaffen, Nutzer oder Tokens in Paperless anlegen, Paperless aktualisieren,
# Sicherungen von der Maschine wegbringen.

set -euo pipefail

ZWEIG="${KUMERO_ZWEIG:-release-0.7.0}"
REPO="${KUMERO_REPO:-https://github.com/QCG5OLF5ZU9L2W/kumero}"
KLON="${KUMERO_KLON:-/root/kumero-release}"
ABLAGE="${KUMERO_ABLAGE:-/root}"                      # hier werden mitgebrachte Release-Dateien gesucht
ANTWORTEN="${KUMERO_ANTWORTEN:-/etc/kumero/installer.conf}"
KONFIG="${KUMERO_KONFIG:-/etc/kumero/install.conf}"
STAND="${KUMERO_STAND:-/var/lib/kumero/installiert}"
VERZ="${KUMERO_VERZ:-/var/www/kumero}"
PL_ETC="${KUMERO_PAPERLESS_ETC:-/etc/kumero/paperless}"
PL_DATEN="${KUMERO_PAPERLESS_DATEN:-/var/lib/kumero-paperless}"
PL_SEITE="${KUMERO_PAPERLESS_SEITE:-/etc/nginx/sites-available/kumero-paperless}"
PL_TLS="${KUMERO_PAPERLESS_TLS:-/etc/kumero/tls-paperless}"
SELBST="${KUMERO_SELBST:-/etc/kumero/selbst-freigegeben}"
PL_BEFEHL="${KUMERO_PAPERLESS_BEFEHL:-/usr/local/sbin/kumero-paperless}"

export DEBIAN_FRONTEND=noninteractive

melde()   { printf '\033[1;34m[kumero]\033[0m %s\n' "$*"; }
warne()   { printf '\033[1;33m[kumero] ACHTUNG:\033[0m %s\n' "$*" >&2; }
abbruch() { printf '\033[1;31m[kumero] ABBRUCH:\033[0m %s\n' "$*" >&2; exit 1; }
titel()   { printf '\n\033[1;34m[kumero] ── %s\033[0m\n' "$*"; }

MODUS=einzeln       # „test“: Fragen und Eingaben nehmen ihre Vorgabe, ohne zu fragen (außer mit „immer“)

# Frage mit Vorgabe: frage "Text" j|n [immer] → Rückgabe 0 bei ja. Gelesen wird vom Terminal, auch wenn das Skript aus einer Pipe kommt.
frage() {
    local antwort vorgabe="$2"
    if [[ "$MODUS" == test && "${3:-}" != immer ]]; then [[ "$vorgabe" == j ]]; return; fi
    read -r -p "$(printf '\033[1;34m[kumero]\033[0m %s [%s] ' "$1" "$([[ $vorgabe == j ]] && echo 'J/n' || echo 'j/N')")" antwort </dev/tty || antwort=""
    antwort="${antwort:-$vorgabe}"
    [[ "$antwort" =~ ^[jJyY] ]]
}

# Eingabe mit Vorgabe: eingabe VARIABLE "Text" "Vorgabe"
eingabe() {
    local __ziel="$1" text="$2" vorgabe="${3:-}" antwort
    if [[ "$MODUS" == test ]]; then printf -v "$__ziel" '%s' "$vorgabe"; return 0; fi
    read -r -p "$(printf '\033[1;34m[kumero]\033[0m %s%s: ' "$text" "${vorgabe:+ [$vorgabe]}")" antwort </dev/tty || antwort=""
    printf -v "$__ziel" '%s' "${antwort:-$vorgabe}"
}

hostname_gueltig() { [[ "$1" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ && "$1" == *.* ]]; }
kennung_gueltig()  { [[ "$1" =~ ^[a-z][a-z0-9-]{1,19}$ ]]; }
# Höchste von zwei Versionen
hoechste()         { printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n 1; }

# Setzt SCHLÜSSEL=WERT in einer Datei im Stil von install.conf: ersetzt die erste Zeile mit dem Schlüssel
# (auch auskommentiert), sonst hängt es an. Alles andere bleibt.
conf_setzen() {
    local datei="$1" schluessel="$2" wert="$3" tmp
    tmp="$(mktemp)"
    K="$schluessel" W="$wert" awk '
        !fertig && $0 ~ "^#?" ENVIRON["K"] "=" { print ENVIRON["K"] "=" ENVIRON["W"]; fertig = 1; next }
        { print }
        END { if (!fertig) print ENVIRON["K"] "=" ENVIRON["W"] }' "$datei" > "$tmp"
    cat "$tmp" > "$datei"; rm -f "$tmp"
}

# ── Antworten zur Technik: merken und wieder vorschlagen (keine Namen, keine Mailadressen, keine Geheimnisse) ─────────
antworten_laden() {
    A_TUNNEL=""; A_SERVERNAME=""; A_ZERT_QUELLE=""; A_SCHLUESSEL=""; A_PAPERLESS=""; A_PL_NAME=""; A_PL_PORT=""; A_PL_OCR=""
    # shellcheck source=/dev/null
    [[ -f "$ANTWORTEN" ]] && source "$ANTWORTEN"
    return 0
}
antworten_merken() {
    install -d -m 0755 "$(dirname "$ANTWORTEN")"
    {
        echo "# Antworten des letzten Laufs von installer.sh – werden beim nächsten Lauf vorgeschlagen."
        local v
        for v in A_TUNNEL A_SERVERNAME A_ZERT_QUELLE A_SCHLUESSEL A_PAPERLESS A_PL_NAME A_PL_PORT A_PL_OCR; do
            printf '%s=%q\n' "$v" "${!v:-}"
        done
    } > "$ANTWORTEN"
    chmod 0600 "$ANTWORTEN"
}

# ── 0. Voraussetzungen ───────────────────────────────────────────────────────────────────────────────────────────────
vorab_pruefen() {
    [[ $EUID -eq 0 ]] || abbruch "Bitte als root ausführen."
    [[ -r /dev/tty ]] || abbruch "Das Skript stellt Fragen und braucht ein Terminal."
    # shellcheck source=/dev/null
    . /etc/os-release
    [[ "${ID:-}" == debian && "${VERSION_CODENAME:-}" == trixie ]] \
        || abbruch "Unterstützt wird nur Debian 13 (trixie), gefunden: ${PRETTY_NAME:-unbekannt}."
    command -v systemctl >/dev/null || abbruch "systemd wird benötigt."
}

# ── Fragen ───────────────────────────────────────────────────────────────────────────────────────────────────────────
fragen() {
    titel "Fragen – danach läuft alles durch bis zur Freigabe"
    INSTALLIERT=""; [[ -f "$STAND" ]] && INSTALLIERT="$(cat "$STAND")"

    # Vorgaben der ersten Einrichtung; die Test-Installation füllt sie mit erfundenen Angaben
    local v_verwalter="" v_mandant="praxis" v_name="Praxis" v_vor="" v_fam="" v_mail="" v_nvor="" v_nfam="" v_nmail=""
    E_TESTBUCH=nein
    if [[ -z "$INSTALLIERT" ]]; then
        melde "Test-Installation zum Ausprobieren: Alles läuft von selbst – Zugang über einen SSH-Tunnel, der Freigabeschlüssel wird"
        melde "hier erzeugt und die Version damit freigegeben, dazu ein Mandant „test“ mit Testbuch, allen Modulen und drei Zugängen."
        melde "Nicht für echte Daten."
        if [[ "${1:-}" == --test ]] || frage "Als Test-Installation einrichten (sonst wird alles einzeln abgefragt)?" j immer; then
            MODUS=test; A_TUNNEL=""; E_TESTBUCH=ja
            v_verwalter="Test Verwalter"; v_mandant="test"; v_name="Testpraxis"
            v_vor="Test"; v_fam="Administrator"; v_mail="administrator@example.org"
            v_nvor="Test"; v_nfam="Buchhaltung"; v_nmail="buchhaltung@example.org"
        fi
    fi
    if [[ -z "$INSTALLIERT" ]]; then
        [[ "$MODUS" == test ]] || melde "Zugang: Passkeys brauchen einen Hostnamen mit gültigem Zertifikat – oder http://localhost über einen SSH-Tunnel."
        if frage "Test-VM mit Zugang über SSH-Tunnel (ohne Zertifikat)?" "$([[ "$A_TUNNEL" == nein ]] && echo n || echo j)"; then
            A_TUNNEL=ja
        else
            A_TUNNEL=nein
            while :; do
                eingabe A_SERVERNAME "Vollständiger Hostname von kumero" "${A_SERVERNAME:-$(hostname -f 2>/dev/null || hostname)}"
                hostname_gueltig "$A_SERVERNAME" && break
                warne "Erwartet wird ein Name wie kumero.example.org (Kleinbuchstaben, Ziffern, Punkt, Bindestrich)."
            done
            eingabe A_ZERT_QUELLE "Verzeichnis, in das ein Zertifikat von außen geliefert wird (leer = vorerst selbst signiert)" "$A_ZERT_QUELLE"
            [[ -z "$A_ZERT_QUELLE" || "$A_ZERT_QUELLE" =~ ^/[A-Za-z0-9._/-]+$ ]] || abbruch "Kein gültiger Pfad: $A_ZERT_QUELLE"
        fi

        melde "Freigabe (F73): Installiert wird nur, was mit dem Freigabeschlüssel dieser Installation signiert ist."
        A_SCHLUESSEL="${A_SCHLUESSEL:-/root/freigabe.asc}"; A_ERZEUGEN=nein
        if [[ -f "$A_SCHLUESSEL" ]]; then
            melde "Gefunden: $A_SCHLUESSEL"
            schluessel_pruefen "$A_SCHLUESSEL"
        elif release_suchen; then
            # Ein mitgebrachtes Release ist schon signiert – dazu gehört der Schlüssel dessen, der signiert hat.
            abbruch "In $ABLAGE liegt ein Release, aber kein Freigabeschlüssel ($A_SCHLUESSEL). Den öffentlichen Schlüssel dazulegen und erneut starten."
        else
            melde "In $A_SCHLUESSEL liegt kein Schlüssel. Zwei Wege:"
            melde "  einfach:  Das Skript erzeugt hier einen. Diese Maschine gibt sich ihre Versionen dann selbst frei."
            melde "  getrennt: Du bringst den öffentlichen Teil deines Schlüssels mit; signiert wird auf deinem Rechner."
            if frage "Hier einen Freigabeschlüssel erzeugen?" j; then
                A_ERZEUGEN=ja
            else
                melde "Auf dem Rechner mit dem Schlüssel:  gpg --armor --export <Schlüssel-ID> > freigabe.asc   – und dann hierher kopieren."
                eingabe A_SCHLUESSEL "Datei mit dem öffentlichen Freigabeschlüssel" "$A_SCHLUESSEL"
                schluessel_pruefen "$A_SCHLUESSEL"
            fi
        fi
    else
        melde "kumero $INSTALLIERT ist hier schon installiert – System und Release bleiben, wie sie sind (Updates: werkzeuge/update-neueste.sh)."
        # Betriebsart der vorhandenen Installation übernehmen
        A_TUNNEL="$(sed -n 's/^ENTWICKLUNG_TUNNEL=//p' "$KONFIG" 2>/dev/null | tail -n 1)"; A_TUNNEL="${A_TUNNEL:-nein}"
        A_SERVERNAME="$(sed -n 's/^SERVERNAME=//p' "$KONFIG" 2>/dev/null | tail -n 1)"; A_SERVERNAME="${A_SERVERNAME:-$(hostname -f 2>/dev/null || hostname)}"
    fi

    # Paperless
    if [[ -f "$PL_ETC/compose.yml" ]]; then
        melde "Paperless ist hier schon eingerichtet ($PL_ETC) – es wird nur geprüft und gestartet."
        A_PAPERLESS=ja
    elif frage "Paperless-ngx mit auf diese Maschine installieren?" "$([[ "$A_PAPERLESS" == ja ]] && echo j || echo n)" immer; then
        A_PAPERLESS=ja
    else
        A_PAPERLESS=nein
    fi
    if [[ "$A_PAPERLESS" == ja && ! -f "$PL_ETC/compose.yml" ]]; then
        if [[ "$A_TUNNEL" == nein ]]; then
            while :; do
                eingabe A_PL_NAME "Vollständiger Hostname von Paperless (eigener Name, eigenes Zertifikat)" "${A_PL_NAME:-paperless.${A_SERVERNAME#*.}}"
                hostname_gueltig "$A_PL_NAME" && [[ "$A_PL_NAME" != "$A_SERVERNAME" ]] && break
                warne "Erwartet wird ein eigener Name wie paperless.example.org – nicht der von kumero."
            done
        fi
        eingabe A_PL_PORT "Port von Paperless auf 127.0.0.1" "${A_PL_PORT:-8000}"
        [[ "$A_PL_PORT" =~ ^[0-9]{2,5}$ && "$A_PL_PORT" != 8080 ]] || abbruch "Kein brauchbarer Port: $A_PL_PORT (8080 gehört dem Tunnel von kumero)."
        eingabe A_PL_OCR "Sprachen der Texterkennung" "${A_PL_OCR:-deu+eng}"
        [[ "$A_PL_OCR" =~ ^[a-z_]+(\+[a-z_]+)*$ ]] || abbruch "Sprachen wie deu+eng erwartet."
    fi

    # Erste Einrichtung
    E_EINRICHTEN=nein; E_MODULE=nein; E_NUTZER=nein
    if frage "System-Verwalter und einen Mandanten mit erstem Buch anlegen?" "$([[ -z "$INSTALLIERT" ]] && echo j || echo n)"; then
        E_EINRICHTEN=ja
        eingabe E_VERWALTER "System-Verwalter: Anzeigename (leer = keinen anlegen)" "$v_verwalter"
        while :; do
            eingabe E_MANDANT "Mandant: Kennung für den Pfad" "$v_mandant"
            kennung_gueltig "$E_MANDANT" && break
            warne "Kleinbuchstaben, Ziffern, Bindestrich; 2 bis 20 Zeichen, beginnt mit einem Buchstaben."
        done
        eingabe E_MANDANT_NAME "Mandant: Anzeigename" "$v_name"
        eingabe E_ADMIN_VOR "Administrator des Mandanten: Vorname" "$v_vor"
        eingabe E_ADMIN_FAM "Administrator des Mandanten: Familienname" "$v_fam"
        eingabe E_ADMIN_MAIL "Administrator des Mandanten: E-Mail" "$v_mail"
        [[ -n "$E_ADMIN_VOR" && -n "$E_ADMIN_FAM" && "$E_ADMIN_MAIL" == *@*.* ]] || abbruch "Vorname, Familienname und E-Mail des Administrators werden gebraucht."
        eingabe E_ART "Erstes Buch „haupt“: Gewinnermittlung (euer oder bilanz)" "euer"
        [[ "$E_ART" =~ ^(euer|bilanz)$ ]] || abbruch "euer oder bilanz erwartet."
        frage "Im neuen Mandanten alle mitgelieferten Module einschalten?" j && E_MODULE=ja
        # Der Administrator verwaltet, er bucht nicht: Wer bucht, ist eine eigene Identität.
        if frage "Auch gleich jemanden anlegen, der bucht (Rolle Verantwortlicher)?" j; then
            E_NUTZER=ja
            eingabe E_NUTZER_VOR "Wer bucht: Vorname" "$v_nvor"
            eingabe E_NUTZER_FAM "Wer bucht: Familienname" "$v_nfam"
            eingabe E_NUTZER_MAIL "Wer bucht: E-Mail" "$v_nmail"
            [[ -n "$E_NUTZER_VOR" && -n "$E_NUTZER_FAM" && "$E_NUTZER_MAIL" == *@*.* ]] || abbruch "Vorname, Familienname und E-Mail werden gebraucht."
        fi
    fi
    antworten_merken
}

# Der öffentliche Schlüssel: muss lesbar sein, einen Schlüssel enthalten und darf kein privater sein.
schluessel_pruefen() {
    local datei="$1"
    [[ "$datei" =~ ^/[A-Za-z0-9._/-]+$ ]] || abbruch "Kein gültiger Pfad: $datei"
    [[ -f "$datei" ]] || abbruch "$datei fehlt. Erst den öffentlichen Schlüssel hierher kopieren, dann erneut starten – die Antworten bleiben erhalten."
    ! grep -q 'PRIVATE KEY' "$datei" \
        || abbruch "$datei enthält einen privaten Schlüssel. Er gehört nie auf einen Server – hier nur den öffentlichen Teil ablegen und die Datei löschen."
    grep -q 'BEGIN PGP PUBLIC KEY BLOCK' "$datei" || abbruch "$datei ist kein öffentlicher OpenPGP-Schlüssel im ASCII-Format."
}

# Fingerabdruck des Schlüssels in der Datei
schluessel_fingerabdruck() { gpg --show-keys --with-colons "$1" 2>/dev/null | awk -F: '/^fpr/ {print $10; exit}'; }

# Liegt der private Teil des Freigabeschlüssels auf dieser Maschine? Dann gibt sie sich selbst frei (F171).
selbst_freigegeben() {
    local fpr; fpr="$(schluessel_fingerabdruck "$A_SCHLUESSEL")"
    [[ -n "$fpr" ]] && gpg --batch --list-secret-keys "$fpr" >/dev/null 2>&1
}

# Der einfache Weg (F171): Schlüssel auf dieser Maschine erzeugen, im Schlüsselbund von root, ohne Passphrase.
# Der öffentliche Teil kommt nach $A_SCHLUESSEL und geht von dort den gewohnten Weg über install.sh.
schluessel_erzeugen() {
    [[ "${A_ERZEUGEN:-nein}" == ja && ! -f "$A_SCHLUESSEL" ]] || return 0
    titel "Freigabeschlüssel erzeugen"
    local name fpr
    name="kumero Freigabe $(hostname -f 2>/dev/null || hostname) $(date +%Y-%m-%d)"
    gpg --batch --quiet --pinentry-mode loopback --passphrase '' --quick-generate-key "$name" ed25519 sign never
    fpr="$(gpg --batch --list-secret-keys --with-colons "=$name" | awk -F: '/^fpr/ {print $10; exit}')"
    [[ -n "$fpr" ]] || abbruch "Der Schlüssel ließ sich nicht erzeugen."
    ( umask 022; gpg --batch --armor --export "$fpr" > "$A_SCHLUESSEL" )
    schluessel_pruefen "$A_SCHLUESSEL"
    melde "Erzeugt: „$name“ – der private Teil liegt im Schlüsselbund von root auf dieser Maschine."
}

# ── 1. Quelle ────────────────────────────────────────────────────────────────────────────────────────────────────────
grundpakete() {
    titel "Grundpakete"
    apt-get update -qq
    apt-get install -y --no-install-recommends ca-certificates curl gnupg gpgv openssl >/dev/null
}

# Sucht ein mitgebrachtes Release in $ABLAGE. Setzt ARCHIV und NEU (die Version); Rückgabe 1, wenn keines da ist.
release_suchen() {
    local d
    d="$(find "$ABLAGE" -maxdepth 1 -name 'kumero-*.tar.gz' 2>/dev/null | sort -V | tail -n 1)"
    [[ -n "$d" && "$(basename "$d")" =~ ^kumero-([0-9]+\.[0-9]+\.[0-9]+)\.tar\.gz$ ]] || return 1
    ARCHIV="$d"; NEU="${BASH_REMATCH[1]}"
}

# Prüft Signatur und Prüfsumme eines Archivs gegen die Datei mit dem öffentlichen Schlüssel – bevor irgendetwas
# daraus ausgeführt wird.
release_pruefen() {
    local archiv="$1" schluessel="$2" verz name ring
    verz="$(dirname "$archiv")"; name="$(basename "$archiv" .tar.gz)"
    [[ -f "$verz/$name.sha256" && -f "$verz/$name.sha256.asc" ]] \
        || abbruch "Zu $archiv fehlen $name.sha256 oder $name.sha256.asc – die drei Dateien gehören zusammen."
    ring="$(mktemp)"
    gpg --batch --yes --dearmor -o "$ring" "$schluessel"
    if ! gpgv --keyring "$ring" "$verz/$name.sha256.asc" "$verz/$name.sha256" 2>/dev/null; then
        rm -f "$ring"; abbruch "Die Signatur von $name passt nicht zum Freigabeschlüssel in $schluessel – nicht freigegeben."
    fi
    rm -f "$ring"
    (cd "$verz" && sha256sum --quiet -c "$name.sha256") || abbruch "Prüfsumme stimmt nicht – $archiv ist verändert."
    melde "Signatur und Prüfsumme von $name stimmen."
}

quelle_holen() {
    titel "Quelle"
    BAUEN=nein
    if release_suchen; then
        melde "Release in $ABLAGE gefunden: $(basename "$ARCHIV")"
        release_pruefen "$ARCHIV" "$A_SCHLUESSEL"
        [[ -d "$ABLAGE/kumero-$NEU" ]] || tar -xzf "$ARCHIV" -C "$ABLAGE" --no-same-owner
        BAUM="$ABLAGE/kumero-$NEU"
        return 0
    fi
    melde "In $ABLAGE liegt kein Release – der Stand kommt von GitHub und wird hier gebaut."
    melde "(Für den Betrieb ist der andere Weg der vorsichtigere: woanders bauen und signieren, die drei Dateien nach $ABLAGE legen.)"
    frage "Von GitHub holen und hier bauen?" j || abbruch "Nichts geändert. Die drei Dateien nach $ABLAGE legen und erneut starten."
    apt-get install -y --no-install-recommends git unzip >/dev/null
    if [[ -d "$KLON/.git" ]]; then
        [[ -z "$(git -C "$KLON" status --porcelain)" ]] || abbruch "Der Klon $KLON hat lokale Änderungen – erst aufräumen."
        git -C "$KLON" fetch -q origin --tags
    else
        git clone -q "$REPO" "$KLON"
    fi
    local tag
    tag="$(git -C "$KLON" ls-remote --tags --refs origin | awk '{sub("refs/tags/", "", $2); print $2}' \
        | { grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' || true; } | sort -V | tail -n 1)"
    [[ -n "$tag" ]] || abbruch "Auf GitHub steht kein Versions-Tag – es gibt nichts Freigegebenes zu bauen."
    NEU="${tag#v}"
    git -C "$KLON" switch -q --detach "$tag"
    local imzweig; imzweig="$(git -C "$KLON" show "origin/$ZWEIG:VERSION" 2>/dev/null | tr -d '[:space:]' || true)"
    melde "Höchste Version mit Tag: $NEU"
    if [[ -n "$imzweig" && "$imzweig" != "$NEU" && "$(hoechste "$imzweig" "$NEU")" == "$imzweig" ]]; then
        warne "Im Zweig $ZWEIG steht schon $imzweig – dafür gibt es noch keinen Tag. Installiert wird $NEU."
    fi
    BAUM="$KLON"; BAUEN=ja
    ARCHIV="$KLON/dist/kumero-$NEU.tar.gz"
}

# ── 2. System ────────────────────────────────────────────────────────────────────────────────────────────────────────
system_einrichten() {
    titel "System einrichten (install.sh von $NEU)"
    [[ -f "$BAUM/install/install.sh" ]] || abbruch "$BAUM/install/install.sh fehlt."
    if [[ ! -f "$KONFIG" ]]; then
        install -d -m 0755 "$(dirname "$KONFIG")"
        install -m 0640 "$BAUM/install/vorlagen/install.conf" "$KONFIG"
    fi
    conf_setzen "$KONFIG" ENTWICKLUNG_TUNNEL "$A_TUNNEL"
    conf_setzen "$KONFIG" FREIGABE_SCHLUESSEL "$A_SCHLUESSEL"
    if [[ "$A_TUNNEL" == nein ]]; then
        conf_setzen "$KONFIG" SERVERNAME "$A_SERVERNAME"
        [[ -z "$A_ZERT_QUELLE" ]] || conf_setzen "$KONFIG" ZERTIFIKAT_QUELLE "$A_ZERT_QUELLE"
    fi
    bash "$BAUM/install/install.sh"
}

# ── 3. Freigabe ──────────────────────────────────────────────────────────────────────────────────────────────────────
schluessel_bestaetigen() {
    titel "Freigabeschlüssel"
    local fpr
    fpr="$(schluessel_fingerabdruck "$A_SCHLUESSEL")"
    [[ -n "$fpr" ]] || abbruch "Aus $A_SCHLUESSEL lässt sich kein Fingerabdruck lesen."
    melde "Hinterlegt ist der Schlüssel mit dem Fingerabdruck:"
    printf '\n        %s\n\n' "$(sed 's/..../& /g' <<<"$fpr")"
    if selbst_freigegeben; then
        melde "Sein privater Teil liegt auf dieser Maschine: Sie gibt sich selbst frei (vermerkt in $SELBST)."
        printf 'Der private Teil des Freigabeschlüssels %s liegt auf dieser Maschine (Schlüsselbund von root).\nSie gibt sich ihre Versionen selbst frei (F171). Vermerkt von installer.sh am %s.\n' "$fpr" "$(date +%Y-%m-%d)" > "$SELBST"
        return 0
    fi
    rm -f "$SELBST"
    melde "Vergleiche ihn auf dem Rechner mit deinem Schlüssel (gpg --fingerprint). Nur was damit signiert ist, läuft hier."
    frage "Ist das dein Freigabeschlüssel?" n immer \
        || abbruch "Nicht bestätigt. Den richtigen öffentlichen Schlüssel nach $A_SCHLUESSEL legen und erneut starten – kumero ist noch nicht eingespielt."
}

release_bauen() {
    [[ "$BAUEN" == ja ]] || return 0
    titel "Release $NEU bauen"
    if [[ -f "$ARCHIV" && -f "${ARCHIV%.tar.gz}.sha256" ]]; then
        melde "Archiv ist schon gebaut: $ARCHIV"
    else
        # composer aus Debian; PHP steht seit install.sh bereit
        apt-get install -y --no-install-recommends composer >/dev/null
        (cd "$KLON" && bash werkzeuge/release-bauen.sh)
        [[ -f "$ARCHIV" ]] || abbruch "Nach dem Bauen fehlt $ARCHIV."
    fi
    git -C "$KLON" switch -q "$ZWEIG" 2>/dev/null || true
}

# Die Signatur ist die Freigabe. Sie entsteht auf dem Rechner mit dem privaten Schlüssel – das Skript wartet darauf.
signatur_abwarten() {
    local summe="${ARCHIV%.tar.gz}.sha256" antwort
    [[ -f "$summe.asc" ]] && return 0
    titel "Signatur – deine Freigabe für Version $NEU"
    if selbst_freigegeben; then
        melde "Der Freigabeschlüssel liegt auf dieser Maschine. Mit der Signatur gibst du Version $NEU für diese Installation frei."
        if ! frage "Version $NEU jetzt hier freigeben?" j; then
            melde "Nicht freigegeben – nichts eingespielt. Später: bash installer.sh"
            exit 2
        fi
        (cd "$(dirname "$summe")" && gpg --armor --local-user "$(schluessel_fingerabdruck "$A_SCHLUESSEL")" --detach-sign "$(basename "$summe")")
        return 0
    fi
    melde "Die Signatur fehlt noch. Auf dem Rechner mit deinem privaten Schlüssel:"
    printf '\n    scp root@%s:%s .\n    gpg --armor --detach-sign %s\n    scp %s.asc root@%s:%s/\n\n' \
        "$(hostname -f 2>/dev/null || hostname)" "$summe" "$(basename "$summe")" "$(basename "$summe")" \
        "$(hostname -f 2>/dev/null || hostname)" "$(dirname "$summe")"
    melde "Dieses Skript wartet solange. Es signiert nie selbst."
    while [[ ! -f "$summe.asc" ]]; do
        read -r -p "$(printf '\033[1;34m[kumero]\033[0m Eingabetaste, sobald die Signatur daliegt (a = abbrechen und später fortsetzen): ')" antwort </dev/tty || antwort=a
        if [[ "$antwort" =~ ^[aA] ]]; then
            melde "Abgebrochen. Sobald $summe.asc daliegt: bash installer.sh – es macht an dieser Stelle weiter."
            exit 2
        fi
        [[ -f "$summe.asc" ]] || warne "$summe.asc ist noch nicht da."
    done
}

# ── 4. Einspielen ────────────────────────────────────────────────────────────────────────────────────────────────────
release_einspielen() {
    titel "Release $NEU einspielen"
    release_pruefen "$ARCHIV" "$A_SCHLUESSEL"
    # aktualisieren.sh kommt aus dem eben geprüften Archiv, nicht aus dem Klon
    local skript; skript="$(mktemp -d)/aktualisieren.sh"
    tar -xzf "$ARCHIV" -O "kumero-$NEU/install/aktualisieren.sh" > "$skript"
    # In der Test-Installation entfällt das Eintippen der Versionsnummer (die Signatur ist die Freigabe)
    bash "$skript" "$ARCHIV" $([[ "$MODUS" == test ]] && echo --ja) </dev/tty
    [[ "$(cat "$STAND" 2>/dev/null)" == "$NEU" ]] || abbruch "Nach dem Einspielen steht nicht $NEU in $STAND – siehe Ausgabe oben."
}

# ── 5. Paperless-ngx ─────────────────────────────────────────────────────────────────────────────────────────────────
# Adresse, unter der Menschen und kumero das Paperless erreichen
paperless_adresse() {
    if [[ "$A_TUNNEL" == ja ]]; then echo "http://localhost:$A_PL_PORT"; else echo "https://$A_PL_NAME"; fi
}

# Schreibt Compose-Datei, Umgebung, Aufruf-Hilfe und Sicherungs-Einheiten. Vorhandene Geheimnisse bleiben.
paperless_dateien() {
    install -d -m 0700 "$PL_ETC"
    install -d -m 0755 "$PL_DATEN"
    local d; for d in data media export consume pgdata redis; do install -d "$PL_DATEN/$d"; done

    if [[ ! -f "$PL_ETC/paperless.env" ]]; then
        local zone; zone="$(timedatectl show -p Timezone --value 2>/dev/null || true)"
        ( umask 077; cat > "$PL_ETC/paperless.env" <<EOF
# Umgebung von Paperless-ngx (nur root). Angelegt von installer.sh, danach von Hand gepflegt.
# Nach einer Änderung:  kumero-paperless up -d
KUMERO_PL_ABBILD=ghcr.io/paperless-ngx/paperless-ngx:latest
KUMERO_PL_PORT=$A_PL_PORT
PAPERLESS_URL=$(paperless_adresse)
PAPERLESS_TIME_ZONE=${zone:-Europe/Berlin}
PAPERLESS_OCR_LANGUAGE=$A_PL_OCR
PAPERLESS_SECRET_KEY=$(openssl rand -hex 32)
PAPERLESS_DBPASS=$(openssl rand -hex 20)
# Erstes Konto mit allen Rechten. Das Passwort nach der ersten Anmeldung in Paperless ändern;
# ein vorhandenes Konto wird von diesen Zeilen nicht mehr berührt.
PAPERLESS_ADMIN_USER=admin
PAPERLESS_ADMIN_PASSWORD=$(openssl rand -hex 16)
EOF
        )
    fi

    cat > "$PL_ETC/compose.yml" <<EOF
# Paperless-ngx neben kumero (von installer.sh verwaltet). Erreichbar nur auf 127.0.0.1.
# Aufruf immer über:  kumero-paperless <compose-Befehl>     z. B. kumero-paperless ps
name: kumero-paperless
services:
  broker:
    image: docker.io/library/redis:7
    restart: unless-stopped
    volumes:
      - $PL_DATEN/redis:/data
  db:
    image: docker.io/library/postgres:17
    restart: unless-stopped
    volumes:
      - $PL_DATEN/pgdata:/var/lib/postgresql/data
    environment:
      POSTGRES_DB: paperless
      POSTGRES_USER: paperless
      POSTGRES_PASSWORD: \${PAPERLESS_DBPASS}
  webserver:
    image: \${KUMERO_PL_ABBILD}
    restart: unless-stopped
    depends_on:
      - db
      - broker
    ports:
      - "127.0.0.1:\${KUMERO_PL_PORT}:8000"
    volumes:
      - $PL_DATEN/data:/usr/src/paperless/data
      - $PL_DATEN/media:/usr/src/paperless/media
      - $PL_DATEN/export:/usr/src/paperless/export
      - $PL_DATEN/consume:/usr/src/paperless/consume
    env_file: paperless.env
    environment:
      PAPERLESS_REDIS: redis://broker:6379
      PAPERLESS_DBHOST: db
EOF
    chmod 0600 "$PL_ETC/compose.yml"

    cat > "$PL_BEFEHL" <<EOF
#!/bin/bash
# Ruft docker compose für das Paperless neben kumero auf (von installer.sh angelegt).
set -euo pipefail
if docker compose version >/dev/null 2>&1; then c=(docker compose); else c=(docker-compose); fi
exec "\${c[@]}" --env-file "$PL_ETC/paperless.env" -f "$PL_ETC/compose.yml" "\$@"
EOF
    chmod 0755 "$PL_BEFEHL"

    local einheiten="${KUMERO_PAPERLESS_EINHEITEN:-/etc/systemd/system}"
    cat > "$einheiten/kumero-paperless-sicherung.service" <<EOF
[Unit]
Description=Paperless neben kumero: Export aller Dokumente und Daten nach $PL_DATEN/export
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/kumero-paperless exec -T webserver document_exporter ../export
EOF
    cat > "$einheiten/kumero-paperless-sicherung.timer" <<EOF
[Unit]
Description=Paperless neben kumero: nächtlicher Export

[Timer]
OnCalendar=*-*-* 02:30
Persistent=true

[Install]
WantedBy=timers.target
EOF
}

# Eigener Servername in nginx (nur Betrieb). Das Zertifikat liegt getrennt von dem für kumero.
paperless_nginx() {
    [[ "$A_TUNNEL" == nein ]] || return 0
    install -d -m 0750 "$PL_TLS"
    if [[ ! -f "$PL_TLS/fullchain.pem" || ! -f "$PL_TLS/privkey.pem" ]]; then
        install -m 0644 /etc/ssl/certs/ssl-cert-snakeoil.pem "$PL_TLS/fullchain.pem"
        install -m 0600 /etc/ssl/private/ssl-cert-snakeoil.key "$PL_TLS/privkey.pem"
    fi
    if [[ -f "$PL_SEITE" ]]; then
        melde "$PL_SEITE gibt es schon – bleibt unverändert."
    else
        cat > "$PL_SEITE" <<EOF
# Paperless-ngx neben kumero (von installer.sh angelegt, danach von Hand gepflegt)
server {
    listen 80;
    listen [::]:80;
    server_name $A_PL_NAME;
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
    server_name $A_PL_NAME;

    ssl_certificate     $PL_TLS/fullchain.pem;
    ssl_certificate_key $PL_TLS/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    client_max_body_size 350m;
    server_tokens off;
    add_header Strict-Transport-Security "max-age=31536000" always;

    access_log /var/log/nginx/kumero-paperless.access.log;
    error_log  /var/log/nginx/kumero-paperless.error.log;

    location / {
        proxy_pass http://127.0.0.1:$A_PL_PORT;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_redirect off;
    }
}
EOF
    fi
    ln -sf ../sites-available/"$(basename "$PL_SEITE")" /etc/nginx/sites-enabled/"$(basename "$PL_SEITE")"
    if nginx -t 2>/dev/null; then
        systemctl reload nginx
    else
        rm -f /etc/nginx/sites-enabled/"$(basename "$PL_SEITE")"
        warne "nginx nimmt $PL_SEITE nicht an (nginx -t) – die Seite ist nicht eingeschaltet. Paperless läuft trotzdem auf 127.0.0.1:$A_PL_PORT."
    fi
}

# Liest eine Zeile SCHLÜSSEL=WERT aus der Umgebung von Paperless
paperless_wert() { sed -n "s/^$1=//p" "$PL_ETC/paperless.env" | tail -n 1; }

# Wartet, bis Paperless antwortet, und stellt die Version fest (kumero braucht 3.0 oder neuer).
paperless_pruefen() {
    local port admin i code version=""
    port="$(paperless_wert KUMERO_PL_PORT)"; admin="$(paperless_wert PAPERLESS_ADMIN_USER)"
    melde "Warte auf Paperless (der erste Start richtet die Datenbank ein und dauert einige Minuten)"
    for ((i = 0; i < 90; i++)); do
        code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "http://127.0.0.1:$port/" || true)"
        [[ "$code" =~ ^[234] ]] && break
        sleep 5
    done
    if [[ ! "$code" =~ ^[234] ]]; then
        warne "Paperless antwortet nach 7 Minuten nicht auf 127.0.0.1:$port. Ansehen mit: kumero-paperless logs webserver"
        return 1
    fi
    # Das Passwort geht über die Standardeingabe an curl, nicht über die Befehlszeile.
    version="$(printf 'user = "%s:%s"\n' "$admin" "$(paperless_wert PAPERLESS_ADMIN_PASSWORD)" \
        | curl -s -K - -D - -o /dev/null --max-time 10 "http://127.0.0.1:$port/api/" \
        | tr -d '\r' | awk -F': *' 'tolower($1) == "x-version" {print $2; exit}' || true)"
    if [[ -z "$version" ]]; then
        warne "Die Version von Paperless ließ sich nicht feststellen (Passwort des ersten Kontos schon geändert?). kumero zeigt sie im Verbindungstest."
    elif [[ "$(hoechste "$version" 3.0)" != "$version" ]]; then
        warne "Paperless meldet Version $version – kumero verlangt 3.0 oder neuer und lehnt ältere ab."
    else
        melde "Paperless $version läuft auf 127.0.0.1:$port."
    fi
    PL_VERSION="$version"
}

paperless_einrichten() {
    [[ "$A_PAPERLESS" == ja ]] || return 0
    titel "Paperless-ngx"
    apt-get install -y --no-install-recommends docker.io >/dev/null
    if ! docker compose version >/dev/null 2>&1 && ! command -v docker-compose >/dev/null; then
        apt-get install -y --no-install-recommends docker-compose >/dev/null
    fi
    docker compose version >/dev/null 2>&1 || command -v docker-compose >/dev/null \
        || abbruch "Weder „docker compose“ noch docker-compose ließen sich einrichten."
    systemctl enable --now docker >/dev/null
    local neu=nein
    if [[ -f "$PL_ETC/compose.yml" ]]; then
        melde "Vorhandene Einrichtung in $PL_ETC bleibt, wie sie ist."
    else
        neu=ja
        paperless_dateien
        systemctl daemon-reload
    fi
    "$PL_BEFEHL" pull
    "$PL_BEFEHL" up -d
    systemctl enable --now kumero-paperless-sicherung.timer >/dev/null 2>&1 || true
    [[ "$neu" == nein ]] || paperless_nginx
    PL_VERSION=""; paperless_pruefen || true
}

# ── 6. Erste Einrichtung ─────────────────────────────────────────────────────────────────────────────────────────────
erste_einrichtung() {
    [[ "$E_EINRICHTEN" == ja ]] || return 0
    titel "Erste Einrichtung"
    [[ -f "$VERZ/bin/kumero" ]] || abbruch "$VERZ/bin/kumero fehlt – das Release ist nicht eingespielt."
    local k=(php "$VERZ/bin/kumero")
    echo; melde "▶ Zugang 1 – Administrator des Mandanten „$E_MANDANT“ ($E_ADMIN_VOR $E_ADMIN_FAM): verwaltet Nutzer, Rollen und Module"
    "${k[@]}" mandant:anlegen "$E_MANDANT" "$E_MANDANT_NAME" --administrator "$E_ADMIN_VOR" "$E_ADMIN_FAM" "$E_ADMIN_MAIL"
    melde "Erstes Buch „haupt“ ($E_ART$([[ "$E_TESTBUCH" == ja ]] && echo ', Testbuch'))"
    if [[ "$E_TESTBUCH" == ja ]]; then
        "${k[@]}" buch:anlegen "$E_MANDANT" haupt "$E_MANDANT_NAME" --art "$E_ART" --testbuch
    else
        "${k[@]}" buch:anlegen "$E_MANDANT" haupt "$E_MANDANT_NAME" --art "$E_ART"
    fi
    if [[ "$E_MODULE" == ja ]]; then
        melde "Module einschalten"
        "${k[@]}" modul:alle "$E_MANDANT" || warne "Nicht jedes Modul ließ sich einschalten – in der Oberfläche unter Mandant → Module nachholen."
    fi
    if [[ "$E_NUTZER" == ja ]]; then
        echo; melde "▶ Zugang 2 – wer bucht ($E_NUTZER_VOR $E_NUTZER_FAM): Buchhaltung im Buch „haupt“"
        "${k[@]}" nutzer:anlegen "$E_MANDANT" "$E_NUTZER_VOR" "$E_NUTZER_FAM" "$E_NUTZER_MAIL" --rolle Verantwortlicher
    fi
    if [[ -n "$E_VERWALTER" ]]; then
        echo; melde "▶ Zugang 3 – System-Verwalter ($E_VERWALTER): legt Mandanten an, liest Kontenrahmen ein"
        "${k[@]}" verwalter:anlegen "$E_VERWALTER"
    fi
}

# ── Abschluss ────────────────────────────────────────────────────────────────────────────────────────────────────────
abschluss() {
    titel "Fertig: kumero $(cat "$STAND" 2>/dev/null || echo '?')"
    local host; host="$(hostname -f 2>/dev/null || hostname)"
    if [[ "$A_TUNNEL" == ja ]]; then
        melde "Zugang vom Arbeitsplatz:"
        if [[ "$A_PAPERLESS" == ja ]]; then
            printf '\n    ssh -L 8080:127.0.0.1:8080 -L %s:127.0.0.1:%s root@%s\n\n' "$(paperless_wert KUMERO_PL_PORT)" "$(paperless_wert KUMERO_PL_PORT)" "$host"
        else
            printf '\n    ssh -L 8080:127.0.0.1:8080 root@%s\n\n' "$host"
        fi
        melde "kumero: http://localhost:8080"
    else
        melde "kumero: https://$A_SERVERNAME – Passkeys verlangen ein Zertifikat, dem der Browser vertraut (install/README.md, „Zertifikat“)."
    fi
    if [[ "$E_EINRICHTEN" == ja ]]; then
        echo
        melde "So geht es weiter, am eigenen Rechner:"
        if [[ "$A_TUNNEL" == ja ]]; then
            melde "  1. Ein Terminal öffnen (Windows: PowerShell), die Zeile mit „ssh -L“ von oben einfügen und das Fenster offen lassen."
            melde "  2. Im Browser den Link eines Zugangs von oben öffnen und einen Passkey anlegen. Für jeden Zugang einmal."
        else
            melde "  1. Im Browser den Link eines Zugangs von oben öffnen und einen Passkey anlegen. Für jeden Zugang einmal."
        fi
        melde "  Die Links gelten nur kurz und nur einmal, und jeder gehört nur der genannten Person. Abgelaufen? Neue Links:"
        melde "    php $VERZ/bin/kumero nutzer:liste      dann  php $VERZ/bin/kumero nutzer:link <mandant> <Nutzer-ID>"
        melde "    php $VERZ/bin/kumero verwalter:liste   dann  php $VERZ/bin/kumero verwalter:link <ID>"
        [[ "$E_NUTZER" == ja ]] || melde "  Wer bucht, bekommt eine eigene Identität – der Administrator legt sie an (Mandant → Nutzer und Rollen)."
        [[ "$E_TESTBUCH" != ja ]] || melde "  Das Buch ist ein Testbuch mit erfundenen Namen: zum Ausprobieren, nie für echte Daten."
    fi
    if [[ "$A_PAPERLESS" == ja ]]; then
        local adresse; adresse="$(paperless_wert PAPERLESS_URL)"
        echo
        melde "Paperless: $adresse"
        melde "  Erstes Konto: $(paperless_wert PAPERLESS_ADMIN_USER) – das Passwort steht in $PL_ETC/paperless.env (nur root). Nach der ersten Anmeldung ändern."
        [[ "$A_TUNNEL" == ja ]] || melde "  Zertifikat: fullchain.pem und privkey.pem für den Namen nach $PL_TLS legen, dann: systemctl reload nginx (bis dahin selbst signiert)."
        melde "  Von Hand in Paperless: je Nutzer ein Konto und ein API-Token, dazu das Tag für offene Belege."
        melde "  In kumero unter „Paperless – mein Zugang“: Adresse $adresse, das eigene Token, das Tag."
        melde "  Sicherung: jede Nacht 02:30 nach $PL_DATEN/export – von dort muss sie jemand von der Maschine wegbringen."
        melde "  Updates: nie von selbst. Erst wenn kumero die neue Version kennt: kumero-paperless pull && kumero-paperless up -d"
    fi
    if [[ -f "$SELBST" ]]; then
        echo
        warne "Diese Maschine gibt sich selbst frei: Der private Freigabeschlüssel liegt hier (Schlüsselbund von root)."
        melde "  Wer root auf dieser Maschine ist, kann jede Version einspielen. Für den Betrieb mit Daten von Mandanten den Schlüssel"
        melde "  wegbringen: gpg --armor --export-secret-keys > <Datei>, auf den eigenen Rechner holen, hier löschen (gpg --delete-secret-keys)."
        melde "  Danach wartet jedes Update auf die Signatur von dort."
    fi
    [[ ! -f /var/run/reboot-required ]] || warne "Die Maschine braucht einen Neustart (neuer Kern oder Bibliotheken): systemctl reboot"
}

# Alles in einer Funktion, damit eine halb geladene Datei nichts ausführt.
main() {
    vorab_pruefen
    antworten_laden
    fragen "$@"
    if [[ -z "$INSTALLIERT" ]]; then
        grundpakete
        schluessel_erzeugen
        quelle_holen
        system_einrichten
        schluessel_bestaetigen
        release_bauen
        signatur_abwarten
        release_einspielen
    fi
    paperless_einrichten
    erste_einrichtung
    abschluss
}

[[ -n "${KUMERO_INSTALLER_NUR_FUNKTIONEN:-}" ]] || { main "$@"; exit 0; }
