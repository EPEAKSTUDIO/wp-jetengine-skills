# Test regimen: jetsearch-query-pipeline

Validates claims in `SKILL.md`. Run against the sandbox site (`jackfruit.epeak.studio`),
JetSearch and JetEngine both active.

This skill has a **runnable suite** (`tests.php`, 10 tests, `jsp-1` through `jsp-10`)
per `docs/test-harness-guide.md`. Run it live with:

```
GET /wp-json/agent-test/v1/suite/jetsearch-query-pipeline
```

(requires the always-active AGENT-TEST-CORE harness plus this suite's own snippet, both
active.)

## Run log — 2026-07-17

Deployed as Code Snippets snippet id 79 ("AGENT-TEST-SUITE: jetsearch-query-pipeline"),
active.

**First run — 9/10 passed, 1 failed, a test-only bug (not a plugin/doc bug):**

- `jsp-10` failed: `apply_filters( 'jet-engine/listings/macros-list', [] )` came back
  with only a handful of unrelated macros (`get_grandparent`, `get_grandchild`,
  `add_to_cart_text`, `wc_product_title`), no `jet_search_current_results`. Root cause:
  JetEngine's `Jet_Engine_Listings_Macros::init()`
  (`plugins/jet-engine/includes/components/listings/macros.php:28-44`) only fires
  `do_action( 'jet-engine/register-macros' )` — the hook `Jet_Search_Compatibility_JE`
  listens on to add its own filter callback — **the first time `init()` is called**,
  guarded by an `$initialized` flag; nothing in this suite's own request had triggered
  that lazy init yet (no listing item had been rendered), so `apply_filters()` was
  reading the filter's state from *before* any sibling-plugin macro registered. Fixed by
  calling `jet_engine()->listings->macros->init()` directly first (the same lazy-trigger
  a real listing render would eventually do), then reading
  `jet_engine()->listings->macros->handler->get_raw_list()` instead of re-`apply_filters`ing
  blind. Documented as a new gotcha in `SKILL.md` — this is the same general shape as this
  repo's other "lazy-init/lazy-require-behind-a-gate" lessons (see `jetengine-modules`'
  `Stores\Factory` and `jetengine-rest-api`'s Relations `Public_Controller`), just via a
  lazy `do_action()` + instance flag instead of a lazy `require`.

**Second run (after the fix) — 10/10 passed.** No `SKILL.md` corrections were needed for
any of the other 9 claims — first-run passes on `jsp-1` through `jsp-9` confirmed the
AJAX action/nonce scheme, `get_search_data()`'s `$_GET['data']` → `WP_Query` mapping, both
taxonomy-scoping helpers, built-in and custom Search Sources registration, and the REST
route's registration/no-nonce `permission_callback()` exactly as documented.

## Test jsp-1 (`tests.php`): AJAX action name / nonce action string

**Claim being tested:** `Jet_Search_Ajax_Handlers::$action` is the literal string
`jet_ajax_search`, reused for both the `wp_ajax_{action}`/`wp_ajax_nopriv_{action}` hook
names and the nonce action string `get_search_results()` verifies against.

**Setup:** none.

**Trigger:** `jet_search_ajax_handlers()->get_ajax_action()`.

**Expected observable:** returns `'jet_ajax_search'`.

**Pass criteria:** exact string match.

## Test jsp-2 (`tests.php`): nonce round-trip

**Claim being tested:** `get_search_results()`'s gate
(`wp_verify_nonce( $_GET['nonce'], $this->action )`) is a plain WP nonce check against
the `jet_ajax_search` action string — a nonce minted for that action verifies, one
minted for a different action does not.

**Setup:** none.

**Trigger:** `wp_create_nonce('jet_ajax_search')` then `wp_verify_nonce()` against the
same action; separately `wp_create_nonce('agent-test-bogus-action')` then
`wp_verify_nonce()` against `'jet_ajax_search'`.

**Expected observable:** first verifies truthy, second verifies falsy.

**Pass criteria:** both hold. Doesn't drive `get_search_results()` itself, since that
method ends in `wp_send_json_error()`/`wp_send_json_success()` → `wp_die()` on every
path — see "Not yet automated" below.

## Test jsp-3 (`tests.php`): `get_search_data()` empty-data early exit

