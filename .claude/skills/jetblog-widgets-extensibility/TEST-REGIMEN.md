# Test regimen: jetblog-widgets-extensibility

Validates claims in `SKILL.md`. Run against the sandbox site (jackfruit.epeak.studio),
which needs JetBlog For Elementor 2.4.8.1 active alongside Elementor.

## Run log — 2026-07-16: UNBLOCKED, live-verified (6/6 pass after 2 test-only fixes)

Deployed `tests.php` as Code Snippets snippet id 53 and ran
`GET /agent-test/v1/suite/jetblog-widgets-extensibility`. First run: 500 Internal Server
Error (whole request), triaged by deactivating and bisecting with isolated `ZZZ-DIAG`
probes down to `jbwe-2` immediately followed by `jbwe-3` — Elementor's own
`widgets_manager->get_widget_types()` lazily `require`s every registered widget's class
file the first time it's called in a request, with no `class_exists()` guard on its own
side; `jbwe-2`'s own guarded `require` of the Smart Listing widget file ran first (since
nothing had triggered Elementor's lazy registration yet), so `jbwe-3`'s later
`get_widget_types()` call re-`require`d the same file and threw an uncatchable "Cannot
redeclare class" fatal. Fixed by forcing `get_widget_types()` first in `jbwe-2`, before
its own guarded requires (a test-only ordering bug, not a plugin bug). Second run: 5/6 —
`jbwe-1` failed on a strict `true === $has_elementor` check against a truthy non-bool
return value; fixed to a loose truthy check. Third run: **6/6 pass**.

## Prerequisites

- JetBlog For Elementor 2.4.8.1 active, with Elementor active.
- The always-active `AGENT-TEST-CORE harness` snippet (id 22).
- No YouTube Data API key configured yet on this sandbox (the default state) — Test 5
  exercises the "silently empty without a key" gotcha directly, which needs no fixture.

## Test 1: `jet_blog()` singleton resolves and gates its whole init on Elementor having loaded

**Claim:** `jet_blog()` returns `Jet_Blog::get_instance()`; `init()` checks
`has_elementor()` (`did_action('elementor/loaded')`) and returns early (only an admin
notice) if Elementor hasn't loaded — nothing else in the plugin (REST, AJAX handlers,
widget registration) initializes on such a request.

**Automated as:** `jbwe-1` in `tests.php` — confirms `function_exists('jet_blog')`,
`get_class(jet_blog())`, and (since Elementor is active on this sandbox)
`jet_blog()->has_elementor()` is `true`, plus that `jet_blog_assets`/`jet_blog_integration`/
`jet_blog_video_data`/`jet_blog_ajax_handlers` singleton functions all exist and resolve
to non-null instances (proving `init()` actually ran past the gate on this site).

## Test 2: per-widget include/exclude-controls filters are read once per widget instance, and include wins over widgets_load_level

**Claim:** `Jet_Blog_Base::__construct()` resolves
`jet-blog/editor/{$widget_name}/include-controls` /
`.../exclude-controls` into `$this->_include_controls`/`_exclude_controls`;
`_is_visible_control()` returns `true` for any control id present in
`_include_controls` regardless of the global `widgets_load_level` setting.

**Automated as:** `jbwe-2` in `tests.php` — hooks
`jet-blog/editor/jet-blog-smart-listing/include-controls` to return a marker control id,
instantiates the widget (which triggers the constructor read), and confirms
`_is_visible_control( 'agent_test_marker_control', 100 )` returns `true` even when
temporarily forcing a fake high `$load_level` argument that would otherwise fail — plus
a control NOT in the include-list at the same load_level returns `false`.

## Test 3: widget registration honors the `avaliable_widgets` per-slug toggle, with the "unset option = all enabled" default

**Claim:** `Jet_Blog_Integration::register_addons()` registers every `includes/addons/*.php`
widget unless `jet_blog_settings()->get('avaliable_widgets')` is a non-empty array that
explicitly omits/disables that slug.

**Automated as:** `jbwe-3` in `tests.php` — source-presence + live-settings check: reads
the current `avaliable_widgets` option value via `jet_blog_settings()->get('avaliable_widgets')`
and confirms its shape (array or falsy), then confirms via
`class_exists('Elementor\Jet_Blog_Smart_Listing')` etc. that the 6 known addon classes
are actually registered as Elementor widget types
(`\Elementor\Plugin::instance()->widgets_manager->get_widget_types()`) — consistent with
whatever the current toggle state implies. Does not flip the setting and re-test the
disabled case live (would mutate site-wide widget availability) — flagged as manual
below.

