# Test regimen: jetthemecore-template-conditions

Validates claims in `SKILL.md`. Run against the sandbox site once JetThemeCore is
installed there (not installed as of this writing — see `tests.php`'s header comment).
For each test: set up the snippet/state described, trigger the action, then check the
expected observable via `GET /agent-test/v1/suite/jetthemecore-template-conditions`.

## Run log — 2026-07-16: UNBLOCKED, live-verified (5/5 pass, one real bug found and fixed)

Deployed `tests.php` as Code Snippets snippet id 62 and ran
`GET /agent-test/v1/suite/jetthemecore-template-conditions`. First run: the whole request
500'd — triaged by bisecting with isolated `ZZZ-DIAG` probes (same technique as
`jetmenu-structure`'s landmine diagnosis) down to `jttc-4`, which called
`register_cpt_conditions()` a second time. **Real doc bug, not a test bug**: SKILL.md's
"Bootstrap" section had claimed the `Manager` class was safe to re-run past its
constructor, but `register_cpt_conditions()` itself does an unconditional `require` (not
`require_once`) of 4 condition class files every call — a second call re-`require`s
already-loaded files and throws an uncatchable "Cannot redeclare class" fatal. Fixed
`SKILL.md` (added the correction to "Bootstrap" and "Gotchas") and rewrote `jttc-4` to
check WooCommerce's already-registered `product` CPT's auto-generated conditions instead
of re-invoking the unsafe method. Second run: 3/5 — `jttc-1` had a wrong expected id
string (`cpt-singular-post-type` vs. the real `singular-post-type`), and `jttc-3`'s fake
post-type slug (28 chars) silently exceeded WordPress's 20-character post-type-slug
limit, so `register_post_type()` no-op'd. Both test-only bugs, fixed. Re-run: **5/5
pass**.

## Prerequisites

- JetThemeCore 2.3.1.2 active on the sandbox, alongside the always-active
  `AGENT-TEST-CORE harness` snippet (`test-harness/core-snippet.php`).
- This suite deployed as its own Code Snippets snippet
  (`AGENT-TEST-SUITE: jetthemecore-template-conditions`).
- At least one public custom post type registered on the sandbox (to exercise
  `register_cpt_conditions()` — if none exists, register a throwaway one, e.g.
  `agent_test_cpt`, in a setup snippet before running).

## Test 1: the registry is reachable and populated after `init`

**Claim:** `jet_theme_core()->template_conditions_manager` is the live `Manager`
singleton; `register_conditions()` runs on `init` priority 999 and populates
`get_conditions()`.

**Setup:** none beyond the harness.

**Trigger:** call `jet_theme_core()->template_conditions_manager->get_conditions()`
from inside the suite (the harness's own action fires late enough that `init` has
already run).

**Expected observable:** a non-empty array containing at least the built-in ids
`entire`, `archive-all`, `singular-page`, `singular-page-template`, `cpt-singular-post-type`.

**Pass criteria:** all listed ids present as array keys.

## Test 2: `check()` really is called with 3 args, not 1

**Claim:** every real call site invokes `check( $sub_group_value, $sub_group, $sub_group_arg )`
despite `Base::check( $args )` declaring one parameter.

**Setup:** register a throwaway condition class inline (via
`jet-theme-core/template-conditions/register`, calling `add_condition()` directly with a
pre-built instance — avoids needing a separate `require`-able file) whose `check()`
signature is `check( $value = null, $sub_group = null, $arg = null )` and simply records
all 3 params it received.

**Trigger:** call `find_matched_conditions()` (or, more directly, invoke
`call_user_func( [$instance, 'check'], 'a', 'b', 'c' )` the same way the manager does)
against a template configured to use the throwaway condition's sub-group.

**Expected observable:** the recorded params are exactly `['a', 'b', 'c']` (3 non-null
values), confirming the 3-arg call convention.

**Pass criteria:** all 3 args non-null and equal to the values passed.

## Test 3: `post-types-list/deprecated` and `custom-post-types-list/deprecated` are both real, currently-firing filters

**Claim:** both hooks are live, not dead/inert despite their "deprecated" naming, and
they gate two *different* functions (`get_post_types()` vs.
`get_custom_post_types_options()`).

**Setup:** add temporary filter callbacks to both hooks that append a marker post-type
slug (`agent_test_marker_post_type`, pre-registered as a public CPT) to whichever list is
passed in, then remove the callbacks at the end of the test.

**Trigger:** call `\Jet_Theme_Core\Utils::get_post_types()` and
`\Jet_Theme_Core\Utils::get_custom_post_types_options()` before/after adding the
callback.

**Expected observable:** with the callback active, `agent_test_marker_post_type` is
excluded from each function's respective output; without it, present.

**Pass criteria:** presence toggles correctly for both functions independently.

## Test 4: CPT conditions are auto-generated, one archive+single pair per surviving post type

**Claim:** `register_cpt_conditions()` creates `cpt-archive-{slug}`/`cpt-single-{slug}`
(and `cpt-taxonomy-{tax}`/`cpt-post-term-{tax}` per attached public/nav-menu taxonomy)
for every post type `Utils::get_custom_post_types_options()` returns.

**Setup:** ensure at least one public custom post type is registered on the sandbox
(`agent_test_cpt`, with `public: true`).

**Trigger:** read `get_conditions()` after `init`.

**Expected observable:** `cpt-archive-agent_test_cpt` and `cpt-single-agent_test_cpt`
keys both present.

**Pass criteria:** both keys present with instances whose `get_id()` matches.

## Test 5: `find_matched_conditions()` has no priority-based tie-break

**Claim:** when two templates both match the same structure type, the function returns
both in option-array order (most-recently-saved first), and does not consult
`get_priority()` to pick a winner.

**Setup:** using `update_template_conditions()` directly (not through the REST
endpoint), save two dummy template ids (real trashed/draft posts created for this test,
cleaned up after) against the same structure type (`jet_single`) with an `'entire'`
include condition each, in a known save order.

**Trigger:** call `find_matched_conditions( 'jet_single' )`.

**Expected observable:** an array containing both ids, ordered so the most-recently-saved
one is first — matching the option's `array_reverse()` behavior at
`update_template_conditions()`, not the conditions' relative `get_priority()` values
(which are irrelevant here since both used the same `entire` condition anyway — the
point is confirming no priority-comparison logic runs at all, verified by reading the
function, not by a differential priority setup).

**Pass criteria:** both ids present, first one is the one saved most recently.

## Not yet automated — manual steps only

- Verifying `find_matched_conditions()`'s "fail open" behavior for an orphaned
  condition (a condition instance registered by a since-deactivated plugin) needs a
  fixture that can't be created safely inside a single suite run (would require
  deactivating a real plugin mid-test) — leave as a manual repro: save a template
  condition referencing a `subGroup` id that doesn't exist in the current registry, and
  confirm the template matches unconditionally.
