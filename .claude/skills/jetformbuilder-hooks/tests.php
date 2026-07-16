<?php
/**
 * AGENT-TEST-SUITE: jetformbuilder-hooks
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetformbuilder-hooks"),
 * alongside the always-active AGENT-TEST-CORE harness. Run via
 * GET /agent-test/v1/suite/jetformbuilder-hooks. See docs/test-harness-guide.md.
 *
 * The 2026-07-15 TEST-REGIMEN.md run already did a real end-to-end curl submission
 * against a throwaway form/page to confirm firing order/arg counts for the submission
 * lifecycle hooks — that isn't re-automated here (it needs a live form to submit against
 * every run, which isn't a safe repeatable fixture). What IS safe to automate without any
 * form submission: the Call Hook action's do_action() can be invoked directly (its
 * `$settings` property is public), and Action_Exception's success/status wiring is
 * fully self-contained. Both are exercised directly below, within this one request.
 */

add_action( 'agent-test/run-suite/jetformbuilder-hooks', function() {

	$suite = 'jetformbuilder-hooks';

	// hooks-1: is_success() is true ONLY for the literal string 'success' — not any
	// truthy/non-empty message, and not an empty message either.
	try {
		$mk = function( $msg ) {
			return new \Jet_Form_Builder\Exceptions\Action_Exception( $msg );
		};
		$results = array(
			'success'            => $mk( 'success' )->is_success(),
			'Success (case)'     => $mk( 'Success' )->is_success(),
			'custom_test_status' => $mk( 'custom_test_status' )->is_success(),
			'' /* empty */       => $mk( '' )->is_success(),
		);
		$pass = ( true === $results['success'] )
			&& ( false === $results['Success (case)'] )
			&& ( false === $results['custom_test_status'] )
			&& ( false === $results[''] );
		agent_test_assert(
			$suite, 'hooks-1',
			'SKILL.md gotcha: "a thrown exception can still register as success depending on how it\'s constructed" — pinned down exactly: is_success() is true only for the literal, case-sensitive string "success"',
			$pass,
			array( 'success' => true, 'Success (wrong case)' => false, 'custom_test_status' => false, 'empty string' => false ),
			$results,
			'includes/form-messages/status-info.php:63-77 (in_array($message[0], array(\'success\'), true) — strict comparison, case-sensitive)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'hooks-1', 'literal-success-string smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// hooks-2: dynamic_success()/dynamic_error() are the REAL mechanism for making an
	// exception with an arbitrary custom message still count as success/failure — this is
	// what actually backs the "can still register as success" gotcha, not documented by
	// name in SKILL.md yet.
	try {
		$ex = ( new \Jet_Form_Builder\Exceptions\Action_Exception( 'agent_test_custom_message' ) )->dynamic_success();
		$err_ex = ( new \Jet_Form_Builder\Exceptions\Action_Exception( 'agent_test_custom_message' ) )->dynamic_error();
		$pass = ( true === $ex->is_success() ) && ( false === $err_ex->is_success() );
		agent_test_assert(
			$suite, 'hooks-2',
			'Addition to SKILL.md: Handler_Exception::dynamic_success()/dynamic_error() prefix the message ("dsuccess|"/"derror|") so is_success() reports true/false for an ARBITRARY custom message, bypassing the literal-"success"-string restriction from hooks-1',
			$pass,
			array( 'dynamic_success_is_success' => true, 'dynamic_error_is_success' => false ),
			array( 'dynamic_success_is_success' => $ex->is_success(), 'dynamic_error_is_success' => $err_ex->is_success() ),
			'includes/exceptions/handler-exception.php:42-52 (dynamic_success()/dynamic_error()), includes/form-messages/manager.php:19-20,84-98 (DYNAMIC_SUCCESS_PREF="dsuccess|", dynamic_types())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'hooks-2', 'dynamic_success()/dynamic_error() smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// hooks-3: Call Hook action's do_action() fires both the custom-action hook (2 args)
	// and the custom-filter (3 args, return value controls hook_result) with the exact
	// signatures SKILL.md documents — invoked directly, no real form submission needed.
	try {
		if ( ! class_exists( '\\JFB_Modules\\Actions_V2\\Call_Hook\\Call_Hook_Action' ) || ! function_exists( 'jet_fb_action_handler' ) ) {
			throw new \Exception( 'Call_Hook_Action or jet_fb_action_handler() not available' );
		}
		$hook_name = 'agent_test_hook_' . wp_generate_password( 6, false );
		$action_args = null;
		$filter_args = null;
		add_action( "jet-form-builder/custom-action/{$hook_name}", function( $request, $handler ) use ( &$action_args ) {
			$action_args = array( 'request' => $request, 'handler_class' => get_class( $handler ) );
		}, 10, 2 );
		add_filter( "jet-form-builder/custom-filter/{$hook_name}", function( $result, $request, $handler ) use ( &$filter_args ) {
			$filter_args = array( 'result' => $result, 'request' => $request, 'handler_class' => get_class( $handler ) );
			return false; // force this step to report failure, to confirm the return value is honored
		}, 10, 3 );

		$call_hook = new \JFB_Modules\Actions_V2\Call_Hook\Call_Hook_Action();
		$call_hook->settings = array( 'hook_name' => $hook_name );
		$handler = jet_fb_action_handler();
		$fake_request = array( 'agent_test_field' => 'agent_test_value' );
		$call_hook->do_action( $fake_request, $handler );

		$pass = is_array( $action_args ) && $action_args['request'] === $fake_request
			&& is_array( $filter_args ) && true === $filter_args['result'] && $filter_args['request'] === $fake_request
			&& isset( $handler->response_data['hook_result'] ) && false === $handler->response_data['hook_result'];

		agent_test_assert(
			$suite, 'hooks-3',
			'SKILL.md "Hooking a form action step": Call Hook fires jet-form-builder/custom-action/{hook_name} ($request, $handler) then jet-form-builder/custom-filter/{hook_name} (true, $request, $handler) and stores the filter\'s return value in $handler->response_data[\'hook_result\']',
			$pass,
			array( 'action_fired_with_request' => true, 'filter_fired_with_default_true' => true, 'hook_result_reflects_filter_return' => false ),
			array( 'action_args' => $action_args, 'filter_args' => $filter_args, 'hook_result' => $handler->response_data['hook_result'] ?? 'unset' ),
			'modules/actions-v2/call-hook/call-hook-action.php:36-68'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'hooks-3', 'Call Hook do_action() direct-invocation smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
