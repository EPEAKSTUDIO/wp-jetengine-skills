---
name: jetblog-query-pipeline
description: Use when a JetBlog For Elementor widget (Smart Listing, Smart Tiles, Text Ticker) needs its post query customized or driven by JetEngine Query Builder, when debugging the Smart Listing "load more"/AJAX pagination endpoint, or when writing a `jet-blog/pre-query` / `jet-blog/*/query-args` callback. Captures verified behavior of JetBlog For Elementor 2.4.8.1 source (plugins/jet-blog/), live-verified 2026-07-16 against jackfruit.epeak.studio (5/5 tests.php assertions passing after 3 test-only fixes — see TEST-REGIMEN.md).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetBlog Query & AJAX Pipeline

Verified facts about how JetBlog's three query-driven widgets (Smart Listing, Smart
Tiles, Text Ticker) build a `WP_Query`, how that pipeline can be replaced entirely
(custom JSON query, or a JetEngine Query Builder query), and how the Smart Listing
"load more" AJAX request is authenticated. Confirmed against JetBlog For Elementor
2.4.8.1 source, `plugins/jet-blog/`. **No official Crocoblock developer-documentation
exists for JetBlog** — checked `github.com/Crocoblock/developer-documentation` directly
(2026-07-16): it has numbered doc folders for JetEngine, JetSmartFilters,
JetFormBuilder, JetPopup, JetBooking, JetWooProductGallery, and JetCompareWishlist, but
none for JetBlog. Everything below is source-verified only.

## The three-way query pipeline, same shape in all three widgets

Smart Listing (`includes/addons/jet-blog-smart-listing.php`), Smart Tiles
(`jet-blog-smart-tiles.php`), and Text Ticker (`jet-blog-text-ticker.php`) each build
their query the same way, e.g. Smart Listing's `_get_posts()` (`:6746-6789`):

```php
$query_args = ( 'true' === $settings['use_custom_query'] )
    ? $this->get_custom_query_args( $settings )   // raw JSON control, see below
    : $this->get_default_query_args( $settings ); // built from the widget's own controls

$query_args = apply_filters( 'jet-blog/smart-listing/query-args', $query_args, $this ); // per-widget name
$query_args = $this->sanitize_query_args( $query_args );                                // see Base, below

$posts = apply_filters( 'jet-blog/pre-query', false, $settings, $query_args, $this );   // 4 args, shared name

if ( false === $posts ) {
    $query = new \WP_Query( $query_args );
    $posts = ! empty( $query->posts ) ? $query->posts : array();
    // ...
}
```

**`jet-blog/pre-query` is the one shared, cross-widget hook** (same literal name in
all three files: `jet-blog-smart-listing.php:6782`, `jet-blog-smart-tiles.php:2776`,
`jet-blog-text-ticker.php:1979`) — return anything other than `false` from it and the
widget's own `new WP_Query(...)` call never runs; your return value is used as the
posts array directly. **The per-widget `*-query-args` filter names are NOT shared** —
`jet-blog/smart-listing/query-args`, `jet-blog/smart-tiles/query-args`,
`jet-blog/text-ticker/query-args` are three separate filter names, each only firing
for its own widget.

`Jet_Blog_Base::sanitize_query_args()` (`includes/base/class-jet-blog-base.php:1291-1325`)
runs on every widget's query args right before `pre-query` fires (both the default-built
and the custom-JSON path) and strips/rejects, regardless of what a filter callback or an
authenticated user's custom JSON put there: `post_status` values `private`/`draft`/
`trash`/`auto-draft`/`inherit`/`any`, any `post_password`/`perm`/`has_password` key
entirely, and `post_type` values `revision`/`nav_menu_item`/`custom_css`/
`customize_changeset`/`oembed_cache`/`user_request`. **This runs unconditionally on both
the default query and the "Custom Query" JSON path** — a widget's `custom_query` control
(raw editor-authored JSON, decoded via `get_custom_query_args()`,
`jet-blog-smart-listing.php:6723-6739`) cannot be used to query trashed/private posts or
password-protected content even though the editor lets you type an arbitrary WP_Query
args array.

## `use_custom_query` has two independent meanings depending on which extension is active

The Elementor "Use Custom Query" switcher control (`use_custom_query` = `'true'`) means
different things depending on whether `Jet_Blog_Query_Builder` (below) is loaded:

- **JetEngine not active / Query Builder extension not loaded**: `use_custom_query =
  'true'` reveals only the `custom_query` raw-JSON textarea control — `get_custom_query_args()`
  is used, `json_decode( wp_unslash( $settings['custom_query'] ), true )`.
- **JetEngine active** (`includes/class-jet-blog-query-builder.php`, only `require`d
  from `jet-blog.php:163-166` when `function_exists('jet_engine')`): the *same*
  `use_custom_query` switcher additionally reveals a `query_builder_id` SELECT control
  populated from `\Jet_Engine\Query_Builder\Manager::instance()->get_queries()`
  (filtered to `query_type === 'posts'` only, `get_query_builder_options()`,
  `:108-131`), and `Jet_Blog_Query_Builder::maybe_do_query()` is what actually hooks
  `jet-blog/pre-query` (`:21`) to run the Query Builder query instead of `WP_Query`:

