<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Dev instance on Proxmox

A throwaway Nextcloud 35 in an unprivileged LXC, for testing the app on a real
server. It runs Nextcloud, MariaDB and Mailpit (catches outgoing mail) with
Docker.

1. Copy `proxmox-nextcloud-lxc.sh` to the Proxmox host and run it as root:

   ```
   scp dev/proxmox-nextcloud-lxc.sh user@proxmox-host:/tmp/
   ssh -t user@proxmox-host 'sudo bash /tmp/proxmox-nextcloud-lxc.sh'
   ```

   Override defaults with environment variables (`CTID`, `STORAGE`, `BRIDGE`,
   `SSH_KEYS_FILE`, `NC_IMAGE`, ...). `SSH_KEYS_FILE` should point at a file of
   public keys, so `dev/deploy.sh` can log in as root in the container.

2. From the repo on your laptop, build, sync and enable the app:

   ```
   dev/deploy.sh <user>@<host ip or tailscale name>
   ```

   The current dev box is a full VM (Proxmox VM 102, `nc-dev`), not an LXC. Root
   login is disabled there; use the `youruser` user, which owns `/opt/nc-dev` and is
   in the `docker` group. Find its IP with
   `ssh user@proxmox-host 'sudo qm guest cmd 102 network-get-interfaces'`.

   ```
   dev/deploy.sh youruser@<vm ip>
   ```

3. Open `http://<container ip>/`, log in as `admin` with the printed password,
   create a form through the API (the builder UI does not exist yet) and open
   `/index.php/apps/advancedforms/f/<token>`.

Notes:

- The image tag `nextcloud:35-apache` is a guess that follows the official
  naming. If the pull fails, set `NC_IMAGE` to the right tag.
- Docker inside an unprivileged LXC needs `nesting=1,keyctl=1` (the script sets
  both). If containers refuse to start, check the Proxmox version and AppArmor.
- The container is not on Tailscale by default. Install it inside the container
  if you want a stable name.
- Real PHPUnit can be run here once the repo is in the container, since
  Packagist is reachable from your network (it is not from my workspace).
