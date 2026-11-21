#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Hashliste des Standes (CODEINTEGRITAET.md Abschnitt 4, F162): je Zeile SHA-512 und Pfad jeder Datei – außer der Liste
# selbst und ihrer Signatur. Format wie sha512sum, sortiert; prüfbar mit Bordmitteln.
#
#   bash werkzeuge/hashliste.sh erzeugen   schreibt HASHLISTE neu (vor jedem Commit eines Release)
#   bash werkzeuge/hashliste.sh pruefen    vergleicht den Stand mit HASHLISTE: jede Datei, keine zu viel, keine zu wenig
#
# Die Liste wird schon mitgeführt; ihre Signatur von außen (HASHLISTE.asc) und die Prüfung beim Einspielen sind bis zur
# Produktionsreife zurückgestellt (P46). Liegt eine Signatur daneben, nennt „pruefen“ das – es prüft sie nicht.

set -euo pipefail
export LC_ALL=C
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LISTE=HASHLISTE

# Die Dateien des Standes: im Klon, was Git führt oder führen würde; im ausgepackten Archiv alles außer vendor/
# (die Abhängigkeiten stehen nicht in Git – CODEINTEGRITAET.md CI4).
dateien() {
    if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        git ls-files -z --cached --others --exclude-standard
    else
        find . -type f -not -path './vendor/*' -printf '%P\0'
    fi | grep -zvxE "$LISTE(\.asc)?" | sort -z
}

case "${1:-}" in
    erzeugen)
        neu="$(mktemp)"                              # außerhalb des Standes, sonst stünde die Zwischendatei in der Liste
        dateien | xargs -0 -r sha512sum -- > "$neu"
        install -m 0644 "$neu" "$LISTE" && rm -f "$neu"
        echo "$LISTE geschrieben: $(wc -l < "$LISTE") Dateien, SHA-512 der Liste: $(sha512sum "$LISTE" | cut -c1-32)…"
        ;;
    pruefen)
        [[ -f "$LISTE" ]] || { echo "$LISTE fehlt." >&2; exit 1; }
        fehler=0
        sha512sum --quiet -c "$LISTE" || fehler=1
        # Vollständigkeit in beide Richtungen
        abweichung="$(diff <(dateien | tr '\0' '\n') <(sed -E 's/^[0-9a-f]{128} [ *]//' "$LISTE" | sort) || true)"
        if [[ -n "$abweichung" ]]; then
            fehler=1
            sed -n 's/^< /nicht in der Liste: /p; s/^> /fehlt im Stand:     /p' <<<"$abweichung"
        fi
        if ((fehler)); then echo "Der Stand weicht von $LISTE ab." >&2; exit 1; fi
        echo "Stand stimmt mit $LISTE überein ($(wc -l < "$LISTE") Dateien)."
        [[ -f "$LISTE.asc" ]] && echo "Signatur liegt daneben ($LISTE.asc) – hier nicht geprüft: gpgv --keyring <öffentlicher Schlüssel> $LISTE.asc $LISTE" \
            || echo "Hinweis: $LISTE trägt noch keine Signatur (zurückgestellt, P46)."
        ;;
    *)
        echo "Aufruf: bash werkzeuge/hashliste.sh erzeugen | pruefen" >&2; exit 2 ;;
esac
