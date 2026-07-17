---
name: jetsearch-widgets-extensibility
description: Use when registering/gating a JetSearch widget, block, or Bricks element (Ajax Search / Search Suggestions), overriding its default controls across Elementor/Gutenberg/Bricks, or hooking JetSearch's own extension points (per-platform "add-custom-controls" hooks, the block-attributes filter, the Bricks controls-converter, the `%jet_search_current_results%` JetEngine macro). Captures verified behavior of JetSearch 3.6.1.3 source (`includes/elementor-views/`, `includes/blocks-views/`, `includes/bricks-views/`, `includes/compatibility/jet-engine/`), live-verified 2026-07-17 against jackfruit.epeak.studio (Elementor/Gutenberg/macro paths, 13/13 tests.php assertions passing; Bricks not installed on this sandbox — source-verified only, see TEST-REGIMEN.md).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetSearch Widget/Block/Element Registration & Extensibility

Verified facts about how JetSearch registers its two search UIs — **Ajax Search** and
**Search Suggestions** — across three independent page-builder platforms (Elementor,
Gutenberg blocks, Bricks), plus the plugin's real extensibility surface and its
cross-plugin JetEngine `%macro%` integration. Confirmed against JetSearch 3.6.1.3 source
(`plugins/jet-search/`). This skill does **not** cover the search-query pipeline
(AJAX handlers, `WP_Query`/`WP_User_Query`/`WP_Term_Query` building — see
`jetsearch-query-pipeline`) or the suggestions data model (logging/popular/manual
suggestion storage — see `jetsearch-suggestions`); it only covers how the widgets/
block/element get *registered* and how their control UI is *extended*.

## The one big picture fact: three platforms, one shared render class

Every registration path below — Elementor widget, Gutenberg block, Bricks element —
ultimately constructs the **same** render object and calls `->render()` on it:
`\Jet_Search_Render` (backs Ajax Search) or `\Jet_Search_Suggestions_Render` (backs
Search Suggestions), both defined under `includes/renders/` (out of scope here — see
`jetsearch-query-pipeline`/`jetsearch-suggestions`). Confirmed at three call sites:
`elementor-views/widgets/ajax-search.php:6390` (`new \Jet_Search_Render(
$this->get_settings_for_display(), $this->get_id() )`),
`elementor-views/widgets/search-suggestions.php:1821` (`new
\Jet_Search_Suggestions_Render(...)`), `blocks-views/integration.php:240,257`
(`search_render_callback()`/`search_suggestions_render_callback()`, same two classes
constructed from block `$attributes` instead of Elementor settings), and
`bricks-views/elements/ajax-search.php:6197` (`new \Jet_Search_Render( $settings,
$element_id )` inside `render()`, `:6175-6202`). **The only thing that differs per
platform is how the settings array is collected and how its control UI is presented** —
the actual search-form/results HTML is generated identically everywhere. Extending
JetSearch's *behavior* (as opposed to its control UI) almost always means hooking the
shared render classes, not the platform-specific registration code this skill documents.

## Bootstrap: three different gating strategies for the same three integrations

`Jet_Search::init()` (`jet-search.php:183-209`) wires up all three platform
integrations, but each is gated differently — a real, easy-to-miss asymmetry:

- **Elementor** — gated at the call site itself: `if ( $this->has_elementor() ) {
  require ... 'includes/elementor-views/integration.php'; jet_search_integration()->init();
  }` (`jet-search.php:187-190`). `has_elementor()` is `defined( 'ELEMENTOR_VERSION' )`
  (`:218-220`). If Elementor isn't active, the entire `Jet_Search_Integration` class
  never even gets `require`d.
- **Gutenberg blocks** — `require` happens unconditionally in `load_files()`
  (`jet-search.php:244`), and `jet_search_blocks_integration()->init()` is called
  unconditionally too (`:195`) — Gutenberg blocks register **regardless of which page
  builder (if any) is active**, since block registration is core WP, not
  builder-specific.
