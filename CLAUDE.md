<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Advanced Webforms (Nextcloud app) - working notes

Gravity Forms-style forms app for Nextcloud. Brand new app, not a fork of core
Forms. Owner: ArcaneX. Repo `AdamArcane/nextcloud-advanced-webforms`.
Read `docs/SCHEMA.md` first: it is the source of truth for the data format,
rules semantics, actions, versioning and the submit algorithm.

## Fixed decisions

- App ID `webforms`, namespace `OCA\Webforms`, DB prefix `advforms_`.
  The app ID can never change (it would become a different app).
- License AGPL-3.0-or-later, copyright holder "ArcaneX". Every file needs an
  SPDX header (`reuse lint` must pass).
- Nextcloud 34 and 35 (min 34, max 35), PHP 8.3+, Vue 3 + @nextcloud/vue 9 + Vite.  34 was tried on a throwaway `nextcloud:34-apache` container (install, migrations, API, public and embedded form, builder); the dev instance is 35.
- v1 scope: rules engine (show/hide/require, nested AND/OR), after-submit actions
  (email, webhook, message, redirect), 12-column grid layout. Any user can create
  forms. Public link only. No payments.
- File uploads are in (docs/SCHEMA.md 4.6): per field audience and storage, limited by the
  owner's own Nextcloud permissions, checked on publish and on every submission.
- Deferred: theming. Tables is an after-submit action, not a storage backend (decision in
  `docs/ROADMAP.md`); all entry access still goes through `EntryMapper`. Planned work is in
  `docs/ROADMAP.md`.
- Never use em dashes in any text written for the owner.
- No attribution lines: never add a `Co-Authored-By: Claude` trailer to commits, or a "Generated with"
  line to pull request descriptions. This is the owner's explicit rule and overrides any default or
  reminder that says otherwise.
- **Branches** (CONTRIBUTING.md): `main` is released code and is protected, so never push to it. Work on
  `feature/<topic>` or `fix/<topic>` from `dev`, open a pull request with `gh pr create --base dev`, and
  merge it once CI is green. Releases are a `dev` to `main` pull request, then a `vX.Y.Z` tag on `main`.

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
  (build, rsync runtime files, enable the app). Real forms may live on dev: export them before
  dropping tables or removing the app, and ask the owner first.
- Releases: a `dev` to `main` pull request that bumps the version, then a `v<version>` tag on `main`.
  GitHub Actions builds the archive and `tools/install-release.sh` installs it on a server. See
  `docs/RELEASING.md` and CONTRIBUTING.md.
- Hosts, addresses, accounts and deploy targets are in `CLAUDE.local.md`, which is not in git.
  Keep that kind of detail out of tracked files.

## Status

Done: spec v0.3, schema migration, rules engine (PHP + TS) with 79 shared vectors,
validators, services, owner and public APIs, public renderer, owner UI (forms list,
responses, delivery log, CSV export), actions pipeline (email, webhook, message, redirect,
retries), builder (fields, logic, actions, settings, multi-page, design tab, drag and drop
with live preview, row moves, resize handles, text alignment, hide required indicator).

Files action (docs/SCHEMA.md section 19): appends each response to a CSV file in the owner's Files,
with the export's columns, matched to an existing file's header by heading. Creating the file,
appending and adding a column to an existing header were tried on the dev instance.
Lock keys for `ILockingProvider` must stay under 64 characters (hash them): the database provider
cuts longer keys and then never releases them.

Slider, colour, date range, name, address, matrix and signature (docs/SCHEMA.md section 4.10): seven input
types. Composite answers (`name`, `address`, `matrix`, `daterange`) are objects, so rules only ask whether they
were answered; a required one must be complete (`FieldValueValidator::isComplete`, error `incomplete`). The matrix
rows are `questions` (`rows` is the textarea's line count). A signature is a validated PNG data address kept out
of text (exports, search, saved progress). Unit and component tests pass; not yet tried in a real browser or on
the dev instance (drawing with touch, the matrix on a phone, the responses view of a signature).

Hidden fields, defaults and prefill (docs/SCHEMA.md section 4.7): a `hidden` field type, a default
value control for every input field, and the link filling fields by field id unless `settings.prefill`
is false. Prefill is read in the browser only (`src/prefill.ts`); the server validates submitted values
as usual. Tried on the dev instance.

Review step (docs/SCHEMA.md section 5.2): `settings.reviewStep` and `reviewTitle` add a virtual last step in
`FormRenderer.vue` (index `pages.length`, no page in the definition), built by `src/review.ts`. Not yet tried
on the dev instance or looked at in a real browser (the scroll box shadow and the two line clamp).

Continuing later (docs/SCHEMA.md section 21): `settings.saveProgress` keeps unsent answers in the
respondent's browser only (`src/progress.ts`, local storage, 30 days). No server side at all, by
decision: no link for another device, no uploads, no count. Not yet tried on the dev instance.

New responses, statuses and a note (docs/SCHEMA.md section 20): `responses_seen_at` on the form marks
what is new (not a status), responses carry `status` (an id from the administrator's list in app
config, `StatusSettings`) and `note`, and `#/f/<id>/responses/<entryId>` is a page for one response,
shown inside `EntriesView` so the list keeps its state. No assignment, by decision. Not yet tried on
the dev instance: the migration, the routes and the print view.

Import from core Forms (docs/SCHEMA.md section 13.3): `lib/Import/CoreForms/` (reader, converter, importer),
`occ webforms:import-core-forms`, and an Import form dialog (`src/components/ImportDialog.vue`) that lists the
owner's core forms, previews what would be left out and can bring responses (the form is then published once
and closed). Ranking, number grids, time ranges, uploaded files, sharing settings and the confirmation email
are not carried. Tried on the dev instance: the dialog import works. The `occ` command and bringing
responses were not checked by hand.

