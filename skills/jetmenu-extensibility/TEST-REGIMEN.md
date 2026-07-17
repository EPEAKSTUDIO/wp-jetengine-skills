# Test regimen: jetmenu-extensibility

Validates claims in `SKILL.md`. Run against the sandbox site once JetMenu is installed.

## Run log — 2026-07-16: UNBLOCKED, live-verified (7/7 pass, one test-only bug fixed)

JetMenu 3.0.2.1 is now active on jackfruit.epeak.studio. Deployed `tests.php` as Code
Snippets snippet id 43 ("AGENT-TEST-SUITE: jetmenu-extensibility") and ran
`GET /agent-test/v1/suite/jetmenu-extensibility`: first run was 6/7 — `jex-1` threw
"Base_Condition not loaded" because it checked `class_exists()` on
`\Jet_Menu\Modules\Dynamic_Visibility\Conditions\Base_Condition` *before* constructing a
`Registry`, but that class is only `require_once`'d lazily inside
`Registry::register_defaults()` — nothing in the plugin's own bootstrap eagerly loads it
(the only thing that constructs a `Registry` in normal operation is `Checker`, itself only
constructed lazily from a real `wp_get_nav_menu_items` render with a dynamic-visibility-
enabled item). This was a test-only bug, not a doc bug — SKILL.md never claimed eager
loading. Fixed by having the test force-load `base-condition.php` itself before the `eval()`-
defined subclass, then proceeding to construct `Registry` as before (safe, `require_once`-
based, per SKILL.md's "safe to instantiate freely" note). Re-ran: **7/7 pass**.

## Prerequisites

- Same as `jetmenu-structure`'s regimen (Elementor active, at least one mega-enabled nav
  menu location/item).
- For the Dynamic Visibility tests: a nav menu item with `dynamic_visibility.enabled`
  set to `true` and at least one rule, so `Checker::check()` actually evaluates
  something.
- Debug log sink and/or the `agent-test/v1/suite/` REST route.

## Test 1: registering a custom Dynamic Visibility condition — end to end

**Claim:** a class `extends Base_Condition`, registered via
`jet-menu/modules/dynamic-visibility/register-condition`, is reachable by `Checker` and
its `check()` method is actually invoked when a rule of that `type` is present in a menu
item's stored settings.

**Setup:**
```php
class Agent_Test_Always_True_Condition extends \Jet_Menu\Modules\Dynamic_Visibility\Conditions\Base_Condition {
    public function get_key() { return 'agent_test_always_true'; }
    public function check( $rule, $context ) { return true; }
}
add_action( 'jet-menu/modules/dynamic-visibility/register-condition', function( $registry ) {
    $registry->register_condition( new Agent_Test_Always_True_Condition() );
} );
add_filter( 'jet-menu/dynamic-visibility/allowed-rule-types', function( $types ) {
    $types[] = 'agent_test_always_true';
    return $types;
} );
```

**Trigger:** call `(new \Jet_Menu\Modules\Dynamic_Visibility\Conditions\Registry())->has('agent_test_always_true')`
directly (safe — `Registry` has no unconditional-require landmine, see `SKILL.md`), and
separately drive `Checker::check(['enabled'=>true,'type'=>'show','relation'=>'AND','rules'=>[['type'=>'agent_test_always_true','attrs'=>[]]]], $context)`.

**Expected observable:** `has()` returns true; `Checker::check()` returns true (matching
the always-true condition).

**Pass criteria:** both true, no fatal. Not yet automated in `tests.php` (needs the
3-line custom class + 2 hook registrations to be present in the same request as the
suite runs, which is safe to bake in — a future session should add this as a live test
rather than a source-presence check only).

## Test 2: registering a rule type without the `allowed-rule-types` filter — silently dropped

**Claim:** a rule type registered only via `register-condition` (step 1) but not added
to `jet-menu/dynamic-visibility/allowed-rule-types` (step 2) is silently stripped by
`Settings_Manager::sanitize_dynamic_visibility()` and never persisted.

**Setup:** register a condition class via step 1 only (skip the `allowed-rule-types`
filter), then call `jet_menu()->settings_manager->sanitize_dynamic_visibility([
  'enabled' => true, 'type' => 'show', 'relation' => 'AND',
  'rules' => [ ['type' => 'agent_test_unregistered_type', 'attrs' => []] ]
])` directly.

