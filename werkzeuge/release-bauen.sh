#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Baut ein Release aus dem Git-Tag v<VERSION> (F73).
#
# Ergebnis in dist/:
#   kumero-<version>.tar.gz          Quellstand genau dieses Tags (git archive)
#   kumero-<version>.sha256          Prüfsumme des Archivs
#
# Genehmigung: Der Projektverantwortliche signiert die Prüfsummendatei mit
# seinem OpenPGP-Schlüssel:
#   gpg --armor --detach-sign dist/kumero-<version>.sha256
#   → dist/kumero-<version>.sha256.asc
# Ohne diese Signatur verweigert install/aktualisieren.sh die Installation.

set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

VERSION="$(cat VERSION)"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "VERSION ist keine SemVer-Version: $VERSION" >&2; exit 1; }
TAG="v$VERSION"

if [[ -n "$(git status --porcelain)" ]]; then
    echo "Arbeitsverzeichnis ist nicht sauber – erst committen." >&2; exit 1
fi
git rev-parse -q --verify "refs/tags/$TAG" >/dev/null || { echo "Tag $TAG fehlt: git tag -a $TAG -m 'kumero $VERSION'" >&2; exit 1; }
[[ "$(git rev-list -n1 "$TAG")" == "$(git rev-parse HEAD)" ]] || { echo "HEAD ist nicht $TAG." >&2; exit 1; }
grep -q "^## \[$VERSION\]" CHANGELOG.md || { echo "CHANGELOG.md hat keinen Abschnitt [$VERSION]." >&2; exit 1; }

mkdir -p dist
ARCHIV="kumero-$VERSION.tar.gz"
# Fester Zeitstempel → reproduzierbares Archiv aus demselben Tag
git -c tar.tar.gz.command="gzip -n" archive --format=tar.gz --prefix="kumero-$VERSION/" -o "dist/$ARCHIV" "$TAG"
(cd dist && sha256sum "$ARCHIV" > "kumero-$VERSION.sha256")

echo "Release gebaut:"
echo "  dist/$ARCHIV"
echo "  dist/kumero-$VERSION.sha256"
echo
echo "Zur Genehmigung signieren (nur der Projektverantwortliche):"
echo "  gpg --armor --detach-sign dist/kumero-$VERSION.sha256"
