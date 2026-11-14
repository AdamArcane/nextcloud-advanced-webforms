#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 ArcaneX
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Build the app and sync it into the dev container, then enable it.
# Usage: dev/deploy.sh root@nc-dev      (or root@<container ip or tailscale name>)
set -euo pipefail

TARGET="${1:?usage: dev/deploy.sh user@host}"
cd "$(dirname "$0")/.."

npm run build

# Only what Nextcloud needs at runtime: no sources, tests, or node_modules.
rsync -a --delete \
	--include='/appinfo/***' --include='/lib/***' --include='/templates/***' \
	--include='/js/***' --include='/css/***' --include='/img/***' --include='/l10n/***' \
	--include='/LICENSES/***' --include='/CHANGELOG.md' --include='/README.md' \
	--exclude='*' \
	./ "$TARGET:/opt/nc-dev/custom_apps/advancedforms/"

# Docker creates custom_apps as root (bind mount parent); Nextcloud needs to write there.
ssh "$TARGET" 'cd /opt/nc-dev && docker compose exec -T app chown www-data:www-data /var/www/html/custom_apps'

# The image revalidates opcache only every 60 s; reload Apache so new PHP runs at once.
ssh "$TARGET" 'cd /opt/nc-dev && docker compose exec -T app apachectl graceful'

OCC='cd /opt/nc-dev && docker compose exec -T -u www-data app php occ'
ssh "$TARGET" "$OCC app:enable advancedforms && $OCC app:list | grep -A1 advancedforms"
echo "Deployed. Logs: ssh $TARGET 'cd /opt/nc-dev && docker compose logs -f app'"
