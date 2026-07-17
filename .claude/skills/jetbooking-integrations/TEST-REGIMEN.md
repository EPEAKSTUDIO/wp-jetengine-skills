# Test regimen: jetbooking-integrations

**UNBLOCKED and live-verified 2026-07-16 — JetBooking 4.1.2.1 and WooCommerce are both
now active on jackfruit.epeak.studio.** Deployed `tests.php` as Code Snippets snippet id 41
("AGENT-TEST-SUITE: jetbooking-integrations") and ran
`GET /agent-test/v1/suite/jetbooking-integrations`: **6/6 pass**, no fixes needed.

## Prerequisites (once installed)

- JetBooking + WooCommerce both active, with `booking_mode` set to a WC-backed mode, and
  at least one WC product wired to a booking apartment post (for Tests 1-4).
- Browser devtools (or a page rendering a JetBooking calendar/date-range field) for the
  JS-side tests (7-9) — these need the shipped `booking-init.js` actually executing in a
  real page, not just PHP source presence.

## Test 1: `jet-booking/wc-integration/pre-cart-info` short-circuits cart-info building

**Claim:** a truthy return from this filter (`manager.php:626`) is returned directly by
`Wc_Cart_Manager`'s caller without building the default cart-info array.

**Setup:**
```php
add_filter( 'jet-booking/wc-integration/pre-cart-info', function( $pre, $data, $form_data, $form_id ) {
    error_log( '[JBI-TEST] pre-cart-info fired, data=' . wp_json_encode( $data ) );
    return [ 'agent_test' => true ];
}, 10, 4 );
```

**Trigger:** add a JetBooking-enabled WC product to cart through a real booking form
submission.

**Expected observable:** the log line fires with the real `$data`/`$form_data`/
`$form_id`; the resulting cart item's JetBooking payload reflects the short-circuited
`['agent_test' => true]` value instead of the normally-computed cart info.

**Pass criteria:** confirms the short-circuit shape, not just that the filter fires.

## Test 2: `jet_abaf()->wc->data_key` is the real WC cart-item array key

**Claim:** JetBooking's booking payload lives under
`$cart_item_data[ jet_abaf()->wc->data_key ]` — a property, not a hardcoded string
literal like `'jet_abaf_booking'`.

**Setup:**
```php
add_filter( 'woocommerce_add_cart_item_data', function( $cart_item_data ) {
    error_log( '[JBI-TEST] data_key=' . jet_abaf()->wc->data_key . ' present=' . var_export( isset( $cart_item_data[ jet_abaf()->wc->data_key ] ), true ) );
    return $cart_item_data;
}, 20 );
```

**Trigger:** add a JetBooking-enabled product with a selected date range to cart.

**Expected observable:** logged `data_key` value (whatever string it actually is), and
`present=true` confirming that key holds the booking payload.

**Pass criteria:** the key name and presence match.

## Test 3: `process-order` fires once per booking line item, with the same 4-arg signature at every hook site

**Claim:** `jet-booking/wc-integration/process-order` (`manager.php:755`) fires with
`($data, $order_id, $order, $cart_item)`, and both `Wc_Order_Details_Builder::
set_order_meta()` and `Wc_Order_Manager::update_order_line_item_meta()` hook it
independently.

**Setup:**
```php
add_action( 'jet-booking/wc-integration/process-order', function( $data, $order_id, $order, $cart_item ) {
    error_log( '[JBI-TEST] process-order order_id=' . $order_id . ' data=' . wp_json_encode( $data ) );
}, 5, 4 );
```

**Trigger:** complete a checkout containing a JetBooking-enabled product.

**Expected observable:** one log line per booking line item in the order, each with the
matching order id and booking data.

**Pass criteria:** arg count/order matches; fires once per line item, not once per order.

## Test 4: Google Calendar URL — `jet-booking/google-calendar-url/args` sees the pre-`add_query_arg()` array

**Claim:** this filter (`google-calendar.php:183`) receives `$args` as a plain
associative array (`action`/`text`/`dates`/`details`/`location`) before
`add_query_arg()` runs, and `array_filter()` drops any key set to empty/false/null.

**Setup:**
```php
add_filter( 'jet-booking/google-calendar-url/args', function( $args, $booking ) {
    error_log( '[JBI-TEST] gcal args=' . wp_json_encode( $args ) );
    $args['location'] = 'AGENT-TEST location';
    $args['details']  = ''; // should be dropped from the final URL.
    return $args;
}, 10, 2 );
```

