---
name: jetengine-booking-forms
description: Use when working with JetEngine's own built-in "Dynamic Calendar" module (module id `calendar`) or its legacy "Forms (Legacy)" module (module id `booking-forms`, class `Jet_Engine_Module_Booking_Forms`) — these are DISTINCT from the separate JetFormBuilder plugin (see jetformbuilder-fields/jetformbuilder-actions/jetformbuilder-hooks), which is the maintained replacement for the legacy Forms module. Covers module activation gating (`jet_engine()->modules->is_module_active()`), the Calendar widget/block's CPT+date-meta data pipeline including the "Advanced Date Field" meta field type, and the legacy Forms module's `jet-engine-booking` CPT, builder/editor, and submission-handler/notification pipeline. Captures verified behavior of JetEngine 3.8.12 source (`includes/modules/calendar/`, `includes/modules/forms/`), live-verified 2026-07-17 against jackfruit.epeak.studio (12/12 tests.php assertions passing).
license: MIT
metadata:
  author: project
  version: "0.1.0"
---

# JetEngine Calendar & legacy Forms (Booking Forms) modules

Two unrelated, independently-gated JetEngine modules that share this skill because they
were both flagged as coverage gaps together (`docs/audit-2026-07-16.md`) and are both
easily confused with something else by name:

- **Calendar** (module id `calendar`, class `Jet_Engine_Module_Calendar`) — a Listing
  Grid *render type* (`listing-calendar`) that groups an existing CPT/CCT/Query
  Builder query's items onto a month grid by a date. Not a booking/scheduling engine —
  it has no availability or slot-booking concept at all (that's JetBooking/Jet
  Appointments Booking, separate plugins — see `jetbooking-calendar`/
  `jetappointments-core`).
- **Forms (Legacy)** (module id `booking-forms`, class
  `Jet_Engine_Module_Booking_Forms`) — JetEngine's **own**, pre-JetFormBuilder form
  builder. **This is not JetFormBuilder.** The module's own admin-dashboard copy says
  so explicitly: *"This is a legacy form builder functionality. It would be updated
  only for critical bug fixes. Proceed to the JetFormBuilder plugin to get the latest
  updates..."* (`includes/modules/forms/forms.php:50`). Its internal class/hook/meta
  names all say `booking` (`Jet_Engine_Booking_Forms_*`, `jet-engine/forms/booking/...`)
  for historical reasons — the module used to be literally called "Booking Forms" — but
  it has nothing to do with the Calendar module above or with JetBooking/Jet
  Appointments Booking. Don't let the shared "booking" vocabulary suggest a
  relationship between this module and either booking plugin.

## Module registration/activation — the pattern both modules share

Every JetEngine module (built-in or external) is preloaded as an instance keyed by
`module_id()`, then only `module_init()`-ed if its slug is present in the
`jet_engine_modules` option:

- `Jet_Engine_Modules::preload_modules()` (`includes/modules/modules-manager.php:163-204`)
  — builds the full module registry via `apply_filters('jet-engine/available-modules', ...)`
  (`:166`), including `'Jet_Engine_Module_Calendar' => .../calendar/calendar.php` and
  `'Jet_Engine_Module_Booking_Forms' => .../forms/forms.php` (`:172-173`). Every listed
  class is `require`d and instantiated **unconditionally** here — `preload_modules()`
  runs for every module regardless of activation state, it's `init_active_modules()`
  below that gates actual behavior.
- `Jet_Engine_Modules::init_active_modules()` (`:211-246`) reads the
  `jet_engine_modules` option (an array of active module-id strings) and calls
  `init_module( $module_id )` (`:254-263`) for each active one, which calls
  `$module_instance->module_init()`.
- **Check activation with `jet_engine()->modules->is_module_active( 'calendar' )` /
  `jet_engine()->modules->is_module_active( 'booking-forms' )`**
  (`modules-manager.php:383-385`, `return in_array( $module_id, $this->active_modules )`)
  — `$active_modules` is only populated by `init_module()`, so this correctly reflects
  "did `module_init()` actually run," not just "is the module class loaded" (every
  module class is always loaded per `preload_modules()` above, active or not — don't
  use `class_exists()` as a proxy for activation).
