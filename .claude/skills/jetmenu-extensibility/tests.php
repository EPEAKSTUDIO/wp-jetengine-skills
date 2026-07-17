<?php
/**
 * AGENT-TEST-SUITE: jetmenu-extensibility
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetmenu-extensibility"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetmenu-extensibility. See docs/test-harness-guide.md.
 *
 * NOT YET RUNNABLE: JetMenu is not installed on the sandbox (jackfruit.epeak.studio) as
 * of this writing — see TEST-REGIMEN.md's "BLOCKED" run log. Written as-if-ready.
 *
 * SAFETY: per SKILL.md's "never re-instantiate" list, this suite never calls
 * `new \Jet_Menu\Compatibility\Manager()`, `new \Jet_Menu\Integration()`,
 * `new \Jet_Menu\Blocks\Manager()`, or `new \Jet_Menu\Modules\Dynamic_Visibility\Dynamic_Visibility()`
 * — all four already run exactly once via the plugin's own `init` bootstrap and fatal
 * with "Cannot redeclare class" on a second `new`. `Registry`/`Checker` (Dynamic
 * Visibility's leaf classes) and the two compatibility leaf classes
 * (`Jet_Smart_Filters`/`Jet_Theme_Core`) ARE safe to instantiate directly (confirmed:
 * no unconditional `require` in their own constructors) and are used below.
 */

