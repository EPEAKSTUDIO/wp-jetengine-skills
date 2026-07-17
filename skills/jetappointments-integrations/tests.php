<?php
/**
 * AGENT-TEST-SUITE: jetappointments-integrations
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetappointments-integrations"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetappointments-integrations. See docs/test-harness-guide.md.
 *
 * Reachability/source-presence smoke tests plus two live-driven filter tests
 * (apbi-3 calls a real method with a fake, never-saved Appointment_Model — no DB writes,
 * no real form/checkout submission needed). No live JetFormBuilder submission or WC
 * checkout fixture exists yet on this sandbox (see TEST-REGIMEN.md Test 2).
 *
 * Safety note (HANDOFF.md "Adding a new Crocoblock plugin"): every object touched here
 * is read off the existing jet_apb() singleton, or is JET_APB\Resources\Appointment_Model
 * (confirmed a plain data object with no singleton/hook-registration side effects in its
 * constructor — see jetappointments-core/SKILL.md) — nothing here calls `new` on any
 * class that owns its own require/registration side effects.
 */

add_action( 'agent-test/run-suite/jetappointments-integrations', function() {

	$suite = 'jetappointments-integrations';

	// apbi-1: Insert_Appointment_Action is a real, correctly-shaped JetFormBuilder action class.
	try {
		$class_name = 'JET_APB\\Formbuilder_Plugin\\Actions\\Insert_Appointment_Action';
		$exists     = class_exists( $class_name );
		$is_subclass = $exists && is_subclass_of( $class_name, 'Jet_Form_Builder\\Actions\\Types\\Base' );
		$id = $name = null;
		if ( $exists ) {
			$instance = new $class_name();
			$id   = $instance->get_id();
			$name = $instance->get_name();
		}
		$pass = $exists && $is_subclass && 'insert_appointment' === $id;
		agent_test_assert(
			$suite, 'apbi-1',
			'SKILL.md "The booking form action is a real JetFormBuilder custom action": Insert_Appointment_Action extends Jet_Form_Builder\\Actions\\Types\\Base and get_id() returns "insert_appointment"',
			$pass,
			array( 'class_exists' => true, 'is_subclass_of_base' => true, 'get_id' => 'insert_appointment' ),
			array( 'class_exists' => $exists, 'is_subclass_of_base' => $is_subclass, 'get_id' => $id, 'get_name' => $name ),
			'includes/formbuilder-plugin/actions/insert-appointment-action.php:19,62-71'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'apbi-1', 'Insert_Appointment_Action class shape smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// apbi-2: WC_Integration gating — hooks are only wired when WooCommerce is active AND the
	// plugin's own WC feature + linked product are configured; degrades gracefully if WC is absent.
	try {
		$wc = function_exists( 'jet_apb' ) ? jet_apb()->wc : null;
		$class = $wc ? get_class( $wc ) : null;
		$has_wc = $wc && method_exists( $wc, 'has_woocommerce' ) ? $wc->has_woocommerce() : null;
		$hook_priority = ( $wc && $has_wc )
			? has_action( 'jet-apb/jet-fb/action/success', array( $wc, 'process_wc_notification' ) )
			: null;
		// Pass condition: class reachable, AND (WC inactive => hook NOT registered) OR (WC active => consistent, don't require it be fully "on").
		$pass = ( 'JET_APB\\WC_Integration' === $class )
			&& ( ( false === $has_wc && false === $hook_priority ) || ( true === $has_wc ) );
		agent_test_assert(
			$suite, 'apbi-2',
			'SKILL.md "WooCommerce integration": jet_apb()->wc is a WC_Integration instance; process_wc_notification is only hooked to jet-apb/jet-fb/action/success when has_woocommerce() is true (gated, not unconditional)',
			$pass,
			array( 'class' => 'JET_APB\\WC_Integration', 'gating_consistent' => true ),
			array( 'class' => $class, 'has_woocommerce' => $has_wc, 'hook_priority_if_checked' => $hook_priority ),
			'includes/wc-integration.php:25-66 (constructor early-return at :27-30 if !has_woocommerce(), hook added at :43)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'apbi-2', 'WC_Integration gating smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// apbi-3: jet-appointment/wc-integration/pre-cart-info fires with 4 args and can short-circuit
	// the default formatting — live-driven with a fake, never-saved Appointment_Model (no DB writes).
	try {
		$wc = function_exists( 'jet_apb' ) ? jet_apb()->wc : null;
		if ( ! $wc || ! method_exists( $wc, 'get_formatted_appointment_info' ) ) {
			throw new \Exception( 'jet_apb()->wc->get_formatted_appointment_info not available' );
		}
		if ( ! class_exists( 'JET_APB\\Resources\\Appointment_Model' ) ) {
			throw new \Exception( 'Appointment_Model class not available' );
		}
		$fake_appointment = new \JET_APB\Resources\Appointment_Model( array(
			'service'  => 0,
			'provider' => 0,
			'date'     => 0,
			'slot'     => 0,
		) );

		$seen_args = null;
		add_filter( 'jet-appointment/wc-integration/pre-cart-info', function( $pre, $data, $form_data, $form_id ) use ( &$seen_args ) {
			$seen_args = array(
				'pre_default'      => $pre,
				'data_is_model'    => ( $data instanceof \JET_APB\Resources\Appointment_Model ),
				'form_data'        => $form_data,
				'form_id'          => $form_id,
			);
			return 'AGENT_TEST_MARKER_' . uniqid();
		}, 10, 4 );

		$result = $wc->get_formatted_appointment_info( $fake_appointment, array( 'agent_test' => true ), 12345 );
		remove_all_filters( 'jet-appointment/wc-integration/pre-cart-info' );

		$pass = is_array( $seen_args )
			&& false === $seen_args['pre_default']
			&& true === $seen_args['data_is_model']
			&& 12345 === $seen_args['form_id']
			&& is_string( $result ) && 0 === strpos( $result, 'AGENT_TEST_MARKER_' );

		agent_test_assert(
			$suite, 'apbi-3',
			'SKILL.md "WooCommerce integration" pre-cart-info: get_formatted_appointment_info() fires apply_filters(\'jet-appointment/wc-integration/pre-cart-info\', false, $data, $form_data, $form_id) and returns the filter\'s truthy value directly, short-circuiting default formatting',
			$pass,
			array( 'seen_args_shape' => array( 'pre_default' => false, 'data_is_model' => true, 'form_id' => 12345 ), 'short_circuit_return' => true ),
			array( 'seen_args' => $seen_args, 'result' => $result ),
			'includes/wc-integration.php:469-478'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'apbi-3', 'pre-cart-info live filter test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
