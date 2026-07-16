<?php
/**
 * AGENT-TEST-SUITE: jetengine-query-builder
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetengine-query-builder"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetengine-query-builder. See docs/test-harness-guide.md.
 *
 * Fixtures used (already exist on the sandbox, kept from the jetengine-mcp-tools regimen):
 * - Query Builder query id 16 ("AGENT TEST Query - CCT test items", type custom-content-type
 *   against CCT agent_test_cct, id 15).
 *
 * 2026-07-16: this suite's first version called `jet_engine()->query_builder->manager->...`,
 * which does not exist (confirmed by this suite's own first run — all 4 assertions
 * failed with "call to a member function on null"). Corrected to the real static
 * singleton `\Jet_Engine\Query_Builder\Manager::instance()` API — see SKILL.md's
 * "Correction notice" for the full story.
 */

add_action( 'agent-test/run-suite/jetengine-query-builder', function() {

	$suite = 'jetengine-query-builder';

	// qb-1: get_query_by_id() returns a working query object whose get_items() runs.
	try {
		$query = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id( 16 );
		$class = $query ? get_class( $query ) : null;
		$items = $query ? $query->get_items() : null;
		agent_test_assert(
			$suite, 'qb-1',
			'SKILL.md "Fetching a configured query and running it": Manager::instance()->get_query_by_id() returns a non-null query object whose get_items() returns an array',
			( $query !== null && is_array( $items ) ),
			array( 'non_null_query' => true, 'items_is_array' => true ),
			array( 'class' => $class, 'items_is_array' => is_array( $items ), 'items_count' => is_array( $items ) ? count( $items ) : null ),
			'manager.php:432'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'qb-1', 'get_query_by_id() smoke test', false, 'no exception', $e->getMessage(), 'THREW — see qb-1 actual for exception message' );
	}

	// qb-2: get_query_args() returns an array (structure, not just a scalar/null).
	try {
		$query = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id( 16 );
		$args  = $query ? $query->get_query_args() : null;
		agent_test_assert(
			$suite, 'qb-2',
			'SKILL.md "Fetching a configured query and running it": get_query_args() returns the assembled query args as an array',
			is_array( $args ),
			array( 'is_array' => true ),
			array( 'is_array' => is_array( $args ), 'keys' => is_array( $args ) ? array_keys( $args ) : null ),
			'queries/base.php:553'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'qb-2', 'get_query_args() smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// qb-3: Query_Factory::register_query() is a static method, callable directly on the class, no fatal.
	try {
		\Jet_Engine\Query_Builder\Query_Factory::register_query( 'agent_test_query_type', 'Agent_Test_Query_Type_Does_Not_Need_To_Exist_For_This_Check' );
		$types = \Jet_Engine\Query_Builder\Query_Factory::get_query_types();
		$registered = in_array( 'agent_test_query_type', $types, true );
		agent_test_assert(
			$suite, 'qb-3',
			'SKILL.md "Registering a custom query type": Query_Factory::register_query() is a static method that writes into the static type registry, confirmable via get_query_types()',
			$registered,
			array( 'agent_test_query_type_in_get_query_types' => true ),
			array( 'agent_test_query_type_in_get_query_types' => $registered, 'all_types' => $types ),
			'query-factory.php:139,158 — this confirms registration lands in the static map; it does not confirm the type is usable end-to-end (that needs a real Base_Query subclass, see TEST-REGIMEN.md Test 4)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'qb-3', 'Query_Factory::register_query() static-call smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// qb-4: get_query_by_id() with a bogus id degrades gracefully (no fatal), doesn't assert a specific return shape.
	try {
		$bogus = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id( 999999999 );
		agent_test_assert(
			$suite, 'qb-4',
			'get_query_by_id() with a non-existent id does not throw/fatal (negative-path smoke test, not an explicit SKILL.md claim yet)',
			true, // reaching this line at all is the pass condition
			array( 'no_fatal' => true ),
			array( 'no_fatal' => true, 'returned' => is_object( $bogus ) ? get_class( $bogus ) : var_export( $bogus, true ) ),
			'manager.php:432 — worth promoting to a documented SKILL.md claim once the returned value (null vs WP_Error vs empty object) is confirmed'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'qb-4', 'get_query_by_id() bogus-id smoke test', false, 'no exception', $e->getMessage(), 'THREW — if this is the normal behavior for a bad id, SKILL.md should document that it throws rather than returning null/false' );
	}

	// qb-5: query/items fires on every get_items() call (2 args: items, query) — driven
	// live against the real query-16 fixture. NOTE: after-query-setup is NOT tested live
	// here (see qb-6) — 2026-07-16 finding: Manager::get_query_by_id() returns an
	// ALREADY-CONSTRUCTED object from its ->queries[] cache (manager.php:442), and
	// after_query_setup()/its do_action only ever run once, during that object's initial
	// construction on `init` (base.php:392-397) — NOT on every get_items() call. A filter
	// registered mid-REST-request is always too late to see it, the same class of gotcha
	// as CCT's raw-fields/admin-columns and Relations' raw-relations (registration-time-
	// only hooks). The first version of this test wrongly assumed after-query-setup
	// would re-fire per get_items() call and failed (after_setup_seen: null) — corrected
	// per this note and qb-6 below.
	try {
		$items_filter_seen = null;
		add_filter( 'jet-engine/query-builder/query/items', function( $items, $q ) use ( &$items_filter_seen ) {
			if ( 16 === (int) $q->id ) {
				$items_filter_seen = array( 'items_is_array' => is_array( $items ), 'query_id_matches' => true );
			}
			return $items;
		}, 10, 2 );

		$query = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id( 16 );
		$items = $query ? $query->get_items() : null;

		remove_all_filters( 'jet-engine/query-builder/query/items' );

		$pass = is_array( $items_filter_seen ) && $items_filter_seen['items_is_array'];

		agent_test_assert(
			$suite, 'qb-5',
			'SKILL.md "Rewriting a query\'s assembled args or its returned items": query/items fires on get_items() with (items, query), items genuinely an array — driven live against query id 16',
			$pass,
			array( 'items_filter_fired_with_array' => true ),
			array( 'items_filter_seen' => $items_filter_seen, 'items_count' => is_array( $items ) ? count( $items ) : null ),
			'includes/components/query-builder/queries/base.php:577,591 (query/items) — driven live against existing query-16 fixture'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'qb-5', 'query/items live hook test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// qb-6: after-query-setup is a registration-time-only hook (see qb-5's note) — can't
	// be usefully re-triggered mid-request, so this is a source-presence check only,
	// plus a live confirmation that the query-16 fixture's final_query (populated by the
	// time after-query-setup fired, long before this suite ran) still has the expected
	// _query_type key — proving the hook's precondition (final_query populated first)
	// without needing to catch the action fire in real time.
	try {
		$file = WP_PLUGIN_DIR . '/jet-engine/includes/components/query-builder/queries/base.php';
		$contents = file_exists( $file ) ? file_get_contents( $file ) : '';
		$has_hook = false !== strpos( $contents, "do_action( 'jet-engine/query-builder/query/after-query-setup', \$this )" );

		$query = \Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id( 16 );
		$final_query_populated = $query && ! empty( $query->final_query ) && isset( $query->final_query['_query_type'] );

		$pass = ( '' !== $contents ) && $has_hook && $final_query_populated;

		agent_test_assert(
			$suite, 'qb-6',
			'SKILL.md "Rewriting a query\'s assembled args...": after-query-setup hook present in live source, and query id 16\'s final_query (populated at construction time, before the hook fires) already contains _query_type by the time this suite runs — confirms the hook\'s precondition even though it can\'t be caught firing mid-request',
			$pass,
			array( 'hook_present' => true, 'final_query_populated' => true ),
			array( 'hook_present' => $has_hook, 'final_query_populated' => $final_query_populated, 'final_query_keys' => $query ? array_keys( $query->final_query ) : null ),
			'includes/components/query-builder/queries/base.php:397 — source-presence + precondition check, not a live fire-catch (see qb-5\'s note on why)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'qb-6', 'after-query-setup presence/precondition test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
