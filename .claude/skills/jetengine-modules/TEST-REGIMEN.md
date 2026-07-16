# Test regimen: jetengine-modules

Validates claims in `SKILL.md`. Run against the sandbox site (`jackfruit.epeak.studio`,
JetEngine, confirmed test install).

## Run log — 2026-07-16: runnable suite added, 6/6 pass, one doc bug caught before deploy

This skill now has a **runnable suite** (`tests.php`, deployed as Code Snippets snippet
id 27, "AGENT-TEST-SUITE: jetengine-modules") per `docs/test-harness-guide.md` — run via
`GET /agent-test/v1/suite/jetengine-modules` (requires the always-active AGENT-TEST-CORE
harness, snippet id 22). These are reachability/gating smoke tests (`mod-1` through
`mod-6`), not the heavier fixture-based tests below (no meta-box field groups, options
pages, or data-store fixtures exist yet on this site).

**One real documentation bug was caught** while writing `mod-6` — re-reading
`includes/components/post-types/custom-tables/manager.php` to write the assertion
turned up that the class is namespaced `Jet_Engine\CPT\Custom_Tables\Manager`, not
`Jet_Engine\Custom_Tables\Manager` as this skill originally documented. Fixed in
`SKILL.md` (see its "Custom Meta Tables" correction note) before ever deploying the
suite, then confirmed live: `mod-6` asserts the wrong namespace is absent and the right
one resolves `get_table_name('agent_test_slug')` to `'agent_test_slug_meta'` — passed
on the very first live run.

**Result: 6/6 pass** at run_at "2026-07-16 16:06:27". Notable observations from the
actual run, not failures:
- `mod-4` (Dynamic Visibility) and `mod-5` (Data Stores) both passed, but
  `is_module_active` was `false` for both on this site — meaning these two *optional*
  JetEngine modules aren't enabled here. The tests only confirm the module-gating
  behavior is internally consistent (class loaded iff active), **not** that
  `Condition_Checker::check_cond()` or a real `Base_Store` subclass behaves correctly
  when the module is actually turned on — that still needs Test 4/Test 3 below run for
  real, once/if those modules get activated on this sandbox.
- `mod-1`/`mod-2` confirm reachability but found zero registered meta-box field groups
  or options pages on this site (`count: 0`, `slugs: []`) — so the priority-11 timing
  trap (Test 1 below) and the storage-mode `get_option()` visibility split (Test 2
  below) are still not exercised; they need real fixtures, not just reachability.

## Fixture status

No meta-box field groups, options pages, or data-store fixtures exist yet. Tests 1, 2,
3, and 5 below remain fixture-blocked (source-cited only); Test 4 remains blocked by
the Dynamic Visibility module being inactive on this site. See `tests.php` for what's
already automated (reachability layer only).

## Run log — 2026-07-16 (addendum): Meta Boxes custom Options Source pair added, 7/7 pass

New `SKILL.md` callout under "Meta Boxes" for registering a custom checkbox/radio/select
Options Source, sourced from a real Codelab snippet (a "User Roles" options source).
**mod-7** end-to-end drives the real two-filter pairing —
`jet-engine/meta-boxes/option-sources` (registers the source name via
`Jet_Engine_Meta_Boxes_Option_Sources::instance()->get_allowed_sources()`) and
`jet-engine/meta-fields/field-options` (supplies the option list via
`Jet_Engine_CPT_Meta::filter_options_list()`) — confirming both fire and that the second
filter is genuinely **3-arg** (`$options, $field, $this`), not 2-arg as the source
Codelab snippet itself registered it (harmless there since PHP ignores unrequested
trailing args, but `SKILL.md`'s example is written with the correct `10, 3`). PASS.

## Run log — 2026-07-16 (second addendum): Data Stores hooks, Options Pages registration, Maps Listings added, 10/10 pass

New `SKILL.md` sections (Data Stores AJAX-only hook gotcha + post-count hooks,
programmatic Options Page registration, Maps Listings geocode providers) sourced from
real Codelab/Gist snippets found auditing Crocoblock's public GitHub Gists account (305
gists; see repo `HANDOFF.md`). A Profile Builder section was also added but has no new
automated test — it only adds filter/hook documentation, no new callable surface beyond
what mod-1 through mod-9 already reach.

