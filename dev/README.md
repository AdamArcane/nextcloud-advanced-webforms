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
   scp dev/proxmox-nextcloud-lxc.sh <user>@<proxmox host>:/tmp/
   ssh -t <user>@<proxmox host> 'sudo bash /tmp/proxmox-nextcloud-lxc.sh'
   ```

   Override defaults with environment variables (`CTID`, `STORAGE`, `BRIDGE`,
   `SSH_KEYS_FILE`, `NC_IMAGE`, ...). `SSH_KEYS_FILE` should point at a file of
   public keys, so `dev/deploy.sh` can log in to the container.

2. From the repo on your laptop, build, sync and enable the app:

   ```
   dev/deploy.sh <user>@<container or VM address>
   ```

   The login user must own `/opt/nc-dev` and be able to run `docker compose`
   (for example be in the `docker` group). The script also works against a full VM
   instead of an LXC, as long as it has the same `/opt/nc-dev` Docker layout.

3. Open `http://<container ip>/`, log in as `admin` with the printed password,
   enable the app if needed, and create a form from the Webforms menu.

Notes:

- The image tag `nextcloud:35-apache` is a guess that follows the official
  naming. If the pull fails, set `NC_IMAGE` to the right tag.
- Docker inside an unprivileged LXC needs `nesting=1,keyctl=1` (the script sets
  both). If containers refuse to start, check the Proxmox version and AppArmor.
- The container is not on Tailscale by default. Install it inside the container
  if you want a stable name.
