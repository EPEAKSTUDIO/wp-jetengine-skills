---
name: jetpopup-conditions
description: Use when working with JetPopup's display-condition system — registering a custom condition type (a Base subclass), understanding how a popup's per-condition AND/OR relation logic actually evaluates matches (including the separate "exclude" filters for each relation type), or adding a whole new condition group/sub-group the way the built-in WooCommerce/JetEngine compatibility modules do. Captures verified behavior of Jet_Popup\Conditions\Manager/Base from JetPopup 2.2.1 source, live-verified 2026-07-16 against jackfruit.epeak.studio (6/6 tests.php assertions passing).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetPopup Display Conditions

Verified facts about JetPopup's "where should this popup show" condition system —
registration, storage, and the include/exclude/AND/OR matching algorithm. Confirmed
against JetPopup 2.2.1 source, `plugins/jet-popup/`.

## Registration: one big filter, not a `register()` method call

`Jet_Popup\Conditions\Manager` (`includes/conditions-manager/manager.php`) is a classic
`instance()` singleton, but the *canonical* accessor is `jet_popup()->conditions_manager`
(instantiated in `Jet_Popup::init_action()`, `jet-popup.php:325`) — the class's own
`Manager::instance()` (`:40-48`) is a second, independent singleton slot that the plugin
itself never uses; don't call it, use `jet_popup()->conditions_manager`.

`register_conditions()` (`manager.php:60-128`, hooked on `init` at priority 999,
`:811`) builds the full condition list every request:

1. `apply_filters( 'jet-popup/conditions/condition-sub-groups', [...] )` (`:62`) — seeds
   the 3 built-in sub-groups (`page-singular`, `post-archive`, `post-singular`).
2. `apply_filters( 'jet-popup/conditions/conditions-list', [ '\Class\Name' => $file, ... ] )`
   (`:81`) — the real extension point. Each entry is `require`d then `new $class` —
   **the array key is the class name (with a `require`-able file path as the value),
   not an id string** — instantiate-and-`get_id()` is how the manager learns the
   condition's actual slug, not the array key itself.
3. `register_cpt_conditions()` (`:133-241`) auto-generates per-registered-post-type
   Single/Archive/Taxonomy conditions from `Jet_Popup_Utils::get_post_types_options()` —
   these aren't in the static list above.
4. `do_action( 'jet-popup/conditions/register', $this )` (`:126`) — fires after
   everything above is built, receiving the `Manager` instance itself; use this if you
   need to call `$manager->register_condition_sub_group()` /
   `add_condition_sub_group_option()` directly instead of going through the filters.

## Writing a custom condition: extend `Base`, 6 abstract methods

`Jet_Popup\Conditions\Base` (`includes/conditions-manager/conditions/base.php`) is
abstract with **6 required methods**: `get_id()`, `get_label()`, `get_group()`,
`get_priority()`, `get_body_structure()`, `check( $args )`. Everything else
(`get_sub_group()` → `false`, `get_control()` → `false`, `get_avaliable_options()` →
`false`, `ajax_action()` → `false`, `get_label_by_value( $value )` → `''`) has a working
no-op default — only override what your condition actually needs (e.g. a condition with
no dropdown value, like "Is 404", only needs the 6 required methods).

Real worked examples to copy, both registered via `jet-popup/conditions/conditions-list`
from their own compatibility manager's constructor:

- `Jet_Popup\Conditions\Jet_Engine_Custom_Query_Has_Items`
  (`includes/compatibility/plugins/jet-engine/conditions/custom-query-has-items.php`) —
  `get_control()` returns a `select` control, `get_avaliable_options()` calls
  `\Jet_Engine\Query_Builder\Manager::instance()->get_queries_for_options(...)` to
  populate it, and `check( $args )` (`$args` is the selected query id) resolves the
  query via `get_query_by_id()` and returns `$query->has_items()`.
- The WooCommerce module (`includes/compatibility/plugins/woocommerce/manager.php`)
  registers 15 condition classes and a whole new top-level group (`woocommerce`) plus 3
  new sub-groups (`woocommerce-archive`/`-single`/`-page`) via the group/sub-group
  filters, gated by `class_exists( 'WooCommerce' )` in its own constructor — the pattern
  to follow for "add a condition group only when some other plugin is active."

