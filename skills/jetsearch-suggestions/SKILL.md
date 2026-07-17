---
name: jetsearch-suggestions
description: Use when reading/writing JetSearch's "Search Suggestions" (autocomplete) feature — the two custom DB tables backing it, the REST CRUD surface for admin-managed suggestions, the near-duplicate AJAX handlers that actually power the shipped admin UI (not the REST routes), and the separate "form suggestions" auto-log/weight-increment mechanism that powers the public autocomplete widget. Captures verified behavior of JetSearch 3.6.1.3 source (`plugins/jet-search/`), live-verified 2026-07-17 against jackfruit.epeak.studio (10/10 tests.php assertions passing).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetSearch Search Suggestions & Data Store

Verified facts about how JetSearch actually stores, reads, and writes "search
suggestions" — confirmed against JetSearch 3.6.1.3 source
(`plugins/jet-search/`). Scope is deliberately narrow: the suggestions/autocomplete
feature and its data model only. The core search-query pipeline is covered by
`jetsearch-query-pipeline`; widgets/extensibility by `jetsearch-widgets-extensibility`.

## What a "suggestion" row actually is — two different things share one table

`Jet_Search_DB::tables()` (`includes/core/db.php:26-55`) defines exactly two custom
tables, both created in `create_all_tables()` (`db.php:81-104`, called from
`Jet_Search::activation()`, `jet-search.php:362,364`):

- **`{$wpdb->prefix}jet_search_suggestions`** — columns `id` (PK, auto-increment),
  `name` (text), `weight` (bigint), `parent` (text — despite the name, holds an int
  id or `0`, not a real FK-typed column), `term` (text, unused by any CRUD path read
  in this audit — always inserted as `NULL`). One row is used for **two conceptually
  different things depending on how it got there**, verified from the actual CRUD
  code, not guessed from the name:
  - **Admin-curated autocomplete entries** — created via the admin Suggestions
    screen (add/edit/delete), can have a `parent` (making it a "child" suggestion
    nested under a "parent" term) and an explicit `weight` the admin sets by hand.
  - **Auto-logged search terms** — created by the public-facing "form suggestions"
    path (see below) every time a visitor types/submits a term through the
    search-suggestions widget: `weight` starts at `1` and is incremented by `+1` on
    every repeat occurrence of the same `name` (`includes/rest-api/endpoints/form-add-suggestion.php:53-60`,
    mirrored in `includes/ajax-handlers.php:1990-1997`) — a de-facto popularity/log
    counter, not an admin-assigned number. `parent` is always `0` for these
    (`form-add-suggestion.php:94`).

  Both kinds live in the exact same table/rows with no `type`/`source` discriminator
  column — the only way to tell them apart after the fact is `parent` (children are
  always admin-curated, since nothing auto-logged ever sets `parent`) and whether
  `weight` looks like a small monotonic counter vs. a hand-picked number.

- **`{$wpdb->prefix}jet_search_suggestions_sessions`** — columns `id`, `token`
  (varchar 255), `created_at` (timestamp, default `CURRENT_TIMESTAMP`). Purely a
  rate-limit ledger for the auto-log path (below), unrelated to WP sessions/cookies.
  Rows are pruned daily by a real-cron event `clean_old_tokens_event`
  (`includes/token-manager.php:37-53`, scheduled via `schedule_token_cleanup()`,
  hooked on `wp`), or synchronously on the same request if `DISABLE_WP_CRON` is set
  (`token-manager.php:71-77`).

## `Jet_Search_DB` — the shared CRUD primitive (table-key based, not full table names)

`Jet_Search_DB::update( $table, $data, $format )` (`db.php:112-147`) is
insert-vs-update-by-presence-of-an-`id`-key: no `id` in `$data` → `$wpdb->insert()`,
returns `$wpdb->insert_id`; `id` present → `$wpdb->update()` keyed on it, returns that
same id back. `delete( $table, $where, $format )` (`db.php:149-153`) is a thin
`$wpdb->delete()` wrapper. Both take the **short table key** (`'search_suggestions'`,
not the prefixed table name) and resolve it via `tables( $table, 'name' )`
internally — passing a real table name here silently resolves to nothing and no-ops.
`count()` (`db.php:229-239`) and `query()`/`query_raw()`/`query_cached()`
(`db.php:293-407`) round out generic read access, with an in-request memory cache
(`$this->query_cache`) that `query()` uses by default (`$from_cache = true`) unless
explicitly bypassed.

