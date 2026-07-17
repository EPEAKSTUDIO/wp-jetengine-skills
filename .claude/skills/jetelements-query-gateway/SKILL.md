---
name: jetelements-query-gateway
description: Use when a JetElements widget's repeater-based "Items" control (Carousel slides, Portfolio items, Timeline steps, chart data points, etc.) needs to be driven by a JetEngine Query Builder query instead of static content, or when debugging why JetEngine Dynamic Tags don't resolve correctly inside a JetElements widget's loop. Captures verified behavior of the cross-plugin "Query Gateway" integration from JetElements 2.9.1.2 and JetEngine's `Jet_Engine\Query_Builder\Query_Gateway\Manager` source, live-verified 2026-07-16 against jackfruit.epeak.studio (4/4 tests.php assertions passing after two test-only fixes — see TEST-REGIMEN.md).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetElements ↔ JetEngine Query Gateway

Verified facts about the one genuine, non-obvious cross-plugin integration between
JetElements and JetEngine: a small set of `jet-engine-query-gateway/*` hooks that let
**any** Elementor widget with a Repeater-based "Items" control swap its static repeater
rows for the results of a JetEngine Query Builder query, with JetEngine Dynamic Tags
resolving per-item exactly as they do inside a real JetEngine Listing Grid. Confirmed
against JetElements 2.9.1.2 (`plugins/jet-elements/`) and the currently-installed
JetEngine source (`plugins/jet-engine/`).

## Who owns what — this is JetEngine's feature, JetElements just cooperates

The hooks live in JetElements' widget base class, but the class that actually *listens*
to them is entirely on the JetEngine side:
`Jet_Engine\Query_Builder\Query_Gateway\Manager`
(`plugins/jet-engine/includes/components/query-builder/query-gateway/manager.php`).
JetEngine's own `Query_Builder\Manager::register_instances()` does a bare
`new Query_Gateway\Manager;` (`plugins/jet-engine/includes/components/query-builder/manager.php:300`)
every time it runs, on JetEngine's own `init` bootstrap — **the instance is never stored
on any property**, so there is no accessor to reach "the" Query Gateway manager object
at all, by design or by accident. Because of that, and because its constructor
registers a batch of `add_action`/`add_filter` calls with no idempotency guard, treat it
the same as the `Storage\Controller` landmine documented in `jetsmartfilters-query`:
**never construct `new Jet_Engine\Query_Builder\Query_Gateway\Manager()` yourself** —
doing so on a request where JetEngine has already run its own `init` bootstrap
double-registers every one of its hook callbacks, which silently corrupts JetEngine's
internal "current listing object" stack (used by Dynamic Tags) rather than fataling
outright — arguably worse than a crash because it fails quietly. Verify its presence
via `has_action()`/`has_filter()` on the hook names below, never by instantiating it.

## The four hooks, fired from `Jet_Elements_Base`'s own loop renderer

`Elementor\Jet_Elements_Base::_get_render_looped_template()`
(`plugins/jet-elements/includes/base/class-jet-elements-base.php:140-204`) is the shared
loop-rendering method every repeater-backed widget calls (Carousel, Portfolio, Timeline,
Team Member, Testimonials, Bar/Line Chart, etc — anything using `_get_global_looped_template()`).
It fires, in this order, per render:

1. **`jet-elements/widget/loop-items`** (filter, 3 args: `$loop`, `$setting`, `$this`
   [the widget instance]) — `:165` — applied to `get_settings_for_display( $setting )`
   **before** the loop runs. This is the actual swap point: JetEngine's
   `Query_Gateway\Manager` hooks this exact filter name (registered per-plugin-slug —
   `add_filter( $plugin_slug . '/widget/loop-items', ... )` for both `'jet-tabs'` and
   `'jet-elements'`, `manager.php:46-48`) and replaces `$loop` with the query's items
   when that control is Query-Gateway-enabled (see "Enabling a control" below).
2. **`jet-engine-query-gateway/before-loop`** (action, 0 args) — `:179` — fired once,
   right before iterating `$loop`. JetEngine's handler stashes the *current* JetEngine
   listing object (if any — e.g. this widget is itself rendered inside a JetEngine
   Listing Grid item) onto an internal stack (`Query_Gateway\Manager::before_loop()`,
   `manager.php:56-59`) so it can be restored after the loop ends.
3. **`jet-engine-query-gateway/do-item`** (action, 1 arg: `$item`) — `:183` — fired once
   per loop item, **before** that item's template is included. If `$item` carries a
   `_jet_engine_queried_object` key (only true when the loop came from a real Query
   Gateway query — see below), JetEngine's handler sets it as the "current object" so
   `%dynamic_field%`-style tags in the item's template resolve against that row
   (`Query_Gateway\Manager::set_item_object()`, `manager.php:101-129`).
4. **`jet-engine-query-gateway/reset-item`** (action, 0 args) — `:195` — fired once
   after the loop finishes, restoring whatever object was current before the loop
   started (`Query_Gateway\Manager::reset_item_object()`, `manager.php:163-189`, plus
   `after_loop()` at `:61-64,42` popping the before-loop stash back off the stack).