- **Bricks** — also `require`d and `->init()`-called unconditionally
  (`jet-search.php:260,196`), but `Jet_Search_Bricks_Integration::init()` gates
  **internally**, first line: `if ( ! $this->has_bricks() ) { return; }`
  (`bricks-views/integration.php:40-42`), where `has_bricks()` is `defined(
  'BRICKS_VERSION' )` (`:94-96`). So unlike Elementor, the Bricks integration class is
  always loaded and constructed on every request — only its actual element
  registration (`register_elements()`, hooked on `init` priority 10) is skipped when
  Bricks isn't installed. **This sandbox does not have Bricks Builder installed**, so
  everything in the "Bricks" section below is source-verified only — see
  `TEST-REGIMEN.md`.

## Elementor: two widgets, one shared (but plugin-owned) base class

`Jet_Search_Integration` (`includes/elementor-views/integration.php:20`, singleton via
`jet_search_integration()`, `:195-202,212-214`) wires registration on Elementor's own
hooks in `init()` (`:35-54`), version-gated the same way `jetelements-widgets` documents
for JetElements — Elementor `>= 3.5.0` uses `elementor/widgets/register`, older versions
use `elementor/widgets/widgets_registered` (`:47-51`).

`register_widgets()` (`:79-86`) `require`s `includes/elementor-views/base/widget-base.php`
once, then `glob()`s every file in `includes/elementor-views/widgets/*.php` and calls
`register_widget( $file, $widgets_manager )` for each. `register_widget()` (`:95-111`)
derives the Elementor class name from the **filename**, not any docblock: dashes→spaces→
`ucwords()`→underscores, wrapped as `Elementor\Jet_Search_%s_Widget` — e.g.
`ajax-search.php` → `Elementor\Jet_Search_Ajax_Search_Widget`. `require $file;` then
instantiates via `$widgets_manager->register( new $class )` (current Elementor API) or
`register_widget_type()` (pre-3.5.0 fallback) — same dual-API shape as JetElements. As of
3.6.1.3 there are exactly **two** widget files, both under `namespace Elementor`:

| File | Class | `get_name()` slug | `get_help_url()` |
|---|---|---|---|
| `widgets/ajax-search.php` | `Jet_Search_Ajax_Search_Widget` (`:24,33-35`) | `jet-ajax-search` | `crocoblock.com/knowledge-base/article-category/jet-search/` (`:46-48`) |
| `widgets/search-suggestions.php` | `Jet_Search_Search_Suggestions_Widget` (`:22,31-33`) | `jet-search-suggestions` | `.../jet-search-suggestions/` (`:44-46`) |

Both register under Elementor category `'cherry'` (`get_categories()` returns
`array( 'cherry' )` in each widget) — `register_category()`
(`elementor-views/integration.php:61-71`, hooked `elementor/elements/categories_registered`)
adds that category with the UI label **"JetElements"**, not "JetSearch" — a real, verified
naming artifact carried over from the shared Crocoblock Elementor-category convention,
not a typo to "fix" when reading the editor's category dropdown.

### The shared widget base class

Both widgets extend `\Elementor\Jet_Search_Widget_Base extends Widget_Base`
(`includes/elementor-views/base/widget-base.php:6`) — this is **JetSearch's own**
base class (parallel to, but structurally unrelated from, JetElements'
`Jet_Elements_Base` or JetTabs' `Jet_Tabs_Base`; none of these three plugins share a
base class with each other despite the similar naming convention). Its contract:

- `$__context` (`'render'`/`'edit'`), `$__processed_item`, `$__processed_index`,
  `$__new_icon_prefix` (`:8-11`) — the same "one property set, three parallel method
  families dispatched via `call_user_func( [ $this, "__{context}_..." ] )`" pattern
  used across the Jet* Elementor-widget ecosystem.
- `__get_render_looped_template()` (`:83-112`) applies
  **`apply_filters( 'jet-search/widget/loop-items', $loop, $setting, $this )`**
  (`:86`) to a **repeater-typed control's** value before looping it (e.g. the meta
  fields repeater added by `add_meta_controls()`, not the AJAX search results
  themselves — those come from a separate AJAX round trip handled by the render
  classes, out of scope here). This is the one loop-extension filter available on the
  Elementor side; there is no equivalent hook on the Blocks or Bricks paths, since
  neither platform uses this base class.
