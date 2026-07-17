---
name: jetreviews-conditions
description: Use when writing a custom "who can submit a review" rule (a Base_Condition subclass) or a reviewer "badge" rule (a Base_Verification subclass) for JetReviews For Elementor, or when debugging why a condition's invalid message/icon can't be overridden per-slug. Captures verified behavior of Jet_Reviews\User\Manager's Conditions/Verifications registries from JetReviews 3.1.0.1 source, live-verified 2026-07-16 against jackfruit.epeak.studio (5/5 tests.php assertions passing).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetReviews Conditions & Verifications

Verified facts about JetReviews's two small, parallel extensibility registries for
gating/badging review authors: **Conditions** ("can this person submit a review") and
**Verifications** ("what badge should show next to this person's review"). Confirmed
against JetReviews For Elementor 3.1.0.1 source (`plugins/jet-reviews/`).

## Both registries live on `Jet_Reviews\User\Manager` — reach it via `jet_reviews()->user_manager`

`User\Manager`'s constructor registers both sets in one pass
(`includes/components/user/manager.php:52-55`): `register_conditions()` then
`register_verifications()`. **Do not construct this class yourself** (`new
\Jet_Reviews\User\Manager()` or its own `::get_instance()`, `manager.php:40-47`) — both
registration methods do an unconditional `require` of already-declared class files
(`:65,74-78,121,127-131`), so a second construction is a compile-time "Cannot redeclare
class" fatal — see `jetreviews-data-model`'s "Never instantiate a component Manager class
yourself" section for the full explanation and the other three classes with the same
landmine. The only safe accessor is the plugin bootstrap's live instance:
`jet_reviews()->user_manager`.

## Conditions: gate "can this user submit a review"

`Jet_Reviews\User\Conditions\Base_Condition` (abstract, `user/conditions/base.php:9-36`)
declares four methods a subclass must implement: `get_type()`, `get_slug()`,
`get_invalid_message()`, `check()` (the base declares it with no params, but every real
implementation takes `( $user_data, $review_type_settings )` — PHP doesn't enforce
parameter-signature matching against an abstract method, so this is fine).

Four built-ins register on construction (`manager.php:67-78`):

| class | slug | gates |
|---|---|---|
| `User_Guest` | `user-guest` | blocks guests unless `'guest'` is in the review type's `allowed_roles` |
| `User_Role` | `user-role` | blocks any role not present in `allowed_roles` |
| `Moderator_Check` | `moderator-check` | blocks a user who already has an *unapproved* (`approved=0`) review pending for this same source |
| `Already_Reviewed` | `already-reviewed` | blocks a user who already has an *approved* (`approved=1`) review for this same source |

Register a custom one:

```php
add_action( 'jet-reviews/user/conditions/register', function( $manager ) {
    $manager->register_condition( '\\My_Plugin\\Review_Condition' );
} );
```

(`manager.php:84`, fired at the end of `register_conditions()`) — `$manager` is the
`User\Manager` instance itself; `register_condition( $class )` (`:93-97`) does `new
$class` and stores it keyed by `$instance->get_slug()`, overwriting any prior condition
registered under the same slug. The class must `extend
\Jet_Reviews\User\Conditions\Base_Condition` (confirmed — matches the constraint stated
in this same doc-comment, `manager.php:82-83`, and a real Crocoblock gist implementing
a custom "can review" condition this exact way).

### `is_user_can_review()` — the actual gate, and only `'can-review'`-typed conditions run

`User\Manager::is_user_can_review( $user_data, $review_type_settings )`
(`manager.php:231-264`) loops every registered condition, **skipping any whose
`get_type()` isn't the literal string `'can-review'`** (`:244-246`) — a custom condition
with a different `get_type()` return value is registered but silently never consulted
here. For each `'can-review'` condition it calls `check( $user_data,
$review_type_settings )`; the **first** one that returns falsy short-circuits the whole
check with `{ allowed: false, code: <slug>, message: <get_invalid_message()> }`
(`:250-256`); if every condition passes (or none are registered), the result is `{
allowed: true, code: 'can_review', message: '*Publish your review' }` (`:259-263`, also
the immediate return if `$user_data` itself is empty, `:233-239`).

**This is a render-time gate only.** The single call site is
`reviews/render/review-listing-render.php:168`, inside the widget's own render method,
used to decide whether to show the submission form or a "why not" message. **It is never
called from the REST `submit-review` endpoint** — see `jetreviews-data-model`'s REST
gotcha section: `Submit_Review::permission_callback()` only checks role membership
against `allowed_roles` directly, so a custom Condition changes the UI, not what a
crafted REST request can actually do.

### Confirmed real bug: the per-slug invalid-message/icon/message hooks never fire with a per-slug name

Every built-in condition's `get_invalid_message()`, and the built-in verification's
`get_icon()`/`get_message()`, call `apply_filters()` with the hook name written as a
**single-quoted PHP string containing a literal, un-interpolated `{$this->slug}`**:

```php
// user/conditions/already-reviewed.php:50 (also moderator-check.php:50, user-guest.php:50, user-role.php:50)
return apply_filters( 'jet-reviews/user/conditions/invalid-message/{$this->slug}', $this->invalid_message, $this );

// user/verifications/guest-user.php:65,73
return apply_filters( 'jet-reviews/user/verification/successful-icon/{$this->slug}', $this->icon, $this );
return apply_filters( 'jet-reviews/user/verification/successful-message/{$this->slug}', $this->message, $this );
```

