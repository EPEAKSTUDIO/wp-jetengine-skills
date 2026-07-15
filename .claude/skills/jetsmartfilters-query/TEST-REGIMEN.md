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
