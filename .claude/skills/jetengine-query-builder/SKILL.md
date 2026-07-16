---
name: jetengine-query-builder
description: Use when working with JetEngine's Query Builder component (SQL/Posts/Terms/Users/Comments/Repeater query types configured under Query Builder in the admin) — fetching a configured query object by id, calling it to get items/counts, or registering a brand-new custom query type. Captures verified behavior of `Jet_Engine\Query_Builder\Manager`/`Query_Factory` from JetEngine 3.8.12 source, live-verified 2026-07-16 against a running site (see TEST-REGIMEN.md) — the accessor path below was corrected after the runnable test suite caught an incorrect one.
license: MIT
metadata:
  author: project
  version: "0.3.0"
---

# JetEngine Query Builder

Query Builder is JetEngine's system for defining a reusable, named query (SQL, Posts,
Terms, Users, Comments, Repeater, or a merge of several) that listings/widgets/dynamic
tags can reference by id. It is a **factory keyed by a `query_type` string**, not a
single flat "Query" class — agents that assume one universal query object will call
methods that don't exist on the actual subclass returned.

**Correction notice (2026-07-16):** the first version of this skill claimed a
`jet_engine()->query_builder->manager` accessor. That was wrong — live-verified via the
runnable test suite (`tests.php`), which failed with "call to a member function on
null" the moment it ran, catching the mistake immediately. **There is no
`jet_engine()->query_builder` property anywhere in the plugin.** The real access path
is the static singleton below. This is a good example of exactly why this repo now
runs suites instead of trusting source-reading alone — see `docs/test-harness-guide.md`.

## Core classes

- `Jet_Engine\Query_Builder\Manager` — `includes/components/query-builder/manager.php`
  — the top-level registry. **Reachable only via the static singleton
  `Jet_Engine\Query_Builder\Manager::instance()`** — confirmed live; there is no
  `jet_engine()->query_builder` property.
- `Jet_Engine\Query_Builder\Query_Factory` — `includes/components/query-builder/query-factory.php`
  — maps a `query_type` string to a concrete query class. **Not an object you fetch from
  the Manager** — its type registry (`self::$_queries`) is a private **static** property
  on the class itself, and `register_query()` is a **static method**
  (`query-factory.php:139`), called directly on the class, not through any instance.
- Abstract `Queries\Base_Query` — `includes/components/query-builder/queries/base.php`
  — the shared contract every concrete query type implements.
- Concrete types, one file each in `includes/components/query-builder/queries/`:
  `SQL_Query`, `Posts_Query`, `Terms_Query`, `Users_Query`, `Comments_Query`,
  `Repeater_Query` (queries a CCT/meta repeater field), `Current_WP_Query` (wraps the
  page's own main query), `Merged_Query` (combines multiple queries' results).
- **JetEngine's own other modules register additional query types beyond these eight**,
  through the exact same `Query_Factory::register_query()` mechanism documented below —
  confirmed live via `Query_Factory::get_query_types()` on the sandbox, which returned
  `custom-content-type`, `relations-query`, and `jet-form-builder-query` in addition to
  the eight built-ins. A CCT-backed Query Builder query's actual runtime class is
  `Jet_Engine\Modules\Custom_Content_Types\Query_Builder\CCT_Query`, not one of the
  eight core `Queries\*` classes — don't assume every query object is an instance of a
  core query class just because it came from `get_query_by_id()`.

## Fetching a configured query and running it

```php
$query = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id( $query_id );
// or, when resolving for a specific render context (e.g. inside a listing loop):
$query = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id_for_context( $query_id );

$items = $query->get_items();               // returns the rows/posts/terms/etc for this query type
$args  = $query->get_query_args();          // the assembled query args (WP_Query args, SQL, etc. depending on type)
$total = $query->get_items_total_count();
```

`get_query_by_id()` (`manager.php:432`) and `get_query_by_id_for_context()`
(`manager.php:457`) are the only supported entry points — don't `new Posts_Query(...)`
directly or reach into `$wpdb` to re-derive what a configured query would return; the
query's settings (conditions, order, dynamic tag values in its args) only get applied
correctly through the Manager. **Live-verified**: called against real query id 16
(`agent_test_cct`-backed) on the sandbox — returned a real query object whose
`get_items()`/`get_query_args()` both worked (see `TEST-REGIMEN.md` Test 1/2).
**`get_query_by_id()` with a non-existent id returns plain `false`** (not `null`, not a
`WP_Error`) — confirmed live, safe to check with `if ( ! $query )`.

`Base_Query::get_items()` (`queries/base.php:567`) and `get_query_args()` (`:553`) are
concrete on the base class and call down into each subtype's abstract
`_get_items()`/`get_items_total_count()` (`:638`, `:645`) — the actual query execution
differs completely by type (a `SQL_Query` runs raw SQL via `$wpdb->get_results()`, a
`Posts_Query` builds and runs a `WP_Query`), so don't assume a `WP_Query`-shaped return
value from `get_items()` without first checking the query's configured type.

## Registering a custom query type

```php
add_action( 'jet-engine/query-builder/queries/register', function( $factory_class ) {
    \Jet_Engine\Query_Builder\Query_Factory::register_query( 'my_custom_type', 'My_Namespace\\My_Query_Class' );
} );
```

