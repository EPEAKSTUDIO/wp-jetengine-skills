# Test regimen: jetthemecore-theme-builder

Validates claims in `SKILL.md`. Run against the sandbox site once JetThemeCore is
installed there (not installed as of this writing). For each test: set up the
snippet/state described, trigger the action, then check the expected observable via
`GET /agent-test/v1/suite/jetthemecore-theme-builder`.

## Run log — 2026-07-16: UNBLOCKED, live-verified (4/4 pass after 3 test-only fixes)

Deployed `tests.php` as Code Snippets snippet id 63 and ran
`GET /agent-test/v1/suite/jetthemecore-theme-builder`: first run 1/4. Root causes, all
test-only: (1) `jttb-1`/`jttb-3` extracted the new Page Template's id from the wrong
response key (`create_page_template()` actually returns it at `data.newTemplateId`, not
`id`/`data.id`) — the null id then meant `wp_delete_post()` cleanup silently never ran on
those first attempts, leaking 3 orphaned `jet-page-template` posts (cleaned up
separately, see `jetthemecore-locations`' run log for the follow-on flakiness this
caused). (2) `create_page_template()` itself leaves the *option-based* conditions record
empty regardless of the `$template_conditions` arg passed in — only
`update_page_template_conditions()` actually populates what the matcher reads; `jttb-1`
now calls it explicitly after creation. (3) `jttb-4` fataled with "Call to a member
function has_conditions() on false" because `post_conditions_verbose()` resolves a
structure via the `_jet_template_type` post meta (not `post_type`), which the fabricated
test post never set — fixed by setting that meta to `'jet_header'`. Re-run: **4/4
pass**.

## Prerequisites

- JetThemeCore 2.3.1.2 active on the sandbox, alongside the always-active
  `AGENT-TEST-CORE harness` snippet.
- This suite deployed as its own Code Snippets snippet
  (`AGENT-TEST-SUITE: jetthemecore-theme-builder`).
- At least one real `jet-page-template` post creatable via
  `Page_Templates_Manager::create_page_template()` for the matcher tests (cleaned up
  after each test).

## Test 1: `is_excluded/and` and `is_excluded/or` both fire, with the documented 3-arg signature

**Claim:** both hooks exist and fire from `get_matched_page_template_conditions()`,
gated by which `_relation_type` a given Page Template post uses.

**Setup:** create two throwaway `jet-page-template` posts via
`create_page_template()`, one with `relation_type = 'and'`, one with `'or'`, each with
one `include` condition (`entire`) and one `exclude` condition (also `entire`, so
`$excludes_matchs` is non-empty and the `is_excluded` filter definitely fires).

**Trigger:** hook both filters, recording the 3 args each receives; call
`jet_theme_core()->theme_builder->frontend_manager->get_matched_page_template_conditions()`.

**Expected observable:** both hooks fired, each with 3 args (`$is_excluded_bool`
[array/bool depending on which — actually a bool going in], `$excludes_matchs` [array],
`$page_template_id` [one of the two test post ids]) — `/and` fired for the `'and'`-relation
post, `/or` fired for the `'or'`-relation post.

**Pass criteria:** both hooks recorded with 3 args and the right post id each.

## Test 2: averaged-priority tie-break picks the lower-average-priority Page Template

**Claim:** `get_primary_page_template_id_by_conditions()` sorts ascending by the average
`get_priority()` of each candidate's include rows, and returns the winner via
`array_key_first()`.

**Setup:** create two `jet-page-template` posts, both matching (both use `'entire'`
include conditions so both always match) — since `entire`'s own priority isn't
meaningfully differentiated in the built-in set, this test instead directly calls
`get_primary_page_template_id_by_conditions()` with a hand-built
`$page_template_conditions` array shaped exactly like `get_matched_page_template_conditions()`'s
output, using two fabricated condition rows with distinctly different `priority` values
(e.g. 5 vs. 50) — avoids needing a real low/high-priority condition pair to naturally
match the same request.

**Trigger:** call `get_primary_page_template_id_by_conditions( $fabricated_array )`.

**Expected observable:** returns the key whose averaged include-row priority is lower.

**Pass criteria:** returned id matches the lower-priority candidate.

## Test 3: `_layout`/`_relation_type`/`_conditions` meta round-trips through the Page_Templates_Manager write methods

**Claim:** `update_page_template_conditions()`/`update_page_template_relation_type()`/
`update_page_template_layout()` write the expected post meta keys, and
`get_site_page_template_conditions()` reflects them back through the site-wide option.

**Setup:** create one throwaway `jet-page-template` post.

**Trigger:** call all 3 update methods with distinctive test values, then read back via
`get_page_template_relation_type()`, `get_page_template_layout()`, and
`get_site_page_template_conditions()`.

**Expected observable:** each read-back matches what was written; the post id appears as
a key in `get_site_page_template_conditions()`'s return value with matching
`conditions`/`relation_type`.

**Pass criteria:** all fields round-trip correctly.

## Test 4: REST `update-template-conditions` endpoint writes through to the classic Manager and returns fresh `verboseHtml`

**Claim:** `POST jet-theme-core-api/v2/update-template-conditions` calls
`Template_Conditions\Manager::update_template_conditions()` directly and its response
includes `data.verboseHtml` from `post_conditions_verbose()` in the same response, not a
separately-fetched summary.

**Setup:** create one throwaway "template" post (any post type usable with
`_jet_template_conditions` meta — a plain draft post is enough since the endpoint itself
doesn't validate template type).

**Trigger:** call the endpoint's `callback()` directly (bypassing REST dispatch, same
technique used elsewhere in this repo to avoid needing a live authenticated REST round
trip) with `$request` args `{template_id, conditions: [...]}`.

**Expected observable:** response `success: true`; `get_post_meta($id, '_jet_template_conditions', true)`
now equals the submitted conditions; `data.verboseHtml` is a non-empty string containing
markup produced by `post_conditions_verbose()`.

**Pass criteria:** meta persisted and `verboseHtml` non-empty.

## Not yet automated — manual steps only

- The full frontend rendering side-effects of a matched Page Template (the `get_header`/
  `get_footer`/`template_include` hooks actually swapping in overridden header/footer/body
  content) require a real front-end page load to observe visually — not practically
  automatable via a single REST-triggered suite call. Manually confirm by creating a Page
  Template with a header override enabled, visiting a page matched by its conditions, and
  checking the rendered header differs from the theme default.
