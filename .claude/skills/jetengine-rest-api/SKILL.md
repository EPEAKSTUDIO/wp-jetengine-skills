---
name: jetengine-rest-api
description: Use when JetEngine's REST surface is involved in either direction — (A) JetEngine EXPOSING its own data over REST (why a CPT/CCT/meta-box/options-page field does or doesn't show up in `wp/v2/{post_type}` or `wp/json/settings`, what namespace a component's own `rest-api/` subfolder actually serves, how a Custom Content Type gets its own `jet-cct/{slug}` route since it isn't a real WP post, or giving a Query Builder query a public REST route) or (B) JetEngine CONSUMING a third-party REST API as a Listing Grid/form-notification data source via the "Rest API Listings" module (slug `rest-api-listings`) — registering/debugging an endpoint, its response-caching behavior, or the "REST API Request" JetFormBuilder action/notification. Captures verified behavior of JetEngine 3.8.12 source (`includes/components/{post-types,taxonomies,meta-boxes,options-pages,query-builder,relations}/rest-api/`, `includes/modules/custom-content-types/inc/rest-api/`, `includes/modules/rest-api-listings/`), live-verified 2026-07-17 against jackfruit.epeak.studio (11/11 tests.php assertions passing).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetEngine REST API — exposing vs. consuming

JetEngine's "REST API" surface is really **three unrelated things that happen to share
the word REST**, easy to conflate:

1. **JetEngine's own admin-builder CRUD API** (`jet-engine/v2` namespace) — lets the
   wp-admin JS dashboard create/edit/delete CPT/Taxonomy/Meta-Box/Options-Page/Query-
   Builder/Relations/CCT/Glossary **definitions**. Not covered in depth here — see
   `jetengine-mcp-tools`, which documents the same underlying `Data::create_item()` CRUD
   these endpoints call, and is the layer most agents actually need.
