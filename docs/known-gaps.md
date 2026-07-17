# Known gaps

A short, curated list of what's still open in this repo. Not blocking, not urgent —
just honest bookkeeping so contributors know where to look first. See each skill's own
`TEST-REGIMEN.md` for the fine-grained "not yet automated" notes behind these.

## Real plugin bugs found, not fixed upstream

These are genuine JetSearch defects (not documentation gaps) discovered while building
`jetsearch-suggestions` — reported here so they don't get lost, and so a user hitting
the symptom finds an explanation instead of re-debugging from scratch. See
[`../skills/jetsearch-suggestions/SKILL.md`](../skills/jetsearch-suggestions/SKILL.md)
for full detail.

- `wp_ajax_suggestions_get_user_id` / `wp_ajax_nopriv_suggestions_get_user_id` are wired
  to a method that doesn't exist on `Jet_Search_Ajax_Handlers` — triggering this AJAX
  action fatals with "call to undefined method."
- `remove_deleted_parent()` (both the REST `delete-suggestion` endpoint and its AJAX
  twin) compares a DB-fetched `parent` value (string) against a deleted id (int) with
  strict `===`, which is always false — deleting a parent suggestion never actually
  clears its children's `parent` field, silently leaving them orphaned.

## Things that need a real browser/editor session, not a PHP test

The REST-request-based test harness (see [`test-harness-guide.md`](test-harness-guide.md))
can't safely exercise these — each is flagged in its owning skill's `TEST-REGIMEN.md`:

- Elementor editor round-trips (JetWooBuilder Document editing, JetThemeCore's Elementor
  document-type registration and Elementor Pro location bridge).
- Front-end template-swap visual checks (JetWooBuilder's shop-page toggles, JetThemeCore's
  Page Template `template_include` swap) — the PHP-level gating is tested, the rendered
  page isn't.
- JS-only behavior (JetPopup's open/close trigger event payloads).
- Real checkout/payment flows (Jet Appointments Booking + WooCommerce order completion;
  JetFormBuilder Payment Gateways' non-PayPal gateways and any flow needing live
  third-party credentials).
- Real submitted-review fixtures for two JetReviews conditions (duplicate-submission
  blocking, a custom non-`Base_Condition` class).
- JetSearch's Bricks Builder integration (element registration, controls-DSL, hooks) —
  source-verified only, Bricks isn't installed on the sandbox this repo verifies
  against. A full `wp_ajax_jet_ajax_search`/REST `search-posts` request through its real
  terminating callback (both end in `wp_send_json_success()`/`wp_die()`, uncatchable by
  this repo's in-process test harness) — the underlying data-building methods are
  covered instead, same pattern used for `jetwoobuilder-templates`' AJAX handlers.

## Smaller open threads

- Whether JetEngine has its own type-aware meta-field read/write helper (repeater-aware,
  beyond core `get_post_meta`/`update_post_meta`) that agents might assume exists —
  unconfirmed either way, worth a targeted grep on `jet_engine()->meta_boxes` sometime.
- Dynamic Functions (Elementor dynamic-tag resolvers) vs. the `%macro%` system in
  `jetengine-listings-macros` — a short distinguishing note is owed but not yet written.
- **JetFormBuilder's own macro-filter system** (`%field|filter(args)%`,
  `jet-form-builder/content-filters`) — a separate implementation from JetEngine's
  `%macro%` engine with confusingly similar syntax; ~12 built-in filters, zero coverage.
- **JetEngine Profile Builder module** (`includes/modules/profile-builder/`) — real,
  undocumented anywhere in this repo.
- A handful of suites (`jetengine-mcp-tools` Test 3/4, `jetsmartfilters-query`'s indexer
  tests, `jetengine-query-builder` Test 2) mostly check reachability rather than a full
  fixture-driven pipeline — real field groups / live AJAX fixtures would deepen these.

None of the above blocks using this repo day to day — they're the next things to pick up,
not defects in what's already documented and live-verified.
