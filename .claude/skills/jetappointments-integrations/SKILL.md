---
name: jetappointments-integrations
description: Use when wiring Jet Appointments Booking's "Insert appointment" JetFormBuilder action, hooking its post-submission success event (jet-apb/jet-fb/action/success), or working with its WooCommerce integration (jet_apb()->wc, appointment-linked WC products/orders). Captures verified behavior of Jet Appointments Booking 2.5.1 source (plugins/jet-appointments-booking/), live-verified 2026-07-16 against jackfruit.epeak.studio (3/3 tests.php assertions passing); cross-references jetformbuilder-hooks/jetformbuilder-actions for the JetFormBuilder side.
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# Jet Appointments Booking — JetFormBuilder & WooCommerce Integrations

Verified facts about how Jet Appointments Booking plugs into JetFormBuilder (its
appointment-booking form action) and WooCommerce (paid appointments). Confirmed against
Jet Appointments Booking 2.5.1 source, `plugins/jet-appointments-booking/`. See
`jetappointments-core` for the accessor/data-model/calendar-hook facts this skill builds
on, and `jetformbuilder-actions`/`jetformbuilder-hooks` for the JetFormBuilder-side
conventions this integration follows.

## The booking form action is a real JetFormBuilder custom action, not a "Call Hook"

`JET_APB\Formbuilder_Plugin\Actions\Insert_Appointment_Action`
(`includes/formbuilder-plugin/actions/insert-appointment-action.php:19`) is registered as
a first-class JetFormBuilder action: `extends Jet_Form_Builder\Actions\Types\Base`,
implements the local `Smart_Action_It` interface, `get_id()` returns `'insert_appointment'`
(`:62-64`), `get_name()` returns `"Insert appointment"` (`:69-71`) — this is exactly the
pattern `jetformbuilder-actions` documents for writing a fully custom action class, and
confirms Jet Appointments Booking's "Insert appointment" form-editor action step is that
pattern applied, not JetFormBuilder's lighter "Call Hook" mechanism.