**Trigger:** call `jet_abaf()->google_cal->get_calendar_url_by_booking( $booking )` with
a real/fixture booking array, or click a rendered "Add to Google Calendar" link.

**Expected observable:** logged `$args` matches the documented shape; the returned URL
string contains `location=AGENT-TEST` but no `details=` param at all.

**Pass criteria:** both the args shape and the empty-value-dropping behavior confirmed.

## Test 5: `myaccount/endpoint` and `myaccount/bookings-per-page` actually control the My Account tab

**Claim:** overriding these filters (`manager.php:183,241`) changes the "My Account →
Bookings" endpoint slug and page size without code changes elsewhere.

**Setup:**
```php
add_filter( 'jet-booking/wc-integration/myaccount/endpoint', fn() => 'agent-test-bookings' );
add_filter( 'jet-booking/wc-integration/myaccount/bookings-per-page', fn() => 2 );
```

**Trigger:** visit My Account with a logged-in customer that has 3+ bookings.

**Expected observable:** the tab's URL segment is `/agent-test-bookings/`, and only 2
bookings are shown per page.

**Pass criteria:** both overrides visibly take effect.

## Test 6: `booking-price` filter's differing arg counts across call sites

**Claim:** the same filter name `jet-booking/wc-integration/booking-price` is applied
from 4 different files/call sites with `$booking_price` always first but a varying total
arg count — not a single fixed signature.

**Setup:** log `func_get_args()` from a callback registered with a generously-high
accepted-arg count (e.g. 5) at each relevant trigger path (order display, order line
recalculation, plain-mode booking display).

**Trigger:** view an order containing a booking from each of the code paths cited in
`SKILL.md` (`class-wc-order-manager.php:836,974`, `modes/plain.php:244,561`).

**Pass criteria:** confirms which call sites pass which additional args — useful to lock
down exactly, since `SKILL.md` currently only says "2-3 args, varies by call site."

## Test 7: `jet-booking/init` fires once, after all fields/calendars are wired

**Claim:** the plain jQuery trigger `jet-booking/init` (`booking-init.js:1249`) fires
once per page load, after every `jet-booking/init-field`/`jet-booking/init-calendar`
event for individual widgets has already fired.

**Setup:** in a snippet's front-end inline script (or via a `wp_footer` hook), add:
```js
let fieldCount = 0, calendarCount = 0;
jQuery(document)
  .on('jet-booking/init-field', () => fieldCount++)
  .on('jet-booking/init-calendar', () => calendarCount++)
  .on('jet-booking/init', () => console.log('[JBI-TEST] init fired, fields=' + fieldCount + ' calendars=' + calendarCount));
```

**Trigger:** load a page with at least one check-in/check-out field and one standalone
calendar widget.

**Expected observable:** console log shows `init` firing with `fieldCount`/`calendarCount`
already at their final page totals (not 0).

**Pass criteria:** confirms firing order — `init` is genuinely last.

## Test 8: `window.JetPlugins.hooks.applyFilters('jet-booking.input.config', ...)` is honored by the real widget

**Claim:** registering `jet-booking.input.config` via `JetPlugins.hooks.addFilter()`
lets a callback mutate the date-range-picker's config before it's applied.

**Setup:**
```js
window.JetPlugins.hooks.addFilter('jet-booking.input.config', 'agentTest', function(config) {
    config.__agentTestMarker = true;
    return config;
});
```

**Trigger:** load a page with a check-in/check-out field, open devtools, inspect the
initialized picker's config.

**Pass criteria:** the marker is present on the actual config object the picker library
receives (not just returned from the filter callback in isolation).

## Test 9: deprecated `window.jetBookingState.filters.add()` still forwards correctly

**Claim:** the pre-2.6.3 slash-form API (`booking-init.js:3-11`) is a real, still-working
forwarding shim to `JetPlugins.hooks.addFilter()`, not a dead no-op — and it logs a
`console.warn()` deprecation notice on every registration call.

**Setup:**
```js
window.jetBookingState.filters.add('jet-booking/input/config', function(config) {
    config.__agentTestDeprecatedMarker = true;
    return config;
});
```

**Trigger:** load a page with a check-in/check-out field; check the console for the
deprecation warning and inspect the resulting config object.

**Pass criteria:** both the warning appears and the marker ends up on the real config —
confirms the shim is functionally equivalent to the current API, just noisier.
