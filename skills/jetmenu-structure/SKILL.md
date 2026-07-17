---
name: jetmenu-structure
description: Use when working with JetMenu (Crocoblock's Elementor mega-menu plugin) — the Mega Menu Items CPT and its meta structure, where per-item/per-location settings are actually stored, the Base_Render → Elementor_Content_Render/Block_Editor_Content_Render rendering pipeline, the Mega_Menu_Walker/Vertical_Menu_Walker nav-menu walkers, or the jet-menu-api/v2 REST endpoints. Captures verified behavior from JetMenu 3.0.2.1 source, live-verified 2026-07-16 against jackfruit.epeak.studio (10/10 tests.php assertions passing — first run caught a real fatal-instantiation bug in this doc, see SKILL.md's Bootstrap section and TEST-REGIMEN.md).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetMenu Data Model & Rendering

Verified facts about how JetMenu stores mega-menu content and settings, and how that
content actually gets turned into HTML on the front end. Confirmed against JetMenu
3.0.2.1 source (`plugins/jet-menu/`).

## Bootstrap: everything lives on `jet_menu()`, don't re-instantiate it

`jet_menu()` (`jet-menu.php:532`) returns the single `Jet_Menu::get_instance()` object
(`jet-menu.php:513-520`). Its `init()` method — hooked on WP `init` at priority `-999`
(`jet-menu.php:150`) — constructs every manager exactly once and stores most of them as
a public property: `$post_type_manager` (`\Jet_Menu\Menu_Post_Type`, `jet-menu.php:213`),
`$settings_manager` (`\Jet_Menu\Settings_Manager`, `:216`), `$render_manager`
(`\Jet_Menu\Render\Manager`, `:234`), `$blocks_manager` (`\Jet_Menu\Blocks\Manager`, `:237`),
`$elementor_manager` (`\Jet_Menu\Elementor`, `:225`). **`Rest_Api` is the one exception**:
it's constructed as `new \Jet_Menu\Rest_Api()` (`:219`) but never assigned to a `jet_menu()`
property — there is no `jet_menu()->rest_api`; reach it (rarely needed) via
`\Jet_Menu\Rest_Api::get_instance()`.

