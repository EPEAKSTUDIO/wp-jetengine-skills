# Test regimen: jetengine-mcp-tools

Validates claims in `SKILL.md`. Run against the sandbox site
(`jackfruit.epeak.studio`, JetEngine MCP server + Code Snippets REST API — see
`docs/code-snippets-rest-api.md` for the snippet-plugin gotchas, especially that
`DELETE` doesn't reliably work: deactivate probes instead of trying to delete them).

## Run log — 2026-08-10: JWT bearer auth verified live (manual, not in `tests.php`)

Run by hand with `curl` against `https://jackfruit.epeak.studio/wp-json/jet-engine/v1/mcp/`
on **JetEngine 3.8.13**, using a short-lived AAM-issued JWT for user_id 1. Not folded into
`tests.php` on purpose: the suite executes inside WordPress via the Code Snippets harness,
by which point authentication has already happened — it cannot observe its own transport
auth, so a test there would prove nothing.

- **`initialize` with `Authorization: Bearer <jwt>`** → HTTP 200,
  `application/json; charset=UTF-8`. Response:
  `{"protocolVersion":"2025-03-26","capabilities":{"tools":{"listChanged":false}},`
  `"serverInfo":{"name":"Crocoblock Client MCP Server","version":"1.0.0"}}`.
  Note the client sent `protocolVersion: 2024-11-05` and the server answered `2025-03-26`
  anyway. **PASS.**
- **Same request with no `Accept` header** → identical 200 and identical body. The
  `Accept: application/json, text/event-stream` header that the streamable-HTTP transport
  normally expects is **not** required by this endpoint; it always replies plain JSON.
  Worth knowing before blaming a missing header for a failed handshake. **PASS.**
- **Same request with no `Authorization` header** (control) → HTTP **401**
  `{"code":"rest_forbidden","message":"You cannot access this resource."}`. Confirms the
  200s above are attributable to the token. **PASS.**
- **`tools/list`** → 11 tools: `tool-add-cct`, `tool-add-cpt`, `tool-add-taxonomy`,
  `tool-add-meta-box`, `tool-add-query`, `tool-add-listing`, `tool-add-glossary`,
  `tool-manage-modules`, `resource-get-configuration`, `resource-get-website-config`,
  `resource-get-macros`. **PASS**, and confirms the `{type}-{id}` rule (mcp-1) over the
  wire rather than just against the `Registry` object.
- **`resources/list`** → `-32601 Method not found`. The three `resource-*` features are
  exposed as MCP *tools*; the server implements no resources capability. **New fact**,
  added to `SKILL.md`.
- **`tools/call` → `resource-get-website-config`** → HTTP 200 with a real post-types
  payload. This is the one that matters for the auth claim: it actually executes
  `Feature::check_permissions()` → `current_user_can( 'manage_options' )`, so a Bearer
  token demonstrably satisfies the capability check, not just the route. **PASS.**
- **Cross-check:** the same JWT authenticated core's `GET /wp-json/wp/v2/plugins`
  (returned the full plugin list, JetEngine 3.8.13). Confirms this is ordinary WordPress
  `determine_current_user` resolution and not an MCP-specific auth path. **PASS.**

Read-only throughout — no `tool-add-*` called, no artifacts created.

**Not covered:** a JWT scoped below `manage_options` returning 403 (needs a second
limited-role token). Still source-derived only.

## Run log — 2026-07-16: runnable suite added, 4/4 pass

Added `tests.php` (per `docs/test-harness-guide.md`), deployed as Code Snippets snippet
id 31, run via `GET /agent-test/v1/suite/jetengine-mcp-tools`. Deliberately does NOT call
any `tool-add-*` live (that would create a new entity every run) — instead re-inspects
the `Registry`/`Feature` objects directly (read-only) and re-checks the existing fixtures
from the original live pass (CCT id 15, query id 16), so this suite is safe to re-run
indefinitely.

- **mcp-1** (naming convention: `Registry::get_feature('tool-add-cct')` resolves and its
  `get_name()`/`get_type()`/`get_id()` match the `{type}-{id}` convention): PASS.
- **mcp-2** (`is_features_api_enabled()` reads the raw option directly): PASS.
- **mcp-3** (re-check of the `agent_test_cct` table's column shape — supersedes the
  original Test 1, below, kept only as history): PASS, exact same shape as originally
  observed.
- **mcp-4** (re-check of query id 16's stored args — supersedes the original Test 2,
  below, kept only as history): PASS — **found one addendum**: the stored `order`/`args`
  rows also carry `_id`/`collapsed`/`type` keys not previously documented in `SKILL.md`.
  Added as a non-breaking addendum (the previously documented keys were all still
  present and correct).

Test 3 (`next_tool` inconsistency) and Test 4 (live creation of the remaining `tool-add-*`
tools) are not covered by `tests.php` — see below for why.

## Test 3: `next_tool` values are inconsistent across tools

**Claim being tested:** the `next_tool` field returned by different "add" tools uses
three different, mutually-inconsistent naming formats, none matching the real
`tool-{feature-id}` convention.

**Result (2026-07-16, executed once, not re-automated):** `tool-add-cct` →
`"tool-crocoblock/add-query"`; `tool-add-query` → `"tool/add-listing"`. Source-read
confirms `tool-add-cpt`/`tool-add-taxonomy` also return `"tool-crocoblock/add-query"` and
`tool-add-meta-box` returns `"tool-crocoblock/add-listing"` (not independently executed
for those three). **PASS** — at least one mismatch observed live.

Not folded into `tests.php`: this is a claim about the actual MCP tool-call response
shape, not something re-derivable from the `Feature` object's current state without
calling the tool again (which would create a new entity every run).

## Test 4 (not yet run): `tool-add-cpt` / `tool-add-taxonomy` / `tool-add-meta-box` / `tool-add-listing` / `tool-add-glossary` live creation

**Claim being tested:** these tools funnel into the same `Data::create_item(false)`
pattern as `add-cct`, produce a real registered CPT/taxonomy/meta-box/listing/glossary,
and (for `add-listing`) actually execute the target query live during creation.

**Setup:** call each tool once with a clearly namespaced test name (e.g. `AGENT-TEST
CPT`), confirm via `resource-get-configuration` that the entity appears; for
`tool-add-listing` specifically, confirm the query really is re-executed at creation
time (not just query-creation time).

**Pass criteria:** entity created with the same field/DB shape as a corresponding
manually-built one. Deliberately left as a manual one-off task rather than part of the
repeatable `tests.php` suite, since each call creates a new, permanent entity — running
it every suite invocation would accumulate garbage.

## Cleanup note

Snippet id 19 (`AGENT-TEST mcp-tools audit`) is left on `jackfruit.epeak.studio`,
inactive, per this repo's keep-don't-delete convention. CCT id 15 (`agent_test_cct`) and
Query id 16 (`AGENT TEST Query - CCT test items`) are living, re-runnable fixtures for
Test 4 above — do not delete them without re-pointing `tests.php` at new ids.
