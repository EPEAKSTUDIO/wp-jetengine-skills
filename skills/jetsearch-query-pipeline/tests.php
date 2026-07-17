<?php
/**
 * AGENT-TEST-SUITE: jetsearch-query-pipeline
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetsearch-query-pipeline"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetsearch-query-pipeline. See docs/test-harness-guide.md.
 *
 * Deliberately does NOT drive get_search_results() (the wp_ajax_jet_ajax_search callback)
 * or Jet_Search_Rest_Search_Route::callback() (the REST search-posts callback) end to end —
 * both terminate in wp_send_json_success()/wp_send_json_error() -> wp_die(), the same
 * "uncatchable in an in-process harness" shape documented elsewhere in this repo (see
 * jetwoobuilder-templates/TEST-REGIMEN.md). Instead this suite calls the underlying
 * get_search_data() (safe on its normal path — only wp_die()s on the is_wp_error() branch,
 * which these tests never trigger), prepare_terms_data(), and the Search Sources Manager
 * directly, and checks the REST route's registration/permission_callback() without invoking
 * its terminating callback().
 */

add_action( 'agent-test/run-suite/jetsearch-query-pipeline', function() {

	$suite = 'jetsearch-query-pipeline';

	// jsp-1: the AJAX action name / nonce action string is the literal 'jet_ajax_search',
	// reused for both wp_ajax_{action}/wp_ajax_nopriv_{action} hook names and the nonce
	// action string get_search_results() checks against.
	try {
		$action = jet_search_ajax_handlers()->get_ajax_action();
		agent_test_assert(
			$suite, 'jsp-1',
			'SKILL.md "The AJAX path": Jet_Search_Ajax_Handlers::$action (via get_ajax_action()) is the literal string \'jet_ajax_search\', used as both the AJAX action name and the nonce action string',
			( 'jet_ajax_search' === $action ),
			array( 'action' => 'jet_ajax_search' ),
			array( 'action' => $action ),
			'includes/ajax-handlers.php:35,497-498'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsp-1', 'get_ajax_action() smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jsp-2: the nonce scheme is a plain wp_verify_nonce( $nonce, 'jet_ajax_search' ) check —
	// a nonce created for that exact action string verifies true; a nonce created for a
	// different action string verifies false, confirming get_search_results()'s gate
	// (wp_verify_nonce( $_GET['nonce'], $this->action ), ajax-handlers.php:1571) would
	// accept/reject correctly without needing to drive the wp_die()-terminated callback itself.
	try {
		$action = jet_search_ajax_handlers()->get_ajax_action();
		$good_nonce = wp_create_nonce( $action );
		$bad_nonce  = wp_create_nonce( 'agent-test-bogus-action' );

		$good_verifies = (bool) wp_verify_nonce( $good_nonce, $action );
		$bad_verifies  = (bool) wp_verify_nonce( $bad_nonce, $action );

		$pass = $good_verifies && ! $bad_verifies;

		agent_test_assert(
			$suite, 'jsp-2',
			'SKILL.md "get_search_results() — nonce gate": a nonce created for the jet_ajax_search action verifies against that same action string; a nonce created for a different action does not',
			$pass,
			array( 'good_nonce_verifies' => true, 'bad_nonce_verifies' => false ),
			array( 'good_nonce_verifies' => $good_verifies, 'bad_nonce_verifies' => $bad_verifies ),
			'includes/ajax-handlers.php:1571'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsp-2', 'nonce round-trip smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jsp-3: get_search_data() returns false when $_GET['data'] is empty — the same "Empty
	// Search Data" early-exit condition get_search_results() checks for after calling it.
	try {
		$saved_get = $_GET;
		unset( $_GET['data'] );

		$result = jet_search_ajax_handlers()->get_search_data();

		$_GET = $saved_get;

		agent_test_assert(
			$suite, 'jsp-3',
			'SKILL.md "get_search_data() — $_GET[\'data\'] -> WP_Query args": returns false when $_GET[\'data\'] is empty',
			( false === $result ),
			array( 'result' => false ),
			array( 'result' => $result ),
			'includes/ajax-handlers.php:1598-1601'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsp-3', 'get_search_data() empty-data gate smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jsp-4: get_search_data() with a real $_GET['data'] payload builds and runs a real
	// WP_Query and returns a response array shaped with posts/post_count/columns keys — the
	// core "$_GET['data'] becomes WP_Query args, produces real results" claim.
	try {
		$saved_get = $_GET;
		$_GET['data'] = array(
			'value'         => 'a',
			'search_source' => 'post',
		);

		$response = jet_search_ajax_handlers()->get_search_data();

		$_GET = $saved_get;

		$pass = is_array( $response )
			&& array_key_exists( 'posts', $response )
			&& array_key_exists( 'post_count', $response )
			&& array_key_exists( 'columns', $response )
			&& false === $response['error'];

		agent_test_assert(
			$suite, 'jsp-4',
			'SKILL.md "get_search_data() — $_GET[\'data\'] -> WP_Query args": a real data payload (value + search_source) builds a WP_Query and returns a response array with posts/post_count/columns, error=false',
			$pass,
			array( 'is_array' => true, 'has_posts_key' => true, 'has_post_count_key' => true, 'has_columns_key' => true, 'error' => false ),
			array( 'is_array' => is_array( $response ), 'keys' => is_array( $response ) ? array_keys( $response ) : null, 'error' => is_array( $response ) ? ( $response['error'] ?? null ) : null ),
			'includes/ajax-handlers.php:1598-1856'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsp-4', 'get_search_data() real-payload smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jsp-5: search_taxonomy + category__in map into $this->search_query['tax_query'] as an
	// IN clause against the given taxonomy/term ids (mechanism A from SKILL.md's "Taxonomy
	// scoping" section) — fetches a real existing category term rather than assuming a fixed id.
	try {
		$terms = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'number' => 1 ) );
		$term  = ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0] : null;

		if ( ! $term ) {
			agent_test_assert(
				$suite, 'jsp-5',
				'SKILL.md "Taxonomy scoping — mechanism A": category__in + search_taxonomy build an IN tax_query clause',
				false,
				array( 'tax_query_contains_clause' => true ),
				array( 'skipped' => 'no category terms exist on this site to test against' ),
				'includes/ajax-handlers.php:998-1010'
			);
		} else {
			$saved_get = $_GET;
			$_GET['data'] = array(
				'value'           => 'a',
				'search_source'   => 'post',
				'category__in'    => array( $term->term_id ),
				'search_taxonomy' => 'category',
			);

			jet_search_ajax_handlers()->get_search_data();

			$tax_query = jet_search_ajax_handlers()->search_query['tax_query'] ?? array();

			$_GET = $saved_get;

			$found_clause = false;
			foreach ( $tax_query as $clause ) {
				if ( is_array( $clause ) && isset( $clause['taxonomy'], $clause['operator'], $clause['terms'] )
					&& 'category' === $clause['taxonomy']
					&& 'IN' === $clause['operator']
					&& in_array( $term->term_id, (array) $clause['terms'], true )
				) {
					$found_clause = true;
					break;
				}
			}

			agent_test_assert(
				$suite, 'jsp-5',
				'SKILL.md "Taxonomy scoping — mechanism A": category__in + search_taxonomy build an IN tax_query clause against the real taxonomy/term id',
				$found_clause,
				array( 'tax_query_contains_clause' => true ),
				array( 'tax_query_contains_clause' => $found_clause, 'tax_query' => $tax_query, 'term_id_used' => $term->term_id ),
				'includes/ajax-handlers.php:998-1010'
			);
		}
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsp-5', 'category__in tax_query smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jsp-6: prepare_terms_data() groups arbitrary term ids by their REAL taxonomy (via
	// get_term()->taxonomy), the mechanism include_terms_ids/exclude_terms_ids both build on.
	try {
		$terms = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'number' => 1 ) );
		$term  = ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0] : null;

		if ( ! $term ) {
			agent_test_assert(
				$suite, 'jsp-6',
				'SKILL.md "Taxonomy scoping — mechanism A": prepare_terms_data() groups term ids by their real taxonomy',
				false,
				array( 'grouped_by_taxonomy' => true ),
				array( 'skipped' => 'no category terms exist on this site to test against' ),
				'includes/ajax-handlers.php:1396-1411'
			);
		} else {
			$grouped = jet_search_ajax_handlers()->prepare_terms_data( array( $term->term_id ) );
			$pass    = isset( $grouped['category'] ) && in_array( $term->term_id, (array) $grouped['category'], true );

			agent_test_assert(
				$suite, 'jsp-6',
				'SKILL.md "Taxonomy scoping — mechanism A": prepare_terms_data() groups term ids by their real taxonomy (get_term()->taxonomy), keyed by taxonomy slug',
				$pass,
				array( 'grouped_by_taxonomy' => true ),
				array( 'grouped' => $grouped ),
				'includes/ajax-handlers.php:1396-1411'
			);
		}
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsp-6', 'prepare_terms_data() smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jsp-7: the Search Sources Manager auto-registers the two built-in sources (Terms,
	// Users) reachable via jet_search()->search_sources, keyed by their own get_name().
	try {
		$sources_manager = jet_search()->search_sources;
		$sources         = $sources_manager->get_sources();

		$has_terms = isset( $sources['terms'] ) && $sources['terms'] instanceof \Jet_Search\Search_Sources\Terms;
		$has_users = isset( $sources['users'] ) && $sources['users'] instanceof \Jet_Search\Search_Sources\Users;

		agent_test_assert(
			$suite, 'jsp-7',
			'SKILL.md "The Search Sources extensibility system": Manager::register_search_sources() auto-registers built-in Terms/Users sources, reachable via jet_search()->search_sources->get_sources()',
			( $has_terms && $has_users ),
			array( 'terms_registered' => true, 'users_registered' => true ),
			array( 'terms_registered' => $has_terms, 'users_registered' => $has_users, 'source_keys' => array_keys( $sources ) ),
			'includes/search-sources/manager.php:19-29'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsp-7', 'built-in search sources smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jsp-8: a custom search source registers exactly the same way the built-ins do — via
	// Manager::register_source(), the real mechanism jet-search/sources/register callbacks
	// use (extending \Jet_Search\Search_Sources\Base, the documented extension contract).
	try {
		if ( ! class_exists( '\Jet_Search\Search_Sources\Base' ) ) {
			require jet_search()->search_sources->component_path( 'base.php' );
		}

		if ( ! class_exists( 'Agent_Test_Jetsearch_Custom_Source' ) ) {
			class Agent_Test_Jetsearch_Custom_Source extends \Jet_Search\Search_Sources\Base {
				protected $source_name = 'agent_test_source';
				public function get_label() { return 'Agent Test Source'; }
				public function get_priority() { return 5; }
				public function build_items_list() { $this->items_list = array(); $this->results_count = 0; }
				public function get_query_result( $limit = null ) { return array(); }
			}
		}

		$sources_manager = jet_search()->search_sources;
		$sources_manager->register_source( new Agent_Test_Jetsearch_Custom_Source() );

		$fetched = $sources_manager->get_source( 'agent_test_source' );
		$pass    = ( $fetched instanceof Agent_Test_Jetsearch_Custom_Source );

		agent_test_assert(
			$suite, 'jsp-8',
			'SKILL.md "The Search Sources extensibility system": a custom source (extending Base, implementing get_label/get_priority/build_items_list/get_query_result) registers via the same Manager::register_source() call the built-ins use, reachable afterward via get_source()',
			$pass,
			array( 'custom_source_reachable' => true ),
			array( 'custom_source_reachable' => $pass, 'get_name' => $fetched ? $fetched->get_name() : null ),
			'includes/search-sources/manager.php:41-47; includes/search-sources/base.php:17'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsp-8', 'custom search source registration smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jsp-9: the REST route jet-search/v1/search-posts is registered (GET) and its
	// permission_callback() is an unconditional `return true` — no nonce check at all,
	// confirming the "REST alternative is not nonce-gated, unlike the AJAX path" claim.
	// Deliberately does NOT call callback() itself — it ends in wp_send_json_success()/
	// wp_die(), same uncatchable-in-harness shape as get_search_results().
	try {
		$routes        = rest_get_server()->get_routes();
		$route_present = isset( $routes['/jet-search/v1/search-posts'] );

		$get_method_present = false;
		if ( $route_present ) {
			foreach ( $routes['/jet-search/v1/search-posts'] as $route_def ) {
				if ( isset( $route_def['methods']['GET'] ) && $route_def['methods']['GET'] ) {
					$get_method_present = true;
					break;
				}
			}
		}

		$search_route         = new Jet_Search_Rest_Search_Route();
		$permission_no_nonce  = true === $search_route->permission_callback( new WP_REST_Request( 'GET', '/jet-search/v1/search-posts' ) );

		$pass = $route_present && $get_method_present && $permission_no_nonce;

		agent_test_assert(
			$suite, 'jsp-9',
			'SKILL.md "The REST alternative": /jet-search/v1/search-posts is a real registered GET route whose permission_callback() unconditionally returns true (no nonce check), unlike the AJAX path\'s wp_verify_nonce() gate',
			$pass,
			array( 'route_registered' => true, 'get_method_present' => true, 'permission_callback_returns_true_unconditionally' => true ),
			array( 'route_registered' => $route_present, 'get_method_present' => $get_method_present, 'permission_result' => $permission_no_nonce ),
			'includes/rest-api/manager.php:19,101-123; includes/rest-api/endpoints/search-route.php:42-53,451-453'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsp-9', 'search-posts REST route registration/permission smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jsp-10: the JetEngine macro compatibility integration registers jet_search_current_results
	// on JetEngine's jet-engine/listings/macros-list filter, via the jet-engine/register-macros
	// hook (the same cross-plugin registration pattern jettabs-query-gateway/
	// jetelements-query-gateway document other Crocoblock plugins using).
	try {
		// Jet_Engine_Listings_Macros::init() (includes/components/listings/macros.php:28-44)
		// only fires do_action('jet-engine/register-macros') and applies the
		// jet-engine/listings/macros-list filter lazily, the first time it's called
		// (guarded by an $initialized flag) — a raw apply_filters() call before anything
		// else has triggered that lazy init sees none of the sibling-plugin registrations,
		// including JetSearch's own. Force the same lazy init the plugin itself would
		// eventually trigger (e.g. from rendering a listing item), then read the handler's
		// own registered list rather than re-applying the filter blind.
		jet_engine()->listings->macros->init();
		$macros_list = jet_engine()->listings->macros->handler->get_raw_list();
		$pass        = isset( $macros_list['jet_search_current_results'] )
			&& isset( $macros_list['jet_search_current_results']['cb'] )
			&& is_callable( $macros_list['jet_search_current_results']['cb'] );

		agent_test_assert(
			$suite, 'jsp-10',
			'SKILL.md "JetEngine macro integration": jet_search_current_results is registered on jet-engine/listings/macros-list via Jet_Search_Compatibility_JE hooking jet-engine/register-macros',
			$pass,
			array( 'macro_registered' => true, 'callback_is_callable' => true ),
			array( 'macro_registered' => isset( $macros_list['jet_search_current_results'] ), 'macros_list_keys' => array_keys( $macros_list ) ),
			'includes/compatibility/jet-engine/manager.php:26,36-41; includes/compatibility/jet-engine/macros/current-results.php:11-21'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsp-10', 'JetEngine macro registration smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
