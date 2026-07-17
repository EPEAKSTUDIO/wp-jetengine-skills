<?php
/**
 * AGENT-TEST-SUITE: jetelements-query-gateway
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetelements-query-gateway"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetelements-query-gateway. See docs/test-harness-guide.md.
 *
 * Safety note (see SKILL.md "Who owns what"): this suite NEVER constructs
 * `new \Jet_Engine\Query_Builder\Query_Gateway\Manager()` — JetEngine's own
 * Query_Builder\Manager already instantiates one, unstored, during its own init
 * bootstrap. A second instantiation wouldn't fatal (no unconditional require in that
 * class), but WOULD silently double-register every one of its hook callbacks,
 * corrupting the JetEngine "current listing object" stack used by Dynamic Tags across
 * the whole site for the rest of the request — worse than a crash because it's quiet.
 * Every assertion here either fires the documented hooks directly as plain WP
 * filters/actions (jeg-1), checks whether JetEngine already registered its own
 * listeners via has_action()/has_filter() (jeg-2), or is a source-presence check
 * (jeg-3/jeg-4) — nothing here ever instantiates Query_Gateway\Manager.
 */

add_action( 'agent-test/run-suite/jetelements-query-gateway', function() {

	$suite = 'jetelements-query-gateway';

	// jeg-1: jet-elements/widget/loop-items is a real, honored filter — driven directly with a marker callback,
	// exactly the shape Jet_Elements_Base::_get_render_looped_template() applies it in (loop array, $setting, $widget).
	// No real widget instance is needed since apply_filters() is a generic WP mechanism independent of who calls it.
	try {
		// 2026-07-16: first version passed $widget = null and fataled — JetEngine's OWN
		// Query_Gateway\Manager::jet_plugins_compatibility() is already a real listener on this
		// exact hook (confirmed in jeg-2), and it unconditionally calls $widget->get_name()
		// (manager.php:218). Use a minimal stub with a get_name() that returns a name absent from
		// Query_Gateway\Manager's internal $_controls_map, so is_control_supported() short-circuits
		// false before it ever needs get_settings() — safe, and still proves loop-items is honored.
		$fake_widget = new class {
			public function get_name() { return 'agent_test_widget_stub_does_not_exist'; }
		};
		$fake_loop = array( array( 'item_title' => 'agent-test-item' ) );
		// Named function (not a closure) so remove_filter() below can target exactly this
		// callback — remove_all_filters() would also strip JetEngine's own real listener on
		// this hook (Query_Gateway\Manager::jet_plugins_compatibility(), see jeg-2), corrupting
		// that test if it runs afterward in the same request.
		$marker_cb = function( $loop, $setting, $widget ) {
			$loop[] = array( 'item_title' => 'agent_test_injected_item' );
			return $loop;
		};
		add_filter( 'jet-elements/widget/loop-items', $marker_cb, 10, 3 );
		$result = apply_filters( 'jet-elements/widget/loop-items', $fake_loop, 'agent_test_setting', $fake_widget );
		remove_filter( 'jet-elements/widget/loop-items', $marker_cb, 10 );
		$pass = is_array( $result ) && 2 === count( $result ) && 'agent_test_injected_item' === ( $result[1]['item_title'] ?? null );
		agent_test_assert(
			$suite, 'jeg-1',
			'SKILL.md "The four hooks...": jet-elements/widget/loop-items is applied to the loop array with ($loop, $setting, $widget) before _get_render_looped_template() iterates it — proven honored via a direct apply_filters() call matching that exact shape',
			$pass,
			array( 'result_count' => 2, 'injected_item_present' => true ),
			array( 'result' => $result ),
			'plugins/jet-elements/includes/base/class-jet-elements-base.php:165'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jeg-1', 'jet-elements/widget/loop-items honored smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jeg-2: JetEngine's own Query_Gateway\Manager (constructed unconditionally by JetEngine's Query_Builder\Manager
	// on its own init bootstrap, unrelated to whether JetElements is active) has already registered its listeners for
	// all four lifecycle hooks plus the per-plugin loop-items filter and the control-injection action, confirmed via
	// has_action()/has_filter() rather than by touching the Manager instance itself (see file docblock for why).
	try {
		$jetengine_active = function_exists( 'jet_engine' );
		$checks = array(
			'jet-engine-query-gateway/control'    => has_action( 'jet-engine-query-gateway/control' ),
			'jet-engine-query-gateway/do-item'    => has_action( 'jet-engine-query-gateway/do-item' ),
			'jet-engine-query-gateway/before-loop' => has_action( 'jet-engine-query-gateway/before-loop' ),
			'jet-engine-query-gateway/reset-item' => has_action( 'jet-engine-query-gateway/reset-item' ),
			'jet-engine-query-gateway/query'      => has_filter( 'jet-engine-query-gateway/query' ),
			'jet-elements/widget/loop-items'      => has_filter( 'jet-elements/widget/loop-items' ),
		);
		$all_registered = $jetengine_active && ! in_array( false, $checks, true );
		agent_test_assert(
			$suite, 'jeg-2',
			'SKILL.md "Who owns what...": Jet_Engine\\Query_Builder\\Query_Gateway\\Manager (constructed once, unstored, by Query_Builder\\Manager::register_instances()) has already registered listeners for all 4 lifecycle hooks plus jet-engine-query-gateway/query and jet-elements/widget/loop-items on this site — confirmed via has_action()/has_filter(), never by instantiating the Manager',
			$all_registered,
			array( 'jetengine_active' => true, 'all_hooks_registered' => true ),
			array( 'jetengine_active' => $jetengine_active, 'hooks' => $checks ),
			'plugins/jet-engine/includes/components/query-builder/manager.php:300 (new Query_Gateway\\Manager, unstored); plugins/jet-engine/includes/components/query-builder/query-gateway/manager.php:26-29,46-48 (its own add_action/add_filter calls)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jeg-2', 'Query_Gateway\\Manager hook-registration reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jeg-3: the jet-engine-query-gateway/control action call site exists in a real JetElements addon (Portfolio),
	// confirming the exact string/arg shape a widget author would copy. Source-presence only — firing it for real
	// requires _register_controls() to be mid-execution inside a real Elementor widget construction.
	try {
		$file = WP_PLUGIN_DIR . '/jet-elements/includes/addons/jet-elements-portfolio.php';
		$contents = file_exists( $file ) ? file_get_contents( $file ) : '';
		$has_call = false !== strpos( $contents, "do_action( 'jet-engine-query-gateway/control', \$this, 'image_list' )" );
		agent_test_assert(
			$suite, 'jeg-3',
			'SKILL.md "Enabling a control...": jet-engine-query-gateway/control is fired as do_action( \'jet-engine-query-gateway/control\', $this, \'image_list\' ) from inside Jet_Elements_Portfolio\'s _register_controls(), immediately before its Repeater control is added',
			( '' !== $contents && $has_call ),
			array( 'file_readable' => true, 'call_present' => true ),
			array( 'file_readable' => ( '' !== $contents ), 'call_present' => $has_call ),
			'plugins/jet-elements/includes/addons/jet-elements-portfolio.php:91 — source-presence only, not live-triggered (needs a real Elementor widget mid-construction, see file docblock)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jeg-3', 'jet-engine-query-gateway/control call-site presence smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jeg-4: the loop-lifecycle hook call sites exist in JetElements' shared base class with the documented arg counts,
	// and JetEngine's Query_Gateway\Manager gating logic (query_enbaled()'s 3-part check) is present as claimed.
	// Source-presence only — get_queried_items()/query_enbaled() need a real widget object with Elementor's
	// get_settings()/get_controls() API, not safely callable with a fake object.
	try {
		$base_file = WP_PLUGIN_DIR . '/jet-elements/includes/base/class-jet-elements-base.php';
		$base_contents = file_exists( $base_file ) ? file_get_contents( $base_file ) : '';
		$gateway_file = WP_PLUGIN_DIR . '/jet-engine/includes/components/query-builder/query-gateway/manager.php';
		$gateway_contents = file_exists( $gateway_file ) ? file_get_contents( $gateway_file ) : '';

		$checks = array(
			'before-loop call site' => ( '' !== $base_contents ) && false !== strpos( $base_contents, "do_action( 'jet-engine-query-gateway/before-loop', \$setting, \$this )" ),
			'do-item call site'     => ( '' !== $base_contents ) && false !== strpos( $base_contents, "do_action( 'jet-engine-query-gateway/do-item', \$item )" ),
			'reset-item call site'  => ( '' !== $base_contents ) && false !== strpos( $base_contents, "do_action( 'jet-engine-query-gateway/reset-item' )" ),
			'query_enbaled 3-part gate' => ( '' !== $gateway_contents ) && false !== strpos( $gateway_contents, 'if ( ! $is_active || ! $query_id || empty( $control_val ) )' ),
		);
		$all_pass = ! in_array( false, $checks, true );
		agent_test_assert(
			$suite, 'jeg-4',
			'SKILL.md "The four hooks..." / "Enabling a control...": the before-loop/do-item/reset-item call sites in Jet_Elements_Base match the documented arg shapes, and Query_Gateway\\Manager::query_enbaled()\'s 3-part gate (switcher + query_id + non-empty repeater value) is present verbatim in currently-installed source',
			$all_pass,
			array( 'all_present' => true ),
			$checks,
			'plugins/jet-elements/includes/base/class-jet-elements-base.php:179,183,195; plugins/jet-engine/includes/components/query-builder/query-gateway/manager.php:225-237 — source-presence only, see file docblock for why get_queried_items()/query_enbaled() are not live-invoked'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jeg-4', 'loop-lifecycle + query_enbaled gate source-presence test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
