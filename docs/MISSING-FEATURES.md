<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Missing features

Gap review written against 0.3.x (2026-11-15), from README.md, docs/SCHEMA.md and CHANGELOG.md.

## Already planned or deferred in the docs

- Payments, calculations, PDF generation (non-goals in SCHEMA.md section 1).
- Theming and custom CSS. `design` only offers width, card, background and accent color.
- Nextcloud Tables as an optional response storage backend. Today Tables is only an after-submit action.
- Hidden fields, rating, matrix and signature fields (SCHEMA.md, deferred field types).
- App store listing, signing, screenshots and real translations.

## Gaps not tracked anywhere

- **Response limits and scheduling.** No max-responses cap and no open or close date. Only a manual closed status.
- **Duplicate-submission control.** Anonymous duplicates are not controlled (SCHEMA.md open question 6).
- **URL prefill.** Fields cannot be prefilled from query parameters. This goes with hidden fields.
- **Response management.**
  - No search or filter UI, no charts or summary.
  - Respondents cannot edit a response after submitting.
  - No per-response PDF.
  - No review states or assignment.
- **Save and resume.** Drafts autosave for the owner, but respondents cannot resume a long form.
- **Respondent experience.** No progress indicator in multi-page forms, no per-form language, no field-level images.
- **Sharing.** Public link only. No collaborator or co-editor sharing and no per-user form permissions.
- **Question types.** No scale or slider, color, consent checkbox, or date-range field.
- **Builder.** Touch dragging is not supported.
- **Rules.** Show, hide and require only. No conditional values or calculated fields.
- **Actions.** Submit message and redirect are resolved synchronously, so they cannot depend on a background action.

## Not yet verified

- A fresh install migration.
- Rate-limit attributes under load.

## Suggested first picks

1. Response limits and scheduling.
2. URL prefill together with hidden fields.

Both are mostly schema and validator work and fit the existing architecture.
