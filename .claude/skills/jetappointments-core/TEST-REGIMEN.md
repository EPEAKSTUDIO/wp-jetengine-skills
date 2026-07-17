# Test regimen: jetappointments-core

Validates claims in `SKILL.md`. Run against the sandbox site (jackfruit.epeak.studio),
which has Jet Appointments Booking 2.5.1 installed alongside JetEngine (its hard
dependency).

## Run log

**2026-07-16**: deployed as snippet id 35, ran via
`GET /agent-test/v1/suite/jetappointments-core` — first run was **4/5**, `apb-4` failed.
Triaged per `docs/test-harness-guide.md`'s 4-step procedure: the source at
`includes/public-actions/manager.php:191-198` was correct (`render_error_page()` really
does call `render_action_result_page( 'error', ... )`), but the test's `strpos()` check
looked for the single-line string `"render_action_result_page( 'error'"` while the real
call wraps its args across multiple lines — a test bug, not a plugin/doc bug. Fixed by
switching to a whitespace-tolerant `preg_match()`; re-ran and got **5/5**. All tests here
are class/accessor/source-presence reachability checks — no live appointment/booking
fixture exists yet on the sandbox, so the full submission pipeline (Test 4 below) is
still not automated.

## Prerequisites

- Jet Appointments Booking 2.5.1 active, with JetEngine active (hard dependency — the
  plugin's `Plugin::__construct()` bails out entirely if `jet_engine()` isn't available).
- The always-active `AGENT-TEST-CORE harness` snippet (id 22).
- At least one Service CPT post configured (`settings->get('services_cpt')`) if Test 4
  is ever automated for real.

## Test 1: `jet_apb()` singleton resolves to the expected components

**Claim:** `jet_apb()` returns `JET_APB\Plugin::instance()`, with `->db`, `->settings`,
`->calendar`, `->statuses`, `->wc`, `->rest_api` all populated after `init_components()`.

**Automated as:** `apb-1` in `tests.php` — checks `function_exists('jet_apb')`, then
`get_class(jet_apb())`, then each documented property is non-null and an instance of the
expected class.

## Test 2: appointment DB tables exist with the documented names

**Claim:** `wp_jet_appointments`, `wp_jet_appointments_meta`,
`wp_jet_appointments_excluded`, `wp_jet_appointments_external`, `wp_jet_external_meta`
are real tables (`Base::table()`, `includes/db/base.php:64-66`), reachable via
`jet_apb()->db->appointments->table()` etc.

**Automated as:** `apb-2` in `tests.php` — calls `->table()` on each of the five
`jet_apb()->db->*` properties and confirms `SHOW TABLES LIKE` finds each one.

## Test 3: `jet-apb/calendar/custom-schedule` fires with 5 args

**Claim:** `Calendar::get_schedule_settings( $provider, $service, $default_value, $meta_key )`
fires `apply_filters('jet-apb/calendar/custom-schedule', $value, $meta_key, $default_value, $provider, $service)`
(`includes/calendar.php:646`).

**Automated as:** `apb-3` in `tests.php` — registers a filter capturing all 5 args, calls
`jet_apb()->calendar->get_schedule_settings( 0, 0, 'agent_test_default', 'agent_test_meta_key' )`
with harmless fake ids, and confirms the callback saw exactly that `$meta_key`/
`$default_value` pair (proves the hook is live-honored, not just present in source).

## Test 4: appointment-insert pipeline fires `jet-apb/form-action/insert-appointment` by reference before save

**Claim:** `Insert_Appointment::run_action()` calls
`do_action_ref_array('jet-apb/form-action/insert-appointment', [&$appointment, $this])`
then `$appointment->save()` — a callback mutating the model persists.

**Not automated** — `run_action()` is only reachable through a real form/REST submission
with a configured Service CPT, a valid email field, and eligibility-validator checks
passing (`Appointment_Eligibility_Validator`) — not safe to fabricate a fixture for
without an actual Service/Provider CPT setup on this sandbox yet. Manual steps: submit a
real booking form with a snippet hooked to `jet-apb/form-action/insert-appointment` that
logs `$appointment->get('ID')` before/after — confirm the ID is still empty during the
hook (pre-save) and populated only after, and that a `->set('status','completed')` call
inside the hook is reflected in the saved DB row.

## Test 5: public confirm/cancel action-page content filters exist with the right names

**Claim:** `render_action_result_page()` fires
`jet-apb/public-actions/custom-action-page-content` on success and
`jet-apb/public-actions/custom-error-page-content` on error, both `(false, $manager)`.

**Automated as:** `apb-4` in `tests.php` — source-presence grep only (the real trigger
path needs `allow_action_links` enabled plus a valid confirm/cancel token in `$_GET`,
which isn't safe to fabricate against a real appointment on this sandbox).

## Test 6: REST endpoints register via JetEngine's REST manager, not a dedicated namespace

**Claim:** `Rest_API\Manager` hooks `jet-engine/rest-api/init-endpoints` and registers 13
endpoint classes through `$api_manager->register_endpoint()`.

**Automated as:** `apb-5` in `tests.php` — confirms `has_action('jet-engine/rest-api/init-endpoints')`
includes a callback tied to `JET_APB\Rest_API\Manager`, and that `jet_apb()->rest_api->get_urls()`
returns the 12 documented keys. Not yet automated: actually hitting each endpoint's
resolved URL over HTTP and checking response shape — flagged as an open gap.
