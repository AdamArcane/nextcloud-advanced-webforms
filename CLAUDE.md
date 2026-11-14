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
- Deferred: multi-page, theming, anonymous file uploads, Nextcloud Tables as an
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
- PHP: `composer install && composer test:unit` (PHPUnit 11). Real PHPUnit has
  NOT been run yet: earlier work used a stand-in runner because Packagist was
  blocked. Run it first, and commit `composer.lock`.
- `reuse lint`, and scan for em dashes before committing.
- Dev instance: `dev/` has a Proxmox setup script and `dev/deploy.sh`
  (build, rsync runtime files, enable the app). Dev VM is Proxmox VM 102 `nc-dev`
  on host `pve` (reachable over Tailscale as `user@proxmox-host`).

## Status

Done: spec v0.3, schema migration, rules engine (PHP + TS) with 75 shared
vectors, validators, services, owner and public APIs, public form renderer.

NOT verified on a real server: all PHP that touches Nextcloud (`lib/Db`,
`lib/Service`, controllers, migration), `info.xml` against the app store XSD,
routes, rate-limit attributes, the public page template. Expect small API
mistakes there. First job: get the dev instance running and fix what breaks.

Next phases: 4 owner UI for forms list and entries (+ CSV export), 5 actions
pipeline (queued jobs, retries, log; email via Nextcloud mailer, webhook with
HMAC and SSRF-safe client, message/redirect resolved synchronously), 6 builder
UI (JSON editing first), 7 hardening and release (l10n, app store packaging).
Submit currently returns `message: null, redirect: null` until Phase 5.

## Gotchas

- Field ids and option values match `^[a-z][a-z0-9_]{2,31}$` and are immutable;
  removed field ids are retired and cannot be reused.
- `ValueNormalizer` lowercases text for rule comparison only. Stored values keep
  the respondent's text (trimmed).
- Do not store `JSON_FORCE_OBJECT` entries: it would turn checkbox lists into
  objects.
