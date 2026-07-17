# Test regimen: jetappointments-integrations

Validates claims in `SKILL.md`. Run against the sandbox site (jackfruit.epeak.studio),
which has Jet Appointments Booking 2.5.1 and JetFormBuilder installed.

## Run log

**2026-07-16**: deployed as snippet id 36, ran via
`GET /agent-test/v1/suite/jetappointments-integrations` — **3/3 pass on first run**, no
fixes needed. WooCommerce is not active on this sandbox — the WC-dependent tests (`apbi-2`,
`apbi-3`) correctly assert "class present, gated correctly" / drive the filter with a fake
never-saved model rather than requiring the full WC pipeline, per this repo's
`jetengine-modules` lesson about checking gating state before assuming a class is always
fully wired.

## Prerequisites

- Jet Appointments Booking 2.5.1 + JetFormBuilder both active.
- The always-active `AGENT-TEST-CORE harness` snippet (id 22).
- For full WC-path testing (Test 3): WooCommerce active, the plugin's own WC integration
  toggled on with a linked product (`settings->get('wc_integration')` +
  `WC_Integration::get_product_id()`) — not present yet on this sandbox; that portion
  stays manual until then.

## Test 1: `Insert_Appointment_Action` is a real registered JetFormBuilder action

**Claim:** `get_id()` returns `'insert_appointment'`, `extends Jet_Form_Builder\Actions\Types\Base`.

**Automated as:** `apbi-1` in `tests.php` — `class_exists()` + `is_subclass_of()` check,
plus confirming a fresh instance's `get_id()`/`get_name()` return the documented values.
Does not drive a real form submission through it (see Test 2).

## Test 2: `jet-apb/jet-fb/action/success` fires with `($appointments, $handler)` on success only

**Claim:** `Smart_Action_Trait::do_action()` calls `run_action()` then
`do_action('jet-apb/jet-fb/action/success', $appointments, $this)` — only reached if
`run_action()` didn't throw.

**Not automated** — `do_action()` (the trait method) requires a real `Action_Handler`
instance from an in-flight JetFormBuilder submission and a `$request` array shaped like
a real POST; fabricating one risks the same "assumes a fully-configured runtime context"
landmine this repo already avoided for JetSmartFilters' filter-type classes. Manual
steps: build a real form with the "Insert appointment" action, hook
`jet-apb/jet-fb/action/success` to `error_log()` its two args, submit the form
successfully once and confirm the log shows an array of `Appointment_Model`-shaped data
plus the handler object; then submit with intentionally invalid data (e.g. missing
required field) and confirm the hook does NOT fire.

## Test 3: WooCommerce integration gating and hook wiring

**Claim:** `WC_Integration` only wires its `jet-apb/*/action/success` hooks (and
everything past the constructor's early-return) when WooCommerce is active AND the
plugin's own WC feature + linked product are both configured.

**Automated as:** `apbi-2` in `tests.php` — checks `jet_apb()->wc->has_woocommerce()`
and, if true, whether `jet-apb/jet-fb/action/success` has a callback registered on the
`WC_Integration` instance specifically (distinguishing "WC inactive" from "WC active but
feature off" from "fully wired"). If WooCommerce is not active on this sandbox, this
degrades to confirming the early-return path (no hooks registered) rather than a hard
failure.

**Not yet automated:** a real WC checkout completing with appointment data attached to
the resulting order — needs a live WC product + checkout flow, manual only for now.

## Test 4: `jet-appointment/wc-integration/pre-cart-info` fires with 4 args

**Claim:** `get_formatted_appointment_info()` fires
`apply_filters('jet-appointment/wc-integration/pre-cart-info', false, $data, $form_data, $form_id)`
before building the default cart/order summary text.

**Automated as:** `apbi-3` in `tests.php` — live-drives `get_formatted_appointment_info()`
directly with a fake in-memory `Appointment_Model` (never saved to DB) and confirms a
registered filter sees all 4 args with the right shapes, and that returning a truthy
value from the filter short-circuits the method's own return value.
