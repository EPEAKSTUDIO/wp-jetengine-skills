<?php
/**
 * AGENT-TEST-SUITE: jetmenu-structure
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetmenu-structure"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetmenu-structure. See docs/test-harness-guide.md.
 *
 * NOT YET RUNNABLE: JetMenu is not installed on the sandbox (jackfruit.epeak.studio) as
 * of this writing — see TEST-REGIMEN.md's "BLOCKED" run log. Written as-if-ready,
 * following this repo's safe smoke-test conventions: class/accessor-reachability checks
 * and direct method calls with safe fake ids, never re-instantiating a singleton the
 * plugin bootstrap already constructed (see SKILL.md's "Bootstrap" section — several of
 * these classes fatal with "Cannot redeclare class" on a second `new`).
 */

add_action( 'agent-test/run-suite/jetmenu-structure', function() {

	$suite = 'jetmenu-structure';

	// jms-1: jet_menu() singleton is reachable and exposes the documented manager properties
	// (all constructed once during Jet_Menu::init(), never re-instantiated by this suite).
	try {
		$plugin = function_exists( 'jet_menu' ) ? jet_menu() : null;
		$has_managers = $plugin
			&& $plugin->post_type_manager instanceof \Jet_Menu\Menu_Post_Type
			&& $plugin->settings_manager instanceof \Jet_Menu\Settings_Manager
			&& $plugin->render_manager instanceof \Jet_Menu\Render\Manager
			&& $plugin->elementor_manager instanceof \Jet_Menu\Elementor;
		agent_test_assert(
			$suite, 'jms-1',
			'SKILL.md "Bootstrap": jet_menu() exposes post_type_manager/settings_manager/render_manager/elementor_manager, all already-constructed instances',
			(bool) $has_managers,
			array( 'has_managers' => true ),
			array( 'has_managers' => $has_managers, 'version' => $plugin ? $plugin->get_version() : null ),
			'jet-menu.php:212-237'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jms-1', 'jet_menu() manager reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jms-2: Rest_Api is NOT exposed as a jet_menu() property (the one exception to the pattern above) —
	// confirm the real accessor is the class's own ::get_instance().
	try {
		$plugin = function_exists( 'jet_menu' ) ? jet_menu() : null;
		$no_rest_prop = $plugin && ! isset( $plugin->rest_api );
		$rest_api = class_exists( '\Jet_Menu\Rest_Api' ) ? \Jet_Menu\Rest_Api::get_instance() : null;
		$namespace = $rest_api ? $rest_api->api_namespace : null;
		agent_test_assert(
			$suite, 'jms-2',
			'SKILL.md "Bootstrap": jet_menu()->rest_api does not exist; \Jet_Menu\Rest_Api::get_instance() is the real accessor, exposing api_namespace = "jet-menu-api/v2"',
			( $no_rest_prop && 'jet-menu-api/v2' === $namespace ),
			array( 'no_rest_prop' => true, 'api_namespace' => 'jet-menu-api/v2' ),
			array( 'no_rest_prop' => $no_rest_prop, 'api_namespace' => $namespace ),
			'jet-menu.php:219 (constructed but not assigned to a property), includes/rest-api/rest-api.php:28,42-49'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jms-2', 'Rest_Api accessor smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jms-3: the jet-menu CPT is registered with the documented args (show_in_menu false, show_in_nav_menus false, has_archive false).
	try {
		$post_type_obj = get_post_type_object( 'jet-menu' );
		$matches = $post_type_obj
			&& false === $post_type_obj->show_in_menu
			&& false === $post_type_obj->show_in_nav_menus
			&& false === $post_type_obj->has_archive
			&& true === $post_type_obj->public;
		agent_test_assert(
			$suite, 'jms-3',
			'SKILL.md "The Mega Menu Items CPT (jet-menu)": registered with public=true, show_in_menu=false, show_in_nav_menus=false, has_archive=false',
			(bool) $matches,
			array( 'public' => true, 'show_in_menu' => false, 'show_in_nav_menus' => false, 'has_archive' => false ),
			array(
				'exists'            => (bool) $post_type_obj,
				'public'            => $post_type_obj ? $post_type_obj->public : null,
				'show_in_menu'      => $post_type_obj ? $post_type_obj->show_in_menu : null,
				'show_in_nav_menus' => $post_type_obj ? $post_type_obj->show_in_nav_menus : null,
				'has_archive'       => $post_type_obj ? $post_type_obj->has_archive : null,
			),
			'includes/menu-post-type.php:293-329'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jms-3', 'jet-menu CPT registration args smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jms-4: jet_menu()->settings_manager->options_manager is the only safe accessor for Options_Manager —
	// \Jet_Menu\Options_Manager::get_instance() is a FATAL (uncatchable "Cannot redeclare class"), not merely a
	// desync, because its constructor's init_options() does an unconditional `require $path` per options-module
	// file (options.php:1467). Live-verified 2026-07-16: calling ::get_instance() directly 500s the whole request
	// with no catchable exception, so this test deliberately does NOT call it — source-presence check only.
	try {
		$real_is_live_object = jet_menu()->settings_manager->options_manager instanceof \Jet_Menu\Options_Manager;
		$options_source = file_get_contents( jet_menu()->plugin_path( 'includes/settings/options.php' ) );
		$has_require_not_require_once = (bool) preg_match( '/\brequire\s+\$path\s*;/', $options_source )
			&& ! preg_match( '/\brequire_once\s+\$path\s*;/', $options_source );
		$pass = $real_is_live_object && $has_require_not_require_once;
		agent_test_assert(
			$suite, 'jms-4',
			'SKILL.md "Bootstrap" landmine: jet_menu()->settings_manager->options_manager is the ONLY safe Options_Manager accessor; \Jet_Menu\Options_Manager::get_instance() is a fatal (unconditional `require $path` per options module in init_options(), not require_once) — NOT live-called here on purpose, source-presence check only',
			$pass,
			array( 'real_is_live_object' => true, 'uses_require_not_require_once' => true ),
			array( 'real_is_live_object' => $real_is_live_object, 'uses_require_not_require_once' => $has_require_not_require_once ),
			'includes/settings/manager.php:70, includes/settings/options.php:1430-1477 (unconditional require at :1467), confirmed live via isolated diagnostic snippet (500 Internal Server Error, no catchable exception)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jms-4', 'Options_Manager safe-accessor source-presence check', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jms-5: get_menu_item_settings() merges defaults from default_nav_item_controls_data() -
	// a non-existent item id should still return a full settings array (all default keys present), not empty/false.
	try {
		$fake_item_id = 999999999; // does not exist
		$settings = jet_menu()->settings_manager->get_menu_item_settings( $fake_item_id );
		$expected_keys = array( 'enabled', 'content_type', 'custom_mega_menu_position', 'menu_icon_type', 'dynamic_visibility' );
		$has_all_keys = is_array( $settings );
		foreach ( $expected_keys as $key ) {
			$has_all_keys = $has_all_keys && array_key_exists( $key, $settings );
		}
		agent_test_assert(
			$suite, 'jms-5',
			'SKILL.md "Where mega-menu data actually lives": get_menu_item_settings() merges default_nav_item_controls_data() as fallback via wp_parse_args, so even a non-existent item id returns the full default schema',
			$has_all_keys,
			array( 'has_all_default_keys' => true ),
			array( 'has_all_default_keys' => $has_all_keys, 'settings' => $settings ),
			'includes/settings/manager.php:1019-1049,555-668'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jms-5', 'get_menu_item_settings() default-merge smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jms-6: Base_Render is abstract and cannot be instantiated directly; its two concrete subclasses exist and extend it.
	try {
		$base_is_abstract = false;
		if ( class_exists( '\Jet_Menu\Render\Base_Render' ) ) {
			$reflection = new \ReflectionClass( '\Jet_Menu\Render\Base_Render' );
			$base_is_abstract = $reflection->isAbstract();
		}
		$elementor_render_ok = class_exists( '\Jet_Menu\Render\Elementor_Content_Render' )
			&& is_subclass_of( '\Jet_Menu\Render\Elementor_Content_Render', '\Jet_Menu\Render\Base_Render' );
		$block_render_ok = class_exists( '\Jet_Menu\Render\Block_Editor_Content_Render' )
			&& is_subclass_of( '\Jet_Menu\Render\Block_Editor_Content_Render', '\Jet_Menu\Render\Base_Render' );
		agent_test_assert(
			$suite, 'jms-6',
			'SKILL.md "The render pipeline": Base_Render is abstract; Elementor_Content_Render and Block_Editor_Content_Render both extend it',
			( $base_is_abstract && $elementor_render_ok && $block_render_ok ),
			array( 'base_is_abstract' => true, 'elementor_render_extends_base' => true, 'block_render_extends_base' => true ),
			array( 'base_is_abstract' => $base_is_abstract, 'elementor_render_extends_base' => $elementor_render_ok, 'block_render_extends_base' => $block_render_ok ),
			'includes/render/base-render.php:4,125,132, includes/render/render-modules/elementor-template-render.php:9, includes/render/render-modules/block-editor-template-render.php:9'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jms-6', 'Base_Render class hierarchy smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jms-7: Block_Editor_Content_Render::render() on a non-existent template id returns false / empty content, no fatal
	// (safe direct call, per HANDOFF.md's "check singleton/constructor safety first" lesson - Base_Render subclasses
	// are plain value objects, not singletons the bootstrap already owns, so direct instantiation here is safe).
	try {
		$render_instance = new \Jet_Menu\Render\Block_Editor_Content_Render( array(
			'template_id' => 999999999,
			'with_css'    => true,
			'is_content'  => true,
		) );
		$content = $render_instance->get_content();
		$pass = ( '' === trim( (string) $content ) );
		agent_test_assert(
			$suite, 'jms-7',
			'SKILL.md "The render pipeline": Block_Editor_Content_Render::render() returns/echoes nothing for a non-existent template_id, no fatal (get_post() returns null, guarded at block-editor-template-render.php:78-82)',
			$pass,
			array( 'content_is_empty' => true ),
			array( 'content_is_empty' => $pass, 'content' => $content ),
			'includes/render/render-modules/block-editor-template-render.php:70-82'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jms-7', 'Block_Editor_Content_Render safe-fake-id smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jms-8: REST endpoint base permission_callback is a signature check (hash_equals), not a capability check -
	// confirm generate_signature() is deterministic for the same template id and differs for a different one.
	try {
		$endpoint = class_exists( '\Jet_Menu\Endpoints\Get_Elementor_Template_Content' )
			? new \Jet_Menu\Endpoints\Get_Elementor_Template_Content()
			: null;
		$sig_a1 = $endpoint ? $endpoint->generate_signature( 123 ) : null;
		$sig_a2 = $endpoint ? $endpoint->generate_signature( 123 ) : null;
		$sig_b  = $endpoint ? $endpoint->generate_signature( 456 ) : null;
		$pass = ( $sig_a1 && $sig_a1 === $sig_a2 && $sig_a1 !== $sig_b );
		agent_test_assert(
			$suite, 'jms-8',
			'SKILL.md "REST API": Endpoints\Base::generate_signature($template_id) is deterministic per template id (md5 of unique site id + template id + NONCE_KEY) and differs across ids - the real mechanism behind the default (non-overridden) permission_callback()',
			$pass,
			array( 'deterministic_and_distinct' => true ),
			array( 'sig_a1' => $sig_a1, 'sig_a2' => $sig_a2, 'sig_b' => $sig_b ),
			'includes/rest-api/endpoints/base.php:41-52,104-116'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jms-8', 'REST signature-check smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jms-9: get-menu-items endpoint permission_callback is unconditionally public (returns true), unlike the base class.
	try {
		$endpoint = class_exists( '\Jet_Menu\Endpoints\Get_Menu_Items' ) ? new \Jet_Menu\Endpoints\Get_Menu_Items() : null;
		$is_public = $endpoint ? true === $endpoint->permission_callback( new \WP_REST_Request() ) : false;
		agent_test_assert(
			$suite, 'jms-9',
			'SKILL.md "REST API": Get_Menu_Items::permission_callback() overrides the base signature check and unconditionally returns true - a fully public, unauthenticated endpoint',
			$is_public,
			array( 'permission_callback_returns_true' => true ),
			array( 'permission_callback_returns_true' => $is_public ),
			'includes/rest-api/endpoints/get-menu-items.php:78-80'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jms-9', 'get-menu-items public-endpoint smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jms-10: is_mega_enabled() truthiness discrepancy between the two nextgen walkers (only meaningful in nextgen mode -
	// guard on class existence, since legacy mode loads a differently-named walker, Main_Walker, instead).
	try {
		if ( ! class_exists( '\Jet_Menu\Render\Mega_Menu_Walker' ) || ! class_exists( '\Jet_Menu\Render\Vertical_Menu_Walker' ) ) {
			throw new \Exception( 'Nextgen walker classes not loaded - site is likely in legacy mode (loads Main_Walker instead), see SKILL.md "NextGen vs legacy mode"' );
		}
		// Both walkers memoize per-item settings via get_settings(), fed by jet_menu()->settings_manager->get_menu_item_settings().
		// We can't cheaply fabricate a real nav_menu_item post here without mutating state, so this is a source-presence
		// check only: confirm the two methods exist and are independently defined (not inherited from a shared trait/base
		// that would make the documented discrepancy impossible).
		$mega_walker_method = new \ReflectionMethod( '\Jet_Menu\Render\Mega_Menu_Walker', 'is_mega_enabled' );
		$vertical_walker_method = new \ReflectionMethod( '\Jet_Menu\Render\Vertical_Menu_Walker', 'is_mega_enabled' );
		$independently_defined = (
			$mega_walker_method->getDeclaringClass()->getName() === '\Jet_Menu\Render\Mega_Menu_Walker' || $mega_walker_method->getDeclaringClass()->getName() === 'Jet_Menu\Render\Mega_Menu_Walker'
		) && (
			$vertical_walker_method->getDeclaringClass()->getName() === '\Jet_Menu\Render\Vertical_Menu_Walker' || $vertical_walker_method->getDeclaringClass()->getName() === 'Jet_Menu\Render\Vertical_Menu_Walker'
		);
		agent_test_assert(
			$suite, 'jms-10',
			'SKILL.md "Gotcha: is_mega_enabled() truthiness check differs": both walkers independently define their own is_mega_enabled(), consistent with the documented FILTER_VALIDATE_BOOLEAN vs loose string-comparison discrepancy (source-presence check; a real settings fixture is needed to trigger the actual behavioral difference)',
			$independently_defined,
			array( 'independently_defined' => true ),
			array( 'independently_defined' => $independently_defined ),
			'includes/render/walkers/mega-menu-walker.php:616-620, includes/render/walkers/vertical-menu-walker.php:485-489'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jms-10', 'is_mega_enabled() discrepancy source-presence smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
