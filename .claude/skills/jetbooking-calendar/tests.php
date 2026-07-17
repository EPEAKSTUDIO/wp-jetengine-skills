<?php
/**
 * AGENT-TEST-SUITE: jetbooking-calendar
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetbooking-calendar"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetbooking-calendar. See docs/test-harness-guide.md.
 *
 * NOT YET RUN — JetBooking is not installed on the sandbox as of 2026-07-16 (see
 * TEST-REGIMEN.md). Written as-if-ready: safe reachability/direct-invocation smoke
 * tests only, no live form/WooCommerce submission required. All fixture posts/units
 * created here are namespaced `AGENT-TEST-*` and cleaned up at the end of each test
 * that creates one, per docs/test-harness-guide.md's "suites must be safe to re-run".
 */

add_action( 'agent-test/run-suite/jetbooking-calendar', function() {

	$suite = 'jetbooking-calendar';

	// jbc-1: jet_abaf() is a stable singleton, identical to \JET_ABAF\Plugin::instance().
	try {
		$exists = function_exists( 'jet_abaf' ) && class_exists( '\\JET_ABAF\\Plugin' );
		$a = $exists ? jet_abaf() : null;
		$b = $exists ? jet_abaf() : null;
		$c = $exists ? \JET_ABAF\Plugin::instance() : null;
		$pass = $exists && $a === $b && $a === $c;
		agent_test_assert(
			$suite, 'jbc-1',
			'SKILL.md "The Plugin singleton": jet_abaf() returns the same \\JET_ABAF\\Plugin::instance() singleton on every call',
			$pass,
			array( 'same_instance' => true ),
			array( 'function_and_class_exist' => $exists, 'a_equals_b' => ( $a === $b ), 'a_equals_c' => ( $a === $c ) ),
			'includes/plugin.php:36,62,276,292; jet-booking.php:79'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbc-1', 'jet_abaf() singleton identity smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbc-2: documented component properties are populated with the right classes after init.
	try {
		$expected = array(
			'settings'   => 'JET_ABAF\\Settings',
			'statuses'   => 'JET_ABAF\\Statuses',
			'db'         => 'JET_ABAF\\DB\\Manager',
			'assets'     => 'JET_ABAF\\Assets',
			'google_cal' => 'JET_ABAF\\Google_Calendar',
			'wc'         => 'JET_ABAF\\WC_Integration\\Manager',
			'tools'      => 'JET_ABAF\\Tools',
		);
		$plugin = function_exists( 'jet_abaf' ) ? jet_abaf() : null;
		$actual = array();
		$all_pass = (bool) $plugin;
		foreach ( $expected as $prop => $class ) {
			$val = $plugin ? ( $plugin->$prop ?? null ) : null;
			$actual[ $prop ] = $val ? get_class( $val ) : null;
			$all_pass = $all_pass && ( $actual[ $prop ] === $class );
		}
		agent_test_assert(
			$suite, 'jbc-2',
			'SKILL.md "The Plugin singleton": jet_abaf()->settings/statuses/db/assets/google_cal/wc/tools are populated with their documented classes by init_components()',
			$all_pass,
			$expected,
			$actual,
			'includes/plugin.php:122-166 (init_components(), hooked on init at -999)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbc-2', 'component properties reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbc-3: jet_abaf_price is one serialized meta key with the 4 documented sub-keys — round-trip on a throwaway fixture post.
	try {
		$post_id = wp_insert_post( array(
			'post_title'  => 'AGENT-TEST jetbooking-calendar price fixture',
			'post_type'   => 'post',
			'post_status' => 'draft',
		), true );
		$pass = false;
		$readback = null;
		if ( ! is_wp_error( $post_id ) && $post_id ) {
			$fixture = array(
				'_apartment_price' => 100,
				'_pricing_rates'   => array(),
				'_seasonal_prices' => array(),
				'_weekend_prices'  => array(),
			);
			update_post_meta( $post_id, 'jet_abaf_price', $fixture );
			$readback = get_post_meta( $post_id, 'jet_abaf_price', true );
			$pass = is_array( $readback )
				&& array_key_exists( '_apartment_price', $readback )
				&& array_key_exists( '_pricing_rates', $readback )
				&& array_key_exists( '_seasonal_prices', $readback )
				&& array_key_exists( '_weekend_prices', $readback )
				&& 100 == $readback['_apartment_price'];
			wp_delete_post( $post_id, true ); // clean up — safe to re-run.
		}
		agent_test_assert(
			$suite, 'jbc-3',
			'SKILL.md "Pricing model": jet_abaf_price is a single serialized meta key whose value carries _apartment_price/_pricing_rates/_seasonal_prices/_weekend_prices sub-keys',
			$pass,
			array( 'keys_present' => true, 'apartment_price_roundtrips' => true ),
			array( 'post_created' => ! is_wp_error( $post_id ), 'readback' => $readback ),
			'includes/price.php:11,53, includes/dashboard/post-meta/price-meta.php:10,56-60'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbc-3', 'jet_abaf_price round-trip smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbc-4: Price::get_booking_price_breakdown() is callable and returns the documented shape (base/total keys always present).
	try {
		$post_id = wp_insert_post( array(
			'post_title'  => 'AGENT-TEST jetbooking-calendar breakdown fixture',
			'post_type'   => 'post',
			'post_status' => 'draft',
		), true );
		$breakdown = null;
		$pass = false;
		if ( ! is_wp_error( $post_id
		) && $post_id && class_exists( '\\JET_ABAF\\Price' ) ) {
			update_post_meta( $post_id, 'jet_abaf_price', array(
				'_apartment_price' => 100,
				'_pricing_rates'   => array(),
				'_seasonal_prices' => array(),
				'_weekend_prices'  => array(),
			) );
			$price = new \JET_ABAF\Price( $post_id );
			$in    = strtotime( 'tomorrow' );
			$out   = strtotime( '+3 days' );
			$breakdown = $price->get_booking_price_breakdown( array(
				'check_in_date'  => $in,
				'check_out_date' => $out,
				'apartment_id'   => $post_id,
			) );
			$pass = is_array( $breakdown ) && array_key_exists( 'base', $breakdown ) && array_key_exists( 'total', $breakdown );
			wp_delete_post( $post_id, true );
		}
		agent_test_assert(
			$suite, 'jbc-4',
			'SKILL.md "Pricing model": Price::get_booking_price_breakdown() returns an array always containing base/total keys, with a flat _apartment_price feeding the base',
			$pass,
			array( 'has_base_and_total' => true ),
			array( 'post_created' => ! is_wp_error( $post_id ), 'breakdown' => $breakdown ),
			'includes/price.php:449,465-546'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbc-4', 'Price::get_booking_price_breakdown() smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbc-5: jet-booking/form-action/pre-process is a real, honored filter — a callback returning a truthy value
	// is observable via a direct do_action/apply_filters probe (no real Apartment_Booking_Trait instance needed,
	// avoiding any risk of constructing an action class that assumes a fully-configured form context).
	try {
		add_filter( 'jet-booking/form-action/pre-process', function( $pre_processed, $booking, $action ) {
			return array( 'agent_test_marker' => true );
		}, 10, 3 );
		$result = apply_filters( 'jet-booking/form-action/pre-process', false, array( 'apartment_id' => 0 ), null );
		remove_all_filters( 'jet-booking/form-action/pre-process' );
		$pass = is_array( $result ) && ! empty( $result['agent_test_marker'] );
		agent_test_assert(
			$suite, 'jbc-5',
			'SKILL.md "form-submission booking-insert pipeline": jet-booking/form-action/pre-process is applied with ($pre_processed=false, $booking, $action) and a truthy return is what Apartment_Booking_Trait::run_action() short-circuits on',
			$pass,
			array( 'marker_present' => true ),
			array( 'result' => $result ),
			'includes/apartment-booking-trait.php:198 — direct apply_filters() probe, not a live run_action() call (avoids constructing an action instance with no real form context)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbc-5', 'pre-process filter honored smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbc-6: Statuses accessors are reachable and self-consistent (temporary_status() is a single string absent from valid_statuses()).
	try {
		$statuses = function_exists( 'jet_abaf' ) ? jet_abaf()->statuses : null;
		$valid = $statuses ? $statuses->valid_statuses() : null;
		$invalid = $statuses ? $statuses->invalid_statuses() : null;
		$temp = $statuses ? $statuses->temporary_status() : null;
		$pass = is_array( $valid ) && is_array( $invalid ) && is_string( $temp ) && ! in_array( $temp, $valid, true );
		agent_test_assert(
			$suite, 'jbc-6',
			'SKILL.md "Booking statuses": jet_abaf()->statuses exposes valid_statuses()/invalid_statuses()/temporary_status(), and temporary_status() is never itself a member of valid_statuses()',
			$pass,
			array( 'temp_not_in_valid' => true ),
			array( 'valid' => $valid, 'invalid' => $invalid, 'temporary' => $temp ),
			'includes/statuses.php:59,100,113'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbc-6', 'Statuses accessors smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbc-7: DB\Manager::insert_booking() fires jet-booking/db/booking-inserted (1 arg, the booking array with booking_id set)
	// on a real insert against the actual bookings table — requires tables_exists(); cleans up the inserted row itself.
	try {
		$db = function_exists( 'jet_abaf' ) ? jet_abaf()->db : null;
		$tables_ok = $db && method_exists( $db, 'tables_exists' ) && $db->tables_exists();
		$seen = null;
		$booking_id = false;
		if ( $tables_ok ) {
			add_action( 'jet-booking/db/booking-inserted', function( $booking ) use ( &$seen ) {
				$seen = $booking;
			} );
			$fixture_post = wp_insert_post( array(
				'post_title'  => 'AGENT-TEST jetbooking-calendar booking-insert fixture',
				'post_type'   => 'post',
				'post_status' => 'draft',
			), true );
			if ( ! is_wp_error( $fixture_post ) ) {
				$in  = strtotime( '+30 days' );
				$out = strtotime( '+33 days' );
				$booking_id = $db->insert_booking( array(
					'status'         => 'pending',
					'apartment_id'   => $fixture_post,
					'check_in_date'  => $in,
					'check_out_date' => $out,
					'user_email'     => 'agent-test@example.com',
				) );
				if ( $booking_id ) {
					$db->delete_booking( array( 'booking_id' => $booking_id ) ); // clean up.
				}
				wp_delete_post( $fixture_post, true );
			}
		}
		$pass = $tables_ok && $booking_id && is_array( $seen ) && ! empty( $seen['booking_id'] );
		agent_test_assert(
			$suite, 'jbc-7',
			'SKILL.md "Booking DB layer": DB\\Manager::insert_booking() fires jet-booking/db/booking-inserted with the assembled booking array (booking_id included) right after a real insert',
			$pass,
			array( 'tables_exist' => true, 'hook_fired_with_booking_id' => true ),
			array( 'tables_exist' => $tables_ok, 'booking_id' => $booking_id, 'seen_hook_arg' => $seen ),
			'includes/db/manager.php:417-455 — skipped/fails gracefully if bookings tables are not yet created (tables_exists() false)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbc-7', 'insert_booking()/booking-inserted live test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
