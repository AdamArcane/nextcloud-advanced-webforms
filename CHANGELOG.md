<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Changelog

## 0.3.6 - 2026-11-16

- App store listing: a description that matches what the app does now, and six screenshots.

- Fixed the Summary tab on the Responses page: in the cards for number, rating and scale questions, the
  labels (Average, Lowest, Highest, Total) now sit directly above their values instead of drifting away
  from them.

## 0.3.5 - 2026-11-16

- A summary before sending. Switch on "Show a summary before sending" under Settings and the form gets a
  last step that lists what was entered, page by page, with an Edit button for each page, a count of the
  optional questions skipped, and any required question still empty. You can name the step. A long summary
  scrolls inside a box of its own, so the page stays short. On a phone each answer sits under its label. Works with one page too. See `docs/SCHEMA.md`
  section 5.2.

- Continue later. A form can keep a respondent's unsent answers in their own browser, so a long form
  can be left and picked up again on the same device for up to 30 days. Switch it on per form under
  Settings, "Let people continue later", and publish. Nothing is stored on the server, uploaded files
  are not kept, a line under the buttons tells people they can come back later (or that saving did not
  work), and a "Start over" button clears the kept answers. See `docs/SCHEMA.md` section 21.

- New responses. The forms list shows how many responses each form got since you last looked at its
  responses, and the Responses view marks those as new until you leave it. Nothing has to be clicked:
  the next visit only marks what arrived in between. See `docs/SCHEMA.md` section 20.1.

- Statuses. Give a response a status such as "In progress", "Done" or "Spam" from its row, or give
  several ticked responses one together, and filter the list by status. Downloads get a `Status`
  column. Administrators keep the list of statuses for the whole server under Administration settings,
  "Response statuses": names, colours and order. A new "Response status changed" event is there for
  Flow and webhook listeners. See `docs/SCHEMA.md` section 20.2.

- A private note on each response, for the form owner only. It is never sent anywhere and is not in
  downloads. See `docs/SCHEMA.md` section 20.3.

- A page for one response, with its own address: every question with its answer, the status, the note
  and what was sent, with buttons to step to the earlier and later response and a print view that shows
  the answers only. See `docs/SCHEMA.md` section 20.4.

- Four new field types. "Rating" is a row of 3 to 10 stars. "Scale" is a row of numbers, from 0 or 1 up
  to 10, with a label at each end. "Consent" is a checkbox beside a text to agree to, which can link to
  your terms. "Date and time" is one answer for a day and a time. All four work in rules, defaults,
  filling from the link, exports, emails and the Tables action. See `docs/SCHEMA.md` section 4.8.

- Image blocks. Add a picture to a form from your Files, with a "Choose from Files" button, or by the
  address of a picture on another site. Set its description, largest width and alignment. A picture in
  Files is read where it is, so replacing the file updates the form. See `docs/SCHEMA.md` section 4.9.

- Page progress. A form with several pages shows respondents where they are: a bar or numbered steps,
  with the step number ("Step 2 of 4"), the page's name or a percentage. It is on by default, as a bar
  with the step number, and forms that are already published show it too. It takes the accent colour or
  a colour of its own. Change it or switch it off
  under Settings, Design. See `docs/SCHEMA.md` section 5.1.

- Hidden values, default values and filling fields from the link. A new "Hidden value" field is never
  shown to respondents and is sent with every response. Every field except an upload can have a default
  value, and the form's link can fill any field by its field ID: `?utm_source=newsletter` fills the
  field with ID `utm_source`. Each field shows its link parameter with a copy button, and a form setting
  switches filling from the link off. Rules can react to a hidden value. See `docs/SCHEMA.md` section 4.7.

- Response limit and opening dates. A form can take at most a number of responses, open at a set time
  and close at a set time, each with its own message, and can show respondents how many places are
  left. Set them under Settings, Sharing; they apply when the form is published. The forms list shows
  Scheduled, Ended or Full, and the settings show the responses counted against the limit. See
  `docs/SCHEMA.md` section 3.1.

- A new action adds each response as a line to a CSV file in your Files, with the same columns as the
  CSV download. An existing file keeps its own header row: answers go under the matching heading, and
  new questions become columns at the end. See `docs/SCHEMA.md` section 19.

- The Actions tab has one "Add an action" button at the top, with the kinds of action grouped by
  whether they happen on the page or in the background, each with a short description.

- Rename a form from the header of the builder: double-click its name (or press Enter or F2 on it),
  type, and press Enter. Escape cancels.

- Email actions can include every answer at once. "Add all answers" puts `{all_fields}` in the body,
  which becomes a list of the questions the person answered with their labels. Such a mail is also sent
  as HTML, with the answers in a table. See `docs/SCHEMA.md` section 7.2.

- Email actions can set a reply-to address, picked the same way as the recipient: an email field,
  your account, the signed-in respondent, or a typed address. A notification to you can then be answered
  straight to the person who filled in the form.

- Email actions can set a sender name. Only the name changes; the sender address stays the server's.

