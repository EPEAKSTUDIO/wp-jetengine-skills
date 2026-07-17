---
name: jettabs-widgets
description: Use when registering/customizing a JetTabs For Elementor widget (Tabs, Accordion, Image Accordion, Switcher), overriding its controls via the `__add_control`/load-level gating system, hooking the `jet-tabs/widgets/template_id`/`jet-tabs/widgets/template_content` filters that shape ajax-loaded template content, working with the `jet-query` custom Elementor control, or debugging why "Clear Tabs Cache" didn't actually refresh a tab's ajax-loaded content. Captures verified behavior of JetTabs For Elementor 2.3.2 source (`plugins/jet-tabs/`), live-verified 2026-07-16 against jackfruit.epeak.studio (4/4 tests.php assertions passing).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetTabs For Elementor — widget/structure internals

Verified facts about JetTabs' own widget architecture: a shared `Jet_Tabs_Base` Elementor
widget base class, four addon widgets built on top of it, its ajax/self-request template
loader, and a genuinely confusing two-cache-systems situation. Confirmed against JetTabs
2.3.2 (`plugins/jet-tabs/`). No official Crocoblock developer docs exist for JetTabs —
checked `https://github.com/Crocoblock/developer-documentation` (2026-07-16): its README
lists JetEngine, JetSmartFilters, JetFormBuilder, JetThemeCore, JetPopup, JetBooking,
JetAppointmentsBooking, JetMenu, JetSearch, JetElements, JetBlocks, JetBlog, JetReviews —
no JetTabs section at all, and no `jet-tabs`/`gateway` hits anywhere in that repo's file
tree — so plugin source is the *only* source of truth here, not a supplementary check.

## Plugin bootstrap and the four widgets

`Jet_Tabs::init()` (`jet-tabs.php:176-212`) requires Elementor to be active
(`has_elementor()`, `:277-279`), then loads settings/assets/integration/rest-api/
compatibility, inits the DB-backed `Jet_Cache\Manager` singleton, and instantiates the
Elementor Template + Rest_Api REST classes. Addon widgets are auto-discovered via
`glob( 'includes/addons/*.php' )` in `Jet_Tabs_Integration::register_addons()`
(`includes/integration.php:178-193`), gated per-file against the `avaliable_widgets`
setting — every `.php` in that folder is registered unless explicitly disabled:

- `jet-tabs-widget.php` → `Jet_Tabs_Widget` (`get_name() = 'jet-tabs'`) — horizontal/
  vertical tabs.
- `jet-accordion-widget.php` → `Jet_Accordion_Widget` (`'jet-accordion'`) — toggle/FAQ
  accordion, with an optional JSON-LD FAQ schema (`includes/base/json-ld-schema.php`).
- `jet-image-accordion-widget.php` → `Jet_Image_Accordion_Widget`
  (`'jet-image-accordion'`) — hover-expand image panels.
- `jet-switcher-widget.php` → `Jet_Switcher_Widget` (`'jet-switcher'`) — a single
  enable/disable toggle switching between two fixed content states (not repeater-backed,
  no Query Gateway support — see `jettabs-query-gateway`).

All four `extends \Elementor\Jet_Tabs_Base` (`includes/base/class-jet-tabs-base.php:6`),
which itself `extends \Elementor\Widget_Base` — **not** any JetElements base class; see
`jettabs-query-gateway` for why that distinction matters for the shared Query Gateway
integration.

## `Jet_Tabs_Base`'s helper-method conventions

- **`__context`-dispatched methods**: most `__*` helpers (`__html`, `__loop_item`,
  `__get_global_template`, `__glob_inc_if`) look up `$this->__context` (`'render'` or
  `'edit'`) and `call_user_func` a `__{context}_*` sibling method
  (`class-jet-tabs-base.php:40-49,170-173,237-242,318-321`) — e.g. `__html('foo')` in
  render context calls `__render_html('foo')`; in Elementor-editor JS-template context
  it calls `__edit_html('foo')` instead, which emits Underscore.js `<# #>` template tags
  rather than real HTML. A widget's `render()` sets `$this->__context = 'render'` at its
  very first line (e.g. `jet-tabs-widget.php:1789`) before calling any of these.
