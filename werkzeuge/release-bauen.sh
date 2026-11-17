#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Baut ein Release aus dem Git-Tag v<VERSION> (F73).
#
# Ergebnis in dist/:
#   kumero-<version>.tar.gz          Quellstand genau dieses Tags (git archive)
#                                    plus vendor/ nach composer.lock (E1, F77)
#   kumero-<version>.sha256          Prüfsumme des Archivs
#
# Die Abhängigkeiten kommen über Git direkt aus den Repositorys der Projekte
# (composer.json, „repositories“); composer.lock legt jede Version per Commit fest.
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
BAU="$(mktemp -d)"
trap 'rm -rf "$BAU"' EXIT
git archive --format=tar --prefix="kumero-$VERSION/" "$TAG" | tar -x -C "$BAU"
if [[ -f "$BAU/kumero-$VERSION/composer.lock" ]]; then
    (cd "$BAU/kumero-$VERSION" \
        && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --classmap-authoritative --no-interaction --no-progress --quiet \
        && COMPOSER_ALLOW_SUPERUSER=1 composer audit --locked --no-dev --no-interaction)
    # Abhängigkeiten werden aus Git geholt: Repository-Metadaten nicht mitliefern
    find "$BAU/kumero-$VERSION/vendor" -name .git -prune -exec rm -rf {} +
fi
# Fester Zeitstempel, Reihenfolge und Eigentümer → reproduzierbares Archiv
zeit="$(git log -1 --format=%ct "$TAG")"
tar --sort=name --mtime="@$zeit" --owner=0 --group=0 --numeric-owner -C "$BAU" -cf - "kumero-$VERSION" | gzip -n > "dist/$ARCHIV"
(cd dist && sha256sum "$ARCHIV" > "kumero-$VERSION.sha256")

echo "Release gebaut:"
echo "  dist/$ARCHIV"
echo "  dist/kumero-$VERSION.sha256"
echo
echo "Zur Genehmigung signieren (nur der Projektverantwortliche):"
echo "  gpg --armor --detach-sign dist/kumero-$VERSION.sha256"
