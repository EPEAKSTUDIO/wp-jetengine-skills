<?php
/**
 * AGENT-TEST-SUITE: jetreviews-data-model
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetreviews-data-model"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetreviews-data-model. See docs/test-harness-guide.md.
 *
 * NOT YET RUN: JetReviews For Elementor is not installed on the sandbox
 * (jackfruit.epeak.studio) as of this writing — see TEST-REGIMEN.md. Written as-if-ready
 * per this repo's convention; every call here either targets a static/pure method or the
 * plugin's own live singleton accessor (jet_reviews()->...) — never `new`s a component
 * Manager class directly, per SKILL.md's "Never instantiate a component Manager class
 * yourself" landmine (User\Manager, Reviews\Manager, Reviews\Sources, Comments\Manager
 * all do an unconditional `require` inside their own constructor's call chain).
 */

add_action( 'agent-test/run-suite/jetreviews-data-model', function() {

	$suite = 'jetreviews-data-model';

	// jrd-1: DB\Manager::tables() is a static, side-effect-free read returning the 6 expected table keys.
	try {
		$class_ok = class_exists( '\\Jet_Reviews\\DB\\Manager' );
		$tables   = $class_ok ? \Jet_Reviews\DB\Manager::tables() : array();
		$keys     = is_array( $tables ) ? array_keys( $tables ) : array();
		$expected_keys = array( 'reviews', 'review_meta', 'review_media', 'review_types', 'review_comments', 'review_guests' );
		$pass = $class_ok && empty( array_diff( $expected_keys, $keys ) );
		agent_test_assert(
			$suite, 'jrd-1',
			'SKILL.md "Reviews live in six custom DB tables": \\Jet_Reviews\\DB\\Manager::tables() (static, no side effects) returns config for all 6 tables: reviews, review_meta, review_media, review_types, review_comments, review_guests',
			$pass,
			array( 'table_keys' => $expected_keys ),
			array( 'class_exists' => $class_ok, 'table_keys' => $keys ),
			'includes/db/manager.php:70-186 (tables()), :76-166 (the 6 table configs)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jrd-1', 'DB\\Manager::tables() smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jrd-2: jet_reviews()->db is the live, already-constructed instance and its table actually exists in the DB.
	try {
		$db = function_exists( 'jet_reviews' ) ? jet_reviews()->db : null;
		$class = $db ? get_class( $db ) : null;
		$table_exists = $db ? $db->is_table_exists( 'reviews' ) : null;
		agent_test_assert(
			$suite, 'jrd-2',
			'SKILL.md "Never instantiate...": jet_reviews()->db is the live Jet_Reviews\\DB\\Manager instance the bootstrap already constructed, and its "reviews" table exists (init_db_required() ran on init)',
			( 'Jet_Reviews\\DB\\Manager' === $class && true === $table_exists ),
			array( 'class' => 'Jet_Reviews\\DB\\Manager', 'table_exists' => true ),
			array( 'class' => $class, 'table_exists' => $table_exists ),
			'jet-reviews.php:266 ($this->db = new Jet_Reviews\\DB\\Manager, inside init() hooked at init -999), includes/db/manager.php:351-362 (is_table_exists())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jrd-2', 'jet_reviews()->db reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jrd-3: the live Sources registry (never `new Sources()` directly — see SKILL.md landmine) has both built-in sources.
	try {
		$reviews_manager = function_exists( 'jet_reviews' ) ? jet_reviews()->reviews_manager : null;
		$sources = $reviews_manager ? $reviews_manager->sources : null;
		$list = ( $sources && method_exists( $sources, 'get_registered_source_list' ) ) ? $sources->get_registered_source_list() : array();
		$has_post = is_array( $list ) && array_key_exists( 'post', $list );
		$has_user = is_array( $list ) && array_key_exists( 'user', $list );
		agent_test_assert(
			$suite, 'jrd-3',
			'SKILL.md "The Sources abstraction": jet_reviews()->reviews_manager->sources->get_registered_source_list() contains both "post" and "user" keys (the two built-in sources) — read via the LIVE instance only, never `new Sources()`',
			( $has_post && $has_user ),
			array( 'has_post' => true, 'has_user' => true ),
			array( 'list_keys' => is_array( $list ) ? array_keys( $list ) : null ),
			'includes/components/reviews/sources.php:32-43 (registered_sources filter default), :118-131 (get_registered_source_list()) — DO NOT `new Sources()`, see SKILL.md landmine'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jrd-3', 'live Sources registry smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jrd-4: jet-reviews/source/source-{slug}/current-id is genuinely honored for both built-in sources.
	try {
		$reviews_manager = function_exists( 'jet_reviews' ) ? jet_reviews()->reviews_manager : null;
		$sources = $reviews_manager ? $reviews_manager->sources : null;
		$post_source = $sources ? $sources->get_source_instance( 'post' ) : null;
		$user_source = $sources ? $sources->get_source_instance( 'user' ) : null;

		add_filter( 'jet-reviews/source/source-post/current-id', function( $id ) {
			return 'agent_test_post_marker';
		} );
		add_filter( 'jet-reviews/source/source-user/current-id', function( $id ) {
			return 'agent_test_user_marker';
		} );

		$post_id = $post_source ? $post_source->get_current_id() : null;
		$user_id = $user_source ? $user_source->get_current_id() : null;

		remove_all_filters( 'jet-reviews/source/source-post/current-id' );
		remove_all_filters( 'jet-reviews/source/source-user/current-id' );

		$pass = ( 'agent_test_post_marker' === $post_id ) && ( 'agent_test_user_marker' === $user_id );
		agent_test_assert(
			$suite, 'jrd-4',
			'SKILL.md "per-source override hook": jet-reviews/source/source-{slug}/current-id filters the resolved id for both the post and user sources',
			$pass,
			array( 'post_id' => 'agent_test_post_marker', 'user_id' => 'agent_test_user_marker' ),
			array( 'post_id' => $post_id, 'user_id' => $user_id ),
			'includes/components/reviews/sources/post.php:34, includes/components/reviews/sources/user.php:35'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jrd-4', 'per-source current-id filter smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jrd-5: jet-reviews/user-manager/raw-user-data is genuinely honored.
	try {
		$user_manager = function_exists( 'jet_reviews' ) ? jet_reviews()->user_manager : null;
		add_filter( 'jet-reviews/user-manager/raw-user-data', function( $data ) {
			$data['agent_test_marker'] = true;
			return $data;
		} );
		$data = $user_manager ? $user_manager->get_raw_user_data( 0 ) : null;
		remove_all_filters( 'jet-reviews/user-manager/raw-user-data' );
		$pass = is_array( $data ) && ! empty( $data['agent_test_marker'] ) && isset( $data['id'], $data['name'], $data['mail'], $data['avatar'], $data['roles'] );
		agent_test_assert(
			$suite, 'jrd-5',
			'SKILL.md "jet-reviews/user-manager/raw-user-data...": the filter is applied to the {id,name,mail,avatar,roles} shape returned by get_raw_user_data(), including for the anonymous/guest-fallback branch (user_id=0)',
			$pass,
			array( 'marker_present' => true, 'shape_keys' => array( 'id', 'name', 'mail', 'avatar', 'roles' ) ),
			array( 'data' => $data ),
			'includes/components/user/manager.php:329-367 (three apply_filters call sites: real user, known guest, anonymous fallback)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jrd-5', 'raw-user-data filter smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jrd-6: jet-reviews/structure-data/types is present, and get_valid_structure_data_type() falls back to 'Product' for garbage input.
	try {
		$tools = function_exists( 'jet_reviews_tools' ) ? jet_reviews_tools() : null;
		$types = ( $tools && method_exists( $tools, 'get_structure_data_types' ) ) ? $tools->get_structure_data_types() : array();
		$has_groups = is_array( $types ) && count( $types ) > 0;
		$fallback = ( $tools && method_exists( $tools, 'get_valid_structure_data_type' ) )
			? $tools->get_valid_structure_data_type( 'AgentTestNotARealType' )
			: null;
		$pass = $has_groups && ( 'Product' === $fallback );
		agent_test_assert(
			$suite, 'jrd-6',
			'SKILL.md "Structured data...": jet-reviews/structure-data/types returns a non-empty grouped list, and get_valid_structure_data_type() silently falls back to the default "Product" for an unregistered type value rather than erroring',
			$pass,
			array( 'has_groups' => true, 'fallback' => 'Product' ),
			array( 'has_groups' => $has_groups, 'fallback' => $fallback ),
			'includes/tools.php:666-718 (get_structure_data_types()), :757-760 (get_default_structure_data_type() = Product), :767-783 (get_valid_structure_data_type())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jrd-6', 'structure-data-type fallback smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
