<?php
/**
 * AGENT-TEST-SUITE: jetengine-listings-macros
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetengine-listings-macros"),
 * alongside the always-active AGENT-TEST-CORE harness. Run via
 * GET /agent-test/v1/suite/jetengine-listings-macros. See docs/test-harness-guide.md.
 *
 * No listing grid fixture needed: jet_engine()->listings->macros->handler is a real
 * \Crocoblock\Macros_Handler instance reachable directly, and its public
 * register_macros()/do_macros() methods can be called straight from this suite (the
 * handler is already initialized by the time any REST request runs, since something on
 * the site has always triggered ->init() via the admin/editor UI at least once — if not,
 * mac-1 through mac-4 below force ->init() as their first step). This tests the actual
 * parser/registry, not just source reading — no page-builder render, no browser needed.
 */

add_action( 'agent-test/run-suite/jetengine-listings-macros', function() {

	$suite = 'jetengine-listings-macros';

	if ( ! function_exists( 'jet_engine' ) || ! isset( jet_engine()->listings ) ) {
		agent_test_assert( $suite, 'macros-0', 'jet_engine()->listings reachable', false, 'reachable', 'not reachable', 'jet_engine() unavailable — is JetEngine active?' );
		return;
	}

	$macros = jet_engine()->listings->macros; // Jet_Engine_Listings_Macros
	$macros->init(); // idempotent — guarded by $initialized flag in the real class

	static $call_log = array();

	// A macro with NO custom args declared (macros_args() defaults to []).
	if ( ! class_exists( 'Agent_Test_Macro_Plain' ) ) {
		class Agent_Test_Macro_Plain extends \Jet_Engine_Base_Macros {
			public function macros_tag() { return 'agent_test_macro_plain'; }
			public function macros_name() { return 'Agent Test Macro Plain'; }
			public function macros_callback( $args = array() ) {
				return 'ECHO:' . ( empty( $args ) ? 'empty-args' : wp_json_encode( $args ) );
			}
		}
	}
	// A macro WITH one custom arg declared, to test positional pipe-arg mapping.
	if ( ! class_exists( 'Agent_Test_Macro_With_Args' ) ) {
		class Agent_Test_Macro_With_Args extends \Jet_Engine_Base_Macros {
			public function macros_args() { return array( 'first' => array( 'label' => 'First' ) ); }
			public function macros_tag() { return 'agent_test_macro_args'; }
			public function macros_name() { return 'Agent Test Macro With Args'; }
			public function macros_callback( $args = array() ) {
				return 'ARGVAL:' . ( $args['first'] ?? 'none' );
			}
		}
	}
	// A macro that is registered but whose callback increments a counter, to prove
	// regex-level misses never invoke the callback at all.
	if ( ! class_exists( 'Agent_Test_Macro_Uppercase' ) ) {
		class Agent_Test_Macro_Uppercase extends \Jet_Engine_Base_Macros {
			public function macros_tag() { return 'Agent_Test_Upper'; } // invalid: uppercase + underscore ok, but uppercase fails [a-z_-]+
			public function macros_name() { return 'Agent Test Uppercase (invalid tag)'; }
			public function macros_callback( $args = array() ) {
				$GLOBALS['agent_test_macro_uppercase_called'] = true;
				return 'SHOULD_NEVER_APPEAR';
			}
		}
	}

	$GLOBALS['agent_test_macro_uppercase_called'] = false;
	$macros->handler->register_macros( new Agent_Test_Macro_Plain() );
	$macros->handler->register_macros( new Agent_Test_Macro_With_Args() );
	$macros->handler->register_macros( new Agent_Test_Macro_Uppercase() );

	// macros-1: plain %macro% with no args declared resolves via do_macros().
	try {
		$out = $macros->do_macros( 'before %agent_test_macro_plain% after' );
		agent_test_assert(
			$suite, 'macros-1',
			'SKILL.md "Syntax"/"Registering a custom macro": a plain %macro_name% tag resolves through do_macros() to the callback\'s return value',
			( 'before ECHO:empty-args after' === $out ),
			'before ECHO:empty-args after',
			$out,
			'framework/macros/macros-handler.php:494-621 (do_macros()), includes/base/base-macros.php (Jet_Engine_Base_Macros self-registration)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'macros-1', 'plain macro resolution', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// macros-2: pipe-delimited args are NOT passed through to macros_callback() unless the
	// macro class declares matching macros_args() — a real finding beyond what SKILL.md
	// currently states (it doesn't pin down the empty-args-declared case).
	try {
		$out = $macros->do_macros( '%agent_test_macro_plain|foo,bar%' );
		agent_test_assert(
			$suite, 'macros-2',
			'Correction/addition to SKILL.md "Registering a custom macro": if a macro class declares no macros_args(), the raw pipe-argument string is silently dropped — macros_callback() receives an empty $args array, not the raw "foo,bar" string',
			( 'ECHO:empty-args' === $out ),
			'ECHO:empty-args (pipe args dropped, since Agent_Test_Macro_Plain declares no macros_args())',
			$out,
			'includes/base/base-macros.php _macros_callback(): explodes raw_args by | only inside a foreach over get_macros_args() — if that\'s empty, $args stays [] regardless of what was piped in'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'macros-2', 'pipe-args-without-declared-schema smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// macros-3: pipe args ARE passed through, positionally, when macros_args() declares a
	// matching arg key.
	try {
		$out = $macros->do_macros( '%agent_test_macro_args|hello-value%' );
		agent_test_assert(
			$suite, 'macros-3',
			'SKILL.md "Registering a custom macro": pipe args map positionally onto whatever keys macros_args() declares',
			( 'ARGVAL:hello-value' === $out ),
			'ARGVAL:hello-value',
			$out,
			'includes/base/base-macros.php _macros_callback() — explodes raw_args by | and zips them onto get_macros_args() keys in declared order'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'macros-3', 'pipe-args-with-declared-schema smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// macros-4: a tag containing uppercase letters never matches the parser regex at all —
	// the callback is NEVER invoked (regex-level miss), as opposed to a registry-miss.
	try {
		$out = $macros->do_macros( '%Agent_Test_Upper%' );
		$callback_invoked = $GLOBALS['agent_test_macro_uppercase_called'];
		agent_test_assert(
			$suite, 'macros-4',
			'SKILL.md "Why a macro prints literally": a tag with uppercase letters never matches the regex, so the callback is never even invoked (regex-level miss, not a registry-miss)',
			( '%Agent_Test_Upper%' === $out && false === $callback_invoked ),
			array( 'output' => '%Agent_Test_Upper%', 'callback_invoked' => false ),
			array( 'output' => $out, 'callback_invoked' => $callback_invoked ),
			'framework/macros/macros-handler.php:503 regex /%([a-z_-]+).../ — uppercase T never matches [a-z_-]+'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'macros-4', 'uppercase-tag regex-miss smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// macros-5: a validly-shaped but never-registered tag also prints literally (registry
	// miss) — same visible output as macros-4's regex-miss, confirming both are
	// indistinguishable from the front end.
	try {
		$out = $macros->do_macros( '%agent_test_totally_unregistered_tag%' );
		agent_test_assert(
			$suite, 'macros-5',
			'SKILL.md "Why a macro prints literally": a validly-shaped but unregistered tag also prints literally, same visible result as a regex-level miss',
			( '%agent_test_totally_unregistered_tag%' === $out ),
			'%agent_test_totally_unregistered_tag%',
			$out,
			'framework/macros/macros-handler.php:508 (if (!isset($macros[$found])) return $matches[0];)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'macros-5', 'registry-miss smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// macros-6: subclassing a BUILT-IN macro class (Query_Results_Macro) instead of the
	// abstract Jet_Engine_Base_Macros directly — registers and resolves correctly.
	try {
		if ( ! class_exists( '\\Jet_Engine\\Query_Builder\\Macros\\Query_Results_Macro' ) ) {
			throw new \Exception( 'Jet_Engine\\Query_Builder\\Macros\\Query_Results_Macro not loaded' );
		}
		if ( ! class_exists( 'Agent_Test_Query_Results_Subclass' ) ) {
			class Agent_Test_Query_Results_Subclass extends \Jet_Engine\Query_Builder\Macros\Query_Results_Macro {
				public function macros_tag() { return 'agent_test_query_results_subclass'; }
				public function macros_callback( $args = array() ) {
					return 'SUBCLASS_OK';
				}
			}
		}
		$is_real_subclass = is_subclass_of( 'Agent_Test_Query_Results_Subclass', '\\Jet_Engine\\Query_Builder\\Macros\\Query_Results_Macro' );
		$macros->handler->register_macros( new Agent_Test_Query_Results_Subclass() );
		$out = $macros->do_macros( '%agent_test_query_results_subclass%' );
		$pass = $is_real_subclass && ( 'SUBCLASS_OK' === $out );
		agent_test_assert(
			$suite, 'macros-6',
			'SKILL.md "Subclassing a built-in macro instead of the abstract base": a class extending \\Jet_Engine\\Query_Builder\\Macros\\Query_Results_Macro (a real built-in macro), not Jet_Engine_Base_Macros directly, registers and resolves through do_macros() the same way as any other macro',
			$pass,
			array( 'is_real_subclass' => true, 'macro_output' => 'SUBCLASS_OK' ),
			array( 'is_real_subclass' => $is_real_subclass, 'macro_output' => $out ),
			'includes/components/query-builder/macros/query-results.php:6 (Query_Results_Macro extends Jet_Engine_Base_Macros)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'macros-6', 'subclassing built-in macro smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// macros-7: the custom-"context" two-filter pairing (allowed-context-list registers
	// the key for the admin UI, data/object-by-context/{key} resolves it) both work
	// end-to-end through the real Data::get_object_by_context().
	try {
		$data = jet_engine()->listings->data; // Jet_Engine_Listings_Data instance

		add_filter( 'jet-engine/listings/allowed-context-list', function( $context ) {
			$context['agent_test_context'] = 'Agent Test Context';
			return $context;
		} );
		add_filter( 'jet-engine/listings/data/object-by-context/agent_test_context', function( $default ) {
			return 'AGENT_TEST_CONTEXT_RESOLVED';
		} );

		$allowed = jet_engine()->listings->allowed_context_list();
		$resolved = $data->get_object_by_context( 'agent_test_context' );

		remove_all_filters( 'jet-engine/listings/allowed-context-list' );
		remove_all_filters( 'jet-engine/listings/data/object-by-context/agent_test_context' );

		$key_listed = is_array( $allowed ) && array_key_exists( 'agent_test_context', $allowed );
		$pass = $key_listed && ( 'AGENT_TEST_CONTEXT_RESOLVED' === $resolved );

		agent_test_assert(
			$suite, 'macros-7',
			'SKILL.md "Registering a custom context": jet-engine/listings/allowed-context-list registers a new context key (appears in allowed_context_list()), and jet-engine/listings/data/object-by-context/{key} resolves it via Data::get_object_by_context() — both wired through the real methods',
			$pass,
			array( 'key_listed' => true, 'resolved_value' => 'AGENT_TEST_CONTEXT_RESOLVED' ),
			array( 'key_listed' => $key_listed, 'resolved' => $resolved ),
			'includes/components/listings/manager.php:548-558 (allowed_context_list()), includes/components/listings/data.php:862-898 (get_object_by_context(), dynamic filter fallthrough)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'macros-7', 'custom listing context two-filter pair smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
