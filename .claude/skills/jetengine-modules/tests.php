<?php
/**
 * AGENT-TEST-SUITE: jetengine-modules
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetengine-modules"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetengine-modules. See docs/test-harness-guide.md.
 *
 * These are reachability/gating smoke tests, not full pipeline tests (no meta-box field
 * groups, options pages, or data-store fixtures exist on the sandbox yet — see
 * TEST-REGIMEN.md for the still-open, heavier fixture-based tests).
 *
 * Dynamic Visibility and Data Stores are OPTIONAL JetEngine modules (gated by
 * jet_engine()->modules->is_module_active()), unlike Meta Boxes/Options Pages/
 * Glossaries/Custom Meta Tables, which are core components always loaded. mod-4/mod-5
 * are written to pass whether or not those two modules are active on this site —
 * they assert internal consistency (class loaded iff active), not that the module
 * happens to be turned on here.
 */

add_action( 'agent-test/run-suite/jetengine-modules', function() {

	$suite = 'jetengine-modules';

	// mod-1: Meta Boxes manager reachable, get_fields_for_context() returns an array (no fatal),
	// callable safely this late (well after init prio 11, since this runs on a REST request).
	try {
		$mb = function_exists( 'jet_engine' ) ? jet_engine()->meta_boxes : null;
		$class = $mb ? get_class( $mb ) : null;
		$fields = $mb ? $mb->get_fields_for_context( 'post_type' ) : null;
		agent_test_assert(
			$suite, 'mod-1',
			'SKILL.md "Meta Boxes": jet_engine()->meta_boxes is reachable and get_fields_for_context() returns an array',
			( $mb !== null && is_array( $fields ) ),
			array( 'reachable' => true, 'is_array' => true ),
			array( 'class' => $class, 'is_array' => is_array( $fields ), 'count' => is_array( $fields ) ? count( $fields ) : null ),
			'includes/components/meta-boxes/manager.php:472'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'mod-1', 'meta_boxes reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// mod-2: Options Pages manager reachable, registered_pages is an array (public property, not a method).
	try {
		$op = function_exists( 'jet_engine' ) ? jet_engine()->options_pages : null;
		$class = $op ? get_class( $op ) : null;
		$pages = $op ? $op->registered_pages : null;
		agent_test_assert(
			$suite, 'mod-2',
			'SKILL.md "Options Pages": jet_engine()->options_pages is reachable and ->registered_pages is an array keyed by page slug',
			( $op !== null && is_array( $pages ) ),
			array( 'reachable' => true, 'registered_pages_is_array' => true ),
			array( 'class' => $class, 'registered_pages_is_array' => is_array( $pages ), 'slugs' => is_array( $pages ) ? array_keys( $pages ) : null ),
			'includes/components/options-pages/manager.php:49'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'mod-2', 'options_pages reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// mod-3: Glossaries manager reachable (core component, always loaded — not module-gated).
	try {
		$gl = function_exists( 'jet_engine' ) ? jet_engine()->glossaries : null;
		$class = $gl ? get_class( $gl ) : null;
		$js_data = ( $gl && method_exists( $gl, 'get_glossaries_for_js' ) ) ? $gl->get_glossaries_for_js() : null;
		agent_test_assert(
			$suite, 'mod-3',
			'SKILL.md "Glossaries": jet_engine()->glossaries (\\Jet_Engine\\Glossaries\\Manager) is reachable and get_glossaries_for_js() returns without fatal',
			( $class === 'Jet_Engine\\Glossaries\\Manager' ),
			array( 'class' => 'Jet_Engine\\Glossaries\\Manager' ),
			array( 'class' => $class, 'get_glossaries_for_js_type' => gettype( $js_data ) ),
			'includes/core/components-manager.php:80-83 (registered as a default/always-on component), includes/components/glossaries/manager.php:19,111'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'mod-3', 'glossaries reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// mod-4: Dynamic Visibility is an OPTIONAL module — Condition_Checker class is loaded iff the
	// module is active, and when active, check_cond() with no configured conditions safely returns
	// true (the "not enabled" early-return) rather than fataling.
	try {
		$is_active = function_exists( 'jet_engine' ) ? jet_engine()->modules->is_module_active( 'dynamic-visibility' ) : null;
		$class_loaded = class_exists( '\\Jet_Engine\\Modules\\Dynamic_Visibility\\Condition_Checker' );
		$check_result = null;
		if ( $class_loaded ) {
			$checker = new \Jet_Engine\Modules\Dynamic_Visibility\Condition_Checker();
			$check_result = $checker->check_cond( array(), array() ); // jedv_enabled absent -> true, per source
		}
		$consistent = ( $is_active === $class_loaded );
		$pass = $consistent && ( ! $class_loaded || $check_result === true );
		agent_test_assert(
			$suite, 'mod-4',
			'SKILL.md "Dynamic Visibility": Condition_Checker is only loaded when the module is active (is_module_active() matches class_exists()), and check_cond() with no jedv_enabled flag returns true without fataling',
			$pass,
			array( 'is_module_active_matches_class_loaded' => true, 'check_cond_default_true_if_loaded' => true ),
			array( 'is_module_active' => $is_active, 'class_loaded' => $class_loaded, 'check_cond_result' => $check_result ),
			'includes/modules/dynamic-visibility/inc/module.php:208-216 (Module::instance() singleton, only constructed if active), includes/modules/dynamic-visibility/inc/conditions-checker.php:37-42 (is_enabled default-false early return)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'mod-4', 'Dynamic Visibility module-gating smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// mod-5: Data Stores is also an OPTIONAL module — Module::instance()->stores (the Stores\Manager)
	// is reachable iff the module is active; get_store() on a non-existent store id degrades
	// gracefully (no fatal) rather than throwing.
	try {
		$is_active = function_exists( 'jet_engine' ) ? jet_engine()->modules->is_module_active( 'data-stores' ) : null;
		$class_loaded = class_exists( '\\Jet_Engine\\Modules\\Data_Stores\\Module' );
		$stores_manager = null;
		$store_lookup = 'not_attempted';
		if ( $class_loaded ) {
			$module = \Jet_Engine\Modules\Data_Stores\Module::instance();
			$stores_manager = $module->stores;
			if ( $stores_manager && method_exists( $stores_manager, 'get_store' ) ) {
				$found = $stores_manager->get_store( 'agent_test_store_does_not_exist' );
				$store_lookup = var_export( $found, true );
			}
		}
		$consistent = ( $is_active === $class_loaded );
		$pass = $consistent && ( ! $class_loaded || $stores_manager !== null );
		agent_test_assert(
			$suite, 'mod-5',
			'SKILL.md "Data Stores": jet_engine()->data_stores-equivalent (Module::instance()->stores) is only loaded when the module is active, and get_store() on an unknown id does not fatal',
			$pass,
			array( 'is_module_active_matches_class_loaded' => true, 'no_fatal_on_unknown_store' => true ),
			array( 'is_module_active' => $is_active, 'class_loaded' => $class_loaded, 'stores_manager_class' => $stores_manager ? get_class( $stores_manager ) : null, 'unknown_store_lookup_result' => $store_lookup ),
			'includes/modules/data-stores/inc/module.php:146-... (Module::instance()), includes/modules/data-stores/inc/stores/manager.php:151'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'mod-5', 'Data Stores module-gating smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// mod-6: Custom Meta Tables — confirms the REAL namespace (found live during this suite's
	// first run, see SKILL.md's correction note): it's \Jet_Engine\CPT\Custom_Tables\Manager,
	// NOT \Jet_Engine\Custom_Tables\Manager as this skill originally documented.
	try {
		$wrong_ns_exists = class_exists( '\\Jet_Engine\\Custom_Tables\\Manager' );
		$right_ns_exists = class_exists( '\\Jet_Engine\\CPT\\Custom_Tables\\Manager' );
		$table_name = $right_ns_exists ? \Jet_Engine\CPT\Custom_Tables\Manager::instance()->get_table_name( 'agent_test_slug' ) : null;
		agent_test_assert(
			$suite, 'mod-6',
			'SKILL.md "Custom Meta Tables" (corrected): the real class is \\Jet_Engine\\CPT\\Custom_Tables\\Manager (singleton via ::instance()), not \\Jet_Engine\\Custom_Tables\\Manager — get_table_name() applies the "_meta" suffix',
			( $right_ns_exists && ! $wrong_ns_exists && $table_name === 'agent_test_slug_meta' ),
			array( 'right_namespace_exists' => true, 'wrong_namespace_exists' => false, 'table_name' => 'agent_test_slug_meta' ),
			array( 'right_namespace_exists' => $right_ns_exists, 'wrong_namespace_exists' => $wrong_ns_exists, 'table_name' => $table_name ),
			'includes/components/post-types/custom-tables/manager.php:2,76-83,519 — component always loaded via includes/components/post-types/manager.php:97 (require_once in the always-on cpt component)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'mod-6', 'Custom Meta Tables namespace smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// mod-7: Meta Boxes custom Options Source — the two-filter pairing
	// (jet-engine/meta-boxes/option-sources registers the name, jet-engine/meta-fields/field-options
	// supplies the values for a field whose options_source matches) both work end-to-end
	// through the real Jet_Engine_Meta_Boxes_Option_Sources / Jet_Engine_CPT_Meta classes.
	try {
		if ( ! class_exists( 'Jet_Engine_Meta_Boxes_Option_Sources' ) || ! class_exists( 'Jet_Engine_CPT_Meta' ) ) {
			throw new \Exception( 'Jet_Engine_Meta_Boxes_Option_Sources or Jet_Engine_CPT_Meta not loaded' );
		}

		add_filter( 'jet-engine/meta-boxes/option-sources', function( $sources ) {
			$sources['agent_test_source'] = 'Agent Test Source';
			return $sources;
		} );

		$captured_field = null;
		add_filter( 'jet-engine/meta-fields/field-options', function( $options, $field ) use ( &$captured_field ) {
			if ( 'agent_test_source' !== ( $field['options_source'] ?? '' ) ) {
				return $options;
			}
			$captured_field = $field;
			$options[]      = array( 'value' => 'agent_test_value', 'label' => 'Agent Test Value' );
			return $options;
		}, 10, 3 );

		$sources = Jet_Engine_Meta_Boxes_Option_Sources::instance()->get_allowed_sources();
		$post_meta = new Jet_Engine_CPT_Meta(); // no-arg constructor early-returns, safe to instantiate directly
		$options = $post_meta->filter_options_list( array(), array( 'options_source' => 'agent_test_source' ) );

		$source_registered = isset( $sources['agent_test_source'] );
		$option_present    = is_array( $options ) && in_array( array( 'value' => 'agent_test_value', 'label' => 'Agent Test Value' ), $options, true );

		$pass = $source_registered && $option_present && ( null !== $captured_field );

		agent_test_assert(
			$suite, 'mod-7',
			'SKILL.md "Adding a custom Options Source": jet-engine/meta-boxes/option-sources registers a new source name (shows in get_allowed_sources()), and jet-engine/meta-fields/field-options (3-arg: $options, $field, $this) supplies its option list when $field[\'options_source\'] matches — both wired through the real Jet_Engine_Meta_Boxes_Option_Sources / Jet_Engine_CPT_Meta::filter_options_list()',
			$pass,
			array( 'source_registered' => true, 'option_present' => true, 'field_options_filter_received_field' => true ),
			array( 'source_registered' => $source_registered, 'option_present' => $option_present, 'captured_field' => $captured_field ),
			'includes/components/meta-boxes/fields-options/option-sources.php:411 (get_allowed_sources()), includes/components/meta-boxes/post.php:1544-1546 (filter_options_list(), 3-arg apply_filters)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'mod-7', 'Meta Boxes custom option-source pair smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// mod-8: Data Stores post-count hooks (post-count-increased/decreased) fire from a
	// direct increase_post_count()/decrease_post_count() call (these two ARE public,
	// unlike add_to_store()/remove(), whose before/after hooks per SKILL.md only fire
	// from the AJAX handlers). Skipped gracefully if the module isn't active (mod-5
	// already covers that gating).
	try {
		$is_active = function_exists( 'jet_engine' ) ? jet_engine()->modules->is_module_active( 'data-stores' ) : false;
		$fired = array( 'increased' => null, 'decreased' => null );
		if ( $is_active && class_exists( '\\Jet_Engine\\Modules\\Data_Stores\\Module' ) ) {
			$manager = \Jet_Engine\Modules\Data_Stores\Module::instance()->stores;
			$manager->register_store( array( 'slug' => 'agent_test_store', 'type' => 'user-meta', 'count_posts' => true ) );
			$factory = $manager->get_store( 'agent_test_store' );
			if ( $factory ) {
				add_action( 'jet-engine/data-stores/post-count-increased', function( $post_id, $count, $f ) use ( &$fired ) {
					$fired['increased'] = array( 'post_id' => $post_id, 'count' => $count );
				}, 10, 3 );
				add_action( 'jet-engine/data-stores/post-count-decreased', function( $post_id, $count, $f ) use ( &$fired ) {
					$fired['decreased'] = array( 'post_id' => $post_id, 'count' => $count );
				}, 10, 3 );
				$factory->increase_post_count( 999999 );
				$factory->decrease_post_count( 999999 );
				remove_all_actions( 'jet-engine/data-stores/post-count-increased' );
				remove_all_actions( 'jet-engine/data-stores/post-count-decreased' );
				delete_user_meta( 999999, 'jet_engine_store_count_agent_test_store' ); // cleanup, post_id here is really a user id under user-meta type
			}
		}
		$pass = ( ! $is_active ) || ( is_array( $fired['increased'] ) && 1 === (int) $fired['increased']['count'] && is_array( $fired['decreased'] ) && 0 === (int) $fired['decreased']['count'] );
		agent_test_assert(
			$suite, 'mod-8',
			'SKILL.md "Data Stores" post-count hooks: increase_post_count()/decrease_post_count() (public, unlike add_to_store()) fire post-count-increased/decreased with (post_id, count, factory)',
			$pass,
			array( 'module_inactive_or_hooks_fired_with_right_counts' => true ),
			array( 'is_active' => $is_active, 'fired' => $fired ),
			'includes/modules/data-stores/inc/stores/factory.php:290-344 — temp store "agent_test_store" (type user-meta) registered/exercised in-request, not persisted'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'mod-8', 'Data Stores post-count hook test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// mod-9: Options Pages — register_new_options_page() is a real public method that
	// adds an entry to ->registered_pages, matching the SKILL.md claim that this (not a
	// filter) is the way to ship an options page programmatically.
	try {
		$op = function_exists( 'jet_engine' ) ? jet_engine()->options_pages : null;
		$before = $op ? array_key_exists( 'agent_test_options_page', $op->registered_pages ) : null;
		if ( $op ) {
			$op->register_new_options_page( array(
				'slug'   => 'agent_test_options_page',
				'title'  => 'Agent Test Options Page',
				'fields' => array(),
			) );
		}
		$after = $op ? array_key_exists( 'agent_test_options_page', $op->registered_pages ) : null;
		$is_factory = $after ? is_a( $op->registered_pages['agent_test_options_page'], 'Jet_Engine_Options_Page_Factory' ) : false;
		$pass = ( false === $before ) && ( true === $after ) && $is_factory;
		agent_test_assert(
			$suite, 'mod-9',
			'SKILL.md "Registering a whole options page programmatically": jet_engine()->options_pages->register_new_options_page($args) adds a real Jet_Engine_Options_Page_Factory entry to ->registered_pages',
			$pass,
			array( 'not_registered_before' => true, 'registered_after' => true, 'is_factory_instance' => true ),
			array( 'before' => $before, 'after' => $after, 'is_factory' => $is_factory ),
			'includes/components/options-pages/manager.php:122-126 — registers in-request only, not persisted to the site\'s actual options-pages config'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'mod-9', 'Options Pages programmatic registration test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// mod-10: Maps Listings Providers_Manager is reachable (module may or may not be
	// active) and, if active, get_providers('geocode', 'google') resolves to a real
	// provider instance with get_location_data() callable.
	try {
		$is_active = function_exists( 'jet_engine' ) ? jet_engine()->modules->is_module_active( 'maps-listings' ) : false;
		$class_loaded = class_exists( '\\Jet_Engine\\Modules\\Maps_Listings\\Module' );
		$provider_ok = 'not_attempted';
		if ( $is_active && $class_loaded ) {
			$providers = \Jet_Engine\Modules\Maps_Listings\Module::instance()->providers;
			$google = $providers ? $providers->get_providers( 'geocode', 'google' ) : null;
			$provider_ok = ( $google && method_exists( $google, 'get_location_data' ) );
		}
		$consistent = ( $is_active === $class_loaded );
		$pass = $consistent && ( ! $is_active || true === $provider_ok );
		agent_test_assert(
			$suite, 'mod-10',
			'SKILL.md "Maps Listings": Providers_Manager reachable via Module::instance()->providers iff module active; get_providers(\'geocode\',\'google\') resolves to an instance with get_location_data()',
			$pass,
			array( 'is_module_active_matches_class_loaded' => true, 'google_provider_resolves_if_active' => true ),
			array( 'is_active' => $is_active, 'class_loaded' => $class_loaded, 'provider_ok' => $provider_ok ),
			'includes/modules/maps-listings/inc/providers-manager.php:23-48'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'mod-10', 'Maps Listings providers reachability test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
