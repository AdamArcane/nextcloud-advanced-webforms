<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Roadmap

What is planned, in rough order, and what is missing compared with other form builders. For how things
work see [SCHEMA.md](SCHEMA.md). Nothing here is promised for a date.

## Next

- **Release 0.3.2.** Ships what is on `main` and not yet released: the Nextcloud events, the Flow
  trigger, the Flow category and the Tables action.

## Integrations and storage

Responses always live in the app's own database, and everything that sends them elsewhere is an
after-submit action (see the decision below). The work here makes that model complete.

1. **Send existing responses to a destination (backfill).** Adding a Tables action after responses
   were collected leaves the old ones behind. A "send existing responses" button on the action replays
   them through the same queue, with the same retries and delivery log.
2. **Administration settings.** Done: allowed action types, response retention, limits per form and who
   can create forms (SCHEMA.md section 16). Still open: defaults for destinations, such as a default
   Tables folder or sender address.
3. **Files destination.** Append each response to a CSV file in the form owner's Files. Real
   spreadsheet formats are harder, since Nextcloud has no native spreadsheet storage, and wait until
   there is demand.
4. **Remember the destination row.** The Tables action already receives the new row's id and drops it.
   Keeping it in the delivery log makes it possible to follow deletes (and later edits) of a response
   into Tables, which are not followed today. Decide per action whether deletes propagate.
5. **Choice options from a Tables column.** Fill a select, radio or checkbox list from a table, like the
   ready-made lists. Decide whether the options are copied when publishing or read live, because public
   forms would show them.
6. **More events for Flow.** A failed delivery, and a whole form being deleted. More actions in the
   same pattern (Deck, Talk, Calendar) once there is a clear use.

## Sharing

Done: custom address, QR code, and embedding in a frame with a list of allowed websites (SCHEMA.md
section 18). Still open, roughly in order:

1. A server-wide switch for administrators to forbid embedding.
2. A plain HTML version of a form, with no script, that takes the look of the website it is pasted
   into. Only for simple forms: no rules, one page, no uploads, and the pasted code goes stale when
   the form is published again. Needs a form-encoded submit route and a page for errors.
3. A button that opens the form in an overlay on the host website.
4. Regenerating the random link, to revoke one that leaked.
5. More than one link per form, to tell where a response came from.
6. A password, and open and close dates, on a link (goes with response limits and scheduling below).

## Responses view

Done: table with search and filters, selecting, bulk delete, CSV or Excel download that follows the
filter, the selection and the chosen columns, and a summary above the table (SCHEMA.md sections 17 to
17.2). Still open, roughly in
order:

1. ODS export, and a zip of uploaded files.
2. Clicking a bar in the summary to filter by that answer.
3. Statuses (new, reviewed, done, spam), private notes and assignment. Needs a migration.
4. A per-response page that can be printed or shared with a colleague.
5. Sharing results with someone who cannot edit the form.

## Decision: actions, not pluggable storage

Recorded 2026-11-15 so it is not argued again from scratch.

We considered an administration setting that picks where responses are stored (built in, Tables, a CSV
file, a spreadsheet). We chose to keep the built-in database as the single source of truth and treat
every other place as an action, because:

- The Responses view, CSV export, rules, versioning, the delivery log and account deletion all read the
  built-in store. A second primary store would make each of them depend on it.
- With actions a response is saved first and copied after, so an outage in Tables only delays a copy. With
  a pluggable primary store a submission could be lost or fail.
- It matches how other form tools work: they keep entries in their own database and offer copies and
  exports.
- Published versions are immutable and fields get renamed, which is the hard part of mapping a store.

The price is that copies can drift from the original (a deleted response stays in Tables) and that
responses exist in two places. Items 1 and 4 above exist to reduce that.

**Revisit if** someone needs responses to live only outside the app, for example to avoid keeping a
copy for data minimisation. That would be an optional Tables-only mode, with the open questions in
SCHEMA.md section 9 to answer first.

## Release and publishing

- App store registration, signing, screenshots and real translations (see RELEASING.md).
- Not yet verified:
  - A fresh install migration.
  - Rate-limit attributes under load.

## Missing features

Gap review written against 0.3.x (2026-11-15), from README.md, docs/SCHEMA.md and CHANGELOG.md.

### Already planned or deferred in the docs

- Payments, calculations, PDF generation (non-goals in SCHEMA.md section 1).
- Theming and custom CSS. `design` only offers width, card, background and accent color.
- Nextcloud Tables as an optional response storage backend. Today Tables is only an after-submit action,
  by decision (see the decision above).
- Hidden fields, rating, matrix and signature fields (SCHEMA.md, deferred field types).
- App store listing, signing, screenshots and real translations.

### Gaps not tracked anywhere

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
- **Sharing.** Public link, custom address, QR code and embedding (see Sharing above). No collaborator or co-editor sharing and no per-user form permissions.
- **Question types.** No scale or slider, color, consent checkbox, or date-range field.
- **Builder.** Touch dragging is not supported.
- **Rules.** Show, hide and require only. No conditional values or calculated fields.
- **Actions.** Submit message and redirect are resolved synchronously, so they cannot depend on a background action.

### Suggested first picks from the gap review

1. Response limits and scheduling.
2. URL prefill together with hidden fields.

Both are mostly schema and validator work and fit the existing architecture.
