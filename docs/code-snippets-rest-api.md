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
  Source of truth: `skills/jetengine-query-builder/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetengine-query-builder`. Updated 2026-07-16 (gists audit
  round) with `qb-5`/`qb-6` (after-query-setup/query-items) — first run of `qb-5` FAILED
  on a wrong assumption about when `after-query-setup` fires (registration-time only,
  not per-`get_items()` call), fixed by splitting into a live test (qb-5) and a
  source/precondition check (qb-6); 6/6 pass after the fix.
- id 24 — "AGENT-TEST-SUITE: jetsmartfilters-query" — runnable suite for that skill.
  Source of truth: `skills/jetsmartfilters-query/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetsmartfilters-query`. Updated 2026-07-16 (dev-docs/Codelab
  audit round) with `jsf-6`/`jsf-7`, then again (gists audit round) with `jsf-8`/`jsf-9`
  (meta-query-row, filter-instance/args, filters/filter-options, range/source-callbacks,
  post-type/meta-fields-settings, JS event-bus channels); 9/9 pass.
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
  Source of truth: `skills/jetengine-modules/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetengine-modules`. Updated 2026-07-16 (dev-docs/Codelab
  audit round) with `mod-7`, then again (gists audit round) with `mod-8`/`mod-9`/`mod-10`
  (Data Stores post-count hooks, Options Pages programmatic registration, Maps Listings
  providers); 10/10 pass. Redeployed 2026-07-17 after Dynamic Visibility/Data Stores
  were activated on the sandbox: `mod-8` initially fataled for real
  (`Stores\Factory not found` — a lazy-`require` gotcha, now documented in `SKILL.md`),
  fixed; 10/10 pass, all four previously gating-only tests now exercising real behavior.
- id 28 — "AGENT-TEST-SUITE: jetformbuilder-fields" — runnable suite for that skill.
  Source of truth: `skills/jetformbuilder-fields/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetformbuilder-fields`. Updated 2026-07-16 (dev-docs/Codelab
  audit round) with `jfb-8`; 8/8 pass.
- id 29 — "AGENT-TEST-SUITE: jetengine-cct-internals" — runnable suite for that skill.
  Source of truth: `skills/jetengine-cct-internals/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetengine-cct-internals`. Updated 2026-07-16 (dev-docs/Codelab
  audit round) with `cct-4`, then again (gists audit round) with `cct-5`/`cct-6`
  (user-has-access, item-to-update, raw-fields, admin-columns filters); 6/6 pass.
- id 30 — "AGENT-TEST-SUITE: jetengine-relations" — runnable suite for that skill.
  Source of truth: `skills/jetengine-relations/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetengine-relations`. Updated 2026-07-16 (gists audit round)
  with `rel-7`/`rel-8`/`rel-9` (raw-relations, relation/update/before+after, Sources
  fallback, posts get-items); 9/9 pass.
- id 31 — "AGENT-TEST-SUITE: jetengine-mcp-tools" — runnable suite for that skill.
  Source of truth: `skills/jetengine-mcp-tools/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetengine-mcp-tools`. 4/4 pass as of 2026-07-16.
- id 32 — "AGENT-TEST-SUITE: jetengine-listings-macros" — runnable suite for that skill.
  Source of truth: `skills/jetengine-listings-macros/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetengine-listings-macros`. Updated 2026-07-16 (dev-docs/Codelab
  audit round) with `macros-6`, then again (gists audit round) with `macros-7` (custom
  listing-context two-filter pairing: allowed-context-list + object-by-context/{key});
  7/7 pass.
