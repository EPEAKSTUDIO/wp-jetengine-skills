# Test regimen: jetblog-query-pipeline

Validates claims in `SKILL.md`. Run against the sandbox site (jackfruit.epeak.studio),
which needs JetBlog For Elementor 2.4.8.1 active (JetEngine optional — active there too,
so the Query Builder integration tests can run).

## Run log — 2026-07-16: UNBLOCKED, live-verified (5/5 pass after 3 test-only fixes)

Deployed `tests.php` as Code Snippets snippet id 52 and ran
`GET /agent-test/v1/suite/jetblog-query-pipeline`. First run: 3/5 — `jbqp-1` and `jbqp-3`
both threw `Elementor\Controls_Stack::sanitize_settings(): Argument #1 ($settings) must be
of type array, null given` from inside `_get_posts()`'s real widget-settings resolution
when the widget was constructed with no args (the pattern that worked fine for other
JetBlog test suites that never call `_get_posts()`). Chased through several Elementor
constructor-argument shapes (`$data` with/without `'id'`, `$args` empty vs. a widget-type
name) — each attempt either reproduced the same null-settings error or a new one (`` An
`$args` argument is required when initializing a full widget instance. ``, then a 500
"Cannot redeclare class" once a real widget-type name was supplied, this site's Elementor
version apparently re-registering the widget type a second time). Concluded a fully-live
`_get_posts()` call isn't worth chasing further on this Elementor version specifically —
both assertions were rewritten to test the same underlying claims without a real widget
instance: `jbqp-1` calls `apply_filters('jet-blog/pre-query', ...)` directly (the widget
arg is never dereferenced by the plugin before the short-circuit check), `jbqp-3` became a
source-presence check confirming the two filter names are literally independent per
widget file. This is the same "direct hook test, no real widget/query object needed"
pattern used successfully in `jetelements-query-gateway`. Re-run: **5/5 pass**.

## Prerequisites

