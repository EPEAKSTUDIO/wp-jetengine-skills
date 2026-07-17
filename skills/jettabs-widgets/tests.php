<?php
/**
 * AGENT-TEST-SUITE: jettabs-widgets
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jettabs-widgets"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jettabs-widgets. See docs/test-harness-guide.md.
 *
 * Requires JetTabs For Elementor (2.3.2) active. All assertions here are either generic
 * WP-mechanism smoke tests (REST route registration, has_filter()/apply_filters() on a
 * real hook, a read-only SHOW TABLES check) or source-presence checks (file_get_contents
 * + strpos against the plugin's own currently-installed files) — nothing here renders a
 * real Elementor widget or touches the jet_cache DB table's contents.
 */

add_action( 'agent-test/run-suite/jettabs-widgets', function() {

	$suite = 'jettabs-widgets';

	// jtw-1: the jet-tabs-api/v1 REST namespace and its three documented endpoints are
	// registered once JetTabs has run its own rest_api_init hook (Jet_Tabs\Rest_Api::register_routes()).
	try {
		$jettabs_active = function_exists( 'jet_tabs' );
		$routes         = $jettabs_active ? rest_get_server()->get_routes() : array();
		$expected_routes = array(
			'/jet-tabs-api/v1/elementor-template',
			'/jet-tabs-api/v1/plugin-settings',
			'/jet-tabs-api/v1/clear-tabs-cache',
		);
		$found = array();
		foreach ( $expected_routes as $expected ) {
			$found[ $expected ] = false;
			foreach ( array_keys( $routes ) as $route ) {
				// Endpoint routes append a regex query-params suffix (get_query_params()),
				// so match by prefix rather than exact string.
				if ( 0 === strpos( $route, $expected ) ) {
					$found[ $expected ] = true;
					break;
				}
			}
		}
		$all_found = $jettabs_active && ! in_array( false, $found, true );
		agent_test_assert(
			$suite, 'jtw-1',
			'SKILL.md "Ajax/lazy-loaded template content": jet-tabs-api/v1 REST namespace exposes elementor-template, plugin-settings, and clear-tabs-cache routes, registered by Jet_Tabs\\Rest_Api::register_routes() on rest_api_init',
			$all_found,
			array( 'jettabs_active' => true, 'all_routes_found' => true ),
			array( 'jettabs_active' => $jettabs_active, 'routes' => $found ),
			'plugins/jet-tabs/includes/rest-api/rest-api.php:136-156; includes/rest-api/endpoints/elementor-template.php, plugin-settings.php, clear-tabs-cache.php'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jtw-1', 'jet-tabs-api/v1 route registration smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jtw-2: jet_set_transient()/jet_get_transient() exist and their bodies delegate to
	// Jet_Cache\Manager's DB-backed cache, per SKILL.md's "two separate cache stores" gotcha.
	// This only confirms the functions exist and what they'd do IF called — whether they are
	// ever actually called anywhere in the plugin is a live-DB-state question, see TEST-REGIMEN.md Test 3.
	try {
		$funcs_exist = function_exists( 'jet_set_transient' ) && function_exists( 'jet_get_transient' );
		$file = WP_PLUGIN_DIR . '/jet-tabs/includes/modules/jet-cache/inc/functions.php';
		$contents = file_exists( $file ) ? file_get_contents( $file ) : '';
		$delegates_correctly = ( '' !== $contents )
			&& false !== strpos( $contents, 'Jet_Cache\Manager::get_instance()->db_manager->set_cache' )
			&& false !== strpos( $contents, 'Jet_Cache\Manager::get_instance()->db_manager->get_cache' );
		$pass = $funcs_exist && $delegates_correctly;
		agent_test_assert(
			$suite, 'jtw-2',
			'SKILL.md "Gotcha: two separate, disconnected cache stores": jet_set_transient()/jet_get_transient() exist and delegate to Jet_Cache\\Manager::get_instance()->db_manager->set_cache()/get_cache() (the custom DB-table cache, separate from WP core transients)',
			$pass,
			array( 'funcs_exist' => true, 'delegates_to_db_manager' => true ),
			array( 'funcs_exist' => $funcs_exist, 'delegates_to_db_manager' => $delegates_correctly ),
			'plugins/jet-tabs/includes/modules/jet-cache/inc/functions.php:9-19'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jtw-2', 'jet_set_transient/jet_get_transient delegation smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jtw-3: the custom wp_jet_cache DB table exists (created by DB_Manager::init_db_required()
	// once the jet-cache module has loaded). Read-only SHOW TABLES check, no writes.
	try {
		global $wpdb;
		$table_name = $wpdb->prefix . 'jet_cache';
		$exists = ( $table_name === $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) );
		agent_test_assert(
			$suite, 'jtw-3',
			'SKILL.md "Gotcha": the custom {$wpdb->prefix}jet_cache DB table exists once the jet-cache module has bootstrapped, per DB_Manager::tables()/init_db_required()',
			$exists,
			array( 'table_exists' => true ),
			array( 'table_exists' => $exists, 'table_name' => $table_name ),
			'plugins/jet-tabs/includes/modules/jet-cache/inc/db-manager.php:43-63,87-95'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jtw-3', 'wp_jet_cache table existence smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jtw-4: jet-tabs/widgets/template_id is a real, mutable filter (generic WP mechanism,
	// no widget instance needed). jet-tabs/widgets/template_content's 4-arg call site is
	// checked via source-presence only, since it fires mid-render() with a real widget's
	// rendered markup as $content.
	try {
		$marker_cb = function( $template_id, $widget ) {
			return $template_id + 1000;
		};
		add_filter( 'jet-tabs/widgets/template_id', $marker_cb, 10, 2 );
		$result = apply_filters( 'jet-tabs/widgets/template_id', 42, null );
		remove_filter( 'jet-tabs/widgets/template_id', $marker_cb, 10 );
		$filter_pass = ( 1042 === $result );

		$file = WP_PLUGIN_DIR . '/jet-tabs/includes/addons/jet-tabs-widget.php';
		$contents = file_exists( $file ) ? file_get_contents( $file ) : '';
		$content_filter_present = ( '' !== $contents ) && false !== strpos( $contents, "'jet-tabs/widgets/template_content'," );

		$pass = $filter_pass && $content_filter_present;
		agent_test_assert(
			$suite, 'jtw-4',
			'SKILL.md "jet-tabs/widgets/template_id and jet-tabs/widgets/template_content filters": template_id is applied to the raw repeater value ($item_template_id, $widget) before resolving the post, and template_content wraps the synchronously-rendered template markup with 4 args ($content, $item, $dom_id, $widget) in the non-ajax path',
			$pass,
			array( 'template_id_filter_mutable' => true, 'template_content_call_site_present' => true ),
			array( 'template_id_filter_result' => $result, 'template_content_call_site_present' => $content_filter_present ),
			'plugins/jet-tabs/includes/addons/jet-tabs-widget.php:1707,1728-1734'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jtw-4', 'jet-tabs/widgets/template_id + template_content smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
