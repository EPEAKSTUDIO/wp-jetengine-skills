---
name: jetthemecore-theme-builder
description: Use when working with JetThemeCore's Theme Builder "Page Template" layouts — a single object that bundles header/body/footer overrides and its own AND/OR condition-matching relation type, distinct from the classic per-structure Template Conditions system — including the `jet-theme-core/page-template-condition/is_excluded/and`(`/or`) hooks, the priority-averaging tie-break between competing Page Templates, or the `jet-theme-core-api/v2` REST endpoints that manage them. Captures verified behavior of `Jet_Theme_Core\Theme_Builder` from JetThemeCore 2.3.1.2 source, live-verified 2026-07-16 against jackfruit.epeak.studio (4/4 tests.php assertions passing after fixing 3 test-only bugs — see TEST-REGIMEN.md).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetThemeCore Theme Builder — Page Template layouts

Verified facts about JetThemeCore's **Theme Builder "Page Template"** feature: a
`jet-page-template` post that bundles header/body/footer override settings into one
"layout" and is matched to a request via its **own** condition-matching engine — parallel
to, and reusing condition instances from, but NOT the same code path as, the classic
per-structure `Template_Conditions\Manager::find_matched_conditions()` covered in
`jetthemecore-template-conditions`/`jetthemecore-locations`. Confirmed against
JetThemeCore 2.3.1.2 source (`plugins/jet-theme-core/`).

## Naming collision warning: two unrelated things are both called "Page Template"

1. The classic **`Page_Template` Template Condition** (`conditions/singular-page-template.php`,
   condition id `singular-page-template`) — matches on which core-WordPress *page
   template file* (`get_page_template_slug()`) a Page uses. Covered in
   `jetthemecore-template-conditions`.
