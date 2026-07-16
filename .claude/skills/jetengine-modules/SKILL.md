---
name: jetengine-modules
description: Use when working with a JetEngine module that isn't CCT/Relations/Query Builder/Listings — Meta Boxes (custom fields for post/term/user/options, not CCT), Options Pages, Data Stores (favorites/recently-viewed lists), Dynamic Visibility (conditional display for Elementor/Blocks/Bricks), Glossaries, or Custom Meta Tables (per-field custom-table post meta storage). Captures verified behavior from JetEngine 3.8.12 source, and live-verified via tests.php. See TEST-REGIMEN.md.
license: MIT
metadata:
  author: project
  version: "0.4.0"
---

# JetEngine standalone modules

**Live-verified (2026-07-16):** this skill now has a runnable suite
(`tests.php`, 10 tests, `mod-1` through `mod-10`) per `docs/test-harness-guide.md`. While
writing the first version, re-reading the Custom Meta Tables source turned up a real
documentation bug — the namespace claim below was wrong (see that section for the
correction) — `mod-6` now asserts the fix directly. See `TEST-REGIMEN.md` for the full
run log and pass/fail results.

Several JetEngine subsystems are self-contained enough that they don't fit
`jetengine-cct-internals`, `jetengine-relations`, or `jetengine-query-builder`, but are
still common places an agent invents a plausible-sounding function name that doesn't
exist. Each section below is independent — read the one relevant to the task.

## Meta Boxes (custom fields for post/term/user/options — NOT CCT)

Distinct from Custom Content Types: this is JetEngine's wrapper for adding custom
fields to **existing** WP objects (a post type, taxonomy, user profile, or an options
page), not a new standalone data table.

- `Jet_Engine_Meta_Boxes` — `includes/components/meta-boxes/manager.php` — reachable as
  `jet_engine()->meta_boxes`.
- Register a field group programmatically:
  ```php
  jet_engine()->meta_boxes->register_metabox(
      $post_type, $meta_fields, $title, $object_name, $context
  ); // manager.php:421
  ```
- Introspect what's registered: `get_fields_for_context( $context, $object )`
  (`manager.php:472`) and `get_registered_fields()` (`manager.php:489`) — works
  generically across post/term/user/options contexts. **Must be called at `init`
  priority ≥ 11** per the source docblock (`manager.php:463`) — calling it earlier
  returns incomplete/empty results, not an error, which is an easy silent-failure trap.
- Hooks: `do_action('jet-engine/meta-boxes/register-instances', $this)`
  (`manager.php:256`), `apply_filters('jet-engine/meta-boxes/raw-fields', $meta_fields, $this)`
  (`manager.php:266`), per-object-type
  `do_action('jet-engine/meta-boxes/register-custom-source/' . $object_type, ...)`
  (`manager.php:404`).

**Don't** reach for core `add_meta_box()` and hand-roll field storage when the fields
were actually defined through JetEngine's meta-box UI — read them back through
`get_fields_for_context()`, not by guessing at a `register_metabox()`-adjacent template
function that doesn't exist.

**Adding a custom "Options Source" for checkbox/radio/select fields** is a **two-filter
pairing**, not one — easy to implement only half of and get an empty dropdown:

```php
// 1. Register the source's name (shows up in the field's "Options Source" dropdown)
add_filter( 'jet-engine/meta-boxes/option-sources', function( $sources ) {
    $sources['user_roles'] = __( 'User Roles' );
    return $sources;
} ); // Jet_Engine_Meta_Boxes_Option_Sources::get_allowed_sources(), fields-options/option-sources.php:411

// 2. Supply the actual option list when a field's options_source matches your key
// Fires as `apply_filters( ..., $options, $field, $this )` from Jet_Engine_CPT_Meta::filter_options_list()
// (post.php:1545) — 3 args, register with `10, 3` even though only $field is needed here.
add_filter( 'jet-engine/meta-fields/field-options', function( $options, $field ) {
    if ( 'user_roles' !== ( $field['options_source'] ?? '' ) ) {
        return $options;
    }
    global $wp_roles;
    foreach ( $wp_roles->roles as $slug => $role ) {
        $options[] = array( 'value' => $slug, 'label' => $role['name'] );
    }
    return $options;
}, 10, 3 );
```

Registering only the source name makes it selectable in the admin but yields an empty
options list at render time; registering only the options filter without the source name
means the admin UI never offers it as a choice.

## Options Pages

- `Jet_Engine_Options_Pages` — `includes/components/options-pages/manager.php`,
  reachable as `jet_engine()->options_pages`.
