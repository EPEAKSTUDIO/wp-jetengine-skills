---
name: jetsearch-query-pipeline
description: Use when tracing how a JetSearch AJAX Search widget turns a user's typed query into results — the `jet_ajax_search` AJAX action and its nonce scheme, how `$_GET['data']` settings map onto `WP_Query` args in `get_search_data()`, taxonomy-scoped search (`search_taxonomy`/`include_terms_ids`/`exclude_terms_ids`), the Search Sources extensibility system (registering a custom source like a DB-table search alongside the built-in Terms/Users sources), the parallel `jet-search/v1/search-posts` REST route and how it differs from the AJAX path (no nonce), or JetSearch's `%jet_search_current_results%` JetEngine macro integration. Captures verified behavior of JetSearch 3.6.1.3 source (`plugins/jet-search/`), live-verified 2026-07-17 against jackfruit.epeak.studio (10/10 tests.php assertions passing).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetSearch query pipeline — from typed input to `WP_Query` results

First skill in this repo for JetSearch (source newly checked out to
`plugins/jet-search/`, v3.6.1.3, active on the sandbox). Covers the full lifecycle of
"user types into a JetSearch AJAX Search widget, results come back": the AJAX action/
nonce scheme, how the widget's posted settings (`$_GET['data']`) become `WP_Query`
args, taxonomy scoping, the Search Sources extensibility point (searching things beyond
regular posts), and the REST alternative. Bootstrap: `jet_search()` singleton
(`jet-search.php:401-403`) exposes `->db` (custom-table layer), `->rest_api`
(`Jet_Search_REST_API`), and `->search_sources` (`Jet_Search\Search_Sources\Manager`),
all constructed in `Jet_Search::init()` (`jet-search.php:183-209`), hooked on WP `init`
at priority `-999` (`:102`). `load_files()` (`:240-261`) is the require-order map for
everything below — `ajax-handlers.php`, `rest-api/manager.php`, `token-manager.php`,
`jet-search-tax-query.php`, `custom-url-handler.php`, and `search-sources/manager.php`
are all required unconditionally at that point, not lazily gated.

## The AJAX path: `jet_ajax_search`

`Jet_Search_Ajax_Handlers` (`includes/ajax-handlers.php:20`, singleton via
`jet_search_ajax_handlers()`) has a private `$action = 'jet_ajax_search'`
(`:35`) — this one string is reused for **three** distinct purposes: the AJAX action
name, the nonce action string, and (in `Jet_Search_Rest_Search_Route`, see REST section
below) a duplicated private constant of the same value. `init()` (`:85-129`)
unconditionally hooks `pre_get_posts`/`posts_search` for the server-rendered results
path, then — **only if `defined('DOING_AJAX') && DOING_AJAX'`** (`:97`) — registers the
actual AJAX callbacks:

```php
add_action( "wp_ajax_{$this->action}",        array( $this, 'get_search_results' ) ); // :112
add_action( "wp_ajax_nopriv_{$this->action}",  array( $this, 'get_search_results' ) ); // :113
```

i.e. `wp_ajax_jet_ajax_search` / `wp_ajax_nopriv_jet_ajax_search`, open to both logged-in
and anonymous visitors — no capability gate beyond the nonce. **The `DOING_AJAX` gate
means these two hooks are never registered outside a real `admin-ajax.php` request** —
a suite or diagnostic script running via a normal REST/PHP request cannot observe them
via `has_action()`; the action name/nonce scheme themselves (`get_ajax_action()`,
`:497-498`) are still directly introspectable and testable without a real AJAX request.

### `get_search_results()` — nonce gate, then delegates

`get_search_results()` (`:1569-1590`) is the actual `wp_ajax_*` callback:

```php
if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( $_GET['nonce'], $this->action ) ) { // :1571
    wp_send_json_error( array( 'message' => 'Invalid Nonce!' ) );
    return;
}
$data = $this->get_search_data(); // :1579
if ( empty( $data ) ) {
    wp_send_json_error( array( 'message' => 'Empty Search Data' ) );
    return;
}
wp_send_json_success( $data );
```

