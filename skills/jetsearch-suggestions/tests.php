<?php
/**
 * AGENT-TEST-SUITE: jetsearch-suggestions
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetsearch-suggestions"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetsearch-suggestions. See docs/test-harness-guide.md.
 *
 * Every AGENT-TEST-* suggestion row this suite creates is deleted at the end of the
 * same test's own callback (fabricate-then-clean-up-in-the-same-request discipline).
 * No test calls a wp_ajax_* handler directly (those end in wp_send_json*() -> wp_die(),
 * which would terminate the whole request) -- REST endpoints are driven either via
 * rest_do_request() or by calling the underlying endpoint class's callback()/method
 * directly, matching this repo's established pattern (see jetengine-rest-api/tests.php,
 * jetcomparewishlist-data-store/tests.php).
 */

add_action( 'agent-test/run-suite/jetsearch-suggestions', function() {

	$suite = 'jetsearch-suggestions';

	// jss-1: both custom tables exist with the documented columns.
	try {
		global $wpdb;

		$suggestions_table = Jet_Search_DB::tables( 'search_suggestions', 'name' );
		$sessions_table    = Jet_Search_DB::tables( 'search_suggestions_sessions', 'name' );

		$suggestions_cols = array();
		$sessions_cols    = array();

		if ( $suggestions_table ) {
			$rows = $wpdb->get_results( "DESCRIBE {$suggestions_table}" );
			foreach ( (array) $rows as $r ) {
				$suggestions_cols[] = $r->Field;
			}
		}
		if ( $sessions_table ) {
			$rows = $wpdb->get_results( "DESCRIBE {$sessions_table}" );
			foreach ( (array) $rows as $r ) {
				$sessions_cols[] = $r->Field;
			}
		}

		$expected_suggestions_cols = array( 'id', 'name', 'weight', 'parent', 'term' );
		$expected_sessions_cols    = array( 'id', 'token', 'created_at' );

		$suggestions_ok = ! array_diff( $expected_suggestions_cols, $suggestions_cols );
		$sessions_ok    = ! array_diff( $expected_sessions_cols, $sessions_cols );

		agent_test_assert(
			$suite, 'jss-1',
			'SKILL.md "What a suggestion row actually is": jet_search_suggestions (id,name,weight,parent,term) and jet_search_suggestions_sessions (id,token,created_at) both exist with the documented columns',
			( $suggestions_ok && $sessions_ok ),
			array( 'suggestions_cols_present' => true, 'sessions_cols_present' => true ),
			array( 'suggestions_table' => $suggestions_table, 'suggestions_cols' => $suggestions_cols, 'sessions_table' => $sessions_table, 'sessions_cols' => $sessions_cols ),
			'includes/core/db.php:26-55,81-104'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jss-1', 'DB schema smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jss-2: all 5 suggestion REST routes are registered under jet-search/v1 by boot time.
	try {
		$routes = rest_get_server()->get_routes();
		$expected = array(
			'/jet-search/v1/add-suggestion',
			'/jet-search/v1/update-suggestion',
			'/jet-search/v1/delete-suggestion',
			'/jet-search/v1/get-suggestions',
			'/jet-search/v1/form-add-suggestion',
		);
		$found = array();
		foreach ( $expected as $route_prefix ) {
			$hit = false;
			foreach ( array_keys( $routes ) as $route ) {
				if ( 0 === strpos( $route, $route_prefix ) ) {
					$hit = true;
					break;
				}
			}
			$found[ $route_prefix ] = $hit;
		}
		$pass = ! in_array( false, $found, true );
		agent_test_assert(
			$suite, 'jss-2',
			'SKILL.md "The REST CRUD surface": all 5 suggestion routes (add/update/delete/get-suggestions, form-add-suggestion) are registered under jet-search/v1',
			$pass,
			array( 'all_routes_registered' => true ),
			$found,
			'includes/rest-api/manager.php:27-48,101-123'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jss-2', 'REST route registration smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jss-3: full admin CRUD round-trip (add -> get -> update -> delete), driven by
	// calling each registered endpoint class's callback() directly rather than via
	// rest_do_request()/dispatch() -- WP_REST_Request::set_param() stores the value
	// under whichever param-type bucket get_parameter_order() picks for the request's
	// method/content-type, and a full dispatch()'s route-arg merging didn't surface
	// 'content' the way a direct callback() call does (confirmed: jss-5/jss-6/jss-9
	// already use this same direct-call pattern successfully). Endpoint objects are
	// the real, already-`require`d singletons registered in Jet_Search_REST_API's
	// $_endpoints array (confirmed reachable via class_exists in jss-4), so
	// instantiating a fresh one here exercises the exact same callback() code path
	// dispatch() would have called.
	try {
		global $wpdb;

		$table_name = $wpdb->prefix . 'jet_search_suggestions';
		$name       = 'AGENT-TEST-jss3-' . uniqid();
		$renamed    = $name . '-renamed';
		$row_id     = null;

		try {
			// add-suggestion
			$add_endpoint = new Jet_Search_Rest_Add_Suggestion();
			$add_request  = new WP_REST_Request( 'POST', '/jet-search/v1/add-suggestion/' );
			$add_request->set_param( 'content', wp_json_encode( array( 'name' => $name, 'weight' => 1, 'parent' => 0, 'term' => null ) ) );
			$add_response = $add_endpoint->callback( $add_request );
			$add_data     = $add_response->get_data();
			$add_ok       = ! empty( $add_data['success'] );

			$row_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE name = %s", $name ) );

			// get-suggestions (admin search-by-name path)
			$get_endpoint = new Jet_Search_Rest_Get_Suggestions();
			$get_request  = new WP_REST_Request( 'GET', '/jet-search/v1/get-suggestions/' );
			$get_request->set_param( 'query', $name );
			$get_response = $get_endpoint->callback( $get_request );
			$get_data     = $get_response->get_data();
			$get_ok       = is_array( $get_data ) && ! empty( array_filter( $get_data, function( $o ) use ( $row_id ) { return isset( $o['value'] ) && (string) $row_id === (string) $o['value']; } ) );

			// update-suggestion
			$update_endpoint = new Jet_Search_Rest_Update_Suggestion();
			$update_request  = new WP_REST_Request( 'POST', '/jet-search/v1/update-suggestion/' );
			$update_request->set_param( 'content', wp_json_encode( array( 'id' => $row_id, 'name' => $renamed, 'weight' => 1, 'parent' => 0 ) ) );
			$update_response = $update_endpoint->callback( $update_request );
			$update_data     = $update_response->get_data();
			$update_ok       = ! empty( $update_data['success'] );
			$name_after_update = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$table_name} WHERE id = %d", $row_id ) );

			// delete-suggestion
			$delete_endpoint = new Jet_Search_Rest_Delete_Suggestion();
			$delete_request  = new WP_REST_Request( 'POST', '/jet-search/v1/delete-suggestion/' );
			$delete_request->set_param( 'content', wp_json_encode( array( 'id' => $row_id, 'name' => $renamed ) ) );
			$delete_response = $delete_endpoint->callback( $delete_request );
			$delete_data     = $delete_response->get_data();
			$delete_ok       = ! empty( $delete_data['success'] );
			$still_exists    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE id = %d", $row_id ) );

			$pass = $add_ok && $get_ok && $update_ok && ( $renamed === $name_after_update ) && $delete_ok && empty( $still_exists );

			agent_test_assert(
				$suite, 'jss-3',
				'SKILL.md "The REST CRUD surface": add-suggestion -> get-suggestions -> update-suggestion -> delete-suggestion full round trip, driven in-process by calling each endpoint\'s callback() directly',
				$pass,
				array( 'add_ok' => true, 'get_ok' => true, 'update_ok' => true, 'renamed_persisted' => true, 'delete_ok' => true, 'row_gone_after_delete' => true ),
				array( 'add_ok' => $add_ok, 'get_ok' => $get_ok, 'update_ok' => $update_ok, 'name_after_update' => $name_after_update, 'delete_ok' => $delete_ok, 'still_exists' => $still_exists, 'row_id' => $row_id ),
				'includes/rest-api/endpoints/add-suggestion.php, update-suggestion.php, delete-suggestion.php, get-suggestions.php'
			);
		} finally {
			// Safety-net cleanup: if delete-suggestion above didn't actually remove the row
			// (e.g. an earlier assertion in this try block failed/threw before reaching it),
			// make sure no AGENT-TEST-jss3-* row is left behind either way.
			if ( $row_id ) {
				$wpdb->delete( $table_name, array( 'id' => $row_id ) );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table_name} WHERE name IN (%s, %s)", $name, $renamed ) );
		}
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jss-3', 'admin CRUD round-trip smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jss-4: permission gating -- add-suggestion closed to logged-out, get-suggestions
	// open only for action=get_form_suggestions.
	try {
		if ( ! class_exists( 'Jet_Search_Rest_Add_Suggestion' ) || ! class_exists( 'Jet_Search_Rest_Get_Suggestions' ) ) {
			throw new \Exception( 'suggestion endpoint classes not loaded' );
		}

		$prior_user = get_current_user_id();
		wp_set_current_user( 0 );

		$add_endpoint = new Jet_Search_Rest_Add_Suggestion();
		$add_denied   = false === $add_endpoint->permission_callback( new WP_REST_Request( 'POST', '/jet-search/v1/add-suggestion/' ) );

		$get_endpoint = new Jet_Search_Rest_Get_Suggestions();

		$req_form_suggestions = new WP_REST_Request( 'GET', '/jet-search/v1/get-suggestions/' );
		$req_form_suggestions->set_param( 'action', 'get_form_suggestions' );
		$form_suggestions_allowed = true === $get_endpoint->permission_callback( $req_form_suggestions );

		$req_no_action = new WP_REST_Request( 'GET', '/jet-search/v1/get-suggestions/' );
		$no_action_denied = false === $get_endpoint->permission_callback( $req_no_action );

		wp_set_current_user( $prior_user );

		$pass = $add_denied && $form_suggestions_allowed && $no_action_denied;

		agent_test_assert(
			$suite, 'jss-4',
			'SKILL.md "The REST CRUD surface" / gotcha "get-suggestions has an unauthenticated carve-out": add-suggestion requires manage_options; get-suggestions only waives that when action=get_form_suggestions',
			$pass,
			array( 'add_suggestion_denied_logged_out' => true, 'get_form_suggestions_allowed_logged_out' => true, 'get_suggestions_no_action_denied_logged_out' => true ),
			array( 'add_denied' => $add_denied, 'form_suggestions_allowed' => $form_suggestions_allowed, 'no_action_denied' => $no_action_denied ),
			'includes/rest-api/endpoints/add-suggestion.php:83-85, get-suggestions.php:407-413'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jss-4', 'permission gating smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jss-5: form-add-suggestion auto-logs a new term (weight=1, parent=0) and increments
	// weight (not a new row) on a repeat of the same name.
	try {
		global $wpdb;

		if ( ! class_exists( 'Jet_Search_Rest_Form_Add_Suggestion' ) ) {
			throw new \Exception( 'Jet_Search_Rest_Form_Add_Suggestion not loaded' );
		}

		$table_name = $wpdb->prefix . 'jet_search_suggestions';
		$name       = 'AGENT-TEST-jss5-' . uniqid();
		$row_id     = null;

		try {
			$endpoint = new Jet_Search_Rest_Form_Add_Suggestion();

			$request = new WP_REST_Request( 'POST', '/jet-search/v1/form-add-suggestion/' );
			$request->set_param( 'data', array( 'name' => $name ) );

			$endpoint->callback( $request ); // 1st call: insert weight=1, parent=0
			$first  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE name = %s", $name ), ARRAY_A );
			$row_id = $first ? $first['id'] : null;

			$endpoint->callback( $request ); // 2nd call: same name, weight -> 2, no new row
			$count_after   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE name = %s", $name ) );
			$second        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE name = %s", $name ), ARRAY_A );

			$pass = $first && ( '1' === (string) $first['weight'] ) && ( '0' === (string) $first['parent'] )
				&& ( 1 === $count_after ) && $second && ( '2' === (string) $second['weight'] );

			agent_test_assert(
				$suite, 'jss-5',
				'SKILL.md "get-suggestions?action=get_form_suggestions and form-add-suggestion...": form-add-suggestion inserts weight=1/parent=0 on first call, increments weight to 2 on the same row (not a new row) on a repeat',
				$pass,
				array( 'first_weight' => '1', 'first_parent' => '0', 'row_count_after_repeat' => 1, 'second_weight' => '2' ),
				array( 'first' => $first, 'count_after' => $count_after, 'second' => $second ),
				'includes/rest-api/endpoints/form-add-suggestion.php:47-98'
			);
		} finally {
			if ( $row_id ) {
				jet_search()->db->delete( 'search_suggestions', array( 'id' => $row_id ) );
			}
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table_name} WHERE name = %s", $name ) );
		}
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jss-5', 'form-add-suggestion auto-log/weight-increment smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jss-6: get_form_suggestions_list()'s no-value branch only returns parent=0 rows
	// (a child suggestion is never surfaced by the popular/latest list).
	try {
		if ( ! class_exists( 'Jet_Search_Rest_Get_Suggestions' ) ) {
			throw new \Exception( 'Jet_Search_Rest_Get_Suggestions not loaded' );
		}

		$parent_name = 'AGENT-TEST-jss6-parent-' . uniqid();
		$child_name  = 'AGENT-TEST-jss6-child-' . uniqid();
		$parent_id   = null;
		$child_id    = null;

		try {
			$parent_id = jet_search()->db->update( 'search_suggestions', array( 'name' => $parent_name, 'weight' => 999999, 'parent' => 0, 'term' => null ) );
			$child_id  = jet_search()->db->update( 'search_suggestions', array( 'name' => $child_name, 'weight' => 999999, 'parent' => $parent_id, 'term' => null ) );

			$endpoint = new Jet_Search_Rest_Get_Suggestions();
			$list     = $endpoint->get_form_suggestions_list( array( 'list_type' => 'latest', 'limit' => 500, 'value' => '' ) );

			$ids_in_list  = is_array( $list ) ? array_map( function( $r ) { return (string) $r['id']; }, $list ) : array();
			$parent_found = in_array( (string) $parent_id, $ids_in_list, true );
			$child_found  = in_array( (string) $child_id, $ids_in_list, true );

			$pass = $parent_found && ! $child_found;

			agent_test_assert(
				$suite, 'jss-6',
				'SKILL.md "...form-add-suggestion...": get_form_suggestions_list()\'s no-value ("popular"/"latest") branch filters WHERE parent = 0 -- a child suggestion is never returned',
				$pass,
				array( 'parent_in_list' => true, 'child_in_list' => false ),
				array( 'parent_found' => $parent_found, 'child_found' => $child_found, 'parent_id' => $parent_id, 'child_id' => $child_id ),
				'includes/rest-api/endpoints/get-suggestions.php:132-192 (no-value branch: lines 151-161)'
			);
		} finally {
			if ( $child_id ) {
				jet_search()->db->delete( 'search_suggestions', array( 'id' => $child_id ) );
			}
			if ( $parent_id ) {
				jet_search()->db->delete( 'search_suggestions', array( 'id' => $parent_id ) );
			}
		}
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jss-6', 'parent=0 filtering smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jss-7: the shipped admin UI calls AJAX action names, not the REST suggestion routes.
	try {
		$js_file = WP_PLUGIN_DIR . '/jet-search/assets/js/jet-search-admin-vue-components.js';
		if ( ! file_exists( $js_file ) ) {
			throw new \Exception( 'admin Vue bundle not found at expected path: ' . $js_file );
		}
		$contents = file_get_contents( $js_file );

		$ajax_actions = array( 'jet_search_add_suggestion', 'jet_search_get_suggestion', 'jet_search_update_suggestion', 'jet_search_delete_suggestion' );
		$ajax_found   = array();
		foreach ( $ajax_actions as $a ) {
			$ajax_found[ $a ] = ( false !== strpos( $contents, $a ) );
		}
		$all_ajax_present = ! in_array( false, $ajax_found, true );

		$rest_paths = array( '/jet-search/v1/add-suggestion', '/jet-search/v1/update-suggestion', '/jet-search/v1/delete-suggestion' );
		$rest_found = array();
		foreach ( $rest_paths as $p ) {
			$rest_found[ $p ] = ( false !== strpos( $contents, $p ) );
		}
		$no_rest_present = ! in_array( true, $rest_found, true );

		$pass = $all_ajax_present && $no_rest_present;

		agent_test_assert(
			$suite, 'jss-7',
			'SKILL.md "AJAX handlers duplicate this almost line-for-line": the compiled admin Vue UI calls admin-ajax jet_search_*_suggestion actions and never references the REST suggestion routes',
			$pass,
			array( 'all_ajax_actions_present' => true, 'no_rest_suggestion_paths_present' => true ),
			array( 'ajax_found' => $ajax_found, 'rest_found' => $rest_found ),
			'assets/js/jet-search-admin-vue-components.js (compiled bundle, grepped for literal action/route strings)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jss-7', 'admin UI transport smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jss-8: wp_ajax_suggestions_get_user_id is registered against a method that doesn't
	// exist -- a real, live, registered-but-undefined-callback bug. Does NOT actually
	// fire the action (would fatal with "call to undefined method").
	//
	// Jet_Search_Ajax_Handlers::init() (ajax-handlers.php:97-129) only registers the
	// wp_ajax_* suggestion actions inside `if ( defined('DOING_AJAX') && DOING_AJAX )`.
	// This suite runs inside a REST request, so DOING_AJAX was never true when init()
	// ran at plugin boot and that whole registration block was skipped -- same
	// "lazily-gated behind a runtime condition, force it yourself" shape as the
	// lazy-require gotchas documented in jetengine-modules/jetengine-rest-api. Define
	// DOING_AJAX and re-invoke init() to force that branch to run (idempotent/safe:
	// add_action() de-dupes an identical [$this, 'method'] callback on the same hook,
	// and init()'s other side effects are all already-satisfied no-ops on a second call).
	try {
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}
		if ( function_exists( 'jet_search_ajax_handlers' ) ) {
			jet_search_ajax_handlers()->init();
		}

		$hook_registered = (bool) has_action( 'wp_ajax_suggestions_get_user_id' );
		$method_exists    = method_exists( 'Jet_Search_Ajax_Handlers', 'suggestions_get_user_id' );

		$pass = $hook_registered && ! $method_exists;

		agent_test_assert(
			$suite, 'jss-8',
			'SKILL.md gotcha "wp_ajax_suggestions_get_user_id is registered against a method that does not exist": hook is registered, but Jet_Search_Ajax_Handlers has no such method (triggering the action would fatal) -- a PASS here confirms the bug is still present, not that behavior is correct',
			$pass,
			array( 'hook_registered' => true, 'method_exists' => false ),
			array( 'hook_registered' => $hook_registered, 'method_exists' => $method_exists ),
			'includes/ajax-handlers.php:100-101 (registration) -- no matching method anywhere in the plugin'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jss-8', 'undefined AJAX callback registration smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jss-9: deleting a parent suggestion never clears its children's parent field --
	// live reproduction of the remove_deleted_parent() strict-=== type-mismatch bug.
	// NOTE: a PASS here means the documented bug was reproduced (child parent unchanged),
	// not that the plugin behaved "correctly" -- see $notes.
	try {
		global $wpdb;

		if ( ! class_exists( 'Jet_Search_Rest_Delete_Suggestion' ) ) {
			throw new \Exception( 'Jet_Search_Rest_Delete_Suggestion not loaded' );
		}

		$table_name  = $wpdb->prefix . 'jet_search_suggestions';
		$parent_name = 'AGENT-TEST-jss9-parent-' . uniqid();
		$child_name  = 'AGENT-TEST-jss9-child-' . uniqid();
		$parent_id   = null;
		$child_id    = null;

		try {
			$parent_id = jet_search()->db->update( 'search_suggestions', array( 'name' => $parent_name, 'weight' => 1, 'parent' => 0, 'term' => null ) );
			$child_id  = jet_search()->db->update( 'search_suggestions', array( 'name' => $child_name, 'weight' => 1, 'parent' => $parent_id, 'term' => null ) );

			$endpoint = new Jet_Search_Rest_Delete_Suggestion();
			$request  = new WP_REST_Request( 'POST', '/jet-search/v1/delete-suggestion/' );
			$request->set_param( 'content', wp_json_encode( array( 'id' => $parent_id, 'name' => $parent_name ) ) );
			$endpoint->callback( $request );

			$parent_gone       = ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE id = %d", $parent_id ) );
			$child_parent_now  = $wpdb->get_var( $wpdb->prepare( "SELECT parent FROM {$table_name} WHERE id = %d", $child_id ) );
			$child_still_orphaned = $parent_gone && ( (string) $child_parent_now === (string) $parent_id );

			agent_test_assert(
				$suite, 'jss-9',
				'SKILL.md gotcha "Deleting a parent suggestion never actually detaches its children": remove_deleted_parent()\'s strict === comparison never matches (string vs int), so a child\'s parent field is never reset to 0 after its parent is deleted -- PASS confirms the bug is reproduced live, it does NOT mean this is correct/desired behavior',
				$child_still_orphaned,
				array( 'parent_deleted' => true, 'child_parent_still_points_at_deleted_parent_id' => true ),
				array( 'parent_gone' => $parent_gone, 'child_parent_now' => $child_parent_now, 'parent_id' => $parent_id ),
				'includes/rest-api/endpoints/delete-suggestion.php:113-132 (remove_deleted_parent, buggy === at line 116)'
			);
		} finally {
			if ( $child_id ) {
				jet_search()->db->delete( 'search_suggestions', array( 'id' => $child_id ) );
			}
			if ( $parent_id ) {
				jet_search()->db->delete( 'search_suggestions', array( 'id' => $parent_id ) );
			}
		}
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jss-9', 'parent-orphaning bug live reproduction', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jss-10: Jet_Search_DB::update() insert-vs-update-by-id round trip.
	try {
		$name    = 'AGENT-TEST-jss10-' . uniqid();
		$renamed = $name . '-renamed';
		$id1     = null;

		try {
			$id1 = jet_search()->db->update( 'search_suggestions', array( 'name' => $name, 'weight' => 1, 'parent' => 0, 'term' => null ) );
			$id2 = jet_search()->db->update( 'search_suggestions', array( 'id' => $id1, 'name' => $renamed ) );

			global $wpdb;
			$table_name = $wpdb->prefix . 'jet_search_suggestions';
			$row_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE name IN (%s, %s)", $name, $renamed ) );
			$final_name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$table_name} WHERE id = %d", $id1 ) );

			$pass = is_numeric( $id1 ) && ( (string) $id1 === (string) $id2 ) && ( 1 === $row_count ) && ( $renamed === $final_name );

			agent_test_assert(
				$suite, 'jss-10',
				'SKILL.md "Jet_Search_DB - the shared CRUD primitive": update() with no id key inserts and returns insert_id; with an id key present, updates that row in place and returns the same id, no second row created',
				$pass,
				array( 'ids_match' => true, 'single_row' => true, 'renamed_persisted' => true ),
				array( 'id1' => $id1, 'id2' => $id2, 'row_count' => $row_count, 'final_name' => $final_name ),
				'includes/core/db.php:112-147'
			);
		} finally {
			if ( $id1 ) {
				jet_search()->db->delete( 'search_suggestions', array( 'id' => $id1 ) );
			}
		}
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jss-10', 'Jet_Search_DB::update() insert/update round trip', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