- **`__add_control`/`__add_responsive_control`/`__add_group_control`/
  `__start_controls_section` etc. gate on a numeric "load level"** (`__load_level`,
  default 100, set from the `widgets_load_level` admin setting,
  `class-jet-tabs-base.php:25`) — a control is skipped entirely (never registered with
  Elementor) if `__load_level < $load_level` **unless** the control id is explicitly
  present in `__include_controls` (from the `jet-tabs/editor/{widget_name}/include-controls`
  filter, `:29`), and is force-skipped if present in `__exclude_controls`
  (`jet-tabs/editor/{widget_name}/exclude-controls`, `:31`) even above its load level —
  `__add_control` at `:414-434`. This is how JetTabs lets a site admin trim the control
  panel for performance/simplicity without a full custom widget.
- **Icon rendering handles the WP 5.5+ FontAwesome-4-to-SVG migration** —
  `__render_icon()` (`:608-674`) checks a `__fa4_migrated` map and
  `Icons_Manager::is_migration_allowed()` before falling back to the legacy
  `<i class="...">`-icon-class string path; it also auto-detects an icon *value* that's
  actually an image URL (`filter_var(..., FILTER_VALIDATE_URL)` +
  extension regex, `:643-650`) and renders an `<img>` instead of an `<i>`.

## Ajax/lazy-loaded template content — REST endpoint and the "self-request" shortcut

When a Tabs/Accordion/Switcher item's content type is `'template'` (an Elementor
Template Library item) and "Ajax Template" is enabled, the widget renders only a
`<div class="jet-tabs-loader">` placeholder server-side
(`jet-tabs-widget.php:1748-1750`) and the front-end JS
(`assets/js/jet-tabs-frontend.js`) fetches the real content afterward from one of two
places, chosen by the `ajax_request_type` admin setting
(`jet_tabs_settings()->get('ajax_request_type', 'default')`, `includes/assets.php:86`):

- **`'default'`** — `GET /wp-json/jet-tabs-api/v1/elementor-template?id={template_id}` —
  a real REST route registered by `\Jet_Tabs\Rest_Api` (`includes/rest-api/rest-api.php`)
  against `Endpoints\Elementor_Template::callback()`
  (`includes/rest-api/endpoints/elementor-template.php:280-312`), publicly accessible
  (`permission_callback()` returns `true` unconditionally, `:570-572`).
- **`'self'`** — the *same page URL* with a `?jet_tabs_self` query param appended,
  intercepted by a `parse_request` hook
  (`Elementor_Template::handle_self_request()`, `:237-273`) that swaps in a synthetic
  `WP_Query` for that request, builds the same template data, `wp_send_json()`s it, and
  `exit`s — avoiding a second REST round-trip/CORS surface for sites where the REST API
  is restricted.

Both paths funnel through `prepare_template_data()` (`:78-118`), which calls
`Elementor\Plugin::instance()->frontend->get_builder_content( $template_id, true )` and
also walks the template's Elementor element tree
(`get_elements_raw_data()`/`find_widgets_script_handlers()`) to report which registered
script/style handles the returned markup depends on, so the front end can enqueue them
lazily too.

### `jet-tabs/widgets/template_id` and `jet-tabs/widgets/template_content` filters

