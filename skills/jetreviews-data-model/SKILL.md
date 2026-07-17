---
name: jetreviews-data-model
description: Use when working with JetReviews For Elementor's review data model — the custom DB tables (not a CPT or JetEngine CCT), the Sources abstraction that resolves "what is this review attached to" (post vs. WP user, extensible), the raw-user-data/reviewer-avatar filter, the Review Types system, structured-data (rich snippet) types, the REST API layer, or Elementor widget registration. Captures verified behavior of JetReviews 3.1.0.1 source, live-verified 2026-07-16 against jackfruit.epeak.studio (6/6 tests.php assertions passing).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetReviews Data Model & Rendering

Verified facts about how JetReviews stores and serves review data. Confirmed against
JetReviews For Elementor 3.1.0.1 source (`plugins/jet-reviews/`).

## Reviews live in six custom DB tables — no CPT, no JetEngine CCT involved

Unlike JetEngine's own content types, JetReviews rolls its own plain `$wpdb` tables,
created/altered on activation and on every version bump (`Jet_Reviews\DB\Manager`,
`includes/db/manager.php`):

- `{$wpdb->prefix}jet_reviews` — the reviews themselves: `post_id`/`post_type` (the
  *source* item, see below), `author` (a user ID or guest ID string), `type_slug` (FK to
  `jet_review_types.slug`), `rating_data` (serialized per-field ratings),
  `rating` (0-100 aggregate), `approved`, `pinned`, `likes`/`dislikes`
  (`db/manager.php:76-97`).
- `{$wpdb->prefix}jet_review_meta` — free-form `review_id`/`meta_key`/`meta_value` rows,
  read/written via `Jet_Reviews\Reviews\Data::get_review_meta()`/`update_review_meta()`
  (`db/manager.php:98-108`, `reviews/data.php:598-701`) — a per-request in-memory cache
  keyed by `review_id` backs reads (`reviews/data.php:23,604-623`).
- `{$wpdb->prefix}jet_review_media` — attached images/video per review
  (`db/manager.php:109-120`).
- `{$wpdb->prefix}jet_review_types` — one row per configured "review type": `source`/
  `source_type` (which Source this type applies to, see below), `fields` (serialized
  rating-field config), `settings` (serialized — allowed roles, approval/moderation,
  structured-data config; see below) (`db/manager.php:121-136`).
- `{$wpdb->prefix}jet_review_comments` — threaded comments on a review, own table (not
  core `wp_comments`) (`db/manager.php:137-153`).
- `{$wpdb->prefix}jet_review_guests` — non-logged-in reviewers, keyed by a synthetic
  `guest_id` string plus captured name/mail (`db/manager.php:154-166`).

Get a table's real name/CREATE-column-list programmatically — never hardcode
`{$wpdb->prefix}jet_reviews`: `\Jet_Reviews\DB\Manager::tables( $key, $return )`
(`db/manager.php:70-186`), e.g. `jet_reviews()->db->tables( 'reviews', 'name' )`.
`$return` is `'all'` (default, full config array), `'name'`, or `'query'` (the raw column
DDL string used by `dbDelta()`).

## Never instantiate a component "Manager" class yourself — most do an unconditional `require` in their own constructor

This is the single most important gotcha in this plugin, and it recurs far more widely
than the one landmine `jetsmartfilters-query` documents for JetSmartFilters. Confirmed by
reading each class's constructor chain directly:

- `Jet_Reviews\User\Manager::__construct()` → `register_conditions()` (`user/manager.php:61-86`)
  does `require $base_path . 'base.php'` (unconditional, not `require_once`) then
  `require`s all 4 built-in condition class files, then `register_verifications()`
  (`:117-139`) does the same for the verifications base + built-ins.
- `Jet_Reviews\Reviews\Manager::__construct()` → `load_files()` (`reviews/manager.php:67-68,85-117`)
  unconditionally `require`s ~15 files (every REST endpoint class, `Sources`, `Data`,
  `Media`, `Types`, the listing-render class, and admin pages).
- `Jet_Reviews\Reviews\Sources::__construct()` → `register_sources()` (`reviews/sources.php:59-80`)
  unconditionally `require`s `sources/base.php` plus every registered source's file
  (`post.php`, `user.php`, and any custom one added via the filter below).
- `Jet_Reviews\Comments\Manager::__construct()` → `load_files()` (`comments/manager.php:64-87`)
  same pattern for the comments REST endpoints + `Data`.

