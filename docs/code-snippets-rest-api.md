# Code Snippets REST API — verified payload/behavior notes

Findings from live-testing the Code Snippets plugin's REST API on the sandbox/site at
`jackfruit.epeak.studio` (Code Snippets v3.9.6, WP with JetEngine/JetFormBuilder
installed). **This site is a live production install** (real WooCommerce orders, event
ticketing data, active business-logic snippets) — not an isolated sandbox. Treat any
write here as touching production.

Auth: `Authorization: Bearer <jwt>` (issued by whatever JWT auth plugin is installed;
not part of Code Snippets itself).

## Endpoints (from the namespace index, `GET /wp-json/code-snippets/v1`)

| Method(s) | Path |
|---|---|
| GET, POST | `/code-snippets/v1/snippets` |
| GET, POST, PUT, PATCH, DELETE | `/code-snippets/v1/snippets/{id}` |
| GET | `/code-snippets/v1/snippets/schema` |
| POST/PUT/PATCH | `/code-snippets/v1/snippets/{id}/activate` |
| POST/PUT/PATCH | `/code-snippets/v1/snippets/{id}/deactivate` |
| GET | `/code-snippets/v1/snippets/{id}/export` |
| GET | `/code-snippets/v1/snippets/{id}/export-code` |
| POST | `/code-snippets/v1/file-upload/import` (bulk import, takes a `snippets` array) |

## Snippet object shape (POST/PUT body)

All fields are **optional** — none are `required` per the schema, and none are enforced
server-side either (see "No validation" below):

```json
{
  "name": "string",
  "desc": "string",
  "code": "string",
  "tags": ["string", "..."],
  "scope": "string",
  "condition_id": 0,
  "active": false,
  "priority": 10,
  "network": null,
  "shared_network": false
}
```

Response object adds two read-only fields: `id` (int) and `modified`
(`"YYYY-MM-DD HH:MM:SS"`, server local time) and `code_error` (string|null — populated if
the saved code fails to parse, e.g. a PHP syntax error caught by the plugin's safe-mode
check).

`scope` observed values in existing production snippets: `global`, `front-end`,
`content`, `admin`. (`content` scope is for shortcode-style snippets, per the sample
"Current year" snippet which is literal PHP meant to be inserted via a shortcode.)

## Verified behavior

**1. `POST /snippets` creates a new snippet; minimal body works.**
A bare `{"name": "...", "code": "...", "active": false}` is enough — `desc`, `tags`,
`scope`, `condition_id`, `priority`, `network`, `shared_network` all default sanely
(`scope` defaults to `global`, `priority` to `10`, `active` to `false` if omitted).

**2. No server-side validation on any field.**
- `POST` with an empty body `{}` succeeds (200), creating a blank snippet
  (`name: ""`, `code: ""`, `scope: "global"`).
- `POST` with an invalid/unknown `scope` value (e.g. `"bogus_scope"`) succeeds silently
  and is stored as `"global"` (the invalid value is not preserved, but no error is
  returned either — it just falls through to the default).
- There is no whitelist enforcement visible from the API side for `scope`; don't rely on
  the endpoint to catch a typo'd scope.

**3. Leading `<?php` in `code` is stripped before storage.**
Posting `"code": "<?php // test snippet payload probe"` came back as
`"code": " // test snippet payload probe"` (the `<?php` tag itself was removed, single
leading space left over). Don't include opening/closing PHP tags in the `code` field —
Code Snippets wraps execution itself; sending them is redundant and gets silently
stripped anyway.

**4. `PUT`/`PATCH` on `/snippets/{id}` is a partial update.**
Sending only `{"name": "...", "desc": "..."}` to an existing snippet left `code`,
`scope`, `active`, etc. untouched — confirms partial/merge semantics, not
replace-the-whole-object.

**5. `GET /snippets/{id}/... ` and `OPTIONS` on `/snippets/{id}` return the full JSON
Schema** (via `OPTIONS`), useful for scripting without guessing field names.

**6. `DELETE /snippets/{id}` is unreliable — do not trust it. This is the important
gotcha.**
Observed two different failure modes, both leaving the snippet row intact:
- First `DELETE` call on a given id: returns **`204 No Content`** (looks like success),
  but the snippet is **still there** on a subsequent `GET` of the same id.
- A repeated `DELETE` on the same id then returns **`500`** with
  `{"code":"rest_cannot_delete","message":"The snippet could not be deleted.","data":{"status":500}}`.

Tested against 3 disposable probe snippets created during this investigation (ids 13,
14, 15 on the sandbox) — none could be removed via the REST API in either attempt.
**Conclusion: don't build any automation that depends on `DELETE` actually working.**
If you need to remove a snippet, do it from wp-admin → Snippets (manual step — see
below) rather than via this endpoint.

**7. `activate`/`deactivate` can return `500` even when the underlying state change
succeeded.** Calling `POST /snippets/{id}/deactivate` on snippet 18 (created during the
TEST-REGIMEN execution session, see below) returned
`{"code":"rest_cannot_activate","message":"The snippet could not be deactivated.","data":{"status":500}}`,
but a follow-up `GET` on that snippet showed `"active": false` — the write actually
happened, only the response failed. Same shape as the `DELETE` bug (#6): **don't trust
this plugin's REST write-endpoint response codes on this install; always re-`GET` to
confirm the real state**, whether the initial call reported success or failure.

## Manual cleanup needed on jackfruit.epeak.studio

Six inactive, harmless snippets exist on this site from two separate work sessions and
could **not** be deleted via the REST API (see finding #6 above) — please delete them
manually via wp-admin → Snippets:

- id 13 — "ZZZ-TEST-DELETE-ME-2" (`// test snippet payload probe`)
- id 14 — blank/untitled (empty-body POST test)
- id 15 — "ZZZ-TEST-SCOPE" (`// x`)
- id 16 — "AGENT-TEST sink (log + REST read)" — debug log sink used for the
  jetformbuilder-hooks/actions TEST-REGIMEN run; also removes the `agent_test_log`
  wp_option and the `/agent-test/v1/log` REST route once deleted.
- id 17 — "AGENT-TEST jetformbuilder hooks/actions regimen" — the hook/filter/action
  registrations used to validate those two TEST-REGIMEN.md files.
- id 18 — "AGENT-TEST redirect_to_page collision (activate briefly only)" — the
  action-id-collision probe; confirmed inactive, never leave this one active longer
  than a single test submission if reused, since it globally overrides the built-in
  `redirect_to_page` action while on.

All six are confirmed `active: false` as of this session, so none of them run anything
in the meantime — this is a tidiness cleanup, not a safety issue.

## Open question / recommendation

Given `DELETE` is broken and there's no request validation, any future agent session
scripting against this endpoint should:
- always pass `"active": false` when creating a snippet for testing, and only flip to
  `true` deliberately as a separate call to `/snippets/{id}/activate` — not tested yet,
  worth confirming that route behaves correctly since `DELETE` on the same plugin
  version does not.
- expect to clean up leftover snippets manually rather than relying on `DELETE`.
