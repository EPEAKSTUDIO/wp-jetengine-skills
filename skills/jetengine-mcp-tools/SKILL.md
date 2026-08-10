---
name: jetengine-mcp-tools
description: Use when a JetEngine site exposes MCP tools (tool-add-cct, tool-add-cpt, tool-add-taxonomy, tool-add-meta-box, tool-add-query, tool-add-listing, tool-add-glossary, tool-manage-modules) or the equivalent REST routes under jet-engine/v1/mcp-tools/ — e.g. deciding whether to call a tool or write raw PHP, predicting what a tool actually creates, or debugging why a created CCT/CPT/query doesn't look like you expected. Captures real, verified behavior of JetEngine's built-in MCP Tools feature (Jet_Engine\MCP_Tools\*), confirmed by reading source and by calling tool-add-cct/tool-add-query live and inspecting the resulting DB table.
license: MIT
metadata:
  author: project
  version: "0.3.0"
---

# JetEngine MCP Tools

**Live-verified (2026-07-16):** this skill now has a runnable suite (`tests.php`, 4
tests, `mcp-1` through `mcp-4`) per `docs/test-harness-guide.md` — 4/4 pass on first
live run. One small addendum found (not a correction): the stored `order`/`args` rows
documented below also carry `_id`/`collapsed`/`type` keys not previously mentioned — see
the "verified live" note under `tool-add-query` and `TEST-REGIMEN.md`.

JetEngine ships its own first-class "Features API" / MCP Tools layer
(`includes/core/mcp-tools/` plus per-component `*/mcp/tool-add-*.php` files) — this is
**not** a wrapper someone bolted on, it's shipped inside the `jet-engine` plugin itself
and calls the exact same internal CRUD the wp-admin screens use. If a site's MCP server
exposes tools named `tool-add-cct`, `tool-add-cpt`, etc., this skill documents what they
really do, so you can decide whether to call them or write raw PHP against
`jetengine-cct-internals`/`jetengine-relations` instead.

## Architecture (verified from `includes/core/mcp-tools/`)

- `Jet_Engine\MCP_Tools\Registry` is a singleton. It only initializes (`add_action(
  'plugins_loaded', ...)` / `rest_api_init`) if `is_features_api_enabled()` returns true —
  which reads the **raw option** `jet-engine-misc-settings['enable_features_api']`
  directly (not via `jet_engine()->misc_settings`, since that object doesn't exist yet at
  this point in the load order). Default is `true` via `ensure_settings()`. A site owner
  can disable the whole layer by setting this option false — **if a tool call fails with
  no matching route at all, check this setting before assuming a tool name is wrong.**
- Each tool is a `Feature` (`feature.php`) with `id`, `type` (`'tool'`), `input_schema`,
  `output_schema`, `execute_callback`, optional `permission_callback`. **The externally
  visible tool name is always `{type}-{id}`** — e.g. id `add-cct` + type `tool` →
  `tool-add-cct`. This is the *only* naming convention that's actually correct (see
  gotcha below about the `next_tool` field lying about this).
- **The `resource-*` features are MCP *tools*, not MCP resources.** `resources/list`
  returns `-32601 Method not found` — the server doesn't implement the resources
  capability at all. `tools/list` returns all 11 features including
  `resource-get-configuration`, `resource-get-website-config` and `resource-get-macros`,
  and you invoke them through `tools/call` like any other. The `{type}-{id}` naming rule
  above holds; `type` just isn't `tool` for those three. `initialize` advertises exactly
  `"capabilities":{"tools":{"listChanged":false}}` and identifies as
  `"serverInfo":{"name":"Crocoblock Client MCP Server","version":"1.0.0"}`. It answers
  `"protocolVersion":"2025-03-26"` regardless of the version the client offers (verified
  by sending `2024-11-05` and getting `2025-03-26` back). All live-verified 2026-08-10 on
  JetEngine 3.8.13.
- Two REST surfaces exist side by side: `jet-engine/v1/mcp-tools/run/{name}` (a plain
  nonce-gated REST route, `Run_Controller`, requires header `X-WP-Nonce`) and
  `jet-engine/v1/mcp/` (the real streamable-HTTP MCP protocol endpoint,
  `MCP_Controller`, gated separately by `enable_mcp_server`, also default `true`). An
  external agent (Claude, etc.) talks to the latter; the former is what the admin JS
  dashboard itself uses.
- **Permission default is `current_user_can( 'manage_options' )`** (`Feature::check_permissions()`)
  unless a tool supplies its own `permission_callback` — none of the core tools do. Every
  tool here requires an admin-capable user token; there is no reduced-privilege mode.
