---
name: jetsmartfilters-query
description: Use when building or debugging JetSmartFilters — how a selected filter value becomes a tax_query/meta_query and reaches WP_Query, how the AJAX filtering endpoint works, and how to register a custom filter type or query provider. Captures verified behavior from JetSmartFilters 3.8.3.1 source.
license: MIT
metadata:
  author: project
  version: "0.3.0"
---

# JetSmartFilters Query Internals

Verified facts about how JetSmartFilters turns a filter selection into an actual query,
how live AJAX filtering works, and the two real extension points (custom filter type,
custom provider). Confirmed against JetSmartFilters 3.8.3.1 source.

## The pipeline: filter render → request → query args → provider → WP_Query

Two halves that never call each other directly — they communicate through
`$_REQUEST` conventions and a shared `Jet_Smart_Filters_Query` object.

**Render time** (`includes/filters/checkboxes.php`, and every other filter type):
`prepare_args()` decides, per data source, which `query_type` (`tax_query` or
`meta_query`) and `query_var` (taxonomy slug or meta key) this filter instance drives —
e.g. a taxonomy source uses `tax_query` unless a custom query var is set, in which case
it downgrades to `meta_query`; posts/custom_fields/CCT/manual sources always use
`meta_query`. These are embedded as `data-query-var`/`data-query-type` HTML attributes
that the frontend JS uses to build the request key (`_meta_query_{key}` /
`_tax_query_{taxonomy}`). **The filter class itself never builds a `tax_query`/
`meta_query` array — it only decides which query var name a value maps to.**

**Request time** (`includes/query.php`, class `Jet_Smart_Filters_Query`):
`get_query_from_request()` parses `$_REQUEST` (or the AJAX payload) against the
recognized var prefixes (`plain_query, tax_query, meta_query, date_query, _s, _sm,
sort, alphabet`), dispatching each to `add_tax_query_var()` or `add_meta_query_var()`,
which build the actual WP-shaped `tax_query`/`meta_query` row arrays (meta compare
operand comes from a suffix on the key, e.g. `less/greater/like/in/between/exists/regexp`
→ `<=,>=,LIKE,IN,BETWEEN,EXISTS,REGEXP`). The assembled array is exposed via
`get_query_args()` and passed through `apply_filters('jet-smart-filters/query/final-query', ...)`
— **this is the hook to mutate the fully-assembled query args** before providers
consume them.

**Provider time**: e.g. the JetEngine provider (`includes/providers/jet-engine.php`)
hooks `jet-engine/listing/grid/posts-query-args` and merges
`jet_smart_filters()->query->get_query_args()` into JetEngine's own query args, which
then become the real `new WP_Query(...)` call.

### `jet-smart-filters/query/final-query` — worked examples

Real snippets hooking this filter, confirming the before/after shape of `$query_args`
(a plain array, same keys `get_query_args()` returns — `_range`/`--range`-suffixed keys
for range filters, `_search`-suffixed for search):

```php
// Split a `foo--range` array value into two scalar keys a downstream SQL/Query-Builder
// query can bind separately as start/end.
add_filter( 'jet-smart-filters/query/final-query', function( $query_args ) {
    foreach ( $query_args as $key => $value ) {
        if ( is_array( $value ) && preg_match( '/^(.+)--range$/', $key, $m ) ) {
            $query_args[ $m[1] . '--range1' ] = $value[0] ?? '';
            $query_args[ $m[1] . '--range2' ] = $value[1] ?? '';
        }
    }
    return $query_args;
} );

// JetSmartFilters' own Search filter type leaves a `|search` suffix on its query var
// name that downstream consumers don't recognize — strip it back off.
add_filter( 'jet-smart-filters/query/final-query', function( $query_args ) {
    foreach ( $query_args as $key => $value ) {
        if ( false !== strpos( $key, '|search' ) ) {
            $query_args[ str_replace( '|search', '', $key ) ] = $value;
            unset( $query_args[ $key ] );
        }
    }
    return $query_args;
} );
```