- `get_help_url()` defaults to `false` (`:13-15`) but both real widgets override it
  with a real knowledge-base URL (table above).

### Custom control: `jet-search-query`

`Jet_Search_Control_Query extends \Elementor\Control_Select2`
(`includes/elementor-views/controls/query.php:5`, `get_type()` returns
`'jet-search-query'`, `:7-9`) — a thin Select2 subclass used for the AJAX-populated
Include/Exclude Terms/Posts pickers (`widgets/ajax-search.php:538-579`, `action:
'jet_search_get_query_control_options'`). Registered via `add_controls()`
(`elementor-views/integration.php:119-135`), hooked `elementor/controls/controls_registered`
— version-gated the same `register()` vs `register_control()` way as widget
registration. `include_control( $class_name, $grouped = false )` (`:144-162`) resolves
the control's file path from its class name (`Jet_Search_Control_Query` →
`controls/query.php`) the same filename-derivation pattern as widget registration; a
grouped control (none currently ship) would resolve from `controls/groups/` instead.

### The two widgets' real extension points

- **`do_action( 'jet-search/ajax-search/add-custom-controls', $this, $default_query_settings )`**
  (`widgets/ajax-search.php:667`) — fired inside `register_controls()`, right after the
  built-in Search Query section's controls and before `limit_query`. This is where
  `Jet_Search_Compatibility`'s WooCommerce branch adds the `catalog_visibility` switcher
  control (`includes/compatibility.php:49,241-254`) — the real, working example of this
  hook. Search Suggestions' `register_controls()` has **no equivalent action** — only
  Ajax Search is extensible this way.
- **`add_additional_results_controls( $section, $source )`**
  (`widgets/ajax-search.php:6370-6380`) — for each registered Search Source (see
  "Search Sources" below), pulls `$source->editor_general_controls()[$section]` and
  registers each as a plain Elementor control via `$this->add_control( $key, $control )`.
  This is how a **custom search source** (registered via `jet-search/sources/register`,
  see below) gets its own settings surfaced inside the "Additional Results" section of
  the Ajax Search widget — the source class defines Elementor-shaped control arrays,
  this method just forwards them.
- **`apply_filters( 'jet-search/ajax-search/css-scheme', [...] )`**
  (`widgets/ajax-search.php:72-135`) and the equivalent
  `'jet-search/search-suggestions/css-scheme'`
  (`widgets/search-suggestions.php:70-93`) — remap every BEM-style CSS selector the
  widget's style controls target, keyed by short logical names (`'form'`,
  `'results_area'`, `'submit'`, ...). Overriding one entry redirects every style
  control that references it, without needing to override each control individually.

## Gutenberg: block.json-based, always registered, a fully separate control surface

`Jet_Search_Blocks_Integration` (`includes/blocks-views/integration.php:20`, singleton
via `jet_search_blocks_integration()`, `:425-432,442-444`). `init()` (`:38-49`) requires
two `blocks-styles/*.php` files, hooks `register_block_type()` on `init` priority `999`,
and wires the plugin's own **Blocks Style Manager** module into
`enqueue_block_editor_assets` (below). `register_block_type()` (`:87-139`) is
**block.json-based** for both blocks — not the older `register_block_type( $name, $args )`
signature — reading `includes/blocks-views/blocks/{ajax-search,search-suggestions}-block.json`
directly:

- `jet-search/ajax-search` — `ajax-search-block.json` (`:114-131`). Its `attributes`
  schema (~90 keys, `ajax-search-block.json:10-350`) mirrors the Elementor widget's
  control names almost 1:1 (`search_placeholder_text`, `limit_query`, `listing_id`, ...),
  confirming the settings are meant to be structurally interchangeable across platforms
  even though nothing enforces that at runtime. Defaults are further overridden at
  registration time from the site-wide `jet_ajax_search_query_settings` option via
  `\Jet_Search_Tools::prepared_settings_for_blocks()` (`:117-123`) — the same "global
  defaults" option the Elementor widget reads via `prepared_settings_for_elementor()`
  (see "Settings" below). The final attributes array is filterable:
  **`apply_filters( 'jet-search/ajax-search/blocks-views/attributes', $attributes )`**
  (`:129`) — the block-registration equivalent of the Elementor
  `add-custom-controls` action, but reshapes the whole attributes array at once rather
  than adding one control at a time.