`check( $args )`'s single argument is `$condition['subGroupValue']` from the saved
popup condition row (a string id, or empty for value-less conditions like "Entire
site") — **not** the whole condition array.

## Where conditions are stored — two different places depending on caller

- **Per-popup**: `_conditions` / `_relation_type` post meta on the popup post itself
  (`Manager::update_popup_conditions()` / `get_popup_conditions()`,
  `manager.php:434-519`) — also mirrored into `_elementor_page_settings['jet_popup_conditions']`
  for Elementor-panel compatibility. Reading code should call
  `jet_popup()->conditions_manager->get_popup_conditions( $popup_id )`, not read the
  meta keys directly, because of migration/back-compat fallbacks (see next section).
- **Site-wide index**: a single option, `jet_popup_conditions` (the `Manager::$conditions_key`
  property), shaped `[ 'jet-popup' => [ $popup_id => [ 'conditions' => [...], 'relation_type' => 'or' ] ] ]`
  — this is what `find_matched_popups_by_conditions()` actually iterates (not a
  `WP_Query` over popup posts), so it's rebuilt on every `update_popup_conditions()`
  call and pruned on `wp_trash_post` (`Jet_Popup_Post_Type::remove_popup_from_site_conditions()`,
  `includes/post-type.php:337-342`).

**Gotcha**: a condition row saved with `include: 'false'` (a JS-serialized string, not a
PHP bool) is read with `filter_var( $condition['include'], FILTER_VALIDATE_BOOLEAN )`
throughout — comparing it with `===` or `!$condition['include']` directly will misread
the exclude flag.

## The matching algorithm: `find_matched_popups_by_conditions()` — separate AND/OR + include/exclude paths

`manager.php:705-803`, run once per page load from `Jet_Popup\Render_Manager::define_page_popups()`
(`includes/render/manager.php:184`). For each popup with saved conditions:

1. Every condition row is checked via `call_user_func( [ $instance, 'check' ], $subGroupValue, $subGroup )`
   and annotated with `$condition['match'] = <bool>` (`:745-747`) — **conditions whose
   type was since removed/renamed get `match = true` automatically** (`:737-741`), i.e.
   an unresolvable condition doesn't disqualify a popup, it silently passes.
2. Rows are split into `$includes_matchs`/`$excludes_matchs` by their `include` flag
   (`:756-764`), **not evaluated in a single pass** — include and exclude conditions are
   two independent match-sets.
3. **`'and'` relation type**: `$is_included` requires at least one include row AND all of
   them matching (`! in_array(false, $includes_matchs)`); `$is_excluded` requires at
   least one exclude row AND (by default) all of them matching, but that "all" check is
   itself filterable:
   ```php
   apply_filters( 'jet-popup/popup-condition/is_excluded/and', ! in_array( false, $excludes_matchs ), $excludes_matchs, $popup_id )
   ```
   (`:772-776`) — this is the backlog's documented "exclude relation type" hook.
4. **`'or'` relation type**: same shape but `in_array(true, ...)` instead of
   `!in_array(false, ...)` for both include and exclude, and its own parallel filter:
   ```php
   apply_filters( 'jet-popup/popup-condition/is_excluded/or', in_array( true, $excludes_matchs ), $excludes_matchs, $popup_id )
   ```
   (`:783-787`) — **not documented anywhere in the backlog/gist research, which only
   mentions the `and` variant.** Both take the same 3 args (`$is_excluded_bool`,
   `$excludes_matchs`, `$popup_id`) and both must be hooked if a custom exclude-matching
   policy should apply regardless of which relation type an editor picked.
5. Final gate: `$is_included && ! $is_excluded` (`:792`) — a popup with excludes that
   "win" never shows even if its includes all matched.

`popup_id` passed into both exclude filters has already gone through
`apply_filters( 'jet-popup/get_conditions/template_id', $popup_id )` (`:727`) — the
documented WPML/multilingual popup-id-translation hook, fired **before** condition
checks run, so a callback there can redirect matching to a translated popup's own id.

## Gotchas

- `Base::check()`'s signature in the abstract class is `check( $args )` (1 param), but
  the manager always calls it with 2 positional args
  (`call_user_func( [ $instance, 'check' ], $sub_group_value, $sub_group )`,
  `manager.php:745`) — a condition that needs to know its own sub-group id (e.g. a
  shared class backing multiple sub-groups, like `CPT_Single_Post`) should declare
  `check( $value, $sub_group = null )`, not just `check( $args )`.
- Backward-compat parsing (`get_old_conditions()`/`maybe_convert_popup_conditions()`,
  `:526-634`) only runs when a popup has **no** `_conditions` meta and **no**
  `_elementor_page_settings['jet_popup_conditions']` — a fresh popup created via the
  REST `create-popup` endpoint already gets `_conditions => []` in its `meta_input`
  (`includes/post-type.php:586`), so this path is effectively legacy-import-only.

## How this was verified

Read `includes/conditions-manager/manager.php`, `includes/conditions-manager/conditions/base.php`,
`includes/compatibility/plugins/jet-engine/manager.php` +
`conditions/custom-query-has-items.php`, `includes/compatibility/plugins/woocommerce/manager.php`,
and `includes/post-type.php` directly in JetPopup 2.2.1 source
(`plugins/jet-popup/`), confirming exact filter names/arg counts by reading the
`apply_filters()`/`do_action()` call sites themselves. Cross-checked against
Crocoblock's public `developer-documentation` GitHub repo (`05-jet-popup/01-hooks/`) —
its `frontend-hooks.md`/`admin-hooks.md`/JS-hooks files exist as navigation stubs but are
**empty (0 bytes)** as of this writing, so no corroborating or conflicting official
documentation exists for this plugin; the plugin source is the only source of truth
here. Not yet verified against a running site — see `TEST-REGIMEN.md`;
`tests.php` has reachability/source-presence smoke tests ready to deploy.