```php
// includes/class-jet-blog-query-builder.php:41-105 (paraphrased for the parts that matter)
public function maybe_do_query( $result, $settings, $query_args, $widget ) {
    if ( empty( $settings['use_custom_query'] ) || empty( $settings['query_builder_id'] )
        || ! empty( $settings['is_archive_template'] ) ) {
        return $result; // falls through to the widget's own WP_Query
    }
    $query = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id( absint( $settings['query_builder_id'] ) );
    if ( ! $query ) {
        return $result;
    }
    $query->setup_query();
    // ... optionally replaces the tax_query row from a `jet_request_data[term]` request value,
    // forces final_query['posts_per_page']/['paged']/['page'] from the widget's own
    // posts_columns*posts_rows math or the incoming AJAX request ...
    return $query->get_items();
}
```

**Gotcha**: if `is_archive_template` is truthy on the widget's settings, the Query
Builder path is skipped entirely even with a `query_builder_id` set — archive-template
mode always falls through to `WP_Query`/the main loop (see `_get_posts()`'s own
archive-template branch, `jet-blog-smart-listing.php:6750-6763`, which never even calls
`get_default_query_args()`/`get_custom_query_args()` and instead uses `$wp_query->posts`
directly, or a plain `get_posts()` call under Elementor's own template-preview mode).

`Jet_Blog_Query_Builder` also hooks two more filters, both narrowly scoped:
- `jet-blog/query-controls` (2 args: `$widget`, `$has_custom_query` bool) —
  `register_query_controls()` (`:133-188`) is where the `query_builder_id` SELECT
  control itself gets added to a widget; fired via `do_action('jet-blog/query-controls',
  $this, true )` / `do_action('jet-blog/query-controls', $this, false )` from each
  widget's `register_controls()` (Smart Listing/Smart Tiles pass `true`, Text Ticker
  passes `false` — the boolean controls whether the plain `use_custom_query` switcher
  control itself also needs to be added, since Smart Listing/Smart Tiles already have
  their own).
- `jet-blog/smart-listing/exported-options` — `export_smart_list_options()` (`:32-35`)
  appends `'query_builder_id'` to the list of settings keys the Smart Listing widget
  exports to its front-end `data-settings` attribute (see the signature system below —
  this filter is what lets `query_builder_id` survive into the signed payload the AJAX
  endpoint re-validates against).

## Smart Listing's AJAX "load more" endpoint: HMAC-signed settings, not a nonce

