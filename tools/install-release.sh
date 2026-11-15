#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 ArcaneX
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Install or update Advanced Webforms from a GitHub release into a Nextcloud server.
# Run it on the server as the user that owns the Nextcloud files.
#
#   NC_ROOT=/var/www/nextcloud tools/install-release.sh
#
# NC_ROOT     Nextcloud root, the folder that holds `occ` (required).
# VERSION     Release tag to install, for example v0.3.1. Default: the latest release.
# TOKEN_FILE  File holding a GitHub token, only needed if the repository is private.
# REPO        GitHub repository. Default: AdamArcane/nextcloud-advanced-webforms.
set -euo pipefail

NC_ROOT="${NC_ROOT:?set NC_ROOT to the Nextcloud folder that holds occ}"
REPO="${REPO:-AdamArcane/nextcloud-advanced-webforms}"
VERSION="${VERSION:-latest}"
APP=webforms

[ -f "$NC_ROOT/occ" ] || { echo "occ not found in $NC_ROOT" >&2; exit 1; }

AUTH=()
if [ -n "${TOKEN_FILE:-}" ]; then
	AUTH=(-H "Authorization: Bearer $(tr -d '[:space:]' < "$TOKEN_FILE")")
fi

# Nextcloud only loads apps from the folders in `apps_paths`. A default install has no custom_apps
# entry, and enabling the app then fails with "not found on the appstore".
cd "$NC_ROOT"
if ! php occ config:system:get apps_paths >/dev/null 2>&1; then
	cat >&2 <<MSG
Nextcloud has no apps_paths setting, so it ignores custom_apps. Add it once, then run this again:

  php occ config:system:set apps_paths --type=json --value="[{\"path\":\"\$PWD/apps\",\"url\":\"/apps\",\"writable\":false},{\"path\":\"\$PWD/custom_apps\",\"url\":\"/custom_apps\",\"writable\":true}]"
MSG
	exit 1
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

if [ "$VERSION" = latest ]; then
	API="https://api.github.com/repos/$REPO/releases/latest"
else
	API="https://api.github.com/repos/$REPO/releases/tags/$VERSION"
fi
curl -fsS "${AUTH[@]}" -H "Accept: application/vnd.github+json" "$API" -o "$WORK/release.json"
TAG="$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["tag_name"])' "$WORK/release.json")"
ASSET_ID="$(python3 -c 'import json,sys;a=[x for x in json.load(open(sys.argv[1]))["assets"] if x["name"].endswith(".tar.gz")];print(a[0]["id"])' "$WORK/release.json")"
echo "Installing $TAG"

curl -fsSL "${AUTH[@]}" -H "Accept: application/octet-stream" \
	"https://api.github.com/repos/$REPO/releases/assets/$ASSET_ID" -o "$WORK/app.tar.gz"
tar -xzf "$WORK/app.tar.gz" -C "$WORK"
[ -f "$WORK/$APP/appinfo/info.xml" ] || { echo "Archive has no $APP/appinfo/info.xml" >&2; exit 1; }

mkdir -p "$NC_ROOT/custom_apps"
rm -rf "${NC_ROOT:?}/custom_apps/$APP"
mv "$WORK/$APP" "$NC_ROOT/custom_apps/$APP"

php occ app:enable "$APP" || true
php occ upgrade
php occ app:enable "$APP"
php occ app:list | grep -A1 "$APP" || true
echo "Advanced Webforms $TAG installed. Reload PHP-FPM if the old build still shows (opcache)."
