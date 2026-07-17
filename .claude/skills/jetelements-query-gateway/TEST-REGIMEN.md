# Test regimen: jetelements-query-gateway

Validates claims in `SKILL.md`. Run against the sandbox site once JetElements For
Elementor (2.9.1.2) **and** JetEngine (already installed, with Query Builder) are both
active.

## Run log — 2026-07-16: live-verified, 4/4 pass after two fixes

JetElements was activated on the sandbox later the same session. Deployed `tests.php` as
snippet id 38 (`AGENT-TEST-SUITE: jetelements-query-gateway`). First run: **3/4**, `jeg-1`
threw `Call to a member function get_name() on null`. Triaged per
`docs/test-harness-guide.md`: the plugin behavior was correct — JetEngine's own
`Query_Gateway\Manager::jet_plugins_compatibility()` is a real, already-registered
listener on `jet-elements/widget/loop-items` (exactly what `jeg-2` checks), and it
unconditionally calls `$widget->get_name()` — the test passed `null` for `$widget`,
which is a test bug, not a plugin bug. Fixed by passing a minimal stub object whose
`get_name()` returns a name absent from `Query_Gateway\Manager`'s internal
`$_controls_map`, so `is_control_supported()` short-circuits `false` before it needs
`get_settings()`. Second run: still **3/4**, now `jeg-2` failed —
`jeg-1`'s cleanup used `remove_all_filters( 'jet-elements/widget/loop-items' )`, which
also strips JetEngine's own real listener off that hook (not just the test's own
callback), so by the time `jeg-2` ran and checked `has_filter()`, the site's real
listener was gone. Fixed by switching to `remove_filter()` with the exact callback
reference instead of `remove_all_filters()`. Re-ran: **4/4 pass**. Both fixes are a good
worked example of this repo's "confirm gotcha" lesson from `HANDOFF.md`: a `tests.php`
suite runs inside a real, already-bootstrapped site, so any global mutation (removing
*all* filters on a hook, not just your own) can quietly corrupt a later assertion in the
same run.

## Prerequisites

- JetEngine active with at least one Query Builder query configured (any SQL/Posts type
  query with 2+ results is enough).
- JetElements active, with a page containing a widget that has a Repeater "Items"
  control (Portfolio, Advanced Carousel, or Team Member are all good candidates —
  `jet-engine-query-gateway/control` fires in each).
- Debug log sink (see `docs/test-regimen-guide.md`).

## Test 1: enabling "Use JetEngine query" actually swaps repeater rows for query results

**Claim being tested:** `SKILL.md` "Enabling a control" / "Building a custom
Query-Gateway-compatible widget" — `query_enbaled()`'s three-part gate
(switcher + query id + non-empty repeater) controls whether `get_queried_items()`
replaces the static rows.

**Setup:** add a Portfolio widget to a page. In its Items repeater section, leave
exactly one static row, toggle "Use JetEngine query" on, and select a real query with
2+ results.

**Trigger:** view the page on the front end (or Elementor preview).

**Expected observable:** the widget renders one item per query result row (not just the
one static row), with dynamic-tag-mapped fields from that row's data.

**Pass criteria:** item count on the front end matches the query's result count, not 1
— confirms the full gate + `get_queried_items()` pipeline works end to end. Not
automatable via `tests.php` (needs a real widget instance with Elementor-managed
settings and a real front-end render), left for manual/browser verification.

## Test 2: removing the last static repeater row breaks the mapping (documented gotcha)

**Claim being tested:** `SKILL.md` Gotchas — deleting the one remaining static row once
Query Gateway is enabled breaks the field-mapping, because `query_enbaled()` requires
`$control_val` (the repeater's own raw value) to be non-empty.

**Setup:** starting from Test 1's working state, delete the last static repeater row
entirely (leaving the Items list empty) while "Use JetEngine query" stays on.

**Trigger:** view the page on the front end.

**Expected observable:** the widget now renders zero items (falls back to
`query_enbaled()` returning `false`), not the query's results — confirms the row is
load-bearing, not decorative.

**Pass criteria:** matches expected "reverts to empty" behavior. Manual/browser check,
same reason as Test 1.

## Test 3: JetEngine's current-listing-object stack is restored after the loop

**Claim being tested:** `SKILL.md` "The four hooks" — `before-loop`/`reset-item`
correctly save and restore whatever JetEngine "current object" was active before a
Query-Gateway-enabled widget's loop ran, so nesting (e.g. this widget placed inside a
real JetEngine Listing Grid item) doesn't leak state.

**Setup:** place a JetEngine Listing Grid on a page, and inside one listing item's
template embed a JetElements widget (e.g. Team Member repeated via a Query Gateway
query) that itself uses Dynamic Tags mapped to the *outer* listing's fields somewhere
after the inner widget in the same template.

**Trigger:** view the page on the front end.

**Expected observable:** dynamic tags placed **after** the inner widget in the same
listing-item template still resolve against the outer listing item's data, not the
inner widget's last query row.

**Pass criteria:** no stale/leaked data from the inner loop — confirms `before-loop`/
`reset-item`'s stack push/pop actually round-trips correctly. Not automatable via
`tests.php` (needs nested real listing + widget fixtures and a rendered page); flagged
as the highest-value follow-up manual test for this skill.

## Automated in `tests.php` (once deployed)

- `jeg-1`: `jet-elements/widget/loop-items` filter genuinely fires and is mutable — live,
  via a bare `apply_filters()` call with a marker filter, no widget needed.
- `jeg-2`: the three lifecycle actions (`before-loop`/`do-item`/`reset-item`) and the
  `jet-engine-query-gateway/control` action are all already registered by JetEngine's
  `Query_Gateway\Manager` on this site (`has_action()` checks) — proves JetEngine's side
  of the integration is live-wired without ever touching the Manager instance itself.
- `jeg-3`/`jeg-4`: source-presence checks for the exact hook names/arg counts in both
  plugins' currently-installed source, guarding against a future update silently
  renaming one side of the integration.
