# Test regimen: jetformbuilder-fields

Validates claims in `SKILL.md`. Run against the sandbox site (`jackfruit.epeak.studio`,
JetFormBuilder installed, per the `jetformbuilder-hooks` regimen's existing fixtures —
a test form with at least one repeater field would need to be added for Test 2).

## Run log — 2026-07-16: runnable suite added, 7/7 pass

This skill now has a **runnable suite** (`tests.php`, deployed as Code Snippets snippet
id 28, "AGENT-TEST-SUITE: jetformbuilder-fields") per `docs/test-harness-guide.md` — run
via `GET /agent-test/v1/suite/jetformbuilder-fields` (requires the always-active
AGENT-TEST-CORE harness, snippet id 22). No real form submission exists yet on this
site, so `jfb-1` through `jfb-7` exercise `jet_fb_context()` standalone (a fabricated
field name written via `update_request()` and read back via `get_value()`, rather than
a real `$_POST`) plus reachability checks for the block-registration hook, the
validation-rules controller, the form-records DB table, and the presets classes.

**Result: 7/7 pass** at run_at "2026-07-16 16:06:42", first live run, no fixes needed.
Notable: `jfb-6` confirmed the Form Records table exists with a real row count check
(`table_exists: true`) — this site uses a non-default `$wpdb` table prefix, so the
resolved table name is not the literal `wp_jet_fb_records` a naive guess would produce;
`Record_Model::table()` computed it correctly.

**What this run did NOT exercise** (still needs the fixtures below): a real form
submission, repeater-field dotted-path resolution against actually-submitted data
(Test 2), a custom field block type registered end-to-end (Test 3), and whether any
*other*, differently-named validation-rule filter exists beyond the one specific guess
`jfb-5` checked (Test 4's fuller version).

## Run log — 2026-07-16 (addendum): media-field guest-upload gate added, 8/8 pass

New `SKILL.md` section "Media field: guest uploads are blocked by default", sourced from
a real Codelab snippet. **jfb-8** instantiates `Media_Field_Parser` directly, calls
`set_context( new Parser_Context() )` first (`get_context()` requires this —
`field-data-parser.php:181`, a gotcha the first draft of this test tripped on: it fataled
with "Return value must be of type Parser_Context, null returned" until the missing
`set_context()` call was added), then confirms `jet-form-builder/media-field/before-upload`
fires with the parser instance itself and that `->get_context()->allow_for_guest()`/
`->update_setting()` are real, callable methods. PASS after the fix. Doesn't exercise
`get_response()`'s actual upload path (needs a real uploaded file), so the "guests can
now actually upload" end-to-end claim is confirmed only at the hook/context-API level,
not via a full submission.

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