**In practice, almost none of the suggestions REST/AJAX endpoints actually go through
`Jet_Search_DB::update()`/`query()`** — they mostly hand-roll raw `$wpdb->prepare()`/
`$wpdb->get_results()` calls against the table directly (see below). Only `delete()`
is consistently used across every delete path. `Jet_Search_DB` is the documented,
correct way to touch this table programmatically, but reading the endpoint source
literally will show you raw SQL more often than not.

## The REST CRUD surface — `jet-search/v1`, 5 suggestion routes

`Jet_Search_REST_API::init_endpoints()` (`includes/rest-api/manager.php:27-48`)
`require`s and registers 5 suggestion-related endpoint classes (plus the unrelated
`search-route.php`, covered by `jetsearch-query-pipeline`) via a generic
name→route loop (`register_routes()`, `manager.php:101-123`, route = `/{name}/`
under namespace `jet-search/v1`):

| Route | Class | Method | Permission |
|---|---|---|---|
| `/jet-search/v1/add-suggestion/` | `Jet_Search_Rest_Add_Suggestion` | POST | `current_user_can('manage_options')` |
| `/jet-search/v1/update-suggestion/` | `Jet_Search_Rest_Update_Suggestion` | POST | `current_user_can('manage_options')` |
| `/jet-search/v1/delete-suggestion/` | `Jet_Search_Rest_Delete_Suggestion` | POST | `current_user_can('manage_options')` |
| `/jet-search/v1/get-suggestions/` | `Jet_Search_Rest_Get_Suggestions` | GET | `manage_options` **unless** `action=get_form_suggestions`, which is always `true` (public) |
| `/jet-search/v1/form-add-suggestion/` | `Jet_Search_Rest_Form_Add_Suggestion` | POST | same-origin `HTTP_REFERER`/`HTTP_HOST` check, no auth |

All admin routes take a single `content` param: a **JSON-encoded string** of the
suggestion object (`stripcslashes()` then `json_decode( $x, true )` —
`add-suggestion.php:31-33`, `update-suggestion.php:31-33`,
`delete-suggestion.php:30-31`), not individual REST params per field.
`add-suggestion`/`update-suggestion` both reject a duplicate `name` (case-sensitive
exact match) before writing (`add-suggestion.php:49-57`,
`update-suggestion.php:45-53`). `delete-suggestion` accepts either a single
`content.id` or a bulk `content.ids` array (`delete-suggestion.php:33-59`).

`get-suggestions` (no `action`) is the admin list/grid endpoint: supports
`offset`/`per_page`/`sort` (JSON `{orderby,order}`, whitelisted column map only —
arbitrary `orderby` silently falls back to `s1.id`) and `filter` (JSON
`{search,searchType}` where `searchType` is one of `parent`/`child`/`unassigned`,
resolved via a `LEFT JOIN` of the table against itself aliased `s1`/`s2` to compute
a `child` column = "does anything have me as parent"). Response shape:
`{ success, items_list, parents_list, total, on_page }`.

## `get-suggestions?action=get_form_suggestions` and `form-add-suggestion` are the real public autocomplete pipeline — "form suggestions" is NOT a JetFormBuilder feature

Despite the name, **"form suggestions" has nothing to do with JetFormBuilder** — it's
what the *search suggestions widget's own front-end form* (the one on the page, not
an admin form) uses to (a) fetch the autocomplete dropdown list and (b) silently log
what a visitor typed as a new/incremented suggestion row. Confirmed by
`includes/assets.php:172-181`, which localizes exactly these two routes under a
`searchSuggestions` JS object consumed by the suggestions widget's own front-end
script — no JetFormBuilder class or hook is involved anywhere in this pipeline.

- **Read side** — `get-suggestions.php`'s `callback()` special-cases
  `action=get_form_suggestions` before anything else (`get-suggestions.php:42-45`)
  and delegates to `get_form_suggestions_list( $params )` (`get-suggestions.php:132-192`).
  With no `data.value` typed yet, it's a simple `WHERE parent = 0` query ordered by
  `weight DESC` ("popular") or `id DESC` ("latest") — `get-suggestions.php:151-161`.
  **With a `data.value` (the user is actively typing)**, the query does **not**
  filter `parent = 0` up front (`get-suggestions.php:145-150`) — instead it fetches
  by `name LIKE %search%`, then walks the result set promoting any matched **child**
  row's **parent** into the result set instead, via
  `Jet_Search_Tools::merge_arrays_unique_by_id()` (`get-suggestions.php:165-189`,
  `includes/tools.php:933-957`) — so typing a term that matches a child suggestion
  surfaces its parent in the dropdown, not the child itself.
