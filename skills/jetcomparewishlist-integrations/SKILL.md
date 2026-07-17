---
name: jetcomparewishlist-integrations
description: Use when rendering custom product fields inside JetCompareWishlist's Compare Table/Wishlist widgets (the jet-cw/template-functions/* filter family — the real integration point for combining with JetEngine's checkbox-field renderer), wiring compare/wishlist buttons into JetWooBuilder product grid/list widgets or default WooCommerce templates, or working with the plugin's JetEngine/JetWooBuilder/JetPopup compatibility packages (lazy-load/layout-switcher/popup AJAX responses re-injecting widget state). Captures verified behavior of JetCompareWishlist For Elementor 1.5.12.3 source, cross-checked against Crocoblock's public developer-documentation repo, live-verified 2026-07-16 against jackfruit.epeak.studio (5/5 tests.php assertions passing).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetCompareWishlist Template Rendering & Cross-Plugin Integrations

Verified facts about JetCompareWishlist's per-field template-rendering filter family, how
its buttons get injected into JetWooBuilder/WooCommerce product templates, and its three
thin compatibility packages for JetEngine/JetWooBuilder/JetPopup. Confirmed against
JetCompareWishlist For Elementor 1.5.12.3 source (`plugins/jet-compare-wishlist/`) and
cross-checked against Crocoblock's public
[developer-documentation](https://github.com/Crocoblock/developer-documentation) repo
(`18-jet-compare-wishlist/`).

## `jet-cw/template-functions/*` — one filter per Compare Table / Wishlist data field

All defined in `Jet_CW_Functions` (`includes/class-jet-cw-functions.php`), reachable via
`jet_cw_functions()`. Each wraps a single, already-`sprintf()`'d HTML fragment for one
product and one field — the standard extension point to reskin/wrap one field's markup
without touching the whole row template:

`title`, `thumbnail`, `price`, `rating`, `sku`, `dimension`, `weight`, `excerpt`,
`description`, `categories`, `tags`, `compare-remove`, `wishlist-remove`,
`add-to-cart-settings` (filters the *args array* before the add-to-cart link is built,
not the HTML), `visible-attributes`, `exclude-attributes` (the last two on
`Jet_CW_Widgets_Functions`, `includes/class-jet-cw-widgets-functions.php`, since they
control which WooCommerce product attributes appear as Compare Table rows at all, not a
single field's markup).

### `jet-cw/template-functions/compare-custom-field/{$field_key}` — dynamic, per configured Custom Field row

```php
// includes/class-jet-cw-functions.php:514
$custom_field = apply_filters( 'jet-cw/template-functions/compare-custom-field/' . $field_key, $field_value );
```

One filter tag **per Custom Field row** configured in the Compare Table widget's "Custom
Field" data-type rows (the `compare_table_custom_field` setting picks `$field_key`) —
receives the raw `get_post_meta( $product->get_id(), $field_key, true )` value. Confirmed
both in source and in Crocoblock's official developer-documentation
(`18-jet-compare-wishlist/01-hooks/02-templates/filters.md`), whose own worked example is
exactly the genuine JetEngine-integration use case:

```php
add_filter( 'jet-cw/template-functions/compare-custom-field/__additional_params', function( $field_value ) {
    $field_value = jet_engine_render_checkbox_values( $field_value );
    return $field_value;
} );
```

**This JetEngine call is 100% user-supplied glue code** — `class-jet-cw-functions.php`
itself has zero JetEngine dependency; the filter is a plain generic value-transform hook
that happens to be the natural place to reach for a JetEngine helper when the underlying
custom field was populated by a JetEngine checkbox/repeater meta field (which stores an
array, needing `jet_engine_render_checkbox_values()`/`jet_engine()` to render as text).

## Button injection: two independent paths, only one needs JetWooBuilder

`Jet_CW_Wishlist_Integration`/`Jet_CW_Compare_Integration`
(`includes/wishlist/class-jet-cw-wishlist-integration.php`,
`includes/compare/class-jet-cw-compare-integration.php`) wire buttons into product
listings via **two separate mechanisms that don't overlap**:

1. **JetWooBuilder's own per-template action hooks** —
   `jet-woo-builder/templates/jet-woo-products/wishlist-button`,
   `.../jet-woo-products-list/wishlist-button` (and `compare-button` equivalents) — these
   only ever fire if JetWooBuilder's own Products Grid/List widgets are rendering, so
   JetWooBuilder must actually be active and in use on that page for this path to matter.
2. **Vanilla WooCommerce templates** — gated by the *separate* settings
   `add_default_wishlist_button`/`add_default_compare_button`; when enabled, the same
   button widget is also injected via `woocommerce_after_shop_loop_item` (priority 11)
   and `woocommerce_single_product_summary` (priority 31) — **zero JetWooBuilder
   involvement**, works on a plain WooCommerce archive/single-product template.

Before assuming a customization needs to target JetWooBuilder's hooks, check which path
is actually firing on the page in question — a default-WC-theme site with
`add_default_wishlist_button` enabled never touches path 1 at all.

## Three thin compatibility packages — none of them touch the compare/wishlist lists themselves

All three re-inject `jet_cw()->widgets_store->get_widgets_types()` (the same
selector→settings map documented in `jetcomparewishlist-data-store`) into *another
plugin's own* AJAX response, so a lazy-loaded or AJAX-swapped DOM region still knows
which of its buttons are in the "added" state — none of them mutate the compare/wishlist
data itself:

- **`includes/lib/compatibility/plugins/jet-engine.php`** (`Jet_CW_Engine_Package`):
  hooks `jet-engine/ajax-handlers/before-call-handler` to re-`enqueue_styles()` on every
  JetEngine AJAX call (skipped if the request is a JetEngine *editor* AJAX call, detected
  via `$_POST['isEditMode']`), and `jet-engine/ajax/get_listing/response` to inject
  `jetCompareWishlistWidgets` into a lazy-loaded Listing Grid's AJAX response (only when
  that listing's `lazy_load` setting is `'yes'`).
- **`includes/lib/compatibility/plugins/jet-woo-builder.php`**
  (`Jet_CW_Woo_Builder_Package`): the same injection into
  `jet-woo-builder/ajax-handler/get-switcher-template/response` (JetWooBuilder's own
  "layout switcher" AJAX action).
- **`includes/lib/compatibility/plugins/jet-popup.php`** (`Jet_CW_Popup_Package`): the
  same injection into `jet-popup/ajax-request/after-content-define/post-data`, but only
  when the popup payload's own `isJetWooBuilder`/`isJetEngine` flags are set.

**All three files are conditionally `require()`'d**, only if the companion plugin's own
main class already exists, by
`Jet_CW_Compatibility::include_plugin_integration_file()`
(`includes/lib/compatibility/class-jet-cw-compatibility.php:138-161`):

```php
$plugins = [
    'jet-popup.php'       => [ 'cb' => 'class_exists', 'args' => 'Jet_Popup' ],
    'jet-engine.php'      => [ 'cb' => 'class_exists', 'args' => 'Jet_Engine' ],
    'jet-woo-builder.php' => [ 'cb' => 'class_exists', 'args' => 'Jet_Woo_Builder' ],
];
```

On a site without JetEngine/JetPopup/JetWooBuilder active, the corresponding
`Jet_CW_*_Package` class **never gets loaded at all** — hooking one of these hook names
yourself pre-emptively (e.g. to prepare for a plugin that might get installed later) is
harmless (no-op), not a fatal, since the `add_action`/`add_filter` call for a hook that
never fires simply never runs its callback.

## `in_elementor()` — a private-flag Elementor-AJAX detector, same gotcha class as JetSmartFilters

`Jet_CW_Integration::in_elementor()` (`includes/class-jet-cw-integration.php:47-60`):

```php
public function in_elementor() {
    $result = false;
    if ( wp_doing_ajax() ) {
        $result = $this->is_elementor_ajax;
    } elseif ( Elementor\Plugin::instance()->editor->is_edit_mode() || Elementor\Plugin::instance()->preview->is_preview_mode() ) {
        $result = true;
    }
    return apply_filters( 'jet-cw/in-elementor', $result );
}
```

`$is_elementor_ajax` is a private property, only ever set `true` by the constructor's own
`add_action( 'wp_ajax_elementor_render_widget', [ $this, 'set_elementor_ajax' ] )`
(`class-jet-cw-integration.php:22,38-40`) — during any *other* `wp_doing_ajax()` request
(including JetCompareWishlist's own `jet_update_wish_list`/`jet_update_compare_list`
actions), `in_elementor()` returns `false` even if the request is conceptually
"Elementor-adjacent". This is the same class of gotcha documented in
`jetsmartfilters-query` for its own AJAX-action-name detection: **don't assume
`wp_doing_ajax()` alone tells you "this is an Elementor render"** — only the specific
`elementor_render_widget` action sets the flag, and it's per-request state, not
persisted. `jet-cw/in-elementor` is the correct filter to override the result if a custom
AJAX action needs to be treated as an Elementor context.

## Settings-layer filters (admin-only)

- `jet-cw/dashboard/settings/{$setting}` (`includes/class-jet-cw-settings.php:205`) —
  filters a single setting's value on every `Jet_CW_Settings::get()` call, keyed by
  setting name (e.g. `jet-cw/dashboard/settings/compare_page`) — this is what
  `Jet_CW_Compatibility` itself hooks for WPML/Polylang translated-page-ID resolution
  (`class-jet-cw-compatibility.php:38-39,61-79`), the reference pattern to copy for a
  similar per-setting override.
- `jet-cw/admin/settings-page/localized-config` — mutates the whole settings-page JS
  localization payload (admin editor only).
- `jet-cw/settings/registered-subpage-modules` (`includes/settings/manager.php:32-45`) —
  registers a whole new JetDashboard settings subpage module, alongside the built-in
  Compare/Wishlist/Available-Addons ones.

All three confirmed against Crocoblock's official developer-documentation
(`18-jet-compare-wishlist/01-hooks/03-settings/filters.md`), which matched source exactly.

## Gotchas

- **The `compare-custom-field` JetEngine pattern is user glue, not a shipped dependency**
  — don't expect `jet_engine_render_checkbox_values()`/`jet_engine()` calls anywhere in
  `jet-cw`'s own source; the plugin only provides the generic per-field-key filter.
- **Two independent button-injection paths** — JetWooBuilder-hook path vs.
  default-WooCommerce-template path, gated by different settings, don't assume one
  implies the other is active.
- **Compatibility packages are absent (not just inert) without the companion plugin** —
  `class_exists()`-gated `require()`, so e.g. `Jet_CW_Engine_Package` simply doesn't exist
  as a class on a site without JetEngine.
- **`in_elementor()`'s AJAX detection is action-name-gated**, not a general
  `wp_doing_ajax()` check — same class of landmine as JetSmartFilters' own AJAX-action
  detection (see `jetsmartfilters-query`).

## How this was verified

Read `includes/class-jet-cw-functions.php`, `includes/class-jet-cw-widgets-functions.php`,
`includes/wishlist/class-jet-cw-wishlist-integration.php`,
`includes/compare/class-jet-cw-compare-integration.php`,
`includes/lib/compatibility/class-jet-cw-compatibility.php`,
`includes/lib/compatibility/plugins/{jet-engine,jet-woo-builder,jet-popup}.php`,
`includes/class-jet-cw-integration.php`, `includes/class-jet-cw-settings.php`, and
`includes/settings/manager.php` in JetCompareWishlist 1.5.12.3 source by direct file:line
citation. Cross-checked the `compare-custom-field` dynamic filter and the three
settings-layer filters against Crocoblock's official
[developer-documentation](https://github.com/Crocoblock/developer-documentation) repo
(`18-jet-compare-wishlist/01-hooks/02-templates/filters.md`,
`18-jet-compare-wishlist/01-hooks/03-settings/{actions,filters}.md`), which matched
source exactly, including the docs' own worked JetEngine-integration example. Not yet
verified against a running site — see `TEST-REGIMEN.md`; `tests.php` has a
reachability/direct-call suite ready to deploy.
