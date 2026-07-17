---
name: jetbooking-integrations
description: Use when hooking JetBooking's WooCommerce cart/order integration (booking-priced products, cart/order-meta hooks), its Google Calendar export links, or its front-end JS API (the `window.JetPlugins.hooks` filter bus that drives the date-range picker/calendar widgets, and the `JetABAFData`/`JetABAFInput` localized config objects that feed it). Captures verified behavior from JetBooking 4.1.2.1 PHP and shipped JS source, live-verified 2026-07-16 against jackfruit.epeak.studio (6/6 tests.php assertions passing).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetBooking Integrations: WooCommerce, Google Calendar, Front-End JS API

Verified facts about the three integration surfaces around JetBooking's core booking
model (see `jetbooking-calendar` for the `\JET_ABAF\Plugin` singleton, DB layer, and
pricing model this all builds on). Confirmed against JetBooking 4.1.2.1 source in
`plugins/jet-booking/`, including the shipped `assets/js/booking-init.js` bundle for the
JS-side claims. Hook prefix is **`jet-booking/`** throughout (this plugin, `JET_ABAF`) —
do not confuse with the singular `jet-appointment/` prefix used by the unrelated Jet
Appointments Booking plugin's own WC integration (`jet_apb()`/`JET_APB`); both plugins
independently define a `.../wc-integration/pre-cart-info` filter with the *same* generic
name but different slash-vs-word forms, verified as two distinct files in two distinct
plugin checkouts.

## WooCommerce cart/order integration — `jet_abaf()->wc`

`WC_Integration\Manager` (`includes/wc-integration/manager.php`) plus several
collaborator classes (`Wc_Cart_Manager`, `Wc_Order_Manager`, `Wc_Order_Details_Builder`,
`Wc_Product_Jet_Booking`, `Wc_Attributes_Manager`) implement the "book a WooCommerce
product for a date range" mode — used when `settings->get('booking_mode')` is a WC mode,
the counterpart to the `'plain'`-mode form-action pipeline in `jetbooking-calendar`.
Confirmed real extension points (all `jet-booking/wc-integration/*`, grepped verbatim
across `includes/wc-integration/`):

- **`pre-cart-info`** (filter, `manager.php:626`, 4 args: `$pre_cart_info` [starts
  `false`], `$data`, `$form_data`, `$form_id`) — registered by
  `Wc_Order_Details_Builder::set_cart_details()` (`class-wc-order-details-builder.php:22`).
  Fires before the cart-info array is built for a booking add-to-cart; return a truthy
  value to short-circuit and supply the cart info yourself (same "replace the pipeline"
  shape as the `plain`-mode `jet-booking/form-action/pre-process` hook in
  `jetbooking-calendar`) — the plugin's own default handler for this filter is itself
  just another hooked callback, not special-cased code.
- **`cart-info`** (filter, `manager.php:663`, 4 args: `$result`, `$data`, `$form_data`,
  `$form_id`) — the *final*, fully-assembled cart info, right before it's returned to the
  caller; use this to adjust the result rather than replace the whole pipeline.
- **`booking-data`** (filter, `class-wc-cart-manager.php:346`, 2 args: `$args`,
  `$product_id`) and **`cart-item-data`** (filter, `:365`, 4 args: `$args`,
  `$cart_item_data`, `$product`, `$variation_id`, `$quantity` — 5 args total despite the
  name suggesting otherwise) shape what's stored under
  `$cart_item_data[ jet_abaf()->wc->data_key ]` — the actual WC cart-item array key
  JetBooking's own booking payload lives under; read it back via that same
  `jet_abaf()->wc->data_key` property, never hardcode the string.
- **`booking-inserted`** (action, `class-wc-cart-manager.php:373` and
  `manager.php:454`, 1 arg: `$booking_id`) — fires when a WC-mode booking is inserted
  into the bookings DB table (distinct from `jet-booking/db/booking-inserted` in
  `jetbooking-calendar`, which fires from `DB\Manager::insert_booking()` directly and so
  actually fires for *both* modes — this WC-specific one is an additional, mode-specific
  signal fired alongside it).