**Undocumented cross-plugin hook**: when a Query Builder query is being driven by a live
JetSmartFilters AJAX request, `Jet_Engine\Query_Builder\Listings\Filters::set_filtered_props()`
fires `do_action( 'jet-engine/query-builder/filters/before-after-props', $query )`
(`jet-engine/includes/components/query-builder/listings/filters.php:115`) — **one arg,
the `Base_Query` instance** — right after the filtered query args have been applied to
it via `set_filtered_prop()`, but before the query actually runs. This is a JetEngine
Query Builder hook, not a JetSmartFilters one, but it only fires during a JSF filter
request (guarded by `is_filters_request()`) — the real integration point for e.g.
forcing a specific `order`/`orderby` on the underlying query independent of what JSF
itself resolved, by calling `$query->set_filtered_prop( 'order', [...] )` again inside
the callback. Neither this skill nor `jetengine-query-builder` previously documented it.

## More render-time and admin-editor filters (beyond `prepare_args()`)

Five more real hooks, sourced from Codelab/Gist snippets and confirmed in
JetSmartFilters 3.8.3.1 source — none previously documented here:

- **`jet-smart-filters/filter-instance/args`** (filter, 2 args: `$args`, `$this` [the
  `Filter_Instance`]) — `includes/filters/instance.php:41` — fires once per filter
  instance, right after its type-specific `prepare_args()` has run and `query_id`/
  `show_label`/`display_options` defaults are merged in. This is the same filter the
  built-in tax/plain-query dynamic-var system hooks (`tax-query/query-var.php:17`) to
  resolve `%placeholder%` tokens in a filter's own settings — so a custom dynamic-var
  resolver for filter *settings* (not query values) belongs here, not in `final-query`.
- **`jet-smart-filters/filters/filter-options`** (filter, 3 args: `$options`,
  `$filter_id`, `$this` [`Filter_Instance`, or `false` when called from the indexer])
  — fires from every option-list-based filter type's `prepare_args()`
  (`checkboxes.php:198`, `radio.php:193`, `select.php:206`, `color-image.php:197`,
  `check-range.php:115`) plus the indexer's own option-building code
  (`indexer/manager.php:882`, `indexer/data.php:491`, where `$this` is `false` — guard
  for that before calling instance methods on it). Use to add/remove/reorder specific
  option entries for one `$filter_id` without touching the underlying taxonomy/meta
  source.
- **`jet-smart-filters/range/source-callbacks`** (filter, 1 arg: `$callbacks` array) —
  admin-editor only (`admin/includes/filter-settings-list.php:241`, and a legacy-UI
  duplicate at `admin-classic/includes/filter-settings-list.php:835`) — registers extra
  entries in the Range filter's "Source" dropdown (built-ins pull min/max from a single
  meta key; a custom callback here can instead compute them from a Query Builder query
  or arbitrary PHP, as in the "Custom Query Builder-backed range source" pattern several
  real snippets implement).
- **`jet-smart-filters/query/meta-query-row`** (filter, 3 args: `$current_row`, `$this`
  [`Jet_Smart_Filters_Query`], `$additional_options`) — `includes/query.php:1175` — the
  single assembled `meta_query` clause row, right before it's added to the overall
  query args, one level more granular than `final-query` (which sees the whole assembled
  array, not per-clause). Use this to rewrite one filter's clause (e.g. switching its
  `compare` to a raw SQL-safe custom operator) without re-parsing the entire query args.
- **`jet-smart-filters/post-type/meta-fields-settings`** (filter) — used by JetSmartFilters'
  own JetEngine-compatibility layer (`includes/compatibility/jet-engine/manager.php:27`,
  `cct_register_controls()`) to register CCT-specific field settings in the filter
  editor's meta-field picker — the real extension point if you need a source's field
  list to include something beyond regular post meta (the compatibility file itself is
  the reference example to copy from).

## The front-end JS event bus: `JetSmartFilters.events`

