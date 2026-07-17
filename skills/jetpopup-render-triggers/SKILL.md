---
name: jetpopup-render-triggers
description: Use when working with how a JetPopup actually gets onto the page and opens/closes — the wp_footer render pipeline that decides which popups are "defined" for a request, the front-end jet-popup-open-trigger/jet-popup-close-trigger jQuery event API for opening/closing a popup from custom JS, the AJAX lazy-content endpoint (jet_popup_get_content), or the close-after-form-submit wiring shared with JetFormBuilder/Elementor Pro Forms. Captures verified behavior from JetPopup 2.2.1 source, live-verified 2026-07-16 against jackfruit.epeak.studio (5/5 tests.php assertions passing).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetPopup Render Pipeline & Trigger Events

Verified facts about how JetPopup decides which popups to output on a given page, how
their markup/content actually reaches the DOM (inline vs AJAX), and the real JS event
API for opening/closing a popup programmatically. Confirmed against JetPopup 2.2.1
source, `plugins/jet-popup/`.

## Which popups render on a request: two independent sources, merged

`Jet_Popup\Render_Manager` (`includes/render/manager.php`) hooks `wp_footer` twice —
`page_popups_init` at priority 1, `page_popups_render` at priority 2 (`:58-59`) — so
"which popups are defined" and "render them" are deliberately two passes.

`define_page_popups()` (`:176-220`) merges **two independent id lists**, not one query:

1. **Condition-matched popups**: `jet_popup()->conditions_manager->find_matched_popups_by_conditions()`
   (see `jetpopup-conditions` for the matching algorithm).
2. **Attached popups**: ids pushed via `Render_Manager::add_attached_popup( $popup_id )`
   (`:93-113`) during the request — e.g. from a Gutenberg block whose
   `jetPopupInstance` attribute names a popup (`Block_Editor_Manager::render_block_data()`,
   `includes/block-editor/manager.php:315-317`) or an Elementor "Action Button" widget.
   **`add_attached_popup()` only accepts a popup whose `post_status` is exactly
   `'publish'`** (`:95-97`) — a draft/pending popup attached to a block silently never
   renders, no error.

Both lists are unioned into `$this->popup_id_list`, then `apply_filters('jet-popup/popup-generator/defined-popup-list', ...)`
(`:127`) exposes the final per-popup array of `{id, settings, is_condition, is_attached}`.
`do_action('jet-popup/render-manager/define-popups/after', $this->defined_popup_list, $this->ajax_popup_defined)`
(`:219`) fires right after — the hook to inspect/veto the final list before rendering.

**Attach-vs-condition interaction gotcha**: if a popup is block-attached (not
condition-matched) and its `open_trigger` is `'page-load'`, `popup_render()` silently
downgrades the effective trigger to `'none'` (`:259-261`) — a block-attached popup never
auto-opens on page load even if that's its configured trigger; it only opens via its
attached block's own click/hover/scroll trigger.

**Elementor element-cache interaction**: when `elementor_element_cache_ttl` isn't
`'disable'`, attached-popup ids are cached in a transient keyed by
`md5(queried_object_id|current_post_id|request_uri)` (`get_attached_popups_transient_key()`,
`:78-86`) for `HOUR_IN_SECONDS` — a popup newly attached to a block won't show up for up
to an hour on a previously-cached URL unless something busts that transient (the plugin
itself never explicitly does on save; only Elementor's own element-cache invalidation
indirectly helps here since the transient key isn't tied to Elementor's cache version).

## Rendered markup: one `<div id="jet-popup-{id}">` per popup, JSON settings in `data-settings`

`popup_render()` (`includes/render/manager.php:250-444`) builds the container HTML
directly (no template file) — a `role="dialog"` element with `data-settings` holding
`htmlspecialchars(json_encode($popup_json))` (`:340,433`). `$popup_json`'s real keys
(`:305-338`) are the front-end JS's actual contract — notably `open-trigger`,
`close-event`, `use-ajax`, `force-ajax`, `show-once`, `close-after-form-submit`, and
`content-type` (`'default'`/`'elementor'`, from `Jet_Popup_Post_Type::get_popup_content_type()`).

