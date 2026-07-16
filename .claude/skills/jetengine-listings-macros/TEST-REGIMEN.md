# Test regimen: jetengine-listings-macros

Validates claims in `SKILL.md`. Run against the sandbox site (JetEngine 3.8.12).

## Run log — 2026-07-16: runnable suite added, 5/5 pass, no listing fixture needed

The 2026-07-15 blocker below (no listing grid on the sandbox, `tool-add-cct` fataling)
turned out to be avoidable entirely: `jet_engine()->listings->macros->handler` is a real
`\Crocoblock\Macros_Handler` instance reachable directly from any PHP context, and its
public `register_macros()`/`do_macros()` methods can be called straight from a REST
callback — no listing render, no page-builder, no browser needed. Added `tests.php`,
deployed as Code Snippets snippet id 32, run via
`GET /agent-test/v1/suite/jetengine-listings-macros`.

- **macros-1** (plain `%macro%` resolves via `do_macros()`): PASS.
- **macros-2** (pipe args are DROPPED if the macro class declares no `macros_args()`):
  PASS — **this is a genuine finding**, not previously stated in `SKILL.md` (which didn't
  pin down the no-schema-declared case for Test 1's pipe-arg variant). Now documented as
  a callout in `SKILL.md`.
- **macros-3** (pipe args map positionally when `macros_args()` IS declared): PASS —
  confirms the exact positional-mapping mechanism.
- **macros-4** (uppercase tag is a regex-level miss, callback never invoked — automates
  Test 2 above): PASS.
- **macros-5** (unregistered-but-valid tag is a registry-level miss, same literal output
  — automates Test 3 above): PASS.

Still not automated (both genuinely need a live listing render/page builder, can't be
done via REST alone): Test 4 (context resolves to the current grid item, not global
state), Test 5 (CCT rows resolve via `cct_slug` duck-typing), and Test 6 (Elementor/Bricks
widget parity).

## Run log — 2026-07-15: BLOCKED, not executed

Attempted against jackfruit.epeak.studio (turned out to be a live production BadgeIt/
WooCommerce install, not an isolated sandbox — see the caveat in the
`jetformbuilder-hooks` regimen's run log, same site). `resource-get-configuration`
showed **no JetEngine listing grids, meta boxes, or CCTs exist on this site at all** —
it only uses JetEngine's SQL Query Builder for event-ticketing queries. Every test here
needs a listing grid (CPT- or CCT-backed) to place `%macro%` tokens into, which didn't
exist.

Tried to build a throwaway fixture the same way the JFB regimens did (CCT → CCT-type
query → listing, via the jetengine MCP tools), but the very first step —
`tool-add-cct` — returned a **500 Internal Server Error / WordPress critical error**
from the site. The site recovered immediately and nothing was left broken (re-checked
`resource-get-configuration`: no CCT was partially created), but this tool call wasn't
retried given a fatal error is a real signal something's wrong (version mismatch,
PHP 8.5.8 incompatibility, or a conflict with an active plugin on this install) rather
than something to just try again. Stopped here rather than pushing further on a
production site after a tool-induced crash.

**To make this regimen runnable:** either fix whatever is causing `tool-add-cct` to
fatal on this site, or build the CCT + listing fixture manually in wp-admin instead of
via the MCP tool, then re-run these tests as written — none of them depend on which
site hosts the fixture. Test 6 (Elementor/Bricks widget parity) additionally needs a
real browser to configure and view a dynamic-tag field in each page builder — not
achievable via REST/curl alone regardless of fixture availability.

## Prerequisites

- A listing grid (CPT-backed) and, if available, a second one backed by a CCT, so
  macro resolution can be compared across both.
- Debug log sink (see `docs/test-regimen-guide.md`).
- Ability to edit a listing item template to insert raw `%macro%` tokens into a text
  field.

## Test 1: syntax variants all resolve

**Claim:** `%macro%`, `%macro|args%`, and `%macro%{"fallback":"..."}` are all valid,
parseable forms.

**Setup:** register a simple test macro:
```php
add_action( 'jet-engine/register-macros', function() {
    class Test_Echo_Macro extends \Jet_Engine_Base_Macros {
        public function macros_tag()  { return 'test_echo'; }
        public function macros_name() { return 'Test Echo'; }
        public function macros_callback( $args = [] ) {
            return 'ECHO:' . ( $args ? implode( ',', $args ) : 'none' );
        }
    }
    new Test_Echo_Macro();
} );
```
Place `%test_echo%`, `%test_echo|foo,bar%`, and `%test_echo%{"fallback":"N/A"}` in a
listing item text field.

**Trigger:** view the listing on the front end.

**Expected observable:** all three render (first shows `ECHO:none`, second shows
`ECHO:foo,bar` if args are passed through as expected — confirm exact arg-passing shape
since SKILL.md doesn't pin down whether `$args` is the raw pipe string or pre-split).

**Pass criteria:** no variant prints literally; note the actual `$args` shape observed
for the pipe-arg case.

## Test 2: uppercase/digit tags never match — literal by definition

**Claim:** a macro tag containing uppercase or digits fails the regex entirely, so the
callback is never invoked (as opposed to a "not found in registry" literal).

**Setup:** register a macro tagged `Test_Echo2` (uppercase T, trailing digit) — same
class pattern as Test 1 but `macros_tag()` returns `'Test_Echo2'`. Place
`%Test_Echo2%` in the template.

**Expected observable:** prints literally as `%Test_Echo2%`; add a log line inside
`macros_callback()` to confirm it's never called at all (vs. called and returning
something falsy).

**Pass criteria:** callback log never appears — confirms it's a regex-level miss, not a
registry miss.

## Test 3: registry-miss vs. regex-miss both produce identical literal output

**Claim:** an unregistered-but-validly-shaped tag (e.g. `%totally_unregistered%`) also
prints literally, same as the regex-miss case, but for a different reason.

**Setup:** place `%totally_unregistered%` (never registered anywhere) in the template.

**Expected observable:** prints literally, same visual result as Test 2 — confirming
both failure modes are indistinguishable from the front end (useful debugging note: you
can't tell from output alone which failure mode occurred; need source-level check).

**Pass criteria:** literal output matches; document this indistinguishability as a
debugging tip if not already clear enough in SKILL.md.

## Test 4: macro context resolves to the current listing item, not global state

**Claim:** `get_macros_object()` resolves via `get_current_object()` inside a listing
render, giving access to the specific item being rendered (not e.g. the queried
archive object).

**Setup:**
```php
class Test_Context_Macro extends \Jet_Engine_Base_Macros {
    public function macros_tag()  { return 'test_context_id'; }
    public function macros_name() { return 'Test Context ID'; }
    public function macros_callback( $args = [] ) {
        $obj = $this->get_macros_object();
        return is_object( $obj ) ? ( $obj->ID ?? ( $obj->_ID ?? 'no-id-prop' ) ) : 'not-an-object';
    }
}
```
Place `%test_context_id%` in a listing item template rendering multiple items in a
grid.

**Expected observable:** each rendered item in the grid shows a DIFFERENT id (matching
that specific item), not the same id repeated or the archive/page id.

**Pass criteria:** ids vary per grid item, confirming per-item context resolution.

## Test 5: CCT rows resolve via duck-typing (`cct_slug`/`_ID`), not a WP_Post branch

**Claim:** CCT rows fall through to the `jet-engine/macros/current-meta` filter branch
since they aren't `WP_Post`/`WP_Term`/`WP_User`, distinguished by `isset($object->cct_slug)`.

**Setup:** on a CCT-backed listing grid, use the Test 4 macro (or a variant logging
`get_class( $this->get_macros_object() )`) to confirm the object's actual class.

**Expected observable:** logged class is NOT `WP_Post` — likely a JetEngine-internal
CCT row object/array-like structure with a `cct_slug` property.

**Pass criteria:** confirms the duck-typing claim; note the actual class name found for
future reference (SKILL.md doesn't currently name it).

## Test 6: macro resolution in Elementor/Bricks widgets (not traced in source pass)

**Claim:** unconfirmed whether every render path (Elementor widgets, Bricks provider)
routes through the same `do_macros()` — flagged as a gap in the source research.

**Setup:** place the Test 1 macro inside an Elementor dynamic-tag field and a Bricks
dynamic-data field on a listing item, if the sandbox has both page builders active.

**Expected observable:** macro resolves the same way as it does in the native
block-based listing template.

**Pass criteria:** if it fails to resolve in either builder, that's a real, previously
undocumented gap — add it to SKILL.md as a confirmed limitation rather than leaving it
as "unconfirmed."
