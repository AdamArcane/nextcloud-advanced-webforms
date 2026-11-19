<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Roadmap

What is planned, in rough order, and what is missing compared with other form builders. For how things
work see [SCHEMA.md](SCHEMA.md). Nothing here is promised for a date.

## Collaboration

Designed 2026-11-19 and written down in [SCHEMA.md](SCHEMA.md) section 22: share a form with accounts and
groups as Editor or Reviewer, so a team can work on one form. In this order, each a pull request:

1. **Access and roles (built).** Two new tables, one place that decides a person's role, the list of forms shared
   with you, "new responses" kept for each person, the Collaborators section and a reading copy of the
   definition for Reviewers.
2. **Revision conflicts.** The builder autosaves today with no guard, so two editors overwrite each other.
   Co-editing is not announced before this is in.
3. **Lifecycle.** Handing a form to someone else, what happens when an owner's account is deleted, and a
   server setting for sharing.

Later: notifications to collaborators, Teams as a share target, a read only Viewer role, and a history of
who changed what.

## Integrations and storage

Responses always live in the app's own database, and everything that sends them elsewhere is an
after-submit action (see the decision below). The work here makes that model complete.

1. **Send existing responses to a destination (backfill).** Adding a Tables action after responses
   were collected leaves the old ones behind. A "send existing responses" button on the action replays
   them through the same queue, with the same retries and delivery log.
2. **Administration settings.** Done: allowed action types, response retention, limits per form, who
   can create forms, and defaults for email (reply-to, sender name, and whether forms may set their
   own; SCHEMA.md sections 16 and 16.2). Still open: a default Tables folder.
3. **Files destination.** Done: the `files` action appends each response to a CSV file in the owner's
   Files (SCHEMA.md section 19). Real spreadsheet formats are harder, since Nextcloud has no native
   spreadsheet storage, and wait until there is demand.
4. **Remember the destination row.** The Tables action already receives the new row's id and drops it.
   Keeping it in the delivery log makes it possible to follow deletes (and later edits) of a response
   into Tables, which are not followed today. Decide per action whether deletes propagate.
5. **Choice options from a Tables column.** Fill a select, radio or checkbox list from a table, like the
   ready-made lists. Decide whether the options are copied when publishing or read live, because public
   forms would show them.
6. **More events for Flow.** A failed delivery, and a whole form being deleted. More actions in the
   same pattern (Deck, Talk, Calendar) once there is a clear use.

## Sharing

Done: custom address, QR code, embedding in a frame with a list of allowed websites (SCHEMA.md
section 18), and a password on the link (section 18.5). Still open, roughly in order:

1. A server-wide switch for administrators to forbid embedding.
2. A plain HTML version of a form, with no script, that takes the look of the website it is pasted
   into. Only for simple forms: no rules, one page, no uploads, and the pasted code goes stale when
   the form is published again. Needs a form-encoded submit route and a page for errors.
3. A button that opens the form in an overlay on the host website.
4. Regenerating the random link, to revoke one that leaked.
5. More than one link per form, to tell where a response came from.
6. Open and close dates on a link, per link (a form has them for the whole form already).

## Responses view

Done: table with search and filters, selecting, bulk delete, CSV or Excel download that follows the
filter, the selection and the chosen columns, a summary above the table (SCHEMA.md sections 17 to
17.2), and marking what is new, statuses, a private note and a page for one response that can be
printed (SCHEMA.md section 20). Still open, roughly in order:

1. ODS export, and a zip of uploaded files.
2. Advanced response filtering: filter responses by what people answered, with nested all and any
   groups written like rule conditions. The server already takes this (`where`, SCHEMA.md section 17) and
   the editor was built, then taken out of the Responses view because a bare "Answers" button did not say
   what it did. It needs a clearer place and wording, for example a "Filter by answer" panel with saved
   filters.
3. Clicking a bar in the summary to filter by that answer. Builds on the item above.
4. Sharing results with someone who cannot edit the form.

Not planned: assigning a response to someone. Forms are not a task tool, and a flow on "Response
submitted" or "Response status changed" can create a task or a Deck card (SCHEMA.md section 20.5).

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
- Matrix and signature fields (SCHEMA.md, deferred field types). Hidden, rating, scale, consent and
  date and time fields are done.
- App store listing, signing, screenshots and real translations.

### Gaps not tracked anywhere

- **Response limits and scheduling.** Done: a limit on responses, opening and closing times, and a
  message for each (SCHEMA.md section 3.1). They apply on publish. Still open: changing them on a live
  form without publishing, and telling respondents when a form will open.
- **Duplicate-submission control.** Anonymous duplicates are not controlled (SCHEMA.md open question 6).
- **URL prefill.** Done, with hidden fields and default values: the address fills fields by their id,
  unless the form switches it off (SCHEMA.md section 4.7). Still open: forwarding the host page's
  parameters into an embedded form, and making a prefilled field read only.
- **Response management.** Done: search, filters, a summary, statuses, notes and a printable page for
  one response (SCHEMA.md sections 17 and 20). Still open: respondents cannot edit a response after
  submitting.
- **Save and resume.** Done for the same device: a form can keep unsent answers in the respondent's
  browser (SCHEMA.md section 21). Left out by decision: a link to continue on another device, uploads,
  and a count of people who started.
- **Respondent experience.** Done: a progress indicator on forms with several pages (SCHEMA.md section 5.1). Also done: a review step that lists the answers before sending (SCHEMA.md section 5.2). Still open: per-form language, and a picture inside a question (image blocks, SCHEMA.md section 4.9, are done).
- **Sharing.** Public link, custom address, QR code and embedding (see Sharing above). Sharing a form with other accounts and groups is designed (see Collaboration above); it is not built yet.
- **Question types.** Done: rating, scale, consent and date and time (SCHEMA.md section 4.8). Also done: slider, colour, date range, name, address, matrix and signature (SCHEMA.md section 4.10). Image blocks are done (SCHEMA.md section 4.9).
- **Builder.** Touch dragging is not supported.
- **Rules.** Show, hide and require only. No conditional values or calculated fields.
- **Actions.** Submit message and redirect are resolved synchronously, so they cannot depend on a background action.

### Suggested first picks from the gap review

1. Response limits and scheduling (done).
2. URL prefill together with hidden fields (done).

Both are mostly schema and validator work and fit the existing architecture.
