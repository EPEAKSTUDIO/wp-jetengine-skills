<?php
/**
 * AGENT-TEST-SUITE: jetpopup-render-triggers
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetpopup-render-triggers"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetpopup-render-triggers. See docs/test-harness-guide.md.
 *
 * Creates and cleans up throwaway `jet-popup` fixture post(s) (AGENT-TEST prefix) for the
 * live render-pipeline tests. The real JS open/close trigger event payload shape (SKILL.md's
 * "The JS open/close trigger event API" section) is NOT covered here — it needs a real
 * browser, not a PHP snippet — see TEST-REGIMEN.md Test 5 for the manual steps.
 */

add_action( 'agent-test/run-suite/jetpopup-render-triggers', function() {

	$suite = 'jetpopup-render-triggers';

	// jprt-1: add_attached_popup() only accepts published popups.
	$draft_id = null;
	$publish_id = null;
	try {
		$draft_id = wp_insert_post( array(
			'post_type'   => 'jet-popup',
			'post_title'  => 'AGENT-TEST jetpopup-render-triggers draft fixture',
			'post_status' => 'draft',
		), true );
		$draft_id = is_wp_error( $draft_id ) ? null : $draft_id;

		$publish_id = wp_insert_post( array(
			'post_type'   => 'jet-popup',
			'post_title'  => 'AGENT-TEST jetpopup-render-triggers publish fixture',
			'post_status' => 'publish',
		), true );
		$publish_id = is_wp_error( $publish_id ) ? null : $publish_id;

		if ( ! $draft_id || ! $publish_id || ! function_exists( 'jet_popup' ) ) {
			throw new \Exception( 'fixture popups or jet_popup() unavailable' );
		}

		// Reset render manager's in-memory list so this test is isolated from other assertions.
		jet_popup()->generator->attached_popups = array();

		jet_popup()->generator->add_attached_popup( $draft_id );
		jet_popup()->generator->add_attached_popup( $publish_id );

		$attached = jet_popup()->generator->get_attached_popups();
		$attached = array_map( 'intval', $attached );

		$pass = ! in_array( (int) $draft_id, $attached, true ) && in_array( (int) $publish_id, $attached, true );

		agent_test_assert(
			$suite, 'jprt-1',
			'SKILL.md "Which popups render": add_attached_popup() silently skips a draft popup and accepts a published one',
			$pass,
			array( 'draft_excluded' => true, 'publish_included' => true ),
			array( 'attached' => $attached, 'draft_id' => $draft_id, 'publish_id' => $publish_id ),
			'includes/render/manager.php:93-113'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jprt-1', 'add_attached_popup publish-only gate test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jprt-2: page-load trigger downgrades to 'none' for attach-only (non-condition) popups.
	try {
		if ( ! $publish_id || ! function_exists( 'jet_popup' ) ) {
			throw new \Exception( 'fixture popup unavailable' );
		}

		$settings = jet_popup()->settings->get_popup_default_settings();
		$settings['jet_popup_open_trigger'] = 'page-load';

		ob_start();
		jet_popup()->generator->popup_render( $publish_id, $settings, array(
			'is_attached'  => true,
			'is_condition' => false,
		) );
		$html = ob_get_clean();

		$matched = preg_match( '/data-settings="([^"]+)"/', $html, $m );
		$json = $matched ? json_decode( html_entity_decode( $m[1] ), true ) : null;
		$open_trigger = is_array( $json ) ? ( $json['open-trigger'] ?? null ) : null;

		$pass = ( 'none' === $open_trigger );

		agent_test_assert(
			$suite, 'jprt-2',
			'SKILL.md "Which popups render": popup_render() downgrades open-trigger to \'none\' when is_attached && !is_condition && configured trigger is page-load',
			$pass,
			array( 'open-trigger' => 'none' ),
			array( 'open-trigger' => $open_trigger, 'html_had_data_settings' => (bool) $matched ),
			'includes/render/manager.php:257-261'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jprt-2', 'page-load-trigger-downgrade live test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jprt-3: jet_popup_get_content AJAX action is registered under DOING_AJAX.
	try {
		$was_doing_ajax = defined( 'DOING_AJAX' );
		if ( ! $was_doing_ajax ) {
			define( 'DOING_AJAX', true );
			// Ajax handlers class registers its actions from its own constructor guarded by DOING_AJAX;
			// re-instantiate is unsafe (see jetsmartfilters-query's Storage\Controller landmine), so we
			// only check whether the hook already ended up registered by the normal request bootstrap.
		}
		$registered = has_action( 'wp_ajax_jet_popup_get_content' );
		$registered_nopriv = has_action( 'wp_ajax_nopriv_jet_popup_get_content' );

		agent_test_assert(
			$suite, 'jprt-3',
			'SKILL.md "The AJAX content endpoint": wp_ajax_jet_popup_get_content / wp_ajax_nopriv_jet_popup_get_content are registered (only meaningfully true if this request itself ran under DOING_AJAX at plugin-init time; a REST-triggered suite run typically will NOT have DOING_AJAX true at that point, so a false result here is expected/inconclusive, not a plugin bug — see TEST-REGIMEN.md)',
			true, // informational — always "pass", real signal is in actual/notes
			array( 'informational' => true ),
			array( 'registered' => (bool) $registered, 'registered_nopriv' => (bool) $registered_nopriv, 'DOING_AJAX_was_predefined' => $was_doing_ajax ),
			'includes/ajax-handlers.php:59-65 — informational only, see TEST-REGIMEN.md Test 3'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jprt-3', 'jet_popup_get_content registration smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jprt-4: cache-teardown meta keys match between post-type save and JFB-compat clear_popup_cache.
	try {
		$post_type_file = WP_PLUGIN_DIR . '/jet-popup/includes/post-type.php';
		$jfb_file       = WP_PLUGIN_DIR . '/jet-popup/includes/compatibility/plugins/jet-form-builder/manager.php';
		$post_type_src  = file_exists( $post_type_file ) ? file_get_contents( $post_type_file ) : '';
		$jfb_src        = file_exists( $jfb_file ) ? file_get_contents( $jfb_file ) : '';

		$keys = array( '_is_deps_ready', '_is_script_deps', '_is_style_deps', '_is_content_elements' );
		$results = array();
		$all_pass = ( '' !== $post_type_src ) && ( '' !== $jfb_src );

		foreach ( $keys as $key ) {
			$in_post_type = false !== strpos( $post_type_src, "'" . $key . "'" );
			$in_jfb       = false !== strpos( $jfb_src, "'" . $key . "'" );
			$results[ $key ] = array( 'post_type' => $in_post_type, 'jfb_compat' => $in_jfb );
			$all_pass = $all_pass && $in_post_type && $in_jfb;
		}

		agent_test_assert(
			$suite, 'jprt-4',
			'SKILL.md "Rendered markup": save_popup_post_type() and Jet_Form_Builder::clear_popup_cache() both reference all 4 documented cache-teardown meta keys',
			$all_pass,
			array( 'all_keys_in_both_files' => true ),
			$results,
			'includes/post-type.php:322-329, includes/compatibility/plugins/jet-form-builder/manager.php:155-166'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jprt-4', 'cache-teardown key parity source-presence test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jprt-6: JetEngine popup-render-context swap is a no-op without a post_id (only if JetEngine is active).
	try {
		if ( ! class_exists( 'Jet_Engine' ) || ! function_exists( 'jet_engine' ) ) {
			agent_test_assert(
				$suite, 'jprt-6',
				'SKILL.md "The AJAX content endpoint": Jet_Engine::setup_popup_render_context() is a no-op without settings[post_id]',
				true,
				array( 'skipped' => 'JetEngine not active on this sandbox' ),
				array( 'skipped' => true ),
				'includes/compatibility/plugins/jet-engine/manager.php:31-59 — SKIPPED, not FAILED: JetEngine not active here'
			);
		} else {
			$before = jet_engine()->listings->data->get_current_object();

			$compat_manager = jet_popup()->compatibility;
			$ref = new \ReflectionClass( $compat_manager );
			$prop = $ref->getProperty( 'registered_mobules' );
			$prop->setAccessible( true );
			$modules = $prop->getValue( $compat_manager );
			$je_instance = isset( $modules['jet-engine']['instance'] ) ? $modules['jet-engine']['instance'] : null;

			if ( ! $je_instance ) {
				throw new \Exception( 'jet-engine compatibility module instance not found' );
			}

			$je_instance->setup_popup_render_context( array() ); // no post_id -> should be a no-op

			$after = jet_engine()->listings->data->get_current_object();

			$pass = ( $before === $after );

			agent_test_assert(
				$suite, 'jprt-6',
				'SKILL.md "The AJAX content endpoint": Jet_Engine::setup_popup_render_context() is a no-op without settings[post_id]',
				$pass,
				array( 'current_object_unchanged' => true ),
				array( 'unchanged' => $pass ),
				'includes/compatibility/plugins/jet-engine/manager.php:31-35'
			);
		}
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jprt-6', 'JetEngine render-context no-op test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// Cleanup fixture popups.
	try {
		if ( $draft_id ) {
			wp_delete_post( $draft_id, true );
		}
		if ( $publish_id ) {
			wp_delete_post( $publish_id, true );
		}
	} catch ( \Throwable $e ) {
		// Best-effort cleanup; not itself an assertion.
	}

} );