`Query_Factory::register_query( $type, $class )` (`query-factory.php:139`) is a
**static** method that writes into a **static** `self::$_queries` map
(`query-factory.php:17`) shared by every query on the request — it adds an entry read
by `get_default_query_types()`/`get_query_class()` (`query-factory.php:92,149`). The
registered class must extend `Queries\Base_Query` and implement its abstract methods —
there is no lighter-weight "just add a callback" registration path for a genuinely new
data source, unlike JetSmartFilters' provider/filter-type registration (see
`jetsmartfilters-query`) which is comparatively closer to a plain action-based registry.

**Timing gotcha, confirmed by reading `setup_queries()`/`ensure_queries()`
(`manager.php:382-421`, `query-factory.php:105-130`): the `Query_Factory` class file
itself is not `require`d until `Manager::setup_queries()` runs**, which happens *after*
`do_action('jet-engine/query-builder/init', $this)` fires. That means **the `init` hook
is the wrong place to call `Query_Factory::register_query()`** — the class doesn't
exist yet and the call would fatal ("Class not found") unless something else already
loaded it. The registration hook below is the correct, guaranteed-safe one.

## Rewriting a query's assembled args or its returned items (the two most commonly used real-world hooks)

By far the two most frequently used Query Builder extension points in real Codelab/Gist
snippets — more common than the registration hooks above, which most integrators never
need:

- **`jet-engine/query-builder/query/after-query-setup`** (action, 1 arg: `$this` [the
  `Base_Query` instance]) — `queries/base.php:397` — fires after `$this->final_query` is
  fully assembled (the WP_Query-shaped args array, keyed by `query_type`/
  `queried_object_id` plus whatever the concrete type added) but **before** items are
  fetched. This is the right place to rewrite `$query->final_query` directly (e.g.
  combining `post__in`/`post__not_in` so exclude wins over include, rewriting
  `tax_query` into an OR-relation group, or forcing an `order`/`orderby` — see the
  cross-plugin `jet-engine/query-builder/filters/before-after-props` hook in
  `jetsmartfilters-query`, which fires from the same general area but is JSF-request-
  specific). Since it's an action, not a filter, you mutate the object's public
  `final_query` property directly rather than returning a value:
  ```php
  add_action( 'jet-engine/query-builder/query/after-query-setup', function( $query ) {
      if ( 'posts' !== $query->query_type ) {
          return;
      }
      if ( ! empty( $query->final_query['post__not_in'] ) && ! empty( $query->final_query['post__in'] ) ) {
          $query->final_query['post__in'] = array_diff( $query->final_query['post__in'], $query->final_query['post__not_in'] );
      }
  } );
  ```
- **`jet-engine/query-builder/query/items`** (filter, 2 args: `$items`, `$query` [the
  `Base_Query` instance]) — `queries/base.php:577,591`, inside `Base_Query::get_items()`
  — fires on **every** call, including the cached-results early-return path (`:577`), so
  a callback here runs whether or not the query actually re-executed. Use this to
  reshape the *result set* after fetching (e.g. converting WooCommerce product/variation
  posts into real `WC_Product` objects, or filtering out currently-invisible variations)
  — as opposed to `after-query-setup`, which only affects what's queried, not what's
  returned.

## Hooks

- `do_action( 'jet-engine/query-builder/init', $manager )` (`manager.php:302`) — fires
  with the `Manager` instance, **before** `Query_Factory` is loaded. Useful for other
  Manager-level integration (e.g. reading `$manager->queries` once populated later), but
  **not** the place to register a custom query type — see the timing gotcha above.
- `do_action( 'jet-engine/query-builder/queries/register', get_called_class() )`
  (`query-factory.php:127`) — fires once per request, guarded by
  `if ( empty( self::$_queries ) )` (`query-factory.php:107`), immediately after the
  eight built-in query types are registered and their class files are `require`d. The
  argument passed is **the class name string** `'Jet_Engine\Query_Builder\Query_Factory'`
  (from `get_called_class()`), **not a `Query_Factory` instance** — don't call
  instance methods on the callback argument; call `Query_Factory::register_query()`
  statically instead, as shown above.
- `apply_filters( 'jet-engine/query-builder/flush-object-cache-on-save', false )`
  (`manager.php:419`) — return `true` to force a cache flush whenever a query is saved
  in the admin (useful if a custom query type caches externally and needs invalidating
  on config changes).

## How this was verified

Read `includes/components/query-builder/manager.php`, `query-factory.php`, and
`queries/base.php` in JetEngine 3.8.12 source. **Live-verified 2026-07-16** via the
runnable suite `tests.php` (deployed as Code Snippets snippet, run through
`GET /agent-test/v1/suite/jetengine-query-builder` — see `docs/test-harness-guide.md`
for the harness convention): the suite's first run failed all four assertions against
the originally-written `jet_engine()->query_builder->manager` accessor ("call to a
member function on null"), which is what prompted re-reading the source and finding the
real static-singleton/static-method API documented above. Re-ran after the fix — see
`TEST-REGIMEN.md` for the corrected pass/fail results.
