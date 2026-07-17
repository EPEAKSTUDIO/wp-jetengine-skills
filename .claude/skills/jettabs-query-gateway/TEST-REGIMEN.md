# Test regimen: jettabs-query-gateway

Validates claims in `SKILL.md`. Run against the sandbox site once JetTabs (2.3.2),
JetEngine (with Query Builder), and ideally JetElements are all active — see
`docs/test-regimen-guide.md` for format and `docs/test-harness-guide.md` for the
runnable-suite convention `tests.php` follows.

## Run log — 2026-07-16: UNBLOCKED, live-verified (4/4 pass)

Deployed `tests.php` as Code Snippets snippet id 59 and ran
`GET /agent-test/v1/suite/jettabs-query-gateway`: **4/4 pass**, no fixes needed.

## Prerequisites

- JetEngine active with at least one Query Builder query configured (2+ results).
- JetTabs active, with a page containing an Image Accordion widget (repeater control
  `item_list`) and a Tabs widget (repeater control `tabs`) — both are Query-Gateway-
  eligible per `SKILL.md`.
- Debug log sink (see `docs/test-regimen-guide.md`).

## Test 1 (highest priority): Image Accordion's missing `do-item` really breaks per-item Dynamic Tags

**Claim being tested:** `SKILL.md` "Practical consequence for Image Accordion" — because
`Jet_Tabs_Base::__get_render_looped_template()` never fires
`jet-engine-query-gateway/do-item`, `Query_Gateway\Manager::set_item_object()` never runs
per item, so a Dynamic Tag inside the Image Accordion item template resolves against
whatever object was current before the loop, not the queried row.

**Setup:**
1. Add an Image Accordion widget to a page. In its Items repeater, leave exactly one
   static row, toggle "Use JetEngine query" on for `item_list`, and select a real query
   with 2+ results whose rows have visibly different values for some field (e.g. post
   title).
2. Map that static row's "Title" field to a JetEngine Dynamic Tag pulling the queried
   object's title field.

**Trigger:** view the page on the front end.

**Expected observable:** every rendered Image Accordion item shows the **same** title
(either blank, or the title of whatever object was ambient before the widget rendered —
e.g. the containing page/post), not one distinct title per query result row.

**Pass criteria:** all items show identical (wrong) dynamic content — this is the
opposite of what a working Query Gateway integration looks like (compare against Test 2,
same setup on Tabs, which should show one distinct value per item). Confirms the gap end
to end. Not automatable via `tests.php` (needs a real widget + query + rendered page);
this is the single highest-value manual test for this skill.

## Test 2 (control): the same setup on the Tabs widget resolves correctly per item

**Claim being tested:** `SKILL.md` table — Tabs' hand-rolled loop *does* fire `do-item`
per item, so this same Dynamic-Tag setup should work correctly there, proving the gap is
specific to Image Accordion's shared-base-class path and not a site-wide Query Gateway
misconfiguration.

**Setup:** repeat Test 1's setup exactly, but on a Tabs widget's `tabs` repeater instead
of Image Accordion's `item_list`.

**Trigger:** view the page on the front end.

**Expected observable:** each tab shows a distinct title matching its own query result
row.

**Pass criteria:** per-item values differ and match the query's actual rows — this is
what "working" looks like, and its success alongside Test 1's failure isolates the bug to
Image Accordion specifically (not a general Query-Gateway-on-JetTabs failure). Manual/
browser check, same reason as Test 1.

## Test 3: `jet-engine-query-gateway/control` fires for exactly the three expected controls, not Switcher

**Claim being tested:** `SKILL.md` "The three Query-Gateway-enabled controls" — Tabs
(`tabs`), Accordion (`toggles`), and Image Accordion (`item_list`) each fire this action
once from their own `_register_controls()`; Switcher never fires it at all.

**Setup:** none beyond having all four JetTabs widgets registered (default).

**Trigger:** in the Elementor editor, open each of the four JetTabs widgets' panels and
check whether a "Use JetEngine query" switcher control appears above the Items/Toggles
repeater section (Tabs/Accordion/Image Accordion should show it; Switcher — which has no
repeater at all — should not).

**Expected observable:** switcher control present on Tabs/Accordion/Image Accordion,
absent (not applicable) on Switcher.

**Pass criteria:** matches expected per-widget presence — confirms
`Query_Gateway\Manager::register_controls()`'s per-widget control map is populated
exactly by the three documented call sites. Not automatable via `tests.php` (needs a
live Elementor editor); `jqg-3` in `tests.php` covers the source-presence half of this
(the exact call-site strings existing in the 3 files, absent from the 4th).

## Automated in `tests.php` (once deployed)

- `jqg-1`: `jet-tabs/widget/loop-items` is a real, mutable filter (same generic-WP-hook
  smoke test pattern as `jetelements-query-gateway`'s `jeg-1`) — driven directly via
  `apply_filters()` with a marker callback and a widget stub whose `get_name()` misses
  `Query_Gateway\Manager`'s internal controls map, so `is_control_supported()`
  short-circuits false (same safety reasoning as `jetelements-query-gateway/tests.php`'s
  `jeg-1` — see that file's docblock for why a real widget object isn't safe to fake
  further than this).
- `jqg-2`: JetEngine's `Query_Gateway\Manager` has already registered its
  `jet-tabs/widget/loop-items` filter listener specifically (not just the JetElements
  one) — via `has_filter()`, never by instantiating the Manager (same landmine as
  `jetelements-query-gateway`'s `jeg-2`).
- `jqg-3`: source-presence check that `jet-engine-query-gateway/do-item` is **absent**
  from `class-jet-tabs-base.php` but **present** in both
  `jet-tabs-widget.php`/`jet-accordion-widget.php` — the core, directly-checkable claim
  of this whole skill; also checks the 3-widget/not-Switcher `control` call-site pattern
  from Test 3.
- `jqg-4`: the `before-loop` call sites in both `class-jet-tabs-base.php` and JetElements'
  `class-jet-elements-base.php` pass 2 args (`$setting, $this`/`$widget`), confirming the
  "arg-count correction" note against `jetelements-query-gateway/SKILL.md`'s "0 args"
  claim.
