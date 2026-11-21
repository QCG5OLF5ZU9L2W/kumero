#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Bringt die Installation auf dieser Maschine auf die neueste veröffentlichte Version (INSTALL.md Abschnitt 3a, F144).
#
# Aufruf als root, im lesenden Klon:   bash werkzeuge/update-neueste.sh
#
# Ein Lauf macht, was sonst vier Blöcke aus INSTALL.md sind – und fragt, wo ein Mensch entscheiden muss:
#   0. Pakete des Systems (Debian, PostgreSQL, PHP): Stehen Updates an, fragt das Skript, ob es sie einspielt (Vorgabe ja).
#      Am Ende sagt es, ob ein Neustart der Maschine nötig ist – es startet nie selbst neu.
#   1. Stand von GitHub holen, die höchste Version bauen (werkzeuge/release-vom-tag.sh). Nur lesend.
#   2. Signatur: Fehlt sie, fragt das Skript, ob hier signiert werden soll – die Signatur ist die Freigabe (F73) und
#      geschieht nie von selbst. Ohne geheimen Schlüssel auf dieser Maschine nennt es, was zu tun ist, und endet.
#   3. Sicherung aller Datenbanken von kumero (Frage; Vorgabe ja).
#   4. install/aktualisieren.sh der installierten Version – es prüft Signatur und Prüfsumme, zeigt die Migrationen und
#      fragt die Versionsnummer ab. Aktive Module zieht es seit 0.7.49 selbst nach.
#   5. Frage: Sollen überall die Module eingeschaltet werden, die noch nie eingeschaltet waren (bin/kumero modul:alle)?
#      Was jemand ausgeschaltet hat, bleibt aus.
#
# Steht im Zweig schon eine Version, die noch keinen Tag trägt, sagt das Skript das am Anfang und am Ende.
#
# Das Skript fragt nie nach Zugangsdaten und schreibt nichts nach GitHub. Tags setzt weiter der Projektverantwortliche.

set -euo pipefail

melde()   { printf '\033[1;34m[kumero]\033[0m %s\n' "$*"; }
abbruch() { printf '\033[1;31m[kumero] ABBRUCH:\033[0m %s\n' "$*" >&2; exit 1; }
# Frage mit Vorgabe: frage "Text" j|n  → Rückgabe 0 bei ja
frage() {
    local antwort vorgabe="$2"
    read -r -p "$(printf '\033[1;34m[kumero]\033[0m %s [%s] ' "$1" "$([[ $vorgabe == j ]] && echo 'J/n' || echo 'j/N')")" antwort </dev/tty || antwort=""
    antwort="${antwort:-$vorgabe}"
    [[ "$antwort" =~ ^[jJyY] ]]
}

# Pakete des Systems auf den Stand bringen. Vor dem Update von kumero, damit Sicherung und Migrationen schon mit der neuen
# Datenbank- und PHP-Version laufen. Immer als full-upgrade (apt-get dist-upgrade, F159): Es holt, was ein Update neu braucht,
# und darf dafür auch Pakete entfernen – so bleibt nichts zurückgehalten.
systempakete() {
    command -v apt-get >/dev/null || { melde "Kein apt-get – Pakete des Systems bleiben, wie sie sind."; return 0; }
    melde "Prüfe die Pakete des Systems"
    apt-get update -qq || { melde "ACHTUNG: Die Paketlisten ließen sich nicht holen – Pakete des Systems bleiben, wie sie sind."; return 0; }
    local n
    n="$(apt-get -s dist-upgrade 2>/dev/null | grep -c '^Inst ' || true)"
    if [[ "$n" == 0 ]]; then
        melde "Pakete des Systems sind aktuell."
        return 0
    fi
    if frage "$n Pakete des Systems lassen sich aktualisieren. Jetzt einspielen?" j; then
        apt-get -y dist-upgrade || abbruch "Die Pakete ließen sich nicht einspielen – kumero ist unverändert. Erst das klären (apt full-upgrade), dann erneut starten."
        n="$(apt-get -s dist-upgrade 2>/dev/null | grep -c '^Inst ' || true)"
        [[ "$n" == 0 ]] && melde "Pakete des Systems sind aktuell." || melde "ACHTUNG: $n Pakete sind weiter offen – ansehen mit: apt list --upgradable"
    else
        melde "Pakete des Systems bleiben, wie sie sind ($n offen)."
    fi
}

