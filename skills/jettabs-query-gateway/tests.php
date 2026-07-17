<?php
/**
 * AGENT-TEST-SUITE: jettabs-query-gateway
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jettabs-query-gateway"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jettabs-query-gateway. See docs/test-harness-guide.md.
 *
 * Safety note (mirrors jetelements-query-gateway/tests.php): this suite NEVER constructs
 * `new \Jet_Engine\Query_Builder\Query_Gateway\Manager()` — JetEngine's own
 * Query_Builder\Manager already instantiates one, unstored, during its own init bootstrap.
 * A second instantiation would silently double-register every hook callback in that class,
 * corrupting the site-wide "current listing object" stack Dynamic Tags depend on for the
 * rest of the request. Every assertion here either fires a documented hook directly as a
 * plain WP filter/action (jqg-1), checks whether JetEngine already registered a listener
 * via has_filter() (jqg-2), or is a source-presence check (jqg-3/jqg-4).
 */

add_action( 'agent-test/run-suite/jettabs-query-gateway', function() {

	$suite = 'jettabs-query-gateway';

	// jqg-1: jet-tabs/widget/loop-items is a real, honored filter — driven directly with a
	// marker callback, matching the shape both Jet_Tabs_Base::__get_render_looped_template()
	// and the hand-rolled Tabs/Accordion render() loops apply it in ($loop, $setting, $widget).
	try {
		// Same reasoning as jetelements-query-gateway/tests.php's jeg-1: JetEngine's own
		// Query_Gateway\Manager::jet_plugins_compatibility() is a real listener on this exact
		// hook (confirmed in jqg-2) and unconditionally calls $widget->get_name() — use a
		// minimal stub whose get_name() returns a name absent from Query_Gateway\Manager's
		// internal $_controls_map, so is_control_supported() short-circuits false first.
		$fake_widget = new class {
			public function get_name() { return 'agent_test_jettabs_widget_stub_does_not_exist'; }
		};
		$fake_loop = array( array( 'item_title' => 'agent-test-item' ) );
		$marker_cb = function( $loop, $setting, $widget ) {
			$loop[] = array( 'item_title' => 'agent_test_injected_item' );
			return $loop;
		};
		add_filter( 'jet-tabs/widget/loop-items', $marker_cb, 10, 3 );
		$result = apply_filters( 'jet-tabs/widget/loop-items', $fake_loop, 'agent_test_setting', $fake_widget );
		// remove_filter() with the exact callback reference, NOT remove_all_filters() — the
		// latter would also strip JetEngine's own real listener on this hook, corrupting jqg-2
		// if it runs afterward in the same request (this is exactly the bug
		// jetelements-query-gateway's TEST-REGIMEN.md run log describes hitting and fixing).
		remove_filter( 'jet-tabs/widget/loop-items', $marker_cb, 10 );
		$pass = is_array( $result ) && 2 === count( $result ) && 'agent_test_injected_item' === ( $result[1]['item_title'] ?? null );
		agent_test_assert(
			$suite, 'jqg-1',
			'SKILL.md "Three different copies of the loop": jet-tabs/widget/loop-items is applied to the loop array with ($loop, $setting, $widget) shape — proven honored via a direct apply_filters() call',
			$pass,
			array( 'result_count' => 2, 'injected_item_present' => true ),
			array( 'result' => $result ),
			'plugins/jet-tabs/includes/base/class-jet-tabs-base.php:101; includes/addons/jet-tabs-widget.php:1818; includes/addons/jet-accordion-widget.php:1003; includes/addons/jet-image-accordion-widget.php:1272'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jqg-1', 'jet-tabs/widget/loop-items honored smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jqg-2: JetEngine's own Query_Gateway\Manager has registered its listener specifically
	// on jet-tabs/widget/loop-items (not just jet-elements/widget/loop-items), confirmed via
	// has_filter() rather than by touching the Manager instance itself (see file docblock).
	try {
		$jetengine_active = function_exists( 'jet_engine' );
		$checks = array(
			'jet-tabs/widget/loop-items'           => has_filter( 'jet-tabs/widget/loop-items' ),
			'jet-engine-query-gateway/do-item'      => has_action( 'jet-engine-query-gateway/do-item' ),
			'jet-engine-query-gateway/before-loop'  => has_action( 'jet-engine-query-gateway/before-loop' ),
			'jet-engine-query-gateway/reset-item'   => has_action( 'jet-engine-query-gateway/reset-item' ),
			'jet-engine-query-gateway/control'      => has_action( 'jet-engine-query-gateway/control' ),
		);
		$all_registered = $jetengine_active && ! in_array( false, $checks, true );
		agent_test_assert(
			$suite, 'jqg-2',
			'SKILL.md intro / "The three Query-Gateway-enabled controls": Jet_Engine\\Query_Builder\\Query_Gateway\\Manager has registered a listener on jet-tabs/widget/loop-items specifically (same Manager instance also serves jet-elements/widget/loop-items), plus all 3 lifecycle actions and the control-injection action — confirmed via has_action()/has_filter() only',
			$all_registered,
			array( 'jetengine_active' => true, 'all_hooks_registered' => true ),
			array( 'jetengine_active' => $jetengine_active, 'hooks' => $checks ),
			'plugins/jet-engine/includes/components/query-builder/query-gateway/manager.php:26-29,46-48 (foreach over jet-tabs and jet-elements plugin slugs)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jqg-2', 'Query_Gateway\\Manager jet-tabs hook-registration reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jqg-3: the core finding of this skill — jet-engine-query-gateway/do-item is ABSENT from
	// the shared Jet_Tabs_Base loop method but PRESENT in both widgets that hand-roll their own
	// loop. Also checks the control-call-site pattern (3 widgets fire it, Switcher doesn't).
	try {
		$base_file = WP_PLUGIN_DIR . '/jet-tabs/includes/base/class-jet-tabs-base.php';
		$base_contents = file_exists( $base_file ) ? file_get_contents( $base_file ) : '';

		$tabs_file = WP_PLUGIN_DIR . '/jet-tabs/includes/addons/jet-tabs-widget.php';
		$tabs_contents = file_exists( $tabs_file ) ? file_get_contents( $tabs_file ) : '';

		$accordion_file = WP_PLUGIN_DIR . '/jet-tabs/includes/addons/jet-accordion-widget.php';
		$accordion_contents = file_exists( $accordion_file ) ? file_get_contents( $accordion_file ) : '';

		$image_accordion_file = WP_PLUGIN_DIR . '/jet-tabs/includes/addons/jet-image-accordion-widget.php';
		$image_accordion_contents = file_exists( $image_accordion_file ) ? file_get_contents( $image_accordion_file ) : '';

		$switcher_file = WP_PLUGIN_DIR . '/jet-tabs/includes/addons/jet-switcher-widget.php';
		$switcher_contents = file_exists( $switcher_file ) ? file_get_contents( $switcher_file ) : '';

		$do_item_needle = "do_action( 'jet-engine-query-gateway/do-item', \$item )";

		$checks = array(
			'base class do-item ABSENT'          => ( '' !== $base_contents ) && false === strpos( $base_contents, $do_item_needle ),
			'base class before-loop present'     => ( '' !== $base_contents ) && false !== strpos( $base_contents, "do_action( 'jet-engine-query-gateway/before-loop', \$setting, \$this )" ),
			'base class reset-item present'      => ( '' !== $base_contents ) && false !== strpos( $base_contents, "do_action( 'jet-engine-query-gateway/reset-item' )" ),
			'tabs widget do-item present'         => ( '' !== $tabs_contents ) && false !== strpos( $tabs_contents, $do_item_needle ),
			'accordion widget do-item present'    => ( '' !== $accordion_contents ) && false !== strpos( $accordion_contents, $do_item_needle ),
			'tabs control call-site present'      => ( '' !== $tabs_contents ) && false !== strpos( $tabs_contents, "do_action( 'jet-engine-query-gateway/control', \$this, 'tabs' )" ),
			'accordion control call-site present'  => ( '' !== $accordion_contents ) && false !== strpos( $accordion_contents, "do_action( 'jet-engine-query-gateway/control', \$this, 'toggles' )" ),
			'image accordion control call-site present' => ( '' !== $image_accordion_contents ) && false !== strpos( $image_accordion_contents, "do_action( 'jet-engine-query-gateway/control', \$this, 'item_list' )" ),
			'switcher has NO control call-site'    => ( '' !== $switcher_contents ) && false === strpos( $switcher_contents, "jet-engine-query-gateway/control" ),
		);
		$all_pass = ! in_array( false, $checks, true );
		agent_test_assert(
			$suite, 'jqg-3',
			'SKILL.md "Three different copies of the loop" / "The three Query-Gateway-enabled controls": jet-engine-query-gateway/do-item is missing from Jet_Tabs_Base::__get_render_looped_template() (used only by Image Accordion) but present in the Tabs and Accordion widgets\' own hand-rolled render() loops; jet-engine-query-gateway/control fires for tabs/toggles/item_list but never for Switcher',
			$all_pass,
			array( 'all_checks_true' => true ),
			$checks,
			'plugins/jet-tabs/includes/base/class-jet-tabs-base.php:98-131 (do-item absent); includes/addons/jet-tabs-widget.php:67,1946; includes/addons/jet-accordion-widget.php:70,1063; includes/addons/jet-image-accordion-widget.php:74; includes/addons/jet-switcher-widget.php (no do_action( \'jet-engine-query-gateway/control\'... at all)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jqg-3', 'do-item presence/absence + control call-site source-presence test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jqg-4: arg-count correction against jetelements-query-gateway/SKILL.md's "0 args" claim
	// for before-loop — both JetTabs' shared base method and JetElements' base class currently
	// pass 2 args ($setting, $widget) in the source checked out for this repo.
	try {
		$tabs_base_file = WP_PLUGIN_DIR . '/jet-tabs/includes/base/class-jet-tabs-base.php';
		$tabs_base_contents = file_exists( $tabs_base_file ) ? file_get_contents( $tabs_base_file ) : '';

		$elements_base_file = WP_PLUGIN_DIR . '/jet-elements/includes/base/class-jet-elements-base.php';
		$elements_base_contents = file_exists( $elements_base_file ) ? file_get_contents( $elements_base_file ) : '';

		$checks = array(
			'jet-tabs base before-loop is 2-arg'     => ( '' !== $tabs_base_contents ) && false !== strpos( $tabs_base_contents, "do_action( 'jet-engine-query-gateway/before-loop', \$setting, \$this )" ),
			'jet-elements base before-loop is 2-arg'  => ( '' !== $elements_base_contents ) && false !== strpos( $elements_base_contents, "do_action( 'jet-engine-query-gateway/before-loop', \$setting, \$this )" ),
		);
		// Only assert on jet-tabs' own file being present/correct — the jet-elements check is
		// informational (may be absent if JetElements isn't installed on this particular site)
		// and included in $actual for a human to eyeball, not gated into $pass.
		$pass = $checks['jet-tabs base before-loop is 2-arg'];
		agent_test_assert(
			$suite, 'jqg-4',
			'SKILL.md "Version gating... and a documented-elsewhere arg-count correction": before-loop fires with 2 args ($setting, $widget) in JetTabs\' shared base method (and, per source read during this audit, in JetElements\' base class too) — correcting jetelements-query-gateway/SKILL.md\'s "0 args" claim for the currently-installed source',
			$pass,
			array( 'jet_tabs_before_loop_2_arg' => true ),
			$checks,
			'plugins/jet-tabs/includes/base/class-jet-tabs-base.php:111; plugins/jet-elements/includes/base/class-jet-elements-base.php:179 (jet-elements check is informational only, not required for pass — see notes)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jqg-4', 'before-loop arg-count source-presence test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
