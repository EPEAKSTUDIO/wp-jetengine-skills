---
name: jetengine-cct-internals
description: Use when writing PHP that reads OR writes JetEngine Custom Content Type (CCT) data — directly from the database, via the Relations API, or via the real `Item_Handler::update_item()`/`raw_delete_item()` CRUD API for creating/updating/deleting CCT rows — on any JetEngine site. E.g. building a custom REST endpoint that joins multiple CCTs, resolving a relation between two CCTs, debugging why a "FK" field on a CCT record is empty, or programmatically inserting/updating/deleting a CCT item without hand-rolling `$wpdb` writes. Captures real, verified behavior of JetEngine's CCT tables, the `Item_Handler` write API, and `Jet_Engine\Relations\Manager`, learned by building/testing a custom REST endpoint and by live-verifying CCT row CRUD against a real site.
license: MIT
metadata:
  author: project
  version: "0.4.0"
---

# JetEngine CCT & Relations Internals

**Live-verified (2026-07-16):** this skill now has a runnable suite (`tests.php`, 6
tests, `cct-1` through `cct-6`) per `docs/test-harness-guide.md` — 6/6 pass. See
`TEST-REGIMEN.md` for the run log.

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

## Writing CCT rows: don't hand-roll `$wpdb->insert()` — use `Item_Handler`

Everything above is the *read* side. For **creating, updating, or deleting a CCT row**,
JetEngine has a real public API — do not `$wpdb->insert()`/`update()`/`delete()` the CCT
table directly, since that skips status/timestamp/single-post bookkeeping the plugin's
own admin UI relies on. The verified, correct path:

```php
$factory = \Jet_Engine\Modules\Custom_Content_Types\Module::instance()
    ->manager->get_content_types( 'your_cct_slug' ); // Factory instance, false if slug unknown
$handler = $factory->get_item_handler(); // Item_Handler instance

// Insert: no `_ID` key in the array → new row. Returns the new row's int id.
$new_id = $handler->update_item( array( 'title' => 'Hello', 'status' => 'draft' ) );

// Update: same method, just include `_ID` → updates that row instead. Returns the id.
$handler->update_item( array( '_ID' => $new_id, 'title' => 'Hello (edited)' ) );

// Delete: explicitly documented in source as safe to call from anywhere
// ("Used to delete CCT items programatically from anywhere. All user access
// checks must be implemented before calling of this method!" — it does NOT
// check current_user_can() itself).
$handler->raw_delete_item( $new_id );
```

**One method (`update_item()`) does both insert and update** — the only signal is
whether the array contains an `_ID` key, not a separate `insert_item()`/`create_item()`
method (those names don't exist on `Item_Handler` — don't guess them). Verified live
(2026-07-16, JetEngine on `jackfruit.epeak.studio`): inserting via `update_item()`
without `_ID` returns a fresh int id (`1`, then `2` for a second insert); calling it
again with `'_ID' => 1` updated that same row (title changed, `cct_status` stayed
`publish`, `cct_modified` bumped); `raw_delete_item( 1 )` removed exactly that row, row
`2` untouched.

**Gotcha: `status` (your own field, if you named one that) vs. `cct_status` (built-in)
are two different columns.** `update_item()` doesn't require you to pass `cct_status` —
it defaults to `'publish'` on insert regardless of what you name your own fields. If a
CCT has a custom field literally named `status`, don't confuse it with the built-in
`cct_status` bookkeeping column when reading rows back.

`update_item()` returns an **int id on success or a `WP_Error`** — always check
`is_wp_error()` before treating the return value as an id; it is not a bare
`false`-on-failure API.

Fires real hooks around every write, useful for reacting to CCT changes without polling:
`jet-engine/custom-content-types/{create-item,created-item,update-item,updated-item,delete-item}/{slug}`
(each interpolates the CCT's slug, mirroring the JetFormBuilder per-action-type hook
pattern documented in `jetformbuilder-hooks`).

## Reformatting a field's value during CSV export

