<?php
/**
 * AGENT-TEST-SUITE: jetbooking-integrations
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetbooking-integrations"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetbooking-integrations. See docs/test-harness-guide.md.
 *
 * NOT YET RUN — JetBooking is not installed on the sandbox as of 2026-07-16 (see
 * TEST-REGIMEN.md). Most of this skill's claims need a real WooCommerce cart/checkout
 * flow or a live browser executing booking-init.js — genuinely not automatable inside
 * one PHP request (see TEST-REGIMEN.md Tests 1-3,5-9, left as manual/browser steps).
 * What IS safely automatable from a single request: direct apply_filters()/do_action()
 * probes against filters that don't require a real cart/order object to fire (jbi-1
 * through jbi-4), plus source-presence checks for hook names and shipped-JS
 * filter/trigger strings that can't be safely live-triggered without WooCommerce
 * installed (jbi-5, jbi-6).
 */

add_action( 'agent-test/run-suite/jetbooking-integrations', function() {

	$suite = 'jetbooking-integrations';

	// jbi-1: jet-booking/google-calendar-url/args is real and honored — call
	// get_calendar_url_by_booking() directly with a fake booking array, no live WC/order needed.
	try {
		$google_cal = function_exists( 'jet_abaf' ) ? jet_abaf()->google_cal : null;
		$pass = false;
		$url = null;
		if ( $google_cal && method_exists( $google_cal, 'get_calendar_url_by_booking' ) ) {
			add_filter( 'jet-booking/google-calendar-url/args', function( $args, $booking ) {
				$args['location'] = 'AGENT-TEST-MARKER';
				$args['details']  = ''; // apply_filters(array_filter(...)) should drop this.
				return $args;
			}, 10, 2 );
			$url = $google_cal->get_calendar_url_by_booking( array(
				'apartment_id'   => 0,
				'check_in_date'  => strtotime( '+1 day' ),
				'check_out_date' => strtotime( '+2 days' ),
			) );
			remove_all_filters( 'jet-booking/google-calendar-url/args' );
			$pass = is_string( $url )
				&& false !== strpos( $url, 'AGENT-TEST-MARKER' )
				&& false === strpos( $url, 'details=' );
		}
		agent_test_assert(
			$suite, 'jbi-1',
			'SKILL.md "Google Calendar export link": jet-booking/google-calendar-url/args mutates the args array before add_query_arg(), and array_filter() drops empty-string values from the final URL',
			$pass,
			array( 'location_present' => true, 'empty_details_dropped' => true ),
			array( 'url' => $url ),
			'includes/google-calendar.php:183 — direct method call with a fake booking array, no real booking/order required'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbi-1', 'google-calendar-url/args live filter test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbi-2: jet-booking/wc-integration/pre-cart-info is a real, honored filter — direct apply_filters() probe
	// (no live WooCommerce add-to-cart flow needed to prove the hook is wired and honored).
	try {
		add_filter( 'jet-booking/wc-integration/pre-cart-info', function( $pre, $data, $form_data, $form_id ) {
			return array( 'agent_test_marker' => true );
		}, 10, 4 );
		$result = apply_filters( 'jet-booking/wc-integration/pre-cart-info', false, array(), array(), 0 );
		remove_all_filters( 'jet-booking/wc-integration/pre-cart-info' );
		$pass = is_array( $result ) && ! empty( $result['agent_test_marker'] );
		agent_test_assert(
			$suite, 'jbi-2',
			'SKILL.md "WooCommerce cart/order integration": jet-booking/wc-integration/pre-cart-info is applied with ($pre_cart_info=false, $data, $form_data, $form_id) and a truthy return is what short-circuits cart-info building',
			$pass,
			array( 'marker_present' => true ),
			array( 'result' => $result ),
			'includes/wc-integration/manager.php:626, includes/wc-integration/class-wc-order-details-builder.php:22 — direct apply_filters() probe, not a live add-to-cart flow'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbi-2', 'pre-cart-info filter honored smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbi-3: jet-booking/assets/config wraps the JetABAFData localization array — direct apply_filters() probe.
	try {
		add_filter( 'jet-booking/assets/config', function( $config ) {
			$config['agent_test_marker'] = true;
			return $config;
		} );
		$result = apply_filters( 'jet-booking/assets/config', array( 'ajax_url' => 'x', 'css_url' => 'y', 'post_id' => 0 ) );
		remove_all_filters( 'jet-booking/assets/config' );
		$pass = is_array( $result ) && ! empty( $result['agent_test_marker'] ) && isset( $result['ajax_url'] );
		agent_test_assert(
			$suite, 'jbi-3',
			'SKILL.md "Front-end JS API...PHP side feeding the JS": jet-booking/assets/config wraps the whole JetABAFData localized array (1 arg) after ajax_url/css_url/post_id defaults are merged in',
			$pass,
			array( 'marker_present' => true, 'defaults_preserved' => true ),
			array( 'result' => $result ),
			'includes/assets.php:130 — direct apply_filters() probe on the same shape Assets::enqueue_deps() builds'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbi-3', 'assets/config filter honored smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbi-4: check-in-out field's default-value/attributes filters are real and honored — direct apply_filters() probes.
	try {
		add_filter( 'jet-booking/form-fields/check-in-out/default-value', function( $value ) {
			return 'agent-test-default';
		} );
		add_filter( 'jet-booking/form-fields/check-in-out/attributes', function( $attrs ) {
			return 'data-agent-test="1"';
		} );
		$default_value = apply_filters( 'jet-booking/form-fields/check-in-out/default-value', '' );
		$attrs = apply_filters( 'jet-booking/form-fields/check-in-out/attributes', '' );
		remove_all_filters( 'jet-booking/form-fields/check-in-out/default-value' );
		remove_all_filters( 'jet-booking/form-fields/check-in-out/attributes' );
		$pass = ( 'agent-test-default' === $default_value ) && ( false !== strpos( $attrs, 'agent-test' ) );
		agent_test_assert(
			$suite, 'jbi-4',
			'SKILL.md "...PHP side feeding the JS": jet-booking/form-fields/check-in-out/default-value and .../attributes are both real, single-arg filters applied inside Check_In_Out_Render_Trait::field_template()',
			$pass,
			array( 'default_value_overridden' => true, 'attrs_overridden' => true ),
			array( 'default_value' => $default_value, 'attrs' => $attrs ),
			'includes/form-fields/check-in-out-render-trait.php:43,46 — direct apply_filters() probes, not a live field render'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbi-4', 'check-in-out field filters honored smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbi-5: WooCommerce-specific hooks that need a real cart/order object to fire meaningfully
	// (process-order, myaccount/endpoint, myaccount/bookings-per-page, booking-price, cart-item-data,
	// booking-data) are source-presence checks only here — see TEST-REGIMEN.md Tests 1-3,5-6 for the
	// live-cart/checkout-driven versions of these claims.
	try {
		$checks = array(
			array( 'includes/wc-integration/manager.php', "do_action( 'jet-booking/wc-integration/process-order'" ),
			array( 'includes/wc-integration/manager.php', "apply_filters( 'jet-booking/wc-integration/myaccount/endpoint'" ),
			array( 'includes/wc-integration/manager.php', "apply_filters( 'jet-booking/wc-integration/myaccount/bookings-per-page'" ),
			array( 'includes/wc-integration/class-wc-cart-manager.php', "apply_filters( 'jet-booking/wc-integration/booking-data'" ),
			array( 'includes/wc-integration/class-wc-cart-manager.php', "apply_filters( 'jet-booking/wc-integration/cart-item-data'" ),
			array( 'includes/wc-integration/class-wc-order-manager.php', "apply_filters( 'jet-booking/wc-integration/booking-price'" ),
		);
		$results = array();
		$all_pass = true;
		foreach ( $checks as $c ) {
			list( $rel_path, $needle ) = $c;
			$file = WP_PLUGIN_DIR . '/jet-booking/' . $rel_path;
			$contents = file_exists( $file ) ? file_get_contents( $file ) : '';
			$found = ( '' !== $contents ) && ( false !== strpos( $contents, $needle ) );
			$results[ $rel_path . '::' . $needle ] = $found;
			$all_pass = $all_pass && $found;
		}
		agent_test_assert(
			$suite, 'jbi-5',
			'SKILL.md "WooCommerce cart/order integration": process-order, myaccount/endpoint, myaccount/bookings-per-page, booking-data, cart-item-data, booking-price are all present in the live-installed JetBooking source',
			$all_pass,
			array( 'all_present' => true ),
			$results,
			'includes/wc-integration/manager.php:183,241,755, includes/wc-integration/class-wc-cart-manager.php:346,365, includes/wc-integration/class-wc-order-manager.php:836 — source-presence only, needs a real WC cart/checkout to live-trigger (see TEST-REGIMEN.md)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbi-5', 'WC-integration hook source-presence check', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbi-6: shipped booking-init.js contains every documented JS filter/trigger name and global object reference —
	// source-presence only (JS execution needs a real browser rendering a booking field, see TEST-REGIMEN.md Tests 7-9).
	try {
		$js_file = WP_PLUGIN_DIR . '/jet-booking/assets/js/booking-init.js';
		$js_contents = file_exists( $js_file ) ? file_get_contents( $js_file ) : '';
		$needles = array(
			'jet-booking.input.config',
			'jet-booking.calendar.config',
			'jet-booking.apartment-price',
			'jet-booking.dynamic-price',
			'jet-booking.day-price',
			'jet-booking.date-range-picker.date-show-params',
			"jet-booking/init'",
			'jet-booking/init-field',
			'jet-booking/init-calendar',
			'window.jetBookingState',
			'JetPlugins.hooks',
			'is deprecated since 2.6.3',
		);
		$found = array();
		$all_pass = ( '' !== $js_contents );
		foreach ( $needles as $n ) {
			$ok = ( '' !== $js_contents ) && ( false !== strpos( $js_contents, $n ) );
			$found[ $n ] = $ok;
			$all_pass = $all_pass && $ok;
		}
		agent_test_assert(
			$suite, 'jbi-6',
			'SKILL.md "Front-end JS API": every documented JetPlugins.hooks filter name, jQuery trigger event name, and the deprecated window.jetBookingState.filters.add() shim are present verbatim in the shipped assets/js/booking-init.js bundle',
			$all_pass,
			array( 'all_strings_present' => true ),
			array( 'file_readable' => ( '' !== $js_contents ), 'found' => $found ),
			'assets/js/booking-init.js:3-11,179,281,339,377,810,940,1074,1152,1249 — source-presence only, not live-executed (needs a real page rendering a JetBooking field, see TEST-REGIMEN.md Tests 7-9)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbi-6', 'booking-init.js source-presence check', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
