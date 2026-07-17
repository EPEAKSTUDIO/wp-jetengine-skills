# Test regimen: jetpopup-extensibility

Validates claims in `SKILL.md`. Run against a sandbox site with JetPopup 2.2.1 active.

## Run log — 2026-07-16: UNBLOCKED, live-verified (7/7 pass after one test-only fix)

Deployed `tests.php` as Code Snippets snippet id 57 and ran
`GET /agent-test/v1/suite/jetpopup-extensibility`: first run 6/7 — `jpe-1` failed because
the `jet-popup` CPT was already registered (at this site's own `init`, long before this
REST-triggered request) before the test's `jet-popup/access-cap` filter was added, so
reading `$cpt->cap` without re-registering never reflected the filter. Not a doc bug —
fixed by unregistering + re-registering the CPT with the filter active (then doing the
same in reverse afterward to restore real state). Re-run: **7/7 pass**.

## Prerequisites

- JetPopup 2.2.1 active.
- The always-active `AGENT-TEST-CORE harness` snippet (id 22).
- Admin/manage_options access to hit the `jet-popup/v2` REST namespace with
  authenticated cookies (for the REST permission_callback test).

## Test 1: `jet-popup/access-cap` fans out to every CPT capability and to the REST permission_callback

**Claim:** all 15 keys in the `jet-popup` CPT's `capabilities` array resolve to
`jet_popup()->get_admin_ui_cap()`'s single filtered value, and
`Endpoints\Base::permission_callback()` calls `current_user_can()` on that exact same
value.

**Automated as:** `jpe-1` in `tests.php` — hooks `jet-popup/access-cap` to return a
distinctive marker capability (e.g. `agent_test_marker_cap`), re-registers the
`jet-popup` post type (or reads `get_post_type_object('jet-popup')->cap` if already
registered before the filter was added — note whichever path was used), and confirms
every one of the 15 `cap` properties equals the marker; separately calls
`(new Jet_Popup\Endpoints\Get_Posts())->permission_callback(null)`-equivalent (via
`current_user_can()` directly against `jet_popup()->get_admin_ui_cap()`) and confirms it
reads the same marker value. Removes the filter afterward.

## Test 2: `get_endpoints()` lazily self-initializes

**Claim:** `Rest_Api::get_endpoints()` calls `init_endpoints()` itself if
`$_endpoints` is still `null`, so it's safe to call before `rest_api_init`.

**Automated as:** `jpe-2` in `tests.php` — calls `jet_popup()->rest_api->get_endpoints()`
directly (this suite runs from a custom REST route already inside `rest_api_init`, so
this specifically checks the returned array is non-empty and contains all 15 documented
built-in endpoint name keys, not that "before `rest_api_init`" precisely — note this
caveat in the result).

## Test 3: `jet-popup/rest-api/endpoint-list` filter is honored before endpoint instantiation

**Claim:** a callback added to this filter can inject a new endpoint class, and it will
appear in `get_endpoints()`'s returned list, registered under the `jet-popup/v2`
namespace.

**Automated as:** `jpe-3` in `tests.php` — this filter only fires once, memoized in
`$_endpoints`, so this test hooks it as early as possible in the suite's own execution
and checks whether a probe endpoint got included; if `$_endpoints` was already
memoized by an earlier real request this test run, it's marked UNCLEAR rather than
FAIL (documented as a known limitation of testing a lazily-memoized singleton from a
REST-route-triggered suite — a full page load/fresh request is needed for a clean run).

## Test 4: block_type_metadata / register_block_type_args both inject registered data-attributes

**Claim:** any block not in `get_not_supported_blocks()` gets every registered
`Data_Attributes` entry merged into its `attributes` schema via both
`block_type_metadata` and `register_block_type_args`.

**Automated as:** `jpe-4` in `tests.php` — calls
`jet_popup()->block_editor->add_block_attrs(['name' => 'core/paragraph', 'attributes' => []])`
directly and confirms the returned array's `attributes` key contains
`jetPopupInstance`/`jetPopupTriggerType`/`jetPopupCustomSelector` (the 3 built-in
attributes registered in `register_data_attrs()`).

## Test 5: excluded blocks are skipped by the data-attribute injection

**Claim:** `core/html`, `core/shortcode`, `core/freeform`, `core/legacy-widget`, and
JetPopup's own `jet-popup/action-button` are excluded from attribute injection.

**Automated as:** `jpe-5` in `tests.php` — same call as Test 4 but with
`'name' => 'core/html'`, confirming the returned metadata is unchanged (no
`jetPopupInstance` key added).

## Test 6: the `lodash` dependency IS present on `jet-popup-block-editor` (backlog correction)

**Claim:** the backlog's "missing lodash dependency" gist doesn't apply to the
currently-installed version — `lodash` is already in the script's dependency array.

**Automated as:** `jpe-6` in `tests.php` — source-grep confirming
`includes/block-editor/manager.php`'s `wp_enqueue_script('jet-popup-block-editor', ...)`
call site's dependency array literally contains `'lodash'`. A live equivalent (checking
`wp_scripts()->registered['jet-popup-block-editor']->deps` after
`enqueue_block_editor_assets` has fired in wp-admin) would be stronger but needs an
admin-screen request context this REST-triggered suite doesn't have — noted as a gap.

## Test 7: compatibility modules are only instantiated when their own dependency check passes

**Claim:** `Compatibility\Manager::load_compatibility_modules()` unconditionally `new`s
every registered module class; each built-in module does its own
`class_exists()`/`defined()` guard in its constructor and `return`s early otherwise.

**Automated as:** `jpe-7` in `tests.php` — confirms `jet_popup()->compatibility` exists
and, for whichever of WooCommerce/JetEngine/JFB are NOT active on this sandbox, checks
that querying the corresponding condition/block-list side-effects (e.g.
`get_condition('woocommerce-shop-page')` if WooCommerce is inactive) return `false`,
proving the guard actually prevented registration rather than merely not crashing.
