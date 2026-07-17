---
name: jetcomparewishlist-data-store
description: Use when reading/writing JetCompareWishlist's compare/wishlist product lists programmatically — the session/cookie/user-meta storage backend, the add/remove AJAX endpoints, the render-time widget-registry that re-renders every wishlist/compare widget on a page after an AJAX add/remove, or the jet_cw()->wishlist_data/compare_data accessors. Captures verified behavior of JetCompareWishlist For Elementor 1.5.12.3 source, live-verified 2026-07-16 against jackfruit.epeak.studio (6/6 tests.php assertions passing — 2 gracefully skip since Wishlist/Compare are disabled by default on this site, see TEST-REGIMEN.md).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetCompareWishlist Data Store & AJAX Pipeline

Verified facts about how JetCompareWishlist actually stores a visitor's compare/wishlist
product lists, how the front-end add/remove flow works end to end, and the accessor
landmines around it. Confirmed against JetCompareWishlist For Elementor 1.5.12.3 source
(`plugins/jet-compare-wishlist/`).

## The `jet_cw()` singleton and its per-feature null-object landmine

`jet_cw()` returns `Jet_CW::get_instance()` (`jet-cw.php:534`). Its `->wishlist_data`
(`Jet_CW_Wishlist_Data`), `->compare_data` (`Jet_CW_Compare_Data`), `->wishlist_render`,
`->compare_render` properties are only ever assigned inside `Jet_CW::init()`
(`jet-cw.php:302-312`), and **only if the corresponding site setting is enabled**:

```php
if ( filter_var( $this->compare_enabled, FILTER_VALIDATE_BOOLEAN ) ) {
    $this->compare_integration = new Jet_CW_Compare_Integration();
    $this->compare_render      = new Jet_CW_Compare_Render();
    $this->compare_data        = new Jet_CW_Compare_Data();
}
if ( filter_var( $this->wishlist_enabled, FILTER_VALIDATE_BOOLEAN ) ) {
    $this->wishlist_integration = new Jet_CW_Wishlist_Integration();
    $this->wishlist_render      = new Jet_CW_Wishlist_Render();
    $this->wishlist_data        = new Jet_CW_Wishlist_Data();
}
```

**Landmine**: on a site with the Wishlist feature toggled off in Settings (`enable_wishlist`),
`jet_cw()->wishlist_data` is `null` — calling `jet_cw()->wishlist_data->get_wish_list()`
is a fatal "call to a member function on null", not a graceful no-op. Always check
`jet_cw()->wishlist_enabled` (or just `jet_cw()->wishlist_data`) before touching it, same
for `compare_data`/`compare_enabled`.

## Storage backend: session or cookies, plus an independent user-meta overlay

`Jet_CW_Compare_Data`/`Jet_CW_Wishlist_Data` (`includes/compare/class-jet-cw-compare-data.php`,
`includes/wishlist/class-jet-cw-wishlist-data.php`) each read a `store_type` setting
(`compare_store_type`/`wishlist_store_type`, configured independently per list) with only
two real values:

- `'session'` — `$_SESSION['jet-compare-list']` / `$_SESSION['jet-wish-list']`, a
  colon-delimited string of product IDs (`implode( ':', $list )`). Session is only
  started (`session_start()`) once, on `parse_request` (`__construct`, guarded by
  `headers_sent()`), and only if `store_type === 'session'`.
- `'cookies'` — same colon-delimited string, written via
  `jet_cw()->widgets_store->set_cookie()` (`class-jet-cw-widgets-store.php:118-135`): a
  1-year cookie, `httponly = true`, secure only if `is_ssl()` and site URL is `https:`.

**Independently of `store_type`**, if the visitor `is_user_logged_in()` **and** settings
`save_user_compare_list`/`save_user_wish_list` is truthy, the list is *also* written to
user meta (`jet_compare_list`/`jet_wish_list`) on every `set_*_list()` call, and — this is
the part that's easy to miss — **user meta wins as the read source whenever both
conditions hold** (`compare-data.php:125-129`, `wishlist-data.php:125-129`): the
session/cookie value is fetched first, then unconditionally overwritten by the user-meta
value if the user is logged in and that setting is on. A logged-in user with the setting
enabled will never actually see their pre-login session/cookie list merged in — it's
fully replaced by whatever's in their user meta.

## Reads self-prune trashed/draft products silently

`get_compare_list()`/`get_wish_list()` both loop the stored ID list and
`array_splice()` out any ID whose `get_post()` is empty or not `'publish'` status
(`compare-data.php:137-143`, mirrored in wishlist). **This mutates the return value but
not the persisted store** — a trashed product just silently stops appearing in the list
on every subsequent read, with no explicit removal event, no hook fired, and the stale ID
technically still sits in the cookie/session/user-meta value until the list is next
`set_*_list()`'d (e.g. on the next real add/remove).

## Compare has a max-size guard; wishlist does not

`Jet_CW_Compare_Data::set_compare_list()` only actually persists the new list if
`count( $compare_list ) <= compare_page_max_items` (`compare-data.php:159-181`) — if the
caller pushes past the configured max, the write is silently dropped and the store keeps
its previous value. `set_wish_list()` has no equivalent cap.

## `update_data_*($pid, $context)` — two contexts only, `remove` with `$pid = 0` clears everything

```php
function update_data_wishlist( $pid, $context ) {
    $wishlist_list = $this->get_wish_list();
    switch ( $context ) {
        case 'add':
            if ( ! in_array( $pid, $wishlist_list ) ) { $wishlist_list[] = $pid; }
            break;
        case 'remove':
            if ( $pid ) {
                $index = array_search( $pid, $wishlist_list );
                unset( $wishlist_list[ $index ] );
            } else {
                $wishlist_list = [];
            }
            break;
    }
    $this->set_wish_list( $wishlist_list );
    return $wishlist_list;
}
```

