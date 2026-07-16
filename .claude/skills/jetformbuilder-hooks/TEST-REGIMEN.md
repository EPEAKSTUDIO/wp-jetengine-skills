# Test regimen: jetformbuilder-hooks

Validates claims in `SKILL.md`. Run against the sandbox site (JetFormBuilder 3.6.3.1).
For each test: add the snippet, trigger the action, check the observable via the
logging endpoint. Mark PASS/FAIL/UNCLEAR inline — a FAIL against a documented claim
means the skill needs a correction, not a silent deletion.

## Run log — 2026-07-16: runnable suite added, 3/3 pass

Added `tests.php` (per `docs/test-harness-guide.md`), deployed as Code Snippets snippet
id 34, run via `GET /agent-test/v1/suite/jetformbuilder-hooks`. Rather than re-doing a
real form submission (already done manually below), these tests call
`Action_Exception`/`Call_Hook_Action` directly within the REST request.

- **hooks-1** (`is_success()` true only for the literal, case-sensitive string
  `'success'`): PASS — sharpens Test 4 above with an exact rule instead of "depends on
  construction."
- **hooks-2** (`dynamic_success()`/`dynamic_error()` are the real mechanism for an
  arbitrary custom message to still count as success/failure): PASS — **new finding**,
  not previously named in `SKILL.md`; now documented in the gotchas section.
- **hooks-3** (Call Hook's `do_action()` fires `custom-action/{hook}` then
  `custom-filter/{hook}` with the exact `($request,$handler)`/`(true,$request,$handler)`
  signatures and stores the filter's return in `response_data['hook_result']`, invoked
  directly rather than via a real submission): PASS — automates Test 3 above without
  needing a live form.

Test 1 (after-send 2-arg signature, fires after DB save), Test 2 (per-action-type
Condition-gating), and Test 5 (block vs. shortcode forms) remain verified only by the
2026-07-15 manual curl-based run below, not re-automated — each needs either a real form
submission or a Condition/legacy-shortcode fixture that doesn't exist as a safe,
repeatable target yet.

## Run log — 2026-07-15, executed against jackfruit.epeak.studio

**Important context:** the site named as "the sandbox" in this doc turned out to be a
**live production install** (real WooCommerce orders + BadgeIt event-ticketing data, no
existing JetEngine CCTs/relations/listings, JetSmartFilters not even installed). There
was no pre-existing JFB test form either, so one was built for this run: a throwaway
form (`jet-form-builder` post id 353, title "AGENT-TEST throwaway form", one text field
`test_field` + submit) embedded in a throwaway page (id 354,
`/agent-test-form-host-page/`). Both should be deleted from wp-admin — see the
Manual cleanup note in `docs/code-snippets-rest-api.md` and at the bottom of this file.

Debug sink: a Code Snippets snippet exposing `GET/DELETE /wp-json/agent-test/v1/log`
backed by a `wp_options` row (see `docs/code-snippets-rest-api.md`), plus a global
`agent_test_log($msg)` helper other test snippets called into.

Submission mechanism used: JFB's native `submit-type-reload` flow — GET the host page,
scrape the rendered `_wpnonce` + hidden fields + the (Wordfence-obfuscated) form
`action` URL, then POST back to that exact URL with `curl`. No browser was used; this is
a real end-to-end submission through WordPress, not a simulated one.

- **Test 1 (after-send, 2 args, fires last): PASS.** Log showed
  `after-send fired. is_success=true handler_class=Jet_Form_Builder\Form_Handler` on
  every submission, args populated as claimed.
- **Test 2 (before-do-action/{id}, per-action-type): PASS** for the "fires per specific
  action type, receives `$action`" part — both `before-do-action/call_hook` and
  `before-do-action/agent_test_log_action` fired independently with the right
  `$action->get_id()`. **Not tested**: the Condition-gating half of the claim (fires
  only after Conditions pass) — building a Condition on a step wasn't done this run;
  still UNCLEAR, needs a follow-up test.
- **Test 3 (custom-filter signature `($result, $request, $handler)`, `10,3`): PASS.**
  Logged `result=true request_keys=test_field,__form_id,__refer,__is_ajax
  handler_class=Jet_Form_Builder\Actions\Action_Handler` — confirms both the 3-arg
  signature and (bonus finding, see `jetformbuilder-actions` regimen) that `$request` is
  a small normalized set of keys, not raw `$_POST` (which also had `_wpnonce`,
  `_wp_http_referer`, `__queried_post_id`, `_jfb_current_render_states[]` — none of
  which leaked into `$request`).
