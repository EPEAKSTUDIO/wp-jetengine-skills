# Test regimen: jetbooking-calendar

**UNBLOCKED and live-verified 2026-07-16 — JetBooking 4.1.2.1 is now active on
jackfruit.epeak.studio.** Deployed `tests.php` as Code Snippets snippet id 40
("AGENT-TEST-SUITE: jetbooking-calendar") and ran `GET /agent-test/v1/suite/jetbooking-calendar`:
**7/7 pass**, no fixes needed. Every claim below (Plugin singleton reachability, the
`init_components()`-populated manager properties, the `jet_abaf_price` serialized-meta
pricing model, `Price::get_booking_price_breakdown()`'s base/total shape,
`jet-booking/form-action/pre-process`'s short-circuit signature, the valid/invalid/
temporary status sets, and `DB\Manager::insert_booking()`'s `jet-booking/db/booking-inserted`
hook) was confirmed live, not just against source.

## Prerequisites (once installed)

- JetBooking active, with its DB tables created (`jet_abaf()->db->tables_exists()`
  should be `true` — the plugin creates these during its own setup wizard, not on bare
  plugin activation).
- At least one post of the configured `apartment_post_type` (check
  `jet_abaf()->settings->get('apartment_post_type')`) with `jet_abaf_price` meta set
  (`_apartment_price` at minimum).
- `booking_mode` setting = `'plain'` for Tests 4-5 (the form-action pipeline early-
  returns otherwise — see `SKILL.md`'s "form-submission booking-insert pipeline"
  section).

## Test 1: `jet_abaf()` reaches the singleton, and it's the same instance every call

**Claim:** `jet_abaf()` returns `\JET_ABAF\Plugin::instance()`, a private-constructor
singleton created once (`plugin.php:36,62,276`); `jet_abaf() === jet_abaf()` and
`jet_abaf() === \JET_ABAF\Plugin::instance()` always.

**Trigger:** call `jet_abaf()` twice and `\JET_ABAF\Plugin::instance()` once in the same
request; compare with `===`.

**Pass criteria:** all three references are the identical object.

## Test 2: component properties are real, populated objects

**Claim:** `jet_abaf()->db`, `->settings`, `->statuses`, `->wc`, `->google_cal`,
`->tools` are all non-null instances of their documented classes
(`DB\Manager`/`Settings`/`Statuses`/`WC_Integration\Manager`/`Google_Calendar`/`Tools`),
populated by `init_components()` (`plugin.php:122-166`) on `init` at priority `-999`.

**Trigger:** read each property's `get_class()` from a hook that fires after `init`
(e.g. `wp_loaded`, or directly inside the REST callback since REST routes register on
`rest_api_init`, safely after `init`).

**Pass criteria:** every property is non-null and `get_class()` matches the documented
class name.

## Test 3: `jet_abaf_price` is one serialized meta key with 4 sub-keys, not 4 separate keys

**Claim:** `get_post_meta( $post_id, 'jet_abaf_price', true )` returns an array with
`_apartment_price`/`_pricing_rates`/`_seasonal_prices`/`_weekend_prices` keys
(`price-meta.php:56-60`) — and `_apartment_price`/`_pricing_rates` may **also** exist as
their own separate top-level meta keys via the backward-compat write
(`price-meta.php:106-116`), which is not a bug.

**Setup:** on a fixture apartment post, set `jet_abaf_price` to a known array via
`update_post_meta()` in the test itself (self-contained, no admin-UI dependency).

**Trigger:** read it back with `get_post_meta( $id, 'jet_abaf_price', true )`.

**Pass criteria:** the read-back array matches what was written, keyed exactly as
documented.

## Test 4: `Price::get_booking_price_breakdown()` layers seasonal → weekend → rate correctly

**Claim:** per-day price resolution priority is seasonal override, then weekend
override, then duration-rate override (`price.php:465-529`), and the returned breakdown
array only includes non-negligible adjustment keys.

**Setup:** on a fixture post, set `jet_abaf_price` with a known `_apartment_price` (e.g.
100), one `_seasonal_prices` entry covering a known date range with its own `price`, and
one `_weekend_prices` override for a known weekday.

**Trigger:** call `(new \JET_ABAF\Price($post_id))->get_booking_price_breakdown([
'check_in_date' => <ts>, 'check_out_date' => <ts>, 'apartment_id' => $post_id ])`.

**Pass criteria:** returned `total`/`base`/`seasonal`/`weekend` keys match a hand-computed
expected value for the fixture's date range.

## Test 5: `jet-booking/form-action/pre-process` short-circuits `insert_booking()`

**Claim:** a truthy return from this filter (`apartment-booking-trait.php:198`) makes
`run_action()` return that value directly, **without** calling
`DB\Manager::insert_booking()` — confirmed by checking `jet-booking/db/booking-inserted`
never fires when the pre-process filter short-circuits.

**Setup:**
```php
add_filter( 'jet-booking/form-action/pre-process', function( $pre_processed, $booking, $action ) {
    return [ 'agent_test_short_circuited' => true ];
}, 10, 3 );
add_action( 'jet-booking/db/booking-inserted', function( $booking ) {
    update_option( 'agent_test_booking_inserted_fired', true );
} );
```

**Trigger:** invoke `run_action()` on a real (or minimally-stubbed) action instance using
the `Apartment_Booking_Trait`, with `booking_mode` set to `'plain'`.

**Pass criteria:** the trait returns `['agent_test_short_circuited' => true]`, and
`agent_test_booking_inserted_fired` option was never set.

## Test 6: multi-unit booking fires `bookings-group-inserted` only when >1 row inserted

**Claim:** `jet-booking/form-action/bookings-group-inserted` (`:253`) fires with the full
array of inserted rows only when `$multiple_units` is true **and** more than one booking
was actually inserted; a single-unit booking only ever fires `booking-inserted` (`:243`)
per row, never the group event.

**Setup:** fixture apartment with 2+ units in the `Tables\Units` table (via
`jet_abaf()->db->units->insert()`), `booking_multiple_units` = true on the action
settings, `booking_capacity_field` requesting 2 units.

**Trigger:** run the booking action end-to-end (real form submission, or direct
`run_action()` call with a stubbed `getSettings()`/`getRequest()`).

**Pass criteria:** `booking-inserted` fires twice (once per unit), `bookings-group-
inserted` fires once with a 2-element array. Repeat with capacity 1 and confirm
`bookings-group-inserted` never fires.

## Test 7: `Statuses` accessors partition consistently

**Claim:** `jet_abaf()->statuses->invalid_statuses()` and `->temporary_status()` are the
two buckets every "is this unit still free" DB query filters against
(`db/manager.php:261-266`, `:357-358`).

**Trigger:** call `valid_statuses()`, `invalid_statuses()`, `temporary_status()`,
`get_statuses()` and compare their contents.

**Pass criteria:** `temporary_status()` returns a single string not present in
`valid_statuses()`; `invalid_statuses()` + `[temporary_status()]` covers every status
`get_statuses()` lists that isn't in `valid_statuses()`/`in_progress_statuses()`.
