# Test regimen: jetengine-booking-forms

Validates claims in `SKILL.md`. Run against the sandbox site (`jackfruit.epeak.studio`)
with both the `calendar` and `booking-forms` JetEngine modules active (activated for
this round via `tool-manage-modules`, alongside `dynamic-visibility`, `data-stores`,
`rest-api-listings`).

This skill has a **runnable suite** (`tests.php`, 12 tests, `bf-1` through `bf-12`) per
`docs/test-harness-guide.md`. Run it live with:

```
GET /wp-json/agent-test/v1/suite/jetengine-booking-forms
```

(requires the always-active AGENT-TEST-CORE harness and this suite's snippet, both
active.)

## Run log — 2026-07-17: first run, 12/12 pass, no fixes needed

Deployed `tests.php` as Code Snippets snippet id 75 ("AGENT-TEST-SUITE:
jetengine-booking-forms"), active. First run (`run_at: 2026-07-17 18:58:27`):
**12/12 pass**, `fatal_error: null`. No plugin bugs and no test bugs found — clean
first run.

Two real documentation **additions** (not corrections) surfaced by the live results
and folded into `SKILL.md`, both examples of the built-in extensibility filters
genuinely being used by other active plugins on this site, not just theoretical:

- `bf-4` (`get_calendar_group_keys()`): the live list has **6** entries, not the 4 core
  ones — `jet_appointment`/`jet_booking` are appended by Jet Appointments Booking/
  JetBooking (both active on this sandbox) via
  `jet-engine/listing/calendar/group-keys`. The test itself only asserts the 4 core
  keys are a *subset* of the real result (`array_diff`), so this didn't fail the
  assertion — it was caught by eyeballing `actual` in the response, per this repo's
  "always look at `actual`, not just `pass`" habit.
- `bf-9` (`get_notification_types()`): the live list has **16** entries, not the 11
  core ones — `insert_appointment`, `apartment_booking`,
  `insert_custom_content_type`, `rest_api_request`, `connect_relation_items` are
  appended by CCT/Relations/REST API Listings/Jet Appointments Booking/JetBooking
  through `jet-engine/forms/booking/notification-types`. Same subset-check shape, same
  "look at actual" discovery.

No redeploy of `tests.php` was needed for either finding since the assertions were
already written as subset checks — only `SKILL.md` was updated.

## Test bf-1: `is_module_active()` reports both modules active

**Claim being tested:** `jet_engine()->modules->is_module_active('calendar')` and
`...('booking-forms')` both return `true` once activated.

**Setup:** none beyond both modules being active on the sandbox.

**Trigger:** call `is_module_active()` for both ids.

**Expected observable:** both return `true`.

**Pass criteria:** both booleans true.

## Test bf-2 / bf-3: `get_module()` returns the real instance / degrades safely for a bogus id

**Claim being tested:** `get_module('calendar')` returns the real
`Jet_Engine_Module_Calendar` instance (regardless of activation state — this call
doesn't itself check activation); `get_module()` with an unregistered id returns `false`
rather than throwing.

**Trigger:** call both.

**Pass criteria:** correct class/id for the real module; bogus id returns `false`
without a fatal.

## Test bf-4: `get_calendar_group_keys()` returns the 4 documented keys

**Claim being tested:** the default "Group posts by" options are exactly `post_date`,
`post_mod`, `meta_date`, `item_date`.

**Trigger:** call `jet_engine()->modules->get_module('calendar')->get_calendar_group_keys()`.

**Pass criteria:** all 4 keys present.

## Test bf-5: Advanced Date Field singleton + derived meta-key naming convention

**Claim being tested:** `Jet_Engine_Advanced_Date_Field::instance()->field_type` is
`'advanced-date'`; `end_date_field_name('my_date')` returns `'my_date__end_date'`;
`config_field_name('my_date')` returns `'my_date__config'`.

**Trigger:** call all three directly.

**Pass criteria:** exact string matches — this is the naming contract the calendar
query's multiday end-date auto-derivation (`query.php:64-68`) depends on.

## Test bf-6: Advanced Date Field data round-trip (multi-row storage, `get_date_pairs()` correlation)

**Claim being tested:** each date occurrence is its own `add_post_meta(..., false)` row
(not one serialized value); `get_date_pairs()` correlates a start row with its matching
`__end_date` row when present, and reports `['end' => false]` when a start has no
matching end.

**Setup:** create a throwaway draft `post`, write two manual date entries via
`Jet_Engine_Advanced_Date_Field_Data::update_field_with_value()` — one with an end date,
one without.

**Trigger:** read back via `get_dates()`/`get_end_dates()`/`get_date_pairs()`.

**Expected observable:** 2 start rows, 1 end row, 2 correlated pairs, at least one pair
with `end === false`.

**Pass criteria:** all of the above; test force-deletes the post afterward regardless of
outcome.

## Test bf-7: `jet_engine()->forms` is the real instance, only present when `booking-forms` is active

**Claim being tested:** `jet_engine()->forms instanceof Jet_Engine_Booking_Forms`,
`->slug()` returns `'jet-engine-booking'`, and the back-compat `->booking` property is
self-referential.

**Trigger:** read the properties/call the method directly.

**Pass criteria:** all three checks true.

## Test bf-8: the `jet-engine-booking` CPT is really registered, with the documented visibility flags

**Claim being tested:** `public: true`, `show_in_menu: false` (surfaced only through the
module's own custom admin page, not a normal CPT admin-menu item).

**Trigger:** `post_type_exists()` + `get_post_type_object()`.

**Pass criteria:** CPT exists with exactly those two flag values.

## Test bf-9: `get_notification_types()` includes every documented built-in type

**Claim being tested:** the notification-type list documented in `SKILL.md` (`email`,
`insert_post`, `register_user`, `update_user`, `update_options`, `hook`, `webhook`,
`redirect`, `mailchimp`, `activecampaign`, `getresponse`) is really what the method
returns.

**Trigger:** call `jet_engine()->forms->get_notification_types()`.

**Pass criteria:** all 11 keys present (extra keys from other integrations are fine,
only checks a superset).

## Test bf-10: `_form_data`/`_notifications_data` meta round-trips through the Editor's read methods

**Claim being tested:** `Editor::get_form_data()`/`get_notifications()` correctly
`json_decode()` back whatever was written to those two meta keys (the same shape
`save_layout()` writes from `$_POST`).

**Setup:** create a throwaway `jet-engine-booking` post, write both meta keys directly
with test JSON.

**Trigger:** call both read methods.

**Pass criteria:** both read back the exact test values submitted; post force-deleted
afterward.

## Test bf-11: notification dispatch is a flat, per-type action fan-out — a custom type needs only its own hook, no core-class edit

**Claim being tested:** `Jet_Engine_Booking_Forms_Notifications::send()` fires
`jet-engine/forms/booking/notification/{type}` once per configured notification entry,
with args `($notification, $this)` — confirmed for a made-up, non-built-in type name to
prove no special-casing is required to add a custom notification type.

**Setup:** create a throwaway `jet-engine-booking` post with one `_notifications_data`
entry of type `agent_test_notification`; hook that exact action.

**Trigger:** instantiate `Jet_Engine_Booking_Forms_Notifications` directly (constructor
args: `$form_id, $data, $manager, $handler` — `$handler` can be `null`, it's only
touched by specific built-in notification callbacks not exercised here) and call
`->send()`.

**Expected observable:** the hook fires exactly once, with the right notification array
and a `Jet_Engine_Booking_Forms_Notifications` instance as the second arg.

**Pass criteria:** both captured correctly; post force-deleted afterward regardless of
outcome.

## Test bf-12: calendar AJAX settings-signature check accepts a real signature, rejects a forged one

**Claim being tested:** `Jet_Engine_Module_Calendar::has_valid_settings_signature()`
uses `hash_equals()` against a signature freshly computed from the settings payload —
not just a static per-site secret — so a signature copied from a different payload (or
made up) is rejected while a freshly generated one for the *same* payload is accepted.

**Trigger:** generate a signature for a fixed settings array via
`generate_settings_signature()`, then check `has_valid_settings_signature()` against
that settings array once with the real signature attached and once with a forged
string.

**Pass criteria:** real signature accepted, forged signature rejected, and the
generated signature itself is non-empty (confirms `jet_engine()->listings->ajax_handlers`
was actually reachable — see the method's own early-return-empty-string guard).

## Not yet automated — manual steps only

- **Full front-end month-navigation AJAX round trip** (`wp_ajax_jet_engine_calendar_get_month`
  actually re-rendering a `listing-calendar`/`listing-multiday-calendar` grid via a real
  HTTP POST) — `bf-12` only exercises the signature-validation helper directly, not a
  live `admin-ajax.php` hit with real listing settings/Elementor markup; needs a real
  Elementor Calendar widget on a page plus a browser or scripted HTTP client.
- **A full legacy-form submission through the browser** (the classic page-reload path
  with `HTTP_REFERER` validation, and the AJAX path via the real front-end JS) — `bf-11`
  proves the notification fan-out mechanism directly without going through
  `Jet_Engine_Booking_Forms_Handler::process_form()`'s nonce/referer/session-token
  gates, which are exactly the kind of request-lifecycle-dependent checks this repo's
  other regimens (see `HANDOFF.md`'s "third lesson") reserve for a real submission
  rather than a direct unit-style call.
- **Recurring-date expansion** (`Jet_Engine_Advanced_Date_Recurring_Dates::generate()`,
  driven from an RRULE-shaped config) — `bf-6` only exercises the manual (non-recurring)
  date-pair path; a recurring fixture would need a real RRULE payload and is a good
  candidate for a follow-up test.
- **Elementor Calendar/Form widget rendering** (visual confirmation that the
  `Jet_Listing_Calendar_Widget`/`Jet_Engine_Booking_Form_Widget` controls produce the
  expected front-end markup) — requires the Elementor editor UI, not practically
  automatable via a single REST-triggered suite call.
- **Gateways sub-module** (`forms/gateways/paypal.php`, gated behind
  `apply_filters('jet-engine/forms/allow-gateways', false)` — off by default,
  `manager.php:49-52`) — not activated on this sandbox and out of scope for this skill;
  flagged here in case a future session wants to extend coverage once/if it's enabled.

## Cleanup note

No persistent fixtures are left behind — every test that creates a post (`bf-6`,
`bf-10`, `bf-11`) force-deletes it at the end of its own callback regardless of
pass/fail. Suite snippet kept active per this repo's convention — re-runnable any time
via the REST route above.