**Claim being tested:** `get_search_data()` returns `false` when `$_GET['data']` is
empty — the same condition `get_search_results()` treats as "Empty Search Data".

**Setup:** temporarily unset `$_GET['data']`.

**Trigger:** `jet_search_ajax_handlers()->get_search_data()`.

**Expected observable:** `false`.

**Pass criteria:** exact match. Restores `$_GET` afterward.

## Test jsp-4 (`tests.php`): `$_GET['data']` → real `WP_Query` results

**Claim being tested:** a real `$_GET['data']` payload (`value`, `search_source`) makes
`get_search_data()` build and run a genuine `WP_Query` and return a response array
shaped with `posts`/`post_count`/`columns`, `error: false`.

**Setup:** set `$_GET['data'] = ['value' => 'a', 'search_source' => 'post']`.

**Trigger:** `jet_search_ajax_handlers()->get_search_data()`.

**Expected observable:** an array with all three keys present and `error === false`.

**Pass criteria:** all hold. This is the core "settings map onto `WP_Query` args and
produce a real result" claim — deliberately calls `get_search_data()` (safe on this
path) rather than `get_search_results()` (wp_die()s).

## Test jsp-5 (`tests.php`): `category__in` + `search_taxonomy` build a real `tax_query` IN clause

**Claim being tested:** taxonomy-scoping mechanism A (`SKILL.md`) — `category__in` +
`search_taxonomy` produce an `IN` clause in `$this->search_query['tax_query']` against
the given taxonomy and term id.

**Setup:** fetch a real, existing `category` term via `get_terms()` (no fixed term id
assumed — degrades to a documented skip if the site genuinely has zero category terms,
which shouldn't happen on a normal WP install but the test doesn't assume it). Set
`$_GET['data']` with `category__in => [term_id]`, `search_taxonomy => 'category'`, plus
`value`/`search_source`.

**Trigger:** `get_search_data()`, then read
`jet_search_ajax_handlers()->search_query['tax_query']` (public property).

**Expected observable:** a clause with `taxonomy: 'category'`, `operator: 'IN'`, and the
term id present in `terms`.

**Pass criteria:** clause found.

## Test jsp-6 (`tests.php`): `prepare_terms_data()` groups by real taxonomy

**Claim being tested:** `prepare_terms_data()` (the function backing
`include_terms_ids`/`exclude_terms_ids`) groups arbitrary term ids by their **actual**
taxonomy via `get_term( $id )->taxonomy`, not by an assumed/passed-in taxonomy.

**Setup:** reuse the same real category term id from jsp-5's `get_terms()` call.

**Trigger:** `jet_search_ajax_handlers()->prepare_terms_data( [ $term_id ] )`.

**Expected observable:** returned array has key `'category'` containing that term id.

**Pass criteria:** holds.

## Test jsp-7 (`tests.php`): built-in Search Sources auto-register

**Claim being tested:** `Manager::register_search_sources()` always registers `Terms`
and `Users` as built-in sources, reachable via `jet_search()->search_sources->get_sources()`.

**Setup:** none — sources register on `init` priority 99, already run by request time.

**Trigger:** inspect `get_sources()`'s returned array.

**Expected observable:** keys `terms`/`users` present, instances of the right classes.

**Pass criteria:** both hold.

## Test jsp-8 (`tests.php`): custom search source registers the same way as the built-ins

**Claim being tested:** a from-scratch custom source (extending
`\Jet_Search\Search_Sources\Base`, implementing the four abstract methods) registers via
the exact same `Manager::register_source()` call the built-ins use — the real extension
point for "search a custom DB table alongside posts."

