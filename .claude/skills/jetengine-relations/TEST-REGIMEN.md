# Test regimen: jetengine-relations

Validates claims in `SKILL.md`. Run against the sandbox site (JetEngine 3.8.12).

## Run log — 2026-07-15: BLOCKED, not executed

Attempted against jackfruit.epeak.studio, believed to be the sandbox. It turned out to
be a **live production install** (BadgeIt/WooCommerce event ticketing) with
**zero JetEngine Relations configured** — `resource-get-configuration` (via the
jetengine MCP) came back with no `relations` section at all, confirming none exist.

No test in this file could be run: every test needs at least one real relation with
parent/child links to call `get_children()`/`get_parents()` against, and there's no MCP
tool that creates a *relation* (only CCT/CPT/listing/meta-box/query/taxonomy/glossary —
checked the full jetengine-jackfruit tool list). Creating one requires the wp-admin
Relations UI, a manual step.

Separately: while probing for fixtures for the sibling `jetengine-listings-macros`
regimen on this same site, the `tool-add-cct` MCP tool itself returned a **500 Internal
Server Error / WordPress critical error** on this install (site recovered immediately,
nothing was left in a broken state — confirmed via `resource-get-configuration` showing
no CCT was partially created). That tool wasn't retried. If a relation test is attempted
later, it's worth checking whether `tool-add-cct`/CCT creation in general is reliable on
this specific site (version/plugin-conflict issue) before depending on it to build a
fixture relation between two CCTs.

**To make this regimen runnable:** create one relation manually in wp-admin (JetEngine →
Relations) between any two existing content types — a CCT/CCT or CPT/CCT pair, per the
Prerequisites below — then re-run these tests with the code as written; none of it
depends on which site hosts the relation.

## Prerequisites

- At least one active JetEngine relation with a handful of parent/child links (a
  `many_to_many` relation between two CCTs or a CPT+CCT pair is ideal — reuse whatever
  exists from `jetengine-cct-internals` testing if possible).
- A snippet plugin with PHP execute permission, and a debug log endpoint (see
  `docs/test-regimen-guide.md` for the sink convention).
- A query-count tool: either `$wpdb->num_queries` deltas logged around the call, or
  Query Monitor if installed on the sandbox.

## Test 1: bulk `get_children()`/`get_parents()` is one query, not N

**Claim:** passing an array of ids to `get_children()`/`get_parents()` builds a single
`IN`-clause query, confirmed via `relation.php:682-689`.

**Setup:**
```php
add_action( 'init', function() {
    if ( ! isset( $_GET['test_bulk_relations'] ) ) return;
    $relation = jet_engine()->relations->get_active_relations( (int) $_GET['rel_id'] );
    $before = get_num_queries();
    $rows = $relation->get_children( array( /* 3+ real parent ids */ ), 'all' );
    $after = get_num_queries();
    error_log( '[REL-TEST] queries_used=' . ( $after - $before ) . ' rows=' . count( $rows ) );
    wp_die( 'logged' );
} );
```

**Trigger:** hit `/?test_bulk_relations=1&rel_id=<real id>`.

**Expected observable:** `queries_used` is 1 (or 2 if object-cache miss triggers one
extra internal lookup — check against a baseline single-id call for comparison), NOT
one query per input id.

**Pass criteria:** query count for N ids ≈ query count for 1 id (not N× higher).

## Test 2: results must be grouped by `parent_object_id` manually

**Claim:** rows for all input ids come back mixed together with no automatic bucketing.

**Setup:** same call as Test 1, log `array_column( $rows, 'parent_object_id' )` (or
`child_object_id` depending on direction) alongside the input array.

**Expected observable:** the logged column values are an unsorted mix matching the
input ids, confirming the caller must `array_filter`/group manually to reconstruct
per-parent lists.

**Pass criteria:** claim holds if no per-id grouping is done by the method itself.

## Test 3: relation type is not enforced at query time

**Claim:** `one_to_one`/`one_to_many`/`many_to_many` is a config-time label only;
`get_children()`/`get_parents()` behave identically regardless.

**Setup:** on a relation configured as `one_to_one`, attempt to programmatically create
a second child link for the same parent (via `Relation::update()` or the JFB
"Connect Relation Items" action — see Test 5) and then call `get_children()`.

**Expected observable:** either the second link is silently allowed (returns 2 children
for the "one to one" relation), or some other guard rejects it — note whichever occurs.

**Pass criteria:** if the second link succeeds and `get_children()` returns 2 rows, the
claim is confirmed. If rejected, the SKILL.md claim needs correcting to note actual
enforcement exists (and where).

## Test 4: relation config storage — `jet_rel_*` custom tables, not live `wp_options`

**Claim:** relation config lives in custom DB tables prefixed `jet_rel_`, migrated from
option `jet_engine_relations` on first `get_raw()` call.

**Setup:**
```php
global $wpdb;
error_log( '[REL-TEST] jet_rel tables: ' . implode( ',', $wpdb->get_col(
    "SHOW TABLES LIKE '{$wpdb->prefix}jet_rel_%'"
) ) );
error_log( '[REL-TEST] jet_engine_relations option: ' . ( get_option( 'jet_engine_relations' ) ? 'present' : 'absent/migrated' ) );
```

**Expected observable:** at least one `jet_rel_*` table exists once any relation has
been created/used on the sandbox.

**Pass criteria:** table(s) present; if absent, check whether `jet_engine_relations_replaced`
option is set (would mean migration logic changed).

## Test 5: JetFormBuilder "Connect Relation Items" action fires and links records

**Claim:** action id `connect_relation_items`, reads `relation`/`parent_id`/`child_id`/
`context`/`store_items_type` settings, delegates to `Forms\Manager::update_related_items()`.

**Setup:** build a test form with a "Connect Relation Items" action step, targeting the
same relation used above. Add:
```php
add_action( 'jet-form-builder/before-do-action/connect_relation_items', function( $action ) {
    error_log( '[REL-TEST] connect_relation_items about to run' );
} );
add_action( 'jet-form-builder/form-handler/after-send', function( $handler, $is_success ) {
    error_log( '[REL-TEST] after-send is_success=' . var_export( $is_success, true ) );
}, 10, 2 );
```

**Trigger:** submit the form with valid parent/child field values.

**Expected observable:** both log lines appear; `get_children()`/`get_parents()`
afterward shows the new link exists.

**Pass criteria:** link is created and visible via the Relations API immediately after
submission.

## Test 6: public REST API `{context}` param values

**Claim:** unconfirmed — `GET /jet-rel/{rel_id}/{context}/{_ID}` context values weren't
traced past `public-controller.php:61-107`.

**Setup:** enable "public REST API" on a test relation, then try
`GET /jet-rel/{rel_id}/parent/{_ID}` and `GET /jet-rel/{rel_id}/child/{_ID}`.

**Expected observable:** one or both return valid data; note which context strings are
actually accepted.

**Pass criteria:** whatever is found should be added to SKILL.md as a confirmed fact,
replacing the "unconfirmed" note.
