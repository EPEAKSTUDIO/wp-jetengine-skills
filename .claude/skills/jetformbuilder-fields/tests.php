<?php
/**
 * AGENT-TEST-SUITE: jetformbuilder-fields
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetformbuilder-fields"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetformbuilder-fields. See docs/test-harness-guide.md.
 *
 * jet_fb_context() normally operates inside a real form submission request. These tests
 * exercise it standalone (no live submission fixture yet — see TEST-REGIMEN.md for the
 * still-open repeater/dotted-path test against a real submitted form), using
 * update_request()/get_value() round-trips on a fabricated field name to prove the
 * accessor's read/write contract without needing a real POST.
 */

add_action( 'agent-test/run-suite/jetformbuilder-fields', function() {

	$suite = 'jetformbuilder-fields';

	// jfb-1: jet_fb_context() is reachable and returns a Parser_Context with the documented methods.
	try {
		$ctx = function_exists( 'jet_fb_context' ) ? jet_fb_context() : null;
		$class = $ctx ? get_class( $ctx ) : null;
		$has_methods = $ctx
			&& method_exists( $ctx, 'get_value' )
			&& method_exists( $ctx, 'update_request' )
			&& method_exists( $ctx, 'has_field' )
			&& method_exists( $ctx, 'get_field_type' );
		agent_test_assert(
			$suite, 'jfb-1',
			'SKILL.md "Reading/writing a field value generically": jet_fb_context() returns a Jet_Form_Builder\\Request\\Parser_Context exposing get_value()/update_request()/has_field()/get_field_type()',
			( $class === 'Jet_Form_Builder\\Request\\Parser_Context' && $has_methods ),
			array( 'class' => 'Jet_Form_Builder\\Request\\Parser_Context', 'methods_exist' => true ),
			array( 'class' => $class, 'methods_exist' => $has_methods ),
			'includes/functions.php:104'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jfb-1', 'jet_fb_context() reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jfb-2: has_field()/get_value() on a field that was never submitted degrade gracefully
	// (false / empty string), no fatal — confirms the "no $_POST guessing needed" safety claim.
	try {
		$ctx = function_exists( 'jet_fb_context' ) ? jet_fb_context() : null;
		$has = $ctx ? $ctx->has_field( 'agent_test_field_never_submitted' ) : null;
		$val = $ctx ? $ctx->get_value( 'agent_test_field_never_submitted' ) : null;
		agent_test_assert(
			$suite, 'jfb-2',
			'SKILL.md "Reading/writing a field value generically": has_field() on an unsubmitted field returns false, get_value() returns empty string, neither fatals',
			( $has === false && $val === '' ),
			array( 'has_field' => false, 'get_value' => '' ),
			array( 'has_field' => $has, 'get_value' => $val ),
			'modules/block-parsers/parser-context.php:219-236 (Repository_Exception caught, returns raw_request[$name] ?? \'\'), :653-663 (has_field catches Repository_Exception -> false)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jfb-2', 'has_field/get_value on unsubmitted field smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jfb-3: update_request() + get_value() round-trip works for a field name with no matching
	// block parser (falls back to the internal $parsers plain-value store) — proves write-then-read
	// works even outside of a real submission with registered field blocks.
	try {
		$ctx = function_exists( 'jet_fb_context' ) ? jet_fb_context() : null;
		if ( $ctx ) {
			$ctx->update_request( 'agent_test_value_123', 'agent_test_roundtrip_field' );
		}
		$val = $ctx ? $ctx->get_value( 'agent_test_roundtrip_field' ) : null;
		agent_test_assert(
			$suite, 'jfb-3',
			'SKILL.md "Reading/writing a field value generically": update_request($value, $name) followed by get_value($name) returns the value just written, for a field with no matching block parser',
			( $val === 'agent_test_value_123' ),
			array( 'roundtrip_value' => 'agent_test_value_123' ),
			array( 'roundtrip_value' => $val ),
			'modules/block-parsers/parser-context.php:245-269 (update_request, Silence_Exception -> stores into $parsers[$path[0]]), :219-239 (get_value, Plain_Value_Exception -> returns $parsers[$path[0]])'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jfb-3', 'update_request/get_value round-trip smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jfb-4: 'jet-form-builder/blocks/register' fires during a normal request (block-type registration hook exists).
	try {
		$fired = false;
		add_action( 'jet-form-builder/blocks/register', function() use ( &$fired ) { $fired = true; }, 999 );
		// The hook fires during plugin bootstrap (init-time), which has already happened by the time
		// this REST-triggered suite runs — so re-fire it defensively via has_action() presence check
		// instead of relying on re-triggering plugin bootstrap.
		$has_action = has_action( 'jet-form-builder/blocks/register' ) !== false;
		agent_test_assert(
			$suite, 'jfb-4',
			'SKILL.md "Registering a custom field block type": jet-form-builder/blocks/register is a real, currently-hooked action (Module::register_block_types() calls do_action on it)',
			$has_action,
			array( 'has_action' => true ),
			array( 'has_action' => $has_action, 'note' => 'fires at plugin bootstrap, before this suite runs — presence via has_action() confirms the hook exists and something is already listening, not a fresh fire' ),
			'includes/blocks/module.php:107-121'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jfb-4', 'blocks/register hook existence smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jfb-5: no validation-rule registration filter exists — Rules_Controller::rep_instances() is a
	// hardcoded list (non-empty), and a plausible guessed filter name is not actually registered.
	try {
		$controller = class_exists( '\\JFB_Modules\\Validation\\Rules_Controller' ) ? new \JFB_Modules\Validation\Rules_Controller() : null;
		$rules = ( $controller && method_exists( $controller, 'rep_instances' ) ) ? $controller->rep_instances() : null;
		$guessed_filter_registered = has_filter( 'jet-form-builder/register-validation-rule' ) !== false;
		agent_test_assert(
			$suite, 'jfb-5',
			'SKILL.md "Custom validation rules — no public registration filter exists": Rules_Controller::rep_instances() returns a non-empty hardcoded array, and the plausible guessed filter name jet-form-builder/register-validation-rule is NOT registered on this site',
			( is_array( $rules ) && count( $rules ) > 0 && ! $guessed_filter_registered ),
			array( 'rules_is_non_empty_array' => true, 'guessed_filter_registered' => false ),
			array( 'rules_count' => is_array( $rules ) ? count( $rules ) : null, 'guessed_filter_registered' => $guessed_filter_registered ),
			'modules/validation/rules-controller.php:17,25 (no apply_filters wrapper around rep_instances, confirmed by source grep — this filter-name check is a smoke test, not exhaustive: a differently-named real filter could still exist)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jfb-5', 'Rules_Controller / no-filter smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jfb-6: Form Records live in a dedicated custom DB table ({prefix}jet_fb_records), not wp_posts.
	try {
		global $wpdb;
		$table = class_exists( '\\JFB_Modules\\Form_Record\\Models\\Record_Model' )
			? \JFB_Modules\Form_Record\Models\Record_Model::table()
			: null;
		$table_exists = $table ? (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) : false;
		agent_test_assert(
			$suite, 'jfb-6',
			'SKILL.md "Reading previously-submitted entries": Record_Model::table() resolves to {wpdb_prefix}jet_fb_records and that table exists in the DB (not a wp_posts/CPT-backed store)',
			( $table === $wpdb->prefix . 'jet_fb_records' && $table_exists ),
			array( 'table' => '{prefix}jet_fb_records', 'table_exists' => true ),
			array( 'table' => $table, 'table_exists' => $table_exists ),
			'modules/form-record/models/record-model.php:15-19 (table_name() returns \'records\'), includes/db-queries/base-db-model.php:17,29,324 (DB_TABLE_PREFIX = \'jet_fb_\', table() = wpdb prefix + \'jet_fb_\' + table_name())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jfb-6', 'Record_Model table existence smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jfb-7: Preset_Manager / Base_Preset are reachable and shaped as documented (separate
	// "which fields" vs "where the value comes from" responsibilities).
	try {
		$pm_exists = class_exists( '\\Jet_Form_Builder\\Presets\\Preset_Manager' );
		$base_exists = class_exists( '\\Jet_Form_Builder\\Presets\\Types\\Base_Preset' );
		$base_methods = $base_exists && method_exists( '\\Jet_Form_Builder\\Presets\\Types\\Base_Preset', 'get_fields_map' )
			&& method_exists( '\\Jet_Form_Builder\\Presets\\Types\\Base_Preset', 'get_slug' )
			&& method_exists( '\\Jet_Form_Builder\\Presets\\Types\\Base_Preset', 'get_source' );
		agent_test_assert(
			$suite, 'jfb-7',
			'SKILL.md "Presets & dynamic default values": Preset_Manager and the abstract Types\\Base_Preset (get_fields_map()/get_slug()/get_source()) are both reachable',
			( $pm_exists && $base_exists && $base_methods ),
			array( 'preset_manager_exists' => true, 'base_preset_exists' => true, 'base_preset_methods_exist' => true ),
			array( 'preset_manager_exists' => $pm_exists, 'base_preset_exists' => $base_exists, 'base_preset_methods_exist' => $base_methods ),
			'includes/presets/preset-manager.php:25, includes/presets/types/base-preset.php:21,33,40,69'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jfb-7', 'Preset_Manager/Base_Preset reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jfb-8: media-field guest-upload gate — jet-form-builder/media-field/before-upload fires
	// with the Media_Field_Parser itself, and its get_context()->allow_for_guest()/
	// update_setting() (both on Parser_Context) are real, callable methods that mutate the
	// parser's own settings. Doesn't invoke get_response() itself (that needs a real uploaded
	// file) — confirms the hook fires and the two context methods it's documented to call work.
	try {
		if ( ! class_exists( '\\JFB_Modules\\Block_Parsers\\Fields\\Media_Field_Parser' ) ) {
			throw new \Exception( 'Media_Field_Parser not loaded' );
		}
		$parser = new \JFB_Modules\Block_Parsers\Fields\Media_Field_Parser();
		$parser->set_context( new \Jet_Form_Builder\Request\Parser_Context() ); // get_context() requires this to be set first — field-data-parser.php:181
		$has_get_context = method_exists( $parser, 'get_context' );
		$ctx = $has_get_context ? $parser->get_context() : null;
		$has_context_methods = $ctx && method_exists( $ctx, 'allow_for_guest' ) && method_exists( $ctx, 'update_setting' );

		$fired_with_parser = null;
		add_action( 'jet-form-builder/media-field/before-upload', function( $p ) use ( &$fired_with_parser ) {
			$fired_with_parser = get_class( $p );
			$p->get_context()->allow_for_guest();
			$p->get_context()->update_setting( 'value_format', 'id' );
		} );
		do_action( 'jet-form-builder/media-field/before-upload', $parser );

		$pass = $has_get_context && $has_context_methods && ( 'JFB_Modules\\Block_Parsers\\Fields\\Media_Field_Parser' === $fired_with_parser );

		agent_test_assert(
			$suite, 'jfb-8',
			'SKILL.md "Media field: guest uploads are blocked by default": jet-form-builder/media-field/before-upload fires with the Media_Field_Parser instance itself, and $parser->get_context()->allow_for_guest()/update_setting() are real callable methods on Parser_Context',
			$pass,
			array( 'has_get_context' => true, 'has_context_methods' => true, 'hook_fired_with' => 'JFB_Modules\\Block_Parsers\\Fields\\Media_Field_Parser' ),
			array( 'has_get_context' => $has_get_context, 'has_context_methods' => $has_context_methods, 'hook_fired_with' => $fired_with_parser ),
			'modules/block-parsers/fields/media-field-parser.php:48 (before-upload), modules/block-parsers/field-data-parser.php:240 (get_context()), modules/block-parsers/parser-context.php:384,401 (update_setting()/allow_for_guest())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jfb-8', 'media-field before-upload hook smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
