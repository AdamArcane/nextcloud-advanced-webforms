<!--
  - SPDX-FileCopyrightText: 2026 ArcaneX
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Read-only API

For tools such as n8n, scripts and other software that need to read forms and responses. It only reads.
The full specification is [SCHEMA.md section 23](SCHEMA.md); this page is the short version.

## Before you start

1. In Nextcloud, open **Personal settings, Security** and create an **app password** with a name for the tool,
   for example "n8n". Use the account that owns the form, or one a form is shared with (Reviewer or above).
   Revoke the app password there when the tool is no longer needed.
2. Ask your administrator if the API answers `404`: they can switch it off under **Administration settings,
   Forms and responses**.

## Calling it

Every call needs basic authentication (account name and app password) and two headers:

```bash
curl -u 'ada:APP-PASSWORD' \
  -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  'https://cloud.example.com/ocs/v2.php/apps/webforms/api/v1/forms'
```

The answer is wrapped the way Nextcloud wraps every OCS answer: the useful part is under `ocs.data`.

| Route (after `/ocs/v2.php/apps/webforms/api/v1`) | What it returns |
| --- | --- |
| `/forms` | the forms you can open |
| `/forms/{id}` | one form, with the questions of its latest published version |
| `/forms/{id}/versions` and `/forms/{id}/versions/{versionId}` | published versions, and what each field meant in one |
| `/forms/{id}/responses` | responses (filters below) |
| `/forms/{id}/responses/{responseId}` | one response |
| `/forms/{id}/summary` | counts per choice, number ranges and responses per day |
| `/forms/{id}/responses/export` | a CSV file |

A response looks like this. `answers` is keyed by field id, and the `versionId` says which version to read the
field ids against.

```json
{
  "id": 8, "versionId": 1, "submittedAt": 1795210517, "respondent": null, "status": null,
  "answers": { "f_first": "Omar", "f_email": "omar@example.org", "f_topics": ["compost", "seeds"] }
}
```

An uploaded file is only `{name, size, mime}`, and a signature is `true`. The owner's private notes are never
included. A form you cannot open is `404`.

## Getting only new responses (polling)

Ask for responses `after` the last id you saw. They come back oldest first, and `nextAfter` is the id to send
next time:

```bash
curl -u 'ada:APP-PASSWORD' -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
  '.../forms/12/responses?after=41&limit=100'
```

Keep that one number between runs. Ids only grow, so nothing is missed and nothing repeats. Other filters:
`from`, `to` (dates, UTC), `status`, `respondent` (`any`, `signed_in`, `anonymous`), `q` (text), `sort`
(`newest` or `oldest`) and `limit` (1 to 200).

## In n8n

Use an **HTTP Request** node: method `GET`, the URL above, **Authentication: Generic credential type, Basic
Auth** (account name and app password), and two headers, `OCS-APIRequest: true` and `Accept: application/json`.
In a **Schedule Trigger** workflow, store `ocs.data.nextAfter` in the workflow's static data and send it as
`after` on the next run. To be told the moment a response arrives instead of asking, use the form's webhook
action (SCHEMA.md section 7).
