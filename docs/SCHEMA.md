<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Advanced Webforms: form definition spec

Status: DRAFT v0.3 (design review, no code yet)

This document defines the data format everything else is built on: the form
definition, grid layout, rules engine, after-submit actions, versioning, and
the server-side submit algorithm. Decisions marked **DECISION** are hard to
change after release and deserve scrutiny. Items under "Open questions" are
undecided.

## 1. Goals and non-goals

Goals (v1):

- Rules engine: conditional show/hide, required-if, and conditional actions.
- Grid layout: several fields per row (inline fields), responsive.
- After-submit actions: email, webhook, confirmation message, redirect.
- Public forms by link, plus forms for logged-in users.

Non-goals (v1, but the format must not block them):

- Theming and custom CSS.
- Payments, calculations, PDF generation.
- Anonymous file uploads.

## 2. Terminology

- **Definition**: the JSON document describing a form (this spec).
- **Draft**: the mutable definition the owner edits.
- **Version**: an immutable published snapshot of a definition.
- **Entry**: one submission, always tied to the version it was submitted against.

## 3. Top-level document

```json
{
  "schemaVersion": 1,
  "title": "Project inquiry",
  "description": "Tell us about your project.",
  "settings": {
    "submitLabel": "Send",
    "labelPosition": "above",
    "requireLogin": false,
    "allowMultipleSubmissions": true,
    "hideRequiredIndicator": false,
    "captcha": true,
    "hideTitle": false,
    "closedMessage": "This form is no longer accepting responses.",
    "design": { "width": "standard", "card": true, "background": "default", "accentColor": "#0082c9" }
  },
  "fields": {
    "f_name": { "type": "text", "label": "Name", "required": true, "labelPosition": "inline" },
    "f_email": { "type": "email", "label": "Email", "required": true },
    "f_phone": { "type": "phone", "label": "Phone", "hideLabel": true, "placeholder": "Phone number", "required": false },
    "f_kind": {
      "type": "select",
      "label": "What do you need?",
      "required": true,
      "options": [
        { "value": "new", "label": "New website" },
        { "value": "support", "label": "Support" }
      ]
    },
    "f_url": { "type": "text", "label": "Current website URL", "required": false },
    "f_note": { "type": "paragraph", "text": "We reply within **two business days**." }
  },
  "pages": [
    {
      "id": "p_1",
      "title": null,
      "rows": [
        { "id": "r_1", "cells": [
          { "id": "c_1", "span": 6, "field": "f_name" },
          { "id": "c_2", "span": 6, "field": "f_email" }
        ]},
        { "id": "r_2", "cells": [
          { "id": "c_3", "span": 8, "field": "f_kind" },
          { "id": "c_6", "span": 4, "field": "f_phone" }
        ]},
        { "id": "r_3", "cells": [
          { "id": "c_4", "span": 12, "field": "f_url" }
        ]},
        { "id": "r_4", "cells": [
          { "id": "c_5", "span": 12, "field": "f_note" }
        ]}
      ]
    }
  ],
  "rules": [
    {
      "id": "rule_1",
      "effect": "show",
      "targets": ["f_url"],
      "when": { "all": [ { "field": "f_kind", "op": "eq", "value": "support" } ] }
    }
  ],
  "actions": [
    {
      "id": "act_1",
      "type": "email",
      "to": { "field": "f_email" },
      "subject": "We got your message, {f_name}",
      "body": "Thanks {f_name}. We will be in touch."
    },
    {
      "id": "act_2",
      "type": "webhook",
      "url": "https://n8n.example.com/webhook/abc",
      "secretRef": "whsec_1"
    },
    {
      "id": "act_3",
      "type": "message",
      "text": "Thanks, your inquiry was sent."
    }
  ]
}
```

`schemaVersion` is the version of this format, not of the form. It lets later
releases migrate stored definitions.

`settings.captcha` (boolean, default true) turns the server's spam check off for this form
when false. It is a form setting only; which check runs, and its keys, are server-wide and set
by an administrator (section 10.1).

`settings.prefill` (boolean, default true) lets the address the public form is opened with fill its
fields by field id (section 4.7). False switches that off for the form.

`settings.hideTitle` (boolean, default false) leaves the form title off the public page and the
builder preview. The title is still the page's heading for assistive technology, and the
description is unaffected.

`settings.hideRequiredIndicator` (boolean, default false) leaves out the asterisk
after the labels of required fields. It only changes how the label is drawn: the
field is still required, still marked required for assistive technology, and
still validated.

`settings.design` is optional and every key in it too: `width` (`narrow`,
`standard`, `wide`), `card` (boolean), `background` (`default`, `plain`,
`color`), `backgroundColor`, `accentColor` and `buttonColor` (the submit button only; defaults to
`accentColor`) (`#rrggbb` only, so a value can
never carry CSS), and `submitAlign` (`left`, `center`, `right` or `justify`; default `left`), where
the buttons under the form sit. `justify` makes them share the full width. Back and Next on a form
with several pages follow it too. `progress`, `progressLabel` and `progressColor` set the progress indicator of a form
with several pages (section 5.1).

### 3.1 Response limit and opening dates (DECISION)

A form can stop taking responses by itself: after a number of responses, before an opening time, and
after a closing time. All of it is optional and lives in `settings`, so it is versioned with the rest
of the definition, travels with export and import, and takes effect when the form is published. To
extend a deadline or raise the limit on a live form, change it and publish again.

| Key | Value | Meaning |
| --- | --- | --- |
| `maxResponses` | integer, 1 to 1 000 000 | the form takes at most this many responses |
| `showRemaining` | boolean, default false | tell respondents how many more responses the form takes. Only with `maxResponses` |
| `opensAt` | unix seconds | the form takes responses from this moment |
| `closesAt` | unix seconds, later than `opensAt` (`window_invalid`) | the form takes responses until this moment |
| `notOpenMessage` | string, up to 500 | shown before `opensAt`. Default "This form is not open yet." |
| `fullMessage` | string, up to 500 | shown at the limit. Default "This form has received the maximum number of responses." |

Times are unix seconds, so they mean the same moment everywhere. The builder shows and takes them in
the owner's own time zone. A time in the past is allowed: a `closesAt` that has passed simply
publishes a form that has ended.

A published form is in one of four states, worked out by `Availability` on every request. The first
that applies wins:

1. `ended`: `closesAt` has come. Answered like a closed form, with `closedMessage`.
2. `scheduled`: `opensAt` has not come yet. Answered with `notOpenMessage`.
3. `full`: the form has as many responses as its limit. Answered with `fullMessage`.
4. `open`.

The limit that counts is the lower of `maxResponses` and the server's cap (section 16). Every response
the form has counts, whichever version it was sent to, and deleting a response frees a place. As with
the server's cap, responses sent at the same instant can pass the limit by one.

The public page, the submit and the upload routes all answer `410` with
`{"error": "closed", "reason": ..., "message": ...}` when the form does not take responses. `reason` is
`closed` (closed by the owner, archived, or ended), `not_open` or `full`, and the page picks its
heading by it. Someone who opened the form before it closed or filled up gets the same answer when they
submit, and keeps what they typed.

A closed or archived form stays closed whatever its dates say: the owner's own status always wins, and
nothing here ever opens or closes a form by changing its status.

With `showRemaining` and `maxResponses`, the public form carries `remaining` (how many more responses
it takes, by the limit that counts) and the page shows it under the description. Otherwise `remaining`
is null.

The owner API adds `availability` to every form: `{state, opensAt, closesAt, cap, responses}` for the
published version, or null before the first publish. `cap` is the limit that counts, 0 for none. The
forms list shows Scheduled, Ended or Full in place of Published, and the settings show the state with
the responses counted against the limit.

## 4. Fields

Fields live in a map keyed by field ID. The layout references fields by ID, so
moving a field in the layout never changes its identity.

### 4.1 Field IDs (DECISION)

- Pattern: `^[a-z][a-z0-9_]{2,31}$`, conventionally prefixed `f_`.
- Unique within a form and never positional. The builder generates one, and the
  owner may replace it with a meaningful name (for example `customer_email`),
  because the id is what a webhook receiver or an export sees.
- While the builder made the id (the random one, or one made from the label), it
  follows the label as you type: `First Name` becomes `f_first_name`, with `_2`,
  `_3` added if taken. Accents are dropped and other characters become `_`. Once
  the owner sets an id by hand it never changes by itself. Only fields that
  carry a value follow (not headings, paragraphs or dividers), and a field that
  was already in a published version keeps its id, since webhooks and exports
  use it. Hyphens are not allowed in ids, so words are joined with `_`.
- Changing an id means moving everything that refers to it: the layout cell,
  rule targets and conditions, action conditions, an email's recipient field,
  and `{placeholders}` in action text. The builder does this in one step.
- Entries are read through the version they were submitted to (section 8), so
  changing an id in a later version leaves earlier responses intact. Receivers
  and exports that join responses across versions will see the new id from the
  next publish on; the builder warns about this for a published form.
- An id is **retired** (reserved for that form for good) when a field that
  appeared in a published version is removed or renamed, so a different field
  can never take over an id that old responses used for something else. An id
  that only ever existed in a draft is not retired and can be reused freely.
  The owner API returns `retiredIds`, and saving a draft that reuses one is
  refused.

### 4.2 Field types (v1)

Input types (produce a value):

| type | value type | notes |
| --- | --- | --- |
| `text` | string | single line, optional `minLength`, `maxLength` |
| `textarea` | string | multi line, optional `minLength`, `maxLength`, `rows` (2 to 20, default 5) |
| `email` | string | validated as an email address |
| `phone` | string | validated as a phone number, see 4.4 |
| `url` | string | a web address, see 4.4a |
| `number` | number | optional `min`, `max`, `step`, and `prefix`, `suffix` (up to 16 characters, display only, such as `$` or `kg`) |
| `select` | string | one of `options[].value` |
| `radio` | string | one of `options[].value`, optional `columns` (1 to 4) |
| `checkboxes` | string[] | subset of `options[].value`, optional `minSelected`, `maxSelected`, `columns` (1 to 4) |
| `checkbox` | boolean | single yes/no, `required` means must be true |
| `date` | string | `YYYY-MM-DD`, optional `min` and `max` (same format, inclusive) |
| `time` | string | `HH:MM` 24 hour, optional `min` and `max` (same format, inclusive) |
| `datetime` | string | `YYYY-MM-DDTHH:MM`, one day and time, optional `min` and `max` (same format, inclusive), see 4.8 |
| `rating` | number | stars: a whole number from 1 to `max` (3 to 10, default 5), see 4.8 |
| `scale` | number | a whole number from `min` (0 or 1, default 1) to `max` (2 to 10, default 5), optional `minLabel` and `maxLabel` (up to 50 characters), see 4.8 |
| `consent` | boolean | a checkbox beside `text` (limited markdown, see 4.5), `required` means must be true, see 4.8 |
| `file` | list of files | uploaded files, see 4.6 |
| `hidden` | string | never shown to respondents, see 4.7 |