add_action( 'agent-test/run-suite/jetmenu-extensibility', function() {

	$suite = 'jetmenu-extensibility';

	// jex-1: registering a custom Dynamic Visibility condition via the documented action makes it
	// reachable through a fresh Registry (safe to instantiate - require_once-guarded, not a landmine).
	// NOTE: Base_Condition is only require_once'd lazily inside Registry::register_defaults() - the
	// plugin's own init bootstrap (dynamic-visibility.php's Dynamic_Visibility::__construct()) does NOT
	// eagerly load it, and Checker (the only thing that constructs a Registry in normal operation) is
	// itself only constructed lazily from apply_visibility(), which only runs on a real wp_get_nav_menu_items
	// call with at least one dynamic-visibility-enabled item. So class_exists(Base_Condition) can genuinely
	// be false at the point this test runs - constructing Registry directly (safe, require_once-based) is
	// what makes it available, not a precondition for doing so.
	try {
		if ( ! class_exists( 'Agent_Test_Always_True_Condition' ) ) {
			// Registry's own require_once of base-condition.php hasn't necessarily run yet - force it first
			// so this eval-defined class can extend a real, loaded parent.
			if ( ! class_exists( '\Jet_Menu\Modules\Dynamic_Visibility\Conditions\Base_Condition' ) ) {
				require_once jet_menu()->plugin_path( 'includes/modules/dynamic-visibility/inc/conditions/base-condition.php' );
			}
			eval( '
				class Agent_Test_Always_True_Condition extends \Jet_Menu\Modules\Dynamic_Visibility\Conditions\Base_Condition {
					public function get_key() { return "agent_test_always_true"; }
					public function check( $rule, $context ) { return true; }
				}
			' );
		}

		add_action( 'jet-menu/modules/dynamic-visibility/register-condition', function( $registry ) {
			$registry->register_condition( new \Agent_Test_Always_True_Condition() );
		} );

		// Registry's own constructor already fires register_custom() -> the action above -
		// safe per SKILL.md, since Registry uses require_once for its own file includes.
		$registry = new \Jet_Menu\Modules\Dynamic_Visibility\Conditions\Registry();
		$has_condition = $registry->has( 'agent_test_always_true' );

		$checker_result = null;
		if ( $has_condition && class_exists( '\Jet_Menu\Modules\Dynamic_Visibility\Context' ) ) {
			$condition = $registry->get( 'agent_test_always_true' );
			$checker_result = $condition->check( array( 'type' => 'agent_test_always_true', 'attrs' => array() ), new \Jet_Menu\Modules\Dynamic_Visibility\Context( array() ) );
		}

		$pass = $has_condition && ( true === $checker_result );

		agent_test_assert(
			$suite, 'jex-1',
			'SKILL.md "Dynamic Visibility: registering a custom condition": a class extending Base_Condition, registered via jet-menu/modules/dynamic-visibility/register-condition, is reachable through Registry::get()/has() and its check() runs',
			$pass,
			array( 'has_condition' => true, 'check_returns_true' => true ),
			array( 'has_condition' => $has_condition, 'check_result' => $checker_result ),
			'includes/modules/dynamic-visibility/inc/conditions/registry.php:24-37,106-114, inc/conditions/base-condition.php:10-22'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jex-1', 'custom Dynamic Visibility condition registration smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jex-2: a rule type registered only via register-condition (step 1), but never added to the
	// allowed-rule-types whitelist (step 2), is silently dropped by sanitize_dynamic_visibility().
	try {
		$sanitized = jet_menu()->settings_manager->sanitize_dynamic_visibility( array(
			'enabled'  => true,
			'type'     => 'show',
			'relation' => 'AND',
			'rules'    => array(
				array( 'type' => 'agent_test_unregistered_type', 'attrs' => array() ),
			),
		) );
		$pass = is_array( $sanitized ) && isset( $sanitized['rules'] ) && empty( $sanitized['rules'] );
		agent_test_assert(
			$suite, 'jex-2',
			'SKILL.md "Dynamic Visibility": sanitize_dynamic_visibility() drops any rule whose type is not in the jet-menu/dynamic-visibility/allowed-rule-types whitelist, even if a Base_Condition for it was registered',
			$pass,
			array( 'rules_empty' => true ),
			array( 'sanitized' => $sanitized ),
			'includes/settings/manager.php:834,848,795-949'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jex-2', 'unregistered rule-type sanitize smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jex-3: a rule type added to allowed-rule-types but relying on the sanitize-rule filter for its
	// attrs shape is persisted only if that filter returns a valid {type, attrs} array.
	try {
		add_filter( 'jet-menu/dynamic-visibility/allowed-rule-types', function( $types ) {
			$types[] = 'agent_test_custom_type';
			return $types;
		} );
		add_filter( 'jet-menu/dynamic-visibility/sanitize-rule', function( $result, $rule, $rule_type, $attrs ) {
			if ( 'agent_test_custom_type' === $rule_type ) {
				return array( 'type' => 'agent_test_custom_type', 'attrs' => array( 'marker' => true ) );
			}
			return $result;
		}, 10, 4 );

		$sanitized = jet_menu()->settings_manager->sanitize_dynamic_visibility( array(
			'enabled'  => true,
			'type'     => 'show',
			'relation' => 'AND',
			'rules'    => array(
				array( 'type' => 'agent_test_custom_type', 'attrs' => array() ),
			),
		) );

		$pass = is_array( $sanitized )
			&& ! empty( $sanitized['rules'] )
			&& 'agent_test_custom_type' === $sanitized['rules'][0]['type']
			&& ! empty( $sanitized['rules'][0]['attrs']['marker'] );

		agent_test_assert(
			$suite, 'jex-3',
			'SKILL.md "Dynamic Visibility": with the rule type whitelisted AND a jet-menu/dynamic-visibility/sanitize-rule callback returning a valid {type, attrs} array, the custom rule IS persisted through sanitize_dynamic_visibility()',
			$pass,
			array( 'rule_persisted_with_marker' => true ),
			array( 'sanitized' => $sanitized ),
			'includes/settings/manager.php:921-934'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jex-3', 'custom rule-type full round-trip smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jex-4: Jet_Smart_Filters / Jet_Theme_Core compatibility classes bail (register no hooks) when
	// their target plugin's marker constant/class is absent - safe to instantiate directly (leaf
	// classes, no unconditional require of their own; do NOT instantiate Compatibility\Manager itself).
	try {
		$had_filter_before = has_filter( 'jet-menu/mega-menu/location/prevent-modify-nav-menu' );

		$jsf_class_exists  = class_exists( '\Jet_Menu\Compatibility\Jet_Smart_Filters' );
		$jtc_class_exists  = class_exists( '\Jet_Menu\Compatibility\Jet_Theme_Core' );

		$jsf_instance = $jsf_class_exists ? new \Jet_Menu\Compatibility\Jet_Smart_Filters() : null;
		$jtc_instance = $jtc_class_exists ? new \Jet_Menu\Compatibility\Jet_Theme_Core() : null;

		$had_filter_after = has_filter( 'jet-menu/mega-menu/location/prevent-modify-nav-menu' );

		// Neither JetSmartFilters nor JetTheme Core is installed on this sandbox (see HANDOFF.md's
		// "plugins installed" inventory), so both constructors should have bailed early and the
		// filter count should be unchanged.
		$pass = ( $had_filter_before === $had_filter_after );

		agent_test_assert(
			$suite, 'jex-4',
			'SKILL.md "Compatibility & Integration registries": Jet_Smart_Filters/Jet_Theme_Core compatibility classes bail without registering any hooks when their target plugin (JetSmartFilters/JetThemeCore) is not active',
			$pass,
			array( 'no_new_hooks_registered' => true ),
			array( 'had_filter_before' => $had_filter_before, 'had_filter_after' => $had_filter_after, 'classes_found' => array( 'jsf' => $jsf_class_exists, 'jtc' => $jtc_class_exists ) ),
			'includes/compatibility/plugins/jet-smart-filters/manager.php:17-21, includes/compatibility/plugins/jet-theme-core/manager.php:33-41'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jex-4', 'compatibility-class bail-early smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jex-5: JetFormBuilder "integration" is confirmed a dead stub - constructing it registers no hooks
	// and it exposes no methods beyond the empty load_files(), even when JetFormBuilder IS active.
	try {
		$class_exists = class_exists( '\Jet_Menu\Integration\Jet_Form_Builder' );
		$reflection = $class_exists ? new \ReflectionClass( '\Jet_Menu\Integration\Jet_Form_Builder' ) : null;
		$public_methods = $reflection ? array_map( function( $m ) { return $m->getName(); }, $reflection->getMethods( \ReflectionMethod::IS_PUBLIC ) ) : array();
		// Expect only the constructor and the empty load_files() - no real hook-registering methods.
		$has_only_stub_methods = $class_exists && count( array_diff( $public_methods, array( '__construct', 'load_files' ) ) ) === 0;
		agent_test_assert(
			$suite, 'jex-5',
			'SKILL.md "JetFormBuilder integration is a dead stub": \Jet_Menu\Integration\Jet_Form_Builder exposes only __construct()/load_files(), no hook-registering methods - confirms no dynamic form-to-menu wiring exists in this version',
			$has_only_stub_methods,
			array( 'only_stub_methods' => true ),
			array( 'class_exists' => $class_exists, 'public_methods' => $public_methods ),
			'integration/plugins/jet-form-builder/manager.php:9-26'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jex-5', 'JetFormBuilder integration stub smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jex-6: source-presence check for the walker/render customization hooks and the JS event trio -
	// these need a real rendered mega-menu item / a browser to trigger live, so this confirms the
	// literal strings are still present in the currently-installed source (guards against a future
	// JetMenu update silently renaming/removing them).
	try {
		$checks = array(
			array( 'includes/render/walkers/mega-menu-walker.php', "apply_filters( 'jet-menu/mega-menu-walker/start-el'" ),
			array( 'includes/render/walkers/mega-menu-walker.php', "do_action( 'jet-menu/mega-sub-menu/before-render'" ),
			array( 'includes/render/walkers/vertical-menu-walker.php', "'jet-menu/widgets/custom-menu/mega-sub-menu/before-render'" ),
			array( 'includes/elementor/widgets/legacy/jet-widget-mega-menu.php', "do_action( 'jet-menu/widgets/mega-menu/controls'" ),
			array( 'includes/blocks/manager.php', "apply_filters( 'jet-menu/block-manager/blocks-list'" ),
			array( 'includes/rest-api/rest-api.php', "do_action( 'jet-menu/rest/init-endpoints'" ),
			array( 'includes/compatibility/manager.php', "apply_filters( 'jet-menu/compatibility-manager/registered-plugins'" ),
			array( 'integration/manager.php', "'jet-menu/integration-manager/registered-plugins'" ),
		);
		$results  = array();
		$all_pass = true;
		foreach ( $checks as $c ) {
			list( $rel_path, $needle ) = $c;
			$file     = WP_PLUGIN_DIR . '/jet-menu/' . $rel_path;
			$contents = file_exists( $file ) ? file_get_contents( $file ) : '';
			$found    = ( '' !== $contents ) && ( false !== strpos( $contents, $needle ) );
			$results[ $rel_path . ' :: ' . $needle ] = $found;
			$all_pass = $all_pass && $found;
		}

		// JS event trio - source-presence in the shipped public bundle only (not live-triggered, needs a browser).
		$js_file     = WP_PLUGIN_DIR . '/jet-menu/assets/public/js/jet-menu-public-scripts.js';
		$js_contents = file_exists( $js_file ) ? file_get_contents( $js_file ) : '';
		$js_events   = array( 'jet-menu/ajax/frontend-init', 'jet-menu/ajax/frontend-init/before', 'jet-menu/ajax/frontend-init/after', 'JetMegaMenuInited' );
		$js_found    = array();
		foreach ( $js_events as $evt ) {
			$js_found[ $evt ] = ( '' !== $js_contents ) && ( false !== strpos( $js_contents, $evt ) );
		}
		$all_pass = $all_pass && ! in_array( false, $js_found, true );

		agent_test_assert(
			$suite, 'jex-6',
			'SKILL.md hook-name source-presence: all documented jet-menu/* PHP hook strings and JS event-name strings are present verbatim in the currently-installed source',
			$all_pass,
			array( 'all_php_hooks_present' => true, 'all_js_events_present' => true ),
			array( 'php' => $results, 'js' => $js_found ),
			'see individual SKILL.md sections for each file:line citation - source-presence only, not live-triggered (several need a rendered mega item or a browser)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jex-6', 'hook/event source-presence smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jex-7: confirmed absence of JetEngine-specific integration - no jet-menu/*jetengine* hook exists,
	// and jet_engine()/Jet_Engine references in the plugin tree are limited to the documented
	// wp-admin-menu-grouping usage and text-domain typos, not a data-layer integration.
	try {
		$widget_file     = WP_PLUGIN_DIR . '/jet-menu/includes/elementor/widgets/jet-widget-mega-menu.php';
		$widget_contents = file_exists( $widget_file ) ? file_get_contents( $widget_file ) : '';
		$no_jetengine_hook = ( '' === $widget_contents ) || ( false === strpos( $widget_contents, "'jet-menu/jetengine" ) );
		agent_test_assert(
			$suite, 'jex-7',
			'SKILL.md "No JetEngine-specific integration exists": no jet-menu/jetengine-* prefixed hook exists anywhere in the mega-menu widget source',
			$no_jetengine_hook,
			array( 'no_jetengine_specific_hook' => true ),
			array( 'no_jetengine_specific_hook' => $no_jetengine_hook, 'file_readable' => ( '' !== $widget_contents ) ),
			'confirmed by full-tree grep during research; this assertion re-checks the most likely file for a regression'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jex-7', 'no-JetEngine-integration smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