2. **This skill's subject**: the Theme Builder's `jet-page-template` custom post type
   (`Theme_Builder::$post_type`, `theme-builder/manager.php:23`) — a whole-page *layout*
   object (which header/footer/body templates to force, and whether to override the
   theme's own header/footer at all) matched by its own condition set. Completely
   separate storage, completely separate matching code, same underlying Template
   Condition *instances* reused for the `check()` calls.

Do not assume a hook/method name containing "page template" refers to whichever one you
expected — always check which file it's defined in.

## Storage: post + 3 meta keys + one denormalized site-wide option

Each `jet-page-template` post (registered `public: false`, `show_in_rest: true`,
`theme-builder/manager.php:110-130`) carries:

- `_conditions` — the raw condition rows array (same row shape as classic Template
  Conditions: `{include, group, subGroup, subGroupValue, ...}`).
- `_relation_type` — `'and'` or `'or'` (default `'or'`,
  `Page_Templates_Manager::get_page_template_relation_type()`,
  `page-templates-manager.php:608-616`) — **this per-template relation-type toggle is
  the whole reason the `is_excluded/and` vs `is_excluded/or` hooks exist as two separate
  filters** instead of one — the classic Template Conditions matcher
  (`jetthemecore-template-conditions`) has no such toggle and therefore only one fixed
  OR-of-includes/OR-of-excludes rule.
- `_layout` — `{header: {id, enabled, override}, body: {id, enabled, override}, footer: {id, enabled, override}}`
  (default shape at `page-templates-manager.php:106-122`) — per-section, does this
  layout override the theme's normal header/footer with a specific other template id, or
  leave it alone.

`get_site_page_template_conditions()` (`page-templates-manager.php:65-88`) reads a single
option, `jet_page_template_conditions` (`Page_Templates_Manager::$page_template_conditions_option_key`),
shaped `{ $page_template_id => { conditions: [...], relation_type: 'and'|'or' } }` —
**this option is what the matcher actually iterates, not a `WP_Query` over
`jet-page-template` posts** — it's kept in sync whenever
`update_page_template_conditions()`/`update_page_template_relation_type()` write the
per-post meta (`page-templates-manager.php:431-465`). It also silently drops any
template id whose post no longer exists (`array_filter(..., function($id){ return
get_post_status($id); })`, `:72-74`) — a trashed-then-force-deleted Page Template just
disappears from matching with no explicit cleanup call needed.

## The matcher: `Frontend_Manager::get_matched_page_template_conditions()`

`jet_theme_core()->theme_builder->frontend_manager` (`Jet_Theme_Core\Theme_Builder\Frontend_Manager`,
`theme-builder/includes/frontend-manager.php:512-622`). For every Page Template in the
site-wide option:

1. `$page_template_id = apply_filters( 'jet-theme-core/page-template-conditions/page-template-id', $page_template_id )`
   (`:530`) — the documented WPML/multilingual hook, applied **before** any condition
   check, letting a callback redirect matching onto a translated Page Template's id.
2. Each condition row is resolved through
   `jet_theme_core()->template_conditions_manager->get_condition( $sub_group )` — **the
   exact same registry** documented in `jetthemecore-template-conditions` — then
   `check()` is called with the same undocumented-by-the-abstract 3-arg form
   (`$sub_group_value, $sub_group, $sub_group_arg`, `:554`). **Difference from the
   classic matcher**: here, an unresolvable condition instance sets `match: false`
   (`:546-549`), the opposite of `find_matched_conditions()`'s "fail open to true"
   behavior in the classic system — a deactivated-plugin condition silently disqualifies
   a Page Template here, but silently force-matches a classic Header/Footer/Single/Archive
   template there. Don't assume the two systems fail the same way.
3. Rows split into `$includes_matchs`/`$excludes_matchs` by `include` flag, same as the
   classic matcher.
4. **`'and'` relation type** (`:579-589`):
   ```php
   $is_included = ( ! empty( $includes_matchs ) && ! in_array( false, $includes_matchs ) );
   $is_excluded = ( ! empty( $excludes_matchs ) &&
       apply_filters(
           'jet-theme-core/page-template-condition/is_excluded/and',
           ! in_array( false, $excludes_matchs ),
           $excludes_matchs,
           $page_template_id
       )
   );
   ```
   — i.e. included requires *all* include rows to match; excluded (by default) requires
   *all* exclude rows to match, but that "requires all" default is itself filterable, 3
   args: the computed bool, the raw `$excludes_matchs` array, and the (possibly
   WPML-translated) `$page_template_id`. **This is exactly the gist-flagged
   `jet-theme-core/page-template-condition/is_excluded/and` hook** — confirmed real,
   fires here.
5. **`'or'` relation type** (`:590-601`) — same shape, `in_array( true, ... )` instead of
   the negated-`false` form for both includes and excludes, with its own **parallel,
   not-yet-documented-anywhere-else** filter:
   ```php
   apply_filters( 'jet-theme-core/page-template-condition/is_excluded/or', in_array( true, $excludes_matchs ), $excludes_matchs, $page_template_id )
   ```
   (`:597`) — a custom exclude policy that should apply regardless of which relation type
   an editor picked for a given Page Template must hook **both** `is_excluded/and` and
   `is_excluded/or`, mirroring the identical dual-filter pattern in JetPopup's own
   condition matcher (see `jetpopup-conditions` — same `.../is_excluded/and`+`/or` shape,
   same 3-arg signature, strongly suggesting shared Crocoblock boilerplate between the
   two plugins' condition engines, not independently invented).
6. Final gate, same for both relation types: `$is_included && ! $is_excluded`
   (`:605`) → collects into `$page_template_id_list[ $page_template_id ] = $page_template_conditions`.

## Tie-break between multiple matching Page Templates: averaged priority, not first-match

Unlike the classic system (which has **no** tie-break — see
`jetthemecore-template-conditions`), the Theme Builder matcher has a real one:
`get_primary_page_template_id_by_conditions()` (`frontend-manager.php:459-490`), called
from `get_matched_page_template_layouts()` once `get_matched_page_template_conditions()`
returns more than one candidate:

```php
$priorityA = round( array_sum( array_column( $filteredTemplateA, 'priority' ) ) / count( $filteredTemplateA ) );
```

— for each competing Page Template, take only its **include** rows (`$filteredTemplateA`,
filtered by the same `include` flag), average their `get_priority()` values (this is
where each Template Condition class's `get_priority()` — otherwise unused dead weight in
the classic matcher — actually does something), and `uasort()` ascending by that average
— **lowest average priority wins** (a template whose only include condition is
`Page_Template` at priority 50 loses to one using `CPT_Archive` at priority 9, all else
equal). A template with a non-boolean-true `include` flag on its `$templateB` in the
comparator is forced to sort first regardless of priority (`:463-466`,
`if ( ! filter_var($includeB, FILTER_VALIDATE_BOOLEAN) ) return -1;` — a malformed
include flag effectively wins the tie-break by comparator quirk, not intentional
priority logic). The winner is whichever template id is `array_key_first()` after
sorting (`:489`).

**This averaged-priority tie-break exists nowhere in the classic Template Conditions
matcher** — don't port assumptions about "the Page Template with the more specific
condition wins" back onto `find_matched_conditions()`'s Header/Footer/Single/Archive
matching.

## `is_theme_builder_render` — the flag that overrides the classic Locations pipeline

`Frontend_Manager::$is_theme_builder_render` is what `Locations\Manager::do_location()`
checks to bail out entirely (see `jetthemecore-locations`) — when a Page Template layout
matches, its `header`/`footer` sections (if `enabled` + `override` in `_layout`) are
rendered via `add_action( 'get_header', ... )`/`add_action( 'get_footer', ... )`
(`frontend-manager.php:118,122`) and `template_include` is hooked at priority 98
(`:687`) to swap in the Theme Builder's own body wrapper template
(`includes/theme-builder/templates/frontend-body-template.php`, which itself just calls
core `get_header()`/`get_footer()` around the matched body content) — a fundamentally
different rendering path than the per-structure `do_location()` dispatch, not a
specialization of it.

## The `jet-theme-core-api/v2` REST namespace

`Rest_Api` (`includes/rest-api/rest-api.php`), registered on `rest_api_init`. Every
endpoint requires `manage_options` by default (`Endpoints\Base::permission_callback()`,
`rest-api/endpoints/base.php:40-42` — override per-endpoint if a lower capability is
ever needed). Extend the list:

```php
add_filter( 'jet-theme-core/rest-api/endpoint-list', function( $endpoints ) {
    $endpoints['\\My_Plugin\\My_Endpoint'] = __DIR__ . '/my-endpoint.php';
    return $endpoints;
} );
```

(`rest-api.php:75`, same class-name-key/file-path-value convention as the two `-list`
filters in the other two JetThemeCore skills) — class must `extend
\Jet_Theme_Core\Endpoints\Base`, implementing `get_name()` and `callback( $request )`.
`do_action( 'jet-theme-core/rest-api/init-endpoints', $rest_api_instance )` fires after
(`:108`) for direct `register_endpoint()` calls instead.

Relevant to this skill specifically, under `endpoints/theme-builder/`:
`get-page-template-list`, `create-page-template`, `copy-page-template`,
`delete-page-template`, `get-page-template-conditions`, `update-page-template-data`,
`get-structure-template-list` — plus the classic-conditions-shared
`get-template-conditions`/`update-template-conditions` (writes straight through to
`Template_Conditions\Manager::update_template_conditions()`, confirmed at
`rest-api/endpoints/update-template-conditions.php:75`, which also returns a
ready-to-inject `verboseHtml` string via `post_conditions_verbose()` in the same
response — the admin UI never separately re-fetches a rendered summary). All routes are
plain `GET`/`POST` REST, not admin-ajax, and are the same routes
`wp_localize_script( 'jet-theme-builder-script', 'JetThemeBuilderConfig', ... )`
(`theme-builder/manager.php:185-212`) hands to the Theme Builder's own Vue admin app —
calling them directly from custom PHP/JS is the supported way to manage Page Templates
programmatically without reimplementing the meta-write logic in
`Page_Templates_Manager` by hand.

## Gotchas

- The **same** Template Condition class instance backs both matchers (classic and Theme
  Builder) — a custom condition registered via
  `jet-theme-core/template-conditions/conditions-list` (see
  `jetthemecore-template-conditions`) is automatically usable for Page Template
  conditions too, no separate registration needed. But its `check()`'s "unresolvable
  condition" fallback behaves **oppositely** in the two matchers (fail-open here is
  fail-closed there, or vice versa depending which is "here") — see point 2 above.
- `get_priority()` is dead weight in the classic matcher but load-bearing in this one —
  don't assume a condition class's priority value is cosmetic just because one of the
  two systems ignores it.
- `is_excluded/or` is functionally identical in shape to `is_excluded/and` (3 args, same
  semantics of "should the exclude-rows verdict be treated as true") but was **not**
  flagged by the gist-research backlog at all — only the `/and` variant was — so a
  hook implemented only for `/and` silently does nothing for any Page Template
  configured with the `'or'` relation type.
- **Not corroborated by official docs**: as with the other two JetThemeCore skills,
  Crocoblock's public `developer-documentation` GitHub repo has no JetThemeCore folder
  (checked 2026-07-16) — no first-party reference exists for any of this.

## How this was verified

Read `includes/theme-builder/manager.php`,
`includes/theme-builder/includes/page-templates-manager.php` (`get_site_page_template_conditions()`,
`create_page_template()`, `update_page_template_conditions()`,
`update_page_template_relation_type()`, `update_page_template_layout()`,
`get_page_template_relation_type()`), `includes/theme-builder/includes/frontend-manager.php`
(`get_matched_page_template_conditions()`, `get_primary_page_template_id_by_conditions()`,
`get_matched_page_template_layouts()`, `is_layout_structure_override()`), and
`includes/rest-api/rest-api.php` + `includes/rest-api/endpoints/base.php` +
`includes/rest-api/endpoints/update-template-conditions.php` in JetThemeCore 2.3.1.2
source, confirming both `is_excluded/and`/`is_excluded/or` hooks fire with the documented
3-arg signature, the averaged-priority tie-break logic, and the opposite fail-open/
fail-closed unresolvable-condition behavior versus the classic matcher, all by direct
file:line citation. Cross-referenced `jetpopup-conditions` (a parallel skill covering an
almost structurally identical `is_excluded/and`+`/or` matcher in a different Crocoblock
plugin) to confirm this is shared boilerplate, not independently reverse-engineered
convergent design. Not yet verified against a running site — see `TEST-REGIMEN.md`.