- `jet-search/search-suggestions` — `search-suggestions-block.json` (`:133-138`), no
  attributes filter applied at all — an asymmetry with Ajax Search worth knowing before
  assuming every JetSearch block has a matching attributes filter.

Both blocks' `render_callback` (`search_render_callback()`/
`search_suggestions_render_callback()`, `:237-269`) wrap the shared render classes (see
top of this doc) in a `<div data-is-block="jet-search/...">` marker, incrementing
`$this->rendered` each call (used only as the render-instance id passed to the render
class, not for anything else).

### The Blocks Style Manager — a *third*, separate styling system

`get_style_manager()` (`:56-80`) lazily constructs
`\Crocoblock\Blocks_Style\Manager` from a Cherry-X module bundled at
`includes/modules/blocks-style-manager/` (loaded via `jet_search()->module_loader`, the
`Jet_Search_CX_Loader` wired in `jet-search.php:117-127`). This is **not** the Elementor
Style tab or the Bricks Style tab — it's JetSearch's own block-editor-native "Style"
sidebar panel for the Gutenberg block editor, populated by
`blocks-styles/ajax-search.php`'s `ajax_search_block_add_style()` and
`blocks-styles/search-suggestions.php`'s `search_suggestions_block_add_style()`
(both hooked on plain `init`, priority 10, `integration.php:43-44`). The public entry
point for registering a **new** block's style controls into this same system is
`jet_search_register_style_for_block( $name )` (`integration.php:453-474`) — calls
`get_style_manager()->register_block_support( $name )` then returns
`get_proxy( $name )`, the object every `blocks-styles/*.php` file calls
`->start_section()`/`->add_control()`/`->add_responsive_control()` on (same control-array
shape as Elementor's, just a different manager object underneath — confirmed by
comparing `blocks-styles/ajax-search.php`'s `search_form_bg_color` control against the
Elementor widget's control of the same id: same `css_selector`/`type` semantics,
different method names on the two managers).

## Bricks: a completely independent element base class and control DSL

`Jet_Search_Bricks_Integration::register_elements()`
(`includes/bricks-views/integration.php:69-92`, hooked `init` priority 10, only reached
if `has_bricks()` — see "Bootstrap" above) requires `elements/base.php` and six
`helpers/controls-converter/*.php` files, then calls **`\Bricks\Elements::register_element( $file )`**
— Bricks' own native registration API, a real API call rather than the manual
glob+require+`new`+`register()` dance Elementor/Blocks both hand-roll. Two element
files, `elements/ajax-search.php` and `elements/search-suggestions.php` (`:81-84`).
`do_action( 'jet-search/bricks-views/register-elements' )` fires immediately after
(`:90`) — the extension point for registering a third custom Bricks element alongside
JetSearch's own two.

### `Base extends \Bricks\Element` — unrelated to the Elementor base class

`Jet_Search\Bricks_Views\Elements\Base` (`elements/base.php:11`) extends Bricks' own
`\Bricks\Element` directly — **it shares no code or inheritance with
`\Elementor\Jet_Search_Widget_Base`**, same "parallel, cosmetically-similar, structurally
independent base classes per platform" pattern this repo has already documented for
JetTabs vs. JetElements (`jettabs-query-gateway`). `Base` layers a small
control-registration DSL on top of Bricks' own `$controls`/`$control_groups` properties
— `register_jet_control_group()`/`start_jet_control_group()`/`end_jet_control_group()`
(`:39-72`) track a "current group/tab" so `register_jet_control( $name, $data )`
(`:81-92`) can auto-stamp `$data['tab']`/`$data['group']` onto every control without the
element class repeating them — but the underlying arrays it writes into
(`$this->controls[$name]`, `$this->control_groups[$name]`) are Bricks' own real
properties, not a shim; Bricks reads them directly. `get_jet_settings( $setting,
$default )` (`:106-121`) reads a saved value, falling back to the control's own
registered `'default'` if unset — Bricks doesn't do this fallback itself.
`parse_jet_render_attributes( $attrs )` (`:123-125`) applies **`apply_filters(
'jet-search/bricks-views/element/parsed-attrs', $attrs, $this )`** — a Bricks-only
attribute post-processing hook with no Elementor/Blocks equivalent, called from each
element's own `render()` right before constructing the shared render class (e.g.
`elements/ajax-search.php:6179`).

