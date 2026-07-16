# Test regimen: jetengine-relations

Validates claims in `SKILL.md`. Run against the sandbox site (JetEngine 3.8.12).

## Run log — 2026-07-15: BLOCKED, not executed

Attempted against jackfruit.epeak.studio, believed at the time to be a live production
install with **zero JetEngine Relations configured** — `resource-get-configuration`
(via the jetengine MCP) came back with no `relations` section at all, confirming none
exist. No test in this file could be run: every test needs at least one real relation
with parent/child links, and there's no MCP tool that creates a *relation* (only
CCT/CPT/listing/meta-box/query/taxonomy/glossary — checked the full jetengine-jackfruit
tool list). Creating one via the admin UI was left as the unblocking step.

Separately: while probing for fixtures for the sibling `jetengine-listings-macros`
regimen on this same site, the `tool-add-cct` MCP tool itself returned a **500 Internal
Server Error / WordPress critical error** on this install (site recovered immediately,
nothing left in a broken state). That tool wasn't retried in that session.

## Run log — 2026-07-16: unblocked — created a relation programmatically, no admin UI needed

The site owner confirmed `jackfruit.epeak.studio` is a test install, not production —
free to create real fixtures on it. Rather than using the (admin-UI-only, per the prior
session's read of the MCP tool list) relation creation path, called the same internal
CRUD the admin UI itself uses directly from a Code Snippets REST probe:

```php
jet_engine()->relations->data->set_request( array(
    'name' => 'AGENT TEST relation (post -> agent_test_cct)',
    'parent_object' => 'posts::post',
    'child_object' => 'cct::agent_test_cct', // 'cct::{slug}' format, confirmed in cct-internals
    'type' => 'one_to_many',
    'db_table' => true,
) );
$rel_id = jet_engine()->relations->data->create_item( false ); // → 17
```

This confirms **relations, like CCT/CPT/taxonomy/meta-box, can be created without the
admin UI or an MCP tool** — same `Data::create_item(false)` pattern documented in
`jetengine-mcp-tools`' "every add tool funnels into the same CRUD" section, just called
directly instead of through a tool wrapper. **`tool-add-cct` did NOT fail this session**
(created CCT id 15 cleanly) — the prior session's 500 error looks like it was
transient/version-specific rather than a standing issue, but wasn't re-investigated.

This unblocks Test 1, 2, 4 below and adds new executed tests 7–9 for the
`update()`/`delete_rows()`/`update_meta()` API documented in `SKILL.md`'s "Creating,
updating, and removing a relation LINK" section (added this session). Test 3, 5, 6
remain not executed — see below.

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

**Result — 2026-07-16: PASS (executed).** Relation 17 (created with `db_table: true`)
produced table `{prefix}jet_rel_17`, confirmed present via `SHOW TABLES LIKE`. Bonus
finding beyond the original claim: its paired `{prefix}jet_rel_17_meta` table did
**not** exist at the same point, despite the main table being auto-created by
`Relation::update()` — see Test 9 below, this is the root cause of the `update_meta()`
gotcha now documented in `SKILL.md`.

## Test 7: `Relation::update()` creates a link and is idempotent — PASS (executed)

**Claim being tested:** `update( $parent_id, $child_id )` creates the DB row (or
auto-creates the table first if missing) and returns a `get_children()`-shaped row, not
a bare bool/id; calling it again with the same pair returns the existing row rather than
duplicating it.

**Setup:** relation 17 (`posts::post` → `cct::agent_test_cct`), `parent_id = 1` (real
`post` id, "Hello world!"), `child_id = 2` (the `agent_test_cct` row id left over from
the `jetengine-cct-internals` write-API test).

**Trigger:** Code Snippets REST probe (id 20, route
`/agent-test/v1/mcp-audit-relation-test?rel_id=17&parent_id=1&child_id=2`) called
`$relation->get_children( $parent_id )` (before), then `$relation->update( $parent_id, $child_id )`,
then `get_children()` again (after).

**Actual result:**
```json
"before_link": [],
"update_result": {"_ID":"1","created":"2026-07-16 13:02:44","rel_id":"17",
  "parent_rel":"0","parent_object_id":"1","child_object_id":"2"},
"after_link": [{"_ID":"1", "...": "same row"}]
```

**Pass criteria:** `update_result` matches the `get_children()` row shape and `after_link`
shows exactly that row. **PASS.** (Re-calling `update()` with the same pair to confirm
idempotency specifically was not separately re-executed this session — the "returns
`$exists[0]` if a row already matches" behavior is confirmed by source citation only,
`relation.php:1446-1454`.)

## Test 8: `Relation::delete_rows()` removes the link — PASS (executed)

**Claim being tested:** `delete_rows( $parent_id, $child_id )` removes exactly the
matching row(s).

**Trigger:** same probe as Test 7, called `$relation->delete_rows( $parent_id, $child_id )`
then `get_children( $parent_id )` again.

**Actual result:** `"after_delete": []` — row gone. **PASS.**

## Test 9: `update_meta()`/`get_meta()` silently no-op without a meta table — PASS (executed)

**Claim being tested:** `update_meta()` doesn't auto-create the relation's `_meta` table
the way `update()` does for the main table; on a relation without one, the write
silently fails and `get_meta()` returns `false`, with no error surfaced anywhere.

**Trigger:** same probe as Test 7/8, called
`$relation->update_meta( $parent_id, $child_id, 'agent_test_meta_key', 'agent_test_meta_value' )`
then `$relation->get_meta( $parent_id, $child_id, 'agent_test_meta_key' )`. Follow-up
probe (id 21, route `/agent-test/v1/mcp-audit-relation-meta-table`) directly checked
`SHOW TABLES LIKE '{prefix}jet_rel_17'` and `'...jet_rel_17_meta'`.

**Actual result:**
```json
{"meta_write_result": null, "meta_read": false}
```
```json
{"main_table_exists": true, "meta_table_exists": false}
```

**Pass criteria:** meta write/read both fail silently (no thrown error, no `WP_Error`)
while the main table exists. **PASS** — confirms the gotcha exactly as written in
`SKILL.md`. **Still open:** what admin-UI action actually creates the `_meta` table
(the plugin's own "add a meta field to this relation" screen almost certainly does, but
that specific trigger wasn't isolated this session) — worth tracing in a future pass if
per-link relation meta becomes a common task.

## Cleanup note

Relation id 17 and the two probe snippets (20, kept from the `jetengine-cct-internals`
run; 21, meta-table check) are left in place, deactivated, per this repo's
keep-don't-delete convention — see `docs/code-snippets-rest-api.md`.

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
