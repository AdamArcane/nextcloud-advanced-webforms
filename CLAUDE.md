<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Advanced Forms (Nextcloud app) - working notes

Gravity Forms-style forms app for Nextcloud. Brand new app, not a fork of core
Forms. Owner: Adam (ArcaneX). Private repo `AdamArcane/nextcloud-advanced-forms`.
Read `docs/SCHEMA.md` first: it is the source of truth for the data format,
rules semantics, actions, versioning and the submit algorithm.

## Fixed decisions

- App ID `advancedforms`, namespace `OCA\AdvancedForms`, DB prefix `advforms_`.
  The app ID can never change (it would become a different app).
- License AGPL-3.0-or-later, copyright holder "ArcaneX". Every file needs an
  SPDX header (`reuse lint` must pass).
- Nextcloud 35 only (min 35, max 35), PHP 8.3+, Vue 3 + @nextcloud/vue 9 + Vite.
- v1 scope: rules engine (show/hide/require, nested AND/OR), after-submit actions
  (email, webhook, message, redirect), 12-column grid layout. Any user can create
  forms. Public link only. No payments.
- File uploads are in (docs/SCHEMA.md 4.6): per field audience and storage, limited by the
  owner's own Nextcloud permissions, checked on publish and on every submission.
- Deferred: theming, Nextcloud Tables as an
  optional entry storage backend (all entry access goes through `EntryMapper`).
- Never use em dashes in any text written for Adam.
- Commits are authored as Adam (repo-local git config) with the Claude
  co-author trailer. Push to `main` is fine.

## Architecture

- `lib/Rules/` PHP rules engine (authoritative). `src/rules/` TypeScript mirror
  for live UI. Both must pass `tests/vectors/rules.json`. Change semantics by
  changing the vectors first.
- `lib/Forms/` DefinitionValidator (publish gate), FieldValueValidator,
  SubmissionValidator (server recomputes visibility, drops hidden values).
- `lib/Service/` FormService (owner side: draft, publish, status, entries),
  SubmissionService (public side). `lib/Db/` entities and mappers.
- `lib/Controller/` FormController (`/api/v1/forms`), PublicController
  (`/api/v1/public/{token}`), PublicPageController (`/f/{token}` shell). These use
  `#[FrontpageRoute]` and plain `Controller`. `#[ApiRoute]` is for OCS only.
- `src/components/render/` public renderer. Paragraph markdown is parsed to an
  AST and rendered as elements, never raw HTML.
- Drafts save leniently; publish is the validation gate. Published versions are
  immutable; entries store the version id.

## Commands

- `npm ci`, `npm run build`, `npm test` (vitest, includes component tests),
  `npm run typecheck`
- PHP: `composer install && composer test:unit` (PHPUnit 11). Real PHPUnit has run:
  236 tests pass on PHP 8.5 (`composer.lock` is committed). `composer` is not installed
  system-wide here; a checked `composer.phar` works fine.
- CI: `.github/workflows/ci.yml` runs typecheck, vitest and build, PHP lint and PHPUnit on
  8.3 and 8.4, `reuse lint`, and an em dash scan of the Markdown docs.
- `reuse lint`, and scan for em dashes before committing.
- Dev instance: `dev/` has a Proxmox setup script and `dev/deploy.sh`
  (build, rsync runtime files, enable the app). Dev VM is Proxmox VM 102 `nc-dev`
  on host `pve` (reachable over Tailscale as `user@proxmox-host`).

## Status

Done: spec v0.3, schema migration, rules engine (PHP + TS) with 79 shared vectors,
validators, services, owner and public APIs, public renderer, owner UI (forms list,
responses, delivery log, CSV export), actions pipeline (email, webhook, message, redirect,
retries), builder (fields, logic, actions, settings, multi-page, design tab, drag and drop
with live preview, row moves, resize handles, text alignment, hide required indicator).

Verified on the dev instance by hand (deploy with `dev/deploy.sh user@dev-host`;
`user@proxmox-host` is the Proxmox host and is the wrong target). Not yet verified: `info.xml`
against the app store XSD, a fresh install migration, rate-limit attributes under load.

Phase 7 so far: security pass done (account deletion now cleans up forms and responses),
`info.xml` validates against the app store XSD, translation tooling (`npm run l10n:extract`,
`l10n:compile`) and `tools/package.sh` for the release archive. Release steps are in
`docs/RELEASING.md`. Remaining: app store registration and signing, screenshots, and any
actual translations. Touch dragging is not supported
(the move buttons in the field panel cover it). Submit message and redirect are resolved
synchronously; see `docs/SCHEMA.md`.

## Gotchas

- Field ids and option values match `^[a-z][a-z0-9_]{2,31}$` and are immutable;
  removed field ids are retired and cannot be reused.
- `ValueNormalizer` lowercases text for rule comparison only. Stored values keep
  the respondent's text (trimmed).
- Do not store `JSON_FORCE_OBJECT` entries: it would turn checkbox lists into
  objects.