The admin "Export items to CSV" action (`Jet_Engine\Modules\Custom_Content_Types\Export::send_items()`,
`includes/modules/custom-content-types/inc/export.php:58-101`) runs each cell through
`apply_filters( 'jet-engine/custom-content-types/export/value', $value, $key, $content_type, $item )`
(`export.php:91`) right before writing it into the CSV row — this is the hook to
reformat a field for export without touching how it's stored/returned elsewhere (e.g.
turning a `repeater`-type field's raw array into human-readable text). Use
`$content_type->get_formatted_fields()` to look up a field's declared `type` by key:

```php
add_filter( 'jet-engine/custom-content-types/export/value', function( $value, $key, $content_type, $item ) {
    $fields = $content_type->get_formatted_fields();
    if ( 'repeater' !== ( $fields[ $key ]['type'] ?? '' ) || ! is_array( $value ) ) {
        return $value;
    }
    return implode( '; ', array_map( 'wp_json_encode', $value ) );
}, 10, 4 );
```

The column separator itself is a **separate** filter,
`jet-engine/custom-content-types/export/cvs-separator` (`export.php:70` — note the
misspelling, "cvs" not "csv", matches the real hook name), defaulting to `,`.

## Gating access, reshaping schema, and rewriting a pending write

Four more `Factory`/`Item_Handler` filters, sourced from real Codelab/Gist snippets and
confirmed against `includes/modules/custom-content-types/inc/factory.php` and
`inc/item-handler.php`:

- **`jet-engine/custom-content-types/user-has-access`** (filter, 2 args: `$allow` bool,
  `$factory` instance) — `factory.php:128-134`, inside `Factory::user_has_access()`.
  Fires with `current_user_can( $this->user_cap() )` as the starting value; return
  `false` to block a user from creating/editing/deleting items of this CCT regardless of
  their capability, or `true` to grant access to a role that otherwise lacks the
  configured capability (e.g. letting a custom `hr-manager` role edit others' items).
- **`jet-engine/custom-content-types/factory/raw-fields`** (filter, 2 args: `$fields`
  array, `$factory` instance) — `factory.php:39`, applied to the field list right after
  it's assembled from config + service fields, before it's handed to `DB` for column
  definitions. Use to add/remove options on a specific field by checking
  `$factory->get_arg( 'slug' )`.
- **`jet-engine/custom-content-types/admin-columns`** (filter, 2 args: `$columns` array,
  `$factory` instance) — `factory.php:850-854`, applied after JetEngine builds its
  default admin-list columns (including any `_cb` render callback already assigned for
  known column types like `cct_single_post_id`). Add a custom `_cb` callable here to
  reformat a column's displayed value (e.g. resolving an author-id field to a
  `display_name`, or a date field to a formatted string).
- **`jet-engine/custom-content-types/item-to-update`** (filter, 3 args: `$item` array,
  `$fields` array, `$item_handler` instance) — `item-handler.php:397`, inside
  `Item_Handler::update_item()`, applied to the assembled `$item` array right before the
  actual insert/update DB call. This is the last point to rewrite/sanitize a value (e.g.
  `wp_unslash()`-ing a textarea field) or inject a computed column before it's persisted
  — later than `export/value` (which only affects CSV export, not storage).

```php
// Let a custom role edit CCT items they don't own, but only for this one CCT:
add_filter( 'jet-engine/custom-content-types/user-has-access', function( $allow, $factory ) {
    if ( 'orders' === $factory->get_arg( 'slug' ) && current_user_can( 'hr-manager' ) ) {
        return true;
    }
    return $allow;
}, 10, 2 );
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

The "Writing CCT rows" section above was added later: read
`includes/modules/custom-content-types/inc/item-handler.php` (`Item_Handler::update_item()`/
`raw_delete_item()`) and `inc/manager.php`/`inc/factory.php` (`get_content_types()`,
`get_item_handler()`) in JetEngine source, then live-verified on
`jackfruit.epeak.studio` against the `agent_test_cct` CCT (id 15, created for the
`jetengine-mcp-tools` skill) via a temporary Code Snippets REST probe (id 20, kept
inactive, not deleted — see `docs/code-snippets-rest-api.md`): inserted two rows,
updated one by id, confirmed both via direct `$wpdb` read, then deleted one and
reconfirmed only it was gone.