# Alles in einer Funktion: Das Skript wechselt beim Bauen auf den Tag, und bash liest eine Datei, die sich unter ihm
# ändert, sonst mitten im Lauf neu.
main() {
    [[ $EUID -eq 0 ]] || abbruch "Bitte als root ausführen."
    local zweig="release-0.7.0" stand="${KUMERO_STAND:-/var/lib/kumero/installiert}" releases="${KUMERO_RELEASES:-/var/lib/kumero/releases}"
    local verz="${KUMERO_VERZ:-/var/www/kumero}"
    local klon
    klon="$(git rev-parse --show-toplevel 2>/dev/null)" || abbruch "Im Klon des Repositorys aufrufen (z. B. /root/kumero-release)."
    cd "$klon"
    [[ -z "$(git status --porcelain)" ]] || abbruch "Der Klon hat lokale Änderungen – erst aufräumen (git status)."
    [[ -f "$stand" ]] || abbruch "Hier ist kumero nicht installiert ($stand fehlt) – für eine neue VM gilt INSTALL.md Abschnitt 2."
    local installiert; installiert="$(cat "$stand")"

    # 0. Pakete des Systems
    systempakete

    # 1. Neueste Version feststellen
    git switch -q "$zweig"
    git pull -q --ff-only
    local neu
    neu="$(git ls-remote --tags --refs origin | awk '{sub("refs/tags/", "", $2); print $2}' | { grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' || true; } | sort -V | tail -n 1)"
    [[ -n "$neu" ]] || abbruch "Auf GitHub steht kein Versions-Tag."
    neu="${neu#v}"
    melde "Installiert: $installiert   Neueste Version: $neu"
    # Im Zweig kann schon mehr stehen, als veröffentlicht ist: Installiert wird nur, was einen Tag trägt.
    local imzweig ohnetag=""; imzweig="$(tr -d '[:space:]' < VERSION 2>/dev/null || true)"
    if [[ -n "$imzweig" && "$imzweig" != "$neu" && "$(printf '%s\n%s\n' "$imzweig" "$neu" | sort -V | tail -n 1)" == "$imzweig" ]]; then
        ohnetag="$imzweig"
        melde "ACHTUNG: Im Zweig steht schon $imzweig – dafür gibt es auf GitHub noch keinen Tag. Installiert wird $neu."
    fi
    local aktuell=0
    if [[ "$installiert" == "$neu" ]]; then
        aktuell=1
        melde "Die neueste Version ist schon installiert."
    elif [[ "$(printf '%s\n%s\n' "$installiert" "$neu" | sort -V | tail -n 1)" != "$neu" ]]; then
        abbruch "Installiert ist $installiert, das ist neuer als $neu – nichts zu tun. (Fehlt ein Tag auf GitHub?)"
    fi

    if ((!aktuell)); then
        # 2. Bauen, falls das Archiv nicht schon da ist
        local archiv="$klon/dist/kumero-$neu.tar.gz" summe="$klon/dist/kumero-$neu.sha256"
        if [[ -f "$archiv" && -f "$summe" ]]; then
            melde "Archiv ist schon gebaut: $archiv"
        else
            melde "Baue Version $neu"
            bash werkzeuge/release-vom-tag.sh
            git switch -q "$zweig"
            [[ -f "$archiv" && -f "$summe" ]] || abbruch "Nach dem Bauen fehlt $archiv."
        fi

        # 3. Signatur – die Freigabe (F73)
        if [[ -f "$summe.asc" ]]; then
            melde "Signatur liegt vor: $(basename "$summe.asc")"
        elif gpg --list-secret-keys --with-colons 2>/dev/null | grep -q '^sec'; then
            melde "Mit der Signatur gibst du Version $neu für diese Installation frei."
            frage "Jetzt hier signieren?" n || abbruch "Nicht signiert – nichts geändert. Später: bash werkzeuge/update-neueste.sh"
            (cd "$klon/dist" && gpg --armor --detach-sign "kumero-$neu.sha256")
        else
            melde "Auf dieser Maschine liegt kein geheimer Schlüssel (so soll es auf einem Server sein)."
            melde "Auf dem Rechner mit dem Schlüssel:  gpg --armor --detach-sign kumero-$neu.sha256"
            melde "Dazu die Datei $summe dorthin holen und kumero-$neu.sha256.asc nach $klon/dist/ zurücklegen, dann dieses Skript erneut starten."
            exit 2
        fi

        # 4. Sicherung
        if frage "Vorher alle Datenbanken von kumero sichern?" j; then
            local ziel="/root/kumero-sicherung/$installiert-$(date +%Y%m%d-%H%M%S)" db
            install -d -m 0700 "$ziel"
            while read -r db; do
                [[ -n "$db" ]] || continue
                pg_dump -U "${KUMERO_DB_USER:-kumero_owner}" -Fc "$db" > "$ziel/$db.dump"
                melde "gesichert: $db"
            done < <(psql -X -qtA -U "${KUMERO_DB_USER:-kumero_owner}" -d postgres -c "
                SELECT d.datname FROM pg_database d JOIN pg_roles r ON r.oid = d.datdba
                 WHERE r.rolname = 'kumero_owner' AND d.datname NOT LIKE 'kumero_test%' ORDER BY d.datname")
            melde "Sicherung liegt in $ziel"
        else
            melde "Ohne Sicherung weiter."
        fi

        # 5. Aktualisieren – mit dem Skript der installierten Version; es übergibt an das der neuen
        local skript="$releases/$installiert/install/aktualisieren.sh"
        [[ -f "$skript" ]] || abbruch "$skript fehlt – von Hand nach INSTALL.md Abschnitt 3."
        bash "$skript" "$archiv"
        [[ "$(cat "$stand")" == "$neu" ]] || abbruch "Nach dem Update steht $(cat "$stand") statt $neu – siehe Ausgabe oben."
    fi

    # 6. Module
    local kumero=(php "$verz/bin/kumero")
    if "${kumero[@]}" list 2>/dev/null | grep -q 'modul:nachziehen'; then
        melde "Aktive Module nachziehen"
        "${kumero[@]}" modul:nachziehen || melde "ACHTUNG: Nicht jedes Modul ließ sich nachziehen – siehe oben."
    fi
    if "${kumero[@]}" list 2>/dev/null | grep -q 'modul:alle'; then
        melde "Stand der Module:"
        "${kumero[@]}" modul:liste || true
        if frage "Überall die Module einschalten, die noch nie eingeschaltet waren? (Ausgeschaltetes bleibt aus)" n; then
            "${kumero[@]}" modul:alle || melde "ACHTUNG: Nicht jedes Modul ließ sich einschalten – siehe oben."
        else
            melde "Module bleiben, wie sie sind. Einzeln geht es in der Oberfläche unter Mandant → Module."
        fi
    else
        melde "Diese Version kennt „modul:alle“ noch nicht – Module in der Oberfläche unter Mandant → Module einschalten."
    fi
    melde "Fertig: kumero $(cat "$stand"). Im Browser neu laden."
    [[ -z "$ohnetag" ]] || melde "Nicht der neueste Stand: $ohnetag wartet auf seinen Tag (v$ohnetag, auf dem Rechner mit Schreibzugang) – danach dieses Skript erneut starten."
    [[ ! -f /var/run/reboot-required ]] || melde "ACHTUNG: Die Maschine braucht einen Neustart (neuer Kern oder Bibliotheken) – bei Gelegenheit: systemctl reboot"
}

main "$@"
exit 0