PHP does **not** expand variables inside single-quoted strings, so `{$this->slug}` is not
resolved to e.g. `already-reviewed` — the actual, literal hook name fired for **every one
of the four conditions** (and both Guest_User verification hooks) is the exact string
`jet-reviews/user/conditions/invalid-message/{$this->slug}` — the same one hook name for
all four conditions, not four distinct per-slug hooks as the naming clearly intends.
Consequences:

- `add_filter( 'jet-reviews/user/conditions/invalid-message/already-reviewed', ... )` —
  the "obvious" per-slug name a developer would reach for — **will never fire, for any
  condition**.
- The only way to actually intercept/override a built-in condition's invalid message is
  to hook the broken literal string verbatim:
  ```php
  add_filter( 'jet-reviews/user/conditions/invalid-message/{$this->slug}', function( $message, $condition ) {
      if ( 'already-reviewed' === $condition->get_slug() ) {
          return __( 'You already left a review for this.', 'my-textdomain' );
      }
      return $message;
  }, 10, 2 );
  ```
  and disambiguate *which* condition fired using the 2nd arg's `get_slug()`, since the
  hook name itself can't distinguish them.
- This affects only the four **built-in** conditions/the one built-in verification — a
  **custom** condition class controls its own `get_invalid_message()` implementation, so
  it isn't forced to repeat this bug (though copy-pasting a built-in as a starting point,
  as the "can review" condition gist in `OTHER-PLUGINS.md` effectively does, would carry
  it forward unless corrected).

## Verifications: badges shown alongside a review, not a gate

`Jet_Reviews\User\Verifications\Base_Verification` (abstract,
`user/verifications/base.php:9-44`): `get_slug()`, `get_name()`, `get_icon()`,
`get_message()`, `check( $args )`. One built-in, `Guest_User` (slug `guest-user`,
`user/verifications/guest-user.php`), whose `check( $args )` returns true when
`$args['user_id']`'s resolved `raw_user_data()` roles include `'guest'` (`:80-93`).
Register a custom one the same shape as Conditions:

```php
add_action( 'jet-reviews/user/verifications/register', function( $manager ) {
    $manager->register_verification( '\\My_Plugin\\Review_Verification' );
} );
```

(`manager.php:137`, must `extend Base_Verification` per the doc-comment at `:133-135`).

Verifications have **no** `get_type()`/gating concept at all — every registered
verification participates in `get_verification_data( $verifications, $args )`
(`manager.php:170-199`), which is driven by a review type's own `verifications` setting
(an array of verification **slugs** the admin picked for that type,
`reviews/types.php:113`, default `[]`) — it looks up each listed slug, calls its
`check( $args )`, and if truthy, includes `{slug, icon, message}` in the returned list for
the UI to render as a badge. A verification not listed in the review type's own
`verifications` array is registered but never evaluated for that type, even if
`check()` would return true.

## A worked example: how the built-in conditions reach "the current source"

`Already_Reviewed`/`Moderator_Check`'s `check()` both need "the DB row(s) for the item
being reviewed," which requires re-resolving the current Source (see
`jetreviews-data-model`) rather than trusting `$review_type_settings` alone:

```php
$source          = $review_type_settings['source'];
$source_instance = jet_reviews()->reviews_manager->sources->get_source_instance( $source );
$source_id       = $source_instance->get_current_id();
```

(`user/conditions/already-reviewed.php:63-65`, identical in `moderator-check.php:63-65`)
— then a direct `$wpdb`-style query against `jet_reviews()->db->tables( 'reviews', 'name' )`
filtered by `source`/`post_id`/`author`/`approved`. This is the real pattern to copy for a
custom condition that needs to look at existing review rows for the same source.

## Gotchas

- `get_type()` must return the exact string `'can-review'` (not `'can_review'`,
  `'review'`, etc.) for a custom condition to ever be consulted by
  `is_user_can_review()` — confirmed by the strict `!==` comparison at `manager.php:244`.
- Registering two conditions/verifications with the same `get_slug()` silently
  overwrites the earlier one in the registry array (`manager.php:96`, `:148`) — no
  warning, no error.
- `check()` on a Condition receives `$user_data` as the array shape produced by
  `get_raw_user_data()` (`{id, name, mail, avatar, roles}` — see `jetreviews-data-model`),
  not a `WP_User` object — indexing `$user_data->ID` instead of `$user_data['id']` is a
  common mistake given how similar the two look.

## How this was verified

Read `includes/components/user/manager.php` (both `register_conditions()`/
`register_verifications()` and `is_user_can_review()`/`get_verification_data()`),
`includes/components/user/conditions/{base,user-guest,user-role,moderator-check,already-reviewed}.php`,
`includes/components/user/verifications/{base,guest-user}.php`, and the one call site of
`is_user_can_review()` in `includes/components/reviews/render/review-listing-render.php`
in JetReviews For Elementor 3.1.0.1 source — confirming the registration hooks, the
`'can-review'` type gate, the render-time-only call site, and the single-quoted
un-interpolated hook-name bug by direct file:line citation (the bug is present
identically across all 4 condition files plus the 1 verification file, not a one-off
typo). Cross-checked against `other-plugins-backlog/OTHER-PLUGINS.md`'s
gist reference for `jet-reviews/user/conditions/register` — confirmed it fires with the
`User\Manager` instance and requires extending `Base_Condition`, exactly as the gist's
example does. Not yet verified against a running site — JetReviews is not installed on
the sandbox (jackfruit.epeak.studio) as of this writing; see `TEST-REGIMEN.md`.
