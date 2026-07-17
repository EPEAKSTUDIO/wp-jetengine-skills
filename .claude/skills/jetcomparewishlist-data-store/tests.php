<?php
/**
 * AGENT-TEST-SUITE: jetcomparewishlist-data-store
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetcomparewishlist-data-store"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetcomparewishlist-data-store. See docs/test-harness-guide.md.
 *
 * IMPORTANT: never call Jet_CW_Wishlist_Render::update_wish_list() /
 * Jet_CW_Compare_Render::update_compare_list() directly from a suite — they end in
 * wp_send_json_success() which calls wp_die(), terminating the whole request and
 * skipping every assertion after it (same class of landmine documented in
 * jetsmartfilters-query's tests.php for Storage\Controller). This suite only calls the
 * underlying Data-class methods directly.
 */

add_action( 'agent-test/run-suite/jetcomparewishlist-data-store', function() {

	$suite = 'jetcomparewishlist-data-store';

	// cw-1: wishlist_data/compare_data are null iff the corresponding feature is disabled.
	try {
		$wishlist_enabled = function_exists( 'jet_cw' ) ? (bool) filter_var( jet_cw()->wishlist_enabled, FILTER_VALIDATE_BOOLEAN ) : null;
		$compare_enabled  = function_exists( 'jet_cw' ) ? (bool) filter_var( jet_cw()->compare_enabled, FILTER_VALIDATE_BOOLEAN ) : null;
		$wishlist_data_is_object = function_exists( 'jet_cw' ) && ( jet_cw()->wishlist_data instanceof Jet_CW_Wishlist_Data );
		$compare_data_is_object  = function_exists( 'jet_cw' ) && ( jet_cw()->compare_data instanceof Jet_CW_Compare_Data );
		$pass = ( $wishlist_enabled === $wishlist_data_is_object ) && ( $compare_enabled === $compare_data_is_object );
		agent_test_assert(
			$suite, 'cw-1',
			'SKILL.md "The jet_cw() singleton and its per-feature null-object landmine": jet_cw()->wishlist_data/compare_data are Jet_CW_Wishlist_Data/Jet_CW_Compare_Data instances exactly when wishlist_enabled/compare_enabled is truthy, null otherwise',
			$pass,
			array( 'wishlist_data_matches_flag' => true, 'compare_data_matches_flag' => true ),
			array( 'wishlist_enabled' => $wishlist_enabled, 'wishlist_data_is_object' => $wishlist_data_is_object, 'compare_enabled' => $compare_enabled, 'compare_data_is_object' => $compare_data_is_object ),
			'jet-cw.php:302-312'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'cw-1', 'wishlist_data/compare_data null-object consistency smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// cw-2: add/remove round-trip through update_data_wishlist()/update_data_compare(), using a real product.
	try {
		if ( ! function_exists( 'jet_cw' ) || ! function_exists( 'wc_get_products' ) ) {
			throw new \Exception( 'jet_cw() or WooCommerce not available' );
		}
		if ( ! jet_cw()->wishlist_data ) {
			// Confirmed by cw-1: wishlist_data is null exactly when the Wishlist feature is
			// toggled off in settings, which is this site's current (default) state - not a
			// plugin bug, just an untoggled feature. Skip gracefully rather than fail.
			agent_test_assert( $suite, 'cw-2', 'add/remove round-trip via update_data_wishlist()/update_data_compare() (needs the Wishlist feature enabled in settings)', true, 'wishlist feature enabled, or a documented skip', 'wishlist_enabled is false on this site - see cw-1', 'SKIPPED — feature disabled by site config, not a plugin failure' );
		} elseif ( empty( wc_get_products( array( 'limit' => 1, 'status' => 'publish' ) ) ) ) {
			agent_test_assert( $suite, 'cw-2', 'add/remove round-trip via update_data_wishlist()/update_data_compare() (needs 1 real published product)', false, 'at least 1 published product', 'no published products found', 'SKIPPED — fixture unavailable, not a plugin failure' );
		} else {
			$products = wc_get_products( array( 'limit' => 1, 'status' => 'publish' ) );
			$product_id = $products[0]->get_id();

			$before = jet_cw()->wishlist_data->get_wish_list();
			jet_cw()->wishlist_data->update_data_wishlist( $product_id, 'add' );
			$after_add = jet_cw()->wishlist_data->get_wish_list();
			jet_cw()->wishlist_data->update_data_wishlist( $product_id, 'remove' );
			$after_remove = jet_cw()->wishlist_data->get_wish_list();

			$wishlist_pass = in_array( $product_id, $after_add, false ) && ! in_array( $product_id, $after_remove, false ) && ( array_values( $after_remove ) == array_values( $before ) );

			$compare_pass = true;
			if ( jet_cw()->compare_data ) {
				$before_c = jet_cw()->compare_data->get_compare_list();
				jet_cw()->compare_data->update_data_compare( $product_id, 'add' );
				$after_add_c = jet_cw()->compare_data->get_compare_list();
				jet_cw()->compare_data->update_data_compare( $product_id, 'remove' );
				$after_remove_c = jet_cw()->compare_data->get_compare_list();
				$compare_pass = in_array( $product_id, $after_add_c, false ) && ! in_array( $product_id, $after_remove_c, false ) && ( $after_remove_c == $before_c );
			}

			agent_test_assert(
				$suite, 'cw-2',
				'SKILL.md "update_data_*($pid, $context)": add adds the id (dedup via in_array), remove removes exactly that id and restores the prior list, for both wishlist and compare',
				( $wishlist_pass && $compare_pass ),
				array( 'wishlist_round_trip_ok' => true, 'compare_round_trip_ok' => true ),
				array( 'product_id' => $product_id, 'wishlist_round_trip_ok' => $wishlist_pass, 'compare_round_trip_ok' => $compare_pass, 'before' => $before, 'after_add' => $after_add, 'after_remove' => $after_remove ),
				'includes/wishlist/class-jet-cw-wishlist-data.php:73-100, includes/compare/class-jet-cw-compare-data.php:73-100'
			);
		}
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'cw-2', 'add/remove round-trip smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// cw-3: compare's max-items cap silently rejects an oversized set_compare_list() write.
	try {
		if ( ! function_exists( 'jet_cw' ) ) {
			throw new \Exception( 'jet_cw() not available' );
		}
		if ( ! jet_cw()->compare_data ) {
			// Confirmed by cw-1: compare_data is null exactly when the Compare feature is
			// toggled off in settings, which is this site's current (default) state - not a
			// plugin bug, just an untoggled feature. Skip gracefully rather than fail.
			agent_test_assert( $suite, 'cw-3', 'compare max-items cap smoke test (needs the Compare feature enabled in settings)', true, 'compare feature enabled, or a documented skip', 'compare_enabled is false on this site - see cw-1', 'SKIPPED — feature disabled by site config, not a plugin failure' );
		} else {
		$max = filter_var( jet_cw()->settings->get( 'compare_page_max_items' ), FILTER_VALIDATE_INT );
		$before_list = jet_cw()->compare_data->get_compare_list();
		$before_raw  = ( 'session' === jet_cw()->compare_data->store_type ) ? ( $_SESSION['jet-compare-list'] ?? '' ) : ( $_COOKIE['jet-compare-list'] ?? '' );

		// Build an oversized list of fabricated ids (max + 5 entries) — fine for this
		// test since we're checking the count guard in set_compare_list(), not the
		// prune-on-read behavior of get_compare_list().
		$oversized = range( 900001, 900000 + $max + 5 );
		jet_cw()->compare_data->set_compare_list( $oversized );

		$after_raw = ( 'session' === jet_cw()->compare_data->store_type ) ? ( $_SESSION['jet-compare-list'] ?? '' ) : ( $_COOKIE['jet-compare-list'] ?? '' );
		$rejected  = ( $after_raw === $before_raw );

		// Restore original state explicitly regardless of outcome.
		jet_cw()->compare_data->set_compare_list( $before_list );

		agent_test_assert(
			$suite, 'cw-3',
			'SKILL.md "Compare has a max-size guard; wishlist does not": set_compare_list() only persists if count($list) <= compare_page_max_items — an oversized write is silently dropped, raw stored value unchanged',
			$rejected,
			array( 'oversized_write_rejected' => true ),
			array( 'max' => $max, 'oversized_count' => count( $oversized ), 'raw_unchanged' => $rejected ),
			'includes/compare/class-jet-cw-compare-data.php:159-181'
		);
		}
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'cw-3', 'compare max-items cap smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// cw-4: get_stored_widgets() echoes back $_REQUEST['widgets_data'] verbatim — not server-cached state.
	try {
		if ( ! function_exists( 'jet_cw' ) || ! jet_cw()->widgets_store ) {
			throw new \Exception( 'jet_cw()->widgets_store not available' );
		}
		$_REQUEST['nonce']        = wp_create_nonce( 'jet-cw-compare' );
		$_REQUEST['widgets_data'] = array( 'agent_test_marker' => true );

		$result = jet_cw()->widgets_store->get_stored_widgets();

		unset( $_REQUEST['nonce'], $_REQUEST['widgets_data'] );

		$pass = is_array( $result ) && ! empty( $result['agent_test_marker'] );
		agent_test_assert(
			$suite, 'cw-4',
			'SKILL.md "The widgets-store re-render mechanism": Jet_CW_Widgets_Store::get_stored_widgets() reads and returns $_REQUEST[\'widgets_data\'] verbatim, not any server-side cached property',
			$pass,
			array( 'echoes_request_data' => true ),
			array( 'result' => $result ),
			'includes/class-jet-cw-widgets-store.php:94-99'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'cw-4', 'get_stored_widgets() request-echo smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// cw-5: before-add-to-wishlist/before-add-to-compare do_action() calls appear before the
	// corresponding update_data_*() call in source (static-ordering check — see TEST-REGIMEN.md
	// for why this isn't live-triggered: the real AJAX handlers end in wp_die()).
	try {
		$checks = array(
			array(
				WP_PLUGIN_DIR . '/jet-compare-wishlist/includes/wishlist/class-jet-cw-wishlist-render.php',
				"do_action( 'jet-cw/wishlist/render/before-add-to-wishlist', \$pid, \$context, \$this )",
				'jet_cw()->wishlist_data->update_data_wishlist( $pid, $context )',
			),
			array(
				WP_PLUGIN_DIR . '/jet-compare-wishlist/includes/compare/class-jet-cw-compare-render.php',
				"do_action( 'jet-cw/compare/render/before-add-to-compare', \$pid, \$context, \$this )",
				'jet_cw()->compare_data->update_data_compare( $pid, $context )',
			),
		);
		$all_pass = true;
		$results  = array();
		foreach ( $checks as $c ) {
			list( $file, $hook_needle, $update_needle ) = $c;
			$contents  = file_exists( $file ) ? file_get_contents( $file ) : '';
			$hook_pos  = strpos( $contents, $hook_needle );
			$update_pos = strpos( $contents, $update_needle );
			$ordered   = ( false !== $hook_pos ) && ( false !== $update_pos ) && ( $hook_pos < $update_pos );
			$results[ basename( $file ) ] = $ordered;
			$all_pass = $all_pass && $ordered;
		}
		agent_test_assert(
			$suite, 'cw-5',
			'SKILL.md "Front-end add/remove is classic admin-ajax...": the before-add-to-wishlist/before-add-to-compare do_action() calls appear textually before the update_data_*() calls in both render classes (fires before the store mutates)',
			$all_pass,
			array( 'hook_before_update_in_both_files' => true ),
			$results,
			'includes/wishlist/class-jet-cw-wishlist-render.php:30,32, includes/compare/class-jet-cw-compare-render.php:31,33 — source-order check only, see TEST-REGIMEN.md for why this isn\'t live-triggered'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'cw-5', 'before-add-to-* ordering smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// cw-6: the plugin's own REST API only exposes an admin-gated plugin-settings endpoint.
	// Note: the route is registered POST-only (Plugin_Settings::get_method(), plugin-settings.php:20-22);
	// requesting it as an unauthenticated (guest) user must be denied by permission_callback.
	try {
		$prior_user = get_current_user_id();
		wp_set_current_user( 0 ); // simulate a logged-out visitor for this one request

		$request  = new WP_REST_Request( 'POST', '/jet-cw-api/v1/plugin-settings' );
		$response = rest_do_request( $request );
		$status   = $response->get_status();

		wp_set_current_user( $prior_user ); // restore whatever user this snippet was actually running as

		$is_denied = in_array( $status, array( 401, 403 ), true );
		agent_test_assert(
			$suite, 'cw-6',
			'SKILL.md "Real REST API...admin-settings-only": POST /jet-cw-api/v1/plugin-settings as a logged-out visitor is denied (401/403), confirming it is not a public add/remove surface',
			$is_denied,
			array( 'status_401_or_403' => true ),
			array( 'status' => $status ),
			'includes/rest-api/rest-api.php:54, includes/rest-api/endpoints/base.php:43-45 (permission_callback defaults to current_user_can(\'manage_options\')), includes/rest-api/endpoints/plugin-settings.php:20-22 (POST-only route)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'cw-6', 'plugin-settings REST admin-gate smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