**Setup:** force-load `base.php` if not already loaded (via
`jet_search()->search_sources->component_path()`, the plugin's own path helper — not a
guessed raw path, per this repo's established lazy-require-behind-a-gate lesson), define
a minimal throwaway subclass.

**Trigger:** `register_source( new Agent_Test_Jetsearch_Custom_Source() )`, then
`get_source('agent_test_source')`.

**Expected observable:** the fetched instance is the registered custom source.

**Pass criteria:** holds. Only lives in PHP memory for the request — no persistent state
created.

## Test jsp-9 (`tests.php`): REST `search-posts` route is real and not nonce-gated

**Claim being tested:** `/jet-search/v1/search-posts` is a genuinely registered GET
route (not legacy/unused), and its `permission_callback()` is an unconditional
`return true` — no nonce check, unlike the AJAX path.

**Setup:** none — the route registers on `rest_api_init`, already run for the outer
request this suite executes inside of (same "already-passed hook" situation as
`jetengine-rest-api`'s `rapi-7`, but here the route registration happens at plugin-boot
time via a constructor-hooked `add_action`, not something this suite needs to trigger
itself).

**Trigger:** inspect `rest_get_server()->get_routes()` for
`/jet-search/v1/search-posts` with a `GET` method entry; separately instantiate
`Jet_Search_Rest_Search_Route` directly and call `permission_callback()`.

**Expected observable:** route present with GET method; `permission_callback()` returns
`true`.

**Pass criteria:** all hold. Deliberately does not call `callback()` — see "Not yet
automated."

## Test jsp-10 (`tests.php`): JetEngine macro registration

**Claim being tested:** `Jet_Search_Compatibility_JE` hooks JetEngine's
`jet-engine/register-macros` action to register `jet_search_current_results` on the
`jet-engine/listings/macros-list` filter — the same cross-plugin macro-registration
pattern `jettabs-query-gateway`/`jetelements-query-gateway` document other Crocoblock
plugins using.

**Setup:** none beyond forcing JetEngine's own lazy macros init (see Run log below —
`jet-engine/register-macros` only fires the first time `Jet_Engine_Listings_Macros::init()`
runs, not unconditionally at boot).

**Trigger:** `jet_engine()->listings->macros->init()`, then read
`jet_engine()->listings->macros->handler->get_raw_list()`.

**Expected observable:** `jet_search_current_results` key present with a callable `cb`.

**Pass criteria:** holds.

## Not yet automated

- **A real end-to-end `wp_ajax_jet_ajax_search` request** (through `admin-ajax.php`,
  with a real front-end-generated nonce) — `get_search_results()` always ends in
  `wp_send_json_error()`/`wp_send_json_success()` → `wp_die()`, which this in-process
  harness cannot catch cleanly (same documented limitation as
  `jetwoobuilder-templates/TEST-REGIMEN.md`). jsp-1/jsp-2/jsp-3/jsp-4 cover the same
  underlying logic (`get_search_data()`, the nonce scheme) via safe direct calls instead.
  Needs a real browser or an out-of-process HTTP client hitting `admin-ajax.php` to
  exercise the full callback.
- **A real end-to-end `GET /jet-search/v1/search-posts` request through the actual REST
  callback** — same `wp_send_json_success()` → `wp_die()` ending as the AJAX path. jsp-9
  covers route registration and the (safe, side-effect-free) `permission_callback()`
  only. An out-of-process HTTP client (real `curl`/browser hitting the live REST URL,
  not `rest_do_request()` in-process) would be needed to observe the full response body
  without risking killing the harness's own PHP process.
- **Mechanism B taxonomy scoping** (`Jet_Search_Tax_Query`'s `search_in_taxonomy`/
  `search_in_taxonomy_source` raw-SQL term-name search, wired through the `posts_search`
  filter) — read and documented from source (`jet-search-tax-query.php`), not
  independently live-tested this round; would need a real search term matching an actual
  term *name* on the sandbox (not just a term id) to observe the SQL path taking effect,
  as distinct from mechanism A's `tax_query`-based scoping which jsp-5/jsp-6 do cover.
- **`Jet_Search_Custom_URL_Handler`'s server-rendered results-page path** — flagged in
  `SKILL.md` as out of primary scope for this skill; not read in the depth the AJAX/REST
  paths were, and not tested here. Would need a real "search results page" configured on
  a form plus a plain (non-AJAX) page load to exercise.
- **A real front-end AJAX Search widget's JS actually calling `wp_ajax_jet_ajax_search`
  with a nonce sourced from `wp_localize_script()`** — the exact enqueue/localize call
  site that hands the nonce to the front end JS was not independently traced this round
  (see `SKILL.md`'s note under "`get_search_results()` — nonce gate, then delegates").
- **Search Suggestions / `Jet_Search_Token_Manager`** — out of scope for this skill
  (a different feature); only flagged in `SKILL.md`'s Gotchas as "don't confuse this with
  the AJAX search nonce," not independently tested.
