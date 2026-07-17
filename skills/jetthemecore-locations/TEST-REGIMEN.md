# Test regimen: jetthemecore-locations

Validates claims in `SKILL.md`. Run against the sandbox site once JetThemeCore is
installed there (not installed as of this writing). For each test: set up the
snippet/state described, trigger the action, then check the expected observable via
`GET /agent-test/v1/suite/jetthemecore-locations`.

## Run log — 2026-07-16: UNBLOCKED, live-verified (4/4 pass after fixes)

Deployed `tests.php` as Code Snippets snippet id 61 and ran
`GET /agent-test/v1/suite/jetthemecore-locations`. First run: 3/4 — `jtl-1` failed
because this site's WooCommerce activation makes JetThemeCore register 6 additional
WooCommerce-specific structures/locations beyond the 6/4 documented as the complete set —
a real doc correction (see SKILL.md), not a test bug; fixed the assertion to a
subset/missing check instead of exact-set equality. Second run: 4/4, but a later
confirmation sweep caught `jtl-4` intermittently returning `false` instead of the
expected marker — root cause: the render-content filter name is dynamic
(`{$content_type}-location-content`), and this shared sandbox's real site content can
shift which content_type actually matches over time; the test had hardcoded "elementor".
Fixed by computing the same content_type `do_location()` itself would resolve (via
`find_matched_conditions()`) before choosing which filter to hook — confirmed stable
across 3 repeated runs afterward. Also cleaned up 3 orphaned `jet-page-template` posts
(ids 362-364) left over from an earlier, since-fixed `jetthemecore-theme-builder` test
bug (see that skill's TEST-REGIMEN.md) that had skipped its own cleanup step.

## Prerequisites

- JetThemeCore 2.3.1.2 active on the sandbox, alongside the always-active
  `AGENT-TEST-CORE harness` snippet.
- This suite deployed as its own Code Snippets snippet
  (`AGENT-TEST-SUITE: jetthemecore-locations`).

## Test 1: Structures registry populated, Locations registry only contains `is_location()` structures

**Claim:** 6 built-in structures exist (`jet_page`, `jet_header`, `jet_footer`,
`jet_section`, `jet_archive`, `jet_single`); only Header/Footer/Single/Archive are
registered as Locations (`is_location() === true`).

**Trigger:** read `jet_theme_core()->structures->get_structures()` and
`jet_theme_core()->locations->get_locations()`.

**Expected observable:** `get_structures()` has all 6 ids; `get_locations()` has exactly
`header`/`footer`/`single`/`archive` as keys (their `location_name()` values), not
`jet_page`/`jet_section` or their raw structure ids.

**Pass criteria:** both counts and key sets match.

## Test 2: `do_location()` bridges to `find_matched_conditions()` with the right structure id

**Claim:** `do_location( 'header' )` resolves the `Header` structure, then calls
`find_matched_conditions( 'jet_header' )` — the exact structure id, not the location name
(`'header'`).

**Setup:** temporarily replace `jet_theme_core()->template_conditions_manager` calls
aren't easily interceptable without a full mock; instead, hook
`jet-theme-core/location/do-location/active-location` to record the `$location` value
passed through, and separately confirm (via source-grep, since live-swapping the
conditions manager risks side effects) that `get_structure_for_location('header')->get_id()`
returns `'jet_header'` — the value that then feeds `find_matched_conditions()`.

**Trigger:** call `jet_theme_core()->locations->do_location( 'header' )` in a context
where no header template is configured (expect a graceful `false`/no-fatal return, not
requiring a real match).

**Expected observable:** the recorded `$location` marker fired with value `'header'`; no
fatal error; `get_structure_for_location('header')->get_id() === 'jet_header'`.

**Pass criteria:** both checks pass.

## Test 3: `do_location()` short-circuits when Theme Builder Page Template render is active

**Claim:** `do_location()` returns `false` immediately without firing any of the 3
content-render hooks when `jet_theme_core()->theme_builder->frontend_manager->is_theme_builder_render`
is true.

**Setup:** force `is_theme_builder_render` to `true` on the live `Frontend_Manager`
instance (public property, directly settable) for the duration of this test, restore
after.

**Trigger:** hook `jet-theme-core/location/before-render/elementor-location-content` and
`.../default-location-content` to set a flag if either fires, then call
`do_location( 'header' )`.

**Expected observable:** `do_location()` returns `false`; neither before-render hook
fired.

**Pass criteria:** both conditions true.

## Test 4: content-type render filter is honored, and its return value is a bare status bool (not markup)

**Claim:** `apply_filters("jet-theme-core/location/render/{$content_type}-location-content", false, $template_id, $location)`
is applied inside `do_location()`, and a callback's return value flows back as
`do_location()`'s own return value unmodified.

**Trigger:** hook `jet-theme-core/location/render/elementor-location-content` (forcing
`$content_type` to resolve as `'elementor'` is the default when no template matches, per
SKILL.md) to return a distinctive marker value instead of a bool, then call
`do_location('header')` with no header template configured.

**Expected observable:** `do_location()`'s return value is exactly the marker.

**Pass criteria:** returned value === marker.

## Not yet automated — manual steps only

- The Elementor document-type registration (`register_document_types_for_structures()`)
  and the Elementor Pro location bridge (`Elementor\Locations::register_elementor_locations()`)
  both require a real Elementor/Elementor Pro admin-editor context to observe end to end
  (opening a template in the Elementor editor, checking Theme Builder's own native
  locations list) — not practically automatable via a REST-triggered suite. Manually
  confirm by opening a Header structure's template post in the Elementor editor and
  checking the document type shown matches `Jet_Header_Document`.