- `Jet_Engine_Options_Page_Factory` (extends the same `Jet_Engine_CPT_Meta` base as
  Meta Boxes) — `includes/components/options-pages/options-page.php`.
- **There is no `jet_engine_get_option()` template-tag helper** — agents commonly
  invent one. The real read path is object-oriented:
  ```php
  $value = jet_engine()->options_pages->registered_pages[ $page_slug ]->get( $option_name, $default );
  ```
  (`options-page.php:488`)
- Write programmatically: `update_options( $data, $rewrite, $sanitize )`
  (`options-page.php:226`).
- **Storage mode changes what `get_option()` would even see directly**: check
  `get_separate_option_name()` (`options-page.php:520`) — if the page's storage is
  `'separate'`, each option is its own `wp_options` row (readable via core
  `get_option()` with the derived name); otherwise all fields live serialized inside
  one option keyed by the page slug, and core `get_option()` on an individual field
  name will return nothing.
- Hooks: `do_action('jet-engine/options-pages/updated/' . $slug, $this)` and the
  non-namespaced `'jet-engine/options-pages/updated'` (`options-page.php:283,291`),
  `do_action('jet-engine/options-pages/after-save', $this)` (`options-page.php:200`).
- **Registering a whole options page programmatically** (not just reading/writing an
  existing one) is a real, public one-liner — confirmed at `manager.php:122-126`:
  `jet_engine()->options_pages->register_new_options_page( $args )`. `$args` is the same
  shape the admin UI saves (`slug`, `title`, `fields` — a repeater-style array of field
  definitions, same schema Meta Boxes uses — plus optional `id`/`parent` for a child
  page). This is what `Manager::register_instances()` itself calls in a loop over
  configured pages on `init`, so calling it yourself at the same priority ships a page
  with no manual admin-UI setup step, the same pattern as the Relations
  `raw-relations` filter (see `jetengine-relations`) — except this one's a plain method
  call, not a filter you append to.

## Data Stores (favorites / recently-viewed / on-view tracking — NOT a generic KV store)

The name invites a wrong assumption: this is specifically for **lists of post IDs**
(wishlists, recently-viewed, comparison lists) backed by cookies/user-meta/session, not
a generic key-value API.

- `Jet_Engine\Modules\Data_Stores\Stores\Manager` —
  `includes/modules/data-stores/inc/stores/manager.php`.
- Abstract `Base_Store` — same directory, `base.php` — concrete built-ins:
  `Cookies_Store`, `User_Meta`, `Session`, `Local_Storage`, `On_View`, `User_IP*`.
- Fetch/register:
  ```php
  $store = jet_engine()->data_stores->stores_manager->get_store( $store_id ); // manager.php:151
  $store->add_to_store( $store_id, $post_id );   // base.php:56
  $store->remove( $store_id, $post_id );          // base.php:61
  $ids = $store->get( $store_id );                // base.php:66 — array of post IDs
  ```
- A custom storage backend (e.g. a "database" store instead of cookies) must extend
  `Base_Store` and implement that same contract, registered via
  `Manager::register_store_type( $type_instance )` (`manager.php:98`), fired from
  `do_action('jet-engine/data-stores/register-store-types', $this)` (`manager.php:36`).
  A custom named store instance (not a new backend type) goes through
  `Manager::register_store( $args )` (`manager.php:79`), fired from
  `do_action('jet-engine/data-stores/register-stores', $this)` (`manager.php:65`).
- There's a separate, unrelated internal `DB` class
  (`includes/modules/data-stores/inc/db.php`) used only by the `On_View`/database-backed
  store types — don't confuse it with the public `Manager`/`Base_Store` API above.
- **Gotcha: the before/after add/remove/count hooks only fire from the AJAX handlers,
  not from a direct `add_to_store()`/`remove()` call.** `Factory::ajax_add_to_store()`/
  `ajax_remove_from_store()` (`inc/stores/factory.php:182-288`) wrap the actual store
  write with `do_action( 'jet-engine/data-stores/before-add-to-store', $post_id, $store,
  $this )` / `.../after-add-to-store` (same 3 args) and the `remove` equivalents — but
  if you call `$store->get_type()->add_to_store( $store_slug, $post_id )` directly from
  your own PHP (as shown above), **none of these fire** — they're only reachable via the
  real front-end AJAX action (`wp_ajax_jet_engine_add_to_store_{slug}`). If you need a
  hook point for a programmatic add, call the AJAX method's logic yourself or hook one
  level up at your own call site instead of expecting these to fire.
