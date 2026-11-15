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
never carry CSS).

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
| `file` | list of files | uploaded files, see 4.6 |

Display types (no value, cannot be rule sources, can be rule targets):

| type | properties |
| --- | --- |
| `heading` | `text`, `level` (1 to 6, default 2, rendered as `h1` to `h6`), `align` |
| `paragraph` | `text` (limited markdown, see 4.5), `align` |
| `divider` | `style` (`solid` default, `dashed`, `dotted`) |

Deferred: hidden fields, rating, matrix, signature.

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
- `inline` always renders as `above` for `textarea`, `radio`, `checkboxes`, and
  `checkbox`. Their label either belongs to a group or is part of the control,
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
- `number`: numeric. A non-numeric stored value counts as empty.
- `date` and `time`: lexical comparison on the normalized string formats above.
- `checkbox`: compares as boolean (`eq` with `true` or `false`).
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
- `number`: a JSON number, or a string like `12` or `-3.5`. Anything else
  (including exponent forms like `1e3`) counts as empty.
- `select`, `radio`: a non-empty string, compared exactly. Any other type counts
  as empty.
- `checkboxes`: a list of strings. Any other type counts as empty.
- `checkbox`: a boolean. Any other type counts as empty.
- `date`, `time`: strings in the formats from section 4.2, anything else counts
  as empty.

Operators allowed per field type (a rule using any other operator is rejected
on save, and evaluates to false if it ever reaches the engine):

| field type | operators |
| --- | --- |
| `text`, `textarea`, `email`, `phone`, `url` | `eq`, `neq`, `contains`, `not_contains`, `in`, `not_in`, `empty`, `not_empty` |
| `number` | `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `empty`, `not_empty` |
| `select`, `radio` | `eq`, `neq`, `in`, `not_in`, `empty`, `not_empty` |
| `checkboxes` | `contains`, `not_contains`, `empty`, `not_empty` |
| `checkbox` | `eq`, `neq` |
| `date`, `time` | `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `empty`, `not_empty` |

Rule values must fit the source field: for `select`, `radio`, and `checkboxes`
they must be existing option values, for `number` a number, for `checkbox` a
boolean, for `date` and `time` a valid string. `in` and `not_in` take a
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
- Targets may be any field.
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
  - `subject`, `body`: plain text with `{field_id}` placeholders.
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

Entry access goes through one class (`EntryMapper`) so the storage can be
swapped. **Planned integration:** an optional backend that stores submissions
as rows in Nextcloud Tables (one table per form, one column per field, mapped
from the entry's version). Open design points: how a field rename or type change
maps to Tables columns, whether the table is created and owned by the form
owner, and whether entries are written to both stores or only to Tables.

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

1. Load the form and the submitted version. Reject if the form is closed or
   requires login and the user is not logged in.
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
9. Nextcloud Tables as an entry storage backend (see section 9): decide the
   column mapping, ownership and sharing model, and the failure behaviour when
   Tables is unavailable. Not in v1.
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

`OCA\Webforms\WorkflowEngine\FormsEntity` registers the four events with the Nextcloud workflow engine
(Flow) as an entity called "Webforms", so "Response submitted", "Response deleted", "Form published"
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
