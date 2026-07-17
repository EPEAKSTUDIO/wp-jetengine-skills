# Test regimen: jetsearch-widgets-extensibility

Validates claims in `SKILL.md`. Run against the sandbox site (jackfruit.epeak.studio),
which has JetSearch 3.6.1.3 and Elementor/Elementor Pro active. **Bricks Builder is NOT
installed on this sandbox** — every Bricks-specific claim in `SKILL.md` is
source-verified only, not exercised by `tests.php`; see "Bricks — source-verified only"
below for the manual/future steps that would close that gap once Bricks is installed.

## Run log

**2026-07-17**: Deployed `tests.php` as Code Snippets snippet id 80 ("AGENT-TEST-SUITE:
jetsearch-widgets-extensibility"), active. First run: **10/13** (`fatal_error: null` —
the suite itself ran cleanly, three individual assertions failed):

- `jsw-4` failed with `Call to undefined method Elementor\Controls_Manager::get_control_types()`
  — **test-only bug**: that method doesn't exist on this Elementor version's
  `Controls_Manager`. Fixed by switching to `get_control( 'jet-search-query' )` (returns
  the single control instance directly, the correct real API).
- `jsw-11`/`jsw-12` both failed with `class_ready: false` /
  `Jet_Search_Macros_Current_Results` undefined — **test-only bug, same lazy-load-behind-
  a-gate shape this repo has hit before** (`jetengine-modules`' `Stores\Factory`,
  `jetengine-rest-api`'s Relations `Public_Controller`). Root cause: JetEngine's own
  macro registry (`Jet_Engine_Listings_Macros::init()`,
  `includes/components/listings/macros.php:28-44`, in the JetEngine source tree) only
  fires `do_action( 'jet-engine/register-macros' )` — the hook
  `Jet_Search_Compatibility_JE` uses to register `Jet_Search_Macros_Current_Results` —
  on the **first real macro lookup** (`get_all()`/`do_macros()`), not at plugin boot.
  Since this REST-request suite never triggers a real macro lookup itself, the class was
  never declared. Fixed by calling `jet_engine()->listings->macros->init()` directly
  before the `class_exists()` check — a real, idempotent, safe entry point (guarded by
  its own `$initialized` flag) rather than reaching around the lazy-load with a manual
  file require.

Second run after both fixes: **12/13** — new failure:

- `jsw-4` still failed, this time on the `is_a()` check itself: actual class was
  `Jet_Search_Control_Query` (global namespace), not `Elementor\Jet_Search_Control_Query`
  as the test assumed. **Test-only bug** — re-reading `elementor-views/controls/query.php`
  confirms it has **no** `namespace Elementor;` declaration at the top despite the class
  extending `Elementor\Control_Select2` — the class genuinely lives in the global
  namespace. `SKILL.md`'s own claim was already written correctly (no `Elementor\` prefix
  on the class name); only the test's `is_a()` string was wrong. Fixed by checking
  against `'Jet_Search_Control_Query'`.

Third run: **13/13 pass, clean**. No real plugin/doc bugs found this round — every
failure was a test-side wrong-API-assumption or a lazy-init-ordering gap, not a
mismatch between `SKILL.md`'s claims and actual plugin behavior.

## Prerequisites

- JetSearch 3.6.1.3 active, Elementor/Elementor Pro active.
- The always-active `AGENT-TEST-CORE harness` snippet (id 22).
- Bricks Builder NOT installed (the expected, current state of this sandbox) — several
  tests (`jsw-8`) specifically assert on this *absence* being handled cleanly, not on
  Bricks itself working.

## Automated coverage (`tests.php`)

- **`jsw-1`** — `jet_search()` resolves to `Jet_Search`; `has_elementor()` is `true`;
  `jet_search_integration()` resolves to a real `Jet_Search_Integration` instance,
  proving `init()` ran past its `has_elementor()` gate (`jet-search.php:183-190,218-220`).
