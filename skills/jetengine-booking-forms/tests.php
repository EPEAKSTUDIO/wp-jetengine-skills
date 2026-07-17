<?php
/**
 * AGENT-TEST-SUITE: jetengine-booking-forms
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetengine-booking-forms"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetengine-booking-forms. See docs/test-harness-guide.md.
 *
 * Fixtures: this suite creates throwaway `jet-engine-booking` (legacy Forms CPT) posts
 * and a throwaway "AGENT_TEST_ADF_POST" post carrying Advanced Date Field meta, each
 * force-deleted at the end of its own callback (wp_delete_post with $force_delete = true)
 * so the suite is safe to re-run. Requires both the `calendar` and `booking-forms`
 * JetEngine modules active (confirmed active on jackfruit.epeak.studio as of 2026-07-17).
 */

add_action( 'agent-test/run-suite/jetengine-booking-forms', function() {

	$suite = 'jetengine-booking-forms';

	// bf-1: both modules report active via the real gating accessor.
	try {
		$calendar_active = jet_engine()->modules->is_module_active( 'calendar' );
		$forms_active    = jet_engine()->modules->is_module_active( 'booking-forms' );

		agent_test_assert(
			$suite, 'bf-1',
			'SKILL.md "Module registration/activation": jet_engine()->modules->is_module_active() reports both calendar and booking-forms as active on this sandbox',
			( true === $calendar_active && true === $forms_active ),
			array( 'calendar' => true, 'booking-forms' => true ),
			array( 'calendar' => $calendar_active, 'booking-forms' => $forms_active ),
			'includes/modules/modules-manager.php:383-385 (is_module_active())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'bf-1', 'is_module_active() smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// bf-2: get_module('calendar') returns the real Jet_Engine_Module_Calendar instance.
	try {
		$module = jet_engine()->modules->get_module( 'calendar' );

		agent_test_assert(
			$suite, 'bf-2',
			'SKILL.md "Module registration/activation": jet_engine()->modules->get_module(\'calendar\') returns a Jet_Engine_Module_Calendar instance with module_id() calendar and module_name() "Dynamic Calendar"',
			( $module instanceof Jet_Engine_Module_Calendar && 'calendar' === $module->module_id() ),
			array( 'class' => 'Jet_Engine_Module_Calendar', 'module_id' => 'calendar' ),
			array( 'class' => $module ? get_class( $module ) : null, 'module_id' => $module ? $module->module_id() : null, 'module_name' => $module ? $module->module_name() : null ),
			'includes/modules/modules-manager.php:393-395 (get_module()); includes/modules/calendar/calendar.php:25-36'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'bf-2', 'get_module(calendar) smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// bf-3: get_module() with a bogus id degrades to false (negative path), doesn't fatal.
	try {
		$bogus = jet_engine()->modules->get_module( 'agent_test_nonexistent_module' );

		agent_test_assert(
			$suite, 'bf-3',
			'get_module() with a non-registered module id returns false (negative-path smoke test, confirms it is safe to call before checking existence)',
			( false === $bogus ),
			array( 'returned' => false ),
			array( 'returned' => var_export( $bogus, true ) ),
			'includes/modules/modules-manager.php:393-395 (get_module())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'bf-3', 'get_module() bogus-id smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// bf-4: get_calendar_group_keys() returns the 4 documented built-in group-by keys.
	try {
		$module = jet_engine()->modules->get_module( 'calendar' );
		$keys   = $module ? $module->get_calendar_group_keys() : array();

		$expected_keys = array( 'post_date', 'post_mod', 'meta_date', 'item_date' );
		$has_all       = ! array_diff( $expected_keys, array_keys( $keys ) );

		agent_test_assert(
			$suite, 'bf-4',
			'SKILL.md "Calendar module": get_calendar_group_keys() returns the 4 built-in group-by keys (post_date, post_mod, meta_date, item_date)',
			$has_all,
			array( 'keys' => $expected_keys ),
			array( 'keys' => array_keys( $keys ) ),
			'includes/modules/calendar/calendar.php:88-110 (get_calendar_group_keys())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'bf-4', 'get_calendar_group_keys() smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// bf-5: Advanced Date Field singleton + derived-key naming convention.
	try {
		$adf = Jet_Engine_Advanced_Date_Field::instance();

		$field_type   = $adf->field_type;
		$end_key      = $adf->data->end_date_field_name( 'my_date' );
		$config_key   = $adf->data->config_field_name( 'my_date' );

		$pass = ( 'advanced-date' === $field_type )
			&& ( 'my_date__end_date' === $end_key )
			&& ( 'my_date__config' === $config_key );

		agent_test_assert(
			$suite, 'bf-5',
			'SKILL.md "Advanced Date Field": Jet_Engine_Advanced_Date_Field::instance() is reachable, field_type is "advanced-date", and end_date_field_name()/config_field_name() derive the documented __end_date/__config suffixes',
			$pass,
			array( 'field_type' => 'advanced-date', 'end_key' => 'my_date__end_date', 'config_key' => 'my_date__config' ),
			array( 'field_type' => $field_type, 'end_key' => $end_key, 'config_key' => $config_key ),
			'includes/modules/calendar/advanced-date-field/manager.php:6-8,220-226; data.php:67-69,168-170'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'bf-5', 'Advanced Date Field singleton/derived-key test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// bf-6: Advanced Date Field live data round-trip (multi-row storage + get_date_pairs() correlation).
	try {
		$post_id = wp_insert_post( array(
			'post_title'  => 'AGENT_TEST_ADF_POST',
			'post_type'   => 'post',
			'post_status' => 'draft',
		) );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			throw new \Exception( 'could not create test post for Advanced Date Field round-trip' );
		}

		$adf   = Jet_Engine_Advanced_Date_Field::instance();
		$field = 'agent_test_adf_field';

		// Two manual date pairs: one with an end date, one without.
		$value = array(
			'dates' => array(
				array( 'date' => '2030-01-10', 'is_end_date' => true, 'end_date' => '2030-01-12' ),
				array( 'date' => '2030-02-01' ),
			),
		);

		$adf->data->update_field_with_value( $value, $post_id, $field );

		$dates      = $adf->data->get_dates( $post_id, $field );
		$end_dates  = $adf->data->get_end_dates( $post_id, $field );
		$pairs      = $adf->data->get_date_pairs( $post_id, $field );

		wp_delete_post( $post_id, true );

		$pass = is_array( $dates ) && 2 === count( $dates )
			&& is_array( $end_dates ) && 1 === count( $end_dates )
			&& is_array( $pairs ) && 2 === count( $pairs )
			&& in_array( false, array_column( $pairs, 'end' ), true ); // at least one pair has no end date

		agent_test_assert(
			$suite, 'bf-6',
			'SKILL.md "Advanced Date Field": each date occurrence is stored as its own get_post_meta($id,$field,false) row (not a single serialized value), and get_date_pairs() correlates start rows with their matching __end_date row, with unmatched starts reported as [\'end\' => false]',
			$pass,
			array( 'dates_count' => 2, 'end_dates_count' => 1, 'pairs_count' => 2, 'has_pair_with_no_end' => true ),
			array( 'dates_count' => is_array( $dates ) ? count( $dates ) : null, 'end_dates_count' => is_array( $end_dates ) ? count( $end_dates ) : null, 'pairs' => $pairs ),
			'includes/modules/calendar/advanced-date-field/data.php:78-160,222-379 (get_dates()/get_end_dates()/get_date_pairs()/add_field_data())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'bf-6', 'Advanced Date Field data round-trip test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// bf-7: jet_engine()->forms is the real Jet_Engine_Booking_Forms instance, only present because booking-forms is active.
	try {
		$forms = isset( jet_engine()->forms ) ? jet_engine()->forms : null;

		$pass = ( $forms instanceof Jet_Engine_Booking_Forms )
			&& ( 'jet-engine-booking' === $forms->slug() )
			&& ( $forms->booking === $forms ); // back-compat alias, manager.php:114

		agent_test_assert(
			$suite, 'bf-7',
			'SKILL.md "Legacy Forms module": jet_engine()->forms is a real Jet_Engine_Booking_Forms instance (only set because booking-forms is active), slug() returns the jet-engine-booking CPT slug, and the ->booking back-compat alias points at itself',
			$pass,
			array( 'instanceof' => true, 'slug' => 'jet-engine-booking', 'booking_alias_self' => true ),
			array( 'class' => $forms ? get_class( $forms ) : null, 'slug' => $forms ? $forms->slug() : null, 'booking_alias_self' => $forms ? ( $forms->booking === $forms ) : null ),
			'includes/modules/forms/forms.php:98-116 (create_instances()); manager.php:17-19,111-114,396-398'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'bf-7', 'jet_engine()->forms accessor test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// bf-8: the jet-engine-booking CPT is really registered (public, not shown in the normal admin menu).
	try {
		$exists     = post_type_exists( 'jet-engine-booking' );
		$post_type  = get_post_type_object( 'jet-engine-booking' );

		$pass = $exists
			&& $post_type
			&& true === (bool) $post_type->public
			&& false === (bool) $post_type->show_in_menu;

		agent_test_assert(
			$suite, 'bf-8',
			'SKILL.md "Legacy Forms module": the jet-engine-booking CPT is registered with public=true, show_in_menu=false (surfaced only via the module\'s own custom admin page)',
			$pass,
			array( 'exists' => true, 'public' => true, 'show_in_menu' => false ),
			array( 'exists' => $exists, 'public' => $post_type ? $post_type->public : null, 'show_in_menu' => $post_type ? $post_type->show_in_menu : null ),
			'includes/modules/forms/editor.php:27,631-678 (register_post_type())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'bf-8', 'jet-engine-booking CPT registration test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// bf-9: get_notification_types() includes the documented built-in notification types.
	try {
		$types    = jet_engine()->forms->get_notification_types();
		$expected = array( 'email', 'insert_post', 'register_user', 'update_user', 'update_options', 'hook', 'webhook', 'redirect', 'mailchimp', 'activecampaign', 'getresponse' );
		$has_all  = ! array_diff( $expected, array_keys( $types ) );

		agent_test_assert(
			$suite, 'bf-9',
			'SKILL.md "Legacy Forms module": get_notification_types() includes all documented built-in notification types',
			$has_all,
			array( 'types' => $expected ),
			array( 'types' => array_keys( $types ) ),
			'includes/modules/forms/manager.php:276-290 (get_notification_types())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'bf-9', 'get_notification_types() smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// bf-10: _form_data/_notifications_data meta round-trips through Editor::get_form_data()/get_notifications().
	try {
		$post_id = wp_insert_post( array(
			'post_title'  => 'AGENT_TEST_BOOKING_FORM',
			'post_type'   => 'jet-engine-booking',
			'post_status' => 'draft',
		) );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			throw new \Exception( 'could not create test jet-engine-booking post' );
		}

		$test_form_data = array( array( 'name' => 'agent_test_field', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 1 ) );
		$test_notifs    = array( array( 'type' => 'agent_test_notification', 'label' => 'Agent Test' ) );

		update_post_meta( $post_id, '_form_data', wp_slash( wp_json_encode( $test_form_data ) ) );
		update_post_meta( $post_id, '_notifications_data', wp_slash( wp_json_encode( $test_notifs ) ) );

		$read_form_data = jet_engine()->forms->editor->get_form_data( $post_id );
		$read_notifs    = jet_engine()->forms->editor->get_notifications( $post_id );

		wp_delete_post( $post_id, true );

		$pass = is_array( $read_form_data ) && isset( $read_form_data[0]['name'] ) && 'agent_test_field' === $read_form_data[0]['name']
			&& is_array( $read_notifs ) && isset( $read_notifs[0]['type'] ) && 'agent_test_notification' === $read_notifs[0]['type'];

		agent_test_assert(
			$suite, 'bf-10',
			'SKILL.md "Legacy Forms module": _form_data/_notifications_data JSON meta round-trips through Editor::get_form_data()/get_notifications()',
			$pass,
			array( 'form_data_field_name' => 'agent_test_field', 'notification_type' => 'agent_test_notification' ),
			array( 'read_form_data' => $read_form_data, 'read_notifs' => $read_notifs ),
			'includes/modules/forms/editor.php:53-102 (save_layout()), 451-465 (get_notifications()), 497-555 (get_form_data())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'bf-10', '_form_data/_notifications_data round-trip test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// bf-11: notification dispatch is a flat per-type action fan-out — a custom type's hook fires
	// with (notification_array, $notifications_instance) when Notifications::send() runs.
	try {
		$post_id = wp_insert_post( array(
			'post_title'  => 'AGENT_TEST_BOOKING_FORM_NOTIF',
			'post_type'   => 'jet-engine-booking',
			'post_status' => 'draft',
		) );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			throw new \Exception( 'could not create test jet-engine-booking post' );
		}

		$test_notifs = array( array( 'type' => 'agent_test_notification', 'label' => 'Agent Test' ) );
		update_post_meta( $post_id, '_notifications_data', wp_slash( wp_json_encode( $test_notifs ) ) );

		$seen = null;
		$cb   = function( $notification, $notifications_instance ) use ( &$seen ) {
			$seen = array(
				'notification_type' => is_array( $notification ) && isset( $notification['type'] ) ? $notification['type'] : null,
				'instance_class'    => is_object( $notifications_instance ) ? get_class( $notifications_instance ) : null,
			);
		};
		add_action( 'jet-engine/forms/booking/notification/agent_test_notification', $cb, 10, 2 );

		if ( ! class_exists( 'Jet_Engine_Booking_Forms_Notifications' ) ) {
			require_once jet_engine()->modules->modules_path( 'forms/notifications.php' );
		}

		$notifications = new Jet_Engine_Booking_Forms_Notifications( $post_id, array(), jet_engine()->forms, null );
		$notifications->send();

		remove_action( 'jet-engine/forms/booking/notification/agent_test_notification', $cb, 10 );

		wp_delete_post( $post_id, true );

		$pass = is_array( $seen )
			&& 'agent_test_notification' === $seen['notification_type']
			&& 'Jet_Engine_Booking_Forms_Notifications' === $seen['instance_class'];

		agent_test_assert(
			$suite, 'bf-11',
			'SKILL.md "Submission pipeline": Notifications::send() fires jet-engine/forms/booking/notification/{type} per configured notification with args ($notification, $this) — a custom type only needs to hook that action, no core-class edit required',
			$pass,
			array( 'notification_type' => 'agent_test_notification', 'instance_class' => 'Jet_Engine_Booking_Forms_Notifications' ),
			array( 'seen' => $seen ),
			'includes/modules/forms/notifications.php:204-227 (send())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'bf-11', 'notification dispatch fan-out live-fire test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// bf-12: calendar month-navigation AJAX signature check rejects a forged/mismatched settings
	// signature and accepts one freshly generated for the same payload.
	try {
		$module = jet_engine()->modules->get_module( 'calendar' );

		if ( ! $module || ! method_exists( $module, 'has_valid_settings_signature' ) ) {
			throw new \Exception( 'calendar module or has_valid_settings_signature() not available' );
		}

		$settings = array(
			'lisitng_id'      => 123,
			'renderer'        => '',
			'custom_query'    => false,
			'custom_query_id' => 0,
		);

		$real_signature            = $module->generate_settings_signature( $settings );
		$settings_with_real_sig    = $settings;
		$settings_with_real_sig['settings_signature'] = $real_signature;

		$settings_with_forged_sig = $settings;
		$settings_with_forged_sig['settings_signature'] = 'not_a_real_signature';

		$accepts_real   = $module->has_valid_settings_signature( $settings_with_real_sig );
		$rejects_forged = ! $module->has_valid_settings_signature( $settings_with_forged_sig );

		$pass = ! empty( $real_signature ) && $accepts_real && $rejects_forged;

		agent_test_assert(
			$suite, 'bf-12',
			'SKILL.md "Calendar module" (month-navigation AJAX): has_valid_settings_signature() accepts a signature freshly generated for a given settings payload and rejects a forged one, via hash_equals() comparison',
			$pass,
			array( 'accepts_real' => true, 'rejects_forged' => true ),
			array( 'real_signature_nonempty' => ! empty( $real_signature ), 'accepts_real' => $accepts_real, 'rejects_forged' => $rejects_forged ),
			'includes/modules/calendar/calendar.php:235-304 (get_calendar_signature_payload()/generate_settings_signature()/has_valid_settings_signature())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'bf-12', 'calendar AJAX settings-signature test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
