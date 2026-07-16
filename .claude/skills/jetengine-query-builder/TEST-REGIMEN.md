# Test regimen: jetengine-query-builder

Validates claims in `SKILL.md`. Not yet run — written source-cited-only on 2026-07-16.
Run against the sandbox site (`jackfruit.epeak.studio`, JetEngine, confirmed test
install). Query Builder query id 16 ("AGENT TEST Query - CCT test items", type
`custom-content-type` against `agent_test_cct`) already exists from the
`jetengine-mcp-tools` regimen and can be reused as the fixture here.

## Test 1 (not yet run): `get_query_by_id()` returns a working query object for an existing query

**Claim:** `jet_engine()->query_builder->manager->get_query_by_id( $query_id )` is the
correct/only supported way to fetch a configured query.

**Setup:**
```php
$query = jet_engine()->query_builder->manager->get_query_by_id( 16 );
error_log( '[QB-TEST] class=' . get_class( $query ) . ' items=' . count( $query->get_items() ) );
```

**Expected observable:** logged class name matches the query's configured type (e.g.
`Posts_Query` or the CCT-backed equivalent), `items` count matches what the query
returns in the admin preview.

**Pass criteria:** no fatal, item count matches the admin-preview count for query 16.

## Test 2 (not yet run): `get_query_args()` shape differs by query type

**Claim:** the args shape returned by `get_query_args()` depends entirely on the
concrete subtype (WP_Query args for `Posts_Query`, raw SQL for `SQL_Query`, etc.) —
there's no single universal shape.

**Setup:** log `$query->get_query_args()` for query 16 (a CCT/custom-content-type
query) and compare to a `Posts_Query`-type query if one exists/can be created.

**Expected observable:** the two dumps look structurally different (one keyed like
`WP_Query` args, one keyed like a CCT table query).

**Pass criteria:** confirms "don't assume one universal args shape" claim; if they
happen to look identical, note that instead and adjust `SKILL.md`.

## Test 3 (not yet run): `jet-engine/query-builder/init` fires before any query is resolvable

**Claim:** `query_factory` isn't ready until this hook fires — registering a custom
query type at plain `plugins_loaded` would fail or no-op.

**Setup:**
```php
add_action( 'plugins_loaded', function() {
    error_log( '[QB-TEST] query_factory at plugins_loaded: ' . ( isset( jet_engine()->query_builder->query_factory ) ? 'set' : 'unset' ) );
}, 20 );
add_action( 'jet-engine/query-builder/init', function( $manager ) {
    error_log( '[QB-TEST] query_factory at qb-init: ' . ( isset( $manager->query_factory ) ? 'set' : 'unset' ) );
} );
```

**Expected observable:** `unset` (or fatal, if accessed unguarded) at `plugins_loaded`,
`set` at the dedicated init hook.

**Pass criteria:** confirms the hook-timing claim; if `query_factory` is already
available at `plugins_loaded`, the "register only after this hook" guidance in
`SKILL.md` needs softening.

## Test 4 (not yet run): registering a custom query type via `register_query()` makes it selectable

**Claim:** `Query_Factory::register_query( $type, $class )` is sufficient to make a new
query type available, no additional registration step.

**Setup:** register a minimal test class extending `Base_Query` under a
`agent_test_query_type` key, then check the admin Query Builder's "type" dropdown for
a new query.

**Expected observable:** the new type appears in the dropdown (or, if the dropdown is
hardcoded in JS/admin templates rather than driven by the factory map, it doesn't —
note whichever is true).

**Pass criteria:** whatever's observed gets written into `SKILL.md`, since this
directly affects whether `register_query()` alone is a complete recipe for a custom
query type or needs an accompanying admin-UI registration too.

## Cleanup note

No new fixtures needed beyond Query Builder query id 16, already kept from the
`jetengine-mcp-tools` regimen.
