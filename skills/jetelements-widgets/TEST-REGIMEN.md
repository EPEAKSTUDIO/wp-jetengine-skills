# Test regimen: jetelements-widgets

Validates claims in `SKILL.md`. Run against the sandbox site once JetElements For
Elementor (2.9.1.2, requires Elementor active) is installed.

## Run log — 2026-07-16: live-verified, 7/7 pass

JetElements was activated on the sandbox later the same session. Deployed `tests.php` as
snippet id 37 (`AGENT-TEST-SUITE: jetelements-widgets`), ran via
`GET /agent-test/v1/suite/jetelements-widgets` — **7/7 pass on first run**, no fixes
needed.

**To make this regimen runnable:** confirm JetElements + Elementor are both active
(`resource-get-website-config` should list both), deploy `tests.php` as a new Code
Snippets snippet ("AGENT-TEST-SUITE: jetelements-widgets"), run it via
`GET /agent-test/v1/suite/jetelements-widgets`, then work through Tests 1-3 below (the
things `tests.php` can't cover — see each test's note).

## Prerequisites

- Elementor active, JetElements active, at least one page edited with the Elementor
  editor (to exercise editor-only hooks like `elementor/controls/controls_registered`
  and the per-widget include/exclude-controls filters in a real widget context).
- Debug log sink (see `docs/test-regimen-guide.md`).

## Test 1: widget registration is gated by `avaliable_widgets`, not hardcoded

**Claim being tested:** `SKILL.md` "Widget/addon registration pipeline" — a widget
addon is skipped only when `avaliable_widgets[$slug]` is explicitly set falsy; an
unset/never-saved settings array loads every widget.

**Setup:** in the JetElements Settings page (Available Widgets tab), disable one
widget (e.g. Advanced Carousel), save.

**Trigger:** open the Elementor editor's widget panel.

**Expected observable:** the disabled widget no longer appears in the panel; re-enabling
it and reloading brings it back.

**Pass criteria:** matches the on/off toggle exactly — not automatable via `tests.php`
because it requires driving the real Elementor editor UI (a widget-panel visual check,
not something assertable from a single PHP request).

## Test 2: `jet-elements/editor/{widget}/include-controls` affects a real widget instance

**Claim being tested:** `SKILL.md` "Per-widget dynamic control filters" — the filter
name is genuinely per-widget-slug and is read inside `Jet_Elements_Base::__construct()`.

**Setup:**
```php
add_filter( 'jet-elements/editor/jet-carousel/include-controls', function( $controls, $widget_name, $widget ) {
    error_log( '[JEW-TEST] include-controls fired for ' . $widget_name );
    return $controls;
}, 10, 3 );
```

**Trigger:** add an Advanced Carousel widget to a page in the Elementor editor.

**Expected observable:** log line fires with `$widget_name === 'jet-carousel'`.

**Pass criteria:** confirms the filter fires for a real widget instantiation, not just
that `apply_filters()` is callable in isolation (which `tests.php`'s `jew-5` already
covers generically, without a real widget) — needs the real Elementor editor to
construct an actual `Jet_Elements_Base` subclass.

## Test 3: `-skin` stylesheet toggle is visible on the front end

**Claim being tested:** `SKILL.md` "Assets" — `jet-elements/assets/css/default-theme-enabled`
gates whether `{addon}-skin` stylesheets load at all.

**Setup:**
```php
add_filter( 'jet-elements/assets/css/default-theme-enabled', '__return_false' );
```

**Trigger:** view a page with a JetElements widget (e.g. Pricing Table) on the front end.

**Expected observable:** the widget renders with only its structural CSS (`jet-elements`
+ `{addon}` handles), visibly missing its default skin styling (colors/spacing from the
`-skin` stylesheet).

**Pass criteria:** visual/network-tab confirmation the `{addon}-skin` `<link>` tag is
absent — a rendering check, not automatable from a single PHP request.

## Not yet automated in `tests.php` — see suite comments for why

- The registration-pipeline landmine (`register_addons()`/`register_addon()` re-`require`
  risk) is deliberately **not** live-triggered by `tests.php` — confirmed by reading
  source (`require`, not `require_once`) rather than by reproducing the fatal, unlike
  the analogous `jetsmartfilters-query` landmine which had already been hit for real
  before being documented. If a future session wants to actually confirm this fatal
  occurs, do it in an isolated, deactivated diagnostic snippet only (per `HANDOFF.md`'s
  guidance), never in the real suite.
- The carousel-options pattern (5 filters) is source-presence-only in `tests.php`
  (`jew-7`) since triggering any of the `get_*_options()` methods needs a fully
  Elementor-constructed widget instance with real `get_settings()` data — a fixture
  this sandbox doesn't have yet. A future session with a real page containing an
  Advanced Carousel widget could add a live test hooking
  `jet-elements/jet-carousel/carousel-options` and asserting the mutation appears in
  the rendered page's inline JS config.