2. **JetEngine EXPOSING its own post/CCT/relation *data*** to REST consumers (WP core's
   `wp/v2/*`, or JetEngine's own `jet-cct`/`jet-rel` namespaces for the two data shapes
   that aren't real WP posts/comments). This is "direction A" below.
3. **The Rest API Listings module, CONSUMING a third-party REST API** as a Listing Grid
   data source (and, separately, as a JetFormBuilder/JetEngine-Forms notification
   action). This is "direction B" below.

This skill does **not** re-document `includes/core/mcp-tools/rest-api/` (the
`jet-engine/v1/mcp-tools/` and `jet-engine/v1/mcp/` routes) — that's `jetengine-mcp-tools`'s
job; this skill is about the REST routes those MCP tools sit on top of.

## The three REST namespaces, disambiguated

| Namespace | Registered by | Purpose |
|---|---|---|
| `jet-engine/v2` | `Jet_Engine_REST_API` (`includes/rest-api/manager.php:15,19`) | Admin-builder CRUD for JetEngine's own component **definitions** (add/edit/get/delete a CPT, taxonomy, meta box, options page, query, relation, CCT, glossary). Each component's `rest-api/` subfolder (`components/post-types/rest-api/*.php`, etc.) is a set of `Jet_Engine_Base_API_Endpoint` subclasses registered here via `$api_manager->register_endpoint(...)` (e.g. `components/post-types/manager.php:388-396`). **Note the version bump to `v2`** despite everything else in the plugin (MCP tools, CCT/Relations public data) living under `v1` — don't assume all JetEngine REST lives at one namespace. |
| `jet-engine/v1` | `includes/core/mcp-tools/rest-api/{run,mcp,get}-controller.php:21/41/21` | The MCP Tools layer (`jetengine-mcp-tools`) — a different, unrelated `v1`. |
| `jet-cct` | `Jet_Engine\Modules\Custom_Content_Types\Rest\Public_Controller` (`modules/custom-content-types/inc/rest-api/public-controller.php:8`) | **Public data** access to CCT *items* — see "CCTs need their own controller" below. |
| `jet-rel` | `Jet_Engine\Relations\Rest\Public_Controller` (`components/relations/rest-api/public-controller.php:8`) | **Public data** access to a relation's parent/child links — see below. |

`Jet_Engine_Base_API_Endpoint` (`includes/base/base-api-endpoint.php:16`) is the shared
abstract every `jet-engine/v2` admin endpoint extends: `get_name()` (route slug),
`callback()`, `get_method()` (default `'GET'`), `permission_callback()` (default `true`
— **individual endpoints must lock this down themselves**, e.g.
`add-post-type.php:111-113` requires `current_user_can('manage_options')`; don't assume
every `v2` route is admin-gated by the base class). `Jet_Engine_REST_API::register_endpoint()`
(`rest-api/manager.php:57-63`) keys endpoints by `get_name()` into a flat array, then
`register_routes()` (`:102-124`) walks it on `rest_api_init`, building the route as
`/{endpoint->get_name()}/{endpoint->get_query_params()}`.

## Direction A: JetEngine exposing its own data

### A1. CPT/Taxonomy items — plain WP core REST, JetEngine just plumbs `show_in_rest`

There is **no JetEngine-specific data endpoint for CPT/taxonomy items** — a CPT/taxonomy
created through JetEngine's admin UI gets its actual item data exposed exactly the way
any `register_post_type()`/`register_taxonomy()` call would: `wp/v2/{post_type}` /
`wp/v2/{taxonomy}`, core WP's own controller. JetEngine's `rest-api/add-post-type.php`
endpoint (the `jet-engine/v2` admin-CRUD route from the table above) just forwards the
admin UI's `advanced_settings.show_in_rest` checkbox straight into the `register_post_type()`
args it builds (`components/post-types/rest-api/add-post-type.php:63`) — **don't look for
a separate "JetEngine REST exposure" toggle**; it's the same `show_in_rest` WP core
already understands.

### A2. Meta Box fields — `register_meta()` with `show_in_rest`, NOT `register_rest_field()`

This is the one worth confirming explicitly, since `register_rest_field()` is the more
commonly-guessed API for "add a meta field to `wp/v2/{post_type}`" and JetEngine does
**not** use it. `Jet_Engine_Rest_Post_Meta` (`components/meta-boxes/rest-api/fields/post-meta.php:16`)
calls `register_meta( 'post', $field['name'], $args )` where `$args['show_in_rest']` is
either plain `true` or a schema array (`post-meta.php:216-229`) — meaning a Meta Box
field shows up under a post's `meta` object in the REST response
(`wp/v2/{post_type}/{id}.meta.{field_name}`), not as a top-level response key the way
`register_rest_field()` would add one. `get_field_type()`
(`post-meta.php:75-153`) maps each JetEngine field type to a REST scalar/array/object
type (e.g. `checkbox` → `object` unless `is_array`, `repeater` → always `object`,
`media` → `string`/`object`/`array` depending on `multi_upload`/`value_format`), and
`get_rest_schema()` (`:155-214`) builds the actual JSON-Schema `show_in_rest` array for
non-scalar types — both filterable via `jet-engine/meta-boxes/rest-api/fields/field-type`
and `.../schema`. Sibling classes `term-meta.php`/`user-meta.php` mirror this for
term/user meta boxes (same `register_meta( 'term' | 'user', ... )` pattern, not
independently read line-by-line here).

### A3. Options Pages — `register_setting()` with `show_in_rest`, exposed via `wp/v2/settings`

`Jet_Engine_Rest_Settings` (`components/options-pages/rest-api/fields/site-settings.php:20`)
**extends** `Jet_Engine_Rest_Post_Meta` from A2 (reusing its field-type/schema logic) but
overrides `register_field()` to call `register_setting( $option_group, $field_name, [
'type' => ..., 'show_in_rest' => ... ] )` instead (`site-settings.php:243-246`) — an
Options Page field is a WP **setting**, so it surfaces at `wp/v2/settings`, not on any
post-type route. It also wires `rest_pre_get_setting`/`rest_pre_update_setting` filters
(`:33-34,38-99`) to route reads/writes through JetEngine's own options storage
(`jet_engine()->listings->data->get_option()` / `$this->page->update_options()`) instead
of `wp_options` directly, since JetEngine Options Pages can be stored "separate" (own
option row per field, see `jetengine-modules`) rather than as one combined option.

### A4. CCTs need their own REST controller — they are not real WP posts

Custom Content Type items live in their own DB table (`{prefix}jet_cct_{slug}`, see
`jetengine-cct-internals`), so there is no `wp/v2/{cct}` route WP core could ever
generate — JetEngine ships a **hand-rolled controller**,
`Jet_Engine\Modules\Custom_Content_Types\Rest\Public_Controller`
(`modules/custom-content-types/inc/rest-api/public-controller.php:6`), registered per-CCT
on `rest_api_init` only if at least one of four admin-configured flags is on
(`rest_get_enabled`/`rest_put_enabled`/`rest_post_enabled`/`rest_delete_enabled` —
`Factory::register_endpoints()`, `inc/factory.php:57,104-121`; **same flag names as the
CPT/Taxonomy admin-CRUD args**, reused across components, not CCT-specific). Routes,
under namespace `jet-cct`:

- `GET|POST /jet-cct/{slug}` — list / create (`public-controller.php:34-57,519-548`)
- `GET|PUT|DELETE /jet-cct/{slug}/{_ID}` — single item (`:42-47,59-75,574-618`)

`create_item()`/`update_item()` both funnel into `Item_Handler::update_item()`
(`:534,583` — the same write API documented in `jetengine-cct-internals`, confirming
there's no separate REST-only write path). Permission checks read a **per-context
capability from the CCT's own config**: `rest_get_access`/`rest_put_access`/
`rest_post_access`/`rest_delete_access`, where the stored value `'public'` (or falsy)
means "no check" and anything else is passed straight to `current_user_can()`
(`check_user_permissions()`, `:497-513`) — a CCT with `rest_get_access: 'public'` is
genuinely open to unauthenticated `GET` requests.

**Query args are a JetEngine-specific mini-language, not WP_Query-shaped**: `_limit`,
`_offset`, `_orderby`/`_order`/`_ordertype`, `_cct_search`/`_cct_search_by`, and
`_filters` (a JSON-encoded array of `{field,value,operator}` rows, same row shape Query
Builder's CCT query type uses internally — see `jetengine-query-builder`), plus **any
plain query param matching a real field name becomes an implicit equality filter**
(`get_items()`, `:241-447`, the "plain param → equality filter" fallback at `:380-391`).
Checkbox-type fields get special LIKE-based matching since they're stored serialized
(`:283-327`) — don't pass a checkbox field's raw value expecting exact-match semantics.
Response carries item totals as **headers**, not body fields: `Jet-Query-Total` /
`Jet-Query-Pages` (`:440-445`), only present when `_limit` was set.

Two more real extension points confirmed in source: `jet-engine/custom-content-types/rest-api/{slug}/get-items/{query,limit,offset,order}`
filters reshape the query before it runs (`:403-433`), and
`jet-engine/custom-content-types/rest-api/filters/{slug}` (a filter returning a
`field => callback` map, `filter_data()`, `:450-495`) reformats individual field values
in the response — the source comment points at a real customer example
(`gist.github.com/MjHead/f3e883c19cd0f754761719b5101a1f62`) for the intended shape.

### A5. Relations get their own public controller too — parent/child link data, optionally with meta

`Jet_Engine\Relations\Rest\Public_Controller` (`components/relations/rest-api/public-controller.php:6`),
namespace `jet-rel`, base path keyed by the relation's numeric id (`register_routes()`,
`:14-59`):

- `GET|PUT /jet-rel/{rel_id}` — bulk list (all parent→children groupings) / update a link
  (`:29-56`)
- `GET /jet-rel/{rel_id}/{context}/{_ID}` — single object's related items, where
  `{context}` is literally `children` or `parents` (`:38-45,57` — read via
  `$relation->get_children( $id, 'ids' )` / `get_parents( $id, 'ids' )`, `:220-230`)

`update_item()` (`:290-319`) doesn't touch the relation's row-CRUD directly — it delegates
to `Jet_Engine\Relations\Forms\Manager::instance()->update_related_items()` (the same
manager backing the "Connect Relation Items" JetFormBuilder action documented in
`jetengine-relations`), then separately calls `$relation->update_all_meta()` if a `meta`
param was sent. Like CCT's controller, whether a relation's REST routes exist at all and
whether `GET`/`PUT` require a capability both come from **relation-level config**
(`rest_get_access`/`rest_post_access` args, `check_user_permissions()`, `:184-200`) — not
a site-wide toggle.

### A6. Query Builder: give any configured query its own public REST route

A fourth, easy-to-miss data-exposure path, entirely separate from A4/A5: **any** Query
Builder query (SQL, Posts, CCT, whatever — see `jetengine-query-builder`) can be given a
standalone REST route via `Jet_Engine\Query_Builder\Rest\Query_Endpoint`
(`components/query-builder/rest-api/query-endpoint.php:6`), configured per-query in the
admin (namespace/path/access settings). `callback()` (`:169-207`) resolves the query with
`Manager::instance()->get_query_by_id_for_context( $query_id, [ 'type' => 'rest_endpoint',
... ] )` — **the same accessor documented in `jetengine-query-builder`**, just called with
a context array tagging the request as coming from this endpoint — then returns
`$query->get_items()` as the response body, `Jet-Query-Total`/`Jet-Query-Pages` as
headers (same header convention as A4's CCT controller). Access control is **not** the
`current_user_can()`-only pattern of A4/A5: it supports `public` (open), `role`
(any/specific logged-in role), or `cap` (any/specific capability), each independently
configurable per query (`permission_callback()`/`check_access_by_role()`/
`check_access_by_capability()`, `:80-161`), gated additionally by the filterable
`jet-engine/query-builder/query-rest-api/has-access` (`:97`).

## Direction B: consuming a third-party REST API — the "Rest API Listings" module

Module slug `rest-api-listings`, display name **"Rest API Listings"**
(`Jet_Engine_Module_Rest_Api_Listings::module_id()`/`module_name()`,
`modules/rest-api-listings/rest-api-listings.php:25-36`). Bootstraps on `jet-engine/init`
→ `Jet_Engine\Modules\Rest_API_Listings\Module::instance()` (`rest-api-listings.php:76-87`),
whose own `init` (priority `-1`, `inc/module.php:60-91`) wires up six sub-objects:
`Data` (endpoint storage), `Request` (the actual HTTP fetch/cache layer), `Settings`
(admin UI + endpoint CRUD), `Listings\Manager` (Listing Grid "REST API Endpoint" source),
`Auth_Types\Manager` (pluggable auth), `Forms` (legacy JetEngine-Forms notification) —
plus, conditionally, `Query_Builder\Manager::instance()` and `new Action_Manager()`
(the JetFormBuilder action).

### Endpoint storage — a real gotcha: not a dedicated table

`Data` (`inc/data.php:7`) extends `Jet_Engine_Base_Data` with `$table = 'post_types'`
(`:14`) and a permanent `$query_args = [ 'status' => 'rest-api-endpoint' ]` filter
(`:21-23`). **Configured REST API endpoints are stored as rows in the same custom table
JetEngine uses for its own CPT definitions, distinguished only by a special `status`
value** — there is no separate "REST endpoints" table. If you're ever inspecting that
table directly (e.g. debugging via `$wpdb`), don't assume every row is a real post type;
filter or check `status` first. `sanitize_item_from_request()` (`:64-129`) is where the
endpoint config shape is defined: `url`, `auth_type`, `items_path` (default `/`),
`cache`/`cache_period`/`cache_value`, `fetched_fields`, `sample_item` — the last two are
populated by the admin UI's "Send Request" sample-fetch (`Settings::save_endpoint()`,
`inc/settings.php:76-108`), not something you set by hand.

`Settings::get( $endpoint_id = false )` (`inc/settings.php:466-500`) is the read accessor
— `false` (default) returns every configured endpoint, an id returns one or `false` if
not found; it lazily loads+caches via `Module::instance()->data->get_item_for_register()`
on first call (`:468-484`).

### Fetching: `Request` class — caching, item-path drilling, macro-substituted URL

`Request::set_endpoint( $endpoint )` (`inc/request.php:13-22`) builds the target URL by
running it through **both** `jet_engine()->listings->macros->do_macros()` (JetEngine's
own `%macro%` engine, see `jetengine-listings-macros`) **and** `do_shortcode()`
(`:19`) — so a listing/query-arg field can reference a JetEngine macro or a WP shortcode
inside the endpoint URL. `get_items( $query_args, $force = false )` (`:100-144`) is the
main entry point:

1. Unless `$force`, checks `get_cached_items()` — returns immediately on a cache hit.
2. `send_request()` (`:48-71`) fires `jet-engine/rest-api-listings/request/before-send`
   (action), applies `jet-engine/rest-api-listings/request/args` and `.../request/type`
   filters (this is where an auth type injects its header — see below), then
   `wp_remote_get()`/`wp_remote_post()` based on `$type`.
3. On `WP_Error` or non-`200` status, records the error (`get_error()`/`get_error_details()`)
   and returns `false` — **callers must check for `false`, not just falsy/empty**, since
   an endpoint returning a genuinely empty items array is a different, non-error case.
4. `recursive_find_items()` (`:227-268`) walks the decoded JSON body along the
   slash-delimited `items_path` (e.g. `/data/items` → `['data','items']`) to find the
   actual items array/object — `items_path: '/'` (the default) means "the whole body is
   the items list," no drilling. Returns `false` (→ a `WP_Error`-equivalent "items not
   found" state) if any path segment is missing.
5. On success, caches via `update_items_cache()` and returns the items.

**Caching is opt-in per endpoint** (`is_cached()`, `:146-156`, reads the endpoint's
`cache` flag) and keyed by `get_cache_transient()` (`:158-175`): `'jet_rest_' . $url .
md5($queryArgsString)` — **the raw endpoint URL is concatenated into the transient name
unhashed**, only the query-args portion is `md5()`'d. A very long endpoint URL therefore
risks exceeding WP core's ~172-char `option_name` column limit (transients are stored as
options), which WP would silently truncate — **not independently live-verified in this
round** (would need a URL long enough to actually trigger truncation), flagged here as a
plausible real risk from reading the code, not a confirmed bug; see "not yet automated"
below. Cache duration = `cache_value * (60 | HOUR_IN_SECONDS | DAY_IN_SECONDS)` depending
on `cache_period` (`update_items_cache()`, `:193-225`), `cache_value` defaulting to `1`
if unset/falsy.

### Auth types — pluggable, shared between the Listing source AND the form action

`Auth_Types\Manager` registers four built-ins (`Base`, `Bearer_Token`, `Application_Password`,
`Custom_Header`, `Rapidapi` — one file each under `inc/auth-types/`). Each hooks the same
filter `Request` calls during `send_request()`: `jet-engine/rest-api-listings/request/args`
(confirmed via `Bearer_Token::set_token()`, `auth-types/bearer-token.php:30,33-53`, which
checks `is_current_type_endpoint()` before injecting an `Authorization: Bearer …` header)
— meaning a custom auth type is just another callback on this one filter, gated on
whichever endpoint/settings shape it cares about; there's no separate registration
mechanism to learn beyond "add a filter."

### Listing Grid integration — a `REST_API_Query` Query Builder type + a listing source

Two separate registrations, both wiring into systems `jetengine-query-builder` and
`jetengine-listings-macros` already document:

- **`Query_Builder\Manager`** (`inc/query-builder/manager.php:11`) registers a new query
  type, `'rest-api'` → `REST_API_Query`, via
  `Query_Factory::register_query( 'rest-api', ... )` on the **exact same**
  `jet-engine/query-builder/queries/register` hook documented in `jetengine-query-builder`
  (`manager.php:54,74-82`) — no special-cased registration path.
  `REST_API_Query::_get_items()` (`inc/query-builder/query.php:15-45`) reads the
  configured endpoint id from `$this->final_query['endpoint']`, builds query args from
  `final_query['args']` rows (each `{field, value, exclude_empty}`, `value` run through
  JetEngine's macro engine — `get_query_args_from_query()`, `:52-75`), then calls
  `Module::instance()->request->set_endpoint($endpoint)->get_items($query_args)`. Every
  returned item gets **synthetic identity properties** stamped on
  (`_get_items()`, `:38-41`): `$item->is_rest_api_endpoint = true` and
  `$item->_rest_api_item_id = "{queryId}-{arrayIndex}"` — since a REST API item has no
  real WP object ID, this composite string is what stands in for one everywhere a
  listing/macro needs "the current item's id."
- **`Listings\Manager`** (`inc/listings/manager.php:11`, `$source = 'rest_api_endpoint'`)
  registers "REST API Endpoint" as a selectable Listing Grid source
  (`jet-engine/templates/listing-sources` filter, `:324-327`), adds an endpoint-picker
  dropdown to the "Add Listing" popup (`jet-engine/templates/listing-options` action,
  `register_listing_popup_options()`, `:330-355`), and on creation stamps the chosen
  endpoint id into both `_listing_data.post_type` and the Elementor-specific
  `_elementor_page_settings.{listing_post_type,rest_api_endpoint}` postmeta
  (`modify_inject_listing_settings()`, `:363-385`) — so a REST-sourced listing's "post
  type" meta value is actually an endpoint id, not a real post type slug. Dynamic
  field/image/link macros for a REST-sourced item are accessed through a
  **`rest_api__` prefix** on the field key (`get_meta()`, `:130-141`; field list built by
  `add_source_fields_for_js()`, `:152-194`) — the same "prefix disambiguates which source
  a field key belongs to" pattern other non-post listing sources use.

### "REST API Request" — two independent form-notification integrations, not one

The module wires the **same** `Request`/auth-type machinery into REST-sending from a form
submission in **two unrelated places**, gated differently:

- **`Forms`** (`inc/forms.php:12`) — hooks into JetEngine's own legacy Forms/Booking
  notification system (`jet-engine/forms/booking/notification-types` /
  `.../notification/{slug}` filters, `:23-37`), slug `rest_api_request`, always active
  (no version gate).
- **`Jet_Action`** (`inc/jet-action.php:11`) — a real **JetFormBuilder** action class
  (`extends \Jet_Form_Builder\Actions\Types\Base`, see `jetformbuilder-actions`), id
  `rest_api_request`, name "REST API Request" — but only registered by `Action_Manager`
  (`inc/action-manager.php:6,22-26,43-46`) **if `jet_form_builder()` exists AND its
  version is `>= 1.2.3`** — a genuinely optional integration, not always present just
  because the module is active.

Both share request-body/URL templating via `Forms::prepare_body()`/`prepate_url()`
(`inc/forms.php:130-203`) — **note the real method name is `prepate_url`, not
`prepare_url`** (typo baked into the shipped source, used consistently at both call sites,
`inc/forms.php:177` and `inc/jet-action.php:112` — don't "fix" it when calling, it's the
actual API). Both use the macro-token regex `/%(.*?)(\|([a-zA-Z0-9\(\)_-]+))?%/`
(`get_macros_regex()`, `:120-122`) — a `%field_name%` or `%field_name|filter_name%` token
against the submitted form data, **not** JetEngine's listing `%macro%` engine (different
regex, different data source: raw submitted field values, optionally piped through
`jet_engine()->listings->filters->apply_filters()` if a pipe-suffix is present). Both
raise/log an error on `WP_Error` or HTTP status `>= 400` (`jet-action.php:126-138`,
`forms.php:238-250`) — the JetFormBuilder version throws a real `Action_Exception`
(stops the action chain), the legacy Forms version just logs and returns `false`.

## Gotchas

- **`jet-engine/v2` is the admin-CRUD namespace, not `v1`** — every other JetEngine REST
  surface in this repo (MCP Tools) uses `v1`; don't assume a shared version.
- **Meta Box / Options Page REST exposure is `register_meta()`/`register_setting()` with
  `show_in_rest`, never `register_rest_field()`** — searching the plugin for
  `register_rest_field` finds nothing; don't guess that API when extending a field's REST
  shape, use the `jet-engine/meta-boxes/rest-api/fields/{field-type,schema}` filters
  instead (A2 above).
- **CCT and Relations public data live under their own namespaces (`jet-cct`, `jet-rel`),
  each individually gated per-CCT/per-relation** by `rest_*_enabled`/`rest_*_access`
  config — a CCT or relation with REST disabled simply has no registered route at all
  (404, not 403).
- **Rest API Listings endpoints are rows in the shared `post_types` custom table**, keyed
  off `status = 'rest-api-endpoint'` — not a dedicated endpoints table.
- **`REST_API_Query` items carry no real WP object id** — always a synthetic
  `_rest_api_item_id` string (`"{queryId}-{index}"`), not a database-backed integer;
  don't try to `get_post()`/DB-lookup it.
- **The JetFormBuilder "REST API Request" action only exists if JetFormBuilder is active
  and >= 1.2.3** — the legacy Forms/Booking notification of the same name has no such
  gate. Two independently-wired integrations sharing one name and one underlying
  `Request` class.
- **`prepate_url()` is the real, misspelled method name** in both integrations — copy it
  exactly if calling directly.
- **Caching is opt-in per endpoint and keyed partly on the raw, unhashed URL** — a very
  long endpoint URL is a plausible (not live-confirmed) transient-name-truncation risk;
  see "not yet automated."

## How this was verified

Read every file under `includes/components/{post-types,taxonomies,meta-boxes,
options-pages,query-builder,relations}/rest-api/`, `includes/modules/custom-content-types/
inc/rest-api/`, `includes/base/base-api-endpoint.php`, `includes/rest-api/manager.php`,
and the full `includes/modules/rest-api-listings/` tree (module bootstrap, `Data`,
`Request`, `Settings`, `Auth_Types\*`, `Listings\Manager`/`Query`, `Query_Builder\Manager`/
`REST_API_Query`, `Action_Manager`, `Jet_Action`, `Forms`) in JetEngine 3.8.12 source —
every claim above cites the specific file:line it came from. Cross-checked terminology
against Crocoblock's public `developer-documentation` GitHub repo, which confirmed the
module's two knowledge-base article titles quoted in `get_module_links()`
(`rest-api-listings.php:57-68`) but added no facts beyond what direct source-reading
already found.

**Live-verified 2026-07-17** against `jackfruit.epeak.studio` (JetEngine active,
`rest-api-listings` module activated for this task via `tool-manage-modules`): runnable
suite `tests.php` (11 assertions, `rapi-1` through `rapi-11`), deployed as Code Snippets
snippet id 77 ("AGENT-TEST-SUITE: jetengine-rest-api") — **11/11 passing** after fixing
two test-only bugs on first run (`rapi-6`/`rapi-7`, both missing an explicit `require` /
calling a hook-registered method that had no chance to re-fire mid-request — see
`TEST-REGIMEN.md`'s Run log; no plugin/doc bugs found). Tests drive real WP REST routes
in-process via `rest_do_request()`/`WP_REST_Server` (per this repo's established "prefer
in-process REST calls over fragile live object construction" lesson — see
`jetblog-query-pipeline/TEST-REGIMEN.md`) rather than a real outbound HTTP call to a
third-party API, since this repo's convention is to avoid live network dependencies in an
automated suite (see "Not yet automated" in `TEST-REGIMEN.md`).