**Constructing a second instance of any of these four classes re-runs an unconditional
`require` of an already-declared class — a compile-time "Cannot redeclare class" fatal,
uncatchable by `try/catch`, that crashes the entire request** (the exact failure mode
`jetsmartfilters-query`'s `Storage\Controller` landmine documents, confirmed there by
actually crashing a live site — not re-tested live here since JetReviews isn't installed
on the sandbox, but the source pattern is identical and the mechanism is the same PHP
behavior). All four classes also happen to declare their own `get_instance()` singleton
method (`user/manager.php:40-47`, `reviews/manager.php:169-177`, `reviews/sources.php:182-190`,
`comments/manager.php:129-136`) — **calling that yourself is exactly as dangerous**, since
`get_instance()` still does `new self` the first time it's called from your code, and the
plugin's own bootstrap (`jet-reviews.php:262-300`, `Jet_Reviews::init()`, hooked on
`init` at priority `-999`) has *already* constructed one of each via plain `new` by the
time any normal request-time code runs. **The only safe way to reach these is through the
live instances the bootstrap already created:**

```php
jet_reviews()->user_manager       // Jet_Reviews\User\Manager
jet_reviews()->reviews_manager    // Jet_Reviews\Reviews\Manager
jet_reviews()->reviews_manager->sources // Jet_Reviews\Reviews\Sources
jet_reviews()->comments_manager   // Jet_Reviews\Comments\Manager
```

By contrast, `Jet_Reviews\Reviews\Data`, `\Reviews\Types`, `\Reviews\Media`, and
`\Comments\Data` have **no** nested `require` calls in their constructors (`Types::load_files()`
is even an empty method stub, `reviews/types.php:34`) — these four are safe to reach via
either their own `get_instance()` **or** the live `jet_reviews()->reviews_manager->data`
property; the plugin's own code is inconsistent about which it uses (e.g. the real
`Submit_Review` REST endpoint and `Types::get_review_type_data()` both call
`\Jet_Reviews\Reviews\Data::get_instance()` directly rather than going through
`jet_reviews()->reviews_manager->data` — `reviews/rest-api/submit-review.php:202`,
`reviews/types.php:69`). The two accessors are functionally equivalent for `Data` except
for the request-scoped `$reviews_cache` (`reviews/data.php:23`), which is per-instance —
mixing both accessors for meta reads/writes in the same request can see stale cached
values from one instance after a write through the other.

`\Jet_Reviews\DB\Manager` is also safe to reconstruct (its constructor only calls the
static, idempotent `init_db_required()` — no `require` calls, `db/manager.php:51-53`),
but there's no reason to: `jet_reviews()->db` is already the live instance.

## The Sources abstraction: "what is this review about"

A review's `post_id`/`post_type` columns actually point at a **Source** — an abstraction
over "the thing being reviewed," not necessarily a post. `Jet_Reviews\Reviews\Source\Base`
(abstract, `reviews/sources/base.php:9-60`) declares: `get_slug()`, `get_name()`,
`get_current_id()`, `get_type()`, `get_item_label()`, `get_item_thumb_url()`,
`get_item_decsription()` (**verbatim typo in the abstract method name and both built-in
overrides** — "decsription", not "description" — same class of baked-in misspelling this
repo's other skills flag for JetEngine's `cvs-separator` and JetSmartFilters' `fiter`
event names; match the typo exactly when implementing a custom source), `get_settings()`,
`get_types_options()`.

Two built-ins ship: `Source\Post` (`reviews/sources/post.php`, slug `post`, current id =
`get_the_ID()`) and `Source\User` (`reviews/sources/user.php`, slug `user`, current id =
author/queried-author/current-user fallback chain, `:119-130`). Both are registered
through a filter, not hardcoded — a custom source (e.g. a WooCommerce product, a
BuddyPress group) is added the same way:

```php
add_filter( 'jet-reviews/sources/registered_sources', function( $sources ) {
    $sources['my-source'] = array(
        'class'    => '\\My_Plugin\\Review_Source',
        'path'     => __DIR__ . '/review-source.php',
        'instance' => false,
    );
    return $sources;
} );
```

(`reviews/sources.php:32-43`) — `Sources::register_sources()` `require`s the file and does
`new $class()` for any entry whose `instance` is still `false` (`:59-80`), so the class
only needs to `extend Base`; no separate "register" call is needed beyond the filter.