## Test 4: REST endpoint permission_callback defaults to manage_options; plugin-settings doesn't override it

**Claim:** `Jet_Blog\Endpoints\Base::permission_callback()` defaults to
`current_user_can('manage_options')`; `Plugin_Settings` doesn't override it.

**Automated as:** `jbwe-4` in `tests.php` — instantiates
`Jet_Blog\Endpoints\Plugin_Settings` directly (safe — a plain endpoint object, no
hook-registration side effects per its class body having no constructor), confirms
`get_method()` is `'POST'`, `get_name()` is `'plugin-settings'`, and calling
`permission_callback( $fake_request )` while the test runs as a non-manage_options user
context is not attempted directly (test runs inside the harness's own admin/REST
context) — instead confirms via `Reflection` that `Plugin_Settings` does NOT declare its
own `permission_callback` method (inherits `Base`'s), and confirms
`jet_blog()->has_elementor() && get_class( new \Jet_Blog\Rest_Api() )` resolves so the
route is actually registered — a live unauthenticated HTTP check against
`/wp-json/jet-blog-api/v1/plugin-settings` expecting 401/403 is flagged as manual below
(this suite runs PHP-side, not over HTTP).

## Test 5: Video Data silently returns empty for YouTube sources without an API key, unlike Vimeo

**Claim:** every YouTube-branch method on `Jet_Blog_Video_Data` (`get_youtube_data()`,
`get_youtube_playlist_video_list()`, `get_youtube_channelid_video_list()`,
`get_youtube_channel_video_list()`) returns `array()` immediately when
`jet_blog_settings()->get('youtube_api_key')` is empty, with no exception/error surfaced
— Vimeo's `get_vimeo_data()` has no such gate (makes the request regardless, since Vimeo
doesn't require a key).

**Automated as:** `jbwe-5` in `tests.php` — confirms `jet_blog_settings()->get('youtube_api_key')`
is empty on this sandbox (the default/undisturbed state), then calls
`jet_blog_video_data()->get_youtube_data( 'dQw4w9WgXcQ' )` and confirms it returns `array()`
with no exception — proving the short-circuit, not a network failure path (no HTTP
request should even be attempted; can't directly assert "zero HTTP calls made" from
inside this harness, so this is an outcome-only check — flagged as a limitation).

## Test 6: cache TTL filters apply independently for video vs. list caching

**Claim:** `get_ttl( $type )` applies `jet-blog/video-cache/ttl-hours` for single-video
metadata and the separate `jet-blog/list-cache/ttl-hours` for channel/playlist video
lists — two distinct filter names, not one shared one.

**Automated as:** `jbwe-6` in `tests.php` — calls the private `get_ttl()` method via
`ReflectionMethod` with `'video'` and `'list'`, having hooked both filter names with
distinguishable multipliers, and confirms each call only triggered its own filter name
(cross-contamination would mean both filters fired for both calls).

## Test 7 (manual, not automated): `elementor/element/jet-blog-smart-listing/section_general/before_section_end` really fires

**Claim:** this is Elementor core's own generic per-widget/per-section hook (not
something JetBlog registers), firing because `section_general` is a real section id in
`Jet_Blog_Smart_Listing::register_controls()` — used by the backlog gist to add a higher
max on the `title_length` NUMBER control (default max `15` words,
`jet-blog-smart-listing.php:750-757`).

**Not automated** — safely triggering Elementor's own controls-registration hook cycle
for a specific widget from inside a REST-context test suite risks interacting with
Elementor's editor-only bootstrapping in ways not yet characterized (unlike the
JetSmartFilters/JetAppointments precedents, this isn't a JetBlog-owned
singleton/registry, it's Elementor core's per-element hook system). Manual steps: hook
`add_action('elementor/element/jet-blog-smart-listing/section_general/before_section_end',
function($element,$args){ /* raise title_length max via $element->update_control() */ },
10, 2);`, open the widget in the Elementor editor, and confirm the Title Max Length
field's max attribute changed from `15`.