The nonce is a plain `wp_verify_nonce( $nonce, 'jet_ajax_search' )` check — no separate
nonce-generation helper exists for it (contrast with `token-manager.php` below, which is
a completely different mechanism for a different feature). Whatever localizes the
front-end JS is expected to have called `wp_create_nonce( 'jet_ajax_search' )` (not
independently traced to a specific `wp_localize_script()` call site in this round — the
nonce *action string* and its verification are what's verified here, not the exact JS
enqueue call).

### `get_search_data()` — `$_GET['data']` → `WP_Query` args

`get_search_data()` (`:1598-1856`) is the real work — **safe to call directly outside a
real AJAX request** (unlike `get_search_results()`), since it only reaches
`wp_send_json_success()`/`wp_die()` on the `is_wp_error( $search )` branch
(`:1653-1661`); the normal path ends in a plain `return $response;` (`:1856`). Shape:

1. **Early exit**: `empty( $_GET['data'] )` → returns `false` (`:1599-1601`) — this is
   the same "Empty Search Data" condition `get_search_results()` checks after the call.
2. **Base `WP_Query` args** (`:1603-1609`): `s` from `urldecode( esc_sql( $data['value'] ) )`,
   `nopaging: false`, `ignore_sticky_posts: false`, `posts_per_page` from
   `$data['limit_query_in_result_area']` (default 25), `post_status: 'publish'`.
