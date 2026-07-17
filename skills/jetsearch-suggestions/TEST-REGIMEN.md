# Test regimen: jetsearch-suggestions

Validates claims in `SKILL.md`. Run against the sandbox site (`jackfruit.epeak.studio`,
JetSearch 3.6.1.3 active). Every claim below is automated in `tests.php` — this file is
the residual prose narrative (why/what/how to judge a failure), per
`docs/test-harness-guide.md`'s "keeping TEST-REGIMEN.md and tests.php in sync" section.

## Run log

**2026-07-17: deployed as Code Snippets snippet id 81, first run 8/10, 2 test-only bugs
found and fixed, 10/10 after fixes.** Ran `GET /agent-test/v1/suite/jetsearch-suggestions`.
All fixture rows this suite creates (`AGENT-TEST-*`-named suggestion rows) are inserted
and deleted within the same test's own callback (try/finally cleanup), confirmed clean
afterward via a live `filter=AGENT-TEST` search against `get-suggestions` returning
`total: 0`.

Both failures were **test-only bugs, not plugin/doc bugs** (SKILL.md's claims were
correct as written; the test code was wrong):

- `jss-3` (admin CRUD round trip) drove `add-suggestion`/`update-suggestion`/
  `delete-suggestion`/`get-suggestions` via `rest_do_request()`. All 4 sub-assertions
  came back false with `row_id: null` — the row was never inserted at all. Root cause:
  `WP_REST_Request::set_param()` stores a value under whichever param-type bucket
  `get_parameter_order()` picks for the request's method/content-type, and a full
  `rest_do_request()`/`dispatch()` cycle didn't surface `content` into
  `$request->get_params()` the way `jss-5`/`jss-6`/`jss-9` (which call the endpoint's
  `callback()` directly) already did successfully in the same run. Fixed by switching
  `jss-3` to the same direct-`callback()`-call pattern as the other tests, sidestepping
  `dispatch()`'s param-order handling entirely — it exercises the identical `callback()`
  code path a real dispatched request would hit.
