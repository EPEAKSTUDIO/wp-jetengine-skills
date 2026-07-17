<?php
/**
 * AGENT-TEST-SUITE: jetformbuilder-payment-gateways
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetformbuilder-payment-gateways"),
 * alongside the always-active AGENT-TEST-CORE harness. Run via
 * GET /agent-test/v1/suite/jetformbuilder-payment-gateways. See docs/test-harness-guide.md.
 *
 * No real PayPal/Stripe credentials or live checkout exist on this sandbox, so this
 * suite deliberately does NOT attempt a real payment flow (see SKILL.md's "Not yet
 * automated" section). Instead it focuses on: architecture/class reachability (including
 * the Jet_Form_Builder\Gateways\* -> JFB_Modules\Gateways\* autoloader alias), a real
 * Payment_Model DB CRUD round-trip against the custom jet_fb_payments table (fabricated
 * row, cleaned up in the same request), and the GATEWAY.SUCCESS/GATEWAY.FAILED Action
 * Event firing mechanism driven directly via the executor classes (not a full form
 * submission), same "direct hook/class test" pattern HANDOFF.md documents for the
 * jetformbuilder-actions/jetformbuilder-hooks suites.
 */

add_action( 'agent-test/run-suite/jetformbuilder-payment-gateways', function() {

	$suite = 'jetformbuilder-payment-gateways';

	// pg-1: the Jet_Form_Builder\Gateways\* namespace used throughout the gateway source
	// (base-scenario-gateway.php, paypal/controller.php, ...) has no real class defined in
	// it -- Autoloader::DEPRECATED_CLASSMAP aliases it to the real JFB_Modules\Gateways\*
	// class via class_alias(), confirmed here by resolving both names to the same
	// underlying class via ReflectionClass::getName().
	try {
		if ( ! class_exists( '\\Jet_Form_Builder\\Gateways\\Base_Gateway' ) ) {
			throw new \Exception( 'Jet_Form_Builder\\Gateways\\Base_Gateway alias did not resolve' );
		}
		$alias_reflection = new \ReflectionClass( '\\Jet_Form_Builder\\Gateways\\Base_Gateway' );
		$real_name        = $alias_reflection->getName();

		$scenario_alias_ok = class_exists( '\\Jet_Form_Builder\\Gateways\\Base_Scenario_Gateway' )
			&& is_subclass_of( '\\Jet_Form_Builder\\Gateways\\Base_Scenario_Gateway', '\\JFB_Modules\\Gateways\\Base_Gateway' );

		$pass = ( 'JFB_Modules\\Gateways\\Base_Gateway' === $real_name ) && $scenario_alias_ok;

		agent_test_assert(
			$suite, 'pg-1',
			'SKILL.md "The namespace-alias landmine": Jet_Form_Builder\\Gateways\\Base_Gateway (and \\Base_Scenario_Gateway) are not real class definitions -- Autoloader::DEPRECATED_CLASSMAP aliases them via class_alias() to the real JFB_Modules\\Gateways\\* classes',
			$pass,
			array( 'resolved_class' => 'JFB_Modules\\Gateways\\Base_Gateway', 'scenario_gateway_is_subclass_of_base_gateway' => true ),
			array( 'resolved_class' => $real_name, 'scenario_gateway_is_subclass_of_base_gateway' => $scenario_alias_ok ),
			'includes/autoloader.php:44-45,158-183 (DEPRECATED_CLASSMAP + autoload()), modules/gateways/base-scenario-gateway.php:19'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'pg-1', 'namespace-alias smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// pg-2: Base_Gateway's default method shapes -- a minimal concrete subclass only
	// implementing the 3 abstract methods (get_id/get_name/retrieve_gateway_meta) still
	// gets sane defaults for custom_labels()/additional_editor_data()/
	// required_credentials_fields()/get_payment().
	try {
		if ( ! class_exists( 'Agent_Test_Minimal_Gateway' ) ) {
			class Agent_Test_Minimal_Gateway extends \Jet_Form_Builder\Gateways\Base_Gateway {
				public function get_id() { return 'agent_test_gateway'; }
				public function get_name() { return 'Agent Test Gateway'; }
				protected function retrieve_gateway_meta() { return array(); }
			}
		}
		$gateway = new Agent_Test_Minimal_Gateway();

		$pass = ( array() === $gateway->custom_labels() )
			&& ( array( 'version' => 0 ) === $gateway->additional_editor_data() )
			&& ( array() === $gateway->required_credentials_fields() )
			&& ( array() === $gateway->get_payment() );

		agent_test_assert(
			$suite, 'pg-2',
			'SKILL.md "Registering a gateway": a minimal Base_Gateway subclass (only get_id()/get_name()/retrieve_gateway_meta() implemented) gets custom_labels() => [], additional_editor_data() => ["version"=>0], required_credentials_fields() => [], get_payment() => [] by default',
			$pass,
			array( 'custom_labels' => array(), 'additional_editor_data' => array( 'version' => 0 ), 'required_credentials_fields' => array(), 'get_payment' => array() ),
			array( 'custom_labels' => $gateway->custom_labels(), 'additional_editor_data' => $gateway->additional_editor_data(), 'required_credentials_fields' => $gateway->required_credentials_fields(), 'get_payment' => $gateway->get_payment() ),
			'modules/gateways/base-gateway.php:52-88,381-383'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'pg-2', 'Base_Gateway default-method smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// pg-3: Payment_Model's table()/schema_columns() shape -- table() resolves to
	// $wpdb->prefix . 'jet_fb_' . 'payments', and the schema has the documented columns.
	try {
		global $wpdb;
		if ( ! class_exists( '\\JFB_Modules\\Gateways\\Db_Models\\Payment_Model' ) ) {
			throw new \Exception( 'Payment_Model not loaded' );
		}
		$expected_table = $wpdb->prefix . 'jet_fb_payments';
		$real_table     = \JFB_Modules\Gateways\Db_Models\Payment_Model::table();
		$schema_cols    = array_keys( \JFB_Modules\Gateways\Db_Models\Payment_Model::schema() );

		$expected_cols = array( 'id', 'transaction_id', 'initial_transaction_id', 'form_id', 'user_id', 'gateway_id', 'scenario', 'amount_value', 'amount_code', 'type', 'status', 'created_at', 'updated_at' );

		$pass = ( $expected_table === $real_table ) && ( $expected_cols === $schema_cols );

		agent_test_assert(
			$suite, 'pg-3',
			'SKILL.md "The payments data model": Payment_Model::table() === $wpdb->prefix."jet_fb_payments" (Base_Db_Model::DB_TABLE_PREFIX), schema() column list matches documented shape',
			$pass,
			array( 'table' => $expected_table, 'columns' => $expected_cols ),
			array( 'table' => $real_table, 'columns' => $schema_cols ),
			'modules/gateways/db-models/payment-model.php:18-40, includes/db-queries/base-db-model.php:17,323-325'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'pg-3', 'Payment_Model schema/table smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// pg-4: Payment_Model CRUD round-trip against the real wp_jet_fb_payments table --
	// create() the table (idempotent, safe_create() no-ops if it already exists), insert
	// one AGENT-TEST-namespaced fabricated row, verify it reads back correctly via a
	// direct $wpdb read, then delete it in the same request (try/finally so a failed
	// assertion still cleans up).
	$inserted_payment_id = null;
	try {
		global $wpdb;
		if ( ! class_exists( '\\JFB_Modules\\Gateways\\Db_Models\\Payment_Model' ) ) {
			throw new \Exception( 'Payment_Model not loaded' );
		}
		$model = new \JFB_Modules\Gateways\Db_Models\Payment_Model();
		$model->create();

		$fabricated = array(
			'transaction_id' => 'AGENT-TEST-TXN-' . time(),
			'form_id'        => 999999,
			'gateway_id'     => 'agent_test_gateway',
			'scenario'       => 'agent_test_scenario',
			'amount_value'   => 12.34,
			'amount_code'    => 'USD',
			'type'           => 'initial',
			'status'         => 'AGENT_TEST_STATUS',
			'updated_at'     => current_time( 'mysql' ),
		);

		$inserted_payment_id = $model->insert( $fabricated );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$model::table()}` WHERE id = %d", $inserted_payment_id ),
			ARRAY_A
		);

		$pass = $inserted_payment_id > 0
			&& is_array( $row )
			&& $row['transaction_id'] === $fabricated['transaction_id']
			&& (float) $row['amount_value'] === 12.34
			&& $row['status'] === 'AGENT_TEST_STATUS';

		agent_test_assert(
			$suite, 'pg-4',
			'SKILL.md "CRUD": Payment_Model::insert() writes a real row to wp_jet_fb_payments; the row reads back with the same fabricated values (fresh table auto-created via ->create() if not already present)',
			$pass,
			array( 'inserted_id_gt_0' => true, 'transaction_id' => $fabricated['transaction_id'], 'amount_value' => 12.34, 'status' => 'AGENT_TEST_STATUS' ),
			array( 'inserted_id' => $inserted_payment_id, 'row' => $row ),
			'modules/gateways/db-models/payment-model.php, includes/db-queries/base-db-model.php:93-97,155-165, includes/db-queries/execution-builder.php:82-120'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'pg-4', 'Payment_Model CRUD round-trip smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	} finally {
		if ( $inserted_payment_id ) {
			global $wpdb;
			$wpdb->delete( \JFB_Modules\Gateways\Db_Models\Payment_Model::table(), array( 'id' => $inserted_payment_id ) );
		}
	}

	// pg-5: Base_Db_Model::insert() fires the table-scoped filter/action pair --
	// jet-form-builder/db/payments/before-insert (1-arg filter: $columns) and
	// jet-form-builder/db/payments/after-insert (2-arg action: $id, $columns) -- alongside
	// the generic (non-table-scoped) pair. Fabricates and cleans up its own row.
	$pg5_payment_id = null;
	try {
		if ( ! class_exists( '\\JFB_Modules\\Gateways\\Db_Models\\Payment_Model' ) ) {
			throw new \Exception( 'Payment_Model not loaded' );
		}
		$model = new \JFB_Modules\Gateways\Db_Models\Payment_Model();
		$model->create();

		$seen_before_columns = null;
		$seen_after_id       = null;
		$seen_after_columns  = null;

		$before_cb = function( $columns ) use ( &$seen_before_columns ) {
			$seen_before_columns = $columns;
			return $columns;
		};
		$after_cb = function( $id, $columns ) use ( &$seen_after_id, &$seen_after_columns ) {
			$seen_after_id      = $id;
			$seen_after_columns = $columns;
		};

		add_filter( 'jet-form-builder/db/payments/before-insert', $before_cb, 10, 1 );
		add_action( 'jet-form-builder/db/payments/after-insert', $after_cb, 10, 2 );

		$fabricated = array(
			'transaction_id' => 'AGENT-TEST-TXN-HOOKS-' . time(),
			'form_id'        => 999999,
			'gateway_id'     => 'agent_test_gateway',
			'status'         => 'AGENT_TEST_STATUS',
			'updated_at'     => current_time( 'mysql' ),
		);

		$pg5_payment_id = $model->insert( $fabricated );

		remove_filter( 'jet-form-builder/db/payments/before-insert', $before_cb, 10 );
		remove_action( 'jet-form-builder/db/payments/after-insert', $after_cb, 10 );

		$pass = is_array( $seen_before_columns )
			&& $seen_before_columns['transaction_id'] === $fabricated['transaction_id']
			&& $seen_after_id === $pg5_payment_id
			&& is_array( $seen_after_columns )
			&& $seen_after_columns['transaction_id'] === $fabricated['transaction_id'];

		agent_test_assert(
			$suite, 'pg-5',
			'SKILL.md "CRUD": Payment_Model::insert() fires the table-scoped jet-form-builder/db/payments/before-insert filter (1 arg: $columns) and jet-form-builder/db/payments/after-insert action (2 args: $id, $columns)',
			$pass,
			array( 'before_columns_seen' => true, 'after_id_matches_inserted_id' => true, 'after_columns_seen' => true ),
			array( 'seen_before_columns' => $seen_before_columns, 'seen_after_id' => $seen_after_id, 'inserted_id' => $pg5_payment_id, 'seen_after_columns' => $seen_after_columns ),
			'includes/db-queries/base-db-model.php:155-165'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'pg-5', 'table-scoped insert hooks smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	} finally {
		if ( $pg5_payment_id ) {
			global $wpdb;
			$wpdb->delete( \JFB_Modules\Gateways\Db_Models\Payment_Model::table(), array( 'id' => $pg5_payment_id ) );
		}
	}

	// pg-6: GATEWAY.SUCCESS / GATEWAY.FAILED are real, always-registered Events on the
	// Events_Manager singleton, with the documented ids and ignored_executors() list.
	try {
		if ( ! function_exists( 'jet_fb_events' ) ) {
			throw new \Exception( 'jet_fb_events() not available' );
		}
		$success_event = new \Jet_Form_Builder\Actions\Events\Gateway_Success\Gateway_Success_Event();
		$failed_event  = new \Jet_Form_Builder\Actions\Events\Gateway_Failed\Gateway_Failed_Event();

		$success_ignored = $success_event->ignored_executors();
		$failed_ignored  = $failed_event->ignored_executors();

		$manager_has_both = false;
		try {
			$manager_has_both = jet_fb_events()->rep_get_item( 'GATEWAY.SUCCESS' ) instanceof \Jet_Form_Builder\Actions\Events\Gateway_Success\Gateway_Success_Event
				&& jet_fb_events()->rep_get_item( 'GATEWAY.FAILED' ) instanceof \Jet_Form_Builder\Actions\Events\Gateway_Failed\Gateway_Failed_Event;
		} catch ( \Throwable $inner ) {
			$manager_has_both = false;
		}

		$pass = ( 'GATEWAY.SUCCESS' === $success_event->get_id() )
			&& ( 'GATEWAY.FAILED' === $failed_event->get_id() )
			&& in_array( \Jet_Form_Builder\Actions\Events\Default_Process\Default_Process_Executor::class, $success_ignored, true )
			&& in_array( \Jet_Form_Builder\Actions\Events\Default_Process\Default_Process_Executor::class, $failed_ignored, true )
			&& $manager_has_both;

		agent_test_assert(
			$suite, 'pg-6',
			'SKILL.md "GATEWAY.SUCCESS/GATEWAY.FAILED": both events are registered on the Events_Manager singleton (always, not gateway-conditional) with ids GATEWAY.SUCCESS/GATEWAY.FAILED, and both list Default_Process_Executor in ignored_executors() so a gateway-scoped action step is excluded from the ordinary DEFAULT.PROCESS run',
			$pass,
			array( 'success_id' => 'GATEWAY.SUCCESS', 'failed_id' => 'GATEWAY.FAILED', 'default_process_executor_ignored' => true, 'registered_on_manager' => true ),
			array( 'success_id' => $success_event->get_id(), 'failed_id' => $failed_event->get_id(), 'success_ignored' => $success_ignored, 'failed_ignored' => $failed_ignored, 'manager_has_both' => $manager_has_both ),
			'includes/actions/events/gateway-success/gateway-success-event.php:17-47, includes/actions/events/gateway-failed/gateway-failed-event.php:17-47, includes/actions/events-manager.php (rep_instances() registers both)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'pg-6', 'GATEWAY.SUCCESS/FAILED registration smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// pg-7: Gateway_Base_Executor::before_execute() fires
	// jet-form-builder/gateways/on-payment-{success|failed} -- called directly on a fresh
	// executor instance (no set_event()/full execute() loop needed), since
	// Base_Executor::validate_actions() no-ops when jet_fb_action_handler()->get_all()
	// is empty (no live form submission in progress during this REST-triggered request).
	//
	// This hook has a REAL third-party listener on this site: Jet Appointments Booking's
	// JET_APB\Formbuilder_Plugin\Gateway_Manager::on_gateway_success() -- a genuine,
	// live-discovered cross-plugin fragility bug (see SKILL.md Gotchas): that listener
	// type-hints its $gateway param as the non-nullable Base_Gateway, but
	// jet_fb_gateway_current() legitimately returns `false` outside a real payment
	// request, so firing this hook for real fatals with a TypeError. To test JFB's own
	// firing mechanism without tripping that unrelated plugin's bug, this test saves and
	// temporarily clears the hook's real registered callbacks (global $wp_filter, scoped
	// to this one request only, fully restored in a finally block -- $wp_filter is
	// rebuilt from scratch on every request anyway, so this has no effect beyond the
	// current response), fires before_execute() against a clean hook, then restores
	// exactly what was there before.
	try {
		if ( ! class_exists( '\\Jet_Form_Builder\\Actions\\Events\\Gateway_Success\\Gateway_Success_Executor' ) ) {
			throw new \Exception( 'Gateway_Success_Executor not loaded' );
		}
		$executor      = new \Jet_Form_Builder\Actions\Events\Gateway_Success\Gateway_Success_Executor();
		$fired_gateway = 'NOT_FIRED';
		$hook_name     = 'jet-form-builder/gateways/on-payment-success';

		global $wp_filter;
		$saved_hook_object = isset( $wp_filter[ $hook_name ] ) ? clone $wp_filter[ $hook_name ] : null;
		unset( $GLOBALS['wp_filter'][ $hook_name ] );

		$cb = function( $gateway ) use ( &$fired_gateway ) {
			$fired_gateway = $gateway;
		};
		add_action( $hook_name, $cb, 10, 1 );

		try {
			$executor->before_execute();
		} finally {
			unset( $GLOBALS['wp_filter'][ $hook_name ] );
			if ( $saved_hook_object ) {
				$GLOBALS['wp_filter'][ $hook_name ] = $saved_hook_object;
			}
		}

		// jet_fb_gateway_current() resolves to false when no gateway is configured for the
		// (nonexistent, in this REST context) current form -- get_current_gateway_controller_or_die()
		// catches Repository_Exception and returns false rather than actually dying
		// (module.php:319-325), so "false" is the expected/correct arg here, not a bug.
		$pass = ( false === $fired_gateway ) && ( 'success' === $executor->get_gateway_type() );

		agent_test_assert(
			$suite, 'pg-7',
			'SKILL.md "GATEWAY.SUCCESS/GATEWAY.FAILED" firing: Gateway_Base_Executor::before_execute() fires do_action("jet-form-builder/gateways/on-payment-{type}", jet_fb_gateway_current()) -- confirmed for the success executor (get_gateway_type() === "success"); jet_fb_gateway_current() correctly resolves to false (not a fatal) when no gateway is configured for the current request. (Real third-party listeners on this hook are temporarily unhooked and restored around the call -- see comment above; do not remove this isolation without re-reading it.)',
			$pass,
			array( 'hook_fired_with_gateway_current_value' => false, 'get_gateway_type' => 'success' ),
			array( 'fired_gateway' => $fired_gateway, 'get_gateway_type' => $executor->get_gateway_type() ),
			'includes/actions/events/gateway-base-executor.php:15-22, includes/actions/events/gateway-success/gateway-success-executor.php:13-19, includes/functions.php:76-78, modules/gateways/module.php:319-325 (get_current_gateway_controller_or_die() catches Repository_Exception, returns false)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'pg-7', 'Gateway_Base_Executor::before_execute() firing smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// pg-8: Gateway_Exception shares Handler_Exception's is_success()/get_form_status()/
	// dynamic_error() mechanics with Action_Exception (jetformbuilder-actions' act-2) --
	// confirming SKILL.md's claim that it's a thin subclass with no independent behavior.
	try {
		if ( ! class_exists( '\\Jet_Form_Builder\\Exceptions\\Gateway_Exception' ) ) {
			throw new \Exception( 'Gateway_Exception not loaded' );
		}
		$is_handler_exception_subclass = is_subclass_of(
			'\\Jet_Form_Builder\\Exceptions\\Gateway_Exception',
			'\\Jet_Form_Builder\\Exceptions\\Handler_Exception'
		);

		$plain_ex   = new \Jet_Form_Builder\Exceptions\Gateway_Exception( 'Invalid gateway options', 'client_id' );
		$dynamic_ex = ( new \Jet_Form_Builder\Exceptions\Gateway_Exception( 'order_id is not set.' ) )->dynamic_error();

		$pass = $is_handler_exception_subclass
			&& ( false === $plain_ex->is_success() )
			&& ( 'Invalid gateway options' === $plain_ex->get_form_status() )
			&& ( false === $dynamic_ex->is_success() )
			&& ( 0 === strpos( $dynamic_ex->get_form_status(), 'derror|' ) );

		agent_test_assert(
			$suite, 'pg-8',
			'SKILL.md "Gateway_Exception": it is a thin Handler_Exception subclass with no new behavior of its own -- same is_success()/get_form_status()/dynamic_error() mechanics as Action_Exception (jetformbuilder-actions\' act-2)',
			$pass,
			array( 'is_subclass_of_handler_exception' => true, 'plain_is_success' => false, 'plain_form_status' => 'Invalid gateway options', 'dynamic_is_success' => false, 'dynamic_form_status_prefixed_derror' => true ),
			array( 'is_subclass_of_handler_exception' => $is_handler_exception_subclass, 'plain_is_success' => $plain_ex->is_success(), 'plain_form_status' => $plain_ex->get_form_status(), 'dynamic_is_success' => $dynamic_ex->is_success(), 'dynamic_form_status' => $dynamic_ex->get_form_status() ),
			'includes/exceptions/gateway-exception.php:11, modules/gateways/base-gateway.php:193-215 (set_current_gateway_options throws this shape), modules/gateways/paypal/api-actions/capture-payment-action.php:46-50 (before_make_request throws "order_id is not set.")'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'pg-8', 'Gateway_Exception mechanics smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// pg-9: Payment_View::get_prepared_join() builds the documented 3-table LEFT JOIN
	// (payment_to_payer_shipping -> payers_shipping -> payers) referencing real table
	// names; Payment_Count_View overrides it to a no-op (empty join), confirming the
	// "counting doesn't need the payer/shipping joins" optimization claim. No real
	// payment rows needed -- this only inspects the generated SQL fragment.
	try {
		if ( ! class_exists( '\\JFB_Modules\\Gateways\\Query_Views\\Payment_View' )
			|| ! class_exists( '\\JFB_Modules\\Gateways\\Query_Views\\Payment_Count_View' ) ) {
			throw new \Exception( 'Payment_View / Payment_Count_View not loaded' );
		}

		$builder_class = class_exists( '\\Jet_Form_Builder\\Db_Queries\\Query_Builder' )
			? '\\Jet_Form_Builder\\Db_Queries\\Query_Builder'
			: null;
		if ( ! $builder_class ) {
			throw new \Exception( 'Query_Builder not loaded' );
		}

		$view = new \JFB_Modules\Gateways\Query_Views\Payment_View();
		$fake_builder = new $builder_class();
		$view->get_prepared_join( $fake_builder );
		$join_sql = $fake_builder->join ?? '';

		$payments_to_p_ships_table = \JFB_Modules\Gateways\Db_Models\Payment_To_Payer_Shipping_Model::table();
		$payers_ship_table         = \JFB_Modules\Gateways\Db_Models\Payer_Shipping_Model::table();
		$payers_table              = \JFB_Modules\Gateways\Db_Models\Payer_Model::table();

		$has_all_joins = ( false !== strpos( $join_sql, $payments_to_p_ships_table ) )
			&& ( false !== strpos( $join_sql, $payers_ship_table ) )
			&& ( false !== strpos( $join_sql, $payers_table ) );

		$count_view    = new \JFB_Modules\Gateways\Query_Views\Payment_Count_View();
		$fake_builder2 = new $builder_class();
		$count_view->get_prepared_join( $fake_builder2 );
		$count_join_sql = $fake_builder2->join ?? '';

		$pass = $has_all_joins && empty( trim( $count_join_sql ) );

		agent_test_assert(
			$suite, 'pg-9',
			'SKILL.md "Reading payment records": Payment_View::get_prepared_join() builds a LEFT JOIN chain through payment_to_payer_shipping/payers_shipping/payers (real table names present in the generated SQL); Payment_Count_View overrides get_prepared_join() to a no-op (empty $builder->join)',
			$pass,
			array( 'payment_view_join_has_all_3_tables' => true, 'count_view_join_empty' => true ),
			array( 'payment_view_join_sql' => $join_sql, 'count_view_join_sql' => $count_join_sql ),
			'modules/gateways/query-views/payment-view.php:82-97, modules/gateways/query-views/payment-count-view.php:18-25'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'pg-9', 'query-views join-SQL smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
