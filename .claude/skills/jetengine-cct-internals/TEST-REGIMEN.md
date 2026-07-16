# Test regimen: jetengine-cct-internals

## Run log — 2026-07-16: runnable suite added, 3/3 pass

Added `tests.php` (per `docs/test-harness-guide.md`), deployed as Code Snippets snippet
id 29, run via `GET /agent-test/v1/suite/jetengine-cct-internals`. All 3 tests reuse
existing fixtures (CCT `agent_test_cct`, relation id 17) rather than creating new ones;
`cct-2` creates and deletes its own throwaway row each run so repeated runs stay clean.

- **cct-1** (CCT table naming + `_ID` PK, read against fixture row `_ID` 2): PASS.
- **cct-2** (`Item_Handler::update_item()`/`raw_delete_item()` full insert→update→delete
  roundtrip, self-cleaning): PASS — supersedes the manually-executed Test 1/2 above with
  an automated, repeatable version.
- **cct-3** (no `get_relation($id)` method; `get_active_relations()` keyed by id; relation
  17 resolves to a `Relation` object with `get_parents()`/`get_children()`): PASS.

No bugs found this round — the source had already been read carefully enough (this
skill's original write-up) that the tests confirmed rather than corrected anything.
Test 3 and 4 below (media `value_format` behavior, CCT hook firing order) remain
not-yet-automated — still open for a future pass, see below.

Validates claims in `SKILL.md`. Run against the sandbox site (`jackfruit.epeak.studio`,
JetEngine, confirmed by the site owner to be a test install, free to create real
fixtures on). This skill previously had no `TEST-REGIMEN.md` at all — this file starts
with the one section that's actually been executed (the CCT row write API, added
2026-07-16) and lists the older read-side claims that still need a runnable checklist.

## Test 1: `Item_Handler::update_item()` inserts (no `_ID`) or updates (with `_ID`) — PASS (executed)

**Claim being tested:** one method does both insert and update, keyed only on whether
the input array has an `_ID` entry; returns an int id on success (or `WP_Error`, not
checked this run since no failure case was forced).

**Setup:** CCT `agent_test_cct` (id 15, slug created for `jetengine-mcp-tools`, fields
`title`/`photo`/`status`).

**Trigger:** Code Snippets REST probe (id 20, route `/agent-test/v1/mcp-audit-cct-write`):
```php
$factory = \Jet_Engine\Modules\Custom_Content_Types\Module::instance()
    ->manager->get_content_types( 'agent_test_cct' );
$handler = $factory->get_item_handler();
$id_a = $handler->update_item( array( 'title' => 'AGENT TEST row A', 'status' => 'draft' ) );
$id_b = $handler->update_item( array( 'title' => 'AGENT TEST row B', 'status' => 'published' ) );
$id_a_updated = $handler->update_item( array( '_ID' => $id_a, 'title' => 'AGENT TEST row A (updated)', 'status' => 'published' ) );
```

**Actual result (2026-07-16):** `insert_id_a: 1`, `insert_id_b: 2`,
`update_id_a: 1` (same id returned, confirming update not a second insert). Direct
`$wpdb` read after both calls showed row 1 with title `"AGENT TEST row A (updated)"`
and `status: "published"` (both fields changed), `cct_status: "publish"` (defaulted,
never explicitly set), `cct_created`/`cct_modified` both populated.

**Pass criteria:** insert returns a new id each time; update reuses the same id and the
row's fields actually change. **PASS.**

## Test 2: `Item_Handler::raw_delete_item()` deletes exactly one row — PASS (executed)

**Claim being tested:** deletes only the targeted row, documented in source as safe to
call "from anywhere" (no built-in permission check of its own).

**Trigger:** same probe, `$handler->raw_delete_item( $id_a )` after Test 1, then
re-read the table.

**Actual result:** `rows_after_delete_a` showed only row `2` ("AGENT TEST row B")
remaining — row 1 gone. **PASS.**

## Test 3 (not yet run): media field `value_format` actually changes what's returned, not just what's stored

**Claim being tested:** `SKILL.md`'s "Media fields" section says a `media` field stores
a plain attachment post ID and needs `wp_get_attachment_url()` to resolve — but the
`tool-add-cct`/`update_item()` `value_format` option (`id`/`url`/`both`) wasn on the
input side. Not yet confirmed whether `value_format: both` changes what a *read* API
(e.g. a query/listing) returns, or purely how the admin UI display works.

**Setup:** on `agent_test_cct`'s `photo` field (currently `value_format: id`), if the
CCT editor supports changing it: set to `both`, insert a new row with a real attachment
id, then compare what a Query Builder `custom-content-type` query (or a raw `$wpdb`
read) returns for that field before/after.

**Expected observable:** `id` format returns a bare int; `both` (if it affects storage,
not just display) might return a serialized array `{id, url}` instead — or it might not
change storage at all and only affect a specific read helper. State whichever is true.

**Pass criteria:** whatever is observed gets written into `SKILL.md`'s "Media fields"
section as a confirmed fact, replacing the current single-format assumption.

## Test 4 (not yet run): CCT hooks fire in the documented order

**Claim being tested:** `SKILL.md`'s "Writing CCT rows" section lists
`jet-engine/custom-content-types/{create-item,created-item,update-item,updated-item,delete-item}/{slug}`
hooks, sourced from `item-handler.php` but not independently fired/observed.

**Setup:** register `error_log()` callbacks on all five hooks for `agent_test_cct`,
then repeat Test 1/2's insert → update → delete sequence.

**Expected observable:** `create-item` fires before insert, `created-item` after (with
the new id available), `update-item` before update, `updated-item` after, `delete-item`
once on delete — in that order, once each per operation.

**Pass criteria:** all five fire exactly once per matching operation, in the order
listed above.

## Cleanup note

CCT id 15 (`agent_test_cct`) and Code Snippets probe id 20 (kept inactive, shared with
the `jetengine-relations` regimen — see that file's Test 7-9) are left in place per this
repo's keep-don't-delete convention. Rows in `agent_test_cct`: row `_ID` 2 ("AGENT TEST
row B") still exists; row 1 was deleted by Test 2 above.
