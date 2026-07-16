# Test regimen: jetengine-mcp-tools

Validates claims in `SKILL.md`. Run against the sandbox site
(`jackfruit.epeak.studio`, JetEngine MCP server + Code Snippets REST API — see
`docs/code-snippets-rest-api.md` for the snippet-plugin gotchas, especially that
`DELETE` doesn't reliably work: deactivate probes instead of trying to delete them).

## Test 1: `tool-add-cct` creates the same table shape `jetengine-cct-internals` describes — PASS (executed)

**Claim being tested:** CCT table is `{prefix}jet_cct_{slug}`, PK is `_ID` (bigint,
auto_increment), media fields store as int columns, select/checkbox/radio store as text
with `bulk_options`.

**Setup:** Called `tool-add-cct` with `name: "AGENT TEST CCT (skills audit)"`,
`slug: "agent_test_cct"`, fields `title` (text, key field), `photo` (media,
`value_format: id`), `status` (select, options `draft`/`published`).

**Trigger:** Created Code Snippets probe (id 19, `agent-test/v1/mcp-audit-cct`,
`active: false` by default) that runs `DESCRIBE {prefix}jet_cct_agent_test_cct` and
`SHOW TABLES LIKE`. Activated snippet 19, hit the route, deactivated snippet 19 again.

**Expected observable:** Table exists; `_ID` bigint PRI auto_increment; `photo`
bigint(20); `title`/`status` text; `cct_status`/`cct_author_id`/`cct_created`/
`cct_modified` present and nullable.

**Actual result (2026-07-16):**
```json
{"table_name":"UntX2Uit_jet_cct_agent_test_cct","table_exists":true,
 "columns":[
   {"Field":"_ID","Type":"bigint(20)","Key":"PRI","Extra":"auto_increment"},
   {"Field":"cct_status","Type":"text"},
   {"Field":"title","Type":"text"},
   {"Field":"photo","Type":"bigint(20)"},
   {"Field":"status","Type":"text"},
   {"Field":"cct_author_id","Type":"bigint(20)"},
   {"Field":"cct_created","Type":"datetime"},
   {"Field":"cct_modified","Type":"datetime"}
 ]}
```

**Pass criteria:** matches expected observable exactly. **PASS.**

## Test 2: `tool-add-query` converts WP_Query-style `query_args` into a different stored shape — PASS (executed)

**Claim being tested:** for `query_type: "custom-content-type"`, the tool does not store
the input verbatim — `posts_per_page` becomes `number`, `meta_query` rows become `args`
rows with `field`/`operator`/`value` keys, and `orderby`/`order` become an `order` array.

**Setup:** Called `tool-add-query` with `name: "AGENT TEST Query - CCT test items"`,
`query_type: "custom-content-type"`, `query_args: {"post_type":
"agent_test_cct/name","meta_query":[{"key":"status","value":"published"}],
"posts_per_page":10,"orderby":"date","order":"DESC"}` → created query id 16.

**Trigger:** Extended snippet 19's probe to call
`\Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id(16)`, `setup_query()`,
and dump `$q->query` plus `get_query_type()`.

**Expected observable:** stored `content_type` = `agent_test_cct/name`, `number` = `10`,
`order` = array with one `{orderby: date, order: DESC}` row, `args` = array with one
`{field: status, operator: '=', value: published}` row.

**Actual result (2026-07-16):**
```json
{"query_type":"custom-content-type",
 "raw_query_args":{
   "content_type":"agent_test_cct\/name","number":"10",
   "order":[{"orderby":"date","order":"DESC"}],
   "args":[{"field":"status","operator":"=","value":"published"}]
 },
 "items_count":0,"first_item":null}
```

**Pass criteria:** matches expected observable exactly (items_count 0 is expected — no
rows were ever inserted into the test CCT, this run only validated the query's stored
shape, not result content). **PASS.**

## Test 3: `next_tool` values are inconsistent across tools — PASS (executed/observed directly)

**Claim being tested:** the `next_tool` field returned by different "add" tools uses
three different, mutually-inconsistent naming formats, none matching the real
`tool-{feature-id}` convention.

**Trigger:** Directly observed the live tool-call responses in this session.

**Actual result:** `tool-add-cct` → `"tool-crocoblock/add-query"`; `tool-add-query` →
`"tool/add-listing"`. Source-read confirms `tool-add-cpt`/`tool-add-taxonomy` also
return `"tool-crocoblock/add-query"` and `tool-add-meta-box` returns
`"tool-crocoblock/add-listing"` (not independently executed for those three, only
verified by reading their `callback()` return arrays — see `SKILL.md` "How this was
verified").

**Pass criteria:** at least one mismatch observed between a returned `next_tool` string
and the tool's real callable name. **PASS** — confirmed for `add-cct`/`add-query` live,
`add-cpt`/`add-taxonomy`/`add-meta-box` by direct source citation only.

## Test 4 (not yet run): `tool-add-cpt` / `tool-add-taxonomy` / `tool-add-meta-box` / `tool-add-listing` / `tool-add-glossary` live creation

**Claim being tested:** these tools funnel into the same `Data::create_item(false)`
pattern as `add-cct`, produce a real registered CPT/taxonomy/meta-box/listing/glossary,
and (for `add-listing`) actually execute the target query live during creation.

**Setup:** none yet — only verified by reading `callback()` source for each tool in this
pass (see file:line references in `SKILL.md`).

**Trigger (for a future session):** call each tool once with a clearly namespaced test
name (e.g. `AGENT-TEST CPT`, matching the `agent_test_cct` convention above), confirm via
`resource-get-configuration` that the entity appears, and for `tool-add-listing`
specifically confirm the query really is re-executed (e.g. by pointing it at a query with
a deliberately expensive/side-effecting SQL query type and checking whether that SQL
runs at listing-creation time, not just query-creation time).

**Expected observable:** `success: true`, `item_id` populated, entity visible in
`resource-get-configuration`.

**Pass criteria:** entity created with the same field/DB shape as a corresponding
manually-built one (cross-check against `jetengine-cct-internals`'s CPT/meta-box
facts if that skill is extended to cover them).

## Cleanup note

Snippet id 19 (`AGENT-TEST mcp-tools audit (CCT table + query verify)`) is left on
`jackfruit.epeak.studio`, **inactive**, per this repo's convention of keeping test
snippets rather than deleting them (`DELETE` on this plugin/site is unreliable — see
`docs/code-snippets-rest-api.md`). CCT id 15 (`agent_test_cct`) and Query id 16
(`AGENT TEST Query - CCT test items`) are also left in place as living, re-runnable
fixtures for Test 4 above — do not delete them without re-pointing this file's tests at
new ids.
