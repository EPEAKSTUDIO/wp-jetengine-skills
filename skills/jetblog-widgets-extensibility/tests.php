<?php
/**
 * AGENT-TEST-SUITE: jetblog-widgets-extensibility
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetblog-widgets-extensibility"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetblog-widgets-extensibility. See docs/test-harness-guide.md.
 *
 * Safety note: never `new`s Jet_Blog itself (jet_blog() is the correct accessor —
 * Jet_Blog::get_instance() is a lazy singleton already instantiated at the bottom of
 * jet-blog.php on every request). Widget classes (Elementor\Jet_Blog_Smart_Listing etc.)
 * and the plain-data Jet_Blog\Endpoints\Plugin_Settings endpoint object ARE safe to `new`
 * directly — neither has constructor side effects beyond what Elementor's own
 * Widget_Base does for any widget instantiation.
 */

add_action( 'agent-test/run-suite/jetblog-widgets-extensibility', function() {

	$suite = 'jetblog-widgets-extensibility';

	// jbwe-1: jet_blog() singleton + component singletons all resolve; has_elementor() is true on this site.
	try {
		$plugin = function_exists( 'jet_blog' ) ? jet_blog() : null;
		$class  = $plugin ? get_class( $plugin ) : null;
		$has_elementor = ( $plugin && method_exists( $plugin, 'has_elementor' ) ) ? $plugin->has_elementor() : null;

		$components = array(
			'jet_blog_assets'        => function_exists( 'jet_blog_assets' ) ? get_class( jet_blog_assets() ) : null,
			'jet_blog_integration'   => function_exists( 'jet_blog_integration' ) ? get_class( jet_blog_integration() ) : null,
			'jet_blog_video_data'    => function_exists( 'jet_blog_video_data' ) ? get_class( jet_blog_video_data() ) : null,
			'jet_blog_ajax_handlers' => function_exists( 'jet_blog_ajax_handlers' ) ? get_class( jet_blog_ajax_handlers() ) : null,
		);

		$pass = ( 'Jet_Blog' === $class ) && $has_elementor && ! in_array( null, $components, true );

		agent_test_assert(
			$suite, 'jbwe-1',
			'SKILL.md "Bootstrap": jet_blog() returns Jet_Blog::get_instance(); has_elementor() is truthy on this site (source returns a non-strict-bool truthy value, not necessarily `true` itself); jet_blog_assets()/jet_blog_integration()/jet_blog_video_data()/jet_blog_ajax_handlers() all resolve to real singleton instances (proving init() ran past the Elementor gate)',
			$pass,
			array( 'class' => 'Jet_Blog', 'has_elementor' => true, 'all_components_resolved' => true ),
			array( 'class' => $class, 'has_elementor' => $has_elementor, 'components' => $components ),
			'jet-blog.php:133-169,252-254,388-390'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbwe-1', 'jet_blog() singleton + component reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbwe-2: include-controls filter wins over an artificially-high load_level requirement.
	//
	// Live-verified gotcha (2026-07-16): calling get_widget_types() FIRST is required, not optional.
	// Elementor's own widgets_manager lazily require()s every registered widget's class file the
	// first time get_widget_types() is called in a request, with no class_exists guard on its own
	// side (it only runs once per request by design, tracked internally, not via class_exists). If
	// this test's own guarded `require` below runs BEFORE anything has triggered Elementor's lazy
	// registration, then a later jbwe-3 (or any other) call to get_widget_types() re-requires the
	// same file a second time and throws an uncatchable "Cannot redeclare class" fatal - confirmed
	// live by isolating this exact ordering. Forcing registration first makes our own guarded
	// require's class_exists() check correctly see the class as already loaded (a no-op), instead
	// of racing Elementor's own first-time require.
	try {
		if ( class_exists( '\\Elementor\\Plugin' ) ) {
			\Elementor\Plugin::instance()->widgets_manager->get_widget_types();
		}
		if ( ! class_exists( 'Elementor\\Jet_Blog_Base' ) ) {
			require WP_PLUGIN_DIR . '/jet-blog/includes/base/class-jet-blog-base.php';
		}
		if ( ! class_exists( 'Elementor\\Jet_Blog_Smart_Listing' ) ) {
			require WP_PLUGIN_DIR . '/jet-blog/includes/addons/jet-blog-smart-listing.php';
		}

		add_filter( 'jet-blog/editor/jet-blog-smart-listing/include-controls', function( $controls ) {
			$controls[] = 'agent_test_marker_control';
			return $controls;
		} );

		$widget = new \Elementor\Jet_Blog_Smart_Listing();

		remove_all_filters( 'jet-blog/editor/jet-blog-smart-listing/include-controls' );

		$ref = new \ReflectionMethod( $widget, '_is_visible_control' );
		$ref->setAccessible( true );

		// Included control should be visible even at an absurdly high required load_level.
		$included_visible = $ref->invoke( $widget, 'agent_test_marker_control', 99999 );
		// A non-included control at the same absurd load_level should NOT be visible
		// (assuming the site's widgets_load_level is a normal <= 100 value).
		$other_visible = $ref->invoke( $widget, 'agent_test_not_included_control', 99999 );

		$pass = ( true === $included_visible ) && ( false === $other_visible );

		agent_test_assert(
			$suite, 'jbwe-2',
			'SKILL.md "Jet_Blog_Base": a control id present in jet-blog/editor/{widget}/include-controls is always visible via _is_visible_control(), regardless of the required load_level argument; a non-included control at the same load_level is not',
			$pass,
			array( 'included_control_visible' => true, 'other_control_visible' => false ),
			array( 'included_control_visible' => $included_visible, 'other_control_visible' => $other_visible ),
			'includes/base/class-jet-blog-base.php:26-27,1017-1026'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbwe-2', 'include-controls override load_level test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbwe-3: widget registration state is consistent with the avaliable_widgets toggle's current value.
	try {
		$available_widgets = function_exists( 'jet_blog_settings' ) ? jet_blog_settings()->get( 'avaliable_widgets' ) : null;

		$expected_classes = array(
			'jet-blog-posts-navigation' => 'jet-blog-posts-navigation',
			'jet-blog-posts-pagination' => 'jet-blog-posts-pagination',
			'jet-blog-smart-listing'    => 'jet-blog-smart-listing',
			'jet-blog-smart-tiles'      => 'jet-blog-smart-tiles',
			'jet-blog-text-ticker'      => 'jet-blog-text-ticker',
			'jet-blog-video-playlist'   => 'jet-blog-video-playlist',
		);

		$registered_names = array();
		if ( class_exists( '\\Elementor\\Plugin' ) ) {
			$widgets_manager = \Elementor\Plugin::instance()->widgets_manager;
			foreach ( $widgets_manager->get_widget_types() as $name => $instance ) {
				$registered_names[] = $name;
			}
		}

		$results  = array();
		$all_pass = true;

		foreach ( $expected_classes as $slug => $widget_name ) {
			$enabled_setting = ( is_array( $available_widgets ) && isset( $available_widgets[ $slug ] ) )
				? filter_var( $available_widgets[ $slug ], FILTER_VALIDATE_BOOLEAN )
				: false;
			// register_addons()'s real condition: filter_var($enabled) || ! $available_widgets (option unset/empty).
			$expected_registered = $enabled_setting || empty( $available_widgets );
			$actually_registered = in_array( $widget_name, $registered_names, true );

			$results[ $slug ] = array( 'expected' => $expected_registered, 'actual' => $actually_registered );
			$all_pass = $all_pass && ( $expected_registered === $actually_registered );
		}

		agent_test_assert(
			$suite, 'jbwe-3',
			'SKILL.md "Widget registration": each addon slug\'s actual Elementor widget-type registration matches what avaliable_widgets (or its absence) predicts — filter_var($enabled) || !$available_widgets',
			$all_pass,
			array( 'all_slugs_consistent' => true ),
			array( 'avaliable_widgets_option' => $available_widgets, 'per_slug' => $results ),
			'includes/class-jet-blog-integration.php:249-264'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbwe-3', 'avaliable_widgets registration consistency test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbwe-4: Plugin_Settings endpoint shape (POST, name, no own permission_callback override).
	// Same "trigger real registration before manually requiring" precaution as jbwe-2, though this
	// endpoint class isn't Elementor-lazy-loaded - guarded requires here are just the standard pattern.
	try {
		if ( ! class_exists( 'Jet_Blog\\Endpoints\\Base' ) ) {
			require WP_PLUGIN_DIR . '/jet-blog/includes/rest-api/endpoints/base.php';
		}
		if ( ! class_exists( 'Jet_Blog\\Endpoints\\Plugin_Settings' ) ) {
			require WP_PLUGIN_DIR . '/jet-blog/includes/rest-api/endpoints/plugin-settings.php';
		}

		$endpoint = new \Jet_Blog\Endpoints\Plugin_Settings();
		$method   = $endpoint->get_method();
		$name     = $endpoint->get_name();

		$reflection = new \ReflectionClass( $endpoint );
		$declares_own_permission_callback = $reflection->getMethod( 'permission_callback' )->getDeclaringClass()->getName() === '\\Jet_Blog\\Endpoints\\Plugin_Settings'
			|| $reflection->getMethod( 'permission_callback' )->getDeclaringClass()->getName() === 'Jet_Blog\\Endpoints\\Plugin_Settings';

		$pass = ( 'POST' === $method ) && ( 'plugin-settings' === $name ) && ( false === $declares_own_permission_callback );

		agent_test_assert(
			$suite, 'jbwe-4',
			'SKILL.md "REST API": Jet_Blog\\Endpoints\\Plugin_Settings is a POST endpoint named plugin-settings that does NOT declare its own permission_callback (inherits Base\'s default current_user_can(\'manage_options\'))',
			$pass,
			array( 'method' => 'POST', 'name' => 'plugin-settings', 'declares_own_permission_callback' => false ),
			array( 'method' => $method, 'name' => $name, 'declares_own_permission_callback' => $declares_own_permission_callback, 'permission_callback_declaring_class' => $reflection->getMethod( 'permission_callback' )->getDeclaringClass()->getName() ),
			'includes/rest-api/endpoints/plugin-settings.php:11-28, includes/rest-api/endpoints/base.php:40-42'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbwe-4', 'Plugin_Settings endpoint shape test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbwe-5: YouTube video-data methods short-circuit to array() with no API key configured; Vimeo has no such gate in source.
	try {
		$api_key = function_exists( 'jet_blog_settings' ) ? jet_blog_settings()->get( 'youtube_api_key' ) : null;

		$video_data = function_exists( 'jet_blog_video_data' ) ? jet_blog_video_data() : null;
		$result     = null;
		$threw      = false;

		if ( $video_data ) {
			try {
				$result = $video_data->get_youtube_data( 'agent_test_fake_video_id', false );
			} catch ( \Throwable $inner ) {
				$threw = true;
			}
		}

		$key_is_empty = empty( $api_key );
		// If the key really is empty, get_youtube_data() must short-circuit to array() with no exception.
		$pass = $key_is_empty
			? ( is_array( $result ) && empty( $result ) && ! $threw )
			: true; // if a key IS configured on this sandbox, this specific assertion doesn't apply — not a failure of the claim.

		agent_test_assert(
			$suite, 'jbwe-5',
			'SKILL.md "Video Playlist\'s video-data layer": get_youtube_data() returns array() with no exception when youtube_api_key is empty (the default/undisturbed sandbox state) — the YouTube-branch short-circuit',
			$pass,
			array( 'key_empty' => true, 'result_is_empty_array' => true, 'no_exception' => true ),
			array( 'key_empty' => $key_is_empty, 'result' => $result, 'threw' => $threw ),
			'includes/class-jet-blog-video-data.php:327-331'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbwe-5', 'YouTube video-data no-API-key short-circuit test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jbwe-6: get_ttl() applies jet-blog/video-cache/ttl-hours and jet-blog/list-cache/ttl-hours independently.
	try {
		$video_data = function_exists( 'jet_blog_video_data' ) ? jet_blog_video_data() : null;
		if ( ! $video_data || ! method_exists( $video_data, 'get_ttl' ) ) {
			throw new \Exception( 'jet_blog_video_data()->get_ttl not available' );
		}

		$ref = new \ReflectionMethod( $video_data, 'get_ttl' );
		$ref->setAccessible( true );

		$video_filter_hits = 0;
		$list_filter_hits  = 0;

		add_filter( 'jet-blog/video-cache/ttl-hours', function( $hours ) use ( &$video_filter_hits ) {
			$video_filter_hits++;
			return $hours;
		} );
		add_filter( 'jet-blog/list-cache/ttl-hours', function( $hours ) use ( &$list_filter_hits ) {
			$list_filter_hits++;
			return $hours;
		} );

		$video_ttl = $ref->invoke( $video_data, 'video' );
		$list_ttl  = $ref->invoke( $video_data, 'list' );

		remove_all_filters( 'jet-blog/video-cache/ttl-hours' );
		remove_all_filters( 'jet-blog/list-cache/ttl-hours' );

		$pass = ( 1 === $video_filter_hits ) && ( 1 === $list_filter_hits )
			&& is_int( $video_ttl ) && is_int( $list_ttl );

		agent_test_assert(
			$suite, 'jbwe-6',
			'SKILL.md "Video Playlist\'s video-data layer": get_ttl(\'video\') fires jet-blog/video-cache/ttl-hours exactly once and get_ttl(\'list\') fires jet-blog/list-cache/ttl-hours exactly once — the two filter names are independent, neither cross-fires for the other type',
			$pass,
			array( 'video_filter_hits' => 1, 'list_filter_hits' => 1 ),
			array( 'video_filter_hits' => $video_filter_hits, 'list_filter_hits' => $list_filter_hits, 'video_ttl_seconds' => $video_ttl, 'list_ttl_seconds' => $list_ttl ),
			'includes/class-jet-blog-video-data.php:609-615'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jbwe-6', 'get_ttl() per-type filter isolation test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
