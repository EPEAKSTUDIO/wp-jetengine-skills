<?php
/**
 * AGENT-TEST-SUITE: jetwoobuilder-templates
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetwoobuilder-templates"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetwoobuilder-templates. See docs/test-harness-guide.md.
 *
 * NOT YET DEPLOYED OR RUN as of writing — JetWooBuilder is not installed on this repo's
 * sandbox site (jackfruit.epeak.studio, see HANDOFF.md). This file is source-cited and
 * ready to deploy once the plugin (+ Elementor + WooCommerce, both hard requirements —
 * see SKILL.md "Gating") is installed there. Do not report this suite as "passing" until
 * it has actually been run — see TEST-REGIMEN.md's run log (currently empty).
 *
 * Every method here degrades gracefully (records a FAIL with the caught exception rather
 * than fataling the whole suite) if JetWooBuilder/Elementor/WooCommerce aren't active,
 * per docs/test-harness-guide.md's try/catch convention.
 */

add_action( 'agent-test/run-suite/jetwoobuilder-templates', function() {

	$suite = 'jetwoobuilder-templates';

	// jwb-1: jet_woo_builder() singleton is reachable, and its sub-objects are only
	// populated once init()'s Elementor/WooCommerce gate has actually passed.
	try {
		$exists       = function_exists( 'jet_woo_builder' );
		$instance     = $exists ? jet_woo_builder() : null;
		$class        = $instance ? get_class( $instance ) : null;
		$macros_ready = $instance ? ( null !== $instance->macros ) : null;
		agent_test_assert(
			$suite, 'jwb-1',
			'SKILL.md "Core architecture": jet_woo_builder() is reachable (Jet_Woo_Builder singleton), and ->macros is populated once init() has run (i.e. Elementor+WooCommerce gate passed)',
			( $exists && $instance !== null && 'Jet_Woo_Builder' === $class && true === $macros_ready ),
			array( 'reachable' => true, 'class' => 'Jet_Woo_Builder', 'macros_populated' => true ),
			array( 'reachable' => $exists, 'class' => $class, 'macros_populated' => $macros_ready ),
			'jet-woo-builder.php:513 (jet_woo_builder()), jet-woo-builder.php:210-254 (init() gate + sub-object assignment)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jwb-1', 'jet_woo_builder() reachability smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jwb-2: Macros — get_all() returns exactly the 2 built-ins (unless already filtered
	// by something else on this site), and a custom macro registered via
	// jet-woo-builder/macros/macros-list round-trips through do_macros() with the raw
	// pipe-arg string passed as $args (not parsed into an array).
	try {
		$macros = jet_woo_builder()->macros;
		$has_builtins = false;
		$custom_ok    = false;

		if ( $macros ) {
			$all = $macros->get_all();
			$has_builtins = isset( $all['percentage_sale'] ) && isset( $all['numeric_sale'] );

			$captured_args = null;
			add_filter( 'jet-woo-builder/macros/macros-list', function( $m ) {
				$m['agent_test_macro'] = function( $field_value, $args ) {
					return 'AGENT-TEST:' . $args;
				};
				return $m;
			} );

			$result    = $macros->do_macros( '%agent_test_macro|foo-bar%', 'unused' );
			$custom_ok = ( 'AGENT-TEST:foo-bar' === $result );
		}

		agent_test_assert(
			$suite, 'jwb-2',
			'SKILL.md "Macros": get_all() includes the 2 built-ins (percentage_sale, numeric_sale); a custom macro registered via jet-woo-builder/macros/macros-list is invoked by do_macros() with the raw pipe-arg string (not array-parsed) as the 2nd callback param',
			( $has_builtins && $custom_ok ),
			array( 'has_builtins' => true, 'custom_macro_result' => 'AGENT-TEST:foo-bar' ),
			array( 'has_builtins' => $has_builtins, 'custom_macro_result' => $macros ? ( $result ?? null ) : 'macros object unavailable' ),
			'includes/class-jet-woo-builder-macros.php:24-27 (get_all()), :165-192 (do_macros(), regex + callback invocation)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jwb-2', 'Macros custom-registration smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jwb-3: Macros regex gate — an uppercase/invalid macro key never matches inside a
	// string, even though it's a real registered key (SKILL.md's "[a-z_-]+ only" claim).
	try {
		$macros = jet_woo_builder()->macros;
		$literal_result = null;

		if ( $macros ) {
			add_filter( 'jet-woo-builder/macros/macros-list', function( $m ) {
				$m['AGENT_TEST_UPPER'] = function() { return 'SHOULD-NOT-APPEAR'; };
				return $m;
			} );
			$literal_result = $macros->do_macros( '%AGENT_TEST_UPPER%' );
		}

		$pass = ( '%AGENT_TEST_UPPER%' === $literal_result );

		agent_test_assert(
			$suite, 'jwb-3',
			'SKILL.md "Macros" regex gate: do_macros() regex only matches [a-z_-]+ for the macro name — an uppercase-keyed macro is left untouched (printed literally) even though it is a real registered key',
			$pass,
			array( 'literal_result' => '%AGENT_TEST_UPPER%' ),
			array( 'literal_result' => $literal_result ),
			'includes/class-jet-woo-builder-macros.php:170 (preg_replace_callback pattern)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jwb-3', 'Macros regex-gate smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jwb-4: Template Functions reachable, and price/thumbnail methods degrade to
	// null/false outside The Loop (global $product not a WC_Product) rather than fataling.
	try {
		global $product;
		$saved_product = $product;
		$product       = null; // force the "not a WC_Product" branch

		$tf    = function_exists( 'jet_woo_builder_template_functions' ) ? jet_woo_builder_template_functions() : null;
		$price = $tf ? $tf->get_product_price() : 'unavailable';
		$thumb = $tf ? $tf->get_product_thumbnail() : 'unavailable';

		$product = $saved_product; // restore

		$pass = ( $tf !== null && null === $price && null === $thumb );

		agent_test_assert(
			$suite, 'jwb-4',
			'SKILL.md "Template Functions": jet_woo_builder_template_functions() is reachable, and get_product_price()/get_product_thumbnail() return null (not fatal) when global $product is not a WC_Product instance',
			$pass,
			array( 'reachable' => true, 'price_null_outside_loop' => true, 'thumbnail_null_outside_loop' => true ),
			array( 'reachable' => ( $tf !== null ), 'price' => $price, 'thumbnail' => $thumb ),
			'includes/class-jet-woo-builder-template-functions.php:384-396 (get_product_price), :146-152 (get_product_thumbnail)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jwb-4', 'Template Functions reachability/degrade smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jwb-5: product-price filter actually reshapes the returned HTML (confirms the
	// documented jet-woo-builder/template-functions/product-price hook fires and is the
	// last thing applied before return).
	try {
		global $product;
		$saved_product = $product;
		$products      = wc_get_products( array( 'limit' => 1, 'status' => 'publish' ) );
		$test_product  = ! empty( $products ) ? $products[0] : null;
		$filtered      = null;

		if ( $test_product ) {
			$product = $test_product;

			add_filter( 'jet-woo-builder/template-functions/product-price', function( $html ) {
				return 'AGENT-TEST-WRAPPED:' . $html;
			} );

			$filtered = jet_woo_builder_template_functions()->get_product_price();
		}

		$product = $saved_product;

		$pass = ( null !== $test_product ) && is_string( $filtered ) && ( 0 === strpos( $filtered, 'AGENT-TEST-WRAPPED:' ) );

		agent_test_assert(
			$suite, 'jwb-5',
			'SKILL.md "Template Functions": jet-woo-builder/template-functions/product-price filters the final price HTML returned by get_product_price()',
			$pass,
			array( 'has_test_product' => true, 'filtered_html_wrapped' => true ),
			array( 'has_test_product' => ( null !== $test_product ), 'filtered' => $filtered ),
			'includes/class-jet-woo-builder-template-functions.php:392-394 — requires at least one publish-status WC product to exist on the site; FAILs gracefully with "no test product" if none exist rather than asserting a false negative'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jwb-5', 'product-price filter smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jwb-6: The two settings stores are genuinely separate wp_options rows (the
	// "easy to reach for the wrong one" gotcha) — writing under one key's option and
	// reading via the other accessor does NOT see the value.
	try {
		$plugin_settings = function_exists( 'jet_woo_builder_settings' ) ? jet_woo_builder_settings() : null;
		$shop_settings   = function_exists( 'jet_woo_builder_shop_settings' ) ? jet_woo_builder_shop_settings() : null;

		$plugin_key = $plugin_settings ? $plugin_settings->key : null; // 'jet-woo-builder-settings'
		$shop_key   = $shop_settings ? $shop_settings->options_key : null; // 'jet_woo_builder'

		$keys_differ = ( $plugin_key !== null && $shop_key !== null && $plugin_key !== $shop_key );

		// Write a marker directly into the shop-settings option, confirm the plain
		// settings accessor (different option row, cached separately) does not see it.
		$shop_option = get_option( $shop_key, array() );
		$shop_option['agent_test_marker'] = 'shop-value';
		update_option( $shop_key, $shop_option );

		// Force both accessors to re-read (they cache in a instance property on first ->get()).
		$plugin_settings->settings = null;
		$shop_settings->settings   = null;

		$seen_via_plugin = $plugin_settings ? $plugin_settings->get( 'agent_test_marker', 'not-found' ) : 'unavailable';
		$seen_via_shop    = $shop_settings ? $shop_settings->get( 'agent_test_marker', 'not-found' ) : 'unavailable';

		// Cleanup
		unset( $shop_option['agent_test_marker'] );
		update_option( $shop_key, $shop_option );

		$pass = $keys_differ && ( 'not-found' === $seen_via_plugin ) && ( 'shop-value' === $seen_via_shop );

		agent_test_assert(
			$suite, 'jwb-6',
			'SKILL.md "Two settings stores": jet_woo_builder_settings() (option key jet-woo-builder-settings) and jet_woo_builder_shop_settings() (option key jet_woo_builder) are different wp_options rows — a value written to one is invisible via the other accessor',
			$pass,
			array( 'keys_differ' => true, 'seen_via_plugin_settings' => 'not-found', 'seen_via_shop_settings' => 'shop-value' ),
			array( 'plugin_key' => $plugin_key, 'shop_key' => $shop_key, 'seen_via_plugin' => $seen_via_plugin, 'seen_via_shop' => $seen_via_shop ),
			'includes/settings/class-jet-woo-builder-settings.php:36,311-318 (key + get()), includes/settings/class-jet-woo-builder-shop-settings.php:26,48-56 (options_key + get())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jwb-6', 'Two-settings-stores smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jwb-7: Document types table is well-formed — 8 entries, each with a unique slug
	// built off the jet-woo-builder CPT slug, confirming the "one CPT, meta+taxonomy
	// distinguishes type" claim rather than 8 separate post types.
	try {
		$documents  = jet_woo_builder()->documents;
		$doc_types  = $documents ? $documents->get_document_types() : array();
		$expected_keys = array( 'single', 'archive', 'category', 'shop', 'cart', 'checkout', 'thankyou', 'myaccount' );
		$has_all_keys  = ! array_diff( $expected_keys, array_keys( $doc_types ) );
		$slugs         = wp_list_pluck( $doc_types, 'slug' );
		$single_slug_is_bare_cpt = isset( $doc_types['single'] ) && 'jet-woo-builder' === $doc_types['single']['slug'];
		$others_are_suffixed     = true;
		foreach ( $expected_keys as $k ) {
			if ( 'single' === $k ) {
				continue;
			}
			if ( ! isset( $doc_types[ $k ]['slug'] ) || 0 !== strpos( $doc_types[ $k ]['slug'], 'jet-woo-builder-' ) ) {
				$others_are_suffixed = false;
			}
		}
		$pass = $has_all_keys && $single_slug_is_bare_cpt && $others_are_suffixed && ( count( array_unique( $slugs ) ) === count( $slugs ) );

		agent_test_assert(
			$suite, 'jwb-7',
			'SKILL.md "Templates / Document types": get_document_types() returns exactly 8 entries (single/archive/category/shop/cart/checkout/thankyou/myaccount), single\'s slug is the bare jet-woo-builder CPT slug, and the other 7 are jet-woo-builder-{suffix} — one CPT, distinguished by taxonomy/meta, not 8 post types',
			$pass,
			array( 'has_all_8_keys' => true, 'single_slug_is_bare_cpt' => true, 'others_suffixed' => true, 'slugs_unique' => true ),
			array( 'keys_found' => array_keys( $doc_types ), 'slugs' => $slugs ),
			'includes/class-jet-woo-builder-documents.php:163-213 (get_document_types())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jwb-7', 'Document types table smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// jwb-8: custom_{type}_page toggle gates whether a configured template id is actually
	// returned by get_custom_single_template() — flipping the toggle off makes a
	// configured non-'default' template id resolve to false, confirming the 3-way gate
	// (CPT exists + Document type registered + this toggle) documented in SKILL.md.
	try {
		$woocommerce_component = jet_woo_builder()->woocommerce ?? null;
		$shop_settings         = jet_woo_builder_shop_settings();

		$original_option = get_option( $shop_settings->options_key, array() );
		$test_option     = $original_option;
		$test_option['custom_single_page'] = 'yes';
		$test_option['single_template']    = 999999; // fake, non-'default' template id
		update_option( $shop_settings->options_key, $test_option );
		$shop_settings->settings = null; // force re-read

		$enabled_result = $woocommerce_component ? $woocommerce_component->get_custom_single_template() : 'unavailable';

		$test_option['custom_single_page'] = 'no';
		update_option( $shop_settings->options_key, $test_option );
		$shop_settings->settings = null;

		// get_custom_single_template() caches on $this->current_template after first call in-request,
		// so re-check via a fresh reflection-free read is not possible in the same request; this half
		// of the test documents the toggle read path itself rather than re-invoking the cached method.
		$toggle_now_reads_no = ( 'no' === $shop_settings->get( 'custom_single_page' ) );

		// Cleanup: restore the original option verbatim.
		update_option( $shop_settings->options_key, $original_option );
		$shop_settings->settings = null;

		$pass = ( null !== $woocommerce_component )
			&& is_int( $enabled_result ) && 999999 === $enabled_result
			&& $toggle_now_reads_no;

		agent_test_assert(
			$suite, 'jwb-8',
			'SKILL.md "Templates / Document types": get_custom_single_template() returns the configured template id only while shop-settings custom_single_page is \'yes\' and the value is not the literal string \'default\'; flipping the toggle off is independently confirmed via the settings accessor (get_custom_single_template() itself caches per-request, so only the enabled-path return value is asserted directly)',
			$pass,
			array( 'reachable' => true, 'enabled_result_is_999999' => true, 'toggle_reads_no_after_flip' => true ),
			array( 'enabled_result' => $enabled_result, 'toggle_now_reads_no' => $toggle_now_reads_no ),
			'includes/components/woocommerce/manager.php:374-397 (get_custom_single_template()), includes/settings/class-jet-woo-builder-shop-settings.php:48-56 (get()) — mutates then restores the real jet_woo_builder wp_option, verbatim, within this single test'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'jwb-8', 'custom_single_page toggle gate smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