### Two element classes, same slugs/names as their Elementor/block counterparts

`Jet_Search_Bricks_Ajax_Search` (`elements/ajax-search.php:14`, `$name =
'jet-search-ajax-search'`, category `'jetsearch'`) and
`Jet_Search_Bricks_Search_Suggestions` (`elements/search-suggestions.php:15`, `$name =
'jet-search-search-suggestions'`) both set `$jet_element_render` to `'ajax-search'` /
`'search-suggestions'` respectively — used only as a `data-is-block` marker attribute in
`render()` (`base.php:141`, matching the Elementor/block markup convention above), not
as a file lookup key.

### The controls-converter family — bridging Search Source controls into Bricks' control shape

`Jet_Search\Bricks_Views\Helpers\Options_Converter::convert( $args )`
(`helpers/options-converter.php:12-43`) maps a JetEngine/Elementor-shaped control
definition's `'type'` (`text`/`textarea`→`Control_Text`, `jet-query`/`select`→
`Control_Select`, `switcher`→`Control_Checkbox`, `repeater`→`Control_Repeater`,
`icons`/`icon`→`Control_Icon`, anything else→`Control_Default`) into a Bricks-shaped
control array, each `Controls_Converter\*` class implementing
`parse_callback_arguments( $args )` (base contract: `helpers/controls-converter/base.php:7-9`).
This is **not** dead code — `add_sources_controls( $section, $source )`
(`elements/ajax-search.php:6419-6431`) is the Bricks-side equivalent of the Elementor
widget's `add_additional_results_controls()`: it pulls the same
`$source->editor_general_controls()` array every Search Source class defines, runs each
control through `Options_Converter::convert()`, and calls
`$this->register_jet_control( $key, $control )` — meaning **a custom Search Source's
controls only need to be defined once, in one Elementor-shaped array**; both the
Elementor widget and the Bricks element independently convert/consume that same array
(the Gutenberg block instead gets the fully pre-flattened array via
`Jet_Search_Blocks_Integration::get_sources_controls()`,
`blocks-views/integration.php:280-353`, then a plain JS `additionalSourcesControls`
localized value — a third, separate consumption path for the same source data).

### Ajax Search's custom-controls hook has a *different name* on Bricks than on Elementor

`do_action( 'jet-search/ajax-search-bricks/add-custom-controls', $this,
$default_query_settings )` (`elements/ajax-search.php:939`) is the Bricks analog of
Elementor's `jet-search/ajax-search/add-custom-controls` (above) — **not the same hook
name** (`-bricks` is inserted into the middle of the string, not appended). Confirmed
both are hooked independently by the same WooCommerce-compatibility class:
`Jet_Search_Compatibility::add_custom_controls()` on the Elementor hook and
`::add_bricks_custom_controls()` on the Bricks hook (`includes/compatibility.php:49,62-63,
241-269`) — a developer adding a custom Ajax Search control that should show up on
**both** platforms must hook both action names separately; there is no shared/aliased
hook. Gutenberg has neither action — it only gets the attributes-filter mechanism
described above.

## Search Sources — the registry that widget/block/element controls all read from

`\Jet_Search\Search_Sources\Manager` (`includes/search-sources/manager.php:11`,
constructed once as `jet_search()->search_sources` at `jet-search.php:201`, **not** a
`get_instance()` singleton like everything else in this plugin — don't call `new` on it
a second time). `register_search_sources()` (hooked `init` priority 99, `:16,19-29`)
requires and registers two built-ins, `Terms` and `Users`
(`source-terms.php`/`source-users.php`), then fires **`do_action( 'jet-search/sources/register',
$this )`** (`:28`) — the extension point for a custom "Additional Results" source,
registered via `$manager->register_source( new My_Source() )` (`:41-43`). Every
registered source's `editor_general_controls()` method is what all three platforms'
"Additional Results"/`section_additional_results` control sections pull from (see the
Elementor/Bricks/Blocks sections above) — implementing a new source class is therefore
the single change that adds a control section to all three UIs at once, without touching
any platform-specific registration code in this skill.

