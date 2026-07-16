---
name: jetengine-query-builder
description: Use when working with JetEngine's Query Builder component (SQL/Posts/Terms/Users/Comments/Repeater query types configured under Query Builder in the admin) — fetching a configured query object by id, calling it to get items/counts, or registering a brand-new custom query type. Captures verified behavior of `Jet_Engine\Query_Builder\Manager`/`Query_Factory` from JetEngine 3.8.12 source. Not yet live-verified against a running site — see TEST-REGIMEN.md.
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetEngine Query Builder

Query Builder is JetEngine's system for defining a reusable, named query (SQL, Posts,
Terms, Users, Comments, Repeater, or a merge of several) that listings/widgets/dynamic
tags can reference by id. It is a **factory keyed by a `query_type` string**, not a
single flat "Query" class — agents that assume one universal query object will call
methods that don't exist on the actual subclass returned.

## Core classes

- `Jet_Engine\Query_Builder\Manager` — `includes/components/query-builder/manager.php`
  — the top-level registry, reachable as `jet_engine()->query_builder`.
- `Jet_Engine\Query_Builder\Query_Factory` — `includes/components/query-builder/query-factory.php`
  — maps a `query_type` string to a concrete query class.
- Abstract `Queries\Base_Query` — `includes/components/query-builder/queries/base.php`
  — the shared contract every concrete query type implements.
- Concrete types, one file each in `includes/components/query-builder/queries/`:
  `SQL_Query`, `Posts_Query`, `Terms_Query`, `Users_Query`, `Comments_Query`,
  `Repeater_Query` (queries a CCT/meta repeater field), `Current_WP_Query` (wraps the
  page's own main query), `Merged_Query` (combines multiple queries' results).

## Fetching a configured query and running it

```php
$query = jet_engine()->query_builder->manager->get_query_by_id( $query_id );
// or, when resolving for a specific render context (e.g. inside a listing loop):
$query = jet_engine()->query_builder->manager->get_query_by_id_for_context( $query_id );

$items = $query->get_items();               // returns the rows/posts/terms/etc for this query type
$args  = $query->get_query_args();          // the assembled query args (WP_Query args, SQL, etc. depending on type)
$total = $query->get_items_total_count();
```

`get_query_by_id()` (`manager.php:432`) and `get_query_by_id_for_context()`
(`manager.php:457`) are the only supported entry points — don't `new Posts_Query(...)`
directly or reach into `$wpdb` to re-derive what a configured query would return; the
query's settings (conditions, order, dynamic tag values in its args) only get applied
correctly through the Manager.

`Base_Query::get_items()` (`queries/base.php:567`) and `get_query_args()` (`:553`) are
concrete on the base class and call down into each subtype's abstract
`_get_items()`/`get_items_total_count()` (`:638`, `:645`) — the actual query execution
differs completely by type (a `SQL_Query` runs raw SQL via `$wpdb->get_results()`, a
`Posts_Query` builds and runs a `WP_Query`), so don't assume a `WP_Query`-shaped return
value from `get_items()` without first checking the query's configured type.

## Registering a custom query type

```php
add_action( 'jet-engine/query-builder/init', function( $query_builder ) {
    $query_builder->query_factory->register_query( 'my_custom_type', 'My_Namespace\\My_Query_Class' );
} );
```

`Query_Factory::register_query( $type, $class )` (`query-factory.php:129`) adds an
entry to the type→class map read by `get_default_query_types()` (`query-factory.php:90`).
The registered class must extend `Queries\Base_Query` and implement its abstract
methods (see the base class file for the exact contract) — there is no lighter-weight
"just add a callback" registration path for a genuinely new data source, unlike
JetSmartFilters' provider/filter-type registration (see `jetsmartfilters-query`) which
is comparatively closer to a plain action-based registry.

## Hooks

- `do_action( 'jet-engine/query-builder/init', $manager )` (`manager.php:302`) — fires
  once the Manager (and its `query_factory`) is ready; the correct place to register a
  custom query type, since `query_factory` doesn't exist yet at plain `plugins_loaded`.
- `do_action( 'jet-engine/query-builder/queries/register', $query_factory )` (`query-factory.php:126`)
  — fires specifically for query-type registration, narrower than the manager-level
  hook above; either works for `register_query()`, but this one guarantees
  `query_factory` itself (not just the manager) is the argument passed.
- `apply_filters( 'jet-engine/query-builder/flush-object-cache-on-save', false )`
  (`manager.php:419`) — return `true` to force a cache flush whenever a query is saved
  in the admin (useful if a custom query type caches externally and needs invalidating
  on config changes).

## How this was verified

Read `includes/components/query-builder/manager.php`, `query-factory.php`, and
`queries/base.php` in JetEngine 3.8.12 source, confirming method signatures, the
factory registration pattern, and hook names by direct file:line citation. Not yet
run against a live site — see `TEST-REGIMEN.md`.