- Administration settings, "Emails from forms": a default reply-to for emails that set none (the form
  owner's account email or one fixed address), a default sender name, and a switch for whether form
  owners may set their own. With the switch off, a form may still reply to the address a respondent
  typed. See `docs/SCHEMA.md` section 16.2.

- Design: choose where the submit button sits, left, center, right or full width.

## 0.3.4 - 2026-11-15

- Picking a published form that has no unpublished changes now opens its responses instead of the
  editor. Drafts, closed forms and forms with unpublished changes still open in the editor. Each user
  can switch this off under Personal settings, "Advanced Webforms". See `docs/SCHEMA.md` section 16.1.

- More ways to share a form, under Settings, Sharing. Give a form a custom address such as
  `/apps/webforms/f/contact` beside its random link. Download a QR code of the link as PNG or SVG. Allow
  other websites to embed the form in a frame, for any website or only the ones you list, and copy the
  code as plain HTML or with a short script that fits the frame to the form. Embedding is off until you
  switch it on for a form. See `docs/SCHEMA.md` section 18.

## 0.3.3 - 2026-11-15

- A summary above the responses: responses per day (per month for long periods), how often each choice
  was picked, and the average, lowest, highest and total of number questions. It follows the filters
  and can be closed. See `docs/SCHEMA.md` section 17.2.

- Select responses in the table to delete or download just those. Tick the page, or "select all N
  matching", and delete them after a confirmation. Deleting by filter is refused if the number of matches
  changed since you confirmed it. One Download menu offers CSV and Excel (.xlsx), and follows what is
  ticked, else what the filters match, else everything, with the columns you chose. Excel keeps numbers
  as numbers and never runs a response as a formula. See `docs/SCHEMA.md` section 17.1.

- Responses as a table, with search and filters. Search the text of answers (choices by their label),
  filter by date range, by signed-in or anonymous, and by the answers themselves with the same
  conditions as rules. Sort by when it was submitted, pick which question columns to show, and switch
  back to cards if you prefer them. See `docs/SCHEMA.md` section 17.

- Administration settings under "Forms and responses": switch action types off for the whole server
  (hidden in the builder, refused at publish, and stopped on live forms), delete responses
  automatically after a number of days (off by default, with a count of what would be deleted and a
  confirmation before existing responses go), cap responses per form, lower the number of actions per
  form, and limit who can create forms to chosen groups. Everything defaults to the old behaviour. See
  `docs/SCHEMA.md` section 16.

## 0.3.2 - 2026-11-15

- Nextcloud events for Flow and other automation: a response was submitted, a response was deleted,
  a form was published, a form was unpublished, reopened or closed. They are webhook compatible, so an
  administrator can send them anywhere with the Webhook Listeners app. They also show up in Flow as a
  "Webforms" trigger. See `docs/SCHEMA.md` section 14.

- Tables action: add a row to a Nextcloud Tables table for each response, as the form owner. In the
  Actions tab, pick a table, choose which answer goes in each column (or let "Match by name" do it),
  and add the submission time, the respondent or the response number if you want them. "Create table"
  in the same card makes a new table with one column for each question and fills in the mapping. A
  choice the Tables column has no option for is flagged, because those responses leave the column
  empty. Text, number, choice, yes/no and date columns are supported, people and relation columns are
  not. It runs in the background with retries and shows in the delivery log. See `docs/SCHEMA.md`
  section 15.

## 0.3.1 - 2026-11-15

- First release build. The app ID is now `webforms`, so public links are `/apps/webforms/f/<token>`.
  Release archives are built by GitHub Actions on `v*` tags.

## 0.3.0 - 2026-11-14

- Archive forms. The three dot menu in the sidebar and the form settings both have Archive; archived
  forms move to a collapsible Archived section in the sidebar where they can be opened, exported and
  restored. An archived form stops taking responses and keeps everything else. Needs a database
  migration (a new `archived_at` column).
- Rules on a checkbox group can now match several options at once: includes any of, includes all of, includes none of.
- A form can hide its title on the public page (Settings, Show the title on the form page).
- Spam protection for people who are not logged in. An administrator picks one check for the
  whole server under Administration settings: a built-in proof-of-work check (default, no account,
  nothing shown, no data leaves the server), Cloudflare Turnstile, hCaptcha, or none. Form owners
  can turn the check off for a single form ("Spam check" switch in Settings). Logged-in users never
  see it. The hidden trap field, minimum fill time and rate limits stay on in every case.
- Import and export a whole form. Each form in the sidebar has a menu with Export draft and
  Export published version, and the list has Import form. The file holds the definition (fields,
  layout, rules, actions, settings) and never responses, ids, links or webhook secrets. Importing
  always creates a new draft form and never overwrites one.
- Website (URL) field type: http and https only, `https://` added when missing.
- Select, radio and checkbox fields can be filled from a ready-made list (countries, US states,
  Canadian provinces, Australian states, currencies, languages, months, weekdays). Option values may
  now be two characters, and a field may have up to 300 options.
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
