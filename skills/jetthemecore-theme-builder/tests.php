<?php
/**
 * AGENT-TEST-SUITE: jetthemecore-theme-builder
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetthemecore-theme-builder"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetthemecore-theme-builder. See docs/test-harness-guide.md.
 *
 * NOT YET RUN: JetThemeCore is not installed on the sandbox (jackfruit.epeak.studio) as
 * of this writing — see TEST-REGIMEN.md. Written as-if-ready. Every test that creates a
 * jet-page-template post force-deletes it at the end of its own callback (wp_delete_post
 * with $force_delete = true) so the suite is safe to re-run.
 */

add_action( 'agent-test/run-suite/jetthemecore-theme-builder', function() {

	$suite = 'jetthemecore-theme-builder';

	// jttb-1: is_excluded/and and is_excluded/or both fire with the documented 3-arg signature,
	// gated by which relation_type a given Page Template post uses.
	try {
		$ptm = function_exists( 'jet_theme_core' ) ? jet_theme_core()->theme_builder->page_templates_manager : null;
		$fem = function_exists( 'jet_theme_core' ) ? jet_theme_core()->theme_builder->frontend_manager : null;

		if ( ! $ptm || ! $fem ) {
			throw new \Exception( 'theme_builder->page_templates_manager or ->frontend_manager not available' );
		}

		$condition_rows = array(
			array( 'id' => '_inc', 'include' => 'true', 'group' => 'entire', 'subGroup' => 'entire' ),
			array( 'id' => '_exc', 'include' => 'false', 'group' => 'entire', 'subGroup' => 'entire' ),
		);

		// create_page_template() returns the new id at data.newTemplateId (not id/data.id), and
		// — live-verified 2026-07-16 — it leaves the OPTION-based conditions record it writes
		// empty ([]) regardless of the $template_conditions arg passed in; only
		// update_page_template_conditions() actually populates the option that
		// get_site_page_template_conditions()/get_matched_page_template_conditions() read (the
		// $template_conditions arg only ever lands in post meta via meta_input, a separate,
		// not-read-by-the-matcher store). Both calls are required to make a freshly-created
		// Page Template actually match anything.
		$and_result = $ptm->create_page_template( 'AGENT_TEST_AND', $condition_rows, array(), 'unassigned', 'and' );
		$or_result  = $ptm->create_page_template( 'AGENT_TEST_OR', $condition_rows, array(), 'unassigned', 'or' );

		$and_id = is_array( $and_result ) && isset( $and_result['data']['newTemplateId'] ) ? $and_result['data']['newTemplateId'] : null;
		$or_id  = is_array( $or_result ) && isset( $or_result['data']['newTemplateId'] ) ? $or_result['data']['newTemplateId'] : null;

		if ( $and_id ) { $ptm->update_page_template_conditions( $and_id, $condition_rows ); }
		if ( $or_id )  { $ptm->update_page_template_conditions( $or_id, $condition_rows ); }

		$seen_and = null;
		$seen_or  = null;

		add_filter( 'jet-theme-core/page-template-condition/is_excluded/and', function( $is_excluded, $excludes_matchs, $page_template_id ) use ( &$seen_and ) {
			$seen_and = array( 'is_excluded' => $is_excluded, 'excludes_matchs' => $excludes_matchs, 'page_template_id' => $page_template_id );
			return $is_excluded;
		}, 10, 3 );
		add_filter( 'jet-theme-core/page-template-condition/is_excluded/or', function( $is_excluded, $excludes_matchs, $page_template_id ) use ( &$seen_or ) {
			$seen_or = array( 'is_excluded' => $is_excluded, 'excludes_matchs' => $excludes_matchs, 'page_template_id' => $page_template_id );
			return $is_excluded;
		}, 10, 3 );

		$fem->get_matched_page_template_conditions();

		remove_all_filters( 'jet-theme-core/page-template-condition/is_excluded/and' );
		remove_all_filters( 'jet-theme-core/page-template-condition/is_excluded/or' );

		// Cleanup.
		if ( $and_id ) { wp_delete_post( $and_id, true ); }
		if ( $or_id ) { wp_delete_post( $or_id, true ); }

		$pass = is_array( $seen_and ) && is_array( $seen_or )
			&& is_array( $seen_and['excludes_matchs'] ) && is_array( $seen_or['excludes_matchs'] )
			&& $and_id && $or_id
			&& (int) $seen_and['page_template_id'] === (int) $and_id
			&& (int) $seen_or['page_template_id'] === (int) $or_id;

		agent_test_assert(
			$suite, 'jttb-1',
			'SKILL.md "and\'/\'or\' relation type...": jet-theme-core/page-template-condition/is_excluded/and and /or both fire from get_matched_page_template_conditions(), each with 3 args (bool, excludes_matchs array, page_template_id), gated by each post\'s own _relation_type',
			$pass,
			array( 'and_fired_for_and_post' => true, 'or_fired_for_or_post' => true ),
			array( 'and_id' => $and_id, 'or_id' => $or_id, 'seen_and' => $seen_and, 'seen_or' => $seen_or ),
			'includes/theme-builder/includes/frontend-manager.php:579-601 (get_matched_page_template_conditions())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jttb-1', 'is_excluded/and + /or live-fire test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jttb-2: get_primary_page_template_id_by_conditions() picks the candidate with the
	// LOWER averaged include-row priority, using a hand-built input array (avoids needing a
	// real low/high-priority condition pair to naturally co-match one request).
	try {
		$fem = function_exists( 'jet_theme_core' ) ? jet_theme_core()->theme_builder->frontend_manager : null;
		if ( ! $fem || ! method_exists( $fem, 'get_primary_page_template_id_by_conditions' ) ) {
			throw new \Exception( 'frontend_manager->get_primary_page_template_id_by_conditions not available' );
		}

		$fabricated = array(
			'high_priority_template' => array(
				array( 'include' => 'true', 'priority' => 50 ),
			),
			'low_priority_template' => array(
				array( 'include' => 'true', 'priority' => 5 ),
			),
		);

		$winner = $fem->get_primary_page_template_id_by_conditions( $fabricated );

		agent_test_assert(
			$suite, 'jttb-2',
			'SKILL.md "Tie-break between multiple matching Page Templates...": get_primary_page_template_id_by_conditions() sorts ascending by averaged include-row priority and returns the LOWER-priority-average candidate as the winner',
			( 'low_priority_template' === $winner ),
			array( 'winner' => 'low_priority_template' ),
			array( 'winner' => $winner ),
			'includes/theme-builder/includes/frontend-manager.php:459-490 (get_primary_page_template_id_by_conditions())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jttb-2', 'averaged-priority tie-break test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jttb-3: _layout/_relation_type/_conditions meta round-trip through Page_Templates_Manager's
	// write methods, and get_site_page_template_conditions() reflects them back.
	try {
		$ptm = function_exists( 'jet_theme_core' ) ? jet_theme_core()->theme_builder->page_templates_manager : null;
		if ( ! $ptm ) {
			throw new \Exception( 'page_templates_manager not available' );
		}

		$created = $ptm->create_page_template( 'AGENT_TEST_ROUNDTRIP', array(), array(), 'unassigned', 'or' );
		$id = is_array( $created ) && isset( $created['data']['newTemplateId'] ) ? $created['data']['newTemplateId'] : null;

		if ( ! $id ) {
			throw new \Exception( 'create_page_template did not return a usable id: ' . wp_json_encode( $created ) );
		}

		$test_conditions = array( array( 'id' => '_rt', 'include' => 'true', 'group' => 'entire', 'subGroup' => 'entire' ) );
		$ptm->update_page_template_conditions( $id, $test_conditions );
		$ptm->update_page_template_relation_type( $id, 'and' );
		$test_layout = array( 'header' => array( 'id' => 123, 'enabled' => true, 'override' => true ) );
		$ptm->update_page_template_layout( $id, $test_layout );

		$read_relation = $ptm->get_page_template_relation_type( $id );
		$read_layout   = $ptm->get_page_template_layout( $id );
		$site_conditions = $ptm->get_site_page_template_conditions();

		$pass = ( 'and' === $read_relation )
			&& is_array( $read_layout ) && isset( $read_layout['header']['id'] ) && 123 === (int) $read_layout['header']['id']
			&& isset( $site_conditions[ $id ] ) && 'and' === $site_conditions[ $id ]['relation_type'];

		wp_delete_post( $id, true );

		agent_test_assert(
			$suite, 'jttb-3',
			'SKILL.md "Storage...": update_page_template_conditions()/update_page_template_relation_type()/update_page_template_layout() write meta that round-trips through get_page_template_relation_type()/get_page_template_layout()/get_site_page_template_conditions()',
			$pass,
			array( 'relation_type' => 'and', 'layout_header_id' => 123, 'site_conditions_has_id_with_and' => true ),
			array( 'read_relation' => $read_relation, 'read_layout' => $read_layout, 'site_conditions_entry' => isset( $site_conditions[ $id ] ) ? $site_conditions[ $id ] : null ),
			'includes/theme-builder/includes/page-templates-manager.php:65-88,431-475,608-616'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jttb-3', 'Page Template meta round-trip test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jttb-4: the classic REST update-template-conditions endpoint writes through to
	// Template_Conditions\Manager::update_template_conditions() and returns fresh verboseHtml
	// in the same response. Callback invoked directly (not through a live REST round-trip).
	try {
		if ( ! class_exists( '\\Jet_Theme_Core\\Endpoints\\Update_Template_Conditions' ) ) {
			// The endpoint class is only require()'d lazily inside Rest_Api::init_endpoints();
			// force that to happen by reading get_endpoints() once.
			if ( function_exists( 'jet_theme_core' ) ) {
				$rest_api = new \Jet_Theme_Core\Rest_Api();
				$rest_api->get_endpoints();
			}
		}

		if ( ! class_exists( '\\Jet_Theme_Core\\Endpoints\\Update_Template_Conditions' ) ) {
			throw new \Exception( 'Update_Template_Conditions endpoint class still not loaded' );
		}

		// post_conditions_verbose() resolves a structure via get_post_structure(), which reads
		// the _jet_template_type post meta (NOT post_type) - a plain 'post' with no such meta
		// makes get_post_structure() return false, and post_conditions_verbose() calls
		// ->has_conditions() on it unconditionally, fataling with "Call to a member function
		// has_conditions() on false" (live-verified 2026-07-16). Set _jet_template_type to a
		// real structure id ('jet_header') so this resolves to a real Structures\Header instance.
		$test_post_id = wp_insert_post( array(
			'post_title'  => 'AGENT_TEST_TEMPLATE',
			'post_type'   => 'post',
			'post_status' => 'draft',
			'meta_input'  => array( '_jet_template_type' => 'jet_header' ),
		) );
		if ( is_wp_error( $test_post_id ) || ! $test_post_id ) {
			throw new \Exception( 'could not create test post' );
		}

		$endpoint = new \Jet_Theme_Core\Endpoints\Update_Template_Conditions();
		$test_conditions = array( array( 'id' => '_rest', 'include' => 'true', 'group' => 'entire', 'subGroup' => 'entire' ) );

		$request = new \WP_REST_Request( 'POST', '/jet-theme-core-api/v2/update-template-conditions' );
		$request->set_param( 'template_id', $test_post_id );
		$request->set_param( 'conditions', $test_conditions );

		$response = $endpoint->callback( $request );
		$data = $response instanceof \WP_REST_Response ? $response->get_data() : $response;

		$saved_meta = get_post_meta( $test_post_id, '_jet_template_conditions', true );

		wp_delete_post( $test_post_id, true );

		$pass = is_array( $data ) && ! empty( $data['success'] )
			&& $saved_meta === $test_conditions
			&& ! empty( $data['data']['verboseHtml'] ) && is_string( $data['data']['verboseHtml'] );

		agent_test_assert(
			$suite, 'jttb-4',
			'SKILL.md "The jet-theme-core-api/v2 REST namespace...": update-template-conditions endpoint writes straight through to Template_Conditions\\Manager::update_template_conditions() and returns a ready-to-inject verboseHtml string in the same response',
			$pass,
			array( 'success' => true, 'meta_matches_submitted' => true, 'verboseHtml_nonempty' => true ),
			array( 'response_data' => $data, 'saved_meta' => $saved_meta ),
			'includes/rest-api/endpoints/update-template-conditions.php:54-84'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jttb-4', 'update-template-conditions REST endpoint direct-callback test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
