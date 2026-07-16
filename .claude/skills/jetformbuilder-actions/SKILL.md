---
name: jetformbuilder-actions
description: Use when writing a fully custom JetFormBuilder action class (a PHP class that runs as a form-editor action step) — as opposed to the lighter "Call Hook" mechanism (see jetformbuilder-hooks). Covers the real base class, registration, reading submitted field values, and signaling success/failure. Captures verified behavior from JetFormBuilder 3.6.3.1 source.
license: MIT
metadata:
  author: project
  version: "0.2.0"
---

# JetFormBuilder Custom Actions

**Live-verified (2026-07-16):** this skill now has a runnable suite (`tests.php`, 3
tests, `act-1` through `act-3`) per `docs/test-harness-guide.md` — 3/3 pass on first live
run, no corrections needed. Unlike the 2026-07-15 manual run below (curl against a
throwaway form), these tests call `Base`/`Action_Exception`/`Manager` directly — no form
submission needed. See `TEST-REGIMEN.md`.

How to write a real custom JetFormBuilder action type — a PHP class that shows up as a
step in the form editor's action list, like the built-in "Insert Post" or "Redirect to
Page" actions. If you just need to run arbitrary PHP against a submission without a
full class, use the "Call Hook" mechanism in `jetformbuilder-hooks` instead — it's far
less code for the common case. Verified against JetFormBuilder 3.6.3.1 source.

## Base class and required methods

`includes/actions/types/base.php`, abstract class `Base`:

```php
abstract class Base implements Repository_Item_Instance_Trait {
    abstract public function do_action( array $request, Action_Handler $handler );
    public function dependence() { return true; }        // gate: false = don't register at all
    public function is_disabled(): bool { return false; } // true = hide from editor picker
    public function on_register_in_flow() {}
}
```

Only `do_action()` is formally `abstract`, but every shipped action also defines
`get_id()` (unique action-type slug) and `get_name()` (editor label) — these aren't
enforced by the base class itself but are read by the repository system
(`rep_item_id()` calls `get_id()`) and the editor UI, so treat them as required in
practice. `action_attributes()` (base.php:133) declares the settings schema saved from
the form editor into `$this->settings`.

## Registration

Hook `jet-form-builder/actions/register` (fired on WP `init` priority **99** —
register later than that and you'll miss the window) and call
`$manager->register_action_type( new My_Action() )`:

```php
add_action( 'jet-form-builder/actions/register', function( $manager ) {
    $manager->register_action_type( new My_Custom_Action() );
} );
```

This is all a standalone custom action needs — the built-in actions additionally wrap
themselves in a Module/Integration class (`modules/actions-v2/module.php`) purely for
JetFormBuilder's own code organization, not because it's required.

**Gotcha:** ID collisions are silent. The repository's default `rep_allow_rewrite()`
returns `true`, so reusing an existing `get_id()` (e.g. `'insert_post'`) silently
overwrites the built-in action with no error. Use a distinctly namespaced ID.

## Reading submitted field values

`Action_Handler::process_single_action()` calls your action as:

```php
$action->do_action( jet_fb_context()->resolve_request(), $this );
```

`$request` is the **fully resolved/flattened submission array** (field name → value) —
not raw `$_POST`, already normalized for repeaters/nested fields. Two idiomatic ways to
read a value, both used by real shipped actions:

```php
// direct array access (register-user-action.php, mailchimp-action.php)
$login = $request[ $fields_map['login'] ];

// via the context object — dynamic-macro/repeater-safe (redirect-to-page-action.php)
$value = jet_fb_context()->get_value( $arg );
```

`$handler` (the `Action_Handler`) exposes a public `response_data` array for writing
output back to the front end, e.g. `$handler->response_data['redirect'] = $url;`.

## Signaling success or failure

Return normally from `do_action()` for success. Throw
`Jet_Form_Builder\Exceptions\Action_Exception` to fail:

```php
public function do_action( array $request, Action_Handler $handler ) {
    $to_url = $this->get_redirect_url();
    if ( ! $to_url ) {
        throw new Action_Exception( 'failed', $this->settings );
    }
    $handler->response_data['redirect'] = $this->get_completed_redirect_url( $to_url );
}
```

`Action_Exception`'s constructor is `__construct( $message = '', ...$additional_data )`
— **the message string doubles as the form's displayed `status` value** and drives
`is_success()` classification (see `jetformbuilder-hooks` for how `is_success` then
propagates to `after-send`). An empty-string message falls back to a generic `'failed'`
status — `throw new Action_Exception()` with no args still fails the form, just with no
specific message.

**Pinned down exactly (2026-07-16, live-verified via `tests.php` act-2): `is_success()`
is `true` only for the literal, case-sensitive string `'success'`** — any other message
(`'custom_test_status'`, `'Success'` with a capital S, etc.) is `false`, not just
"whatever seems truthy." To make an exception with an arbitrary custom message still
count as success/failure, use `->dynamic_success()`/`->dynamic_error()` (both defined on
the parent `Handler_Exception`) — these prefix the message (`'dsuccess|'`/`'derror|'`)
so `Status_Info` classifies it via a registered "dynamic type" instead of the literal
string, e.g. `throw ( new Action_Exception( 'promo code invalid' ) )->dynamic_error();`.

## `includes/actions/methods/` — a separate field-mapping framework (see also, not this skill)

Complex built-in actions (Insert Post, Insert Term, Register User, Update User) don't
map submitted fields to WP object properties themselves — they delegate to
`Abstract_Modifier` (`includes/actions/methods/abstract-modifier.php`), a distinct
framework for building a "map fields to object properties" editor UI. A minimal custom
action (the redirect-to-page pattern above) never touches this. Only reach for it if
you're building something that needs the same kind of field-mapping UI as Insert Post.

## Gotchas

- Registration fires at `init` priority 99 — code that depends on something not yet
  loaded by then will silently miss registration.
- `dependence()` returning `false` aborts registration entirely (no editor entry, no
  execution) — an easy thing to forget as the reason a conditional custom action "isn't
  showing up."
- `$request` is not raw `$_POST` — don't assume PHP superglobal shapes; it's already
  resolved/flattened.
- It's unconfirmed whether `get_id()`/`get_name()` are enforced anywhere beyond
  convention (e.g. a JS-side editor contract) — they aren't declared `abstract` in
  `Base`, only relied on implicitly.

## How this was verified

Read `includes/actions/types/base.php`, `includes/actions/manager.php`,
`includes/actions/action-handler.php`, and worked through two real action
implementations end-to-end — `modules/actions-v2/redirect-to-page/redirect-to-page-action.php`
(simple, minimal pattern used above) and `modules/actions-v2/insert-post/insert-post-action.php`
(complex, delegates to the methods/modifier framework) — in JetFormBuilder 3.6.3.1
source, confirming method signatures, the registration hook/priority, and exception
semantics by direct file:line citation. Not yet verified against a running site — see
`TEST-REGIMEN.md`.
