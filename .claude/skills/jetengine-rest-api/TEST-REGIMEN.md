# Test regimen: jetengine-rest-api

Validates claims in `SKILL.md`. Run against the sandbox site (`jackfruit.epeak.studio`),
JetEngine active, `rest-api-listings` module activated for this task via
`tool-manage-modules`. Reuses existing fixtures rather than creating new ones: CCT
`agent_test_cct` (id 15), Query Builder query id 16 (`custom-content-type` type against
`agent_test_cct`), relation id 17 (`posts::post -> cct::agent_test_cct`).

This skill has a **runnable suite** (`tests.php`, 11 tests, `rapi-1` through `rapi-11`)
per `docs/test-harness-guide.md`. Run it live with:

```
GET /wp-json/agent-test/v1/suite/jetengine-rest-api
```

(requires the always-active AGENT-TEST-CORE harness plus this suite's own snippet — id
77, "AGENT-TEST-SUITE: jetengine-rest-api" — both active.) **Result: 11/11 passing** as
of 2026-07-17 — see Run log below.

## Run log — 2026-07-17

Deployed as Code Snippets snippet id 77 ("AGENT-TEST-SUITE: jetengine-rest-api"), active.

**First run — 9/11 passed, 2 failed, both test-only bugs (not plugin/doc bugs):**

- `rapi-6` threw `Class "Jet_Engine\Relations\Rest\Public_Controller" not found`. Root
  cause: unlike the CCT `Public_Controller` class (already loaded by boot time for other
  reasons), the Relations `Public_Controller` class file is only `require`d lazily, from
  inside `Relation::init_public_rest_api()` (`includes/components/relations/relation.php:102`),
  itself gated behind the relation's own `rest_get_enabled`/`rest_post_enabled` args — the
  exact same lazy-`require`-behind-a-gate shape as the `Stores\Factory` gotcha found the
  same day in `jetengine-modules` (see that skill's `SKILL.md`). Fixed by force-`require`ing
  via the plugin's own `jet_engine()->relations->component_path()` helper (not a guessed
  raw path) before instantiating — more robust than a `WP_PLUGIN_DIR`-based guess since it
  can't drift if the plugin's installed folder name ever differs from `jet-engine`.
- `rapi-7` got HTTP 404 instead of 200. Root cause: `Query_Endpoint`'s constructor only
  does `add_action( 'rest_api_init', [ $this, 'register_route' ] )`
  (`query-endpoint.php:13`) — but `rest_api_init` had **already fired** for the outer
  REST request this suite itself runs inside of, so that hook could never re-fire and the
  route was never actually registered via `register_rest_route()`. Fixed by calling
  `$endpoint->register_route()` directly after instantiation instead of relying on the
  (already-passed) action — the same "call the method directly instead of relying on a
  hook that already fired" pattern used for `Public_Controller::register_routes()` in
  `rapi-4`/`rapi-6`.

**Second run (after both fixes) — 11/11 passed.** No `SKILL.md` corrections were needed —
both bugs were in how the test drove the classes (missing `require`, relying on an
already-passed hook), not in what the doc claimed about the plugin's real behavior.

**Infrastructure note, not a plugin/test bug:** mid-session, every request to the sandbox
(both `code-snippets/v1` and `agent-test/v1` routes) briefly started returning a 200
"Please wait while your request is being verified..." JS bot-challenge page instead of
JSON — an edge/WAF anti-bot check that a non-browser HTTP client can never pass since it
requires executing JS. Resolved itself after a short wait (a few retries at ~15s
intervals); no code or config on this repo's side caused or fixed it. Worth knowing for a
future session: if a request that previously worked suddenly comes back as HTML instead
of JSON, retry with a short backoff before assuming anything is actually broken.

## Test rapi-1 (`tests.php`): `jet-engine/v2` is the real admin-CRUD namespace

**Claim being tested:** `Jet_Engine_REST_API` registers JetEngine's own admin-builder
CRUD endpoints (add/edit/get/delete a CPT, taxonomy, etc.) under `jet-engine/v2`, distinct
from the `jet-engine/v1` namespace the MCP Tools layer uses.

**Setup:** none — the admin CRUD routes are already registered by plugin boot time.

**Trigger:** inspect `rest_get_server()->get_routes()` for a route starting
`/jet-engine/v2/add-post-type`.

**Expected observable:** the route is present.

**Pass criteria:** route found under `jet-engine/v2`, confirming the namespace claim.

## Test rapi-2 (`tests.php`): Meta Box fields use `register_meta()` + `show_in_rest`, not `register_rest_field()`

**Claim being tested:** `Jet_Engine_Rest_Post_Meta::register_field()` calls
`register_meta( 'post', $name, [ 'show_in_rest' => ... ] )` — a Meta Box field surfaces
under a post's `meta` object in `wp/v2/{post_type}`, not as a top-level REST field the
way `register_rest_field()` would add one.

**Setup:** directly instantiate `Jet_Engine_Rest_Post_Meta` with a throwaway field name
(`agent_test_rapi_field`) against object subtype `post`.

