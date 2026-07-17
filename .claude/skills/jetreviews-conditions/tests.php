<?php
/**
 * AGENT-TEST-SUITE: jetreviews-conditions
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetreviews-conditions"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetreviews-conditions. See docs/test-harness-guide.md.
 *
 * NOT YET RUN: JetReviews For Elementor is not installed on the sandbox
 * (jackfruit.epeak.studio) as of this writing — see TEST-REGIMEN.md. Written as-if-ready.
 * Only jet_reviews()->user_manager (the live instance) is ever read here — never
 * `new \Jet_Reviews\User\Manager()` / its own ::get_instance(), per SKILL.md's landmine
 * (its constructor's register_conditions()/register_verifications() both do an
 * unconditional `require` of already-declared class files — a second construction would
 * be an uncatchable "Cannot redeclare class" fatal). Individual Condition/Verification
 * subclass instances (Already_Reviewed, etc.) ARE safe to `new` directly — confirmed by
 * reading their files, none do any `require`/file-loading in their own constructors.
 */

add_action( 'agent-test/run-suite/jetreviews-conditions', function() {

	$suite = 'jetreviews-conditions';

	// jrc-1: the 4 built-in conditions are registered on the live User\Manager instance, all type 'can-review'.
	try {
		$user_manager = function_exists( 'jet_reviews' ) ? jet_reviews()->user_manager : null;
		$conditions = $user_manager ? $user_manager->registered_conditions : array();
		$expected_slugs = array( 'user-guest', 'user-role', 'moderator-check', 'already-reviewed' );
		$found_slugs = is_array( $conditions ) ? array_keys( $conditions ) : array();
		$all_can_review = true;
		foreach ( $expected_slugs as $slug ) {
			if ( ! isset( $conditions[ $slug ] ) || 'can-review' !== $conditions[ $slug ]->get_type() ) {
				$all_can_review = false;
			}
		}
		$pass = empty( array_diff( $expected_slugs, $found_slugs ) ) && $all_can_review;
		agent_test_assert(
			$suite, 'jrc-1',
			'SKILL.md "Conditions: gate...": jet_reviews()->user_manager->registered_conditions contains user-guest, user-role, moderator-check, already-reviewed, each reporting get_type() === "can-review"',
			$pass,
			array( 'slugs' => $expected_slugs, 'all_type_can_review' => true ),
			array( 'found_slugs' => $found_slugs, 'all_type_can_review' => $all_can_review ),
			'includes/components/user/manager.php:61-78 (register_conditions() defaults), each condition file\'s get_type() returning "can-review"'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jrc-1', 'built-in conditions registry smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jrc-2: the 1 built-in verification is registered.
	try {
		$user_manager = function_exists( 'jet_reviews' ) ? jet_reviews()->user_manager : null;
		$verifications = $user_manager ? $user_manager->registered_verifications : array();
		$pass = is_array( $verifications ) && array_key_exists( 'guest-user', $verifications );
		agent_test_assert(
			$suite, 'jrc-2',
			'SKILL.md "Verifications...": jet_reviews()->user_manager->registered_verifications contains the built-in "guest-user" slug',
			$pass,
			array( 'has_guest_user' => true ),
			array( 'found_slugs' => is_array( $verifications ) ? array_keys( $verifications ) : null ),
			'includes/components/user/manager.php:117-131 (register_verifications() default), includes/components/user/verifications/guest-user.php:15'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jrc-2', 'built-in verifications registry smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jrc-3: is_user_can_review() with an empty $user_data returns the "everything passes" base-case shape.
	try {
		$user_manager = function_exists( 'jet_reviews' ) ? jet_reviews()->user_manager : null;
		$result = $user_manager ? $user_manager->is_user_can_review( false, array() ) : null;
		$pass = is_array( $result )
			&& true === $result['allowed']
			&& 'can_review' === $result['code'];
		agent_test_assert(
			$suite, 'jrc-3',
			'SKILL.md "is_user_can_review()...": called with empty $user_data, returns { allowed: true, code: "can_review", message: "*Publish your review" } immediately, without consulting any registered condition',
			$pass,
			array( 'allowed' => true, 'code' => 'can_review' ),
			array( 'result' => $result ),
			'includes/components/user/manager.php:231-239 (empty $user_data early-return branch)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jrc-3', 'is_user_can_review() base-case smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jrc-4: the single-quoted un-interpolated hook-name bug is a real, literal source string in all 4 condition
	// files + the 1 verification file (source-grep, confirms the currently-installed version still has the bug
	// before relying on the "hook the broken literal string" workaround documented in SKILL.md).
	try {
		$checks = array(
			array( 'includes/components/user/conditions/already-reviewed.php', "'jet-reviews/user/conditions/invalid-message/{\$this->slug}'" ),
			array( 'includes/components/user/conditions/moderator-check.php', "'jet-reviews/user/conditions/invalid-message/{\$this->slug}'" ),
			array( 'includes/components/user/conditions/user-guest.php', "'jet-reviews/user/conditions/invalid-message/{\$this->slug}'" ),
			array( 'includes/components/user/conditions/user-role.php', "'jet-reviews/user/conditions/invalid-message/{\$this->slug}'" ),
			array( 'includes/components/user/verifications/guest-user.php', "'jet-reviews/user/verification/successful-icon/{\$this->slug}'" ),
			array( 'includes/components/user/verifications/guest-user.php', "'jet-reviews/user/verification/successful-message/{\$this->slug}'" ),
		);
		$results = array();
		$all_pass = true;
		foreach ( $checks as $c ) {
			list( $rel_path, $needle ) = $c;
			$file = WP_PLUGIN_DIR . '/jet-reviews/' . $rel_path;
			$contents = file_exists( $file ) ? file_get_contents( $file ) : '';
			$found = ( '' !== $contents ) && ( false !== strpos( $contents, $needle ) );
			$results[ $rel_path . '::' . $needle ] = $found;
			$all_pass = $all_pass && $found;
		}
		agent_test_assert(
			$suite, 'jrc-4',
			'SKILL.md "Confirmed real bug...": the literal single-quoted, un-interpolated hook name string is present verbatim in all 4 condition files\' get_invalid_message() and both Guest_User verification hooks',
			$all_pass,
			array( 'all_literal_strings_present' => true ),
			array( 'per_file' => $results ),
			'user/conditions/{already-reviewed,moderator-check,user-guest,user-role}.php:50, user/verifications/guest-user.php:65,73 — source-presence check only, see jrc-5 for a live-fired confirmation'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jrc-4', 'un-interpolated hook-name source-presence check', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jrc-5: the broken literal hook name nonetheless genuinely fires — driven live on a standalone
	// Already_Reviewed instance (safe to `new` directly: confirmed no require calls in its own constructor,
	// unlike User\Manager itself).
	try {
		if ( ! class_exists( '\\Jet_Reviews\\User\\Conditions\\Already_Reviewed' ) ) {
			throw new \Exception( 'Already_Reviewed class not loaded' );
		}
		$condition = new \Jet_Reviews\User\Conditions\Already_Reviewed();
		$seen_slug = null;
		// Deliberately using the literal, broken (un-interpolated) hook name string, per SKILL.md.
		add_filter( 'jet-reviews/user/conditions/invalid-message/{$this->slug}', function( $message, $cond ) use ( &$seen_slug ) {
			$seen_slug = $cond->get_slug();
			return 'AGENT_TEST_OVERRIDE';
		}, 10, 2 );
		$message = $condition->get_invalid_message();
		remove_all_filters( 'jet-reviews/user/conditions/invalid-message/{$this->slug}' );
		$pass = ( 'AGENT_TEST_OVERRIDE' === $message ) && ( 'already-reviewed' === $seen_slug );
		agent_test_assert(
			$suite, 'jrc-5',
			'SKILL.md "the only way to actually intercept...": hooking the literal broken string \'jet-reviews/user/conditions/invalid-message/{$this->slug}\' verbatim DOES fire and can override the message, disambiguating via the 2nd arg\'s get_slug()',
			$pass,
			array( 'message' => 'AGENT_TEST_OVERRIDE', 'seen_slug' => 'already-reviewed' ),
			array( 'message' => $message, 'seen_slug' => $seen_slug ),
			'includes/components/user/conditions/already-reviewed.php:49-51 (get_invalid_message()) — Already_Reviewed is safe to `new` directly (no nested require in its constructor), unlike User\\Manager'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jrc-5', 'broken-literal-hook-name live-fire smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
