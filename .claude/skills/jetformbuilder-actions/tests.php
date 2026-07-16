<?php
/**
 * AGENT-TEST-SUITE: jetformbuilder-actions
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetformbuilder-actions"),
 * alongside the always-active AGENT-TEST-CORE harness. Run via
 * GET /agent-test/v1/suite/jetformbuilder-actions. See docs/test-harness-guide.md.
 *
 * Deliberately does NOT submit a real form (that already happened once, manually, via
 * curl against a throwaway form/page — see TEST-REGIMEN.md's 2026-07-15 run log). These
 * tests instead call the same underlying classes (Base, Action_Exception, Manager)
 * directly within this single REST request — safe because register_action_type()'s
 * effects only live for the lifetime of this one PHP process/request, they don't persist
 * or affect other visitors.
 */

add_action( 'agent-test/run-suite/jetformbuilder-actions', function() {

	$suite = 'jetformbuilder-actions';

	// act-1: Base class shape — dependence()/is_disabled()/on_register_in_flow() have the
	// documented defaults (true/false/no-op) unless a subclass overrides them.
	try {
		if ( ! class_exists( '\\Jet_Form_Builder\\Actions\\Types\\Base' ) ) {
			throw new \Exception( 'Jet_Form_Builder\\Actions\\Types\\Base not loaded' );
		}
		if ( ! class_exists( 'Agent_Test_Minimal_Action' ) ) {
			class Agent_Test_Minimal_Action extends \Jet_Form_Builder\Actions\Types\Base {
				public function get_id() { return 'agent_test_minimal_action'; }
				public function get_name() { return 'Agent Test Minimal Action'; }
				public function do_action( array $request, $handler ) {}
			}
		}
		$action = new Agent_Test_Minimal_Action();
		$pass = ( true === $action->dependence() ) && ( false === $action->is_disabled() ) && ( null === $action->on_register_in_flow() );
		agent_test_assert(
			$suite, 'act-1',
			'SKILL.md "Base class and required methods": dependence() defaults true, is_disabled() defaults false, on_register_in_flow() is a no-op — a minimal subclass only needs do_action()',
			$pass,
			array( 'dependence' => true, 'is_disabled' => false ),
			array( 'dependence' => $action->dependence(), 'is_disabled' => $action->is_disabled() ),
			'includes/actions/types/base.php'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'act-1', 'Base class default-method smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// act-2: Action_Exception's message doubles as the form status AND drives is_success() —
	// but is_success() only returns true for the LITERAL string 'success', not any truthy
	// message. Fully self-contained, no form submission needed.
	try {
		if ( ! class_exists( '\\Jet_Form_Builder\\Exceptions\\Action_Exception' ) ) {
			throw new \Exception( 'Action_Exception not loaded' );
		}
		$success_ex = new \Jet_Form_Builder\Exceptions\Action_Exception( 'success' );
		$other_ex   = new \Jet_Form_Builder\Exceptions\Action_Exception( 'custom_test_status' );
		$empty_ex   = new \Jet_Form_Builder\Exceptions\Action_Exception();

		$pass = ( true === $success_ex->is_success() )
			&& ( false === $other_ex->is_success() )
			&& ( 'custom_test_status' === $other_ex->get_form_status() )
			&& ( false === $empty_ex->is_success() )
			&& ( 'failed' === $empty_ex->get_form_status() ); // default_type_message fallback for an empty message

		agent_test_assert(
			$suite, 'act-2',
			'SKILL.md "Signaling success or failure": Action_Exception\'s message string doubles as the form status and drives is_success() classification — but only the LITERAL string "success" counts as success, and an empty message falls back to "failed"',
			$pass,
			array( "is_success('success')" => true, "is_success('custom_test_status')" => false, "is_success('')" => false, "get_form_status('')" => 'failed' ),
			array( "is_success('success')" => $success_ex->is_success(), "is_success('custom_test_status')" => $other_ex->is_success(), "get_form_status('custom_test_status')" => $other_ex->get_form_status(), "is_success('')" => $empty_ex->is_success(), "get_form_status('')" => $empty_ex->get_form_status() ),
			'includes/exceptions/handler-exception.php:56,90 (get_form_status()/is_success()), includes/form-messages/status-info.php:63-77 (is_success only true for literal "success" or a registered dynamic-success type)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'act-2', 'Action_Exception status/is_success smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// act-3: silent ID collision — registering a custom action reusing an existing built-in
	// id ('redirect_to_page') overwrites it in the repository with no error. Scoped to this
	// single request only (register_action_type() mutates an in-memory array on the Manager
	// singleton, which does not persist across separate PHP requests).
	try {
		$manager = function_exists( 'jet_form_builder' ) ? jet_form_builder()->actions : null;
		if ( ! $manager ) {
			throw new \Exception( 'jet_form_builder()->actions not reachable' );
		}
		if ( ! class_exists( 'Agent_Test_Fake_Redirect_Action' ) ) {
			class Agent_Test_Fake_Redirect_Action extends \Jet_Form_Builder\Actions\Types\Base {
				public function get_id() { return 'redirect_to_page'; } // deliberately reuses a real built-in id
				public function get_name() { return 'Agent Test Fake Redirect (overwrite probe)'; }
				public function do_action( array $request, $handler ) {}
			}
		}
		$before = $manager->get_actions( 'redirect_to_page' );
		$before_class = is_object( $before ) ? get_class( $before ) : null;
		$manager->register_action_type( new Agent_Test_Fake_Redirect_Action() );
		$after = $manager->get_actions( 'redirect_to_page' );
		$after_class = is_object( $after ) ? get_class( $after ) : null;
		$pass = ( $before_class !== 'Agent_Test_Fake_Redirect_Action' ) && ( $after_class === 'Agent_Test_Fake_Redirect_Action' );
		agent_test_assert(
			$suite, 'act-3',
			'SKILL.md "Registration" gotcha: reusing an existing built-in action id (e.g. redirect_to_page) silently overwrites it in the repository, no error — rep_allow_rewrite() defaults true',
			$pass,
			array( 'before_is_builtin' => true, 'after_is_fake' => true ),
			array( 'before_class' => $before_class, 'after_class' => $after_class ),
			'components/repository/repository-pattern-trait.php:57-59 (rep_allow_rewrite() returns true), includes/actions/manager.php:46-49 (register_action_type())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'act-3', 'silent ID collision smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// act-4: Action Conditions — a custom operator registered via
	// jet-form-builder/register/action-condition-settings shows up in the operators list,
	// and jet-form-builder/actions/process-condition is consulted (and its verdict honored)
	// for that operator, end-to-end through Condition_Manager::check_all().
	try {
		if ( ! class_exists( '\\Jet_Form_Builder\\Actions\\Conditions\\Condition_Manager' )
			|| ! function_exists( 'jet_fb_context' ) ) {
			throw new \Exception( 'Condition_Manager or jet_fb_context() not available' );
		}

		add_filter( 'jet-form-builder/register/action-condition-settings', function( $settings ) {
			$settings['operators'][] = array(
				'label'        => 'Agent Test Contains Any',
				'value'        => 'agent_test_contains_any',
				'need_explode' => true,
			);
			return $settings;
		} );

		$seen_operator = null;
		add_filter( 'jet-form-builder/actions/process-condition', function( $result, $condition ) use ( &$seen_operator ) {
			if ( 'agent_test_contains_any' !== $condition->get_operator() ) {
				return $result;
			}
			$seen_operator = $condition->get_operator();
			$field         = (array) $condition->get_field_value();
			return (bool) array_intersect( $field, $condition->get_compare_as_array() );
		}, 10, 2 );

		jet_fb_context()->update_request( array( 'a', 'b' ), 'agent_test_condition_field' );

		$manager = new \Jet_Form_Builder\Actions\Conditions\Condition_Manager();
		$manager->set_conditions( array(
			array(
				'type'     => 'field',
				'field'    => 'agent_test_condition_field',
				'operator' => 'agent_test_contains_any',
				'default'  => 'b,c', // intersects with ['a','b'] submitted above -> condition should pass
				'execute'  => true,
			),
		) );
		$manager->set_condition_operator( 'and' );

		$operator_registered = in_array( 'agent_test_contains_any', $manager->get_operators_list(), true );

		$threw = false;
		try {
			$manager->check_all();
		} catch ( \Jet_Form_Builder\Exceptions\Condition_Exception $e ) {
			$threw = true;
		}

		$pass = $operator_registered && ( 'agent_test_contains_any' === $seen_operator ) && ( false === $threw );

		agent_test_assert(
			$suite, 'act-4',
			'SKILL.md "Action conditions": a custom operator registered via jet-form-builder/register/action-condition-settings appears in Condition_Manager\'s operators list, and jet-form-builder/actions/process-condition is consulted for it — Condition_Manager::check_all() honors the filter\'s verdict (no Condition_Exception thrown when it returns true)',
			$pass,
			array( 'operator_registered' => true, 'process_condition_consulted' => 'agent_test_contains_any', 'check_all_did_not_throw' => true ),
			array( 'operator_registered' => $operator_registered, 'seen_operator' => $seen_operator, 'threw' => $threw ),
			'includes/actions/conditions/condition-manager.php:196-199 (register/action-condition-settings), includes/actions/conditions/condition-instance.php:231-259 (check(), falls through to process-condition filter for unknown operators)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'act-4', 'action-condition custom operator smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// act-5: jet-form-builder/post-modifier/object-properties filter really lets a custom
	// property class join the Insert/Update Post property collection — driven by calling
	// the filter directly with a real Object_Properties_Collection (not a full form
	// submission), confirming ->add()/->has_by_id()/->get_by_id() work as documented.
	try {
		if ( ! class_exists( '\\Jet_Form_Builder\\Actions\\Methods\\Object_Properties_Collection' ) ) {
			throw new \Exception( 'Object_Properties_Collection not loaded' );
		}
		if ( ! class_exists( 'Agent_Test_Post_Slug_Property' ) ) {
			class Agent_Test_Post_Slug_Property extends \Jet_Form_Builder\Actions\Methods\Base_Object_Property {
				public function get_id(): string { return 'agent_test_post_slug'; }
				public function get_label(): string { return 'Agent Test Post Slug'; }
			}
		}

		$seen_collection_class = null;
		add_filter( 'jet-form-builder/post-modifier/object-properties', function( $properties ) use ( &$seen_collection_class ) {
			$seen_collection_class = get_class( $properties );
			$properties->add( new Agent_Test_Post_Slug_Property() );
			return $properties;
		} );

		$collection = apply_filters(
			'jet-form-builder/post-modifier/object-properties',
			new \Jet_Form_Builder\Actions\Methods\Object_Properties_Collection( array() )
		);
		remove_all_filters( 'jet-form-builder/post-modifier/object-properties' );

		$has_it = $collection->has_by_id( 'agent_test_post_slug' );
		$found  = null;
		foreach ( $collection->get_by_id( 'agent_test_post_slug' ) as $prop ) {
			$found = $prop;
			break;
		}

		$pass = ( 'Jet_Form_Builder\\Actions\\Methods\\Object_Properties_Collection' === $seen_collection_class )
			&& $has_it
			&& $found instanceof \Jet_Form_Builder\Actions\Methods\Base_Object_Property
			&& 'Agent Test Post Slug' === $found->get_label();

		agent_test_assert(
			$suite, 'act-5',
			'SKILL.md "Adding a custom Insert/Update Post object property": jet-form-builder/post-modifier/object-properties filter receives a real Object_Properties_Collection; ->add()/->has_by_id()/->get_by_id() (not ->push()) are the real methods to register and retrieve a custom Base_Object_Property subclass',
			$pass,
			array( 'collection_class' => 'Jet_Form_Builder\\Actions\\Methods\\Object_Properties_Collection', 'has_by_id' => true, 'label_matches' => true ),
			array( 'seen_collection_class' => $seen_collection_class, 'has_by_id' => $has_it, 'found_label' => $found ? $found->get_label() : null ),
			'modules/actions-v2/insert-post/properties/post-modifier.php:33-55 (filter site), includes/actions/methods/object-properties-collection.php, includes/classes/arrayable/collection.php:77,107,117 (add()/has_by_id()/get_by_id())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'act-5', 'post-modifier object-properties filter smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
