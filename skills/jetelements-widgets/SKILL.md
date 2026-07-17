---
name: jetelements-widgets
description: Use when registering/gating a JetElements For Elementor widget, overriding its default controls/CSS/JS, adding a new shortcode, or hooking the plugin's own extension points (per-widget include/exclude-controls, custom Elementor controls, REST endpoints, carousel-options). Captures verified behavior from JetElements 2.9.1.2 source, live-verified 2026-07-16 against jackfruit.epeak.studio (7/7 tests.php assertions passing).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetElements Widget/Addon Registration Internals

Verified facts about how JetElements For Elementor (an Elementor widget-pack addon, not
a JetEngine module) registers its ~35 widgets, gates them, extends Elementor's control
system, and exposes its own REST namespace. Confirmed against JetElements 2.9.1.2
source (`plugins/jet-elements/`).

## The plugin singleton and per-subsystem singletons

Same pattern throughout the codebase: a private static `$instance`, a `get_instance()`
static method, and a lowercase global accessor function — never call `new ClassName()`
directly for any of these (see the "Gotchas" section for why one of them is unsafe to
double-instantiate):

- `jet_elements()` → `Jet_Elements::get_instance()` (`jet-elements.php:397-403,415-417`).
  `get_version()` returns the plugin version string (`'2.9.1.2'`, `jet-elements.php:68,129-131`);
  `plugin_path( $path )` / `plugin_url( $path )` resolve paths relative to the plugin
  root (`jet-elements.php:309-330`). `jet_elements()->has_elementor()` is just
  `did_action( 'elementor/loaded' )` (`jet-elements.php:263-265`) — the plugin does
  nothing (widgets, controls, REST, shortcodes) until Elementor has already loaded.
- `jet_elements_settings()` → `Jet_Elements_Settings::get_instance()`
  (`includes/class-jet-elements-settings.php:356-362,371-373`). Its `->init()` runs
  immediately at file-load time (`:375`, not deferred to a hook), which populates
  `$this->avaliable_widgets` (slug ⇒ display name) by globbing
  `includes/addons/*.php` and reading each file's `Name`/`Slug`/`Class` docblock
  headers via `get_file_data()` (`:89-95`) — so `avaliable_widgets` is already a
  populated array by the time any other init-hook code runs. `get( $setting, $default )`
  reads a single flat option, `get_option( 'jet-elements-settings', array() )`
  (`:35,287-295`) — there is no per-widget option row, everything (widget on/off,
  API keys, map provider, `widgets_load_level`) lives in that one option array under
  a different key.
- `jet_elements_integration()` → `Jet_Elements_Integration::get_instance()`
  (`includes/class-jet-elements-integration.php:430-436,447-449`). Owns widget/addon
  registration and Elementor control rewriting (below).
- `jet_elements_assets()`, `jet_elements_shortocdes()` (**verbatim typo — "shortocdes",
  not "shortcodes"**, `includes/class-jet-elements-shortcodes.php:117-119`), and the
  advanced-map provider registries follow the identical pattern. **The typo'd accessor
  is the real, only way to reach the shortcode registry** — `jet_elements_shortcodes()`
  (correctly spelled) does not exist and will fatal with an undefined-function error.

## Widget/addon registration pipeline

`Jet_Elements_Integration::init()` (`includes/class-jet-elements-integration.php:42-71`)
wires the whole thing on Elementor's own hooks — version-gated because Elementor 3.5.0
renamed its registration hook:

```php
if ( version_compare( ELEMENTOR_VERSION, '3.5.0', '>=' ) ) {
    add_action( 'elementor/widgets/register', array( $this, 'register_addons' ), 10 );
    add_action( 'elementor/widgets/register', array( $this, 'register_vendor_addons' ), 20 );
} else {
    add_action( 'elementor/widgets/widgets_registered', array( $this, 'register_addons' ), 10 );
    add_action( 'elementor/widgets/widgets_registered', array( $this, 'register_vendor_addons' ), 20 );
}
```