**Every one of these manager classes has its own `get_instance()` singleton method, but
the plugin's own bootstrap never calls it** — it always does `new ClassName()` directly
(confirmed for `Menu_Post_Type`, `Settings_Manager`, `Elementor`, `Render\Manager`; see
each class's own `get_instance()` at e.g. `menu-post-type.php:48-56`). Calling
`SomeClass::get_instance()` yourself therefore does **not** return the object the rest of
the plugin is using — it constructs a second, independent instance.

**Live-verified correction (2026-07-16, jackfruit.epeak.studio): `Options_Manager` is
*also* a fatal, not just a desync — a broader class of this landmine than initially
documented.** `Options_Manager::__construct()` (`options.php:1486-1515`) calls
`init_options()` (`:1430-1477`), which does an unconditional `require $path;` (not
`require_once`, `:1467`) for each of its options-module files
(`options-modules/general-options.php`, `mobile-menu-options.php`, plus either
`desktop-menu-options.php` or `main-menu-options.php` depending on nextgen mode,
`:1435-1458`) before `new $class()`-ing each one (`:1475`). A second
`\Jet_Menu\Options_Manager::get_instance()` call re-runs `init_options()`, re-`require`s
those already-loaded files, and throws an uncatchable "Cannot redeclare class" fatal —
confirmed live by isolating the exact call in its own test-suite snippet (500 Internal
Server Error, WordPress's generic "critical error" page, no catchable exception). The
original draft of this section incorrectly called this "just" a desynced second object;
it is not — treat it exactly like `Render\Manager`/`Blocks\Manager` below.

**For `Options_Manager`, `Render\Manager`, and `Blocks\Manager` specifically,
re-instantiating is a fatal, not a desync.** `Render\Manager::__construct()` calls
`load_files()` (`render/manager.php:814-822`), which does unconditional `require` (not
`require_once`) of `base-render.php` and every walker/render-module file (`:49-67`);
`Blocks\Manager::register_block_types()` does the same for `base.php` and every block
class file (`blocks/manager.php:144,152`). A second `new \Jet_Menu\Render\Manager()`,
`new \Jet_Menu\Blocks\Manager()`, or `\Jet_Menu\Options_Manager::get_instance()` (when not
already constructed) re-runs those `require`s and throws an uncatchable "Cannot redeclare
class" fatal. **Always reach every manager via `jet_menu()->post_type_manager` /
`->settings_manager` / `->render_manager` / `->blocks_manager` / `->elementor_manager` —
never construct or `::get_instance()` any of them yourself**, including
`jet_menu()->settings_manager->options_manager` for the options manager specifically —
that's the only safe accessor. (`Blocks\Manager`'s constructor additionally does
`self::$instance = $this;` unconditionally, `blocks/manager.php:166`, so even a stray
`new` call — not just `::get_instance()` — silently clobbers the "singleton" pointer on
top of the redeclare risk.)

## The Mega Menu Items CPT (`jet-menu`)

Registered in `Menu_Post_Type::register_post_type()` (`includes/menu-post-type.php:293-330`,
slug from `$this->post_type = 'jet-menu'`, `menu-post-type.php:28`): `public => true`,
`show_ui => true`, `show_in_menu => false` (no dedicated admin-menu entry — posts are
only created/edited via the nav-menu-item flow, see below), `show_in_nav_menus => false`
(mega-menu content posts never appear as menu items themselves), `has_archive => false`,
`supports => array('title', 'editor', 'custom-fields')`. It gets Elementor edit support
via a filter hack rather than the normal `elementor_cpt_support` option UI:
`add_filter('option_elementor_cpt_support', ...)` / `add_filter('default_option_elementor_cpt_support', ...)`
(`menu-post-type.php:68-70`) inject `'jet-menu'` into whatever Elementor's own CPT-support
option returns.

A logged-out/non-`edit_posts` visitor who somehow requests a singular `jet-menu` post
directly is redirected to the home URL by `restrict_template_access()`
(`menu-post-type.php:544-557`), and its front-end template is forced to a blank shell
(`templates/blank.php`) via `template_include` (`menu-post-type.php:346-362`), firing
`do_action('jet-menu/template-include/found')` when that happens.

## Where mega-menu data actually lives — three separate storage locations

This is the part most likely to be guessed wrong. There is no single "get the mega
menu" call; three different things are stored in three different places:

1. **Per-menu-item settings** (whether mega is enabled, content type, icon/badge,
   dynamic-visibility rules, custom width, etc.) — **post meta on the `nav_menu_item`
   post**, meta key `jet_menu_settings` (`Settings_Manager::$menu_item_meta_key`,
   `includes/settings/manager.php:43`). Full default schema (every key a JetMenu nav
   item can have) is `default_nav_item_controls_data()` (`manager.php:555-668`). Read it
   with `jet_menu()->settings_manager->get_menu_item_settings( $menu_item_id )`
   (`manager.php:1019-1049`, applies `default_nav_item_controls_data()` as fallback via
   `wp_parse_args`), or the raw stored value only with `get_item_settings( $id )`
   (`manager.php:731-737`, no defaults merged).
2. **The mega-menu content itself** — a separate `jet-menu` CPT post, whose ID is stored
   as **post meta on the same `nav_menu_item` post**, under one of two keys depending on
   content type: `jet-menu-item` for Elementor content
   (`Menu_Post_Type::$meta_key`, `menu-post-type.php:33`) or
   `jet-menu-item-block-editor` for Block Editor content (hardcoded string, e.g.
   `render/manager.php:95`, `render/walkers/mega-menu-walker.php:400`). Which key is
   authoritative is decided by a **third** meta key, `_content_type` (`'elementor'` or
   `'default'`), written by `Menu_Post_Type::perform_redirect()`
   (`menu-post-type.php:507-515`) — but note `get_menu_item_settings()`'s own
   `content_type` resolution (`manager.php:1038-1041`) treats `elementor` as authoritative
   whenever a `jet-menu-item` meta value exists AND `_content_type` is empty, not only
   when `_content_type === 'elementor'`.
3. **Per-nav-menu-location settings** (enabled/preset/mobile-menu-linkage per WP theme
   location) — **term meta on the `nav_menu` taxonomy term** (the menu itself, not an
   item), confusingly reusing the *same* meta-key string `jet_menu_settings`
   (`Settings_Manager::$menu_item_meta_key`, shared between `get_settings( $menu_id )`
   → `get_term_meta()` and `get_item_settings( $id )` → `get_post_meta()`,
   `manager.php:711-737`). They're different storage entities (term vs post meta) so
   there's no real collision, but the shared constant name is worth knowing about if you
   go looking for "the" meta key.

The `jet-menu` CPT post itself additionally carries `_jet_menu_content_type` (set at
creation, `menu-post-type.php:499`) and three render-cache meta keys managed by the
render pipeline below: `_is_deps_ready`, `_is_script_deps`, `_is_style_deps` (plus
`_is_content_elements` for Block Editor content) — all four are wiped on every
`save_post` for a `jet-menu` post (`Menu_Post_Type::save_menu_post_type()`,
`menu-post-type.php:523-538`, which also clears any `Jet_Cache\DB_Manager` cache entries
tagged `'jet-menu'` if the JetCache module class exists).

## "NextGen" vs legacy mode — check this before reading any render/widget code

`jet_menu_tools()->is_nextgen_mode()` gates large parts of the codebase: which walker
class loads (`render/manager.php:51`, nextgen → `includes/render/walkers/*`, legacy →
`includes/render/walkers/legacy/*`), whether the Gutenberg Blocks Manager does anything
at all (`Blocks\Manager::__construct` returns `false` immediately if not nextgen,
`includes/blocks/manager.php:168-170`), and which Elementor widgets directory gets
glob-loaded (`includes/elementor/widgets/` vs `.../legacy/`, `elementor/manager.php:85`).
It's backed by the `plugin-nextgen-edition` option (`options.php:190-191,1447`, toggled
by the `revamp-on`/`revamp-off` `jet-action` query-string actions,
`options.php:230-245`). Before assuming a class/hook you found actually runs, check
which mode it belongs to.