**A widget that never enables Query Gateway support still fires all four hooks on
every normal render** — `$item['_jet_engine_queried_object']` is simply absent for
plain repeater rows, so `set_item_object()`/`reset_item_object()` no-op for those items.
This is why the hooks are safe to leave firing unconditionally rather than being gated
behind a "is Query Gateway enabled" check in JetElements' own code.

## Enabling a control — the fifth hook, fired from `_register_controls()`

`jet-engine-query-gateway/control` (action, 2 args: `$this` [widget instance],
`$control_name` [the Repeater control's own name, e.g. `'image_list'`]) is fired by a
widget **inside its own `_register_controls()`**, immediately before adding the actual
Repeater control — e.g. `includes/addons/jet-elements-portfolio.php:91`,
`do_action( 'jet-engine-query-gateway/control', $this, 'image_list' )`. JetEngine's
`register_controls()` handler (`manager.php:26,335-374`) reacts by:

- recording `$widget_name => $control_name` in its own internal map
  (`store_widget_data()`, `:325-333`, used later to gate which controls are even
  eligible — `is_control_supported()`, `:285-291`);
- injecting three extra Elementor controls right there, before the Repeater: a
  `jet_engine_query_{$control_name}` SWITCHER ("Use JetEngine query"), a
  `jet_engine_query_id_{$control_name}` SELECT populated from
  `Query_Builder\Manager::instance()->get_queries_for_options()`, and a `RAW_HTML`
  instructions block — both new controls named by string-concatenating the control
  name, not a fixed name (`:339-372`).

**A JetEngine Query Builder query only actually replaces the repeater's static rows
when all three are true** (`query_enbaled()`, `manager.php:225-237`): the switcher is
on, a query is selected, **and** the Repeater control itself still has at least one
static row (`$control_val` non-empty) — the UI's own instructions say to leave exactly
one static row as the "field map" template (`get_instructions_message()`, `:376-387`):
JetEngine reads that one row's raw settings as a *field-mapping* template
(`$widget->parse_dynamic_settings( $fields_map, $control['fields'], $fields_map )`,
`get_queried_items()`, `:239-283`) and re-applies it once per query result row, not as
literal content.

## Building a custom Query-Gateway-compatible widget

To make a from-scratch Elementor widget's own Repeater control support this (not
required reading to *use* Carousel/Portfolio/etc, only to build a new widget that
should support it too): fire `do_action( 'jet-engine-query-gateway/control', $this,
$control_name )` right before `$this->add_control( $control_name, [...] )` (repeater),
then wrap the loop exactly like `_get_render_looped_template()` does — apply
`{plugin_slug}/widget/loop-items` to the settings array, fire `before-loop` once,
`do-item`/loop-body/`reset-item` per iteration in that order, `reset-item` once at the
end. Copying `Jet_Elements_Base::_get_render_looped_template()`
(`includes/base/class-jet-elements-base.php:140-204`) verbatim is the safe pattern —
it's the one JetEngine's own compatibility code was written against.

## Gotchas

- **Never instantiate `Query_Gateway\Manager` yourself** (see "Who owns what" above) —
  this is a silent-corruption risk (duplicate hook registration), not a fatal, which
  makes it easy to miss in testing.
- **The four loop hooks fire on every widget render regardless of whether Query Gateway
  is enabled for that instance** — presence of the hook firing is not itself evidence a
  query is wired up; check `$item['_jet_engine_queried_object']` (per-item) or the
  three `jet_engine_query*` control values (per-widget) instead.
- **The Repeater's one remaining static row is not decorative** — deleting it entirely
  once "Use JetEngine query" is enabled breaks the mapping (`query_enbaled()` requires
  `$control_val` non-empty, `manager.php:231`), because that row is read back as the
  dynamic-tag field-mapping template, not skipped.
- **`jet-elements/widget/loop-items` and `jet-tabs/widget/loop-items` are separate
  filters JetEngine registers identically for each plugin** (`manager.php:46-48`) — a
  fix applied to one does not automatically apply to the other; JetTabs has its own,
  unaudited copy of this same integration (out of scope for this repo — see
  `.claude/skills/_other-plugins-backlog/OTHER-PLUGINS.md`).

## How this was verified

Read `plugins/jet-elements/includes/base/class-jet-elements-base.php` in full (the
`_get_render_looped_template()`/`_get_global_looped_template()` methods) and grepped
every `includes/addons/*.php` call site of `jet-engine-query-gateway/control` (found in
`jet-elements-portfolio.php:91`, plus `advanced-map.php`, `advanced-carousel.php`,
`animated-text.php`, `bar-chart.php`, `horizontal-timeline.php`, `image-comparison.php`,
`images-layout.php`, `line-chart.php`, `price-list.php`, `slider.php`, `testimonials.php`,
`timeline.php`), then read
`plugins/jet-engine/includes/components/query-builder/query-gateway/manager.php` in
full and `plugins/jet-engine/includes/components/query-builder/manager.php:280-300` to
confirm the owning/instantiation side. Not yet verified against a running site —
JetElements was not installed on the sandbox as of this writing (see
`TEST-REGIMEN.md`); `tests.php` drives the two genuinely generic-WP-hook pieces
(`jet-elements/widget/loop-items`, `jet-engine-query-gateway/control`) live via plain
`apply_filters()`/`do_action()` calls (no widget/query fixture needed), and treats
everything that requires a real widget/query object as source-presence-only, per the
instantiation risk noted above.
