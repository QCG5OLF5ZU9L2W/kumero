#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Baut das Release des höchsten Versions-Tags von origin – auf der Maschine des Projektverantwortlichen,
# in einem eigenen, sauberen Klon (UEBERGABE.md Abschnitt 2a). Signiert wird hier nichts.
#
# Aufruf, im Klon:   bash werkzeuge/release-vom-tag.sh
#
# Das Skript wird aus dem Arbeitszweig aufgerufen und wechselt dann auf den Tag. Gebaut wird mit dem
# werkzeuge/release-bauen.sh **des Tags** – also genau dem Stand, der freigegeben wird.

set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

[[ -z "$(git status --porcelain)" ]] || { echo "Abbruch: Lokale Änderungen vorhanden." >&2; exit 1; }

git fetch origin --tags

release_tag="$(
    git ls-remote --tags --refs origin |
        awk '{sub("refs/tags/", "", $2); print $2}' |
        { grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' || true; } |
        sort -V |
        tail -n 1
)"
[[ -n "$release_tag" ]] || { echo "Keine Releaseversion gefunden." >&2; exit 1; }

git switch --detach "$release_tag"
bash werkzeuge/release-bauen.sh

echo "Gebaut: $release_tag"