- `Factory::increase_post_count( $post_id )` / `decrease_post_count( $post_id )`
  (`factory.php:290,316`, only relevant when the store's `count_posts` arg is enabled)
  fire **`jet-engine/data-stores/post-count-increased`** /
  **`jet-engine/data-stores/post-count-decreased`** (both 3 args: `$post_id`, `$count`
  [the count *after* the change], `$this` [the `Factory`]) — these DO fire from
  `ajax_add_to_store()`/`ajax_remove_from_store()` (same caveat as above: not from a bare
  `add_to_store()` call). There's also a filter to fully replace the counting logic:
  **`jet-engine/data-stores/custom-count-increased`**/`...-decreased` (4 args: `false`
  default, `$post_id`, `$count`, `$this`) — return non-`false` to skip JetEngine's own
  `update_post_meta()`/`update_user_meta()` bookkeeping and do your own instead.
- **`jet-engine/data-stores/pre-get-post-count`** (filter, 3 args: `false` default,
  `$post_id`, `$this`) — `factory.php:110` — return a non-`false` value to fully
  override the reported count for a post without touching the stored meta value.

## Dynamic Visibility (conditional display — a standalone module, not part of JFB/JSF)

Agents often assume conditional visibility is a JetSmartFilters or JetFormBuilder
feature; it's actually its own module that separately hooks Elementor
(`before_element_render`), Gutenberg (`render_block`), and Bricks.

- `Jet_Engine\Modules\Dynamic_Visibility\Module` —
  `includes/modules/dynamic-visibility/inc/module.php`.
- `Conditions\Manager` — `includes/modules/dynamic-visibility/inc/conditions/manager.php`
  — register a brand-new condition type by instantiating a class and calling
  `register_condition( $instance )` (`manager.php:87`), fired from
  `do_action('jet-engine/modules/dynamic-visibility/conditions/register', $this)`
  (`conditions/manager.php:77`). There's no lighter "just add a callback" filter —
  a real condition class is required (~35 built-in examples exist in
  `inc/conditions/*.php` to copy the pattern from).
- The actual evaluation entry point shared by all three builder integrations:
  `Condition_Checker::check_cond( $settings, $dynamic_settings )`
  (`conditions-checker.php:35`) — this is what you'd call to evaluate a visibility
  condition manually outside of a builder render, e.g. inside a custom template.
- `apply_filters('jet-engine/modules/dynamic-visibility/condition/prevent-check', ...)`
  (`conditions-checker.php:23`) — return `true` to skip evaluation entirely (e.g. to
  force-show/hide regardless of configured conditions in a specific custom context).

## Glossaries

Smaller subsystem; agents sometimes assume glossaries are just a meta-field select
option list, but they're a distinct CPT-backed store (`jet-engine-glossaries`) with
their own sanitizer/blacklist and an optional external-CSV-file mode.

- `Jet_Engine\Glossaries\Manager` — `includes/components/glossaries/manager.php` —
  `get_glossaries_for_js()` (`manager.php:111`).
- `Data` — `includes/components/glossaries/data.php` — `get_item_for_edit( $id )`
  (`data.php:216`), `get_fields_from_file( $item )` (`data.php:265`) for CSV-backed
  glossaries specifically.

## Custom Meta Tables (per-field custom-table storage — distinct from CCT)

**Correction (2026-07-16, live-verified):** the original version of this section
claimed the class was `Jet_Engine\Custom_Tables\Manager`. **That namespace does not
exist.** The real class is `Jet_Engine\CPT\Custom_Tables\Manager` — confirmed both by
`grep`-ing the installed plugin source and by this skill's `tests.php` (`mod-6`), which
asserts the wrong namespace is absent and the right one is present. It's part of the
always-loaded `cpt` core component (`includes/components/post-types/manager.php:97`),
not a separate optional module — reachable on any site with JetEngine active, no
enablement step needed.

When a meta field's "Storage" setting (a per-field admin toggle on regular post-type
meta boxes) is switched from default `wp_postmeta` to a custom table, reading it with
core `get_post_meta()` silently returns nothing — the value lives in a dedicated table
reachable only through this API. Easy to confuse with CCT (a different subsystem,
different tables entirely) since both involve "JetEngine + a custom DB table."

- `Jet_Engine\CPT\Custom_Tables\Manager` (singleton via `::instance()`) —
  `includes/components/post-types/custom-tables/manager.php`.
- `Jet_Engine\CPT\Custom_Tables\DB` —
  `includes/components/post-types/custom-tables/db.php`.
- Get the handler for a given post type/field group's storage:
  `Manager::instance()->get_db_instance( $object_slug, $fields )` (`manager.php:91`).
- `Manager::instance()->register_storage( $object_type, $object_slug, $fields )` (used
  internally, see `manager.php` callers), `get_table_name( $slug )` (`manager.php:76`,
  applies a `_meta` suffix — confirmed live: `get_table_name('foo')` returns
  `'foo_meta'`).