- **`process-order`** (action, `manager.php:755`, 4 args: `$data`, `$order_id`, `$order`,
  `$cart_item`) — fires once per booking line item when a WC order is processed;
  `Wc_Order_Details_Builder::set_order_meta()` and `Wc_Order_Manager::
  update_order_line_item_meta()` both hook it (`class-wc-order-details-builder.php:24`,
  `class-wc-order-manager.php:55`) to write the booking's dates/price onto the order line
  item meta — the pattern to copy for adding a custom order-line field.
  `before-update-status`/`before-set-order-data` (actions, `manager.php:594`/`:746`) fire
  earlier in the same flow and are both hooked by `Wc_Order_Manager::
  validate_booking_update()` (`class-wc-order-manager.php:62-63`) to re-validate booking
  dates are still available before the order status actually changes.
- **`booking-price`** (filter, multiple call sites —
  `class-wc-order-manager.php:836,974`, `modes/plain.php:244,561`) — the single
  price-override point re-used across several different code paths for a WC-mode
  booking's stored price; args vary by call site (2-3 args, always `$booking_price`
  first) so check the specific call site before relying on a fixed signature.
- **`myaccount/endpoint`** (filter, `manager.php:183`, default `'jet-bookings'`) and
  **`myaccount/bookings-per-page`** (filter, `:241`, default `10`) — the "My Account →
  Bookings" tab's endpoint slug and page size, both overridable without touching core.

## Google Calendar export link — `jet_abaf()->google_cal`

`Google_Calendar::get_calendar_url_by_booking( $booking )` (`includes/google-calendar.php`)
builds a `calendar.google.com/calendar/render` add-event URL for one booking. Confirmed
filters:

- **`jet-booking/google-calendar-url/args`** (filter, `:183`, 2 args: `$args` [the
  `action`/`text`/`dates`/`details`/`location` query-arg array, **before** being passed
  to `add_query_arg()`], `$booking`) — **the** hook to add e.g. `location`/`details`
  content or change the event title, matching the gist-sourced lead in
  `.claude/skills/_other-plugins-backlog/OTHER-PLUGINS.md`. `add_query_arg()` runs
  `array_filter()` on the result first, so setting a key to an empty string/false/null
  drops it from the URL rather than emitting it blank.
- **`jet-booking/google-calendar-url/utc-timezone`** (filter, default `false`) — toggles
  whether the generated `dates` param gets a trailing `Z` (UTC) suffix.
