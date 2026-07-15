# Test regimen: jetformbuilder-actions

Validates claims in `SKILL.md`. Run against the sandbox site (JetFormBuilder 3.6.3.1).

## Run log — 2026-07-15, executed against jackfruit.epeak.studio

Same live-production caveat and fixture as `jetformbuilder-hooks/TEST-REGIMEN.md`: no
sandbox existed, so a throwaway form (id 353) + host page (id 354) were built for this
run and should be deleted afterward. Submission was via real end-to-end POSTs to the
form's rendered `submit-type-reload` action URL (curl, no browser) — see the hooks
regimen's run log for the exact mechanism.

- **Test 1 (minimal custom action registers, no Module wrapper needed): PASS,
  functionally.** Registered `Agent_Test_Log_Action` (id `agent_test_log_action`) purely
  via `add_action( 'jet-form-builder/actions/register', ... )` +
  `register_action_type()`, added it as a step on the test form, submitted, and its
  `do_action()` ran (logged). **Not confirmed:** whether it visually appeared in the
  form-editor action picker — no browser was available this run, so the "appears in the
  editor UI" half of the claim is UNCLEAR, only the functional-execution half is PASS.
- **Test 2 (`$request` is resolved, not raw `$_POST`): PASS (via the hooks regimen's
  Test 3 evidence, not a dedicated repeater test).** The logged `$request` for a
  one-field form was exactly `test_field,__form_id,__refer,__is_ajax` — none of the raw
  POST body's WordPress/Wordfence/JFB plumbing fields (`_wpnonce`,
  `_wp_http_referer`, `__queried_post_id`, `_jfb_current_render_states[]`) leaked
  through, confirming it's a filtered/normalized set rather than raw `$_POST`. The
  repeater-field variant of this test (comparing shapes with 2+ repeater rows) was
  **not run** — no repeater field was added to the throwaway form this session.
- **Test 3 (`Action_Exception` message drives both status and is_success): PASS** —
  same finding as the hooks regimen's Test 4: `Action_Exception('success')` produced
  `status=success` in the redirect and `is_success=true` in `after-send`, confirming the
  message string is both the form's `status` value and the success determinant (exact
  mechanism: it must equal the literal string `'success'`, see hooks regimen notes).
- **Test 4 (silent ID collision overwrites a built-in action, e.g. `redirect_to_page`):
  PASS, confirmed carefully.** Registered a second action class also claiming id
  `redirect_to_page` (priority 20, logging only, no real redirect) in a **separate
  snippet kept inactive except for the single submission needed to test it** — pointed
  only the throwaway test form at `redirect_to_page`, activated the override snippet,
  submitted once, immediately deactivated it. Result: the override's `do_action()` ran
  (logged) and the response redirected back to the *same* host page with
  `?status=success` rather than to the configured target page — confirming the real
  built-in redirect never fired, i.e. the overwrite is silent as claimed. Kept this
  window as short as possible since this snippet is active globally and would have
  intercepted `redirect_to_page` on any other form on the site while active (none exist
  yet on this site, but would matter on a busier install).
- **Test 5 (registration timing, `init` priority 99, "too late" dependence() check): NOT
  TESTED.** Not attempted this run — lower priority than confirming the core
  hooks/exception semantics, and constructing a deliberately-late `dependence()` check
  adds another moving part. Still open.

## Prerequisites

- A snippet plugin with PHP execute permission (this is where the custom action class
  + registration code will live for testing).
- A simple test form with 1-2 fields, editable to add a new action step once the
  custom action registers.
- Debug log sink (see `docs/test-regimen-guide.md`).

## Test 1: minimal custom action registers and appears in the editor

**Claim:** hooking `jet-form-builder/actions/register` and calling
`$manager->register_action_type( new My_Action() )` is sufficient — no Module wrapper
needed.

