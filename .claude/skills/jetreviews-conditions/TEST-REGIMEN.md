# Test regimen: jetreviews-conditions

Validates claims in `SKILL.md`. Run against a sandbox site with JetReviews For Elementor
3.1.0.1 installed.

## Run log — 2026-07-16: UNBLOCKED, live-verified (5/5 pass)

JetReviews For Elementor 3.1.0.1 is now active on jackfruit.epeak.studio. Deployed
`tests.php` as Code Snippets snippet id 45 ("AGENT-TEST-SUITE: jetreviews-conditions")
and ran `GET /agent-test/v1/suite/jetreviews-conditions`: **5/5 pass**, no fixes needed
(the un-interpolated `{$this->slug}` bug documented in `SKILL.md` was confirmed live too).

**To make this regimen runnable:** install JetReviews For Elementor on a site with at
least one review type configured and one approved + one pending review on the same
source (so `Already_Reviewed`/`Moderator_Check` have real rows to find), deploy
`tests.php` as its own Code Snippets snippet (name: "AGENT-TEST-SUITE:
jetreviews-conditions") alongside the always-active AGENT-TEST-CORE harness (snippet id
22), and run via `GET /agent-test/v1/suite/jetreviews-conditions`.

## Prerequisites (once installable)

- Everything `jetreviews-data-model`'s regimen requires, plus:
- One user account with an existing **approved** review on a given source (to trigger
  `Already_Reviewed`), and one with an existing **pending** (`approved=0`) review on a
  different source (to trigger `Moderator_Check`) without colliding with the first.

## Tests automated in `tests.php` (jrc-1 through jrc-5)

- **jrc-1** — `jet_reviews()->user_manager->registered_conditions` contains the 4
  built-in slugs (`user-guest`, `user-role`, `moderator-check`, `already-reviewed`), each
  reporting `get_type() === 'can-review'`.
- **jrc-2** — `jet_reviews()->user_manager->registered_verifications` contains the 1
  built-in slug (`guest-user`).
- **jrc-3** — `is_user_can_review()` returns the documented "everything passes" shape
  when called with an empty `$user_data` (the base-case branch, `manager.php:233-239`) —
  called directly on the live instance, no fixtures needed.
- **jrc-4** — the single-quoted un-interpolated hook-name bug is a genuine, literal
  source string (source-grep across all 4 condition files + the 1 verification file) —
  confirms the bug wasn't fixed in the currently-installed version before relying on the
  workaround in `SKILL.md`.
- **jrc-5** — the broken literal hook name is nonetheless a real, functioning
  `apply_filters()` call — driven live by calling `Already_Reviewed::get_invalid_message()`
  directly (safe: it's a fresh, standalone `Base_Condition` subclass instance with no
  nested `require` in its own constructor — unlike the Manager classes, this one is safe
  to `new` directly) with a filter registered on the literal broken hook name string.

## Test 6 (not yet automated — needs a real review + REST call, manual only)

**Claim:** `Already_Reviewed`/`Moderator_Check` correctly find an existing
approved/pending review row for the *same* user+source and block a second submission,
via `is_user_can_review()` at render time.

**Setup:** as a test user, submit one review for a given source (approve it via the
admin Reviews list). Load the same source's page again as the same user.

**Expected observable:** the review widget shows the `Already_Reviewed` condition's
invalid message ("*Already reviewed") instead of the submission form.

**Trigger:** repeat with a review left in "pending" state (don't approve it) instead —
expect `Moderator_Check`'s message ("*Your review must be approved by the moderator")
instead, confirming the two conditions are mutually exclusive by design (a source can't
simultaneously have an approved and unapproved review from the same user without both
firing — confirm which one wins if it ever can, since `is_user_can_review()` short-circuits
on the *first* failing condition and both are registered in the same fixed order,
`manager.php:67-71`).

**Pass criteria:** the correct condition's message shows in each case, confirming the
DB-query-based `check()` implementations (`already-reviewed.php:57-83`,
`moderator-check.php:57-83`) work against real rows, not just their static shape.

## Test 7 (not yet automated — needs a custom Condition class + real widget render, manual only)

**Claim:** registering a custom class via `jet-reviews/user/conditions/register` that
does not `extend Base_Condition` still gets stored in the registry (no type-checking
enforced by `register_condition()`), but will fatal the first time
`is_user_can_review()` calls a method that class doesn't implement.

**Setup:**
```php
add_action( 'jet-reviews/user/conditions/register', function( $manager ) {
    $manager->register_condition( '\\Not_A_Condition_Class' ); // does not extend Base_Condition
} );
class Not_A_Condition_Class {
    public function get_slug() { return 'broken'; }
    public function get_type() { return 'can-review'; }
    // deliberately missing check()/get_invalid_message()
}
```

**Trigger:** load any page that renders the JetReviews widget.

**Expected observable:** a fatal error (`Call to undefined method
Not_A_Condition_Class::check()`) the first time `is_user_can_review()` reaches this
condition — confirming `register_condition()` (`manager.php:93-97`) does no
`instanceof Base_Condition` check, so the "must extend Base_Condition" contract stated in
the plugin's own doc-comment (`manager.php:82-83`) is convention only, not enforced.

**Pass criteria:** fatal occurs exactly as predicted; if PHP instead silently no-ops or
throws a catchable `\Error`, revise this claim's severity.