Content is either inlined (`$popup_content`, non-AJAX) or an empty placeholder
(`use-ajax` true) that the front-end JS fills in later via the AJAX endpoint below.
`_is_deps_ready` post meta (`:355,382`) tracks whether this popup's CSS/JS deps have
already been printed once on this page — subsequent AJAX loads skip re-declaring
style/script dependencies. Content caching (`use_content_cache` + the
`useContentCache` plugin setting) is a separate transient layer keyed by
`Jet_Popup_Utils::get_render_data_cache_key()`, invalidated by deleting
`_is_deps_ready`/`_is_script_deps`/`_is_style_deps`/`_is_content_elements` post meta and
two `jet_popup_render_content_data_{styles,scripts}_{id}` transients — both
`Jet_Popup_Post_Type::save_popup_post_type()` (on every popup save) and the JetFormBuilder
compat module's `clear_popup_cache()` (when an embedded form changes) do exactly this
teardown, the reference pattern for "force a popup to re-render its cached content."

## The AJAX content endpoint: `jet_popup_get_content` — a genuine `admin-ajax` action, not a REST route

`Jet_Popup_Ajax_Handlers::jet_popup_get_content()` (`includes/ajax-handlers.php:201-278`)
registers on both `wp_ajax_jet_popup_get_content` and `wp_ajax_nopriv_jet_popup_get_content`
(`:63-64`, gated by `DOING_AJAX`) — this is the literal `action=jet_popup_get_content`
`$_POST` value a 3rd-party plugin (e.g. the backlog's Gravity Forms URL-rewrite gist)
checks for to detect "this request is JetPopup fetching AJAX popup content."

Flow: `$_POST['data']` → `apply_filters('jet-popup/ajax-request/post-data', $data)` →
visibility check (unpublished popup requires `is_user_logged_in()` **and**
`current_user_can('read_post', $popup_id)`, else `wp_send_json_error(...)`, `:212-229`)
→ `apply_filters("jet-popup/ajax-request/get-{$content_type}-content", false, $popup_data)`
(a per-content-type override point, `:232`) → builds a `Block_Editor_Content_Render` or
`Elementor_Content_Render` instance with **`is_style_deps`/`is_script_deps` forced
`false`** (`:239-254`, since the initial page load already declared them) → response is
`{ type: 'success', content: <render data array>, data: <popup_data> }`.

The JetEngine compatibility module hooks two render-lifecycle actions specifically to
support this AJAX path for Elementor-content popups showing "current loop item" dynamic
data: `jet-popup/render/elementor-content/before` / `/after`
(`includes/compatibility/plugins/jet-engine/manager.php:172-173`) temporarily swap
JetEngine's `jet_engine()->listings->data` current/queried object to the popup's
`post_id` (passed via `popupData.postId` from the triggering listing item) and restore
it afterward — the mechanism behind the backlog's "scroll a Listing Grid slider to the
matching popup" gist actually working with dynamic per-item popup content.

## The JS open/close trigger event API — `$(window).trigger(...)`, not `dispatchEvent`

Confirmed in `assets/js/jet-popup-frontend.js`. Both events are plain jQuery custom
events fired on `window`, listened to per-popup-instance (each popup's IIFE binds its
own `popupId` closure) at `:897` (open) / `:915` (close):

```js
// Open (or toggle-close if already open) the popup whose data-settings.id === popupId:
jQuery( window ).trigger( {
    type: 'jet-popup-open-trigger',
    popupData: { popupId: 'jet-popup-123' },   // NOTE: the STRING id "jet-popup-{ID}", not a bare number
    triggeredBy: jQuery( someElement ),         // optional; used for focus-return bookkeeping
} );

// Close the popup, optionally suppressing it from re-showing this session/permanently:
jQuery( window ).trigger( {
    type: 'jet-popup-close-trigger',
    popupData: {
        popupId: 'jet-popup-123',
        constantly: false,   // true = respect this popup's own "show once" persistence
    },
} );
```

**`popupData.popupId` must be the string `"jet-popup-" + ID`** (matching the container's
`id` attribute), not the raw post ID — every built-in trigger (`click-self`,
`click-selector`, `hover`, `scroll-to` attach triggers at `:97-186`; every Action Button
`actionType` at `:237-358`) constructs it exactly that way. A callback firing the open
event with a bare numeric id silently matches no popup (the `==` comparison at `:903`/`:920`
never succeeds).

**Open-trigger toggles, close-trigger doesn't**: the open handler (`:897-913`) checks
`$popup.hasClass('jet-popup--hide-state')` — if already open, it calls `hidePopup()`
instead of `showPopup()`. There's no separate "toggle" event name; re-firing
`jet-popup-open-trigger` on an already-open popup is how you close it via the "open"
channel.

`$window.trigger('jet-popup/init-events/after', { self, settings })` (`:928-931`) fires
once per popup instance right after both trigger listeners are bound — the hook for
attaching additional custom-JS behavior to a specific popup instance without racing its
own init.

## Close-after-form-submit: two independent listeners, not one shared "form submitted" event

`initCompatibilityHandler()` (`:999-1044`) wires **both**:

- `$form.on('submit_success', ...)` scoped to `.elementor-widget-form .elementor-form`
  inside this popup only (Elementor Pro's own form widget event) — proves a popup with
  an Elementor Pro form closes based on that widget's own success event.
- `$(document).on('jet-form-builder/ajax/on-success', ...)` — **document-scoped, not
  scoped to this popup** — this is JetFormBuilder's cross-plugin success event; any
  JFB-form-triggered `jet-form-builder/ajax/on-success` anywhere on the page will run
  every currently-open popup's close-after-submit logic, gated only by that popup's own
  `close-after-form-submit`/`close-after-form-delay` settings, not by "was the form
  actually inside this popup." A page with a JFB form outside any popup, plus an
  unrelated open popup configured to close-on-form-submit, would see that popup close
  too — worth flagging if a custom JFB-based form triggers this event manually.

## Gotchas

- The `jet-popup/ajax-request/after-content-define/post-data` filter (`:235`) is marked
  **Deprecated** in a code comment right at its call site — don't build new integrations
  against it; use `jet-popup/ajax-request/post-data` (before content-type resolution)
  instead.
- `get_popup_content_type()` (`includes/post-type.php:744-753`) defaults to `'elementor'`
  when `_content_type` meta is empty, **not** `'default'` — despite
  `get_popup_default_content_type()` elsewhere defaulting new-popup creation to
  `'default'` (Block Editor). These are two different defaults for two different
  situations (existing popup missing its meta vs. brand-new popup being created) — don't
  assume they agree.

## How this was verified

Read `includes/render/manager.php`, `includes/render/base-render.php`,
`includes/ajax-handlers.php`, `includes/post-type.php`,
`includes/block-editor/manager.php`, `includes/compatibility/plugins/jet-engine/manager.php`,
`includes/compatibility/plugins/jet-form-builder/manager.php`, and
`assets/js/jet-popup-frontend.js` directly in JetPopup 2.2.1 source
(`plugins/jet-popup/`), confirming exact JS event names/payload shapes by grepping the
shipped (non-minified) bundle for the literal `jet-popup-open-trigger`/
`jet-popup-close-trigger` strings and reading every call site, not just the first one.
Cross-checked against Crocoblock's public `developer-documentation` GitHub repo
(`05-jet-popup/01-hooks/02-js-hooks/`) — the JS-hooks page exists as a navigation stub
only (its `README.md` is a 3-line index, no actual hook docs are filled in), so no
corroborating/conflicting official documentation exists yet. Not yet verified against a
running site — see `TEST-REGIMEN.md`; `tests.php` has reachability/source-presence smoke
tests ready to deploy.