- **`jet-booking/google-calendar-url/booking-id`** / **`.../secure-id`** — the
  obfuscated-id encode/decode pair used for the public "add to Google Calendar" link
  (`get_booking_id_from_secure_id()`/`secure_id()`); both default to `false` (meaning "use
  the built-in offset-key obfuscation") and can be overridden to plug in a different
  scheme entirely.

## Front-end JS API — `window.JetPlugins.hooks`, `JetABAFData`/`JetABAFInput`

Confirmed by reading the shipped `assets/js/booking-init.js` bundle directly (not just
docs/gists) — string literals below are exact matches found in that file.

**Current API** (since 2.6.3): `window.JetPlugins.hooks.addFilter( name, namespace,
callback )` / `.applyFilters( name, value, ...args )` — a shared filter-hook bus, **not**
JetBooking-specific infrastructure (the same `JetPlugins.hooks` object is used by
JetFormBuilder and other Crocoblock front-end JS; see
`.claude/skills/_other-plugins-backlog/OTHER-PLUGINS.md`'s "Shared JetPlugins framework"
note). Real filter names fired by JetBooking (dot-separated, confirmed at the cited
`booking-init.js` line numbers):

- `jet-booking.input.config` (`:810`) — the date-range-picker input widget's full config
  object, right before it's handed to the picker library.
- `jet-booking.calendar.config` (`:1074`) — same shape, for the standalone calendar
  widget variant.
- `jet-booking.apartment-price` (`:339`) — the resolved apartment price shown in the
  calendar/picker UI; args `(price, field)`.
- `jet-booking.dynamic-price` (`:179`) — a second, differently-named price filter (args
  `(price, rawPrice, period)`) distinct from `apartment-price` — fires during the
  dynamic per-selection price recalculation as dates change, not the initial static
  price.
- `jet-booking.day-price` (`:377`) — per-calendar-day price shown when hovering/browsing
  the calendar, args `(price, day, daysCount)`.
- `jet-booking.date-range-picker.date-show-params` (`:281`) — controls
  `[valid, cssClass, tooltip]` per rendered calendar date — the hook to disable specific
  dates or add a custom tooltip/class beyond the built-in booked/blocked logic.

**Trigger events** (plain jQuery `document`-level triggers, not the filter bus — listen
with `jQuery(document).on(...)`): `jet-booking/init` (`:1249`, fires once, after the
whole booking-init script has wired up every field/calendar on the page — **this is the
one to wait for before touching `window.jetBookingState`/calling `JetPlugins.hooks`
yourself**), `jet-booking/init-field` (`:940`, 1 arg: the input-widget jQuery `$field`,
fires once per date-range-picker input field initialized), `jet-booking/init-calendar`
(`:1152`, 1 arg: the calendar jQuery `$el`, fires once per standalone calendar widget).

**Deprecated API** (pre-2.6.3, still shipped as a compatibility shim,
`booking-init.js:3-11`): `window.jetBookingState.filters.add( name, callback )` —
internally just rewrites `name` (`s/\//./g`, i.e. `jet-booking/input/config` →
`jet-booking.input.config`) and forwards to `JetPlugins.hooks.addFilter()`, logging a
`console.warn()` deprecation notice every call (`:11`). **A snippet written against this
old slash-form API still works** (it's a real forwarding shim, not a dead stub) but new
code should use `JetPlugins.hooks.addFilter()` with the dot-form name directly.
`window.jetBookingState` itself also exposes non-filter runtime state worth knowing:
`.isActive` (bool, true while a calendar/picker popup is open — several gist snippets
gate same-day/cutoff logic on this) and `.bookingCalendars` (array, every initialized
calendar/picker jQuery element pushed onto it as it's set up, `:881`,`:1119`).

**PHP side feeding the JS**: `Assets::enqueue_deps()`
(`includes/assets.php`) localizes `JetABAFData` onto the `jquery-date-range-picker`
script handle, wrapped in **`jet-booking/assets/config`** (filter, `:130`, 1 arg — the
whole config array, applied *after* `wp_parse_args()` merges in `ajax_url`/`css_url`/
`post_id` defaults) — the right PHP-side hook to inject extra data the JS-side filters
above will consume (e.g. per-apartment custom config), rather than trying to mutate
`JetABAFData` from JS after the fact. A second, action-only hook,
**`jet-booking/assets/after`** (`:123`), fires right after the core scripts are enqueued
but before the localize call — for enqueuing an additional dependent script/style, not
for data. The check-in/check-out field additionally localizes its own smaller
`JetABAFInput` object (`includes/form-fields/check-in-out-render-trait.php:65`,
distinct handle payload: `layout`/`field_format`/`start_of_week`/`options`) — and that
render path separately exposes **`jet-booking/form-fields/check-in-out/default-value`**
(filter, `:46`, 1 arg) and **`jet-booking/form-fields/check-in-out/attributes`** (filter,
`:43`, 1 arg) for customizing that specific field's default value / raw HTML attributes
before the JS ever sees them.

## Gotchas

- **Two similarly-named `.../wc-integration/pre-cart-info` filters exist across
  Crocoblock's booking plugins, but they are not the same hook**: this plugin's is
  `jet-booking/wc-integration/pre-cart-info` (plural "booking"); Jet Appointments
  Booking's is the singular `jet-appointment/wc-integration/pre-cart-info` — a callback
  copy-pasted from one plugin's gist into the other's site will silently never fire.
- **`booking-price` filter's arg count is call-site-dependent** (see above) — don't
  assume a fixed signature just because the name is reused verbatim in 4 different files.
- The deprecated `window.jetBookingState.filters.add()` shim rewrites `/` to `.`
  literally by regex — a filter name containing a literal `.` already (there are none in
  the built-ins above) would not round-trip correctly through the old API; irrelevant for
  the documented names but worth knowing if extending the shim.

## How this was verified

Read `includes/wc-integration/manager.php`, `includes/wc-integration/class-wc-cart-
manager.php`, `includes/wc-integration/class-wc-order-manager.php`,
`includes/wc-integration/class-wc-order-details-builder.php`,
`includes/google-calendar.php`, `includes/assets.php`,
`includes/form-fields/check-in-out-render-trait.php`, and the shipped
`assets/js/booking-init.js` bundle directly (grepping for every filter/trigger string
literal rather than trusting the gist descriptions) in JetBooking 4.1.2.1 source
(`plugins/jet-booking/`) — confirming every hook name, arg count, and JS
filter/trigger/global-object claim above by direct file:line citation, and cross-checking
the `pre-cart-info` naming collision against
`.claude/skills/_other-plugins-backlog/OTHER-PLUGINS.md`'s already-verified Jet
Appointments Booking split. Not yet verified against a running site — JetBooking is not
installed on the sandbox; see `TEST-REGIMEN.md`.
