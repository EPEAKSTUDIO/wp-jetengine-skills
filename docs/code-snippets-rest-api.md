# Code Snippets REST API — verified payload/behavior notes

Findings from live-testing the Code Snippets plugin's REST API on the sandbox/site at
`jackfruit.epeak.studio` (Code Snippets v3.9.6, WP with JetEngine/JetFormBuilder
installed). This site carries real-looking WooCommerce/event-ticketing data and several
live business-logic snippets (it's a multi-purpose plugin test install), but the site
owner has confirmed **it is not production** — it's fine to test freely here, including
creating CPTs/CCTs/taxonomies/queries, as long as test artifacts stay clearly namespaced
(`AGENT-TEST` / `agent_test_*` / `ZZZ-TEST*`) and are kept (not deleted) with a note of
what they validate, per this repo's convention.

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

## Inventory of test snippets kept on jackfruit.epeak.studio

Since `DELETE` doesn't reliably work (finding #6) and the repo convention is to keep
test artifacts rather than delete them, every `AGENT-TEST`/`ZZZ-TEST` snippet created
across sessions is listed here, confirmed `active: false` unless noted:

- id 13 — "ZZZ-TEST-DELETE-ME-2" (`// test snippet payload probe`)
- id 14 — blank/untitled (empty-body POST test)
- id 15 — "ZZZ-TEST-SCOPE" (`// x`)
- id 16 — "AGENT-TEST sink (log + REST read)" — debug log sink used for the
  jetformbuilder-hooks/actions TEST-REGIMEN run; adds the `agent_test_log`
  wp_option and the `/agent-test/v1/log` REST route while active.
- id 17 — "AGENT-TEST jetformbuilder hooks/actions regimen" — the hook/filter/action
  registrations used to validate those two TEST-REGIMEN.md files.
- id 18 — "AGENT-TEST redirect_to_page collision (activate briefly only)" — the
  action-id-collision probe; confirmed inactive, never leave this one active longer
  than a single test submission if reused, since it globally overrides the built-in
  `redirect_to_page` action while on.
- id 19 — "AGENT-TEST mcp-tools audit (CCT table + query verify)" — adds
  `GET /agent-test/v1/mcp-audit-cct` while active, dumping the `wp_jet_cct_agent_test_cct`
  table schema and re-running Query Builder query id 16 live. Used to verify the
  `jetengine-mcp-tools` skill (see its `TEST-REGIMEN.md`). Activate/deactivate both
  worked cleanly and instantly on this run (re-`GET`-confirmed `active: false`
  afterward) — contrary to finding #7 below, this plugin version's activate/deactivate
  responses were trustworthy this time; still re-`GET` to confirm rather than assuming.
- id 20 — "AGENT-TEST write-api audit (CCT item CRUD + relation link CRUD)" — adds three
  routes while active: `GET /agent-test/v1/mcp-audit-cct-write` (CCT row insert/update/
  delete via `Item_Handler`), `GET /agent-test/v1/mcp-audit-relation-create` (creates
  relation id 17), `GET /agent-test/v1/mcp-audit-relation-test?rel_id=&parent_id=&child_id=`
  (`Relation::update()`/`update_meta()`/`get_meta()`/`delete_rows()`). Used to verify the
  write-side additions to `jetengine-cct-internals` and `jetengine-relations` (see their
  `TEST-REGIMEN.md` files).
- id 21 — "AGENT-TEST relation meta-table existence check" — one-off follow-up to
  snippet 20; adds `GET /agent-test/v1/mcp-audit-relation-meta-table`, confirming
  relation 17's main link table exists but its `_meta` table doesn't (root cause of the
  `update_meta()` silent-no-op gotcha in `jetengine-relations`).
- id 22 — **"AGENT-TEST-CORE harness (keep active, do not delete)"** — shared runnable
  test-suite infrastructure (`agent_test_assert()`/`agent_test_run_suite()` plus
  `GET /agent-test/v1/suite/{suite}` and `/suites`). Source of truth:
  `test-harness/core-snippet.php`. **Keep this one active permanently** — every
  `AGENT-TEST-SUITE:*` snippet below depends on it. See `docs/test-harness-guide.md`.
- id 23 — "AGENT-TEST-SUITE: jetengine-query-builder" — runnable suite for that skill.
  Source of truth: `.claude/skills/jetengine-query-builder/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetengine-query-builder`.
- id 24 — "AGENT-TEST-SUITE: jetsmartfilters-query" — runnable suite for that skill.
  Source of truth: `.claude/skills/jetsmartfilters-query/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetsmartfilters-query`.
- id 25 — "ZZZ-DIAG isolated jsf-3 probe" — **deactivated, do not activate/hit its
  route.** One-off diagnostic that directly calls
  `new \Jet_Smart_Filters\Listing\Storage\Controller()` to isolate a crash found while
  building suite 24 — confirmed this line alone triggers an uncatchable "Cannot
  redeclare class" fatal (critical error page) on this site. Kept inactive as a
  documented reproduction of that landmine (see `jetsmartfilters-query/SKILL.md`'s
  "Live-verified landmine" note) rather than deleted.
- id 26 — "ZZZ-DIAG isolated jsf-3b probe (pre-check)" — deactivated, harmless
  (`class_exists()`/`plugin_path()` checks only, no instantiation) — the diagnostic
  that confirmed `DB_Storage`/`Storage\Controller` were already declared before any
  instantiation, root-causing snippet 25's crash.
- id 27 — "AGENT-TEST-SUITE: jetengine-modules" — runnable suite for that skill.
  Source of truth: `.claude/skills/jetengine-modules/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetengine-modules`. 6/6 pass as of 2026-07-16.
- id 28 — "AGENT-TEST-SUITE: jetformbuilder-fields" — runnable suite for that skill.
  Source of truth: `.claude/skills/jetformbuilder-fields/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetformbuilder-fields`. 7/7 pass as of 2026-07-16.
- id 29 — "AGENT-TEST-SUITE: jetengine-cct-internals" — runnable suite for that skill.
  Source of truth: `.claude/skills/jetengine-cct-internals/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetengine-cct-internals`. 3/3 pass as of 2026-07-16.
- id 30 — "AGENT-TEST-SUITE: jetengine-relations" — runnable suite for that skill.
  Source of truth: `.claude/skills/jetengine-relations/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetengine-relations`. 6/6 pass as of 2026-07-16.
- id 31 — "AGENT-TEST-SUITE: jetengine-mcp-tools" — runnable suite for that skill.
  Source of truth: `.claude/skills/jetengine-mcp-tools/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetengine-mcp-tools`. 4/4 pass as of 2026-07-16.
- id 32 — "AGENT-TEST-SUITE: jetengine-listings-macros" — runnable suite for that skill.
  Source of truth: `.claude/skills/jetengine-listings-macros/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetengine-listings-macros`. 5/5 pass as of 2026-07-16.
- id 33 — "AGENT-TEST-SUITE: jetformbuilder-actions" — runnable suite for that skill.
  Source of truth: `.claude/skills/jetformbuilder-actions/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetformbuilder-actions`. 3/3 pass as of 2026-07-16.
- id 34 — "AGENT-TEST-SUITE: jetformbuilder-hooks" — runnable suite for that skill.
  Source of truth: `.claude/skills/jetformbuilder-hooks/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetformbuilder-hooks`. 3/3 pass as of 2026-07-16.

None of these run anything while inactive — this is a documentation/tidiness note, not
a safety issue, **except id 25's route, which is destructive if hit while active** (see
above). Related non-snippet test fixtures, kept for the same reason: JetEngine CCT id
15 (slug `agent_test_cct`, one row left — `_ID` 2, "AGENT TEST row B"), Query Builder
query id 16 ("AGENT TEST Query - CCT test items"), and relation id 17 ("AGENT TEST
relation (post -> agent_test_cct)").

## Open question / recommendation

Given `DELETE` is broken and there's no request validation, any future agent session
scripting against this endpoint should:
- always pass `"active": false` when creating a snippet for testing, and only flip to
  `true` deliberately as a separate call to `/snippets/{id}/activate` — not tested yet,
  worth confirming that route behaves correctly since `DELETE` on the same plugin
  version does not.
- expect to clean up leftover snippets manually rather than relying on `DELETE`.
