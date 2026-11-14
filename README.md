<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Advanced Forms for Nextcloud

Forms with conditional logic, grid layouts, and after-submit actions, for
Nextcloud. Your data stays on your server.

**Status: early development.** The app installs and shows an empty page. The
data format, rules engine, validation, the owner and public APIs, and the public
form page (live rules, grid layout, markdown paragraphs) are written. The
builder, entries view and after-submit actions are not built yet, and none of it
has been run on a real Nextcloud server.

## Planned for v1

- Rules engine: show, hide, or require fields based on earlier answers, with
  nested AND/OR conditions.
- Grid layout: several fields per row, responsive.
- After-submit actions: email, webhook, confirmation message, redirect.
- Public forms by link, and forms for logged-in users.

## Planned later

- Store submissions in Nextcloud Tables (optional backend).
- Multi-page forms, theming, anonymous file uploads.

## Design

The form definition format, rules semantics, action pipeline, versioning, and
submit algorithm are specified in [docs/SCHEMA.md](docs/SCHEMA.md).

## Development

Requirements: Nextcloud 35, PHP 8.3 or newer (Nextcloud 35 dropped 8.2), Node 24
or newer (a build dependency declares it; the build also ran on Node 22 with an
engine warning).

```
git clone https://github.com/AdamArcane/nextcloud-advanced-forms advancedforms
cd advancedforms
npm ci
npm run build
```

The folder name must be `advancedforms` (it has to match the app ID). Place it
in your Nextcloud `apps` or `apps-extra` directory, then enable it:

```
occ app:enable advancedforms
```

Useful commands:

- `npm run watch` rebuilds on every change.
- `npm test` runs the frontend tests, including the shared rule vectors.
- `npm run typecheck` type checks the frontend.
- `composer lint` syntax checks the PHP code.

## License

AGPL-3.0-or-later. See [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt).
