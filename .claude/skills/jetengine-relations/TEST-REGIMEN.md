# Test regimen: jetengine-relations

Validates claims in `SKILL.md`. Run against the sandbox site (JetEngine 3.8.12).

## Run log — 2026-07-16: runnable suite added, 6/6 pass

Added `tests.php` (per `docs/test-harness-guide.md`), deployed as Code Snippets snippet
id 30, run via `GET /agent-test/v1/suite/jetengine-relations`. Reuses relation id 17 and
CCT row `_ID` 2 as fixtures; `rel-2`/`rel-3` create and remove their own link (parent
post id 1, child cct row 2) each run, so nothing accumulates.

- **rel-1** (`get_relations_types()` enumerates exactly the 3 documented type strings):
  PASS.
- **rel-2** (`update()`/`get_children()`/`delete_rows()` full roundtrip, self-cleaning):
  PASS — supersedes the original Test 7/8 (below, kept only as history).
- **rel-3** (`update()` idempotent — same pair twice returns the same row `_ID`, not a
  duplicate): PASS — supersedes the source-only-confirmed half of the original Test 7.
- **rel-4** (bulk `get_children()` accepts an array of ids without fataling): PASS. Note:
  this only confirms no-fatal-on-array-input, not the query-count claim — Test 1 below
  (exact query-count) and Test 2 (grouping behavior) remain not automated.
- **rel-5** (`update_meta()`/`get_meta()` silent no-op gotcha): PASS — supersedes the
  original Test 9 (below, kept only as history); re-confirms the `_meta` table still
  doesn't exist for relation 17.
- **rel-6** (relation config lives in a real `jet_rel_{id}` table): PASS — supersedes the
  original Test 4 (below, kept only as history).

No bugs found this round. Still not automated: exact query-count assertions (Test 1),
result-grouping behavior (Test 2), relation-type enforcement-at-query-time (Test 3), the
JetFormBuilder "Connect Relation Items" action (Test 5, needs a real form submission),
and the public REST API `{context}` param values (Test 6, needs a live HTTP call against
`/jet-rel/...`).

## How the fixtures were built (2026-07-15/16)

The 2026-07-15 attempt was blocked: the sandbox had zero JetEngine Relations configured,
and there's no MCP tool that creates a *relation* (only CCT/CPT/listing/meta-box/query/
taxonomy/glossary). Once the site owner confirmed the sandbox is a test install (not
production), a relation was created programmatically instead of via the admin UI — same
internal CRUD the admin UI itself uses:

```php
jet_engine()->relations->data->set_request( array(
    'name' => 'AGENT TEST relation (post -> agent_test_cct)',
    'parent_object' => 'posts::post',
    'child_object' => 'cct::agent_test_cct',
    'type' => 'one_to_many',
    'db_table' => true,
) );
$rel_id = jet_engine()->relations->data->create_item( false ); // → 17
```

This confirms relations, like CCT/CPT/taxonomy/meta-box, can be created without the admin
UI or an MCP tool — same `Data::create_item(false)` pattern documented in
`jetengine-mcp-tools`' "every add tool funnels into the same CRUD" section, just called
directly. Relation 17 (`posts::post` → `cct::agent_test_cct`) and CCT row `_ID` 2 are the
fixtures `tests.php` still reuses today.

## Test 1: bulk `get_children()`/`get_parents()` is one query, not N

**Claim:** passing an array of ids to `get_children()`/`get_parents()` builds a single
`IN`-clause query, confirmed via `relation.php:682-689`. `rel-4` confirms array input is
accepted without fataling, but not the query-count part of this claim.

**Setup:** log `get_num_queries()` deltas around a `get_children(array(...ids...))` call
vs. a single-id baseline call, comparing to `wp_cache` state.

**Pass criteria:** query count for N ids ≈ query count for 1 id (not N× higher). Not
automated — needs `$wpdb->num_queries` deltas or Query Monitor, not easily expressed as
an `agent_test_assert()`.

## Test 2: results must be grouped by `parent_object_id` manually

**Claim:** rows for all input ids come back mixed together with no automatic bucketing.

**Setup:** call `get_children(array(...3+ ids...))`, log `array_column($rows, 'parent_object_id')`
alongside the input array.

**Pass criteria:** claim holds if no per-id grouping is done by the method itself. Not
yet automated — straightforward to add to `tests.php` in a future pass if this becomes
load-bearing.

## Test 3: relation type is not enforced at query time

**Claim:** `one_to_one`/`one_to_many`/`many_to_many` is a config-time label only;
`get_children()`/`get_parents()` behave identically regardless.

**Setup:** on a relation configured as `one_to_one`, attempt to programmatically create
a second child link for the same parent and call `get_children()`.

**Pass criteria:** if the second link succeeds and `get_children()` returns 2 rows, the
claim is confirmed; if rejected, `SKILL.md` needs correcting to note real enforcement.
Not automated — needs a dedicated `one_to_one` relation fixture, which doesn't exist yet
(relation 17 is `one_to_many`).

## Test 5: JetFormBuilder "Connect Relation Items" action fires and links records

**Claim:** action id `connect_relation_items`, reads `relation`/`parent_id`/`child_id`/
`context`/`store_items_type` settings, delegates to `Forms\Manager::update_related_items()`.

**Setup:** build a test form with a "Connect Relation Items" action step targeting
relation 17, submit with valid parent/child field values, log
`jet-form-builder/before-do-action/connect_relation_items` and
`jet-form-builder/form-handler/after-send`.

**Pass criteria:** link is created and visible via the Relations API immediately after
submission. Not automated — needs a real form submission (see `jetformbuilder-hooks`
for why the Call Hook mechanism can be tested without one, but this is a different,
built-in action type without an equivalent direct-invocation shortcut confirmed yet).

## Test 6: public REST API `{context}` param values

**Claim:** unconfirmed — `GET /jet-rel/{rel_id}/{context}/{_ID}` context values weren't
traced past `public-controller.php:61-107`.

**Setup:** enable "public REST API" on relation 17, try
`GET /jet-rel/17/parent/{_ID}` and `GET /jet-rel/17/child/{_ID}`.

**Pass criteria:** whatever is found should be added to `SKILL.md` as a confirmed fact,
replacing the "unconfirmed" note. Not automated — needs a live HTTP call against a
route that isn't currently enabled on relation 17.

## Cleanup note

Relation id 17 and Code Snippets probe ids 20/21 (kept from the `jetengine-cct-internals`
run, deactivated) are left in place per this repo's keep-don't-delete convention.