Distinct from the `document`-level jQuery events (`jet-smart-filters/inited`,
`jet-filter-content-rendered`) already used throughout this skill's examples — JSF also
ships a small pub/sub event bus, confirmed present (channel name strings found verbatim)
in the shipped `assets/js/public.js` bundle:

```js
document.addEventListener( 'jet-smart-filters/inited', function() {
    window.JetSmartFilters.events.subscribe( 'ajaxFilters/updated', function( data ) {
        // fires after a filtered AJAX request re-renders content — data carries the
        // provider/query context, not just a bare "done" signal.
    } );
} );
```

Real channel names seen in the shipped JS (subscribe with these literal strings):
`fiter/change` and `fiter/apply` (note: **"fiter"**, not "filter" — a real typo baked
into the shipped event names, matches the pattern already flagged elsewhere in this repo
for JetEngine's CSV `cvs-separator` misspelling), `fiter/syncSameFilters`,
`ajaxFilters/updated`, `ajaxFilters/start-loading`, `ajaxFilters/end-loading`,
`pagination/change`. `start-loading`/`end-loading` are the right hooks for a custom
loading-spinner overlay (toggle it on/off in those two callbacks) instead of guessing at
a CSS-class-based approach tied to JSF's own default spinner markup.

## AJAX filtering — no REST route, classic admin-ajax

Live filtering uses `admin-ajax.php`, not a REST endpoint:

- `wp_ajax_jet_smart_filters` / `wp_ajax_nopriv_jet_smart_filters` →
  `ajax_apply_filters()` (`includes/render.php`).
- Flow: resolve `provider_id`/`query_id` → `do_action('jet-smart-filters/render/ajax/before', ...)`
  → `jet_smart_filters()->query->get_query_from_request()` parses posted filter values
  → optional signature verification → response is
  `{ content: <rendered HTML>, pagination: {...}, is_data?: bool }` →
  `apply_filters('jet-smart-filters/render/ajax/data', $args)` → `wp_send_json($args)`.
- **The response is rendered HTML plus pagination metadata, not raw query-var deltas.**
- Related AJAX actions: `jet_smart_filters_get_hierarchy_level` (hierarchical term
  filters), `jet_smart_filters_get_indexed_data` (reads from the indexer table).
- A genuine REST namespace `jet-smart-filters-api/v1` does exist
  (`includes/rest-api/manager.php`), but it only serves the editor/admin UI (Filters,
  Filter, TaxonomyTerms, PostsList, Plugin_Settings, AdminModeSwitch endpoints) — not
  front-end filtering.

**AJAX detection is action-name based**, not just `wp_doing_ajax()`:
`is_ajax_filter()` checks the request's `jet_engine_action`/`action` param against
`apply_filters('jet-smart-filters/query/allowed-ajax-actions', [...])` (default:
`jet_smart_filters`, `jet_smart_filters_refresh_controls`,
`jet_smart_filters_refresh_controls_reload`) before falling back to `wp_doing_ajax()`.
A custom AJAX action that should be treated as a filter request must be added to that
filter list.

## Registering a custom filter type

`includes/filters/manager.php`, `register_filter_types()` hardcodes the 15 built-in
types, then fires:

```php
do_action( 'jet-smart-filters/filter-types/register', $this );
```

```php
add_action( 'jet-smart-filters/filter-types/register', function( $manager ) {
    $manager->register_filter_type( 'My_Custom_Filter', __DIR__ . '/my-custom-filter.php' );
} );
```

The class must `extends Jet_Smart_Filters_Filter_Base` and implement `get_name()`,
`get_id()`, `get_scripts()` (abstract on the base) plus a `prepare_args()` method
(pattern followed by every built-in type, though not itself declared abstract).

## Registering a custom provider

`includes/providers/manager.php` globs `includes/providers/*.php`, reads each file's
header comment for `Class`/`Name`/`Slug`, then fires:

```php
do_action( 'jet-smart-filters/providers/register', $this );
```

```php
add_action( 'jet-smart-filters/providers/register', function( $manager ) {
    $manager->register_provider( 'My_Custom_Provider', __DIR__ . '/my-custom-provider.php' );
} );
```

Unlike filter types, a custom provider class must `extends Jet_Smart_Filters_Provider_Base`
and implement `get_name()`, `get_id()`, `ajax_get_content()`, `get_wrapper_selector()`
— and typically hooks a `*-query-args`-style filter of whatever rendering system it
targets (the JetEngine provider hooks `jet-engine/listing/grid/posts-query-args`).

## The indexer — optional, off by default

`includes/indexer/manager.php` is a performance layer, gated by the
`use_indexed_filters` setting; if disabled, the constructor returns immediately and the
indexer table/data files never load. When enabled, it maintains a custom DB table
pre-storing per-post-type filterable meta/term/taxonomy values so filter option lists
and counts can be fetched without live `get_terms()`/meta scans. **Not required for
filters to function** — purely a lookup-speed optimization for large datasets.

**Write side and read side are different classes with unrelated method names** — don't
assume one `Indexer` class with a single `index($post_id)` method:

- `Jet_Smart_Filters_Indexer_Manager` (`includes/indexer/manager.php`) is the write
  side. `add_single_data( $id, $type )` (`manager.php:262`) and
  `remove_single_data( $id, $type )` (`manager.php:369`) re-index or drop one
  post/user/term — call these yourself if you bypass normal save hooks (e.g. bulk
  `$wpdb` writes, an import script). Building the row itself is filterable per source:
  `jet-smart-filters/indexer/get-post-meta` / `get-user-meta` / `get-term-meta`
  (`manager.php:476-563`) control what value gets captured for a given meta key, and
  `jet-smart-filters/indexer/single-item-data` (`manager.php:355`) lets you mutate a
  row right before it's written.
- `Jet_Smart_Filters_Indexer_Data` (`includes/indexer/data.php`) is the read side, and
  is what actually backs the `jet_smart_filters_get_indexed_data` AJAX action already
  mentioned above: `get_indexed_data( $provider_key, $query_args )` (`data.php:121`),
  `get_queried_ids( $args )` (`data.php:541`).

**Confirmed live: `jet_smart_filters()->indexer->data` is `null` (not an object) on a
site with `use_indexed_filters` off (the default)** — `Indexer_Manager::__construct()`
returns early before ever assigning `$this->data` (`manager.php:34-38`). Guard with
`if ( jet_smart_filters()->indexer->data )` before calling anything on it — don't
assume it's always an instantiated object just because the indexer classes are
documented here.

## JetSmartFilters has its own Listing/Query-Builder engine — separate from JetEngine's

Easy to miss entirely: independent of JetEngine's Listing Grid module (different
plugin, different DB table), JetSmartFilters ships its own listing/grid rendering and
storage system under namespace `Jet_Smart_Filters\Listing`:

- Entry point `Listing\Controller` (`includes/listing/controller.php`); rendering base
  `Listing\Render\Listing_Base` (`includes/listing/render/listing-base.php`) with
  `get_query_args()` (`:128`) / `get_query()` (`:177`), filterable via
  `jet-smart-filters/listing/render/raw-query-args` and
  `jet-smart-filters/listing/render/query`.
- Query dispatch is a factory, same pattern as the JetEngine Query Builder documented
  in `jetengine-query-builder`: `Listing\Render\Query_Factory::register_query_type( $type, $class )`
  (`query-factory.php:41`), fired from
  `do_action('jet-smart-filters/listing/render/query-types/register')` (`:32`). Only
  one built-in type ships (`Query_Types\Posts`, `includes/listing/render/query-types/posts.php`)
  — this is the real extension point for a custom listing source.
- Storage is a **generic DB-backed CRUD class**, not WP posts:
  `Listing\Storage\Controller::get_listings()` / `get_listing()` (`storage/controller.php:43,68`),
  backed by `Listing\Storage\DB_Storage` (`storage/db-storage.php`) and its own custom
  table (prefix `{wp_prefix}jsf_`, e.g. `{wp_prefix}jsf_listings` —
  `db-storage.php:29`). A task like "read/write a JSF listing definition
  programmatically" needs this class, not `get_post()`/CCT lookups.

  **Live-verified landmine (2026-07-16): never call `new \Jet_Smart_Filters\Listing\Storage\Controller()`
  yourself.** Its constructor does an unconditional `require` (not `require_once`) of
  `db-storage.php` (`storage/controller.php:19`). The top-level
  `\Jet_Smart_Filters\Listing\Controller` is a real singleton
  (`Controller::instance()`, `listing/controller.php:70`) whose `init_components()`
  (hooked on WP `init`) already instantiates `Storage\Controller` into its own
  `->storage` property (`listing/controller.php:59`) on every normal request where the
  Listing module is active — which is effectively always, since it's `require`d
  unconditionally from the main plugin bootstrap. By the time any `rest_api_init`-timed
  code runs, `Storage\Controller`/`DB_Storage` are **already declared**. Instantiating
  a second `Storage\Controller` yourself re-runs that `require`, which is a **compile-time
  "Cannot redeclare class" fatal — uncatchable by try/catch, crashes the entire
  request** with a generic WordPress "critical error" page (confirmed live: this is
  exactly what happened when this skill's first test suite tried it). **The correct
  way to reach it:**
  ```php
  $storage  = \Jet_Smart_Filters\Listing\Controller::instance()->storage;
  $listings = $storage->get_listings();
  ```

## Provider Helpers — a magic-getter on each PROVIDER instance, not on the providers manager

**Correction (2026-07-16, live-verified):** `jet_smart_filters()->providers` is the
*registry* (`Jet_Smart_Filters_Providers_Manager`, `includes/providers/manager.php`) —
it has **no `helpers` property of its own** (confirmed live: accessing it returns
`null`, no fatal, just nothing there). `helpers` is a lazy-initialized property on each
individual **provider instance** (e.g. the JetEngine provider,
`Jet_Smart_Filters_Provider_Jet_Engine extends Jet_Smart_Filters_Provider_Base`),
populated via that base class's own magic `__get('helpers')`
(`includes/providers/base.php:120-129`), which calls `init_helpers()` (`base.php:103`)
to construct a `Jet_Smart_Filters_Provider_Helpers_Manager`
(`includes/providers/helpers/manager.php`) on first access. Get the provider instance
first, via `Providers_Manager::get_providers( $provider_id )` (`manager.php:140`,
e.g. `$provider_id = 'jet-engine'` — confirmed via `Provider_Jet_Engine::get_id()`,
`providers/jet-engine.php:127-129`), **then** read `->helpers` off *that* object:

```php
$provider = jet_smart_filters()->providers->get_providers( 'jet-engine' );
$helpers  = $provider->helpers; // triggers the base class's lazy __get(), not a plain property read
$post_id  = $helpers->elementor->get_filtered_post_id(); // elementor.php:22
```

Don't guess a static `Jet_Smart_Filters_Elementor_Helper::method()` call, and don't
assume `jet_smart_filters()->providers->helpers` resolves to anything — it doesn't.

## Tax-query / plain-query dynamic vars — a per-query-type mini-system, resolved twice

Previously untraced; now confirmed: `includes/tax-query/` and `includes/plain-query/`
each implement the **same** dynamic-tag/query-var substitution pattern independently
(`Jet_Smart_Filters_Plain_Query_Manager` extends the tax-query manager), not a shared
service:

- `Tax_Query_Var::filter_instance_replace_var( $filter_args )` (`tax-query/query-var.php:22`)
  resolves a placeholder like `%current_post_id%` inside a filter's own settings **at
  render time**.
- `indexing_filter_data_replace_var()` / `indexer_filter_source_replace_var()`
  (`tax-query/query-var.php:84,100`) resolve the same placeholder again, separately,
  **when the indexer reads that filter's config** to build its index.

**Gotcha:** a custom dynamic var registered only in the render-time path (the first
hook) will resolve correctly on the front end but leave indexed data stale, because the
indexer never sees it — hook both paths (and both `tax-query`/`plain-query` variants,
since they don't share the hook) if the filter's data source can be indexed.

## The admin REST namespace has a direct-CRUD PHP escape hatch

`jet-smart-filters-api/v1` (`includes/rest-api/manager.php`, endpoints under
`includes/rest-api/endpoints/`) is editor/admin-UI only, as already noted below — but
its actual backing CRUD, `Jet_Smart_Filters_Service_Filters` (no namespace, despite the
name — plain global class, `includes/services/filters.php`), is directly callable from
custom PHP without going through REST at all, reachable as
`jet_smart_filters()->services->filters` (`includes/services/services.php:19`):
`get( $args )` (`:25`), `restore()` (`:177`), `move_to_trash( $ids )` (`:211`),
`delete()` (`:248`) — useful for e.g. bulk-programmatically trashing/restoring filter
posts in a migration script.

## Hierarchical term rendering is a bespoke `Walker`, not the filter-type pipeline

`Jet_Smart_Filters_Terms_Walker` (`includes/walkers/terms-walker.php`) extends core WP
`Walker` and is invoked only from the `jet_smart_filters_get_hierarchy_level` AJAX
handler — it does **not** go through each filter type's `prepare_args()`. To customize
per-term markup/attributes for hierarchical checkbox/radio filters, filter inside
`start_el()` (`terms-walker.php:74`), not the filter-type registration pipeline above.

## Gotchas

- **Default query merging in AJAX mode**: `get_query_args()` merges the page's
  original, non-filtered query args (stashed by the provider via
  `store_provider_default_query()`) with the parsed filter request. A custom provider
  that skips stashing the default query will lose original constraints (e.g. post_type)
  on filtered AJAX requests.
- **Offset/pagination interaction**: the JetEngine provider has a dedicated
  offset-adjustment (`query_maybe_has_offset()`, and a `found_posts` correction hooked
  when both a `jet_smart_filters` query var and `offset` are set) — a custom provider
  mixing `offset` with AJAX paging needs the same correction or counts will be wrong.
- **Archive-template mode**: when `is_archive_template` is true, the JetEngine provider
  directly overwrites the global `$wp_query` with a fresh `WP_Query` — a more invasive
  path only taken for "listing as archive template" usage.
- Compatibility patch files exist for JetEngine indexer/range/macros integration, plus
  WooCommerce, WPML, RankMath SEO, and Weglot — flagging known integration friction
  points, though their specific fixes weren't traced in this pass.
- Not yet traced: the contents of `includes/compatibility/*` patch files.

## How this was verified

Read `includes/filters/checkboxes.php`, `includes/filters/base.php`,
`includes/filters/manager.php`, `includes/query.php`, `includes/render.php`,
`includes/providers/base.php`, `includes/providers/manager.php`,
`includes/providers/jet-engine.php`, `includes/indexer/manager.php`,
`includes/indexer/data.php`, `includes/providers/helpers/manager.php`,
`includes/listing/{controller,render/listing-base,render/query-factory}.php`,
`includes/listing/storage/{controller,db-storage}.php`,
`includes/tax-query/query-var.php`, `includes/plain-query/manager.php`,
`includes/services/filters.php`, and `includes/walkers/terms-walker.php` in
JetSmartFilters 3.8.3.1 source, confirming the checkboxes→query-arg pipeline, AJAX
endpoint flow, both registration hooks, and the 2026-07-16 follow-up findings (indexer
read/write split, the standalone Listing engine, provider helpers registry, tax/plain
dynamic-var double-resolution, `Service_Filters` direct-CRUD escape hatch, and the
terms walker) by direct file:line citation. Not yet verified against a running site —
see `TEST-REGIMEN.md`.

**2026-07-16 addendum**: added the `final-query` worked examples and the
`jet-engine/query-builder/filters/before-after-props` cross-plugin hook after reading
`jet-engine/includes/components/query-builder/listings/filters.php` directly (confirming
the 1-arg signature) and cross-checking against real Codelab snippets exercising
`final-query` for range-splitting and `|search`-suffix stripping.