- `DB::insert()` / `DB::update()` / `DB::query()` — same public surface as other
  JetEngine `Base_DB`-style storage classes.
- Filters: `jet-engine/custom-meta-tables/table-name-for-object-slug` (`manager.php:78`).

## Maps Listings (geocoding + autocomplete providers)

A standalone module for the Map field/widget's address→coordinates lookups — distinct
from the Map *field* itself (JFB has its own Map field JS API, unrelated).

- `Jet_Engine\Modules\Maps_Listings\Providers_Manager` —
  `includes/modules/maps-listings/inc/providers-manager.php` — reachable via
  `\Jet_Engine\Modules\Maps_Listings\Module::instance()->providers`. Built-in geocode
  providers: `Google`, `OpenStreetMap`, `Photon`, `Bing` (each in its own file under
  `inc/geocode-providers/`), all extending a common `Base`. Get the active one
  (configured in module settings) via `->get_active_map_provider()`, or any provider by
  type+id via `->get_providers( 'geocode', $provider_id )` (`providers-manager.php:25`).
- **Registration hook is misnamed relative to the module** — a real, confirmed naming
  inconsistency: registering a custom geocode provider goes through
  `jet-engine/maps-listing/register-geocode-providers` (**singular** "listing",
  `providers-manager.php:46`), while the request-shaping filters below are
  `jet-engine/maps-listings/...` (**plural**). Don't assume they share a prefix — copy
  the exact string for whichever one you need.
- Once you have a geocode provider instance (e.g. from a "Call a Hook" JFB action doing
  server-side geocoding), the real call is `$provider->get_location_data( $address )` —
  used together with `Module::instance()->settings->get( 'geocode_provider' )` to find
  which one is active.
- Per-provider request-shaping filters (all confirmed in `inc/geocode-providers/{google,openstreetmap}.php`):
  `jet-engine/maps-listings/autocomplete-request-body/google` (filters the JSON body sent
  to Google's new Places API), `jet-engine/maps-listings/autocomplete-url-args/google`,
  `jet-engine/maps-listings/autocomplete-url-args/openstreetmap` (both filter the URL
  query-args array for the legacy/OSM autocomplete request) — useful for restricting
  autocomplete results to specific countries via each API's own region-bias params.

## Profile Builder (front-end user account pages)

A standalone module for building a front-end "my account"-style page with sub-pages, out
of scope of the base Relations/CCT facts already covered elsewhere.

- `Jet_Engine\Modules\Profile_Builder\Module::instance()` —
  `includes/modules/profile-builder/`. Key sub-objects: `->settings` (`inc/settings.php`
  — `get_pages()`, `get( 'user_page_structure' )`, `get_subpage_data( $slug, $page )`),
  `->query` (`inc/query.php` — `is_account_page()`, `get_subpage_data()`,
  `get_queried_user_slug()`). Note **both** `->settings` and `->query` expose a
  `get_subpage_data()` method with a different signature — check which object you have
  before calling it.
- **`jet-engine/profile-builder/rewrite-rules`** (filter, 2 args: `$rules` array,
  `$this` [rewrite manager]) — `inc/rewrite.php:79` — the generated rewrite rules array
  right before it's returned/registered; used to add e.g. two-level subpage URLs or
  slug-less single-user URLs.
- **`jet-engine/profile-builder/subpage-url`** (filter, 5 args: `$url`, `$slug`, `$page`,
  `$page_data`, `$this` [settings]) — `inc/settings.php:623` — the generated subpage URL,
  right before it's returned; rewrite this to change the URL structure without touching
  rewrite rules directly.
- **`jet-engine/profile-builder/user-page-title/macros`** (filter, array of macro
  definitions) — `inc/frontend.php:459` — extends the set of macros usable specifically
  inside the account page's title setting (separate from the general Listings macro
  system in `jetengine-listings-macros` — this is a narrower, page-title-only set).

## How this was verified

Read `includes/components/meta-boxes/manager.php`,
`includes/components/options-pages/{manager,options-page}.php`,
`includes/modules/data-stores/inc/{stores/manager,stores/base,db}.php`,
`includes/modules/dynamic-visibility/inc/{module,conditions/manager,conditions-checker}.php`,
`includes/components/glossaries/{manager,data}.php`, and
`includes/components/post-types/custom-tables/{manager,db}.php` in JetEngine 3.8.12
source, confirming method signatures, hook names, and the storage-mode gotchas by
direct file:line citation. Not yet run against a live site — see `TEST-REGIMEN.md`.
