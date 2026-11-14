#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 ArcaneX
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Creates an unprivileged Debian LXC on a Proxmox host and runs a Nextcloud 35
# dev instance in it with Docker (Nextcloud + MariaDB + Mailpit).
#
# Run as root ON THE PROXMOX HOST:
#   bash proxmox-nextcloud-lxc.sh
#
# Every setting can be overridden from the environment, for example:
#   CTID=210 STORAGE=local-zfs BRIDGE=vmbr0 SSH_KEYS_FILE=/root/.ssh/authorized_keys bash proxmox-nextcloud-lxc.sh
set -euo pipefail

CTID="${CTID:-$(pvesh get /cluster/nextid)}"
CT_NAME="${CT_NAME:-nc-dev}"
STORAGE="${STORAGE:-local-lvm}"                    # where the container disk lives
TEMPLATE_STORAGE="${TEMPLATE_STORAGE:-local}"      # where OS templates are kept
BRIDGE="${BRIDGE:-vmbr0}"
DISK_GB="${DISK_GB:-16}"
CORES="${CORES:-2}"
MEMORY_MB="${MEMORY_MB:-3072}"
SWAP_MB="${SWAP_MB:-512}"
NC_IMAGE="${NC_IMAGE:-nextcloud:35-apache}"
SSH_KEYS_FILE="${SSH_KEYS_FILE:-}"                 # public keys allowed to log in as root in the container

say() { printf '\n==> %s\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { echo "Run as root on the Proxmox host." >&2; exit 1; }
command -v pct >/dev/null || { echo "pct not found: this must run on the Proxmox host." >&2; exit 1; }
if pct status "$CTID" >/dev/null 2>&1; then echo "Container $CTID already exists." >&2; exit 1; fi

say "Finding a Debian 13 template"
pveam update >/dev/null
TEMPLATE="$(pveam available --section system | awk '/debian-13-standard/ {print $2}' | sort -V | tail -n1)"
[ -n "$TEMPLATE" ] || { echo "No debian-13-standard template offered by pveam." >&2; exit 1; }
if ! pveam list "$TEMPLATE_STORAGE" | grep -q "$TEMPLATE"; then
	pveam download "$TEMPLATE_STORAGE" "$TEMPLATE"
fi

say "Creating container $CTID ($CT_NAME)"
KEY_ARGS=()
[ -n "$SSH_KEYS_FILE" ] && KEY_ARGS=(--ssh-public-keys "$SSH_KEYS_FILE")
pct create "$CTID" "$TEMPLATE_STORAGE:vztmpl/$TEMPLATE" \
	--hostname "$CT_NAME" \
	--cores "$CORES" --memory "$MEMORY_MB" --swap "$SWAP_MB" \
	--rootfs "$STORAGE:$DISK_GB" \
	--net0 "name=eth0,bridge=$BRIDGE,ip=dhcp" \
	--unprivileged 1 --features nesting=1,keyctl=1 \
	--onboot 1 --tags nextcloud-dev \
	"${KEY_ARGS[@]}"
pct start "$CTID"

say "Waiting for network"
for _ in $(seq 1 30); do
	pct exec "$CTID" -- ping -c1 -W2 deb.debian.org >/dev/null 2>&1 && break
	sleep 2
done
pct exec "$CTID" -- ping -c1 -W2 deb.debian.org >/dev/null 2>&1 || { echo "Container has no network." >&2; exit 1; }

say "Installing Docker and SSH inside the container"
pct exec "$CTID" -- bash -euo pipefail -c '
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq ca-certificates curl gnupg openssh-server rsync >/dev/null
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
. /etc/os-release
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $VERSION_CODENAME stable" > /etc/apt/sources.list.d/docker.list
apt-get update -qq
apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-compose-plugin >/dev/null
systemctl enable --now docker ssh
'

say "Writing the Nextcloud stack"
pw() { tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24; }
ADMIN_PW="$(pw)"; DB_PW="$(pw)"; DB_ROOT_PW="$(pw)"
CT_IP="$(pct exec "$CTID" -- hostname -I | awk '{print $1}')"

pct exec "$CTID" -- mkdir -p /opt/nc-dev/custom_apps/advancedforms
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
cat > "$WORK/.env" <<ENV
NC_IMAGE=$NC_IMAGE
ADMIN_PW=$ADMIN_PW
DB_PW=$DB_PW
DB_ROOT_PW=$DB_ROOT_PW
TRUSTED_DOMAINS=$CT_NAME $CT_NAME.local $CT_IP localhost
ENV
cat > "$WORK/compose.yml" <<'YML'
services:
  db:
    image: mariadb:11
    restart: unless-stopped
    command: --transaction-isolation=READ-COMMITTED --log-bin=binlog --binlog-format=ROW
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PW}
      MYSQL_DATABASE: nextcloud
      MYSQL_USER: nextcloud
      MYSQL_PASSWORD: ${DB_PW}
    volumes:
      - db:/var/lib/mysql

  app:
    image: ${NC_IMAGE}
    restart: unless-stopped
    depends_on: [db]
    ports:
      - "80:80"
    environment:
      MYSQL_HOST: db
      MYSQL_DATABASE: nextcloud
      MYSQL_USER: nextcloud
      MYSQL_PASSWORD: ${DB_PW}
      NEXTCLOUD_ADMIN_USER: admin
      NEXTCLOUD_ADMIN_PASSWORD: ${ADMIN_PW}
      NEXTCLOUD_TRUSTED_DOMAINS: ${TRUSTED_DOMAINS}
      PHP_MEMORY_LIMIT: 512M
    volumes:
      - nextcloud:/var/www/html
      # The app under development, synced here by dev/deploy.sh.
      - ./custom_apps/advancedforms:/var/www/html/custom_apps/advancedforms

  # Catches outgoing mail so email actions can be tested. Web UI on port 8025.
  mailpit:
    image: axllent/mailpit
    restart: unless-stopped
    ports:
      - "8025:8025"

volumes:
  db:
  nextcloud:
YML
pct push "$CTID" "$WORK/.env" /opt/nc-dev/.env --perms 0600
pct push "$CTID" "$WORK/compose.yml" /opt/nc-dev/compose.yml --perms 0644

say "Starting Nextcloud (first start installs it, this takes a few minutes)"
pct exec "$CTID" -- bash -c 'cd /opt/nc-dev && docker compose up -d'
READY=""
for _ in $(seq 1 90); do
	if pct exec "$CTID" -- bash -c 'cd /opt/nc-dev && docker compose exec -T -u www-data app php occ status 2>/dev/null' | grep -q 'installed: true'; then
		READY=1; break
	fi
	sleep 5
done

if [ -n "$READY" ]; then
	STATUS_LINE="Nextcloud reported installed."
else
	STATUS_LINE="Nextcloud is not reporting installed yet. Check: pct exec $CTID -- docker compose -f /opt/nc-dev/compose.yml logs app"
fi

say "Result"
pct exec "$CTID" -- bash -c 'cd /opt/nc-dev && docker compose exec -T -u www-data app php occ status' || true
cat <<DONE

Container:   $CTID ($CT_NAME), IP $CT_IP
Nextcloud:   http://$CT_IP/        login: admin / $ADMIN_PW
Mailpit:     http://$CT_IP:8025/   (SMTP host "mailpit", port 1025, no auth, from inside Nextcloud)
Credentials: /opt/nc-dev/.env inside the container
$STATUS_LINE

Next, from your laptop in the repo:  dev/deploy.sh root@$CT_IP
DONE
