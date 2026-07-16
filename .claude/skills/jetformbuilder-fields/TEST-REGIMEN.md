# Test regimen: jetformbuilder-fields

Validates claims in `SKILL.md`. Not yet run — written source-cited-only on 2026-07-16.
Run against the sandbox site (`jackfruit.epeak.studio`, JetFormBuilder installed, per
the `jetformbuilder-hooks` regimen's existing fixtures — a test form with at least one
repeater field would need to be added for Test 2).

## Test 1 (not yet run): `jet_fb_context()->get_value()` reads submitted values inside a Call Hook

**Claim:** `jet_fb_context()` is the correct generic accessor for a submitted field's
value, usable identically inside a custom action and a Call Hook callback.

**Setup:** add a Call Hook action to an existing test form (reuse the fixture from
`jetformbuilder-hooks`' regimen if present), calling:
```php
add_action( 'jet-form-builder/custom-hook-name', function() {
    error_log( '[JFB-FIELDS-TEST] value=' . jet_fb_context()->get_value( 'some_field_name' ) );
} );
```

**Expected observable:** logged value matches whatever was actually typed into
`some_field_name` on submission.

**Pass criteria:** value matches exactly; confirms the accessor works without needing
the raw `$request` array passed to the action.

## Test 2 (not yet run): repeater fields resolve via dotted path, not bracket syntax

**Claim:** `jet_fb_context()->get_value( 'repeater_name.0.sub_field' )` is the correct
addressing scheme; `repeater_name[0][sub_field]` is not.

**Setup:** on a test form with one repeater field (`items`) containing a sub-field
(`label`), submit one row, then in a Call Hook log both:
```php
error_log( '[JFB-FIELDS-TEST] dotted=' . jet_fb_context()->get_value( 'items.0.label' ) );
error_log( '[JFB-FIELDS-TEST] bracket=' . jet_fb_context()->get_value( 'items[0][label]' ) );
```

**Expected observable:** `dotted` returns the submitted value, `bracket` returns
empty/null.

**Pass criteria:** confirms the dotted-path claim exactly as written.

## Test 3 (not yet run): `jet-form-builder/blocks/register` is the real custom-field-type hook

**Claim:** registering a class extending `Blocks\Types\Base` via this action makes a
new field block type available in the editor.

**Setup:**
```php
add_action( 'jet-form-builder/blocks/register', function( $module ) {
    error_log( '[JFB-FIELDS-TEST] blocks/register fired' );
} );
```

**Expected observable:** log line fires on every relevant page load; confirms the hook
exists and fires (a full custom block-type registration is a heavier follow-up, not
required just to confirm the hook fires).

**Pass criteria:** hook fires as documented.

## Test 4 (not yet run): no validation-rule registration filter exists

**Claim:** `Rules_Controller::rep_instances()` hardcodes the rule list with no
`apply_filters` wrapper — there's no way to add a rule class via a filter.

**Setup:** grep the installed plugin's `modules/validation/rules-controller.php` for
`apply_filters` near `rep_instances()`; separately, try
`add_filter( 'jet-form-builder/register-validation-rule', ... )` (or similarly-named
guesses) and confirm no such filter is ever called (e.g. via a global filter-logging
snippet that logs every `do_action`/`apply_filters` call containing "validation").

**Expected observable:** no filter call sites found; the guessed filter name(s) never
fire.

**Pass criteria:** confirms the "no extension filter exists" claim; if a real filter
is found (e.g. added in a JetFormBuilder version newer than what was read), correct
`SKILL.md`.

## Test 5 (not yet run): no REST route exists for form submission

**Claim:** submission goes through `wp_ajax_jet_form_builder_submit`/`wp_loaded`, not a
`register_rest_route()`-based endpoint.

**Setup:** `GET /wp-json/jet-forms/v1` (and any other plausible JFB REST namespace
guess) and confirm a 404/no matching namespace; separately confirm the site's
`wp-json` index has no submission-related JFB route at all (only form-record
management / rest-validation-endpoint routes, if any).

**Pass criteria:** no submission REST route found, confirming the claim; note any JFB
REST namespaces that *do* exist for completeness.

## Test 6 (not yet run): Form Records live in a custom DB table, not `wp_posts`

**Claim:** `Query_Builder`/`Base_Db_Model` read/write a dedicated custom table for
stored submissions.

**Setup:** submit a test form with "save submission" enabled (if that's a per-form
setting), then `SHOW TABLES LIKE '%jet_form%'` or similar, and cross-check via
`Query_Builder::query_all()` against a plain `$wpdb` read of the same table.

**Pass criteria:** confirms a custom table exists and `Query_Builder` reads it
correctly, not a `wp_posts`/CPT-backed store.

## Cleanup note

No fixtures created yet for this skill. Any test form/repeater/Call Hook added to run
these should be clearly `AGENT-TEST`-namespaced and kept per this repo's convention.