- **Test 4 (`Action_Exception` is_success from message content, not "was thrown"):
  PASS, and concretely pinned down.** Throwing
  `new \Jet_Form_Builder\Exceptions\Action_Exception( 'success' )` from a Call Hook
  filter produced `after-send ... is_success=true` and a redirect to `?status=success`
  — i.e. the exception being thrown did **not** fail the form, because its message was
  literally the string `'success'`. Source-level detail found while confirming this
  (worth folding into SKILL.md): `Handler_Exception::is_success()` delegates to
  `Status_Info`, which only returns `is_success = true` if the message is **exactly**
  `'success'** (or matches a registered "dynamic" success-type) — `custom_test_status`
  or any other arbitrary string is *not* success by default. The SKILL.md wording ("a
  thrown exception can still register as success depending on how it's constructed") is
  correct but slightly underspecifies "success" to mean the literal string, not just
  "any truthy-sounding message."
- **Test 5 (block vs. shortcode forms, no difference): NOT TESTED.** Only a
  block-editor form was built for this run; no legacy shortcode form existed on the site
  to compare against and building one wasn't attempted. Still flagged UNCLEAR in
  SKILL.md — this run doesn't resolve it either way.

## Prerequisites

- A snippet plugin with PHP execute permission, active on the same site as a real
  JetFormBuilder form (any simple form with 1-2 fields + a "Call Hook" action step is
  enough for most tests below).
- A debug log sink reachable via REST — e.g. write test output with
  `error_log( '[JFB-TEST] ' . wp_json_encode( $data ) )` to a dedicated file
  (`WP_DEBUG_LOG` to a custom path, or a tiny custom endpoint that tails it), then
  expose `GET /wp-json/jfb-test/v1/log` to read the last N lines back over HTTP.

## Test 1: `after-send` fires with 2 args, after DB save

**Claim:** `jet-form-builder/form-handler/after-send` (`form-handler.php:363`) fires
last, passes `($this, $is_success)`, and DB records ("Save Form Record") already exist
by the time it fires.

**Setup:** On a form with "Save Form Record" enabled, add:
```php
add_action( 'jet-form-builder/form-handler/after-send', function( $handler, $is_success ) {
    error_log( '[JFB-TEST] after-send fired. is_success=' . var_export( $is_success, true )
        . ' handler_class=' . get_class( $handler ) );
}, 10, 2 );
```

**Trigger:** Submit the form successfully once, then submit it again in a way that
forces a failure (e.g. omit a required field, if validation still lets it reach this
hook — otherwise force failure via a Call Hook step that throws `Action_Exception`).

**Expected observable:** Log line appears both times; `is_success` is `true` on the
first, and the value depends on how failure was forced on the second (see Test 4 for
why this isn't always `false`). Confirm via the DB that the form-record row exists
*before* this hook fires (query it inside the callback itself, or check timestamps).

**Pass criteria:** Log line present with both args populated (not `NULL` for the 2nd
arg — that would indicate the claimed 2-arg signature is wrong).

## Test 2: `before-do-action/{action_id}` is per-action-type and fires pre-Conditions-pass

**Claim:** `do_action( "jet-form-builder/before-do-action/{$action->get_id()}", $action )`
(`action-handler.php:220`) fires only after Conditions pass, before that specific
action type executes.

**Setup:** On a form with a "Call Hook" action step (id `call_hook`) gated by a
Condition that's sometimes false:
```php
add_action( 'jet-form-builder/before-do-action/call_hook', function( $action ) {
    error_log( '[JFB-TEST] before-do-action/call_hook fired, action_id=' . $action->get_id() );
}, 10, 1 );
```

**Trigger:** Submit once with the Condition true, once with it false.

**Expected observable:** Log line present on the true-Condition submission, absent on
the false one.

**Pass criteria:** Presence/absence matches the Condition state exactly.

## Test 3: Call Hook filter signature is `($result, $request, $handler)`, `10,3`

**Claim:** `jet-form-builder/custom-filter/<hook_name>` passes 3 args total; return
value controls step success/failure.

**Setup:** Add a Call Hook step named `test_hook`, then:
```php
add_filter( 'jet-form-builder/custom-filter/test_hook', function( $result, $request, $handler ) {
    error_log( '[JFB-TEST] custom-filter fired. result=' . var_export( $result, true )
        . ' request_keys=' . implode( ',', array_keys( (array) $request ) )
        . ' handler_class=' . get_class( $handler ) );
    return false; // force this step to report failure
}, 10, 3 );
```

**Expected observable:** Log line shows `result=true` (the default passed in),
`request_keys` includes your form's actual field names, `handler_class` is the
Form_Handler class. Form response should reflect the step as failed since we returned
`false`.

**Pass criteria:** All 3 args present and correctly typed; returned `false` visibly
changes form behavior (response status/hook_result).

## Test 4: `Action_Exception` thrown from Call Hook can still register as "success"

**Claim:** `is_success` on the exception, not "was an exception thrown", determines the
form's success state (`form-handler.php:280-304`, referencing Crocoblock/jetformbuilder#334).

**Setup:** In a Call Hook filter, throw:
```php
throw new \Jet_Form_Builder\Exceptions\Action_Exception( 'Test message', 200, true ); // check real constructor signature on the sandbox first
```
(Confirm the actual `Action_Exception` constructor signature on the sandbox before
writing this — the SKILL.md doesn't pin it down exactly.)

**Trigger:** Submit the form so this filter runs.

**Expected observable:** Compare form response's success/fail state against what the
exception's `is_success()` was constructed with, not merely "an exception was thrown".

**Pass criteria:** If constructed with `is_success = true`, the form should still
report overall success in `after-send`'s 2nd arg despite the thrown exception.

## Test 5: No behavior difference between block-based and legacy shortcode forms

**Claim:** SKILL.md flags this as *unconfirmed* — the lifecycle hooks appeared to share
code paths but this wasn't exhaustively traced.

**Setup:** Duplicate the same form/hook setup from Test 1 on both a block-editor form
and a legacy shortcode form, if the sandbox has both.

**Expected observable:** Identical hook firing order and arg values on both.

**Pass criteria:** Any divergence found should be added to the SKILL.md as a
correction — this test exists specifically to either confirm or overturn that
"no difference found" note.
