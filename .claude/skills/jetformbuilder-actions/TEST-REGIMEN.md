# Test regimen: jetformbuilder-actions

Validates claims in `SKILL.md`. Run against the sandbox site (JetFormBuilder 3.6.3.1).

## Run log — 2026-07-16: runnable suite added, 3/3 pass

Added `tests.php` (per `docs/test-harness-guide.md`), deployed as Code Snippets snippet
id 33, run via `GET /agent-test/v1/suite/jetformbuilder-actions`. Unlike the 2026-07-15
manual run (real curl submission against a throwaway form, referenced below), these
tests call `Base`/`Action_Exception`/`Manager` directly within the REST request — no
form submission needed, and safe to re-run indefinitely (registration effects are scoped
to the single PHP request).

- **act-1** (`Base`'s default `dependence()`/`is_disabled()`/`on_register_in_flow()`):
  PASS — automates the "no Module wrapper needed" half of Test 1 (below); the "appears
  in the editor UI" half still needs a browser, remains UNCLEAR (see Test 1).
- **act-2** (`Action_Exception` message→status/`is_success()` wiring, pinned to the exact
  literal-string-only rule): PASS — supersedes the original Test 3 (below, trimmed);
  confirms `is_success()` is `true` only for the literal string `'success'`.
- **act-3** (silent ID collision on `redirect_to_page`, done safely within a single
  request instead of a live/global override window): PASS — supersedes the original
  Test 4 (below, trimmed) with no risk to other visitors.

Test 2 (repeater-field `$request` shape) and Test 5 (registration timing, `init`
priority 99) remain not automated — both still need a real form submission, see below.

## Test 1: minimal custom action registers and appears in the editor

**Claim:** hooking `jet-form-builder/actions/register` and calling
`$manager->register_action_type( new My_Action() )` is sufficient — no Module wrapper
needed. `act-1` confirms the functional-execution half (default method values); the
"appears in the editor UI" half needs a browser and remains UNCLEAR from the 2026-07-15
manual run (no browser was available that session either).

**Setup:** register a test action, open the form editor, add an action step, check if it
appears in the picker.

**Pass criteria:** action is selectable and savable as a step. Not automated — needs a
browser session against the form editor.

## Test 2: `$request` is fully resolved, not raw `$_POST`

**Claim:** `do_action()`'s `$request` param comes from `jet_fb_context()->resolve_request()`,
already flattened/normalized (not raw superglobal shape). Confirmed for a simple 1-field
form via the 2026-07-15 manual run (logged `$request` was exactly
`test_field,__form_id,__refer,__is_ajax` — none of the raw POST body's WordPress/
Wordfence/JFB plumbing fields leaked through). The repeater-field variant (comparing
shapes with 2+ repeater rows) was not run.

**Setup:** use a form with a repeater field; submit with 2+ repeater rows filled in;
compare the logged `$request` shape against what raw `$_POST` would have looked like.

**Pass criteria:** confirm the shape differs from raw POST in a way consistent with
"already resolved." Not automated — needs a real form submission with a repeater field.

## Test 5: registration timing — `init` priority 99

**Claim:** the `jet-form-builder/actions/register` hook fires at `init` priority 99;
code depending on something not yet loaded by then misses the window.

**Setup:** register a custom action from a callback whose `dependence()` checks a flag
only set later (e.g. on `wp_loaded`) — deliberately create the "too late" scenario.

**Pass criteria:** action does not appear in the editor because `dependence()` returned
false at registration time despite the flag being true later. Not automated — needs a
form-editor check, and deliberately creating the timing race isn't easily expressed as
a same-request `agent_test_assert()`.