Display types (no value, cannot be rule sources, can be rule targets):

| type | properties |
| --- | --- |
| `heading` | `text`, `level` (1 to 6, default 2, rendered as `h1` to `h6`), `align` |
| `paragraph` | `text` (limited markdown, see 4.5), `align` |
| `divider` | `style` (`solid` default, `dashed`, `dotted`) |
| `image` | `src`, `alt`, `width` (25, 50, 75 or 100), `align`, see 4.9 |

Deferred: matrix, signature.

Common properties for input fields: `label` (required), `description`,
`required` (default false), `defaultValue`, `labelPosition`, `hideLabel` (default false).

`placeholder` (optional string) is supported on `text`, `textarea`, `email`,
`number`, `phone`, `url`, and `select` (for `select` it is the empty-choice prompt).

`minLength`, `prefix`, `suffix`, `rows`, `columns`, `min` and `max` on a date or time, and `style` are
validated by the definition validator and are rejected on any other type (`unknown_property`). The
server enforces the value limits on submit: `too_short`, `date_too_early`, `date_too_late`,
`time_too_early`, `time_too_late`. `prefix`, `suffix`, `rows`, `columns` and `style` only change how the
field looks.

`align` on a `heading` or `paragraph` is `start` (default), `center` or `end`. It aligns the
text only; the field still fills its cell. It is `start` and `end` rather than left and right so
right-to-left languages behave.

`label` stays required for every input field, whatever its position, because
accessibility, entry display, and exports depend on it. A placeholder is not a
substitute for a label.

#### Label position

`labelPosition` is one of:

| value | meaning |
| --- | --- |
| `above` | the label sits above the input (the default) |
| `inline` | the label sits beside the input, in a two-column row |
| `hidden` | the label is visually hidden but stays in the DOM for screen readers (for example as a visually hidden element tied to the input) |

It can be set for the whole form with `settings.labelPosition` and overridden
per input field with `labelPosition`. Display types (`heading`, `paragraph`,
`divider`) have no label and reject the property. The position used for a field
is the first of these that is set:

1. the field's `labelPosition`
2. `hidden`, if the field has `hideLabel: true` (the older flag, kept so existing
   definitions keep working; it means the same as `labelPosition: "hidden"`)
3. `settings.labelPosition`
4. `above`

Renderer contract:

- `inline` is a request, not a guarantee. When the field's own box is narrow
  (about 380 px) the label moves above the input, so a quarter-width field or a
  phone never gets a cramped two-column row.
- `inline` always renders as `above` for `textarea`, `radio`, `checkboxes`,
  `checkbox`, `rating`, `scale` and `consent`. Their label either belongs to a group or is part of the control,
  and a side label reads badly next to a tall or multi-line control.
- `hidden` applies to every input type. For `checkbox` the text beside the box is
  the label, so it is hidden visually and still read by screen readers.
- The position never changes how a value is stored, validated, or exported.

All text properties are plain text and must be escaped by the renderer, with
one exception: `paragraph.text` accepts limited markdown (section 4.5). Raw
HTML is never interpreted anywhere (avoids stored XSS on public pages).

### 4.3 Option values

`options[].value` matches `^[a-z][a-z0-9_]{1,31}$` (like field IDs, but two
characters are allowed so codes such as `us` or `de` work) and is immutable;
`label` can change freely. Rules compare option values, never labels. A field has
at most 300 options.

The builder can fill the options from a built-in list (countries, US states,
Canadian provinces, Australian states, months, weekdays, currencies, languages).
The list is copied into the field, so it never changes under an existing form.
Values are lowercase standard codes (`us`, `us_ca`, `eur`, `january`); labels are
in the owner's language where the browser can translate them.

### 4.4a Website (url) fields

- Stored as a string, trimmed, up to 2048 characters.
- Only `http` and `https` are accepted, so a stored value can never be a
  `javascript:` or `data:` link. A value without a scheme gets `https://` put in
  front (`example.com` is stored as `https://example.com`). The host must contain
  a dot, and spaces, `<`, `>` and `"` are refused (`invalid_url`).
- Rules treat it like text (`eq`, `contains`, ...), compared case-insensitively.

### 4.4 Phone fields

- Stored as a string, trimmed, as the respondent typed it (display fidelity).
- Allowed characters: digits, spaces, and `+ ( ) - .`. A `+` is only allowed as
  the first character.
- Must contain 7 to 15 digits (15 is the E.164 maximum).
- Rendered as `type="tel"`.
- No region, carrier, or "is this number real" validation in v1 (see open
  questions).

### 4.5 Markdown in paragraphs

Only `paragraph.text` accepts markdown. Labels, descriptions, option labels,
and action messages stay plain text.

- Supported: paragraphs, line breaks, bold, italic, ordered and unordered lists,
  and links.
- Not supported: raw HTML (not interpreted), images, headings (use the `heading`
  type), tables, code blocks.
- Links: only `http`, `https`, `mailto`, and `tel` schemes. Rendered with
  `rel="noopener noreferrer nofollow"` and `target="_blank"`, so following a
  link does not discard a half-filled form.
- Maximum length 2000 characters.
- The server stores the markdown source only. The renderer parses it with HTML
  disabled, then passes the result through an allowlist sanitizer. The builder
  preview and the public page must use the same renderer.

### 4.6 File uploads (DECISION)

A `file` field takes one or more uploaded files. Properties (all optional):

| property | meaning |
| --- | --- |
| `maxFiles` | 1 to 10, default 1 |
| `maxSizeMb` | largest single file, 1 to 100, default 10. The server's PHP upload limit also applies |
| `accept` | list of groups (`images`, `documents`, `archives`, `audio`, `video`) and extra extensions like `.dwg`. Default `images` and `documents` |
| `audience` | `anyone` (default) or `login` (signed-in users only) |
| `storage` | `files` (default) saves into the form owner's Nextcloud Files, `app` keeps files in the app's own storage |
| `folder` | for `storage: files`, a folder path in the owner's Files. Default `Webforms/<form title>` |

A file field has no `placeholder` and no `defaultValue`. In rules it can only be tested with
`empty` and `not_empty`.

**Always refused**, whatever `accept` says: programs, scripts and web pages (`php`, `phtml`, `phar`,
`exe`, `dll`, `bat`, `sh`, `py`, `js`, `html`, `htm`, `svg`, and similar), a name with such an
extension anywhere in it (`shell.php.jpg`), and content that sniffs as an executable, script or web
page, whatever the file is called.

**Flow.** The browser uploads each file on its own to
`POST /api/v1/public/{token}/upload` (multipart: `file`, `fieldId`, `versionId`, `fillToken`) and gets
back an upload id. The form is submitted with the ids as the field's value. Until then the file waits
in the app's storage and in the `advforms_uploads` table. On submit the server checks every id (it must
be unused, uploaded for this form and this field), stores the files, and replaces the ids with file
descriptors `{ name, size, mime, storage, ref }` in the response. If any file cannot be stored nothing
is kept and the respondent can submit again with the same uploads. Waiting uploads that are never
submitted are removed after 25 hours by a background job. Each form may have 200 MB waiting at a time.

**Permissions.** Where files go and who may send them are limited by what the form owner may do.
`folder` must be somewhere the owner can write, and `audience: anyone` with `storage: files` is only
allowed if the owner may share that folder by public link (a public upload is what that amounts to),
which also respects the administrator's sharing settings. This is checked when the draft is saved (as a
warning), when it is published (as an error), and again at every submission, because the owner's access
can change. Files are always written as the owner, so Nextcloud's own rules (quota, antivirus, file
locking) apply. `folder` and `storage` are never sent to respondents.