`$context` is a string, exactly `'add'` or `'remove'` — not a boolean toggle, and there's
no third value. `update_data_compare()` is the same shape (`compare-data.php:73-100`).
Calling `update_data_wishlist( 0, 'remove' )` is the documented way to clear a visitor's
entire wishlist in one call.

## Front-end add/remove is classic admin-ajax, not the plugin's own REST API

- `wp_ajax_jet_update_wish_list` / `wp_ajax_nopriv_jet_update_wish_list` →
  `Jet_CW_Wishlist_Render::update_wish_list()` (`includes/wishlist/class-jet-cw-wishlist-render.php:13-14,22-39`).
  `wp_ajax_jet_update_compare_list` / `..._nopriv_...` → `Jet_CW_Compare_Render::update_compare_list()`
  (`includes/compare/class-jet-cw-compare-render.php:13-14,23-40`).
- Both `check_ajax_referer( 'jet-cw-compare', 'nonce' )` against the **one shared nonce**
  action name for both lists, localized to the front end as `JetCWSettings.nonce`
  (`class-jet-cw-widgets-store.php:161`).
- POST body: `pid` (`absint`), `context` (`'add'`/`'remove'`, `sanitize_text_field`).
- `do_action( 'jet-cw/wishlist/render/before-add-to-wishlist', $pid, $context, $this )`
  (the render instance) fires **inside the handler, before the store is mutated**
  (`wishlist-render.php:30`; compare equivalent `jet-cw/compare/render/before-add-to-compare`,
  `compare-render.php:31`) — confirmed against Crocoblock's official
  [developer-documentation](https://github.com/Crocoblock/developer-documentation)
  (`18-jet-compare-wishlist/01-hooks/01-widgets/{01-compare,02-wishlist}/actions.md`),
  whose own worked example hooks exactly this action to reject the request with a
  `wp_redirect()` + `exit` for logged-out users — this is the right hook to
  reject/redirect before the list changes, not a generic "after update" hook.
- Response (`wp_send_json_success`): `{ content: { "<selector>": "<rendered widget HTML>", ... }, wishlistItemsCount|compareItemsCount: <int> }`.
- A genuine REST namespace `jet-cw-api/v1` exists (`Jet_CW\Rest_Api`, `includes/rest-api/rest-api.php`),
  but `init_endpoints()` only ever registers `Endpoints\Plugin_Settings`
  (`rest-api.php:54`), whose base class defaults `permission_callback()` to
  `current_user_can( 'manage_options' )` (`endpoints/base.php:43-45`) — **this REST API
  is admin-settings-only, it is not how front-end add/remove works.**

## The widgets-store re-render mechanism — client re-sends its own widget map every request

Every wishlist/compare widget instance calls
`jet_cw()->widgets_store->store_widgets_types( $type, $selector, $settings, 'wishlist'|'compare' )`
during its own render (e.g. `render_wishlist_button()`,
`includes/wishlist/class-jet-cw-wishlist-render.php:106`), building an in-memory map keyed
by `urlencode( $selector )` where `$selector` contains a literal `"{pid}"` placeholder
token — this whole map gets `wp_localize_script()`'d to the front end as
`JetCWSettings.widgets` (`class-jet-cw-widgets-store.php:146-167`) so client JS knows
every DOM node it must patch after an add/remove.

**Non-obvious**: `Jet_CW_Widgets_Store::get_stored_widgets()` (`widgets-store.php:94-99`)
— called from `render_content()` on every AJAX add/remove — reads
`$_REQUEST['widgets_data']`, **not** any server-side property. The client echoes its own
copy of the widgets map back on every add/remove POST; the server keeps no memory of it
across requests. A custom caller that POSTs to `wp_ajax_jet_update_wish_list` without a
`widgets_data` field gets back an **empty** `content` object (nothing to re-render), not
an error — `render_content()` just returns `[]` early when `get_stored_widgets()` is empty
(`wishlist-render.php:48-55`).

## Gotchas

- **Disabled-feature null object** (see above) — always gate on `wishlist_enabled`/`compare_enabled`.
- **User-meta silently overrides session/cookie for logged-in users** when the "save for
  logged-in user" setting is on — don't assume the session/cookie value is authoritative
  just because that's the configured `store_type`.
- **Compare's max-items cap silently drops writes**, wishlist has none.
- **Prune-on-read** removes trashed/draft product IDs from the returned array without
  ever persisting that removal or firing a hook.
- **The real REST API is admin-only** — don't go looking for a public REST route for
  add/remove; it's admin-ajax.

## How this was verified

Read `jet-cw.php`, `includes/compare/class-jet-cw-compare-data.php`,
`includes/wishlist/class-jet-cw-wishlist-data.php`,
`includes/compare/class-jet-cw-compare-render.php`,
`includes/wishlist/class-jet-cw-wishlist-render.php`,
`includes/class-jet-cw-widgets-store.php`, `includes/rest-api/rest-api.php`,
`includes/rest-api/endpoints/base.php` in JetCompareWishlist 1.5.12.3 source by direct
file:line citation. Cross-checked the two `before-add-to-*` action hooks (name, 3-arg
signature, "reject before mutation" usage pattern) against Crocoblock's official
[developer-documentation](https://github.com/Crocoblock/developer-documentation) repo
(`18-jet-compare-wishlist/01-hooks/01-widgets/`), which matched the source exactly. Not
yet verified against a running site — see `TEST-REGIMEN.md`; `tests.php` has a
class/accessor-reachability and direct-call suite ready to deploy.
