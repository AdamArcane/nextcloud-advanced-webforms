#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 ArcaneX
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Build a release tarball for the Nextcloud app store: dist/webforms-<version>.tar.gz
# with a single top-level `webforms/` folder holding only what runs on the server.
# Signing and uploading are separate steps, see docs/RELEASING.md.
set -euo pipefail

cd "$(dirname "$0")/.."

VERSION="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml | head -1)"
[ -n "$VERSION" ] || { echo 'No <version> in appinfo/info.xml' >&2; exit 1; }
grep -q "^## $VERSION\b" CHANGELOG.md || { echo "CHANGELOG.md has no section for $VERSION" >&2; exit 1; }

npm ci
npm run l10n:compile
# The build does not clean its output folders, so chunks from earlier builds would ship too.
rm -rf js css
npm run build

OUT="dist/webforms"
rm -rf dist
mkdir -p "$OUT"

# Runtime files only: no sources, tests, tooling or node_modules. Source maps are left out too.
rsync -a --exclude='*.map' \
	--include='/appinfo/***' --include='/lib/***' --include='/templates/***' \
	--include='/js/***' --include='/css/***' --include='/img/***' --include='/l10n/***' \
	--include='/LICENSES/***' --include='/CHANGELOG.md' --include='/README.md' \
	--exclude='*' \
	./ "$OUT/"

tar -C dist -czf "dist/webforms-$VERSION.tar.gz" webforms
echo "Built dist/webforms-$VERSION.tar.gz"
tar -tzf "dist/webforms-$VERSION.tar.gz" | head -n 20
