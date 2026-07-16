<?php
/**
 * AGENT-TEST-SUITE: jetengine-cct-internals
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetengine-cct-internals"),
 * alongside the always-active AGENT-TEST-CORE harness (test-harness/core-snippet.php).
 * Run via GET /agent-test/v1/suite/jetengine-cct-internals. See docs/test-harness-guide.md.
 *
 * Reuses existing fixtures rather than creating new ones: CCT `agent_test_cct` (id 15,
 * slug from jetengine-mcp-tools) and relation id 17 (posts::post -> cct::agent_test_cct,
 * from jetengine-relations). cct-2 creates and deletes its own throwaway row each run via
 * Item_Handler so repeated runs don't accumulate garbage rows.
 */

add_action( 'agent-test/run-suite/jetengine-cct-internals', function() {

	$suite = 'jetengine-cct-internals';

	// cct-1: CCT table naming ({prefix}jet_cct_{slug}) and _ID primary key, against the
	// live agent_test_cct fixture (row _ID 2, "AGENT TEST row B", left over from prior runs).
	try {
		global $wpdb;
		$table = $wpdb->prefix . 'jet_cct_agent_test_cct';
		$row   = $wpdb->get_row( "SELECT * FROM {$table} WHERE _ID = 2 LIMIT 1", ARRAY_A );
		agent_test_assert(
			$suite, 'cct-1',
			'SKILL.md "CCT tables": table is {prefix}jet_cct_{slug}, PK column is _ID (not ID/id)',
			( is_array( $row ) && isset( $row['_ID'] ) && '2' === (string) $row['_ID'] ),
			array( 'table' => '{prefix}jet_cct_agent_test_cct', 'row_found' => true, '_ID' => '2' ),
			array( 'table' => $table, 'row_found' => is_array( $row ), '_ID' => $row['_ID'] ?? null ),
			'includes/modules/custom-content-types (table naming), verified live 2026-07-16 via tool-add-cct + DESCRIBE (see jetengine-mcp-tools tests.php mcp-2)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'cct-1', 'CCT table/PK smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// cct-2: Item_Handler::update_item() insert (no _ID) then update (with _ID), then
	// raw_delete_item() cleans up — full roundtrip, self-cleaning so repeated runs don't
	// accumulate rows.
	try {
		$factory = class_exists( '\\Jet_Engine\\Modules\\Custom_Content_Types\\Module' )
			? \Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager->get_content_types( 'agent_test_cct' )
			: false;
		$handler   = $factory ? $factory->get_item_handler() : null;
		$new_id    = $handler ? $handler->update_item( array( 'title' => 'AGENT TEST roundtrip', 'status' => 'draft' ) ) : null;
		$is_new_id = is_int( $new_id ) || ( is_numeric( $new_id ) && ! is_wp_error( $new_id ) );
		$updated_id = ( $handler && $is_new_id ) ? $handler->update_item( array( '_ID' => $new_id, 'title' => 'AGENT TEST roundtrip (updated)' ) ) : null;
		global $wpdb;
		$table = $wpdb->prefix . 'jet_cct_agent_test_cct';
		$row_after_update = ( $is_new_id ) ? $wpdb->get_row( $wpdb->prepare( "SELECT title FROM {$table} WHERE _ID = %d", $new_id ), ARRAY_A ) : null;
		$deleted = ( $handler && $is_new_id ) ? $handler->raw_delete_item( $new_id ) : null;
		$row_after_delete = ( $is_new_id ) ? $wpdb->get_row( $wpdb->prepare( "SELECT _ID FROM {$table} WHERE _ID = %d", $new_id ), ARRAY_A ) : 'not_attempted';
		$pass = $is_new_id
			&& (int) $updated_id === (int) $new_id
			&& $row_after_update && $row_after_update['title'] === 'AGENT TEST roundtrip (updated)'
			&& null === $row_after_delete;
		agent_test_assert(
			$suite, 'cct-2',
			'SKILL.md "Writing CCT rows": update_item() inserts without _ID / updates with _ID (one method, same id returned), raw_delete_item() removes exactly that row',
			$pass,
			array( 'insert_returns_id' => true, 'update_reuses_id' => true, 'row_title_changed' => true, 'row_gone_after_delete' => true ),
			array( 'new_id' => $new_id, 'updated_id' => $updated_id, 'row_after_update' => $row_after_update, 'row_after_delete' => $row_after_delete ),
			'includes/modules/custom-content-types/inc/item-handler.php (update_item()/raw_delete_item()), verified live 2026-07-16 via probe id 20'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'cct-2', 'Item_Handler insert/update/delete roundtrip', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// cct-3: Relations manager has get_active_relations() keyed by numeric id, NOT a
	// get_relation($id) method; relation 17 (from jetengine-relations fixtures) resolves
	// to a Relation object exposing get_parents()/get_children().
	try {
		$manager       = function_exists( 'jet_engine' ) ? jet_engine()->relations : null;
		$has_get_relation = $manager ? method_exists( $manager, 'get_relation' ) : null;
		$relations     = $manager ? $manager->get_active_relations() : array();
		$relation_17   = $relations[17] ?? null;
		$has_methods   = $relation_17 ? ( method_exists( $relation_17, 'get_parents' ) && method_exists( $relation_17, 'get_children' ) ) : false;
		$pass = ( $manager !== null ) && ( $has_get_relation === false ) && is_array( $relations ) && ( $relation_17 !== null ) && $has_methods;
		agent_test_assert(
			$suite, 'cct-3',
			'SKILL.md "Querying a relation": no get_relation($id) method exists; get_active_relations() returns Relation objects keyed by numeric id, each with get_parents()/get_children()',
			$pass,
			array( 'get_relation_method_exists' => false, 'relation_17_resolves' => true, 'has_get_parents_children' => true ),
			array( 'manager_reachable' => $manager !== null, 'get_relation_method_exists' => $has_get_relation, 'relation_17_class' => $relation_17 ? get_class( $relation_17 ) : null, 'has_get_parents_children' => $has_methods ),
			'includes/components/relations/manager.php, relation.php — relation id 17 created 2026-07-16 for jetengine-relations tests.php'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'cct-3', 'Relations manager accessor smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