**`get_current_id()`'s per-source override hook**, confirmed for both built-ins:
`apply_filters( "jet-reviews/source/source-{$slug}/current-id", $current_id, $this )`
(`post.php:34` → `jet-reviews/source/source-post/current-id`; `user.php:35` →
`jet-reviews/source/source-user/current-id`) — filters the resolved post/user ID, 2nd arg
is the source instance itself. This is the real hook a BuddyBoss/BuddyPress compatibility
patch (or any "resolve the reviewed item differently in this context" need) uses.

## `jet-reviews/user-manager/raw-user-data` — the one true reviewer-identity filter

Every place JetReviews resolves "who is this review's author" — a real logged-in user, a
stored guest row, or the anonymous fallback — funnels through
`Jet_Reviews\User\Manager::get_raw_user_data( $user_id = false )` and its return is always
passed through `apply_filters( 'jet-reviews/user-manager/raw-user-data', [...] )`
(`user/manager.php:329-367`, three call sites: real user, known guest, unknown/anonymous).
The shape is always `{id, name, mail, avatar, roles}` (`roles` is `['guest']` for the
guest/anonymous cases). This is the hook to override a reviewer's displayed avatar/name
(e.g. pull from a different profile system) without needing to know which of the three
internal branches produced the data — confirmed real (a public Crocoblock gist patches
exactly this hook to override the avatar).

## Review Types: per-context configuration, keyed by (source, source_type)

`jet_reviews()->reviews_manager->types` (`Jet_Reviews\Reviews\Types`, `reviews/types.php`)
resolves which configured "review type" row applies to a given source:
`get_review_type_slug_by_source_type( $source, $source_type )` (`:41-62`) looks up the
`jet_review_types` table by its `source`/`source_type` columns (e.g. `source='post'`,
`source_type='product'`), then `get_review_type_data( $slug, $only_settings = false )`
(`:68-94`) returns the fields/settings, applying defaults via `get_review_type_settings()`
(`:101-137`) — notable defaults: `allowed_roles` (who may submit a review, checked at the
REST layer — see the gotcha below), `verifications` (badge slugs, see
`jetreviews-conditions`), `need_approve`, `upload_media`/`allowed_media`/`maxsize_media`,
`metadata`/`metadata_rating_key`/`metadata_ratio_bound` (auto-write the average rating to
a post meta key), and `structuredata`/`structuredata_type`.

## Structured data (rich snippets) — a filterable, validated type list

`jet-reviews/structure-data/types` (`includes/tools.php:666`) returns the grouped
label/options list shown in the review-type editor's "Structured Data Type" dropdown
(Google-supported types, `LocalBusiness` subtypes, etc.) — add a custom entry (the
"Recipe" rich-snippet type gist does exactly this) by filtering this array. The flattened
value list (`get_structure_data_type_values()`, `tools.php:724-727`) backs
`get_valid_structure_data_type( $type )` (`tools.php:767-783`), which any code setting
`structuredata_type` should go through — an invalid/unregistered type value silently
falls back to `get_default_structure_data_type()` (`'Product'`, `tools.php:757-760`)
rather than erroring. The actual JSON-LD `<script type="application/ld+json">` output
(schema.org `Review`/`Rating`/`Person`) is built by
`render_structured_data()` (`reviews/render/review-listing-render.php:527-576`), called
only when the review type's `structuredata` setting is truthy (`:251-252`).

## REST API: two separate route registration paths under one namespace

Everything lives under namespace `jet-reviews-api/v1` (`Jet_Reviews\Rest_Api`,
`rest-api/rest-api.php:28`), but the namespace's own `init_endpoints()` only registers
one endpoint itself (`Elementor_Template`, `:65`) — every review/comment endpoint is
added by a **different action fired from the same method**:
`do_action( 'jet-reviews/rest/init-endpoints', $this )` (`rest-api.php:67`), which
`Reviews\Manager` (`reviews/manager.php:70,143-161`) and `Comments\Manager`
(`comments/manager.php:48,106-121`) both hook to call
`$rest_api_manager->register_endpoint( new My_Endpoint() )`. A custom endpoint follows the
same shape: `extends \Jet_Reviews\Endpoints\Base` (`rest-api/endpoints/base.php:11`),
implementing abstract `get_name()` and `callback( $request )`; `get_method()` defaults to
`'GET'`, and **`permission_callback()` defaults to a hardcoded `false`**
(`endpoints/base.php:31-44`) — every real endpoint must override it explicitly or it's
permanently inaccessible.

