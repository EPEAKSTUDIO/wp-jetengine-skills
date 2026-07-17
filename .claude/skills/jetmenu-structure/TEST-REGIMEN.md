# Test regimen: jetmenu-structure

Validates claims in `SKILL.md`. Run against the sandbox site once JetMenu is installed.

## Run log — 2026-07-16: UNBLOCKED, live-verified (10/10 pass, one real bug found and fixed)

JetMenu 3.0.2.1 is now active on jackfruit.epeak.studio. Deployed `tests.php` as Code
Snippets snippet id 42 ("AGENT-TEST-SUITE: jetmenu-structure") and ran
`GET /agent-test/v1/suite/jetmenu-structure`. First run: the whole request 500'd
(WordPress's generic "critical error" page, no catchable exception/JSON at all) —
triaged by deactivating the suite and bisecting with isolated `ZZZ-DIAG` probe snippets
(same technique as `jetsmartfilters-query`'s snippet-25/26 landmine diagnosis) down to
`jms-4`, which called `\Jet_Menu\Options_Manager::get_instance()` directly. **This was a
real doc bug, not a test bug**: SKILL.md's "Bootstrap" section had claimed re-instantiating
`Options_Manager` "just" desyncs a second object, the same as most other manager classes —
but `Options_Manager::__construct()` → `init_options()` does an unconditional
`require $path;` (not `require_once`, `options.php:1467`) for its options-module files,
exactly like the already-documented `Render\Manager`/`Blocks\Manager` fatal pattern. A
second `::get_instance()` call re-`require`s an already-loaded file and throws an
uncatchable "Cannot redeclare class" fatal. Fixed both `SKILL.md` (corrected the Bootstrap
section, moved `Options_Manager` into the fatal-risk group) and `tests.php` (jms-4 is now
a source-presence check confirming the unconditional `require`, rather than triggering
the fatal live). Redeployed: **10/10 pass**.

**To make this regimen runnable:** install JetMenu on a site with Elementor active, wire
up at least one nav menu location with mega menu enabled and one item carrying real
Elementor-content-type mega content, then run these tests (and `tests.php`, deployed as
its own Code Snippets snippet) as written.

## Prerequisites

- Elementor active (JetMenu requires it — `jet-menu.php`'s `register_required_plugins()`).
- A WP nav menu assigned to at least one registered theme location, with JetMenu enabled
  for that location (via the "JetMenu Locations Settings" metabox on `nav-menus.php`).
- At least one top-level menu item with "Mega" enabled and Elementor content type, so the
  `jet-menu-item` meta / `jet-menu` CPT post / `Elementor_Content_Render` path can be
  exercised end-to-end.
- Debug log sink (see `docs/test-regimen-guide.md`) and/or the `agent-test/v1/suite/`
  REST route once the harness + this skill's `tests.php` are deployed.

## Test 1: manager reachability — `jet_menu()` exposes already-constructed managers

**Claim:** `jet_menu()->post_type_manager` / `->settings_manager` / `->render_manager` /
`->blocks_manager` / `->elementor_manager` are all live, already-constructed instances by
the time any normal request-time code runs (constructed once during `Jet_Menu::init()`,
hooked on `init` priority -999).

**Trigger:** call `jet_menu()` and inspect the properties from any hook that fires after
`init` (e.g. `wp_loaded`, a REST callback).

**Expected observable:** each property is an object of the documented class, not null.

**Pass criteria:** all five properties are present and are instances of their documented
classes. Automated as `jms-1` in `tests.php`.

## Test 2: `Rest_Api` is not exposed as a `jet_menu()` property

**Claim:** unlike the other five managers, `Rest_Api` is constructed
(`new \Jet_Menu\Rest_Api()`) but never assigned to a `jet_menu()` property — the real
accessor is `\Jet_Menu\Rest_Api::get_instance()`.

**Trigger:** check `isset( jet_menu()->rest_api )` (should be false) vs.
`\Jet_Menu\Rest_Api::get_instance()->api_namespace` (should be `'jet-menu-api/v2'`).

**Pass criteria:** matches. Automated as `jms-2`.

## Test 3: the `jet-menu` CPT is registered with the documented args

**Claim:** `public=true`, `show_in_menu=false`, `show_in_nav_menus=false`,
`has_archive=false`.

**Trigger:** `get_post_type_object( 'jet-menu' )`.

**Pass criteria:** all four flags match. Automated as `jms-3`.

## Test 4: `Options_Manager::get_instance()` is a DIFFERENT, desynced object

**Claim:** `jet_menu()->settings_manager->options_manager` (constructed via a direct
`new Options_Manager()` in `Settings_Manager::__construct()`) is not the same object as
`\Jet_Menu\Options_Manager::get_instance()` — the plugin's own bootstrap never calls the
latter, so calling it yourself gets a second, unrelated instance.

**Trigger:** compare object identity (`!==`) between the two.

**Pass criteria:** they are different objects. Automated as `jms-4`.

## Test 5: `get_menu_item_settings()` merges full defaults even for a non-existent item

**Claim:** calling `get_menu_item_settings( $fake_id )` for an id with no stored
`jet_menu_settings` meta still returns the complete default schema from
`default_nav_item_controls_data()` (via `wp_parse_args`), not an empty/false value.

**Trigger:** call with a large, guaranteed-nonexistent post id.

**Pass criteria:** returned array contains all documented default keys (`enabled`,
`content_type`, `custom_mega_menu_position`, `menu_icon_type`, `dynamic_visibility`, etc).
Automated as `jms-5`.

## Test 6: `Base_Render` class hierarchy

**Claim:** `Base_Render` is abstract; `Elementor_Content_Render` and
`Block_Editor_Content_Render` both extend it.

**Trigger:** `ReflectionClass` checks.

**Pass criteria:** matches. Automated as `jms-6`.

## Test 7: `Block_Editor_Content_Render` on a non-existent template id — safe, empty output

**Claim:** rendering a template id that doesn't exist returns/echoes nothing, no fatal
(`get_post()` returns null, guarded early).

**Trigger:** `new Block_Editor_Content_Render(['template_id' => 999999999, ...])->get_content()`.

**Pass criteria:** empty string, no exception. Automated as `jms-7`. This is a plain
value object (not a singleton the bootstrap already owns), so direct instantiation here
is safe per `HANDOFF.md`'s "check singleton safety first" lesson — confirmed by reading
`Base_Render`'s constructor, which does no `require`/file-loading of its own.

## Test 8: REST signature-check is deterministic per template id

**Claim:** `Endpoints\Base::generate_signature( $template_id )` (the default, non-signature-
overridden `permission_callback()`'s backing mechanism) returns the same value for the
same id across calls, and a different value for a different id.

**Trigger:** call `generate_signature(123)` twice and `generate_signature(456)` once,
compare.

**Pass criteria:** first two equal, third different. Automated as `jms-8`.

## Test 9: `get-menu-items` endpoint is unconditionally public

**Claim:** `Get_Menu_Items::permission_callback()` overrides the base signature check and
always returns `true` — genuinely unauthenticated.

**Trigger:** call `permission_callback()` directly with an empty `WP_REST_Request`, and
separately hit `GET /wp-json/jet-menu-api/v2/get-menu-items/?menu_id=<real id>` from a
logged-out browser session.

**Pass criteria:** both confirm no auth is required. `jms-9` automates the direct-call
half; the real HTTP request from a logged-out session is a manual follow-up (flagging a
public, unauthenticated data-exposure surface if writing security-relevant notes).

## Test 10 (manual, needs a real fixture): `is_mega_enabled()` truthiness discrepancy

**Claim:** `Mega_Menu_Walker::is_mega_enabled()` uses `FILTER_VALIDATE_BOOLEAN`, while
`Vertical_Menu_Walker::is_mega_enabled()` uses a loose `'true' == $value` string
comparison — storing boolean `true` (vs. string `'true'`) in `jet_menu_settings.enabled`
would pass the former's check but fail the latter's.

**Setup:** programmatically `update_post_meta( $nav_item_id, 'jet_menu_settings', array_merge( $existing, ['enabled' => true] ) )`
(PHP boolean `true`, not the string `'true'`) on a nav item used both in a `wp_nav_menu()`
location and inside a Custom Menu Elementor widget on the same page.

**Trigger:** render both.

**Expected observable:** the `wp_nav_menu()`-rendered version shows the mega container;
the Custom Menu widget version does not (or vice versa, if the discrepancy has been
fixed by then).

**Pass criteria:** confirms the documented behavioral split. `jms-10` in `tests.php`
automates only the source-presence half (confirming both methods are independently
defined, not inherited from a shared implementation) — the actual behavioral divergence
needs this live fixture and is not yet automated.
