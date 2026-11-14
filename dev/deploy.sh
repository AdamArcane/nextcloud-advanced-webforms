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

# Dev only: Nextcloud serves app assets with a ~6 month max-age and a cache-buster that
# only changes with the app version, so without this a browser keeps the previous build.
ssh "$TARGET" 'cd /opt/nc-dev && docker compose exec -T app sh -c "printf \"<Location /custom_apps>\\n\\tHeader onsuccess unset Cache-Control\\n\\tHeader always set Cache-Control \\\"no-cache\\\"\\n</Location>\\n\" > /etc/apache2/conf-enabled/dev-nocache.conf"'

# The image revalidates opcache only every 60 s; reload Apache so new PHP runs at once.
ssh "$TARGET" 'cd /opt/nc-dev && docker compose exec -T app apachectl graceful'

OCC='cd /opt/nc-dev && docker compose exec -T -u www-data app php occ'
# Route outgoing mail to Mailpit (web UI on port 8025) so email actions can be tried without a real mail server.
ssh "$TARGET" "$OCC config:system:set mail_smtpmode --value=smtp && $OCC config:system:set mail_smtphost --value=mailpit && $OCC config:system:set mail_smtpport --value=1025 --type=integer && $OCC config:system:set mail_from_address --value=forms && $OCC config:system:set mail_domain --value=nc-dev.test" >/dev/null

# Nextcloud only runs an app's migrations when its version changed, and the version stays the same
# between dev deploys. Resetting the recorded version makes `upgrade` treat the app as updated, so new
# migrations apply (applied ones are skipped).
ssh "$TARGET" "$OCC config:app:set advancedforms installed_version --value=0.0.0" >/dev/null
ssh "$TARGET" "$OCC upgrade" | tail -3
ssh "$TARGET" "$OCC app:enable advancedforms && $OCC app:list | grep -A1 advancedforms"
echo "Deployed. Logs: ssh $TARGET 'cd /opt/nc-dev && docker compose logs -f app'"
