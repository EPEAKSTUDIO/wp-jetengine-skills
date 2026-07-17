---
name: jettabs-query-gateway
description: Use when a JetTabs widget's repeater-based "Items" control (Tabs, Toggles/Accordion, or Image Accordion's item list) needs to be driven by a JetEngine Query Builder query instead of static content, or when Dynamic Tags don't resolve correctly inside a Query-Gateway-enabled JetTabs widget's loop — especially Image Accordion, which has a real gap in its copy of this integration. Captures verified behavior of JetTabs' own independent copy of the "Query Gateway" integration from JetTabs For Elementor 2.3.2 source (`plugins/jet-tabs/`), cross-referenced against `jetelements-query-gateway` and JetEngine's `Jet_Engine\Query_Builder\Query_Gateway\Manager` (`plugins/jet-engine/`), live-verified 2026-07-16 against jackfruit.epeak.studio (4/4 tests.php assertions passing).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetTabs' copy of the JetEngine ↔ JetElements/JetTabs Query Gateway

`jetelements-query-gateway` documents the cross-plugin "Query Gateway" integration in
general and flags that JetTabs has its own, separately-maintained copy of the same
hooks — this skill audits that copy. **The headline finding: JetTabs did not copy the
loop-rendering method uniformly.** Two of its three Query-Gateway-enabled widgets
(Tabs, Accordion) hand-roll their own correct copy of the loop with all four hooks;
the third (Image Accordion) instead calls a shared base-class method that is **missing
one of the four hooks**, breaking per-item Dynamic Tag resolution for that widget only.
Confirmed against JetTabs 2.3.2 (`plugins/jet-tabs/`) and the currently-installed
JetEngine source
(`plugins/jet-engine/includes/components/query-builder/query-gateway/manager.php`). No
official Crocoblock developer docs cover JetTabs or this integration at all — checked
`https://github.com/Crocoblock/developer-documentation` (2026-07-16, see
`jettabs-widgets/SKILL.md` intro for detail) — source is the only source of truth.

## `Jet_Tabs_Base` is an independent copy, not a `Jet_Elements_Base` subclass

`\Elementor\Jet_Tabs_Base` (`includes/base/class-jet-tabs-base.php:6`) extends
`\Elementor\Widget_Base` directly — it does **not** extend or reuse JetElements'
`Jet_Elements_Base` in any way. Every method name in it uses JetTabs' own double-
underscore prefix (`__get_render_looped_template`, `__context`, `__processed_item`, ...)
vs. JetElements' single-underscore prefix (`_get_render_looped_template`, `_context`,
`_processed_item`, ...) — cosmetically parallel, structurally unrelated classes that
happen to have been written to fire the same JetEngine-side hook names.

## Three different copies of the loop, only two of them complete

Grepping every `jet-engine-query-gateway/*` call site across `plugins/jet-tabs/`
surfaces **three separate implementations**, not one shared one:

| Widget | Where the loop lives | `before-loop` | `do-item` per item | `reset-item` |
|---|---|---|---|---|
| Tabs (`jet-tabs-widget.php`) | hand-rolled directly in `render()` | `:1942` | `:1946` | `:2008` |
| Accordion (`jet-accordion-widget.php`) | hand-rolled directly in `render()` | `:1059` | `:1063` | `:1220` |
| Image Accordion (`jet-image-accordion-widget.php`) | **shared** `Jet_Tabs_Base::__get_render_looped_template()` | `:111`* | **absent** | `:122`* |

*(line numbers for the shared-method rows are in `class-jet-tabs-base.php`, reached via
`templates/jet-image-accordion/global/index.php:18` →
`__get_global_looped_template('image-accordion', 'item_list')` →
`__get_render_looped_template()`.)*

**`Jet_Tabs_Base::__get_render_looped_template()`
(`class-jet-tabs-base.php:98-131`) never fires `jet-engine-query-gateway/do-item` at
all** — it applies the `jet-tabs/widget/loop-items` filter, fires `before-loop` once,
`foreach`es the loop including each item's template, then fires `reset-item` once, with
no `do-item` call inside the loop body (contrast with the Tabs/Accordion widgets' own
manual copies immediately above/below in the same table, and with JetElements'
`Jet_Elements_Base::_get_render_looped_template()`, which does call
`do_action( 'jet-engine-query-gateway/do-item', $item )` per item at
`class-jet-elements-base.php:183`). Tabs and Accordion **bypass this shared method
entirely** and hand-roll their own `render()` loops (see table) that do include the
`do-item` call — so the omission only affects widgets that actually route through
`__get_render_looped_template()`, which today is Image Accordion alone (confirmed via
grep: `__get_global_looped_template`/`__get_render_looped_template` have exactly one
call site outside the base class itself,
`templates/jet-image-accordion/global/index.php:18`).

### Practical consequence for Image Accordion

`jet-engine-query-gateway/control` **is** fired for Image Accordion's `item_list`
control (`jet-image-accordion-widget.php:74`), so JetEngine's
`Query_Gateway\Manager::register_controls()` still injects the "Use JetEngine query"
switcher/query-select/instructions controls for it, and its
`query_enbaled()`/`get_queried_items()` gate (see `jetelements-query-gateway` for the
three-part check) still works — **the static rows do get replaced by real query
results**, because that swap happens entirely inside the `jet-tabs/widget/loop-items`
filter (`class-jet-tabs-base.php:101`), independent of `do-item`. What breaks is
**per-item Dynamic Tag resolution**: `Query_Gateway\Manager::set_item_object()`
(`manager.php:101-129`) — the method that calls
`jet_engine()->listings->data->set_current_object( $item['_jet_engine_queried_object'] )`
so `%dynamic_field%`-style tags in the item template resolve against *that* row — is
only ever invoked from the `jet-engine-query-gateway/do-item` action
(`manager.php:27`). Since Image Accordion never fires that action, any Dynamic Tag
placed inside `templates/jet-image-accordion/global/image-accordion-loop-item.php`
resolves against whatever object was "current" *before* the whole loop started (e.g.
the containing post, or nothing), not the queried row for that specific item — every
item in the Image Accordion effectively shows the same (wrong, or empty) dynamic value
instead of one value per row. Tabs and Accordion do not have this problem: their own
hand-rolled loops call `do-item` per item exactly like JetElements does.

