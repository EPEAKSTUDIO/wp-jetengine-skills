---
name: jetengine-cct-internals
description: Use when writing PHP that reads JetEngine Custom Content Type (CCT) data directly from the database or via the Relations API on the BadgeIt/app.badgeit.io WordPress install (or any JetEngine site) — e.g. building a custom REST endpoint that joins multiple CCTs, resolving a relation between two CCTs, or debugging why a "FK" field on a CCT record is empty. Captures real, verified behavior of JetEngine's CCT tables and `Jet_Engine\Relations\Manager` API, learned by building and testing the `/badgeit/v1/get-data-for-print` endpoint against production data.
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetEngine CCT & Relations Internals

Practical, verified facts about how JetEngine stores and links Custom Content Type (CCT)
data, gathered by building a real endpoint (`GET /badgeit/v1/get-data-for-print` on
app.badgeit.io) that replaced 6 chained REST calls with direct DB reads. Everything here
was confirmed against production data, not just documentation/inference — see
"How this was verified" at the bottom.

## CCT tables

Each CCT is stored in its own table named `{$wpdb->prefix}jet_cct_{slug}` (e.g.
`wp_jet_cct_ticketscct`, `wp_jet_cct_eventscct`). The slug is exactly what
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

CCTs sometimes have fields that *look* like foreign keys (`recordid_event`,
`recordid_ticket`, `recordid_qr_checkin`, etc. on `ticketscct`) — **do not trust these
are populated.** On real production records they were empty strings. The actual
parent/child link lived entirely in JetEngine's Relations system instead.

**How to tell which one a given install actually uses:** query a real record and check
whether the `recordid_*` column has a value. If it's blank, the relation is managed by
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
  $parents = $relation->get_parents( $ticket_id );
  $row      = is_array( $parents ) ? reset( $parents ) : null;
  $event_id = $row ? ( (array) $row )['parent_object_id'] ?? null : null;
  ```

- `Relation::is_parent()` takes **2 required arguments**, not 1 — don't call it just to
  check object type without checking its signature first (`ArgumentCountError` if you
  guess wrong on arg count for any JetEngine API method; when unsure, dump
  `get_class_methods()` and `get_args()` from a debug branch before assuming a
  signature).

## `get()`/`map()` flat-key fields (from REST responses)

Custom REST endpoints built on JetEngine CCTs (e.g. `event-by-event-id-badgeit`,
`get-ticket-qrs-event-from-ticket-id-badgeit`) that join across CCTs return the joined
table's fields as **flat keys containing a literal dot**, not nested objects:

```json
{ "_ID": "17209", "pdf": "8942", "jet_cct_eventscct._ID": "351",
  "jet_cct_eventscct.dates_timezone": "America/Los_Angeles" }
```

In Make/Integromat mappers this is why you see backtick-escaped field names like
`` `jet_cct_eventscct._ID` `` — the backticks are escaping a literal dot inside a single
key name, not indicating a nested path. When replicating this shape from PHP, build it
the same way: `$event_row['jet_cct_eventscct._ID'] = ...` as one array key, not
`$event_row['jet_cct_eventscct']['_ID']`.

## Media fields

A CCT field of type `media` stores a plain WP attachment post ID (integer, as a string
in the row). To resolve it to a URL, use core WP — no JetEngine API needed:

```php
$source_url = wp_get_attachment_url( (int) $row['pdf'] );
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

This found the real API in one round trip instead of guessing method names one fatal
error at a time. Required args are declared as fatal `ArgumentCountError`s in the PHP
error log, which name the exact file/line/expected-count — always paste that back
rather than re-guessing.

## How this was verified

Built while consolidating 6 chained Make.com HTTP calls (ticket, printer, print,
ticket+event join, event, WP media) into one PHP REST endpoint
(`GET /badgeit/v1/get-data-for-print`) doing direct `$wpdb` reads + Relations API calls
instead. Verified end-to-end against a real production ticket (id 17209, event 351,
temp_key `sZsBXV2zWh`) by comparing the new endpoint's output field-by-field against the
original 6 modules' outputs, then running the updated Make scenario against the same
webhook payload and confirming a successful replay with the expected drop in operation
count (26 → 21, i.e. -5 for 6 modules folded into 1).
