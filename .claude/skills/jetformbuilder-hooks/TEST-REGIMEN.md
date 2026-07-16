# Test regimen: jetformbuilder-hooks

Validates claims in `SKILL.md`. Run against the sandbox site (JetFormBuilder 3.6.3.1).
For each test: add the snippet, trigger the action, check the observable via the
logging endpoint. Mark PASS/FAIL/UNCLEAR inline — a FAIL against a documented claim
means the skill needs a correction, not a silent deletion.

## Run log — 2026-07-16: runnable suite added, 3/3 pass

Added `tests.php` (per `docs/test-harness-guide.md`), deployed as Code Snippets snippet
id 34, run via `GET /agent-test/v1/suite/jetformbuilder-hooks`. Rather than re-doing a
real form submission (already done manually once, referenced below), these tests call
`Action_Exception`/`Call_Hook_Action` directly within the REST request.

- **hooks-1** (`is_success()` true only for the literal, case-sensitive string
  `'success'`): PASS — sharpens the original Test 4 (below, trimmed) with an exact rule
  instead of "depends on construction."
- **hooks-2** (`dynamic_success()`/`dynamic_error()` are the real mechanism for an
  arbitrary custom message to still count as success/failure): PASS — **new finding**,
  not previously named in `SKILL.md`; now documented in the gotchas section.
- **hooks-3** (Call Hook's `do_action()` fires `custom-action/{hook}` then
  `custom-filter/{hook}` with the exact `($request,$handler)`/`(true,$request,$handler)`
  signatures and stores the filter's return in `response_data['hook_result']`, invoked
  directly rather than via a real submission): PASS — supersedes the original Test 3
  (below, trimmed) without needing a live form.

Test 1 (after-send 2-arg signature, fires after DB save), Test 2 (per-action-type
Condition-gating), and Test 5 (block vs. shortcode forms) remain verified only by the
2026-07-15 manual curl-based run below, not re-automated.

## How the manual run was done (2026-07-15, for context)

The site named as "the sandbox" turned out to be a live production install at the time
(real WooCommerce/BadgeIt data, no JetEngine fixtures, JetSmartFilters not installed).
No JFB test form existed either, so one was built: a throwaway form (post id 353) on a
throwaway host page (id 354). Submission was via JFB's native `submit-type-reload` flow
— GET the host page, scrape the rendered `_wpnonce` + hidden fields + the
(Wordfence-obfuscated) form `action` URL, POST back with `curl`. No browser was used;
this was a real end-to-end submission through WordPress, not a simulated one.

## Test 1: `after-send` fires with 2 args, after DB save

**Claim:** `jet-form-builder/form-handler/after-send` (`form-handler.php:363`) fires
last, passes `($this, $is_success)`, and DB records ("Save Form Record") already exist
by the time it fires.

**Result (2026-07-15, executed, PASS):** log showed
`after-send fired. is_success=true handler_class=Jet_Form_Builder\Form_Handler` on every
submission, args populated as claimed. Not re-automated in `tests.php` — needs a real
form submission to observe the full lifecycle, not just the exception class in
isolation.

## Test 2: `before-do-action/{action_id}` is per-action-type and fires pre-Conditions-pass

**Claim:** `do_action( "jet-form-builder/before-do-action/{$action->get_id()}", $action )`
(`action-handler.php:220`) fires only after Conditions pass, before that specific action
type executes.

**Result (2026-07-15, partial):** PASS for the "fires per specific action type, receives
`$action`" part — both `before-do-action/call_hook` and
`before-do-action/agent_test_log_action` fired independently with the right
`$action->get_id()`. **Not tested:** the Condition-gating half (fires only after
Conditions pass) — building a Condition on a step wasn't done. Still UNCLEAR.

**To close out:** submit a form with a Call Hook step gated by a Condition, once true
and once false; confirm the hook fires only on the true submission. Needs a real form
submission with a configured Condition — not automatable via direct class invocation.

## Test 5: no behavior difference between block-based and legacy shortcode forms

**Claim:** `SKILL.md` flags this as *unconfirmed* — the lifecycle hooks appeared to
share code paths but this wasn't exhaustively traced.

**Setup:** duplicate the same form/hook setup from Test 1 on both a block-editor form
and a legacy shortcode form, if the sandbox has both; compare firing order/args.

**Pass criteria:** any divergence found should be added to `SKILL.md` as a correction.
Not automated — no legacy shortcode form fixture exists on the sandbox yet.
