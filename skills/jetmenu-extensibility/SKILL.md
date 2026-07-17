---
name: jetmenu-extensibility
description: Use when extending JetMenu (Crocoblock's Elementor mega-menu plugin) — registering a custom Dynamic Visibility condition, customizing a mega-menu item's rendered HTML via the walker filters, reacting to AJAX-lazy-loaded mega content on the front end (the jet-menu/ajax/frontend-init JS events), registering a compatibility module for a 3rd-party plugin, or adding a custom block/REST endpoint through JetMenu's own registries. Captures verified behavior from JetMenu 3.0.2.1 source, live-verified 2026-07-16 against jackfruit.epeak.studio (7/7 tests.php assertions passing after one test-only fix — see TEST-REGIMEN.md).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetMenu Extensibility

Verified facts about JetMenu's real 3rd-party extension points — PHP registries, the
Dynamic Visibility custom-condition system, walker/render customization hooks, and the
front-end JS event API. Confirmed against JetMenu 3.0.2.1 source (`plugins/jet-menu/`).
See `jetmenu-structure` for the CPT/meta data model and render-class hierarchy these
hooks plug into.

## Safety first: four classes you must never re-instantiate

All four of these `require` (not `require_once`) their dependency files unconditionally
inside a constructor/method that the plugin bootstrap calls exactly once. A second
`new` anywhere else — including from a debug/test snippet — throws an uncatchable
"Cannot redeclare class" fatal:

- `\Jet_Menu\Compatibility\Manager` — `load_compatibility_modules()`
  (`includes/compatibility/manager.php:41-57`) `require`s each registered module's file.
  Bootstrapped once: `jet-menu.php:245`.
- `\Jet_Menu\Integration` — same pattern (`integration/manager.php:79`), bootstrapped
  once at `jet-menu.php:228`. **Extra trap**: it also exposes a `get_instance()`
  singleton method (`integration/manager.php:42-49`) that the real bootstrap never
  calls — calling `\Jet_Menu\Integration::get_instance()` yourself constructs a second
  instance (on top of the one already created via `new Integration()`), and that second
  instance's constructor re-`require`s every integration file → fatal.
- `\Jet_Menu\Blocks\Manager` — `register_block_types()`
  (`includes/blocks/manager.php:140-160`) `require`s `base.php` and every block class
  file. Same unused-`get_instance()` trap as `Integration` (`blocks/manager.php:27-35`
  vs. the real bootstrap's `new` at `jet-menu.php:237`). See `jetmenu-structure` for the
  full manager-reachability rule (`jet_menu()->blocks_manager`, never construct it
  yourself).
- `\Jet_Menu\Modules\Dynamic_Visibility\Dynamic_Visibility` — constructor
  (`includes/modules/dynamic-visibility/dynamic-visibility.php:12-22`) `require`s
  `context.php`/`checker.php`/`conditions/registry.php`. Bootstrapped once via a closure
  on `init` priority 20 (`:167-173`) — not by a named-method hook, so it can't be
  accidentally re-triggered by a `remove_action`/`add_action` on the same callback, but a
  direct `new` still fatals.

By contrast, `\Jet_Menu\Modules\Dynamic_Visibility\Conditions\Registry` and `Checker`
are safe to instantiate freely — `Registry::register_defaults()`
(`inc/conditions/registry.php:58-101`) uses `require_once` for every built-in condition
file, and `Checker::__construct()` (`inc/checker.php:16-18`) in fact constructs a fresh
`Registry` on every single visibility check with no ill effect.

## Dynamic Visibility: registering a custom condition needs 3 hooks, not 1

JetMenu's Dynamic Visibility module (distinct from JetEngine's own module of the same
name — no relation) lets a nav menu item be conditionally hidden/shown. A rule is
`{type, attrs}`; `Checker::check()` (`inc/checker.php:25-60`) ANDs/ORs each rule's result
via a `Base_Condition::check($rule, $context)` call looked up by `type` in a `Registry`.
Sixteen conditions ship built-in (user/page-context/WooCommerce). Adding a working custom
condition requires **all three** of these — missing any one means the condition either
never fires, or can't be saved from the admin UI at all:

1. **Register the checker class** — `do_action('jet-menu/modules/dynamic-visibility/register-condition', $registry)`
   (`inc/conditions/registry.php:106-114`, literally documented in the source's own
   PHPDoc example):
   ```php
   add_action( 'jet-menu/modules/dynamic-visibility/register-condition', function( $registry ) {
       $registry->register_condition( new My_Custom_Condition() );
   } );
   ```
   The class must `extends \Jet_Menu\Modules\Dynamic_Visibility\Conditions\Base_Condition`
   (`inc/conditions/base-condition.php:10`) and implement two abstract methods:
   `get_key()` (the rule-type string, e.g. `'my_condition'`) and
   `check( $rule, $context )` (returns bool; `$context` is a plain `Context` value object
   exposing `get('item')`/`get('menu_args')`, `inc/context.php`). Three protected helpers
   are available to subclasses: `get_rule_attrs( $rule )`, `get_rule_operator( $rule, $default = '=' )`,
   `get_rule_value( $rule, $key, $default = null )` (all read from `$rule['attrs']`).
   `register_condition()` (`registry.php:24-37`) silently no-ops if the object isn't a
   `Base_Condition` instance or `get_key()` is empty — no error is raised.
2. **Whitelist the rule type for saving** — `apply_filters('jet-menu/dynamic-visibility/allowed-rule-types', $allowed_types)`
   (`includes/settings/manager.php:834`). The admin-side sanitizer
   (`Settings_Manager::sanitize_dynamic_visibility()`, `manager.php:795-949`) drops any
   rule whose `type` isn't in this list (`:848`) **before** it ever reaches step 3 —
   register your condition in step 1 but skip this filter and the rule silently never
   gets saved when an editor picks it in the UI.
3. **Sanitize the rule's `attrs`** — `apply_filters('jet-menu/dynamic-visibility/sanitize-rule', [], $rule, $rule_type, $attrs)`
   (`manager.php:921-934`) is the fallback the sanitizer defers to for any rule type not
   one of the ~16 built-ins. Return `['type' => $rule_type, 'attrs' => [...]]`
   (sanitized) — returning an empty array or omitting `type` means the rule is discarded
   (`:923,929`).

## Compatibility & Integration registries — the same 2-filter pattern, twice

Both follow an identical shape: `apply_filters(..., ['slug' => ['class' => '\Fully\Qualified\Class', 'instance' => false, 'path' => '/abs/path/to/manager.php']])`,
then each entry's file is `require`d and the class `new`'d
(`load_compatibility_modules()`, present in both managers with the same name):

- **`jet-menu/compatibility-manager/registered-plugins`** (`includes/compatibility/manager.php:22-33`)
  — built-ins `jet-smart-filters` → `\Jet_Menu\Compatibility\Jet_Smart_Filters`,
  `jet-theme-core` → `\Jet_Menu\Compatibility\Jet_Theme_Core`. Add a third entry to wire
  up your own compatibility class the same way.
- **`jet-menu/integration-manager/registered-plugins`** (`integration/manager.php:55`) —
  same shape, for `integration/plugins/*` modules (JetFormBuilder, Elementor Header
  Footer, various themes, WPML/Polylang, WooCommerce, The Events Calendar).

**What the two built-in compatibility classes actually do** (both narrow, single-purpose
— don't expect a deep data integration):

- `Jet_Smart_Filters` (`includes/compatibility/plugins/jet-smart-filters/manager.php`) —
  bails unless `class_exists('\Jet_Smart_Filters')`. Hooks
  `elementor/frontend/widget/before_render` to detect whether a JetSmartFilters widget is
  present on the page (checks `$widget->get_categories()`), and only then conditionally
  `wp_enqueue_style('jet-smart-filters')` — pure asset-loading optimization, no query/data
  integration with JetMenu content at all.
- `Jet_Theme_Core` (`includes/compatibility/plugins/jet-theme-core/manager.php`) — bails
  unless `defined('JET_THEME_CORE_VERSION')`. Registers exactly one filter:
  `add_filter('jet-menu/mega-menu/location/prevent-modify-nav-menu', ..., 10, 4)`
  (`:39`), forcing `$prevent = true` whenever
  `jet_theme_core()->theme_builder->frontend_manager->is_theme_builder_render` is true —
  i.e. it stops JetMenu from overriding `wp_nav_menu()` output while JetTheme Core's own
  Theme Builder is actively rendering a header/footer template, to avoid the two systems
  fighting over the same menu location. This is the real consumer of the
  `prevent-modify-nav-menu` filter documented in `jetmenu-structure`.

**JetFormBuilder "integration" is a dead stub**: `integration/plugins/jet-form-builder/manager.php`'s
entire `Jet_Form_Builder::__construct()` is `if ( ! defined('JET_FORM_BUILDER_VERSION') ) { return false; }`
— no hooks, no methods beyond an empty `load_files()`. There is no dynamic wiring between
JetFormBuilder forms and menu items in this version; don't assume one exists.

**`integration/plugins/jet-menu/functions.php` is not JetMenu-integrating-with-itself** —
despite the directory name matching the plugin's own slug, its actual content is a
JetPopup compatibility filter (`add_filter('jet-popup/block-manager/not-supported-blocks', ...)`,
marking JetMenu's own `jet-menu/mega-menu`/`jet-menu/mobile-menu` Gutenberg blocks as
unsupported inside JetPopup). It only loads because `Integration`'s per-plugin loader
(`integration/manager.php:112-139`) checks whether any active plugin's path contains
`"{$dir}/"` where `$dir` is the subfolder name (here, literally `"jet-menu"`) — and since
JetMenu is always active while its own code runs, this folder always self-matches. It's
a coincidental self-reference, not a recursive integration.

## Customizing rendered mega-menu markup: the walker filters

Beyond the WP-core nav-menu filters (`nav_menu_css_class`, `walker_nav_menu_start_el`,
etc. — standard, not JetMenu-specific), `Mega_Menu_Walker` fires its own filters, all
passing the **walker instance itself** as an extra argument so a callback can inspect
per-item mega-menu state:

- `apply_filters('jet-menu/mega-menu-walker/start-el', $item_output, $item, $this, $depth, $args)`
  (`includes/render/walkers/mega-menu-walker.php:513`) and
  `.../end-el` (`:559`, same 5-arg signature minus `$item_output`→`$output`) — the
  **JetMenu-specific** pre-final-output filters, fired before the standard WP
  `walker_nav_menu_start_el` filter runs. Legacy-mode equivalents exist under a different
  name: `jet-menu/main-walker/start-el` / `end-el`
  (`includes/render/walkers/legacy/main-menu-walker.php:390,436`).
- `apply_filters('jet-menu/mega-menu-walker/dropdown-format', '<div class="jet-mega-menu-item__dropdown">%1$s</div>', $dropdown_icon)`
  (`mega-menu-walker.php:338-342`) and
  `apply_filters('jet-menu/mega-menu-walker/badge-format', '<div class="jet-mega-menu-item__badge">...</div>', $badge, $depth)`
  (`:664-669`) — wrapper-markup format strings.
- `do_action('jet-menu/mega-sub-menu/before-render', $item->ID)` /
  `'jet-menu/mega-sub-menu/after-render'` (`mega-menu-walker.php:482,490`) — fire
  immediately around the point where a mega item's rendered content HTML is assigned
  into the output. **Nuance**: these only fire on the **non-AJAX** render path — when
  the item's `ajax-loading` setting is on, content is instead lazy-loaded client-side via
  `templates/public/mega-content-loader.php` and these two hooks never fire server-side
  for that item (`mega-menu-walker.php:481-495`).
- The parallel pair for the "Custom Menu" Elementor widget's own walker uses a
  **differently-prefixed** action name — easy to hook the wrong one:
  `do_action('jet-menu/widgets/custom-menu/mega-sub-menu/before-render', $item->ID)` /
  `'...after-render'` (`includes/render/walkers/vertical-menu-walker.php:388,395`; legacy
  variant at `includes/render/walkers/legacy/vertical-menu-walker.php:261,268`).

**Legacy-widget-only hook**: `do_action('jet-menu/widgets/mega-menu/controls', $this)`
(`includes/elementor/widgets/legacy/jet-widget-mega-menu.php:136`, `$this` = the
`Widget_Base` instance, called from inside `_register_controls()`) — lets a callback
`$this->add_control(...)` its own Elementor controls onto the legacy mega-menu widget.
Confirmed by grep that the **nextgen** widget file
(`includes/elementor/widgets/jet-widget-mega-menu.php`, 3280 lines) contains **zero**
`do_action`/`apply_filters` calls anywhere — this hook, and any callback registered on
it, is a no-op on a nextgen-mode site.

## Registries for adding new blocks/REST endpoints

- **`apply_filters('jet-menu/block-manager/blocks-list', ['\Jet_Menu\Blocks\Mega_Menu' => $path, '\Jet_Menu\Blocks\Mobile_Menu' => $path])`**
  (`includes/blocks/manager.php:146-160`) — add your own `'\My\Namespace\My_Block' => '/abs/path/to/file.php'`
  entry; the class must `extends \Jet_Menu\Blocks\Base` (abstract `get_name()`/
  `get_attributes()`/`render_callback()`, `includes/blocks/blocks/base.php:67-81`) and
  registers itself under the `jet-menu/` Gutenberg block namespace
  (`base.php:9,47-57`). **Nextgen-mode only** — `Blocks\Manager`'s constructor bails
  entirely in legacy mode (`blocks/manager.php:168-170`).
- **`do_action('jet-menu/rest/init-endpoints', $this)`** (`includes/rest-api/rest-api.php:71`,
  `$this` = the `Rest_Api` instance) — call `$this->register_endpoint( new My_Endpoint() )`
  from the callback to add a custom route under the `jet-menu-api/v2` namespace. See
  `jetmenu-structure` for the `Endpoints\Base` class shape and its signature-based
  default permission model.

## The front-end JS event API

Three layers, easy to conflate — only the third is genuinely JetMenu's own reusable API:

1. **`elementorFrontend.hooks`** (standard Elementor widget-init pattern, not JetMenu- or
   JetPlugins-specific) — `frontend/element_ready/jet-mega-menu.default`,
   `frontend/element_ready/jet-custom-menu.default`,
   `frontend/element_ready/jet-mobile-menu.default`
   (`includes/elementor/assets/public/js/widgets-scripts.js:241`) fire once each
   Elementor widget instance initializes on the front end — the normal way to hook a
   custom Elementor widget's JS init, applied here to JetMenu's own three widgets.
2. **`window.JetPlugins.hooks`** — the shared cross-plugin JS framework referenced in
   this repo's `other-plugins-backlog/OTHER-PLUGINS.md`. **JetMenu is only a consumer,
   not an owner, of this framework** — confirmed exactly one call site in the shipped
   public bundle: `window.JetPlugins.hooks.doAction(window.JetPlugins.hookNameFromBlock(t.dataset.isBlock), jQuery(t))`
   (`assets/public/js/jet-menu-public-scripts.js`, fired once per `[data-is-block*="/"]`
   element found inside AJAX-loaded Block-Editor mega content) — `JetPlugins.hooks`
   itself and `hookNameFromBlock()` are defined elsewhere (a different Crocoblock
   plugin's bundle), not in `jet-menu`. Separately, JetMenu **listens** for a
   JetPopup-emitted signal via core `@wordpress/hooks`:
   `wp.hooks.addAction("jet-plugins.frontend.element-ready.jet-menu.mega-menu", "jet-popup", callback)`
   — reinitializes location/mobile render when JetPopup lazy-renders a `jet-mega-menu`
   element inside a popup. No `doAction` for that channel exists in JetMenu's own code —
   the emitter lives in JetPopup.
3. **JetMenu's own jQuery custom events on `window`** — the real, JetMenu-specific,
   reusable JS API for reacting to AJAX-lazy-loaded mega-menu content
   (`assets/public/js/jet-menu-public-scripts.js`), fired in this order once AJAX content
   has been injected:
   ```js
   jQuery(window).trigger('jet-menu/ajax/frontend-init/before', settingsObj);
   jQuery(window).trigger('jet-menu/ajax/frontend-init', settingsObj);
   jQuery(window).trigger('jet-menu/ajax/frontend-init/after', settingsObj);
   ```
   where `settingsObj` carries `{ $container, content, contentElements, contentType, ... }`.
   Subscribe the same way JetMenu's own internal listeners do:
   ```js
   jQuery(window).on('jet-menu/ajax/frontend-init/after', function( event, data ) {
       // data.$container is the jQuery-wrapped mega-content container that was just injected
   } );
   ```
   Additionally, a plain jQuery instance event `document.trigger('JetMegaMenuInited')`
   fires once per mega-menu widget instance after its own internal init completes — a
   "this specific menu instance is ready" signal distinct from the AJAX-content trio
   above (which fires only for lazy-loaded mega content, not on initial page load).

## No JetEngine-specific integration exists

Grepping the entire `includes/` tree for `jet-engine`/`Jet_Engine`/`jet_engine(` turns up
only two categories of match, neither a real integration: (1) `wp-dashboard-manager.php`
uses `jet_engine()` purely to group wp-admin sidebar menu items across installed
Crocoblock plugins (unrelated to mega-menu rendering); (2) several leftover
`esc_html__(..., 'jet-engine')` text-domain typos (copy-paste artifacts from a shared
module, not functional). **There is no JetMenu code for putting JetEngine dynamic
listing/macro content into a mega-menu column** — it works purely because a mega-menu
column is rendered as a generic Elementor or Gutenberg template
(`jetmenu-structure`'s render pipeline), and those template systems can contain a
JetEngine Listing Grid widget/block with zero JetMenu awareness of JetEngine at the data
layer. Don't look for a `jet-menu/jetengine-*` hook — none exists.

## Gotchas

- Registering something via `jet-menu/compatibility-manager/registered-plugins` or
  `jet-menu/integration-manager/registered-plugins` only works if your filter callback
  runs before `Manager`/`Integration`'s constructor reads the filtered array — both fire
  from inside `Jet_Menu::init()` (`init` priority `-999`), so a callback added later than
  priority `-999` on `init` (or added on a later hook entirely) is too late.
- The Blocks Style Manager module (`includes/modules/blocks-style-manager/`, shared
  cross-plugin `Crocoblock\Blocks_Style` namespace) is internal styling plumbing for
  JetMenu's own two Gutenberg blocks — it's loaded through the safe Cherry-X module
  loader (`require_once`-guarded, deduplicated by filename+version), unlike the four
  landmine classes above, but it exposes no documented 3rd-party extension point beyond
  the generic `register_block_type_args` it hooks internally.

## How this was verified

Two independent research passes read JetMenu 3.0.2.1 source directly and cross-checked
each other. Pass 1 confirmed the Dynamic Visibility module in full
(`includes/modules/dynamic-visibility/{dynamic-visibility,inc/checker,inc/context,inc/conditions/{registry,base-condition,user-role}}.php`)
and the sanitize/allowed-rule-types filters in `includes/settings/manager.php`. Pass 2
independently re-confirmed the same Dynamic Visibility files, then covered ground pass 1
didn't: `includes/compatibility/manager.php`,
`includes/compatibility/plugins/{jet-smart-filters,jet-theme-core}/manager.php`,
`integration/manager.php`, `integration/plugins/jet-form-builder/manager.php`,
`integration/plugins/jet-menu/functions.php`, `includes/blocks/manager.php`,
`includes/blocks/blocks/base.php`, the shipped JS bundles
(`assets/public/js/jet-menu-public-scripts.js`,
`assets/public/js/jet-menu-mobile-menu-public-scripts.js`,
`includes/elementor/assets/public/js/widgets-scripts.js`), and a full-tree grep for
`jet-engine`/`Jet_Engine` references confirming no JetEngine-specific integration
exists. Not yet verified against a running site — JetMenu is not installed on the
sandbox (jackfruit.epeak.studio) this round; see `TEST-REGIMEN.md`.