## JetEngine compatibility: a `%macro%`, not a Query Gateway

**This is a `%macro%` registration, structurally unrelated to the "Query Gateway"
four-hook pattern documented in `jetelements-query-gateway`/`jettabs-query-gateway`** —
JetSearch does not feed a repeater-backed widget control from a JetEngine Query Builder
query; instead it exposes its *own* current search results as data JetEngine's macro
engine can consume anywhere `%macro%` tokens are parsed (Listing Grid filters, dynamic
visibility conditions, etc).

`Jet_Search_Compatibility_JE` (`includes/compatibility/jet-engine/manager.php:14`) is
constructed unconditionally inside `Jet_Search_Compatibility::init()`
(`includes/compatibility.php:66-70`), itself gated on `function_exists( 'jet_engine' )`
at both the outer call site and again in the class's own constructor (`manager.php:19-23`,
double-gated, harmless). Its constructor hooks `jet-engine/register-macros`
(`:26`) — JetEngine's real macro-registration hook (see `jetengine-listings-macros`),
firing `register_macros()` (`:36-41`), which requires
`compatibility/jet-engine/macros/current-results.php` and does `new
Jet_Search_Macros_Current_Results()`.

`Jet_Search_Macros_Current_Results extends Jet_Engine_Base_Macros`
(`macros/current-results.php:11`) implements the standard three-method contract
(`macros_tag()` → `'jet_search_current_results'`, `:18-21`; `macros_name()` →
`'Current JetSearch Results'`, `:28-31`; `macros_callback( $args = [] )`, `:53-65`) and
deliberately declares an **empty** `macros_args()` (`:38-41`) — per
`jetengine-listings-macros`' documented gotcha, a macro's `|pipe,args%` syntax is
silently dropped unless `macros_args()` declares a matching schema, and this macro takes
none by design (`%jet_search_current_results%` is used bare).

**`macros_callback()` does not read anything cached from a live widget render** — it
calls `jet_search_ajax_handlers()->get_current_results_ids()`
(`current-results.php:55`, defined `ajax-handlers.php:2776-2839`), which **rebuilds the
search from the current request** if `$this->current_results_ids` isn't already
populated in-process: reads the search string from `$_REQUEST[
$this->get_custom_search_query_param() ]` (`ajax-handlers.php:2784-2787`), builds
`WP_Query` args from the widget's saved global settings
(`build_current_results_query_args()`), checks a request-keyed object cache
(`get_current_results_cache_key()`/`wp_cache_get()`, `:2805-2817`, TTL filterable via
`apply_filters( 'jet-search/current-results-cache-ttl', HOUR_IN_SECONDS, ... )`,
`:2834`), and only then runs a real `fields => 'ids'` `WP_Query`
(`:2819-2823`). **Practical consequence**: `%jet_search_current_results%` only resolves
to something non-empty on a request that actually carries the search query param (e.g.
the search-results page itself, or a request URL with `?s=...`-equivalent) — evaluating
it from an unrelated admin/cron context returns an empty string (`macros_callback()`'s
`empty( $ids )` branch, `:57-59`), not an error.

## Settings — where widget-level global defaults live (brief)

