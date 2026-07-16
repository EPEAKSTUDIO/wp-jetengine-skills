# Test regimen: jetengine-cct-internals

Validates claims in `SKILL.md`. Run against the sandbox site (`jackfruit.epeak.studio`,
JetEngine, confirmed by the site owner to be a test install, free to create real
fixtures on).

## Run log — 2026-07-16: runnable suite added, 3/3 pass

Added `tests.php` (per `docs/test-harness-guide.md`), deployed as Code Snippets snippet
id 29, run via `GET /agent-test/v1/suite/jetengine-cct-internals`. All 3 tests reuse
existing fixtures (CCT `agent_test_cct`, relation id 17) rather than creating new ones;
`cct-2` creates and deletes its own throwaway row each run so repeated runs stay clean.

- **cct-1** (CCT table naming + `_ID` PK): PASS.
- **cct-2** (`Item_Handler::update_item()`/`raw_delete_item()` full insert→update→delete
  roundtrip, self-cleaning): PASS — supersedes the original manually-executed Test 1/2
  (below, kept only as history) with an automated, repeatable version. Original result:
  insert returned ids 1/2, update reused id 1 and changed its fields, delete removed
  exactly that row (`cct_status` defaulted to `publish`, never set explicitly).
- **cct-3** (no `get_relation($id)` method; `get_active_relations()` keyed by id; relation
  17 resolves to a `Relation` object with `get_parents()`/`get_children()`): PASS.

No bugs found this round — the source had already been read carefully enough that the
tests confirmed rather than corrected anything. Test 3/4 below remain not-yet-automated.

## Test 3 (not yet run): media field `value_format` actually changes what's returned, not just what's stored

**Claim being tested:** `SKILL.md`'s "Media fields" section says a `media` field stores
a plain attachment post ID and needs `wp_get_attachment_url()` to resolve — but the
`tool-add-cct`/`update_item()` `value_format` option (`id`/`url`/`both`) wasn't traced on
the input side. Not yet confirmed whether `value_format: both` changes what a *read* API
(e.g. a query/listing) returns, or purely how the admin UI display works.

**Setup:** on `agent_test_cct`'s `photo` field (currently `value_format: id`), if the
CCT editor supports changing it: set to `both`, insert a new row with a real attachment
id, then compare what a Query Builder `custom-content-type` query (or a raw `$wpdb`
read) returns for that field before/after.

**Pass criteria:** whatever is observed gets written into `SKILL.md`'s "Media fields"
section as a confirmed fact, replacing the current single-format assumption. Not
automated in `tests.php` because it needs an admin-UI field-config change, not just PHP.

## Test 4 (not yet run): CCT hooks fire in the documented order

**Claim being tested:** `SKILL.md`'s "Writing CCT rows" section lists
`jet-engine/custom-content-types/{create-item,created-item,update-item,updated-item,delete-item}/{slug}`
hooks, sourced from `item-handler.php` but not independently fired/observed.

**Setup:** register `error_log()` callbacks on all five hooks for `agent_test_cct`, then
repeat `cct-2`'s insert → update → delete sequence (either manually or by temporarily
extending `tests.php`).

**Pass criteria:** all five fire exactly once per matching operation, in the documented
order. Not automated yet — would need `tests.php` to capture hook-firing order via a
static log array rather than `error_log()`, straightforward to add in a future pass.

## Cleanup note

CCT id 15 (`agent_test_cct`) and Code Snippets probe id 20 (kept inactive, shared with
the `jetengine-relations` regimen) are left in place per this repo's keep-don't-delete
convention. Row `_ID` 2 ("AGENT TEST row B") still exists in `agent_test_cct`.
