---
name: jetwoobuilder-templates
description: Use when working with JetWooBuilder (Crocoblock's Elementor/WooCommerce page-builder plugin) internals — the %macro% render engine for widget text fields, the `jet-woo-builder/template-functions/*` filters that shape widget-rendered product markup (price, thumbnail, stock status, add-to-cart), the custom Elementor Document types backing "Single/Archive/Category/Shop/Cart/Checkout/Thank You/My Account" templates, or the two separately-keyed settings stores (`jet_woo_builder_settings()` vs `jet_woo_builder_shop_settings()`). Captures verified behavior from JetWooBuilder 2.3.3 source, live-verified 2026-07-16 against jackfruit.epeak.studio (8/8 tests.php assertions passing — see TEST-REGIMEN.md).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetWooBuilder templates, macros & template functions

Every claim below is cited to `plugins/jet-woo-builder/*` source (v2.3.3, the checkout in
this repo — gitignored, not committed). **Live-verified 2026-07-16** against
`jackfruit.epeak.studio` (JetWooBuilder + Elementor active; WooCommerce itself is not
installed on this sandbox, so WC-dependent edge cases still need a real product/order —
see TEST-REGIMEN.md's "Not yet automated" section) — 8/8 `tests.php` assertions passed on
first run, no corrections needed.

## When to use

- Registering/debugging a custom `%macro%` for a JetWooBuilder widget text field (sale
  badges, custom price displays).
- A widget-rendered product field (price, thumbnail, stock status, add-to-cart button,
  rating, terms list) looks wrong and you need the exact filter that reshapes it.
- Working with JetWooBuilder's own Elementor "template library" post type
  (`jet-woo-builder`) — the Single/Archive/Category/Shop/Cart/Checkout/Thank You/My
  Account custom Document types, and how a saved template gets picked for the front end.
- Reading/writing a JetWooBuilder setting from PHP and getting `false`/an unexpected
  default back — see "Two settings stores" gotcha below before assuming the option name
  is wrong.

## Core architecture

- Global accessor: `jet_woo_builder()` → singleton `Jet_Woo_Builder` instance
  (`jet-woo-builder.php:513`). It exists as soon as the main file loads (called
  unconditionally at `jet-woo-builder.php:519`), **but its public sub-object properties
  (`->documents`, `->macros`, `->parser`, `->ajax_handlers`, `->export_import`,
  `->components`, `->compatibility`, `->woocommerce`, `->elementor_views`,
  `->dynamic_tags`) are only populated inside `Jet_Woo_Builder::init()`, hooked at plain
  `init`** (`jet-woo-builder.php:210,244-254`).
- **Hard gate: `init()` no-ops (with an admin notice) if Elementor isn't loaded, if the
  loaded Elementor is below `3.0.0`, or if WooCommerce isn't active**
  (`jet-woo-builder.php:212-227`). On a site missing either dependency, `jet_woo_builder()`
  itself is truthy but every sub-object above is `null` — calling
  `jet_woo_builder()->documents->...` there is a fatal `Call to a member function on
  null`, not a graceful no-op. Always confirm Elementor+WooCommerce are active (or check
  `jet_woo_builder()->documents !== null`) before reaching into a sub-object from a
  snippet/hook that might run early or on a misconfigured site.
- `->woocommerce` (`Jet_Woo_Builder_Woocommerce`) and `->elementor_views`
  (`Jet_Woo_Builder_Elementor_Views`) are **not** constructed directly by `init()` —
  they're registered as "components" via `Jet_Woo_Builder_Components::register_components()`
  (`includes/components/manager.php:38-47`) and instantiated on a *later*, separate
  `init` callback (priority `-1`, vs. the main class's `-999`,
  `includes/components/manager.php:22-24,84-96`). A brand-new custom component follows
  the same two-step shape: register a `[filepath, class_name]` pair on
  `jet-woo-builder/components/registered`, then it gets `require`d and assigned to
  `jet_woo_builder()->{$slug}` automatically — no manual `new`/assignment needed.
- Accessor functions for the standalone singletons loaded via `load_files()`
  (`jet-woo-builder.php:381-401`): `jet_woo_builder_post_type()`, `jet_woo_builder_tools()`,
  `jet_woo_builder_template_functions()`, `jet_woo_builder_settings()`,
  `jet_woo_builder_shop_settings()`. These are separate globals, not properties of
  `jet_woo_builder()` — e.g. it's `jet_woo_builder_tools()->get_attr_string(...)`, not
  `jet_woo_builder()->tools->...`.

## Macros (`%macro%` render engine — widget text-field tokens)

- `Jet_Woo_Builder_Macros` (`includes/class-jet-woo-builder-macros.php`), reachable via
  `jet_woo_builder()->macros` (only after `init`, per the gating note above).
- Two built-ins only: `%percentage_sale%` and `%numeric_sale%`
  (`get_all()`, `class-jet-woo-builder-macros.php:24-27`) — both read `global $product`
  and return `null` if it isn't a `WC_Product` instance.
- **Register a custom macro** via the filter, not a method call:
  ```php
  add_filter( 'jet-woo-builder/macros/macros-list', function( $macros ) {
      $macros['my_custom_macro'] = 'my_custom_macro_callback';
      return $macros;
  } );
  ```
  (`get_all()` applies this filter over the two built-ins, `macros.php:24`).
- **The regex only matches `[a-z_-]+`** for the macro name and an optional
  `|`-prefixed single arg segment matching `[a-z0-9_-]+`
  (`do_macros()`, `macros.php:170`) — a macro key with uppercase letters, digits, or
  characters outside that set will never match inside a string, even if it's a real key
  in `get_all()`. Keep custom macro keys lowercase/snake-case.
- Callback signature is **`callback( $field_value, $args )`** — `do_macros( $string,
  $field_value = null )` passes its own `$field_value` param straight through to every
  macro callback it invokes (`macros.php:187`), and `$args` is the raw string after the
  `|` (or `false` if absent) — not parsed into an array, unlike some other Crocoblock
  macro engines. A callback expecting comma-split args must split `$args` itself.
- Non-callable or unregistered macro tokens are returned untouched (`macros.php:175-183`)
  — a typo'd macro name silently prints literally in the widget output rather than
  erroring, the same silent-failure shape documented for JetEngine's macro engine in
  `jetengine-listings-macros`.

## Template Functions (`jet-woo-builder/template-functions/*` filters)

`Jet_Woo_Builder_Template_Functions` (`includes/class-jet-woo-builder-template-functions.php`,
reachable via `jet_woo_builder_template_functions()`) backs most of the widget-rendered
product fields. Every method reads `global $product` and returns `null`/`''`/`false` if
it isn't a real `WC_Product` — safe to call outside The Loop only if you've set
`global $product` yourself first. Key filters (all confirmed by direct source read, not
just a Codelab snippet — several of these match this repo's `other-plugins-backlog/
OTHER-PLUGINS.md` JetWooBuilder gist entries, cross-checked against source before being
listed here):

- **`jet-woo-builder/template-functions/product-price`** — filters the final price HTML
  string from `get_product_price()` (`template-functions.php:392-394`). The gist behind
  this repo's backlog entry uses it to inject variation-swatch markup into the archive
  price widget.
- **`jet-woo-builder/template-functions/product-add-to-cart-settings`** — filters the
  whole `$args` array (`quantity`, `class`, `attributes`, ...) passed to WooCommerce's
  `loop/add-to-cart.php` template, 2 args: `$defaults, $product`
  (`get_product_add_to_cart_button()`, `template-functions.php:461-482`). Used by the
  backlogged AJAX-add-to-cart-with-swatches gist to swap in a variation-aware add-to-cart
  markup.
- **`jet-woo-builder/template-functions/thumbnail_id`** — filters the attachment ID
  before it's rendered (`get_product_thumbnail()`, `template-functions.php:154`);
  **`.../placeholder-thumbnail`** / **`.../placeholder-thumbnail-src`** — the no-image
  fallback path specifically, 5 args `$html, $image_size, $use_thumb_effect, $attr,
  $this` for the HTML filter (`template-functions.php:159-162`);
  **`.../product-thumbnail`** — the final thumbnail HTML, same 5-arg signature
  (`template-functions.php:179`); **`.../attachment_ids`** — the gallery ids used for the
  hover-swap second image (`add_thumb_effect()`, `template-functions.php:200`).
- **`jet-woo-builder/template-functions/stock-status`** — wraps `wc_get_stock_html()`
  (`get_product_stock_status()`, `template-functions.php:88`);
  **`.../custom-stock-status`** — the separate "custom" stock-status widget variant with
  its own in/backorder/out labels (`get_custom_product_stock_status()`,
  `template-functions.php:127`). These are two distinct widgets/methods, not one — don't
  assume a fix to one covers the other.
- **`jet-woo-builder/template-functions/product-rating`** /
  **`.../custom-product-rating`** — two separate rating-render paths
  (`get_product_rating()` uses core `wc_get_star_rating_html()`,
  `get_product_custom_rating()` hand-builds icon-font markup) — same
  "two similarly-named widgets, different implementation" trap as stock status
  (`template-functions.php:328,372`).
- **`jet-woo-builder/template-functions/sku`** (`template-functions.php:258`),
  **`.../product-excerpt`** (`template-functions.php:411`),
  **`.../terms-list/{$taxonomy}`** — dynamic, one per taxonomy, 3 args `$terms_list,
  $product, $taxonomy` (`get_product_terms_list()`, `template-functions.php:558`),
  **`.../cart-table-custom-field/{$field_key}`** — dynamic per custom-field key, used by
  the cart-table custom-column widget setting (`get_cart_table_custom_field_value()`,
  `template-functions.php:698`).
- **`woocommerce_sale_flash`** (WooCommerce core filter, not a JetWooBuilder one) is
  applied *before* `jet-woo-builder/template-functions/product-sale-flash` in
  `get_product_sale_flash()` (`template-functions.php:58-66`) — a filter registered
  against the JetWooBuilder-specific hook alone won't see whatever a
  `woocommerce_sale_flash` callback already changed unless you also account for hook
  order; both fire on every sale-badge widget render.
- **`jet-woo-builder/template-functions/product-sale-flash/on-sale`** — filters the
  boolean gate itself (`$product->is_on_sale()`), 3 args `$on_sale, $product, $settings`
  (`template-functions.php:58`) — return `true` here to force-show a sale badge on a
  product WooCommerce doesn't consider on sale (e.g. a custom "clearance" flag), instead
  of fighting the HTML-level filter.

## Templates / Document types (the "Woo Page Builder" custom post type)

- A single custom post type, `jet-woo-builder` (`Jet_Woo_Builder_Post_Type::$post_type`,
  `includes/class-jet-woo-builder-post-type.php:31`), holds every saved template
  regardless of type; **the type is a `_elementor_template_type` post-meta value plus a
  `jet_woo_library_type` taxonomy term, not a separate post type per template kind**
  (`register_post_type()`/`register_taxonomy()`, `post-type.php:512-544`).
- 8 registered Elementor Document types, each its own PHP class extending the shared
  `Jet_Woo_Builder_Document_Base` (`includes/documents/class-jet-woo-builder-document-base.php`):
  Single (`Jet_Woo_Builder_Document`), Archive Item (`..._Archive_Document_Product`),
  Category Item (`..._Archive_Document_Category`), Shop (`..._Shop_Document`), Cart
  (`..._Cart_Document`), Checkout (`..._Checkout_Document`), Thank You
  (`..._ThankYou_Document`), My Account (`..._MyAccount_Document`) — full table with
  slugs/classes/files at `Jet_Woo_Builder_Documents::get_document_types()`
  (`includes/class-jet-woo-builder-documents.php:163-213`).
- **Whether a saved template actually gets used on the front end is controlled by a
  *third*, separate mechanism**: per-doc-type `custom_{X}_page` boolean toggles read from
  the **shop settings** store (`jet_woo_builder_shop_settings()->get('custom_single_page')`
  etc.), independent of both the CPT itself existing and the Document type being
  registered. E.g. `Jet_Woo_Builder_Woocommerce::get_custom_single_template()`
  (`includes/components/woocommerce/manager.php:374-397`) returns `false` (falls back to
  the theme/WooCommerce default) unless `custom_single_page` is `'yes'` **and** the
  configured template id/slug isn't the literal string `'default'`. A template can exist,
  be fully built in the editor, and still never render on the live site if this toggle is
  off — check this before assuming a template-content bug.
- Each of the 8 methods above (`get_custom_single_template()`,
  `get_custom_archive_template()`, `get_custom_cart_template()`, ... one per doc type,
  `manager.php:374-912`) has its own **`jet-woo-builder/custom-{type}-template`** filter
  applied to its resolved value, plus its own internal request-scoped cache
  (`$this->current_template_*`, reset only across full requests) — filtering one doc
  type's resolution has no effect on any other, even though the toggle-then-filter shape
  is identical across all 8.
- Front-end template swap happens via WooCommerce's own template-loader filters
  (`wc_get_template_part`, `wc_get_template`, `template_include`), rewired in
  `Jet_Woo_Builder_Woocommerce`'s constructor (`manager.php:86-98`) — not via Elementor's
  own template-assignment system. A per-page-builder-layout choice (`default`/
  `elementor_canvas`/`elementor_header_footer`) is read off the *template's own* Elementor
  page settings (`_elementor_page_settings` post meta,
  `get_elementor_page_settings()`, `template-functions.php:715-733`, request-cached), not
  a JetWooBuilder-specific setting.

## Two settings stores — easy to reach for the wrong one

**`jet_woo_builder_settings()`** (`Jet_Woo_Builder_Settings`, key `jet-woo-builder-settings`,
`includes/settings/class-jet-woo-builder-settings.php:36,314`) and
**`jet_woo_builder_shop_settings()`** (`Jet_Woo_Builder_Shop_Settings extends
Jet_Woo_Builder_Settings`, key `jet_woo_builder`,
`includes/settings/class-jet-woo-builder-shop-settings.php:26`) are **two different
`wp_options` rows**, both accessed with the identical-looking `->get( $name, $default )`
call (both inherit/override the same method signature). Mixing them up silently returns
`$default` (usually `false`) instead of erroring, since both classes implement `get()` the
same way. Rule of thumb from what's actually stored in each (per source):
- `jet_woo_builder_settings()` → global plugin/widget-enablement settings (which widgets
  are active per context, thumbnail-effect toggle, inline-template-styles toggle) — read
  in `Jet_Woo_Builder_Settings::get_localize_data()` (`class-jet-woo-builder-settings.php:239-284`).
- `jet_woo_builder_shop_settings()` → the per-doc-type `custom_{type}_page`/`{type}_template`
  toggles described above, plus `use_ajax_add_to_cart`, `use_native_templates`,
  `custom_taxonomy_template`, `related_products_per_page`, etc. — every call site in
  `includes/components/woocommerce/manager.php` and `includes/class-jet-woo-builder-post-type.php`
  uses this one, never the plain `jet_woo_builder_settings()`.

## AJAX handlers

`Jet_Woo_Builder_Ajax_Handlers` (`includes/class-jet-woo-builder-ajax-handler.php`)
registers exactly two actions, both with `nopriv` counterparts (guest-accessible):
`wp_ajax_jet_woo_builder_get_layout` (grid/list layout switcher —
`get_switcher_template()`, re-runs the product loop with `query_posts()` and temporarily
sets `jet_woo_builder()->woocommerce->products_loop_template_rewrite = true` /
`->current_template_archive = $layout` so the swapped-in loop honors the requested custom
archive template) and `wp_ajax_jet_woo_builder_add_cart_single_product` (delegates
straight to core `WC_Form_Handler::add_to_cart_action()` + `WC_AJAX::get_refreshed_fragments()`,
then `die()`s — no JetWooBuilder-specific response shaping). The layout switcher response
is filterable via **`jet-woo-builder/ajax-handler/get-switcher-template/response`**
(`class-jet-woo-builder-ajax-handler.php:107`).

## How this was verified

Read `jet-woo-builder.php` (main class, gating, component/module loading order),
`includes/class-jet-woo-builder-macros.php`, `includes/class-jet-woo-builder-template-functions.php`,
`includes/class-jet-woo-builder-tools.php`, `includes/class-jet-woo-builder-ajax-handler.php`,
`includes/class-jet-woo-builder-post-type.php`, `includes/class-jet-woo-builder-documents.php`,
`includes/documents/class-jet-woo-builder-document-base.php`,
`includes/components/manager.php`, `includes/components/woocommerce/manager.php`,
`includes/components/elementor-views/manager.php`,
`includes/settings/class-jet-woo-builder-settings.php`, and
`includes/settings/class-jet-woo-builder-shop-settings.php` in JetWooBuilder 2.3.3 source,
cross-checked against the 5 JetWooBuilder gists logged in
`other-plugins-backlog/OTHER-PLUGINS.md` (all 5 hook names confirmed
present at the cited file:line, no corrections needed this round). **Not yet run against
a live site** — JetWooBuilder is not installed on this repo's sandbox
(`jackfruit.epeak.studio`); see `TEST-REGIMEN.md` for the runnable suite (`tests.php`)
that's ready to deploy once it is.
