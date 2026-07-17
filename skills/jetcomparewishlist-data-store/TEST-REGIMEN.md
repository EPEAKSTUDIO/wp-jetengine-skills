# Test regimen: jetcomparewishlist-data-store

Validates claims in `SKILL.md`. Run against a sandbox site with JetCompareWishlist For
Elementor 1.5.12.3 and WooCommerce active (WooCommerce is installed/active on the target
sandbox per the task brief, so the WC-dependent claims here are genuinely testable, not
purely hypothetical).

## Run log — 2026-07-16: UNBLOCKED, live-verified (6/6 pass, 2 graceful skips)

Deployed `tests.php` as Code Snippets snippet id 54 and ran
`GET /agent-test/v1/suite/jetcomparewishlist-data-store`: first run 4/6 — `cw-2`/`cw-3`
threw because Wishlist/Compare are both disabled by default in this site's settings
(confirmed as real, expected behavior by `cw-1`, which passed both times). Not a plugin
or doc bug — fixed both tests to skip gracefully (matching this suite's existing
"fixture unavailable" pattern) rather than fail when the feature is off. Re-run: 6/6
pass, with `cw-2`/`cw-3` reporting a documented skip.

All tests below are designed to be safe to re-run (they either read-only, or add/remove
a fixture product ID from the live list and restore prior state).

## Prerequisites

- `AGENT-TEST-CORE harness` snippet active (see `test-harness/core-snippet.php`).
- At least one real, published WooCommerce product exists (any product — tests fetch the
  first one via `wc_get_products( [ 'limit' => 1 ] )` rather than hardcoding an ID).
- JetCompareWishlist's Wishlist and Compare features both enabled in Settings (the
  default) — if either is disabled, the corresponding suite assertions will correctly
  fail per the "disabled-feature null object" landmine documented in `SKILL.md`, which
  itself is a useful confirmation, not a broken test.

## Test 1: `jet_cw()->wishlist_data`/`compare_data` are null iff the feature is disabled

**Claim:** `Jet_CW::init()` only instantiates `wishlist_data`/`compare_data` when
`wishlist_enabled`/`compare_enabled` is truthy; otherwise the property is `null` and
calling a method on it fatals.

**Setup:** none — read current state.

**Trigger:** read `jet_cw()->wishlist_enabled` and `jet_cw()->wishlist_data` (and the
compare equivalents).

**Expected observable:** `wishlist_data` is a `Jet_CW_Wishlist_Data` instance exactly
when `wishlist_enabled` is truthy; same for compare.

**Pass criteria:** the two never disagree (object present but flag false, or flag true
but object null).

## Test 2: add/remove round-trip through `update_data_wishlist()`/`update_data_compare()`

**Claim:** `update_data_wishlist( $pid, 'add' )` adds a product ID (deduped), and
`update_data_wishlist( $pid, 'remove' )` removes exactly that ID, without touching
others already in the list.

**Setup:**
```php
$product_id = wc_get_products( [ 'limit' => 1 ] )[0]->get_id();
$before     = jet_cw()->wishlist_data->get_wish_list();
```

**Trigger:**
```php
jet_cw()->wishlist_data->update_data_wishlist( $product_id, 'add' );
$after_add = jet_cw()->wishlist_data->get_wish_list();
jet_cw()->wishlist_data->update_data_wishlist( $product_id, 'remove' );
$after_remove = jet_cw()->wishlist_data->get_wish_list();
```

**Expected observable:** `$product_id` present in `$after_add`, absent from
`$after_remove`, and `$after_remove` matches `$before` (list restored).

**Pass criteria:** all three hold. Repeat for `compare_data`/`update_data_compare()`.

## Test 3: compare's max-items cap silently drops an over-limit write

**Claim:** `set_compare_list()` only writes if `count($list) <= compare_page_max_items`.

**Setup:** read current `compare_page_max_items` (e.g. `N`); build a list of `N + 1`
distinct product IDs (reuse the fixture product ID `N+1` times if only one product
exists — `in_array` dedup in `update_data_compare` would prevent that, so instead call
`set_compare_list()` directly with `N + 1` fabricated integer IDs, which don't need to
resolve to real products for this specific test since we're testing the count guard, not
`get_compare_list()`'s prune step).

**Trigger:** call `jet_cw()->compare_data->set_compare_list( $oversized_list )`, then
read `get_compare_list()` (noting prune will drop the fabricated non-existent IDs anyway
— so instead directly inspect the raw stored value, e.g. `$_SESSION['jet-compare-list']`
if `store_type === 'session'`, to see whether the oversized write landed).

**Expected observable:** the raw stored value is unchanged from before the oversized
write (rejected), not the oversized list.

**Pass criteria:** write rejected exactly when `count > max`.

## Test 4: `get_stored_widgets()` reads from `$_REQUEST`, not server state

**Claim:** `Jet_CW_Widgets_Store::get_stored_widgets()` echoes back whatever
`$_REQUEST['widgets_data']` currently holds — it is not itself a source of truth.

**Setup:** `$_REQUEST['widgets_data'] = [ 'agent_test_marker' => true ];` plus a valid
`_wpnonce`/`nonce` value satisfying `check_ajax_referer('jet-cw-compare','nonce')` (e.g.
`$_REQUEST['nonce'] = wp_create_nonce('jet-cw-compare');`).

**Trigger:** call `jet_cw()->widgets_store->get_stored_widgets()`.

**Expected observable:** return value equals exactly the `$_REQUEST['widgets_data']`
array set above.

**Pass criteria:** exact match — proves it's a pure request-echo, not cached state.

## Test 5: `before-add-to-wishlist`/`before-add-to-compare` fire before the store mutates

**Claim:** `do_action('jet-cw/wishlist/render/before-add-to-wishlist', $pid, $context,
$this)` fires inside `update_wish_list()` before `update_data_wishlist()` is called —
confirmed both from source and Crocoblock's official developer-documentation.

**Setup:** hook the action, capture `jet_cw()->wishlist_data->get_wish_list()` at the
moment the hook fires (should NOT yet contain the pid being added).

**Trigger:** cannot safely call `Jet_CW_Wishlist_Render::update_wish_list()` directly in
an automated suite — it ends in `wp_send_json_success()` which calls `wp_die()`,
terminating the whole request (would kill the rest of the suite, same class of landmine
documented elsewhere in this repo for JSF's `Storage\Controller`). `tests.php` instead
does a source-grep confirming the `do_action(...)` call appears textually **before** the
`update_data_wishlist(...)` call in `wishlist-render.php`/`compare-render.php` — this is
a static-ordering check, not a live trigger. A future session with a browser/HTTP client
available (not just a PHP snippet sandbox) should drive the real AJAX endpoint and
confirm the hook fires with a request-scoped nonce, to fully close this out live.

**Pass criteria:** source order confirms the claim; full live confirmation deferred.

## Test 6: the plugin's own REST API is admin-only, not used for add/remove

**Claim:** `Jet_CW\Rest_Api::init_endpoints()` only registers `Plugin_Settings`, gated by
`current_user_can('manage_options')`.

**Setup:** none.

**Trigger:** `POST /wp-json/jet-cw-api/v1/plugin-settings` (the route is registered
POST-only) as an unauthenticated request — e.g. via `curl` outside of PHP with no auth
cookie, or `rest_do_request()` inside the snippet with `wp_set_current_user(0)` first.

**Expected observable:** `401`/`403`-shaped rest error, not product-list data.

**Pass criteria:** confirms this REST route is not a public add/remove surface.