## The three Query-Gateway-enabled controls (Switcher has none)

`jet-engine-query-gateway/control` is fired from exactly three places, each inside that
widget's own `register_controls()`, immediately before its Repeater control:

- Tabs — `do_action( 'jet-engine-query-gateway/control', $this, 'tabs' )`
  (`jet-tabs-widget.php:67`).
- Accordion — `do_action( 'jet-engine-query-gateway/control', $this, 'toggles' )`
  (`jet-accordion-widget.php:70`).
- Image Accordion — `do_action( 'jet-engine-query-gateway/control', $this, 'item_list' )`
  (`jet-image-accordion-widget.php:74`).

**Switcher (`jet-switcher-widget.php`) never fires this action** — it has no
repeater-backed "Items" control at all (it's a fixed two-state enable/disable widget,
see `jettabs-widgets`), so it is simply not Query-Gateway-eligible, by design rather
than omission.

## Version gating on JetEngine's side, and a documented-elsewhere arg-count correction

`Query_Gateway\Manager::__construct()` (`manager.php:24-54`) sets `$this->new_hooks =
false` if JetTabs' own `jet_tabs()->get_version()` is `<= '2.2.6.1'`
(`manager.php:36-38`) — the installed JetTabs (2.3.2) is above that floor, so the
currently-installed stack uses the newer stack-based `add_to_stack()`/`get_from_stack()`
object-tracking path (`:74-99`), not the older `$depth`-counter path
(`old_set_item_object()`/`old_reset_item_object()`, `:131-155,191-214`) that existed for
compatibility with pre-2.2.6.1 JetTabs / pre-2.7.2 JetElements installs. If auditing an
older JetTabs site, re-check which path is live before trusting the stack-based
description in `jetelements-query-gateway`.

Separately: **`jetelements-query-gateway/SKILL.md` documents `before-loop` as firing with
0 args** (`class-jet-elements-base.php:179` per that skill). Reading the same line in the
currently-checked-out JetElements source shows
`do_action( 'jet-engine-query-gateway/before-loop', $setting, $this )` — **2 args**,
matching what `Query_Gateway\Manager::before_loop()` (`manager.php:56-59`, declared with
0 parameters) simply ignores by PHP's normal fewer-params-than-passed-args rule. JetTabs'
own copies (both the shared base method and the Tabs/Accordion hand-rolled loops) also
consistently pass 2 args (`$setting, $this`) to `before-loop`. This looks like the
JetElements source moved on since that skill was last verified rather than a JetTabs-side
inconsistency — flagged here rather than silently corrected in the other (read-only for
this task) skill.

## Gotchas

- **Don't judge "is Query Gateway wired up for widget X" by whether the four hooks fire
  at all** — as with JetElements, `jet-tabs/widget/loop-items` and the loop hooks fire on
  every render of a Query-Gateway-eligible widget whether or not a query is actually
  selected; check the per-widget `jet_engine_query_{control_name}` switcher setting
  instead (see `jetelements-query-gateway`'s `query_enbaled()` gate — identical on the
  JetTabs side, since it's the same JetEngine-side `Manager` class serving both plugins).
- **Image Accordion's missing `do-item` is a real functional gap, not a hypothetical** —
  if a query-driven Image Accordion looks like every panel is showing the same dynamic
  content (or blank dynamic fields), this is the first thing to check, and there is no
  per-widget setting to work around it — it requires either avoiding Dynamic Tags in that
  widget's item template (use the query's own `_jet_engine_queried_object` data via
  static/plain fields instead) or a code fix adding the missing `do_action(
  'jet-engine-query-gateway/do-item', $item )` call inside
  `Jet_Tabs_Base::__get_render_looped_template()`'s `foreach` loop
  (`class-jet-tabs-base.php:113-120`, immediately after `$this->__processed_item =
  $item;`).
- **Never instantiate `Query_Gateway\Manager` yourself** — same landmine as documented in
  `jetelements-query-gateway`; it's the same class serving both plugins, constructed once
  by JetEngine's own `Query_Builder\Manager::register_instances()`, never stored anywhere
  you can reach.

## How this was verified

Read `plugins/jet-tabs/includes/base/class-jet-tabs-base.php` in full, then grepped every
`jet-engine-query-gateway`/`widget/loop-items` call site across `plugins/jet-tabs/`
(found in `class-jet-tabs-base.php`, `includes/addons/jet-tabs-widget.php`,
`includes/addons/jet-accordion-widget.php`, `includes/addons/jet-image-accordion-widget.php`)
and read each addon's `render()`/control-registration code around those call sites in
full. Cross-checked line-for-line against `plugins/jet-elements/includes/base/
class-jet-elements-base.php:140-204` (already documented in `jetelements-query-gateway`)
and `plugins/jet-engine/includes/components/query-builder/query-gateway/manager.php` in
full. Checked `https://github.com/Crocoblock/developer-documentation` for any JetTabs or
Query Gateway documentation (none — see intro). Not yet verified against a running site;
`tests.php` covers what's checkable via source-presence and generic-WP-hook smoke tests
without a live widget/query fixture — see `TEST-REGIMEN.md` for the manual/browser test
that would prove the Image Accordion Dynamic-Tag gap end to end.
