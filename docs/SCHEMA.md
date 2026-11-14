<!--
  - SPDX-FileCopyrightText: 2026 Adam and Advanced Forms contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Advanced Forms: form definition spec

Status: DRAFT v0.2 (design review, no code yet)

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

- Multi-page forms (schema reserves `pages`, v1 allows exactly one).
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
    "requireLogin": false,
    "allowMultipleSubmissions": true,
    "closedMessage": "This form is no longer accepting responses."
  },
  "fields": {
    "f_name": { "type": "text", "label": "Name", "required": true },
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

## 4. Fields

Fields live in a map keyed by field ID. The layout references fields by ID, so
moving a field in the layout never changes its identity.

### 4.1 Field IDs (DECISION)

- Pattern: `^[a-z][a-z0-9_]{2,31}$`, conventionally prefixed `f_`.
- Unique within a form, opaque, immutable.
- Never positional and never derived from the label.
- IDs of deleted fields stay reserved for that form so old entries cannot be
  confused with a new field that reuses the ID.

### 4.2 Field types (v1)

Input types (produce a value):

| type | value type | notes |
| --- | --- | --- |
| `text` | string | single line, optional `maxLength` |
| `textarea` | string | multi line, optional `maxLength` |
| `email` | string | validated as an email address |
| `phone` | string | validated as a phone number, see 4.4 |
| `number` | number | optional `min`, `max`, `step` |
| `select` | string | one of `options[].value` |
| `radio` | string | one of `options[].value` |
| `checkboxes` | string[] | subset of `options[].value`, optional `minSelected`, `maxSelected` |
| `checkbox` | boolean | single yes/no, `required` means must be true |
| `date` | string | `YYYY-MM-DD` |
| `time` | string | `HH:MM` 24 hour |

Display types (no value, cannot be rule sources, can be rule targets):

| type | properties |
| --- | --- |
| `heading` | `text`, `level` (2 to 4) |
| `paragraph` | `text` (limited markdown, see 4.5) |
| `divider` | none |

Deferred: file upload, hidden fields, rating, matrix, signature.

Common properties for input fields: `label` (required), `description`,
`required` (default false), `defaultValue`, `hideLabel` (default false).

`placeholder` (optional string) is supported on `text`, `textarea`, `email`,
`number`, `phone`, and `select` (for `select` it is the empty-choice prompt).

`hideLabel`: the label is visually hidden but stays in the DOM for screen
readers (for example as a visually hidden element tied to the input). `label`
stays required because accessibility, entry display, and exports depend on it.
A placeholder is not a substitute for a label.

All text properties are plain text and must be escaped by the renderer, with
one exception: `paragraph.text` accepts limited markdown (section 4.5). Raw
HTML is never interpreted anywhere (avoids stored XSS on public pages).

### 4.3 Option values

`options[].value` follows the same ID pattern as field IDs and is immutable;
`label` can change freely. Rules compare option values, never labels.

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

## 5. Layout: pages > rows > cells (DECISION)

- `pages`: ordered. v1 validation requires exactly one page.
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
| `empty`, `not_empty` | no value / has value | none |

Deliberately not included: regular expressions. User-supplied patterns run
against public input are a ReDoS risk. Revisit only with a safe engine.

Comparison semantics (**DECISION**, must be identical in PHP and TypeScript):

- Option values (`select`, `radio`, `checkboxes`): exact string match.
- Free text (`text`, `textarea`, `email`): trimmed, case-insensitive.
- `phone`: compared on digits and a leading `+` only, so spacing and punctuation
  do not matter.
- `number`: numeric. A non-numeric stored value counts as empty.
- `date` and `time`: lexical comparison on the normalized string formats above.
- `checkbox`: compares as boolean (`eq` with `true` or `false`).
- Empty means: missing, `null`, empty string after trim, or empty array.
- A comparison against an empty value is false, except `empty`, `neq`,
  `not_contains`, and `not_in`, which are true.
- A rule whose source field is itself hidden sees that field as empty.

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
  - `to`: either a static address set by the form owner, or `{ "field": "<id>" }`
    pointing at an `email` field (confirmation to the submitter).
  - `subject`, `body`: plain text with `{field_id}` placeholders.
  - Sent through Nextcloud's configured mailer.
  - Recipient from respondent input is only allowed from an `email` field and is
    always rate limited per form, because it is an abuse vector (spam relay).
  - Placeholder values are inserted as plain text, and header injection
    (newlines) is stripped from `subject`.
- `webhook`:
  - `url`: https URL set by the form owner (no placeholders in the URL).
  - POST with a JSON body (section 7.3), HMAC-SHA256 signature header computed
    with a per-action secret, 10 second timeout.
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
- Each job retries with backoff (limit to be tuned, initial proposal: 5 tries).
- Every attempt is recorded in an action log (entry, action, status, attempt,
  error, time). The owner can view it and retry manually.
- `message` and `redirect` are the exception: they are resolved synchronously
  and returned in the submit response, because the respondent needs them now.

## 8. Versioning (DECISION)

- A form has one mutable **draft** definition.
- **Publish** validates the draft and stores an immutable **version** (numbered
  1, 2, 3, ...). Published versions are never edited.
- The public endpoint serves the latest published version.
- A submission carries the `versionId` it was rendered from. The server validates
  it against that version, so someone who opened the form before an edit still
  submits against the schema they saw. Submissions against a version older than
  the latest are accepted by default (see open questions).
- Entries store the `versionId`. Entry display and export map values through
  that version's definition, so renamed or deleted fields still render correctly.

## 9. Entry storage

Initial proposal, one row per entry:

| column | notes |
| --- | --- |
| `id` | primary key |
| `form_id`, `version_id` | foreign keys |
| `submitted_at` | UTC timestamp |
| `user_id` | nullable, set only for logged-in respondents |
| `data` | JSON text, keyed by field ID, only visible fields |

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

## 11. Shared test vectors

The condition evaluator exists twice (PHP for enforcement, TypeScript for the
live form). Both are tested against one shared file of cases, `tests/vectors/`,
each case being: definition fragment, submitted values, expected visible set,
expected required set. A change to semantics changes the vectors first.

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
5. Webhook secret storage: per-action secret kept encrypted with Nextcloud's
   crypto helper; confirm the exact mechanism during implementation.
6. Anonymous multiple-submission control: accept the limitation, or add a
   cookie-based soft limit.
7. Phone validation depth: keep the permissive format check, or validate with a
   phone number library (region aware). Check what Nextcloud already bundles
   before adding a dependency.
8. Markdown renderer and sanitizer: choose during implementation, preferring
   whatever the Nextcloud frontend stack already provides over a new dependency.
