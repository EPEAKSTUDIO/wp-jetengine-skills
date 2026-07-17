# Test regimen: jetreviews-data-model

Validates claims in `SKILL.md`. Run against a sandbox site with JetReviews For Elementor
3.1.0.1 installed and at least one post type with a review type configured.

## Run log — 2026-07-16: UNBLOCKED, live-verified (6/6 pass)

JetReviews For Elementor 3.1.0.1 is now active on jackfruit.epeak.studio. Deployed
`tests.php` as Code Snippets snippet id 44 ("AGENT-TEST-SUITE: jetreviews-data-model")
and ran `GET /agent-test/v1/suite/jetreviews-data-model`: **6/6 pass**, no fixes needed.

**To make this regimen runnable:** install JetReviews For Elementor on a site (ideally
one that already has a post type with a review type configured, plus one submitted
review, one guest-submitted review, and one pending/unapproved review, so the
approved/unapproved condition-adjacent queries in `jetreviews-conditions` have real rows
to find), then deploy `tests.php` as its own Code Snippets snippet (name:
"AGENT-TEST-SUITE: jetreviews-data-model") alongside the always-active AGENT-TEST-CORE
harness (snippet id 22), and run via `GET /agent-test/v1/suite/jetreviews-data-model`.

## Prerequisites (once installable)

- JetReviews For Elementor active, Elementor active (JetReviews's own Elementor
  integration is conditional on `ELEMENTOR_VERSION` being defined,
  `includes/components/elementor/manager.php:24-28`).
- At least one review type row in `{prefix}jet_review_types` beyond the auto-seeded
  `default` row (`includes/db/manager.php:321-343`), ideally for a real post type.
- One approved review and one pending (`approved=0`) review on the same source, to
  exercise `Already_Reviewed`/`Moderator_Check` in the companion `jetreviews-conditions`
  suite.

## Tests automated in `tests.php` (jrd-1 through jrd-6)

- **jrd-1** — `\Jet_Reviews\DB\Manager::tables()` (static, pure read, no side effects)
  returns the 6 expected table keys.
- **jrd-2** — `jet_reviews()->db` is the live instance and `is_table_exists('reviews')`
  is true (confirms the bootstrap actually ran `init_db_required()` and the table was
  created).
- **jrd-3** — `jet_reviews()->reviews_manager->sources->get_registered_source_list()`
  contains both `post` and `user` keys — read via the **live** `Sources` instance only;
  see `SKILL.md`'s landmine note for why `new Sources()` is never called here.
- **jrd-4** — the per-source `current-id` filter (`jet-reviews/source/source-{slug}/current-id`)
  is genuinely honored — driven live by registering a filter and calling
  `get_current_id()` on the live `post`/`user` source instances.
- **jrd-5** — `jet-reviews/user-manager/raw-user-data` fires and is honored — driven live
  via `jet_reviews()->user_manager->get_raw_user_data()` with a marker filter registered.
- **jrd-6** — `jet-reviews/structure-data/types` is present in source and
  `get_valid_structure_data_type()` falls back to `'Product'` for a garbage input —
  called directly (both are pure functions with no constructor side effects).

## Test 7 (not yet automated — needs real fixtures/UI, manual only)

**Claim:** a custom Elementor widget file dropped into
`includes/components/elementor/widgets/` self-registers purely from its derived class
name, with no error if the name is wrong.

**Setup:** add a temp file `includes/components/elementor/widgets/my-test-widget.php`
defining `class \Elementor\My_Test_Widget extends Base_Widget` (copy an existing built-in
widget's shape), reload the Elementor editor's widget panel.

**Expected observable:** the new widget appears in the "JetReviews" Elementor category
panel with no PHP notice/warning in the debug log, confirming
`Jet_Reviews\Elementor\Manager::register_addon()`'s filename→classname derivation
(`elementor/manager.php:142-154`) worked. Then rename the file to something whose
derived class name doesn't match the class actually defined inside it, reload again, and
confirm the widget silently disappears from the panel with still no error logged.

**Pass criteria:** widget appears/disappears exactly as the filename-derivation claim
predicts, with no PHP error either way.

## Test 8 (not yet automated — needs a real REST request + a real Condition, manual only)

**Claim:** a custom "can review" Condition changes the widget's rendered form/message but
does not block a direct `POST /wp-json/jet-reviews-api/v1/submit-review` request from a
user whose role is still in the review type's `allowed_roles`.

**Setup:** register a custom `Base_Condition` (type `can-review`) via
`jet-reviews/user/conditions/register` that always returns `false` from `check()`.
Confirm the widget now shows the condition's invalid message instead of the submission
form for a user in an allowed role.

**Trigger:** as that same user, send a `POST` directly to
`/wp-json/jet-reviews-api/v1/submit-review` with valid params (bypassing the widget UI
entirely — e.g. via `curl`/Postman with a valid nonce/auth).

**Expected observable:** the REST call still succeeds (`success: true`, a new row in
`jet_reviews`), because `Submit_Review::permission_callback()` never consults the
Condition — confirming the "render-time only" gotcha in `SKILL.md`.

**Pass criteria:** REST submission succeeds despite the widget having blocked the same
action visually — if it fails instead, the gotcha claim needs re-examination (the
Condition may be consulted somewhere else this pass didn't find).
