<?php
/**
 * AGENT-TEST-SUITE: jetpopup-conditions
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetpopup-conditions"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetpopup-conditions. See docs/test-harness-guide.md.
 *
 * Creates and cleans up a throwaway `jet-popup` fixture post (AGENT-TEST prefix) for the
 * live matching-algorithm tests (jpc-3/jpc-4/jpc-5). Safe to re-run.
 */

add_action( 'agent-test/run-suite/jetpopup-conditions', function() {

	$suite = 'jetpopup-conditions';

	// jpc-1: jet_popup()->conditions_manager is reachable, with static + CPT-generated conditions populated.
	try {
		$cm = function_exists( 'jet_popup' ) ? jet_popup()->conditions_manager : null;
		$entire = $cm ? $cm->get_condition( 'entire' ) : false;
		$cpt_single = $cm ? $cm->get_condition( 'cpt-single-post' ) : false;
		$pass = ( $entire instanceof \Jet_Popup\Conditions\Entire ) && is_object( $cpt_single );
		agent_test_assert(
			$suite, 'jpc-1',
			'SKILL.md "Registration": jet_popup()->conditions_manager->get_condition() resolves both a static condition ("entire") and a CPT-auto-generated one ("cpt-single-post")',
			$pass,
			array( 'entire_class' => 'Jet_Popup\\Conditions\\Entire', 'cpt_single_is_object' => true ),
			array( 'entire_class' => $entire ? get_class( $entire ) : null, 'cpt_single_is_object' => is_object( $cpt_single ) ),
			'includes/conditions-manager/manager.php:60-128,133-241, jet-popup.php:325'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jpc-1', 'conditions_manager reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jpc-2: Base's no-op defaults are inherited, not overridden, by the simplest built-in condition (Entire).
	try {
		$cm = function_exists( 'jet_popup' ) ? jet_popup()->conditions_manager : null;
		$entire = $cm ? $cm->get_condition( 'entire' ) : false;
		$sub_group = $entire ? $entire->get_sub_group() : 'N/A';
		$control   = $entire ? $entire->get_control() : 'N/A';
		$pass = ( false === $sub_group ) && ( false === $control );
		agent_test_assert(
			$suite, 'jpc-2',
			'SKILL.md "Writing a custom condition": Base::get_sub_group()/get_control() no-op defaults (both false) are what Entire inherits, unoverridden',
			$pass,
			array( 'sub_group' => false, 'control' => false ),
			array( 'sub_group' => $sub_group, 'control' => $control ),
			'includes/conditions-manager/conditions/base.php:52-54,70-72'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jpc-2', 'Base no-op defaults smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// Shared fixture setup for jpc-3/4/5: one throwaway published popup post.
	$fixture_id = null;
	try {
		$existing = get_page_by_path( 'agent-test-jetpopup-conditions-fixture', OBJECT, 'jet-popup' );
		if ( $existing ) {
			$fixture_id = $existing->ID;
		} else {
			$fixture_id = wp_insert_post( array(
				'post_type'   => 'jet-popup',
				'post_title'  => 'AGENT-TEST jetpopup-conditions fixture',
				'post_name'   => 'agent-test-jetpopup-conditions-fixture',
				'post_status' => 'publish',
			), true );
			$fixture_id = is_wp_error( $fixture_id ) ? null : $fixture_id;
		}
	} catch ( \Throwable $e ) {
		$fixture_id = null;
	}

	// jpc-3: 'and'-relation exclude filter fires with (bool, excludes_matchs, popup_id).
	try {
		if ( ! $fixture_id || ! function_exists( 'jet_popup' ) ) {
			throw new \Exception( 'fixture popup or jet_popup() unavailable' );
		}

		jet_popup()->conditions_manager->update_popup_conditions( $fixture_id, array(
			array( 'id' => '_agent_test_1', 'include' => 'false', 'group' => 'entire', 'subGroup' => 'entire', 'subGroupValue' => '' ),
		), 'and' );

		$seen = null;
		add_filter( 'jet-popup/popup-condition/is_excluded/and', function( $is_excluded, $excludes_matchs, $popup_id ) use ( &$seen ) {
			$seen = compact( 'is_excluded', 'excludes_matchs', 'popup_id' );
			return $is_excluded;
		}, 10, 3 );

		jet_popup()->conditions_manager->find_matched_popups_by_conditions();

		remove_all_filters( 'jet-popup/popup-condition/is_excluded/and' );

		$pass = is_array( $seen ) && (int) $seen['popup_id'] === (int) $fixture_id;
		agent_test_assert(
			$suite, 'jpc-3',
			'SKILL.md "The matching algorithm": jet-popup/popup-condition/is_excluded/and fires (bool, $excludes_matchs, $popup_id) from find_matched_popups_by_conditions() for an and-relation popup with an exclude condition',
			$pass,
			array( 'callback_fired_with_fixture_popup_id' => true ),
			array( 'seen' => $seen ),
			'includes/conditions-manager/manager.php:766-776'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jpc-3', 'and-relation exclude filter live test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jpc-4: 'or'-relation exclude filter also fires (the backlog only documented the 'and' variant).
	try {
		if ( ! $fixture_id || ! function_exists( 'jet_popup' ) ) {
			throw new \Exception( 'fixture popup or jet_popup() unavailable' );
		}

		jet_popup()->conditions_manager->update_popup_conditions( $fixture_id, array(
			array( 'id' => '_agent_test_1', 'include' => 'false', 'group' => 'entire', 'subGroup' => 'entire', 'subGroupValue' => '' ),
		), 'or' );

		$seen = null;
		add_filter( 'jet-popup/popup-condition/is_excluded/or', function( $is_excluded, $excludes_matchs, $popup_id ) use ( &$seen ) {
			$seen = compact( 'is_excluded', 'excludes_matchs', 'popup_id' );
			return $is_excluded;
		}, 10, 3 );

		jet_popup()->conditions_manager->find_matched_popups_by_conditions();

		remove_all_filters( 'jet-popup/popup-condition/is_excluded/or' );

		$pass = is_array( $seen ) && (int) $seen['popup_id'] === (int) $fixture_id;
		agent_test_assert(
			$suite, 'jpc-4',
			'SKILL.md "The matching algorithm": jet-popup/popup-condition/is_excluded/or (undocumented in the pre-existing gist backlog) fires with the same 3-arg shape as the and-relation variant, for an or-relation popup',
			$pass,
			array( 'callback_fired_with_fixture_popup_id' => true ),
			array( 'seen' => $seen ),
			'includes/conditions-manager/manager.php:777-788 — correction to backlog which only documented the /and hook'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jpc-4', 'or-relation exclude filter live test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jpc-5: an unresolvable condition sub-group auto-passes (match = true) rather than disqualifying the popup.
	try {
		if ( ! $fixture_id || ! function_exists( 'jet_popup' ) ) {
			throw new \Exception( 'fixture popup or jet_popup() unavailable' );
		}

		jet_popup()->conditions_manager->update_popup_conditions( $fixture_id, array(
			array( 'id' => '_agent_test_1', 'include' => 'true', 'group' => 'advanced', 'subGroup' => 'agent-test-nonexistent-condition', 'subGroupValue' => '' ),
		), 'or' );

		$matched = jet_popup()->conditions_manager->find_matched_popups_by_conditions();

		$pass = is_array( $matched ) && in_array( (int) $fixture_id, array_map( 'intval', $matched ), true );
		agent_test_assert(
			$suite, 'jpc-5',
			'SKILL.md "The matching algorithm" step 1: an unresolvable condition sub_group (get_condition() returns false) gets match=true automatically, so the popup still matches',
			$pass,
			array( 'fixture_in_matched_list' => true ),
			array( 'matched' => $matched ),
			'includes/conditions-manager/manager.php:735-741'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jpc-5', 'unresolvable condition auto-pass live test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jpc-6: JetEngine compat condition class registers only when JetEngine is active (graceful skip otherwise).
	try {
		if ( ! class_exists( 'Jet_Engine' ) ) {
			agent_test_assert(
				$suite, 'jpc-6',
				'SKILL.md "Writing a custom condition": Jet_Engine_Custom_Query_Has_Items registers as a condition when JetEngine is active',
				true,
				array( 'skipped' => 'JetEngine not active on this sandbox' ),
				array( 'skipped' => true ),
				'includes/compatibility/plugins/jet-engine/manager.php:146-156,161-165 — SKIPPED, not FAILED: JetEngine not active here'
			);
		} else {
			$cm = function_exists( 'jet_popup' ) ? jet_popup()->conditions_manager : null;
			$instance = $cm ? $cm->get_condition( 'jet-engine-custom-query-has-items' ) : false;
			agent_test_assert(
				$suite, 'jpc-6',
				'SKILL.md "Writing a custom condition": Jet_Engine_Custom_Query_Has_Items registers as a condition when JetEngine is active',
				is_object( $instance ),
				array( 'instance_is_object' => true ),
				array( 'instance_is_object' => is_object( $instance ) ),
				'includes/compatibility/plugins/jet-engine/manager.php:146-156,161-165'
			);
		}
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jpc-6', 'JetEngine compat condition reachability test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// Cleanup: remove fixture popup and its site-condition-option entry.
	try {
		if ( $fixture_id ) {
			jet_popup()->post_type->remove_popup_from_site_conditions( $fixture_id );
			wp_delete_post( $fixture_id, true );
		}
	} catch ( \Throwable $e ) {
		// Best-effort cleanup; not itself an assertion.
	}

} );
