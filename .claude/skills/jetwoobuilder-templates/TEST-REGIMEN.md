# Test regimen: jetwoobuilder-templates

Validates claims in `SKILL.md`. **Live-verified 2026-07-16** against
`jackfruit.epeak.studio` (JetWooBuilder 2.3.3, Elementor/Elementor Pro active).
WooCommerce was not installed on this sandbox for the first run below; it is now active
(added later the same day), and a second run confirms the WC-dependent path this
unblocked — see Run log.

## Run log

**2026-07-16 (WooCommerce not yet installed)**: deployed as snippet id 39
(`AGENT-TEST-SUITE: jetwoobuilder-templates`, see `docs/code-snippets-rest-api.md`), ran
via `GET /agent-test/v1/suite/jetwoobuilder-templates` — **8/8 pass on first run**, no
fixes needed. WooCommerce itself was not active on this sandbox at the time; the suite
was written defensively enough (per its own docblock) to degrade to real, correct results
rather than fatal — `jwb-1`'s sub-object gate read as `null` (consistent with
`SKILL.md`'s Elementor+WooCommerce hard-gate claim), and `jwb-5` reported its "no test
product" fallback path instead of a false negative.

**2026-07-16 (WooCommerce now active)**: re-ran the same suite (unchanged) after
WooCommerce was installed and activated on this sandbox — **8/8 pass**, and this time
`jwb-5` exercised its real code path against an actual WC product (`has_test_product:
true`), confirming `jet-woo-builder/template-functions/product-price` genuinely reshapes
real price HTML rather than only being source-verified. `jwb-8`'s live WC-page-serving
edge case (does the shop-settings `custom_single_page` toggle actually swap what's served
on a real single-product request) remains open — see "Not yet automated" below; that
needs a full page-request round trip, not just the PHP-level accessor `jwb-8` checks.

## Automated coverage (`tests.php`, 8 assertions, `jwb-1` through `jwb-8`)

All 8 are written to degrade to a clear `FAIL` with the caught exception message (not a
suite-fatal) if JetWooBuilder/Elementor/WooCommerce aren't active — see each test's
try/catch in `tests.php`. Summary of what each checks:

- **jwb-1** — `jet_woo_builder()` singleton reachability, and that its sub-object
  properties (`->macros` used as the probe) are only populated after `init()`'s
  Elementor+WooCommerce gate passes (SKILL.md "Core architecture").
- **jwb-2** — `Jet_Woo_Builder_Macros::get_all()` includes both built-ins
  (`percentage_sale`, `numeric_sale`); a custom macro registered via
  `jet-woo-builder/macros/macros-list` round-trips through `do_macros()`, confirming the
  callback receives the raw `|`-suffix string as `$args` (not array-parsed).
- **jwb-3** — the macro regex (`[a-z_-]+` only) never matches an uppercase-keyed macro
  inside a string, even though it's a real registered key — confirms the silent-print
  gotcha.
- **jwb-4** — `jet_woo_builder_template_functions()` reachability; `get_product_price()`/
  `get_product_thumbnail()` return `null` (not fatal) when `global $product` isn't a
  `WC_Product`.
- **jwb-5** — `jet-woo-builder/template-functions/product-price` genuinely reshapes the
  HTML returned by `get_product_price()`, using a real product on the site (requires at
  least one `publish`-status WooCommerce product to exist; fails gracefully with a
  distinguishable "no test product" result rather than a false negative if none do).
- **jwb-6** — the two settings stores are separate `wp_options` rows: a value written
  directly into the shop-settings option (`jet_woo_builder`) is invisible via
  `jet_woo_builder_settings()->get()` (option `jet-woo-builder-settings`), and vice versa.
  Mutates then restores the real `jet_woo_builder` option within the same test.
- **jwb-7** — `get_document_types()` returns exactly the 8 documented keys, `single`'s
  slug is the bare `jet-woo-builder` CPT slug, and the other 7 are
  `jet-woo-builder-{suffix}` — confirms "one CPT distinguished by taxonomy/meta", not 8
  separate post types.
- **jwb-8** — flipping the shop-settings `custom_single_page` toggle changes what
  `get_custom_single_template()` returns for a configured (non-`'default'`) template id.
  Mutates then restores the real `jet_woo_builder` option within the same test. Note:
  `get_custom_single_template()` caches its result on `$this->current_template` for the
  rest of the request after first call, so this test only asserts the *enabled* path
  directly (returns the configured id) and asserts the *disabled* path indirectly (via
  the settings accessor re-reading `'no'`) rather than re-invoking the cached method —
  documented as a known limitation in the test's own `notes`, not a full round-trip.

## Not yet automated — needs a real editor/browser session

- **Elementor Document editing round-trip**: opening a JetWooBuilder template in the
  actual Elementor editor, confirming the `Jet_Woo_Builder_Document_Base` custom controls
  (Hide Title, Template Layout dropdown) appear and that `save_archive_templates()`
  persists `_jet_woo_builder_content` post meta correctly. Not safe/meaningful to
  automate via a single PHP request — needs a real editor session.
- **Front-end template-swap visual check**: with `custom_single_page`/`custom_shop_page`/
  etc. enabled and a real template built, confirm the WooCommerce template-loader filters
  (`wc_get_template`, `template_include`) actually serve the JetWooBuilder-rendered markup
  on a live single-product/archive/cart/checkout page — `jwb-8` only asserts the PHP-level
  gating logic, not that the swapped-in template renders correctly end-to-end in a
  browser.
- **AJAX handlers** (`wp_ajax_jet_woo_builder_get_layout`,
  `wp_ajax_jet_woo_builder_add_cart_single_product`) — both are real `admin-ajax.php`
  endpoints; testing them via a same-request PHP call would require faking `$_POST` and
  capturing `wp_send_json_success()`'s `wp_die()` exit, which the shared
  `agent_test_assert()`/`agent_test_run_suite()` harness isn't built to catch cleanly
  (unlike `Call_Hook_Action::do_action()`'s pattern noted in `HANDOFF.md`'s third lesson,
  these two don't expose a return-value-based entry point separate from the full AJAX
  request/exit cycle) — left as a manual curl-against-`admin-ajax.php` check instead.
- **Widget-level rendering** (the actual Elementor widget classes under
  `includes/widgets/*`) — out of scope for this skill entirely; this skill covers the
  macro engine, template-functions filters, Document/template system, and settings
  stores that widgets call into, not the ~40 individual widget classes themselves. A
  future `jetwoobuilder-widgets` skill (mirroring this repo's `jetelements-widgets` split)
  would be the place for that, if warranted.

## Deploying this suite once JetWooBuilder is installed on the sandbox

1. Install JetWooBuilder + confirm Elementor and WooCommerce are both active (hard
   requirement — `SKILL.md` "Core architecture" gating note).
2. Deploy `tests.php`'s contents as a new Code Snippets snippet named exactly
   `AGENT-TEST-SUITE: jetwoobuilder-templates`, active, per
   `docs/code-snippets-rest-api.md`.
3. `GET /wp-json/agent-test/v1/suite/jetwoobuilder-templates` (same bearer auth as every
   other suite in this repo).
4. Work through any `pass: false` per `docs/test-harness-guide.md`'s "Reading results"
   4-step procedure before touching `SKILL.md`.
5. Add the new snippet id to `docs/code-snippets-rest-api.md`'s inventory and this file's
   "Run log" section, and update `HANDOFF.md`'s status summary.
