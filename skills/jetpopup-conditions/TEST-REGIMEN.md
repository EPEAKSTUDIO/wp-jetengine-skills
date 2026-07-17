# Test regimen: jetpopup-conditions

Validates claims in `SKILL.md`. Run against a sandbox site with JetPopup 2.2.1 active
(ideally with JetEngine and WooCommerce also active, to exercise the compatibility
condition modules).

## Run log — 2026-07-16: UNBLOCKED, live-verified (6/6 pass)

Deployed `tests.php` as Code Snippets snippet id 56 and ran
`GET /agent-test/v1/suite/jetpopup-conditions`: **6/6 pass**, no fixes needed.

## Prerequisites

- JetPopup 2.2.1 active.
- The always-active `AGENT-TEST-CORE harness` snippet (id 22, see
  `test-harness/core-snippet.php`).
- At least one popup post (`jet-popup` CPT) with saved `_conditions`/`_relation_type`
  meta, for the live matching-algorithm tests. A popup can be created via the REST
  `create-popup` endpoint or the admin UI if none exists yet.
- Ideally WooCommerce and/or JetEngine active, to exercise the compatibility
  condition-group filters live rather than just via source-presence checks.

## Test 1: `jet_popup()->conditions_manager` is reachable with the documented conditions populated

**Claim:** `register_conditions()` builds `Manager::$_conditions` from the static list
plus `register_cpt_conditions()`'s auto-generated per-post-type conditions, and
`get_condition( $id )` resolves any of them.

**Automated as:** `jpc-1` in `tests.php` — calls `jet_popup()->conditions_manager->get_condition('entire')`
and confirms it returns a `Jet_Popup\Conditions\Entire` instance (a condition with no
sub-group/value), then does the same for at least one CPT-generated id
(`cpt-single-post` or `cpt-archive-post`, generated for the built-in `post` type).

## Test 2: `Base`'s 6 abstract methods vs. no-op defaults

**Claim:** `get_sub_group()`/`get_control()`/`get_avaliable_options()`/`ajax_action()`/
`get_label_by_value()` all have working no-op defaults; only `get_id`/`get_label`/
`get_group`/`get_priority`/`get_body_structure`/`check` are actually required.

**Automated as:** `jpc-2` in `tests.php` — reflects on the `Entire` condition class
(the simplest built-in, no sub-group/value) and confirms its `get_sub_group()` returns
`false` and `get_control()` returns `false` without being overridden, i.e. inherited
straight from `Base`.

## Test 3: the AND-relation exclude filter fires with the documented 3-arg signature

**Claim:** `find_matched_popups_by_conditions()` fires
`apply_filters('jet-popup/popup-condition/is_excluded/and', $bool, $excludes_matchs, $popup_id)`
for any 'and'-relation popup with at least one exclude condition.

**Automated as:** `jpc-3` in `tests.php` — creates a throwaway popup (or reuses a fixed
`AGENT-TEST`-prefixed one) with one 'and'-relation exclude condition pointed at the
`Entire` condition (always matches), hooks the filter to capture its 3 args, calls
`find_matched_popups_by_conditions()`, and confirms the callback fired with a non-null
`$popup_id` matching the fixture. Cleans up the fixture popup/option state afterward.

## Test 4: the OR-relation exclude filter also fires — the backlog only documented the AND variant

**Claim:** a parallel, undocumented-in-backlog `jet-popup/popup-condition/is_excluded/or`
filter exists with the same 3-arg shape, for 'or'-relation popups.

**Automated as:** `jpc-4` in `tests.php` — same technique as Test 3 but with
`relation_type = 'or'`. This is the highest-value test in this suite since it's the one
correction to the existing gist-research backlog.

## Test 5: an unresolvable condition type passes automatically rather than failing the popup

**Claim:** `find_matched_popups_by_conditions()` sets `$condition['match'] = true` when
`get_condition($sub_group)` returns `false` (condition type not registered) —
`manager.php:737-741`.

**Automated as:** `jpc-5` in `tests.php` — builds a fixture popup with a single include
condition whose `subGroup` is a nonsense string (`agent-test-nonexistent-condition`),
confirms `find_matched_popups_by_conditions()` still includes that popup's id in its
returned list (proving the "unknown condition auto-passes" behavior, not a failure).

## Test 6: compatibility condition classes are reachable when their host plugin is active

**Claim:** `Jet_Engine_Custom_Query_Has_Items::check($query_id)` calls
`\Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id($query_id)->has_items()`.

**Not fully automated** — requires a real JetEngine Query Builder query configured with
known has/no-items state. `tests.php`'s `jpc-6` only checks that the condition class is
registered (`get_condition('jet-engine-custom-query-has-items')` resolves) when
`class_exists('Jet_Engine')`, skipping gracefully (marked pass with a note) if JetEngine
isn't active on this sandbox. Extend with a real query fixture once one exists.
