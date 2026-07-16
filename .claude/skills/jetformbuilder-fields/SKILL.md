---
name: jetformbuilder-fields
description: Use when you need to read or write a submitted field's value generically (including nested repeater values by dotted path), register a brand-new field block type, understand how a field's raw POST value becomes a typed value (field parsers), add custom field validation, wire a preset/dynamic default value, or read back previously-submitted form entries from storage. Captures verified behavior of `Jet_Form_Builder\Request\Parser_Context`, the block-parsers module, presets, and form-record storage from JetFormBuilder 3.6.3.1 source, and live-verified via tests.php. See TEST-REGIMEN.md.
license: MIT
metadata:
  author: project
  version: "0.2.0"
---

# JetFormBuilder field data, custom fields, validation, presets, and stored records

**Live-verified (2026-07-16):** this skill now has a runnable suite (`tests.php`, 7
tests, `jfb-1` through `jfb-7`) per `docs/test-harness-guide.md` — 7/7 pass on first
live run, no corrections needed this round. See `TEST-REGIMEN.md` for the run log and
what's still fixture-blocked (a real form submission, repeater dotted-path resolution
against live data, a full custom field block type).

`jetformbuilder-actions` and `jetformbuilder-hooks` cover the action/hook lifecycle,
but the single most load-bearing API for actually reading/writing a submitted value —
used identically inside both custom actions and Call Hook callbacks — isn't documented
there. This skill covers that plus the field-type extension points and where submitted
data actually lives afterward.

## Reading/writing a field value generically: `jet_fb_context()` / `Parser_Context`

Don't reach for `$_POST` directly, and don't assume a `Form_Handler` getter exists —
the real, general-purpose accessor is a global context object with dotted-path support
for nested fields (repeaters):

```php
$ctx = jet_fb_context(); // includes/functions.php:104

$value = $ctx->get_value( 'field_name' );              // parser-context.php:219
$ctx->update_request( $new_value, 'field_name' );       // parser-context.php:245
$raw   = $ctx->get_request( 'field_name' );             // parser-context.php:302
$has   = $ctx->has_field( 'field_name' );                // parser-context.php:653
$type  = $ctx->get_field_type( 'field_name' );           // parser-context.php:464
```

Core classes: `Jet_Form_Builder\Request\Parser_Context` (block-parser-adjacent, actual
file `modules/block-parsers/parser-context.php`) and the per-field-type
`JFB_Modules\Block_Parsers\Field_Data_Parser` (`modules/block-parsers/field-data-parser.php`)
that `get_value()`/`set_value()` (`field-data-parser.php:336,187`) delegate to.

**Repeater fields use a dotted path, not array-index bracket syntax.** A field named
`items` inside a repeater is addressed as `items.0.field_name`, e.g.:

```php
$first_row_value = jet_fb_context()->get_value( 'my_repeater.0.sub_field' );
```

Don't write `my_repeater[0][sub_field]` expecting it to resolve — that's the raw POST
field-name shape, not the accessor's addressing scheme. `Multiple_Parsers`/
`Exclude_Self_Parser` interfaces (`modules/block-parsers/interfaces/`) control how
repeater/group fields fan out internally, but callers only need the dotted-path form
above.

## Registering a custom field block type

There is no `register_field_type()` filter (agents commonly invent one by analogy to
ACF/Elementor) — registration is action + repository based:

```php
add_action( 'jet-form-builder/blocks/register', function( $module ) {
    $module->register_block_type( new My_Custom_Field_Block_Type() ); // module.php:121
} );
```

The class extends `Jet_Form_Builder\Blocks\Types\Base` (`includes/blocks/types/base.php`)
and implements rendering/attribute methods (`get_name()`, `jsm_controls()`, per the
pattern in `modules/blocks-v2/repeater-field/block-type.php:38,52`). The hook fires
from `Module::register_block_type()`/`register_default_block_type()`
(`includes/blocks/module.php:111,121,132`), which install the type into an internal
repository (`rep_install_item_soft()`), same repository pattern as custom actions in
`jetformbuilder-actions`.

**A field block *type* and its *value parser* (below) are two separate registrations
that agents often conflate** — a custom field type still needs a matching parser if
its raw-to-typed value conversion differs from the default.

## Field parsers: how raw POST becomes a typed value

Each built-in field type has its own parser under `modules/block-parsers/fields/`:
`Default_Parser`, `Text_Field_Parser`, `Date_Field_Parser`, `Media_Field_Parser`,
`Repeater_Field_Parser`, `Wysiwyg_Field_Parser`, `Hidden_Field_Parser` — all extend
`Field_Data_Parser` and override `set_value()`/`get_value()` for their own
sanitization/shape. Registered per-type via `Module::get_parser( $type )`
(`modules/block-parsers/module.php`). If a custom field type's value needs anything
beyond plain-string handling (e.g. an array, a date object, an attachment ID), write a
matching parser — don't assume the default parser's plain-string behavior applies.