## The render pipeline: `Base_Render` → two concrete renderers

`Jet_Menu\Render\Base_Render` (`includes/render/base-render.php:4`) is an abstract base:
constructor stores `wp_parse_args($settings, $this->default_settings())`
(`base-render.php:16-17,34-39`), `get_content()` wraps the abstract `render()` in
`ob_start()`/`ob_get_clean()` (`base-render.php:106-112`), and `get_render_data()`
(overridden by subclasses) returns a `{content, contentElements, styles, scripts,
afterScripts}` array. Two concrete renderers, both constructed with
`['template_id' => $id, 'with_css' => true, 'is_content' => bool]`:

- **`Elementor_Content_Render`** (`includes/render/render-modules/elementor-template-render.php:9`)
  — `render()` calls `\Elementor\Plugin::instance()->frontend->get_builder_content( $template_id, $with_css )`
  then `echo do_shortcode( $content )` (`:101-111`); diffs `wp_styles()->queue`/
  `wp_scripts()->queue` before/after to discover style/script dependencies, cached into
  `_is_style_deps`/`_is_script_deps` post meta (`:204,251`).
- **`Block_Editor_Content_Render`** (`includes/render/render-modules/block-editor-template-render.php:9`)
  — `render()` runs the CPT post's raw `post_content` through `do_blocks()` then
  `do_shortcode()` (`:91-92`), filterable via `apply_filters('jet-menu/render/block-editor/content', ..., $template_id)`
  (`:91`). Also caches which block names appear via `parse_blocks()` into
  `_is_content_elements` meta (`get_content_elements()`, `:232-272`).

