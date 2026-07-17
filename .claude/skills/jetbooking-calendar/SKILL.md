---
name: jetbooking-calendar
description: Use when writing PHP against JetBooking's core booking/pricing model — reaching the `\JET_ABAF\Plugin` singleton and its component properties, reading/writing the date-range booking DB tables (`DB\Manager`, `Tables\Bookings`/`Tables\Units`), computing a booking's price from the seasonal/weekend/rate pricing meta, or hooking the form-submission booking-insert pipeline. Captures verified behavior from JetBooking 4.1.2.1 source, live-verified 2026-07-16 against jackfruit.epeak.studio (7/7 tests.php assertions passing).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetBooking Core: Plugin Singleton, Booking DB, Pricing

Verified facts about JetBooking's (`JET_ABAF`) main plugin object, its booking
storage/query layer, its multi-layered pricing model, and the hook pipeline a booking
passes through when a form submits it. Confirmed against JetBooking 4.1.2.1 source in
`plugins/jet-booking/`. **Not to be confused with Jet Appointments Booking**
(`JET_APB`/`jet_apb()`) — a separate, separately-versioned plugin with a similar but
distinctly-prefixed hook set (`jet-apb/...` vs. this plugin's `jet-booking/...`); see
`.claude/skills/_other-plugins-backlog/OTHER-PLUGINS.md` for how the two were told apart.

## The `Plugin` singleton — `jet_abaf()`, not `new JET_ABAF\Plugin()`

`\JET_ABAF\Plugin` (`includes/plugin.php:36`) is a classic private-constructor singleton:
`public static function instance()` (`:62`) lazily creates `self::$instance`, and the
constructor itself is `private` (`:276`) — **PHP itself blocks `new JET_ABAF\Plugin()`
from outside the class**, so there's no "don't instantiate directly" landmine to avoid
here (unlike JetSmartFilters' `Storage\Controller`, see `jetsmartfilters-query`). The
global helper `jet_abaf()` (`jet-booking.php:79`) just returns `Plugin::instance()`;
`Plugin::instance()` is also called unconditionally at the bottom of `plugin.php:292` on
every request once the file loads, so by the time any hook fires, the singleton already
exists — always use `jet_abaf()->whatever`, never construct a component class yourself.

Component properties are set dynamically (the class is `#[\AllowDynamicProperties]`,
`plugin.php:35`) inside `init_components()` (`:122-166`, hooked on `init` at priority
`-999`) — there is **no interface/abstract base** enforcing this list, it's just a
`@property` docblock (`plugin.php:13-31`) plus direct `$this->x = new Y()` assignment.
The real, commonly-used ones: `jet_abaf()->settings` (`Settings`), `->statuses`
(`Statuses`), `->db` (`DB\Manager`), `->assets` (`Assets`), `->google_cal`
(`Google_Calendar`), `->wc` (`WC_Integration\Manager`), `->vendors`
(`Multivendors\Manager`), `->tools` (`Tools`), `->components` (`Components\Manager`,
which in turn lazily adds `->blocks_views`/`->bricks_views`/`->elementor_views` onto
`jet_abaf()` itself from `Components\Manager::init_components()`,
`includes/components/manager.php:44-49`).

## Booking DB layer — `jet_abaf()->db`, three custom tables

`DB\Manager` (`includes/db/manager.php:7`) is constructed once as `jet_abaf()->db` and
owns three table wrapper objects: `->bookings` (`Tables\Bookings`), `->bookings_meta`
(`Tables\Bookings_Meta`), `->units` (`Tables\Units`) — plain custom tables, not CPT/CCT
storage. Real methods to know (all on `jet_abaf()->db`):

- `insert_booking( $booking )` (`:417`) — takes a plain array (`apartment_id`,
  `check_in_date`/`check_out_date` as Unix timestamps, `status`, `user_email`, etc.),
  auto-resolves `apartment_unit` via `get_available_unit()` if not given, checks
  `is_booking_dates_available()`, and on success fires
  `do_action('jet-booking/db/booking-inserted', $booking)` (`:451`, **1 arg, the array
  including the new `booking_id`**) and stores the row on `->inserted_booking` for the
  caller to read back. Returns `false` (not an exception) if the dates are already taken.
- `update_booking( $id, $data )` (`:469`) fires `jet-booking/db/booking-updated'` (1 arg,
  `$booking_id`, **not** the data array).
- `delete_booking( $where )` (`:486`) fires `jet-booking/db/before-booking-delete`
  (`:488`, 1 arg, the `$where` array) before deleting, and also cascades a delete of the
  booking's `bookings_meta` rows first.
- `get_apartment_units( $apartment_id )` (`:223`), `get_booked_units( $booking )`
  (`:240`), `get_available_units( $booking )` (`:973`), `get_available_unit( $booking )`
  (singular, `:285`, returns one `unit_id` or `null`) — the multi-unit-per-apartment
  primitives. `get_booked_units()`/`get_available_units()` both filter out bookings whose
  `status` is in `jet_abaf()->statuses->invalid_statuses()` or the temporary status
  (`->temporary_status()`) — a cancelled or momentarily-reserved booking never blocks a
  unit from being counted available.
- `jet_abaf_get_bookings( $args )` / `jet_abaf_get_booking( $id )`
  (`includes/booking-functions.php:21,38`) are the public procedural wrappers around
  `Resources\Booking_Query` — prefer these over hand-building SQL against
  `DB\Manager::bookings_table()` for read paths.

## Pricing model — one serialized meta key, four sub-arrays, layered price resolution

**Confirmed (correcting a plausible misreading of the meta key list)**: `jet_abaf_price`
is a **single serialized post meta key** (`includes/price.php:11`,
`includes/dashboard/post-meta/price-meta.php:10`) whose value is an associative array
with four sub-keys — `_apartment_price`, `_pricing_rates`, `_seasonal_prices`,
`_weekend_prices` (`price-meta.php:56-60`, `price.php:57-61`). **These are not four
separate `get_post_meta()` calls** — read them all at once via `get_post_meta( $post_id,
'jet_abaf_price', true )`, which returns the whole array (`price.php:53`). One gotcha:
`Price_Meta::backward_save_post_meta()` (`price-meta.php:106-116`) *also* writes
`_apartment_price` and `_pricing_rates` back out as their own top-level, unserialized
meta keys for backward compatibility with older code that reads them directly — so both
forms can legitimately exist simultaneously; don't assume one or the other is stale.

Resolution is layered through three helper classes, all constructed from the same
`$meta` array by `Price::__construct()` (`price.php:36-49`): `Advanced_Price_Rates`
(duration-based rate breakpoints, `_pricing_rates`), `Seasonal_Price` (`_seasonal_prices`,
each with its own nested `price`/`weekend_price`/`price_rates`), `Weekend_Price`
(`_weekend_prices`, per-weekday overrides). `Price::get_booking_price_breakdown( $data )`
(`:465`) is the real per-stay calculator — walks every day in the date range
(`jet_abaf()->tools->get_booking_period()`), applies seasonal override → weekend
override → duration-rate override in that priority order per day, and returns a
breakdown array `{base, seasonal, weekend, rate, total}` (only the sub-keys with a
non-negligible amount are present, `has_breakdown_amount()`, `:407`) — filtered through
`jet-booking/price/breakdown` (`:546`, 4 args: breakdown, data, period, interval, Price
instance). `get_booking_price()` (`:449`) is a thin wrapper returning just `total`.
Per-day price is separately filterable via `jet-booking/price/day-price` (`:527`, 2 args)
before it's summed, and the whole period total via `jet-booking/price/total-price`
(`:531`, 4 args) — use `day-price` for a per-day override (e.g. a custom discount rule),
`total-price`/`breakdown` for a final adjustment to the aggregate.

`Price::get_default_price()` (`:79`) is filterable via `jet-booking/price/default-price`
(`:83`, 4 args: price, post_id, meta, Price instance) — the base "no seasonal/weekend/
rate override applies" price, added since 4.1.0. The whole `$meta` array itself is
filterable earlier via `jet-booking/price/meta` (`price.php:64`, 2 args: meta, post_id) —
the right hook for e.g. pulling pricing from a different CPT/CCT source entirely instead
of post meta.

## The form-submission booking-insert pipeline

`Apartment_Booking_Trait::run_action()` (`includes/apartment-booking-trait.php:33`) is
the shared logic both JetFormBuilder's booking action and the multivendor/plain booking
paths run through (it's a **trait**, mixed into the action classes under
`includes/formbuilder-plugin/` and `includes/actions/`, not called directly). Real facts
worth knowing before writing custom logic that runs alongside it:

- It only runs at all when `jet_abaf()->settings->get('booking_mode') === 'plain'`
  (`:35`) — early-returns otherwise, so a site using the WooCommerce booking mode
  doesn't go through this trait (see `jetbooking-integrations` for that path instead).
- **`jet-booking/form-action/pre-process`** (`:198`, 3 args: `$pre_processed` [starts
  `false`], `$booking` [the assembled array, before DB insert], `$this` [the action
  instance, giving access to `getRequest()`/`getSettings()`]) — **the real
  "intercept/replace the whole insert" hook**: if a callback returns a truthy value,
  `run_action()` returns that value immediately and **never calls `insert_booking()`**
  — use this to redirect to a custom storage backend or a payment-gated flow, not
  `jet-booking/db/booking-inserted` (that one only fires *after* a real DB insert).
- Multi-unit handling: when `booking_multiple_units` is truthy on the form settings
  and the apartment has more than one unit (`:207-231`), it inserts one row per unit
  requested (`booking_capacity_field`), throwing a `Base_Handler_Exception` if fewer
  units are available than requested capacity. After all inserts,
  **`jet-booking/form-action/bookings-group-inserted`** fires (`:253`, 1 arg: array of
  all inserted booking rows) **only when more than one row was inserted** — a
  single-unit booking never fires this, only `jet-booking/form-action/booking-inserted`
  (`:243`, 1 arg: the new `booking_id`, fired inside the same loop for **every** booking
  including group ones).
- Throws `Base_Handler_Exception` (not a `WP_Error`/silent `false`) for user-facing
  validation failures (past date, missing time, insufficient units, etc.) — a JFB action
  wrapping this trait needs to catch that exception type specifically to surface the
  message, not check a return value.

## Booking statuses — `jet_abaf()->statuses`, not hardcoded strings

`Statuses` (`includes/statuses.php`) is the single source of truth for status buckets —
`valid_statuses()` (`:59`), `in_progress_statuses()` (`:73`), `finished_statuses()`
(`:86`), `invalid_statuses()` (`:100`), `temporary_status()` (`:113`, singular — one
string, the placeholder status used while a WC checkout is in flight), `get_statuses()`
(`:127`, the full labeled list for UI). Prefer these accessors over hardcoding
`'pending'`/`'cancelled'`/etc. literals — several DB queries above (`get_booked_units()`,
`get_booked_apartments()`) already filter against `invalid_statuses()` +
`temporary_status()` combined, and a custom status-dependent query should do the same to
stay consistent with what the plugin itself considers "blocks a unit".

## Gotchas

- **`check_in_date`/`check_out_date` are Unix timestamps in the DB**, not date strings —
  `insert_booking()` even does `$booking['check_in_date']++` (`db/manager.php:426`) to
  dodge exact-timestamp overlap edge cases; don't pass `'2026-01-01'` directly.
- **`Price_Meta::get_default_meta()`** (`price-meta.php:51`) calls `get_the_ID()` with no
  argument — it only works correctly inside The Loop / on a singular admin post-edit
  screen, not from an arbitrary REST/CLI context; pass an explicit post id to
  `get_post_meta()` yourself if computing pricing outside a normal post context.
- `jet_abaf()->tools->is_booking_post( $post_id )` (`tools.php:231`) is the gate
  `run_action()` itself uses (`apartment-booking-trait.php:47`) to confirm a submitted
  post id actually belongs to a configured booking post type before trusting it — reuse
  this rather than checking `get_post_type()` against `settings->get('apartment_post_type')`
  by hand.

## How this was verified

Read `includes/plugin.php`, `jet-booking.php`, `includes/booking-functions.php`,
`includes/db/manager.php`, `includes/price.php`,
`includes/dashboard/post-meta/price-meta.php`, `includes/apartment-booking-trait.php`,
`includes/statuses.php`, `includes/settings.php`, `includes/tools.php`, and
`includes/components/manager.php` in JetBooking 4.1.2.1 source
(`plugins/jet-booking/`), confirming the singleton pattern, every hook name/arg count
cited above (`jet-booking/form-action/pre-process`, `booking-inserted`,
`bookings-group-inserted`, `jet-booking/db/booking-inserted`/`booking-updated`/
`before-booking-delete`, `jet-booking/price/meta`/`default-price`/`day-price`/
`total-price`/`breakdown`) by direct file:line citation, and the `jet_abaf_price`
single-serialized-meta-key structure (correcting/confirming a lead from
`.claude/skills/_other-plugins-backlog/OTHER-PLUGINS.md`'s gist audit, which described it
correctly). Not yet verified against a running site — JetBooking is not installed on the
sandbox; see `TEST-REGIMEN.md`.