3. **`set_query_settings( $data )`** (`:987-1070`, protected) layers on: `post_type` from
   `$data['search_source']`, `order`/`orderby` from `results_order`/`results_order_by`,
   an empty `tax_query` shell (`['relation' => 'AND']`), `sentence` (a `WP_Query` "phrase
   search" flag) from `filter_var( $data['sentence'], FILTER_VALIDATE_BOOLEAN )`, plus
   taxonomy scoping (see below), `post__not_in` from `exclude_posts_ids`, and a
   `current_query` merge-in via `Jet_Search_Tools::merge_public_current_query_args()`
   (lets a listing widget's own current archive query context extend the search query).
   Fires `do_action( 'jet-search/ajax-search/search-query', $this, $args )` (`:1069`) —
   the real extension point WooCommerce/Polylang compatibility (`compatibility.php:42-53`)
   hooks to further modify `$this->search_query`.
4. **Security clamp, always applied last**: `Jet_Search_Tools::prepare_public_search_query_args()`
   (`tools.php:1152-1158`) forcibly sets `post_status = 'publish'` and `unset($query_args['perm'])`
   on the final args array, **after** step 3's filters have already run — a defense-in-depth
   boundary so `jet-search/ajax-search/query-args`/`current_query` cannot be abused to
   widen the public AJAX endpoint into returning drafts/private posts. `filter_public_search_query_results()`
   (`tools.php:1169-1186`) does the same after the query actually runs, re-filtering
   `$query->posts`/recomputing `post_count`/`max_num_pages` — a second belt-and-suspenders
   pass, not just a pre-query arg check.
5. `new WP_Query( $this->search_query )` executes; response is built into
   `posts`/`columns`/`limit_query`/`post_count`/`results_navigation`/`sources`/
   `sources_results_count` (`:1643-1739`).

### Taxonomy scoping — two entirely separate mechanisms, don't conflate them

**A. Result-set narrowing via `tax_query`** — `set_query_settings()` (`:998-1050`):
- If `$data['category__in']` is set: one `IN` clause against `search_taxonomy` (default
  `'category'`) with those term IDs (`:999-1010`).
- Else if `$data['include_terms_ids']` is set: `prepare_terms_data()` (`:1396-1411`) groups
  arbitrary term IDs by their **real** taxonomy (via `get_term( $id )->taxonomy` — so
  `include_terms_ids` can mix terms from different taxonomies in one call, unlike
  `category__in` which is single-taxonomy), building one `OR`-related `IN` clause per
  taxonomy (`:1011-1029`).
- `exclude_terms_ids` (`:1032-1050`) mirrors this with `NOT IN`, always applied
  independently of which include-path was taken.

**B. "Search within taxonomy term names" via `Jet_Search_Tax_Query`**
(`includes/jet-search-tax-query.php:5`) — a completely different feature, wired in via
`posts_search` (not `tax_query`): `Jet_Search_Ajax_Handlers::set_posts_search()`
(`ajax-handlers.php:410-457`, hooked at `:95`) instantiates
`new \Jet_Search_Tax_Query()` and calls `get_posts_ids()` (`:412-413`). Its own settings
gate is `search_in_taxonomy`/`search_in_taxonomy_source`
(`jet-search-tax-query.php:29-32`, **not** `search_taxonomy`) — when set, it runs a raw
SQL query joining `posts`/`term_relationships`/`term_taxonomy`/`terms` to find posts
whose **term name** (not post title/content) `LIKE '%search string%'`
(`:115-127`), optionally further constrained by the same `include_terms_ids`/
`exclude_terms_ids` settings (`:74-76,101-113`). Its result feeds back into the SQL
`WHERE` clause of the *main* search query via a regex swap on the `posts_search` filter's
`$query` string, not via `tax_query` at all — a genuinely separate code path from
mechanism A above despite superficially-similar setting names (`include_terms_ids`/
`exclude_terms_ids` are consumed by **both** A and B, `search_taxonomy` only by A,
`search_in_taxonomy`/`search_in_taxonomy_source` only by B). Also fires
`apply_filters( 'jet_search/custom_attribute_search_ids', [], $search, $taxonomies, $settings )`
(`:152-158`) as an escape hatch for e.g. WooCommerce custom-attribute search.

## The Search Sources extensibility system

`Jet_Search\Search_Sources\Manager` (`includes/search-sources/manager.php:11`,
`jet_search()->search_sources`) is the mechanism for adding non-post search results
(terms, users, or a fully custom source like a separate DB table) alongside the regular
`WP_Query` post results. `register_search_sources()` (`:19-29`, hooked on `init`
priority `99` — late, so it runs after most other plugins' `init` setup) requires
`base.php`/`source-terms.php`/`source-users.php` and registers the two built-ins:

```php
$this->register_source( new Terms() );
$this->register_source( new Users() );
do_action( 'jet-search/sources/register', $this );  // :28 — the real extension point
```

`register_source( $instance )` (`:41-43`) keys `$this->_sources` by `$instance->get_name()`
— **a custom source is registered exactly the same way the two built-ins are**, from a
callback on `jet-search/sources/register`:

```php
add_action( 'jet-search/sources/register', function( $manager ) {
    $manager->register_source( new My_Custom_Source() );
} );
```

### The `Base` contract (`includes/search-sources/base.php:17`)

Four abstract methods a subclass must implement: `get_label()`, `get_priority()`
(negative = rendered "before" the main post results in
`preview_source_template()`/the front-end template, positive = "after" — see
`renders/ajax-search.php:259-298`'s `preview_source_template()`, which is a real caller
using exactly this priority sign convention to split sources into two render slots),
`build_items_list()` (populate `$this->items_list`/`$this->results_count` from
`$this->search_string`), and `get_query_result()`. The base class supplies everything
else: `set_args()`/`set_search_string()` (the latter calls `build_items_list()`
automatically, `:414-420`), `render()` (`:123-225` — either a plain `name`/`url` link
list, or, if the source has `search_source_{name}_listing_id` configured and
`has_listing = true`, a full JetEngine Listing Grid render per item via
`jet_engine()->frontend->get_listing_item()`), and `editor_general_controls()`
(auto-generates the Elementor/Blocks editor controls: enable switcher, title, icon,
limit, and — if `has_listing` — a listing picker). **The built-in `Terms`
(`source-terms.php:13`, priority `-2`) and `Users` (`source-users.php:15`, priority
`-1`) sources are the reference implementation to copy for a custom source**: `Terms`
wraps `WP_Term_Query` with a `search` arg against a configurable taxonomy
(`search_source_terms_taxonomy`, default `category`); `Users` wraps `WP_User_Query`
searching `user_login`/`user_nicename`, excluding administrators
(`role__not_in: ['administrator']`) — neither is DB-table-specific, but the pattern (own
`get_query_result()` building a result set, own `build_items_list()` shaping it into
`['name' => ..., 'url' => ...]` rows) is exactly what a custom-DB-table source would
follow, just replacing `WP_Term_Query`/`WP_User_Query` with a raw `$wpdb` query.

### How the AJAX handler actually consumes registered sources

`get_search_data()` (`ajax-handlers.php:1693-1726`) loops `$sources_manager->get_sources()`,
and for each source whose `search_source_{key}` toggle in `$data` is truthy, calls
`$source->set_args( $data )` then `$source->set_search_string( $this->search_query['s'] )`
(triggering `build_items_list()`), then reads back `get_priority()`/`get_name()`/`render()`/
`get_results_count()` into `$response['sources'][]`. `Jet_Search_Rest_Search_Route::callback()`
(REST twin, see below) does the **identical** loop (`rest-api/endpoints/search-route.php:163-196`)
— sources are consumed the same way from both entry points, no source-side branching on
which pipeline invoked it.

## The REST alternative: `jet-search/v1/search-posts` — a genuine parallel, not legacy, and NOT nonce-gated

`Jet_Search_REST_API` (`includes/rest-api/manager.php:13`, `jet_search()->rest_api`,
namespace `jet-search/v1`) lazily requires and registers six endpoint classes on
`rest_api_init` (`init_endpoints()`, `:27-48`, called from `get_endpoints()` the first
time `register_routes()` runs). `Jet_Search_Rest_Search_Route`
(`rest-api/endpoints/search-route.php:7`, route name `search-posts` →
`/jet-search/v1/search-posts`, method `GET`) is a **near-verbatim duplicate** of
`get_search_data()`'s logic — same `$_GET['data']`-shaped input (read via
`$request->get_params()['data']` instead of `$_GET['data']` directly), same
`set_query_settings()`/taxonomy-scoping/`prepare_public_search_query_args()`/Search
Sources loop, same response shape. **This is a real, live, currently-reachable second
entry point to the exact same search pipeline — not dead code and not an older/legacy
implementation** (both files declare no version-gate against each other, and both are
unconditionally loaded/registered).

**The one substantive difference: no nonce.** `permission_callback()`
(`search-route.php:451-453`) unconditionally `return true` — there is no
`wp_verify_nonce()` call anywhere in this class, unlike the AJAX path's hard nonce gate
in `get_search_results()`. Anyone who can reach `GET /wp-json/jet-search/v1/search-posts?data[...]=...`
gets results without first having loaded a page that generated a
`jet_ajax_search`-action nonce — the REST route is strictly more exposed than the AJAX
one for the same query capability (both still enforce `post_status: 'publish'`
server-side via `prepare_public_search_query_args()`/`filter_public_search_query_results()`,
so this is a CSRF/rate-limiting difference, not a data-exposure difference). A second,
smaller difference: the REST route fires one extra hook the AJAX path doesn't,
`do_action_ref_array( 'jet-search/ajax-search/before-search-sources', [&$response,
&$search->posts, $data] )` (`search-route.php:158-161`), immediately before the Search
Sources loop — not present in `get_search_data()` at all.

## `Jet_Search_Token_Manager` — unrelated to the AJAX nonce despite the name

`includes/token-manager.php` (`jet_search_token_manager()`) is **not** part of the
search-request nonce/auth scheme above — it's session tracking for the separate Search
Suggestions feature (`wp_search_suggestions_sessions` table,
`generate_token()`/`add_token()`/`check_token_records()`), keyed by
`md5( $ip_address . NONCE_SALT )` (`:99-110`), with a daily WP-Cron cleanup
(`clean_old_tokens_event`). Don't confuse this "token" with the `jet_ajax_search`-action
nonce `get_search_results()` checks — they're two unrelated auth-adjacent mechanisms
that happen to both live in files named for security concepts.

## JetEngine macro integration: `%jet_search_current_results%`

`Jet_Search_Compatibility_JE` (`includes/compatibility/jet-engine/manager.php:14`,
constructed from `Jet_Search_Compatibility::init()` only `if ( function_exists(
'jet_engine' ) )`, `compatibility.php:66-70`) hooks JetEngine's own
`jet-engine/register-macros` action (`manager.php:26`) — the same registration hook
`jetelements-query-gateway`/`jettabs-query-gateway` document other Crocoblock plugins
using for their own cross-plugin integrations, confirming this is JetEngine's standard
"a sibling plugin registers its own macro" pattern, not something JetSearch invented.
`register_macros()` (`:36-41`) requires
`compatibility/jet-engine/macros/current-results.php` and instantiates
`Jet_Search_Macros_Current_Results` (`current-results.php:11`, extends
`Jet_Engine_Base_Macros` — see `jetengine-listings-macros` for the base class). Its
`macros_tag()` is the literal string `jet_search_current_results` (`:18-21`), taking no
args (`macros_args()` returns `[]`, `:38-41`). `macros_callback()` (`:53-65`) returns a
comma-joined list of post IDs from
`jet_search_ajax_handlers()->get_current_results_ids()` — **not** a live read of the
in-flight AJAX response; `get_current_results_ids()` (`ajax-handlers.php:2776-2839`)
independently rebuilds a search query from the current request's own query param
(`get_custom_search_query_param()`) and `get_form_settings()`, caching the resulting ID
list on both the instance (`$this->current_results_ids`) and via `wp_cache_set()`
(1-hour default TTL, filterable via `jet-search/current-results-cache-ttl`). This means
`%jet_search_current_results%` reflects "what would the *current page's* search return,"
reconstructed independently — it is not wired to read `$response['posts']` from a
specific already-executed `get_search_data()` call.

## Server-rendered results page (out of primary scope, noted for completeness)

`Jet_Search_Custom_URL_Handler` (`includes/custom-url-handler.php:20`, constructed
unconditionally in `Jet_Search::init()`) is a **third**, non-AJAX way search input turns
into results: when a JetSearch form is configured with a dedicated results page/target
widget and the visitor lands there via a plain URL (not an AJAX call), this class hooks
`pre_get_posts` (`:50`) to reshape the *main* query for that page using the same kind of
settings (`search_results_target_widget_id`, taxonomy/term scoping) the AJAX path
consumes from `$_GET['data']`. Not read in depth for this skill (out of scope per this
round's focus on the AJAX/REST request pipeline) — flagged here so a future session
knows a third entry point exists rather than assuming AJAX/REST are the only two ways a
search request becomes results.

## Gotchas

- **The AJAX `wp_ajax_jet_ajax_search`/`wp_ajax_nopriv_jet_ajax_search` hooks are only
  registered when `DOING_AJAX` is true** (`ajax-handlers.php:97`) — a script or test
  suite running outside a real `admin-ajax.php` request cannot observe them via
  `has_action()`; test the action-name/nonce constants and `get_search_data()` directly
  instead (see `tests.php`).
- **`get_search_results()` (the actual `wp_ajax_*` callback) always ends in
  `wp_send_json_error()`/`wp_send_json_success()` → `wp_die()`** — same "uncatchable in
  an in-process test harness" shape documented elsewhere in this repo
  (`jetwoobuilder-templates/TEST-REGIMEN.md`). Its inner method, `get_search_data()`,
  does **not** have this problem on the normal path (only on the `is_wp_error()`
  branch) — call that directly instead.
- **`Jet_Search_Rest_Search_Route::callback()` has the exact same `wp_send_json_success()`
  →`wp_die()` ending** — do not drive it end-to-end via `rest_do_request()` in an
  in-process suite; test route registration and `permission_callback()` directly instead
  (both are safe, side-effect-free calls).
- **The REST `search-posts` route has no nonce check at all** — `permission_callback()`
  is a bare `return true`. Don't assume REST and AJAX have equivalent request
  authentication just because they call nearly-identical code.
- **`search_taxonomy`/`category__in`/`include_terms_ids`/`exclude_terms_ids` (mechanism
  A, real `tax_query` scoping) and `search_in_taxonomy`/`search_in_taxonomy_source`
  (mechanism B, `Jet_Search_Tax_Query`'s raw-SQL term-name search wired through
  `posts_search`) are two unrelated features that happen to share the
  `include_terms_ids`/`exclude_terms_ids` setting names** — don't assume setting
  `search_taxonomy` alone activates mechanism B, or that `search_in_taxonomy_source`
  alone activates mechanism A.
- **`Jet_Search_Token_Manager` is unrelated to the AJAX search nonce** despite the
  similar naming — it's Search Suggestions session tracking, keyed by
  `md5(ip + NONCE_SALT)`, not a WP nonce at all.
- **`%jet_search_current_results%` independently rebuilds its own query** rather than
  reading back a specific AJAX response — it reflects "what the current page's search
  settings would return right now," cached for up to an hour by default.
- **JetEngine's `jet-engine/register-macros` action (and the `jet-engine/listings/
  macros-list` filter it feeds) only fires the first time `Jet_Engine_Listings_Macros::init()`
  runs** (`jet-engine` plugin, `includes/components/listings/macros.php:28-44`, guarded by
  an `$initialized` flag) — it is **not** fired unconditionally at plugin-boot time.
  Reading `apply_filters( 'jet-engine/listings/macros-list', [] )` directly, before
  anything else in the same request has triggered a listing render (or otherwise called
  `jet_engine()->listings->macros->get_all()`/`init()`), sees none of the sibling-plugin
  registrations, JetSearch's `jet_search_current_results` included — call
  `jet_engine()->listings->macros->init()` first if verifying this outside a real
  listing-render request.

## How this was verified

Read `jet-search.php` (bootstrap, `jet_search()` singleton, `load_files()`),
`includes/ajax-handlers.php` (the `get_ajax_action`/`get_search_results`/
`get_search_data`/`set_query_settings`/`prepare_terms_data`/`set_posts_search`/
`get_current_results_ids` regions — not the full 2865 lines line-by-line, but every
region relevant to the search-request lifecycle), `includes/token-manager.php`,
`includes/rest-api/manager.php` and `includes/rest-api/endpoints/{base,search-route}.php`,
`includes/search-sources/{manager,base,source-terms,source-users}.php`,
`includes/jet-search-tax-query.php`, `includes/compatibility.php` and
`includes/compatibility/jet-engine/{manager,macros/current-results}.php`, relevant
`tools.php` helpers (`prepare_public_search_query_args`/`filter_public_search_query_results`/
`merge_public_current_query_args`), and skimmed `includes/custom-url-handler.php` (scope
decision documented above) — all in JetSearch 3.6.1.3 source
(`plugins/jet-search/`). Cross-referenced `jettabs-query-gateway`'s and
`jetelements-query-gateway`'s documentation of the `jet-engine/register-macros`
cross-plugin pattern to confirm JetSearch's own macro registration follows the same
convention rather than inventing something new.

**Live-verified 2026-07-17** against `jackfruit.epeak.studio` (JetSearch active,
JetEngine active): runnable suite `tests.php` (10 assertions, `jsp-1` through `jsp-10`),
deployed as Code Snippets snippet id 79 ("AGENT-TEST-SUITE: jetsearch-query-pipeline")
— see `TEST-REGIMEN.md` for the full run log — **10/10 passing**, after fixing one
test-only bug on first run (`jsp-10`: a raw `apply_filters('jet-engine/listings/
macros-list', [])` call doesn't see any sibling-plugin macro registrations, JetSearch's
included, unless something has already triggered JetEngine's own lazy
`Jet_Engine_Listings_Macros::init()` first — see the Gotchas entry above; no `SKILL.md`
correction was needed, the plugin behavior was as documented, only the test's approach
to observing it was wrong). Tests call `get_search_data()`/`prepare_terms_data()`/the
Search Sources `Manager`/the REST route's registration and `permission_callback()`
directly rather than driving the full `wp_ajax_*`/REST-callback request cycle end to
end, since both real entry points end in `wp_send_json_success()`/`wp_die()` — the same
documented "uncatchable in this harness" shape as other `wp_ajax_*`/REST-callback
endpoints elsewhere in this repo (see Gotchas above). The AJAX/REST hooks'
*registration* itself (`wp_ajax_jet_ajax_search` existing, `/jet-search/v1/search-posts`
being a routed endpoint) is exercised where safe to do so without invoking the
terminating callback.