It composes several traits: `Smart_Action_Trait` (`includes/vendor/actions-core/
smart-action-trait.php`, generic request/settings glue — `getSettings()`, `getRequest()`,
`hasGateway()` for WC payment-gateway integration), `Send_Email_Handler`,
`Webhook_Handler`, and `JET_APB\Insert_Appointment` (the actual appointment-building
logic documented in `jetappointments-core`'s "Appointment-insert pipeline" section).

## `jet-apb/jet-fb/action/success` — the real "form action ran" event, 2 args

`Smart_Action_Trait::do_action( array $request, Action_Handler $handler )`
(`includes/vendor/actions-core/smart-action-trait.php:72-93`) is the actual method
JetFormBuilder calls to execute the action step. It calls `$this->run_action()` (the
`Insert_Appointment` trait's pipeline — builds/saves each `Appointment_Model`, fires
`jet-apb/form-action/insert-appointment` per row, see `jetappointments-core`), then:

```php
do_action( 'jet-apb/jet-fb/action/success', $appointments, $this );
```

(`:80`) — **`$appointments` is the return value of `run_action()`**, an array of already-
`->save()`d `Appointment_Model` instances (not raw arrays), and **`$this` is the action
handler instance** (the `Insert_Appointment_Action` object itself, via the trait). This
fires only on success — a thrown `Base_Handler_Exception` inside `run_action()` is caught
and re-thrown as a JetFormBuilder `Action_Exception` (`:83-91`) **before** reaching this
`do_action()` call, so a failed/rejected booking never fires it.

**This is the right hook for "run custom PHP after a booking form action succeeds"** —
equivalent in spirit to `jetformbuilder-hooks`' generic post-submission hooks, but scoped
specifically to this one action succeeding, with the created appointment objects already
in hand. Two real, verified consumers of this exact hook in the plugin's own code:

```php
// Insert_Appointment_Action's own constructor caches the appointments statically —
// includes/formbuilder-plugin/actions/insert-appointment-action.php:45-48,58-60
add_action( 'jet-apb/jet-fb/action/success', array( self::class, 'save_appointments' ) );
public static function save_appointments( $appointments ) {
    self::$appointments = $appointments; // readable later via getAppointments()
}

// WC_Integration hooks the same event to send the WC order/cart notification —
// includes/wc-integration.php:43
add_action( 'jet-apb/jet-fb/action/success', array( $this, 'process_wc_notification' ), 10, 2 );
```

## WooCommerce integration: `jet_apb()->wc`

`JET_APB\WC_Integration` (`includes/wc-integration.php`) is only meaningfully active if
WooCommerce is present (`has_woocommerce()`, `:72-74`, plain `class_exists('\WooCommerce')`
check) **and** the plugin's own WC feature is turned on with a linked product
(`get_status()`/`get_product_id()` both truthy, guarded at `:37-39` — everything past
that point in the constructor, including the two `jet-apb/*/action/success` hooks above,
is skipped otherwise).

`process_wc_notification` (hooked to both `jet-apb/form/notification/success` — the
non-JFB classic form-handler success event — and `jet-apb/jet-fb/action/success`, both
`10, 2`, `:42-43`) is the method the backlog's `jet_apb()->wc->process_wc_notification`
lead refers to: it's what actually stashes the just-created appointment data into the WC
session/cart flow (`$this->data_key`/`$this->form_data_key`/`$this->form_id_key`
properties, `:16-19`) so the subsequent WC checkout can attach it to an order.

Key public properties/hooks on `WC_Integration`, all confirmed at
`includes/wc-integration.php:10-66`:
- `$appointmet_product_key = '_is_jet_appointment'` (`:15`, note the plugin's own typo —
  "appointmet", not "appointment" — a real misspelling baked into the meta key, matches
  the pattern this repo already flags for JetEngine's `cvs-separator` and
  JetSmartFilters' "fiter" typos) — the post-meta key marking a WC product as the
  appointment-booking product.
- Cart/order pipeline hooks: `woocommerce_get_item_data` → `add_formatted_cart_data`,
  `woocommerce_checkout_order_processed` → `process_order`,
  `woocommerce_store_api_checkout_order_processed` → `process_order_by_api` (Store API/
  block-checkout path — a separate method from the classic-checkout one, don't assume
  one handles both), `woocommerce_order_status_changed` → `update_status_on_order_update`.
- `jet-apb/settings/before-write` → `maybe_create_appointment_product` (`:33`) — the WC
  product backing appointments is auto-created/kept in sync whenever plugin settings are
  saved, not provisioned once at activation.
- If the `wc_synch_orders` setting is on, `jet-apb/db/update/appointments` (the generic
  DB-write hook documented in `jetappointments-core`) additionally drives
  `update_order_on_status_update` (`:60-62`) — appointment status changes push back onto
  the linked WC order, one more consumer of that same core hook worth knowing about
  before assuming it's only used internally for the excluded-dates housekeeping already
  described in `jetappointments-core`.
- `$this->details` (a `WC_Order_Details_Builder` instance, `:64`) renders the
  booking-details block shown in cart/checkout/order-received/emails/admin-order screens
  (`order_details`/`email_order_details`/`admin_order_details`, `:54-57`).
  `WC_Integration::get_formatted_appointment_info( object $data, $form_data = [], $form_id = null )`
  (`includes/wc-integration.php:469-...`) is where the cart/order-detail line-items text
  is actually assembled; right before building it from scratch it fires:
  ```php
  apply_filters( 'jet-appointment/wc-integration/pre-cart-info', false, $data, $form_data, $form_id )
  ```
  (`:471-474`, note the **singular** `jet-appointment/...` prefix, unlike every other
  hook in this plugin which uses plural `jet-apb/...`) — 4 args: the default `false`,
  `$data` (the `Appointment_Model`), the raw form submission array, and the form's post
  ID. Returning a truthy value short-circuits the built-in formatting entirely (`:476-478`)
  — this is the hook for fully replacing the cart/order appointment summary text.
  `WC_Order_Details_Builder::set_cart_details()` (`wc-order-details-builder.php:19`,
  hooked at `10, 4`) is the plugin's own consumer, driven by the admin-configurable
  "WooCommerce order details" schema (`details_types` list already listed in
  `jetappointments-core`'s REST/action-data survey).

## Gotchas

- **`jet-apb/jet-fb/action/success` only fires for the JetFormBuilder path.** The classic
  (non-JFB) form handler fires a differently-named event, `jet-apb/form/notification/success`
  — `WC_Integration` deliberately hooks both (`:42-43`) because it needs to work either
  way; a custom integration relying on only one of these two hooks will silently miss
  bookings submitted through the other form system.
- **`Insert_Appointment_Action::$appointments` is a `static` property** (`:26`), shared
  across every instance of the action class process-wide for the current request — don't
  assume it's per-submission-scoped if multiple "Insert appointment" actions run in the
  same request (e.g. two separate forms on one page).
- Don't assume `process_wc_notification`'s two hook registrations mean it runs twice per
  booking in practice — `jet-apb/form/notification/success` and `jet-apb/jet-fb/action/success`
  are mutually exclusive per submission (classic-form vs. JFB-form), not both-always-fire.

## How this was verified

Read `includes/formbuilder-plugin/actions/insert-appointment-action.php`,
`includes/vendor/actions-core/smart-action-trait.php`, `includes/wc-integration.php`, and
`includes/wc-order-details-builder.php` directly in Jet Appointments Booking 2.5.1 source,
confirming the exact `do_action()`/`apply_filters()` call sites and arg counts for both
`jet-apb/jet-fb/action/success` and `jet-appointment/wc-integration/pre-cart-info`, and
cross-checking real consumers of each already present in the plugin's own code (no
gist-only claims taken on faith). Not yet verified against a running site — see
`TEST-REGIMEN.md`; `tests.php` has reachability/source-presence smoke tests ready to
deploy.