**After the fact.** Each response with files in `storage: files` gets its own subfolder
`Response <date time> <id>` in the field's folder. Deleting a response, or the form, deletes its files
(into the owner's trash bin) and the subfolder. Owners download files from the Responses view, always
as attachments. Emails and CSV show file names only; webhook payloads carry `name`, `size` and `mime`
for each file, never where it is stored.

### 4.7 Hidden fields, default values and filling from the address (DECISION)

A `hidden` field carries a value the respondent never sees, such as where they came from or an id from
another system. Its properties are `label` (required: it names the value in responses, exports and
emails), `description` (a note for the owner, never shown) and `defaultValue`. `required`,
`placeholder`, `labelPosition` and `hideLabel` are `unknown_property`.

- **Value.** Text, trimmed, at most 2000 characters like a short text answer. Empty means no answer.
- **On the page.** It sits in the layout like any field, so the builder can show and move it, but the
  public form leaves its cell out and takes no room for it. A row or page with nothing else in it is
  left out too.
- **Rules.** It can be a rule source, with the operators of a text field. It cannot be a rule target
  (section 6.5), so it is always sent.
- **Everywhere else** it is a text answer: the Responses view, CSV and Excel downloads, `{field_id}` and
  `{all_fields}` in emails, webhooks, Tables (a text column) and the CSV file action.
- **Not a secret, not a proof.** The value is in the page and in the address, and a respondent can change
  it before sending. Never use it to decide something a respondent must not be able to fake.

**Default values.** Every input field except `file` may have a `defaultValue`, the answer it starts
with: text for the text types and `hidden`, a number for `number`, an option value for `select` and
`radio`, a list of option values for `checkboxes`, `true` or `false` for `checkbox` and `consent`, a
number the field offers for `rating` and `scale`, `YYYY-MM-DD` for a date, `HH:MM` for a time and
`YYYY-MM-DDTHH:MM` for a date and time. It must be a value the field itself accepts (`field_default_invalid`). A
respondent can change the default of a field that is shown.

**Filling fields from the address.** When the public form opens, every input field except `file` can
be filled from the address by its field id: `/f/<token>?utm_source=newsletter` starts the field with id
`utm_source` as `newsletter`. Nothing needs to be set up per field; to get a readable parameter, give
the field a readable id (section 4.1). `settings.prefill: false` switches this off for the whole form,
and then the address fills nothing.

- A value from the address replaces the field's `defaultValue`. A field with neither starts empty.
- Changing a field id changes the parameter that fills it, as it changes what webhooks and exports see,
  so links that use the old id stop filling the field.
- How the text in the address becomes a value: text, email, phone, web address, long text and hidden
  take it as it is; a number must be a number; a date `YYYY-MM-DD` and a time `HH:MM`; a dropdown or
  single choice takes an option's value or its label (ignoring case); multiple choice takes several,
  either by repeating the parameter or separated by commas, and leaves out any it does not know; a
  checkbox is checked by `1`, `true`, `yes` or `on` and left unchecked by `0`, `false`, `no` or `off`.
- A value the field would not accept (wrong type, not an option, outside the field's limits) is ignored
  and the field keeps its default. So is a parameter that is not a field id of the form. Nothing is
  reported to the respondent.
- A prefilled field that is shown can still be changed by the respondent.
- It happens in the browser, when the form opens. The server checks what is submitted as for any
  answer, so prefill adds nothing a respondent could not already send.
- An embedded form reads the address of its frame, so put the parameters on the `src` of the frame
  (`/f/<token>/embed?utm_source=partner`). The builder preview does not fill anything from the address.
- Because any field can be filled, a default, including a hidden field's, is only a starting value:
  `?<id>=...` replaces it unless the form switches filling off.

### 4.8 Date and time, rating, scale and consent (DECISION)

Four input types that store one plain value each, so rules, exports, placeholders and the Tables
action treat them like the types they resemble.

- **`datetime`.** One answer for a day and a time, stored as `YYYY-MM-DDTHH:MM` with no time zone: it
  is the respondent's own clock, like `date` and `time`. The day must exist and the time is 24 hour.
  `min` and `max` are in the same format and inclusive; an answer outside them is `date_too_early` or
  `date_too_late`. Because the format has a fixed width, text order is time order, which is how rules
  and limits compare it. Where people read it (responses, CSV, email, Tables) the `T` is shown as a
  space. A Tables date-and-time column takes it as it is.
- **`rating`.** A row of stars. The answer is a whole number from 1 to `max`; `max` is 3 to 10 and 5
  when left out. There is no zero: no stars is no answer. In a new table it becomes a stars column when
  `max` is 5 or less (Tables draws five stars) and a number column otherwise.
- **`scale`.** A row of numbers from `min` to `max` with an optional label at each end, for questions
  like "How likely are you to recommend us?". `min` is 0 or 1 (default 1), `max` is 2 to 10 (default 5).
  Zero is an answer here, not an empty one.
- **Picking.** For both, picking the number already chosen takes the answer back, so an optional
  question can be left unanswered again. They are radio buttons underneath, so the arrow keys move
  between them, and each is announced with the field's label.
- **Numbers.** `rating` and `scale` answers are stored as JSON numbers, are summarised with an average
  like `number`, and take the same rule operators. An answer outside the range is `below_min` or
  `above_max`, one that is not whole is `not_step`.
- **`consent`.** A checkbox beside a text the respondent agrees to. `text` is required, up to 2000
  characters, in the paragraph's markdown (4.5), so it can link to terms or a privacy notice. `label`
  names the answer in responses and exports ("Terms") and is shown above the checkbox unless hidden.
  The answer is `true` or `false` like `checkbox`, and `required` means it must be ticked. The wording
  that was agreed to is the `text` of the version the response answered: versions are immutable and
  every response stores its version id, so it can always be shown later.
- **Filling from the address** (4.7): a number the field offers for `rating` and `scale`, `yes` or
  `no` for `consent`, and a date and time with a `T` or a space for `datetime`.

### 4.9 Image blocks (DECISION)

An `image` is a display type: it shows a picture, holds no value, and can be the target of a rule.
`src` is one piece of text that is read in one of two ways.

- **An address on another site.** It must start with `https://`, name a host, and carry no user name,
  password, white space or quotes. The respondent's browser loads it straight from that site, so that
  site sees each visit and decides whether the picture stays. The public page's content security
  policy names the hosts of the published form's pictures, and nothing else, for pictures only.
- **A path in the owner's Files**, anything that does not start with a scheme: `Pictures/logo.png`,
  below the owner's home folder. Its parts are plain names (no `.` or `..`), and it must end in
  `.png`, `.jpg`, `.jpeg`, `.gif` or `.webp`. SVG is refused: it can carry script and would be served
  from this site's own address. The builder's "Choose from Files" button fills the path in.
- Anything else is `field_image_invalid`. A draft may hold an image with no picture yet; publishing
  waits for one.

How a picture in Files reaches respondents:

- The path is the owner's business. The definition sent to the public page carries `@files` in its
  place, and the page asks for the picture by form and field:
  `GET /api/v1/public/<token>/image/<fieldId>` (and the same under `embed` for a framed form).
- That route answers only for a form the caller could open right now, with the same checks as loading
  the form, and only for an image block of the published version. It reads the file as the owner,
  so it reaches only what the owner can reach, and serves it with the type its extension names and
  `nosniff`. Pictures over 10 MB are not served.
- The file is read where it is, not copied. Replace it and the form shows the new picture; move or
  delete it and the block shows nothing. Saving a draft warns, and publishing refuses, when the file
  is not there (`field_image_missing`).
- In the builder the owner sees their own file through `GET /api/v1/image?path=...`, which takes
  the same paths and serves the same types, for the signed-in user only.

Other properties: `alt` (up to 255 characters, what the picture shows; empty marks it as decoration),
`width` (the most room it takes in its cell, as a percentage: 25, 50, 75 or 100, default 100; a
picture is never stretched past its own size), and `align` as for a paragraph.

## 5. Layout: pages > rows > cells (DECISION)

- `pages`: ordered, 1 to 20. Each page is shown as one step; respondents move
  through them with Back and Next. A page whose fields are all hidden by rules
  is skipped. `title` is optional. A field appears in exactly one cell on one page.
- `rows`: ordered, each row is a horizontal band.
- `cells`: ordered within a row. Each cell holds exactly one field.
- `span`: width in twelfths of the row. v1 allows only 3, 4, 6, 8, 9, and 12
  (1/4, 1/3, 1/2, 2/3, 3/4, full width). Spans in a row must sum to at most 12.
  The builder offers these as presets, not a free number. Storing twelfths keeps
  widths exact (1/3 + 2/3 is exactly 12, while 33% + 66% is 99%), and the
  allowed set can widen later without a migration.
- Every field must appear in exactly one cell. Cells without a valid field are
  rejected.

Responsive behavior (renderer contract):

- At narrow widths (below a fixed breakpoint, about 600 px) every cell becomes
  full width and cells stack in document order.
- A cell whose field is hidden by rules is removed from the layout, and the
  remaining cells in the row keep their own spans (the row does not stretch the
  survivors; this avoids layout shifting every time an answer changes).
- A row whose cells are all hidden is removed entirely.

### 5.1 Page progress (DECISION)

A form with more than one page shows where the respondent is, above the fields. Three optional keys in
`settings.design` set how:

| Key | Values | Default | Meaning |
| --- | --- | --- | --- |
| `progress` | `bar`, `steps`, `none` | `bar` | A bar that fills, a row of numbered steps, or no indicator. |
| `progressLabel` | `step`, `title`, `percent`, `none` | `step` | The line of text with it: "Step 2 of 4", the page's `title`, "50%", or no text. |
| `progressColor` | `#rrggbb` | `accentColor` | The colour of the bar or the steps. |

- **On by default.** A form that sets neither key gets the bar with the step number, so forms published
  before this existed show it too. Setting both to `none` shows nothing.
- **What is counted.** Pages, not answers. The total is the pages a respondent can reach right now:
  a page whose fields are all hidden by rules is not counted, so the total can change as answers change.
  A form that is down to one reachable page shows no progress.
- **How full.** The step over the total: the first of four pages is 25% and the last page is 100%.
- **Page name.** With `title`, a page that has no title shows its step number instead.
- **Steps.** Numbered and not clickable: Back and Next are the only way to move, because Next checks
  the required fields. In a narrow container the numbers are left out and the steps are plain dots.
- **Colour.** The indicator uses `progressColor`, or the form's `accentColor` without one. The text keeps
  the theme's own colour, so it stays readable in light and dark, and the numbers in filled steps are
  black or white, whichever reads better on the colour.
- **Assistive technology.** The bar is a `progressbar` whose value text is the step ("Step 2 of 4")
  whatever the label shows, and the current step in the row is marked `aria-current="step"`. After Next
  and Back the label takes focus, so the new page is announced and the keyboard carries on from its top.
- This is display only. The server takes no part in it and nothing about it is stored with a response.

## 6. Rules

### 6.1 Shape

```json
{
  "id": "rule_1",
  "effect": "show",
  "targets": ["f_url", "f_note"],
  "when": {
    "any": [
      { "field": "f_kind", "op": "eq", "value": "support" },
      { "all": [
        { "field": "f_budget", "op": "gte", "value": 5000 },
        { "field": "f_email", "op": "not_empty" }
      ]}
    ]
  }
}
```

- `id`: unique within the form, same pattern as field IDs (section 4.1).
- `effect`: `show`, `hide`, or `require`.
- `targets`: field IDs (input or display fields).
- `when`: a condition group.

### 6.2 Condition tree

A group is exactly one of `{"all": [...]}` or `{"any": [...]}`. Members are
either groups (nesting allowed, maximum depth 5) or leaf conditions:

```json
{ "field": "f_kind", "op": "eq", "value": "support" }
```

Operators:

| op | meaning | value |
| --- | --- | --- |
| `eq`, `neq` | equal / not equal | scalar |
| `gt`, `gte`, `lt`, `lte` | numeric or date comparison | number or date string |
| `contains`, `not_contains` | for `checkboxes`: membership; for text: substring | scalar |
| `in`, `not_in` | field value is (not) in a list | array of scalars |
| `contains_any`, `contains_all`, `contains_none` | for `checkboxes` only: at least one / every / none of the listed options is selected | non-empty array of option values |
| `empty`, `not_empty` | no value / has value | none |

Deliberately not included: regular expressions. User-supplied patterns run
against public input are a ReDoS risk. Revisit only with a safe engine.

Comparison semantics (**DECISION**, must be identical in PHP and TypeScript):

- Option values (`select`, `radio`, `checkboxes`): exact string match.
- Free text (`text`, `textarea`, `email`, `url`): trimmed, case-insensitive.
- `phone`: compared on digits and a leading `+` only, so spacing and punctuation
  do not matter.
- `number`, `rating`, `scale`: numeric. A non-numeric stored value counts as empty.
- `date`, `time` and `datetime`: lexical comparison on the normalized string formats above.
- `checkbox`, `consent`: compares as boolean (`eq` with `true` or `false`).
- Empty means: missing, `null`, empty string after trim, or empty array.
- A comparison against an empty value is false, except `empty`, `neq`,
  `not_contains`, `not_in`, and `contains_none`, which are true. `contains_all`
  is false against an empty value even for an empty list (it never matches an empty list).
- A rule whose source field is itself hidden sees that field as empty.

Normalization before comparing (applied to the submitted value and to the rule's
`value`; the same function is used in PHP and TypeScript):

- Text (`text`, `textarea`, `email`): must be a string, otherwise it counts as
  empty. Trimmed of Unicode whitespace (the set JavaScript's `trim()` removes),
  then lowercased with full Unicode lowercasing.
- `phone`: digits plus a leading `+` are kept, everything else is dropped. No
  digits means empty.
- `number`, `rating`, `scale`: a JSON number, or a string like `12` or `-3.5`. Anything else
  (including exponent forms like `1e3`) counts as empty.
- `select`, `radio`: a non-empty string, compared exactly. Any other type counts
  as empty.
- `checkboxes`: a list of strings. Any other type counts as empty.
- `checkbox`, `consent`: a boolean. Any other type counts as empty.
- `date`, `time`, `datetime`: strings in the formats from section 4.2, anything else counts
  as empty.

Operators allowed per field type (a rule using any other operator is rejected
on save, and evaluates to false if it ever reaches the engine):

| field type | operators |
| --- | --- |
| `text`, `textarea`, `email`, `phone`, `url` | `eq`, `neq`, `contains`, `not_contains`, `in`, `not_in`, `empty`, `not_empty` |
| `number`, `rating`, `scale` | `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `empty`, `not_empty` |
| `select`, `radio` | `eq`, `neq`, `in`, `not_in`, `empty`, `not_empty` |
| `checkboxes` | `contains`, `not_contains`, `empty`, `not_empty` |
| `checkbox`, `consent` | `eq`, `neq` |
| `date`, `time`, `datetime` | `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `empty`, `not_empty` |

Rule values must fit the source field: for `select`, `radio`, and `checkboxes`
they must be existing option values, for `number`, `rating` and `scale` a number, for `checkbox` and
`consent` a boolean, for `date`, `time` and `datetime` a valid string. `in` and `not_in` take a
non-empty list of such values. `empty` and `not_empty` take no value. `all` and
`any` groups must not be empty.

### 6.3 Effect evaluation

For each field:

```
visible  = (no show rules target it OR any show rule matches)
           AND NOT (any hide rule matches)
required = (field.required OR any require rule matches) AND visible
```

A hidden field is never required and its submitted value is discarded.
Display fields are only affected by show and hide.

### 6.4 Evaluation order and cycles (DECISION)

Rules create a dependency graph: a rule's target depends on its source fields.
On save the server rejects any definition where this graph has a cycle
(including a field targeting itself). Evaluation runs in topological order so
each field's visibility is final before it is used as a source.

### 6.5 Source and target restrictions

- Sources must be input fields (they need a value).
- Targets may be any field except a `hidden` field (`rule_target_hidden_field`): it is never shown, so
  there is nothing to show or hide, and it is never required.
- Referencing a nonexistent field ID is a validation error on save.
- The save-time checks (references, operator and value validity, nesting depth,
  dependency cycles) are done by the rule validator, `lib/Rules/RuleValidator.php`.

## 7. After-submit actions

### 7.1 Shape and ordering

`actions` is an ordered array. Each action may carry an optional `when`
condition group (same format as rules) evaluated against the submitted
(post-visibility) values.

```json
{ "id": "act_4", "type": "webhook", "url": "https://...", "when": {
  "all": [ { "field": "f_kind", "op": "eq", "value": "support" } ]
}}
```

### 7.2 Types

- `email`:
  - `to` is one of:
    - a static address set by the form owner;
    - `{ "field": "<id>" }` pointing at an `email` field (confirmation to the
      submitter);
    - `{ "owner": true }`, the email address on the form owner's Nextcloud
      account, looked up when the email is sent (skipped as `no_account_email`
      when the account has none);
    - `{ "respondent": true }`, the email address on the account the
      respondent was signed in with (skipped as `not_signed_in` for an
      anonymous response, or `no_account_email`).
    The last two store no address in the form, so they follow account changes.
  - `replyTo` (optional) is where a reply to the mail goes, written exactly like `to`: a static
    address, `{ "field": "<id>" }`, `{ "owner": true }` or `{ "respondent": true }`
    (`action_bad_reply_to` otherwise). The usual use is a notification to the owner that replies to
    the address the respondent typed. Unlike `to` it is never a reason not to send: when it resolves
    to nothing, or to something that is not one plain address, the mail goes out without a reply-to.
    It is not counted against the hourly cap, because it does not decide who receives mail.
  - `fromName` (optional) is the name the mail is sent under: one line of at most 100 characters
    (`action_from_name_invalid`). Only the name changes. The sender address is always the server's
    own, which is what its SPF and DKIM records vouch for.
  - An administrator can set defaults for both, and switch off a form's own choice (section 16.2).
  - `subject`, `body`: plain text with `{field_id}` placeholders.
  - In `body` only, `{all_fields}` stands for every question the respondent answered, in the order the
    form shows them, as `Label: answer` lines (an answer of several lines is indented under its label).
    Unanswered and hidden questions are left out, and a question without a label is listed by its id.
    A mail whose body uses it is also sent as HTML, with the answers as a table of labels and answers
    and everything a person typed escaped. A mail without it stays plain text only. Anywhere else
    `{all_fields}` is an unknown placeholder, and a field that really has the id `all_fields` keeps
    its own meaning.
  - Sent through Nextcloud's configured mailer.
  - Recipient from respondent input is only allowed from an `email` field and is
    always rate limited per form, because it is an abuse vector (spam relay).
    Mail to the owner or a signed-in respondent's own account is not capped, as
    an anonymous visitor does not choose those addresses. The cap applies to
    the field recipient: at most 30 such emails per form per hour, beyond which the delivery is
    logged as skipped (`rate_limited`). Mail to a fixed owner address is not
    capped.
  - If the respondent left the email field empty or gave an invalid address,
    the delivery is logged as skipped (`no_recipient`).
  - Placeholder values are inserted as plain text, and header injection
    (newlines) is stripped from `subject`.
- `webhook`:
  - `url`: https URL set by the form owner (no placeholders in the URL).
  - POST with a JSON body (section 7.3), 10 second timeout, redirects are not
    followed.
  - Signing: if the action has a `secretRef`, the request carries
    `X-Webforms-Timestamp` (Unix seconds) and `X-Webforms-Signature`,
    which is `sha256=` followed by the hex HMAC-SHA256 of `<timestamp>.<body>`
    with the secret. The timestamp is part of what is signed, so a captured
    request cannot be replayed with a fresh one; receivers should also reject
    stale timestamps. Without a `secretRef` the request is unsigned.
  - Other headers: `X-Webforms-Event: entry.created` and
    `X-Webforms-Delivery: <log row id>`.
  - Secrets never appear in a definition, only the reference. The value is
    created by the owner API (`POST /forms/{id}/webhook-secret`), returned once,
    and stored encrypted as a sensitive app config value. It cannot be read
    back; regenerating under the same reference replaces it. Deleting the form
    deletes its secrets. A webhook whose `secretRef` has no stored secret fails
    without sending anything.
  - Must use Nextcloud's HTTP client so its local-address (SSRF) protection
    applies. Targets on private networks are blocked unless the admin allows it.
- `message`: confirmation text shown to the respondent. If several match, the
  first matching one wins.
- `redirect`: URL the respondent is sent to. Set by the form owner, https only,
  placeholders are not allowed in scheme or host, and substituted values are
  URL-encoded. First matching `message` or `redirect` wins (they share one slot).
- `tables`: adds a row to a Nextcloud Tables table (section 15).
- `files`: adds a line to a CSV file in the owner's Files (section 19).

### 7.3 Webhook payload

```json
{
  "event": "entry.created",
  "formId": 12,
  "versionId": 3,
  "entryId": 457,
  "submittedAt": "2026-11-14T06:12:00Z",
  "userId": null,
  "data": {
    "f_name": { "label": "Name", "value": "Ada" },
    "f_kind": { "label": "What do you need?", "value": "support" }
  }
}
```

Hidden or unanswered fields are omitted from `data`.

### 7.4 Execution semantics (DECISION)

- Actions never run inside the submit request. After the entry is committed,
  one queued background job per matching action is created.
- Action failure never fails or rolls back the submission.
- Deliveries are rows in the action log, worked through by a background job.
  A job queued at submit time sends them at the next background run, and a
  one-minute timed job picks up retries.
- Each delivery is tried up to 5 times, waiting 1 minute, 5 minutes, 30 minutes
  and 2 hours between attempts. A rejected request (a 4xx other than 408 or
  429, a missing secret) is not retried. A 5xx, 408, 429, or a network error is.
- The log row records status (`queued`, `success`, `failed`, `skipped`), the
  number of attempts, the last error, and when it is next due. There is at most
  one row per action per entry. A worker reserves a row for 5 minutes while it
  runs, so a crashed worker's delivery becomes due again instead of stuck.
- The owner can view the log and retry manually (not built yet).
- `message` and `redirect` are the exception: they are resolved synchronously
  and returned in the submit response, because the respondent needs them now.

## 8. Versioning (DECISION)

- A form has one mutable **draft** definition. Saving a draft is lenient so the
  builder can store work in progress: only an oversize document, a missing
  title, a non-object `fields`, or reuse of a retired field id is refused, and
  other validation errors are returned as warnings. Publishing is the gate (this
  amends the earlier "cycles are rejected on save": a draft is never executed,
  so a cycle in it is harmless until publish).
- **Publish** validates the draft and stores an immutable **version** (numbered
  1, 2, 3, ...). Published versions are never edited.
- The public endpoint serves the latest published version.
- A submission carries the `versionId` it was rendered from. The server validates
  it against that version, so someone who opened the form before an edit still
  submits against the schema they saw. Submissions against a version older than
  the latest are accepted by default (see open questions).
- Entries store the `versionId`. Entry display and export map values through
  that version's definition, so renamed or deleted fields still render correctly.

### 8.1 Archiving

A form can be archived by its owner (`PUT /api/v1/forms/{id}/archived` with `{"archived": true|false}`).
Archiving stores a timestamp (`archivedAt`, null when not archived) beside the status and changes
nothing else, so restoring a form brings back exactly what it was.

- An archived form takes no responses: its public page and upload endpoint answer as a closed form
  does, with the form's closed message.
- It keeps its link, responses, drafts, versions and deliveries, and the owner can still open,
  export and edit it.
- It cannot be published or have its status changed until it is restored (`form_archived`).
- The forms list returns archived forms with `archivedAt` set; the app shows them in a separate
  Archived section.

## 9. Entry storage

Initial proposal, one row per entry:

| column | notes |
| --- | --- |
| `id` | primary key |
| `form_id`, `version_id` | foreign keys |
| `submitted_at` | UTC timestamp |
| `user_id` | nullable, set only for logged-in respondents |
| `data` | JSON text, keyed by field ID, only visible fields |
| `status`, `note` | nullable, the owner's status and private note (section 20) |

Entry access goes through one class (`EntryMapper`) so the storage can be
swapped. Tables is not a storage backend: it is an after-submit action (section 15), and the built-in
store stays the source of truth. The reasons, and when to revisit, are in `docs/ROADMAP.md`.

### 9.1 CSV export

`GET /api/v1/forms/{id}/entries/export` returns every response as a UTF-8 CSV
(with a byte order mark so spreadsheets open it correctly, CRLF line endings,
RFC 4180 quoting), oldest first, up to 20 000 rows.

- Fixed columns: `Response ID`, `Submitted (UTC)`, `Respondent` (the Nextcloud
  user, empty for anonymous responses).
- Then one column per input field, taken from the union of every version that
  has responses. Newest version first, in layout order, using the newest label;
  a field removed from the form keeps its column. Two different fields that
  share a label are told apart as `Label (field_id)`.
- Each response is read through its own version (section 8), so an option
  renamed later still shows the label it had when it was chosen.
- Values read like the emails do: option labels, `Yes` or `No`, lists joined
  with commas, nothing for an unanswered field.
- **Spreadsheet formulas:** a response is text anyone can submit, and a cell
  starting with `=`, `+`, `-`, `@`, a tab or a carriage return would run as a
  formula. Such text (and column headers) gets a leading apostrophe. Numbers are
  exempt, so a negative number stays a number.
- The file name is ASCII, built from the form title and the date.

### 9.2 Delivery log

`GET /api/v1/forms/{id}/deliveries` lists email and webhook deliveries newest
first (filters: `status`, `entryId`), with a short target (an address, the label
of the email field used, or a webhook's host, never a full URL). `POST
/api/v1/forms/{id}/deliveries/{logId}/retry` starts a `failed` one over from
attempt 1. Deleting a response deletes its deliveries.

No database-level JSON querying (Nextcloud supports several databases). Filtering
and search happen in PHP for v1. IP addresses and user agents are not stored by
default. If abuse tracking needs them later, store a salted hash with a
retention limit.

## 10. Submit algorithm (server)

The server never trusts the client's idea of visibility.

1. Load the form and the submitted version. Reject if the form is closed, not
   open yet, ended or full (section 3.1), or requires login and the user is not
   logged in.
2. Public endpoint protections (before parsing the body): body size limit,
   rate limit per IP, honeypot field must be empty, signed minimum-fill-time
   token must be present and old enough.
3. Reject unknown field IDs in the payload.
4. Evaluate visibility in topological order using the submitted values.
5. Drop values for hidden fields.
6. For each visible input field, normalize and validate by type (options exist,
   numbers within `min` and `max`, email syntax, lengths, selection counts).
7. Compute `required` (including `require` rules) and reject missing values.
8. If the form disallows multiple submissions, enforce that for logged-in users.
   (Anonymous duplicate control is not reliable and is not promised.)
9. In one transaction: insert the entry.
10. After commit: evaluate action conditions, enqueue jobs, resolve the
    `message` or `redirect` result, return it.

Validation errors return field IDs with machine-readable codes so the client
can show them next to the right field.

### 10.1 Spam check

Between the fill-time token (step 2) and field validation, a respondent who is not logged
in must pass the administrator's spam check, unless `settings.captcha` is false. Failing it
returns the form level code `captcha_failed`. The check is skipped for logged-in users.

The administrator picks one provider for the whole server (Settings, Administration,
Advanced Webforms): `builtin` (default), `turnstile`, `hcaptcha`, or `none`. The choice,
the site key, and the secret key live in app config; the secret is stored encrypted and is
never sent to a browser. A Turnstile or hCaptcha choice without both keys counts as `none`,
so an unfinished setup never locks respondents out.

- `builtin`: proof of work in the ALTCHA v1 format (SHA-256). `GET /api/v1/public/captcha/challenge`
  returns `{algorithm, challenge, salt, signature, maxnumber}`, where `challenge = sha256(salt || n)`
  for a secret `n <= maxnumber` and `signature` is an HMAC of the challenge keyed from the instance
  secret. The salt ends in `?expires=<unix time>` (ten minutes). The browser finds `n` and sends
  `base64(json{algorithm, challenge, number, salt, signature})` as `captcha`. The server checks the
  signature, the expiry and the hash, and accepts each challenge once (distributed cache; without
  a shared cache replay protection is best effort, bounded by the expiry and the rate limits).
- `turnstile`, `hcaptcha`: the widget's token is sent as `captcha` and the server verifies it
  with the provider's siteverify endpoint. A provider that cannot be reached fails closed.

The public form response carries `captcha: {provider, siteKey} | null`; `null` means no check.

## 11. Shared test vectors

The condition evaluator exists twice (PHP for enforcement in `lib/Rules/`,
TypeScript for the live form in `src/rules/`). Both are tested against one
shared file, `tests/vectors/rules.json`. Each case has `fields` and `rules` (a
definition fragment), `values` (submitted values), and `expect.visible` and
`expect.required` (sorted field ID lists). A change to semantics changes the
vectors first, and both test suites must pass.

## 12. Open questions

1. Accept submissions against old versions indefinitely, or only for a grace
   window after a new publish?
2. Should the `message` action reuse the paragraph markdown subset? v1 says
   plain text only.
3. Do we need "hide but keep value" (hidden field values retained) for any use
   case? Current rule: hidden means discarded.
4. Per-form limits (maximum fields, maximum rules, maximum actions) to bound
   evaluation cost on public forms. Proposed starting values: 100 fields,
   100 rules, 10 actions.
5. Webhook secret storage: decided, see section 7.2.
6. Anonymous multiple-submission control: accept the limitation, or add a
   cookie-based soft limit.
7. Phone validation depth: keep the permissive format check, or validate with a
   phone number library (region aware). Check what Nextcloud already bundles
   before adding a dependency.
8. Markdown renderer and sanitizer: choose during implementation, preferring
   whatever the Nextcloud frontend stack already provides over a new dependency.
9. Nextcloud Tables as an entry storage backend: decided against for now, see
   `docs/ROADMAP.md`. Only revisit for a Tables-only mode, which needs the column
   mapping, ownership and sharing model, and the failure behaviour when Tables is
   unavailable.
10. Case-insensitive text comparison uses full Unicode lowercasing in both PHP
   (`mb_strtolower`) and JavaScript (`toLowerCase`). They agree for common text,
   but unusual characters (for example context-dependent letter forms) may
   differ. The server is authoritative, so the worst case is the browser briefly
   showing a different field set than the server accepts. Revisit only if a real
   mismatch shows up.

## 13. Import and export (DECISION)

A form can be saved to a file and brought back, on the same server or another one. The menu on each
form in the sidebar offers **Export draft** and, once the form has been published, **Export published
version**. The forms list has **Import form**.

```json
{
  "format": "webforms.form",
  "formatVersion": 1,
  "source": "draft",
  "exportedAt": 1763100000,
  "definition": { "schemaVersion": 1, "title": "...", "...": "the document from section 3" }
}
```

- `formatVersion` is the version of this wrapper. `definition.schemaVersion` is the version of the
  definition (section 3). A file with a newer value of either is refused (`import_too_new`) so an old
  app never guesses at a newer format. Anything that is not this wrapper is `import_not_a_form`.
- `source` is `draft` (what the owner is editing) or `published` (the latest published version).
  Asking for `published` before the first publish is refused (`not_published_yet`).
- **What is in the file:** the whole definition, so title, description, settings, fields, layout,
  rules and actions.
- **What is never in the file:** the form id, owner, public link, status, version history, retired ids,
  responses and the delivery log. Responses have their own CSV export (section 9.1).
- **Secrets are never in the file.** A webhook's `secretRef` is removed on export, because the secret
  it names lives encrypted on the exporting server and cannot be read back (section 7.2). It is also
  removed on import, so a file from elsewhere cannot point a form at a secret it does not own. An
  imported webhook is therefore unsigned until the owner generates a new secret for it.
- **Email addresses** typed into an email action and upload `folder` paths are part of the definition
  and are exported. The upload folder is checked against the importing owner's permissions like any
  draft (section 4.6).
- **Import always creates a new form**, as an unpublished draft owned by the importing user. It never
  overwrites an existing form. The definition is stored through the normal draft save (section 8), so
  structural problems come back as warnings and publishing remains the gate. A file that is refused
  outright (for example an oversize or title-less definition) leaves nothing behind.
- Endpoints: `GET /api/v1/forms/{id}/export?source=draft|published` (a JSON download) and
  `POST /api/v1/forms/import` with `{ "package": <the file's contents> }`, which answers 201 with
  `{ form, errors }` like a draft save.

## 14. Nextcloud events (DECISION)

Besides the per-form webhook action (section 7), the app dispatches Nextcloud events, so an
administrator can connect Flow, Windmill, n8n or any receiver to every form on the server with the
Webhook Listeners app, without each form owner setting anything up. The two routes serve different
people: the webhook action is the form owner's, aimed at one URL for one form; events are the
administrator's, server wide.

All events extend `OCP\EventDispatcher\Event` and implement `IWebhookCompatibleEvent`, so the class
name is what an administrator registers. They live in `OCA\Webforms\Event\`.

| Class | `event` | Fired when |
| --- | --- | --- |
| `EntryCreatedEvent` | `entry.created` | a response is stored |
| `EntryDeletedEvent` | `entry.deleted` | the owner deletes a response |
| `EntryStatusChangedEvent` | `entry.status_changed` | the owner gives a response another status (section 20.2) |
| `FormPublishedEvent` | `form.published` | a form is published, including a republish with no changes |
| `FormStatusChangedEvent` | `form.status_changed` | a form is unpublished, reopened or closed |

Every payload has `event`, `formId`, `formTitle` and `ownerId`. The rest:

- `entry.created`: the webhook body of section 7.3 (`versionId`, `entryId`, `submittedAt`, `userId`,
  `data` with label and value for each visible answered field). Uploaded files are described by
  name, size and type, never by where they are stored.
- `entry.deleted`: `versionId`, `entryId`, `deletedBy`. No answers.
- `form.published`: `versionId`, `versionNumber`, `newVersion` (false when the draft matched the
  latest version, so no version was added) and `publicToken`.
- `form.status_changed`: `status` and `previousStatus`, each `unpublished`, `published` or `closed`.

Rules:

- Events are dispatched after the database commit. A failing listener is logged and ignored, and
  never fails or rolls back a submission, publish or delete.
- They are not queued: delivery to a remote receiver is the Webhook Listeners app's job, which has
  its own retry and logging. The per-form webhook action keeps its own signed, retried delivery.
- `entry.created` carries personal data, and so does `publicToken`. Only an administrator can
  register a listener, so this stays an administrator's decision. Do not add more personal data
  without thinking about that.
- Deleting a whole form does not fire `entry.deleted` for each response. There is no form-deleted
  event yet.

### 14.1 Flow trigger

`OCA\Webforms\WorkflowEngine\FormsEntity` registers the events with the Nextcloud workflow engine
(Flow) as an entity called "Webforms", so "Response submitted", "Response deleted", "Response status
changed", "Form published"
and "Form status changed" can be chosen as the "When" of a flow. The event name the engine listens
for is the event class name. An administrator's flow sees every form. A user's own flow only runs for
forms that user owns. Webforms offers triggers only. The actions (what a flow does) come from other
apps, and Webforms has none yet.

## 15. Tables action (DECISION)

An action of type `tables` adds a row to a Nextcloud Tables table for each matching response:

```json
{ "id": "act_leads", "type": "tables", "tableId": 12,
  "map": { "31": "f_name", "32": "f_kind", "33": "@submitted" } }
```

- `map` is column id to source. A source is the id of an input field (so renaming a field's label
  changes nothing), or one of `@submitted` (when the response arrived), `@respondent` (the signed-in
  account, empty for anyone else) and `@entry` (the response id). Up to 100 columns.
- It runs like email and webhook (section 7.4): queued after the response is stored, retried with
  backoff, shown in the delivery log, and never able to fail a submission.
- **Permissions.** The row is added as the form owner, through Tables' own services, so Tables decides
  what is allowed. At publish the owner must be allowed to add rows to the table, every mapped column
  must still exist and be able to take its source (`tables_not_allowed`, `tables_column_missing`,
  `tables_type_mismatch`, `tables_unavailable`). The same is checked again on every run.
- **What can go where.** Text columns take any answer (choices as their labels, several as a comma
  separated list, uploads as file names). Number, stars and progress take number fields. A single
  choice column takes a select or radio, a multiple choice column takes checkboxes, and a checkbox
  column takes a checkbox. Date, time and date and time columns take date or time fields. Choices are
  matched to the column's options by label, ignoring case. People and relation columns cannot be filled.
- An answer that is empty, or a choice with no matching option, is left out of the row, and Tables
  decides whether that column was required. If nothing at all would be written the delivery is
  `skipped`, not failed.
- A column deleted from the table after publishing is skipped. Tables being disabled is a retried
  failure (`tables_unavailable`). Tables refusing the values (`tables_rejected`) and permission loss
  (`tables_forbidden`, `tables_not_found`) are not retried.
- **Risk.** Tables has no public PHP interface for writing, only its REST API (which needs a login
  session) and its internal services. The app uses the services behind `TablesPort`, so a change in
  Tables fails visibly in the delivery log and nothing else.
- Endpoints for the builder: `GET /api/v1/tables` (the tables the user may add rows to) and
  `GET /api/v1/tables/{id}/columns` (with each column's `kind` and whether it is `supported`).

### 15.1 Create a table from a form

`POST /api/v1/tables/from-form` with `{ "title": "...", "fields": [...] }` creates a Tables table owned
by the signed-in user and answers `201 { tableId, title, map }`, where `map` is ready to be the action's
`map`. The builder sends the form's input fields in order (id, type, label, and for choices their
options, for numbers the step, prefix and suffix).

- One column per input field, in form order, then a `Submitted` column filled from `@submitted`.
  Headings, paragraphs and dividers have no column.
- Text, long text, email, phone, web address and uploads become text columns (an upload is its file
  names). Numbers become number columns with the step's decimals (two when there is no step) and the
  prefix and suffix. A select or radio becomes a single choice column, checkboxes a multiple choice
  column, a checkbox a yes or no column, a date a date column, a time a time column. Choice columns get
  the field's option labels, once each.
- **Every column is optional**, even for a required field. A field hidden by a rule or left blank has
  no answer, and Tables rejects a whole row when a required column stays empty.
- The table is a snapshot. Fields added or renamed later are not copied: add the column in Tables and
  choose it in the action. Column titles are the field labels at that moment.
- If a column cannot be created the table is deleted again, so a failure leaves nothing behind.
- Refused with `422 tables_no_fields` when the form has no input field, `403 tables_forbidden` when
  Tables will not let the user create tables, and `409 tables_unavailable` when Tables is off.
  Limited to 10 per minute per user.

## 16. Server policy (DECISION)

An administrator sets server-wide rules under Administration settings, "Forms and responses". Every
value defaults to today's behaviour, so nothing changes until someone changes it. The values are app
config, read through `PolicySettings`, and a damaged number falls back to its default. A damaged list
of allowed actions allows none, the safe side.

| Setting | Default | Meaning |
| --- | --- | --- |
| Allowed actions | all of `message`, `redirect`, `email`, `webhook`, `tables`, `files` | which action types forms may use |
| Retention | 0 (off) | delete responses older than this many days, 1 to 3650 |
| Responses per form | 0 (no limit) | a form accepts at most this many, up to 1 000 000 |
| Actions per form | 10 | lower than the structural maximum of 10 only, 1 to 10 |
| Who can create forms | everyone | limit to members of chosen groups, and administrators |

- **Allowed actions.** A type that is switched off is hidden in the builder, reported on a draft as
  `action_type_disabled`, refused at publish, and not run: the planner leaves it out of new submissions,
  and a delivery that was already queued fails with `action_disabled` and is not retried. That is what
  makes switching webhooks off effective at once for forms that are already live. Existing actions are
  never deleted from a definition, so switching a type back on restores them.
- **Actions per form.** More actions than the policy allows is `policy_too_many_actions` at publish.
- **Responses per form.** When a form has this many responses its public page and submit answer
  `410 closed` with the message "This form has received the maximum number of responses." Responses sent
  at the same instant can pass the limit by one. A form can set a lower limit of its own, and its own
  message (section 3.1); the lower of the two limits counts.
- **Who can create forms.** Creating and importing a form need membership of a creator group or the
  administrator role (`create_not_allowed`). Forms that exist stay editable by their owners, and a
  group that is later deleted simply matches nobody.
- **Retention.** `RetentionJob` runs hourly and deletes the oldest due responses, at most 1000 per run so
  a first run on a big server does not hold a background job, with the same clean-up as an owner
  deleting a response: uploaded files and delivery log rows go too. Copies already sent by email,
  webhook, to Tables or to a CSV file are not touched. No `entry.deleted` event is sent for these deletions.
  Because this deletes data, saving a retention that is newly on or shorter than before is refused with
  `409 confirmation_required` and `due` (how many responses are affected) until the request repeats with
  `confirmDelete: true`. The page shows the same count before saving.
- Endpoints (administrators only): `GET` and `PUT /api/v1/admin/policy`,
  `GET /api/v1/admin/policy/retention-preview?days=N`, `GET /api/v1/admin/groups?search=`. Every signed-in
  user can read `GET /api/v1/settings` (`canCreate`, `allowedActionTypes`, `maxActions`), which the
  builder uses to hide what the server would refuse.

### 16.1 Personal preferences

Each user has their own preferences under Personal settings, "Advanced Webforms". They are user config,
so they are removed with the account, and they change nothing about forms or other people.

| Setting | Default | Meaning |
| --- | --- | --- |
| Open published forms on their responses | on | picking a form in the list opens its responses when the form is published and has no unpublished changes |

- Drafts, closed forms and published forms with unpublished changes always open in the editor, and so
  does the address `#/f/<id>` when it is typed or shared: only the links in the form list change.
- Endpoints (any signed-in user, own preferences only): `GET` and `PUT /api/v1/preferences` with
  `openPublishedOnResponses` (boolean).

### 16.2 Emails from forms

An administrator sets defaults for the mail that forms send, under Administration settings, "Emails
from forms". They are app config, read through `MailSettings`, and never configured means the behaviour
without them. They are applied when a mail is sent, not copied into forms, so a change takes effect at
once on forms that are already live.

| Setting | Default | Meaning |
| --- | --- | --- |
| Where replies go | the server's sender address | the reply-to for an email action that sets none: nothing, the form owner's account email, or one fixed address |
| Sender name | Nextcloud's own | the name mail is sent under |
| Form owners may set their own | yes | whether an action's `replyTo` and `fromName` count |

- **Order.** Reply-to is the action's own `replyTo` if it is allowed and resolves to an address, else
  the default, else none. Sender name is the action's own `fromName` if allowed, else the default, else
  Nextcloud's. A default reply-to equal to the recipient is left out.
- **Locked.** With "form owners may set their own" off, an action's `fromName` and `replyTo` are
  ignored when sending, with one exception: a `replyTo` of `{ "field": "<id>" }` still counts, because
  replying to the person who filled in the form is what a notification is for and it names nobody the
  form owner chose. On a draft and at publish such an action is reported as `action_mail_locked` (path
  `actions[n].replyTo` or `.fromName`), a warning on a draft and a refusal at publish. Forms that are
  already live keep sending. Nothing is deleted from a definition, so switching it back on restores
  the form's own choice.
- **Damaged values** fall back to the default: an unknown mode or a fixed address that is not one plain
  address means no reply-to, and only an explicit off locks.
- Endpoints (administrators only): `GET` and `PUT /api/v1/admin/mail` (`replyToMode` of `none`, `owner`
  or `address`, `replyToAddress`, `allowOverride`, `fromName`). `GET /api/v1/settings` gives every
  signed-in user the same four values under `mail`, with `defaultReplyTo` and `defaultFromName`: the
  real address and name that apply to that user's forms when an action sets none (their own account
  email, the fixed address, or the server's sender address; the default name or Nextcloud's own). The
  builder shows them in the empty boxes and hides what is locked.

## 17. Finding responses

`GET /api/v1/forms/{id}/entries` takes a filter besides `limit` and `offset`. The answer has `total` (how
many match), `grandTotal` (how many the form has), and `truncated`.

| Parameter | Meaning |
| --- | --- |
| `q` | free text, at most 200 characters |
| `from`, `to` | submitted dates, `YYYY-MM-DD` in UTC. `to` counts the whole day |
| `respondent` | `any` (default), `signed_in` or `anonymous` |
| `where` | a condition on the answers, JSON, written like a rule (section 6) |
| `sort` | `newest` (default) or `oldest`, by when it was submitted |

- **Free text** looks at what a person sees: answers, a choice by its label (never its internal value),
  an upload by its file names, and the signed-in account. Case is ignored.
- **`where`** reuses the rules engine, so an owner writes it with the same editor as a rule, with nested
  all and any groups. Each response is read against its own version of the form. A question that
  response's version never had counts as unanswered, so "is empty" is true for it and "equals" is not.
  A question that no version has any more matches nothing.
- Dates, respondent and order are answered by the database. Text and `where` need each response read
  against its own version, so they scan the form's responses in order and stop after 20 000. When that
  happens `truncated` is true and `total` is a lower bound, and narrowing the dates finds the rest.
- A malformed filter is `422 filter_invalid` with a `reason`.
- The Responses view shows a table by default, with a column for each question (union across versions,
  newest labels), a sortable submitted column, and cards as an alternative. The columns a person picks
  are remembered in their browser for that form.

### 17.1 Downloading and deleting several responses

**Download.** `GET /api/v1/forms/{id}/entries/export` takes the same filter parameters as the list, plus:

| Parameter | Meaning |
| --- | --- |
| `format` | `csv` (default) or `xlsx` |
| `ids` | comma separated response ids, at most 500. When given, the filter is not used |
| `columns` | comma separated field ids. Only these questions, in form order. Unknown ids are ignored. Without it, every question |

The three fixed columns (response id, submitted in UTC, respondent) are always there. Responses are
written oldest first, up to 20 000. CSV keeps its leading apostrophe on text that starts with `=`, `+`,
`-` or `@`, the only defence a CSV has. The Excel file stores text as text, so a response can never run as
a formula and needs no apostrophe, and keeps numbers as numbers so they can be summed. Ids of another
form are ignored, and a bad format, list or filter is `422`.

**Delete.** `POST /api/v1/forms/{id}/entries/bulk-delete` takes either `{ "ids": [...] }` (at most 500) or
`{ "filter": {q, from, to, respondent, where}, "expected": N }`. It does the same clean-up as deleting
one response: uploaded files, delivery log rows, and an `entry.deleted` event for each. A filter is
deleted only if it still matches exactly `expected` responses, the number the person confirmed, and
otherwise nothing is deleted and the answer is `409 count_changed` with `current`. More than 5 000
matches, or a search that stopped early, is refused (`422 too_many_matches`): narrow it, or let retention
(section 16) do it.

The Responses view ticks rows in the table. Ticking the whole page offers "select all N matching",
which uses the filter route. One Download menu follows what is ticked, else what the filter matches,
else everything, and passes the columns the person chose.

### 17.2 Summary

`GET /api/v1/forms/{id}/entries/summary` takes the list's filter (not `sort`, `limit` or `offset`) and
answers what the matching responses add up to:

```json
{ "total": 62, "truncated": false,
  "timeline": { "unit": "day", "buckets": [{ "start": "2026-11-14", "count": 3 }] },
  "fields": [
    { "id": "f_kind", "label": "Kind", "type": "select", "kind": "choice", "answered": 60,
      "options": [{ "value": "opt_sup", "label": "Support", "count": 14 }] },
    { "id": "f_score", "label": "Score", "type": "number", "kind": "number", "answered": 38,
      "min": 1, "max": 5, "average": 3.53, "sum": 134 },
    { "id": "f_ok", "label": "Agree", "type": "checkbox", "kind": "yesno", "answered": 62, "yes": 38, "no": 24 }
  ] }
```

- **Timeline.** Responses per UTC day from the first day that has one to the last, empty days included.
  More than 92 days is given per month, `start` being the first of the month.
- **Fields.** Select, radio and checkboxes are `choice`, a number is `number`, a single checkbox is
  `yesno`. Other questions are not summarised. Each response is read against its own version. Labels,
  option names and the order come from the newest version that has them, and an option only an older
  version had comes last. Every option is listed, also with no picks. A stored value with no option any
  more counts under its own name. `answered` is how many responses answered the question, so the shares
  of a checkboxes question can add up to more than 100 percent.
- `truncated` is true when the walk stopped at 20 000 responses, and the numbers then cover those only.
- The Responses view shows it above the table: thin columns for the timeline with a readout on hover
  and with the arrow keys, horizontal bars in the form's own option order with the count and share at
  the tip, and figures (not a chart) for numbers. Screen readers get the timeline as a table. It follows
  the filters, and whether it is open is remembered in the browser.

## 18. Sharing (DECISION)

How a form reaches people: its link, a custom address, a QR code, and a frame on another website. These
options belong to the form, not to a published version. They are columns on `advforms_forms`, they are
saved with `PUT /api/v1/forms/{id}/share` (`slug`, `embed`, `embedOrigins`) and take effect at once,
without publishing. They are not part of the definition, so they are not in an export file and an
imported form starts without them.

### 18.1 Link and custom address

- Publishing gives a form a random token, and `/apps/webforms/f/<token>` is its link. That does not
  change.
- A form can also have one custom address (`slug`), for example `/apps/webforms/f/contact`. Both
  addresses lead to the same form, and every public route that takes a token takes the custom address
  too. A lookup tries the token first, so a custom address can never take over another form's link.
- A custom address is 3 to 64 characters: lowercase letters, digits and hyphens, starting and ending
  with a letter or digit, with no two hyphens in a row (`slug_invalid`). A few words the app's own
  addresses use are refused (`slug_reserved`). It is unique on the server, first come first served
  (`slug_taken`), and a form keeps it while archived. Deleting the form or clearing the field frees it.
- A custom address can be guessed, which the token cannot. The form still has to be published and open
  to be seen, and `requireLogin` still applies. The builder says so where the address is set.
- Changing or removing a custom address breaks links, QR codes and embed codes that used it. The token
  link keeps working.
- It can be set before the first publish. It leads nowhere until the form is published.

### 18.2 QR code

The builder draws a QR code of the link (the custom address when there is one) and offers it as PNG
and SVG. It is made in the browser from the link alone, black on white with the standard quiet zone,
at error correction level M. Nothing is stored and no service is called.

### 18.3 Embedding in another website

Off by default. `embed` (boolean) allows it for a form, and `embedOrigins` optionally limits it to a
list of at most 20 websites, each written as an origin: scheme, host and optional port, with an optional
leading `*.` for subdomains (`embed_origins_invalid` otherwise). An empty list allows any website.

- **The page.** `/apps/webforms/f/<token>/embed` is the form without Nextcloud's header and page
  background. Nextcloud forbids framing by other sites, so for a form that allows embedding this page
  answers with a `frame-ancestors` policy naming the listed origins, or `*`. For any other form, and
  for an address that leads nowhere, nothing is opened up and the browser refuses to draw the frame.
  The ordinary page `/f/<token>` can never be framed. Origins are validated when saved and again when
  read, because they end up in a response header.
- **No session.** A browser does not send Nextcloud's session cookie from a frame on another site, so
  a request token can never match there. The embedded form therefore uses its own routes, which do
  without the request token: `GET /api/v1/public/{token}/embed`, `POST .../embed/submit` and
  `POST .../embed/upload`. They answer `404` unless the form allows embedding, and they always treat
  the respondent as anonymous, even if a session cookie did arrive. That is what makes leaving out the
  request token safe: there is no signed-in identity for another site to act as. Everything else is
  the same as section 10: fill token, honeypot, spam check and rate limits.
- **Consequences of being anonymous.** A form with `requireLogin` shows "login required" in the frame
  with a link that opens the form on its own page. Upload fields limited to signed-in users cannot be
  used. `allowMultipleSubmissions: false` has nothing to go by, as for any anonymous respondent.
- **Height.** A frame cannot size itself to its content. The builder shows one code with a switch: a plain
  `<iframe>` of a fixed height (HTML only, the form scrolls inside it), or the same with a short
  script. The embedded page posts `{ type: "webforms:height", height }` to its parent whenever its
  height changes, and the script sets the frame's height from messages that come from that frame.
- **Look.** The embedded page has a transparent background, so the website shows around the form, and
  it is as tall as the form instead of pinned to the window. It always uses the light theme and the
  light colour scheme: the form cannot see how the website looks, and a browser paints a solid backdrop
  behind a frame whose colour scheme differs from the page around it. A website that declares a dark
  colour scheme therefore gets a white backdrop. The form's own design settings (card, accent colour,
  background colour) apply as usual.
- **Redirect.** A `redirect` action takes the whole page to the address, not only the frame. If the
  browser does not allow that, the frame itself goes there.
- **Not done yet.** A server-wide switch for administrators to forbid embedding, and a form rendered
  as plain HTML with no script (ROADMAP.md).

## 19. Files action (DECISION)

An action of type `files` adds a line to a CSV file in the form owner's Nextcloud Files for each
matching response:

```json
{ "id": "act_sheet", "type": "files", "path": "Webforms/Contact responses.csv" }
```

- `path` is the file, relative to the owner's home: names joined by `/`, no `.` or `..` parts, no
  control characters or backslashes, at most 8 parts and 255 bytes, ending in `.csv`
  (`action_bad_file_path` otherwise). The builder suggests `Webforms/<form title> responses.csv`.
- It runs like email, webhook and Tables (section 7.4): queued after the response is stored, retried
  with backoff, shown in the delivery log with the path as its target, and never able to fail a
  submission.
- **Columns and values** are those of the CSV export (section 9.1) for the version the response was
  submitted against: `Response ID`, `Submitted (UTC)`, `Respondent`, then one column per input field in
  layout order, headed by its label (`Label (field_id)` when two fields share one). Values read the
  same way, and text that a spreadsheet would run as a formula gets a leading apostrophe.
- **A new file** is created with a byte order mark, the header row and the response, with CRLF line
  endings and RFC 4180 quoting. Folders on the way are created.
- **An existing file** keeps its own header row. Columns are matched to it by their heading, so the
  owner may reorder columns or add their own. A heading the file does not have yet (a new field, or a
  renamed label) is added to the end of the header row; rows already in the file simply have no value
  there. Columns the form no longer fills stay and get an empty cell. A file that is empty (or only a
  byte order mark) gets a new header row. A file without a final line break gets one first.
- **Permissions.** The file is written as the owner, so Nextcloud's own rules (quota, locking,
  versions, antivirus) apply. At publish the owner must be able to change the file, or create it in
  the nearest folder that exists (`files_not_writable`); the same is checked again on every run.
- **Failures.** No permission (`files_not_writable`), a folder where the file should be or a file where
  a folder should be (`files_not_a_file`), and a file over 20 MB (`files_too_large`, start a new file)
  are not retried. The file being in use (`files_busy`), a full quota (`files_storage_full`) and other
  storage errors (`files_error`) are.
- Two deliveries never write the same file at once: each holds a lock on it while it reads and
  writes, and one that finds it locked is retried.
- Like Tables, the file is a copy: deleting a response or the form does not remove its line, and
  retention (section 16) does not touch the file.

## 20. New responses, statuses and a note (DECISION)

Three small things an owner can do with responses: see which ones arrived since the last look, give a
response a status, and keep a note on it. It is deliberately not a workflow. Nothing is assigned to
anyone and nothing has to be moved through steps. None of it is part of the form definition, it takes
effect at once, and it is never shown or sent to the respondent.

### 20.1 New responses

A response is **new** when it arrived after the owner last opened the form's list of responses. It is
not a status and nobody sets it: it follows from one moment kept per form.

- `advforms_forms` gets `responses_seen_at` (Unix seconds, nullable). A response is new when its
  `submitted_at` is later. Empty means the list was never opened, so every response is new.
- Opening the Responses view calls `POST /api/v1/forms/{id}/entries/seen`. It sets the moment to now and
  answers `{ "previousSeenAt": ... }`, the moment before this visit. The view marks the responses that
  are later than that as new, and keeps marking them until the owner leaves the view, so they do not
  lose the mark while being read. Paging, filtering and sorting do not move the moment.
- The next visit starts clean: only what arrived in between is new.
- `GET /api/v1/forms` gives each form `newCount`, how many responses are later than its moment, and the
  forms list shows it beside the form. It is one count on the existing `form_id, submitted_at` index.
- Forms that already exist start with the moment empty. After the update each of them shows all its
  responses as new, once, until the owner opens its list.
- Opening the builder, a download, the summary or a single response does not count as looking at the
  list.

### 20.2 Statuses

A response has one status or none. A status is a label for the owner's own sorting.

**The list is the administrator's.** Under Administration settings, "Response statuses", the
administrator keeps one list of statuses for the whole server, and every form uses it. It is app config
like the settings of section 16.

- A status has an `id` (like a field ID: `^[a-z][a-z0-9_]{2,31}$`, made from the name when it is
  created and never changed, and never `none`), a `label` (1 to 40 characters) and a `color` (one of
  `gray`, `red`, `orange`, `yellow`, `green`, `teal`, `blue`, `purple`). At most 20 statuses, shown in
  the order the administrator puts them in.
- A fresh server starts with `in_progress` ("In progress"), `done` ("Done") and `spam` ("Spam"). They
  are ordinary statuses: the administrator can rename, reorder or delete them. Their labels are
  translated only until the list is first changed.
- Renaming a status changes it everywhere, because responses store the `id`.
- Deleting a status clears it from every response that has it, on all forms. Because that changes
  responses, saving a list that leaves out a status some responses have is refused with
  `409 confirmation_required` and `affected` (how many responses) until the request repeats with
  `confirmDelete: true`.
- Endpoints (administrators only): `GET` and `PUT /api/v1/admin/statuses`. The `PUT` takes
  `{ "statuses": [{id, label, color}, ...], "confirmDelete": false }` and replaces the whole list. A bad
  list is `400` with `status_invalid`, `status_id_invalid`, `status_id_duplicate`, `status_label_invalid`,
  `status_color_invalid` or `too_many_statuses`.
- A damaged list reads as empty, and responses then show no status until it is repaired. Stored ids
  are not touched.

**On a response.** `advforms_entries` gets `status` (string, 32, nullable) with an index on
`form_id, status`, so filtering by status is answered by the database and never needs the scan of
section 17. A response arrives with none.

- `PUT /api/v1/forms/{id}/entries/{entryId}/status` with `{ "status": "done" }`, or `null` to clear it.
  An id that is not in the list is `422` with the error code `status_invalid`.
- `POST /api/v1/forms/{id}/entries/bulk-status` takes `{ "status": ..., "ids": [...] }` (at most 500) or
  `{ "status": ..., "filter": {...}, "expected": N }`, with the same rules as the bulk delete of section
  17.1: `409 count_changed` when the filter no longer matches the number the person confirmed, and
  `422 too_many_matches` above 5 000. It answers how many changed.
- `GET /api/v1/forms/{id}/entries` takes `status`, a comma separated list of ids, where `none` means
  responses without one. Each response in the answer has `status` and `note`. The summary, the
  download and the bulk delete filter take the same parameter.
- The owner's app reads the list from `GET /api/v1/settings` (`statuses`). With an empty list it shows
  no status column, filter or menu. A response that still carries the id of a deleted status shows
  none.
- The table shows the status as a coloured label in a column of its own, changed from a menu on the
  row, and ticked rows can be given a status together.
- A status changes nothing else. A response marked "Spam" is still in the list, the summary and the
  count for the response limit, and what its actions already did stays done. Filter it out to hide it.

**In a download.** The CSV and Excel files get a fourth fixed column, `Status`, after `Respondent`,
holding the label. The Files action (section 19) and the Tables action (section 15) do not: they run
when a response arrives, when it has no status, and they are copies that are not followed afterwards.

**Event.** One more event in the pattern of section 14: `EntryStatusChangedEvent`,
`entry.status_changed`, with the common keys plus `versionId`, `entryId`, `status`, `previousStatus`
(each an id or `null`) and `changedBy`. No answers. Setting the status a response already has fires
nothing, a bulk change fires one event per response that changed, and a status deleted by the
administrator fires none. It is registered with Flow as "Response status changed" (section 14.1).

### 20.3 A note

A response can carry one note: plain text, at most 4 000 characters, line breaks kept. It is a column
on `advforms_entries` (`note`, text, nullable).

- `PUT /api/v1/forms/{id}/entries/{entryId}/note` with `{ "note": "..." }`. Empty text removes it, and
  more than 4 000 characters is `422` with the error code `note_too_long`.
- The table shows a mark on a response that has a note, and the note is read and edited on the
  response's card and page.
- **Private.** The note is never in an email, a webhook body, an event payload, a Tables row, the Files
  action or a download, and no public route can read it. Free text search (section 17) does not look in
  it.
- It goes when the response is deleted.

### 20.4 A page for one response

`#/f/<id>/responses/<entryId>` in the owner's app shows one response by itself. It is an address, so it
can be bookmarked and reached with the back button. It only opens for the form's owner.

- `GET /api/v1/forms/{id}/entries/{entryId}` answers `entry` (the response as in the list), `version`
  (the definition of its own version), and `previousId` and `nextId`, the neighbours by when they were submitted, so the
  page can step through responses without going back to the table.
- The page shows every question of that version in layout order with its answer, read like the emails
  do (option labels, yes or no, file names as download links). A question the response did not answer
  is shown as unanswered instead of left out, so the page reads like the form.
- Beside the answers: the status, the note, when it was submitted and by whom, and the deliveries of
  this response from the delivery log (section 9.2).
- **Print.** The page has a print style: the answers, the form's title, the response id and the date.
  Navigation, the status, the note and deliveries are not printed. This is the browser's own print,
  which can save a PDF. The app generates no PDF (a non-goal, section 1).
- The table and the cards link each response to its page. A response of another form, or one that was
  deleted, is `404`.

### 20.5 Left out on purpose

- **Assignment.** Forms are not a task tool. Handing a response to someone is what Tasks and Deck are
  for, and a flow on "Response submitted" or "Response status changed" can create one there.
- Statuses per form or per user. One list for the server keeps it simple.
- Hiding responses of a status by default, or leaving them out of the response limit.
- Several notes, authors, mentions and notifications.
