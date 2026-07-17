# Test regimen: jettabs-widgets

Validates claims in `SKILL.md`. Run against the sandbox site once JetTabs For Elementor
(2.3.2) is active alongside Elementor. See `docs/test-regimen-guide.md` for format and
`docs/test-harness-guide.md` for the runnable-suite convention `tests.php` follows.

## Run log — 2026-07-16: UNBLOCKED, live-verified (4/4 pass)

Deployed `tests.php` as Code Snippets snippet id 60 and ran
`GET /agent-test/v1/suite/jettabs-widgets`: **4/4 pass**, no fixes needed.

## Prerequisites

- JetTabs 2.3.2 active, Elementor active.
- At least one published Elementor Template Library item (`elementor_library` post type)
  usable as tab/accordion/switcher content.
- A page with a Tabs (or Accordion/Switcher) widget with "Ajax Template" enabled and at
  least one item pointed at that template.
- Debug log sink (see `docs/test-regimen-guide.md`).

## Test 1: the ajax-template REST route is really public and returns cached content

**Claim being tested:** `SKILL.md` "Ajax/lazy-loaded template content" — `GET
/wp-json/jet-tabs-api/v1/elementor-template?id={template_id}` is registered, publicly
callable (no auth), and caches its response in a WP transient keyed
`md5('jet_tabs_elementor_template_data_' . $template_id)` for 12 hours.

**Setup:** none beyond prerequisites.

**Trigger:** as a logged-out (or non-admin) client, `curl` the route with a real
published template's id: `GET /wp-json/jet-tabs-api/v1/elementor-template?id={id}`.

**Expected observable:** HTTP 200 with `template_content`/`template_scripts`/
`template_styles` keys in the JSON body (not a 401/403). A direct DB check
(`SELECT * FROM wp_options WHERE option_name LIKE
'_transient_%<md5 of the key>%'`) shows a transient row was created after the first
request.

**Pass criteria:** route responds without auth, and the transient row appears —
confirms both the "publicly accessible" and "WP-transient-cached" halves of the claim.

## Test 2: editing the linked template does NOT clear that transient (the cache gotcha)

**Claim being tested:** `SKILL.md` "Gotcha: two separate, disconnected cache stores" —
`save_elementor_template_post_type()` and the "Clear Tabs Cache" REST action both act on
the (unused) `wp_jet_cache` DB table, not on the WP transient that Test 1 populated.

**Setup:** starting right after Test 1 (transient exists for `$template_id`).

**Trigger:**
1. Edit and re-save (Update) the Elementor template used in Test 1, changing its
   visible text.
2. Re-`curl` the same REST route from Test 1 immediately after.
3. Separately, call `POST /wp-json/jet-tabs-api/v1/clear-tabs-cache` (admin auth
   required, `manage_options`), then re-`curl` the REST route from Test 1 again.

**Expected observable:** step 2's response still shows the *old* template text (proving
`save_post` did not invalidate the transient). Step 3's response, immediately after
calling "Clear Tabs Cache", **still** shows the old text too (proving that endpoint's
`delete_cache_by_source('elementor_library')` call — which only touches the unused
`wp_jet_cache` table — has no effect on the actual REST cache).

**Pass criteria:** both re-fetches return stale content despite both invalidation
paths being exercised — confirms the gotcha as a real, observable bug rather than a
theoretical one from reading source alone. If either re-fetch shows fresh content, the
skill's claim is wrong and needs correcting (check whether a WP core object-cache drop-in
is transparently proxying `set_transient`/`get_transient` through something that *does*
get cleared, which would explain a false pass here).

## Test 3: `wp_jet_cache` table truly never receives a row from normal plugin use

**Claim being tested:** `SKILL.md` "Gotcha" — `jet_set_transient()`/
`db_manager->set_cache()` are never called anywhere in the plugin, so the table stays
empty under normal use.

**Setup:** a fresh site state where Tests 1-2 have already been exercised (REST route
hit, template edited, "Clear Tabs Cache" clicked).

**Trigger:** `SELECT COUNT(*) FROM wp_jet_cache;` via a debug snippet.

**Expected observable:** `0` rows, even after all of Tests 1-2's actions (which do
populate the WP-transient-backed cache and do call the `delete_cache_by_*` methods
against this table, but never `set_cache()`).

**Pass criteria:** count stays 0 — confirms the table is write-never under the exercised
code paths, not just "we didn't happen to trigger the write path in these tests."

## Test 4: load-level control gating actually removes a control from the editor panel

**Claim being tested:** `SKILL.md` "`Jet_Tabs_Base`'s helper-method conventions" —
`__add_control` skips registering a control entirely when `__load_level` (from the
`widgets_load_level` setting) is below that control's required level, unless the control
id is in `__include_controls`.

**Setup:** in JetTabs' plugin settings, set "Widgets Load Level" to a low value (e.g. the
lowest option). Open a Tabs widget in the Elementor editor.

**Trigger:** inspect the widget's control panel for a control known to require a higher
load level (or use `elementor.getPanelView().getCurrentPageView().model.get('editSettings')`
in devtools, or simpler: check network/JS for whether the control name appears in the
widget's `get_controls()` output via the Elementor editor's own debug tools).

**Expected observable:** the higher-load-level control is absent from the panel; setting
"Widgets Load Level" back to 100 (max) makes it reappear.

**Pass criteria:** control visibly appears/disappears purely based on the setting — not
automatable via `tests.php` (needs a real widget instance mid-`_register_controls()` and
a live Elementor editor session); left for manual/browser verification.

## Automated in `tests.php` (once deployed)

- `jtw-1`: the `jet-tabs-api/v1` REST namespace and its three expected endpoints
  (`elementor-template`, `plugin-settings`, `clear-tabs-cache`) are registered — checked
  via `rest_get_server()->get_routes()`, no widget/query fixture needed.
- `jtw-2`: `jet_set_transient`/`jet_get_transient` function bodies exist and delegate to
  `Jet_Cache\Manager::get_instance()->db_manager`, confirmed by source-presence
  (function-exists + reflection on the function body isn't practical in PHP, so this is a
  grep-equivalent string check against the plugin file, matching how other suites in this
  repo do source-presence checks) — the "never actually called" half of the claim is
  Test 3's job (needs live DB state), not this suite's.
- `jtw-3`: `wp_jet_cache` DB table exists (created by `DB_Manager::init_db_required()`
  once the module has loaded) — a live but harmless read-only `SHOW TABLES` check.
- `jtw-4`: `jet-tabs/widgets/template_id` and `jet-tabs/widgets/template_content` are
  real, mutable filters — driven directly via `apply_filters()` with a marker callback,
  no widget instance needed for `template_id`; `template_content`'s real call site takes
  4 args, checked via source-presence only since it fires mid-`render()`.
