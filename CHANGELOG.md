<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Changelog

## 0.3.0 - Unreleased

- File upload fields. Per field: how many files (1 to 10), the largest size, which types
  (images, documents, archives, audio, video, or listed extensions), who may upload
  (anyone or signed-in users) and where files go (a folder in the form owner's Files, or the
  app's own storage). The folder and the audience are limited by the owner's own permissions,
  checked on publish and again on every submission. Executable and web page types are never
  accepted. Files are uploaded before the form is sent and thrown away if it never is.

## 0.2.0 - Unreleased

- Multi-page forms: the builder can add, title, reorder and delete pages (up to 20), and
  respondents step through them with Back and Next. Required fields are checked per page,
  and pages whose fields are all hidden by rules are skipped.
- Email recipients can be the form owner or the signed-in respondent, looked up from
  the Nextcloud account when sent, besides a fixed address or an email field. The
  recipient is one dropdown that also accepts a typed address.
- Initial app skeleton: app metadata, empty page, database schema for forms,
  versions, entries, and the action log.
- Form definition spec in `docs/SCHEMA.md`.
- Rules engine, validation, and public form page with live rules and grid layout.
- Form builder: form list, canvas, field and form settings, autosave, preview, and
  a rules editor (show, hide, require) with nested All/Any groups.
- Label position: above, beside, or hidden, set for the whole form and per field.
  `hideLabel` still works and means the same as `hidden`.
- After-submit actions: message and redirect (resolved in the submit response),
  and email and webhook (queued, sent in the background, retried with backoff,
  logged). Webhooks are signed with HMAC-SHA256 and use Nextcloud's HTTP client
  so its private-address protection applies. Respondent-addressed email is
  capped per form per hour. Actions tab in the builder.
- Field IDs follow the label (`First Name` becomes `f_first_name`) until the owner sets one by hand;
  published fields keep theirs.
- Field IDs can be chosen by the owner. Changing one updates layout, rules, and
  actions together. Only ids that were published are reserved afterwards.
- Responses view: browse responses with their answers, see the delivery log for
  emails and webhooks with plain-language reasons, retry failed deliveries, delete
  responses, and download a CSV (spreadsheet-formula safe).