`Jet_Blog_Ajax_Handlers::init()` (`includes/class-jet-blog-ajax-handlers.php:33-43`)
only registers its `wp_ajax_jet_blog_smart_listing_get_posts` /
`wp_ajax_nopriv_jet_blog_smart_listing_get_posts` actions **when the current request's
`$_GET['jet_blog_ajax']` is non-empty and `$_REQUEST['action']` is set** — this is a
front-end-URL AJAX bridge (`setup_front_referrer()`, hooked on `parse_request`,
`:51-66`) that lets JetBlog fire `wp_ajax_{action}`/`wp_ajax_nopriv_{action}` from a
plain front-end permalink URL (built by `Jet_Blog_Integration::get_ajax_url()`,
`includes/class-jet-blog-integration.php:146-165`, which appends
`?nocache={time}&jet_blog_ajax=1` to the current page URL) instead of routing through
`admin-ajax.php` — this preserves the page's own URL/rewrite context for the query
(needed since a Smart Listing "load more" request must resolve the *same* archive/query
context as the page it's embedded on). **This is a routing mechanism, not a permission
bypass**: WordPress's normal `wp_ajax_*`/`wp_ajax_nopriv_*` action-name dispatch and
each handler's own checks still apply.

The actual handler, `get_listing_posts()` (`:73-117`), does not use a nonce — it uses a
**server-side HMAC-SHA256 signature over the widget's own exported settings**,
generated when the widget first rendered and echoed back by the request:

```php
// jet-blog-smart-listing.php — creating the signature at render time (_export_settings(), :6236-6249)
$result = array(); // only keys from get_exported_settings_keys() (a fixed allow-list, :6114-6229,
                    // e.g. post_type/post_ids/order/order_by/use_custom_query/custom_query/
                    // posts_offset/is_archive_template/query_builder_id — NOT arbitrary settings)
$result['signature'] = $this->create_settings_signature( $result ); // hash_hmac('sha256', json, wp_salt('auth'))
echo esc_attr( wp_json_encode( $result ) ); // → the widget's data-settings HTML attribute

// get_listing_posts() re-validates the *request's* copy of those same settings ( :90 )
if ( ! is_array( $settings ) || ! $widget->validate_settings_signature( $settings ) ) {
    wp_send_json_error( array( 'message' => ... ), 403 );
}
```

`validate_settings_signature()` (`:6269-6282`) recomputes the same HMAC over a
normalized copy of the *request's* settings (`normalize_settings_for_signature()` /
`normalize_signature_value()`, `:6290-6344` — stringifies bools/numbers, `ksort()`s
associative sub-arrays for stable ordering) and compares with `hash_equals()`. **The
practical effect: an attacker can't submit `jet_widget_settings` with a different
`post_type`/`post_ids`/`custom_query`/`query_builder_id` than what the widget actually
rendered with** — any tampering changes the HMAC input and fails the signature check
(403). This is keyed off `wp_salt('auth')` (not a nonce, so it isn't tied to a
particular logged-in user or short expiry — it's stable for as long as `AUTH_KEY`/
`AUTH_SALT` don't rotate).

**After the signature check passes**, the handler still lets the AJAX request override
`posts_per_page`/`offset` freely via `set_posts_number()` (`:119-138`, hooked onto
`jet-blog/smart-listing/query-args` only for the duration of this one request) — reading
straight from `$_POST['jet_request_data']['posts_per_page']` and
`$_REQUEST['jet_widget_settings']['posts_offset']` with no signature check on those two
values specifically. This is intentional (pagination has to vary per-request) but means
the signature only pins down *which posts/query* a request can target, not the
paging window into it.

## The `custom_query` JSON control's request-data merge

Both `get_default_query_args()` and `get_custom_query_args()` end with the same
conditional merge (`jet-blog-smart-listing.php:6711-6713`, `:6733-6735`):

```php
if ( isset( $_REQUEST['jet_request_data'] ) ) {
    $query_args = array_merge( $query_args, $this->_add_request_data() );
}
```

i.e. whatever `_add_request_data()` returns always wins over both the widget-authored
default args *and* the raw custom-JSON args on any request carrying a
`jet_request_data` key — this is the same mechanism the AJAX "load more" handler's
`posts_offset`/`paged` overrides ultimately ride on, and also what feeds the
filter-by-taxonomy-term interaction (`jet_request_data[term]`) the Query Builder
integration reads in `maybe_do_query()` above.

## Gotchas

- `jet-blog/pre-query`'s 4-arg signature (`$result, $settings, $query_args, $widget`) is
  identical across all three widgets — a single shared callback keyed on
  `$widget->get_name()` (`'jet-blog-smart-listing'` / `'jet-blog-smart-tiles'` /
  `'jet-blog-text-ticker'`) can serve all three instead of duplicating per-widget hooks.
- `sanitize_query_args()` runs on the args array as a whole (`post_status`/`post_type`
  scalar-or-array-aware) but does **not** validate `meta_query`/`tax_query` sub-arrays —
  a filter callback on `jet-blog/*/query-args` that injects a raw `meta_query` clause is
  not further sanitized before `WP_Query` runs (still subject to `WP_Query`'s own
  argument handling, just not this plugin's extra guard).
- `Jet_Blog_Query_Builder`'s `maybe_do_query()` silently no-ops (`return $result`,
  falling through to the widget's own `WP_Query`) if `get_query_by_id()` returns falsy —
  a stale/deleted Query Builder id in a widget's saved settings degrades to the default
  query rather than erroring visibly.
- Not yet traced: `jet-blog/query-conditions` (`Jet_Blog_Query_Builder::register_query_conditions()`,
  adds `'use_custom_query!' => 'true'` to a conditions array returned from Text Ticker's
  `get_query_conditions()`) — only one call site read (`jet-blog-text-ticker.php:49-51`),
  Smart Listing/Smart Tiles don't appear to call an equivalent method, not confirmed why.

## How this was verified

Read `jet-blog.php` (`init()`'s conditional `require` of the Query Builder integration),
`includes/class-jet-blog-query-builder.php`, `includes/base/class-jet-blog-base.php`
(`sanitize_query_args()`), `includes/class-jet-blog-ajax-handlers.php`, and the query/
signature-related sections of `includes/addons/jet-blog-smart-listing.php` (`_get_posts()`,
`get_default_query_args()`, `get_custom_query_args()`, `_export_settings()`,
`create_settings_signature()`/`validate_settings_signature()`/
`normalize_settings_for_signature()`) and `jet-blog-smart-tiles.php`/
`jet-blog-text-ticker.php` (confirming the shared `jet-blog/pre-query` call site and the
per-widget `*-query-args` filter names) directly in JetBlog For Elementor 2.4.8.1 source
(`plugins/jet-blog/`), all by file:line citation. Cross-checked against
`github.com/Crocoblock/developer-documentation` (2026-07-16): confirmed no JetBlog
section exists there (its top-level tree only covers JetEngine, JetSmartFilters,
JetFormBuilder, JetPopup, JetBooking, JetWooProductGallery, and JetCompareWishlist), so
no official doc could corroborate or correct anything above — this skill is source-only.
Not yet verified against a running site — see `TEST-REGIMEN.md`; `tests.php` has
reachability/source-presence smoke tests ready to deploy.
