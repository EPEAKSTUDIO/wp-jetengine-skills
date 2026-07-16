<?php
/**
 * AGENT-TEST-SUITE: jetengine-mcp-tools
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetengine-mcp-tools"),
 * alongside the always-active AGENT-TEST-CORE harness. Run via
 * GET /agent-test/v1/suite/jetengine-mcp-tools. See docs/test-harness-guide.md.
 *
 * Deliberately does NOT call any tool-add-* live (that would create a new entity on
 * every run) — instead re-inspects the Registry/Feature objects directly (read-only,
 * no side effects) and re-checks the already-created fixtures from the original live
 * pass (CCT id 15 / agent_test_cct, query id 16), so this suite is safe to re-run
 * indefinitely without accumulating garbage.
 */

add_action( 'agent-test/run-suite/jetengine-mcp-tools', function() {

	$suite = 'jetengine-mcp-tools';

	// mcp-1: naming convention is real — Feature::get_name() is "{type}-{id}", confirmed by
	// finding the actual registered add-cct feature and checking its computed name.
	try {
		$registry = class_exists( '\\Jet_Engine\\MCP_Tools\\Registry' ) ? \Jet_Engine\MCP_Tools\Registry::instance() : null;
		$feature  = $registry ? $registry->get_feature( 'tool-add-cct' ) : null;
		$found_via_get_feature = ( $feature !== null );
		$name_matches = $found_via_get_feature ? ( $feature->get_name() === 'tool-add-cct' && $feature->get_type() === 'tool' && $feature->get_id() === 'add-cct' ) : false;
		agent_test_assert(
			$suite, 'mcp-1',
			'SKILL.md "Architecture": the externally visible tool name is always "{type}-{id}" — Registry::get_feature(\'tool-add-cct\') resolves and its get_name()/get_type()/get_id() match',
			( $found_via_get_feature && $name_matches ),
			array( 'get_feature_resolves' => true, 'name_is_type_dash_id' => true ),
			array( 'registry_reachable' => $registry !== null, 'feature_found' => $found_via_get_feature, 'get_name' => $found_via_get_feature ? $feature->get_name() : null, 'get_type' => $found_via_get_feature ? $feature->get_type() : null, 'get_id' => $found_via_get_feature ? $feature->get_id() : null ),
			'includes/core/mcp-tools/{registry,feature}.php — Feature::get_name() = get_type().\'-\'.get_id() (feature.php:103-105)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'mcp-1', 'Registry/Feature naming smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// mcp-2: is_features_api_enabled() reads the RAW option directly (not via
	// jet_engine()->misc_settings), defaulting true via ensure_settings().
	try {
		$registry = class_exists( '\\Jet_Engine\\MCP_Tools\\Registry' ) ? \Jet_Engine\MCP_Tools\Registry::instance() : null;
		$raw_option = get_option( 'jet-engine-misc-settings', array() );
		$enabled = $registry ? $registry->is_features_api_enabled() : null;
		// Cross-check: re-derive the same result manually from the raw option the way
		// Registry does, to confirm it isn't secretly reading jet_engine()->misc_settings.
		$ensured = $registry ? $registry->ensure_settings( $raw_option ) : array();
		$expected_enabled = ! empty( $ensured['enable_features_api'] ) ? filter_var( $ensured['enable_features_api'], FILTER_VALIDATE_BOOLEAN ) : false;
		agent_test_assert(
			$suite, 'mcp-2',
			'SKILL.md "Architecture": is_features_api_enabled() reads option jet-engine-misc-settings[enable_features_api] directly, defaulting true',
			( $enabled === $expected_enabled ),
			array( 'matches_raw_option_derivation' => true ),
			array( 'is_features_api_enabled' => $enabled, 'derived_from_raw_option' => $expected_enabled, 'raw_option_present' => ! empty( $raw_option ) ),
			'includes/core/mcp-tools/registry.php:52-67,122-130'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'mcp-2', 'is_features_api_enabled() smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// mcp-3: re-checks the already-created agent_test_cct table (fixture from the original
	// live tool-add-cct call) still has the exact column shape documented in SKILL.md.
	try {
		global $wpdb;
		$table = $wpdb->prefix . 'jet_cct_agent_test_cct';
		$columns = $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A );
		$by_field = array();
		foreach ( (array) $columns as $col ) { $by_field[ $col['Field'] ] = $col['Type']; }
		$pass = isset( $by_field['_ID'] ) && strpos( $by_field['_ID'], 'bigint' ) === 0
			&& isset( $by_field['photo'] ) && strpos( $by_field['photo'], 'bigint' ) === 0
			&& isset( $by_field['title'] ) && $by_field['title'] === 'text'
			&& isset( $by_field['status'] ) && $by_field['status'] === 'text'
			&& isset( $by_field['cct_status'], $by_field['cct_author_id'], $by_field['cct_created'], $by_field['cct_modified'] );
		agent_test_assert(
			$suite, 'mcp-3',
			'SKILL.md "tool-add-cct — verified live": media field -> bigint(20) column, text/select fields -> text column, built-in bookkeeping columns always present',
			$pass,
			array( '_ID' => 'bigint(20)', 'photo' => 'bigint(20)', 'title' => 'text', 'status' => 'text', 'bookkeeping_columns_present' => true ),
			$by_field,
			'verified live 2026-07-16 via tool-add-cct + DESCRIBE (Code Snippets probe id 19, kept inactive)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'mcp-3', 'tool-add-cct column-shape re-check', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// mcp-4: re-checks query id 16's stored args still match the documented query_args ->
	// internal-shape conversion (posts_per_page -> number, meta_query row -> args row, etc).
	try {
		$q = class_exists( '\\Jet_Engine\\Query_Builder\\Manager' ) ? \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id( 16 ) : null;
		$type = $q ? $q->get_query_type() : null;
		$args = $q ? $q->query : null;
		$pass = ( $type === 'custom-content-type' )
			&& is_array( $args )
			&& isset( $args['number'] ) && '10' === (string) $args['number']
			&& isset( $args['order'][0]['orderby'], $args['order'][0]['order'] )
			&& isset( $args['args'][0]['field'], $args['args'][0]['operator'], $args['args'][0]['value'] );
		agent_test_assert(
			$suite, 'mcp-4',
			'SKILL.md "tool-add-query — verified live": query_args is converted to a different internal shape (posts_per_page->number, meta_query row->{field,operator,value}, orderby/order->order array), not stored verbatim',
			$pass,
			array( 'query_type' => 'custom-content-type', 'number' => '10', 'order_row_present' => true, 'args_row_shaped' => true ),
			array( 'query_type' => $type, 'stored_args' => $args ),
			'verified live 2026-07-16 via tool-add-query (query id 16) + Manager::get_query_by_id()->setup_query() dump (Code Snippets probe id 19, kept inactive)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'mcp-4', 'tool-add-query stored-shape re-check', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