## Custom validation rules — no public registration filter exists (real gap)

Unlike field types, **there is no documented extension filter for adding a validation
rule class**. `JFB_Modules\Validation\Rules_Controller::rep_instances()`
(`modules/validation/rules-controller.php:20`) hardcodes the built-in rule list with no
`apply_filters` around it. An agent asked to "add a custom validation rule" will likely
hallucinate a `jet-form-builder/register-validation-rule`-style filter — **it does not
exist**. The two real options are:

1. Hook the per-field `jet-form-builder/validate-field` filter (see
   `jetformbuilder-hooks` for its signature) and throw/return an error from there — a
   materially different shape than a standalone "rule class."
2. Subclass/replace `Rules_Controller` itself (heavier, only worth it if you need the
   rule to appear in the editor's rule picker UI, not just to enforce validation).

`JFB_Modules\Validation\Advanced_Rules\Rule` (`modules/validation/advanced-rules/rule.php`)
is the abstract shape built-in rules follow (`validate_field( Field_Data_Parser $parser )`,
`get_id()`, `get_label()` — `rule.php:24,20,22`) if you do go the subclass route.

## Presets & dynamic default values

"Preset" (auto-filling a field on page load from post/user/query data, editor-configured)
is a different mechanism from a plain static default value — don't invent a single
`set_default()`-style API:

- `Jet_Form_Builder\Presets\Preset_Manager` — dispatches to preset type classes.
- Abstract `Types\Base_Preset` (`includes/presets/types/base-preset.php`):
  `get_fields_map()` (`:33`), `get_slug()` (`:40`), `is_active_preset( $args )` (`:49`).
- `get_source( $args = [] )` (`base-preset.php:69`) returns a `Sources\Base_Source`
  instance that actually fetches the prefill value — Query/CCT/post-meta/user-meta
  sources are separate `Base_Source` subclasses under `includes/presets/sources/`. The
  preset class decides *which fields* get prefilled; the source class decides *where
  the value comes from* — both pieces are needed for a custom preset.

## Reading previously-submitted entries: custom DB tables, not WP posts

Agents asked "how do I read past form submissions" usually invent a CPT-based query —
JetFormBuilder actually stores submissions ("Form Records") in **custom DB tables**:

- `Jet_Form_Builder\Db_Queries\Query_Builder` (`includes/db-queries/query-builder.php`):
  `query_all()` (`:232`), `query_one()` (`:271`), `query_var()` (`:285`).
- `Base_Db_Model` (`includes/db-queries/base-db-model.php`): `insert()` (`:155`),
  `update()` (`:204`), `delete()` (`:223`).
- `modules/form-record/models/record-model.php`'s `Record_Model` is the concrete model
  for stored submissions; matching REST endpoints for the admin UI live under
  `modules/form-record/rest-endpoints/*` (fetch/delete/mark-viewed).

**There is no REST route for the submission itself** — agents frequently assume
something like `POST /wp-json/jet-forms/v1/submit` exists; it doesn't. Submission is
handled via classic AJAX/`wp_loaded`: `Jet_Form_Builder\Form_Handler`
(`includes/form-handler.php:79-90`) hooks `wp_loaded`/`wp_ajax_jet_form_builder_submit`
directly, not a `register_rest_route()` call.

**Not covered here, flagged for a future pass:** the Payment Gateways module
(`modules/gateways/*` — base gateway action, PayPal-specific API actions, DB-backed
payment records) is a distinct, non-trivial subsystem with its own action/DB-model
surface, not yet inventoried in depth.

## How this was verified

Read `includes/functions.php`, `modules/block-parsers/{module,parser-context,field-data-parser}.php`,
`modules/block-parsers/fields/*.php`, `includes/blocks/{module,types/base}.php`,
`modules/blocks-v2/repeater-field/block-type.php`,
`modules/validation/{module,rules-controller,advanced-rules/rule}.php`,
`includes/presets/{preset-manager,types/base-preset}.php`, `includes/presets/sources/*.php`,
`includes/db-queries/{query-builder,base-db-model}.php`,
`modules/form-record/models/record-model.php`, and `includes/form-handler.php` in
JetFormBuilder 3.6.3.1 source, confirming method signatures, hook names, and the
"no validation-rule filter exists" / "no submission REST route exists" negative
findings by direct file:line citation. Not yet run against a live site — see
`TEST-REGIMEN.md`.
