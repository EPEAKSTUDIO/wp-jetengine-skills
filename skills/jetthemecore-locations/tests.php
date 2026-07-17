<?php
/**
 * AGENT-TEST-SUITE: jetthemecore-locations
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetthemecore-locations"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetthemecore-locations. See docs/test-harness-guide.md.
 *
 * NOT YET RUN: JetThemeCore is not installed on the sandbox (jackfruit.epeak.studio) as
 * of this writing — see TEST-REGIMEN.md. Written as-if-ready.
 */

add_action( 'agent-test/run-suite/jetthemecore-locations', function() {

	$suite = 'jetthemecore-locations';

	// jtl-1: Structures registry has at least the 6 core built-ins; Locations registry has at
	// least the 4 core is_location()=true ones, keyed by location_name(), not raw structure id.
	//
	// Live-verified correction (2026-07-16): with WooCommerce active (as it is on this
	// sandbox), JetThemeCore registers 6 ADDITIONAL WooCommerce-specific structures/locations
	// (jet_products_archive, jet_single_product, jet_products_card, jet_products_checkout,
	// jet_products_checkout_endpoint, jet_account_page, and matching location keys
	// products-archive/single-product/products-card/products-checkout/
	// products-checkout-endpoint/account-page) beyond the 6 "core" structures/4 "core"
	// locations this SKILL.md originally documented as the complete set. The original "has
	// exactly" claim was wrong on a WooCommerce-active site — corrected to a
	// superset/subset check (missing check only, not an exact-set-equality check).
	try {
		$structures = function_exists( 'jet_theme_core' ) ? jet_theme_core()->structures->get_structures() : array();
		$locations  = function_exists( 'jet_theme_core' ) ? jet_theme_core()->locations->get_locations() : array();

		$expected_structure_ids = array( 'jet_page', 'jet_header', 'jet_footer', 'jet_section', 'jet_archive', 'jet_single' );
		$found_structure_ids = is_array( $structures ) ? array_keys( $structures ) : array();
		$missing_structures = array_diff( $expected_structure_ids, $found_structure_ids );

		$expected_location_keys = array( 'header', 'footer', 'single', 'archive' );
		$found_location_keys = is_array( $locations ) ? array_keys( $locations ) : array();
		$missing_locations = array_diff( $expected_location_keys, $found_location_keys );

		$pass = empty( $missing_structures ) && empty( $missing_locations );

		agent_test_assert(
			$suite, 'jtl-1',
			'SKILL.md "Two registries...": Structures has at least the 6 core built-in ids; Locations has at least the 4 core header/footer/single/archive keys (both may have MORE entries if WooCommerce is active — see SKILL.md correction)',
			$pass,
			array( 'structure_ids_present' => $expected_structure_ids, 'location_keys_present' => $expected_location_keys ),
			array( 'found_structure_ids' => $found_structure_ids, 'missing_structures' => array_values( $missing_structures ), 'found_location_keys' => $found_location_keys, 'missing_locations' => array_values( $missing_locations ) ),
			'includes/template-structures/manager.php:34-50 (register_structures()), includes/locations/manager.php:41-43 (register_location())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jtl-1', 'Structures/Locations registry population test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jtl-2: get_structure_for_location('header')->get_id() === 'jet_header' — the bridge value
	// that feeds find_matched_conditions(), and the active-location filter fires with the
	// expected raw location string.
	try {
		$locations_manager = function_exists( 'jet_theme_core' ) ? jet_theme_core()->locations : null;
		$structure = $locations_manager ? $locations_manager->get_structure_for_location( 'header' ) : null;
		$structure_id = $structure ? $structure->get_id() : null;

		$seen_location = null;
		add_filter( 'jet-theme-core/location/do-location/active-location', function( $location ) use ( &$seen_location ) {
			$seen_location = $location;
			return $location;
		} );
		$locations_manager->do_location( 'header' );
		remove_all_filters( 'jet-theme-core/location/do-location/active-location' );

		$pass = ( 'jet_header' === $structure_id ) && ( 'header' === $seen_location );

		agent_test_assert(
			$suite, 'jtl-2',
			'SKILL.md "do_location()...": get_structure_for_location(\'header\')->get_id() === \'jet_header\' (the value passed to find_matched_conditions()), and the active-location filter fires with the raw \'header\' string before structure lookup',
			$pass,
			array( 'structure_id' => 'jet_header', 'seen_location' => 'header' ),
			array( 'structure_id' => $structure_id, 'seen_location' => $seen_location ),
			'includes/locations/manager.php:51-53,68,76 (do_location())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jtl-2', 'do_location() structure-id bridge test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jtl-3: do_location() short-circuits (returns false, fires no render hooks) when the
	// Theme Builder's is_theme_builder_render flag is active.
	try {
		$frontend_manager = function_exists( 'jet_theme_core' )
			? jet_theme_core()->theme_builder->frontend_manager
			: null;

		if ( ! $frontend_manager ) {
			throw new \Exception( 'theme_builder->frontend_manager not available' );
		}

		$original_flag = $frontend_manager->is_theme_builder_render;
		$frontend_manager->is_theme_builder_render = true;

		$hook_fired = false;
		add_action( 'jet-theme-core/location/before-render/elementor-location-content', function() use ( &$hook_fired ) {
			$hook_fired = true;
		} );
		add_action( 'jet-theme-core/location/before-render/default-location-content', function() use ( &$hook_fired ) {
			$hook_fired = true;
		} );

		$result = jet_theme_core()->locations->do_location( 'header' );

		// Restore.
		$frontend_manager->is_theme_builder_render = $original_flag;
		remove_all_actions( 'jet-theme-core/location/before-render/elementor-location-content' );
		remove_all_actions( 'jet-theme-core/location/before-render/default-location-content' );

		$pass = ( false === $result ) && ! $hook_fired;

		agent_test_assert(
			$suite, 'jtl-3',
			'SKILL.md "Bails immediately if the Theme Builder\'s own Page-Template override is active": do_location() returns false and fires no before-render hook when is_theme_builder_render is true',
			$pass,
			array( 'result' => false, 'hook_fired' => false ),
			array( 'result' => $result, 'hook_fired' => $hook_fired ),
			'includes/locations/manager.php:62-66'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jtl-3', 'Theme Builder override short-circuit test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jtl-4: the render-content filter's return value flows straight back out of do_location()
	// unmodified — confirming it's a bare status value the caller controls, not markup
	// do_location() itself post-processes.
	//
	// Live-verified gotcha (2026-07-16): the filter name is dynamic —
	// "jet-theme-core/location/render/{$content_type}-location-content" — where $content_type
	// comes from whatever real Header template (if any) actually matches on this site right
	// now (do_location()'s own find_matched_conditions() call, manager.php:76-88). This is a
	// shared sandbox with real site content, so hardcoding "elementor" is fragile — it happened
	// to be right on one run and wrong on a later run once site content changed. Compute the
	// same content_type do_location() would resolve, using the SAME classic Template
	// Conditions the plugin itself consults (not the separate Theme Builder Page Template
	// conditions probed elsewhere in this suite), and hook that exact filter name instead of
	// assuming "elementor".
	try {
		$structure = jet_theme_core()->locations->get_structure_for_location( 'header' );
		$template_ids = $structure ? jet_theme_core()->template_conditions_manager->find_matched_conditions( $structure->get_id() ) : false;
		$template_id = ( is_array( $template_ids ) && ! empty( $template_ids ) ) ? $template_ids[0] : $template_ids;
		$content_type = $template_id ? jet_theme_core()->templates->get_template_content_type( $template_id ) : 'elementor';

		$marker = 'AGENT_TEST_RENDER_MARKER_' . wp_rand( 1000, 9999 );
		$filter_name = "jet-theme-core/location/render/{$content_type}-location-content";

		add_filter( $filter_name, function( $status, $template_id, $location ) use ( $marker ) {
			return $marker;
		}, 10, 3 );

		$result = jet_theme_core()->locations->do_location( 'header' );

		remove_all_filters( $filter_name );

		$pass = ( $result === $marker );

		agent_test_assert(
			$suite, 'jtl-4',
			'SKILL.md "the actual template output happens inside the filter callback...": do_location()\'s return value is exactly whatever the content-type render filter callback returns, unmodified',
			$pass,
			array( 'result' => $marker ),
			array( 'result' => $result ),
			'includes/locations/manager.php:98,105 (apply_filters(...) assigned directly to $render_status, then returned)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jtl-4', 'render-content filter passthrough test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