**mod-8** registers a throwaway `agent_test_store` (type `user-meta`, in-request only,
not persisted) and drives `increase_post_count()`/`decrease_post_count()` directly to
confirm `post-count-increased`/`post-count-decreased` fire with the right `$count`
values — since Data Stores is inactive on this sandbox (same as `mod-5`'s finding), this
currently passes via the "module inactive" branch, not a real exercise of the hooks;
re-run once/if the module is activated. **mod-9** confirms
`register_new_options_page()` really does add a `Jet_Engine_Options_Page_Factory` entry
to `->registered_pages` — PASS, exercised for real (Options Pages is a core, always-on
component, not gated). **mod-10** confirms `Providers_Manager` reachability and, since
Maps Listings is also inactive on this sandbox, mostly exercises the "module inactive,
consistency-only" branch like mod-4/mod-5/mod-8.

**Final result: 10/10 pass.** Notable: three of the ten tests (`mod-4`, `mod-5`, `mod-8`,
`mod-10` — four, not three) currently only assert internal consistency because their
modules (Dynamic Visibility, Data Stores, Maps Listings) are inactive on this sandbox —
if this repo's test site ever gets those modules turned on, re-run the suite to get a
real (not just gating-consistency) pass for those hook/method bodies.

## Test 1 (not yet run): `get_fields_for_context()` returns empty before `init` priority 11

**Claim:** calling `jet_engine()->meta_boxes->get_fields_for_context()` before `init`
priority ≥ 11 silently returns incomplete/empty results rather than erroring.

**Setup:**
```php
add_action( 'init', function() {
    error_log( '[MOD-TEST] fields at prio 5: ' . count( jet_engine()->meta_boxes->get_fields_for_context( 'post', 'post' ) ) );
}, 5 );
add_action( 'init', function() {
    error_log( '[MOD-TEST] fields at prio 20: ' . count( jet_engine()->meta_boxes->get_fields_for_context( 'post', 'post' ) ) );
}, 20 );
```

**Expected observable:** the priority-5 count is lower (likely 0) than the priority-20
count, on a post type that has at least one JetEngine meta-box field group registered.

**Pass criteria:** confirms the claimed silent-failure timing trap; if counts match at
both priorities, correct `SKILL.md`.

## Test 2 (not yet run): Options Page storage mode determines core `get_option()` visibility

**Claim:** a `'separate'`-storage options page exposes each field as its own
`wp_options` row readable via core `get_option()`; a combined-storage page does not.

**Setup:** create one options page of each storage mode (if the admin UI allows
choosing), save a known value in each, then call plain `get_option()` with the derived
option name against both.

**Expected observable:** `get_option()` returns the value for the `'separate'` page,
returns `false`/nothing for the combined page (value only visible via
`registered_pages[$slug]->get(...)`).

**Pass criteria:** matches the claim exactly, confirming which storage mode is
core-`get_option()`-compatible.

## Test 3 (not yet run): Data Stores only hold post IDs, not arbitrary values

**Claim:** `Base_Store::add_to_store()` is specifically for lists of post IDs
(favorites/recently-viewed), not a generic key-value store.

**Setup:**
```php
$store = jet_engine()->data_stores->stores_manager->get_store( 'test_store' );
$store->add_to_store( 'test_store', 123 ); // a real post ID
error_log( '[MOD-TEST] store contents: ' . wp_json_encode( $store->get( 'test_store' ) ) );
```

**Expected observable:** `get()` returns an array containing `123`; attempting to store
a non-post-id scalar (e.g. a string) either gets rejected/cast, or is accepted without
validation — note whichever occurs.

**Pass criteria:** confirms the "list of post IDs" shape; any type coercion/validation
behavior observed gets added to `SKILL.md`.

## Test 4 (not yet run): Dynamic Visibility `Condition_Checker::check_cond()` is callable standalone

**Claim:** visibility conditions can be evaluated manually outside of an
Elementor/Blocks/Bricks render, via `Condition_Checker::check_cond()` directly.

**Setup:** call `check_cond()` with a hand-built `$settings` array matching one of the
built-in condition types (e.g. a simple "user is logged in" condition), inside a plain
snippet/shortcode, and log the boolean result while logged in vs. logged out.

**Expected observable:** result flips correctly with login state, confirming the
method works standalone without a builder context.

**Pass criteria:** correct boolean returned in both states with no fatal/dependency on
builder-specific globals.

## Test 5 (not yet run): Custom Meta Table storage bypasses `get_post_meta()`

**Claim:** a meta field with "Storage" set to a custom table is invisible to core
`get_post_meta()` and only readable via `Manager::get_db_instance()`.

**Setup:** create a post-type meta field group with one field's storage set to custom
table, save a value through the normal edit-post UI, then compare
`get_post_meta( $post_id, $field_name, true )` (expect empty) against
`jet_engine()->custom_tables->get_db_instance( $object_slug, $fields )->query(...)`
(expect the real value).

**Pass criteria:** confirms the silent-miss gotcha exactly as documented.

## Cleanup note

No fixtures created yet for this skill — each test above should create clearly
`AGENT-TEST`-namespaced options pages/meta field groups/stores and keep them per this
repo's convention once run.
