<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Advanced Webforms for Nextcloud

A form builder for Nextcloud with the features larger form tools have: conditional logic,
grid layouts, multi-page forms, file uploads and after-submit actions. Forms and responses stay
on your own server.

It supports **Nextcloud 34 and 35**. Install it from the Nextcloud app store, or from a GitHub
release (see [Install](#install)). Please back up before you update.

## Features

- **Builder.** Drag and drop fields on a 12-column grid with a live preview. Text, long text, email, phone,
  website, number, date, time, select, radio, checkboxes, rating, scale, consent, hidden fields,
  file upload, plus headings, paragraphs, images and dividers. Label position can be
  above, beside or hidden, for the whole form or per field. Fields can be filled in from the link.
- **Rules.** Show, hide or require fields based on earlier answers, with nested AND/OR groups.
  Rules run live in the browser and are checked again on the server.
- **Multi-page forms** with Back and Next. Required fields are checked per page, and pages whose
  fields are all hidden by rules are skipped. An optional summary step lets people review their
  answers before sending, and an optional "continue later" keeps unsent answers in the respondent's
  own browser (nothing is stored on the server).
- **After-submit actions.** Confirmation message, redirect, email, signed webhooks (HMAC-SHA256), a
  row in a Nextcloud Tables table, and a line in a CSV file in your Files. Emails, webhooks, Tables
  rows and file lines are sent in the background with retries, and each action can have conditions.
- **Flow.** Submissions, deleted responses, publishing and status changes are Nextcloud events and a
  "Webforms" trigger in Flow, so other apps can react to them.
- **File uploads.** Per field limits for file count, size and type, and a choice of where files go: a
  folder in the form owner's Files, or the app's own storage. Executable and web page types are
  never accepted.
- **Administration.** Server-wide settings for which actions forms may use, how long responses are kept,
  limits per form, and who can create forms.
- **Spam protection** for anonymous visitors: a built-in proof-of-work check (default, nothing
  leaves your server), Cloudflare Turnstile, hCaptcha, or none. A hidden trap field, a minimum fill
  time and rate limits are always on.
- **Responses.** New responses are marked for you. Browse and filter answers, give each a status and
  a note, see the delivery log for each email and webhook with a plain
  explanation of failures, retry failed deliveries, and download a CSV. Settings in the sidebar hold your defaults, and a
  backup button downloads all your forms.
- **Versioning.** Published versions are immutable, and each response records the version it was
  submitted against. Drafts autosave.
- **Import and export** a whole form as a file (definition only: no responses or secrets), and
  **archive** forms you no longer use.
- **Sharing.** Public forms by link (`/apps/webforms/f/<token>`), with an optional custom address, a QR
  code, and code to embed the form in another website. Any user can create forms.

Not included: payments, calculations, PDF generation, theming and custom CSS. Sending responses to
Nextcloud Tables is an after-submit action, not a storage backend (see the roadmap).

## Requirements

- Nextcloud 34 or 35
- PHP 8.3 or newer
- Shell access to the server, to run `occ`

## Install

From the app store: open **Apps** in Nextcloud, find **Advanced Webforms** and enable it.

From a GitHub release: download `webforms-<version>.tar.gz` from the
[releases page](https://github.com/AdamArcane/nextcloud-advanced-webforms/releases), then:

```bash
tar -xzf webforms-<version>.tar.gz -C /path/to/nextcloud/custom_apps/
sudo -u www-data php /path/to/nextcloud/occ app:enable webforms
```

The folder must be named `webforms` (it has to match the app ID). Nextcloud only loads apps from
the folders in its `apps_paths` setting. If `custom_apps` is not one of them, enabling fails with
"not found on the appstore". Add it once:

```bash
cd /path/to/nextcloud
sudo -u www-data php occ config:system:set apps_paths --type=json \
  --value="[{\"path\":\"$PWD/apps\",\"url\":\"/apps\",\"writable\":false},{\"path\":\"$PWD/custom_apps\",\"url\":\"/custom_apps\",\"writable\":true}]"
```

To update, replace the folder with the new release and run `occ upgrade`.
[tools/install-release.sh](tools/install-release.sh) does all of this from a GitHub release:

```bash
NC_ROOT=/path/to/nextcloud tools/install-release.sh
```

Emails and webhooks are sent by Nextcloud's background jobs, so make sure cron is set up
(Administration settings, Basic settings).

## Using it

Open **Webforms** from the Nextcloud menu, create a form, build it, and publish it. Publishing is
where the form is validated. The share link is `https://<your server>/index.php/apps/webforms/f/<token>`.

## Administration

Administrators find the server-wide settings under **Administration settings, Webforms**:

- which after-submit actions forms may use (email, webhook, Tables, Files),
- the spam check for the whole server (built-in proof-of-work by default, Cloudflare Turnstile,
  hCaptcha, or none),
- how long responses are kept, limits per form, and who can create forms,
- the list of response statuses.

Emails and webhooks need Nextcloud cron to be working. Forms and responses are stored in the
Nextcloud database, and uploaded files in the form owner's Files or in the app's own storage.

## Documentation

- [docs/SCHEMA.md](docs/SCHEMA.md): the form definition format, rules semantics, action pipeline,
  versioning and submit algorithm.
- [docs/ROADMAP.md](docs/ROADMAP.md): what is planned and why, and what is missing compared with
  other form builders.
- [docs/RELEASING.md](docs/RELEASING.md): building and installing a release.
- [CHANGELOG.md](CHANGELOG.md)

## Development

You need Nextcloud 34 or 35, PHP 8.3 or newer and Node 24 or newer.

```bash
git clone https://github.com/AdamArcane/nextcloud-advanced-webforms webforms
cd webforms
npm ci
npm run build
```

Put the folder (named `webforms`) in your Nextcloud `apps` or `custom_apps` directory and run
`occ app:enable webforms`.

- `npm run watch` rebuilds on every change.
- `npm test` runs the frontend tests, including the shared rule vectors.
- `npm run typecheck` type checks the frontend.
- `composer install && composer test:unit` runs the PHP tests, and `composer lint` checks syntax.
- `dev/` has a script for a throwaway Nextcloud on Proxmox and `dev/deploy.sh` to sync a build to it.
  See [dev/README.md](dev/README.md).

The rules engine exists twice, in PHP (authoritative) and TypeScript (live UI), and both must pass
`tests/vectors/rules.json`. Change rule semantics by changing the vectors first.

## Reporting problems

Open an [issue](https://github.com/AdamArcane/nextcloud-advanced-webforms/issues). For security
problems, please do not post details publicly. Open an issue that asks for a private way to
report it, without describing the problem.

## License

AGPL-3.0-or-later. See [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt).
