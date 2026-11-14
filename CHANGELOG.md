<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Changelog

## 0.2.0 - Unreleased

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
- Field IDs can be chosen by the owner. Changing one updates layout, rules, and
  actions together. Only ids that were published are reserved afterwards.
- Responses view: browse responses with their answers, see the delivery log for
  emails and webhooks with plain-language reasons, retry failed deliveries, delete
  responses, and download a CSV (spreadsheet-formula safe).