`\Jet_Search\Settings` (`includes/class-jet-search-settings.php` for the low-level
option accessor, `includes/settings/manager.php` for the admin subpage UI) stores
site-wide defaults two ways relevant here: `get_option( 'jet_ajax_search_query_settings' )`
feeds `\Jet_Search_Tools::prepared_settings_for_elementor()`/
`prepared_settings_for_blocks()`, which both the Elementor widget
(`widgets/ajax-search.php:154-156`) and the Gutenberg block
(`blocks-views/integration.php:117-123`) read as their **control defaults** — set these
once in the admin "Ajax Search Settings" subpage rather than per-widget-instance to
shorten generated search-result URLs. `\Jet_Search\Settings` itself (constructed via
plain `new` — not `get_instance()` — only when `is_admin()`, `jet-search.php:205-208`)
registers two subpage modules (`Suggestions`, `Ajax_Search_Settings`) filterable via
`apply_filters( 'jet-search/settings/registered-subpage-modules', [...] )`
(`settings/manager.php:45-54`) — admin-UI plumbing, not re-documented in depth here.

## Extensibility filter/action catalog (widget/registration-relevant subset)

Curated from grepping every `jet-search/` `apply_filters`/`do_action` call across
`includes/` — this list is the subset that matters for customizing
registration/control-UI/loop behavior; it deliberately excludes the query-building and
suggestions-storage filters documented by the other two JetSearch skills.

| Hook | Type | Where | Purpose |
|---|---|---|---|
| `jet-search/widget/loop-items` | filter | `elementor-views/base/widget-base.php:86` | Reshape a repeater-control's items before the Elementor base class loops them. |
| `jet-search/ajax-search/add-custom-controls` | action | `elementor-views/widgets/ajax-search.php:667` | Add Elementor controls to Ajax Search's Search Query section. |
| `jet-search/ajax-search-bricks/add-custom-controls` | action | `bricks-views/elements/ajax-search.php:939` | Bricks analog of the above — **different hook name**, must hook separately. |
| `jet-search/ajax-search/blocks-views/attributes` | filter | `blocks-views/integration.php:129` | Reshape the whole Gutenberg block attributes array at registration time. |
| `jet-search/ajax-search/css-scheme` | filter | `elementor-views/widgets/ajax-search.php:72` (+ blocks-styles equivalent) | Remap logical CSS-selector keys the widget's style controls target. |
| `jet-search/search-suggestions/css-scheme` | filter | `elementor-views/widgets/search-suggestions.php:70` | Same, for the Search Suggestions widget. |
| `jet-search/bricks-views/element/parsed-attrs` | filter | `bricks-views/elements/base.php:124` | Post-process a Bricks element's resolved settings right before render. Bricks-only. |
| `jet-search/bricks-views/register-elements` | action | `bricks-views/integration.php:90` | Register a custom Bricks element alongside JetSearch's own two. |
| `jet-search/sources/register` | action | `search-sources/manager.php:28` | Register a custom "Additional Results" search source — feeds all three platforms' control UIs at once. |
| `jet-search/settings/registered-subpage-modules` | filter | `settings/manager.php:45` | Add/remove an admin settings subpage module. |
| `jet-engine/register-macros` (JetEngine's own hook) | action | `compatibility/jet-engine/manager.php:26` | Where `%jet_search_current_results%` self-registers. |

## Gotchas

- **Elementor gates at the call site; Blocks and Bricks don't.** `jet-search.php` only
  `require`s/`init()`s the Elementor integration if `has_elementor()`; Blocks and Bricks
  integrations are always constructed, with Bricks self-gating one level deeper inside
  `init()`. Don't assume a missing builder means the corresponding integration class was
  never loaded — for Bricks specifically, it was, it just no-oped.
- **Three unrelated base classes, one naming convention.** `Jet_Search_Widget_Base`
  (Elementor), the Gutenberg path (no base class — plain `register_block_type()`
  callbacks), and `Jet_Search\Bricks_Views\Elements\Base` (Bricks) share no code. Don't
  port a fix/override from one platform's base class expecting it to affect another.
- **The Ajax Search custom-controls hook has a different literal name per platform**
  (`jet-search/ajax-search/add-custom-controls` vs.
  `jet-search/ajax-search-bricks/add-custom-controls`) and **Search Suggestions and
  Gutenberg have no equivalent action at all** (Gutenberg gets an attributes-array filter
  instead). Verified via `Jet_Search_Compatibility::init()` hooking both action names
  independently for the same WooCommerce feature.
- **`%jet_search_current_results%` is request-driven, not render-driven.** It doesn't
  read state left behind by a widget's last AJAX render — it re-derives (or hits a
  request-keyed object cache for) the search results from the *current* request's query
  param every time it's evaluated. Evaluating it on a request with no search query param
  present returns an empty string, not the last-known results.
