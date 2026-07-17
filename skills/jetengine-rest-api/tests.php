<?php
/**
 * AGENT-TEST-SUITE: jetengine-rest-api
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetengine-rest-api"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetengine-rest-api. See docs/test-harness-guide.md.
 *
 * Reuses existing fixtures: CCT `agent_test_cct` (id 15), Query Builder query id 16
 * (custom-content-type query against agent_test_cct), relation id 17
 * (posts::post -> cct::agent_test_cct). Drives real WP REST routes in-process via
 * rest_do_request()/rest_get_server() rather than real outbound HTTP, per this repo's
 * "prefer in-process REST over fragile live construction" lesson (see
 * jetblog-query-pipeline/TEST-REGIMEN.md). No test in this suite makes a real outbound
 * HTTP call to a third-party API — see SKILL.md/TEST-REGIMEN.md "Not yet automated" for
 * what that would require.
 */

add_action( 'agent-test/run-suite/jetengine-rest-api', function() {

	$suite = 'jetengine-rest-api';

	// rapi-1: jet-engine/v2 is the real admin-CRUD namespace (not v1) — add-post-type is
	// registered there by boot time (Jet_Engine_REST_API::register_routes() already ran
	// on rest_api_init for the outer request this suite executes inside of).
	try {
		$routes = rest_get_server()->get_routes();
		$found  = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( 0 === strpos( $route, '/jet-engine/v2/add-post-type' ) ) {
				$found = true;
				break;
			}
		}
		agent_test_assert(
			$suite, 'rapi-1',
			'SKILL.md "The three REST namespaces": Jet_Engine_REST_API registers the admin-CRUD add-post-type route under jet-engine/v2 (not v1)',
			$found,
			array( 'route_registered' => true ),
			array( 'route_registered' => $found ),
			'includes/rest-api/manager.php:15,19; includes/components/post-types/manager.php:388'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'rapi-1', 'jet-engine/v2 namespace smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// rapi-2: Meta Box field REST exposure is register_meta()+show_in_rest, NOT
	// register_rest_field() — direct instantiation of Jet_Engine_Rest_Post_Meta against a
	// throwaway field name, then confirm it landed in get_registered_meta_keys().
	try {
		$file = WP_PLUGIN_DIR . '/jet-engine/includes/components/meta-boxes/rest-api/fields/post-meta.php';
		if ( ! class_exists( 'Jet_Engine_Rest_Post_Meta' ) && file_exists( $file ) ) {
			require_once $file;
		}
		new Jet_Engine_Rest_Post_Meta( array( 'name' => 'agent_test_rapi_field', 'type' => 'text' ), 'post' );
		$meta_keys = get_registered_meta_keys( 'post', 'post' );
		$registered = isset( $meta_keys['agent_test_rapi_field'] );
		$show_in_rest = $registered ? ! empty( $meta_keys['agent_test_rapi_field']['show_in_rest'] ) : false;
		agent_test_assert(
			$suite, 'rapi-2',
			'SKILL.md "A2 Meta Box fields": Jet_Engine_Rest_Post_Meta::register_field() calls register_meta() with show_in_rest, not register_rest_field()',
			( $registered && $show_in_rest ),
			array( 'meta_key_registered' => true, 'show_in_rest_truthy' => true ),
			array( 'meta_key_registered' => $registered, 'show_in_rest' => $registered ? $meta_keys['agent_test_rapi_field']['show_in_rest'] : null ),
			'includes/components/meta-boxes/rest-api/fields/post-meta.php:216-229'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'rapi-2', 'Jet_Engine_Rest_Post_Meta register_meta() smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// rapi-3: Options Page field REST exposure is register_setting()+show_in_rest via
	// Jet_Engine_Rest_Settings (extends rapi-2's class, overrides register_field()).
	try {
		$file = WP_PLUGIN_DIR . '/jet-engine/includes/components/options-pages/rest-api/fields/site-settings.php';
		if ( ! class_exists( 'Jet_Engine_Rest_Settings' ) && file_exists( $file ) ) {
			require_once $file;
		}
		$fake_page = (object) array( 'storage_type' => 'global' );
		new Jet_Engine_Rest_Settings( array( 'name' => 'agent_test_rapi_setting', 'type' => 'text' ), 'agent_test_rapi_group', $fake_page );
		$registered_settings = isset( $GLOBALS['wp_registered_settings'] ) ? $GLOBALS['wp_registered_settings'] : array();
		$registered   = isset( $registered_settings['agent_test_rapi_setting'] );
		$show_in_rest = $registered ? ! empty( $registered_settings['agent_test_rapi_setting']['show_in_rest'] ) : false;
		agent_test_assert(
			$suite, 'rapi-3',
			'SKILL.md "A3 Options Pages": Jet_Engine_Rest_Settings::register_field() calls register_setting() with show_in_rest, exposing the field via wp/v2/settings',
			( $registered && $show_in_rest ),
			array( 'setting_registered' => true, 'show_in_rest_truthy' => true ),
			array( 'setting_registered' => $registered, 'show_in_rest' => $registered ? $registered_settings['agent_test_rapi_setting']['show_in_rest'] : null ),
			'includes/components/options-pages/rest-api/fields/site-settings.php:229-248'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'rapi-3', 'Jet_Engine_Rest_Settings register_setting() smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// rapi-4: CCT Public_Controller registers real jet-cct/{slug} routes when told to,
	// against the existing agent_test_cct fixture (id 15).
	try {
		$controller = new \Jet_Engine\Modules\Custom_Content_Types\Rest\Public_Controller();
		$controller->register_routes( array( 'get' => true, 'slug' => 'agent_test_cct' ) );
		$routes = rest_get_server()->get_routes();
		$has_list_route = isset( $routes['/jet-cct/agent_test_cct'] );
		$has_item_route = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( 0 === strpos( $route, '/jet-cct/agent_test_cct/' ) ) {
				$has_item_route = true;
				break;
			}
		}
		agent_test_assert(
			$suite, 'rapi-4',
			'SKILL.md "A4 CCTs need their own REST controller": Public_Controller::register_routes() registers /jet-cct/{slug} (list) and /jet-cct/{slug}/{_ID} (item) routes',
			( $has_list_route && $has_item_route ),
			array( 'list_route_registered' => true, 'item_route_registered' => true ),
			array( 'list_route_registered' => $has_list_route, 'item_route_registered' => $has_item_route ),
			'includes/modules/custom-content-types/inc/rest-api/public-controller.php:14-80'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'rapi-4', 'CCT Public_Controller::register_routes() smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// rapi-5: live in-process GET on /jet-cct/agent_test_cct (registered by rapi-4 in this
	// same request) returns 200 with an array body, driven via rest_do_request() rather
	// than a real HTTP round trip.
	try {
		$request  = new WP_REST_Request( 'GET', '/jet-cct/agent_test_cct' );
		$response = rest_do_request( $request );
		$status   = $response->get_status();
		$data     = $response->get_data();
		agent_test_assert(
			$suite, 'rapi-5',
			'SKILL.md "A4 CCTs...": GET /jet-cct/{slug} returns 200 with an array of items, driven in-process via rest_do_request()',
			( 200 === $status && is_array( $data ) ),
			array( 'status' => 200, 'data_is_array' => true ),
			array( 'status' => $status, 'data_is_array' => is_array( $data ), 'count' => is_array( $data ) ? count( $data ) : null ),
			'includes/modules/custom-content-types/inc/rest-api/public-controller.php:241-448 — requires rapi-4 to have registered the route in this same request'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'rapi-5', 'jet-cct GET route live in-process test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// rapi-6: Relations Public_Controller registers a /jet-rel/{rel_id} route for relation
	// 17 (existing fixture, posts::post -> cct::agent_test_cct), and a live in-process GET
	// on it returns 200 with an array body.
	try {
		if ( ! class_exists( '\Jet_Engine\Relations\Rest\Public_Controller' ) ) {
			// Gotcha (live-verified 2026-07-17): same lazy-require pattern as Data Stores'
			// Factory (see jetengine-modules/SKILL.md) — relation.php:102 only requires this
			// file inside init_public_rest_api(), itself gated by the relation's own
			// rest_get_enabled/rest_post_enabled args. Force-load via the plugin's own
			// component_path() helper (not a guessed WP_PLUGIN_DIR path, which can be wrong
			// if the plugin folder isn't literally named "jet-engine").
			require jet_engine()->relations->component_path( 'rest-api/public-controller.php' );
		}
		$controller = new \Jet_Engine\Relations\Rest\Public_Controller();
		$controller->register_routes( array( 'get' => true, 'rel_id' => 17 ) );
		$routes         = rest_get_server()->get_routes();
		$route_registered = isset( $routes['/jet-rel/17'] );

		$request  = new WP_REST_Request( 'GET', '/jet-rel/17' );
		$response = rest_do_request( $request );
		$status   = $response->get_status();
		$data     = $response->get_data();

		$pass = $route_registered && 200 === $status && is_array( $data );

		agent_test_assert(
			$suite, 'rapi-6',
			'SKILL.md "A5 Relations get their own public controller too": Public_Controller registers /jet-rel/{rel_id}, and a live GET against relation 17 returns 200 with an array body',
			$pass,
			array( 'route_registered' => true, 'status' => 200, 'data_is_array' => true ),
			array( 'route_registered' => $route_registered, 'status' => $status, 'data_is_array' => is_array( $data ) ),
			'includes/components/relations/rest-api/public-controller.php:14-59,108-182'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'rapi-6', 'jet-rel Public_Controller live in-process test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// rapi-7: Query_Builder\Rest\Query_Endpoint gives an arbitrary configured query
	// (query id 16, custom-content-type type against agent_test_cct) its own public REST
	// route, resolved live in-process, with Jet-Query-Total/Pages headers set.
	try {
		$file = WP_PLUGIN_DIR . '/jet-engine/includes/components/query-builder/rest-api/query-endpoint.php';
		if ( ! class_exists( '\Jet_Engine\Query_Builder\Rest\Query_Endpoint' ) && file_exists( $file ) ) {
			require_once $file;
		}
		// Query_Endpoint's constructor only hooks add_action( 'rest_api_init', ... ) —
		// rest_api_init already fired for the outer request this suite runs inside of, so
		// that hook will never re-fire here. Call register_route() directly instead of
		// relying on the (already-passed) action, same rationale as calling
		// Public_Controller::register_routes() directly in rapi-4/rapi-6.
		$endpoint = new \Jet_Engine\Query_Builder\Rest\Query_Endpoint( array(
			'id'            => 16,
			'api_namespace' => 'agent-test-rapi/v1',
			'api_path'      => '/q16',
			'api_access'    => 'public',
			'api_schema'    => array(),
		) );
		$endpoint->register_route();

		$request  = new WP_REST_Request( 'GET', '/agent-test-rapi/v1/q16' );
		$response = rest_do_request( $request );
		$status   = $response->get_status();
		$data     = $response->get_data();
		$headers  = $response->get_headers();
		$has_total_header = isset( $headers['Jet-Query-Total'] );

		$pass = ( 200 === $status ) && is_array( $data ) && $has_total_header;

		agent_test_assert(
			$suite, 'rapi-7',
			'SKILL.md "A6 Query Builder: give any configured query its own public REST route": Query_Endpoint resolves query 16 via Manager::get_query_by_id_for_context() and returns items + Jet-Query-Total header',
			$pass,
			array( 'status' => 200, 'data_is_array' => true, 'jet_query_total_header_present' => true ),
			array( 'status' => $status, 'data_is_array' => is_array( $data ), 'headers' => array_keys( $headers ) ),
			'includes/components/query-builder/rest-api/query-endpoint.php:31-44,169-207'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'rapi-7', 'Query_Endpoint live in-process test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// rapi-8: Rest API Listings module's Data class stores endpoints as rows in the SAME
	// shared post_types custom table, filtered by status = 'rest-api-endpoint' — not a
	// dedicated endpoints table. The module must be active for this (activated for this
	// task via tool-manage-modules).
	try {
		$module_class = '\Jet_Engine\Modules\Rest_API_Listings\Module';
		$module = class_exists( $module_class ) ? $module_class::instance() : null;
		$table  = ( $module && $module->data ) ? $module->data->table : null;
		$query_args = ( $module && $module->data ) ? $module->data->query_args : null;
		$pass = ( 'post_types' === $table ) && is_array( $query_args ) && isset( $query_args['status'] ) && 'rest-api-endpoint' === $query_args['status'];
		agent_test_assert(
			$suite, 'rapi-8',
			'SKILL.md "Endpoint storage — a real gotcha": Rest API Listings\' Data class table is post_types (shared with CPT config), filtered by status=rest-api-endpoint, not a dedicated table',
			$pass,
			array( 'table' => 'post_types', 'status_filter' => 'rest-api-endpoint' ),
			array( 'module_reachable' => ( $module !== null ), 'table' => $table, 'query_args' => $query_args ),
			'includes/modules/rest-api-listings/inc/data.php:14,21-23'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'rapi-8', 'Rest API Listings Data table/status smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// rapi-9: REST_API_Query is registered as Query Builder query type 'rest-api' through
	// the exact same Query_Factory::register_query() mechanism documented in
	// jetengine-query-builder — not a special-cased registration path.
	try {
		$types = \Jet_Engine\Query_Builder\Query_Factory::get_query_types();
		$registered = in_array( 'rest-api', $types, true );
		$manager_class = '\Jet_Engine\Modules\Rest_API_Listings\Query_Builder\Manager';
		$manager_reachable = class_exists( $manager_class ) && ( $manager_class::instance() instanceof $manager_class );
		agent_test_assert(
			$suite, 'rapi-9',
			'SKILL.md "Listing Grid integration": REST_API_Query is registered as query type "rest-api" via the shared Query_Factory::register_query() mechanism',
			( $registered && $manager_reachable ),
			array( 'rest_api_type_registered' => true, 'module_query_builder_manager_reachable' => true ),
			array( 'rest_api_type_registered' => $registered, 'all_types' => $types, 'manager_reachable' => $manager_reachable ),
			'includes/modules/rest-api-listings/inc/query-builder/manager.php:52-55,74-82'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'rapi-9', 'REST_API_Query registration smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// rapi-10: Request caching round-trips through update_items_cache()/get_cached_items()
	// for a "cache: true" endpoint WITHOUT making any real outbound HTTP call — exercises
	// the real caching code path (transients) deterministically. Endpoint URL deliberately
	// namespaced so it can never collide with a real configured endpoint's cache entry.
	try {
		$request_obj = new \Jet_Engine\Modules\Rest_API_Listings\Request();
		$fake_endpoint = array(
			'url'          => 'https://agent-test.invalid/rapi-cache-fixture',
			'cache'        => true,
			'cache_period' => 'minutes',
			'cache_value'  => 5,
		);
		$request_obj->set_endpoint( $fake_endpoint );
		$fake_items = array( (object) array( 'id' => 1, 'name' => 'AGENT TEST cached item' ) );
		$request_obj->update_items_cache( $fake_items, array() );
		$cached = $request_obj->get_cached_items( array() );

		$pass = is_array( $cached ) && count( $cached ) === 1 && isset( $cached[0]->name ) && 'AGENT TEST cached item' === $cached[0]->name;

		// cleanup: remove the transient this test created.
		delete_transient( $request_obj->get_cache_transient( array() ) );

		agent_test_assert(
			$suite, 'rapi-10',
			'SKILL.md "Fetching: Request class": update_items_cache()/get_cached_items() round-trip real cached data via a transient when the endpoint has cache=true, exercised without any real outbound HTTP call',
			$pass,
			array( 'cached_roundtrip_matches' => true ),
			array( 'cached' => $cached ),
			'includes/modules/rest-api-listings/inc/request.php:100-225 — deliberately does not hit send_request()/wp_remote_get(), see SKILL.md "not yet automated" for the real-fetch gap'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'rapi-10', 'Request cache round-trip smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// rapi-11: Bearer_Token auth type only injects its Authorization header for an
	// endpoint whose own auth_type matches it (is_current_type_endpoint()) — confirms the
	// "just another callback gated on the endpoint's own config" claim directly, without
	// needing a real request/response round trip.
	try {
		$bearer = new \Jet_Engine\Modules\Rest_API_Listings\Auth_Types\Bearer_Token();

		$matching_endpoint = array( 'authorization' => true, 'auth_type' => 'bearer-token', 'bearer_token' => 'agent-test-token' );
		$other_endpoint     = array( 'authorization' => true, 'auth_type' => 'custom-header', 'bearer_token' => 'agent-test-token' );

		// set_token() reads the endpoint off the Request instance passed in — build real
		// Request instances to exercise it faithfully.
		$req_match = new \Jet_Engine\Modules\Rest_API_Listings\Request();
		$req_match->set_endpoint( $matching_endpoint );
		$args_match = $bearer->set_token( array(), $req_match );

		$req_other = new \Jet_Engine\Modules\Rest_API_Listings\Request();
		$req_other->set_endpoint( $other_endpoint );
		$args_other = $bearer->set_token( array(), $req_other );

		$injected_for_match = isset( $args_match['headers']['Authorization'] ) && 'Bearer agent-test-token' === $args_match['headers']['Authorization'];
		$not_injected_for_other = ! isset( $args_other['headers']['Authorization'] );

		$pass = $injected_for_match && $not_injected_for_other;

		agent_test_assert(
			$suite, 'rapi-11',
			'SKILL.md "Auth types — pluggable...": Bearer_Token::set_token() injects Authorization only when the endpoint\'s own auth_type matches this auth type\'s id',
			$pass,
			array( 'injected_for_matching_endpoint' => true, 'not_injected_for_other_endpoint' => true ),
			array( 'injected_for_matching_endpoint' => $injected_for_match, 'not_injected_for_other_endpoint' => $not_injected_for_other, 'args_match' => $args_match, 'args_other' => $args_other ),
			'includes/modules/rest-api-listings/inc/auth-types/bearer-token.php:29-53; base.php:62-73'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'rapi-11', 'Bearer_Token auth-gating smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
