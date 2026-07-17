# Test regimen: jetpopup-render-triggers

Validates claims in `SKILL.md`. Run against a sandbox site with JetPopup 2.2.1 active.

## Run log — 2026-07-16: UNBLOCKED, live-verified (5/5 pass)

Deployed `tests.php` as Code Snippets snippet id 58 and ran
`GET /agent-test/v1/suite/jetpopup-render-triggers`: **5/5 pass**, no fixes needed.

## Prerequisites

- JetPopup 2.2.1 active.
- The always-active `AGENT-TEST-CORE harness` snippet (id 22).
- At least one published popup post, ideally one with `use_ajax` enabled (for the AJAX
  content-endpoint tests) and one with a block-editor (`default` content type) body.
- Browser/JS-level tests (the `jet-popup-open-trigger`/`-close-trigger` event payload
  shape) need a real browser session, not just PHP REST calls — flagged as manual below
  where PHP-only automation isn't possible.

## Test 1: `add_attached_popup()` only accepts published popups

**Claim:** `Render_Manager::add_attached_popup($popup_id)` checks
`get_post_status($popup_id) === 'publish'` before adding to `$attached_popups` —
`includes/render/manager.php:95-97`.

**Automated as:** `jprt-1` in `tests.php` — calls `add_attached_popup()` with a draft
post id (creates a throwaway draft `jet-popup` post, or reuses any existing draft post
id) and confirms `get_attached_popups()` does NOT include it; then does the same with a
published popup and confirms it DOES.

## Test 2: page-load trigger downgrades to 'none' for attach-only (non-condition) popups

**Claim:** `popup_render()` sets `$open_trigger = 'none'` when `is_attached && !is_condition && open_trigger === 'page-load'` (`:259-261`).

**Automated as:** `jprt-2` in `tests.php` — calls `jet_popup()->generator->popup_render($id, $settings, ['is_attached' => true, 'is_condition' => false])`
with `jet_popup_open_trigger` forced to `'page-load'` in `$settings`, captures the
echoed HTML via output buffering, and confirms the `data-settings` JSON's `open-trigger`
key is `'none'`, not `'page-load'`.

## Test 3: `jet_popup_get_content` AJAX action is registered and enforces the read_post gate

**Claim:** `wp_ajax_jet_popup_get_content`/`wp_ajax_nopriv_jet_popup_get_content` are
registered only when `DOING_AJAX`; the handler requires `is_user_logged_in()` +
`current_user_can('read_post', $popup_id)` for a non-published popup.

**Automated as:** `jprt-3` in `tests.php` — confirms `has_action('wp_ajax_jet_popup_get_content')`
is truthy when simulated under `DOING_AJAX` (define the constant if not already set, or
skip gracefully if it can't be defined this late — note whichever happened). Does not
attempt a live nopriv request against a draft popup (would need an actual HTTP round
trip) — flagged as manual.

## Test 4: cache-teardown meta keys match between post-type save and JFB-compat clear_popup_cache

**Claim:** `Jet_Popup_Post_Type::save_popup_post_type()` and
`Jet_Form_Builder::clear_popup_cache()` delete the same 4 post-meta keys
(`_is_deps_ready`, `_is_script_deps`, `_is_style_deps`, `_is_content_elements`) plus the
same 2 transient-key patterns.

**Automated as:** `jprt-4` in `tests.php` — source-presence grep confirming both methods
reference all 4 identical meta key strings (catches a future drift where one gets
updated and the other doesn't).

## Test 5: JS open/close trigger event names and payload shape (manual — needs a browser)

**Claim:** `jet-popup-open-trigger`/`jet-popup-close-trigger` are jQuery `window` events
with `popupData.popupId` required as the string `"jet-popup-" + ID`, and re-firing
`jet-popup-open-trigger` on an already-open popup closes it (toggle behavior only on the
open channel).

**Not automated** — needs real browser JS execution against a live popup instance, not
achievable through a PHP snippet. Manual steps: open a page with a `page-load` or
`attach`-triggered popup, open devtools console, run
`jQuery(window).trigger({type:'jet-popup-open-trigger', popupData:{popupId:'jet-popup-<ID>'}})`,
confirm the popup opens; re-run the same command and confirm it closes (proving the
toggle); then run the close-trigger event with `constantly: true` and confirm reloading
the page doesn't reopen it (respecting "show once" persistence).

## Test 6: JetEngine popup-render-context swap/restore is a no-op without a `post_id`

**Claim:** `Jet_Engine::setup_popup_render_context($settings)` returns immediately if
`$settings['post_id']` is empty or `jet_engine()` doesn't exist — no state mutation.

**Automated as:** `jprt-6` in `tests.php` — calls `setup_popup_render_context([])` (no
`post_id`) directly on the compatibility instance and confirms
`jet_engine()->listings->data->get_current_object()` is unchanged before/after (only
runs if JetEngine is active; skips gracefully with a note otherwise).
