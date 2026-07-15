---
name: jetengine-relations
description: Use when working with JetEngine Relations beyond simple parent/child lookups — bulk-fetching relations for multiple ids without N+1 queries, wiring the "Connect Relation Items" JetFormBuilder action, hitting the public Relations REST API, or understanding where relation config is actually stored. Captures verified behavior of the `Jet_Engine\Relations\Manager`/`Relation` classes from JetEngine 3.8.12 source, extending the base facts already in `jetengine-cct-internals`.
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetEngine Relations (deep dive)

Builds on the base facts in `jetengine-cct-internals` (`jet_engine()->relations` is a
`Jet_Engine\Relations\Manager`; `get_active_relations()` returns `Relation` objects
keyed by numeric id; `get_parents()`/`get_children()` return rows with
`parent_object_id`/`child_object_id`, not bare ids). This skill covers what's beyond
that: relation/object types, storage, bulk fetching, the JetFormBuilder integration,
and the public REST API. Verified against JetEngine 3.8.12 source.

## Relation types vs. object types (two different things)

**Relation type** — how many parents/children a link allows, literal strings enumerated
in `Manager::get_relations_types()` (`includes/components/relations/manager.php:172-176`):
`'one_to_one'`, `'one_to_many'`, `'many_to_many'`. This is a config-time attribute
stored on the relation — **not enforced at query time**; `get_children()`/`get_parents()`
behave identically regardless of type. Don't assume a `one_to_one` relation will fail if
you programmatically create a second link — check `is_single_child()`/`is_single_parent()`
(`relation.php:306,320`) if you need to enforce that yourself.

**Object type** — what kind of thing is on each side of the relation: `'posts'`, `'terms'`,
or `'mix'` (CCTs and other custom sources), literal strings from each `Types\*::get_name()`
(`types/posts.php:16`, `types/terms.php:18`, `types/mix.php:16`), all extending abstract
`Types\Base` (`types/base.php:9`).

## Storage: a custom DB table, not `wp_options`

Relation config lives in a real DB table, **not** a wp_options blob you can read
directly and expect to be current. `Data` class (`data.php:16`) starts from option
`jet_engine_relations`, but on first `get_raw()` call migrates it into custom tables
prefixed **`jet_rel_`** (`storage/db.php:18`) — a default relation-rows table plus a
separate meta table, managed via `Storage\Manager::get_db_instance('default'|'default_meta', ...)`
(`storage/manager.php:27-58`). There is **no public `register_relation()` helper** —
programmatic creation goes through the same internal CRUD JetEngine's admin UI uses
(`Data::create_item()`/`edit_item()`), or via the REST endpoints below.

## Bulk-fetching relations without N+1 queries

`get_children($parent_id, $fields)` and `get_parents($child_id, $fields)`
(`relation.php:674-757`) **both accept an array**, not just a single id — this isn't a
separate method, just pass an array:

```php
$relation = jet_engine()->relations->get_active_relations( $rel_id );
$rows = $relation->get_children( array( 12, 45, 99 ), 'all' ); // ONE query, not 3
```

Internally this switches the query to an `'operator' => 'IN'` clause
(`relation.php:682-689`). **The caller must group results by `parent_object_id`
manually** — rows for all input ids come back mixed together, there's no automatic
per-id bucketing. Passing `$fields` as anything other than `'all'` (e.g. `'ids'`) maps
rows down to just the id column instead of full rows (`relation.php:702-706`).

`get_siblings($object_id, $from = 'child_object', $fields)` (`relation.php:768`) does 2
queries internally (fetch related ids, then a second `IN` query) regardless of
single/array input — still far better than looping `get_parents()`/`get_children()`
per id.

**Caching:** every call is memoized via `wp_cache_get/set` keyed on relation id + object
id(s) (`relation.php:695-698`). On a persistent object cache this helps across requests;
on the default non-persistent cache it only dedupes repeats **within the same request**
— calling `get_children($single_id)` in a loop across many single ids is still N
queries. Use the array form whenever you're resolving relations for a result set, not a
single record.

## JetFormBuilder integration: "Connect Relation Items" action

JetFormBuilder has a real, built-in form action for this (not something you build
yourself) — registered only if `\Jet_Form_Builder\Actions\Manager` exists
(`forms/jet-form-builder/actions-manager.php:10-15`), action id
**`connect_relation_items`**, labeled "Connect Relation Items"
(`forms/manager.php:24,54`). It reads `relation`, `parent_id`/`child_id` field mappings,
`context` (default `'child'`), and `store_items_type` (default `'replace'`) from the
action's settings, then delegates to `Forms\Manager::update_related_items()`:

```php
// forms/jet-form-builder/action.php:28-46 (abridged)
public function do_action( array $request, Action_Handler $handler ) {
    $relation  = $this->settings['relation'] ?? false;
    $parent_id = $request[ $this->settings['parent_id'] ] ?? false;
    $child_id  = $request[ $this->settings['child_id'] ] ?? false;
    $res = Forms::instance()->update_related_items( array(
        'relation' => $relation, 'parent_id' => $parent_id, 'child_id' => $child_id,
        'context' => $this->settings['context'] ?? 'child',
        'store_items_type' => $this->settings['store_items_type'] ?? 'replace',
    ) );
    if ( is_wp_error( $res ) ) {
        throw ( new Action_Exception( $res->get_error_message() ) )->dynamic_error();
    }
}
```

Editor JS asset choice depends on which JFB actions system is active: `jfb-action-v2`
if `\JFB_Modules\Actions_V2\Module` exists, else legacy `jfb-action`
(`actions-manager.php:24-34`) — both wire to the same PHP action class either way.

**Unconfirmed:** whether `store_items_type` supports values beyond `'replace'` (e.g. an
`'append'` mode) wasn't traced to the editor JS config — check
`forms/jet-form-builder/action.php:56+` before assuming append is/isn't supported.

## Public REST API for relations

Distinct from the generic CCT/post REST namespace. Admin/editor-only CRUD lives under
`includes/components/relations/rest-api/` (`Add_Relation`, `Edit_Relation`,
`Delete_Relation`, `Get_Relation`, `Get_Relations`). Separately, each relation can
expose a **public** REST surface if `init_public_rest_api()` is enabled on it
(`relation.php:93`), registered per-relation by `Public_Controller`
(`rest-api/public-controller.php:6`), namespace **`jet-rel`**:

- `GET /jet-rel/{rel_id}` (and `PUT` if the relation's `edit` setting is on)
- `GET /jet-rel/{rel_id}/{context}/{_ID}` — single-item lookup

**Unconfirmed:** exact accepted values for `{context}` in the route (likely
`parent`/`child`) weren't traced deep enough into `public-controller.php:61-107` to
state as fact — verify against a live route before depending on a specific value.

## How this was verified

Read `includes/components/relations/manager.php`, `relation.php`, `data.php`,
`storage/db.php`, `storage/manager.php`, `types/{base,posts,terms,mix}.php`,
`rest-api/public-controller.php`, and `forms/jet-form-builder/{action,actions-manager,manager}.php`
in JetEngine 3.8.12 source, confirming method signatures, literal type strings, table
prefixes, and the JFB action wiring by direct citation (file:line) rather than
inference. Not yet verified against a running site — see `TEST-REGIMEN.md` for the
runtime checks a future sandbox session should perform, particularly around bulk-fetch
query counts and the REST `{context}` param.