Both cache-style checks are guarded by `apply_filters('jet-menu/render/render-data/use-cache', true)`
(e.g. `elementor-template-render.php:231` for style data, `block-editor-template-render.php:156,203,246`
for style/script/content-elements data) — return `false` from that filter to force a
fresh render every time (useful while debugging a template that isn't picking up
changes). **Naming inconsistency to watch for**: `Elementor_Content_Render`'s *script*
data cache uses a *different* filter name, `'jet-menu/elementor-render/render-data/use-cache'`
(`elementor-template-render.php:163`) — hooking only the `.../render/render-data/use-cache`
filter will bust the Elementor style-dependency cache but not its script-dependency cache.

Separately, `Render\Manager::generate_menu_raw_data()`'s per-item render payload is
filtered through `apply_filters('jet-plugins/render/render-data', $mega_render_data, $template_id, $item_content_type)`
(`render/manager.php:139`, also fired again in both walkers, e.g.
`mega-menu-walker.php:470`) — **note the `jet-plugins/` prefix, not `jet-menu/`**, an easy
typo target since virtually every other filter in this plugin uses the `jet-menu/` prefix.

Whole-render-data results are additionally cached as **transients**, keyed
`md5('jet_menu_elementor_template_data_' . $template_id)`, written via the plugin's own
`jet_set_transient()`/read via `jet_get_transient()` helpers (not core `set_transient`,
e.g. `render/walkers/mega-menu-walker.php:459-467`, `rest-api/endpoints/get-elementor-template-content.php:63-94`)
— controlled by the `use-template-cache` / `template-cache-expiration` options.

## The two walkers: `Mega_Menu_Walker` and `Vertical_Menu_Walker`

Both `extends \Walker_Nav_Menu` (core WP), live under `includes/render/walkers/`
(nextgen mode only — legacy mode uses different classes under `.../legacy/`: the legacy
desktop walker is named **`Main_Walker`**, not `Mega_Menu_Walker` — same
`Jet_Menu\Render` namespace, different class name, so a hardcoded
`Jet_Menu\Render\Mega_Menu_Walker` reference will not resolve on a legacy-mode site;
`legacy/vertical-menu-walker.php` does reuse the `Vertical_Menu_Walker` class name, so
that one name means two different implementations depending on mode).
`Mega_Menu_Walker::start_el()` (`includes/render/walkers/mega-menu-walker.php:143-530`)
decides per item whether it's a "mega" item via
`is_mega_enabled( $item_id )` → `jet_menu()->settings_manager->get_menu_item_settings( $item_id )['enabled']`
(`:616-620`), caches that decision per top-level item in
`wp_cache_set( 'item-type', $item_type, 'jet-menu' )` (`:586-600` — **process-local WP
object cache, not persisted**, recomputed every request), then constructs the
appropriate `Elementor_Content_Render`/`Block_Editor_Content_Render` and splices the
rendered content into a `<div class="jet-mega-menu-mega-container" data-template-id="...">`
wrapper (`:507`). `Vertical_Menu_Walker` (`includes/render/walkers/vertical-menu-walker.php:6`)
is the parallel implementation used by the "Custom Menu" Elementor widget
(`jet-custom-menu` widget type) — same mega-content logic, different wrapper markup
(`.jet-custom-nav__mega-sub`) and its own cache group `'jet-custom-menu'` (`:467-477`).

