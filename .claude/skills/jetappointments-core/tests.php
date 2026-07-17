<?php
/**
 * AGENT-TEST-SUITE: jetappointments-core
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetappointments-core"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetappointments-core. See docs/test-harness-guide.md.
 *
 * These are class/accessor-reachability and source-presence smoke tests — no live
 * appointment/booking fixture exists yet on the sandbox (no Service/Provider CPT wired
 * up). Extend with real fixture-driven tests once one exists (see TEST-REGIMEN.md's
 * Test 4).
 *
 * Safety note (HANDOFF.md "Adding a new Crocoblock plugin"): jet_apb() is a private-
 * constructor singleton (JET_APB\Plugin::instance()) already instantiated on
 * plugins_loaded by the plugin's own bootstrap file — this suite never calls `new` on
 * Plugin or any of its ->db/->calendar/etc. component classes, only reads properties
 * off the existing jet_apb() instance.
 */

add_action( 'agent-test/run-suite/jetappointments-core', function() {

	$suite = 'jetappointments-core';

	// apb-1: jet_apb() resolves to JET_APB\Plugin::instance(), with documented component properties populated.
	try {
		$plugin = function_exists( 'jet_apb' ) ? jet_apb() : null;
		$class  = $plugin ? get_class( $plugin ) : null;
		$components = array(
			'db'       => 'JET_APB\\DB\\Manager',
			'settings' => 'JET_APB\\Admin\\Settings',
			'calendar' => 'JET_APB\\Calendar',
			'statuses' => 'JET_APB\\Statuses',
			'wc'       => 'JET_APB\\WC_Integration',
			'rest_api' => 'JET_APB\\Rest_API\\Manager',
		);
		$found = array();
		$all_pass = ( 'JET_APB\\Plugin' === $class );
		foreach ( $components as $prop => $expected_class ) {
			$value = $plugin ? $plugin->$prop : null;
			$actual_class = $value ? get_class( $value ) : null;
			$found[ $prop ] = $actual_class;
			$all_pass = $all_pass && ( $actual_class === $expected_class );
		}
		agent_test_assert(
			$suite, 'apb-1',
			'SKILL.md "The accessor": jet_apb() returns JET_APB\\Plugin::instance(); ->db/->settings/->calendar/->statuses/->wc/->rest_api are populated with the documented classes',
			$all_pass,
			array( 'plugin_class' => 'JET_APB\\Plugin', 'components' => $components ),
			array( 'plugin_class' => $class, 'components' => $found ),
			'jet-appointments-booking.php:40-42, includes/plugin.php:80,205-254,388-397'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'apb-1', 'jet_apb() singleton + component reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// apb-2: the five documented custom DB tables exist under the wp_jet_* naming scheme.
	try {
		global $wpdb;
		$db = function_exists( 'jet_apb' ) ? jet_apb()->db : null;
		$tables_to_check = array(
			'appointments'          => $db ? $db->appointments : null,
			'appointments_meta'     => $db ? $db->appointments_meta : null,
			'excluded_dates'        => $db ? $db->excluded_dates : null,
			'appointments_external' => $db ? $db->appointments_external : null,
			'external_meta'         => $db ? $db->external_meta : null,
		);
		$results  = array();
		$all_pass = (bool) $db;
		foreach ( $tables_to_check as $key => $obj ) {
			$table_name = ( $obj && method_exists( $obj, 'table' ) ) ? $obj->table() : null;
			$exists     = $table_name ? (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) : false;
			$results[ $key ] = array( 'table' => $table_name, 'exists' => $exists );
			$all_pass = $all_pass && $exists;
		}
		agent_test_assert(
			$suite, 'apb-2',
			'SKILL.md "Data model": jet_apb()->db->{appointments,appointments_meta,excluded_dates,appointments_external,external_meta}->table() resolve to real wp_jet_* tables that exist in the DB',
			$all_pass,
			array( 'all_tables_exist' => true ),
			$results,
			'includes/db/base.php:64-66, includes/db/{appointments,appointments-meta,excluded-dates,appointments-external,external-meta}.php table_slug() definitions'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'apb-2', 'DB table existence smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// apb-3: jet-apb/calendar/custom-schedule is a real, live-honored filter with the documented 5-arg signature.
	try {
		$calendar = function_exists( 'jet_apb' ) ? jet_apb()->calendar : null;
		if ( ! $calendar || ! method_exists( $calendar, 'get_schedule_settings' ) ) {
			throw new \Exception( 'jet_apb()->calendar not available' );
		}
		$seen_args = null;
		add_filter( 'jet-apb/calendar/custom-schedule', function( $value, $meta_key, $default_value, $provider, $service ) use ( &$seen_args ) {
			$seen_args = compact( 'value', 'meta_key', 'default_value', 'provider', 'service' );
			return $value;
		}, 10, 5 );
		// Fake, harmless ids (0 = no such post) — get_schedule_settings() only reads post meta/settings, no writes.
		$calendar->get_schedule_settings( 0, 0, 'agent_test_default', 'agent_test_meta_key' );
		remove_all_filters( 'jet-apb/calendar/custom-schedule' );
		$pass = is_array( $seen_args )
			&& 'agent_test_meta_key' === $seen_args['meta_key']
			&& 'agent_test_default' === $seen_args['default_value'];
		agent_test_assert(
			$suite, 'apb-3',
			'SKILL.md "Calendar customization": jet-apb/calendar/custom-schedule fires as apply_filters($value, $meta_key, $default_value, $provider, $service) from Calendar::get_schedule_settings(), live-driven with fake provider/service ids',
			$pass,
			array( 'meta_key' => 'agent_test_meta_key', 'default_value' => 'agent_test_default' ),
			array( 'seen_args' => $seen_args ),
			'includes/calendar.php:615-647'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'apb-3', 'jet-apb/calendar/custom-schedule live filter test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// apb-4: public-actions custom-page-content filters are present in source (source-presence only —
	// the real trigger needs allow_action_links enabled plus a valid confirm/cancel token in $_GET,
	// not safe to fabricate against a real appointment on this sandbox yet).
	try {
		$file = WP_PLUGIN_DIR . '/jet-appointments-booking/includes/public-actions/manager.php';
		$contents = file_exists( $file ) ? file_get_contents( $file ) : '';
		$has_action_filter = false !== strpos( $contents, "apply_filters( 'jet-apb/public-actions/custom-' . \$key . '-page-content'" );
		// 2026-07-16: first version used a single-line strpos() for both call sites; failed for 'error'
		// because the real call (render_error_page(), manager.php:193-196) wraps its args across
		// multiple lines (render_action_result_page(\n\t\t\t'error', ...) — a formatting difference,
		// not a behavior difference. Switched to a whitespace-tolerant regex so line-wrapping doesn't matter.
		$has_error_call  = (bool) preg_match( '/render_action_result_page\(\s*\'error\'/', $contents );
		$has_action_call = (bool) preg_match( '/render_action_result_page\(\s*\'action\'/', $contents );
		agent_test_assert(
			$suite, 'apb-4',
			'SKILL.md "Public confirm/cancel action-link pages": render_action_result_page() builds the filter name from $key, called with literal \'action\' (success) and \'error\' (failure) — so the two real hook names are jet-apb/public-actions/custom-action-page-content and jet-apb/public-actions/custom-error-page-content',
			( '' !== $contents && $has_action_filter && $has_error_call && $has_action_call ),
			array( 'file_readable' => true, 'filter_present' => true, 'action_key_used' => true, 'error_key_used' => true ),
			array( 'file_readable' => ( '' !== $contents ), 'filter_present' => $has_action_filter, 'action_key_used' => $has_action_call, 'error_key_used' => $has_error_call ),
			'includes/public-actions/manager.php:115-131,191-204 — source-presence only, see TEST-REGIMEN.md Test 5'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'apb-4', 'public-actions custom-page-content hook presence test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// apb-5: REST endpoints register through JetEngine's own REST manager (jet-engine/rest-api/init-endpoints),
	// not a dedicated jet-appointments-booking REST namespace.
	try {
		$rest_api = function_exists( 'jet_apb' ) ? jet_apb()->rest_api : null;
		$class    = $rest_api ? get_class( $rest_api ) : null;
		$hook_registered = has_action( 'jet-engine/rest-api/init-endpoints' );
		$urls = ( $rest_api && method_exists( $rest_api, 'get_urls' ) ) ? $rest_api->get_urls() : array();
		$expected_keys = array(
			'date_slots', 'refresh_dates', 'service_providers', 'provider_services',
			'appointments_list', 'delete_appointment', 'update_appointment', 'add_appointment',
			'update_workflows', 'appointment_meta', 'get_appointment', 'external_meta',
		);
		$missing_keys = array_diff( $expected_keys, array_keys( $urls ) );
		$pass = 'JET_APB\\Rest_API\\Manager' === $class
			&& false !== $hook_registered
			&& empty( $missing_keys );
		agent_test_assert(
			$suite, 'apb-5',
			'SKILL.md "REST API surface": JET_APB\\Rest_API\\Manager hooks jet-engine/rest-api/init-endpoints (not its own REST namespace) and jet_apb()->rest_api->get_urls() returns all 12 documented endpoint keys',
			$pass,
			array( 'class' => 'JET_APB\\Rest_API\\Manager', 'hook_registered' => true, 'missing_keys' => array() ),
			array( 'class' => $class, 'hook_registered' => $hook_registered, 'url_keys_found' => array_keys( $urls ), 'missing_keys' => array_values( $missing_keys ) ),
			'includes/rest-api/manager.php:14-16,23-40,50-64'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'apb-5', 'REST API registration + get_urls() smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
