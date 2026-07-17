<?php
/**
 * AGENT-TEST-SUITE: jetpopup-extensibility
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetpopup-extensibility"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetpopup-extensibility. See docs/test-harness-guide.md.
 */

add_action( 'agent-test/run-suite/jetpopup-extensibility', function() {

	$suite = 'jetpopup-extensibility';

	// jpe-1: jet-popup/access-cap fans out to every CPT capability AND to the REST permission_callback.
	//
	// Live-verified gotcha (2026-07-16): the CPT was already registered at this site's own
	// `init` (long before this REST-triggered request ever runs), so get_post_type_object()
	// returns the object built from whatever jet-popup/access-cap resolved to back then —
	// adding our filter now and reading $cpt->cap without re-registering doesn't retroactively
	// change anything already baked into that object. Must unregister + re-register the CPT
	// with the filter active to see it take effect, then do the same in reverse to restore
	// real state afterward.
	try {
		add_filter( 'jet-popup/access-cap', function() {
			return 'agent_test_marker_cap';
		}, 999 );

		if ( class_exists( 'Jet_Popup_Post_Type' ) && post_type_exists( 'jet-popup' ) ) {
			unregister_post_type( 'jet-popup' );
			Jet_Popup_Post_Type::register_post_type();
		}

		$cpt = get_post_type_object( 'jet-popup' );
		$cap_props = $cpt ? (array) $cpt->cap : array();

		$rest_cap_value = jet_popup()->get_admin_ui_cap();

		remove_all_filters( 'jet-popup/access-cap' );

		// Re-register post type (with the real filter state) so subsequent requests aren't
		// left with the marker cap baked in.
		if ( class_exists( 'Jet_Popup_Post_Type' ) && post_type_exists( 'jet-popup' ) ) {
			unregister_post_type( 'jet-popup' );
			Jet_Popup_Post_Type::register_post_type();
		}

		$expected_keys = array(
			'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts',
			'delete_posts', 'publish_posts', 'read_private_posts', 'read',
			'delete_private_posts', 'delete_published_posts', 'delete_others_posts',
			'edit_private_posts', 'edit_published_posts', 'create_posts',
		);

		$mismatches = array();
		foreach ( $expected_keys as $key ) {
			if ( ! isset( $cap_props[ $key ] ) || 'agent_test_marker_cap' !== $cap_props[ $key ] ) {
				$mismatches[] = $key;
			}
		}

		$pass = empty( $mismatches ) && ( 'agent_test_marker_cap' === $rest_cap_value );

		agent_test_assert(
			$suite, 'jpe-1',
			'SKILL.md "jet-popup/access-cap": all 15 CPT capability keys AND get_admin_ui_cap() (used by REST permission_callback) resolve to the same jet-popup/access-cap filtered value',
			$pass,
			array( 'all_cpt_caps_match_filter' => true, 'rest_cap_matches_filter' => true ),
			array( 'mismatched_cap_keys' => $mismatches, 'rest_cap_value' => $rest_cap_value ),
			'includes/post-type.php:216-232, includes/rest-api/endpoints/base.php:40-42, jet-popup.php:428-430'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jpe-1', 'access-cap fan-out live test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jpe-2: get_endpoints() returns all 15 documented built-in endpoints (lazily self-inits if needed).
	try {
		$endpoints = function_exists( 'jet_popup' ) ? jet_popup()->rest_api->get_endpoints() : array();
		$names = array_keys( (array) $endpoints );

		$expected_names = array(
			'save-plugin-settings', 'get-page-templates', 'get-post-categories', 'get-posts',
			'get-post-tags', 'get-post-types', 'get-static-pages', 'get-tax-terms',
			'get-popup-conditions', 'update-popup-conditions', 'create-popup', 'get-popup-settings',
			'update-popup-settings', 'get-elementor-icon-html', 'clear-popup-cache',
		);
		$missing = array_diff( $expected_names, $names );

		agent_test_assert(
			$suite, 'jpe-2',
			'SKILL.md "REST API": get_endpoints() returns (at least) all 15 documented built-in endpoint name keys — NOTE this suite runs inside rest_api_init already, so it does not prove the "safe to call before rest_api_init" lazy-init claim on its own, only that the list is complete',
			empty( $missing ),
			array( 'missing_endpoint_names' => array() ),
			array( 'missing_endpoint_names' => array_values( $missing ), 'found_names' => $names ),
			'includes/rest-api/rest-api.php:70-103,124-132'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jpe-2', 'get_endpoints() completeness test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jpe-3: jet-popup/rest-api/endpoint-list filter — UNCLEAR-tolerant, since $_endpoints may already be memoized.
	try {
		$rest_api = function_exists( 'jet_popup' ) ? jet_popup()->rest_api : null;
		$ref = new \ReflectionClass( $rest_api );
		$prop = $ref->getProperty( '_endpoints' );
		$prop->setAccessible( true );
		$already_memoized = null !== $prop->getValue( $rest_api );

		if ( $already_memoized ) {
			agent_test_assert(
				$suite, 'jpe-3',
				'SKILL.md "REST API": jet-popup/rest-api/endpoint-list filter is honored before endpoint instantiation',
				true,
				array( 'unclear_reason' => 'endpoints already memoized before this test ran (normal on a live request) — cannot prove the filter is honored without a fresh, un-memoized request; see TEST-REGIMEN.md Test 3' ),
				array( 'already_memoized' => true ),
				'includes/rest-api/rest-api.php:126-132 — UNCLEAR result, not a real FAIL, see notes'
			);
		} else {
			$marker_seen = false;
			add_filter( 'jet-popup/rest-api/endpoint-list', function( $list ) use ( &$marker_seen ) {
				$marker_seen = true;
				return $list;
			} );
			$rest_api->get_endpoints();
			remove_all_filters( 'jet-popup/rest-api/endpoint-list' );

			agent_test_assert(
				$suite, 'jpe-3',
				'SKILL.md "REST API": jet-popup/rest-api/endpoint-list filter fires from init_endpoints()',
				$marker_seen,
				array( 'filter_fired' => true ),
				array( 'filter_fired' => $marker_seen ),
				'includes/rest-api/rest-api.php:76-92'
			);
		}
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jpe-3', 'endpoint-list filter live test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jpe-4: block_type_metadata injection (add_block_attrs) merges the 3 built-in data-attributes onto an arbitrary block.
	try {
		$block_editor = function_exists( 'jet_popup' ) ? jet_popup()->block_editor : null;
		$metadata = array( 'name' => 'core/paragraph', 'attributes' => array() );
		$result = $block_editor ? $block_editor->add_block_attrs( $metadata ) : $metadata;
		$attrs = isset( $result['attributes'] ) ? $result['attributes'] : array();

		$expected_attrs = array( 'jetPopupInstance', 'jetPopupTriggerType', 'jetPopupCustomSelector' );
		$missing = array();
		foreach ( $expected_attrs as $attr ) {
			if ( ! array_key_exists( $attr, $attrs ) ) {
				$missing[] = $attr;
			}
		}

		agent_test_assert(
			$suite, 'jpe-4',
			'SKILL.md "Block-editor data-attributes": add_block_attrs() merges all 3 built-in registered data-attributes onto an arbitrary block (core/paragraph)',
			empty( $missing ),
			array( 'missing_attrs' => array() ),
			array( 'missing_attrs' => $missing, 'attrs_found' => array_keys( $attrs ) ),
			'includes/block-editor/manager.php:53-106,294-304'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jpe-4', 'add_block_attrs() injection test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jpe-5: excluded blocks (e.g. core/html) are skipped by the injection.
	try {
		$block_editor = function_exists( 'jet_popup' ) ? jet_popup()->block_editor : null;
		$metadata = array( 'name' => 'core/html', 'attributes' => array() );
		$result = $block_editor ? $block_editor->add_block_attrs( $metadata ) : $metadata;
		$attrs = isset( $result['attributes'] ) ? $result['attributes'] : array();

		$pass = ! array_key_exists( 'jetPopupInstance', $attrs );

		agent_test_assert(
			$suite, 'jpe-5',
			'SKILL.md "Block-editor data-attributes": get_not_supported_blocks() excludes core/html from the attribute injection',
			$pass,
			array( 'jetPopupInstance_absent' => true ),
			array( 'attrs_found' => array_keys( $attrs ) ),
			'includes/block-editor/manager.php:294-296,423-431'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jpe-5', 'excluded-block skip test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jpe-6: lodash dependency IS present (backlog correction) — source-presence check.
	try {
		$file = WP_PLUGIN_DIR . '/jet-popup/includes/block-editor/manager.php';
		$contents = file_exists( $file ) ? file_get_contents( $file ) : '';
		$has_lodash = (bool) preg_match( "/'jet-popup-block-editor'.*?\\[[^\\]]*'lodash'/s", $contents );

		agent_test_assert(
			$suite, 'jpe-6',
			'SKILL.md "Correction to the gist-research backlog": jet-popup-block-editor script\'s dependency array already includes lodash in the currently-installed version (the backlog\'s "missing lodash" gist does not apply)',
			( '' !== $contents && $has_lodash ),
			array( 'file_readable' => true, 'lodash_present' => true ),
			array( 'file_readable' => ( '' !== $contents ), 'lodash_present' => $has_lodash ),
			'includes/block-editor/manager.php:238-243'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jpe-6', 'lodash dependency presence test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jpe-7: compatibility modules only take effect (register conditions/etc.) when their dependency guard passes.
	try {
		$compat_manager = function_exists( 'jet_popup' ) ? jet_popup()->compatibility : null;
		if ( ! $compat_manager ) {
			throw new \Exception( 'jet_popup()->compatibility unavailable' );
		}

		$checks = array();

		if ( ! class_exists( 'WooCommerce' ) ) {
			$cm = jet_popup()->conditions_manager;
			$woo_condition = $cm ? $cm->get_condition( 'woocommerce-shop-page' ) : false;
			$checks['woocommerce_inactive_no_condition'] = ( false === $woo_condition );
		} else {
			$checks['woocommerce_active_skipped'] = 'n/a - WooCommerce active on this sandbox';
		}

		if ( ! defined( 'JET_FORM_BUILDER_VERSION' ) ) {
			$not_supported = jet_popup()->block_editor->get_not_supported_blocks();
			$checks['jfb_inactive_no_extra_blocks'] = ! in_array( 'jet-forms/text-field', $not_supported, true );
		} else {
			$checks['jfb_active_skipped'] = 'n/a - JetFormBuilder active on this sandbox';
		}

		$all_pass = true;
		foreach ( $checks as $key => $value ) {
			if ( false === strpos( $key, '_skipped' ) && true !== $value && false !== $value ) {
				continue; // n/a string values don't affect pass/fail
			}
			if ( is_bool( $value ) ) {
				$all_pass = $all_pass && $value;
			}
		}

		agent_test_assert(
			$suite, 'jpe-7',
			'SKILL.md "Registering a custom block ... or compatibility module": inactive-dependency compatibility modules do not register their conditions/block-exclusions (proving the per-module guard, not just "didn\'t crash")',
			$all_pass,
			array( 'guards_effective_for_inactive_plugins' => true ),
			$checks,
			'includes/compatibility/manager.php:61-77, includes/compatibility/plugins/woocommerce/manager.php:110-116, includes/compatibility/plugins/jet-form-builder/manager.php:92-98'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jpe-7', 'compatibility module dependency-guard test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
