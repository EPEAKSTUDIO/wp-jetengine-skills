---
name: jetsmartfilters-query
description: Use when building or debugging JetSmartFilters — how a selected filter value becomes a tax_query/meta_query and reaches WP_Query, how the AJAX filtering endpoint works, and how to register a custom filter type or query provider. Captures verified behavior from JetSmartFilters 3.8.3.1 source.
license: MIT
metadata:
  author: project
  version: "0.1.0"
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
- Not yet traced: `includes/plain-query/` and `includes/tax-query/` (URL query-var/
  dynamic-var handling), and the contents of `includes/compatibility/*` patch files.

## How this was verified

Read `includes/filters/checkboxes.php`, `includes/filters/base.php`,
`includes/filters/manager.php`, `includes/query.php`, `includes/render.php`,
`includes/providers/base.php`, `includes/providers/manager.php`, and
`includes/providers/jet-engine.php` in JetSmartFilters 3.8.3.1 source, confirming the
checkboxes→query-arg pipeline, AJAX endpoint flow, and both registration hooks by
direct file:line citation. Not yet verified against a running site — see
`TEST-REGIMEN.md`.