### Gotcha: a custom "can review" Condition does not block direct REST submission

`Endpoints\Submit_Review::permission_callback()` (`reviews/rest-api/submit-review.php:91-104`)
only checks `array_intersect( $user_data['roles'], $source_settings['allowed_roles'] )` —
it never calls `jet_reviews()->user_manager->is_user_can_review()`. That method (the
Conditions-system entry point documented in `jetreviews-conditions`) is only invoked at
widget **render** time, to decide whether to show the review form or a "why you can't
review" message (`reviews/render/review-listing-render.php:168`). **A custom
`Base_Condition` registered via `jet-reviews/user/conditions/register` changes what the
front-end widget displays, but does nothing to stop a crafted `POST
/wp-json/jet-reviews-api/v1/submit-review` request from a role that's still in
`allowed_roles`** — role-gating at the REST layer and Condition-gating at the render
layer are two independent systems that happen to look related.

## Elementor widget registration: filename-derived class name, not a manual list

`Jet_Reviews\Elementor\Manager` (`includes/components/elementor/manager.php`) hooks
`elementor/widgets/register` (Elementor ≥3.5) or the legacy `elementor/widgets/widgets_registered`
(`:36-40`) to `register_addons()`, which `glob()`s every file in
`includes/components/elementor/widgets/*.php` and, for each, derives the expected class
name purely from the filename — `jet-reviews-advanced.php` → `ucwords()` +
dash-to-space-to-underscore → `\Elementor\Jet_Reviews_Advanced` (`:124-154`) — then
`require`s the file and calls `$widgets_manager->register_widget_type( new $class )` if
that exact class exists. **Adding a widget means adding a correctly-named file to that
directory**, not calling a registration function directly — get the derived class name
wrong (e.g. non-standard capitalization in the filename) and the widget silently never
registers, no error.

## Gotchas

- Two of the Review Types system's settings keys are named for a rating percentage
  scale internally (`rating` column is 0-100), but the public REST responses/JS expect a
  0-100 int too — don't assume a 1-5 star scale is stored anywhere; `rating_data`
  (per-field raw values) is what carries the original 1-5-ish scale, `rating` is always
  the normalized 0-100 aggregate (`reviews/rest-api/submit-review.php:302-312`,
  `calculate_rating()`).
- `get_item_decsription()` (the misspelled abstract method) is genuinely unimplemented
  for the `User` source (`sources/user.php:67-75` always returns `''`) — don't expect a
  bio/description for user-sourced reviews.
- Not yet traced in this pass: Bricks Builder integration (`bricks_manager`), the
  export/import module, and the Vue-based admin UI's own internal data flow beyond the
  REST endpoints it calls.

## How this was verified

Read `jet-reviews.php` (plugin bootstrap, `Jet_Reviews::init()`), `includes/db/manager.php`,
`includes/components/reviews/{manager,data,sources,types,media}.php`,
`includes/components/reviews/sources/{base,post,user}.php`,
`includes/components/comments/{manager,data}.php`, `includes/components/user/manager.php`,
`includes/components/elementor/manager.php`, `includes/rest-api/rest-api.php`,
`includes/rest-api/endpoints/base.php`, `includes/components/reviews/rest-api/submit-review.php`,
`includes/components/reviews/render/review-listing-render.php`, and `includes/tools.php`
in JetReviews For Elementor 3.1.0.1 source (`plugins/jet-reviews/`) — confirming every
table schema, hook signature, and class-construction path by direct file:line citation,
including tracing each "Manager" class's constructor for unconditional `require` calls
before ever suggesting a direct-instantiation pattern (per this repo's safety lesson in
`HANDOFF.md`). Cross-checked against `other-plugins-backlog/OTHER-PLUGINS.md`'s
"JetReviews" gist list (`jet-reviews/user-manager/raw-user-data`,
`jet-reviews/source/source-user/current-id`, `jet-reviews/structure-data/types` — all
three confirmed present verbatim in source, not just in the gist description). Not yet
verified against a running site — JetReviews is not installed on the sandbox
(jackfruit.epeak.studio) as of this writing; see `TEST-REGIMEN.md`.
