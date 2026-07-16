# Test regimen: jetengine-query-builder

Validates claims in `SKILL.md`. Run against the sandbox site (`jackfruit.epeak.studio`,
JetEngine, confirmed test install). Query Builder query id 16 ("AGENT TEST Query - CCT
test items", type `custom-content-type` against `agent_test_cct`) already exists from
the `jetengine-mcp-tools` regimen and was reused as the fixture here.

This skill now has a **runnable suite** (`tests.php`, deployed as Code Snippets snippet
id 23, "AGENT-TEST-SUITE: jetengine-query-builder") — see `docs/test-harness-guide.md`
for the convention. Run it live with:

```
GET /wp-json/agent-test/v1/suite/jetengine-query-builder
```

(requires the always-active AGENT-TEST-CORE harness, snippet id 22, and this suite's
snippet, id 23, both active.)

## Run log — 2026-07-16: first run caught a real documentation bug

**First run (13:44:28) — all 4 FAILED**, every assertion throwing
`Call to a member function get_query_by_id() on null`. Root cause: the originally
written `SKILL.md` claimed `jet_engine()->query_builder->manager->get_query_by_id()` —
that accessor **does not exist anywhere in the plugin** (confirmed by grepping the full
source for `->query_builder` after the failure, zero matches outside admin-settings
array keys). The real API is the static singleton `Manager::instance()`. Fixed
`SKILL.md` and `tests.php` to match, then re-ran.

**Second run (13:47:47) — all 4 PASSED.** Full detail per test below. This is the
harness working exactly as designed: a failure that's clearly "call on null" (not a
mismatched value) pointed straight at a wrong accessor path, not a subtle behavior
difference — see `docs/test-harness-guide.md`'s "is the plugin wrong, or is the test
wrong" section.

## Test qb-1 (`tests.php`): `Manager::instance()->get_query_by_id()` — PASS

**Claim:** returns a non-null query object whose `get_items()` returns an array.

**Actual (2026-07-16 13:47:47):** returned an instance of
`Jet_Engine\Modules\Custom_Content_Types\Query_Builder\CCT_Query` (not one of the eight
core `Queries\*` classes — see the bonus finding below), `get_items()` returned an
array (count 0, since the one remaining `agent_test_cct` row don't match query 16's
configured conditions — not investigated further, item count wasn't the claim under
test).

**Bonus finding, folded into `SKILL.md`:** `get_query_types()` on this site returned
`sql, posts, terms, users, comments, repeater, current-wp-query, merged-query,
relations-query, jet-form-builder-query, custom-content-type, agent_test_query_type` —
three extra types (`relations-query`, `jet-form-builder-query`, `custom-content-type`)
beyond the eight documented core ones, confirming other JetEngine modules register
their own query types through the same mechanism.

## Test qb-2 (`tests.php`): `get_query_args()` returns an array — PASS

**Actual:** `{content_type, number, order, args, _query_type, queried_object_id}` — a
CCT-query-shaped args array, distinct from what a `Posts_Query` would return (not
independently confirmed this run — see Test 2 (not yet run) below for the
cross-type-shape comparison, still open).

## Test qb-3 (`tests.php`): `Query_Factory::register_query()` is static, writes a shared type registry — PASS

**Actual:** after calling `register_query('agent_test_query_type', ...)`,
`get_query_types()` included `agent_test_query_type` in its result — confirms the
static map is genuinely shared/mutable from outside the class, not per-instance.

## Test qb-4 (`tests.php`): `get_query_by_id()` with a bogus id — PASS, and now a confirmed fact

**Claim was originally "not yet confirmed how it degrades."** **Actual: returns plain
`false`** (not `null`, not `WP_Error`) — promoted from "unconfirmed" to a documented
fact in `SKILL.md`.

## Test 2 (not yet run): `get_query_args()` shape differs by query type

**Claim:** the args shape returned by `get_query_args()` depends entirely on the
concrete subtype (WP_Query args for `Posts_Query`, raw SQL for `SQL_Query`, etc.).

**Setup:** create (or find) a `posts`-type Query Builder query, log its
`get_query_args()` next to query 16's (CCT-type) output — already captured above,
`{content_type, number, order, args, _query_type, queried_object_id}`.

**Expected observable:** the two dumps look structurally different.

**Pass criteria:** confirms "don't assume one universal args shape" claim; if they
happen to look identical, note that instead and adjust `SKILL.md`. Still needs a
second, non-CCT query fixture to compare against — not yet created.

## Test 3 (not yet run): `jet-engine/query-builder/queries/register` really only fires once per request

**Claim:** the hook is guarded by `if ( empty( self::$_queries ) )` and so only fires
the first time any query is set up in a given request — a second `Manager::instance()`
call later in the same request should not re-fire it.

**Setup:** count how many times a logging callback on
`jet-engine/query-builder/queries/register` fires within one REST request that
triggers `setup_queries()` more than once (if that's even possible to trigger twice in
one request — may require calling `Manager::instance()` fresh in two different code
paths).

**Pass criteria:** callback fires exactly once per request, confirming the guard.

## Test 4 (not yet run): registering a custom query type via `register_query()` makes it selectable in the admin UI

**Claim:** `Query_Factory::register_query()` alone (Test qb-3, confirmed at the
static-registry level) is sufficient to make a new query type usable end-to-end, not
just present in `get_query_types()`.

**Setup:** register a minimal real class extending `Base_Query` under a
`agent_test_query_type` key with a working `_get_items()`, then check the admin Query
Builder's "type" dropdown and try actually creating+running a query of that type.

**Expected observable:** the new type appears in the dropdown (or, if the dropdown is
hardcoded in JS/admin templates rather than driven by the factory map, it doesn't) and,
if selectable, a real query of that type returns real items via `get_query_by_id()`.

**Pass criteria:** whatever's observed gets written into `SKILL.md`, since this
directly affects whether `register_query()` alone is a complete recipe for a custom
query type end-to-end, versus just being visible in introspection.

## Cleanup note

No new fixtures needed beyond Query Builder query id 16 (kept from `jetengine-mcp-tools`).
Suite snippet id 23 kept active per this repo's convention — re-runnable any time via
the REST route above. The `agent_test_query_type` registered by qb-3 is process-local
(the static map resets every request) and leaves no persistent state to clean up.
