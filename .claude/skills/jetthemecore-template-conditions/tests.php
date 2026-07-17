<?php
/**
 * AGENT-TEST-SUITE: jetthemecore-template-conditions
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetthemecore-template-conditions"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetthemecore-template-conditions. See docs/test-harness-guide.md.
 *
 * NOT YET RUN: JetThemeCore is not installed on the sandbox (jackfruit.epeak.studio) as
 * of this writing — see TEST-REGIMEN.md. Written as-if-ready. Only the live singleton
 * jet_theme_core()->template_conditions_manager is ever read here — never
 * `new \Jet_Theme_Core\Template_Conditions\Manager()` (no known landmine confirmed for
 * this class, but there's no reason to construct a second one either — see SKILL.md).
 */

add_action( 'agent-test/run-suite/jetthemecore-template-conditions', function() {

	$suite = 'jetthemecore-template-conditions';

	// jttc-1: the registry is reachable after init and populated with built-in conditions.
	try {
		$manager = function_exists( 'jet_theme_core' ) ? jet_theme_core()->template_conditions_manager : null;
		$conditions = $manager ? $manager->get_conditions() : array();
		$expected_ids = array( 'entire', 'archive-all', 'singular-page', 'singular-page-template', 'singular-post-type' );
		$found_ids = is_array( $conditions ) ? array_keys( $conditions ) : array();
		$missing = array_diff( $expected_ids, $found_ids );
		agent_test_assert(
			$suite, 'jttc-1',
			'SKILL.md "The registry...": jet_theme_core()->template_conditions_manager->get_conditions() is populated with built-in condition ids after init priority 999',
			empty( $missing ),
			array( 'expected_ids' => $expected_ids ),
			array( 'found_ids_sample' => array_slice( $found_ids, 0, 20 ), 'missing' => array_values( $missing ) ),
			'includes/template-conditions/manager.php:81-125,848 (register_conditions() hooked on init priority 999)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jttc-1', 'template_conditions_manager reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jttc-2: check() is really called with 3 positional args (value, sub_group, arg), not 1,
	// despite Base::check($args) declaring one parameter. Registers a throwaway condition
	// instance directly via add_condition() (not the conditions-list file-require convention,
	// to avoid needing a separate on-disk file for a one-off test class).
	try {
		if ( ! class_exists( '\\Jet_Theme_Core\\Template_Conditions\\Base' ) ) {
			throw new \Exception( 'Template_Conditions\\Base not loaded' );
		}

		if ( ! class_exists( 'Agent_Test_TTC_Recorder_Condition' ) ) {
			eval( '
				class Agent_Test_TTC_Recorder_Condition extends \\Jet_Theme_Core\\Template_Conditions\\Base {
					public static $seen = array();
					public function get_id() { return "agent-test-ttc-recorder"; }
					public function get_label() { return "Agent Test Recorder"; }
					public function get_group() { return "advanced"; }
					public function get_priority() { return 1; }
					public function get_body_structure() { return "jet_single"; }
					public function check( $value = null, $sub_group = null, $arg = null ) {
						self::$seen = array( $value, $sub_group, $arg );
						return true;
					}
				}
			' );
		}

		$instance = new Agent_Test_TTC_Recorder_Condition();
		call_user_func( array( $instance, 'check' ), 'val_a', 'val_b', 'val_c' );
		$seen = Agent_Test_TTC_Recorder_Condition::$seen;
		$pass = ( $seen === array( 'val_a', 'val_b', 'val_c' ) );

		agent_test_assert(
			$suite, 'jttc-2',
			'SKILL.md "check()\'s real signature...": check() is invoked with 3 positional args (value, sub_group, arg) matching the manager\'s call_user_func(array($instance,"check"), $sub_group_value, $sub_group, $sub_group_arg) convention',
			$pass,
			array( 'seen' => array( 'val_a', 'val_b', 'val_c' ) ),
			array( 'seen' => $seen ),
			'includes/template-conditions/manager.php:543 (call_user_func with 3 args), conditions/base.php:127 (abstract check($args) declares only 1)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jttc-2', '3-arg check() call-convention test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jttc-3: post-types-list/deprecated and custom-post-types-list/deprecated are both real,
	// independently-firing filters gating two different Utils functions.
	try {
		if ( ! class_exists( '\\Jet_Theme_Core\\Utils' ) ) {
			throw new \Exception( 'Jet_Theme_Core\\Utils not loaded' );
		}

		$marker = 'agent_test_ttc_mrkr';
		if ( ! post_type_exists( $marker ) ) {
			register_post_type( $marker, array( 'public' => true, 'label' => 'Agent Test Marker' ) );
		}

		$before_general = array_key_exists( $marker, \Jet_Theme_Core\Utils::get_post_types() );
		$before_custom  = in_array(
			$marker,
			array_column( \Jet_Theme_Core\Utils::get_custom_post_types_options(), 'value' ),
			true
		);

		add_filter( 'jet-theme-core/post-types-list/deprecated', function( $list ) use ( $marker ) {
			$list[] = $marker;
			return $list;
		} );
		add_filter( 'jet-theme-core/custom-post-types-list/deprecated', function( $list ) use ( $marker ) {
			$list[] = $marker;
			return $list;
		} );

		$after_general = array_key_exists( $marker, \Jet_Theme_Core\Utils::get_post_types() );
		$after_custom  = in_array(
			$marker,
			array_column( \Jet_Theme_Core\Utils::get_custom_post_types_options(), 'value' ),
			true
		);

		$pass = $before_general && $before_custom && ! $after_general && ! $after_custom;

		agent_test_assert(
			$suite, 'jttc-3',
			'SKILL.md "Corrected fact...": jet-theme-core/post-types-list/deprecated and jet-theme-core/custom-post-types-list/deprecated are both real, currently-firing filters (not dead despite the "deprecated" name) gating Utils::get_post_types() and Utils::get_custom_post_types_options() independently',
			$pass,
			array( 'before_general' => true, 'before_custom' => true, 'after_general' => false, 'after_custom' => false ),
			array( 'before_general' => $before_general, 'before_custom' => $before_custom, 'after_general' => $after_general, 'after_custom' => $after_custom ),
			'includes/utils.php:90-128 (get_post_types()), :159-176 (get_custom_post_types_options())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jttc-3', 'post-types-list/deprecated + custom-post-types-list/deprecated live-fire test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jttc-4: CPT conditions are auto-generated (cpt-archive-{slug}/cpt-single-{slug}) for a
	// public custom post type, not registered via the conditions-list filter.
	//
	// Live-verified gotcha (2026-07-16): register_cpt_conditions() (manager.php:136-289) does
	// unconditional `require` (not require_once) of 4 condition class files (cpt-archive.php,
	// cpt-taxonomy.php, cpt-single-post.php, cpt-single-post-term.php, :139-142) - the same
	// fatal-on-second-call landmine as other unconditional-require constructors in this repo,
	// except here it's a plain method, not a constructor. It already ran once at the plugin's
	// own init priority 999 for every public CPT that existed at that point; calling it again
	// (even from outside a constructor) re-requires those files and throws an uncatchable
	// "Cannot redeclare class" fatal - confirmed live by isolating this exact call. Registering
	// a brand-new fake CPT at REST-request time can't get picked up without re-running that
	// method, so instead this test confirms the claim against a CPT that was ALREADY public
	// before this site's `init` ran: WooCommerce's `product` (WooCommerce is active on this
	// sandbox) already has real, boot-time-generated cpt-archive-product/cpt-single-product
	// condition entries - no re-registration call needed or attempted.
	try {
		$manager = function_exists( 'jet_theme_core' ) ? jet_theme_core()->template_conditions_manager : null;
		$conditions = $manager ? $manager->get_conditions() : array();

		$probe_slug = post_type_exists( 'product' ) ? 'product' : null;
		if ( ! $probe_slug ) {
			throw new \Exception( 'no already-public CPT available on this site to probe (expected WooCommerce\'s "product")' );
		}

		$has_archive = array_key_exists( 'cpt-archive-' . $probe_slug, $conditions );
		$has_single  = array_key_exists( 'cpt-single-' . $probe_slug, $conditions );

		agent_test_assert(
			$suite, 'jttc-4',
			'SKILL.md "Custom-post-type conditions are auto-generated...": register_cpt_conditions() creates cpt-archive-{slug} and cpt-single-{slug} condition instances for a public custom post type - checked against WooCommerce\'s already-registered "product" CPT rather than re-invoking register_cpt_conditions() live (that method does an unconditional require per condition-class file and fatals on a second call - see comment above)',
			( $has_archive && $has_single ),
			array( 'cpt-archive-' . $probe_slug => true, 'cpt-single-' . $probe_slug => true ),
			array( 'probe_slug' => $probe_slug, 'has_archive' => $has_archive, 'has_single' => $has_single ),
			'includes/template-conditions/manager.php:136-289 (register_cpt_conditions(), unconditional require at :139-142)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jttc-4', 'CPT condition auto-generation test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jttc-5: find_matched_conditions() returns multiple matches in save order (most-recent
	// first) with no priority-based tie-break, confirmed by reading the option write path
	// (array_reverse in update_template_conditions()) rather than needing 2 real posts.
	try {
		$manager = function_exists( 'jet_theme_core' ) ? jet_theme_core()->template_conditions_manager : null;
		if ( ! $manager ) {
			throw new \Exception( 'template_conditions_manager not available' );
		}

		$type = 'agent_test_structure_type';
		$conditions_key = $manager->conditions_key;
		$original = get_option( $conditions_key, array() );

		$fake_condition_row = array(
			array( 'id' => '_a', 'include' => 'true', 'group' => 'entire', 'subGroup' => 'entire' ),
		);

		// Simulate the save order update_template_conditions() itself produces:
		// array_reverse(..., true) puts the most-recently-added key first.
		$site_conditions = $original;
		$site_conditions[ $type ] = array(
			1001 => $fake_condition_row,
			1002 => $fake_condition_row,
		);
		$site_conditions[ $type ] = array_reverse( $site_conditions[ $type ], true );
		update_option( $conditions_key, $site_conditions, true );

		$result = $manager->find_matched_conditions( $type );

		// Clean up: restore original option so this suite is safe to re-run.
		update_option( $conditions_key, $original, true );

		$pass = is_array( $result ) && count( $result ) === 2 && $result[0] === 1002;

		agent_test_assert(
			$suite, 'jttc-5',
			'SKILL.md "no priority-based tie-break": find_matched_conditions() returns all matching template ids in option order (most-recently-saved first via array_reverse), with no get_priority()-based comparison',
			$pass,
			array( 'count' => 2, 'first' => 1002 ),
			array( 'result' => $result ),
			'includes/template-conditions/manager.php:503-583 (find_matched_conditions()), :450 (array_reverse in update_template_conditions())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jttc-5', 'find_matched_conditions() ordering/no-tie-break test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
