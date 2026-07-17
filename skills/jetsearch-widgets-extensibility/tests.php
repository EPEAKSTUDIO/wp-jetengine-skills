<?php
/**
 * Runnable test suite for jetsearch-widgets-extensibility.
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetsearch-widgets-extensibility"),
 * depends on the always-active "AGENT-TEST-CORE harness" snippet for agent_test_assert()/
 * agent_test_run_suite(). See docs/test-harness-guide.md.
 *
 * Bricks Builder is NOT installed on the sandbox this suite runs against — every
 * Bricks-specific claim in SKILL.md is source-verified only, not covered here (see
 * TEST-REGIMEN.md). Elementor widget registration is confirmed via
 * \Elementor\Plugin::instance()->widgets_manager->get_widget_types() (forcing Elementor's
 * lazy widget-type load first), not by manually `new`-ing a widget instance. Macro/filter
 * mechanisms are tested by calling apply_filters()/do_action() directly.
 */

add_action( 'agent-test/run-suite/jetsearch-widgets-extensibility', function() {

	$suite = 'jetsearch-widgets-extensibility';

	// jsw-1: jet_search() singleton resolves; has_elementor() is true on this sandbox;
	// the Elementor integration singleton resolved too (proving init() ran past its gate).
	try {
		$js_exists   = function_exists( 'jet_search' );
		$instance    = $js_exists ? jet_search() : null;
		$has_el      = $js_exists && $instance ? (bool) $instance->has_elementor() : false;
		$integration = function_exists( 'jet_search_integration' ) ? jet_search_integration() : null;

		$pass = $js_exists
			&& is_a( $instance, 'Jet_Search' )
			&& true === $has_el
			&& is_a( $integration, 'Jet_Search_Integration' );

		agent_test_assert(
			$suite, 'jsw-1',
			'jet_search() resolves to Jet_Search; has_elementor() true; jet_search_integration() resolved (init() ran past the Elementor gate)',
			$pass,
			'Jet_Search instance, has_elementor()=true, Jet_Search_Integration instance',
			array(
				'class'          => $js_exists ? get_class( $instance ) : null,
				'has_elementor'  => $has_el,
				'integration'    => $integration ? get_class( $integration ) : null,
			),
			'jet-search.php:183-190,218-220; elementor-views/integration.php:195-202'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsw-1', 'jet_search()/has_elementor()/jet_search_integration() bootstrap', false, 'no exception', $e->getMessage(), 'jet-search.php:183-220' );
	}

	// jsw-2: both Elementor widgets are actually registered with Elementor's widgets manager.
	// Force Elementor's own lazy widget-type load first (same fix documented in
	// jetblog-widgets-extensibility/TEST-REGIMEN.md) rather than manually instantiating a widget.
	try {
		$widgets_manager = \Elementor\Plugin::instance()->widgets_manager;
		$types           = $widgets_manager->get_widget_types(); // triggers lazy require of every registered widget's class file

		$has_ajax_search  = isset( $types['jet-ajax-search'] );
		$has_suggestions  = isset( $types['jet-search-suggestions'] );

		$pass = $has_ajax_search && $has_suggestions
			&& is_a( $types['jet-ajax-search'], 'Elementor\Jet_Search_Ajax_Search_Widget' )
			&& is_a( $types['jet-search-suggestions'], 'Elementor\Jet_Search_Search_Suggestions_Widget' );

		agent_test_assert(
			$suite, 'jsw-2',
			'Both Elementor widgets (jet-ajax-search, jet-search-suggestions) are registered with the real widget classes',
			$pass,
			"widget_types['jet-ajax-search'] instanceof Jet_Search_Ajax_Search_Widget, ['jet-search-suggestions'] instanceof Jet_Search_Search_Suggestions_Widget",
			array(
				'has_ajax_search' => $has_ajax_search,
				'has_suggestions' => $has_suggestions,
				'ajax_search_class' => $has_ajax_search ? get_class( $types['jet-ajax-search'] ) : null,
				'suggestions_class' => $has_suggestions ? get_class( $types['jet-search-suggestions'] ) : null,
			),
			'elementor-views/integration.php:79-111; widgets/ajax-search.php:24,33-35; widgets/search-suggestions.php:22,31-33'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsw-2', 'Elementor widget registration', false, 'both widgets registered', $e->getMessage(), 'elementor-views/integration.php:79-111' );
	}

	// jsw-3: the 'cherry' Elementor category is registered with the label "JetElements"
	// (a real, verified naming artifact, not a typo to "fix").
	try {
		$categories_manager = \Elementor\Plugin::instance()->elements_manager->get_categories();
		$has_cherry          = isset( $categories_manager['cherry'] );
		$label                = $has_cherry ? $categories_manager['cherry']['title'] : null;

		$pass = $has_cherry && 'JetElements' === $label;

		agent_test_assert(
			$suite, 'jsw-3',
			"The 'cherry' Elementor category both JetSearch widgets register under is literally labeled \"JetElements\"",
			$pass,
			"categories['cherry']['title'] === 'JetElements'",
			array( 'has_cherry' => $has_cherry, 'label' => $label ),
			'elementor-views/integration.php:61-71'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsw-3', "'cherry' category label", false, 'JetElements', $e->getMessage(), 'elementor-views/integration.php:61-71' );
	}

	// jsw-4: the jet-search-query custom Elementor control is registered.
	try {
		$controls_manager = \Elementor\Plugin::instance()->controls_manager;
		$control           = $controls_manager->get_control( 'jet-search-query' );
		$has_control       = ! empty( $control );

		// Note: Jet_Search_Control_Query is declared with NO `namespace Elementor;` line
		// in controls/query.php despite extending Elementor\Control_Select2 — it lives in
		// the global namespace, not Elementor\Jet_Search_Control_Query.
		$pass = $has_control && is_a( $control, 'Jet_Search_Control_Query' );

		agent_test_assert(
			$suite, 'jsw-4',
			'The jet-search-query custom control (extends Control_Select2) is registered with Elementor',
			$pass,
			'get_control(jet-search-query) instanceof Jet_Search_Control_Query',
			array( 'has_control' => $has_control, 'class' => $has_control ? get_class( $control ) : null ),
			'elementor-views/controls/query.php:5-10; elementor-views/integration.php:119-135'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsw-4', 'jet-search-query control registration', false, 'registered', $e->getMessage(), 'elementor-views/controls/query.php:5-10' );
	}

	// jsw-5: the jet-search/widget/loop-items filter mechanism works, and its call site is
	// really present in widget-base.php (source-presence + direct hook-mechanism check,
	// per this repo's "prefer testing the underlying apply_filters() call over live widget
	// instantiation" lesson rather than instantiating a real Widget_Base subclass).
	try {
		$base_file    = jet_search()->plugin_path( 'includes/elementor-views/base/widget-base.php' );
		$source       = file_exists( $base_file ) ? file_get_contents( $base_file ) : '';
		$source_has_it = false !== strpos( $source, "apply_filters( 'jet-search/widget/loop-items'" );

		$marker = 'agent_test_jsw5_marker';
		$cb     = function( $loop, $setting, $widget ) use ( $marker ) {
			$loop[] = $marker;
			return $loop;
		};
		add_filter( 'jet-search/widget/loop-items', $cb, 10, 3 );
		$result = apply_filters( 'jet-search/widget/loop-items', array(), 'some_setting', null );
		remove_filter( 'jet-search/widget/loop-items', $cb, 10 );

		$pass = $source_has_it && in_array( $marker, $result, true );

		agent_test_assert(
			$suite, 'jsw-5',
			'jet-search/widget/loop-items filter call site exists in widget-base.php and the hook mechanism works',
			$pass,
			'source contains the apply_filters call; filter callback result observed',
			array( 'source_has_it' => $source_has_it, 'filtered_result' => $result ),
			'elementor-views/base/widget-base.php:83-86'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsw-5', 'jet-search/widget/loop-items filter', false, 'no exception', $e->getMessage(), 'elementor-views/base/widget-base.php:86' );
	}

	// jsw-6: both Gutenberg blocks are registered regardless of which builder is active
	// (block registration is unconditional per jet-search.php:195,244).
	try {
		$registry     = \WP_Block_Type_Registry::get_instance();
		$has_ajax     = $registry->is_registered( 'jet-search/ajax-search' );
		$has_suggest  = $registry->is_registered( 'jet-search/search-suggestions' );

		$pass = $has_ajax && $has_suggest;

		agent_test_assert(
			$suite, 'jsw-6',
			'Both Gutenberg blocks (jet-search/ajax-search, jet-search/search-suggestions) are registered unconditionally',
			$pass,
			'WP_Block_Type_Registry has both blocks registered',
			array( 'has_ajax_search_block' => $has_ajax, 'has_suggestions_block' => $has_suggest ),
			'blocks-views/integration.php:87-139; jet-search.php:195,244'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsw-6', 'Gutenberg block registration', false, 'both blocks registered', $e->getMessage(), 'blocks-views/integration.php:125-138' );
	}

	// jsw-7: the jet-search/ajax-search/blocks-views/attributes filter mechanism works
	// (direct hook test, since re-triggering real block registration mid-request is unsafe).
	try {
		$marker = array( 'agent_test_jsw7_marker' => array( 'type' => 'string', 'default' => 'x' ) );
		$cb     = function( $attributes ) use ( $marker ) {
			return array_merge( $attributes, $marker );
		};
		add_filter( 'jet-search/ajax-search/blocks-views/attributes', $cb, 10, 1 );
		$result = apply_filters( 'jet-search/ajax-search/blocks-views/attributes', array( 'existing' => true ) );
		remove_filter( 'jet-search/ajax-search/blocks-views/attributes', $cb, 10 );

		$pass = isset( $result['agent_test_jsw7_marker'] ) && isset( $result['existing'] );

		agent_test_assert(
			$suite, 'jsw-7',
			'jet-search/ajax-search/blocks-views/attributes filter reshapes the whole attributes array',
			$pass,
			'filtered array contains both the original key and the injected marker key',
			$result,
			'blocks-views/integration.php:125-131'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsw-7', 'blocks-views/attributes filter', false, 'no exception', $e->getMessage(), 'blocks-views/integration.php:129' );
	}

	// jsw-8: Bricks integration is loaded (class exists, unconditional require) but gates
	// entirely inside init() since Bricks is not installed on this sandbox — its own
	// register_elements() never ran, so neither Bricks element class exists.
	try {
		$bricks_integration_exists = class_exists( 'Jet_Search_Bricks_Integration' );
		$has_bricks_defined        = defined( 'BRICKS_VERSION' );
		$bricks_instance           = function_exists( 'jet_search_bricks_integration' ) ? jet_search_bricks_integration() : null;
		$has_bricks_method         = $bricks_instance ? (bool) $bricks_instance->has_bricks() : null;
		$element_class_exists      = class_exists( 'Jet_Search\Bricks_Views\Elements\Jet_Search_Bricks_Ajax_Search' );

		$pass = $bricks_integration_exists
			&& false === $has_bricks_defined
			&& is_a( $bricks_instance, 'Jet_Search_Bricks_Integration' )
			&& false === $has_bricks_method
			&& false === $element_class_exists;

		agent_test_assert(
			$suite, 'jsw-8',
			'Jet_Search_Bricks_Integration is always loaded/constructed, but has_bricks() is false and register_elements() never ran on this Bricks-less sandbox',
			$pass,
			'class loaded, BRICKS_VERSION undefined, has_bricks()=false, Bricks element class NOT declared',
			array(
				'bricks_integration_exists' => $bricks_integration_exists,
				'bricks_version_defined'    => $has_bricks_defined,
				'has_bricks_method'         => $has_bricks_method,
				'element_class_exists'      => $element_class_exists,
			),
			'jet-search.php:196,260; bricks-views/integration.php:38-42,94-96 (source-verified gating, Bricks not installed on this sandbox)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsw-8', 'Bricks integration gating', false, 'gated cleanly, no fatal', $e->getMessage(), 'bricks-views/integration.php:38-42' );
	}

	// jsw-9: Search Sources manager holds the two built-in sources (terms, users) and is
	// reachable via jet_search()->search_sources (a plain property, not get_instance()).
	try {
		$manager = jet_search()->search_sources;
		$is_manager = is_a( $manager, 'Jet_Search\Search_Sources\Manager' );
		$sources    = $is_manager ? $manager->get_sources() : array();

		$pass = $is_manager && isset( $sources['terms'] ) && isset( $sources['users'] );

		agent_test_assert(
			$suite, 'jsw-9',
			'jet_search()->search_sources is a Search_Sources\Manager holding the terms/users built-in sources',
			$pass,
			"get_sources() has 'terms' and 'users' keys",
			array( 'is_manager' => $is_manager, 'source_keys' => array_keys( $sources ) ),
			'search-sources/manager.php:11,19-29; jet-search.php:201'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsw-9', 'Search Sources manager', false, 'terms + users registered', $e->getMessage(), 'search-sources/manager.php:19-29' );
	}

	// jsw-10: jet-search/sources/register action mechanism works (direct do_action test,
	// not re-triggering the real init-priority-99 registration pass).
	try {
		$fired = false;
		$cb    = function( $manager ) use ( &$fired ) {
			$fired = is_a( $manager, 'Jet_Search\Search_Sources\Manager' );
		};
		add_action( 'jet-search/sources/register', $cb, 10, 1 );
		do_action( 'jet-search/sources/register', jet_search()->search_sources );
		remove_action( 'jet-search/sources/register', $cb, 10 );

		agent_test_assert(
			$suite, 'jsw-10',
			'jet-search/sources/register action fires with the real Manager instance, the extension point for a custom Additional Results source',
			$fired,
			'callback received a Search_Sources\Manager instance',
			array( 'fired' => $fired ),
			'search-sources/manager.php:28'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsw-10', 'jet-search/sources/register action', false, 'no exception', $e->getMessage(), 'search-sources/manager.php:28' );
	}

	// jsw-11: the JetEngine macro class has the expected tag/name/empty-args contract.
	// JetEngine's own macro registry (`Jet_Engine_Listings_Macros::init()`, which fires
	// `jet-engine/register-macros`) is itself lazily initialized on first real macro
	// lookup (`includes/components/listings/macros.php:28-44`), not at plugin boot — the
	// same "class only loaded behind a lazy gate" shape this repo already hit for
	// jetengine-modules' Stores\Factory and jetengine-rest-api's Relations
	// Public_Controller (see HANDOFF.md). Force it via the real, idempotent `init()`
	// entry point (guarded by its own `$initialized` flag, safe to call from here) rather
	// than reaching around it with a manual require.
	try {
		if ( ! class_exists( 'Jet_Search_Macros_Current_Results' ) && function_exists( 'jet_engine' ) ) {
			jet_engine()->listings->macros->init();
		}

		$class_ready = class_exists( 'Jet_Search_Macros_Current_Results' );
		$macro       = $class_ready ? new Jet_Search_Macros_Current_Results() : null;

		$tag  = $macro ? $macro->macros_tag() : null;
		$name = $macro ? $macro->macros_name() : null;
		$args = $macro ? $macro->macros_args() : null;

		$pass = $class_ready
			&& 'jet_search_current_results' === $tag
			&& 'Current JetSearch Results' === $name
			&& is_array( $args ) && empty( $args );

		agent_test_assert(
			$suite, 'jsw-11',
			"Jet_Search_Macros_Current_Results declares tag 'jet_search_current_results', name 'Current JetSearch Results', and empty macros_args()",
			$pass,
			"tag=jet_search_current_results, name=Current JetSearch Results, args=[]",
			array( 'class_ready' => $class_ready, 'tag' => $tag, 'name' => $name, 'args' => $args ),
			'compatibility/jet-engine/macros/current-results.php:11,18-41'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsw-11', 'Jet_Search_Macros_Current_Results contract', false, 'no exception', $e->getMessage(), 'compatibility/jet-engine/macros/current-results.php' );
	}

	// jsw-12: macros_callback() returns '' (not an error) when this request carries no
	// search query param — confirming the "request-driven, not render-driven" gotcha.
	try {
		$macro = class_exists( 'Jet_Search_Macros_Current_Results' ) ? new Jet_Search_Macros_Current_Results() : null;
		$ajax_handlers_ok = function_exists( 'jet_search_ajax_handlers' );

		$result = null;
		if ( $macro && $ajax_handlers_ok ) {
			$result = $macro->macros_callback();
		}

		// This REST-API test request carries no ?s=/custom search-query param, so
		// get_current_results_ids() should short-circuit to an empty array and the
		// macro callback should return an empty string.
		$pass = $macro && $ajax_handlers_ok && '' === $result;

		agent_test_assert(
			$suite, 'jsw-12',
			"macros_callback() returns '' (not an error/exception) on a request with no search query param present",
			$pass,
			"''",
			array( 'ajax_handlers_ok' => $ajax_handlers_ok, 'result' => $result ),
			'compatibility/jet-engine/macros/current-results.php:53-65; ajax-handlers.php:2776-2839'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsw-12', 'macros_callback() with no search param', false, "''", $e->getMessage(), 'ajax-handlers.php:2789-2791' );
	}

	// jsw-13: the Elementor-vs-Bricks custom-controls hook names are genuinely distinct,
	// and both are independently hooked by Jet_Search_Compatibility (WooCommerce branch).
	try {
		global $wp_filter;

		$elementor_hook = 'jet-search/ajax-search/add-custom-controls';
		$bricks_hook    = 'jet-search/ajax-search-bricks/add-custom-controls';

		$el_has_listener     = has_action( $elementor_hook ) !== false;
		$bricks_has_listener = has_action( $bricks_hook ) !== false;
		$distinct_names       = $elementor_hook !== $bricks_hook;

		$pass = $distinct_names && $el_has_listener && $bricks_has_listener;

		agent_test_assert(
			$suite, 'jsw-13',
			'Elementor and Bricks Ajax-Search custom-controls hooks are distinctly-named, both with a real registered listener (Jet_Search_Compatibility)',
			$pass,
			'two distinct hook names, both with >=1 listener',
			array(
				'elementor_hook' => $elementor_hook,
				'bricks_hook'    => $bricks_hook,
				'el_has_listener'     => $el_has_listener,
				'bricks_has_listener' => $bricks_has_listener,
			),
			'compatibility.php:49,62-63,241-269; widgets/ajax-search.php:667; bricks-views/elements/ajax-search.php:939'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jsw-13', 'Elementor vs Bricks add-custom-controls hook names', false, 'no exception', $e->getMessage(), 'compatibility.php:49,62-63' );
	}

} );