- **Authentication is whatever WordPress says it is.** JetEngine never reads the
  `Authorization` header itself — it calls `current_user_can()` against the user WordPress
  already resolved through the `determine_current_user` filter. So Basic auth via core
  Application Passwords and `Bearer <jwt>` from a JWT plugin (AAM, JWT Authentication for
  WP REST API, miniOrange) are equally valid; JetEngine can't tell them apart. **Bearer/JWT
  live-verified 2026-08-10** against `jackfruit.epeak.studio` — `initialize`, `tools/list`,
  and a real `tools/call` (`resource-get-website-config`, which actually runs
  `check_permissions()`) all returned 200, and the same JWT authenticated core's
  `wp/v2/plugins` too, confirming it's ordinary WordPress user resolution and not an
  MCP-specific path. Basic is what Crocoblock's own VS Code docs show. Two consequences
  worth knowing: JWTs expire, so a token pasted into a client config starts 401ing later
  with no visible cause, and the `manage_options` requirement above still bites — a
  per-user JWT scoped to a limited role authenticates cleanly and then 403s on every tool.
  With no `Authorization` header at all the endpoint returns **401 `rest_forbidden`**
  ("You cannot access this resource."), not 403.
- **A 401 with credentials you know are correct is usually the host, not the token.** Many
  Apache/CGI and nginx+PHP-FPM stacks strip `Authorization` before PHP sees it, which
  breaks Basic and Bearer identically. `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`
  (Apache) or `fastcgi_param HTTP_AUTHORIZATION $http_authorization;` (nginx) is the fix.
  See `docs/mcp-setup.md`.
- Core tools are hardcoded in `Registry::load_core_features()`: `add-cct`,
  `get-configuration`, `get-website-config`, `get-macros`, `add-glossary`,
  `manage-modules`. The **rest** (`add-cpt`, `add-taxonomy`, `add-meta-box`, `add-query`,
  `add-listing`) are registered by their own component (`includes/components/{post-types,
  taxonomies,meta-boxes,query-builder,listings}/mcp/tool-add-*.php`) via
  `do_action( 'jet-engine/mcp-tools/register-features', $registry )` inside
  `Registry::get_features()` — i.e. lazily, on first access, not at `load()` time.

## Every "add" tool funnels into the same CRUD the wp-admin UI uses

`tool-add-cct`, `tool-add-cpt`, `tool-add-taxonomy`, and `tool-add-meta-box` all end with
the identical pattern: `$component->data->set_request( [...] )` then
`$component->data->create_item( false )`. This is **the same internal `Data` class
methods** the JetEngine admin screens themselves call when a human clicks "Add New" and
saves — confirmed live (see "How this was verified"). Practical consequence: once
created, an MCP-made CCT/CPT/taxonomy is byte-for-byte indistinguishable from one built
by hand — everything in `jetengine-cct-internals` and `jetengine-relations` (table
naming `{prefix}jet_cct_{slug}`, `_ID` primary key, Relations API behavior, etc.)
continues to apply unchanged. There is no separate "MCP-created" code path to worry
about downstream.

## `tool-add-cct` — verified live

- **Silently auto-activates the `custom-content-types` module** if it isn't active yet
  (`if ( ! jet_engine()->modules->is_module_active( 'custom-content-types' ) ) {
  jet_engine()->modules->activate_module(...) }`) — no confirmation, no flag to opt out.
