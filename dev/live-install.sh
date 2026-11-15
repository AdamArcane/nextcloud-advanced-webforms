#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 ArcaneX
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Install the latest GitHub release of Advanced Webforms into the live Nextcloud.
# Run it on the live server as the site user (siteuser). It needs a read-only GitHub
# token for this repo in ~/.webforms-secret. Ploi cannot run it: that site has no repository.
set -euo pipefail

SITE=/home/siteuser/cloud.example.com
TOKEN_FILE=/home/siteuser/.webforms-secret
REPO=AdamArcane/nextcloud-advanced-forms
APP=webforms

chmod 600 "$TOKEN_FILE"
TOKEN="$(tr -d '[:space:]' < "$TOKEN_FILE")"

# Nextcloud root is the folder that holds occ.
ROOT=""
for D in "$SITE" "$SITE/public"; do
  if [ -f "$D/occ" ]; then ROOT="$D"; break; fi
done
[ -n "$ROOT" ] || { echo "occ not found under $SITE"; exit 1; }
echo "Nextcloud root: $ROOT"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

curl -fsS -H "Authorization: Bearer $TOKEN" -H "Accept: application/vnd.github+json" \
  "https://api.github.com/repos/$REPO/releases/latest" -o "$WORK/release.json"
TAG="$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["tag_name"])' "$WORK/release.json")"
ASSET_ID="$(python3 -c 'import json,sys;a=[x for x in json.load(open(sys.argv[1]))["assets"] if x["name"].endswith(".tar.gz")];print(a[0]["id"])' "$WORK/release.json")"
echo "Latest release: $TAG (asset $ASSET_ID)"

curl -fsSL -H "Authorization: Bearer $TOKEN" -H "Accept: application/octet-stream" \
  "https://api.github.com/repos/$REPO/releases/assets/$ASSET_ID" -o "$WORK/app.tar.gz"
tar -xzf "$WORK/app.tar.gz" -C "$WORK"
[ -f "$WORK/$APP/appinfo/info.xml" ] || { echo "Archive has no $APP/appinfo/info.xml"; exit 1; }

mkdir -p "$ROOT/custom_apps"
rm -rf "$ROOT/custom_apps/$APP"
mv "$WORK/$APP" "$ROOT/custom_apps/$APP"

cd "$ROOT"
php occ app:enable "$APP" || true
php occ upgrade
php occ app:enable "$APP"
php occ app:list | grep -A1 "$APP" || true

echo "Advanced Webforms $TAG installed."
