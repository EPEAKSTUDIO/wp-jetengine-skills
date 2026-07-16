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

	// jsf-6: jet-smart-filters/query/final-query is a real, honored filter — a callback
	// that adds a marker key is present in get_query_from_request()'s return value, called
	// directly with a fake (empty) request array so no live filter/listing fixture is needed.
	try {
		$query_manager = function_exists( 'jet_smart_filters' ) ? jet_smart_filters()->query : null;
		if ( ! $query_manager || ! method_exists( $query_manager, 'get_query_from_request' ) ) {
			throw new \Exception( 'jet_smart_filters()->query not available' );
		}
		add_filter( 'jet-smart-filters/query/final-query', function( $query_args ) {
			$query_args['agent_test_final_query_marker'] = true;
			return $query_args;
		} );
		$result = $query_manager->get_query_from_request( array() );
		$pass   = is_array( $result ) && ! empty( $result['agent_test_final_query_marker'] );
		agent_test_assert(
			$suite, 'jsf-6',
			'SKILL.md "jet-smart-filters/query/final-query — worked examples": the filter is applied to the fully-assembled $query_args inside get_query_from_request() before it\'s returned/exposed via get_query_args() — a registered callback\'s mutation is present in the result',
			$pass,
			array( 'marker_present' => true ),
			array( 'result_keys' => is_array( $result ) ? array_keys( $result ) : null, 'marker_present' => $pass ),
			'includes/query.php:780 (apply_filters(\'jet-smart-filters/query/final-query\', $this->_query) right before return)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsf-6', 'final-query filter honored smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jsf-7: the cross-plugin jet-engine/query-builder/filters/before-after-props hook exists
	// in the currently-installed JetEngine source. Not invoked live here — it only fires
	// mid-way through a real JetSmartFilters AJAX request driving a Query Builder query
	// (Filters::set_filtered_props(), gated by is_filters_request()), which needs a live
	// filter+listing fixture this sandbox doesn't have yet (see TEST-REGIMEN.md). Source-grep
	// guards against the hook being renamed/removed by a plugin update.
	try {
		$file = WP_PLUGIN_DIR . '/jet-engine/includes/components/query-builder/listings/filters.php';
		$contents = file_exists( $file ) ? file_get_contents( $file ) : '';
		$has_hook = false !== strpos( $contents, "do_action( 'jet-engine/query-builder/filters/before-after-props', \$query )" );
		agent_test_assert(
			$suite, 'jsf-7',
			'SKILL.md "Undocumented cross-plugin hook": jet-engine/query-builder/filters/before-after-props (1 arg, the Base_Query instance) is fired from Jet_Engine\\Query_Builder\\Listings\\Filters::set_filtered_props() in the currently-installed JetEngine source',
			( '' !== $contents && $has_hook ),
			array( 'file_readable' => true, 'hook_present' => true ),
			array( 'file_readable' => ( '' !== $contents ), 'hook_present' => $has_hook ),
			'jet-engine/includes/components/query-builder/listings/filters.php:115 — live source-grep since triggering this hook for real needs a live JSF AJAX request against a Query Builder query, not available on this sandbox yet'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsf-7', 'before-after-props hook presence smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jsf-8: jet-smart-filters/query/meta-query-row fires per-clause, more granular than
	// final-query (jsf-6 tests the whole-array hook; this tests the per-row one) — driven
	// live by feeding get_query_from_request() a crafted meta_query-shaped request key,
	// same safe technique as jsf-6 (no live filter/listing fixture needed).
	try {
		$query_manager = function_exists( 'jet_smart_filters' ) ? jet_smart_filters()->query : null;
		if ( ! $query_manager || ! method_exists( $query_manager, 'get_query_from_request' ) ) {
			throw new \Exception( 'jet_smart_filters()->query not available' );
		}
		$seen_row = null;
		add_filter( 'jet-smart-filters/query/meta-query-row', function( $row, $q, $additional_options ) use ( &$seen_row ) {
			$seen_row = $row;
			return $row;
		}, 10, 3 );
		$query_manager->get_query_from_request( array( '_meta_query_agent_test_key' => 'agent_test_value' ) );
		remove_all_filters( 'jet-smart-filters/query/meta-query-row' );
		$pass = is_array( $seen_row ) && isset( $seen_row['key'] ) && 'agent_test_key' === $seen_row['key'];
		agent_test_assert(
			$suite, 'jsf-8',
			'SKILL.md "More render-time and admin-editor filters": jet-smart-filters/query/meta-query-row fires per meta_query clause (3 args: current_row, query_manager, additional_options) from add_meta_query_var(), driven live via a crafted _meta_query_{key} request key',
			$pass,
			array( 'row_seen_with_right_key' => true ),
			array( 'seen_row' => $seen_row ),
			'includes/query.php:1175 (meta-query-row), :997-... (add_meta_query_var()), :620-631 (get_query_from_request()\'s meta_query key parsing)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsf-8', 'meta-query-row live filter test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jsf-9: filter-instance/args, filters/filter-options, range/source-callbacks, and
	// post-type/meta-fields-settings are source-presence checks only — the first two
	// only fire from inside a real Filter_Instance/filter-type prepare_args() call
	// (constructing one directly risks the same class of fatal jsf-3's landmine
	// exposed for Storage\Controller, since filter-type classes assume a fully-configured
	// filter post exists), and the latter two are admin-editor-only / compatibility-layer
	// hooks with no safe live trigger from a REST request context.
	try {
		$checks = array(
			array( 'includes/filters/instance.php', "apply_filters( 'jet-smart-filters/filter-instance/args'" ),
			array( 'includes/filters/checkboxes.php', "apply_filters( 'jet-smart-filters/filters/filter-options'" ),
			array( 'admin/includes/filter-settings-list.php', "apply_filters( 'jet-smart-filters/range/source-callbacks'" ),
			array( 'includes/compatibility/jet-engine/manager.php', "'jet-smart-filters/post-type/meta-fields-settings'" ),
		);
		$results = array();
		$all_pass = true;
		foreach ( $checks as $c ) {
			list( $rel_path, $needle ) = $c;
			$file = WP_PLUGIN_DIR . '/jet-smart-filters/' . $rel_path;
			$contents = file_exists( $file ) ? file_get_contents( $file ) : '';
			$found = ( '' !== $contents ) && ( false !== strpos( $contents, $needle ) );
			$results[ $rel_path ] = $found;
			$all_pass = $all_pass && $found;
		}
		// Also confirm the JS event-bus channel name strings are present in the shipped bundle.
		$js_file = WP_PLUGIN_DIR . '/jet-smart-filters/assets/js/public.js';
		$js_contents = file_exists( $js_file ) ? file_get_contents( $js_file ) : '';
		$js_channels = array( 'ajaxFilters/updated', 'ajaxFilters/start-loading', 'ajaxFilters/end-loading', 'pagination/change', 'fiter/change', 'fiter/apply' );
		$js_found = array();
		foreach ( $js_channels as $ch ) {
			$js_found[ $ch ] = ( '' !== $js_contents ) && ( false !== strpos( $js_contents, $ch ) );
		}
		$all_pass = $all_pass && ! in_array( false, $js_found, true );

		agent_test_assert(
			$suite, 'jsf-9',
			'SKILL.md "More render-time..." / "The front-end JS event bus": filter-instance/args, filters/filter-options, range/source-callbacks, post-type/meta-fields-settings all present in live PHP source; ajaxFilters/updated, start-loading, end-loading, pagination/change, fiter/change, fiter/apply all present in the shipped public.js bundle',
			$all_pass,
			array( 'all_php_hooks_present' => true, 'all_js_channels_present' => true ),
			array( 'php' => $results, 'js' => $js_found ),
			'includes/filters/instance.php:41, includes/filters/checkboxes.php:198, admin/includes/filter-settings-list.php:241, includes/compatibility/jet-engine/manager.php:27, assets/js/public.js (built bundle, channel names found as literal substrings) — source-presence only, not live-triggered'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsf-9', 'filter-instance/filter-options/range-callbacks/meta-fields-settings + JS channel presence test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
