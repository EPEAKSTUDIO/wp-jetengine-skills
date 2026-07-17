---
name: jetblog-widgets-extensibility
description: Use when registering/gating a JetBlog For Elementor widget (Smart Listing, Smart Tiles, Text Ticker, Video Playlist, Posts Navigation/Pagination), hooking `Jet_Blog_Base`'s control-visibility system (per-widget include/exclude-controls, the widgets_load_level style gate), adding a custom REST endpoint under JetBlog's own `jet-blog-api/v1` namespace, or debugging YouTube/Vimeo video-data fetching in the Video Playlist widget. Captures verified behavior of JetBlog For Elementor 2.4.8.1 source (plugins/jet-blog/), live-verified 2026-07-16 against jackfruit.epeak.studio (6/6 tests.php assertions passing after 2 test-only fixes — see TEST-REGIMEN.md).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetBlog Widgets, Extensibility & REST

Verified facts about JetBlog's widget registration/bootstrap, the shared
`Jet_Blog_Base` extension points every widget inherits, its own (non-JetEngine) REST
namespace, and the YouTube/Vimeo video-data layer behind the Video Playlist widget.
Confirmed against JetBlog For Elementor 2.4.8.1 source, `plugins/jet-blog/`. Query-args/
AJAX-pipeline internals live in the sibling `jetblog-query-pipeline` skill. **No official
Crocoblock developer-documentation exists for JetBlog** — checked
`github.com/Crocoblock/developer-documentation` directly (2026-07-16): it has numbered
doc folders for JetEngine, JetSmartFilters, JetFormBuilder, JetPopup, JetBooking,
JetWooProductGallery, and JetCompareWishlist, but none for JetBlog. Everything below is
source-verified only.

## Bootstrap: `jet_blog()` is a plain lazy singleton, hard-gated on Elementor being loaded

`jet_blog()` (`jet-blog.php:388-390`) returns `Jet_Blog::get_instance()`, a classic
public-constructor-but-effectively-singleton (`get_instance()` lazily creates
`self::$instance`, `:370-376`) instantiated unconditionally at the bottom of the main
file (`:393`). Its `init()` (hooked on `init` at priority `-999`, `:90`) is a hard gate:

```php
if ( ! $this->has_elementor() ) {           // did_action( 'elementor/loaded' ), :252-254
    add_action( 'admin_notices', array( $this, 'required_plugins_notice' ) );
    return;                                  // nothing else in the plugin loads at all
}
$this->load_files();                         // requires the 8 core includes + rest-api files
jet_blog_assets()->init();
jet_blog_integration()->init();              // Elementor widget/control registration, below
jet_blog_video_data()->init();
jet_blog_ajax_handlers()->init();
if ( is_admin() ) { /* DB upgrader, Jet_Blog\Settings */ }
new \Jet_Blog\Rest_Api();
if ( function_exists( 'jet_engine' ) ) {     // OPTIONAL, not a hard dependency
    require $this->plugin_path( 'includes/class-jet-blog-query-builder.php' );
    new Jet_Blog_Query_Builder();
}
do_action( 'jet-blog/init', $this );          // fires once init has fully run, 1 arg: the Jet_Blog instance
```