- **`jet_search()->search_sources` is a plain property, not a `get_instance()`
  singleton** — don't `new \Jet_Search\Search_Sources\Manager()` again; its constructor
  re-hooks `register_search_sources()` on `init`, which would `require` the same
  `Terms`/`Users` source files a second time (plain `require`, not `require_once`,
  `manager.php:22-23`) and re-run `register_source()` — likely a "Cannot redeclare
  class" fatal on any request past the first, following the same pattern this repo has
  hit for real elsewhere (`jetsmartfilters-query`'s `Storage\Controller`,
  `jetelements-widgets`' `register_addon()`) — reasoned from source here, not
  independently live-triggered.
- **`\Jet_Search\Settings` is likewise bootstrapped with a bare `new`**
  (`jet-search.php:207`), not its own `get_instance()`, and only when `is_admin()` —
  same category of risk as above if ever called a second time from a snippet.
- **Bricks-specific claims in this skill are source-verified only** — Bricks Builder is
  not installed on the sandbox this repo tests against (Elementor/Elementor Pro are
  active). See `TEST-REGIMEN.md`.

## How this was verified

Read `jet-search.php` (bootstrap/gating), `includes/elementor-views/integration.php`,
`includes/elementor-views/base/widget-base.php`,
`includes/elementor-views/controls/query.php`,
`includes/elementor-views/widgets/{ajax-search,search-suggestions}.php` (control
registration + `render()` in full), `includes/blocks-views/integration.php`,
`includes/blocks-views/blocks/ajax-search-block.json`,
`includes/blocks-views/blocks-styles/ajax-search.php`,
`includes/bricks-views/integration.php`, `includes/bricks-views/elements/base.php`,
`includes/bricks-views/elements/{ajax-search,search-suggestions}.php`,
`includes/bricks-views/helpers/options-converter.php`,
`includes/bricks-views/helpers/controls-converter/base.php`,
`includes/search-sources/manager.php`, `includes/settings/manager.php`,
`includes/compatibility.php`, `includes/compatibility/jet-engine/manager.php`, and
`includes/compatibility/jet-engine/macros/current-results.php` in full, in JetSearch
3.6.1.3 source (`plugins/jet-search/`). Grepped every `jet-search/` `apply_filters`/
`do_action` call site across `includes/` to build the extensibility catalog above and
confirm the Elementor-vs-Bricks custom-controls hook naming asymmetry. Cross-checked the
macro contract shape (`macros_tag`/`macros_name`/`macros_args`/`macros_callback`) against
`jetengine-listings-macros/SKILL.md`'s documented `Jet_Engine_Base_Macros` base class, and
the "parallel unrelated base classes per platform" framing against `jetelements-widgets/
SKILL.md` and `jettabs-query-gateway/SKILL.md`.

**Live-verified 2026-07-17** against `jackfruit.epeak.studio` (JetSearch 3.6.1.3 active,
Elementor/Elementor Pro active, **Bricks Builder not installed**): runnable suite
`tests.php` deployed as Code Snippets snippet id 80 ("AGENT-TEST-SUITE:
jetsearch-widgets-extensibility") — **13/13 assertions passing** after fixing two
test-only bugs and one test-only assumption (see `TEST-REGIMEN.md`'s Run log; no
plugin/doc bugs found). Elementor widget-registration assertions confirm registration via
`\Elementor\Plugin::instance()->widgets_manager->get_widget_types()` (forcing Elementor's
lazy widget-type load first, per this repo's `jetblog-widgets-extensibility/TEST-REGIMEN.md`
lesson) rather than manually `new`-ing a widget instance; macro/filter assertions call
`apply_filters()`/`do_action()` directly rather than requiring a live widget render, per
this repo's documented Elementor `Widget_Base` constructor-fragility lesson
(`HANDOFF.md`). Every Bricks-specific claim above is marked source-verified-only in
`TEST-REGIMEN.md` and not exercised by `tests.php`.