`register_addons()` (`:112-127`) globs every file in `includes/addons/*.php`, gates
each by `jet_elements_settings()->get( 'avaliable_widgets' )[ $slug ]` (a widget is
skipped only if the setting array exists **and** explicitly has that slug set falsy —
`! $avaliable_widgets` short-circuits to "load everything" when the setting has never
been saved), then calls `register_addon( $file, $widgets_manager )` (`:388-404`), which
derives the Elementor widget class name straight from the filename: dashes→spaces→
`ucwords()`→underscores, namespaced `Elementor\` — e.g.
`includes/addons/jet-elements-advanced-carousel.php` → class
`Elementor\Jet_Elements_Advanced_Carousel`, registered as `->get_name() === 'jet-carousel'`
(confirmed literal string at `jet-elements-advanced-carousel.php:25-27` — the widget
slug and the addon filename are **not** the same string).

`register_vendor_addons()` (`:135-235`) is a second, separate pass for
integration-widgets (SmartSlider3, WooCommerce product widgets, Contact Form 7, MP
Timetable, Booked Calendar/Appointments) — each entry in the
`jet-elements/allowed-vendor-addons` filter array carries its own `conditional`
(`class_exists`/`defined` callback) so e.g. the WooCommerce widgets never even attempt
to load their addon file when WooCommerce is inactive.

**Gotcha — do not call `register_addons()`/`register_vendor_addons()`/`register_addon()`
directly from a snippet, even though they look like safe read-only registration
helpers.** `register_addon()`'s `require $file;` (`:395`) is a plain `require`, not
`require_once` — on a normal request Elementor's own `elementor/widgets/register` hook
has already fired and loaded every enabled addon's class file once. Calling any of
these methods a second time re-`require`s an already-declared widget class, which is
the same class of uncatchable "Cannot redeclare class" fatal this repo hit for real
against `jetsmartfilters-query`'s `Storage\Controller` (see that skill's SKILL.md and
`HANDOFF.md`'s safety lesson) — reasoned from source here, not yet live-triggered
(deliberately not attempted).

## Per-widget dynamic control filters

Every widget's base class constructor (`Elementor\Jet_Elements_Base extends
Widget_Base`, `includes/base/class-jet-elements-base.php:21-31`) applies two
**dynamically-named** filters keyed on the widget's own slug:

```php
$this->_include_controls = apply_filters( "jet-elements/editor/{$widget_name}/include-controls", [], $widget_name, $this );
$this->_exclude_controls = apply_filters( "jet-elements/editor/{$widget_name}/exclude-controls", [], $widget_name, $this );
```

(`:28-30`) — e.g. to force-include or hide a specific control on just the Advanced
Carousel widget: `add_filter( 'jet-elements/editor/jet-carousel/include-controls', ... )`.
`$widget_name` is `$this->get_name()` (the slug, e.g. `'jet-carousel'`), not the addon
file's basename.

## Custom/rewritten Elementor controls

`Jet_Elements_Integration` hooks `elementor/controls/controls_registered` twice
(`:56-58`):

- `rewrite_controls()` (`:295-314`) **unregisters and replaces Elementor's own core
  `ICON` control** with `Jet_Elements_Control_Icon extends Elementor\Control_Icon`
  (`includes/controls/class-jet-elements-control-icon.php:15`) — a real, working
  example of overriding a built-in Elementor control, not just adding a new one.
- `add_controls()` (`:322-356`) registers two brand-new controls: `jet_dynamic_date_time`
  → `Jet_Elements_Control_Date_Time extends Elementor\Control_Date_Time`
  (`includes/controls/class-jet-elements-control-date-time.php:17`), and a **grouped**
  control `jet-box-style` → `Jet_Group_Control_Box_Style extends
  Elementor\Group_Control_Base` (`includes/controls/groups/class-jet-group-control-box-style.php:14`,
  registered via `$controls_manager->add_group_control()`, not `register()`).
  `include_control( $class_name, $grouped )` (`:364-379`) derives the file path from the
  class name (`class-jet-elements-control-icon.php`), reading from
  `includes/controls/groups/` only when `$grouped` is true.

## Widget "carousel-options" pattern — reused across every slick-based widget

Every widget that wraps its items in a slick-carousel builds a plain PHP options array
for the JS carousel init, then runs it through a **widget-specific** filter right before
returning it — the reusable pattern for changing e.g. adaptive height, arrows, or
autoplay without touching the widget's own controls:

- `jet-elements/jet-carousel/carousel-options` — Advanced Carousel,
  `get_advanced_carousel_options()`, `includes/addons/jet-elements-advanced-carousel.php:2233`.
- `jet-elements/jet-posts/carousel-options` — Posts widget's carousel-layout mode,
  `includes/addons/jet-elements-posts.php:2490`.
- `jet-elements/jet-testimonials/carousel-options` — `includes/addons/jet-elements-testimonials.php:2781`.
- `jet-elements/jet-image-comparison/carousel-options` — `includes/addons/jet-elements-image-comparison.php:1692`.
- `jet-elements/slider/slider-options` — the dedicated Slider widget (different naming,
  no `jet-` widget-slug segment), `includes/addons/jet-elements-slider.php:3362`.

All five receive `( $options_array, $settings, $widget_id )` and must return the mutated
array — confirmed identical signature shape by reading each call site.

## Assets: registration/enqueue hooks and override points

`jet_elements_assets()->init()` (`includes/class-jet-elements-assets.php:33-58`) hooks
Elementor's own asset lifecycle, not raw `wp_enqueue_scripts`:
`elementor/frontend/before_register_styles` → `register_styles()`,
`elementor/frontend/before_register_scripts` → `register_scripts()`,
`elementor/frontend/before_enqueue_scripts` → `enqueue_scripts()`,
`elementor/editor/before_enqueue_scripts` → `editor_scripts()`. Every per-widget CSS/JS
handle is named after the addon slug from `Jet_Elements_Integration::addons_with_styles()`
(`includes/class-jet-elements-integration.php:246-287`, e.g. `jet-carousel`, `jet-banner`,
`jet-posts`) and registered with `[ 'jet-elements' ]` as a dependency
(`class-jet-elements-assets.php:126-133`) — a custom stylesheet meant to override one
widget's look only needs to depend on that same handle to guarantee load order. The
`-skin` variant stylesheet (default JetElements visual theme) is registered only when
`apply_filters( 'jet-elements/assets/css/default-theme-enabled', true )` is truthy
(`:124,135-143`) — the real switch to fully opt out of JetElements' bundled skin CSS
site-wide, not a per-widget setting.

## REST API — `jet-elements-api/v1`

`\Jet_Elements\Rest_Api` (`includes/rest-api/rest-api.php`) is a singleton
(`get_instance()`, `:42-49`) but the main plugin bootstraps it with a bare
`new \Jet_Elements\Rest_Api()` instead (`jet-elements.php:160`) — a minor internal
inconsistency, not a landmine: the constructor only does
`add_action( 'rest_api_init', ... )` (`rest-api.php:53-55`) with no unconditional
`require`, so calling `\Jet_Elements\Rest_Api::get_instance()` from a snippet afterward
is safe (creates a second, harmless manager object; at worst double-registers the same
REST routes, which `register_rest_route()` tolerates). Two endpoints ship built-in,
registered from `init_endpoints()` (`:58-70`), then
`do_action( 'jet-elements/rest/init-endpoints', $this )` (`:68`) — the extension point
for adding a third: `Endpoints\Elementor_Template` (route `elementor-template`, used to
fetch a Global Template's rendered content/id for widgets like Carousel/Slider/Dropbar
that support a "load from template" item type — see their own
`jet-elements/widgets/template_id` / `template_content` filters) and
`Endpoints\Plugin_Settings` (route `plugin-settings`, backs the admin Settings page's
save/load AJAX). Every endpoint class extends `abstract Endpoints\Base`
(`includes/rest-api/endpoints/base.php`), defaulting `permission_callback()` to
`current_user_can( 'manage_options' )` (`:43-45`) unless overridden.

## Shortcodes — a second, non-Elementor entry point

`jet_elements_shortocdes()->register_shortcodes()` (hooked on `init`, priority 30,
`includes/class-jet-elements-shortcodes.php:42-59`) globs `includes/shortcodes/*.php`
the same filename→class-name way widgets do, instantiates each, and stores it keyed by
`->get_tag()`. As of 2.9.1.2 there is exactly **one** shortcode,
`[jet-posts]` (`Jet_Posts_Shortcode`, `includes/shortcodes/jet-posts-shortcode.php:5,18-20`,
extending `abstract Jet_Elements_Shortcode_Base`,
`includes/base/class-jet-elements-shortcode-base.php:7`) — a fully independent
implementation of the Posts widget usable outside Elementor entirely (in classic
content, a template via `do_shortcode()`, etc). Its attribute schema is filterable via
`jet-elements/shortcodes/jet-posts/atts` (`jet-posts-shortcode.php:35`), and its render
loop fires four of its own hooks distinct from the widget's Query-Gateway hooks (see
`jetelements-query-gateway`): `jet-elements/shortcodes/jet-posts/loop-start`,
`loop-item-start`, `loop-item-end`, `loop-end` (`:729,743,750,759`).

## Gotchas

- **`jet_elements_shortocdes()` — the misspelling is load-bearing**, not a doc error;
  the correctly-spelled function genuinely doesn't exist.
- **Never call `register_addons()`/`register_vendor_addons()`/`register_addon()`
  directly** — re-`require`s already-declared widget classes on any request past the
  first (see "Widget/addon registration pipeline" above).
- A widget's Elementor-facing slug (`get_name()`, e.g. `'jet-carousel'`) is **not** the
  same string as its addon filename slug (`jet-elements-advanced-carousel`) used for
  the `avaliable_widgets` settings-gating key — don't assume `get_name()` matches
  `basename( $file, '.php' )`.
- `widgets_load_level` (`Elements_Base::$_load_level`, `includes/base/class-jet-elements-base.php:24`)
  is a JetStyleManager-compatibility integration point
  (`register_for_styles_manager()`, `class-jet-elements-settings.php:112-114`), not a
  per-widget individual toggle — it's a single global "how much CSS to preload"
  setting.

## How this was verified

Read `jet-elements.php`, `includes/class-jet-elements-integration.php`,
`includes/class-jet-elements-settings.php`, `includes/class-jet-elements-assets.php`,
`includes/class-jet-elements-shortcodes.php`, `includes/base/class-jet-elements-base.php`,
`includes/base/class-jet-elements-shortcode-base.php`,
`includes/shortcodes/jet-posts-shortcode.php`,
`includes/controls/class-jet-elements-control-icon.php`,
`includes/controls/class-jet-elements-control-date-time.php`,
`includes/controls/groups/class-jet-group-control-box-style.php`,
`includes/rest-api/rest-api.php`, `includes/rest-api/endpoints/base.php`, and
`includes/rest-api/endpoints/elementor-template.php` in JetElements 2.9.1.2 source
(`plugins/jet-elements/`), plus grepping every `jet-elements/*` hook across
`includes/addons/*.php` to confirm the carousel-options pattern's five real call sites.
Cross-checked the one gist already logged in
`other-plugins-backlog/OTHER-PLUGINS.md`'s "JetElements" section
(`jet-elements/jet-carousel/carousel-options`, adaptive-height gist) — confirmed as a
real hook, folded into the broader carousel-options section above rather than
documented standalone. Not yet verified against a running site — JetElements was not
installed on the sandbox as of this writing (see `TEST-REGIMEN.md`); `tests.php` is
written as reachability/source-presence smoke tests ready to run once it is.
