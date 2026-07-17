---
name: jetformbuilder-actions
description: Use when writing a fully custom JetFormBuilder action class (a PHP class that runs as a form-editor action step) — as opposed to the lighter "Call Hook" mechanism (see jetformbuilder-hooks). Covers the real base class, registration, reading submitted field values, and signaling success/failure. Captures verified behavior from JetFormBuilder 3.6.3.1 source.
license: MIT
metadata:
  author: project
  version: "0.4.0"
---

# JetFormBuilder Custom Actions

**Live-verified (2026-07-16):** this skill now has a runnable suite (`tests.php`, 5
tests, `act-1` through `act-5`) per `docs/test-harness-guide.md` — 5/5 pass. Unlike the
2026-07-15 manual run below (curl against a throwaway form), these tests call
`Base`/`Action_Exception`/`Manager` directly — no form
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

## Adding a custom Insert/Update Post "object property" — the real extension point for that field-mapping framework

The previous section says the `Abstract_Modifier` field-mapping framework is "see also,
not this skill" — but extending its **property list** is a genuinely common real-world
task (confirmed across many Codelab/Gist snippets: custom Post Slug, Post Password,
Menu Order, and Scheduled-Publish-Date properties for the Insert/Update Post action), so
it's documented here rather than left as an unexplained gap.

`Post_Modifier::get_properties()` (`modules/actions-v2/insert-post/properties/post-modifier.php:33-55`)
returns the list every Insert/Update Post action step maps fields against
(`Post_Id_Property`, `Post_Title_Property`, `Post_Status_Property`, `Post_Meta_Property`,
`Post_Terms_Property`, etc.), wrapped in:

```php
apply_filters( 'jet-form-builder/post-modifier/object-properties', new Object_Properties_Collection( [...] ) )
```

**`jet-form-builder/post-modifier/object-properties`** (filter, 1 arg: the
`Object_Properties_Collection`) is the real, public way to add a new mappable property —
call `->add( new My_Property() )` on the collection (a plain `Collection` method,
confirmed at `includes/classes/arrayable/collection.php:77` — not `push()`, which
doesn't exist on this class) and return it. A sibling filter,
**`jet-form-builder/post-modifier/object-actions`** (`post-modifier.php:57-60`), extends
the separate list of post-level *actions* (not properties) the same way.

A custom property class extends `\Jet_Form_Builder\Actions\Methods\Base_Object_Property`
(abstract: `get_label()`; `get_id()` comes from the `Collection_Item_Interface` contract)
— in practice, every real-world example found extends a concrete existing property
(commonly `Post_Title_Property`) rather than the abstract base directly, since that
picks up `get_id()`/other boilerplate for free and only needs overriding what's actually
different:

```php
class My_Post_Slug_Property extends \JFB_Modules\Actions_V2\Insert_Post\Properties\Post_Title_Property {
    public function get_id(): string { return 'post_name'; }
    public function get_label(): string { return __( 'Post Slug' ); }
    public function do_after( \Jet_Form_Builder\Actions\Methods\Abstract_Modifier $modifier ) {
        // runs after the value is set — e.g. wp_update_post() to apply a computed slug
    }
}

add_filter( 'jet-form-builder/post-modifier/object-properties', function( $properties ) {
    $properties->add( new My_Post_Slug_Property() );
    return $properties;
} );
```

`Base_Object_Property::do_before( $key, $value, $modifier )` / `do_after( $modifier )`
(`includes/actions/methods/base-object-property.php:46,50`) are the two hook points on
the property itself — `do_before()` runs when the field value is first attached (default
just stores it on `$this->value`), `do_after()` runs later, once the underlying object
exists (e.g. after `wp_insert_post()` — the right place for anything needing the new
post's real ID, like scheduling a future-publish `wp_schedule_single_event()` or writing
a computed value back via `$modifier->get_action()->get_inserted()`).

## Action conditions — gating whether an action step runs at all

Distinct from field validation and from the action's own success/failure logic: every
action can carry a `conditions` array + `condition_operator` (`'and'`/`'or'`) in its
editor settings (`$props['conditions']`, `$props['condition_operator']`) — the "Conditions"
panel on an action step in the form editor. `Action_Handler::process_single_action()`
builds one `Condition_Manager` per action id
(`includes/actions/action-handler.php:530-538`) and calls
`get_current_condition_manager()->check_all()` (`action-handler.php:206`) before running
the action's `do_action()`; a failed condition throws `Condition_Exception`, which skips
that action (does not fail the whole form) — see `condition-manager.php:115-142`.

The built-in operators (`equal`, `greater`, `less`, `between`, `one_of`, `contain`) are
checked in `Condition_Instance::check()`
(`includes/actions/conditions/condition-instance.php:231-259`) via a plain `switch`. If
none of those match, `check()` falls through to
`apply_filters( 'jet-form-builder/actions/process-condition', false, $this )`
(`condition-instance.php:257`) — **this is the extension point for a custom operator's
comparison logic**, receiving the `Condition_Instance` itself so you can call
`->get_operator()`, `->get_compare_as_array()`/`->get_compare()`, and
`->get_field_value()` (reads via `jet_fb_context()->get_value()`).

Registering a new operator name (so it shows up in the editor's operator dropdown) is a
**separate** filter — `jet-form-builder/register/action-condition-settings`
(`condition-manager.php:196-199`), which receives/returns the whole settings array
(`operators`, `compare_value_formats`, etc., default shape at `condition-manager.php:25-86`)
— append to `$settings['operators']`, with `'need_explode' => true` on the operator entry
if its compare value should be parsed as a comma-separated list
(`get_compare_as_array()`) rather than a scalar:

```php
add_filter( 'jet-form-builder/register/action-condition-settings', function( $settings ) {
    $settings['operators'][] = array(
        'label'        => 'Contains any',
        'value'        => 'contains_any',
        'need_explode' => true,
    );
    return $settings;
} );

add_filter( 'jet-form-builder/actions/process-condition', function( $result, $condition ) {
    if ( 'contains_any' !== $condition->get_operator() ) {
        return $result;
    }
    $field = (array) $condition->get_field_value();
    return (bool) array_intersect( $field, $condition->get_compare_as_array() );
}, 10, 2 );
```

Both filters are needed together — registering only the operator name makes it
selectable but silently `false` (falls through the `switch` default with no matching
custom logic); registering only the `process-condition` filter without adding the
operator name means the editor never offers it.

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