- id 33 — "AGENT-TEST-SUITE: jetformbuilder-actions" — runnable suite for that skill.
  Source of truth: `skills/jetformbuilder-actions/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetformbuilder-actions`. Updated 2026-07-16 (dev-docs/Codelab
  audit round) with `act-4`, then again (gists audit round) with `act-5` (post-modifier/
  object-properties extension point) — caught a real doc bug pre-deploy (`->push()`
  doesn't exist on `Object_Properties_Collection`, real method is `->add()`); 5/5 pass.
- id 34 — "AGENT-TEST-SUITE: jetformbuilder-hooks" — runnable suite for that skill.
  Source of truth: `skills/jetformbuilder-hooks/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetformbuilder-hooks`. Updated 2026-07-16 (dev-docs/Codelab
  audit round) with `hooks-4`; 4/4 pass.

None of these run anything while inactive — this is a documentation/tidiness note, not
a safety issue, **except id 25's route, which is destructive if hit while active** (see
above). Related non-snippet test fixtures, kept for the same reason: JetEngine CCT id
15 (slug `agent_test_cct`, one row left — `_ID` 2, "AGENT TEST row B"), Query Builder
query id 16 ("AGENT TEST Query - CCT test items"), and relation id 17 ("AGENT TEST
relation (post -> agent_test_cct)").

- id 35 — "AGENT-TEST-SUITE: jetappointments-core" — runnable suite for that skill.
  Source of truth: `skills/jetappointments-core/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetappointments-core`. 5/5 pass as of 2026-07-16 (first run
  was 4/5 — `apb-4` was a test-only bug, a single-line `strpos()` check against a call
  site whose real args wrap across multiple lines; fixed with a whitespace-tolerant regex).
- id 36 — "AGENT-TEST-SUITE: jetappointments-integrations" — runnable suite for that
  skill. Source of truth: `skills/jetappointments-integrations/tests.php`. Active;
  run via `GET /agent-test/v1/suite/jetappointments-integrations`. 3/3 pass as of
  2026-07-16.
- id 37 — "AGENT-TEST-SUITE: jetelements-widgets" — runnable suite for that skill.
  Source of truth: `skills/jetelements-widgets/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetelements-widgets`. 7/7 pass as of 2026-07-16.
- id 38 — "AGENT-TEST-SUITE: jetelements-query-gateway" — runnable suite for that skill.
  Source of truth: `skills/jetelements-query-gateway/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetelements-query-gateway`. 4/4 pass as of 2026-07-16 (first
  run was 3/4 on a fatal — a test passed `$widget = null` into a hook a real JetEngine
  listener consumes, fixed with a fake widget stub; second run was still 3/4 because the
  same test's cleanup used `remove_all_filters()`, which also stripped JetEngine's own
  real listener off that hook — fixed by switching to `remove_filter()` with the exact
  callback reference. See that skill's TEST-REGIMEN.md for the full story — a good
  example of a live suite mutating shared site state across its own assertions.).
- id 39 — "AGENT-TEST-SUITE: jetwoobuilder-templates" — runnable suite for that skill.
  Source of truth: `skills/jetwoobuilder-templates/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetwoobuilder-templates`. 8/8 pass as of 2026-07-16, no fixes
  needed (WooCommerce itself isn't installed on this sandbox, but the suite was written
  defensively enough to degrade to correct results rather than fatal).

## PowerShell + `Invoke-RestMethod` gotchas (2026-07-16)

Every deploy in this repo before this round used `curl` from a Linux/macOS-style shell.
This round's session ran on Windows and used PowerShell's `Invoke-RestMethod` instead —
two real gotchas surfaced that a future PowerShell-based session should know about
up front:

**1. `Get-Content -Raw` strings carry hidden ETS NoteProperties that break
`ConvertTo-Json`.** Reading a snippet's `tests.php` with `Get-Content -Raw` returns a
`System.String`, but PowerShell's filesystem provider tacks on extra
NoteProperties (`PSPath`, `PSChildName`, `PSDrive`, `PSProvider`, `ReadCount`) via the
Extended Type System. `ConvertTo-Json` sees those and silently re-serializes the string
as a nested object (`{"code": {"value": "...", ...}}`) instead of a plain JSON string —
the REST API then rejects it with `"code is not of type string"`. Fix: cast to a plain
string before building the request body — `$code = [string](Get-Content $path -Raw)`.

**2. Default `Invoke-RestMethod`/`ConvertTo-Json` string encoding mangles the UTF-8
em-dashes/arrows this repo's docs and code comments use freely**, causing
`{"code":"rest_invalid_json","message":"Invalid JSON body passed.","data":{"json_error_message":"Malformed UTF-8 characters, possibly incorrectly encoded"}}`.
Fix: build the JSON body, convert it to bytes with
`[System.Text.Encoding]::UTF8.GetBytes($json)`, and pass that byte array as `-Body` with
`-ContentType "application/json; charset=utf-8"` rather than passing the JSON string
directly.

Both gotchas apply to every snippet POST/PUT in this round's deploys (ids 35-39); a
`curl`-based session on Linux/macOS shouldn't hit either one.

- id 40 — "AGENT-TEST-SUITE: jetbooking-calendar" — Source of truth:
  `skills/jetbooking-calendar/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetbooking-calendar`. 7/7 pass as of 2026-07-16 (first run,
  once JetBooking activated), no fixes needed.
- id 41 — "AGENT-TEST-SUITE: jetbooking-integrations" — Source of truth:
  `skills/jetbooking-integrations/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetbooking-integrations`. 6/6 pass as of 2026-07-16, no fixes
  needed.
- id 42 — "AGENT-TEST-SUITE: jetmenu-structure" — Source of truth:
  `skills/jetmenu-structure/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetmenu-structure`. First run 500'd the whole request
  (uncatchable "Cannot redeclare class" fatal) — isolated to `jms-4` calling
  `\Jet_Menu\Options_Manager::get_instance()` directly, which turned out to be a real doc
  bug (SKILL.md had called this "just a desync," not a fatal — corrected). 10/10 pass
  after the fix.
- id 43 — "AGENT-TEST-SUITE: jetmenu-extensibility" — Source of truth:
  `skills/jetmenu-extensibility/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetmenu-extensibility`. 7/7 pass as of 2026-07-16 (first run
  6/7 on a test-only ordering bug — see that skill's TEST-REGIMEN.md).
- id 44 — "AGENT-TEST-SUITE: jetreviews-data-model" — Source of truth:
  `skills/jetreviews-data-model/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetreviews-data-model`. 6/6 pass as of 2026-07-16, no fixes
  needed.
- id 45 — "AGENT-TEST-SUITE: jetreviews-conditions" — Source of truth:
  `skills/jetreviews-conditions/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetreviews-conditions`. 5/5 pass as of 2026-07-16, no fixes
  needed.
- ids 46-51, 64-72, 74 — one-off `ZZZ-DIAG` isolation probes used to bisect the
  `jetmenu-structure`/`jetblog-widgets-extensibility`/`jetthemecore-template-conditions`
  fatal-error crashes and one `jetthemecore-locations` flakiness investigation (see each
  skill's TEST-REGIMEN.md for what each isolated). All deactivated after use, per this
  repo's established pattern (see ids 25/26).
- id 52 — "AGENT-TEST-SUITE: jetblog-query-pipeline" — Source of truth:
  `skills/jetblog-query-pipeline/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetblog-query-pipeline`. 5/5 pass as of 2026-07-16 (first run
  3/5 — Elementor version-specific widget-constructor validation issues, resolved by
  testing the underlying filter mechanisms directly instead of through a fully-live
  widget instance; see that skill's TEST-REGIMEN.md).
- id 53 — "AGENT-TEST-SUITE: jetblog-widgets-extensibility" — Source of truth:
  `skills/jetblog-widgets-extensibility/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetblog-widgets-extensibility`. 6/6 pass as of 2026-07-16
  (first run 500'd — Elementor's lazy widget-registration vs. a manually-guarded
  `require` race condition, then 5/6 on a strict-bool test bug — both fixed).
- id 54 — "AGENT-TEST-SUITE: jetcomparewishlist-data-store" — Source of truth:
  `skills/jetcomparewishlist-data-store/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetcomparewishlist-data-store`. 6/6 pass as of 2026-07-16 (2
  of 6 gracefully skip since Wishlist/Compare are disabled by default on this site).
- id 55 — "AGENT-TEST-SUITE: jetcomparewishlist-integrations" — Source of truth:
  `skills/jetcomparewishlist-integrations/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetcomparewishlist-integrations`. 5/5 pass as of 2026-07-16,
  no fixes needed.
- id 56 — "AGENT-TEST-SUITE: jetpopup-conditions" — Source of truth:
  `skills/jetpopup-conditions/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetpopup-conditions`. 6/6 pass as of 2026-07-16, no fixes
  needed.
- id 57 — "AGENT-TEST-SUITE: jetpopup-extensibility" — Source of truth:
  `skills/jetpopup-extensibility/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetpopup-extensibility`. 7/7 pass as of 2026-07-16 (first run
  6/7 — the CPT's capabilities were already baked in before the test's filter was added;
  fixed by unregistering/re-registering the CPT with the filter active).
- id 58 — "AGENT-TEST-SUITE: jetpopup-render-triggers" — Source of truth:
  `skills/jetpopup-render-triggers/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetpopup-render-triggers`. 5/5 pass as of 2026-07-16, no
  fixes needed.
- id 59 — "AGENT-TEST-SUITE: jettabs-query-gateway" — Source of truth:
  `skills/jettabs-query-gateway/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jettabs-query-gateway`. 4/4 pass as of 2026-07-16, no fixes
  needed.
- id 60 — "AGENT-TEST-SUITE: jettabs-widgets" — Source of truth:
  `skills/jettabs-widgets/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jettabs-widgets`. 4/4 pass as of 2026-07-16, no fixes needed.
- id 61 — "AGENT-TEST-SUITE: jetthemecore-locations" — Source of truth:
  `skills/jetthemecore-locations/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetthemecore-locations`. 4/4 pass as of 2026-07-16 after
  fixing a real doc bug (WooCommerce registers 6 additional structures/locations beyond
  the documented "core 6/4") and a flaky test relying on a hardcoded filter name that
  should have been computed dynamically — see that skill's TEST-REGIMEN.md.
- id 62 — "AGENT-TEST-SUITE: jetthemecore-template-conditions" — Source of truth:
  `skills/jetthemecore-template-conditions/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetthemecore-template-conditions`. 5/5 pass as of 2026-07-16
  after fixing a real doc bug (`register_cpt_conditions()` fatals on a second call, an
  unconditional-`require` landmine the doc had missed) plus 2 test-only bugs.
- id 63 — "AGENT-TEST-SUITE: jetthemecore-theme-builder" — Source of truth:
  `skills/jetthemecore-theme-builder/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetthemecore-theme-builder`. 4/4 pass as of 2026-07-16 after
  fixing 3 test-only bugs (wrong response-key extraction, a missing
  `update_page_template_conditions()` call, and a missing `_jet_template_type` post
  meta) — see that skill's TEST-REGIMEN.md.
- id 73 — one-off diagnostic dumping live Theme Builder Page Template conditions state
  during the `jetthemecore-locations` flakiness investigation. Deactivated after use.
- id 75 — "AGENT-TEST-SUITE: jetengine-booking-forms" — Source of truth:
  `skills/jetengine-booking-forms/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetengine-booking-forms`. 12/12 pass as of 2026-07-17, clean
  first run (no plugin or test bugs) after Dynamic Calendar + Forms (Legacy) modules
  were activated on the sandbox.
- id 76 — "AGENT-TEST-SUITE: jetformbuilder-payment-gateways" — Source of truth:
  `skills/jetformbuilder-payment-gateways/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetformbuilder-payment-gateways`. 9/9 pass as of 2026-07-17,
  clean first run against the real PayPal gateway already registered on this sandbox (no
  live credentials needed — schema/class/hook-level assertions only).
- id 77 — "AGENT-TEST-SUITE: jetengine-rest-api" — Source of truth:
  `skills/jetengine-rest-api/tests.php`. Active; run via
  `GET /agent-test/v1/suite/jetengine-rest-api`. 11/11 pass as of 2026-07-17, after
  fixing 2 test-only bugs (a lazy-`require`-behind-a-gate class-loading gotcha in
  Relations' `Public_Controller`, same shape as `jetengine-modules`' `Stores\Factory`
  fix the same day; and a `Query_Endpoint` route that never registered because its
  `rest_api_init` hook had already fired for the outer request) — see that skill's
  TEST-REGIMEN.md.

## Open question / recommendation

Given `DELETE` is broken and there's no request validation, any future agent session
scripting against this endpoint should:
- always pass `"active": false` when creating a snippet for testing, and only flip to
  `true` deliberately as a separate call to `/snippets/{id}/activate` — not tested yet,
  worth confirming that route behaves correctly since `DELETE` on the same plugin
  version does not.
- expect to clean up leftover snippets manually rather than relying on `DELETE`.