**Location-level render doesn't always reach the walker at all**: `Location::modify_pre_wp_nav_menu()`
(`includes/render/location.php:57-169`) hooks core's `pre_wp_nav_menu` filter and, for
any theme location JetMenu is enabled on, **entirely replaces** `wp_nav_menu()`'s output
with either a `Mobile_Menu_Render` or `Mega_Menu_Render` instance (chosen by
`is_mobile_render()`, `:174-196`) — the walker classes above are invoked *inside* those
renderers, not directly by core. See `jetmenu-extensibility` for the escape-hatch filter
that lets a location fall back to normal `wp_nav_menu()` behavior.

## Gotcha: `is_mega_enabled()` truthiness check differs between the two walkers

`Mega_Menu_Walker::is_mega_enabled()` (`mega-menu-walker.php:616-620`) uses
`filter_var( $item_settings['enabled'], FILTER_VALIDATE_BOOLEAN )`, while
`Vertical_Menu_Walker::is_mega_enabled()` (`vertical-menu-walker.php:485-489`) uses a
loose `'true' == $item_settings['enabled']` string comparison. A stored value of boolean
`true` (as opposed to the string `'true'`) would pass the mega-menu walker's check but
**fail** the vertical (Custom Menu widget) walker's check — worth knowing if a mega item
renders correctly in a `wp_nav_menu()` location but not inside the Custom Menu widget (or
vice versa).

## Gotcha: adding a 3rd content-type option doesn't make it render

`apply_filters('jet-menu/admin/content-type-options', [...])` (hooked by
`Elementor::modify_content_type_options()`, `elementor/manager.php:263-270`, to add the
`'elementor'` option to the admin dropdown) looks like the extension point for adding a
brand-new mega-menu content type. It only controls what shows up in the admin UI
dropdown, though — the actual render dispatch in `Render\Manager::generate_menu_raw_data()`
and both walkers is a hardcoded `switch ($content_type) { case 'default': ...; case
'elementor': ...; }` with no default/fallback case (e.g. `mega-menu-walker.php:398-433`).
Adding a third option via this filter makes it selectable but renders nothing — there is
no hook that lets a 3rd party plug a new `case` into that switch.

## REST API: `jet-menu-api/v2`

Namespace `jet-menu-api/v2` (`Rest_Api::$api_namespace`, `includes/rest-api/rest-api.php:28`)
— note it's `v2` despite no `v1` existing anywhere in this codebase, a naming trap for
anyone assuming `v1`.
Each endpoint is a class `extends \Jet_Menu\Endpoints\Base` (`includes/rest-api/endpoints/base.php:11`,
abstract `get_name()`/`callback()`). **The base class's default `permission_callback()` is
not a capability check — it's a signature check** (`base.php:41-52`): it reads `id` and
`signature` request params and compares `hash_equals()` against
`generate_signature( $template_id )` (`:111-116`, `md5( $unique_id . $template_id .
NONCE_KEY )`, where `$unique_id` is a persisted `jet_menu_unique_id` option created on
first use, `:91-101`). Each endpoint's real behavior:

- **`get-elementor-template-content`** (`GET`, `id`+`signature` required,
  `endpoints/get-elementor-template-content.php`) — uses the base signature check
  as-is; returns a `Elementor_Content_Render::get_render_data()` result, transient-cached.
- **`get-blocks-template-content`** — same shape (also inherits the base signature
  check unmodified), for Block Editor content.
- **`get-menu-items`** (`GET`, `endpoints/get-menu-items.php:78-80`) — **overrides
  `permission_callback()` to `return true` unconditionally** — this one is genuinely
  public with no signature needed. Returns `jet_menu()->render_manager->generate_menu_raw_data( $menu_id )`,
  transient-cached 24h keyed on `menu_id`.
- **`clear-cache`** (`POST`, `endpoints/clear-cache.php:36-38`) — overrides permission to
  `current_user_can( 'edit_posts' )`.
