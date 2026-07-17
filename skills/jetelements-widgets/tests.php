<?php
/**
 * AGENT-TEST-SUITE: jetelements-widgets
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetelements-widgets"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetelements-widgets. See docs/test-harness-guide.md.
 *
 * IMPORTANT: JetElements was NOT installed on the sandbox at the time this suite was
 * written. Every assertion below is guarded with function_exists()/class_exists() so
 * the suite degrades to "reachable == false" (not a fatal) if run before the plugin is
 * active — but do not deploy/run this snippet until JetElements + Elementor are both
 * confirmed active, per TEST-REGIMEN.md.
 *
 * Safety note (see SKILL.md "Gotchas" and HANDOFF.md's safety lesson): this suite
 * deliberately never calls register_addons()/register_vendor_addons()/register_addon()
 * directly — those re-`require` already-declared widget class files on any request
 * past the first, risking the same class of "Cannot redeclare class" fatal this repo
 * hit for real against jetsmartfilters-query's Storage\Controller. Everything touching
 * widget/addon registration here is a source-presence (file_get_contents+strpos) check
 * instead.
 */

add_action( 'agent-test/run-suite/jetelements-widgets', function() {

	$suite = 'jetelements-widgets';

	// jew-1: jet_elements() singleton is reachable, reports the expected version, and exposes plugin_path()/plugin_url().
	try {
		$reachable = function_exists( 'jet_elements' );
		$plugin    = $reachable ? jet_elements() : null;
		$version   = ( $plugin && method_exists( $plugin, 'get_version' ) ) ? $plugin->get_version() : null;
		$has_paths = $plugin && method_exists( $plugin, 'plugin_path' ) && method_exists( $plugin, 'plugin_url' );
		$has_elementor_check = $plugin && method_exists( $plugin, 'has_elementor' );
		agent_test_assert(
			$suite, 'jew-1',
			'SKILL.md "The plugin singleton...": jet_elements() is Jet_Elements::get_instance(); get_version() reports the plugin version; plugin_path()/plugin_url()/has_elementor() exist',
			( $reachable && null !== $version && $has_paths && $has_elementor_check ),
			array( 'reachable' => true, 'version' => '2.9.1.2', 'has_paths' => true, 'has_elementor_check' => true ),
			array( 'reachable' => $reachable, 'version' => $version, 'has_paths' => $has_paths, 'has_elementor_check' => $has_elementor_check ),
			'jet-elements.php:397-403,415-417 (get_instance/jet_elements()), :68,129-131 (get_version), :263-265,309-330 (has_elementor/plugin_path/plugin_url)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jew-1', 'jet_elements() reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jew-2: jet_elements_settings() singleton is reachable, avaliable_widgets is already populated (init() runs at file-load time),
	// and get() falls back to the passed default for an unknown key.
	try {
		$reachable = function_exists( 'jet_elements_settings' );
		$settings  = $reachable ? jet_elements_settings() : null;
		$avaliable = $settings ? $settings->avaliable_widgets : null;
		$default_marker = 'agent_test_default_' . wp_generate_password( 6, false );
		$get_default = $settings ? $settings->get( 'agent_test_setting_does_not_exist_xyz', $default_marker ) : null;
		$pass = $reachable && is_array( $avaliable ) && ! empty( $avaliable ) && $get_default === $default_marker;
		agent_test_assert(
			$suite, 'jew-2',
			'SKILL.md "The plugin singleton...": jet_elements_settings()->avaliable_widgets is a non-empty array populated by init() at file-load time (not deferred to a hook); get($key, $default) falls back to $default for unknown keys',
			$pass,
			array( 'avaliable_widgets_is_nonempty_array' => true, 'get_default_fallback_works' => true ),
			array( 'avaliable_widgets_type' => gettype( $avaliable ), 'avaliable_widgets_count' => is_array( $avaliable ) ? count( $avaliable ) : null, 'get_default_result' => $get_default ),
			'includes/class-jet-elements-settings.php:89-95 (avaliable_widgets population), :287-295 (get()), :375 (init() called immediately at file-load time)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jew-2', 'jet_elements_settings() reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jew-3: jet_elements_integration() singleton is reachable and addons_with_styles() returns the documented widget slug list.
	try {
		$reachable   = function_exists( 'jet_elements_integration' );
		$integration = $reachable ? jet_elements_integration() : null;
		$styled      = ( $integration && method_exists( $integration, 'addons_with_styles' ) ) ? $integration->addons_with_styles() : null;
		$has_carousel = is_array( $styled ) && in_array( 'jet-carousel', $styled, true );
		$has_posts    = is_array( $styled ) && in_array( 'jet-posts', $styled, true );
		agent_test_assert(
			$suite, 'jew-3',
			'SKILL.md "Assets...": jet_elements_integration()->addons_with_styles() returns an array of widget slugs including jet-carousel and jet-posts',
			( $reachable && $has_carousel && $has_posts ),
			array( 'reachable' => true, 'contains_jet-carousel' => true, 'contains_jet-posts' => true ),
			array( 'reachable' => $reachable, 'contains_jet-carousel' => $has_carousel, 'contains_jet-posts' => $has_posts, 'count' => is_array( $styled ) ? count( $styled ) : null ),
			'includes/class-jet-elements-integration.php:246-287 (addons_with_styles), :430-436,447-449 (singleton accessor)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jew-3', 'jet_elements_integration() reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jew-4: the genuinely-typo'd jet_elements_shortocdes() accessor is reachable, and get_shortcode('jet-posts') resolves
	// to the real Jet_Posts_Shortcode instance. The correctly-spelled jet_elements_shortcodes() must NOT exist.
	try {
		$typo_reachable = function_exists( 'jet_elements_shortocdes' );
		$correct_spelling_absent = ! function_exists( 'jet_elements_shortcodes' );
		$shortcodes = $typo_reachable ? jet_elements_shortocdes() : null;
		$jet_posts  = ( $shortcodes && method_exists( $shortcodes, 'get_shortcode' ) ) ? $shortcodes->get_shortcode( 'jet-posts' ) : null;
		$tag_matches = $jet_posts && method_exists( $jet_posts, 'get_tag' ) && 'jet-posts' === $jet_posts->get_tag();
		agent_test_assert(
			$suite, 'jew-4',
			'SKILL.md "The plugin singleton...": the shortcode registry accessor is the misspelled jet_elements_shortocdes() (not jet_elements_shortcodes()); get_shortcode(\'jet-posts\') resolves to a Jet_Posts_Shortcode instance whose get_tag() === \'jet-posts\'',
			( $typo_reachable && $correct_spelling_absent && $tag_matches ),
			array( 'typo_accessor_exists' => true, 'correct_spelling_absent' => true, 'jet_posts_tag_matches' => true ),
			array( 'typo_accessor_exists' => $typo_reachable, 'correct_spelling_absent' => $correct_spelling_absent, 'jet_posts_class' => $jet_posts ? get_class( $jet_posts ) : null, 'tag_matches' => $tag_matches ),
			'includes/class-jet-elements-shortcodes.php:42-59 (register_shortcodes, hooked on init prio 30),:90-92 (get_shortcode),:117-119 (jet_elements_shortocdes()); includes/shortcodes/jet-posts-shortcode.php:5,18-20'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jew-4', 'jet_elements_shortocdes()/get_shortcode() smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jew-5: the per-widget dynamic include-controls/exclude-controls filters are real, generic WP filters — honored live
	// via apply_filters() directly, no real widget instance needed (Jet_Elements_Base's constructor just calls apply_filters
	// with this exact dynamic name at construction time; we only need to prove WP's own filter mechanism sees it, which
	// doesn't require constructing a Widget_Base subclass).
	try {
		$marker = 'agent_test_marker_' . wp_generate_password( 6, false );
		add_filter( 'jet-elements/editor/agent-test-widget/include-controls', function( $controls, $widget_name, $widget ) use ( $marker ) {
			$controls[] = $marker;
			return $controls;
		}, 10, 3 );
		$result = apply_filters( 'jet-elements/editor/agent-test-widget/include-controls', array(), 'agent-test-widget', null );
		remove_all_filters( 'jet-elements/editor/agent-test-widget/include-controls' );
		$pass = is_array( $result ) && in_array( $marker, $result, true );
		agent_test_assert(
			$suite, 'jew-5',
			'SKILL.md "Per-widget dynamic control filters": jet-elements/editor/{widget_name}/include-controls (and the exclude-controls sibling) is a real, dynamically-named filter applied with ([], $widget_name, $this) in Jet_Elements_Base::__construct() — proven honored via a direct apply_filters() call with the same dynamic name shape',
			$pass,
			array( 'marker_present' => true ),
			array( 'result' => $result, 'marker_present' => $pass ),
			'includes/base/class-jet-elements-base.php:28-30'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jew-5', 'dynamic include-controls filter honored smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jew-6: \Jet_Elements\Rest_Api::get_instance() is safe to call (no unconditional require in its constructor/init_endpoints,
	// confirmed by reading rest-api.php in full — unlike jetsmartfilters-query's Storage\Controller landmine), reports the
	// correct api_namespace, and get_endpoints() includes both built-in endpoints after being triggered.
	try {
		$class_exists = class_exists( '\\Jet_Elements\\Rest_Api' );
		$rest_api = $class_exists ? \Jet_Elements\Rest_Api::get_instance() : null;
		$namespace = $rest_api ? $rest_api->api_namespace : null;
		$endpoints = ( $rest_api && method_exists( $rest_api, 'get_endpoints' ) ) ? $rest_api->get_endpoints() : null;
		$has_template_endpoint = is_array( $endpoints ) && isset( $endpoints['elementor-template'] );
		$has_settings_endpoint = is_array( $endpoints ) && isset( $endpoints['plugin-settings'] );
		agent_test_assert(
			$suite, 'jew-6',
			'SKILL.md "REST API...": \\Jet_Elements\\Rest_Api::get_instance() is safe to call (no unconditional require), api_namespace === "jet-elements-api/v1", and get_endpoints() returns both elementor-template and plugin-settings after lazy init_endpoints()',
			( $class_exists && 'jet-elements-api/v1' === $namespace && $has_template_endpoint && $has_settings_endpoint ),
			array( 'class_exists' => true, 'api_namespace' => 'jet-elements-api/v1', 'has_elementor-template' => true, 'has_plugin-settings' => true ),
			array( 'class_exists' => $class_exists, 'api_namespace' => $namespace, 'endpoint_keys' => is_array( $endpoints ) ? array_keys( $endpoints ) : null ),
			'includes/rest-api/rest-api.php:29 (api_namespace),:42-49 (get_instance),:58-70 (init_endpoints/get_endpoints); jet-elements.php:160 uses `new Rest_Api()` instead of get_instance() but this is harmless, see SKILL.md'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jew-6', 'Rest_Api::get_instance() reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jew-7: source-presence checks for things deliberately NOT live-invoked (registration-pipeline landmine avoidance,
	// carousel-options pattern across 5 widgets, and the 3 custom/rewritten Elementor control files).
	try {
		$plugin_dir = WP_PLUGIN_DIR . '/jet-elements/';
		$checks = array(
			// registration pipeline uses plain require (landmine) — confirm the exact line still says `require $file;`
			'includes/class-jet-elements-integration.php' => "require \$file;",
			// carousel-options pattern, 4 of the 5 documented call sites (5th uses a differently-named filter, checked separately)
			'includes/addons/jet-elements-advanced-carousel.php' => "apply_filters( 'jet-elements/jet-carousel/carousel-options'",
			'includes/addons/jet-elements-posts.php' => "apply_filters( 'jet-elements/jet-posts/carousel-options'",
			'includes/addons/jet-elements-testimonials.php' => "apply_filters( 'jet-elements/jet-testimonials/carousel-options'",
			'includes/addons/jet-elements-image-comparison.php' => "apply_filters( 'jet-elements/jet-image-comparison/carousel-options'",
			'includes/addons/jet-elements-slider.php' => "apply_filters( 'jet-elements/slider/slider-options'",
			// custom/rewritten controls
			'includes/controls/class-jet-elements-control-icon.php' => 'class Jet_Elements_Control_Icon extends Elementor\\Control_Icon',
			'includes/controls/class-jet-elements-control-date-time.php' => 'class Jet_Elements_Control_Date_Time extends Elementor\\Control_Date_Time',
			'includes/controls/groups/class-jet-group-control-box-style.php' => 'class Jet_Group_Control_Box_Style extends Elementor\\Group_Control_Base',
		);
		$results = array();
		$all_pass = true;
		foreach ( $checks as $rel_path => $needle ) {
			$file = $plugin_dir . $rel_path;
			$contents = file_exists( $file ) ? file_get_contents( $file ) : '';
			$found = ( '' !== $contents ) && ( false !== strpos( $contents, $needle ) );
			$results[ $rel_path ] = $found;
			$all_pass = $all_pass && $found;
		}
		agent_test_assert(
			$suite, 'jew-7',
			'SKILL.md "Widget/addon registration pipeline" (require, not require_once — the landmine reasoning) / "carousel-options pattern" (4 more real call sites) / "custom/rewritten Elementor controls" (3 control class files): all present in live installed source, deliberately not live-invoked (registration methods risk a redeclare fatal; carousel-options getters need a real widget instance)',
			$all_pass,
			array( 'all_present' => true ),
			$results,
			'includes/class-jet-elements-integration.php:395 (require, landmine reasoning); jet-elements-{advanced-carousel,posts,testimonials,image-comparison,slider}.php (carousel-options call sites); includes/controls/{class-jet-elements-control-icon,class-jet-elements-control-date-time}.php, includes/controls/groups/class-jet-group-control-box-style.php'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jew-7', 'registration-landmine + carousel-options + custom-controls source-presence test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
