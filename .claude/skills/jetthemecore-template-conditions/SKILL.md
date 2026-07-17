---
name: jetthemecore-template-conditions
description: Use when registering a custom Template Condition type for JetThemeCore (Crocoblock's Elementor/Block-Editor theme-builder plugin), when a Header/Footer/Single/Archive template isn't matching the page you expect, or when deciding which post types/taxonomies show up as condition options. Captures verified behavior of `Jet_Theme_Core\Template_Conditions\Manager` from JetThemeCore 2.3.1.2 source, live-verified 2026-07-16 against jackfruit.epeak.studio (5/5 tests.php assertions passing after fixing a real fatal-instantiation bug found in this doc — see SKILL.md's Bootstrap section and TEST-REGIMEN.md).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetThemeCore Template Conditions

Verified facts about JetThemeCore's **Template Conditions** system — the registry of
"where should this template apply" rules (Front Page, Singular Page, Archive Category,
custom-post-type singular/archive, device, user role, URL param, ...) that every
Header/Footer/Single/Archive/Section template is matched against. Confirmed against
JetThemeCore 2.3.1.2 source (`plugins/jet-theme-core/`).

## The registry: `Jet_Theme_Core\Template_Conditions\Manager`, reached via `jet_theme_core()->template_conditions_manager`

Constructed once in `Plugin::init()` (`includes/plugin.php:139`) and hooks its own
registration at `init` priority 999 (`template-conditions/manager.php:848`) — late enough
that custom post types are already registered. **Do not construct a second
`Template_Conditions\Manager` yourself**, and — **live-verified correction, 2026-07-16** —
**do not call `register_cpt_conditions()` a second time either.** The class constructor
itself has no unconditional `require`, but `register_cpt_conditions()`
(`manager.php:136-289`, see below) does an unconditional `require` (not `require_once`)
of 4 condition class files (`:139-142`) every time it runs — a second call re-`require`s
already-loaded files and throws an uncatchable "Cannot redeclare class" fatal, confirmed
live by isolating the exact call. The rest of `register_conditions()`'s built-in
registration (everything besides the CPT-condition loop) *is* safe to re-trigger, since
`add_condition()` itself is a harmless no-op the second time (`manager.php:401-410`) — the
CPT-condition method is the one specific exception, because of its file-`require`s, not
because of anything `add_condition()`-related. The live singleton is always reachable as
`jet_theme_core()->template_conditions_manager` — there is no legitimate reason to call
either the constructor or `register_cpt_conditions()` again from outside the plugin's own
bootstrap.

## Registering a custom condition type

```php
add_filter( 'jet-theme-core/template-conditions/conditions-list', function( $conditions ) {
    $conditions['\\My_Plugin\\My_Condition'] = __DIR__ . '/my-condition.php';
    return $conditions;
} );
```

(`template-conditions/manager.php:81`, inside `register_conditions()`) — the filter
receives/returns an **array keyed by fully-qualified class name, valued by the file path
that defines it**; `register_conditions()` then `require`s each file and does `new
$class` itself (`:115-125`) — you don't instantiate it, just register the mapping. The
class must `extend \Jet_Theme_Core\Template_Conditions\Base` (abstract,
`conditions/base.php:9`), which declares `get_id()`, `get_label()`, `get_group()`,
`get_priority()`, `get_body_structure()`, and `check( $args )` as abstract, plus optional
overridable methods (`get_sub_group()`, `get_control()`, `get_avaliable_options()`,
`ajax_action()`, `get_label_by_value()`, `get_node_data()` — all default to `false`/`''`
on the base class).

A second, later hook fires after all built-ins (including the auto-generated CPT ones,
see below) are registered:

```php
do_action( 'jet-theme-core/template-conditions/register', $manager );
```

(`:129`) — `$manager` is the `Manager` instance itself; call
`$manager->add_condition( $id, $instance )` here instead if you'd rather register an
already-constructed instance directly (bypassing the require-a-file convention above).

## `check()`'s real signature: 3 args despite the abstract declaring 1

`Base::check( $args )` is declared abstract with one parameter, but every call site
(`find_matched_conditions()` at `manager.php:543`, and the Theme Builder's own
`get_matched_page_template_conditions()` — see `jetthemecore-theme-builder`) invokes it
as:

```php
call_user_func( array( $instance, 'check' ), $sub_group_value, $sub_group, $sub_group_arg );
```

i.e. **`check( $value, $sub_group_id, $arg )`** — `$value` is whatever the condition's
own `get_control()`-driven UI stored (a post ID, taxonomy term id, template slug, etc.),
`$sub_group_id` is the condition's own id (rarely used inside `check()` itself), and
`$arg` is an optional extra value from a `get_arg_control()`-configured secondary field
(used by e.g. a "URL param name = value" condition needing both a param name and a
value). PHP doesn't enforce parameter-count matching against an abstract method
declaration, so a real subclass silently receiving only its first declared `$args` param
still gets called with all 3 — write `check( $value = '', $sub_group = '', $arg = '' )`
in a custom condition, not `check( $args )` verbatim, or the 2nd/3rd args are simply
unreachable inside the method body (not an error, just silently dropped).

## Built-in conditions and groups

Conditions are organized into 4 top-level **groups** (`entire`, `singular`, `archive`,
`advanced`, `get_conditions_raw_data()` at `manager.php:755`) and, within `singular`/
`archive`, further into **sub-groups** (`page-singular`, `post-singular`, `post-archive`,
plus one auto-created `{post_type}-single-post`/`{post_type}-archive` pair per public
custom post type — see below). Built-ins registered directly in
`register_conditions()`'s `conditions-list` filter default array (`manager.php:82-112`):
`Entire`, `Archive_All`, `Archive_All_Post`, `Archive_Category`, `Archive_Tag`,
`Archive_Search`, `Front_Page`, `Page`, `Page_Child`, `Page_Template`, `Page_404`,
`Post`, `Post_From_Category`, `Post_From_Tag`, `CPT_Singular_Post_Type`,
`CPT_Archive_Post_Type`, `CPT_Archive_Taxonomy`, `Url_Param`, `Device`, `Roles`,
`Mobile_OS`, `Mobile_Browsers`, `WP_Option` — one file per class under
`includes/template-conditions/conditions/`.

**`Page_Template`'s `check()` gotcha**: this is the condition behind "match on a
specific WordPress Page Template file", not the JetThemeCore *Theme Builder Page
Template* layout concept (different feature entirely, see `jetthemecore-theme-builder`)
despite the name collision. Its `check( $arg )` (`conditions/singular-page-template.php:120`)
returns `false` immediately if `$arg` is empty or `! is_page()` — so it can never match
on a CPT singular even if that CPT also uses a custom page template file.

## Custom-post-type conditions are auto-generated, not filter-registered

`register_conditions()` finishes by calling `register_cpt_conditions()`
(`manager.php:127,136-289`), which loops **every post type returned by
`Utils::get_custom_post_types_options()`** (see next section) and, per post type,
constructs (directly, no class-map filter) one `CPT_Archive` instance (`cpt-archive-{slug}`),
one `CPT_Single_Post` instance (`cpt-single-{slug}`), and — per public/nav-menu-visible
taxonomy attached to that post type — one `CPT_Taxonomy` + one `CPT_Single_Post_Term`
pair. **There is no filter to add/remove one of these generated instances directly** —
the only lever is which post types/taxonomies feed the loop (next section) or removing
via `jet-theme-core/template-conditions/register` afterward using
`$manager->get_condition( $id )`/reassigning `_conditions` isn't exposed either — the
array is `private $_conditions`, so a truly custom CPT condition variant must register
under its own new id via `conditions-list`, not by overwriting a `cpt-*` one.

## Corrected fact: the *current* mechanism for post-type/taxonomy inclusion is a plain "opt-out" filter, not the "deprecated"-named ones alone

The gist-research backlog flagged `jet-theme-core/post-types-list/deprecated` and
`jet-theme-core/custom-post-types-list/deprecated` as literally named "deprecated" in
their gist titles. **Confirmed: these are real, currently-firing filters in 2.3.1.2 —
not dead code — the "deprecated" in the hook name refers to the *default excluded post
types list itself* being a historical hardcoded blocklist, not to the filter hook being
retired.** There is no newer, differently-named replacement filter in this version; this
remains the one and only mechanism:

```php
// includes/utils.php:90-128 — Utils::get_post_types(), used for the plugin's general
// "pick a post type" UI (e.g. Locations settings), independent of conditions.
$deprecated = apply_filters( 'jet-theme-core/post-types-list/deprecated', [
    'page', 'jet-woo-builder', 'e-landing-page', 'jet-form-builder', 'jet-engine',
    'jet-menu', 'attachment', 'elementor_library', 'jet-theme-core', 'jet-block-template',
] );
// any public post type slug in this array is excluded from the returned list
```

```php
// includes/utils.php:159-176 — Utils::get_custom_post_types_options(), the one that
// actually feeds register_cpt_conditions()'s loop, i.e. THIS is the filter that controls
// which CPTs get their own Template Conditions (archive/single/taxonomy) auto-generated.
$deprecated = apply_filters( 'jet-theme-core/custom-post-types-list/deprecated', [
    'post', 'jet-menu', 'e-floating-buttons',
] );
```

To make a custom post type available as a condition source (or, going the other
direction, hide one that shouldn't be), filter `custom-post-types-list/deprecated` — e.g.
add `'my_internal_cpt'` to exclude it, or remove `'post'` from the default array if you
specifically want core Posts to get its own CPT-style archive/single conditions (they
already have dedicated `Post`/`Archive_All_Post`/`Post_From_Category`/`Post_From_Tag`
conditions instead — removing `'post'` here would generate a redundant
`cpt-single-post`/`cpt-archive-post` pair alongside those). `post-types-list/deprecated`
only affects the separate general-purpose post-type picker (`get_post_types()`), not the
conditions registry — don't confuse the two when only one needs adjusting.

**Custom taxonomies** aren't gated by their own filter — `register_cpt_conditions()`
calls `Utils::get_taxonomies_by_post_type( $post_type_slug )` (`manager.php:150`,
`utils.php:183-192`) for every post type that survives the `custom-post-types-list`
filter, and includes any taxonomy attached to it with `public: true` and
`show_in_nav_menus: true` — a private or admin-only custom taxonomy is silently excluded
from conditions with no filter to opt it back in short of making it public/nav-menu
visible (there's a separate, currently-unused `Utils::get_taxonomies()` with a
`jet-theme-core/taxonomies-list/deprecated` filter — `utils.php:237-244` — but it's not
the code path `register_cpt_conditions()` actually calls; it's dead weight for the
conditions system specifically, though may be used elsewhere in the plugin's admin UI).

## Matching: `find_matched_conditions( $type, $single = false )`

The engine behind "which template applies to structure `$type`" (`$type` is a structure
id like `jet_header`/`jet_single`/`jet_archive`, see `jetthemecore-locations`):
`manager.php:503-583`.

1. Reads `get_option( 'jet_site_conditions', [] )[$type]` — an array keyed by
   **template post id**, each value an array of per-condition rows
   `{id, include, group, subGroup, subGroupValue, subGroupArg}` (this option is written
   by `update_template_conditions()`, `:433-454`, called from the
   `update-template-conditions` REST endpoint).
2. Skips any template whose conditions array is empty or still has the legacy `'main'`
   key (pre-2.0 format not yet converted — see `maybe_update_backward_conditions()`,
   `:345-385`, which runs once on every request via the constructor if
   `JET_THEME_CORE_VERSION >= 2.0.0`, but only migrates rows it can, leaving stragglers
   with `'main'` permanently unmatched).
3. For each remaining condition row: `'entire'` group always matches; otherwise resolves
   the condition instance via `get_condition( $sub_group )` and calls `check()` (3-arg
   form above) — **if the sub_group's condition instance can't be found at all (e.g. it
   was registered by a since-deactivated plugin), the row is treated as `match: true`**
   (`:534-538`) rather than `false` — a silent "fail open" that can make a template with
   a now-orphaned condition match unconditionally.
4. Splits the row's `match` results into `$includes_matchs`/`$excludes_matchs` by each
   row's own `include` flag (a string `'true'`/`'false'`, parsed with
   `filter_var(..., FILTER_VALIDATE_BOOLEAN)`, not a real bool — `:522`).
5. **A template matches only if at least one include condition is `true` AND no exclude
   condition is `true`** (`:568`, a plain `in_array( true, ... )` OR-of-includes / OR-of-excludes
   check — this is a simpler, fixed OR/AND-of-excludes rule with **no per-template
   AND/OR relation-type toggle**, unlike the Theme Builder's own page-template matcher —
   see `jetthemecore-theme-builder`'s `is_excluded/and`/`is_excluded/or` hooks, which
   exist there specifically because that newer system added a configurable relation type
   this older one never got).
6. Returns an array of all matching template ids (option order, which is
   most-recently-updated-first thanks to `array_reverse()` in `update_template_conditions()`,
   `:450`) or `false` if none — **no priority-based tie-break at all**: if two templates
   both match the same structure, `find_matched_conditions()` returns both, and it's the
   *caller* (`Locations\Manager::do_location()`, `locations/manager.php:79`) that
   arbitrarily takes `$template_ids[0]` — effectively "whichever was saved most recently
   wins," not "whichever condition is more specific," despite every condition class
   exposing a `get_priority()` value that looks like it should drive this.
   `get_priority()` is real (documented on `get_conditions_raw_data()`'s admin-editor
   payload, `:780`) but **this method never reads it** — it's purely a UI hint for how
   the admin condition-picker orders/nests options, not a runtime tie-breaker.

## Gotchas

- `get_priority()` values (e.g. `Page_Template` = 50, `CPT_Archive` = 9,
  `CPT_Taxonomy` = 8) look like a specificity-ranking system but are **not consulted by
  `find_matched_conditions()`** — see point 6 above. Don't assume registering a custom
  condition with a very high/low priority changes which template wins when two match the
  same structure.
- Registering two conditions under the same class-map key is a no-op the second time
  (`add_condition()` returns `false` silently, `:401-410`) — but registering the *same
  condition id* under a *different* class entirely still silently loses the first
  registration's instance if you called `add_condition()` directly from the
  `template-conditions/register` action instead of going through the
  `conditions-list` filter (which itself doesn't protect against a duplicate key inside
  the array you return — a later filter callback with the same class-name key simply
  overwrites the earlier one before `register_conditions()` ever loops it).
- A condition instance whose backing plugin got deactivated doesn't just vanish quietly
  from the admin UI — see point 3 above, it can make its template match **every** page.
- **`register_cpt_conditions()` fatals on a second call** — see the "Bootstrap" section
  above. Live-verified 2026-07-16: isolating a direct second call to
  `jet_theme_core()->template_conditions_manager->register_cpt_conditions()` in its own
  test snippet threw an uncatchable "Cannot redeclare class" (500, no catchable exception)
  because of the unconditional `require`s at `manager.php:139-142`.
- `Utils::get_taxonomies()` (with its own `jet-theme-core/taxonomies-list/deprecated`
  filter) exists but is **not** the function `register_cpt_conditions()` calls — don't
  assume filtering it affects which taxonomies get Template Conditions.
- **Not corroborated by official docs**: Crocoblock's public
  `developer-documentation` GitHub repo (github.com/Crocoblock/developer-documentation,
  checked 2026-07-16) has folders for JetEngine, JetSmartFilters, JetFormBuilder,
  JetPopup, JetBooking, JetWooProductGallery, and JetCompareWishlist, but **no folder for
  JetThemeCore at all** despite it being listed in the repo's own "Main Plugins" README
  section — everything in this skill is sourced from plugin code directly, not
  cross-checked against any first-party developer doc (because none exists).

## How this was verified

Read `includes/template-conditions/manager.php` (`register_conditions()`,
`register_cpt_conditions()`, `find_matched_conditions()`, `maybe_convert_conditions()`,
`get_conditions_raw_data()`), `includes/template-conditions/conditions/base.php`,
`includes/template-conditions/conditions/singular-page-template.php`, and
`includes/utils.php` (`get_post_types()`, `get_custom_post_types_options()`,
`get_taxonomies_by_post_type()`, `get_taxonomies()`) in JetThemeCore 2.3.1.2 source,
confirming both gist-flagged "deprecated" filters are real and currently firing (not
dead), the true 3-arg `check()` call signature, the CPT-condition auto-generation loop,
and the "no priority tie-break" finding by direct file:line citation. Cross-checked
Crocoblock's public `developer-documentation` GitHub repo and confirmed it has no
JetThemeCore section to compare against. Not yet verified against a running site — see
`TEST-REGIMEN.md`.