- **`jsw-2`** — both Elementor widgets are registered with their real classes. Forces
  Elementor's own lazy widget-type `require` via
  `\Elementor\Plugin::instance()->widgets_manager->get_widget_types()` **before** any
  other check, same fix this repo's `jetblog-widgets-extensibility/TEST-REGIMEN.md`
  documents (Elementor's lazy-load race can otherwise throw an uncatchable "Cannot
  redeclare class" fatal if something else `require`s the same widget file first).
  Deliberately does **not** manually `new` a widget instance, per
  `HANDOFF.md`'s Elementor `Widget_Base` constructor-fragility lesson.
- **`jsw-3`** — the `'cherry'` Elementor category (both widgets' `get_categories()`)
  really is labeled `"JetElements"` in the registered category data, not a
  misread/typo.
- **`jsw-4`** — the `jet-search-query` custom control (`Control_Select2` subclass) is
  registered with Elementor's controls manager.
- **`jsw-5`** — `jet-search/widget/loop-items`: source-presence check (grepping
  `widget-base.php` for the literal `apply_filters` call) **plus** a direct
  `apply_filters()` mechanism test — deliberately not instantiating
  `Jet_Search_Widget_Base` or calling `__get_render_looped_template()` directly, per the
  "prefer testing the underlying hook mechanism" lesson.
- **`jsw-6`** — both Gutenberg blocks (`jet-search/ajax-search`,
  `jet-search/search-suggestions`) are registered in `WP_Block_Type_Registry`,
  confirming block registration really is unconditional (doesn't depend on Elementor
  being active).
- **`jsw-7`** — `jet-search/ajax-search/blocks-views/attributes`: direct filter
  mechanism test (not re-triggering real `register_block_type()`, which WP would refuse
  to run twice for the same block name mid-request).
- **`jsw-8`** — `Jet_Search_Bricks_Integration` is loaded/constructed (class exists,
  singleton resolves) but `has_bricks()` is `false` and no Bricks element class was ever
  declared, confirming the "always-constructed, internally-gated" bootstrap asymmetry
  documented in `SKILL.md` — this is the one test that specifically depends on Bricks
  **not** being installed; if Bricks is ever installed on this sandbox, this assertion's
  `expected` values need inverting (see "Bricks" section below).
- **`jsw-9`** — `jet_search()->search_sources` is a real `Search_Sources\Manager` with
  the two built-in sources (`terms`, `users`) registered under those exact keys.
- **`jsw-10`** — `jet-search/sources/register`: direct `do_action()` mechanism test with
  the real, already-constructed `Manager` instance passed through (not re-triggering the
  real `init`-priority-99 registration pass, which already ran once this request).
- **`jsw-11`** — `Jet_Search_Macros_Current_Results` contract: `macros_tag()` ===
  `'jet_search_current_results'`, `macros_name()` === `'Current JetSearch Results'`,
  `macros_args()` === `[]`. Instantiated directly (safe — no unconditional `require` in
  its constructor, no singleton collision risk) rather than re-firing
  `jet-engine/register-macros`.
- **`jsw-12`** — `macros_callback()` returns `''` (not an exception/error) on this
  REST-request context, which carries no search query param — the concrete proof of the
  "request-driven, not render-driven" gotcha in `SKILL.md`.
- **`jsw-13`** — the Elementor (`jet-search/ajax-search/add-custom-controls`) and Bricks
  (`jet-search/ajax-search-bricks/add-custom-controls`) custom-controls hooks are
  genuinely distinct strings, both with a real listener already registered by
  `Jet_Search_Compatibility::init()`.

## Bricks — source-verified only (Bricks Builder not installed on this sandbox)

The following `SKILL.md` claims cannot be live-tested until Bricks Builder is installed
and activated here. They're reasoned from full reads of
`includes/bricks-views/integration.php`, `includes/bricks-views/elements/{base,ajax-search,
search-suggestions}.php`, and `includes/bricks-views/helpers/{options-converter.php,
controls-converter/*.php}` — not automated, not manually spot-checked against a live
Bricks editor:

- `\Bricks\Elements::register_element()` actually registers both element classes with
  Bricks' own registry, under the exact `$name` slugs (`jet-search-ajax-search`,
  `jet-search-search-suggestions`) and `$category` (`jetsearch`) declared.
- `Jet_Search\Bricks_Views\Elements\Base`'s control-group DSL
  (`register_jet_control_group()`/`register_jet_control()`) actually populates Bricks'
  real `$controls`/`$control_groups` properties in a way the Bricks builder UI renders
  correctly (structurally plausible from reading `\Bricks\Element`'s expected property
  shapes, not confirmed against a running Bricks editor).
- `Options_Converter::convert()` produces control arrays Bricks actually accepts for
  every source `'type'` value a real Search Source might declare (`text`, `select`,
  `switcher`, `repeater`, `icon` all have explicit converters; anything else falls back
  to `Control_Default`, whose actual on-screen behavior in the Bricks builder is
  unverified).
- `jet-search/bricks-views/element/parsed-attrs` and
  `jet-search/bricks-views/register-elements` really fire at the times/with the
  arguments `SKILL.md` describes, in the context of a live Bricks page render/builder
  session (as opposed to the direct-invocation confirmation `tests.php` could do for the
  Elementor/Blocks equivalents).
- The `jet-search/ajax-search-bricks/add-custom-controls` hook actually surfaces an
  added control in the real Bricks element panel (as opposed to `jsw-13`, which only
  confirms the hook name exists and has a listener attached).

**Manual steps once Bricks is installed**: activate Bricks Builder, add a JetSearch Ajax
Search element to a Bricks page, confirm both control groups (`section_search_form_settings`,
..., `section_search_form_style`, ...) render under the expected Content/Style tabs;
hook `jet-search/bricks-views/register-elements` with a throwaway custom element and
confirm it appears in the Bricks element panel under the "jetsearch" category; hook
`jet-search/ajax-search-bricks/add-custom-controls` and confirm the added control
appears in the element's Content panel, distinct from confirming the same for the
Elementor widget via `jet-search/ajax-search/add-custom-controls`.

## Not yet automated (Elementor/Blocks side, non-Bricks)

- **Blocks Style Manager (`\Crocoblock\Blocks_Style\Manager`) actually renders controls
  in the block editor's native Style sidebar.** `tests.php` doesn't confirm
  `jet_search_register_style_for_block()`'s returned proxy object actually produces a
  working block-editor UI panel — that's a visual/editor-UI check, not something
  assertable from a single PHP REST request. Manual steps: open the block editor, add a
  JetSearch Ajax Search block, open its Style tab, confirm the "Search Form" section's
  Background Color control changes the rendered background.
- **`add_additional_results_controls()`/`add_sources_controls()` actually surface a
  registered custom Search Source's controls in the Elementor widget panel and (once
  Bricks is installed) the Bricks element panel.** `jsw-9`/`jsw-10` confirm the registry
  and the extension hook fire correctly, but not that a *new* source class's
  `editor_general_controls()` array actually renders as usable Elementor/Bricks controls
  end to end — would need a throwaway custom source class registered live and the
  widget/element panel opened in the editor.
- **The `jet_ajax_search_query_settings` global-defaults option actually flows into both
  the Elementor widget's control defaults and the Gutenberg block's attribute defaults
  identically.** Reasoned from source (`\Jet_Search_Tools::prepared_settings_for_elementor()`/
  `prepared_settings_for_blocks()`) but not live-tested with a real saved option value,
  since that would require exercising the admin Ajax Search Settings subpage UI.