**Trigger:** call `get_registered_meta_keys( 'post', 'post' )` afterward.

**Expected observable:** `agent_test_rapi_field` is present with a truthy `show_in_rest`.

**Pass criteria:** both conditions hold — confirms the mechanism directly rather than
depending on a real meta-box field group existing on the sandbox.

## Test rapi-3 (`tests.php`): Options Page fields use `register_setting()` + `show_in_rest`

**Claim being tested:** `Jet_Engine_Rest_Settings` (extends the class from rapi-2)
overrides `register_field()` to call `register_setting()` instead, surfacing the field at
`wp/v2/settings`.

**Setup:** directly instantiate `Jet_Engine_Rest_Settings` with a throwaway setting name
(`agent_test_rapi_setting`) and a fake `$page` object (`storage_type: 'global'`, so the
`'separate' === storage_type` branch — which needs a real `get_separate_option_name()`
method — is never taken).

**Trigger:** inspect `$GLOBALS['wp_registered_settings']` afterward.

**Expected observable:** `agent_test_rapi_setting` present with a truthy `show_in_rest`.

**Pass criteria:** both conditions hold.

## Test rapi-4 (`tests.php`): CCT `Public_Controller` registers `/jet-cct/{slug}` routes

**Claim being tested:** CCTs need their own REST controller since they're not real WP
posts — `Public_Controller::register_routes()` registers a list route
(`/jet-cct/{slug}`) and an item route (`/jet-cct/{slug}/{_ID}`).

**Setup:** manually instantiate `Public_Controller` and call `register_routes( [ 'get' =>
true, 'slug' => 'agent_test_cct' ] )` against the existing CCT fixture (rather than
relying on the sandbox's admin-configured `rest_get_enabled` flag for that CCT, which
this suite doesn't control).

**Trigger:** inspect `rest_get_server()->get_routes()`.

**Expected observable:** both `/jet-cct/agent_test_cct` and a
`/jet-cct/agent_test_cct/(?P<_ID>...)`-shaped route are present.

**Pass criteria:** both routes found.

## Test rapi-5 (`tests.php`): live in-process `GET /jet-cct/{slug}` returns items

**Claim being tested:** the CCT public controller's list route actually returns item
data, not just that the route exists.

**Setup:** relies on rapi-4 having registered the route earlier in the same request.

**Trigger:** `rest_do_request( new WP_REST_Request( 'GET', '/jet-cct/agent_test_cct' ) )`.

**Expected observable:** HTTP 200, response body is an array.

**Pass criteria:** both hold. Driven in-process via `rest_do_request()`/`WP_REST_Server`
rather than a real HTTP round trip, per this repo's established lesson (see
`jetblog-query-pipeline/TEST-REGIMEN.md`).

## Test rapi-6 (`tests.php`): Relations `Public_Controller` registers and serves `/jet-rel/{rel_id}`

**Claim being tested:** Relations get an equivalent public data controller under `jet-rel`,
gated the same way as CCT's.

**Setup:** manually instantiate `Public_Controller` and call `register_routes( [ 'get' =>
true, 'rel_id' => 17 ] )` against the existing relation fixture.

**Trigger:** confirm `/jet-rel/17` is in the route table, then
`rest_do_request( GET /jet-rel/17 )`.

**Expected observable:** route present; response HTTP 200, array body.

**Pass criteria:** all three hold.

## Test rapi-7 (`tests.php`): `Query_Builder\Rest\Query_Endpoint` gives a query its own public route

**Claim being tested:** any configured Query Builder query (not just CCT/Relations) can
get a standalone public REST route via `Query_Endpoint`, resolved through
`Manager::get_query_by_id_for_context()`, with `Jet-Query-Total`/`Jet-Query-Pages`
response headers.

**Setup:** instantiate `Query_Endpoint` directly against existing query id 16
(`custom-content-type` type), with `api_namespace: 'agent-test-rapi/v1'`, `api_path:
'/q16'`, `api_access: 'public'` — a namespace deliberately outside anything real so it
can't collide with an admin-configured query REST route.

**Trigger:** `rest_do_request( GET /agent-test-rapi/v1/q16 )`.

**Expected observable:** HTTP 200, array body, `Jet-Query-Total` header present.

**Pass criteria:** all three hold.

## Test rapi-8 (`tests.php`): Rest API Listings endpoints share the `post_types` table

**Claim being tested:** `Data::$table = 'post_types'` with a permanent
`status = 'rest-api-endpoint'` filter — endpoints are rows in the same custom table
JetEngine uses for CPT config, not a dedicated table.

**Setup:** requires the `rest-api-listings` module active (it was activated for this
task). Reach `Module::instance()->data`.

**Trigger:** read `$data->table` and `$data->query_args`.

**Expected observable:** `table === 'post_types'`, `query_args['status'] ===
'rest-api-endpoint'`.

**Pass criteria:** both hold.

## Test rapi-9 (`tests.php`): `REST_API_Query` registers as query type `rest-api` via the shared factory mechanism