- **`plugin-settings`** (`POST`, `endpoints/plugin-settings.php:77-79`) — overrides
  permission to `current_user_can( 'manage_options' )`; writes the whole
  `jet_menu_options` option in one shot (merges posted keys over existing).

Register a 3rd-party endpoint via `do_action('jet-menu/rest/init-endpoints', $this)`
(`rest-api.php:71`, fires after all built-ins are registered, `$this` is the `Rest_Api`
instance — call `$this->register_endpoint( new My_Endpoint() )` from the callback).

## Gotchas

- **`Jet_Menu_Item_Document` is dead code**: `includes/elementor/document-types/jet-menu-item.php`
  defines a real `extends \Elementor\Core\Base\Document` class, but the line that would
  register it, `add_action('elementor/documents/register', ...)`, is commented out in
  `Elementor::__construct()` (`includes/elementor/manager.php:57`). `jet-menu` CPT posts
  are edited in Elementor using Elementor's own default document type, not this one —
  don't assume its custom `get_properties()`/`cpt` wiring is actually active.
- **`jet-menu/widgets/mega-menu/controls`** (used by `Settings_Manager::add_widget_settings()`
  to inject a Preset select control, `options.php:1506,993-1010`) only fires from the
  **legacy** Elementor mega-menu widget (`includes/elementor/widgets/legacy/jet-widget-mega-menu.php:136`)
  — the nextgen widget (`includes/elementor/widgets/jet-widget-mega-menu.php`) never
  calls `do_action` for this hook at all (confirmed by grep: zero `do_action`/
  `apply_filters` calls anywhere in that 3280-line file). A callback registered on this
  hook silently never runs in nextgen mode.
- `get_menu_item_settings()`'s per-item cache key collision risk: `Mega_Menu_Walker`
  nulls `$this->item_settings` at the very top of every `start_el()` call
  (`mega-menu-walker.php:146`, commented "Don't put any code before this!") specifically
  because the settings are memoized per walker-instance-lifetime, not per item — reading
  it earlier in a custom override would return the *previous* item's settings.

## How this was verified

Read `jet-menu.php` (bootstrap/singleton), `includes/menu-post-type.php`,
`includes/settings/manager.php`, `includes/settings/options.php`,
`includes/render/manager.php`, `includes/render/base-render.php`,
`includes/render/location.php`, `includes/render/render-modules/{elementor-template-render,block-editor-template-render}.php`,
`includes/render/walkers/{mega-menu-walker,vertical-menu-walker}.php`,
`includes/rest-api/rest-api.php`, `includes/rest-api/endpoints/{base,get-menu-items,get-elementor-template-content,clear-cache,plugin-settings}.php`,
`includes/elementor/manager.php`, and `includes/elementor/document-types/jet-menu-item.php`
directly, in JetMenu 3.0.2.1 source, confirming the CPT registration args, the
three-location storage split (nav-item post meta / CPT post / nav-menu term meta), the
`Base_Render`/`Elementor_Content_Render`/`Block_Editor_Content_Render` class hierarchy,
both walker classes' mega-detection logic, all five REST endpoints' real permission
models, and the commented-out document-type registration and legacy-only widget-controls
hook (all confirmed by direct file:line reading, not inferred from names). A parallel
research pass (same source, read independently) cross-checked and extended this with the
`Rest_Api`-has-no-property fact, the `Render\Manager`/`Blocks\Manager` redeclare-fatal
mechanics (the unconditional-`require` chain was traced precisely, correcting an initial
weaker "duplicates hooks" draft of this same claim), the legacy `Main_Walker` class name,
the `is_mega_enabled()` truthiness discrepancy, the `jet-plugins/render/render-data`
prefix inconsistency, and the `use-cache` filter-name split between style and script
data — every one of those corrections was re-confirmed against the live file:line before
being written down here. Not yet verified against a running site — JetMenu is not
installed on the sandbox (jackfruit.epeak.studio) this round; see `TEST-REGIMEN.md`.