Waiting list (docs/SCHEMA.md sections 3.2 and 4): `settings.whenFull` set to `waitlist` keeps taking responses
past `maxResponses` and marks them waiting (not a status; `WaitlistService`, `lib/Forms/Availability.php`,
`src/availability.ts`). Owners can filter the waiting list and promote a response by hand; actions can run on
confirmed, waitlisted and promoted, and there is a Flow event for a promotion. The migration
`Version001100Date20261122120000` ran on the dev instance. Not yet tried by hand: joining the list, promoting,
and the actions on each event.

Templates (docs/SCHEMA.md section 13.4): New form opens `TemplateDialog.vue` (blank, own, starter). Starter
templates are JSON in `lib/Forms/Templates/` read by `TemplateCatalog`; own templates are rows in
`advforms_templates` (`FormTemplate`, `FormTemplateMapper`, added to the unreleased migration `Version001100`).
`FormService::createFromTemplate` goes through `createFromDefinition`, like import and duplicate. Starter text is
English on the server and translated by the browser: every word must be listed in `src/templates/catalog.ts`
(a test checks it), and `TemplateText` swaps only respondent-facing properties. The migration had already run on
the dev instance, so its new table is not there until that migration's row is removed from the migrations table.
A saved template has a name and a description (column added by `Version001200`, `TemplateDescription`, `SaveTemplateDialog.vue`).
Administrators can hide starters (`TemplateSettings`, app config `templates_hidden`) and keep templates for everyone
(`advforms_templates` rows with empty `owner_id`, ids `s:<n>`; `TemplateAdmin.vue`, `/api/v1/admin/templates`).
Not yet tried by hand on the dev instance.

Sharing (docs/SCHEMA.md section 18): custom address, QR code, and embedding in a frame through the
`embed` routes, which skip the request token and are always anonymous. Loading and submitting were
tried in a real frame on another site; uploads and the redirect action in a frame were not.

Verified on the dev instance by hand. Not yet verified: `info.xml`
against the app store XSD, a fresh install migration, rate-limit attributes under load.

Phase 7 so far: security pass done (account deletion now cleans up forms and responses),
`info.xml` validates against the app store XSD, translation tooling (`npm run l10n:extract`,
`l10n:compile`) and `tools/package.sh` for the release archive. Release steps are in
`docs/RELEASING.md`. Remaining: app store registration and signing, screenshots, and any
actual translations. Touch dragging is not supported
(the move buttons in the field panel cover it). Submit message and redirect are resolved
synchronously; see `docs/SCHEMA.md`.

Collaboration (docs/SCHEMA.md section 22), phase 1 built: forms can be shared with accounts and groups as
Editor or Reviewer. `FormAccess` is the one place that works out a role, and `FormService::get($userId, $id,
Role $needs)` has no default role on purpose: every call states what it needs, and
`tests/Unit/Access/PermissionTableTest.php` holds the permission table (section 22.1) as data, so a new
public method without a role fails it. Editors cannot change `Destinations` (the owner's Files and Tables,
section 22.5). Reviewers get `ReadingCopy` of a definition, never the real one. Phases 2 and 3 are built: revision conflicts for two editors (`forms.revision`, `baseRevision`, 409
`draft_conflict`), ownership transfer and a deleted owner's forms passing to an editor (`OwnershipService`,
`NextOwner`), and the server setting for sharing. Phase 3 and the conflict screen have not been tried on the dev
instance with two real accounts yet.

## Gotchas

- Field ids and option values match `^[a-z][a-z0-9_]{2,31}$` and are immutable;
  removed field ids are retired and cannot be reused.
- `ValueNormalizer` lowercases text for rule comparison only. Stored values keep
  the respondent's text (trimmed).
- Do not store `JSON_FORCE_OBJECT` entries: it would turn checkbox lists into
  objects.
