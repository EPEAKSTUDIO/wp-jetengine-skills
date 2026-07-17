# Test regimen: jetcomparewishlist-integrations

Validates claims in `SKILL.md`. Run against a sandbox site with JetCompareWishlist For
Elementor 1.5.12.3, WooCommerce, and (ideally) JetEngine active — JetEngine is expected
to be present on the target sandbox (this repo builds JetEngine skills), so the
`class_exists('Jet_Engine')`-gated compatibility package should genuinely load there.

## Run log — 2026-07-16: UNBLOCKED, live-verified (5/5 pass)

Deployed `tests.php` as Code Snippets snippet id 55 and ran
`GET /agent-test/v1/suite/jetcomparewishlist-integrations`: **5/5 pass**, no fixes needed.

## Prerequisites

- `AGENT-TEST-CORE harness` snippet active.
- WooCommerce active (all `template-functions` filters take a `WC_Product`-shaped
  object, though `tests.php` uses a minimal duck-typed stub exposing only the methods
  each filter path actually calls, so a real product isn't strictly required for those
  specific assertions — see `tests.php` comments).
- JetEngine active, to exercise the `class_exists('Jet_Engine')` compatibility-package
  gate live (Test 3 / `cwi-3`).

## Test 1: `jet-cw/template-functions/title` is honored

**Claim:** `Jet_CW_Functions::get_title($product)` passes its built HTML through
`apply_filters('jet-cw/template-functions/title', $title)` before returning.

**Setup:**
```php
add_filter( 'jet-cw/template-functions/title', function( $title ) {
    return $title . '<!--agent-test-marker-->';
} );
```

**Trigger:** call `jet_cw_functions()->get_title($product)` for any product (a duck-typed
stub exposing `get_id()`, `get_permalink()`-compatible id, `get_type()`, `get_title()` is
sufficient — the method never does an `instanceof WC_Product` check).

**Expected observable:** returned string ends with the marker.

**Pass criteria:** marker present — confirms the filter is genuinely applied, not just
documented.

## Test 2: `jet-cw/template-functions/compare-custom-field/{$field_key}` uses the literal field key in the tag name

**Claim:** the filter tag is built dynamically per `$field_key`, e.g.
`compare-custom-field/__additional_params`, not a single static tag.

**Setup:**
```php
add_filter( 'jet-cw/template-functions/compare-custom-field/agent_test_field', function( $value ) {
    return 'AGENT_TEST_OVERRIDE';
} );
```

**Trigger:** call
`jet_cw_functions()->get_custom_field($product_stub, ['compare_table_custom_field' => 'agent_test_field'])`.

**Expected observable:** the returned markup contains `AGENT_TEST_OVERRIDE`.

**Pass criteria:** confirms the dynamic-tag construction matches the documented pattern,
and that a filter registered for one specific field key doesn't leak into others (repeat
with a second, un-hooked field key and confirm no override applied there).

## Test 3: compatibility packages load iff the companion plugin's class exists

**Claim:** `Jet_CW_Engine_Package`/`Jet_CW_Woo_Builder_Package`/`Jet_CW_Popup_Package`
only exist as loaded classes when `Jet_Engine`/`Jet_Woo_Builder`/`Jet_Popup` respectively
already exist.

**Setup:** none — read current plugin-active state.

**Trigger:** check `class_exists('Jet_CW_Engine_Package')` against
`class_exists('Jet_Engine')` (and the two other pairs).

**Expected observable:** the two booleans always agree, for all three pairs.

**Pass criteria:** no mismatch — proves the `class_exists()` gate in
`include_plugin_integration_file()` is working as documented, not just present in source.

## Test 4: `jet-cw/in-elementor` filter overrides `in_elementor()`'s result unconditionally

**Claim:** `Jet_CW_Integration::in_elementor()`'s final `apply_filters('jet-cw/in-elementor', $result)`
call lets a callback force the return value regardless of the internal
`wp_doing_ajax()`/edit-mode/preview-mode logic.

**Setup:**
```php
add_filter( 'jet-cw/in-elementor', function( $result ) {
    return 'AGENT_TEST_FORCED';
} );
```

**Trigger:** call `jet_cw()->integration->in_elementor()` from a plain (non-Elementor,
non-AJAX) request context.

**Expected observable:** returns the literal string `'AGENT_TEST_FORCED'`, not `false`
(which is what the internal logic alone would produce in this context).

**Pass criteria:** exact match — confirms the filter is the true last word, not merely
advisory.

## Test 5: `jet-cw/dashboard/settings/{$setting}` filter is honored per-setting-name

**Claim:** `Jet_CW_Settings::get($setting)` applies
`apply_filters('jet-cw/dashboard/settings/' . $setting, $value, $this)`, keyed by the
literal setting name.

**Setup:**
```php
add_filter( 'jet-cw/dashboard/settings/agent_test_setting', function( $value ) {
    return 'AGENT_TEST_OVERRIDE';
} );
```

**Trigger:** call `jet_cw()->settings->get('agent_test_setting', 'default_value')`.

**Expected observable:** returns `'AGENT_TEST_OVERRIDE'`, not `'default_value'`.

**Pass criteria:** exact match; repeat with an unhooked setting name to confirm it still
returns the plain default (no cross-talk between differently-named settings).

## Test 6 (not yet automatable without a browser): button-injection path is verifiably-dual

**Claim:** JetWooBuilder-hook injection and default-WooCommerce-template injection are
independent and gated by different settings.

**Setup:** on a page using JetWooBuilder's Products Grid widget, toggle
`add_default_wishlist_button` off; separately render a plain WooCommerce shop loop.

**Expected observable:** the wishlist button still appears inside the JetWooBuilder grid
(path 1, unaffected by the `add_default_*` setting) but does NOT appear on the plain
WooCommerce loop template until `add_default_wishlist_button` is turned on.

**Pass criteria:** confirms the two paths are genuinely independent, not one gating the
other. Needs an actual rendered page (Elementor + WooCommerce templates), not just a PHP
snippet — left for a future session with browser access to the sandbox front end.