- Field type enum is **narrower than the CPT tool's**: `text, number, date, datetime,
  media, checkbox, select, radio, textarea` — no `repeater`, no `time`/`datetime-local`.
  If a task needs a repeater field on a CCT, this tool can't do it; fall back to
  `tool-add-meta-box`-style raw field arrays or the admin UI.
- **Verified DB column types** created for a CCT via this tool (live-checked with
  `DESCRIBE` on `{prefix}jet_cct_{slug}`, JetEngine on `jackfruit.epeak.studio`):
  - `_ID` — `bigint(20)`, `PRI`, `auto_increment` (matches `jetengine-cct-internals`).
  - a `media` field → **`bigint(20)`** column (stores the attachment ID as a real int,
    regardless of `value_format` — `value_format` only affects what the *read* API
    returns, not the column type).
  - `text`, `select`, `checkbox`, `radio`, `textarea` fields → **`text`** columns.
  - `date`/`datetime` fields → **`datetime`** columns (the tool forces
    `save_as_timestamp`-style storage regardless of the `save_as_timestamp` input flag
    in this code path — not independently confirmed for the raw admin-UI path).
  - Built-in bookkeeping columns always present regardless of your fields:
    `cct_status`, `cct_author_id`, `cct_created`, `cct_modified` (all nullable). A
    `cct_single_post_id` entry appears in `admin_columns` config but is **not** a real
    table column — admin-column config and table schema are separate things, don't
    assume every `admin_columns` key names a real column.
- `select`/`checkbox`/`radio` options are stored as newline-joined `"value::label"`
  strings under `bulk_options` with `options_source: 'manual_bulk'` — if you're reading
  the CCT config back via `resource-get-configuration`, that's the shape to expect, not
  a structured options array.
- Response includes `next_tool` — **do not trust this field's exact string**, see
  gotcha below.

## `tool-add-cpt` / `tool-add-taxonomy` / `tool-add-meta-box`

- Same shape of tool as `add-cct` but for Custom Post Types, Taxonomies, and Meta Boxes.
  `meta_fields` accepts either a "simplified" descriptor (`name`/`type`/`options`) or a
  raw JetEngine field object (detected via `object_type === 'field'`) — both tools
  normalize simplified descriptors into the same internal field array shape
  (`prepare_meta_fields()`), including recursive handling for `repeater` (CPT/meta-box
  only, not CCT).
- `tool-add-cpt`: `general_settings.slug` and `.name` are the only required inputs (slug
  auto-derived from name via `sanitize_title()` if omitted); ~20 boolean/`advanced_settings`
  flags default to sane WordPress CPT defaults (`public: true`, `show_in_rest: true`,
  `has_archive: true`, etc.) if not supplied.
- `tool-add-taxonomy`: **requires `general_settings.object_type`** (at least one post
  type slug) — fails with `invalid_input` if omitted, unlike the CPT/CCT tools which
  only strictly require a name/slug.
- `tool-add-meta-box`: `general_settings.object_type` must be one of the values reported
  by `jet_engine()->meta_boxes->get_sources()` at call time (falls back to `post`,
  `taxonomy`, `user` if that call fails) — **this list is dynamic per-site**, not a fixed
  enum; a site with extra registered meta box object types (e.g. via an add-on) may
  accept more values than the three defaults. `active_conditions` (visibility gating)
  is auto-populated from whichever condition-specific settings you actually pass
  (`allowed_posts` → adds `'allowed_posts'` to `active_conditions` automatically) — you
  don't need to separately list conditions you're already configuring.

## `tool-add-query` (Query Builder) — verified live

- `query_type` is **not a fixed list** — it comes from
  `Jet_Engine\Query_Builder\Query_Factory::get_query_types()` at call time, so the valid
  enum is whatever query types are registered on that specific site (core types seen:
  `sql, posts, terms, users, comments, repeater, current-wp-query, merged-query,
  relations-query, jet-form-builder-query, custom-content-type`).
- **The tool converts your WP_Query-style `query_args` into a completely different
  internal shape per query type** — it does not store what you sent. Verified live for
  `query_type: "custom-content-type"`: sending
  `{"post_type":"cct-slug/name","meta_query":[{"key":"status","value":"published"}],
  "posts_per_page":10,"orderby":"date","order":"DESC"}` produced a stored query whose
  real internal args were:
  ```json
  {
    "content_type": "agent_test_cct/name",
    "number": "10",
    "order": [ { "orderby": "date", "order": "DESC" } ],
    "args": [ { "field": "status", "operator": "=", "value": "published" } ]
  }
  ```
  i.e. `posts_per_page` → `number`, `meta_query` rows → `args` rows with `field`/
  `operator`/`value` keys (not `key`/`value`/`compare`), `orderby`/`order` scalars →
  an `order` array of row objects. **If you're debugging "why doesn't my query_args
  input look like what's saved," this conversion is why — read back via
  `resource-get-configuration` to see the real stored shape, don't assume it round-trips.**
  **Addendum (2026-07-16, live-verified via `tests.php` mcp-4):** each `order`/`args` row
  also carries a generated `_id` (int), `collapsed` (bool, `false`), and `type` (empty
  string `""`) key beyond the ones shown above — editor-UI bookkeeping fields, not
  something you need to set yourself when building `query_args`, but expect them when
  reading a stored query back.
- The tool's own `date_warning` output field is a real, permanent hint (not
  conditional on whether you used a date) — it always fires, telling you dates should be
  stored/compared as timestamps. Treat it as a standing recommendation, not a signal
  that something about your specific call was wrong.
- Rejects with `invalid_input` if `query_args` is empty/missing — always required, even
  for query types where it feels optional.

## `tool-add-listing`

- **Requires an existing `query_id`** (from `tool-add-query`) — it does not create a
  query itself.
- To build the listing template content, it **actually executes the target query live**
  (`$query->setup_query(); $query->get_items();`) and inspects the first real result
  item to detect context type (`post`/`term`/`user`/`comment`/`cct`/`generic`) by duck
  typing (`WP_Post`, `WP_Term`, presence of `cct_slug`, etc.) — meaning calling this tool
  has the side effect of running the underlying query for real at creation time, not
  just reading its saved config.
- `view_type: "auto"` picks the **first available** builder integration in a fixed
  order — Elementor, then Bricks, then Timber/Twig, then the block-based "Performance"
  views — based on what's actually active on the site (`jet_engine()->has_elementor()`,
  etc.), not a fixed default. If nothing is active it returns a `no_view_available`
  error rather than silently defaulting to Elementor.
- Auto-populates the listing's dynamic-field widgets from the target's real meta fields
  (`jet_engine()->meta_boxes->get_meta_fields_for_object()` for posts/terms/users, or the
  CCT's field list for CCT queries) — a listing created this way is pre-populated with
  fields, not blank.

## Gotcha: the `next_tool` output field is not a reliable tool name

Every "add" tool returns a `next_tool` string suggesting what to call next — but the
literal values are **internally inconsistent and don't match the real tool-naming
convention** (`tool-{feature-id}`, e.g. `tool-add-query`). Verified live/by-source:

| Tool | `next_tool` value returned | Actual correct tool name |
|---|---|---|
| `tool-add-cct` | `"tool-crocoblock/add-query"` | `tool-add-query` |
| `tool-add-cpt` | `"tool-crocoblock/add-query"` | `tool-add-query` |
| `tool-add-taxonomy` | `"tool-crocoblock/add-query"` | `tool-add-query` |
| `tool-add-meta-box` | `"tool-crocoblock/add-listing"` | `tool-add-listing` |
| `tool-add-query` | `"tool/add-listing"` | `tool-add-listing` |

Three different, mutually inconsistent formats across five tools, none of which is the
real name. **Don't programmatically parse or dispatch on `next_tool` — use it only as a
human-readable hint about which capability to reach for next**, and call tools by their
real `tool-{id}` name.

## `tool-manage-modules` / `resource-get-configuration` / `resource-get-website-config` / `resource-get-macros`

- `tool-manage-modules` operations: `list` (default), `activate`, `deactivate` — takes a
  `modules` array of slugs for the latter two. `list` returns `active_modules` (full
  objects) plus `all_modules` (every installable module, active or not, each with
  HTML `description`).
- `resource-get-configuration`'s `parts` object is opt-in per section (`custom_content_types`,
  `post_types`, `taxonomies`, `meta_boxes`, `options_pages`, `queries`, `relations`,
  `glossaries`, `rest_api_endpoints`) — omitting a key or setting it `false` omits that
  section entirely rather than returning an empty array for it; pass only what you need.
- `resource-get-macros` returns the same macro registry documented in
  `jetengine-listings-macros`, but pre-resolved with arguments/usage examples for
  tool-calling — cross-check against that skill's regex/registry facts if a macro
  behaves unexpectedly rather than trusting this resource's examples blindly.

## How this was verified

Read `includes/core/mcp-tools/{registry,feature}.php`,
`includes/core/mcp-tools/features/{add-cct,manage-modules}.php`,
`includes/core/mcp-tools/rest-api/run-controller.php`, and the five component-level
`*/mcp/tool-add-*.php` files (post-types, taxonomies, meta-boxes, query-builder,
listings) in JetEngine source, confirming the Registry/Feature architecture, permission
model, and each tool's field-normalization logic by direct file citation. Then
live-verified against `jackfruit.epeak.studio` (JetEngine MCP server) in one session:
called `tool-add-cct` (created CCT id 15, slug `agent_test_cct`, fields `title`/`photo`/
`status`), confirmed its shape via `resource-get-configuration`, called `tool-add-query`
(query id 16, `query_type: custom-content-type`) against it, then added a temporary
Code Snippets REST probe (snippet id 19, `agent-test/v1/mcp-audit-cct`, since
deactivated but **kept, not deleted** — see repo convention in
`docs/code-snippets-rest-api.md`) that ran `DESCRIBE` on the resulting
`wp_jet_cct_agent_test_cct` table and re-executed query 16 live, confirming the exact
column types and the `query_args` → stored-args conversion documented above. See
`TEST-REGIMEN.md` for the full executed run and remaining untested tools
(`add-cpt`, `add-taxonomy`, `add-meta-box`, `add-listing`, `add-glossary` were verified
by source only, not exercised live in this pass).

**Authentication addendum (2026-08-10, v0.3.0):** live-verified over the wire against
`jackfruit.epeak.studio` (JetEngine 3.8.13) with an AAM-issued JWT — see the
`2026-08-10` run log in `TEST-REGIMEN.md` for the exact requests and responses. This was
run by hand with `curl`, **not** added to `tests.php`: the suite runs *inside* WordPress
via the Code Snippets harness, so it can't observe its own transport-layer auth. A
`mcp-5` case there would be tautological; the regimen entry is the artifact instead.

Two claims here remain source-derived rather than executed: that a JWT scoped below
`manage_options` gets a 403 (inferred from `Feature::check_permissions()`; not tested,
as it needs a second limited-role token), and that hosts stripping `Authorization` cause
the same failure for Basic and Bearer (general WordPress REST behavior, documented here
only because it presents as a JetEngine auth bug).
