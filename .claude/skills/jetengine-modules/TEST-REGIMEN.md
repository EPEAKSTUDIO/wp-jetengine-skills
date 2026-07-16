# Test regimen: jetengine-modules

Validates claims in `SKILL.md`. Not yet run — written source-cited-only on 2026-07-16.
Run against the sandbox site (`jackfruit.epeak.studio`, JetEngine, confirmed test
install).

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