**Claim being tested:** the Rest API Listings module's own Query Builder integration uses
the exact same `Query_Factory::register_query()` mechanism documented in
`jetengine-query-builder` — no special-cased registration path for this module.

**Setup:** none beyond the module being active.

**Trigger:** `Query_Factory::get_query_types()`; also confirm
`Query_Builder\Manager::instance()` (the module's own manager, distinct from the core
Query Builder `Manager`) is reachable.

**Expected observable:** `'rest-api'` present in the returned types list; manager
reachable.

**Pass criteria:** both hold.

## Test rapi-10 (`tests.php`): `Request` caching round-trips through a transient without a real HTTP call

**Claim being tested:** caching is opt-in per endpoint (`cache: true`) and genuinely
persists/retrieves items via `update_items_cache()`/`get_cached_items()` — exercised here
without hitting `send_request()`/`wp_remote_get()` at all, so this suite has zero live
network dependency.

**Setup:** construct a `Request`, `set_endpoint()` with a fake endpoint (`cache: true`,
`cache_period: minutes`, `cache_value: 5`, a URL under the reserved `.invalid` TLD so it
can never resolve even if something did try to hit it), call `update_items_cache()`
directly with fabricated item data.

**Trigger:** `get_cached_items()` immediately after.

**Expected observable:** the exact fabricated items array comes back.

**Pass criteria:** round-trip matches. Cleans up its own transient at the end
(`delete_transient()`), safe to re-run.

**What this does NOT test** (see "Not yet automated" below): whether a *real* third-party
endpoint's response actually reaches `recursive_find_items()`/gets cached correctly end
to end, or the long-URL transient-name-truncation risk flagged in `SKILL.md`'s Gotchas —
both would require a real (or realistically mocked) outbound HTTP call.

## Test rapi-11 (`tests.php`): `Bearer_Token` only injects its header for a matching endpoint

**Claim being tested:** an auth type is "just another callback" on the
`jet-engine/rest-api-listings/request/args` filter, gated by `is_current_type_endpoint()`
reading the specific endpoint's own `auth_type` — not a global toggle.

**Setup:** two fake `Request` instances, one `set_endpoint()`'d with `auth_type:
'bearer-token'` (matching), one with `auth_type: 'custom-header'` (non-matching), both
otherwise identical (`authorization: true`, a `bearer_token` value present on both).

**Trigger:** call `Bearer_Token::set_token( [], $request )` against each.

**Expected observable:** the matching endpoint's args gain
`headers.Authorization === 'Bearer agent-test-token'`; the non-matching endpoint's args
do not gain an `Authorization` header at all.

**Pass criteria:** both hold.

## Not yet automated

- **A real outbound fetch through `Request::get_items()` against a genuine third-party
  endpoint** (the full `send_request()` → `recursive_find_items()` → cache pipeline, not
  just the caching half rapi-10 isolates) — deliberately not automated per this task's
  instructions, to avoid a flaky live network dependency in an auto-repeatable suite. If
  this is ever exercised, use a fixture endpoint under this project's control (not a real
  third party) and assert on `items_path` drilling with a genuinely nested response body,
  since `recursive_find_items()`'s multi-level-path behavior (as opposed to the
  default `/` no-drilling case) isn't covered by any test above.
- **The long-URL transient-name-truncation risk** flagged in `SKILL.md`'s Gotchas
  (`get_cache_transient()` concatenates the raw, unhashed endpoint URL into the transient
  name) — would need an endpoint URL long enough to push the resulting `option_name` past
  WP core's underlying column limit, then confirming two different long-URL endpoints
  actually collide in the cache. Flagged as a plausible risk from reading the code, not
  confirmed live.
- **A real Listing Grid render sourced from a REST API Endpoint** (Elementor/Blocks output
  actually showing `rest_api__{field}`-prefixed dynamic tag values from a real fetched
  item) — a visual/editor-UI check, not something to fabricate inside a single PHP REST
  request the way the rest of this suite does.
- **The legacy Forms/Booking notification path** (`Forms::handle_notification()`) — only
  `Jet_Action` (the JetFormBuilder action) and the shared `prepate_url()`/`prepare_body()`
  helpers are exercisable without a full legacy-Forms submission fixture; the
  `jet-engine/forms/booking/notification/{slug}` dispatch itself wasn't independently
  driven.
- **`tool-add-query`'s `query_type: 'rest-api'` end-to-end via the MCP tool** (creating a
  brand-new REST-API-backed query through `tool-add-query` rather than reusing the
  existing CCT-backed query id 16) — out of scope for this skill (see
  `jetengine-mcp-tools`), noted here only because it would be a natural next fixture if
  this skill's Query Builder integration needs deeper coverage later.

## Cleanup note

No new persistent fixtures created — rapi-2/rapi-3 register a throwaway meta
key/setting that only exists in PHP memory for the duration of the request (no DB write);
rapi-4/rapi-6/rapi-7 register REST routes that also only live for the request; rapi-10
creates and immediately deletes its own transient. Existing fixtures reused only,
consistent with this repo's "prefer reusing fixtures over creating new state" convention.
