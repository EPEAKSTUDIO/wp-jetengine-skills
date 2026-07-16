---
name: jetformbuilder-hooks
description: Use when you need to run custom PHP against a JetFormBuilder form submission without writing a full custom action class — e.g. validating/mutating field values before an action runs, reacting after a submission succeeds or fails regardless of which actions ran, or wiring a "Call Hook" form-editor step to your own function. Captures real, verified hook names/order/signatures from JetFormBuilder 3.6.3.1 source.
license: MIT
metadata:
  author: project
  version: "0.3.0"
---

# JetFormBuilder Hooks

**Live-verified (2026-07-16):** this skill now has a runnable suite (`tests.php`, 3
tests, `hooks-1` through `hooks-3`) per `docs/test-harness-guide.md` — 3/3 pass on first
live run. One addition beyond the 2026-07-15 manual run below: `dynamic_success()`/
`dynamic_error()` are the real mechanism behind "a thrown exception can still register as
success" — see the updated gotcha below and `TEST-REGIMEN.md`.

Verified facts about JetFormBuilder's submission lifecycle: what fires, in what order,
with what arguments — and the one built-in mechanism (`Call Hook` action) that lets you
run arbitrary PHP against a submission **without** writing a custom action class (see
`jetformbuilder-actions` for that heavier path). Confirmed against JetFormBuilder
3.6.3.1 source, cross-checked against a real multi-step form built on top of it.

## Submission lifecycle, in firing order

1. `do_action( 'jet-form-builder/request' )` — `includes/request/request-handler.php:35`,
   fired as soon as form field definitions load, before request data is parsed.
2. `do_action( 'jet-form-builder/request-handler/before-init', $this )` —
   `modules/block-parsers/module.php:103`.
3. `apply_filters( 'jet-form-builder/request-handler/request', $request )` —
   `modules/block-parsers/module.php:112`. **This is the earliest point to inspect or
   mutate the whole submitted `$request` array**, before it's bound to field
   definitions. You can throw `Spam_Exception` here to reject the submission outright.
4. `do_action( 'jet-form-builder/form-handler/before-send', $this )` —
   `includes/form-handler.php:320`, right before any actions run. `$this` is the
   `Form_Handler` instance.
5. Actions execute (DEFAULT.PROCESS event):
   - `do_action( 'jet-form-builder/actions/before-send' )` — no args, once before the
     whole action loop.
   - Per action step: `do_action( "jet-form-builder/before-do-action/{$action->get_id()}", $action )`
     — `includes/actions/action-handler.php:220`, fired right before that specific
     action type runs, only after its Conditions passed. **The hook name is dynamic**
     (interpolates the action type ID, e.g. `insert_post`, `call_hook`) — you must know
     the specific action type to hook it, and the callback receives the `$action`
     object, not the raw request.
   - `do_action( 'jet-form-builder/actions/after-send' )` — no args, once after the loop.
6. The DEFAULT.REQUIRED event runs next (this is what "Save Form Record" hangs off) —
   **always fires, regardless of errors or unsuccessful actions**. It's an internal
   event class, not a public hook name you `add_action` to.
7. `do_action( 'jet-form-builder/form-handler/after-send', $this, $this->is_success )` —
   `includes/form-handler.php:363`. Fires **last**, after DB records are already saved.
   This is the real "after everything, success-or-fail" hook. **Takes 2 args** — a
   callback registered with the default `add_action` arg count (1) will silently miss
   the success flag; register with `10, 2`.

## Filtering which actions actually run (DEFAULT.PROCESS executors)

Step 5 above ("Actions execute") is really one "event" object,
`Default_Process_Event` (`includes/actions/events/default-process/default-process-event.php`),
whose `executors()` method is what actually returns the list of things to run:

```php
public function executors(): array {
    return apply_filters(
        'jet-form-builder/default-process-event/executors',
        array( new Default_Process_Executor() )
    );
}
```

The filter receives/returns an **array of executor objects**, not action instances or
IDs directly — by default just one `Default_Process_Executor` that walks all configured
action steps. This is the extension point real snippets use to conditionally strip out
an executor, e.g. skipping the Payment Gateways module's checkout-redirect executor when
a price field is `0`: filter the array, `instanceof`/class-name-check each entry, and
`unset()` the ones you don't want. This is a coarser lever than
`before-do-action/{action_id}` (step-scoped) or action Conditions (per-step, see
`jetformbuilder-actions`) — it operates on the whole executor list before any action
runs.

**Payment Gateways module** (`modules/gateways/module.php`) is a real JetFormBuilder
subsystem (PayPal/Stripe checkout wired as a special action executor) that isn't
inventoried in depth by any of these three skills yet — `jet_form_builder()->module( 'post-type' )->get_gateways()`
is one real accessor into its config, seen filtering the executors list above. Treat
anything beyond "the executors filter exists and gateway config is reachable via that
accessor" as unverified; a deeper dive is open work.