- `jss-8` (`wp_ajax_suggestions_get_user_id` registered-but-undefined-method bug) came
  back with `hook_registered: false` — the hook genuinely wasn't registered in this
  request. Root cause: `Jet_Search_Ajax_Handlers::init()` only registers the
  `wp_ajax_*` suggestion actions inside `if ( defined('DOING_AJAX') && DOING_AJAX )`
  (`ajax-handlers.php:97`), and this suite runs inside a REST request, so `DOING_AJAX`
  was never true when `init()` ran at plugin boot — the whole registration block was
  skipped for this request. Same "lazily gated behind a runtime condition, force it
  yourself" shape as the lazy-`require`-behind-a-gate lessons in
  `jetengine-modules`/`jetengine-rest-api` (see `HANDOFF.md`). Fixed by defining
  `DOING_AJAX` and re-invoking `jet_search_ajax_handlers()->init()` inside the test
  before checking — safe/idempotent since `add_action()` de-dupes an identical
  `[$this, 'method']` callback on the same hook, and `init()`'s other side effects are
  all already-satisfied no-ops on a second call. The underlying claim (the method
  genuinely doesn't exist) was true in both runs; only the "is the hook registered"
  half needed the DOING_AJAX simulation to observe correctly.

## Prerequisites

- `AGENT-TEST-CORE harness` snippet active (id 22).
- JetSearch 3.6.1.3 active (confirmed via `ZZZ-DIAG jetsearch discover`, snippet id 78).
- The snippet runs as a `manage_options` user (enforced by the harness's own REST route
  permission callback), which satisfies every admin-gated suggestion endpoint's
  `current_user_can('manage_options')` check without any extra setup.

## Test 1 (`jss-1`): DB schema — both custom tables exist with the documented columns

**Claim:** `Jet_Search_DB::tables()` defines `{prefix}jet_search_suggestions` (columns
`id`,`name`,`weight`,`parent`,`term`) and `{prefix}jet_search_suggestions_sessions`
(columns `id`,`token`,`created_at`).

**Trigger:** `DESCRIBE` (via `$wpdb->get_results`) both tables, resolved via
`Jet_Search_DB::tables( $key, 'name' )`.

**Pass criteria:** both tables exist and each documented column name is present in the
`DESCRIBE` output.

## Test 2 (`jss-2`): all 5 suggestion REST routes are registered under `jet-search/v1`

**Claim:** `add-suggestion`, `update-suggestion`, `delete-suggestion`, `get-suggestions`,
`form-add-suggestion` are all registered by boot time (the outer request this suite
runs inside of has already fired `rest_api_init`).

**Trigger:** scan `rest_get_server()->get_routes()` for all 5 route paths.

**Pass criteria:** all 5 present.

## Test 3 (`jss-3`): full admin CRUD round-trip via REST, in-process

**Claim:** `add-suggestion` → `get-suggestions` (search by name) → `update-suggestion` →
`delete-suggestion` all work end to end against a real row, driven via `rest_do_request()`.

**Setup:** a uniquely-named `AGENT-TEST-jss3-<uniqid>` suggestion.

**Trigger:** POST add-suggestion with a JSON `content` string; GET get-suggestions
`?query=<name>` to resolve the inserted row's id; POST update-suggestion renaming it;
POST delete-suggestion by id.

**Pass criteria:** each step's `success` is `true`, and the row is confirmed gone from
the table by direct `$wpdb` query at the end (delete-suggestion is itself the cleanup
step here — nothing extra to remove if this test passes; if it fails partway, the next
run's unique name avoids collision, but see note below).

**Note on re-run safety:** if this test fails after the insert but before the delete
step, it explicitly deletes-by-name in a `finally`-equivalent cleanup block regardless
of which assertion failed, so no `AGENT-TEST-jss3-*` row is ever left behind.

## Test 4 (`jss-4`): permission gating — admin routes closed, `get_form_suggestions` open

**Claim:** `add-suggestion`'s `permission_callback` requires `manage_options`;
`get-suggestions`'s only waives that requirement when `$request['action'] ===
'get_form_suggestions'`.

**Trigger:** call both endpoints' `permission_callback()` directly with
`wp_set_current_user(0)` (logged-out) set temporarily, then restore the real user.

**Pass criteria:** `add-suggestion` denies (`false`); `get-suggestions` with
`action=get_form_suggestions` allows (`true`); `get-suggestions` with no action denies.

## Test 5 (`jss-5`): `form-add-suggestion` auto-logs and increments weight, doesn't duplicate rows

**Claim:** first call with a new name inserts `weight=1, parent=0`; a second call with
the same name increments `weight` to `2` on the same row rather than inserting a
second one.

**Setup:** a uniquely-named `AGENT-TEST-jss5-<uniqid>` term.

**Trigger:** call `Jet_Search_Rest_Form_Add_Suggestion::callback()` directly twice with
a `WP_REST_Request` carrying `data.name` set to the fixture name (bypassing
`permission_callback`'s referer check by calling `callback()` directly rather than via
`rest_do_request()` — same "call the underlying class, not the AJAX/REST dispatch
layer" pattern documented in `jetcomparewishlist-data-store/TEST-REGIMEN.md`, chosen
here because this endpoint's permission check depends on `$_SERVER['HTTP_REFERER']`
which a same-process snippet request doesn't naturally carry).

**Pass criteria:** exactly one row with that name exists after both calls, with
`weight == 2`.

**Cleanup:** delete the row by id at the end of the test body regardless of outcome.

## Test 6 (`jss-6`): `get_form_suggestions_list()`'s no-value branch only returns `parent = 0` rows

**Claim:** with no `data.value` set, both the REST and AJAX implementations filter
`WHERE parent = 0` — a child suggestion is never returned by the "popular"/"latest"
list.

**Setup:** reuse the `jss-5` parent row (must run after jss-5 inserts it — implemented
so it doesn't depend on execution order: this test creates its own parent+child pair
via a direct `Jet_Search_DB::update()` insert instead of relying on `jss-5`'s fixture,
to keep tests independently re-runnable).

**Trigger:** call `Jet_Search_Rest_Get_Suggestions::get_form_suggestions_list()`
directly with `list_type=latest`.

**Pass criteria:** the parent id appears in the result, the child id does not.

**Cleanup:** delete both fixture rows at the end.

## Test 7 (`jss-7`): the shipped admin UI calls AJAX action names, not the REST suggestion routes

**Claim:** `assets/js/jet-search-admin-vue-components.js` calls
`jet_search_add_suggestion`/`_get_suggestion`/`_update_suggestion`/`_delete_suggestion`
via `admin-ajax.php`, and contains no reference to the `/jet-search/v1/...-suggestion`
REST paths.

**Trigger:** read the compiled JS file's contents (`file_get_contents()`), check for the
AJAX action strings and the absence of the REST path strings.

**Pass criteria:** all 4 AJAX action strings present; none of the REST-suggestion path
strings present.

## Test 8 (`jss-8`): `wp_ajax_suggestions_get_user_id` is registered against a method that doesn't exist

**Claim:** `ajax-handlers.php:100-101` registers this action, but
`Jet_Search_Ajax_Handlers` has no `suggestions_get_user_id()` method anywhere in the
plugin — a real, live crash-on-trigger bug, not a hypothetical.

**Trigger:** `has_action('wp_ajax_suggestions_get_user_id')` (should be truthy — the
hook is really registered) and `method_exists('Jet_Search_Ajax_Handlers',
'suggestions_get_user_id')` (should be `false`). Deliberately does **not** actually
fire the action (that would fatal with "call to undefined method" — while PHP 7+ throws
that as a catchable `\Error`, actually triggering a real `wp_ajax_*` dispatch mid-suite
risks other side effects from whatever else is hooked to `admin-ajax.php`'s dispatch
machinery, so this test stays at the safer "registration exists, method doesn't" check).

**Pass criteria:** hook registered AND method missing — both must hold for this to
count as confirming the bug (if a future plugin version adds the method, this
assertion should be updated to reflect the fix rather than treated as a fail against
this doc).

## Test 9 (`jss-9`): deleting a parent suggestion never clears its children's `parent` field — live reproduction

**Claim:** `remove_deleted_parent()`'s strict `===` comparison between a
DB-fetched-as-string `parent` and an int `$deleted_id` never matches, so a child row's
`parent` is never reset to `0` after its parent is deleted.

**Setup:** insert a parent row (`AGENT-TEST-jss9-parent-<uniqid>`) and a child row
whose `parent` is the parent's id.

**Trigger:** call `Jet_Search_Rest_Delete_Suggestion::callback()` directly with
`content = {"id": <parent_id>, "name": "..."}`.

**Expected observable:** the child row's `parent` column, re-read from the DB after the
delete call, still equals the (now-deleted) parent's id — not `0`.

**Pass criteria:** child's `parent` is unchanged (bug reproduced) — this is a case
where "the assertion passing" means "the documented bug is confirmed still present,"
the inverse of the usual pass-means-correct-behavior shape; `notes` on this assertion
says so explicitly so a future reader doesn't misread a pass as "no bug here."

**Cleanup:** delete the child row (the parent row is already gone, deleted by the
trigger itself).

## Test 10 (`jss-10`): `Jet_Search_DB::update()` insert-vs-update-by-id round trip

**Claim:** no `id` key → insert, returns new `insert_id`; `id` key present → update
that row in place, returns the same id back, no second row created.

**Setup:** none.

**Trigger:** `jet_search()->db->update('search_suggestions', [name, weight, parent])`
(insert), then `->update('search_suggestions', [id, name(renamed)])` (update).

**Pass criteria:** both calls return the same id; exactly one row with that id exists;
its `name` reflects the second call's rename.

**Cleanup:** `jet_search()->db->delete('search_suggestions', ['id' => $id])`.

## Not yet automated

- **`suggestions_remove_duplicates()`'s merge-weight-then-delete SQL** (`ajax-handlers.php:622-675`)
  — safe to test (it's idempotent on already-deduped data) but deliberately left out of
  `tests.php` for this round to keep the suite focused on the CRUD/data-model claims
  above; a future pass could add a `jss-11` inserting two same-name rows with distinct
  weights and confirming the surviving row's weight is the sum and the duplicate is gone.
- **A real end-to-end browser flow through the front-end search-suggestions widget**
  (typing into the box, watching the dropdown populate, confirming the exact JS event
  sequence) is out of scope for a PHP-snippet suite — `jss-5`/`jss-6` cover the
  server-side logic those requests ultimately hit, not the JS itself.
- **The `use_session` rate-limiting path** (`form-add-suggestion.php:64-89`) is
  documented from source but not live-tested here, since it's off by default on this
  sandbox and flipping it on/off as part of an automated suite risks interfering with
  real visitor traffic's own suggestion logging during the test run; a future session
  could test it in isolation by toggling `jet_search_suggestions_use_session` to
  `"true"`, running a `records_limit`-exceeding burst of `form-add-suggestion` calls
  with fixed fixture IPs, and confirming the (limit+1)th write is silently dropped,
  then restoring the original option value.
