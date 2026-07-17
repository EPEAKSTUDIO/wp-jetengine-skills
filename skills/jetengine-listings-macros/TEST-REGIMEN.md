# Test regimen: jetengine-listings-macros

Validates claims in `SKILL.md`. Run against the sandbox site (JetEngine 3.8.12).

## Run log — 2026-07-16: runnable suite added, 5/5 pass, no listing fixture needed

The original blocker (no listing grid on the sandbox, `tool-add-cct` fataling on an
earlier, different site) turned out to be avoidable entirely:
`jet_engine()->listings->macros->handler` is a real `\Crocoblock\Macros_Handler`
instance reachable directly from any PHP context, and its public
`register_macros()`/`do_macros()` methods can be called straight from a REST callback —
no listing render, no page-builder, no browser needed. Added `tests.php`, deployed as
Code Snippets snippet id 32, run via
`GET /agent-test/v1/suite/jetengine-listings-macros`.

- **macros-1** (plain `%macro%` resolves via `do_macros()`): PASS — covers the plain-tag
  part of the original Test 1 (below, trimmed).
- **macros-2** (pipe args are DROPPED if the macro class declares no `macros_args()`):
  PASS — **a genuine finding**, not previously stated in `SKILL.md`. Now documented as a
  callout under "Registering a custom macro".
- **macros-3** (pipe args map positionally when `macros_args()` IS declared): PASS —
  confirms the exact positional-mapping mechanism, resolving the "confirm exact
  arg-passing shape" open question from the original Test 1.
- **macros-4** (uppercase tag is a regex-level miss, callback never invoked): PASS —
  supersedes the original Test 2 (below, trimmed).
- **macros-5** (unregistered-but-valid tag is a registry-level miss, same literal output):
  PASS — supersedes the original Test 3 (below, trimmed).

## Run log — 2026-07-16 (addendum): subclassing a built-in macro added, 6/6 pass

New `SKILL.md` section "Subclassing a built-in macro instead of the abstract base",
sourced from a real Codelab snippet (extending the shipped `%query_results%` macro to
keep falsy/empty-valued items its default `array_filter()` drops). **macros-6** defines
a real subclass of `\Jet_Engine\Query_Builder\Macros\Query_Results_Macro` (not
`Jet_Engine_Base_Macros` directly), registers it, and confirms it resolves through
`do_macros()` the same way as any other macro — PASS, and cross-confirms
`Manager::instance()->get_query_by_id()` as a working Query Builder accessor from a
third independent call site (previously corroborated in `jetengine-query-builder` and
the Codelab snippet itself).

Still not automated (both genuinely need a live listing render/page builder, not doable
via REST alone): Test 4 (context resolves to the current grid item), Test 5 (CCT rows
resolve via `cct_slug` duck-typing), Test 6 (Elementor/Bricks widget parity), and the
JSON-config-block variant of macro syntax (`%macro%{"fallback":"..."}`) — `tests.php`
only exercises the plain and pipe-arg forms, not the trailing JSON-config block.

## Run log — 2026-07-16 (second addendum): custom listing-context registration added, 7/7 pass

New `SKILL.md` section "Registering a custom context — extending get_object_by_context()
itself", sourced from real Codelab/Gist snippets found auditing Crocoblock's public
GitHub Gists account (305 gists; see repo `HANDOFF.md`) — several independent snippets
register custom contexts (`post_parent`, `post_featured_image`, "object previous in
stack") through this exact two-filter pairing. **macros-7** drives both filters live —
`jet-engine/listings/allowed-context-list` (confirms the new key appears in
`allowed_context_list()`'s output) and the dynamic `jet-engine/listings/data/
object-by-context/{key}` filter (confirms `Data::get_object_by_context()` resolves it) —
PASS, same "both halves required" shape as the Meta Boxes Options Source pairing in
`jetengine-modules`.

**Final result: 7/7 pass**, no fixes needed this round.

## Test 1 (remaining open half): JSON-config-block syntax variant

**Claim:** `%macro%{"fallback":"N/A","before":"$","after":"","filter":"..."}` — the
trailing JSON config block — is a valid, parseable form supporting `context`,
`fallback`, `before`, `after`, `filter` keys. The plain-tag and pipe-arg variants are now
covered by `macros-1`/`macros-2`/`macros-3`; the JSON-config-block variant is not yet
exercised by `tests.php`.

**Pass criteria:** confirm `before`/`after`/`fallback` actually wrap/replace the result
as documented in `macros-handler.php`'s `do_macros()`. Straightforward to add to
`tests.php` in a future pass — no listing fixture needed, same direct-call technique as
`macros-1` applies.

## Test 4: macro context resolves to the current listing item, not global state

**Claim:** `get_macros_object()` resolves via `get_current_object()` inside a listing
render, giving access to the specific item being rendered (not e.g. the queried archive
object).

**Setup:** register a macro that returns `$this->get_macros_object()`'s id, place it in
a listing item template rendering multiple items in a grid, view on the front end.

**Pass criteria:** each rendered item shows a DIFFERENT id, not the same one repeated.
Not automated — genuinely needs a live listing render, not achievable via a direct
`do_macros()` call outside of one (there's no "current item" without an active render).

## Test 5: CCT rows resolve via duck-typing (`cct_slug`/`_ID`), not a WP_Post branch

**Claim:** CCT rows fall through to the `jet-engine/macros/current-meta` filter branch
since they aren't `WP_Post`/`WP_Term`/`WP_User`, distinguished by `isset($object->cct_slug)`.

**Setup:** on a CCT-backed listing grid, log `get_class($this->get_macros_object())`.

**Pass criteria:** confirms the duck-typing claim; note the actual class name (SKILL.md
doesn't currently name it). Not automated — needs a live CCT-backed listing render.

## Test 6: macro resolution in Elementor/Bricks widgets

**Claim:** unconfirmed whether every render path (Elementor widgets, Bricks provider)
routes through the same `do_macros()`.

**Setup:** place a test macro inside an Elementor dynamic-tag field and a Bricks
dynamic-data field on a listing item, if the sandbox has both page builders active.

**Pass criteria:** if it fails to resolve in either builder, that's a real, previously
undocumented gap — add it to `SKILL.md`. Needs a real browser to configure/view; not
achievable via REST/curl.