## Hooking a form action step without writing a custom action class

JetFormBuilder ships a built-in **"Call Hook"** action type
(`modules/actions-v2/call-hook/call-hook-action.php`). Add it as an action step in the
form editor, give it a `hook_name`, and it fires:

```php
do_action( 'jet-form-builder/custom-action/' . $hook_name, $request, $handler );

$handler->response_data['hook_result'] = apply_filters(
    'jet-form-builder/custom-filter/' . $hook_name,
    true, $request, $handler
);
```

You then hook your own code against the filter name:

```php
add_filter( 'jet-form-builder/custom-filter/validate_promo_code', 'my_jfb_validate_promo_code', 10, 3 );
function my_jfb_validate_promo_code( $result, $request, $action_handler ) {
    $code = sanitize_text_field( trim( $request['promo_code'] ?? '' ) );
    if ( empty( $code ) ) { return $result; } // soft-skip, no-op
    // ... validate, may throw Action_Exception to fail the step
    return $result;
}
```

Signature is always `function( $result, $request, $action_handler )` registered with
`add_filter( ..., 10, 3 )` — the filter's own return value (`true`/`false`, defaulting
to `true`) is what marks the step as succeeded/failed to the form response.

**Step ordering is controlled entirely by the order of action steps in the form editor
UI, not by PHP hook priority.** Chaining several Call Hook steps (each with its own
`hook_name`, e.g. `validate_promo_code` → `apply_discount` → `send_confirmation`) as
separate ordered steps on the same form works because each fires at its own point in
the action loop — there is no hook-priority mechanism between them.

## Gotchas (verified from real signatures, not assumed)

- `jet-form-builder/form-handler/after-send` passes 2 args (`$this, $this->is_success`)
  — register with `add_action( ..., 10, 2 )`.
- `jet-form-builder/custom-filter/*` / `jet-form-builder/custom-action/*` pass
  `$request, $handler` after the filter's own leading value — 3 total args for the
  filter, `add_filter( ..., 10, 3 )`.
- `jet-form-builder/action/after-post-*` and `after-term-*` (fired only from Insert
  Post / Insert Term action instances, not globally) pass **3 args**:
  `$current_action, $action_handler, $this` — here `$this` is the post/term action
  instance, **not** the form handler. Don't confuse it with the after-send `$this`.
- `jet-form-builder/before-do-action/{action_id}` is per-action-type, not generic —
  the hook name only exists once you know which action type ID to interpolate.
- `jet-form-builder/actions/before-send` / `after-send` (wrapping the whole action
  loop) take **zero args** — useful only as global markers, not for request data.
- Throwing `Action_Exception` from a Call Hook filter or a `before-do-action` callback
  is caught in `Form_Handler::try_send()` and converted into a form response — but
  `is_success` comes from `$exception->is_success()`, meaning a thrown exception can
  still register as "success" depending on how it's constructed. Don't assume "threw an
  exception" always means "form failed" — check what `is_success()` the exception
  actually returns. **Pinned down exactly (2026-07-16, live-verified via `tests.php`
  hooks-1/hooks-2): `is_success()` is `true` only for the literal, case-sensitive string
  `'success'`** as the exception's message — any other message is `false` by default. To
  make an arbitrary custom message still count as success/failure, the real mechanism is
  `->dynamic_success()`/`->dynamic_error()` on the exception (defined on the parent
  `Handler_Exception`): these prefix the message (`'dsuccess|'`/`'derror|'`) so
  `Status_Info` classifies it via a registered "dynamic type" instead of requiring the
  literal string — e.g. `throw ( new Action_Exception( 'promo code invalid' ) )->dynamic_error();`
  still fails the form even though the message isn't `'failed'`.
- I found **no evidence** the lifecycle above differs between block-editor forms and
  legacy shortcode forms (shared code path in `form-handler.php`/`action-handler.php`);
  I did not trace every legacy code path exhaustively, so treat "no difference" as
  unconfirmed rather than proven.

## How this was verified

Traced the real call chain in JetFormBuilder 3.6.3.1 source: `form-handler.php` →
`includes/request/request-handler.php` → `modules/block-parsers/module.php` →
`includes/actions/action-handler.php` → `includes/actions/events/default-process/*` —
grepping every `do_action`/`apply_filters` call and reading the surrounding function to
confirm arg counts and firing order. Cross-checked against a real multi-step form with
several distinct `jet-form-builder/custom-filter/*` registrations chained as separate
Call Hook steps, confirming the Call Hook mechanism and its `10, 3` signature match what
ships in every real callback, and that step ordering is managed via the form editor
rather than hook priority. Additionally re-verified live via the
`jetformbuilder-hooks/TEST-REGIMEN.md` run — see that file for the executed results.