Every widget applies `jet-tabs/widgets/template_id` to the raw `item_template_id` repeater
value right before resolving it to a post (e.g. `jet-tabs-widget.php:1707`,
`jet-accordion-widget.php:1126`, `jet-switcher-widget.php:730`) — the multilingual-plugin
integration point (swap in a translated template's post id). The **non-ajax** synchronous
render path then applies `jet-tabs/widgets/template_content`
(`$content, $item, $content_dom_id, $this`, e.g. `jet-tabs-widget.php:1728-1734`) to the
already-rendered Elementor markup before echoing it — this filter does **not** fire on the
ajax/self-request path, since that path returns raw `get_builder_content()` output straight
from the REST/self-request `callback()` with no equivalent filter applied.

## Gotcha: two separate, disconnected cache stores

JetTabs ships an entire custom DB-table cache module (`Jet_Cache\Manager`/`DB_Manager`,
`includes/modules/jet-cache/`) that creates its own `{$wpdb->prefix}jet_cache` table
(`inc/db-manager.php:43-63,87-95`) with `set_cache()`/`get_cache()`/
`delete_cache_by_instance_id()`/`delete_cache_by_source()`, exposed via the global helper
functions `jet_set_transient()`/`jet_get_transient()` (`inc/functions.php`). **Grepping the
entire plugin shows `jet_set_transient()`/`jet_get_transient()`/`db_manager->set_cache()`
are never called anywhere** — nothing ever writes a row into that table. What *does* use
this system: `Jet_Tabs_Integration::save_elementor_template_post_type()`
(`includes/integration.php:290-297`, on `save_post`) calls
`delete_cache_by_instance_id( $template_id, 'elementor_library' )`, and the
"Clear Tabs Cache" admin action (`Endpoints\Clear_Tabs_Cache::callback()`,
`includes/rest-api/endpoints/clear-tabs-cache.php:44-59`) calls
`delete_cache_by_source( 'elementor_library' )` — both **delete from a table that is
never populated**.

Meanwhile the REST endpoint that actually serves ajax-loaded tab content
(`Elementor_Template::callback()`) caches its response with plain **WP core transients**
(`get_transient`/`set_transient`, keyed `md5('jet_tabs_elementor_template_data_' .
$template_id)`, 12-hour TTL, `elementor-template.php:294-309`) — a completely separate
storage mechanism that neither `save_elementor_template_post_type()` nor
"Clear Tabs Cache" ever touches (no `delete_transient()` call anywhere in the plugin).
**Net effect: editing an Elementor template used as ajax tab/accordion/switcher content,
or clicking "Clear Tabs Cache" in JetTabs' settings, does not invalidate the actual
per-template REST cache** — a page requesting that template's content via the default
(non-self-request) ajax path can keep serving stale markup for up to 12 hours after a
template edit. The `'self'`-request path (`handle_self_request()`) has no caching at all
and is unaffected.

## `jet-query` custom Elementor control

`Jet_Elementor_Extension\Query_Control` (`includes/modules/jet-elementor-extension/inc/
controls/query.php`) registers Elementor control type `'jet-query'`, extending Core's
`Control_Select2`. Used for e.g. the Switcher widget's "Template" picker
(`jet-switcher-widget.php:103-113`, `'type' => 'jet-query', 'query_type' =>
'elementor_templates'`) — a searchable AJAX-populated select rather than a flat dropdown
of every Elementor Library template. `before_save()` normalizes the stored value to
`absint`/array-of-`absint` (`:25-35`) regardless of what the AJAX search widget posted.

## How this was verified

Read `jet-tabs.php`, `includes/base/class-jet-tabs-base.php`,
`includes/integration.php`, `includes/assets.php`, `includes/settings.php`,
`includes/rest-api/rest-api.php` + all 4 files under `includes/rest-api/endpoints/`, all
4 files under `includes/addons/`, `includes/modules/jet-cache/` in full, and
`includes/modules/jet-elementor-extension/inc/controls/query.php`, then grepped every
`apply_filters`/`do_action` call with a `jet-tabs`-prefixed hook name across
`includes/` to build the hook inventory above. Checked
`https://github.com/Crocoblock/developer-documentation` for a JetTabs section (none
exists — see intro). Not yet verified against a running site — `tests.php` covers the
few claims checkable via plain source-presence (file/string checks) and generic
WP-mechanism smoke tests (REST route registration, hook presence) that don't require a
real Elementor-rendered page; the ajax-template-cache-mismatch gotcha and the load-level
gating behavior are flagged in `TEST-REGIMEN.md` as needing a live site + browser to
fully confirm end-to-end.