- Get the module instance itself (to call module-specific public methods, e.g. the
  Calendar module's `get_calendar_group_keys()`) via
  `jet_engine()->modules->get_module( 'calendar' )` (`:393-395`, returns `false` if the
  id isn't registered — it does **not** check activation, so this can return a real
  instance for an inactive module; always pair it with `is_module_active()` if the
  distinction matters).
- **Gotcha confirmed by reading `modules-manager.php:69-76`**: `booking-forms` is in
  the `$reload_modules` list used by the admin "Save modules" AJAX handler (forces a
  page reload after toggling), but `calendar` is **not** — toggling Calendar on/off
  from the modules dashboard doesn't force a reload the way toggling Forms (Legacy)
  does.

## Calendar module — Listing Grid render type over an existing data source

The Calendar widget/block is **not** a standalone query — it wraps a Listing Grid
render pipeline and groups whatever items that query already returns by a date value.

- `Jet_Engine_Module_Calendar::module_init()` (`calendar.php:117-161`) registers the
  render class, an Elementor widget, a Gutenberg block, a Bricks element, and the
  Advanced Date Field meta-field type — all conditional on this one module being
  active.
- **Registering the render class**: `register_render_class()`
  (`calendar.php:365-389`) hooks `jet-engine/listings/renderers/registered` and calls
  `$listings->register_render_class( 'listing-calendar', array( 'class_name' =>
  'Jet_Listing_Render_Calendar', 'path' => .../renders/render.php, 'deps' =>
  array('listing-grid') ) )` — `deps` declares `Jet_Listing_Render_Calendar` (
  `renders/render.php:7`) as a subclass of the base grid renderer
  (`Jet_Engine_Render_Listing_Grid`), so every generic Listing Grid setting (post type,
  query, meta/tax query relation, custom Query Builder query) is inherited unchanged —
  the Calendar-specific settings (`group_by`, `group_by_key`, `allow_multiday`,
  `end_date_key`, ...) only change *how results are laid out on a grid*, not *what gets
  queried*.
- **The date-grouping key, `group_by`** (`elementor-views/calendar-widget.php:92-99`,
  default `post_date`) has four built-in values, extensible via
  `apply_filters('jet-engine/listing/calendar/group-keys', ...)` (`calendar.php:90`) —
  **live-verified 2026-07-17: this site's actual list has 6 entries, not 4** —
  `jet_appointment`/`jet_booking` are appended by JetBooking/Jet Appointments Booking
  (both active on this sandbox), confirming this filter is a real, used cross-plugin
  extension point, not just theoretical:
  - `post_date` / `post_mod` — `WP_Post::post_date` / `post_modified`, handled inline
    in `prepare_posts_for_calendar()` (`renders/render.php:191-197`).
  - `meta_date` — the interesting one: reads a **post meta key** (the "Meta field
    name" control, `group_by_key`) via `strtotime()`-parsed timestamps, or via
    **`Jet_Engine_Advanced_Date_Field::instance()->data->get_dates( $post_id, $field
    )`** (`renders/render.php:213-215`) if that field's underlying storage is an
    Advanced Date Field (see below) — both paths coexist in the same `case 'meta_date'`
    branch (`renders/render.php:199-339`).
  - `item_date` — a generic "whatever this query item's own creation-date concept is"
    fallback (works even for non-post query items, e.g. CCT rows), resolved via
    `jet_engine()->listings->data->get_object_date( $item )`
    (`Jet_Engine_Calendar_Query::prepare_item_date_key()`, `query.php:25-36`) — this is
    the extension point for a custom `group_by` value too: hook
    `apply_filters('jet-engine/listing/calendar/date-key', false, $post, $group_by,
    $this)` (`renders/render.php:348-351`, default branch) and check your own
    `$group_by` value.
- **The actual date-range restriction that turns "all matching items" into "just this
  visible month" is a separate filter**, `jet-engine/listing/calendar/query`
  (action-adjacent filter, 3 args: `$args`, `$group_by`, `$render`) —
  `Jet_Engine_Calendar_Query::prepare_calendar_args()` (`query.php:38-95`) is the
  built-in listener: for `meta_date` grouping it derives `end_date_key` automatically
  from the Advanced Date Field's naming convention when multiday is on and no explicit
  end key was set (`query.php:64-68`, see naming convention below), then calls
  `$render->query_instance->add_date_range_args( $args, $date_values, $settings )`
  when a real Query Builder query backs the listing, or falls back to instantiating a
  throwaway `Posts_Query` just to reuse its `add_date_range_args()` method for
  old-style (pre-Query-Builder) listings (`query.php:71-91`) — **this fallback branch
  constructs `new Jet_Engine\Query_Builder\Queries\Posts_Query()` directly**, guarded
  by `ensure_queries()`/`include_factory()` calls if the class isn't loaded yet
  (`query.php:84-87`) — an example of Query Builder internals being reached into from
  outside `jetengine-query-builder`'s own documented entry points, worth knowing if
  you're tracing why a `Posts_Query` object appears with no matching saved query id.
- **Month navigation is AJAX**, `wp_ajax(_nopriv)_jet_engine_calendar_get_month`
  (`calendar.php:119-120,311-358`) — re-renders `listing-calendar` (or
  `listing-multiday-calendar`) via `jet_engine()->listings->get_render_instance()` with
  the posted `settings` array, returning fresh HTML. **The request is
  signature-gated**: `has_valid_settings_signature()` (`calendar.php:294-304`)
  `hash_equals()`-compares an HMAC-style signature (generated server-side via
  `jet_engine()->listings->ajax_handlers->generate_signature()` over a normalized
  payload — `lisitng_id` (sic, the misspelling is real and load-bearing, matches the
  Elementor control's own `lisitng_id` key), `renderer`, `custom_query`,
  `custom_query_id`) against a `settings_signature` value baked into the
  server-rendered markup's `data-settings` JSON at `posts_template()`
  (`renders/render.php:670`, `get_data_settings()`). **A hand-built AJAX POST to this
  endpoint with a forged `settings` array (e.g. pointing at a different
  `custom_query_id`) will be rejected** unless the signature is regenerated for the new
  payload — this is a genuine anti-tampering measure, not just a nonce, don't try to
  bypass it by only fixing up a WP nonce.

### Advanced Date Field — the calendar-specific meta field type

A dedicated meta field type (`field_type = 'advanced-date'`,
`Jet_Engine_Advanced_Date_Field`, `advanced-date-field/manager.php:6-8`), registered
only when the Calendar module is active (`calendar.php:141-142`,
`require .../advanced-date-field/manager.php; Jet_Engine_Advanced_Date_Field::instance();`
— a singleton, safe to call `::instance()` repeatedly).

- **Storage is multi-row `wp_postmeta`, not a single serialized value**: each concrete
  date (including every occurrence a recurring-date rule expands to) is stored as its
  own `add_post_meta( $post_id, $field_name, $timestamp, false )` row (**`false`** as
  the 4th "unique" arg is required — a field can legitimately have many rows with the
  same meta key), via `Jet_Engine_Advanced_Date_Field_Data::add_field_data()`
  (`advanced-date-field/data.php:256-379`). Reading with `get_post_meta( $post_id,
  $field, false )` (the plural/array form) is exactly `Data::get_dates()`
  (`data.php:89-91`) — a single-row `get_post_meta($id, $field, true)` call will
  silently return just one arbitrary occurrence, not "the date."
- **A companion "end date" sub-field exists under a derived meta key**:
  `Data::end_date_field_name( $field )` returns `$field . '__end_date'`
  (`data.php:67-69`) — **this naming convention (`__end_date` suffix) is itself part of
  the documented contract**, since `Jet_Engine_Calendar_Query::prepare_calendar_args()`
  (`query.php:64-68`) auto-derives the end-date meta key this exact way whenever a
  Calendar widget has "Allow multi-day events" on but no explicit "End date field name"
  set — naming a custom non-Advanced-Date meta field `foo` and a matching end field
  anything other than `foo__end_date` means that auto-derivation silently won't find
  it.
- **A third derived key holds the raw JSON config** (recurrence rule, per-field UI
  settings): `Data::config_field_name( $field )` → `$field . '__config'`
  (`data.php:168-170`), a single `true`-mode meta row, `json_decode()`-able via
  `get_field_config( $post_id, $field, true )` (`data.php:179-188`).
- **`get_date_pairs( $post_id, $field )`** (`data.php:103-160`) is the one method that
  actually correlates a start date with its matching end date row-by-row (by shared
  `meta_id` ordering, since both live as separate un-keyed rows in the same table) —
  cached via `wp_cache_set()`/`get()` keyed by `jet_engine_advanced_date_field_pairs_{post}_{field}`
  (`data.php:93-96`), invalidated on every write (`update_field_with_value()`,
  `data.php:236`). Prefer this over hand-correlating `get_dates()`/`get_end_dates()`
  arrays yourself — a date with no end (no `is_end_date` set on that occurrence) is
  represented as `['start' => ..., 'end' => false]`, easy to miscount if you assume
  both arrays are always the same length and index-aligned.
- **Two listing macros/dynamic-tag callbacks** are registered specifically for this
  field type (only when Calendar is active):
  `jet_engine_advanced_date_next`/`jet_engine_advanced_end_date_next`
  (`advanced-date-field/manager.php:148-158`, functions defined at
  `advanced-date-field/manager.php:234-374`) — "next upcoming date" resolvers that
  understand both plain and recurring configurations, used by the Dynamic Field
  widget's "Advanced date: Get next date" callback option.

## Legacy Forms module (`booking-forms`) — JetEngine's own pre-JetFormBuilder form builder

**Do not conflate this with JetFormBuilder.** They are different plugins with
completely separate codebases, hook namespaces, and storage. If a task mentions "a
JetEngine form," check whether the site is actually using this legacy module (`Forms`
JetEngine submenu, CPT `jet-engine-booking`) or JetFormBuilder (a `jet-form-builder`
CPT/blocks, covered by `jetformbuilder-fields`/`jetformbuilder-actions`/
`jetformbuilder-hooks`) before writing any code — the two are not API-compatible.

- Module bootstrap: `Jet_Engine_Module_Booking_Forms::module_init()`
  (`forms/forms.php:98-100`) just hooks `jet-engine/init` → `create_instances()`
  (`:108-116`), which requires `forms/manager.php` and sets `jet_engine()->forms = new
  Jet_Engine_Booking_Forms()` (plus `jet_engine()->forms->booking = jet_engine()->forms`
  for old code that used the module's former name). **`jet_engine()->forms` only exists
  when this module is active** — always pair any direct access with
  `jet_engine()->modules->is_module_active('booking-forms')` first, or guard with
  `isset( jet_engine()->forms )`.
- **Storage: a CPT, not CCT** — `Jet_Engine_Booking_Forms::$post_type = 'jet-engine-booking'`
  (`manager.php:19`, `slug()` at `:396-398`), registered on `init` by
  `Jet_Engine_Booking_Forms_Editor::register_post_type()` (`editor.php:27,631-678`,
  `public: true`, `show_in_menu: false` — it's surfaced only via the module's own
  custom "Forms" JetEngine-submenu admin page, not a normal top-level CPT menu item).
  Args are filterable via `jet-engine/forms/booking/post-type/args` (`editor.php:675`).
- **A form's field layout + notifications live in two JSON-encoded post-meta keys**,
  not structured rows:
  - `_form_data` — the field/layout grid definition (each field's type, position,
    validation, etc., as a JSON array) — written by
    `Jet_Engine_Booking_Forms_Editor::save_layout()` (`editor.php:53-102`, hooked on
    `save_post` priority 999, `editor.php:37`) from `$_POST['_form_data']`, read back
    via `$this->manager->editor->get_form_data( $post_id )` (`editor.php:497-555`,
    falls back to a sane 1-field default layout if empty/`'[]'`).
  - `_notifications_data` — the list of configured notification actions (email,
    insert/update post, register/update user, webhook, Call a Hook, redirect,
    Mailchimp/ActiveCampaign/GetResponse — the full list is
    `Jet_Engine_Booking_Forms::get_notification_types()`, `manager.php:276-290`,
    filterable via `jet-engine/forms/booking/notification-types`) — same
    save/read pattern, `editor.php:97-98` / `get_notifications( $post_id )`
    (`editor.php:451-465`). **Live-verified 2026-07-17: this site's actual list has 16
    entries, not 11** — `insert_appointment`, `apartment_booking`,
    `insert_custom_content_type`, `rest_api_request`, `connect_relation_items` are
    appended by other active JetEngine modules/plugins (CCT, Relations, REST API
    Listings, Jet Appointments Booking, JetBooking) through this same filter — treat the
    11 core types as a floor, not the complete set, on any real site with other
    Crocoblock plugins active.
  - Both are guarded in `Jet_Engine_Booking_Forms::__construct()`'s `import_post_meta`
    compatibility hook (`manager.php:71-77`) — a WP core importer round-trip on either
    key needs a delete-then-update, not a plain `update_post_meta()`, because the
    importer's own meta-import path mishandles the pre-existing JSON string otherwise.
- **Rendering a form on the front end** goes through the same Listing Grid render-class
  registry as Calendar, not a separate widget-only code path:
  `register_render_classes()` (`manager.php:484-502`) globs every file in
  `forms/render/*.php` (currently `booking-form.php` → class `Booking_Form`,
  `check-mark.php` → `Check_Mark`) and registers each as
  `Jet_Engine\Forms\Render\{Class_Name}`. The Elementor "Form" widget
  (`Elementor\Jet_Engine_Booking_Form_Widget`, `widgets/booking-form.php`) just calls
  `jet_engine()->listings->get_render_instance( 'booking-form', $this->get_settings()
  )->render_content()` (`widgets/booking-form.php:2547-2558`) — which in turn resolves
  the actual `<form>` markup via
  `jet_engine()->forms->get_form_builder( $form_id, false, $args )`
  (`render/booking-form.php:58`, `Jet_Engine_Booking_Forms::get_form_builder()`,
  `manager.php:230-252` — memoized per `$form_id` in `$this->builder_instances`, lazily
  `require`s `forms/builder.php` on first call).

### Submission pipeline — dual entry point (classic redirect vs. AJAX), no REST route

`Jet_Engine_Booking_Forms_Handler` (`forms/handler.php`) is the single class handling
both submission modes, decided in its constructor (`:36-59`):

- **AJAX mode**: `wp_ajax(_nopriv)_jet_engine_form_booking_submit` →
  `process_ajax_form()` (`:40-41,121-124`) sets `$this->is_ajax = true`, then calls the
  same `process_form()` as the classic path.
- **Classic (page-reload) mode**: only wired up if the current request already carries
  `$_REQUEST['jet_engine_action'] === 'book'` (`:45-51` — `$hook_key`/`$hook_val`
  properties, `:20-21`), then hooks `wp_loaded` priority 0 to call `process_form()`.
  This mode does an **extra CSRF-adjacent check** the AJAX path skips:
  `validate_reload_form()` (`:151-168`) compares `$_SERVER['HTTP_REFERER']` against the
  submitted `_jet_engine_refer` hidden field and, if
  `jet-engine/forms/validate-session-token` is filtered `true`, a PHP-session-backed
  token (`get_session_token()`, `:130-146` — this is the one place this module starts a
  raw PHP `session_start()`, gated behind that same filter, `:53-55`).
- **There is no REST API route for form submission** — same architectural choice
  JetFormBuilder made for its own forms (see `jetformbuilder-fields`'s "no REST route
  for submission" note) — both entry points are classic `admin-ajax.php`/`wp_loaded`,
  not `register_rest_route()`.
- Every submission is nonce-gated regardless of mode:
  `wp_verify_nonce( $this->nonce, '_jet_engine_booking_form' )` (`handler.php:179`) —
  fails closed (`redirect(['status'=>'failed'])`, `:180-183`, which for AJAX means a
  `wp_send_json()` response, not an actual HTTP redirect — see below).
- **Notification dispatch is a flat, filterable action fan-out, not a switch
  statement**: `Jet_Engine_Booking_Forms_Notifications::send()`
  (`notifications.php:204-227`) loops the form's configured `_notifications_data`
  entries and fires `do_action( 'jet-engine/forms/booking/notification/' .
  $notification['type'], $notification, $this )` (`:217`) per entry — the built-in
  types (`register_user`, `update_user`, `webhook`, `hook`, `insert_post`, `redirect`,
  `activecampaign`, `mailchimp`, `getresponse`, `update_options`, `email`) are each just
  a listener registered on their own `.../notification/{type}` hook in the same
  constructor (`notifications.php:60-99`). **Adding a wholly custom notification type**
  is exactly this two-part pattern (register the type's label via
  `jet-engine/forms/booking/notification-types` so it shows in the editor UI, then hook
  `jet-engine/forms/booking/notification/your_type`) — the same "two-filter/hook
  pairing" shape documented elsewhere in this repo for JetEngine Option Sources
  (`jetengine-modules`) and JFB gateways, not a coincidence, it's a recurring
  Crocoblock convention.
  `unregister_notification_type( $type )` (`notifications.php:116-173`) exists to
  remove a built-in type's listener if you want to fully replace its behavior instead
  of adding alongside it — the `default` branch (`:169-171`) falls back to
  `remove_all_actions()` for any type not explicitly cased, which also removes any
  *other* custom listener on that same hook, not just the built-in one — a real
  footgun if two unrelated pieces of code both hook the same custom notification type
  name and one calls `unregister_notification_type()` expecting to remove only its own.
- **Redirect/response shape differs completely by mode** —
  `Jet_Engine_Booking_Forms_Handler::redirect()` (`handler.php:242-327`) is the single
  exit point for both success and failure: AJAX mode ends in `wp_send_json( $query_args
  )` (`:319`, with pre-rendered `message`/`field_message` HTML from
  `get_messages_builder()`), classic mode ends in `wp_redirect( $redirect ); die();`
  (`:322-324`) back to the page the form lives on, with status/messages/values passed
  as **query-string args** appended via `add_query_arg()` — a page that shows form
  success/failure messages needs to actually read those query args (rendered
  server-side by `Jet_Engine_Booking_Forms_Messages`, `forms/messages.php`, based on
  `$_GET['status']`), there's no client-side-only "form submitted" event to hook for
  the classic-mode path the way JetFormBuilder's JS event bus works.

## Gotchas

- **`jet_engine()->modules->get_module( $id )` does not check activation** — it returns
  the always-preloaded instance for any registered module id whether or not it's
  active. Only `is_module_active()` reflects the real on/off state; a plausible-looking
  `if ( jet_engine()->modules->get_module('calendar') ) { ... }` guard will pass even
  when Calendar is off.
- **Advanced Date Field values must be read with the plural `get_post_meta($id, $key,
  false)` form** (or, better, `Jet_Engine_Advanced_Date_Field::instance()->data->get_dates()`)
  — the field intentionally stores multiple meta rows under one key; a `true`-mode read
  silently returns just one row.
- **The `__end_date`/`__config` meta-key suffixes are a real naming contract**, not
  incidental — code elsewhere (the calendar query auto-derivation in
  `query.php:64-68`) depends on exactly that suffix to auto-wire a multiday field's end
  date without an explicit "End date field name" setting.
- **Legacy Forms' `unregister_notification_type()` default branch nukes *all* listeners
  on a custom `.../notification/{type}` hook**, not just the caller's own — see above.
- **No REST endpoint exists for legacy-form submission** — don't assume
  `wp-json/jet-engine/...` has a form-post route; it's `admin-ajax.php`
  (`action=jet_engine_form_booking_submit`) or a same-page `wp_loaded`-gated POST.
- **Both modules are independently gated** — a site can have Calendar on and Forms
  (Legacy) off, or vice versa; don't assume "JetEngine module X" implies any other
  module's state. `resource-get-website-config`/`tool-manage-modules` (see
  `jetengine-mcp-tools`) is the live way to check current activation state on a given
  site rather than guessing from what other modules are on.

## How this was verified

Read `includes/modules/modules-manager.php` (module registry, `is_module_active()`/
`get_module()`, the `booking-forms`-only reload-modules gotcha),
`includes/modules/calendar/calendar.php`, `query.php`,
`elementor-views/calendar-widget.php`, `renders/render.php`, and
`advanced-date-field/{manager,data}.php`, and `includes/modules/forms/{forms,manager,
handler,notifications,editor}.php` and `render/booking-form.php` in JetEngine 3.8.12
source, confirming class names, hook names, meta-key naming conventions, and the
submission pipeline's dual-entry-point/notification-fan-out shape by direct file:line
citation. **Live-verified 2026-07-17** against `jackfruit.epeak.studio` (both `calendar`
and `booking-forms` modules newly activated for this round, alongside
`dynamic-visibility`/`data-stores`/`rest-api-listings`) via the runnable suite
`tests.php` (deployed as Code Snippets snippet, run through
`GET /agent-test/v1/suite/jetengine-booking-forms` — see `docs/test-harness-guide.md`
for the harness convention). See `TEST-REGIMEN.md` for the full run log.