(`jet-blog.php:133-169`). **`has_elementor()` checks `did_action('elementor/loaded')`,
not `class_exists()`** — since JetBlog's own `init()` runs at `init` priority `-999`
(very early), this ordering only works because Elementor's `elementor/loaded` action
itself fires even earlier (on `plugins_loaded`); don't assume a plain `class_exists(
'Elementor\Plugin' )` check would behave the same on every request order. Unlike Jet
Appointments Booking's hard dependency on JetEngine (see `jetappointments-core`),
**JetEngine is optional for JetBlog** — only the Query Builder integration
(`jetblog-query-pipeline`) is gated on it; every widget itself works without JetEngine
installed.

## Widget registration: per-widget on/off toggle, category name is "cherry" not "jet-blog"

`Jet_Blog_Integration::register_addons()` (`includes/class-jet-blog-integration.php:249-264`),
hooked on `elementor/widgets/register` (Elementor ≥3.5) or the legacy
`elementor/widgets/widgets_registered`, globs every file in `includes/addons/*.php` and
registers each one **unless** the site's `jet_blog_settings()->get('avaliable_widgets')`
option explicitly disables that file's slug:

```php
$available_widgets = jet_blog_settings()->get( 'avaliable_widgets' ); // keyed by addon filename slug, e.g. 'jet-blog-smart-listing'
foreach ( glob( ... 'includes/addons/' . '*.php' ) as $file ) {
    $slug    = basename( $file, '.php' );
    $enabled = isset( $available_widgets[ $slug ] ) ? $available_widgets[ $slug ] : false;
    if ( filter_var( $enabled, FILTER_VALIDATE_BOOLEAN ) || ! $available_widgets ) {
        $this->register_addon( $file, $widgets_manager );
    }
}
```

**Gotcha**: `! $available_widgets` (the option never having been saved at all) is what
makes every widget register by default. The moment the JetBlog settings screen is
saved once (`generate_frontend_config_data()`,
`includes/class-jet-blog-settings.php:86-165`, defaults every discovered addon to
`'true'`), `$available_widgets` becomes a real non-empty array — from then on, any addon
slug **missing** from that array (e.g. a brand-new widget added by a plugin update,
before the settings screen has been re-saved) defaults to `$enabled = false` and won't
register until the site owner revisits the settings screen. `register_addon()`
(`:295-311`) derives the expected class name mechanically from the filename
(`jet-blog-smart-listing.php` → `Elementor\Jet_Blog_Smart_Listing`) — a custom widget
file dropped into `includes/addons/` without going through
`jet-blog/init` externally would need to follow this same naming convention to
autoload, since nothing else scans that directory.

Every JetBlog widget registers into an Elementor category literally named `'cherry'`
(`register_category()`, `:318-330`, hooked on `elementor/elements/categories_registered`)
but **labeled "JetElements" in the Elementor UI** (`'title' => __('JetElements', ...)`) —
a historical/shared-framework naming mismatch, not a bug, but confusing if you're
grepping for where a "JetElements" category label comes from and only find `'cherry'`
as the literal category id in this plugin's source.

## `Jet_Blog_Base` (`includes/base/class-jet-blog-base.php`): the real per-widget extension points

Every widget (`Elementor\Jet_Blog_Smart_Listing`, etc.) extends this abstract class,
namespaced `Elementor` (not `Jet_Blog`). Two real, independent gating mechanisms control
which of a widget's *style* controls actually get added — both apply only to controls
added via the underscore-prefixed wrappers (`_add_control()`, `_add_responsive_control()`,
`_add_group_control()`, `_start_controls_section()`/`_start_controls_tabs()`/
`_start_controls_tab()`), **not** to controls added with Elementor's own plain
`add_control()` etc., which always run regardless of either mechanism:

1. **Global `widgets_load_level` setting** (`jet_blog_settings()->get('widgets_load_level',
   100)`, one of `0`/`25`/`50`/`75`/`100` — "None"/"Low"/"Medium"/"Advanced"/"Full" in the
   settings UI) is read once into `$this->_load_level` in the constructor (`:22`).
   `_is_visible_control( $control_id, $load_level )` (`:1017-1026`) rejects any control
   whose own declared `$load_level` argument exceeds the site's configured
   `widgets_load_level` — this is a site-wide "how much of this widget's styling UI do
   you want to see" dial, independent of any single widget.
2. **Per-widget include/exclude filters**, resolved once per widget instance in the
   constructor (`:26-27`):
   ```php
   $this->_include_controls = apply_filters( "jet-blog/editor/{$widget_name}/include-controls", array(), $widget_name, $this );
   $this->_exclude_controls = apply_filters( "jet-blog/editor/{$widget_name}/exclude-controls", array(), $widget_name, $this );
   ```
   e.g. `jet-blog/editor/jet-blog-smart-listing/include-controls`. A control id present
   in `_include_controls` is **always** added regardless of `widgets_load_level`
   (`_is_visible_control()`'s condition is `( level-too-low OR excluded ) AND NOT
   included`) — the correct way to force one specific style control back on for a site
   that otherwise runs at a low `widgets_load_level`, instead of raising the global dial.

`_render_meta()` (`:384-502`, used by any widget's "meta fields" repeater UI) resolves
each meta row's display callback through a **fixed allow-list**,
`jet_blog_tools()->allowed_meta_callbacks()` (`includes/class-jet-blog-tools.php:536-548`
— `get_permalink`/`get_the_title`/`wp_get_attachment_url`/`wp_get_attachment_image`/
`date`/`date_i18n`, plus a blank "Clean" option), itself filterable via
`jet-blog/base/meta-callbacks` — this is the real extension point for adding a custom
formatter function selectable from a meta repeater's "Prepare meta value with callback"
dropdown; the callback is still gated by `is_callable()` at render time (`:458`), so
registering a name here that isn't an actually-callable global function/static method is
silently ignored (falls back to the raw meta value, `:461`).

## REST API: JetBlog has its own namespace, `jet-blog-api/v1` — not routed through JetEngine

Unlike Jet Appointments Booking (which piggybacks on JetEngine's REST manager, see
`jetappointments-core`), `Jet_Blog\Rest_Api` (`includes/rest-api/rest-api.php`) is a
fully independent REST controller, hooked directly on `rest_api_init`
(`:52-54`). Only one endpoint ships by default:

- `POST /wp-json/jet-blog-api/v1/plugin-settings` (`Jet_Blog\Endpoints\Plugin_Settings`,
  `includes/rest-api/endpoints/plugin-settings.php`) — merges every key of the request
  body into the `jet-blog-settings` option (`get_option(jet_blog_settings()->key)`,
  scalar values passed through `esc_attr()`, array values kept as-is) and
  `update_option()`s the result. This is what actually persists the JetBlog settings
  screen (its `settingsApiUrl` is hardcoded to this exact route,
  `includes/class-jet-blog-settings.php:123`) — the admin UI is a Vue app POSTing here,
  not a classic `options.php` form.

**The endpoint base class's default `permission_callback()` requires
`current_user_can('manage_options')`** (`includes/rest-api/endpoints/base.php:40-42`) —
`Plugin_Settings` doesn't override it, so this endpoint is admin-only despite having no
nonce/capability check of its own in `callback()`.

Registering a new endpoint: `Rest_Api::init_endpoints()` (`rest-api.php:61-69`) fires
`do_action( 'jet-blog/rest/init-endpoints', $this )` (1 arg, the `Rest_Api` manager
instance) after registering the built-in `Plugin_Settings` endpoint:

```php
add_action( 'jet-blog/rest/init-endpoints', function( $api_manager ) {
    $api_manager->register_endpoint( new My_Custom_Endpoint() ); // must extend Jet_Blog\Endpoints\Base
} );
```

A custom endpoint class must implement `get_name()` and `callback( $request )`
(both abstract on `Base`); `get_method()` defaults to `'GET'`,
`get_query_params()` defaults to `''` (no URL params), and — importantly —
`permission_callback()` defaults to requiring `manage_options`, so a public-facing
custom endpoint must explicitly override it, not just leave the base class default.
`Rest_Api::get_route( $endpoint, $full = false )` (`:122-132`) and
`get_endpoints_urls()` (`:103-115`) are the helpers for resolving a registered
endpoint's URL from PHP rather than hardcoding `jet-blog-api/v1/...` strings.

## Video Playlist's video-data layer: transient-cached, silently empty without a YouTube API key

`Jet_Blog_Video_Data` (`jet_blog_video_data()`,
`includes/class-jet-blog-video-data.php`) backs the Video Playlist widget's per-video
metadata and "pull all videos from a channel/playlist/user/handle" list-building:

- `get( $url, $caching = true )` (`:100-123`) resolves single-video metadata via WP core's
  own oEmbed client (`_wp_oembed_get_object()->get_data( $url )`), then supplements
  `duration`/`publication_date` with a direct YouTube Data API v3 call
  (`get_youtube_data()`, `:327-381`) or a legacy `vimeo.com/api/v2/video/{id}.json` call
  (`get_vimeo_data()`, `:576-601`, no API key needed for Vimeo). **The YouTube half of
  every method silently returns `array()` (or the oEmbed-only partial data) whenever
  `jet_blog_settings()->get('youtube_api_key')` is empty** — `get_youtube_data()` (`:329-331`),
  `get_youtube_playlist_video_list()` (`:392-394`), `get_youtube_channelid_video_list()`
  (`:459-461`), `get_youtube_channel_video_list()` (`:515-517`) all short-circuit this
  way with no exception/log entry a template would surface — a channel/playlist-driven
  Video Playlist widget with no YouTube API key configured renders as if the source had
  zero videos, not as an error state. `$this->api_error` (public property) does get
  populated on an actual API error response, but nothing in the shipped templates reads
  it — a custom template override is the only way to surface it to an admin.
- `get_video_source_properties( $url )` (`:192-207`) detects 4 YouTube source shapes by
  regex (`yt_channel`/`yt_user`/`yt_playlist`/`yt_videos` — the last matching a
  `youtube.com/@handle` URL) and is what `get_video_list_from_source()` (`:134-183`)
  dispatches on for the "pull N videos from this URL" widget setting.
- Every fetch is transient-cached, keyed by `md5(url)` plus the installed plugin version
  (`transient_key()`/`k()`, `:624-626,708-710` — so a plugin update invalidates all
  caches automatically, no manual cache-bust needed) with a configurable TTL:
  `get_ttl( $type )` (`:609-615`) reads `jet_blog_settings()->get('video_cache_ttl_hours',
  6)`, clamps it to 1–168 hours, then applies **two separate filters** depending on
  cache type — `jet-blog/video-cache/ttl-hours` for single-video metadata,
  `jet-blog/list-cache/ttl-hours` for channel/playlist video lists. These are the two
  real hooks to shorten/lengthen caching independently (e.g. a fast-changing playlist vs.
  rarely-changing per-video metadata) instead of one blanket TTL setting.

## Gotchas

- `Jet_Blog_Integration::add_controls()` (`:206-218`) only registers the
  `Jet_Blog_Group_Control_Box_Style` group control **if the file
  `includes/controls/groups/class-jet_blog_group_control_box_style.php` exists on disk**
  (`include_control()`, `:226-241` — silently returns `false` and skips registration
  otherwise, no warning) — a custom build stripping unused control files would silently
  lose this control rather than fatal.
- `has_elementor()`'s early-return means **nothing** past that check runs on a request
  where Elementor hasn't loaded yet — not just widget registration, but also the REST
  API (`new \Jet_Blog\Rest_Api()`), the AJAX handlers, and the Query Builder
  integration. A REST request to `jet-blog-api/v1/plugin-settings` on a site where
  Elementor is deactivated will 404, not 403, since the route was never registered.
- Not yet traced: `includes/settings/manager.php` (required from `load_files()` but not
  read in this pass — likely the Jet Dashboard settings-page wiring, given
  `jet_dashboard_init()`'s `Jet_Dashboard\Dashboard::get_instance()->init()` call in
  `jet-blog.php:175-195`) and the `includes/modules/jet-dashboard` /
  `includes/modules/vue-ui` bundled frameworks (shared Crocoblock infrastructure, not
  JetBlog-specific — out of scope for this skill).

## How this was verified

Read `jet-blog.php` (bootstrap/`init()`/`has_elementor()`), `includes/class-jet-blog-integration.php`
(widget/control registration), `includes/base/class-jet-blog-base.php` (control-visibility
gating, `_render_meta()`), `includes/class-jet-blog-settings.php`, `includes/class-jet-blog-tools.php`
(`allowed_meta_callbacks()`), `includes/rest-api/rest-api.php`,
`includes/rest-api/endpoints/base.php`, `includes/rest-api/endpoints/plugin-settings.php`,
and `includes/class-jet-blog-video-data.php` directly in JetBlog For Elementor 2.4.8.1
source (`plugins/jet-blog/`), all by file:line citation, plus a `grep` for
`start_controls_section`/`_start_controls_section` in `jet-blog-smart-listing.php` to
confirm `title_length` (the backlog's Smart Posts List gist target) really sits inside
`section_general` — the section id the gist's
`elementor/element/jet-blog-smart-listing/section_general/before_section_end` hook
targets. That specific hook itself is **Elementor core's own generic per-widget/
per-section hook** (`do_action("elementor/element/{$name}/{$section_id}/before_section_end",
...)`, fired by every Elementor element for every one of its sections) — not something
JetBlog registers itself — so the gist's claim is correct in effect (the hook does fire
for this widget/section) but it isn't a JetBlog-specific extension point; no literal
`elementor/element/jet-blog-*` string exists anywhere in JetBlog's own source. Cross-checked
against `github.com/Crocoblock/developer-documentation` (2026-07-16): confirmed no JetBlog
section exists there, so no official doc could corroborate or correct anything above —
this skill is source-only. Not yet verified against a running site — see
`TEST-REGIMEN.md`; `tests.php` has reachability/source-presence smoke tests ready to
deploy.