- **Write side** — `form-add-suggestion.php`'s `callback()` (`form-add-suggestion.php:27-101`):
  look up by exact `name`; if found, `weight += 1` and update in place
  (lines 53-60); if not found **and** the `jet_search_suggestions_widget_suggestion_save_permission`
  option is `"true"` (default, auto-created on first use), insert a brand-new row
  with `weight = 1, parent = 0, term = NULL` (lines 91-98). **Rate limiting is
  opt-in and off by default**: only if `jet_search_suggestions_use_session` is the
  literal string `"true"` does it consult `jet_search_suggestions_sessions` — one
  token (`md5($ip . NONCE_SALT)`, `includes/token-manager.php:99-110`) per
  visitor/IP, capped at `jet_search_suggestions_records_limit` (default `5`) new
  suggestions before writes are silently dropped (`form-add-suggestion.php:77-82`,
  returns `true` with no row inserted, no error surfaced to the client either).

## AJAX handlers duplicate this almost line-for-line — and diverge on one thing

`Jet_Search_Ajax_Handlers::init()` (`includes/ajax-handlers.php:85-129`, only wired
up when `DOING_AJAX`) registers a second, near-identical set of handlers:

| `wp_ajax_*` action | Method | Mirrors REST route |
|---|---|---|
| `get_form_suggestions` (+ `nopriv`) | `get_form_suggestions()` (`ajax-handlers.php:1864-1890`) | `get-suggestions?action=get_form_suggestions` |
| `add_form_suggestion` (+ `nopriv`) | `add_form_suggestion()` (`ajax-handlers.php:1940-2038`) | `form-add-suggestion` |
| `jet_search_add_suggestion` | `add_suggestion()` (`ajax-handlers.php:2045-2097`) | `add-suggestion` |
| `jet_search_get_suggestion` | `get_suggestion()` (`ajax-handlers.php:2104-2226`) | `get-suggestions` (admin list) |
| `jet_search_update_suggestion` | `update_suggestion()` (`ajax-handlers.php:2441-2514`) | `update-suggestion` |
| `jet_search_delete_suggestion` | `delete_suggestion()` (`ajax-handlers.php:2521-2617`) | `delete-suggestion` |
| `jet_search_suggestions_remove_duplicates` | `suggestions_remove_duplicates()` (`ajax-handlers.php:622-675`) | *(no REST equivalent)* — merges duplicate-`name` rows' weight into the lowest `id`, deletes the rest, via two raw SQL statements |

**Live-verified which transport actually powers the shipped UI**: the compiled
admin Suggestions-manager UI (`assets/js/jet-search-admin-vue-components.js`) calls
`admin-ajax.php` with actions `jet_search_add_suggestion`/`_get_suggestion`/
`_update_suggestion`/`_delete_suggestion` (confirmed by grepping the bundle — no
`/jet-search/v1/…-suggestion` REST path string appears anywhere in it). **The 5 REST
CRUD routes above are live, registered, and fully functional if called directly, but
nothing in the shipped plugin actually calls them** — they appear to be a REST port
that was never wired into the admin UI, not the admin UI's real transport. Don't
assume "the REST API is what the plugin uses internally" for this feature; verify
per-plugin, since `jetcomparewishlist-data-store` found the *opposite* split (REST
admin-only, AJAX for the public flow) for a different plugin.

The **public** front-end widget (autocomplete read+auto-log) genuinely does use REST
by default — `get-suggestions?action=get_form_suggestions` / `form-add-suggestion`
are the primary transport, with the AJAX `get_form_suggestions`/`add_form_suggestion`
actions kept only as a fallback gated by the
`jet-ajax-search/assets/localize-data/use-legacy-ajax` filter (default `false`,
`includes/assets.php:188`) — the opposite direction from the admin CRUD split above.

**One real behavioral divergence** between the AJAX and REST versions of the same
read: the REST `get_form_suggestions_list()` (`get-suggestions.php:132-192`) does
the "promote matched child to its parent" logic described above when `data.value` is
set; the AJAX twin (`ajax-handlers.php:1899-1933`) does not — its `value` branch
just adds `AND parent = 0` to the query directly (line 1913) and never looks at
child rows at all. Same route name/action, genuinely different query results for a
`value`-filtered request.

## Gotchas

