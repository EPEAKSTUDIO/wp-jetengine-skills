<?php
/**
 * AGENT-TEST-SUITE: jetcomparewishlist-integrations
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetcomparewishlist-integrations"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetcomparewishlist-integrations. See docs/test-harness-guide.md.
 *
 * Uses a minimal duck-typed product stub (not a real WC_Product) for the
 * template-functions tests, since those methods only ever call specific getters
 * (get_id(), get_permalink()-target id, get_type(), get_title()) and never do an
 * `instanceof WC_Product` check — this keeps the suite runnable even with an empty
 * product catalog.
 */

if ( ! class_exists( 'Agent_Test_CW_Product_Stub' ) ) {
	class Agent_Test_CW_Product_Stub {
		public function get_id() { return 999999; }
		public function get_type() { return 'simple'; }
		public function get_title() { return 'Agent Test Product'; }
		public function get_name() { return 'Agent Test Product'; }
	}
}

add_action( 'agent-test/run-suite/jetcomparewishlist-integrations', function() {

	$suite = 'jetcomparewishlist-integrations';

	// cwi-1: jet-cw/template-functions/title is genuinely applied.
	try {
		if ( ! function_exists( 'jet_cw_functions' ) ) {
			throw new \Exception( 'jet_cw_functions() not available' );
		}
		add_filter( 'jet-cw/template-functions/title', function( $title ) {
			return $title . '<!--agent-test-marker-->';
		} );
		$product = new Agent_Test_CW_Product_Stub();
		$result  = jet_cw_functions()->get_title( $product );
		$pass    = is_string( $result ) && false !== strpos( $result, '<!--agent-test-marker-->' );
		agent_test_assert(
			$suite, 'cwi-1',
			'SKILL.md "jet-cw/template-functions/*": get_title() applies the jet-cw/template-functions/title filter to its returned markup',
			$pass,
			array( 'marker_present' => true ),
			array( 'result' => $result ),
			'includes/class-jet-cw-functions.php:39-54'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'cwi-1', 'template-functions/title filter smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// cwi-2: jet-cw/template-functions/compare-custom-field/{$field_key} is dynamically keyed —
	// a filter registered for one field key doesn't leak into a different, unhooked field key.
	try {
		if ( ! function_exists( 'jet_cw_functions' ) ) {
			throw new \Exception( 'jet_cw_functions() not available' );
		}
		add_filter( 'jet-cw/template-functions/compare-custom-field/agent_test_field', function( $value ) {
			return 'AGENT_TEST_OVERRIDE';
		} );
		$product = new Agent_Test_CW_Product_Stub();

		$hooked_result   = jet_cw_functions()->get_custom_field( $product, array( 'compare_table_custom_field' => 'agent_test_field' ) );
		$unhooked_result = jet_cw_functions()->get_custom_field( $product, array( 'compare_table_custom_field' => 'agent_test_field_unhooked' ) );

		$pass = ( false !== strpos( $hooked_result, 'AGENT_TEST_OVERRIDE' ) ) && ( false === strpos( $unhooked_result, 'AGENT_TEST_OVERRIDE' ) );
		agent_test_assert(
			$suite, 'cwi-2',
			'SKILL.md "jet-cw/template-functions/compare-custom-field/{$field_key}": the filter tag is built per literal $field_key — a callback registered for one field key does not affect a different field key',
			$pass,
			array( 'hooked_field_overridden' => true, 'unhooked_field_unaffected' => true ),
			array( 'hooked_result' => $hooked_result, 'unhooked_result' => $unhooked_result ),
			'includes/class-jet-cw-functions.php:514'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'cwi-2', 'compare-custom-field dynamic-tag smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// cwi-3: compatibility packages exist as loaded classes iff the companion plugin's own class exists.
	try {
		$pairs = array(
			array( 'Jet_CW_Engine_Package', 'Jet_Engine' ),
			array( 'Jet_CW_Woo_Builder_Package', 'Jet_Woo_Builder' ),
			array( 'Jet_CW_Popup_Package', 'Jet_Popup' ),
		);
		$results  = array();
		$all_pass = true;
		foreach ( $pairs as $pair ) {
			list( $package_class, $companion_class ) = $pair;
			$package_loaded  = class_exists( $package_class );
			$companion_exists = class_exists( $companion_class );
			$agree = ( $package_loaded === $companion_exists );
			$results[ $package_class ] = array( 'package_loaded' => $package_loaded, 'companion_exists' => $companion_exists, 'agree' => $agree );
			$all_pass = $all_pass && $agree;
		}
		agent_test_assert(
			$suite, 'cwi-3',
			'SKILL.md "Three thin compatibility packages": Jet_CW_Engine_Package/Jet_CW_Woo_Builder_Package/Jet_CW_Popup_Package are loaded classes exactly when Jet_Engine/Jet_Woo_Builder/Jet_Popup respectively already exist',
			$all_pass,
			array( 'all_pairs_agree' => true ),
			$results,
			'includes/lib/compatibility/class-jet-cw-compatibility.php:138-161'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'cwi-3', 'compatibility package class_exists() gate smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// cwi-4: jet-cw/in-elementor filter is the final word on in_elementor()'s return value.
	try {
		if ( ! function_exists( 'jet_cw' ) || ! jet_cw()->integration ) {
			throw new \Exception( 'jet_cw()->integration not available' );
		}
		add_filter( 'jet-cw/in-elementor', function( $result ) {
			return 'AGENT_TEST_FORCED';
		} );
		$result = jet_cw()->integration->in_elementor();
		$pass   = ( 'AGENT_TEST_FORCED' === $result );
		agent_test_assert(
			$suite, 'cwi-4',
			'SKILL.md "in_elementor() — a private-flag Elementor-AJAX detector": the jet-cw/in-elementor filter unconditionally overrides in_elementor()\'s return value, regardless of the internal wp_doing_ajax()/edit-mode/preview-mode logic',
			$pass,
			array( 'result' => 'AGENT_TEST_FORCED' ),
			array( 'result' => $result ),
			'includes/class-jet-cw-integration.php:47-60'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'cwi-4', 'jet-cw/in-elementor override smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// cwi-5: jet-cw/dashboard/settings/{$setting} filter is honored, keyed by literal setting name,
	// with no cross-talk between differently-named settings.
	try {
		if ( ! function_exists( 'jet_cw' ) || ! jet_cw()->settings ) {
			throw new \Exception( 'jet_cw()->settings not available' );
		}
		add_filter( 'jet-cw/dashboard/settings/agent_test_setting', function( $value ) {
			return 'AGENT_TEST_OVERRIDE';
		} );
		$hooked_result   = jet_cw()->settings->get( 'agent_test_setting', 'default_value' );
		$unhooked_result = jet_cw()->settings->get( 'agent_test_setting_unhooked', 'default_value' );

		$pass = ( 'AGENT_TEST_OVERRIDE' === $hooked_result ) && ( 'default_value' === $unhooked_result );
		agent_test_assert(
			$suite, 'cwi-5',
			'SKILL.md "Settings-layer filters (admin-only)": jet-cw/dashboard/settings/{$setting} is keyed by the literal setting name — overriding one setting does not affect Jet_CW_Settings::get() calls for a different, unhooked setting name',
			$pass,
			array( 'hooked_result' => 'AGENT_TEST_OVERRIDE', 'unhooked_result' => 'default_value' ),
			array( 'hooked_result' => $hooked_result, 'unhooked_result' => $unhooked_result ),
			'includes/class-jet-cw-settings.php:199-207'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'cwi-5', 'dashboard/settings per-key filter smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
