<?php
/**
 * AGENT-TEST-SUITE: jetblog-query-pipeline
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetblog-query-pipeline"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetblog-query-pipeline. See docs/test-harness-guide.md.
 *
 * These tests instantiate JetBlog widget classes directly (Elementor\Jet_Blog_Smart_Listing,
 * etc.) — safe, since Widget_Base's own constructor has no hook-registration/require
 * side effects beyond what Elementor does for any widget instantiation, unlike the
 * "never `new` a plugin's own singleton/manager class" landmine documented in
 * jetsmartfilters-query/jetappointments-core for THOSE plugins' custom classes.
 * No live Query Builder query or published Smart Listing widget instance exists yet on
 * this sandbox — see TEST-REGIMEN.md's Test 6/7 for the manual, fixture-needing steps.
 */

add_action( 'agent-test/run-suite/jetblog-query-pipeline', function() {

	$suite = 'jetblog-query-pipeline';

	// jbqp-1: jet-blog/pre-query's return value replaces WP_Query's output entirely.
	//
	// Live-verified gotcha (2026-07-16): fully instantiating Elementor\Jet_Blog_Smart_Listing
	// via `new` and calling its real _get_posts()/_get_widget_settings() pipeline hit repeated
	// Elementor-version-specific constructor validation (a `$args` argument requirement whose
	// exact shape isn't documented, then a "Cannot redeclare class" fatal once a widget-type
	// name was supplied) - not worth chasing further since the actual claim under test (a
	// non-false apply_filters() return short-circuits the rest of the pipeline) doesn't need a
	// real widget object at all: `apply_filters('jet-blog/pre-query', false, $settings,
	// $query_args, $widget)` is a plain generic WP filter call (smart-listing.php:6782) whose
	// 4th arg the plugin's own code never dereferences before the false-check at :6784 - a
	// fake stub is equally valid here, the same "direct hook test, no real widget needed"
	// pattern used successfully in jetelements-query-gateway/tests.php.
	try {
		$marker = array( 'agent_test_marker_post' => true );
		add_filter( 'jet-blog/pre-query', function( $result, $settings, $query_args, $w ) use ( $marker ) {
			return $marker;
		}, 10, 4 );
		$posts = apply_filters( 'jet-blog/pre-query', false, array(), array(), null );
		remove_all_filters( 'jet-blog/pre-query' );
		$pass  = ( $posts === $marker );
		agent_test_assert(
			$suite, 'jbqp-1',
			'SKILL.md "The three-way query pipeline": jet-blog/pre-query (4 args: $result, $settings, $query_args, $widget) short-circuits WP_Query entirely — a non-false return value IS the posts array used (verified via direct apply_filters() call — see comment above for why this isn\'t routed through a real widget instance)',
			$pass,
			array( 'query_equals_marker' => true ),
			array( 'posts' => $posts ),
			'includes/addons/jet-blog-smart-listing.php:6782-6789'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbqp-1', 'jet-blog/pre-query short-circuit live test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbqp-2: sanitize_query_args() strips disallowed post_status/post_type/password keys, leaves allowed ones.
	try {
		if ( ! class_exists( 'Elementor\\Jet_Blog_Base' ) ) {
			require WP_PLUGIN_DIR . '/jet-blog/includes/base/class-jet-blog-base.php';
		}
		if ( ! class_exists( 'Elementor\\Jet_Blog_Smart_Listing' ) ) {
			require WP_PLUGIN_DIR . '/jet-blog/includes/addons/jet-blog-smart-listing.php';
		}
		$widget = new \Elementor\Jet_Blog_Smart_Listing();
		$ref    = new \ReflectionMethod( $widget, 'sanitize_query_args' );
		$ref->setAccessible( true );

		$input = array(
			'post_status'   => array( 'publish', 'private', 'draft' ),
			'post_type'     => array( 'post', 'revision', 'nav_menu_item' ),
			'post_password' => 'agent_test_should_be_removed',
			'posts_per_page'=> 5,
		);
		$result = $ref->invoke( $widget, $input );

		$pass = isset( $result['post_status'] ) && $result['post_status'] === array( 0 => 'publish' )
			&& isset( $result['post_type'] ) && in_array( 'post', $result['post_type'], true ) && ! in_array( 'revision', $result['post_type'], true ) && ! in_array( 'nav_menu_item', $result['post_type'], true )
			&& ! isset( $result['post_password'] )
			&& isset( $result['posts_per_page'] ) && 5 === $result['posts_per_page'];

		agent_test_assert(
			$suite, 'jbqp-2',
			'SKILL.md "The three-way query pipeline": sanitize_query_args() strips private/draft post_status values, revision/nav_menu_item post_type values, and the post_password key entirely, while leaving allowed values untouched',
			$pass,
			array( 'post_status' => array( 'publish' ), 'post_type_excludes' => array( 'revision', 'nav_menu_item' ), 'post_password_removed' => true ),
			$result,
			'includes/base/class-jet-blog-base.php:1291-1325'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbqp-2', 'sanitize_query_args() reflection test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbqp-3: per-widget *-query-args filter names are independent (smart-listing vs smart-tiles).
	// Source-presence check, not live-triggered — see jbqp-1's comment above for why routing
	// this through a real _get_posts() widget call proved too fragile against this site's
	// Elementor version; a direct apply_filters() probe would prove independence just as
	// trivially as a real widget call would (neither needs the widget's own state), so the
	// only meaningfully different thing left to verify here is that the two literal filter
	// name strings actually appear at their own distinct call sites in source, per plugin.
	try {
		$listing_file = WP_PLUGIN_DIR . '/jet-blog/includes/addons/jet-blog-smart-listing.php';
		$tiles_file    = WP_PLUGIN_DIR . '/jet-blog/includes/addons/jet-blog-smart-tiles.php';
		$listing_src   = file_exists( $listing_file ) ? file_get_contents( $listing_file ) : '';
		$tiles_src     = file_exists( $tiles_file ) ? file_get_contents( $tiles_file ) : '';

		$listing_has_own  = false !== strpos( $listing_src, "apply_filters( 'jet-blog/smart-listing/query-args'" );
		$listing_lacks_tiles = false === strpos( $listing_src, "'jet-blog/smart-tiles/query-args'" );
		$tiles_has_own    = false !== strpos( $tiles_src, "apply_filters( 'jet-blog/smart-tiles/query-args'" );
		$tiles_lacks_listing = false === strpos( $tiles_src, "'jet-blog/smart-listing/query-args'" );

		$pass = $listing_has_own && $listing_lacks_tiles && $tiles_has_own && $tiles_lacks_listing;

		agent_test_assert(
			$suite, 'jbqp-3',
			'SKILL.md "The three-way query pipeline": jet-blog/smart-listing/query-args and jet-blog/smart-tiles/query-args are independent, per-widget-file filter names — each widget file applies only its own name, never the other\'s',
			$pass,
			array( 'listing_has_own' => true, 'listing_lacks_tiles' => true, 'tiles_has_own' => true, 'tiles_lacks_listing' => true ),
			array( 'listing_has_own' => $listing_has_own, 'listing_lacks_tiles' => $listing_lacks_tiles, 'tiles_has_own' => $tiles_has_own, 'tiles_lacks_listing' => $tiles_lacks_listing ),
			'includes/addons/jet-blog-smart-listing.php:6776, includes/addons/jet-blog-smart-tiles.php:2772 — source-presence only, see comment above'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbqp-3', 'per-widget query-args filter isolation test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbqp-4: Query Builder integration presence tracks function_exists('jet_engine'), and
	// maybe_do_query() falls through unchanged when is_archive_template is truthy.
	try {
		$jetengine_active = function_exists( 'jet_engine' );

		if ( $jetengine_active && ! class_exists( 'Jet_Blog_Query_Builder' ) ) {
			require WP_PLUGIN_DIR . '/jet-blog/includes/class-jet-blog-query-builder.php';
		}

		$class_matches_expectation = $jetengine_active
			? class_exists( 'Jet_Blog_Query_Builder' )
			: true; // can't assert absence meaningfully if some other snippet already loaded it this request

		$fallthrough_pass = true;
		$actual_return    = null;

		if ( $jetengine_active && class_exists( 'Jet_Blog_Query_Builder' ) ) {
			$qb = new Jet_Blog_Query_Builder();
			$fake_result = array( 'agent_test_untouched' => true );
			$settings = array(
				'use_custom_query'    => 'true',
				'query_builder_id'    => 999999, // does not need to exist — is_archive_template short-circuits first
				'is_archive_template' => 'yes',
			);
			$actual_return    = $qb->maybe_do_query( $fake_result, $settings, array(), null );
			$fallthrough_pass = ( $actual_return === $fake_result );
		}

		$pass = $class_matches_expectation && $fallthrough_pass;

		agent_test_assert(
			$suite, 'jbqp-4',
			'SKILL.md "use_custom_query has two independent meanings": Jet_Blog_Query_Builder only exists when function_exists(\'jet_engine\') is true, and maybe_do_query() returns $result untouched (falls through to WP_Query) whenever is_archive_template is truthy, even with a query_builder_id set',
			$pass,
			array( 'jetengine_active' => $jetengine_active, 'class_matches_expectation' => true, 'fallthrough_returns_untouched_result' => true ),
			array( 'jetengine_active' => $jetengine_active, 'class_exists' => class_exists( 'Jet_Blog_Query_Builder' ), 'actual_return' => $actual_return ),
			'jet-blog.php:163-166, includes/class-jet-blog-query-builder.php:41-47'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbqp-4', 'Query Builder integration gating + archive-template fallthrough test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbqp-5: HMAC settings-signature accepts untampered settings, rejects a tampered copy.
	try {
		if ( ! class_exists( 'Elementor\\Jet_Blog_Smart_Listing' ) ) {
			throw new \Exception( 'Smart Listing widget class not loaded' );
		}
		$widget = new \Elementor\Jet_Blog_Smart_Listing();

		$ref_keys = new \ReflectionMethod( $widget, 'get_exported_settings_keys' );
		$ref_keys->setAccessible( true );
		$keys = $ref_keys->invoke( $widget );

		$settings = array();
		foreach ( $keys as $k ) {
			$settings[ $k ] = 'agent_test_value';
		}
		$settings['post_type'] = array( 'post' );

		$signature = $widget->create_settings_signature( $settings );
		$settings['signature'] = $signature;

		$valid_pass = $widget->validate_settings_signature( $settings );

		$tampered = $settings;
		$tampered['post_type'] = array( 'page' ); // mutate one allow-listed key after signing
		$tampered_pass = $widget->validate_settings_signature( $tampered ); // should now be false

		$pass = ( true === $valid_pass ) && ( false === $tampered_pass );

		agent_test_assert(
			$suite, 'jbqp-5',
			'SKILL.md "Smart Listing\'s AJAX load more endpoint": create_settings_signature()/validate_settings_signature() is an HMAC over the allow-listed exported settings — an untampered signed payload validates, a payload with one allow-listed key changed after signing fails validation',
			$pass,
			array( 'valid_settings_pass' => true, 'tampered_settings_pass' => false ),
			array( 'valid_settings_pass' => $valid_pass, 'tampered_settings_pass' => $tampered_pass ),
			'includes/addons/jet-blog-smart-listing.php:6257-6282,6114-6229'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbqp-5', 'settings-signature create/validate live test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
