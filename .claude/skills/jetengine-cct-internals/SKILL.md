---
name: jetengine-cct-internals
description: Use when writing PHP that reads JetEngine Custom Content Type (CCT) data directly from the database or via the Relations API on any JetEngine site — e.g. building a custom REST endpoint that joins multiple CCTs, resolving a relation between two CCTs, or debugging why a "FK" field on a CCT record is empty. Captures real, verified behavior of JetEngine's CCT tables and `Jet_Engine\Relations\Manager` API, learned by building and testing a custom REST endpoint that joined CCT data server-side instead of chaining multiple API calls.
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetEngine CCT & Relations Internals

Practical, verified facts about how JetEngine stores and links Custom Content Type (CCT)
data, gathered by building a real endpoint that replaced several chained REST calls with
direct DB reads. Everything here was confirmed against a live JetEngine site, not just
documentation/inference — see "How this was verified" at the bottom.

## CCT tables

Each CCT is stored in its own table named `{$wpdb->prefix}jet_cct_{slug}` (e.g. a CCT
with slug `orders` lives in `wp_jet_cct_orders`). The slug is exactly what
`resource-get-configuration`'s `custom_content_types[].args.slug` reports. Every table
has a primary key column `_ID` (not `ID`, not `id` — that's a separate, often-unused
text meta field many CCTs also happen to have).

```php
$table = $wpdb->prefix . 'jet_cct_' . sanitize_key( $slug );
$row   = $wpdb->get_row(
    $wpdb->prepare( "SELECT * FROM {$table} WHERE _ID = %d LIMIT 1", $id ),
    ARRAY_A
);
```

Always `sanitize_key()`/whitelist the slug if it comes from user input — it's
interpolated directly into the table name.

## Relations are NOT always plain FK columns

CCTs sometimes have fields that *look* like foreign keys (e.g. a `related_order_id` or
`parent_record_id` text field) — **do not trust these are populated.** On a real record
they may be empty strings even though the field exists in the schema. The actual
parent/child link can live entirely in JetEngine's Relations system instead.

**How to tell which one a given install actually uses:** query a real record and check
whether the FK-looking column has a value. If it's blank, the relation is managed by
the Relations API (below), not the column.

Confirm via `resource-get-configuration` → `relations[]`, which lists every configured
relation as `{id, args: {parent_object: "cct::x", child_object: "cct::y", type}}`. Get
the numeric relation `id` for whichever parent→child link you need.

## Querying a relation: `jet_engine()->relations`

`jet_engine()->relations` is an instance of `Jet_Engine\Relations\Manager`. Key facts,
confirmed by dumping `get_class_methods()` on a live site:

- **There is no `get_relation( $id )` method** on the Manager, despite that being the
  intuitive guess. Fatal error: `Call to undefined method
  Jet_Engine\Relations\Manager::get_relation()`.
- The correct accessor is `get_active_relations()`, which returns an array of
  `Jet_Engine\Relations\Relation` objects **keyed by the relation's numeric id**:

  ```php
  $relations = jet_engine()->relations->get_active_relations();
  $relation  = $relations[6] ?? null; // 6 = the relation id from the config dump
  ```

- `Relation` objects expose `get_parents( $child_id )` and `get_children( $parent_id )`.
- **Both return relation ROWS, not bare ids.** A row looks like:

  ```php
  [ '_ID' => '19568', 'created' => '...', 'rel_id' => '6',
    'parent_rel' => '0', 'parent_object_id' => '351', 'child_object_id' => '17209' ]
  ```

  To get the actual related record's id, pull `parent_object_id` (from
  `get_parents()`) or `child_object_id` (from `get_children()`) off the first row —
  don't treat the return value itself as an id:

  ```php
  $parents   = $relation->get_parents( $child_id );
  $row       = is_array( $parents ) ? reset( $parents ) : null;
  $parent_id = $row ? ( (array) $row )['parent_object_id'] ?? null : null;
  ```

- `Relation::is_parent()` takes **2 required arguments**, not 1 — don't call it just to
  check object type without checking its signature first (`ArgumentCountError` if you
  guess wrong on arg count for any JetEngine API method; when unsure, dump
  `get_class_methods()` and `get_args()` from a debug branch before assuming a
  signature).

## `get()`/`map()` flat-key fields (from REST responses)

Custom REST endpoints built on JetEngine CCTs that join across CCTs (e.g. via Query
Builder's join-table support) return the joined table's fields as **flat keys
containing a literal dot**, not nested objects:

```json
{ "_ID": "17209", "photo": "8942", "jet_cct_events._ID": "351",
  "jet_cct_events.timezone": "America/Los_Angeles" }
```

In Make/Integromat mappers this is why you see backtick-escaped field names like
`` `jet_cct_events._ID` `` — the backticks are escaping a literal dot inside a single
key name, not indicating a nested path. When replicating this shape from PHP, build it
the same way: `$row['jet_cct_events._ID'] = ...` as one array key, not
`$row['jet_cct_events']['_ID']`.

## Media fields

A CCT field of type `media` stores a plain WP attachment post ID (integer, as a string
in the row). To resolve it to a URL, use core WP — no JetEngine API needed:

```php
$source_url = wp_get_attachment_url( (int) $row['photo'] );
```

## Debugging JetEngine APIs you're unsure about

Don't guess method signatures against a live site and iterate on fatals. Add a
temporary, param-gated debug branch to the same endpoint that dumps what you need, call
it once, read the real answer, then delete the branch:

```php
if ( $request->get_param( 'debug_relations' ) && function_exists( 'jet_engine' ) ) {
    $manager = jet_engine()->relations;
    return new WP_REST_Response( [
        'manager_methods' => get_class_methods( $manager ),
        'active_relations' => array_map(
            fn( $r ) => [ 'class' => get_class( $r ), 'methods' => get_class_methods( $r ) ],
            $manager->get_active_relations()
        ),
    ], 200 );
}
```

This finds the real API in one round trip instead of guessing method names one fatal
error at a time. Required args are declared as fatal `ArgumentCountError`s in the PHP
error log, which name the exact file/line/expected-count — always paste that back
rather than re-guessing.

## How this was verified

Built while consolidating several chained external HTTP calls (a typical pattern:
fetch record → fetch related record → fetch a second related record → fetch a media
attachment, done as separate round trips) into one PHP REST endpoint doing direct
`$wpdb` reads + Relations API calls instead. Verified end-to-end against a real record
on a live JetEngine site by comparing the new endpoint's output field-by-field against
the original chained calls' outputs, then confirming a successful replay of the
consuming automation (Make/Integromat scenario) against the same payload with the
expected drop in operation count from folding multiple modules into one custom
endpoint.