**Setup:**
```php
add_action( 'jet-form-builder/actions/register', function( $manager ) {
    class Test_Log_Action extends \Jet_Form_Builder\Actions\Types\Base {
        public function get_id() { return 'test_log_action'; }
        public function get_name() { return 'Test Log Action'; }
        public function do_action( array $request, $handler ) {
            error_log( '[JFBA-TEST] test_log_action ran. request=' . wp_json_encode( $request ) );
        }
    }
    $manager->register_action_type( new Test_Log_Action() );
} );
```

**Trigger:** open the form editor, add an action step, check if "Test Log Action"
appears in the picker.

**Expected observable:** action appears in the editor UI without any additional
Module/Integration boilerplate.

**Pass criteria:** action is selectable and savable as a step.

## Test 2: `$request` is fully resolved, not raw `$_POST`

**Claim:** `do_action()`'s `$request` param comes from `jet_fb_context()->resolve_request()`,
already flattened/normalized (not raw superglobal shape).

**Setup:** use the action from Test 1 on a form that includes a repeater field; submit
with 2+ repeater rows filled in.

**Expected observable:** the logged `request` JSON shows repeater values in whatever
normalized shape JetFormBuilder uses (compare against what raw `$_POST` would have
looked like for the same form, e.g. via a separate `add_action('jet-form-builder/request')`
logger).

**Pass criteria:** confirm the shape differs from raw POST in a way consistent with
"already resolved" (exact shape TBD — record what's actually observed).

## Test 3: `Action_Exception` message drives both status and `is_success`

**Claim:** the exception's first constructor arg becomes the form's `status`, and
`is_success()` (not "was an exception thrown") determines success/fail.

**Setup:**
```php
class Test_Fail_Action extends \Jet_Form_Builder\Actions\Types\Base {
    public function get_id() { return 'test_fail_action'; }
    public function get_name() { return 'Test Fail Action'; }
    public function do_action( array $request, $handler ) {
        throw new \Jet_Form_Builder\Exceptions\Action_Exception( 'custom_test_status' );
    }
}
add_action( 'jet-form-builder/actions/register', function( $manager ) {
    $manager->register_action_type( new Test_Fail_Action() );
} );
add_action( 'jet-form-builder/form-handler/after-send', function( $handler, $is_success ) {
    error_log( '[JFBA-TEST] after-send is_success=' . var_export( $is_success, true ) );
}, 10, 2 );
```

**Trigger:** add this action to a form step, submit.

**Expected observable:** form response's `status` field is `custom_test_status`; log
shows whatever `is_success` value the exception produces by default with a single
string arg.

**Pass criteria:** confirms (or corrects) the claimed message→status wiring and default
success classification.

## Test 4: silent ID collision overwrites a built-in action

**Claim:** registering a custom action with `get_id() === 'redirect_to_page'` (an
existing built-in) silently overwrites it, no error.

**Setup:** register a custom action reusing `'redirect_to_page'` as its id, with an
obviously different `do_action()` body (e.g. just `error_log(...)`, no actual
redirect).

**Trigger:** submit a form that has an existing "Redirect to Page" step configured.

**Expected observable:** the log line from the custom action's `do_action()` appears
instead of an actual redirect happening — confirming the overwrite.

**Pass criteria:** if the built-in redirect still happens instead, the "silent
overwrite" claim is wrong and needs correcting (would mean some rewrite guard exists
that wasn't found in source).

## Test 5: registration timing — `init` priority 99

**Claim:** the `jet-form-builder/actions/register` hook fires at `init` priority 99;
code depending on something not yet loaded by then misses the window.

**Setup:** register a custom action from a callback that depends on a value only set
later (e.g. `add_action('wp_loaded', ...)` sets a flag your action's `dependence()`
checks) — deliberately create the "too late" scenario.

**Expected observable:** action does not appear in the editor because `dependence()`
returned false at registration time despite the flag being true later.

**Pass criteria:** confirms priority-99 timing matters in practice, not just in theory.