- **`wp_ajax_suggestions_get_user_id` is registered against a method that does not
  exist.** `ajax-handlers.php:100-101` registers both
  `wp_ajax_suggestions_get_user_id` and `wp_ajax_nopriv_suggestions_get_user_id`
  pointing at `array( $this, 'suggestions_get_user_id' )`, but
  `Jet_Search_Ajax_Handlers` (which doesn't extend anything) has no such method
  anywhere in the plugin. Triggering this action (`admin-ajax.php?action=suggestions_get_user_id`)
  fatals with "Call to undefined method" — a real, live, unauthenticated-reachable
  crash-on-demand bug in 3.6.1.3, not a hypothetical.
- **Deleting a parent suggestion never actually detaches its children — a real bug,
  not a design choice.** `remove_deleted_parent( $item, $deleted_id )` exists in both
  `delete-suggestion.php:113-132` (REST) and `ajax-handlers.php:2622-2641` (AJAX,
  identical logic) and is supposed to reset a child row's `parent` to `0` once its
  parent is deleted. It compares `$item['parent'] === $deleted_id` with **strict
  equality** — but `$item['parent']` always arrives as a **string** (from
  `$wpdb->get_results( ..., ARRAY_A )`) while `$deleted_id` is always an **int** —
  so the comparison is always `false`, the `if` branch that would call
  `$wpdb->update()` never runs, and the `else` branch's `maybe_serialize()` result is
  discarded without ever being saved. **Every child suggestion silently keeps
  pointing at a deleted parent id forever.** Live-confirmed, see `jss-9` below.
- **`parent` looks like it should be an int/FK column but is declared `text`**
  (`db.php:40`) — comparisons against it (`s1.parent = 0`, `"0" != $item['parent']`)
  rely on MySQL's loose string/int coercion, not PHP's; don't assume PHP-side
  `===`/type-strict comparisons against a freshly-fetched `parent` value will behave
  the same way the SQL did (see the bug above for exactly this trap).
- **`Jet_Search_DB` table keys are short names, not real table names** — always pass
  `'search_suggestions'`, never `$wpdb->prefix . 'jet_search_suggestions'`, to
  `update()`/`delete()`/`count()`/`query()`. Passing the real name silently no-ops
  (`tables()`'s lookup array is keyed by the short name only).
- **`get-suggestions`'s permission gate has an unauthenticated carve-out inside one
  endpoint**, not a separate route — `action=get_form_suggestions` is the only case
  where `Jet_Search_Rest_Get_Suggestions::permission_callback()` returns `true`
  unconditionally (`get-suggestions.php:407-413`); every other `$request['action']`
  value (including no action at all) requires `manage_options`. Don't assume the
  whole route is public just because you've seen it called from the front end.
  `form-add-suggestion`'s permission gate is a `HTTP_REFERER`/`HTTP_HOST` same-origin
  check (`form-add-suggestion.php:108-128`) — not a nonce alone, and it literally
  `die()`s on a cross-origin referer rather than returning a REST error.
- **Rate limiting for auto-logged suggestions is off by default.** Both
  `jet_search_suggestions_use_session` (gates the token-bucket entirely) and the
  `records_limit` it enforces default to disabled/`5` respectively, and are only
  ever created as options lazily on first read/write — don't assume either option
  exists until `suggestions_get_settings()`/`form-add-suggestion` has run at least
  once.

## How this was verified

Read `includes/core/db.php` (`Jet_Search_DB`, full file), `includes/rest-api/manager.php`,
`includes/rest-api/endpoints/base.php`, `add-suggestion.php`, `update-suggestion.php`,
`delete-suggestion.php`, `get-suggestions.php`, `form-add-suggestion.php`,
`includes/ajax-handlers.php` (the `suggestions_*`/`*_suggestion`/`get_form_suggestions`/
`add_form_suggestion` methods and their `wp_ajax_*` registrations at lines 98-129),
`includes/token-manager.php`, `includes/renders/search-suggestions.php`,
`includes/assets.php` (localize-data + allowed-REST-routes sections), and
`includes/tools.php`'s `merge_arrays_unique_by_id()`, all in JetSearch 3.6.1.3
source (`plugins/jet-search/`). The "admin UI uses AJAX, not REST" and "public
widget uses REST by default, AJAX as legacy fallback" claims were each confirmed two
ways: by reading `includes/assets.php`'s localized JS data (which REST/AJAX URLs are
actually handed to the front end and under what filter) and by grepping the compiled
`assets/js/jet-search-admin-vue-components.js` bundle for the concrete action/route
strings it calls. Live-verified against jackfruit.epeak.studio via `tests.php` (see
below) — 10/10 tests.php assertions passing, including a genuine live reproduction of
the parent-orphaning bug (`jss-9`) and a source/registration check for the
undefined-method AJAX crash (`jss-8`) that stops short of actually triggering it.