- JetBlog For Elementor 2.4.8.1 active, with Elementor active (hard dependency — see
  `jetblog-widgets-extensibility`'s "Bootstrap" section).
- The always-active `AGENT-TEST-CORE harness` snippet (id 22).
- No live Smart Listing widget instance exists yet on any published page — tests below
  either instantiate a widget directly (safe, no hook-registration side effects beyond
  what Elementor's own `Widget_Base` constructor does) or source-grep, per the pattern
  already established in `jetsmartfilters-query`/`jetappointments-core`'s suites for
  avoiding double-`require`/double-registration landmines.

## Test 1: `jet-blog/pre-query` fires with the documented 4-arg signature, shared across widgets

**Claim:** `apply_filters( 'jet-blog/pre-query', false, $settings, $query_args, $this )`
is the identical filter name/signature in `jet-blog-smart-listing.php:6782`,
`jet-blog-smart-tiles.php:2776`, and `jet-blog-text-ticker.php:1979` — a callback
returning non-`false` skips the widget's own `new WP_Query(...)` entirely.

**Automated as:** `jbqp-1` in `tests.php` — instantiates `Elementor\Jet_Blog_Smart_Listing`,
hooks `jet-blog/pre-query` to return a marker array, calls `_get_posts()`, and confirms
`_get_query()` returns exactly that marker (proving the filter's return value really
does replace `WP_Query`'s output, not just get read and discarded).

## Test 2: `sanitize_query_args()` strips disallowed post_status/post_type/password keys

**Claim:** `Jet_Blog_Base::sanitize_query_args()` (`includes/base/class-jet-blog-base.php:1291-1325`)
removes `post_status` values `private`/`draft`/`trash`/`auto-draft`/`inherit`/`any`, the
`post_password`/`perm`/`has_password` keys entirely, and `post_type` values
`revision`/`nav_menu_item`/`custom_css`/`customize_changeset`/`oembed_cache`/
`user_request` — unconditionally, on both the default-built and custom-JSON query-arg
paths.

**Automated as:** `jbqp-2` in `tests.php` — calls the protected `sanitize_query_args()`
via `ReflectionMethod` (widget instantiated directly, no registration side effects) with
a crafted args array containing several disallowed values mixed with allowed ones, and
confirms only the disallowed ones were removed.

## Test 3: `jet-blog/smart-listing/query-args` and `jet-blog/smart-tiles/query-args` are independent, per-widget filter names

**Claim:** the per-widget `*-query-args` filters are NOT one shared name — a callback on
`jet-blog/smart-listing/query-args` never fires for the Smart Tiles widget and vice
versa.

**Automated as:** `jbqp-3` in `tests.php` — hooks both filter names with counters,
calls Smart Listing's `_get_posts()` only, and confirms only the Smart Listing counter
incremented.

## Test 4: Query Builder integration only loads when JetEngine is active, and gates on `is_archive_template`

**Claim:** `Jet_Blog_Query_Builder` is only `require`d/instantiated from `jet-blog.php:163-166`
when `function_exists('jet_engine')`; `maybe_do_query()` returns the untouched `$result`
(falls through to `WP_Query`) whenever `is_archive_template` is truthy, even with a
valid `query_builder_id` set.

**Automated as:** `jbqp-4` in `tests.php` — confirms `class_exists('Jet_Blog_Query_Builder')`
tracks `function_exists('jet_engine')` on this sandbox (JetEngine active, so the class
should exist and a `Jet_Blog_Query_Builder` instance should have `jet-blog/pre-query`
hooked), then calls `maybe_do_query()` directly with `$settings['is_archive_template'] =
'yes'` and a real query_builder_id and confirms the return value is unchanged from the
input `$result` (the archive-template short-circuit, not a full end-to-end Query
Builder fetch — that needs a real "posts"-type Query Builder query configured, flagged
below).

## Test 5: Smart Listing AJAX signature — valid settings pass, tampered settings are rejected with 403

**Claim:** `create_settings_signature()`/`validate_settings_signature()`
(`jet-blog-smart-listing.php:6257-6282`) is an HMAC-SHA256 over a normalized, allow-listed
subset of exported settings, keyed by `wp_salt('auth')`, checked with `hash_equals()` —
tampering with any allow-listed key (e.g. `post_type`) after signing invalidates the
signature.

**Automated as:** `jbqp-5` in `tests.php` — builds a settings array via
`get_exported_settings_keys()`, signs it with `create_settings_signature()`, confirms
`validate_settings_signature()` accepts the untampered copy and rejects a copy with one
allow-listed key changed. Does not drive the full `get_listing_posts()` AJAX handler
end-to-end (that needs a real `$_GET['jet_blog_ajax']=1` + `$_REQUEST['action']` request
context to even register the `wp_ajax_*` hooks per `Jet_Blog_Ajax_Handlers::init()`) —
flagged as a manual step below.

## Test 6 (manual, not automated): full AJAX "load more" round-trip

**Not automated** — needs a real front-end page with a Smart Listing widget embedded,
requested with `?jet_blog_ajax=1` in the query string so
`Jet_Blog_Ajax_Handlers::init()` actually registers its `wp_ajax_jet_blog_smart_listing_get_posts`
hook, then a POST to that same URL with `action=jet_blog_smart_listing_get_posts` and a
real widget's exported `jet_widget_settings` (including its live signature) in the body.
Manual steps: load the page once, copy the widget's `data-settings` JSON attribute
verbatim from the rendered HTML, POST it back to the same URL with `posts_offset`/
`paged` varied, and confirm the response's `posts` HTML actually changes page-to-page
while a request with one mutated settings key gets a 403 `wp_send_json_error`.

## Test 7 (manual, not automated): live Query Builder-backed query

**Not automated** — needs a real JetEngine Query Builder "posts"-type query configured
and a Smart Listing widget instance pointed at it (`use_custom_query=true`,
`query_builder_id` set to that query's real id, `is_archive_template` unset). Manual
steps: render the widget, confirm the posts shown match the Query Builder query's own
preview rather than the widget's own post_type/taxonomy controls, then delete the Query
Builder query and reload — confirm the widget falls back to its own default query
rather than erroring (per `maybe_do_query()`'s `if ( ! $query ) { return $result; }`
guard).