**Expected observable:** returned `rules` array is empty (the rule was dropped).

**Pass criteria:** matches — confirms the "3 hooks, not 1" claim's second step is load-
bearing. Not yet automated.

## Test 3: compatibility/integration registries only accept callbacks that ran by `init -999`

**Claim:** `jet-menu/compatibility-manager/registered-plugins` and
`jet-menu/integration-manager/registered-plugins` are read inside `Jet_Menu::init()`
itself (hooked `init` priority -999) — a filter callback added on a later `init` priority
(or a later hook) is too late to affect what actually loads.

**Setup:** add a callback on `init` priority 20 (after `-999`) that appends a fake
compatibility module entry; separately add one at priority `-1000` (before `-999`).

**Trigger:** inspect whether the fake module's class ends up instantiated (e.g. via a
static flag set in its constructor).

**Pass criteria:** the priority-20 callback's entry is NOT loaded; the priority -1000
one IS. Not yet automated (needs a full page-load-timing test, not a single-request
direct call).

## Test 4: `Jet_Smart_Filters`/`Jet_Theme_Core` compatibility classes bail without their target plugin

**Claim:** both classes' constructors return early (no hooks registered) if their target
plugin's marker constant/class isn't present.

**Trigger:** with neither JetSmartFilters nor JetTheme Core active, instantiate both
classes directly (safe — they're plain classes, not singletons the bootstrap manages
exclusively... though note `Compatibility\Manager` itself must not be re-instantiated,
see `SKILL.md` — instantiating the two leaf compatibility classes it wraps is fine) and
confirm neither registers `add_filter`/`add_action` calls (e.g. via
`has_filter('jet-menu/mega-menu/location/prevent-modify-nav-menu')` before/after for
`Jet_Theme_Core`).

**Pass criteria:** no hooks added when the target plugin is absent. Automated as `jex-4`
in `tests.php` for the presence/absence pattern (source-level check, since neither target
plugin is installed on this sandbox anyway).

## Test 5: `jet-menu/ajax/frontend-init` JS event trio actually fires

**Claim:** loading a mega-menu item's content via AJAX triggers, on `window`, in order:
`jet-menu/ajax/frontend-init/before`, `jet-menu/ajax/frontend-init`,
`jet-menu/ajax/frontend-init/after`.

**Setup:**
```js
['before', '', 'after'].forEach(function(suffix) {
    var evt = 'jet-menu/ajax/frontend-init' + (suffix ? '/' + suffix : '');
    jQuery(window).on(evt, function(e, data) { console.log('[JETMENU-TEST] ' + evt, data); });
});
```

**Trigger:** enable AJAX loading for a mega menu item's content (`ajax-loading` setting),
load the page, trigger the mega menu open (hover/click depending on config).

**Expected observable:** three console log lines in the documented order, each with a
`data` object carrying `$container`/`content`/`contentElements`/`contentType`.

**Pass criteria:** matches order and payload shape. Cannot be automated in PHP — browser
JS test only, left as a manual follow-up.

## Test 6: `jet-menu/block-manager/blocks-list` registers a custom block

**Claim:** adding an entry to this filter (nextgen mode only) makes a custom class
`extends Jet_Menu\Blocks\Base` register under the `jet-menu/` block namespace.

**Trigger:**
```php
add_filter( 'jet-menu/block-manager/blocks-list', function( $blocks ) {
    $blocks['\Agent_Test_Custom_Block'] = __DIR__ . '/agent-test-custom-block.php';
    return $blocks;
} );
```
(with a minimal `Agent_Test_Custom_Block extends \Jet_Menu\Blocks\Base` class defined in
that file, implementing `get_name()`, `get_attributes()`, `render_callback()`.)

**Expected observable:** `jet_menu()->blocks_manager->get_registered_blocks()` includes
the new block after `init`; `WP_Block_Type_Registry::get_instance()->is_registered('jet-menu/agent-test')`
returns true.

**Pass criteria:** matches, and only on a nextgen-mode site (confirm `is_nextgen_mode()`
first — the filter is never read at all in legacy mode). Not yet automated (needs a
real second file on disk at suite-run time, not safe to fabricate inline without care).
