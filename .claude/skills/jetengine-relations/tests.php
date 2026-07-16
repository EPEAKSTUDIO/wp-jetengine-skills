<?php
/**
 * AGENT-TEST-SUITE: jetengine-relations
 *
 * Deploy as its own Code Snippets snippet (name: "AGENT-TEST-SUITE: jetengine-relations"),
 * alongside the always-active AGENT-TEST-CORE harness. Run via
 * GET /agent-test/v1/suite/jetengine-relations. See docs/test-harness-guide.md.
 *
 * Reuses relation id 17 (posts::post -> cct::agent_test_cct, created 2026-07-16) and CCT
 * row _ID 2 (agent_test_cct) as fixtures. rel-2/rel-3 create and remove their own link
 * each run (parent post id 1, child cct row 2) so repeated runs stay clean — no link is
 * left behind after this suite finishes.
 */

add_action( 'agent-test/run-suite/jetengine-relations', function() {

	$suite = 'jetengine-relations';

	// rel-1: relation TYPE is a fixed 3-value enum, distinct from object type.
	try {
		$manager = function_exists( 'jet_engine' ) ? jet_engine()->relations : null;
		$types   = $manager ? array_keys( $manager->get_relations_types() ) : null;
		$expected = array( 'one_to_one', 'one_to_many', 'many_to_many' );
		agent_test_assert(
			$suite, 'rel-1',
			'SKILL.md "Relation types vs. object types": get_relations_types() enumerates exactly one_to_one/one_to_many/many_to_many',
			( is_array( $types ) && array() === array_diff( $expected, $types ) && array() === array_diff( $types, $expected ) ),
			$expected,
			$types,
			'includes/components/relations/manager.php:170-176'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'rel-1', 'get_relations_types() smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// rel-2 + rel-3: update()/get_children()/delete_rows() full roundtrip on relation 17,
	// plus idempotency (calling update() twice with the same pair returns the SAME row,
	// not a duplicate). Self-cleaning: deletes the link it creates before returning.
	try {
		$manager  = function_exists( 'jet_engine' ) ? jet_engine()->relations : null;
		$relation = $manager ? ( $manager->get_active_relations( 17 ) ) : null;
		$parent_id = 1; // real 'post' post, "Hello world!"
		$child_id  = 2; // agent_test_cct row left over from jetengine-cct-internals tests

		$before = $relation ? $relation->get_children( $parent_id ) : null;
		$row_a  = $relation ? $relation->update( $parent_id, $child_id ) : null;
		$row_b  = $relation ? $relation->update( $parent_id, $child_id ) : null; // idempotency check
		$after  = $relation ? $relation->get_children( $parent_id ) : null;
		$relation && $relation->delete_rows( $parent_id, $child_id ); // cleanup
		$after_delete = $relation ? $relation->get_children( $parent_id ) : null;

		$row_a_shaped = is_array( $row_a ) && isset( $row_a['parent_object_id'], $row_a['child_object_id'] );
		$idempotent   = $row_a_shaped && is_array( $row_b ) && isset( $row_a['_ID'], $row_b['_ID'] ) && $row_a['_ID'] === $row_b['_ID'];

		agent_test_assert(
			$suite, 'rel-2',
			'SKILL.md "Creating, updating, and removing a relation LINK": update() creates a link and returns a get_children()-shaped row (not a bare bool/id); delete_rows() removes it',
			( empty( $before ) && $row_a_shaped && count( $after ) === 1 && empty( $after_delete ) ),
			array( 'before_empty' => true, 'update_returns_row_shape' => true, 'after_has_one_row' => true, 'after_delete_empty' => true ),
			array( 'before' => $before, 'update_result' => $row_a, 'after' => $after, 'after_delete' => $after_delete ),
			'includes/components/relations/relation.php:1438 (update()), :885 (delete_rows())'
		);
		agent_test_assert(
			$suite, 'rel-3',
			'SKILL.md "update() is idempotent, not additive": calling update() twice with the same parent/child pair returns the SAME row (same _ID), not a duplicate',
			$idempotent,
			array( 'same_row_id_both_calls' => true ),
			array( 'row_a_id' => $row_a['_ID'] ?? null, 'row_b_id' => $row_b['_ID'] ?? null ),
			'includes/components/relations/relation.php:1446-1454 ($exists = ...; if (!empty($exists)) return $exists[0];)'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'rel-2', 'update()/get_children()/delete_rows() roundtrip', false, 'no exception', $e->getMessage(), 'THREW' );
		agent_test_assert( $suite, 'rel-3', 'update() idempotency', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// rel-4: bulk get_children() accepts an array of ids without fataling (the N+1-query-avoidance claim).
	try {
		$manager  = function_exists( 'jet_engine' ) ? jet_engine()->relations : null;
		$relation = $manager ? $manager->get_active_relations( 17 ) : null;
		$rows     = $relation ? $relation->get_children( array( 1, 999999 ), 'all' ) : null;
		agent_test_assert(
			$suite, 'rel-4',
			'SKILL.md "Bulk-fetching relations": get_children()/get_parents() accept an array of ids (not just a single id) without fataling',
			is_array( $rows ),
			array( 'array_input_accepted' => true ),
			array( 'result_is_array' => is_array( $rows ), 'count' => is_array( $rows ) ? count( $rows ) : null ),
			'includes/components/relations/relation.php:674-757'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'rel-4', 'bulk get_children() array-input smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// rel-5: update_meta()/get_meta() silently no-op when the relation's dedicated _meta
	// table doesn't exist (real, reproducible gotcha) — confirms no fatal/WP_Error surfaces
	// either way, and get_meta() returns false in that state.
	try {
		global $wpdb;
		$meta_table_exists = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}jet_rel_17_meta'" );
		$manager  = function_exists( 'jet_engine' ) ? jet_engine()->relations : null;
		$relation = $manager ? $manager->get_active_relations( 17 ) : null;
		$write_result = $relation ? $relation->update_meta( 1, 2, 'agent_test_meta_key', 'agent_test_meta_value' ) : 'not_attempted';
		$read_result  = $relation ? $relation->get_meta( 1, 2, 'agent_test_meta_key' ) : 'not_attempted';
		// Only assert the "silently no-ops, no fatal" half unconditionally; the exact
		// false-vs-value outcome depends on whether the _meta table happens to exist yet.
		$pass = ( ! $meta_table_exists ) ? ( false === $read_result ) : true;
		agent_test_assert(
			$suite, 'rel-5',
			'SKILL.md "Relation meta" gotcha: update_meta()/get_meta() do not throw/fatal; if the _meta table does not exist yet, get_meta() returns false (silent no-op, not an error)',
			$pass,
			array( 'no_fatal' => true, 'get_meta_false_if_no_meta_table' => true ),
			array( 'meta_table_exists' => $meta_table_exists, 'update_meta_return' => $write_result, 'get_meta_return' => $read_result ),
			'includes/components/relations/relation.php:1132 (update_meta(), no auto-create), :1200 (get_meta())'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'rel-5', 'update_meta()/get_meta() no-fatal smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// rel-6: relation config storage is a real jet_rel_{id} DB table, not a live wp_options blob.
	try {
		global $wpdb;
		$table_exists = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}jet_rel_17'" );
		agent_test_assert(
			$suite, 'rel-6',
			'SKILL.md "Storage": relation link data lives in a custom {prefix}jet_rel_{id} table, not wp_options',
			$table_exists,
			array( 'jet_rel_17_table_exists' => true ),
			array( 'jet_rel_17_table_exists' => $table_exists ),
			'includes/components/relations/storage/db.php:18, storage/manager.php:27-58'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'rel-6', 'relation storage table smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// rel-7: relation/update/before and relation/update/after actually fire during a real
	// update() call, with the documented arg shapes. Self-cleaning (removes the link it
	// creates, same as rel-2).
	try {
		$manager  = function_exists( 'jet_engine' ) ? jet_engine()->relations : null;
		$relation = $manager ? $manager->get_active_relations( 17 ) : null;
		$parent_id = 1;
		$child_id  = 2;

		$before_args = null;
		$after_args  = null;
		if ( $relation ) {
			add_action( 'jet-engine/relation/update/before', function( $p, $c, $rel_obj ) use ( &$before_args ) {
				$before_args = array( 'parent' => $p, 'child' => $c, 'is_relation_obj' => is_a( $rel_obj, '\\Jet_Engine\\Relations\\Relation' ) );
			}, 10, 3 );
			add_action( 'jet-engine/relation/update/after', function( $p, $c, $item_id, $rel_obj ) use ( &$after_args ) {
				$after_args = array( 'parent' => $p, 'child' => $c, 'item_id' => $item_id, 'is_relation_obj' => is_a( $rel_obj, '\\Jet_Engine\\Relations\\Relation' ) );
			}, 10, 4 );
			$row = $relation->update( $parent_id, $child_id );
			remove_all_actions( 'jet-engine/relation/update/before' );
			remove_all_actions( 'jet-engine/relation/update/after' );
			$relation->delete_rows( $parent_id, $child_id ); // cleanup
		}

		$pass = is_array( $before_args ) && (int) $before_args['parent'] === $parent_id && (int) $before_args['child'] === $child_id && $before_args['is_relation_obj']
			&& is_array( $after_args ) && (int) $after_args['parent'] === $parent_id && (int) $after_args['child'] === $child_id && $after_args['is_relation_obj']
			&& isset( $row['_ID'] ) && (string) $after_args['item_id'] === (string) $row['_ID'];

		agent_test_assert(
			$suite, 'rel-7',
			'SKILL.md "Reacting to a link write": relation/update/before (3 args: parent, child, relation) and relation/update/after (4 args: parent, child, item_id, relation) both fire on a real update() call, item_id matching the returned row\'s _ID',
			$pass,
			array( 'before_fired_with_right_args' => true, 'after_fired_with_right_args' => true, 'item_id_matches_row' => true ),
			array( 'before_args' => $before_args, 'after_args' => $after_args, 'row_id' => $row['_ID'] ?? null ),
			'includes/components/relations/relation.php:1462 (before), :1537 (after) — driven live against relation 17, link removed after assertion'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'rel-7', 'relation/update/before+after live hook test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// rel-8: Sources system — get_id_by_source() with a key not in its built-in switch
	// falls through to the dynamic jet-engine/relations/object-id-by-source/{key} filter.
	try {
		$sources = function_exists( 'jet_engine' ) ? jet_engine()->relations->sources : null;
		$seen_var = null;
		if ( $sources ) {
			add_filter( 'jet-engine/relations/object-id-by-source/agent_test_source', function( $default, $var ) use ( &$seen_var ) {
				$seen_var = $var;
				return 12345;
			}, 10, 2 );
		}
		$result = $sources ? $sources->get_id_by_source( 'agent_test_source', 'agent_test_var' ) : null;
		if ( $sources ) {
			remove_all_filters( 'jet-engine/relations/object-id-by-source/agent_test_source' );
		}
		$pass = ( 12345 === $result ) && ( 'agent_test_var' === $seen_var );
		agent_test_assert(
			$suite, 'rel-8',
			'SKILL.md "Resolving an object id from context": get_id_by_source() falls through to jet-engine/relations/object-id-by-source/{source} for any key not in its built-in switch, passing $var through as the 2nd filter arg',
			$pass,
			array( 'result' => 12345, 'var_passed_through' => 'agent_test_var' ),
			array( 'result' => $result, 'seen_var' => $seen_var ),
			'includes/components/relations/sources.php:57-121'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'rel-8', 'Sources object-id-by-source fallback test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

	// rel-9: raw-relations filter and the posts-type get-items filter are only ever
	// applied at CCT/relations registration time on `init` (raw-relations) or at
	// admin-UI-picker render time (get-items) — neither can be usefully re-triggered
	// mid-request the way rel-7/rel-8's hooks can. Source-presence check only.
	try {
		$file = WP_PLUGIN_DIR . '/jet-engine/includes/components/relations/manager.php';
		$contents = file_exists( $file ) ? file_get_contents( $file ) : '';
		$has_raw_relations = false !== strpos( $contents, "apply_filters( 'jet-engine/relations/raw-relations'" );

		$file2 = WP_PLUGIN_DIR . '/jet-engine/includes/components/relations/types/posts.php';
		$contents2 = file_exists( $file2 ) ? file_get_contents( $file2 ) : '';
		$has_get_items = false !== strpos( $contents2, "apply_filters( 'jet-engine/relations/types/posts/get-items'" );

		$pass = ( '' !== $contents ) && $has_raw_relations && ( '' !== $contents2 ) && $has_get_items;
		agent_test_assert(
			$suite, 'rel-9',
			'SKILL.md "there IS a way to register a relation" / "Custom post-picker items": raw-relations filter (manager.php) and types/posts/get-items filter (types/posts.php) both present in live plugin source',
			$pass,
			array( 'raw_relations_present' => true, 'posts_get_items_present' => true ),
			array( 'raw_relations_present' => $has_raw_relations, 'posts_get_items_present' => $has_get_items ),
			'includes/components/relations/manager.php:357-358, types/posts.php:112 — source-presence check, both fire outside a normal REST-request lifecycle'
		);
	} catch ( \Throwable $e ) {
		agent_test_assert( $suite, 'rel-9', 'raw-relations / posts get-items presence smoke test', false, 'no exception', $e->getMessage(), 'THREW' );
	}

} );
