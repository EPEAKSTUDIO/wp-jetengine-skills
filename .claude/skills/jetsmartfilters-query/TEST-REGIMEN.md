# Test regimen: jetsmartfilters-query

Validates claims in `SKILL.md`. Run against the sandbox site (JetSmartFilters 3.8.3.1,
with a JetEngine listing grid + at least one checkboxes filter wired to it).

## Run log — 2026-07-15: BLOCKED, not executed

Attempted against jackfruit.epeak.studio (the site available this session — see the
`jetformbuilder-hooks` regimen's run log for the live-production caveat). **JetSmartFilters
is not installed on this site at all** — absent from the active-plugins list
(`resource-get-website-config`) and there are no `jet-smart-filters` namespaces in the
`wp-json` route index. Nothing in this file is testable without the plugin present;
none of it was attempted.

**To make this regimen runnable:** install JetSmartFilters on a site that already has (or
can have) a JetEngine listing grid with a checkboxes filter wired to it, per the
Prerequisites below, then run these tests as written.

## Prerequisites

- A page with a JetEngine listing grid and a checkboxes filter (taxonomy-sourced) and
  ideally a second one sourced from custom fields/CCT meta, so both `tax_query` and
  `meta_query` paths can be tested.
- Debug log sink (see `docs/test-regimen-guide.md`).
- Browser devtools (Network tab) to inspect the actual AJAX request/response for the
  `wp_ajax_jet_smart_filters` action.

## Test 1: `jet-smart-filters/query/final-query` sees the fully-assembled query

**Claim:** this filter fires with the complete `tax_query`/`meta_query`/`paged` array,
right before providers consume it.

**Setup:**
```php
add_filter( 'jet-smart-filters/query/final-query', function( $query ) {
    error_log( '[JSF-TEST] final-query=' . wp_json_encode( $query ) );
    return $query;
} );
```

**Trigger:** apply the taxonomy checkbox filter on the front end.

**Expected observable:** logged array contains a `tax_query` entry matching the
selected term, with `field=>'term_id'`, `operator` as expected.

**Pass criteria:** structure matches WP's native `tax_query` row shape.

## Test 2: AJAX response is rendered HTML + pagination, not raw query deltas

**Claim:** `ajax_apply_filters()` response shape is `{content, pagination, is_data?}`.

**Setup:** none needed — just inspect the network request.

**Trigger:** apply any filter that triggers AJAX (no full page reload).

**Expected observable:** the `admin-ajax.php?action=jet_smart_filters` response body
(devtools Network tab) has top-level `content` (HTML string) and `pagination` keys.

**Pass criteria:** matches the claimed shape; note any additional top-level keys found.

## Test 3: AJAX detection is action-name gated, not just `wp_doing_ajax()`

**Claim:** `is_ajax_filter()` checks `action`/`jet_engine_action` against
`jet-smart-filters/query/allowed-ajax-actions` before falling back to `wp_doing_ajax()`.

**Setup:**
```php
add_filter( 'jet-smart-filters/query/final-query', function( $query ) {
    error_log( '[JSF-TEST] is_ajax_filter=' . var_export(
        jet_smart_filters()->query->is_ajax_filter(), true
    ) );
    return $query;
} );
```
Trigger once via the real filter AJAX action, once via a plain page load with filter
query-string params set manually (no AJAX).

**Expected observable:** `is_ajax_filter` true on the AJAX-triggered case; check what it
returns on the manual query-string case (should be false unless `wp_doing_ajax()`
somehow also true, which it won't be on a normal page load).

**Pass criteria:** confirms the action-name gate is what actually distinguishes the two
modes.

## Test 4: custom filter type registers via `jet-smart-filters/filter-types/register`

**Claim:** registering a class extending `Jet_Smart_Filters_Filter_Base` via this hook
makes it available.

**Setup:**
```php
add_action( 'jet-smart-filters/filter-types/register', function( $manager ) {
    error_log( '[JSF-TEST] filter-types/register fired, registering test type' );
    // register_filter_type() call here once a minimal test filter class exists
} );
```

**Expected observable:** log line fires on every page load involving JetSmartFilters
init; confirms the hook exists and fires at the expected point (full custom-class
registration is a heavier follow-up test, not required just to confirm the hook).

**Pass criteria:** hook fires; if a full custom filter class is built, confirm it
appears in the filter-type picker in the editor.

## Test 5: indexer is genuinely optional

**Claim:** with `use_indexed_filters` disabled, indexer code doesn't load at all, and
filtering still works correctly.

**Setup:** confirm the `use_indexed_filters` setting is OFF (default), apply filters
normally, then check that `jet_smart_filters_get_indexed_data` AJAX calls never fire
(devtools Network tab shows no such request) and filtering still returns correct
results.

**Pass criteria:** filtering works with the indexer fully inactive, confirming it's not
a hidden dependency.

## Test 6: offset/pagination correction only applies to the JetEngine provider path

**Claim:** `query_maybe_has_offset()`/`adjust_offset_pagination()` are JetEngine-provider-
specific; a custom provider needs its own equivalent.

**Setup:** on a JetEngine listing grid with both `offset` set (in the listing's query
settings) and an active AJAX filter, paginate through multiple pages and check
`found_posts`/total-pages displayed matches the actual filtered result count.

**Expected observable:** correct total count/pagination despite the offset.

**Pass criteria:** if pagination is wrong with offset+filter combined, the correction
isn't working as claimed and needs re-examination.

## Tests 7-12 (not yet run — same BLOCKED status as above): 2026-07-16 follow-up claims

JetSmartFilters is still absent from the only sandbox available this session
(jackfruit.epeak.studio) — same blocker as the 2026-07-15 run log above. These tests
cover the subsystems added to `SKILL.md` on 2026-07-16 (indexer read/write split,
Listing engine, provider helpers, tax/plain dynamic vars, `Service_Filters`, terms
walker) and are source-cited only until a site with the plugin installed is available.

**Test 7 — indexer write/read split:** call
`Jet_Smart_Filters_Indexer_Manager::add_single_data($post_id, 'posts')` directly, then
confirm `Jet_Smart_Filters_Indexer_Data::get_indexed_data($provider_key, $args)` reflects
the change without waiting for the normal save-hook-triggered reindex.

**Test 8 — Listing engine is a separate DB table from JetEngine listings:** create a
JSF listing via `Listing\Storage\Controller::update_listing()`, then confirm via
`SHOW TABLES` that it lands in JSF's own custom table, not `{prefix}jet_post_types`/CCT
tables.

**Test 9 — provider helpers magic-getter:** confirm
`jet_smart_filters()->providers->helpers->elementor` returns the singleton (not null/
fatal) on a site with Elementor active, and that `get_filtered_post_id()` returns a
sane value inside an Elementor-rendered loop.

**Test 10 — tax/plain dynamic-var double-resolution gotcha:** register a custom
dynamic var only in the render-time hook (`tax-query/query-var.php:22`'s hook), not the
indexer-time hook (`:84`/`:100`); confirm front-end rendering resolves it correctly
while the indexed data for that filter goes stale (mismatches once indexing is
enabled).

**Test 11 — `Service_Filters` direct CRUD bypasses REST:** call
`Service_Filters::move_to_trash($filter_id)` directly from a snippet (no REST call),
confirm the filter post moves to trash exactly as the admin "Filters" list action does.

**Test 12 — hierarchical term walker only fires via AJAX hierarchy-level action:**
confirm `Jet_Smart_Filters_Terms_Walker::start_el()` never fires during a normal
`prepare_args()`-driven filter render (only via
`jet_smart_filters_get_hierarchy_level`), by breakpoint/log comparison between a flat
checkbox filter and a hierarchical one.
