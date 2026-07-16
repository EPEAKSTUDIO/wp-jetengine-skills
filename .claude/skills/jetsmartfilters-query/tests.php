<?php
/**
 * AGENT-TEST-SUITE: jetsmartfilters-query
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetsmartfilters-query"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetsmartfilters-query. See docs/test-harness-guide.md.
 *
 * No filter/listing fixtures exist yet on the sandbox (JetSmartFilters was only just
 * installed) — these are class/accessor-reachability smoke tests, not full pipeline
 * tests. Extend with real filter/listing fixtures once one exists (see TEST-REGIMEN.md).
 */

add_action( 'agent-test/run-suite/jetsmartfilters-query', function() {

	$suite = 'jetsmartfilters-query';

	// jsf-1: jet_smart_filters()->query is reachable and its documented methods exist.
	try {
		$query_manager = function_exists( 'jet_smart_filters' ) ? jet_smart_filters()->query : null;
		$class = $query_manager ? get_class( $query_manager ) : null;
		$has_methods = $query_manager
			&& method_exists( $query_manager, 'get_query_args' )
			&& method_exists( $query_manager, 'get_query_from_request' )
			&& method_exists( $query_manager, 'is_ajax_filter' );
		agent_test_assert(
			$suite, 'jsf-1',
			'SKILL.md "The pipeline...": jet_smart_filters()->query is a Jet_Smart_Filters_Query_Manager exposing get_query_args()/get_query_from_request()/is_ajax_filter()',
			(bool) $has_methods,
			array( 'class' => 'Jet_Smart_Filters_Query_Manager', 'methods_exist' => true ),
			array( 'class' => $class, 'methods_exist' => $has_methods ),
			'includes/query.php:16,157,170,572'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsf-1', 'jet_smart_filters()->query reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jsf-2: jet_smart_filters()->indexer->data is null when the indexer is disabled (the default/expected state on
	// this site) — 2026-07-16: the first version of this test wrongly required $indexer_data to be truthy and
	// failed here; corrected per SKILL.md's "Confirmed live" note. The real claim is "null without fataling", not
	// "always an object".
	try {
		$indexer = function_exists( 'jet_smart_filters' ) ? jet_smart_filters()->indexer : null;
		$indexer_data = $indexer ? $indexer->data : null;
		$is_enabled = $indexer ? $indexer->is_indexer_enabled : null;
		$class = $indexer_data ? get_class( $indexer_data ) : null;
		$result = null;
		if ( $indexer_data && method_exists( $indexer_data, 'get_indexed_data' ) ) {
			$result = $indexer_data->get_indexed_data( 'agent_test_provider_key_does_not_exist', array() );
		}
		// Pass condition: no fatal reaching this point, AND data is null exactly when disabled (both consistent).
		$pass = ( $is_enabled === false && $indexer_data === null ) || ( $is_enabled === true && $indexer_data !== null );
		agent_test_assert(
			$suite, 'jsf-2',
			'SKILL.md "The indexer...": jet_smart_filters()->indexer->data is null exactly when is_indexer_enabled is false (no fatal either way) — data is NOT unconditionally an Indexer_Data instance',
			$pass,
			array( 'data_null_iff_disabled' => true ),
			array( 'is_indexer_enabled' => $is_enabled, 'data_class' => $class, 'get_indexed_data_result_if_available' => $result ),
			'includes/indexer/manager.php:22-38 (data property + is_indexer_enabled), includes/indexer/data.php:15,121'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsf-2', 'indexer enabled/data consistency smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jsf-3: the standalone Listing/Storage engine is reachable via the Listing\Controller singleton's ->storage property.
	//
	// 2026-07-16: the first version of this test did `new \Jet_Smart_Filters\Listing\Storage\Controller()`
	// directly, which CRASHED THE ENTIRE SITE (uncatchable "Cannot redeclare class" fatal,
	// not even caught by this try/catch — confirmed via an isolated diagnostic snippet).
	// Root cause: Storage\Controller's constructor unconditionally `require`s
	// db-storage.php, and \Jet_Smart_Filters\Listing\Controller::instance() already
	// instantiates one into its own ->storage property during normal WP `init` — so a
	// second instantiation redeclares an already-declared class. Fixed to use the real
	// singleton accessor instead. See SKILL.md's "Live-verified landmine" note.
	try {
		$listing_controller = class_exists( '\\Jet_Smart_Filters\\Listing\\Controller' )
			? \Jet_Smart_Filters\Listing\Controller::instance()
			: null;
		$storage  = $listing_controller ? $listing_controller->storage : null;
		$listings = $storage ? $storage->get_listings() : null;
		global $wpdb;
		$table_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'jsf_listings' ) );
		agent_test_assert(
			$suite, 'jsf-3',
			'SKILL.md "JetSmartFilters has its own Listing/Query-Builder engine": Listing\\Controller::instance()->storage is the existing singleton storage instance, get_listings() returns an array, and it created its own {prefix}jsf_listings DB table (not a JetEngine table)',
			( is_array( $listings ) && $table_exists ),
			array( 'listings_is_array' => true, 'table_exists' => true ),
			array( 'listings_is_array' => is_array( $listings ), 'listings_count' => is_array( $listings ) ? count( $listings ) : null, 'table_exists' => $table_exists ),
			'listing/controller.php:59,70, storage/controller.php:12,17-48, storage/db-storage.php:29 — DO NOT `new Storage\\Controller()` directly, see SKILL.md'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsf-3', 'Listing\\Controller::instance()->storage smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jsf-4: Provider Helpers live on a PROVIDER INSTANCE (e.g. the jet-engine provider), not on the providers manager.
	// 2026-07-16: the first version of this test asserted jet_smart_filters()->providers->helpers directly and
	// failed (null, no such property on the manager) — corrected per SKILL.md's "Correction" note.
	try {
		$providers_manager = function_exists( 'jet_smart_filters' ) ? jet_smart_filters()->providers : null;
		$manager_has_no_helpers_prop = $providers_manager && ! property_exists( $providers_manager, 'helpers' );
		$provider = $providers_manager ? $providers_manager->get_providers( 'jet-engine' ) : null;
		$helpers  = ( $provider && $provider !== false ) ? $provider->helpers : null;
		$class    = $helpers ? get_class( $helpers ) : null;
		agent_test_assert(
			$suite, 'jsf-4',
			'SKILL.md "Provider Helpers...": jet_smart_filters()->providers has no helpers property; get_providers("jet-engine")->helpers (lazy __get on the provider base class) is a Jet_Smart_Filters_Provider_Helpers_Manager instance',
			( $manager_has_no_helpers_prop && $class === 'Jet_Smart_Filters_Provider_Helpers_Manager' ),
			array( 'manager_has_no_helpers_prop' => true, 'provider_helpers_class' => 'Jet_Smart_Filters_Provider_Helpers_Manager' ),
			array( 'manager_has_no_helpers_prop' => $manager_has_no_helpers_prop, 'provider_found' => (bool) $provider, 'provider_helpers_class' => $class ),
			'includes/providers/manager.php:140, includes/providers/base.php:103-129, includes/providers/jet-engine.php:127-129, includes/providers/helpers/manager.php:15'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsf-4', 'per-provider Helpers reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jsf-5: Service_Filters direct-CRUD escape hatch is reachable and callable.
	try {
		$filters_service = function_exists( 'jet_smart_filters' ) ? jet_smart_filters()->services->filters : null;
		$class = $filters_service ? get_class( $filters_service ) : null;
		$result = null;
		$callable_ok = false;
		if ( $filters_service && method_exists( $filters_service, 'get' ) ) {
			$result = $filters_service->get( array() );
			$callable_ok = true;
		}
		agent_test_assert(
			$suite, 'jsf-5',
			'SKILL.md "The admin REST namespace...": jet_smart_filters()->services->filters (Jet_Smart_Filters_Service_Filters, no namespace despite the class name) is directly callable from PHP without going through REST',
			$callable_ok,
			array( 'class' => 'Jet_Smart_Filters_Service_Filters', 'callable' => true ),
			array( 'class' => $class, 'callable' => $callable_ok, 'result_type' => is_wp_error( $result ) ? 'WP_Error' : gettype( $result ) ),
			'includes/services/services.php:19, includes/services/filters.php:6,25'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsf-5', 'Service_Filters reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
